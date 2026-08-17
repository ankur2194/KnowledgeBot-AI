# The token values

Source of truth is `packages/design-tokens/src/tokens.json`. This file is the **derivation** — why each value is what it is, what floor it must clear, and which are load-bearing. When the two disagree, `tokens.json` wins and this file is stale; say so rather than editing a component around it.

**About the contrast figures.** Every ratio below was derived analytically from the OKLCH lightness and is stated as the floor the pair must clear, not as a measured certificate. The authority is the CI contrast checker (`kb-ui-accessibility` → *The contrast matrix*), which computes real sRGB relative luminance over the actual emitted values. Treat a figure here as "this pair was designed to clear X" and let CI prove it. <!-- UNVERIFIED: no contrast checker has been run in this repo yet; the job does not exist. -->

**About the hex column.** Approximations for eyeballing and for pasting into a design tool only. Nothing reads them; the mobile entry point regenerates its own hex by gamut-mapping the OKLCH, and a hand-copied hex is exactly the drift `pnpm tokens:build && git diff --exit-code` exists to catch.

---

## Surface

| Token | Light | ≈ | Dark | ≈ | Note |
| --- | --- | --- | --- | --- | --- |
| `--canvas` | `oklch(0.945 0.004 264)` | #EBECEF | `oklch(0.185 0.012 266)` | #16171E | The recessed page. Never holds text directly |
| `--card` | `oklch(1 0 0)` | #FFFFFF | `oklch(0.235 0.012 266)` | #1F2029 | The raised plane. **In dark it is lighter than the canvas** |
| `--card-inset` | `oklch(0.976 0.003 264)` | #F6F6F9 | `oklch(0.275 0.012 266)` | #262731 | Table headers, footers, code blocks, input fills, a card nested in a card |
| `--popover` | `oklch(1 0 0)` | #FFFFFF | `oklch(0.265 0.012 266)` | #24252E | Menus, tooltips, combobox lists. Distinct from `--card` in dark so a menu over a card is visible |
| `--overlay` | `oklch(0.205 0.014 266 / 0.45)` | — | `oklch(0.145 0.010 266 / 0.65)` | — | The dialog scrim. Alpha is part of the token; never apply an extra `opacity` |

The light-mode canvas/card lightness gap is **0.055 OKLCH**. That number is what the shadow ladder is tuned against. Change the canvas and every shadow needs re-tuning; it is not an independent knob.

## Text

| Token | Light | ≈ | Dark | ≈ | Floor |
| --- | --- | --- | --- | --- | --- |
| `--foreground` | `oklch(0.205 0.014 266)` | #1B1D26 | `oklch(0.965 0.004 264)` | #F3F4F7 | ≥ 12:1 on `--card` |
| `--muted-foreground` | `oklch(0.520 0.020 264)` | #666A79 | `oklch(0.720 0.018 264)` | #A3A8B8 | **≥ 4.5:1 on `--card` AND on `--canvas`** |
| `--subtle-foreground` | `oklch(0.660 0.018 264)` | #939AA8 | `oklch(0.560 0.018 264)` | #6F7484 | ≥ 3:1 — **disabled and decorative only** |

`--subtle-foreground` does not clear 4.5:1 and never will. **A placeholder is text and uses `--muted-foreground`.** `--subtle-foreground` is for disabled control labels (WCAG exempts them), decorative separators between metadata, and the dimmed half of a two-state icon. Reaching for it because a placeholder "looked heavy" is the most common way this palette fails an audit.

## Line

| Token | Light | ≈ | Dark | ≈ | Note |
| --- | --- | --- | --- | --- | --- |
| `--border` | `oklch(0.917 0.006 264)` | #E2E3E8 | `oklch(0.310 0.012 266)` | #2C2D37 | Hairlines, table rules, dividers, chip edges. **Decorative — it does not clear 3:1 and does not need to** |
| `--border-strong` | `oklch(0.660 0.014 264)` | #909AA5 | `oklch(0.620 0.016 266)` | #838795 | ≥ 3:1 on `--card`. The token for **any boundary that is the sole identifier of a control**, and for error outlines |
| `--input` | `oklch(0.900 0.006 264)` | #DDDFE5 | `oklch(0.330 0.012 266)` | #30313B | The resting outline of a field whose fill already identifies it |
| `--ring` | `var(--primary)` | — | `var(--primary)` | — | Focus. Geometry and offset are `kb-motion-and-effects` → *Focus* |

**Why `--border` is allowed to be decorative.** WCAG 1.4.11 requires 3:1 for the visual information needed to identify a control's boundary. In this design language a text field is identified by its `--card-inset` fill against the `--card` it sits on, not by its outline — which is why the outlines in our reference screens are near-invisible and the fields are still obvious. The rule that falls out of it: **a control whose fill matches its container must use `--border-strong`.** The search field in a header that is already `--card-inset` is the case that catches people.

## Accent — the only tenant-movable family

| Token | Light | ≈ | Dark | ≈ | Note |
| --- | --- | --- | --- | --- | --- |
| `--primary` | `oklch(0.525 0.235 264)` | #3E56E8 | `oklch(0.585 0.215 264)` | #5468F0 | **Tenant-overridable.** Platform default is indigo |
| `--primary-foreground` | `oklch(0.985 0 0)` | #FAFAFA | `oklch(0.985 0 0)` | #FAFAFA | **Derived server-side** from the supplied `--primary` lightness, never accepted from a form |
| `--primary-hover` | `oklch(0.470 0.230 264)` | #3547CE | `oklch(0.645 0.200 264)` | #6B7DF5 | Derived: light mode darkens, dark mode lightens |
| `--primary-active` | `oklch(0.425 0.215 264)` | #2F3EB4 | `oklch(0.700 0.180 264)` | #8291F8 | The pressed state. Required — hover is media-gated (`kb-motion-and-effects`) |
| `--primary-soft` | `oklch(0.960 0.022 264)` | #EDEFFE | `oklch(0.300 0.055 264)` | #2C3050 | Active nav pill, selected row, checked checkbox fill at rest |
| `--primary-soft-foreground` | `oklch(0.440 0.215 264)` | #3542D2 | `oklch(0.800 0.120 264)` | #A6B0FA | ≥ 4.5:1 on `--primary-soft` |
| `--accent` | `oklch(0.960 0.008 264)` | #F1F2F6 | `oklch(0.300 0.012 266)` | #2B2C36 | shadcn's semantic: the neutral hover wash on menu items and list rows. **Not a second brand colour** |
| `--accent-foreground` | `oklch(0.205 0.014 266)` | #1B1D26 | `oklch(0.965 0.004 264)` | #F3F4F7 | |

`--accent` is a naming inheritance from shadcn and it is a trap: it reads like "the secondary brand colour" and it is not — it is a neutral hover surface. `bots.theme_configuration` exposes it as tenant-overridable because `tokens.json` already did; treat a tenant that moves it as moving their hover wash, and derive `--accent-foreground` from it exactly as for `--primary`.

## Status

Semantic outcomes. Rendered as a **tinted pill or a dot plus a label**, never as saturated body text and never as a solid button fill except where a `-strong` token exists.

| Token | Light | ≈ | Dark | ≈ |
| --- | --- | --- | --- | --- |
| `--success` | `oklch(0.585 0.145 152)` | #17A45C | `oklch(0.700 0.145 152)` | #37C77C |
| `--success-soft` | `oklch(0.955 0.040 152)` | #E6F7ED | `oklch(0.300 0.055 155)` | #17392A |
| `--success-soft-foreground` | `oklch(0.435 0.105 152)` | #14713F | `oklch(0.820 0.130 155)` | #74E3A9 |
| `--warning` | `oklch(0.650 0.145 66)` | #B26A08 | `oklch(0.780 0.145 78)` | #DCA034 |
| `--warning-soft` | `oklch(0.960 0.050 80)` | #FCF2DE | `oklch(0.310 0.055 70)` | #3B2C15 |
| `--warning-soft-foreground` | `oklch(0.450 0.100 62)` | #7A4E12 | `oklch(0.850 0.120 82)` | #EDBA5E |
| `--destructive` | `oklch(0.577 0.245 27.325)` | #E5484D | `oklch(0.704 0.191 22.216)` | #F87171 |
| `--destructive-strong` | `oklch(0.520 0.230 27)` | #C8353C | `oklch(0.560 0.220 25)` | #D64A50 |
| `--destructive-foreground` | `oklch(0.985 0 0)` | #FAFAFA | `oklch(0.985 0 0)` | #FAFAFA |
| `--destructive-soft` | `oklch(0.955 0.030 22)` | #FCECEC | `oklch(0.305 0.070 20)` | #3E2124 |
| `--destructive-soft-foreground` | `oklch(0.470 0.180 25)` | #A32020 | `oklch(0.830 0.110 22)` | #F2A8A5 |
| `--info` | `oklch(0.560 0.145 245)` | #2C7FC4 | `oklch(0.680 0.130 245)` | #58A6DE |
| `--info-soft` | `oklch(0.958 0.032 240)` | #E7F1FC | `oklch(0.300 0.055 243)` | #17303F |
| `--info-soft-foreground` | `oklch(0.440 0.130 250)` | #1D5C99 | `oklch(0.830 0.100 245)` | #9FD0F0 |

Three decisions worth knowing before you change one of these:

- **`--destructive` and `--destructive-strong` are different tokens because the existing pairing does not clear text contrast.** `tokens.json` today pairs `oklch(0.577 0.245 27.325)` with a near-white foreground, which lands near **4.2:1** — under the 4.5:1 floor. `--destructive` is therefore the *icon, dot, outline and text-on-card* colour; a solid destructive button fills with `--destructive-strong`. Do not "simplify" them back together without moving the base value, and log it if you do.
- **`--info` is fixed and does not follow the tenant accent.** A tenant whose brand is red would otherwise render every informational banner in red, next to a `--destructive` that means something else entirely.
- **There is no solid `--success` or `--warning` button.** The only solid, saturated fill in the product is the primary action. Success and warning appear as soft pills, dots and icons. A green "Confirm" button is the request that breaks rule 3.

## Tone — the closed set of six

Categorical and decorative only. The pastel category cards, the coloured icon badge behind a suggestion chip, the tinted tile on a dashboard. **Never semantic**: a tone does not mean "good" or "urgent", and reaching for `--tone-rose-*` to signal danger is how a colourblind user loses the only cue.

| Family | `-surface` light | `-border` light | `-foreground` light | `-surface` dark | `-border` dark | `-foreground` dark |
| --- | --- | --- | --- | --- | --- | --- |
| `amber` | `oklch(0.960 0.045 78)` | `oklch(0.905 0.070 78)` | `oklch(0.450 0.100 66)` | `oklch(0.295 0.045 70)` | `oklch(0.360 0.060 72)` | `oklch(0.845 0.095 80)` |
| `sky` | `oklch(0.958 0.035 240)` | `oklch(0.900 0.058 240)` | `oklch(0.445 0.125 248)` | `oklch(0.292 0.045 242)` | `oklch(0.358 0.058 242)` | `oklch(0.840 0.085 244)` |
| `violet` | `oklch(0.958 0.035 292)` | `oklch(0.900 0.058 292)` | `oklch(0.450 0.140 292)` | `oklch(0.295 0.048 292)` | `oklch(0.360 0.062 292)` | `oklch(0.845 0.090 292)` |
| `mint` | `oklch(0.958 0.038 165)` | `oklch(0.898 0.060 165)` | `oklch(0.435 0.098 162)` | `oklch(0.292 0.045 165)` | `oklch(0.356 0.058 165)` | `oklch(0.840 0.085 165)` |
| `rose` | `oklch(0.958 0.035 15)` | `oklch(0.900 0.058 15)` | `oklch(0.465 0.145 20)` | `oklch(0.298 0.048 18)` | `oklch(0.362 0.062 18)` | `oklch(0.845 0.090 18)` |
| `slate` | `oklch(0.958 0.005 264)` | `oklch(0.905 0.008 264)` | `oklch(0.440 0.016 264)` | `oklch(0.292 0.010 264)` | `oklch(0.356 0.012 264)` | `oklch(0.840 0.012 264)` |

Every `-foreground` clears 4.5:1 against its own `-surface`, in both modes. Nothing else clears anything: a tone surface against `--card` is a ~0.04 lightness step and is invisible to a contrast checker on purpose — it is a wash, not a boundary. Pair it with `-border` when the tile needs an edge.

**Assignment must be stable, not positional.** Map a tone from a stable key — the source type, the bot id, the model family — through a lookup object, never from an array index into a sorted list. A user who re-sorts a table and watches every category change colour has lost the only thing the tone was doing. And Tailwind cannot see `` `bg-tone-${x}-surface` ``: a runtime-composed class never exists (`tailwind-shadcn` Gotcha 3), so the lookup returns whole class strings.

## Chart

| Token | Light | Dark | Reads as |
| --- | --- | --- | --- |
| `--chart-1` | `oklch(0.545 0.225 264)` | `oklch(0.640 0.200 264)` | indigo |
| `--chart-2` | `oklch(0.700 0.150 45)` | `oklch(0.760 0.140 48)` | coral |
| `--chart-3` | `oklch(0.575 0.125 168)` | `oklch(0.665 0.120 168)` | teal |
| `--chart-4` | `oklch(0.780 0.135 90)` | `oklch(0.830 0.125 90)` | amber |
| `--chart-5` | `oklch(0.505 0.180 305)` | `oklch(0.610 0.170 305)` | violet |
| `--chart-6` | `oklch(0.680 0.085 225)` | `oklch(0.740 0.080 225)` | steel |
| `--chart-grid` | `oklch(0.930 0.004 264)` | `oklch(0.290 0.010 266)` | horizontal rules only |
| `--chart-axis` | `oklch(0.520 0.020 264)` | `oklch(0.720 0.018 264)` | axis labels and ticks |

Two properties are load-bearing and get destroyed by a well-meaning re-order:

- **Adjacent series differ by ≥ 0.06 OKLCH lightness** (the deltas here are 0.155, 0.125, 0.205, 0.275, 0.175). That is what keeps a two- or three-series chart readable under deuteranopia and in greyscale print, where hue is gone and only lightness survives.
- **`--chart-1` is fixed indigo and is *not* `var(--primary)`.** If the first series followed the tenant accent, a red-branded tenant would get a red first series sitting beside `--destructive` in the same figure, meaning two different things. The accent still appears in charts — as the highlighted bar, the selected point, the hover state — which is rule 3 applied to data, and is the job `--primary` keeps.

Chart construction, axes, tooltips and the "Other" rule are `kb-ui-patterns` → `references/charts.md`.

## Elevation

| Token | Light | Dark |
| --- | --- | --- |
| `--shadow-hairline` | `inset 0 0 0 1px oklch(0.917 0.006 264)` | `inset 0 0 0 1px oklch(1 0 0 / 0.07)` |
| `--shadow-xs` | `0 1px 2px 0 oklch(0.205 0.014 266 / 0.05)` | `inset 0 1px 0 0 oklch(1 0 0 / 0.05), 0 1px 2px 0 oklch(0 0 0 / 0.40)` |
| `--shadow-sm` | `0 1px 2px 0 oklch(0.205 0.014 266 / 0.04), 0 2px 6px -1px oklch(0.205 0.014 266 / 0.06)` | `inset 0 0 0 1px oklch(1 0 0 / 0.06), 0 2px 6px -1px oklch(0 0 0 / 0.45)` |
| `--shadow-md` | `0 1px 2px 0 oklch(0.205 0.014 266 / 0.04), 0 8px 20px -6px oklch(0.205 0.014 266 / 0.10)` | `inset 0 0 0 1px oklch(1 0 0 / 0.06), 0 8px 20px -6px oklch(0 0 0 / 0.50)` |
| `--shadow-lg` | `0 2px 4px 0 oklch(0.205 0.014 266 / 0.04), 0 16px 32px -10px oklch(0.205 0.014 266 / 0.14)` | `inset 0 0 0 1px oklch(1 0 0 / 0.08), 0 16px 32px -10px oklch(0 0 0 / 0.55)` |
| `--shadow-xl` | `0 4px 8px 0 oklch(0.205 0.014 266 / 0.04), 0 32px 64px -16px oklch(0.205 0.014 266 / 0.18)` | `inset 0 0 0 1px oklch(1 0 0 / 0.10), 0 32px 64px -16px oklch(0 0 0 / 0.60)` |

The shadow colour is the **foreground**, not black. A neutral-black shadow on a cool canvas goes muddy; tinting it with the same hue as the text is what makes the light mode read as soft rather than dirty.

Every dark step carries an `inset … oklch(1 0 0 / α)` ring. That ring, not the shadow, is what separates a dark card from a dark canvas — which is why a component that hardcodes the light-mode string looks borderless in dark rather than merely flatter.

## Radius

`--radius` is tenant-set from `tokens.json`'s enum (`0rem`, `0.25rem`, `0.5rem`, `0.625rem`, `0.75rem`, `1rem`); the default is `0.625rem`. Everything else derives:

```css
--radius-xs:   max(0px, calc(var(--radius) - 6px));   /*  4px at default — checkbox, tag  */
--radius-sm:   max(0px, calc(var(--radius) - 4px));   /*  6px — small button, chip edge   */
--radius-md:   max(0px, calc(var(--radius) - 2px));   /*  8px — input, select, menu item  */
--radius-lg:   var(--radius);                          /* 10px — button, field, list row  */
--radius-xl:   calc(var(--radius) + 4px);              /* 14px — inner card, KPI tile     */
--radius-2xl:  calc(var(--radius) + 10px);             /* 20px — the standard card        */
--radius-3xl:  calc(var(--radius) + 18px);             /* 28px — app shell, hero, dialog  */
--radius-full: 9999px;                                 /* pills, avatars, dots            */
```

The `max(0px, …)` wrappers are not defensive style — they are the difference between a square-cornered tenant and a tenant whose small controls lose `border-radius` entirely, because a negative `border-radius` is an invalid value and the whole declaration is dropped.

**Concentric radius.** A rounded thing inside a rounded thing needs `inner = outer − padding` or the gap looks pinched at the corners. Inside `--card-pad-md` (1.25rem) on a `--radius-2xl` card, a nested surface reads correctly at `--radius-xl`. The scale is spaced so the next step down is usually right; going two steps down is what makes a nested tile look like it was pasted in.

## Space

4px grid: `--space-0-5` 0.125rem, then `--space-1` 0.25rem, `-2` 0.5rem, `-3` 0.75rem, `-4` 1rem, `-5` 1.25rem, `-6` 1.5rem, `-8` 2rem, `-10` 2.5rem, `-12` 3rem, `-16` 4rem, `-20` 5rem, `-24` 6rem.

Composites: `--gutter-sm` 1rem · `--gutter-md` 1.5rem · `--gutter-lg` 2rem · `--card-pad-sm` 1rem · `--card-pad-md` 1.25rem · `--card-pad-lg` 1.5rem.

## Type

```css
--font-sans: "Inter Variable", "Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
--font-mono: "Geist Mono", ui-monospace, SFMono-Regular, Menlo, monospace;
```

Each `--text-*` ships four values — size, line-height, weight, tracking — and they travel together. Splitting them (taking `--text-h1`'s size but leaving the tracking at 0) is how a heading ends up almost right. The table is in `SKILL.md` → *Type*.

The fallback stack carries `size-adjust` and matching `ascent-override`/`descent-override` in the `@font-face` block so the swap from system to Inter does not reflow. Without it, the first paint of every page shifts vertically once, and it looks like a layout bug on slow connections.

## Motion

```css
--dur-1: 120ms;   /* colour, hover, press, focus ring                        */
--dur-2: 180ms;   /* popover, tooltip, chip, accordion, toast                */
--dur-3: 260ms;   /* dialog, sheet, drawer, sidebar collapse                 */
--dur-4: 400ms;   /* route transition, chart draw-in, skeleton crossfade     */

--ease-out:    cubic-bezier(0.2, 0, 0, 1);      /* everything entering       */
--ease-in:     cubic-bezier(0.4, 0, 1, 1);      /* everything leaving        */
--ease-in-out: cubic-bezier(0.4, 0, 0.2, 1);    /* things that move in place */
--ease-spring: cubic-bezier(0.34, 1.30, 0.64, 1); /* toggles and checkboxes only */
```

When each applies, what may animate, and the reduced-motion contract are `kb-motion-and-effects`.
