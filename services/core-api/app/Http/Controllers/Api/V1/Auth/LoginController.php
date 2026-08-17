<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\SessionResource;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionOrganizationResolver;
use App\Support\Contracts\ResponseShape;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Establish the admin session.
 *
 * THE SIX CHECKS (kb-security-baseline §18.4) — and this is the ONE action in the application where
 * the first four are deliberately absent, because it is the action that creates the thing they read:
 *   1. authenticated identity   ESTABLISHED HERE. `Auth::guard('web')->attempt()` is check 1.
 *   2. organization membership  N/A — this route is user-scoped, not org-scoped, and carries no
 *                               `{organization}` segment. It must NOT carry `org.member`:
 *                               TenantContext::handle() throws AuthorizationException when the
 *                               segment is absent, so it would 403 every login.
 *   3. role / permission        N/A — there is no record and no organization to hold a role in.
 *   4. entity ownership         N/A, same reason.
 *   5. entity status            NOT APPLIED, and that is a decision rather than an omission: login
 *                               succeeds for a user whose only membership is suspended, and for one
 *                               with no membership at all, returning `current_organization_id: null`.
 *                               Refusing would make POST /auth/email/verification-notification —
 *                               which requires `auth:sanctum` — unreachable, i.e. the only way out
 *                               of the broken state would require the state not to be broken.
 *   6. rate limit               `throttle:login` on the route: 5/min per account AND 20/min per IP.
 *                               Per-IP alone lets a botnet spray one account; per-account alone lets
 *                               one host walk the user table.
 *
 * WHY BAD CREDENTIALS ARE 422 `validation` AND NOT 401 `authentication`. Two reasons, and the first
 * is decisive. (a) Every SPA installs a global 401 interceptor that redirects to /login; a 401 FROM
 * /login is a redirect loop, and the workaround — special-casing one URL inside the interceptor — is
 * a rule nobody remembers when the next client is written. (b) The taxonomy fits: the login request
 * carries no session and no token that could be missing, expired or invalid, which is what
 * `authentication` describes. It carries a form, and the form failed. The 401s this endpoint CAN
 * produce are real ones — a `TokenMismatchException` (rendered 401 by bootstrap/app.php) and a
 * request whose Origin does not match `sanctum.stateful`, where no session middleware ran at all.
 */
final class LoginController extends Controller
{
    /**
     * WHY 200 AND NOT 204 (decision D8): an empty `#[ResponseShape]` would have `operation()` emit
     * `properties => []`, which is not valid JSON Schema — and `responseShapeFor()` now REFUSES that
     * attribute by name rather than publishing it, so the convention is enforced rather than merely
     * followed. Returning the session state is also what lets the SPA skip a `GET /me` round trip
     * immediately after login.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => SessionResource::class],
        description: 'The established session: the user, the resolved current organization (which '
            .'may be null) and every membership. Wrapped in `data` because the action returns the '
            .'Resource itself and Laravel wraps it. Byte-for-byte the same shape GET /me returns, so '
            .'the SPA has one reducer.',
        errors: [401, 422, 429, 500, 503],
    )]
    public function store(
        LoginRequest $request,
        SessionOrganizationResolver $resolver,
        AuditLogger $audit,
    ): SessionResource {
        $credentials = $request->credentials();

        // `attempt()` on the `web` guard, not a hand-rolled Hash::check. SessionGuard::attempt()
        // TIMEBOXES both failure modes to 200 ms (`auth.timebox_duration`, defaulted) and calls
        // returnEarly() on SUCCESS only — so the measurable timing signal is success-versus-failure,
        // never exists-versus-absent. A hand-rolled comparison would answer an unknown address in
        // microseconds and a wrong password in the cost of a bcrypt verify, which is the enumeration
        // oracle with a stopwatch.
        if (! Auth::guard('web')->attempt($credentials)) {
            // AUDITED BEFORE THE THROW, because ValidationException leaves this method immediately.
            // `auth.login.failed` is an ON_FAILURE_LOG operation (AuditLogger::OPERATIONS), so a
            // failed audit write logs at ERROR and does not turn a 422 into a 500 — which is why
            // this call needs no transaction around it.
            $audit->record(
                AuditLogger::LOGIN_FAILED,
                // Both null, and necessarily so: there is no proven identity and therefore no
                // organization. AuditLogger's own docblock says as much for this operation.
                organizationId: null,
                actorId: null,
                details: [
                    'email' => $credentials['email'],
                    // COARSE ON PURPOSE, and this is a deliberate narrowing of what
                    // AuditLogger::LOGIN_FAILED's allow-list would permit. Distinguishing
                    // unknown-address from wrong-password here would cost one more
                    // `retrieveByCredentials()` probe on the failure path, OUTSIDE the guard's
                    // 200 ms timebox — reintroducing, in our own code, a differential the framework
                    // spent a timebox closing. The forensic value is not lost: an investigator
                    // reading this row can ask whether a `users` row exists for that address at the
                    // time they read it, which is the same question answered from the same database.
                    'reason' => 'invalid_credentials',
                ],
                request: $request,
            );

            // On `email` ONLY. A message under `password` would say "the address exists, the secret
            // is wrong". One sentence, one key, for a wrong password and for an address that has
            // never existed.
            throw ValidationException::withMessages(['email' => [LoginRequest::FAILED]]);
        }

        // `Auth::guard('web')->user()` and NOT `$request->user()`: the request's user resolver reads
        // the DEFAULT guard, and on this application that is a configurable value
        // (`AUTH_GUARD`, config/auth.php:8). Naming the guard makes the login path independent of it.
        $user = Auth::guard('web')->user();

        if (! $user instanceof User) {
            // Unreachable: attempt() returning true means the guard set the user. Asserted rather
            // than assumed because everything below reads $user, and a null here would render as a
            // TypeError 500 pointing at the resolver instead of at the guard.
            throw ValidationException::withMessages(['email' => [LoginRequest::FAILED]]);
        }

        // SESSION FIXATION. regenerate() is migrate() + regenerateToken(): a new session id AND a new
        // CSRF token, carrying the existing session data across. Without it an attacker who fixed the
        // victim's session id before login holds a session that is now authenticated as the victim.
        //
        // CONSEQUENCE FOR THE SPA, and it is the one integration detail people get wrong: the CSRF
        // token changed, so a client that cached `XSRF-TOKEN` at boot will fail its first mutation
        // after login. Read the cookie fresh per request.
        $request->session()->regenerate();

        // `null` as the preference: nothing has been chosen yet, so the resolver picks the oldest
        // active membership. Not the value left in the pre-login session — that session belonged to
        // whoever was there before.
        $snapshot = $resolver->snapshot($user, null);

        $request->session()->put('current_organization_id', $snapshot->currentOrganizationId);

        $audit->record(
            AuditLogger::LOGIN_SUCCEEDED,
            // The resolved organization, which may be null. It is recorded because "which tenant did
            // this session start in" is the first question asked of a login row, and it is NOT read
            // as authority by anything: the org-scoped routes re-read membership per request.
            organizationId: $snapshot->currentOrganizationId,
            actorId: $user->id,
            details: [
                'email' => $user->email,
                // 'session' for the SPA cookie. The other value this field will ever hold is
                // 'token', if a mobile personal-access-token surface is ever built — which is why
                // the field exists now rather than being inferred from the absence of anything else.
                'mechanism' => 'session',
            ],
            request: $request,
        );

        return new SessionResource($snapshot);
    }
}
