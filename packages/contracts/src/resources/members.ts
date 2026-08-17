/**
 * The member and invitation resources the control plane returns from the four admin endpoints under
 * `/api/v1/organizations/{organization}` — `members`, `members/{user}`, `invitations`, and the
 * invitation create/revoke/resend actions.
 *
 * ── TYPES ONLY. ZERO RUNTIME VALUES. ─────────────────────────────────────────────────────────────
 * Same rule as `session.ts`, for the same reason: this module is re-exported from `src/index.ts`,
 * budgeted at <=1 kB brotli inside apps/widget's app shell, and only an erased `export type`
 * re-export keeps that free. `InvitationStatus` is therefore a union and not a tuple. If a runtime
 * status list is ever needed it goes behind the `./forms` subpath (that is where `ORG_ROLES` lives).
 *
 * ── WHY THIS FILE EXISTS ─────────────────────────────────────────────────────────────────────────
 * These three declarations lived in `apps/web/src/features/members/api.ts` — hand-written a second
 * time, against `MemberResource.php` and `InvitationResource.php`, with nothing comparing the two.
 * They agreed field for field when 6B checked, so nothing was broken; what was broken is that
 * NOTHING WOULD HAVE NOTICED if they had not. `test/form-drift.test.ts` closes over the dumped
 * manifest set, so a FormRequest nobody mirrored fails by name; `test/resource-drift.test.ts` had a
 * hardcoded list of three components and therefore could not close over the fifteen published ones.
 * It does now, and that closure is what named these.
 *
 * The chain that made it possible: fixture → hand-written type → PHP resource, with no mechanical
 * link at any step. `apps/web/tests/components/members-screen.test.tsx` typed its MSW fixtures from
 * the local copy, which defeats the discipline `apps/web/tests/msw/handlers.ts` states for itself —
 * every payload typed from `@kb/contracts` so a fixture cannot disagree with the server.
 *
 * ── snake_case verbatim ─────────────────────────────────────────────────────────────────────────
 * A straight carry of the JSON, for the reason `errors.ts` gives: a rename layer is a place for a
 * typo to read `undefined` with nothing thrown.
 */

import type { MembershipStatus, Role } from './session.js';

/**
 * One member of ONE organization. The subject is the MEMBERSHIP row, not the user — `role` and
 * `status` are per organization, and the same person can be an owner in one tenant and an analyst in
 * another.
 *
 * `user_id` AND NOT `id`, mirroring the server: the membership has no single-column identity (its
 * primary key is `(organization_id, user_id)`), and naming the field `id` would invite a client to
 * treat it as an addressable resource id, which it is not.
 */
export interface MemberResource {
  readonly user_id: string;
  readonly name: string;
  readonly email: string;
  /** The role held IN THIS ORGANIZATION. One per user per organization; the catalog is fixed. */
  readonly role: Role;
  /**
   * Only `active` confers anything. `suspended` rows are LISTED so an administrator restoring access
   * can find the person.
   */
  readonly status: MembershipStatus;
  /** RFC 3339. Nullable only because an unsaved model has no timestamp; every persisted row has one. */
  readonly joined_at: string | null;
}

/**
 * What an invitation currently is, DERIVED SERVER-SIDE AND NEVER STORED.
 *
 * There is no `status` column on `organization_invitations`: the state is a function of `revoked_at`,
 * `accepted_at` and `expires_at`, and `expired` is the one state a row enters with nobody writing to
 * it. Precedence is revoked, then accepted, then expired — an invitation accepted after its expiry is
 * `accepted`, not `expired`.
 *
 * The client must NEVER re-derive this from `expires_at`. Two spellings of one fact disagree the
 * moment a clock skews, and the one the UI reads would then authorize a Resend button the server
 * refuses.
 */
export type InvitationStatus = 'pending' | 'expired' | 'accepted' | 'revoked';

/** One invitation, as an administrator of the inviting organization sees it. Carries no token. */
export interface InvitationResource {
  readonly id: string;
  /** Always lower-case: a database CHECK enforces `email = lower(email)`. */
  readonly email: string;
  readonly role: Role;
  readonly status: InvitationStatus;
  /** RFC 3339. In the PAST for a row whose `status` is `expired`. */
  readonly expires_at: string;
  readonly created_at: string | null;
  /** The NAME only. The accountable actor's ULID and address are not part of a list screen. */
  readonly invited_by_name: string;
}

/**
 * `{"data": {"acknowledged": true}}` — and there is no `false`. A failure is the error envelope, so a
 * client that branches on this boolean is branching on a constant; what it is FOR is giving every
 * mutating endpoint with nothing to return a body at all, because
 * `DumpOpenApiCommand::operation()` emits `properties => []` — invalid JSON Schema — for a 204 (D8).
 *
 * Five hand-written copies of this interface existed across `apps/web` before it moved here, which is
 * the same fifth-copy pattern that produced `asText` (D41).
 */
export interface AcknowledgementResource {
  readonly acknowledged: boolean;
}

/**
 * The two list bodies, whose extra nesting level is FORCED rather than chosen.
 *
 * `App\Support\Contracts\ResponseShape` maps a response KEY to a schema class and the dumper composes
 * one object whose properties are those keys — the attribute has no shape meaning "an array of". And
 * `tests/Contract/OpenApiDocumentTest.php` requires every published component to carry
 * `additionalProperties: false`, which an array-typed schema cannot. So `{"data": {"members": [...]}}`
 * rather than `{"data": [...]}` (D26). The wrapper also buys a place for `next_cursor` and `total` to
 * land later without moving the list.
 *
 * Typed here so the named key is unwrapped against a shared declaration rather than an inline literal
 * at each fetcher.
 */
export interface MemberCollectionResource {
  readonly members: readonly MemberResource[];
}

export interface InvitationCollectionResource {
  readonly invitations: readonly InvitationResource[];
}
