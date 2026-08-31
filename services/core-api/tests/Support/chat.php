<?php

declare(strict_types=1);

use App\Models\Bot;
use App\Models\KnowledgeSource;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use App\Services\Sdk\WidgetSessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ChatFixture;
use Tests\Support\ChatScenario;

/**
 * One organization wired end to end for a chat turn.
 *
 * ═══ IT WRITES THE INGESTION ROWS DIRECTLY, AND THAT IS A COMPROMISE WITH A REASON ══════════
 *
 * `KnowledgeSourceFactory::indexed()` — the state that drives the REAL ingestion path — throws by
 * design: ADR-030 removed local embedding, so publishing a version means a live provider call over
 * the network on a real credential from inside the suite. Its message says exactly that and names
 * the two shapes that would close it.
 *
 * What THIS helper needs is narrower and is not blocked by any of it: `allowed_version_ids` is
 * resolved by a SQL join over `bot_source_assignments ⋈ knowledge_sources ⋈ source_items ⋈
 * source_versions`, and every predicate in that join is a relational fact. No vector is involved, no
 * Qdrant point is asserted, and nothing here claims anything about the upserter's payload — which is
 * the thing `indexed()`'s message warns against faking.
 *
 * SO THE BOUNDARY IS: this helper may write the ROWS the scope query reads, and may not write
 * anything that stands in for an INDEX. A test that needs a real point still cannot have one.
 *
 * ═══ EVERY ROW IS IN ONE ORGANIZATION, AND THE FACTORIES REFUSE OTHERWISE ══════════════════
 *
 * `recycle($organization)` is passed to every factory. Without it each nested factory mints its own
 * organization — the failure that makes an isolation test pass with the tenant filter deleted — and
 * `BotFactory`, `ProviderModelEntryFactory` and `KnowledgeSourceFactory` all RAISE rather than
 * silently doing it.
 */
function chatFixture(?Organization $organization = null, string $origin = 'https://customer.example'): ChatFixture
{
    $organization ??= Organization::factory()->create();

    $connection = ProviderConnection::factory()->recycle($organization)->create();

    $model = ProviderModelEntry::factory()
        ->recycle($organization)
        ->recycle($connection)
        ->create([
            // A REAL VENDOR ID, because the embedding connection is resolved by matching the
            // `(provider, model)` pair parsed out of the version's own `embedding_model_version`
            // (ADR-035). A faker-generated model name would still work — the fixture builds the
            // identity string FROM this value below — but naming it makes the coupling visible.
            'model' => 'fixture-chat-model',
            'context_window' => 8192,
            'max_output_tokens' => 1024,
        ]);

    $bot = Bot::factory()
        ->recycle($organization)
        ->published()
        ->publiclyAccessible()
        ->usingModel($connection, $model)
        ->withOrigins([$origin])
        ->create();

    $source = KnowledgeSource::factory()
        ->recycle($organization)
        ->assignedTo($bot)
        ->status(\App\Enums\SourceState::Ready)
        ->create();

    // THE IDENTITY IS BUILT FROM THE CONNECTION'S OWN PAIR, so `embeddingConnectionForSpace()`
    // resolves it. A mismatch here produces a `validation` refusal that reads like a resolver bug —
    // see ChatFixture's docblock.
    $identity = 'emb/v1:'.$connection->provider->value.':'.$model->model.':d1024:'.str_repeat('a', 16);

    // LOWER CASE, because `HasUlids::newUniqueId()` is `strtolower((string) Str::ulid())` and every
    // id already in this database is spelled that way. Under `COLLATE "C"` the two spellings are
    // different values, so a fixture that minted upper-case ids would be internally consistent and
    // would compare unequal to everything the application writes.
    $ulid = static fn (): string => Str::lower((string) Str::ulid());

    $itemId = $ulid();
    $versionId = $ulid();
    $elementId = $ulid();
    $chunkId = $ulid();
    $hash = str_repeat('a', 64);

    // WRITTEN THROUGH THE QUERY BUILDER RATHER THAN THROUGH MODELS. There is no factory for
    // `source_items` or `source_versions`, and every ownership column on both is outside `$fillable`
    // by design, so a model write would be five `forceFill`-shaped assignments in a helper —
    // and `forceFill` is arch-banned outside one annotated call site.
    // THE ITEM IS WRITTEN WITHOUT ITS POINTER, AND THAT ORDER IS FORCED BY THE SCHEMA. The two
    // tables reference each other — a version names its item, and the item names its ACTIVE version —
    // so `source_items_current_version_same_org` cannot be satisfied until the version row exists.
    // The pair is a cycle in the schema and never in time; the pointer is filled below, which is the
    // same order the real publication path uses.
    DB::table('source_items')->insert([
        'id' => $itemId,
        'organization_id' => $organization->id,
        'source_id' => $source->id,
        'canonical_key' => 'fixture/refund-policy.md',
        'title' => 'Refund policy',
        'content_hash' => $hash,
    ]);

    DB::table('source_versions')->insert([
        'id' => $versionId,
        'organization_id' => $organization->id,
        'source_item_id' => $itemId,
        'version_number' => 1,
        'content_hash' => $hash,
        'ingest_key' => $hash,
        'parser_cfg_version' => 'p1',
        'ocr_cfg_version' => 'o1',
        'chunker_cfg_version' => 'c1',
        'embedding_model_version' => $identity,
        'status' => \App\Enums\SourceState::Ready->value,
        // ACTIVATED AND NOT RETIRED — the two predicates that make it part of the resolved scope,
        // and non-negotiable 5's read side. A version missing `activated_at` is invisible to
        // retrieval, which is what a test asserting the empty-scope refusal relies on.
        'activated_at' => now('UTC'),
        'retired_at' => null,
    ]);

    DB::table('source_items')->where('id', '=', $itemId)
        ->update(['current_version_id' => $versionId]);

    DB::table('document_elements')->insert([
        'id' => $elementId,
        'organization_id' => $organization->id,
        'source_version_id' => $versionId,
        'seq' => 0,
        'kind' => \App\Enums\DocumentElementKind::Text->value,
        'text' => 'Refunds are accepted for 30 days.',
        'char_start' => 0,
        'char_end' => 33,
    ]);

    // THE CHUNK EXISTS SO A CITATION CAN BE HYDRATED. `citations.excerpt` and `location_metadata`
    // come from THIS table — the wire carries neither — so a turn whose citation frame names a chunk
    // that does not exist writes no citation row at all, which is correct behaviour and would make a
    // citation assertion vacuous.
    DB::table('chunks')->insert([
        'id' => $chunkId,
        'organization_id' => $organization->id,
        'source_id' => $source->id,
        'source_item_id' => $itemId,
        'source_version_id' => $versionId,
        'seq' => 0,
        'document_element_id' => $elementId,
        'element_ids' => '{'.$elementId.'}',
        'heading_path' => '{"Refund policy"}',
        'page' => 1,
        'char_start' => 0,
        'char_end' => 33,
        'lang' => 'en',
        'content_type' => \App\Enums\ChunkContentType::Prose->value,
        'token_count' => 12,
        'content_hash' => $hash,
        'text' => 'Refunds are accepted for 30 days.',
        'vector_point_id' => (string) Str::uuid(),
        'index_status' => \App\Enums\ChunkIndexStatus::Indexed->value,
        'embedding_model_id' => $identity,
        'parser_version' => 'p1',
        'chunker_version' => 'c1',
    ]);

    return new ChatFixture(
        organization: $organization,
        bot: $bot,
        connection: $connection,
        model: $model,
        source: $source,
        origin: $origin,
        embeddingIdentity: $identity,
        versionIds: [$versionId],
        chunkId: $chunkId,
    );
}

/**
 * Mint a real chat-session token for a fixture, through the real service.
 *
 * THROUGH THE SERVICE AND NOT BY WRITING A VALKEY KEY BY HAND. The stored record's shape, its
 * digest, its TTL and the key's own grammar are all `WidgetSessionService`'s decisions; a
 * hand-written key asserts the fixture author's idea of them, and `resolve()` would then be tested
 * against a record only the test knows how to produce.
 */
function chatSessionToken(ChatFixture $fixture): string
{
    $session = app(WidgetSessionService::class)->mint(
        (string) $fixture->bot->public_bot_id,
        $fixture->origin,
    );

    if ($session === null) {
        throw new \RuntimeException(
            'chatSessionToken() could not mint a session for the fixture. That means the bot is not '
            .'published, not public, or the origin is not on its active allow-list — all three are '
            .'set by chatFixture(), so a failure here is a fixture defect rather than a product one.',
        );
    }

    return $session['token'];
}

/**
 * Read org-scoped models from a test, with the tenant context bound.
 *
 * ═══ A TEST HAS NO TENANT CONTEXT, AND `OrganizationScope` FAILS CLOSED ════════════════════
 *
 * `Conversation`, `ProviderCall`, `UsageEvent`, `Bot` and `BotDomain` all carry
 * `#[ScopedBy(OrganizationScope::class)]`, which appends `whereRaw('1 = 0')` when nothing is bound.
 * Inside a REQUEST the middleware binds it; a test asserting on rows AFTERWARDS is outside that
 * request, so `Conversation::query()->find($id)` answers null for a row that plainly exists — and
 * the failure reads as "the endpoint did not write it" rather than as "this read had no scope".
 *
 * BINDING IS THE RIGHT FIX AND `withoutGlobalScopes()` IS NOT. The scope is not in the way here, it
 * is simply unbound: the test knows exactly which organization it means, and saying so is a
 * restatement rather than a bypass. `kb-tenancy-isolation` NN4 forbids a bypass EXISTING to be
 * called, and this helper is not one — it narrows a read that would otherwise be unscoped.
 *
 * @template T
 *
 * @param  callable(): T  $read
 * @return T
 */
function asTenant(string $organizationId, callable $read): mixed
{
    return app(\App\Support\Tenancy\TenantContext::class)->runFor($organizationId, $read);
}

/**
 * Re-declare the three public-surface throttles as unlimited, for the duration of one test.
 *
 * ═══ WHY THIS IS NEEDED AT ALL, AND WHY IT IS NOT A BYPASS ═════════════════════════════════
 *
 * All three keep their counters in Valkey, on the NON-EVICTING instance, and nothing resets them
 * between tests — `RefreshDatabase` rolls back PostgreSQL and has no opinion about a cache. So a
 * file with sixteen requests from `127.0.0.1` exhausts a ten-per-minute limiter partway through and
 * every test after it fails with a 429 that has nothing to do with what it was asserting. Worse, the
 * failures MOVE with test order, which is the shape that gets a suite marked flaky and skipped.
 *
 * `Cache::flush()` is the reflex and is banned: under `--parallel` it wipes the sibling workers'
 * keyspaces. Clearing the specific keys means re-deriving `md5($limiterName.$key)` from the
 * framework's own internals in a test, which is a copy of a private detail.
 *
 * `RateLimiter::for()` is the PUBLIC API and simply re-registers the limiter — so this is the
 * framework's own supported way to say "not this test".
 *
 * ═══ IT IS NOT CALLED EVERYWHERE, AND THAT IS THE POINT ════════════════════════════════════
 *
 * The limiters are real controls: `sdk-bootstrap` is the enumeration cover that makes the 404 rule
 * affordable, because `botByPublicId()` is deliberately unscoped by organization. A helper that
 * every test called would make them untested, so the tests that assert THE LIMITER ITSELF do not
 * call this — and one of them must exist, or this helper has quietly disabled a security control for
 * the whole suite.
 */
function relaxPublicSurfaceLimiters(): void
{
    foreach (['sdk-bootstrap', 'runtime-read', 'runtime-write'] as $limiter) {
        \Illuminate\Support\Facades\RateLimiter::for(
            $limiter,
            static fn (): \Illuminate\Cache\RateLimiting\Limit => \Illuminate\Cache\RateLimiting\Limit::none(),
        );
    }
}

/**
 * The headers a public runtime request carries: the bearer and nothing else.
 *
 * NO COOKIE, NO CSRF TOKEN, NO `Origin`. The runtime group is outside `api/`, so it never acquires
 * the session stack — and the frame's own `Origin` is our widget host on every real request, which
 * is precisely why `resolve()` re-validates the STORED embedder origin instead of the request's.
 *
 * @return array<string, string>
 */
function chatHeaders(string $token, string $accept = 'application/json'): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'Accept' => $accept,
    ];
}

/**
 * A fixture, a live session token and an open conversation — everything a chat-surface test needs.
 *
 * IT OPENS THE CONVERSATION THROUGH THE REAL ENDPOINT rather than through a factory, because the
 * ownership columns and the participant are exactly what that endpoint decides. A factory-made
 * conversation would carry whichever session id the fixture chose, and every ownership assertion
 * built on it would be testing the fixture.
 */
function chatScenario(?string $origin = null): ChatScenario
{
    $fixture = $origin === null ? chatFixture() : chatFixture(origin: $origin);
    $token = chatSessionToken($fixture);

    $conversation = (string) currentTest()
        ->postJson('/rt/v1/conversations', [], chatHeaders($token))
        ->assertStatus(201)
        ->json('data.id');

    return new ChatScenario($fixture, $token, $conversation);
}
