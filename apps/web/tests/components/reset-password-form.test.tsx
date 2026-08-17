import { http, HttpResponse } from 'msw';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest';

import { Providers } from '@/components/providers';
import { ResetPasswordForm } from '@/features/auth/reset-password-form';
import { browserNavigation } from '@/features/auth/session';

import { envelope, ORIGIN } from '../msw/handlers';
import { worker } from '../msw/setup';

/**
 * The reset-password form.
 *
 * NAVIGATION IS ASSERTED AS A CALL AND NEVER FOLLOWED, through the one seam in
 * `src/features/auth/session.ts`. `window.location.replace` — like `assign` — is `[LegacyUnforgeable]`:
 * in Chromium it is an OWN property of the location object with `configurable: false, writable: false`,
 * so `vi.spyOn(window.location, 'replace')` throws `TypeError: Cannot redefine property` and a spec
 * reaching for it NAVIGATES THE HARNESS AWAY instead of observing anything. Per the vitest-playwright
 * boundary table a component spec may not assert cross-route navigation at all; what it may assert is
 * that the code asked for one, and which of the two verbs it asked with — which on this screen is a
 * security property rather than a preference.
 */

/**
 * BOTH SEAM METHODS ARE STUBBED FOR EVERY SPEC IN THIS FILE, NOT PER SPEC, AND THAT IS A HARNESS
 * SAFETY PROPERTY RATHER THAN CONVENIENCE.
 *
 * A spec that expects a 422 or a 429 has no reason to stub a navigation it does not expect — until
 * something answers 200 anyway, `onSuccess` fires, and the UNSTUBBED seam calls the real
 * `window.location.replace('/login')`, which navigates the whole Vitest iframe away. The run then dies
 * with "Cannot connect to the iframe … Received URL: …", which names neither the spec nor the cause and
 * takes the rest of the FILE with it. Two ways that 200 arrives: a mutation left in flight by an earlier
 * spec settling after `afterEach` restored the stubs, and Browser Mode running several spec files in
 * parallel iframes against ONE shared MSW service-worker registration, where a request can be answered
 * by the default handler set rather than by this iframe's `worker.use(...)` override.
 *
 * Stubbing unconditionally makes the seam unreachable from this file, so the worst either failure can do
 * is fail an assertion — which is what a test is for.
 */
let replace: MockInstance<typeof browserNavigation.replace>;
let assign: MockInstance<typeof browserNavigation.assign>;

beforeEach(() => {
  // The seed EVERY spec against these handlers needs; see tests/msw/handlers.ts.
  document.cookie = 'XSRF-TOKEN=test-token';
  replace = vi.spyOn(browserNavigation, 'replace').mockImplementation(() => undefined);
  assign = vi.spyOn(browserNavigation, 'assign').mockImplementation(() => undefined);
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

const TOKEN = 'reset-token-0123456789abcdef';
const EMAIL = 'ada@example.test';
/** Satisfies the whole mirrored policy: >= 12 characters, upper, lower, digit. */
const STRONG = 'Correct-Horse-9';
const OPERATOR_DETAIL = 'api-7.internal';

const renderForm = (token = TOKEN, email = EMAIL) =>
  render(
    <Providers>
      <ResetPasswordForm token={token} email={email} />
    </Providers>,
  );

/**
 * `{ exact: true }` IS REQUIRED, and the failure without it is worth naming: `getByLabelText` matches
 * a SUBSTRING, so "New password" also matches "Confirm new password" and the locator resolves to two
 * elements. Strict mode then fails with "resolved to 2 elements" on the FILL, several lines before any
 * assertion — a failure that reads like a broken component rather than a loose selector.
 */
const newPassword = (screen: Awaited<ReturnType<typeof renderForm>>) =>
  screen.getByLabelText('New password', { exact: true });

async function fillPasswords(
  screen: Awaited<ReturnType<typeof renderForm>>,
  password: string,
  confirmation: string,
): Promise<void> {
  await newPassword(screen).fill(password);
  await screen.getByLabelText('Confirm new password').fill(confirmation);
  await screen.getByRole('button', { name: 'Set new password' }).click();
}

describe('a mismatched confirmation is blocked client-side, on the second field', () => {
  it('renders the mismatch under Confirm new password and sends nothing', async () => {
    const posted: string[] = [];
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/reset-password`, () => {
        posted.push('posted');
        return HttpResponse.json({ data: { acknowledged: true } });
      }),
    );

    const screen = await renderForm();
    await fillPasswords(screen, STRONG, `${STRONG}-typo`);

    // The schema's superRefine mirrors Laravel's `confirmed` and raises the issue on
    // `password_confirmation`, never on `password`: the field the user must fix is the second one, and
    // RHF focuses the field the error is keyed to.
    await expect.element(screen.getByText('The two passwords do not match.')).toBeVisible();
    await expect
      .element(screen.getByLabelText('Confirm new password'))
      .toHaveAttribute('aria-invalid', 'true');
    await expect.element(newPassword(screen)).not.toHaveAttribute('aria-invalid', 'true');

    // Client validation is a UX affordance and never a control — but it does save the round trip, and
    // `ResetPasswordRequest`'s `confirmed` rule checks the same thing again and is the authority.
    expect(posted).toEqual([]);
  });

  it('states the password policy before the request rather than after a 422', async () => {
    const screen = await renderForm();

    // All four constraints are mirrored from
    // ['min:12','regex:/\p{Ll}/u','regex:/\p{Lu}/u','regex:/\d/u'], so the user is told the rule
    // rather than shown a server rejection for one they never saw.
    await expect
      .element(
        screen.getByText(
          'At least 12 characters, with an upper-case letter, a lower-case letter and a digit.',
        ),
      )
      .toBeVisible();

    await fillPasswords(screen, 'alllowercase99', 'alllowercase99');
    await expect.element(screen.getByText('Include an upper-case letter.')).toBeVisible();
  });
});

describe('the collapsed 422 on `token` lands on the banner, never on the hidden input', () => {
  it('renders the message once, in role=alert, with the hidden field untouched', async () => {
    // Laravel collapses invalid, expired and already-consumed tokens AND an unknown user into ONE 422
    // keyed on `token` — four distinguishable answers would be an enumeration oracle. The message is a
    // VALIDATION message, so it is Laravel-translated end-user copy and is shown verbatim.
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/reset-password`, () =>
        HttpResponse.json(
          envelope('validation', {
            errors: { token: ['This password reset link is no longer valid.'] },
          }),
          { status: 422 },
        ),
      ),
    );

    const screen = await renderForm();
    await fillPasswords(screen, STRONG, STRONG);

    await expect
      .element(screen.getByRole('alert'))
      .toHaveTextContent('This password reset link is no longer valid.');

    // THE ASSERTION THIS SPEC EXISTS FOR. `token` is not in the form's knownPaths, so
    // `applyServerErrors` treats it as an orphan and writes it to the single `root.serverError` slot. If
    // it were in that set, `setError('token')` would attach the only message the user needs to a hidden
    // input that has no focusable ref and renders nothing — they submit, the server rejects, the screen
    // does not change, and they submit again.
    const hidden = screen.container.querySelector('input[name="token"]');
    expect(hidden).not.toBeNull();
    expect(hidden?.getAttribute('type')).toBe('hidden');
    expect(hidden?.getAttribute('aria-invalid')).toBeNull();

    // Nothing navigates on a failed reset: the user has to be able to read the banner.
    expect(replace).not.toHaveBeenCalled();
    expect(document.body.textContent).not.toContain(OPERATOR_DETAIL);
  });
});

describe('success asks for a REPLACE to /login, not an assign, and does not sign the user in', () => {
  it('calls the navigation seam exactly once with /login', async () => {
    const screen = await renderForm();
    await fillPasswords(screen, STRONG, STRONG);

    await vi.waitFor(() => {
      // `/login`, because Laravel answers an acknowledgement rather than a session: Sanctum's
      // AuthenticateSession compares a password hash per request, so a session minted here would be
      // invalidated by the very change that created it.
      expect(replace).toHaveBeenCalledExactlyOnceWith('/login');
    });
    // `assign` would PUSH past the entry holding the token, leaving one Back press between the user and
    // a consumed capability in the address bar.
    expect(assign).not.toHaveBeenCalled();
  });

  it('POSTs the four schema fields, with the token from the hidden input', async () => {
    const bodies: unknown[] = [];
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/reset-password`, async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ data: { acknowledged: true } });
      }),
    );

    const screen = await renderForm();
    await fillPasswords(screen, STRONG, STRONG);

    await vi.waitFor(() => {
      expect(replace).toHaveBeenCalledTimes(1);
    });
    // The RESOLVER'S OUTPUT: exactly the schema's four paths, no ownership column, and the passwords
    // UNTRIMMED — Laravel's TrimStrings excepts them, so trimming here would store a different secret
    // than the one typed.
    expect(bodies).toEqual([
      {
        token: TOKEN,
        email: EMAIL,
        password: STRONG,
        password_confirmation: STRONG,
      },
    ]);
  });
});

describe('the token is stripped from the address bar on mount', () => {
  it('removes ?token= while keeping the value the form submits', async () => {
    // The pathname is deliberately left alone: rewriting it in Browser Mode would re-base every
    // subsequent dynamic import and asset request against a route the harness does not serve.
    const original = `${window.location.pathname}${window.location.search}`;
    window.history.replaceState(
      null,
      '',
      `${window.location.pathname}?token=${TOKEN}&email=${encodeURIComponent(EMAIL)}`,
    );

    try {
      const bodies: unknown[] = [];
      worker.use(
        http.post(`${ORIGIN}/api/v1/auth/reset-password`, async ({ request }) => {
          bodies.push(await request.json());
          return HttpResponse.json({ data: { acknowledged: true } });
        }),
      );

      const screen = await renderForm();

      await vi.waitFor(() => {
        expect(new URL(window.location.href).searchParams.has('token')).toBe(false);
      });
      // `email` stays: it is not a capability, and holding it grants nothing without the token.
      expect(new URL(window.location.href).searchParams.get('email')).toBe(EMAIL);

      // AND THE STRIP DOES NOT BREAK THE REQUEST, which is the half that would fail silently. The value
      // submitted comes from the prop that arrived before the strip — React state, not the address bar.
      await fillPasswords(screen, STRONG, STRONG);
      await vi.waitFor(() => {
        expect(replace).toHaveBeenCalledTimes(1);
      });
      expect(bodies).toEqual([
        { token: TOKEN, email: EMAIL, password: STRONG, password_confirmation: STRONG },
      ]);
    } finally {
      window.history.replaceState(null, '', original);
    }
  });
});

describe('the email field is read-only, and read-only is not disabled', () => {
  it('renders the linked address as readOnly so its key still submits', async () => {
    const screen = await renderForm();

    // The broker pairs the address with the token, so the only thing an edit can do is turn a working
    // link into "no longer valid".
    await expect.element(screen.getByLabelText('Email')).toHaveValue(EMAIL);
    await expect.element(screen.getByLabelText('Email')).toHaveAttribute('readonly');
    // `disabled` would be the bug: RHF strips a disabled field's name from the submitted values, so
    // `email` would never reach the server and the FormRequest would 422 it as `required` — a rule the
    // user can neither see nor satisfy.
    await expect.element(screen.getByLabelText('Email')).not.toBeDisabled();
  });
});

describe('a client-side error on the hidden token still reaches the user', () => {
  it('shows the incomplete-link message in the banner rather than nowhere', async () => {
    // The page renders an incomplete-link panel instead of this form when `?token=` is absent, so this
    // is the belt-and-braces path: a resolver error keyed to a HIDDEN input has no FormMessage to render
    // it, and without this the user would submit into complete silence.
    const posted: string[] = [];
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/reset-password`, () => {
        posted.push('posted');
        return HttpResponse.json({ data: { acknowledged: true } });
      }),
    );

    const screen = await renderForm('');
    await fillPasswords(screen, STRONG, STRONG);

    await expect
      .element(screen.getByRole('alert'))
      .toHaveTextContent('This reset link is incomplete.');
    expect(posted).toEqual([]);
  });
});

describe('a 429 disables submit for the header window and never retries', () => {
  it('renders the class-mapped sentence and a countdown', async () => {
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/reset-password`, () =>
        HttpResponse.json(envelope('rate_limit', { retryable: true }), {
          status: 429,
          headers: { 'Retry-After': '20' },
        }),
      ),
    );

    const screen = await renderForm();
    await fillPasswords(screen, STRONG, STRONG);

    await expect
      .element(screen.getByRole('alert'))
      .toHaveTextContent('Too many requests just now. Wait a moment and try again.');
    await expect.element(screen.getByText('Try again in 20s.')).toBeVisible();
    await expect.element(screen.getByRole('button', { name: 'Set new password' })).toBeDisabled();
  });
});

describe('the double-click guard is the disabled attribute, not a retry', () => {
  it('disables submit while the mutation is pending', async () => {
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/reset-password`, async () => {
        await new Promise((resolve) => setTimeout(resolve, 300));
        return HttpResponse.json({ data: { acknowledged: true } });
      }),
    );

    const screen = await renderForm();
    await fillPasswords(screen, STRONG, STRONG);

    await expect.element(screen.getByRole('button', { name: 'Saving…' })).toBeDisabled();

    // THE DELAYED RESPONSE IS CONSUMED INSIDE THE SPEC THAT ASKED FOR IT: a mutation's callbacks fire
    // whether or not the component is mounted, so leaving this in flight would land `onSuccess` in the
    // NEXT spec, after `afterEach` restored the seam — and the real `window.location.replace` navigates
    // the harness away.
    await vi.waitFor(() => {
      expect(replace).toHaveBeenCalledExactlyOnceWith('/login');
    });
  });
});
