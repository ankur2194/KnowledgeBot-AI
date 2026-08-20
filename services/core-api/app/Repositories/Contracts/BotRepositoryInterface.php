<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Bot;
use App\Services\Bots\BotChildSummary;
use App\Services\Bots\BotEdit;
use App\Services\Bots\NewBot;
use App\Support\Http\ListQuery;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The organization's bots.
 *
 * ── EVERY METHOD TAKES `$organizationId` FIRST, AND POSITIONALLY ──────────────────────────────
 *
 * The explicit predicate is the MECHANISM (kb-tenancy-isolation);
 * `#[ScopedBy(OrganizationScope::class)]` on the model is the BACKSTOP, and the two fail
 * differently. The backstop reads the ambient `TenantContext`, so it fails in exactly the situation
 * the explicit argument exists for — a queue worker or a pooled request whose context is stale or
 * empty. Neither is redundant and both are written out.
 *
 * That matters more here than on any table so far: `bot_ids` is one of the four mandatory Qdrant
 * filter terms (kb-tenancy-isolation NN3), so a bot resolved out of the wrong organization does not
 * produce a wrong answer — it produces a correct-looking answer, at normal latency, with a 200,
 * citing a document the organization never uploaded.
 *
 * ── EVERY MUTATING METHOD TAKES ITS AUDIT ROW AS A REQUIRED CLOSURE ───────────────────────────
 *
 * All three `bot.*` operations are `ON_FAILURE_ABORT`, so a failed audit write must roll the state
 * change back. `App\Services\Audit\AuditLogger` opens no transaction of its own — it cannot know
 * what else belongs inside one — and `Illuminate\Support\Facades\DB` is arch-pinned to
 * `App\Repositories\Eloquent`, so this layer is the only place the wrapping can happen. The closure
 * is REQUIRED rather than optional: there is no overload that omits it and `null` is not an
 * accepted value, so a future caller cannot write the state change with no row. The same
 * construction `ProviderModelRepositoryInterface` uses, for the same reason.
 */
interface BotRepositoryInterface
{
    /**
     * One PAGE of this organization's bots, ordered and filtered as the query says.
     *
     * ── WHY THIS RETURNS A PAGINATOR AND NOT A `list<Bot>` LIKE ITS SIBLINGS ──────────────────
     *
     * `forOrg()` on the connection and model repositories returns whole collections, and both say
     * in as many words that the wrapper in their resource "leaves room for pagination fields to
     * join later". This is that day, and it arrives for the collection most likely to be large: a
     * platform tenant's bot list is unbounded in a way a credential list is not. A list endpoint is
     * a query whose cost the CALLER chooses, and `LengthAwarePaginator` is what turns that into a
     * bounded `LIMIT`/`OFFSET` plus one `COUNT` — `ListQuery::MAX_PER_PAGE` is the ceiling that
     * makes "give me everything" inexpressible.
     *
     * ORDER IS ALWAYS DETERMINISTIC AND THE TIE-BREAK IS NOT OPTIONAL. Offset pagination over an
     * unordered result set is free to repeat a row on page 2 that it already showed on page 1 —
     * PostgreSQL gives no ordering guarantee without `ORDER BY`, and `name` is not unique within an
     * organization. So the sort column is followed by `id`, always, even when the sort column IS
     * `id`.
     *
     * @return LengthAwarePaginator<int, Bot>
     */
    public function paginate(string $organizationId, ListQuery $query): LengthAwarePaginator;

    /**
     * Whether this organization already has a bot with this slug.
     *
     * IT EXISTS SO A DUPLICATE IS A 422 AND NOT A 500. The authority is the unique index
     * `bots_org_slug_unique`; this is the good error message, and the service catches SQLSTATE
     * 23505 for the race it cannot win.
     *
     * A REPOSITORY METHOD AND NOT A `unique:` VALIDATION RULE, for the reason
     * `DesignateEmbeddingConnectionRequest` writes out at length: `unique:` queries the table with
     * NO organization predicate unless somebody remembers to add one, which is the exact shape of
     * Filament CVE-2026-48067 — the select query was tenant-scoped and the validation rule for the
     * same field was not. Spelling the scope into the rule string by hand would work and would also
     * be a tenant predicate assembled from route input inside a string, which is the thing that
     * goes wrong. And the slug is unique PER ORGANIZATION, so an unscoped rule would additionally
     * refuse a name another tenant happens to have used — an existence oracle over the platform,
     * rendered as a validation error on a form.
     *
     * @param  string|null  $exceptBotId  the row being edited, so a PATCH that re-sends its own
     *                                    slug is not a duplicate of itself
     */
    public function slugExists(string $organizationId, string $slug, ?string $exceptBotId = null): bool;

    /**
     * What this bot's three child collections hold right now.
     *
     * ── IT EXISTS FOR THE AUDIT ROW, AND THAT IS WHY IT IS ON THIS INTERFACE ──────────────────
     *
     * Finding L2: deleting a bot destroyed its widget origin allow-list with no record of what it
     * permitted, contradicting the reason `bot_domains` gives for its own `ON DELETE RESTRICT`.
     * `BotService::record()` closes half of that by carrying a scalar summary of all three
     * collections onto every `bot.created`, `bot.updated` and `bot.deleted` row — and `BotService`
     * cannot read them itself, because Eloquent is arch-pinned to `App\Repositories\Eloquent`.
     *
     * ── IT IS CALLED FROM INSIDE THE AUDIT CLOSURE, AND THEREFORE INSIDE THE TRANSACTION ──────
     *
     * Which is the whole reason the numbers are true. On the delete path the closure runs BEFORE
     * the children are removed, so this read sees the list that is about to be destroyed; a read
     * taken after the fact would report zeroes for every collection and record the absence of the
     * thing the finding is about.
     */
    public function childSummary(string $organizationId, string $botId): BotChildSummary;

    /**
     * Store one bot, in one transaction.
     *
     * `$publicBotId` IS AN ARGUMENT AND NOT SOMETHING THIS METHOD MINTS, so the value is decided by
     * `App\Support\Kb\PublicBotIdentifier` — one implementation, testable without a database — and
     * the persistence layer stays free of secret-material generation.
     *
     * @param  Closure(Bot): void  $audit  invoked inside the transaction
     *
     * @throws \Illuminate\Database\QueryException SQLSTATE 23505 when the (organization, slug) pair
     *                                             already exists. The service maps it onto the same
     *                                             422 the pre-flight check produces; the unique
     *                                             index is the authority and the check is only the
     *                                             good message
     */
    public function create(
        string $organizationId,
        string $publicBotId,
        NewBot $input,
        Closure $audit,
    ): Bot;

    /**
     * Apply a partial edit under a row lock, bumping `retrieval_configuration_version` when — and
     * only when — a retrieval knob's VALUE actually changes.
     *
     * ── THE BUMP IS HERE AND NOT IN THE SERVICE, AND THAT IS NOT WHERE IT READS LIKE IT BELONGS ─
     *
     * `App\Models\Bot` says the service bumps it, and the POLICY is indeed the service layer's —
     * it is `BotEdit::RETRIEVAL_KNOBS`, a constant a reviewer can read without opening a query.
     * The APPLICATION has to happen under the same `lockForUpdate()` that reads the current values,
     * because "bump if changed" is a read-modify-write and two concurrent PATCHes that computed the
     * increment outside the lock would both write the same version number to two different
     * configurations. That is precisely the failure the column exists to prevent: the §21.5
     * regression gate replays a trace against the configuration its version names, and two
     * configurations sharing a version make the replay compare the wrong pair.
     *
     * A knob that is NAMED but UNCHANGED does not bump, deliberately. A console that re-submits the
     * whole form on every save would otherwise mint a new configuration identity on every keystroke
     * pass, invalidating every cached answer for a configuration that did not move.
     *
     * @param  Closure(Bot): void  $audit  invoked inside the transaction, and ONLY when the save
     *                                     genuinely changed the row. A PATCH whose every value
     *                                     equals what is stored — the shape a whole-form resubmit
     *                                     produces, and one this endpoint deliberately accepts —
     *                                     issues no UPDATE and must not leave a `bot.updated` row
     *                                     asserting an edit that did not happen. The implementation
     *                                     records why the predicate is `wasChanged()`.
     * @return Bot|null null when no such bot exists in THIS organization
     */
    public function update(
        string $organizationId,
        string $botId,
        BotEdit $edit,
        Closure $audit,
    ): ?Bot;

    /**
     * Hard-delete one bot and the three child collections that exist only to describe it.
     *
     * ── THE CHILDREN ARE REMOVED IN CODE BECAUSE THE FOREIGN KEYS ARE `ON DELETE RESTRICT` ─────
     *
     * `bot_domains`, `bot_starter_questions` and `bot_fallback_models` all reference
     * `bots (organization_id, id)` with RESTRICT, so a bot with a single origin on its allow-list
     * cannot be deleted by a bare `DELETE` — it raises SQLSTATE 23503, which the error envelope
     * renders as a 500. Switching those keys to CASCADE would fix the symptom and lose the
     * property they were chosen for: with RESTRICT, the blast radius of a delete is written out at
     * the ONE call site that performs it, and a fourth child table added later fails loudly here
     * instead of being silently swept away by the database.
     *
     * Each child DELETE carries the organization predicate as well as the bot predicate. It is
     * redundant against the composite foreign key — a child's bot cannot belong to another
     * organization — and it is written out for the same reason every other predicate in this layer
     * is: "correct only because of a constraint in another file" is not the property this layer
     * exists to have.
     *
     * @param  Closure(Bot): void  $audit  invoked inside the transaction, BEFORE the row is removed
     *                                     — after it there is nothing left to describe
     * @return bool false when no such bot exists in THIS organization
     */
    public function delete(string $organizationId, string $botId, Closure $audit): bool;
}
