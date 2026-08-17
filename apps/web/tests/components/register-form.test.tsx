import { http, HttpResponse } from 'msw';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Providers } from '@/components/providers';
import { RegisterForm } from '@/features/auth/register-form';
import { browserNavigation } from '@/features/auth/session';

import { envelope, invitationFixture, ORIGIN, sessionFixture } from '../msw/handlers';
import { worker } from '../msw/setup';

/**
 * Invitation-gated registration.
 *
 * ── WHAT THIS SPEC MAY AND MAY NOT CLAIM ─────────────────────────────────────────────────────────
 * It proves RENDERING, the request bodies, and the 422 ROUTING — which is the one property in this flow
 * that is observable nowhere else (see the `token` describe block below). It proves nothing about
 * navigation between routes, nothing about tenancy, and nothing about whether the token really leaves
 * the browser's history: those are Playwright's, per the vitest-playwright boundary table.
 *
 * NAVIGATION IS ASSERTED AS A CALL AND NEVER FOLLOWED, through the one seam in
 * `src/features/auth/session.ts`. `window.location.replace` is `[LegacyUnforgeable]` — in Chromium an OWN
 * property with `configurable: false, writable: false` — so `vi.spyOn(window.location, 'replace')` throws
 * `TypeError: Cannot redefine property` and a spec reaching for it NAVIGATES THE HARNESS AWAY instead of
 * observing anything. EVERY spec below in which the POST succeeds stubs the seam, for that reason and not
 * for tidiness.
 */

// THERE IS DELIBERATELY NO `next/navigation` MOCK IN THIS FILE, unlike org-switcher.test.tsx. Nothing in
// this subtree touches a router: the sign-in affordance is a PLAIN ANCHOR (argued in register-form.tsx —
// a full document load is what discards the heap holding the token) and the post-registration navigation
// goes through the `browserNavigation` seam. `useRouter()` throws outside a mounted App Router, so the
// absence of that mock is also the assertion that nothing here reaches for one.

/** The seed EVERY spec against these handlers needs. Without it the code under test takes the
 *  `refreshCsrfToken()` path, the handler answers 204 with no cookie a cross-origin worker can set, and a
 *  "XSRF-TOKEN cookie absent" KbError is thrown — a failure with nothing to do with what the spec is
 *  about. This flow needs it twice over: the PREVIEW is a POST too, so it also carries `X-XSRF-TOKEN`. */
beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

const TOKEN = 'f'.repeat(64);
const PASSWORD = 'Correcthorse9battery';
const OPERATOR_DETAIL = 'api-7.internal';

const renderForm = (token = TOKEN) =>
  render(
    <Providers>
      <RegisterForm token={token} />
    </Providers>,
  );

async function fillAndSubmit(
  screen: Awaited<ReturnType<typeof renderForm>>,
  confirmation = PASSWORD,
): Promise<void> {
  await screen.getByLabelText('Your name').fill('Grace Hopper');
  // `exact: true`, because Playwright's label matching is a case-insensitive SUBSTRING by default and
  // 'Password' would also match 'Confirm password' — two elements, strict-mode violation, and a failure
  // that names the locator rather than the behaviour.
  await screen.getByLabelText('Password', { exact: true }).fill(PASSWORD);
  await screen.getByLabelText('Confirm password').fill(confirmation);
  await screen.getByRole('button', { name: 'Create account' }).click();
}

describe('the preview is the gate: it renders the invitation before it renders a password field', () => {
  it('shows the organization, the invited address and the role', async () => {
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/invitations/preview`, () =>
        HttpResponse.json({
          data: invitationFixture({
            organization_name: 'Brightwater Legal',
            email: 'newcomer@example.test',
            role: 'knowledge_manager',
          }),
        }),
      ),
    );

    const screen = await renderForm();

    await expect.element(screen.getByText('Brightwater Legal')).toBeVisible();
    // The address is DISPLAYED and never submitted — `registerSchema` has no `email` field, so an invitee
    // cannot register under somebody else's. Showing it is what lets them notice the mismatch.
    await expect.element(screen.getByText('newcomer@example.test')).toBeVisible();
    // The LABEL, not the wire value. A raw `knowledge_manager` in a summary is the symptom of a
    // `Record<Role, string>` lookup that missed a member.
    await expect.element(screen.getByText('Knowledge manager')).toBeVisible();
  });

  it('POSTs the token in a BODY, never in a path segment', async () => {
    const requests: { url: string; body: unknown }[] = [];
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/invitations/preview`, async ({ request }) => {
        requests.push({ url: request.url, body: await request.json() });
        return HttpResponse.json({ data: invitationFixture() });
      }),
    );

    const screen = await renderForm();
    await expect.element(screen.getByText('Acme Research')).toBeVisible();

    expect(requests).toHaveLength(1);
    expect(requests[0]?.body).toEqual({ token: TOKEN });
    // A capability in a URL lands in access logs, in `Referer` and in history. The URL must not contain it.
    expect(requests[0]?.url).not.toContain(TOKEN);
  });

  it('refuses ALL FIVE invalid states with ONE sentence and no form at all', async () => {
    // Unknown, expired, accepted, revoked, and a pending invitation into a suspended organization all
    // answer the same 404 with the same body — the states are indistinguishable on purpose, because
    // telling a prober "expired" confirms the token was once real.
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/invitations/preview`, () =>
        HttpResponse.json(envelope('authorization'), { status: 404 }),
      ),
    );

    const screen = await renderForm();

    await expect
      .element(screen.getByRole('alert'))
      .toHaveTextContent('This invitation is no longer valid.');
    // No password field on a page whose token is dead: a control that can only fail invites the guest to
    // use it twice before reading.
    expect(screen.getByLabelText('Password', { exact: true }).elements()).toHaveLength(0);
    // The envelope's `message` is operator-facing and is never rendered on any path.
    expect(document.body.textContent).not.toContain(OPERATOR_DETAIL);
  });

  it('does NOT report a transport fault as a dead invitation', async () => {
    // The branch is on `error_class`, never on the status. A 503 must not tell somebody their invitation
    // is gone — that costs them the invitation for a reason that was ours.
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/invitations/preview`, () =>
        HttpResponse.json(envelope('internal_dependency', { retryable: false }), { status: 500 }),
      ),
    );

    const screen = await renderForm();

    await expect
      .element(screen.getByRole('alert'))
      .toHaveTextContent('Something on our side is unavailable. Try again shortly.');
    expect(document.body.textContent).not.toContain('This invitation is no longer valid.');
  });

  it('makes NO request at all when the link carried no token', async () => {
    const previews: string[] = [];
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/invitations/preview`, () => {
        previews.push('previewed');
        return HttpResponse.json({ data: invitationFixture() });
      }),
    );

    const screen = await renderForm('');

    await expect
      .element(screen.getByRole('alert'))
      .toHaveTextContent('This invitation link is incomplete.');
    // `throttle:invitation` is keyed on the TOKEN, so a request here would spend a budget on a value the
    // screen already knows is unusable.
    expect(previews).toEqual([]);
  });
});

describe('the token submits from the hidden field, and the body carries no email', () => {
  it('POSTs {token, name, password, password_confirmation} and asks for a full document navigation', async () => {
    const bodies: unknown[] = [];
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/register`, async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ data: sessionFixture() }, { status: 201 });
      }),
    );
    const replace = vi.spyOn(browserNavigation, 'replace').mockImplementation(() => undefined);

    const screen = await renderForm();
    await expect.element(screen.getByText('Acme Research')).toBeVisible();
    await fillAndSubmit(screen);

    await vi.waitFor(() => {
      expect(bodies).toEqual([
        {
          token: TOKEN,
          name: 'Grace Hopper',
          password: PASSWORD,
          password_confirmation: PASSWORD,
        },
      ]);
    });
    // NO `email` KEY. The invitation pins the address; a submitted one would let an invitee register under
    // somebody else's. `z.strictObject` is what makes an extra key a test failure rather than a silently
    // stripped one.
    expect(bodies[0]).not.toHaveProperty('email');

    // `replace`, not `assign`: the history entry being overwritten is the one that arrived holding
    // `?token=…`. Asserted as a CALL and never followed.
    await vi.waitFor(() => {
      expect(replace).toHaveBeenCalledExactlyOnceWith('/');
    });
  });

  it('renders the token in a hidden input rather than a typeable one', async () => {
    const screen = await renderForm();
    await expect.element(screen.getByText('Acme Research')).toBeVisible();

    const hidden = document.querySelector<HTMLInputElement>('input[type="hidden"][name="token"]');
    expect(hidden?.value).toBe(TOKEN);
  });
});

describe('an invalid-token 422 lands on the BANNER and not on the hidden input', () => {
  it('is the §7.3 property, and the component layer is the only place it is observable', async () => {
    // `token` IS a schema path — the drift suite asserts set equality against the dumped manifest — and it
    // is deliberately absent from REGISTER_KNOWN_PATHS. A hidden input has no focusable ref, so
    // `setError('token')` would write to a control that displays nowhere: the guest submits, the server
    // refuses, nothing changes on screen, and they submit again. `applyServerErrors` routes unknown keys to
    // ONE `root.serverError` write, which is what puts the sentence where it can be read.
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/register`, () =>
        HttpResponse.json(
          envelope('validation', { errors: { token: ['This invitation is no longer valid.'] } }),
          { status: 422 },
        ),
      ),
    );
    const replace = vi.spyOn(browserNavigation, 'replace').mockImplementation(() => undefined);

    const screen = await renderForm();
    await expect.element(screen.getByText('Acme Research')).toBeVisible();
    await fillAndSubmit(screen);

    // In the banner, verbatim: validation messages are Laravel-translated end-user copy.
    await expect
      .element(screen.getByRole('alert'))
      .toHaveTextContent('This invitation is no longer valid.');
    // EXACTLY ONCE in the document. Had the key been routed to `setError('token')` there would be no root
    // write and therefore no banner at all; a second occurrence would mean it was written to both.
    expect(document.body.textContent?.match(/This invitation is no longer valid\./g)).toHaveLength(1);
    // The hidden field is untouched — still carrying the token, still not rendering anything.
    const hidden = document.querySelector<HTMLInputElement>('input[type="hidden"][name="token"]');
    expect(hidden?.value).toBe(TOKEN);
    expect(replace).not.toHaveBeenCalled();
    expect(document.body.textContent).not.toContain(OPERATOR_DETAIL);
  });

  it('routes the existing-account disclosure to the banner too, because there is no email field', async () => {
    // The ONE deliberate disclosure: the caller has already proven possession of the token, so telling them
    // an account exists for the invitation's own address discloses nothing they cannot already read. It is
    // keyed `email` — a path this form does not render at all — so it lands in the same banner, beside the
    // sign-in instruction the screen renders permanently.
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/register`, () =>
        HttpResponse.json(
          envelope('validation', {
            errors: {
              email: ['An account already exists for this address. Sign in to accept the invitation.'],
            },
          }),
          { status: 422 },
        ),
      ),
    );
    vi.spyOn(browserNavigation, 'replace').mockImplementation(() => undefined);

    const screen = await renderForm();
    await expect.element(screen.getByText('Acme Research')).toBeVisible();
    await fillAndSubmit(screen);

    await expect
      .element(screen.getByRole('alert'))
      .toHaveTextContent('An account already exists for this address.');
    await expect.element(screen.getByRole('link', { name: 'Sign in' })).toBeVisible();
  });
});

describe('the token leaves the address bar on mount', () => {
  it('rewrites the history entry in place and keeps every other parameter', async () => {
    // An email can only carry a URL, so the capability is in the address bar for exactly one load. This is
    // the layer that stops that from becoming a permanent history entry; `browserNavigation.replace` on
    // success is the second. What is NOT asserted here — that Back really cannot recover it — is
    // Playwright's: this layer may not assert history navigation at all.
    const original = `${window.location.pathname}${window.location.search}`;
    try {
      // `window.history.state` is passed back UNCHANGED, here and in the hook: the App Router keeps its
      // routing state in that object, and replacing it with `null` breaks subsequent navigation with
      // nothing logged.
      window.history.replaceState(
        window.history.state,
        '',
        `${window.location.pathname}?token=${TOKEN}&keep=1`,
      );

      const screen = await renderForm();
      await expect.element(screen.getByText('Acme Research')).toBeVisible();

      await vi.waitFor(() => {
        expect(new URLSearchParams(window.location.search).has('token')).toBe(false);
      });
      // Re-serialized from `URL` rather than string-sliced, which is why a second parameter survives and a
      // trailing `?` does not.
      expect(new URLSearchParams(window.location.search).get('keep')).toBe('1');
      // The token is still in the component's props — stripping the URL is not the same as losing the
      // value, and the form still submits it from its hidden field.
      const hidden = document.querySelector<HTMLInputElement>('input[type="hidden"][name="token"]');
      expect(hidden?.value).toBe(TOKEN);
    } finally {
      window.history.replaceState(window.history.state, '', original);
    }
  });
});

describe('the mirrored password policy fails before the request, not after it', () => {
  it('raises the mismatch on password_confirmation and posts nothing', async () => {
    const posted: string[] = [];
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/register`, () => {
        posted.push('posted');
        return HttpResponse.json({ data: sessionFixture() }, { status: 201 });
      }),
    );

    const screen = await renderForm();
    await expect.element(screen.getByText('Acme Research')).toBeVisible();
    await fillAndSubmit(screen, 'Correcthorse9batteryX');

    // The issue is raised on the SECOND field, never on `password`: the field the user must fix is the one
    // react-hook-form will focus.
    await expect.element(screen.getByText('The two passwords do not match.')).toBeVisible();
    expect(posted).toEqual([]);
  });

  it('states the whole policy up front rather than letting the server refuse a hidden rule', async () => {
    const screen = await renderForm();

    // Mirrors `newPasswordField()` — min 12 plus three character classes. A form that mirrors only `min:12`
    // accepts "aaaaaaaaaaaa" and the server refuses a rule the user was never shown.
    await expect
      .element(
        screen.getByText(
          'At least 12 characters, with an upper-case letter, a lower-case letter and a digit.',
        ),
      )
      .toBeVisible();
  });
});
