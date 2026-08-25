'use client';

import type { Role } from '@kb/contracts';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftIcon, SlidersHorizontalIcon } from 'lucide-react';
import Link from 'next/link';

import { ErrorState, SkeletonLines } from '@/components/states';
import { StatusPill } from '@/components/status-pill';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useCurrentOrgId, useOrgKey, useSession } from '@/features/auth/session-context';
import {
  connectionStatusKind,
  connectionStatusLabel,
  providerLabel,
} from '@/features/providers/api';

import { fetchConnection, fetchModels } from './api';
import { ModelList } from './model-list';
import { CreateModelDialog } from './model-form';

/**
 * The model catalog of ONE provider connection, for the CURRENT organization.
 *
 * ── THE ORGANIZATION IS IN THE SESSION AND THE CONNECTION IS IN THE URL, AND ONLY ONE OF THOSE IS
 *    A CACHE KEY ANYWHERE IN NEXT ────────────────────────────────────────────────────────────────
 * `/settings/providers/{connectionId}` is one URL per connection and one URL for every organization
 * an admin belongs to. The connection id is in the path, so it IS in every Next cache key; the
 * organization is in the session cookie, so it is in NONE of them — not the Full Route Cache, not the
 * Data Cache, not the Router Cache, not `unstable_cache`, not `use cache`. A URL-keyed cache is
 * therefore exactly half a key on this route, which is more dangerous than none: it looks specific.
 *
 * So no organization-scoped byte is produced by the Next server, and every row here arrives from a
 * browser fetch keyed in the one cache this app designs itself:
 *
 *     ['org', orgId, 'provider-connections', connectionId]            <- the parent row
 *     ['org', orgId, 'provider-connections', connectionId, 'models']  <- this catalogue
 *
 * BOTH SEGMENTS ARE IN BOTH KEYS. Dropping the organization is the cross-tenant serve `useOrgKey()`
 * exists to prevent; dropping the connection would make two connections of the SAME organization
 * share one cache entry, and the symptom would be a correctly rendered catalogue belonging to the
 * other credential. Neither key is built by hand — `orgKeyFor` throws when there is no organization.
 *
 * ── THE TWO-COMPONENT SPLIT IS THE `enabled` GATE, EXPRESSED AS A MOUNT CONDITION ───────────────
 * `useOrgKey()` THROWS when there is no current organization, because a key built from `undefined` is
 * ONE shared namespace for every org-less state on the platform. The wrapper below resolves `orgId`
 * first and only mounts the inner component when it is non-null, so the throw is unreachable rather
 * than merely unlikely. `orgId === null` is reachable BY DESIGN: login succeeds with
 * `current_organization_id: null` for a user whose memberships are all `invited` or `suspended`.
 *
 * ── ON AN ORGANIZATION SWITCH THIS SCREEN IS A HARD RESET, NOT A REFETCH ───────────────────────
 * The URL does not change when the organization does — the connection id in it belongs to the
 * organization the admin just left. `useResetQueryClient()` replaces the client and the session
 * re-enters `loading`, so this subtree unmounts and both queries above go with it; the id in the path
 * then 404s at binding time under the new organization, and the operator reads the class-mapped
 * refusal rather than another tenant's catalogue. That is the correct outcome and it is why the
 * connection is READ rather than assumed: a screen that trusted the path segment would render a
 * heading for a connection it never fetched.
 */
export function ModelsScreen({ connectionId }: { readonly connectionId: string }) {
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
          Provider connections belong to an organization. Choose one on the settings page first.
        </AlertDescription>
      </Alert>
    );
  }

  // The role the VIEWER holds HERE, read from the membership list the server itself handed us. Per
  // organization, always: the same person can be an owner in one tenant and an analyst in another.
  const viewerRole =
    session.organizations.find((organization) => organization.id === orgId)?.role ?? null;

  return (
    <CatalogueForOrganization
      orgId={orgId}
      connectionId={connectionId}
      viewerRole={viewerRole}
    />
  );
}

/**
 * Mounted only with a real organization.
 *
 * ── THE ROLE SPLIT IS THE SIBLING SCREEN'S, AND FOR THE SAME REASON ────────────────────────────
 * §6.4 gives a KNOWLEDGE MANAGER `providers.view` and nothing else — an ingestion operator has to be
 * able to see whether the organization can embed at all, and these rows' capability flags are half of
 * that answer — while `providers.manage` is owner and admin. So a successful READ says nothing about
 * writing, and the only available signal is the viewer's role in THIS organization.
 *
 * It is an AFFORDANCE, NOT AUTHORIZATION. Laravel answers 403 whatever this line renders, every
 * mutation below handles that class, and a role that changed under a cached session shows up as that
 * 403 rather than as a silently missing control.
 *
 * NO `retry` PROPERTY on either query — the identifier is an ESLint error outside
 * src/lib/query/client.ts, and the global predicate there is already the right policy: `authorization`
 * is not retryable by class, because a 403 for an analyst is an ANSWER rather than a failure to
 * reattempt.
 *
 * NO POLLING. Nothing on this screen changes without somebody acting, and a `refetchInterval` that 20
 * open admin tabs turn into pure status traffic against `throttle:admin` buys nothing. Every mutation
 * invalidates, which is the push this screen actually needs.
 */
function CatalogueForOrganization({
  orgId,
  connectionId,
  viewerRole,
}: {
  readonly orgId: string;
  readonly connectionId: string;
  readonly viewerRole: Role | null;
}) {
  const orgKeyFor = useOrgKey();
  // Built ONCE per render, and the ARRAYS are what travel to the children. Passing an array rather
  // than the builder matters after an organization switch: a mutation's callbacks fire whether or not
  // its component is still mounted, so an `onSettled` that called `orgKeyFor(...)` at that moment
  // would throw inside a callback nobody is watching. A captured array cannot.
  const connectionKey = orgKeyFor('provider-connections', connectionId);
  const modelsKey = orgKeyFor('provider-connections', connectionId, 'models');

  const connection = useQuery({
    queryKey: connectionKey,
    // `signal` forwarded, because `queryClient.cancelQueries()` is a no-op against a queryFn that
    // drops it — and cancelling in-flight reads is step 2 of both logout and the organization switch.
    queryFn: ({ signal }) => fetchConnection(orgId, connectionId, signal),
  });

  const models = useQuery({
    queryKey: modelsKey,
    queryFn: ({ signal }) => fetchModels(orgId, connectionId, signal),
    // The catalogue is meaningless without the row it hangs off, and both requests 404 together on a
    // foreign id — so the second one is not sent until the first has answered. This is the ONE place
    // on the screen where a query is gated on something other than the organization, and it is a
    // request-count decision rather than a correctness one: the server would refuse it identically.
    enabled: connection.isSuccess,
  });

  const canManage = viewerRole === 'owner' || viewerRole === 'admin';

  return (
    // SECTIONS ARE SEPARATED BY --space-8, NEVER BY A DIVIDER (kb-ui-patterns, the composition law).
    <div className="flex flex-col gap-8">
      {/* THE WAY BACK IS EXPLICIT, because this route is two levels deep and the browser's back
          button is not an affordance a screen may rely on — an operator who arrived from a bookmark
          has no history to go back through. */}
      <p>
        <Link
          href="/settings/providers"
          className="inline-flex items-center gap-2 text-base text-link underline-offset-4 hover:underline"
        >
          <ArrowLeftIcon aria-hidden className="size-4" />
          All provider connections
        </Link>
      </p>

      {/* ── THE PARENT ROW, IN ALL FOUR STATES ────────────────────────────────────────────────────
          Loading is a skeleton at the card's shape. The error covers BOTH a 403 (an analyst holds
          nothing in this catalog) and a 404 (a foreign or unknown connection, including one belonging
          to the organization the admin just switched away from) — Laravel renders 404 as
          `authorization` on the admin surface by the deny split, so the client cannot tell them apart
          and must not try to. When it fails, nothing below it renders: a create form for a connection
          that may not exist is a form whose every submit is a 404. */}
      {connection.isPending ? (
        <Card>
          <CardContent className="pt-6">
            <SkeletonLines lines={2} />
          </CardContent>
        </Card>
      ) : null}

      {connection.error === null ? null : (
        <ErrorState title="This provider connection could not be loaded" error={connection.error} />
      )}

      {connection.data === undefined ? null : (
        <>
          <Card>
            <CardHeader>
              <CardTitle as="h2">
                <span className="flex flex-wrap items-center gap-2">
                  <Badge>{providerLabel(connection.data.provider)}</Badge>
                  {connection.data.label}
                  <StatusPill
                    status={connectionStatusKind(connection.data.status)}
                    label={connectionStatusLabel(connection.data.status)}
                  />
                </span>
              </CardTitle>
              <CardDescription>
                {/* THE MASK IS TEXT, HERE AS ON THE LIST SCREEN, and there is no input on this
                    screen that could receive it: the whole catalogue surface reaches no vault and
                    has no credential field in any of its request bodies. It is here because an
                    operator managing several keys for one vendor needs to know which one this is. */}
                The models this credential may be used with. Key ending{' '}
                <span className="font-mono">{connection.data.masked_key}</span>.
              </CardDescription>
            </CardHeader>
          </Card>

          {/* THE CREATE PATH IS THE SECTION'S ACTION, not a card of its own, and it is a DIALOG
              rather than an inline form. Nine controls inline would dominate a page whose subject is
              the table below them — and, more mechanically, the create and edit forms render the
              SAME eight labelled controls, so mounting both at once would put two "Display name"
              inputs on one screen. Accessible-name matching is a substring in both Playwright and
              vitest-browser, and a screen-reader user meets the same ambiguity one control at a
              time. See `CreateModelDialog`.

              It is rendered only for a viewer with `providers.manage` — an affordance, never
              authorization: Laravel answers 403 whatever this line renders. */}
          <ModelList
            orgId={orgId}
            connectionId={connectionId}
            modelsKey={modelsKey}
            models={models.data}
            isPending={models.isPending}
            error={models.error}
            canManage={canManage}
            action={
              canManage ? (
                <CreateModelDialog
                  orgId={orgId}
                  connectionId={connectionId}
                  modelsKey={modelsKey}
                />
              ) : null
            }
          />

          <p className="flex items-start gap-2 text-sm text-muted-foreground">
            <SlidersHorizontalIcon aria-hidden className="mt-0.5 size-4 shrink-0" />
            <span>
              Registering a model does not designate it for anything. Which credential and model pay
              for embedding is a separate, organization-wide choice made on the embedding settings
              screen — and a row named by that choice cannot be deleted until it is cleared.
            </span>
          </p>
        </>
      )}
    </div>
  );
}
