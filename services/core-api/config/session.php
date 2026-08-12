<?php

declare(strict_types=1);

return [

    // Valkey, on the NON-EVICTING instance: an evicted session is a silent logout mid-form.
    'driver' => env('SESSION_DRIVER', 'redis'),
    'connection' => env('SESSION_CONNECTION', 'default'),
    'store' => env('SESSION_STORE', 'valkey'),

    'lifetime' => (int) env('SESSION_LIFETIME', 120),
    'expire_on_close' => false,

    // The payload sits in Valkey in plaintext otherwise, and it carries current_organization_id and
    // the CSRF token.
    'encrypt' => true,

    'files' => storage_path('framework/sessions'),
    'table' => 'sessions',
    'lottery' => [2, 100],

    'cookie' => env('SESSION_COOKIE', 'kb_session'),
    'path' => '/',

    /*
     * SCOPED TO THE ADMIN AND API HOSTS ONLY — never '.<domain>'.
     *
     * The Sanctum SPA guide suggests a leading-dot parent domain. Do not follow it. A cookie scoped
     * to '.example.com' is sent to EVERY subdomain, so the moment the widget is served as a sibling
     * host the widget document — running inside an iframe on a hostile customer page — holds a live
     * admin credential. CHIPS cannot help, because the cookie is not the widget's. A subdomain
     * takeover on any unrelated host becomes a CSRF platform for the same reason.
     *
     * The widget is served from a SEPARATE REGISTRABLE DOMAIN with its own eTLD+1, which is what
     * makes this scoping enforceable rather than merely intended (laravel-sanctum-auth, Gotchas).
     *
     * Null means "the current host, host-only" — the safe default when a deployment has one host.
     */
    'domain' => env('SESSION_DOMAIN'),

    // Secure-only. `Sec-Fetch-Site` is only sent over HTTPS, so plain-HTTP anywhere silently changes
    // which CSRF code path runs.
    'secure' => (bool) env('SESSION_SECURE_COOKIE', true),

    // Not JS-readable: the entire reason the admin uses a cookie instead of a bearer token is that
    // one XSS must not yield a replayable credential.
    'http_only' => true,

    // Lax, not None. `None` would let any site send the admin session cookie; the admin SPA and the
    // API are same-site, so Lax is sufficient and the CSRF token flow covers the rest.
    'same_site' => 'lax',

    // Partitioned (CHIPS) is for third-party contexts. This cookie must never appear in one.
    'partitioned' => false,

];
