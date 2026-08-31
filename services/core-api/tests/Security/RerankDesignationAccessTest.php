<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Repositories\Contracts\OrganizationRepositoryInterface;
use App\Services\Rerank\RerankDesignation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

use function Pest\Laravel\assertDatabaseHas;

/*
|--------------------------------------------------------------------------
| The rerank designation cannot point at another organization's credential (§22.5)
|--------------------------------------------------------------------------
|
| WHY THIS IS WORTH ITS OWN FILE, AND WHY THE CONSEQUENCE IS NOT THE USUAL READ LEAK.
|
| A rerank designation naming another organization's connection means THIS tenant's END USERS'
| QUESTIONS and its retrieved chunk text are sent to THAT tenant's provider account — billed to
| them, and readable in their provider dashboard — while every layer downstream agrees, because you
| have told it whose credential reranks. It is the same shape as the embedding version of the
| failure, on data that is arguably more sensitive: an embedding call carries corpus text the
| organization uploaded, a rerank call carries corpus text AND the question a customer's end user
| just typed.
|
| FOUR LAYERS ARE ASSERTED, and each one alone would be enough on a good day:
|   1. the ADMIN DENY, 403 on a foreign `{organization}` — this is an authenticated admin surface,
|      so a member of the organization is already entitled to know the record is there and the
|      enumeration-safe 404 belongs to the public runtime and SDK surfaces, not here;
|   2. the ORG-SCOPED CONNECTION READ, so a foreign connection is refused by ABSENCE rather than by
|      a comparison against a foreign row somebody loaded;
|   3. the CATALOG RE-VERIFICATION inside the write's transaction, whose predicates are explicit;
|   4. the COMPOSITE FOREIGN KEY (id, rerank_connection_id) -> provider_connections
|      (organization_id, id), which refuses the row even when every layer above is bypassed.
|
| `Http::fake()` APPEARS NOWHERE IN THIS FILE, and cannot: pest-testing bans it under tests/Security
| outright. Neither rerank action calls the AI service, so there is nothing to fake and nothing that
| could pass because a faked response short-circuited a real code path. That is a property of the
| surface rather than a convenience — see App\Services\Rerank\RerankDesignationService.
|
| EVERY FIXTURE IS tenantPair(). A one-organization fixture passes every test below against code
| with no tenant filter at all, which is the reason there is deliberately no single-org helper to
| reach for.
*/

/**
 * A connection with one ranking model row, in the organization named.
 *
 * `recycle()` is not optional: ProviderConnectionFactory refuses to run without it, precisely
 * because a connection minted into a THIRD organization is what makes an isolation assertion pass
 * with the filter deleted.
 */
function rerankConnectionIn(Organization $organization, string $model): ProviderConnection
{
    return ProviderConnection::factory()->recycle($organization)
        ->withModel($model, ['rerank'])
        ->create();
}

it('never lets one organization designate another\'s connection', function (): void {
    $t = tenantPair();

    // A DISTINGUISHABLE MODEL ID PER ORGANIZATION, so an assertion about the RESPONSE can name the
    // thing that must not appear rather than only the status code.
    $connectionA = rerankConnectionIn($t->a, 'A-RERANKER-01JQZ');
    $connectionB = rerankConnectionIn($t->b, 'B-RERANKER-01JQZ');

    $urlA = "/api/v1/organizations/{$t->a->id}/rerank-configuration";

    // POSITIVE CONTROL FIRST (pest-testing NN2). Without it every assertion below also passes
    // against an endpoint that refuses everything — which is how an isolation test becomes
    // decoration.
    currentTest()->actingAs($t->actorA)->putJson($urlA, [
        'connection_id' => $connectionA->id,
        'model' => 'A-RERANKER-01JQZ',
    ])->assertOk();

    // THE ASSERTION. Org A's admin, acting inside Org A, naming Org B's connection.
    $response = currentTest()->actingAs($t->actorA)->putJson($urlA, [
        'connection_id' => $connectionB->id,
        'model' => 'B-RERANKER-01JQZ',
    ])->assertStatus(422)->assertJsonPath('error_class', 'validation');

    // AND THE REFUSAL SAYS NOTHING ABOUT ORG B. The connection is refused by absence from an
    // org-scoped read, so the message cannot contain a fact about the other tenant — and the
    // response must not echo the submitted id back either, which would confirm to a prober that the
    // value was at least well formed enough to be looked up.
    //
    // Written as str_contains(...)->toBeFalse() and NOT as expect($body)->not->toContain(...):
    // toContain takes only needles, `not` treats any failure as success, and that shape has already
    // hidden a real tenant-id leak in this repository.
    $body = $response->getContent();

    assert(is_string($body));

    expect(str_contains($body, 'B-RERANKER-01JQZ'))->toBeFalse()
        ->and(str_contains($body, $connectionB->id))->toBeFalse()
        ->and(str_contains($body, $t->b->id))->toBeFalse()
        ->and(str_contains($body, $t->canary))->toBeFalse();

    // NOTHING MOVED. Org A still holds the designation it legitimately made, so the refusal did not
    // half-apply; Org B holds none at all, so nothing was written into the wrong row.
    assertDatabaseHas('organizations', [
        'id' => $t->a->id,
        'rerank_connection_id' => $connectionA->id,
        'rerank_model' => 'A-RERANKER-01JQZ',
    ]);

    assertDatabaseHas('organizations', [
        'id' => $t->b->id,
        'rerank_connection_id' => null,
        'rerank_model' => null,
    ]);
});

it('denies a foreign organization on the admin surface with 403, not 404', function (): void {
    // THE DENY SPLIT, ASSERTED IN THE DIRECTION THAT IS EASY TO GET BACKWARDS. `authorization`
    // renders 404 on the public runtime and SDK surfaces because a foreign identifier there is an
    // enumeration oracle. This is the authenticated ADMIN surface, where a member of the
    // organization is already entitled to know the record exists — so the deny is 403, and the
    // `error_class` is `authorization` either way.
    $t = tenantPair();

    $urlB = "/api/v1/organizations/{$t->b->id}/rerank-configuration";

    currentTest()->actingAs($t->actorA)->getJson($urlB)
        ->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');

    currentTest()->actingAs($t->actorA)->putJson($urlB, ['connection_id' => null, 'model' => null])
        ->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');

    // POSITIVE CONTROL: Org B's own admin reaches the same URL. Without it this test also passes
    // against a route that 403s for everybody, including the tenant that owns it.
    currentTest()->actingAs($t->actorB)->getJson($urlB)->assertOk();
});

it('never reads one organization\'s designation into another\'s response', function (): void {
    // THE READ HALF. `show` renders two columns off the bound `organizations` row, so the failure
    // shape is a binding that resolved the wrong row rather than a query missing a predicate — and
    // it is asserted on the RAW BODY, so a designation hiding anywhere in the envelope trips it.
    $t = tenantPair();

    $connectionB = rerankConnectionIn($t->b, 'B-RERANKER-01JQZ');

    currentTest()->actingAs($t->actorB)->putJson("/api/v1/organizations/{$t->b->id}/rerank-configuration", [
        'connection_id' => $connectionB->id,
        'model' => 'B-RERANKER-01JQZ',
    ])->assertOk();

    // POSITIVE CONTROL FIRST: the designation really is readable by the organization that made it.
    $ownBody = currentTest()->actingAs($t->actorB)
        ->getJson("/api/v1/organizations/{$t->b->id}/rerank-configuration")
        ->assertOk()
        ->assertJsonPath('data.degrades_to_fused_order', false)
        ->getContent();

    assert(is_string($ownBody));

    expect(str_contains($ownBody, 'B-RERANKER-01JQZ'))->toBeTrue();

    // THE ASSERTION. Org A reads its own configuration and sees none of Org B's.
    $otherBody = currentTest()->actingAs($t->actorA)
        ->getJson("/api/v1/organizations/{$t->a->id}/rerank-configuration")
        ->assertOk()
        ->assertJsonPath('data.designated', null)
        ->assertJsonPath('data.degrades_to_fused_order', true)
        ->getContent();

    assert(is_string($otherBody));

    expect(str_contains($otherBody, 'B-RERANKER-01JQZ'))->toBeFalse()
        ->and(str_contains($otherBody, $connectionB->id))->toBeFalse()
        ->and(str_contains($otherBody, $t->b->id))->toBeFalse();
});

it('is refused by the database even when every layer above it is bypassed', function (): void {
    // LAYER 4, ON ITS OWN. This writes the column directly — no FormRequest, no policy, no service,
    // no repository — which is exactly the situation a composite foreign key exists for: a future
    // code path nobody has written yet, a repair script, a console command.
    $t = tenantPair();

    $connectionB = rerankConnectionIn($t->b, 'B-RERANKER-01JQZ');

    expect(function () use ($t, $connectionB): void {
        $t->a->rerank_connection_id = $connectionB->id;
        $t->a->rerank_model = 'B-RERANKER-01JQZ';
        $t->a->save();
    })->toThrow(QueryException::class);

    // NO assertDatabaseHas AFTERWARDS, and its absence is not an oversight. RefreshDatabase wraps
    // each test in one transaction, and a constraint violation puts PostgreSQL into 25P02 — every
    // subsequent statement on that connection is refused until the block ends. A follow-up
    // assertion would fail with "current transaction is aborted", which reads as a schema problem
    // rather than as the constraint doing its job. The raised QueryException IS the assertion.
});

it('refuses half a designation at the database too', function (): void {
    // The CHECK num_nonnulls(...) <> 1. The FormRequest says the same thing where it can name the
    // field; this is the layer that holds when nothing above it ran. It matters more here than it
    // looks: half a designation reaches `rerank_gate` as `model = null`, which reads as "reranking
    // is off" — the operator's choice discarded with no error anywhere.
    $t = tenantPair();

    expect(function () use ($t): void {
        $t->a->rerank_model = 'B-RERANKER-01JQZ';
        $t->a->save();
    })->toThrow(QueryException::class);
});

it('refuses to delete a connection that is currently the designated reranker', function (): void {
    // ON DELETE RESTRICT rather than SET NULL, and the reason is the INVERSE of embedding's. There,
    // SET NULL could return the organization to resolve-by-rule and move the vector space under an
    // indexed corpus. Here it would merely stop reranking — with no error, no metric jump on any
    // single request, and nothing on any response to say so, which is the failure this platform is
    // least able to notice.
    //
    // Asserted at the DATABASE rather than over HTTP, deliberately: what is under test is the
    // constraint, and the controller's 409 pre-flight is asserted in
    // tests/Feature/RerankConfigurationTest.php. The two are different layers and a test that only
    // exercised the controller would pass with the constraint dropped.
    $t = tenantPair();

    $connectionA = rerankConnectionIn($t->a, 'A-RERANKER-01JQZ');

    // THE runFor() IS NOT CEREMONY. The repository method declares a precondition — bound context,
    // equal to the argument — because its catalog re-verification reads a #[ScopedBy] model whose
    // scope fails closed. Every production caller arrives inside App\Http\Middleware\TenantContext,
    // which binds exactly this; a direct call that skipped it would be exercising a state no
    // request can produce.
    app(TenantContext::class)->runFor($t->a->id, function () use ($t, $connectionA): void {
        app(OrganizationRepositoryInterface::class)->designateRerankConnection(
            $t->a->id,
            new RerankDesignation($connectionA->id, 'A-RERANKER-01JQZ'),
            // The audit closure is required by the contract. Here it is a no-op: this test is about
            // the constraint, and AuditLoggerTest plus the Feature suite own the trail.
            static function (): void {},
        );
    });

    expect(fn (): mixed => ProviderConnection::query()->withoutGlobalScopes()
        ->whereKey($connectionA->id)->delete())->toThrow(QueryException::class);
});

it('refuses to write a rerank designation at all when the tenant context does not agree', function (): void {
    /*
     * THE PRECONDITION, ASSERTED IN BOTH ITS FAILING DIRECTIONS.
     *
     * The catalog re-verification inside the repository reads a #[ScopedBy] model, so under a
     * missing or disagreeing context it comes back empty whatever the catalogue contains.
     * Interpreting that as "the model was removed from this connection" would hand the caller a 422
     * asserting a deletion that never happened, and point an investigation at the wrong table.
     *
     * So the assertion is specifically that it is NOT a KbException. KbException extends
     * RuntimeException, so `toThrow(RuntimeException::class)` would not have distinguished them —
     * LogicException does.
     */
    $t = tenantPair();

    $connectionA = rerankConnectionIn($t->a, 'A-RERANKER-01JQZ');

    $repository = app(OrganizationRepositoryInterface::class);
    $designation = new RerankDesignation($connectionA->id, 'A-RERANKER-01JQZ');
    $audit = static function (): void {};

    // DIRECTION 1: no context at all — the pooled worker whose context was never set.
    expect(fn (): mixed => $repository->designateRerankConnection($t->a->id, $designation, $audit))
        ->toThrow(\LogicException::class);

    // DIRECTION 2: a context naming somebody else — the pooled worker still holding the previous
    // tenant, which is the silent one of the two failures.
    expect(fn (): mixed => app(TenantContext::class)->runFor(
        $t->b->id,
        fn (): mixed => $repository->designateRerankConnection($t->a->id, $designation, $audit),
    ))->toThrow(\LogicException::class);

    $caught = null;

    try {
        $repository->designateRerankConnection($t->a->id, $designation, $audit);
    } catch (\Throwable $e) {
        $caught = $e;
    }

    // AND IT IS NOT THE 422. A KbException here would mean the repository had converted "I could not
    // ask the question" into "the answer is no", which is the whole defect this guards.
    expect($caught)->toBeInstanceOf(\LogicException::class)
        ->and($caught)->not->toBeInstanceOf(\App\Exceptions\KbException::class);

    // NOTHING WAS WRITTEN, in any direction.
    assertDatabaseHas('organizations', [
        'id' => $t->a->id,
        'rerank_connection_id' => null,
        'rerank_model' => null,
    ]);

    // CLEARING IS HELD TO THE SAME BAR. A precondition that depends on the value of an argument is
    // the same trap in a smaller size — and undesignating under a context naming another tenant is
    // the stale-worker shape, not a harmless no-op.
    expect(fn (): mixed => $repository->designateRerankConnection($t->a->id, null, $audit))
        ->toThrow(\LogicException::class);
});
