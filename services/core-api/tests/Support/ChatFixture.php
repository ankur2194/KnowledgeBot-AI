<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Bot;
use App\Models\KnowledgeSource;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;

/**
 * One organization wired end to end for a chat turn — every row `ConfigSnapshotResolver` has to
 * find, and nothing it does not.
 *
 * ── IT IS A VALUE OBJECT WITH NARROWED TYPES, LIKE `TenantPair`, AND FOR THE SAME REASON ──────
 *
 * A property typed `object` lets a test hand the WRONG organization's record to an assertion written
 * for the other one, which type-checks at level 8 and makes a negative isolation assertion run as
 * the tenant that planted the canary — passing while proving the opposite of what it claims.
 *
 * ── `embeddingIdentity` IS ON THE FIXTURE BECAUSE THE RESOLVER REFUSES A MISMATCH ─────────────
 *
 * The chat snapshot's embedding connection is resolved from the identity the SOURCE VERSIONS were
 * indexed under — not from whatever the organization designates today (ADR-035). So a fixture whose
 * version identity names a `(provider, model)` pair no connection offers produces a `validation`
 * refusal, and it looks like a bug in the resolver rather than in the fixture. Exposing the string
 * is what lets a test assert on that refusal deliberately.
 */
final readonly class ChatFixture
{
    /**
     * @param  string  $origin  the embedder origin on the bot's allow-list — the one a session must
     *                          be minted from, and the one every later request re-validates against
     * @param  string  $embeddingIdentity  `emb/v1:provider:model:dNNNN:digest`, as written onto the
     *                                     published `source_versions` row
     * @param  list<string>  $versionIds  the ACTIVE version ids, which is exactly what
     *                                    `allowed_version_ids` must resolve to
     */
    public function __construct(
        public Organization $organization,
        public Bot $bot,
        public ProviderConnection $connection,
        public ProviderModelEntry $model,
        public KnowledgeSource $source,
        public string $origin,
        public string $embeddingIdentity,
        public array $versionIds,
        public string $chunkId,
    ) {}
}
