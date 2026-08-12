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

];
