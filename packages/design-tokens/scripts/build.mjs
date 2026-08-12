#!/usr/bin/env node
// Node builtins ONLY. This package declares zero dependencies and zero devDependencies because
// .npmrc sets shared-workspace-lockfile=false — one devDependency here writes a fifth
// pnpm-lock.yaml and breaks the four-lockfile contract security-scanning-toolchain scans.
//
// Output goes to generated/, NOT dist/: the root .gitignore ignores dist/, and this output must be
// COMMITTED so CI can run `pnpm tokens:build && git diff --exit-code` — the same generate-and-diff
// gate the repo uses for packages/contracts/rules.
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const pkg = join(here, '..');
const outDir = join(pkg, 'generated');

/** @type {{radius: {default: string, enum: string[]}, tenantOverridable: string[], colors: Record<string, {light: string, dark: string}>}} */
const src = JSON.parse(readFileSync(join(pkg, 'src', 'tokens.json'), 'utf8'));

const banner = [
  '/* GENERATED FILE — do not edit.',
  ' * Source: packages/design-tokens/src/tokens.json',
  ' * Regenerate: pnpm tokens:build',
  ' * Committed on purpose: CI runs the build and then `git diff --exit-code`.',
  ' */',
  '',
].join('\n');

const q = (s) => `'${s}'`;
const union = (values) => values.map(q).join(' | ');
const entries = Object.entries(src.colors);

// --- tokens.css -------------------------------------------------------------------------------
// Plain custom properties in :root and .dark. No @theme here: the @theme inline block lives in
// apps/web/src/app/globals.css, because `inline` is what makes a subtree override work and this
// file is also imported by apps/widget, which runs its own Tailwind build.
const css = [banner, ':root {', `  --radius: ${src.radius.default};`];
for (const [name, pair] of entries) css.push(`  --${name}: ${pair.light};`);
css.push('}', '', '.dark {');
for (const [name, pair] of entries) css.push(`  --${name}: ${pair.dark};`);
css.push('}', '');

// --- index.js ---------------------------------------------------------------------------------
const js = [banner, 'export const colors = Object.freeze({'];
for (const [name, pair] of entries) {
  js.push(`  ${q(name)}: Object.freeze({ light: ${q(pair.light)}, dark: ${q(pair.dark)} }),`);
}
js.push('});', '');
js.push(`export const DEFAULT_RADIUS = ${q(src.radius.default)};`, '');
js.push(`export const RADIUS_VALUES = Object.freeze([${src.radius.enum.map(q).join(', ')}]);`, '');
js.push(
  `export const TENANT_OVERRIDABLE_COLOR_KEYS = Object.freeze([${src.tenantOverridable.map(q).join(', ')}]);`,
  '',
);

// --- index.d.ts -------------------------------------------------------------------------------
const dts = [
  banner,
  `export type TokenName = ${union(Object.keys(src.colors))};`,
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
  'export declare const colors: Readonly<Record<TokenName, TokenPair>>;',
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
writeFileSync(join(outDir, 'index.js'), js.join('\n'), 'utf8');
writeFileSync(join(outDir, 'index.d.ts'), dts.join('\n'), 'utf8');

console.log(`design-tokens: wrote ${entries.length} colours + radius to generated/`);
