'use client';

import type { Role } from '@kb/contracts';
import { useQuery } from '@tanstack/react-query';
import { CpuIcon, SlidersHorizontalIcon } from 'lucide-react';
import Link from 'next/link';

import { SkeletonLines } from '@/components/states';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useCurrentOrgId, useOrgKey, useSession } from '@/features/auth/session-context';

import { fetchConnections } from './api';
import { ConnectionList } from './connection-list';
import { CreateConnectionForm } from './connection-form';

/**
 * Provider connections for the CURRENT organization.
 *
 * ── THE ORGANIZATION IS IN THE SESSION, NOT IN THE URL, AND THAT IS THE WHOLE HAZARD ─────────────
 * `/settings/providers` is one URL for every organization an admin belongs to. Not one of Next's five
 * caches is keyed by the session cookie the organization lives in, so no organization-scoped byte may
 * be produced by the Next server — the page above this component renders chrome that is byte-identical
 * for every tenant, and every row here arrives from a browser fetch. The one cache key in this app we
 * design ourselves is the TanStack Query key, so it carries the organization:
 * `['org', orgId, 'provider-connections']`, built by `useOrgKey()` and never by hand.
 *
 * ── THE TWO-COMPONENT SPLIT IS THE `enabled` GATE, EXPRESSED AS A MOUNT CONDITION ────────────────
 * `useOrgKey()` THROWS when there is no current organization, and that is correct: a key built from
 * `undefined` is `['org', undefined, 'provider-connections']` — ONE shared cache namespace for every
 * org-less state on the platform, which is precisely the leak the prefix exists to prevent, and its
 * symptom is a correctly rendered list belonging to nobody. So the wrapper below resolves `orgId`
 * first and only mounts `<ProvidersForOrganization/>` when it is non-null.
 *
 * `orgId === null` is REACHABLE BY DESIGN: login succeeds with `current_organization_id: null` for a
 * user whose memberships are all `invited` or `suspended`.
 */
export function ProvidersScreen() {
  const session = useSession();
  const orgId = useCurrentOrgId();

  if (session.status === 'loading') {
    return <SkeletonLines lines={3} />;
  }

  if (session.status !== 'authenticated') {
    // `anonymous` and `unavailable`, and NOTHING is rendered for either — the `<SessionProvider>`
    // above owns both: it bounces to `/login` for `anonymous` and renders the class-mapped "your
    // account could not be loaded" panel for `unavailable`.
    return null;
  }

  if (orgId === null) {
    return (
      <Alert variant="info">
        <AlertTitle>No organization selected</AlertTitle>
        <AlertDescription>
          Provider credentials belong to an organization. Choose one on the settings page first.
        </AlertDescription>
      </Alert>
    );
  }

  // The role the VIEWER holds HERE, read from the membership list the server itself handed us. Per
  // organization, always: the same person can be an owner in one tenant and an analyst in another.
  const viewerRole =
    session.organizations.find((organization) => organization.id === orgId)?.role ?? null;

  return <ProvidersForOrganization orgId={orgId} viewerRole={viewerRole} />;
}

/**
 * Mounted only with a real organization.
 *
 * ── WHY THIS SCREEN GATES ON THE ROLE WHERE THE MEMBERS SCREEN GATES ON A SERVER ANSWER ──────────
 * THE PERMISSION SPLIT IS DIFFERENT HERE, and copying the members pattern would be wrong. There,
 * `members.view` and `members.manage` are held by exactly the same two roles, so a successful READ is
 * Laravel's own word that the reader may also write, and the invite form is gated on
 * `invitations.isSuccess`.
 *
 * §6.4 gives a KNOWLEDGE MANAGER `providers.view` AND NOTHING ELSE — deliberately, because an
 * ingestion operator has to be able to see whether the organization can embed at all — while
 * `providers.manage` is owner and admin. So a successful read here says nothing about writing, and the
 * only available signal is the viewer's role in THIS organization.
 *
 * It is an AFFORDANCE, NOT AUTHORIZATION. Laravel answers 403 whatever this line renders, every
 * mutation below handles that class, and a role that changed under a cached session shows up as that
 * 403 rather than as a silently missing control.
 *
 * NO `retry` PROPERTY on the query — the identifier is an ESLint error outside src/lib/query/client.ts,
 * and the global predicate there is already the right policy: `authorization` is not retryable by class
 * (a 403 for an analyst is an ANSWER, not a failure to reattempt).
 *
 * NO POLLING. Nothing on this screen changes without somebody acting, and a `refetchInterval` that 20
 * open admin tabs turn into pure status traffic against `throttle:admin` buys nothing. Every mutation
 * invalidates, which is the push this screen actually needs.
 */
function ProvidersForOrganization({
  orgId,
  viewerRole,
}: {
  readonly orgId: string;
  readonly viewerRole: Role | null;
}) {
  const orgKeyFor = useOrgKey();
  // Built ONCE per render, and the ARRAY is what travels to the children. Passing the array rather
  // than the builder matters after an organization switch: `useResetQueryClient()` replaces the
  // client, the session re-enters `loading`, this subtree unmounts — but a mutation's callbacks fire
  // whether or not its component is mounted, so an `onSettled` that called `orgKeyFor(...)` at that
  // moment would throw inside a callback nobody is watching. A captured array cannot.
  const connectionsKey = orgKeyFor('provider-connections');

  const connections = useQuery({
    queryKey: connectionsKey,
    // `signal` forwarded, because `queryClient.cancelQueries()` is a no-op against a queryFn that
    // drops it — and cancelling in-flight reads is step 2 of both logout and the organization switch.
    queryFn: ({ signal }) => fetchConnections(orgId, signal),
  });

  const canManage = viewerRole === 'owner' || viewerRole === 'admin';

  return (
    // SECTIONS ARE SEPARATED BY --space-8, NEVER BY A DIVIDER (kb-ui-patterns, the composition law).
    <div className="flex flex-col gap-8">
      {canManage ? (
        // A card, because content never floats directly on the canvas, and ONE card because this card
        // has one job.
        <Card>
          <CardHeader>
            <CardTitle as="h2">Add a provider connection</CardTitle>
            <CardDescription>
              The key is encrypted before it is stored and is never shown again — only its last four
              characters.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <CreateConnectionForm orgId={orgId} connectionsKey={connectionsKey} />
          </CardContent>
        </Card>
      ) : null}

      <ConnectionList
        orgId={orgId}
        connectionsKey={connectionsKey}
        connections={connections.data}
        isPending={connections.isPending}
        error={connections.error}
        canManage={canManage}
      />

      {/* ── THE LINK OUTLIVED ITS DISCLAIMER, WHICH IS WHAT WAS SUPPOSED TO HAPPEN ────────────────
          `/settings/embedding` was linked from here a batch before A4b built it, so that agent did
          not have to edit this screen to be reachable — the same argument the model-catalogue link
          in the table makes. A sentence underneath said the screen was coming, which is what made a
          404 read as "not yet" rather than as a broken console.

          THE ROUTE EXISTS NOW AND THE SENTENCE HAS BEEN REMOVED. It had stopped being an honest
          affordance and become a product-visible false statement — an administrator told that a
          working screen is unavailable does not click it. Gating the link behind a feature flag was
          the rejected alternative at the time, and this is why: a flag with one consumer and a
          one-batch life is a second thing to remove, and forgetting to remove it hides a finished
          screen. Exactly one sentence had to be deleted instead, here and on `/settings`. */}
      <Card>
        <CardHeader>
          <CardTitle as="h2">Embedding</CardTitle>
          <CardDescription>
            Which stored credential pays for embedding, and which model names the vector space your
            documents are indexed under.
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-2">
          <Link
            href="/settings/embedding"
            className="inline-flex items-center gap-2 text-base text-link underline-offset-4 hover:underline"
          >
            <SlidersHorizontalIcon aria-hidden className="size-4" />
            Embedding designation
          </Link>
        </CardContent>
      </Card>

      <p className="flex items-start gap-2 text-sm text-muted-foreground">
        <CpuIcon aria-hidden className="mt-0.5 size-4 shrink-0" />
        <span>
          A connection is a credential. The models it may be used with are listed on the connection
          itself — open one above to manage its catalogue.
        </span>
      </p>
    </div>
  );
}
