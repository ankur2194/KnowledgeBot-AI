<?php

declare(strict_types=1);

use App\Exceptions\SecretUnavailableException;
use App\Support\Crypto\CredentialVault;

const VAULT_KEK = 'kb-test-kek-not-a-real-key-0000000000000000';

const VAULT_PLAINTEXT = 'kb-fixture-credential-DO-NOT-LOG-01JQZ';

it('round-trips a credential through the envelope', function (): void {
    $vault = new CredentialVault(VAULT_KEK, 3);

    $sealed = $vault->seal(VAULT_PLAINTEXT);

    expect($vault->open($sealed['credential_ciphertext'], $sealed['data_key_ciphertext']))
        ->toBe(VAULT_PLAINTEXT);
});

it('never puts the plaintext in the row', function (): void {
    $sealed = (new CredentialVault(VAULT_KEK, 1))->seal(VAULT_PLAINTEXT);

    // `str_contains` and not Pest's `not->toContain`: `expect($x)->not->toContain('a', 'b')` reads
    // like an assertion with a message and is not one — toContain takes only needles, and `not`
    // treats ANY failure as success, so the whole expression passes unconditionally. That exact
    // shape has already hidden a real tenant-id leak in this repository.
    expect(str_contains($sealed['credential_ciphertext'], VAULT_PLAINTEXT))->toBeFalse()
        ->and(str_contains($sealed['data_key_ciphertext'], VAULT_PLAINTEXT))->toBeFalse();
});

it('uses a fresh data key per credential, so two identical secrets do not collide', function (): void {
    $vault = new CredentialVault(VAULT_KEK, 1);

    // Deterministic ciphertext over provider keys would be an equality oracle: an operator with
    // read access to the column could tell that two organizations pasted the same key.
    expect($vault->seal(VAULT_PLAINTEXT)['credential_ciphertext'])
        ->not->toBe($vault->seal(VAULT_PLAINTEXT)['credential_ciphertext']);
});

it('records the KEK version, so a rotation is a data change and not a migration', function (): void {
    expect((new CredentialVault(VAULT_KEK, 7))->seal(VAULT_PLAINTEXT)['key_version'])->toBe(7);
});

it('fails closed when the KEK is unavailable', function (): void {
    // A missing key-encrypting key is NEVER a reason to store a credential unwrapped and never a
    // reason to skip encryption. The only correct response is to refuse the write.
    $vault = new CredentialVault(null, 1);

    expect(fn (): array => $vault->seal(VAULT_PLAINTEXT))
        ->toThrow(SecretUnavailableException::class);

    expect(fn (): array => (new CredentialVault('   ', 1))->seal(VAULT_PLAINTEXT))
        ->toThrow(SecretUnavailableException::class);
});

it('exposes four characters of the key and never a prefix', function (): void {
    // A provider key PREFIX identifies the vendor and, on several providers, the account. The last
    // four identify the key to the person who pasted it and to nobody else.
    $vault = new CredentialVault(VAULT_KEK, 1);

    expect($vault->lastFour('sk-proj-abcdefgh1234'))->toBe('1234')
        ->and($vault->lastFour('ab'))->toHaveLength(4);
});

it('fingerprints a key without being derivable back to it', function (): void {
    $vault = new CredentialVault(VAULT_KEK, 1);
    $fingerprint = $vault->fingerprint(VAULT_PLAINTEXT);

    expect($fingerprint)->toHaveLength(16)
        ->and(str_contains($fingerprint, VAULT_PLAINTEXT))->toBeFalse()
        // Stable for one key, so an audit row can answer "which key was this".
        ->and($vault->fingerprint(VAULT_PLAINTEXT))->toBe($fingerprint)
        ->and($vault->fingerprint('some-other-key'))->not->toBe($fingerprint);
});

it('refuses a ciphertext it cannot decrypt, without saying which layer failed', function (): void {
    $vault = new CredentialVault(VAULT_KEK, 1);
    $sealed = $vault->seal(VAULT_PLAINTEXT);

    $wrongKek = new CredentialVault('a-different-kek-entirely', 1);

    // An oracle that distinguishes "wrong KEK" from "corrupt ciphertext" is a probe an attacker
    // can run, so the message says neither.
    expect(fn (): string => $wrongKek->open(
        $sealed['credential_ciphertext'],
        $sealed['data_key_ciphertext'],
    ))->toThrow(\RuntimeException::class, 'Provider credential could not be decrypted.');
});
