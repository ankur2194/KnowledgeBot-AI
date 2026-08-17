# Effect recipes

Every recipe reads tokens from `kb-design-language` and nothing else. Copy the recipe; do not copy a value out of it.

The CSS is written as plain declarations so it reads the same whether you express it as a Tailwind utility, a `components/ui/` variant, or a hand-written rule in the widget's shadow root. React Native equivalents are in the last section.

---

## E1 · Soft elevation

```css
.card {
  background: var(--card);
  border-radius: var(--radius-2xl);
  box-shadow: var(--shadow-md);
  padding: var(--card-pad-md);
}

/* the lift, when a card is a link or a row target */
@media (hover: hover) {
  .card-interactive { transition: box-shadow var(--dur-2) var(--ease-out); }
  .card-interactive:hover { box-shadow: var(--shadow-lg); }
}
```

Pick one step from the ladder. **A card never carries both a shadow and a full border** — in dark mode the ladder already contains an inset ring, and adding `border: 1px` on top produces a double edge that only shows up in dark.

The lift is a shadow change, not a `translateY`. A fractional transform lands text on a subpixel boundary and blurs it for the duration of the hover, which is the effect people describe as "the card goes soft when I mouse over it".

## E2 · Hairline ring

```css
.field, .chip, .table-shell {
  box-shadow: var(--shadow-hairline);   /* inset 0 0 0 1px … */
  border-radius: var(--radius-md);
}
```

An inset ring instead of `border: 1px` because **it does not participate in layout**. A border added on focus or on error shifts every sibling by a pixel; a ring does not. It also composites with the elevation shadow in one property, so a focused input is `box-shadow: var(--shadow-hairline), var(--shadow-xs)` and not a specificity fight.

Where the boundary is the *only* thing identifying a control, the ring colour is `--border-strong` (3:1), not `--border` (`kb-design-language` → `references/tokens.md`).

## E3 · Tone tile wash

```css
.tone-tile {
  background: var(--tone-sky-surface);
  color: var(--tone-sky-foreground);
  border-radius: var(--radius-xl);
  padding: var(--card-pad-md);
  /* no shadow: a tone tile is a wash on the plane it sits on, not a raised object */
}
/* only when it sits on --card rather than on --canvas and needs an edge */
.tone-tile--edged { box-shadow: inset 0 0 0 1px var(--tone-sky-border); }
```

The icon badge form is the same three tokens at `2.25rem` square, `--radius-lg`, icon in the `-foreground`.

Assignment comes from a stable key through a lookup returning whole class strings (`kb-ui-patterns` → `references/catalog.md`, P7). There is no muted text on a tone surface.

## E4 · Gradient mesh

The soft multi-hue wash behind a hero card, an AI summary panel, or a large empty state.

```css
.mesh {
  position: relative;
  background-color: var(--card);
  background-image:
    radial-gradient(at 12% 18%, var(--tone-violet-surface) 0, transparent 55%),
    radial-gradient(at 88% 8%,  var(--tone-sky-surface)    0, transparent 50%),
    radial-gradient(at 72% 92%, var(--tone-rose-surface)   0, transparent 50%);
}
```

Three rules, and each of them has been broken in a shipped dashboard somewhere:

- **It is built from tone surfaces, which are near-white by design.** The result is a wash you notice only when it is removed. A saturated mesh is a different product; if it looks like a marketing page, it is too strong.
- **Text never sits directly on it.** Either the text layer has its own opaque or scrim background, or the mesh is confined to a region the text does not enter. Contrast has to be measurable against a known colour, and a gradient has no single colour.
- **It is `aria-hidden` and decorative**, and it encodes nothing. If a reader could ask "what does the pink part mean?", it is a chart wearing a gradient.

For a dark-on-gradient hero (the summary card that is *supposed* to be loud), invert: a `--foreground`-dark surface with the mesh at low opacity on top, text in `--card`. Contrast is then against the dark surface, which is knowable.

## E5 · Gradient border ring

The multi-hue ring around the chat composer when it has focus. A **masked background**, not an animated `border-color` — a border cannot hold a gradient.

```css
.composer { position: relative; border-radius: var(--radius-xl); background: var(--card); }

.composer::before {
  content: "";
  position: absolute;
  inset: 0;
  border-radius: inherit;
  padding: 1.5px;                       /* the ring's thickness */
  background: conic-gradient(from 180deg,
    var(--chart-5), var(--chart-1), var(--chart-3), var(--chart-4), var(--chart-2), var(--chart-5));
  /* punch out the interior so only the padding band paints */
  -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
  -webkit-mask-composite: xor;
          mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
          mask-composite: exclude;
  opacity: 0;
  transition: opacity var(--dur-2) var(--ease-out);
  pointer-events: none;
}
.composer:focus-within::before { opacity: 1; }
```

**This is decoration and it is not the focus indicator.** The keyboard focus ring (E10) still applies to the `<textarea>` inside. A reviewer who sees the gradient and assumes focus is handled is the failure this note exists to prevent — and it fails silently for anyone whose browser lacks `mask-composite`.

The conic gradient does **not** rotate. An animated ring is a loop, which reduced-motion forbids and which pulls a compositor layer for the entire time the composer has focus.

## E6 · Frosted surface

`backdrop-filter` is the most expensive property here. It ships only when **all three** hold:

1. The surface is **small and fixed** — a header bar, the widget panel's title strip. Never a full-page overlay, never a dialog.
2. Content **actually moves behind it**. Over a static background it is an expensive way to draw a solid colour.
3. The surface is **legible on its fallback**, because the filter silently does nothing where it is unsupported.

```css
.frost {
  background: var(--card);                       /* fallback: fully opaque, always legible */
  border-bottom: 1px solid var(--border);
}
@supports ((backdrop-filter: blur(12px)) or (-webkit-backdrop-filter: blur(12px))) {
  .frost {
    background: color-mix(in oklch, var(--card) 72%, transparent);
    -webkit-backdrop-filter: blur(12px) saturate(140%);
            backdrop-filter: blur(12px) saturate(140%);
  }
}
```

The `@supports` wrapper is the whole recipe: opaque by default, translucent only where the blur will actually happen. Writing it the other way round — translucent with the filter as an enhancement — produces unreadable text on every browser that lacks it.

## E7 · Skeleton shimmer

```css
@keyframes kb-shimmer { from { transform: translateX(-100%); } to { transform: translateX(100%); } }

.skeleton {
  position: relative;
  overflow: hidden;
  background: var(--card-inset);
  border-radius: var(--radius-md);
}
.skeleton::after {
  content: "";
  position: absolute;
  inset: 0;
  background: linear-gradient(90deg,
    transparent, color-mix(in oklch, var(--card) 55%, transparent), transparent);
  animation: kb-shimmer 1400ms var(--ease-in-out) infinite;
}

@media (prefers-reduced-motion: reduce) {
  .skeleton::after { display: none; }        /* the tinted block remains, and so does aria-busy */
}
```

The sweep is a `translateX` on a pseudo-element, not an animated `background-position` — the second repaints the gradient every frame.

Under reduced motion the *indicator* survives as a static tinted block and the container keeps `aria-busy="true"`. Removing both is what makes the app unusable for the user the media query was for.

Skeleton lines vary in width — 100% / 80% / 60% — and mirror the loaded layout box for box (`kb-ui-patterns` → `references/states.md`).

## E8 · Streaming caret

```css
@keyframes kb-caret { 0%, 45% { opacity: 1; } 55%, 100% { opacity: 0.15; } }

.stream-caret {
  display: inline-block;
  width: 0.5ch;
  height: 1em;
  vertical-align: -0.15em;
  margin-left: 1px;
  background: var(--primary);
  border-radius: 1px;
  animation: kb-caret 1000ms steps(1, end) infinite;
}
@media (prefers-reduced-motion: reduce) {
  .stream-caret { animation: none; opacity: 1; }   /* solid, not gone */
}
```

**One caret for the whole stream.** It is appended after the last rendered character and removed on the terminal event. Nothing else in the streaming path animates — no per-token fade, no per-token slide (`kb-ai-chat-ux`).

## E9 · Press feedback

```css
.btn-primary {
  background: var(--primary);
  color: var(--primary-foreground);
  border-radius: var(--radius-lg);
  transition: background-color var(--dur-1) var(--ease-out),
              box-shadow      var(--dur-1) var(--ease-out);
}
@media (hover: hover) {
  .btn-primary:hover { background: var(--primary-hover); }
}
.btn-primary:active {                 /* MANDATORY — most traffic never fires :hover */
  background: var(--primary-active);
  transform: translateY(1px);         /* whole pixel: a fractional shift blurs the label */
}
```

The same shape for neutral controls with `--accent` as the hover wash and `--border-strong` as the pressed edge, and for list rows with `--accent` / `--primary-soft`.

On touch, add `touch-action: manipulation` to kill the 300ms tap delay, and never suppress the pressed state to "reduce flicker" — that flicker is the only confirmation the user gets.

## E10 · Focus ring

`SKILL.md` → *Focus*. The value is `outline: 2px solid var(--ring); outline-offset: 2px; border-radius: inherit;` on `:focus-visible`, and `outline: none` without a replacement is a defect.

## E11 · Progress arc

A 180° gauge drawn as **discrete ticks** rather than a solid sweep — it reads as a measurement rather than a decoration, and it degrades gracefully when the value is unknown.

- 40–48 ticks across the arc, 2px wide, `--radius-full`. Filled ticks `--primary`, empty ticks `--border`. The boundary tick may be `--primary` at 50% for the fractional remainder.
- Centre: the percentage in `--text-metric` with `tabular-nums`; beneath it the raw pair in `--text-caption` `--muted-foreground` ("19 of 30 used"). A percentage alone is not actionable.
- Threshold colours at 80% (`--warning`) and 100% (`--destructive`) change the *filled* ticks and are accompanied by a text change — never colour alone.
- Draw-in animates `--dur-4` `--ease-out` on first paint only. Under reduced motion it renders at its final value immediately.

Implement as SVG so the ticks are real elements and the whole thing carries `role="meter"` with `aria-valuenow` / `aria-valuemin` / `aria-valuemax` and an `aria-label`.

## E12 · Count-up on a metric

`--dur-4`, `--ease-out`, easing the *value* and not the opacity. Rules:

- **First paint only.** A polling value that re-animates every refresh is a distraction the user learns to ignore, which defeats the tile.
- `tabular-nums` throughout, or the number's width changes on every frame and drags the layout with it.
- Skipped entirely under reduced motion — render the final value.
- Never on a value that can decrease, and never on a currency figure someone might read mid-animation.

## E13 · Row highlight on change

```css
@keyframes kb-row-flash {
  from { background-color: var(--primary-soft); }
  to   { background-color: transparent; }
}
.row-updated { animation: kb-row-flash var(--dur-4) var(--ease-out) 1; }
@media (prefers-reduced-motion: reduce) { .row-updated { animation: none; } }
```

The sanctioned exception to "transform and opacity only": it is one shot, on one row, and there is no other way to say *this one changed*. It fires **once** per change — a row that flashes on every poll because the payload was re-created rather than compared is the bug this always turns into.

## E14 · Overlay enter and exit

Anchored to where the thing came from, distance ≤ 8px, and driven by the primitive's `data-state` rather than by state you track:

| Surface | Enter | Exit |
| --- | --- | --- |
| Dialog | `opacity 0→1`, `scale 0.97→1`, `--dur-3` `--ease-out` | reverse, `--dur-2` `--ease-in` |
| Sheet / drawer | `translateX(100%)→0` (or `translateY` from the bottom), `--dur-3` `--ease-out` | reverse, `--dur-2` `--ease-in` |
| Popover / menu | `opacity 0→1`, `scale 0.96→1` **from the trigger's side** (`data-side`), `--dur-2` | `--dur-1` `--ease-in` |
| Toast | `translateY(8px)→0` + fade, `--dur-2` | fade only, `--dur-1` |
| Scrim | `opacity 0→1`, `--dur-2`, both directions | |

Use `data-[state=open]:animate-in` / `data-[state=closed]:animate-out` from `tw-animate-css` and `shadcn/tailwind.css`. Tracking open state yourself is what produces the menu that never unmounts, or the one that closes visibly late.

---

## React Native equivalents

| Effect | RN |
| --- | --- |
| E1 elevation | The resolved shadow object per platform, from `@kb/design-tokens/tokens.mobile`; on Android `elevation`. `kb-design-language` → `references/cross-platform.md` |
| E2 hairline ring | `borderWidth: StyleSheet.hairlineWidth` — RN has no inset shadow |
| E3 tone wash | Same three token values from the resolved theme object |
| E4 gradient mesh | `expo-linear-gradient`, stacked and absolutely positioned. RN has no radial gradient primitive; approximate with two linear layers or ship an SVG via `react-native-svg` |
| E5 gradient ring | `expo-linear-gradient` as the parent with an inset `--card` child — there is no mask-composite. Do not attempt the conic |
| E6 frost | `expo-blur`'s `BlurView`, and the same three conditions. It is more expensive on Android than on iOS |
| E7 shimmer | `react-native-reanimated` `translateX` on an overlay, gated on `AccessibilityInfo.isReduceMotionEnabled()` |
| E8 caret | A looping opacity animation, same gate |
| E9 press | `Pressable` with a style function on `pressed`. **There is no hover**, so the pressed state is the entire interaction feedback |
| E10 focus | Only relevant for keyboard and switch-control users; RN focus rings are platform-drawn — do not suppress them |
| E11 arc | `react-native-svg` |
| E12/E13 | Same rules, `reanimated`, same reduced-motion gate |
| E14 overlays | `react-native-reanimated` layout animations or the navigator's own transitions; prefer the navigator's |

`prefers-reduced-motion` does not exist in React Native. Read `AccessibilityInfo.isReduceMotionEnabled()` once in the theme provider, subscribe to its change event, and expose it on context — `reanimated` animates regardless of the OS setting unless you check.
