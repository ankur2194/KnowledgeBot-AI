<?php

declare(strict_types=1);

use App\Enums\BotAccessMode;
use App\Enums\BotAnswerMode;
use App\Enums\BotStatus;
use App\Enums\EvidenceThresholdScale;
use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Enums\Provider;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Bots\BotService;
use App\Support\Http\ListQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| The bot resource — index, store, show, update, destroy
|--------------------------------------------------------------------------
|
| The BEHAVIOUR half. The authorization and isolation half is
| tests/Security/BotEndpointAccessTest.php, and the two are separate files because they fail for
| different reasons and a reviewer reads them for different questions.
|
| EVERY FIXTURE HERE IS TWO ORGANIZATIONS with overlapping, distinguishable data, even in the
| behaviour file. A one-organization fixture passes every assertion below against code with the
| tenant filter deleted, so there is no "this test does not need isolation" case. Both organizations
| carry a bot with the SAME SLUG — which is legal, because a slug is unique PER ORGANIZATION, and
| which is exactly the shape that makes a missing tenant predicate return a plausible row where only
| the name betrays it.
|
| ABSENCE IS ALWAYS `expect(str_contains($body, $needle))->toBeFalse()` and NEVER
| `->not->toContain(...)`. Pest's toContain(mixed ...$needles) takes no message argument, so a label
| passed there becomes a second needle, and `not` treats any failure as success — the expression
| passes unconditionally. That exact shape has already hidden a real tenant-id leak in this repo.
*/

beforeEach(function (): void {
    // A CLIENT ADDRESS OF THIS TEST'S OWN. RefreshDatabase rolls back the database and nothing else,
    // and phpunit.xml points the cache at a real Valkey — so every rate-limiter bucket survives the
    // test that filled it and the next run of the suite.
    SpaSession::isolateRateLimits(currentTest());
});

/**
 * The slug BOTH organizations use, so a missing tenant predicate returns a row that looks exactly
 * right.
 */
const BOT_SHARED_SLUG = 'support-desk';

/**
 * Two organizations, one bot each on the SAME slug, and org A additionally holding a provider
 * connection with one model row so the model-selection paths are reachable.
 *
 * A HELPER OF THIS FILE'S OWN, with a name of its own. Pest declares test-file helpers at FILE
 * SCOPE, so a name another test file already uses is a redeclaration fatal in a full run and only
 * in a full run. `tenantPair()` is the shared harness and is used by the SECURITY file; this one
 * needs a provider catalog that `tenantPair()` deliberately does not build.
 *
 * @return array{
 *     orgA: Organization, orgB: Organization,
 *     ownerA: User, ownerB: User,
 *     botA: Bot, botB: Bot,
 *     connectionA: ProviderConnection, modelA: ProviderModelEntry,
 * }
 */
function botCrudFixture(): array
{
    $orgA = Organization::factory()->create(['name' => 'Bot CRUD Org ALPHA', 'slug' => 'bot-crud-alpha']);
    $orgB = Organization::factory()->create(['name' => 'Bot CRUD Org BRAVO', 'slug' => 'bot-crud-bravo']);

    $connectionA = ProviderConnection::factory()->recycle($orgA)
        ->provider(Provider::OpenAI)->create(['label' => 'ALPHA key']);

    return [
        'orgA' => $orgA,
        'orgB' => $orgB,
        'ownerA' => User::factory()->recycle($orgA)->orgRole(OrgRole::Owner)
            ->create(['email' => SpaSession::uniqueEmail('bot-crud-owner-alpha')]),
        'ownerB' => User::factory()->recycle($orgB)->orgRole(OrgRole::Owner)
            ->create(['email' => SpaSession::uniqueEmail('bot-crud-owner-bravo')]),

        // SAME SLUG IN BOTH ORGANIZATIONS. Legal — `bots_org_slug_unique` is per organization — and
        // the reason the duplicate-slug test below can prove the check is scoped rather than global.
        'botA' => Bot::factory()->recycle($orgA)
            ->create(['name' => 'ALPHA support bot', 'slug' => BOT_SHARED_SLUG]),
        'botB' => Bot::factory()->recycle($orgB)
            ->create(['name' => 'BRAVO support bot', 'slug' => BOT_SHARED_SLUG]),

        'connectionA' => $connectionA,
        'modelA' => ProviderModelEntry::factory()->recycle($orgA)->recycle($connectionA)
            ->supporting(['text'])
            ->create(['model' => 'gpt-5.1', 'display_name' => 'ALPHA chat row']),
    ];
}

// ── GET …/bots ───────────────────────────────────────────────────────────────────────────────────

it('emits the paginated envelope the admin console is already written against', function (): void {
    $fixture = botCrudFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $response = currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots",
        spaHeaders(),
    );

    // THE ENVELOPE, ASSERTED AS A SHAPE AND NOT ONLY AS A PATH. `meta` is a SIBLING OF THE
    // COLLECTION INSIDE `data`, and apps/web/src/lib/table/envelope.ts THROWS rather than degrading
    // when it cannot read exactly that — an unreadable envelope renders the error state, but a
    // `meta` moved one level up would render the FIRST-RUN EMPTY state to an administrator whose
    // organization has two hundred bots.
    $response->assertOk()
        ->assertJsonPath('data.meta.page', 1)
        ->assertJsonPath('data.meta.per_page', ListQuery::DEFAULT_PER_PAGE)
        ->assertJsonPath('data.meta.total', 1)
        ->assertJsonPath('data.meta.total_pages', 1)
        ->assertJsonPath('data.meta.sort', 'id')
        ->assertJsonPath('data.meta.dir', 'asc')
        ->assertJsonPath('data.meta.filter', null)
        ->assertJsonCount(1, 'data.bots');

    /** @var array<string, mixed> $body */
    $body = (array) $response->json();

    /** @var array<string, mixed> $data */
    $data = (array) ($body['data'] ?? []);

    // EXACTLY TWO KEYS INSIDE `data`, in the published order. A third key here is an undocumented
    // field the generated client silently drops; `meta` promoted out of `data` is the shape the
    // console cannot read at all.
    expect(array_keys($data))->toBe(['bots', 'meta'])
        ->and(array_keys($body))->toBe(['data']);

    // AND THE ISOLATION ASSERTION, in the behaviour file too. Org B holds a bot with the SAME SLUG,
    // so a missing organization predicate returns a row that looks perfectly plausible — only the
    // name and the id give it away.
    $raw = (string) $response->getContent();

    expect(str_contains($raw, $fixture['botB']->id))->toBeFalse('org B\'s bot id is in org A\'s list');
    expect(str_contains($raw, 'BRAVO'))->toBeFalse('org B\'s bot name is in org A\'s list');
});

it('pages, and reports a total that is not the page size', function (): void {
    $fixture = botCrudFixture();

    // Nine more, so org A holds ten and page two is a partial page. A fixture with exactly one page
    // cannot tell a working `LIMIT` from an endpoint that returns everything.
    Bot::factory()->recycle($fixture['orgA'])->count(9)->create();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $first = currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots?per_page=4",
        spaHeaders(),
    );

    $first->assertOk()
        ->assertJsonCount(4, 'data.bots')
        ->assertJsonPath('data.meta.total', 10)
        ->assertJsonPath('data.meta.total_pages', 3)
        ->assertJsonPath('data.meta.per_page', 4);

    $third = currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots?per_page=4&page=3",
        spaHeaders(),
    );

    $third->assertOk()
        ->assertJsonCount(2, 'data.bots')
        ->assertJsonPath('data.meta.page', 3);

    // NO ROW APPEARS ON TWO PAGES. This is what the `id` tie-break exists for: offset pagination
    // over a non-total order is free to repeat a row, and the duplicate is invisible until somebody
    // counts.
    $ids = array_merge(
        array_column((array) $first->json('data.bots'), 'id'),
        array_column((array) $third->json('data.bots'), 'id'),
    );

    expect($ids)->toHaveCount(count(array_unique($ids)));
});

it('sorts by a column in the closed set, in both directions, and refuses one outside it', function (): void {
    $fixture = botCrudFixture();

    Bot::factory()->recycle($fixture['orgA'])->create(['name' => 'AAA first alphabetically', 'slug' => 'aaa-bot']);
    Bot::factory()->recycle($fixture['orgA'])->create(['name' => 'ZZZ last alphabetically', 'slug' => 'zzz-bot']);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $ascending = currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots?sort=name&dir=asc",
        spaHeaders(),
    );

    $ascending->assertOk()->assertJsonPath('data.meta.sort', 'name')->assertJsonPath('data.meta.dir', 'asc');

    expect(array_column((array) $ascending->json('data.bots'), 'name'))
        ->toBe(['AAA first alphabetically', 'ALPHA support bot', 'ZZZ last alphabetically']);

    $descending = currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots?sort=name&dir=desc",
        spaHeaders(),
    );

    expect(array_column((array) $descending->json('data.bots'), 'name'))
        ->toBe(['ZZZ last alphabetically', 'ALPHA support bot', 'AAA first alphabetically']);

    // A COLUMN OUTSIDE THE CLOSED SET IS A 422 AND NOT A SILENT FALLBACK. The value reaches an
    // ORDER BY, so an open set is a caller choosing which index the query uses at best. Note the
    // column chosen: `system_instruction` is a real column of this table, so this refuses an
    // EXISTING column that is merely not sortable rather than a nonsense string a typo check would
    // also catch.
    currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots?sort=system_instruction",
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonStructure(['errors' => ['sort']]);
});

it('filters on name and slug together, without losing the tenant predicate', function (): void {
    $fixture = botCrudFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // THE TERM MATCHES A BOT IN **BOTH** ORGANIZATIONS, which is the whole design of this test. The
    // filter is a grouped OR, and `AND` binds tighter than `OR` in SQL — so a repository that wrote
    // `where(org)->orWhere(name)->orWhere(slug)` without the closure group would produce
    // `organization_id = ? AND name ILIKE ? OR slug ILIKE ?`, whose second disjunct carries NO
    // TENANT PREDICATE. That leak looks like a formatting choice in review and is invisible to a
    // filter test whose term matches only one organization.
    $response = currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots?filter=support",
        spaHeaders(),
    );

    $response->assertOk()
        ->assertJsonCount(1, 'data.bots')
        ->assertJsonPath('data.bots.0.id', $fixture['botA']->id)
        ->assertJsonPath('data.meta.total', 1)
        ->assertJsonPath('data.meta.filter', 'support');

    expect(str_contains((string) $response->getContent(), $fixture['botB']->id))
        ->toBeFalse('the filter returned another organization\'s bot');

    // THE SLUG ARM, on its own. `alpha-only` is in no bot's NAME, so a filter that searched only
    // `name` would return nothing here and the second arm would be untested.
    Bot::factory()->recycle($fixture['orgA'])->create(['name' => 'Unrelated', 'slug' => 'alpha-only']);

    currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots?filter=alpha-only",
        spaHeaders(),
    )
        ->assertOk()
        ->assertJsonCount(1, 'data.bots')
        ->assertJsonPath('data.bots.0.slug', 'alpha-only');

    // A LIKE METACHARACTER IS A LITERAL, NOT A WILDCARD. `%` unescaped would match every bot, which
    // is a filter that silently returns the whole list for a search that found nothing.
    currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots?filter=%25",
        spaHeaders(),
    )
        ->assertOk()
        ->assertJsonCount(0, 'data.bots')
        ->assertJsonPath('data.meta.total', 0)
        // ONE, NOT ZERO. Page one exists and is empty; a client that computed a page count from
        // `total` would disagree with the server on an empty list.
        ->assertJsonPath('data.meta.total_pages', 1);
});

it('refuses a page size above the platform ceiling rather than clamping it silently', function (): void {
    $fixture = botCrudFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // A 422 AND NOT A CLAMP, on the HTTP path: there is a field to key the error on, and a caller
    // that asked for 500 rows and silently got 100 would page through a set it thinks is five times
    // shorter than it is. The silent clamp in `ListQuery::fromValidated()` is for the service and
    // job callers that never ran a FormRequest.
    currentTest()->getJson(
        '/api/v1/organizations/'.$fixture['orgA']->id.'/bots?per_page='.(ListQuery::MAX_PER_PAGE + 1),
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonStructure(['errors' => ['per_page']]);
});

// ── POST …/bots ──────────────────────────────────────────────────────────────────────────────────

it('creates a draft bot, mints its public identifier, and applies the specification defaults', function (): void {
    $fixture = botCrudFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $response = currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots",
        [
            'name' => 'ALPHA new bot',
            'slug' => 'alpha-new-bot',
            'welcome_message' => 'Hello from ALPHA.',
            // OVER-POSTED, AND ALL THREE MUST BE IGNORED RATHER THAN HONOURED. `organization_id` is
            // the tenant key; `public_bot_id` is the token every widget snippet carries;
            // `retrieval_configuration_version` is the identity the §21.5 gate replays against.
            // None of the three is in any rule set, so `Model::shouldBeStrict()` never sees them —
            // the FormRequest drops them before a DTO could carry one.
            'organization_id' => $fixture['orgB']->id,
            'public_bot_id' => 'pub_attacker_chosen',
            'retrieval_configuration_version' => 99,
            'status' => 'published',
        ],
        spaHeaders(),
    );

    $response->assertStatus(201)
        ->assertJsonPath('data.name', 'ALPHA new bot')
        ->assertJsonPath('data.slug', 'alpha-new-bot')
        // A BOT IS CREATED `draft`, ALWAYS. The posted `status` is not in StoreBotRequest's rules
        // and NewBot has no member for it, so publishing stays a transition with a guard.
        ->assertJsonPath('data.status', BotStatus::Draft->value)
        ->assertJsonPath('data.access_mode', BotAccessMode::Private->value)
        ->assertJsonPath('data.answer_mode', BotAnswerMode::Strict->value)
        ->assertJsonPath('data.dense_top_k', 20)
        ->assertJsonPath('data.sparse_top_k', 20)
        ->assertJsonPath('data.rerank_candidates', 20)
        ->assertJsonPath('data.rerank_retain', 6)
        ->assertJsonPath('data.retrieval_configuration_version', 1)
        ->assertJsonPath('data.allow_general_answers', false)
        ->assertJsonPath('data.collect_end_user_data', false)
        // NULL, WITH NO PLATFORM DEFAULT. `0.30` is a valid float on every scale, so a default here
        // would move only the refusal rate, only in aggregate, and fail no test.
        ->assertJsonPath('data.evidence_threshold', null)
        ->assertJsonPath('data.evidence_threshold_scale', null)
        ->assertJsonPath('data.provider_connection_id', null)
        ->assertJsonPath('data.theme', []);

    $id = $response->json('data.id');
    $publicId = $response->json('data.public_bot_id');

    assert(is_string($id) && is_string($publicId));

    expect($publicId)->toStartWith('pub_')
        ->and($publicId)->not->toBe('pub_attacker_chosen')
        // THE GRAMMAR `bots_public_bot_id_shape` CHECKS, and the same one
        // apps/web's stylesheet route refuses to forward anything else for.
        ->and((bool) preg_match('/^[A-Za-z0-9_-]{1,64}$/', $publicId))->toBeTrue();

    $row = Bot::query()->withoutGlobalScopes()->findOrFail($id);

    // THE TENANT KEY CAME FROM THE URL AND THE SESSION, NOT FROM THE BODY. Over-posting it is an
    // authorization bug with a 200 response, and this is the assertion that would catch it.
    expect($row->organization_id)->toBe($fixture['orgA']->id)
        ->and($row->retrieval_configuration_version)->toBe(1)
        ->and($row->theme)->toBe([]);

    assertDatabaseHas('audit_logs', [
        'operation' => AuditLogger::BOT_CREATED,
        'organization_id' => $fixture['orgA']->id,
        'actor_id' => $fixture['ownerA']->id,
        'subject_type' => Bot::class,
        'subject_id' => $id,
    ]);

    $audit = AuditLog::query()
        ->where('operation', '=', AuditLogger::BOT_CREATED)
        ->where('subject_id', '=', $id)
        ->firstOrFail();

    /** @var array<string, mixed> $details */
    $details = (array) $audit->details;

    // THE ALLOW-LIST HELD IN BOTH DIRECTIONS. `welcome_message` is prose that decides nothing and
    // is absent; `public_bot_id` is a token-shaped identifier and is absent; `name` and `status` are
    // what makes the row readable after a hard delete and are present.
    expect($details)->toHaveKeys(['name', 'slug', 'status', 'access_mode'])
        ->and($details['name'])->toBe('ALPHA new bot')
        ->and($details['status'])->toBe('draft')
        ->and($details)->not->toHaveKey('welcome_message')
        ->and($details)->not->toHaveKey('public_bot_id')
        ->and($details)->not->toHaveKey('system_instruction');
});

it('refuses a slug this organization already uses, and permits one another organization uses', function (): void {
    $fixture = botCrudFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // THE COLLISION, INSIDE ONE ORGANIZATION: a 422 keyed on the field the form renders, not a 500
    // from SQLSTATE 23505 and not a 409 banner about a request that is plainly about one field.
    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots",
        ['name' => 'Another ALPHA bot', 'slug' => BOT_SHARED_SLUG],
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonStructure(['errors' => ['slug']]);

    // AND THE POSITIVE CONTROL THAT MAKES THE CHECK'S SCOPE VISIBLE: org B already holds this slug
    // and org A may still take it. An unscoped `unique:bots,slug` would refuse it, which is an
    // existence oracle over the whole platform rendered as a validation error on a form — this
    // assertion is what would catch that rule being reached for.
    Bot::query()->withoutGlobalScopes()->whereKey($fixture['botA']->id)->update(['slug' => 'alpha-renamed']);

    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots",
        ['name' => 'Another ALPHA bot', 'slug' => BOT_SHARED_SLUG],
        spaHeaders(),
    )->assertStatus(201);
});

it('refuses a model that belongs to another connection, and a model with no connection', function (): void {
    $fixture = botCrudFixture();

    // A SECOND CONNECTION IN THE SAME ORGANIZATION, with no catalog row of its own. This is the
    // pairing NOTHING IN THE DATABASE refuses: `bots_connection_same_org` and `bots_model_same_org`
    // check each reference against the ORGANIZATION and neither checks them against each other, so
    // a bot naming connection B with a model registered under connection A is a row PostgreSQL will
    // happily store and the data plane cannot interpret — the credential would come from one
    // account and the model from another.
    $second = ProviderConnection::factory()->recycle($fixture['orgA'])
        ->provider(Provider::Anthropic)->create(['label' => 'ALPHA second key']);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots",
        [
            'name' => 'ALPHA mismatched',
            'slug' => 'alpha-mismatched',
            'provider_connection_id' => $second->id,
            'provider_model_id' => $fixture['modelA']->id,
        ],
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['provider_model_id']]);

    // A MODEL WITH NO CONNECTION names no credential — a `provider_models` row reaches one only
    // through its parent — and it is refused declaratively, so the generated client is told.
    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots",
        [
            'name' => 'ALPHA orphan model',
            'slug' => 'alpha-orphan-model',
            'provider_model_id' => $fixture['modelA']->id,
        ],
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['provider_connection_id']]);

    // AND THE POSITIVE CONTROL: the correct pair is accepted, so the two refusals above are about
    // the pairing rather than about the endpoint rejecting model selection entirely.
    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots",
        [
            'name' => 'ALPHA configured',
            'slug' => 'alpha-configured',
            'provider_connection_id' => $fixture['connectionA']->id,
            'provider_model_id' => $fixture['modelA']->id,
        ],
        spaHeaders(),
    )
        ->assertStatus(201)
        ->assertJsonPath('data.provider_model_id', $fixture['modelA']->id);

    assertDatabaseMissing('bots', ['slug' => 'alpha-mismatched']);
    assertDatabaseMissing('bots', ['slug' => 'alpha-orphan-model']);
});

it('accepts a theme the renderer will honour and refuses every one it would drop', function (string $theme, bool $accepted): void {
    $fixture = botCrudFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    /** @var array<string, string> $decoded */
    $decoded = (array) json_decode($theme, true, flags: JSON_THROW_ON_ERROR);

    $response = currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots",
        ['name' => 'ALPHA themed', 'slug' => 'alpha-themed', 'theme' => $decoded],
        spaHeaders(),
    );

    $response->assertStatus($accepted ? 201 : 422);

    if ($accepted) {
        $response->assertJsonPath('data.theme', $decoded);
    } else {
        $response->assertJsonPath('error_class', 'validation');
    }
})->with([
    // THE PLATFORM ACCENT ITSELF. Its `oklch(0.525 0.235 264)` spelling is the shape the pattern
    // this rule replaced on the TypeScript side used to REJECT, because it required a decimal point
    // in every component — a failure that dropped our own defaults and did it silently.
    'the platform accent' => ['{"primary":"oklch(0.525 0.235 264)"}', true],
    // NO LEADING ZERO AND AN INTEGER COMPONENT, both legal CSS and both spellings a stricter-looking
    // pattern refuses.
    'a leading-dot spelling' => ['{"accent":"oklch(.96 .01 264)"}', true],
    'an integer lightness' => ['{"primary":"oklch(1 0 0)"}', true],
    'a published radius' => ['{"radius":"0.625rem"}', true],
    'all three keys at once' => ['{"primary":"oklch(0.525 0.235 264)","accent":"oklch(0.96 0.01 264)","radius":"1rem"}', true],
    // THE CONTRAST REFUSAL, which is the half a grammar alone cannot carry: this is a perfectly
    // legal colour that NEITHER platform text colour clears 4.5:1 against, so the renderer drops it
    // and serves the platform accent. Accepting it here would tell the customer their brand colour
    // was saved and then quietly ignore it — the standing FLAG in apps/web/src/lib/theme.ts.
    'a mid-lightness colour no text reads on' => ['{"primary":"oklch(0.58 0.2 264)"}', false],
    'the same hole on accent' => ['{"accent":"oklch(0.58 0.2 264)"}', false],
    'a hex colour' => ['{"primary":"#4f46e5"}', false],
    'an rgb() colour' => ['{"primary":"rgb(79 70 229)"}', false],
    'a lightness out of range' => ['{"primary":"oklch(1.5 0.2 264)"}', false],
    'a hue out of range' => ['{"primary":"oklch(0.5 0.2 400)"}', false],
    'a chroma beyond any display primary' => ['{"primary":"oklch(0.5 0.9 264)"}', false],
    // CSS INJECTION, which is what the whole closed grammar exists to make unrepresentable.
    'a closing brace and a rule of its own' => ['{"primary":"oklch(0.5 0.1 264)} body{background:url(https://evil.example/x)"}', false],
    'a radius outside the published set' => ['{"radius":"13px"}', false],
    // THE KEY SET IS CLOSED. A fourth key is a value stored forever and rendered nowhere.
    'an undeclared key' => ['{"shadow":"0 0 0 red"}', false],
]);

it('refuses to write a state the table would not hold', function (array $payload, string $field): void {
    $fixture = botCrudFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots",
        array_merge(['name' => 'ALPHA invalid', 'slug' => 'alpha-invalid'], $payload),
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonStructure(['errors' => [$field]]);
})->with([
    // `bots_evidence_threshold_paired` CHECKs num_nonnulls(...) <> 1. Either half alone is
    // uninterpretable — a number with no scale is three different instructions wearing one float.
    'a threshold with no scale' => [['evidence_threshold' => 0.3], 'evidence_threshold_scale'],
    'a scale with no threshold' => [['evidence_threshold_scale' => 'sigmoid'], 'evidence_threshold'],
    // `bots_evidence_threshold_range`: the one half of the portability problem a constraint can
    // catch. 1.7 is refused on a bounded scale and accepted on a logit, correctly in both
    // directions — the logit half is asserted in the accepting test below.
    'a bounded scale out of range' => [
        ['evidence_threshold' => 1.7, 'evidence_threshold_scale' => 'sigmoid'],
        'evidence_threshold',
    ],
    // `uncalibrated` is a real member of the DATA PLANE's RerankScale and deliberately not a case
    // here: it means "no characterization exists", so a threshold carrying it is a stored
    // contradiction.
    'the uncalibrated scale' => [
        ['evidence_threshold' => 0.3, 'evidence_threshold_scale' => 'uncalibrated'],
        'evidence_threshold_scale',
    ],
    // `bots_consent_text_present_when_collecting`: collecting end-user data without telling anyone
    // what for is not a state this table will hold.
    'collection with no disclosure' => [['collect_end_user_data' => true], 'consent_text'],
    // docs/07 §12.7-12.12's own bands. A value outside them is not a tuning choice, it is a typo
    // that changes what the §21.5 regression gate is comparing.
    'a rerank retain above the band' => [['rerank_retain' => 11], 'rerank_retain'],
    'a rerank candidate count below the band' => [['rerank_candidates' => 19], 'rerank_candidates'],
    'a dense depth of zero' => [['dense_top_k' => 0], 'dense_top_k'],
    // A rate limit of zero is not a limit, it is a bot that answers nobody — and it is a plausible
    // typo for "no limit", which is spelled null.
    'a rate limit of zero' => [['rate_limit_per_minute' => 0], 'rate_limit_per_minute'],
    'a slug with capitals' => [['slug' => 'Support-Desk'], 'slug'],
    'a slug ending in a hyphen' => [['slug' => 'support-'], 'slug'],
    'a blank name' => [['name' => '   '], 'name'],
]);

it('accepts an out-of-unit-range threshold on the logit scale, which is the other direction of the same rule', function (): void {
    $fixture = botCrudFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // 1.7 IS REFUSED ON `sigmoid` AND ACCEPTED HERE, and both are correct: NVIDIA's ranking models
    // return an unbounded signed logit whose published example ranks 0.226, -1.17 and -1.52. A
    // range rule that did not read the scale would be wrong in one of the two directions, and the
    // one it would be wrong in is the one that silently refuses every answer.
    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots",
        [
            'name' => 'ALPHA logit',
            'slug' => 'alpha-logit',
            'evidence_threshold' => 1.7,
            'evidence_threshold_scale' => EvidenceThresholdScale::Logit->value,
        ],
        spaHeaders(),
    )
        ->assertStatus(201)
        ->assertJsonPath('data.evidence_threshold', 1.7)
        ->assertJsonPath('data.evidence_threshold_scale', 'logit');
});

it('refuses to create a bot in a suspended organization', function (): void {
    $fixture = botCrudFixture();

    $fixture['orgA']->forceFill(['status' => OrganizationStatus::Suspended])->save();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // CHECK 5, ENTITY STATUS — the check `Gate::authorize()` cannot make, because a policy
    // authorizes a caller and not a state. A 409 carrying its own message: bootstrap/app.php
    // preserves an unclassified 4xx's status AND its message, so the actionable sentence reaches
    // the client verbatim as `internal_dependency` / retryable false.
    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots",
        ['name' => 'ALPHA suspended', 'slug' => 'alpha-suspended'],
        spaHeaders(),
    )
        ->assertStatus(409)
        ->assertJsonPath('error_class', 'internal_dependency')
        ->assertJsonPath('retryable', false);

    assertDatabaseMissing('bots', ['slug' => 'alpha-suspended']);

    // AND READING STILL WORKS, deliberately: seeing which bots exist is exactly what a suspended
    // organization's operator needs to do while working out why everything stopped.
    currentTest()->getJson("/api/v1/organizations/{$fixture['orgA']->id}/bots", spaHeaders())
        ->assertOk();
});

it('resolves the instruction projection ONCE for the page, not once per row', function (): void {
    // THE COMMENT IN `BotController` SAYS "ONCE PER REQUEST"; THIS IS WHAT MAKES THAT ENFORCEABLE.
    // `BotResource` nulls the two instruction fields for a caller without `bots.manage`, and the
    // obvious way to write that — `$request->user()->can('update', $bot)` inside `toArray()` — is
    // correct and costs one `organization_users` read PER ROW, because `OrgScopedPolicy::permit()`
    // resolves membership per check and is deliberately never memoized across organizations. A
    // correctness test cannot see the difference: both spellings return the same body.
    $fixture = botCrudFixture();

    // Twenty-four more, so one page carries twenty-five rows. A single-row page cannot distinguish
    // "once" from "once per row" — which is the entire failure mode this asserts against.
    Bot::factory()->recycle($fixture['orgA'])->count(24)->create();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $membershipReads = 0;

    DB::listen(function (QueryExecuted $query) use (&$membershipReads): void {
        if (str_contains($query->sql, 'organization_users')) {
            $membershipReads++;
        }
    });

    currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots?per_page=25",
        spaHeaders(),
    )
        ->assertOk()
        ->assertJsonCount(25, 'data.bots');

    // THE BOUND IS DELIBERATELY LOOSE AND STILL DECISIVE. Three reads are expected on this path —
    // `org.member` re-reading current membership, `Gate::authorize('viewBots')` and
    // `Gate::allows('manageBots')` — and the bound is set well above that so an added middleware or
    // an extra authorization call is not a false failure. A per-row projection is twenty-five plus
    // those three, which is nowhere near it: this test discriminates the two shapes, not a
    // particular number.
    expect($membershipReads)->toBeLessThanOrEqual(8);
});

// ── GET …/bots/{bot} ─────────────────────────────────────────────────────────────────────────────

it('renders one bot with its whole configuration and nothing of the parent credential', function (): void {
    $fixture = botCrudFixture();

    $bot = $fixture['botA'];

    Bot::query()->withoutGlobalScopes()->whereKey($bot->id)->update([
        'provider_connection_id' => $fixture['connectionA']->id,
        'provider_model_id' => $fixture['modelA']->id,
        'system_instruction' => 'You answer only from the handbook.',
    ]);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $response = currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$bot->id}",
        spaHeaders(),
    );

    $response->assertOk()
        ->assertJsonPath('data.id', $bot->id)
        ->assertJsonPath('data.provider_connection_id', $fixture['connectionA']->id)
        // THE ADMIN SURFACE RENDERS THE SYSTEM INSTRUCTION, and it is the field that makes this
        // resource authenticated-only: the hosted-chat, widget and stylesheet surfaces publish
        // their own, much smaller, shape and must never carry it.
        ->assertJsonPath('data.system_instruction', 'You answer only from the handbook.');

    $body = (string) $response->getContent();

    // NO CREDENTIAL, IN ANY FORM. The positive control is the factory: it seals FIXTURE_CREDENTIAL
    // for real, so the plaintext genuinely exists in the referenced connection's row.
    expect(str_contains($body, \Database\Factories\ProviderConnectionFactory::FIXTURE_CREDENTIAL))
        ->toBeFalse('the plaintext provider credential reached a bot response');
    expect(str_contains($body, 'last_four'))->toBeFalse();
    expect(str_contains($body, 'masked_key'))->toBeFalse();
    // AND NOT THE OWNERSHIP COLUMN EITHER: the caller asked through a URL that already named the
    // organization, so echoing it would put a tenant identifier in every cached response body.
    expect(str_contains($body, '"organization_id"'))->toBeFalse();
});

// ── PATCH …/bots/{bot} ───────────────────────────────────────────────────────────────────────────

it('leaves an unnamed field alone and clears one sent as null', function (): void {
    $fixture = botCrudFixture();

    $bot = $fixture['botA'];

    Bot::query()->withoutGlobalScopes()->whereKey($bot->id)->update([
        'description' => 'The original description.',
        'welcome_message' => 'The original greeting.',
    ]);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // THE WHOLE OF PATCH SEMANTICS IN ONE REQUEST: `name` is changed, `welcome_message` is CLEARED,
    // and `description` is not mentioned. An implementation that collapsed "absent" and "null" into
    // one answer would either blank the description or refuse to clear the greeting, and both are
    // silent.
    currentTest()->patchJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$bot->id}",
        ['name' => 'ALPHA renamed', 'welcome_message' => null],
        spaHeaders(),
    )
        ->assertOk()
        ->assertJsonPath('data.name', 'ALPHA renamed')
        ->assertJsonPath('data.welcome_message', null)
        ->assertJsonPath('data.description', 'The original description.')
        ->assertJsonPath('data.slug', BOT_SHARED_SLUG);

    // `sometimes|required` MEANS "IF YOU SENT IT, IT MUST NOT BE EMPTY" AND NOT "YOU MUST SEND IT".
    // `name` is NOT NULL in the schema, so clearing it is refused — while omitting it is what the
    // request above just did successfully.
    currentTest()->patchJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$bot->id}",
        ['name' => null],
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['name']]);

    // A BODY THAT NAMES NOTHING IS REFUSED, with a real per-field map. Accepting it would return
    // 200 and write a `bot.updated` audit row describing an edit that did not happen — the trail
    // wrong in the one direction nobody checks it in.
    currentTest()->patchJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$bot->id}",
        [],
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonStructure(['errors' => ['bot']]);
});

it('moves the retrieval configuration version only when a knob\'s value actually changes', function (): void {
    $fixture = botCrudFixture();

    $bot = $fixture['botA'];
    $url = "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$bot->id}";

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // A NON-KNOB EDIT DOES NOT MOVE IT. The voice fields and the model selection are deliberately
    // outside RETRIEVAL_KNOBS: they change what the answer sounds like and who is billed for it,
    // not which twenty candidates come back.
    currentTest()->patchJson($url, ['name' => 'ALPHA renamed'], spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.retrieval_configuration_version', 1);

    // A KNOB RE-SENT AT ITS CURRENT VALUE DOES NOT MOVE IT EITHER, and this is the assertion that
    // matters: a console submitting its whole form on every save names every knob on every request,
    // so a presence test would mint a new configuration identity for a configuration that did not
    // move — invalidating every cached answer and making the §21.5 gate compare traces that differ
    // by a number nobody changed.
    currentTest()->patchJson($url, ['dense_top_k' => 20], spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.retrieval_configuration_version', 1);

    // AND A REAL CHANGE DOES.
    currentTest()->patchJson($url, ['dense_top_k' => 40], spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.dense_top_k', 40)
        ->assertJsonPath('data.retrieval_configuration_version', 2);

    // INCLUDING AN ENUM KNOB, which is the case a strict comparison gets wrong when the FormRequest
    // hands the repository a raw string instead of the cast the model produces: `'strict'` is never
    // identical to BotAnswerMode::Strict, so every save would bump.
    currentTest()->patchJson($url, ['answer_mode' => BotAnswerMode::Strict->value], spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.retrieval_configuration_version', 2);

    currentTest()->patchJson($url, ['answer_mode' => BotAnswerMode::RagFirst->value], spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.retrieval_configuration_version', 3);
});

it('replaces the theme wholesale rather than merging it', function (): void {
    $fixture = botCrudFixture();

    $bot = $fixture['botA'];
    $url = "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$bot->id}";

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->patchJson(
        $url,
        ['theme' => ['primary' => 'oklch(0.525 0.235 264)', 'radius' => '1rem']],
        spaHeaders(),
    )
        ->assertOk()
        ->assertJsonPath('data.theme.primary', 'oklch(0.525 0.235 264)')
        ->assertJsonPath('data.theme.radius', '1rem');

    // WHOLESALE. A merge would need a second vocabulary for "delete this key" and would make the
    // stored value depend on every previous request rather than on this one — for a column that is
    // a configuration snapshot written once and read whole.
    $response = currentTest()->patchJson($url, ['theme' => ['radius' => '0rem']], spaHeaders());

    $response->assertOk()->assertJsonPath('data.theme', ['radius' => '0rem']);

    // AND `{}` RESTORES THE PLATFORM THEME, encoded as an OBJECT rather than an array. PHP cannot
    // tell an empty array from an empty map, so the built-in `array` cast would serialize every
    // unthemed bot as `[]` and every themed one as `{}` — one field, two JSON types, decided by
    // whether anybody has themed the bot.
    $cleared = currentTest()->patchJson($url, ['theme' => []], spaHeaders());

    $cleared->assertOk();

    expect(str_contains((string) $cleared->getContent(), '"theme":{}'))
        ->toBeTrue('an unthemed bot serialized as a JSON array rather than an object');
});

it('refuses `status` on the PATCH and points at the transition endpoint', function (): void {
    // A 422 AND NOT A SILENT DROP, which is the whole reason `UpdateBotRequest` declares the field
    // `prohibited` rather than simply omitting the rule. An omitted rule means `validated()`
    // discards the key, so a client written against the old contract would publish a bot, receive a
    // 200 with `status: draft` in the body, and have to notice the discrepancy itself.
    $fixture = botCrudFixture();

    $bot = $fixture['botA'];

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->patchJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$bot->id}",
        ['name' => 'ALPHA renamed', 'status' => BotStatus::Published->value],
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonValidationErrors('status');

    // AND THE REST OF THE BODY IS NOT APPLIED EITHER. Validation fails whole, so a caller cannot
    // half-succeed: the rename did not happen and neither did the transition.
    assertDatabaseHas('bots', [
        'id' => $bot->id,
        'name' => 'ALPHA support bot',
        'status' => BotStatus::Draft->value,
    ]);
});

it('refuses to publish a bot that could not answer, and refuses every edit to an archived one', function (): void {
    $fixture = botCrudFixture();

    $bot = $fixture['botA'];
    $url = "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$bot->id}";
    $statusUrl = $url.'/status';

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // NO MODEL. A published bot with none does not fail at publish time — it fails at the first
    // end-user question, as a resolution error in the data plane, on a customer's website, days
    // after the action that caused it.
    currentTest()->putJson($statusUrl, ['status' => BotStatus::Published->value], spaHeaders())
        ->assertStatus(409)
        ->assertJsonPath('error_class', 'internal_dependency')
        ->assertJsonPath('message', BotService::PUBLISH_NEEDS_MODEL);

    Bot::query()->withoutGlobalScopes()->whereKey($bot->id)->update([
        'provider_connection_id' => $fixture['connectionA']->id,
        'provider_model_id' => $fixture['modelA']->id,
        'answer_mode' => BotAnswerMode::RagFirst->value,
    ]);

    // RAG-FIRST WITH THE ESCAPE HATCH CLOSED. The pair is a contradiction once published: the bot
    // answers exactly like `strict` while its configuration screen says otherwise, and the only
    // symptom is a refusal rate nobody can explain from the console.
    currentTest()->putJson($statusUrl, ['status' => BotStatus::Published->value], spaHeaders())
        ->assertStatus(409)
        ->assertJsonPath('message', BotService::PUBLISH_RAG_FIRST_NEEDS_ESCAPE_HATCH);

    // TWO REQUESTS, NOT ONE, AND THAT IS THE COST OF THE SPLIT — stated here rather than left to be
    // rediscovered. The configuration and the transition are separate endpoints now, so "open the
    // escape hatch AND publish" cannot be one atomic request. What it does not cost is the property
    // that matters: the guard still reads the state the write LEAVES the bot in, which is what the
    // next block proves against a request that does not mention `status` at all.
    currentTest()->patchJson($url, ['allow_general_answers' => true], spaHeaders())->assertOk();

    currentTest()->putJson($statusUrl, ['status' => BotStatus::Published->value], spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    // A NO-OP TRANSITION IS REFUSED. Re-publishing an already-published bot would return 200 and
    // write a `bot.updated` audit row describing a change that did not happen — the trail wrong in
    // the one direction nobody checks it in. It costs strict PUT idempotency deliberately.
    currentTest()->putJson($statusUrl, ['status' => BotStatus::Published->value], spaHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonValidationErrors('status');

    // AND THE CASE A TRANSITION-ONLY GUARD WOULD MISS ENTIRELY: clearing the model on an ALREADY
    // published bot is exactly as dangerous as publishing a model-less draft, and the request goes
    // to the PATCH and does not mention `status` at all. This is the assertion that proves the
    // guard runs on the RESULTING STATE rather than on a transition, and it is the reason the
    // guard did not move to the status endpoint with the field.
    currentTest()->patchJson(
        $url,
        ['provider_connection_id' => null, 'provider_model_id' => null],
        spaHeaders(),
    )
        ->assertStatus(409)
        ->assertJsonPath('message', BotService::PUBLISH_NEEDS_MODEL);

    // ARCHIVED IS TERMINAL AND READ-ONLY, INCLUDING ITS STATUS. An archived bot's configuration is
    // the record of what answered the conversations it produced; editing it rewrites the
    // explanation of those conversations without changing them.
    currentTest()->putJson($statusUrl, ['status' => BotStatus::Archived->value], spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.status', 'archived');

    currentTest()->patchJson($url, ['name' => 'ALPHA resurrected'], spaHeaders())
        ->assertStatus(409)
        ->assertJsonPath('message', BotService::ARCHIVED_IS_READ_ONLY);

    // AND THERE IS NO UN-ARCHIVE, on the transition endpoint either. The refusal comes from
    // `BotService::update()`, which both paths go through, so the archived rule cannot hold on one
    // and not the other.
    currentTest()->putJson($statusUrl, ['status' => BotStatus::Paused->value], spaHeaders())
        ->assertStatus(409)
        ->assertJsonPath('message', BotService::ARCHIVED_IS_READ_ONLY);
});

// ── DELETE …/bots/{bot} ──────────────────────────────────────────────────────────────────────────

it('deletes a bot with its children, and 404s the second attempt', function (): void {
    $fixture = botCrudFixture();

    $bot = Bot::factory()->recycle($fixture['orgA'])
        ->withOrigins(['https://alpha.example.com'])
        ->create(['name' => 'ALPHA doomed', 'slug' => 'alpha-doomed']);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // POSITIVE CONTROL: the child really is there. The three child tables reference
    // `bots (organization_id, id)` with ON DELETE RESTRICT, so a delete that did NOT remove them
    // first raises SQLSTATE 23503 and renders as a 500 — this fixture is what makes that path real
    // rather than hypothetical.
    assertDatabaseHas('bot_domains', ['bot_id' => $bot->id, 'origin' => 'https://alpha.example.com']);

    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$bot->id}",
        [],
        spaHeaders(),
    )
        ->assertOk()
        ->assertExactJson(['data' => ['acknowledged' => true]]);

    assertDatabaseMissing('bots', ['id' => $bot->id]);
    assertDatabaseMissing('bot_domains', ['bot_id' => $bot->id]);

    $audit = AuditLog::query()
        ->where('operation', '=', AuditLogger::BOT_DELETED)
        ->where('subject_id', '=', $bot->id)
        ->firstOrFail();

    /** @var array<string, mixed> $details */
    $details = (array) $audit->details;

    // THE ONLY SURVIVING DESCRIPTION. `subject_id` now points at a ULID no table resolves, so
    // without the echoed fields the trail says a bot was removed without being able to say which.
    expect($details['name'])->toBe('ALPHA doomed')
        ->and($details['slug'])->toBe('alpha-doomed');

    // NOT IDEMPOTENT, ON PURPOSE: a 200 for the second delete would claim this actor performed a
    // deletion the trail does not record. It 404s at BINDING time, so the body is byte-identical to
    // the one a path with no route produces.
    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$bot->id}",
        [],
        spaHeaders(),
    )->assertStatus(404);

    // AND THE ORIGINAL BOT SURVIVES, so the delete's predicate really did name one row.
    assertDatabaseHas('bots', ['id' => $fixture['botA']->id]);
});
