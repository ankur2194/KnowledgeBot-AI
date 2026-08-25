'use client';

import type { Role } from '@kb/contracts';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftIcon } from 'lucide-react';
import Link from 'next/link';

import { SkeletonLines } from '@/components/states';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useCurrentOrgId, useOrgKey, useSession } from '@/features/auth/session-context';

import { fetchEmbeddingConfiguration } from './api';
import { DesignationForm } from './designation-form';
import { ReadinessPanel } from './readiness-panel';

/**
 * The embedding designation for the CURRENT organization.
 *
 * ── THE ORGANIZATION IS IN THE SESSION, NOT IN THE URL, AND THAT IS THE WHOLE HAZARD ────────────
 * `/settings/embedding` is one URL for every organization an admin belongs to, and this route has no
 * segment of its own at all — not even a connection id — so a URL-keyed cache here would be keyed on
 * NOTHING but the path. Not one of Next's five caches is keyed by the session cookie the organization
 * lives in, so no organization-scoped byte may be produced by the Next server: the page above this
 * component renders chrome that is byte-identical for every tenant, and every field below arrives
 * from a browser fetch. The one cache key in this app we design ourselves is the TanStack Query key,
 * so it carries the organization: `['org', orgId, 'embedding-configuration']`, built by `useOrgKey()`
 * and never by hand.
 *
 * ── ON AN ORGANIZATION SWITCH THIS SCREEN IS A HARD RESET, NOT A REFETCH ───────────────────────
 * The URL does not change when the organization does. `useResetQueryClient()` replaces the client and
 * the session re-enters `loading`, so this subtree unmounts, the query goes with it, and — because
 * the designation form's `defaultValues` are seeded on mount from the readiness of the organization
 * being left — the form state goes too. A refetch alone would have left a radio group holding another
 * tenant's connection id, which is the "correctly rendered surface belonging to nobody" symptom the
 * prefix exists to prevent.
 *
 * ── THE TWO-COMPONENT SPLIT IS THE `enabled` GATE, EXPRESSED AS A MOUNT CONDITION ──────────────
 * `useOrgKey()` THROWS when there is no current organization, and that is correct: a key built from
 * `undefined` is ONE shared cache namespace for every org-less state on the platform. So the wrapper
 * below resolves `orgId` first and only mounts `<EmbeddingForOrganization/>` when it is non-null.
 *
 * `orgId === null` is REACHABLE BY DESIGN: login succeeds with `current_organization_id: null` for a
 * user whose memberships are all `invited` or `suspended`.
 */
export function EmbeddingScreen() {
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
          An embedding designation belongs to an organization. Choose one on the settings page first.
        </AlertDescription>
      </Alert>
    );
  }

  // The role the VIEWER holds HERE, read from the membership list the server itself handed us. Per
  // organization, always: the same person can be an owner in one tenant and an analyst in another.
  const viewerRole =
    session.organizations.find((organization) => organization.id === orgId)?.role ?? null;

  return <EmbeddingForOrganization orgId={orgId} viewerRole={viewerRole} />;
}

/**
 * Mounted only with a real organization.
 *
 * ── THE ROLE SPLIT IS WIDER ON THE READ THAN ON THE WRITE, AND THIS SCREEN IS WHY IT IS ────────
 * §6.4 gives a KNOWLEDGE MANAGER `providers.view` and nothing else, and `EmbeddingConfigurationTest`
 * pins exactly that on this endpoint: a knowledge manager reads 200 from the GET and 403 from the
 * PUT. That is not an inconsistency — an ingestion operator whose upload is blocked has to be able to
 * see WHY, and the designation selects which credential pays, which is on the credential side of the
 * line. So a successful read here says nothing about writing, and the only available signal is the
 * viewer's role in THIS organization.
 *
 * It is an AFFORDANCE, NOT AUTHORIZATION. Laravel answers 403 whatever this line renders, the
 * mutation handles that class, and a role that changed under a cached session shows up as that 403
 * rather than as a silently missing control.
 *
 * NO `retry` PROPERTY on the query — the identifier is an ESLint error outside
 * src/lib/query/client.ts, and the global predicate there is already the right policy: `authorization`
 * is not retryable by class, because a 403 is an ANSWER rather than a failure to reattempt.
 *
 * NO POLLING. The verdict changes only when somebody edits a connection or a model row, and a
 * `refetchInterval` that 20 open admin tabs turn into status traffic against `throttle:admin` buys
 * nothing. The mutation invalidates, which is the push this screen actually needs.
 */
function EmbeddingForOrganization({
  orgId,
  viewerRole,
}: {
  readonly orgId: string;
  readonly viewerRole: Role | null;
}) {
  const orgKeyFor = useOrgKey();
  // Built ONCE per render, and the ARRAY is what travels to the child. Passing the array rather than
  // the builder matters after an organization switch: a mutation's callbacks fire whether or not its
  // component is mounted, so an `onSettled` that called `orgKeyFor(...)` at that moment would throw
  // inside a callback nobody is watching. A captured array cannot.
  const readinessKey = orgKeyFor('embedding-configuration');

  const readiness = useQuery({
    queryKey: readinessKey,
    // `signal` forwarded, because `queryClient.cancelQueries()` is a no-op against a queryFn that
    // drops it — and cancelling in-flight reads is step 2 of both logout and the organization switch.
    queryFn: ({ signal }) => fetchEmbeddingConfiguration(orgId, signal),
  });

  const canManage = viewerRole === 'owner' || viewerRole === 'admin';

  return (
    // SECTIONS ARE SEPARATED BY --space-8, NEVER BY A DIVIDER (kb-ui-patterns, the composition law).
    <div className="flex flex-col gap-8">
      {/* THE WAY BACK IS EXPLICIT. This route is reached from the providers screen and an operator
          who arrived from a bookmark has no history to go back through. */}
      <p>
        <Link
          href="/settings/providers"
          className="inline-flex items-center gap-2 text-base text-link underline-offset-4 hover:underline"
        >
          <ArrowLeftIcon aria-hidden className="size-4" />
          All provider connections
        </Link>
      </p>

      <ReadinessPanel
        readiness={readiness.data}
        isPending={readiness.isPending}
        error={readiness.error}
      />

      {/* THE FORM IS MOUNTED ONLY ONCE THE VERDICT HAS LOADED, and that is a correctness condition
          rather than a loading nicety: `defaultValues` are seeded from `selected` at MOUNT, and RHF
          does not re-seed them when a prop later changes. A form mounted against `undefined` would
          hold two nulls for ever and would post a clear-designation body for an organization whose
          designation had simply not arrived yet.

          It is also not mounted on the error path: a designation form for a verdict that failed to
          load is a form whose every submit is the same 403. */}
      {readiness.data === undefined ? null : (
        <Card>
          <CardHeader>
            <CardTitle as="h2">Designate the embedding pair</CardTitle>
            <CardDescription>
              A designation is never substituted. If the pair named here stops being able to embed,
              nothing else is selected in its place — because silently embedding through a different
              connection would change the vector space under a corpus nobody reindexed.
            </CardDescription>
          </CardHeader>
          <CardContent>
            {canManage ? (
              <DesignationForm
                orgId={orgId}
                readinessKey={readinessKey}
                readiness={readiness.data}
              />
            ) : (
              // NOT `<ForbiddenState>`: nothing was refused. The read succeeded, this viewer holds
              // `providers.view` and not `providers.manage`, and the page they came for — the verdict
              // and the reason their upload is blocked — is above and fully rendered. A lock icon and
              // "You don't have access to this" would say the opposite.
              <p className="text-base text-muted-foreground">
                Changing which connection embeds is an owner or admin action. Ask one of them if this
                organization is designating the wrong pair.
              </p>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  );
}
