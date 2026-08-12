import { KbError } from '@kb/contracts';
import { afterEach, beforeEach, describe, expect, it, jest } from '@jest/globals';

import { OfflineError, apiFetch, setUnauthenticatedHandler, toKbError } from '@/api/client';
import { writeSession } from '@/auth/secure-store';

/**
 * `apiFetch` — the one place that attaches a credential and the one place that handles a 401.
 *
 * WHY AN IN-PROCESS DOUBLE IS LEGITIMATE HERE, when tests/stream-answer.test.ts insists on a real
 * socket. The rule in tests/README.md is about STREAMING: a doubled response hands the reader one
 * complete body, so every chunk is a whole frame, every timing assertion is vacuous, and the suite
 * goes green over a read loop that drops tokens at a TCP boundary. None of that applies to a
 * buffered JSON call — a body that arrives whole IS the contract here — so the interesting
 * behaviour is entirely in what this function does with a status, a header and a rejection, and a
 * stubbed `fetch` can produce all three exactly.
 *
 * The one thing the double must be faithful about is HOW a transport failure arrives, because that
 * is the offline signal: every runtime this app ships on rejects with a `TypeError` when the
 * request never reached a server.
 */

const LIVE_SESSION = {
  token: 'sanctum-plaintext-token',
  expires_at: new Date(Date.now() + 30 * 24 * 3600 * 1000).toISOString(),
  organization_id: '01JORGA',
  user_id: '01JUSERA',
};

const ORIGIN = 'http://localhost:8080';

interface StubResponse {
  status: number;
  headers?: Record<string, string>;
  json?: () => Promise<unknown>;
}

function stubResponse({ status, headers = {}, json }: StubResponse): Response {
  return {
    status,
    ok: status >= 200 && status < 300,
    headers: {
      get: (name: string): string | null => headers[name.toLowerCase()] ?? null,
    },
    json:
      json ??
      (async () => {
        throw new SyntaxError('Unexpected end of JSON input');
      }),
  } as unknown as Response;
}

let fetchMock: jest.Mock;
let unauthenticatedCalls: number;

beforeEach(() => {
  unauthenticatedCalls = 0;
  setUnauthenticatedHandler(() => {
    unauthenticatedCalls += 1;
  });
  fetchMock = jest.fn();
  globalThis.fetch = fetchMock as unknown as typeof globalThis.fetch;
});

afterEach(() => {
  setUnauthenticatedHandler(null);
});

/** The init object `apiFetch` handed to fetch, with the headers narrowed for assertions. */
function lastInit(): { method: string; headers: Record<string, string>; body?: string } {
  return fetchMock.mock.calls[0]?.[1] as {
    method: string;
    headers: Record<string, string>;
    body?: string;
  };
}

describe('apiFetch', () => {
  it('attaches the bearer token and concatenates the path onto the pinned origin', async () => {
    await writeSession(LIVE_SESSION);
    fetchMock.mockResolvedValue(
      stubResponse({ status: 200, json: async () => ({ data: ['ok'] }) }) as never,
    );

    const body = await apiFetch<{ data: string[] }>({ path: '/rt/v1/conversations' });

    expect(body).toEqual({ data: ['ok'] });
    expect(fetchMock.mock.calls[0]?.[0]).toBe(`${ORIGIN}/rt/v1/conversations`);
    expect(lastInit().headers.Authorization).toBe(`Bearer ${LIVE_SESSION.token}`);
    expect(lastInit().method).toBe('GET');
  });

  it('refuses pre-flight inside the expiry grace window and never spends the request', async () => {
    // A token with 60 seconds left is inside the 90 s window, so `readToken()` reports it absent.
    // Sending anyway would cost a round trip over a metered radio to learn what is already known,
    // and it would cost the user their composed draft to a 401.
    await writeSession({
      ...LIVE_SESSION,
      expires_at: new Date(Date.now() + 60 * 1000).toISOString(),
    });

    await expect(apiFetch({ path: '/rt/v1/conversations' })).rejects.toBeInstanceOf(KbError);
    expect(fetchMock).not.toHaveBeenCalled();
    expect(unauthenticatedCalls).toBe(1);
  });

  it('treats a 401 as a session event, not merely a failed request', async () => {
    await writeSession(LIVE_SESSION);
    fetchMock.mockResolvedValue(
      stubResponse({
        status: 401,
        headers: { 'content-type': 'application/json' },
        json: async () => ({
          error_class: 'authentication',
          message: 'Unauthenticated.',
          retryable: false,
          request_id: '01JREQ',
        }),
      }) as never,
    );

    const error = await apiFetch({ path: '/rt/v1/conversations' }).catch((e: unknown) => e);

    // The purge-and-navigate handler fires, and it fires ONCE — the token is gone (expired, or
    // revoked from another device), which is a session event. Handling a 401 inside the generic
    // non-2xx branch would throw the right error and skip the purge.
    expect(unauthenticatedCalls).toBe(1);
    expect(error).toBeInstanceOf(KbError);
    expect((error as KbError).error_class).toBe('authentication');
    // Non-retryable, and nothing above retries it: a retry loop against a dead token is how a
    // device gets rate-limited out of its own login endpoint.
    expect((error as KbError).retryable).toBe(false);
  });

  it('maps any other non-2xx through the shared envelope, with Retry-After off the header', async () => {
    await writeSession(LIVE_SESSION);
    fetchMock.mockResolvedValue(
      stubResponse({
        status: 429,
        headers: { 'content-type': 'application/json', 'retry-after': '30' },
        json: async () => ({
          error_class: 'rate_limit',
          message: 'slow down',
          retryable: true,
          request_id: '01JREQ',
        }),
      }) as never,
    );

    const error = (await apiFetch({ path: '/rt/v1/conversations' }).catch(
      (e: unknown) => e,
    )) as KbError;

    expect(error).toBeInstanceOf(KbError);
    expect(error.error_class).toBe('rate_limit');
    // Seconds, off the RESPONSE HEADER — it is not in the JSON envelope. Spelled in camelCase it
    // reads undefined and we retry inside the window we were told to wait.
    expect(error.retry_after).toBe(30);
    // NOT a session event. Purging on every failure would sign the user out on a rate limit.
    expect(unauthenticatedCalls).toBe(0);
  });

  it('returns from a 204 without parsing an empty body', async () => {
    await writeSession(LIVE_SESSION);
    // The stub's `json()` throws, exactly as a real one does on an empty body. A `res.json()` on a
    // 204 reports a malformed server response for the one status that means "this worked and there
    // is nothing to say" — which is every successful delete.
    fetchMock.mockResolvedValue(stubResponse({ status: 204 }) as never);

    await expect(apiFetch({ path: '/rt/v1/conversations/01J', method: 'DELETE' })).resolves.toBe(
      undefined,
    );
  });

  it('reports a transport failure as offline, never as a server error class', async () => {
    await writeSession(LIVE_SESSION);
    // What React Native throws with the radio off. There is no response, no envelope, no
    // `error_class` and no `request_id`.
    fetchMock.mockRejectedValue(new TypeError('Network request failed') as never);

    const error = await apiFetch({ path: '/rt/v1/conversations' }).catch((e: unknown) => e);

    expect(error).toBeInstanceOf(OfflineError);
    // THE ASSERTION THAT MATTERS. `internal_dependency` is the class this would plausibly be mapped
    // to, and it is retryable AND it pages — so a tunnel would wake someone up and a phone would
    // retry into a dead radio on a full backoff ladder.
    expect(error).not.toBeInstanceOf(KbError);
    expect(JSON.stringify({ m: (error as Error).message })).not.toContain('internal_dependency');
    // And it is not a session event either: being offline is not being signed out.
    expect(unauthenticatedCalls).toBe(0);
  });

  it('lets an abort stay an abort rather than reporting it as offline', async () => {
    await writeSession(LIVE_SESSION);
    // Cancellation is an outcome (499 / `user_cancellation`), not a failure, and it is identified
    // by NAME: an AbortError is a DOMException on some runtimes and a plain Error on others, so
    // `instanceof` is not portable. Misfiled as offline it would render "you are offline" every
    // time a user navigated away from a loading screen.
    const aborted = new Error('The operation was aborted');
    aborted.name = 'AbortError';
    fetchMock.mockRejectedValue(aborted as never);

    const error = await apiFetch({ path: '/rt/v1/conversations' }).catch((e: unknown) => e);

    expect((error as Error).name).toBe('AbortError');
    expect(error).not.toBeInstanceOf(OfflineError);
  });

  it('sends a JSON body with its content type, and an idempotency key when given one', async () => {
    await writeSession(LIVE_SESSION);
    fetchMock.mockResolvedValue(stubResponse({ status: 204 }) as never);

    await apiFetch({
      path: '/rt/v1/conversations',
      method: 'POST',
      body: { title: 'new' },
      idempotencyKey: 'idem-1',
    });

    expect(lastInit().body).toBe('{"title":"new"}');
    expect(lastInit().headers['Content-Type']).toBe('application/json');
    // The IETF spelling, i.e. the PUBLIC one. `X-KB-Idempotency-Key` belongs to the
    // Laravel<->FastAPI seam and this app has no HMAC key, so anything it wrote there would be an
    // unsigned claim on an internal contract.
    expect(lastInit().headers['Idempotency-Key']).toBe('idem-1');
    expect(Object.keys(lastInit().headers)).not.toContain('X-KB-Idempotency-Key');
  });

  it('forwards the abort signal so cancelQueries aborts the request and not merely its result', async () => {
    await writeSession(LIVE_SESSION);
    fetchMock.mockResolvedValue(stubResponse({ status: 204 }) as never);
    const controller = new AbortController();

    await apiFetch({ path: '/rt/v1/conversations', signal: controller.signal });

    expect((fetchMock.mock.calls[0]?.[1] as { signal?: AbortSignal }).signal).toBe(
      controller.signal,
    );
  });
});

describe('toKbError', () => {
  it('yields a null class for a response with no parseable envelope', async () => {
    // Unknown, and unknown is PERMANENTLY non-retryable. Never invent a class to fill the slot, and
    // in particular never reach for `internal_dependency`: it is retryable and it pages, so a
    // malformed response would wake someone up.
    const error = await toKbError(
      stubResponse({ status: 500, headers: { 'content-type': 'text/html' } }),
    );

    expect(error.error_class).toBeNull();
    expect(error.retryable).toBe(false);
  });
});
