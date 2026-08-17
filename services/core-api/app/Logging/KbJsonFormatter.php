<?php

declare(strict_types=1);

namespace App\Logging;

use App\Support\Observability\LogContext;
use BackedEnum;
use DateTimeInterface;
use DateTimeZone;
use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;
use OpenTelemetry\API\Trace\Span;
use Throwable;
use UnitEnum;

/**
 * One JSON object per line, RFC3339 UTC, carrying the closed field set the log contract names.
 *
 * WHAT WAS HERE BEFORE: Monolog's stock `JsonFormatter`
 * =====================================================
 * `config/logging.php` pointed `stdout` and `stderr` at `Monolog\Formatter\JsonFormatter`, which
 * emits `{message, context, level, level_name, channel, datetime, extra}`. Measured against
 * `kb-observability-conventions/references/logs-health-audit.md`, which requires `timestamp`,
 * `severity`, `service`, `env`, `trace_id`, `span_id`, `request_id` and `operation` on every line,
 * that is EIGHT missing fields and one actively harmful one:
 *
 *   * `level` is Monolog's INTEGER (200 for INFO, 400 for ERROR). The Collector's `file_log`
 *     severity parser is guarded with `type(attributes.level) == "string"`
 *     (`infrastructure/docker/otel/collector.yaml`) precisely because an integer errors the
 *     operator rather than mapping to anything — so every Laravel line reached Loki with the
 *     record still at `SEVERITY_NUMBER_UNSPECIFIED` and NO `level` stream label. Every
 *     "errors in the last hour" query and every level-faceted panel read zero while the lines
 *     themselves sat in the store. That collector comment names this file as the fix.
 *   * `context` was nested under a `context` key, which makes every Loki query a JSON path
 *     expression instead of `| json | org_id="…"`.
 *
 * THE SEVERITY STRINGS ARE NOT A GUESS — THEY WERE MEASURED
 * ========================================================
 * `otel/opentelemetry-collector-contrib:0.158.0` was run against the operator block from
 * `collector.yaml` with one probe line per Monolog level name. Its `severity_parser` accepts,
 * case-insensitively, EXACTLY `trace|debug|info|warn|error|fatal`, each with an optional `2`/`3`/
 * `4` suffix, plus the alias `warning`. Everything else lands on `SeverityNumber Unspecified(0)`,
 * which is the same broken outcome as the integer:
 *
 *     severity=INFO       -> Info(9)            severity=NOTICE    -> Unspecified(0)   ✗
 *     severity=WARNING    -> Warn(13)           severity=CRITICAL  -> Unspecified(0)   ✗
 *     severity=ERROR      -> Error(17)          severity=ALERT     -> Unspecified(0)   ✗
 *     severity=INFO2      -> Info2(10)          severity=EMERGENCY -> Unspecified(0)   ✗
 *     severity=ERROR2     -> Error2(18)
 *     severity=ERROR3     -> Error3(19)
 *     severity=FATAL      -> Fatal(21)
 *
 * So emitting `$record->level->getName()` verbatim would have fixed four of Monolog's eight levels
 * and left the four MOST serious ones silently unlabelled. :self::SEVERITY therefore maps through
 * the OpenTelemetry logs data model's syslog table — Monolog's levels ARE the RFC 5424 severities,
 * so that table is the specified answer rather than an invented one, and it is the only mapping
 * that preserves ORDERING: EMERGENCY(21) > ALERT(19) > CRITICAL(18) > ERROR(17) > WARNING(13) >
 * NOTICE(10) > INFO(9) > DEBUG(5).
 *
 * The alternative — a `mapping:` block on the `severity_parser` teaching it `notice`/`critical`/
 * `alert`/`emergency` — lives in `infrastructure/`, which this service does not own, and would
 * still leave every OTHER producer's four levels unmapped. Fixing it at the emitter fixes it once.
 *
 * WHY THE DEFENCE IS AN ALLOW-LIST AND NOT A SCRUBBER
 * ===================================================
 * `logs-health-audit.md` is explicit: the never-log list is enforced by EXCLUSION, because a
 * denylist only catches the cases somebody imagined. :self::ALLOWED_EXTRA_FIELDS is therefore
 * closed and is the SAME closed list as
 * `services/ai-service/app/observability/logging.py:ALLOWED_EXTRA_FIELDS` — two runtimes, one
 * vocabulary, pinned by `tests/Contract/LogFieldContractTest.php` so the two cannot drift. A
 * context key outside it is DROPPED and only its NAME is echoed back in `dropped_fields`, so an
 * author sees the field did not ship instead of believing it did.
 *
 * A CONTEXT KEY THAT COLLIDES WITH A CONTRACT FIELD LOSES — AND IS REPORTED.
 * `Log::info('hi', ['service' => 'some-other-service'])` does not overwrite `service`; it is dropped and
 * `dropped_fields: ["service"]` says so. Letting user-supplied context win would make every log
 * field forgeable by anyone who can influence a context array — the `severity`, `service` and
 * `trace_id` of a line are exactly what an incident is reconstructed from, and a forged one is
 * worse than an absent one.
 *
 * THERE ARE TWO REDACTION ENTRY POINTS AND THEY ARE NOT INTERCHANGEABLE. :self::redact() is for a
 * free-form MESSAGE, where a key name and its value sit side by side in prose. :self::redactValue()
 * is for ONE ALREADY-NAMED VALUE — an `audit_logs.details` entry, whose field name is the caller's
 * map key and never part of the string — and it therefore omits the `key=value` rule, which for a
 * value has no discriminating power beyond the key name the caller already holds. Each has its own
 * published limits constant; the argument for every rule in and out is on :self::redactValue().
 *
 * :self::redact() IS THE BACKSTOP, NEVER THE DEFENCE — see :self::REDACTION_LIMITS. It is a
 * port of the Python function, INCLUDING the two bugs that were found there by proving the
 * redaction rather than reading it: the optional auth-scheme group (without it,
 * `Authorization: Bearer <tok>` replaced the word `Bearer` and left the token standing, which is
 * worse than no match because the output LOOKS redacted) and the `:` inside the KB1 character
 * class (without it, `KB1 k1:<sig>` matched only `KB1 k1` and the signature survived).
 */
final class KbJsonFormatter implements FormatterInterface
{
    /**
     * The eight the contract requires on EVERY line. Named separately from the full owned set so a
     * test can assert the requirement rather than the implementation.
     */
    public const REQUIRED_FIELDS = [
        'timestamp',
        'severity',
        'service',
        'env',
        'trace_id',
        'span_id',
        'request_id',
        'operation',
    ];

    /**
     * Emitted by this formatter and never settable from a call site. The first eight are
     * :self::REQUIRED_FIELDS; the last four are structural and match the Python module's
     * `FORMATTER_OWNED_FIELDS` — a log line without a message is not a log line, `logger` names
     * the emitting channel, `exception` carries the failure, and `dropped_fields` is how the
     * allow-list tells an author their field did not ship.
     */
    public const FORMATTER_OWNED_FIELDS = [
        'timestamp',
        'severity',
        'service',
        'env',
        'trace_id',
        'span_id',
        'request_id',
        'operation',
        'logger',
        'message',
        'exception',
        'dropped_fields',
    ];

    /**
     * What a call site may pass in a log context. CLOSED. Anything else is dropped by name.
     *
     * This is `ALLOWED_EXTRA_FIELDS` from `services/ai-service/app/observability/logging.py`,
     * character for character, and it is duplicated rather than derived because the two runtimes
     * share no code. `tests/Contract/LogFieldContractTest.php` reads the Python file as data and
     * fails on any disagreement — which is the only thing that makes "one vocabulary" true rather
     * than aspirational.
     *
     * Three buckets, and extending it means editing
     * `kb-observability-conventions/references/logs-health-audit.md` in the same change:
     *   1. the catalogue's own *when applicable* fields;
     *   2. the metric label allow-list, every value of which is bounded by construction;
     *   3. log-only diagnostics no metric label can express.
     *
     * NOTE WHAT IS ABSENT and must stay absent: `source_id`, `conversation_id`, `message_id`,
     * `chunk_id`, `user_id`, `url`, `query`, `prompt`, `packed_context`, `text`, `content`,
     * `messages`, `provider_credential`, `authorization`, `x-kb-signature`.
     */
    public const ALLOWED_EXTRA_FIELDS = [
        // (1) logs-health-audit.md — "When applicable"
        'org_id',
        'bot_id',
        'job_id',
        'error_class',
        'duration_ms',
        // (2) the metric label allow-list, bounded by construction
        'outcome',
        'disposition',
        'provider',
        'model',
        'from_model',
        'to_model',
        'token_type',
        'finish_reason',
        'stage',
        'reason',
        'scale',
        'kind',
        'engine',
        'file_type',
        'queue',
        'collection',
        'task',
        'status_class',
        'dependency',
        'required',
        'version',
        'git_sha',
        'contract_version',
        // (3) log-only diagnostics
        'attempt',
        'count',
        'errors',
        'in_worker',
        'instrumented_app',
    ];

    /**
     * Monolog level -> a string the Collector's `severity_parser` actually maps, via the
     * OpenTelemetry logs data model's syslog severity table. See the class docblock for the
     * measured evidence; `tests/Unit/KbJsonFormatterTest.php` pins both the mapping and the
     * accepted alphabet.
     */
    public const SEVERITY = [
        'DEBUG' => 'DEBUG',        // Debug(5)
        'INFO' => 'INFO',          // Info(9)
        'NOTICE' => 'INFO2',       // Info2(10)   — "normal but significant"
        'WARNING' => 'WARNING',    // Warn(13)    — `warning` is a built-in alias for `warn`
        'ERROR' => 'ERROR',        // Error(17)
        'CRITICAL' => 'ERROR2',    // Error2(18)
        'ALERT' => 'ERROR3',       // Error3(19)
        'EMERGENCY' => 'FATAL',    // Fatal(21)
    ];

    /**
     * Framework-generated context keys that are dropped like any other unadmitted name, but WITHOUT
     * being reported in `dropped_fields`.
     *
     * `userId` is put on the context of every reported exception by Laravel's own exception
     * handler. It is not a call site's field and no author can stop it being added, so reporting it
     * would stamp a permanent, meaningless `dropped_fields: ["userId"]` on every error line in
     * every container — which is how a signal that means "your field did not ship" becomes noise
     * nobody reads. The VALUE is still dropped: `user_id` is one of the identifiers
     * `references/logs-health-audit.md` deliberately withholds from logs.
     *
     * This is the same call `services/ai-service/app/observability/logging.py` makes about
     * uvicorn's `color_message`, for the same reason.
     */
    public const IGNORED_CONTEXT_FIELDS = ['userId'];

    public const REDACTION_MARKER = '[REDACTED]';

    /**
     * Stated plainly, because a redactor that gives false confidence is worse than none.
     *
     * TWO FUNCTIONS, TWO COVERAGES, TWO CONSTANTS. This one describes :self::redact(), the rule set
     * for a free-form MESSAGE. :self::VALUE_REDACTION_LIMITS describes :self::redactValue(), the
     * rule set for ONE ALREADY-NAMED VALUE, which is a strict subset. Publishing one string for
     * both is how a caller ends up confident about the coverage of the other one.
     *
     * What :self::redact() DOES catch, in the message and in a rendered exception:
     *   * `key: value` / `key=value` for a credential-shaped key name;
     *   * `Bearer …` / `Basic …` and our own `KB1 <key_id>:<signature>` scheme;
     *   * the five vendor key shapes we can actually pin (`sk-`, `sk-ant-`, `nvapi-`, `AKIA…`,
     *     `ghp_`);
     *   * the query string of any absolute http(s) URL — the route by which a customer's question
     *     reaches telemetry unnoticed, and the same key the Collector's `transform` deletes.
     *
     * What it does NOT catch, and cannot:
     *   * `Log::info('answer: '.$completion)` — model output interpolated into the message.
     *     Nothing in a formatter can tell prose from prose. The allow-list stops the FIELD; only
     *     review stops the MESSAGE.
     *   * a credential with no recognisable shape — a bare 32-character hex string from a provider
     *     that does not prefix its keys is indistinguishable from a request id.
     *   * retrieved chunk text, a packed prompt or a user question written into the message.
     */
    public const REDACTION_LIMITS = 'catches credential-shaped tokens, Authorization/X-KB-Signature/KB1 values and URL query '
        .'strings in the message and in a rendered exception; does NOT catch tenant content, model '
        .'output or an unshaped credential interpolated into a message string. This is the MESSAGE '
        .'rule set: a caller sanitising one already-named value uses redactValue(), whose narrower '
        .'coverage is VALUE_REDACTION_LIMITS';

    /**
     * The same statement for :self::redactValue(), which is :self::redact() MINUS the `key=value`
     * rule — so its coverage is strictly smaller and saying so is the whole point of a second
     * constant. The reasoning for each inclusion and the one exclusion is on :self::redactValue().
     */
    public const VALUE_REDACTION_LIMITS = 'catches an Authorization/Basic scheme value, our KB1 '
        .'<key_id>:<signature>, the five pinned vendor key shapes and the query string of an absolute '
        .'http(s) URL, anywhere inside ONE already-named value; does NOT catch tenant content, model '
        .'output, an unshaped credential, or a `key=value` pair — the field name is the caller\'s map '
        .'key, so a key=value inside the value is incidental text and that rule stays in redact()';

    /**
     * How deep a context value is walked before it is rendered as its type name. A log line is not
     * a serializer: an object graph five levels down is a payload somebody forgot they were
     * logging.
     */
    private const MAX_DEPTH = 4;

    /** Frames of an exception rendered. Enough to locate the failure, not a core dump. */
    private const MAX_FRAMES = 10;

    /**
     * The optional auth SCHEME is captured and KEPT, and the token after it is what goes. Without
     * that third group the key/value rule matches `Authorization: Bearer <token>`, replaces the
     * word `Bearer` (the next non-space run) and leaves the actual credential standing — which is
     * worse than not matching at all, because the output looks redacted.
     */
    private const CREDENTIAL_KEY_VALUE = '/\b(authorization|x[-_]kb[-_]signature|x[-_]api[-_]key|api[-_]?key|apikey|'
        .'secret[-_]?key|access[-_]?key|password|passwd|secret|token|cookie|credential)'
        .'("?\s*[:=]\s*"?)'
        .'((?:bearer|basic|kb1)\s+)?'
        .'[^\s,;"\'}\])]+/i';

    private const BEARER = '/\b(bearer|basic)\s+[A-Za-z0-9._\-+\/=]{8,}/i';

    /**
     * Our own signing scheme is `KB1 <key_id>:<signature>` (kb-internal-api-contracts), so the
     * COLON has to be inside the character class. Without it the pattern matches `KB1 k1` — two
     * characters, below the length floor — and the signature after the colon survives intact.
     */
    private const KB1_SIGNATURE = '/\bKB1\s+[A-Za-z0-9+\/=._:\-]{8,}/';

    private const VENDOR_KEYS = '/\b(?:sk-ant-[A-Za-z0-9_\-]{12,}'
        .'|sk-[A-Za-z0-9_\-]{12,}'
        .'|nvapi-[A-Za-z0-9_\-]{12,}'
        .'|ghp_[A-Za-z0-9]{20,}'
        .'|AKIA[0-9A-Z]{16})/';

    private const URL_QUERY = '/(https?:\/\/[^\s"\'<>]*?)\?[^\s"\'<>]*/i';

    /**
     * `service` and `env` are resolved ONCE, by `config/logging.php`, and injected — not read per
     * record. They are process-wide, they are two of the four permitted Loki stream labels, and
     * re-reading the environment per line would let a mid-process mutation split one container's
     * logs across two streams. Injecting them is also what keeps this class free of `env()`, which
     * `tests/Arch/DoctrineTest.php` bans outside `config/`.
     */
    public function __construct(
        private readonly string $service,
        private readonly string $env,
    ) {}

    /**
     * The `env` log field, from `OTEL_RESOURCE_ATTRIBUTES` first and `APP_ENV` second.
     *
     * That order is deliberate: `deployment.environment.name` is what the Collector projects onto
     * the `env` METRIC label (`transform/metric_labels` in collector.yaml), so reading it first
     * guarantees the log field and the metric label agree. A log line whose `env` disagrees with
     * the metric label for the same process is the quiet version of a broken dashboard — both
     * queries succeed and they describe different fleets.
     *
     * Pure and static so `config/logging.php` can call it with two `env()` reads and a Unit test
     * can call it with two strings.
     */
    public static function environmentFrom(?string $resourceAttributes, ?string $fallback): string
    {
        foreach (explode(',', (string) $resourceAttributes) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);

            if (trim((string) $key) === 'deployment.environment.name' && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        $fallback = trim((string) $fallback);

        return $fallback === '' ? 'local' : $fallback;
    }

    /**
     * Strip credential-shaped material from a free-text string. The BACKSTOP — see
     * :self::REDACTION_LIMITS for exactly what it misses.
     */
    public static function redact(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        // `?? $text`: preg_replace returns null on a backtrack-limit blow-up, and a null here would
        // turn a log line into an empty string. Keeping the previous value keeps the earlier
        // substitutions and never loses the line.
        //
        // THE ONE MESSAGE-ONLY RULE, THEN THE VALUE-SHAPED ONES. Delegating the tail to
        // :self::redactValue() is what makes "the message rule set is a SUPERSET of the value rule
        // set" a property of the code instead of a claim in a comment: a rule added there applies
        // here for free, and the two can never disagree about a shape they both recognise. The
        // ORDER is unchanged from when this was one flat sequence — key/value first, so
        // `Authorization: Bearer <tok>` is consumed by the rule that keeps its scheme.
        $text = preg_replace(self::CREDENTIAL_KEY_VALUE, '$1$2$3'.self::REDACTION_MARKER, $text) ?? $text;

        return self::redactValue($text);
    }

    /**
     * Strip credential-shaped material from ONE ALREADY-NAMED VALUE. The value-shaped subset of
     * :self::redact() — see :self::VALUE_REDACTION_LIMITS for what that costs.
     *
     * WHO CALLS THIS, AND WHY IT IS NOT :self::redact()
     * =================================================
     * `App\Services\Audit\AuditLogger::sanitize()` uses a redaction pass as a SHAPE BACKSTOP under
     * its per-operation `details` allow-list: an `ECHOED` value that redaction alters is not echoed.
     * That caller's input is not a message. It is one value whose FIELD NAME the caller already
     * holds as the map key, and the two are never in the same string. :self::redact() assumes the
     * opposite — that a key name and its value sit side by side in prose — and one of its rules is
     * built entirely on that assumption.
     *
     * MEASURED IN THE PROJECT IMAGE, against this repo's `egulias/email-validator` under the exact
     * pair `email:rfc,strict` builds (`RFCValidation` + `NoRFCWarningsValidation`), showing which
     * rule fires on each address:
     *
     *     token=abc@example.com          rfc,strict VALID   CREDENTIAL_KEY_VALUE   -> not echoed
     *     my.token=x@example.com         rfc,strict VALID   CREDENTIAL_KEY_VALUE   -> not echoed
     *     secret=1@example.com           rfc,strict VALID   CREDENTIAL_KEY_VALUE   -> not echoed
     *     password=hunter2@example.com   rfc,strict VALID   CREDENTIAL_KEY_VALUE   -> not echoed
     *     x-api-key=q@example.com        rfc,strict VALID   CREDENTIAL_KEY_VALUE   -> not echoed
     *     sk-abcdefghijkl@example.com    rfc,strict VALID   VENDOR_KEYS            -> not echoed
     *     bob@example.com                rfc,strict VALID   (none)                 -> echoed
     *
     * `=` is legal `atext` in a dot-atom, so every one of those is an address a person can register
     * and log in with. `AuditLogger::OPERATIONS` documents `auth.login.failed.email` as
     * attacker-controlled free text from a login form, and that row carries `organization_id = NULL`
     * and `actor_id = NULL` by design, so a caller choosing such a username used to produce an
     * audit row identifying nothing. THE FALSE POSITIVE IS THE `key=value` RULE, and it is a false
     * positive only for a value — in a log line `... password=hunter2 ...` it is exactly right,
     * which is why :self::redact() keeps it.
     *
     * EVERY RULE, IN OR OUT, WITH THE ARGUMENT
     * ========================================
     * The dividing question is: **does the rule recognise the credential's own shape, or does it
     * recognise a SENTENCE about a credential?** A value has no sentence around it.
     *
     *   * CREDENTIAL_KEY_VALUE — **EXCLUDED.** It recognises a sentence: fifteen credential-ish key
     *     names followed by `:` or `=` and then whatever comes next. The value it captures has NO
     *     shape of its own, so the rule's entire discriminating power is the key name — the one
     *     thing the caller already supplies out of band. Measured against the whole `ECHOED`
     *     vocabulary in `AuditLogger::OPERATIONS` (`email`, `mechanism`, `reason`, `role`,
     *     `from_role`, `to_role`, `expires_at`), it can only ever fire on free text, and the one
     *     free-text field there is documented as hostile input. What excluding it COSTS is a
     *     credential with no recognisable shape sitting behind a credential-ish word inside a
     *     value — `email: "password=hunter2"`. That is a real loss, and it is smaller than it
     *     looks: the scheme-prefixed forms (`Authorization: Bearer …`, `KB1 …`) are caught by BEARER
     *     and KB1_SIGNATURE regardless of the key name in front of them, so what remains is
     *     precisely the class :self::REDACTION_LIMITS already declares uncatchable (an unshaped
     *     credential), reached through a key name that is not this map's key. The allow-list, not
     *     redaction, is what keeps a credential out of `details`: no operation admits a
     *     credential-bearing field, and `token` is `FINGERPRINTED` so its plaintext never reaches
     *     the echo path at all.
     *   * BEARER — **KEPT.** `bearer|basic` plus 8+ base64/`._-+/=` characters is the credential's
     *     own shape, not a sentence: the scheme word is part of the value a real `Authorization`
     *     header carries. It fires on `Bearer <tok>` with no key name in front, which is what makes
     *     it independent of the excluded rule. No rfc,strict-valid address can reach it — the
     *     scheme needs a literal space, and a space is not `atext` (probed: `bearer.abcdefghij@…`
     *     and `basic+abcdefghij@…` are valid addresses and neither matches).
     *   * KB1_SIGNATURE — **KEPT.** Our own `KB1 <key_id>:<signature>` scheme
     *     (`kb-internal-api-contracts`). Same argument as BEARER and the same space requirement
     *     (probed: `KB1.abcdefghij@…` and `KB1abcdefghijkl@…` are valid addresses, neither matches).
     *     An internal signature in an append-only table is a replay primitive for as long as
     *     retention lasts.
     *   * VENDOR_KEYS — **KEPT, with a known and accepted residual.** The five prefixes are the
     *     credential's own shape and the reason `sk-ant-…` is still caught. The residual is in the
     *     table above: `sk-abcdefghijkl@example.com` is a registrable address that matches
     *     `sk-[A-Za-z0-9_\-]{12,}`, so narrowing does NOT rescue it. IT IS DELIBERATELY NOT FIXED.
     *     The only available fix is a lookahead refusing a match followed by `@domain`, and that
     *     drops a credential in URL userinfo position (`https://sk-ant-…@host/`), which is a real
     *     leak shape — trading a rare false positive for a class of true negatives. The residual is
     *     also no longer destructive: `AuditLogger` writes `<key>_fingerprint` for a value that
     *     fails this backstop, so such a user's rows stay correlatable and the WARNING still fires.
     *   * URL_QUERY — **KEPT, and this was the close call.** Argument against: an audit detail could
     *     legitimately carry a URL, and `https://example.test/docs?page=2` has no capability in it,
     *     so redaction there costs a reader the one part of the URL that said WHICH page. Argument
     *     for, which wins: a query string is a VALUE shape (it needs a literal `https?://`, which no
     *     rfc,strict address can contain — the only email that reaches it needs a quoted local part,
     *     `"https://x/?a=b"@example.com`, and that is REJECTED by NoRFCWarningsValidation, measured),
     *     so unlike the excluded rule it has no reachable false positive here; and it is now the
     *     ONLY rule left that catches a capability in a query string, because dropping
     *     CREDENTIAL_KEY_VALUE gave up `?token=…`. That is not hypothetical in this codebase — this
     *     application's own password-reset and invitation links are
     *     `FrontendUrl::for('/reset-password', ['token' => …, 'email' => …])`
     *     (`app/Notifications/ResetPassword.php`), i.e. `https://…/reset-password?token=<live bearer
     *     capability>`, exactly the string a future `details` field naming a generated link would
     *     hold. The harm is asymmetric: a false positive costs one field's plaintext and leaves a
     *     fingerprint plus a WARNING, while a false negative writes a working account-takeover
     *     primitive into an append-only store for the whole retention window. It keeps the URL up to
     *     the `?`, so the row still says which endpoint was involved.
     *
     * NO PYTHON COUNTERPART, ON PURPOSE. `audit_logs` is Laravel-owned (`kb-architecture-map`), so
     * the data plane has no caller for this and `services/ai-service/app/observability/logging.py`
     * keeps one `redact()` for its messages. The cross-plane parity pinned by
     * `tests/Contract/LogFieldContractTest.php` is about the FIELD vocabulary and the message
     * treatment, neither of which moves here. If the data plane ever writes audit rows, port this
     * function and its argument together — a second narrowing decided independently is how the two
     * planes come to disagree about what a credential looks like.
     */
    public static function redactValue(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $marker = self::REDACTION_MARKER;

        // `?? $value` for the reason :self::redact() states: a backtrack-limit null must not empty
        // the value. Here it matters twice over — the caller compares input to output, and an empty
        // string would read as "redaction fired" on a value nothing recognised.
        $value = preg_replace(self::BEARER, '$1 '.$marker, $value) ?? $value;
        $value = preg_replace(self::KB1_SIGNATURE, 'KB1 '.$marker, $value) ?? $value;
        $value = preg_replace(self::VENDOR_KEYS, $marker, $value) ?? $value;

        return preg_replace(self::URL_QUERY, '$1?'.$marker, $value) ?? $value;
    }

    public function format(LogRecord $record): string
    {
        $payload = [
            // RFC3339 UTC with millisecond precision — the same shape the Collector's docker
            // `json_parser` layout uses, so a human comparing the two sees one format.
            'timestamp' => $record->datetime
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.v\Z'),
            // `severity`, NOT `level` and NOT `level_name`. The catalogue names this field and the
            // Collector's severity_parser reads it first.
            'severity' => self::SEVERITY[$record->level->getName()] ?? 'ERROR',
            'service' => $this->service,
            'env' => $this->env,
            'trace_id' => null,
            'span_id' => null,
            'request_id' => LogContext::requestId(),
            'operation' => LogContext::operation(),
            'logger' => $record->channel,
            'message' => self::redact($record->message),
        ];

        [$payload['trace_id'], $payload['span_id']] = self::traceIds();

        [$extras, $dropped, $exception] = $this->partition($record);

        foreach ($extras as $key => $value) {
            $payload[$key] = $value;
        }

        if ($exception !== null) {
            $payload['exception'] = $exception;
        }

        if ($dropped !== []) {
            $payload['dropped_fields'] = $dropped;
        }

        return $this->encode($payload)."\n";
    }

    /**
     * @param  array<array-key, LogRecord>  $records
     */
    public function formatBatch(array $records): string
    {
        $out = '';

        foreach ($records as $record) {
            $out .= $this->format($record);
        }

        return $out;
    }

    /**
     * The active trace and span ids as lowercase hex, or `[null, null]`.
     *
     * READ FROM THE ACTIVE SPAN, NOT FROM A "is OTel enabled" FLAG. `OTEL_LOGS_EXPORTER=none` and
     * `OTEL_PHP_AUTOLOAD_ENABLED` decide whether telemetry is EXPORTED; they say nothing about
     * whether a span is in scope. `Span::getCurrent()` is part of `open-telemetry/api`, works with
     * no SDK installed at all, and returns a non-recording span whose context is invalid — so this
     * renders `null` today and starts populating itself the day the SDK is switched on, with no
     * second change anywhere.
     *
     * THE ALL-ZERO SENTINEL IS REJECTED EXPLICITLY, mirroring `_trace_ids` in
     * `services/ai-service/app/observability/logging.py`. `isValid()` already covers it, and the
     * check stays because taking `"0"*32` at face value would put a dead link in Grafana on every
     * line emitted outside a span — and that is a property of the VALUE, which is what a reader
     * needs to see asserted.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function traceIds(): array
    {
        try {
            $context = Span::getCurrent()->getContext();

            if (! $context->isValid()) {
                return [null, null];
            }

            $traceId = $context->getTraceId();
            $spanId = $context->getSpanId();

            if (trim($traceId, '0') === '' || trim($spanId, '0') === '') {
                return [null, null];
            }

            return [$traceId, $spanId];
        } catch (Throwable) {
            // Telemetry fails soft (§19.6). A broken tracer must not cost the log line that would
            // have explained why it broke.
            return [null, null];
        }
    }

    /**
     * Split a record's context and extra into the fields that ship, the names that were dropped,
     * and the rendered exception.
     *
     * FLAT, NOT NESTED. `context` under a `context` key makes every Loki query a JSON path
     * expression; flattened, an on-call reader writes `| json | error_class="provider_temporary"`.
     *
     * @return array{0: array<string, mixed>, 1: list<string>, 2: ?string}
     */
    private function partition(LogRecord $record): array
    {
        $allowed = [];
        $dropped = [];
        $exception = null;

        // `extra` (processor output) first so an explicit context key of the same name wins: the
        // call site is the more specific statement.
        /** @var array<array-key, mixed> $fields */
        $fields = array_merge($record->extra, $record->context);

        foreach ($fields as $key => $value) {
            $key = (string) $key;

            // Monolog's own convention: `['exception' => $throwable]` is how Laravel's handler
            // reports. It is formatter-owned, so it is CONSUMED rather than dropped.
            if ($key === 'exception') {
                $exception = $value instanceof Throwable
                    ? $this->renderException($value)
                    : self::redact((string) $this->scalarize($value));

                continue;
            }

            // Dropped, but not reported — see :self::IGNORED_CONTEXT_FIELDS.
            if (in_array($key, self::IGNORED_CONTEXT_FIELDS, true)) {
                continue;
            }

            // A context key colliding with a contract field never wins — see the class docblock.
            // It is reported by NAME so the author sees the collision instead of wondering why
            // their field vanished.
            if (in_array($key, self::FORMATTER_OWNED_FIELDS, true)) {
                $dropped[] = $key;

                continue;
            }

            if (! in_array($key, self::ALLOWED_EXTRA_FIELDS, true)) {
                // The NAME only. A dropped field's VALUE is dropped precisely because it may be
                // the thing that must never be written down.
                $dropped[] = $key;

                continue;
            }

            if ($value === null) {
                continue;
            }

            $allowed[$key] = $this->normalize($value, 0);
        }

        $dropped = array_values(array_unique($dropped));
        sort($dropped);

        return [$allowed, $dropped, $exception];
    }

    /**
     * Class, redacted message and a file:line frame list. DELIBERATELY NOT `getTraceAsString()`.
     *
     * PHP renders call ARGUMENTS in that string — scalars truncated to 15 characters — so a user
     * question, a chunk of retrieved text or a provider key passed to any function on the stack
     * lands in the log store. `#[SensitiveParameter]` (see `App\Support\Crypto\CredentialVault`)
     * covers the credential case and nothing covers the tenant-content case, and `redact()` cannot
     * tell prose from prose. Frames are therefore rebuilt from `file`/`line`/`class`/`function`
     * and `args` is never read.
     */
    private function renderException(Throwable $throwable): string
    {
        $rendered = sprintf(
            '%s: %s at %s:%d',
            $throwable::class,
            $throwable->getMessage(),
            $throwable->getFile(),
            $throwable->getLine(),
        );

        $frames = [];

        foreach (array_slice($throwable->getTrace(), 0, self::MAX_FRAMES) as $frame) {
            $frames[] = sprintf(
                '%s(%s) %s%s%s',
                is_string($frame['file'] ?? null) ? $frame['file'] : '[internal]',
                is_int($frame['line'] ?? null) ? (string) $frame['line'] : '0',
                is_string($frame['class'] ?? null) ? $frame['class'] : '',
                is_string($frame['type'] ?? null) ? $frame['type'] : '',
                is_string($frame['function'] ?? null) ? $frame['function'] : '',
            );
        }

        if ($frames !== []) {
            $rendered .= "\n".implode("\n", $frames);
        }

        $previous = $throwable->getPrevious();

        if ($previous !== null) {
            $rendered .= "\ncaused by ".$previous::class.': '.$previous->getMessage();
        }

        return self::redact($rendered);
    }

    /**
     * Render a value that survived the allow-list, refusing unknown objects BY NAME.
     *
     * An unknown object becomes `"<ClassName>"` and never `(string) $value`. `__toString()` on an
     * arbitrary object is an open channel from any library into the log store — a provider
     * exception whose repr echoes the request, a model holding a decrypted credential — and it is
     * exactly the leak the allow-list cannot see, because the FIELD NAME was fine.
     */
    private function normalize(mixed $value, int $depth): mixed
    {
        if (is_string($value)) {
            return self::redact($value);
        }

        if (is_int($value) || is_bool($value)) {
            return $value;
        }

        if (is_float($value)) {
            // NAN and INF are not representable in JSON and make json_encode fail, which would
            // cost the whole line.
            return is_finite($value) ? $value : (string) $value;
        }

        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return '<array>';
            }

            $out = [];

            foreach ($value as $key => $item) {
                $out[(string) $key] = $this->normalize($item, $depth + 1);
            }

            return $out;
        }

        return $this->scalarize($value);
    }

    private function scalarize(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::RFC3339_EXTENDED);
        }

        if ($value instanceof UnitEnum) {
            return $value instanceof BackedEnum ? (string) $value->value : $value->name;
        }

        if (is_object($value)) {
            return '<'.$value::class.'>';
        }

        if (is_resource($value)) {
            return '<resource>';
        }

        return '<'.get_debug_type($value).'>';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        if ($json !== false) {
            return $json;
        }

        // NEVER THROW FROM A FORMATTER. Monolog swallows a handler exception, and the line is then
        // lost — including, on a bad day, the line describing the outage. A minimal, guaranteed
        // encodable object keeps the required fields and says what happened.
        return (string) json_encode([
            'timestamp' => $payload['timestamp'] ?? '',
            'severity' => 'ERROR',
            'service' => $this->service,
            'env' => $this->env,
            'trace_id' => null,
            'span_id' => null,
            'request_id' => is_string($payload['request_id'] ?? null) ? $payload['request_id'] : null,
            'operation' => is_string($payload['operation'] ?? null) ? $payload['operation'] : null,
            'logger' => 'kb-json-formatter',
            'message' => 'a log record could not be encoded as JSON: '.json_last_error_msg(),
        ]);
    }
}
