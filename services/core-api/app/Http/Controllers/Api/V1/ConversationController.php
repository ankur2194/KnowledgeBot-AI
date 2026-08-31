<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexConversationsRequest;
use App\Http\Resources\ConversationCollectionResource;
use App\Http\Resources\ConversationTranscriptResource;
use App\Models\Conversation;
use App\Models\Organization;
use App\Services\Conversations\ConversationReader;
use App\Support\Contracts\ResponseShape;
use Illuminate\Support\Facades\Gate;

/**
 * An organization's stored conversations: the thread list, and one thread's transcript.
 *
 * ── READ-ONLY, AND THAT IS THE WHOLE SURFACE ─────────────────────────────────────────────────
 *
 * There is no create, no edit and no delete here. A thread is opened by the public runtime, written
 * by the relay's finalizer, and removed by the retention sweeper or by an erasure workflow that
 * belongs to `deletion-engineer` — because §18.11 treats a transcript as EVIDENCE, and a surface
 * that could both read and destroy it would be a surface that can rewrite the record it exists to
 * preserve.
 *
 * ══ THE §18.10 PRIVACY SWITCH IS SPECIFIED, IS NOT IMPLEMENTED, AND IS NOT INVENTED HERE ═════
 *
 * docs/04 §8.22 says, verbatim: *"Administrators may be allowed to view conversations only when the
 * organization's privacy policy enables it."* docs/13 §18.10 lists the same control among six
 * per-organization switches — store conversations at all, retention duration, ADMIN REVIEW OF
 * CONVERSATIONS, anonymous metadata, feedback comments, crawl snapshots — and says each should
 * default to the privacy-preserving value.
 *
 * TODO(unassigned): NO COLUMN EXISTS FOR ANY OF THE SIX. Measured on 2026-08-27, and the commands
 * are quoted rather than the conclusion, because an absence claimed without one is what
 * `docs/22` § Q8 is about:
 *
 *   grep -rniE 'review|privacy' services/core-api/database/migrations/   -> 13 hits, every one of
 *       them prose in a docblock or a comment; `grep -rn '_review\|privacy_'` over the same tree
 *       returns 0, so none of the 13 is a column name.
 *   grep -rn -- "->settings\|'settings'" app/ tests/ database/factories/   -> 3 hits, and all
 *       three are DECLARATIONS rather than uses: the `$fillable` entry, the `array` cast, and the
 *       factory's `[]` default. Nothing reads a key out of that column and nothing writes one.
 *
 * So `organizations.settings` — `jsonb NOT NULL DEFAULT '{}'` — is the eventual home of these six
 * switches and currently defines no key vocabulary at all. Inventing one here would be inventing a
 * column, in a controller, for a setting nobody can set and no screen can show.
 *
 * SO THIS ENDPOINT SHIPS WITHOUT THE GATE, AND THE GAP IS NAMED RATHER THAN LEFT TO BE NOTICED.
 * What that costs, precisely: an organization has no way to withhold transcripts from its own
 * administrators, and the platform's answer to "who may read a customer's words" is the role matrix
 * alone. What limits it: `conversations.view` is Owner/Admin/Analyst, the Knowledge Manager is
 * refused, and every read is org-scoped and rate-limited. When the switch lands it is CHECK 5
 * (entity status) on `index` and `show` — an `abort_unless(...)` beside the Gate call, not a
 * permission and not a policy argument, because `OrgScopedPolicy::permit()` has no position for it
 * — and it should land together with the other five, as one decision about privacy posture rather
 * than as six columns added one endpoint at a time.
 *
 * ── THE CONTROLLER SIGNATURE ORDER IS LOAD-BEARING ───────────────────────────────────────────
 *
 * `ImplicitRouteBinding::resolveForRoute()` iterates the ACTION'S SIGNATURE and calls
 * `$route->setParameter()` as it goes, and `Route::parentOfParameter()` reads the parameter bag as
 * it stands — it returns `array_values($this->parameters)[$key - 1]`, the PRECEDING bound parameter
 * and not the first one. So the parent must already be a model when the child is resolved, which it
 * is only if the signature lists them in path order. `show()` takes `Organization` and then
 * `Conversation`.
 *
 * `$organization` IS USED IN `show()`'s BODY (the reader needs it), which is a difference from
 * `SourceController::show()` — there the parameter is unused and a comment begs nobody to remove
 * it. Here removing it would break the read as well as the binding, which is the safer accident.
 *
 * ── THE SIX CHECKS (kb-security-baseline §18.4), PER ACTION ─────────────────────────────────
 *
 * 1. AUTHENTICATED IDENTITY — `auth:sanctum` on the route group. The admin surface is the Sanctum
 *    SPA cookie session, not a bearer token.
 *
 * 2. ORGANIZATION MEMBERSHIP — `org.member`, which RE-READS `organization_users` from PostgreSQL on
 *    every request. Neither a session value nor a token row is evidence of CURRENT membership.
 *
 * 3. ROLE / PERMISSION — `Gate::authorize()` as the FIRST STATEMENT of every body, demanding
 *    `conversations.view`. `$this->authorize()` does not exist: since Laravel 11 the base
 *    controller no longer uses AuthorizesRequests, and calling it fatals at runtime rather than at
 *    analysis time.
 *
 * 4. ENTITY OWNERSHIP — three layers. The scoped binding 404s a foreign or unknown `{conversation}`
 *    at BINDING time, before any policy is constructed and before the row is in memory; the policy
 *    resolves membership of THE RECORD'S organization through `OrgOwned`; and every repository
 *    method takes `organization_id` as a required positional argument, which below `conversations`
 *    is the only tenancy there is. `index` authorizes against the PARENT ORGANIZATION, because a
 *    list has no row to take an organization from — `OrganizationPolicy::viewConversations()` is
 *    that half.
 *
 * 5. ENTITY STATUS — DELIBERATELY NONE, on either action, and the reason is the same one
 *    `SourceController` gives for its own read actions: reading what was said, and why an answer
 *    was refused, is exactly what a suspended organization's operator needs to do. Neither action
 *    writes anything, so there is no state a 409 would protect. The one status check this surface
 *    SHOULD eventually have is the §18.10 privacy switch above, and it does not exist.
 *
 * 6. RATE LIMIT — `throttle:admin` on the group, plus `verified`, so an unverified address reaches
 *    no tenant data. Check 6 in the §18.3 sense — re-authentication for a destructive action — is
 *    not performed and has nothing to protect: nothing here touches a credential and nothing here
 *    writes.
 *
 * ── NO ACTION HERE CAN REACH A CREDENTIAL ───────────────────────────────────────────────────
 *
 * This file imports no vault, `ConversationReader` imports no vault, and the only provider-shaped
 * identifiers on the wire are `provider_connection_id` and `model_id` — references to rows this
 * organization owns, from which no key is derivable. `ProviderCallResource` states the same thing
 * where the fields are declared.
 */
final class ConversationController extends Controller
{
    /**
     * ONE PAGE of this organization's threads.
     *
     * ── THE ENVELOPE IS FIXED AND IS NOT THIS ACTION'S TO RESHAPE ────────────────────────────
     *
     *     {"data": {"conversations": [...], "meta": {page, per_page, total, total_pages, sort, dir, filter}}}
     *
     * `apps/web/src/lib/table/envelope.ts` is written against exactly that and THROWS rather than
     * degrading when it cannot read it, because an unreadable envelope is not an empty list.
     *
     * ── WHY THE QUERY STRING GOES THROUGH A FormRequest ──────────────────────────────────────
     *
     * Because `sort` reaches an `ORDER BY` and `per_page` decides how much work one caller may ask
     * the database for. `IndexConversationsRequest` closes the sortable set with `Rule::in(...)`,
     * caps `per_page` at `ListQuery::MAX_PER_PAGE`, and closes `status` and `channel` against the
     * same enums the column CHECKs are generated from. It also puts the parameters in
     * `packages/contracts/rules/IndexConversationsRequest.json`, which is the only place a
     * generated client learns they exist.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => ConversationCollectionResource::class],
        description: 'One page of this organization\'s conversation threads, with `meta` beside the '
            .'array inside `data`. EVERY lifecycle state is included, including threads still in '
            .'flight and threads the retention sweeper has marked `expired`. `page` is 1-based; '
            .'`per_page`, `sort` and `dir` are echoed AS APPLIED, which may differ from what was '
            .'asked for because the platform clamps the page size and falls back to the endpoint '
            .'default sort — which here is `last_activity_at` DESCENDING, the most recently active '
            .'thread first. `meta.filter` is always null: this endpoint takes no free-text term. A '
            .'`bot_id`, `user_id` or `session_id` belonging to another organization is not an error '
            .'and not a leak — every predicate carries the organization AND the filtered column '
            .'together, so it matches nothing and the page reads empty.',
        errors: [401, 403, 404, 422, 429, 500, 503],
    )]
    public function index(
        IndexConversationsRequest $request,
        Organization $organization,
        ConversationReader $conversations,
    ): ConversationCollectionResource {
        // CHECKS 3 AND 4, ON THE PARENT. A list has no conversation row to take an organization
        // from, and the organization is the right scope anyway — it is the record whose threads are
        // read.
        Gate::authorize('viewConversations', $organization);

        $query = $request->toQuery();

        // No 409 — see the class docblock, check 5.
        return new ConversationCollectionResource(
            $conversations->list($organization, $request->toFilter(), $query),
            $query,
        );
    }

    /**
     * One thread's full transcript.
     *
     * The conversation row is already in memory: `->scopeBindings()` resolved it through
     * `$organization->conversations()`, so a foreign or unknown id 404d before this action ran. The
     * policy is what refuses the remaining case — the row IS in this organization and the caller's
     * role is wrong.
     *
     * FIVE STATEMENTS FOR THE WHOLE TRANSCRIPT, not four per message. `ConversationReader` fetches
     * the messages and then each child table once, bounded to the page of message ids it is
     * returning, and groups in PHP. The obvious `->with(['citations', 'retrievalTrace', ...])` is
     * refused deliberately: those relations carry NO organization predicate and cannot, because the
     * four tables have no such column — `TranscriptTurn`'s docblock carries the argument.
     */
    #[ResponseShape(
        status: 200,
        properties: ['data' => ConversationTranscriptResource::class],
        description: 'One conversation and everything that was said in it, wrapped in `data`: every '
            .'field of the list row, plus the messages with their citations RESOLVED TO THE EXCERPT '
            .'the model read, their retrieval traces, their feedback, and every provider attempt '
            .'behind each turn — which is where per-turn latency, token counts and fallback events '
            .'live. UNSETTLED TURNS ARE INCLUDED, unlike the public runtime transcript: a turn that '
            .'died mid-stream is what an operator opens this screen to find. The read is bounded '
            .'and `messages_truncated` says when the bound bit. EVERYTHING BELOW THE HEADER IS '
            .'UNTRUSTED TEXT — visitor questions, model output, quoted document passages and '
            .'feedback comments — and must render through the same sanitizer the widget uses. A '
            .'foreign or unknown `{conversation}` 404s at binding time, before this action runs, '
            .'and the body is byte-identical to the 404 for a path with no route.',
        errors: [401, 403, 404, 429, 500, 503],
    )]
    public function show(
        Organization $organization,
        Conversation $conversation,
        ConversationReader $conversations,
    ): ConversationTranscriptResource {
        // CHECKS 3 AND 4 — on the ROW, so the policy resolves membership of THE RECORD'S
        // organization rather than of whichever one the session happens to name.
        Gate::authorize('view', $conversation);

        return new ConversationTranscriptResource(
            $conversation,
            $conversations->transcript($organization, $conversation),
        );
    }
}
