<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Enums\SourceState;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Feedback;
use App\Models\KnowledgeSource;
use App\Models\Message;
use App\Models\Organization;
use App\Models\ProviderCall;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use App\Models\RetrievalTrace;
use App\Models\SourceItem;
use App\Models\SourceVersion;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| §8.23 — the usage and quality dashboard
|--------------------------------------------------------------------------
|
| ELEVEN TILES, ELEVEN ORG-SCOPED AGGREGATES, ONE RESPONSE. What this suite asserts, in order of how
| easy each is to get wrong:
|
|   1. THE ARITHMETIC THAT IS SILENTLY WRONG. Output tokens include reasoning tokens; input tokens do
|      NOT include the cache columns a second time. Every fixture below carries a non-zero value in
|      the column the wrong implementation ignores or double-counts, because with zeroes the wrong
|      implementation passes.
|   2. THE POPULATIONS. `cancelled` is in neither side of the error rate; the fallback denominator is
|      primary ATTEMPTS and not distinct messages; ingestion counts VERSIONS and `ready_with_warnings`
|      is a success.
|   3. THE WINDOW. Half-open, bounded in width, and echoed back resolved.
|   4. THE SIX CHECKS on a read: permission, and the deliberate absence of an entity-status refusal.
|
| Cross-organization isolation is tests/Security/AnalyticsTenancyTest.php, on `tenantPair()`. It is
| NOT here, because a single-organization fixture cannot fail an isolation test.
*/

/**
 * One organization with a bot, a connection and a catalogue row that carries a PRICE.
 *
 * The price is what makes the cost column non-null, and the currency is what makes it renderable at
 * all — `provider_models_price_needs_currency` makes a price without one unwritable, so a null
 * currency in the response means "no price recorded" rather than "the join missed".
 *
 * @return array{org: Organization, actor: User, bot: Bot, connection: ProviderConnection, model: ProviderModelEntry}
 */
function analyticsFixture(OrgRole $role = OrgRole::Admin): array
{
    $org = Organization::factory()->create();
    $connection = ProviderConnection::factory()->recycle($org)->withModel('gpt-5.1', ['chat'])->create();

    $model = app(TenantContext::class)->runFor($org->id, static fn (): ProviderModelEntry => ProviderModelEntry::query()
        ->where('organization_id', '=', $org->id)
        ->where('provider_connection_id', '=', $connection->id)
        ->firstOrFail());

    // PRICED, so the cost tile has something to compute. Assigned directly rather than through mass
    // assignment, exactly as ProviderModelService does.
    $model->input_price_per_million = '2.000000';
    $model->output_price_per_million = '10.000000';
    $model->price_currency = 'USD';
    $model->save();

    return [
        'org' => $org,
        'actor' => User::factory()->recycle($org)->orgRole($role)->create(),
        'bot' => Bot::factory()->recycle($org)->create(),
        'connection' => $connection,
        'model' => $model,
    ];
}

/**
 * One `source_versions` row, with the `knowledge_sources -> source_items` chain under it.
 *
 * BUILT BY HAND BECAUSE THERE IS NO `SourceVersionFactory`, and writing one is not this change's to
 * make: the ingestion tile needs four rows in four states and nothing else in this suite touches the
 * cascade. Every column is assigned directly, which is also what the repository does — `$fillable`
 * on both models is empty, deliberately, because no client input reaches either table.
 *
 * `version_number` is unique per item, so each call gets its own ITEM rather than its own version of
 * one item: `source_versions_item_version_number` would refuse the second row otherwise, and a
 * shared item would additionally make `source_versions_one_active_per_item` relevant to a fixture
 * that is not about activation at all.
 */
function ingestionVersion(Organization $organization, SourceState $state): SourceVersion
{
    $source = KnowledgeSource::factory()->recycle($organization)->create();

    $item = new SourceItem;
    $item->organization_id = $organization->id;
    $item->source_id = $source->id;
    $item->canonical_key = 'file:'.Str::lower((string) Str::ulid());
    $item->save();

    $version = new SourceVersion;
    $version->organization_id = $organization->id;
    $version->source_item_id = $item->id;
    $version->version_number = 1;
    // Sixty-four lowercase hex characters: `source_versions_content_hash_is_hex` enforces the shape,
    // because a `char(64)` will happily hold 64 spaces and the failure that produces is an ingest key
    // for an identity that does not exist.
    $version->content_hash = str_repeat('a', 64);
    $version->ingest_key = str_repeat('b', 64);
    $version->parser_cfg_version = 'p1';
    $version->ocr_cfg_version = 'o1';
    $version->chunker_cfg_version = 'c1';
    $version->embedding_model_version = 'openai:text-embedding-3-large';
    $version->status = $state;
    $version->save();

    return $version;
}

/**
 * @param  array<string, string>  $query
 */
function analyticsUrl(Organization $organization, array $query = []): string
{
    $url = "/api/v1/organizations/{$organization->id}/analytics";

    return $query === [] ? $url : $url.'?'.http_build_query($query);
}

it('counts conversations, messages and unique sessions inside the window only', function (): void {
    $fixture = analyticsFixture();
    $now = CarbonImmutable::now('UTC');

    // TWO THREADS FROM ONE SESSION, plus one from another. Unique sessions is 2, conversations is 3
    // — a tile that returned the same number for both would be counting threads and calling them
    // people.
    $shared = 'sess_'.str_repeat('a', 26);

    // BOTH TIMESTAMPS MOVE TOGETHER. `conversations_activity_after_start` refuses
    // `last_activity_at < started_at`, and `ConversationFactory`'s default activity time is derived
    // from ITS OWN default start — so overriding only `started_at` produces a row the database
    // rejects with a constraint name, which reads as a schema bug rather than as a fixture that
    // moved one half of a pair.
    Conversation::factory()->recycle($fixture['org'])->recycle($fixture['bot'])
        ->count(2)->create([
            'anonymous_session_id' => $shared,
            'started_at' => $now->subDay(),
            'last_activity_at' => $now->subDay(),
        ]);

    $third = Conversation::factory()->recycle($fixture['org'])->recycle($fixture['bot'])
        ->create(['started_at' => $now->subDay(), 'last_activity_at' => $now->subDay()]);

    Message::factory()->recycle($third)->count(4)->create();

    // OUTSIDE THE WINDOW. It exists so the assertion proves the window BOUNDS the count rather than
    // reading the whole table — a fixture with only in-window rows passes against no predicate at
    // all.
    Conversation::factory()->recycle($fixture['org'])->recycle($fixture['bot'])
        ->create(['started_at' => $now->subDays(120), 'last_activity_at' => $now->subDays(120)]);

    currentTest()->actingAs($fixture['actor'])
        ->getJson(analyticsUrl($fixture['org'], [
            'from' => $now->subDays(7)->toIso8601String(),
            'until' => $now->addHour()->toIso8601String(),
        ]))
        ->assertOk()
        ->assertJsonPath('data.conversations', 3)
        ->assertJsonPath('data.messages', 4)
        ->assertJsonPath('data.unique_sessions', 2);
});

it('reports token usage per model with reasoning in the output and no double-counted cache', function (): void {
    $fixture = analyticsFixture();

    ProviderCall::factory()
        ->recycle($fixture['org'])->recycle($fixture['bot'])
        ->recycle($fixture['connection'])->recycle($fixture['model'])
        ->create([
            // 4,000 TOTAL INPUT WITH 3,000 OF IT CACHED. The additive form —
            // `input + cache_read + cache_write` — reports 7,000, which is the first silent defect.
            'input_tokens' => 4_000,
            'cache_read_tokens' => 2_500,
            'cache_write_tokens' => 500,
            // 200 VISIBLE TOKENS BEHIND 1,800 REASONING TOKENS. `SUM(output_tokens)` reports 200,
            // which is the second — and 200 looks entirely plausible on a dashboard.
            'output_tokens' => 200,
            'reasoning_tokens' => 1_800,
        ]);

    $response = currentTest()->actingAs($fixture['actor'])
        ->getJson(analyticsUrl($fixture['org']))
        ->assertOk()
        ->assertJsonPath('data.usage_by_model.0.provider', 'openai')
        ->assertJsonPath('data.usage_by_model.0.model', 'gpt-5.1')
        ->assertJsonPath('data.usage_by_model.0.input_tokens', 4_000)
        ->assertJsonPath('data.usage_by_model.0.output_tokens', 2_000)
        ->assertJsonPath('data.usage_by_model.0.currency', 'USD');

    // COST IS A STRING AND IS COMPUTED FROM THE TOKENS AND THE PRICE IN FORCE:
    // 4,000 × 2.00/1e6 + 2,000 × 10.00/1e6 = 0.008 + 0.020 = 0.028.
    // Compared numerically rather than as a literal, because PostgreSQL's `numeric` renders its own
    // scale and pinning the digits would assert a formatting decision rather than an amount.
    expect((float) $response->json('data.usage_by_model.0.estimated_cost'))
        ->toEqualWithDelta(0.028, 0.0000001);
});

it('keeps a cancelled attempt out of both sides of the error rate', function (): void {
    $fixture = analyticsFixture();

    $call = fn (): mixed => ProviderCall::factory()
        ->recycle($fixture['org'])->recycle($fixture['bot'])
        ->recycle($fixture['connection'])->recycle($fixture['model']);

    $call()->create();
    $call()->failed()->create();
    // THE ONE THAT MUST NOT COUNT. A user closing the tab is not a provider error, and counting it
    // as one hides real outages behind ordinary behaviour — the denominator would be 3 and the rate
    // 0.33 instead of 0.5.
    $call()->cancelled()->create();

    currentTest()->actingAs($fixture['actor'])
        ->getJson(analyticsUrl($fixture['org']))
        ->assertOk()
        ->assertJsonPath('data.provider_calls.succeeded', 1)
        ->assertJsonPath('data.provider_calls.failed', 1)
        ->assertJsonPath('data.provider_calls.error_rate', 0.5);
});

it('computes the fallback rate over primary attempts, which survive retention', function (): void {
    $fixture = analyticsFixture();

    $primary = ProviderCall::factory()
        ->recycle($fixture['org'])->recycle($fixture['bot'])
        ->recycle($fixture['connection'])->recycle($fixture['model'])
        ->failed()->create();

    ProviderCall::factory()
        ->recycle($fixture['org'])->recycle($fixture['bot'])
        ->recycle($fixture['connection'])->recycle($fixture['model'])
        ->fallbackFrom($primary)->create();

    // ONE TURN, TWO ROWS: one primary attempt and one fallback attempt. Both have a NULL
    // `message_id` (the factory's default is the post-retention state), which is the whole point —
    // a denominator built from `count(DISTINCT message_id)` would be 0 here and the rate undefined,
    // and in production it would SHRINK as retention ran, making a closed month's fallback rate
    // climb nightly with no fallback having happened.
    $response = currentTest()->actingAs($fixture['actor'])
        ->getJson(analyticsUrl($fixture['org']))
        ->assertOk()
        ->assertJsonPath('data.provider_calls.primary_attempts', 1)
        ->assertJsonPath('data.provider_calls.fallback_attempts', 1);

    // COMPARED NUMERICALLY AND NOT WITH `assertJsonPath(..., 1.0)`, and the reason is a JSON
    // encoding fact worth knowing rather than a test convenience: PHP's `json_encode` drops a zero
    // fraction unless `JSON_PRESERVE_ZERO_FRACTION` is passed, and Laravel does not pass it. So a
    // rate of exactly 1.0 or 0.0 arrives on the wire as the INTEGER `1` or `0` while 0.5 arrives as
    // `0.5` — the same field, two JSON types, depending on the value. JSON Schema's `number` admits
    // both, so the published contract is correct either way; an `assertJsonPath` with a float
    // literal is what is wrong.
    expect((float) $response->json('data.provider_calls.fallback_rate'))
        ->toEqualWithDelta(1.0, 0.0000001);
});

it('reports null rather than zero for a window with no traffic', function (): void {
    $fixture = analyticsFixture();

    currentTest()->actingAs($fixture['actor'])
        ->getJson(analyticsUrl($fixture['org']))
        ->assertOk()
        // "NO CALLS" AND "NO FAILURES" ARE DIFFERENT FACTS. Rendering 0% for an organization that
        // made no calls reports health nobody measured, and a 0 ms p50 puts an impossible latency on
        // a dashboard for an outage.
        ->assertJsonPath('data.provider_calls.error_rate', null)
        ->assertJsonPath('data.provider_calls.fallback_rate', null)
        ->assertJsonPath('data.latency.first_token_p50_ms', null)
        ->assertJsonPath('data.latency.total_p95_ms', null)
        ->assertJsonPath('data.usage_by_model', []);
});

it('splits feedback and counts insufficient-evidence answers through the two-hop chain', function (): void {
    $fixture = analyticsFixture();

    $conversation = Conversation::factory()->recycle($fixture['org'])->recycle($fixture['bot'])->create();
    $answer = Message::factory()->recycle($conversation)->assistant()->create();

    Feedback::factory()->recycle($answer)->create();
    Feedback::factory()->recycle($answer)->negative()->create();

    // `feedback` AND `retrieval_traces` HOLD NO `organization_id`. Both reach one only through
    // `messages -> conversations`, which is the longest join on this surface and the one most likely
    // to be shortened by somebody optimising — so this test exists as much for the JOIN as for the
    // counts.
    RetrievalTrace::factory()->recycle($answer)->create(['insufficient_evidence' => true]);
    RetrievalTrace::factory()->recycle(
        Message::factory()->recycle($conversation)->assistant()->create()
    )->create();

    currentTest()->actingAs($fixture['actor'])
        ->getJson(analyticsUrl($fixture['org']))
        ->assertOk()
        ->assertJsonPath('data.feedback.positive', 1)
        ->assertJsonPath('data.feedback.negative', 1)
        // ONE, NOT TWO. This tile measures a CORRECT refusal rather than a failure, which is why it
        // is its own number and not folded into the error rate.
        ->assertJsonPath('data.insufficient_evidence_answers', 1);
});

it('counts a warned ingestion as a success, and publishes what is still moving', function (): void {
    $fixture = analyticsFixture();

    $version = fn (SourceState $state): SourceVersion => ingestionVersion($fixture['org'], $state);

    $version(SourceState::Ready);
    // READY_WITH_WARNINGS IS A SUCCESS. The version IS searchable and IS serving answers; filing it
    // as a failure would report an outage that is not happening, and the warning count is its own
    // surface.
    $version(SourceState::ReadyWithWarnings);
    $version(SourceState::Failed);
    $version(SourceState::Embedding);

    currentTest()->actingAs($fixture['actor'])
        ->getJson(analyticsUrl($fixture['org']))
        ->assertOk()
        ->assertJsonPath('data.ingestion.succeeded', 2)
        ->assertJsonPath('data.ingestion.failed', 1)
        // PUBLISHED so succeeded + failed + in_flight accounts for every version created in the
        // window. A reader who cannot make the numbers add up assumes one of them is wrong.
        ->assertJsonPath('data.ingestion.in_flight', 1);
});

it('restricts every tile to one bot when asked, and answers zero for a foreign bot id', function (): void {
    $fixture = analyticsFixture();
    $other = Bot::factory()->recycle($fixture['org'])->create();

    Conversation::factory()->recycle($fixture['org'])->recycle($fixture['bot'])->count(2)->create();
    Conversation::factory()->recycle($fixture['org'])->recycle($other)->create();

    currentTest()->actingAs($fixture['actor'])
        ->getJson(analyticsUrl($fixture['org'], ['bot_id' => $fixture['bot']->id]))
        ->assertOk()
        ->assertJsonPath('data.conversations', 2)
        ->assertJsonPath('data.window.bot_id', $fixture['bot']->id);

    // A BOT ID FROM ANOTHER ORGANIZATION IS NEITHER AN ERROR NOR A LEAK. There is no `exists:` rule
    // on the field, deliberately — one would be an existence oracle over every tenant's bots, which
    // is the Filament CVE-2026-48067 shape — and every repository predicate carries the organization
    // AND the bot together, so a foreign id matches nothing.
    $foreign = Bot::factory()->recycle(Organization::factory()->create())->create();

    currentTest()->actingAs($fixture['actor'])
        ->getJson(analyticsUrl($fixture['org'], ['bot_id' => $foreign->id]))
        ->assertOk()
        ->assertJsonPath('data.conversations', 0);
});

it('echoes the resolved window, defaulted when the caller sent neither bound', function (): void {
    $fixture = analyticsFixture();

    $response = currentTest()->actingAs($fixture['actor'])
        ->getJson(analyticsUrl($fixture['org']))
        ->assertOk();

    // WITHOUT THIS FIELD A CLIENT THAT SENT NEITHER BOUND CANNOT LABEL THE CHART. The default is 30
    // days, which is what a dashboard opens on.
    $from = CarbonImmutable::parse((string) $response->json('data.window.from'));
    $until = CarbonImmutable::parse((string) $response->json('data.window.until'));

    expect($from->diffInDays($until))->toEqualWithDelta(30, 0.01)
        ->and($response->json('data.window.bot_id'))->toBeNull();
});

it('refuses an inverted window and one wider than the ceiling', function (): void {
    $fixture = analyticsFixture();
    $now = CarbonImmutable::now('UTC');

    // INVERTED: half-open [from, until) with `until <= from` is empty, and every tile would read
    // zero — a page of zeroes that looks like "no traffic" rather than like a bad request.
    currentTest()->actingAs($fixture['actor'])
        ->getJson(analyticsUrl($fixture['org'], [
            'from' => $now->toIso8601String(),
            'until' => $now->subDay()->toIso8601String(),
        ]))
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation');

    // TOO WIDE. The bound is on WORK: every tile behind this page is an aggregate, so an unbounded
    // `from` is a full-table scan on a screen that renders on every visit. It is a rule rather than
    // a clamp, because silently narrowing the range would answer a different question and label it
    // with the caller's dates.
    currentTest()->actingAs($fixture['actor'])
        ->getJson(analyticsUrl($fixture['org'], [
            'from' => $now->subDays(400)->toIso8601String(),
            'until' => $now->toIso8601String(),
        ]))
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation');
});

it('is readable by an analyst and refused to a knowledge manager', function (): void {
    // THE PERMISSION SPLIT THAT `analytics.view` EXISTS FOR. It is held by Owner, Admin and ANALYST,
    // and withheld from the KNOWLEDGE MANAGER — a shape no other permission in the catalog has,
    // which is why it is a new case rather than a reuse of `bots.view` (all four) or `sources.view`
    // (the other three).
    //
    // The refusal is the decided half: §6.4's six items are all source operations, and this page
    // carries TOKEN SPEND AND ESTIMATED PROVIDER COST — the side of the line §6.4 is explicitly kept
    // away from, which `providers.view` versus `providers.manage` already expresses.
    $org = Organization::factory()->create();

    $analyst = User::factory()->recycle($org)->orgRole(OrgRole::Analyst)->create();
    $manager = User::factory()->recycle($org)->orgRole(OrgRole::KnowledgeManager)->create();

    currentTest()->actingAs($analyst)->getJson(analyticsUrl($org))->assertOk();

    currentTest()->actingAs($manager)->getJson(analyticsUrl($org))
        ->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');
});

it('serves a suspended organization its own numbers', function (): void {
    // CHECK 5 IS DELIBERATELY ABSENT ON THIS ACTION. The endpoint writes nothing, and refusing it
    // would hide the very data an operator uses to understand why they were suspended. The two
    // configuration `show` actions make the same call for the same reason.
    $fixture = analyticsFixture();
    $fixture['org']->status = \App\Enums\OrganizationStatus::Suspended;
    $fixture['org']->save();

    currentTest()->actingAs($fixture['actor'])
        ->getJson(analyticsUrl($fixture['org']))
        ->assertOk();
});
