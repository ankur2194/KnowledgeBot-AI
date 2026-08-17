<?php

declare(strict_types=1);

use App\Logging\KbJsonFormatter;
use App\Support\Observability\LogContext;
use Monolog\Level;
use Monolog\LogRecord;

/*
|--------------------------------------------------------------------------
| The log line shape (finding #56)
|--------------------------------------------------------------------------
|
| A Unit test, with no container and no facades, because App\Logging\KbJsonFormatter takes its two
| process-wide fields as constructor arguments precisely so it can be one. A formatter that had to
| boot the framework to be exercised is a formatter nobody exercises.
|
| THE REDACTION ASSERTIONS ASSERT ABSENCE OF THE SECRET, NEVER PRESENCE OF THE MASK. Those are
| different claims and only one of them is worth anything: the two bugs found in the Python
| redactor earlier — `Bearer <token>` where the word `Bearer` was replaced and the token survived,
| and `KB1 k1:<sig>` where the pattern stopped at the colon — BOTH produced output containing
| `[REDACTED]`. A test looking for the marker passes on every one of them.
|
| WHY EVERY CREDENTIAL FIXTURE BELOW IS A REPEATED MARKER AND NOT A RANDOM-LOOKING STRING (#137)
| ----------------------------------------------------------------------------------------------
| A `secret-scan` job used to run gitleaks over BOTH the worktree and every ref with no waiver of any
| kind. TWO THINGS HAVE CHANGED AND BOTH WEAKEN THIS PARAGRAPH: the job was deleted with `.github/`
| on 2026-08-17, so nothing scans automatically; and a repo-root `.gitleaks.toml` now exists, whose
| one allowlist covers password-shaped fixtures under test paths (this file's fixtures are not that
| shape, so they are still caught by a manual run). Three fixtures in this file were the only
| findings in the whole repository that survive `actions/checkout`, so they would have turned the
| first PR after this tree is committed red, and a waiver file would have hollowed out the gate for
| the next real fixture. They were lowered instead.
|
| gitleaks' `generic-api-key` rule needs THREE things at once on one line: a credential-shaped
| KEYWORD (`api_key`, `token`, `secret`, `credential`, …), an assignment operator, and a captured
| value whose Shannon entropy is at least 3.5. Measured with `zricethezav/gitleaks:v8.30.1`, the
| three that fired, and what each became — DESCRIBED AND NOT REPRODUCED, because writing the old
| value into this comment fires the rule again from the comment, which is how the first attempt at
| this note failed:
|
|   1. the `api_key` dataset row — `nvapi-` plus 22 pseudo-random characters — entropy 4.593.
|      Now `nvapi-MUSTNOTAPPEARMUSTNOTAPPEAR`: it keeps the `nvapi-` PREFIX and stays far over
|      redact()'s 12-character floor, while the repeated marker puts the entropy under the bar.
|
|   2. the auth-scheme test's local — a real JWT bound to `$token` — entropy 4.451. Now the same
|      JWT bound to `$jwt`. A JWT's entropy lives in its base64 header and CANNOT be lowered
|      without destroying the shape the bug was found in, so this one is disarmed at the
|      IDENTIFIER instead. That is sound precisely because the identifier is not a byte the
|      redactor reads: redact() keys on `Authorization` and `Bearer` in the MESSAGE.
|
|   3. the exception test's local — `sk-proj-` plus 21 characters bound to `$secret` — entropy
|      4.142. Now `$argument`, holding unshaped prose. Read that test: its old fixture also
|      detected nothing, so it changed for two independent reasons.
|
| The two knobs are therefore the VALUE'S ENTROPY and the PHP IDENTIFIER, and NEITHER IS A BYTE
| `KbJsonFormatter::redact()` READS. The redactor keys on a vendor PREFIX (`sk-`, `sk-ant-`,
| `nvapi-`, `ghp_`, `AKIA`), on a LENGTH FLOOR after it, and on words that appear in the MESSAGE
| (`Authorization`, `Bearer`, `KB1`, `?`). A repeated marker keeps every one of those.
|
| DO NOT "make these look more like real keys", and do not add a `.gitleaksignore`. Both are the
| same refused trade. If a fixture must be random-looking, prove first that the redactor still
| fires on it by breaking `redact()` and watching this file go red.
*/

/**
 * A record with a fixed, deliberately non-UTC instant, so the UTC conversion is observable.
 *
 * @param  array<string, mixed>  $context
 */
function kbRecord(
    Level $level = Level::Info,
    string $message = 'hello',
    array $context = [],
): LogRecord {
    return new LogRecord(
        datetime: new \DateTimeImmutable('2026-08-10T14:34:56.789000+02:00'),
        channel: 'stdout',
        level: $level,
        message: $message,
        context: $context,
    );
}

function kbFormatter(): KbJsonFormatter
{
    return new KbJsonFormatter(service: 'core-api', env: 'testing');
}

/**
 * @param  array<string, mixed>  $context
 * @return array<string, mixed>
 */
function kbFormatted(
    Level $level = Level::Info,
    string $message = 'hello',
    array $context = [],
): array {
    $line = kbFormatter()->format(kbRecord($level, $message, $context));

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

beforeEach(function (): void {
    // Static context: a test that binds one and does not clear it hands its request id to the
    // next test in the file, which is the same class of bug the middleware's terminate() prevents.
    LogContext::forget();
});

afterEach(function (): void {
    LogContext::forget();
});

it('emits one JSON object terminated by exactly one newline', function (): void {
    $line = kbFormatter()->format(kbRecord());

    expect(substr_count($line, "\n"))->toBe(1)
        ->and(str_ends_with($line, "\n"))->toBeTrue()
        ->and(json_decode(trim($line), true))->toBeArray();
});

it('carries all eight required fields on every line, at every level', function (): void {
    foreach (Level::cases() as $level) {
        $payload = kbFormatted($level);

        foreach (KbJsonFormatter::REQUIRED_FIELDS as $field) {
            expect(array_key_exists($field, $payload))->toBeTrue(
                "{$field} is missing from a {$level->getName()} line. The eight required fields "
                .'are the log contract (kb-observability-conventions), not a suggestion.',
            );
        }
    }

    expect(KbJsonFormatter::REQUIRED_FIELDS)->toHaveCount(8);
});

it('renders severity as a STRING, which is the whole of finding #56', function (): void {
    foreach (Level::cases() as $level) {
        expect(kbFormatted($level)['severity'])->toBeString();
    }
});

it('renders a severity the Collector severity_parser actually maps', function (): void {
    /*
     * MEASURED, NOT ASSUMED. otel/opentelemetry-collector-contrib:0.158.0 was run against the
     * `file_log` operator block from infrastructure/docker/otel/collector.yaml with one probe line per
     * candidate string. Its severity_parser accepts, case-insensitively, exactly
     * trace|debug|info|warn|error|fatal with an optional 2/3/4 suffix, plus the alias `warning`.
     * Everything else — including Monolog's NOTICE, CRITICAL, ALERT and EMERGENCY — lands on
     * SeverityNumber Unspecified(0), which is the SAME broken outcome as the integer `level` this
     * formatter replaced: no `level` stream label in Loki, and every level-faceted panel reads zero.
     *
     * This pattern is that measurement written down. Widening it means re-running the probe.
     */
    $accepted = '/^(?:trace|debug|info|warn|warning|error|fatal)[234]?$/i';

    foreach (Level::cases() as $level) {
        $severity = kbFormatted($level)['severity'];

        expect($severity)->toBeString()->toMatch($accepted, sprintf(
            'severity "%s" (Monolog %s) is outside the set the Collector maps; it would reach Loki '
            .'with SeverityNumber Unspecified(0) and no level label.',
            is_string($severity) ? $severity : gettype($severity),
            $level->getName(),
        ));
    }
});

it('maps every Monolog level through the OpenTelemetry syslog severity table', function (): void {
    // Monolog's levels ARE the RFC 5424 severities, so this table is the specified mapping rather
    // than an invented one — and it is the only one preserving ORDER across the four levels the
    // parser has no plain name for. The numbers are the SeverityNumber each string produced in the
    // probe above.
    $expected = [
        'DEBUG' => 'DEBUG',      // 5
        'INFO' => 'INFO',        // 9
        'NOTICE' => 'INFO2',     // 10
        'WARNING' => 'WARNING',  // 13
        'ERROR' => 'ERROR',      // 17
        'CRITICAL' => 'ERROR2',  // 18
        'ALERT' => 'ERROR3',     // 19
        'EMERGENCY' => 'FATAL',  // 21
    ];

    foreach (Level::cases() as $level) {
        expect(kbFormatted($level)['severity'])->toBe($expected[$level->getName()]);
    }

    expect(KbJsonFormatter::SEVERITY)->toBe($expected);
});

it('emits none of the stock JsonFormatter keys that the contract does not name', function (): void {
    $payload = kbFormatted(Level::Error, 'boom', ['error_class' => 'internal_dependency']);

    // `level` is the one that matters: the Collector's compatibility arm is guarded with
    // `type(attributes.level) == "string"`, so re-introducing it as Monolog's integer puts the
    // record straight back into the unparsed branch.
    foreach (['level', 'level_name', 'context', 'extra', 'datetime', 'channel'] as $key) {
        expect(array_key_exists($key, $payload))->toBeFalse("stock JsonFormatter key [{$key}] is back");
    }
});

it('flattens context instead of nesting it under a context key', function (): void {
    $payload = kbFormatted(Level::Info, 'ok', ['org_id' => '01J0ORG', 'duration_ms' => 12]);

    expect($payload['org_id'])->toBe('01J0ORG')
        ->and($payload['duration_ms'])->toBe(12);
});

it('renders the timestamp as RFC3339 UTC with millisecond precision', function (): void {
    // The record was built at 14:34:56.789 +02:00.
    expect(kbFormatted()['timestamp'])->toBe('2026-08-10T12:34:56.789Z');
});

it('renders trace_id and span_id as null when no span is active, without crashing', function (): void {
    $payload = kbFormatted();

    expect($payload)->toHaveKeys(['trace_id', 'span_id'])
        ->and($payload['trace_id'])->toBeNull()
        ->and($payload['span_id'])->toBeNull();

    // The all-zero sentinel must never be taken at face value: rendered literally it puts a dead
    // link in Grafana on every line emitted outside a span. This is the PHP half of the rule
    // `_trace_ids` states in services/ai-service/app/observability/logging.py.
    expect(json_encode($payload))->not->toContain(str_repeat('0', 32));
});

it('takes request_id and operation from the request-scoped context', function (): void {
    LogContext::bind('01JREQUESTID0000000000', static fn (): string => 'admin.provider-connections.store');

    $payload = kbFormatted();

    expect($payload['request_id'])->toBe('01JREQUESTID0000000000')
        ->and($payload['operation'])->toBe('admin.provider-connections.store');
});

it('survives an operation resolver that throws', function (): void {
    LogContext::bind('01JREQUESTID0000000000', static function (): ?string {
        throw new \RuntimeException('the router blew up');
    });

    $payload = kbFormatted();

    // The line that has to survive is the one describing the failure.
    expect($payload['operation'])->toBeNull()
        ->and($payload['request_id'])->toBe('01JREQUESTID0000000000');
});

it('never lets a context key overwrite a contract field, and says which one it dropped', function (): void {
    LogContext::bind('01JREALREQUESTID000000');

    $payload = kbFormatted(Level::Info, 'ok', [
        'service' => 'ai-api',
        'severity' => 'DEBUG',
        'request_id' => 'attacker-chosen',
        'timestamp' => '1970-01-01T00:00:00.000Z',
    ]);

    // Silently overwriting `service` from user-supplied context is a log-forging primitive: the
    // fields an incident is reconstructed from would be settable by anyone who can influence a
    // context array, and a forged field is worse than an absent one.
    expect($payload['service'])->toBe('core-api')
        ->and($payload['severity'])->toBe('INFO')
        ->and($payload['request_id'])->toBe('01JREALREQUESTID000000')
        ->and($payload['timestamp'])->toBe('2026-08-10T12:34:56.789Z')
        ->and($payload['dropped_fields'])->toBe(['request_id', 'service', 'severity', 'timestamp']);
});

it('drops a context key outside the allow-list, keeping only its name', function (): void {
    $payload = kbFormatted(Level::Info, 'ok', [
        'org_id' => '01J0ORG',
        'user_id' => '01J0USER',
        'query' => 'what is our refund policy',
    ]);

    // `org_id` is blessed for logs; `user_id` and `query` are not, and admitting either is an edit
    // to references/logs-health-audit.md rather than to a code constant.
    expect($payload['org_id'])->toBe('01J0ORG')
        ->and($payload['dropped_fields'])->toBe(['query', 'user_id'])
        ->and(json_encode($payload))->not->toContain('01J0USER')
        ->and(json_encode($payload))->not->toContain('refund policy');
});

it('never writes a secret that arrives in the context', function (string $key, string $value, string $secret): void {
    $line = kbFormatter()->format(kbRecord(Level::Error, 'provider call failed', [$key => $value]));

    // ABSENCE of the secret, not presence of a mask. `[REDACTED]` appeared in the output of both
    // Python bugs while the credential sat beside it.
    expect($line)->not->toContain($secret);
})->with([
    // Outside the allow-list: the field is dropped whole, value included.
    //
    // EVERY VALUE HERE IS DELIBERATELY UNSHAPED, AND THAT IS WHAT MAKES THE ROWS MEASURE THE
    // EXCLUSION (finding G11, fixed 2026-08-12). The first three used to carry real credential
    // shapes — `sk-ant-…`, `Bearer eyJ…`, `nvapi-…` — and all three stayed GREEN under the
    // mutation that opens `partition()` so every context key ships, because `normalize()` hands
    // the bare VALUE to redact() and the vendor-key and bearer rules caught them on the way out.
    // They measured the BACKSTOP while reading as if they measured the exclusion, and would have
    // kept passing if `ALLOWED_EXTRA_FIELDS` were deleted outright.
    //
    // The rule for adding a row here: the KEY is the excluded field under test, and the VALUE
    // must be something redact() cannot recognise — no `sk-`/`nvapi-`/`ghp_`/`AKIA` prefix, no
    // `bearer `/`basic ` scheme, no `KB1 `, no `http…?query`, and none of CREDENTIAL_KEY_VALUE's
    // keywords followed by `:` or `=`. Nothing but the exclusion may stand between the value and
    // the log store, or the row proves the wrong defence. `MUSTNOTAPPEAR` is the needle in every
    // one, so a failure names itself.
    //
    // The backstop is not left unmeasured: the three rows in the second group below are exactly
    // that test, with the credential shapes, on fields the allow-list PERMITS.
    ['provider_credential', 'MUSTNOTAPPEAR-pasted-into-the-admin-form', 'MUSTNOTAPPEAR'],
    ['authorization', 'MUSTNOTAPPEAR-scheme-and-value-together', 'MUSTNOTAPPEAR'],
    ['api_key', 'MUSTNOTAPPEAR-copied-from-the-vendor-console', 'MUSTNOTAPPEAR'],
    ['user_id', 'MUSTNOTAPPEAR-01J0USERWHOASKED', 'MUSTNOTAPPEAR'],
    // INSIDE the allow-list, so the field ships and only redact() stands between the secret and
    // the log store. This is the case the allow-list cannot help with, and the needle is the
    // CREDENTIAL rather than the sentence around it.
    ['reason', 'refused: api_key=sk-proj-AAAABBBBCCCCDDDDEEEE', 'sk-proj-AAAABBBBCCCCDDDDEEEE'],
    ['reason', 'signature mismatch for KB1 k1:9f8e7d6c5b4a3f2e1d0c', '9f8e7d6c5b4a3f2e1d0c'],
    ['model', 'gpt-5 via Authorization: Bearer abcdefghijklmnop', 'abcdefghijklmnop'],
]);

it('redacts the token after an auth scheme, not the word naming the scheme', function (): void {
    // THE EXACT BUG FOUND IN THE PYTHON REDACTOR. Without the optional scheme group the key/value
    // rule replaces `Bearer` — the next non-space run after the colon — and leaves the credential
    // standing, which is worse than not matching at all because the output LOOKS redacted.
    // `$jwt` AND NOT `$token`, and the third segment is a marker rather than a random run — see the
    // gitleaks note in the file header. Neither byte is one the redactor reads: CREDENTIAL_KEY_VALUE
    // keys on the word `Authorization` in the MESSAGE plus the optional `Bearer ` scheme, and BEARER
    // keys on `bearer\s+[A-Za-z0-9._\-+/=]{8,}`. The base64url header segments are kept because they
    // are what makes the value a JWT, which is the shape this bug was found in.
    $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.MUSTNOTAPPEARSIGNATURE';

    $line = kbFormatter()->format(kbRecord(
        Level::Error,
        "upstream rejected Authorization: Bearer {$jwt}",
    ));

    // Two needles: the whole token, and the SIGNATURE segment alone — a match that stopped at a `.`
    // would leave the signature standing while the line still read as redacted.
    expect($line)->not->toContain($jwt)
        ->and($line)->not->toContain('MUSTNOTAPPEARSIGNATURE');
});

it('redacts the whole KB1 signature, colon included', function (): void {
    // THE SECOND BUG. `KB1 <key_id>:<signature>` — without the colon inside the character class
    // the pattern matches `KB1 k1`, which is below the length floor, and the signature survives.
    $signature = '4f3c2b1a0e9d8c7b6a5f4e3d2c1b0a99';

    $line = kbFormatter()->format(kbRecord(Level::Error, "bad signature KB1 k1:{$signature}"));

    expect($line)->not->toContain($signature);
});

it('redacts the query string of an absolute URL', function (): void {
    $line = kbFormatter()->format(kbRecord(
        Level::Warning,
        'fetch failed for https://example.test/search?q=how+do+I+cancel&token=abcd1234',
    ));

    expect($line)->not->toContain('how+do+I+cancel')
        ->and($line)->not->toContain('abcd1234')
        ->and($line)->toContain('https://example.test/search');
});

it('states what its redaction cannot do', function (): void {
    // A redactor that gives false confidence is worse than none. The limits are a constant so that
    // deleting the caveat is a diff rather than a forgotten sentence.
    expect(KbJsonFormatter::REDACTION_LIMITS)->toContain('does NOT catch tenant content');
});

/*
|--------------------------------------------------------------------------
| redactValue() — the value-shaped subset, and the false positive it removes
|--------------------------------------------------------------------------
|
| App\Services\Audit\AuditLogger::sanitize() uses a redaction pass as a SHAPE BACKSTOP over
| `audit_logs.details`: an ECHOED value that redaction alters is not echoed. Its input is not a
| message — it is one value whose FIELD NAME is the caller's map key and is never in the string —
| so redact()'s CREDENTIAL_KEY_VALUE rule, whose whole discriminating power is a key name sitting
| next to a value, fires on ordinary registrable email addresses. `=` is legal `atext` in a
| dot-atom.
|
| THE ROWS BELOW ARE MEASURED, NOT REASONED. Every address marked valid was run through this
| repo's egulias/email-validator under the exact pair `email:rfc,strict` builds (RFCValidation +
| NoRFCWarningsValidation) in the project image, and each rule was applied individually to see
| which one fires. `docker run … php` over app/Logging/KbJsonFormatter.php's private constants by
| reflection is the measurement; re-run it before changing any expectation here.
|
| The assertions come in the two directions that matter, and both are needed: the false positive
| is GONE from redactValue(), and it is still PRESENT in redact(), because it is correct there.
| Only asserting the first would go green on a change that deleted the rule outright.
*/

/**
 * Addresses a person can really register and really log in with, which redact() alters.
 *
 * @return list<string>
 */
function kbKeyValueAddresses(): array
{
    return [
        'token=abc@example.com',
        'my.token=x@example.com',
        'secret=1@example.com',
        // Two of this file's own, on the same measurement: the rule's keyword list is 15 names
        // long, so the false-positive surface is much wider than the three addresses the audit of
        // AuditLogger reported.
        'password=hunter2@example.com',
        'x-api-key=q@example.com',
    ];
}

it('leaves a registrable address containing a key=value pair intact', function (string $address): void {
    // The whole point of the narrowing. An audit row for auth.login.failed carries
    // organization_id = NULL and actor_id = NULL by design, so before this the caller only had to
    // choose its own username to produce a row that identified nothing.
    expect(KbJsonFormatter::redactValue($address))->toBe($address);
})->with(kbKeyValueAddresses());

it('still applies the key=value rule to a MESSAGE, which is where it is correct', function (string $address): void {
    // `Log::info("rejected password=hunter2")` is exactly what CREDENTIAL_KEY_VALUE is for. This
    // assertion is what stops the narrowing being applied to redact() by a later "unification".
    expect(KbJsonFormatter::redact($address))->not->toBe($address);
})->with(kbKeyValueAddresses());

it('still catches a genuine credential in a value, one retained rule at a time', function (string $value, string $needle): void {
    // ABSENCE of the secret, never presence of the mask — the file header explains why. Every
    // fixture keeps the shape the rule reads (prefix, scheme word, length floor) while the repeated
    // marker holds Shannon entropy under gitleaks' 3.5 bar; see the header note before editing one.
    expect(KbJsonFormatter::redactValue($value))->not->toContain($needle);
})->with([
    // VENDOR_KEYS — the `sk-ant-` arm, the one an Anthropic credential actually has.
    ['sk-ant-MUSTNOTAPPEARMUSTNOTAPPEAR', 'MUSTNOTAPPEAR'],
    // VENDOR_KEYS — a second arm, so deleting one prefix from the alternation is visible.
    ['nvapi-MUSTNOTAPPEARMUSTNOTAPPEAR', 'MUSTNOTAPPEAR'],
    // BEARER — with NO key name in front of it, which is what makes this independent of the rule
    // that was removed. A `key: scheme value` message shape is covered in the redact() specs above.
    ['Bearer MUSTNOTAPPEARMUSTNOTAPPEAR', 'MUSTNOTAPPEAR'],
    ['Basic MUSTNOTAPPEARMUSTNOTAPPEAR', 'MUSTNOTAPPEAR'],
    // KB1_SIGNATURE — our own scheme, colon included. `KB1 k1` alone is under the length floor, so
    // a pattern that stopped at the colon would leave the signature standing.
    ['KB1 k1:MUSTNOTAPPEARMUSTNOTAPPEAR', 'MUSTNOTAPPEAR'],
    // URL_QUERY — and this is the shape this application's own reset link has:
    // FrontendUrl::for('/reset-password', ['token' => …, 'email' => …]). The capability is in the
    // query string, it has no shape of its own, and with CREDENTIAL_KEY_VALUE gone this rule is the
    // only thing left that catches it.
    ['https://app.example.test/reset-password?token=MUSTNOTAPPEARMUSTNOTAPPEAR', 'MUSTNOTAPPEAR'],
]);

it('keeps the part of a URL that says which endpoint was involved', function (): void {
    // The cost of keeping URL_QUERY is that a legitimate query string goes too. It is bounded: the
    // scheme, host and path survive, so an audit reader still knows what was called.
    expect(KbJsonFormatter::redactValue('https://app.example.test/reset-password?token=MUSTNOTAPPEARMUSTNOTAPPEAR'))
        ->toContain('https://app.example.test/reset-password')
        ->and(KbJsonFormatter::redactValue('https://example.test/docs?page=2'))
        ->toBe('https://example.test/docs?[REDACTED]');
});

it('fires on nothing the audit allow-list actually admits', function (string $value): void {
    // The complete ECHOED vocabulary of AuditLogger::OPERATIONS — email, mechanism, reason, role,
    // from_role, to_role, expires_at — in the value shapes those fields really take. A rule added
    // to redactValue() that fires on one of these turns every audited request into a WARNING plus a
    // fingerprinted row, which is the failure this whole change exists to remove.
    expect(KbJsonFormatter::redactValue($value))->toBe($value);
})->with([
    'bob@example.com',
    // `mechanism` is literally the string `token` for a personal access token. CREDENTIAL_KEY_VALUE
    // needs a `:` or `=` after the keyword, so even the message rule spares it — but a sloppier
    // keyword-only rule would not, and this row is where that would be caught.
    'token',
    'session',
    'admin',
    'owner',
    'member',
    // auth.login.failed.reason — the internal distinction the 422 response must never make.
    'unknown_email',
    'invalid_password',
    'unverified_email',
    // expires_at
    '2026-08-20T00:00:00+00:00',
]);

it('keeps a residual VENDOR_KEYS false positive, measured and deliberately not fixed', function (): void {
    // MEASURED: `sk-abcdefghijkl@example.com` is rfc,strict VALID and matches
    // `sk-[A-Za-z0-9_\-]{12,}` — so it is NOT a CREDENTIAL_KEY_VALUE false positive and the
    // narrowing does not rescue it. Pinned rather than fixed, because the only available fix is a
    // lookahead refusing a match followed by `@domain`, which would stop catching a credential in
    // URL userinfo position (`https://sk-ant-…@host/`) — a real leak shape traded away for a rare
    // false positive. AuditLogger now writes `<key>_fingerprint` for a value that fails the
    // backstop, so this costs correlatability of the plaintext and not the row.
    //
    // If this ever goes red, the fix was attempted: re-read the VENDOR_KEYS paragraph on
    // KbJsonFormatter::redactValue() before deleting the assertion.
    $address = 'sk-abcdefghijkl@example.com';

    expect(KbJsonFormatter::redactValue($address))->not->toBe($address)
        ->and(KbJsonFormatter::redactValue($address))->toContain('@example.com');
});

it('is a strict subset of the message redactor', function (): void {
    // The superset property, asserted behaviourally rather than trusted from the delegation in
    // redact(). Anything the value path catches, the message path must also catch — otherwise a
    // rule was added to redactValue() alone and a log MESSAGE now leaks a shape an audit row
    // refuses.
    $corpus = array_merge(kbKeyValueAddresses(), [
        'sk-ant-MUSTNOTAPPEARMUSTNOTAPPEAR',
        'Bearer MUSTNOTAPPEARMUSTNOTAPPEAR',
        'KB1 k1:MUSTNOTAPPEARMUSTNOTAPPEAR',
        'https://app.example.test/reset-password?token=MUSTNOTAPPEARMUSTNOTAPPEAR',
        'bob@example.com',
        'session',
        '',
    ]);

    foreach ($corpus as $value) {
        if (KbJsonFormatter::redactValue($value) !== $value) {
            expect(KbJsonFormatter::redact($value))->not->toBe($value, "redact() misses [{$value}]");
        }
    }

    // And the subset is PROPER: at least one input the message path alters and the value path does
    // not. Without this the assertion above is satisfied by two identical functions.
    expect(KbJsonFormatter::redactValue('token=abc@example.com'))->toBe('token=abc@example.com')
        ->and(KbJsonFormatter::redact('token=abc@example.com'))->not->toBe('token=abc@example.com');
});

it('states what its VALUE redaction cannot do, separately', function (): void {
    // Two functions with different coverage need two published strings: one constant covering both
    // is how a caller ends up confident about the coverage of the other one.
    expect(KbJsonFormatter::VALUE_REDACTION_LIMITS)->toContain('does NOT catch tenant content')
        ->and(KbJsonFormatter::VALUE_REDACTION_LIMITS)->toContain('key=value')
        ->and(KbJsonFormatter::REDACTION_LIMITS)->toContain('redactValue()')
        ->and(KbJsonFormatter::VALUE_REDACTION_LIMITS)->not->toBe(KbJsonFormatter::REDACTION_LIMITS);
});

it('returns an empty value untouched rather than a mask', function (): void {
    // The caller compares input to output. An empty string coming back as anything else would read
    // as "redaction fired" on a value nothing recognised.
    expect(KbJsonFormatter::redactValue(''))->toBe('');
});

it('renders an exception without PHP argument values', function (): void {
    // getTraceAsString() renders call ARGUMENTS — scalars truncated to 15 characters — so a user
    // question or a provider key passed to any function on the stack would land in the log store.
    // Frames are rebuilt from file/line/class/function and `args` is never read.
    //
    // THE NEEDLE IS UNSHAPED PROSE, AND IT HAS TO BE — THIS TEST USED TO DETECT NOTHING (#137).
    // It passed an `sk-proj-` credential 29 characters long and asserted all 29 were absent, which
    // was undetectable twice over. Appending `getTraceAsString()` to the rendered exception — the
    // exact regression the paragraph above warns about — left this test GREEN. Measured:
    //
    //   getTraceAsString() renders  {closure:…}('sk-proj-THISMUS...')   <- truncated to 15
    //
    // so a 29-character needle can never be found however badly the formatter behaves; and even
    // the surviving 15-character stub is eaten by redact()'s `sk-[A-Za-z0-9_\-]{12,}` rule — by
    // one character — so the backstop would have covered the regression and this test would still
    // not have measured the `args` refusal. A user question is the case REDACTION_LIMITS states
    // redact() CANNOT cover, which makes it the only needle that measures this defence rather than
    // the one behind it — and the marker sits inside the first 15 characters so truncation cannot
    // hide it.
    $argument = 'MUSTNOTAPPEAR: what is our refund policy?';

    $thrower = static function (string $userQuestion): never {
        throw new \RuntimeException('provider rejected the call');
    };

    try {
        $thrower($argument);
    } catch (\Throwable $e) {
        $line = kbFormatter()->format(kbRecord(Level::Error, 'call failed', ['exception' => $e]));
    }

    // The marker FIRST: it is the assertion that can actually fail, because it survives the
    // 15-character truncation. The whole-string assertion stays for a regression that renders
    // `args` without truncating, such as an implode() over the frame.
    expect($line)->not->toContain('MUSTNOTAPPEAR')
        ->and($line)->not->toContain($argument)
        ->and($line)->toContain('RuntimeException')
        ->and($line)->toContain('provider rejected the call');
});

it('refuses an unknown object by NAME and never calls __toString', function (): void {
    $leaky = new class
    {
        public function __toString(): string
        {
            return 'sk-ant-LEAKED-THROUGH-TOSTRING';
        }
    };

    // `model` is inside the allow-list, so the value reaches the normalizer — which is the point.
    // (string) on an arbitrary object is an open channel from any library into the log store.
    $line = kbFormatter()->format(kbRecord(Level::Info, 'ok', ['model' => $leaky]));

    expect($line)->not->toContain('LEAKED-THROUGH-TOSTRING')
        ->and($line)->toContain('class@anonymous');
});

it('never throws or loses a line on an unencodable value', function (): void {
    $line = kbFormatter()->format(kbRecord(Level::Info, 'ok', [
        'duration_ms' => NAN,
        'count' => INF,
    ]));

    /** @var array<string, mixed> $payload */
    $payload = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);

    foreach (KbJsonFormatter::REQUIRED_FIELDS as $field) {
        expect(array_key_exists($field, $payload))->toBeTrue($field);
    }
});

it('resolves env from OTEL_RESOURCE_ATTRIBUTES first and APP_ENV second', function (): void {
    // `deployment.environment.name` is what the Collector projects onto the `env` METRIC label, so
    // reading it first is what keeps the log field and the metric label describing one fleet.
    expect(KbJsonFormatter::environmentFrom('service.version=abc,deployment.environment.name=staging', 'production'))
        ->toBe('staging')
        ->and(KbJsonFormatter::environmentFrom('', 'production'))->toBe('production')
        ->and(KbJsonFormatter::environmentFrom(null, null))->toBe('local')
        ->and(KbJsonFormatter::environmentFrom('deployment.environment.name=', 'testing'))->toBe('testing');
});
