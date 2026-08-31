import { z } from 'zod';

import type { QuotaMetricName, QuotaResource } from '../resources/quotas.js';

/**
 * Mirrors `UpdateQuotaLimitsRequest` in services/core-api
 * (PUT /api/v1/organizations/{organization}/quotas).
 *
 * It MIRRORS it; it does not enforce it. The FormRequest is the authority and
 * test/form-drift.test.ts, probing this schema against rules/UpdateQuotaLimitsRequest.json, is what
 * keeps the two honest.
 *
 * ── A PUT AND NOT A PATCH, AND EVERY FIELD IS `present` FOR ONE REASON ──────────────────────────
 * The four ceilings are a COMPLETE SET. On this body an omitted key and a null key would otherwise
 * mean the same thing while meaning OPPOSITE things — "leave it alone" and "remove this ceiling
 * entirely" — so `UpdateQuotaLimitsRequest` puts `present` on all four and the server's own message
 * says it in as many words. A client that dropped a key from its payload would silently make an
 * organization unlimited.
 *
 * `strictObject` with four REQUIRED keys is therefore the mirror, not four optional ones. That is
 * also what makes the form's `defaultValues` safe: it is seeded from the four rows the GET returned,
 * so every key is present before the user touches anything.
 *
 * ── `null` IS UNLIMITED AND `0` IS NOTHING-IS-ALLOWED ───────────────────────────────────────────
 * They are opposite states and both render as "no number" in a bare `<input type="number">`, which
 * is exactly how they get conflated. The screen therefore renders an explicit Unlimited control
 * beside the number field rather than treating an emptied input as a choice — and this schema keeps
 * both expressible: `null` for unlimited, `0` for a hard stop.
 *
 * ── `organization_id` AND EVERY OTHER OWNERSHIP COLUMN ARE UNREPRESENTABLE HERE ─────────────────
 * The organization comes from the bound route segment, which `org.member` has already proved the
 * caller belongs to (kb-tenancy-isolation NN6). `strictObject` is what makes a leaked key a failing
 * test rather than something a reviewer has to spot.
 *
 * ── WHAT THIS SCHEMA CANNOT AND MUST NOT CHECK: THE DIRECTION OF THE CHANGE ─────────────────────
 * `QuotaLimitService::apply()` refuses a RAISE from anyone without `users.is_platform_owner`, and
 * lowering is free. That is not a validation rule — it is a comparison between the submitted numbers
 * and the PERSISTED ones, which no schema holds — so it is deliberately absent here and lives in the
 * screen, which has both halves. See `apps/web/src/features/quotas/api.ts::quotaRaises()`.
 */

/**
 * The four metrics, in the server's declaration order — which is also the order `QuotaResource.metrics`
 * arrives in and the order the form renders.
 *
 * A RUNTIME TUPLE, and this subpath is the only place in the package it may live: the root entry is
 * types-only and budgeted at <=1 kB brotli inside apps/widget's app shell, so `src/resources/quotas.ts`
 * declares `QuotaMetricName` as a UNION with zero runtime values. Same split as `ORG_ROLES` and
 * `BOT_STATUSES`, and each spelling is pinned to the server independently: the union against the
 * published `enum` in test/resource-drift.test.ts, this tuple against the FIELD NAMES below.
 */
export const QUOTA_METRICS = [
  'storage_bytes',
  'bots',
  'users',
  'monthly_tokens',
] as const satisfies readonly QuotaMetricName[];

/**
 * The wire field for a metric: `storage_bytes` → `storage_bytes_quota`.
 *
 * DERIVED, NEVER A SECOND LIST. The request's four keys and the resource's four metric names are one
 * vocabulary with a suffix, and writing them out twice is how a fifth metric arrives with three of
 * its four spellings updated.
 */
export const quotaFieldFor = <M extends QuotaMetricName>(metric: M): `${M}_quota` =>
  `${metric}_quota` as `${M}_quota`;

/**
 * `Number("")` is `0`, so a cleared field under a bare `z.coerce.number()` would submit a HARD STOP
 * where the user meant "no limit". `''` becomes `null` — unlimited — which is the only reading a
 * cleared ceiling can honestly have, and the screen's Unlimited control is what makes it a choice
 * rather than an accident. Laravel's global `ConvertEmptyStringsToNull` does the same thing on the
 * other side, before any rule runs, so the two agree about the empty case by construction.
 *
 * ── `undefined` IS NOT MAPPED, AND THAT OMISSION IS THE `present` RULE ──────────────────────────
 * An earlier spelling folded `undefined` into `null` alongside `''`, which made an OMITTED key parse
 * as "unlimited" — so the schema accepted a body that removes a ceiling nobody asked to remove, and
 * the drift harness caught it by name on all four fields ("form accepts input the server rejects:
 * bots_quota — omitted"). `present` on the server means the key must be THERE; the difference
 * between an absent key and a null one is the difference between "leave it alone" and "make this
 * organization unlimited". Passing `undefined` through leaves it to fail the inner parse, which is
 * what makes the key required — the same shape `embedding-designation.ts`'s `clearable` uses, and
 * for the same `present|nullable` pair.
 *
 * `9007199254740991` is the server's own `max:` — `Number.MAX_SAFE_INTEGER`, so a value that
 * survives this bound survives a JSON round trip through a double without silently changing, and the
 * boundary+1 probe is the first integer that would not.
 */
const ceiling = z.preprocess(
  (value) => (value === '' ? null : value),
  z.coerce
    .number({ error: 'Enter a whole number, or choose Unlimited.' })
    .int({ error: 'A quota is a whole number.' })
    .min(0, { error: 'A quota cannot be negative. Zero means nothing is allowed.' })
    .max(Number.MAX_SAFE_INTEGER)
    .nullable(),
);

export const quotaLimitsSchema = z.strictObject({
  storage_bytes_quota: ceiling,
  bots_quota: ceiling,
  users_quota: ceiling,
  monthly_tokens_quota: ceiling,
});

export type QuotaLimitsIn = z.input<typeof quotaLimitsSchema>;
export type QuotaLimitsOut = z.output<typeof quotaLimitsSchema>;

/**
 * The ONLY path from server data into form state.
 *
 * NEVER `reset(quotaResource)`: that object is `{metrics: [...]}` — a list of six-field rows
 * carrying `used`, `remaining`, `exceeded` and `source`, none of which the endpoint accepts. RHF
 * keeps every key it is handed and submit posts them back, so a `reset()` here would send a
 * `metrics` array to a PUT that validates four scalars and get a 422 nobody can act on.
 *
 * A metric MISSING from the response resolves to `null` — unlimited — which is the server's own
 * reading of an absent ceiling. The list is documented as TOTAL, so this branch should be
 * unreachable; it is here because a defaults builder that throws on a short list would take the
 * whole screen down over a field the form can express perfectly well.
 */
export const quotaLimitsFormDefaults = (resource: QuotaResource): QuotaLimitsIn => {
  const limitOf = (metric: QuotaMetricName): number | null =>
    resource.metrics.find((row) => row.metric === metric)?.limit ?? null;

  return {
    storage_bytes_quota: limitOf('storage_bytes'),
    bots_quota: limitOf('bots'),
    users_quota: limitOf('users'),
    monthly_tokens_quota: limitOf('monthly_tokens'),
  };
};
