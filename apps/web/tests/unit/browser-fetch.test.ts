import { KbError } from '@kb/contracts';
import { HttpResponse, http } from 'msw';
import { setupServer } from 'msw/node';
import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

import { browserFetch, refreshCsrfToken } from '@/lib/api/browser';

/**
 * `lib/api/browser.ts` — the ONE browser-side Laravel caller.
 *
 * MSW IS LEGITIMATE HERE AND NOWHERE NEAR THE CHAT PATH. Every response below is one buffered JSON
 * document, so there is no chunk boundary to get wrong and nothing a mock can flatten; the streaming
 * suite runs against a real socket for exactly the reason this one does not have to
 * (tests/README.md, tests/msw/handlers.ts). It also buys the one thing a fixture server cannot:
 * `browserFetch` has NO `apiOrigin` seam — it is built from `NEXT_PUBLIC_API_ORIGIN` — so
 * intercepting that origin is the only way to test it without adding a seam to production code that
 * would then need its own rule about never being set.
 *
 * The origin is `http://api.invalid` (vitest.config.ts). RFC 2606 reserves `.invalid` and it never
 * resolves, so a request MSW fails to intercept fails loudly on DNS instead of reaching a host.
 */

const ORIGIN = 'http://api.invalid';

interface Recorded {
  readonly method: string;
  readonly url: string;
  readonly headers: Headers;
  readonly body: string;
}

let recorded: Recorded[] = [];

/** Capture whatever MSW is about to answer, so header assertions have something to read. */
async function record(request: Request): Promise<void> {
  recorded.push({
    method: request.method,
    url: request.url,
    headers: request.headers,
    body: await request.clone().text(),
  });
}

const server = setupServer();

beforeAll(() => {
  // `error`, not `warn`: an unhandled request here is a spec that thinks it is asserting something
  // and is actually watching a DNS failure.
  server.listen({ onUnhandledRequest: 'error' });
});
afterEach(() => {
  server.resetHandlers();
  vi.unstubAllGlobals();
  recorded = [];
});
afterAll(() => {
  server.close();
});

/**
 * Observe the arguments handed to `fetch` WITHOUT replacing it — the wrapper delegates to whatever
 * fetch is already installed, MSW's included.
 *
 * `credentials` is the reason this exists. It is unobservable from a handler in Node: there is no
 * cookie jar, so 'omit' and 'include' produce byte-identical requests, and MSW's reconstructed
 * `Request` cannot be trusted to carry an init field the transport never sent. It is nonetheless a
 * SECURITY property — see the chat_session spec — so it is asserted where it is decided.
 */
function observeFetch(): RequestInit[] {
  const calls: RequestInit[] = [];
  const real = globalThis.fetch;
  vi.stubGlobal('fetch', (input: RequestInfo | URL, init?: RequestInit) => {
    calls.push(init ?? {});
    return real(input, init);
  });
  return calls;
}

/** The document.cookie a browser would have. Node has no `document` at all in this project. */
function withCookies(value: string): void {
  vi.stubGlobal('document', { cookie: value });
}

/** A `window` whose only member is the one thing this module touches. */
function withLocation(): { assigned: string[] } {
  const assigned: string[] = [];
  vi.stubGlobal('window', {
    location: {
      assign: (url: string) => {
        assigned.push(url);
      },
    },
  });
  return { assigned };
}

// ── refreshCsrfToken (B3) ────────────────────────────────────────────────────────────────────────

describe('refreshCsrfToken', () => {
  beforeEach(() => {
    server.use(
      http.get(`${ORIGIN}/sanctum/csrf-cookie`, async ({ request }) => {
        await record(request);
        // Laravel answers 204 with the cookie in a Set-Cookie header. Node has no cookie jar, so the
        // cookie is supplied to the code under test as `document.cookie`, which is the only thing it
        // reads anyway.
        return new HttpResponse(null, { status: 204 });
      }),
    );
  });

  it('URL-DECODES the cookie, because Laravel percent-encodes the base64 padding', async () => {
    withCookies('laravel_session=abc; XSRF-TOKEN=eyJpdiI6InQ%3D%3D');

    // The single character that matters. Echoing `%3D` verbatim as X-XSRF-TOKEN 419s every mutation
    // while the cookie is perfectly valid — and it reproduces nowhere, because a token whose base64
    // happens to need no padding works fine.
    await expect(refreshCsrfToken()).resolves.toBe('eyJpdiI6InQ==');
  });

  it('sends credentials include, or Laravel never sees the session it is re-issuing for', async () => {
    const calls = observeFetch();
    withCookies('XSRF-TOKEN=token');

    await refreshCsrfToken();

    expect(recorded[0]?.method).toBe('GET');
    expect(recorded[0]?.url).toBe(`${ORIGIN}/sanctum/csrf-cookie`);
    expect(calls[0]?.credentials).toBe('include');
  });

  it('matches the cookie NAME exactly, so a lookalike cannot supply the token', async () => {
    // `document.cookie.includes('XSRF-TOKEN')` is true for every one of these. Two of them are
    // cookies Laravel never set, and the value they carry produces a 419 the user cannot clear by
    // signing in again.
    withCookies('MY-XSRF-TOKEN=wrong; XSRF-TOKEN_backup=alsowrong; XSRF-TOKEN=right');

    await expect(refreshCsrfToken()).resolves.toBe('right');
  });

  it('rejects with error_class null when the cookie is absent — never an invented class', async () => {
    // A third-party-cookie block or a misconfigured SESSION_DOMAIN. Neither is one of the 18, so the
    // slot stays null: unknown, and unknown is permanently non-retryable.
    withCookies('laravel_session=abc; XSRF-TOKEN_backup=nope');

    const error = await refreshCsrfToken().then(
      () => null,
      (cause: unknown) => cause,
    );
    expect(error).toBeInstanceOf(KbError);
    expect((error as KbError).error_class).toBeNull();
    expect((error as KbError).retryable).toBe(false);
  });
});

// ── browserFetch (B2) ────────────────────────────────────────────────────────────────────────────

describe('browserFetch and the credential union', () => {
  it('sends the decoded X-XSRF-TOKEN and credentials include on the admin surface', async () => {
    const calls = observeFetch();
    server.use(
      http.patch(`${ORIGIN}/api/v1/bots/01JBOT`, async ({ request }) => {
        await record(request);
        return HttpResponse.json({ id: '01JBOT', name: 'Support' });
      }),
    );

    const result = await browserFetch<{ id: string }>({
      path: '/api/v1/bots/01JBOT',
      method: 'PATCH',
      body: { name: 'Support' },
      credential: { kind: 'session', xsrf_token: 'decoded==token' },
    });

    expect(result).toEqual({ id: '01JBOT', name: 'Support' });
    // app.<domain> -> api.<domain> is same-SITE but not same-ORIGIN, so Laravel 13's
    // PreventRequestForgery falls through to token validation on every mutation. The header is not
    // optional for a cookie-authenticated write.
    expect(recorded[0]?.headers.get('x-xsrf-token')).toBe('decoded==token');
    expect(recorded[0]?.headers.get('authorization')).toBeNull();
    expect(recorded[0]?.body).toBe(JSON.stringify({ name: 'Support' }));
    expect(calls[0]?.credentials).toBe('include');
  });

  it("sends a bearer and credentials 'omit' on the hosted-chat surface", async () => {
    const calls = observeFetch();
    server.use(
      http.get(`${ORIGIN}/rt/v1/conversations/01JCONV`, async ({ request }) => {
        await record(request);
        return HttpResponse.json({ id: '01JCONV' });
      }),
    );

    await browserFetch({
      path: '/rt/v1/conversations/01JCONV',
      credential: { kind: 'chat_session', token: 'cs_opaque' },
    });

    expect(recorded[0]?.headers.get('authorization')).toBe('Bearer cs_opaque');
    expect(recorded[0]?.headers.get('x-xsrf-token')).toBeNull();

    // THE SECURITY BOUNDARY, and the reason this is a spec of its own rather than a line in the one
    // above. The admin session cookie is scoped to app. and api.; hosted chat runs on chat., which
    // renders model output — the highest-risk sink in the product. 'include' is silently empty there
    // TODAY and would become a live cross-surface credential leak the day the cookie scope widens by
    // one config line. 'omit' cannot become that, which is why it is asserted and not assumed.
    expect(calls[0]?.credentials).toBe('omit');
  });

  it('forwards the idempotency key as Idempotency-Key', async () => {
    server.use(
      http.post(`${ORIGIN}/rt/v1/conversations`, async ({ request }) => {
        await record(request);
        return HttpResponse.json({ id: '01JCONV' });
      }),
    );

    await browserFetch({
      path: '/rt/v1/conversations',
      method: 'POST',
      body: {},
      idempotency_key: '01JIDEMPOTENT',
      credential: { kind: 'chat_session', token: 'cs_opaque' },
    });

    expect(recorded[0]?.headers.get('idempotency-key')).toBe('01JIDEMPOTENT');
  });

  it('forwards the abort signal, or cancelQueries cancels only the result', async () => {
    server.use(
      http.get(`${ORIGIN}/api/v1/sources`, async () => {
        await new Promise((resolve) => setTimeout(resolve, 500));
        return HttpResponse.json([]);
      }),
    );

    const controller = new AbortController();
    const pending = browserFetch({
      path: '/api/v1/sources',
      credential: { kind: 'session', xsrf_token: 't' },
      signal: controller.signal,
    });
    controller.abort();

    await expect(pending).rejects.toThrow();
  });

  it('returns without parsing a body on 204', async () => {
    server.use(
      http.delete(`${ORIGIN}/api/v1/sources/01JSRC`, () => new HttpResponse(null, { status: 204 })),
    );

    // `json()` on an empty body rejects with a SyntaxError, which would surface as an unparseable
    // `unknown` failure over a request that actually succeeded.
    await expect(
      browserFetch({
        path: '/api/v1/sources/01JSRC',
        method: 'DELETE',
        credential: { kind: 'session', xsrf_token: 't' },
      }),
    ).resolves.toBeUndefined();
  });
});

describe('browserFetch error mapping', () => {
  it('builds the KbError from the envelope plus the Retry-After HEADER', async () => {
    server.use(
      http.post(`${ORIGIN}/api/v1/bots`, () =>
        HttpResponse.json(
          {
            error_class: 'rate_limit',
            message: 'bucket exhausted on api-7.internal',
            retryable: true,
            request_id: '01JREQ',
          },
          { status: 429, headers: { 'retry-after': '30' } },
        ),
      ),
    );

    const error = (await browserFetch({
      path: '/api/v1/bots',
      method: 'POST',
      body: {},
      credential: { kind: 'session', xsrf_token: 't' },
    }).then(
      () => null,
      (cause: unknown) => cause,
    )) as KbError;

    expect(error).toBeInstanceOf(KbError);
    expect(error.error_class).toBe('rate_limit');
    // Seconds, off the header. It is not in the JSON, and reading only the body means retrying
    // inside the window we were told to wait.
    expect(error.retry_after).toBe(30);
    expect(error.request_id).toBe('01JREQ');
  });

  it('yields error_class null for a response with no parseable envelope', async () => {
    server.use(
      http.get(
        `${ORIGIN}/api/v1/sources`,
        () => new HttpResponse('<html>502</html>', { status: 502 }),
      ),
    );

    const error = (await browserFetch({
      path: '/api/v1/sources',
      credential: { kind: 'session', xsrf_token: 't' },
    }).then(
      () => null,
      (cause: unknown) => cause,
    )) as KbError;

    expect(error.error_class).toBeNull();
    expect(error.retryable).toBe(false);
  });
});

// ── the 419 path (B3 x B2) ───────────────────────────────────────────────────────────────────────

describe('the 419 session-expiry path', () => {
  /**
   * A session-authenticated mutation fails CSRF BEFORE it fails auth, so an idle admin gets
   * `419 CSRF token mismatch` and never sees a 401. Nobody writes this path because nobody hits it
   * during a working day.
   */
  function csrfRoutes(options: { readonly failures: number }): void {
    let posts = 0;
    server.use(
      http.get(`${ORIGIN}/sanctum/csrf-cookie`, async ({ request }) => {
        await record(request);
        withCookies('XSRF-TOKEN=fresh%3D');
        return new HttpResponse(null, { status: 204 });
      }),
      http.post(`${ORIGIN}/api/v1/bots`, async ({ request }) => {
        await record(request);
        posts += 1;
        if (posts <= options.failures) {
          return HttpResponse.json(
            { error_class: 'authentication', message: 'CSRF token mismatch', retryable: false },
            { status: 419 },
          );
        }
        return HttpResponse.json({ id: '01JBOT' });
      }),
    );
  }

  it('refreshes the token, retries EXACTLY once, and sends the new token on the retry', async () => {
    withCookies('XSRF-TOKEN=stale');
    withLocation();
    csrfRoutes({ failures: 1 });

    const result = await browserFetch<{ id: string }>({
      path: '/api/v1/bots',
      method: 'POST',
      body: { name: 'Support' },
      credential: { kind: 'session', xsrf_token: 'stale' },
    });

    expect(result).toEqual({ id: '01JBOT' });
    expect(recorded.map((entry) => `${entry.method} ${new URL(entry.url).pathname}`)).toEqual([
      'POST /api/v1/bots',
      'GET /sanctum/csrf-cookie',
      'POST /api/v1/bots',
    ]);
    // The retry carries the REFRESHED, decoded token. Replaying the stale one would 419 again and
    // bounce a signed-in admin to the login page over a token they could have refreshed.
    expect(recorded[0]?.headers.get('x-xsrf-token')).toBe('stale');
    expect(recorded[2]?.headers.get('x-xsrf-token')).toBe('fresh=');
    // The body survives the retry: losing what the user just typed is the part people notice.
    expect(recorded[2]?.body).toBe(JSON.stringify({ name: 'Support' }));
  });

  it('performs a FULL DOCUMENT navigation to /login when the retry 419s too', async () => {
    withCookies('XSRF-TOKEN=stale');
    const location = withLocation();
    csrfRoutes({ failures: 2 });

    await expect(
      browserFetch({
        path: '/api/v1/bots',
        method: 'POST',
        body: {},
        credential: { kind: 'session', xsrf_token: 'stale' },
      }),
    ).rejects.toBeInstanceOf(KbError);

    // `window.location.assign`, never `router.push`. The Router Cache lives in the tab and is keyed
    // by path, so a client-side push leaves prefetched authenticated RSC payloads that the back
    // button renders after the session is gone.
    expect(location.assigned).toEqual(['/login']);
    // Exactly two attempts. A loop here is a signed-out client hammering the endpoint that is
    // throttled per account AND per IP.
    expect(recorded.filter((entry) => entry.method === 'POST')).toHaveLength(2);
  });

  it('does not refresh or navigate for a chat_session credential', async () => {
    const location = withLocation();
    let posts = 0;
    server.use(
      http.post(`${ORIGIN}/rt/v1/conversations`, async ({ request }) => {
        await record(request);
        posts += 1;
        return HttpResponse.json(
          { error_class: 'authentication', message: 'session expired', retryable: false },
          { status: 419 },
        );
      }),
    );

    await expect(
      browserFetch({
        path: '/rt/v1/conversations',
        method: 'POST',
        body: {},
        credential: { kind: 'chat_session', token: 'cs_opaque' },
      }),
    ).rejects.toBeInstanceOf(KbError);

    // CSRF is a session-only concept: a bearer-authenticated request has no XSRF-TOKEN cookie to
    // refresh. Retrying would send the mutation twice, and navigating would drop an anonymous
    // hosted-chat visitor onto an admin login page they have no business seeing.
    expect(posts).toBe(1);
    expect(location.assigned).toEqual([]);
    // No csrf-cookie request at all.
    expect(recorded.every((entry) => !entry.url.includes('sanctum'))).toBe(true);
  });
});
