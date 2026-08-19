<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Support\Crypto\BinaryCast;
use App\Support\Crypto\CredentialVault;
use Database\Factories\ProviderConnectionFactory;

/*
|--------------------------------------------------------------------------
| BinaryCast — reading a bytea attribute more than once
|--------------------------------------------------------------------------
|
| THE REGRESSION THIS FILE EXISTS FOR, stated as the bug rather than as the fix.
|
| App\Support\Crypto\BinaryCast::get() used to call `stream_get_contents($value)` with no offset.
| PDO_PGSQL returns a `bytea` column as a STREAM RESOURCE in the builds this project runs on, and
| `stream_get_contents()` with no offset reads from the CURRENT POSITION and leaves the handle
| drained. That would be harmless if Eloquent cached the cast result — and it does not:
| `getClassCastableAttributeValue()` stores the value in `$classCastCache` only when
| `is_object($value)`, and this cast returns a `string`. So every access re-entered the cast against
| the SAME, now-exhausted handle.
|
| THE OBSERVED SYMPTOM WAS NOT "empty string". It was `CredentialVault::open()` reporting
| "ciphertext is truncated" on the SECOND read of a connection — a message that reads like key
| corruption and sends the reader to the KEK, the key version, and the envelope format, none of
| which had anything to do with it. The first read of any bytea attribute worked, which is why it
| survived: every test that touched a credential once passed.
|
| The fix is the explicit offset — `stream_get_contents($value, -1, 0)` — which makes repeated
| access idempotent. It shipped with NO test, and this file is that test.
|
| ── WHY THIS IS A FEATURE TEST AND NOT A UNIT ONE ──────────────────────────────────────────────
|
| It needs a REAL PostgreSQL round trip. tests/Unit extends nothing — no container, no database, no
| facades — and a unit test of BinaryCast could only hand `get()` a string it constructed itself,
| which is precisely the branch that never had the bug. The stream branch only exists when PDO
| produced the value, so a test that cannot reach PDO cannot reach the defect.
|
| Nor can it be asserted by re-fetching the model: `ProviderConnection::find()` twice makes two
| queries and two fresh handles, and both would succeed even with the broken cast. The property is
| specifically REPEATED ACCESS TO ONE HYDRATED INSTANCE.
*/

it('returns identical bytes on every read of a bytea attribute, not just the first', function (): void {
    $organization = Organization::factory()->create(['name' => 'BinaryCast Org']);

    // The factory seals FIXTURE_CREDENTIAL for real, so both ciphertext columns hold genuine
    // envelope-encrypted bytes rather than a placeholder — which is what makes the length and the
    // vault round-trip below statements about the cast rather than about the fixture.
    $created = ProviderConnection::factory()->recycle($organization)->create();

    // A FRESH QUERY, NOT THE FACTORY'S INSTANCE. The instance returned by create() holds the values
    // that were ASSIGNED to it — plain PHP strings that never went through PDO — so reading it
    // three times exercises the `is_string` branch and would pass against the broken cast.
    // Everything below must come from a hydrated model whose attributes arrived from the driver.
    $connection = ProviderConnection::query()
        ->withoutGlobalScopes()
        ->findOrFail($created->id);

    $reads = [
        $connection->getAttribute('credential_ciphertext'),
        $connection->getAttribute('credential_ciphertext'),
        $connection->getAttribute('credential_ciphertext'),
    ];

    // POSITIVE CONTROL FIRST, AND IT IS THE WHOLE TEST. "All three reads are equal" is satisfied by
    // three empty strings — which is exactly what the bug produced from the second read onward, and
    // would have been satisfied by it if the FIRST read were empty too. So the first read is
    // asserted to be real bytes before anything is compared.
    expect($reads[0])->toBeString()
        ->and($reads[0])->not->toBe('', 'the first read of credential_ciphertext was empty, so this test proves nothing');

    // AES-GCM output is longer than its 12-byte nonce plus its 16-byte tag whatever the plaintext,
    // so this is a floor no correct ciphertext can be under and a bound the bug's empty string
    // fails by construction.
    expect(strlen((string) $reads[0]))->toBeGreaterThan(28);

    expect($reads[1])->toBe(
        $reads[0],
        'the SECOND read of a bytea attribute differed from the first. This is the drained-stream '
        .'regression: PDO returns bytea as a stream, Eloquent does not cache a class cast whose '
        .'get() returns a non-object, and a bare stream_get_contents() reads from the current '
        .'position — so read two onward came back empty and CredentialVault::open() reported '
        .'"ciphertext is truncated", which reads like KEK corruption.',
    );

    expect($reads[2])->toBe($reads[0], 'the THIRD read differed from the first');

    // THE SECOND BYTEA COLUMN TOO. The two are read together by every vault call, so a cast that
    // was fixed for one and not the other would fail in exactly the same confusing way.
    $keys = [
        $connection->getAttribute('data_key_ciphertext'),
        $connection->getAttribute('data_key_ciphertext'),
    ];

    expect($keys[0])->not->toBe('')
        ->and($keys[1])->toBe($keys[0], 'data_key_ciphertext drained on its second read');
});

it('still opens the credential after the ciphertext has already been read twice', function (): void {
    $organization = Organization::factory()->create(['name' => 'BinaryCast Vault Org']);

    $created = ProviderConnection::factory()->recycle($organization)->create();

    $connection = ProviderConnection::query()
        ->withoutGlobalScopes()
        ->findOrFail($created->id);

    // THE FAILURE AS IT ACTUALLY PRESENTED. Anything that touches a connection twice in one request
    // — a resource that renders it and a service that then uses it, an audit closure reading the
    // row the repository just saved — reaches the vault on a handle something else has already
    // drained. Two throwaway reads reproduce that ordering exactly; without them this is just a
    // vault test and passes either way.
    $connection->getAttribute('credential_ciphertext');
    $connection->getAttribute('credential_ciphertext');

    $plaintext = app(CredentialVault::class)->open(
        (string) $connection->getAttribute('credential_ciphertext'),
        (string) $connection->getAttribute('data_key_ciphertext'),
    );

    expect($plaintext)->toBe(
        ProviderConnectionFactory::FIXTURE_CREDENTIAL,
        'the vault could not recover the plaintext after the ciphertext attribute had been read '
        .'more than once — the message this produced was "ciphertext is truncated", which is a '
        .'true statement about a value the CAST truncated and a false lead about the KEK',
    );
});

it('round-trips arbitrary bytes through the cast, including a value that starts with the hex marker', function (): void {
    $organization = Organization::factory()->create(['name' => 'BinaryCast Marker Org']);

    $connection = ProviderConnection::factory()->recycle($organization)->create();

    // `\x` IS AN ORDINARY TWO-BYTE PREFIX IN CIPHERTEXT. AES-GCM output is uniform bytes, so
    // `0x5C 0x78` occurs about once in every 65,536 credentials — and the tempting optimisation in
    // BinaryCast::set() ("skip the encoding if the value already starts with \x, so a re-save does
    // not double-encode") would store exactly those rows half-encoded and undetectably corrupt.
    // The cast encodes unconditionally; this asserts the consequence.
    $bytes = "\x5c\x78".random_bytes(64)."\x00\xff\xfe";

    $connection->setAttribute('credential_ciphertext', $bytes);
    $connection->save();

    $reloaded = ProviderConnection::query()
        ->withoutGlobalScopes()
        ->findOrFail($connection->id);

    expect($reloaded->getAttribute('credential_ciphertext'))->toBe($bytes)
        // …AND AGAIN, because the point of this file is the second read.
        ->and($reloaded->getAttribute('credential_ciphertext'))->toBe($bytes);
});

it('encodes and decodes symmetrically at the cast boundary, with no database involved', function (): void {
    // The pure half, kept here rather than in tests/Unit only because the two above need the
    // database and splitting one cast across two suites hides the relationship. It is what proves
    // `set()` produces PostgreSQL's own `\x` hex input format — pure ASCII, losslessly cast to
    // `bytea` by the server — rather than something that merely survives a round trip by accident.
    $cast = new BinaryCast;
    $model = new ProviderConnection;

    $bytes = random_bytes(48);

    $encoded = $cast->set($model, 'credential_ciphertext', $bytes, []);

    expect($encoded['credential_ciphertext'])->toBe('\x'.bin2hex($bytes));

    expect($cast->get($model, 'credential_ciphertext', $encoded['credential_ciphertext'], []))
        ->toBe($bytes);

    // Null survives in both directions: a nullable bytea column is a real state and must not become
    // an empty string, which would decrypt to a truncation error instead of failing as "absent".
    expect($cast->set($model, 'credential_ciphertext', null, []))
        ->toBe(['credential_ciphertext' => null])
        ->and($cast->get($model, 'credential_ciphertext', null, []))->toBeNull();
});

it('does not hex-decode stored bytes that happen to spell out the hex marker and valid hex', function (): void {
    // THE AMBIGUITY THE SOURCE-BRANCH REMOVES, AND THE ONE VALUE THE OLD PROBABILITY ARGUMENT
    // COULD NOT COVER.
    //
    // `get()` used to decide hex-versus-raw from the `\x` prefix PLUS "and the remainder looks like
    // hex", on the reasoning that a run of 64 random bytes being entirely ASCII hex digits is
    // (16/256)^64 and not worth a thought. That is true of RANDOM bytes and says nothing about
    // bytes an attacker, a fixture, or a future non-credential caller chooses — `token_hash` on
    // OrganizationInvitation and EmailVerificationToken go through this same general cast.
    //
    // These 34 bytes are exactly that value: `\` `x` followed by 32 ASCII hex characters. PDO hands
    // them back as a STREAM of decoded bytes, and the old code read the stream, saw the prefix, saw
    // a well-formed even-length hex body, and decoded it — returning 16 DIFFERENT, SHORTER bytes
    // with nothing raised. Branching on the source makes that unrepresentable: a resource is bytes.
    $organization = Organization::factory()->create(['name' => 'BinaryCast Ambiguity Org']);

    $connection = ProviderConnection::factory()->recycle($organization)->create();

    $bytes = '\x'.'0123456789abcdef0123456789abcdef';

    expect(strlen($bytes))->toBe(34);

    $connection->setAttribute('credential_ciphertext', $bytes);
    $connection->save();

    $reloaded = ProviderConnection::query()
        ->withoutGlobalScopes()
        ->findOrFail($connection->id);

    // The whole 34 bytes, not the 16 the old branch would have decoded them into.
    expect($reloaded->getAttribute('credential_ciphertext'))->toBe($bytes)
        ->and(strlen((string) $reloaded->getAttribute('credential_ciphertext')))->toBe(34)
        // …and again, because repeated access is what this file is about.
        ->and($reloaded->getAttribute('credential_ciphertext'))->toBe($bytes);
});

it('refuses a non-string rather than nulling the column', function (): void {
    // SILENT DATA LOSS, CLOSED. `set()` returned `[$key => null]` for any non-string, so assigning
    // an integer, a stream, or an object with a __toString to a NULLABLE bytea column wrote a null,
    // `save()` succeeded, and the row read back as "no value stored" with nothing anywhere saying
    // one had been discarded. On `provider_connections` the columns are NOT NULL so it surfaced as
    // a driver error that looks like a schema bug; this is a general-purpose cast and
    // `token_hash` on two other models goes through it, so the nullable case is one column away.
    //
    // There is no correct silent handling — the contract is raw BYTES and PHP has one type for
    // those — so a caller passing anything else has a bug, and a bug in a credential write must be
    // loud at the assignment rather than discovered at the next decryption.
    $cast = new BinaryCast;
    $model = new ProviderConnection;

    // Held as `mixed` rather than passed as literals: the parameter is declared `string|null`, so
    // a literal int or array is a STATIC type error and phpstan fails the file — on a test whose
    // entire subject is what happens when a caller gets that type wrong at runtime. The values and
    // the assertion are unchanged; only the analyser's view of them is.
    /** @var mixed $notAString */
    $notAString = 12345;
    /** @var mixed $alsoNotAString */
    $alsoNotAString = ['bytes'];

    expect(static fn () => $cast->set($model, 'credential_ciphertext', $notAString, []))
        ->toThrow(\InvalidArgumentException::class);

    expect(static fn () => $cast->set($model, 'credential_ciphertext', $alsoNotAString, []))
        ->toThrow(\InvalidArgumentException::class);

    // NULL IS STILL A REAL STATE and must not throw: clearing a nullable bytea column is a
    // legitimate write, and conflating it with a type error would make the guard unusable.
    expect($cast->set($model, 'credential_ciphertext', null, []))
        ->toBe(['credential_ciphertext' => null]);
});
