<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * Where the canary lives. This is the most consequential factory in the suite.
 *
 * tenantPair() plants a fresh canary string in ORG B's source content and every isolation test then
 * asserts it is absent from ORG A's raw response body — so a canary hiding in a citation title, an
 * export cell, or a cached completion still trips the assertion.
 *
 * THREE STATES, AND EACH ONE EXISTS FOR A DIFFERENT FAILURE:
 *
 *   indexed(string $content)
 *       Drives the REAL ingestion path into the test Qdrant container: parse -> chunk -> embed ->
 *       upsert -> publish the version. NOT a hand-written Qdrant point.
 *
 *       Two reasons it must be the real path. First, a hand-written point can carry a payload the
 *       real upserter would never produce, so the test asserts against a fixture's idea of the
 *       payload rather than the code's — and the six mandatory payload fields are exactly what the
 *       tenant filter matches on. Second, the source must be ACTIVE-versioned by the real
 *       publication path, or `source_version_id` and `source_status` (two of the four filter terms)
 *       will not match anything and the negative assertion passes because the positive one would
 *       have failed too.
 *
 *       Never against QdrantClient(":memory:") on the Python side either: its fusion path ignores
 *       the root filter, so the leaky query is safe in memory and only leaks in production.
 *
 *   assignedTo(Bot $bot)
 *       Creates the `bot_source_assignments` row. It NEVER infers an organization — see crossOrg().
 *
 *   crossOrg(Bot $foreignBot)
 *       DELIBERATELY assigns a source from one organization to a bot in another. This is the one row
 *       in the schema that can span two organizations: `bot_id` and `source_id` each inherit their
 *       own org and nothing in the FK graph forces them to agree.
 *
 *       It exists ONLY so a test can assert that BOTH the service layer AND the database reject it —
 *       the denormalized `organization_id` on the row plus the composite foreign keys
 *       `(organization_id, bot_id)` and `(organization_id, source_id)`. One mis-scoped insert here is
 *       a permanent leak that every downstream filter AGREES with, because you have taught it that
 *       Org B's source belongs to Org A's bot. That is why assignedTo() must never quietly infer an
 *       organization: if it did, this state could not be expressed and the guard could not be tested.
 *
 * COLUMNS THIS MUST PRODUCE (docs/11 §16, kb-source-lifecycle):
 *
 *   knowledge_sources:
 *     id                    ULID
 *     organization_id       NOT NULL
 *     type                  file | url | text
 *     name / title          DISTINGUISHABLE between the two orgs of a pair
 *     status                the lifecycle state — pending | processing | ready |
 *                           ready_with_warnings | failed | disabled | deleting
 *     active_version_id     nullable FK; set ONLY by the atomic publication path
 *     created_at / updated_at
 *
 *   source_items:      id, source_id (NOT NULL), storage_key under org/{org_id}/..., content_hash,
 *                      mime, byte_size
 *   source_versions:   id, source_item_id (NOT NULL), status, parser_config_version,
 *                      chunking_config_version, embedding_model_version, published_at
 *   chunks:            id, source_version_id (NOT NULL), seq, text, page, vector_point_id
 *                      (the deterministic uuid5 written back so PostgreSQL can name every point it
 *                      owns — deletion targets stable identifiers, never a text match)
 *   bot_source_assignments:
 *                      organization_id (DENORMALIZED, NOT NULL), bot_id, source_id
 *
 * FORWARD REFERENCE, DELIBERATELY IN PROSE: this factory builds App\Models\KnowledgeSource, which does not
 * exist yet. The binding is NOT written as `@extends Factory<\App\Models\KnowledgeSource>` plus
 * `protected $model = \App\Models\KnowledgeSource::class;` because a `::class` pointer at a class that does
 * not exist is five level-8 errors per file, and phpstan.neon carries no baseline on purpose
 * (ADR-020). Nothing is lost by omitting it: Factory::modelName() resolves
 * Database\Factories\KnowledgeSourceFactory -> App\Models\KnowledgeSource by convention, so the binding is
 * identical the moment the model lands. RESTORE BOTH LINES in the PR that creates the model, in
 * the same commit as this paragraph's deletion.
 *
 * @extends Factory<\Illuminate\Database\Eloquent\Model>
 */
final class KnowledgeSourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        throw new RuntimeException(
            'KnowledgeSourceFactory is scaffolded but not implemented: it needs '
            .'App\Models\KnowledgeSource and the knowledge_sources / source_items / source_versions '
            .'/ chunks / bot_source_assignments migrations. See this class docblock.',
        );
    }

    /**
     * Ingest $content through the real pipeline and publish an active version.
     *
     * The canary is the caller's, and it is regenerated per test so a stale Qdrant point or a warm
     * answer cache left by a previous run can never satisfy an assertion.
     */
    public function indexed(string $content): static
    {
        throw new RuntimeException(
            'KnowledgeSourceFactory::indexed() is scaffolded but not implemented: it must drive the '
            .'REAL ingestion path into the test Qdrant container and publish an active version. A '
            .'hand-written Qdrant point asserts the fixture author\'s idea of the payload instead of '
            .'the upserter\'s, and the payload is what the tenant filter matches on.',
        );
    }

    /**
     * Assign this source to $bot. Never infers an organization — see crossOrg().
     */
    public function assignedTo(mixed $bot): static
    {
        throw new RuntimeException(
            'KnowledgeSourceFactory::assignedTo() is scaffolded but not implemented. It must write '
            .'bot_source_assignments.organization_id EXPLICITLY and must not infer it from either '
            .'side, or crossOrg() below becomes inexpressible.',
        );
    }

    /**
     * The one row that can span two organizations. Used only to assert it is REJECTED.
     */
    public function crossOrg(mixed $foreignBot): static
    {
        throw new RuntimeException(
            'KnowledgeSourceFactory::crossOrg() is scaffolded but not implemented. It must attempt '
            .'the mis-scoped assignment so a test can assert BOTH the service AND the composite '
            .'foreign key reject it. If this state ever succeeds silently, that is the finding.',
        );
    }
}
