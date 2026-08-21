<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\BotSourceAssignment;
use App\Models\KnowledgeSource;
use App\Repositories\Contracts\BotSourceAssignmentRepositoryInterface;
use App\Support\Http\ListQuery;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

final class EloquentBotSourceAssignmentRepository implements BotSourceAssignmentRepositoryInterface
{
    /**
     * The columns a free-text filter searches — on the SOURCE, because the assignment row has no
     * text of its own.
     *
     * `name` is what an operator types when looking for a document they know they uploaded;
     * `origin_url` is what a security reviewer types, for the reason
     * `EloquentKnowledgeSourceRepository::FILTERABLE` states, and it is the same pair for the same
     * reasons — `description` is prose whose match surfaces a row whose name has nothing to do with
     * the search, and `tags` is a `text[]` an `ILIKE` cannot address at all.
     *
     * @var list<string>
     */
    private const FILTERABLE = ['name', 'origin_url'];

    /**
     * @return LengthAwarePaginator<int, BotSourceAssignment>
     */
    public function paginate(string $organizationId, string $botId, ListQuery $query): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, BotSourceAssignment> $page */
        $page = $this->scoped($organizationId, $botId)
            // THE SOURCE, WITH ITS OWN ORGANIZATION PREDICATE ON THE EAGER LOAD. Without the
            // constraint the relation query would be scoped only by
            // `#[ScopedBy(OrganizationScope::class)]`, i.e. by the ambient `TenantContext` — the
            // backstop rather than the mechanism, and the one layer that fails in a pooled worker.
            // Without the eager load at all, `Model::shouldBeStrict()` turns the resource's first
            // `$assignment->source` into a LazyLoadingViolationException.
            ->with(['source' => static function (Relation $source) use ($organizationId): void {
                $source->where('organization_id', '=', $organizationId);
            }])
            ->when(
                $query->filter !== null,
                // ONE PREDICATE AT THIS LEVEL, INSIDE A CLOSURE GROUP ANYWAY. There is a single
                // disjunct here today, so the `AND`/`OR` precedence trap that
                // `EloquentKnowledgeSourceRepository::paginate()` records cannot fire — but the
                // group is what makes a SECOND disjunct added later unable to escape the tenant and
                // bot predicates, and the trap is precisely the one that looks like a formatting
                // choice. The OR that does exist lives inside the sub-query, which carries its own
                // organization predicate.
                fn (Builder $builder): Builder => $builder->where(
                    function (Builder $group) use ($organizationId, $query): void {
                        $term = '%'.$this->escapeLike((string) $query->filter).'%';

                        $group->whereIn('source_id', KnowledgeSource::query()
                            ->where('organization_id', '=', $organizationId)
                            ->where(function (Builder $columns) use ($term): void {
                                foreach (self::FILTERABLE as $column) {
                                    // PostgreSQL's own operator rather than a `whereRaw('lower(…)')`,
                                    // so the value stays a bound parameter and never becomes SQL.
                                    $columns->orWhere($column, 'ilike', $term);
                                }
                            })
                            ->select('id'));
                    },
                ),
            )
            ->orderBy($query->sort, $query->direction->value)
            // THE TIE-BREAK IS NOT OPTIONAL, and it is appended even when the sort column IS `id`.
            // `priority` and `source_id` are both non-unique within one bot, so without a total
            // order PostgreSQL may legally return on page 2 a row it already showed on page 1 — and
            // the duplicate is invisible until somebody counts.
            ->orderBy('id')
            ->paginate(perPage: $query->perPage, page: $query->page);

        return $page;
    }

    public function existsFor(string $organizationId, string $botId, string $sourceId): bool
    {
        return $this->scoped($organizationId, $botId)
            ->where('source_id', '=', $sourceId)
            ->exists();
    }

    public function countForBot(string $organizationId, string $botId): int
    {
        return $this->scoped($organizationId, $botId)->count();
    }

    public function countEnabledForBot(string $organizationId, string $botId): int
    {
        // POSITIVELY, `where('enabled', true)` and never `whereNot('enabled', false)`. It is the
        // same direction `kb-tenancy-isolation` NN5 requires of the tenant filter and the same
        // direction the partial index `bot_source_assignments_org_bot_priority ... WHERE enabled`
        // is built in, so the predicate and the index agree by construction.
        return $this->scoped($organizationId, $botId)
            ->where('enabled', '=', true)
            ->count();
    }

    /**
     * @param  Closure(BotSourceAssignment): void  $audit
     */
    public function create(
        string $organizationId,
        string $botId,
        string $sourceId,
        int $priority,
        bool $enabled,
        Closure $audit,
    ): BotSourceAssignment {
        return DB::transaction(function () use (
            $organizationId, $botId, $sourceId, $priority, $enabled, $audit,
        ): BotSourceAssignment {
            $assignment = new BotSourceAssignment;

            // ── THE THREE COLUMNS OUTSIDE $fillable, ASSIGNED HERE AND ONLY HERE ─────────────
            //
            // `organization_id` comes from the authenticated context passed in as an argument —
            // NOT from `$bot->organization_id` and NOT from the source's own column. A value
            // derived from one parent agrees with that parent by construction, which would leave
            // one of the two composite foreign keys permanently satisfied and only the other ever
            // under test; `KnowledgeSourceFactory::assignedTo()` refuses to infer it for exactly
            // that reason and this writer must not either.
            //
            // `bot_id` comes from the route and `source_id` from the validated body. Both are the
            // GRANT ITSELF, which is why neither is fillable: a fillable `bot_id` would let a PATCH
            // move a live assignment between two bots of the same tenant, and no composite key can
            // object because both bots belong to that tenant.
            //
            // `setAttribute` and not `fill()`, so `$fillable` is not a second allow-list deciding
            // the same write.
            $assignment->organization_id = $organizationId;
            $assignment->bot_id = $botId;
            $assignment->source_id = $sourceId;

            $assignment->priority = $priority;
            $assignment->enabled = $enabled;

            // TWO SQLSTATES ARE POSSIBLE HERE AND NEITHER IS HANDLED IN THIS FILE. 23505 is the
            // duplicate `(organization_id, bot_id, source_id)`, which the service pre-checks for a
            // readable message and catches for the race that check cannot win. 23503 is a composite
            // foreign key refusing a cross-organization row — the guard this whole table exists for
            // — which the service also catches, because it is the ONE failure on this surface that
            // must never be rendered as a 500 that reads like a server bug.
            $assignment->save();

            // INSIDE the transaction, after the INSERT so the row has its ULID, before the COMMIT
            // so an ON_FAILURE_ABORT audit failure rethrows and takes the grant with it. A granted
            // corpus with no audit row is finding L2 with documents instead of a widget.
            $audit($assignment);

            return $assignment;
        });
    }

    /**
     * @param  Closure(BotSourceAssignment): void  $audit
     */
    public function delete(string $organizationId, string $botId, string $assignmentId, Closure $audit): bool
    {
        return DB::transaction(function () use ($organizationId, $botId, $assignmentId, $audit): bool {
            $assignment = $this->scoped($organizationId, $botId)
                ->whereKey($assignmentId)
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                // The route binding already 404'd a foreign id long before this line; reaching here
                // means the row was removed between the binding and this transaction. False rather
                // than an exception, so the caller renders the same 404 the binding would have
                // rather than a 500 describing a race it cannot act on.
                return false;
            }

            // BEFORE the row goes, because after it there is nothing left to describe — and inside
            // the transaction, so an ON_FAILURE_ABORT write failure leaves the grant in place
            // rather than withdrawing it untraceably.
            $audit($assignment);

            $assignment->delete();

            return true;
        });
    }

    /**
     * `LIKE`'s own metacharacters, escaped so a filter term is matched literally.
     *
     * The same escape `EloquentKnowledgeSourceRepository` applies, and for the same reason: `%` and
     * `_` in a value a human typed are characters they meant, not a pattern they wrote. The
     * backslash goes first or it would double-escape the escapes added after it.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * The two ownership predicates, written out once so no query in this class can be built without
     * them.
     *
     * `#[ScopedBy(OrganizationScope::class)]` adds the organization term from the ambient
     * `TenantContext` and is the BACKSTOP; this is the MECHANISM. The bot term has no backstop at
     * all — nothing in the model layer knows which bot a request is about — so it exists only here.
     *
     * @return Builder<BotSourceAssignment>
     */
    private function scoped(string $organizationId, string $botId): Builder
    {
        return BotSourceAssignment::query()
            ->where('organization_id', '=', $organizationId)
            ->where('bot_id', '=', $botId);
    }
}
