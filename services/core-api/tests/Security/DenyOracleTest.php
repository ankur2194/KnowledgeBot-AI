<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| The deny oracle — docs/17 §22.5, laravel-rbac-policies NN3
|--------------------------------------------------------------------------
|
| THE 403-ADMIN / 404-PUBLIC SPLIT IS A STATUS RULE, AND A STATUS RULE ALONE CLOSES NOTHING.
|
| An attacker enumerating identifiers does not read the status line in isolation — they diff whole
| responses. If a denied record answers 404 with one body and a record that never existed answers
| 404 with another, the surface is still a cross-tenant existence oracle: probe an id, diff the
| response, learn whether that row exists in somebody else's organization. The same is true one
| level further out, in the HEADERS: an `Allow:` on a wrong-verb probe tells the prober the URI
| exists and which verbs it takes, which is strictly MORE than the 403 the split exists to avoid.
|
| So the property this file asserts is not "denials render 404". It is:
|
|     ON AN ENUMERATION-SENSITIVE SURFACE, EVERY WAY OF SAYING NO IS ONE RESPONSE — SAME STATUS,
|     SAME BODY BYTES, SAME HEADERS.
|
| THERE IS NO REFERENCE STRING IN THIS FILE, DELIBERATELY. Asserting `message === 'The requested
| resource was not found.'` would only prove that this file and bootstrap/app.php agree with each
| other, and they would go on agreeing after both drifted. Every case below is compared against a
| LIVE request to a path on the same surface that certainly has no route — the one response an
| attacker already knows the shape of, and therefore the only honest reference.
|
| WHY THIS TEST DOES NOT CALL tenantPair(), and why that is not rule 1 being skipped. The suite's
| two-organization rule exists for tests that assert TENANT CONTENT does not cross a boundary; a
| one-org fixture cannot fail those, because with one tenant there is nothing to leak. This test
| asserts a property of the RESPONSE SHAPE and carries no tenant content at all — the oracle is
| open or closed identically for one organization or a thousand, and the leak it describes is the
| existence of a row, not its contents. Adding a second organization here would add a fixture and
| no assertion. The cross-tenant content cases are the tenantPair() ones, and they land with the
| models.
|
| Routes are declared INSIDE each test: a scaffolded placeholder in routes/api_public.php is a
| published endpoint, and none of these paths may ever be reachable in production. The render
| closure keys on the request PATH, so a bare Route::get at these prefixes exercises the real one.
*/

beforeEach(function (): void {
    // Reporting an unhandled throwable is the point of several of these; the `stack` channel writes
    // JSON to php://stdout and would interleave with Pest's own output. Silencing the LOGGER never
    // silences the RENDERER — the closure under test is untouched.
    config(['logging.default' => 'null']);
});

/**
 * A request id pinned across every case in this file.
 *
 * `request_id` echoes X-KB-Request-Id when the caller sends one and mints a fresh ULID otherwise.
 * Pinning it is what makes a BYTE comparison of the two bodies meaningful rather than guaranteed to
 * fail on a field that is supposed to differ. An attacker controls this header too, so pinning it
 * is also the realistic probe: they send the same id to both targets and diff.
 */
const DENY_PROBE_REQUEST_ID = '01JKB000000000000000ORACLE';

/**
 * The headers that are allowed to differ between two otherwise identical responses.
 *
 * `Date` is second-granular wall clock and would make this test fail across a second boundary
 * roughly once per suite run — a flake, not a finding. Nothing else is exempt: a header that
 * differs between a denial and a miss is exactly what this file exists to catch, so the list is
 * short and every future addition to it needs the same kind of justification.
 *
 * @var list<string>
 */
const DENY_PROBE_VOLATILE_HEADERS = ['date'];

/**
 * Every header on a response, lowercased and sorted, minus the volatile ones.
 *
 * @param  TestResponse<\Illuminate\Http\JsonResponse>  $response
 * @return array<string, list<string>>
 */
function denyProbeHeaders(TestResponse $response): array
{
    /** @var array<string, list<string>> $headers */
    $headers = $response->headers->all();

    foreach (DENY_PROBE_VOLATILE_HEADERS as $volatile) {
        unset($headers[$volatile]);
    }

    ksort($headers);

    return $headers;
}

/**
 * The reference response: a path on `$prefix` that certainly matches no route.
 *
 * Random per call, so it can never collide with a route another test registered, and so this can
 * never accidentally become "compare a denial against a denial".
 *
 * @return TestResponse<\Illuminate\Http\JsonResponse>
 */
function denyProbeControl(string $prefix): TestResponse
{
    return currentTest()->getJson(
        $prefix.'/'.\Illuminate\Support\Str::ulid()->toBase32(),
        ['X-KB-Request-Id' => DENY_PROBE_REQUEST_ID],
    );
}

// -------------------------------------------------------------------------------------------
// Every way of saying no, on the two enumeration-sensitive surfaces.
// -------------------------------------------------------------------------------------------

/**
 * The deny shapes a real tenant-owned route produces, each keyed by the layer it fires at.
 *
 * These are not hypotheticals: they are the five things that happen when a caller sends somebody
 * else's identifier to `rt/v1/bots/{bot}`, plus the control. A production route reaches them in
 * this order — binding, then policy, then a hand-rolled ownership check — and a caller must not be
 * able to tell WHICH ONE fired, because each of them is a different fact about the row.
 *
 * @return array<string, \Closure(string): TestResponse<\Illuminate\Http\JsonResponse>>
 */
function denyProbeShapes(): array
{
    $get = static fn (string $path): TestResponse => currentTest()->getJson(
        $path,
        ['X-KB-Request-Id' => DENY_PROBE_REQUEST_ID],
    );

    return [
        // Layer 1. ->scopeBindings() resolves {bot} through $organization->bots(), so a foreign id
        // never loads. This is the FIRST thing an id probe hits and the one most likely to be
        // rendered by a different code path, because Laravel converts it before our closure sees it.
        'route-model binding miss' => static fn (string $prefix): TestResponse => $get(
            Route::get("{$prefix}/_probe/binding", static function (): never {
                // The exception TYPE is the load-bearing part: Laravel's prepareException() is what
                // turns a ModelNotFoundException into a 404, so this is the only case here that
                // reaches the renderer through a conversion rather than as itself.
                //
                // The message is written out rather than produced by ->setModel(Bot::class, [...])
                // only because App\Models\Bot does not exist yet and PHPStan requires a real
                // class-string. It is byte-for-byte what setModel() formats.
                // TODO(models): swap to ->setModel(Bot::class, ['01HXYZBOTOFANOTHERORG']) when the
                // model lands, so this cannot drift from Laravel's actual wording.
                throw new ModelNotFoundException(
                    'No query results for model [App\Models\Bot] 01HXYZBOTOFANOTHERORG',
                );
            })->uri(),
        ),

        // Layer 3. The policy denied it — OrgScopedPolicy::refuse() on a public surface returns
        // Response::denyAsNotFound(), which reaches the renderer as a 404-shaped denial.
        'policy denial' => static fn (string $prefix): TestResponse => $get(
            Route::get("{$prefix}/_probe/policy", static function (): never {
                throw new AuthorizationException('Bot 01HXYZ belongs to organization 01HABC.');
            })->uri(),
        ),

        // Layer 3, spelled by hand. abort_unless($x->organization_id === $orgId, 403) on a public
        // route is the enumeration oracle laravel-control-plane names by name; the renderer is what
        // has to save it, because this spelling will keep being written.
        'hand-rolled abort(403)' => static fn (string $prefix): TestResponse => $get(
            Route::get("{$prefix}/_probe/abort403", static fn () => abort(403))->uri(),
        ),

        // A genuine miss inside a handler, with a message that names the record. The message is the
        // leak here, not the status.
        'abort(404) naming the record' => static fn (string $prefix): TestResponse => $get(
            Route::get("{$prefix}/_probe/abort404", static fn () => abort(404, 'Bot 01HXYZ not found.'))->uri(),
        ),

        // The ROUTER's own 405, not a hand-thrown one. Symfony's message names the allowed methods
        // and Laravel's names the URI, and Symfony attaches an `Allow` header to the exception —
        // which is why this case is here in header form and not only in body form.
        'wrong verb on a real route' => static function (string $prefix) use ($get): TestResponse {
            $uri = Route::post("{$prefix}/_probe/verb", static fn () => response()->json([]))->uri();

            return $get($uri);
        },
    ];
}

it('renders every denial byte-identically to a resource that never existed', function (string $prefix): void {
    $control = denyProbeControl($prefix);

    // POSITIVE CONTROL FIRST. Without it, a renderer that 500s on everything, or a surface that
    // stopped routing entirely, satisfies "the two responses match" perfectly.
    $control->assertStatus(404);
    $control->assertJsonPath('error_class', 'authorization');

    // Two statements, not one chain: `->not` is a property of Pest\Expectation and NOT of the
    // Pest\Mixins\Expectation that toBeJson() returns, so `->toBeJson()->not->toBe('')` is an
    // access to an undefined property that happens to work at runtime through __get.
    expect((string) $control->getContent())->toBeJson();
    expect((string) $control->getContent())->not->toBe('');

    foreach (denyProbeShapes() as $label => $probe) {
        $response = $probe($prefix);

        // NOT $response->assertStatus(404, $label): TestResponse::assertStatus() takes ONE
        // argument, and PHP discards extra arguments to a user-defined method without a murmur —
        // so the label would read like a failure message and be nothing at all.
        expect($response->status())->toBe(404, "[{$label}] did not even render 404");

        expect((string) $response->getContent())->toBe(
            (string) $control->getContent(),
            "[{$label}] is distinguishable from a resource that never existed BY ITS BODY. The "
            .'403/404 split is defeated one layer down: an attacker diffs responses, not statuses, '
            .'so this is a cross-tenant existence oracle.',
        );

        expect(denyProbeHeaders($response))->toBe(
            denyProbeHeaders($control),
            "[{$label}] is distinguishable from a resource that never existed BY ITS HEADERS. A "
            .'header the miss does not carry answers the same question the body was stopped from '
            .'answering.',
        );
    }
})->with([
    'public runtime' => 'rt/v1',
    'sdk' => 'sdk/v1',
]);

/**
 * Fragments that must never survive into a public deny body, and what each one would tell a prober.
 *
 * @return array<string, string>
 */
function denyProbeForbiddenFragments(): array
{
    return [
        '01HXYZ' => 'the identifier the caller probed — confirming it addressed something real',
        '01HABC' => 'the organization that owns the record — a cross-tenant fact',
        'Bot' => 'the model class, i.e. what KIND of record the identifier names',
        'unauthorized' => 'that the answer was DENIED rather than MISSING — the oracle in one word',
        'POST' => 'the verb list on a 405, i.e. that the URI exists and what it accepts',
    ];
}

it('leaks neither the identifier, the wording, nor the verb list of anything it denies', function (string $prefix): void {
    // The byte comparison above already implies this, but only as long as the CONTROL stays clean.
    // If a future change leaked an id into the missing-route body too, both sides would leak and
    // the comparison would still pass. This is the assertion that does not depend on the reference.
    //
    // WRITTEN WITH str_contains AND NOT WITH `->not->toContain(...)`, and that is not a style
    // preference — it is a bug this file already had once, found by mutation-testing it. Pest's
    // toContain() is `toContain(mixed ...$needles)`: it takes NO message argument, so a second
    // argument becomes a SECOND NEEDLE. Worse, `not` is implemented by running the positive
    // expectation and treating any failure as success, and the positive expectation throws on the
    // FIRST absent needle — so `->not->toContain($needle, $label)` passes whenever the label is
    // absent from the body, whatever the needle did. It reads like an assertion with a helpful
    // message and is unconditionally green. One needle per expectation, message on toBeFalse().
    foreach (denyProbeShapes() as $label => $probe) {
        $body = (string) $probe($prefix)->getContent();

        foreach (denyProbeForbiddenFragments() as $fragment => $tells) {
            expect(str_contains($body, $fragment))->toBeFalse(
                "[{$label}] leaked [{$fragment}] into the deny body, which tells a prober {$tells}.",
            );
        }
    }
})->with([
    'public runtime' => 'rt/v1',
    'sdk' => 'sdk/v1',
]);

it('keeps the admin surface at 403 — the split still exists to be defeated', function (): void {
    // The oracle is closed by making the four public denials equal, NOT by making every surface
    // answer 404. If this ever goes green at 404, the test above has become vacuous: it would be
    // comparing a miss against a miss on every surface, and would keep passing with the public
    // deny path deleted entirely.
    Route::get('api/v1/_probe/policy', static function (): never {
        throw new AuthorizationException('Bot 01HXYZ belongs to organization 01HABC.');
    });

    $response = currentTest()->getJson('api/v1/_probe/policy', ['X-KB-Request-Id' => DENY_PROBE_REQUEST_ID]);

    $response->assertStatus(403);
    $response->assertJsonPath('error_class', 'authorization');

    // Admin admits existence by design, so this is not an oracle — but the policy's free-form
    // message must still not reach the wire.
    expect((string) $response->getContent())->not->toContain('01HABC');
});
