'use client';

import { useQuery } from '@tanstack/react-query';
import { LibraryIcon } from 'lucide-react';
import { useMemo } from 'react';

import { DataTableSurface } from '@/components/data-table';
import { ServerDataTable, type ServerDataTableStatus } from '@/components/server-data-table';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/states';
import { TableSearchField } from '@/components/table-search-field';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useCurrentOrgId, useOrgKey, useSession } from '@/features/auth/session-context';
import {
  SOURCE_FILTER_PARAM,
  SOURCE_LIST_CONFIG,
  SOURCE_VIEW_ROLE,
  canManageSources,
  canViewSources,
  fetchSourcePage,
  sourcePollInterval,
} from '@/features/sources/api';
import { SourceActionsContext } from '@/features/sources/source-actions-context';
import { SOURCE_COLUMN_COUNT, SOURCE_COLUMNS } from '@/features/sources/source-columns';
import { useTableParams } from '@/lib/table/use-table-params';

/**
 * `/sources` — one page of the CURRENT organization's knowledge sources, server-sorted,
 * server-filtered, server-paginated, and POLLED while anything on it is moving.
 *
 * ── THE ORGANIZATION IS IN THE SESSION AND IN NONE OF NEXT'S CACHE KEYS ────────────────────────
 * `/sources` is ONE URL for every organization an administrator belongs to. Not one byte of this list
 * is produced by the Next server — not "produced and marked no-store", not produced — because every
 * Next cache is keyed by URL or by arguments and the organization is in the session cookie, which is
 * in none of them. The rows arrive from a browser fetch keyed in the one cache this app designs
 * itself:
 *
 *     ['org', orgId, 'sources', { page, per_page, sort, dir, filter? }]
 *
 * EVERY SERVER-VISIBLE VALUE IS IN THAT KEY, and it is `view.requestParams` — the SAME object that
 * goes on the wire — so a page-2 response can never be cached under the page-1 question. The key is
 * built by `useOrgKey()` and never by hand; the organization is a cache NAMESPACE and never a request
 * parameter.
 *
 * ── ON AN ORGANIZATION SWITCH THIS SCREEN IS A HARD RESET, NOT A REFETCH ───────────────────────
 * The URL does not change when the organization does — the page, the sort and the filter in it were
 * chosen against the tenant the admin just left. `useResetQueryClient()` REPLACES the QueryClient (a
 * `clear()` keeps the same observers, which immediately refetch and can still resolve an in-flight
 * request into the new cache) and the session re-enters `loading`, so this subtree unmounts and both
 * the query and its poll timer go with it. The `orgId` prefix is what still holds if a future
 * refactor skips that step, and `placeholderData` below is the one place that discipline could be
 * undone silently.
 *
 * ── FOUR STATES, SHIPPED WITH THE SUCCESS STATE ────────────────────────────────────────────────
 * Loading (a table-shaped skeleton, plus a refetch bar that never blanks correct rows — and a
 * five-second poll makes that distinction load-bearing rather than theoretical), empty — SPLIT into
 * first-run and filtered — error, FORBIDDEN (see `requiredRole` below, which this list genuinely
 * needs and the bot list did not), and the out-of-range page only a typed URL can reach.
 */
export function SourcesScreen() {
  const session = useSession();
  const orgId = useCurrentOrgId();

  if (session.status === 'loading') {
    // The skeleton mirrors the loaded layout box for box, or the page reflows when the data arrives
    // and reads as a rendering bug.
    return (
      <DataTableSurface>
        <TableSkeleton columns={SOURCE_COLUMN_COUNT} />
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
    // built from `undefined` is ONE shared namespace for every org-less state on the platform — so
    // the gate is a mount condition rather than an `enabled` flag, and the throw is unreachable.
    return (
      <Alert variant="info">
        <AlertTitle>No organization selected</AlertTitle>
        <AlertDescription>
          Knowledge sources belong to an organization. Choose one on the settings page first.
        </AlertDescription>
      </Alert>
    );
  }

  return (
    <SourcesForOrganization
      orgId={orgId}
      // The role the VIEWER holds HERE, read from the membership list the server itself handed us,
      // rather than from a global "am I an admin" flag: a person can be an owner of one organization
      // and an analyst in the next.
      viewerRole={
        session.organizations.find((organization) => organization.id === orgId)?.role ?? null
      }
      organizationName={
        session.organizations.find((organization) => organization.id === orgId)?.name
      }
    />
  );
}

/** Mounted only with a real organization. */
function SourcesForOrganization({
  orgId,
  viewerRole,
  organizationName,
}: {
  readonly orgId: string;
  readonly viewerRole: Parameters<typeof canViewSources>[0];
  readonly organizationName?: string;
}) {
  const orgKeyFor = useOrgKey();
  const view = useTableParams(SOURCE_LIST_CONFIG);
  // The PREFIX, for invalidation after a mutation: a status change moves a row between pages this
  // view is not looking at, so invalidating the exact key would leave the neighbours stale.
  //
  // MEMOIZED because it reaches a context value: `orgKeyFor` returns a FRESH ARRAY each call, and an
  // unstable prefix would give the actions context a new identity every render — which re-renders
  // every row's three mutations and its dialog on every poll tick. `orgKeyFor` itself is a
  // `useCallback` keyed on the organization, so this recomputes exactly when the tenant changes.
  const listKey = useMemo(() => orgKeyFor('sources'), [orgKeyFor]);
  const sourcesKey = orgKeyFor('sources', view.requestParams);

  const sources = useQuery({
    queryKey: sourcesKey,
    // `signal` forwarded: `queryClient.cancelQueries()` is a no-op against a queryFn that drops it,
    // and cancelling in-flight reads is step 2 of both logout and the organization switch.
    queryFn: ({ signal }) => fetchSourcePage(orgId, view.requestParams, signal),
    /**
     * KEEP-PREVIOUS-DATA, GATED ON THE ORGANIZATION — and the gate is the whole point.
     *
     * Paging without it blanks the table to a skeleton on every Next, which is a reflow per page.
     * `keepPreviousData` unguarded brings the leak back: the helper renders the previous KEY's data
     * while the new key loads, and once `orgId` is in the key the previous key can be the previous
     * ORGANIZATION. So the previous key's namespace segment is compared against the current one, and
     * a mismatch renders nothing rather than another tenant's documents for ~200 ms.
     *
     * Segment 1 is `orgId` by construction: `orgKey()` is `['org', orgId, ...rest]`.
     */
    placeholderData: (previous, previousQuery) =>
      previousQuery?.queryKey[1] === orgId ? previous : undefined,
    /**
     * THE POLL, IN FUNCTION FORM, WITH A STOP CONDITION — and this screen is the one in the console
     * that genuinely needs one. A source walks a fifteen-state lifecycle of which eight states are
     * transient, so a list opened right after an upload is watching six pipeline stages go past; and
     * `deleting` only becomes `deleted` when a background purge finishes, which §8.17 requires an
     * administrator to be able to SEE.
     *
     * A BARE NUMBER NEVER STOPS. That is the failure this shape exists to prevent: a tab left open on
     * this route polls for hours, each request paying a session lookup, a membership re-check and a
     * policy evaluation against `throttle:admin`, until the limiter starts rejecting real work.
     * `sourcePollInterval` returns `false` — not `0`, which means "as fast as possible" — the moment
     * no row on THIS PAGE is going anywhere. Its predicate and the two states it polls beyond
     * `status_is_processing` are documented at its definition.
     *
     * `refetchIntervalInBackground` is left at its default `false`, so a backgrounded tab stops.
     */
    refetchInterval: (query) => sourcePollInterval(query.state.data?.rows),
  });

  /**
   * `'error'` REPLACES the rows, which is right only when there are none to keep. A refetch that
   * failed with a correct page still on screen is NOT that state — and with a five-second poll it is
   * the common case, so a single blip must not throw away the page the user is reading.
   */
  const hasRows = sources.data !== undefined;
  const status: ServerDataTableStatus = hasRows ? 'success' : sources.status;
  const refetchFailed = hasRows && sources.error !== null;

  const appliedFilter = view.params.filters.get(SOURCE_FILTER_PARAM) ?? '';

  const actions = useMemo(
    () => ({ orgId, listKey, canManage: canManageSources(viewerRole) }),
    [orgId, listKey, viewerRole],
  );

  return (
    // Sections are separated by --space-8, never by a divider (the composition law).
    <div className="flex flex-col gap-8">
      {refetchFailed ? (
        <ErrorState
          title="This list could not be refreshed"
          error={sources.error}
          onRetry={() => void sources.refetch()}
        />
      ) : null}

      <SourceActionsContext.Provider value={actions}>
        <ServerDataTable
          caption="Knowledge sources"
          columns={SOURCE_COLUMNS}
          rows={sources.data?.rows}
          // `meta.total` from the envelope. `undefined` renders the pager as a skeleton rather than
          // as "Page 1 of 1" — both a wrong total and a zero are statements, and there is no answer
          // yet.
          rowCount={sources.data?.rowCount}
          // A STABLE server id: React keys off it, so an index would re-key every row on every poll.
          getRowId={(source) => source.id}
          status={status}
          error={sources.error}
          // A refetch is a 2px bar under the header, never a skeleton over correct rows — and every
          // five seconds, that is the difference between a live table and a flickering one.
          isFetching={sources.isFetching}
          onRetry={() => void sources.refetch()}
          /**
           * NAMED ONLY FOR THE VIEWER WHO IS ACTUALLY MISSING THE ROLE, which is what makes this
           * honest rather than a guess.
           *
           * An `authorization` failure has three indistinguishable causes on the wire — the deny
           * split makes 403, a foreign id and an unknown id byte-identical — so naming a role
           * unconditionally would tell an owner with a stale organization id to go and ask for a
           * promotion. But unlike `bots.view`, which all four roles hold, `sources.view` is withheld
           * from ANALYST, and the sidebar offers `/sources` to everybody: an analyst clicking it is
           * the common case and the role really is the reason. So the forbidden state is offered
           * exactly when this session's own role cannot hold the permission, and every other viewer
           * gets the class-mapped sentence, which is correct for all three of their causes.
           */
          requiredRole={canViewSources(viewerRole) ? undefined : SOURCE_VIEW_ROLE}
          organizationName={organizationName}
          view={view}
          header={
            <TableSearchField
              label="Search sources"
              // What the server ACTUALLY searches: `EloquentKnowledgeSourceRepository::FILTERABLE`
              // is `name` and `origin_url`, so an empty result is readable rather than mysterious.
              // There is no status filter on this endpoint — see `SOURCE_FILTER_PARAM`.
              placeholder="Name or URL"
              applied={appliedFilter}
              onApply={(value) => view.setFilter(SOURCE_FILTER_PARAM, value)}
              onClear={view.clearFilters}
            />
          }
          emptyState={
            <EmptyState
              glyph={LibraryIcon}
              title="No sources yet"
              /* FIRST-RUN — the onboarding moment, and it says what a source IS as well as what to
                 do. There is deliberately NO primary action here yet: the create/upload screen is a
                 later batch, and a button that goes nowhere is worse than a sentence. When it lands
                 it goes here, with a trigger label that is not a substring of the page header's. */
              body="Upload a document, add a website to crawl, or paste text. Your bots answer from what you add here."
            />
          }
          /* FILTERED. It restates what was asked for, because a user who cannot see their own query
             cannot tell a too-narrow filter from a broken screen — and neither can support, when the
             customer reports it. The filter is tenant-typed text and is a JSX child, never markup. */
          describeFilter={<>No sources match “{appliedFilter}”.</>}
        />
      </SourceActionsContext.Provider>
    </div>
  );
}
