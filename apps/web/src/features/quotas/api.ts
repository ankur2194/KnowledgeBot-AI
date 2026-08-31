import type { QuotaMetricName, QuotaMetricUsage, QuotaResource, Role } from '@kb/contracts';
import type { QuotaLimitsOut } from '@kb/contracts/forms';
import { QUOTA_METRICS, quotaFieldFor } from '@kb/contracts/forms';
import updateQuotaLimitsRules from '@kb/contracts/rules/UpdateQuotaLimitsRequest.json';

import { formatBytes, formatCount } from '@/features/analytics/api';
import { organizationPath } from '@/features/providers/api';
import { browserFetchData, sessionCredential } from '@/lib/api/browser';
import { knownPathsFromRules, type FormRulesManifest } from '@/lib/forms/known-paths';

/**
 * THE QUOTA TRANSPORT — read the four ceilings and their usage, and replace all four.
 *
 * REACT-FREE on purpose: nothing here imports a hook, so a spec can call a fetcher directly and a
 * render site cannot acquire a second copy of the envelope knowledge.
 *
 * ── THE READ AND THE WRITE SIT BEHIND DIFFERENT PERMISSIONS, WHICH IS UNUSUAL AND IS THE POINT ──
 * `show` demands `analytics.view` — reading how much of an allowance is used is a REPORT, and it is
 * the same `storage_bytes_used` figure the dashboard already shows every holder of that permission.
 * `update` demands `quotas.manage`, which the ORGANIZATION OWNER alone holds among the four org
 * roles: `OrgRole::grants()` excludes the Administrator from it by name, alongside
 * `members.manage_owner`, because §6.3 says an administrator "may not own BILLING or destructive
 * organization-level actions" and a ceiling is the billing boundary wearing an operational hat.
 *
 * ── AND THERE IS A SEVENTH CHECK NEITHER PERMISSION CAN EXPRESS ─────────────────────────────────
 * `QuotaLimitService::apply()` refuses a RAISE from any actor without `users.is_platform_owner`.
 * LOWERING IS FREE. It cannot be a policy — a policy takes `(user, record, permission)` and has no
 * argument position for the DIRECTION of a change, which is a comparison between the submitted
 * numbers and the persisted ones — so it lives in the service and this client has to mirror the
 * *disclosure* rather than the enforcement. See `quotaRaises` below.
 */

export const quotasPath = (orgId: string): string => `${organizationPath(orgId)}/quotas`;

/**
 * `GET .../quotas` -> 200 `{data:{metrics:[…]}}` | 403 | 404.
 *
 * THE LIST IS TOTAL — one entry per metric, always, in enum order — so a client rendering four rows
 * never has to decide what a missing one means. `source` on each row says which store answered;
 * this endpoint always reads PostgreSQL, so a `cache` value means the primary read was skipped and
 * the number is a LOWER BOUND, which the screen surfaces rather than rendering as fact.
 *
 * NO ENTITY-STATUS CHECK ON THE SERVER: reading how much of an allowance is left is exactly what a
 * suspended organization's operator needs to do, and this action writes nothing. So there is no 409
 * branch here and none should be added.
 */
export const fetchQuotas = async (orgId: string, signal: AbortSignal): Promise<QuotaResource> =>
  browserFetchData<QuotaResource>({
    path: quotasPath(orgId),
    credential: await sessionCredential(),
    // Forwarded because `queryClient.cancelQueries()` is a no-op against a queryFn that drops it,
    // and cancelling in-flight reads is step 2 of both logout and the organization switch.
    signal,
  });

/**
 * `PUT .../quotas` -> 200 `{data:{metrics:[…]}}` | 403 | 404 | 409 | 422.
 *
 * A PUT AND NOT A PATCH, and the body is COMPLETE. On these four keys an omitted one and a null one
 * would otherwise mean the same thing while meaning opposite things — "leave it alone" and "remove
 * this ceiling entirely" — so every field carries `present` and a client that dropped a key would
 * silently make an organization unlimited. `quotaLimitsSchema` is a `strictObject` with four
 * REQUIRED keys for exactly that reason.
 *
 * FOUR REFUSALS AND THE SCREEN PRE-JUDGES NONE OF THEM: 403 for a role without `quotas.manage`, 403
 * AGAIN for a raise by a non-platform-owner (the same class, deliberately — the caller is not over a
 * quota, they are not permitted to perform this act), 409 for a suspended organization, 422 for a
 * value outside the bounds.
 *
 * NO OPTIMISTIC UPDATE, and no cache write from the response's ceilings alone: `apply()` returns the
 * row AFTER the transaction with usage RECOMPUTED, which is a fact the client cannot derive. The
 * 200 body is written to the cache whole, which is not optimism — it is the server's answer.
 */
export const updateQuotaLimits = async (
  orgId: string,
  limits: QuotaLimitsOut,
): Promise<QuotaResource> =>
  browserFetchData<QuotaResource>({
    path: quotasPath(orgId),
    method: 'PUT',
    body: limits,
    credential: await sessionCredential(),
  });

/**
 * The paths this form renders, derived from the endpoint's own dumped rules.
 *
 * DERIVED AND NOT HAND-TYPED. The manifest's four keys ARE the four controls — this is the case
 * `knownPathsFromRules` exists for, unlike the upload form where the manifest describes eleven paths
 * against a picker that renders two. A 422 on any of them lands under its own control; anything else
 * reaches `root.serverError`, which is where the raise refusal and the suspended-organization 409
 * both go.
 */
export const QUOTA_KNOWN_PATHS: readonly string[] = knownPathsFromRules(
  updateQuotaLimitsRules as FormRulesManifest,
);

/**
 * AFFORDANCE, NEVER AUTHORIZATION. Laravel answers 403 whatever this returns.
 *
 * A POSITIVE TEST OVER A LISTED SET OF ONE, and the set really is one: `quotas.manage` is the only
 * permission in the catalogue an ADMINISTRATOR does not hold. Writing it as `role === 'owner'` would
 * read as an oversight; writing it as a listed set says it was decided.
 */
export const canManageQuotas = (role: Role | null): boolean => role === 'owner';

/** The role to NAME where the screen explains why the form is read-only. */
export const QUOTA_MANAGE_ROLE = 'owner';

// ── THE RAISE ASYMMETRY, DISCLOSED CLIENT-SIDE BECAUSE THE SERVER STRUCTURALLY CANNOT ───────────

/**
 * Which metrics this edit would RAISE.
 *
 * ── WHY THE CLIENT HAS TO SAY THIS AT ALL ───────────────────────────────────────────────────────
 * `QuotaLimitService` has a sentence written for exactly this moment —
 * `RAISING_NEEDS_PLATFORM_OWNER`: *"A quota may be lowered from here, and raising or removing one is
 * a plan change: it needs a platform operator."* IT NEVER REACHES A BROWSER. The refusal is
 * `error_class: 'authorization'`, and `bootstrap/app.php`'s render closure replaces the message of
 * EVERY authorization envelope with the constant `'This action is not permitted.'` so that the
 * 403/404 deny split cannot become an enumeration oracle one layer down. Measured, not assumed: the
 * `$errorClass === 'authorization'` arm of that `match` has no exception for a self-raised
 * `KbException`.
 *
 * So the choice is between letting the owner discover the rule by having a save fail with a bare
 * class-mapped sentence, or disclosing it before the submit from the two things this client does
 * have: `SessionUser.is_platform_owner`, and the direction of the edit. This is the second.
 *
 * ── `null` IS THE LARGEST POSSIBLE RAISE ────────────────────────────────────────────────────────
 * `null` means UNLIMITED, so `100 → null` is a bigger raise than `100 → 10_000` and the server's
 * `raisesAny()` treats it as one. The four cases, matching that method exactly:
 *
 *     current null,   proposed null     unchanged — not a raise (nothing is higher than unlimited)
 *     current null,   proposed number   a LOWERING, and it is the one that reads backwards
 *     current number, proposed null     the ceiling is REMOVED — the largest raise available
 *     current number, proposed number   an ordinary comparison
 *
 * ── IT IS A DISCLOSURE AND NOT A CONTROL ────────────────────────────────────────────────────────
 * The server decides, under a lock, against the values the repository actually read — this compares
 * against a row that may already be stale. A screen may use it to explain and to disable a button;
 * it may never use it to conclude that a save WILL succeed.
 */
export const quotaRaises = (
  current: QuotaResource,
  proposed: QuotaLimitsOut,
): readonly QuotaMetricName[] =>
  QUOTA_METRICS.filter((metric) => {
    const stored = current.metrics.find((row) => row.metric === metric)?.limit ?? null;
    const next = proposed[quotaFieldFor(metric)];
    if (next === null) return stored !== null;
    if (stored === null) return false;
    return next > stored;
  });

// ── THE DISPLAY VOCABULARY ──────────────────────────────────────────────────────────────────────

export interface QuotaMetricDisplay {
  readonly label: string;
  /** One sentence on what the number counts and when it resets. */
  readonly description: string;
  /** How a used/limit figure reads for this metric. Bytes are not counts. */
  readonly format: (value: number) => string;
  /** The unit a number input is entered in, said next to the field so nobody types gigabytes. */
  readonly inputUnit: string;
}

/**
 * A `Record` keyed by the union, so a fifth metric added to `QuotaMetricName` fails to typecheck
 * HERE rather than rendering as a bare wire string on the screen.
 *
 * ── `storage_bytes` AND `monthly_tokens` ACCUMULATE; `bots` AND `users` ARE COUNTS ──────────────
 * The difference matters to the reader, not just to the server: a count goes DOWN when a row is
 * deleted, so an over-quota `bots` is fixed by deleting a bot, while an over-quota
 * `monthly_tokens` is fixed by waiting for the first of the month. Saying which is which is the
 * whole content of `description`.
 */
const QUOTA_DISPLAY = {
  storage_bytes: {
    label: 'Storage',
    description:
      'Bytes currently occupied by uploaded and crawled content. It never resets — it goes down when a source is purged.',
    format: (value: number) => formatBytes(value),
    inputUnit: 'bytes',
  },
  bots: {
    label: 'Bots',
    description: 'Bots that exist right now. Deleting one frees a slot immediately.',
    format: (value: number) => formatCount(value),
    inputUnit: 'bots',
  },
  users: {
    label: 'Members',
    description: 'People with a membership in this organization, including suspended ones.',
    format: (value: number) => formatCount(value),
    inputUnit: 'members',
  },
  monthly_tokens: {
    label: 'Monthly tokens',
    description:
      'Provider tokens spent this calendar month. It resets on the first of the month, UTC.',
    format: (value: number) => formatCount(value),
    inputUnit: 'tokens',
  },
} as const satisfies Readonly<Record<QuotaMetricName, QuotaMetricDisplay>>;

/**
 * A `Map`, and not the object above, for the LOOKUP: the key comes off the wire, and indexing a
 * plain object by a server-supplied value is the `security/detect-object-injection` sink. It also
 * gives the unknown-metric case a natural answer — the raw wire word, counted, which is what the row
 * claims and what the server will act on. Inventing a label for it would be worse.
 */
const QUOTA_DISPLAY_BY_NAME = new Map<string, QuotaMetricDisplay>(Object.entries(QUOTA_DISPLAY));

export const quotaMetricDisplay = (metric: string): QuotaMetricDisplay =>
  QUOTA_DISPLAY_BY_NAME.get(metric) ?? {
    label: metric,
    description: '',
    format: (value: number) => formatCount(value),
    inputUnit: '',
  };

/**
 * How full a metric is, as a percentage, or `null` when there is no ceiling to be full of.
 *
 * `null` FOR UNLIMITED rather than 0: a meter at zero over an unlimited metric would say "you have
 * used none of your allowance", which is false in the way that matters — there is no allowance. The
 * screen renders no meter at all in that case.
 *
 * CLAMPED AT 100 FOR THE BAR ONLY. An over-quota organization is genuinely past its ceiling, and the
 * exact figure is `used` beside `limit`; a progress bar that overflows its track is a rendering bug
 * rather than information, so the bar clamps and the numbers do not.
 */
export const quotaFill = (row: QuotaMetricUsage): number | null => {
  if (row.limit === null) return null;
  if (row.limit === 0) return 100;
  return Math.min(100, (row.used / row.limit) * 100);
};
