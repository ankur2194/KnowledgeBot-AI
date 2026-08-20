<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrgUploadLimitsResource;
use App\Models\Organization;
use App\Support\Contracts\ResponseShape;
use Illuminate\Support\Facades\Gate;

/**
 * `GET .../sources/upload-limits` — what this deployment will accept, before anything is sent.
 *
 * ── IT IS A READ OF THE ENFORCEMENT, NOT A SECOND STATEMENT OF IT ────────────────────────────
 *
 * Every number this returns is read from `UploadLimits`, which reads `StoreSourceRequest`'s
 * constants, which are what the FormRequest and `UploadIntake` actually enforce. That chain is the
 * whole design: the alternative is a client that hardcodes the ceiling, which is one deployment
 * away from a green upload that 422s and one release away from nobody remembering which copy is
 * authoritative.
 *
 * ── THE ROUTE IS REGISTERED BEFORE `/sources/{source}` AND THAT IS LOAD-BEARING ──────────────
 *
 * `Illuminate\Routing\RouteCollection` matches in registration order, so a literal segment that
 * shares a prefix with a parameterised one must be declared first. Registered after it,
 * `upload-limits` would bind as `{source}`, fail `$organization->sources()` scoped resolution, and
 * 404 — a 404 that looks exactly like "the endpoint does not exist", on an endpoint that does. The
 * route file says so at the declaration; it is repeated here because this is where somebody looking
 * at the 404 would land.
 *
 * ── THE SIX CHECKS (kb-security-baseline §18.4) ──────────────────────────────────────────────
 *
 * 1. AUTHENTICATED IDENTITY — `auth:sanctum` on the route group. These numbers are not secret, and
 *    that is not why the route is authenticated: it lives inside the organization-scoped group
 *    because every route in that group is, and a public exception would be a new surface with its
 *    own limiter and its own reasoning for no benefit.
 * 2. ORGANIZATION MEMBERSHIP — `org.member`, re-reading `organization_users` per request.
 * 3. ROLE / PERMISSION — `Gate::authorize('uploadSource', $organization)`, i.e. `sources.upload`,
 *    as the FIRST STATEMENT of the body. NOT `viewSources`: this is the upload surface's
 *    configuration, and a role that may read the source list but not upload has no use for it. The
 *    pairing also means the permission that governs the POST governs its precondition, so a role
 *    change cannot leave a console able to read the limits and unable to act on them, or the
 *    reverse.
 * 4. ENTITY OWNERSHIP — on the PARENT, because there is no source row involved at all. The scoped
 *    group has already resolved `{organization}` and `OrganizationPolicy` resolves membership of
 *    THAT record.
 * 5. ENTITY STATUS — NONE, DELIBERATELY, and it is the one check worth arguing. `index` and `show`
 *    on this surface have none either: reading what the platform accepts is exactly what an
 *    operator of a suspended organization does while working out what to do about it, and a 409
 *    here would leave the console unable to render the reason it is blocked. The enforcement is on
 *    `POST .../sources`, which does check organization status and refuses with
 *    `OrganizationStatus::SUSPENDED_REFUSAL`. NOTE THE COST HONESTLY: a console that reads limits
 *    successfully may still render an enabled drop zone for a suspended organization, and the file
 *    is refused on submit. That is a client-side affordance problem with a client-side fix — the
 *    organization's status is already on every page the console renders — and it is a better trade
 *    than an endpoint that goes dark exactly when someone is trying to understand why they are
 *    blocked.
 * 6. RATE LIMIT — `throttle:admin` on the group, plus `verified`. No §18.3 re-authentication: this
 *    action reads three constants, changes nothing, and touches no credential.
 *
 * ── NO ORGANIZATION-SPECIFIC VALUE IS RETURNED TODAY, AND THE ROUTE IS ORG-SCOPED ANYWAY ─────
 *
 * Every number is a platform constant, so this could have been a global endpoint — and it is not,
 * because per-plan ceilings are a named requirement (docs/03 §8.10, "configurable per-organization
 * storage limits") and moving a client from a global URL to an org-scoped one later is a breaking
 * change for the sake of a URL segment saved now.
 */
final class UploadLimitsController extends Controller
{
    #[ResponseShape(
        status: 200,
        properties: ['data' => OrgUploadLimitsResource::class],
        description: 'The upload ceilings this deployment enforces, wrapped in `data`: the per-file '
            .'byte limit, every media type the server may read out of an uploaded file\'s content, '
            .'and the number of files one request may carry. THESE ARE THE SAME CONSTANTS THE '
            .'SERVER VALIDATES AGAINST — read them rather than restating them, because a client '
            .'that hardcodes a ceiling renders a green upload that 422s the moment the server\'s '
            .'value moves. No 409 for a suspended organization: the numbers are what stop being '
            .'usable, not what stops being true, and `POST .../sources` is where the refusal lives.',
        errors: [401, 403, 404, 429, 500, 503],
    )]
    public function __invoke(Organization $organization): OrgUploadLimitsResource
    {
        // CHECKS 3 AND 4, ON THE PARENT. There is no source row to take an organization from and
        // there never will be one on this route — it describes the act of creating one.
        Gate::authorize('uploadSource', $organization);

        // No 409 — see the class docblock, check 5.
        return new OrgUploadLimitsResource;
    }
}
