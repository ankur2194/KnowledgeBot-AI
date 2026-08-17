import { http, HttpResponse } from 'msw';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Providers } from '@/components/providers';
import { ForgotPasswordForm } from '@/features/auth/forgot-password-form';

import { envelope, ORIGIN } from '../msw/handlers';
import { worker } from '../msw/setup';

/**
 * The forgot-password form.
 *
 * ── THE ONE ASSERTION THIS FILE EXISTS FOR ───────────────────────────────────────────────────────
 *
 * `POST /api/v1/auth/forgot-password` answers 200 with a BYTE-IDENTICAL body for every outcome — link
 * sent, address unknown, broker-throttled — because a distinguishable answer is an account-enumeration
 * oracle (return 429 on the throttle and 200 on the unknown address, and probing twice proves the first
 * probe created a token, which proves the account exists). The server half of that is Laravel's and is
 * tested there. THE CLIENT HALF IS ONLY CATCHABLE HERE: a well-meaning "we've sent an email to
 * ada@example.test" re-opens the oracle in the browser, over an identical response, and no server test
 * can see it.
 *
 * So the confirmation is captured as a STRING for a known and an unknown address and the two are
 * compared. That comparison is only meaningful because the copy interpolates nothing — the day somebody
 * personalises the sentence, this goes red rather than shipping.
 */

/** The seed EVERY spec against these handlers needs. Without it the form takes the
 *  `refreshCsrfToken()` path, the handler answers 204 with no cookie a cross-origin worker can set,
 *  and the code under test throws the "XSRF-TOKEN cookie absent" KbError — a failure with nothing to do
 *  with what the spec is about. */
beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

/** Spelled out rather than imported from the component. Copy this load-bearing has to be changed
 *  deliberately, in two places, by somebody who reads why. */
const CONFIRMATION =
  'If that address belongs to an account, a password reset link is on its way. Check your inbox and your spam folder.';

const KNOWN_ADDRESS = 'ada@example.test';
const UNKNOWN_ADDRESS = 'nobody-at-all@example.test';
const OPERATOR_DETAIL = 'api-7.internal';

const renderForm = (email = '') =>
  render(
    <Providers>
      <ForgotPasswordForm email={email} />
    </Providers>,
  );

/**
 * Submit one address and return the rendered confirmation, unmounting afterwards.
 *
 * The unmount is not tidiness: `render`'s locators are scoped to `baseElement`, which defaults to
 * `document.body`, so a second tree mounted beside the first makes `getByRole('status')` match two
 * elements and fail on ambiguity rather than on the thing being asserted.
 */
async function confirmationFor(address: string): Promise<string> {
  const screen = await renderForm();
  await screen.getByLabelText('Email').fill(address);
  await screen.getByRole('button', { name: 'Email a reset link' }).click();

  await expect.element(screen.getByRole('status')).toBeVisible();
  const rendered = screen.getByRole('status').element().textContent ?? '';

  await screen.unmount();
  return rendered;
}

describe('the confirmation is byte-identical for a known and an unknown address', () => {
  it('renders the same string for both, and never names the address', async () => {
    const posted: string[] = [];
    worker.use(
      // ONE handler for both calls, answering the SAME bytes. There is deliberately no "unknown
      // address" branch to install: the fixture cannot distinguish the cases because the server does
      // not, and a spec that mocked two different bodies would be testing a server that does not exist.
      http.post(`${ORIGIN}/api/v1/auth/forgot-password`, async ({ request }) => {
        const body = (await request.json()) as { email?: string };
        posted.push(body.email ?? '');
        return HttpResponse.json({ data: { acknowledged: true } });
      }),
    );

    const known = await confirmationFor(KNOWN_ADDRESS);
    const unknown = await confirmationFor(UNKNOWN_ADDRESS);

    // Both addresses really were sent, so both branches of the server's behaviour were exercised.
    expect(posted).toEqual([KNOWN_ADDRESS, UNKNOWN_ADDRESS]);

    // THE ASSERTION. Not "both are non-empty", not "both contain 'reset link'" — identical.
    expect(unknown).toBe(known);
    expect(known).toBe(CONFIRMATION);

    // The two failure shapes that would make the strings differ, named so a regression reads clearly.
    expect(known).not.toContain(KNOWN_ADDRESS);
    expect(unknown).not.toContain(UNKNOWN_ADDRESS);
  });

  it('phrases the confirmation conditionally, asserting nothing about the account', () => {
    // "We've sent you an email" claims the account exists. "If that address belongs to an account"
    // claims nothing, which is the only honest sentence available over an indistinguishable response.
    expect(CONFIRMATION.startsWith('If that address belongs to an account')).toBe(true);
    expect(CONFIRMATION).not.toMatch(/\bwe(?:'ve| have) sent you\b/i);
    expect(CONFIRMATION).not.toMatch(/\b(?:no|not) (?:such )?account\b/i);
  });
});

describe('the confirmation is an inline live region, not a navigation', () => {
  it('is announced politely and appears only after the 200', async () => {
    const screen = await renderForm();

    // Nothing before the request: no confirmation shown against no answer.
    expect(screen.container.querySelector('[role="status"]')).toBeNull();

    await screen.getByLabelText('Email').fill(KNOWN_ADDRESS);
    await screen.getByRole('button', { name: 'Email a reset link' }).click();

    // polite, not assertive: nothing is wrong and the user must not be interrupted mid-keystroke. And
    // `status` rather than `alert` so this element and the ERROR banner stay distinguishable by role —
    // <Alert/> hard-codes role="alert", and two of those makes every error assertion ambiguous.
    await expect.element(screen.getByRole('status')).toHaveAttribute('aria-live', 'polite');
    // There is nowhere to navigate to — the next step is in the user's mailbox — so the form stays
    // mounted and the address stays editable.
    await expect.element(screen.getByLabelText('Email')).toHaveValue(KNOWN_ADDRESS);
  });
});

describe('client-side validation is a UX affordance and blocks the request', () => {
  it('does not POST when the address is not an email', async () => {
    const posted: string[] = [];
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/forgot-password`, () => {
        posted.push('posted');
        return HttpResponse.json({ data: { acknowledged: true } });
      }),
    );

    const screen = await renderForm();
    await screen.getByLabelText('Email').fill('not-an-address');
    await screen.getByRole('button', { name: 'Email a reset link' }).click();

    await expect.element(screen.getByText('Enter a valid email address.')).toBeVisible();
    // The FormRequest checks the same rule again and is the authority; this only saves a round trip.
    expect(posted).toEqual([]);
    expect(screen.container.querySelector('[role="status"]')).toBeNull();
  });
});

describe('a 429 disables submit for the header window and never retries', () => {
  it('renders the class-mapped sentence and a countdown, with no confirmation', async () => {
    // Laravel's own throttle middleware, which is a DIFFERENT thing from the password broker's
    // throttle — the latter is folded into the identical 200 precisely so it cannot be probed.
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/forgot-password`, () =>
        HttpResponse.json(envelope('rate_limit', { retryable: true }), {
          status: 429,
          // SECONDS, off the RESPONSE HEADER — `retry_after` is not in the JSON envelope. A 429 without
          // this header degrades the cooldown to nothing, silently.
          headers: { 'Retry-After': '45' },
        }),
      ),
    );

    const screen = await renderForm(KNOWN_ADDRESS);
    await screen.getByRole('button', { name: 'Email a reset link' }).click();

    await expect
      .element(screen.getByRole('alert'))
      .toHaveTextContent('Too many requests just now. Wait a moment and try again.');
    // The one identifier a user is ever shown, because it is the only one support can grep across both
    // services.
    await expect.element(screen.getByRole('alert')).toHaveTextContent('(ref 01JREQFROMLARAVEL)');
    await expect.element(screen.getByText('Try again in 45s.')).toBeVisible();
    await expect.element(screen.getByRole('button', { name: 'Email a reset link' })).toBeDisabled();

    // No confirmation on a failure: it must never claim a link was sent when the request was refused.
    expect(screen.container.querySelector('[role="status"]')).toBeNull();
    // The envelope's `message` is operator-facing and is never rendered on any path.
    expect(document.body.textContent).not.toContain(OPERATOR_DETAIL);
  });
});

describe('a response with no envelope renders "Something went wrong." and invents no class', () => {
  it('shows the unknown copy and no confirmation', async () => {
    worker.use(
      http.post(
        `${ORIGIN}/api/v1/auth/forgot-password`,
        () => new HttpResponse('<html>502 Bad Gateway</html>', { status: 502 }),
      ),
    );

    const screen = await renderForm(KNOWN_ADDRESS);
    await screen.getByRole('button', { name: 'Email a reset link' }).click();

    await expect.element(screen.getByRole('alert')).toHaveTextContent('Something went wrong.');
    expect(document.body.textContent).not.toContain('(ref');
    expect(document.body.textContent).not.toContain('502 Bad Gateway');
    expect(screen.container.querySelector('[role="status"]')).toBeNull();
  });
});

describe('the double-click guard is the disabled attribute, not a retry', () => {
  it('disables submit while the mutation is pending', async () => {
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/forgot-password`, async () => {
        await new Promise((resolve) => setTimeout(resolve, 300));
        return HttpResponse.json({ data: { acknowledged: true } });
      }),
    );

    const screen = await renderForm(KNOWN_ADDRESS);
    await screen.getByRole('button', { name: 'Email a reset link' }).click();

    await expect.element(screen.getByRole('button', { name: 'Sending…' })).toBeDisabled();

    // THE DELAYED RESPONSE IS CONSUMED INSIDE THE SPEC THAT ASKED FOR IT. A mutation's callbacks fire
    // whether or not the component is still mounted, so a spec that ends while the 300 ms handler is in
    // flight leaks its `onSettled` into the NEXT spec, where `afterEach` has already restored every
    // stub — observed once as a phantom navigation arriving inside an unrelated assertion.
    await expect.element(screen.getByRole('status')).toHaveTextContent(CONFIRMATION);
  });
});
