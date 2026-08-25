<?php

declare(strict_types=1);

use App\Services\Internal\InternalRequestSigner;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The two internal key rings hold DIFFERENT secrets, and the suite proves it
|--------------------------------------------------------------------------
|
| `k*` signs Laravel -> FastAPI. `c*` signs FastAPI -> Laravel. Two rings, so that a compromised
| OUTBOUND key cannot forge an inbound callback — and a forged callback is not a small thing: it
| carries `X-KB-Org-Id`, it advances a source's lifecycle, and `IngestionCallbackController` switches
| the active-version pointer on the strength of it.
|
| ══════════════════════════════════════════════════════════════════════════════════════════════
| WHY THIS FILE EXISTS: THE RULE WAS WRITTEN DOWN AND NOTHING CHECKED IT.
| ══════════════════════════════════════════════════════════════════════════════════════════════
|
| `phpunit.xml` says it in as many words, beside the two `AI_CALLBACK_HMAC_KEY_C*` values: "If the
| two directions shared a value here, a verifier wired to the wrong ring would still pass every
| callback test, which is exactly the defect the split exists to make visible." That is a correct
| description of a hazard and it was, until this file, a comment. The Phase C critical finding is the
| worked example of what it costs: the suite was green because the HELPER signed with the wrong ring
| too, so both sides agreed and every assertion held.
|
| ── AND `IngestionCallbackTest`'s OUTBOUND-RING TEST DOES NOT COVER IT ─────────────────────────
|
| That test signs with `k1` — the outbound ID and the outbound SECRET — and asserts a 401. It would
| still pass if the two rings held one identical secret, because the verifier never finds a `k1` in
| the callback ring and refuses on the ID LOOKUP before any secret is compared. It proves the ring is
| selected by direction; it cannot prove the secrets differ. The first test below closes that by
| signing with a callback ID and an outbound SECRET, which reaches the comparison.
|
| ── EVERY ASSERTION HERE IS ABOUT THE SUITE'S OWN CONFIGURATION, AND THAT IS THE POINT ────────
|
| It cannot prove a deployment's rings are disjoint — nothing in a test can. What it proves is that
| THIS SUITE is capable of telling the two directions apart, which is the precondition for every
| other signing assertion in `tests/Feature/IngestionCallbackTest.php` meaning anything at all.
*/

/** @return array<string, string> */
function outboundRing(): array
{
    /** @var array<string, string> $keys */
    $keys = (array) config('services.ai.hmac.keys');

    return $keys;
}

/** @return array<string, string> */
function callbackRing(): array
{
    /** @var array<string, string> $keys */
    $keys = (array) config('services.ai.callback_hmac.keys');

    return $keys;
}

// ── the configuration ────────────────────────────────────────────────────────────────────────────

it('holds at least one key in each direction, so nothing below passes vacuously', function (): void {
    // THE POSITIVE CONTROL, and it is first on purpose. Every other assertion in this file is a
    // DISJOINTNESS claim, and disjointness is trivially true of two empty sets — `array_filter` in
    // config/services.php drops an id whose secret is unset, so a phpunit.xml that lost both
    // `AI_*_KEY_*` lines would make this whole file green while the suite signed nothing.
    expect(outboundRing())->not->toBeEmpty()
        ->and(callbackRing())->not->toBeEmpty();
});

it('shares no secret between the outbound and inbound rings', function (): void {
    // VALUES, NOT IDS. The ids differ by construction (`k*` against `c*`) and comparing them would
    // be a test of a naming convention. What matters is that no byte string appears in both rings:
    // one shared value means a leaked outbound key forges callbacks, whatever the ids are called.
    $shared = array_intersect(array_values(outboundRing()), array_values(callbackRing()));

    expect($shared)->toBe(
        [],
        'the outbound and inbound key rings share a secret, so a compromised Laravel->FastAPI key '
        .'can forge a FastAPI->Laravel callback — and every signing test in this suite would pass '
        .'against a verifier wired to the wrong direction',
    );
});

it('shares no key id either, so a rotation can never make one ring answer for the other', function (): void {
    // The weaker property, and it is still worth pinning: the verifier resolves a secret BY ID from
    // the ring its direction names. An id present in both rings makes "which ring am I reading" an
    // unobservable choice — the lookup succeeds either way — and the wrong-ring bug then has no
    // symptom at all until someone rotates one side.
    expect(array_intersect(array_keys(outboundRing()), array_keys(callbackRing())))->toBe([]);
});

it('never carries an empty or placeholder-length secret in either ring', function (): void {
    // An empty secret is an HMAC key of `b""`, which any peer with an equally empty key agrees with.
    // The data plane refuses these at load (`app/core/keys.py` rejects empty and short files, and
    // says why); this is the control plane's half of the same rule, applied to the suite's own
    // material so a truncated env value fails here rather than producing signatures nobody notices.
    foreach (['outbound' => outboundRing(), 'callback' => callbackRing()] as $direction => $ring) {
        foreach ($ring as $id => $secret) {
            expect(strlen($secret))->toBeGreaterThanOrEqual(
                16,
                "the {$direction} ring's `{$id}` secret is shorter than 16 bytes",
            );
        }
    }
});

it('names an active outbound id that exists outbound and does NOT exist inbound', function (): void {
    $active = (string) config('services.ai.hmac.active');

    expect(outboundRing())->toHaveKey($active)
        ->and(callbackRing())->not->toHaveKey($active);
});

// ── the property the configuration exists for ────────────────────────────────────────────────────

it('refuses a callback whose key id is inbound but whose SECRET is the outbound one', function (): void {
    /**
     * THE TEST `IngestionCallbackTest` CANNOT WRITE, because it signs with the outbound ID and is
     * therefore refused by the id lookup before any secret is compared. Here the id is a REAL
     * callback id, so the verifier finds material for it and reaches the HMAC comparison — which
     * fails only because the secrets differ. If the two rings ever held one value, this returns
     * something other than 401 and this test is what says so.
     */
    $callbackId = (string) array_key_first(callbackRing());
    $outbound = outboundRing();
    $outboundSecret = $outbound[(string) config('services.ai.hmac.active')];

    $body = [
        'job_id' => (string) Str::ulid(),
        'source_id' => (string) Str::ulid(),
        'source_item_id' => (string) Str::ulid(),
        'sequence' => 1,
        'stage' => 'fetch',
        'status' => 'fetching',
    ];

    expect(postKeyRingProbe($callbackId, $outboundSecret, $body)->getStatusCode())->toBe(401);
});

it('accepts the same frame when the secret is the inbound one, so the refusal above is about the secret', function (): void {
    /**
     * THE CONTROL, and without it the test above is worthless: a route that 401s every request
     * would satisfy it. This signs the SAME bytes with the SAME id and the correct secret, and
     * asserts only that the answer is not 401 — the frame names an organization and a job that do
     * not exist, so a 404 or a 422 is the expected and correct outcome. What is being proved is that
     * the harness can produce a signature this verifier accepts, which makes the 401 above a
     * statement about the secret rather than about the harness.
     */
    $callbackRing = callbackRing();
    $callbackId = (string) array_key_first($callbackRing);

    $body = [
        'job_id' => (string) Str::ulid(),
        'source_id' => (string) Str::ulid(),
        'source_item_id' => (string) Str::ulid(),
        'sequence' => 1,
        'stage' => 'fetch',
        'status' => 'fetching',
    ];

    expect(postKeyRingProbe($callbackId, $callbackRing[$callbackId], $body)->getStatusCode())
        ->not->toBe(401);
});

/**
 * POST one ingestion frame signed with an explicit (key id, secret) pair.
 *
 * A HELPER OF THIS FILE'S OWN, with a name of its own: Pest declares test-file helpers at FILE
 * SCOPE, so a name another test file already uses is a redeclaration fatal in a FULL run and only in
 * a full run. `postIngestionFrame()` in `tests/Feature/IngestionCallbackTest.php` resolves the ring
 * itself and is deliberately not reused — this file's whole subject is signing with material that
 * helper would never choose.
 *
 * THE SIGNED BYTES ARE THE SENT BYTES, for the reason that helper states: `postJson()` re-encodes
 * the body, and re-encoding JSON to hash it is not byte-stable.
 *
 * @param  array<string, mixed>  $body
 */
function postKeyRingProbe(string $keyId, string $secret, array $body): \Symfony\Component\HttpFoundation\Response
{
    $path = '/internal/v1/callbacks/ingestion';
    $payload = json_encode($body, JSON_THROW_ON_ERROR);

    $headers = [
        'X-KB-Org-Id' => (string) Str::ulid(),
        'X-KB-Actor-Type' => 'system',
        'X-KB-Operation' => 'ingestion.progress',
        'X-KB-Request-Id' => (string) Str::ulid(),
        'X-KB-Contract-Version' => (string) config('kb.contract_version'),
        'X-KB-Timestamp' => (string) time(),
    ];

    /** @var list<string> $prefixes */
    $prefixes = (array) config('services.ai.callback_hmac.accepted_prefixes');

    $signature = (new InternalRequestSigner((string) ($prefixes[0] ?? 'KB1'), $keyId, $secret))
        ->sign('POST', $path, $payload, $headers);

    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    foreach ($headers + ['X-KB-Signature' => $signature] as $name => $value) {
        $server['HTTP_'.str_replace('-', '_', strtoupper($name))] = $value;
    }

    return currentTest()->call('POST', $path, [], [], [], $server, $payload)->baseResponse;
}
