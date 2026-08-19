<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\User;
use App\Support\Crypto\VaultQueryScrubber;
use Database\Factories\ProviderConnectionFactory;
use Database\Factories\UserFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RecordingLogger;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| A failed vault-column write must not report the credential (§18.2, §20.3)
|--------------------------------------------------------------------------
|
| THE BUG. `BinaryCast::set()` renders raw bytes as `'\x'.bin2hex($value)` because PDO cannot bind a
| `bytea`. That string is an ordinary binding, and `QueryException::formatMessage()` builds its
| message by interpolating EVERY binding into the SQL (vendor QueryException:83). So any database
| failure on the INSERT or UPDATE that writes `credential_ciphertext` and `data_key_ciphertext`
| reports both of them, hex-encoded and complete, into a log store nobody classifies as sensitive.
|
| `KbJsonFormatter::redact()` cannot save it — every rule in that set recognises a credential by its
| own SHAPE (`sk-…`, `Bearer …`, `KB1 …`, a URL query string) and AES-GCM ciphertext rendered as hex
| has no shape at all. Nothing else was watching either: the RESPONSE is safe, because a
| QueryException is not an HttpExceptionInterface and the render closure's >=500 arm emits a fixed
| constant, which is precisely why this could sit there unnoticed.
|
| THE HARNESS. A CHECK constraint that can never be satisfied is added to `provider_connections`,
| so the real INSERT fails at the database, inside the real transaction, on the real connection —
| the same technique tests/Feature/AuditAtomicityTest.php uses on `audit_logs` partitions, and for
| the same reason: a mocked repository that throws proves the PHP branch and nothing about what PDO
| actually put in the message. DDL is transactional in PostgreSQL, so RefreshDatabase removes it.
|
| `Http::fake()` APPEARS NOWHERE and cannot (pest-testing NN4 bans it under tests/Security). It is
| not needed: `ProviderConnectionService::create()` computes embedding readiness only AFTER the
| repository returns, and here the repository throws first.
|
| ABSENCE IS ALWAYS `expect(str_contains(...))->toBeFalse()` and never `->not->toContain(...)`,
| which takes no message argument and passes unconditionally under `not`.
*/

beforeEach(function (): void {
    SpaSession::isolateRateLimits(currentTest());
});

/**
 * The plaintext this test seals. Distinctive so an assertion cannot pass because the key was never
 * in play, and long enough to clear `min:8`.
 */
const VAULT_LOG_TEST_CREDENTIAL = 'kb-vault-log-test-credential-DO-NOT-LOG-01JQZ';

/**
 * Make every write to `provider_connections` fail at the database.
 *
 * `NOT VALID` skips the check against EXISTING rows — there are none here, but it also means the
 * statement cannot fail for a reason other than the one under test — while still applying to every
 * INSERT and UPDATE. SQLSTATE 23514.
 */
function breakProviderConnectionWrites(): void
{
    // `Schema::getConnection()->statement()` and NOT `DB::statement()`: tests/Arch/DoctrineTest.php
    // pins the DB facade to App\Repositories\Eloquent, and tests/Feature/AuditAtomicityTest.php
    // reaches for the connection the same way for the same reason.
    Schema::getConnection()->statement(
        'ALTER TABLE provider_connections ADD CONSTRAINT kb_test_forced_failure CHECK (false) NOT VALID',
    );
}

/**
 * Swap the container's PSR-3 logger for a recorder, and hand it back.
 *
 * Laravel's handler resolves `LoggerInterface::class` — an alias of the `log` binding — so
 * `Log::swap()` intercepts BOTH the default reporting stack (which is what would leak) and the
 * scrubbed line the report closure writes in its place. That is the point: the assertion has to be
 * able to see the leak if it happens, not merely see the replacement.
 */
function recordEveryLogLine(): RecordingLogger
{
    $recorder = new RecordingLogger;

    Log::swap($recorder);

    return $recorder;
}

/**
 * @return array{org: Organization, actor: User}
 */
function vaultLogFixture(): array
{
    $org = Organization::factory()->create();

    return ['org' => $org, 'actor' => User::factory()->recycle($org)->orgRole(OrgRole::Owner)->create()];
}

// -------------------------------------------------------------------------------------------

it('logs nothing key-shaped when the connection INSERT fails', function (): void {
    $fixture = vaultLogFixture();
    $recorder = recordEveryLogLine();

    breakProviderConnectionWrites();

    $response = currentTest()->actingAs($fixture['actor'])
        ->postJson("/api/v1/organizations/{$fixture['org']->id}/provider-connections", [
            'provider' => 'openai',
            'label' => 'Primary',
            'credential' => VAULT_LOG_TEST_CREDENTIAL,
            'models' => [],
        ]);

    // POSITIVE CONTROL ON THE HARNESS, FIRST. Without it every absence assertion below is satisfied
    // by a request that never reached the INSERT at all — a 403, a 422, a route that moved.
    $response->assertStatus(500)->assertJsonPath('error_class', 'internal_dependency');

    expect(ProviderConnection::query()->withoutGlobalScopes()->count())->toBe(0);

    // POSITIVE CONTROL ON THE RECORDER. An empty recorder makes "no line contains the key" true for
    // the wrong reason, and a silenced failure would be its own finding.
    $lines = array_map(static fn (array $line): string => $line['message'], $recorder->lines);

    expect($lines)->not->toBeEmpty(
        'the vault write failed and nothing at all was logged, so this test proves nothing about '
        .'what a log line may contain',
    );

    $joined = implode("\n", $lines);

    // THE ASSERTION. `\x` followed by a long run of hex is BinaryCast::set()'s output and nothing
    // else in this application produces it — so this catches the sealed credential, the wrapped data
    // key, and any future bytea column, without the test needing to know which is which.
    expect(preg_match('/\\\\x(?:[0-9a-f]{2}){16,}/i', $joined))->toBe(
        0,
        'a log line carries a `\\x…` hex run, which is exactly the shape BinaryCast::set() hands PDO',
    );

    // And the plaintext, which is a different failure with the same consequence.
    expect(str_contains($joined, VAULT_LOG_TEST_CREDENTIAL))->toBeFalse(
        'the submitted plaintext credential reached a log line',
    );

    // STILL LOUD. A scrub that silenced the failure would trade one finding for another: an
    // operator has to be able to see that a credential write failed, and to correlate it.
    expect(str_contains($joined, 'scrubbed before logging'))->toBeTrue($joined);
    expect(str_contains($joined, '23514'))->toBeTrue($joined);
});

it('logs nothing key-shaped when the rotation UPDATE fails', function (): void {
    // THE SECOND WRITER, ASSERTED SEPARATELY. `rotateCredential()` writes the same two columns from
    // a different statement, and a fix applied at one call site would leave this one leaking — which
    // is the argument for putting the guard in the reporting funnel rather than in the repository.
    $fixture = vaultLogFixture();

    $connection = ProviderConnection::factory()->recycle($fixture['org'])->create();

    // The session is established BEFORE the recorder, so the only lines it holds are the ones the
    // rotation produced.
    SpaSession::establish(currentTest(), $fixture['actor']);

    $recorder = recordEveryLogLine();

    breakProviderConnectionWrites();

    currentTest()->putJson(
        "/api/v1/organizations/{$fixture['org']->id}/provider-connections/{$connection->id}/credential",
        [
            'current_password' => UserFactory::PASSWORD,
            'credential' => VAULT_LOG_TEST_CREDENTIAL,
        ],
        spaHeaders(),
    )->assertStatus(500);

    $joined = implode("\n", array_map(
        static fn (array $line): string => $line['message'],
        $recorder->lines,
    ));

    expect($joined)->not->toBe('', 'nothing was logged, so this proves nothing');

    expect(preg_match('/\\\\x(?:[0-9a-f]{2}){16,}/i', $joined))->toBe(0, $joined);
    expect(str_contains($joined, VAULT_LOG_TEST_CREDENTIAL))->toBeFalse();
    expect(str_contains($joined, ProviderConnectionFactory::FIXTURE_CREDENTIAL))
        ->toBeFalse('the credential the row was sealed with reached a log line');
});

it('leaves an ordinary query failure fully reported', function (): void {
    // THE COST CONTROL. A scrub that fired on every QueryException would make every database
    // failure in the application undiagnosable, and would pass every assertion above. This asserts
    // the narrowing: a statement with no vault table and no encoded-bytes binding still reports the
    // driver's own message, interpolated bindings and all.
    $recorder = recordEveryLogLine();

    Route::get(
        'api/v1/_test/ordinary-query-failure',
        static fn () => Schema::getConnection()
            ->select('select * from a_table_that_does_not_exist where id = ?', ['01JQZ']),
    );

    currentTest()->getJson('api/v1/_test/ordinary-query-failure')->assertStatus(500);

    $joined = implode("\n", array_map(
        static fn (array $line): string => $line['message'],
        $recorder->lines,
    ));

    expect(str_contains($joined, 'a_table_that_does_not_exist'))->toBeTrue($joined);
    expect(str_contains($joined, 'scrubbed before logging'))->toBeFalse(
        'an unrelated query failure was scrubbed, which makes every database error undiagnosable',
    );
});

it('recognises an encoded-bytes binding on a table nobody listed', function (): void {
    // THE SECOND DETECTION TEST, PROVED DIRECTLY. A table-name list is exactly the mechanism that
    // fails silently on the next bytea column somebody adds, so the binding-shape check has to work
    // without one — and asserting it through HTTP would require a second credential-bearing table
    // to exist first.
    $sealed = '\x'.bin2hex(random_bytes(48));

    $vaultish = new QueryException(
        'pgsql',
        'insert into "some_future_table" ("secret_bytes") values (?)',
        [$sealed],
        new \PDOException('SQLSTATE[40P01]: Deadlock detected'),
    );

    expect(VaultQueryScrubber::isVaultQuery($vaultish))->toBeTrue();
    expect(str_contains(VaultQueryScrubber::summarize($vaultish), $sealed))->toBeFalse();

    // …and does not fire on the ordinary bindings this application really binds: a ULID, a label, a
    // status, an email. Over-scrubbing is not free — it costs an operator the values on every
    // failure it touches — so the narrowness is asserted rather than assumed.
    $ordinary = new QueryException(
        'pgsql',
        'insert into "bots" ("id", "name") values (?, ?)',
        ['01JQZ0000000000000000000AA', 'Support bot'],
        new \PDOException('SQLSTATE[23505]: Unique violation'),
    );

    expect(VaultQueryScrubber::isVaultQuery($ordinary))->toBeFalse();
});
