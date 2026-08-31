import type { AuditLogResource } from '@kb/contracts';

import { StatusPill } from '@/components/status-pill';
import { formatTimestamp } from '@/features/providers/api';
import {
  AUDIT_SORTABLE_COLUMNS,
  auditDetailEntries,
  auditOperationLabel,
  auditOutcomeKind,
} from '@/features/audit/api';
import { createServerColumnHelper } from '@/lib/table/features';

/**
 * The audit list's columns — declared ONCE, at MODULE SCOPE.
 *
 * The stability is a correctness requirement rather than tidiness: a fresh array each render
 * rebuilds the core row model and re-renders every row on unrelated state changes.
 * `helper.columns([...])` rather than a bare array literal, because the helper preserves each
 * column's value type through a variadic tuple.
 *
 * ── WHICH HEADERS SORT IS COMPUTED FROM THE MANIFEST, NOT DECIDED HERE ──────────────────────────
 * `enableSorting` is `AUDIT_SORTABLE_COLUMNS.includes(<wire name>)`, parsed out of
 * `IndexAuditLogsRequest.json`. That array has exactly ONE member today, so exactly one header is
 * clickable — which is the honest rendering of a table whose every index ends in `created_at DESC`,
 * and it becomes two the moment the endpoint says so, with no edit here.
 *
 * ── TWO COLUMNS CARRY HOSTILE OR PERSONAL DATA AND BOTH ARE JSX CHILDREN ────────────────────────
 * `user_agent` is ATTACKER-CONTROLLED FREE TEXT on the unauthenticated paths (a failed login records
 * whatever the client claimed), and `ip_address` is PII about a colleague. React escapes a child and
 * `dangerouslySetInnerHTML` is banned repo-wide by ESLint; neither is ever a link, and the user
 * agent is truncated with the full value in `title` rather than allowed to set the column's width.
 *
 * ── AND `details` IS RENDERED, NOT HIDDEN BEHIND A DRAWER ───────────────────────────────────────
 * It is what the operation RECORDED — the half a trail exists for — and its key set differs per
 * operation, so a fixed set of columns cannot show it. It goes in the row as labelled pairs, capped,
 * with the count of what is not shown. A drawer would make the common question ("what changed")
 * cost a click per row.
 */
const helper = createServerColumnHelper<AuditLogResource>();

/** How many `details` pairs a row shows before it says how many more there are. Four fits the row
 *  height at every breakpoint; the rest are counted rather than truncated silently, because a row
 *  that quietly drops half its record is worse than one that says it did. */
const DETAIL_PREVIEW = 4;

export const AUDIT_COLUMNS = helper.columns([
  /**
   * The card layout's heading, and the only column carrying `card: 'title'` — a card with no title
   * is a card nobody can identify.
   *
   * The outcome pill sits WITH the operation rather than in its own column on the card, because the
   * two are one fact: `auth.login.succeeded` and `auth.login.failed` are separate operations, so the
   * pill is confirmation rather than a second axis.
   */
  helper.accessor('operation', {
    header: 'Operation',
    enableSorting: AUDIT_SORTABLE_COLUMNS.includes('operation'),
    meta: { card: 'title' },
    cell: ({ getValue, row }) => (
      <div className="flex min-w-0 flex-col gap-1">
        <span className="font-medium">{auditOperationLabel(getValue())}</span>
        <span className="flex flex-wrap items-center gap-2">
          <StatusPill
            status={auditOutcomeKind(row.original.outcome)}
            label={row.original.outcome === 'success' ? 'Succeeded' : 'Failed'}
          />
          {row.original.subject_type === null ? null : (
            // The fully-qualified PHP class, shortened for the eye and complete in `title`. It is an
            // OPAQUE DISCRIMINATOR from an OPEN set — read values off rows, never compose them — so
            // the shortening is display only and nothing branches on it.
            <span
              className="truncate font-mono text-caption text-muted-foreground"
              title={`${row.original.subject_type}${row.original.subject_id === null ? '' : ` ${row.original.subject_id}`}`}
            >
              {row.original.subject_type.split('\\').at(-1) ?? row.original.subject_type}
            </span>
          )}
        </span>
      </div>
    ),
  }),

  /**
   * WHO. `actor_id` is a ULID and NOT a name, and this column does not pretend otherwise.
   *
   * There is no foreign key behind it — the actor may be deleted and the row must remain, which is
   * what "append-only and outlives the record it describes" means — so joining it against the member
   * list would render blank for exactly the rows an investigation cares about most. It renders the
   * id, monospaced, as the handle it is.
   *
   * `null` IS A REAL AND FREQUENT VALUE: an unauthenticated event (a failed login) and anything the
   * platform did with no person behind it. "The platform" is the honest word for it; an em dash
   * would read as missing data.
   */
  helper.accessor('actor_id', {
    header: 'Actor',
    enableSorting: AUDIT_SORTABLE_COLUMNS.includes('actor_id'),
    meta: { cardLabel: 'Actor' },
    cell: ({ getValue }) => {
      const actor = getValue();
      return actor === null ? (
        <span className="text-muted-foreground">The platform</span>
      ) : (
        <span className="font-mono text-caption" title={actor}>
          {actor}
        </span>
      );
    },
  }),

  /**
   * WHERE FROM. PII about a colleague, which is part of why this whole surface is withheld from the
   * reporting and ingestion roles — and part of why it is a plain cell rather than a link to any
   * lookup service.
   *
   * `card: 'hidden'` and `showFrom: 'xl'`: it is the least-scanned column on a narrow screen, and
   * dropping it is what keeps the actions-free table from needing a horizontal scroller between
   * 768px and 1279px.
   */
  helper.accessor('ip_address', {
    header: 'From',
    enableSorting: AUDIT_SORTABLE_COLUMNS.includes('ip_address'),
    meta: { card: 'hidden', showFrom: 'xl' },
    cell: ({ getValue, row }) => {
      const ip = getValue();
      return (
        <div className="flex min-w-0 flex-col">
          <span className="font-mono text-caption">{ip ?? '—'}</span>
          {row.original.user_agent === null ? null : (
            // ATTACKER-CONTROLLED FREE TEXT on the unauthenticated paths. A JSX child, truncated so a
            // 512-character claim cannot set the column width, with the full value in `title` — which
            // is also what a screen reader reads on request.
            <span
              className="truncate text-caption text-muted-foreground"
              title={row.original.user_agent}
            >
              {row.original.user_agent}
            </span>
          )}
        </div>
      );
    },
  }),

  /**
   * WHAT IT RECORDED. The key set differs per operation, so this is labelled pairs rather than
   * columns, capped at `DETAIL_PREVIEW` with the remainder COUNTED rather than dropped.
   *
   * No credential, token or key fragment can appear here — the writer allow-lists per operation and
   * admits a bearer only as `<name>_fingerprint` — and this cell deliberately does not re-filter:
   * a client-side deny-list over an open key set is a control that reads as one and is not.
   */
  helper.display({
    id: 'details',
    header: 'Recorded',
    meta: { cardLabel: 'Recorded' },
    cell: ({ row }) => {
      const entries = auditDetailEntries(row.original.details);
      if (entries.length === 0) {
        // `{}` is the records-nothing state and is the honest shape for something like a logout. It
        // is a DEFINED ZERO — a word, not a blank cell, which would read as a rendering failure.
        return <span className="text-muted-foreground">Nothing beyond the columns</span>;
      }

      const shown = entries.slice(0, DETAIL_PREVIEW);
      const hidden = entries.length - shown.length;

      return (
        /**
         * THE OVERFLOW COUNT IS OUTSIDE THE `<dl>`, AND IT WAS INSIDE IT UNTIL A BROWSER SAID SO.
         *
         * A `<dl>` may directly contain `<dt>`, `<dd>`, `<script>`, `<template>` and `<div>` — but a
         * `<div>` is admitted only as a WRAPPER around a term/definition group, not as a place to
         * put loose text. "3 more fields" is neither a term nor a definition; it is a note about the
         * list. Inside the `<dl>` it made every row of this table carry a `definition-list` (serious)
         * violation, and a screen reader walking the list hit unstructured content between pairs.
         *
         * `tests/e2e/admin/audit-logs.spec.ts` is what found it, on its first execution, against a
         * trail that had rows because signing in writes one — three scans red on one node. It was
         * invisible to `tests/components/audit-screen.test.tsx`, which renders the same cell and
         * asserts the same text, because a component test reads the DOM it was given and has no
         * opinion about whether that DOM is a legal definition list.
         *
         * Two nested flex columns at the same gap lay out identically to one, so nothing moved.
         */
        <div className="flex min-w-0 flex-col gap-0.5 text-caption">
          <dl className="flex min-w-0 flex-col gap-0.5">
            {shown.map(([key, value]) => (
              <div key={key} className="flex min-w-0 gap-1.5">
                <dt className="shrink-0 text-muted-foreground">{key}</dt>
                <dd className="truncate font-mono" title={value}>
                  {value}
                </dd>
              </div>
            ))}
          </dl>
          {hidden > 0 ? (
            <p className="text-muted-foreground">
              {hidden === 1 ? '1 more field' : `${hidden} more fields`}
            </p>
          ) : null}
        </div>
      );
    },
  }),

  /**
   * WHEN — and it is the only sortable column, which is why the header carries the sort affordance
   * on this table.
   *
   * `title` carries the machine-readable timestamp so the exact value is one hover or one accessible
   * description away from the formatted one. The formatter is `features/providers`', imported rather
   * than pasted.
   *
   * The `request_id` sits under it, monospaced and selectable: it is the bridge to telemetry and the
   * ONLY one — this row and the log lines for the same request carry the same value, which is what
   * lets an investigator move between two stores that must never be one store.
   */
  helper.accessor('created_at', {
    header: 'When',
    enableSorting: AUDIT_SORTABLE_COLUMNS.includes('created_at'),
    meta: { align: 'end', cardLabel: 'When' },
    cell: ({ getValue, row }) => (
      <div className="flex flex-col items-end">
        <span title={getValue()}>{formatTimestamp(getValue())}</span>
        {row.original.request_id === null ? null : (
          <code className="font-mono text-caption text-muted-foreground select-all">
            {row.original.request_id}
          </code>
        )}
      </div>
    ),
  }),
]);

/** The number of skeleton columns the first load draws, so the skeleton and the table cannot drift. */
export const AUDIT_COLUMN_COUNT = AUDIT_COLUMNS.length;
