import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

import { NextRequest, type NextResponse } from 'next/server';
import { describe, expect, it } from 'vitest';

import { PUBLIC_PATHS } from '@/lib/auth/safe-next';
import proxy, { SESSION_COOKIE } from '@/proxy';

/**
 * `src/proxy.ts` is a pure function of a `NextRequest`, so it is testable without a server — the same
 * property tests/unit/theme-route.test.ts exploits for the theme route handler.
 *
 * NOTHING HERE IS AN AUTHORIZATION ASSERTION, and no test added later may make it one. CVE-2025-29927
 * skipped every Next.js middleware with a single request header, so every behaviour below is UX
 * routing that fails safe in both directions. What the suite protects is the two ways this file goes
 * silently wrong: a constant that drifts out of sync with Laravel (the whole console became
 * unreachable that way, and the redirect looked like it was working), and a public route added
 * without a matching entry in PUBLIC_PATHS.
 */

const HOST = 'app.knowledgebot.test';

interface Outcome {
  readonly status: number;
  readonly location: string | null;
  readonly rewrite: string | null;
}

function inspect(response: NextResponse): Outcome {
  return {
    status: response.status,
    location: response.headers.get('location'),
    rewrite: response.headers.get('x-middleware-rewrite'),
  };
}

function call(
  path: string,
  init: {
    readonly cookie?: string;
    readonly method?: string;
    readonly host?: string;
    readonly prefetch?: boolean;
  } = {},
): Outcome {
  const headers = new Headers();
  headers.set('host', init.host ?? HOST);
  if (init.cookie !== undefined) headers.set('cookie', init.cookie);
  // Next sends this when the router speculatively fetches a route the user only hovered.
  if (init.prefetch === true) headers.set('next-router-prefetch', '1');

  const request = new NextRequest(new URL(path, `https://${init.host ?? HOST}`), {
    headers,
    method: init.method ?? 'GET',
  });
  return inspect(proxy(request));
}

/** A cookie header that makes the visitor LOOK signed in. Presence is not a session. */
const signedIn = `${SESSION_COOKIE}=opaque-encrypted-value`;

const repoFile = (relative: string): string =>
  fileURLToPath(new URL(`../../../../${relative}`, import.meta.url));

// ── (a) the cookie name is in sync with running Laravel, through a committed file ─────────────────

describe('the session cookie name cannot drift from Laravel', () => {
  it('equals components.securitySchemes.sanctumSession.name in the generated OpenAPI document', () => {
    // That document is generated from LIVE `config('session.cookie')` and is `--check`-gated in CI
    // by `php artisan kb:dump-openapi --check`, so this assertion makes the web-side literal provably
    // in sync with running Laravel — through a file both sides commit, with no shared runtime.
    //
    // THE DEFECT THIS PINS. The constant was `laravel_session`, Laravel's factory default, while
    // Laravel sets `kb_session`. `request.cookies.has(SESSION_COOKIE)` was therefore ALWAYS false and
    // every authenticated admin path 307'd to /login forever, including straight after a successful
    // sign-in. Nothing failed: the redirect is by design, so a broken console looked like a working
    // UX rule.
    const document = JSON.parse(
      readFileSync(repoFile('packages/contracts/openapi/core-api.openapi.json'), 'utf8'),
    ) as {
      components: { securitySchemes: Record<string, { in: string; name: string }> };
    };

    const scheme = document.components.securitySchemes['sanctumSession'];
    expect(scheme).toBeDefined();
    expect(scheme?.in).toBe('cookie');
    expect(SESSION_COOKIE).toBe(scheme?.name);
    // Stated separately so the failure message names the mistake rather than just a mismatch.
    expect(SESSION_COOKIE).not.toBe('laravel_session');
  });

  it('treats the real cookie as signed-in and the factory default as absent', () => {
    // The behavioural half. The assertion above compares two strings; this one proves the string is
    // the one the code actually branches on, which a rename of the constant would not break.
    expect(call('/sources', { cookie: signedIn }).location).toBeNull();
    expect(call('/sources', { cookie: 'laravel_session=opaque' }).location).toBe(
      `https://${HOST}/login?next=%2Fsources`,
    );
  });
});

// ── (b) PUBLIC_PATHS is in sync with the route tree ──────────────────────────────────────────────

describe('PUBLIC_PATHS is in sync with the (auth) route tree', () => {
  /** Every `page.tsx` under `src/app/(auth)/`, as the URL Next will route to it. The group segment
   *  itself contributes nothing to the path, which is the whole point of a route group. */
  function authRouteUrls(): ReadonlySet<string> {
    const root = fileURLToPath(new URL('../../src/app/(auth)/', import.meta.url));
    const found = new Set<string>();

    const walk = (segments: readonly string[]): void => {
      const dir = [root, ...segments].join('/');
      for (const entry of readdirSync(dir, { withFileTypes: true })) {
        if (entry.isDirectory()) {
          walk([...segments, entry.name]);
        } else if (entry.name === 'page.tsx') {
          found.add(`/${segments.join('/')}`);
        }
      }
    };

    walk([]);
    return found;
  }

  it('derives exactly the six paths PUBLIC_PATHS declares', () => {
    // THIS IS WHAT MAKES "added a route, forgot the proxy" A NAMED RED TEST rather than a comment
    // nobody reads. Both directions fail: a new (auth) page without an entry here is a live route
    // that 307s to /login forever, and an entry with no page is a hole in the redirect for a URL
    // that renders a 404 — reachable, and no longer covered by the signed-in bounce's reasoning.
    expect([...authRouteUrls()].sort()).toEqual([...PUBLIC_PATHS].sort());
  });

  it('contains no prefix-shaped entry, because there are no prefixes (decision D2)', () => {
    // All three email landing routes take their token in a query string, so nothing here needs to
    // match a variable segment. An entry ending in `/` would mean somebody reintroduced prefix
    // matching, and with it the `/invitations-evil` hazard.
    for (const path of PUBLIC_PATHS) {
      expect(path.endsWith('/')).toBe(false);
      expect(path).not.toContain('[');
    }
  });
});

// ── (c) the telemetry carve-out comes before every branch ────────────────────────────────────────

describe('the browser OTLP ingest path is carved out of every branch', () => {
  it('is neither redirected nor rewritten with no cookie', () => {
    // A 307 preserves the method, so redirecting this would re-POST a span batch at a page route —
    // and a signed-out visitor is exactly the state the login and error surfaces need traces from.
    const outcome = call('/telemetry/v1/traces', { method: 'POST' });
    expect(outcome.location).toBeNull();
    expect(outcome.rewrite).toBeNull();
  });

  it('is not rewritten under /c/ on the chat host', () => {
    // Uncarved, an exporter POST there lands on /c/telemetry/v1/traces — a 404 the exporter reports
    // nowhere, so hosted chat simply has no browser traces and nothing says so.
    const outcome = call('/telemetry/v1/traces', {
      method: 'POST',
      host: 'chat.knowledgebot.test',
    });
    expect(outcome.rewrite).toBeNull();
    expect(outcome.location).toBeNull();
  });
});

// ── (d)(e) the anonymous redirect and its ?next= ─────────────────────────────────────────────────

describe('an anonymous visitor is redirected to /login', () => {
  it('(d) carries the path AND query as ?next=', () => {
    const outcome = call('/sources?x=1');
    expect(outcome.status).toBe(307);
    expect(outcome.location).toBe(`https://${HOST}/login?next=%2Fsources%3Fx%3D1`);
  });

  it('(e) omits ?next= for the root, which is already the default destination', () => {
    expect(call('/').location).toBe(`https://${HOST}/login`);
  });

  it('omits ?next= for a PREFETCH, because the user never opened that page', () => {
    // Without this, hovering the nav on a signed-out tab rewrites the post-login destination to
    // whichever link the pointer passed over last.
    expect(call('/bots', { prefetch: true }).location).toBe(`https://${HOST}/login`);
  });

  it('omits ?next= when the target exceeds the cap', () => {
    const long = `/sources?q=${'a'.repeat(600)}`;
    expect(call(long).location).toBe(`https://${HOST}/login`);
  });
});

// ── (h) method preservation ──────────────────────────────────────────────────────────────────────

describe('a non-GET request is never redirected', () => {
  it.each(['POST', 'PUT', 'PATCH', 'DELETE'])(
    '(h) passes %s through for Laravel to 401',
    (method) => {
      // A 307 PRESERVES THE METHOD, so redirecting would re-POST a stale tab's form body at /login —
      // a route that never expected it. Laravel is the gate; let it answer 401.
      const outcome = call('/sources', { method });
      expect(outcome.location).toBeNull();
      expect(outcome.rewrite).toBeNull();
    },
  );
});

// ── THE `/login` BOUNCE IS GONE, AND THIS IS THE LOOP REGRESSION TEST ───────────────────────────
//
// This block previously asserted the opposite: that a request to `/login` carrying `kb_session` is
// redirected to `safeNext(?next)`. It passed, and it was encoding a live infinite refresh of
// `app.<domain>` reported from a real browser:
//
//     /login -> bounce to '/' -> admin layout mounts SessionProvider -> GET /me -> 401
//            -> session-provider.tsx:77 assigns '/login?next=/' -> bounce to '/' -> ...
//
// `kb_session` is present for a GUEST: every auth form calls `refreshCsrfToken()` before its POST,
// `StartSession` answers that with a session cookie, and `SESSION_DOMAIN` is the parent domain so it is
// visible on `app.<domain>`. The cookie means "a session exists", never "signed in".
//
// So the assertions below are inverted ON PURPOSE, and they are the guard: reinstating any redirect away
// from `/login` fails here by name. The `?next=` cases are kept because they are the ones a reader is
// most likely to think are missing — the open-redirector protection did not move, it simply has one
// caller now (`(auth)/login/page.tsx`, covered by `safe-next.test.ts`) instead of two.
describe('a visitor holding a session cookie is NOT redirected away from /login', () => {
  it('does not bounce a bare /login — this is the loop', () => {
    expect(call('/login', { cookie: signedIn }).location).toBeNull();
  });

  it('does not bounce /login even with a safe ?next=', () => {
    expect(call('/login?next=/bots', { cookie: signedIn }).location).toBeNull();
  });

  it('cannot be an open redirector, because it no longer redirects at all', () => {
    // The former hazard: `new URL('//evil.com', request.url)` is `https://evil.com`, so the removed
    // branch was one missing `safeNext` call away from an open redirector on an authenticated origin.
    // Asserted as `null` rather than as "goes to /" so that restoring the branch fails even if whoever
    // restores it remembers the sanitizer.
    const outcome = call('/login?next=//evil.com', { cookie: signedIn });
    expect(outcome.location).toBeNull();
    expect(outcome.location ?? '').not.toContain('evil.com');
  });

  it('leaves every other public path alone with the cookie present', () => {
    for (const path of ['/register', '/verify-email', '/invitations/accept', '/forgot-password']) {
      expect(call(path, { cookie: signedIn }).location, path).toBeNull();
    }
  });

  it('still redirects a cookieless visitor INTO /login — absence is the signal that works', () => {
    // The surviving direction, asserted here so the removal above cannot be read as "proxy no longer
    // cares about sessions". Absence of the cookie does mean no session of any kind.
    expect(call('/sources').location).toBe(`https://${HOST}/login?next=%2Fsources`);
  });
});

// ── (i)(j) exact matching, in both directions ───────────────────────────────────────────────────

describe('public paths are matched EXACTLY', () => {
  it.each([...PUBLIC_PATHS])('(i) %s is reachable with no cookie', (path) => {
    const outcome = call(path);
    expect(outcome.location).toBeNull();
    expect(outcome.rewrite).toBeNull();
  });

  it('(i) a public path keeps its query string untouched', () => {
    // The token-bearing shape of every email landing route (decision D2).
    expect(call('/reset-password?token=abc&email=a%40b.test').location).toBeNull();
  });

  it.each([
    '/logins',
    '/login/extra',
    '/registers',
    '/invitations',
    '/invitations/accepted',
    '/invitations-evil',
    '/verify-emails',
  ])('(j) %s merely RESEMBLES a public path and is redirected', (path) => {
    // With decision D2 there are no prefixes left to get wrong, and this is the assertion that keeps
    // it that way: reintroduce `PUBLIC_PREFIXES = ['/invitations/']` and `/invitations/accepted`
    // silently becomes public; drop its trailing slash and `/invitations-evil` does too.
    expect(call(path).status, path).toBe(307);
    expect(call(path).location, path).toContain('/login');
  });
});

// ── the hosted-chat branches still hold ─────────────────────────────────────────────────────────

describe('the namespace branches are unchanged by the auth routing', () => {
  it('rewrites a chat-host path under /c/ and sets a CSP', () => {
    const request = new NextRequest(new URL('https://chat.knowledgebot.test/01JBOT'), {
      headers: new Headers({ host: 'chat.knowledgebot.test' }),
    });
    const response = proxy(request);
    expect(response.headers.get('x-middleware-rewrite')).toContain('/c/01JBOT');
    expect(response.headers.get('content-security-policy')).toContain("frame-ancestors 'none'");
  });

  it('redirects /c/* away from the admin host even for a signed-in visitor', () => {
    // Hosted chat must never render on the origin the admin session cookie is scoped to: one XSS in
    // model output there would execute with a real admin credential attached. Asserted WITH the
    // cookie present, because that is the case the new bounce could have reordered.
    expect(call('/c/01JBOT', { cookie: signedIn }).location).toBe(`https://${HOST}/`);
  });
});
