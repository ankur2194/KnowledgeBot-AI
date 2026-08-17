<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResetPasswordRequest;
use App\Http\Resources\AcknowledgementResource;
use App\Services\Auth\PasswordResetService;
use App\Support\Contracts\ResponseShape;
use Illuminate\Validation\ValidationException;

/**
 * `POST /api/v1/auth/reset-password` — set a new password from an emailed token.
 *
 * ── TWO BROKER FAILURES, ONE RESPONSE ────────────────────────────────────────────────────────────
 *
 * `PasswordBroker::validateReset()` distinguishes `INVALID_USER` (no `users` row for that address)
 * from `INVALID_TOKEN` (no token row, a bcrypt mismatch, an expired row, or one already consumed).
 * Four ways of failing, two broker constants, ONE 422 body keyed on `token`. Collapsing them is what
 * stops a prober learning either that an address is unknown or that a guessed token was once real.
 *
 * The collapse is structural: `PasswordResetService::reset()` returns `bool`, so this action cannot
 * see which constant came back, and the single message lives on `ResetPasswordRequest::INVALID` so no
 * second call site can phrase it differently. Keyed on `token` and NEVER on `email` — a message under
 * `email` would say "the token is fine, the address is not", which is the enumeration oracle wearing a
 * different field name.
 *
 * TIMING: `reset()` is timeboxed at 200 ms and calls `returnEarly()` on SUCCESS ONLY, so both failure
 * modes burn the full floor. The measurable signal is success-versus-failure, never
 * exists-versus-absent — the same asymmetry `SessionGuard::attempt()` has, and the same reason it is
 * acceptable.
 *
 * ── IT DOES NOT LOG THE USER IN, AND THE SIBLING-LOGOUT BEHAVIOUR IS WHY ─────────────────────────
 *
 * `Laravel\Sanctum\Http\Middleware\AuthenticateSession` is ACTIVE — config/sanctum.php wires it into
 * the stateful pipeline through `sanctum.middleware.authenticate_session`, so it runs on every request
 * in the `api` group. Sanctum's version compares a PASSWORD HASH per request (it needs no
 * `remember_token`; that requirement belongs to `Illuminate\Session\Middleware\AuthenticateSession`,
 * which this application does not use).
 *
 * So a completed reset rotates the value every other session is being compared against, and EVERY
 * OTHER SESSION FOR THAT USER DIES ON ITS NEXT REQUEST — as an `AuthenticationException` rendered 401
 * `authentication`, NOT a 419. That is the "log out siblings on password change" behaviour
 * laravel-sanctum-auth requires, and it is the ONLY mechanism this application has for it: there is no
 * session index and nothing else to enumerate a user's sessions with. A test must assert the 401, and
 * must assert it is not a 419, because a 419 would mean the session survived and merely lost its CSRF
 * token.
 *
 * ESTABLISHING A SESSION HERE WOULD DEFEAT ITSELF. The new session's `password_hash_web` is seeded
 * AFTER the response, on the next authenticated request — so logging the user in inside this request
 * would create a session and then immediately invalidate it on its first use. Return the
 * acknowledgement and let the SPA send them to the login form.
 *
 * ── THE AUDIT ROW AND THE PASSWORD WRITE ARE ONE TRANSACTION ─────────────────────────────────────
 *
 * `AuditLogger::PASSWORD_RESET_COMPLETED` is `ON_FAILURE_ABORT` — unlike the three login/logout events,
 * which are `ON_FAILURE_LOG` because a cookie has already been issued and there is nothing left to roll
 * back. This is a CREDENTIAL CHANGE: if the audit row cannot be written, the password must not commit.
 * `AuditLogger` deliberately opens no transaction of its own, so the wrapping is
 * `PasswordResetService::reset()`'s job and it is done INSIDE the broker's callback — see that class
 * for why inside-the-callback rather than around the whole `reset()` call, and for the one residual
 * that choice accepts. A NO-FAILURE reset is therefore never audit-less, and a failed audit write
 * leaves the user's link still usable.
 *
 * ── THE SIX CHECKS (kb-security-baseline §18.4) ──────────────────────────────────────────────────
 *   1. authenticated identity   N/A at the route — THE TOKEN IS THE CREDENTIAL, and it is verified in
 *                               the body by `PasswordBroker::validateReset()`. That is the whole point
 *                               of a guest reset route: requiring a session would strand exactly the
 *                               people who have lost access to one.
 *   2. organization membership  N/A — user-scoped, no `{organization}` segment.
 *   3. role / permission        N/A — no record and no organization.
 *   4. entity ownership         PROVED BY THE TOKEN, which is bound to one address in
 *                               `password_reset_tokens` (that column is the primary key) and stored
 *                               only as a bcrypt hash.
 *   5. entity status            N/A, deliberately: an unverified or suspended user may still reset
 *                               their password. Refusing would make the state unrecoverable, and a
 *                               status-dependent response would be a status oracle.
 *   6. rate limit               `throttle:password-reset`: 5/min keyed on `tok:<sha256 of the token>`
 *                               AND 20/min per IP. Keyed on the TOKEN because the token is what is
 *                               being guessed — never on the email, which the guesser supplies and can
 *                               vary one character at a time to reset the budget.
 *
 * ── ERRORS ───────────────────────────────────────────────────────────────────────────────────────
 * 422 `validation` for a weak or unconfirmed password (field errors on `password`), for a malformed
 * body, and — with one fixed sentence on `token` — for every way the credential can fail;
 * 429 `rate_limit` + `Retry-After`; 500/503 `internal_dependency` (including a failed audit write,
 * which arrives here as the underlying QueryException and is classified by bootstrap/app.php).
 * NO 401 and NO 403: a 401 from a route whose job is to restore access would hit the SPA's global
 * 401 interceptor and redirect to the login page, and a 403 would announce that the token was the
 * thing that failed.
 */
final class NewPasswordController extends Controller
{
    #[ResponseShape(
        status: 200,
        properties: ['data' => AcknowledgementResource::class],
        description: 'The password was changed, and every other session for that user dies on its '
            .'next request. No session is established here: the SPA sends the user to the login form.',
        // 401 IS PUBLISHED ON A GUEST ROUTE ON PURPOSE, and it is not about being signed in.
        // Two producers reach it. `PreventRequestForgery` (from `statefulApi()`) throws 419 for a
        // dead or absent XSRF cookie, and the render closure maps 419 -> `authentication`/401
        // (bootstrap/app.php: the `$httpStatus === 401, $httpStatus === 419` arm). `RejectBearerToken`
        // is the second: an `Authorization: Bearer` header anywhere on the `api` group is a 401.
        // Omitting it made the document assert a status is impossible when it is reachable with one
        // expired tab -- the mirror image of the 409 that D36 removed for being unreachable.
        errors: [401, 422, 429, 500, 503],
    )]
    public function store(ResetPasswordRequest $request, PasswordResetService $passwordReset): AcknowledgementResource
    {
        // A BOOLEAN, on purpose. The service collapses INVALID_USER and INVALID_TOKEN before they
        // reach here, so there is no constant to accidentally branch on, log, or map to a status.
        if (! $passwordReset->reset($request->credentials(), $request)) {
            // ONE SENTENCE, ON `token`, FOR ALL FOUR FAILURE MODES. Thrown rather than returned so the
            // 422 envelope is the same one every FormRequest failure produces — a hand-built body here
            // would be a second 422 shape for the SPA's error mapper to get wrong.
            //
            // NOT AUDITED: AuditLogger::OPERATIONS has no `auth.password_reset.failed`, and record()
            // throws on an unknown operation rather than writing a row whose allow-list nobody has
            // decided. Guessing is priced by throttle:password-reset, not by an audit row.
            throw ValidationException::withMessages(['token' => [ResetPasswordRequest::INVALID]]);
        }

        // NO `Auth::login()`, NO `session()->regenerate()`. See the class docblock: the reset is what
        // kills every existing session for this user, and a session created here would be the first
        // casualty.
        return AcknowledgementResource::ok();
    }
}
