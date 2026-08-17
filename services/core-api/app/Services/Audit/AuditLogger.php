<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Logging\KbJsonFormatter;
use App\Models\AuditLog;
use App\Repositories\Contracts\AuditLogRepositoryInterface;
use App\Repositories\Eloquent\EloquentAuditLogRepository;
use App\Support\Observability\LogContext;
use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Give;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * THE writer of audit_logs. There is exactly one, and that is the design rather than a tidiness
 * preference.
 *
 * WHAT THIS CLASS IS DEFENDING AGAINST
 * ====================================
 * `kb-security-baseline:147`, verbatim: *"The audit row leaks the thing it was auditing.
 * `'details' => $request->all()` on a credential update writes the plaintext key into the audit
 * table, which is append-only and long-lived by design. Allow-list the fields that go into
 * `details`; fingerprint, never echo."* Every structural choice below follows from that one
 * sentence:
 *
 *   * `details` IS ALLOW-LISTED PER OPERATION, not filtered by a denylist. A denylist is a list of
 *     the field names somebody thought of, and it fails on the first field added after it was
 *     written — silently, because the failure mode of a missed denylist entry is a successful write.
 *     An allow-list fails closed: an unlisted key does not reach the table, whether it is
 *     `api_key`, `plaintext`, or `x`.
 *   * A FIELD IS DECLARED `ECHOED` OR `FINGERPRINTED`, AND THAT IS THE ONLY HANDLING IT HAS. There is
 *     no call site that can choose to echo a token, because the map says `token` is FINGERPRINTED and
 *     the value is hashed here, under the key `token_fingerprint`. Passing `token` and hoping is not
 *     a way to leak it. (The constants are `ECHOED`/`FINGERPRINTED` rather than the obvious two
 *     nouns because `const ECHO` is a class constant named after a language construct — legal since
 *     PHP 7 and still the kind of thing a tool gets wrong.)
 *   * NOTHING IS SILENT. A dropped key is a WARNING naming the key (never the value). A write failure
 *     is an ERROR carrying `error_class`. The failure mode of a quiet audit logger is an empty
 *     compliance record that everyone believes is complete.
 *   * A SHAPE BACKSTOP SITS UNDER THE ALLOW-LIST, and it is documented as a backstop rather than
 *     promoted to the defence — the same distinction `KbJsonFormatter::VALUE_REDACTION_LIMITS` makes
 *     for log messages. It reuses that class's regex set instead of inventing a second redaction
 *     vocabulary, but through `redactValue()` rather than `redact()`: the message rule set matches a
 *     `key=value` pair in prose, which in an audit detail is incidental text because the caller
 *     already supplies the key.
 *   * WHEN THE BACKSTOP FIRES, THE VALUE IS FINGERPRINTED RATHER THAN DROPPED. It used to be dropped,
 *     on the argument that "a value that looks like a credential under a rule that says echo is a bug
 *     in the map, and hiding it would hide the bug". The first half is right; the conclusion was
 *     wrong for the one field the map itself documents as HOSTILE INPUT. `auth.login.failed` carries
 *     `organization_id = NULL` and `actor_id = NULL` by design, so dropping `email` left a row that
 *     identified nothing — and a caller only had to choose a username of the right shape to make
 *     every failed-login row anonymous. The WARNING still fires, so a genuinely mis-mapped ECHO field
 *     is still reported; it is now reported without also destroying the evidence.
 *
 * AN AUDIT ROW IS NOT A LOG LINE
 * ==============================
 * `kb-observability-conventions:16` — telemetry is sampled, TTL'd and may fail silently; audit is
 * complete, append-only and transactional. They never share a store, in either direction. Nothing
 * here writes an audit fact to Loki and nothing writes telemetry to `audit_logs`. The single thread
 * between them is `request_id`, taken from `LogContext` so an investigator can move from one store to
 * the other for the same request.
 *
 * THE AUDIT-WRITE-FAILURE POLICY, AND THE CONTRADICTION IT SITS ON
 * ================================================================
 * `kb-observability-conventions:16` and its Definition of done say an audit write failure **fails the
 * operation**. The brief for this unit says a failed audit write **must not turn a successful login
 * into a 500**. Both are right about different events, and the line between them is not a compromise:
 *
 *   * For a state change — a password reset, an invitation, a role change — the audit row goes in the
 *     SAME transaction as the change. If the row cannot be written, the change must not commit, and
 *     `ON_FAILURE_ABORT` rethrows so it does not. That is the skill's rule, unmodified.
 *   * Where the audited thing has ALREADY HAPPENED IRREVERSIBLY by the time the row is written, there
 *     is no transaction left to roll back. "Fail the operation" would mean returning a 500 to a caller
 *     who is nonetheless logged in — a lie to the client AND still no audit row, the worst of both. So
 *     `ON_FAILURE_LOG` records an ERROR naming the operation and lets the response stand.
 *
 * THE TEST IS "CAN THIS STILL BE ROLLED BACK", NOT "IS THIS AN AUTHENTICATION EVENT", and stating it
 * the second way is how this docblock went wrong. It used to say "the three authentication-outcome
 * events" while the constant assigned `ON_FAILURE_LOG` to FOUR operations — and then said "changing
 * four rows" two sentences later, so the file disagreed with itself. The fourth is
 * `auth.password_reset.requested`, which is not an authentication outcome and does earn the lenient
 * policy under the real rule: by the time the row is written the broker has committed the token row
 * and QUEUED THE MAIL, and a queued email cannot be recalled by a rollback. Aborting there would 500 a
 * caller whose reset link is already in flight and lose the row describing it.
 *
 * Per ADR-036 the count is not restated here. The measuring command is
 *
 *     grep -cE 'self::ON_FAILURE_LOG,$' app/Services/Audit/AuditLogger.php
 *
 * ANCHORED AT END OF LINE, AND THAT IS NOT FUSSINESS — IT TOOK TWO TRIES TO GET RIGHT. The obvious
 * `grep -c 'ON_FAILURE_LOG,'` returns one MORE than there are operations, because it also matches the
 * paragraph documenting it; and rewriting the paragraph to quote the assignment verbatim made the prose
 * match the tighter pattern too. Only the assignments END with the comma, so the anchor is what makes the
 * command immune to its own documentation. This is the trap CLAUDE.md calls out for
 * `grep -c NotImplementedError`, met twice in one docblock — which is how a published measurement drifts
 * from the code while looking rigorous. Swap `ABORT` for `LOG` to count the other policy.
 *
 * `AuditLoggerTest` pins the policy PER OPERATION BY NAME, which is the check that matters: a flip is red.
 * That pin is the load-bearing half: the suite used to assert only that each `on_failure` was one of
 * the two constants, which is a type check that a role change silently moved to LOG would satisfy.
 *
 * THE POLICY IS PER OPERATION AND LIVES IN :self::OPERATIONS, not at the call site. A caller cannot
 * pick the lenient one for a role change by accident, and the two policies are reviewable in one
 * table beside the allow-list they belong to. The contradiction between the skill and the brief is
 * reported rather than resolved by fiat.
 *
 * REQUIREMENT ON CALLERS OF AN `ON_FAILURE_ABORT` OPERATION: wrap the state change and this call in
 * one transaction. This class deliberately does not open one — it cannot know what else belongs
 * inside it, and a service that opens its own transaction around one INSERT gives the appearance of
 * atomicity without the fact.
 *
 * NO CONTAINER BINDING IS NEEDED. `#[Give]` and `#[Config]` are contextual container attributes, so
 * `app(AuditLogger::class)` resolves with nothing registered in any service provider — which is what
 * lets this unit ship without editing AppServiceProvider (another agent's file this batch). Moving
 * `AuditLogRepositoryInterface` into AppServiceProvider::bindRepositories() later and deleting the
 * attribute is a valid, behaviour-preserving change.
 */
final class AuditLogger
{
    /** A user proved their identity and a session was established. */
    public const LOGIN_SUCCEEDED = 'auth.login.succeeded';

    /** A credential was rejected. `organization_id` and `actor_id` are usually both null. */
    public const LOGIN_FAILED = 'auth.login.failed';

    public const LOGOUT = 'auth.logout';

    public const PASSWORD_RESET_REQUESTED = 'auth.password_reset.requested';

    public const PASSWORD_RESET_COMPLETED = 'auth.password_reset.completed';

    public const EMAIL_VERIFIED = 'auth.email.verified';

    public const INVITATION_CREATED = 'organization.invitation.created';

    public const INVITATION_REVOKED = 'organization.invitation.revoked';

    public const INVITATION_ACCEPTED = 'organization.invitation.accepted';

    /**
     * A resend is NOT a second `created`, and it is not cosmetic to separate them.
     *
     * Resend MINTS A NEW BEARER CAPABILITY and invalidates the previous one — `rotateToken()`
     * overwrites `token_hash`, so the old link stops working. That is the same class of event as
     * issuing the first invitation, and §18.11 requires it audited: without a row, an admin can mail
     * an unlimited number of live capabilities to a third party's mailbox and the trail shows one
     * `created` from whenever the invitation was first made.
     *
     * Reusing `INVITATION_CREATED` was considered and rejected: it would make the table assert that one
     * invitation was created twice, which is false, and it would break the "count creations to count
     * invitees" reading that is the obvious query against this data.
     */
    public const INVITATION_RESENT = 'organization.invitation.resent';

    public const ROLE_CHANGED = 'organization.member.role_changed';

    public const OUTCOME_SUCCESS = 'success';

    public const OUTCOME_FAILURE = 'failure';

    /** The audit row is part of the operation's transaction; a write failure aborts it. */
    public const ON_FAILURE_ABORT = 'abort';

    /** The audited act is already irreversible; a write failure is logged at ERROR and the response stands. */
    public const ON_FAILURE_LOG = 'log';

    /** The value is written as given, after normalisation. */
    public const ECHOED = 'echo';

    /** The value is HMAC-hashed and stored under `<key>_fingerprint`. It is never written. */
    public const FINGERPRINTED = 'fingerprint';

    /**
     * EVERY AUDITABLE OPERATION, ITS OUTCOME, ITS FAILURE POLICY, AND ITS `details` ALLOW-LIST.
     *
     * One table, because three tables drift. `outcome` is derived from the operation rather than
     * passed in, so no row can claim `auth.login.failed` with `outcome = success`.
     *
     * `subject_type`/`subject_id` are NOT in this table: they are a pointer to the record acted on,
     * they carry no free text, and constraining them here would mean a code change to audit an
     * existing operation against a new kind of record.
     *
     * WHY `email` IS ECHOED AND NOT FINGERPRINTED. It is the identifier a compliance reader searches
     * by, it is the only identifier a failed login for a non-existent account HAS, and a fingerprint
     * would make the table unqueryable for the one question it is most often asked. It is PII in a
     * long-lived store and that is a deliberate, recorded trade. It is not a secret, which is the
     * distinction that matters here.
     *
     * WHY `token` IS FINGERPRINTED. A password-reset token and an invitation token are bearer
     * capabilities: a plaintext one in an append-only table is a live account-takeover primitive for
     * as long as retention lasts. The fingerprint still answers "was THIS link the one used", which
     * is the only question an investigation asks of it.
     *
     * @var array<string, array{outcome: string, on_failure: string, details: array<string, string>}>
     */
    public const OPERATIONS = [
        self::LOGIN_SUCCEEDED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_LOG,
            'details' => [
                'email' => self::ECHOED,
                // 'session' for the SPA cookie, 'token' for a personal access token. Which
                // mechanism proved identity is the first thing an investigation asks.
                'mechanism' => self::ECHOED,
            ],
        ],
        self::LOGIN_FAILED => [
            'outcome' => self::OUTCOME_FAILURE,
            'on_failure' => self::ON_FAILURE_LOG,
            'details' => [
                // Attacker-controlled free text from a login form. Bounded by
                // :self::MAX_VALUE_LENGTH here and by a CHECK on the table for user_agent; jsonb
                // makes the content itself inert.
                'email' => self::ECHOED,
                // Why it failed, INTERNALLY. This distinguishes unknown-address from wrong-password,
                // which the RESPONSE must never do (the login endpoint answers both with one
                // byte-identical 422). An audit row is not a response and the asymmetry is the point.
                'reason' => self::ECHOED,
            ],
        ],
        self::LOGOUT => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_LOG,
            // EMPTY ON PURPOSE. actor_id, ip_address, user_agent, request_id and created_at are
            // columns; there is nothing left for a logout row to say, and an allow-list of [] is the
            // honest statement of that.
            'details' => [],
        ],
        self::PASSWORD_RESET_REQUESTED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_LOG,
            'details' => [
                'email' => self::ECHOED,
                'token' => self::FINGERPRINTED,
            ],
        ],
        self::PASSWORD_RESET_COMPLETED => [
            'outcome' => self::OUTCOME_SUCCESS,
            // A credential change. kb-security-baseline §18.11 audits credential changes and
            // kb-observability-conventions requires the row inside the operation's transaction.
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'email' => self::ECHOED,
                'token' => self::FINGERPRINTED,
            ],
        ],
        self::EMAIL_VERIFIED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'email' => self::ECHOED,
                'token' => self::FINGERPRINTED,
            ],
        ],
        self::INVITATION_CREATED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'email' => self::ECHOED,
                'role' => self::ECHOED,
                'token' => self::FINGERPRINTED,
                'expires_at' => self::ECHOED,
            ],
        ],
        self::INVITATION_REVOKED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'email' => self::ECHOED,
                'role' => self::ECHOED,
            ],
        ],
        self::INVITATION_ACCEPTED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'email' => self::ECHOED,
                'role' => self::ECHOED,
            ],
        ],
        self::INVITATION_RESENT => [
            'outcome' => self::OUTCOME_SUCCESS,
            // ABORT, like every other state change here: the new digest and the audit row must land
            // together, or the trail records a capability that was never issued — or worse, misses one
            // that was. The rotation and this write share rotateToken()'s transaction.
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'email' => self::ECHOED,
                'role' => self::ECHOED,
                // The NEW token, fingerprinted. Two resends therefore leave two rows with different
                // fingerprints, which is what makes "which link did this person actually click" a
                // question the trail can answer at all.
                'token' => self::FINGERPRINTED,
                'expires_at' => self::ECHOED,
            ],
        ],
        self::ROLE_CHANGED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            // BOTH ends of the change. `to_role` alone cannot answer "was this a privilege
            // escalation", which is the only reason the row exists.
            'details' => [
                'from_role' => self::ECHOED,
                'to_role' => self::ECHOED,
            ],
        ],
    ];

    /**
     * How long an echoed string may be. Everything admitted above is an email (≤ 254), a role name,
     * a reason token or a timestamp, so this is a bound on hostile input rather than on our own.
     */
    private const MAX_VALUE_LENGTH = 512;

    /** `user_agent` has a matching CHECK on the table; this keeps the insert from ever hitting it. */
    private const MAX_USER_AGENT_LENGTH = 512;

    /** Dropped-key names echoed into one warning line before it is truncated. */
    private const MAX_REPORTED_DROPS = 10;

    /**
     * Bytes of the HMAC kept. 16 hex characters is 64 bits — enough that two distinct tokens
     * colliding is not a thing that happens, and short enough that nobody mistakes it for the value.
     */
    private const FINGERPRINT_LENGTH = 16;

    public function __construct(
        // No service-provider binding needed — see the class docblock.
        #[Give(EloquentAuditLogRepository::class)]
        private readonly AuditLogRepositoryInterface $repository,
        private readonly LoggerInterface $log,
        // KEYED, not a bare hash. An unkeyed sha256 of an email address is a rainbow-table lookup,
        // so a "fingerprint" of one would be the value with extra steps. This is the same
        // construction kb-security-baseline §18.11's worked example uses.
        // NULLABLE, not `string` with a default: config('app.key') returns NULL when APP_KEY is
        // unset, and a non-nullable promoted property would then TypeError during resolution —
        // making every audited action fail with a container error instead of the sentence in
        // :self::fingerprint().
        #[Config('app.key')]
        private readonly ?string $fingerprintKey,
    ) {}

    /**
     * Append one audit row.
     *
     * @param  string  $operation  one of the :self:: operation constants — a literal string is a
     *                             typo waiting to become an unqueryable row
     * @param  array<string, mixed>  $details  filtered against the operation's allow-list; anything
     *                                         unlisted is dropped and reported
     * @param  Request|null  $request  supplies `ip_address` and `user_agent`. Null from a queued job
     *                                 or a console command, which have neither.
     * @return AuditLog|null the row, or null when the write failed under ON_FAILURE_LOG
     *
     * @throws InvalidArgumentException on an unknown operation or a half-specified subject — both
     *                                  programming errors, loud on purpose
     * @throws Throwable the underlying write failure, for an ON_FAILURE_ABORT operation
     */
    public function record(
        string $operation,
        ?string $organizationId,
        ?string $actorId,
        array $details = [],
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?Request $request = null,
    ): ?AuditLog {
        $spec = self::OPERATIONS[$operation] ?? null;

        if ($spec === null) {
            // FAIL LOUD RATHER THAN WRITING THE ROW WITH NO DETAILS. An operation name that is not
            // in the map is a typo or a missing map entry, and both mean nobody has decided what
            // this event may record. Writing it anyway produces a row no query finds and an
            // allow-list nobody ever gets around to writing.
            throw new InvalidArgumentException(
                "Unknown audit operation '{$operation}'. Add it to ".self::class
                .'::OPERATIONS together with its outcome, its write-failure policy and its details '
                .'allow-list, and use the constant rather than a literal at the call site.',
            );
        }

        if (($subjectType === null) !== ($subjectId === null)) {
            throw new InvalidArgumentException(
                'An audit subject is a (type, id) pair or it is absent: a type with no id is '
                .'unresolvable and an id with no type is ambiguous across every table. The database '
                .'enforces the same thing (audit_logs_subject_paired).',
            );
        }

        [$sanitized, $dropped] = $this->sanitize($spec['details'], $details);

        if ($dropped !== []) {
            $this->reportDrops($operation, $organizationId, $dropped);
        }

        try {
            return $this->repository->write(
                $operation,
                $spec['outcome'],
                $organizationId,
                $actorId,
                $subjectType,
                $subjectId,
                $this->ipFrom($request),
                $this->userAgentFrom($request),
                LogContext::requestId(),
                $sanitized,
            );
        } catch (Throwable $failure) {
            if ($spec['on_failure'] === self::ON_FAILURE_ABORT) {
                // Rethrown UNWRAPPED so bootstrap/app.php's render closure sees the QueryException it
                // already knows how to classify (internal_dependency). Wrapping it here would put a
                // service-layer exception in front of the taxonomy and lose the SQLSTATE.
                throw $failure;
            }

            // The audited act already happened and cannot be undone. This line is the only remaining
            // record of the event, so it names the operation and carries error_class so the existing
            // error panels see it. It is NOT an audit row and must never be treated as one:
            // Loki is sampled and TTL'd (kb-observability-conventions).
            $this->log->error(
                'AUDIT WRITE FAILED and the audited action stands: operation '
                .$this->safeLabel($operation)
                .'. This event is now absent from audit_logs and the compliance record is incomplete.',
                [
                    'error_class' => 'internal_dependency',
                    'org_id' => $organizationId,
                    'outcome' => 'error',
                    'exception' => $failure,
                ],
            );

            return null;
        }
    }

    /**
     * Apply one operation's allow-list.
     *
     * @param  array<string, string>  $rules
     * @param  array<string, mixed>  $details
     * @return array{0: array<string, scalar>, 1: list<string>} the fields that ship, and the names
     *                                                          that did not
     */
    private function sanitize(array $rules, array $details): array
    {
        $kept = [];
        $dropped = [];

        foreach ($details as $key => $value) {
            $key = (string) $key;
            $rule = $rules[$key] ?? null;

            if ($rule === null) {
                $dropped[] = $key;

                continue;
            }

            // A null carries no information and cannot leak, so it is skipped WITHOUT being
            // reported — the same call KbJsonFormatter::partition() makes about a null context
            // value. Reporting it would make an optional field produce a warning on every request
            // it is absent from, which is how a signal becomes noise nobody reads.
            if ($value === null) {
                continue;
            }

            if ($rule === self::FINGERPRINTED) {
                $fingerprintable = is_string($value) || is_int($value);

                if (! $fingerprintable || (string) $value === '') {
                    $dropped[] = $key;

                    continue;
                }

                // The plaintext never reaches $kept under any key, and the key it lands under says
                // what it is so no reader mistakes it for the value.
                $kept[$key.'_fingerprint'] = $this->fingerprint((string) $value);

                continue;
            }

            if (is_bool($value) || is_int($value)) {
                $kept[$key] = $value;

                continue;
            }

            if (is_float($value)) {
                // NAN/INF are not representable in JSON and would fail the insert, taking the whole
                // audit row with them.
                if (! is_finite($value)) {
                    $dropped[] = $key;

                    continue;
                }

                $kept[$key] = $value;

                continue;
            }

            if (! is_string($value)) {
                // An array, an object, a resource. Nothing in the allow-list is non-scalar, and a
                // structure here is how `$request->all()` gets in one nesting level down. It is also
                // what guarantees `details` json-encodes as an OBJECT, which the table CHECKs.
                $dropped[] = $key;

                continue;
            }

            $normalized = mb_substr(trim($value), 0, self::MAX_VALUE_LENGTH);

            if ($normalized === '') {
                continue;
            }

            // THE BACKSTOP, NOT THE DEFENCE — see the class docblock and
            // KbJsonFormatter::REDACTION_LIMITS for exactly what it cannot catch (an unshaped
            // credential is indistinguishable from a request id). Reusing that regex set rather than
            // writing a second one is deliberate: one redaction vocabulary, already tested.
            //
            // NOT ECHOED — but FINGERPRINTED rather than dropped outright, so the row stays
            // correlatable. Both halves matter and the second was a real weakness.
            //
            // The premise of the old plain drop was that "an ECHO field holding something
            // credential-shaped is a defect in the map above", which is true for a developer-supplied
            // value and FALSE for the one field the map itself documents as hostile input:
            // `auth.login.failed.email` is attacker-controlled free text from a login form. And `=` is
            // legal `atext` in a dot-atom, so `token=abc@example.com`, `password=hunter2@example.com`,
            // `x-api-key=q@example.com` and `cookie=a@example.com` all pass `email:rfc,strict` —
            // measured against this project's own egulias pair — while matching the `key=value` rule
            // written for free-form log MESSAGES. The narrowing below removes that whole class.
            //
            // ONE RESIDUAL FALSE POSITIVE SURVIVES ON PURPOSE, and it is not a `key=value` case:
            // `sk-abcdefghijkl@example.com` fires `VENDOR_KEYS` (an `sk-` prefix and twelve characters),
            // which is KEPT. The only available fix is a lookahead refusing a match followed by
            // `@domain`, which would stop catching a credential in URL userinfo position
            // (`https://sk-ant-…@host/`) — a real leak shape traded for a rare address. The fingerprint
            // below is what makes that a degradation rather than a loss.
            //
            // What that bought an attacker: `auth.login.failed` rows carry `organization_id = NULL` and
            // `actor_id = NULL` by design (there is no tenant and no identity for a failed login), so
            // dropping `email` left an IDENTITY-LESS row. Probing with addresses of that shape made the
            // audit trail unable to say which account was attacked, from a client that only had to
            // choose its own username. A legitimate user with such an address lost their identity from
            // every audit operation and generated a WARNING per request.
            //
            // The fingerprint answers the question an investigation actually asks — "was it THIS
            // address" — without storing the value, which is the same trade `FINGERPRINTED` makes for
            // real capabilities. The WARNING still fires, so a genuinely mis-mapped ECHO field is still
            // reported rather than hidden; it is now reported WITHOUT also destroying the row.
            //
            // `redactValue()`, NOT `redact()`. That is the narrowing this file used to only ask for:
            // `redact()` is the MESSAGE rule set and includes `CREDENTIAL_KEY_VALUE`, which discriminates
            // by a key name sitting beside a value in prose — meaningless for an audit detail, where the
            // caller already supplies the key. `redactValue()` keeps every rule that recognises a
            // credential's own SHAPE (`BEARER`, `KB1`, vendor prefixes, and a URL query string, which
            // after the narrowing is the only remaining catcher of a capability in a `?token=`) and drops
            // that one. `redact()` now delegates to it, so the superset relationship is structural rather
            // than a promise: a rule added to the value path cannot go missing from the message path.
            // Coverage is published as `KbJsonFormatter::VALUE_REDACTION_LIMITS`.
            if (KbJsonFormatter::redactValue($normalized) !== $normalized) {
                $dropped[] = $key;

                // Never overwrite a fingerprint the map asked for itself. `AuditLoggerTest` asserts no
                // FINGERPRINTED field has a sibling already named `<key>_fingerprint`, but an ECHOED
                // field downgraded here has had no such check, and a silent overwrite would make two
                // different values indistinguishable in the one column meant to tell them apart.
                $fingerprintKey = $key.'_fingerprint';

                if (! array_key_exists($fingerprintKey, $kept)) {
                    try {
                        $kept[$fingerprintKey] = $this->fingerprint($normalized);
                    } catch (InvalidArgumentException) {
                        // An empty `app.key` — `fingerprint()` refuses rather than write an UNKEYED
                        // digest, which would be reversible by lookup and only LOOK redacted. Falling
                        // back to the plain drop keeps this a degradation of the audit row rather than a
                        // 500 on a login attempt: `sanitize()` runs OUTSIDE `record()`'s try/catch, so an
                        // escaping throw here would ignore this operation's ON_FAILURE_LOG policy and
                        // fail the request. A misconfigured APP_KEY already breaks sessions and
                        // encryption; it must not additionally turn a 422 login failure into a 500.
                    }
                }

                continue;
            }

            $kept[$key] = $normalized;
        }

        $dropped = array_values(array_unique($dropped));
        sort($dropped);

        return [$kept, $dropped];
    }

    /**
     * A dropped key is a WARNING carrying the key NAMES and never the values — the value is dropped
     * precisely because it may be the thing that must never be written down.
     *
     * The message carries the operation because `operation` in the log payload is formatter-owned
     * (it is the ROUTE's operation, bound by RequestId middleware) and a context key of that name
     * would be dropped by KbJsonFormatter's own allow-list. `count` is admitted; the names are not,
     * so they go in the message.
     *
     * @param  list<string>  $dropped
     */
    private function reportDrops(string $operation, ?string $organizationId, array $dropped): void
    {
        $shown = array_slice($dropped, 0, self::MAX_REPORTED_DROPS);
        $names = implode(', ', array_map($this->safeLabel(...), $shown));
        $overflow = count($dropped) - count($shown);

        if ($overflow > 0) {
            $names .= ", +{$overflow} more";
        }

        $this->log->warning(
            'audit detail fields were dropped by the allow-list for operation '
            .$this->safeLabel($operation).': '.$names
            .'. They are absent from the audit row. Add them to '.self::class
            .'::OPERATIONS if they belong there — and fingerprint rather than echo anything secret.',
            [
                'count' => count($dropped),
                'org_id' => $organizationId,
            ],
        );
    }

    /**
     * Keyed sha256, truncated. Identifies WHICH value without being derivable back to it (§18.11).
     */
    private function fingerprint(string $value): string
    {
        if ($this->fingerprintKey === null || $this->fingerprintKey === '') {
            // An unkeyed digest of an email or a token is a lookup, not a fingerprint. Refuse rather
            // than write something that looks redacted and is not — this is a misconfigured app, and
            // APP_KEY being empty already breaks sessions and encryption.
            throw new InvalidArgumentException(
                'Cannot fingerprint an audit detail with an empty app.key: an unkeyed digest is '
                .'reversible by lookup, which is exactly what §18.11 forbids.',
            );
        }

        return substr(hash_hmac('sha256', $value, $this->fingerprintKey), 0, self::FINGERPRINT_LENGTH);
    }

    /**
     * A validated IP literal or null.
     *
     * `ip_address` is `inet`, so an unparseable value is a 22P02 that fails the audit insert — and
     * the value comes from a header a client controls. Validating here means a hostile
     * X-Forwarded-For costs the IP field rather than the whole row.
     */
    private function ipFrom(?Request $request): ?string
    {
        $ip = $request?->ip();

        if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $ip;
    }

    private function userAgentFrom(?Request $request): ?string
    {
        $agent = $request?->userAgent();

        if (! is_string($agent) || trim($agent) === '') {
            return null;
        }

        return mb_substr($agent, 0, self::MAX_USER_AGENT_LENGTH);
    }

    /**
     * Render an identifier into a log MESSAGE safely.
     *
     * Operation names and detail keys are developer symbols, but a message string is the one place
     * KbJsonFormatter's field allow-list cannot help, so nothing arbitrary goes into one: anything
     * outside `[A-Za-z0-9_.-]` becomes `?` and the result is short.
     */
    private function safeLabel(string $label): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.\-]/', '?', $label) ?? '?';

        return mb_substr($safe, 0, 64);
    }
}
