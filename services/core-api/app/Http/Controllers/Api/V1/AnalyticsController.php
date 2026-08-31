<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShowAnalyticsRequest;
use App\Http\Resources\AnalyticsResource;
use App\Models\Organization;
use App\Services\Analytics\AnalyticsService;
use App\Support\Contracts\ResponseShape;
use Illuminate\Support\Facades\Gate;

/**
 * The organization's usage and quality dashboard (§8.23).
 *
 * THE SIX CHECKS (kb-security-baseline §18.4):
 *   1. authenticated identity        `auth:sanctum` on the route group
 *   2. organization membership       `org.member`, which RE-READS the row from PostgreSQL
 *   3. role / permission             Gate::authorize() below — `analytics.view`
 *   4. entity ownership              the policy resolves membership of THE RECORD's organization,
 *                                    and `->scopeBindings()` 404s a foreign `{organization}` before
 *                                    any of it runs
 *   5. entity status                 NONE, DELIBERATELY. A suspended organization's operator still
 *                                    needs to read their own numbers — and this endpoint writes
 *                                    nothing, so there is no state change to refuse. Refusing it
 *                                    would hide the very data an operator uses to understand why
 *                                    they were suspended. This is the same call `show` on the
 *                                    rerank and embedding configuration endpoints makes.
 *   6. rate limit                    `throttle:admin` on the route group
 *
 * Checks 5 and 6 are the ones reviewers forget, because 1 through 4 are visible in the route file
 * and these two are not.
 *
 * ── THE `bot_id` FILTER IS NOT A SECOND AUTHORIZATION ───────────────────────────────────────
 *
 * A bot id in the query string is validated for SHAPE only — there is no `exists:` rule, because one
 * would be an existence oracle over every organization's bots (`ShowAnalyticsRequest` carries the
 * CVE this mirrors). Ownership is enforced by the repository: every predicate carries the
 * organization AND the bot together, so a foreign bot id matches nothing and the page reads zero.
 * That is deliberately not a 403 or a 404 — the caller is entitled to ask, and the answer is empty.
 */
final class AnalyticsController extends Controller
{
    #[ResponseShape(
        status: 200,
        properties: ['data' => AnalyticsResource::class],
        description: 'Every §8.23 tile for one organization and one window, wrapped in `data` '
            .'because the action returns the Resource itself and Laravel wraps it. A nonexistent '
            .'`{organization}` 404s at binding time, before this action runs.',
        // NO 409: this action writes nothing and a suspended organization may read it. 422 IS
        // reachable — an inverted window, a bot id that is not a ULID, or a window wider than
        // ShowAnalyticsRequest::MAX_WINDOW_DAYS.
        errors: [401, 403, 404, 422, 429, 500],
    )]
    public function __invoke(
        ShowAnalyticsRequest $request,
        Organization $organization,
        AnalyticsService $analytics,
    ): AnalyticsResource {
        // CHECKS 3 AND 4. The record IS the organization, which already implements OrgOwned, so
        // permit() still resolves membership of the record's organization and "admin of some
        // organization" stays unrepresentable.
        Gate::authorize('viewAnalytics', $organization);

        $window = $request->toWindow();

        return new AnalyticsResource(
            $analytics->snapshot($organization, $window),
            // THE RESOLVED WINDOW, ECHOED. `from` and `until` both have server-side defaults, so a
            // caller that sent neither cannot label the numbers without it.
            $window,
        );
    }
}
