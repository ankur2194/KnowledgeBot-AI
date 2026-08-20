<?php

declare(strict_types=1);

use App\Support\Kb\KbSecrets;

/*
 * Project constants and project-owned security material that are NOT framework configuration and
 * NOT a third-party service (which would belong in config/services.php). Everything here is quoted
 * from an owning skill and lives in exactly one place, because two copies of a number drift and the
 * drifting copy is always the one that ships.
 */

return [

    /*
     * ENVELOPE ENCRYPTION — the key-encrypting key (kb-security-baseline §18.2).
     *
     * A per-credential data key (DEK) encrypts a provider secret; the DEK is wrapped by this KEK
     * and the wrapped form is what the `provider_connections` row stores, alongside `key_version`.
     * Storing the version beside the wrapped key is what makes a KEK rotation a DATA change —
     * re-wrap every DEK — instead of a schema migration. The KEK itself never encrypts a provider
     * secret directly and never leaves this process.
     *
     * NOT APP_KEY, deliberately. APP_KEY covers sessions and cookies and is rotated for entirely
     * different reasons and on an entirely different cadence; sharing one key would make "log
     * everyone out" and "re-wrap every tenant's provider credential" the same operation.
     *
     * DELIVERED AS A FILE: KB_KEK_PATH=/run/secrets/kek (KB_KEK_FILE is accepted too). It is
     * resolved here rather than at the use site so there is exactly one read, and it is null-able
     * at config-build time because local development and the arch/unit suites run without a KEK
     * mounted. The consumer (App\Support\Crypto\KeyVault, not yet written) MUST fail closed on a
     * null KEK at the moment of use — a missing KEK is never a reason to store a credential
     * unwrapped, and never a reason to skip encryption.
     */
    'credentials' => [

        'kek' => KbSecrets::get('KB_KEK'),

        /*
         * The version tag written into `provider_connections.key_version` for newly-wrapped DEKs.
         * An integer label, NOT key material — it identifies which KEK wrapped a row so a rotation
         * can find the rows it still has to re-wrap.
         *
         * OPEN CONTRACT: an online KEK rotation needs BOTH the outgoing and the incoming KEK
         * readable at once (unwrap with the old, re-wrap with the new), exactly as the two HMAC key
         * ids are. The canonical secret-file set currently names one file, `kek`, so the second
         * file's name is not ours to invent here — it is a contract for whoever owns
         * infrastructure/docker. Until it exists, a KEK rotation is an offline operation.
         */
        'kek_version' => (int) env('KB_KEK_VERSION', 1),
    ],

    /*
     * TIMEOUT BUDGETS — quoted from kb-error-taxonomy, seconds.
     *
     * The rule is arithmetic, not vibes:
     *     attempts × (inner timeout + max backoff) + overhead  <  outer timeout
     *
     *     chat (client -> Laravel)          60      outermost
     *     └── internal (Laravel -> FastAPI) 55      leaves 5 s for Laravel to finalize usage and
     *                                              close the SSE stream cleanly after the upstream
     *                                              is done. Shrinking this margin is how a cancelled
     *                                              stream ends with no usage row.
     *         ├── retrieval leg              8      in SERIES with the provider budget
     *         └── provider total            45      8 + 45 = 53 < 55 ✓
     *
     * `internal` is the value passed to Http::timeout() on the chat call; `chat` is the value
     * X-KB-Deadline is computed from, as an ABSOLUTE epoch-millisecond instant derived from
     * LARAVEL_START — never a duration, and never re-derived downstream.
     */
    'timeouts' => [
        'chat' => 60,
        'internal' => 55,
        'retrieval' => 8,
        'provider' => 45,
        'connect' => 3,

        /*
         * Embedding-readiness resolution (finding C1), seconds. NOT `internal`: 55 s is sized for
         * a streamed answer that is waiting on a provider, and this call waits on nothing — it is
         * a pure function of a request body, with no provider call, no Qdrant query and no
         * database read on the far side. Giving it the chat budget would let an admin screen hold
         * an FPM child for the better part of a minute against an ai-api that is already gone,
         * which is how one unhealthy dependency starves the whole admin surface.
         */
        'readiness' => 10,

        /*
         * Ingestion SUBMISSION (Laravel -> FastAPI), seconds. Its own budget, and neither of the
         * two above.
         *
         * NOT `internal` (55 s): that number is sized for a STREAMED ANSWER waiting on a provider,
         * and it is the value X-KB-Deadline is derived from for a request that may legitimately
         * take most of a minute. A submission waits on none of that — it hands the data plane a
         * source, an item list and an idempotency key, and gets a 202 back before any byte of any
         * document has been read. Giving it the chat budget would let one unreachable ai-api hold a
         * queue worker for 55 s per attempt, five attempts deep, which is the whole `ai-dispatch`
         * queue stalled behind a dependency that is already gone.
         *
         * NOT `readiness` (10 s) either, and the difference is the point of having a third row: a
         * readiness call is a pure function of its request body, while a submission WRITES — it
         * claims an idempotency key and enqueues a Celery task. A timeout on a call that wrote is
         * not a failure, it is an UNKNOWN, and the retry that follows it depends on the
         * idempotency record to be a replay rather than a second job. 20 s is chosen to sit well
         * inside SubmitIngestionJob's 60 s `#[Timeout]` with room for the 3 s connect and one
         * jittered backoff rung, so the job's own ceiling is never the thing that fires first.
         */
        'ingestion' => 20,
    ],

    /*
     * BACKOFF LADDER for INTERNAL calls — full jitter, quoted from kb-error-taxonomy:
     *     sleep = uniform(0, min(cap, base × 2 ** attempt))
     * Provider calls use base 0.5 / cap 20 and are the FastAPI adapter's, not ours. Laravel never
     * retries its chat call to FastAPI at all; this ladder is for job-level submission retries only.
     * Note release() takes whole seconds, so rungs below 1 s floor to an immediate retry — `tries`
     * and the taxonomy's 10%-of-requests retry budget are what actually bound the loop.
     */
    'backoff' => [
        'base' => 0.2,
        'cap' => 5.0,
    ],

    /*
     * THE FOUR SURFACES. One place, quoted. Every route sits in exactly one of them, and the value
     * bound per route group is what OrgScopedPolicy reads to choose between a 403 and a 404 deny:
     * on `public_runtime` and `sdk` a foreign identifier must be indistinguishable from a
     * nonexistent one, or the endpoint is an enumeration oracle. The error_class is `authorization`
     * in every case; nothing branches on the rendered status
     * (laravel-sanctum-auth, laravel-rbac-policies, kb-error-taxonomy footnote 1).
     */
    'surfaces' => [
        'admin' => ['prefix' => 'api/v1', 'public' => false, 'deny_status' => 403],
        'public_runtime' => ['prefix' => 'rt/v1', 'public' => true, 'deny_status' => 404],
        'sdk' => ['prefix' => 'sdk/v1', 'public' => true, 'deny_status' => 404],
        'internal' => ['prefix' => 'internal/v1', 'public' => false, 'deny_status' => 403],
    ],

    /*
     * THE SEAM. `contract_version` mirrors the /internal/v1 path prefix and rides as
     * X-KB-Contract-Version; a mismatch between the two is a 409, not a best-effort guess.
     *
     * `signing_prefix` is what this application EMITS. `accepted_signing_prefixes` is what it
     * ACCEPTS on inbound callbacks, and it is configuration rather than a constant precisely so a
     * prefix bump can be a two-deploy operation: the verifier accepts both for one release window
     * while the signer emits one (ADR-018).
     */
    'contract_version' => 'v1',
    'signing_prefix' => 'KB1',

    /*
     * What this application ACCEPTS on an inbound signed callback. A LIST, not a scalar, and the
     * plurality is the whole mechanism: during a prefix bump the verifier accepts both for one
     * release window while the signer emits only the new one, because signer and verifier deploy at
     * different times and a single-value scheme 401s every callback through a rolling deploy.
     * Dropping the retired prefix is a DELIBERATE SECOND DEPLOY, never part of the first.
     *
     * It is configuration rather than a constant for exactly that reason (ADR-018). The comment
     * above has named this key since the seam was designed; this is it.
     */
    'accepted_signing_prefixes' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('KB_ACCEPTED_SIGNING_PREFIXES', 'KB1')),
    ))),
    'signature_skew_seconds' => 60,
    'replay_nonce_ttl_seconds' => 120,

    /*
     * SSE heartbeat interval, seconds. `: ping` is an SSE COMMENT, not an event — which is why the
     * relay is response()->stream() and not eventStream(), since eventStream() can only emit named
     * events and a forwarded `heartbeat` event would fire onmessage in every client.
     *
     * It is also the disconnect probe: PHP learns the client is gone only when a write FAILS, so
     * with no writes there is nothing to fail and connection_aborted() stays 0 through an
     * arbitrarily long silence. 15 s is chosen to sit well under the tightest hop we do not control
     * (nginx defaults proxy_read_timeout to 60 s), not to match any one of them.
     */
    'heartbeat_seconds' => 15,

    /*
     * THE ADMIN SPA'S PUBLIC BASE URL — the base of every emailed link.
     *
     * Password reset, email verification and organization invitation all point at the Next.js
     * console, never at a Laravel route: this application serves no HTML pages at all, so the SPA
     * reads the token out of its own query string and POSTs it back to the API.
     *
     * IT IS NOT `app.url`. config('app.url') is THIS service's own host (`api.<domain>` in Compose);
     * this is `app.<domain>`. Confusing the two mails every recipient a link to a JSON 404, and the
     * symptom is "the link doesn't work" rather than anything an operator would grep for.
     *
     * A plain env() read is correct here: FRONTEND_URL contains none of SECRET_NAME_FRAGMENTS
     * (tests/Arch/SecretsResolverTest.php), and a public base URL is not key material.
     *
     * RTRIMMED HERE, VALIDATED ELSEWHERE. Configuration must never throw — config/ is rebuilt by
     * `php artisan config:cache`, so an exception here takes down the very commands an operator
     * would run to fix it. So the trailing slash is normalised here and the "is this actually a
     * URL" question is asked by App\Support\Kb\FrontendUrl at the moment a link is built. That
     * split is load-bearing: the failure mode of an unset value is not an error, it is
     * `https:///verify-email?token=…` — mail that sends, arrives, and does nothing.
     */
    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/'),

    /*
     * OPAQUE-TOKEN LIFETIMES, IN HOURS.
     *
     * These two are ours; the password-reset lifetime is NOT here. That one is
     * config/auth.php's `passwords.users.expire` (60 MINUTES) because the framework's
     * DatabaseTokenRepository reads it, and it is deliberately not an env var:
     * AUTH_PASSWORD_RESET_EXPIRE contains "PASSWORD", so it would need allow-listing in
     * tests/Arch/SecretsResolverTest.php for a value that is a duration, not a secret.
     *
     * An invitation week is long because it crosses a weekend and a holiday; a verification day is
     * short because the recipient just typed the address and a resend costs one click. Both are read
     * by the service that mints the row AND quoted into the email body, so neither number appears
     * twice.
     */
    /**
     * The role the REQUEST-SERVING containers authenticate as — the grantee whose privileges decide
     * whether `audit_logs` is really append-only (D21).
     *
     * ── WHY THIS IS NOT `database.connections.pgsql.username` ────────────────────────────────────
     * It was, and that was a live bug caught by the acceptance test rather than by review. The
     * `audit_logs` migration and `EloquentAuditLogPartitionRepository` both have to REVOKE write
     * privileges from the application role — but the migration runs in the `laravel-migrate`
     * container, where `DB_USERNAME` is deliberately `kb_migrate` so it can create tables. So
     * `pgsql.username` resolved to the MIGRATION role and the migration revoked from itself:
     * measured on the live cluster, `audit_logs` came out
     * `{kb_migrate=arxtm/kb_migrate,kb_app=ar/kb_migrate}` — correct only because the separate
     * `apply-roles.sh` had already revoked from `kb_app`. Without that script the table would have
     * been writable by the application for the whole session.
     *
     * The application role is a DEPLOYMENT fact, not a property of whichever connection is open, so
     * it is named once here and read from both places. The fallback chain keeps every environment
     * that has no split working unchanged: explicit `DB_APP_USERNAME`, else whatever this process
     * connects as, else the least-privileged name.
     */
    'db_app_role' => (string) env('DB_APP_USERNAME', env('DB_USERNAME', 'kb_app')),

    'invitation_ttl_hours' => (int) env('KB_INVITATION_TTL_HOURS', 168),
    'email_verification_ttl_hours' => (int) env('KB_EMAIL_VERIFICATION_TTL_HOURS', 24),

];
