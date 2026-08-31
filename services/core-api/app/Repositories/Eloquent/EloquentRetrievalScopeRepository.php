<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Enums\SourceState;
use App\Models\SourceVersion;
use App\Repositories\Contracts\RetrievalScopeRepositoryInterface;
use App\Services\Chat\RetrievalScope;

final class EloquentRetrievalScopeRepository implements RetrievalScopeRepositoryInterface
{
    /**
     * How many version ids one turn may carry.
     *
     * The far side bounds `allowed_version_ids` at `max_length=10_000`, so more than that is a 422
     * for a body that is otherwise correct. Capping here rather than letting the request fail means
     * a very large tenant gets a DEGRADED scope instead of a broken bot — and the cap is applied to
     * an ORDERED read, so the same 10 000 are chosen on every turn rather than a planner-dependent
     * sample that would make the answer cache useless and the answers non-reproducible.
     *
     * REPORTED RATHER THAN SOLVED: a bot with more than 10 000 live versions cannot be searched
     * completely through this wire, and nothing here can tell the operator. The honest fix is a
     * scope expressed as a predicate rather than an id list, which is a contract change on both
     * planes.
     */
    private const MAX_VERSION_IDS = 10_000;

    public function forBot(string $organizationId, string $botId, string $now): RetrievalScope
    {
        // ONE QUERY, THROUGH THE ELOQUENT BUILDER, so `#[ScopedBy(OrganizationScope::class)]` on
        // SourceVersion still applies on top of the explicit predicate. A `DB::table()` join here
        // would be faster to write and would touch neither global scope — which is the shape
        // tests/Arch/StringLevelDoctrineTest.php and the tenancy grep both exist to catch.
        //
        // THE ORGANIZATION PREDICATE IS REPEATED ON EVERY JOINED TABLE and that is deliberate rather
        // than redundant. Composite foreign keys already guarantee the three rows agree, so one
        // predicate would be sufficient TODAY; repeating it means a future join added to this query
        // without a composite key is still scoped, and it costs the planner nothing on an indexed
        // leading column.
        $rows = SourceVersion::query()
            ->where('source_versions.organization_id', '=', $organizationId)
            // NON-NEGOTIABLE 5's READ SIDE. Published, and not yet replaced.
            ->whereNotNull('source_versions.activated_at')
            ->whereNull('source_versions.retired_at')
            ->join('source_items', function ($join) use ($organizationId): void {
                $join->on('source_items.id', '=', 'source_versions.source_item_id')
                    ->where('source_items.organization_id', '=', $organizationId);
            })
            ->join('knowledge_sources', function ($join) use ($organizationId): void {
                $join->on('knowledge_sources.id', '=', 'source_items.source_id')
                    ->where('knowledge_sources.organization_id', '=', $organizationId);
            })
            ->join('bot_source_assignments', function ($join) use ($organizationId, $botId): void {
                $join->on('bot_source_assignments.source_id', '=', 'knowledge_sources.id')
                    ->where('bot_source_assignments.organization_id', '=', $organizationId)
                    // THE BOT-ACCESS TERM. Bot access is a QUERY-TIME scope (ADR-067): the same
                    // indexed chunk is visible to whichever bots the source is assigned to at the
                    // moment of the query, which is what makes reassigning a bot free of re-indexing.
                    ->where('bot_source_assignments.bot_id', '=', $botId)
                    // The operator turned this source off FOR THIS BOT specifically.
                    ->where('bot_source_assignments.enabled', '=', true);
            })
            // POSITIVE MATCH against the retrievable states, never a negative match against the
            // disabled ones. `kb-tenancy-isolation` NN5: a `whereNotIn` is satisfied by a row whose
            // status is a value nobody has thought about yet — a new state added to SourceState
            // would start answering. A positive list fails closed.
            ->whereIn('knowledge_sources.status', [
                SourceState::Ready->value,
                SourceState::ReadyWithWarnings->value,
            ])
            ->whereNull('knowledge_sources.deleted_at')
            // The validity window. NULL on either side means "no bound", which is the common case
            // and is why these are nested rather than a plain BETWEEN — a BETWEEN over a NULL is
            // NULL, which is not TRUE, so every source without an end date would drop out.
            ->where(function ($query) use ($now): void {
                $query->whereNull('knowledge_sources.effective_at')
                    ->orWhere('knowledge_sources.effective_at', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('knowledge_sources.expires_at')
                    ->orWhere('knowledge_sources.expires_at', '>', $now);
            })
            // DETERMINISTIC ORDER, and it is not cosmetic: this list is hashed into
            // `configuration_version` and into the answer-cache fingerprint, so a planner-dependent
            // order would produce a different cache key on every request for an unchanged
            // configuration — a cache that never hits and a version that never means anything.
            ->orderBy('source_versions.id')
            ->limit(self::MAX_VERSION_IDS)
            ->get(['source_versions.id', 'source_versions.embedding_model_version']);

        $versionIds = [];
        $identities = [];

        foreach ($rows as $row) {
            $versionIds[] = (string) $row->id;
            $identities[(string) $row->embedding_model_version] = true;
        }

        $distinct = array_keys($identities);
        sort($distinct, SORT_STRING);

        return new RetrievalScope($versionIds, $distinct);
    }
}
