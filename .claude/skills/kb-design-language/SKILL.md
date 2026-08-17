---
name: kb-design-language
description: The visual doctrine every KnowledgeBot surface obeys — the recessed canvas and floating cards, the neutral ramp, the single accent, the tone families, the elevation ladder, the type and space scales, and how one token set reaches Next.js, the widget and React Native without three palettes. Use whenever choosing a colour, a radius, a shadow, a font size or a spacing value, adding a token, styling a new screen, or reviewing a UI that "looks off but nobody can say why". Owns the values; tailwind-shadcn owns the Tailwind v4 and shadcn mechanism that delivers them. Pairs with kb-ui-patterns (what to build from these) and kb-motion-and-effects (how they move).
---

# The KnowledgeBot design language

**Authoritative spec:** docs/05-tech-stack.md §9.1 §9.2, docs/11-data-model.md §16.3 (`bots.theme_configuration`), docs/19-repo-structure-adrs.md §27 (`packages/design-tokens`), ADR-007
**Mechanism, not values:** `tailwind-shadcn` (Tailwind v4 CSS-first config, shadcn vendoring, CSP-safe tenant theming), `preact-vite-library` (the widget's budget), `expo-react-native` (the RN runtime).

Every admin screen, hosted-chat page, embedded widget and mobile screen is the same product. A user who configures a bot in the console and then opens that bot in the widget must not experience two design systems. This file is the one place the values live.

**The repo does not carry this palette yet, and pretending otherwise is the failure mode this paragraph exists to prevent.** `packages/design-tokens/src/tokens.json` today holds stock shadcn `neutral` — a white `background`, no canvas plane, pure-grey neutrals, and the shadcn names `secondary` / `muted` / `ring` as a flat greyscale. That is a different design language from the one below: it has no recessed canvas, so rule 1 has nothing to stand on, and no tone or chart families at all. The values here are the target, and adopting them is a **single migration** owned by `admin-web-engineer` — rename `background` → `canvas`, add the canvas/card gap and everything derived from it, add the tone, chart, status-soft and elevation families, drop `secondary` and `muted` in favour of the three text tokens — done once, in one PR, with `pnpm tokens:build` re-run and the contrast check written in the same change. Do not adopt it piecemeal: a half-migrated token set is a product with two palettes, which is worse than either one. Until that PR lands, verify against `tokens.json` before quoting a value at anyone.

## The five rules that make a screen look like ours

A screen that follows these five reads as KnowledgeBot before a single component is recognised. A screen that breaks one reads as a different application, and reviewers will call it "off" without locating the cause.

1. **Two planes, always.** The page is a *recessed canvas* (`--canvas`, a light cool grey); content sits on *raised cards* (`--card`, white). A card is never the same colour as the canvas it sits on, and content never floats directly on the canvas without a card. In dark mode the relationship inverts by lightness — the card is *lighter* than the canvas — because shadow carries no information on a dark ground.
2. **Elevation is shadow and radius, not border.** Cards separate by a soft, large-blur, low-alpha shadow from the ladder in *Elevation*. Hairline `--border` is for structure *inside* a card — table rules, dividers, input outlines, chip edges — never to draw the card itself. A card with both a shadow and a full border is a bug.
3. **One loud accent on a quiet field.** Everything is neutral except the accent: the primary button, the active nav item, links, focus rings, the first chart series, the selected state. If two things on a screen compete for "most saturated", one of them is wrong. This is also *why* the aesthetic was chosen — see *What a tenant may move*.
4. **Generous, hierarchical radius.** Radius grows with the size of the thing: chips are fully round, controls are `--radius-lg`, cards are `--radius-2xl`, the shell and hero surfaces are `--radius-3xl`. A sharp-cornered element beside a round one reads as unfinished.
5. **Colour never carries meaning alone.** Every status, delta, series and category pairs its colour with a glyph, a label, a shape or a position. That is an accessibility requirement (`kb-ui-accessibility`) and a legibility one: the tone families below are deliberately low-chroma and several of them are near-indistinguishable at a glance.

## Non-negotiables

- **No literal colour, shadow, radius, font size or duration in a component.** Every one of them is a token. `bg-[#f3f4f6]`, `shadow-[0_2px_8px_rgba(0,0,0,.06)]`, `text-[13px]`, `rounded-[20px]` and their React Native equivalents are review failures, not shortcuts. If the value you need is not in the token set, the design language is missing something — add the token in `packages/design-tokens/src/tokens.json` and say so in your report; do not inline it. This is the single rule that makes cross-platform parity mechanically checkable rather than aspirational.
- **The tenant moves the accent and the radius. Nothing else.** `tokens.json`'s `tenantOverridable` is the closed set and it stays closed: `primary`, `primary-foreground`, `accent`, `accent-foreground`, plus `--radius` from its enum. Canvas, neutrals, tone families, chart series, every shadow and every type size are **platform-owned**. A brand-coloured canvas or a tenant-supplied chart palette is out of scope for the product, not a feature request. `tailwind-shadcn` owns *how* the four values reach the DOM safely; this skill owns *that there are only four*.
- **Contrast is derived, never chosen.** Every `-foreground` is computed from its surface's OKLCH lightness. `--muted-foreground` must clear 4.5:1 against **both** `--card` and `--canvas` — it is used on both, and passing only against white is the failure mode that ships. `kb-ui-accessibility` carries the full matrix and the CI assertion.
- **OKLCH is the storage space, and the browser baseline is a promise.** Safari 16.4 / Chrome 111 / Firefox 128. An unsupported colour function invalidates the whole declaration — an older device renders a transparent surface with black text and no error. The one exception is the **mobile entry point, which ships gamut-mapped sRGB hex** (`references/cross-platform.md`); do not "fix" that by shipping OKLCH to React Native.
- **One token source, four emitted entry points.** `packages/design-tokens/src/tokens.json` is the source; `pnpm tokens:build` emits the web/widget CSS, the trimmed widget CSS, the TypeScript object and the mobile hex object. The package is zero-dependency and Node-builtins-only on purpose (one devDependency writes a fifth `pnpm-lock.yaml`), and its `generated/` output is **committed** so CI can run the build and `git diff --exit-code`. Hand-editing anything under `generated/` is caught by that gate.
- **Dark mode is a first-class palette, not an inversion.** Every token has a dark value chosen against the dark canvas, and the elevation model changes with it (rule 1). `filter: invert()`, an opacity-dimmed light palette, or a dark mode that exists only on `apps/web` are all failures.

## The token set

Full values, in OKLCH with sRGB approximations and contrast figures, live in **`references/tokens.md`**; a ready-to-paste `:root` / `.dark` block lives in **`references/tokens.css`**. Read `references/tokens.md` before adding or changing any value — it carries the derivation. This section is only the map.

| Family | Tokens | What it is for |
| --- | --- | --- |
| **Surface** | `--canvas`, `--card`, `--card-inset`, `--popover`, `--overlay` | The two planes, plus the recessed strip inside a card (table headers, footers, code) and the scrim behind a dialog |
| **Text** | `--foreground`, `--muted-foreground`, `--subtle-foreground` | Primary, secondary, and disabled/placeholder. Three, and only three |
| **Line** | `--border`, `--border-strong`, `--input`, `--ring` | Hairlines, emphasised rules, control outlines, focus |
| **Accent** | `--primary`, `--primary-foreground`, `--primary-hover`, `--primary-soft`, `--primary-soft-foreground`, `--accent`, `--accent-foreground` | The one loud colour and its tinted form. `--primary-soft` is the active-nav pill and the selected-row wash |
| **Status** | `--success`, `--warning`, `--destructive`, `--info` — each with `-soft` and `-soft-foreground` | Semantic outcomes. Always a tinted pill, never saturated body text |
| **Tone** | `--tone-{amber,sky,violet,mint,rose,slate}-{surface,border,foreground}` | The **closed set of six** categorical tints — the pastel category cards and icon badges. Decorative and categorical only; never semantic |
| **Chart** | `--chart-1` … `--chart-6`, `--chart-grid`, `--chart-axis` | The ordered series palette. Six, then "Other" (`kb-ui-patterns` → `references/charts.md`) |
| **Elevation** | `--shadow-xs`, `-sm`, `-md`, `-lg`, `-xl`, `--shadow-hairline` | The ladder below |
| **Radius** | `--radius` (tenant), `--radius-xs` … `--radius-3xl`, `--radius-full` | Derived from `--radius`; see the negative-radius gotcha |
| **Space** | `--space-0-5` … `--space-24` on a 4px grid, plus `--gutter-*` and `--card-pad-*` | Layout rhythm |
| **Type** | `--font-sans`, `--font-mono`, `--text-{caption,sm,base,md,lg,h3,h2,h1,display,metric}`, each with a paired line-height, weight and tracking | The scale below |
| **Motion** | `--dur-1` … `--dur-4`, `--ease-out`, `--ease-in`, `--ease-in-out`, `--ease-spring` | Owned jointly with `kb-motion-and-effects`, which states when each applies |

### Elevation

Five steps plus a ring. A component picks one — never a blend, never a custom offset.

| Step | Used by | Note |
| --- | --- | --- |
| `--shadow-hairline` | inputs, chips, table containers | An `inset 0 0 0 1px` ring, not a shadow. Unlike `border` it does not affect layout |
| `--shadow-xs` | segmented controls, small toggles, avatars on a card | |
| `--shadow-sm` | KPI tiles, list rows lifted on hover | |
| `--shadow-md` | **the standard content card** | The default. If you are unsure, this is the answer |
| `--shadow-lg` | popovers, dropdown menus, tooltips, toasts, the widget panel | |
| `--shadow-xl` | dialogs, sheets, the app shell against the page background | Never more than one `-xl` on screen at a time |

In dark mode each step resolves to a much weaker shadow **plus** an `inset 0 0 0 1px oklch(1 0 0 / 0.06)` top-light ring, and the surface itself steps lighter. `references/tokens.md` gives both columns; a component that hardcodes the light-mode shadow string looks flat and borderless in dark.

### Type

One family. `--font-sans` is Inter Variable, self-hosted and subset, with a system stack behind it declared with `size-adjust` so the fallback swap does not reflow. `--font-mono` appears only in request ids, object keys, model ids and code blocks.

| Token | Size / line-height | Weight | Tracking | Used by |
| --- | --- | --- | --- | --- |
| `--text-caption` | 0.75rem / 1rem | 500 | +0.01em | Table column labels, KPI captions, timestamps, chart axes |
| `--text-sm` | 0.8125rem / 1.25rem | 400 | 0 | Dense table cells, chip labels, helper text |
| `--text-base` | 0.875rem / 1.375rem | 400 | 0 | **Admin default.** Body text, nav, form fields |
| `--text-md` | 1rem / 1.5rem | 400 | 0 | **Chat default.** Message bodies — reading, not scanning |
| `--text-lg` | 1.125rem / 1.625rem | 400 | 0 | Lead paragraphs, empty-state bodies |
| `--text-h3` | 1.0625rem / 1.5rem | 600 | −0.006em | Card titles |
| `--text-h2` | 1.25rem / 1.75rem | 600 | −0.01em | Section headings |
| `--text-h1` | 1.5rem / 2rem | 600 | −0.014em | Page titles |
| `--text-display` | 2rem / 2.25rem | 600 | −0.02em | Greetings, hero lines, marketing surfaces |
| `--text-metric` | 2.25rem / 2.5rem | 600 | −0.02em | The big number on a KPI tile |

Two rules that are easy to miss and visible everywhere once broken:

- **`font-variant-numeric: tabular-nums` on every number that can change.** Metrics, table numerics, counters, token counts, durations, percentages, currency. Proportional digits make a polling KPI jitter horizontally on every refresh, and it reads as a rendering bug.
- **Secondary text uses `--muted-foreground`, never opacity.** `opacity: 0.6` on text over a tinted surface produces a colour that is in no palette, changes with the surface, and cannot be contrast-checked.

### Space and density

4px grid. Admin surfaces are dense; chat and mobile are not.

| Context | Value |
| --- | --- |
| Page gutter | `--gutter-sm` 1rem (mobile) · `--gutter-md` 1.5rem (tablet) · `--gutter-lg` 2rem (desktop) |
| Card padding | `--card-pad-sm` 1rem · `--card-pad-md` 1.25rem · `--card-pad-lg` 1.5rem |
| Grid gap between cards | `--space-4` (1rem) mobile, `--space-5` (1.25rem) desktop |
| Vertical rhythm inside a card | `--space-2` label→value, `--space-4` between groups, `--space-6` before a divider |
| Control height | 2rem small · 2.25rem default · 2.5rem large · **2.75rem minimum on touch** (`kb-ui-accessibility`) |

## What a tenant may move — and why the aesthetic survives it

The tenant contributes `primary`, `accent` and `radius` (plus the two derived foregrounds). That is a narrow lever, and the design language is built so it is *enough*: because rule 3 puts the entire brand signal on one accent against a fixed neutral field, swapping that accent re-brands every surface at once — with no per-tenant build, no second palette, and no possibility of a customer's colour landing on a chart axis or a body-text colour whose contrast was never checked.

The corollary is a constraint on you: **never spend the accent on decoration.** If the accent is already carrying six decorative flourishes, a tenant's brand colour arrives and the screen becomes unreadable. Decoration uses the tone families; the accent is reserved for the interactive and the selected.

Which surfaces receive the override, and by what CSP-safe mechanism, is `tailwind-shadcn`'s section *Per-tenant branding, on two surfaces*. Read it before writing any theming code: the two paths — a same-origin stylesheet response for single-bot documents, CSSOM `setProperty` for the multi-bot console — are not interchangeable.

## Cross-platform delivery

The one token source reaches four consumers. **`references/cross-platform.md`** carries the emitted entry points, the widget's trimmed subset, the React Native conversion and the ownership handoffs. The summary:

| Consumer | Entry point | Constraint that shapes it |
| --- | --- | --- |
| `apps/web` | `@kb/design-tokens/tokens.css` + `@theme inline` in `globals.css` | Every token used by a utility **must** be aliased under `@theme inline`, or subtree theming silently fails (`tailwind-shadcn` Gotcha 1) |
| `apps/widget` iframe app | `@kb/design-tokens/tokens.widget.css` | Custom properties are **not** tree-shaken. The full file is dead weight against a 30 kB brotli shell budget, so the widget gets a trimmed entry that must stay a strict subset, verified in CI |
| `apps/widget` loader | hand-written declarations on `:host` | `:host { all: initial }` cuts inheritance, so the shadow-root chrome re-declares the handful of tokens it needs. It cannot inherit them from the host page |
| `apps/mobile` | `@kb/design-tokens/tokens.mobile` (JS object, **sRGB hex**) | RN has no custom properties and no cascade; light and dark are two resolved objects selected at the theme provider. Colour is hex because OKLCH support across the RN colour parser is not something this repo has verified |

**Ownership handoff:** `packages/design-tokens/` is written by `admin-web-engineer` only. `widget-sdk-engineer` and `mobile-engineer` import it and **report** a needed token rather than adding one — their agent files forbid editing `packages/`. A missing token is a one-line report, not a local constant.

## Gotchas

- **A tenant sets `radius: 0rem` and every small control's corners stop rendering — or the whole rule vanishes.** The derived scale is `calc(var(--radius) - 6px)` at the small end, which goes negative and makes the `border-radius` declaration invalid; the browser drops it and the element falls back to whatever it inherited. Every derived radius is wrapped: `max(0px, calc(var(--radius) - 6px))`. Nothing warns, and `0rem` is in the tenant enum, so this ships the first time a customer picks square corners.
- **A card looks flat beside its neighbour and the CSS is identical.** It was placed on `--card` rather than on `--canvas`. The shadow ladder is tuned for a card-on-canvas lightness gap of about 0.055 OKLCH; on white-on-white the same shadow is invisible. Nest with `--card-inset`, never with another `--card`.
- **Dark mode looks correct in the console and washed out in the widget.** The widget's trimmed token entry drifted — someone added a token to `tokens.json`, used it in the iframe app, and the trimmed file never gained the `.dark` half. The CI subset check compares names present **in both blocks**, not just names present.
- **A colour applies on desktop and renders as a transparent surface with black text on an older iPad.** OKLCH below the baseline. There is no fallback layer by design; check the customer's promised embed targets before committing, and never add an `rgb()` fallback pair to "be safe" — a two-value token set is precisely what this package exists to prevent.
- **Numbers in a live-updating table shift left and right on every poll.** Missing `tabular-nums`. It reads as a layout-thrash bug and gets debugged as one.
- **Secondary text is unreadable on the tinted tone surfaces.** `--muted-foreground` is contrast-checked against `--card` and `--canvas`, not against `--tone-*-surface`. On a tone surface the paired text token is `--tone-*-foreground`; there is no "muted" variant of a tone, and inventing one with opacity puts you back in the untestable-colour hole.
- **Someone adds a seventh tone or an eighth chart colour "just for this screen".** Both sets are closed, and the closure is what makes them legible — six low-chroma tints are already near the discrimination limit. A seventh category becomes "Other" with a tooltip breakdown; a seventh tone is a signal the screen is categorising too finely.
- **The RN app's colours are subtly wrong against the web.** The mobile entry was generated by clipping OKLCH into sRGB rather than gamut-mapping it. Clipping preserves the hue angle and destroys the lightness relationship, so the neutral ramp loses its spacing and the two-plane model collapses. The conversion lives in the build script; never reimplement it per-app.

## Official docs

- [Tailwind: Theme variables](https://tailwindcss.com/docs/theme) — `@theme`, and why `inline` is load-bearing for tenant theming
- [MDN: `oklch()`](https://developer.mozilla.org/en-US/docs/Web/CSS/color_value/oklch) and [CSS Color 4: gamut mapping](https://www.w3.org/TR/css-color-4/#gamut-mapping) — the conversion the mobile entry must implement
- [MDN: `font-variant-numeric`](https://developer.mozilla.org/en-US/docs/Web/CSS/font-variant-numeric) — `tabular-nums`
- [MDN: `size-adjust`](https://developer.mozilla.org/en-US/docs/Web/CSS/@font-face/size-adjust) — the fallback stack that does not reflow
- [WCAG 2.2 SC 1.4.3 Contrast (Minimum)](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html) and [SC 1.4.11 Non-text Contrast](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast.html) — the 4.5:1 and 3:1 floors this palette is built against

## Definition of done

- [ ] `rg -n '#[0-9a-fA-F]{3,8}\b|rgba?\(|shadow-\[|rounded-\[|text-\[[0-9]|oklch\(' apps/web/src apps/widget/src apps/mobile/src` returns nothing outside a generated file, or a documented one-off carrying a comment that names why
- [ ] Every token the app uses appears in `packages/design-tokens/src/tokens.json`; `pnpm tokens:build && git diff --exit-code` is clean
- [ ] Every token referenced by a Tailwind utility is aliased under `@theme inline`, proven by the subtree-override test in `tailwind-shadcn`'s checklist
- [ ] `tokens.widget.css` is a strict subset of `tokens.css` **by name in both the `:root` and `.dark` blocks**, asserted in CI
- [ ] Every derived radius is wrapped in `max(0px, …)`, verified by rendering a component tree with `--radius: 0rem` and asserting no element lost its `border-radius`
- [ ] `--muted-foreground` clears 4.5:1 against `--card` **and** `--canvas` in both modes; every `--tone-*-foreground` clears 4.5:1 against its own `-surface`
- [ ] The mobile token object is generated, not transcribed; a test asserts a sample of its hex values round-trips to within ΔE 2 of the OKLCH source
- [ ] Dark mode has been looked at on every screen the change touches — not toggled once on the dashboard
- [ ] No tenant-overridable token beyond the closed set in `tokens.json` reaches the DOM (`tailwind-shadcn`)
