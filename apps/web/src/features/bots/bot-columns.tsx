import type { BotResource } from '@kb/contracts';

import { StatusPill } from '@/components/status-pill';
import { botAccessModeLabel, botStatusDisplay } from '@/features/bots/api';
import { formatTimestamp } from '@/features/providers/api';
import { createServerColumnHelper } from '@/lib/table/features';

/**
 * The bot list's columns — declared ONCE, at MODULE SCOPE.
 *
 * The stability is a correctness requirement rather than tidiness: a fresh array each render rebuilds
 * the core row model and re-renders every row on unrelated state changes
 * (`tanstack-query-table` gotcha 9). `helper.columns([...])` rather than a bare array literal, because
 * the helper preserves each column's value type through a variadic tuple and the result is the shape
 * `useTable`'s `columns` accepts; a bare array of `helper.accessor(...)` results is typed per element
 * and will not assign. That is TanStack's design, not ours.
 *
 * ── EVERY SORTABLE HEADER'S COLUMN ID IS A COLUMN THE SERVER WILL ACCEPT ────────────────────────
 * `enableSorting` is false on everything outside `BOT_SORTABLE_COLUMNS`. A header that offers an
 * ordering `Rule::in($sortable)` rejects is a 422 one click away, on a request the user made by
 * clicking a control we drew.
 *
 * ── THE "CREATED" COLUMN SORTS BY `id`, AND THAT IS THE ENDPOINT'S OWN INSTRUCTION ──────────────
 * `created_at` is deliberately not in the sortable set: `id` is a ULID whose leading 48 bits are a
 * millisecond timestamp, stored `COLLATE "C"`, so lexicographic order IS byte order IS creation
 * order, and the create migration declined to add a second index expressing the same ordering.
 * `IndexBotsRequest` says it out loud — *"A console that wants a 'Created' column sorts it by `id`"* —
 * so this column ACCESSES `created_at` and IDENTIFIES as `id`. Clicking it writes `?sort=id`, which is
 * the true statement about what the database is doing.
 *
 * ── TWO FIELDS THAT ARE PRESENT ON THE ROW AND RENDERED NOWHERE ────────────────────────────────
 * `system_instruction` and `answer_style_instruction`. They are the bot's own prompt and its voice
 * guidance, they are moving to a management-only projection — the keys stay and become `null` for a
 * caller without `bots.manage` — and a `null` there will mean "not shown to you", NOT "not set". A
 * list column over a field whose absence has two meanings is a column that lies to half its readers,
 * so neither appears here and neither should appear in a cell later.
 *
 * ── TENANT TEXT IS A JSX CHILD, NEVER MARKUP ───────────────────────────────────────────────────
 * `name` and `slug` are operator-supplied. React escapes a child; `dangerouslySetInnerHTML` is banned
 * repo-wide by ESLint. Nothing here reaches for a formatter that could re-introduce markup.
 */
const helper = createServerColumnHelper<BotResource>();

export const BOT_COLUMNS = helper.columns([
  /**
   * The card layout's heading, and the only column carrying `card: 'title'` — a card with no title is
   * a card nobody can identify, and the first cell is only a fallback.
   */
  helper.accessor('name', {
    header: 'Name',
    meta: { card: 'title' },
    cell: ({ getValue }) => <span className="font-medium">{getValue()}</span>,
  }),
  /**
   * The human handle, unique PER ORGANIZATION — another organization using the same handle is not a
   * conflict, which is why every cache key derived from it carries the organization id first. Rendered
   * as secondary text (P6's closed cell-type set), not as a link: `/bots/{id}` does not exist yet, and
   * a link to a 404 is worse than a label.
   */
  helper.accessor('slug', {
    header: 'Slug',
    cell: ({ getValue }) => <span className="text-muted-foreground">{getValue()}</span>,
  }),
  helper.accessor('status', {
    header: 'Status',
    cell: ({ getValue }) => {
      const display = botStatusDisplay(getValue());
      return <StatusPill status={display.kind} label={display.label} />;
    },
  }),
  /**
   * Whether an anonymous end user may converse. NOT sortable — the endpoint does not offer it — and
   * deliberately NOT a pill: P8's pill is a lifecycle signal, and a second coloured badge in the same
   * row would put two competing status channels beside each other.
   */
  helper.accessor('access_mode', {
    header: 'Access',
    enableSorting: false,
    cell: ({ getValue }) => botAccessModeLabel(getValue()),
  }),
  /**
   * `card: 'hidden'` — present in the table, dropped from the below-768px row-card. A card with five
   * labelled pairs is a table with extra steps, and of the four secondary columns this is the one an
   * operator scanning a phone needs least.
   *
   * `title` carries the machine-readable timestamp, so the exact value is one hover or one accessible
   * description away from the formatted one. The formatter is `features/providers`', imported rather
   * than pasted: its own note names three copies as the moment a duplicate becomes a move, and this
   * would have been the fourth.
   */
  helper.accessor('created_at', {
    id: 'id',
    header: 'Created',
    meta: { card: 'hidden' },
    cell: ({ getValue }) => {
      const timestamp = getValue();
      return (
        <span className="text-muted-foreground" title={timestamp ?? undefined}>
          {formatTimestamp(timestamp)}
        </span>
      );
    },
  }),
]);

/** The number of skeleton columns the first load draws, so the skeleton and the table cannot drift. */
export const BOT_COLUMN_COUNT = BOT_COLUMNS.length;
