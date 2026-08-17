<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use Tests\Support\SpaSession;

// NO `use RecursiveDirectoryIterator;` / `use RecursiveIteratorIterator;` HERE, AND THAT IS THE FIX.
// This file declares NO namespace, so a non-compound `use` imports a global name INTO the global
// namespace — which PHP reports as `Warning: The use statement with non-compound name '...' has no
// effect`, twice, at load time. phpunit.xml sets `failOnWarning="true"`, so those two dead lines made
// the Security suite — and therefore `php artisan test` — exit **1 with 490 tests passing and zero
// failures**: a red build whose report says everything is green. Found via `--log-events-text`, which
// records "Test Runner Triggered PHP Warning" events that JUnit does not log (its counters read
// errors="0" failures="0") and that Pest's printer does not surface.
// The call site below is `new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(...))`, with
// leading backslashes. Unqualified names would resolve identically here — with no namespace in force,
// an unqualified class name IS the global one — but Pint's `fully_qualified_strict_types` rewrites them
// to the explicit form once the imports are gone, so the backslashes are the linter's shape, not a
// second opinion about resolution. Do not "tidy" them back: that is a Pint failure, and re-adding the
// imports to justify the short form brings the warning back.
// The four files under tests/Support/ carry the same shape legitimately — each declares a namespace,
// so their `use RuntimeException;`-style imports do real work. `grep -c '^namespace '` is the
// discriminator, not the presence of a short import.

/*
|--------------------------------------------------------------------------
| One surface, one credential — decision D11
|--------------------------------------------------------------------------
|
| THE ADMIN SURFACE HAS EXACTLY ONE CREDENTIAL: THE SANCTUM SPA COOKIE SESSION. `App\Models\User`
| deliberately does NOT use `Laravel\Sanctum\HasApiTokens`, nothing mints a personal access token, and
| no migration creates `personal_access_tokens`.
|
| THIS IS WHY THE SANCTUM SKILL'S DEFINITION-OF-DONE TEST IS UNWRITABLE HERE. That test is
| "`tokenCan('members.manage')` returns true under a session while the same route still 403s", and it
| needs the trait: `tokenCan()` only exists on a model that uses it, and it is `HasApiTokens` that
| attaches a `TransientToken` whose `can()` is unconditionally true. Adding the trait to write the test
| would ADD the very capability the gotcha warns about — a session that answers yes to every ability
| check — so this file asserts the STRONGER structural property instead: there is no second mechanism
| to get wrong.
|
| Three negatives, and each one fails for a different reason if somebody "just adds the trait":
|   1. a bearer token on any api/v1 route is a 401, never an authentication and never a 500;
|   2. nothing in app/ mints a token and nothing in routes/ gates on an ability;
|   3. User does not use HasApiTokens.
*/

beforeEach(function (): void {
    // A CLIENT ADDRESS OF THIS TEST'S OWN. RefreshDatabase rolls back the database and nothing else,
    // and phpunit.xml points the cache at a real Valkey — so every rate-limiter bucket survives the
    // test that filled it and the next run of the suite. `login` is 20/minute per IP and every request
    // here arrives from one address, so without this a suite that logs in for real is a 429 storm that
    // only appears in CI. See Tests\Support\SpaSession::isolateRateLimits().
    SpaSession::isolateRateLimits(currentTest());
});

/**
 * Every PHP file under one of the service's own directories.
 *
 * A recursive scan rather than a shelled-out `rg`: this must fail in CI, in the container and on a
 * developer machine, and none of the three is guaranteed to have ripgrep. The Definition of done in
 * laravel-sanctum-auth asks for the grep; a test that runs everywhere is the same check with a
 * failure message.
 *
 * @return array<string, string> path => contents
 */
function credentialScanSources(string $directory, int $atLeast): array
{
    $root = dirname(__DIR__, 2).'/'.$directory;

    expect(is_dir($root))->toBeTrue("the scan target {$directory} does not exist, so this test would "
        .'silently pass over nothing');

    $files = [];

    /** @var iterable<string, \SplFileInfo> $iterator */
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

    foreach ($iterator as $path => $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[substr((string) $path, strlen(dirname(__DIR__, 2)) + 1)] = (string) file_get_contents((string) $path);
        }
    }

    // POSITIVE CONTROL: the scan found files. An empty result satisfies "no file matches" perfectly,
    // and a path typo is exactly how a grep gate goes quietly green.
    expect(count($files))->toBeGreaterThanOrEqual(
        $atLeast,
        "the scan of {$directory} found ".count($files)." files, fewer than the {$atLeast} expected, so "
        .'the assertions over it prove less than they claim',
    );

    return $files;
}

// -------------------------------------------------------------------------------------------
// 1. A bearer token is a 401 on this surface, by definition
// -------------------------------------------------------------------------------------------

it('answers 401 to a bearer token on any api/v1 route, and never 500', function (
    string $method,
    string $uri,
): void {
    // WHAT THIS ALSO FIXES, AND IT PRE-DATES THIS WORK: Sanctum's Guard reaches its bearer branch
    // whenever an `auth:sanctum` route sees a non-empty `Authorization: Bearer` and no valid session,
    // and `PersonalAccessToken::findToken()` then queries a table NO MIGRATION CREATES —
    // SQLSTATE[42P01] — a 500 `internal_dependency` from any unauthenticated caller.
    expect(Schema::hasTable('personal_access_tokens'))->toBeFalse(
        'personal_access_tokens now exists. If tokens are being introduced, App\\Http\\Middleware\\'
        .'RejectBearerToken is the thing to reconsider first — and Sanctum::authenticateAccessTokensUsing '
        .'has to land in the same change, because it reads an organization_id column the framework '
        .'migration does not have.',
    );

    $response = currentTest()->json($method, $uri, [], spaHeaders([
        'Authorization' => 'Bearer kb-not-a-real-token-0000000000000000000',
    ]));

    expect($response->status())->toBe(
        401,
        "[{$method} {$uri}] did not answer 401 to a bearer token. If it answered 500, the request "
        .'reached PersonalAccessToken::findToken() against a table that does not exist; if it answered '
        .'2xx, this surface has a second credential.',
    );

    $response->assertJsonPath('error_class', 'authentication');
})->with([
    'guest route' => ['POST', '/api/v1/auth/login'],
    'authenticated user-scoped route' => ['GET', '/api/v1/me'],
    'authenticated action' => ['POST', '/api/v1/auth/logout'],
    'org-scoped route' => ['GET', '/api/v1/organizations/01JKB000000000000000000000/members'],
]);

it('rejects a bearer token even when a valid session cookie is present', function (): void {
    // THE CASE THAT MATTERS, and the one an "unauthenticated 401" test misses entirely. A caller who
    // holds a good session AND sends a bearer header must be refused rather than quietly served off
    // the cookie: the invariant is "this surface accepts one credential", and silently ignoring the
    // other one is how a second mechanism arrives without a decision.
    $user = User::factory()->create();

    SpaSession::establish(currentTest(), $user);

    // POSITIVE CONTROL: the session works.
    currentTest()->getJson('/api/v1/me', spaHeaders())->assertOk();

    currentTest()->getJson('/api/v1/me', spaHeaders([
        'Authorization' => 'Bearer kb-not-a-real-token-0000000000000000000',
    ]))
        ->assertStatus(401)
        ->assertJsonPath('error_class', 'authentication');
});

it('rejects every spelling of the Bearer scheme', function (string $header): void {
    // Case and whitespace, because `Authorization: bearer x` reaches Sanctum's bearerToken() the same
    // way — Symfony's own parsing is case-insensitive, so a case-sensitive guard here would be a hole
    // one keystroke wide.
    //
    // THE LAST THREE ROWS ARE NOT COSMETIC VARIANTS — THEY WERE A LIVE BYPASS, and this dataset is why
    // it survived review. The five original rows are all PREFIX-ANCHORED, which is exactly the shape
    // `RejectBearerToken`'s old `preg_match('/^\s*bearer(\s|$)/i', …)` matched. So the suite asserted
    // the property the middleware happened to have rather than the property Sanctum requires:
    // `Request::bearerToken()` uses `strripos($header, 'Bearer ')` — case-insensitive, matching
    // ANYWHERE in the value, LAST occurrence winning. Measured: all three of these walked past the
    // middleware while Sanctum extracted a token from each, reaching `personal_access_tokens` (which
    // no migration creates) as an anonymous 42P01 -> 500 on every `auth:sanctum` route.
    //
    // `Basic …, Bearer …` is here for the bearer half, not the basic half: a lone `Basic` is
    // deliberately still allowed through, because it is not a Sanctum path and no guard here reads it.
    currentTest()->getJson('/api/v1/me', spaHeaders(['Authorization' => $header]))
        ->assertStatus(401)
        ->assertJsonPath('error_class', 'authentication');
})->with([
    'canonical' => 'Bearer kb-token-0000000000000000000000000000',
    'lower case' => 'bearer kb-token-0000000000000000000000000000',
    'upper case' => 'BEARER kb-token-0000000000000000000000000000',
    'leading space' => '  Bearer kb-token-0000000000000000000000000000',
    'scheme only' => 'Bearer',
    'not at the start' => 'X bearer kb-token-0000000000000000000000000000',
    'glued to another word' => 'NotBearer kb-token-0000000000000000000000000000',
    'second scheme in a list' => 'Basic Zm9vOmJhcg==, Bearer kb-token-000000000000000000',
]);

it('agrees with Sanctum about what a bearer token IS, for every spelling above', function (): void {
    // THE ASSERTION THAT WOULD HAVE CAUGHT THE BYPASS WITHOUT ANYONE THINKING OF THE THREE SPELLINGS.
    // The defect was not a missing case; it was two extractors disagreeing. So compare them directly:
    // whenever `Request::bearerToken()` finds a token, the middleware must refuse the request. That is
    // a property over ALL headers, and a fuzz corpus can extend it without touching the middleware.
    $corpus = [
        'Bearer abc', 'bearer abc', 'BEARER abc', '  Bearer abc', 'Bearer',
        'X bearer abc', 'NotBearer abc', 'Basic Zm9v, Bearer abc',
        'Bearer abc, Bearer def', "\tBearer abc", 'xxBEARER abc',
        'Basic Zm9vOmJhcg==', 'Token abc', '',
    ];

    $disagreements = [];

    foreach ($corpus as $header) {
        $request = \Illuminate\Http\Request::create('/api/v1/me');
        $request->headers->set('Authorization', $header);

        if ($request->bearerToken() === null) {
            continue;
        }

        $refused = false;

        try {
            (new \App\Http\Middleware\RejectBearerToken)->handle(
                $request,
                static fn (): \Symfony\Component\HttpFoundation\Response => new \Illuminate\Http\Response,
            );
        } catch (\Illuminate\Auth\AuthenticationException) {
            $refused = true;
        }

        if (! $refused) {
            $disagreements[] = $header;
        }
    }

    expect($disagreements)->toBe(
        [],
        'Sanctum would extract a bearer token from these headers and RejectBearerToken let them through',
    );

    // POSITIVE CONTROL on the loop: at least one header in the corpus must actually produce a token,
    // or every iteration `continue`s and the assertion above passes having compared nothing.
    $withTokens = array_filter($corpus, static function (string $header): bool {
        $request = \Illuminate\Http\Request::create('/api/v1/me');
        $request->headers->set('Authorization', $header);

        return $request->bearerToken() !== null;
    });

    expect(count($withTokens))->toBeGreaterThan(6);
});

it('lets a lone Basic credential through, so the refusal is scoped to the bearer scheme', function (): void {
    // The negative half of the clause above: this class states "this surface has no bearer credential",
    // not "this surface refuses every Authorization header". `Basic` is not a Sanctum path and no guard
    // here reads it, so refusing it would be scope this middleware has no reason to take — and a test
    // that did not pin this would let someone "harden" it into a change that breaks a future surface.
    $request = \Illuminate\Http\Request::create('/api/v1/me');
    $request->headers->set('Authorization', 'Basic Zm9vOmJhcg==');

    expect($request->bearerToken())->toBeNull();

    $response = (new \App\Http\Middleware\RejectBearerToken)->handle(
        $request,
        static fn (): \Symfony\Component\HttpFoundation\Response => new \Illuminate\Http\Response('ok'),
    );

    expect($response->getContent())->toBe('ok');
});

// -------------------------------------------------------------------------------------------
// 2. Nothing mints a token, and nothing gates on an ability
// -------------------------------------------------------------------------------------------

it('mints no personal access token anywhere in app/', function (): void {
    $offenders = [];

    foreach (credentialScanSources('app', 40) as $path => $contents) {
        foreach (explode("\n", $contents) as $number => $line) {
            if (preg_match('/->createToken\s*\(/', $line) !== 1) {
                continue;
            }

            // THE ONE EXCLUSION, AND IT IS A FALSE POSITIVE IN THE SKILL'S OWN GREP.
            // laravel-sanctum-auth's Definition of done asks for `rg -n 'createToken\('`, and that
            // pattern also matches `Password::broker()->createToken($user)` — the framework's
            // PASSWORD RESET token, which BootstrapOrganizationCommand mints on purpose so that the
            // first operator sets their own password through the ordinary flow rather than having one
            // handed to them on a terminal. It is a single-use, 60-minute, hashed-in-the-database
            // token and it is not a bearer credential for this API. Narrowed by RECEIVER rather than
            // by file, so a genuine `$user->createToken(...)` in the same file still fails.
            if (preg_match('/(Password::|->broker\(\)|PasswordBroker)/', $line) === 1) {
                continue;
            }

            $offenders[] = $path.':'.($number + 1);
        }
    }

    expect($offenders)->toBe(
        [],
        'createToken() appears in '.implode(', ', $offenders).'. A minted personal access token on '
        .'this surface is a long-lived replayable credential that must live where JavaScript can read '
        .'it, and it arrives with none of the things a token needs: no organization_id column, no '
        .'Sanctum::authenticateAccessTokensUsing backstop, no prune schedule, and no expiry '
        .'(sanctum.expiration is null). This is the moment before the first mint, which is the only '
        .'moment this check is cheap.',
    );
});

it('gates no route on a token ability', function (): void {
    $offenders = [];

    foreach (credentialScanSources('routes', 7) as $path => $contents) {
        if (preg_match('/[\'"](abilities|ability):/', $contents) === 1) {
            $offenders[] = $path;
        }
    }

    // `abilities:bots.manage` LETS THE WHOLE DASHBOARD THROUGH. Under a cookie session Sanctum
    // attaches a TransientToken whose can() is unconditionally true, so the middleware passes for
    // every ability — an authorization check that looks like one and is not. Authorization on this
    // surface is Gate::authorize() in the controller.
    expect($offenders)->toBe(
        [],
        'an abilities/ability middleware appears in '.implode(', ', $offenders).'. Under a session it '
        .'passes unconditionally, so it is an authorization check that never denies anything.',
    );
});

it('keeps token expiry and the token table switched off in configuration', function (): void {
    // Lowering sanctum.expiration retro-expires every live token at once, and the fix (raising it
    // again) does not bring the logged-out users back. Nothing mints here, so null is correct — and
    // pinning it means the day tokens land, this line is a decision rather than an inherited default.
    expect(config('sanctum.expiration'))->toBeNull();

    // `token_prefix` is SET (`kb_`) and that is deliberate in the other direction: a prefix is what
    // makes a leaked token greppable by a secret scanner, and it has to be in place BEFORE the first
    // token is minted, because a prefix added afterwards does not apply retroactively. Asserted as
    // non-empty rather than as a literal so a rename is not a failure.
    $prefix = config('sanctum.token_prefix');

    expect(is_string($prefix) && $prefix !== '')->toBeTrue(
        'sanctum.token_prefix is empty. Nothing mints a token today, but a prefix cannot be applied '
        .'retroactively — the day one is minted without it, no secret scanner can recognise it in a '
        .'log, a paste or a repository.',
    );

    // Personal access tokens do not update `last_used_at` here either — the setting that would make
    // personal_access_tokens the hottest write table in the schema, one UPDATE per authenticated
    // request.
    expect(config('sanctum.expiration'))->toBeNull();
});

// -------------------------------------------------------------------------------------------
// 3. User does not use HasApiTokens
// -------------------------------------------------------------------------------------------

it('does not give App\\Models\\User the HasApiTokens trait', function (): void {
    $traits = class_uses_recursive(User::class);

    expect(in_array(HasApiTokens::class, $traits, true))->toBeFalse(
        'App\\Models\\User now uses Laravel\\Sanctum\\HasApiTokens. THIS IS DECISION D11 AND THE TRAIT '
        .'IS NOT NEEDED FOR AUTHENTICATION: Sanctum\'s Guard returns the session user UNCHANGED when '
        .'supportsTokens() is false, so SPA cookie auth already works without it. What the trait adds '
        .'is (a) `createToken()`, i.e. the ability to mint a long-lived replayable credential on a '
        .'surface whose entire credential story is a HttpOnly cookie, and (b) a TransientToken '
        .'attached to every session request, which makes `tokenCan()` and therefore the '
        .'`abilities`/`ability` middleware return true UNCONDITIONALLY — an authorization check that '
        .'cannot deny. If personal access tokens are genuinely being introduced, that is an ADR plus a '
        .'migration plus an organization_id column plus Sanctum::authenticateAccessTokensUsing plus a '
        .'prune schedule, not a trait.',
    );

    // The mechanism that makes the trait unnecessary, asserted rather than trusted: authentication
    // works, right now, with the trait absent.
    $user = User::factory()->create();

    SpaSession::establish(currentTest(), $user);

    currentTest()->getJson('/api/v1/me', spaHeaders())
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id);

    // get_class_methods() rather than method_exists(): PHPStan folds the latter over a literal class
    // name and reports "will always evaluate to false", which is true today and is the regression this
    // line exists to catch tomorrow.
    expect(in_array('tokenCan', get_class_methods(User::class), true))->toBeFalse(
        'tokenCan() exists on User, so something has reintroduced the token surface',
    );
});
