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
     * X-KB-Deadline is computed from, as an ABSOLUTE epoch-millisecond instant — never a duration,
     * and never re-derived downstream.
     *
     * THE INSTANT IT IS ADDED TO IS THE CALLER'S, NOT A CONSTANT. This block used to say "derived
     * from LARAVEL_START", which is right for a request-scoped caller and WRONG for a queued one:
     * LARAVEL_START is process-scoped, so in a long-lived worker it is the worker's BOOT and
     * `boot + budget` is an instant already in the past. InternalAiClient names the two epochs
     * separately (`requestEpoch()` / `callEpoch()`) and requires the call site to pick one.
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

        /*
         * PAYLOAD MAINTENANCE (Laravel -> FastAPI), seconds — `source.status.sync` and
         * `bot.access.sync`.
         *
         * ITS OWN ROW BECAUSE THIS CALL WAITS FOR THE WORK, unlike every other row above. A
         * submission gets a 202 before a byte is read; these two run the rewrite and the filtered
         * count that proves it, and return the counts, because a filtered count is the only
         * evidence any of it happened and an accepted-and-report-later shape would hand the caller
         * the acknowledgement `set_payload` already returns whether it rewrote everything or
         * nothing.
         *
         * 45 s is sized for the expensive half — `bot.access.sync` scrolls the points of a source
         * and rewrites `bot_ids` per point, so its cost scales with the corpus rather than being
         * constant like the status rewrite's single filtered `set_payload`. It sits inside
         * `SyncBotAccessJob`'s 90 s `#[Timeout]` with room for the 3 s connect, so the job's
         * ceiling is never what fires first — the same arrangement `ingestion` has with
         * `SubmitIngestionJob`.
         *
         * A TIMEOUT HERE IS AN UNKNOWN AND NOT A FAILURE, and it is safe to retry into: both
         * operations are convergent on the far side, which selects the points still NEEDING the
         * change, so a redelivery after a lost response rewrites nothing and re-counts the proof.
         */
        'maintenance' => 45,
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
     * `signing_prefix` is what this application EMITS on the OUTBOUND direction (Laravel ->
     * FastAPI). What it ACCEPTS on an inbound callback is `services.ai.callback_hmac
     * .accepted_prefixes`, and it lives beside the callback KEY RING rather than here on purpose:
     * the two are one direction's configuration and separating them is how a verifier ends up
     * reading one direction's prefixes against the other direction's keys (ADR-018).
     */
    'contract_version' => 'v1',
    'signing_prefix' => 'KB1',

    /*
     * THERE IS NO `accepted_signing_prefixes` KEY HERE, and its absence is load-bearing. The
     * inbound list is `services.ai.callback_hmac.accepted_prefixes` (env
     * `AI_CALLBACK_ACCEPTED_PREFIXES`), which is what every .env.example and the Compose env file
     * have shipped since the seam was designed. A second key of the same meaning under `kb.` read
     * from an env var no deployment sets would SILENTLY DEFAULT to `['KB1']` and make the
     * documented two-deploy prefix bump a no-op — the verifier would keep accepting exactly one
     * prefix however the operator set the variable they were told to set.
     */
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

    /*
     * THE ORPHAN-SWEEP GRACE WINDOW, IN MINUTES, and it is generous on purpose.
     *
     * `kb:sweep-orphan-objects` deletes an object that no `source_items` row names. A reservation
     * younger than this window is SKIPPED, because the request that made it may still be running —
     * its object written, its rows not yet committed — and in that instant the sweep's own check
     * legitimately says "nothing claims this key". Deleting then produces the failure the whole
     * write-before-row ordering was chosen to avoid: a committed row pointing at nothing, which
     * fails ingestion on every attempt with `error_class: storage` and needs an operator.
     *
     * SO THE TWO DIRECTIONS ARE NOT SYMMETRIC AND THE DEFAULT REFLECTS THAT. Too long and an
     * orphan sits on disk for a few more hours, costing storage. Too short and live bytes are
     * destroyed. Six hours is roughly two orders of magnitude above any request this application
     * can serve — PHP-FPM's `request_terminate_timeout` and the 25 MB per-file cap bound an upload
     * request to minutes — and the cost of that margin is measured in megabytes.
     *
     * LOWER IT ONLY WITH A MEASUREMENT OF THE LONGEST CREATE REQUEST, not with an intuition about
     * how long uploads take.
     */
    'upload_orphan_grace_minutes' => (int) env('KB_UPLOAD_ORPHAN_GRACE_MINUTES', 360),

    /*
     * HOW MANY RESERVATIONS ONE SWEEP TICK MAY PROCESS.
     *
     * The scheduler runs due events SEQUENTIALLY in one process, so a task with no ceiling delays
     * every task defined after it (routes/console.php's header states the budget as seconds). Each
     * collected row costs one indexed SELECT and one object DELETE against SeaweedFS; 500 is a
     * few seconds of work in the worst case, and a backlog larger than that drains over successive
     * hourly ticks in `created_at` order rather than in one long tick.
     */
    'upload_orphan_sweep_limit' => (int) env('KB_UPLOAD_ORPHAN_SWEEP_LIMIT', 500),

    'invitation_ttl_hours' => (int) env('KB_INVITATION_TTL_HOURS', 168),
    'email_verification_ttl_hours' => (int) env('KB_EMAIL_VERIFICATION_TTL_HOURS', 24),

];
