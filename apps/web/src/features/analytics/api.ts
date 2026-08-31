import type { AnalyticsResource, Role } from '@kb/contracts';
import showAnalyticsRules from '@kb/contracts/rules/ShowAnalyticsRequest.json';

import { organizationPath } from '@/features/providers/api';
import { browserFetchData, sessionCredential } from '@/lib/api/browser';
import type { FormRulesManifest } from '@/lib/forms/known-paths';

/**
 * §8.23's DASHBOARD TRANSPORT — one endpoint, eleven tiles, one round trip.
 *
 * REACT-FREE on purpose: nothing here imports a hook, so a spec can call the fetcher directly and a
 * render site cannot acquire a second copy of the envelope knowledge.
 *
 * ── ONE ENDPOINT AND NOT ELEVEN, AND THAT IS THE SERVER'S DECISION ──────────────────────────────
 * The tiles are read together — they are one screen — so a tile per endpoint would be eleven round
 * trips, eleven authorization checks and eleven chances for one of them to be scoped differently
 * from the other ten. `kb-tenancy-isolation` lists analytics as its own isolation layer precisely
 * because "group by bot_id and drop the redundant organization_id" is such an easy thing to talk
 * yourself into.
 *
 * ── THE ORGANIZATION IS IN THE PATH AND THAT IS NOT A TENANCY VIOLATION ─────────────────────────
 * Every route under `organizations/{organization}` uses `->scopeBindings()`, and `TenantContext`
 * re-reads the membership row from PostgreSQL on EVERY request. The segment is a ROUTING HINT, never
 * a scope: Laravel derives the real organization from the session and would ignore a client-supplied
 * one. The same `orgId` is separately a CACHE NAMESPACE in the query key, which is a different job.
 */

export const analyticsPath = (orgId: string): string => `${organizationPath(orgId)}/analytics`;

const SHOW_ANALYTICS_RULES = (showAnalyticsRules as FormRulesManifest).rules;

/**
 * The three query parameters this endpoint accepts, READ OUT OF ITS OWN DUMPED RULES rather than
 * typed here.
 *
 * `Object.keys` and not a literal: Laravel IGNORES an unvalidated query parameter, so a `?channel=`
 * this screen invented would render as an applied filter, the URL would say so, and the numbers
 * would come back unfiltered with nothing reported anywhere. Deriving the set means a parameter the
 * endpoint STOPS accepting also stops being sent, with no edit here.
 *
 * There is deliberately no `sort`, no `per_page` and no `page`: this is an aggregate, not a list.
 */
export const ANALYTICS_PARAM_NAMES: readonly string[] = Object.keys(SHOW_ANALYTICS_RULES);

export const ANALYTICS_BOT_PARAM = 'bot_id';
export const ANALYTICS_FROM_PARAM = 'from';
export const ANALYTICS_UNTIL_PARAM = 'until';

/**
 * `GET .../analytics?bot_id&from&until` -> 200 `{data: AnalyticsResource}` | 403 | 404.
 *
 * ── `params` IS THE TAIL OF THE QUERY KEY AND THE WHOLE OF THE REQUEST ──────────────────────────
 * One object for both, so a response can never be cached under a question it does not answer — the
 * same discipline `toRequestParams()` enforces for a table. An empty value is DROPPED rather than
 * sent as `?from=`: Laravel's `date` rule refuses an empty string, and a filter nobody set must not
 * 422 a page nobody asked to filter.
 *
 * ── NO ENTITY-STATUS CHECK ON THE SERVER, WHICH IS WHY THIS SCREEN WORKS WHEN SUSPENDED ─────────
 * A suspended organization's operator still needs to read their own numbers, and this endpoint
 * writes nothing. So there is no 409 branch here and none should be added.
 *
 * `signal` is forwarded because `queryClient.cancelQueries()` is a NO-OP against a `queryFn` that
 * drops it, and cancelling in-flight reads is step 2 of both logout and the organization switch.
 */
export const fetchAnalytics = async (
  orgId: string,
  params: Readonly<Record<string, string>>,
  signal: AbortSignal,
): Promise<AnalyticsResource> => {
  const query = new URLSearchParams(
    Object.entries(params).filter(([, value]) => value !== ''),
  ).toString();

  return browserFetchData<AnalyticsResource>({
    path: query === '' ? analyticsPath(orgId) : `${analyticsPath(orgId)}?${query}`,
    credential: await sessionCredential(),
    signal,
  });
};

/**
 * AFFORDANCE, NEVER AUTHORIZATION. Laravel answers 403 whatever this returns, the screen handles
 * that class, and a role that changed under a cached session shows up as that 403 rather than as a
 * silently missing page.
 *
 * A POSITIVE TEST OVER A LISTED SET, so a fifth role added to `Role` defaults to holding nothing.
 *
 * ── THE KNOWLEDGE MANAGER IS THE ONE ROLE WITHHELD, AND IT IS DELIBERATE SERVER-SIDE ────────────
 * `OrgRole::grants()` gives `analytics.view` to owner, admin and ANALYST — §6.5 is reporting-only
 * and the feedback split, the insufficient-evidence count and the latency percentiles are that
 * reporting. The knowledge manager's six permissions are all `sources.*` plus `providers.view` and
 * `bots.view`; none of them is a report. So an ingestion operator clicking this nav item is the
 * common case for a 403 here, exactly as an analyst clicking `/sources` is on that screen.
 */
export const canViewAnalytics = (role: Role | null): boolean =>
  role === 'owner' || role === 'admin' || role === 'analyst';

/** The role to NAME in the forbidden state — the least-privileged role that can read this page, in
 *  the words the console uses for it elsewhere (`roleLabel`, lower-cased for mid-sentence use). */
export const ANALYTICS_VIEW_ROLE = 'analyst';

// ── FORMATTING, AND EVERY ONE OF THESE HAS A "NOT MEASURED" ARM ─────────────────────────────────

/**
 * `null` IS NOT ZERO ON ANY FIELD OF THIS RESOURCE, and every formatter here says so with an em
 * dash rather than a `0`.
 *
 * A latency percentile over a window with no successful streaming call, an error rate over a window
 * with no calls, a cost for a model with no recorded price: rendering `0` for any of them reports
 * health nobody measured, and `0 ms` in particular reads as "instant" rather than as "we did not
 * measure". `references/states.md`'s defined-zero rule applied to the case where there is not one.
 */
export const EM_DASH = '—';

const COUNT = new Intl.NumberFormat();

export const formatCount = (value: number): string => COUNT.format(value);

/**
 * Milliseconds, promoted to seconds past 1000 because a p95 of 12400 is a number nobody reads at a
 * glance. One decimal on the seconds form, none on the millisecond form.
 */
export const formatDuration = (ms: number | null): string => {
  if (ms === null) return EM_DASH;
  if (ms < 1000) return `${COUNT.format(Math.round(ms))} ms`;
  return `${(ms / 1000).toFixed(1)} s`;
};

/**
 * A rate as a percentage.
 *
 * IT IS NOT CLAMPED, and `fallback_rate` is why: a turn that fell back twice writes two attempts, so
 * the ratio may legitimately exceed 1.0. The server declines to clamp it because clamping hides
 * exactly the configuration worth looking at, and a client that clamped would undo that server-side
 * decision one layer later.
 */
export const formatRate = (rate: number | null): string =>
  rate === null ? EM_DASH : `${(rate * 100).toFixed(1)}%`;

/**
 * Bytes, in the units an operator recognises.
 *
 * BINARY UNITS WITH BINARY NAMES. `storage_bytes_used` is the same ledger figure the quota gate
 * refuses against, and the quota screen renders the ceiling; the two must not disagree about what
 * "1 GB" means, so both go through this one function.
 */
const BYTE_UNITS = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'] as const;

export const formatBytes = (bytes: number | null): string => {
  if (bytes === null) return EM_DASH;
  if (bytes < 1024) return `${COUNT.format(bytes)} B`;

  let value = bytes;
  let unit = 0;
  while (value >= 1024 && unit < BYTE_UNITS.length - 1) {
    value /= 1024;
    unit += 1;
  }
  // `.at()` rather than `BYTE_UNITS[unit]`: the index is computed, and a computed member read is
  // what `security/detect-object-injection` reports — a warning nobody can act on if it is left in,
  // and one that trains a reader to ignore the rule. `.at()` is a method call over a frozen tuple
  // and returns `undefined` past the end, which the `?? 'B'` already handled.
  return `${value.toFixed(value < 10 ? 1 : 0)} ${BYTE_UNITS.at(unit) ?? 'B'}`;
};

/**
 * The window, in words, from the RESOLVED values the server echoed — never from what this client
 * asked for.
 *
 * `from` and `until` both have server-side defaults, so a caller that sent neither does not know
 * what period the numbers describe. Labelling the page from the request would put a date range on
 * screen that the numbers may not be for, which is the failure the echo exists to prevent.
 */
export const formatWindow = (from: string, until: string): string => {
  const format = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' });
  const start = new Date(from);
  const end = new Date(until);
  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return `${from} – ${until}`;
  return `${format.format(start)} – ${format.format(end)}`;
};
