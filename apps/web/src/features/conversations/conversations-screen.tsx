'use client';

import type { BotResource, Role } from '@kb/contracts';
import { useQuery } from '@tanstack/react-query';
import { MessagesSquareIcon } from 'lucide-react';
import { useMemo } from 'react';

import { DataTableSurface } from '@/components/data-table';
import { DateRangeFilter } from '@/components/date-range-filter';
import { FilterSelect, type FilterOption } from '@/components/filter-select';
import { ServerDataTable, type ServerDataTableStatus } from '@/components/server-data-table';
import { EmptyState, ErrorState, TableSkeleton } from '@/components/states';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useCurrentOrgId, useOrgKey, useSession } from '@/features/auth/session-context';
import { fetchBotPage } from '@/features/bots/api';
import {
  CONVERSATION_BOT_PARAM,
  CONVERSATION_CHANNELS,
  CONVERSATION_CHANNEL_PARAM,
  CONVERSATION_FROM_PARAM,
  CONVERSATION_LIST_CONFIG,
  CONVERSATION_STATUSES,
  CONVERSATION_STATUS_PARAM,
  CONVERSATION_UNTIL_PARAM,
  CONVERSATION_VIEW_ROLE,
  canViewConversations,
  channelDisplay,
  conversationPollInterval,
  conversationStatusDisplay,
  fetchConversationPage,
} from '@/features/conversations/api';
import {
  CONVERSATION_COLUMNS,
  CONVERSATION_COLUMN_COUNT,
} from '@/features/conversations/conversation-columns';
import { useTableParams } from '@/lib/table/use-table-params';

/**
 * `/conversations` — one page of the CURRENT organization's threads, server-sorted, server-filtered
 * and server-paginated.
 *
 * ── THE ORGANIZATION IS IN THE SESSION AND IN NONE OF NEXT'S CACHE KEYS ─────────────────────────
 * `/conversations` is ONE URL for every organization an administrator belongs to. Not one byte of
 * this list is produced by the Next server — every Next cache is keyed by URL or by arguments and
 * the organization is in the session cookie, which is in none of them. The rows arrive from a
 * browser fetch keyed:
 *
 *     ['org', orgId, 'conversations', { page, per_page, sort, dir, bot_id?, channel?, … }]
 *
 * ── ON AN ORGANIZATION SWITCH THIS SCREEN IS A HARD RESET, NOT A REFETCH ────────────────────────
 * `useResetQueryClient()` REPLACES the QueryClient and the session re-enters `loading`, so this
 * subtree unmounts and the query goes with it. `placeholderData` below compares the previous key's
 * namespace segment, which is the one place that discipline could be undone silently.
 *
 * ── IT DOES NOT POLL, AND THE REASON IS THE ABSENCE OF A STOP CONDITION ─────────────────────────
 * See `conversationPollInterval`. `active` is not a transient state: a thread stays active until a
 * visitor stops talking or the idle sweeper expires it, which can be hours — so a function-form
 * interval would return a number for ever, which is the bare-number failure wearing a function's
 * shape.
 *
 * ── FOUR STATES, SHIPPED WITH THE SUCCESS STATE ─────────────────────────────────────────────────
 * Loading (a table-shaped skeleton), empty — SPLIT into first-run and filtered — error, FORBIDDEN
 * (this list genuinely needs it: `conversations.view` is withheld from the KNOWLEDGE MANAGER and the
 * sidebar offers `/conversations` to everybody), and the out-of-range page only a typed URL can
 * reach.
 */
export function ConversationsScreen() {
  const session = useSession();
  const orgId = useCurrentOrgId();

  if (session.status === 'loading') {
    // The skeleton mirrors the loaded layout box for box, or the page reflows when the data arrives
    // and reads as a rendering bug.
    return (
      <DataTableSurface>
        <TableSkeleton columns={CONVERSATION_COLUMN_COUNT} />
      </DataTableSurface>
    );
  }

  if (session.status !== 'authenticated') {
    // `anonymous` and `unavailable` are both `<SessionProvider>`'s.
    return null;
  }

  if (orgId === null) {
    // Reachable BY DESIGN: login succeeds with `current_organization_id: null` for a user whose
    // memberships are all `invited` or `suspended`. `useOrgKey()` would THROW here, so the gate is a
    // mount condition rather than an `enabled` flag.
    return (
      <Alert variant="info">
        <AlertTitle>No organization selected</AlertTitle>
        <AlertDescription>
          Conversations belong to an organization. Choose one on the settings page first.
        </AlertDescription>
      </Alert>
    );
  }

  const membership = session.organizations.find((organization) => organization.id === orgId);

  return (
    <ConversationsForOrganization
      orgId={orgId}
      viewerRole={membership?.role ?? null}
      organizationName={membership?.name}
    />
  );
}

/**
 * The bot picker's page size.
 *
 * ONE PAGE AND NOT EVERY BOT, because "fetch all and filter" is exactly what a server-paginated list
 * forbids — and a picker is not exempt from it. An organization with more bots than this gets a
 * picker that names the first page; the honest fix on that day is a searchable combobox against the
 * bot list's own `filter`, not a higher number here.
 */
const BOT_PICKER_PAGE = 100;

function ConversationsForOrganization({
  orgId,
  viewerRole,
  organizationName,
}: {
  readonly orgId: string;
  readonly viewerRole: Role | null;
  readonly organizationName?: string;
}) {
  const orgKeyFor = useOrgKey();
  const view = useTableParams(CONVERSATION_LIST_CONFIG);
  const conversationsKey = orgKeyFor('conversations', view.requestParams);

  const canView = canViewConversations(viewerRole);

  const conversations = useQuery({
    queryKey: conversationsKey,
    // `signal` forwarded: `queryClient.cancelQueries()` is a no-op against a queryFn that drops it.
    queryFn: ({ signal }) => fetchConversationPage(orgId, view.requestParams, signal),
    /**
     * KEEP-PREVIOUS-DATA, GATED ON THE ORGANIZATION — and the gate is the whole point. The helper
     * renders the previous KEY's data while the new key loads, and once `orgId` is in the key the
     * previous key can be the previous ORGANIZATION. Segment 1 is `orgId` by construction.
     */
    placeholderData: (previous, previousQuery) =>
      previousQuery?.queryKey[1] === orgId ? previous : undefined,
    // `false`, PERMANENTLY. Conversations do not settle — see `conversationPollInterval`.
    refetchInterval: conversationPollInterval(),
  });

  /**
   * The bot list, for the bot filter — a SEPARATE, NON-BLOCKING query.
   *
   * ALL FOUR ROLES HOLD `bots.view` (ADR-056), including the analyst, and §6.4/§6.5 both name it as
   * granted precisely so a role that reviews conversations can read the bot that produced one. So
   * this query cannot be the thing that 403s the screen, and it degrades to NO PICKER rather than to
   * an error: the conversation table is the page.
   *
   * The key is org-namespaced like every other, and the request carries no organization — that is in
   * the path and in the key.
   */
  const bots = useQuery({
    queryKey: orgKeyFor('bots', { page: '1', per_page: String(BOT_PICKER_PAGE) }),
    queryFn: ({ signal }) =>
      fetchBotPage(orgId, { page: '1', per_page: String(BOT_PICKER_PAGE) }, signal),
    enabled: canView,
  });

  const botOptions = useMemo<readonly FilterOption[]>(
    () =>
      (bots.data?.rows ?? []).map((bot: BotResource) => ({
        value: bot.id,
        // TENANT-AUTHORED TEXT as a JSX child downstream. The slug disambiguates two bots with the
        // same display name, which the server permits.
        label: `${bot.name} · ${bot.slug}`,
      })),
    [bots.data],
  );

  const channelOptions = useMemo<readonly FilterOption[]>(
    () =>
      // FROM THE MANIFEST, NEVER TYPED: a value outside the endpoint's `in:` set is a validation
      // error rather than an empty result, which is why this is a select at all.
      CONVERSATION_CHANNELS.map((channel) => ({
        value: channel,
        label: channelDisplay(channel).label,
      })),
    [],
  );

  const statusOptions = useMemo<readonly FilterOption[]>(
    () =>
      CONVERSATION_STATUSES.map((status) => ({
        value: status,
        label: conversationStatusDisplay(status).label,
      })),
    [],
  );

  /**
   * `'error'` REPLACES the rows, which is right only when there are none to keep. A refetch that
   * failed with a correct page still on screen is NOT that state.
   */
  const hasRows = conversations.data !== undefined;
  const status: ServerDataTableStatus = hasRows ? 'success' : conversations.status;
  const refetchFailed = hasRows && conversations.error !== null;

  const filterValue = (name: string): string => view.params.filters.get(name) ?? '';

  return (
    // Sections are separated by --space-8, never by a divider (the composition law).
    <div className="flex flex-col gap-8">
      {refetchFailed ? (
        <ErrorState
          title="This list could not be refreshed"
          error={conversations.error}
          onRetry={() => void conversations.refetch()}
        />
      ) : null}

      <ServerDataTable
        caption="Conversations"
        columns={CONVERSATION_COLUMNS}
        rows={conversations.data?.rows}
        rowCount={conversations.data?.rowCount}
        getRowId={(conversation) => conversation.id}
        status={status}
        error={conversations.error}
        isFetching={conversations.isFetching}
        onRetry={() => void conversations.refetch()}
        /**
         * NAMED ONLY FOR THE VIEWER WHO IS ACTUALLY MISSING THE ROLE. An `authorization` failure has
         * three indistinguishable causes on the wire, so naming a role unconditionally would tell an
         * owner with a stale organization id to ask for a promotion. `conversations.view` is
         * genuinely withheld from the knowledge manager and the sidebar offers this route to
         * everybody, so an ingestion operator landing here is the common case.
         */
        requiredRole={canView ? undefined : CONVERSATION_VIEW_ROLE}
        organizationName={organizationName}
        view={view}
        header={
          <div className="flex flex-wrap items-end gap-3">
            <FilterSelect
              // UNIQUE ON THE PAGE: substring role-name matching would resolve a bare "Filter"
              // against every control on a screen that grows a second one.
              label="Bot"
              anyLabel="Any bot"
              options={botOptions}
              applied={filterValue(CONVERSATION_BOT_PARAM)}
              onChange={(value) => view.setFilter(CONVERSATION_BOT_PARAM, value)}
              className="w-56"
            />
            <FilterSelect
              label="Channel"
              anyLabel="Any channel"
              options={channelOptions}
              applied={filterValue(CONVERSATION_CHANNEL_PARAM)}
              onChange={(value) => view.setFilter(CONVERSATION_CHANNEL_PARAM, value)}
              className="w-44"
            />
            <FilterSelect
              label="Thread status"
              anyLabel="Any status"
              options={statusOptions}
              applied={filterValue(CONVERSATION_STATUS_PARAM)}
              onChange={(value) => view.setFilter(CONVERSATION_STATUS_PARAM, value)}
              className="w-40"
            />
            <DateRangeFilter
              fromLabel="Started from"
              untilLabel="Started until"
              from={filterValue(CONVERSATION_FROM_PARAM)}
              until={filterValue(CONVERSATION_UNTIL_PARAM)}
              onFrom={(value) => view.setFilter(CONVERSATION_FROM_PARAM, value)}
              onUntil={(value) => view.setFilter(CONVERSATION_UNTIL_PARAM, value)}
            />
          </div>
        }
        emptyState={
          <EmptyState
            glyph={MessagesSquareIcon}
            title="No conversations yet"
            /* FIRST-RUN. It says what a conversation IS and what has to happen for one to exist, and
               it deliberately offers no primary action: nothing here creates a thread — a visitor
               does, on a published bot. A "Start a conversation" button would take an administrator
               to a surface that records a `playground` thread, which is not what this table is for. */
            body="A thread appears here as soon as somebody asks one of your published bots a question — from hosted chat, an embedded widget, or the playground."
          />
        }
        /* FILTERED. It restates what was asked for, because a user who cannot see their own query
           cannot tell a too-narrow filter from a broken screen. Every filter here is a closed
           vocabulary or a date, so this is our own copy rather than tenant text. */
        describeFilter={<>No conversations match these filters.</>}
      />
    </div>
  );
}
