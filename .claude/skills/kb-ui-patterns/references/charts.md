# Charts

Chart *type* selection — which form fits which question — is general craft, and the `dataviz` skill covers it. **This file is what makes a chart look like ours**, and the rules here override any default a charting library ships.

No chart library is pinned in this repo yet. Whichever one `admin-web-engineer` picks must satisfy: renders SVG (not canvas — the axis labels have to be real text for screen readers and for the print/greyscale case), accepts colours as arbitrary strings so it can be fed `var(--chart-1)`, allows the grid and axis to be styled or removed, and does not ship its own font. A library whose colours are a fixed enum is disqualified; the palette is a token set, not a theme prop.

## The look, in seven rules

1. **Horizontal gridlines only**, `--chart-grid`, 1px. No vertical grid, no chart border, no axis lines. The lines are a reading aid, not a frame.
2. **Axis labels are `--text-caption` in `--chart-axis`.** No axis titles — the card header already said what this is. If the unit is not obvious, it goes in the header or in the tick format (`$`, `%`, `ms`), never in a rotated label.
3. **Ticks are sparse.** Four or five on the value axis; on the category axis, every label if they fit and every *n*th if they do not. **Never rotate a label** — drop to every second, abbreviate, or switch to a horizontal bar chart, which is what a chart with long category names wanted in the first place.
4. **Bars have rounded caps** — `--radius-sm` on the two outer corners only, so a stacked segment keeps its join square. Bar width is 40–60% of the band; grouped series sit 4px apart inside the band.
5. **Lines are 2px with no dots at rest**, `--ease-out` on the hover dot. Areas are a vertical gradient from the series colour at 18% to 0%, and only ever for a **single**-series chart — two overlapping translucent areas produce a third colour that is in no palette and means nothing.
6. **Colour comes from `--chart-1` … `--chart-6` in order**, and adjacent series must stay adjacent in that order (their ≥0.06 lightness spacing is what survives greyscale and CVD). A **seventh category is "Other"**, with the breakdown in the tooltip.
7. **Emphasis uses `--primary`, not a seventh colour.** The selected bar, the hovered point, the current period, the projected segment — that is rule 3 of the design language applied to data, and it is the job `--primary` keeps in a chart where `--chart-1` is fixed and does not follow the tenant.

## Per-form specifics

**Bar / column.** Zero baseline, always — a truncated y-axis on a bar chart misrepresents magnitude and is the one charting choice that is simply wrong rather than merely ugly. Grouped for comparing 2–3 series; stacked only when the total is meaningful and the parts are ≤4. A value label above the bar only when there are fewer than eight bars.

**Line / area.** A truncated y-axis is legitimate here and often necessary; label the axis so the truncation is visible. Gaps in data are gaps — never interpolate across a missing period, and a dashed segment for a partial current period is worth the extra code.

**Donut.** A centre label carrying the total or the headline percentage in `--text-metric` is what makes a donut better than a pie; use a donut, never a pie. Maximum six segments plus "Other". Segments in descending order starting at 12 o'clock. A legend beneath with the value and percentage per segment — leader lines into the segments look precise and are unreadable below about 900px.

**Sparkline.** 2px line, no axes, no grid, no dots, `--space-1` vertical padding so the extremes are not clipped. It always sits beside a number that carries the actual value; a sparkline is a shape, not a reading.

**Heat / matrix.** A sequential ramp from one hue, not the categorical palette. Always paired with a value in the cell or in the tooltip, because a colour ramp cannot be read to a precision anybody trusts.

## Tooltip, legend, interaction

- Tooltip: `--popover`, `--radius-xl`, `--shadow-lg`, `--card-pad-sm`. Header = the category or timestamp; rows = a colour dot, the series name, and a right-aligned `tabular-nums` value. It follows the **band**, not the pointer, and shows every series at that band at once. A tooltip per series makes comparison impossible, which is usually the whole reason the chart exists.
- Legend beneath the chart, `--text-caption`, a 8px dot plus the label; clicking toggles a series, and a toggled-off series is `--subtle-foreground` and still keyboard-reachable.
- Hover is not the only path to a value. The keyboard must be able to move along the category axis, and the accessible table (below) is always available.

## The four states

- **Loading** — a skeleton with the *shape* of the chart: the axis labels' positions and a muted block where the plot goes. Not a spinner in an empty box; the layout must not shift on resolve.
- **Empty** — "No data for this period", the period stated, and if a filter narrowed it, a Clear action. Not a chart of zeroes.
- **Partial** — some series present, some missing. Say so above the chart in `--muted-foreground`; do not silently draw fewer lines than the legend implies.
- **Error** — a class-mapped sentence and the `request_id`, in a `--destructive-soft` inline banner sized to the chart's box.

## Accessibility

Non-negotiable, and the reason SVG is a library requirement:

- `role="img"` on the chart with an `aria-label` that states what it shows and the headline finding, not "chart".
- **A visually hidden `<table>` with the same data**, or a "View as table" toggle that shows a real one. This is the only chart accessibility measure that actually works, and it doubles as the copy-paste affordance analysts ask for anyway.
- Every series is identifiable without colour: the legend order, the tooltip's name, the table. For a printed or greyscale reading, the ≥0.06 lightness spacing of the palette carries it — which is why the palette order is not yours to shuffle.
- Chart animation obeys reduced-motion: with it set, series appear at their final state (`kb-motion-and-effects`).

## Gotchas

- **The chart renders at the wrong size on first paint, then snaps.** A responsive container measured before layout settled. Give the container an explicit aspect ratio or height; do not let the chart decide the card's size.
- **`var(--chart-1)` renders as black.** The chart is in an SVG that the library injected outside the themed subtree, or into a portal that is not a descendant of the element carrying the bot's scoped tokens. Custom properties resolve at the element; a portalled tooltip needs the token scope re-applied.
- **Colours are correct in light mode and unreadable in dark.** The series were read once into JS state at mount instead of being left as `var()` references in the DOM. Never resolve a token to a string in JavaScript and hold it — the class flip re-themes the tree with no re-render only if the `var()` survives into the rendered attribute.
- **A stacked bar's rounded corners round every segment.** Round the outer corners of the outer segments only; a rounded join reads as a gap.
- **The y-axis maximum jumps on every poll.** Auto-scaling to the data. Pin the domain for a live chart, or round the max up to a stable step, or the chart appears to animate while the data is flat.
