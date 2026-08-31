<?php

declare(strict_types=1);

namespace App\Services\Chat;

/**
 * The resolved retrieval scope for one bot: which `source_versions` it may search RIGHT NOW, and the
 * embedding identity those versions were indexed under.
 *
 * ═══ THIS IS NON-NEGOTIABLE 2'S FOURTH FILTER TERM, AND IT IS RESOLVED HERE BECAUSE ONLY
 *     LARAVEL CAN RESOLVE IT ═══════════════════════════════════════════════════════════════
 *
 * `bot_source_assignments ⋈ knowledge_sources ⋈ source_versions`, restricted to this organization
 * and to versions that are active at this instant. `SourceService`'s docblock names it as owed to
 * this phase and names its inputs; `kb-tenancy-isolation` says Laravel resolves it and ships it in
 * the config snapshot. FastAPI opens no connection to PostgreSQL for configuration and could not
 * compute it.
 *
 * Shipping it is what makes a DISABLE take effect at once — the next turn simply computes a
 * different set — rather than after millions of Qdrant payload rewrites. The payload rewrite
 * (`SyncSourceStatusJob`) is the complementary half that makes the disable durable; neither replaces
 * the other.
 *
 * ═══ AN EMPTY SET IS EXPRESSED BY NOT QUERYING, NEVER BY AN EMPTY FILTER ════════════════════
 *
 * A bot with no assigned source, or whose every source is disabled, retrieves nothing and refuses.
 * That is a real OUTCOME and it is why `isEmpty()` exists — but it must never become an empty
 * `allowed_version_ids` on the wire: `Filter(must=[])` is a confirmed MATCH-ALL in Qdrant and
 * `MatchAny(any=[])` matches nothing without saying so. The far side bounds the field `min_length=1`
 * for exactly that reason, so an empty scope has to be refused BEFORE the call rather than sent.
 *
 * ═══ ONE EMBEDDING IDENTITY, AND MORE THAN ONE IS A REFUSAL RATHER THAN A CHOICE ════════════
 *
 * `embedding_model_version` derives the Qdrant collection name (ADR-035), so a bot whose assigned
 * versions span two identities has points in TWO collections and the wire can address only one.
 * Picking one silently answers from half the corpus with a well-formed citation and no error
 * anywhere — the exact failure class ADR-031's "two eligible connections that disagree is a refusal,
 * not a tiebreak" exists to prevent, one layer down. So the resolver refuses, and this object
 * carries the whole distinct set so the refusal can say how many there were without a second query.
 */
final readonly class RetrievalScope
{
    /**
     * @param  list<string>  $versionIds  active `source_versions.id`, ascending. Ordered because the
     *                                    set is hashed into `configuration_version` and into the
     *                                    answer-cache fingerprint: an unordered set is a different
     *                                    key on every request for a configuration that did not
     *                                    change.
     * @param  list<string>  $embeddingIdentities  every DISTINCT `embedding_model_version` across
     *                                             those versions, ascending. One is the normal case.
     */
    public function __construct(
        public array $versionIds,
        public array $embeddingIdentities,
    ) {}

    public function isEmpty(): bool
    {
        return $this->versionIds === [];
    }

    /**
     * True when the scope spans more than one embedding identity — see the class docblock.
     */
    public function isSplitAcrossEmbeddingSpaces(): bool
    {
        return count($this->embeddingIdentities) > 1;
    }

    /**
     * The single identity, when there is one.
     *
     * Callers ask `isEmpty()` and `isSplitAcrossEmbeddingSpaces()` first; this returns `''` in
     * either degenerate case rather than throwing, because the two questions above are the ones a
     * caller is meant to branch on and a third throwing accessor would make the refusal path depend
     * on catch order.
     */
    public function embeddingIdentity(): string
    {
        return $this->embeddingIdentities[0] ?? '';
    }
}
