<?php

declare(strict_types=1);

use App\Services\Internal\InternalRequestSigner;

/*
|--------------------------------------------------------------------------
| The canonical string
|--------------------------------------------------------------------------
|
| No framework, no HTTP, no socket. The bug this file exists to catch — the signed header set and
| the sent header set drifting apart — is INVISIBLE in an integration test, which only ever reports
| a 401 on a request that looks correct in the log.
*/

function signer(?string $secret = 'test-hmac-secret-k1'): InternalRequestSigner
{
    return new InternalRequestSigner(prefix: 'KB1', keyId: 'k1', secret: $secret);
}

/** @return array<string, string> */
function readinessHeaders(string $orgId = '01JQZ0000000000000000000AA'): array
{
    return [
        'X-KB-Org-Id' => $orgId,
        'X-KB-Actor-Type' => 'user',
        'X-KB-Operation' => 'embedding.readiness',
        'X-KB-Request-Id' => '01JQZ0000000000000000000RR',
        'X-KB-Contract-Version' => 'v1',
        'X-KB-Deadline' => '1786000000000',
        'X-KB-Timestamp' => '1786000000',
    ];
}

it('builds the canonical string the verifier reconstructs', function (): void {
    $body = '{"connections":[]}';

    $canonical = signer()->canonicalString(
        'POST',
        '/internal/v1/embedding/readiness',
        $body,
        readinessHeaders(),
    );

    [$prefix, $method, $path, $timestamp, $bodyHash] = explode("\n", $canonical);

    expect($prefix)->toBe('KB1')
        ->and($method)->toBe('POST')
        ->and($path)->toBe('/internal/v1/embedding/readiness')
        ->and($timestamp)->toBe('1786000000')
        ->and($bodyHash)->toBe(hash('sha256', $body));

    // Header names lowercased, values trimmed, lines in BYTE order. The verifier sorts the same
    // way; a locale-aware or numeric sort on either side is a 401 that reproduces only for header
    // values that happen to be all digits.
    $lines = array_slice(explode("\n", $canonical), 5);
    $sorted = $lines;
    sort($sorted, SORT_STRING);

    expect($lines)->toBe($sorted)
        ->and($lines)->toContain('x-kb-org-id:01JQZ0000000000000000000AA')
        ->and($lines)->toContain('x-kb-operation:embedding.readiness');
});

it('covers X-KB-Org-Id, so a signed request cannot be replayed against another organization', function (): void {
    // THE ASSERTION THIS WHOLE FILE IS FOR. X-KB-Org-Id is the tenant scope for the entire data
    // plane. A signature over method, path, timestamp and body alone leaves it forgeable: flip one
    // header and a legitimately signed request executes against a different organization,
    // defeating every downstream layer at once.
    $body = '{"connections":[]}';
    $path = '/internal/v1/embedding/readiness';

    $ours = signer()->sign('POST', $path, $body, readinessHeaders('01JQZ0000000000000000000AA'));
    $theirs = signer()->sign('POST', $path, $body, readinessHeaders('01JQZ0000000000000000000BB'));

    expect($ours)->not->toBe($theirs);
});

it('covers the method and the path, so a body signature is not portable to another endpoint', function (): void {
    $body = '{"connections":[]}';
    $headers = readinessHeaders();

    $readiness = signer()->sign('POST', '/internal/v1/embedding/readiness', $body, $headers);
    $elsewhere = signer()->sign('POST', '/internal/v1/chat/stream', $body, $headers);
    $otherVerb = signer()->sign('DELETE', '/internal/v1/embedding/readiness', $body, $headers);

    expect($readiness)->not->toBe($elsewhere)
        ->and($readiness)->not->toBe($otherVerb);
});

it('excludes the signature header from its own canonical string', function (): void {
    // Otherwise signing is a fixed point nobody can compute, and the verifier — which recomputes
    // the set from the headers actually present — would include it and never match.
    $headers = readinessHeaders();

    $without = signer()->canonicalString('POST', '/p', '', $headers);
    $with = signer()->canonicalString('POST', '/p', '', $headers + ['X-KB-Signature' => 'k1:deadbeef']);

    expect($with)->toBe($without);
});

it('covers only X-KB-* headers, filtering inside the function as the verifier does', function (): void {
    // ── SYMMETRY WITH `services/ai-service/app/core/signing.py` ───────────────────────────────
    //
    // The Python side filters to `x-kb-*` INSIDE the function that builds the canonical string.
    // This side used to emit every key it was handed and rely on both of its call sites to
    // pre-filter. They do — so this is a no-op today and the assertion is about the FORMAT rather
    // than about current behaviour. The day a call site stops filtering, PHP would include a line
    // Python drops, and the symptom is a 401 on a request that is correct in every log.
    $withNoise = readinessHeaders() + [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer never-on-this-seam',
    ];

    expect(signer()->canonicalString('POST', '/internal/v1/embedding/readiness', '{}', $withNoise))
        ->toBe(signer()->canonicalString('POST', '/internal/v1/embedding/readiness', '{}', readinessHeaders()));

    // THE CONTROL: an X-KB-* header genuinely changes the string, so the equality above is the
    // filter working and not the header block being ignored altogether.
    expect(signer()->canonicalString('POST', '/internal/v1/embedding/readiness', '{}', readinessHeaders() + ['X-KB-Bot-Id' => '01JQZ0000000000000000000BB']))
        ->not->toBe(signer()->canonicalString('POST', '/internal/v1/embedding/readiness', '{}', readinessHeaders()));
});

it('carries the key id, because two are live during a rotation', function (): void {
    expect(signer()->sign('POST', '/p', '', readinessHeaders()))->toStartWith('k1:');
});

it('refuses to sign with an empty secret rather than producing a valid-looking signature', function (): void {
    // An empty signing key still produces a well-formed HMAC, and it verifies against any peer
    // that also resolved to the empty string. Refusing is the only safe answer.
    expect(fn (): string => signer(null)->sign('POST', '/p', '', readinessHeaders()))
        ->toThrow(\RuntimeException::class);

    expect(fn (): string => signer('')->sign('POST', '/p', '', readinessHeaders()))
        ->toThrow(\RuntimeException::class);
});
