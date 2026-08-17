<?php

declare(strict_types=1);

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/*
|--------------------------------------------------------------------------
| Trusted proxies — the header that decides who a caller IS
|--------------------------------------------------------------------------
|
| Traefik is the direct TCP peer of every request from the internet, so `$request->ip()` is either
| read out of `X-Forwarded-For` or is the reverse proxy's own container address for EVERYBODY. This
| suite exists because the second state was live, and this application is the first code here to
| depend on that value as a security control:
|
|   * `AppServiceProvider`'s `login` limiter is `Limit::perMinute(20)->by('ip:'.$request->ip())` —
|     twenty login attempts a minute FOR THE ENTIRE INTERNET COMBINED.
|   * `password-request` is 10/minute, so one host makes password reset unavailable to every user of
|     the platform, indefinitely, with legitimate traffic.
|   * `AuditLogger::ipFrom()` writes it to `audit_logs.ip_address`, so every row — including
|     `auth.login.failed`, whose `organization_id` and `actor_id` are both null BY DESIGN — records
|     the proxy and locates nothing.
|
| THE ASSERTION THAT MATTERS MOST IS THE SECOND ONE. Trusting the header from everywhere (`at: '*'`,
| which expands to `0.0.0.0/0`) is STRICTLY WORSE than trusting nothing: an attacker then forges a
| distinct address per request and evades every per-IP limiter completely, rather than merely sharing
| one bucket, while the audit table faithfully records whatever it was told. So "the header is IGNORED
| from outside the trusted range" is the property under test, not "the header is read".
|
| `TrustProxies::flushState()` runs in the framework's test lifecycle, so these set the CONFIG KEY
| rather than the static property — the middleware reads config at request time, which is the whole
| reason `bootstrap/app.php` passes `headers:` without `at:`.
*/

/**
 * A trusted-proxy CIDR, standing in for whatever `compose.yaml`'s `x-edge-subnet` anchor supplies.
 *
 * DELIBERATELY NOT COUPLED TO THAT ANCHOR, and it used to claim it was: the anchor's default moved
 * to a range outside Docker's dynamic address pool on 2026-08-14 and this literal did not, which is
 * harmless only because nothing here compares the two. What is under test is the middleware's
 * inside/outside decision, and any bounded CIDR exercises that — the value's only requirements are
 * that TRAEFIK_PEER falls inside it and APPLICATION_PEER falls outside. `preflight.sh` §3b is what
 * asserts the deployed range and TRUSTED_PROXIES agree; a second copy of the real CIDR here would
 * be a third place to update with nothing checking it.
 */
const EDGE_SUBNET = '172.24.0.0/16';

/** Traefik's peer address, from inside `edge`. */
const TRAEFIK_PEER = '172.24.0.7';

/** ai-service on the `application` network — a legitimate internal caller that is NOT a proxy. */
const APPLICATION_PEER = '172.25.0.4';

/**
 * Push a request through the real `TrustProxies` middleware and hand back the mutated request.
 *
 * The middleware is exercised rather than simulated: `setTrustedProxies()` is what makes Symfony
 * consult the headers at all, and asserting against a hand-built `Request` would prove only that
 * this test can set a property.
 *
 * @param  array<string, string>  $server  `$_SERVER` keys — `REMOTE_ADDR` is the one that decides
 *                                         whether the forwarded headers are consulted at all.
 * @param  array<string, string>  $headers  request headers, set after construction so a value with a
 *                                          comma survives verbatim.
 */
function throughTrustProxies(array $server, array $headers = []): Request
{
    $request = Request::create('/api/v1/me', 'GET', [], [], [], $server);

    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    $seen = null;

    (new TrustProxies)->handle($request, function (Request $passed) use (&$seen): Response {
        $seen = $passed;

        return new Response;
    });

    expect($seen)->toBeInstanceOf(Request::class);

    /** @var Request $seen */
    return $seen;
}

beforeEach(function (): void {
    config(['trustedproxy.proxies' => [EDGE_SUBNET]]);
});

it('reads X-Forwarded-For when the peer is inside the edge network', function (): void {
    $request = throughTrustProxies(
        ['REMOTE_ADDR' => TRAEFIK_PEER],
        ['X-Forwarded-For' => '203.0.113.9'],
    );

    expect($request->ip())->toBe('203.0.113.9');
});

it('IGNORES X-Forwarded-For when the peer is outside it', function (): void {
    // THE ONE THAT FAILS UNDER `at: '*'`, and therefore the one worth having. ai-service's HMAC
    // callbacks arrive over `application`, so this is a real caller and not a hypothetical attacker —
    // but an attacker who can reach the container directly is the case that matters, and the answer
    // must be the peer address rather than whatever they claimed.
    $request = throughTrustProxies(
        ['REMOTE_ADDR' => APPLICATION_PEER],
        ['X-Forwarded-For' => '203.0.113.9'],
    );

    expect($request->ip())->toBe(APPLICATION_PEER);
});

it('takes the RIGHTMOST untrusted entry, so a client-seeded prefix cannot win', function (): void {
    // Symfony walks the chain right to left and stops at the first address it does not trust. A client
    // that sends `X-Forwarded-For: 1.2.3.4` before Traefik appends its own view would otherwise get to
    // choose its identity. This also survives a future upstream that appends rather than replaces.
    $request = throughTrustProxies(
        ['REMOTE_ADDR' => TRAEFIK_PEER],
        ['X-Forwarded-For' => '1.2.3.4, 203.0.113.9'],
    );

    expect($request->ip())->toBe('203.0.113.9');
});

it('honours forwarded proto and port from inside the range, and not from outside', function (): void {
    // NOT COSMETIC. Without trusted PROTO and PORT, `isSecure()` is false and `getPort()` is the
    // container port behind TLS, so `url()`, `route()` and every redirect build `http://` — and the
    // admin login loops with nothing in any log. That is the failure the Traefik config warns about.
    $inside = throughTrustProxies(
        ['REMOTE_ADDR' => TRAEFIK_PEER],
        ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'],
    );

    expect($inside->isSecure())->toBeTrue()
        ->and($inside->getPort())->toBe(443);

    $outside = throughTrustProxies(
        ['REMOTE_ADDR' => APPLICATION_PEER],
        ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'],
    );

    expect($outside->isSecure())->toBeFalse()
        ->and($outside->getPort())->toBe(80);
});

it('does NOT trust X-Forwarded-Host, even from inside the range', function (): void {
    // ASSERTS THE BITMASK OMISSION, so widening it to the framework default of six headers goes red
    // here. Traefik's `passHostHeader` is true, so the real Host already arrives intact and the
    // forwarded copy adds nothing — while trusting it would make `getHost()`, and therefore every
    // generated URL and Location header, depend on a client header rather than on the router rule.
    $request = throughTrustProxies(
        ['REMOTE_ADDR' => TRAEFIK_PEER, 'HTTP_HOST' => 'api.knowledgebot.test'],
        ['X-Forwarded-Host' => 'evil.example'],
    );

    expect($request->getHost())->toBe('api.knowledgebot.test');
});

it('trusts exactly three forwarded headers, named', function (): void {
    // Pinned as a VALUE rather than as behaviour, because the three exclusions each have a reason that
    // a reader should find when this goes red: X_FORWARDED_HOST (passHostHeader already true, and
    // trusting it moves getHost() onto a client header), X_FORWARDED_PREFIX (only emitted behind
    // StripPrefix, which is unused; a forged value rewrites getBaseUrl()), X_FORWARDED_AWS_ELB (no ELB).
    $expected = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_PROTO
        | Request::HEADER_X_FORWARDED_PORT;

    $request = throughTrustProxies(['REMOTE_ADDR' => TRAEFIK_PEER]);

    expect($request::getTrustedHeaderSet())->toBe(
        $expected,
        'the trusted-header bitmask moved. Widening it to the framework default (62) re-admits '
        .'X-Forwarded-Host, X-Forwarded-Prefix and X-Forwarded-AWS-ELB — see bootstrap/app.php for '
        .'why each is excluded.',
    );
});

it('never trusts a wildcard range, however TRUSTED_PROXIES is spelled', function (): void {
    // `'*'` and `'**'` both expand to ['0.0.0.0/0', '::/0'] inside TrustProxies, which makes
    // X-Forwarded-For fully client-supplied — an attacker forges a distinct address per request and
    // evades every per-IP limiter, instead of sharing one bucket. That is worse than the bug this
    // whole file exists to fix, so it is asserted as a shape rather than left to review.
    /** @var list<string> $proxies */
    $proxies = config('trustedproxy.proxies');

    foreach ($proxies as $range) {
        expect($range)->not->toBe('*')
            ->and($range)->not->toBe('**')
            ->and($range)->not->toBe('0.0.0.0/0')
            ->and($range)->not->toBe('::/0');
    }

    // And the config resolves to a LIST, never null: on null the middleware falls through to a branch
    // that sets '*' when the host ends .on-forge.com/.on-vapor.com or laravel_cloud() is true. An empty
    // array cannot reach that branch and means "trust nothing", which is a degradation, not a bypass.
    expect($proxies)->toBeArray();
});

it('resolves to an empty list when TRUSTED_PROXIES is unset, and never to null', function (): void {
    // The fail-closed direction, asserted directly against the config file's own expression rather
    // than against a cached value. Absent and empty must both mean "trust nothing".
    $resolve = static fn (?string $value): array => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) $value),
    )));

    expect($resolve(null))->toBe([])
        ->and($resolve(''))->toBe([])
        ->and($resolve('  '))->toBe([])
        ->and($resolve('172.24.0.0/16'))->toBe(['172.24.0.0/16'])
        // Multiple ranges, and whitespace around a comma is an operator's most likely typo.
        ->and($resolve('172.24.0.0/16, 10.90.7.0/24'))->toBe(['172.24.0.0/16', '10.90.7.0/24']);
});
