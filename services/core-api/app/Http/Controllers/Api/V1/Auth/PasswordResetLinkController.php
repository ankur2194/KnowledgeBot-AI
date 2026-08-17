<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Resources\AcknowledgementResource;
use App\Services\Auth\PasswordResetService;
use App\Support\Contracts\ResponseShape;

/**
 * `POST /api/v1/auth/forgot-password` — email a password-reset link.
 *
 * ── ONE RESPONSE FOR THREE OUTCOMES, AND THE THIRD ONE IS THE SUBTLE ONE ─────────────────────────
 *
 * `RESET_LINK_SENT`, `INVALID_USER` and `RESET_THROTTLED` all answer 200 with the SAME body. The first
 * two are the obvious collapse. The third is the one that gets shipped wrong: returning 429 when the
 * framework's broker throttles and 200 when the address is unknown IS AN ORACLE — probe an address
 * twice inside `config('auth.passwords.users.throttle')` (60 s), and a throttle on the second probe
 * proves the first probe CREATED A TOKEN, which proves the account exists. One 429 answers the
 * question the 200 exists to refuse.
 *
 * The collapse is enforced structurally, not by discipline: `PasswordResetService::sendResetLink()`
 * returns `void`, so this action HAS no status to branch on, and `AcknowledgementResource` takes no
 * input, so the body is `{"data":{"acknowledged":true}}` by construction — byte-identical across all
 * three, with no field a future change could add a distinguishing value to.
 *
 * OUR OWN LIMITER STILL RETURNS A REAL 429, and that discloses nothing. `throttle:password-request`
 * keys on `acct:<lowercased email>` AND `ip:<ip>` (AppServiceProvider) and fires identically for an
 * address that exists and one that never has — it counts REQUESTS, not tokens, which is precisely the
 * difference between it and the broker's throttle. The broker's covers only the branch where a token
 * was actually created, so an unknown address is entirely unthrottled by it; ours is what makes
 * walking the user table expensive, and what stops this endpoint being a mailbox flood aimed at
 * somebody else.
 *
 * ── TIMING IS PART OF THE RESPONSE ───────────────────────────────────────────────────────────────
 *
 * `PasswordBroker::sendResetLink()` wraps everything in a 200 ms `Timebox` (`auth.timebox_duration` is
 * absent from config/auth.php, so the constructor default of 200000 µs applies) and — unlike
 * `reset()` — never calls `returnEarly()`, so EVERY outcome including success is padded to the same
 * floor. That is the whole defence, and it has one load-bearing prerequisite: the mail is sent INSIDE
 * the box, so `App\Notifications\ResetPassword` must be `ShouldQueue`. VERIFIED — it implements
 * `ShouldQueue` and pins itself to the `notify` queue. A synchronous SMTP handshake there would exceed
 * 200 ms on the exists-branch ONLY, and the difference is measurable from the internet with no
 * credentials at all.
 *
 * ── THE SIX CHECKS (kb-security-baseline §18.4) ──────────────────────────────────────────────────
 *   1. authenticated identity   N/A — this route exists to help someone who cannot authenticate. It
 *                               must NOT carry `auth:sanctum`.
 *   2. organization membership  N/A — user-scoped, no `{organization}` segment. `org.member` would
 *                               403 every request (TenantContext throws when the segment is absent).
 *   3. role / permission        N/A — no record, no organization, no role to hold.
 *   4. entity ownership         N/A — no entity is addressed. The submitted address is a *claim*, and
 *                               possession of the mailbox is what the emailed token proves.
 *   5. entity status            N/A, AND ITS ABSENCE IS THE POINT: the response is identical whether
 *                               a user exists, is unverified, is suspended, or has no membership at
 *                               all. Any status check here would be a status ORACLE.
 *   6. rate limit               `throttle:password-request` on the route: 3 per 15 minutes per
 *                               account AND 10/min per IP. The per-account axis is TIGHTER than
 *                               login's because this endpoint sends mail to somebody else's mailbox.
 *
 * ── ERRORS ───────────────────────────────────────────────────────────────────────────────────────
 * 422 `validation` (malformed or missing address — a 422 cannot correspond to an account, so the
 * difference from the 200 discloses nothing); 429 `rate_limit` + `Retry-After`; 500/503
 * `internal_dependency` if PostgreSQL or Valkey is unreachable. NOTHING ELSE — in particular no 404
 * and no 403, either of which would be an existence answer.
 */
final class PasswordResetLinkController extends Controller
{
    #[ResponseShape(
        status: 200,
        properties: ['data' => AcknowledgementResource::class],
        description: 'Acknowledged. IDENTICAL for an address that exists, one that does not, and one '
            .'the framework\'s broker throttled — the collapse is the account-enumeration defence, so '
            .'do not add a field that distinguishes them.',
        // 401 IS PUBLISHED ON A GUEST ROUTE ON PURPOSE, and it is not about being signed in.
        // Two producers reach it. `PreventRequestForgery` (from `statefulApi()`) throws 419 for a
        // dead or absent XSRF cookie, and the render closure maps 419 -> `authentication`/401
        // (bootstrap/app.php: the `$httpStatus === 401, $httpStatus === 419` arm). `RejectBearerToken`
        // is the second: an `Authorization: Bearer` header anywhere on the `api` group is a 401.
        // Omitting it made the document assert a status is impossible when it is reachable with one
        // expired tab -- the mirror image of the 409 that D36 removed for being unreachable.
        errors: [401, 422, 429, 500, 503],
    )]
    public function store(ForgotPasswordRequest $request, PasswordResetService $passwordReset): AcknowledgementResource
    {
        // `credentials()` hands over the ONE key the broker may use, already lowercased and trimmed by
        // ForgotPasswordRequest::prepareForValidation(). Normalisation is not optional here: the
        // broker's lookup is an exact `where('email', …)` while `users_email_unique` is on
        // `lower(email)`, so a mixed-case submission silently resolves no user — and this endpoint
        // reports that as the same 200 everything else gets.
        //
        // The whole request object goes to the service because AuditLogger reads `ip_address` and
        // `user_agent` off it. It is passed, never re-read for input: nothing below the FormRequest
        // touches `$request->input()`.
        $passwordReset->sendResetLink($request->credentials(), $request);

        // NO ARGUMENT, NO STATE, NO BRANCH. The resource has no inputs, which is what makes the three
        // collapsed outcomes byte-identical rather than merely similar.
        return AcknowledgementResource::ok();
    }
}
