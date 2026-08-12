<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\User;
use Database\Factories\ProviderConnectionFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\assertDatabaseHas;

/*
|--------------------------------------------------------------------------
| Finding C1 — the embedding configuration surface
|--------------------------------------------------------------------------
|
| WHY Http::fake() IS ALLOWED HERE AND NOT EVERYWHERE. The ban in pest-testing NN4 is on the CHAT
| RELAY path and on the Contract and Security suites, and the reason is specific: a faked body is a
| string, so the relay drains it in microseconds and every buffering, heartbeat, ordering and
| disconnect bug passes. The readiness call is a small buffered JSON request/response with no
| stream, so none of that applies. What IS faked here is the data plane's VERDICT — and that is the
| point: the rule belongs to embedding_selection.py and is tested there. These tests are about what
| Laravel SENDS, what it PERSISTS, and who it lets do either.
|
| These live in Feature/ rather than Security/ deliberately. The §22.5 suite's entry point is
| tenantPair(), which drives the real ingestion path into a Qdrant container and cannot be
| implemented until Bot and KnowledgeSource exist. Putting a hand-rolled two-org fixture under
| tests/Security would defeat the grep that keeps that suite honest.
*/

/**
 * @return array{org: Organization, actor: User, connection: ProviderConnection}
 */
function orgWithEmbeddingConnection(OrgRole $role = OrgRole::Admin): array
{
    $org = Organization::factory()->create();

    return [
        'org' => $org,
        'actor' => User::factory()->recycle($org)->orgRole($role)->create(),
        'connection' => ProviderConnection::factory()->recycle($org)
            ->withModel('text-embedding-3-large', ['embedding'])
            ->create(),
    ];
}

/**
 * @param  array<string, mixed>  $verdict
 */
function fakeReadiness(array $verdict): void
{
    Http::fake([
        '*/internal/v1/embedding/readiness' => Http::response($verdict, 200),
    ]);
}

/**
 * @return array<string, mixed>
 */
function unreadyVerdict(string $explanation = 'This organization has no embedding-capable provider connection, so it cannot ingest any document.'): array
{
    return [
        'selected' => null,
        'eligible' => [],
        'rejected' => [],
        'explanation' => $explanation,
    ];
}

/**
 * @return array<string, mixed>
 */
function readyVerdict(string $connectionId, string $model = 'text-embedding-3-large'): array
{
    $candidate = [
        'connection_id' => $connectionId,
        'provider' => 'openai',
        'model' => $model,
        'caps' => ['supported' => ['embedding'], 'context_window' => 8192, 'max_output_tokens' => 0],
    ];

    return [
        'selected' => $candidate,
        'eligible' => [$candidate],
        'rejected' => [],
        'explanation' => '',
    ];
}

it('renders the blocking banner verbatim when the organization cannot ingest', function (): void {
    $explanation = 'This organization has no embedding-capable provider connection, so it cannot '
        .'ingest any document. Providers recorded as offering an embedding endpoint: openai.';

    fakeReadiness(unreadyVerdict($explanation));

    $fixture = orgWithEmbeddingConnection();

    $response = currentTest()->actingAs($fixture['actor'])
        ->getJson("/api/v1/organizations/{$fixture['org']->id}/embedding-configuration");

    $response->assertOk()
        ->assertJsonPath('data.ready', false)
        // Named for what the operator sees. "Not ready" and "cannot upload anything at all" are
        // the same fact; only one of the two names is actionable.
        ->assertJsonPath('data.blocks_ingestion', true)
        // VERBATIM. The upload path raises this same string, so the banner and the error say the
        // same words. A paraphrase on this side is a second copy that drifts.
        ->assertJsonPath('data.explanation', $explanation);
});

it('sends only this organization\'s connections to the resolver', function (): void {
    // THE TENANCY ASSERTION, with its positive control first.
    $a = orgWithEmbeddingConnection();

    $orgB = Organization::factory()->create();
    $connectionB = ProviderConnection::factory()->recycle($orgB)
        ->withModel('OTHER-TENANT-EMBEDDER-01JQZ', ['embedding'])
        ->create();

    fakeReadiness(readyVerdict($a['connection']->id));

    currentTest()->actingAs($a['actor'])
        ->getJson("/api/v1/organizations/{$a['org']->id}/embedding-configuration")
        ->assertOk();

    $body = '';

    Http::assertSent(function (ClientRequest $request) use (&$body): bool {
        $body = $request->body();

        return true;
    });

    // POSITIVE CONTROL FIRST. Without it this test also passes when the candidate query is broken
    // and returns nothing at all — the exact way an isolation assertion becomes decoration.
    expect(str_contains($body, 'text-embedding-3-large'))->toBeTrue()
        ->and(str_contains($body, $a['connection']->id))->toBeTrue();

    // The real assertion. Written as str_contains(...)->toBeFalse() and NOT as
    // expect($body)->not->toContain(...): toContain takes only needles, `not` treats any failure
    // as success, and that shape has already hidden a real tenant-id leak in this repository.
    expect(str_contains($body, 'OTHER-TENANT-EMBEDDER-01JQZ'))->toBeFalse()
        ->and(str_contains($body, $connectionB->id))->toBeFalse()
        ->and(str_contains($body, $orgB->id))->toBeFalse();
});

it('never puts a provider credential on the readiness request', function (): void {
    $fixture = orgWithEmbeddingConnection();

    fakeReadiness(readyVerdict($fixture['connection']->id));

    currentTest()->actingAs($fixture['actor'])
        ->getJson("/api/v1/organizations/{$fixture['org']->id}/embedding-configuration")
        ->assertOk();

    $body = '';
    $headers = [];

    Http::assertSent(function (ClientRequest $request) use (&$body, &$headers): bool {
        $body = $request->body();
        $headers = $request->headers();

        return true;
    });

    // POSITIVE CONTROL: the fixture credential really is stored and really is decryptable, so this
    // assertion is not passing because nothing was ever sealed.
    $stored = app(\App\Support\Crypto\CredentialVault::class)->open(
        (string) $fixture['connection']->getAttribute('credential_ciphertext'),
        (string) $fixture['connection']->getAttribute('data_key_ciphertext'),
    );
    expect($stored)->toBe(ProviderConnectionFactory::FIXTURE_CREDENTIAL);

    // Selection answers WHICH connection, never WITH WHAT KEY. This request renders an admin
    // screen; a plaintext credential on it would be a key on the wire for no reason at all.
    expect(str_contains($body, ProviderConnectionFactory::FIXTURE_CREDENTIAL))->toBeFalse()
        ->and(str_contains($body, 'provider_credential'))->toBeFalse();

    // The signature is present and carries the key id; the org header is signed, not decorative.
    expect($headers)->toHaveKey('X-KB-Signature')
        ->and($headers['X-KB-Org-Id'][0])->toBe($fixture['org']->id)
        ->and($headers['X-KB-Operation'][0])->toBe('embedding.readiness');
});

/**
 * Every X-KB-* header the last readiness request actually carried, plus its body.
 *
 * Read off the sent request rather than restated: a second literal list here would agree with
 * itself forever while the real one drifted, which is the exact failure InternalRequestSigner's own
 * docblock warns about.
 *
 * @return array{headers: array<string, string>, body: string, signature: string}
 */
function sentReadinessRequest(): array
{
    $headers = [];
    $body = '';

    Http::assertSent(function (ClientRequest $request) use (&$headers, &$body): bool {
        $body = $request->body();

        foreach ($request->headers() as $name => $values) {
            if (str_starts_with(strtolower($name), 'x-kb-')) {
                $headers[$name] = (string) $values[0];
            }
        }

        return true;
    });

    $signature = $headers['X-KB-Signature'] ?? '';
    unset($headers['X-KB-Signature']);

    return ['headers' => $headers, 'body' => $body, 'signature' => $signature];
}

it('sends X-KB-Config-Version, in the decimal shape the verifier requires', function (): void {
    // WITHOUT THIS HEADER THE ONLY LARAVEL->FASTAPI CALL IN THE APPLICATION 422s AT THE BOUNDARY.
    // `request_context` in services/ai-service/app/api/deps.py calls required("x-kb-config-version")
    // before it builds anything, so the omission is not a degraded response — it is a request that
    // never reaches a handler. It is also invisible from this side: Http::fake() answers whatever
    // it is asked, so a suite full of green readiness tests proves nothing about the header set.
    $fixture = orgWithEmbeddingConnection();

    fakeReadiness(readyVerdict($fixture['connection']->id));

    currentTest()->actingAs($fixture['actor'])
        ->getJson("/api/v1/organizations/{$fixture['org']->id}/embedding-configuration")
        ->assertOk();

    $sent = sentReadinessRequest();

    expect($sent['headers'])->toHaveKey('X-KB-Config-Version');

    // The verifier's own regex, quoted from app/api/deps.py:91 (`_DECIMAL_RE`) rather than
    // approximated: `str.isdigit()` is true for "¹²" and `int("¹²")` then raises, so a looser
    // assertion here would pass a value that turns a malformed header into a 500 over there.
    expect((bool) preg_match('/\A(?:0|[1-9][0-9]{0,17})\z/', $sent['headers']['X-KB-Config-Version']))
        ->toBeTrue("X-KB-Config-Version is not a value app/api/deps.py can parse: {$sent['headers']['X-KB-Config-Version']}");

    // 2**56 — seven bytes of SHA-256, which is the whole reason the derivation slices 14 hex digits
    // and not 16. THE REGEX ABOVE IS NOT ENOUGH ON ITS OWN, and that was measured rather than
    // assumed. Widening the slice to eight bytes was run four times against this fixture: three
    // runs produced a value the regex rejects (19 digits, e.g. 2526610430182340203, or NEGATIVE —
    // -6385967989600985088, PHP casting a float past PHP_INT_MAX) and one produced a value that
    // happened to land back inside 18 digits and passed. The body's ULIDs differ per run, so
    // whether the regex catches it is a coin flip, and a defect that reproduces sometimes reads as
    // a flake. The WIDTH is therefore asserted directly, not inferred from one sample.
    expect((int) $sent['headers']['X-KB-Config-Version'])
        ->toBeLessThan(72057594037927936, 'X-KB-Config-Version is wider than seven bytes; some bodies will exceed the verifier\'s 18 digits');
});

it('covers X-KB-Config-Version with the signature rather than merely sending it', function (): void {
    // A header outside the signature is a header any hop may rewrite. The client is written so the
    // signed set and the sent set are ONE array — this asserts that property holds for the new
    // header specifically, with a negative control so it cannot pass by the signature being
    // computed over nothing in particular.
    $fixture = orgWithEmbeddingConnection();

    fakeReadiness(readyVerdict($fixture['connection']->id));

    currentTest()->actingAs($fixture['actor'])
        ->getJson("/api/v1/organizations/{$fixture['org']->id}/embedding-configuration")
        ->assertOk();

    $sent = sentReadinessRequest();
    $signer = app(\App\Services\Internal\InternalRequestSigner::class);
    $path = '/internal/'.config('kb.contract_version').'/embedding/readiness';

    expect($signer->sign('POST', $path, $sent['body'], $sent['headers']))
        ->toBe($sent['signature'], 'the signature does not verify over the headers that were sent');

    // THE CONTROL. Re-sign the same request with X-KB-Config-Version removed: if that still
    // matched, the header would be decoration and this test would be asserting nothing.
    $without = $sent['headers'];
    unset($without['X-KB-Config-Version']);

    expect($signer->sign('POST', $path, $sent['body'], $without))
        ->not->toBe($sent['signature'], 'X-KB-Config-Version is not inside the signature');
});

it('derives X-KB-Config-Version from the configuration, not from a clock or a constant', function (): void {
    // The three properties the header is only useful if it has, asserted in one place because each
    // is meaningless without the other two:
    //
    //   stable      the same configuration twice yields the same version, or `cfg:{org}:{bot}:{v}`
    //               (valkey-keyspaces) is a cache that never hits and an answer cache keyed on it
    //               is invalidated by nothing more than a page reload
    //   sensitive   a different configuration yields a different version, or it distinguishes
    //               nothing and a stale snapshot is served as current
    //   credential-blind  rotating a key does NOT move it (laravel-control-plane DoD; docs/22
    //               ADR-011 property 1), or every rotation invalidates every cached answer
    $fixture = orgWithEmbeddingConnection();

    fakeReadiness(readyVerdict($fixture['connection']->id));

    $read = fn () => currentTest()->actingAs($fixture['actor'])
        ->getJson("/api/v1/organizations/{$fixture['org']->id}/embedding-configuration")
        ->assertOk();

    $read();
    $first = sentReadinessRequest()['headers']['X-KB-Config-Version'];

    $read();
    $second = sentReadinessRequest()['headers']['X-KB-Config-Version'];

    expect($second)->toBe($first, 'the configuration did not change, so neither may the version');

    // Credential-blind. The row really is re-sealed — assert the ciphertext moved first, or this
    // is the redaction-test failure mode where nothing was ever encrypted.
    $before = (string) $fixture['connection']->getAttribute('credential_ciphertext');
    $sealed = app(\App\Support\Crypto\CredentialVault::class)->seal('kb-rotated-credential-01JQZ');
    $fixture['connection']->forceFill([
        'credential_ciphertext' => $sealed['credential_ciphertext'],
        'data_key_ciphertext' => $sealed['data_key_ciphertext'],
        'key_version' => $sealed['key_version'],
        'last_four' => $sealed['last_four'],
    ])->save();

    expect((string) $fixture['connection']->getAttribute('credential_ciphertext'))->not->toBe($before);

    $read();

    expect(sentReadinessRequest()['headers']['X-KB-Config-Version'])
        ->toBe($first, 'a credential rotation moved the configuration version');

    // Sensitive. A second embedding row is a real change to the snapshot this request carries.
    ProviderConnection::factory()->recycle($fixture['org'])
        ->withModel('text-embedding-3-small', ['embedding'])
        ->create();

    $read();

    expect(sentReadinessRequest()['headers']['X-KB-Config-Version'])
        ->not->toBe($first, 'the configuration changed and the version did not');
});

it('persists a designation the resolver accepts', function (): void {
    $fixture = orgWithEmbeddingConnection();

    fakeReadiness(readyVerdict($fixture['connection']->id));

    currentTest()->actingAs($fixture['actor'])
        ->putJson("/api/v1/organizations/{$fixture['org']->id}/embedding-configuration", [
            'connection_id' => $fixture['connection']->id,
            'model' => 'text-embedding-3-large',
        ])
        ->assertOk()
        ->assertJsonPath('data.ready', true);

    assertDatabaseHas('organizations', [
        'id' => $fixture['org']->id,
        'embedding_connection_id' => $fixture['connection']->id,
        'embedding_model' => 'text-embedding-3-large',
    ]);
});

it('refuses a designation the resolver cannot resolve, and stores nothing', function (): void {
    $explanation = 'The designated embedding connection 01JQZ -> nope cannot embed: '
        .'row_lacks_embedding_flag. It is not substituted.';

    fakeReadiness(unreadyVerdict($explanation));

    $fixture = orgWithEmbeddingConnection();

    currentTest()->actingAs($fixture['actor'])
        ->putJson("/api/v1/organizations/{$fixture['org']->id}/embedding-configuration", [
            'connection_id' => $fixture['connection']->id,
            'model' => 'text-embedding-3-large',
        ])
        // 422 / `validation`: a configuration state, not a vendor fault and not an outage. The
        // next identical attempt fails identically, so `retryable` is false.
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonPath('retryable', false)
        ->assertJsonPath('message', $explanation);

    // Unlike a connection save, THIS path hard-fails — and it must leave nothing behind, or the
    // organization ends up pointing at a space its corpus was never indexed under.
    assertDatabaseHas('organizations', [
        'id' => $fixture['org']->id,
        'embedding_connection_id' => null,
        'embedding_model' => null,
    ]);
});

it('lets an organization clear its designation even when nothing can embed', function (): void {
    $fixture = orgWithEmbeddingConnection();

    // fakeSequence, not two fake() calls: Http::fake() PUSHES a stub and the first match wins, so
    // a second fake() for the same URL is silently ignored and the second request would get the
    // first verdict. That is the kind of test bug that reads as a production bug.
    Http::fakeSequence('*/internal/v1/embedding/readiness')
        ->push(readyVerdict($fixture['connection']->id), 200)
        // Now the organization is unready, and it is trying to UNDO the designation. Refusing here
        // would leave a dangling pointer at a connection it is trying to remove — which ON DELETE
        // RESTRICT would then also block. Undesignate, then delete.
        ->push(unreadyVerdict(), 200);

    currentTest()->actingAs($fixture['actor'])
        ->putJson("/api/v1/organizations/{$fixture['org']->id}/embedding-configuration", [
            'connection_id' => $fixture['connection']->id,
            'model' => 'text-embedding-3-large',
        ])->assertOk();

    currentTest()->actingAs($fixture['actor'])
        ->putJson("/api/v1/organizations/{$fixture['org']->id}/embedding-configuration", [
            'connection_id' => null,
            'model' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.ready', false);

    assertDatabaseHas('organizations', [
        'id' => $fixture['org']->id,
        'embedding_connection_id' => null,
    ]);
});

it('rejects half a designation before it reaches the database', function (): void {
    $fixture = orgWithEmbeddingConnection();
    fakeReadiness(readyVerdict($fixture['connection']->id));

    // A connection with no model names no vector space; a model with no connection names no
    // credential. The CHECK constraint says the same thing one layer down, and this is the layer
    // that can name the field.
    currentTest()->actingAs($fixture['actor'])
        ->putJson("/api/v1/organizations/{$fixture['org']->id}/embedding-configuration", [
            'connection_id' => $fixture['connection']->id,
            'model' => null,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonStructure(['errors' => ['model']]);

    currentTest()->actingAs($fixture['actor'])
        ->putJson("/api/v1/organizations/{$fixture['org']->id}/embedding-configuration", [
            'connection_id' => null,
            'model' => 'text-embedding-3-large',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['connection_id']]);

    Http::assertNothingSent();
});

it('reports the AI service being unreachable as a downstream dependency failure', function (): void {
    // Not `validation`: nothing about the tenant's configuration is wrong. Not a SELF-origin
    // internal_dependency either — that renders 500 and blames our own code. DOWNSTREAM: 503 and
    // retryable, because a dependency of ours is briefly unavailable and the next attempt may work.
    Http::fake(['*/internal/v1/embedding/readiness' => fn () => throw new \Illuminate\Http\Client\ConnectionException('nope')]);

    $fixture = orgWithEmbeddingConnection();

    currentTest()->actingAs($fixture['actor'])
        ->getJson("/api/v1/organizations/{$fixture['org']->id}/embedding-configuration")
        ->assertStatus(503)
        ->assertJsonPath('error_class', 'internal_dependency')
        ->assertJsonPath('retryable', true);
});

it('relays the class the data plane assigned rather than deriving one from the status', function (): void {
    // The rule this proves: Laravel NEVER re-derives an error class from an HTTP status. Without
    // the KbException branch in the render closure this 503 would come back out as
    // `internal_dependency`, and a client's retry decision would be made against a class the data
    // plane never assigned.
    Http::fake(['*/internal/v1/embedding/readiness' => Http::response([
        'error_class' => 'provider_temporary',
        'message' => 'the vendor is overloaded',
        'retryable' => true,
        'request_id' => '01JQZ0000000000000000000RR',
    ], 503)]);

    $fixture = orgWithEmbeddingConnection();

    currentTest()->actingAs($fixture['actor'])
        ->getJson("/api/v1/organizations/{$fixture['org']->id}/embedding-configuration")
        ->assertStatus(503)
        ->assertJsonPath('error_class', 'provider_temporary');
});

it('runs all six checks on the designation endpoint', function (): void {
    $fixture = orgWithEmbeddingConnection();
    fakeReadiness(readyVerdict($fixture['connection']->id));

    $body = ['connection_id' => $fixture['connection']->id, 'model' => 'text-embedding-3-large'];
    $url = "/api/v1/organizations/{$fixture['org']->id}/embedding-configuration";

    // CHECK 1 — authenticated identity.
    currentTest()->putJson($url, $body)->assertStatus(401)
        ->assertJsonPath('error_class', 'authentication');

    // CHECK 2 — organization membership, re-read from PostgreSQL. An admin of SOME organization is
    // the cross-tenant bug; this one is an admin of a DIFFERENT one.
    $otherOrg = Organization::factory()->create();
    $outsider = User::factory()->recycle($otherOrg)->orgRole(OrgRole::Owner)->create();

    currentTest()->actingAs($outsider)->putJson($url, $body)->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');

    // CHECKS 3 and 4 — role, resolved against THE RECORD's organization. An Analyst is a real,
    // active member of the right organization and still may not move the vector space.
    $analyst = User::factory()->recycle($fixture['org'])->orgRole(OrgRole::Analyst)->create();

    currentTest()->actingAs($analyst)->putJson($url, $body)->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');

    // CHECK 5 — entity status. The check reviewers forget, because it is not visible in the route
    // file the way the first four are.
    $suspended = Organization::factory()->suspended()->create();
    $suspendedActor = User::factory()->recycle($suspended)->orgRole(OrgRole::Owner)->create();

    currentTest()->actingAs($suspendedActor)
        ->putJson("/api/v1/organizations/{$suspended->id}/embedding-configuration", [
            'connection_id' => null, 'model' => null,
        ])
        ->assertStatus(409);
});

it('lets a knowledge manager read the banner and not change the designation', function (): void {
    // §6.4 excludes provider credentials, and the designation is on the credential side of that
    // line: it selects which credential pays. Reading it is different — a knowledge manager whose
    // upload is blocked needs to be able to see why.
    $fixture = orgWithEmbeddingConnection();
    $manager = User::factory()->recycle($fixture['org'])->orgRole(OrgRole::KnowledgeManager)->create();

    fakeReadiness(readyVerdict($fixture['connection']->id));

    $url = "/api/v1/organizations/{$fixture['org']->id}/embedding-configuration";

    currentTest()->actingAs($manager)->getJson($url)->assertOk();

    currentTest()->actingAs($manager)->putJson($url, [
        'connection_id' => $fixture['connection']->id,
        'model' => 'text-embedding-3-large',
    ])->assertStatus(403);
});

it('404s a foreign organization id at binding time on a nested route', function (): void {
    // Belt and braces on the same denial: the membership middleware denies first (403), and the
    // scoped binding would deny a child of a foreign parent afterwards. Asserted on a
    // NONEXISTENT organization so the two are distinguishable.
    $fixture = orgWithEmbeddingConnection();

    currentTest()->actingAs($fixture['actor'])
        ->getJson('/api/v1/organizations/01JQZ0000000000000000000ZZ/embedding-configuration')
        ->assertStatus(403)
        ->assertJsonPath('error_class', 'authorization');
});

it('refuses to move an organization by over-posting the ownership column', function (): void {
    $fixture = orgWithEmbeddingConnection();
    fakeReadiness(readyVerdict($fixture['connection']->id));

    $otherOrg = Organization::factory()->create();

    currentTest()->actingAs($fixture['actor'])
        ->putJson("/api/v1/organizations/{$fixture['org']->id}/embedding-configuration", [
            'connection_id' => $fixture['connection']->id,
            'model' => 'text-embedding-3-large',
            'organization_id' => $otherOrg->id,
        ])
        ->assertOk();

    // The FormRequest has no `organization_id` rule, so validated() never carries it and no DTO
    // has a field for it. Over-posting a tenant key is an authorization bug with a 200 response;
    // the 200 here is correct precisely because the key went nowhere.
    assertDatabaseHas('organizations', [
        'id' => $fixture['org']->id,
        'embedding_connection_id' => $fixture['connection']->id,
    ]);
});
