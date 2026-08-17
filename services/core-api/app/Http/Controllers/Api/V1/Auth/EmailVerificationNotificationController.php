<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\AcknowledgementResource;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use App\Support\Contracts\ResponseShape;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/auth/email/verification-notification` — send the verification mail again.
 *
 * THIS IS THE ENDPOINT THE WHOLE "LOGIN SUCCEEDS FOR AN UNVERIFIED USER" DECISION EXISTS FOR. It
 * requires `auth:sanctum`, so if an unverified email had blocked login there would be no way to ask for
 * the mail again — the classic dead end where the only exit from a broken state requires the state not
 * to be broken. It takes NO request body: the address is the authenticated user's, never a parameter,
 * because a parameter would make this a mail relay pointed at any address an attacker chose.
 *
 * WHAT THE IMPLEMENTATION MUST NOT LOSE — and each of these is now a line of code or a deliberate
 * absence of one:
 *   * ONE RESPONSE whether or not the user was already verified. A distinct "already verified" reply is
 *     harmless here (the caller is authenticated as that user) but a single shape is one fewer branch,
 *     and it matches every other acknowledgement on this surface.
 *   * A RESEND INVALIDATES THE PREVIOUS LIVE TOKEN. `email_verification_tokens_one_live_per_user` is a
 *     partial UNIQUE index, so the mint is DELETE-then-INSERT in one transaction — which is also why an
 *     expired-but-unconsumed row does not wedge this path. That transaction is
 *     EloquentEmailVerificationTokenRepository::replaceForUser(); it locks the `users` row first, so two
 *     clicks on "resend" serialise instead of racing into a 23505.
 *   * The notification stays `ShouldQueue` on the `notify` queue. `config/queue.php` defaults the
 *     connection's queue to `ai-dispatch`; auth mail queued behind ingestion submissions is an unbounded
 *     delay on a token with a fixed lifetime. That is App\Notifications\VerifyEmailAddress::viaQueues()
 *     and nothing here may override it.
 *
 * ── NO FormRequest, AND THAT IS A CONTRACT RATHER THAN AN OMISSION ──────────────────────────────
 *
 * The action takes `Illuminate\Http\Request`. There is deliberately no `ResendVerificationRequest`,
 * because there is no field to validate: the address is the authenticated user's. Adding an empty
 * FormRequest would publish `packages/contracts/rules/…​.json` for an endpoint that accepts nothing,
 * which the web design (§7.5) pins as having no Zod schema and no manifest entry — so a FormRequest
 * here breaks an artifact contract on the other side of the boundary in exchange for nothing.
 *
 * ── WHY THE ALREADY-VERIFIED GUARD IS HERE AND NOT IN THE SERVICE ───────────────────────────────
 *
 * EmailVerificationService::send() mints unconditionally, and both of its callers ask the question
 * themselves: the framework's auto-registered SendEmailVerificationNotification listener checks
 * `! $user->hasVerifiedEmail()` before it ever calls the model method, and this action checks it below.
 * A third copy inside the service would be the one that silently swallows a deliberate re-mint.
 *
 * THE SIX CHECKS: 1 is `auth:sanctum`. 2 is deliberately NOT `org.member` — this is user-scoped, there
 * is no `{organization}` segment, and the middleware throws when the segment is absent. 3-4 N/A: the
 * subject is the caller. 5 NOT APPLIED, deliberately — `verified` must never gate this route, which is
 * the route an unverified user needs. 6 is `throttle:verification`, 6 per hour per user and 20/min per
 * IP: the per-user axis is what stops this being a mailbox flood aimed at the account's own owner.
 */
final class EmailVerificationNotificationController extends Controller
{
    #[ResponseShape(
        status: 200,
        properties: ['data' => AcknowledgementResource::class],
        description: 'Acknowledged. IDENTICAL whether mail was queued or the user was already '
            .'verified — one shape, one fewer branch for the SPA.',
        errors: [401, 429, 500, 503],
    )]
    public function store(
        Request $request,
        EmailVerificationService $verification,
    ): AcknowledgementResource {
        $user = $request->user();

        // `auth:sanctum` already guaranteed this, so the guard is here only because
        // Authenticatable is not User to static analysis. It answers with the SAME body rather than a
        // 401 because reaching it would mean the guard resolved something that is not our user model,
        // which is a defect, not a client error — and a 500 on this route would strand the one exit
        // from an unverified account.
        if (! $user instanceof User) {
            return AcknowledgementResource::ok();
        }

        // ONE RESPONSE, TWO PATHS. An already-verified user gets no mail and no new token: re-minting
        // for them would leave a live capability nobody asked for and would let this endpoint be used
        // to send mail to a verified address at 6/hour indefinitely.
        if (! $user->hasVerifiedEmail()) {
            // Mints, invalidates the previous live token, and queues the mail — all inside the
            // service, so the TTL and the invalidation rule have one home.
            $verification->send($user);
        }

        // NO AUDIT ROW. `AuditLogger::OPERATIONS` has no operation for "a verification mail was
        // requested", and inventing one would need an allow-list decision and a policy row of its own
        // — AuditLogger::record() throws on an unknown operation precisely so that decision cannot be
        // made by a literal at a call site. The event is a request for mail, not a state change to the
        // account; the state change is audited where it happens, in EmailVerificationController.
        return AcknowledgementResource::ok();
    }
}
