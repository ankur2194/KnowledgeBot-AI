<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\AcknowledgementResource;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Contracts\ResponseShape;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Destroy the admin session.
 *
 * THE SIX CHECKS (kb-security-baseline §18.4):
 *   1. authenticated identity   `auth:sanctum` on the route. A request with no session 401s BEFORE
 *                               this method runs, which is what makes the action idempotent from the
 *                               client's point of view without being idempotent in code.
 *   2. organization membership  N/A — user-scoped, no `{organization}` segment, and therefore
 *                               deliberately NOT `org.member` (which throws when the segment is
 *                               absent). Logging out of an organization you were just removed from
 *                               must not be the one thing you cannot do.
 *   3. role / permission        N/A — a user is always permitted to end their own session. There is
 *                               no ability to check and no record to authorize.
 *   4. entity ownership         N/A — the subject IS the caller.
 *   5. entity status            NOT APPLIED, deliberately: a suspended membership or a suspended
 *                               organization must not trap a session open.
 *   6. rate limit               `throttle:admin` on the route.
 *
 * ORDER MATTERS AND IS THE WHOLE IMPLEMENTATION. `Auth::logout()` clears the guard's user, so the
 * actor id and the session's organization must be read FIRST; `session()->invalidate()` flushes the
 * data and migrates to a new id, so the organization must be read before that too.
 */
final class LogoutController extends Controller
{
    #[ResponseShape(
        status: 200,
        properties: ['data' => AcknowledgementResource::class],
        description: 'The session is gone. A body rather than a 204 (decision D8): an empty '
            .'#[ResponseShape] publishes `properties: []`, which is not valid JSON Schema.',
        errors: [401, 429, 500, 503],
    )]
    public function store(Request $request, AuditLogger $audit): AcknowledgementResource
    {
        $user = $request->user();
        $actorId = $user instanceof User ? $user->id : null;

        $organizationId = $request->session()->get('current_organization_id');
        $organizationId = is_string($organizationId) ? $organizationId : null;

        Auth::guard('web')->logout();

        // invalidate() = flush() + migrate(destroy: true) — the old session record is DELETED from
        // the store, not merely abandoned. Abandoning it would leave a valid session id in Valkey
        // until its TTL, and a cookie captured earlier would keep working.
        $request->session()->invalidate();

        // AND the CSRF token, separately. invalidate() does not rotate it, so without this line the
        // next login would be issued under the token the just-destroyed session was using — one
        // fewer thing rotated than a session boundary ought to rotate.
        $request->session()->regenerateToken();

        // AFTER the act, because the act is irreversible: a session cookie has already been
        // destroyed by the time this row is written and there is no transaction left to roll back.
        // `auth.logout` is ON_FAILURE_LOG for exactly that reason, so a failed audit write records an
        // ERROR and lets this 200 stand rather than telling a caller they are still logged in when
        // they are not. No transaction is needed around it.
        //
        // `details` is EMPTY, and AuditLogger's allow-list for this operation is `[]` — actor,
        // organization, IP, user agent, request id and timestamp are all columns, so a logout row has
        // nothing left to say. Passing anything here would be silently dropped and warned about.
        $audit->record(
            AuditLogger::LOGOUT,
            organizationId: $organizationId,
            actorId: $actorId,
            details: [],
            request: $request,
        );

        return AcknowledgementResource::ok();
    }
}
