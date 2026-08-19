import { z } from 'zod';

import type { ProviderConnectionStatus } from '../resources/providers.js';

/**
 * Mirrors `App\Http\Requests\UpdateProviderConnectionRequest`
 * (PATCH /api/v1/organizations/{organization}/provider-connections/{providerConnection}).
 *
 * ── THE ONE PROVIDER FORM THAT MAY HAVE A SHARED SCHEMA, AND WHY THE OTHER TWO MAY NOT ───────────
 * Three FormRequests touch a provider connection. Two of them carry `credential` — the PLAINTEXT
 * key — and both are `NO_CLIENT_FORM` entries in test/form-drift.test.ts with the reason written out
 * there: an exported, importable schema naming that field is one `defaults(resource)` away from
 * seeding `masked_key` back as the new key, and the failure is a 200 followed by `provider_auth` on
 * every subsequent call.
 *
 * This one has NO credential and cannot acquire one: the FormRequest declares no such rule,
 * `ProviderConnectionEdit` has no member to hold a key, and `ProviderConnectionService::update()`
 * never reaches the vault. So the field it would leak does not exist in its vocabulary, and the two
 * rules that ARE here — `min:1`, `max:120` and the status enum — are exactly the kind that drift
 * silently: a fourth lifecycle state added server-side becomes a `<Select>` that cannot express it,
 * with nothing red anywhere. `form-drift.test.ts` probes both against the server's own `rules()`.
 *
 * It MIRRORS the FormRequest; it does not enforce it. Client validation is a UX affordance and the
 * FormRequest is the authority (rhf-zod-forms NN2).
 *
 * ── THE LOOSENESS THAT USED TO BE HERE, AND HOW IT WENT AWAY ─────────────────────────────────────
 * This block used to record a deliberate looseness: `label` carried `required_without:status|string|
 * max:120` and NO `min:1`, so `{label: "", status: "active"}` was ACCEPTED by the server and blanked
 * the label — `required_without` was satisfied by `status` being present, and `string`/`max:` both
 * pass on the empty string. The schema reproduced it rather than tightening locally, because a form
 * STRICTER than the server removes functionality nobody reports.
 *
 * REPORTING IT WAS THE RIGHT MOVE AND THE SERVER FIXED IT. The blank was not cosmetic: the update
 * writes the new label into its own audit row and into every later one, so one empty PATCH erased the
 * only human-readable identifier a reviewer had for that connection, retroactively. `min:1` is now on
 * the FormRequest and this schema mirrors it — so the direction of the disagreement is what mattered,
 * not the size of it.
 *
 * The server chose `min:1` over `filled` because of the harness that found this: `filled` appears in
 * neither `PROBED_RULES` nor `UNPROBED_RULES` in test/form-drift.test.ts, so the "every rule name is
 * probed, exempt, or explicitly unprobed" gate would have failed by name. `min:1` is already probed —
 * `min:1 - 1` synthesizes `""` and asserts the server rejects it — so the tightening arrived with a
 * check on it rather than as an untested rule name.
 */

/**
 * THE RUNTIME STATUS TUPLE, and this subpath is the only place in the package it may live.
 * `src/resources/providers.ts` declares `ProviderConnectionStatus` as a union with zero runtime
 * values because it is re-exported from the ROOT entry, budgeted at <=1 kB brotli inside the
 * widget's app shell, and test/resource-drift.test.ts asserts that entry's export list.
 *
 * `satisfies` rather than an annotation, so the tuple stays a literal tuple for `z.enum` while a
 * member that is not a `ProviderConnectionStatus` — or a member DROPPED after the union grows — is a
 * typecheck failure here rather than a select that silently omits an option.
 */
export const PROVIDER_CONNECTION_STATUSES = [
  'active',
  'invalid',
  'revoked',
] as const satisfies readonly ProviderConnectionStatus[];

/**
 * `strictObject`, and BOTH KEYS OPTIONAL, because the endpoint is a PATCH: either field alone is a
 * legitimate body. The server expresses "but not neither" as `required_without` in BOTH directions
 * rather than as a `withValidator` hook, precisely so the constraint appears in the dumped manifest
 * — `kb:dump-form-rules` records `rules()` and nothing else, so a constraint in a closure is a
 * constraint no client is ever told about.
 *
 * The `superRefine` below is this side of that mutual pair. The drift harness classifies
 * `required_without` as CROSS_FIELD and therefore SUPPRESSES the presence probes for both fields —
 * it cannot answer "is this field required?" from one field's rule list — so this refinement is
 * asserted by hand (test/form-drift.test.ts's cross-field section) rather than by a generated probe.
 * The message is the server's own wording, so the two sides say the same thing whichever gets there
 * first.
 */
export const providerConnectionEditSchema = z
  .strictObject({
    label: z.string().min(1).max(120).optional(),
    status: z.enum(PROVIDER_CONNECTION_STATUSES).optional(),
  })
  .superRefine((value, ctx) => {
    if (value.label === undefined && value.status === undefined) {
      ctx.addIssue({
        code: 'custom',
        path: ['label'],
        message:
          'An edit names a label, a status, or both. A request that changes neither would write ' +
          'an audit row describing an edit that did not happen.',
      });
    }
  });

export type ProviderConnectionEditIn = z.input<typeof providerConnectionEditSchema>;
export type ProviderConnectionEditOut = z.output<typeof providerConnectionEditSchema>;

/**
 * The narrow shape the defaults factory reads out of `ProviderConnectionResource`. STRUCTURAL ON
 * PURPOSE and NOT the resource type itself, and this is the load-bearing line in the file.
 *
 * `providerConnectionEditDefaults(connection)` is the only path from server data into this form's
 * state, and it can reach exactly two fields. Typing the parameter as `ProviderConnectionResource`
 * would compile identically today and would put `masked_key` in scope at the one call site where a
 * spread is tempting — `reset({...connection})` keeps every key it is handed, so the credential
 * input would come back pre-filled with `…4a91` and submit it. A two-field structural parameter
 * makes that spread a typecheck failure instead of a code-review question.
 */
export interface ProviderConnectionEditSource {
  readonly label: string;
  readonly status: ProviderConnectionStatus;
}

/** NEVER `reset(resource)`. See `ProviderConnectionEditSource`. */
export const providerConnectionEditDefaults = (
  source: ProviderConnectionEditSource,
): ProviderConnectionEditIn => ({
  label: source.label,
  status: source.status,
});
