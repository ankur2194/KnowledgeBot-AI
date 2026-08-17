<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Contracts\Auth\PasswordBrokerFactory;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LogicException;
use SensitiveParameter;

/**
 * The two halves of a password reset, and the reasons they are here rather than in the controllers.
 *
 * THIS CLASS EXISTS FOR ONE MECHANICAL REASON: THE TRANSACTION.
 * ============================================================
 * `AuditLogger::PASSWORD_RESET_COMPLETED` is an `ON_FAILURE_ABORT` operation — a credential change, so
 * the audit row must go in the SAME transaction as the password write and a failed audit write must
 * roll the password back. `AuditLogger` deliberately opens no transaction of its own ("a service that
 * opens its own transaction around one INSERT gives the appearance of atomicity without the fact"), so
 * wrapping is the caller's job. `Illuminate\Support\Facades\DB` is arch-banned outside
 * `App\Repositories\Eloquent` (tests/Arch/DoctrineTest.php), so the connection arrives as
 * `ConnectionResolverInterface` and `->connection()` names the default connection explicitly — which
 * is the connection both `users` and `audit_logs` live on, and the whole claim of atomicity depends on
 * that still being true. If either model ever declares `$connection`, this transaction becomes a lie
 * that nothing tests.
 *
 * WHERE THE TRANSACTION SITS, AND WHY IT IS *INSIDE* THE BROKER'S CALLBACK
 * =======================================================================
 * `PasswordBroker::reset()` is `validateReset()` → `$callback($user, $password)` → `tokens->delete()`,
 * all inside a 200 ms `Timebox`. The transaction wraps the CALLBACK's two writes and nothing else:
 *
 *   * an exception from the audit write leaves `reset()` before `tokens->delete()` runs, so the
 *     password is rolled back AND the token is left intact — the user's link still works and they can
 *     retry. Wrapping only the callback is what produces that outcome; there is no arrangement in
 *     which the password changes and the audit row is missing.
 *   * wrapping the whole `reset()` call instead would put the Timebox's own `usleep` inside an open
 *     transaction on every FAILED attempt — 200 ms of `idle in transaction` per guess, handed to
 *     whoever is guessing. The residual accepted in exchange is narrow and stated: if
 *     `tokens->delete()` itself fails after our COMMIT, the password is changed and the consumed token
 *     survives until its 60-minute expiry. That requires the connection to fail between two
 *     statements on it, the request 500s (`internal_dependency`), and the window is bounded by
 *     `config('auth.passwords.users.expire')`.
 *
 * WHY THE AUDIT ROWS ARE WRITTEN INSIDE THE TIMEBOX RATHER THAN AFTER IT
 * =====================================================================
 * `sendResetLink()`'s timebox is the account-enumeration defence: it pads every outcome to the same
 * 200 ms so "this address has an account" and "it does not" are indistinguishable with a stopwatch.
 * The `PASSWORD_RESET_REQUESTED` row is written ONLY on the exists-branch, so its cost is a
 * differential — and a differential INSIDE the timebox is absorbed (the box pads the remainder) while
 * the same differential after the box is measurable from the internet with no credentials. So it goes
 * inside, and the budget it consumes is stated rather than assumed: one INSERT plus one queue push
 * against 200 ms. If that branch ever exceeds the box, `Timebox::call()` does not warn — it simply
 * stops padding, and the oracle re-opens silently. Raise `auth.timebox_duration` before adding work
 * here.
 *
 * NO FAILURE IS AUDITED, AND THAT IS THE MAP'S DECISION, NOT AN OMISSION. `AuditLogger::OPERATIONS`
 * has no `auth.password_reset.failed`, and `record()` THROWS on an unknown operation rather than
 * writing a row nobody has decided the shape of. A guessed token therefore leaves no audit row; the
 * `throttle:password-reset` limiter (keyed on the token digest) is what makes guessing expensive, and
 * the 429s it produces are visible in telemetry.
 */
final class PasswordResetService
{
    public function __construct(
        private readonly PasswordBrokerFactory $brokers,
        private readonly ConnectionResolverInterface $connections,
        private readonly Dispatcher $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Email a reset link — or do nothing, indistinguishably.
     *
     * RETURNS `void` ON PURPOSE. The broker's three outcomes (`RESET_LINK_SENT`, `INVALID_USER`,
     * `RESET_THROTTLED`) collapse here rather than at the controller, so no caller can branch on a
     * value it should not have. Returning the status would be an invitation to log it, to put it in a
     * response header, or to "helpfully" 429 on the throttled case — and returning 429 for a throttled
     * address while returning 200 for an unknown one IS the oracle: probe twice, and a throttle proves
     * the first probe created a token, which proves the account exists.
     *
     * The one status that could matter operationally — RESET_THROTTLED — is already visible as the
     * absence of a `PASSWORD_RESET_REQUESTED` audit row for an address that has one from a minute ago.
     *
     * @param  array{email: string}  $credentials  from ForgotPasswordRequest::credentials(), already
     *                                             lowercased and trimmed
     */
    public function sendResetLink(#[SensitiveParameter] array $credentials, Request $request): void
    {
        $this->broker()->sendResetLink(
            $credentials,
            /**
             * PASSING A CALLBACK REPLACES the framework's own send-and-dispatch block, so this closure
             * owes both halves of it. The token is the reason the callback exists at all: the audit
             * map declares `token` FINGERPRINTED for this operation, and the plaintext exists nowhere
             * else — `password_reset_tokens.token` holds only a bcrypt hash of it.
             */
            function (CanResetPassword $user, string $token) use ($request): string {
                // App\Notifications\ResetPassword, which IS `ShouldQueue` — verified, and it is a
                // security requirement rather than a throughput one. A synchronous SMTP handshake
                // inside this timebox blows the 200 ms floor on the exists-branch ONLY.
                $user->sendPasswordResetNotification($token);

                $this->audit->record(
                    AuditLogger::PASSWORD_RESET_REQUESTED,
                    // NULL, and deliberately. A password reset is a fact about a USER, not about an
                    // organization: App\Models\User is not owned by one, and this route carries no
                    // `{organization}` segment and no session. Resolving "their" organization here
                    // would mean choosing one of several memberships and recording it as though the
                    // reset happened inside it. `ON_FAILURE_LOG` applies, so a failed write here logs
                    // at ERROR and the response still stands — correct, because the mail has already
                    // been queued and cannot be recalled.
                    organizationId: null,
                    actorId: $this->identify($user),
                    details: [
                        // The address the broker RESOLVED, not the one submitted. They are equal
                        // whenever the FormRequest normalised correctly, and the resolved one is the
                        // one the token row is keyed by — so this row answers "which account" rather
                        // than "which spelling".
                        'email' => (string) $user->getEmailForPasswordReset(),
                        // Fingerprinted by AuditLogger under `token_fingerprint`. The plaintext
                        // reaches no column, no log line and no response; there is no call site that
                        // could choose to echo it.
                        'token' => $token,
                    ],
                    request: $request,
                );

                // The framework dispatches this itself when no callback is given, and a callback must
                // not silently remove an event a package may already be listening to. Nothing in this
                // application listens today; that is exactly why its disappearance would go unnoticed.
                $this->events->dispatch(new PasswordResetLinkSent($user));

                return PasswordBroker::RESET_LINK_SENT;
            },
        );
    }

    /**
     * Consume the token and set the new password.
     *
     * @param  array{token: string, email: string, password: string, password_confirmation: string}  $credentials
     *                                                                                                             from ResetPasswordRequest::credentials()
     * @return bool true only on `PASSWORD_RESET`. `INVALID_USER` and `INVALID_TOKEN` are one `false`,
     *              because the caller must not be able to tell them apart — the controller turns that
     *              single `false` into a single 422 on the `token` key.
     */
    public function reset(#[SensitiveParameter] array $credentials, Request $request): bool
    {
        $token = $credentials['token'];

        $status = $this->broker()->reset(
            $credentials,
            function (CanResetPassword $user, string $password) use ($token, $request): void {
                if (! $user instanceof User) {
                    // The configured user provider returned something that is not our model — only
                    // reachable by pointing AUTH_MODEL somewhere else. LOUD, because the alternative
                    // shapes are both worse: a silent skip would report "reset succeeded" while the
                    // password stayed put (the broker deletes the token either way), and calling
                    // Eloquent methods through the contract would be an "undefined method" fatal from
                    // inside a closure inside a timebox.
                    throw new LogicException(
                        'The password broker resolved a user that is not App\Models\User, so the '
                        .'password write and its audit row cannot be performed. Check '
                        .'auth.providers.users.model (AUTH_MODEL).',
                    );
                }

                $this->connections->connection()->transaction(
                    function () use ($user, $password, $token, $request): void {
                        // `forceFill` rather than `fill`: `password` IS fillable, but this write is not
                        // a user-supplied payload and saying so keeps it independent of $fillable. The
                        // `hashed` cast (User::casts()) hashes the plaintext on assignment, so no
                        // Hash:: call belongs here.
                        $user->forceFill([
                            'password' => $password,
                            // ROTATED EVEN THOUGH NOTHING SETS IT TODAY. Decision D4 admits no
                            // "remember me" field, so `remember_token` is unused — but if D4 is ever
                            // reversed, a password reset that left the token standing would leave a
                            // live long-lived credential behind, and the PR that adds the checkbox
                            // will not think to edit this file.
                            //
                            // ASSIGNED THROUGH forceFill AND NOT setRememberToken(): the latter is
                            // declared `void` on Illuminate\Auth\Authenticatable, so chaining it
                            // returns null and `->save()` is a call on null. PHPStan catches it; a
                            // hand-test would not, because the reset path is not covered by the Unit
                            // suite (it needs a database).
                            'remember_token' => Str::random(60),
                        ])->save();

                        // IN THE SAME TRANSACTION AS THE WRITE ABOVE — that is this method's whole
                        // reason for existing. ON_FAILURE_ABORT rethrows, the transaction rolls back,
                        // and `tokens->delete()` is never reached.
                        $this->audit->record(
                            AuditLogger::PASSWORD_RESET_COMPLETED,
                            organizationId: null,
                            actorId: $user->id,
                            details: [
                                'email' => $user->email,
                                'token' => $token,
                            ],
                            request: $request,
                        );
                    },
                );
            },
        );

        return $status === PasswordBroker::PASSWORD_RESET;
    }

    /**
     * The default broker, named by `auth.defaults.passwords`. Resolved per call rather than in the
     * constructor because `PasswordBrokerManager` memoizes brokers itself, and holding one would pin
     * the token repository across a config change in a long-lived worker.
     */
    private function broker(): PasswordBroker
    {
        return $this->brokers->broker();
    }

    /**
     * The audit actor id, or null.
     *
     * `CanResetPassword` declares no identifier accessor, so this narrows instead of assuming. A null
     * actor on an audit row is honest; a TypeError inside a broker callback is a 500 pointing at the
     * wrong layer.
     */
    private function identify(CanResetPassword $user): ?string
    {
        return $user instanceof User ? $user->id : null;
    }
}
