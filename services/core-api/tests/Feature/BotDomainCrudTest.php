<?php

declare(strict_types=1);

use App\Enums\BotDomainStatus;
use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\BotDomain;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Bots\BotDomainService;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| The widget origin allow-list — index, store, update, destroy
|--------------------------------------------------------------------------
|
| The BEHAVIOUR half. The authorization and isolation half is
| tests/Security/BotChildEndpointAccessTest.php, and the two are separate files because they fail
| for different reasons: a red test there is a tenant or privilege boundary, a red test here is a
| bug in an endpoint.
|
| WHAT THIS SURFACE IS. A row in `bot_domains` is a SECURITY CONTROL — it is what lets a page on the
| public internet boot a chat widget that speaks with this organization's credential, on its corpus,
| against its quota — and every downstream check AGREES with it, because it has been told that this
| origin belongs to that bot. There is no later layer that catches a bad row, which is why the
| normalisation and refusal matrix below is the longest test in the file rather than a formality.
|
| EVERY FIXTURE IS TWO ORGANIZATIONS with overlapping, distinguishable data, even in the behaviour
| file — a one-organization fixture passes every assertion here against code with the tenant filter
| deleted. Both organizations carry a bot, and both bots list the SAME ORIGIN, which is legal
| (`bot_domains_org_bot_origin` is unique per BOT) and is exactly the shape that makes a missing
| predicate return a plausible row.
|
| ABSENCE IS ALWAYS `expect(str_contains($body, $needle))->toBeFalse()` and NEVER
| `->not->toContain(...)`. Pest's toContain(mixed ...$needles) takes no message argument, so a label
| passed there becomes a second needle, and `not` treats any failure as success — the expression
| passes unconditionally.
*/

beforeEach(function (): void {
    // A CLIENT ADDRESS OF THIS TEST'S OWN. RefreshDatabase rolls back the database and nothing else,
    // and phpunit.xml points the cache at a real Valkey — so every rate-limiter bucket survives the
    // test that filled it and the next run of the suite.
    SpaSession::isolateRateLimits(currentTest());
});

/**
 * The origin BOTH organizations list, so a missing predicate returns a row that looks right.
 */
const BOT_DOMAIN_SHARED_ORIGIN = 'https://shared.example.com';

/**
 * Two organizations, one bot each, and both bots listing the same origin.
 *
 * A HELPER OF THIS FILE'S OWN, with a name of its own. Pest declares test-file helpers at FILE
 * SCOPE, so a name another test file already uses is a redeclaration fatal in a full run and only
 * in a full run.
 *
 * @return array{
 *     orgA: Organization, orgB: Organization,
 *     ownerA: User, botA: Bot, botB: Bot,
 *     domainA: BotDomain, domainB: BotDomain,
 * }
 */
function botDomainFixture(): array
{
    $orgA = Organization::factory()->create(['name' => 'Domain Org ALPHA', 'slug' => 'domain-alpha']);
    $orgB = Organization::factory()->create(['name' => 'Domain Org BRAVO', 'slug' => 'domain-bravo']);

    // ->recycle() on every child, without exception: BotFactory and BotDomainFactory both REFUSE to
    // run without a recycled organization, because a row minted into a THIRD organization is what
    // makes an isolation assertion pass with the tenant filter deleted.
    $botA = Bot::factory()->recycle($orgA)->create(['name' => 'ALPHA bot', 'slug' => 'alpha-bot']);
    $botB = Bot::factory()->recycle($orgB)->create(['name' => 'BRAVO bot', 'slug' => 'bravo-bot']);

    return [
        'orgA' => $orgA,
        'orgB' => $orgB,
        'ownerA' => User::factory()->recycle($orgA)->orgRole(OrgRole::Owner)
            ->create(['email' => SpaSession::uniqueEmail('domain-owner-alpha')]),
        'botA' => $botA,
        'botB' => $botB,
        'domainA' => BotDomain::factory()->recycle($orgA)->recycle($botA)
            ->origin(BOT_DOMAIN_SHARED_ORIGIN)->create(),
        'domainB' => BotDomain::factory()->recycle($orgB)->recycle($botB)
            ->origin(BOT_DOMAIN_SHARED_ORIGIN)->create(),
    ];
}

/**
 * The collection URL for org A's bot.
 *
 * @param  array{orgA: Organization, botA: Bot, ...}  $fixture
 */
function botDomainUrl(array $fixture): string
{
    return "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$fixture['botA']->id}/domains";
}

// ── GET …/domains ────────────────────────────────────────────────────────────────────────────────

it('lists every entry including the ones that grant nothing, ordered and wrapped', function (): void {
    $fixture = botDomainFixture();

    BotDomain::factory()->recycle($fixture['orgA'])->recycle($fixture['botA'])
        ->active()->origin('https://aaa.example.com')->create();
    BotDomain::factory()->recycle($fixture['orgA'])->recycle($fixture['botA'])
        ->disabled()->origin('https://zzz.example.com')->create();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $response = currentTest()->getJson(botDomainUrl($fixture), spaHeaders())->assertOk();

    // PENDING AND DISABLED ROWS ARE INCLUDED. Filtering them out would make "why is my widget
    // refused on this site" unanswerable from the console while the row sat in the table.
    expect($response->json('data.domains.*.origin'))
        ->toBe(['https://aaa.example.com', BOT_DOMAIN_SHARED_ORIGIN, 'https://zzz.example.com']);

    // AND THE PUBLISHED PREDICATE, per row. A client that recomputed it as `status !== 'disabled'`
    // would admit `pending` — the negative-test hole `BotDomainStatus::permitsEmbedding()` is
    // written positively to avoid.
    expect($response->json('data.domains.*.permits_embedding'))->toBe([true, false, false]);

    // THE ENVELOPE IS AN OBJECT WRAPPING THE ARRAY, not a bare array: `#[ResponseShape]` maps a
    // response KEY to a resource class and cannot express "an array of".
    expect(array_keys((array) $response->json('data')))->toBe(['domains']);
});

it('returns an empty array for a bot with no allow-list, which denies every origin', function (): void {
    $fixture = botDomainFixture();

    $bare = Bot::factory()->recycle($fixture['orgA'])->create(['name' => 'ALPHA bare', 'slug' => 'alpha-bare']);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // THE STATE A CLIENT MUST NOT READ THE NATURAL WAY. "No allow-list configured" reads as
    // "unrestricted" everywhere allow-lists are misread, and here that reading is an embed on any
    // site on the internet. The decision is "SOME ACTIVE ROW MATCHES THIS EXACT ORIGIN", false for
    // the empty set by construction.
    currentTest()->getJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$bare->id}/domains",
        spaHeaders(),
    )
        ->assertOk()
        ->assertExactJson(['data' => ['domains' => []]]);
});

// ── POST …/domains ───────────────────────────────────────────────────────────────────────────────

it('stores the normalised origin rather than the posted one', function (
    string $posted,
    string $stored,
): void {
    // THE THREE MUTATIONS `ExactOrigin` PERFORMS, each asserted, because every one of them decides
    // whether a legitimate row ever MATCHES a real request. The default-port case is the one worth
    // reading twice: a browser serialises `https://example.com:443` as `https://example.com`, so a
    // stored `:443` is a grant that silently never fires.
    $fixture = botDomainFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->postJson(botDomainUrl($fixture), ['origin' => $posted], spaHeaders())
        ->assertStatus(201)
        ->assertJsonPath('data.origin', $stored)
        // A NEW ROW GRANTS NOTHING. Whether an origin is under the operator's control is not
        // something the entry form knows, so promotion is a second, separately-audited action.
        ->assertJsonPath('data.status', BotDomainStatus::Pending->value)
        ->assertJsonPath('data.permits_embedding', false);

    assertDatabaseHas('bot_domains', ['bot_id' => $fixture['botA']->id, 'origin' => $stored]);
})->with([
    'case folded' => ['HTTPS://Example.COM', 'https://example.com'],
    'one trailing slash dropped' => ['https://example.com/', 'https://example.com'],
    'default https port dropped' => ['https://example.com:443', 'https://example.com'],
    'default http port dropped' => ['http://example.com:80', 'http://example.com'],
    'a non-default port is kept' => ['http://localhost:3000', 'http://localhost:3000'],
    'surrounding whitespace trimmed' => ['  https://example.com  ', 'https://example.com'],
    'already exact' => ['https://widget.example.com', 'https://widget.example.com'],
]);

it('refuses everything that is not an exact origin, keyed on the field, with a reason', function (
    string $posted,
    string $needle,
): void {
    // ONE CASE PER REFUSAL, AND THE MESSAGE IS ASSERTED RATHER THAN JUST THE STATUS. "Invalid
    // origin" leaves an operator with nothing to do but guess, and the guess most people make on an
    // allow-list is to try a wildcard — so the wildcard refusal in particular has to say that there
    // is no spelling of one that works.
    $fixture = botDomainFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $response = currentTest()->postJson(botDomainUrl($fixture), ['origin' => $posted], spaHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonValidationErrors('origin');

    expect(str_contains((string) $response->getContent(), $needle))
        ->toBeTrue("the refusal for `{$posted}` did not explain itself: ".(string) $response->getContent());

    // AND NOTHING WAS STORED. A 422 that also wrote the row would be the worst possible outcome
    // here, because the caller would believe the grant was refused.
    expect(BotDomain::query()->withoutGlobalScopes()
        ->where('bot_id', '=', $fixture['botA']->id)->count())
        ->toBe(1, 'a refused origin reached the table anyway');
})->with([
    'a wildcard subdomain' => ['https://*.example.com', 'Wildcards are not accepted'],
    'a bare wildcard' => ['*', 'Wildcards are not accepted'],
    // NOT TRIMMED TO THE HOST, and this is the security decision rather than a strictness
    // preference: shortening it would grant the whole site when the operator asked for one page.
    'a path' => ['https://example.com/widget', 'An origin has no path'],
    'a query string' => ['https://example.com?embed=1', 'no query string'],
    'a fragment' => ['https://example.com#frag', 'no query string'],
    'userinfo' => ['https://trusted.example@evil.test', 'no username or password'],
    'an IPv6 literal' => ['http://[::1]:3000', 'IPv6-literal origin'],
    'a scheme we do not embed from' => ['ftp://example.com', 'only two schemes a browser embeds'],
    'no scheme at all' => ['example.com', 'only two schemes a browser embeds'],
    'port zero' => ['http://localhost:0', 'A port is a number from 1 to 65535'],
    'a leading-zero port' => ['http://localhost:03000', 'A port is a number from 1 to 65535'],
    'a port above the range' => ['http://localhost:70000', 'A port is a number from 1 to 65535'],
    'an underscore in the host' => ['https://my_site.example.com', 'is not one this allow-list can store'],
    'a trailing dot' => ['https://example.com.', 'is not one this allow-list can store'],
    'a non-ASCII host' => ['https://bücher.example', 'is not one this allow-list can store'],
    // ── THE HOMOGLYPH ROW, WHICH IS THE ONE THAT WAS STORED ──────────────────────────────────
    //
    // `ExactOrigin` used to fold with `mb_strtolower()`, which applies Unicode simple lowercase
    // mapping. Exactly one codepoint above ASCII lowercases INTO ASCII — U+212A KELVIN SIGN, to
    // `k` — and the control-character guard ahead of the fold is byte-wise with no `/u`, so the
    // UTF-8 reached it untouched. `https://<U+212A>elvin.example.com` therefore became
    // `https://kelvin.example.com`, satisfied the ASCII host grammar, and was STORED: an operator
    // pasting a homoglyph granted a DIFFERENT host from the one they typed, and two distinct
    // inputs collided onto one row. Both are properties the class argues at length that it does
    // not have. The fix is ASCII-only `strtolower()`, which leaves the bytes alone so the host
    // grammar refuses them — which is why the expected needle is the ordinary punycode message
    // and not a new one.
    'a KELVIN SIGN homoglyph host' => ["https://\u{212A}elvin.example.com", 'is not one this allow-list can store'],
    // A SECOND NON-ASCII HOST WHOSE FOLD LANDS BESIDE AN ASCII LABEL, so a fix that only special-
    // cased a leading character is caught too.
    'a KELVIN SIGN mid-host' => ["https://example.\u{212A}9.com", 'is not one this allow-list can store'],
    // U+0130 LATIN CAPITAL I WITH DOT ABOVE, whose simple lowercase mapping is TWO codepoints
    // (`i` plus U+0307 COMBINING DOT ABOVE) — the other shape of "the fold is not
    // identity-preserving", and one that also changes the string's length.
    'a dotted capital I' => ["https://\u{0130}stanbul.example", 'is not one this allow-list can store'],
]);

it('refuses a duplicate origin per bot, and does not refuse it across bots', function (): void {
    $fixture = botDomainFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // A DUPLICATE IN THE NORMALISED FORM, not a byte-identical resend. Without normalisation
    // `https://Shared.Example.com/` and the stored value would be two rows granting one thing, and
    // the unique index could not object because they are different strings.
    currentTest()->postJson(
        botDomainUrl($fixture),
        ['origin' => 'https://Shared.Example.com/'],
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('origin');

    // ANOTHER BOT IN THE SAME ORGANIZATION MAY LIST IT. The uniqueness is per bot, and refusing it
    // organization-wide would make one origin serve one bot forever.
    $other = Bot::factory()->recycle($fixture['orgA'])->create(['name' => 'ALPHA other', 'slug' => 'alpha-other']);

    currentTest()->postJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$other->id}/domains",
        ['origin' => BOT_DOMAIN_SHARED_ORIGIN],
        spaHeaders(),
    )->assertStatus(201);

    // AND ORG B'S IDENTICAL ROW IS UNTOUCHED — a pre-flight check that had lost its tenant predicate
    // would have refused org A's first insert on the strength of org B's row.
    assertDatabaseHas('bot_domains', [
        'id' => $fixture['domainB']->id,
        'organization_id' => $fixture['orgB']->id,
    ]);
});

it('refuses to grow the allow-list past its ceiling', function (): void {
    $fixture = botDomainFixture();

    // ONE SHORT OF THE CAP, so the next POST is the boundary. Written from the constant rather than
    // from a literal, so raising the ceiling does not silently turn this into a test of nothing.
    for ($i = 1; $i < BotDomainService::MAX_PER_BOT; $i++) {
        BotDomain::factory()->recycle($fixture['orgA'])->recycle($fixture['botA'])
            ->origin("https://filler-{$i}.example")->create();
    }

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $response = currentTest()->postJson(
        botDomainUrl($fixture),
        ['origin' => 'https://one-too-many.example'],
        spaHeaders(),
    )
        ->assertStatus(422)
        ->assertJsonValidationErrors('origin');

    // THE MESSAGE SAYS WHAT NOT TO DO NEXT. An operator who hits a cap on an allow-list reaches for
    // a wildcard, and this list has no grammar for one.
    expect(str_contains((string) $response->getContent(), 'not a hint to use a wildcard'))->toBeTrue();

    assertDatabaseMissing('bot_domains', ['origin' => 'https://one-too-many.example']);
});

// ── PATCH …/domains/{domain} ─────────────────────────────────────────────────────────────────────

it('promotes, withdraws and re-enables an entry, and refuses a no-op', function (): void {
    $fixture = botDomainFixture();

    $url = botDomainUrl($fixture)."/{$fixture['domainA']->id}";

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->patchJson($url, ['status' => BotDomainStatus::Active->value], spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.permits_embedding', true)
        // THE ORIGIN IS UNCHANGED BY A PROMOTION and is echoed so the console can show what is now
        // live rather than what was typed.
        ->assertJsonPath('data.origin', BOT_DOMAIN_SHARED_ORIGIN);

    // A NO-OP IS REFUSED. Setting `active` again would write a `bot.domain.status_changed` row
    // claiming a promotion that did not happen, on the one table whose trail exists to say exactly
    // when an origin started and stopped granting an embed.
    currentTest()->patchJson($url, ['status' => BotDomainStatus::Active->value], spaHeaders())
        ->assertStatus(422)
        ->assertJsonPath('error_class', 'validation')
        ->assertJsonValidationErrors('status');

    currentTest()->patchJson($url, ['status' => BotDomainStatus::Disabled->value], spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.permits_embedding', false);

    // AND BACK. `disabled` is retained rather than deleted precisely so an origin can be turned off
    // for an investigation and back on without retyping it.
    currentTest()->patchJson($url, ['status' => BotDomainStatus::Active->value], spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.permits_embedding', true);
});

it('accepts no field but `status`, so an origin cannot be edited in place', function (): void {
    $fixture = botDomainFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->patchJson(
        botDomainUrl($fixture)."/{$fixture['domainA']->id}",
        [
            'status' => BotDomainStatus::Active->value,
            // OVER-POSTED, and the point is that it is IGNORED rather than applied. Editing an
            // origin in place would carry the promotion this same request performs across to a
            // different origin — a grant moved silently, with the `bot.domain.created` row still
            // naming the old value.
            'origin' => 'https://attacker.example',
            'organization_id' => $fixture['orgB']->id,
            'bot_id' => $fixture['botB']->id,
        ],
        spaHeaders(),
    )
        ->assertOk()
        ->assertJsonPath('data.origin', BOT_DOMAIN_SHARED_ORIGIN);

    assertDatabaseHas('bot_domains', [
        'id' => $fixture['domainA']->id,
        'organization_id' => $fixture['orgA']->id,
        'bot_id' => $fixture['botA']->id,
        'origin' => BOT_DOMAIN_SHARED_ORIGIN,
    ]);

    assertDatabaseMissing('bot_domains', ['origin' => 'https://attacker.example']);
});

// ── DELETE …/domains/{domain} ────────────────────────────────────────────────────────────────────

it('removes an entry and 404s the second attempt', function (): void {
    $fixture = botDomainFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $url = botDomainUrl($fixture)."/{$fixture['domainA']->id}";

    currentTest()->deleteJson($url, [], spaHeaders())
        ->assertOk()
        ->assertExactJson(['data' => ['acknowledged' => true]]);

    assertDatabaseMissing('bot_domains', ['id' => $fixture['domainA']->id]);

    // ORG B'S IDENTICAL ROW SURVIVES. A delete whose predicate lost its organization term is
    // invisible to every assertion above, and the two rows carry the SAME ORIGIN, so a delete keyed
    // on the value alone would have taken both.
    assertDatabaseHas('bot_domains', ['id' => $fixture['domainB']->id]);

    // NOT IDEMPOTENT, ON PURPOSE: a 200 for the second delete would claim this actor removed a
    // grant the trail does not record them removing. It 404s at BINDING time.
    currentTest()->deleteJson($url, [], spaHeaders())->assertStatus(404);
});

// ── the audit trail: finding L2 ──────────────────────────────────────────────────────────────────

it('writes one audit row per origin, naming who granted it and when', function (): void {
    // THIS IS FINDING L2 BEING CLOSED, and it is the reason this whole surface is audited per row.
    // The finding: deleting a bot destroyed its allow-list with NO RECORD OF WHAT IT PERMITTED,
    // contradicting the reason `bot_domains` gives for its own ON DELETE RESTRICT. These rows are
    // append-only and they outlive the bot, so they are what actually reconstructs an allow-list.
    $fixture = botDomainFixture();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    $created = currentTest()->postJson(
        botDomainUrl($fixture),
        ['origin' => 'HTTPS://Audited.Example.com/'],
        spaHeaders(),
    )->assertStatus(201);

    $domainId = (string) $created->json('data.id');

    $row = AuditLog::query()
        ->where('operation', '=', AuditLogger::BOT_DOMAIN_CREATED)
        ->where('subject_id', '=', $domainId)
        ->firstOrFail();

    /** @var array<string, mixed> $details */
    $details = (array) $row->details;

    // THE ORIGIN IS ECHOED IN ITS NORMALISED FORM — the string a browser will actually be compared
    // against, not the one that was typed. A trail carrying the typed form would answer a different
    // question from the one an investigation asks.
    expect($details['origin'])->toBe('https://audited.example.com')
        ->and($details['status'])->toBe(BotDomainStatus::Pending->value)
        // `bot_id` IS LOAD-BEARING: `subject_id` is the entry's own ULID and resolves to nothing
        // once the bot is hard-deleted, so without this the trail can say an origin was granted and
        // cannot say for which bot.
        ->and($details['bot_id'])->toBe($fixture['botA']->id)
        ->and($row->actor_id)->toBe($fixture['ownerA']->id)
        ->and($row->organization_id)->toBe($fixture['orgA']->id);

    // THE TRANSITION ROW CARRIES BOTH ENDS. "Who turned this origin on, and what was it before" is
    // the question an incident asks, and a row carrying only the result cannot answer it.
    currentTest()->patchJson(
        botDomainUrl($fixture)."/{$domainId}",
        ['status' => BotDomainStatus::Active->value],
        spaHeaders(),
    )->assertOk();

    /** @var array<string, mixed> $changed */
    $changed = (array) AuditLog::query()
        ->where('operation', '=', AuditLogger::BOT_DOMAIN_STATUS_CHANGED)
        ->where('subject_id', '=', $domainId)
        ->firstOrFail()
        ->details;

    expect($changed['status'])->toBe(BotDomainStatus::Active->value)
        ->and($changed['previous_status'])->toBe(BotDomainStatus::Pending->value)
        ->and($changed['origin'])->toBe('https://audited.example.com');

    // AND THE DELETE ROW IS THE ONLY SURVIVING DESCRIPTION OF THE GRANT: `subject_id` points at a
    // ULID no table resolves afterwards, so `origin` and `status` are load-bearing rather than
    // decorative — and `status` is what says whether the removed row had been LIVE.
    currentTest()->deleteJson(botDomainUrl($fixture)."/{$domainId}", [], spaHeaders())->assertOk();

    /** @var array<string, mixed> $deleted */
    $deleted = (array) AuditLog::query()
        ->where('operation', '=', AuditLogger::BOT_DOMAIN_DELETED)
        ->where('subject_id', '=', $domainId)
        ->firstOrFail()
        ->details;

    expect($deleted['origin'])->toBe('https://audited.example.com')
        ->and($deleted['status'])->toBe(BotDomainStatus::Active->value);
});

it('records what a deleted bot permitted, on the bot row itself', function (): void {
    // THE OTHER HALF OF L2. The per-origin rows above are what reconstructs the list; the summary
    // here is the TRIPWIRE — a reader who lands on `bot.deleted` and sees `domain_count: 3` knows
    // to go looking for them, where a reader who saw nothing would conclude the bot never had one.
    $fixture = botDomainFixture();

    BotDomain::factory()->recycle($fixture['orgA'])->recycle($fixture['botA'])
        ->active()->origin('https://live-one.example')->create();
    BotDomain::factory()->recycle($fixture['orgA'])->recycle($fixture['botA'])
        ->active()->origin('https://live-two.example')->create();

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->deleteJson(
        "/api/v1/organizations/{$fixture['orgA']->id}/bots/{$fixture['botA']->id}",
        [],
        spaHeaders(),
    )->assertOk();

    /** @var array<string, mixed> $details */
    $details = (array) AuditLog::query()
        ->where('operation', '=', AuditLogger::BOT_DELETED)
        ->where('subject_id', '=', $fixture['botA']->id)
        ->firstOrFail()
        ->details;

    // THREE ROWS, TWO OF THEM LIVE. Both numbers are recorded because they answer different
    // questions: `domain_count` says how many `bot.domain.*` rows to expect, and
    // `active_domain_count` says how many of them were actually granting.
    expect($details['domain_count'])->toBe(3)
        ->and($details['active_domain_count'])->toBe(2)
        // ONLY THE ACTIVE ORIGINS ARE ECHOED — a pending row granted nothing, and its value is in
        // its own `bot.domain.created` row. Joined into a SCALAR because `sanitize()` drops arrays
        // outright; the count beside it is what makes a 512-character truncation detectable.
        ->and($details['active_origins'])->toBe('https://live-one.example,https://live-two.example')
        ->and($details['starter_question_count'])->toBe(0)
        ->and($details['fallback_model_count'])->toBe(0);

    // AND THE PENDING ORIGIN IS *NOT* IN THE ACTIVE LIST, asserted on the raw payload rather than
    // by reading the field back, so a summary that concatenated every row regardless of status
    // fails here rather than looking correct.
    expect(str_contains((string) json_encode($details), BOT_DOMAIN_SHARED_ORIGIN))
        ->toBeFalse('a pending origin was recorded as one that granted an embed');
});

// ── check 5: the organization's status ───────────────────────────────────────────────────────────

it('refuses every write while the organization is suspended, and still serves the read', function (): void {
    $fixture = botDomainFixture();

    Organization::query()->whereKey($fixture['orgA']->id)
        ->update(['status' => OrganizationStatus::Suspended->value]);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    // THE READ SURVIVES, DELIBERATELY. Reading which origins are allowed is exactly what a suspended
    // organization's operator needs to do while working out why an embed stopped, and it changes
    // nothing.
    currentTest()->getJson(botDomainUrl($fixture), spaHeaders())->assertOk();

    currentTest()->postJson(botDomainUrl($fixture), ['origin' => 'https://new.example'], spaHeaders())
        ->assertStatus(409)
        ->assertJsonPath('message', OrganizationStatus::SUSPENDED_REFUSAL);

    currentTest()->patchJson(
        botDomainUrl($fixture)."/{$fixture['domainA']->id}",
        ['status' => BotDomainStatus::Active->value],
        spaHeaders(),
    )->assertStatus(409);

    currentTest()->deleteJson(botDomainUrl($fixture)."/{$fixture['domainA']->id}", [], spaHeaders())
        ->assertStatus(409);
});

it('does not refuse an allow-list edit on an archived bot', function (): void {
    // THE OMISSION A LATER READER IS MOST LIKELY TO MISTAKE FOR A GAP, asserted so the decision is
    // visible rather than implicit. `BotStatus::isEditable()` refuses every edit to an ARCHIVED bot
    // because its configuration is the record of what answered past conversations — but an archived
    // bot answers nobody on any channel, so its allow-list grants nothing and removing a stale
    // origin from it is a CLEANUP. Refusing that would make the grant permanently unremovable,
    // which is the wrong direction for a security control.
    $fixture = botDomainFixture();

    Bot::query()->withoutGlobalScopes()->whereKey($fixture['botA']->id)
        ->update(['status' => \App\Enums\BotStatus::Archived->value]);

    SpaSession::establish(currentTest(), $fixture['ownerA']);

    currentTest()->deleteJson(botDomainUrl($fixture)."/{$fixture['domainA']->id}", [], spaHeaders())
        ->assertOk();

    assertDatabaseMissing('bot_domains', ['id' => $fixture['domainA']->id]);
});
