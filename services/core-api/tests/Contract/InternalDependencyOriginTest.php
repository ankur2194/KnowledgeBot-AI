<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-plane parity: the RENDERING of internal_dependency (ADR-029, finding O1)
|--------------------------------------------------------------------------
|
| ErrorTaxonomyParityTest compares the two TABLES. This file compares the two RENDERINGS, and the
| distinction is the whole reason it exists: the tables can agree perfectly while Laravel's render
| closure stops consulting the origin axis, and nothing in this repository would notice.
|
| The mutation this is aimed at is one deleted argument. bootstrap/app.php reads `$origin` twice —
| once to pick the status, once to pick `retryable` — and BOTH have a default that silently means
| `downstream`:
|
|     ErrorTaxonomy::retryable($errorClass, $origin)      ->  drop $origin, get `true`
|     $origin === ORIGIN_SELF ? 500 : 503                 ->  simplify to 503, get a brownout
|
| Either edit restores exactly the split O1 closed — 503 from one plane and 500 from the other for
| the same unhandled bug — and a client obeying the envelope then retries one plane's defects down a
| full backoff ladder while reporting the other's immediately. It cannot tell which plane it is
| talking to, so it cannot compensate. Both edits look like cleanup in a diff.
|
| NOTHING BELOW IS A LITERAL COPY OF THE PYTHON SIDE. The expected status, the expected retry
| verdict, the expected message string and the expected KEY ORDER are all extracted from
| services/ai-service/ as data and then asserted against Laravel's live HTTP rendering. A test that
| hard-coded `500` would keep passing after FastAPI moved to something else, which is the failure
| this file is named after.
|
| It is a CONTRACT test rather than a Feature test because the thing under test is the SEAM: not
| "does Laravel render 500" but "do the two planes render the same situation identically". The
| Feature suite pins Laravel's behaviour against our own intent; this pins it against the other
| runtime's source.
|
| PARSING PYTHON WITH REGEX IS ONLY SAFE BECAUSE EVERY EXTRACTION THROWS ON A MISS. An extraction
| that quietly returned null would make the comparison it feeds vacuous, and the suite would go
| green on the day it stopped testing anything.
*/

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

beforeEach(function (): void {
    // Reporting the unhandled throwable is the point; its JSON log line would interleave with
    // Pest's output. Silencing the LOGGER never silences the RENDERER.
    config(['logging.default' => 'null']);
});

/** Pinned so `request_id` is not the field that breaks an equality assertion. */
const ORIGIN_PROBE_REQUEST_ID = '01JKB00000000000000ORIGIN0';

/**
 * A file under services/ai-service/, resolved from this one.
 *
 * tests/Contract -> tests -> core-api -> services -> <repository root>.
 */
function kbAiServiceSource(string $relative): string
{
    $path = dirname(__DIR__, 4).'/services/ai-service/'.$relative;

    expect(is_file($path))->toBeTrue(
        "The FastAPI plane is not at [{$path}]. This test compares the two planes' RENDERINGS and "
        .'cannot do that from half a checkout — run the suite from the monorepo, and if the file '
        .'genuinely moved, fix this path rather than deleting the test.',
    );

    return (string) file_get_contents($path);
}

/**
 * @param  non-empty-string  $pattern
 */
function kbExtract(string $pattern, string $subject, string $what): string
{
    if (preg_match($pattern, $subject, $m) !== 1) {
        // A THROW, NOT AN EMPTY STRING. See the note at the top of the file.
        throw new \RuntimeException(
            "Could not extract {$what} from the FastAPI plane. The file was restructured; update "
            .'this parser rather than deleting the assertion it feeds.',
        );
    }

    return $m[1];
}

/**
 * `_STATUS[ErrorClass.INTERNAL_DEPENDENCY]` — the DOWNSTREAM rendering, read from errors.py.
 */
function kbPythonDownstreamStatus(): int
{
    return (int) kbExtract(
        '/ErrorClass\.INTERNAL_DEPENDENCY: (\d{3}),/',
        kbAiServiceSource('app/core/errors.py'),
        'the _STATUS row for internal_dependency',
    );
}

/**
 * The SELF override inside `status_for()` — the 500 that ADR-029 introduced.
 *
 * Read from the FUNCTION BODY and not from `_STATUS`, because that is where the override lives: a
 * `_STATUS` row unchanged at 503 is correct and expected, and reading it here would assert nothing.
 */
function kbPythonSelfOriginStatus(): int
{
    $source = kbAiServiceSource('app/core/errors.py');

    $body = kbExtract(
        '/def status_for\((.*?)\n\ndef /s',
        $source,
        'the body of status_for()',
    );

    return (int) kbExtract(
        '/if error_class is ErrorClass\.INTERNAL_DEPENDENCY and origin is Origin\.SELF:\s+return (\d{3})/',
        $body,
        'the ADR-029 self-origin status override in status_for()',
    );
}

/**
 * The message `_handle_unexpected()` puts on the wire, and the proof it passes `origin=Origin.SELF`.
 *
 * The origin assertion is not incidental. If FastAPI drops that keyword the KbError constructor
 * defaults to `Origin.DOWNSTREAM`, FastAPI silently returns to 503/retryable for its own bugs, and
 * Laravel — still correct — becomes the plane that disagrees.
 */
function kbPythonSelfOriginMessage(): string
{
    $source = kbAiServiceSource('app/main.py');

    $body = kbExtract(
        '/async def _handle_unexpected\((.*?)\n\ndef /s',
        $source,
        'the body of _handle_unexpected()',
    );

    expect($body)->toContain('ErrorClass.INTERNAL_DEPENDENCY')
        ->and($body)->toContain('origin=Origin.SELF')
        // AND THAT IT MARKS ITSELF NON-ACTIONABLE (finding J2). This handler's message is the
        // placeholder by definition; dropping the argument would default it to True and publish
        // "this sentence was written for you" on a string chosen to say nothing — which is the
        // one direction of that field's failure that reaches a tenant.
        ->and($body)->toContain('actionable=False');

    // READ FROM THE MODULE CONSTANT, NOT FROM THE HANDLER BODY. The literal used to be inline and
    // this parser read it out of `$body`; it is a named constant now precisely because `actionable`
    // retired the client-side comparison that made a second spelling of it dangerous. Reading the
    // constant keeps this assertion pinned to the same bytes without depending on where they sit.
    return kbExtract(
        '/^SERVICE_FAILURE_MESSAGE = "([^"]+)"$/m',
        $source,
        'the SERVICE_FAILURE_MESSAGE constant used by _handle_unexpected()',
    );
}

/**
 * The envelope's key order, read from `_envelope()`'s `content={...}` literal in main.py.
 *
 * "Byte-identical" is a claim about the serialized bytes, and two JSON objects with the same pairs
 * in a different order are not the same bytes. A consumer parsing them cannot tell — but a consumer
 * hashing, caching, signing, or golden-file-diffing an envelope can, and so can anyone trying to
 * work out which plane produced a captured response.
 *
 * @return list<string>
 */
function kbPythonEnvelopeKeys(): array
{
    $content = kbExtract(
        '/content=\{(.*?)\n        \},/s',
        kbAiServiceSource('app/main.py'),
        'the content= literal in _envelope()',
    );

    preg_match_all('/^\s+"([a-z_]+)":/m', $content, $keys);

    expect($keys[1])->toHaveCount(5, 'the envelope is exactly five keys — the parser found something else');

    return $keys[1];
}

/**
 * Register a route that throws, and request it.
 *
 * @return TestResponse<JsonResponse>
 */
function originProbe(string $path, \Throwable $e): TestResponse
{
    Route::get($path, static function () use ($e): never {
        throw $e;
    });

    return currentTest()->getJson($path, ['X-KB-Request-Id' => ORIGIN_PROBE_REQUEST_ID]);
}

// -------------------------------------------------------------------------------------------

it('reads the FastAPI rendering as data, and finds every part of it', function (): void {
    // THE GUARD ON EVERY ASSERTION BELOW. Each extractor throws on a miss rather than returning
    // nothing, and this test is where that is proven before anything depends on it.
    expect(kbPythonSelfOriginStatus())->toBe(500)
        ->and(kbPythonDownstreamStatus())->toBe(503)
        ->and(kbPythonSelfOriginMessage())->not->toBe('')
        ->and(kbPythonEnvelopeKeys())->toBe(['error_class', 'message', 'retryable', 'request_id', 'actionable']);
});

it('renders an unhandled exception exactly as FastAPI _handle_unexpected does', function (): void {
    // THE PIN. Status, class, retry verdict and message all come from the other plane's source.
    $response = originProbe('api/v1/_origin/unhandled', new \RuntimeException('null pointer in BotService'));

    $response->assertStatus(kbPythonSelfOriginStatus());
    $response->assertJsonPath('error_class', 'internal_dependency');

    // assertJsonPath compares with assertSame, so `0` or `"false"` fails here — which is the point:
    // a client branches on this field and a truthy zero is not a false.
    $response->assertJsonPath('retryable', false);
    $response->assertJsonPath('message', kbPythonSelfOriginMessage());

    // AND THE SAME `actionable`, read from the other plane's source rather than restated. This is
    // the field that separates this response from a deliberate 409, which shares its class and its
    // retry verdict — so a divergence here is a client rendering one plane's placeholder as advice.
    $response->assertJsonPath('actionable', false);

    // Same five keys, same order, so the two planes' envelopes are the same BYTES.
    expect(array_keys((array) $response->json()))->toBe(kbPythonEnvelopeKeys());
});

it('still renders a real downstream failure as FastAPI would, so the fix is not "always 500"', function (): void {
    // Collapsing both readings into 500 was option (c) of finding O1 and was rejected: "come back
    // shortly" is a true and load-bearing statement when a dependency is having a moment, and this
    // is the assertion that stops the self-origin pin above being satisfied by deleting the split.
    $response = originProbe('api/v1/_origin/downstream', new ServiceUnavailableHttpException(null, 'ai-service is draining'));

    $response->assertStatus(kbPythonDownstreamStatus());
    $response->assertJsonPath('error_class', 'internal_dependency');
    $response->assertJsonPath('retryable', true);
});

it('fails if the origin argument is dropped from either rendering site', function (): void {
    // THE MUTATION GUARD, WRITTEN AS ONE ASSERTION SO IT CANNOT BE HALF-DELETED.
    //
    // bootstrap/app.php consults $origin twice and each site defaults to `downstream` when the
    // argument goes missing, so a dropped argument moves exactly one of these two fields and leaves
    // the other looking right:
    //
    //   drop it from ErrorTaxonomy::retryable(...)   -> retryable becomes true, status stays 500
    //   drop it from the status match arm            -> status becomes 503, retryable stays false
    //
    // Asserting the PAIR of responses differs in BOTH fields catches either edit alone. Asserting
    // only one of them catches only one of the edits.
    $self = originProbe('api/v1/_origin/pair-self', new \RuntimeException('ours'));
    $downstream = originProbe('api/v1/_origin/pair-down', new ServiceUnavailableHttpException);

    expect($self->json('error_class'))->toBe($downstream->json('error_class'))
        ->and($self->status())->toBe(kbPythonSelfOriginStatus())
        ->and($downstream->status())->toBe(kbPythonDownstreamStatus())
        ->and($self->json('retryable'))->toBeFalse()
        ->and($downstream->json('retryable'))->toBeTrue();

    // And the two differ in BOTH axes at once — which is what "origin selects the rendering" means,
    // as opposed to "retryable happens to be derived from the status".
    expect($self->status())->not->toBe($downstream->status())
        ->and($self->json('retryable'))->not->toBe($downstream->json('retryable'));
});

it('does not carry origin onto the wire', function (): void {
    // Origin is a RENDERING INPUT, not a nineteenth field. It selects the status and the retry
    // verdict, both of which the envelope already carries; publishing it would invite a client to
    // branch on "was this your bug or theirs", which is not a question a client can act on and not
    // one we want to answer. FastAPI's _envelope() emits five keys and so does this one.
    //
    // `actionable` IS on the wire and is not a counter-example: it answers "may I show this
    // message", which a client can act on, rather than "whose bug was it", which it cannot.
    $response = originProbe('api/v1/_origin/no-wire-field', new \RuntimeException('ours'));

    expect((array) $response->json())->not->toHaveKey('origin')
        ->and((string) $response->getContent())->not->toContain('downstream')
        ->and((string) $response->getContent())->not->toContain('"self"');
});
