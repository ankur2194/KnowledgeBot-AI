import { http, HttpResponse } from 'msw';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Providers } from '@/components/providers';
import { VerifyEmailNotice } from '@/features/auth/verify-email-notice';

import { envelope, ORIGIN } from '../msw/handlers';
import { worker } from '../msw/setup';
import { ERROR_COPY } from '@/lib/forms/apply-server-errors';

/**
 * The email-verification screen — the link target and the notice/resend screen, which are one
 * component told apart by a single primitive.
 *
 * A component spec may not assert navigation, authentication or isolation (the vitest-playwright
 * boundary table), and nothing here is cited as coverage for any of those. What it CAN assert is the
 * rendered consequence of each response, how many requests were made, and — the one thing only a real
 * browser can check — that the token left the address bar.
 */

/** The seed EVERY spec against these handlers needs. Both endpoints are POSTs, so both build a
 *  `sessionCredential()`; without the cookie that silently takes the `refreshCsrfToken()` path, the
 *  handler answers 204 with no cookie a cross-origin worker can set, and the code under test throws
 *  the "XSRF-TOKEN cookie absent" KbError — a failure with nothing to do with the assertion. */
let originalHref = '';

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-token';
  originalHref = window.location.href;
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  // The URL is restored because one spec below deliberately puts a token in it. Leaving a rewritten
  // location behind would make the NEXT spec's `stripTokenFromUrl()` a non-no-op.
  window.history.replaceState(window.history.state, '', originalHref);
  vi.restoreAllMocks();
});

const OPERATOR_HOST = 'api-7.internal';

const renderNotice = (token: string | null) =>
  render(
    <Providers>
      <VerifyEmailNotice token={token} />
    </Providers>,
  );

describe('a token in the URL is consumed once, on mount', () => {
  it('POSTs {token} to /api/v1/auth/email/verify exactly once and renders the success state', async () => {
    const bodies: unknown[] = [];
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/email/verify`, async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ data: { acknowledged: true } });
      }),
    );

    const screen = await renderNotice('tok_abcdef');

    await expect
      .element(screen.getByRole('heading', { name: 'Your email address is confirmed' }))
      .toBeVisible();
    // ONCE. StrictMode invokes effects twice in development and refs survive that simulated remount,
    // which is what the `started` ref is for. Success is idempotent server-side, so a second POST
    // would not break anything visible — it would just be a second request against a throttled
    // endpoint, which is exactly the kind of defect no rendered assertion can see.
    expect(bodies).toEqual([{ token: 'tok_abcdef' }]);
  });

  it('strips the token from the address bar for the same page load', async () => {
    // An email can only carry a URL, so the token IS in the address bar on arrival. It must not stay
    // there: not in session history, not restorable by Back, not readable out of location.search by
    // anything that runs later. The pathname is preserved on purpose — rewriting it would move the
    // Vitest harness page out from under the run.
    const path = window.location.pathname;
    window.history.replaceState(window.history.state, '', `${path}?token=tok_inurl&keep=1`);

    const screen = await renderNotice('tok_inurl');

    await expect
      .element(screen.getByRole('heading', { name: 'Your email address is confirmed' }))
      .toBeVisible();
    expect(new URL(window.location.href).searchParams.has('token')).toBe(false);
    // Only `token` is removed. A blanket `search = ''` would drop whatever else a link carries.
    expect(new URL(window.location.href).searchParams.get('keep')).toBe('1');
  });

  it('renders no resend control on the link path', async () => {
    const screen = await renderNotice('tok_abcdef');

    await expect
      .element(screen.getByRole('heading', { name: 'Your email address is confirmed' }))
      .toBeVisible();
    // Resend is AUTHENTICATED and this route is a guest route: the link is very often opened in a
    // different browser from the one that registered. A button that 401s for precisely the users who
    // most need it is worse than a link to sign in.
    expect(screen.getByRole('button', { name: 'Send a new link' }).elements()).toHaveLength(0);
  });
});

describe('the single failure state claims nothing the server did not say', () => {
  it('never says the link expired, because expiry is one of four indistinguishable answers', async () => {
    // Unknown token, expired token, already-consumed token, and a token issued for a different
    // address are ONE 404 with ONE body, deliberately. Copy that names any single cause is a lie in
    // three cases out of four.
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/email/verify`, () =>
        HttpResponse.json(envelope('authorization'), { status: 404 }),
      ),
    );

    const screen = await renderNotice('tok_stale');

    await expect
      .element(screen.getByRole('heading', { name: 'We could not confirm your email address' }))
      .toBeVisible();

    const body = document.body.textContent ?? '';
    // The words a well-meaning edit would reach for first. `expire` covers expired/expires/expiry.
    expect(body.toLowerCase()).not.toContain('expire');
    expect(body.toLowerCase()).not.toContain('has run out');
    // What it DOES say: all four, as possibilities, with the one remedy that fixes every one.
    expect(body).toContain('may never have been valid');
    expect(body).toContain('may already have been used');
    expect(body).toContain('issued for a different address');
    // And never the envelope's operator-facing `message`.
    expect(body).not.toContain(OPERATOR_HOST);
  });

  it('overrides only the authorization sentence, and keeps the request_id', async () => {
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/email/verify`, () =>
        HttpResponse.json(envelope('authorization'), { status: 404 }),
      ),
    );

    const screen = await renderNotice('tok_stale');

    // THE OVERRIDE, ASSERTED BY WHAT IT REPLACES. An unusable link answers `authorization`/404 — the
    // taxonomy-correct class, because the capability addresses nothing the caller may act on — but
    // ERROR_COPY.authorization reads "You do not have access to this.", which tells someone who clicked
    // a link in their own inbox that they lack a permission, and contradicts the paragraph rendered
    // beneath the banner. `copyFor` on this one screen replaces that sentence and nothing else.
    await expect
      .element(screen.getByRole('alert'))
      .toHaveTextContent('This confirmation link cannot be used.');
    // The negative half is the point: if someone deletes the override, this is what goes red.
    await expect.element(screen.getByRole('alert')).not.toHaveTextContent('do not have access');
    // The one identifier a user is ever shown, because it is the only one support can grep across
    // both services. The override must not drop it.
    await expect.element(screen.getByRole('alert')).toHaveTextContent('(ref 01JREQFROMLARAVEL)');
  });

  it('renders the unknown-class copy with no invented class when no envelope parsed', async () => {
    worker.use(
      http.post(
        `${ORIGIN}/api/v1/auth/email/verify`,
        () => new HttpResponse('<html>502 Bad Gateway</html>', { status: 502 }),
      ),
    );

    const screen = await renderNotice('tok_abcdef');

    await expect.element(screen.getByRole('alert')).toHaveTextContent(ERROR_COPY.unknown);
    expect(document.body.textContent).not.toContain('(ref');
    expect(document.body.textContent).not.toContain('502 Bad Gateway');
  });
});

describe('with no token this is the notice screen, and resend is its only action', () => {
  it('POSTs an EMPTY body to the resend endpoint exactly once per click', async () => {
    const requests: { body: string; contentType: string | null }[] = [];
    worker.use(
      http.post(
        `${ORIGIN}/api/v1/auth/email/verification-notification`,
        async ({ request }) => {
          requests.push({
            body: await request.text(),
            contentType: request.headers.get('Content-Type'),
          });
          return HttpResponse.json({ data: { acknowledged: true } });
        },
      ),
    );

    const screen = await renderNotice(null);
    await screen.getByRole('button', { name: 'Send a new link' }).click();

    await vi.waitFor(() => {
      expect(requests).toHaveLength(1);
    });
    // EMPTY body, and therefore no Content-Type either. The endpoint derives the address from the
    // session: it has no FormRequest, no manifest and no Zod schema, and inventing an `{email}` field
    // would turn an authenticated no-argument action into an unauthenticated mail-sending oracle.
    expect(requests[0]?.body).toBe('');
    expect(requests[0]?.contentType).toBeNull();
  });

  it('confirms with an inline aria-live="polite" region rather than a toast', async () => {
    const screen = await renderNotice(null);
    await screen.getByRole('button', { name: 'Send a new link' }).click();

    // role="status", not the <Alert> default of role="alert": `alert` is implicitly assertive and
    // interrupts, and a mail being on its way is not an interruption.
    const confirmation = screen.getByRole('status');
    await expect.element(confirmation).toBeVisible();
    await expect.element(confirmation).toHaveAttribute('aria-live', 'polite');
    await expect.element(confirmation).toHaveTextContent('A new link is on its way');
    // INLINE, never a toast: a toast disappears, and this surface can carry a `request_id` a user
    // needs to copy for support.
    expect(confirmation.elements()[0]?.closest('[data-slot="alert"]')).not.toBeNull();
  });

  it('explains that a refusal cannot name this as its reason', async () => {
    // The whole point of the screen. Laravel's render closure rewrites every `authorization` message
    // to one constant string, so a `verified`-gated 403 is byte-identical to a role failure.
    const screen = await renderNotice(null);

    await expect
      .element(screen.getByRole('heading', { name: 'Confirm your email address' }))
      .toBeVisible();
    expect(document.body.textContent).toContain('cannot tell you that this is the reason');
  });

  it('disables the button while the resend is in flight', async () => {
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/email/verification-notification`, async () => {
        await new Promise((resolve) => setTimeout(resolve, 300));
        return HttpResponse.json({ data: { acknowledged: true } });
      }),
    );

    const screen = await renderNotice(null);
    await screen.getByRole('button', { name: 'Send a new link' }).click();

    // There is no `Idempotency-Key` on this request, so nothing may replay it — a second POST is a
    // second email. The guard is this attribute, not a retry.
    await expect.element(screen.getByRole('button', { name: 'Sending…' })).toBeDisabled();

    // THE DELAYED RESPONSE IS CONSUMED INSIDE THE SPEC THAT ASKED FOR IT. A mutation's callbacks fire
    // whether or not the component is still mounted, so a spec that ends while the 300 ms handler is
    // in flight leaks `onSuccess` into the NEXT spec, where it lands inside an unrelated assertion.
    await expect.element(screen.getByRole('status')).toBeVisible();
  });
});

describe('a 429 disables the button for the header window and never retries', () => {
  it('renders the class-mapped sentence and a countdown from Retry-After', async () => {
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/email/verification-notification`, () =>
        HttpResponse.json(envelope('rate_limit', { retryable: true }), {
          status: 429,
          // SECONDS, off the RESPONSE HEADER — `retry_after` is not in the JSON envelope. A 429
          // without this header degrades the cooldown to nothing, silently.
          headers: { 'Retry-After': '45' },
        }),
      ),
    );

    const screen = await renderNotice(null);
    await screen.getByRole('button', { name: 'Send a new link' }).click();

    await expect
      .element(screen.getByRole('alert'))
      .toHaveTextContent('Too many requests just now. Wait a moment and try again.');
    await expect.element(screen.getByText('Try again in 45s.')).toBeVisible();
    // A COOLDOWN, NOT A RETRY: the client never reattempts on the user's behalf. `retry:` is an
    // ESLint error outside src/lib/query/client.ts, and mutations are `retry: false` globally there.
    await expect.element(screen.getByRole('button', { name: 'Send a new link' })).toBeDisabled();
    expect(document.body.textContent).not.toContain(OPERATOR_HOST);
  });
});
