# Minting and verifying the origin-bound widget session

Companion to `SKILL.md`. `WidgetSessionService` in full — the mint path, the guard path that runs
before anything touches a bot, a conversation or a provider, and the verification of the metadata
token the customer's backend signs. Spec: `docs/13-security.md` §18.5, `docs/04-functional-channels-chat.md` §8.20.
Every 404 here is deliberate and every comment is the reasoning, not decoration.

**The mint is `POST /sdk/v1/session`, and the prefix is a security boundary, not a naming choice.**
`services/core-api/bootstrap/app.php` mounts four disjoint groups — `api/v1` (admin: cookie session
+ CSRF, the only group on Laravel's `api` middleware group), `rt/v1` (public chat runtime),
`sdk/v1` (this one) and `internal/v1`. Posting this mint under `api/v1/...` would hand a request
made from a **hostile customer page** to the admin group's session, CSRF and cookie stack — exactly
the inheritance the four-group split exists to prevent — and it would be served by a group that has
no `sdk` route to serve it. `sdk/*` also sits **outside** `api/` deliberately: Laravel 11+ leaves
`config/cors.php` unpublished with `paths` defaulting to `['api/*', 'sanctum/csrf-cookie']`, so
this route needs its own explicit entry or the browser rejects the preflight before any controller
runs and the server log is empty. This document said `api/v1/sdk/session` for several revisions
while three shipped clients had the same bug (ruling D1); the shipped loader is now
`apps/widget/src/loader/bridge.ts`, and its docblock is the second copy of this argument.

```php
// services/core-api/app/Services/Sdk/WidgetSessionService.php
declare(strict_types=1); namespace App\Services\Sdk;
use App\Models\Bot; use Illuminate\Support\Facades\Redis;

final class WidgetSessionService
{
    private const TTL  = 1800;  // sliding; the `sess:` family and its TTL belong to valkey-keyspaces
    private const SKEW = 120;   // clock skew tolerated on customer-signed metadata

    public function __construct(private readonly BotDomainMatcher $domains) {}

    /** POST /sdk/v1/session — NOT api/v1/*. $origin is the request HEADER — never a body or query field. */
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
        $key = $this->key($orgId, $botId, $secret);
        $raw = Redis::get($key);
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
        // Tier 1 of the refresh flow below — the TTL slides on every AUTHORIZED request, so an active
        // conversation can never cross it. Sliding only AFTER every check above is what keeps this from
        // becoming a way to hold a revoked session warm: a removed domain still 404s on this same call.
        Redis::expire($key, self::TTL);

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

## Refresh — how a widget session survives its own TTL

There is no refresh endpoint the iframe can call. Every request the frame makes carries `Origin: https://<widget-domain>` — our own origin — which proves nothing about which page is embedding us, and `iframe-postmessage-bridge` accepts exactly **one** `init` per frame, so "just handshake again" is not available either. Origin proof exists in one place only: the loader's `POST /sdk/v1/session` from the customer's page. Renewal therefore has two tiers, and the re-mint is driven by the **loader**, never by the frame.

**The symptom this closes.** A visitor opens the widget, leaves the tab over lunch, comes back and sends a question. The bearer is past its TTL, `resolve()` 401s, and with no re-mint path the composer clears, nothing streams, and the conversation stays dead until a full page reload — on a customer's site, in front of their customer. One 401 in the console, no retry, no error state: that is the signature.

**Tier 1 — sliding renewal, no round trip and no message.** `resolve()` calls `Redis::expire($key, self::TTL)` after every check passes (above). An *active* conversation consequently cannot expire: the chat deadline is 60 s (`kb-error-taxonomy`), three orders of magnitude inside the window, so no single request can straddle the boundary. This does not weaken revocation, because the live allow-list, `isLive()` and organization checks run on every one of those same requests — removing a domain kills the session on the next call, not at the next TTL boundary.

**Tier 2 — the loader re-mints, on a distinct message type.** What tier 1 cannot save is an idle frame. The sequence:

1. **The frame notices, and asks — it does not act.** Trigger is either a `401` with `error_class: authentication` on any API call, or the proactive one: `expires_in` (returned by `mint()`) is within 5 minutes of lapsing. The frame never calls `/sdk/session` itself; from inside the frame that POST carries an origin nobody should care about.
2. **`{kb:1, ch, type:'session-expiring'}`, frame → host**, `targetOrigin` = its `?origin=`, same envelope and same `ch` as everything else on this channel. A **distinct type**, deliberately: the one-`init`-per-frame rule is untouched and a second `init` stays ignored, because `init` is a state-machine reset and a token swap is not one.
3. **The loader re-issues exactly the boot mint.** After the usual `event.source` → `event.origin` → `ch` checks it repeats `POST https://api.<domain>/sdk/v1/session`, `mode: 'cors'`, `credentials: 'omit'`, body `{bot_id, user_token}` built from **its own boot configuration**. Nothing in the body comes from the message; the message is a ping, not a request with parameters. That POST is made by a document on the customer's page, so the browser sets `Origin: https://customer.example` and page script cannot forge it — the one host-page fact worth anything, produced *fresh at refresh time* rather than replayed from mint time.
4. **Laravel treats it as a plain mint.** Same route, same `sdk-bootstrap` limiter, same 404 on every rejection, `matches($bot, $origin)` against the header. The result is a **new session record with a new secret and its own TTL**, not an extension; the old key is left to lapse. Signed identity is re-verified from scratch, which matters for control-3 bots: the customer's backend mints `userToken` with `exp` ≤ 5 minutes, so the boot token is long dead. The loader obtains a fresh one from the host page before re-minting (the customer's identity callback); a stale token is a 404 like any other and is **never** downgraded to an anonymous session.
5. **`{kb:1, ch, type:'session', payload:{session:{token, expires_in}}}`, host → frame.** The frame replaces its module-scoped bearer and nothing else — no re-boot, no conversation reset, and it re-reads no bot id, origin, end-user identity or ability from the payload. Everything except the token string is ignored, so the best a hostile host page achieves is handing us a token that fails its own `resolve()`.
6. **No token in a URL, fragment included.** The new token exists in exactly two places: that `postMessage` payload and the `Authorization` header of the frame's own fetches. Never `iframe.src`, never `location.hash` — a fragment is not sent to the server but it still lands in `location`, in the frame's history entry, and in whatever the customer's analytics scrapes off the DOM (SKILL.md NN 4). The frame is not re-navigated at all; re-navigation would destroy conversation state and demand a second `init`.
7. **Binding after mint is unchanged.** Origin is proven at mint from the header and stored; every later request re-validates the **stored** `$s['origin']` against the bot's live allow-list and status, never against the refreshing request's `Origin`, which is our widget origin and would 404 every message ever sent.

**What the frame does while a refresh is in flight — a queued send is never lost.** One refresh at a time: the trigger sets a single in-flight promise and every later trigger awaits it, so two simultaneous 401s produce one mint rather than two (two would burn the `sdk-bootstrap` limiter and orphan a session). Sends issued in that window are **queued in memory, in order, and flushed when the new token lands** — never dropped, never retried against the dead token. The composer clears and the message renders as pending exactly as with a live session; the visitor sees latency, not a failure. Each queued send keeps the `Idempotency-Key` it was created with, so a send that actually reached Laravel *before* the 401 cannot be double-posted by the flush (`kb-internal-api-contracts`). If the refresh fails, or does not answer within 10 s, the queue fails **once and visibly** with `error_class: authentication` and a "reload to continue" affordance — a queue that retries forever is the same dead conversation with a spinner on top of it.
