<?php

declare(strict_types=1);

use App\Support\Kb\KbSecrets;

return [

    // The AI data plane. Exactly ONE class in this application may read `services.ai.url`:
    // App\Services\Internal\InternalAiClient. A controller, job or service that builds its own
    // Http:: call bypasses signing, deadline propagation and the retry ban — an arch test pins the
    // Http facade to that namespace and tests/Arch/StringLevelDoctrineTest.php fails on a literal
    // 'ai-api' or 'services.ai.url' anywhere in app/ outside it (laravel-control-plane DoD).
    //
    // THIS COMMENT USED TO SAY "CI GREPS FOR" THAT SECOND HALF, AND THERE IS NO CI: `.github/` was
    // deleted on 2026-08-17 and nothing replaced it. The line below is the DEFINITION and lives in
    // config/, which the test does not scan — a scan that included it would fail on the one read
    // that must exist.
    'ai' => [

        'url' => env('AI_SERVICE_URL', 'http://ai-api:8000'),

        // Outbound signing: Laravel -> FastAPI. Canonical prefix KB1 (config/kb.php).
        'hmac' => [
            // The id the SIGNER uses right now. The verifier accepts every id in `keys`, which is
            // what makes a rotation possible at all: signer and verifier deploy at different times,
            // so two ids must be live at once or a rolling deploy 401s every internal call.
            'active' => env('AI_HMAC_ACTIVE_KEY_ID', 'k1'),

            // key_id => secret. Two entries during a rotation; dropping the retired id is a
            // DELIBERATE SECOND DEPLOY, never part of the first. array_filter drops ids with no
            // secret set, so an unrotated environment carries one live key rather than a null one.
            //
            // SECRET FILES ARE NAMED BY KEY ID, NEVER BY ROTATION ROLE. The wire carries the id
            // (X-KB-Signature = key_id + ":" + hex(...)), verifiers accept two ids at once during a
            // rotation, and the id is the thing that stays stable across it. A file called
            // `current` cannot answer "which id is this", and after a rotation the same physical
            // key would have to be renamed in lockstep with the rotation the id exists to survive.
            //   AI_HMAC_KEY_K1_FILE=/run/secrets/hmac_key_k1
            //   AI_HMAC_KEY_K2_FILE=/run/secrets/hmac_key_k2
            'keys' => array_filter([
                'k1' => KbSecrets::get('AI_HMAC_KEY_K1'),
                'k2' => KbSecrets::get('AI_HMAC_KEY_K2'),
            ]),
        ],

        // Inbound verification: FastAPI's progress/verification callbacks INTO Laravel. Both
        // directions are signed and each direction uses its OWN key id, so a compromised outbound
        // key cannot forge a callback (kb-internal-api-contracts).
        'callback_hmac' => [
            'accepted_prefixes' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('AI_CALLBACK_ACCEPTED_PREFIXES', 'KB1')),
            ))),
            //   AI_CALLBACK_HMAC_KEY_C1_FILE=/run/secrets/callback_hmac_key_c1
            //   AI_CALLBACK_HMAC_KEY_C2_FILE=/run/secrets/callback_hmac_key_c2
            'keys' => array_filter([
                'c1' => KbSecrets::get('AI_CALLBACK_HMAC_KEY_C1'),
                'c2' => KbSecrets::get('AI_CALLBACK_HMAC_KEY_C2'),
            ]),
        ],
    ],

    // Laravel never queries Qdrant — retrieval is reachable only through InternalAiClient. The
    // collection name lives here because ParallelTesting::setUpProcess token-scopes it per test
    // worker, and because the deletion-verification contract needs both runtimes to name the same
    // collection (kb-tenancy-isolation, pest-testing).
    'qdrant' => [
        'url' => env('QDRANT_URL', 'http://qdrant:6333'),
        'collection' => env('QDRANT_COLLECTION', 'kb_chunks'),
    ],

];
