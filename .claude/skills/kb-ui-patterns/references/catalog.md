# The pattern catalog

Every value named here is a token from `kb-design-language`. Where a pattern exists on more than one client the anatomy is the same and only the primitives differ: `radix-ui` + shadcn source on `apps/web`, eight hand-written Preact components on `apps/widget`, React Native primitives on `apps/mobile`.

---

## P1 · App shell

```
┌─ page background: --canvas ──────────────────────────────────────────┐
│  ┌─ shell: --card, --radius-3xl, --shadow-xl, inset --space-3 ─────┐ │
│  │ ┌ sidebar 16rem ─┐ ┌ main ─────────────────────────────────────┐│ │
│  │ │ brand          │ │ top bar: search · mode · notif · avatar   ││ │
│  │ │ primary CTA    │ ├───────────────────────────────────────────┤│ │
│  │ │ nav sections   │ │ canvas: --canvas, scrolls independently   ││ │
│  │ │ …              │ │   page header                             ││ │
│  │ │ org / usage    │ │   sections of cards                       ││ │
│  │ │ user card      │ │                                           ││ │
│  │ └────────────────┘ └───────────────────────────────────────────┘│ │
│  └────────────────────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────────┘
```

- The shell is the only `--shadow-xl` on screen. The inset gutter around it (`--space-3`) is what makes the canvas read as a page rather than as a viewport.
- The sidebar and the main canvas scroll independently. The top bar is sticky within main, `--card` with a `--border` bottom edge — not a floating glass bar unless `kb-motion-and-effects` E6's conditions are met.
- **Collapse state is persisted** (a cookie read server-side, so the first paint is not wrong) and is a *rail* at 4rem — icons plus tooltips — never a full hide. Below 768px it is a drawer over the content with a scrim, and the trigger lives in the header.
- Hosted chat uses the same shell with the nav replaced by a conversation list. The widget does not use it at all (P16).

## P2 · Sidebar nav

- Brand block, then the one primary action (`--primary`, full width, `--radius-lg`), then nav sections, then the footer cluster: org badge, plan/usage meter, user card.
- **Sections** carry a `--text-caption` uppercase label in `--muted-foreground`, `--space-6` above and `--space-2` below. Three sections is comfortable; six means the information architecture is wrong, not that the sidebar needs scrolling.
- **Item**: 2.25rem tall, `--radius-lg`, icon 16px + `--text-base` label, `--space-2` horizontal padding. Rest is transparent; hover is `--accent`; **active is `--primary-soft` with `--primary-soft-foreground`** and the icon inherits. Never a left border bar and a fill together.
- Active state comes from **one function** shared with the breadcrumb, matching exactly (see the `startsWith` gotcha in `SKILL.md`).
- A count or status on an item is a `--text-caption` pill at the right edge, `--card-inset` background — never the accent, which is already spent on the active state.

## P3 · Page header

```
Sources                                    [ Filters ⌄ ]  [ + Add source ]
Documents, spreadsheets and crawled sites this bot can answer from.
```

- `--text-h1` title. The description is **one sentence**, `--text-base` in `--muted-foreground`, and it says what the page is *for* — not a restatement of the title, not two sentences.
- Actions right-aligned, at most one `--primary`; the rest are neutral (`--card` with `--shadow-hairline`). More than three means an overflow menu.
- `--space-6` below the header before the first section. Filters sit **under** the header in their own row, so they can wrap on narrow screens without disturbing the title.
- On mobile the title moves into the sticky header and the primary action becomes a floating action button or a header icon — not a full-width button competing with the content.

## P4 · KPI stat tile

```
┌ --card-inset or --card, --radius-xl, --card-pad-md ─┐
│ TOTAL DOCUMENTS            (icon, 20px, muted)      │   ← --text-caption, --muted-foreground
│ 1,204                                               │   ← --text-metric, tabular-nums
│ ↗ +12.4%  vs last month                             │   ← delta pill + --text-caption muted
└─────────────────────────────────────────────────────┘
```

- Caption above, metric, delta below. Always that order — a row of tiles must align on the metric baseline, and it cannot if the caption is sometimes two lines. Truncate the caption; never wrap it.
- **The tile declares polarity.** `higherIsBetter` is a required prop, not a default. Latency, cost, error rate, refusal rate and quota consumed are all `false`. The delta pill's colour derives from `polarity × sign`, and the arrow glyph derives from the sign — so a bad increase is a red pill with an up arrow, which is exactly right and is what makes the pill readable without colour.
- Delta pill: `--radius-full`, `--text-caption`, `--success-soft` / `--destructive-soft` background with the matching `-soft-foreground`, arrow glyph 12px. Zero change is `--tone-slate-surface` with an em dash, not a green 0%.
- A tile with no comparison period shows no delta — not "+0%".
- Four tiles per row at `≥1280px`, two at tablet, one on mobile. A fifth tile means a second row of four, not five squeezed.

## P5 · Content card

```
┌ --card, --radius-2xl, --shadow-md, --card-pad-md ────────────────────┐
│ Latest ingestions        91 items      [ All ][ Docs ][ Sites ]  ⋯   │
│ Check the most recent runs                                           │
│ ────────────────────────────────────────────────────────────────────│  ← --border, full bleed
│  body                                                                │
│  ─────────────────────────────────────────────────────────────────── │
│  View all →                                                          │
└──────────────────────────────────────────────────────────────────────┘
```

- Header: `--text-h3` title, optional inline count as a `--card-inset` pill, optional one-line `--text-sm` subtitle in `--muted-foreground`, then the right cluster — a segmented control, a period select, or an overflow menu. **At most one control plus the overflow.**
- The rule under the header is `--border` and bleeds to the card edge (negative margin equal to `--card-pad-md`); it is the one divider a card is allowed. A second divider means two cards.
- Footer link is `--text-base` in `--primary` with a trailing arrow, and it is a real link with a real href.
- A card has one job. "Recent activity and quota and a chart" is three cards.

## P6 · Data table

- Container: `--card`, `--radius-2xl`, `--shadow-md`, and the table bleeds to the container edge with `--card-pad-md` cell padding — so rules run the full width.
- Header row: `--card-inset` background, `--text-caption` in `--muted-foreground`, sticky under the card header on scroll. Sort indicator is a 12px chevron that occupies its slot at all times (a reserved column, or the layout shifts on first sort).
- Rows: 3.25rem, `--border` bottom rule, hover `--accent`, selected `--primary-soft`. The last row has no rule.
- **Column widths are fixed.** Text truncates with an ellipsis and the full value is available to a screen reader and on hover. The actions column is fixed-width and right-aligned and is never the flexible one.
- Cell types are a closed set, and each has one rendering: text · secondary text (`--muted-foreground`) · numeric (right-aligned, `tabular-nums`) · status pill (P8) · relative timestamp with the absolute value in `title` · avatar + name · tone tag (P7) · actions.
- Selection: a checkbox column with a header tri-state, and a **selection bar that replaces the card header** while anything is selected — count, then actions, then Clear. Do not float a bar over the content.
- Pagination footer: `--card-inset`, page size select on the left, range text centred, prev/next on the right. Server-paginated always (`tanstack-query-table`).
- Below 768px the table becomes a stack of row-cards (`SKILL.md` → *Density and responsive*).

## P7 · Tone tile / category card

The pastel tiles: a category, a source type, a model family, a plan.

- `--tone-{x}-surface` background, `--radius-xl`, `--card-pad-md`, no shadow. `--tone-{x}-border` only when it sits on `--card` rather than on `--canvas` and needs an edge.
- Title `--text-base` weight 600 in `--tone-{x}-foreground`; supporting line `--text-sm` in the same token — **there is no muted variant of a tone.**
- A large count sits bottom-left in `--text-metric`; the affordance is a 2rem circular button bottom-right, `--foreground` filled with a `--card` arrow glyph.
- **The tone comes from a stable key through a lookup that returns whole class strings.** Not an array index (sorting recolours everything) and not a template literal (Tailwind's scanner is a text pass; `` `bg-tone-${x}-surface` `` never exists — `tailwind-shadcn` Gotcha 3).

```ts
const TONE = {
  document: 'bg-tone-sky-surface text-tone-sky-foreground',
  spreadsheet: 'bg-tone-mint-surface text-tone-mint-foreground',
  presentation: 'bg-tone-amber-surface text-tone-amber-foreground',
  website: 'bg-tone-violet-surface text-tone-violet-foreground',
  image: 'bg-tone-rose-surface text-tone-rose-foreground',
} as const;   // exhaustive over the source-type union, so a new type is a type error
```

## P8 · Status pill and delta pill

- `--radius-full`, `--text-caption`, `--space-2` horizontal / `--space-0-5` vertical, `{status}-soft` background with `{status}-soft-foreground` text, and a **6px dot or a 12px glyph** before the label.
- The glyph is not decoration — it is the non-colour channel that makes the pill readable in greyscale and under CVD, and it is a `kb-ui-accessibility` requirement. A pill with colour alone is a finding.
- The status vocabulary is closed and maps to `kb-source-lifecycle`'s states plus the error taxonomy; do not invent a label. Pending/queued → `--tone-slate`, running → `--info`, ready/active → `--success`, degraded/partial → `--warning`, failed/disabled → `--destructive`.
- Delta pills are P4's; same geometry, polarity-derived colour, sign-derived arrow.

## P9 · Chip, filter bar, segmented control

- **Chip** (a removable applied filter): `--card-inset`, `--radius-full`, `--text-sm`, 12px × close button with an accessible name that includes the filter ("Remove filter: status is failed").
- **Filter bar**: a search field, then filter triggers, then a "Clear all" that appears only when something is applied. It wraps; it does not scroll horizontally.
- **Segmented control**: `--card-inset` track, `--radius-lg`, the selected segment is `--card` with `--shadow-xs` — a raised thumb on a recessed track, which is the two-plane model at control scale. It is for **2–4 mutually exclusive views**, not for filtering, and not for more than four.
- All of it lives in the URL. A filtered view a user cannot share or reload is unfinished.

## P10 · Form layout and field

- One column, `--space-5` between fields, grouped in cards with an `--text-h3` group title and a one-line description. Two columns only for genuinely paired values (first/last, from/to).
- **Field anatomy**: label (`--text-base`, weight 500, always present and always visible — no placeholder-as-label), control, then either helper text or the error, in the same slot so nothing shifts when validation fires. Reserve the slot's height.
- Control: 2.25rem tall, `--card-inset` fill, `--input` outline, `--radius-md`. Focus swaps the outline to `--ring` and adds the focus ring (`kb-motion-and-effects` → *Focus*). Error swaps it to `--border-strong` in `--destructive` **and** shows the message with a 12px alert glyph — colour alone is not an error state.
- Placeholder text uses `--muted-foreground`. It is text, and `--subtle-foreground` does not clear 4.5:1.
- **Client validation is a UX affordance, never the authority.** The schema mirrors the Laravel FormRequest; a 422 maps onto the same field names; ownership columns are unrepresentable in a form schema (`rhf-zod-forms`).
- Submit is bottom-right in a `--card-inset` footer bar that bleeds to the card edge; destructive form actions go in their own card at the bottom of the page with a `--destructive` header, never next to Save.
- Long forms get a sticky footer with the submit and an unsaved-changes indicator; navigating away with unsaved changes prompts.

## P11 · Dialog, sheet, drawer, popover

| | Use for | Geometry |
| --- | --- | --- |
| Dialog | a decision or a short form, ≤ 2 fields | centred, `min(32rem, calc(100vw - 2rem))`, `--radius-3xl`, `--shadow-xl`, `--card-pad-lg` |
| Sheet | a long form or a detail view without losing context | right edge, 28rem, full height, `--radius-3xl` on the left corners only |
| Drawer | the mobile equivalent of both | bottom, `--radius-3xl` top corners, grab handle, snap points |
| Popover / menu | a small set of choices or a filter | `--popover`, `--radius-xl`, `--shadow-lg`, anchored, `--space-1` padding around `--radius-md` items |

- All of them: `--overlay` scrim, focus trapped, Escape closes, focus returned to the trigger, background scroll locked by the primitive. Use `radix-ui`; do not hand-roll a single one of those behaviours.
- The dialog header is `--text-h2` + one-line description; the footer is right-aligned with cancel then confirm. **Destructive dialogs put the consequence in the body in specific numbers and require typed confirmation** (`SKILL.md` → *Non-negotiables*).
- A dialog never opens another dialog. A dialog never contains a table.

## P12 · Toast and inline banner

- **Toast** — an outcome that needs no action. `--popover`, `--radius-xl`, `--shadow-lg`, a 16px status glyph, one line of text, optional single Undo. Bottom-right on desktop, top on mobile (out of thumb reach so it is not dismissed by accident). 4s, paused on hover and on focus, dismissible, and **at most three stacked** — beyond that they collapse into a count.
- **Inline banner** — anything that needs a decision, a retry, or a correction, and anything that describes a persistent condition ("This bot's provider credential expired"). `{status}-soft` background, `{status}` left rule 3px, `--radius-lg`, glyph + title + body + action. It stays until the condition clears.
- **An error the user must act on is never a toast.** It disappears, it is unreachable by keyboard after it goes, and it is the single most common accessibility complaint about dashboards.
- Toasts announce through a polite live region; banners are in the document and need no announcement beyond being reachable.

## P13 · Progress: bar, arc, ring, meter

- **Bar** — determinate work with a known total (an upload, a batch). `--card-inset` track, `--primary` fill, `--radius-full`, 6px. The label sits above with the count, not inside the bar.
- **Arc / gauge** — a proportion of a quota, 180°, drawn as discrete ticks rather than a solid sweep. The number goes in the centre in `--text-metric`; the caption underneath states the raw pair ("19 of 30 summaries used"), because a percentage alone cannot be acted on.
- **Ring** — a compact proportion inside a table cell or a chip, 20–24px, stroke 3px.
- **Meter** — a stacked horizontal bar for a composition (three source types making up a total), segments from the chart palette, legend beneath with values.
- **An indeterminate job gets an indeterminate indicator.** A determinate bar that sits at 90% for two minutes is worse than a spinner. Ingestion stages are known and countable — show the stage name and `3 of 7`, which is honest and more useful than a percentage.
- Quota thresholds: `--warning` at 80%, `--destructive` at 100%, and the colour change is accompanied by a text change.

## P14 · List row

The dense row used on mobile, in conversation lists and in the widget.

- 4rem minimum (**not** the 2.25rem of a nav item — this is a touch target), `--card`, `--border` bottom rule, `--card-pad-sm` horizontal.
- Leading slot: avatar, tone-tinted icon badge (`--radius-lg`, `--tone-{x}-surface`), or checkbox. Title `--text-base` weight 500, one line, truncated. Supporting line `--text-sm` `--muted-foreground`, one line. Trailing: timestamp, status pill, chevron, or an overflow trigger — **one** of them.
- The whole row is the target. A row with a nested button needs the button's hit area excluded from the row's, or every tap on the button also navigates.
- Swipe actions on mobile are additive only: everything reachable by swipe is also reachable another way.

## P15 · Mobile tab shell

- Header (title, back or menu, one or two actions) · canvas · tab bar. 3–5 tabs, icon 24px + `--text-caption` label — always labelled.
- Tab bar: `--card` with a `--border` top rule, `useSafeAreaInsets().bottom` added to its padding, active tab in `--primary` (icon and label both), inactive in `--muted-foreground`.
- **Hide the tab bar while a text input has focus**, or the keyboard covers it and the layout jumps. The chat screen pins the composer above the keyboard and has no tab bar at all.
- Pull-to-refresh on list screens; skeletons, not spinners, on first load.

## P16 · Widget launcher and panel

- **Launcher**: 3.5rem circle, `--primary`, `--shadow-lg`, fixed to a corner with a `--space-4` inset plus the host page's safe area. Optional unread dot in `--destructive`. It lives in a **closed shadow root behind `:host { all: initial }` with hand-written declarations** — not utilities, which lose to the host page's unlayered CSS regardless of specificity.
- **Panel**: an iframe on our origin, 24rem × min(40rem, 80vh), `--radius-3xl`, `--shadow-xl`, anchored above the launcher; full-screen below 480px. Inside it, the chat surface is `kb-ai-chat-ux` unchanged — the widget is not a reduced chat, it is the same chat in a smaller box.
- The panel's chrome is a header (bot name, avatar, minimise, close) and the composer. No sidebar, no conversation list unless the bot is configured for it.
- Everything here is on a byte budget `preact-vite-library` owns. A pattern that needs a new dependency to render does not ship in the widget.

## P17 · Charts

See `charts.md`.
