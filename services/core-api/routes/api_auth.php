<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\AcceptInvitationController;
use App\Http\Controllers\Api\V1\Auth\CurrentOrganizationController;
use App\Http\Controllers\Api\V1\Auth\CurrentSessionController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\InvitationPreviewController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\NewPasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\Auth\RegisteredUserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication & session — Surface: admin (403 deny)
|--------------------------------------------------------------------------
|
| A FIFTH FILE, NOT A FIFTH GROUP. Mounted at `api/v1` on the SAME `api` middleware group that
| routes/api_admin.php uses, by bootstrap/app.php — see the amended "FOUR DISJOINT GROUPS" comment
| there. Every route below carries `surface:admin`, so the deny split, the error envelope and the
| session/CSRF stack are identical to the admin API's. Nothing here is a new surface.
|
| WHY THESE ROUTES ARE NOT IN routes/api_admin.php. That file's own docblock states as an INVARIANT
| that every route in it (a) sits under `{organization}` with `->scopeBindings()` and (b) carries
| `auth:sanctum` + `org.member`. Login satisfies none of the three, and `/me` satisfies only the
| second. Putting them there would make that docblock false and would recreate, inside the admin
| file, exactly the hazard bootstrap/app.php names: one file where a route silently inherits the
| wrong stack.
|
| TWO GROUPS, AND THE DIVIDING LINE IS `auth:sanctum`:
|
|   GROUP A — GUEST. The credential these routes exist to establish (or to re-establish, or to
|   accept) does not exist yet, so `auth:sanctum` must NOT be applied. They are still STATEFUL,
|   because they come from the `api` group: `Auth::guard('web')->login()` writes to the session and
|   PreventRequestForgery needs the token, and both come from EnsureFrontendRequestsAreStateful.
|
|   GROUP B — AUTHENTICATED, AND DELIBERATELY WITHOUT `org.member`. These are USER-scoped, not
|   ORG-scoped: none of them carries an `{organization}` path segment, and
|   App\Http\Middleware\TenantContext::handle() reads `$request->route('organization')` and THROWS
|   AuthorizationException when the segment is absent. `org.member` on `/me` would therefore 403
|   every single request — a fail-closed direction that is correct for the middleware and fatal for
|   these routes. That absence is the whole reason this file exists separately.
|
| `surface:admin` IS APPLIED EXPLICITLY, on both groups, even though AppServiceProvider already
| defaults the container binding to Surface::Admin. That default exists ONLY so a policy resolved
| outside an HTTP request — an artisan command, a queued job — has something rather than a container
| error. Relying on it here would make the surface IMPLICIT at exactly the place an audit needs to
| read it, and an admin 403 rendered on a route someone later moves to a public surface is an
| enumeration oracle.
|
| EVERY ACTION CARRIES #[ResponseShape]. `DumpOpenApiCommand::SURFACES` is ['api/', 'rt/', 'sdk/'],
| so each of these is a CLIENT route and `php artisan kb:dump-openapi --check` fails on any action
| without one. That is deliberate: a client-facing route with no declared shape is a route no client
| can be generated for, and skipping it would publish a document quietly missing an endpoint.
|
| NO TOKEN EVER APPEARS AS A PATH SEGMENT (laravel-sanctum-auth NN4). The invitation, password-reset
| and email-verification tokens are all bearer capabilities, and a capability in a URL lands in
| Traefik access logs, in `Referer` on the next navigation, and in browser history. Every one of them
| arrives in a POST body — which is why `POST /auth/invitations/preview` exists instead of
| `GET /auth/invitations/{token}`, and why the REST awkwardness is accepted.
|
*/

// ── GROUP A — GUEST ─────────────────────────────────────────────────────────────────────────────
//
// NO `auth:sanctum`. Each route names its own throttle, because the axes differ: login is per
// account and per IP, a reset REQUEST is tighter on the account axis than login (it sends mail to
// somebody else's mailbox), a reset SUBMISSION is keyed on the token being guessed, and the
// invitation family is keyed on the token digest because the email on those routes is
// attacker-supplied.
Route::middleware(['surface:admin'])->group(function (): void {
    Route::post('/auth/login', [LoginController::class, 'store'])
        ->middleware('throttle:login')
        ->name('auth.login');

    Route::post('/auth/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:password-request')
        ->name('auth.password.email');

    Route::post('/auth/reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('auth.password.update');

    // POST, not GET, and the token is in the body. See the NN4 note above.
    Route::post('/auth/invitations/preview', [InvitationPreviewController::class, 'store'])
        ->middleware('throttle:invitation')
        ->name('auth.invitations.preview');

    Route::post('/auth/register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:invitation')
        ->name('auth.register');

    // GUEST-REACHABLE ON PURPOSE: a verification link is opened from a mail client, often in a
    // different browser from the one that registered. Requiring a session here would strand exactly
    // the people the link is for.
    Route::post('/auth/email/verify', [EmailVerificationController::class, 'store'])
        ->middleware('throttle:verification')
        ->name('auth.verification.verify');
});

// ── GROUP B — AUTHENTICATED, USER-SCOPED ────────────────────────────────────────────────────────
//
// `auth:sanctum` + `surface:admin`, and NO `org.member` — see the header. `verified` is absent from
// this group too, and that is also deliberate: it is applied to the org-scoped WRITE routes in
// routes/api_admin.php, and applying it here would make `/me` unreadable to an unverified user,
// whose `email_verified: false` is the SPA's only way to explain the 403s it gets elsewhere. Worse,
// it would lock the resend-verification route behind verification.
Route::middleware(['auth:sanctum', 'surface:admin'])->group(function (): void {
    // THE RE-VERIFICATION POINT for user-scoped reads: it re-reads every membership from PostgreSQL
    // and REPAIRS a `current_organization_id` whose membership has since been revoked.
    Route::get('/me', [CurrentSessionController::class, 'show'])
        ->middleware('throttle:admin')
        ->name('auth.me');

    Route::post('/auth/logout', [LogoutController::class, 'store'])
        ->middleware('throttle:admin')
        ->name('auth.logout');

    // The ONLY writer of `current_organization_id` besides login and /me's repair. It re-checks
    // membership before writing, which is why nothing downstream has to treat the session value as
    // untrusted — and why there is no `exists:organizations,id` rule to be a global existence oracle.
    Route::post('/session/organization', [CurrentOrganizationController::class, 'store'])
        ->middleware('throttle:admin')
        ->name('auth.session.organization');

    // `throttle:verification` and not `throttle:admin`: this one queues MAIL, and its budget is 6 per
    // hour per user rather than 120 per minute. It takes no request body — the address is the
    // authenticated user's, because a parameter would make this a relay pointed anywhere.
    Route::post(
        '/auth/email/verification-notification',
        [EmailVerificationNotificationController::class, 'store'],
    )
        ->middleware('throttle:verification')
        ->name('auth.verification.send');

    // The signed-in half of the invitation flow. It must NOT carry `org.member`: the caller is by
    // definition not yet a member of the organization being joined, so the middleware would deny the
    // one request whose purpose is to make them one.
    Route::post('/auth/invitations/accept', [AcceptInvitationController::class, 'store'])
        ->middleware('throttle:invitation')
        ->name('auth.invitations.accept');
});
