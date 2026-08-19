'use client';

import { useQuery } from '@tanstack/react-query';
import { BotIcon } from 'lucide-react';

import { DataTableSurface } from '@/components/data-table';
import { ServerDataTable, type ServerDataTableStatus } from '@/components/server-data-table';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/states';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useCurrentOrgId, useOrgKey, useSession } from '@/features/auth/session-context';
import { BOT_COLUMN_COUNT, BOT_COLUMNS } from '@/features/bots/bot-columns';
import { BotSearchField } from '@/features/bots/bot-search-field';
import { BOT_FILTER_PARAM, BOT_LIST_CONFIG, fetchBotPage } from '@/features/bots/api';
import { useTableParams } from '@/lib/table/use-table-params';

/**
 * `/bots` — one page of the CURRENT organization's bots, server-sorted, server-filtered and
 * server-paginated.
 *
 * ── THE ORGANIZATION IS IN THE SESSION AND IN NONE OF NEXT'S CACHE KEYS ────────────────────────
 * `/bots` is ONE URL for every organization an administrator belongs to. Not one byte of this list is
 * produced by the Next server — not "produced and marked no-store", not produced — because every Next
 * cache is keyed by URL or by arguments and the organization is in the session cookie, which is in
 * none of them. The rows arrive from a browser fetch keyed in the one cache this app designs itself:
 *
 *     ['org', orgId, 'bots', { page, per_page, sort, dir, filter? }]
 *
 * EVERY SERVER-VISIBLE VALUE IS IN THAT KEY, and it is `view.requestParams` — the SAME object that
 * goes on the wire — so a page-2 response can never be cached under the page-1 question, and a sorted
 * view can never be served from an unsorted entry. The key is built by `useOrgKey()` and never by
 * hand; the organization is a cache NAMESPACE and never a request parameter, and `toRequestParams`
 * has a spec asserting it emits no `org`/`organization_id`.
 *
 * ── ON AN ORGANIZATION SWITCH THIS SCREEN IS A HARD RESET, NOT A REFETCH ───────────────────────
 * The URL does not change when the organization does — the page, the sort and the filter in it were
 * chosen against the tenant the admin just left. `useResetQueryClient()` REPLACES the QueryClient
 * (a `clear()` keeps the same observers, which immediately refetch and can still resolve an in-flight
 * request into the new cache) and the session re-enters `loading`, so this subtree unmounts and the
 * query goes with it. The `orgId` prefix is what still holds if a future refactor skips that step.
 *
 * `placeholderData` is the one place that discipline could be undone, and it is handled below.
 *
 * ── FOUR STATES, SHIPPED WITH THE SUCCESS STATE ────────────────────────────────────────────────
 * Loading (a table-shaped skeleton, plus a separate refetch bar that never blanks correct rows),
 * empty — SPLIT into first-run and filtered, because offering "create your first bot" to somebody
 * whose search matched nothing is the bug that split exists to prevent — error, and the out-of-range
 * page that only a typed URL can reach. `ServerDataTable` owns the switch; this component supplies the
 * copy. Forbidden is deliberately NOT one of them here: see `requiredRole` below.
 */
export function BotsScreen() {
  const session = useSession();
  const orgId = useCurrentOrgId();

  if (session.status === 'loading') {
    // The skeleton mirrors the loaded layout box for box, or the page reflows when the data arrives
    // and reads as a rendering bug.
    return (
      <DataTableSurface>
        <TableSkeleton columns={BOT_COLUMN_COUNT} />
      </DataTableSurface>
    );
  }

  if (session.status !== 'authenticated') {
    // `anonymous` and `unavailable`, and NOTHING is rendered for either — `<SessionProvider>` above
    // owns both: it bounces to `/login` for `anonymous` and renders the class-mapped "your account
    // could not be loaded" panel for `unavailable`.
    return null;
  }

  if (orgId === null) {
    // Reachable BY DESIGN: login succeeds with `current_organization_id: null` for a user whose
    // memberships are all `invited` or `suspended`. `useOrgKey()` would THROW here, because a key
    // built from `undefined` is ONE shared namespace for every org-less state on the platform — so the
    // gate is a mount condition rather than an `enabled` flag, and the throw is unreachable.
    return (
      <Alert variant="info">
        <AlertTitle>No organization selected</AlertTitle>
        <AlertDescription>
          Bots belong to an organization. Choose one on the settings page first.
        </AlertDescription>
      </Alert>
    );
  }

  return <BotsForOrganization orgId={orgId} />;
}

/**
 * Mounted only with a real organization.
 *
 * ── NO `requiredRole`, AND THAT IS THE HONEST ANSWER RATHER THAN AN OMISSION ───────────────────
 * `ServerDataTable` renders the FORBIDDEN state — the one that names a role and who can grant it —
 * only when a `requiredRole` is supplied. ALL FOUR roles hold `bots.view` (owner, admin, knowledge
 * manager and analyst; `routes/api_admin.php` records why), so an `authorization` failure on this list
 * is never a role problem. It is a stale organization id, a membership that was revoked, or a bot list
 * belonging to a tenant this session is no longer in — and every one of those 404s at BINDING time and
 * is rendered as `authorization` by the deny split. Naming a role would be a dead end pointing at the
 * wrong door, so the class-mapped "You do not have access to this." is what shows, which is correct
 * for all of them.
 *
 * ── NO `retry` PROPERTY, AND NO POLLING ────────────────────────────────────────────────────────
 * The `retry` identifier is an ESLint error outside `src/lib/query/client.ts`, and the global
 * predicate there is already the right policy. Nothing on this list changes without somebody acting,
 * so a `refetchInterval` that twenty open admin tabs turn into pure status traffic against
 * `throttle:admin` would buy nothing; the create and edit paths invalidate this key instead.
 */
function BotsForOrganization({ orgId }: { readonly orgId: string }) {
  const orgKeyFor = useOrgKey();
  const view = useTableParams(BOT_LIST_CONFIG);
  const botsKey = orgKeyFor('bots', view.requestParams);

  const bots = useQuery({
    queryKey: botsKey,
    // `signal` forwarded: `queryClient.cancelQueries()` is a no-op against a queryFn that drops it,
    // and cancelling in-flight reads is step 2 of both logout and the organization switch.
    queryFn: ({ signal }) => fetchBotPage(orgId, view.requestParams, signal),
    /**
     * KEEP-PREVIOUS-DATA, GATED ON THE ORGANIZATION — and the gate is the whole point.
     *
     * Paging without it blanks the table to a skeleton on every Next, which is a reflow per page.
     * `keepPreviousData` unguarded brings the leak back: the helper renders the previous KEY's data
     * while the new key loads, and once `orgId` is in the key the previous key can be the previous
     * ORGANIZATION. So the previous key's namespace segment is compared against the current one, and a
     * mismatch renders nothing rather than another tenant's bots for ~200ms.
     *
     * Segment 1 is `orgId` by construction: `orgKey()` is `['org', orgId, ...rest]`.
     */
    placeholderData: (previous, previousQuery) =>
      previousQuery?.queryKey[1] === orgId ? previous : undefined,
  });

  /**
   * `'error'` REPLACES the rows, which is right only when there are none to keep. A refetch that
   * failed with a correct page still on screen is NOT that state — the user would lose the page they
   * were reading to a banner about a request they did not make — so the table stays at `'success'` and
   * the failure is rendered beside it.
   */
  const hasRows = bots.data !== undefined;
  const status: ServerDataTableStatus = hasRows ? 'success' : bots.status;
  const refetchFailed = hasRows && bots.error !== null;

  const appliedFilter = view.params.filters.get(BOT_FILTER_PARAM) ?? '';

  return (
    // Sections are separated by --space-8, never by a divider (the composition law).
    <div className="flex flex-col gap-8">
      {refetchFailed ? (
        <ErrorState
          title="This list could not be refreshed"
          error={bots.error}
          onRetry={() => void bots.refetch()}
        />
      ) : null}

      <ServerDataTable
        caption="Bots"
        columns={BOT_COLUMNS}
        rows={bots.data?.rows}
        // `meta.total` from the envelope. `undefined` renders the pager as a skeleton rather than as
        // "Page 1 of 1" — both a wrong total and a zero are statements, and there is no answer yet.
        rowCount={bots.data?.rowCount}
        // A STABLE server id: React keys off it, so an index would re-key every row on every page.
        getRowId={(bot) => bot.id}
        status={status}
        error={bots.error}
        // A refetch is a 2px bar under the header, never a skeleton over correct rows. `isFetching`
        // covers the placeholder-data window too, which is exactly when rows are stale-but-right.
        isFetching={bots.isFetching}
        onRetry={() => void bots.refetch()}
        view={view}
        header={
          <BotSearchField
            applied={appliedFilter}
            onApply={(value) => view.setFilter(BOT_FILTER_PARAM, value)}
            onClear={view.clearFilters}
          />
        }
        emptyState={
          <EmptyState
            glyph={BotIcon}
            title="No bots yet"
            /* FIRST-RUN. The onboarding moment, and it says what a bot IS rather than what to click:
               the create path is a later step in this batch, and a primary action that goes nowhere
               is a worse answer than a sentence. */
            body="A bot answers from the sources you give it, in the voice you configure. Create one to get started."
          />
        }
        /* FILTERED. It restates what was asked for, because a user who cannot see their own query
           cannot tell a too-narrow filter from a broken screen — and neither can support, when the
           customer reports it. The filter is tenant-typed text and is a JSX child, never markup. */
        describeFilter={<>No bots match “{appliedFilter}”.</>}
      />
    </div>
  );
}
