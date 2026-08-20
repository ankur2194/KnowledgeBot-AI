#!/usr/bin/env node
// Node builtins ONLY. This package declares zero dependencies and zero devDependencies because
// .npmrc sets shared-workspace-lockfile=false — one devDependency here writes a fifth
// pnpm-lock.yaml and breaks the four-lockfile contract security-scanning-toolchain scans.
//
// Output goes to generated/, NOT dist/: the root .gitignore ignores dist/, and this output must be
// COMMITTED so CI can run `pnpm tokens:build && git diff --exit-code` — the same generate-and-diff
// gate the repo uses for packages/contracts/rules.
//
// FOUR artifacts, and the second is the one that exists to stop a silent failure:
//   generated/tokens.css   the :root / .dark custom properties
//   generated/theme.css    the `@theme inline` alias body — GENERATED rather than hand-maintained,
//                          because a token that is used by a utility but missing from that block
//                          still works everywhere EXCEPT a scoped override (the bot theme preview),
//                          which is the one place no unit test looks (kb-design-language →
//                          references/cross-platform.md; tailwind-shadcn Gotcha 1).
//   generated/index.js     the values as data, for the theme validator and the contrast test
//   generated/index.d.ts   its types
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const pkg = join(here, '..');
const outDir = join(pkg, 'generated');

const src = JSON.parse(readFileSync(join(pkg, 'src', 'tokens.json'), 'utf8'));

const banner = [
  '/* GENERATED FILE — do not edit.',
  ' * Source: packages/design-tokens/src/tokens.json',
  ' * Regenerate: pnpm tokens:build',
  ' * Committed on purpose: CI runs the build and then `git diff --exit-code`.',
  ' */',
  '',
].join('\n');

const q = (s) => `'${s.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
const union = (values) => values.map(q).join(' | ');

const colors = Object.entries(src.colors);
const legacy = Object.entries(src.legacyColors);
const shadows = Object.entries(src.shadows);
const radii = Object.entries(src.radiusScale);
const space = Object.entries(src.space);
const spaceAliases = Object.entries(src.spaceAliases);
const type = Object.entries(src.type);
const durations = Object.entries(src.motion.durations);
const easings = Object.entries(src.motion.easings);

// --- tokens.css ---------------------------------------------------------------------------------
// Plain custom properties in :root and .dark. No @theme here — that lives in theme.css, because
// apps/widget imports THIS file and runs its own Tailwind build with its own narrower alias block.
const css = [banner, ':root {'];
// THE DERIVED STEPS HERE ARE THE ROOT-LEVEL VALUES ONLY, and they are NOT what `rounded-*` reads.
// A custom property is substituted at the element that DECLARES it, so `--radius-sm` computed here
// is a fixed length that descendants inherit — writing `--radius` onto a nested element (the bot
// theme preview, `apps/web/src/components/bot-theme-scope.tsx`) cannot move it. These declarations
// exist for the handful of stylesheets that read `var(--radius-md)` directly at the root
// (`apps/web/src/app/globals.css`'s `kb-skeleton` and prose rules) and for byte-for-byte parity with
// apps/widget, which imports this file. The utilities get the UNSUBSTITUTED calc from theme.css
// instead — see the radius block there.
css.push('  /* radius — tenant-set, everything else derives from it */');
css.push(`  --radius: ${src.radius.default};`);
for (const [name, value] of radii) css.push(`  --radius-${name}: ${value};`);
css.push('', '  /* colour */');
for (const [name, pair] of colors) css.push(`  --${name}: ${pair.light};`);
css.push('', '  /* DEPRECATED — apps/widget only. See _legacyColors_note in tokens.json. */');
for (const [name, pair] of legacy) css.push(`  --${name}: ${pair.light};`);
css.push('', '  /* elevation */');
for (const [name, pair] of shadows) css.push(`  --shadow-${name}: ${pair.light};`);
css.push('', '  /* space */');
for (const [name, value] of space) css.push(`  --space-${name}: ${value};`);
for (const [name, value] of spaceAliases) css.push(`  --${name}: ${value};`);
css.push('', '  /* type — size, line-height, weight and tracking travel together */');
css.push(`  --font-sans: ${src.fonts.sans};`);
css.push(`  --font-mono: ${src.fonts.mono};`);
for (const [name, t] of type) {
  css.push(
    `  --text-${name}: ${t.size}; --text-${name}-lh: ${t.lineHeight};` +
      ` --text-${name}-w: ${t.weight}; --text-${name}-ls: ${t.tracking};`,
  );
}
css.push('', '  /* motion */');
for (const [name, value] of durations) css.push(`  --${name}: ${value};`);
for (const [name, value] of easings) css.push(`  --ease-${name}: ${value};`);
css.push('}', '');

// In dark the card is LIGHTER than the canvas, and the inset ring in each shadow — not the shadow —
// is what separates them. Only the mode-dependent families are re-declared.
css.push('.dark {');
for (const [name, pair] of colors) css.push(`  --${name}: ${pair.dark};`);
css.push('', '  /* DEPRECATED — apps/widget only. */');
for (const [name, pair] of legacy) css.push(`  --${name}: ${pair.dark};`);
css.push('');
for (const [name, pair] of shadows) css.push(`  --shadow-${name}: ${pair.dark};`);
css.push('}', '');

// --- prefers-color-scheme, for the surfaces that have NO theme switcher --------------------------
// MEASURED, not speculative: `apps/web`'s admin console mounts next-themes, which always writes
// `light` or `dark` onto <html>. HOSTED CHAT DELIBERATELY MOUNTS NO THEME PROVIDER — one bot, one
// anonymous visitor, mode from the bot's configuration — so its <html> carries NO class at all, and
// with `.dark` as the only dark selector that surface could render ONLY light, forever. Verified by
// reading `document.documentElement.className` on both surfaces at both OS settings: admin
// "light"/"dark", chat ""/"".
//
// That breaks two rules at once: "prefers-color-scheme is honoured on first paint" is an
// accessibility requirement, not a polish item (a flash — or a permanent lock — of the wrong theme
// is a real problem for light-sensitive users), and "a dark mode that exists only on the admin" is
// named as a failure of the design language.
//
// THE GUARD IS WHAT MAKES THIS SAFE. `:root:not(.light):not(.dark)` matches only where NOTHING has
// made an explicit choice, so it can never override a user who picked light on a machine set to
// dark. Where a switcher exists one of those classes is always present and this block is inert.
css.push('@media (prefers-color-scheme: dark) {');
css.push('  :root:not(.light):not(.dark) {');
for (const [name, pair] of colors) css.push(`    --${name}: ${pair.dark};`);
for (const [name, pair] of legacy) css.push(`    --${name}: ${pair.dark};`);
for (const [name, pair] of shadows) css.push(`    --shadow-${name}: ${pair.dark};`);
css.push('  }', '}', '');

// --- theme.css ----------------------------------------------------------------------------------
// `inline` is LOAD-BEARING. Without it Tailwind resolves var(--primary) where the block is defined
// (:root) and bakes the result, so a subtree override — the bot theme preview panel — is silently
// ignored. With it the utility emits `background-color: var(--primary)` and resolves at the element.
//
// The `--x-*: initial` resets CLOSE each namespace: after them `bg-red-500`, `text-2xl`,
// `rounded-4xl` and `shadow-2xl` do not compile. That is the point — "no literal colour, shadow,
// radius or type size in a component" stops being a review convention and becomes a build error.
//
// THE SELF-REFERENCES BELOW ARE CORRECT AND DELIBERATE — do not "fix" them.
// For every family whose token name already IS the Tailwind namespace name (--shadow-md, --text-h1,
// --font-sans, --ease-out) this block emits `--shadow-md: var(--shadow-md)`, which reads like a
// cycle. It is inert, and the reason is the cascade rather than the compiler: Tailwind emits the
// theme block inside `@layer theme`, while tokens.css declares the same names in an UNLAYERED
// `:root` — and unlayered CSS beats layered CSS regardless of specificity, so the real value always
// wins and the cycle never resolves. Verified against tailwindcss 4.3.3 by compiling this file and
// reading the output: `.shadow-md{box-shadow:var(--shadow-md)}` resolving to the ladder's step, and
// `.text-h1` emitting all four paired properties. The one way to break it is to import tokens.css
// INTO a layer — then the cycle wins and every shadow and type size silently disappears. Keep the
// import unlayered.
//
// RADIUS IS THE ONE FAMILY THAT MUST NOT TAKE THAT SHAPE, and it is the only family whose steps are
// DERIVED from another property at render time rather than being literal values. `--radius-sm:
// var(--radius-sm)` compiles to `.rounded-sm{border-radius:var(--radius-sm)}`, and `--radius-sm` is
// declared once on `:root` as `max(0px, calc(var(--radius) - 4px))` — substituted there, so every
// descendant inherits the ROOT's arithmetic. Writing `--radius` onto a nested element then moves
// nothing: `rounded-*` inside a bot theme scope silently keeps the platform radius, while the same
// override at `:root` (hosted chat's `theme.css` route) works, which is why no whole-document test
// can see it. Emitting the CALC ITSELF here puts the expression in the utility —
// `.rounded-sm{border-radius:max(0px, calc(var(--radius) - 4px))}` — where it resolves against the
// `--radius` the ELEMENT inherits. `tests/components/design-system-css.test.tsx` pins this by
// setting the property on a nested element rather than on documentElement.
const theme = [
  banner,
  '@theme inline {',
  '  /* ---- colour: the palette is CLOSED. Only the keywords survive the reset. ---- */',
  '  --color-*: initial;',
  '  --color-transparent: transparent;',
  '  --color-current: currentColor;',
  '  --color-inherit: inherit;',
];
for (const [name] of colors) theme.push(`  --color-${name}: var(--${name});`);
theme.push('', '  /* DEPRECATED — apps/widget parity only; nothing in apps/web may use these. */');
for (const [name] of legacy) theme.push(`  --color-${name}: var(--${name});`);

theme.push(
  '',
  '  /* ---- radius: the CALC, not var(--radius-n) — the utility must resolve at the element ---- */',
  '  --radius-*: initial;',
);
for (const [name, value] of radii) theme.push(`  --radius-${name}: ${value};`);

theme.push('', '  /* ---- elevation: pick a step, never blend or invent an offset ---- */', '  --shadow-*: initial;');
for (const [name] of shadows) theme.push(`  --shadow-${name}: var(--shadow-${name});`);

theme.push('', '  /* ---- space: the numeric 4px grid is Tailwind\'s default and matches --space-*;');
theme.push('       these are the named composites so `p-card-pad-md` and `px-gutter-lg` exist ---- */');
for (const [name] of spaceAliases) theme.push(`  --spacing-${name}: var(--${name});`);

theme.push('', '  /* ---- type: closed, and each step carries all four values ---- */');
theme.push('  --text-*: initial;');
for (const [name] of type) {
  theme.push(`  --text-${name}: var(--text-${name});`);
  theme.push(`  --text-${name}--line-height: var(--text-${name}-lh);`);
  theme.push(`  --text-${name}--font-weight: var(--text-${name}-w);`);
  theme.push(`  --text-${name}--letter-spacing: var(--text-${name}-ls);`);
}
theme.push('', '  --font-sans: var(--font-sans);', '  --font-mono: var(--font-mono);');

theme.push('', '  /* ---- motion: durations have no v4 namespace and are reached as duration-(--dur-2) ---- */');
for (const [name] of easings) theme.push(`  --ease-${name}: var(--ease-${name});`);
theme.push('}', '');

// --- index.js -----------------------------------------------------------------------------------
const pairObject = (entries) =>
  entries.map(([name, p]) => `  ${q(name)}: Object.freeze({ light: ${q(p.light)}, dark: ${q(p.dark)} }),`);
const flatObject = (entries) => entries.map(([name, v]) => `  ${q(name)}: ${q(v)},`);

const js = [banner];
js.push('export const colors = Object.freeze({', ...pairObject(colors), '});', '');
js.push('/** DEPRECATED shadcn-inheritance names kept alive for apps/widget. Do not use. */');
js.push('export const legacyColors = Object.freeze({', ...pairObject(legacy), '});', '');
js.push('export const shadows = Object.freeze({', ...pairObject(shadows), '});', '');
js.push('export const radiusScale = Object.freeze({', ...flatObject(radii), '});', '');
js.push('export const space = Object.freeze({', ...flatObject(space), ...flatObject(spaceAliases), '});', '');
js.push('export const fonts = Object.freeze({', `  sans: ${q(src.fonts.sans)},`, `  mono: ${q(src.fonts.mono)},`, '});', '');
js.push('export const type = Object.freeze({');
for (const [name, t] of type) {
  js.push(
    `  ${q(name)}: Object.freeze({ size: ${q(t.size)}, lineHeight: ${q(t.lineHeight)},` +
      ` weight: ${q(t.weight)}, tracking: ${q(t.tracking)} }),`,
  );
}
js.push('});', '');
js.push('export const motion = Object.freeze({');
js.push('  durations: Object.freeze({', ...flatObject(durations).map((l) => `  ${l}`), '  }),');
js.push('  easings: Object.freeze({', ...flatObject(easings).map((l) => `  ${l}`), '  }),');
js.push('});', '');
js.push(`export const DEFAULT_RADIUS = ${q(src.radius.default)};`, '');
js.push(`export const RADIUS_VALUES = Object.freeze([${src.radius.enum.map(q).join(', ')}]);`, '');
js.push(
  `export const TENANT_OVERRIDABLE_COLOR_KEYS = Object.freeze([${src.tenantOverridable.map(q).join(', ')}]);`,
  '',
);

// --- index.d.ts ---------------------------------------------------------------------------------
const dts = [
  banner,
  `export type TokenName = ${union(colors.map(([n]) => n))};`,
  '',
  `export type LegacyTokenName = ${union(legacy.map(([n]) => n))};`,
  '',
  `export type ShadowName = ${union(shadows.map(([n]) => n))};`,
  '',
  `export type RadiusStep = ${union(radii.map(([n]) => n))};`,
  '',
  `export type SpaceName = ${union([...space, ...spaceAliases].map(([n]) => n))};`,
  '',
  `export type TypeStep = ${union(type.map(([n]) => n))};`,
  '',
  `export type DurationName = ${union(durations.map(([n]) => n))};`,
  '',
  `export type EasingName = ${union(easings.map(([n]) => n))};`,
  '',
  `export type TenantOverridableColorKey = ${union(src.tenantOverridable)};`,
  '',
  `export type RadiusValue = ${union(src.radius.enum)};`,
  '',
  'export interface TokenPair {',
  '  readonly light: string;',
  '  readonly dark: string;',
  '}',
  '',
  'export interface TypeStepValues {',
  '  readonly size: string;',
  '  readonly lineHeight: string;',
  '  readonly weight: string;',
  '  readonly tracking: string;',
  '}',
  '',
  'export declare const colors: Readonly<Record<TokenName, TokenPair>>;',
  '',
  '/** DEPRECATED shadcn-inheritance names kept alive for apps/widget. Do not use. */',
  'export declare const legacyColors: Readonly<Record<LegacyTokenName, TokenPair>>;',
  '',
  'export declare const shadows: Readonly<Record<ShadowName, TokenPair>>;',
  '',
  'export declare const radiusScale: Readonly<Record<RadiusStep, string>>;',
  '',
  'export declare const space: Readonly<Record<SpaceName, string>>;',
  '',
  'export declare const fonts: Readonly<{ sans: string; mono: string }>;',
  '',
  'export declare const type: Readonly<Record<TypeStep, TypeStepValues>>;',
  '',
  'export declare const motion: Readonly<{',
  '  durations: Readonly<Record<DurationName, string>>;',
  '  easings: Readonly<Record<EasingName, string>>;',
  '}>;',
  '',
  'export declare const DEFAULT_RADIUS: RadiusValue;',
  '',
  'export declare const RADIUS_VALUES: readonly RadiusValue[];',
  '',
  'export declare const TENANT_OVERRIDABLE_COLOR_KEYS: readonly TenantOverridableColorKey[];',
  '',
];

mkdirSync(outDir, { recursive: true });
writeFileSync(join(outDir, 'tokens.css'), css.join('\n'), 'utf8');
writeFileSync(join(outDir, 'theme.css'), theme.join('\n'), 'utf8');
writeFileSync(join(outDir, 'index.js'), js.join('\n'), 'utf8');
writeFileSync(join(outDir, 'index.d.ts'), dts.join('\n'), 'utf8');

console.log(
  `design-tokens: ${colors.length} colours (+${legacy.length} legacy), ${shadows.length} shadows, ` +
    `${radii.length} radii, ${space.length + spaceAliases.length} space, ${type.length} type steps, ` +
    `${durations.length} durations, ${easings.length} easings → generated/`,
);
