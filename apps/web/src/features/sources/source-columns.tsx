import type { SourceResource } from '@kb/contracts';

import { StatusPill } from '@/components/status-pill';
import { TONE, type ToneClasses } from '@/components/tone';
import { formatTimestamp } from '@/features/providers/api';
import {
  SOURCE_SORTABLE_COLUMNS,
  sourceStatusDisplay,
  sourceTypeDisplay,
} from '@/features/sources/api';
import { SourceRowActions } from '@/features/sources/source-row-actions';
import { createServerColumnHelper } from '@/lib/table/features';
import { cn } from '@/lib/utils';

/**
 * The source list's columns — declared ONCE, at MODULE SCOPE.
 *
 * The stability is a correctness requirement rather than tidiness: a fresh array each render rebuilds
 * the core row model and re-renders every row on unrelated state changes. `helper.columns([...])`
 * rather than a bare array literal, because the helper preserves each column's value type through a
 * variadic tuple and the result is the shape `useTable`'s `columns` accepts.
 *
 * ── WHICH HEADERS SORT IS COMPUTED FROM THE MANIFEST, NOT DECIDED HERE ──────────────────────────
 * `enableSorting` on every column below is `SOURCE_SORTABLE_COLUMNS.includes(<its wire name>)`, and
 * that array is parsed out of `packages/contracts/rules/IndexSourcesRequest.json`. Two consequences,
 * both of them the point: a header can never offer an ordering `Rule::in($sortable)` would 422, and a
 * column the endpoint STARTS accepting becomes clickable here the moment the manifest is re-dumped,
 * with no edit to this file. A hand-written `enableSorting: true` is the drift this closes.
 *
 * ── THE "ADDED" COLUMN ACCESSES `created_at` AND IDENTIFIES AS `id` ─────────────────────────────
 * `created_at` is deliberately outside the sortable set: `id` is a ULID whose leading 48 bits are a
 * millisecond timestamp, stored `COLLATE "C"`, so lexicographic order IS byte order IS creation
 * order. Clicking this header writes `?sort=id`, which is the true statement about what the database
 * is doing — the same arrangement `bot-columns.tsx` uses, for the same reason.
 *
 * ── EVERY STRING IN A CELL IS TENANT-AUTHORED AND IS A JSX CHILD ────────────────────────────────
 * `name`, `description`, `origin_url` and every tag were typed by an operator. React escapes a child;
 * `dangerouslySetInnerHTML` is banned repo-wide by ESLint. `origin_url` in particular is a URL A
 * STRANGER CHOSE — it is the column that answers "which sources make this platform issue outbound
 * requests" — so it renders as TEXT and is not a link. Making it clickable is a decision about
 * following a tenant-supplied URL from an authenticated admin origin, and it is not one this list
 * needs to take.
 */
const helper = createServerColumnHelper<SourceResource>();

/**
 * The tone classes, looked up through a `Map` rather than `TONE[name]`.
 *
 * The key comes from `sourceTypeDisplay`, which derives it from a value off the wire, and indexing a
 * plain object by a server-supplied value is the `security/detect-object-injection` sink. Built once
 * from `TONE`'s own entries, so the six tints are still stated in exactly one place.
 */
const TONE_CLASSES = new Map<string, ToneClasses>(Object.entries(TONE));

export const SOURCE_COLUMNS = helper.columns([
  /**
   * The card layout's heading, and the only column carrying `card: 'title'` — a card with no title is
   * a card nobody can identify, and the first cell is only a fallback.
   *
   * The second line is `origin_url` when there is one, which is exactly when `type` is `url` (the
   * database enforces the equality in both directions). It is here rather than in its own column
   * because it is the same fact as the title for a crawl — and because it is half of what the
   * server's `filter` searches (`name` and `origin_url`), so a search that matched a URL has
   * something visible to have matched.
   */
  helper.accessor('name', {
    header: 'Name',
    enableSorting: SOURCE_SORTABLE_COLUMNS.includes('name'),
    meta: { card: 'title' },
    cell: ({ getValue, row }) => (
      <div className="flex min-w-0 flex-col">
        <span className="font-medium">{getValue()}</span>
        {row.original.origin_url === null ? null : (
          // `--font-mono` says "machine handle" without relying on colour, and `truncate` keeps a
          // 2,048-character URL from setting the column's width. The full value is in `title`, which
          // is also what a screen reader reads on request.
          <span
            className="truncate font-mono text-caption text-muted-foreground"
            title={row.original.origin_url}
          >
            {row.original.origin_url}
          </span>
        )}
      </div>
    ),
  }),
  /**
   * WHAT KIND OF THING IT IS — file, website or pasted text — as a DECORATIVE categorical tint plus
   * the word. The tone is never the only channel: it carries no meaning of its own (P7), so the label
   * is what a greyscale or CVD reader is actually reading, and the tint is a scanning aid.
   *
   * Deliberately NOT a `<StatusPill>`: a pill is a lifecycle signal, and two coloured pills in one
   * row would put two competing status channels beside each other.
   */
  helper.accessor('type', {
    header: 'Type',
    enableSorting: SOURCE_SORTABLE_COLUMNS.includes('type'),
    cell: ({ getValue }) => {
      const display = sourceTypeDisplay(getValue());
      const tone = TONE_CLASSES.get(display.tone) ?? TONE.slate;
      return (
        <span
          className={cn(
            'inline-flex items-center rounded-full px-2 py-0.5 text-caption',
            tone.surface,
          )}
        >
          {display.label}
        </span>
      );
    },
  }),
  /**
   * THE LIFECYCLE, through the closed `<StatusPill>` vocabulary — colour AND glyph AND the word.
   *
   * `ready_with_warnings` is its own tone (`degraded`), not a green with an asterisk: the API refused
   * to collapse the two ready states, and collapsing them here would lose the only signal that says
   * "this parsed badly and published anyway".
   *
   * The caption underneath appears only for a row whose removal is under way, and it distinguishes
   * two DIFFERENT CLAIMS: `deleted_at` says we removed it, `purged_at` says we proved it. §8.17
   * requires an administrator to see deletion completion, and this is where they see it.
   */
  helper.accessor('status', {
    header: 'Status',
    enableSorting: SOURCE_SORTABLE_COLUMNS.includes('status'),
    cell: ({ getValue, row }) => {
      const display = sourceStatusDisplay(getValue());
      return (
        <div className="flex flex-col items-start gap-1">
          {/* `whitespace-normal` and `max-w-full`: a `<Badge>` is `whitespace-nowrap overflow-hidden`
              by default, and "Ready with warnings" is the first label in the console long enough to
              be CLIPPED inside the below-768px row-card's two-column `<dl>` — the word being the
              channel that survives greyscale, half of it is not a smaller problem than the colour.
              Wrapping is the local fix; widening the shared card grid would move every table. */}
          <StatusPill
            status={display.kind}
            label={display.label}
            className="max-w-full whitespace-normal"
          />
          {row.original.deleted_at === null ? null : (
            <span className="text-caption text-muted-foreground">
              {row.original.purged_at === null ? 'Purge in progress' : 'Purge verified'}
            </span>
          )}
        </div>
      );
    },
  }),
  /**
   * `card: 'hidden'` — present in the table, dropped from the below-768px row-card. A card with five
   * labelled pairs is a table with extra steps, and of the secondary columns this is the one an
   * operator scanning a phone needs least.
   *
   * `title` carries the machine-readable timestamp, so the exact value is one hover or one accessible
   * description away from the formatted one. The formatter is `features/providers`', imported rather
   * than pasted.
   */
  helper.accessor('created_at', {
    id: 'id',
    header: 'Added',
    enableSorting: SOURCE_SORTABLE_COLUMNS.includes('id'),
    // DROPPED BELOW 1280px IN BOTH LAYOUTS. `card: 'hidden'` takes it out of the row-card (a card with
    // five labelled pairs is a table with extra steps); `showFrom: 'xl'` takes it out of the TABLE
    // between 768px and 1279px, where five columns plus a three-control actions cluster are wider than
    // the viewport and the container's scroller would hide the actions — the column P6 says a
    // horizontal scroller must never hide. Of the secondary columns this is the one an operator needs
    // least: the pipeline states already say what is recent.
    meta: { card: 'hidden', showFrom: 'xl' },
    cell: ({ getValue }) => {
      const timestamp = getValue();
      return (
        <span className="text-muted-foreground" title={timestamp ?? undefined}>
          {formatTimestamp(timestamp)}
        </span>
      );
    },
  }),
  /**
   * THE ROW ACTIONS. `card: 'action'` puts them in the row-card's action row below 768px, where a
   * horizontal scroller is not the fallback — `components/data-table.tsx` replaces the table with
   * cards at that width precisely so an actions column cannot be scrolled out of sight, and this
   * column is where the destructive action lives.
   *
   * The cell is a COMPONENT rather than markup because it owns three mutations, a dialog and its own
   * error line; it reads the organization and the list's query key from
   * `SourceActionsContext`, which is what keeps this array at module scope.
   */
  helper.display({
    id: 'actions',
    // A visually hidden header: the column has no name worth a column heading, and an empty `<th>` is
    // an unlabelled column for a screen-reader user.
    header: () => <span className="sr-only">Actions</span>,
    meta: { card: 'action', cardLabel: 'Actions', align: 'end' },
    cell: ({ row }) => <SourceRowActions source={row.original} />,
  }),
]);

/** The number of skeleton columns the first load draws, so the skeleton and the table cannot drift. */
export const SOURCE_COLUMN_COUNT = SOURCE_COLUMNS.length;
