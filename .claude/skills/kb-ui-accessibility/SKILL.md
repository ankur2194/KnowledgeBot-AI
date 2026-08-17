---
name: kb-ui-accessibility
description: The accessibility and responsive contract every KnowledgeBot client must meet — the contrast matrix and its CI check, keyboard operability and focus order, touch targets and safe areas, labels and live regions, colour-independence, reduced motion, the three breakpoints, and how the same requirements land on React Native and inside a widget iframe on a stranger's page. Use whenever adding an interactive element, an icon-only control, a form field, a chart, a dialog, a live-updating region, or a new breakpoint — and before calling any UI change done. Pairs with kb-design-language (the values it verifies) and kb-ui-patterns (the anatomies it constrains).
---

# Accessibility and responsive parity

**Verifies:** `kb-design-language` (the palette), `kb-ui-patterns` (the anatomies), `kb-motion-and-effects` (motion and focus) · **Spec:** docs/04-functional-channels-chat.md §8.18 requires keyboard access and screen-reader labels on the chat surfaces · **Tested by:** `vitest-playwright`

The target is **WCAG 2.2 AA**. Not because a customer asked — because a self-hostable product sold to organizations will be asked, and retrofitting this into four clients costs more than every other item in the design system combined.

This skill is short on theory and long on the specific things that break here.

## The contrast matrix

`kb-design-language` sets the values; this is the check that proves them. A CI job walks the emitted token set and asserts every pair below. <!-- UNVERIFIED: the job does not exist yet; it is owed by admin-web-engineer and belongs in the gates workflow. -->

| Pair | Floor | Why this one is listed |
| --- | --- | --- |
| `--foreground` on `--card`, `--canvas`, `--card-inset`, `--popover` | 4.5:1 | |
| `--muted-foreground` on `--card` **and** `--canvas` | 4.5:1 | It is used on both; passing only on white is the failure that ships |
| every `--*-soft-foreground` on its own `--*-soft` | 4.5:1 | |
| every `--tone-*-foreground` on its own `--tone-*-surface` | 4.5:1 | |
| `--primary-foreground` on `--primary`, **over the full accepted range of tenant `primary`** | 4.5:1 | The tenant supplies the surface; the pairing is derived, so the assertion runs over the grammar's bounds, not over one sample |
| `--destructive-foreground` on `--destructive-strong` | 4.5:1 | The base `--destructive` measures ≈4.2:1 with a near-white foreground, which is why the `-strong` token exists |
| `--border-strong`, `--ring`, `--success`, `--warning`, `--destructive`, `--info` on `--card` and `--canvas` | 3:1 | Non-text: control boundaries, focus, status glyphs |
| `--chart-n` on `--card`; adjacent `--chart-n` lightness delta | 3:1 · ≥0.06 OKLCH | The second is what survives greyscale and CVD |

Both colour modes. `--subtle-foreground` is deliberately absent — it does not clear 4.5:1 and is restricted to disabled controls and decoration (`kb-design-language` → `references/tokens.md`). **A placeholder is text and uses `--muted-foreground`.**

## Keyboard

Everything is operable without a pointer. The failures are always the same six:

1. **A `div` with an `onClick`.** It is not focusable, not activatable by Enter or Space, and invisible to assistive tech. Use a `button` or an `a`; if the visual must be a card, put the button inside it or make the card a button.
2. **`outline: none` with no replacement.** A defect, including inside a shadow root, including on a custom composer, including "temporarily" (`kb-motion-and-effects` → *Focus*).
3. **Focus not trapped in a dialog, or not returned to the trigger on close.** Use `radix-ui`; do not hand-roll it. Escape closes; the scrim is not the only way out.
4. **Focus order following the DOM into a visually different order.** If a control is visually first it should be first in the DOM. `tabindex` above 0 is never the fix.
5. **No skip link.** The admin shell puts a "Skip to content" link first in the DOM, visible on focus. Without it every page costs a keyboard user the whole sidebar.
6. **A menu, combobox or tab set with no arrow-key navigation.** These have defined keyboard interaction patterns; the primitive implements them and a hand-rolled dropdown does not.

Product-specific: **the Stop control on a streaming answer is reachable by keyboard the entire time the stream runs**, and it is the first thing in tab order after the streaming turn (`kb-ai-chat-ux`).

## Targets and pointers

- **44 × 44 CSS px minimum** for any touch target, which is why the control-height scale tops out at 2.75rem on touch. A 2rem icon button needs padding or a pseudo-element to reach 44px — the visual may stay small.
- **8px minimum between adjacent targets.** Dense table action columns are where this fails.
- No interaction depends on hover, on a drag, or on a precise path. Everything reachable by swipe on mobile is reachable another way (`kb-ui-patterns` P14).
- **A pressed state is required because hover is media-gated** (`kb-motion-and-effects` E9). Most widget and mobile traffic never fires `:hover`.

## Names, roles, structure

- **Every icon-only control has an accessible name**, and it says the action, not the icon: "Delete source", not "Trash". **No destructive control is icon-only** (`kb-ui-patterns`).
- **Every form control has a visible `<label>` bound to it.** A placeholder is not a label; it disappears exactly when the user needs it.
- **Heading levels are a real outline** — one `h1` per page, no level skipped, and card titles are headings rather than styled `div`s. Screen-reader navigation is by heading; a page of `div`s is a page with no structure.
- **Landmarks**: `header`, `nav`, `main`, `aside`, `footer` once each, and the shell provides them so pages do not have to.
- Decorative images and gradients are `aria-hidden`. Meaningful images have alt text that says what they convey.
- **Tables are `<table>` with `<th scope>`.** A grid of `div`s with `role="table"` is a reimplementation that loses row/column announcements in at least one screen reader.
- **Charts carry `role="img"` with a descriptive label and a real data table alternative** (`kb-ui-patterns` → `references/charts.md`).

## Live regions

- **Toasts** announce through a polite live region.
- **The streaming answer's live region is the turn, not each token** — and preferring to announce *state transitions* rather than the token stream is what actual screen-reader users want (`kb-ai-chat-ux`).
- **Loading containers carry `aria-busy="true"`** and the skeleton blocks inside are `aria-hidden`. Do not announce a skeleton.
- **Form errors** are announced when validation fires: the error text is referenced by `aria-describedby` and the field gets `aria-invalid`. An error that is only red is not an error state.
- Never put `aria-live` on something that updates constantly. A polling KPI announcing every 30 seconds is a reason to stop using the product.

## Colour independence

Every status, delta, category and series carries a second channel:

| Signal | Colour | Second channel |
| --- | --- | --- |
| Status pill | `--{status}-soft` | a dot or a glyph, plus the word |
| Delta pill | polarity-derived | an arrow glyph derived from the sign |
| Chart series | `--chart-n` | ≥0.06 lightness spacing, legend order, the data table |
| Form error | `--destructive` | an alert glyph and the message text |
| Required field | — | the word "Required", not an unexplained asterisk alone |
| Tone tile | `--tone-*` | the label, and a stable icon |

## Motion

`prefers-reduced-motion: reduce` is honoured, and honouring it means transforms removed and loops stopped — **not indicators deleted**. Every progress signal keeps signalling by another channel. The full contract and the React Native equivalent (`AccessibilityInfo.isReduceMotionEnabled()`, which `reanimated` does not check for you) are in `kb-motion-and-effects`.

Nothing auto-plays, auto-advances or loops for more than five seconds without a control to stop it.

## Responsive

Three breakpoints. The full matrix is `kb-ui-patterns` → *Density and responsive*; the accessibility half:

- **Reflow at 320px** with no horizontal scrolling of the page body (WCAG 1.4.10). Wide content — tables, charts, code, long URLs — scrolls inside its own container.
- **Text spacing** survives a user stylesheet raising line-height to 1.5×, letter-spacing to 0.12em and paragraph spacing to 2× without clipping (1.4.12). Fixed-height containers around text are what break this.
- **Zoom to 200%** without loss of content or function. A sticky header plus a sticky footer plus a sticky composer at 200% zoom can leave a phone-sized viewport with no room for content — check it.
- **Orientation is not locked** on mobile, and the layout works in landscape.
- **`prefers-color-scheme` is honoured on first paint**, with the explicit choice persisted. A flash of the wrong theme is a genuine problem for light-sensitive users, not a polish item.

## Per-surface additions

**`apps/mobile`** — RN's accessibility API is not the DOM's. `accessible`, `accessibilityRole`, `accessibilityLabel`, `accessibilityState`, `accessibilityLiveRegion` (Android) / `AccessibilityInfo.announceForAccessibility` (iOS). Dynamic Type must not be ignored: the type scale is in `rem`-equivalents that respond to the OS font-size setting, and a fixed-height row will clip at the largest setting. `useSafeAreaInsets()` for every edge, not just the bottom.

**`apps/widget`** — it lives in a stranger's page. Three consequences: the iframe needs a `title` attribute that names it ("Chat with Acme Support") because it appears in the host page's landmark list; focus must not escape the panel into the host page while it is open, and must return to the launcher on close; and **the host page's own accessibility failures are not ours to fix but are ours not to worsen** — the launcher must not be the only positive-`tabindex` element on the page, and it must not steal focus on load.

**Hosted chat** — one document, one bot, tenant-themed. The derived-contrast guarantee is the only thing standing between a customer's brand colour and an unreadable chat, which is why `-foreground` values are never accepted from the form.

## Testing

Automated checks catch perhaps a third of this, and the third they catch is the third nobody argues about — so run them, and do not mistake a green axe run for an accessible screen.

- **`@axe-core/playwright` on every route** in the Playwright suite, failing the build on a violation (`vitest-playwright`).
- **A keyboard-only pass** on any changed flow: unplug the mouse, complete the task. This finds more than the scanner does.
- **A screen-reader pass** on chat and on any new form, at least once per surface — VoiceOver or NVDA. The streaming announcement model cannot be verified any other way.
- **200% zoom and 320px width** on the changed screens.
- **Reduced motion enabled at the OS level**, not just emulated, for anything with an indicator.
- The contrast matrix runs in CI over the emitted tokens, so a palette change cannot pass review by looking fine.

## Gotchas

- **axe passes and the screen is unusable by keyboard.** Scanners do not test operability. A `div[role="button"]` with `tabindex="0"` and no Enter/Space handler scans clean.
- **The focus ring is invisible on one specific surface.** The 2px offset landed on a colour the ring does not contrast against. Add a 2px `--card` outer ring; never remove the offset.
- **A modal traps focus and the page behind it is still reachable with a screen reader's virtual cursor.** Focus trap is not enough — the background needs `aria-hidden` or `inert` while the dialog is open. The primitive does this; a hand-rolled dialog does not.
- **Dark mode passes contrast and light mode does not, or the reverse.** Both modes, always. The tone families and the soft-status pairs are where this splits.
- **A tenant's brand colour fails contrast and the screen ships anyway.** The assertion must run over the *bounds of the accepted grammar*, not over the default palette (`tailwind-shadcn`).
- **The mobile app clips text at the largest Dynamic Type setting.** A fixed row height. Let rows grow; cap the number of lines instead, with the full value available.
- **The widget steals focus on load, and the host page's form loses what the visitor was typing.** The launcher never takes focus on mount. Ever.

## Definition of done

- [ ] The contrast matrix passes in both modes, over the tenant grammar's bounds
- [ ] Every interactive element is reachable and operable by keyboard, with a visible `:focus-visible` ring
- [ ] Focus is trapped in overlays, the background is `inert`, Escape closes, focus returns to the trigger
- [ ] Every icon-only control has an accessible name; no destructive control is icon-only
- [ ] Every form control has a visible bound label; errors carry `aria-invalid` + `aria-describedby` + a glyph
- [ ] Heading outline is real; landmarks appear once each; tables are `<table>` with `<th scope>`
- [ ] Every colour-carried signal has a second channel
- [ ] Touch targets ≥ 44px with ≥ 8px separation
- [ ] Reduced motion tested at the OS level, and every progress indicator still indicates
- [ ] 320px, 200% zoom and the 1.4.12 text-spacing overrides all reflow without clipping
- [ ] `@axe-core/playwright` is green **and** a keyboard-only pass of the changed flow was completed by hand
- [ ] Chat: state transitions announced, tokens not; Stop reachable throughout the stream
- [ ] Widget: iframe titled, focus contained, nothing stolen on load
- [ ] Mobile: Dynamic Type at max does not clip; safe areas respected on every edge
