import { RADIUS_VALUES, TENANT_OVERRIDABLE_COLOR_KEYS } from '@kb/design-tokens';

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
 * FLAG (reported, not silently fixed): this pattern is transcribed from `tailwind-shadcn` and
 * requires a decimal point in all three components, so `oklch(0.205 0 0)` — the shape of our own
 * neutral defaults — does NOT match. It fails CLOSED (the platform default renders), so it is safe
 * but surprising. It must be byte-identical to Laravel's rule; whichever way that is settled, both
 * sides move together.
 */
const OKLCH = /^oklch\(0?\.\d{1,4} 0?\.\d{1,4} \d{1,3}(\.\d{1,2})?\)$/;

const RADIUS: ReadonlySet<string> = new Set<string>(RADIUS_VALUES);

export type BotTheme = Readonly<Record<string, unknown>>;

/**
 * The tenant-supplied theme reduced to `[--custom-property, value]` pairs that are certainly safe.
 * A key absent from the closed set can never reach the DOM whatever Laravel returns, and a value
 * that fails the grammar is DROPPED rather than guessed at — the platform default is always a
 * valid answer.
 */
export function safeThemeDeclarations(theme: BotTheme): ReadonlyArray<readonly [string, string]> {
  const declarations: Array<readonly [string, string]> = [];

  for (const key of TENANT_OVERRIDABLE_COLOR_KEYS) {
    const value = theme[key];
    if (typeof value === 'string' && OKLCH.test(value)) {
      declarations.push([`--${key}`, value]);
    }
  }

  const radius = theme['radius'];
  if (typeof radius === 'string' && RADIUS.has(radius)) {
    declarations.push(['--radius', radius]);
  }

  return declarations;
}
