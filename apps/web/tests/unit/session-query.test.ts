import { KbError, type SessionResource } from '@kb/contracts';
import { HttpResponse, http } from 'msw';
import { setupServer } from 'msw/node';
import { afterAll, afterEach, beforeAll, describe, expect, it, vi } from 'vitest';

import { sessionCredential } from '@/lib/api/browser';
import {
  activeOrganizations,
  browserFetchData,
  fetchSession,
  LOGIN_PATH,
  LOGOUT_PATH,
  ME_PATH,
  SESSION_KEY,
  sessionQueryOptions,
  SWITCH_ORGANIZATION_PATH,
  toSessionState,
} from '@/features/auth/session';

/**
 * The session layer — key, endpoints, credential, unwrap, and the state machine.
 *
 * MSW over `setupServer` is legitimate here for the reason tests/unit/browser-fetch.test.ts gives: every
 * response is one buffered JSON document, so there is no chunk boundary a mock can flatten. The origin
 * is `http://api.invalid` (vitest.config.ts) — RFC 2606 reserves `.invalid`, so a request the mock fails
 * to intercept dies on DNS instead of reaching a host that happens to exist.
 */

const ORIGIN = 'http://api.invalid';

const server = setupServer();

beforeAll(() => {
  // `error`, not `warn`: an unhandled request here is a spec that thinks it is asserting something and
  // is actually watching a DNS failure.
  server.listen({ onUnhandledRequest: 'error' });
});
afterEach(() => {
  server.resetHandlers();
  vi.unstubAllGlobals();
});
afterAll(() => {
  server.close();
});

/** The `document.cookie` a browser would have. Node has no `document` in this project at all. */
const withCookies = (value: string): void => {
  vi.stubGlobal('document', { cookie: value });
};

const wrapped = <T>(data: T): { data: T } => ({ data });

const fixture = (overrides: Partial<SessionResource> = {}): SessionResource => ({
  user: {
    id: '01JUSER',
    name: 'Ada Lovelace',
    email: 'ada@example.test',
    email_verified: true,
    is_platform_owner: false,
  },
  current_organization_id: '01JORGA',
  organizations: [
    { id: '01JORGA', name: 'Acme Research', slug: 'acme', role: 'owner', status: 'active' },
    { id: '01JORGB', name: 'Brightwater Legal', slug: 'bw', role: 'analyst', status: 'active' },
  ],
  ...overrides,
});

// ── the key ──────────────────────────────────────────────────────────────────────────────────────

describe('SESSION_KEY is not org-prefixed, because this query is the PRODUCER of the org id', () => {
  it("is exactly ['session']", () => {
    // The one legal exception to "every query key begins ['org', orgId, …]". `orgKey` REQUIRES an
    // orgId, and this response is where the orgId comes from — namespacing the identity document by
    // the organization it announces would derive the key from the answer it is fetching.
    expect(SESSION_KEY).toEqual(['session']);
  });

  it('does not begin with the org namespace, and that is asserted rather than assumed', () => {
    expect(SESSION_KEY[0]).not.toBe('org');
  });

  it('is the key the query options carry', () => {
    expect(sessionQueryOptions().queryKey).toBe(SESSION_KEY);
  });

  it('carries NO retry property — the identifier is banned outside lib/query/client.ts', () => {
    // Asserted structurally, not by grep: the global predicate already refuses to retry
    // `authentication`, which is exactly the policy a 401 on /me needs.
    expect(Object.keys(sessionQueryOptions())).toEqual(['queryKey', 'queryFn']);
  });
});

// ── the endpoints (decision D1) ──────────────────────────────────────────────────────────────────

describe('the endpoint paths are the ones the approved route table names', () => {
  it('reads identity from /api/v1/me, not /api/v1/session', () => {
    expect(ME_PATH).toBe('/api/v1/me');
  });

  it('signs in and OUT with POST, not DELETE', () => {
    // A wrong verb is a 405 with no envelope, which renders as "Something went wrong." and tells
    // nobody anything.
    expect(LOGIN_PATH).toBe('/api/v1/auth/login');
    expect(LOGOUT_PATH).toBe('/api/v1/auth/logout');
  });

  it('switches organization at /api/v1/session/organization', () => {
    expect(SWITCH_ORGANIZATION_PATH).toBe('/api/v1/session/organization');
  });
});

// ── the `data` envelope ──────────────────────────────────────────────────────────────────────────

describe('the data envelope is unwrapped ONCE, at the fetch boundary', () => {
  it('returns the resource itself, never the wrapper', async () => {
    withCookies('XSRF-TOKEN=test-token');
    server.use(http.get(`${ORIGIN}${ME_PATH}`, () => HttpResponse.json(wrapped(fixture()))));

    const session = await fetchSession(new AbortController().signal);

    // If this read had to be `session.data.user`, every render site would know about the envelope —
    // and there are more render sites than fetchers.
    expect(session.user.email).toBe('ada@example.test');
    expect(session).not.toHaveProperty('data');
  });

  it('works for the acknowledgement bodies too, which are wrapped exactly the same way', async () => {
    withCookies('XSRF-TOKEN=test-token');
    server.use(
      http.post(`${ORIGIN}${LOGOUT_PATH}`, () =>
        HttpResponse.json(wrapped({ acknowledged: true })),
      ),
    );

    await expect(
      browserFetchData<{ acknowledged: boolean }>({
        path: LOGOUT_PATH,
        method: 'POST',
        credential: { kind: 'session', xsrf_token: 'test-token' },
      }),
    ).resolves.toEqual({ acknowledged: true });
  });

  it('surfaces a 401 as a KbError with error_class authentication, never as 200 with a null user', async () => {
    withCookies('XSRF-TOKEN=test-token');
    server.use(
      http.get(`${ORIGIN}${ME_PATH}`, () =>
        HttpResponse.json(
          {
            error_class: 'authentication',
            message: 'operator detail from api-7.internal',
            retryable: false,
            request_id: '01JREQ',
          },
          { status: 401 },
        ),
      ),
    );

    const error = await fetchSession(new AbortController().signal).then(
      () => null,
      (cause: unknown) => cause,
    );

    expect(error).toBeInstanceOf(KbError);
    expect((error as KbError).error_class).toBe('authentication');
  });
});

// ── sessionCredential ────────────────────────────────────────────────────────────────────────────

describe('sessionCredential reuses the module-private readCookie and never forks it', () => {
  it('makes NO network call when XSRF-TOKEN is already present', async () => {
    // No handler is registered for /sanctum/csrf-cookie, and `onUnhandledRequest: 'error'` means a
    // request would fail the spec by name. The common path is zero round trips.
    withCookies('kb_session=abc; XSRF-TOKEN=eyJpdiI6InQ%3D%3D');

    // URL-DECODED, which is the half that has no symptom until every mutation 419s.
    await expect(sessionCredential()).resolves.toEqual({
      kind: 'session',
      xsrf_token: 'eyJpdiI6InQ==',
    });
  });

  it('makes EXACTLY ONE csrf-cookie request on a cold document', async () => {
    let calls = 0;
    server.use(
      http.get(`${ORIGIN}/sanctum/csrf-cookie`, () => {
        calls += 1;
        // The cookie arrives via Set-Cookie in a browser; Node has no jar, so it is seeded here.
        vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=fresh' });
        return new HttpResponse(null, { status: 204 });
      }),
    );
    withCookies('kb_session=abc');

    await expect(sessionCredential()).resolves.toEqual({ kind: 'session', xsrf_token: 'fresh' });
    expect(calls).toBe(1);
  });
});

// ── signal forwarding ────────────────────────────────────────────────────────────────────────────

describe('the fetcher forwards its signal, or cancelQueries aborts nothing', () => {
  it('aborts the REQUEST and not merely its result', async () => {
    withCookies('XSRF-TOKEN=test-token');
    let handlerSawAbort = false;
    server.use(
      http.get(`${ORIGIN}${ME_PATH}`, async ({ request }) => {
        await new Promise<void>((resolve) => {
          request.signal.addEventListener('abort', () => {
            handlerSawAbort = true;
            resolve();
          });
          setTimeout(resolve, 500);
        });
        return HttpResponse.json(wrapped(fixture()));
      }),
    );

    const controller = new AbortController();
    const pending = fetchSession(controller.signal).then(
      () => 'resolved',
      () => 'rejected',
    );
    // After a tick, so the request is genuinely in flight rather than never started.
    await new Promise((resolve) => setTimeout(resolve, 20));
    controller.abort();

    await expect(pending).resolves.toBe('rejected');
    // Step 1 of both logout and the org switch depends on this being true.
    expect(handlerSawAbort).toBe(true);
  });
});

// ── activeOrganizations ──────────────────────────────────────────────────────────────────────────

describe('activeOrganizations filters client-side, because the server sends every membership', () => {
  it('keeps only status active', () => {
    const session = fixture({
      organizations: [
        { id: '01JORGA', name: 'Acme', slug: 'acme', role: 'owner', status: 'active' },
        { id: '01JORGB', name: 'Bright', slug: 'bw', role: 'analyst', status: 'suspended' },
        { id: '01JORGC', name: 'Cinder', slug: 'cn', role: 'admin', status: 'invited' },
      ],
    });

    // A switcher that offered the suspended or invited row would be offering a 403.
    expect(activeOrganizations(session).map((organization) => organization.id)).toEqual([
      '01JORGA',
    ]);
  });

  it('distinguishes "no memberships at all" from "none of them active"', () => {
    // Two different sentences and two different next steps, which is why the resource ships the
    // inactive rows with their status instead of filtering server-side.
    expect(activeOrganizations(fixture({ organizations: [] }))).toHaveLength(0);
    expect(
      activeOrganizations(
        fixture({
          organizations: [
            { id: '01JORGB', name: 'Bright', slug: 'bw', role: 'analyst', status: 'invited' },
          ],
        }),
      ),
    ).toHaveLength(0);
  });
});

// ── the state machine ────────────────────────────────────────────────────────────────────────────

describe('toSessionState never turns a server fault into "signed out"', () => {
  it('is loading while the query is pending', () => {
    expect(toSessionState('pending', undefined, null)).toEqual({ status: 'loading' });
  });

  it('is anonymous ONLY for error_class authentication', () => {
    const error = new KbError('authentication', false, null, '01JREQ', 'operator detail');
    expect(toSessionState('error', undefined, error)).toEqual({ status: 'anonymous' });
  });

  it('is unavailable — NOT anonymous — for a 502 with no envelope', () => {
    // Folding this into `anonymous` would bounce a signed-in admin to /login and tell them a lie
    // about why; leaving it on `loading` renders skeletons forever with nothing logged.
    const state = toSessionState('error', undefined, new Error('boom'));
    expect(state.status).toBe('unavailable');
    expect(state.status === 'unavailable' && state.error.error_class).toBeNull();
  });

  it('is unavailable for rate_limit, which is emphatically not an identity answer', () => {
    const error = new KbError('rate_limit', true, 30, '01JREQ', 'operator detail');
    const state = toSessionState('error', undefined, error);
    expect(state.status).toBe('unavailable');
    expect(state.status === 'unavailable' && state.error.retry_after).toBe(30);
  });

  it('carries a null orgId through, because that state is reachable by design', () => {
    // Login succeeds with `current_organization_id: null` for a user whose memberships are all
    // invited or suspended; refusing it would make resend-verification unreachable.
    const state = toSessionState(
      'success',
      fixture({ current_organization_id: null, organizations: [] }),
      null,
    );
    expect(state).toEqual({
      status: 'authenticated',
      user: fixture().user,
      organizations: [],
      orgId: null,
    });
  });
});
