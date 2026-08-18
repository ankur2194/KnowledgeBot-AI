import type {
  AcknowledgementResource,
  InvitationCollectionResource,
  InvitationResource,
  InvitationStatus,
  MemberCollectionResource,
  MembershipStatus,
  MemberResource,
  Role,
} from '@kb/contracts';

import type { StatusKind } from '@/components/status-pill';
import { browserFetchData } from '@/features/auth/session';
import { sessionCredential } from '@/lib/api/browser';

/**
 * The members-and-invitations transport: five endpoints, their paths, and the two resource shapes they
 * return.
 *
 * ── THE RESOURCE TYPES LIVE IN `@kb/contracts` NOW, AND THAT SEAM IS CLOSED ──────────────────────
 * `MemberResource`, `InvitationResource`, `InvitationStatus` and the acknowledgement shape were declared
 * HERE, transcribed by hand from `App\Http\Resources\{Member,Invitation}Resource`, with the docblock
 * that used to sit in this spot reporting the move as owed. They are now in
 * `packages/contracts/src/resources/members.ts` and imported above.
 *
 * What made it worth moving was not that they were wrong — they were checked field for field and they
 * agreed — but that NOTHING WOULD HAVE NOTICED if they had not. `test/resource-drift.test.ts` compared a
 * hardcoded list of three components against the generated document, so it said nothing about the other
 * twelve; it now closes over the published set, exactly as `form-drift.test.ts` closes over the dumped
 * manifests, and every component must be mirrored or exempt with a stated reason. That closure is what
 * named these. The chain it replaced ran fixture -> hand-written type -> PHP resource with no assertion
 * at any step, which also defeated the discipline `tests/msw/handlers.ts` states for itself.
 *
 * ── snake_case verbatim ──────────────────────────────────────────────────────────────────────────
 * Every field is a straight carry of the JSON. A rename layer is a place for a typo to read `undefined`
 * with nothing thrown.
 *
 * ── WHAT THE SERVER DELIBERATELY DOES NOT SEND ───────────────────────────────────────────────────
 * No invitation TOKEN, in any form — not the plaintext (never stored), not the digest, not a prefix of
 * either. An invitation is a bearer capability and an admin list is a screen, a log line and a browser
 * cache away from being a place one leaks; re-delivering a link is a RESEND, which mints a new token and
 * kills the old one. And no `is_platform_owner` on a member: that is a platform flag, and publishing it
 * on a tenant's list would tell an organization's administrator which of their members is a platform
 * operator.
 */





/**
 * ── THE ORGANIZATION IS IN THE PATH, AND THAT IS NOT A TENANCY VIOLATION ─────────────────────────
 * Every route below is mounted under `organizations/{organization}` with `->scopeBindings()`, and
 * `TenantContext` re-reads the membership row from PostgreSQL on EVERY request. So the segment is a
 * ROUTING HINT, never a scope: Laravel derives the real organization from the session and would ignore
 * a client-supplied one, and a foreign id 404s at binding time before any policy runs. The same `orgId`
 * is separately a CACHE NAMESPACE in the query key, which is a different job — see `useOrgKey()`.
 *
 * `encodeURIComponent` on a ULID is a no-op today. It is here because the value comes off a server
 * response and is interpolated into a URL, and the habit is what keeps the day it stops being a ULID
 * from being an injected path segment.
 */
const organizationPath = (orgId: string): string =>
  `/api/v1/organizations/${encodeURIComponent(orgId)}`;

export const membersPath = (orgId: string): string => `${organizationPath(orgId)}/members`;

export const invitationsPath = (orgId: string): string => `${organizationPath(orgId)}/invitations`;

export const invitationPath = (orgId: string, invitationId: string): string =>
  `${invitationsPath(orgId)}/${encodeURIComponent(invitationId)}`;

export const resendInvitationPath = (orgId: string, invitationId: string): string =>
  `${invitationPath(orgId, invitationId)}/resend`;

/**
 * ── BOTH LISTS NEST UNDER A NAMED KEY, AND THE EXTRA LEVEL IS FORCED RATHER THAN CHOSEN ──────────
 * The body is `{"data": {"members": [...]}}`, not `{"data": [...]}`. `App\Support\Contracts\ResponseShape`
 * maps a response KEY to a schema class and the dumper composes one object whose properties are those
 * keys — there is no shape in that attribute that says "an array of"; and
 * `tests/Contract/OpenApiDocumentTest.php` requires every resource component to carry
 * `additionalProperties: false`, which an array-typed schema cannot. So the array is wrapped, which also
 * buys a place for `next_cursor` and `total` to land later without moving the list.
 *
 * The named key is therefore unwrapped HERE, once, beside the `data` unwrap in `browserFetchData` — never
 * at a render site. A render site that knows about the envelope is a render site that has to be edited
 * when the envelope changes, and there are more of those than there are fetchers.
 *
 * `signal` is forwarded because `queryClient.cancelQueries()` is a NO-OP against a `queryFn` that drops
 * it — and cancelling in-flight reads is step 2 of both logout and the organization switch, which is
 * exactly when a members list must not resolve.
 */
export const fetchMembers = async (
  orgId: string,
  signal: AbortSignal,
): Promise<readonly MemberResource[]> => {
  const body = await browserFetchData<MemberCollectionResource>({
    path: membersPath(orgId),
    credential: await sessionCredential(),
    signal,
  });
  return body.members;
};

export const fetchInvitations = async (
  orgId: string,
  signal: AbortSignal,
): Promise<readonly InvitationResource[]> => {
  const body = await browserFetchData<InvitationCollectionResource>({
    path: invitationsPath(orgId),
    credential: await sessionCredential(),
    signal,
  });
  return body.invitations;
};

/**
 * `POST …/invitations` -> 201 `{data: InvitationResource}` | 422 | 403 | 409.
 *
 * NO `organization_id` IN THE BODY. It is a URL segment resolved by route binding; a body field would be
 * a tenant key a caller can set, which is an authorization bug with a 201 response. `StoreInvitationRequest`
 * does not validate one either, and `OWNERSHIP_KEYS` is what keeps it out of any future form schema.
 *
 * No `Idempotency-Key`, so this must never be retried by anything — including a double-click, which is
 * what `disabled={isPending}` on the submit button is for. A replayed invite is a 422 ("An invitation for
 * this address is already pending"), not a duplicate row, but it still mails a stranger twice.
 */
export const createInvitation = async (
  orgId: string,
  body: { readonly email: string; readonly role: Role },
): Promise<InvitationResource> =>
  browserFetchData<InvitationResource>({
    path: invitationsPath(orgId),
    method: 'POST',
    body,
    credential: await sessionCredential(),
  });

/**
 * `DELETE …/invitations/{id}` -> 200 `{data: {acknowledged: true}}` | 422.
 *
 * An acknowledgement and not the revoked resource: the only thing the caller learns is that it happened,
 * and the list is where the new derived `status` is read. So the invalidation after this is not a
 * nicety — it is the only way the screen finds out.
 *
 * DELETE is idempotent here on purpose: revoking an already-revoked invitation is a no-op 200. An
 * ACCEPTED one is a 422 on `invitation`, which is a path no form renders — see `actionErrorCopy`.
 */
export const revokeInvitation = async (
  orgId: string,
  invitationId: string,
): Promise<AcknowledgementResource> =>
  browserFetchData<AcknowledgementResource>({
    path: invitationPath(orgId, invitationId),
    method: 'DELETE',
    credential: await sessionCredential(),
  });

/**
 * `POST …/invitations/{id}/resend` -> 200 `{data: {acknowledged: true}}` | 422 | 429.
 *
 * IT IS A POST BECAUSE IT IS NOT IDEMPOTENT. Each call MINTS A NEW TOKEN and overwrites `token_hash`, so
 * the previously mailed link stops working — which is the correct semantics (two live links to one
 * invitation are two capabilities carrying one authority) and is the property that makes an invitation
 * revocable at all. The new token is never in the response.
 *
 * IT IS THE ONE ACTION ON THIS SCREEN WITH ITS OWN LIMITER. `throttle:admin` keys on (organization, user)
 * at 120/min — the ACTOR — so on its own it permits one administrator to mail one invitee 120 links a
 * minute: a mailbox flood aimed at a third party who never asked to be invited, with legitimate
 * credentials, that no other control sees. `throttle:invitation-resend` keys on the INVITATION, so the
 * 429 belongs to the recipient rather than to the sender — which is why the cooldown in the UI is
 * PER ROW and not per screen.
 */
export const resendInvitation = async (
  orgId: string,
  invitationId: string,
): Promise<AcknowledgementResource> =>
  browserFetchData<AcknowledgementResource>({
    path: resendInvitationPath(orgId, invitationId),
    method: 'POST',
    credential: await sessionCredential(),
  });

/**
 * Is there anything left to do to this invitation?
 *
 * `pending` and `expired` only, and the set is DERIVED FROM THE SERVER'S OWN REFUSALS rather than
 * guessed:
 *  - resend: `InvitationService::resend` throws `NOT_RESENDABLE` for accepted and revoked rows;
 *    EXPIRED is deliberately allowed, because renewing the expiry is exactly what a resend is for.
 *  - revoke: `InvitationService::revoke` throws `NOT_REVOCABLE` only for an accepted row, and an
 *    already-revoked one is a no-op 200 — so a Revoke button on a revoked row would be a control that
 *    does nothing and reports success.
 *
 * It is a RENDERING decision, not authorization: the server re-checks the derived status under a row lock
 * so two administrators acting at once cannot both win, and every refusal arrives as a 422 the row
 * renders.
 */
export const isLiveInvitation = (status: InvitationStatus): boolean =>
  status === 'pending' || status === 'expired';

/** `InvitationStatus` -> the label a human reads. A `switch`, so a fifth state added to the union fails
 *  the typecheck here rather than rendering a raw wire value; and an object indexed by a value read off
 *  the wire is an injection sink eslint-plugin-security warns about. */
export function invitationStatusLabel(status: InvitationStatus): string {
  switch (status) {
    case 'pending':
      return 'Pending';
    case 'expired':
      return 'Expired';
    case 'accepted':
      return 'Accepted';
    case 'revoked':
      return 'Revoked';
  }
}

/** `MembershipStatus` -> the label a human reads. Same reasoning as above. */
export function membershipStatusLabel(status: MembershipStatus): string {
  switch (status) {
    case 'active':
      return 'Active';
    case 'invited':
      return 'Invited';
    case 'suspended':
      return 'Suspended';
  }
}

/**
 * `InvitationStatus` -> the CLOSED status vocabulary the pill renders (kb-ui-patterns P8).
 *
 * A `switch` rather than an object for the same reason as the label functions above: a fifth state
 * added to the union fails the typecheck here instead of rendering an untinted pill, and an object
 * indexed by a value read off the wire is an injection sink.
 *
 * The mapping is the pattern's, not this screen's: pending/queued -> slate, running -> info,
 * ready/active -> success, degraded/partial -> warning, failed/disabled -> destructive. An expired
 * invitation is a WARNING rather than a failure — it can still be resent, which is a different next
 * step from one that was revoked.
 */
export function invitationStatusKind(status: InvitationStatus): StatusKind {
  switch (status) {
    case 'pending':
      return 'pending';
    case 'expired':
      return 'degraded';
    case 'accepted':
      return 'ready';
    case 'revoked':
      return 'failed';
  }
}

/** `MembershipStatus` -> the pill's status kind. Same reasoning as above. */
export function membershipStatusKind(status: MembershipStatus): StatusKind {
  switch (status) {
    case 'active':
      return 'ready';
    case 'invited':
      return 'pending';
    case 'suspended':
      return 'disabled';
  }
}

/**
 * RFC 3339 -> something a human reads, or an em dash for the nullable columns.
 *
 * The VIEWER's locale and timezone, and there is no hydration hazard: every value formatted here arrived
 * from a browser fetch, so this subtree does not exist in the RSC payload and there is no
 * server-rendered string for it to disagree with. A malformed timestamp renders raw rather than
 * "Invalid Date" — the field is a straight carry off the wire, and the wire is not ours to trust twice.
 */
export function formatTimestamp(isoTimestamp: string | null): string {
  if (isoTimestamp === null) return '—';

  const at = new Date(isoTimestamp);
  if (Number.isNaN(at.getTime())) return isoTimestamp;

  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(at);
}
