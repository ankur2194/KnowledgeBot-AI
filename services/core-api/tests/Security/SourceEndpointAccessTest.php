<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Enums\SourceState;
use App\Models\KnowledgeSource;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| The knowledge-source ENDPOINTS — who may reach them, and what a denial reveals (§22.5)
|--------------------------------------------------------------------------
|
| WHY THIS SURFACE IS DIFFERENT FROM THE BOT ONE. `source_status` and `source_version_id` are two of
| the four mandatory Qdrant filter terms (kb-tenancy-isolation NN3) and BOTH are resolved from these
| tables. A source reached out of the wrong organization therefore does not produce an error — it
| produces a correct-looking answer, at normal latency, with a well-formed citation, pointing at a
| document the organization never uploaded. There is no later layer that catches it: the data plane
| builds its filter from what Laravel resolved.
|
| The BEHAVIOUR half is tests/Feature/SourceCrudTest.php and the callback half is
| tests/Feature/IngestionCallbackTest.php. Three files because they fail for different reasons: a
| red test here is a tenant or privilege boundary, a red test there is a bug in an endpoint.
|
| `Http::fake()` APPEARS NOWHERE, and cannot: pest-testing bans it under tests/Security outright.
| `Queue::fake()` is not the same thing and is used deliberately — the create and reprocess paths
| DISPATCH a job whose handler would call the AI service, and faking the QUEUE stops the dispatch
| rather than stubbing a response. Nothing under test here reaches the seam.
|
| EVERY FIXTURE IS `tenantPair()`, THE SHARED HARNESS, and every absence assertion carries a
| POSITIVE CONTROL asserted first. A one-organization fixture passes every test below against code
| with no tenant filter at all.
|
| ABSENCE IS ALWAYS `expect(str_contains($body, $needle))->toBeFalse()` and NEVER
| `->not->toContain(...)`. Pest's `toContain(mixed ...$needles)` takes no message argument, so a
| label passed there becomes a second needle and `not` treats any failure as success — the
| expression passes unconditionally.
*/

beforeEach(function (): void {
    SpaSession::isolateRateLimits(currentTest());

    // The create path writes the pasted body to object storage before the row exists, so a real
    // disk here would reach for SeaweedFS. Faking the DISK is not faking the behaviour: the key is
    // still generated, still tenant-prefixed, and still checked by
    // `source_items_storage_key_is_tenant_scoped` on the INSERT.
    Storage::fake('s3');

    Queue::fake();
});

/**
 * Every source endpoint as `[method, path template, body]`, with `{org}` and `{source}` to be
 * substituted.
 *
 * ONE LIST, USED BY EVERY TEST BELOW, so an endpoint added to the route file without a line here is
 * an endpoint no isolation test covers.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function sourceEndpoints(): array
{
    return [
        'index' => ['getJson', '/api/v1/organizations/{org}/sources', []],
        'store' => ['postJson', '/api/v1/organizations/{org}/sources', [
            'type' => 'text', 'name' => 'Probe paste', 'content' => 'probe content',
        ]],
        'show' => ['getJson', '/api/v1/organizations/{org}/sources/{source}', []],
        'update' => ['patchJson', '/api/v1/organizations/{org}/sources/{source}', ['name' => 'Probe rename']],
        'destroy' => ['deleteJson', '/api/v1/organizations/{org}/sources/{source}', []],
        // THE TWO LIFECYCLE ROUTES ARE IN THIS LIST RATHER THAN IN FILES OF THEIR OWN, precisely so
        // the binding, deny-oracle and role assertions below cover them without anybody having to
        // remember: a seventh route added without a line here is a route no isolation test touches.
        'status' => ['putJson', '/api/v1/organizations/{org}/sources/{source}/status', ['status' => 'disabled']],
        'reprocess' => ['postJson', '/api/v1/organizations/{org}/sources/{source}/reprocess', []],
    ];
}

/**
 * A source of `$org` in a state from which every mutating endpoint above has a legal edge.
 *
 * `Ready` AND NOT THE FACTORY DEFAULT. A `draft` source has exactly three legal moves — `queued`,
 * `deleted`, `archived` — so `disabled` and `reprocess` would 422 for EVERY role and the matrix
 * below would prove nothing while looking green. Picking the state from the transition table rather
 * than from the factory is what keeps the authorization assertions about authorization.
 */
function readySourceFor(\App\Models\Organization $org, string $name): KnowledgeSource
{
    return KnowledgeSource::factory()->recycle($org)
        ->status(SourceState::Ready)
        ->create(['name' => $name]);
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<\Illuminate\Http\JsonResponse>
 */
function callSourceEndpoint(string $method, string $path, array $body = []): TestResponse
{
    // `getJson()` takes no body argument, so the two spellings cannot be collapsed into one dynamic
    // call — and a GET that carried one would be silently dropped rather than refused.
    /** @var TestResponse<\Illuminate\Http\JsonResponse> $response */
    $response = $method === 'getJson'
        ? currentTest()->getJson($path, spaHeaders())
        : currentTest()->{$method}($path, $body, spaHeaders());

    return $response;
}

// ── the canary: org B's content never reaches org A ──────────────────────────────────────────────

it('never returns another organization\'s source on the list or the detail', function (): void {
    $t = tenantPair();

    // A DISTINGUISHING VALUE ON ORG B'S SOURCE. The shared canary lives in org B's bot welcome
    // message and no source endpoint renders a bot, so this file plants the source-shaped
    // equivalent — the source's own NAME, which the factory already makes unique per organization —
    // and asserts on it. The harness's canary is deliberately left where it is: tests/Support
    // carries the rule that there is exactly ONE planting site.
    $needle = $t->sourceB->name;

    // POSITIVE CONTROL, FIRST. Without it every absence assertion below also passes when the
    // endpoint is broken and returns nothing at all.
    SpaSession::establish(currentTest(), $t->actorB);

    $forB = currentTest()->getJson("/api/v1/organizations/{$t->b->id}/sources", spaHeaders());

    $forB->assertOk();

    expect(str_contains((string) $forB->getContent(), $needle))
        ->toBeTrue('org B\'s own source is not reaching org B, so no absence assertion below proves anything');

    // AND NOW THE NEGATIVE, from a fresh session as org A's admin.
    SpaSession::freshProcess();
    SpaSession::establish(currentTest(), $t->actorA);

    $list = currentTest()->getJson("/api/v1/organizations/{$t->a->id}/sources", spaHeaders());

    $list->assertOk();

    $body = (string) $list->getContent();

    expect(str_contains($body, $needle))->toBeFalse('org B\'s source name reached org A\'s list');
    expect(str_contains($body, $t->sourceB->id))->toBeFalse('org B\'s source id reached org A\'s list');

    // AND THE DETAIL ENDPOINT, addressed with org A's organization and org B's source. It 404s at
    // BINDING time — `->scopeBindings()` resolves `{source}` through `$organization->sources()` —
    // so the row is never in memory and nothing built from it can leak.
    $detail = currentTest()->getJson(
        "/api/v1/organizations/{$t->a->id}/sources/{$t->sourceB->id}",
        spaHeaders(),
    );

    $detail->assertStatus(404);

    expect(str_contains((string) $detail->getContent(), $needle))->toBeFalse();
});

it('does not leak another organization\'s source through the free-text filter', function (): void {
    $t = tenantPair();

    $sourceA = readySourceFor($t->a, 'Shared term ALPHA handbook');

    // A TERM THAT MATCHES A SOURCE IN BOTH ORGANIZATIONS. The filter is a grouped OR, and `AND`
    // binds tighter than `OR` in SQL — so a repository that chained `orWhere` onto the tenant
    // predicate without the closure group produces
    // `organization_id = ? AND name ILIKE ? OR origin_url ILIKE ?`, whose second disjunct carries
    // NO TENANT PREDICATE. A filter test whose term matches only one organization cannot see that,
    // and the defect reads as a formatting choice in review.
    KnowledgeSource::query()->withoutGlobalScopes()->whereKey($t->sourceB->id)
        ->update(['name' => 'Shared term BRAVO handbook']);

    SpaSession::establish(currentTest(), $t->actorB);

    // POSITIVE CONTROL: the term really does match org B's source, from org B's own session.
    currentTest()->getJson("/api/v1/organizations/{$t->b->id}/sources?filter=Shared+term", spaHeaders())
        ->assertOk()
        ->assertJsonCount(1, 'data.sources')
        ->assertJsonPath('data.sources.0.id', $t->sourceB->id);

    SpaSession::freshProcess();
    SpaSession::establish(currentTest(), $t->actorA);

    $response = currentTest()->getJson(
        "/api/v1/organizations/{$t->a->id}/sources?filter=Shared+term",
        spaHeaders(),
    );

    $response->assertOk()
        ->assertJsonCount(1, 'data.sources')
        ->assertJsonPath('data.sources.0.id', $sourceA->id)
        ->assertJsonPath('data.meta.total', 1);

    expect(str_contains((string) $response->getContent(), $t->sourceB->id))
        ->toBeFalse('the filter\'s OR group lost the tenant predicate');
});

// ── route-model binding: a foreign id is indistinguishable from one that never existed ───────────

it('404s a foreign or unknown source on every route that takes one, before any policy runs', function (string $case): void {
    $t = tenantPair();

    SpaSession::establish(currentTest(), $t->actorA);

    // POSITIVE CONTROL FIRST, ON A FRESH SOURCE PER ACTION. `status`, `reprocess` and `destroy` all
    // MOVE the row, and the transition table does not admit an arbitrary order — `disabled` has no
    // edge to `queued`, and `deleting` has only one edge at all — so re-using one row would make
    // the control fail for a reason that has nothing to do with the identifier under test.
    foreach (['show', 'update', 'status', 'reprocess', 'destroy'] as $action) {
        [$method, $template, $payload] = sourceEndpoints()[$action];

        $probe = readySourceFor($t->a, 'Control source for '.$action);

        $response = callSourceEndpoint(
            $method,
            str_replace(['{org}', '{source}'], [$t->a->id, $probe->id], $template),
            $payload,
        );

        expect($response->getStatusCode())
            ->toBeLessThan(300, "the control for {$action} failed, so the 404s below prove nothing");
    }

    $foreign = $case === 'another organization\'s source' ? $t->sourceB->id : (string) Str::ulid();

    $bodies = [];

    foreach (['show', 'update', 'status', 'reprocess', 'destroy'] as $action) {
        [$method, $template, $payload] = sourceEndpoints()[$action];

        $response = callSourceEndpoint(
            $method,
            str_replace(['{org}', '{source}'], [$t->a->id, $foreign], $template),
            $payload,
        );

        $response->assertStatus(404)->assertJsonPath('error_class', 'authorization');

        $bodies[] = (string) $response->getContent();
    }

    // THE ENUMERATION ORACLE IS CLOSED AT THE BODY AND NOT ONLY AT THE STATUS. Rendering
    // `authorization` as 404 is worthless if the body says which 404 it is: bootstrap/app.php
    // replaces the message with ONE CONSTANT per rendered status, so a denied row, a row in another
    // organization and a row that never existed are one response. `request_id` is the only field
    // that legitimately differs, so it is stripped before the comparison.
    $normalized = array_map(
        static fn (string $body): string => (string) preg_replace('/"request_id":"[^"]*"/', '"request_id":"*"', $body),
        $bodies,
    );

    expect(array_unique($normalized))->toHaveCount(1);
})->with([
    'another organization\'s source',
    'a source that never existed',
]);

it('renders the same 404 for a foreign source as for a path with no route at all', function (): void {
    $t = tenantPair();

    SpaSession::establish(currentTest(), $t->actorA);

    $foreign = currentTest()->getJson(
        "/api/v1/organizations/{$t->a->id}/sources/{$t->sourceB->id}",
        spaHeaders(),
    );

    // A REAL REQUEST AT A PATH THAT CERTAINLY HAS NO ROUTE, rather than a copy of the expected
    // strings: the property being asserted is that the two are INDISTINGUISHABLE rather than that
    // either equals some particular sentence.
    $noRoute = currentTest()->getJson('/api/v1/there-is-no-such-path-'.Str::ulid(), spaHeaders());

    $foreign->assertStatus(404);
    $noRoute->assertStatus(404);

    /**
     * @param  TestResponse<\Illuminate\Http\JsonResponse>  $r
     */
    $strip = static fn (TestResponse $r): string => (string) preg_replace(
        '/"request_id":"[^"]*"/',
        '"request_id":"*"',
        (string) $r->getContent(),
    );

    expect($strip($foreign))->toBe($strip($noRoute));
});

// ── the role matrix, PER ACTION, through the endpoints ───────────────────────────────────────────

it('enforces sources.view on the reads and sources.manage on the writes, per action', function (
    string $action,
    OrgRole $role,
    bool $allowed,
): void {
    $t = tenantPair();

    $actor = User::factory()->recycle($t->a)->orgRole($role)
        ->create(['email' => SpaSession::uniqueEmail('source-endpoint-'.$role->value)]);

    SpaSession::establish(currentTest(), $actor);

    $source = readySourceFor($t->a, 'Matrix source for '.$action.' '.$role->value);

    [$method, $template, $payload] = sourceEndpoints()[$action];

    $response = callSourceEndpoint(
        $method,
        str_replace(['{org}', '{source}'], [$t->a->id, $source->id], $template),
        $payload,
    );

    if ($allowed) {
        expect($response->getStatusCode())->toBeLessThan(300, "{$action} was denied for {$role->value}");

        return;
    }

    // 403 AND NOT 404, because this is an AUTHENTICATED ADMIN SURFACE and the caller is a member of
    // the organization that owns the row — admitting the row exists tells them nothing they could
    // not already learn. The public runtime and SDK surfaces make the opposite call, and
    // `error_class` is `authorization` in both cases so nothing branches on the status.
    $response->assertStatus(403)->assertJsonPath('error_class', 'authorization');
})->with(function (): array {
    // THE MATRIX IS ASSERTED PER ACTION, NEVER FROM A UNIFORM DATASET. Every role that holds
    // `sources.manage` also holds `sources.view`, so a `view` silently mapped to `sources.manage`
    // would produce identical answers for owner, admin and knowledge_manager and would differ only
    // for the ANALYST — the one row a hand-written dataset is most likely to under-cover, and the
    // one whose exclusion is an argued decision rather than an omission: `Permission::SourcesView`
    // refuses the tempting grant, because a conversation transcript renders its citations off the
    // denormalized citation row and reads nothing from `knowledge_sources`.
    $manage = [OrgRole::Owner, OrgRole::Admin, OrgRole::KnowledgeManager];

    $matrix = [
        'index' => $manage,
        'show' => $manage,
        'store' => $manage,
        'update' => $manage,
        'destroy' => $manage,
        'status' => $manage,
        'reprocess' => $manage,
    ];

    $cases = [];

    foreach ($matrix as $action => $allowedRoles) {
        foreach (OrgRole::cases() as $role) {
            $cases["{$action} as {$role->value}"] = [$action, $role, in_array($role, $allowedRoles, true)];
        }
    }

    return $cases;
});

// ── the cross-organization write, refused by the database as well as by the service ──────────────

it('refuses a source assigned to a bot in another organization, by constraint name', function (): void {
    $t = tenantPair();

    // THE ONE ROW IN THE SCHEMA THAT CAN SPAN TWO ORGANIZATIONS. `bot_id` and `source_id` each
    // inherit their own org and nothing in the FK graph forces them to agree, so this is asserted
    // BY CONSTRAINT NAME rather than as "something threw": a bare exception assertion passes when
    // the write fails for an unrelated reason, and a NOT NULL violation on a column the fixture
    // forgot would look identical.
    //
    // If this ever succeeds silently, that is the finding — not a flaky test, but a permanent
    // cross-tenant leak every downstream filter would AGREE with, because it has been told that org
    // B's source belongs to org A's bot.
    $failure = null;

    try {
        // RefreshDatabase holds one transaction per test and in PostgreSQL a raising statement
        // aborts it, so the nested transaction is a SAVEPOINT — without it every later assertion
        // would fail with 25P02 against a statement that is perfectly valid.
        $t->b->getConnection()->transaction(static function () use ($t): void {
            KnowledgeSource::factory()->recycle($t->b)->crossOrg($t->botA)->create();
        });
    } catch (\Illuminate\Database\QueryException $exception) {
        $failure = $exception;
    }

    expect($failure)->not->toBeNull('a source in org B was assigned to a bot in org A and nothing objected');
    expect($failure?->getMessage())->toContain('bot_source_assignments_bot_same_org');
});
