import type { BotResource } from '@kb/contracts';
import Link from 'next/link';

import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
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
   * conflict, which is why every cache key derived from it carries the organization id first.
   *
   * ── IT IS A LINK NOW, AND IT WAS NOT BEFORE ────────────────────────────────────────────────────
   * This cell used to be plain secondary text with a note saying `/bots/{id}` did not exist and a
   * link to a 404 is worse than a label. The route exists, so this is the link — and it addresses
   * `row.original.id`, never the slug: the slug is a HANDLE that an operator may rename, while the id
   * is the ULID Laravel's `->scopeBindings()` binding resolves. A route keyed on the mutable one
   * would break every bookmark on a rename.
   *
   * The MONOSPACE is the second channel that says "this is a machine handle" without relying on the
   * link colour, and `--primary` plus an underline on hover is the repo's link treatment (the back
   * links on the two detail screens use the same one).
   */
  helper.accessor('slug', {
    header: 'Slug',
    cell: ({ getValue, row }) => (
      <Link
        href={`/bots/${row.original.id}`}
        className="font-mono text-primary underline-offset-4 hover:underline"
      >
        {getValue()}
      </Link>
    ),
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
  /**
   * THE ROW ACTION, and the reason it exists beside a slug that is already a link.
   *
   * `card: 'action'` puts it in the row-card's action row below 768px, where the slug's link is
   * buried inside a labelled `<dd>` and the card would otherwise have no way in at all — a horizontal
   * scroller is not the fallback here, because `components/data-table.tsx` replaces the table with
   * cards at that width precisely so an actions column cannot be scrolled out of sight.
   *
   * Above 768px it is the control in a PREDICTABLE POSITION: an operator scanning twenty-five rows
   * for "the thing I click" should not have to know that the second column happens to be a link. Two
   * routes to one destination is the standard table pattern, and the two are distinguishable by name
   * rather than only by position.
   *
   * ── THE NAME IS "Configure", AND THE WORD WAS CHOSEN AGAINST THE OTHER NAMES ON THE SCREEN ─────
   * Not "Settings": the sidebar has a permanent `Settings` link, accessible-name matching is a
   * case-insensitive SUBSTRING, and every locator for either would then resolve two elements. Not
   * "Edit"/"Open" alone either — the bot's name is appended in an `sr-only` span so each row's control
   * has its OWN accessible name ("Configure Support bot"), which is what makes twenty-five identical
   * links addressable by a screen-reader user and by a spec.
   *
   * `variant="link"` and not a button: it navigates, so it is an anchor, and an anchor styled as a
   * button would lose middle-click, "open in new tab" and the status bar preview. `asChild` hands the
   * button's own classes to `next/link` rather than nesting an `<a>` inside a `<button>`.
   */
  helper.display({
    id: 'actions',
    // A visually hidden header: the column has no name worth a column heading, and an empty `<th>` is
    // an unlabelled column for a screen-reader user. `cardLabel` is never read, because `card:
    // 'action'` renders outside the labelled-pair list.
    header: () => <span className="sr-only">Actions</span>,
    meta: { card: 'action', cardLabel: 'Actions', align: 'end' },
    cell: ({ row }) => (
      <Button asChild variant="link" size="sm">
        <Link href={`/bots/${row.original.id}`}>
          Configure<span className="sr-only"> {row.original.name}</span>
        </Link>
      </Button>
    ),
  }),
]);

/** The number of skeleton columns the first load draws, so the skeleton and the table cannot drift. */
export const BOT_COLUMN_COUNT = BOT_COLUMNS.length;
