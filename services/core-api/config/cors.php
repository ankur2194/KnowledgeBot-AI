<?php

declare(strict_types=1);

/*
 * PUBLISHED DELIBERATELY. Laravel 11+ leaves this file unpublished and its `paths` default to
 * ['api/*', 'sanctum/csrf-cookie'] — so an SDK route mounted anywhere else gets no CORS headers at
 * all, the browser rejects the OPTIONS preflight, and NOTHING reaches a controller or a server log.
 * The symptom is an opaque browser CORS error against a silent server (laravel-sanctum-auth).
 */

return [

    // Every browser-reachable prefix, one line each. `internal/*` is absent and must stay absent:
    // no browser may ever call it, and a CORS entry is the first step to it being reachable.
    'paths' => [
        'api/*',              // admin API
        'rt/*',               // public chat runtime — widget, hosted chat, mobile
        'sdk/*',              // SDK bootstrap — the prefix the unpublished default would miss
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'],

    // EXACT ORIGINS, WITH SCHEME AND PORT. Never ['*']: with supports_credentials the browser
    // rejects the wildcard outright, and a wildcard on the SDK surface would let any page on the
    // internet mint widget sessions. The widget's own allow-list is per-bot and enforced
    // server-side at mint time — this list is the platform's own first-party origins only
    // (kb-security-baseline).
    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
    )),

    // Empty on purpose. A pattern here is a regex over the Origin header, which is how
    // https://app.example.com.evil.com gets allowed.
    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-XSRF-TOKEN',        // the URL-decoded XSRF-TOKEN cookie, echoed on every mutation
        'X-KB-Request-Id',
        'X-KB-Session-Token',  // the opaque origin-bound widget/hosted-chat session token

        /*
         * W3C Trace Context, sent by the BROWSER — not by a server.
         *
         * apps/web's instrumentation-client.ts registers FetchInstrumentation with
         * `propagateTraceHeaderCorsUrls` scoped to this API's origin, so every admin-console fetch
         * carries `traceparent`. That is what joins the browser span to the Laravel span to the
         * FastAPI span in one trace; without it the client leg is an orphan.
         *
         * OMITTING THEM DOES NOT DEGRADE TRACING — IT BREAKS THE APPLICATION. A request header the
         * preflight does not list makes the browser refuse the request entirely, before this service
         * is reached: `Request header field traceparent is not allowed by Access-Control-Allow-Headers`.
         * Measured on the deployed stack, where it blocked `GET /sanctum/csrf-cookie` and therefore
         * every login, reset and invite — with nothing in any server log, because Laravel answered the
         * preflight 204 and considered itself correct. A CORS refusal is invisible server-side.
         *
         * `tracestate` accompanies `traceparent` whenever a vendor has added state to it. It is listed
         * because the pair is one propagator's output, and a preflight that allows half of it fails
         * exactly as completely as one that allows neither.
         */
        'traceparent',
        'tracestate',
    ],

    // Nothing sensitive: no X-KB-* internal metadata, no signature, no cost data.
    'exposed_headers' => ['Retry-After', 'X-KB-Request-Id'],

    'max_age' => 600,

    // TRUE, and the reason is Non-negotiable 4 of laravel-sanctum-auth: the admin SPA authenticates
    // with an HttpOnly cookie precisely so an XSS cannot exfiltrate a replayable credential. That
    // choice is only expressible if the browser is allowed to send the cookie cross-origin, and it
    // forces `allowed_origins` to be an exact list.
    'supports_credentials' => true,

];

/*
 * `Vary: Origin` — required, and NOT set here. Laravel's HandleCors middleware sets it on every
 * response it processes, which is what stops a shared cache (Traefik, a CDN, or the browser's own)
 * from serving one origin's Access-Control-Allow-Origin to another. If a middleware or a response
 * macro ever rebuilds headers wholesale, it must re-add Vary: Origin — assert it in a test.
 */
