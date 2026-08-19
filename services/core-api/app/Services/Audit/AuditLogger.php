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

    /**
     * ── THE PROVIDER-CONNECTION OPERATIONS ─────────────────────────────────────────────────────
     *
     * FOUR SUCCESSES AND ONE FAILURE. The four below are state changes;
     * :self::PROVIDER_CREDENTIAL_ROTATION_FAILED is a failed re-authentication ATTEMPT on the
     * rotation endpoint and carries its own docblock. Everything in this block describes the four.
     *
     * kb-security-baseline §18.11 requires credential changes audited, and until this batch the
     * provider surface wrote NO audit row at all — a connection could be created, relabelled,
     * revoked, hard-deleted or have its key replaced with nothing in `audit_logs` to say so. That
     * was a real §18 gap rather than a deferred nicety, and `provider.connection.created` is
     * wired into the pre-existing `store` action for exactly that reason.
     *
     * ALL FOUR ARE `ON_FAILURE_ABORT`, with no judgement call to make: every one of them is a
     * state change that is still inside a repository transaction when the row is written, so
     * "can this still be rolled back" — the real test, see the class docblock — answers yes for
     * all of them. Nothing here queues mail and nothing here has already happened irreversibly.
     * The rotation-FAILURE row is `ON_FAILURE_LOG` for the mirror-image reason: there is no state
     * change to roll back at all.
     *
     * WHAT THE ALLOW-LISTS DELIBERATELY CANNOT CARRY. `credential`, `provider_credential`,
     * `api_key`, `secret`, `password`, `current_password`, `last_four`, `masked_key`,
     * `credential_ciphertext` and `data_key_ciphertext` are absent from all five, so no call site can put one in a row even
     * by passing it under that key — an unlisted key is dropped and reported, never written. That
     * includes FINGERPRINTED: a rotation records no digest of the key at all. §18.11's worked
     * example does fingerprint the key, and the argument for omitting it here is that a
     * fingerprint answers "was it THIS key" — a question nobody asks of a provider credential,
     * because unlike an invitation or reset token it is not a capability anyone presents to us.
     * `key_version` and `credential_version` answer the question that IS asked ("which generation
     * of this credential was live on that date") and are derivable back to nothing.
     */
    public const PROVIDER_CONNECTION_CREATED = 'provider.connection.created';

    public const PROVIDER_CONNECTION_UPDATED = 'provider.connection.updated';

    /**
     * A HARD delete, so this row is the only surviving description of the connection.
     *
     * That is what makes `provider`, `label` and `status` load-bearing here rather than
     * decorative: `subject_id` points at a ULID no table resolves any more, and without the three
     * echoed fields the trail says a connection was deleted without being able to say which.
     */
    public const PROVIDER_CONNECTION_DELETED = 'provider.connection.deleted';

    public const PROVIDER_CREDENTIAL_ROTATED = 'provider.connection.credential_rotated';

    /**
     * AN ATTEMPT THAT FAILED THE RE-AUTHENTICATION, AND THE ONLY PROVIDER OPERATION THAT IS NOT A
     * SUCCESS.
     *
     * §18.11 requires credential changes audited, and an ATTEMPT on the endpoint that changes a
     * credential is precisely what a post-incident timeline needs: `auth.login.failed` exists for
     * the login surface, and this endpoint — which verifies the actor's password under §18.3 —
     * had no equivalent, so a session stolen through XSS could grind at the re-authentication
     * behind it and leave nothing in `audit_logs` at all. The rate limiter bounds the attempts
     * (`credential-rotation`, 5 per 15 minutes per actor); it does not RECORD them.
     *
     * `current_password:web` sits in `rules()` — which is correct and stays, because it is what
     * makes "a wrong password touches no column" true by construction rather than by ordering
     * discipline — so a failed attempt never reaches the service layer and the row has to be
     * written from `failedValidation()`. That is also why it is the only `ON_FAILURE_LOG` row on
     * this surface: there is no transaction to roll back, the 422 is already decided, and turning
     * a wrong password into a 500 because the audit table was unwell would be the worst response
     * to an attempted credential change.
     *
     * WHAT IT MAY RECORD IS THE SAME THREE FIELDS THE SUCCESS ROW CARRIES, so the two read side by
     * side in one query. THE SUBMITTED PASSWORD IS NOT AMONG THEM AND CANNOT BE: `password` and
     * `current_password` are not in the allow-list, so a call site cannot put one in a row even by
     * passing it under that key — and no fingerprint of it either, which would turn an append-only
     * table into an offline guessing oracle against the actor's own account password.
     */
    public const PROVIDER_CREDENTIAL_ROTATION_FAILED = 'provider.connection.credential_rotation_failed';

    /**
     * ── THE THREE PROVIDER-MODEL OPERATIONS ────────────────────────────────────────────────────
     *
     * A CATALOG ROW IS NOT A CREDENTIAL, AND THESE ARE AUDITED ANYWAY. §18.11 requires credential
     * changes and destructive operations audited, and a model row is neither a secret nor, on its
     * face, destructive — so the case for auditing it has to be made on what the row DECIDES
     * rather than on what it holds:
     *
     *   * `capability_flags` is the ROW axis of the capability question, and
     *     services/ai-service/app/providers/embedding_selection.py reads it to decide WHICH of an
     *     organization's connections embeds its corpus. Adding an `embedding` flag can therefore
     *     change the vector space every future upload is indexed under — at a provider's
     *     per-token price, under a different account, with no error anywhere, because cosine
     *     distance is defined between any two vectors of equal width.
     *   * `enabled` can take an organization's only embedder out of the candidate set, which stops
     *     ingestion entirely.
     *   * a DELETE is a hard delete, and if the row was the designated embedding model it would
     *     leave the designation naming nothing.
     *
     * "Who made this organization start embedding through a different model" is exactly the
     * question an incident asks, and without these rows the trail answers it with the connection's
     * `created` row from months earlier.
     *
     * ALL THREE ARE `ON_FAILURE_ABORT`, with no judgement call to make: every one of them is a
     * state change that is still inside EloquentProviderModelRepository's transaction when the row
     * is written, so "can this still be rolled back" — the real test, see the class docblock —
     * answers yes for all of them. Nothing here queues mail and nothing here has already happened
     * irreversibly.
     *
     * WHAT THE ALLOW-LISTS DELIBERATELY CANNOT CARRY. The same nine names absent from the four
     * connection operations are absent from these three — `credential`, `provider_credential`,
     * `api_key`, `secret`, `password`, `last_four`, `masked_key`, `credential_ciphertext`,
     * `data_key_ciphertext` — so no call site can put one in a row even by passing it under that
     * key. That is belt-and-braces here rather than the mechanism: `ProviderModelEntry` has no
     * credential to offer at all, and ProviderModelService builds every detail from the persisted
     * row.
     *
     * `display_name` is TENANT-CONTROLLED FREE TEXT and so is `model` — an operator types the
     * vendor's identifier by hand — so both are bounded by MAX_VALUE_LENGTH and pass the shape
     * backstop like any other echoed string.
     *
     * `context_window` AND `max_output_tokens` ARE ALLOW-LISTED, AND FOR A WHILE THEY WERE NOT.
     * AuditLoggerTest carries a standing guard that no ECHOED field may name a bearer capability,
     * and its first form was `str_contains($field, 'token')` — under which `max_output_tokens` is
     * a false positive, being an integer limit copied off a vendor's documentation page that
     * authorizes nothing and identifies nobody. Both fields were dropped rather than weaken a
     * credential guard to fit a naming coincidence. That was the right call at the time and it
     * left a real gap: the trail could not say who changed a model's limits, on a surface where
     * `max_output_tokens` decides how much a bot may be billed for in one turn and
     * `context_window` decides how much retrieved evidence fits.
     *
     * The guard was NARROWED instead, and the two fields came back with it. `namesABearerCapability()`
     * in tests/Unit/AuditLoggerTest.php now reads the name segment by segment: SINGULAR `token`
     * anywhere is a capability — one capability is one token, so a credential field is never plural
     * — while a segment that is exactly `tokens` is admitted only alongside a magnitude word
     * (`max`, `total`, `used`, …). That is strictly stronger than the substring rule everywhere
     * except the cell it was widened for: it refuses `apitoken` and `access_tokens`, which the
     * substring rule caught only by accident of spelling, and admits `max_output_tokens`, which it
     * could not tell apart from them at all.
     *
     * BOTH VALUES ARE INTEGERS AND TAKE THE `is_int()` FAST PATH in sanitize(), so neither is
     * length-bounded, neither is trimmed, and neither can trip the shape backstop — a
     * `max_output_tokens` of `0` is written as `0` rather than skipped the way an empty string or
     * a null price is.
     */
    public const PROVIDER_MODEL_CREATED = 'provider.model.created';

    public const PROVIDER_MODEL_UPDATED = 'provider.model.updated';

    /**
     * A HARD delete, so this row is the only surviving description of the catalog entry.
     *
     * That is what makes `connection_id`, `model` and `display_name` load-bearing here rather than
     * decorative: `subject_id` points at a ULID no table resolves any more, and without the echoed
     * fields the trail says a model was removed without being able to say which, from whose
     * catalog.
     */
    public const PROVIDER_MODEL_DELETED = 'provider.model.deleted';

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
        self::PROVIDER_CONNECTION_CREATED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                // The vendor, the operator's own name for the connection, and the lifecycle state
                // it was stored in. `label` is TENANT-CONTROLLED FREE TEXT — the only hostile
                // input in these four allow-lists — so it is bounded by MAX_VALUE_LENGTH and
                // passes the shape backstop like any other echoed string.
                'provider' => self::ECHOED,
                'label' => self::ECHOED,
                'status' => self::ECHOED,
            ],
        ],
        self::PROVIDER_CONNECTION_UPDATED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            // The values AFTER the edit. `provider` is not editable and is carried anyway, because
            // a row that cannot say which vendor was touched is unreadable next to a `deleted` row
            // for the same subject.
            'details' => [
                'provider' => self::ECHOED,
                'label' => self::ECHOED,
                'status' => self::ECHOED,
            ],
        ],
        self::PROVIDER_CONNECTION_DELETED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'provider' => self::ECHOED,
                'label' => self::ECHOED,
                'status' => self::ECHOED,
            ],
        ],
        self::PROVIDER_CREDENTIAL_ROTATED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'provider' => self::ECHOED,
                'label' => self::ECHOED,
                'status' => self::ECHOED,
                // WHICH KEK WRAPPED THE NEW DATA KEY, and WHICH GENERATION of this credential the
                // rotation produced. Two different numbers and neither is the other:
                // `key_version` moves when the platform rotates its key-encrypting key,
                // `credential_version` moves when this tenant replaces this provider key. Both
                // are integers with no derivation back to any secret, and together they are what
                // lets an investigation answer "which key was live on that date" — the only
                // question this row is ever asked. See the four-operation docblock above for why
                // there is no `key_fingerprint` beside them.
                'key_version' => self::ECHOED,
                'credential_version' => self::ECHOED,
            ],
        ],
        self::PROVIDER_CREDENTIAL_ROTATION_FAILED => [
            // THE ONLY FAILURE OUTCOME ON THIS SURFACE. `outcome` is derived from the operation, so
            // no row can claim this name with `outcome = success`.
            'outcome' => self::OUTCOME_FAILURE,
            // LOG, not ABORT, and it is the "can this still be rolled back" test answering NO for
            // the usual reason inverted: there is no state change to undo. The 422 is decided by
            // the time this runs, the connection is untouched, and aborting would turn a wrong
            // password into a 500. See the constant's docblock.
            'on_failure' => self::ON_FAILURE_LOG,
            // THE SAME THREE FIELDS THE SUCCESS ROW CARRIES, so a reader can put a failed attempt
            // and the rotation that followed it in one query. `key_version` and
            // `credential_version` are absent because nothing was rotated — writing the CURRENT
            // generation on a failed attempt would read as though a rotation had produced it.
            'details' => [
                'provider' => self::ECHOED,
                'label' => self::ECHOED,
                'status' => self::ECHOED,
            ],
        ],

        // ── THE THREE PROVIDER-MODEL OPERATIONS ────────────────────────────────────────────────
        //
        // ONE ALLOW-LIST, REPEATED THREE TIMES RATHER THAN SHARED THROUGH A CONSTANT. The four
        // connection operations do the same, and the reason is that the lists must be able to
        // DIVERGE: a shared constant makes "add a field to the created row" silently add it to the
        // deleted row too, and the whole design of this table is that each operation decides for
        // itself what it may record.
        //
        // The three lists are IDENTICAL TODAY, deliberately. A hard delete leaves this row as the
        // only description of the entry, so it must carry everything that made the row readable —
        // and a `created`/`updated` pair that carried LESS than the `deleted` row would make the
        // three unreadable side by side when the question is "what changed before it was removed".
        self::PROVIDER_MODEL_CREATED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                // The PARENT connection, on every row. `subject_id` is the catalog row's own ULID
                // and after a hard delete it resolves to nothing, so without this the trail cannot
                // say WHICH credential's catalog was changed.
                'connection_id' => self::ECHOED,
                // The vendor's official identifier, typed by an operator — tenant-controlled free
                // text, bounded like any other echoed string.
                'model' => self::ECHOED,
                'display_name' => self::ECHOED,
                // THE FLAG LIST, JOINED INTO A STRING BY THE CALLER. It has to be a scalar: an
                // array in `details` is dropped outright by sanitize(), because a structure here
                // is how `$request->all()` gets in one nesting level down and is also what would
                // stop `details` json-encoding as an OBJECT, which the table CHECKs. This is the
                // security-relevant field of the three operations — an `embedding` flag decides
                // which credential embeds the corpus — so losing it silently would be the worst
                // of both.
                'capabilities' => self::ECHOED,
                // Whether the row is offerable at all. A row disabled without a trace is an
                // organization whose ingestion silently stopped with nothing recording who
                // stopped it — the same argument `status` carries on the connection operations.
                'enabled' => self::ECHOED,
                // THE TWO LIMITS. Not credentials and not a naming coincidence any more — see the
                // PROVIDER_MODEL_CREATED docblock for why they were absent and what changed.
                // `max_output_tokens` caps what one turn may bill and `context_window` caps how
                // much retrieved evidence fits, so "who moved this, and when" is a question the
                // trail has to be able to answer. Both are integers and take sanitize()'s
                // `is_int()` path unaltered, so a limit of 0 records as 0.
                'context_window' => self::ECHOED,
                'max_output_tokens' => self::ECHOED,
                // PRICING IS NOT A SECRET AND IS NOT A CREDENTIAL. It is a list price the operator
                // copied from a public vendor page, it authorizes nothing, and "who changed the
                // number this month's estimate was computed from" is a question a finance reader
                // asks of exactly this table. The values arrive as decimal STRINGS (the
                // `decimal:6` cast), and a null is skipped by sanitize() without being reported —
                // so an unpriced row simply omits all three rather than writing nulls or a
                // warning per request.
                'input_price_per_million' => self::ECHOED,
                'output_price_per_million' => self::ECHOED,
                'price_currency' => self::ECHOED,
            ],
        ],
        self::PROVIDER_MODEL_UPDATED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            // The values AFTER the replacement. `model` is not editable and is carried anyway,
            // because a row that cannot say which model was touched is unreadable next to a
            // `deleted` row for the same subject.
            'details' => [
                'connection_id' => self::ECHOED,
                'model' => self::ECHOED,
                'display_name' => self::ECHOED,
                'capabilities' => self::ECHOED,
                'enabled' => self::ECHOED,
                'context_window' => self::ECHOED,
                'max_output_tokens' => self::ECHOED,
                'input_price_per_million' => self::ECHOED,
                'output_price_per_million' => self::ECHOED,
                'price_currency' => self::ECHOED,
            ],
        ],
        self::PROVIDER_MODEL_DELETED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'connection_id' => self::ECHOED,
                'model' => self::ECHOED,
                'display_name' => self::ECHOED,
                'capabilities' => self::ECHOED,
                'enabled' => self::ECHOED,
                'context_window' => self::ECHOED,
                'max_output_tokens' => self::ECHOED,
                'input_price_per_million' => self::ECHOED,
                'output_price_per_million' => self::ECHOED,
                'price_currency' => self::ECHOED,
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
                // AN EMPTY VALUE IS SKIPPED — BUT NOT ALWAYS SILENTLY, AND THE SPLIT IS THE POINT.
                //
                // The silent case is load-bearing and relied on by name: `capabilities` on the
                // three provider-model operations is `implode(',', $flags)`, so a row that claims
                // nothing legitimately produces `''` and simply omits the key, exactly as a null
                // price does (ProviderModelService::record()'s docblock states that reading in
                // both directions). Reporting it would put a WARNING on every unflagged write,
                // which is how a signal that means "your field did not ship" becomes noise.
                //
                // The LOUD case is the hole. A value the caller really did supply — `"   "`, a
                // string of control characters, anything `trim()` eats — reads as content at the
                // call site and stores as nothing, and an allow-listed key that vanishes without a
                // drop record is the same class of defect as an ECHOED field the backstop ate.
                // The discriminator is therefore whether the value was ALREADY empty on arrival,
                // not whether it is empty now.
                //
                // A LABEL BLANKED BY A TENANT IS THE `''` CASE AND SO IS STILL SILENT HERE, which
                // is deliberate rather than an oversight: that hole is closed where it belongs, by
                // `min:1` on UpdateProviderConnectionRequest::rules(), so the value cannot reach
                // this method at all. Making `''` loud instead would have traded one real defect
                // for a warning on every unflagged provider-model write.
                if ($value !== '') {
                    $dropped[] = $key;
                }

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
            $redacted = KbJsonFormatter::redactValue($normalized);

            if ($redacted !== $normalized) {
                $dropped[] = $key;

                // Never overwrite a fingerprint the map asked for itself. `AuditLoggerTest` asserts no
                // FINGERPRINTED field has a sibling already named `<key>_fingerprint`, but an ECHOED
                // field downgraded here has had no such check, and a silent overwrite would make two
                // different values indistinguishable in the one column meant to tell them apart.
                $fingerprintKey = $key.'_fingerprint';

                // THE ROW DEGRADES, IT DOES NOT LOSE ITS IDENTITY. A fingerprint alone answers
                // "was it THIS value" and nothing else, which is enough for an email and NOT
                // enough for a field whose CONTENT is the security fact — and three of the ECHOED
                // fields here are exactly that, all three tenant-controlled free text:
                //
                //   * `capabilities` is joined from `supported.*`. This class's own docblock calls
                //     it the security-relevant field of the three model operations, because an
                //     `embedding` flag decides which credential embeds the corpus. A tenant
                //     posting `supported: ["embedding", "sk-aaaaaaaaaaaa"]` used to make the whole
                //     field unreadable in an APPEND-ONLY table — a self-inflicted blind spot in
                //     the row that records what they just changed.
                //   * `label` is the identifying field of `provider.connection.deleted`, whose
                //     `subject_id` resolves to nothing after a hard delete. The same trick there
                //     left a row saying a connection was deleted without being able to say which.
                //   * `display_name` and `model` carry the same exposure on the catalog rows.
                //
                // So the SAFE RENDERING is kept beside the fingerprint: `redactValue()`'s output,
                // in which only the recognised credential substring has become `[REDACTED]` and
                // everything else survives — `embedding,[REDACTED]` rather than nothing at all.
                // It is not a prefix of the secret and not a truncation of it: the marker replaces
                // the whole matched run, which is what makes this a degradation rather than the
                // "no prefix beyond the documented last four" rule being bent (§18.2).
                //
                // A SEPARATE KEY RATHER THAN THE ORIGINAL ONE, deliberately. `$kept[$key]` must
                // stay absent so a reader — and every existing assertion — can still tell an
                // echoed value from a rewritten one; a redacted rendering silently occupying the
                // echoed key would make `details.email` mean two different things depending on a
                // regex nobody can see from the query.
                //
                // A RENDERING THAT IS NOTHING BUT THE MARKER IS NOT WRITTEN. When the whole value
                // was the credential — `sk-ant-…` and nothing else, which is what a mis-mapped
                // ECHO field usually looks like — `[REDACTED]` says exactly what the fingerprint's
                // presence already says, and an audit row is not the place to write the same fact
                // twice. The key appears only when something around the match survived.
                $redactedKey = $key.'_redacted';

                if ($redacted !== KbJsonFormatter::REDACTION_MARKER
                    && ! array_key_exists($redactedKey, $kept)) {
                    $kept[$redactedKey] = $redacted;
                }

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
