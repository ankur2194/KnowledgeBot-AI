/**
 * The organization's four quota ceilings — `GET|PUT /api/v1/organizations/{organization}/quotas`.
 *
 * TYPES ONLY, ZERO RUNTIME VALUES: re-exported from `src/index.ts`, which is budgeted at <=1 kB
 * brotli inside apps/widget's app shell. `QUOTA_METRICS` — the tuple a screen iterates — lives
 * behind `@kb/contracts/forms` for the same reason `ORG_ROLES` and `BOT_STATUSES` do.
 *
 * ── THE READ AND THE WRITE SIT BEHIND DIFFERENT PERMISSIONS, AND THE SCREEN HAS TO SHOW IT ──────
 * `show` is `analytics.view` (owner, admin, analyst); `update` is `quotas.manage`, which the
 * Organization OWNER alone holds among the four org roles — `OrgRole::grants()` excludes the
 * Administrator from it by name, alongside `members.manage_owner`.
 *
 * ── AND THERE IS A SEVENTH CHECK THE ROLE MATRIX CANNOT EXPRESS ─────────────────────────────────
 * `QuotaLimitService::apply()` refuses a RAISE — a higher number, or `null` where a number stood —
 * from any actor without `users.is_platform_owner`. LOWERING IS FREE. It cannot be a policy: a
 * policy takes `(user, record, permission)` and has no argument position for the DIRECTION of a
 * change, which is a comparison between the submitted numbers and the persisted ones.
 *
 * THE SERVER'S OWN EXPLANATION NEVER REACHES A CLIENT, and that is measured rather than assumed:
 * the refusal is `error_class: 'authorization'`, and `bootstrap/app.php`'s render closure rewrites
 * EVERY authorization message to the constant `'This action is not permitted.'` so the deny split
 * cannot become an enumeration oracle. `QuotaLimitService::RAISING_NEEDS_PLATFORM_OWNER` is
 * therefore unreadable from the browser. A quota screen that wants to explain the asymmetry has to
 * do it BEFORE the submit, from `SessionUser.is_platform_owner` and the direction of the edit — see
 * `apps/web/src/features/quotas`.
 */

/**
 * Which allowance. The four are the server's `QuotaMetric` enum, in declaration order, and the list
 * on `QuotaResource` is TOTAL — one entry per metric, always — so a client rendering four rows never
 * has to decide what a missing one means.
 */
export type QuotaMetricName = 'storage_bytes' | 'bots' | 'users' | 'monthly_tokens';

/**
 * Which store produced `used`.
 *
 * This endpoint always reads PostgreSQL, so a healthy response is `database` on every row. A `cache`
 * value means the primary read was skipped and the number is a LOWER BOUND — worth surfacing as a
 * caveat rather than silently rendering as fact.
 */
export type QuotaUsageSource = 'database' | 'cache';

/** One metric: how much is used, what the ceiling is, and which store answered. */
export interface QuotaMetricUsage {
  readonly metric: QuotaMetricName;
  /**
   * Consumption in the metric's current period. `monthly_tokens` resets on the first of the calendar
   * month, UTC; the other three never reset. `storage_bytes` and `monthly_tokens` accumulate from
   * the usage ledger; `bots` and `users` are point-in-time counts that go DOWN when a row is deleted.
   */
  readonly used: number;
  /**
   * The ceiling.
   *
   * NULL MEANS UNLIMITED AND 0 MEANS NOTHING IS ALLOWED. They are opposite states and both render as
   * "no number" on a form, which is exactly how they get conflated — so the control has to spell
   * "Unlimited" as its own choice rather than treating an empty input as one.
   */
  readonly limit: number | null;
  /** `limit − used`, floored at 0, or `null` when unlimited. An over-quota organization has zero
   *  left, never less. */
  readonly remaining: number | null;
  /** True when `used` has reached the ceiling. Always `false` for an unlimited metric, whatever
   *  `used` says. */
  readonly exceeded: boolean;
  readonly source: QuotaUsageSource;
}

export interface QuotaResource {
  /** One entry per `QuotaMetric` case, in declaration order. TOTAL — see the module docblock. */
  readonly metrics: readonly QuotaMetricUsage[];
}
