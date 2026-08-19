import { render } from 'vitest-browser-react';
import { afterEach, beforeAll, describe, expect, it } from 'vitest';

import '@/app/globals.css';

/**
 * THE TWO CSS PROPERTIES THAT CANNOT BE ASSERTED FROM THE TOKEN DATA, only from a real cascade in a
 * real browser. `tests/unit/design-system.test.ts` asserts the VALUES; this asserts what the browser
 * does with them.
 *
 * Both failures below are silent by construction — no build error, no console warning, no visual
 * difference on the default palette — which is exactly why they get a spec rather than a review
 * convention.
 */

const RADIUS_STEPS = ['xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl', 'full'] as const;

/**
 * A base-layer fallback, which is the whole trick.
 *
 * `border-radius: max(0px, calc(0rem - 6px))` is VALID and computes to `0px`. Drop the `max()` and
 * `calc(0rem - 6px)` is `-6px`, which is an invalid `border-radius`, so the browser DISCARDS the
 * declaration — and a discarded declaration is not "0px", it is "whatever else in the cascade
 * applies". On a bare element that is the initial `0px`, which is indistinguishable from success and
 * is why a naive version of this test passes either way.
 *
 * So the probe carries a competing declaration in `@layer base`. Tailwind's utilities live in
 * `@layer utilities`, which beats base — as long as the utility's value is valid. The moment it is
 * not, the utility is dropped and this 99px shows through. That makes "the element lost its
 * border-radius" a thing the test can actually see.
 */
const PROBE_FALLBACK = `@layer base { [data-radius-probe] { border-radius: 99px; } }`;

beforeAll(() => {
  const style = document.createElement('style');
  style.textContent = PROBE_FALLBACK;
  document.head.append(style);
});

afterEach(() => {
  document.documentElement.style.removeProperty('--radius');
});

describe('the derived radius scale survives every value in the tenant enum', () => {
  // `0rem` is IN the enum, and it is the one that breaks an unwrapped calc(). `0.25rem` is the next
  // one up and still goes negative at the small end (4px - 6px), so it is not a redundant case.
  for (const radius of ['0rem', '0.25rem', '0.625rem', '1rem']) {
    it(`keeps every step of the scale valid at --radius: ${radius}`, async () => {
      document.documentElement.style.setProperty('--radius', radius);

      const screen = await render(
        <div>
          {RADIUS_STEPS.map((step) => (
            <div key={step} data-radius-probe data-step={step} className={`rounded-${step}`}>
              {step}
            </div>
          ))}
        </div>,
      );

      for (const step of RADIUS_STEPS) {
        const element = screen.container.querySelector(`[data-step="${step}"]`);
        expect(element, step).not.toBeNull();
        const value = getComputedStyle(element as Element).borderTopLeftRadius;

        // 99px is the base-layer fallback showing through, which means the utility's own value was
        // invalid and got discarded. Anything else means the utility applied.
        expect(value, `--radius-${step} at --radius: ${radius} was discarded`).not.toBe('99px');
        // And it is never negative, which is the shape that made it invalid in the first place.
        expect(Number.parseFloat(value), `--radius-${step}`).toBeGreaterThanOrEqual(0);
      }
    });
  }

  it('clamps the small end to zero rather than going negative', async () => {
    document.documentElement.style.setProperty('--radius', '0rem');
    const screen = await render(
      <div>
        <div data-step="xs" className="rounded-xs" />
        <div data-step="3xl" className="rounded-3xl" />
      </div>,
    );
    const xs = getComputedStyle(screen.container.querySelector('[data-step="xs"]')!).borderTopLeftRadius;
    const xxxl = getComputedStyle(screen.container.querySelector('[data-step="3xl"]')!).borderTopLeftRadius;

    // xs is `max(0px, calc(0rem - 6px))` -> clamped to 0. 3xl is `calc(0rem + 18px)` -> still 18px,
    // which proves the scale is doing arithmetic rather than collapsing wholesale.
    expect(xs).toBe('0px');
    expect(xxxl).toBe('18px');
  });
});

/**
 * THE CASE EVERY SPEC ABOVE IS BLIND TO, and the one the product actually renders in the console.
 *
 * Every assertion in the block above sets `--radius` on `documentElement`. That is the whole-page
 * shape — hosted chat's `/c/[publicBotId]/theme.css` route, which emits `:root`/`.dark` — and it
 * passes whether or not the derived scale is scoped-override-safe. The admin console takes the other
 * path: `<BotThemeScope>` writes `--radius` onto a NESTED div, many bots in one document.
 *
 * A custom property is substituted at the element that DECLARES it. So while the scale was emitted
 * into `@theme inline` as `--radius-xl: var(--radius-xl)`, `rounded-xl` compiled to
 * `border-radius: var(--radius-xl)` and read the value `:root` had already computed — a fixed length
 * every descendant inherits, which a nested `--radius` cannot move. The bot identity preview showed
 * the platform corner while the widget shipped the tenant's, in the direction where the preview
 * UNDER-reports, and nothing failed: no build error, no console warning, and the documentElement
 * specs above stayed green.
 *
 * The fix is in the generator (`packages/design-tokens/scripts/build.mjs`): the block now carries the
 * calc itself, so the utility is `border-radius: max(0px, calc(var(--radius) - 4px))` and the
 * arithmetic resolves against whatever `--radius` the ELEMENT inherits.
 */
describe('the derived radius scale follows a NESTED --radius, not only the document root', () => {
  /** `--radius: 1rem` is 16px and is in the tenant enum; the platform default is 0.625rem = 10px. */
  const SCOPED_RADIUS = '1rem';

  /** Every step at 16px, spelled out rather than derived — a table computed from the same formula
   *  under test would agree with a broken generator. `full` is the constant and is here to prove the
   *  loop covers the whole scale rather than skipping the step that cannot move.
   *
   *  A Map and not an object literal: `security/detect-object-injection` reports `TABLE[step]` for a
   *  non-literal key, and this file is not the place to argue with it. */
  const AT_1REM = new Map<(typeof RADIUS_STEPS)[number], string>([
    ['xs', '10px'],
    ['sm', '12px'],
    ['md', '14px'],
    ['lg', '16px'],
    ['xl', '20px'],
    ['2xl', '26px'],
    ['3xl', '34px'],
    ['full', '9999px'],
  ]);

  it('moves every step inside the scope and leaves an identical sibling outside it alone', async () => {
    const screen = await render(
      <div>
        {/* No colour classes and no style attribute — the same shape <BotThemeScope/> renders. */}
        <div data-testid="scope">
          {RADIUS_STEPS.map((step) => (
            <div key={step} data-inside={step} className={`rounded-${step}`} />
          ))}
        </div>
        {RADIUS_STEPS.map((step) => (
          <div key={step} data-outside={step} className={`rounded-${step}`} />
        ))}
      </div>,
    );

    const inside = (step: string) =>
      getComputedStyle(screen.container.querySelector(`[data-inside="${step}"]`) as Element)
        .borderTopLeftRadius;
    const outside = (step: string) =>
      getComputedStyle(screen.container.querySelector(`[data-outside="${step}"]`) as Element)
        .borderTopLeftRadius;

    const before = new Map(RADIUS_STEPS.map((step) => [step, outside(step)] as const));
    // Sanity: the two columns start identical, so the difference asserted below is the override's.
    for (const step of RADIUS_STEPS) expect(inside(step), step).toBe(before.get(step));

    // Written through the CSSOM exactly as <BotThemeScope/> does it, on a NESTED element — never on
    // documentElement, which is the whole point of this spec.
    const scope = screen.container.querySelector('[data-testid="scope"]') as HTMLElement;
    scope.style.setProperty('--radius', SCOPED_RADIUS);

    for (const step of RADIUS_STEPS) {
      expect(inside(step), `rounded-${step} ignored the scoped --radius`).toBe(AT_1REM.get(step));
      expect(outside(step), `the scoped --radius leaked out of its subtree at rounded-${step}`).toBe(
        before.get(step),
      );
    }

    // The regression signature, stated directly: before the generator emitted the calc, the two
    // columns stayed equal at every DERIVED step and this is the assertion that saw it. `full` is
    // excluded because 9999px is a literal and is legitimately equal on both sides.
    for (const step of RADIUS_STEPS.filter((s) => s !== 'full')) {
      expect(inside(step), `rounded-${step} is inert under a scoped --radius`).not.toBe(
        outside(step),
      );
    }
  });

  it('clamps the small end inside a scope too, at the enum value that goes negative', async () => {
    const screen = await render(
      <div data-testid="scope">
        {/* `data-radius-probe` opts these two into the 99px @layer base fallback declared at the top
            of this file. Without it `0px` is indistinguishable from "the utility was discarded as
            invalid and the initial value showed through", which is the failure being tested. */}
        <div data-radius-probe data-step="xs" className="rounded-xs" />
        <div data-radius-probe data-step="3xl" className="rounded-3xl" />
      </div>,
    );

    const scope = screen.container.querySelector('[data-testid="scope"]') as HTMLElement;
    scope.style.setProperty('--radius', '0rem');

    const read = (step: string) =>
      getComputedStyle(screen.container.querySelector(`[data-step="${step}"]`) as Element)
        .borderTopLeftRadius;

    // The `max(0px, …)` wrapper has to survive the move into the utility: without it `calc(0rem -
    // 6px)` is an invalid border-radius, the browser discards the declaration, and a square-cornered
    // tenant loses the radius entirely rather than getting square corners.
    expect(read('xs')).toBe('0px');
    expect(read('3xl')).toBe('18px');
  });
});

describe('@theme inline is what makes a subtree override work', () => {
  /**
   * `tailwind-shadcn` Gotcha 1, and the reason this spec exists at all: `@theme` WITHOUT `inline`
   * resolves `var(--primary)` where the block is defined — `:root` — and bakes the result into
   * `--color-primary`. Every utility then carries the ROOT's colour, so the bot theme preview panel
   * either shows the platform palette no matter what is set, or leaks its override upward into the
   * whole console.
   *
   * BOTH SPELLINGS COMPILE AND NOTHING WARNS. The only observable difference is the one asserted
   * here: an override on a wrapper must change a descendant and must NOT change a sibling outside
   * it. The alias block is generated from tokens.json, so this also guards the generator.
   */
  it('scopes an override to the subtree and leaves a sibling alone', async () => {
    const screen = await render(
      <div>
        <div data-testid="scope">
          <div data-testid="inside" className="bg-primary" />
        </div>
        <div data-testid="outside" className="bg-primary" />
      </div>,
    );

    const scope = screen.container.querySelector('[data-testid="scope"]') as HTMLElement;
    const inside = screen.container.querySelector('[data-testid="inside"]') as HTMLElement;
    const outside = screen.container.querySelector('[data-testid="outside"]') as HTMLElement;

    const before = getComputedStyle(inside).backgroundColor;
    expect(before).toBe(getComputedStyle(outside).backgroundColor);

    // Written through the CSSOM, exactly as <BotThemeScope/> does it — never a style attribute,
    // which CSP would refuse in production while permitting it in development.
    scope.style.setProperty('--primary', 'oklch(0.6 0.2 20)');

    const after = getComputedStyle(inside).backgroundColor;
    expect(after, 'the override did not reach the descendant').not.toBe(before);
    expect(
      getComputedStyle(outside).backgroundColor,
      'the override leaked out of its subtree',
    ).toBe(before);
  });

  it('carries the whole family, not just --primary', async () => {
    // One alias missing from the generated @theme block breaks exactly one utility, silently, in
    // exactly one place. Sampling one token per family is what makes that visible.
    const screen = await render(
      <div data-testid="scope">
        <div data-testid="canvas" className="bg-canvas" />
        <div data-testid="tone" className="bg-tone-sky-surface" />
        <div data-testid="soft" className="bg-primary-soft" />
        <div data-testid="inset" className="bg-card-inset" />
      </div>,
    );

    for (const [id, property] of [
      ['canvas', '--canvas'],
      ['tone', '--tone-sky-surface'],
      ['soft', '--primary-soft'],
      ['inset', '--card-inset'],
    ] as const) {
      const element = screen.container.querySelector(`[data-testid="${id}"]`) as HTMLElement;
      const before = getComputedStyle(element).backgroundColor;
      element.style.setProperty(property, 'oklch(0.5 0.2 140)');
      expect(getComputedStyle(element).backgroundColor, `${property} is not aliased inline`).not.toBe(
        before,
      );
    }
  });
});
