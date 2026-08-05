# The widget Vite config — reference

Depth for `preact-vite-library`. Spec: docs/05-tech-stack.md §9.2, docs/19-repo-structure-adrs.md ADR-007 ADR-008.
`apps/widget/vite.config.ts` in full — two configs in one file selected by `--mode`, the Vite 8 `oxc` / `rolldownOptions` names, the dev-only preset plugin, the pinned `build.target`, the loader's single-file iife output, and the `define` block that bakes in `__KB_VERSION__`, `__KB_WIDGET_ORIGIN__` and `__KB_API_ORIGIN__` and throws at build time on an unset domain. The budget, the isolation model, the loader bootstrap and every gotcha stay in `SKILL.md`.

```ts
// apps/widget/vite.config.ts — two configs in one file, selected by `--mode`.
import { defineConfig } from 'vite'
import preact from '@preact/preset-vite'

const env = (k: string) => process.env[k] ?? ((): never => { throw new Error(`[kb] ${k} unset`) })()

export default defineConfig(({ mode, command }) => ({
  // Vite 8 names: `oxc` replaces `esbuild`, `build.rolldownOptions` replaces `build.rollupOptions`.
  // The old names still auto-convert, which is exactly why they must not be written here.
  oxc: { jsx: { runtime: 'automatic', importSource: 'preact' } },
  // preset-vite exists for prefresh HMR and pulls @babel/core; production JSX is Oxc's job.
  plugins: command === 'serve' ? [preact()] : [],
  // Our visitors are the customers' visitors, not ours. Pin it; re-run size-limit after any change,
  // because lowering the floor inflates the output. Default is 'baseline-widely-available'.
  build: { target: ['chrome111', 'edge111', 'firefox114', 'safari16.4', 'ios16.4'],
    ...(mode === 'loader'
      ? { outDir: 'dist/loader', cssCodeSplit: false,
          // `name` is mandatory for iife/umd. One file, no chunks: the loader must never
          // emit a second request into a page whose CSP we have not seen.
          lib: { entry: 'src/loader/index.ts', name: 'KBWidget', formats: ['iife'], fileName: () => 'kb-widget.js' },
          rolldownOptions: { output: { inlineDynamicImports: true } } }
      : { outDir: 'dist/app', modulePreload: { polyfill: false } }) },
  // Baked in, never read from `iframe.src` or `currentScript.src` — both host-writable before our code
  // runs. EVERY `__KB_*` either bundle mentions is declared HERE; one declared only in `src/env.d.ts`
  // type-checks and then ships as a bare identifier. `DOMAIN`/`WIDGET_DOMAIN` are Compose's, the two
  // Traefik routes on (`traefik-routing`), and they are different registrable domains by rule; `env()`
  // throws at build time because `?? ''` would bake `https://undefined` onto a customer's page.
  define: { __KB_VERSION__: JSON.stringify(process.env.npm_package_version),
            __KB_WIDGET_ORIGIN__: JSON.stringify(`https://${env('WIDGET_DOMAIN')}`),
            __KB_API_ORIGIN__: JSON.stringify(`https://api.${env('DOMAIN')}`) },
}))
```
