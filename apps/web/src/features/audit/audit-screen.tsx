'use client';

import type { MemberResource, Role } from '@kb/contracts';
import { useQuery } from '@tanstack/react-query';
import { ScrollTextIcon } from 'lucide-react';
import { useMemo } from 'react';

import { DataTableSurface } from '@/components/data-table';
import { DateRangeFilter } from '@/components/date-range-filter';
import { FilterSelect, type FilterOption } from '@/components/filter-select';
import { ServerDataTable, type ServerDataTableStatus } from '@/components/server-data-table';
import { EmptyState, TableSkeleton } from '@/components/states';
import { ErrorState } from '@/components/states';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
  AUDIT_FROM_PARAM,
  AUDIT_LIST_CONFIG,
  AUDIT_OPERATIONS,
  AUDIT_OPERATION_PARAM,
  AUDIT_OUTCOMES,
  AUDIT_OUTCOME_PARAM,
  AUDIT_ACTOR_PARAM,
  AUDIT_UNTIL_PARAM,
  AUDIT_VIEW_ROLE,
  auditOperationLabel,
  canViewAudit,
  fetchAuditPage,
} from '@/features/audit/api';
import { AUDIT_COLUMNS, AUDIT_COLUMN_COUNT } from '@/features/audit/audit-columns';
import { useCurrentOrgId, useOrgKey, useSession } from '@/features/auth/session-context';
import { fetchMembers } from '@/features/members/api';
import { useTableParams } from '@/lib/table/use-table-params';

/**
 * `/audit-logs` — this organization's audit trail, server-sorted, server-filtered,
 * server-paginated, and READ-ONLY.
 *
 * ── THE ORGANIZATION IS IN THE SESSION AND IN NONE OF NEXT'S CACHE KEYS ─────────────────────────
 * `/audit-logs` is ONE URL for every organization an administrator belongs to. Not one byte of this
 * list is produced by the Next server — every Next cache is keyed by URL or by arguments and the
 * organization is in the session cookie, which is in none of them. The rows arrive from a browser
 * fetch keyed in the one cache this app designs itself:
 *
 *     ['org', orgId, 'audit-logs', { page, per_page, sort, dir, actor_id?, operation?, … }]
 *
 * EVERY SERVER-VISIBLE VALUE IS IN THAT KEY, and it is `view.requestParams` — the SAME object that
 * goes on the wire.
 *
 * ── ON AN ORGANIZATION SWITCH THIS SCREEN IS A HARD RESET, NOT A REFETCH ────────────────────────
 * `useResetQueryClient()` REPLACES the QueryClient and the session re-enters `loading`, so this
 * subtree unmounts and the query goes with it. The `orgId` prefix is what still holds if a future
 * refactor skips that step, and `placeholderData` below is the one place that discipline could be
 * undone silently — which is why its guard compares the previous key's namespace segment.
 *
 * ── THIS IS THIS ORGANIZATION'S TRAIL AND THE COPY NEVER SAYS OTHERWISE ─────────────────────────
 * Platform-scope rows (`organization_id IS NULL`) are excluded by the repository, deliberately — a
 * failed login for an address that belongs to no user, a platform action. §6.1 assigns those to the
 * platform owner on a surface that does not exist. So nothing here is titled "everything that
 * happened", and the empty state does not suggest that clearing a filter would reveal more.
 *
 * ── NO POLLING ──────────────────────────────────────────────────────────────────────────────────
 * An audit row appears when somebody acts, not on a schedule this screen could usefully match, and
 * nothing on the page is a transient state waiting to settle. `refetchInterval` here is twenty admin
 * tabs turning one screen into permanent traffic against `throttle:admin` for a table that is
 * append-only.
 */
export function AuditScreen() {
  const session = useSession();
  const orgId = useCurrentOrgId();

  if (session.status === 'loading') {
    return (
      <DataTableSurface>
        <TableSkeleton columns={AUDIT_COLUMN_COUNT} />
      </DataTableSurface>
    );
  }

  if (session.status !== 'authenticated') {
    return null;
  }

  if (orgId === null) {
    return (
      <Alert variant="info">
        <AlertTitle>No organization selected</AlertTitle>
        <AlertDescription>
          An audit trail belongs to an organization. Choose one on the settings page first.
        </AlertDescription>
      </Alert>
    );
  }

  const membership = session.organizations.find((organization) => organization.id === orgId);

  return (
    <AuditForOrganization
      orgId={orgId}
      viewerRole={membership?.role ?? null}
      organizationName={membership?.name}
    />
  );
}

function AuditForOrganization({
  orgId,
  viewerRole,
  organizationName,
}: {
  readonly orgId: string;
  readonly viewerRole: Role | null;
  readonly organizationName?: string;
}) {
  const orgKeyFor = useOrgKey();
  const view = useTableParams(AUDIT_LIST_CONFIG);
  const auditKey = orgKeyFor('audit-logs', view.requestParams);

  const canView = canViewAudit(viewerRole);

  const logs = useQuery({
    queryKey: auditKey,
    queryFn: ({ signal }) => fetchAuditPage(orgId, view.requestParams, signal),
    /**
     * KEEP-PREVIOUS-DATA, GATED ON THE ORGANIZATION — and the gate is the whole point.
     *
     * Paging without it blanks the table to a skeleton on every Next, which is a reflow per page.
     * `keepPreviousData` unguarded brings the leak back: the helper renders the previous KEY's data
     * while the new key loads, and once `orgId` is in the key the previous key can be the previous
     * ORGANIZATION. So the previous key's namespace segment is compared against the current one, and
     * a mismatch renders nothing rather than another tenant's audit rows for ~200 ms — which on THIS
     * table would be another tenant's colleagues, their IP addresses and their user agents.
     *
     * Segment 1 is `orgId` by construction: `orgKey()` is `['org', orgId, ...rest]`.
     */
    placeholderData: (previous, previousQuery) =>
      previousQuery?.queryKey[1] === orgId ? previous : undefined,
  });

  /**
   * The member list, for the actor picker — and it is a SEPARATE, NON-BLOCKING query.
   *
   * ── WHY A PICKER AND NOT A TEXT INPUT ───────────────────────────────────────────────────────
   * `actor_id` is a ULID. Nobody types one, and a text input for it is a filter that returns an
   * empty page for every typo with no way to tell a typo from "this person did nothing".
   *
   * ── WHY IT IS SAFE TO ASK FOR ───────────────────────────────────────────────────────────────
   * `members.view` is held by the owner and the administrator, which is exactly the set that holds
   * `audit.view` — the Administrator's grant is "everything except `members.manage_owner` and
   * `quotas.manage`". So a viewer who can read this page can read the member list, and this query
   * cannot be the thing that 403s the screen.
   *
   * ── AND IT DEGRADES RATHER THAN BLOCKING ────────────────────────────────────────────────────
   * `enabled` on the permission, and a failure renders NO actor picker rather than an error: the
   * audit table is the page, the picker is an affordance on it, and a members outage must not take
   * the trail down with it. There is no `orgId` in the request — it is in the path and in the key —
   * and the key is org-namespaced like every other.
   *
   * `staleTime` is the client default (30 s); membership changes are themselves audited, so a stale
   * picker is visible in the very table it filters.
   */
  const members = useQuery({
    queryKey: orgKeyFor('members'),
    queryFn: ({ signal }) => fetchMembers(orgId, signal),
    enabled: canView,
  });

  const actorOptions = useMemo<readonly FilterOption[]>(
    () =>
      (members.data ?? []).map((member: MemberResource) => ({
        value: member.user_id,
        // TENANT-AUTHORED TEXT as a JSX child downstream; the email disambiguates two people with
        // the same display name, which is a real state rather than a hypothetical one.
        label: `${member.name} · ${member.email}`,
      })),
    [members.data],
  );

  const operationOptions = useMemo<readonly FilterOption[]>(
    () =>
      // FROM THE MANIFEST, NEVER TYPED. 45 names today, and whatever the dumped `in:` rule says
      // tomorrow. A value outside it is a VALIDATION ERROR rather than an empty result, which is why
      // this is a select at all.
      AUDIT_OPERATIONS.map((operation) => ({
        value: operation,
        label: auditOperationLabel(operation),
      })),
    [],
  );

  const outcomeOptions = useMemo<readonly FilterOption[]>(
    () =>
      AUDIT_OUTCOMES.map((outcome) => ({
        value: outcome,
        label: outcome === 'success' ? 'Succeeded' : outcome === 'failure' ? 'Failed' : outcome,
      })),
    [],
  );

  /**
   * `'error'` REPLACES the rows, which is right only when there are none to keep. A refetch that
   * failed with a correct page still on screen is NOT that state.
   */
  const hasRows = logs.data !== undefined;
  const status: ServerDataTableStatus = hasRows ? 'success' : logs.status;
  const refetchFailed = hasRows && logs.error !== null;

  const filterValue = (name: string): string => view.params.filters.get(name) ?? '';

  return (
    // Sections are separated by --space-8, never by a divider (the composition law).
    <div className="flex flex-col gap-8">
      {refetchFailed ? (
        <ErrorState
          title="This trail could not be refreshed"
          error={logs.error}
          onRetry={() => void logs.refetch()}
        />
      ) : null}

      <ServerDataTable
        caption="Audit trail"
        columns={AUDIT_COLUMNS}
        rows={logs.data?.rows}
        // `meta.total` from the envelope. `undefined` renders the pager as a skeleton rather than as
        // "Page 1 of 1" — both a wrong total and a zero are statements, and there is no answer yet.
        rowCount={logs.data?.rowCount}
        // A STABLE server id: React keys off it. The rows are append-only, so an id here names a row
        // that will never change.
        getRowId={(log) => log.id}
        status={status}
        error={logs.error}
        isFetching={logs.isFetching}
        onRetry={() => void logs.refetch()}
        /**
         * NAMED ONLY FOR THE VIEWER WHO IS ACTUALLY MISSING THE ROLE.
         *
         * An `authorization` failure has three indistinguishable causes on the wire — the deny split
         * makes 403, a foreign id and an unknown id byte-identical — so naming a role
         * unconditionally would tell an owner with a stale organization id to go and ask for a
         * promotion. `audit.view` is the NARROWEST grant in the console (owner and admin only) and
         * the sidebar offers this route to everybody, so a knowledge manager or an analyst landing
         * here is the common case and the role really is the reason.
         */
        requiredRole={canView ? undefined : AUDIT_VIEW_ROLE}
        organizationName={organizationName}
        view={view}
        header={
          <div className="flex flex-wrap items-end gap-3">
            <FilterSelect
              // UNIQUE ON THE PAGE. Substring role-name matching resolves "Actor" against nothing
              // else here, and would resolve a bare "Filter" against every control.
              label="Actor"
              anyLabel="Anyone"
              options={actorOptions}
              applied={filterValue(AUDIT_ACTOR_PARAM)}
              onChange={(value) => view.setFilter(AUDIT_ACTOR_PARAM, value)}
              className="w-56"
            />
            <FilterSelect
              label="Operation"
              anyLabel="Any operation"
              options={operationOptions}
              applied={filterValue(AUDIT_OPERATION_PARAM)}
              onChange={(value) => view.setFilter(AUDIT_OPERATION_PARAM, value)}
              className="w-64"
            />
            <FilterSelect
              label="Outcome"
              anyLabel="Any outcome"
              options={outcomeOptions}
              applied={filterValue(AUDIT_OUTCOME_PARAM)}
              onChange={(value) => view.setFilter(AUDIT_OUTCOME_PARAM, value)}
              className="w-40"
            />
            <DateRangeFilter
              fromLabel="Audited from"
              untilLabel="Audited until"
              from={filterValue(AUDIT_FROM_PARAM)}
              until={filterValue(AUDIT_UNTIL_PARAM)}
              onFrom={(value) => view.setFilter(AUDIT_FROM_PARAM, value)}
              onUntil={(value) => view.setFilter(AUDIT_UNTIL_PARAM, value)}
            />
          </div>
        }
        emptyState={
          <EmptyState
            glyph={ScrollTextIcon}
            title="Nothing has been audited yet"
            /* FIRST-RUN. It says what the trail IS as well as why it is empty, and it deliberately
               offers NO primary action: there is nothing to create here, and the only way to add a
               row is to do something elsewhere. A button would be an invitation to go and perform an
               audited action for its own sake. */
            body="Sign-ins, invitations, credential rotations, bot changes and source lifecycle events are recorded here as they happen. Rows are append-only and cannot be edited."
          />
        }
        /* FILTERED. It restates what was asked for, because a user who cannot see their own query
           cannot tell a too-narrow filter from a broken screen — and neither can support, when the
           customer reports it. The filters are closed vocabularies and dates, so this is our own
           copy rather than tenant text; the actor id is a ULID and is named as an id. */
        describeFilter={<>No audited events match these filters.</>}
      />

      <p className="text-caption text-muted-foreground">
        This is {organizationName ?? 'this organization'}&rsquo;s trail. Platform-level events that
        belong to no organization are not shown here.
      </p>
    </div>
  );
}
