---
name: tailwind-shadcn
description: The design system for apps/web — Tailwind CSS v4's CSS-first configuration, shadcn/ui as vendored source, and how one bot's brand colours reach the UI without a per-tenant build or a line of tenant-supplied CSS. Use whenever editing globals.css, running the shadcn CLI, adding a token or variant, theming a bot, or debugging a colour that applies in dev and vanishes in prod. There is no tailwind.config.js in v4. Pairs with nextjs-app-router (which renders the tokens) and kb-security-baseline (which owns output safety).
---

# Tailwind CSS v4 + shadcn/ui in `apps/web`

Tailwind CSS **4.3.3** (`@tailwindcss/postcss` 4.3.3) · shadcn CLI **4.16.1** and its `shadcn/tailwind.css` runtime · `radix-ui` 1.6.7 · `tw-animate-css` 1.4.0 · `class-variance-authority` 0.7.1 · `tailwind-merge` 3.6.0 · `clsx` 2.1.1 · `lucide-react` 1.28.0 · `next-themes` 0.4.6.
**Authoritative spec:** docs/05-tech-stack.md §9.1 §9.2, docs/04-functional-channels-chat.md §8.18–8.20, docs/02-functional-auth-tenancy-bots.md §8.3, docs/11-data-model.md §16.3, docs/19-repo-structure-adrs.md §27 (`packages/design-tokens`), ADR-007

## Non-negotiables

- **A tenant supplies token *values*, never CSS.** `bots.theme_configuration` (§16.3) is a fixed key set of scalars validated against an exact grammar, not a stylesheet, not a class name, not a `style` string. Interpolating a tenant string into a `<style>` element is CSS injection: `}` closes the rule and everything after it is attacker CSS, and `url()` in a matched selector is an unauthenticated outbound GET — the same exfiltration channel `kb-security-baseline` blocks for model-emitted images. Output safety is owned there; this skill owns only the styling-shaped part of it.
- **The Next.js server emits no organization-scoped byte, and that includes colours.** Hosted chat is cached by `publicBotId`, which *is* in the URL; the admin console is not cached at all. A theme document keyed by anything other than the bot id — or an admin route that becomes cacheable because someone wanted to memoize a palette — serves one customer's brand, and their bot's identity, to another (`kb-tenancy-isolation`, `nextjs-app-router`).
- **Styling never re-opens the model-output sanitizer.** One renderer serves hosted chat, the widget, the playground, and conversation review; the admin surface must not get a more permissive one. Style what the sanitizer emits (`markdown-it` → DOMPurify → `replaceChildren`, allow-list in `kb-security-baseline`). Never reach for a prose/typography plugin that wants raw HTML, never add `img` back to `ALLOWED_TAGS` to make an avatar render, never style `[data-*]` hooks the model can write.
- **The widget's bundle budget is a boundary, not a preference.** ADR-007 buys isolation with an iframe; the budget buys first paint on a stranger's page. Nothing from `components/ui/` crosses into `apps/widget` — see *What the widget takes* below for the line and why it is drawn there.
- **Contrast is derived by us, not chosen by the customer.** §8.18 requires keyboard access and screen-reader labels; a brand colour that lands white-on-white or fails 4.5:1 defeats that just as thoroughly as a missing `aria-label`. Every `-foreground` pairing is computed from the supplied surface colour, never accepted from the form.

## How we use it

```
apps/web/src/app/globals.css   the entire Tailwind config. There is no tailwind.config.js.
apps/web/src/components/ui/**  shadcn output — vendored source we own and edit
apps/web/src/components/**     our components; import from components/ui
apps/web/src/lib/utils.ts      cn() = twMerge(clsx(...))
apps/web/components.json       CLI config: style, base, baseColor, aliases. Stays at the app
                               root — the CLI resolves it from there — but every alias in it
                               points into `src/`, and the CLI writes files where they point.
packages/design-tokens/        tokens.css (the :root defaults) + tokens.json — data, shared with apps/widget
```

`apps/web` uses the **`src/` layout** — `nextjs-app-router`, `rhf-zod-forms`, `tanstack-query-table` and the CI lint step all address it that way, and `tsconfig` maps `@/*` → `src/*`. Every relative path below counts from `src/`; get that wrong and `@source`/`@reference` resolve to nothing and fail silently (Gotchas).

### The config is CSS now

v4 deleted the JavaScript config. `content`, `theme.extend`, `darkMode`, `plugins`, `safelist` and `corePlugins` have no equivalent object; the directives are `@theme`, `@custom-variant`, `@utility`, `@plugin`, `@source`, `@source inline()`. Do **not** add `@config "../tailwind.config.js"` — the v3 bridge exists, it silently disables `corePlugins`/`safelist`/`separator`, and it splits the source of truth across two files.

```css
/* apps/web/src/app/globals.css */
@import "tailwindcss";
@import "tw-animate-css";
@import "shadcn/tailwind.css";        /* data-open/data-closed variants, keyframes, scroll-fade */
@import "@kb/design-tokens/tokens.css";

@custom-variant dark (&:where(.dark, .dark *));  /* v4 has no darkMode: 'class' */

@theme inline {                        /* `inline` is load-bearing — see Gotcha 1 */
  --color-background: var(--background);
  --color-primary: var(--primary);
  --color-primary-foreground: var(--primary-foreground);
  --radius-lg: var(--radius);
}

@layer base {
  * { @apply border-border outline-ring/50; }   /* v4's default border colour is currentColor */
  body { @apply bg-background text-foreground; }
}
```

Dark mode is `next-themes` with `attribute="class"` plus `suppressHydrationWarning` on `<html>`; the `.dark` block in `tokens.css` re-declares the same token names. Because every utility resolves `var(--primary)` at the element, one class flip re-themes the tree with no re-render.

### Per-tenant branding, on two surfaces

§16.3 names the column and stops there, so the key set is this skill's: the tenant contributes six scalars and nothing else — `primary`, `accent`, `radius`, `logo_object_key`, `avatar_object_key`, `default_mode`. <!-- UNVERIFIED: the spec does not enumerate `theme_configuration`; an ADR should ratify this list before the first migration. --> Laravel validates them on write against a closed grammar (OKLCH triple or 6-digit hex; radius from an enum of rem values; object keys, never URLs) and derives each `-foreground` from the OKLCH lightness — that derivation is the reason the stored colour space is OKLCH rather than hex, not fashion. There is **one Tailwind build for the whole platform**; the tenant only overrides custom properties that `@theme inline` already points every utility at.

- **Hosted chat and the widget iframe** — the whole document belongs to one bot. A route handler emits the tokens as a real stylesheet, so `style-src 'self'` covers it with no nonce and the response stays cacheable by `publicBotId`. A nonced inline `<style>` cannot do this: a nonce must be per-response, and reading it forces the route dynamic, which throws away the caching posture `nextjs-app-router` sets for `(chat)`.
- **The admin console and the theme preview panel** — many bots in one document, and the console chrome must stay neutral. Write the properties onto a scoping element through the CSSOM. MDN is explicit that CSP does not intercept this: *"styles properties that are set directly on the element's `style` property will not be blocked."* A `style="…"` attribute would be blocked, because nonces never apply to attributes.

```tsx
// apps/web/src/components/bot-theme-scope.tsx
'use client';
import { useLayoutEffect, useRef } from 'react';

/** Closed key set. A key absent here can never reach the DOM, whatever Laravel returns. */
const TOKENS = ['primary', 'primary-foreground', 'accent', 'accent-foreground'] as const;
/** Re-validated at render: the row may predate the validator, or have been written by a fixture. */
const OKLCH = /^oklch\(0?\.\d{1,4} 0?\.\d{1,4} \d{1,3}(\.\d{1,2})?\)$/;
const RADIUS = new Set(['0rem', '0.25rem', '0.5rem', '0.625rem', '0.75rem', '1rem']);

export function BotThemeScope(
  { theme, children }: { theme: Record<string, string>; children: React.ReactNode },
) {
  const ref = useRef<HTMLDivElement>(null);

  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    // setProperty(), never el.setAttribute('style', …) and never dangerouslySetInnerHTML:
    // the CSSOM path is not a style attribute, so our nonce-only style-src permits it,
    // and no value can escape its declaration into a new rule.
    for (const key of TOKENS) {
      const value = theme[key];
      if (typeof value === 'string' && OKLCH.test(value)) el.style.setProperty(`--${key}`, value);
      else el.style.removeProperty(`--${key}`);   // fall back to the platform default, never guess
    }
    if (RADIUS.has(theme.radius)) el.style.setProperty('--radius', theme.radius);
  }, [theme]);

  // Utilities read var(--primary) at the element, so the override applies to this subtree only —
  // which is exactly why @theme must be `inline`. Without it the whole-page case still works and
  // this one silently does not, which is why the preview panel is the test that matters.
  return <div ref={ref} className="bg-background text-foreground">{children}</div>;
}
```

### shadcn is vendored source, not a dependency

`shadcn add` copies TSX into `components/ui/` and stops caring. There is no upstream version, no lockfile entry for a component, and no update path — `add --overwrite` replaces your file wholesale and discards every local edit. So: run `add --diff <component>` before any re-add, keep local changes small and reviewable, and treat `components/ui/` as owned code in review. Only the CLI (`shadcn`), the primitives (`radix-ui`), and the runtime helpers (`cva`, `clsx`, `tailwind-merge`, `lucide-react`, `tw-animate-css`) are real dependencies.

`components.json` is pinned to `{"style":"new-york","base":"radix","baseColor":"neutral","cssVariables":true,"rsc":true}`, with `"tailwind":{"css":"src/app/globals.css"}` and every alias resolving through `src/` — `{"components":"@/components","ui":"@/components/ui","lib":"@/lib","utils":"@/lib/utils","hooks":"@/hooks"}` against a `tsconfig` `@/*` → `src/*` mapping. The CLI writes files wherever the aliases point, so a `components.json` still describing a flat `app/` layout scatters shadcn output into a second component tree that compiles, renders, and is invisible to every `rg … apps/web/src` check in this file. `baseColor` and `cssVariables` **cannot be changed after init** without regenerating every component. `base` must be written explicitly and every CI invocation passes `-b radix` — since July 2026 the CLI's default base is Base UI, and an unflagged `add` will quietly install a Base UI component next to Radix ones.

### What the widget takes

`preact-vite-library` owns the budget and has already ruled: no `react` → `preact/compat` alias, and `packages/design-tokens` is one of the three things shared. Two styling-specific consequences follow.

**Tokens travel; components do not.** `tokens.css` is CSS custom properties and `tokens.json` is data, so both cost zero runtime and sharing them is the only thing that keeps a customer's brand identical between hosted chat and the embed. `components/ui/` and `cn()` cannot follow: shadcn components carry Radix (React-only), plus `class-variance-authority`, `tailwind-merge` and `lucide-react` as *runtime* dependencies. `tailwind-merge` alone ships Tailwind's entire class-group map into the bundle so it can resolve duplicate utilities at runtime — a measurable slice of a 30 kB shell budget for a problem eight hand-written components do not have. Use `clsx` in the widget and avoid the conflict patterns rather than importing a resolver for them.

**Tailwind itself may cross; preflight may not.** Tailwind is entirely build-time and each build tree-shakes to its own `@source` scan, so the iframe app running its own Tailwind v4 build costs only the CSS it actually uses and inherits our token names for free. The loader's shadow-root chrome is the opposite case: import `tailwindcss/theme.css` and `tailwindcss/utilities.css` without `tailwindcss/preflight.css` if you use it there at all — preflight is a global reset, and `:host { all: initial }` already does the isolating job better. Gotcha 4 is the deeper reason the loader ships hand-written declarations instead.

Forms → `rhf-zod-forms`. Tables and data fetching → `tanstack-query-table`. Routing, caching, RSC boundary, CSP wiring → `nextjs-app-router`. Widget build, budget and shadow-root chrome → `preact-vite-library`. Sanitizer configuration and CSP directive set → `kb-security-baseline`. Component and visual specs → `vitest-playwright`.

## Gotchas

- **The bot theme preview panel recolours the entire admin console, or shows the default palette no matter what you set.** `@theme` without `inline` resolves `var(--primary)` where the theme block is defined — `:root` — and bakes the result into `--color-primary`, so a subtree override is either ignored or leaks upward. `@theme inline` emits `background-color: var(--primary)` into the utility itself, which is what makes subtree scoping work. Nothing warns; both spellings compile.
- **A brand colour applies in dev and silently vanishes in production, with `Refused to apply inline style…` in the console.** A `style="--primary: …"` attribute was used. CSP nonces cover `<style>` elements and `<link>`, never attributes — allowing attributes needs `'unsafe-inline'` or `'unsafe-hashes'`, and adding either to satisfy a colour reopens the widget's whole style-injection surface. Use the CSSOM (`setProperty`) or a same-origin stylesheet response.
- **An element renders with no background at all — no error, no build warning.** A class name was composed at runtime: `` `bg-${tone}-500` ``, `` `text-[${color}]` ``. Tailwind's scanner is a plain-text pass over source files, not an evaluator, so a name that never appears literally never exists. Pass whole class strings through a lookup object, or `@source inline("bg-{red,green}-500")` for a genuinely closed set — never for tenant colours, whose value space is unbounded by definition.
- **A launcher styled with utilities loses to the customer's `button {}` rule, and raising specificity does not help.** v4 emits utilities inside real `@layer` blocks (v3 did not), and **unlayered CSS beats layered CSS regardless of specificity** — so every plain rule on the host page outranks every Tailwind utility placed in the same tree. This is the CSS-side reason `preact-vite-library` puts the launcher's declarations in a closed shadow root behind `:host { all: initial }`; inside the iframe, layers behave normally and Tailwind is fine.
- **A `className` you pass to a shadcn component does not win, intermittently, and only for recently-added utilities.** `tailwind-merge` carries its own hardcoded map of Tailwind's conflict groups and ships one release per Tailwind minor to extend it — 3.6.0 *"Add support for Tailwind CSS v4.3"*, 3.5.0 for v4.2. A family the installed `tailwind-merge` has never heard of (4.3's `scrollbar-*`, 4.2's `mauve`/`olive`/`mist`/`taupe`) is not a conflict group, so both classes survive `cn()` and stylesheet order — not prop order — decides. Bump `tailwindcss` and `tailwind-merge` in the same PR; Renovate must not split them.
- **`@apply` fails with "Cannot apply unknown utility class" in a CSS module or any non-entry stylesheet.** v4 scopes theme and utilities to the file that imported Tailwind. Add `@reference` to the entry stylesheet at the top — from `src/features/<x>/` that is `@reference "../../app/globals.css";`, counted from the importing file, not from `apps/web` — or better, use `var(--color-…)` directly — `@reference` makes every consuming file re-process the entry stylesheet. <!-- UNVERIFIED: the build-time cost of `@reference` at our scale is not measured; the directive itself is documented. -->
- **Borders turn the colour of their text everywhere you write CSS outside `@layer base`.** v4's default border colour is `currentColor`, not `gray-200`. shadcn's `* { @apply border-border }` hides this inside `apps/web`, so it surfaces first in `apps/widget` or in any component that sets its own border — and it reads as a design bug, not a config change.
- **Focus rings look thin and low-contrast after a v3-era component is pasted in.** `ring` is 1px `currentColor` in v4, not 3px `blue-500`. `ring-3` restores the geometry. The same rename class covers `shadow-sm`→`shadow-xs`, `rounded-sm`→`rounded-xs`, `blur-sm`→`blur-xs` and `outline-none`→`outline-hidden`; all of them compile fine and just look wrong.
- **A class written inside a workspace package produces no CSS in the app that consumes it.** v4's automatic content detection ignores `node_modules`, and pnpm workspace links resolve through `node_modules`, so a shared package's markup is invisible to `apps/web`'s build. Add `@source "../../../../packages/<name>/src";` to `globals.css` for every workspace package that contains class names — four levels, because the entry stylesheet is `apps/web/src/app/globals.css` and not `apps/web/app/globals.css`. A `@source` pointing at a directory that does not exist is not an error in v4; it scans nothing and you get the identical missing-CSS symptom you were trying to fix. `packages/design-tokens` needs none — it ships CSS and JSON, not markup, which is precisely why it is safe for `apps/widget` to share.
- **A `dark:` utility placed on `<html>` itself does nothing.** shadcn's docs ship `@custom-variant dark (&:is(.dark *))`, which matches descendants of `.dark` but not the `.dark` element. Tailwind's own recommendation, `(&:where(.dark, .dark *))`, matches both and keeps specificity at zero — use it, and note that the difference is invisible until someone styles the root element.
- **Hover styles stop working on tablets and someone "fixes" it by re-breaking sticky hover.** v4 wraps `hover:` in `@media (hover: hover)` deliberately, so a touch device never latches a hover state on the composer's send button. This is correct behaviour; supply an explicit pressed/active state instead of overriding the variant.
- **On an older iOS device a surface renders transparent and its text renders black.** An unsupported colour function invalidates the whole declaration, so the element silently falls back to its initial or inherited value rather than erroring. The palette is OKLCH throughout and v4's baseline is Safari 16.4 / Chrome 111 / Firefox 128; there is no fallback layer and adding one means abandoning the token model. Accept the baseline or do not adopt v4 — and check it before a customer's embed target is promised support below it.

## Official docs

- [Tailwind: Upgrade guide (v3 → v4)](https://tailwindcss.com/docs/upgrade-guide) — the full breaking-change list behind most gotchas above.
- [Tailwind: Theme variables](https://tailwindcss.com/docs/theme) — `@theme`, `inline`, `static`, namespaces, `--*: initial`.
- [Tailwind: Dark mode](https://tailwindcss.com/docs/dark-mode) and [Detecting classes](https://tailwindcss.com/docs/detecting-classes-in-source-files) — `@custom-variant`, `@source`, `@source inline()`.
- [Tailwind: Preflight](https://tailwindcss.com/docs/preflight) — importing `theme.css`/`utilities.css` without the reset, for the widget.
- [Tailwind v4.3 release notes](https://tailwindcss.com/blog/tailwindcss-v4-3) — `@tailwindcss/webpack`, scrollbar utilities, stacked `@variant`.
- [shadcn/ui: Theming](https://ui.shadcn.com/docs/theming) and [components.json](https://ui.shadcn.com/docs/components-json) — the token convention and every config field.
- [shadcn/ui: Base UI as the default](https://ui.shadcn.com/docs/changelog/2026-07-base-ui-default) — why `-b radix` is now mandatory for us.
- [MDN: CSP `style-src`](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Content-Security-Policy/style-src) — nonces do not cover attributes; CSSOM writes are not intercepted.
- [MDN: `@layer` cascade layers](https://developer.mozilla.org/en-US/docs/Web/CSS/@layer) — unlayered beats layered (Gotcha 4).

## Definition of done

- [ ] No `tailwind.config.{js,ts}` and no `@config` anywhere in `apps/`; `rg '@tailwind (base|components|utilities)'` is empty
- [ ] Every colour utility in `globals.css` is declared under `@theme inline`, verified by a test that overrides `--primary` on a wrapper and asserts the child's computed `background-color` changed while a sibling outside the wrapper did not
- [ ] No tenant value reaches the DOM through a `style` attribute, `dangerouslySetInnerHTML`, or string-interpolated `<style>`; `rg 'dangerouslySetInnerHTML' apps/web` returns nothing under theming code
- [ ] A theme fixture containing `oklch(.5 .1 20); } body { background: url(https://evil/) `, a `var(--x)`, and a `calc()` is rejected by both the Laravel validator and the render-time guard, with the platform default rendered instead
- [ ] Derived `-foreground` pairings meet 4.5:1 against their surface for the full range of accepted `primary` values, asserted in CI over the allowed grammar's bounds
- [ ] `components.json` has `"base": "radix"`; every CI/script `shadcn` invocation passes `-b radix`; `rg '@base-ui' apps/web/package.json` is empty
- [ ] The `src/` layout is intact end to end: `components.json` names `src/app/globals.css`, its aliases resolve through `@/*` → `src/*`, `rg -n 'apps/web/app/' apps packages` returns nothing, and every `@source`/`@reference` in `globals.css` resolves to a directory that exists (delete one and confirm the build still passes — that silence is the reason to check by hand)
- [ ] `apps/widget` imports nothing from `apps/web`; `rg 'tailwind-merge|class-variance-authority|components/ui' apps/widget` is empty; `packages/design-tokens` is the only styling import it shares
- [ ] Nothing in the loader bundle imports `tailwindcss/preflight.css`; the iframe document may
- [ ] `tailwindcss` and `tailwind-merge` bumped in the same PR, with a merge test over any newly added utility family
- [ ] Hosted chat's theme response is keyed by `publicBotId` only and carries no session-derived value; no admin route gained a cache directive (`nextjs-app-router`)
- [ ] Model output still renders through the single sanitizer path; no styling change added a tag, attribute, or plugin to it (`kb-security-baseline`)
