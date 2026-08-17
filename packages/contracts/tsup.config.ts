import { defineConfig } from 'tsup';

// A real build is mandatory, not a convenience: the typecheck of apps/mobile
// against the BUILT output of this package (Jest there, Vitest here), and dist/ is gitignored —
// so `pnpm contracts:build` runs before the mobile job or it fails on missing types.
export default defineConfig({
  // Explicit entry map rather than an array: with an array tsup infers the output layout from the
  // common root of the entries, and `src/index.ts` + `src/forms/index.ts` would collide on
  // dist/index.js the day someone removes one of them.
  entry: {
    index: 'src/index.ts',
    'forms/index': 'src/forms/index.ts',
  },
  // Both formats: apps/mobile runs Jest, which resolves the `require` condition.
  format: ['esm', 'cjs'],
  dts: true,
  clean: true,
  sourcemap: true,
  treeshake: true,
  splitting: false,
  target: 'es2022',
  // Zod is an OPTIONAL PEER (ADR-028). Bundling it here would put the whole library inside the
  // widget's 30 kB brotli app-shell budget via the root entry's import graph, and relying on
  // tree-shaking to remove it again is exactly the silently-passing size gate preact-vite-library
  // warns about.
  external: ['zod'],
});
