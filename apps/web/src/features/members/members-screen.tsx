'use client';

import type { Role } from '@kb/contracts';
import { useQuery } from '@tanstack/react-query';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { useCurrentOrgId, useOrgKey, useSession } from '@/features/auth/session-context';

import { fetchInvitations, fetchMembers } from './api';
import { InvitationList } from './invitation-list';
import { InviteForm } from './invite-form';
import { MemberList } from './member-list';

/**
 * Members and invitations for the CURRENT organization — the first consumer of `useOrgKey()`.
 *
 * ── THE ORGANIZATION IS IN THE SESSION, NOT IN THE URL, AND THAT IS THE WHOLE HAZARD ─────────────
 * `/settings/members` is one URL for every organization an admin belongs to. Not one of Next's five
 * caches is keyed by the session cookie the organization lives in, so no organization-scoped byte may be
 * produced by the Next server — the page above this component renders chrome that is byte-identical for
 * every tenant, and every row here arrives from a browser fetch. The one cache key in this app we design
 * ourselves is the TanStack Query key, so it carries the organization: `['org', orgId, 'members']` and
 * `['org', orgId, 'invitations']`, both built by `useOrgKey()` and never by hand.
 *
 * ── THE TWO-COMPONENT SPLIT IS THE `enabled` GATE, EXPRESSED AS A MOUNT CONDITION ────────────────
 * `useOrgKey()` THROWS when there is no current organization, and that is correct: a key built from
 * `undefined` is `['org', undefined, 'members']` — ONE shared cache namespace for every org-less state on
 * the platform, which is precisely the leak the prefix exists to prevent, and its symptom is a correctly
 * rendered list belonging to nobody. So the wrapper below resolves `orgId` first and only mounts
 * `<MembersForOrganization/>` when it is non-null. That is the same guarantee `enabled: orgId !== null`
 * gives, moved to where it cannot be forgotten by a future query added to this screen: inside the inner
 * component the value is a `string` by construction, and there is no expression for it to be null in.
 *
 * `orgId === null` is REACHABLE BY DESIGN, not an edge case: login succeeds with
 * `current_organization_id: null` for a user whose memberships are all `invited` or `suspended`, because
 * refusing it would leave the accept-invitation and resend-verification endpoints unreachable.
 */
export function MembersScreen() {
  const session = useSession();
  const orgId = useCurrentOrgId();

  if (session.status === 'loading') {
    return (
      <div aria-busy="true" className="space-y-3">
        <Skeleton className="h-10" />
        <Skeleton className="h-32" />
      </div>
    );
  }

  if (session.status !== 'authenticated') {
    // `anonymous` and `unavailable`, and NOTHING is rendered for either — on purpose. The
    // `<SessionProvider>` above this component owns both: it fires the full-document bounce to `/login`
    // for `anonymous`, and renders the class-mapped "your account could not be loaded" panel for
    // `unavailable`. A second sentence here would be a duplicate diagnosis, and the two are deliberately
    // not folded together — a 502 or a `rate_limit` is not a statement about who you are.
    return null;
  }

  if (orgId === null) {
    // Authenticated, with no CURRENT organization. The provider's notice already explains WHY ("no active
    // organization" versus "not a member of any" — different sentences, different next steps), so this one
    // only says why this screen is empty.
    return (
      <Alert>
        <AlertTitle>No organization selected</AlertTitle>
        <AlertDescription>
          Members are listed per organization. Choose one on the settings page first.
        </AlertDescription>
      </Alert>
    );
  }

  // The role the VIEWER holds HERE, read from the membership list the server itself handed us. Per
  // organization, always: the same person can be an owner in one tenant and an analyst in another, which
  // is why this is looked up by `orgId` rather than read off the user.
  const viewerRole =
    session.organizations.find((organization) => organization.id === orgId)?.role ?? null;

  return <MembersForOrganization orgId={orgId} viewerRole={viewerRole} />;
}

/**
 * Mounted only with a real organization. See the wrapper's docblock for why that is the `enabled` gate.
 *
 * ── `orgId` HAS TWO JOBS AND THEY ARE NOT THE SAME JOB ───────────────────────────────────────────
 * As a QUERY KEY SEGMENT it is a cache namespace: without it, `['members']` is one cache entry serving
 * two organizations. As a PATH SEGMENT it is a routing hint: the routes are mounted under
 * `organizations/{organization}` with `->scopeBindings()`, `TenantContext` re-reads the membership row
 * from PostgreSQL on every request, and a foreign id 404s at binding time. Laravel derives the real
 * organization from the session and would ignore a client-supplied one, so the segment is not a scope and
 * cannot be used as one.
 *
 * NO `retry` PROPERTY on either query — the identifier is an ESLint error outside src/lib/query/client.ts,
 * and the global predicate there is already the right policy: `authorization` is not retryable by class
 * (a 403 for a role without `members.view` is an ANSWER, not a failure to reattempt), and a 429 gets one
 * attempt after its `Retry-After` window.
 *
 * NO POLLING. Nothing on this screen changes without somebody acting: an invitation's derived `status`
 * moves when it is accepted, revoked, or when its expiry passes, and none of those is worth a
 * `refetchInterval` that 20 open admin tabs turn into pure status traffic against `throttle:admin`. Both
 * mutations invalidate, which is the push this screen actually needs.
 */
function MembersForOrganization({
  orgId,
  viewerRole,
}: {
  readonly orgId: string;
  readonly viewerRole: Role | null;
}) {
  const orgKeyFor = useOrgKey();
  // Built ONCE per render, and the ARRAY is what travels to the children. Passing the array rather than
  // the builder matters after an organization switch: `useResetQueryClient()` replaces the client, the
  // session re-enters `loading`, this subtree unmounts — but a mutation's callbacks fire whether or not
  // its component is mounted, so an `onSettled` that called `orgKeyFor(...)` at that moment would throw
  // inside a callback nobody is watching. A captured array cannot.
  const membersKey = orgKeyFor('members');
  const invitationsKey = orgKeyFor('invitations');

  const members = useQuery({
    queryKey: membersKey,
    // `signal` forwarded, because `queryClient.cancelQueries()` is a no-op against a queryFn that drops
    // it — and cancelling in-flight reads is step 2 of both logout and the organization switch, which is
    // exactly when a members list must not resolve.
    queryFn: ({ signal }) => fetchMembers(orgId, signal),
  });

  const invitations = useQuery({
    queryKey: invitationsKey,
    queryFn: ({ signal }) => fetchInvitations(orgId, signal),
  });

  return (
    <div className="space-y-8">
      {/* ── WHY THE INVITE FORM IS GATED ON A SERVER ANSWER RATHER THAN ON A LOCAL ROLE CHECK ──────
          `invitations.isSuccess` means Laravel authorized `members.view` for this reader. `App\Enums\OrgRole::grants`
          gives owner and admin both `members.view` and `members.manage`, and gives knowledge_manager and
          analyst neither — the two permissions are held by the same two roles — so a successful read IS
          the server's word that this person may also invite. That is strictly better than testing the
          cached role: a stale session cannot hide the form from somebody who may use it, and it cannot
          show it to somebody who may not.

          It is still a RENDERING decision and not authorization. The POST is authorized by Laravel
          whatever this line does, and the 403 path in the form's `onError` is what handles the race. */}
      {invitations.isSuccess ? (
        <>
          <section aria-labelledby="invite-heading" className="space-y-3">
            <h2 id="invite-heading" className="text-lg font-medium">
              Invite a member
            </h2>
            <InviteForm orgId={orgId} invitationsKey={invitationsKey} viewerRole={viewerRole} />
          </section>
          <Separator />
        </>
      ) : null}

      <InvitationList
        orgId={orgId}
        invitationsKey={invitationsKey}
        invitations={invitations.data}
        isPending={invitations.isPending}
        error={invitations.error}
      />

      <Separator />

      <MemberList
        members={members.data}
        isPending={members.isPending}
        error={members.error}
      />
    </div>
  );
}
