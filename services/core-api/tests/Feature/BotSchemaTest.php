<?php

declare(strict_types=1);

use App\Enums\EvidenceThresholdScale;
use App\Models\Bot;
use App\Models\BotDomain;
use App\Models\Organization;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| What the bots schema refuses, asserted against the database itself
|--------------------------------------------------------------------------
|
| EVERY TEST BELOW WRITES THROUGH THE MODEL AND NOT THROUGH AN ENDPOINT, on purpose. These are the
| constraints that have to hold for a writer that never ran a FormRequest — a repair script, a
| console command, a seeder, a future service somebody has not written yet. A test that drove an
| HTTP route would prove the FormRequest works and would say nothing about the row.
|
| The FormRequest is still the layer that produces a usable failure: a 422 keyed on a field, in the
| order a human fills a form. What these assert is that when it is absent, the answer is a refusal
| rather than a stored contradiction.
|
| tests/Security/BotTenancyTest.php holds the OTHER half — the composite foreign keys and the
| organization scope — because those fail for a different reason and a red test there is a tenant
| boundary rather than a shape.
*/

/**
 * Attempt a save and return the QueryException it raised, or null if it succeeded.
 *
 * `$model->save()` uses `newModelQuery()`, which applies no global scopes, so these writes reach the
 * database with no tenant predicate — which is exactly the writer being simulated.
 *
 * THE SAVEPOINT IS LOAD-BEARING AND IS NOT DEFENSIVE PROGRAMMING. `RefreshDatabase` wraps each test
 * in one transaction, and in PostgreSQL a statement that raises inside a transaction ABORTS IT: every
 * subsequent statement fails with 25P02, "current transaction is aborted", until a rollback. Without
 * the nested `transaction()` — which Laravel implements as `SAVEPOINT` / `ROLLBACK TO SAVEPOINT`
 * when one is already open — the first expected violation in a test would poison every line after
 * it, and the test would appear to prove a constraint that never ran.
 *
 * That failure is worth naming because of how it presents: the SECOND assertion fails with a
 * SQLSTATE nobody recognises, pointing at a statement that is perfectly valid, and the obvious fix
 * is to split the test in half — which works, and which hides that the positive control after a
 * negative assertion can never run.
 */
function saveAndCatch(Bot|BotDomain $model): ?QueryException
{
    try {
        $model->getConnection()->transaction(static fn () => $model->save());

        return null;
    } catch (QueryException $e) {
        return $e;
    }
}

it('makes a bot slug unique PER ORGANIZATION and not globally', function (): void {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    // THE POSITIVE CONTROL, AND IT IS THE POINT OF THE TEST rather than a preamble: the same slug
    // in two organizations must SUCCEED. A globally-unique slug would fail here, and the failure is
    // the one that matters — it would mean the first tenant to register `support` took the word
    // away from every other tenant on the platform.
    $slug = 'shared-slug-across-orgs';

    $a = Bot::factory()->recycle($orgA)->create(['slug' => $slug]);
    $b = Bot::factory()->recycle($orgB)->create(['slug' => $slug]);

    expect($a->slug)->toBe($slug)->and($b->slug)->toBe($slug)
        ->and($a->organization_id)->not->toBe($b->organization_id);

    // And the negative: twice inside ONE organization is refused.
    $duplicate = Bot::factory()->recycle($orgA)->make(['slug' => $slug]);
    $duplicate->organization_id = $orgA->id;

    expect(saveAndCatch($duplicate))->toBeInstanceOf(QueryException::class);
});

it('refuses a public bot id that is not the opaque token grammar the client already enforces', function (string $publicBotId): void {
    // The grammar is `^[A-Za-z0-9_-]{1,64}$`, and it is stated twice: here, and at
    // apps/web/src/app/(chat)/c/[publicBotId]/theme.css/route.ts:66, which refuses to forward
    // anything else as a path segment. Two independent statements of one grammar; if this test ever
    // has to be relaxed, that file moves in the same change.
    $org = Organization::factory()->create();
    $bot = Bot::factory()->recycle($org)->make(['name' => 'Shape probe']);
    $bot->organization_id = $org->id;
    $bot->public_bot_id = $publicBotId;

    expect(saveAndCatch($bot))->toBeInstanceOf(QueryException::class);
})->with([
    'a path separator' => ['pub/../admin'],
    'a space' => ['pub token'],
    'an empty string' => [''],
    'longer than sixty-four characters' => [str_repeat('a', 65)],
    'a dot, which the client grammar excludes' => ['pub.token'],
]);

it('accepts a public bot id at the boundary of that grammar', function (): void {
    // The positive control for the dataset above. Without it, a CHECK that refused EVERYTHING would
    // pass every row of it.
    $org = Organization::factory()->create();

    $bot = Bot::factory()->recycle($org)->create(['public_bot_id' => str_repeat('a', 64)]);

    expect($bot->refresh()->public_bot_id)->toBe(str_repeat('a', 64));
});

it('refuses an evidence threshold with no scale, and a scale with no threshold', function (): void {
    // THE WHOLE REASON THE SCALE COLUMN EXISTS. A bare number is not a threshold: 0.30 on a logit
    // passes almost everything and 0.30 on a sigmoid refuses almost everything, and NEITHER RAISES
    // — only the refusal rate moves, in aggregate, for one tenant. So the pair is held together in
    // the schema, where a writer that never ran a FormRequest still cannot separate them.
    $org = Organization::factory()->create();

    $numberOnly = Bot::factory()->recycle($org)->make();
    $numberOnly->organization_id = $org->id;
    $numberOnly->evidence_threshold = 0.30;

    expect(saveAndCatch($numberOnly))->toBeInstanceOf(QueryException::class);

    $scaleOnly = Bot::factory()->recycle($org)->make();
    $scaleOnly->organization_id = $org->id;
    $scaleOnly->evidence_threshold_scale = EvidenceThresholdScale::Sigmoid;

    expect(saveAndCatch($scaleOnly))->toBeInstanceOf(QueryException::class);
});

it('refuses an out-of-range threshold on a BOUNDED scale and accepts the same number on a logit', function (): void {
    // The one half of "0.30 is a valid float on every scale" a constraint can actually catch. The
    // two halves are asserted together because either alone is satisfiable by a constraint that is
    // simply wrong in one direction.
    $org = Organization::factory()->create();

    $bounded = Bot::factory()->recycle($org)->make();
    $bounded->organization_id = $org->id;
    $bounded->evidence_threshold = 1.7;
    $bounded->evidence_threshold_scale = EvidenceThresholdScale::Sigmoid;

    expect(saveAndCatch($bounded))->toBeInstanceOf(QueryException::class);

    // A logit is unbounded and signed — NVIDIA's own published example ranks 0.226, -1.17, -1.52 —
    // so 1.7 is an ordinary value there and -3.5 is too.
    $logit = Bot::factory()->recycle($org)
        ->thresholdedAt(1.7, EvidenceThresholdScale::Logit)->create();

    expect($logit->refresh()->evidence_threshold)->toBe(1.7)
        ->and($logit->evidence_threshold_scale)->toBe(EvidenceThresholdScale::Logit);
});

it('ships no evidence threshold at all, because there is no portable default', function (): void {
    // A COLUMN DEFAULT HERE WOULD FAIL NO TEST, which is exactly why its absence needs one. This is
    // that test: a bot created with nothing said about the threshold carries NULL, and the refusal
    // therefore stays the data plane's — where `RerankCalibration` refuses construction on an
    // uncalibrated (provider, model) pair rather than defaulting.
    $org = Organization::factory()->create();

    $bot = Bot::factory()->recycle($org)->create()->refresh();

    expect($bot->evidence_threshold)->toBeNull()
        ->and($bot->evidence_threshold_scale)->toBeNull();
});

it('closes the theme to the three keys the renderer actually reads', function (): void {
    // `primary`, `accent` and `radius` are exactly what apps/web/src/lib/theme.ts reads off a
    // tenant-supplied theme; every other custom property in its WRITABLE_PROPERTIES is DERIVED at
    // render time and is explicitly never form-settable, because contrast is derived and never
    // chosen. A fourth key stored here would be a value the renderer drops on the floor.
    $org = Organization::factory()->create();

    $accepted = Bot::factory()->recycle($org)->create([
        'theme' => ['primary' => 'oklch(0.525 0.235 264)', 'radius' => '0.5rem'],
    ]);

    // Compared key by key, NOT with `toBe()` against a literal array. `jsonb` does not preserve
    // insertion order — it stores keys sorted by length and then by bytes — so a whole-array
    // identity assertion would be asserting PostgreSQL's storage order, which is not a property of
    // this application and would break the day a key was renamed to a different length.
    $stored = $accepted->refresh()->theme;

    expect($stored)->toHaveCount(2)
        ->and($stored['primary'] ?? null)->toBe('oklch(0.525 0.235 264)')
        ->and($stored['radius'] ?? null)->toBe('0.5rem');

    $rejected = Bot::factory()->recycle($org)->make();
    $rejected->organization_id = $org->id;
    // `--primary-foreground` is in WRITABLE_PROPERTIES and is DERIVED, never submitted. Naming it
    // here is the mistake this constraint exists to catch, and it is a plausible one.
    $rejected->theme = ['primary-foreground' => 'oklch(0.985 0 0)'];

    expect(saveAndCatch($rejected))->toBeInstanceOf(QueryException::class);

    $notAString = Bot::factory()->recycle($org)->make();
    $notAString->organization_id = $org->id;
    $notAString->theme = ['radius' => ['0.5rem']];

    expect(saveAndCatch($notAString))->toBeInstanceOf(QueryException::class);
});

it('refuses a wildcard origin, which is a security control and not a preference', function (string $origin): void {
    // `*.example.com` reads as "our sites" and means "every host anybody can get a certificate for
    // under example.com". The grammar has no metacharacter for a wildcard, so a matcher cannot be
    // introduced without a migration that has to explain itself — and the comparison this list
    // feeds stays byte equality.
    $org = Organization::factory()->create();
    $bot = Bot::factory()->recycle($org)->create();

    $domain = new BotDomain;
    $domain->organization_id = $org->id;
    $domain->bot_id = $bot->id;
    $domain->origin = $origin;

    expect(saveAndCatch($domain))->toBeInstanceOf(QueryException::class);
})->with([
    'a subdomain wildcard' => ['https://*.example.com'],
    'a bare wildcard' => ['*'],
    'a scheme wildcard' => ['*://example.com'],
    'a path, which is not part of an origin' => ['https://example.com/widget'],
    'a trailing slash, which the browser never sends' => ['https://example.com/'],
    'upper case, which is not the serialized form' => ['https://Example.com'],
    'a non-http scheme' => ['javascript:alert(1)'],
    'userinfo' => ['https://user:pass@example.com'],
]);

it('accepts the exact serialized origins a browser actually sends', function (string $origin): void {
    // The positive control for the dataset above: a CHECK that refused everything would pass every
    // row of it, and an allow-list that refuses `http://localhost:3000` makes local development
    // impossible in a way nobody would notice until they tried.
    $org = Organization::factory()->create();
    $bot = Bot::factory()->recycle($org)->create();

    $domain = new BotDomain;
    $domain->organization_id = $org->id;
    $domain->bot_id = $bot->id;
    $domain->origin = $origin;

    expect(saveAndCatch($domain))->toBeNull("origin {$origin} was refused and should not have been");
})->with([
    'a plain https host' => ['https://example.com'],
    'a subdomain' => ['https://chat.example.com'],
    'an explicit port' => ['https://example.com:8443'],
    'local development over http' => ['http://localhost:3000'],
    'a hyphenated label' => ['https://my-shop.example.co.uk'],
]);

it('refuses collecting end-user data with no consent text to disclose it', function (): void {
    $org = Organization::factory()->create();

    $bot = Bot::factory()->recycle($org)->make();
    $bot->organization_id = $org->id;
    $bot->collect_end_user_data = true;

    expect(saveAndCatch($bot))->toBeInstanceOf(QueryException::class);

    // The positive control: the two together are fine, which is the state the form produces.
    $disclosed = Bot::factory()->recycle($org)
        ->collecting('We store your conversation to improve support.')->create();

    expect($disclosed->refresh()->collect_end_user_data)->toBeTrue()
        ->and($disclosed->consent_text)->not->toBeNull();
});

it('ships the retrieval depths of docs/07 §12.7-12.12 and refuses a retain cap above the candidate depth', function (): void {
    $org = Organization::factory()->create();

    $bot = Bot::factory()->recycle($org)->create()->refresh();

    expect($bot->dense_top_k)->toBe(20)
        ->and($bot->sparse_top_k)->toBe(20)
        ->and($bot->rerank_candidates)->toBe(20)
        ->and($bot->rerank_retain)->toBe(6)
        ->and($bot->retrieval_configuration_version)->toBe(1);

    // The BANDS, both directions. A value outside them is not a tuning choice, it is a typo that
    // changes what the §21.5 regression gate is comparing.
    //
    // NOTE WHAT IS *NOT* ASSERTED HERE: `bots_rerank_retain_within_candidates` is unreachable,
    // because the two bands are disjoint — retain tops out at 10 and candidates starts at 20, so
    // no row this table accepts can violate it. The migration says so at the constraint. A test
    // written against it would have to loosen a band to pass, and would then be asserting a schema
    // nobody ships.
    $bot->rerank_retain = 11;

    expect(saveAndCatch($bot))->toBeInstanceOf(QueryException::class);

    $bot->rerank_retain = 10;
    $bot->rerank_candidates = 31;

    expect(saveAndCatch($bot))->toBeInstanceOf(QueryException::class);

    // The positive control for both: the far end of each band is accepted, so the assertions above
    // are about the boundary rather than about a constraint that refuses everything.
    $bot->rerank_candidates = 30;

    expect(saveAndCatch($bot))->toBeNull();
});
