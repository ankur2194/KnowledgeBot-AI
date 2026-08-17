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
     * ── SUPERSEDED 2026-08-13. READ THIS FIRST, THEN THE ORIGINAL REASONING BELOW IT. ──────────────
     *
     * The block below forbids '.<domain>' and the deployment now USES it, by Ankur's ruling of
     * 2026-08-13. What follows is kept rather than rewritten, because its argument is sound and is the
     * cost being paid — a reader who only saw the new value would not know what it bought.
     *
     * WHY IT CHANGED, MEASURED. This file's own heading said "SCOPED TO THE ADMIN AND API HOSTS ONLY",
     * and a host-only cookie cannot be scoped to two hosts — the sentence asked for something the value
     * could not express. The consequence was not theoretical: the SPA on app.<domain> must READ the
     * JS-readable XSRF-TOKEN cookie that api.<domain> sets, and a host-only cookie is invisible to
     * script on a different host. Measured on the deployed stack: `GET /sanctum/csrf-cookie` returned
     * 204 and set both cookies on api.<domain>, `document.cookie` on the SPA's origin was EMPTY, and
     * apps/web/src/lib/api/browser.ts threw "XSRF-TOKEN cookie absent after GET /sanctum/csrf-cookie
     * — check SESSION_DOMAIN". No mutation could be sent, so login, password reset and invitation
     * acceptance all failed with a generic banner and nothing in any server log.
     *
     * WHAT IS NOW ACCEPTED, AND IT IS THE HAZARD THE BLOCK BELOW NAMES. chat.<domain> receives both
     * cookies. kb_session stays HttpOnly, so an XSS there cannot read it — but it CAN read XSRF-TOKEN,
     * and a same-site request already carries kb_session, so a compromised public subdomain can forge
     * admin mutations. Before this change it could not. The widget is unaffected: it is served from a
     * separate registrable domain, which is what the block below correctly relies on.
     *
     * THE TWO UPGRADES THAT WOULD LET THIS GO BACK TO HOST-ONLY, both recorded as open: serve the API
     * under the SPA's own origin at a path prefix, so nothing is cross-origin and no cookie needs
     * widening; or return the CSRF token in a response BODY, which is strictly stronger than this
     * because CORS then stops a sibling subdomain from reading it at all.
     *
     * The legal shapes of this value, the coupling to FRONTEND_URL, and the config-to-wire agreement
     * are asserted by tests/Security/SessionCookieScopeTest.php, which carries the full record.
     *
     * ── ORIGINAL REASONING, SUPERSEDED BUT NOT WRONG ───────────────────────────────────────────────
     *
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
