import { RADIUS_VALUES, TENANT_OVERRIDABLE_COLOR_KEYS } from '@kb/design-tokens';

import { formatOklch, parseOklch, contrastRatio, type Oklch } from './color';

/**
 * A tenant supplies token VALUES, never CSS. `bots.theme_configuration` (§16.3) is a fixed key set
 * of scalars validated against an exact grammar — not a stylesheet, not a class name, not a `style`
 * string. Interpolating a tenant string into a `<style>` element is CSS injection: `}` closes the
 * rule and everything after it is attacker CSS, and `url()` in a matched selector is an
 * unauthenticated outbound GET.
 *
 * This module is the render-time guard, and it is the SECOND check, not the first: Laravel
 * validates on write against the same closed grammar. It re-runs here because the row may predate
 * the validator or have been written by a fixture.
 *
 * RESOLVED (was a standing FLAG): the pattern transcribed from `tailwind-shadcn` required a decimal
 * point in all three components, so `oklch(1 0 0)` and `oklch(0.205 0 0)` — the shape of our own
 * platform defaults — did not match and were silently dropped. It failed closed, so it was safe and
 * wrong. The grammar now lives in `lib/color.ts` as `parseOklch`, which accepts every legitimate
 * spelling and enforces the RANGES numerically instead. It must stay byte-identical to Laravel's
 * rule; whichever way that is settled, both sides move together.
 */

const RADIUS: ReadonlySet<string> = new Set<string>(RADIUS_VALUES);

export type BotTheme = Readonly<Record<string, unknown>>;

export type ColorMode = 'light' | 'dark';

/** The two candidates for any derived `-foreground`. Never accepted from a form. */
const ON_DARK: Oklch = { l: 0.985, c: 0, h: 0 };
const ON_LIGHT: Oklch = { l: 0.205, c: 0.014, h: 266 };

/**
 * Pick the `-foreground` that reads on a surface, rather than trusting the caller.
 *
 * `kb-design-language`: "Contrast is derived, never chosen." The tenant supplies the surface; this
 * picks the text. Returning the better of two fixed candidates — instead of computing an arbitrary
 * colour — keeps the result inside the palette, which is what makes it contrast-checkable.
 *
 * `null` means NEITHER candidate clears 4.5:1, which is a real hole rather than a rounding problem:
 * two fixed text colours cannot cover the whole lightness axis. Measured over the accepted grammar,
 * the unreachable band is L in [0.538, 0.634] for some chroma/hue combinations, bottoming out at
 * 4.143:1 — and even pure black against pure white only reaches 4.583:1 at its crossover, so no
 * choice of candidates closes it. A `primary` that lands there is REFUSED (below), not shipped with
 * unreadable text on it.
 *
 * FLAG for `control-plane-engineer`: Laravel validates `bots.theme_configuration` on write and its
 * grammar must carry this same refusal, or the console will silently fall back to the platform
 * accent for a colour the customer was told was accepted.
 */
export function deriveForeground(surface: Oklch): Oklch | null {
  const onDark = contrastRatio(ON_DARK, surface);
  const onLight = contrastRatio(ON_LIGHT, surface);
  const best = onDark >= onLight ? ON_DARK : ON_LIGHT;
  return Math.max(onDark, onLight) >= 4.5 ? best : null;
}

/** Whether a tenant accent can be given readable text. The one test a brand colour has to pass. */
export function isReadableAccent(surface: Oklch): boolean {
  return deriveForeground(surface) !== null;
}

const clamp01 = (n: number): number => (n < 0 ? 0 : n > 1 ? 1 : n);

/**
 * The rest of the accent family, derived from the one colour a tenant supplies.
 *
 * Without this a tenant's brand reaches the resting primary button and NOTHING else: hover, the
 * pressed state, the active nav pill and the selected table row all stay platform indigo, and the
 * screen reads as two brands. `--primary-hover` / `--primary-active` are not in
 * `tenantOverridable` on purpose — they are derived here, never submitted.
 *
 * The moves are lightness-only with a proportional chroma taper, so the ramp keeps the tenant's hue
 * exactly. Light mode darkens on interaction and dark mode lightens, because on a dark canvas a
 * darker hover reads as "disabled" rather than as "pressed".
 *
 * The deltas reproduce the platform default exactly: `oklch(0.525 0.235 264)` yields the
 * `--primary-hover` / `-active` / `-soft` / `-soft-foreground` values in
 * `kb-design-language` → `references/tokens.md`.
 */
export function deriveAccentRamp(primary: Oklch, mode: ColorMode): Record<string, string> | null {
  const foreground = deriveForeground(primary);
  if (!foreground) return null;

  const { l, c, h } = primary;
  const dir = mode === 'light' ? -1 : 1;
  const step = mode === 'light' ? 0.055 : 0.06;

  const hover: Oklch = { l: clamp01(l + dir * step), c: c * 0.98, h };
  const active: Oklch = { l: clamp01(l + dir * step * 2), c: c * 0.915, h };

  const soft: Oklch =
    mode === 'light' ? { l: 0.96, c: Math.min(c, c * 0.094), h } : { l: 0.3, c: Math.min(c, c * 0.256), h };
  const softForeground: Oklch =
    mode === 'light' ? { l: 0.44, c: c * 0.915, h } : { l: 0.8, c: Math.min(c, c * 0.558), h };

  return {
    '--primary-foreground': formatOklch(foreground),
    '--primary-hover': formatOklch(hover),
    '--primary-active': formatOklch(active),
    '--primary-soft': formatOklch(soft),
    '--primary-soft-foreground': formatOklch(softForeground),
  };
}

/**
 * The tenant-supplied theme reduced to `[--custom-property, value]` pairs that are certainly safe.
 * A key absent from the closed set can never reach the DOM whatever Laravel returns, and a value
 * that fails the grammar is DROPPED rather than guessed at — the platform default is always a
 * valid answer.
 *
 * `mode` decides which half of the accent ramp is derived. The CSSOM path (the admin console) can
 * only carry one set of values on an element, so it re-derives when the colour mode changes; the
 * stylesheet path (hosted chat) emits both and lets the cascade choose.
 */
export function safeThemeDeclarations(
  theme: BotTheme,
  mode: ColorMode = 'light',
): ReadonlyArray<readonly [string, string]> {
  const declarations: Array<readonly [string, string]> = [];

  // `primary` is all-or-nothing: it is only admitted together with the ramp derived from it. Half a
  // ramp is the failure this ordering prevents — a tenant accent on the resting button with the
  // platform indigo still on hover, the pressed state and the active nav pill.
  const primary = typeof theme['primary'] === 'string' ? parseOklch(theme['primary']) : null;
  const ramp = primary ? deriveAccentRamp(primary, mode) : null;

  if (primary && ramp) {
    declarations.push(['--primary', formatOklch(primary)]);
    for (const [property, value] of Object.entries(ramp)) declarations.push([property, value]);
  }

  // `accent` is shadcn's neutral hover wash, not a second brand colour, and it is independent of
  // the accent ramp above. Its foreground is derived for the same reason: §16.3 lists both
  // `-foreground` keys as written-to-the-DOM, never as form-settable.
  const accent = typeof theme['accent'] === 'string' ? parseOklch(theme['accent']) : null;
  const accentForeground = accent ? deriveForeground(accent) : null;
  if (accent && accentForeground) {
    declarations.push(['--accent', formatOklch(accent)]);
    declarations.push(['--accent-foreground', formatOklch(accentForeground)]);
  }

  const radius = theme['radius'];
  if (typeof radius === 'string' && RADIUS.has(radius)) {
    declarations.push(['--radius', radius]);
  }

  return declarations;
}

/** Every custom property this module is capable of writing. The DOM sees nothing outside it. */
export const WRITABLE_PROPERTIES: readonly string[] = Object.freeze([
  ...TENANT_OVERRIDABLE_COLOR_KEYS.map((k) => `--${k}`),
  '--primary-hover',
  '--primary-active',
  '--primary-soft',
  '--primary-soft-foreground',
  '--radius',
]);
