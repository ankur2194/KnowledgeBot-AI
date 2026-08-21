'use client';

import type {
  AcknowledgementResource,
  BotResource,
  BotSourceAssignmentResource,
  Role,
  SourceDetailResource,
} from '@kb/contracts';
import { useMutation, useQueries, useQuery, useQueryClient } from '@tanstack/react-query';
import { BotIcon } from 'lucide-react';
import Link from 'next/link';
import { useMemo, useState } from 'react';

import { EmptyState, ErrorState, SkeletonLines } from '@/components/states';
import { StatusPill } from '@/components/status-pill';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { actionErrorCopy } from '@/features/auth/action-error';
import { useOrgKey } from '@/features/auth/session-context';
import { BOT_SORTABLE_COLUMNS, botStatusDisplay, fetchBotPage } from '@/features/bots/api';
import {
  SOURCE_ASSIGN_ROLE,
  canAssignSources,
  createBotSourceAssignment,
  deleteBotSourceAssignment,
  fetchGrantForSource,
} from '@/features/sources/source-assignment-api';
import { actionableConflictMessage } from '@/lib/api/actionable-conflict';

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *  WHICH BOTS MAY ANSWER FROM THIS SOURCE — the assignment surface, on the SOURCE
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * ── A GRANT IS NOT A PREFERENCE ────────────────────────────────────────────────────────────────
 * `bot_source_assignments` is where `bot_ids` — one of the four mandatory vector-search filter terms
 * (`kb-tenancy-isolation` NN2) — is resolved from. A row here is what makes a corpus REACHABLE from
 * a bot, and every downstream check agrees with it; there is no later layer that catches a bad one.
 * That is why nothing on this panel is optimistic and why the copy never says "assigned" and means
 * "answering": reachability is the AND of the organization, the grant's own `enabled`, the source's
 * status and the item's active-version pointer, and this control owns exactly one of the four.
 *
 * ── THE DIRECTION IS INVERTED AGAINST THE API, ON PURPOSE, AND IT COSTS A REQUEST PER BOT ──────
 * All three endpoints are children of a BOT (`…/bots/{bot}/source-assignments`) and none of them
 * answers "which bots hold a grant on this source": there is no source-side route, `source_id` is
 * not a filter, and the index's `filter` searches the SOURCE's name because the grant row holds no
 * text of its own. So this panel asks each bot in turn — one cheap, indexed, org-scoped query per
 * bot on the page — and the page is deliberately small (`BOTS_PER_PAGE`) so the fan-out is bounded
 * against `throttle:admin`'s 120/minute per (organization, user).
 *
 * THE MISSING ENDPOINT IS REPORTED RATHER THAN WORKED AROUND FURTHER. `GET
 * .../sources/{source}/bot-assignments` would collapse the whole fan-out into one request and is
 * `control-plane-engineer`'s to add. What it is NOT is an argument for moving this screen onto the
 * bot: `App\Enums\OrgRole::grants()` grants `knowledge_manager` the `bots.view` permission FOR this
 * screen, in as many words — "the assignment screen is a list of bots".
 *
 * ── THE FOUR STATES ────────────────────────────────────────────────────────────────────────────
 *   Loading    a skeleton at the loaded shape. A refetch never blanks rows that are still correct.
 *   Empty      FIRST-RUN only: this organization has no bots. There is no filter on this panel, so
 *              there is no filtered-empty state and there must not be one — offering "Clear filters"
 *              to somebody who has not made a bot yet is the bug that split exists to prevent.
 *   Error      the class-mapped sentence plus the `request_id`, retry gated on the envelope's own
 *              `retryable`. A per-bot lookup that failed renders IN ITS ROW and never as "not
 *              assigned", because those are different facts.
 *   Forbidden  THE SAME RENDER AS ERROR for the bot list, and that is the honest answer: all four
 *              roles hold `bots.view`, so an `authorization` failure here is never a role gap.
 *              `sources.assign` is a different matter and is handled as an affordance — a viewer
 *              without it sees no buttons and one sentence naming the role, rather than a column of
 *              disabled controls.
 */

/**
 * HOW MANY BOTS ONE PAGE OF THIS PANEL SHOWS, AND THEREFORE HOW MANY REQUESTS IT ISSUES ON MOUNT.
 *
 * Ten, and the number is sized against the fan-out rather than against how the card looks. Each row
 * costs one `GET …/bots/{bot}/source-assignments`, and every one of those pays a session lookup, a
 * membership re-check and a policy evaluation in Laravel against a 120/minute bucket shared with
 * everything else the admin is doing. Ten is one twelfth of that budget for a page view; the
 * endpoint's own ceiling of 100 would be five sixths of it, for a card nobody can read at a glance
 * anyway. An organization with more bots pages through them.
 */
const BOTS_PER_PAGE = 10;

/**
 * The column the bot list is ordered by here, DERIVED from the dumped `IndexBotsRequest` rather than
 * typed out: `sort` reaches an `ORDER BY` and is closed with `Rule::in(...)`, so a hand-written value
 * the manifest stops permitting is a 422 on a request the user made by opening a page. `name` is the
 * order an operator scans a list of bots in; the fallback keeps the panel working rather than
 * failing if that column ever leaves the set.
 */
const BOT_ORDER = BOT_SORTABLE_COLUMNS.includes('name')
  ? 'name'
  : (BOT_SORTABLE_COLUMNS[0] ?? 'id');

export function SourceBotAssignments({
  orgId,
  source,
  sourceKey,
  viewerRole,
  organizationName,
}: {
  readonly orgId: string;
  readonly source: SourceDetailResource;
  /** `['org', orgId, 'sources', sourceId]` — every key below is built FROM it, never from a second
   *  `useOrgKey()` call, so the two cannot disagree about which organization they name and one
   *  invalidation of the source reaches all of them. */
  readonly sourceKey: readonly unknown[];
  readonly viewerRole: Role | null;
  readonly organizationName?: string;
}) {
  const orgKeyFor = useOrgKey();
  const queryClient = useQueryClient();
  /**
   * THE PAGE IS COMPONENT STATE AND NOT A URL PARAMETER, WHICH IS A DEVIATION AND IS WRITTEN DOWN.
   *
   * `kb-ui-patterns` puts a list's filter, sort and page in the URL, because that is what makes a
   * filtered view shareable and a "no results" report debuggable. This pager has no filter and no
   * sort control, and "page 2 of the bots on this document" is not a view anybody shares or
   * bookmarks — while `page`/`per_page`/`sort`/`dir` are RESERVED names in `lib/table/params.ts`,
   * so writing them here would collide with any real table this route later grows. Local state, and
   * a pager that only renders when there is more than one page.
   */
  const [pageIndex, setPageIndex] = useState(0);

  const botParams = useMemo(
    () => ({
      page: String(pageIndex + 1),
      per_page: String(BOTS_PER_PAGE),
      sort: BOT_ORDER,
      dir: 'asc',
    }),
    [pageIndex],
  );

  /**
   * `['org', orgId, 'bots', {page, per_page, sort, dir}]` — the SAME key shape `/bots` uses, so this
   * panel and that list share a cache entry when they ask the same question rather than holding two
   * copies of one page under two spellings. Every server-visible value is in the key and it is the
   * same object that goes on the wire, so a page-2 response can never be cached under the page-1
   * question.
   */
  const bots = useQuery({
    queryKey: orgKeyFor('bots', botParams),
    queryFn: ({ signal }) => fetchBotPage(orgId, botParams, signal),
  });

  const rows = bots.data?.rows;

  /**
   * ONE LOOKUP PER BOT ON THE PAGE, EACH UNDER ITS OWN KEY.
   *
   *     ['org', orgId, 'sources', sourceId, 'assignments', botId]
   *
   * A PREFIX EXTENSION OF THE SOURCE'S KEY, so invalidating the source invalidates every one of them
   * and a delete or a reprocess cannot leave this panel describing a document that has moved. The
   * organization is at segment 1 by construction, because the key is built from `sourceKey`.
   *
   * `useQueries` and not one query per row component: the mutations below live HERE so that the
   * table and the below-768px card stack drive the SAME write, and a hook inside a row would give
   * one bot two query instances across the two layouts.
   *
   * MEMOIZED on the rows, because a fresh `queries` array each render re-subscribes every observer.
   */
  const grantQueries = useQueries({
    queries: useMemo(
      () =>
        (rows ?? []).map((bot) => ({
          queryKey: [...sourceKey, 'assignments', bot.id],
          queryFn: ({ signal }: { signal: AbortSignal }) =>
            // `signal` forwarded on every page of the walk: `cancelQueries()` is a no-op against a
            // queryFn that drops it, and this is the one fetcher in the app that can issue more than
            // one request — an abandoned organization switch must not keep asking.
            fetchGrantForSource(orgId, bot.id, source, signal),
        })),
      [rows, sourceKey, orgId, source],
    ),
  });

  const canAssign = canAssignSources(viewerRole);

  const invalidateGrants = () => {
    // THE PREFIX, so every bot on the page is re-read rather than just the one that changed: the cap
    // refusal (`MAX_PER_BOT`) and the suspended-organization 409 are facts about the whole set, and
    // a single-row re-read would leave the others stating something that has stopped being true.
    void queryClient.invalidateQueries({ queryKey: [...sourceKey, 'assignments'] });
  };

  /**
   * THE TWO WRITES. `variables` IS THE IN-FLIGHT RECORD AND THERE IS NO SECOND PIECE OF STATE.
   *
   * `mutation.variables` is react-query's own record of the argument currently in flight, so "which
   * row is busy" and "what was it asked to do" come from the mutation itself — no parallel state to
   * disagree with it, NO `onMutate`, no cache write, and no rollback path. The cache holds the
   * SERVER's answer throughout, so a refusal is displayed as a refusal rather than papered over by a
   * value we invented. That is not a style preference here: a grant is a vector-search filter term,
   * and an optimistic one claims a bot can read a document the server may have refused it.
   *
   * NO `retry` ON EITHER — the identifier is an ESLint error outside `lib/query/client.ts`, where
   * mutations are pinned at zero attempts. Neither carries an `Idempotency-Key`, so a replayed POST
   * is a 422 duplicate and a replayed DELETE is a 404; the controls are disabled while pending,
   * which is the double-click half of the same rule.
   */
  const grant = useMutation<BotSourceAssignmentResource, Error, AssignmentIntent>({
    mutationFn: ({ botId }) => createBotSourceAssignment(orgId, botId, source.id),
    onSettled: invalidateGrants,
  });

  const withdraw = useMutation<AcknowledgementResource, Error, AssignmentIntent>({
    mutationFn: ({ botId, assignmentId }) =>
      // Unreachable without an id: the Remove control only renders for a row whose lookup RESOLVED
      // to a grant. The throw names the wiring bug rather than sending `…/undefined`, which would
      // 404 at binding time and render as a permissions problem.
      assignmentId === undefined
        ? Promise.reject(new Error('Withdrawing a grant needs the assignment id.'))
        : deleteBotSourceAssignment(orgId, botId, assignmentId),
    onSettled: invalidateGrants,
  });

  const busy = grant.isPending ? grant.variables : withdraw.isPending ? withdraw.variables : null;
  const busyVerb = grant.isPending ? 'Assigning' : 'Removing';

  /** The class-mapped sentence, or the server's own actionable one for the suspended-organization
   *  409 — which arrives as `internal_dependency` + `retryable: false`, the pair a genuine 500 also
   *  carries, so only the envelope's `actionable` flag separates them. The envelope's `message` is
   *  never rendered on any other path: it is operator-facing. */
  const writeError =
    grant.error === null && withdraw.error === null
      ? null
      : (() => {
          const error = grant.error ?? withdraw.error;
          return actionableConflictMessage(error) ?? actionErrorCopy(error);
        })();

  return (
    <Card>
      <CardHeader>
        <CardTitle>Bots that can use this source</CardTitle>
        <CardDescription>
          A bot answers only from the sources it has been given. Assigning one here is instant and
          reversible — it grants access to the document and copies nothing.
        </CardDescription>
      </CardHeader>

      <CardContent className="flex flex-col gap-4">
        {canAssign ? null : (
          // SAID ONCE, and phrased as a fact about this organization rather than as an error. A
          // viewer who may not assign sees no buttons at all: a control a user may not use is HIDDEN
          // rather than disabled, and a disabled control with no explanation is a puzzle.
          <Alert variant="info">
            <AlertTitle>You can see these grants but not change them</AlertTitle>
            <AlertDescription>
              Assigning a source to a bot needs the {SOURCE_ASSIGN_ROLE} role in{' '}
              {organizationName ?? 'this organization'}.
            </AlertDescription>
          </Alert>
        )}

        {/* THE IN-FLIGHT SENTENCE, and it is a separate channel from the disabled button rather than
            a duplicate of it. `aria-busy` on a control is not announced by any screen reader on its
            own, and `disabled` is announced as "unavailable" with no reason — so without this the
            only feedback for a grant in flight is visual. `role="status"` is polite: it is read
            after whatever the click already announced, and it is EMPTY the rest of the time so
            nothing is re-announced when the row settles. The failure is a separate `role="alert"`
            below; success is the re-read row itself. */}
        <p role="status" className="sr-only">
          {busy === null ? '' : `${busyVerb} ${source.name} for ${busy.botName}…`}
        </p>

        {writeError === null ? null : (
          // AN INLINE BANNER AND NOT A TOAST: this is an outcome the operator has to act on — try
          // again, remove another source first, or ask for a suspension to be lifted — and a toast
          // for that is a message that disappears while they are still reading it.
          <Alert variant="destructive">
            <AlertTitle>That change was not saved</AlertTitle>
            <AlertDescription>{writeError}</AlertDescription>
          </Alert>
        )}

        {bots.isPending ? <SkeletonLines lines={3} /> : null}

        {bots.error === null ? null : (
          <ErrorState
            title={
              rows === undefined
                ? 'The bots in this organization could not be loaded'
                : 'The list of bots could not be refreshed'
            }
            error={bots.error}
            onRetry={() => void bots.refetch()}
          />
        )}

        {rows === undefined ? null : rows.length === 0 ? (
          <EmptyState
            glyph={BotIcon}
            title="No bots yet"
            body="A bot is the thing that answers questions. Once you have one, assign this source to it here and it can start answering from this document."
            action={
              <Button asChild>
                <Link href="/bots">Go to bots</Link>
              </Button>
            }
          />
        ) : (
          <ul className="flex flex-col gap-2">
            {rows.map((bot, index) => (
              <BotAssignmentRow
                key={bot.id}
                bot={bot}
                source={source}
                // `.at(index)` and not `grantQueries[index]`: `useQueries` returns results in the
                // order it was given the queries, so position IS the join — but a computed member
                // read is the `security/detect-object-injection` sink and `.at()` is the same read
                // without it, typed `| undefined`, which the row already handles.
                lookup={grantQueries.at(index)}
                canAssign={canAssign}
                pendingVerb={busy?.botId === bot.id ? busyVerb : null}
                anyPending={busy !== null}
                onGrant={() => grant.mutate({ botId: bot.id, botName: bot.name })}
                onWithdraw={(assignmentId) =>
                  withdraw.mutate({ botId: bot.id, botName: bot.name, assignmentId })
                }
              />
            ))}
          </ul>
        )}

        {bots.data === undefined || bots.data.rowCount <= BOTS_PER_PAGE ? null : (
          <BotPager
            pageIndex={pageIndex}
            rowsOnPage={rows?.length ?? 0}
            rowCount={bots.data.rowCount}
            onPrevious={() => setPageIndex((page) => Math.max(0, page - 1))}
            onNext={() => setPageIndex((page) => page + 1)}
          />
        )}
      </CardContent>
    </Card>
  );
}

/** What one write is about. `botName` travels with it purely so the live region can name the row
 *  without reaching back into a list that may have been re-read since the click. */
interface AssignmentIntent {
  readonly botId: string;
  readonly botName: string;
  /** Present on a withdrawal only — the GRANT's own ULID, which is what `DELETE` addresses. */
  readonly assignmentId?: string;
}

/** The shape `useQueries` hands back per row, narrowed to what this row reads. */
interface GrantLookup {
  /** `null` is a RESOLVED "no grant"; `undefined` is "this query has not resolved". TanStack Query
   *  refuses a `queryFn` that returns `undefined`, which is why the fetcher answers `null`. */
  readonly data?: BotSourceAssignmentResource | null;
  readonly error: Error | null;
  readonly isPending: boolean;
}

/**
 * ONE BOT, AND WHAT IS TRUE OF IT FOR THIS SOURCE.
 *
 * ── THREE OUTCOMES AND NOT TWO ─────────────────────────────────────────────────────────────────
 * A grant, no grant, and WE COULD NOT TELL. The third is not pedantry: a lookup that failed
 * rendering as "not assigned" would offer an Assign button whose click the server answers with a 422
 * duplicate, and would tell an operator debugging a silent bot that the grant they are looking at is
 * absent. So a failed lookup renders the class-mapped sentence and NO control.
 *
 * ── "ASSIGNED" IS NEVER RENDERED AS "ANSWERING" ────────────────────────────────────────────────
 * Reachability is the AND of four terms and this row owns one. A bot can hold a live grant on a
 * source whose ingestion never completed and answer nothing from it, which is exactly the state an
 * operator opens this screen to explain — so when nothing in the source is live the row says so,
 * beside the grant rather than instead of it.
 */
function BotAssignmentRow({
  bot,
  source,
  lookup,
  canAssign,
  pendingVerb,
  anyPending,
  onGrant,
  onWithdraw,
}: {
  readonly bot: BotResource;
  readonly source: SourceDetailResource;
  readonly lookup: GrantLookup | undefined;
  readonly canAssign: boolean;
  /** `'Assigning'` / `'Removing'` while THIS row's write is in flight, `null` otherwise. Read from
   *  `mutation.variables`, which is the only record of it — there is no optimistic write. */
  readonly pendingVerb: string | null;
  /** Any write in flight anywhere in the panel. Every control is disabled for it, because two grants
   *  racing against the per-bot cap produce a refusal nobody can attribute to a click. */
  readonly anyPending: boolean;
  readonly onGrant: () => void;
  readonly onWithdraw: (assignmentId: string) => void;
}) {
  const status = botStatusDisplay(bot.status);
  const grant = lookup?.data ?? null;
  const unknown = lookup?.error != null;
  const nothingLive = source.active_version_count === 0;

  return (
    <li
      // `aria-busy` ON THE ROW THAT IS BUSY rather than one flag for the card: a screen reader
      // exploring the list while one grant is being written is told which row is still moving.
      aria-busy={pendingVerb !== null}
      className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg bg-card-inset p-card-pad-sm"
    >
      {/* `basis-48` on the text column, for the reason `upload-file-row.tsx` measured at 375px: the
          button beside it is `shrink-0`, and without a basis flex gives the button what it needs and
          the name what is left — which can be one character. */}
      <span className="flex min-w-0 flex-1 basis-48 flex-col gap-1">
        {/* TENANT-AUTHORED TEXT AS A JSX CHILD. `break-words`, not `break-all`: a bot name is prose
            and breaking inside every word turns one row into a column of letters. */}
        <span className="flex flex-wrap items-center gap-2">
          <span className="text-base font-medium break-words">{bot.name}</span>
          <StatusPill status={status.kind} label={status.label} />
        </span>

        <span className="text-sm text-muted-foreground">
          {lookup?.isPending === true ? (
            'Checking…'
          ) : unknown ? (
            // NOT "not assigned". The lookup failed, and the two are different facts.
            <>Whether this bot has this source could not be checked. {actionErrorCopy(lookup?.error)}</>
          ) : grant === null ? (
            'Not assigned. This bot cannot see this source.'
          ) : grant.enabled === false ? (
            /* A grant that exists and is switched off. Nothing in this console creates one — the
               POST sends no `enabled` and the server defaults it to true — and there is no endpoint
               that edits an assignment, so the only way back is remove and re-assign. Rendered
               rather than hidden, because a bot pointed at a switched-off grant is precisely the
               "why is this bot not using that document" case. */
            'Assigned but switched off for this bot. Remove it and assign it again to turn it back on.'
          ) : nothingLive ? (
            'Assigned — but nothing in this source is live, so this bot has nothing to answer from yet.'
          ) : (
            'Assigned. This bot may answer from this source.'
          )}
        </span>
      </span>

      {canAssign && !unknown && lookup?.isPending !== true ? (
        <Button
          type="button"
          variant={grant === null ? 'default' : 'outline'}
          size="sm"
          className="shrink-0"
          disabled={anyPending}
          // THE BOT'S NAME IS IN THE ACCESSIBLE NAME. A column of identical "Assign" buttons is
          // unusable with a screen reader and resolves to N elements for a locator. The two verbs
          // are not substrings of one another, so a query for either resolves exactly one control.
          aria-label={
            grant === null ? `Assign this source to ${bot.name}` : `Remove this source from ${bot.name}`
          }
          onClick={() => {
            if (grant === null) onGrant();
            else onWithdraw(grant.id);
          }}
        >
          {pendingVerb === null ? (grant === null ? 'Assign' : 'Remove') : `${pendingVerb}…`}
        </Button>
      ) : null}
    </li>
  );
}

/**
 * PREVIOUS / NEXT ONLY, AND ONLY WHEN THERE IS MORE THAN ONE PAGE.
 *
 * `rowCount` is the envelope's own `meta.total`, never `rows.length`: the second would make the card
 * claim this organization has ten bots when it has forty. There is no page-size control, because the
 * size here is a fan-out budget rather than a preference (see `BOTS_PER_PAGE`).
 */
function BotPager({
  pageIndex,
  rowsOnPage,
  rowCount,
  onPrevious,
  onNext,
}: {
  readonly pageIndex: number;
  readonly rowsOnPage: number;
  readonly rowCount: number;
  readonly onPrevious: () => void;
  readonly onNext: () => void;
}) {
  const first = pageIndex * BOTS_PER_PAGE + 1;
  const last = Math.min(first + rowsOnPage - 1, rowCount);

  return (
    <div className="flex flex-wrap items-center justify-between gap-2">
      <p className="text-caption text-muted-foreground tabular-nums">
        {first}–{last} of {rowCount} bots
      </p>
      <div className="flex items-center gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={pageIndex === 0}
          onClick={onPrevious}
        >
          Previous bots
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          // Derived from the total and the page index ALONE, never from the rows in hand: under a
          // placeholder or a slow refetch `rowsOnPage` is the previous page's count and would offer
          // a Next that walks past the end.
          disabled={(pageIndex + 1) * BOTS_PER_PAGE >= rowCount}
          onClick={onNext}
        >
          Next bots
        </Button>
      </div>
    </div>
  );
}
