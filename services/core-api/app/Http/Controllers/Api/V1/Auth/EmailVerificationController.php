<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyEmailRequest;
use App\Http\Resources\AcknowledgementResource;
use App\Services\Auth\EmailVerificationService;
use App\Support\Contracts\ResponseShape;

/**
 * `POST /api/v1/auth/email/verify` — consume an emailed verification token.
 *
 * GUEST-REACHABLE ON PURPOSE. The link is opened from a mail client, frequently in a different browser
 * from the one that registered, so requiring a session here would strand exactly the people it is for.
 * The token is the credential.
 *
 * ── WHY THIS ACTION IS FOUR LINES ───────────────────────────────────────────────────────────────
 *
 * Every decision it could make has been moved to where it can be made once:
 * App\Http\Requests\VerifyEmailRequest validates the shape, EmailVerificationService::verify() decides
 * whether the token still confers anything, and the repository owns the transaction that the
 * `ON_FAILURE_ABORT` audit row requires. What is left here is the mapping from that one boolean onto
 * two responses — and that mapping is the security property, so it is the thing worth reading:
 *
 *   * IDEMPOTENT SUCCESS. An already-verified user, or the SAME link clicked twice, gets the same 200
 *     and a byte-identical body. A mail client pre-fetching link previews, or a double click, must not
 *     produce an error page. (`verify()` therefore checks "already verified" BEFORE it checks
 *     "consumed" — its docblock explains why that order looks backwards and is not.)
 *   * Unknown, expired, consumed-but-unverified, and address-no-longer-matching all answer ONE 404
 *     `authorization` with the shared deny body ('The requested resource was not found.', assembled by
 *     bootstrap/app.php's render closure). Four reasons, one response: telling them apart would say
 *     whether a guessed token was ever real, and when it expired.
 *   * The row carries the address the token was ISSUED FOR, and `verify()` compares it against
 *     `users.email` — a token minted before an email change must not verify the new address. That is
 *     why `email_verification_tokens` has an `email` column instead of this being a boolean flag.
 *   * `AuditLogger::EMAIL_VERIFIED` is `ON_FAILURE_ABORT`, so its row goes in the SAME transaction as
 *     the `email_verified_at` write — inside
 *     EloquentEmailVerificationTokenRepository::consume(), which takes the audit write as a closure
 *     precisely because `DB` is arch-banned outside App\Repositories\Eloquent. No audit row is written
 *     for the idempotent no-op: nothing happened, and a link preview must not be able to fill the
 *     audit table.
 *
 * ── `abort(404)` AND NOT A 422 ──────────────────────────────────────────────────────────────────
 *
 * A 422 with a field error on `token` would be the natural FormRequest-shaped answer, and it is the
 * shape the sibling password-reset endpoint uses. It is wrong here for one reason: `errors` is a
 * per-FIELD map, so a 422 body distinguishing "no such token" from "expired" would need two different
 * strings under the same key to be useful, and the moment there is one string the body is identical to
 * this 404 in everything but status. 404 `authorization` is the deny the taxonomy already has for
 * "this capability does not address anything you may act on", and it collapses to one body by
 * construction rather than by remembering to reuse a constant.
 *
 * THE SIX CHECKS (kb-security-baseline §18.4):
 *   1. authenticated identity   N/A — guest-reachable by design; the TOKEN is the authority, and it is
 *                               32 bytes of CSPRNG entropy behind a unique index.
 *   2. organization membership  N/A — verification is a fact about a user, and this table has no
 *                               `organization_id` at all.
 *   3. role / permission        N/A — there is no ability to check; the capability IS the token.
 *   4. entity ownership         THE ONE CHECK THIS ACTION DOES MAKE, and it is `verify()`'s address
 *                               comparison: the row's `email` must still be the user's own address.
 *   5. entity status            expiry and consumption, both in `verify()`.
 *   6. rate limit               `throttle:verification` on the route, and its per-account axis is keyed
 *                               correctly FOR A GUEST ROUTE: when `$request->user()` is null it falls
 *                               back to the SHA-256 of the submitted token, not to a literal 'anon'
 *                               (D24). The distinction is the whole limiter — an 'anon' bucket would
 *                               have meant six verification attempts per hour for the entire internet,
 *                               a global choke rather than a per-caller limit, on a route that is
 *                               necessarily guest-reachable because mail links open in another browser.
 *                               What the token-digest key bounds is REPLAY of one link; guessing across
 *                               links is bounded by the token being 256 bits, not by a limiter.
 */
final class EmailVerificationController extends Controller
{
    #[ResponseShape(
        status: 200,
        properties: ['data' => AcknowledgementResource::class],
        description: 'The address is verified. IDENTICAL for a token consumed just now and for a user '
            .'who was already verified — a link preview must not produce an error.',
        // 401 IS PUBLISHED ON A GUEST ROUTE ON PURPOSE, and it is not about being signed in.
        // Two producers reach it. `PreventRequestForgery` (from `statefulApi()`) throws 419 for a
        // dead or absent XSRF cookie, and the render closure maps 419 -> `authentication`/401
        // (bootstrap/app.php: the `$httpStatus === 401, $httpStatus === 419` arm). `RejectBearerToken`
        // is the second: an `Authorization: Bearer` header anywhere on the `api` group is a 401.
        // Omitting it made the document assert a status is impossible when it is reachable with one
        // expired tab -- the mirror image of the 409 that D36 removed for being unreachable.
        errors: [401, 404, 422, 429, 500, 503],
    )]
    public function store(
        VerifyEmailRequest $request,
        EmailVerificationService $verification,
    ): AcknowledgementResource {
        // $request is handed to the service ONLY so the audit row carries the caller's IP and user
        // agent. Nothing else in the body is read: `token()` is the whole of the validated input.
        abort_unless($verification->verify($request->token(), $request), 404);

        return AcknowledgementResource::ok();
    }
}
