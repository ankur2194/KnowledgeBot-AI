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

    /**
     * ── THE TWO RERANK-DESIGNATION OPERATIONS ─────────────────────────────────────────────────
     *
     * `organizations.rerank_connection_id` / `rerank_model` decide which of an organization's
     * credentials sees its END USERS' QUESTIONS and its retrieved chunk text at rerank time, and
     * which provider account is billed for it. Nothing else in the platform records a change to
     * that: `organizations` has no history table, `updated_at` moves for a rename too, and the
     * designation is not on any resource a reader can diff after the fact.
     *
     * ── WHY TWO OPERATIONS AND NOT ONE `changed` ──────────────────────────────────────────────
     *
     * The same reason `source.disabled` and `source.enabled` are two: the two acts have different
     * consequences and a reader filters for one of them. Clearing the designation turns stage 11
     * OFF for the whole organization — answers stop being reranked and are served from fused order
     * with NO error, no metric jump on any single request, and nothing on the response to say so.
     * That is the single most invisible configuration change on this surface, and "did anybody turn
     * reranking off, and when" has to be a query rather than a scan of every `changed` row's
     * details.
     *
     * ── WHY BOTH ARE ON_FAILURE_ABORT ─────────────────────────────────────────────────────────
     *
     * The real test is "can this still be rolled back", and it answers yes for both: each is
     * written inside EloquentOrganizationRepository::designateRerankConnection()'s transaction,
     * before the COMMIT. Nothing has happened irreversibly by the time the row is written.
     *
     * ── `previous_*` IS ECHOED AND IT IS THE HALF THAT MAKES THE ROW USEFUL ───────────────────
     *
     * A designation row that records only the new value cannot answer "what did this replace",
     * and after the write the old pair exists nowhere — the column has been overwritten. Both the
     * new and the previous pair are read off the `organizations` row inside the transaction, never
     * from request input. A null previous half is SKIPPED by the sanitizer without being reported
     * (the same rule the three pricing keys rely on), so "there was no previous designation" is the
     * ABSENCE of the two keys rather than a pair of nulls.
     *
     * ── NOTHING HERE IS A CREDENTIAL, AND NOTHING HERE CAN BECOME ONE ─────────────────────────
     *
     * A connection ULID and a vendor model id, both read off `organizations`. The key itself is
     * envelope-encrypted on `provider_connections`, this path never reads that table, and there is
     * no field in either allow-list a caller could route a secret through — the standing sweep in
     * AuditLoggerTest asserts that over every operation rather than over this comment.
     *
     * ── THE GAP THIS PAIR MADE VISIBLE IS NOW CLOSED, ONE BLOCK DOWN ─────────────────────────
     *
     * This paragraph used to end "THE EMBEDDING DESIGNATION IS AUDITED BY NOTHING", recorded as a
     * gap rather than fixed because closing it meant changing a repository method's signature and
     * every caller. It IS closed: `EMBEDDING_DESIGNATION_SET` / `_CLEARED` are the next two
     * constants and they mirror this pair exactly — same two-operation split, same ABORT policy,
     * same `previous_*` echo, same allow-list shape.
     *
     * The ordering is worth one sentence, because it is backwards from how it should have gone: the
     * embedding designation is STRICTLY MORE CONSEQUENTIAL than this one — re-designating it changes
     * `(provider, model)`, and that pair IS the vector space (ADR-031), so it renames the Qdrant
     * collection and strands every indexed chunk until a re-index at a provider's per-token price.
     * The less consequential surface got its audit first because it was the one being built.
     */
    public const RERANK_DESIGNATION_SET = 'organization.rerank_designation.set';

    public const RERANK_DESIGNATION_CLEARED = 'organization.rerank_designation.cleared';

    /**
     * ── THE TWO EMBEDDING-DESIGNATION OPERATIONS ──────────────────────────────────────────────
     *
     * They mirror the rerank pair one block up in every structural detail, and they are the MORE
     * consequential of the two. `organizations.embedding_connection_id` / `embedding_model` decide
     * which credential pays for every embedding call AND WHICH VECTOR SPACE EVERY FUTURE CORPUS IS
     * INDEXED UNDER: that `(provider, model)` pair IS the vector space (ADR-031), it is part of the
     * Qdrant collection name, and moving it strands every chunk already indexed until somebody
     * re-embeds the whole corpus at a provider's per-token price.
     *
     * Nothing else records that. `organizations` has no history table, `updated_at` moves for a
     * rename too, and the designation is not on any resource a reader can diff after the fact. So
     * before these two existed, "who moved the vector space, and from what" had no answer anywhere.
     *
     * ── WHY TWO OPERATIONS AND NOT ONE `changed` ─────────────────────────────────────────────
     *
     * The same reason `source.disabled` and `source.enabled` are two, and it bites harder here.
     * CLEARING the designation does not turn embedding off — it hands the choice back to the
     * resolution rule in `services/ai-service/app/providers/embedding_selection.py`, which may
     * resolve to a DIFFERENT connection, or may refuse outright and BLOCK INGESTION for the whole
     * organization (ADR-031: two eligible connections that disagree on `(provider, model)` are a
     * refusal, not a tiebreak). "Did anybody clear the embedding designation, and when" is therefore
     * the first question asked when uploads start failing, and it has to be a query rather than a
     * scan of every `changed` row's details.
     *
     * NOTE THE ASYMMETRY WITH RERANK, because it is the one thing about this pair that is NOT a
     * mirror: a null rerank designation means "do not rerank", a supported mode. A null embedding
     * designation means "let the rule pick", and the rule can fail.
     *
     * ── WHY BOTH ARE ON_FAILURE_ABORT ────────────────────────────────────────────────────────
     *
     * The real test is "can this still be rolled back", and it answers yes for both: each is written
     * inside `EloquentOrganizationRepository::designateEmbeddingConnection()`'s transaction, before
     * the COMMIT. Nothing has happened irreversibly by the time the row is written.
     *
     * ── `previous_*` IS ECHOED AND IT IS THE HALF THAT MAKES THE ROW USEFUL ──────────────────
     *
     * A designation row that records only the new value cannot answer "what did this replace", and
     * after the write the old pair exists nowhere. It is also the only thing that says WHICH VECTOR
     * SPACE THE EXISTING CORPUS IS IN — the answer to "what do I have to re-index back to" — which
     * on this pair is a decision with a price attached.
     *
     * Both pairs are read off the LOCKED `organizations` row inside the transaction, never from
     * request input.
     *
     * ── NOTHING HERE IS A CREDENTIAL, AND NOTHING HERE CAN BECOME ONE ────────────────────────
     *
     * A connection ULID and a vendor model id, both read off `organizations`. The key itself is
     * envelope-encrypted on `provider_connections`, this path never reads that table, and there is
     * no field in either allow-list a caller could route a secret through — the standing sweep in
     * AuditLoggerTest asserts that over every operation rather than over this comment.
     */
    public const EMBEDDING_DESIGNATION_SET = 'organization.embedding_designation.set';

    public const EMBEDDING_DESIGNATION_CLEARED = 'organization.embedding_designation.cleared';

    /**
     * ── THE QUOTA-LIMIT OPERATION ─────────────────────────────────────────────────────────────
     *
     * `organizations.storage_bytes_quota`, `bots_quota`, `users_quota`, `monthly_tokens_quota` — the
     * four ceilings `QuotaGate` refuses against. Changing one changes what an organization is
     * ALLOWED TO SPEND, and §6.1 assigns that control to the PLATFORM owner while §6.2 gives the
     * organization owner "manage organization settings"; the two sentences overlap on exactly these
     * four columns and the specification never says which wins. `Permission::QuotasManage` records
     * the contradiction. THIS ROW IS WHAT MAKES THE RESOLUTION AUDITABLE while it is unresolved.
     *
     * ── ONE OPERATION AND NOT ONE PER METRIC ─────────────────────────────────────────────────
     *
     * Unlike the two designation pairs above, this is a single `updated` rather than a split, and
     * the difference is that a designation has two acts with OPPOSITE consequences (setting one
     * chooses a vendor; clearing one hands the choice to a rule that can refuse) while a quota write
     * has one act applied to four numbers. Splitting it four ways would make a single form
     * submission four rows that a reader has to reassemble to see what changed.
     *
     * ── EVERY VALUE IS ECHOED, BEFORE AND AFTER, AND `raised` IS THE FIELD THAT MATTERS ──────
     *
     * The `previous_*` half is what makes "who removed the ceiling" answerable — after the write the
     * old numbers exist nowhere. `raised` is a derived boolean recorded because it is the security
     * question: `QuotaLimitService` permits an organization owner to LOWER a limit and requires
     * `users.is_platform_owner` to RAISE or REMOVE one, so a `raised: true` row should always have a
     * platform owner as its actor and a row that does not is the thing an audit of this surface is
     * looking for.
     *
     * A null in any of the eight numeric keys means UNLIMITED, and nulls are skipped by the
     * sanitizer without being reported — so "this metric is unmetered" is the ABSENCE of the key,
     * exactly as it is for `previous_connection_id` on the designation pairs.
     *
     * ── ON_FAILURE_ABORT ─────────────────────────────────────────────────────────────────────
     *
     * Written inside `EloquentOrganizationRepository::setQuotaLimits()`'s transaction, before the
     * COMMIT, so the rollback test passes: a failed audit write takes the quota change with it. A
     * ceiling that moved with nothing recording who moved it is the one state this table exists to
     * make impossible.
     */
    public const QUOTA_LIMITS_UPDATED = 'organization.quota_limits.updated';

    /**
     * ── THE THREE BOT OPERATIONS ───────────────────────────────────────────────────────────────
     *
     * A BOT IS NOT A CREDENTIAL EITHER, AND THESE ARE AUDITED FOR THE SAME KIND OF REASON THE
     * PROVIDER-MODEL ONES ARE: what the row DECIDES rather than what it holds.
     *
     *   * `access_mode` moving from `private` to `public` makes the bot answerable by an anonymous
     *     visitor with no account and no invitation, and `status` moving to `published` exposes it
     *     on every channel the access mode and the origin allow-list permit. Neither transition
     *     leaves a trace anywhere else in the system.
     *   * `provider_connection_id` and `provider_model_id` decide which credential is BILLED for
     *     every answer and which vendor sees the tenant's questions.
     *   * the four retrieval depths and the evidence pair decide what the bot retrieves and when it
     *     refuses, and a refusal rate that moved without an explanation is the hardest kind of
     *     regression to attribute — `retrieval_configuration_version` is recorded beside them so
     *     the trail can answer "which configuration was version 7" after the row has changed again.
     *   * a DELETE is a hard delete that takes the origin allow-list, the starter questions and the
     *     fallback chain with it, and leaves this row as the only surviving description.
     *
     * "Who made this bot answerable by the internet, and when" is exactly the question an incident
     * asks, and without these rows the trail answers it with the `created` row from months earlier.
     *
     * ALL THREE ARE `ON_FAILURE_ABORT`, with no judgement call to make: every one of them is still
     * inside `EloquentBotRepository`'s transaction when the row is written, so "can this still be
     * rolled back" — the real test, see the class docblock — answers yes for all of them. Nothing
     * here queues mail and nothing here has already happened irreversibly.
     *
     * ── WHAT THE ALLOW-LISTS DELIBERATELY CANNOT CARRY, AND THE FIRST ITEM IS THE POINT ────────
     *
     * `system_instruction` AND `answer_style_instruction` ARE ABSENT. They are the bot's
     * operator-authored PROMPT — unbounded tenant text, and the exact string a prompt-injection
     * review is about — and echoing one wholesale into an append-only, long-lived, exportable table
     * is the shape of the defect this whole class exists to prevent, with the additional property
     * that MAX_VALUE_LENGTH would truncate it into something that reads as the whole instruction
     * and is not. "Who changed the prompt" is answerable from `updated_at` and the actor; "to what"
     * is a question for a configuration history feature, not for the audit table.
     *
     * `welcome_message`, `placeholder_text`, `description` and `consent_text` are absent for the
     * weaker version of the same reason: prose that decides nothing, in a table an investigator has
     * to be able to read. `collect_end_user_data` IS carried, because the FLAG is the compliance
     * fact and `bots_consent_text_present_when_collecting` already guarantees a disclosure exists
     * whenever it is true.
     *
     * `theme` is absent because it is an ARRAY, which `sanitize()` drops outright — a structure in
     * `details` is how `$request->all()` gets in one nesting level down, and it is also what would
     * stop `details` json-encoding as an OBJECT, which the table CHECKs.
     *
     * `public_bot_id` IS ABSENT DELIBERATELY, and it is the one a reviewer will ask about. It is
     * not a secret — it is printed into the customer's own page source — but it is a token-shaped
     * string whose only use is addressing a bot anonymously, and the trail has no question it
     * answers that `name` and `slug` do not. An append-only table is the wrong place to accumulate
     * identifiers of that shape.
     *
     * The same nine names absent from the connection and model operations are absent here too —
     * `credential`, `provider_credential`, `api_key`, `secret`, `password`, `last_four`,
     * `masked_key`, `credential_ciphertext`, `data_key_ciphertext` — so no call site can put one in
     * a row even by passing it under that key. Belt-and-braces rather than the mechanism: a `Bot`
     * has no credential to offer at all, only a connection ULID.
     *
     * `name` and `slug` are TENANT-CONTROLLED FREE TEXT, bounded by MAX_VALUE_LENGTH and passing
     * the shape backstop like any other echoed string. `evidence_threshold` is the first FLOAT in
     * this table and takes `sanitize()`'s `is_float()` path, which refuses NAN and INF because
     * neither is representable in JSON and either would fail the insert and take the whole audit
     * row with it.
     */
    public const BOT_CREATED = 'bot.created';

    /**
     * ── WHY THIS ROW CARRIES NO `previous_status` OR `previous_access_mode`, WHICH IS A CALL ───
     *
     * `bot.domain.status_changed` one block down DOES carry `previous_status`, and the question
     * this row cannot answer on its own is the stronger version of the one that field was added
     * for: "who made this bot answerable by the internet" is `access_mode` going `private` →
     * `public`, and reading this row alone you see only where it ended up. The asymmetry is
     * deliberate and it is worth stating rather than leaving to be rediscovered.
     *
     * IT IS DERIVABLE, at the cost of an ordered read: every `bot.*` row for a subject carries
     * `status` and `access_mode`, so the previous values are the previous row's, within one
     * organization's own trail. That is the fallback, and it is why this is a readability decision
     * rather than a coverage one — nothing is unrecorded.
     *
     * WHAT MAKES THE DOMAIN ROW DIFFERENT IS THAT IT IS A ONE-COLUMN TRANSITION. `status` is the
     * only thing that row can be about, so "previous" is unambiguous on every instance of it. This
     * row describes a PATCH over twenty-four columns, the overwhelming majority of which name
     * neither `status` nor `access_mode`. A `previous_*` pair on every one of those is a "previous"
     * for a field that did not move — a second spelling of the value beside it, which is exactly
     * the reading the map below refuses on `bot.domain.deleted`. Emitting the pair only when the
     * edit names the column is the other option and it is worse: the detail key set would then
     * vary by request body, so a reader could not tell "this edit did not touch status" from "this
     * row predates the field".
     *
     * AND THE CHEAP SPELLING IS THE WRONG ONE. `BotService::update()` has the ROUTE-BOUND `$bot` in
     * scope when it builds the audit closure, so `$bot->status` looks like a free previous value —
     * but it was read OUTSIDE the row lock, before `EloquentBotRepository::update()` opened its
     * transaction. That is precisely the property `bot.domain.status_changed`'s `previous_status`
     * exists to guarantee ("read UNDER THE SAME ROW LOCK that writes the new value, so it can never
     * name a status the row did not hold"), and two concurrent PATCHes would produce a row naming a
     * state the bot never held. The correct spelling changes `BotRepositoryInterface::update()`'s
     * `Closure(Bot): void` callback to carry the pre-image, which is a contract shared with the
     * transition path — a change worth making for a reason, not for a convenience.
     *
     * WHAT WOULD FLIP THIS: `access_mode` gaining real reach. Today no runtime surface consults it,
     * so "who made this bot public" is a question about a column nothing reads yet. When the widget
     * and hosted-chat runtimes ship, add `previous_access_mode` (and `previous_status` with it),
     * read under the lock through a widened callback — not from `$bot` in the service.
     */
    public const BOT_UPDATED = 'bot.updated';

    /**
     * A HARD delete, so this row is the only surviving description of the bot.
     *
     * That is what makes `name` and `slug` load-bearing here rather than decorative: `subject_id`
     * points at a ULID no table resolves any more, and without the echoed fields the trail says a
     * bot was removed without being able to say which.
     */
    public const BOT_DELETED = 'bot.deleted';

    /**
     * A DELETE that was REFUSED because the bot has held at least one conversation.
     *
     * ── IT IS THE ONLY OPERATION D1 ADDS, AND IT RECORDS A NON-EVENT ON PURPOSE ────────────────
     *
     * Every other row in this map describes something that happened. This one describes something
     * that did not, and it earns its place on the same ground §18.11 makes for the destructive
     * operations: A TRANSCRIPT IS AN AUDIT RECORD, and an attempt to destroy an organization's
     * entire conversation history is exactly the event an investigation opens with. Nothing else
     * records it — the request 409s, the response body is not retained, and `updated_at` does not
     * move on a row nothing wrote.
     *
     * IT IS `OUTCOME_FAILURE`, WHICH IS WHAT MAKES IT DISTINGUISHABLE FROM `bot.deleted`. A reader
     * filtering this table for successful destruction sees only the real ones; a reader asking "did
     * anyone TRY" has a value to ask for. Folding the two into one operation with an outcome column
     * the caller chooses is the shape this map exists to refuse — `outcome` is a property of the
     * OPERATION here precisely so a call site cannot pick it.
     *
     * IT IS `ON_FAILURE_LOG`, AND IT IS THE SECOND ROW IN THIS FILE TO BE SO FOR THE SAME REASON AS
     * `source.upload.rejected`: the class docblock's test is "can this still be rolled back", and
     * the answer is no because there is nothing to roll back. The refusal is already decided, no row
     * was written, and aborting would turn a legitimate 409 into a 500 — a lie to the caller AND
     * still no audit row.
     *
     * ── `conversation_count` IS THE FIELD THAT MAKES THE ROW USEFUL ───────────────────────────
     *
     * It is the tripwire the `bot.deleted` child counts are: a scalar that tells a reader HOW MUCH
     * was at stake. A refusal protecting three test conversations and one protecting four hundred
     * thousand are different events, and only the number distinguishes them.
     *
     * NO CONVERSATION IDENTIFIERS, NO PARTICIPANT, NO MESSAGE TEXT, EVER. `audit_logs` is
     * append-only, long-lived and exportable; a conversation is a customer's end users talking to
     * us. The knowledge-source block above states the same rule for document text, and the reason is
     * identical.
     */
    public const BOT_DELETE_REFUSED = 'bot.delete.refused';

    /**
     * ── THE D5 PLAYGROUND CREDENTIAL, AND WHY IT IS AUDITED AT ALL ─────────────────────────────
     *
     * This one was DECIDED rather than defaulted, because both answers are defensible and the wrong
     * one is invisible either way. §18.11's list is "credential changes … and every destructive
     * operation", and a playground mint is neither a change to a stored credential nor destructive:
     * the turn it enables already writes a `conversations` row, a `messages` row, a `provider_calls`
     * row and a `usage_events` row, which is a far better record of what was spent than an audit
     * line could be.
     *
     * IT IS AUDITED ANYWAY, FOR THE ONE THING NONE OF THOSE ROWS RECORDS: that a bearer carrying
     * `actor_type: user` WITH DIAGNOSTICS ENABLED was issued, to whom, for which bot, and for how
     * long. That credential is the only one in the platform a `retrieval.trace` frame can be
     * forwarded to — candidate chunk ids, per-branch scores, and the resolved filter object naming
     * the organization and every allowed version id. "Who was issued a diagnostics credential for
     * this bot, and when" is a question an incident asks, and without this row the honest answer is
     * a `conversations` row on a channel that does not say a token was minted at all — one mint can
     * produce many conversations, or none.
     *
     * `auth.login.succeeded` IS THE PRECEDENT, not `provider.connection.*`. Both are the ISSUANCE of
     * a session credential rather than a change to a stored secret, and both are therefore
     * `ON_FAILURE_LOG` on the class docblock's real test — "can this still be rolled back". It
     * cannot: the record is already in Valkey when this row is written and the token is already on
     * its way to the caller. Aborting would turn a successful mint into a 500 while leaving a LIVE
     * BEARER behind, which is strictly worse than the missing row it was trying to prevent.
     *
     * ── THE FOUR FIELDS, AND THE ONE THAT IS CONSPICUOUSLY ABSENT ──────────────────────────────
     *
     * NO `token`, IN ANY FORM — not echoed, not fingerprinted, not a prefix. `ChatSessionResource`
     * states the rule this row obeys: the plaintext exists in the response body and in the caller's
     * memory and NOWHERE else. `AuditLoggerTest`'s standing guard would refuse an ECHOED field named
     * for a bearer capability; there is simply no field here for it to catch.
     *
     * `session_id` IS ECHOED AND IT IS NOT THE TOKEN. It is `substr(sha256(secret), 0, 32)` — a
     * one-way function of the bearer, derivable FROM it and useless without it, which is exactly why
     * `WidgetSession` already calls it safe as a rate-limit subject and in a log line. It is what
     * ties this row to the `rl:` buckets and the log lines that name the same session, and it is the
     * only identifier that can do so.
     *
     * `bot_id` is load-bearing for the reason it is on every `bot.*` child row: `bot.deleted` is a
     * HARD delete, so `subject_id` resolves to nothing afterwards. `diagnostics` is recorded even
     * though it is always `true` today, for the reason `bot.domain.created` records an always-
     * `pending` status: if a second playground kind is ever introduced, the rows for the two read
     * side by side without a reader having to remember which era they are in.
     */
    public const BOT_PLAYGROUND_SESSION_MINTED = 'bot.playground_session.minted';

    /**
     * ── THE THREE ORIGIN-ALLOW-LIST OPERATIONS, AND WHY THEY EXIST AT ALL ──────────────────────
     *
     * FINDING L2 (`docs/22` § *The security read of the bots surface*): deleting a bot destroys its
     * widget origin allow-list with NO RECORD OF WHAT IT PERMITTED — which contradicts the reason
     * `bot_domains` gives for its own `ON DELETE RESTRICT`, namely that a security review may later
     * need to reconstruct it. The finding was latent only because no route created a domain; the
     * endpoints that made it live are the ones these operations audit.
     *
     * The closure has two halves and THIS IS THE LOAD-BEARING ONE. The `bot.*` rows gained scalar
     * summary fields (`domain_count`, `active_domain_count`, `active_origins`), and those are a
     * TRIPWIRE — a reader who lands on `bot.deleted` and sees `domain_count: 4` knows to go looking.
     * What they go looking FOR is these rows: one per origin, per action, each naming the actor, the
     * origin verbatim and the time. They are append-only and they outlive the bot, so they are what
     * actually answers "what could embed this, and who allowed it".
     *
     * ── A ROW HERE IS A GRANT, WHICH IS WHY `origin` IS ECHOED AND NOT SUMMARISED ──────────────
     *
     * It is tenant-controlled free text, bounded by :self::MAX_VALUE_LENGTH and passing the shape
     * backstop like any other echoed string — and it is the SECURITY FACT itself rather than a
     * description of one. A fingerprint would answer "was it THIS origin" and nothing else, which is
     * the wrong question: an investigation asks WHICH origins a bot permitted, and it asks it
     * without a candidate list to test against.
     *
     * The degradation that follows is the designed one and is named rather than fixed, exactly as
     * finding L4 names it for a bot's slug: a LEGAL origin whose host matches the vendor-key pattern
     * — `https://sk-abcdefghijkl.example` is a legal host, and `sk-` plus twelve characters is what
     * `KbJsonFormatter::VENDOR_KEYS` catches — is FINGERPRINTED rather than echoed. That is tenant
     * self-harm along a path the backstop exists for, and exempting this field is how the backstop
     * stops being one.
     *
     * ── `status` IS ON ALL THREE, AND `previous_status` ONLY ON THE TRANSITION ─────────────────
     *
     * `pending` grants nothing and `active` grants everything this list can grant, so a row that
     * recorded an origin without saying which of those it was would not answer the question it
     * exists for. `previous_status` makes the transition row readable on its own — "who turned this
     * origin on, and what was it before" — and it is read UNDER THE SAME ROW LOCK that writes the
     * new value, so it can never name a status the row did not hold.
     *
     * ── ALL THREE ARE ON_FAILURE_ABORT ────────────────────────────────────────────────────────
     *
     * Each is written inside `EloquentBotDomainRepository`'s transaction, so "can this still be
     * rolled back" — the real test, see the class docblock — answers yes. A LOG policy on `created`
     * would permit a grant to exist with no record of who made it, which is the whole finding.
     */
    public const BOT_DOMAIN_CREATED = 'bot.domain.created';

    public const BOT_DOMAIN_STATUS_CHANGED = 'bot.domain.status_changed';

    /**
     * A HARD delete, so this row is the only surviving description of the grant.
     *
     * `subject_id` points at a ULID no table resolves any more, which is what makes `origin` and
     * `status` load-bearing here rather than decorative — without them the trail says an origin was
     * removed without being able to say which, from whose allow-list, or whether it had been live.
     */
    public const BOT_DOMAIN_DELETED = 'bot.domain.deleted';

    /**
     * ── THE THREE STARTER-QUESTION OPERATIONS, AND THE ONE FIELD THEY DELIBERATELY OMIT ────────
     *
     * §18.11 requires BOT CONFIG CHANGES audited, and a starter question is bot configuration: it
     * is what a first-time visitor is invited to ask, rendered as a suggestion chip on hosted chat,
     * inside the widget and in the mobile app. Without these rows, editing the suggestions would be
     * the one bot configuration change that left no trace anywhere — `bots` is untouched by it, so
     * not even `bot.updated` fires.
     *
     * THE QUESTION TEXT IS NOT RECORDED, AND THAT IS THE DECISION THIS BLOCK EXISTS TO STATE. It is
     * unbounded tenant PROSE that decides nothing: it authorizes nobody, it bills nothing, and it
     * changes no retrieval behaviour. `welcome_message`, `placeholder_text`, `description` and
     * `consent_text` are absent from the `bot.*` allow-lists on exactly that ground — an
     * append-only table an investigator has to be able to READ is the wrong place to accumulate
     * copy — and there is no reason a chip label should be treated differently from a welcome
     * message. What the rows record is that somebody changed the suggestions, which one, in which
     * direction, and how many there are afterwards.
     *
     * THE ASYMMETRY WITH `bot.domain.*` ONE BLOCK UP IS THE POINT, not an inconsistency. An origin
     * is a GRANT and its string IS the security fact; a starter question is text on a button.
     *
     * All three are ON_FAILURE_ABORT: each is written inside
     * `EloquentBotStarterQuestionRepository`'s transaction, so the change can still be rolled back
     * when the row cannot be written.
     */
    public const BOT_STARTER_QUESTION_CREATED = 'bot.starter_question.created';

    public const BOT_STARTER_QUESTION_UPDATED = 'bot.starter_question.updated';

    public const BOT_STARTER_QUESTION_DELETED = 'bot.starter_question.deleted';

    /**
     * ── THE TWELVE KNOWLEDGE-SOURCE OPERATIONS, REGISTERED IN ONE PASS ────────────────────────
     *
     * All twelve constants and all twelve rules land together, before the endpoints that call them
     * exist, because Phase C's later steps are forbidden from editing this file. That is a
     * sequencing decision with a real cost — a rule nothing calls is a rule nothing exercises — so
     * the standing guards at the bottom of tests/Unit/AuditLoggerTest.php are what hold them:
     * every constant is pinned BY NAME against `OPERATIONS`, every `on_failure` is pinned by name,
     * and every `details` key is checked against the credential and bearer-capability name rules.
     *
     * ── §18.11 REQUIRES SOURCE CHANGES AUDITED, AND THE SHAPE OF THE REQUIREMENT IS UNUSUAL ──
     *
     * A knowledge source carries no credential and grants no access on its own, so the argument for
     * auditing it is not the one the provider surface makes. It is this: THE CORPUS IS WHAT THE BOT
     * SAYS. An answer a customer disputes is explained by which documents were retrievable at the
     * time it was produced, and every one of the twelve rows below moves that set —
     *
     *   create / update / delete    what is in the corpus at all
     *   disable / enable            whether a source answers, immediately, with every vector retained
     *   reprocess                   which version answers, and at a provider's per-token price
     *   upload accepted / rejected  what bytes we accepted, from whom, and what we refused
     *   version activated / retired the pointer switch itself — the single act that decides what
     *                               every tenant's next query sees
     *   assignment created/deleted  WHICH BOT may answer from it, which is `bot_ids`, which is one
     *                               of the four mandatory Qdrant filter terms
     *
     * — and none of them leaves a trace anywhere else that outlives the row. `updated_at` says
     * something changed and cannot say what, and a deleted source's row is gone.
     *
     * ── WHAT THE ALLOW-LISTS DELIBERATELY CANNOT CARRY ───────────────────────────────────────
     *
     * NO EXTRACTED DOCUMENT TEXT, EVER. Not a chunk, not an element, not an excerpt, not a "first
     * 200 characters for context". `audit_logs` is append-only, long-lived and exportable, and
     * `chunks.text` is a customer's document — the single largest body of tenant prose in this
     * platform. `MAX_VALUE_LENGTH` would truncate it into something that reads as the whole passage
     * and is not, which is the failure mode that makes a leak look like a summary. There is no
     * `details` key on any of the twelve below that can hold it.
     *
     * `description` IS ABSENT for the weaker version of the same reason `bot.*` refuses
     * `welcome_message`: prose that decides nothing, in a table an investigator has to be able to
     * read. `tags` and `heading_path` are ARRAYS, which `sanitize()` drops outright — a structure
     * in `details` is how `$request->all()` gets in one nesting level down.
     *
     * ── WHAT THEY DO CARRY, AND WHY EACH ONE EARNS ITS PLACE ─────────────────────────────────
     *
     * `name` and `type` are the only surviving identification after a hard delete, exactly as
     * `bot.deleted`'s are. `origin_url` is the crawl target: the one tenant-supplied string on the
     * source that causes this platform to make an OUTBOUND REQUEST, so it is echoed for the same
     * reason `bot.domain.*` echoes an origin — it IS the security fact rather than a description of
     * one, and an investigation asks WHICH URLs we were told to fetch without a candidate list to
     * test against. `content_hash` is a digest and not a secret; it is what makes "we processed
     * exactly these bytes" checkable. `storage_key` is a generated path under this organization's
     * own prefix and names no object outside it.
     *
     * ── ALL BUT ONE ARE ON_FAILURE_ABORT ─────────────────────────────────────────────────────
     *
     * The real test is the class docblock's — "can this still be rolled back" — not "is this
     * interesting". Eleven of the twelve are written inside the transaction that performs the
     * change, so the answer is yes and a failed audit write must take the change with it. The
     * exception is `source.upload.rejected`, which is the mirror image: there is no state change to
     * undo, the refusal is already decided, and aborting would turn a rejected file into a 500 —
     * both a lie to the caller and still no audit row.
     */
    public const SOURCE_CREATED = 'source.created';

    public const SOURCE_UPDATED = 'source.updated';

    /**
     * A HARD delete of the source ROW, at the end of the verified two-phase removal.
     *
     * That is what makes `name`, `type` and `origin_url` load-bearing here rather than decorative:
     * `subject_id` points at a ULID no table resolves any more, and without the echoed fields the
     * trail says a source was removed without being able to say which — or, for a crawl, what we
     * had been fetching.
     *
     * `item_count` and `version_count` are the tripwire the `bot.*` rows' child summaries are:
     * scalars that tell a reader how much went with it, so a row showing 412 items sends them
     * looking rather than letting the delete read as a single-document cleanup.
     */
    public const SOURCE_DELETED = 'source.deleted';

    /**
     * ── DISABLE AND ENABLE ARE SEPARATE OPERATIONS, NOT ONE `status_changed` ─────────────────
     *
     * `bot.domain.status_changed` is one operation with a `previous_status`, and this pair is two
     * operations, which looks inconsistent until you ask what each is for. A domain's status has
     * three values and a promotion is the interesting one, so the transition IS the event. A
     * source's status has FIFTEEN, and disable/enable are the only two moves a human makes
     * directly — every other transition is the pipeline walking. Folding them into a generic
     * `source.status_changed` would put those two beside thirteen machine transitions and make
     * "who turned this source off" a query with a WHERE clause on a detail field.
     *
     * BOTH CARRY `previous_status`, read UNDER THE SAME ROW LOCK that writes the new value, so
     * neither can name a status the row did not hold. That is the property `bot.updated`
     * deliberately does NOT have — see its constant — and the difference is that this is a
     * ONE-COLUMN transition where "previous" is unambiguous on every instance.
     */
    public const SOURCE_DISABLED = 'source.disabled';

    public const SOURCE_ENABLED = 'source.enabled';

    /**
     * An administrator asked for the source to be processed again.
     *
     * `force_nonce` IS ECHOED AND IT IS NOT A CREDENTIAL. It is the reprocess request's own
     * identifier, and it is a component of `ingest_key` — the ONLY component that changes when
     * nothing else did, which is what makes an explicit reprocess reach a worker at all instead of
     * deduping against the completed run. Recording it is what lets a trail answer "did this
     * request actually cause a new version", by matching it against
     * `source_versions.ingest_key`'s inputs. It authorizes nothing and identifies nobody.
     */
    public const SOURCE_REPROCESS_REQUESTED = 'source.reprocess.requested';

    /**
     * ── THE UPLOAD PAIR, AND THE REJECTION IS THE ONE THAT MATTERS ───────────────────────────
     *
     * `kb-security-baseline`'s upload rules are a six-step gate — size, extension allow-list,
     * content-sniffed MIME, extension/MIME cross-check, OPC macro and embedded-object refusal, and
     * the content hash — and every one of them can refuse. WITHOUT `source.upload.rejected` THE
     * REFUSALS ARE INVISIBLE: a caller grinding at that gate with crafted files leaves no trace
     * anywhere, because nothing was written. That is the same gap `provider.connection.
     * credential_rotation_failed` exists to close on the credential surface, and it is the reason
     * this pair is two operations rather than one with an outcome field.
     *
     * `reason` IS A CLOSED TOKEN AND NOT A MESSAGE, and the set is closed by
     * `App\Enums\UploadRejectionReason` rather than by this allow-list — which
     * names the FIELD and has no way to constrain its members. It is the field an operator groups
     * by. An exception message would be unbounded, would vary by library version, and could echo the
     * parser's reading of a hostile file back into the audit table.
     *
     * SEVEN TOKENS, NOT SIX, AND THE SEVENTH IS RECORDED RATHER THAN SLIPPED IN. This docblock used
     * to name one per step of the six-step gate — `size`, `extension`, `mime_sniff`,
     * `mime_mismatch`, `macro_payload`, `duplicate` — and the intake adds `archive_bomb` for the
     * decompression caps (512 MiB declared, ratio 100, 2000 entries), which
     * `kb-security-baseline/references/file-upload-safety.md` requires and which are not one of the
     * six steps. Filing them under `macro_payload` would put a resource refusal and an
     * active-content refusal in one bucket and make the bucket unreadable: the two have different
     * attackers and different remedies, and "is somebody sending us zip bombs" is exactly the
     * question a `GROUP BY reason` is asked.
     *
     * `display_name` IS TENANT-CONTROLLED FREE TEXT AND IS ECHOED ANYWAY, deliberately: on a
     * rejection it is the only thing that identifies the attempt, `source_items`' own CHECK already
     * refuses a separator, a control character and the two directory-relative names, and
     * `MAX_VALUE_LENGTH` bounds it like any other echoed string. A filename crafted to trip the
     * shape backstop is degraded to a fingerprint, which is tenant self-harm along a path the
     * backstop exists for.
     */
    public const SOURCE_UPLOAD_ACCEPTED = 'source.upload.accepted';

    public const SOURCE_UPLOAD_REJECTED = 'source.upload.rejected';

    /**
     * ── THE POINTER SWITCH, WHICH IS THE MOST CONSEQUENTIAL WRITE IN THE INGESTION PATH ─────
     *
     * Activation is the single act that decides what every subsequent query against this item sees.
     * It is Laravel's and not the data plane's (ADR-012) precisely because the audit row, the policy
     * check and the retention clock are here — so an activation with no audit row would defeat the
     * main argument for the split.
     *
     * `previous_version_id` IS ON THE ACTIVATION ROW and is read inside the same transaction, under
     * the `lockForUpdate()` on the item that the switch already takes. It is what makes the row
     * readable on its own: "this version replaced that one, at this time, on this actor's request".
     * Without it a reader has to reconstruct the chain from `source.version.retired` rows and hope
     * none is missing.
     *
     * `chunk_count` is the VERIFIED total the data plane reported with `exact=True`, and it is
     * recorded because it is the number the whole verification gate turns on. A version activated
     * with a chunk count that later disagrees with the collection is the "bot only knows half the
     * document" failure, and this row is where the expected value is written down.
     *
     * `ingest_key` and `embedding_model_version` are echoed for replayability: together they say
     * which content, which four configurations and which vector space produced what went live.
     * Neither is a secret — the first is a sha256 hexdigest of public inputs, the second is a
     * provider/model/width/probe-digest string.
     */
    public const SOURCE_VERSION_ACTIVATED = 'source.version.activated';

    /**
     * The other half of the switch, as its own row.
     *
     * SEPARATE FROM THE ACTIVATION BECAUSE THE TWO CAN COME APART. A version is retired by the
     * publish transaction in the ordinary case, and by an archive or a delete in the cases that are
     * not ordinary — where nothing is activated in its place and the item stops answering. One
     * combined row could not express "retired, replaced by nothing", which is exactly the state an
     * incident is asking about.
     *
     * `superseded_by_version_id` is therefore NULLABLE in meaning: present when the retirement was
     * part of a publish, absent when the version was simply withdrawn. `sanitize()` skips a null
     * silently, so the key's absence is the distinction.
     */
    public const SOURCE_VERSION_RETIRED = 'source.version.retired';

    /**
     * ── THE TWO ASSIGNMENT OPERATIONS, AND THEY AUDIT A CROSS-TENANT BOUNDARY ────────────────
     *
     * `bot_source_assignments` is the one row in the schema that can span two organizations
     * (kb-tenancy-isolation NN2). The composite foreign keys are what make the illegal version
     * impossible; these rows are what record the legal version, which is a grant in exactly the
     * sense `bot.domain.created` is: after this row exists, a bot answers from documents it could
     * not reach before.
     *
     * `source_name` IS ECHOED ALONGSIDE `source_id`, AND THAT IS NOT REDUNDANCY. Both `bot.deleted`
     * and `source.deleted` are HARD deletes, so an assignment row that recorded only two ULIDs
     * would resolve to nothing on either end once either parent is gone — and "which documents was
     * this bot allowed to answer from" is precisely the question an incident asks after the fact.
     * `bot_id` is echoed for the same reason it is on every `bot.*` child row.
     *
     * BOTH ARE ON_FAILURE_ABORT. A LOG policy on `created` would permit a retrieval-scope grant to
     * exist with no record of who made it, which is the whole of finding L2 restated one entity
     * over — and with higher stakes, because this grant reaches documents rather than a page that
     * may embed a widget.
     */
    public const BOT_SOURCE_ASSIGNMENT_CREATED = 'bot.source_assignment.created';

    public const BOT_SOURCE_ASSIGNMENT_DELETED = 'bot.source_assignment.deleted';

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

        // ── THE TWO RERANK-DESIGNATION OPERATIONS ─────────────────────────────────────────────
        //
        // See the constants for why there are two rather than one `changed`, why both are ABORT,
        // and why `previous_*` is the half that makes the row readable. `subject_type`/`subject_id`
        // point at the Organization, which is the record the columns live on.
        self::RERANK_DESIGNATION_SET => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                // The pair as PERSISTED, read back off the locked `organizations` row rather than
                // taken from the request — which is what makes "no credential can appear here" a
                // property of the code rather than of the caller's discipline.
                'connection_id' => self::ECHOED,
                'model' => self::ECHOED,
                // What it replaced. Absent entirely when there was no previous designation: a null
                // is skipped by sanitize() without being reported, so the two keys' ABSENCE is the
                // statement, exactly as it is for the three pricing keys one block up.
                'previous_connection_id' => self::ECHOED,
                'previous_model' => self::ECHOED,
            ],
        ],
        self::RERANK_DESIGNATION_CLEARED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            // NO `connection_id`/`model` PAIR, because after a clear there is none — writing the
            // two keys as nulls would be indistinguishable from a `set` row whose sanitizer dropped
            // them. The whole content of this row is what stopped reranking and who stopped it.
            'details' => [
                'previous_connection_id' => self::ECHOED,
                'previous_model' => self::ECHOED,
            ],
        ],

        // ── THE TWO EMBEDDING-DESIGNATION OPERATIONS ─────────────────────────────────────────
        //
        // The rerank pair's allow-list, repeated rather than shared through a constant — the same
        // call the four connection operations and the three model operations make, and the same
        // reason: the lists must be able to DIVERGE. `subject_type`/`subject_id` point at the
        // Organization, which is the record the columns live on.
        self::EMBEDDING_DESIGNATION_SET => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                // The pair as PERSISTED, read back off the locked `organizations` row rather than
                // taken from the request — which is what makes "no credential can appear here" a
                // property of the code rather than of the caller's discipline.
                'connection_id' => self::ECHOED,
                'model' => self::ECHOED,
                // WHICH VECTOR SPACE THE EXISTING CORPUS IS IN, i.e. what a re-index would have to
                // go back to. Absent entirely when there was no previous designation: a null is
                // skipped by sanitize() without being reported.
                'previous_connection_id' => self::ECHOED,
                'previous_model' => self::ECHOED,
            ],
        ],
        self::EMBEDDING_DESIGNATION_CLEARED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            // NO `connection_id`/`model` PAIR, because after a clear there is none — writing the two
            // keys as nulls would be indistinguishable from a `set` row whose sanitizer dropped
            // them. The whole content of this row is what the organization USED to embed with, which
            // is the only thing that says what to re-index back to if the resolution rule now picks
            // something else or refuses.
            'details' => [
                'previous_connection_id' => self::ECHOED,
                'previous_model' => self::ECHOED,
            ],
        ],

        // ── THE QUOTA-LIMIT OPERATION ────────────────────────────────────────────────────────
        //
        // Eight numbers and one derived boolean. See the constant for why it is one operation
        // rather than four, and why `raised` is the field an audit of this surface reads first.
        self::QUOTA_LIMITS_UPDATED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                // The four ceilings AS PERSISTED, read back off the locked `organizations` row.
                // A key's ABSENCE means null, i.e. UNLIMITED.
                'storage_bytes_quota' => self::ECHOED,
                'bots_quota' => self::ECHOED,
                'users_quota' => self::ECHOED,
                'monthly_tokens_quota' => self::ECHOED,
                // What they replaced. After the write the old numbers exist nowhere.
                'previous_storage_bytes_quota' => self::ECHOED,
                'previous_bots_quota' => self::ECHOED,
                'previous_users_quota' => self::ECHOED,
                'previous_monthly_tokens_quota' => self::ECHOED,
                // DERIVED FROM THE TWO PAIRS ABOVE, INSIDE THE SERVICE, and not from request input.
                // True when any ceiling went up or became unlimited. A `true` row whose actor is not
                // a platform owner is the finding an audit of this surface exists to produce.
                'raised' => self::ECHOED,
            ],
        ],

        // ── THE THREE BOT OPERATIONS ───────────────────────────────────────────────────────────
        //
        // ONE ALLOW-LIST, REPEATED THREE TIMES RATHER THAN SHARED THROUGH A CONSTANT — the same
        // call the four connection operations and the three model operations make, and the same
        // reason: the lists must be able to DIVERGE. A shared constant makes "add a field to the
        // created row" silently add it to the deleted row too, and the whole design of this table
        // is that each operation decides for itself what it may record.
        //
        // The three lists are IDENTICAL TODAY, deliberately. A hard delete leaves the third row as
        // the only description of the bot, so it must carry everything that made the row readable —
        // and a `created`/`updated` pair carrying LESS than the `deleted` row would make the three
        // unreadable side by side when the question is "what changed before it was removed".
        //
        // See the BOT_CREATED docblock for what is deliberately NOT here, and why the first item on
        // that list — the system instruction — is the one that matters.
        self::BOT_CREATED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                // TENANT-CONTROLLED FREE TEXT, and the only surviving identification of the bot
                // after a hard delete: `subject_id` resolves to nothing then.
                'name' => self::ECHOED,
                'slug' => self::ECHOED,

                // WHO CAN REACH THIS BOT, AND HOW IT MAY ANSWER. Four separate facts and a bot is
                // reachable only when they agree, so recording one without the others would make
                // the row unreadable — "published" says nothing on its own about whether an
                // anonymous visitor could get to it.
                'status' => self::ECHOED,
                'access_mode' => self::ECHOED,
                'answer_mode' => self::ECHOED,
                'allow_general_answers' => self::ECHOED,

                // WHICH CREDENTIAL IS BILLED AND WHICH VENDOR SEES THE QUESTIONS. ULIDs, never key
                // material: the credential behind the connection is envelope-encrypted and is not
                // reachable from a Bot at all.
                'provider_connection_id' => self::ECHOED,
                'provider_model_id' => self::ECHOED,

                // THE RETRIEVAL CONFIGURATION AND THE VERSION THAT MAKES IT REPLAYABLE. A refusal
                // rate that moved without an explanation is the hardest regression to attribute,
                // and the version is what lets a stored trace be matched to the configuration that
                // produced it after the row has changed again.
                'dense_top_k' => self::ECHOED,
                'sparse_top_k' => self::ECHOED,
                'rerank_candidates' => self::ECHOED,
                'rerank_retain' => self::ECHOED,
                // THE FIRST FLOAT IN THIS TABLE. sanitize()'s `is_float()` path keeps it and
                // refuses NAN and INF, because neither is representable in JSON and either would
                // fail the insert and take the whole audit row with it. Null is skipped silently —
                // an uncalibrated bot, which is every bot today, simply omits the pair.
                'evidence_threshold' => self::ECHOED,
                'evidence_threshold_scale' => self::ECHOED,
                'retrieval_configuration_version' => self::ECHOED,

                // Integers, kept by the `is_int()` path. Null means "the platform default applies",
                // which is a different fact from a configured limit that happens to equal it — and
                // a null is skipped without being reported, so an unlimited bot omits all three.
                'rate_limit_per_minute' => self::ECHOED,
                'rate_limit_per_day' => self::ECHOED,
                'retention_days' => self::ECHOED,

                // A COMPLIANCE FLAG AND THEREFORE AN AUDIT FIELD. The consent TEXT it requires is
                // NOT echoed: it is prose, and the CHECK constraint already guarantees it exists
                // whenever this is true.
                'collect_end_user_data' => self::ECHOED,

                // ── THE THREE CHILD COLLECTIONS, AS SCALARS. THIS IS FINDING L2. ──────────────
                //
                // A bot delete is a HARD delete that takes the origin allow-list, the starter
                // questions and the fallback chain with it, and `bot_domains` justifies its own
                // ON DELETE RESTRICT by saying a security review may later need to RECONSTRUCT the
                // allow-list. Before these fields the trail could not: `bot.deleted` described the
                // bot in full and said nothing at all about what it permitted.
                //
                // THE COUNTS SIT BESIDE THE JOINED LISTS, AND THAT IS NOT REDUNDANCY. sanitize()
                // truncates an echoed string at :self::MAX_VALUE_LENGTH SILENTLY, and a full
                // allow-list does not fit in 512 characters — so the count is what makes a
                // truncated `active_origins` DETECTABLE rather than merely wrong. An integer takes
                // the `is_int()` path: never truncated, never redacted, and a 0 records as 0.
                //
                // ONLY THE ACTIVE ORIGINS ARE ECHOED. A pending or disabled row granted nothing;
                // its existence is in `domain_count` and its own value is in its own
                // `bot.domain.created` row. And ONLY the origins — the starter questions are
                // COUNTED AND NEVER ECHOED, because a chip label is prose that decides nothing
                // while an origin string IS the security fact. App\Services\Bots\BotChildSummary
                // states both halves and is what builds these values.
                'domain_count' => self::ECHOED,
                'active_domain_count' => self::ECHOED,
                'active_origins' => self::ECHOED,
                'starter_question_count' => self::ECHOED,
                // THE FALLBACK CHAIN NAMES `provider_models` ROWS — i.e. WHICH CREDENTIALS MAY BE
                // BILLED for this bot's answers when the primary model fails. No endpoint writes it
                // yet and the bot delete already destroys it, so it is summarised here on exactly
                // the argument the allow-list makes. Whoever lands its write endpoints owes it the
                // per-row `bot.fallback_model.*` operations the origins now have.
                'fallback_model_count' => self::ECHOED,
                'fallback_model_ids' => self::ECHOED,

                // ── THE RETRIEVAL SCOPE, AS TWO INTEGERS AND NO LIST ────────────────────────
                //
                // `bot_source_assignments` is the FOURTH child collection a bot delete destroys,
                // and the one whose rows decide which DOCUMENTS the bot could read: `bot_ids` is
                // one of the four mandatory Qdrant filter terms and is resolved from that table.
                //
                // COUNTED AND NEVER ECHOED, unlike the origins. An origin string IS the security
                // fact; a source id is a pointer whose meaning lives in another table, and its
                // NAME is unbounded tenant prose of exactly the kind this table refuses from a
                // `bot.*` row. What reconstructs the scope is the per-grant
                // `bot.source_assignment.created` and `.deleted` rows, which carry `source_id`,
                // `source_name`, `priority` and `enabled` one grant at a time and outlive the bot
                // — including the rows a bot delete writes for the grants it takes with it. These
                // two numbers are the TRIPWIRE that sends a reader here looking for those.
                //
                // BOTH, because they answer different questions: how many grants existed, and how
                // many of them GRANTED anything. A bot whose every assignment was switched off had
                // exactly as much corpus as one with none. The same pairing, for the same reason,
                // as `domain_count` beside `active_domain_count`.
                'source_assignment_count' => self::ECHOED,
                'enabled_source_assignment_count' => self::ECHOED,
            ],
        ],
        self::BOT_UPDATED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            // The values AFTER the edit. Every field is carried on every row, including the ones
            // this particular PATCH did not name, because a row that recorded only what changed
            // would be unreadable next to the `created` and `deleted` rows for the same subject —
            // and "what did it look like afterwards" is the question a reader actually has.
            //
            // WHICH IS ALSO WHY THERE IS NO `previous_status` OR `previous_access_mode` HERE while
            // `bot.domain.status_changed` below carries one. It is a decision, not an omission, and
            // the constant's own docblock states it in full: the previous values are derivable from
            // the preceding `bot.*` row for this subject, the domain row is a ONE-COLUMN transition
            // where "previous" is unambiguous and this one is not, and the only cheap way to
            // populate it here would read the pre-image OUTSIDE the row lock.
            'details' => [
                'name' => self::ECHOED,
                'slug' => self::ECHOED,
                'status' => self::ECHOED,
                'access_mode' => self::ECHOED,
                'answer_mode' => self::ECHOED,
                'allow_general_answers' => self::ECHOED,
                'provider_connection_id' => self::ECHOED,
                'provider_model_id' => self::ECHOED,
                'dense_top_k' => self::ECHOED,
                'sparse_top_k' => self::ECHOED,
                'rerank_candidates' => self::ECHOED,
                'rerank_retain' => self::ECHOED,
                'evidence_threshold' => self::ECHOED,
                'evidence_threshold_scale' => self::ECHOED,
                'retrieval_configuration_version' => self::ECHOED,
                'rate_limit_per_minute' => self::ECHOED,
                'rate_limit_per_day' => self::ECHOED,
                'retention_days' => self::ECHOED,
                'collect_end_user_data' => self::ECHOED,
                // THE CHILD COLLECTIONS — see BOT_CREATED above for the whole argument, and note
                // that these are the values AFTER the write like every other field on this row.
                'domain_count' => self::ECHOED,
                'active_domain_count' => self::ECHOED,
                'active_origins' => self::ECHOED,
                'starter_question_count' => self::ECHOED,
                'fallback_model_count' => self::ECHOED,
                'fallback_model_ids' => self::ECHOED,
                // THE RETRIEVAL SCOPE — see BOT_CREATED above for the whole argument.
                'source_assignment_count' => self::ECHOED,
                'enabled_source_assignment_count' => self::ECHOED,
            ],
        ],
        self::BOT_DELETED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'name' => self::ECHOED,
                'slug' => self::ECHOED,
                'status' => self::ECHOED,
                'access_mode' => self::ECHOED,
                'answer_mode' => self::ECHOED,
                'allow_general_answers' => self::ECHOED,
                'provider_connection_id' => self::ECHOED,
                'provider_model_id' => self::ECHOED,
                'dense_top_k' => self::ECHOED,
                'sparse_top_k' => self::ECHOED,
                'rerank_candidates' => self::ECHOED,
                'rerank_retain' => self::ECHOED,
                'evidence_threshold' => self::ECHOED,
                'evidence_threshold_scale' => self::ECHOED,
                'retrieval_configuration_version' => self::ECHOED,
                'rate_limit_per_minute' => self::ECHOED,
                'rate_limit_per_day' => self::ECHOED,
                'retention_days' => self::ECHOED,
                'collect_end_user_data' => self::ECHOED,
                // THE CHILD COLLECTIONS — see BOT_CREATED above for the whole argument, and note
                // that these are the values AFTER the write like every other field on this row.
                'domain_count' => self::ECHOED,
                'active_domain_count' => self::ECHOED,
                'active_origins' => self::ECHOED,
                'starter_question_count' => self::ECHOED,
                'fallback_model_count' => self::ECHOED,
                'fallback_model_ids' => self::ECHOED,
                // THE RETRIEVAL SCOPE — see BOT_CREATED above for the whole argument.
                'source_assignment_count' => self::ECHOED,
                'enabled_source_assignment_count' => self::ECHOED,
            ],
        ],
        self::BOT_DELETE_REFUSED => [
            // THE ONLY FAILURE-OUTCOME ROW IN THE `bot.*` FAMILY. See the constant's docblock for
            // why a refused delete is audited at all and why it is a separate operation rather than
            // a `bot.deleted` row with a different outcome.
            'outcome' => self::OUTCOME_FAILURE,
            'on_failure' => self::ON_FAILURE_LOG,
            'details' => [
                // The same surviving identification the `bot.deleted` row carries — and here the
                // bot is NOT gone, which is the point: a reader has a row to go and look at.
                'name' => self::ECHOED,
                'slug' => self::ECHOED,
                'status' => self::ECHOED,
                // THE TRIPWIRE. How much history the refusal protected. A scalar and nothing else:
                // no conversation ids, no participants, no message text. See the constant.
                'conversation_count' => self::ECHOED,
                // A CLOSED TOKEN NAMING WHICH GUARD REFUSED, in the shape `source.upload.rejected`
                // uses for the same reason: never an exception message, which would be unbounded,
                // would vary by driver version, and on this path would quote a constraint name that
                // means nothing to a compliance reader.
                'reason' => self::ECHOED,
            ],
        ],

        // ── THE D5 PLAYGROUND CREDENTIAL ───────────────────────────────────────────────────────
        //
        // The constant's docblock carries the whole decision: what this row records that no
        // `conversations`, `provider_calls` or `usage_events` row does, why `auth.login.succeeded`
        // rather than `provider.connection.*` is the precedent, and why there is no token field of
        // any kind on it.
        self::BOT_PLAYGROUND_SESSION_MINTED => [
            'outcome' => self::OUTCOME_SUCCESS,
            // LOG, on the class docblock's real test — "can this still be rolled back". It cannot:
            // the Valkey record is already written and the bearer is already on its way out. An
            // ABORT here would 500 a request that HAD ISSUED A LIVE CREDENTIAL, which is strictly
            // worse than the missing row it was trying to prevent.
            'on_failure' => self::ON_FAILURE_LOG,
            'details' => [
                // `subject_id` is the bot, and this is here anyway for the reason every `bot.*`
                // child row carries it: `bot.deleted` is a HARD delete, after which `subject_id`
                // resolves to nothing.
                'bot_id' => self::ECHOED,
                // NOT THE TOKEN AND NOT DERIVABLE INTO ONE — see the constant's docblock. It is
                // `substr(sha256(secret), 0, 32)`, which is what ties this row to the `rl:` buckets
                // and the log lines that name the same session.
                'session_id' => self::ECHOED,
                // Always `true` today, recorded anyway so a second playground kind would read
                // beside this one — the same call `bot.domain.created` makes about an always
                // `pending` status.
                'diagnostics' => self::ECHOED,
                // THE LIFETIME OF THE CAPABILITY, IN SECONDS. An integer takes sanitize()'s
                // `is_int()` path — never truncated, never redacted — and it is what makes a mint
                // that happened during an incident window answerable as "was it still live at
                // 14:20".
                'expires_in' => self::ECHOED,
            ],
        ],

        // ── THE THREE ORIGIN-ALLOW-LIST OPERATIONS ─────────────────────────────────────────────
        //
        // ONE ALLOW-LIST, REPEATED THREE TIMES RATHER THAN SHARED THROUGH A CONSTANT — the same
        // call every other family in this map makes, and the same reason: the lists must be able to
        // DIVERGE, and a shared constant makes "add a field to the created row" silently add it to
        // the deleted row too.
        //
        // THEY ARE NEARLY IDENTICAL, AND THE ONE DIFFERENCE IS THE POINT. `previous_status` is on
        // the transition row alone, because only there is there a previous status to name; putting
        // it on `created` would record a transition out of a state the row never held, and on
        // `deleted` it would be a second spelling of `status`.
        //
        // `bot_id` IS ON ALL THREE AND IS LOAD-BEARING. `subject_id` is the allow-list entry's own
        // ULID, and after the bot is hard-deleted it resolves to nothing — so without this the
        // trail can say an origin was granted and cannot say WHICH BOT it was granted for, which is
        // most of the question finding L2 asks.
        self::BOT_DOMAIN_CREATED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'bot_id' => self::ECHOED,
                // THE GRANT ITSELF, echoed and never fingerprinted — see the constant's docblock
                // for why a fingerprint answers the wrong question here, and for the one
                // tenant-self-harm case where the shape backstop degrades it anyway.
                'origin' => self::ECHOED,
                // Always `pending` on this row. Recorded anyway, so the three rows for one entry
                // read side by side without the reader having to remember which of them can vary.
                'status' => self::ECHOED,
            ],
        ],
        self::BOT_DOMAIN_STATUS_CHANGED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'bot_id' => self::ECHOED,
                'origin' => self::ECHOED,
                // THE STATUS AFTER, matching every other `updated` row in this map.
                'status' => self::ECHOED,
                // AND THE STATUS BEFORE, read under the same row lock that wrote the new one — so
                // two concurrent promotions serialise and neither row can name a status the entry
                // never held. "Who turned this origin on, and what was it before" is the question
                // an incident asks, and it is unanswerable from a row carrying only the result.
                'previous_status' => self::ECHOED,
            ],
        ],
        self::BOT_DOMAIN_DELETED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'bot_id' => self::ECHOED,
                'origin' => self::ECHOED,
                // WHETHER IT WAS LIVE WHEN IT WENT. A removed `pending` row never granted
                // anything; a removed `active` one did, and the difference is the whole reading of
                // this row in an investigation.
                'status' => self::ECHOED,
            ],
        ],

        // ── THE THREE STARTER-QUESTION OPERATIONS ──────────────────────────────────────────────
        //
        // NO `question` FIELD ON ANY OF THEM, and its absence is the decision — see the constants'
        // docblock. It is unbounded tenant PROSE that authorizes nobody and decides nothing, which
        // is exactly the ground `welcome_message`, `placeholder_text` and `description` are refused
        // from the `bot.*` rows on. What these rows answer is that somebody changed the
        // suggestions, which one, in which direction, and how many there are afterwards.
        //
        // `bot_id` is load-bearing for the reason it is on the domain rows: after a hard delete
        // `subject_id` resolves to nothing.
        self::BOT_STARTER_QUESTION_CREATED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'bot_id' => self::ECHOED,
                // Zero-based, and on the create path it is always the end of the list — recorded
                // because it is what makes two `created` rows for one bot orderable after the fact.
                'sort_order' => self::ECHOED,
                // THE RESULTING LENGTH OF THE LIST, which is what makes the row readable beside a
                // `bot.*` row carrying `starter_question_count` for the same bot.
                'question_count' => self::ECHOED,
            ],
        ],
        self::BOT_STARTER_QUESTION_UPDATED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'bot_id' => self::ECHOED,
                'sort_order' => self::ECHOED,
                'question_count' => self::ECHOED,
                // WHICH FIELDS ACTUALLY MOVED, joined into a scalar by the caller — the
                // `capabilities` shape, for the reason sanitize() drops arrays outright. Without
                // it a text edit and a reorder produce byte-identical rows, and "who reordered the
                // suggestions" becomes unanswerable from a trail that recorded both.
                'changed' => self::ECHOED,
            ],
        ],
        self::BOT_STARTER_QUESTION_DELETED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'bot_id' => self::ECHOED,
                // The position it HELD, which is the only thing left that distinguishes it from
                // its siblings once the text is deliberately not recorded.
                'sort_order' => self::ECHOED,
                'question_count' => self::ECHOED,
            ],
        ],

        // ── THE SIX SOURCE-LEVEL OPERATIONS ────────────────────────────────────────────────────
        //
        // ONE ALLOW-LIST SHAPE, REPEATED RATHER THAN SHARED THROUGH A CONSTANT — the same call
        // every other family in this map makes, and the same reason: the lists must be able to
        // DIVERGE, and a shared constant makes "add a field to the created row" silently add it to
        // the deleted row too.
        //
        // NO `description`, NO EXTRACTED TEXT, NO `tags`. See the constants' docblock: the first
        // two are unbounded tenant prose in an append-only table an investigator has to be able to
        // read, and the third is an ARRAY, which sanitize() drops outright.
        self::SOURCE_CREATED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                // TENANT-CONTROLLED FREE TEXT, and the only surviving identification of the source
                // after the hard delete: `subject_id` resolves to nothing then.
                'name' => self::ECHOED,
                'type' => self::ECHOED,
                'status' => self::ECHOED,
                // THE CRAWL TARGET, echoed and never fingerprinted, for the reason
                // `bot.domain.created` echoes an origin: it is the SECURITY FACT itself rather than
                // a description of one. An investigation asks which URLs this platform was told to
                // fetch, and it asks without a candidate list to test against — which is the only
                // question a fingerprint could answer. Null on a file or text source, and a null is
                // skipped silently, so the key's absence is meaningful.
                'origin_url' => self::ECHOED,
                // The retrieval time window. Timestamps, kept by sanitize() like `expires_at` on
                // the invitation rows, and the pair that explains a source which is present and
                // answers nothing.
                'effective_at' => self::ECHOED,
                'expires_at' => self::ECHOED,
            ],
        ],
        self::SOURCE_UPDATED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            // The values AFTER the edit, every field on every row — including the ones this
            // particular PATCH did not name — because a row that recorded only what changed would
            // be unreadable next to the `created` and `deleted` rows for the same subject.
            // Identical reasoning, and the identical decision about `previous_*`, as `bot.updated`.
            'details' => [
                'name' => self::ECHOED,
                'type' => self::ECHOED,
                'status' => self::ECHOED,
                'origin_url' => self::ECHOED,
                'effective_at' => self::ECHOED,
                'expires_at' => self::ECHOED,
            ],
        ],
        self::SOURCE_DELETED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'name' => self::ECHOED,
                'type' => self::ECHOED,
                'status' => self::ECHOED,
                'origin_url' => self::ECHOED,
                // THE SCALE OF WHAT WENT, AS SCALARS. The same tripwire the `bot.*` rows carry for
                // their child collections: a reader who lands on this row and sees 412 items and
                // 1,340 versions knows a crawl was removed rather than a document, and knows to go
                // looking at the `source.version.retired` rows that preceded it. An integer takes
                // sanitize()'s `is_int()` path: never truncated, never redacted, and a 0 records
                // as 0.
                'item_count' => self::ECHOED,
                'version_count' => self::ECHOED,
            ],
        ],
        self::SOURCE_DISABLED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'name' => self::ECHOED,
                'status' => self::ECHOED,
                // READ UNDER THE SAME ROW LOCK that writes the new value, so two concurrent moves
                // serialise and neither row can name a status the source never held. This is a
                // ONE-COLUMN transition, which is what makes "previous" unambiguous here and
                // ambiguous on `source.updated` — the asymmetry `bot.updated` states in full.
                'previous_status' => self::ECHOED,
            ],
        ],
        self::SOURCE_ENABLED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'name' => self::ECHOED,
                'status' => self::ECHOED,
                'previous_status' => self::ECHOED,
            ],
        ],
        self::SOURCE_REPROCESS_REQUESTED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'name' => self::ECHOED,
                'status' => self::ECHOED,
                // NOT A CREDENTIAL — see the constant's docblock. It is the one ingest-key
                // component that changes when nothing else did, so it is what makes an explicit
                // reprocess reach a worker instead of deduping against the completed run.
                'force_nonce' => self::ECHOED,
                // How much work was asked for. A reprocess of a 400-page crawl spends provider
                // embedding tokens on every item, and this is the number that says so.
                'item_count' => self::ECHOED,
            ],
        ],

        // ── THE UPLOAD PAIR ────────────────────────────────────────────────────────────────────
        //
        // TWO OPERATIONS AND NOT ONE WITH A VARYING OUTCOME, because `outcome` is derived from the
        // operation in this map rather than passed in — which is the property that stops any row
        // claiming `auth.login.failed` with `outcome = success`. A single `source.upload` operation
        // could therefore only be one or the other.
        self::SOURCE_UPLOAD_ACCEPTED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                // The item the bytes landed on. `subject_id` is the SOURCE, because that is what
                // was authorized and what a reader searches by.
                'source_item_id' => self::ECHOED,
                // The user's filename. Tenant-controlled free text and echoed anyway — see the
                // constant's docblock, and note that `source_items_display_name_is_not_a_path`
                // already refuses a separator, a control character and the two directory-relative
                // names before this value can reach the column.
                'display_name' => self::ECHOED,
                // SNIFFED FROM CONTENT, never the client's Content-Type. Recording the sniffed
                // value is what makes the cross-check auditable after the fact.
                'mime' => self::ECHOED,
                'byte_size' => self::ECHOED,
                // A DIGEST, NOT A SECRET, and it is what makes "we processed exactly these bytes"
                // checkable against the version that was published from them.
                'content_hash' => self::ECHOED,
                // A GENERATED path under this organization's own prefix — never the uploaded
                // filename, and `source_items_storage_key_is_tenant_scoped` refuses a key outside
                // the row's own organization. It names no object another tenant can reach.
                'storage_key' => self::ECHOED,
            ],
        ],
        self::SOURCE_UPLOAD_REJECTED => [
            'outcome' => self::OUTCOME_FAILURE,
            // THE ONE LOG ROW IN THIS FAMILY, and it is the "can this still be rolled back" test
            // answering NO from the other direction: there is no state change to undo. The refusal
            // is already decided, no row was written, and aborting would turn a rejected file into
            // a 500 — both a lie to the caller and still no audit row.
            'on_failure' => self::ON_FAILURE_LOG,
            'details' => [
                'display_name' => self::ECHOED,
                // What the CLIENT'S bytes actually sniffed as, which on a rejection is the whole
                // point: `.xlsx` arriving as `application/x-dosexec` is the row somebody wants to
                // find. Null when the refusal happened before sniffing — a size rejection reads
                // the header and stops — and a null is skipped silently.
                'mime' => self::ECHOED,
                'byte_size' => self::ECHOED,
                // A CLOSED TOKEN NAMING WHICH GATE REFUSED, never an exception message. See the
                // constant's docblock: a message would be unbounded, would vary by library version,
                // and could echo a parser's reading of a hostile file into an append-only table.
                'reason' => self::ECHOED,
            ],
        ],

        // ── THE POINTER SWITCH, AS TWO ROWS ────────────────────────────────────────────────────
        self::SOURCE_VERSION_ACTIVATED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                // Which page or file of the source this is about. Load-bearing for the same reason
                // `bot_id` is on every `bot.*` child row: after the source is hard-deleted,
                // `subject_id` resolves to nothing.
                'source_item_id' => self::ECHOED,
                'version_number' => self::ECHOED,
                // Which of the two Ready flavours it landed in. `ready_with_warnings` is identical
                // to `ready` for retrieval, so this is the only place the distinction is durable
                // once the warning summary has been superseded.
                'status' => self::ECHOED,
                // WHAT IT REPLACED, read inside the same transaction under the lockForUpdate() the
                // switch already takes on the item — so the row is readable on its own instead of
                // requiring a reader to reconstruct the chain from retirement rows.
                'previous_version_id' => self::ECHOED,
                // Replayability: which content, which four configurations, which vector space.
                // A sha256 hexdigest of public inputs and a provider/model/width/probe-digest
                // string. Neither is a secret and neither identifies a person.
                'ingest_key' => self::ECHOED,
                'embedding_model_version' => self::ECHOED,
                // THE VERIFIED TOTAL the data plane counted with exact=True. The number the whole
                // verification gate turns on, written down where a later disagreement with the
                // collection can be measured against it.
                'chunk_count' => self::ECHOED,
            ],
        ],
        self::SOURCE_VERSION_RETIRED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'source_item_id' => self::ECHOED,
                'version_number' => self::ECHOED,
                'status' => self::ECHOED,
                // PRESENT WHEN THE RETIREMENT WAS PART OF A PUBLISH, ABSENT WHEN THE VERSION WAS
                // SIMPLY WITHDRAWN — an archive or a delete retires with nothing in its place, and
                // "retired, replaced by nothing" is exactly the state an incident asks about.
                // sanitize() skips a null silently, so the key's absence carries the distinction.
                'superseded_by_version_id' => self::ECHOED,
            ],
        ],

        // ── THE RETRIEVAL-SCOPE GRANT ──────────────────────────────────────────────────────────
        //
        // `source_name` sits beside `source_id` and `bot_id` deliberately: both parents are HARD
        // deletes, so a row carrying only ULIDs resolves to nothing on either end afterwards — and
        // "which documents was this bot allowed to answer from" is precisely the question that gets
        // asked after the fact.
        self::BOT_SOURCE_ASSIGNMENT_CREATED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'bot_id' => self::ECHOED,
                'source_id' => self::ECHOED,
                'source_name' => self::ECHOED,
                // A tie-break between this bot's sources, never a filter. Recorded because a
                // reordering is otherwise byte-identical to no change at all.
                'priority' => self::ECHOED,
                // WHETHER THE GRANT IS LIVE. A disabled assignment grants nothing; the difference
                // is the whole reading of this row in an investigation, exactly as `status` is on
                // `bot.domain.deleted`.
                'enabled' => self::ECHOED,
            ],
        ],
        self::BOT_SOURCE_ASSIGNMENT_DELETED => [
            'outcome' => self::OUTCOME_SUCCESS,
            'on_failure' => self::ON_FAILURE_ABORT,
            'details' => [
                'bot_id' => self::ECHOED,
                'source_id' => self::ECHOED,
                'source_name' => self::ECHOED,
                'priority' => self::ECHOED,
                // Whether it was LIVE when it went. A removed disabled row granted nothing; a
                // removed enabled one did.
                'enabled' => self::ECHOED,
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
