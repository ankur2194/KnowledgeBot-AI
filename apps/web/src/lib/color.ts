/**
 * OKLCH colour maths, dependency-free.
 *
 * Two callers, and they are the reason this is not a dependency: `lib/theme.ts` derives a tenant's
 * accent ramp from the one colour they supply, and `tests/unit/contrast.test.ts` proves the whole
 * emitted palette clears its floors. Both must agree byte-for-byte with what the browser computes,
 * so the conversion is the CSS Color 4 one rather than an approximation.
 *
 * `kb-design-language` → `references/cross-platform.md` states the rule that shapes
 * `oklchToSrgb`: out-of-gamut colours are **gamut-mapped, never clipped**. Clipping preserves the
 * hue angle and destroys the lightness relationship, and the first thing it breaks is the 0.055
 * canvas/card gap that the entire two-plane model rests on.
 */

export interface Oklch {
  /** Perceptual lightness, 0–1. */
  readonly l: number;
  /** Chroma, 0–~0.4 in practice. */
  readonly c: number;
  /** Hue angle in degrees, 0–360. */
  readonly h: number;
  /** 0–1. Absent means fully opaque. */
  readonly alpha?: number;
}

export interface Srgb {
  readonly r: number;
  readonly g: number;
  readonly b: number;
}

/**
 * The accepted grammar for a colour anywhere in the token set or in a tenant's submission.
 *
 * Deliberately permissive about NOTATION and strict about RANGE: `oklch(1 0 0)`, `oklch(.5 .1 20)`
 * and `oklch(0.205 0.014 266 / 0.45)` are all legitimate spellings of legitimate colours, and the
 * pattern this replaced rejected the first two — including our own platform defaults — because it
 * required a decimal point in every component. Bounds are checked numerically below rather than in
 * the pattern, because a regex that also enforces `0 ≤ l ≤ 1` is unreadable and gets copied wrong.
 */
/* eslint-disable security/detect-unsafe-regex -- MEASURED, not waved through. The rule flags the
 * nested bounded quantifiers heuristically; there is no ambiguity to backtrack on, because each
 * alternation branch starts with a distinct character class (digit vs `.`) and every quantifier is
 * bounded. Timed against adversarial input at 1k/5k/20k/60k characters of digits, dots and
 * whitespace: 0.26 / 0.02 / 0.03 / 0.09 ms — linear. The regression test in
 * tests/unit/design-system.test.ts keeps it that way. */
const OKLCH_SYNTAX =
  /^oklch\(\s*(\d{1,3}(?:\.\d{1,6})?|\.\d{1,6})\s+(\d{1,3}(?:\.\d{1,6})?|\.\d{1,6})\s+(\d{1,3}(?:\.\d{1,6})?|\.\d{1,6})\s*(?:\/\s*(\d{1,3}(?:\.\d{1,6})?|\.\d{1,6})\s*)?\)$/;
/* eslint-enable security/detect-unsafe-regex */

/** Chroma above this is beyond any real display primary and is treated as malformed input. */
const MAX_CHROMA = 0.5;

/** `null` for anything that is not a well-formed, in-range `oklch()` — never a guess. */
export function parseOklch(value: string): Oklch | null {
  const match = OKLCH_SYNTAX.exec(value.trim());
  if (!match) return null;

  const l = Number(match[1]);
  const c = Number(match[2]);
  const h = Number(match[3]);
  const alpha = match[4] === undefined ? undefined : Number(match[4]);

  if (!(l >= 0 && l <= 1)) return null;
  if (!(c >= 0 && c <= MAX_CHROMA)) return null;
  if (!(h >= 0 && h <= 360)) return null;
  if (alpha !== undefined && !(alpha >= 0 && alpha <= 1)) return null;

  return alpha === undefined ? { l, c, h } : { l, c, h, alpha };
}

const round = (n: number, places: number): number => {
  const f = 10 ** places;
  return Math.round(n * f) / f;
};

/** Round-trips through `parseOklch`. Emits the same shape the token set uses. */
export function formatOklch({ l, c, h, alpha }: Oklch): string {
  const body = `${round(l, 4)} ${round(c, 4)} ${round(h, 3)}`;
  return alpha === undefined || alpha >= 1 ? `oklch(${body})` : `oklch(${body} / ${round(alpha, 4)})`;
}

/** Oklab → linear sRGB (Ottosson). Components may fall outside 0–1; that is what gamut mapping is for. */
function oklabToLinearSrgb(L: number, a: number, b: number): { r: number; g: number; b: number } {
  const l_ = L + 0.3963377774 * a + 0.2158037573 * b;
  const m_ = L - 0.1055613458 * a - 0.0638541728 * b;
  const s_ = L - 0.0894841775 * a - 1.291485548 * b;

  const l = l_ * l_ * l_;
  const m = m_ * m_ * m_;
  const s = s_ * s_ * s_;

  return {
    r: 4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
    g: -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
    b: -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s,
  };
}

const inGamut = ({ r, g, b }: { r: number; g: number; b: number }, epsilon = 1e-6): boolean =>
  r >= -epsilon && r <= 1 + epsilon && g >= -epsilon && g <= 1 + epsilon && b >= -epsilon && b <= 1 + epsilon;

const clampChannel = (n: number): number => (n < 0 ? 0 : n > 1 ? 1 : n);

/** Perceptual distance in Oklab. The CSS Color 4 gamut-mapping loop stops at 0.02. */
const deltaEOk = (
  a: { L: number; a: number; b: number },
  b: { L: number; a: number; b: number },
): number => Math.hypot(a.L - b.L, a.a - b.a, a.b - b.b);

const toLab = ({ l, c, h }: Oklch) => {
  const rad = (h * Math.PI) / 180;
  return { L: l, a: c * Math.cos(rad), b: c * Math.sin(rad) };
};

const linearToGamma = (n: number): number =>
  n <= 0.0031308 ? 12.92 * n : 1.055 * Math.pow(n, 1 / 2.4) - 0.055;

/**
 * OKLCH → sRGB, gamut-mapped by chroma reduction (CSS Color 4 §13.2), not clipped.
 *
 * The binary search walks chroma down while keeping lightness and hue fixed, stopping as soon as
 * the clipped result is within ΔEOK 0.02 of the reduced colour. Lightness — the axis the two-plane
 * model and every contrast floor depend on — is preserved exactly; only saturation is spent.
 */
export function oklchToSrgb(color: Oklch): Srgb {
  const directLab = toLab(color);
  const direct = oklabToLinearSrgb(directLab.L, directLab.a, directLab.b);
  if (inGamut(direct)) {
    return { r: linearToGamma(direct.r), g: linearToGamma(direct.g), b: linearToGamma(direct.b) };
  }

  // Pure white and pure black are always representable; the search below is undefined at c = 0.
  if (color.l >= 1) return { r: 1, g: 1, b: 1 };
  if (color.l <= 0) return { r: 0, g: 0, b: 0 };

  let low = 0;
  let high = color.c;
  let best = { ...color, c: 0 };

  for (let i = 0; i < 32 && high - low > 1e-5; i += 1) {
    const c = (low + high) / 2;
    const candidate: Oklch = { l: color.l, c, h: color.h };
    const lab = toLab(candidate);
    const linear = oklabToLinearSrgb(lab.L, lab.a, lab.b);

    if (inGamut(linear)) {
      best = candidate;
      low = c;
      continue;
    }

    const clipped = { r: clampChannel(linear.r), g: clampChannel(linear.g), b: clampChannel(linear.b) };
    // Re-express the clipped colour in Oklab to measure how far the clip moved it.
    const clippedLab = linearSrgbToOklab(clipped);
    if (deltaEOk(lab, clippedLab) < 0.02) {
      best = candidate;
      break;
    }
    high = c;
  }

  const lab = toLab(best);
  const linear = oklabToLinearSrgb(lab.L, lab.a, lab.b);
  return {
    r: linearToGamma(clampChannel(linear.r)),
    g: linearToGamma(clampChannel(linear.g)),
    b: linearToGamma(clampChannel(linear.b)),
  };
}

/** linear sRGB → Oklab. Only used to measure the clip inside the gamut-mapping loop. */
function linearSrgbToOklab({ r, g, b }: Srgb): { L: number; a: number; b: number } {
  const l = Math.cbrt(0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b);
  const m = Math.cbrt(0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b);
  const s = Math.cbrt(0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b);

  return {
    L: 0.2104542553 * l + 0.793617785 * m - 0.0040720468 * s,
    a: 1.9779984951 * l - 2.428592205 * m + 0.4505937099 * s,
    b: 0.0259040371 * l + 0.7827717662 * m - 0.808675766 * s,
  };
}

export function toHex(color: Oklch): string {
  const { r, g, b } = oklchToSrgb(color);
  const byte = (n: number) =>
    Math.round(clampChannel(n) * 255)
      .toString(16)
      .padStart(2, '0');
  return `#${byte(r)}${byte(g)}${byte(b)}`.toUpperCase();
}

/** WCAG 2.2 relative luminance of a gamma-encoded sRGB triple. */
export function relativeLuminance({ r, g, b }: Srgb): number {
  const channel = (n: number) => {
    const v = clampChannel(n);
    return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
  };
  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

/**
 * WCAG 2.2 contrast ratio, 1–21.
 *
 * Both arguments must be OPAQUE. A translucent token (`--overlay`) has no contrast ratio until it
 * is composited over a known backdrop — pass the composited colour, do not pass the token.
 */
export function contrastRatio(a: Oklch, b: Oklch): number {
  const la = relativeLuminance(oklchToSrgb(a));
  const lb = relativeLuminance(oklchToSrgb(b));
  const [hi, lo] = la >= lb ? [la, lb] : [lb, la];
  return (hi + 0.05) / (lo + 0.05);
}
