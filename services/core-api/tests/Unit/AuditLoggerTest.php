<?php

declare(strict_types=1);

use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Tests\Support\AuditLogRepositorySpy;
use Tests\Support\RecordingLogger;

/*
|--------------------------------------------------------------------------
| AuditLogger — the allow-list, the fingerprinting, and the failure policy
|--------------------------------------------------------------------------
|
| Unit/, so: no container, no database, no facades (tests/Pest.php). Everything below runs against a
| fake repository and a recording logger, which is possible only because AuditLogger takes an
| INTERFACE — see AuditLogRepositoryInterface's docblock for why the interface exists at all.
|
| THE FIXTURE CARRIES FOUR SECRET-SHAPED VALUES, NOT ONE. kb-security-baseline:164 is explicit that a
| redaction test naming a single field, or a one-entry fixture, passes while the second and third
| leak — so the assertion here iterates every planted secret and scans the WHOLE persisted payload
| for each, rather than checking the field somebody remembered.
*/

/**
 * The four planted secrets. Three of their KEYS are absent from every allow-list; the fourth
 * (`token`) is present but declared FINGERPRINTED, so its plaintext must not survive either.
 *
 * THE VALUES ARE DELIBERATELY LOW-ENTROPY AND SELF-DESCRIBING, following UserFactory::PASSWORD's
 * `fixture-…-not-a-secret` convention. That convention is now the WHOLE of the protection: the CI job
 * that ran gitleaks over the worktree and the whole history was deleted on 2026-08-17, and the
 * repo-root `.gitleaks.toml` that remains exempts only password-shaped fixtures in test paths — an
 * `'api_key' => 'sk-live-<high entropy>'` here would still be a finding for anyone who runs the scan
 * by hand, but nothing runs it for you, and
 * an inline `gitleaks:allow` would BE the waiver that policy forbids. Nothing here needs a real key
 * shape: these four assert the ALLOW-LIST, which filters on the field NAME. The one test that needs a
 * credential-SHAPED value builds it with str_repeat() at runtime.
 *
 * @return array<string, string>
 */
function auditSecretFixture(): array
{
    return [
        'token' => 'audit-fixture-token-not-a-secret',
        'password' => 'audit-fixture-password-not-a-secret',
        'api_key' => 'audit-fixture-api-key-not-a-secret',
        'provider_credential' => 'audit-fixture-credential-not-a-secret',
    ];
}

function auditLoggerFor(AuditLogRepositorySpy $repository, RecordingLogger $log): AuditLogger
{
    return new AuditLogger($repository, $log, 'base64:Y2Ftby10ZXN0LWFwcC1rZXktMzItYnl0ZXMtbG9uZyE=');
}

it('writes none of FOUR secret-shaped values into details, and fingerprints the one it keeps', function (): void {
    $repository = new AuditLogRepositorySpy;
    $log = new RecordingLogger;
    $secrets = auditSecretFixture();

    auditLoggerFor($repository, $log)->record(
        AuditLogger::PASSWORD_RESET_COMPLETED,
        '01JD000000000000000000ORG1',
        '01JD00000000000000000USER1',
        $secrets + ['email' => 'bob@example.test'],
    );

    $persisted = $repository->last();
    /** @var array<string, bool|float|int|string> $details */
    $details = $persisted['details'];

    // The whole payload as one string, so a leak into ANY field — a key, a value, a field somebody
    // adds later — is caught. Not `->not->toContain()`: DenyOracleTest:249-257 explains why absence is
    // asserted as a boolean rather than as a negated matcher.
    $payload = (string) json_encode($persisted);

    foreach ($secrets as $key => $secret) {
        expect(str_contains($payload, $secret))->toBeFalse(
            "the plaintext of '{$key}' reached the audit row; audit_logs is append-only and "
            .'long-lived, so this value is now unremovable (kb-security-baseline:147)',
        );
    }

    // `token` was admitted, as a fingerprint, under a key that says so.
    expect($details)->toHaveKey('token_fingerprint')
        ->and($details)->not->toHaveKey('token')
        ->and($details['token_fingerprint'])->toBeString()
        ->and(strlen((string) $details['token_fingerprint']))->toBe(16)
        // The email is allow-listed and IS echoed — deliberately, see OPERATIONS' docblock.
        ->and($details['email'])->toBe('bob@example.test');
});

it('drops a key that is not in the operation allow-list, and says so at WARNING', function (): void {
    $repository = new AuditLogRepositorySpy;
    $log = new RecordingLogger;

    auditLoggerFor($repository, $log)->record(
        AuditLogger::LOGIN_SUCCEEDED,
        null,
        '01JD00000000000000000USER1',
        ['email' => 'bob@example.test', 'shoe_size' => '44', 'password' => 'hunter2hunter2'],
    );

    /** @var array<string, bool|float|int|string> $details */
    $details = $repository->last()['details'];

    expect($details)->toBe(['email' => 'bob@example.test']);

    $warnings = $log->messagesAt('warning');

    expect($warnings)->toHaveCount(1);

    // The NAMES are reported so an author sees their field did not ship; the VALUES never are,
    // because a dropped value is dropped precisely because it might be the unwritable thing.
    expect(str_contains($warnings[0], 'shoe_size'))->toBeTrue()
        ->and(str_contains($warnings[0], 'password'))->toBeTrue()
        ->and(str_contains($warnings[0], 'hunter2hunter2'))->toBeFalse()
        ->and(str_contains($warnings[0], AuditLogger::LOGIN_SUCCEEDED))->toBeTrue();
});

it('fingerprints rather than echoes an allow-listed field whose VALUE is credential-shaped', function (): void {
    $repository = new AuditLogRepositorySpy;
    $log = new RecordingLogger;

    // `email` is ECHOED. A vendor-shaped key arriving in it is a defect in the map or a caller
    // passing the wrong variable; either way the value must not land, and the drop must be loud.
    auditLoggerFor($repository, $log)->record(
        AuditLogger::LOGIN_FAILED,
        null,
        null,
        ['email' => 'sk-ant-'.str_repeat('A1b2', 6), 'reason' => 'invalid_credentials'],
    );

    /** @var array<string, bool|float|int|string> $details */
    $details = $repository->last()['details'];

    // THE VALUE IS GONE AND THE ROW IS STILL CORRELATABLE, and the second half is why this changed from
    // a plain drop. `auth.login.failed` carries `organization_id = NULL` and `actor_id = NULL` by
    // design — there is no tenant and no identity for a failed login — so dropping `email` left a row
    // that identified nothing, and a caller only had to choose a username of the right shape to make
    // every failed-login row anonymous.
    //
    // `sk-ant-…` is the shape that still fires, and deliberately: it is a VENDOR KEY PREFIX, caught by a
    // rule that recognises the credential's own shape. The `key=value` addresses that used to be caught
    // here are now echoed — see the two datasets above, which assert both halves of that split.
    expect($details)->toHaveKey('reason')
        ->and($details['reason'])->toBe('invalid_credentials')
        // Not echoed, under any key.
        ->and(json_encode($details))->not->toContain('sk-ant-')
        ->and($details)->not->toHaveKey('email')
        // But present as a keyed digest, so an investigation can still ask "was it THIS address".
        ->and($details)->toHaveKey('email_fingerprint')
        // Still LOUD: a genuinely mis-mapped ECHO field is reported, not hidden by the fingerprint.
        ->and($log->messagesAt('warning'))->toHaveCount(1);

    // AND THE FINGERPRINT IS THE ONE THING IT CLAIMS TO BE: deterministic for the same value, and
    // different for a different one. Without both halves this could be a constant and still pass above.
    $second = new AuditLogRepositorySpy;
    auditLoggerFor($second, new RecordingLogger)->record(
        AuditLogger::LOGIN_FAILED,
        null,
        null,
        ['email' => 'sk-ant-'.str_repeat('A1b2', 6), 'reason' => 'invalid_credentials'],
    );

    $third = new AuditLogRepositorySpy;
    auditLoggerFor($third, new RecordingLogger)->record(
        AuditLogger::LOGIN_FAILED,
        null,
        null,
        ['email' => 'sk-ant-'.str_repeat('C3d4', 6), 'reason' => 'invalid_credentials'],
    );

    expect($second->last()['details']['email_fingerprint'])->toBe($details['email_fingerprint'])
        ->and($third->last()['details']['email_fingerprint'])->not->toBe($details['email_fingerprint']);
});

it('ECHOES a legitimate address containing `=`, which the message rule set used to eat', function (
    string $address,
): void {
    // THE POINT OF THE NARROWING (D47 + the redactValue() split). `=` is legal `atext` in a dot-atom,
    // so every address below passes `email:rfc,strict` under this project's own egulias pair — they are
    // addresses a real user can register and log in with. The MESSAGE rule set matched them all, because
    // `CREDENTIAL_KEY_VALUE` discriminates on a key name sitting beside a value in prose. In an audit
    // detail the key is already the map's key name, so that match is on incidental text.
    //
    // Consequence while they were eaten: `auth.login.failed` carries `organization_id = NULL` and
    // `actor_id = NULL` by design, so the row identified NOTHING — a caller only had to choose a
    // username of this shape to make every failed-login row anonymous. Now the value is kept, the row
    // names the account, and no WARNING fires because nothing was dropped.
    $repository = new AuditLogRepositorySpy;
    $log = new RecordingLogger;

    auditLoggerFor($repository, $log)->record(
        AuditLogger::LOGIN_FAILED,
        null,
        null,
        ['email' => $address, 'reason' => 'invalid_credentials'],
    );

    /** @var array<string, bool|float|int|string> $details */
    $details = $repository->last()['details'];

    expect($details['email'] ?? null)->toBe($address)
        ->and($details)->not->toHaveKey('email_fingerprint')
        ->and($log->messagesAt('warning'))->toBe([]);
})->with([
    'token=' => 'token=abc@example.com',
    'password=' => 'password=hunter2@example.com',
    'x-api-key=' => 'x-api-key=q@example.com',
    'cookie=' => 'cookie=a@example.com',
    'dotted key' => 'my.token=x@example.com',
]);

it('still fingerprints a value carrying a real credential SHAPE', function (string $value): void {
    // THE OTHER HALF, so the narrowing above cannot be mistaken for switching the backstop off. Each of
    // these is caught by a rule that recognises the credential's own shape rather than a sentence about
    // one, and every one is retained by `redactValue()`.
    $repository = new AuditLogRepositorySpy;

    auditLoggerFor($repository, new RecordingLogger)->record(
        AuditLogger::LOGIN_FAILED,
        null,
        null,
        ['email' => $value, 'reason' => 'invalid_credentials'],
    );

    /** @var array<string, bool|float|int|string> $details */
    $details = $repository->last()['details'];

    expect($details)->not->toHaveKey('email')
        ->and($details)->toHaveKey('email_fingerprint');
})->with([
    // A vendor key prefix. Also the residual FALSE POSITIVE, deliberately unfixed: the only available
    // narrowing is a lookahead refusing a match followed by `@domain`, which would stop catching a
    // credential in URL userinfo position (`https://sk-ant-…@host/`) — a real leak shape traded for a
    // rare address. The fingerprint is what makes it a degradation rather than a loss.
    'vendor prefix' => 'sk-ant-A1b2C3d4E5f6',
    'bearer scheme' => 'Bearer abcdefghijklmnop',
    'internal signature' => 'KB1 abcdefghijklmnop',
    // A URL with a query string — after the narrowing this is the ONLY remaining catcher of a capability
    // in a `?token=`, which `App\Notifications\ResetPassword` really builds.
    'capability in a query string' => 'https://app.example/reset-password?token=abcdefghijklmnop',
]);

it('keeps a readable rendering beside the fingerprint when the backstop eats part of a value', function (): void {
    // FINDING S3. A fingerprint answers "was it THIS value" and nothing else. That is enough for an
    // email; it is NOT enough for a field whose CONTENT is the security fact — and `capabilities` is
    // exactly that, because an `embedding` flag decides which credential embeds an organization's
    // corpus (see AuditLogger's three-operation docblock, which calls it the security-relevant field
    // of those operations).
    //
    // `capabilities` is joined from `supported.*`, which is tenant-controlled, so
    // `supported: ["embedding", "sk-aaaaaaaaaaaa"]` used to make the whole field unreadable in an
    // APPEND-ONLY table: a tenant blanking the record of what they just changed, from a form, with
    // nothing but a WARNING in Loki — which this repository's own doctrine says is not an audit
    // record. The input side is now refused by a character class on `supported.*`; this is the
    // other half, because the backstop must still degrade rather than destroy for every future
    // caller.
    $repository = new AuditLogRepositorySpy;
    $log = new RecordingLogger;

    // Built at runtime rather than written out, so the assertion and the fixture cannot disagree
    // about how many characters `sk-` needs to clear VENDOR_KEYS' twelve-character floor.
    $vendorShaped = 'sk-'.str_repeat('a', 12);

    auditLoggerFor($repository, $log)->record(
        AuditLogger::PROVIDER_MODEL_UPDATED,
        '01JD00000000000000000ORGA',
        '01JD00000000000000000USER1',
        [
            'connection_id' => '01JD0000000000000000CONN01',
            'model' => 'text-embedding-3-large',
            'display_name' => 'Text Embedding 3 Large',
            'capabilities' => 'embedding,'.$vendorShaped,
            'enabled' => true,
        ],
        subjectType: 'App\\Models\\ProviderModelEntry',
        subjectId: '01JD0000000000000000MODEL1',
    );

    /** @var array<string, bool|float|int|string> $details */
    $details = $repository->last()['details'];

    // THE VALUE IS STILL GONE — this is a degradation, not a relaxation.
    expect($details)->not->toHaveKey('capabilities')
        ->and(json_encode($details))->not->toContain($vendorShaped)
        // Still fingerprinted, so "was it this exact list" is still answerable.
        ->and($details)->toHaveKey('capabilities_fingerprint')
        // Still LOUD: a mis-mapped ECHO field is reported, not hidden behind the degradation.
        ->and($log->messagesAt('warning'))->toHaveCount(1);

    // AND THE ROW STILL SAYS WHAT MATTERED. Only the matched run became the marker; the flag that
    // decides which credential embeds the corpus survived.
    expect($details['capabilities_redacted'] ?? null)->toBe('embedding,[REDACTED]');
});

it('writes no redacted rendering when the whole value was the credential', function (): void {
    // THE NARROWING. When the value IS the credential and nothing else, `[REDACTED]` says exactly
    // what the fingerprint's presence already says, and an audit row is not the place to write one
    // fact twice. Without this the table would grow a column of identical markers on the one
    // operation (`auth.login.failed`) whose input is openly hostile.
    $repository = new AuditLogRepositorySpy;

    auditLoggerFor($repository, new RecordingLogger)->record(
        AuditLogger::LOGIN_FAILED,
        null,
        null,
        ['email' => 'sk-ant-'.str_repeat('A1b2', 6), 'reason' => 'invalid_credentials'],
    );

    /** @var array<string, bool|float|int|string> $details */
    $details = $repository->last()['details'];

    expect($details)->toHaveKey('email_fingerprint')
        ->and($details)->not->toHaveKey('email_redacted')
        ->and($details)->not->toHaveKey('email');
});

it('reports a value that NORMALIZED away, and stays silent about one that arrived empty', function (): void {
    // FINDING S7, THE GENERAL HALF. An allow-listed key whose value vanishes with no drop record is
    // the same class of defect as an ECHOED field the backstop ate — the row is quietly less than
    // it claims to be, and nobody finds out.
    //
    // But making EVERY empty value loud was the wrong fix and would have been worse than the bug:
    // `capabilities` is `implode(',', $flags)`, so a model row that claims no flags legitimately
    // produces `''`, and ProviderModelService's docblock states in two places that the key is then
    // simply absent. A WARNING per unflagged write is how a signal that means "your field did not
    // ship" becomes noise nobody reads.
    //
    // So the discriminator is whether the value was ALREADY empty on arrival. A caller supplying
    // `''` said nothing and is not reported; a caller supplying `"   "` supplied something that
    // READS as content at the call site and STORES as nothing, and that is reported.
    $repository = new AuditLogRepositorySpy;
    $log = new RecordingLogger;

    auditLoggerFor($repository, $log)->record(
        AuditLogger::LOGIN_FAILED,
        null,
        null,
        ['email' => "  \t \n ", 'reason' => ''],
    );

    /** @var array<string, bool|float|int|string> $details */
    $details = $repository->last()['details'];

    expect($details)->toBe([]);

    $warnings = $log->messagesAt('warning');

    expect($warnings)->toHaveCount(1);

    // `email` normalized away and IS named. `reason` arrived empty and is NOT — and the VALUE never
    // appears either way, which is the standing rule for a drop report.
    expect(str_contains($warnings[0], 'email'))->toBeTrue($warnings[0]);
    expect(str_contains($warnings[0], 'reason'))->toBeFalse($warnings[0]);
});

it('drops a non-scalar detail value, which is the shape $request->all() arrives in', function (): void {
    $repository = new AuditLogRepositorySpy;
    $log = new RecordingLogger;

    auditLoggerFor($repository, $log)->record(
        AuditLogger::LOGIN_FAILED,
        null,
        null,
        ['email' => ['nested' => 'audit-fixture-api-key-not-a-secret'], 'reason' => 'unknown_user'],
    );

    /** @var array<string, bool|float|int|string> $details */
    $details = $repository->last()['details'];

    expect($details)->toBe(['reason' => 'unknown_user'])
        ->and(str_contains((string) json_encode($repository->last()), 'audit-fixture'))->toBeFalse();
});

it('accepts nothing at all in the details of an operation whose allow-list is empty', function (): void {
    $repository = new AuditLogRepositorySpy;
    $log = new RecordingLogger;

    auditLoggerFor($repository, $log)->record(
        AuditLogger::LOGOUT,
        '01JD000000000000000000ORG1',
        '01JD00000000000000000USER1',
        ['email' => 'bob@example.test'],
    );

    expect($repository->last()['details'])->toBe([])
        ->and($log->messagesAt('warning'))->toHaveCount(1);
});

it('derives outcome from the operation so no row can disagree with its own name', function (): void {
    $repository = new AuditLogRepositorySpy;
    $log = new RecordingLogger;
    $logger = auditLoggerFor($repository, $log);

    $logger->record(AuditLogger::LOGIN_FAILED, null, null, ['reason' => 'unknown_user']);
    $logger->record(AuditLogger::LOGIN_SUCCEEDED, null, '01JD00000000000000000USER1');

    expect($repository->writes[0]['outcome'])->toBe(AuditLogger::OUTCOME_FAILURE)
        ->and($repository->writes[1]['outcome'])->toBe(AuditLogger::OUTCOME_SUCCESS);
});

it('fingerprints deterministically, and differently for different values', function (): void {
    $repository = new AuditLogRepositorySpy;
    $log = new RecordingLogger;
    $logger = auditLoggerFor($repository, $log);

    $logger->record(AuditLogger::PASSWORD_RESET_REQUESTED, null, null, ['token' => 'aaa-token']);
    $logger->record(AuditLogger::PASSWORD_RESET_REQUESTED, null, null, ['token' => 'aaa-token']);
    $logger->record(AuditLogger::PASSWORD_RESET_REQUESTED, null, null, ['token' => 'bbb-token']);

    $first = $repository->writes[0]['details']['token_fingerprint'];
    $second = $repository->writes[1]['details']['token_fingerprint'];
    $third = $repository->writes[2]['details']['token_fingerprint'];

    // Deterministic: "was THIS link the one used" is the only question asked of a fingerprint.
    expect($first)->toBe($second)
        // Distinguishing: a fingerprint that collapsed every token to one value would answer it wrong.
        ->and($first)->not->toBe($third);
});

it('refuses an unknown operation instead of writing a row nobody decided the rules for', function (): void {
    $repository = new AuditLogRepositorySpy;

    expect(fn () => auditLoggerFor($repository, new RecordingLogger)
        ->record('auth.login.suceeded', null, null))
        ->toThrow(\InvalidArgumentException::class);

    expect($repository->writes)->toBe([]);
});

it('refuses a half-specified subject, because the database refuses it too', function (): void {
    $logger = auditLoggerFor(new AuditLogRepositorySpy, new RecordingLogger);

    expect(fn () => $logger->record(
        AuditLogger::ROLE_CHANGED,
        '01JD000000000000000000ORG1',
        '01JD00000000000000000USER1',
        ['from_role' => 'member', 'to_role' => 'admin'],
        subjectType: 'user',
    ))->toThrow(\InvalidArgumentException::class);
});

it('lets a failed write stand for an authentication event, loudly, without a 500', function (): void {
    // The session cookie has already been issued. There is no transaction left to roll back, so
    // "fail the operation" would mean a 500 to a caller who IS logged in — and still no audit row.
    $repository = new AuditLogRepositorySpy(new \RuntimeException('SQLSTATE[08006] connection lost'));
    $log = new RecordingLogger;

    $result = auditLoggerFor($repository, $log)->record(
        AuditLogger::LOGIN_SUCCEEDED,
        null,
        '01JD00000000000000000USER1',
        ['email' => 'bob@example.test'],
    );

    expect($result)->toBeNull();

    $errors = $log->messagesAt('error');

    expect($errors)->toHaveCount(1)
        ->and(str_contains($errors[0], AuditLogger::LOGIN_SUCCEEDED))->toBeTrue()
        // The compliance record is now incomplete and the line has to say so, or the only reader who
        // could act on it reads "an error happened" and moves on.
        ->and(str_contains($errors[0], 'incomplete'))->toBeTrue();
});

it('aborts a state change when its audit row cannot be written', function (): void {
    // kb-observability-conventions: the audit row is inside the operation's transaction and its
    // failure fails the operation. A role change that commits unaudited is exactly the event this
    // table exists for.
    $repository = new AuditLogRepositorySpy(new \RuntimeException('SQLSTATE[08006] connection lost'));

    expect(fn () => auditLoggerFor($repository, new RecordingLogger)->record(
        AuditLogger::ROLE_CHANGED,
        '01JD000000000000000000ORG1',
        '01JD00000000000000000USER1',
        ['from_role' => 'member', 'to_role' => 'owner'],
        subjectType: 'user',
        subjectId: '01JD00000000000000000USER2',
    ))->toThrow(\RuntimeException::class);
});

it('takes ip and user agent from the request, and refuses an ip that is not one', function (): void {
    $repository = new AuditLogRepositorySpy;
    $logger = auditLoggerFor($repository, new RecordingLogger);

    $logger->record(AuditLogger::LOGOUT, null, '01JD00000000000000000USER1', [], null, null,
        Request::create('/api/v1/auth/logout', 'POST', server: [
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (probe)',
        ]));

    // ip_address is `inet`: an unparseable value is a 22P02 that would fail the whole audit insert,
    // and the value comes from a header a client controls.
    $logger->record(AuditLogger::LOGOUT, null, '01JD00000000000000000USER1', [], null, null,
        Request::create('/api/v1/auth/logout', 'POST', server: ['REMOTE_ADDR' => '../../etc/passwd']));

    expect($repository->writes[0]['ip_address'])->toBe('203.0.113.7')
        ->and($repository->writes[0]['user_agent'])->toBe('Mozilla/5.0 (probe)')
        ->and($repository->writes[1]['ip_address'])->toBeNull();
});

it('declares a complete, well-formed rule for every operation constant', function (): void {
    // The map is the closed list the table deliberately does not CHECK. If an operation constant
    // exists with no entry, the first call site using it throws in production instead of here.
    // Every dotted string constant is an operation name; ECHOED/OUTCOME_*/ON_FAILURE_* have no dot
    // and the private bounds are ints, so the shape of the value is the discriminator rather than a
    // hand-maintained exclusion list that would go stale on the next constant.
    $operations = [];

    foreach ((new \ReflectionClass(AuditLogger::class))->getConstants() as $value) {
        if (is_string($value) && str_contains($value, '.')) {
            $operations[] = $value;
        }
    }

    // PINNED BY NAME, NOT BY COUNT, and the difference matters twice.
    //
    // `toHaveCount(n)` was the original form and it went stale the first time an operation was added
    // (`organization.invitation.resent`, for a resend that mints a new bearer capability and so must be
    // audited like any other issuance). A bare number also fails uninformatively: "expected 10, got 11"
    // does not say which operation appeared, so the reader cannot tell a deliberate addition from a
    // typo'd duplicate. This is the same rule ADR-036 states for prose — publish the measuring
    // command, not the measurement — applied to an assertion.
    //
    // The set comparison below it is NOT redundant: this list pins the constants, that line pins
    // constants-against-rules. Together they catch the one failure neither catches alone — a constant
    // and its rule deleted TOGETHER, which leaves both sides agreeing with each other and wrong.
    $expected = [
        'auth.login.succeeded',
        'auth.login.failed',
        'auth.logout',
        'auth.password_reset.requested',
        'auth.password_reset.completed',
        'auth.email.verified',
        'organization.invitation.created',
        'organization.invitation.revoked',
        'organization.invitation.accepted',
        'organization.invitation.resent',
        'organization.member.role_changed',
        // THE PROVIDER SURFACE, ADDED WHEN IT STOPPED BEING UNAUDITED. Until the connection
        // resource was completed a credential could be created, relabelled, revoked, hard-deleted
        // or replaced with nothing in `audit_logs` to say so — a §18.11 gap rather than a deferred
        // nicety, which is why `provider.connection.created` was wired into the pre-existing
        // `store` action in the same change.
        'provider.connection.created',
        'provider.connection.updated',
        'provider.connection.deleted',
        'provider.connection.credential_rotated',
        // THE ONLY FAILURE OUTCOME ON THE PROVIDER SURFACE. `current_password:web` lives in
        // RotateProviderCredentialRequest::rules(), which is right — a wrong password must touch no
        // column — and the consequence was that a failed attempt never reached the service and all
        // four operations above were OUTCOME_SUCCESS. `auth.login.failed` exists for the login
        // surface; the endpoint that CHANGES a credential had no equivalent, so a stolen session
        // could grind at the re-authentication behind it and leave nothing in audit_logs.
        'provider.connection.credential_rotation_failed',
        // THE MODEL CATALOG, AUDITED FOR WHAT A ROW DECIDES RATHER THAN FOR WHAT IT HOLDS. A
        // `provider_models` row carries no secret — but its `capability_flags` are the ROW axis of
        // the capability question, and embedding_selection.py reads them to decide which of an
        // organization's connections embeds its corpus. So adding an `embedding` flag can change
        // the vector space every future upload is indexed under, and disabling the only
        // embedding-capable row stops ingestion outright. Neither is visible in the connection
        // operations above.
        'provider.model.created',
        'provider.model.updated',
        'provider.model.deleted',
        // THE BOT SURFACE, AUDITED ON THE SAME TEST AS THE CATALOG ABOVE: what the row DECIDES. A
        // bot carries no secret either — its `provider_connection_id` is a ULID and the key behind
        // it is never reachable from the row — but `access_mode` moving to `public` makes it
        // answerable by an anonymous visitor, `status` moving to `published` exposes it on every
        // channel, and the pair of provider ids decides which credential is BILLED for every
        // answer. None of those transitions leaves a trace anywhere else. `bot.deleted` matters
        // most for the same reason `provider.model.deleted` does: it is a HARD delete that also
        // removes the origin allow-list, the starter questions and the fallback chain, so this row
        // is the only surviving description and its `subject_id` resolves to nothing afterwards.
        'bot.created',
        'bot.updated',
        'bot.deleted',
        // THE ORIGIN ALLOW-LIST, AND THESE THREE ARE FINDING L2 BEING CLOSED RATHER THAN A GENERAL
        // PRINCIPLE. A bot delete is a HARD delete that destroys `bot_domains` with it, and that
        // table justifies its own ON DELETE RESTRICT by saying a security review may later need to
        // RECONSTRUCT the allow-list — which the trail could not do, because `bot.deleted`
        // described the bot in full and said nothing about what it permitted. It was latent only
        // while no route created a domain. These rows carry ONE ORIGIN EACH, verbatim, with the
        // actor and the time; they are append-only and they outlive the bot, so they are what
        // actually answers "what could embed this, and who allowed it". The scalar summaries added
        // to the three `bot.*` allow-lists above are the tripwire that sends a reader here.
        'bot.domain.created',
        'bot.domain.status_changed',
        'bot.domain.deleted',
        // THE STARTER QUESTIONS, on §18.11's "bot config changes" rather than on a security
        // argument — and without the question TEXT, which is unbounded tenant prose of exactly the
        // kind the `bot.*` allow-lists refuse. Without these rows, editing the suggestion chips
        // would be the one bot configuration change that left no trace anywhere: `bots` is
        // untouched by it, so not even `bot.updated` fires. The asymmetry with the three above is
        // deliberate — an origin string IS a security fact, a chip label is text on a button.
        'bot.starter_question.created',
        'bot.starter_question.updated',
        'bot.starter_question.deleted',
        // THE SOURCE CASCADE (Phase C1), and the argument for auditing it is not the provider
        // surface's. A knowledge source carries no credential and grants no access on its own —
        // what it decides is WHAT THE BOT SAYS. An answer a customer disputes is explained by which
        // documents were retrievable when it was produced, and every one of these twelve moves that
        // set: create/update/delete change the corpus, disable/enable change whether it answers at
        // all with every vector retained, reprocess changes WHICH version answers at a provider's
        // per-token price, and the version pair records the POINTER SWITCH — the single act that
        // decides what every tenant's next query sees, which is Laravel's precisely because the
        // audit row lives here (ADR-012).
        'source.created',
        'source.updated',
        'source.deleted',
        'source.disabled',
        'source.enabled',
        'source.reprocess.requested',
        // THE UPLOAD PAIR, AND THE REJECTION IS THE ONE THAT MATTERS. kb-security-baseline's upload
        // gate is six steps and every one can refuse; without a FAILURE row a caller grinding at it
        // with crafted files leaves no trace anywhere, because nothing was written. Same gap
        // `provider.connection.credential_rotation_failed` closes on the credential surface.
        'source.upload.accepted',
        'source.upload.rejected',
        'source.version.activated',
        'source.version.retired',
        // THE RETRIEVAL-SCOPE GRANT. `bot_source_assignments` is the one row in the schema that can
        // span two organizations; the composite foreign keys make the illegal version impossible
        // and these rows record the legal one. After this row exists a bot answers from documents
        // it could not reach before — finding L2's argument, one entity over and with documents
        // rather than an embed permission at stake.
        'bot.source_assignment.created',
        'bot.source_assignment.deleted',
    ];

    expect($operations)->toEqualCanonicalizing($expected)
        ->and(array_keys(AuditLogger::OPERATIONS))->toEqualCanonicalizing($operations);

    // ── THE WRITE-FAILURE POLICY, PINNED PER OPERATION BY NAME ──────────────────────────────────
    //
    // The `$isOneOf` check below asserts each `on_failure` is one of the two constants, which is a TYPE
    // CHECK: moving `organization.member.role_changed` from ABORT to LOG satisfies it, and a role change
    // that leaves no audit row is exactly what D20 exists to prevent. So the policy is pinned by name
    // here, the same way and for the same reason the operation set is (D29 / ADR-036: publish the rule,
    // not a count — the class docblock used to say "the three authentication-outcome events" while four
    // operations carried LOG).
    //
    // THE RULE THIS TABLE ENCODES: LOG where the audited thing can no longer be rolled back, ABORT where
    // it can. The four LOG rows are not "the authentication events" — `auth.password_reset.requested`
    // is not one, and earns LOG because the broker has already QUEUED THE MAIL, and no rollback recalls
    // an email. Everything else is a state change whose row belongs in the same transaction.
    $expectedPolicy = [
        'auth.login.succeeded' => AuditLogger::ON_FAILURE_LOG,
        'auth.login.failed' => AuditLogger::ON_FAILURE_LOG,
        'auth.logout' => AuditLogger::ON_FAILURE_LOG,
        'auth.password_reset.requested' => AuditLogger::ON_FAILURE_LOG,
        'auth.password_reset.completed' => AuditLogger::ON_FAILURE_ABORT,
        'auth.email.verified' => AuditLogger::ON_FAILURE_ABORT,
        // NO `organization.bootstrapped` — `kb:bootstrap-organization` creates the first organization
        // and owner and records NOTHING, which is a real recorded gap rather than an operation with a
        // policy. It is listed here as a comment because this table is the closed set of operations, so
        // its absence is the honest statement; when the operation lands it gets ABORT (the command's work
        // is one transaction in a repository) and a line above.
        'organization.invitation.created' => AuditLogger::ON_FAILURE_ABORT,
        'organization.invitation.revoked' => AuditLogger::ON_FAILURE_ABORT,
        'organization.invitation.accepted' => AuditLogger::ON_FAILURE_ABORT,
        'organization.invitation.resent' => AuditLogger::ON_FAILURE_ABORT,
        'organization.member.role_changed' => AuditLogger::ON_FAILURE_ABORT,
        // ALL FOUR ARE ABORT, with no judgement call to make. Every one of them is written inside
        // the repository transaction that performs the change, so "can this still be rolled back"
        // — the real test, not "is this an authentication event" — answers yes for all four.
        // Nothing on the provider surface queues mail and nothing has already happened
        // irreversibly by the time the row is written. `deleted` matters most: it is a HARD delete,
        // so the audit row is the only surviving description of the connection, and a LOG policy
        // there would permit a credential to vanish leaving no record it ever existed.
        'provider.connection.created' => AuditLogger::ON_FAILURE_ABORT,
        'provider.connection.updated' => AuditLogger::ON_FAILURE_ABORT,
        'provider.connection.deleted' => AuditLogger::ON_FAILURE_ABORT,
        'provider.connection.credential_rotated' => AuditLogger::ON_FAILURE_ABORT,
        // THE ONE LOG ROW ON THIS SURFACE, and it is the same "can this still be rolled back" test
        // answering NO from the other direction: there is no state change to undo. The 422 is
        // already decided, the connection was never touched, and aborting would turn a wrong
        // password into a 500 — which is both a lie to the caller and still no audit row.
        'provider.connection.credential_rotation_failed' => AuditLogger::ON_FAILURE_LOG,
        // ALL THREE ARE ABORT, on the same test and with the same answer: each is written inside
        // EloquentProviderModelRepository's transaction, so the change can still be rolled back
        // when the row cannot be written. `deleted` matters most for the same reason it does one
        // block up — it is a HARD delete, so the audit row is the only surviving description of
        // the catalog entry, and a LOG policy there would let a model row vanish leaving no record
        // it ever existed and a `subject_id` pointing at a ULID no table resolves.
        'provider.model.created' => AuditLogger::ON_FAILURE_ABORT,
        'provider.model.updated' => AuditLogger::ON_FAILURE_ABORT,
        'provider.model.deleted' => AuditLogger::ON_FAILURE_ABORT,
        // ALL THREE ARE ABORT, on the same test and with the same answer: each is written inside
        // EloquentBotRepository's transaction, so the change can still be rolled back when the row
        // cannot be written. `deleted` matters most, and slightly more than it does one block up:
        // the transaction removes the bot AND its origin allow-list, its starter questions and its
        // fallback chain, so a LOG policy there would let a whole configuration graph vanish with
        // no record that any of it existed.
        'bot.created' => AuditLogger::ON_FAILURE_ABORT,
        'bot.updated' => AuditLogger::ON_FAILURE_ABORT,
        'bot.deleted' => AuditLogger::ON_FAILURE_ABORT,
        // ALL SIX CHILD OPERATIONS ARE ABORT, on the same test with the same answer: each is
        // written inside its repository's transaction, so the change can still be rolled back when
        // the row cannot be written. `bot.domain.created` is the one where a LOG policy would be a
        // real defect rather than an inconsistency — it would permit a GRANT to a page on the
        // public internet to exist with no record of who made it, which is the whole of finding L2.
        'bot.domain.created' => AuditLogger::ON_FAILURE_ABORT,
        'bot.domain.status_changed' => AuditLogger::ON_FAILURE_ABORT,
        'bot.domain.deleted' => AuditLogger::ON_FAILURE_ABORT,
        'bot.starter_question.created' => AuditLogger::ON_FAILURE_ABORT,
        'bot.starter_question.updated' => AuditLogger::ON_FAILURE_ABORT,
        'bot.starter_question.deleted' => AuditLogger::ON_FAILURE_ABORT,
        // ELEVEN OF THE TWELVE SOURCE OPERATIONS ARE ABORT, on the real test — "can this still be
        // rolled back" — rather than on whether the event is interesting. Each is written inside
        // the transaction that performs the change. `source.version.activated` is the one where a
        // LOG policy would be a defect rather than an inconsistency: the pointer switch is the
        // single act that decides what every subsequent query sees, and an activation with no audit
        // row would defeat the main argument for activation being Laravel's at all.
        'source.created' => AuditLogger::ON_FAILURE_ABORT,
        'source.updated' => AuditLogger::ON_FAILURE_ABORT,
        'source.deleted' => AuditLogger::ON_FAILURE_ABORT,
        'source.disabled' => AuditLogger::ON_FAILURE_ABORT,
        'source.enabled' => AuditLogger::ON_FAILURE_ABORT,
        'source.reprocess.requested' => AuditLogger::ON_FAILURE_ABORT,
        'source.upload.accepted' => AuditLogger::ON_FAILURE_ABORT,
        // THE TWELFTH, AND IT IS THE MIRROR IMAGE. There is no state change to undo: the refusal is
        // already decided, no row was written, and aborting would turn a rejected file into a 500 —
        // a lie to the caller, and still no audit row. Same shape as
        // `provider.connection.credential_rotation_failed`.
        'source.upload.rejected' => AuditLogger::ON_FAILURE_LOG,
        'source.version.activated' => AuditLogger::ON_FAILURE_ABORT,
        'source.version.retired' => AuditLogger::ON_FAILURE_ABORT,
        'bot.source_assignment.created' => AuditLogger::ON_FAILURE_ABORT,
        'bot.source_assignment.deleted' => AuditLogger::ON_FAILURE_ABORT,
    ];

    $actualPolicy = array_map(
        static fn (array $spec): string => $spec['on_failure'],
        AuditLogger::OPERATIONS,
    );

    // Keyed comparison, so the failure diff names the operation whose policy moved rather than
    // reporting that two counts differ.
    expect($actualPolicy)->toEqual($expectedPolicy);

    // And the totality both ways: an operation added to the constant without a line above fails here,
    // rather than being silently unpinned. This is the assertion that makes the map closed.
    expect(array_keys($expectedPolicy))->toEqualCanonicalizing(array_keys(AuditLogger::OPERATIONS));

    // Through a closure taking plain `string`/`array`, deliberately: called on the constant directly,
    // PHPStan narrows both sides to their literal values and reports the comparison as always true —
    // which is exactly the shape of assertion that stops being a check the moment the map grows a
    // third value. The runtime check is the point.
    $isOneOf = static fn (string $value, array $allowed): bool => in_array($value, $allowed, true);

    foreach (AuditLogger::OPERATIONS as $operation => $spec) {
        expect($isOneOf($spec['outcome'], [AuditLogger::OUTCOME_SUCCESS, AuditLogger::OUTCOME_FAILURE]))
            ->toBeTrue("{$operation} has an unknown outcome")
            ->and($isOneOf($spec['on_failure'], [AuditLogger::ON_FAILURE_ABORT, AuditLogger::ON_FAILURE_LOG]))
            ->toBeTrue("{$operation} has an unknown write-failure policy");

        foreach ($spec['details'] as $field => $rule) {
            expect($isOneOf($rule, [AuditLogger::ECHOED, AuditLogger::FINGERPRINTED]))
                ->toBeTrue("{$operation}.{$field} has an unknown handling rule");

            // A FINGERPRINTED field lands under `<field>_fingerprint`. If some other field is already
            // called that, one silently overwrites the other.
            if ($rule === AuditLogger::FINGERPRINTED) {
                expect($spec['details'])->not->toHaveKey($field.'_fingerprint');
            }

            // THE SAME HAZARD ON THE OTHER SYNTHETIC SUFFIX. An ECHOED field whose value trips the
            // shape backstop is degraded to `<field>_fingerprint` PLUS `<field>_redacted`, so an
            // allow-list that already declares a key by either of those names would have two
            // different values landing in one column with nothing to tell them apart.
            expect($spec['details'])->not->toHaveKey($field.'_redacted');
        }
    }
});

/**
 * Whether a `details` key NAMES A BEARER CAPABILITY, and must therefore be FINGERPRINTED rather
 * than ECHOED.
 *
 * ── WHY THIS IS NOT `str_contains($field, 'token')` ANY MORE ───────────────────────────────────
 *
 * It was, and that substring rule cost the audit trail two real fields. `provider.model.*` records
 * every attribute of a catalog row except `context_window` and `max_output_tokens`, and the second
 * of those is a FALSE POSITIVE under the substring rule — an integer limit copied off a vendor's
 * documentation page, which authorizes nothing and identifies nobody. Both were dropped rather
 * than weaken a standing credential guard to fit a naming coincidence, which was the right call at
 * the moment it was made and left a gap: nothing recorded who changed a model's limits.
 *
 * The repair is to make the rule SAY WHAT IT MEANS instead of allow-listing one string past it,
 * and English already draws the distinction the substring rule could not see:
 *
 *   SINGULAR `token` is a bearer capability. One capability is one token, so a credential field is
 *   never plural — `token`, `api_token`, `access_token`, `refresh_token`, `id_token`, `_token`,
 *   `token_hash`, `token_secret`, and the underscore-less spellings `apitoken` and `tokenhash`.
 *
 *   PLURAL `tokens` is a COUNT OF TEXT UNITS — `max_output_tokens`, `total_tokens`, `tokens_used`
 *   — but only when the name says it is a quantity. `access_tokens` is a bag of capabilities and
 *   is still refused, because no segment of it states a magnitude.
 *
 * So the rule is: any segment that contains `token` and is not exactly `tokens` is a capability;
 * a segment that IS exactly `tokens` is admitted only alongside a quantity word. That is STRICTLY
 * STRONGER than the substring rule everywhere except the one cell it was widened for — the
 * substring rule could not catch `apitoken` at all differently from `max_output_tokens`, because
 * it could not tell them apart, and this one refuses the first and admits the second.
 *
 * It fails CLOSED on anything it has no reading for: a bare `tokens`, or `token_count`, is refused.
 */
function namesABearerCapability(string $field): bool
{
    // Words that make a plural `tokens` a MEASUREMENT rather than a bag of credentials. A closed
    // vocabulary of magnitudes and never a list of field names — allow-listing `max_output_tokens`
    // itself is exactly the shortcut this function exists instead of.
    $quantities = ['max', 'min', 'total', 'count', 'used', 'limit', 'remaining', 'per', 'window',
        'budget', 'size', 'length'];

    $segments = explode('_', mb_strtolower($field));

    foreach ($segments as $segment) {
        if ($segment !== 'tokens' && str_contains($segment, 'token')) {
            return true;
        }
    }

    if (! in_array('tokens', $segments, true)) {
        return false;
    }

    return array_intersect($segments, $quantities) === [];
}

it('recognises a bearer-capability field name, and does not mistake a token COUNT for one', function (): void {
    // THE GUARD'S OWN TEST. A narrowed rule that nothing exercises is a rule nobody can trust was
    // narrowed correctly, and the whole reason `context_window` and `max_output_tokens` were left
    // out of the audit map for a while is that widening a credential check is not a thing to do on
    // an argument alone. Every name below that USED to be caught by `str_contains($field, 'token')`
    // is still caught here, except the deliberate plural-with-a-magnitude cell.
    $capabilities = ['token', 'api_token', 'access_token', 'refresh_token', 'id_token', '_token',
        'token_hash', 'token_fingerprint', 'token_secret', 'session_token', 'bearer_token',
        // Underscore-less spellings the segment rule must still reach, because a substring rule
        // caught them and a naive `in_array('token', $segments)` would not.
        'apitoken', 'accesstoken', 'tokenhash',
        // PLURAL WITH NO MAGNITUDE — a bag of capabilities, not a count. This is the case that
        // stops "plural means quantity" from being a hole.
        'tokens', 'access_tokens', 'refresh_tokens',
        // Singular beats the quantity vocabulary: `count` does not rescue `token`.
        'token_count'];

    $measurements = ['max_output_tokens', 'total_tokens', 'tokens_used', 'prompt_tokens_count',
        'tokens_per_minute', 'context_window', 'display_name', 'email', 'role', 'capabilities'];

    foreach ($capabilities as $name) {
        expect(namesABearerCapability($name))->toBeTrue("'{$name}' is a bearer capability and must be refused as ECHOED");
    }

    foreach ($measurements as $name) {
        expect(namesABearerCapability($name))->toBeFalse("'{$name}' is a measurement, not a credential, and must be allowed as ECHOED");
    }
});

it('keeps every secret-shaped detail key out of every allow-list', function (): void {
    // A standing guard rather than a one-off: the day somebody adds `api_key` to an operation's
    // allow-list as ECHOED, this fails. Names only — the rule is about the MAP, not about a value.
    $forbidden = ['password', 'api_key', 'apikey', 'secret', 'credential', 'provider_credential',
        'authorization', 'signature', 'private_key', 'access_key', 'session', 'cookie'];

    foreach (AuditLogger::OPERATIONS as $operation => $spec) {
        foreach (array_keys($spec['details']) as $field) {
            expect(in_array($field, $forbidden, true))->toBeFalse(
                "operation '{$operation}' allow-lists '{$field}', which is a credential name"
            );

            if ($spec['details'][$field] === AuditLogger::ECHOED) {
                expect(namesABearerCapability($field))->toBeFalse(
                    "operation '{$operation}' would ECHO '{$field}': a token is a bearer capability "
                    .'and must be FINGERPRINTED'
                );
            }
        }
    }
});
