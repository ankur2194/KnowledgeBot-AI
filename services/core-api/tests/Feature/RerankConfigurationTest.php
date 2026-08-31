<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Providers\ProviderConnectionService;
use App\Services\Rerank\RerankDesignationService;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\assertDatabaseHas;

/*
|--------------------------------------------------------------------------
| Phase 1g — the rerank designation
|--------------------------------------------------------------------------
|
| The third per-surface provider choice: `bots.provider_connection_id` decides who ANSWERS,
| `/embedding-configuration` decides who EMBEDS, and `/rerank-configuration` decides who RERANKS.
|
| THIS SUITE ASSERTS FOUR THINGS AND DELIBERATELY NOT A FIFTH.
|
|   1. The designation round-trips: read, set, re-set, clear — with the response rendered off the
|      row the WRITE returned rather than the bound one.
|   2. The validation failures, including the two that are about a record rather than a field.
|   3. The six checks, on the write.
|   4. The audit trail, both operations, with the previous pair.
|
| THE FIFTH — "can this vendor actually rerank" — IS NOT ASSERTED HERE AND MUST NOT BE. That is the
| AND of three axes (`capabilities.PROVIDER_TASKS`, the model row's `capability_flags`, and
| `capabilities.RERANK_SCALE`) and `capabilities.can_rerank` is its only home. There is a test below
| that pins the OPPOSITE: a designation naming a model row claiming no rerank capability at all is
| ACCEPTED here, because refusing it would be one third of that rule re-implemented on the wrong side
| of the seam. The refusal, where there is one, is reached at query time by `rerank_gate` and
| reported on the retrieval trace as `rerank_skip_reason`.
|
| WHY `Http::fake()` APPEARS AND WHAT IT IS FOR. Not to stub a verdict — there is nothing to stub,
| because neither action crosses the seam. It is armed so `Http::assertNothingSent()` can assert
| exactly that, which is the whole boundary decision stated as an assertion rather than as a comment.
*/

/**
 * A real NVIDIA ranking model id — NVIDIA being the one vendor whose rerank scale this platform can
 * currently threshold. Nothing in Laravel knows or checks that, and this file asserts below that it
 * does not; the id is real only so the fixture reads like a configuration somebody would make.
 */
const RERANK_MODEL = 'nvidia/llama-3.2-nv-rerankqa-1b-v2';

/**
 * One organization, one admin, one connection carrying one ranking model row.
 *
 * `recycle()` on every factory: ProviderConnectionFactory refuses to run without a recycled
 * organization precisely because a connection minted into a THIRD organization is what makes a
 * tenancy assertion pass with the filter deleted.
 *
 * @return array{org: Organization, actor: User, connection: ProviderConnection}
 */
function orgWithRerankConnection(OrgRole $role = OrgRole::Admin): array
{
    $org = Organization::factory()->create();

    return [
        'org' => $org,
        'actor' => User::factory()->recycle($org)->orgRole($role)->create(),
        'connection' => ProviderConnection::factory()->recycle($org)
            ->withModel(RERANK_MODEL, ['rerank'])
            ->create(),
    ];
}

function rerankUrl(Organization $organization): string
{
    return "/api/v1/organizations/{$organization->id}/rerank-configuration";
}

it('reports an undesignated organization as degrading to fused order', function (): void {
    // THE DEFAULT STATE, AND IT IS A SUPPORTED MODE RATHER THAN A MISCONFIGURATION. A null
    // embedding designation blocks ingestion outright; a null rerank designation means
    // `rerank_gate` returns MODEL_NOT_CONFIGURED before any call goes out and
    // `evidence.select_unranked` serves fused order.
    Http::fake();

    $fixture = orgWithRerankConnection();

    currentTest()->actingAs($fixture['actor'])
        ->getJson(rerankUrl($fixture['org']))
        ->assertOk()
        ->assertJsonPath('data.designated', null)
        ->assertJsonPath('data.degrades_to_fused_order', true);

    // THE BOUNDARY, AS AN ASSERTION. Reading the rerank configuration is two columns off a row the
    // binding already resolved. If this endpoint ever grows a readiness round trip, it must be a
    // decision with an ADR rather than a line somebody added — and this is what would notice.
    Http::assertNothingSent();
});

it('persists a designation and renders it off the row the write returned', function (): void {
    Http::fake();

    $fixture = orgWithRerankConnection();

    currentTest()->actingAs($fixture['actor'])
        ->putJson(rerankUrl($fixture['org']), [
            'connection_id' => $fixture['connection']->id,
            'model' => RERANK_MODEL,
        ])
        ->assertOk()
        // THE FRESHNESS RULE. The bound organization row still held two nulls when this request was
        // routed, so a `designated` read off it would be null here — which is exactly the bug this
        // assertion exists to catch, and the reason the controller renders off `$written`.
        ->assertJsonPath('data.degrades_to_fused_order', false)
        // TWO KEYS AND NO THIRD. `organizations` stores a connection id and a model string; a
        // `provider` here would be a join publishing a value the designation does not contain.
        ->assertJsonPath('data.designated', [
            'connection_id' => $fixture['connection']->id,
            'model' => RERANK_MODEL,
        ]);

    assertDatabaseHas('organizations', [
        'id' => $fixture['org']->id,
        'rerank_connection_id' => $fixture['connection']->id,
        'rerank_model' => RERANK_MODEL,
    ]);

    Http::assertNothingSent();
});

it('clears the designation and renders the clear off the row the write returned', function (): void {
    Http::fake();

    $fixture = orgWithRerankConnection();
    $url = rerankUrl($fixture['org']);

    currentTest()->actingAs($fixture['actor'])->putJson($url, [
        'connection_id' => $fixture['connection']->id,
        'model' => RERANK_MODEL,
    ])->assertOk();

    // THE OTHER FRESHNESS DIRECTION. The bound row still holds the pair while this request is
    // routed; the response must show it gone.
    currentTest()->actingAs($fixture['actor'])->putJson($url, [
        'connection_id' => null,
        'model' => null,
    ])
        ->assertOk()
        ->assertJsonPath('data.designated', null)
        ->assertJsonPath('data.degrades_to_fused_order', true);

    assertDatabaseHas('organizations', [
        'id' => $fixture['org']->id,
        'rerank_connection_id' => null,
        'rerank_model' => null,
    ]);
});

it('accepts a model row that claims no rerank capability, because that is not this side\'s question', function (): void {
    /*
     * THE BOUNDARY RULE, PINNED AS A POSITIVE. It reads like a missing check and it is a decision.
     *
     * Whether a designation can usefully rerank is the AND of three axes and `can_rerank` is their
     * only home: does the VENDOR publish a ranking route (`PROVIDER_TASKS`), does this ROW claim the
     * capability (`capability_flags`), and can this PLATFORM threshold the scale that comes back
     * (`RERANK_SCALE`, finding #47). A `capability_flags` check here would be one third of that rule
     * on the wrong side of the seam, and it would start refusing configurations the data plane
     * accepts the day either of the other two axes moves.
     *
     * What Laravel enforces is tenancy and existence, and both are enforced: the row below is this
     * organization's, it exists, and that is all this endpoint claims.
     *
     * The consequence is stated rather than hidden: this designation is stored, and stage 11 skips
     * it at query time with `rerank_skip_reason: provider_lacks_capability`. That is visible on the
     * retrieval trace, which is where a capability verdict belongs.
     */
    Http::fake();

    $org = Organization::factory()->create();
    $actor = User::factory()->recycle($org)->orgRole(OrgRole::Admin)->create();
    $chatOnly = ProviderConnection::factory()->recycle($org)
        ->withModel('gpt-5.6-sol', ['text'])
        ->create();

    currentTest()->actingAs($actor)
        ->putJson(rerankUrl($org), ['connection_id' => $chatOnly->id, 'model' => 'gpt-5.6-sol'])
        ->assertOk()
        ->assertJsonPath('data.designated.model', 'gpt-5.6-sol');

    assertDatabaseHas('organizations', [
        'id' => $org->id,
        'rerank_connection_id' => $chatOnly->id,
        'rerank_model' => 'gpt-5.6-sol',
    ]);
});

it('rejects half a designation before it reaches the database', function (): void {
    Http::fake();

    $fixture = orgWithRerankConnection();
    $url = rerankUrl($fixture['org']);

    // A connection with no model names no reranker; a model with no connection names no credential.
    // A half-designation reaches `rerank_gate` as `model = null` — i.e. as "reranking is off", the
    // operator's choice discarded with no error anywhere. The CHECK constraint says the same thing
    // one layer down; this is the layer that can name the field.
    currentTest()->actingAs($fixture['actor'])->putJson($url, [
        'connection_id' => $fixture['connection']->id,
        'model' => null,
    ])
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonStructure(['errors' => ['model']]);

    currentTest()->actingAs($fixture['actor'])->putJson($url, [
        'connection_id' => null,
        'model' => RERANK_MODEL,
    ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['connection_id']]);

    // AND AN OMITTED KEY IS NOT A PARTIAL UPDATE. `present` is what makes "the field was missing"
    // and "the field was null" different things on a PUT of a complete pair — without it, an empty
    // body would silently clear the designation.
    currentTest()->actingAs($fixture['actor'])->putJson($url, [])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['connection_id', 'model']]);

    assertDatabaseHas('organizations', [
        'id' => $fixture['org']->id,
        'rerank_connection_id' => null,
        'rerank_model' => null,
    ]);
});

it('refuses a connection that is not this organization\'s, without saying whether it exists', function (): void {
    // THE ORG-SCOPED EXISTENCE CHECK. A foreign connection is refused by ABSENCE from an org-scoped
    // read — no foreign row is ever loaded to be compared against — so the sentence is identical
    // whether the id belongs to another tenant or to nobody. Distinguishing the two would turn this
    // endpoint into an existence oracle over every organization's credentials.
    //
    // tests/Security/RerankDesignationAccessTest.php is where the real tenancy assertion lives, on
    // tenantPair(); this one pins the MESSAGE and the fact that a nonexistent id gets the same one.
    Http::fake();

    $fixture = orgWithRerankConnection();

    $foreignOrg = Organization::factory()->create();
    $foreignConnection = ProviderConnection::factory()->recycle($foreignOrg)
        ->withModel(RERANK_MODEL, ['rerank'])->create();

    $foreign = currentTest()->actingAs($fixture['actor'])
        ->putJson(rerankUrl($fixture['org']), [
            'connection_id' => $foreignConnection->id,
            'model' => RERANK_MODEL,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonPath('retryable', false)
        ->assertJsonPath('message', RerankDesignationService::NOT_THIS_ORGANIZATIONS_CONNECTION);

    $absent = currentTest()->actingAs($fixture['actor'])
        ->putJson(rerankUrl($fixture['org']), [
            'connection_id' => '01JQZ0000000000000000000ZZ',
            'model' => RERANK_MODEL,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', RerankDesignationService::NOT_THIS_ORGANIZATIONS_CONNECTION);

    // BYTE-IDENTICAL BUT FOR THE REQUEST ID. If these two ever diverge, the endpoint has started
    // telling a caller which of the two situations they are in.
    $foreignBody = $foreign->json();
    $absentBody = $absent->json();

    assert(is_array($foreignBody) && is_array($absentBody));

    unset($foreignBody['request_id'], $absentBody['request_id']);

    expect($foreignBody)->toBe($absentBody);

    assertDatabaseHas('organizations', [
        'id' => $fixture['org']->id,
        'rerank_connection_id' => null,
    ]);
});

it('refuses a pair whose catalog row was deleted after the form was drawn, and rolls back', function (): void {
    // THE DELETE-THEN-DESIGNATE RACE, which is the one the embedding surface had open for a while
    // (finding S6) because `organizations.embedding_model` is a bare text column with no foreign key
    // to `provider_models`. `rerank_model` is the same shape, so the same race exists and is closed
    // the same way: an org-scoped existence check on the pair, inside the transaction, under the
    // `organizations` row lock that `EloquentProviderModelRepository::delete()` also takes.
    //
    // THIS FAILURE IS QUIETER THAN ITS EMBEDDING TWIN, which is why it is worth closing rather than
    // tolerating: a dangling embedding pair breaks the next upload loudly, a dangling rerank pair
    // turns reranking off and the only symptom is answers getting worse.
    Http::fake();

    $fixture = orgWithRerankConnection();

    // The other administrator's delete, committed in between.
    ProviderModelEntry::query()->withoutGlobalScopes()
        ->where('provider_connection_id', '=', $fixture['connection']->id)
        ->delete();

    currentTest()->actingAs($fixture['actor'])
        ->putJson(rerankUrl($fixture['org']), [
            'connection_id' => $fixture['connection']->id,
            'model' => RERANK_MODEL,
        ])
        // `validation` and not a 409: this IS about a field of the submitted body — the pair the
        // operator named — which is what distinguishes it from the connection delete's 409, which is
        // about the state of a different record and has no field to key on.
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonPath('retryable', false);

    // AND THE TRANSACTION ROLLED BACK. Without this the test passes against an implementation that
    // writes the designation and then throws — the caller told no, the dangling pointer committed.
    assertDatabaseHas('organizations', [
        'id' => $fixture['org']->id,
        'rerank_connection_id' => null,
        'rerank_model' => null,
    ]);

    // AND NO AUDIT ROW, because nothing happened. An `organization.rerank_designation.set` row for a
    // designation that was refused would be worse than no row at all.
    expect(AuditLog::query()->where('operation', AuditLogger::RERANK_DESIGNATION_SET)->count())->toBe(0);
});

it('writes one audit row per change, carrying what the change replaced', function (): void {
    /*
     * WHY THIS IS AUDITED AT ALL. These two columns decide which of an organization's credentials
     * sees its END USERS' QUESTIONS and its retrieved chunk text, and which provider account is
     * billed for it. Nothing else records a change to that: `organizations` has no history table,
     * `updated_at` moves for a rename too, and the pair is on no resource a reader can diff.
     *
     * WHY `previous_*`. After the write the old pair exists nowhere — the column has been
     * overwritten — so a row recording only the new value cannot answer "what did this replace".
     */
    Http::fake();

    $fixture = orgWithRerankConnection();
    $url = rerankUrl($fixture['org']);

    $second = ProviderConnection::factory()->recycle($fixture['org'])
        ->withModel('nvidia/nv-rerankqa-mistral-4b-v3', ['rerank'])
        ->create();

    // 1. FIRST DESIGNATION. Nothing was replaced, so the two `previous_*` keys are ABSENT rather
    //    than null — sanitize() skips a null silently, so the absence is the statement.
    currentTest()->actingAs($fixture['actor'])->putJson($url, [
        'connection_id' => $fixture['connection']->id,
        'model' => RERANK_MODEL,
    ])->assertOk();

    /** @var AuditLog $first */
    $first = AuditLog::query()->where('operation', AuditLogger::RERANK_DESIGNATION_SET)->firstOrFail();

    expect($first->organization_id)->toBe($fixture['org']->id)
        ->and($first->actor_id)->toBe($fixture['actor']->id)
        ->and($first->outcome)->toBe(AuditLogger::OUTCOME_SUCCESS)
        ->and($first->subject_id)->toBe($fixture['org']->id)
        ->and($first->details['connection_id'] ?? null)->toBe($fixture['connection']->id)
        ->and($first->details['model'] ?? null)->toBe(RERANK_MODEL)
        ->and($first->details)->not->toHaveKey('previous_connection_id')
        ->and($first->details)->not->toHaveKey('previous_model');

    // 2. REPLACING ONE DESIGNATION WITH ANOTHER. This is the row the `previous_*` pair exists for.
    currentTest()->actingAs($fixture['actor'])->putJson($url, [
        'connection_id' => $second->id,
        'model' => 'nvidia/nv-rerankqa-mistral-4b-v3',
    ])->assertOk();

    /** @var AuditLog $replaced */
    $replaced = AuditLog::query()->where('operation', AuditLogger::RERANK_DESIGNATION_SET)
        ->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();

    expect($replaced->details['connection_id'] ?? null)->toBe($second->id)
        ->and($replaced->details['previous_connection_id'] ?? null)->toBe($fixture['connection']->id)
        ->and($replaced->details['previous_model'] ?? null)->toBe(RERANK_MODEL);

    // 3. THE CLEAR. A SEPARATE OPERATION and not a `set` with nulls, because "did anybody turn
    //    reranking off, and when" has to be a query rather than a scan of every row's details — and
    //    because a `set` row whose sanitizer dropped two nulls would be indistinguishable from it.
    currentTest()->actingAs($fixture['actor'])->putJson($url, [
        'connection_id' => null,
        'model' => null,
    ])->assertOk();

    /** @var AuditLog $cleared */
    $cleared = AuditLog::query()->where('operation', AuditLogger::RERANK_DESIGNATION_CLEARED)->firstOrFail();

    expect($cleared->details['previous_connection_id'] ?? null)->toBe($second->id)
        ->and($cleared->details['previous_model'] ?? null)->toBe('nvidia/nv-rerankqa-mistral-4b-v3')
        // NO FORWARD PAIR ON A CLEAR: after it there is none, and writing the two keys as nulls
        // would be indistinguishable from a `set` row whose sanitizer dropped them.
        ->and($cleared->details)->not->toHaveKey('connection_id')
        ->and($cleared->details)->not->toHaveKey('model');

    // EXACTLY THREE ROWS FROM THREE WRITES. A second row per request would mean the audit call had
    // escaped the repository transaction and was firing twice.
    expect(AuditLog::query()->whereIn('operation', [
        AuditLogger::RERANK_DESIGNATION_SET,
        AuditLogger::RERANK_DESIGNATION_CLEARED,
    ])->count())->toBe(3);
});

it('never writes a provider credential into the audit trail for this surface', function (): void {
    // NON-NEGOTIABLE 9, ASSERTED WITH ITS POSITIVE CONTROL FIRST — a redaction test that passes
    // because nothing was ever encrypted is decoration.
    Http::fake();

    $fixture = orgWithRerankConnection();

    $stored = app(\App\Support\Crypto\CredentialVault::class)->open(
        (string) $fixture['connection']->getAttribute('credential_ciphertext'),
        (string) $fixture['connection']->getAttribute('data_key_ciphertext'),
    );

    expect($stored)->toBe(\Database\Factories\ProviderConnectionFactory::FIXTURE_CREDENTIAL);

    currentTest()->actingAs($fixture['actor'])
        ->putJson(rerankUrl($fixture['org']), [
            'connection_id' => $fixture['connection']->id,
            'model' => RERANK_MODEL,
        ])
        ->assertOk();

    /** @var AuditLog $row */
    $row = AuditLog::query()->where('operation', AuditLogger::RERANK_DESIGNATION_SET)->firstOrFail();

    $encoded = json_encode($row->details, JSON_THROW_ON_ERROR);

    // str_contains reduced to a boolean, never `->not->toContain(...)`: toContain takes only
    // needles, `not` treats any failure as success, and that shape has already hidden a real
    // tenant-id leak in this repository.
    expect(str_contains($encoded, \Database\Factories\ProviderConnectionFactory::FIXTURE_CREDENTIAL))
        ->toBeFalse('the audit details carry the plaintext provider credential')
        ->and(str_contains($encoded, 'last_four'))->toBeFalse()
        ->and(str_contains($encoded, 'ciphertext'))->toBeFalse();
});

it('runs all six checks on the designation endpoint', function (): void {
    Http::fake();

    $fixture = orgWithRerankConnection();

    $body = ['connection_id' => $fixture['connection']->id, 'model' => RERANK_MODEL];
    $url = rerankUrl($fixture['org']);

    // CHECK 1 — authenticated identity.
    currentTest()->putJson($url, $body)->assertStatus(401)
        ->assertJsonPath('error_class', 'authentication');

    // CHECK 2 — organization membership, re-read from PostgreSQL. An admin of SOME organization is
    // the cross-tenant bug; this one is an owner of a DIFFERENT one.
    $otherOrg = Organization::factory()->create();
    $outsider = User::factory()->recycle($otherOrg)->orgRole(OrgRole::Owner)->create();

    currentTest()->actingAs($outsider)->putJson($url, $body)->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');

    // CHECKS 3 and 4 — role, resolved against THE RECORD's organization. An Analyst is a real,
    // active member of the right organization and still may not decide which credential reranks.
    $analyst = User::factory()->recycle($fixture['org'])->orgRole(OrgRole::Analyst)->create();

    currentTest()->actingAs($analyst)->putJson($url, $body)->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');

    // CHECK 5 — entity status. The check reviewers forget, because it is not visible in the route
    // file the way the first four are.
    $suspended = Organization::factory()->suspended()->create();
    $suspendedActor = User::factory()->recycle($suspended)->orgRole(OrgRole::Owner)->create();

    currentTest()->actingAs($suspendedActor)
        ->putJson(rerankUrl($suspended), ['connection_id' => null, 'model' => null])
        ->assertStatus(409)
        // AND THE SENTENCE, not an empty `message`. A 409 with no message is rendered by
        // bootstrap/app.php as `internal_dependency`, whose class-mapped client copy is "Something
        // on our side is unavailable. Try again shortly." — false twice over.
        ->assertJsonPath('message', OrganizationStatus::SUSPENDED_REFUSAL);

    // CHECK 6 — `throttle:admin`, on the route group. It keys on (organization, user), so it is not
    // exercised by a handful of requests here; what is asserted is that the route carries it.
    //
    // THE NAME CARRIES THE `admin.` PREFIX bootstrap/app.php puts on the whole api_admin.php group.
    // The route file spells it `rerank-configuration.update`; the registered name is the prefixed
    // one, and asking for the unprefixed spelling returns null — which fails here as though the
    // route did not exist at all.
    $route = app(\Illuminate\Routing\Router::class)->getRoutes()
        ->getByName('admin.rerank-configuration.update');

    assert($route instanceof \Illuminate\Routing\Route);

    expect($route->gatherMiddleware())->toContain('throttle:admin', 'auth:sanctum', 'org.member', 'verified');
});

it('lets a knowledge manager read the designation and not change it', function (): void {
    // §6.4 excludes provider credentials, and the designation is on the credential side of that
    // line: it selects which credential pays. Reading it is different — an ingestion operator
    // diagnosing answer quality needs to be able to see whether reranking is on at all.
    //
    // THIS IS WHAT PROVES THE TWO NEW POLICY ABILITIES ARE WIRED TO THE RIGHT SIDE OF THE LINE.
    // No new `Permission` case was minted for them (see OrganizationPolicy), so
    // tests/Unit/RolePermissionMatrixTest.php is unchanged by this surface and cannot say so.
    Http::fake();

    $fixture = orgWithRerankConnection();
    $manager = User::factory()->recycle($fixture['org'])->orgRole(OrgRole::KnowledgeManager)->create();

    $url = rerankUrl($fixture['org']);

    currentTest()->actingAs($manager)->getJson($url)->assertOk();

    currentTest()->actingAs($manager)->putJson($url, [
        'connection_id' => $fixture['connection']->id,
        'model' => RERANK_MODEL,
    ])->assertStatus(403)->assertJsonPath('error_class', 'authorization');
});

it('refuses to move an organization by over-posting the ownership column', function (): void {
    Http::fake();

    $fixture = orgWithRerankConnection();
    $otherOrg = Organization::factory()->create();

    currentTest()->actingAs($fixture['actor'])
        ->putJson(rerankUrl($fixture['org']), [
            'connection_id' => $fixture['connection']->id,
            'model' => RERANK_MODEL,
            'organization_id' => $otherOrg->id,
        ])
        ->assertOk();

    // The FormRequest has no `organization_id` rule, so validated() never carries it and no DTO has
    // a field for it. Over-posting a tenant key is an authorization bug with a 200 response; the
    // 200 here is correct precisely because the key went nowhere.
    assertDatabaseHas('organizations', [
        'id' => $fixture['org']->id,
        'rerank_connection_id' => $fixture['connection']->id,
    ]);

    assertDatabaseHas('organizations', [
        'id' => $otherOrg->id,
        'rerank_connection_id' => null,
        'rerank_model' => null,
    ]);
});

it('refuses with 409 to delete a connection that is the designated reranker', function (): void {
    /*
     * ADR-049's GUARD, WITH ITS SECOND ARM. The composite ON DELETE RESTRICT on
     * `organizations.rerank_connection_id` is the authority; this pre-flight check is the actionable
     * sentence. It sits BESIDE the embedding arm in the same action rather than in a second place.
     *
     * THE SENTENCE IS DIFFERENT FROM EMBEDDING'S BECAUSE THE CONSEQUENCE IS DIFFERENT. Deleting the
     * embedding connection strands an indexed corpus in a vector space nothing can reproduce.
     * Deleting the reranker breaks nothing — which is exactly why RESTRICT is right: a SET NULL
     * would stop reranking with no error, no metric jump on any single request, and nothing on any
     * response to say so.
     */
    Http::fake();

    $fixture = orgWithRerankConnection();

    currentTest()->actingAs($fixture['actor'])->putJson(rerankUrl($fixture['org']), [
        'connection_id' => $fixture['connection']->id,
        'model' => RERANK_MODEL,
    ])->assertOk();

    $deleteUrl = "/api/v1/organizations/{$fixture['org']->id}/provider-connections/{$fixture['connection']->id}";

    currentTest()->actingAs($fixture['actor'])->deleteJson($deleteUrl)
        ->assertStatus(409)
        ->assertJsonPath('message', ProviderConnectionService::DESIGNATED_FOR_RERANK);

    // AND NOTHING WAS DELETED — not the connection, not its catalog rows. A 409 that had already
    // removed the children would leave the organization designating a connection with no model.
    assertDatabaseHas('provider_connections', ['id' => $fixture['connection']->id]);
    assertDatabaseHas('provider_models', [
        'provider_connection_id' => $fixture['connection']->id,
        'model' => RERANK_MODEL,
    ]);

    // THE POSITIVE CONTROL: turning reranking off first releases the guard, which is precisely the
    // remedy the sentence names. Without this the test also passes against a controller that
    // refuses every delete.
    currentTest()->actingAs($fixture['actor'])->putJson(rerankUrl($fixture['org']), [
        'connection_id' => null,
        'model' => null,
    ])->assertOk();

    currentTest()->actingAs($fixture['actor'])->deleteJson($deleteUrl)->assertOk();
});

it('names the embedding designation first when a connection is both', function (): void {
    // ONE CONNECTION CAN HOLD BOTH DESIGNATIONS, AND THEN ONLY ONE SENTENCE CAN BE RETURNED. The
    // embedding one is checked first because it names the worse consequence AND has to be cleared
    // anyway; clearing it and retrying then produces the rerank refusal, so the operator is told
    // both, in the order that costs least to act on. Asserted rather than left to argument, because
    // "which arm fires" is exactly the sort of thing a later edit reorders without noticing.
    $org = Organization::factory()->create();
    $actor = User::factory()->recycle($org)->orgRole(OrgRole::Admin)->create();

    // ONE CREDENTIAL, TWO TASK-EXCLUSIVE ROWS. That is a legitimate configuration and not a
    // contrivance: `capabilities` records that a row claiming `embedding` never also claims chat or
    // rerank flags, because they are different products reached through different endpoints — but a
    // CONNECTION is one credential and may carry a row for each.
    $connection = ProviderConnection::factory()->recycle($org)
        ->withModel('text-embedding-3-large', ['embedding'])
        ->withModel(RERANK_MODEL, ['rerank'])
        ->create();

    $candidate = [
        'connection_id' => $connection->id,
        'provider' => 'openai',
        'model' => 'text-embedding-3-large',
        'caps' => ['supported' => ['embedding'], 'context_window' => 8192, 'max_output_tokens' => 0],
    ];

    Http::fake([
        '*/internal/v1/embedding/readiness' => Http::response([
            'selected' => $candidate,
            'eligible' => [$candidate],
            'rejected' => [],
            'explanation' => '',
        ], 200),
    ]);

    currentTest()->actingAs($actor)->putJson(rerankUrl($org), [
        'connection_id' => $connection->id,
        'model' => RERANK_MODEL,
    ])->assertOk();

    currentTest()->actingAs($actor)
        ->putJson("/api/v1/organizations/{$org->id}/embedding-configuration", [
            'connection_id' => $connection->id,
            'model' => 'text-embedding-3-large',
        ])->assertOk();

    currentTest()->actingAs($actor)
        ->deleteJson("/api/v1/organizations/{$org->id}/provider-connections/{$connection->id}")
        ->assertStatus(409)
        ->assertJsonPath('message', ProviderConnectionService::DESIGNATED_FOR_EMBEDDING);
});
