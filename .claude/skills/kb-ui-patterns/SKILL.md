---
name: kb-ui-patterns
description: The composition layer every KnowledgeBot client shares — the app shell and sidebar, page headers, KPI tiles, content cards, data tables, forms, dialogs, toasts, chips and filters, charts, the mobile tab shell and the widget panel, plus the four states (loading, empty, error, forbidden) every data surface must have before it ships. Use whenever building or reviewing a screen, adding a card, table, form, modal, banner or chart, deciding what an empty list shows, or wondering why a new page does not look like the rest of the product. Owns anatomy and composition; kb-design-language owns the values and kb-motion-and-effects owns how it moves.
---

# UI patterns

**Reads from:** `kb-design-language` (every value below is a token from it) · **Renders through:** `tailwind-shadcn` (web), `preact-vite-library` (widget), `expo-react-native` (mobile) · **Data:** `tanstack-query-table` · **Forms:** `rhf-zod-forms` · **Errors:** `kb-error-taxonomy`

Anatomies live in **`references/catalog.md`**. Charts live in **`references/charts.md`**. The four states and their copy live in **`references/states.md`**. This file is the law that governs all three.

## The composition law

Screens are assembled from a fixed vocabulary, in a fixed order. There are five levels and each one may only contain the next.

```
shell  →  page  →  section  →  card  →  content
```

- **Shell** — sidebar + top bar + canvas, or on mobile the header + canvas + tab bar. One per app. It is `--radius-3xl` and `--shadow-xl` against the page background, and it is the *only* thing at that elevation.
- **Page** — a `--text-h1` title, an optional one-sentence description in `--muted-foreground`, and a right-aligned action cluster. Filters sit under it, never inside it.
- **Section** — an optional `--text-h2` label plus a responsive grid. Sections are separated by `--space-8`, never by a divider.
- **Card** — `--card` on `--canvas`, `--radius-2xl`, `--shadow-md`, `--card-pad-md`. A card has one job. Two unrelated jobs is two cards.
- **Content** — everything else, and it lives on a card.

**Nothing skips a level.** A table dropped straight onto the canvas, a KPI number floating without a tile, a form field outside a card — each one is the thing that makes a screen read as belonging to a different application. If a piece of content seems not to need a card, it is either a page header or it needs a card.

## Non-negotiables

- **Four states before one.** Every surface that renders fetched data ships **loading, empty, error and forbidden** at the same time as the success state, not afterwards. `references/states.md` is the contract, and the two that always get skipped are *filtered-empty* (different copy, different action from first-run empty — offering "Upload your first document" to someone whose search matched nothing is a bug) and *forbidden*. A `null` return, a bare spinner, or an unstyled `Error` string is an unfinished surface.
- **The error the user sees is mapped from `error_class` and shown with the `request_id` — never the envelope's `message`.** That field is operator-facing: it can carry an internal hostname, raw upstream provider text, or an identifier nobody wrote for a reader. Log it; render the class-mapped sentence (`kb-error-taxonomy`). `error_class: null` means unknown, and unknown is permanently non-retryable — so no "Try again" button on it.
- **Retrieved content and model output are untrusted data and are never rendered as HTML.** One sanitizer path serves hosted chat, the widget, the playground and conversation review, and a pattern never re-opens it — no prose/typography plugin that wants raw HTML, no `img` added back to the allow-list to make an avatar work, no styling hooks on `[data-*]` attributes the model can write (`kb-security-baseline`, `tailwind-shadcn`).
- **Every list is server-paginated and org-namespaced.** No client-side "fetch all and filter". Query keys carry the org (`tanstack-query-table`, `kb-tenancy-isolation`); a table that keeps rows in component state across an org switch is the cross-tenant leak wearing a data-grid costume.
- **Destructive and protected actions are confirmed by typing, not by clicking.** Deleting a source, a bot, an organization or a member is irreversible or nearly so. The dialog states what will happen in specific terms ("This removes 1,204 chunks from 3 bots and cannot be undone"), requires the resource's name typed to enable the button, and the button is `--destructive-strong`. The six checks that run server-side are `kb-security-baseline`'s; the dialog does not replace one of them.
- **Numbers that change are `tabular-nums`; content that can be empty has a defined zero.** "0", "—" and "No data yet" are three different statements and the pattern picks one deliberately (`references/states.md`).
- **Nothing invents a third workspace package.** Shared component logic goes in the app that owns it, or in `packages/contracts` if it is genuinely a contract. Three consumers wanting the same code is not a new directory.

## The pattern index

Anatomy, states, spacing and the failure mode for each are in **`references/catalog.md`**.

| # | Pattern | Where | The thing that goes wrong |
| --- | --- | --- | --- |
| P1 | App shell | web admin, hosted chat | Collapsed-sidebar state lost on navigation; no mobile drawer |
| P2 | Sidebar nav | web admin | Active state computed from `startsWith` and matching two items |
| P3 | Page header | every admin page | Description written as a second title instead of a sentence |
| P4 | KPI stat tile | dashboards | Delta pill coloured green for "up" when up is bad |
| P5 | Content card | everywhere | Header actions that are three icon buttons with no labels |
| P6 | Data table | sources, jobs, conversations, members | Column widths that reflow when a cell's content changes |
| P7 | Tone tile / category card | dashboard, source types | Tone assigned by array index, so sorting recolours everything |
| P8 | Status pill + delta pill | tables, tiles, source lists | Colour without a glyph |
| P9 | Chip, filter bar, segmented control | list pages | Filters that do not survive a reload or a shared URL |
| P10 | Form layout and field | settings, bot config, credentials | Client validation presented as authority |
| P11 | Dialog, sheet, drawer, popover | everywhere | Focus not trapped; Escape not wired; scroll not locked |
| P12 | Toast and inline banner | mutations | A toast used for an error the user must act on |
| P13 | Progress: bar, arc, ring, meter | usage, quota, ingestion | A determinate bar for an indeterminate job |
| P14 | List row (mobile and dense web) | mobile, conversations | Tap target under 44px |
| P15 | Mobile tab shell | `apps/mobile` | Tab bar over the keyboard; safe area ignored |
| P16 | Widget launcher and panel | `apps/widget` | Launcher styled with utilities and losing to the host page |
| P17 | Charts | dashboards, evaluation | See `references/charts.md` |

## Density and responsive

Three breakpoints and one rule per surface. Do not add a fourth.

| | `< 768px` | `768–1279px` | `≥ 1280px` |
| --- | --- | --- | --- |
| Shell | drawer over content, header + tab bar | rail (icons only, 4rem) | sidebar (16rem) |
| Page gutter | `--gutter-sm` | `--gutter-md` | `--gutter-lg` |
| KPI row | 1 column | 2 columns | 4 columns |
| Card grid | 1 column | 2 columns | 12-column grid, cards span 4 / 6 / 8 |
| Table | **cards, not a scrolling table** | table, secondary columns hidden | full table |
| Chat | full width, composer pinned to the bottom inset | 44rem centred | 48rem centred, sources panel optional |

**A table does not become a horizontally scrolling table on a phone.** It becomes a stack of cards where each row is a card, the primary column is the title, and two or three secondary columns are labelled pairs beneath. A horizontal scroller hides the actions column, which is where the destructive action lives — the one that most needs to be visible.

## Iconography

`lucide-react` on web, `lucide-react-native` on mobile, the same names. 16px in dense contexts, 20px default, 24px in headers and empty states; stroke 1.75 at 16–20px and 1.5 at 24px. Icons take `currentColor` and never a colour of their own.

**An icon-only control needs an accessible name and a tooltip, and an icon-only *destructive* control needs a label.** Three unlabelled icon buttons in a card header is the most common form of this failure; the fix is usually one overflow menu with text items, not three tooltips.

## Gotchas

- **The sidebar's active item highlights two rows.** Active state was computed with `pathname.startsWith(href)`, so `/settings` matches `/settings/members`. Match exactly, or exactly-plus-descendants only for the item that is genuinely the parent, and derive it from one function so the sidebar and the breadcrumb cannot disagree.
- **A page looks right until a card has short content, then the grid goes ragged.** Cards in a row are not stretched. Set the grid to `align-items: stretch` and let the card fill; a card that ends early gets a `flex-1` spacer before its footer, not a min-height guess.
- **The KPI row jitters on every poll.** Two causes and both ship: proportional digits (`tabular-nums`, `kb-design-language`) and a skeleton that is a different height from the loaded tile. The skeleton mirrors the real layout box-for-box or the page reflows every refresh.
- **A delta pill is green and the news is bad.** "Up" is not "good". Cost, latency, error rate, refusal rate and quota consumption are all *worse* when they rise. The tile declares its polarity (`higherIsBetter: false`) and the pill derives colour from that, never from the sign.
- **A table's columns resize whenever a cell's content changes length.** Auto layout. Fix the widths (`table-layout: fixed` plus explicit column widths, or a grid), truncate with an accessible full value in a tooltip and in the DOM, and never let the actions column be the flexible one.
- **A filter reload loses everything.** Filters, sort and page live in the URL as search params — that is also what makes a filtered view shareable and what makes a "no results" state debuggable when a customer reports it. Do not keep them in component state, and do not put the organization in the URL (`nextjs-app-router`).
- **A dialog closes and the page behind it has scrolled to the top.** Scroll lock was applied by setting `overflow: hidden` on `body` without compensating for the scrollbar and without restoring the position. Use the primitive's lock (`radix-ui`), and never hand-roll it — the same code has to handle iOS's overscroll, which is where the hand-rolled versions all fail.
- **A toast reports an error the user has to do something about, and it disappears.** Toasts are for outcomes the user does not need to act on. Anything requiring a decision, a retry, or a correction is an inline banner or a dialog that stays. A destructive failure is never a toast.
- **The mobile tab bar sits under the home indicator, or on top of the keyboard.** `useSafeAreaInsets()` for the bottom inset and `KeyboardAvoidingView` (or the tab bar hidden while the composer has focus) for the other. Both are invisible on a simulator with the default device and obvious on a real phone.
- **Hover styles work on a laptop and do nothing on an iPad, then someone "fixes" it and hover sticks after a tap.** Tailwind v4 wraps `hover:` in `@media (hover: hover)` on purpose. Supply an explicit pressed state from `--primary-active` rather than overriding the variant (`kb-motion-and-effects`).
- **The widget launcher inherits the customer's `button {}` styles and raising specificity does not help.** Unlayered CSS beats layered CSS regardless of specificity, and Tailwind v4 emits into layers. The launcher lives in a closed shadow root behind `:host { all: initial }` with hand-written declarations (`preact-vite-library`, `kb-design-language` → `references/cross-platform.md`).

## Definition of done

- [ ] Every fetched surface in the change renders all four states, and filtered-empty is distinct from first-run empty (`references/states.md`)
- [ ] Every error path maps `error_class` to a sentence and shows `request_id`; the envelope's `message` appears in no rendered string; no retry affordance on a non-retryable class
- [ ] Every skeleton mirrors its loaded layout closely enough that no reflow occurs on resolve — checked by watching the transition, not by reading the code
- [ ] Every list is server-paginated with an org-namespaced query key, and an org switch was exercised against two orgs with distinguishable data
- [ ] Filters, sort and page are in the URL; the organization is not
- [ ] Every destructive action requires typed confirmation and names the concrete consequence
- [ ] Every icon-only control has an accessible name; no destructive control is icon-only
- [ ] Every KPI declares its polarity; no delta pill derives colour from the sign alone
- [ ] Tables become cards below 768px; no horizontal scroller hides an actions column
- [ ] The screen was opened in dark mode and at 768px and 1280px, not only at the width it was built at
- [ ] Nothing renders model output or source text as HTML; the sanitizer path is unchanged
- [ ] `kb-ui-accessibility`'s checklist passed for anything interactive
