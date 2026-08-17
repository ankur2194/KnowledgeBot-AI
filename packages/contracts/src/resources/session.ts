/**
 * The `SessionResource` the control plane returns from `GET /api/v1/me`, `POST /api/v1/auth/login`,
 * `POST /api/v1/auth/register`, `POST /api/v1/session/organization` and
 * `POST /api/v1/auth/invitations/accept` — one shape from five endpoints, which is why it is one
 * type rather than five.
 *
 * ── TYPES ONLY. ZERO RUNTIME VALUES. ─────────────────────────────────────────────────────────────
 * No `const`, no `enum`, no lookup table, not even a frozen array of roles. This module is
 * re-exported from `src/index.ts` — the root entry, budgeted at <=1 kB brotli inside apps/widget's
 * 30 kB app shell (index.ts:1-6) — and the ONLY reason that is free is that `tsconfig.base.json:12`
 * sets `verbatimModuleSyntax: true`, so the `export type { … }` re-export there is ERASED and emits
 * nothing into dist/index.js. A bare `export { ROLES }` would be a real re-export, would drag this
 * module into the widget's import graph, and would spend that budget on a list the widget never
 * reads. `Role` is therefore a union type and not a `ROLES` tuple. If a runtime role list is ever
 * needed it goes behind the `./forms` subpath, never here, and `test/resource-drift.test.ts`
 * asserts the root entry's export list so this stays a property rather than a promise.
 *
 * ── `id` HERE IS NOT AN `OWNERSHIP_KEYS` VIOLATION. ──────────────────────────────────────────────
 * `src/forms/ownership.ts:11` lists `id` among the columns that must never appear, and this file
 * declares three of them. Both are correct, and the two rules only read as contradictory because
 * nobody wrote this sentence down: THE BAN IS SCOPED TO FORM SCHEMAS, `defaultValues` OBJECTS AND
 * SUBMITTED PAYLOADS (`forms/ownership.ts:1-10`) — it is a rule about what a CLIENT SENDS, because
 * a client that posts an ownership column is attempting privilege escalation for a silent 200. It
 * is not a rule about what a SERVER RETURNS. A resource type that cannot carry `id` cannot address
 * anything: `current_organization_id` is precisely what the org switcher compares against, and the
 * ban is what stops that id from being smuggled back through a form field instead of through the
 * one endpoint whose subject IS the ownership relation (`forms/index.ts`, drift suite).
 *
 * ── snake_case verbatim ─────────────────────────────────────────────────────────────────────────
 * Every field is a straight carry of the JSON, for the reason `errors.ts:18-21` gives: a rename
 * layer is a place for a typo to read `undefined` with nothing thrown.
 *
 * HAND-WRITTEN, MECHANICALLY CHECKED. There is no TypeScript generator in this repo — no
 * `openapi-typescript` anywhere in the tree — so `test/resource-drift.test.ts` set-compares these
 * keys against `openapi/core-api.openapi.json` once the control plane's dump describes them.
 * (A CI comment used to assert that `src/resources/` is generated from that document. It is not,
 * and both that claim and the workflow carrying it are gone as of 2026-08-17.)
 */

/**
 * The authenticated user. NOT owned by an organization — a user can be a member of several, and
 * `services/core-api/app/Models/User.php:16-19` states that as an invariant.
 */
export interface SessionUser {
  readonly id: string;
  readonly name: string;
  readonly email: string;
  /**
   * A BOOLEAN, not a timestamp, and that is a deliberate narrowing of `users.email_verified_at`.
   * The SPA renders a "verify your email" banner and a resend button; it never renders the date,
   * and shipping the timestamp would invite a UI that does. It is also the SPA's ONLY discovery
   * channel for the unverified state: the render closure rewrites every 403 to
   * 'This action is not permitted.', so a `verified`-gated route cannot tell the client WHY.
   */
  readonly email_verified: boolean;
  /**
   * Platform ownership, which is not an organization role and therefore not in `Role`. Renders
   * chrome (a platform-admin entry point); it authorizes nothing client-side.
   */
  readonly is_platform_owner: boolean;
}

/**
 * The fixed organization role catalog. Four members, ordered most- to least-privileged, mirroring
 * the server's `OrgRole` enum — the catalog is fixed in code and is never tenant-configurable
 * (laravel-rbac-policies). A fifth role is a server change first and this union second.
 */
export type Role = 'owner' | 'admin' | 'knowledge_manager' | 'analyst';

/**
 * Membership status, mirroring the server's `MembershipStatus` enum. Only `active` grants access:
 * `invited` and `suspended` memberships are still LISTED (see `SessionResource.organizations`) so
 * the UI can explain why an organization the user knows about cannot be selected.
 */
export type MembershipStatus = 'active' | 'invited' | 'suspended';

/**
 * One membership, FLAT — `{id, name, slug, role, status}`, not `{organization: {...}, role, status}`.
 *
 * The nesting was the obvious shape and it lost: `tests/Contract/OpenApiDocumentTest.php:321-355`
 * requires every resource component be both CLOSED and TOTAL, so a nested `organization` object
 * would have to be published as its own named component with its own totality proof, for three
 * fields that exist only inside this list. Flat is one component instead of two.
 *
 * NAMED `SessionMembership` WHILE THE FIELD IS `organizations`, and the asymmetry is deliberate — do
 * not "fix" either half. The name follows the wire: `components.schemas.SessionMembership` is what
 * `php artisan kb:dump-openapi` publishes from the PHP resource, that document is CI-gated, and
 * `resource-drift.test.ts` compares this type against it by name. It is also the more accurate of the
 * two, because `role` and `status` are facts about a MEMBERSHIP (the `organization_users` row), not
 * about an organization. The field stays `organizations` because that is what a reader indexing into it
 * is looking for, and renaming it would be a real wire change across the resource, the client and every
 * fixture — cost with no correctness gained.
 */
export interface SessionMembership {
  readonly id: string;
  readonly name: string;
  readonly slug: string;
  readonly role: Role;
  readonly status: MembershipStatus;
}

/**
 * Everything the admin SPA needs to answer "who am I, and which organization am I acting in" — the
 * two questions that are NOT in the URL. The whole org-switching hazard in apps/web exists because
 * the answer to the second one lives in the session cookie, so this resource is the only place the
 * client can read it from, and a stale copy of it is a cross-org render.
 */
export interface SessionResource {
  readonly user: SessionUser;
  /**
   * The organization every subsequent request is scoped to, or NULL — which is a real, reachable
   * state and not an error: login succeeds for a user with zero ACTIVE memberships (invited-only,
   * or all suspended), because failing it would leave the resend-verification and
   * accept-invitation endpoints unreachable and the user with no way out. Every org-scoped screen
   * must therefore handle null rather than assume a selection.
   */
  readonly current_organization_id: string | null;
  /**
   * ALL memberships, including non-active ones, each carrying its own `status`. Filtering
   * server-side would leave the UI unable to distinguish "you have no organizations" from "your
   * membership was suspended", which are different sentences and different next steps. The client
   * filters for the switcher; it never infers.
   */
  readonly organizations: readonly SessionMembership[];
}

/**
 * `POST /api/v1/auth/invitations/preview` — what the holder of an invitation token is shown before
 * they register.
 *
 * NO `id` OF ANY KIND, and no inviter identity. The endpoint is guest-reachable and authorized
 * solely by possession of a 256-bit random, so it publishes the minimum that lets the holder
 * RECOGNISE the invitation: which org, which address, which role, and how long it is good for. An
 * organization id would let a token holder address an org they are not yet a member of, and the
 * inviter's name is a person's identity disclosed to someone who is not yet in the tenant.
 *
 * `email` is the address the invitation is PINNED to. It is displayed, never submitted: the
 * register form has no `email` field precisely so an invitee cannot register under someone else's
 * address (see src/forms/auth.ts).
 */
export interface InvitationPreview {
  readonly organization_name: string;
  readonly email: string;
  readonly role: Role;
  /** ISO 8601, straight off the wire. Formatting is a rendering concern, not a contract one. */
  readonly expires_at: string;
}
