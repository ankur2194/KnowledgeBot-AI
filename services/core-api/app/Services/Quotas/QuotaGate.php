<?php

declare(strict_types=1);

namespace App\Services\Quotas;

use App\Enums\QuotaMetric;
use App\Exceptions\KbException;
use App\Models\Bot;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\ThrottleRequestsException;

/**
 * THE ADMISSION GATE. Two entry points, because its two callers arrive at different times.
 *
 * ═══ ENTRY POINT 1 — `assertUploadPermitted()`, WIRED NOW ═══════════════════════════════════
 *
 * Called from `App\Services\Sources\Upload\UploadIntake::screen()`, which is
 * `kb-security-baseline`'s six-step upload gate, BEFORE any byte is written to object storage and
 * before the source row exists. It checks the storage allowance against the batch's total size.
 *
 * ═══ ENTRY POINT 2 — `assertChatTurnPermitted()`, IMPLEMENTED AND CALLED BY NOTHING ═════════
 *
 * READ THIS BEFORE CONCLUDING IT IS DEAD CODE. It is complete, it is tested, and NOTHING IN THIS
 * REPOSITORY CALLS IT, deliberately: the caller is **Phase 4's public chat surface (`rt/v1`), which
 * is not written yet**. That controller calls this method inside its authorization gate, BEFORE THE
 * FIRST BYTE GOES OUT — before `InternalAiClient` is asked for a stream, before a provider
 * credential is decrypted, before an SSE response is opened.
 *
 * THE ORDER IS THE PROPERTY AND IT IS THE REASON THIS METHOD EXISTS RATHER THAN A CHECK INSIDE THE
 * RELAY. Once the stream is open the refusal has to be an SSE `error` frame on a 200 response, the
 * provider call has already been made, and the tokens the quota was protecting have already been
 * spent. A quota checked after the first byte is a quota that reports rather than one that refuses.
 *
 * DO NOT "WIRE IT UP" BY INVENTING A CHAT CONTROLLER. The surface it belongs to has its own
 * authentication (a resolved chat session or a validated embed origin), its own deny status (404,
 * not 403 — it is enumeration-sensitive) and its own rate-limit scopes; building any of that here
 * would be building Phase 4 badly in the wrong file.
 *
 * ═══ WHAT A BREACH RAISES, AND WHY IT CAN NEVER FALL BACK ══════════════════════════════════
 *
 * `tenant_quota`: **403, never retryable, never fallback-eligible** (`kb-error-taxonomy`; §8.7 lists
 * it among the classes that may never fall back). The fallback ban is enforced by WHERE this runs
 * rather than by a flag — the refusal happens in Laravel before any provider attempt exists, so
 * there is nothing to fall back FROM. Falling back on a quota breach would spend the budget twice on
 * the request that was refused for spending too much.
 *
 * ═══ THE DECISION PROCEDURE, AND WHY IT ASKS POSTGRESQL BEFORE IT SAYS YES ═════════════════
 *
 * For each metric in play:
 *
 *   1. UNMETERED (`limit === null`) → skip entirely. No counter read, no query. An organization with
 *      no plan is not frozen, and a `0` ceiling is a different, real, refusing state.
 *   2. THE CACHED LOWER BOUND ALREADY BREACHES → refuse, WITHOUT touching PostgreSQL. Sound because
 *      the counter can only ever be LOW (see `QuotaCounters`), so `cached >= limit` implies
 *      `true >= limit`. This is the branch that fires under abuse, which is exactly when the
 *      aggregate is most expensive.
 *   3. OTHERWISE → read PostgreSQL, which is the truth, and decide on that.
 *
 * Step 3 is not an optimisation this class skipped: `valkey-keyspaces`' catalog row for the `quota:`
 * family requires it in as many words — *"a stale value never authorizes an over-quota action — the
 * enforcing check re-reads the primary."*
 */
final readonly class QuotaGate
{
    public function __construct(
        private QuotaCounters $counters,
        private BotRateLimiter $rateLimiter,
    ) {}

    /**
     * ENTRY POINT 1 — before an upload is admitted.
     *
     * @param  int  $incomingBytes  the total size of the batch about to be written. It is the WHOLE
     *                              batch and not one file: `UploadIntake` is all-or-nothing, so
     *                              admitting file by file would let a batch cross the ceiling in the
     *                              middle and then be rolled back with the quota already charged.
     *
     * @throws KbException `tenant_quota` (403)
     */
    public function assertUploadPermitted(
        Organization $organization,
        int $incomingBytes,
        ?CarbonImmutable $at = null,
    ): void {
        $this->assertWithin(
            $organization,
            QuotaMetric::StorageBytes,
            $incomingBytes,
            'This organization has reached its storage allowance, so no further files can be '
            .'stored. Delete or archive sources you no longer need, or ask an owner to raise the '
            .'storage limit.',
            $at,
        );
    }

    /**
     * ENTRY POINT 2 — before the first byte of a chat answer goes out.
     *
     * CALLED BY NOTHING TODAY. Phase 4's `rt/v1` chat controller is its caller and does not exist;
     * see the class docblock for why that is deliberate and why inventing the controller here would
     * be the wrong repair.
     *
     * ── THE ORDER OF THE THREE CHECKS IS DELIBERATE ─────────────────────────────────────────
     *
     * RATE LIMIT FIRST, THEN THE TOKEN QUOTA. The rate limiter is one round trip to Valkey and
     * refuses the abusive case; the token quota can cost an aggregate query. Checking the expensive
     * one first would let a caller who is already rate-limited drive an unbounded number of
     * aggregate queries by retrying, which turns the rate limiter into a thing that protects
     * everything except the check in front of it.
     *
     * ── `$estimatedTokens` IS A PRE-CHARGE AND IS NOT THE ACCOUNTING ────────────────────────
     *
     * The real token count is not knowable before the turn runs, so this admits against usage
     * ALREADY RECORDED plus whatever the caller can estimate. The ledger row is written afterwards
     * by `UsageRecorder`, from the `provider_calls` row, which is the only number anybody is billed
     * on. Passing 0 is legitimate and means "refuse only an organization that is already over" —
     * which is the correct behaviour for a first turn on a fresh period.
     *
     * ── PHASE 4 WIRED THIS UP AND ADDED THE PLATFORM SCOPES, WHICH ARE A DIFFERENT CLASS ────
     *
     * `$scopes` carries the origin, session and IP subjects of `kb-security-baseline` §18.5. They
     * ride the SAME `EVALSHA` as the bot's own windows — see `BotRateLimiter::charge()` — so the
     * four-scope decision stays atomic and costs one round trip, and a caller cannot sit just under
     * each individual limit while being over the combination.
     *
     * Their breach is `rate_limit` (429 + `Retry-After`) and NOT `tenant_quota`, because they are
     * the PLATFORM speaking rather than a ceiling the tenant chose. `refuseRateLimit()` branches on
     * the decision's scope and argues both halves.
     *
     * @throws KbException `tenant_quota` (403) for the token allowance and for the bot's own rate
     *                     limits; `rate_limit` (429) for the three platform scopes.
     */
    public function assertChatTurnPermitted(
        Organization $organization,
        Bot $bot,
        int $estimatedTokens = 0,
        ?CarbonImmutable $at = null,
        ?ChatRateLimitScopes $scopes = null,
    ): void {
        $decision = $this->rateLimiter->charge($bot, 1, $at, $scopes);

        if (! $decision->allowed) {
            $this->refuseRateLimit($decision);
        }

        $this->assertWithin(
            $organization,
            QuotaMetric::MonthlyTokens,
            $estimatedTokens,
            'This organization has reached its monthly token allowance, so this bot cannot answer '
            .'until the allowance resets or an owner raises it.',
            $at,
        );
    }

    /**
     * The state of every metric, for the quota screen.
     *
     * AUTHORITATIVE ON EVERY METRIC — this reads PostgreSQL rather than the cached lower bound, and
     * that is a deliberate cost. A dashboard is where somebody decides whether to buy more, and a
     * number that is silently 60 seconds low is a number they will reconcile against an invoice and
     * find wrong. The read refreshes the cache as a side effect, which is what keeps the refusal
     * shortcut warm for the request path.
     *
     * @return array<string, QuotaUsage> keyed by `QuotaMetric->value`
     */
    public function snapshot(Organization $organization, ?CarbonImmutable $at = null): array
    {
        $limits = QuotaLimits::fromOrganization($organization);
        $out = [];

        foreach (QuotaMetric::cases() as $metric) {
            $usage = $this->counters->authoritative(
                $organization->organizationId(),
                $metric,
                $at,
            );

            $out[$metric->value] = new QuotaUsage(
                $metric,
                $usage->used,
                $limits->limitFor($metric),
                $usage->source,
            );
        }

        return $out;
    }

    /**
     * The three-step procedure from the class docblock, for one metric.
     *
     * @throws KbException `tenant_quota` (403)
     */
    private function assertWithin(
        Organization $organization,
        QuotaMetric $metric,
        int $additional,
        string $message,
        ?CarbonImmutable $at,
    ): void {
        $limit = QuotaLimits::fromOrganization($organization)->limitFor($metric);

        // STEP 1. Unmetered: no counter read, no query, no comparison against zero.
        if ($limit === null) {
            return;
        }

        $organizationId = $organization->organizationId();

        // STEP 2. The cached lower bound. A refusal here is sound because the counter can only be
        // low; an admission here is NOT, which is why this branch only ever refuses.
        $cached = $this->counters->cachedLowerBound($organizationId, $metric);

        if ($cached !== null) {
            $bound = new QuotaUsage($metric, $cached->used, $limit, QuotaUsage::SOURCE_CACHE);

            if ($bound->exceeded() || $bound->wouldExceed($additional)) {
                throw KbException::tenantQuota($message);
            }
        }

        // STEP 3. The truth. Nothing is admitted on a cached value.
        $authoritative = $this->counters->authoritative($organizationId, $metric, $at);
        $usage = new QuotaUsage($metric, $authoritative->used, $limit, $authoritative->source);

        if ($usage->exceeded() || $usage->wouldExceed($additional)) {
            throw KbException::tenantQuota($message);
        }
    }

    /**
     * A per-bot rate-limit breach, rendered as `tenant_quota` (403) and NOT as `rate_limit` (429).
     *
     * ── THIS IS THE ONE CLASSIFICATION DECISION ON THIS SURFACE THAT IS ARGUABLE, SO IT IS
     *    ARGUED ────────────────────────────────────────────────────────────────────────────
     *
     * `bots.rate_limit_per_minute` and `rate_limit_per_day` are not a platform protection measure —
     * they are a number the ORGANIZATION sets on its own bot, in the same admin form as the model
     * and the retrieval configuration. They exist so a tenant can cap what one of its bots may spend.
     * That is the same kind of fact as the monthly token allowance, one level down, and a client
     * reaching one has reached a ceiling its own organization chose.
     *
     * `rate_limit` (429) is the platform saying "you are going too fast for us, wait and retry", and
     * it is RETRYABLE — `apps/web`'s query client runs a full backoff ladder on that field. Applying
     * it here would tell a caller to hammer a per-day limit that will not clear for hours.
     *
     * `tenant_quota` (403) says "this allowance is exhausted", is non-retryable, and is never
     * fallback-eligible, which is all three of the things that are true.
     *
     * THE PLATFORM'S OWN THROTTLING IS NOW DECIDED HERE TOO, AND IT IS THE OTHER CLASS. The origin,
     * session and IP scopes of `kb-security-baseline` §18.5 are the platform saying "you are going
     * too fast for us", which is `rate_limit` (429) WITH a `Retry-After` and IS retryable — the
     * opposite verdict from the paragraph above, for the opposite reason. Two different facts, two
     * classes, one method, and the decision's `scope` is what tells them apart.
     *
     * THE STATUSES ARE NOT INTERCHANGEABLE EVEN THOUGH BOTH REFUSE. `apps/web`'s query client runs a
     * full backoff ladder on `retryable`, so a per-day tenant ceiling rendered as 429 would have the
     * client hammer a limit that will not clear for hours; and a platform burst rendered as 403
     * would tell a legitimate visitor their allowance is exhausted when waiting four seconds would
     * have worked.
     *
     * @throws KbException always
     */
    private function refuseRateLimit(RateLimitDecision $decision): never
    {
        if ($decision->scope !== RateLimitDecision::SCOPE_BOT) {
            // A `ThrottleRequestsException` AND NOT A `KbException`, WHICH IS THE ONE PLACE IN THIS
            // CLASS THAT REACHES FOR THE FRAMEWORK'S EXCEPTION. The reason is `Retry-After`: the
            // envelope's contract carries it as a HEADER, `bootstrap/app.php` copies it off the
            // exception with `array_intersect_key($e->getHeaders(), ['Retry-After' => true])` — and
            // that branch is guarded on `HttpExceptionInterface`, which `KbException` deliberately
            // is not. A `KbException` here would render a 429 with the header missing and the client
            // would have nothing but prose to parse.
            //
            // The render closure then produces exactly the right envelope from the status alone:
            // `429 -> ['rate_limit', 429]`, and `retryable` comes from `ErrorTaxonomy::RETRYABLE`
            // (true) rather than from anything decided here.
            //
            // THE SUBJECT IS NOT NAMED. "your IP", "this origin" and "this session" would each tell
            // an abuser which axis they tripped and therefore which one to vary; the sentence says
            // what to do and nothing about how the decision was reached.
            throw new ThrottleRequestsException(
                'Too many messages in a short time. Wait a moment and try again.',
                null,
                ['Retry-After' => (string) ($decision->retryAfterSeconds ?? 60)],
            );
        }

        $window = $decision->window === 'minute' ? 'per-minute' : 'per-day';

        throw KbException::tenantQuota(
            "This bot has reached its {$window} request limit of {$decision->limit}. The limit is "
            .'set on the bot itself; raise it in the bot configuration, or wait for the window to '
            .'reset.',
        );
    }
}
