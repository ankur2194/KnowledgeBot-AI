import type { BotAccessMode, BotCollectionResource, BotResource, BotStatus } from '@kb/contracts';
import indexBotsRules from '@kb/contracts/rules/IndexBotsRequest.json';

import type { StatusKind } from '@/components/status-pill';
import { organizationPath } from '@/features/providers/api';
import { browserFetch, sessionCredential, type ApiEnvelope } from '@/lib/api/browser';
import type { FormRulesManifest } from '@/lib/forms/known-paths';
import { readPaginatedEnvelope, type TablePage } from '@/lib/table/envelope';
import { MAX_PER_PAGE, type TableParamsConfig } from '@/lib/table/params';

/**
 * The bot LIST transport, the view configuration the URL is parsed against, and the two display
 * vocabularies the table renders. REACT-FREE on purpose — nothing here imports a hook, so a spec can
 * call the fetcher directly and a render site cannot accidentally acquire a second copy of the
 * envelope knowledge.
 *
 * ── THE RESOURCE TYPES ARE `@kb/contracts`', NOT THIS FILE'S ─────────────────────────────────────
 * `BotResource`, `BotCollectionResource` and `ListMetaResource` are mirrored in
 * `packages/contracts/src/resources/bots.ts` and compared against the generated OpenAPI document by
 * `test/resource-drift.test.ts`. Declaring a local `Bot` interface here is the chain 6B named —
 * fixture -> hand-written type -> PHP resource, with no assertion at any step.
 *
 * ── THE ORGANIZATION IS IN THE PATH AND IS NOT THE SCOPE ─────────────────────────────────────────
 * The route is mounted under `organizations/{organization}` with `->scopeBindings()`, and
 * `TenantContext` re-reads the membership row from PostgreSQL on EVERY request. So the segment is a
 * ROUTING HINT: a foreign or unknown organization 404s at BINDING time, before any policy runs, and
 * `bootstrap/app.php` renders 404 as `authorization` — so the screen shows the class-mapped "You do
 * not have access to this." for a cross-org id and for an unknown one alike. The client cannot tell
 * them apart, which is the point of the deny split.
 *
 * The same `orgId` is separately a CACHE NAMESPACE in the query key, which is a different job (see
 * `useOrgKey()`), and `toRequestParams()` has a spec asserting it emits no `org`/`organization_id`:
 * Laravel derives the real organization from the session cookie and would ignore a client-supplied
 * one.
 */

export const botsPath = (orgId: string): string => `${organizationPath(orgId)}/bots`;

/**
 * THE SORTABLE SET IS READ OUT OF THE SERVER'S OWN RULES MANIFEST, NEVER TYPED OUT HERE.
 *
 * `packages/contracts/rules/IndexBotsRequest.json` is written by `php artisan kb:dump-form-rules`
 * from `IndexBotsRequest::rules()`, which closes the set with `Rule::in(...)` because `sort` reaches
 * an `ORDER BY`. A hand-copied list is a 422 one header click away the moment the endpoint's set
 * moves — and the failure would be a rejected request for a column the header still offers, which is
 * the worst possible place to discover a drift.
 *
 * The same file is already imported this way for `knownPathsFromRules`, so the mechanism is the
 * repo's rather than this feature's. `tests/unit/bot-list-config.test.ts` pins the parsed set and the
 * two bounds, so a manifest whose SHAPE changed (rather than its values) fails by name instead of
 * degrading to an empty set at render time.
 *
 * WHAT IS DELIBERATELY ABSENT FROM IT: `created_at`. `id` is a ULID whose leading 48 bits are a
 * millisecond timestamp, stored `COLLATE "C"`, so lexicographic order IS byte order IS creation
 * order — and the create migration declined the `(organization_id, created_at)` index for exactly
 * that reason. The console's "Created" column therefore sorts by `id`; see `bot-columns.tsx`.
 */
const IN_RULE_PREFIX = 'in:';

/** `in:"id","name","slug","status"` -> the four bare strings. Never throws: a manifest this cannot
 *  read yields an empty set, which `assertTableParamsConfig` then refuses loudly at the call site. */
function enumFromRule(rules: readonly string[] | undefined): readonly string[] {
  const rule = rules?.find((entry) => entry.startsWith(IN_RULE_PREFIX));
  if (rule === undefined) return [];
  return rule
    .slice(IN_RULE_PREFIX.length)
    .split(',')
    .map((value) => value.trim().replace(/^"(.*)"$/, '$1'))
    .filter((value) => value !== '');
}

/** `max:200` -> 200. `null` when the rule carries no numeric bound. */
function maxFromRule(rules: readonly string[] | undefined): number | null {
  const rule = rules?.find((entry) => entry.startsWith('max:'));
  if (rule === undefined) return null;
  const bound = Number.parseInt(rule.slice('max:'.length), 10);
  return Number.isSafeInteger(bound) ? bound : null;
}

const INDEX_BOTS_RULES = (indexBotsRules as FormRulesManifest).rules;

/**
 * The columns `GET .../bots` permits an `ORDER BY` on, in the manifest's order.
 *
 * A `TableParamsConfig` whose `defaultSort` is outside this set throws at the call site rather than
 * rendering a view that 422s, so an empty parse is loud rather than silent.
 */
export const BOT_SORTABLE_COLUMNS: readonly string[] = enumFromRule(INDEX_BOTS_RULES['sort']);

/** The endpoint's applied bounds, for the spec that compares them against `lib/table/params.ts`'s
 *  mirrors. Read here rather than restated, so "who is the authority" has one answer. */
export const BOT_MAX_PER_PAGE: number | null = maxFromRule(INDEX_BOTS_RULES['per_page']);
export const BOT_MAX_FILTER_LENGTH: number | null = maxFromRule(INDEX_BOTS_RULES['filter']);

/**
 * The ONE free-text parameter this endpoint filters by. Its wire name, verbatim: `ListQuery::rules()`
 * calls it `filter`, so the URL, the request and the query key all spell it that way and there is no
 * translation layer for a typo to hide in.
 *
 * It searches `name` and `slug` only (`EloquentBotRepository::FILTERABLE`). There is NO status filter
 * on this endpoint, and inventing one here would be worse than not having it: Laravel ignores an
 * unvalidated query parameter, so the chip would render, the URL would say `status=paused`, and the
 * list would come back unfiltered with nothing reported anywhere.
 */
export const BOT_FILTER_PARAM = 'filter';

/**
 * The view configuration, at MODULE SCOPE because `useTableParams` requires a stable identity — an
 * object literal in a render body rebuilds the params, the filter Map and every callback each render.
 *
 * `defaultSort` mirrors `IndexBotsRequest::DEFAULT_SORT` + `SortDirection::Asc`: `id` ascending, which
 * is creation order, so a page-one bookmark keeps meaning the same thing as bots are added. There is
 * deliberately no "unsorted" state — offset pagination over an unordered result set may repeat on
 * page 2 a row page 1 already showed.
 *
 * `pageSizes[0]` is the default and the one omitted from a canonical URL. 100 is the platform cap
 * exactly; `assertTableParamsConfig` refuses anything above it, because the server would clamp
 * silently and the pager would then state a range nobody was served.
 */
export const BOT_LIST_CONFIG: TableParamsConfig = {
  sortableColumns: BOT_SORTABLE_COLUMNS,
  defaultSort: { id: 'id', desc: false },
  filterNames: [BOT_FILTER_PARAM],
  pageSizes: [25, 50, MAX_PER_PAGE],
};

/**
 * `GET .../bots?page&per_page&sort&dir&filter` -> 200 `{data:{bots:[...],meta:{...}}}` | 403 | 404.
 *
 * ── `browserFetch` AND NOT `browserFetchData`, AND THAT IS NOT AN OVERSIGHT ─────────────────────
 * `readPaginatedEnvelope` reads `data.bots` and `data.meta` together, so it takes the WHOLE body; the
 * `data` unwrap happens inside it rather than twice. The type argument still names
 * `BotCollectionResource`, so the shape this expects stays linked to the drift-tested mirror while
 * the runtime guard refuses anything else.
 *
 * IT THROWS ON AN UNREADABLE ENVELOPE RATHER THAN RETURNING ZERO ROWS. `{rows: [], rowCount: 0}`
 * would render the FIRST-RUN empty state — "No bots yet" — to an administrator whose organization has
 * two hundred of them, which is indistinguishable from data loss at a glance. The thrown value is not
 * a `KbError`, so it carries no `error_class`; no envelope parsed means unknown, and unknown is
 * permanently non-retryable, which is right — a shape mismatch will not fix itself on a second try.
 *
 * `params` IS `view.requestParams` VERBATIM — the same object that forms the tail of the query key.
 * One value for both, so a page-2 response can never be cached under the page-1 question.
 *
 * `signal` is forwarded because `queryClient.cancelQueries()` is a NO-OP against a `queryFn` that
 * drops it, and cancelling in-flight reads is step 2 of both logout and the organization switch —
 * exactly when another organization's bots must not resolve.
 */
export const fetchBotPage = async (
  orgId: string,
  params: Readonly<Record<string, string>>,
  signal: AbortSignal,
): Promise<TablePage<BotResource>> => {
  const query = new URLSearchParams(params).toString();
  const body = await browserFetch<ApiEnvelope<BotCollectionResource>>({
    path: `${botsPath(orgId)}?${query}`,
    credential: await sessionCredential(),
    signal,
  });
  return readPaginatedEnvelope<BotResource>(body, 'bots');
};

// ── THE DISPLAY VOCABULARIES ────────────────────────────────────────────────────────────────────

export interface BotStatusDisplay {
  readonly kind: StatusKind;
  /** Sentence case. The WORD is the channel that survives greyscale and CVD, and it is never omitted. */
  readonly label: string;
}

/**
 * The five lifecycle states, each mapped onto the CLOSED status vocabulary of `<StatusPill>` (P8):
 * pending/queued -> slate, running -> info, ready/active -> success, degraded/partial -> warning,
 * failed/disabled -> destructive.
 *
 * ── WHY `testing` IS SLATE AND NOT INFO, WHICH IS A COMPROMISE AND IS RECORDED AS ONE ───────────
 * A bot in `testing` is being exercised before publication — the info bucket by meaning. The only
 * info-coloured `StatusKind` is `running`, whose glyph SPINS, and an endlessly spinning loader on a
 * static list row reads as "this page is stuck" rather than "this bot is being trialled". So it takes
 * the slate bucket instead and is distinguished from `draft` by its word alone. Repairing it properly
 * is one entry in `components/status-pill.tsx` — an info variant with a still glyph — which is
 * outside this task's ownership and is reported rather than reached for.
 *
 * `paused` is WARNING and not destructive: a paused bot is not answering and somebody did that on
 * purpose. `archived` takes the disabled bucket, which is the same slate the pending bucket renders;
 * the word is again what separates them.
 *
 * A `Record` keyed by the union, so a sixth status added to `BotStatus` fails to typecheck HERE
 * rather than rendering as a bare wire string on the screen.
 */
const BOT_STATUS_DISPLAY = {
  draft: { kind: 'pending', label: 'Draft' },
  testing: { kind: 'pending', label: 'Testing' },
  published: { kind: 'ready', label: 'Published' },
  paused: { kind: 'degraded', label: 'Paused' },
  archived: { kind: 'disabled', label: 'Archived' },
} as const satisfies Readonly<Record<BotStatus, BotStatusDisplay>>;

/**
 * A `Map`, and not the object above, for the LOOKUP: the key comes off the wire, and indexing a plain
 * object by a server-supplied value is the `security/detect-object-injection` sink. It also gives the
 * unknown-status case a natural answer.
 */
const BOT_STATUS_BY_NAME = new Map<string, BotStatusDisplay>(Object.entries(BOT_STATUS_DISPLAY));

/**
 * A stored status -> the pill's kind and word, or THE VALUE ITSELF in the neutral bucket when this
 * build has not heard of it. Rendering the raw value is the honest fallback — it is what the row
 * claims and what the server will act on — and inventing a label for it would be worse.
 */
export const botStatusDisplay = (status: string): BotStatusDisplay =>
  BOT_STATUS_BY_NAME.get(status) ?? { kind: 'pending', label: status };

const BOT_ACCESS_MODE_LABELS = {
  public: 'Public',
  private: 'Private',
} as const satisfies Readonly<Record<BotAccessMode, string>>;

const BOT_ACCESS_MODE_BY_NAME = new Map<string, string>(Object.entries(BOT_ACCESS_MODE_LABELS));

/**
 * Whether an ANONYMOUS end user may converse — not the origin allow-list and no substitute for one.
 * It is rendered as plain text rather than as a pill: a pill is a LIFECYCLE signal (P8), and colouring
 * an access mode would put two competing status channels in one row.
 */
export const botAccessModeLabel = (mode: string): string =>
  BOT_ACCESS_MODE_BY_NAME.get(mode) ?? mode;
