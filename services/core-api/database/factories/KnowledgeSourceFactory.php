<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SourceState;
use App\Enums\SourceType;
use App\Models\Bot;
use App\Models\BotSourceAssignment;
use App\Models\KnowledgeSource;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * Where the canary will live. This is the most consequential factory in the suite.
 *
 * tenantPair() plants a fresh canary string per test and every isolation test asserts it is absent
 * from ORG A's raw response body — so a canary hiding in a citation title, an export cell, or a
 * cached completion still trips the assertion.
 *
 * ── WHERE THE CANARY ACTUALLY IS TODAY, STATED SO THIS DOCBLOCK CANNOT GO STALE ──────────────
 *
 * IT IS STILL IN ORG B'S BOT WELCOME MESSAGE, and it did NOT move when this factory was
 * implemented. `indexed()` below is the state the shipped design puts it in, and `indexed()` is
 * still deferred — see its own message. Moving the canary onto a source whose content never reaches
 * the index would make every isolation test assert against a string that no retrieval path could
 * have leaked, which is a weaker test that reads as a stronger one. tests/Support/tenancy.php
 * carries the same rule from the other side: MOVE it when `indexed()` lands, do not plant a second.
 *
 * ── IT REQUIRES A RECYCLED ORGANIZATION AND REFUSES TO RUN OTHERWISE ─────────────────────────
 *
 * `KnowledgeSource::factory()->for($orgA)` fixes ONE edge. Every NESTED factory a definition
 * resolves still mints its own organization unless it is pinned, and `recycle($orgA)` is what pins
 * them all. The symptom of getting it wrong is an isolation test that passes with the tenant filter
 * deleted — the row it was meant to prove was hidden belonged to nobody in the test to begin with.
 * So `requireOrganization()` raises rather than minting one, exactly as BotFactory and
 * ProviderModelEntryFactory do.
 *
 * ── THREE STATES, AND EACH ONE EXISTS FOR A DIFFERENT FAILURE ────────────────────────────────
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
 *       STILL DEFERRED. See the method for the one thing that blocks it.
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
 *       `bot_source_assignments_bot_same_org` and `bot_source_assignments_source_same_org`. One
 *       mis-scoped insert here is a permanent leak that every downstream filter AGREES with, because
 *       you have taught it that Org B's source belongs to Org A's bot. That is why assignedTo() must
 *       never quietly infer an organization: if it did, this state could not be expressed and the
 *       guard could not be tested.
 *
 * ── THE COLUMNS THIS PRODUCES, CORRECTED BY THE PHASE C1 RULINGS ─────────────────────────────
 *
 * The list this docblock used to carry was written before the schema existed and is superseded in
 * four places. Each correction is named, because the old list will otherwise be read as the
 * authority it was:
 *
 *   R1  NO `active_version_id` ON `knowledge_sources`. The active-version pointer is
 *       `source_items.current_version_id` and there is no source-level counterpart — a source-level
 *       pointer is meaningless once a crawl gives one source hundreds of independently-versioned
 *       items, and `services/ai-service/app/db/writes.py:50` states the data plane never assigns
 *       it.
 *   R2  `parser_cfg_version`, `ocr_cfg_version`, `chunker_cfg_version` — ALL THREE. The old list
 *       named `parser_config_version` and `chunking_config_version` and omitted OCR entirely;
 *       `app/ingestion/identity.py:66-77` makes `ocr_cfg_version` an ingest-key component, so
 *       omitting it means an OCR retune silently no-ops. Dates are `activated_at` and `retired_at`,
 *       never `published_at`: the partial unique index depends on both.
 *   R3  `status` is one of `SourceState`'s FIFTEEN values, on `knowledge_sources` and on
 *       `source_versions`. The old seven-value list including `pending` and `processing` is
 *       superseded — neither string appears anywhere in
 *       `services/ai-service/app/ingestion/states.py`.
 *   R4  `content_hash` is `char(64) COLLATE "C"` hex on `source_items`, `source_versions` and
 *       `chunks`, departing from `postgresql-patterns`' `bytea`. The reasons are in
 *       `2026_08_20_001900_create_source_items_table.php` and the ADR is being written in parallel.
 *
 * Two further corrections that are not rulings but were wrong in the old list: `source_items`
 * carries the user's filename in `display_name` and NEVER a path
 * (kb-security-baseline/references/file-upload-safety.md), and `chunks.vector_point_id` is a `uuid`
 * — the one legitimate uuid column in this schema, because Qdrant point ids may only be u64 or
 * UUID.
 *
 * @extends Factory<KnowledgeSource>
 */
final class KnowledgeSourceFactory extends Factory
{
    protected $model = KnowledgeSource::class;

    /**
     * A DRAFT FILE SOURCE WITH NO ITEMS AND NO VERSIONS, which is the first real state.
     *
     * `Draft` means "created, never submitted; no version exists" — it is what every source is
     * before anything is uploaded into it, and a factory that produced a fully-ingested source
     * would leave that branch untested and would make every `MATCH SIMPLE` half of the schema
     * exercised only in its populated form. `indexed()` is the state that ingests; `crawl()` is the
     * state that makes it a URL source.
     *
     * NOTE WHAT IS NOT CREATED HERE. `kb-source-lifecycle` requires every source to have at least
     * one `source_items` row, including a single-file upload — but that is true of a source that
     * has been SUBMITTED, and a draft has not. The item is created by whichever state puts content
     * into the source, so this default cannot produce the one shape the doctrine forbids: a
     * submitted source with no item.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $organization = $this->requireOrganization();

        // EVERY HUMAN-READABLE COLUMN IS DISTINGUISHABLE BETWEEN TWO ORGANIZATIONS. A factory
        // producing `name: 'Test'` for both organizations of a pair makes a cross-tenant leak
        // compare equal to itself: the response body genuinely contains Org B's row, the assertion
        // compares it against Org A's name, and they are the same string. The whole isolation suite
        // is built on a leaked value LOOKING like a value from the wrong org.
        $name = $this->faker->unique()->company().' Handbook';

        return [
            // Taken from the RECYCLED organization and from nowhere else. `organization_id` is not
            // in the model's $fillable, so nothing outside a factory or a repository can set it.
            'organization_id' => $organization->id,

            'type' => SourceType::File,
            'name' => $name,
            'description' => $this->faker->unique()->sentence(),

            // NULL, and the schema requires it: `knowledge_sources_origin_url_matches_type` is an
            // equality between two booleans, so a file source carrying a URL somebody expects us to
            // fetch is refused outright. `crawl()` sets the type and the URL together for the same
            // reason BotFactory::thresholdedAt() sets its pair together.
            'origin_url' => null,

            'status' => SourceState::Draft,

            // The empty set, which is "nobody has tagged this". A tagged fixture would leave the
            // untagged majority state untested, and the array cast is exercised either way.
            'tags' => [],

            // No time window. A source that is retrievable now and forever is the ordinary case;
            // `effectiveBetween()` is what a test asking about the window uses.
            'effective_at' => null,
            'expires_at' => null,
        ];
    }

    /**
     * A crawl source: type `url`, with the origin URL the schema requires alongside it.
     *
     * The URL is EXPLICIT and never faker-generated. A crawl fixture whose URL is random makes any
     * assertion about what we would fetch depend on faker never colliding with something the test
     * also uses, and `origin_url` is the one column on this table an SSRF review reads.
     */
    public function crawl(string $originUrl): static
    {
        return $this->state(fn (): array => [
            'type' => SourceType::Url,
            'origin_url' => $originUrl,
        ]);
    }

    /**
     * Put this source in a given lifecycle state.
     *
     * IT CREATES NO VERSIONS, AND THAT IS THE LIMIT OF WHAT THIS STATE MEANS. A source whose
     * `status` is `Ready` but which has no `source_items` and no activated `source_versions` is
     * retrievable by NOTHING — the active-version pointer is empty, so two of the four mandatory
     * Qdrant filter terms match nothing. Use it to exercise a status predicate, a list filter or a
     * transition guard; never to stand in for an ingested source. `indexed()` is that, and
     * `indexed()` is deferred.
     */
    public function status(SourceState $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    /**
     * Give the source a retrieval time window.
     *
     * Both ends together, because `knowledge_sources_window_ordered` refuses a window that closes
     * before it opens and because a half-set window is a fixture whose meaning depends on which
     * half was omitted.
     */
    public function effectiveBetween(?string $effectiveAt, ?string $expiresAt): static
    {
        return $this->state(fn (): array => [
            'effective_at' => $effectiveAt,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Ingest $content through the real pipeline and publish an active version.
     *
     * The canary is the caller's, and it is regenerated per test so a stale Qdrant point or a warm
     * answer cache left by a previous run can never satisfy an assertion.
     *
     * @param  string  $content  the text to ingest, canary included
     */
    public function indexed(string $content): static
    {
        // ── ONE BLOCKER REMAINS, AND IT IS NOT THE ONE THIS MESSAGE USED TO NAME ─────────────
        //
        // The migrations exist (Phase C1), App\Models\KnowledgeSource exists, and `qdrant-test` is
        // already a service in the `test` Compose profile — so every schema and container clause
        // this message used to carry is stale and has been removed rather than left to be
        // rediscovered.
        //
        // What is left is a LIVE PROVIDER EMBEDDING CALL. ADR-030 removed local embedding: there is
        // no in-process embedder any more, so driving the real ingestion path to a published
        // version means `embed(texts, model=...)` going out over the network on a real
        // per-organization credential, at a real per-token price, from inside `composer ci`. A
        // suite that cannot run offline is a suite that fails for reasons unrelated to the change
        // under test, and a suite that bills a vendor per run is one somebody eventually stops
        // running.
        //
        // The repo owner deferred it on that basis. The two shapes that would close it, named so
        // the next person does not have to derive them: a recorded-cassette embedder pinned to this
        // fixture's exact text, or a deterministic test-only provider adapter registered for the
        // suite alone — and the second one has to be reconciled with kb-tenancy-isolation NN4,
        // which forbids a test-only bypass EXISTING to be called.
        throw new RuntimeException(
            'KnowledgeSourceFactory::indexed() is deferred, and one thing blocks it: driving the '
            .'REAL ingestion path to a published version requires a LIVE PROVIDER EMBEDDING CALL '
            .'inside the suite. ADR-030 removed local embedding, so there is no in-process embedder '
            .'to reach for — the call goes out over the network, on a real credential, at a real '
            .'per-token price, from `composer ci`. Everything else it needs now exists: the '
            .'knowledge_sources / source_items / source_versions / document_elements / chunks '
            .'migrations, App\Models\KnowledgeSource, and the qdrant-test service in the `test` '
            .'Compose profile. Do NOT substitute a hand-written Qdrant point: it asserts the fixture '
            .'author\'s idea of the payload instead of the upserter\'s, and the payload is what the '
            .'tenant filter matches on. Content that would have been ingested: '
            .mb_strimwidth($content, 0, 60, '...'),
        );
    }

    /**
     * Assign this source to $bot.
     *
     * ── THE ORGANIZATION IS STATED, NEVER INFERRED, AND THAT IS THE WHOLE POINT ──────────────
     *
     * `bot_source_assignments.organization_id` is written from THE RECYCLED ORGANIZATION — the one
     * the call site declared with `->recycle($org)` — and not from `$bot->organization_id`, and not
     * from the source's own column either.
     *
     * IF IT WERE INFERRED FROM EITHER SIDE, `crossOrg()` WOULD BE INEXPRESSIBLE. A derived value
     * always agrees with the side it was derived from, so one of the two composite foreign keys
     * would always pass and only the other would ever be under test — and a service that derived it
     * the same way would produce rows satisfying both keys while still being wrong in exactly the
     * case the keys exist for.
     *
     * The row is built field by field rather than through a factory, because `organization_id`,
     * `bot_id` and `source_id` are all outside `BotSourceAssignment::$fillable` — deliberately,
     * since they are the ownership edges — and `Model::shouldBeStrict()` turns a `fill()` naming
     * one into an exception. Same construction as `BotFactory::withOrigins()`.
     */
    public function assignedTo(Bot $bot): static
    {
        return $this->afterCreating(function (KnowledgeSource $source) use ($bot): void {
            $organization = $this->requireOrganization();

            if ($bot->organization_id !== $organization->id) {
                // Named here rather than left to arrive as SQLSTATE 23503 with a constraint name,
                // which reads like a schema bug and sends the reader to the migration. In a
                // two-organization fixture this is a one-letter typo, and the row it would produce
                // is the exact cross-tenant row the isolation suite exists to prove cannot be
                // reached. A test that WANTS that row asks for it by name.
                throw new RuntimeException(
                    'KnowledgeSourceFactory::assignedTo() was given a bot belonging to a DIFFERENT '
                    .'organization than the recycled one. That row is the one row in the schema '
                    .'that can span two organizations, and it is not something to create by '
                    .'accident: use crossOrg($foreignBot) if you are asserting that it is REFUSED.',
                );
            }

            $this->writeAssignment($organization, $bot, $source);
        });
    }

    /**
     * The one row that can span two organizations. Used ONLY to assert it is REJECTED.
     *
     * The organization written onto the row is the RECYCLED one — the source's — while `bot_id`
     * names a bot in another organization, so `bot_source_assignments_bot_same_org` is the
     * constraint that must raise. `tests/Security/KnowledgeSourceTenancyTest.php` asserts that
     * constraint BY NAME rather than merely asserting "something threw", because a bare exception
     * assertion passes when the write fails for an unrelated reason — a NOT NULL violation on a
     * column the fixture forgot would look identical.
     *
     * IF THIS EVER SUCCEEDS SILENTLY, THAT IS THE FINDING. Not a flaky test: a permanent
     * cross-tenant leak that every downstream filter would agree with.
     */
    public function crossOrg(Bot $foreignBot): static
    {
        return $this->afterCreating(function (KnowledgeSource $source) use ($foreignBot): void {
            $organization = $this->requireOrganization();

            if ($foreignBot->organization_id === $organization->id) {
                // A "cross-org" state whose bot is in the SAME organization writes a perfectly
                // legal row, both keys pass, and the test asserting a refusal fails for the right
                // reason with the wrong explanation — or, worse, is "fixed" by deleting the
                // assertion. The fixture refuses to be a no-op.
                throw new RuntimeException(
                    'KnowledgeSourceFactory::crossOrg() was given a bot in the SAME organization as '
                    .'the recycled one, so the row it would write is legal and both composite '
                    .'foreign keys pass. This state exists only to be REFUSED; pass a bot from the '
                    .'other organization of the pair.',
                );
            }

            $this->writeAssignment($organization, $foreignBot, $source);
        });
    }

    /**
     * The single writer of a `bot_source_assignments` row in this factory.
     *
     * One method for both states on purpose: `assignedTo()` and `crossOrg()` must differ ONLY in
     * which bot they are given, never in how the row is built. Two writers would let the legal path
     * and the illegal path drift, and the illegal one is the one nobody exercises in production.
     */
    private function writeAssignment(Organization $organization, Bot $bot, KnowledgeSource $source): void
    {
        $assignment = new BotSourceAssignment;
        $assignment->organization_id = $organization->id;
        $assignment->bot_id = $bot->id;
        $assignment->source_id = $source->id;
        $assignment->priority = 0;
        $assignment->enabled = true;
        $assignment->save();
    }

    /**
     * The recycled organization, or a failure that names the fixture mistake.
     *
     * `getRandomRecycledModel()` and not `$this->recycle` directly: it is the documented accessor
     * and the one that returns null rather than raising when nothing was recycled, which is the
     * case this method has to detect and refuse loudly.
     */
    private function requireOrganization(): Organization
    {
        $organization = $this->getRandomRecycledModel(Organization::class);

        if (! $organization instanceof Organization) {
            throw new RuntimeException(
                'KnowledgeSourceFactory requires a recycled organization: '
                .'KnowledgeSource::factory()->recycle($org). Letting it mint its own would put the '
                .'source in a THIRD organization, which is the failure that makes an isolation test '
                .'pass with the tenant filter deleted — the row it was meant to prove was hidden '
                .'belonged to nobody in the test to begin with.',
            );
        }

        return $organization;
    }
}
