<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Suite bindings
|--------------------------------------------------------------------------
|
| RefreshDatabase everywhere except Integration/. On Laravel 13 it migrates only when the schema is
| stale and otherwise wraps each test in a transaction it rolls back — one BEGIN/ROLLBACK per test.
| DatabaseMigrations re-runs every migration per test and turns a 90-second suite into minutes; it
| is never the right answer here.
*/
pest()->extend(\Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Security', 'Contract');

/*
| DatabaseTruncation costs a TRUNCATE sweep per test and is the price of admission for EXACTLY ONE
| thing: another process must be able to see the rows. That is the async callback path (Celery calls
| back into Laravel), a real queue worker, and any test whose assertion is made by the SSE fixture
| server. RefreshDatabase holds an open transaction, so those rows do not exist for any other
| connection and the worker's writes vanish on rollback.
|
| The synchronous chat path does NOT need this: Laravel sends the whole resolved configuration
| snapshot in the request body rather than having FastAPI read its tables.
|
| If a queue or callback test finds nothing, MOVE IT HERE. Do not "fix" it by asserting on the job
| payload instead — that is how these tests stop testing anything.
*/
pest()->extend(\Tests\TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Integration');

/*
| Unit/ extends NOTHING. No container, no database, no facades — mock the repository interface. If a
| unit test needs one of those three, it is a Feature test and belongs in that directory.
| Arch/ extends nothing either: arch() reflects over the autoloaded namespaces without booting.
|
| TODO(perf): pest-testing also lists
|   pest()->use(Illuminate\Foundation\Testing\WithCachedConfig::class)->in('Feature', 'Security');
| Left out until it can actually be run: it is worth adding, but a config cache that hides a
| per-test config() override is a confusing failure to debug for the first person who hits it.
*/

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
|
| tenantPair() and the TenantPair value object. Autoloaded here so every suite can reach it without
| a use statement, which is deliberate: writing a LEAKY test must take more effort than writing a
| correct one. There is no single-organization helper to reach for, and there will not be one — with
| one tenant there is nothing to leak, so the test passes against code with no filter at all.
*/
require_once __DIR__.'/Support/tenancy.php';

/*
| spaHeaders(). Autoloaded for the same reason tenancy() is: a Sanctum SPA session does not exist in
| the suite unless the request carries an Origin that matches config('sanctum.stateful'), and the
| symptom of forgetting it is a 500 reading "Session store not set on request." rather than a 401.
| A helper every suite can reach without a use statement is what stops a Feature test failing that
| way silently. The file records the other half of the trap (phpunit.xml's SANCTUM_STATEFUL_DOMAINS)
| and why CSRF cannot be tested by sending a bad token.
*/
require_once __DIR__.'/Support/spa.php';

/*
| chatFixture(), chatSessionToken() and chatHeaders(). Autoloaded for the same reason the two above
| are: a public-runtime request needs a bot that is published AND public AND has an ACTIVE origin
| row AND names an enabled model AND has an assigned source with a PUBLISHED version — five separate
| facts, and a fixture missing any one of them fails with a 404 or a `validation` refusal that reads
| as a product defect rather than as a missing row.
|
| The helper writes the ingestion rows directly and says at length why that is not the thing
| `KnowledgeSourceFactory::indexed()` refuses to do: it writes the rows the SCOPE QUERY reads, and
| nothing that stands in for an index.
*/
require_once __DIR__.'/Support/chat.php';

/**
 * The running test case.
 *
 * NOT `test()`: that returns a HigherOrderTapProxy, so `test()->getJson(...)` works only through
 * `__call` and is untypeable — PHPStan sees `TestCall|HigherOrderTapProxy` and neither declares
 * getJson(). Pest's own registry holds the real instance. Narrowed once, here, so no call site
 * anywhere in the suite needs an annotation.
 */
function currentTest(): \Tests\TestCase
{
    $case = \Pest\TestSuite::getInstance()->test;

    assert($case instanceof \Tests\TestCase);

    return $case;
}

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

/**
 * A denial on an enumeration-sensitive surface is indistinguishable from a resource that never
 * existed — in the STATUS and in the BODY.
 *
 * THE REFERENCE IS NOT A LITERAL. Asserting `message === 'The requested resource was not found.'`
 * would only prove this file and bootstrap/app.php agree with each other, and would keep agreeing
 * after both drifted. Instead this fires a real request at a path on the same surface that
 * certainly has no route, reusing the request id of the response under test so even that field
 * matches, and requires the two raw bodies to be equal byte for byte. The property being asserted
 * is exactly the sentence: "a caller cannot tell a denied record from one that does not exist."
 *
 * Use it on `rt/*` and `sdk/*`. The admin surface answers 403 by design and is not enumeration-
 * sensitive — a member of the organization is already entitled to know the record is there.
 *
 * @param  string  $surfacePrefix  e.g. 'rt/v1' or 'sdk/v1'
 */
expect()->extend('toDenyAsNotFound', function (string $surfacePrefix): void {
    $response = $this->value;

    assert($response instanceof \Illuminate\Testing\TestResponse);

    $response->assertStatus(404);

    $requestId = $response->json('request_id');

    assert(is_string($requestId) && $requestId !== '', 'the deny envelope carried no request_id');

    // A path no route can match, on the same surface, with the same request id. Random so it can
    // never collide with a route a test registered.
    $control = currentTest()->getJson(
        rtrim($surfacePrefix, '/').'/'.\Illuminate\Support\Str::ulid()->toBase32(),
        ['X-KB-Request-Id' => $requestId],
    );

    $control->assertStatus(404);

    // Cast before comparing: getContent() reaches TestResponse through __call and is typed
    // `string|false`, which leaves expect()'s TValue unresolvable.
    expect((string) $response->getContent())->toBe(
        (string) $control->getContent(),
        'a denied resource is distinguishable from one that never existed — the 403/404 status '
        .'split is defeated by the response BODY, which is what an attacker actually reads',
    );
});
