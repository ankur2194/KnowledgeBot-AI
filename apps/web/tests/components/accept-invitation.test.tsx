import { http, HttpResponse } from 'msw';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Providers } from '@/components/providers';
import { AcceptInvitation } from '@/features/auth/accept-invitation';
import { browserNavigation } from '@/features/auth/session';

import { envelope, invitationFixture, ORIGIN, sessionFixture } from '../msw/handlers';
import { worker } from '../msw/setup';

/**
 * `/invitations/accept?token=…` — ONE ROUTE, TWO FLOWS, chosen by an identity probe.
 *
 * The property this file exists to pin: the screen decides between ACCEPTING (authenticated) and
 * REGISTERING (guest) from `GET /api/v1/me`, and it does not fold a transport failure into either. An
 * `unavailable` session treated as `anonymous` would offer an account-creation form to somebody who
 * already has an account, because `/me` was briefly unreachable.
 *
 * `<SessionProvider>` is NOT mounted here, and that is not an omission in the spec — it is not mounted in
 * `(auth)/layout.tsx` either, so `useSession()` would throw and the component reads the identity query
 * directly through the same `sessionQueryOptions()` and the same pure `toSessionState()` machine.
 *
 * NAVIGATION IS ASSERTED AS A CALL AND NEVER FOLLOWED. `window.location.replace` is `[LegacyUnforgeable]`
 * — an OWN property with `configurable: false, writable: false` — so a spec that tried to spy on it would
 * throw `TypeError: Cannot redefine property` and NAVIGATE THE HARNESS AWAY. Every spec whose POST
 * succeeds stubs the `browserNavigation` seam for that reason. Per the vitest-playwright boundary table
 * this layer may not assert cross-route navigation at all; what it may assert is that the code asked.
 */

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

const TOKEN = 'a'.repeat(64);
/** The address `sessionFixture()`'s user holds, so the invitation and the identity agree. */
const ADA = 'ada@example.test';

const renderScreen = (token = TOKEN) =>
  render(
    <Providers>
      <AcceptInvitation token={token} />
    </Providers>,
  );

/** The invitation is pinned to the SIGNED-IN user's own address, which is the normal accept path. */
const previewForAda = () =>
  worker.use(
    http.post(`${ORIGIN}/api/v1/auth/invitations/preview`, () =>
      HttpResponse.json({ data: invitationFixture({ email: ADA, role: 'admin' }) }),
    ),
  );

describe('signed in: it accepts, posting the token in a body', () => {
  it('POSTs {token} and asks for a full document navigation with replace', async () => {
    previewForAda();
    const requests: { url: string; body: unknown }[] = [];
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/invitations/accept`, async ({ request }) => {
        requests.push({ url: request.url, body: await request.json() });
        return HttpResponse.json({ data: sessionFixture() });
      }),
    );
    const replace = vi.spyOn(browserNavigation, 'replace').mockImplementation(() => undefined);

    const screen = await renderScreen();

    // The summary renders before any control: organization, invited address, role. `exact: true` on the
    // organization name, because Playwright's text matching is a case-insensitive SUBSTRING by default and
    // the Join button's own label contains the same name — two elements, a strict-mode violation, and a
    // failure that names the locator rather than the behaviour.
    await expect.element(screen.getByText('Acme Research', { exact: true })).toBeVisible();
    await expect.element(screen.getByText('Admin')).toBeVisible();
    // TWICE, on purpose, and asserted as a count rather than as a single element: once in the summary as
    // the address the invitation is PINNED to, and once in "you are signed in as". Rendering both is the
    // whole mechanism by which a mismatch is visible before the click.
    expect(screen.getByText(ADA).elements()).toHaveLength(2);

    await screen.getByRole('button', { name: 'Join Acme Research' }).click();

    await vi.waitFor(() => {
      expect(requests).toHaveLength(1);
    });
    expect(requests[0]?.body).toEqual({ token: TOKEN });
    // A capability in a URL lands in access logs, in `Referer` and in history.
    expect(requests[0]?.url).not.toContain(TOKEN);

    // `replace`, not `assign`: the entry being overwritten arrived holding `?token=…`.
    await vi.waitFor(() => {
      expect(replace).toHaveBeenCalledExactlyOnceWith('/');
    });
  });

  it('renders the 422 verbatim, because a button has no field to key an error to', async () => {
    // The server collapses invalid token, address mismatch and "already a member" into ONE 422 on `token`
    // — indistinguishable by design. `token` is a path no form renders, and there is no form here at all,
    // so `actionErrorCopy` shows Laravel's translated message rather than `ERROR_COPY.validation`
    // ("Some details need fixing before this can be saved."), which would be nonsense beside a Join
    // button.
    previewForAda();
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/invitations/accept`, () =>
        HttpResponse.json(
          envelope('validation', { errors: { token: ['This invitation is no longer valid.'] } }),
          { status: 422 },
        ),
      ),
    );
    const replace = vi.spyOn(browserNavigation, 'replace').mockImplementation(() => undefined);

    const screen = await renderScreen();
    await screen.getByRole('button', { name: 'Join Acme Research' }).click();

    await expect
      .element(screen.getByRole('alert'))
      .toHaveTextContent('This invitation is no longer valid.');
    expect(replace).not.toHaveBeenCalled();
    // The envelope's `message` is operator-facing and is never rendered.
    expect(document.body.textContent).not.toContain('api-7.internal');
  });
});

describe('signed in as somebody else: it warns and still lets the server decide', () => {
  it('names both addresses and leaves the button enabled', async () => {
    // The default fixtures already disagree — the invitation is for `newcomer@example.test` and the session
    // is Ada's — which is the case an invitation forwarded to a colleague produces.
    const screen = await renderScreen();

    await expect
      .element(screen.getByText('This invitation is for a different address'))
      .toBeVisible();
    // NOT disabled. Client-side blocking would be enforcement-by-UI, and the server is the authority on
    // whether this token and this identity go together.
    await expect.element(screen.getByRole('button', { name: 'Join Acme Research' })).toBeEnabled();
    // The way out of the dead end: the existing sign-out control, imported rather than re-implemented.
    await expect.element(screen.getByRole('button', { name: 'Sign out' })).toBeVisible();
  });
});

describe('signed out: the same route becomes registration', () => {
  it('renders the register form when /me answers `authentication`', async () => {
    previewForAda();
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () =>
        HttpResponse.json(envelope('authentication'), { status: 401 }),
      ),
    );

    const screen = await renderScreen();

    // Registration IS the acceptance for somebody with no account: one transaction server-side creates the
    // user, the membership and the audit row, so there is no two-step to get half-way through.
    await expect.element(screen.getByLabelText('Your name')).toBeVisible();
    await expect.element(screen.getByRole('button', { name: 'Create account' })).toBeVisible();
    // No Join button: there is no session to accept with.
    expect(screen.getByRole('button', { name: /^Join/ }).elements()).toHaveLength(0);
  });

  it('re-uses the preview already in the cache instead of asking twice', async () => {
    // Both this component and the <RegisterForm/> it delegates to read the same
    // `['invitation-preview', token]` key. `throttle:invitation` is keyed on the TOKEN, so a second POST
    // would spend the guest's own budget on an answer already in memory.
    let previews = 0;
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/invitations/preview`, () => {
        previews += 1;
        return HttpResponse.json({ data: invitationFixture({ email: ADA }) });
      }),
      http.get(`${ORIGIN}/api/v1/me`, () =>
        HttpResponse.json(envelope('authentication'), { status: 401 }),
      ),
    );

    const screen = await renderScreen();
    await expect.element(screen.getByRole('button', { name: 'Create account' })).toBeVisible();

    expect(previews).toBe(1);
  });
});

describe('an unreachable /me is NOT anonymity', () => {
  it('says the account could not be loaded rather than offering a sign-up form', async () => {
    // Folding `unavailable` into `anonymous` would offer account creation to somebody who already has an
    // account, and the class is the axis: `anonymous` means Laravel said `authentication` and nothing else.
    previewForAda();
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () =>
        HttpResponse.json(envelope('internal_dependency', { retryable: false }), { status: 500 }),
      ),
    );

    const screen = await renderScreen();

    await expect.element(screen.getByText('Your account could not be loaded')).toBeVisible();
    expect(screen.getByLabelText('Your name').elements()).toHaveLength(0);
    expect(screen.getByRole('button', { name: /^Join/ }).elements()).toHaveLength(0);
  });
});

describe('a dead token refuses before either branch is chosen', () => {
  it('shows one sentence for all five invalid states and no controls', async () => {
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/invitations/preview`, () =>
        HttpResponse.json(envelope('authorization'), { status: 404 }),
      ),
    );

    const screen = await renderScreen();

    await expect
      .element(screen.getByRole('alert'))
      .toHaveTextContent('This invitation is no longer valid.');
    expect(screen.getByRole('button', { name: /^Join/ }).elements()).toHaveLength(0);
    expect(screen.getByLabelText('Your name').elements()).toHaveLength(0);
  });

  it('makes no request at all when the link carried no token', async () => {
    const previews: string[] = [];
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/invitations/preview`, () => {
        previews.push('previewed');
        return HttpResponse.json({ data: invitationFixture() });
      }),
    );

    const screen = await renderScreen('');

    await expect
      .element(screen.getByRole('alert'))
      .toHaveTextContent('This invitation link is incomplete.');
    expect(previews).toEqual([]);
  });
});
