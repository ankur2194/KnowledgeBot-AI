import { http, HttpResponse } from 'msw';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Providers } from '@/components/providers';
import { browserNavigation } from '@/features/auth/session';
import { useCurrentOrgId, useOrgKey, useSession } from '@/features/auth/session-context';
import { SessionNotice, SessionProvider } from '@/features/auth/session-provider';

import { envelope, ORIGIN, sessionFixture } from '../msw/handlers';
import { worker } from '../msw/setup';

/**
 * The session layer as the tree sees it.
 *
 * A component spec may not assert isolation, authentication or navigation (the vitest-playwright
 * boundary table), and nothing here is cited as coverage for any of the three. What it CAN assert is
 * the state machine's rendered consequences — which notice appears, what `useOrgKey` produces, and that
 * the code asked for a navigation.
 */

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

/** Renders everything the three reader hooks answer, so one probe covers all of them. */
function Probe() {
  const session = useSession();
  const orgId = useCurrentOrgId();
  const orgKey = useOrgKey();

  let key: string;
  try {
    key = JSON.stringify(orgKey('sources'));
  } catch {
    // The throw is the designed behaviour for a null org, not an accident: a key built from
    // `undefined` collapses every org-less state into one shared cache namespace.
    key = 'THREW';
  }

  return (
    <div>
      <p data-testid="status">{session.status}</p>
      <p data-testid="orgId">{orgId ?? 'null'}</p>
      <p data-testid="orgKey">{key}</p>
    </div>
  );
}

/**
 * `<SessionNotice/>` is rendered as a CHILD here, mirroring where the real tree puts it.
 *
 * It used to be rendered by `<SessionProvider>` itself, beside `{children}` — which meant it painted
 * OUTSIDE `<AppShell/>`, as a full-bleed bar on the page background belonging to no plane. The shell
 * is the outermost thing (kb-ui-patterns P1), so the notice moved into the main column and this
 * harness follows it. The BEHAVIOUR under test is unchanged: the same three states, the same
 * sentences, still above the content rather than instead of it.
 */
const renderTree = () =>
  render(
    <Providers>
      <SessionProvider>
        <SessionNotice />
        <Probe />
      </SessionProvider>
    </Providers>,
  );

describe('authenticated, with a current organization', () => {
  it('produces an org-prefixed key from the id the session announced', async () => {
    const screen = await renderTree();

    await expect.element(screen.getByTestId('status')).toHaveTextContent('authenticated');
    await expect
      .element(screen.getByTestId('orgId'))
      .toHaveTextContent('01JORGAAAAAAAAAAAAAAAAAAAA');
    // `useOrgKey` is the PRODUCER the orgKey docblock has been waiting for. Every other query in the
    // app is namespaced through this.
    await expect
      .element(screen.getByTestId('orgKey'))
      .toHaveTextContent('["org","01JORGAAAAAAAAAAAAAAAAAAAA","sources"]');
  });

  it('renders no membership notice when at least one membership is active', async () => {
    const screen = await renderTree();
    await expect.element(screen.getByTestId('status')).toHaveTextContent('authenticated');
    expect(screen.getByRole('alert').elements()).toHaveLength(0);
  });
});

describe('authenticated with no ACTIVE membership — a reachable state, not an error', () => {
  it('says "not a member of any organization" when the list is empty', async () => {
    // Login succeeds with `current_organization_id: null` by design: refusing it would leave the
    // resend-verification and accept-invitation endpoints unreachable and the user with no way out.
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () =>
        HttpResponse.json({
          data: sessionFixture({ current_organization_id: null, organizations: [] }),
        }),
      ),
    );

    const screen = await renderTree();

    await expect
      .element(screen.getByRole('alert'))
      .toHaveTextContent('You are not a member of any organization');
    await expect.element(screen.getByTestId('orgId')).toHaveTextContent('null');
    // Every downstream org query must be gated on `enabled: orgId !== null`; the throw is what makes a
    // component that forgot fail loudly instead of sharing one cache namespace with every other org.
    await expect.element(screen.getByTestId('orgKey')).toHaveTextContent('THREW');
  });

  it('says something DIFFERENT when memberships exist but none is active', async () => {
    // The server ships `invited` and `suspended` rows with their status precisely so the client can
    // tell these apart. Collapsing them would tell a suspended member their account is empty.
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () =>
        HttpResponse.json({
          data: sessionFixture({
            current_organization_id: null,
            organizations: [
              {
                id: '01JORGBBBBBBBBBBBBBBBBBBBB',
                name: 'Brightwater Legal',
                slug: 'brightwater-legal',
                role: 'analyst',
                status: 'suspended',
              },
            ],
          }),
        }),
      ),
    );

    const screen = await renderTree();

    await expect.element(screen.getByRole('alert')).toHaveTextContent('No active organization');
    await expect.element(screen.getByTestId('orgId')).toHaveTextContent('null');
  });
});

describe('a server fault is NOT "signed out"', () => {
  it('renders the class-mapped sentence for a 500 and stays out of the anonymous branch', async () => {
    // Folding this into `anonymous` would bounce a signed-in admin to /login and tell them a lie about
    // why; leaving it on `loading` renders skeletons forever with nothing logged.
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () =>
        HttpResponse.json(envelope('internal_dependency', { retryable: false }), { status: 500 }),
      ),
    );
    const assign = vi.spyOn(browserNavigation, 'assign').mockImplementation(() => undefined);

    const screen = await renderTree();

    await expect.element(screen.getByTestId('status')).toHaveTextContent('unavailable');
    await expect
      .element(screen.getByRole('alert'))
      .toHaveTextContent('Something on our side is unavailable. Try again shortly.');
    // Never the envelope's `message`, on any class.
    expect(document.body.textContent).not.toContain('api-7.internal');
    expect(assign).not.toHaveBeenCalled();
  });
});

/**
 * THE FOURTH STATE, and the one the other three specs cannot reach: `loading` is what the provider
 * reports while `GET /me` is still in flight, and it is the state a slow network spends the most time
 * in. It had no assertion.
 *
 * The failure it protects against is the one that looks like a reasonable simplification: folding
 * pending into `anonymous` (or defaulting the status) bounces every user to /login on a slow first
 * paint and then bounces them back, which reads as "randomly signed out" and is unreproducible on a
 * fast connection. `unavailable` and `anonymous` are answers; `loading` is the absence of one.
 *
 * The request is HELD OPEN rather than delayed by a fixed number of milliseconds, because a
 * `setTimeout` race is what makes this spec flaky on a loaded runner — and it is RELEASED before the
 * spec ends, because a response still in flight has its resolution land in the NEXT spec's iframe.
 */
describe('`loading` is the absence of an answer, and nothing bounces on it', () => {
  it('stays loading, refuses to build an org key, and asks for no navigation', async () => {
    let release!: () => void;
    const held = new Promise<void>((resolve) => {
      release = resolve;
    });

    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, async () => {
        await held;
        return HttpResponse.json({ data: sessionFixture() });
      }),
    );
    const assign = vi.spyOn(browserNavigation, 'assign').mockImplementation(() => undefined);

    const screen = await renderTree();

    await expect.element(screen.getByTestId('status')).toHaveTextContent('loading');
    await expect.element(screen.getByTestId('orgId')).toHaveTextContent('null');
    // The throw matters here as much as in the org-less state: a key built from an id we do not have
    // YET would put this user's first queries in the same cache namespace as every other org's.
    await expect.element(screen.getByTestId('orgKey')).toHaveTextContent('THREW');
    expect(assign).not.toHaveBeenCalled();

    // Consume our own delayed response, and assert the transition — which also proves the spec was
    // observing a genuinely pending request rather than a handler that never matched.
    release();
    await expect.element(screen.getByTestId('status')).toHaveTextContent('authenticated');
    expect(assign).not.toHaveBeenCalled();
  });
});

/**
 * ONE bounce assertion in this FILE, deliberately. The guard against a double navigation is a
 * module-level `let bouncing`, and each Vitest spec file gets a fresh module graph — so the first
 * observation is the only one, and a second `it` asserting a bounce would fail against correct code.
 */
describe('a 401 is the one error that bounces, and it bounces exactly once', () => {
  it('asks for /login?next=<current location>, encoded', async () => {
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () =>
        HttpResponse.json(envelope('authentication'), { status: 401 }),
      ),
    );
    const assign = vi.spyOn(browserNavigation, 'assign').mockImplementation(() => undefined);

    const screen = await renderTree();

    await expect.element(screen.getByTestId('status')).toHaveTextContent('anonymous');
    await vi.waitFor(() => {
      expect(assign).toHaveBeenCalledTimes(1);
    });
    const target = assign.mock.calls[0]?.[0] as string;
    expect(target.startsWith('/login?next=')).toBe(true);
    // Encoded, because the value is a path plus a query string and both have to survive being a
    // parameter. The login page runs it back through `safeNext` regardless.
    expect(target).toBe(
      `/login?next=${encodeURIComponent(`${window.location.pathname}${window.location.search}`)}`,
    );
  });
});
