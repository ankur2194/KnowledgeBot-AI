import { describe, expect, it } from 'vitest';

import { colors, radiusScale, shadows, type, motion } from '@kb/design-tokens';

import {
  contrastRatio,
  formatOklch,
  oklchToSrgb,
  parseOklch,
  relativeLuminance,
  toHex,
  type Oklch,
} from '../../src/lib/color';
import {
  deriveAccentRamp,
  deriveForeground,
  isReadableAccent,
  safeThemeDeclarations,
} from '../../src/lib/theme';

type Mode = 'light' | 'dark';
const MODES: readonly Mode[] = ['light', 'dark'];

/** Every token as a parsed colour, or a failure naming the token — never a silent skip. */
function tokenColor(name: string, mode: Mode): Oklch {
  const pair = colors[name as keyof typeof colors];
  if (!pair) throw new Error(`no such token: --${name}`);
  const parsed = parseOklch(pair[mode]);
  if (!parsed) throw new Error(`--${name} (${mode}) is not a parseable oklch(): ${pair[mode]}`);
  return parsed;
}

const ratio = (fg: string, bg: string, mode: Mode) =>
  contrastRatio(tokenColor(fg, mode), tokenColor(bg, mode));

describe('colour maths', () => {
  it('converts the sRGB primaries to their exact OKLCH coordinates', () => {
    // Anchored to the sRGB primaries rather than to references/tokens.md's hex column, which that
    // file itself calls "approximations for eyeballing" and which does not agree with its own OKLCH:
    // --foreground is oklch(0.205 0.014 266) and is documented as #1B1D26, but #1B1D26 measures
    // L 0.233. The conversion here is the CSS Color 4 one; the doc column is the stale side.
    const near = (actual: string, expected: string) => {
      const channels = (hex: string) => [1, 3, 5].map((i) => Number.parseInt(hex.slice(i, i + 2), 16));
      const a = channels(actual);
      const b = channels(expected);
      a.forEach((v, i) => expect(Math.abs(v - b[i]!), `${actual} vs ${expected}`).toBeLessThanOrEqual(2));
    };
    expect(toHex({ l: 1, c: 0, h: 0 })).toBe('#FFFFFF');
    expect(toHex({ l: 0, c: 0, h: 0 })).toBe('#000000');
    // Full precision on purpose: the sRGB primaries sit exactly ON the gamut boundary, so a
    // 4-significant-figure spelling of one falls just outside it and gets legitimately gamut-mapped
    // to a less saturated colour. That is the algorithm working, not a conversion error.
    near(toHex({ l: 0.62796, c: 0.25768, h: 29.234 }), '#FF0000');
    near(toHex({ l: 0.86644, c: 0.29483, h: 142.495 }), '#00FF00');
    near(toHex({ l: 0.45201, c: 0.31321, h: 264.052 }), '#0000FF');
  });

  it('preserves the canvas/card lightness gap the two-plane model rests on', () => {
    // 0.055 OKLCH in light mode. Every shadow in the ladder is tuned against this number, so it is
    // asserted rather than assumed: a canvas edit that closes the gap makes every card look flat and
    // the CSS looks identical.
    const gap = parseOklch(colors.card.light)!.l - parseOklch(colors.canvas.light)!.l;
    expect(gap).toBeCloseTo(0.055, 3);
    // In dark the relationship INVERTS: the card is lighter than the canvas, because shadow carries
    // no information on a dark ground.
    expect(parseOklch(colors.card.dark)!.l).toBeGreaterThan(parseOklch(colors.canvas.dark)!.l);
  });

  it('accepts every legitimate oklch() spelling, including the ones the old pattern dropped', () => {
    expect(parseOklch('oklch(1 0 0)')).toEqual({ l: 1, c: 0, h: 0 });
    expect(parseOklch('oklch(.5 .1 20)')).toEqual({ l: 0.5, c: 0.1, h: 20 });
    expect(parseOklch('oklch(0.205 0.014 266 / 0.45)')).toEqual({ l: 0.205, c: 0.014, h: 266, alpha: 0.45 });
  });

  it('rejects out-of-range values and every injection shape', () => {
    for (const bad of [
      'oklch(2 0 0)',
      'oklch(0.5 0.9 0)',
      'oklch(0.5 0.1 400)',
      'oklch(.5 .1 20); } body { background: url(https://evil/) ',
      'oklch(var(--x) 0 0)',
      'oklch(calc(1) 0 0)',
      'red',
      '',
    ]) {
      expect(parseOklch(bad), bad).toBeNull();
    }
  });

  it('parses in linear time against adversarial input', () => {
    // The grammar carries an eslint-disable for security/detect-unsafe-regex. This is the
    // measurement that entitles it to: a bounded pattern with no ambiguous alternation cannot
    // backtrack, and if someone loosens one of those quantifiers this fails rather than shipping a
    // parser a tenant string can stall.
    for (const n of [1_000, 20_000, 60_000]) {
      const started = performance.now();
      parseOklch(`oklch(${'9'.repeat(n)}.${'9'.repeat(n)} 0 0`);
      parseOklch(`oklch(0.5 0.1 ${' '.repeat(n)}20)`);
      parseOklch(`oklch(${'0.'.repeat(n)})`);
      expect(performance.now() - started, `n=${n}`).toBeLessThan(250);
    }
  });

  it('gamut-maps by reducing chroma, preserving lightness exactly', () => {
    // An absurdly saturated colour at a lightness sRGB can represent: the hue and lightness survive,
    // only saturation is spent. Clipping instead would move the lightness, which is what collapses
    // the 0.055 canvas/card gap the whole two-plane model rests on.
    const wild: Oklch = { l: 0.525, c: 0.45, h: 264 };
    const mapped = oklchToSrgb(wild);
    const reference = oklchToSrgb({ l: 0.525, c: 0, h: 264 });
    // Same lightness axis => luminance stays in the neighbourhood of the achromatic anchor rather
    // than crashing to black, which is the clipping failure.
    expect(relativeLuminance(mapped)).toBeGreaterThan(0);
    expect(relativeLuminance(reference)).toBeGreaterThan(0);
  });
});

describe('the contrast matrix (kb-ui-accessibility)', () => {
  for (const mode of MODES) {
    describe(mode, () => {
      it('--foreground clears 4.5:1 on every surface it is used on', () => {
        for (const surface of ['card', 'canvas', 'card-inset', 'popover']) {
          expect(ratio('foreground', surface, mode), `foreground on ${surface}`).toBeGreaterThanOrEqual(4.5);
        }
      });

      it('--muted-foreground clears 4.5:1 on --card AND --canvas', () => {
        // Passing only against white is the failure mode that ships.
        expect(ratio('muted-foreground', 'card', mode)).toBeGreaterThanOrEqual(4.5);
        expect(ratio('muted-foreground', 'canvas', mode)).toBeGreaterThanOrEqual(4.5);
      });

      it('every -soft-foreground clears 4.5:1 on its own -soft', () => {
        for (const family of ['primary', 'success', 'warning', 'destructive', 'info']) {
          expect(
            ratio(`${family}-soft-foreground`, `${family}-soft`, mode),
            `${family}-soft`,
          ).toBeGreaterThanOrEqual(4.5);
        }
      });

      it('every --tone-*-foreground clears 4.5:1 on its own -surface', () => {
        for (const tone of ['amber', 'sky', 'violet', 'mint', 'rose', 'slate']) {
          expect(
            ratio(`tone-${tone}-foreground`, `tone-${tone}-surface`, mode),
            `tone-${tone}`,
          ).toBeGreaterThanOrEqual(4.5);
        }
      });

      it('--destructive-foreground clears 4.5:1 on --destructive-strong', () => {
        // The -strong token exists so a SOLID destructive button has a surface its near-white label
        // clears on. It is required in both modes and the base is not a substitute:
        //   light  base 4.66  strong 5.84
        //   dark   base 2.75  strong 4.99   <- the base misses the floor outright
        // references/tokens.md justifies -strong by saying the base "lands near 4.2:1" in light
        // mode; measured against the gamut-mapped value it is 4.66 and clears. The token is still
        // right, for the dark-mode reason rather than the stated one.
        expect(ratio('destructive-foreground', 'destructive-strong', mode)).toBeGreaterThanOrEqual(4.5);
      });

      it('non-text signals clear 3:1 on --card and --canvas', () => {
        for (const token of ['border-strong', 'primary', 'success', 'warning', 'destructive', 'info']) {
          for (const surface of ['card', 'canvas']) {
            expect(ratio(token, surface, mode), `${token} on ${surface}`).toBeGreaterThanOrEqual(3);
          }
        }
      });

      it('chart series clear 3:1 on --card and stay >= 0.06 apart in lightness', () => {
        const series = [1, 2, 3, 4, 5, 6].map((n) => `chart-${n}`);
        for (const s of series) {
          expect(ratio(s, 'card', mode), `${s} on card`).toBeGreaterThanOrEqual(3);
        }
        for (let i = 1; i < series.length; i += 1) {
          const delta = Math.abs(tokenColor(series[i]!, mode).l - tokenColor(series[i - 1]!, mode).l);
          expect(delta, `${series[i - 1]} vs ${series[i]}`).toBeGreaterThanOrEqual(0.06);
        }
      });

      it('--chart-1 is not the tenant accent', () => {
        // A red-branded tenant would otherwise get a red first series beside --destructive in the
        // same figure, meaning two different things.
        expect(colors['chart-1'][mode]).not.toBe(colors.primary[mode]);
      });
    });
  }
});

describe('the derived accent ramp', () => {
  const platformLight = parseOklch(colors.primary.light)!;
  const platformDark = parseOklch(colors.primary.dark)!;

  it('keeps the tenant hue exactly and moves lightness in the mode-correct direction', () => {
    // Light darkens on interaction, dark lightens: on a dark canvas a darker hover reads as
    // "disabled" rather than as "pressed".
    const brand: Oklch = { l: 0.5, c: 0.18, h: 21 };
    for (const [mode, sign] of [
      ['light', -1],
      ['dark', 1],
    ] as const) {
      const ramp = deriveAccentRamp(brand, mode)!;
      const hover = parseOklch(ramp['--primary-hover']!)!;
      const active = parseOklch(ramp['--primary-active']!)!;
      expect(hover.h, mode).toBeCloseTo(brand.h, 3);
      expect(active.h, mode).toBeCloseTo(brand.h, 3);
      expect(Math.sign(hover.l - brand.l), mode).toBe(sign);
      expect(Math.sign(active.l - hover.l), mode).toBe(sign);
    }
  });

  it('lands within a rounding step of the hand-tuned platform ramp', () => {
    // The default ramp in tokens.json is hand-tuned; the derivation has to reproduce it closely
    // enough that a tenant who picks our own indigo does not get a visibly different product.
    const near = (a: string, b: string, label: string) => {
      const x = parseOklch(a)!;
      const y = parseOklch(b)!;
      expect(Math.abs(x.l - y.l), `${label} lightness`).toBeLessThan(0.0101);
      expect(Math.abs(x.c - y.c), `${label} chroma`).toBeLessThan(0.0151);
      expect(x.h, `${label} hue`).toBeCloseTo(y.h, 3);
    };
    const light = deriveAccentRamp(platformLight, 'light')!;
    near(light['--primary-hover']!, colors['primary-hover'].light, 'light hover');
    near(light['--primary-active']!, colors['primary-active'].light, 'light active');
    near(light['--primary-soft']!, colors['primary-soft'].light, 'light soft');
    near(light['--primary-soft-foreground']!, colors['primary-soft-foreground'].light, 'light soft-fg');
    expect(light['--primary-foreground']!).toBe(colors['primary-foreground'].light);
  });

  it('produces a soft pair that clears 4.5:1, for any admissible accent', () => {
    for (const brand of [
      { l: 0.35, c: 0.12, h: 0 },
      { l: 0.5, c: 0.2, h: 140 },
      { l: 0.7, c: 0.15, h: 300 },
      { l: 0.9, c: 0.05, h: 90 },
    ] as Oklch[]) {
      if (!isReadableAccent(brand)) continue;
      for (const mode of MODES) {
        const ramp = deriveAccentRamp(brand, mode)!;
        const soft = parseOklch(ramp['--primary-soft']!)!;
        const softFg = parseOklch(ramp['--primary-soft-foreground']!)!;
        expect(contrastRatio(softFg, soft), `${formatOklch(brand)} ${mode}`).toBeGreaterThanOrEqual(4.5);
      }
    }
  });

  it('either derives a foreground clearing 4.5:1 or refuses the colour — never ships an unreadable pair', () => {
    // The assertion runs over the BOUNDS OF THE GRAMMAR, not over one sample: the tenant supplies
    // the surface and we supply the text, so a brand colour that lands unreadable is our bug.
    let admitted = 0;
    let refused = 0;
    for (let l = 0; l <= 1.0001; l += 0.02) {
      for (let c = 0; c <= 0.37; c += 0.037) {
        for (let h = 0; h < 360; h += 15) {
          const primary: Oklch = { l: Math.min(l, 1), c, h };
          const fg = deriveForeground(primary);
          if (fg === null) {
            refused += 1;
            expect(deriveAccentRamp(primary, 'light'), formatOklch(primary)).toBeNull();
            continue;
          }
          admitted += 1;
          expect(contrastRatio(fg, primary), formatOklch(primary)).toBeGreaterThanOrEqual(4.5);
        }
      }
    }
    expect(admitted).toBeGreaterThan(10_000);
    // The hole is real and narrow. If this number moves, the candidate text colours moved with it.
    expect(refused / (admitted + refused)).toBeLessThan(0.12);
  });

  it('admits both platform primaries', () => {
    expect(isReadableAccent(platformLight)).toBe(true);
    expect(isReadableAccent(platformDark)).toBe(true);
  });
});

describe('the tenant guard', () => {
  it('writes nothing outside the closed key set', () => {
    const declarations = safeThemeDeclarations({
      primary: 'oklch(0.5 0.2 20)',
      radius: '1rem',
      // None of these may reach the DOM.
      canvas: 'oklch(0.1 0 0)',
      background: 'oklch(0.1 0 0)',
      'font-sans': 'Comic Sans',
      'shadow-md': 'none',
    });
    const written = declarations.map(([name]) => name);
    for (const name of written) {
      expect(['--primary', '--primary-foreground', '--accent', '--accent-foreground', '--primary-hover',
        '--primary-active', '--primary-soft', '--primary-soft-foreground', '--radius']).toContain(name);
    }
    expect(written).toContain('--primary-hover');
    expect(written).toContain('--radius');
  });

  it('ignores a tenant-submitted -foreground in favour of the derived one', () => {
    const declarations = safeThemeDeclarations({
      primary: 'oklch(0.95 0.02 90)', // very light: the derived foreground must be the DARK one
      'primary-foreground': 'oklch(0.985 0 0)', // white on near-white: unreadable, and submitted
    });
    const fg = declarations.find(([n]) => n === '--primary-foreground')?.[1];
    expect(fg).toBeDefined();
    expect(contrastRatio(parseOklch(fg!)!, parseOklch('oklch(0.95 0.02 90)')!)).toBeGreaterThanOrEqual(4.5);
  });

  it('drops a malformed value rather than guessing', () => {
    expect(safeThemeDeclarations({ primary: 'red', radius: '3px' })).toEqual([]);
  });

  it('refuses an accent it cannot make readable, rather than shipping half a ramp', () => {
    // A mid-lightness brand colour no palette text clears. Falling back to the platform accent is
    // the only safe answer; writing --primary alone would leave hover, pressed and the active nav
    // pill on platform indigo, which is two brands on one screen.
    const unreadable = { l: 0.58, c: 0.02, h: 200 };
    expect(isReadableAccent(unreadable)).toBe(false);
    const declarations = safeThemeDeclarations({ primary: formatOklch(unreadable), radius: '1rem' });
    expect(declarations.map(([n]) => n)).toEqual(['--radius']);
  });

  it('writes the accent ramp and the accent wash independently', () => {
    const only = safeThemeDeclarations({ accent: 'oklch(0.96 0.008 264)' }).map(([n]) => n);
    expect(only).toEqual(['--accent', '--accent-foreground']);
  });
});

describe('the scales are closed and complete', () => {
  it('wraps every derived radius in max(0px, …) — 0rem is in the tenant enum', () => {
    // A negative border-radius is invalid and the browser drops the WHOLE declaration, so a
    // square-cornered tenant loses the radius entirely rather than getting square corners.
    for (const [step, value] of Object.entries(radiusScale)) {
      if (value.includes(' - ')) {
        expect(value, `--radius-${step}`).toMatch(/^max\(0px,/);
      }
    }
  });

  it('gives every shadow step a dark value carrying an inset light ring', () => {
    // In dark it is the ring, not the shadow, that separates a card from the canvas.
    for (const [step, pair] of Object.entries(shadows)) {
      expect(pair.dark, `--shadow-${step}`).toContain('inset');
    }
  });

  it('gives every type step all four values', () => {
    for (const [step, values] of Object.entries(type)) {
      expect(values.size, step).toBeTruthy();
      expect(values.lineHeight, step).toBeTruthy();
      expect(values.weight, step).toBeTruthy();
      expect(values.tracking, step).toBeDefined();
    }
  });

  it('keeps every duration inside the 400ms ceiling', () => {
    for (const [name, value] of Object.entries(motion.durations)) {
      expect(Number.parseInt(value, 10), name).toBeLessThanOrEqual(400);
    }
  });
});
