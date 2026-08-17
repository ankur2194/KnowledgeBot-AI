import type { Role } from '@kb/contracts';

/**
 * Role COPY — the labels and the one sentence of role guidance this UI renders. Nothing here is a
 * catalog, and that is the point of the file now.
 *
 * ── THE CATALOG MOVED, AND THIS NOTE IS WHY THE MOVE MATTERED ─────────────────────────────────────
 * `ORG_ROLES` and `DEFAULT_INVITE_ROLE` used to live here as declared PROVISIONAL stopgaps, because
 * `packages/contracts/rules/StoreInvitationRequest.json` did not exist yet and rhf-zod-forms NN3 says
 * a schema ships WITH a committed manifest and a drift test or it does not ship. They are now
 * `ORG_ROLES` and `inviteMemberFormDefaults()` behind `@kb/contracts/forms` — the subpath
 * `src/resources/session.ts` names as the one legal home for a runtime role list (the ROOT entry is
 * types-only, to keep apps/widget inside its <=1 kB brotli budget).
 *
 * What the move bought is a check neither copy could have: `test/form-drift.test.ts` probes the
 * manifest's `in:` rule MEMBER BY MEMBER against `Rule::in(OrgRole::values())`. The local array was
 * annotated `readonly Role[]`, which catches a role the server DROPPED and is blind to one the server
 * ADDED — the direction that actually happens, and whose symptom is a select silently missing an
 * option with nothing red anywhere. Verified by mutation: removing `knowledge_manager` from the tuple
 * now fails the contracts suite by name.
 */

/**
 * `Role` -> the label a human reads.
 *
 * A `switch` and not a `Record<Role, string>` lookup, for two reasons that both bite: TypeScript
 * proves the switch exhaustive, so a fifth role added to the union fails the TYPECHECK here rather
 * than rendering the raw `knowledge_manager` wire value in a table cell; and an object indexed by a
 * value read off the wire is an object-injection sink that eslint-plugin-security reports as a
 * warning nobody can act on.
 */
export function roleLabel(role: Role): string {
  switch (role) {
    case 'owner':
      return 'Owner';
    case 'admin':
      return 'Admin';
    case 'knowledge_manager':
      return 'Knowledge manager';
    case 'analyst':
      return 'Analyst';
  }
}

/**
 * The one sentence of role copy this screen is allowed to state, because it is the one thing verified
 * in the server's own code rather than inferred: `App\Enums\OrgRole::grants()` gives Admin everything
 * EXCEPT `MembersManageOwner`, and `InvitationController::store` re-checks it as a second
 * `Gate::authorize('inviteOwner', $organization)`. Creating another owner is the one act the role
 * performing it cannot undo, so only an owner may do it.
 *
 * It is a UX AFFORDANCE, NOT ENFORCEMENT. The option is also hidden from a non-owner's select for the
 * same reason, and neither is a control: Laravel answers 403 whatever this file renders.
 */
export const OWNER_INVITE_NOTE = 'Only an owner can invite another owner.';
