import { KbError } from '@kb/contracts';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { ErrorPanel } from '@/components/error-panel';

/**
 * THE FIRST SPEC IN `tests/components/`, which is what let `passWithNoTests: true` be deleted from
 * vitest.config.ts. It also exercises the MSW worker start in tests/msw/setup.ts end-to-end, so a
 * broken harness fails here rather than in the first spec that actually needs a handler.
 *
 * `<ErrorPanel/>` is the body of BOTH error boundaries — `(admin)/error.tsx` and `(auth)/error.tsx`
 * are three-line wrappers over it — so the two rules below are asserted once for both surfaces:
 * branch on `error_class` and never on a status, and never render the envelope's `message`.
 *
 * A component test may not assert isolation, navigation or authentication (the vitest-playwright
 * boundary table). Nothing here is cited as coverage for any of those.
 */

const OPERATOR_DETAIL = 'no route matched on api-7.internal';

let consoleError: ReturnType<typeof vi.spyOn>;

beforeEach(() => {
  // The panel logs the operator-facing detail on mount, deliberately. Silenced so a passing run is
  // quiet, and spied so the "log it, never render it" half can be asserted rather than assumed.
  consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe('the copy is mapped from error_class, never from the envelope message', () => {
  it('renders the class-mapped sentence plus the request_id', async () => {
    const error = new KbError('rate_limit', true, 30, '01JREQFROMLARAVEL', OPERATOR_DETAIL);

    const screen = await render(
      <ErrorPanel surface="admin" error={error} reset={() => undefined} />,
    );

    await expect
      .element(screen.getByRole('alert'))
      .toHaveTextContent('Too many requests just now. Wait a moment and try again.');
    // The one identifier a user is ever shown, because it is the only one support can grep across
    // both services.
    await expect.element(screen.getByRole('alert')).toHaveTextContent('(ref 01JREQFROMLARAVEL)');
  });

  it('NEVER renders the operator-facing message, on any class', async () => {
    // That field can carry an internal hostname, raw text from an upstream provider, or an
    // identifier that has no business in a tenant's UI. It is the string a support engineer greps
    // for, not one anybody wrote for a reader.
    const error = new KbError('provider_temporary', true, null, '01JREQ', OPERATOR_DETAIL);

    await render(<ErrorPanel surface="auth" error={error} reset={() => undefined} />);

    expect(document.body.textContent).not.toContain('api-7.internal');
    expect(document.body.textContent).not.toContain(OPERATOR_DETAIL);
    // Asserted as a call BEFORE the redaction check would be meaningful: a panel that logs nothing
    // would "pass" the assertion above by having no operator detail anywhere at all.
    expect(consoleError).toHaveBeenCalledTimes(1);
    expect(consoleError.mock.calls[0]?.[0]).toBe('[kb] error boundary');
    expect(consoleError.mock.calls[0]?.[1]).toMatchObject({
      surface: 'auth',
      error_class: 'provider_temporary',
      request_id: '01JREQ',
    });
  });

  it('renders "Something went wrong." with no ref for an error carrying no envelope', async () => {
    // error_class null means no envelope parsed — unknown, and unknown is permanently
    // non-retryable. Never invent a class name to fill the slot.
    const screen = await render(
      <ErrorPanel surface="admin" error={new Error('boom')} reset={() => undefined} />,
    );

    await expect.element(screen.getByRole('alert')).toHaveTextContent('Something went wrong.');
    expect(document.body.textContent).not.toContain('(ref');
    expect(document.body.textContent).not.toContain('boom');
  });
});

describe('the Retry affordance is gated on `retryable`, not on the copy', () => {
  it('offers Try again for a retryable error and calls reset', async () => {
    const reset = vi.fn();
    const screen = await render(
      <ErrorPanel
        surface="admin"
        error={new KbError('internal_dependency', true, null, null, OPERATOR_DETAIL)}
        reset={reset}
      />,
    );

    await screen.getByRole('button', { name: 'Try again' }).click();
    expect(reset).toHaveBeenCalledTimes(1);
  });

  it('offers NOTHING for a non-retryable error whose copy still says "shortly"', async () => {
    // `internal_dependency` is one sentence covering two sub-cases: 503/retryable for a real
    // dependency brownout and 500/not-retryable for an unmapped exception in our own code. The axis
    // separating them is deliberately not on the wire, so the copy cannot be the gate — the
    // envelope's `retryable` is.
    const screen = await render(
      <ErrorPanel
        surface="admin"
        error={new KbError('internal_dependency', false, null, null, OPERATOR_DETAIL)}
        reset={() => undefined}
      />,
    );

    await expect.element(screen.getByRole('alert')).toHaveTextContent('Try again shortly.');
    expect(screen.getByRole('button', { name: 'Try again' }).elements()).toHaveLength(0);
  });

  it('offers nothing for a plain Error', async () => {
    const screen = await render(
      <ErrorPanel surface="auth" error={new Error('boom')} reset={() => undefined} />,
    );

    await expect.element(screen.getByRole('alert')).toBeVisible();
    expect(screen.getByRole('button', { name: 'Try again' }).elements()).toHaveLength(0);
  });
});
