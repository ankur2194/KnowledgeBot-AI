import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { GET } from '@/app/(chat)/c/[publicBotId]/theme.css/route';

/**
 * The hosted-chat palette route (5B-B2).
 *
 * TWO DEFECTS, ONE SEAM. It fetched `api/v1/public/bots/{id}/theme` — a prefix on the ADMIN group
 * that has no `public/*` surface and never will — and it treated any non-2xx OR any thrown error as
 * "render the platform default". So hosted chat served every tenant's bot in the platform's colours
 * with no error, no log and no failing test, and the parent page names this route as THE
 * implementation of public bot configuration, so it was the whole seam rather than an outlying
 * asset.
 *
 * The rendering behaviour is unchanged and deliberately so: a theme is decoration, and an error page
 * is not a better answer than the default palette. What is asserted below is that the failure is now
 * ADDRESSED to a surface that can exist and LEAVES A TRACE — a `console.warn` carrying the upstream
 * status and `X-KB-Request-Id`, and an `x-kb-theme: unavailable` header that separates "we could not
 * ask" from "this bot configured nothing", two states whose response bodies are both empty.
 *
 * `global.fetch` is stubbed rather than mocked through MSW because the assertion that matters most
 * is the URL STRING, and a handler-based mock answers a request only if the URL already matches —
 * which is the shape of test that let the original defect through.
 */

const API_ORIGIN = 'http://api.invalid'; // vitest.config.ts sets NEXT_PUBLIC_API_ORIGIN to this.
const BOT = '01JBOTPUBLIC';

const params = (publicBotId: string) => ({ params: Promise.resolve({ publicBotId }) });

/** Every stylesheet response, read the way a browser and an operator would read it. */
async function read(response: Response) {
  return {
    body: await response.text(),
    outcome: response.headers.get('x-kb-theme'),
    cacheControl: response.headers.get('cache-control'),
    contentType: response.headers.get('content-type'),
  };
}

let fetchMock: ReturnType<typeof vi.fn>;
let warn: ReturnType<typeof vi.spyOn>;

beforeEach(() => {
  fetchMock = vi.fn();
  vi.stubGlobal('fetch', fetchMock);
  warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('the palette route addresses the PUBLIC RUNTIME surface', () => {
  it('fetches rt/v1, and nothing under the admin api/v1 prefix', async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ primary: 'oklch(0.5 0.1 20)' }), {
        status: 200,
        headers: { 'content-type': 'application/json' },
      }),
    );

    await GET(new Request(`http://chat.invalid/c/${BOT}/theme.css`), params(BOT));

    const url = fetchMock.mock.calls[0]?.[0] as string;
    expect(url).toBe(`${API_ORIGIN}/rt/v1/bots/${BOT}/theme`);
    // THE DEFECT THIS PINS. `api/v1` is the Sanctum cookie-session admin group behind
    // `surface:admin` + `org.member` under `organizations/{organization}`; this is a
    // server-to-server call with no cookie and no token, so every request to it was a 401 that the
    // fail-open then rendered as the platform default.
    expect(url).not.toContain('/api/v1/');
    expect(url).not.toContain('/public/');
  });

  it('carries no credential — the palette is a function of the public bot id alone', async () => {
    fetchMock.mockResolvedValue(new Response('{}', { status: 200 }));

    await GET(new Request(`http://chat.invalid/c/${BOT}/theme.css`), params(BOT));

    const init = fetchMock.mock.calls[0]?.[1] as RequestInit & { next?: unknown };
    // No cookie, no Authorization, no `credentials: 'include'`. If this response ever varied by a
    // caller it would be cached under a key — publicBotId — that does not name the caller.
    expect(init.headers).toEqual({ accept: 'application/json' });
    expect(init.credentials).toBeUndefined();
    // The cache tag is still the bot, which is the only thing the response varies by.
    expect(init.next).toEqual({ tags: [`bot:${BOT}`], revalidate: 60 });
  });
});

describe('a failed palette fetch is visible, not silent', () => {
  it('logs the status and the X-KB-Request-Id on a non-2xx, and marks the response unavailable', async () => {
    // The literal defect: a 401 from a route that structurally cannot serve this request.
    fetchMock.mockResolvedValue(
      new Response('{"error_class":"authentication","message":"Unauthenticated."}', {
        status: 401,
        headers: { 'x-kb-request-id': '01JREQFROMLARAVEL' },
      }),
    );

    const { body, outcome, cacheControl } = await read(
      await GET(new Request(`http://chat.invalid/c/${BOT}/theme.css`), params(BOT)),
    );

    // Rendering is UNCHANGED: the platform default, never an error page.
    expect(body).toBe('');
    // But it now says which of the two empty bodies this is.
    expect(outcome).toBe('unavailable');
    // And it does not pin the wrong colours into a CDN for five minutes.
    expect(cacheControl).toBe('no-store');

    expect(warn).toHaveBeenCalledTimes(1);
    expect(warn.mock.calls[0]?.[0]).toBe('[kb] bot theme unavailable');
    expect(warn.mock.calls[0]?.[1]).toMatchObject({
      public_bot_id: BOT,
      status: 401,
      // The one identifier that lets an operator find this request in Laravel's log.
      request_id: '01JREQFROMLARAVEL',
    });
  });

  it('never logs the upstream response body — it is operator-facing text from another process', async () => {
    fetchMock.mockResolvedValue(
      new Response('{"message":"no route matched on api-7.internal"}', { status: 404 }),
    );

    await GET(new Request(`http://chat.invalid/c/${BOT}/theme.css`), params(BOT));

    // Asserted before the content check, so a route that logs NOTHING fails on the missing trace
    // rather than "passing" the redaction assertion by having no log line to redact.
    expect(warn).toHaveBeenCalledTimes(1);
    expect(JSON.stringify(warn.mock.calls[0]?.[1] ?? {})).not.toContain('api-7.internal');
  });

  it('logs a thrown transport failure too — DNS, refused connection, unparseable body', async () => {
    fetchMock.mockRejectedValue(new TypeError('fetch failed'));

    const { body, outcome, cacheControl } = await read(
      await GET(new Request(`http://chat.invalid/c/${BOT}/theme.css`), params(BOT)),
    );

    expect(body).toBe('');
    expect(outcome).toBe('unavailable');
    expect(cacheControl).toBe('no-store');
    expect(warn).toHaveBeenCalledTimes(1);
    expect(warn.mock.calls[0]?.[1]).toMatchObject({
      public_bot_id: BOT,
      status: null,
      request_id: null,
      reason: 'fetch failed',
    });
  });

  it('logs a 200 whose body is not JSON — the proxy page that answers 200', async () => {
    fetchMock.mockResolvedValue(new Response('<html>hello</html>', { status: 200 }));

    const { outcome } = await read(
      await GET(new Request(`http://chat.invalid/c/${BOT}/theme.css`), params(BOT)),
    );

    expect(outcome).toBe('unavailable');
    expect(warn).toHaveBeenCalledTimes(1);
  });
});

describe('a successful palette fetch is distinguishable from a failed one', () => {
  it('renders the tenant declarations and marks the response `bot`', async () => {
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ primary: 'oklch(0.5 0.1 20)', radius: '0.625rem' }), {
        status: 200,
      }),
    );

    const { body, outcome, cacheControl, contentType } = await read(
      await GET(new Request(`http://chat.invalid/c/${BOT}/theme.css`), params(BOT)),
    );

    expect(body).toContain('--primary: oklch(0.5 0.1 20);');
    expect(body).toMatch(/^:root \{\n/);
    expect(outcome).toBe('bot');
    expect(cacheControl).toBe('public, max-age=0, s-maxage=60, stale-while-revalidate=300');
    expect(contentType).toBe('text/css; charset=utf-8');
    expect(warn).not.toHaveBeenCalled();
  });

  it('marks an answered-but-empty theme `default`, NOT `unavailable`', async () => {
    // Same empty body as a failure, and it is the distinction the header exists for: we asked, we
    // were answered, and the answer was "nothing to override". Nothing is logged.
    fetchMock.mockResolvedValue(new Response('{}', { status: 200 }));

    const { body, outcome, cacheControl } = await read(
      await GET(new Request(`http://chat.invalid/c/${BOT}/theme.css`), params(BOT)),
    );

    expect(body).toBe('');
    expect(outcome).toBe('default');
    // Cacheable: an answered "no theme" is a real answer worth holding for 60 s.
    expect(cacheControl).toContain('s-maxage=60');
    expect(warn).not.toHaveBeenCalled();
  });

  it('drops a value that fails the grammar rather than guessing at it', async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({ primary: 'oklch(.5 .1 20); } body { background: url(https://evil/) ' }),
        { status: 200 },
      ),
    );

    const { body, outcome } = await read(
      await GET(new Request(`http://chat.invalid/c/${BOT}/theme.css`), params(BOT)),
    );

    expect(body).toBe('');
    expect(body).not.toContain('evil');
    // A rejected VALUE is not an upstream failure: the fetch worked. `default` is the honest state.
    expect(outcome).toBe('default');
  });
});

describe('a bot id that is not a bot id never reaches the API', () => {
  it.each(['../../admin', 'a'.repeat(65), '01J BOT', ''])('rejects %j locally', async (id) => {
    const { body, outcome } = await read(
      await GET(new Request('http://chat.invalid/c/x/theme.css'), params(id)),
    );

    expect(fetchMock).not.toHaveBeenCalled();
    expect(body).toBe('');
    // `default`, not `unavailable`: nothing failed, because nothing was asked. And nothing is
    // logged — an unbounded log line per malformed path segment is a log-flooding primitive.
    expect(outcome).toBe('default');
    expect(warn).not.toHaveBeenCalled();
  });
});
