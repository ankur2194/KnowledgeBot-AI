---
name: kb-motion-and-effects
description: How KnowledgeBot surfaces move and how their signature effects are built — the elevation ladder in practice, the hairline ring, gradient meshes and the composer's gradient border, frosted headers, skeleton shimmer, the streaming caret, hover and pressed states, and the focus ring. Use whenever adding a transition, an animation, a gradient, a blur, a shadow, a hover or pressed state, a loading shimmer, or a focus style — and whenever something feels janky, sticks after a tap on a tablet, or ignores reduced-motion. Owns duration, easing and the effect recipes; kb-design-language owns the token values they read.
---

# Motion and effects

**Reads from:** `kb-design-language` (`--dur-*`, `--ease-*`, every colour and shadow) · **Composed by:** `kb-ui-patterns` · **Constrained by:** `kb-ui-accessibility` (reduced motion, focus visibility), `preact-vite-library` (the widget's budget), `kb-security-baseline` (CSP and what may be injected)

The recipes are in **`references/recipes.md`**. This file is the policy that decides which one applies and how far it may go.

## Five rules

1. **Motion confirms; it never entertains.** Every animation answers one of three questions: *where did this come from* (a menu grows from its trigger), *what just changed* (a row highlights on update), *is something still happening* (a shimmer, a caret). An animation that answers none of them is decoration, and decoration costs frames on a customer's laptop that is already running their own site.
2. **Transform and opacity only.** Animating `width`, `height`, `top`, `margin`, `box-shadow` or `background-color` on a large surface forces layout or paint every frame. Move things with `translate`, size them with `scale`, fade with `opacity`. The one sanctioned exception is a colour transition on a *small* element (a button, a chip) at `--dur-1`, which is cheap and which the eye needs.
3. **Small distances, short times.** Entering elements move ≤ 8px and scale from ≥ 0.96. Nothing in the product takes longer than `--dur-4` (400ms), and almost everything is `--dur-1` or `--dur-2`. A 500ms modal is not elegant; it is a wait.
4. **Enter is `--ease-out`, exit is `--ease-in` at 0.75× the duration.** Things arrive decelerating and leave accelerating, and they leave faster than they arrive — because the user has already decided.
5. **Effects are token compositions, never one-offs.** A gradient, a shadow, a blur radius or a ring width that is not built from `references/recipes.md` out of `kb-design-language` tokens is a fork of the design system that no contrast test and no dark-mode check will ever see.

## Non-negotiables

- **`prefers-reduced-motion: reduce` is honoured, and honouring it does not mean `animation: none`.** Motion-sensitive users still need to know a thing appeared. The contract: **transforms are removed, opacity transitions survive at `--dur-1`, looping and parallax stop entirely, and anything that conveys progress keeps conveying it by another channel** — the shimmer becomes a static tinted block plus `aria-busy`, the streaming caret stops blinking and stays solid. A component that vanishes its own loading indicator under reduced motion has made the app unusable for that user, which is the opposite of the accommodation.
- **Hover is a media-gated enhancement; the pressed state is mandatory.** Tailwind v4 wraps `hover:` in `@media (hover: hover)` deliberately, so no touch device latches a hover state. That is correct — and it means every interactive element defines an explicit `:active` / pressed appearance from `--primary-active` or `--accent`, or a tap produces no feedback at all on the majority of widget and mobile traffic. Overriding the variant to "fix" tablets re-breaks sticky hover.
- **Nothing animates per streaming token.** A fade or a slide on each arriving token turns a 400-token answer into 400 animations, drops frames on a mid-range phone, and makes text unreadable while it is being read. The stream gets **one** caret and an optional single fade on the first paint of the message (`kb-ai-chat-ux`).
- **`backdrop-filter` is opt-in per surface, never global.** It is the most expensive property in the catalogue, it is composited on every scroll frame behind it, and it degrades to transparent on browsers without support — which means the surface must be legible on its fallback background alone. E6 in `references/recipes.md` states the three conditions; a blurred surface that fails one of them ships as a header that stutters when the page scrolls.
- **No effect is delivered as an injected `<style>` or a `style=""` attribute.** Nonces cover `<style>` elements, never attributes, and a string-interpolated stylesheet is CSS injection when any part of it is tenant- or model-derived. Dynamic values reach the DOM through CSSOM `setProperty` on a scoping element (`tailwind-shadcn`, `kb-security-baseline`).
- **Gradients are decorative and carry no meaning.** They are `aria-hidden`, they sit behind an opaque or scrim-backed text layer so contrast is measured against a known colour, and they never encode a value. A gradient that means "high" versus "low" has replaced a legend with a vibe.

## Focus

Focus is the one visual system that is not negotiable at all, and it gets its own section because Tailwind v4 changed the geometry underneath it and the v3 spelling still compiles.

```css
/* the ring, on every focusable element */
:focus-visible {
  outline: 2px solid var(--ring);
  outline-offset: 2px;
  border-radius: inherit;          /* an outline on a square box around a round button is a bug */
}
```

- **`ring` in v4 is 1px `currentColor`, not 3px blue.** A component pasted from a v3-era source gets a thin, low-contrast focus indicator that technically exists and practically does not. `ring-3` restores the geometry if you are using the utility; the `outline` form above is preferred because it never participates in layout.
- **`:focus-visible`, not `:focus`.** A mouse click on a card should not paint a ring; a Tab onto it must.
- The ring is `--ring` (which is `--primary`) at 2px with a 2px offset. On a surface where the offset falls on a colour the ring does not contrast against, add a 2px `--card` outer ring — never remove the offset.
- **`outline: none` without a replacement is a defect**, including inside a shadow root, including on a custom composer, including "temporarily". `kb-ui-accessibility` owns the audit; this is where the value comes from.
- Focus is `--dur-1` and does not move — no sliding focus ring following the pointer, which is disorienting and, under reduced motion, forbidden.

## The effect catalogue

Recipes, with the CSS and the React Native equivalent where one exists, in **`references/recipes.md`**.

| # | Effect | Where it appears | The constraint |
| --- | --- | --- | --- |
| E1 | Soft elevation | every card | Pick a ladder step; never blend or invent an offset |
| E2 | Hairline ring | inputs, chips, table containers | `inset … 1px`, not `border` — it must not affect layout |
| E3 | Tone tile wash | category cards, icon badges | Closed set of six, assigned from a stable key |
| E4 | Gradient mesh | hero cards, AI summary panels, empty states | Decorative, `aria-hidden`, text over a scrim |
| E5 | Gradient border ring | the chat composer on focus | A masked `background`, not an animated `border-color` |
| E6 | Frosted surface | sticky headers, the widget panel header | Three conditions, or it does not ship |
| E7 | Skeleton shimmer | every first-load surface | Reduced-motion → static block, `aria-busy` stays |
| E8 | Streaming caret | chat, during generation | One caret for the whole stream, never per token |
| E9 | Press feedback | every interactive element | Mandatory; hover is not a substitute |
| E10 | Focus ring | everything focusable | Above |
| E11 | Progress arc / gauge | quota, usage | Discrete ticks; reduced-motion draws it at the final value |
| E12 | Count-up on a metric | KPI tiles | `--dur-4`, `tabular-nums`, off under reduced motion, and never on a polling value |
| E13 | Row highlight on change | live tables | One `--dur-4` fade from `--primary-soft`, once |
| E14 | Enter / exit for overlays | dialog, sheet, popover, toast | Origin-anchored; distance ≤ 8px |

## Performance

The widget runs inside a stranger's page and the mobile app runs on a phone that is not new. Three budgets:

- **No more than two simultaneously animating properties per element**, and no animation on more than a handful of elements at once. A list that animates every row on load is a list that jank on the device that matters.
- **`will-change` is applied on interaction and removed after**, never left on in a stylesheet. A permanent `will-change` on many elements exhausts compositor memory and makes everything slower — the exact opposite of the intent.
- **The widget's effects come out of its byte budget.** An animation library is not a dependency the widget can afford; `preact-vite-library` owns the number. On the web, `tw-animate-css` and the `data-open`/`data-closed` variants from `shadcn/tailwind.css` cover the overlay cases with no runtime.

## Gotchas

- **A menu opens instantly and closes after a visible delay, or never unmounts.** The exit animation runs on an element the framework already removed, or the unmount is not waiting for it. Use the primitive's `data-state` variants (`data-[state=closed]:animate-out`) rather than tracking open state yourself — that is what `shadcn/tailwind.css` imports exist for.
- **A hover effect sticks on an iPad after a tap.** Something overrode `@media (hover: hover)`. Restore it and add the pressed state; the tap needed `:active`, not `:hover`.
- **A frosted header stutters when the page scrolls, only on some machines.** `backdrop-filter` recompositing everything behind it. Check E6's conditions; on a long scrolling list the answer is almost always an opaque header.
- **A gradient looks smooth on a display and banded on a projector or a cheap monitor.** 8-bit banding across a long, low-contrast ramp. Add a very low-opacity noise layer, or shorten the ramp, or accept it — but do not "fix" it by increasing the contrast of the gradient, which breaks the text contrast over it.
- **A card lifts on hover and the text inside it goes blurry for the duration.** A fractional `translate` or `scale` on a subpixel boundary. Lift by changing the shadow step and translating by a whole pixel, or not at all — E1's hover step exists so nobody needs to invent this.
- **Reduced motion is respected on the web and ignored in the app.** `prefers-reduced-motion` has no meaning in React Native; the equivalent is `AccessibilityInfo.isReduceMotionEnabled()` plus its change listener, and `react-native-reanimated` will happily animate regardless. Wire it once in the theme provider and read it from context.
- **The streaming answer scrolls itself away from the user who scrolled up to re-read something.** Autoscroll must be conditional on the viewport already being pinned to the bottom, and it must stop the moment the user scrolls up. This is motion, it is deeply annoying, and it is `kb-ai-chat-ux`'s rule — repeated here because it gets implemented as a scroll animation.
- **A count-up animation on a polling metric counts up on every poll.** E12 runs on first paint only. A number that re-animates every 30 seconds is a distraction that the user learns to look away from, which defeats the tile.

## Official docs

- [MDN: `prefers-reduced-motion`](https://developer.mozilla.org/en-US/docs/Web/CSS/@media/prefers-reduced-motion) — and note it says *reduce*, not *none*
- [MDN: `backdrop-filter`](https://developer.mozilla.org/en-US/docs/Web/CSS/backdrop-filter) and [`will-change`](https://developer.mozilla.org/en-US/docs/Web/CSS/will-change) — the two properties with real costs
- [MDN: `:focus-visible`](https://developer.mozilla.org/en-US/docs/Web/CSS/:focus-visible) and [`outline-offset`](https://developer.mozilla.org/en-US/docs/Web/CSS/outline-offset)
- [WCAG 2.2 SC 2.3.3 Animation from Interactions](https://www.w3.org/WAI/WCAG22/Understanding/animation-from-interactions.html) and [SC 2.2.2 Pause, Stop, Hide](https://www.w3.org/WAI/WCAG22/Understanding/pause-stop-hide.html)
- [React Native: `AccessibilityInfo`](https://reactnative.dev/docs/accessibilityinfo) — `isReduceMotionEnabled`

## Definition of done

- [ ] Every animation added answers *where from*, *what changed*, or *still happening* — and you can say which
- [ ] Only `transform` and `opacity` animate, except colour on small elements at `--dur-1`
- [ ] Durations and easings are `--dur-*` / `--ease-*` tokens; no literal `ms` or `cubic-bezier` in a component
- [ ] Reduced motion was tested by actually enabling it (OS setting or devtools emulation), and every progress indicator still indicates progress
- [ ] Every interactive element has a pressed state, verified on a touch device or emulation — not only a hover state
- [ ] Focus is `:focus-visible`, 2px `--ring` at 2px offset, inheriting the element's radius; `rg 'outline-none|outline: none' apps/` has a replacement ring at every hit
- [ ] No `<style>` string interpolation and no `style=""` carrying a dynamic value
- [ ] Every gradient is `aria-hidden` and every text over one sits on a known opaque or scrim colour
- [ ] `backdrop-filter` appears only where E6's three conditions hold, and the fallback was viewed with the filter disabled
- [ ] No per-token animation in the streaming path
- [ ] `will-change` appears only inside an interaction handler, never in a stylesheet
- [ ] The widget's size-limit budget still passes
