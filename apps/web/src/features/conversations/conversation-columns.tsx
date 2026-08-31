import type { ConversationResource } from '@kb/contracts';
import Link from 'next/link';

import { StatusPill } from '@/components/status-pill';
import { TONE, type ToneClasses } from '@/components/tone';
import {
  CONVERSATION_SORTABLE_COLUMNS,
  channelDisplay,
  conversationStatusDisplay,
} from '@/features/conversations/api';
import { formatTimestamp } from '@/features/providers/api';
import { createServerColumnHelper } from '@/lib/table/features';
import { cn } from '@/lib/utils';

/**
 * The conversation list's columns — declared ONCE, at MODULE SCOPE.
 *
 * The stability is a correctness requirement rather than tidiness: a fresh array each render
 * rebuilds the core row model and re-renders every row on unrelated state changes.
 *
 * ── WHICH HEADERS SORT IS COMPUTED FROM THE MANIFEST, NOT DECIDED HERE ──────────────────────────
 * `enableSorting` is `CONVERSATION_SORTABLE_COLUMNS.includes(<wire name>)`, parsed out of
 * `IndexConversationsRequest.json`. Two headers are clickable today and a third becomes clickable
 * the moment the endpoint says so, with no edit here.
 *
 * ── THERE IS NO PREVIEW OF WHAT WAS SAID, AND THAT IS DELIBERATE ────────────────────────────────
 * `ConversationResource` carries no message text — the list endpoint returns the HEADER only — and
 * that is the right shape for a list: a first-message preview would put untrusted end-user text in
 * every row of a table an administrator scans, for a question ("what was this about") that the
 * transcript answers properly. The row identifies a thread; the transcript is where the words are.
 */
const helper = createServerColumnHelper<ConversationResource>();

/**
 * The tone classes, looked up through a `Map` rather than `TONE[name]`.
 *
 * The key comes from `channelDisplay`, which derives it from a value off the wire, and indexing a
 * plain object by a server-supplied value is the `security/detect-object-injection` sink. Built once
 * from `TONE`'s own entries, so the six tints are still stated in exactly one place.
 */
const TONE_CLASSES = new Map<string, ToneClasses>(Object.entries(TONE));

export const CONVERSATION_COLUMNS = helper.columns([
  /**
   * The card layout's heading, and the only column carrying `card: 'title'`.
   *
   * ── THE LINK IS THE THREAD ID, BECAUSE A THREAD HAS NO NAME ─────────────────────────────────
   * There is nothing else to identify it by: the visitor is usually anonymous, the bot is its own
   * column, and the first message is not on this shape. So the ULID is the title, monospaced as the
   * handle it is, with the participant underneath.
   *
   * `anonymous_session_id` IS NOT A CREDENTIAL — it is a one-way DIGEST of the session bearer,
   * derivable from the token and useless without it — which is what makes it safe to render and to
   * filter on. `user_id` is a member's ULID; EXACTLY ONE of the two is populated, enforced by
   * `conversations_participant_exclusive`, so the two branches below are total.
   */
  helper.accessor('id', {
    header: 'Thread',
    enableSorting: CONVERSATION_SORTABLE_COLUMNS.includes('id'),
    meta: { card: 'title' },
    cell: ({ getValue, row }) => (
      <div className="flex min-w-0 flex-col">
        {/* An INTERNAL route built from the row's own ULID. The name is a machine handle, so
            `--font-mono` says so without relying on colour. */}
        <Link
          href={`/conversations/${encodeURIComponent(getValue())}`}
          className="truncate font-mono text-sm text-link underline-offset-4 hover:underline"
        >
          {getValue()}
        </Link>
        <span className="truncate text-caption text-muted-foreground">
          {row.original.user_id !== null
            ? `Member ${row.original.user_id}`
            : row.original.anonymous_session_id !== null
              ? 'Anonymous visitor'
              : // Unreachable while the participant CHECK holds, and rendered rather than crashed:
                // a row that reached a browser has already been written, so a client that threw here
                // would take the whole page down over one malformed record.
                'Unknown participant'}
        </span>
      </div>
    ),
  }),

  /**
   * WHICH SURFACE, as a DECORATIVE categorical tint plus the word. The tone carries no meaning of
   * its own (P7), so the label is what a greyscale or CVD reader is actually reading.
   *
   * Deliberately NOT a `<StatusPill>`: a pill is a lifecycle signal, and two coloured pills in one
   * row would put two competing status channels beside each other.
   */
  helper.accessor('channel', {
    header: 'Channel',
    enableSorting: CONVERSATION_SORTABLE_COLUMNS.includes('channel'),
    cell: ({ getValue }) => {
      const display = channelDisplay(getValue());
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
   * THE THREAD'S LIFECYCLE, through the closed `<StatusPill>` vocabulary — colour AND glyph AND the
   * word.
   *
   * `expired` is `disabled` and not `failed`: the retention sweeper marked it, which is the platform
   * doing what the organization asked. A red pill would read as an incident.
   */
  helper.accessor('status', {
    header: 'Status',
    enableSorting: CONVERSATION_SORTABLE_COLUMNS.includes('status'),
    cell: ({ getValue }) => {
      const display = conversationStatusDisplay(getValue());
      return (
        <StatusPill
          status={display.kind}
          label={display.label}
          className="max-w-full whitespace-normal"
        />
      );
    },
  }),

  /**
   * WHICH BOT. A ULID and not a name: the list endpoint publishes `bot_id` and no bot title, and
   * joining it against the bot list here would be a second query whose failure would take the table
   * down for a decoration.
   *
   * It is NEVER NULL and never nulled — the foreign key is `ON DELETE RESTRICT`, so a bot that has
   * held a conversation is ARCHIVED rather than deleted, which is what keeps a transcript
   * interpretable after the bot is withdrawn. So the link always resolves to a bot that exists, and
   * may resolve to an archived one.
   *
   * `card: 'hidden'` — a card with six labelled pairs is a table with extra steps.
   */
  helper.accessor('bot_id', {
    header: 'Bot',
    enableSorting: CONVERSATION_SORTABLE_COLUMNS.includes('bot_id'),
    meta: { card: 'hidden', showFrom: 'xl' },
    cell: ({ getValue }) => (
      <Link
        href={`/bots/${encodeURIComponent(getValue())}`}
        className="truncate font-mono text-caption text-link underline-offset-4 hover:underline"
        title={getValue()}
      >
        {getValue()}
      </Link>
    ),
  }),

  /**
   * WHEN A TURN WAS LAST TAKEN — the default sort, and a DIFFERENT FACT from `updated_at`, which
   * also moves on a status flip or a consent record.
   *
   * `started_at` sits underneath because the pair is what an operator reads: a thread that started
   * three days ago and was last active four minutes ago is a returning visitor, and one where the
   * two are seconds apart is a single exchange.
   *
   * `title` carries the machine-readable timestamp so the exact value is one hover or one accessible
   * description away. The formatter is `features/providers`', imported rather than pasted.
   */
  helper.accessor('last_activity_at', {
    header: 'Last activity',
    enableSorting: CONVERSATION_SORTABLE_COLUMNS.includes('last_activity_at'),
    meta: { align: 'end', cardLabel: 'Last activity' },
    cell: ({ getValue, row }) => (
      <div className="flex flex-col items-end">
        <span title={getValue()}>{formatTimestamp(getValue())}</span>
        <span className="text-caption text-muted-foreground" title={row.original.started_at}>
          started {formatTimestamp(row.original.started_at)}
        </span>
      </div>
    ),
  }),
]);

/** The number of skeleton columns the first load draws, so the skeleton and the table cannot drift. */
export const CONVERSATION_COLUMN_COUNT = CONVERSATION_COLUMNS.length;
