---
name: laravel-sanctum-auth
description: Authentication for the Laravel control plane — which mechanism proves identity for the admin SPA, hosted chat, the embedded widget, and mobile, plus org resolution, token abilities, and the composite rate-limit keys. Use whenever touching a guard, a login or logout route, session or CSRF config, token minting or revocation, the SDK bootstrap endpoint, or a 401. Owns proving who someone is; whether they may act is laravel-rbac-policies. Pairs with kb-security-baseline (the widget shape this implements).
---

# Laravel Sanctum Authentication

Laravel **13.24** (2026-08-04, PHP 8.3–8.5) · **laravel/sanctum 4.3.3** (2026-06-23, `illuminate/* ^11|^12|^13`) · Valkey for short-lived session records · optional `laravel/passkeys` behind Fortify `Features::passkeys()` as the admin login factor. <!-- UNVERIFIED: passkey feature flag confirmed from Laravel News and the Fortify docs index, not from the feature list itself -->
**Authoritative spec:** docs/02-functional-auth-tenancy-bots.md §8.1 §8.2, docs/13-security.md §18.3 §18.5, docs/12-api-areas.md §17.1–17.4, docs/04-functional-channels-chat.md §8.20

## Non-negotiables

1. **Every authenticated request resolves to exactly one organization, re-read from PostgreSQL.** Neither a token row nor a session value is evidence of *current* membership — both were written in the past. `org.member` middleware and `Sanctum::authenticateAccessTokensUsing()` are the two places that re-read it (`kb-tenancy-isolation` NN 6).
2. **Four client classes, four mechanisms, no fifth.** The table below is the whole set. A new surface picks one; it never gets a second one "for convenience", because two auth paths on one endpoint means two authorization paths and one of them will drift.
3. **Missing/expired/invalid proof is `authentication` → 401. Valid proof, wrong subject is `authorization` → 403 on the admin API, 404 on public runtime and SDK surfaces** (`kb-error-taxonomy`). A 403 on a foreign bot id confirms the bot exists. This skill binds the `Surface` that `laravel-rbac-policies`' `OrgScopedPolicy` reads.
4. **No bearer token ever appears in a URL** — not a query string, not a path segment. It lands in Traefik access logs, in `Referer` on every navigation away from the page, and in browser history. This is what forces the streaming decision below.
5. **The widget shape is fixed by `kb-security-baseline` → `references/widget-embedding-and-output.md`** (controls 1–4, 8). Public bot id grants nothing; origin validated server-side; short-lived origin-bound session token; end-user identity signed by the *customer's backend*; limits on bot + origin + session + IP together. Implement it; do not redesign it.
6. **Token abilities are never the only gate.** For a first-party SPA session Sanctum attaches a `TransientToken` whose `can()` returns `true` for everything, so `tokenCan()` and the `abilities`/`ability` middleware pass unconditionally. Abilities cap what a *token* may attempt; authorization is `laravel-rbac-policies`.
7. **No client holds a provider credential and no client reaches FastAPI** (`kb-architecture-map`). Every mechanism here terminates at Laravel.

## How we use it

| Client | Proves identity with | Scoped to | Lifetime | Revoked by | If it leaks |
|---|---|---|---|---|---|
| **Admin** `apps/web` | Sanctum **SPA cookie session** (`web` guard; `HttpOnly`, `Secure`, `SameSite=Lax`) + CSRF | one user; current org in the session, re-verified per request | session idle lifetime; re-auth for destructive actions (§18.3) | logout invalidates it; `AuthenticateSession` kills siblings on password change | full admin power for that org — but only from a browser the attacker already controls, since the cookie is not JS-readable |
| **Hosted chat** `apps/web` | the **same opaque chat-session token** as the widget, origin-checked against our own origin | one bot, one visitor session | 30 min sliding, refreshable | Valkey TTL / `DEL` | chat with one bot as one anonymous visitor |
| **Widget** `apps/widget` | opaque **origin-bound chat-session token** exchanged for the public bot id | one bot, one origin, one session | 30 min sliding, refreshed via the same origin-checked route | Valkey TTL / `DEL`; removing the domain kills it on the next request | the same one-bot chat ability, and only from a page on an allow-listed origin (non-browser caveat in Gotchas) |
| **Mobile** `apps/mobile` | Sanctum **personal access token**, `Authorization: Bearer` | one user, one org, one device, explicit abilities | hard `expires_at` (30 d); Sanctum has no refresh tokens — re-login | device list → `$user->tokens()->where('id', …)->delete()`; membership revocation via the callback below | that user's non-admin API surface until expiry or revocation |

**Cookies for the admin, not a bearer token.** A bearer token in a browser SPA must live where JavaScript can read it, so one XSS becomes a stolen, long-lived, replayable credential; the `HttpOnly` cookie lets the same XSS *act* but exfiltrates nothing reusable. **The CSRF consequence is unavoidable:** Laravel 13's `PreventRequestForgery` skips token validation only on `Sec-Fetch-Site: same-origin`, and `app.…` → `api.…` is same-*site*, not same-*origin*, so every mutation falls through to token validation. So — `$middleware->statefulApi()`, `GET /sanctum/csrf-cookie` before login, the URL-decoded `XSRF-TOKEN` echoed as `X-XSRF-TOKEN` on every mutation, `supports_credentials => true` in `config/cors.php`, and exact hosts (with ports) in `SANCTUM_STATEFUL_DOMAINS` — never a pattern.

**Hosted chat reuses the widget token rather than the session cookie** because most of its visitors are anonymous and have no session identity at all; one auth path keeps the 404 rule, the rate-limit keys, and the abuse hooks identical across both public surfaces (when the visitor *does* hold an admin session, `mint()` resolves `user_id` from it server-side). **And the widget token is not a Sanctum token**: a Sanctum token needs a `tokenable` model — an anonymous visitor is not a `User` — and lives in PostgreSQL until a prune command runs, whereas a widget session must die in minutes and be droppable in bulk when a domain leaves a bot's allow-list. A Valkey key with a TTL does both.

### Org switching, and the token that outlived its membership

The admin session holds `current_organization_id`, set only by `POST /v1/session/organization`, which re-checks membership. Bearer tokens carry the org in an `organization_id` column on a custom `PersonalAccessToken` model (`Sanctum::usePersonalAccessTokenModel()`), so revoking an org's tokens is one indexed delete instead of a `LIKE` over token names. Both re-read the membership row per request:

```php
// services/core-api/app/Providers/AppServiceProvider::boot() — the one place a revoked membership
// stops an already-minted token. Sanctum's own check reads only created_at, expires_at and the user
// provider; it never asks whether the user still belongs to anything.
Sanctum::authenticateAccessTokensUsing(fn (PersonalAccessToken $t, bool $isValid): bool => $isValid
    && $t->organization_id !== null                       // null fails closed, never "any org"
    && OrganizationUser::query()->where('organization_id', $t->organization_id)
        ->where('user_id', $t->tokenable_id)->where('status', MembershipStatus::Active)->exists());
```

Revoking a membership also deletes that org's tokens for that user; the callback is the backstop. Abilities are explicit and namespaced — `['chat:send', 'conversations:read']` for mobile, never `['*']`, and **never an admin verb on a token minted from anything other than an interactive login**. A widget token is minted with no human authentication at all: anyone who can put the loader on an allow-listed page gets one. If it could carry `sources.delete`, an XSS on the customer's marketing site would delete a tenant's knowledge base.

### Rate limiting (§8.20, §18.3)

The public chat surface throttles on bot, origin, session **and** IP together — each alone fails: IP-only punishes one NAT'd office for one abuser, session-only is defeated by discarding the session, origin-only by hosting the page elsewhere. That composite decision is one sliding-window `EVALSHA` owned by `valkey-keyspaces`, **not** Laravel's `RateLimiter`, which is fixed-window and stays on coarse admin routes. What this skill owns is the two auth-specific limiters:

```php
// §18.3: per account AND per IP. Per-IP alone lets a botnet spray one account; per-account alone
// lets one host walk the user table. Prefix every `by` — identical values inside one array collide.
RateLimiter::for('login', fn (Request $r) => [
    Limit::perMinute(5)->by('acct:'.Str::lower((string) $r->input('email'))),
    Limit::perMinute(20)->by('ip:'.$r->ip()),
]);
// Enumeration cover for the 404 rule: count only the misses, so probing bot ids costs the prober
// and legitimate traffic pays nothing. `after()` is new in Laravel 13.
RateLimiter::for('sdk-bootstrap', fn (Request $r) => Limit::perMinute(10)
    ->by('boot:'.$r->ip())->after(fn (Response $res) => $res->getStatusCode() === 404));
```

### Streaming: we never use `EventSource`

`EventSource` cannot set an `Authorization` header — its constructor's only option is `withCredentials` — and it is GET-only, which our `POST /v1/chat/{conversation}/messages` never was. That leaves a cookie or a query-string token, and a token in a URL is barred by Non-negotiable 4. **Decision: every client reads the SSE stream with `fetch()` + `response.body.getReader()` and an SSE parser.** That restores the header, the request body, and `AbortController` cancellation (→ 499 `user_cancellation`), and sidesteps `EventSource`'s automatic `Last-Event-ID` reconnect, which `kb-internal-api-contracts` already rules out because token streams are not resumable. On React Native use `expo/fetch` (WinterCG fetch — streaming bodies and custom headers; it replaces global `fetch` on iOS and Android): Hermes' XHR-backed `fetch` exposes no `ReadableStream` and fails by delivering the whole answer at once rather than by throwing. `Sanctum::getAccessTokenFromRequestUsing()` would make a query-string token work; it is unused, and CI greps for it.

### Minting and verifying the origin-bound widget session

```php
// services/core-api/app/Services/Sdk/WidgetSessionService.php
declare(strict_types=1); namespace App\Services\Sdk;
use App\Models\Bot; use Illuminate\Support\Facades\Redis;

final class WidgetSessionService
{
    private const TTL  = 1800;  // sliding; the `sess:` family and its TTL belong to valkey-keyspaces
    private const SKEW = 120;   // clock skew tolerated on customer-signed metadata

    public function __construct(private readonly BotDomainMatcher $domains) {}

    /** POST /api/v1/sdk/session. $origin is the request HEADER — never a body or query field. */
    public function mint(string $publicBotId, ?string $origin, ?string $userToken, string $ip): array
    {
        // Public/SDK surface: every rejection here is 404, indistinguishable. A 403 would answer
        // "that bot id is real, your domain just is not on its list".
        $bot = Bot::query()->where('public_id', $publicBotId)->first();
        abort_if($bot === null || ! $bot->isLive(), 404);
        // Origin is the only host-page fact page script cannot forge (Referer can be suppressed); an
        // absent Origin is a rejection, never a default-allow.
        abort_if($origin === null || ! $this->domains->matches($bot, $origin), 404);
        $endUser = $userToken === null ? null : $this->verifyUserToken($bot, $userToken);
        $secret  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        // The org and bot segments are public and untrusted: they only route the lookup, and a forged
        // pair simply misses. Store only the HASH — a Valkey dump must not be a set of credentials.
        Redis::setex($this->key($bot->organization_id, $bot->id, $secret), self::TTL, json_encode([
            'org_id' => $bot->organization_id,  'bot_id' => $bot->id,
            'origin' => $origin,                                // re-checked on every later request
            'token_hash' => hash('sha256', $secret),
            'end_user'   => $endUser,                           // never re-accepted from the client
            'abilities'  => ['chat:send', 'chat:read', 'feedback:submit'],
            'ip_hash'    => hash_hmac('sha256', $ip, config('app.key')),
        ], JSON_THROW_ON_ERROR));
        return ['token' => "kbw_{$bot->organization_id}.{$bot->id}.{$secret}", 'expires_in' => self::TTL];
    }

    /** The guard path, run before anything touches a bot, a conversation, or a provider. */
    public function resolve(?string $bearer, ?string $origin): WidgetSession
    {
        abort_if($bearer === null || ! str_starts_with($bearer, 'kbw_'), 401);   // authentication
        [$orgId, $botId, $secret] = array_pad(explode('.', substr($bearer, 4), 3), 3, null);
        // ULID, not integer. Every id in this system is a ULID char(26) in Crockford base32
        // (`postgresql-patterns`), so `ctype_digit` here would reject every real token and 401
        // every widget session ever minted. Excluded letters: I, L, O, U.
        $ulid = '/^[0-9A-HJKMNP-TV-Z]{26}$/';
        abort_if($secret === null || ! preg_match($ulid, (string) $orgId)
                                  || ! preg_match($ulid, (string) $botId), 401);
        $raw = Redis::get($this->key($orgId, $botId, $secret));
        abort_if($raw === null, 401);                                           // expired or revoked
        $s = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        // The key is a truncated digest, so confirm the full one — timing-safely, always, on a credential.
        abort_unless(hash_equals($s['token_hash'], hash('sha256', $secret)), 401);
        // Origin binding is proven AT MINT ONLY, and this is the subtlety that makes the widget work at
        // all. `mint()` runs on a POST from the customer's page, so its Origin is the embedder's and is
        // unforgeable. Every request after the handshake comes from the IFRAME, whose Origin is always
        // our own widget origin — so comparing $origin to $s['origin'] here would 404 every chat message
        // ever sent (`iframe-postmessage-bridge`). What we check now is that the caller is our own
        // surface, and that the origin we bound at mint is STILL allow-listed.
        $bot = Bot::query()->find($s['bot_id']);
        abort_if($bot === null || ! in_array($origin, self::OUR_EMBED_ORIGINS, true), 404);
        // Re-validate the STORED embedder origin against the LIVE allow-list and status, never the
        // snapshot: a removed domain must stop working now, not when the TTL lapses.
        abort_unless(hash_equals($bot->organization_id, $orgId) && $bot->isLive()
            && $this->domains->matches($bot, $s['origin']), 404);
        // A custom X-KB-Embedder-Origin header is NOT a substitute: page script sets fetch headers
        // freely, so it would be forgeable and the binding worthless.

        return new WidgetSession($s['org_id'], $s['bot_id'], $s['end_user'], $s['abilities']);
    }

    // §18.5 control 3 — the CUSTOMER'S BACKEND signs {sub,name,email,iat,exp} with a per-bot shared
    // secret. Anything the loader could have written is display data at best (docs/04 §8.20), so a
    // failure here is a rejection, not a silent downgrade to anonymous.
    private function verifyUserToken(Bot $bot, string $token): array
    {
        [$h, $p, $sig] = array_pad(explode('.', $token, 3), 3, null);
        abort_if($sig === null, 404);
        // The algorithm is pinned from OUR config, never read from the token — trusting the header's
        // `alg` is the alg-confusion class ("alg":"none", HS/RS swap).
        $header = json_decode($this->b64d($h), true) ?: [];
        abort_unless(($header['typ'] ?? null) === 'JWT', 404);
        // `kid` selects a secret VERSION, so the customer rotates without a window of downtime.
        $sharedSecret = $bot->userTokenSecret($header['kid'] ?? null);
        abort_if($sharedSecret === null, 404);
        // hash_equals, not ===: byte-at-a-time comparison of an HMAC is a forgery oracle.
        $expected = hash_hmac('sha256', "{$h}.{$p}", $sharedSecret, binary: true);
        abort_unless(hash_equals($expected, $this->b64d($sig)), 404);
        $c = json_decode($this->b64d($p), true, flags: JSON_THROW_ON_ERROR); $now = time();
        abort_unless(isset($c['sub'], $c['iat'], $c['exp'])
            && $c['iat'] <= $now + self::SKEW && $c['exp'] > $now - self::SKEW, 404);
        // Display identity and the customer's own scoping key — it never selects our organization, our
        // bot, or any permission; scope comes from the bot, not the visitor.
        return ['sub' => (string) $c['sub'], 'name' => $c['name'] ?? null, 'email' => $c['email'] ?? null];
    }

    // The session id is a one-way function of the token: derivable from the bearer, useless alone.
    private function key(string $o, string $b, string $s): string { return "sess:{$o}:{$b}:".substr(hash('sha256', $s), 0, 32); }
    private function b64d(string $s): string { return base64_decode(strtr($s, '-_', '+/'), true) ?: ''; }
}
```

**Not defined here.** Policies, roles, the permission catalog, `OrgScopedPolicy` → `laravel-rbac-policies`. CSP, `frame-ancestors`, iframe `sandbox`, `postMessage`, CHIPS storage, and full-origin matching rules → `kb-security-baseline` / `widget-sdk-engineer`. Statuses and retry semantics → `kb-error-taxonomy`. App shape, middleware ordering, the SSE relay → `laravel-control-plane`. The HMAC on the Laravel↔FastAPI seam is a *service* credential and belongs to `kb-internal-api-contracts`.

## Gotchas

- **The admin API returns 401 with a perfectly valid session cookie — but only from Next.js server components.** `EnsureFrontendRequestsAreStateful::fromFrontend()` reads `Referer` or `Origin`; a server-side `fetch()` sends neither, so the request is classified third-party, session middleware never runs, and the cookie is ignored. Forward the cookie *and* an `Origin` matching a `sanctum.stateful` entry from every server-side call, or keep authenticated fetching in the browser.
- **Session expiry surfaces as a 419 the SPA renders as "Something went wrong".** Session-authenticated mutations fail CSRF before they fail auth, so an idle admin gets `419 CSRF token mismatch`, not 401. Map 419 → re-fetch `/sanctum/csrf-cookie` → retry once → redirect to login. Everyone writes the 401 handler and forgets this one.
- **Every admin mutation 403s in production the day someone "simplifies" CSRF.** `preventRequestForgery(originOnly: true)` drops the token fallback, and same-*site* is not same-*origin*, so it fails origin verification with a 403 rather than a 419. The instinctive fix, `allowSameSite: true`, re-opens CSRF to **every** subdomain including any customer-facing one. Leave both at their defaults and keep the token flow.
- **A route guarded by `abilities:bots.manage` lets the entire dashboard through.** For a first-party SPA session Sanctum attaches a `TransientToken` and `tokenCan()` returns `true` for every string — documented, and the reason ability middleware alone is never a gate. Pair it with `Gate::authorize()`.
- **`$user->createToken('mobile')` mints a god token and nothing fails.** The abilities argument defaults to `['*']`. Route every mint through one factory that requires an explicit list, and assert in a test that no `personal_access_tokens` row has `abilities = ["*"]`.
- **Every device is logged out at once, hours after a deploy nobody connects it to.** `Guard::isValidAccessToken()` evaluates `created_at > now()->subMinutes(config('sanctum.expiration'))` **at request time**, so lowering `expiration` retroactively expires tokens already issued. Keep `'expiration' => null`, set per-token `expires_at` via `createToken($name, $abilities, $expiresAt)`, and schedule `sanctum:prune-expired --hours=24`.
- **`personal_access_tokens` becomes the hottest write table in the database.** Sanctum's guard `save()`s `last_used_at` on *every* token-authenticated request — one UPDATE per stream, per poll, per prefetch. Set `'last_used_at' => false` in `config/sanctum.php` (undocumented; read as `config('sanctum.last_used_at', true)` by `SanctumServiceProvider::createGuard()`) and derive last use from `usage_events`.
- **The widget iframe arrives carrying an admin session cookie — and a subdomain takeover becomes a CSRF platform.** Both come from wildcard domain scoping. `'domain' => '.ourdomain.example'` (the Sanctum SPA guide's advice) covers *every* subdomain including `widget.ourdomain.example`, so the widget document holds a real admin credential on customer pages and CHIPS cannot help, because the cookie is not the widget's. Separately, `fromFrontend()` strips the scheme and `Str::is`-matches only the host, so `*.ourdomain.example` in `SANCTUM_STATEFUL_DOMAINS` matches a dangling DNS record *and* matches over plain `http://`. Serve the widget from a **separate registrable domain**, scope the session cookie to the admin and API hosts, and list exact stateful hosts with ports.
- **The widget's preflight fails with an opaque browser CORS error and the server log is empty.** `config/cors.php` is unpublished in Laravel 11+ and its `paths` default to `['api/*', 'sanctum/csrf-cookie']`; an SDK route mounted outside `api/` never gets CORS headers, so the browser rejects the OPTIONS before any controller runs. Publish it, add the SDK prefix, keep `Vary: Origin` (`kb-security-baseline`).
- **An ex-employee's mobile app keeps answering, and audit rows show their user id inside an org they left.** `createToken()` wrote a row; nothing ever re-reads `organization_users`. The callback above plus deleting that org's tokens on revocation is the fix — and a token with a null `organization_id` must fail closed.
- **One five-minute network blip logs out every mobile user permanently.** Sanctum has **no refresh tokens**; when the access token expires the user re-enters a password. Price the expiry against that, keep the token in Expo SecureStore (Keychain/Keystore) and never AsyncStorage, which is plaintext on disk. SecureStore is not a defence against a rooted device or a full device backup — that residual is priced by the 30-day expiry and the revocable device list, not by the storage API.

## Official docs

- [Laravel 13.x — Sanctum](https://laravel.com/docs/13.x/sanctum) — SPA cookie flow, `statefulApi()`, abilities, expiration, `actingAs`.
- [Laravel 13.x — CSRF protection](https://laravel.com/docs/13.x/csrf) — `PreventRequestForgery`, `Sec-Fetch-Site` origin verification, `originOnly`/`allowSameSite`, `X-XSRF-TOKEN`.
- [Laravel 13.x — Routing: rate limiting](https://laravel.com/docs/13.x/routing#rate-limiting) — named limiters, arrays of limits, `by()` prefixing, `after()`.
- [laravel/sanctum `Guard.php`](https://github.com/laravel/sanctum/blob/4.x/src/Guard.php) · [`config/sanctum.php`](https://github.com/laravel/sanctum/blob/4.x/config/sanctum.php) — the validity check, `last_used_at`, and the two static callbacks; several of these are not in the docs.
- [Laravel 13.x — Fortify](https://laravel.com/docs/13.x/fortify) — 2FA and `Features::passkeys()` for the admin login factor.
- [WHATWG HTML — `EventSource`](https://html.spec.whatwg.org/multipage/server-sent-events.html#the-eventsource-interface) (its constructor's only option is `withCredentials`) and [Expo — `expo/fetch`](https://docs.expo.dev/versions/latest/sdk/expo/) (streaming bodies and custom headers on React Native) — the two halves of the streaming decision.

## Definition of done

- [ ] Every route sits in exactly one of four groups — admin (`auth:sanctum` + `org.member`), public runtime (chat-session guard), SDK bootstrap (origin-checked, unauthenticated), internal (HMAC) — and each binds the matching `Surface` for `laravel-rbac-policies`.
- [ ] `rg -n "createToken\(" services/core-api/app` shows calls only from the token factory; each passes an explicit abilities array and an `expiresAt`. No row has `abilities = ["*"]`.
- [ ] A test asserts `tokenCan()` returns `true` under a session while the same route still 403s for a user without the permission — proving abilities are not the gate.
- [ ] A test revokes a membership and asserts the previously-minted token 401s on the very next request, with no process restart and no cache flush.
- [ ] SDK bootstrap tests: unlisted origin, `https://<allowed>.evil.com`, no `Origin`, `Origin: null`, unknown bot id, valid bot from the wrong origin — all **404** with byte-identical bodies. Signed-metadata tests: bad signature, `alg: none`, unknown `kid`, expired `exp`, `iat` ten minutes ahead, truncated token — all rejected, none downgraded to anonymous.
- [ ] A session minted for origin A is replayed with `Origin: B` and rejected; a session is replayed after its bot's domain is removed and rejected without waiting for the TTL.
- [ ] Login throttles per account *and* per IP; the composite chat limit (`valkey-keyspaces`) is exercised on each of bot, origin, session, IP while the other three stay under budget.
- [ ] `SANCTUM_STATEFUL_DOMAINS` contains no `*`; `config/cors.php` is published, lists the SDK prefix, sets `supports_credentials => true`, and never `allowed_origins => ['*']` on SDK routes.
- [ ] `rg -n "EventSource|getAccessTokenFromRequestUsing|[?&]token=" apps/ services/core-api` returns nothing; streaming clients use `fetch` + `getReader()`.
- [ ] `config('sanctum.expiration')` is `null`, `sanctum.last_used_at` is `false`, every token row has a non-null `expires_at`, `sanctum:prune-expired` is scheduled, and Valkey holds only the token digest — a test asserts the plaintext never appears in Valkey, `storage/logs`, or an audit `details` payload.
