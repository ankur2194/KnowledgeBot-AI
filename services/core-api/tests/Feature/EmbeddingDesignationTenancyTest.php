<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\User;
use App\Services\Embedding\EmbeddingDesignation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\assertDatabaseHas;

/*
|--------------------------------------------------------------------------
| The designation cannot point at another organization's credential
|--------------------------------------------------------------------------
|
| WHY THIS IS WORTH ITS OWN FILE. The consequence of getting it wrong is not the usual read leak.
| A designation naming another organization's connection means THIS tenant's chunk text is sent to
| THAT tenant's provider account — billed to them, and readable in their provider dashboard — while
| every downstream layer agrees, because you have told it whose credential embeds.
|
| Three layers are asserted, and each one alone would be enough on a good day:
|   1. the ORG-SCOPED CANDIDATE QUERY, so a foreign connection never reaches the resolver at all;
|   2. the resolver, which answers "not among this organization's connections";
|   3. the COMPOSITE FOREIGN KEY (id, embedding_connection_id) -> provider_connections
|      (organization_id, id), which refuses the row even if both layers above were bypassed.
*/

it('never lets one organization designate another\'s connection', function (): void {
    $orgA = Organization::factory()->create();
    $actorA = User::factory()->recycle($orgA)->orgRole(OrgRole::Owner)->create();
    ProviderConnection::factory()->recycle($orgA)
        ->withModel('text-embedding-3-large', ['embedding'])->create();

    $orgB = Organization::factory()->create();
    $connectionB = ProviderConnection::factory()->recycle($orgB)
        ->withModel('text-embedding-3-large', ['embedding'])->create();

    // The resolver's answer when a designation names something outside the candidate set it was
    // given. Note it never reads a foreign row to say so — it says so from the absence.
    $explanation = 'The designated embedding connection '.$connectionB->id.' -> '
        .'text-embedding-3-large is not among this organization\'s connections.';

    Http::fake(['*/internal/v1/embedding/readiness' => Http::response([
        'selected' => null, 'eligible' => [], 'rejected' => [], 'explanation' => $explanation,
    ], 200)]);

    currentTest()->actingAs($actorA)
        ->putJson("/api/v1/organizations/{$orgA->id}/embedding-configuration", [
            'connection_id' => $connectionB->id,
            'model' => 'text-embedding-3-large',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation');

    assertDatabaseHas('organizations', [
        'id' => $orgA->id,
        'embedding_connection_id' => null,
    ]);

    // LAYER 1, asserted directly: Org B's connection id never left this process on Org A's behalf.
    // Positive control first — Org A's OWN connection did.
    $body = '';
    Http::assertSent(function (\Illuminate\Http\Client\Request $request) use (&$body): bool {
        $body = $request->body();

        return true;
    });

    $ownConnection = ProviderConnection::query()->withoutGlobalScopes()
        ->where('organization_id', $orgA->id)->firstOrFail();

    expect(str_contains($body, $ownConnection->id))->toBeTrue();
    expect(str_contains($body, $orgB->id))->toBeFalse();
});

it('is refused by the database even when every layer above it is bypassed', function (): void {
    // LAYER 3, on its own. This writes the column directly — no FormRequest, no policy, no
    // service, no resolver — which is exactly the situation a composite foreign key exists for:
    // a future code path nobody has written yet, a repair script, a console command.
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $connectionB = ProviderConnection::factory()->recycle($orgB)->create();

    expect(function () use ($orgA, $connectionB): void {
        $orgA->embedding_connection_id = $connectionB->id;
        $orgA->embedding_model = 'text-embedding-3-large';
        $orgA->save();
    })->toThrow(QueryException::class);

    // NO assertDatabaseHas AFTERWARDS, and its absence is not an oversight. RefreshDatabase wraps
    // each test in one transaction, and a constraint violation puts PostgreSQL into 25P02 — every
    // subsequent statement on that connection is refused until the block ends. A follow-up
    // assertion would therefore fail with "current transaction is aborted", which reads as a
    // schema problem rather than as the constraint doing its job. The raised QueryException IS the
    // assertion: the row was refused by the database, which is the only thing this test claims.
});

it('refuses half a designation at the database too', function (): void {
    // The CHECK num_nonnulls(...) <> 1. The FormRequest says the same thing where it can name the
    // field; this is the layer that holds when nothing above it ran.
    $org = Organization::factory()->create();

    expect(function () use ($org): void {
        $org->embedding_model = 'text-embedding-3-large';
        $org->save();
    })->toThrow(QueryException::class);
});

it('refuses to delete a connection that is currently designated', function (): void {
    // ON DELETE RESTRICT rather than SET NULL, deliberately. SET NULL would silently return the
    // organization to "resolve by rule" — and with a second embedding-capable connection present,
    // the rule could then select a DIFFERENT (provider, model) than the corpus was indexed under.
    // Nothing would raise; only answer quality would move.
    $org = Organization::factory()->create();
    $connection = ProviderConnection::factory()->recycle($org)
        ->withModel('text-embedding-3-large', ['embedding'])->create();

    app(\App\Repositories\Contracts\OrganizationRepositoryInterface::class)
        ->designateEmbeddingConnection(
            $org->id,
            new EmbeddingDesignation($connection->id, 'text-embedding-3-large'),
        );

    expect(fn (): mixed => ProviderConnection::query()->withoutGlobalScopes()
        ->whereKey($connection->id)->delete())->toThrow(QueryException::class);
});

it('never answers with the ambient tenant\'s rows when asked for another organization', function (): void {
    /*
     * THIS IS THE TEST THAT DISTINGUISHES THE MECHANISM FROM THE BACKSTOP, and it was written
     * because the obvious version does not. Asserting "Org A's request does not carry Org B's
     * connections" passes with the explicit `where organization_id = $organizationId` DELETED,
     * because the #[ScopedBy] global scope reads the same bound context and quietly supplies the
     * predicate — so the assertion is green while the mechanism is gone. Verified by mutation.
     *
     * What the explicit argument actually defends is a context that DISAGREES with the argument:
     * a pooled queue worker holding the previous tenant, an Octane request whose context was never
     * reset, an admin impersonation flow. Simulated exactly here by binding Org B's context and
     * asking for Org A's candidates.
     *
     * With both layers present the answer is empty — the two predicates conflict, which is the
     * fail-closed direction. With only the global scope, the answer is ORG B'S ROWS RETURNED UNDER
     * ORG A'S IDENTITY, which is the leak: Org A's corpus would then be embedded through Org B's
     * credential, billed to Org B, and visible in Org B's provider dashboard.
     */
    $orgA = Organization::factory()->create();
    ProviderConnection::factory()->recycle($orgA)
        ->withModel('A-EMBEDDER-01JQZ', ['embedding'])->create();

    $orgB = Organization::factory()->create();
    ProviderConnection::factory()->recycle($orgB)
        ->withModel('B-EMBEDDER-01JQZ', ['embedding'])->create();

    $repository = app(\App\Repositories\Contracts\EmbeddingCandidateRepositoryInterface::class);
    $context = app(\App\Support\Tenancy\TenantContext::class);

    // POSITIVE CONTROL FIRST: with context and argument agreeing, the repository really does
    // return something. Without this line the assertion below also passes when the query is
    // broken and returns nothing at all.
    $agreeing = $context->runFor($orgB->id, fn (): array => $repository->forOrg($orgB->id));

    expect($agreeing)->toHaveCount(1)
        ->and($agreeing[0]->model)->toBe('B-EMBEDDER-01JQZ');

    // THE ASSERTION. The stale-context worker.
    $disagreeing = $context->runFor($orgB->id, fn (): array => $repository->forOrg($orgA->id));

    expect($disagreeing)->toBe([]);
});

it('returns nothing at all when no tenant context is bound', function (): void {
    // The global scope's empty-context branch, asserted rather than assumed. A query that returns
    // nothing is a loud, immediate, debuggable failure; the alternative — applying no predicate —
    // returns EVERY tenant's rows from a pooled worker whose context was never set, with no
    // exception anywhere.
    $org = Organization::factory()->create();
    ProviderConnection::factory()->recycle($org)->create();

    expect(ProviderConnection::query()->count())->toBe(0);

    // Positive control: the row does exist, and is visible once a context is bound.
    app(\App\Support\Tenancy\TenantContext::class)->runFor($org->id, function (): void {
        expect(ProviderConnection::query()->count())->toBe(1);
    });

    // ...and is gone again afterwards, because runFor() clears in a `finally`. A context that
    // outlives its request is how a pooled worker writes as the previous tenant.
    expect(ProviderConnection::query()->count())->toBe(0);
});
