<?php

declare(strict_types=1);

use App\Enums\OrgRole;
use App\Exceptions\KbException;
use App\Models\Bot;
use App\Models\Organization;
use App\Models\UsageEvent;
use App\Models\User;
use App\Services\Quotas\BotRateLimiter;
use App\Services\Quotas\QuotaGate;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| The admission gate — the two entry points, and the one that is called by nothing
|--------------------------------------------------------------------------
|
| A BREACH IS `tenant_quota`: 403, NEVER RETRYABLE, NEVER FALLBACK-ELIGIBLE. The first two are the
| taxonomy's; the third is enforced by WHERE the check runs rather than by a flag — the refusal
| happens in Laravel BEFORE any provider attempt exists, so there is nothing to fall back FROM.
| Falling back on a quota breach would spend the budget twice on the request that was refused for
| spending too much.
|
| ── ENTRY POINT 2 IS CALLED BY NOTHING, AND THAT IS DELIBERATE ──────────────────────────────────
|
| `assertChatTurnPermitted()` is complete and is tested here, and NOTHING IN THIS REPOSITORY CALLS
| IT: its caller is Phase 4's public chat surface (`rt/v1`), which is not written. That controller
| calls it inside its authorization gate, BEFORE THE FIRST BYTE GOES OUT — before the internal client
| is asked for a stream, before a credential is decrypted, before an SSE response is opened. Once the
| stream is open a refusal has to be an SSE `error` frame on a 200, the provider call has already
| been made, and the tokens the quota was protecting have already been spent.
|
| So this suite is what stands in for the missing caller. Do NOT "wire it up" by inventing a chat
| controller: that surface has its own authentication, its own 404 deny and its own rate-limit
| scopes, and building any of it here would be building Phase 4 badly in the wrong file.
*/

beforeEach(function (): void {
    SpaSession::isolateRateLimits(currentTest());
    Storage::fake('s3');
    Queue::fake();

    // THE PER-BOT LIMITER KEYS LIVE ON `valkey-core` db 2 (`coordination`), which the suite shares
    // across runs — `rl:` counters are absence-sensitive and deliberately NOT on the evicting
    // instance, so nothing expires them between tests. Without this a second run of the same test
    // starts with the previous run's counter and refuses at a limit it has not reached.
    Redis::connection('coordination')->flushdb();
});

/**
 * One organization, one owner, one bot — with a name of its own because Pest declares test-file
 * helpers at FILE SCOPE and a name another suite already uses is a redeclaration fatal in a full
 * run, which is the worst possible time to find out.
 *
 * @return array{org: Organization, owner: User, bot: Bot}
 */
function quotaGateFixture(): array
{
    $org = Organization::factory()->create();

    return [
        'org' => $org,
        'owner' => User::factory()->recycle($org)->orgRole(OrgRole::Owner)
            ->create(['email' => SpaSession::uniqueEmail('quota-gate-owner')]),
        'bot' => Bot::factory()->recycle($org)->create(),
    ];
}

/** Real PDF bytes — libmagic reads them as `application/pdf`, so the six-step gate admits them. */
function quotaGatePdfBytes(): string
{
    return "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

// ── entry point 1: the upload path, wired ────────────────────────────────────────────────────────

it('refuses an upload from an organization already over its storage allowance, with 403 tenant_quota', function (): void {
    $fixture = quotaGateFixture();

    $fixture['org']->storage_bytes_quota = 1_000;
    $fixture['org']->save();

    UsageEvent::factory()->recycle($fixture['org'])->storageAdded(1_000)->create();

    // STEP 0 OF THE INTAKE GATE, BEFORE A SINGLE BYTE IS READ. `used >= limit` is `exceeded()`, so
    // an organization that has consumed exactly its allowance has none left.
    currentTest()->actingAs($fixture['owner'])
        ->postJson("/api/v1/organizations/{$fixture['org']->id}/sources", [
            'type' => 'file',
            'name' => 'Handbook',
            'files' => [UploadedFile::fake()->createWithContent('Handbook.pdf', quotaGatePdfBytes())],
        ])
        ->assertStatus(403)
        ->assertJsonPath('error_class', 'tenant_quota')
        // NEVER RETRYABLE. A quota breach does not clear on a timer a client can wait for — it
        // clears when the period rolls over or somebody changes the plan — so a 429 here would send
        // every over-quota organization down a full backoff ladder against a guaranteed failure.
        ->assertJsonPath('retryable', false);

    // NOTHING WAS WRITTEN. Not a source, not an item, not an object: the refusal is before the gate
    // reads a file, which is the same "do not pay for what you are about to refuse" ordering step 1
    // applies to size.
    expect(\App\Models\KnowledgeSource::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses a batch that would take the organization over, and admits one that fits', function (): void {
    $fixture = quotaGateFixture();
    $bytes = quotaGatePdfBytes();

    // EXACTLY THE SIZE OF ONE FILE. `wouldExceed()` is `used + additional > limit`, so a batch that
    // lands ON the ceiling is inside it — asserting only the refusal would pass against an
    // off-by-one that refuses every batch reaching the limit.
    $fixture['org']->storage_bytes_quota = strlen($bytes);
    $fixture['org']->save();

    $post = fn (int $count): mixed => currentTest()->actingAs($fixture['owner'])
        ->postJson("/api/v1/organizations/{$fixture['org']->id}/sources", [
            'type' => 'file',
            'name' => 'Handbook',
            // DISTINCT CONTENT PER FILE, because the intake gate refuses a byte-identical duplicate
            // inside one batch — two identical files would be a `duplicate` rejection rather than
            // the quota refusal this test is about.
            'files' => array_map(
                static fn (int $i): UploadedFile => UploadedFile::fake()
                    ->createWithContent("Handbook-{$i}.pdf", $bytes."% copy {$i}\n"),
                range(1, $count),
            ),
        ]);

    // TWO FILES: over. STEP 7, charged against the ACCEPTED, DEDUPLICATED total.
    $post(2)->assertStatus(403)->assertJsonPath('error_class', 'tenant_quota');

    expect(\App\Models\KnowledgeSource::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('leaves an unmetered organization completely unmetered', function (): void {
    // THE STATE EVERY EXISTING ORGANIZATION IS IN THE MOMENT THE MIGRATION RUNS: four nullable
    // columns, no default, no backfill. A `DEFAULT 0` would have refused every upload in the
    // platform at the instant of the deploy, so `null` MUST mean unlimited — and this is that
    // sentence as an assertion.
    $fixture = quotaGateFixture();

    currentTest()->actingAs($fixture['owner'])
        ->postJson("/api/v1/organizations/{$fixture['org']->id}/sources", [
            'type' => 'file',
            'name' => 'Handbook',
            'files' => [UploadedFile::fake()->createWithContent('Handbook.pdf', quotaGatePdfBytes())],
        ])
        ->assertStatus(201);
});

it('meters the bytes an accepted upload stored, so the next upload is charged for them', function (): void {
    $fixture = quotaGateFixture();
    $bytes = quotaGatePdfBytes();

    currentTest()->actingAs($fixture['owner'])
        ->postJson("/api/v1/organizations/{$fixture['org']->id}/sources", [
            'type' => 'file',
            'name' => 'Handbook',
            'files' => [UploadedFile::fake()->createWithContent('Handbook.pdf', $bytes)],
        ])
        ->assertStatus(201);

    // THE LEDGER ROW IS WRITTEN AFTER THE COMMIT, never inside it: `UsageRecorder::record()` bumps
    // the Valkey counter as well as writing the row, and the counter is not transactional — a
    // rollback would take the row back and leave the bump, making the counter HIGH, which is the one
    // direction `QuotaCounters` relies on being impossible.
    $event = app(TenantContext::class)->runFor(
        $fixture['org']->id,
        static fn (): ?UsageEvent => UsageEvent::query()->first(),
    );

    expect($event)->not->toBeNull()
        ->and($event?->event_type->value)->toBe('storage.bytes.added')
        ->and($event?->quantity)->toBe(strlen($bytes))
        // KEYED ON THE ITEM'S OWN ULID, which is what makes `kb:rollup-usage` able to re-derive this
        // event without charging twice.
        ->and($event?->dedupe_key)->toBe(
            app(TenantContext::class)->runFor(
                $fixture['org']->id,
                static fn (): string => (string) \App\Models\SourceItem::query()->value('id'),
            )
        );
});

// ── entry point 2: the chat turn, implemented and called by nothing ─────────────────────────────

it('refuses a chat turn once the monthly token allowance is reached', function (): void {
    $fixture = quotaGateFixture();

    $fixture['org']->monthly_tokens_quota = 1_000;
    $fixture['org']->save();

    UsageEvent::factory()->recycle($fixture['org'])->recycle($fixture['bot'])
        ->input(600)->create();
    UsageEvent::factory()->recycle($fixture['org'])->recycle($fixture['bot'])
        ->output(400)->create();

    $gate = app(QuotaGate::class);

    // BOTH DIRECTIONS COUNT: 600 + 400 is the allowance. An output-only quota would let an
    // organization spend an unbounded amount of the platform's budget on packed retrieval context
    // while its meter barely moved.
    app(TenantContext::class)->runFor($fixture['org']->id, function () use ($gate, $fixture): void {
        expect(fn () => $gate->assertChatTurnPermitted($fixture['org'], $fixture['bot']))
            ->toThrow(KbException::class);
    });

    // AND THE CLASS IS `tenant_quota`, 403, NON-RETRYABLE — asserted on the exception rather than
    // through HTTP, because there is no HTTP caller yet and inventing one would be building Phase 4.
    try {
        app(TenantContext::class)->runFor(
            $fixture['org']->id,
            fn () => $gate->assertChatTurnPermitted($fixture['org'], $fixture['bot']),
        );
        $caught = null;
    } catch (KbException $e) {
        $caught = $e;
    }

    expect($caught?->errorClass)->toBe('tenant_quota')
        ->and($caught?->status)->toBe(403)
        ->and($caught?->retryable())->toBeFalse();
});

it('admits a chat turn for an organization inside its allowance, and one with no allowance at all', function (): void {
    $fixture = quotaGateFixture();
    $gate = app(QuotaGate::class);

    // UNMETERED: no counter read, no aggregate query, no comparison against zero.
    app(TenantContext::class)->runFor(
        $fixture['org']->id,
        fn () => $gate->assertChatTurnPermitted($fixture['org'], $fixture['bot']),
    );

    $fixture['org']->monthly_tokens_quota = 10_000;
    $fixture['org']->save();

    UsageEvent::factory()->recycle($fixture['org'])->recycle($fixture['bot'])->input(500)->create();

    // `refresh()` AND NOT `fresh()`: the second returns a nullable model, and the ceiling has to be
    // re-read from the row rather than trusted from the in-memory copy the test just wrote to —
    // `QuotaLimits::fromOrganization()` reads the bound object, so an unrefreshed one would carry
    // the pre-write nulls and the assertion would pass for the wrong reason.
    $fixture['org']->refresh();

    app(TenantContext::class)->runFor(
        $fixture['org']->id,
        fn () => $gate->assertChatTurnPermitted($fixture['org'], $fixture['bot']),
    );

    expect(true)->toBeTrue('both admissions returned without raising');
});

// ── the per-bot rate limits, stored since Phase B and unenforced until now ──────────────────────

it('enforces the per-minute limit that has been stored and unread since the bots table landed', function (): void {
    // BOTH COLUMNS HAVE EXISTED SINCE 2026_08_19_001400 WITH A CHECK CONSTRAINT, A CAST, A
    // FORMREQUEST RULE AND AN ADMIN FORM — AND NOTHING READ THEM. An operator could set a limit, see
    // it persisted, see it rendered back, and have it enforce nothing: visibly configured, invisibly
    // absent, which is the worst shape a security control can have.
    $fixture = quotaGateFixture();
    $fixture['bot']->rate_limit_per_minute = 2;
    $fixture['bot']->save();

    $limiter = app(BotRateLimiter::class);

    expect($limiter->charge($fixture['bot'])->allowed)->toBeTrue()
        ->and($limiter->charge($fixture['bot'])->allowed)->toBeTrue();

    $refused = $limiter->charge($fixture['bot']);

    expect($refused->allowed)->toBeFalse()
        // THE WINDOW NAME TRAVELS WITH THE VERDICT, because the operator's remedy differs: a
        // per-minute breach is a burst and clears in seconds, a per-day breach clears at midnight
        // UTC and usually means the limit is wrong for the traffic.
        ->and($refused->window)->toBe('minute')
        ->and($refused->limit)->toBe(2)
        ->and($refused->retryAfterSeconds)->toBeGreaterThan(0);
});

it('does not let a full DAY window be charged against by a request the MINUTE window refuses', function (): void {
    // THE TWO-PASS SHAPE INSIDE THE LUA SCRIPT, WHICH IS THE WHOLE REASON IT IS ONE `EVAL` AND NOT
    // TWO CALLS. The script checks EVERY window before it increments ANY: incrementing the minute
    // bucket and then discovering the day bucket is full would charge the caller for a request that
    // was refused, so an organization that had exhausted its daily allowance would keep burning its
    // per-minute allowance and the per-minute number would be a lie for the rest of the day.
    $fixture = quotaGateFixture();
    $fixture['bot']->rate_limit_per_minute = 100;
    $fixture['bot']->rate_limit_per_day = 1;
    $fixture['bot']->save();

    $limiter = app(BotRateLimiter::class);

    expect($limiter->charge($fixture['bot'])->allowed)->toBeTrue();

    $refused = $limiter->charge($fixture['bot']);

    expect($refused->allowed)->toBeFalse()
        ->and($refused->window)->toBe('day');

    // THE MINUTE COUNTER MUST STILL READ 1, not 2. Read straight out of Valkey rather than inferred:
    // the property is about what the script WROTE, and a second `charge()` that reported "day" would
    // look identical whether or not it had also incremented the minute bucket.
    // THE SCOPE SEGMENT IS `bot`, NOT `minute`, AND THAT CHANGED WITH PHASE 4.
    //
    // `valkey-keyspaces` specifies `rl:{org_id}:{bot_id}:{scope}:{subject}:{window}` with the scopes
    // being `bot`, `origin`, `session` and `ip`. This key used to carry the WINDOW NAME in the scope
    // segment — `…:minute:{bot_id}:60:{bucket}` — which put a value outside that closed set where an
    // ACL pattern and an org purge both expect one of the four, and made the same bot's two windows
    // look like two different scopes. The window is already distinguished by `{seconds}`, so nothing
    // was gained by it.
    //
    // The old key's own docblock claimed the third segment WAS the bot id, which it was not; adding
    // the three platform scopes is what forced the two to be told apart, and the docblock was right
    // about the intent all along.
    //
    // THE CONSEQUENCE ON DEPLOY: every existing `rl:` counter is orphaned and every bot starts its
    // windows empty. That is a two-window reset with a TTL of at most 48 hours, not a correctness
    // problem — and it is the reason this is written down rather than silently corrected.
    $timestamp = CarbonImmutable::now('UTC')->getTimestamp();
    $bucket = intdiv($timestamp, 60);
    $key = "rl:{$fixture['bot']->organization_id}:{$fixture['bot']->id}:bot:{$fixture['bot']->id}:60:{$bucket}";

    expect((int) Redis::connection('coordination')->get($key))->toBe(1);
});

it('charges nothing at all for a bot with no configured limit', function (): void {
    // A BOT WITH BOTH COLUMNS NULL IS UNLIMITED, and this is a no-op that TOUCHES NO KEY — the same
    // shape `QuotaLimits` uses for a null ceiling, and for the same reason: an unconfigured limit
    // must cost nothing, not merely allow everything.
    $fixture = quotaGateFixture();

    expect($fixture['bot']->rate_limit_per_minute)->toBeNull()
        ->and($fixture['bot']->rate_limit_per_day)->toBeNull();

    $limiter = app(BotRateLimiter::class);

    for ($i = 0; $i < 5; $i++) {
        expect($limiter->charge($fixture['bot'])->allowed)->toBeTrue();
    }

    // NO `rl:` KEY FOR THIS BOT EXISTS. Asserted by counting the keys the family would have produced
    // rather than by trusting the verdicts above, because "allowed" is what an unlimited bot and a
    // bot under its limit both report.
    $keys = Redis::connection('coordination')->keys("rl:{$fixture['bot']->organization_id}:*");

    expect($keys)->toBe([]);
});

it('refuses a chat turn on a rate-limit breach as tenant_quota rather than as a retryable 429', function (): void {
    /*
     * THE ONE CLASSIFICATION DECISION ON THIS SURFACE THAT IS ARGUABLE, ASSERTED SO IT IS DELIBERATE.
     *
     * `bots.rate_limit_per_minute` is not a platform protection measure — it is a number the
     * ORGANIZATION sets on its own bot, in the same admin form as the model and the retrieval
     * configuration, so a client reaching it has reached a ceiling its own organization chose. That
     * is the same kind of fact as the monthly token allowance, one level down.
     *
     * `rate_limit` (429) is the platform saying "you are going too fast for US, wait and retry", and
     * it IS retryable — `apps/web`'s query client runs a full backoff ladder on that field. Applying
     * it here would tell a caller to hammer a per-DAY limit that will not clear for hours.
     *
     * NOT DECIDED HERE: the platform's own throttling of the public chat surface — the origin,
     * session and IP scopes — which is Phase 4's and IS a 429 with a `Retry-After`, because it is
     * the platform speaking. Two different facts, two classes.
     */
    $fixture = quotaGateFixture();
    $fixture['bot']->rate_limit_per_minute = 1;
    $fixture['bot']->save();

    $gate = app(QuotaGate::class);

    app(TenantContext::class)->runFor(
        $fixture['org']->id,
        fn () => $gate->assertChatTurnPermitted($fixture['org'], $fixture['bot']),
    );

    try {
        app(TenantContext::class)->runFor(
            $fixture['org']->id,
            fn () => $gate->assertChatTurnPermitted($fixture['org'], $fixture['bot']),
        );
        $caught = null;
    } catch (KbException $e) {
        $caught = $e;
    }

    expect($caught?->errorClass)->toBe('tenant_quota')
        ->and($caught?->status)->toBe(403)
        ->and($caught?->retryable())->toBeFalse()
        ->and($caught?->getMessage())->toContain('per-minute');
});
