<?php

declare(strict_types=1);

namespace App\Services\Sdk;

use App\Enums\ActorType;
use App\Enums\BotAccessMode;
use App\Enums\Permission;
use App\Models\Bot;
use App\Repositories\Contracts\ChatConfigurationRepositoryInterface;
use App\Repositories\Contracts\MembershipRepositoryInterface;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use SensitiveParameter;

/**
 * Mint and resolve the opaque, origin-bound chat-session token.
 *
 * ═══ WHAT THE TOKEN IS, AND WHAT IT DELIBERATELY IS NOT ════════════════════════════════════
 *
 * NOT A SANCTUM TOKEN. A Sanctum token needs a `tokenable` model and an anonymous visitor is not a
 * `User`; it also lives in PostgreSQL until a prune command runs, while a chat session must die in
 * minutes and be droppable in bulk when a domain leaves a bot's allow-list. A Valkey key with a TTL
 * does both (`laravel-sanctum-auth`).
 *
 * NOT A JWT. Opaque, so it carries no claims a client could read and none this service would then
 * have to decide whether to trust. Everything about the session is server-side under a key the token
 * DERIVES; the token itself is 32 CSPRNG bytes and a routing prefix.
 *
 * ═══ ONLY THE DIGEST IS STORED ═════════════════════════════════════════════════════════════
 *
 * A Valkey dump must not be a set of live credentials. The key's last segment is a TRUNCATED digest
 * of the secret — enough to address the record — and the record carries the FULL digest, compared
 * with `hash_equals`, so a truncation collision cannot authenticate. The plaintext exists in the
 * response body and in the caller's memory, and nowhere else: no column, no log line, no audit
 * detail.
 *
 * ═══ EVERY REJECTION ON THE SDK SURFACE IS THE SAME 404 ════════════════════════════════════
 *
 * Unknown bot id, a bot in another organization, a bot that is not live, an absent `Origin`,
 * `Origin: null`, an unlisted origin, `https://<allowed>.evil.com`, a valid bot from the wrong
 * origin — one status and one body. A 403 on a foreign identifier answers "that bot id is real, your
 * domain just is not on its list", which is an enumeration oracle over every tenant's bots. The
 * `error_class` is `authorization` either way and nothing branches on the status
 * (`kb-error-taxonomy` footnote 1); `bootstrap/app.php`'s render closure produces the byte-identical
 * body, and `expect(...)->toDenyAsNotFound('sdk/v1')` is what proves the bodies match.
 *
 * `mint()` therefore returns NULL for every one of those, and the controller aborts — rather than
 * this class throwing eight different exceptions a caller could accidentally distinguish.
 *
 * ═══ THE ORIGIN IS PROVED AT MINT AND RE-VALIDATED AFTERWARDS, NEVER RE-PROVED ═════════════
 *
 * `mint()` runs on a POST from the CUSTOMER'S page, so its `Origin` is the embedder's and page
 * script cannot forge it. Every request after the handshake comes from the IFRAME, whose `Origin` is
 * always our own widget origin — so comparing a later request's `Origin` against the stored one
 * would 404 every chat message ever sent. What `resolve()` does instead is re-check the STORED
 * origin against the bot's LIVE allow-list and status, so removing a domain kills the session on the
 * next call rather than at the next TTL boundary.
 *
 * ═══ WHAT IS DELIBERATELY OUT OF SCOPE HERE ════════════════════════════════════════════════
 *
 * SIGNED USER METADATA (control 3) — the customer's backend HMAC-signing `{sub,name,email,iat,exp}`
 * with a per-bot shared secret. There is no per-bot secret column and no rotation story for one, so
 * `verifyUserToken()` is not written and `user_token` in the request body is accepted and IGNORED
 * rather than parsed. That is stated here because the failure mode of a half-built version is worse
 * than an absent one: identity claims presented as plain loader config are attacker-controlled by
 * definition, so a token that were "best-effort verified" would be a downgrade to anonymous wearing
 * an authenticated name.
 *
 * THE "IS THE CALLER OUR OWN SURFACE" CHECK. `laravel-sanctum-auth`'s reference has `resolve()`
 * compare the request's `Origin` against a fixed list of OUR embed origins. It is not implemented
 * here, deliberately and not by oversight: the value would have to come from configuration, an unset
 * or mis-set list denies hosted chat and the widget outright, and the shape people reach for to fix
 * that — skip the check when the list is empty — is a fail-open control that reads as a closed one.
 * The property it defends is already held by `config/cors.php`, which browsers enforce before a
 * response body can be read cross-origin, and by the bearer itself against everything that is not a
 * browser. Reported rather than half-built.
 *
 * ═══ TWO KINDS OF SESSION, ONE STORAGE FAMILY, ONE DISCRIMINATOR IN THE RECORD ═════════════
 *
 * `mint()` issues the WIDGET session described above. `mintPlayground()` issues the D5 admin
 * playground's, and it is the "different credential" `StreamChatMessageController` names when it
 * says the playground is what passes `diagnostics: true`. Both produce a `kbw_`-shaped bearer that
 * `rt/v1` resolves through THIS class and nothing else, which is what keeps the four-mechanisms
 * rule intact: `ResolveChatSession` still resolves exactly one credential TYPE. What changed is who
 * may be issued one and what the RECORD says about them.
 *
 * THE DISCRIMINATOR IS THE STORED `kind` FIELD AND IT IS NOT INFERABLE FROM THE TOKEN. Both tokens
 * are `kbw_{org}.{bot}.{32 CSPRNG bytes}` — deliberately identical, because a token whose SHAPE
 * announced its authority would tell anyone who saw one in a paste which of the two they had found.
 * The authority lives server-side under the key the token derives, exactly as everything else about
 * a session does.
 *
 * `actorType`, `diagnostics` and the participant column are all DERIVED from `kind` in `resolve()`,
 * not stored beside it. Three independent fields could disagree — a record claiming
 * `kind=widget, diagnostics=true` is the one shape that would hand `retrieval.trace` to a widget on
 * a stranger's marketing site — and there is no such record because there is no second field to
 * write. If the playground ever needs a variant WITHOUT diagnostics, it is a third `kind`, never a
 * flag beside this one.
 *
 * AND THE TWO BRANCHES ARE DISJOINT ON THE WAY BACK OUT. A widget record is re-checked against the
 * bot's published/public status and its live origin allow-list; a playground record is re-checked
 * against the bot's playground reachability and the ACTOR'S LIVE MEMBERSHIP AND PERMISSION. Neither
 * check can admit the other kind: a playground record carries `PLAYGROUND_ORIGIN`, which is not a
 * valid origin and matches no `bot_domains` row, so a playground session that somehow reached the
 * widget branch is REFUSED rather than admitted — and a widget record carries no `user_id`, so one
 * that reached the playground branch is refused there too.
 */
final readonly class WidgetSessionService
{
    /** The token's routing prefix. `kbw_` — the shipped loader and both stream clients pin it. */
    public const PREFIX = 'kbw_';

    /**
     * The two session kinds, as the stored `kind` field spells them.
     *
     * A CLOSED SET, CHECKED ON THE WAY OUT. `resolve()` refuses any value that is not one of these
     * two rather than falling through to a default branch, so a record written by something that
     * does not exist yet cannot pick up either branch's authority by accident.
     */
    public const KIND_WIDGET = 'widget';

    public const KIND_PLAYGROUND = 'playground';

    /**
     * What a chat-session token may attempt. THREE ABILITIES, ALL ON ONE BOT'S CONVERSATION.
     *
     * There is no admin verb here and none may be added: this token is minted with no human
     * authentication at all.
     *
     * @var list<string>
     */
    public const ABILITIES = ['chat:send', 'chat:read', 'feedback:submit'];

    /**
     * The playground's abilities: TWO, and `feedback:submit` is deliberately absent.
     *
     * NOT AN OVERSIGHT AND NOT A SUBSET FOR TIDINESS. §8.23 reports a satisfaction figure from
     * end-user feedback, and an operator rating their own test run puts a reviewer's thumb into a
     * number that is supposed to describe READERS. The shipped panel already renders no thumbs —
     * `apps/web/src/features/bots/bot-playground-panel.tsx` passes no `onFeedback` — and this is the
     * server-side half of the same decision, so the absence is a refusal rather than a missing
     * button. A playground caller that posts feedback anyway gets the surface's ordinary 404.
     *
     * IT STILL CARRIES NO ADMIN VERB. This token is minted BY an administrator, which is a different
     * thing from being minted WITH administrative authority: it reaches one bot's conversations on
     * the public runtime surface and nothing else, so a leaked one is worth what a widget token is
     * worth plus the retrieval trace for that one bot.
     *
     * @var list<string>
     */
    public const PLAYGROUND_ABILITIES = ['chat:send', 'chat:read'];

    /**
     * The `origin` a playground record carries, in place of an embedder that does not exist.
     *
     * ── IT IS DELIBERATELY NOT A VALID ORIGIN, AND THAT IS THE SAFETY PROPERTY ──────────────
     *
     * `App\Support\Web\ExactOrigin` normalises every `bot_domains.origin` into the RFC 6454
     * serialisation a browser actually sends, so no row in that table can ever equal this string.
     * `BotDomainMatcher::matches()` therefore returns false for it against every bot in the
     * platform — which means that if the playground branch in `resolve()` were deleted, refactored
     * away, or reached with the wrong `kind`, a playground session would land on the widget branch
     * and be REFUSED. The fallback direction is the closed one.
     *
     * The alternative — configuring the admin console's own origin here — is the fail-open shape
     * this class already refuses one paragraph up: an unset or mis-set value would either deny the
     * playground outright or, worse, be "fixed" by skipping the check when it is empty.
     *
     * It is also a real rate-limit subject: `ChatRateLimitScopes` HMACs it into the `origin` scope,
     * and `BotRateLimiter`'s key is `rl:{org_id}:{bot_id}:{scope}:…`, so every organization's
     * playground traffic sits in its own bucket rather than in a shared one.
     */
    public const PLAYGROUND_ORIGIN = 'kb-internal:playground';

    /**
     * Crockford base32, 26 characters, excluding I, L, O and U — CASE-INSENSITIVELY.
     *
     * ── THE `/i` IS LOAD-BEARING AND ITS ABSENCE WOULD 401 EVERY SESSION EVER MINTED ────────
     *
     * `Str::ulid()` returns an UPPERCASE string, and `HasUlids::newUniqueId()` — which every model in
     * this schema uses — returns `strtolower()` of it. So every organization id and every bot id in
     * the database is LOWER case, and an uppercase-only pattern here rejects the routing segments of
     * a token this service itself produced.
     *
     * That is the same trap `laravel-sanctum-auth`'s reference names one variant over: it warns that
     * `ctype_digit` would reject every real token because ids are ULIDs and not integers. Getting the
     * CASE wrong has the identical shape and the identical symptom — a 401 on a credential that is
     * perfectly valid — and it is invisible to any test whose fixture happens to build ids the same
     * wrong way.
     *
     * `Str::isUlid()` (the framework's own `ulid` validation rule) is case-insensitive for the same
     * reason, so this agrees with the rule the FormRequests apply.
     */
    private const ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/i';

    /**
     * The mint, as one atomic script.
     *
     * `HSET` then `EXPIRE` as two round trips is a WINDOW: die in between and the session is
     * IMMORTAL, which on a credential is not a stranded key but a bearer that never stops working.
     * `valkey-keyspaces` names this family on the "must be one command" side of that rule, and a
     * script is how a hash gets there.
     *
     * THIS IS VALKEY'S `EVAL`, NOT PHP'S. It is a compile-time constant with no interpolation of any
     * kind — every value crosses as a bound KEYS/ARGV argument, exactly as a prepared statement's
     * parameters do, so there is no code path by which caller data becomes script text.
     *
     * `kind` AND `user_id` ARE WRITTEN BY THE SAME COMMAND AS THE REST, which is the reason they are
     * here rather than in a follow-up `HSET`: a record whose discriminator arrived a round trip
     * after its `token_hash` is a live credential with no kind for the width of that window, and
     * `resolve()` would have to decide what an absent one means on a record it can authenticate.
     */
    private const MINT_SCRIPT = <<<'LUA'
        redis.call('HSET', KEYS[1],
            'org_id', ARGV[1], 'bot_id', ARGV[2], 'origin', ARGV[3],
            'issued_at', ARGV[4], 'token_hash', ARGV[5], 'abilities', ARGV[6],
            'kind', ARGV[7], 'user_id', ARGV[8])
        redis.call('EXPIRE', KEYS[1], ARGV[9])
        return 1
    LUA;

    public function __construct(
        private RedisFactory $redis,
        private ChatConfigurationRepositoryInterface $configuration,
        private BotDomainMatcher $domains,
        // THE PLAYGROUND'S REVOCATION STORY, and the exact counterpart of the widget's live
        // allow-list re-read. `laravel-sanctum-auth` NN1: a record written in the past is not
        // evidence of CURRENT membership, so a demoted or removed administrator's playground
        // credential must stop working on the NEXT request rather than at the next TTL boundary.
        private MembershipRepositoryInterface $memberships,
    ) {}

    /**
     * `POST /sdk/v1/session`. Returns the token and its lifetime, or NULL for every rejection.
     *
     * @param  string|null  $origin  the request `Origin` HEADER. Never a body field, never a query
     *                               parameter: those are page script's to choose, and the whole
     *                               value of this header is that it is not.
     * @return array{token: string, expires_in: int}|null
     */
    public function mint(string $publicBotId, ?string $origin): ?array
    {
        $bot = $this->configuration->botByPublicId($publicBotId);

        if ($bot === null || ! $this->isLive($bot)) {
            return null;
        }

        if (! $this->domains->matches($bot, $origin)) {
            return null;
        }

        $minted = $this->store(
            (string) $bot->organization_id,
            (string) $bot->id,
            self::KIND_WIDGET,
            (string) $origin,
            self::ABILITIES,
            null,
        );

        return ['token' => $minted['token'], 'expires_in' => $minted['expires_in']];
    }

    /**
     * The D5 ADMIN PLAYGROUND's credential. Every check has already passed when this is called.
     *
     * ═══ THIS METHOD AUTHORIZES NOTHING, AND THAT IS DELIBERATE ════════════════════════════
     *
     * `mint()` above returns NULL for eight different rejections because its caller is an
     * unauthenticated loader on a page we do not control and every one of those rejections must
     * render as the same 404. THIS caller is an authenticated administrator on the admin surface,
     * where the six checks are the route's and the controller's — `auth:sanctum`, `org.member`,
     * `Gate::authorize('update', $bot)`, the scoped binding, the organization's and the bot's
     * status, and `throttle:admin` — and each of them has its OWN correct status code. Duplicating
     * any of them here would produce a second opinion that can drift from the first, and collapsing
     * them into a null return would throw away the 403/409 distinction the admin surface exists to
     * make.
     *
     * So it takes the already-authorized `Bot` and the already-authorized actor id, and writes a
     * record. The one thing it does NOT take is a TTL, an ability list or a kind: those are this
     * class's, because they are what the resolved session's authority is made of.
     *
     * ═══ WHAT MAKES THE ISSUED CREDENTIAL SAFE IS THE RE-READ, NOT THE MINT ═══════════════
     *
     * The permission is checked again on EVERY request the bearer is used on — see `resolve()`'s
     * playground branch — so this row is a pointer to a live authorization rather than a snapshot of
     * a past one. That is the same property `mint()` gets from re-validating the origin allow-list,
     * and it is why neither token needs to be short-lived to be revocable.
     *
     * @param  string  $actorId  the administrator's `users.id`. It becomes `conversations.user_id`
     *                           and the internal request's actor id — never a tenant SCOPE, which is
     *                           the organization's job.
     * @return array{token: string, expires_in: int, session_id: string}
     */
    public function mintPlayground(Bot $bot, string $actorId): array
    {
        return $this->store(
            (string) $bot->organization_id,
            (string) $bot->id,
            self::KIND_PLAYGROUND,
            self::PLAYGROUND_ORIGIN,
            self::PLAYGROUND_ABILITIES,
            $actorId,
        );
    }

    /**
     * Write one session record and return the bearer it derives. THE ONLY WRITER OF THIS FAMILY.
     *
     * Both mints land here so the key grammar, the digest, the atomic HSET+EXPIRE and the token
     * spelling exist once. A second writer is a second opinion about what a session record is, and
     * the field most likely to differ between two of them is the one that decides authority.
     *
     * @param  list<string>  $abilities
     * @return array{token: string, expires_in: int, session_id: string}
     */
    private function store(
        string $organizationId,
        string $botId,
        string $kind,
        string $origin,
        array $abilities,
        ?string $actorId,
    ): array {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $ttl = $this->ttl($kind);

        // ONE COMMAND FOR THE FIELDS AND THE TTL. `HSET` then `EXPIRE` is two round trips and a
        // window: die in between and the session is IMMORTAL, which on a credential is not a stranded
        // key but a bearer that never stops working. `valkey-keyspaces` names this family on the
        // "must be one command" side of that rule; a script is how a hash gets there.
        //
        // THIS IS VALKEY'S `EVAL`, NOT PHP'S. The script below is a compile-time constant with no
        // interpolation of any kind — every value crosses as a bound KEYS/ARGV argument, exactly as
        // a prepared statement's parameters do, so there is no code path by which caller data
        // becomes script text. `BotRateLimiter` uses the identical idiom for the identical reason.
        $connection = $this->connection();

        // ONE FLAT VARIADIC LIST, with the key count telling the server where KEYS ends and ARGV
        // begins — `PhpRedisConnection::eval($script, $numberOfKeys, ...$arguments)`. Getting the
        // count wrong does not raise: Lua simply sees fewer KEYS and more ARGV, and the script reads
        // a field name out of a key.
        $arguments = [
            $this->key($organizationId, $botId, $secret),
            $organizationId,
            $botId,
            $origin,
            (string) time(),
            // THE DIGEST, NEVER THE SECRET. The key already holds a truncation of this value; the
            // full one is here so a collision on the truncation cannot authenticate.
            hash('sha256', $secret),
            implode(',', $abilities),
            $kind,
            // AN EMPTY STRING AND NOT A MISSING FIELD for a widget session: `HSET` cannot write a
            // null, and a field whose ABSENCE meant "anonymous" would make "no actor" and "the
            // record was written by an older build" the same observation. `resolve()` reads `''` as
            // "no actor" explicitly.
            $actorId ?? '',
            (string) $ttl,
        ];

        // THE SUPPRESSION, AND IT IS THE SAME ONE `BotRateLimiter` CARRIES FOR THE SAME REASON.
        // Larastan resolves the Factory's `connection()` to the base `Connection`, whose `__call`
        // forwards to the phpredis client — so the analyser matches phpredis' own
        // `eval($script, $args, $numkeys)` rather than
        // `PhpRedisConnection::eval($script, $numberOfKeys, ...$arguments)`, which is the method
        // actually invoked and which re-orders the arguments for phpredis itself.
        //
        // Scoped to this one line, per phpstan.neon's rule about suppressions — and the connection
        // is resolved on its own line first, because the directive applies to the line that FOLLOWS
        // it and a multi-line fluent call puts the reported expression on a later one.
        // @phpstan-ignore-next-line
        $connection->eval(self::MINT_SCRIPT, 1, ...$arguments);

        // The org and bot segments are PUBLIC and UNTRUSTED — they only route the lookup, and a
        // forged pair simply misses. The secret is what authenticates.
        return [
            'token' => self::PREFIX.$organizationId.'.'.$botId.'.'.$secret,
            'expires_in' => $ttl,
            // DERIVABLE FROM THE BEARER AND USELESS WITHOUT IT. Returned so the caller can write it
            // to an audit row — which is what ties "who was issued a playground credential" to the
            // `rl:` buckets and the log lines that later name the same session — WITHOUT the caller
            // ever holding a reason to touch the token.
            'session_id' => $this->sessionId($secret),
        ];
    }

    /**
     * The guard path, run before anything touches a bot, a conversation, or a provider.
     *
     * ── THE TWO REJECTION SHAPES ARE DIFFERENT AND BOTH ARE CORRECT ────────────────────────
     *
     * A MALFORMED OR EXPIRED BEARER IS 401 `authentication`: the caller presented no valid proof of
     * identity, which is a different fact from "you may not do this", and the client acts on it
     * differently — the widget's refresh flow triggers on `401` + `error_class: authentication` and
     * on nothing else, so rendering it as a 404 would leave a live visitor with a dead composer and
     * no re-mint.
     *
     * A VALID BEARER WHOSE BOT HAS GONE AWAY IS 404 `authorization`: the bot was archived, the
     * domain was removed from its allow-list, or the row now belongs to another organization. That
     * is the enumeration-sensitive case and it renders as the same 404 as every other SDK rejection.
     *
     * A PLAYGROUND BEARER WHOSE ACTOR LOST THE PERMISSION IS THE SAME 404, and the split reads the
     * same way: the credential is intact, the authorization behind it is not. It is a 404 rather
     * than a 403 because this is the PUBLIC RUNTIME surface — the status is a property of the
     * surface, not of the actor (`kb-error-taxonomy` footnote 1), and the `error_class` is
     * `authorization` either way.
     *
     * @throws AuthenticationException 401
     */
    public function resolve(#[SensitiveParameter] ?string $bearer): WidgetSession
    {
        if ($bearer === null || ! str_starts_with($bearer, self::PREFIX)) {
            throw new AuthenticationException;
        }

        [$organizationId, $botId, $secret] = array_pad(
            explode('.', substr($bearer, strlen(self::PREFIX)), 3),
            3,
            null,
        );

        // ULID, NOT AN INTEGER. Every id here is a 26-character Crockford base32 ULID, so a
        // `ctype_digit` check — the shape this is most often written as — would reject every real
        // token and 401 every session ever minted.
        if (! is_string($secret) || $secret === ''
            || ! is_string($organizationId) || preg_match(self::ULID, $organizationId) !== 1
            || ! is_string($botId) || preg_match(self::ULID, $botId) !== 1) {
            throw new AuthenticationException;
        }

        $key = $this->key($organizationId, $botId, $secret);
        $connection = $this->connection();

        /** @var array<string, string> $record */
        $record = $connection->hgetall($key);

        if ($record === [] || ! isset($record['token_hash'], $record['org_id'], $record['bot_id'], $record['origin'])) {
            throw new AuthenticationException;   // expired, revoked, or never existed
        }

        // The key is a TRUNCATED digest, so confirm the full one — timing-safely, always, on a
        // credential. `hash_equals` and never `===`: a byte-wise compare leaks the digest one byte
        // at a time to anyone who can measure the response.
        if (! hash_equals($record['token_hash'], hash('sha256', $secret))) {
            throw new AuthenticationException;
        }

        // The record's own scope wins over the token's routing segments, which are attacker-supplied
        // and only ever addressed the lookup. Comparing them is what makes a forged pair a miss
        // rather than a session under someone else's organization.
        if (! hash_equals($record['org_id'], $organizationId) || ! hash_equals($record['bot_id'], $botId)) {
            throw new AuthenticationException;
        }

        // THE DISCRIMINATOR, READ BEFORE ANY AUTHORITY IS DERIVED FROM IT, AND CHECKED AGAINST A
        // CLOSED SET. An absent field means a record written before `kind` existed, and it reads as
        // `widget` — the NARROWER of the two branches, since the widget path additionally demands
        // published + public + an allow-listed origin. Any other value is refused outright: a branch
        // chosen by a value nobody wrote is a branch nobody reviewed.
        $kind = $record['kind'] ?? self::KIND_WIDGET;

        if ($kind !== self::KIND_WIDGET && $kind !== self::KIND_PLAYGROUND) {
            throw new AuthenticationException;
        }

        $bot = $this->configuration->botForOrg($record['org_id'], $record['bot_id']);

        if ($bot === null) {
            abort(404);
        }

        // AN EMPTY STRING IS "NO ACTOR", EXPLICITLY. `HSET` cannot store a null, so the mint writes
        // `''` for an anonymous session; reading it back as null here is what keeps "anonymous" and
        // "field missing" from being the same observation.
        $actorId = ($record['user_id'] ?? '') === '' ? null : $record['user_id'];

        // ── THE TWO RE-READS, ONE PER KIND, AND NEITHER CAN ADMIT THE OTHER'S RECORD ───────────
        //
        // RE-VALIDATED AGAINST THE LIVE ROW ON EVERY REQUEST, never against the snapshot: a removed
        // domain, an archived bot, a flipped access mode, a revoked membership or a demoted role
        // must stop working NOW, not when the TTL lapses. This is the whole revocation story for a
        // credential with no database row.
        $permitted = $kind === self::KIND_PLAYGROUND
            ? $this->playgroundStillPermitted($bot, $actorId)
            : $this->isLive($bot) && $this->domains->matches($bot, $record['origin']);

        if (! $permitted) {
            abort(404);
        }

        // TIER 1 OF THE REFRESH FLOW: the TTL slides on every AUTHORIZED request, so an active
        // conversation can never cross it — the chat deadline is 60 s, three orders of magnitude
        // inside the window. Sliding only AFTER every check above is what keeps this from becoming a
        // way to hold a revoked session warm. It slides by the KIND'S OWN lifetime, so a playground
        // credential cannot be held warm for the widget's half hour.
        $connection->expire($key, $this->ttl($kind));

        $playground = $kind === self::KIND_PLAYGROUND;

        return new WidgetSession(
            organizationId: $record['org_id'],
            botId: $record['bot_id'],
            // The key's last segment: derivable from the bearer, useless alone, and therefore safe
            // as a rate-limit subject and in a log line.
            sessionId: $this->sessionId($secret),
            embedderOrigin: $record['origin'],
            abilities: array_values(array_filter(explode(',', $record['abilities'] ?? ''))),
            // ── ALL THREE DERIVED FROM ONE STORED FIELD ────────────────────────────────────────
            //
            // See the class docblock: three independently stored fields could disagree, and the
            // disagreement that matters — `kind=widget` with `diagnostics=true` — is a
            // `retrieval.trace` frame rendered on a stranger's marketing site. There is no second
            // field to write, so there is no such record.
            actorType: $playground ? ActorType::User : ActorType::AnonymousSession,
            userId: $playground ? $actorId : null,
            diagnostics: $playground,
        );
    }

    /**
     * A bot answers only when its status AND its access mode both say so.
     *
     * TWO CONDITIONS AND NOT ONE. `published` is "this bot is live"; `public` is "anonymous visitors
     * may talk to it". A private published bot is a real configuration — an internal helpdesk bot
     * reachable only from an authenticated surface — and treating status alone as the gate would
     * make every one of them world-reachable through the widget.
     */
    private function isLive(Bot $bot): bool
    {
        return $bot->status->isRetrievable() && $bot->access_mode === BotAccessMode::Public;
    }

    /**
     * May this actor STILL run this bot from the playground? Re-read on every request.
     *
     * ── THREE CONJUNCTS, AND THE LAST TWO ARE THE REVOCATION STORY ───────────────────────────
     *
     *   THE BOT'S STATUS   `isPlaygroundReachable()` — `testing` or `published`. A bot moved back to
     *                      `draft`, paused or archived stops answering here on the next request, the
     *                      same way an archived bot stops answering the widget.
     *   THE MEMBERSHIP     re-read from PostgreSQL for THIS record's organization. A removed or
     *                      suspended member's credential dies immediately; `grants()` ANDs the
     *                      ACTIVE status itself, so a suspended row cannot satisfy this.
     *   THE PERMISSION     `bots.manage`, the same permission `BotPolicy::update()` demands at the
     *                      mint. An administrator demoted to analyst between the mint and the next
     *                      turn is refused, which is the case a mint-time-only check misses entirely
     *                      and the case `laravel-sanctum-auth` NN1 is about.
     *
     * NO `access_mode` CONDITION, and its absence is a decision. `public` means "anonymous visitors
     * may talk to it"; a PRIVATE bot is exactly the configuration an operator most needs to test
     * before exposing it, and gating the playground on it would make a private bot untestable by the
     * people who own it. The anonymous half is `isLive()`'s, one method up, and stays there.
     *
     * NO ORIGIN CONDITION either: there is no embedder. `PLAYGROUND_ORIGIN` is stored so the record
     * has the shape every other record has and so the rate limiter has a subject, and it is chosen
     * to be unmatchable precisely so it cannot become one by accident.
     */
    private function playgroundStillPermitted(Bot $bot, ?string $actorId): bool
    {
        if ($actorId === null || ! $bot->status->isPlaygroundReachable()) {
            return false;
        }

        return $this->memberships
            ->find((string) $bot->organization_id, $actorId)
            ?->grants(Permission::BotsManage) === true;
    }

    /**
     * `sess:{org_id}:{bot_id}:{session_id}` — the catalog's pattern, unmodified.
     *
     * The session id is a one-way function of the secret, so the key is derivable from the bearer
     * and the bearer is not derivable from the key. NO SEGMENT COMES FROM A USER-CHOSEN STRING: two
     * ULIDs and a hex digest.
     */
    private function key(string $organizationId, string $botId, #[SensitiveParameter] string $secret): string
    {
        return "sess:{$organizationId}:{$botId}:".$this->sessionId($secret);
    }

    private function sessionId(#[SensitiveParameter] string $secret): string
    {
        return substr(hash('sha256', $secret), 0, 32);
    }

    /**
     * The lifetime for one KIND of session, and there is no default argument on purpose.
     *
     * A default would make the widget's half hour the value every future call site inherits by
     * omission, and the playground's credential is the one that carries diagnostics. Making the kind
     * a required argument means a new kind cannot silently adopt another kind's lifetime.
     */
    private function ttl(string $kind): int
    {
        return $kind === self::KIND_PLAYGROUND
            ? (int) config('kb.playground.session_ttl_seconds')
            : (int) config('kb.widget.session_ttl_seconds');
    }

    /**
     * `coordination`, and never the cache store.
     *
     * A session record is ABSENCE-SENSITIVE in the direction that matters: an evicted record reads as
     * "expired", which logs a live visitor out mid-conversation — annoying — but the family shares an
     * instance with the locks and the rate-limit counters, and every one of THOSE fails OPEN when its
     * key disappears. `config/database.php` names `coordination` as the non-evicting connection for
     * exactly this set.
     */
    private function connection(): Connection
    {
        return $this->redis->connection('coordination');
    }
}
