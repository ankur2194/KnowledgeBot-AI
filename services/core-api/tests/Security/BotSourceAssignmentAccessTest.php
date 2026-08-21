<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Models\Bot;
use App\Models\BotSourceAssignment;
use App\Models\KnowledgeSource;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| The retrieval-scope grant — who may make one, and what a denial reveals (§22.5)
|--------------------------------------------------------------------------
|
| `bot_source_assignments` is the one row in the schema that can span two organizations
| (kb-tenancy-isolation NN2), and it is the row `bot_ids` — one of the four mandatory Qdrant filter
| terms — is resolved from. A wrong row is not a bug the tenant filter catches; it is a bug the
| tenant filter ENFORCES, and the answer comes back at normal latency with a well-formed citation
| and an HTTP 200.
|
| THIS FILE ASSERTS THE SURFACE. The SCHEMA's half — each composite foreign key firing alone, by
| name — is tests/Security/KnowledgeSourceTenancyTest.php, and the two files are deliberately not
| merged: that one drives the MODEL, so it proves the constraints hold for a writer that never ran a
| FormRequest and never passed a policy (a repair script, a seeder, a console command), and this one
| drives the ENDPOINTS. The one test below that reaches into the database does so to assert the
| PAIRING rather than to re-assert a constraint, and says so where it does it.
|
| `Http::fake()` APPEARS NOWHERE, and cannot: pest-testing bans it under tests/Security outright.
| None of these endpoints calls the AI service.
|
| ABSENCE IS ALWAYS `expect(str_contains($body, $needle))->toBeFalse()` and NEVER
| `->not->toContain(...)`. Pest's toContain(mixed ...$needles) takes no message argument, so a label
| passed there becomes a second needle, and `not` treats any failure as success — the expression
| passes unconditionally. That exact shape has already hidden a real tenant-id leak in this repo.
*/

beforeEach(function (): void {
    SpaSession::isolateRateLimits(currentTest());
});

/**
 * Every assignment endpoint as `[method, path template, body]`, with `{org}`, `{bot}`, `{child}`
 * and `{source}` to be substituted.
 *
 * ONE LIST, USED BY EVERY TEST BELOW, so an endpoint added to the route file without a line here is
 * an endpoint no isolation test covers.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function assignmentEndpoints(): array
{
    return [
        'index' => ['getJson', '/api/v1/organizations/{org}/bots/{bot}/source-assignments', []],
        'store' => ['postJson', '/api/v1/organizations/{org}/bots/{bot}/source-assignments', ['source_id' => '{source}']],
        'destroy' => ['deleteJson', '/api/v1/organizations/{org}/bots/{bot}/source-assignments/{child}', []],
    ];
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<\Illuminate\Http\JsonResponse>
 */
function callAssignmentEndpoint(string $method, string $path, array $body = []): TestResponse
{
    // `getJson()` and `deleteJson()` take their arguments differently from `postJson()`, so the
    // spellings cannot be collapsed into one dynamic call — and a GET that carried a body would be
    // silently dropped rather than refused.
    /** @var TestResponse<\Illuminate\Http\JsonResponse> $response */
    $response = $method === 'getJson'
        ? currentTest()->getJson($path, spaHeaders())
        : currentTest()->{$method}($path, $body, spaHeaders());

    return $response;
}

// ── THE REQUIRED TEST: the cross-organization grant, refused twice ───────────────────────────────

it('refuses the cross-organization grant at the SERVICE and at the DATABASE, and neither refusal is evidence for the other', function (): void {
    /*
     * ── WHY BOTH HALVES ARE IN ONE TEST ─────────────────────────────────────────────────────
     *
     * `KnowledgeSourceFactory::crossOrg()`'s docblock: *"It exists ONLY so a test can assert that
     * BOTH the service layer AND the database reject it"*, and pest-testing's Definition of done
     * says the same in one line. The reason the pairing has to be asserted, rather than each half
     * separately in a file of its own, is that each half hides the other's absence:
     *
     *   WITHOUT THE DATABASE HALF, a service refusal proves only that THIS code path refuses. Every
     *   other writer — a repair script, a seeder, a console command, an ingestion callback, a
     *   service nobody has written yet — reaches the table with the constraints as its only guard,
     *   and there is no later layer that catches a bad row: the tenant filter AGREES with it.
     *
     *   WITHOUT THE SERVICE HALF, a constraint refusal is a 500 for a request that should have been
     *   a 404. The caller learns the row was attempted, which is an existence oracle over another
     *   organization's document ids, and the error envelope reports `internal_dependency` on what is
     *   an authorization outcome.
     *
     * THE SHAPE UNDER TEST IS THE ONE THE API CAN ACTUALLY REACH: the caller's OWN bot, from the
     * path, and ANOTHER organization's source, from the body. `bot_same_org` passes on that row —
     * the bot really is theirs — so `bot_source_assignments_source_same_org` is the only constraint
     * standing, and it is the realistic attack rather than the symmetrical one.
     *
     * THE DATABASE HALF BELOW IS NOT A DUPLICATE OF
     * tests/Security/KnowledgeSourceTenancyTest.php's `source_same_org` case, even though the row
     * is the same shape. That test asserts the CONSTRAINT. This one asserts that the constraint is
     * what stands behind the 404 the service just returned — i.e. that the service's refusal was
     * not the only thing between this caller and the row.
     */
    $t = tenantPair();

    SpaSession::establish(currentTest(), $t->actorA);

    // POSITIVE CONTROL FIRST (pest-testing NN2), AND IT IS THE ILLEGAL REQUEST WITH ONE FIELD
    // CHANGED. Org A's own bot, Org A's own source. Without it, the refusal below also passes
    // against an endpoint that refuses EVERY grant — which would break the product completely while
    // making the security assertion look strongest.
    $ownSource = KnowledgeSource::factory()->recycle($t->a)->create(['name' => 'ALPHA own corpus']);

    currentTest()->postJson(
        "/api/v1/organizations/{$t->a->id}/bots/{$t->botA->id}/source-assignments",
        ['source_id' => $ownSource->id],
        spaHeaders(),
    )->assertCreated();

    // ── HALF ONE: THE SERVICE ────────────────────────────────────────────────────────────────
    //
    // The same request with ORG B'S SOURCE. `source_id` is a body field, so no route binding can
    // scope it — the scoping is `KnowledgeSourceRepositoryInterface::find($organizationId, …)`,
    // whose organization argument is required and positional.
    $refused = currentTest()->postJson(
        "/api/v1/organizations/{$t->a->id}/bots/{$t->botA->id}/source-assignments",
        ['source_id' => $t->sourceB->id],
        spaHeaders(),
    );

    // A 404 AND NOT A 422. A validation error saying "no such source" answers a question this caller
    // is not entitled to ask, and it answers it differently for an id that exists in another
    // organization than for one that exists nowhere — which is an oracle over every customer's
    // document ids rendered as a form message.
    $refused->assertStatus(404)->assertJsonPath('error_class', 'authorization');

    // AND NOTHING LANDED. A refusal that returns 404 after writing is not a refusal.
    //
    // KEYED ON THE PAIR AND NOT ON THE SOURCE ALONE: `tenantPair()` already gave Org B's source a
    // LEGITIMATE grant to Org B's bot, so a count over `source_id` is 1 by construction and would
    // fail this assertion no matter what the endpoint did.
    expect(
        BotSourceAssignment::withoutGlobalScopes()
            ->where('bot_id', $t->botA->id)
            ->where('source_id', $t->sourceB->id)
            ->count(),
    )->toBe(0, 'the endpoint answered 404 and wrote the grant anyway');

    // ── HALF TWO: THE DATABASE, ON THE EXACT ROW THE SERVICE REFUSED ─────────────────────────
    //
    // Written field by field through the model, because that is what every writer that is not this
    // endpoint looks like. `crossOrg()` cannot express this shape — it always writes the SOURCE's
    // organization onto the row, so `bot_same_org` is the key that fires there — and `assignedTo()`
    // refuses it outright, which is a fixture-level guard worth not routing around.
    $exception = null;

    try {
        // THE SAVEPOINT IS LOAD-BEARING. `RefreshDatabase` wraps each test in one transaction, and
        // in PostgreSQL a statement that raises ABORTS it: every subsequent statement fails with
        // 25P02 until a rollback. Laravel implements a nested `transaction()` as SAVEPOINT /
        // ROLLBACK TO SAVEPOINT when one is already open, so the assertions after this one can run.
        (new BotSourceAssignment)->getConnection()->transaction(static function () use ($t): bool {
            $row = new BotSourceAssignment;
            $row->organization_id = $t->a->id;   // Org A ...
            $row->bot_id = $t->botA->id;         // ... Org A's own bot, so `bot_same_org` PASSES ...
            $row->source_id = $t->sourceB->id;   // ... and ORG B'S SOURCE.
            $row->priority = 0;
            $row->enabled = true;
            $row->save();

            return true;
        });
    } catch (QueryException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(
        QueryException::class,
        'the service refused the grant with a 404 and the DATABASE accepted the same row. The '
        .'service refusal is therefore the only thing standing between an admin of Org A and Org '
        .'B\'s corpus, and every writer that is not this endpoint — a repair script, a seeder, a '
        .'console command, an ingestion callback — bypasses it. That is not a flaky test: it is a '
        .'permanent cross-tenant leak that every downstream filter AGREES with.',
    );

    // THE CONSTRAINT, BY NAME AND WITH NO DISJUNCTION. Exactly one key can fire on this row, so
    // accepting either name would put this assertion back in the state that let a constraint be
    // deleted unnoticed — the defect KnowledgeSourceTenancyTest's header records.
    expect(str_contains((string) $exception?->getMessage(), 'bot_source_assignments_source_same_org'))
        ->toBeTrue(
            'the write was refused, but not by `bot_source_assignments_source_same_org`, which is '
            .'the constraint standing behind the endpoint\'s 404. The actual message was: '
            .(string) $exception?->getMessage(),
        );

    // AND THE LEGAL ROW IS STILL THE ONLY ONE. Asserting by id rather than by count also proves the
    // positive control was not silently rolled back with the violation.
    expect(
        BotSourceAssignment::withoutGlobalScopes()
            ->where('bot_id', $t->botA->id)->pluck('source_id')->all(),
    )->toBe([$ownSource->id]);
});

// ── the canary: org B's grants and corpus never reach org A ──────────────────────────────────────

it('never returns another organization\'s assigned sources', function (): void {
    $t = tenantPair();

    // ORG B'S SOURCE ALREADY CARRIES A GRANT — `tenantPair()` creates it through `assignedTo()` —
    // so the only thing this test adds is a canary a leak could carry. The canary lives in Org B's
    // BOT welcome message (tests/Support/tenancy.php), which no assignment response renders, so the
    // assertion below is planted on the source's NAME as well: a leak through this surface would
    // arrive as a source object.
    KnowledgeSource::withoutGlobalScopes()->whereKey($t->sourceB->id)
        ->update(['name' => "BRAVO handbook {$t->canary}"]);

    // Org A gets a grant of its own, so "org A's response is empty" cannot be what makes the
    // negative assertion pass.
    $ownSource = KnowledgeSource::factory()->recycle($t->a)->create(['name' => 'ALPHA handbook']);
    KnowledgeSource::factory()->recycle($t->a)->assignedTo($t->botA)->create(['name' => 'ALPHA assigned']);

    // POSITIVE CONTROL, FIRST. Without it the absence assertions below also pass when the endpoint
    // is broken and returns nothing at all.
    SpaSession::establish(currentTest(), $t->actorB);

    $forB = currentTest()->getJson(
        "/api/v1/organizations/{$t->b->id}/bots/{$t->botB->id}/source-assignments",
        spaHeaders(),
    )->assertOk();

    expect(str_contains((string) $forB->getContent(), $t->canary))->toBeTrue(
        'the canary is not reaching the organization that OWNS it, so no absence assertion below '
        .'proves anything',
    );

    // AND NOW THE NEGATIVE, from a fresh session as org A's admin.
    SpaSession::freshProcess();
    SpaSession::establish(currentTest(), $t->actorA);

    $forA = currentTest()->getJson(
        "/api/v1/organizations/{$t->a->id}/bots/{$t->botA->id}/source-assignments",
        spaHeaders(),
    )->assertOk();

    $body = (string) $forA->getContent();

    expect(str_contains($body, $t->canary))->toBeFalse('org B\'s source content reached org A');
    expect(str_contains($body, $t->sourceB->id))->toBeFalse('org B\'s source id reached org A');
    expect(str_contains($body, $t->botB->id))->toBeFalse('org B\'s bot id reached org A');
    expect(str_contains($body, $t->b->id))->toBeFalse('org B\'s organization id reached org A');

    // AND THE FILTER IS NOT A WAY ROUND IT. A term BOTH organizations match is what catches a
    // predicate whose tenant term was lost to `AND`/`OR` precedence — the leak that looks like a
    // formatting choice.
    $filtered = currentTest()->getJson(
        "/api/v1/organizations/{$t->a->id}/bots/{$t->botA->id}/source-assignments?filter=handbook",
        spaHeaders(),
    )->assertOk();

    expect(str_contains((string) $filtered->getContent(), $t->canary))
        ->toBeFalse('a filter both organizations match returned org B\'s row');
    expect($filtered->json('data.meta.total'))->toBe(0, 'org A has no ASSIGNED source named "handbook"');

    // The unassigned Org A source really does match the term, so the zero above is about the GRANT
    // list rather than about the filter matching nothing.
    expect(str_contains(Str::lower($ownSource->name), 'handbook'))->toBeTrue();
});

// ── route-model binding: two hops, and the second one is the interesting one ─────────────────────

it('404s a grant of a DIFFERENT bot inside the SAME organization', function (): void {
    // THE CASE NO CROSS-TENANT TEST CAN SEE. `#[ScopedBy(OrganizationScope::class)]` would still
    // hold if the bot predicate were lost, so this defect leaks nothing across tenants — it lets
    // any of an organization's own grants be withdrawn under any of its other bots' URLs, which on
    // this table is one bot losing a corpus because somebody addressed another.
    $t = tenantPair();

    KnowledgeSource::factory()->recycle($t->a)->assignedTo($t->botA)->create(['name' => 'ALPHA mine']);

    $mine = BotSourceAssignment::withoutGlobalScopes()->where('bot_id', $t->botA->id)->firstOrFail();

    $sibling = Bot::factory()->recycle($t->a)->create(['name' => 'ALPHA sibling', 'slug' => 'alpha-sibling']);

    SpaSession::establish(currentTest(), $t->actorA);

    // THE 404 FIRST, so the successful withdrawal below cannot be what made it 404.
    currentTest()->deleteJson(
        "/api/v1/organizations/{$t->a->id}/bots/{$sibling->id}/source-assignments/{$mine->id}",
        [],
        spaHeaders(),
    )
        ->assertStatus(404)
        ->assertJsonPath('error_class', 'authorization');

    // POSITIVE CONTROL: the row IS reachable under its OWN bot, so the 404 above is about the
    // parent rather than about the route being broken.
    currentTest()->deleteJson(
        "/api/v1/organizations/{$t->a->id}/bots/{$t->botA->id}/source-assignments/{$mine->id}",
        [],
        spaHeaders(),
    )->assertOk();
});

it('404s a foreign bot, a foreign grant and a foreign source, with a body byte-identical to no route at all', function (): void {
    $t = tenantPair();

    $foreignAssignment = BotSourceAssignment::withoutGlobalScopes()
        ->where('bot_id', $t->botB->id)->firstOrFail();

    SpaSession::establish(currentTest(), $t->actorA);

    $bodies = [];

    // 1. A FOREIGN BOT on the collection routes. `{bot}` resolves through `$organization->bots()`,
    //    so org B's bot under org A's organization is a 404 at BINDING time — before any policy is
    //    constructed and before either row is in memory, which is what stops a response-time
    //    difference from being an oracle.
    foreach (['index', 'store'] as $action) {
        [$method, $template, $payload] = assignmentEndpoints()[$action];

        $response = callAssignmentEndpoint(
            $method,
            str_replace(['{org}', '{bot}'], [$t->a->id, $t->botB->id], $template),
            array_map(static fn (mixed $v): mixed => $v === '{source}' ? $t->sourceB->id : $v, $payload),
        );

        $response->assertStatus(404)->assertJsonPath('error_class', 'authorization');
        $bodies[] = (string) $response->getContent();
    }

    // 2. A FOREIGN GRANT under the caller's own bot, and one that never existed.
    foreach ([$foreignAssignment->id, (string) Str::ulid()] as $child) {
        $response = currentTest()->deleteJson(
            "/api/v1/organizations/{$t->a->id}/bots/{$t->botA->id}/source-assignments/{$child}",
            [],
            spaHeaders(),
        );

        $response->assertStatus(404)->assertJsonPath('error_class', 'authorization');
        $bodies[] = (string) $response->getContent();
    }

    // 3. A FOREIGN SOURCE in the BODY, which no route binding can scope. This is the one the
    //    service performs by hand, and it must be indistinguishable from all of the above.
    foreach ([$t->sourceB->id, (string) Str::ulid()] as $sourceId) {
        $response = currentTest()->postJson(
            "/api/v1/organizations/{$t->a->id}/bots/{$t->botA->id}/source-assignments",
            ['source_id' => $sourceId],
            spaHeaders(),
        );

        $response->assertStatus(404)->assertJsonPath('error_class', 'authorization');
        $bodies[] = (string) $response->getContent();
    }

    // 4. AND A PATH WITH NO ROUTE AT ALL, on the same surface. This is the reference the others are
    //    compared against, and it is a real request rather than a literal: asserting
    //    `message === 'The requested resource was not found.'` would only prove this file and
    //    bootstrap/app.php agree with each other, and would keep agreeing after both drifted.
    $noRoute = currentTest()->getJson('/api/v1/'.Str::ulid()->toBase32(), spaHeaders());

    $noRoute->assertStatus(404);
    $bodies[] = (string) $noRoute->getContent();

    // THE ENUMERATION ORACLE IS CLOSED AT THE BODY AND NOT ONLY AT THE STATUS. `request_id` is the
    // only field that legitimately differs, so it is normalised before the comparison.
    $normalized = array_map(
        static fn (string $body): string => (string) preg_replace('/"request_id":"[^"]*"/', '"request_id":"*"', $body),
        $bodies,
    );

    expect(array_unique($normalized))->toHaveCount(
        1,
        'a denied resource is distinguishable from one that never existed — the 404 is defeated by '
        .'the response BODY, which is what an attacker actually reads',
    );
});

// ── the role matrix, PER ACTION, through the endpoints ───────────────────────────────────────────

it('enforces bots.view on the read and sources.assign on the writes, per action', function (
    string $action,
    OrgRole $role,
    bool $allowed,
): void {
    $t = tenantPair();

    $source = KnowledgeSource::factory()->recycle($t->a)->create(['name' => 'ALPHA matrix corpus']);

    KnowledgeSource::factory()->recycle($t->a)->assignedTo($t->botA)->create(['name' => 'ALPHA matrix grant']);

    $assignment = BotSourceAssignment::withoutGlobalScopes()->where('bot_id', $t->botA->id)->firstOrFail();

    $actor = User::factory()->recycle($t->a)->orgRole($role)
        ->create(['email' => SpaSession::uniqueEmail('assignment-'.$role->value)]);

    SpaSession::establish(currentTest(), $actor);

    [$method, $template, $payload] = assignmentEndpoints()[$action];

    $response = callAssignmentEndpoint(
        $method,
        str_replace(['{org}', '{bot}', '{child}'], [$t->a->id, $t->botA->id, $assignment->id], $template),
        array_map(static fn (mixed $v): mixed => $v === '{source}' ? $source->id : $v, $payload),
    );

    if ($allowed) {
        expect($response->getStatusCode())->toBeLessThan(300, "{$action} was denied for {$role->value}");

        return;
    }

    // 403 AND NOT 404, because this is an AUTHENTICATED ADMIN SURFACE and the caller is a member of
    // the organization that owns both records — admitting they exist tells them nothing they could
    // not already learn. The public runtime and SDK surfaces make the opposite call, and
    // `error_class` is `authorization` in both cases so nothing branches on the status.
    $response->assertStatus(403)->assertJsonPath('error_class', 'authorization');
})->with(function (): array {
    // ASSERTED PER ACTION, NEVER FROM A UNIFORM DATASET, and on this surface the asymmetry is the
    // whole point: `index` is `bots.view`, which ALL FOUR roles hold, while the two writes are
    // `sources.assign`, which the ANALYST does not. A uniform dataset would go green against a read
    // silently mapped to `sources.assign` — the mapping that would lock an analyst out of a list
    // `Permission::BotsView` names in as many words.
    //
    // KNOWLEDGE_MANAGER IS THE ROW THAT PINS THE PERMISSION CHOICE. It holds `sources.assign` and
    // NOT `bots.manage`, so a write gated on `BotPolicy::manageChildren()` would deny it — and
    // `BotPolicy`'s own docblock says all four roles hold `bots.view` "knowledge_manager because
    // Phase C6 has them assign sources to bots". This row is what makes that sentence enforced
    // rather than merely written down.
    $matrix = [
        'index' => [OrgRole::Owner, OrgRole::Admin, OrgRole::KnowledgeManager, OrgRole::Analyst],
        'store' => [OrgRole::Owner, OrgRole::Admin, OrgRole::KnowledgeManager],
        'destroy' => [OrgRole::Owner, OrgRole::Admin, OrgRole::KnowledgeManager],
    ];

    $rows = [];

    foreach ($matrix as $action => $permitted) {
        foreach (OrgRole::cases() as $role) {
            $rows[$action.' / '.$role->value] = [$action, $role, in_array($role, $permitted, true)];
        }
    }

    return $rows;
});

// ── over-posting the ownership columns ───────────────────────────────────────────────────────────

it('cannot write a grant into another organization or another bot by over-posting', function (): void {
    // `organization_id` and `bot_id` are absent from the rule set, from `NewSourceAssignment` and
    // from `BotSourceAssignment::$fillable` — three layers, because a missing validation rule is
    // one careless line away from coming back. `bot_id` is the one peculiar to this surface: it is
    // the ownership edge INSIDE the tenant, and neither composite foreign key can object to a move
    // between two bots of the same organization because both are legal targets.
    $t = tenantPair();

    $source = KnowledgeSource::factory()->recycle($t->a)->create(['name' => 'ALPHA overpost corpus']);
    $sibling = Bot::factory()->recycle($t->a)->create(['name' => 'ALPHA overpost', 'slug' => 'alpha-overpost']);

    SpaSession::establish(currentTest(), $t->actorA);

    currentTest()->postJson(
        "/api/v1/organizations/{$t->a->id}/bots/{$t->botA->id}/source-assignments",
        [
            'source_id' => $source->id,
            'organization_id' => $t->b->id,
            'bot_id' => $sibling->id,
            // Over-posted too, and this one is NOT an ownership column — it is a real field with a
            // real rule, so it must be HONOURED while the two above are dropped. Without it the
            // assertion below could pass against a request the validator rejected wholesale.
            'priority' => 7,
        ],
        spaHeaders(),
    )->assertCreated();

    $row = BotSourceAssignment::withoutGlobalScopes()->where('source_id', $source->id)->firstOrFail();

    expect($row->organization_id)->toBe($t->a->id)
        ->and($row->bot_id)->toBe($t->botA->id)
        ->and($row->priority)->toBe(7);
});
