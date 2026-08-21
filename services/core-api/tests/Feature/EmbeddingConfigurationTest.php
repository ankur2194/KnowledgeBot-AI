<?php

declare(strict_types=1);

use App\Enums\OrganizationStatus;
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

it('measures X-KB-Deadline from the start of the REQUEST, not from a fresh clock', function (): void {
    // THE OTHER HALF OF FINDING B1, and the reason the client now names its two epochs separately.
    // A request-scoped caller must keep measuring from LARAVEL_START — that is the entire point of
    // the constant (public/index.php) and of an ABSOLUTE deadline: the far side's remaining time
    // shrinks as ours does instead of restarting downstream. A queued caller must NOT, because
    // there the constant is the worker's boot; that direction is asserted in
    // tests/Feature/SubmitIngestionJobTest.php.
    expect(defined('LARAVEL_START'))->toBeTrue(
        'LARAVEL_START is not defined in this process, so the client is taking its fallback branch '
        .'and this assertion is about a code path that does not ship. tests/bootstrap.php defines '
        .'it; if phpunit.xml no longer points at that file, restore it before reading this green.',
    );

    $fixture = orgWithEmbeddingConnection();

    fakeReadiness(readyVerdict($fixture['connection']->id));

    currentTest()->actingAs($fixture['actor'])
        ->getJson("/api/v1/organizations/{$fixture['org']->id}/embedding-configuration")
        ->assertOk();

    $sent = sentReadinessRequest();

    // EXACT, not a window. There is only one instant this may be derived from, so the assertion can
    // name it — and a client that had quietly moved to `microtime(true)` here would fail by the
    // whole age of the process rather than by a rounding error.
    expect((int) ($sent['headers']['X-KB-Deadline'] ?? 0))->toBe(
        (int) round((LARAVEL_START + (float) config('kb.timeouts.readiness')) * 1000),
        'X-KB-Deadline on a request-scoped internal call is not LARAVEL_START plus this call\'s '
        .'budget, so the budget has been restarted somewhere inside the request',
    );
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

it('publishes the STORED designation beside the resolved one, on every read and both writes', function (): void {
    // WHAT `designated` IS FOR. `selected: null` is produced by two different configurations —
    // "nothing designated, and resolve-by-rule found no embedder" and "a pair IS designated and it
    // stopped resolving" — which need opposite copy on the designation screen. Before this field
    // the stored pair appeared only inside `explanation`, which is the data plane's prose, rendered
    // verbatim by contract and therefore not something a client may parse an id out of.
    //
    // This also pins the FRESHNESS rule in both directions, which is the half a resource-level test
    // cannot reach: `show` renders off the BOUND organization and `update` must render off the row
    // the WRITE returned. Rendering `update` off the bound row would echo the operator's previous
    // designation back at them on the very response that changed it — null after a designate, and
    // the old pair after a clear.
    $fixture = orgWithEmbeddingConnection();

    // fakeSequence and not four fake() calls: Http::fake() PUSHES a stub and the first match wins,
    // so a second fake() for the same URL is silently ignored.
    Http::fakeSequence('*/internal/v1/embedding/readiness')
        ->push(readyVerdict($fixture['connection']->id), 200)
        ->push(readyVerdict($fixture['connection']->id), 200)
        ->push(unreadyVerdict('The designated connection and model no longer resolve to an embedder.'), 200)
        ->push(unreadyVerdict(), 200);

    $url = "/api/v1/organizations/{$fixture['org']->id}/embedding-configuration";

    // 1. NOTHING STORED. Null here means "this organization designated nothing", which is a real
    //    and common state — an organization with exactly one embedding-capable connection never
    //    needs to designate anything.
    currentTest()->actingAs($fixture['actor'])->getJson($url)
        ->assertOk()
        ->assertJsonPath('data.designated', null);

    // 2. THE WRITE'S OWN RESPONSE. The bound organization row still held two nulls when this
    //    request was routed, so a `designated` read off it would be null here — which is exactly
    //    the bug this assertion exists to catch.
    currentTest()->actingAs($fixture['actor'])->putJson($url, [
        'connection_id' => $fixture['connection']->id,
        'model' => 'text-embedding-3-large',
    ])
        ->assertOk()
        ->assertJsonPath('data.ready', true)
        ->assertJsonPath('data.designated.connection_id', $fixture['connection']->id)
        ->assertJsonPath('data.designated.model', 'text-embedding-3-large')
        // TWO KEYS AND NO THIRD. `organizations` stores a connection id and a model string;
        // a `provider` here would be a join publishing a value the designation does not contain.
        ->assertJsonPath('data.designated', [
            'connection_id' => $fixture['connection']->id,
            'model' => 'text-embedding-3-large',
        ]);

    // 3. THE CELL THE FIELD WAS ADDED FOR: the pair is stored, and it no longer resolves. `selected`
    //    is null exactly as it would be for an organization that designated nothing, and only
    //    `designated` tells the two apart.
    currentTest()->actingAs($fixture['actor'])->getJson($url)
        ->assertOk()
        ->assertJsonPath('data.ready', false)
        ->assertJsonPath('data.selected', null)
        ->assertJsonPath('data.designated.connection_id', $fixture['connection']->id);

    // 4. AND THE OTHER FRESHNESS DIRECTION. The bound row still holds the pair while this request
    //    is routed; the response must show it gone.
    currentTest()->actingAs($fixture['actor'])->putJson($url, [
        'connection_id' => null,
        'model' => null,
    ])
        ->assertOk()
        ->assertJsonPath('data.designated', null);
});

it('refuses a designation whose catalog row was deleted after the resolver looked', function (): void {
    // FINDING S6 — THE DIRECTION THE LOCK DID NOT CLOSE.
    //
    // EloquentProviderModelRepository::delete() locks the model row, then `organizations`, then
    // re-reads the designation, so DESIGNATE-THEN-DELETE has always been refused. The mirror image
    // was open: designateEmbeddingConnection() took the same `organizations` lock and then WROTE
    // without checking the pair still existed, and `organizations.embedding_model` is a bare `text`
    // column with no foreign key to `provider_models` — so both requests returned 200 and the
    // organization was left naming a catalog row that had been removed. It surfaced days later, at
    // the next upload, as a resolution error nobody could connect to an action.
    //
    // THE RACE IS REPRODUCED BY MAKING THE VERDICT STALE, which is exactly what it is in
    // production. `EmbeddingDesignationService` resolves OUTSIDE the transaction — it makes an HTTP
    // call, and an HTTP call between BEGIN and COMMIT pins xmin and stops autovacuum reclaiming
    // dead tuples database-wide — so the verdict it acts on is always a statement about a moment
    // that has already passed. Faking a READY verdict for a pair whose row is gone is that moment,
    // deterministically.
    $fixture = orgWithEmbeddingConnection();

    // The resolver says yes, and it is not wrong: this is what it saw.
    fakeReadiness(readyVerdict($fixture['connection']->id));

    // The other administrator's delete, committed in between.
    \App\Models\ProviderModelEntry::query()->withoutGlobalScopes()
        ->where('provider_connection_id', '=', $fixture['connection']->id)
        ->delete();

    currentTest()->actingAs($fixture['actor'])
        ->putJson("/api/v1/organizations/{$fixture['org']->id}/embedding-configuration", [
            'connection_id' => $fixture['connection']->id,
            'model' => 'text-embedding-3-large',
        ])
        // `validation` and not a 409: this IS about a field of the submitted body — the pair the
        // operator named — which is what distinguishes it from the model DELETE's 409, which is
        // about the state of a different record and has no field to key on.
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonPath('retryable', false);

    // AND THE TRANSACTION ROLLED BACK. Without this the test passes against an implementation that
    // writes the designation and then throws, which is the worst of the three possible outcomes:
    // the caller is told no and the dangling pointer is committed anyway.
    assertDatabaseHas('organizations', [
        'id' => $fixture['org']->id,
        'embedding_connection_id' => null,
        'embedding_model' => null,
    ]);
});

it('still designates a pair whose catalog row is present, so the check is not "always refuse"', function (): void {
    // THE POSITIVE CONTROL FOR THE TEST ABOVE, stated separately because the existence query is one
    // predicate away from matching nothing — a `where('model', ...)` against the wrong column, an
    // organization id taken from the wrong variable — and every assertion in the test above would
    // still pass while designation stopped working entirely.
    //
    // `persists a designation the resolver accepts` covers the same ground from the other end; this
    // one is here so the two live beside the check they constrain.
    $fixture = orgWithEmbeddingConnection();

    fakeReadiness(readyVerdict($fixture['connection']->id));

    currentTest()->actingAs($fixture['actor'])
        ->putJson("/api/v1/organizations/{$fixture['org']->id}/embedding-configuration", [
            'connection_id' => $fixture['connection']->id,
            'model' => 'text-embedding-3-large',
        ])
        ->assertOk();

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

it('relays the data plane\'s retryable verdict rather than recomputing it from the class', function (): void {
    // FINDING B1, HALF TWO — ADR-029 / O1 re-opened at the relay boundary.
    //
    // services/ai-service/app/main.py::_handle_unexpected raises with `origin=Origin.SELF` and puts
    // `retryable: false` on the wire: "this is our bug, do not retry". KbException::relayed() used
    // to omit $origin, so the carrier defaulted to ORIGIN_DOWNSTREAM and bootstrap/app.php
    // recomputed `retryable` as TRUE from class-plus-origin — and apps/web's query client then ran
    // a full backoff ladder against a guaranteed failure, every attempt costing the data plane
    // another 500.
    //
    // `origin` is not a wire field, so the relayed `retryable` is the ONLY evidence of origin that
    // crosses. This asserts the whole hop: fake envelope in, rendered envelope out.
    Http::fake(['*/internal/v1/embedding/readiness' => Http::response([
        'error_class' => 'internal_dependency',
        'message' => 'The service could not complete this request.',
        'retryable' => false,
        'request_id' => '01JQZ0000000000000000000SS',
    ], 500)]);

    $fixture = orgWithEmbeddingConnection();

    currentTest()->actingAs($fixture['actor'])
        ->getJson("/api/v1/organizations/{$fixture['org']->id}/embedding-configuration")
        // The relayed STATUS is kept verbatim, and the verdict does NOT come from it.
        ->assertStatus(500)
        ->assertJsonPath('error_class', 'internal_dependency')
        ->assertJsonPath('retryable', false);
});

it('keeps a genuine downstream 503 retryable, so the fix is not "relayed means never retry"', function (): void {
    // THE POSITIVE CONTROL FOR THE TEST ABOVE. Collapsing both readings into "not retryable" would
    // satisfy that pin and would be wrong in the expensive direction: a dependency having a moment
    // IS worth another attempt, and 503/retryable is what tells a client so.
    Http::fake(['*/internal/v1/embedding/readiness' => Http::response([
        'error_class' => 'internal_dependency',
        'message' => 'qdrant is unreachable',
        'retryable' => true,
        'request_id' => '01JQZ0000000000000000000TT',
    ], 503)]);

    $fixture = orgWithEmbeddingConnection();

    currentTest()->actingAs($fixture['actor'])
        ->getJson("/api/v1/organizations/{$fixture['org']->id}/embedding-configuration")
        ->assertStatus(503)
        ->assertJsonPath('error_class', 'internal_dependency')
        ->assertJsonPath('retryable', true);
});

it('relays the data plane\'s per-field validation map instead of erasing it', function (): void {
    // FINDING B1, HALF ONE. `_handle_validation_error` builds a real `dict[str, list[str]]` from
    // Pydantic's `loc` paths on EVERY validation envelope — and the relay read only `error_class`
    // and `message`, so a 422 that named the offending field arrived at the browser as `validation`
    // with nothing to key a form error on.
    //
    // It also broke an invariant apps/web tests STRUCTURALLY: features/embedding/api.ts
    // discriminates the ADR-031 resolver refusal on `validation` AND `errors === null`. That test
    // is only sound while "no map" really does mean "a deliberate refusal with no field", which is
    // what this pair of tests — this one and the resolver-refusal one above — assert together.
    Http::fake(['*/internal/v1/embedding/readiness' => Http::response([
        'error_class' => 'validation',
        'message' => 'request failed validation',
        'retryable' => false,
        'request_id' => '01JQZ0000000000000000000UU',
        'errors' => [
            'connections.0.model' => ['Input should be a valid string'],
            'designated' => ['Field required'],
        ],
    ], 422)]);

    $fixture = orgWithEmbeddingConnection();

    $response = currentTest()->actingAs($fixture['actor'])
        ->getJson("/api/v1/organizations/{$fixture['org']->id}/embedding-configuration")
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation');

    $body = $response->json();

    assert(is_array($body));

    // Read as data rather than through assertJsonPath: `Arr::get` explodes on '.' with no escape,
    // so a dotted Pydantic field path is unreachable by that route and asserting it there would
    // check a path that does not exist.
    expect($body)->toHaveKey('errors')
        ->and($body['errors'])->toBe([
            'connections.0.model' => ['Input should be a valid string'],
            'designated' => ['Field required'],
        ]);
});

it('does NOT forward a malformed errors map, and does not invent one on another class', function (): void {
    // FAIL CLOSED ON THE SHAPE. `errors` is contractually `Record<string, string[]>` and a form keys
    // on it; FastAPI's own handler records why a LIST is the dangerous near-miss — it still
    // satisfies `typeof value === "object"`, so the envelope type-guard passes, the form keys on
    // `0` and `1`, no field matches, and every message collapses into one opaque root error.
    //
    // Dropping it renders as the deliberate-refusal shape (`validation`, no map), which is the one
    // thing every client already knows how to display.
    Http::fake(['*/internal/v1/embedding/readiness' => Http::response([
        'error_class' => 'validation',
        'message' => 'request failed validation',
        'retryable' => false,
        'request_id' => '01JQZ0000000000000000000VV',
        // A LIST, not a map — the exact shape the type-guard cannot tell from a map.
        'errors' => [['Input should be a valid string']],
    ], 422)]);

    $fixture = orgWithEmbeddingConnection();

    $body = currentTest()->actingAs($fixture['actor'])
        ->getJson("/api/v1/organizations/{$fixture['org']->id}/embedding-configuration")
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->json();

    assert(is_array($body));

    expect($body)->not->toHaveKey('errors');
});

it('never grows an errors map on a class that is not validation', function (): void {
    // The superset is keyed on the CLASS, not on "the data plane sent something". A producer that
    // wrongly attached a map to a `provider_temporary` must not make one appear here, because a
    // client reads `errors` as proof it is looking at a per-field refusal.
    Http::fake(['*/internal/v1/embedding/readiness' => Http::response([
        'error_class' => 'provider_temporary',
        'message' => 'the vendor is overloaded',
        'retryable' => true,
        'request_id' => '01JQZ0000000000000000000WW',
        'errors' => ['model' => ['nope']],
    ], 503)]);

    $fixture = orgWithEmbeddingConnection();

    $body = currentTest()->actingAs($fixture['actor'])
        ->getJson("/api/v1/organizations/{$fixture['org']->id}/embedding-configuration")
        ->assertStatus(503)
        ->json();

    assert(is_array($body));

    expect($body)->not->toHaveKey('errors');
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
        ->assertStatus(409)
        // AND THE SENTENCE, not an empty `message`. A 409 with no message is rendered by
        // bootstrap/app.php as `internal_dependency`, whose class-mapped client copy is "Something
        // on our side is unavailable. Try again shortly." — false twice over, because nothing is
        // unavailable and retrying never works while the organization is suspended. The render
        // closure's `default => $e->getMessage()` arm carries this through verbatim.
        ->assertJsonPath('message', OrganizationStatus::SUSPENDED_REFUSAL);
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
