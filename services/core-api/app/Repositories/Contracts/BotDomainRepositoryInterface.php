<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Enums\BotDomainStatus;
use App\Models\BotDomain;
use Closure;

/**
 * One bot's widget origin allow-list.
 *
 * ── EVERY METHOD TAKES `$organizationId` AND `$botId` FIRST, AND POSITIONALLY ──────────────────
 *
 * BOTH, on every method, and not because the second implies the first. It does — the composite
 * foreign key `(organization_id, bot_id) -> bots (organization_id, id)` makes a bot id resolve to
 * exactly one organization. But "correct only because of a constraint written in another file" is
 * not the property this layer exists to have: the explicit organization predicate is the MECHANISM
 * (kb-tenancy-isolation), `#[ScopedBy(OrganizationScope::class)]` on the model is the BACKSTOP, and
 * the two fail differently. The backstop reads the ambient `TenantContext`, so it fails in exactly
 * the situation the explicit argument exists for — a queue worker or a pooled request whose context
 * is stale or empty.
 *
 * It matters more here than on most tables. A row in `bot_domains` is a PERMANENT GRANT that every
 * downstream check AGREES with, because it has been told this origin belongs to that bot. There is
 * no later layer that catches a row written into the wrong tenant; the row simply works.
 *
 * ── EVERY MUTATING METHOD TAKES ITS AUDIT ROW AS A REQUIRED CLOSURE ────────────────────────────
 *
 * All three `bot.domain.*` operations are `ON_FAILURE_ABORT`, so a failed audit write must roll the
 * change back. `App\Services\Audit\AuditLogger` opens no transaction of its own — it cannot know
 * what else belongs inside one — and `Illuminate\Support\Facades\DB` is arch-pinned to
 * `App\Repositories\Eloquent`, so this layer is the only place the wrapping can happen. The closure
 * is REQUIRED rather than optional: there is no overload that omits it and `null` is not an
 * accepted value, so a future caller cannot write the state change with no row.
 *
 * THAT IS THE MECHANISM THAT CLOSES FINDING L2. The finding is that a deleted bot took its allow-
 * list with it and left no record of what it permitted; what actually fixes it is that every origin
 * ever granted has its own append-only row naming the actor, the origin and the time — and a
 * repository that cannot write the grant without writing the row is what makes that true by
 * construction rather than by discipline at three call sites.
 */
interface BotDomainRepositoryInterface
{
    /**
     * Every entry on ONE bot's allow-list, in ONE organization.
     *
     * PENDING AND DISABLED ROWS INCLUDED. Filtering them out would make "why is my widget refused
     * on this site" unanswerable from the console while the row sat in the table — the same call
     * `ProviderModelRepositoryInterface::forConnection()` makes about disabled catalog rows.
     *
     * ORDERED BY ORIGIN, DETERMINISTICALLY. Two reads of an unchanged set must be byte-identical or
     * the console's table re-orders itself between polls and a contract test comparing two response
     * bodies is a coin flip. The order carries NO PRECEDENCE — an allow-list is a set and the
     * lookup is an exact match, never a first-match walk.
     *
     * @return list<BotDomain>
     */
    public function forBot(string $organizationId, string $botId): array;

    /**
     * How many entries this bot's allow-list already holds.
     *
     * It exists so the per-bot ceiling is refused with a sentence rather than by growing without
     * bound. A `bot_domains` row is a grant and the list is read on every widget bootstrap, so
     * "unbounded" is both a security surface and a hot-path cost.
     */
    public function countForBot(string $organizationId, string $botId): int;

    /**
     * Whether this bot's allow-list already carries this exact origin.
     *
     * IT EXISTS SO A DUPLICATE IS A 422 AND NOT A 500. The authority is the unique index
     * `bot_domains_org_bot_origin`; this is the good message, and the service catches SQLSTATE
     * 23505 for the race this check cannot win.
     *
     * A REPOSITORY METHOD AND NOT A `unique:` VALIDATION RULE, for the reason
     * `DesignateEmbeddingConnectionRequest` writes out at length — and with a second reason
     * peculiar to this column: an unscoped `unique:` rule would refuse an origin ANOTHER TENANT
     * happens to have listed, which is an existence oracle over every customer's embed sites
     * rendered as a validation error on a form.
     */
    public function originExists(string $organizationId, string $botId, string $origin): bool;

    /**
     * Store one origin, in one transaction, always `pending`.
     *
     * `$status` IS NOT AN ARGUMENT. A row starts unusable and is promoted deliberately; letting the
     * create path choose would collapse entry and promotion into one request, which is exactly what
     * `App\Models\BotDomain` keeps `status` out of `$fillable` to prevent.
     *
     * @param  Closure(BotDomain): void  $audit  invoked inside the transaction
     *
     * @throws \Illuminate\Database\QueryException SQLSTATE 23505 when this bot already lists the
     *                                             origin. The service maps it onto the same 422 the
     *                                             pre-flight check produces; the unique index is
     *                                             the authority and the check is only the good
     *                                             message
     */
    public function create(
        string $organizationId,
        string $botId,
        string $origin,
        Closure $audit,
    ): BotDomain;

    /**
     * Move one entry to a new status, under a row lock.
     *
     * THE LOCK IS WHAT MAKES THE AUDIT ROW HONEST. `previous_status` is read inside the same
     * transaction that writes the new one, so two concurrent promotions serialise and neither can
     * record a transition from a status the row never held.
     *
     * `origin` IS NOT AN ARGUMENT AND NEVER WILL BE. Editing an origin in place would carry an
     * existing promotion across to a different origin — a grant moved silently. Remove and re-add;
     * the trail then says both things happened.
     *
     * @param  Closure(BotDomain, BotDomainStatus): void  $audit  invoked inside the transaction with
     *                                                            the updated row and the status it
     *                                                            held before
     * @return BotDomain|null null when no such entry exists on this bot in this organization
     */
    public function changeStatus(
        string $organizationId,
        string $botId,
        string $domainId,
        BotDomainStatus $status,
        Closure $audit,
    ): ?BotDomain;

    /**
     * Remove one entry, in one transaction.
     *
     * @param  Closure(BotDomain): void  $audit  invoked inside the transaction, BEFORE the row is
     *                                           removed — after it there is nothing left to describe
     * @return bool false when no such entry exists on this bot in this organization
     */
    public function delete(string $organizationId, string $botId, string $domainId, Closure $audit): bool;
}
