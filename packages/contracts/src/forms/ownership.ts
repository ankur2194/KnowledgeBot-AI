/**
 * Ownership columns. They are ABSENT from every schema, every `defaultValues` object and every
 * submitted payload — the server guards them (not fillable, in no rule set), so a client that
 * sends one is attempting privilege escalation and gets a silent 200 with nothing changed
 * (rhf-zod-forms NN1, laravel-rbac-policies NN5).
 *
 * The organization comes from the authenticated context, never from request input
 * (kb-tenancy-isolation NN6). This list exists so the assertion is a test rather than a reviewer:
 * every schema is `z.strictObject`, and a test asserts no schema path intersects this set.
 */
export const OWNERSHIP_KEYS = ['organization_id', 'org_id', 'user_id', 'created_by', 'id'] as const;

export type OwnershipKey = (typeof OWNERSHIP_KEYS)[number];

const OWNERSHIP_SET: ReadonlySet<string> = new Set<string>(OWNERSHIP_KEYS);

/** True for `organization_id` and for `retrieval.organization_id` alike — a nested path is the
 *  shape a defaults builder leaks, not a top-level key. */
export function isOwnershipPath(path: string): boolean {
  return path.split('.').some((segment) => OWNERSHIP_SET.has(segment));
}
