import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useCooldown } from '@/features/auth/use-cooldown';

/**
 * `useCooldown` — a 429's `Retry-After` rendered as a countdown that disables submit, and NOT a retry.
 *
 * ── EVERY COUNTDOWN ASSERTION IS AN ANCHORED REGEX, AND IT HAS TO BE ─────────────────────────────
 * `toHaveTextContent('5')` matches by SUBSTRING, so it is satisfied by "59" — and 59 is precisely what
 * the broken implementation renders. Written with a string, the deadline spec below passed against a
 * hook mutated to `setRemaining((n) => n - 1)`, which is the one implementation it exists to reject.
 * Measured, not theorised: the mutation was applied and only the sibling `never goes negative` spec
 * caught it. `/^5$/` is what makes the claim the claim.
 *
 * ── WHY THIS IS A COMPONENT SPEC AND NOT A UNIT ONE ──────────────────────────────────────────────
 * It is a hook with a `useEffect` and an interval, so it needs a React renderer and a real event loop.
 * The `unit` project is `environment: 'node'` with no JSX transform; there is nowhere else for it.
 *
 * ── WHY THE CLOCK IS NOT FAKED ───────────────────────────────────────────────────────────────────
 * `vi.useFakeTimers()` replaces `setInterval` AND `Date.now`, which are the two halves of the one
 * behaviour under test — the hook recomputes from a DEADLINE rather than decrementing a counter, so a
 * fake clock that moves both together cannot tell the correct implementation from the broken one. It
 * would also freeze the retry loop `expect.element` polls on.
 *
 * So the real interval runs (one tick, ~1s of wall clock, in a serialized project) and `Date.now` alone
 * is moved — which is exactly the situation the deadline exists for: a backgrounded tab whose timers
 * were throttled while the wall clock kept going.
 */

beforeEach(() => {
  // Uniform with every other component spec even though this hook issues no request.
  document.cookie = 'XSRF-TOKEN=test-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

/** The seconds each button starts a cooldown with; one probe covers every case. */
const BUTTONS = [
  ['null', null],
  ['zero', 0],
  ['negative', -5],
  ['nan', Number.NaN],
  ['infinite', Number.POSITIVE_INFINITY],
  ['fractional', 1.2],
  ['thirty', 30],
  ['over-cap', 7_200],
  ['one', 1],
  ['sixty', 60],
] as const;

function Probe() {
  const { remaining, start } = useCooldown();

  return (
    <div>
      <p data-testid="remaining">{remaining}</p>
      {/* Gate on `> 0`, exactly as every consuming form does. */}
      <button type="submit" disabled={remaining > 0}>
        Send
      </button>
      {BUTTONS.map(([label, seconds]) => (
        <button key={label} type="button" onClick={() => start(seconds)}>
          {label}
        </button>
      ))}
    </div>
  );
}

const renderProbe = () => render(<Probe />);

describe('start() refuses to invent a window', () => {
  it.each([
    ['null — a 429 with no Retry-After header degrades to no cooldown', 'null'],
    ['zero', 'zero'],
    ['a negative value', 'negative'],
    ['NaN', 'nan'],
    ['Infinity', 'infinite'],
  ])('is a no-op for %s', async (_label, button) => {
    // Guessing a window means submitting inside the one we were told to wait, which keeps the limiter
    // rejecting and looks to the user like the form is broken. `Number.isFinite` is what rejects the
    // last two: `Date.now() + Infinity` is Infinity, and `Math.ceil(Infinity)` never counts down.
    const screen = await renderProbe();

    await screen.getByRole('button', { name: button }).click();

    await expect.element(screen.getByTestId('remaining')).toHaveTextContent(/^0$/);
    await expect.element(screen.getByRole('button', { name: 'Send' })).toBeEnabled();
  });
});

describe('start() with a real window', () => {
  it('shows the seconds immediately and disables submit', async () => {
    // Immediately, not after the first tick: a form that waits a second before disabling submit lets
    // the user fire a second request into the window they were just told to wait out.
    const screen = await renderProbe();

    await screen.getByRole('button', { name: 'thirty' }).click();

    await expect.element(screen.getByTestId('remaining')).toHaveTextContent(/^30$/);
    await expect.element(screen.getByRole('button', { name: 'Send' })).toBeDisabled();
  });

  it('rounds a fractional header UP', async () => {
    // `Retry-After: 1.2` truncated to 1 would re-enable submit inside the window. Ceil, always.
    const screen = await renderProbe();

    await screen.getByRole('button', { name: 'fractional' }).click();

    await expect.element(screen.getByTestId('remaining')).toHaveTextContent(/^2$/);
  });

  it('caps at one hour however the header was computed', async () => {
    // A misconfigured limiter answering `Retry-After: 7200` would otherwise lock the form for two
    // hours with no way out but a reload.
    const screen = await renderProbe();

    await screen.getByRole('button', { name: 'over-cap' }).click();

    await expect.element(screen.getByTestId('remaining')).toHaveTextContent(/^3600$/);
  });

  it('counts down to zero and re-enables submit', async () => {
    const screen = await renderProbe();

    await screen.getByRole('button', { name: 'one' }).click();
    await expect.element(screen.getByTestId('remaining')).toHaveTextContent(/^1$/);

    // The retrying assertion is the point: what is claimed is "it reaches zero", not "it is exactly N
    // after N wall-clock milliseconds", which is the shape that flakes on a loaded runner.
    await expect
      .element(screen.getByTestId('remaining'), { timeout: 4_000 })
      .toHaveTextContent(/^0$/);
    await expect.element(screen.getByRole('button', { name: 'Send' })).toBeEnabled();
  });
});

describe('the deadline is the source of truth, not the count', () => {
  it('resynchronises after the tab was throttled instead of drifting', async () => {
    // THE ASSERTION THE `deadline` REF EXISTS FOR, and the only one that can tell the two
    // implementations apart. A hook that did `setRemaining((n) => n - 1)` would read 59 here — one
    // tick's worth — and would keep the form disabled for the full 60 seconds of *foreground* time,
    // which on a backgrounded tab is minutes of wall clock. Reading the deadline gives 5.
    const screen = await renderProbe();

    await screen.getByRole('button', { name: 'sixty' }).click();
    await expect.element(screen.getByTestId('remaining')).toHaveTextContent(/^60$/);

    // Only the wall clock moves. The interval is real and fires on its own schedule, exactly as it
    // does when a browser un-throttles a background tab.
    const realNow = Date.now();
    vi.spyOn(Date, 'now').mockImplementation(() => realNow + 55_000);

    await expect.element(screen.getByTestId('remaining'), { timeout: 4_000 }).toHaveTextContent(/^5$/);
    await expect.element(screen.getByRole('button', { name: 'Send' })).toBeDisabled();
  });

  it('never goes negative when the deadline is already in the past', async () => {
    const screen = await renderProbe();

    await screen.getByRole('button', { name: 'sixty' }).click();
    await expect.element(screen.getByTestId('remaining')).toHaveTextContent(/^60$/);

    const realNow = Date.now();
    vi.spyOn(Date, 'now').mockImplementation(() => realNow + 600_000);

    // `Math.max(0, …)`. Without it the countdown renders "-540" under the send button.
    await expect.element(screen.getByTestId('remaining'), { timeout: 4_000 }).toHaveTextContent(/^0$/);
    await expect.element(screen.getByRole('button', { name: 'Send' })).toBeEnabled();
    // Scoped to the countdown's own node: `document.body.textContent` also carries this probe's own
    // button labels, one of which is "over-cap", so the wider assertion failed on the test's fixture
    // rather than on the hook.
    expect(screen.getByTestId('remaining').element().textContent).not.toContain('-');
  });

  it('a second start() replaces the window rather than adding to it', async () => {
    const screen = await renderProbe();

    await screen.getByRole('button', { name: 'sixty' }).click();
    await expect.element(screen.getByTestId('remaining')).toHaveTextContent(/^60$/);

    // Two 429s in a row: the second header is the authoritative one. Accumulating would lock the form
    // for the sum of every rejection the user collected.
    await screen.getByRole('button', { name: 'thirty' }).click();
    await expect.element(screen.getByTestId('remaining')).toHaveTextContent(/^30$/);
  });
});

describe('the interval does not outlive the component', () => {
  it('clears every interval it created when unmounted mid-cooldown', async () => {
    // The cleanup return in the effect. Without it, a form that unmounts mid-cooldown — the register
    // screen navigating away after a successful submit, say — leaves a timer calling setState on a
    // dead component every second for up to an hour.
    //
    // Counted rather than identity-checked: the effect re-runs on every `remaining` change, so there
    // is a create/clear pair per tick and the invariant is that the two counts match after unmount.
    const created: number[] = [];
    const cleared: number[] = [];
    const setSpy = vi.spyOn(globalThis, 'setInterval');
    const clearSpy = vi.spyOn(globalThis, 'clearInterval');

    const screen = await renderProbe();
    await screen.getByRole('button', { name: 'thirty' }).click();
    await expect.element(screen.getByTestId('remaining')).toHaveTextContent(/^30$/);

    for (const call of setSpy.mock.results) created.push(call.value as number);
    // AWAITED, AND NOT AS TIDINESS. `unmount()` wraps its work in React's `act()` and returns the
    // promise for it, so reading `clearSpy.mock.calls` on the next line without awaiting reads the
    // spy BEFORE the effect cleanups are guaranteed to have run — the assertion below would be
    // racing the thing it measures. The second reason is the harness-wide one in tests/msw/setup.ts:
    // an un-awaited act-wrapping call that OVERLAPS the next one corrupts React's act queue for the
    // rest of the file. Nothing overlaps this one today, which is exactly how it survived.
    await screen.unmount();
    for (const call of clearSpy.mock.calls) cleared.push(call[0] as number);

    expect(created.length).toBeGreaterThan(0);
    for (const id of created) expect(cleared).toContain(id);
  });

  it('creates NO interval while there is no cooldown', async () => {
    // `if (remaining <= 0) return;` — a hook that armed an interval on mount would tick once a second
    // on every login screen nobody has been throttled on.
    const setSpy = vi.spyOn(globalThis, 'setInterval');

    const screen = await renderProbe();
    await expect.element(screen.getByTestId('remaining')).toHaveTextContent(/^0$/);

    expect(setSpy).not.toHaveBeenCalled();
  });
});
