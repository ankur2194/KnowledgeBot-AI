import { Geist_Mono, Inter } from 'next/font/google';

/**
 * The two families the design language names, self-hosted.
 *
 * `next/font/google` downloads and SELF-HOSTS at build time — no request to Google at runtime, which
 * matters here because `proxy.ts` sets `font-src 'self'` and a runtime CDN font would simply not
 * load. It also subsets, and it generates the `size-adjust` / `ascent-override` / `descent-override`
 * fallback metrics that `kb-design-language` asks for: without them the swap from the system stack
 * to Inter shifts every page vertically once on first paint, which reads as a layout bug on a slow
 * connection.
 *
 * The variables are applied to <body>, NOT <html>. `packages/design-tokens` declares --font-sans on
 * `:root`, and a class on the same element would be a specificity TIE decided by stylesheet order —
 * which is not something to build a font stack on. On <body> the declaration is simply closer and
 * wins for the whole subtree, with no ordering assumption.
 */
export const fontSans = Inter({
  subsets: ['latin'],
  display: 'swap',
  variable: '--font-sans',
  // NO `fallback` ARRAY, AND THAT IS THE WHOLE POINT — MEASURED, not assumed. Passing one silently
  // suppresses `adjustFontFallback`, so Next stops emitting the metric-adjusted `Inter Fallback`
  // face and every page shifts vertically once when the real font swaps in. Verified by building
  // both ways: with `fallback` the shipped CSS contains zero `ascent-override` declarations and two
  // @font-face families; without it, two more appear (`Inter Fallback`, `Geist Mono Fallback`)
  // carrying `size-adjust` / `ascent-override` / `descent-override`. Nothing warns, and the symptom
  // reads as a layout bug rather than a font-config one.
});

/** Request ids, object keys, model ids and code blocks. Nothing else. */
export const fontMono = Geist_Mono({
  subsets: ['latin'],
  display: 'swap',
  variable: '--font-mono',
  // Same reason as above: no `fallback`, so the adjusted fallback face survives.
});

/** The className every root layout puts on <body>. One spelling, three layouts. */
export const fontClassName = `${fontSans.variable} ${fontMono.variable}`;
