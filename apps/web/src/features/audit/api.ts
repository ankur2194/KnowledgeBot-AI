import type { AuditLogCollectionResource, AuditLogResource, Role } from '@kb/contracts';
import indexAuditLogsRules from '@kb/contracts/rules/IndexAuditLogsRequest.json';

import type { StatusKind } from '@/components/status-pill';
import { organizationPath } from '@/features/providers/api';
import { browserFetch, sessionCredential, type ApiEnvelope } from '@/lib/api/browser';
import type { FormRulesManifest } from '@/lib/forms/known-paths';
import { readPaginatedEnvelope, type TablePage } from '@/lib/table/envelope';
import { MAX_PER_PAGE, type TableParamsConfig } from '@/lib/table/params';
import { enumFromRule } from '@/lib/table/rules';

/**
 * THE AUDIT TRAIL TRANSPORT — one paginated read, and nothing else. There is no write, no row route
 * and no export.
 *
 * REACT-FREE on purpose: nothing here imports a hook, so a spec can call the fetcher directly and a
 * render site cannot acquire a second copy of the envelope knowledge.
 *
 * ── PLATFORM-SCOPE ROWS ARE NEVER RETURNED, AND THE UI MUST NOT IMPLY OTHERWISE ─────────────────
 * Rows with `organization_id IS NULL` — a failed login for an address belonging to no user, a
 * platform action — are excluded by the repository. That is a DECISION and not an accident of
 * `organization_id = ?` being false for a NULL: the repository, the interface and a Security test
 * all say so, because the change that would undo it is a one-line `orWhereNull()` that reads like a
 * feature request. §6.1 assigns those rows to the platform owner, on a surface that does not exist.
 *
 * So this screen is THIS ORGANIZATION'S trail and says so. Nothing here is titled "everything that
 * happened", and the empty state does not suggest widening a filter would reveal platform rows.
 *
 * ── THERE IS NO FREE-TEXT SEARCH, AND ITS ABSENCE IS DECLARED SERVER-SIDE ───────────────────────
 * `ListQuery::rules(..., freeText: false)`. The only free-text targets `audit_logs` has are
 * `user_agent` and the `details` jsonb, where an `ILIKE '%term%'` is a sequential scan of a
 * partitioned append-only table that grows with every state change on the platform. Publishing a
 * `filter` the repository ignored would be worse than omitting it — Laravel ignores an unvalidated
 * query parameter, so the chip would render, the URL would say so, and the list would come back
 * unfiltered with nothing reported anywhere.
 */

export const auditLogsPath = (orgId: string): string => `${organizationPath(orgId)}/audit-logs`;

const INDEX_AUDIT_LOGS_RULES = (indexAuditLogsRules as FormRulesManifest).rules;

/**
 * The columns `GET .../audit-logs` permits an `ORDER BY` on — A SET OF ONE, which is unusual and is
 * the honest shape.
 *
 * All four indexes on this table end in `created_at DESC` and there is no other sortable candidate:
 * `id` is a ULID and carries the same ordering, but it has no index of its own here (the primary key
 * is the composite `(id, created_at)` a partitioned table requires), so offering it would publish a
 * sort with nothing behind it that happens to agree with the one that does.
 *
 * NOT TYPED OUT, EVER. `sort` reaches an `ORDER BY`, so `IndexAuditLogsRequest::rules()` closes the
 * set with `Rule::in(...)` and `php artisan kb:dump-form-rules` writes it here. A hand-copied list
 * is a 422 one header click away the moment the endpoint's set moves.
 */
export const AUDIT_SORTABLE_COLUMNS: readonly string[] = enumFromRule(
  INDEX_AUDIT_LOGS_RULES['sort'],
);

/**
 * THE CLOSED OPERATION VOCABULARY — 45 names at the time of writing, and WHATEVER THE MANIFEST SAYS
 * TOMORROW.
 *
 * ── THIS IS THE ENTRY THAT MATTERS MOST IN THIS FILE ────────────────────────────────────────────
 * `operation` is `Rule::in(...)` over the same closed set the WRITER enforces, so a value outside it
 * is a VALIDATION ERROR rather than an empty result — and that distinction is the whole reason the
 * filter is a `<Select>` over this array rather than a text input. A typed `?operation=bot.updated!`
 * comes back 422 and renders as an error screen; a picker built from this array cannot produce one.
 *
 * ── AND IT IS WHY A HAND-TYPED LIST WOULD BE THE WORST OPTION AVAILABLE ─────────────────────────
 * Forty-five strings is the largest vocabulary in this API, it grows every time something new is
 * audited, and nothing would compare a local copy to the server. A missing member is a filter that
 * silently cannot find a class of event — an operator asking "who rotated that credential" gets no
 * option and concludes it was never recorded. Deriving it means a 46th operation becomes selectable
 * the moment the manifest is re-dumped, with no edit to this app.
 *
 * `tests/unit/audit-list.test.ts` pins the parse so a manifest whose SHAPE changed fails by name
 * instead of degrading to an empty set at render time — which would render a filter with no options
 * and no error.
 */
export const AUDIT_OPERATIONS: readonly string[] = enumFromRule(
  INDEX_AUDIT_LOGS_RULES['operation'],
);

/** The two outcomes, from the same rule set and for the same reason. */
export const AUDIT_OUTCOMES: readonly string[] = enumFromRule(INDEX_AUDIT_LOGS_RULES['outcome']);

/**
 * The filters this screen offers, spelled exactly as the FormRequest spells them — so the URL, the
 * request and the query key all say the same thing and there is no translation layer for a typo to
 * hide in.
 *
 * ── WHAT IS DELIBERATELY ABSENT ─────────────────────────────────────────────────────────────────
 * `subject_type` and `subject_id`. They are a PAIR (`required_with:subject_id`) whose type is an
 * OPEN set of fully-qualified PHP class names, so a control for them is either a free-text box that
 * 422s on a typo or a picker over a vocabulary the server does not publish. The pairing is also
 * cross-field, which no per-field control expresses. When a "trail for this record" affordance is
 * wanted it belongs on the RECORD's own screen, where both halves are known and neither is typed.
 */
export const AUDIT_ACTOR_PARAM = 'actor_id';
export const AUDIT_OPERATION_PARAM = 'operation';
export const AUDIT_OUTCOME_PARAM = 'outcome';
export const AUDIT_FROM_PARAM = 'from';
export const AUDIT_UNTIL_PARAM = 'until';

export const AUDIT_FILTER_PARAMS: readonly string[] = [
  AUDIT_ACTOR_PARAM,
  AUDIT_OPERATION_PARAM,
  AUDIT_OUTCOME_PARAM,
  AUDIT_FROM_PARAM,
  AUDIT_UNTIL_PARAM,
];

/**
 * The view configuration, at MODULE SCOPE because `useTableParams` requires a stable identity — an
 * object literal in a render body rebuilds the params, the filter Map and every callback each
 * render.
 *
 * `defaultSort` IS `created_at` DESCENDING, mirroring `IndexAuditLogsRequest::DEFAULT_SORT` and its
 * `SortDirection::Desc`. It is the opposite of the source list's `id ASC` and deliberately: an audit
 * trail is read from the present backwards ("what just happened", "who did that"), every index on
 * the table is built `created_at DESC` for exactly that, and page one under an ascending sort is a
 * bookmark to the oldest login in the organization's history.
 */
export const AUDIT_LIST_CONFIG: TableParamsConfig = {
  sortableColumns: AUDIT_SORTABLE_COLUMNS,
  defaultSort: { id: 'created_at', desc: true },
  filterNames: AUDIT_FILTER_PARAMS,
  pageSizes: [25, 50, MAX_PER_PAGE],
};

/**
 * `GET .../audit-logs?page&per_page&sort&dir&actor_id&operation&outcome&from&until`
 *   -> 200 `{data:{audit_logs:[…],meta:{…}}}` | 403 | 404 | 422.
 *
 * `browserFetch` AND NOT `browserFetchData`, because `readPaginatedEnvelope` reads `data.audit_logs`
 * and `data.meta` together and therefore takes the WHOLE body.
 *
 * IT THROWS ON AN UNREADABLE ENVELOPE rather than returning zero rows. `{rows: [], rowCount: 0}`
 * would render the FIRST-RUN empty state to an operator whose organization has ten thousand audited
 * events, which is indistinguishable from data loss at a glance. The thrown value is not a
 * `KbError`, so it carries no `error_class`; no envelope parsed means unknown, and unknown is
 * permanently non-retryable, which is right — a shape mismatch will not fix itself on a retry.
 *
 * `signal` is forwarded because `queryClient.cancelQueries()` is a NO-OP against a `queryFn` that
 * drops it, and cancelling in-flight reads is step 2 of both logout and the organization switch.
 */
export const fetchAuditPage = async (
  orgId: string,
  params: Readonly<Record<string, string>>,
  signal: AbortSignal,
): Promise<TablePage<AuditLogResource>> => {
  const query = new URLSearchParams(params).toString();
  const body = await browserFetch<ApiEnvelope<AuditLogCollectionResource>>({
    path: `${auditLogsPath(orgId)}?${query}`,
    credential: await sessionCredential(),
    signal,
  });
  return readPaginatedEnvelope<AuditLogResource>(body, 'audit_logs');
};

/**
 * AFFORDANCE, NEVER AUTHORIZATION. Laravel answers 403 whatever this returns.
 *
 * ── THE NARROWEST GRANT IN THE CONSOLE, AND THE REASON IS ABOUT PEOPLE ──────────────────────────
 * `audit.view` is held by the OWNER and the ADMINISTRATOR and by neither of the other two. That is
 * narrower than `conversations.view`, and the difference is deliberate: an audit row names a
 * COLLEAGUE, carries their IP address and their user agent, and twelve of the forty-five operations
 * being `source.*` is not a reason to hand an ingestion operator the credential-rotation rows beside
 * them. `Permission::AuditView` also records that the grant is an EXTENSION of the spec rather than
 * a reading of it — §6.1 gives platform-level audit access to the PLATFORM owner and §6.2–§6.5 name
 * no organization role at all.
 *
 * A POSITIVE TEST OVER A LISTED SET, so a fifth role added to `Role` defaults to holding nothing.
 */
export const canViewAudit = (role: Role | null): boolean => role === 'owner' || role === 'admin';

/** The role to NAME in the forbidden state — the least-privileged role that can read this list. */
export const AUDIT_VIEW_ROLE = 'admin';

// ── THE DISPLAY VOCABULARY ──────────────────────────────────────────────────────────────────────

/**
 * `bot.domain.status_changed` -> `Bot · domain · status changed`.
 *
 * ── DERIVED FROM THE WIRE VALUE RATHER THAN LOOKED UP IN A 45-ENTRY TABLE ───────────────────────
 * A table would be a 46th place to edit when an operation is added, and its failure mode is the one
 * that matters: an unmapped name renders blank or crashes an object lookup, on a screen whose whole
 * job is to say what happened. The dotted name is already structured — `subject.verb` or
 * `subject.thing.verb` — so a transformation reads every value including one this build has never
 * heard of.
 *
 * IT DOES NOT CAPITALISE THE SEGMENTS PAST THE FIRST, because they are the server's own words and a
 * title-cased `Credential Rotated` reads as a proper noun rather than as an event.
 */
export const auditOperationLabel = (operation: string): string => {
  const segments = operation.split('.');
  const words = segments.map((segment) => segment.replaceAll('_', ' '));
  const [first, ...rest] = words;
  if (first === undefined) return operation;
  return [first.charAt(0).toUpperCase() + first.slice(1), ...rest].join(' · ');
};

/**
 * `success` and `failure` onto the CLOSED `<StatusPill>` vocabulary.
 *
 * `failure` IS `failed` AND NOT `degraded`: a failed login or a failed credential rotation is a
 * refusal that happened, not a partial success — and the pill carries a glyph and the word as well
 * as the colour, so it survives greyscale and CVD either way.
 *
 * The fallback renders the wire value in the neutral bucket rather than inventing a label: this
 * console is deployed separately from the API, and a third outcome would otherwise crash a lookup or
 * blank a cell.
 */
export const auditOutcomeKind = (outcome: string): StatusKind =>
  outcome === 'success' ? 'ready' : outcome === 'failure' ? 'failed' : 'pending';

/**
 * The `details` object as ordered `[key, value]` pairs, with every value rendered as text.
 *
 * ── READ DEFENSIVELY: THE KEY SET DIFFERS PER OPERATION AND IS NOT ENUMERATED ───────────────────
 * `AuditLogResource.details` is `additionalProperties: true` and the server's own description says
 * to read it defensively. So there is no schema here and no per-operation table — the row renders
 * whatever it carries, sorted by key so two rows of the same operation line up.
 *
 * ── NO CREDENTIAL CAN BE IN HERE, AND THAT IS A PROPERTY OF THE WRITER RATHER THAN OF THIS CODE ─
 * The write path allow-lists per operation, an unlisted field never reaches the table, and every
 * bearer value is admitted only as `<name>_fingerprint` — a keyed HMAC that answers "was THIS the
 * value" without being derivable back to it. This function does not re-filter, because a client-side
 * deny-list over an open key set is a control that reads as one and is not: it would pass anything
 * spelled slightly differently and would give the next reader a reason to believe the filter is what
 * keeps secrets out.
 *
 * Objects and arrays are JSON-stringified rather than expanded. A nested viewer on a table row is a
 * different screen; what this needs to answer is "what did this row record", and one line per key
 * does that.
 */
export const auditDetailEntries = (
  details: Readonly<Record<string, unknown>>,
): readonly (readonly [string, string])[] =>
  Object.entries(details)
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([key, value]) => [key, formatDetailValue(value)] as const);

function formatDetailValue(value: unknown): string {
  if (value === null) return 'null';
  if (typeof value === 'string') return value;
  if (typeof value === 'number' || typeof value === 'boolean') return String(value);
  try {
    return JSON.stringify(value) ?? String(value);
  } catch {
    // A cyclic or otherwise unserialisable value cannot come off a JSON wire, so this is a guard
    // against a fixture rather than a case — and it degrades to a word rather than throwing inside a
    // table cell.
    return '[unreadable]';
  }
}
