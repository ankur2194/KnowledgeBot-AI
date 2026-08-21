<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\BotSourceAssignment;
use App\Support\Http\ListQuery;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Which knowledge sources one bot may answer from.
 *
 * ── THIS IS THE ONE TABLE IN THE SCHEMA THAT CAN SPAN TWO ORGANIZATIONS ───────────────────────
 *
 * `kb-tenancy-isolation` non-negotiable 2: `bot_id` and `source_id` each inherit their own
 * organization and nothing in the foreign-key graph forces them to agree. What forces them is the
 * denormalized `organization_id` on the row plus the two composite foreign keys
 * `bot_source_assignments_bot_same_org` and `bot_source_assignments_source_same_org`, both of which
 * point `(organization_id, {bot,source}_id)` at their parent's vacuous `UNIQUE (organization_id,
 * id)`.
 *
 * A MIS-SCOPED ROW IS NOT A BUG THE TENANT FILTER CATCHES; IT IS A BUG THE TENANT FILTER ENFORCES.
 * `bot_ids` is one of the four mandatory Qdrant filter terms and is resolved from this table, so one
 * wrong row makes a correctly-filtered query return another organization's documents at normal
 * latency with a well-formed citation and an HTTP 200.
 *
 * ── EVERY METHOD TAKES `$organizationId` AND `$botId` FIRST, AND POSITIONALLY ──────────────────
 *
 * BOTH, on every method, and not because the second implies the first. It does — the composite
 * foreign key makes a bot id resolve to exactly one organization — but "correct only because of a
 * constraint written in another file" is not the property this layer exists to have. The explicit
 * organization predicate is the MECHANISM (`kb-tenancy-isolation`),
 * `#[ScopedBy(OrganizationScope::class)]` on `App\Models\BotSourceAssignment` is the BACKSTOP, and
 * the two fail differently: the backstop reads the ambient `TenantContext`, so it fails in exactly
 * the situation the explicit argument exists for — a pooled worker or a request whose context is
 * stale or empty.
 *
 * ── THE ORGANIZATION IS STATED ON THE WRITE, NEVER INFERRED FROM EITHER PARENT ─────────────────
 *
 * `create()` takes the organization as an argument and writes it onto the row. It deliberately does
 * NOT read it off the bot or off the source, and `KnowledgeSourceFactory::assignedTo()`'s docblock
 * carries the reasoning from the fixture side: a value derived from one parent always agrees with
 * that parent by construction, so one of the two composite keys would always pass and only the
 * other would ever be under test. A service that derived it the same way would produce rows that
 * satisfy both keys while still being wrong in the case the keys exist for.
 *
 * ── EVERY MUTATING METHOD TAKES ITS AUDIT ROW AS A REQUIRED CLOSURE ────────────────────────────
 *
 * Both `bot.source_assignment.*` operations are `ON_FAILURE_ABORT`, so a failed audit write must
 * roll the grant back. `App\Services\Audit\AuditLogger` opens no transaction of its own and
 * `Illuminate\Support\Facades\DB` is arch-pinned to `App\Repositories\Eloquent`, so this layer is
 * the only place the wrapping can happen. The closure is REQUIRED and `null` is not an accepted
 * value, so a future caller cannot write the grant with no row — which is finding L2's shape one
 * entity over, and with higher stakes: this grant reaches documents rather than a page that may
 * embed a widget.
 */
interface BotSourceAssignmentRepositoryInterface
{
    /**
     * One PAGE of the sources assigned to ONE bot of ONE organization.
     *
     * ORDER IS ALWAYS DETERMINISTIC AND THE TIE-BREAK IS NOT OPTIONAL. Neither `priority` nor
     * `enabled` is unique within a bot, so without a total order PostgreSQL may legally return on
     * page 2 a row it already showed on page 1 — and the duplicate is invisible until somebody
     * counts. The sort column is followed by `id`, always, even when the sort column IS `id`.
     *
     * DISABLED ROWS ARE INCLUDED. A disabled assignment grants nothing, and filtering it out of the
     * list would make "why is this bot not answering from that document" unanswerable from the
     * console while the row sat in the table — the same call `BotDomainRepositoryInterface::
     * forBot()` makes about pending origins.
     *
     * THE SOURCE IS EAGER-LOADED WITH AN EXPLICIT ORGANIZATION PREDICATE, because the resource
     * renders the source's name and lifecycle state beside the grant and `Model::shouldBeStrict()`
     * forbids the lazy load that would otherwise happen one row at a time.
     *
     * @return LengthAwarePaginator<int, BotSourceAssignment>
     */
    public function paginate(string $organizationId, string $botId, ListQuery $query): LengthAwarePaginator;

    /**
     * Whether this bot already has an assignment for this source.
     *
     * The readable half of the duplicate refusal. `bot_source_assignments_org_bot_source` is the
     * authority and this is the pre-flight check that turns SQLSTATE 23505 into a 422 — the same
     * two-layer construction `BotDomainService::add()` uses for a duplicate origin.
     */
    public function existsFor(string $organizationId, string $botId, string $sourceId): bool;

    /**
     * How many sources are assigned to this bot, enabled or not.
     *
     * Bounds the list against `BotSourceAssignmentService::MAX_PER_BOT`.
     */
    public function countForBot(string $organizationId, string $botId): int;

    /**
     * How many of them are ENABLED — that is, how many actually grant anything.
     *
     * THIS IS THE PUBLISH GUARD'S THIRD REFUSAL. A disabled assignment contributes nothing to the
     * retrieval scope, so `countForBot()` is the wrong question to ask before publishing: a bot
     * whose every assignment is switched off has exactly as much corpus as one with none.
     */
    public function countEnabledForBot(string $organizationId, string $botId): int;

    /**
     * Grant this bot access to this source.
     *
     * @param  Closure(BotSourceAssignment): void  $audit  invoked inside the transaction, after the
     *                                                     INSERT so the row carries its ULID, and
     *                                                     before the COMMIT so an ON_FAILURE_ABORT
     *                                                     audit failure takes the grant with it
     */
    public function create(
        string $organizationId,
        string $botId,
        string $sourceId,
        int $priority,
        bool $enabled,
        Closure $audit,
    ): BotSourceAssignment;

    /**
     * Withdraw one grant.
     *
     * A HARD DELETE, and the audit row is the only thing that survives it — which is what makes
     * `source_id`, `source_name` and `enabled` load-bearing on `bot.source_assignment.deleted`
     * rather than decorative: `subject_id` resolves to nothing afterwards.
     *
     * @param  Closure(BotSourceAssignment): void  $audit  invoked inside the transaction, BEFORE
     *                                                     the row goes, because after it there is
     *                                                     nothing left to describe
     * @return bool false when no such assignment exists on this bot in this organization
     */
    public function delete(string $organizationId, string $botId, string $assignmentId, Closure $audit): bool;
}
