<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/*
|--------------------------------------------------------------------------
| The error envelope — bootstrap/app.php's single render closure
|--------------------------------------------------------------------------
|
| THE REGRESSION GUARD FOR FINDING O1 (ADR-029).
|
| The same failure — an unmapped exception in our OWN code — used to render 500/retryable:false
| here and 503/retryable:true from FastAPI. A client obeying the envelope therefore retried one
| plane's defects down a full backoff ladder and reported the other's immediately, and it could not
| tell which plane it was talking to. `internal_dependency` now carries a second axis, ORIGIN —
| `self` for our own defect (500, not retryable), `downstream` for a dependency that is briefly
| unavailable (503, retryable) — exactly the way `authorization` already carries `Surface`. The
| taxonomy STAYS AT 18 CLASSES; origin is a rendering input, not a nineteenth member, and it is not
| a wire field.
|
| The cases below are pinned against services/ai-service/app/core/errors.py (Origin, status_for,
| retryable_for) and app/main.py::_handle_unexpected. If one plane's rendering is edited, this file
| is where the divergence must show up.
|
| Routes are declared INSIDE each test on purpose: a scaffolded placeholder route in
| routes/api_admin.php is a published endpoint, and these paths must never be reachable in
| production. The render closure keys on the request PATH (`api/*`, `rt/*`, `sdk/*`, `internal/*`),
| so a bare Route::get at those prefixes exercises the real closure.
*/

beforeEach(function (): void {
    // The default `stack` channel writes JSON to php://stdout. Reporting an unhandled throwable is
    // the point of half these tests, and its log line would interleave with Pest's own output.
    // Silencing the LOGGER never silences the RENDERER — the closure under test is unaffected.
    config(['logging.default' => 'null']);
});

/**
 * Register a route on `$path` whose handler throws `$e`, then request it as JSON.
 *
 * @return TestResponse<JsonResponse>
 */
function throwingRoute(string $path, \Throwable $e): TestResponse
{
    Route::get($path, static function () use ($e): never {
        throw $e;
    });

    return currentTest()->getJson($path, ['X-KB-Request-Id' => '01JKB0000000000000000ERROR']);
}

// -------------------------------------------------------------------------------------------
// origin = self — our own defect. 500, never retryable.
// -------------------------------------------------------------------------------------------

it('renders an unmapped throwable as 500 internal_dependency and NOT retryable', function (): void {
    $response = throwingRoute('api/v1/_test/unmapped', new \RuntimeException('null pointer in BotService'));

    $response->assertStatus(500);

    // assertJsonPath compares with assertSame, so `retryable: 0` or `"false"` fails here — which is
    // the point: a client branches on this field, and a truthy zero is not a false.
    $response->assertJsonPath('error_class', 'internal_dependency');
    $response->assertJsonPath('retryable', false);

    // Byte-identical to app/main.py::_handle_unexpected. "an internal dependency failed" would be a
    // false statement: nothing downstream failed, we did.
    $response->assertJsonPath('message', 'The service could not complete this request.');

    // The exception message is operator-only. Provider error bodies routinely echo the request,
    // including credentials, so no 5xx ever renders getMessage().
    expect($response->getContent())->not->toContain('null pointer in BotService');

    // One failure, greppable across both services.
    $response->assertJsonPath('request_id', '01JKB0000000000000000ERROR');
});

it('renders an explicit abort(500) as self-origin too', function (): void {
    // abort(500) is our own code declaring a failure it could not classify. Nothing downstream
    // said it was unavailable, so it is not a brownout and no retry fixes it.
    Route::get('api/v1/_test/abort500', static fn () => abort(500));

    $response = currentTest()->getJson('api/v1/_test/abort500');

    $response->assertStatus(500);
    $response->assertJsonPath('error_class', 'internal_dependency');
    $response->assertJsonPath('retryable', false);
});

it('renders a self-origin internal_dependency identically on every surface', function (string $path): void {
    // Origin is orthogonal to Surface. The public runtime and SDK render `authorization` as 404 to
    // avoid an enumeration oracle; there is no equivalent reason to soften a 500, and a client that
    // cannot tell which SURFACE it hit must not get a different retry instruction either.
    $response = throwingRoute($path, new \LogicException('boom'));

    $response->assertStatus(500);
    $response->assertJsonPath('error_class', 'internal_dependency');
    $response->assertJsonPath('retryable', false);
})->with([
    'admin' => 'api/v1/_test/surface',
    'public runtime' => 'rt/v1/_test/surface',
    'sdk' => 'sdk/v1/_test/surface',
    'internal' => 'internal/v1/_test/surface',
]);

// -------------------------------------------------------------------------------------------
// origin = downstream — a real dependency is briefly unavailable. 503, retryable.
// -------------------------------------------------------------------------------------------

it('renders a genuine downstream dependency failure as 503 internal_dependency and retryable', function (\Throwable $e): void {
    // This is the row that must NOT be collapsed into 500. "Come back shortly" is a true and
    // load-bearing statement when something we depend on is having a moment; option (c) of finding
    // O1 — both planes just render 500 — was rejected precisely because it destroys this signal.
    $response = throwingRoute('api/v1/_test/downstream', $e);

    $response->assertStatus(503);
    $response->assertJsonPath('error_class', 'internal_dependency');
    $response->assertJsonPath('retryable', true);
    $response->assertJsonPath('message', 'The service could not complete this request.');
})->with([
    '503 service unavailable' => fn () => new ServiceUnavailableHttpException(null, 'ai-service is draining'),
    // Symfony ships no BadGatewayHttpException; 502 is a bare HttpException.
    '502 bad gateway' => fn () => new HttpException(502, 'qdrant returned garbage'),
]);

// -------------------------------------------------------------------------------------------
// The coupling that finding O1 removed.
// -------------------------------------------------------------------------------------------

it('derives retryable from origin, not from the rendered status', function (): void {
    // The two verdicts used to be one expression: `internal_dependency && $status === 503`. Change
    // the downstream RENDERING and the retry verdict silently followed it, with nothing saying a
    // client had just been told to retry a defect. These two responses share a class and differ in
    // BOTH status and retryable — and they differ because of origin, not because 500 !== 503.
    $selfOrigin = throwingRoute('api/v1/_test/pair-self', new \RuntimeException('ours'));
    $downstream = throwingRoute('api/v1/_test/pair-down', new ServiceUnavailableHttpException);

    expect($selfOrigin->json('error_class'))->toBe($downstream->json('error_class'))
        ->and($selfOrigin->json('retryable'))->toBeFalse()
        ->and($downstream->json('retryable'))->toBeTrue()
        ->and($selfOrigin->status())->toBe(500)
        ->and($downstream->status())->toBe(503);
});

it('does not promote an unclassified 4xx to retryable', function (): void {
    // The fall-through arm keeps a 4xx at its own status and still labels it internal_dependency.
    // Nothing downstream declared itself unavailable, so it is self-origin: telling a client to
    // retry a 409 forever is the same defect O1 describes, one status family down.
    $response = throwingRoute('api/v1/_test/conflict', new ConflictHttpException('version already active'));

    $response->assertStatus(409);
    $response->assertJsonPath('error_class', 'internal_dependency');
    $response->assertJsonPath('retryable', false);
});

// -------------------------------------------------------------------------------------------
// The other 17 classes must be untouched by the sub-case.
// -------------------------------------------------------------------------------------------

it('still renders rate_limit as 429, retryable, with Retry-After', function (): void {
    // `internal_dependency` joined the retryable list as part of this change (it is True in
    // RETRYABLE on the Python side; the SELF override is what subtracts it). This asserts the edit
    // did not disturb the rows that were already there.
    $response = throwingRoute(
        'api/v1/_test/throttle',
        new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => 30]),
    );

    $response->assertStatus(429);
    $response->assertJsonPath('error_class', 'rate_limit');
    $response->assertJsonPath('retryable', true);
    expect($response->headers->get('Retry-After'))->toBe('30');
});

it('still renders validation as 422 with the errors superset and never retryable', function (): void {
    $response = throwingRoute(
        'api/v1/_test/validation',
        ValidationException::withMessages(['name' => ['The name field is required.']]),
    );

    $response->assertStatus(422);
    $response->assertJsonPath('error_class', 'validation');
    $response->assertJsonPath('retryable', false);
    // A MAP of field -> messages, never a list: applyServerErrors in packages/contracts keys on it.
    $response->assertJsonPath('errors.name.0', 'The name field is required.');
});

it('omits the errors key on every class except validation', function (): void {
    // `errors` is a SUPERSET present only on `validation` — never null, never {} elsewhere.
    $response = throwingRoute('api/v1/_test/no-errors-key', new \RuntimeException('boom'));

    expect($response->json())->toHaveKeys(['error_class', 'message', 'retryable', 'request_id'])
        ->and($response->json())->not->toHaveKey('errors');
});

it('still renders authentication as 401 and never retryable', function (): void {
    $response = throwingRoute('api/v1/_test/unauthenticated', new AuthenticationException);

    $response->assertStatus(401);
    $response->assertJsonPath('error_class', 'authentication');
    $response->assertJsonPath('retryable', false);
});

// -------------------------------------------------------------------------------------------
// The enumeration-oracle split — 403 admin / 404 public. Unchanged by ADR-029, asserted here so
// the sub-case cannot be "simplified" into it.
// -------------------------------------------------------------------------------------------

it('renders authorization as 403 on the admin surface and 404 on the public ones', function (string $path, int $expected): void {
    // Same error_class, different rendered status, and NOTHING branches on the status. A 403 on a
    // foreign identifier confirms the row exists and turns the endpoint into an enumeration oracle,
    // so the public runtime and the SDK deny as 404.
    //
    // Note the handler's AuthorizationException never reaches the closure as itself: Laravel's
    // prepareException() converts it to an AccessDeniedHttpException (403) first, which is why the
    // `$httpStatus === 403` arm is the one that actually fires. Both spellings are exercised below.
    $response = throwingRoute($path, new AuthorizationException);

    $response->assertStatus($expected);
    $response->assertJsonPath('error_class', 'authorization');
    $response->assertJsonPath('retryable', false);
})->with([
    'admin -> 403' => ['api/v1/_test/forbidden', 403],
    'public runtime -> 404' => ['rt/v1/_test/forbidden', 404],
    'sdk -> 404' => ['sdk/v1/_test/forbidden', 404],
    'internal -> 403' => ['internal/v1/_test/forbidden', 403],
]);

it('applies the same split to an explicit abort(403)', function (string $path, int $expected): void {
    Route::get($path, static fn () => abort(403));

    $response = currentTest()->getJson($path);

    $response->assertStatus($expected);
    $response->assertJsonPath('error_class', 'authorization');
})->with([
    'admin -> 403' => ['api/v1/_test/abort403', 403],
    'public runtime -> 404' => ['rt/v1/_test/abort403', 404],
    'sdk -> 404' => ['sdk/v1/_test/abort403', 404],
]);

it('renders a missing route as authorization 404 on every surface', function (string $path): void {
    // A route miss and a denied row must be indistinguishable on the public surfaces, which is why
    // 404 and 405 map to `authorization` rather than to a class of their own.
    $response = currentTest()->getJson($path);

    $response->assertStatus(404);
    $response->assertJsonPath('error_class', 'authorization');
    $response->assertJsonPath('retryable', false);
})->with([
    'admin' => 'api/v1/_test/does-not-exist',
    'public runtime' => 'rt/v1/_test/does-not-exist',
    'sdk' => 'sdk/v1/_test/does-not-exist',
]);

// -------------------------------------------------------------------------------------------
// The oracle at the BODY. The status split above is necessary and was never sufficient.
// -------------------------------------------------------------------------------------------

it('denies byte-identically to a resource that never existed', function (string $prefix): void {
    // The assertion that actually closes the oracle: an attacker reads the body, not the status.
    // toDenyAsNotFound() compares against a live request to a path with no route, not against a
    // copy of the message string — see tests/Pest.php.
    expect(throwingRoute("{$prefix}/_test/denied", new AuthorizationException))
        ->toDenyAsNotFound($prefix);
})->with([
    'public runtime' => 'rt/v1',
    'sdk' => 'sdk/v1',
]);

it('does not leak the route or its verb list through a 405', function (string $prefix): void {
    // Symfony's MethodNotAllowedHttpException message names the allowed methods, and Laravel's
    // names the URI too. Rendered verbatim that was strictly MORE informative than the 403 the
    // split exists to avoid: the probe learned the URI exists AND which verbs it takes.
    $response = throwingRoute(
        "{$prefix}/_test/wrong-verb",
        new MethodNotAllowedHttpException(['POST', 'PATCH'], 'The GET method is not supported for route rt/v1/bots/01H. Supported methods: POST, PATCH.'),
    );

    expect($response->getContent())->not->toContain('POST')
        ->and($response->getContent())->not->toContain('01H')
        ->and($response->headers->get('Allow'))->toBeNull();

    expect($response)->toDenyAsNotFound($prefix);
})->with([
    'public runtime' => 'rt/v1',
    'sdk' => 'sdk/v1',
]);

it('keeps the admin 403 body constant too', function (): void {
    // Admin admits existence by design, so this is not an oracle — but a policy's free-form
    // message reaching the wire is still a leak of internal wording, and one rule is one thing to
    // get right. A denied record and a record in another organization read identically.
    $denied = throwingRoute('api/v1/_test/denied', new AuthorizationException('Bot 01HXYZ belongs to organization 01HABC.'));

    $denied->assertStatus(403);
    $denied->assertJsonPath('message', 'This action is not permitted.');
    expect($denied->getContent())->not->toContain('01HABC');
});

/*
|--------------------------------------------------------------------------
| `actionable` — is this envelope's `message` addressed to a person? (finding J2)
|--------------------------------------------------------------------------
|
| THE PROBLEM IT SOLVES. The taxonomy has no 409 row, on purpose: an unclassified 4xx our own code
| raised keeps its status and renders as `internal_dependency` with `retryable: false`, and an
| unhandled exception renders as `internal_dependency` with `retryable: false` too. So ADR-049's
| actionable "clear the designation first, then delete" and "the service could not complete this
| request" arrived at the browser as the SAME (error_class, retryable) pair. apps/web told them
| apart by comparing `message` against a client-side copy of the 5xx constant — a deny-by-exclusion
| filter whose premise was a property of the WHOLE TREE rather than of the response in hand.
|
| WHAT THE FIELD IS NOT. It is not a status, and no test below asserts one through it. It says only
| whether `message` was written for this condition or is a fixed placeholder chosen to say nothing.
|
| The value is DERIVED from the same conditions as the message `match` in bootstrap/app.php, arm for
| arm, so the two cannot drift — which is what makes the cases below a table rather than a list.
*/

it('marks a deliberate 4xx actionable, so its sentence can be rendered', function (): void {
    // The ADR-049 shape: a ConflictHttpException our own code raised, carrying the remedy.
    $response = throwingRoute(
        'api/v1/_test/actionable-conflict',
        new ConflictHttpException('Clear the embedding designation first, then delete this connection.'),
    );

    $response->assertStatus(409);
    // The pair it SHARES with a defect, asserted here so the next assertion is visibly the only
    // thing that separates them.
    $response->assertJsonPath('error_class', 'internal_dependency');
    $response->assertJsonPath('retryable', false);
    $response->assertJsonPath('actionable', true);
    $response->assertJsonPath('message', 'Clear the embedding designation first, then delete this connection.');
});

it('marks an unhandled exception NOT actionable, on the same class and retry verdict', function (): void {
    $response = throwingRoute('api/v1/_test/actionable-defect', new \RuntimeException('psql: password=hunter2'));

    $response->assertStatus(500);
    $response->assertJsonPath('error_class', 'internal_dependency');
    $response->assertJsonPath('retryable', false);
    $response->assertJsonPath('actionable', false);
    // …and the message is the placeholder, which is WHY it is not actionable. The raw text never
    // reaches the body — that rule predates this field and is unaffected by it.
    $response->assertJsonPath('message', 'The service could not complete this request.');
    expect($response->getContent())->not->toContain('hunter2');
});

it('marks a downstream 503 not actionable either, because that message is a placeholder too', function (): void {
    // The OTHER `internal_dependency` rendering. `retryable` differs, `actionable` does not — the
    // two fields answer different questions and this is the case that proves they are not aliases.
    $response = throwingRoute('api/v1/_test/actionable-downstream', new ServiceUnavailableHttpException(null, 'ai-service is draining'));

    $response->assertStatus(503);
    $response->assertJsonPath('retryable', true);
    $response->assertJsonPath('actionable', false);
});

it('never marks an authorization deny actionable, on either surface', function (string $prefix, int $status): void {
    // THIS ARM IS A SECURITY PROPERTY, NOT A COPY DECISION. Both authorization messages are the
    // enumeration-oracle constants: one 403 body for every deny, one 404 body for every 404,
    // byte-identical whether the record was denied, foreign, or never existed. Marking either
    // actionable would invite a client to render it, which is harmless in itself — but the arm
    // exists so that nobody "improves" the constant into a specific sentence later and finds the
    // envelope already inviting them to show it.
    $response = throwingRoute("{$prefix}/_test/actionable-deny", new AuthorizationException('Bot 01HXYZ belongs to organization 01HABC.'));

    $response->assertStatus($status);
    $response->assertJsonPath('actionable', false);
    expect($response->getContent())->not->toContain('01HABC');
})->with([
    'admin 403' => ['api/v1', 403],
    'public runtime 404' => ['rt/v1', 404],
]);

it('marks a FormRequest 422 not actionable, because its payload is the errors map', function (): void {
    // Laravel's own summary string is generic. The thing a client acts on is `errors`, and a form
    // that rendered the summary instead would show one opaque banner over a keyed set of messages.
    $response = throwingRoute(
        'api/v1/_test/actionable-form-422',
        ValidationException::withMessages(['name' => ['The name field is required.']]),
    );

    $response->assertStatus(422);
    $response->assertJsonPath('actionable', false);
    $response->assertJsonPath('errors.name.0', 'The name field is required.');
});

it('marks a KbException validation 422 ACTIONABLE, which is the split that matters', function (): void {
    // NOT "not validation" — `! $e instanceof ValidationException`. KbException::validation()
    // carries the ADR-031 resolver refusal: a full paragraph explaining that the (provider, model)
    // pair IS the vector space, written for the operator and rendered verbatim by
    // /settings/embedding. Keying this arm on the CLASS instead of the producer would silence it.
    $refusal = 'Two eligible connections name different (provider, model) pairs. That pair is the vector space, so this is a refusal rather than a tie-break.';

    $response = throwingRoute(
        'api/v1/_test/actionable-kb-422',
        \App\Exceptions\KbException::validation($refusal),
    );

    $response->assertStatus(422);
    $response->assertJsonPath('error_class', 'validation');
    $response->assertJsonPath('actionable', true);
    $response->assertJsonPath('message', $refusal);
    // …and STILL no `errors` map, which is the other half apps/web discriminates that refusal on.
    expect($response->json())->not->toHaveKey('errors');
});

it('relays the data plane verdict rather than recomputing it, and fails closed on absence', function (): void {
    // ADR-052's lesson applied before it can recur: a relay that reads a SUBSET of an envelope is a
    // hop, and a decision about how two planes agree on a field survives only where every hop
    // preserves it. A relayed 409 carrying `actionable: false` must NOT be promoted to true by
    // Laravel's own derivation just because the status is under 500 and the message is non-empty.
    $relayedFalse = \App\Exceptions\KbException::relayed(
        'internal_dependency',
        'a downstream placeholder',
        409,
        false,
        null,
        false,
    );

    throwingRoute('api/v1/_test/relay-false', $relayedFalse)->assertJsonPath('actionable', false);

    // The same envelope with the downstream saying it IS a sentence.
    $relayedTrue = \App\Exceptions\KbException::relayed(
        'internal_dependency',
        'the data plane refuses this pair, and here is why',
        409,
        false,
        null,
        true,
    );

    throwingRoute('api/v1/_test/relay-true', $relayedTrue)->assertJsonPath('actionable', true);

    // AND THE DEFAULT IS THE CLOSED ONE. `relayed()` defaults the argument to false, so an envelope
    // that never carried the field — an older data plane, a proxy, anything not ours — renders a
    // class-mapped sentence rather than whatever text it happened to send.
    $relayedAbsent = \App\Exceptions\KbException::relayed('internal_dependency', 'unknown provenance', 409, false);

    throwingRoute('api/v1/_test/relay-absent', $relayedAbsent)->assertJsonPath('actionable', false);
});

it('is present on every envelope, so a client never has to treat it as optional', function (): void {
    // The key is REQUIRED in the OpenAPI component. `packages/contracts/src/envelope.ts` types it
    // optional only because that interface is the union of this body and the SSE `error` frame.
    $response = throwingRoute('api/v1/_test/actionable-always-present', new \RuntimeException('boom'));

    expect($response->json())->toHaveKeys(['error_class', 'message', 'retryable', 'request_id', 'actionable']);
});
