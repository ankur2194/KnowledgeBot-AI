# One token set, four consumers

The reason this file exists: a customer configures a bot in the admin console, embeds it on their site, and opens it on their phone. Those are three runtimes with three styling models and no shared cascade, and they have to look like one product. The only mechanism that survives that is **one source file, four generated entry points, and no hand-copied value anywhere**.

`packages/design-tokens/src/tokens.json` is the source. `packages/design-tokens/scripts/build.mjs` is the emitter — Node builtins only, zero dependencies and zero devDependencies, because `.npmrc` sets `shared-workspace-lockfile=false` and one devDependency here writes a fifth `pnpm-lock.yaml` and breaks the lockfile contract `security-scanning-toolchain` scans. Output lands in `generated/`, not `dist/`, and is **committed**, so CI runs `pnpm tokens:build && git diff --exit-code`.

## What the build emits

| Artifact | Consumer | Shape |
| --- | --- | --- |
| `generated/tokens.css` | `apps/web`, and the widget's iframe app if it ever needs the full set | `:root { … }` + `.dark { … }`, plain custom properties, no `@theme` |
| `generated/tokens.widget.css` | `apps/widget` iframe app | A **strict subset** of the above, both blocks, listing only the tokens the widget actually uses |
| `generated/index.js` + `index.d.ts` | anything that needs the values as data — the theme validator, the contrast test, Storybook | The parsed object plus the `tenantOverridable` union type |
| `generated/tokens.mobile.js` + `.d.ts` | `apps/mobile` | Two **fully resolved** objects, `light` and `dark`, with every colour as gamut-mapped **sRGB hex** |

The last two do not exist yet. `build.mjs` currently emits the CSS, the JS object and the types; the widget subset and the mobile object are the two additions this design language requires, and both are `admin-web-engineer`'s to write.

## `apps/web` — CSS custom properties through Tailwind v4

```css
/* apps/web/src/app/globals.css */
@import "@kb/design-tokens/tokens.css";

@theme inline {
  /* Every token a utility resolves MUST be aliased here. A token that is not
   * aliased still works for var(--x) written by hand, and silently breaks the
   * bot theme preview panel, because @theme without `inline` bakes the value at
   * :root instead of emitting var() into the utility. tailwind-shadcn Gotcha 1. */
  --color-canvas: var(--canvas);
  --color-card: var(--card);
  --color-card-inset: var(--card-inset);
  --color-primary: var(--primary);
  --color-primary-soft: var(--primary-soft);
  --color-tone-sky-surface: var(--tone-sky-surface);
  /* …one line per token in every family the app uses… */
  --radius-lg: var(--radius-lg);
  --shadow-md: var(--shadow-md);
  --font-sans: var(--font-sans);
}
```

The mapping is mechanical and long, and being mechanical it should be **generated too** — emit the `@theme inline` body from `tokens.json` rather than maintaining it by hand, or the two drift and the failure is silent in exactly one place (a scoped override) that no unit test looks at.

Dark mode is `next-themes` with `attribute="class"`, and `@custom-variant dark (&:where(.dark, .dark *))` — the `:where` form, not shadcn's `:is(.dark *)`, which does not match the `.dark` element itself.

## `apps/widget` — two different problems in one app

**The iframe app** runs its own Tailwind v4 build and imports `tokens.widget.css`. It needs a trimmed entry because **CSS custom properties in a plain `:root` block are not tree-shaken by anything.** Tailwind removes unused *utilities*; it does not remove unused *declarations* you imported. The full token set is dead weight measured against a brotli shell budget that `preact-vite-library` owns and `size-limit` enforces in CI.

The subset must be verified, not trusted:

```
# CI: the widget entry is a strict subset, in BOTH blocks
node -e '…parse both files, assert every name in tokens.widget.css exists in tokens.css,
         and that each name appears in the :root block AND the .dark block of the widget file…'
```

Comparing only names-present is the check that passes while dark mode is broken: the usual drift is a token added to `:root` and forgotten in `.dark`, which renders the widget's dark mode with an inherited or initial value and no error.

**The loader's shadow root** cannot use either file. `:host { all: initial }` is what isolates the launcher from a hostile page's CSS, and `all: initial` cuts inheritance of custom properties along with everything else. So the loader re-declares the handful of tokens its chrome needs, directly on `:host`, as literal values — and this is the **one sanctioned place a token value appears outside the generated files**. It carries a comment naming the token it mirrors, and it is small enough to review:

```js
// apps/widget/src/loader/host-styles.js
// Mirrors --primary, --primary-foreground, --card, --shadow-lg, --radius-full from
// @kb/design-tokens. :host { all: initial } cuts inheritance, so these cannot be var()
// references to anything outside the shadow root. Keep this list under ten entries; if it
// grows, the launcher is doing too much and belongs in the iframe.
```

The launcher also has a cascade problem that no token solves: **v4 emits utilities inside real `@layer` blocks, and unlayered CSS beats layered CSS regardless of specificity**, so every plain `button {}` rule on the customer's page outranks every Tailwind utility in the same tree. That is why the launcher ships hand-written declarations in a closed shadow root rather than classes (`tailwind-shadcn` Gotcha 4, `preact-vite-library`).

## `apps/mobile` — no cascade, no custom properties, no OKLCH

Expo 57.0.10 / React Native 0.86.2. Three things change:

**1. Colour ships as sRGB hex.** React Native's colour parser is not a browser's, and this repo has not verified its OKLCH support on both platforms and both architectures. <!-- UNVERIFIED: RN's supported colour syntaxes on 0.86.2 have not been checked on-device here; docs/23 already records that no mobile behaviour has been verified on a device. --> The build converts by **gamut-mapping** into sRGB, not by clipping: clipping preserves the hue angle and destroys the lightness relationship, and the first thing that breaks is the 0.055 canvas/card gap that the whole two-plane model rests on.

**2. There is no cascade, so the theme is resolved once and passed down.** No `.dark` class, no `var()`. A provider selects `light` or `dark` from `useColorScheme()` and puts the resolved object on context; `StyleSheet.create` reads from it. A component that reaches for a raw hex, or that builds its own `Platform.select` palette, has forked the design system on the one surface where nobody will notice for months.

```ts
// apps/mobile/src/theme/provider.tsx — the shape, not the whole file
import { light, dark } from '@kb/design-tokens/tokens.mobile';
// light/dark are generated. Never spread a local override into them: an override here
// cannot be seen by the web contrast test, and packages/ is not yours to edit —
// report the missing token to admin-web-engineer instead.
```

**3. Elevation is not `box-shadow` in the general case.** The dark-mode elevation model — a weak shadow plus an inset light ring — has no direct RN equivalent, because RN has no inset shadow. The portable recipe:

| Design token | iOS | Android |
| --- | --- | --- |
| `--shadow-sm` … `--shadow-xl` | `shadowColor` / `shadowOffset` / `shadowOpacity` / `shadowRadius` from the token's outer layer | `elevation`, 2 / 4 / 8 / 16, plus the surface colour — Android elevation ignores colour and offset |
| the dark-mode inset ring | `borderWidth: StyleSheet.hairlineWidth` + `borderColor` from the ring's resolved colour | same |

RN 0.76+ on the New Architecture also accepts a CSS-like `boxShadow` string, which would let the shadow tokens travel more literally. <!-- UNVERIFIED: not exercised in this repo, and the inset half still has no equivalent. --> Until someone verifies it on a device, generate both the platform-shadow object and the ring from the token and keep the ring as a hairline border — it is the layer that actually does the separating in dark mode.

Two smaller consequences: **the font must be loaded** (`expo-font`, Inter Variable, with the same fallback metrics reasoning — a swap that reflows is worse on a phone), and **`tabular-nums` is `fontVariant: ['tabular-nums']`** on every changing number, same rule as the web.

## Ownership and the handoff

`packages/design-tokens/` is written by **`admin-web-engineer` only**. `widget-sdk-engineer` and `mobile-engineer` have `packages/` in their hard-boundary list and import from it.

So when the widget or the app needs a value that does not exist:

1. **Do not add a local constant.** A local constant is invisible to the contrast test, to the parity check, and to the next person.
2. Report it, in the terms the token set uses: *"the widget's typing indicator needs a token for a dot at rest on `--card-inset`; nothing in the neutral ramp sits at 3:1 there."*
3. `admin-web-engineer` adds it to `tokens.json`, runs `pnpm tokens:build`, and the value arrives in all four entry points at once.

The round-trip is the point. A token that only one app has is not a token.

## Parity checklist

Run these when a change touches the design system on any surface — they are cheap and they are the only thing standing between "one product" and "three products that used to match".

- [ ] The value came from `tokens.json` and nothing was transcribed
- [ ] `pnpm tokens:build && git diff --exit-code` is clean
- [ ] `tokens.widget.css` is a strict subset of `tokens.css` in **both** the `:root` and `.dark` blocks
- [ ] Every new token is aliased under `@theme inline` (or the alias block was regenerated)
- [ ] The loader's `:host` mirror list is unchanged, or grew with a comment naming each token it mirrors
- [ ] The mobile object regenerated and its hex round-trips within ΔE 2 of the OKLCH source
- [ ] The same screen was looked at in light and dark, on `apps/web` and on whichever client also renders it
- [ ] Nothing under `generated/` was hand-edited
