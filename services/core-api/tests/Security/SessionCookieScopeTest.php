<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Support\SpaSession;

/*
|--------------------------------------------------------------------------
| The admin session cookie's scope — docs/13 §18.4, laravel-sanctum-auth
|--------------------------------------------------------------------------
|
| THE HAZARD IN THIS DEPLOYMENT IS NOT THE WIDGET, AND THAT IS WHY THIS FILE EXISTS.
|
| The Sanctum SPA guide suggests a leading-dot parent domain (SESSION_DOMAIN=.example.com). The usual
| argument against it names the embedded widget — and here that argument does not apply: the widget is
| served from ${WIDGET_DOMAIN}, a separate registrable domain, so a `.${DOMAIN}` cookie would never
| reach it.
|
| `chat.${DOMAIN}` WOULD REACH IT. Hosted chat is a PUBLIC surface — an unauthenticated visitor's
| browser, running our JavaScript, on a subdomain of the same registrable domain as the admin console.
| A dot-prefixed session cookie is sent there on every request, so one XSS or one subdomain takeover on
| a public page hands over an authenticated admin session. CHIPS cannot help: the cookie is not the
| third party's. So the wildcard hazard is live even with the widget correctly separated.
|
| ── THE RULING OF 2026-08-13, AND THE CLAIM IT RETIRED ──────────────────────────────────────────────
|
| This file used to end: "A HOST-ONLY COOKIE IS ALSO WHAT THE ARCHITECTURE ALREADY NEEDS. The SPA is
| cross-origin from the API and sends `credentials: 'include'`, which works with a host-only cookie; the
| parent-domain trick buys nothing here and costs the above." **The last clause was false, and it was
| measured false on the deployed stack.**
|
| `credentials: 'include'` covers SENDING `kb_session` — that half was right, and same-site Lax permits
| it. It says nothing about READING `XSRF-TOKEN`, which is a separate, deliberately JS-readable cookie
| the SPA must pull out of `document.cookie` to echo as `X-XSRF-TOKEN` on every mutation. A host-only
| cookie set by `api.${DOMAIN}` is invisible to script on `app.${DOMAIN}`. Measured: after
| `GET /sanctum/csrf-cookie` returned 204 and set both cookies, `document.cookie` on the SPA's origin
| was EMPTY, `readCookie('XSRF-TOKEN')` returned null, and `apps/web/src/lib/api/browser.ts` threw its
| own diagnostic — "XSRF-TOKEN cookie absent after GET /sanctum/csrf-cookie — check SESSION_DOMAIN".
| So the POST never left the browser and every auth form rendered the generic "Something went wrong."
| The parent-domain scope does not buy tidiness; it buys the only CSRF token the SPA can obtain.
|
| Ankur ruled on 2026-08-13, with the cost above stated: take `SESSION_DOMAIN=.${DOMAIN}`. THE HAZARD
| IN THE HEADING ABOVE IS ACCEPTED, NOT REFUTED — an XSS or subdomain takeover on `chat.${DOMAIN}` can
| now read `XSRF-TOKEN` and forge admin mutations, which it could not before. Two upgrades were
| designed and not taken, and either would let this be reverted to host-only: serve the API under the
| SPA's own origin at a path prefix (nothing is cross-origin, so no cookie needs widening), or return
| the CSRF token in a response BODY from an allow-listed origin (strictly stronger than this, because
| CORS then stops a sibling subdomain reading it at all). Both are recorded as open.
|
| WHAT THESE ASSERTIONS THEREFORE PROTECT NOW. Not "no Domain attribute" — that would fail the shipped
| deployment. They pin the SHAPE of a legal scope and the AGREEMENT between config and wire: a scope
| must be a bare dot-prefixed hostname of at least two labels (so a typo cannot scope the cookie to a
| public suffix and hand it to every site under `.com`), it must actually cover the SPA host (or the fix
| silently does nothing and the symptom is the measurement above), and no cookie may go out broader
| than the configured scope. The rule is dataset-driven BECAUSE `phpunit.xml` sets no `SESSION_DOMAIN`:
| the suite runs host-only, so a merely conditional test would leave the branch that guards the shipped
| deployment unexecuted in CI, which is the "green because it never ran" failure this repo keeps finding.
*/

/**
 * Is `$domain` a legal value for `session.domain`?
 *
 * Empty means host-only, which is the correct value for a single-host deployment and is what the test
 * suite itself runs with. A non-empty value must be a parent-domain scope somebody chose deliberately.
 *
 * @return string|null the reason it is illegal, or null when it is legal
 */
function sessionDomainRejection(?string $domain): ?string
{
    if ($domain === null || $domain === '') {
        return null; // Host-only.
    }

    if (! str_starts_with($domain, '.')) {
        return 'a scope that is not host-only must be written with a leading dot, so a reader can tell '
            .'a parent-domain scope from a single host at a glance';
    }

    if (str_contains($domain, '*')) {
        return 'browsers do not honour a wildcard in a Domain attribute, so the author intended '
            .'parent-domain scoping and wrote something that either does nothing or is silently '
            .'normalised — neither is a scope anyone can reason about';
    }

    foreach ([':', '/', ' '] as $illegal) {
        if (str_contains($domain, $illegal)) {
            return "a Domain attribute is a bare hostname; `{$illegal}` means a scheme, port or path "
                .'was pasted in, and the browser will reject the whole cookie';
        }
    }

    if ($domain !== strtolower($domain)) {
        return 'host comparison is case-insensitive but string comparison here is not, so an '
            .'upper-case label makes the config and the wire disagree for no reason';
    }

    // '.example.com' -> ['example', 'com']. Two labels is the minimum that is not a public suffix.
    $labels = array_filter(explode('.', $domain), static fn (string $l): bool => $l !== '');

    if (count($labels) < 2) {
        return 'a single-label scope like `.com` or `.example` is a PUBLIC SUFFIX. Browsers reject it, '
            .'and the intent behind writing it — one cookie for everything under there — is the '
            .'catastrophic version of the trade this file documents';
    }

    return null;
}

beforeEach(function (): void {
    // A CLIENT ADDRESS OF THIS TEST'S OWN. RefreshDatabase rolls back the database and nothing else,
    // and phpunit.xml points the cache at a real Valkey — so every rate-limiter bucket survives the
    // test that filled it and the next run of the suite. `login` is 20/minute per IP and every request
    // here arrives from one address, so without this a suite that logs in for real is a 429 storm that
    // only appears in CI. See Tests\Support\SpaSession::isolateRateLimits().
    SpaSession::isolateRateLimits(currentTest());
});

it('classifies session-cookie scopes', function (?string $domain, bool $legal): void {
    $rejection = sessionDomainRejection($domain);

    expect($rejection === null)->toBe(
        $legal,
        $legal
            ? sprintf('`%s` is a legal scope but was rejected: %s', (string) $domain, (string) $rejection)
            : sprintf('`%s` is not a legal scope but was accepted', (string) $domain),
    );
})->with([
    // Legal.
    'host-only (null)' => [null, true],
    'host-only (empty)' => ['', true],
    'parent domain' => ['.knowledgebot.example', true],
    'multi-label TLD' => ['.kb.co.uk', true],
    // Illegal, one reason each.
    'no leading dot' => ['knowledgebot.example', false],
    'wildcard' => ['.*.knowledgebot.example', false],
    'public suffix' => ['.com', false],
    'single label' => ['.example', false],
    'upper case' => ['.KnowledgeBot.example', false],
    'scheme pasted in' => ['https://.knowledgebot.example', false],
    'port pasted in' => ['.knowledgebot.example:443', false],
    'trailing path' => ['.knowledgebot.example/', false],
]);

it('uses a session-cookie scope that is legal and actually covers the SPA host', function (): void {
    $domain = config('session.domain');
    $domain = is_string($domain) ? $domain : null;

    $rejection = sessionDomainRejection($domain);

    expect($rejection)->toBeNull(
        "session.domain is `{$domain}`, which is not a scope this deployment can use: {$rejection}",
    );

    if ($domain === null || $domain === '') {
        // HOST-ONLY. Legal, and what the suite itself runs with. Nothing further to check: the browser
        // adds no Domain attribute, so there is no scope to be too broad. Recorded rather than skipped
        // so a reader can tell this branch ran.
        expect(true)->toBeTrue();

        return;
    }

    // NON-EMPTY, so this deployment has taken the trade in the header. The scope must cover the host
    // the SPA is actually served from — otherwise `document.cookie` cannot see XSRF-TOKEN there and
    // every mutation fails with the client-side diagnostic quoted above, which no server log records.
    $frontendHost = parse_url((string) config('kb.frontend_url'), PHP_URL_HOST);

    expect($frontendHost)->toBeString(
        'kb.frontend_url has no host, so FRONTEND_URL is unset or malformed — the same misconfiguration '
        .'that emails reset links pointing at the recipient\'s own machine',
    );
    assert(is_string($frontendHost));

    // `.knowledgebot.example` covers `app.knowledgebot.example` and the apex itself, and nothing else.
    $covers = $frontendHost === ltrim($domain, '.') || str_ends_with($frontendHost, $domain);

    expect($covers)->toBeTrue(
        "session.domain is `{$domain}` but the SPA is served from `{$frontendHost}`, which that scope "
        .'does not cover. The session cookie and XSRF-TOKEN are then invisible to the admin console, and '
        .'the symptom is every auth form showing a generic error with nothing in any server log.',
    );
});

it('scopes the session cookie a real login issues to exactly the configured scope', function (): void {
    // ASSERTED ON THE WIRE, NOT ON THE CONFIG. config/session.php is one input to the Set-Cookie
    // header; a middleware, a `Cookie::queue()` or a future `SESSION_DOMAIN` interpolation is another,
    // and only the response says what the browser will actually do. The expectation is now the
    // CONFIGURED value rather than the literal `''`, because a deployment whose SPA and API are
    // different hosts must widen this (see the header) — but nothing may widen it FURTHER than the
    // ruling, and a cookie going out with a scope the config never asked for is the drift to catch.
    $user = User::factory()->create();

    $response = currentTest()->withCredentials()->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => \Database\Factories\UserFactory::PASSWORD,
    ], spaHeaders());

    $response->assertOk();

    $cookies = $response->headers->getCookies();

    expect($cookies)->not->toBeEmpty('the login response set no cookies at all, so there is nothing '
        .'to scope and this assertion would be vacuous');

    $sessionCookie = null;

    foreach ($cookies as $cookie) {
        if ($cookie->getName() === SpaSession::cookieName()) {
            $sessionCookie = $cookie;
        }
    }

    expect($sessionCookie)->toBeInstanceOf(Cookie::class);
    assert($sessionCookie instanceof Cookie);

    expect($sessionCookie->getDomain())->toBe(
        (string) config('session.domain'),
        'the session cookie went out with a Domain attribute that config/session.php did not ask for. '
        .'Every host under it — chat.<domain> included, which is public — now receives the admin session '
        .'cookie on every request, and the config a reviewer reads no longer describes the wire.',
    );

    // The rest of the envelope, asserted here because all four are one decision and a change to any
    // of them is the same class of mistake.
    expect($sessionCookie->isHttpOnly())->toBeTrue(
        'the session cookie is readable by JavaScript, which removes the entire reason the admin uses '
        .'a cookie rather than a bearer token: one XSS becomes a stolen replayable credential',
    );
    expect($sessionCookie->getSameSite())->toBe(
        Cookie::SAMESITE_LAX,
        'SameSite is not Lax. `None` lets any site send the admin session cookie, which is CSRF with '
        .'extra steps; `Strict` breaks the SPA\'s cross-origin fetches.',
    );
    expect($sessionCookie->isPartitioned())->toBeFalse(
        'the session cookie is Partitioned (CHIPS), which is for third-party contexts — this cookie '
        .'must never appear in one',
    );
});

it('scopes every cookie the admin surface issues no wider than the configured scope', function (): void {
    // THE SESSION COOKIE IS NOT THE ONLY ONE. XSRF-TOKEN is deliberately JS-readable, and under the
    // ruling in the header it now SHARES the session cookie's scope — that is the accepted cost, and
    // the reason it is accepted is that the SPA cannot read it otherwise. What is still worth pinning
    // is that no cookie goes out BROADER than the one configured scope: a third value appearing here
    // means someone scoped a cookie by hand, and this is the file that says which scope is the ruling.
    currentTest()->getJson('/sanctum/csrf-cookie', spaHeaders())->assertNoContent();

    $user = User::factory()->create();

    $response = currentTest()->withCredentials()->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => \Database\Factories\UserFactory::PASSWORD,
    ], spaHeaders());

    $names = [];

    foreach ($response->headers->getCookies() as $cookie) {
        $names[] = $cookie->getName();

        expect($cookie->getDomain())->toBe(
            (string) config('session.domain'),
            "the `{$cookie->getName()}` cookie is scoped differently from every other cookie this "
            .'surface issues, so it is sent to a set of hosts nobody ruled on — including, if it is '
            .'broader, the public ones',
        );
    }

    // POSITIVE CONTROL: the loop above ran over something. An empty cookie jar satisfies "no cookie
    // has a Domain" perfectly.
    expect($names)->toContain(SpaSession::cookieName());
});

it('scopes the session cookie to a path that cannot be widened by accident', function (): void {
    // A cookie on `/` is correct here — the SPA and the API are different hosts, so there is no
    // sibling application on this origin to shield from it. Asserted so that a future change to
    // `session.path` is a decision somebody made rather than a default they inherited.
    expect(config('session.path'))->toBe('/');

    // And the cookie is not JS-readable, which is the property `session.http_only` carries into every
    // cookie the session driver writes.
    expect(config('session.http_only'))->toBeTrue();
    expect(config('session.same_site'))->toBe('lax');
});

it('never puts the session id in a URL, a body or a log-visible header', function (): void {
    // The session id is a bearer capability. `session.driver` is a cookie-carried id in every
    // environment here, and the one way it becomes a URL is a framework fallback nobody notices.
    $user = User::factory()->create();

    $sessionId = SpaSession::establish(currentTest(), $user);

    Route::middleware(['api', 'auth:sanctum', 'surface:admin'])
        ->get('api/v1/_probe/session-echo', static fn (Request $request): array => [
            'url' => $request->fullUrl(),
        ]);

    $response = currentTest()->getJson('api/v1/_probe/session-echo', spaHeaders());

    $response->assertOk();

    expect(str_contains((string) $response->json('url'), $sessionId))->toBeFalse(
        'the session id reached the request URL, which puts a live credential in Traefik access logs, '
        .'in Referer on the next navigation, and in browser history',
    );

    expect(str_contains((string) $response->getContent(), $sessionId))->toBeFalse(
        'the session id is echoed in a response body',
    );
});
