<?php

declare(strict_types=1);

// READ BY THE FRAMEWORK WITH NO REGISTRATION. TrustProxies::setTrustedProxyIpAddresses() does
// `$this->proxies() ?: config('trustedproxy.proxies')`, so this key alone supplies the ADDRESSES.
// It does NOT supply the bitmask: getTrustedHeaderNames() in this version reads only
// static::$alwaysTrustHeaders and the class property, never config — hence the bootstrap call.
//
// ── WHY THIS FILE EXISTS AT ALL ──────────────────────────────────────────────────────────────────
// Traefik is the direct TCP peer of every request from the internet, so without trusted proxies
// `$request->ip()` is Traefik's container address for EVERY caller on the planet. This surface is the
// first code to depend on that value as a security control, and the consequences are not subtle:
// `AppServiceProvider`'s `login` limiter is 20/minute per IP, which becomes twenty login attempts a
// minute for the entire internet combined; `password-request` is 10/minute, so one host can make
// password reset unavailable to every user of the platform indefinitely. `AuditLogger::ipFrom()`
// writes the same value to `audit_logs.ip_address`, so every row — including `auth.login.failed`,
// whose `organization_id` and `actor_id` are both null by design — would record the reverse proxy and
// locate nothing.
//
// It is also not only about rate limiting. Without trusted PROTO and PORT, `isSecure()` is false and
// `getPort()` is the container port behind TLS, so Laravel builds `http://` URLs and the admin login
// loops with nothing in any log.
//
// ── ONE SOURCE OF TRUTH, AND IT IS DOCKER'S. NEVER TYPE A CIDR HERE. ─────────────────────────────
// The value is the `edge` network's subnet, declared once as `compose.yaml`'s `x-edge-subnet` anchor
// and aliased into both the ipam block and the `TRUSTED_PROXIES` environment key. A literal in this
// file is a range Docker may reassign, in a file Docker cannot update — a latent outage that presents
// as working rate limiting. (It was measured unpinned: `edge` held 172.24.0.0/16 while an unrelated
// project's network had taken the gap between two of ours, so the ordering is not stable either.)
//
// `edge` rather than Traefik's single address, because Docker allocates dynamic addresses from the low
// end WITHOUT reserving statically-assigned ones, so a hand-picked `.10` breaks Traefik's start once
// the network holds enough containers. The network is the trust boundary the rest of the topology
// already draws. Requests arriving over `application` — ai-service's HMAC callbacks — fall outside the
// range and are correctly ignored.
//
// ── `[]` RATHER THAN `null` WHEN UNSET, AND THE DIFFERENCE IS REAL ───────────────────────────────
// On null, TrustProxies falls through to a branch that sets `'*'` when `laravel_cloud()` is true or
// the host ends `.on-forge.com` / `.on-vapor.com`. An empty array cannot reach it, and means "trust
// nothing" — the status quo, a DEGRADATION and not a bypass, which is the right direction for a
// missing value. The loud half lives where it can be loud: `scripts/ops/preflight.sh` §3b fails the
// deploy. It used to be checked at build time too; that job went with `.github/` on 2026-08-17, so
// preflight is now the only thing that objects.
//
// ── NEVER `'*'` OR `'**'` ────────────────────────────────────────────────────────────────────────
// Both expand to `['0.0.0.0/0', '::/0']`, making `X-Forwarded-For` fully client-supplied — STRICTLY
// WORSE than trusting nothing, because an attacker then forges a distinct address per request and
// evades every per-IP limiter entirely instead of merely sharing one bucket, while `audit_logs`
// faithfully records whatever it was told. Measured: with `at: '*'`, an `X-Forwarded-For: 9.9.9.9`
// becomes `$request->ip()` verbatim.
return [
    'proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', '')),
    ))),
];
