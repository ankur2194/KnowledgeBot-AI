import { http, HttpResponse } from 'msw';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Providers } from '@/components/providers';
import { LoginForm } from '@/features/auth/login-form';
import { browserNavigation } from '@/features/auth/session';

import { envelope, ORIGIN, sessionFixture } from '../msw/handlers';
import { worker } from '../msw/setup';

/**
 * The login form.
 *
 * NAVIGATION IS ASSERTED AS A CALL AND NEVER FOLLOWED, through the one seam in
 * `src/features/auth/session.ts`. `window.location.assign` cannot be stubbed: in Chromium it is an OWN
 * property of the location object with `configurable: false, writable: false`, so
 * `vi.spyOn(window.location, 'assign')` throws `TypeError: Cannot redefine property` — measured in this
 * harness — and a spec that reached for it would navigate the test page away instead of observing
 * anything. Per the vitest-playwright boundary table a component spec may not assert cross-route
 * navigation at all; what it may assert is that the code asked for one.
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

const OPERATOR_DETAIL = 'operator detail from api-7.internal';

const renderForm = (next = '/sources') =>
  render(
    <Providers>
      <LoginForm next={next} />
    </Providers>,
  );

async function signIn(screen: Awaited<ReturnType<typeof renderForm>>): Promise<void> {
  await screen.getByLabelText('Email').fill('ada@example.test');
  await screen.getByLabelText('Password').fill('correct horse battery');
  await screen.getByRole('button', { name: 'Sign in' }).click();
}

describe('the happy path posts once and asks for a FULL DOCUMENT navigation', () => {
  it('POSTs {email, password} to /api/v1/auth/login exactly once', async () => {
    const bodies: unknown[] = [];
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/login`, async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ data: sessionFixture() });
      }),
    );
    const assign = vi.spyOn(browserNavigation, 'assign').mockImplementation(() => undefined);

    const screen = await renderForm('/sources');
    await signIn(screen);

    await vi.waitFor(() => {
      expect(assign).toHaveBeenCalledTimes(1);
    });
    // The RESOLVER'S OUTPUT — trimmed, parsed, and with no `remember` field (decision D4) and no
    // ownership column. `z.strictObject` is what makes an extra key a test failure rather than a
    // silently stripped one.
    expect(bodies).toEqual([{ email: 'ada@example.test', password: 'correct horse battery' }]);
  });

  it('assigns the sanitized `next`, and does NOT invalidate or reset any cache', async () => {
    const assign = vi.spyOn(browserNavigation, 'assign').mockImplementation(() => undefined);

    const screen = await renderForm('/bots?page=2');
    await signIn(screen);

    await vi.waitFor(() => {
      // ONE call, with the destination and nothing else. A full document navigation destroys the
      // QueryClient and the Router Cache in one step, so a second mechanism for a cache that is about
      // to cease existing would be noise that reads as a rule.
      expect(assign).toHaveBeenCalledExactlyOnceWith('/bots?page=2');
    });
  });

  it('sends the URL-DECODED XSRF-TOKEN as X-XSRF-TOKEN', async () => {
    // Laravel percent-encodes the base64 padding. Echoing `%3D` verbatim 419s every mutation while the
    // cookie is perfectly valid, and it reproduces nowhere because a token needing no padding works.
    document.cookie = 'XSRF-TOKEN=eyJpdiI6InQ%3D%3D';
    const headers: (string | null)[] = [];
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/login`, ({ request }) => {
        headers.push(request.headers.get('X-XSRF-TOKEN'));
        return HttpResponse.json({ data: sessionFixture() });
      }),
    );
    vi.spyOn(browserNavigation, 'assign').mockImplementation(() => undefined);

    const screen = await renderForm();
    await signIn(screen);

    await vi.waitFor(() => {
      expect(headers).toEqual(['eyJpdiI6InQ==']);
    });
  });
});

describe('the double-click guard is the disabled attribute, not a retry', () => {
  it('disables submit while the mutation is pending', async () => {
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/login`, async () => {
        await new Promise((resolve) => setTimeout(resolve, 300));
        return HttpResponse.json({ data: sessionFixture() });
      }),
    );
    const assign = vi.spyOn(browserNavigation, 'assign').mockImplementation(() => undefined);

    const screen = await renderForm();
    await signIn(screen);

    // A mutation with no Idempotency-Key must never be retried by anything, a user double-click
    // included — and login is not replayable at all.
    await expect.element(screen.getByRole('button', { name: 'Signing in…' })).toBeDisabled();

    // THE DELAYED RESPONSE IS CONSUMED INSIDE THE SPEC THAT ASKED FOR IT, and that is not tidiness.
    // A mutation's callbacks fire whether or not the component is still mounted, so a spec that ends
    // while the 300 ms handler is in flight leaks `onSuccess` into the NEXT spec — where `afterEach`
    // has already restored the seam, so `browserNavigation.assign` is the real
    // `window.location.assign` and the harness NAVIGATES AWAY mid-run. Observed once as a phantom
    // "/sources" call arriving in an unrelated 422 spec; the failure a step later would have been the
    // whole suite dying with no useful name.
    await vi.waitFor(() => {
      expect(assign).toHaveBeenCalledExactlyOnceWith('/sources');
    });
  });
});

describe('bad credentials are a 422 on the email field, never a session-expiry bounce', () => {
  it('renders the server message under Email and leaves the banner empty', async () => {
    // Decision D7: bad credentials arrive as 422 `validation` with the message on `email`. Mapping this
    // through `authentication` would render "Your session has ended. Sign in again to continue.",
    // which is nonsense on a first sign-in attempt.
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/login`, () =>
        HttpResponse.json(
          envelope('validation', {
            errors: { email: ['These credentials do not match our records.'] },
          }),
          { status: 422 },
        ),
      ),
    );
    const assign = vi.spyOn(browserNavigation, 'assign').mockImplementation(() => undefined);

    const screen = await renderForm();
    await signIn(screen);

    // Validation MESSAGES are end-user copy — Laravel translates them — so they are shown verbatim.
    await expect
      .element(screen.getByText('These credentials do not match our records.'))
      .toBeVisible();
    await expect.element(screen.getByLabelText('Email')).toHaveAttribute('aria-invalid', 'true');
    // Nothing navigates: this is not an expired session.
    expect(assign).not.toHaveBeenCalled();
    // And the operator-facing `message` is never rendered on any path.
    expect(document.body.textContent).not.toContain(OPERATOR_DETAIL);
    expect(document.body.textContent).not.toContain('api-7.internal');
  });

  it('routes a key the form does not render to the banner instead of a hidden field', async () => {
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/login`, () =>
        HttpResponse.json(
          envelope('validation', { errors: { token: ['This link is no longer valid.'] } }),
          { status: 422 },
        ),
      ),
    );

    const screen = await renderForm();
    await signIn(screen);

    // `token` is not in this form's knownPaths, and that subtraction is the point: a hidden input has
    // no focusable ref, so `setError('token')` writes to a field that displays nowhere and the user
    // submits into silence.
    await expect
      .element(screen.getByRole('alert'))
      .toHaveTextContent('This link is no longer valid.');
  });
});

describe('a 429 disables submit for the header window and never retries', () => {
  it('renders the class-mapped sentence and a countdown from Retry-After', async () => {
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/login`, () =>
        HttpResponse.json(envelope('rate_limit', { retryable: true }), {
          status: 429,
          // SECONDS, off the RESPONSE HEADER — `retry_after` is not in the JSON envelope. A 429 without
          // this header degrades the cooldown to nothing, silently.
          headers: { 'Retry-After': '30' },
        }),
      ),
    );

    const screen = await renderForm();
    await signIn(screen);

    await expect
      .element(screen.getByRole('alert'))
      .toHaveTextContent('Too many requests just now. Wait a moment and try again.');
    // The one identifier a user is ever shown, because it is the only one support can grep across both
    // services.
    await expect.element(screen.getByRole('alert')).toHaveTextContent('(ref 01JREQFROMLARAVEL)');
    await expect.element(screen.getByText('Try again in 30s.')).toBeVisible();
    await expect.element(screen.getByRole('button', { name: 'Sign in' })).toBeDisabled();
  });
});

describe('a response with no envelope renders "Something went wrong." and invents no class', () => {
  it('shows the unknown copy with no (ref) when the header is absent too', async () => {
    worker.use(
      http.post(
        `${ORIGIN}/api/v1/auth/login`,
        () => new HttpResponse('<html>502 Bad Gateway</html>', { status: 502 }),
      ),
    );

    const screen = await renderForm();
    await signIn(screen);

    await expect.element(screen.getByRole('alert')).toHaveTextContent('Something went wrong.');
    expect(document.body.textContent).not.toContain('(ref');
    expect(document.body.textContent).not.toContain('502 Bad Gateway');
  });
});

describe('the CSRF pre-check fails BEFORE the POST, where the typed input still exists', () => {
  it('reports the misconfiguration without rendering its operator detail', async () => {
    // The realistic failure: the cookie is blocked or SESSION_DOMAIN / sanctum.stateful is wrong. The
    // unconditional refreshCsrfToken() turns that into a diagnosable error before the request instead
    // of an opaque 419 after it — which is what makes the 419-on-login branch near-unreachable.
    document.cookie = 'XSRF-TOKEN=; max-age=0';
    const posted: string[] = [];
    worker.use(
      http.post(`${ORIGIN}/api/v1/auth/login`, () => {
        posted.push('posted');
        return HttpResponse.json({ data: sessionFixture() });
      }),
    );

    const screen = await renderForm();
    await signIn(screen);

    await expect.element(screen.getByRole('alert')).toHaveTextContent('Something went wrong.');
    // No POST was made at all, which is the whole value of the pre-check.
    expect(posted).toEqual([]);
    // The message names SESSION_DOMAIN and sanctum.stateful. It is operator-facing and is logged, never
    // rendered.
    expect(document.body.textContent).not.toContain('SESSION_DOMAIN');
  });
});
