import type {
  Role,
  SourceCollectionResource,
  SourceResource,
  SourceStatus,
  SourceType,
} from '@kb/contracts';
import { uploadSchema, type OrgUploadLimits } from '@kb/contracts/forms';
import indexSourcesRules from '@kb/contracts/rules/IndexSourcesRequest.json';
import updateSourceStatusRules from '@kb/contracts/rules/UpdateSourceStatusRequest.json';

import type { StatusKind } from '@/components/status-pill';
import { SOURCE_TONE, type ToneName } from '@/components/tone';
import { organizationPath } from '@/features/providers/api';
import {
  browserFetch,
  browserFetchData,
  sessionCredential,
  type ApiEnvelope,
} from '@/lib/api/browser';
import { uploadFile, type UploadProgress } from '@/lib/api/upload';
import type { FormRulesManifest } from '@/lib/forms/known-paths';
import { readPaginatedEnvelope, type TablePage } from '@/lib/table/envelope';
import { MAX_PER_PAGE, type TableParamsConfig } from '@/lib/table/params';
import { enumFromRule, maxFromRule } from '@/lib/table/rules';

/**
 * THE WHOLE KNOWLEDGE-SOURCE TRANSPORT — the paginated list, the three lifecycle mutations
 * (disable/enable, reprocess, delete) and the one-file-per-request upload — plus the view
 * configuration the URL is parsed against and the two display vocabularies the table renders.
 *
 * REACT-FREE on purpose: nothing here imports a hook, so a spec can call a fetcher directly and a
 * render site cannot acquire a second copy of the envelope knowledge.
 *
 * ── THE ENDPOINTS EXIST NOW, AND THIS FILE WAS WRITTEN BEFORE THEY DID ──────────────────────────
 * This module opened with a section headed "THE ENDPOINT DOES NOT EXIST YET", written while
 * `POST …/sources` was a shape rather than a route. The control plane landed the whole surface —
 * `index`, `store`, `show`, `update`, `destroy`, the status transition and the reprocess submission,
 * with `SourceResource` and `SourceCollectionResource` published in the OpenAPI document and
 * `IndexSourcesRequest`/`UpdateSourceStatusRequest` dumped to `packages/contracts/rules/`. Both
 * consequences that section recorded are therefore closed, and closed in OPPOSITE directions, which
 * is why they are recorded here rather than deleted:
 *
 *   1. THE RESOURCE TYPES ARE `@kb/contracts`' NOW. `SourceResource` and `SourceCollectionResource`
 *      are mirrored in `packages/contracts/src/resources/sources.ts` and compared field for field
 *      against the generated document by `test/resource-drift.test.ts`. `uploadSourceFile` still
 *      resolves `void` — see its docblock; that is a decision about what a caller may believe, not a
 *      missing type.
 *   2. THE `StoreSourceRequest` MANIFEST LANDED AND `UPLOAD_KNOWN_PATHS` STILL DOES NOT USE IT, and
 *      that is now a ruling rather than a to-do. The argument is under `UPLOAD_KNOWN_PATHS` below.
 *
 * ── THE ORGANIZATION IS IN THE PATH, AND THAT IS NOT A TENANCY VIOLATION ────────────────────────
 * Every route under `organizations/{organization}` uses `->scopeBindings()`, and `TenantContext`
 * re-reads the membership row from PostgreSQL on EVERY request. The segment is a ROUTING HINT, never
 * a scope: Laravel derives the real organization from the session and would ignore a client-supplied
 * one. `encodeURIComponent` on a ULID is a no-op today and is what keeps the day it stops being a
 * ULID from being an injected path segment.
 *
 * The same `orgId` is separately a CACHE NAMESPACE in the query key, which is a different job
 * (`useOrgKey()`), and `toRequestParams()` has a spec asserting it emits no `org`/`organization_id`.
 */

export const sourcesPath = (orgId: string): string => `${organizationPath(orgId)}/sources`;

/**
 * `organizationPath` is `features/providers/api.ts`', imported rather than re-interpolated. This
 * line used to spell `/api/v1/organizations/${encodeURIComponent(orgId)}` itself, which was correct
 * and was a fourth copy of a prefix three other features already share; the note on `formatTimestamp`
 * in that file names three copies as the moment a duplicate becomes a move.
 */
export const sourcePath = (orgId: string, sourceId: string): string =>
  `${sourcesPath(orgId)}/${encodeURIComponent(sourceId)}`;

/** `PUT`, not `PATCH`: the body is the whole of the resource this endpoint owns. */
export const sourceStatusPath = (orgId: string, sourceId: string): string =>
  `${sourcePath(orgId, sourceId)}/status`;

export const sourceReprocessPath = (orgId: string, sourceId: string): string =>
  `${sourcePath(orgId, sourceId)}/reprocess`;

/**
 * `GET .../sources/upload-limits`. A COLLECTION-level route, not a member one — the ceilings belong
 * to the organization, so there is no `{source}` to hang them off and `upload-limits` can never
 * collide with a ULID in the `{source}` slot.
 */
export const sourceUploadLimitsPath = (orgId: string): string =>
  `${sourcesPath(orgId)}/upload-limits`;

// ── THE VIEW CONFIGURATION, READ OUT OF THE SERVER'S OWN RULES ──────────────────────────────────

const INDEX_SOURCES_RULES = (indexSourcesRules as FormRulesManifest).rules;

/**
 * The columns `GET .../sources` permits an `ORDER BY` on, in the manifest's order — `id`, `name`,
 * `type`, `status` at the time of writing, and WHATEVER THE MANIFEST SAYS TOMORROW.
 *
 * NOT TYPED OUT, EVER. `IndexSourcesRequest::rules()` closes the set with `Rule::in(...)` because
 * `sort` reaches an `ORDER BY`, and `php artisan kb:dump-form-rules` writes it to
 * `packages/contracts/rules/IndexSourcesRequest.json`. A hand-copied list is a 422 one header click
 * away the moment the endpoint's set moves, on a request the user made by clicking a control we
 * drew. The parser is `lib/table/rules.ts`, shared with the bot list.
 *
 * A column the server cannot sort is `enableSorting: false` in `source-columns.tsx`, computed from
 * THIS array — so a sortable column added to the FormRequest tomorrow becomes clickable here as soon
 * as the manifest is re-dumped, with no edit to this app, and one removed stops being offered the
 * same way. `tests/unit/source-list.test.ts` pins the parse so a manifest whose SHAPE changed fails
 * by name instead of degrading to an empty set at render time.
 *
 * WHAT IS DELIBERATELY ABSENT: `created_at`. `id` is a ULID whose leading 48 bits are a millisecond
 * timestamp stored `COLLATE "C"`, so lexicographic order IS byte order IS creation order — the same
 * property the bot list's "Created" column sorts by, for the same reason.
 */
export const SOURCE_SORTABLE_COLUMNS: readonly string[] = enumFromRule(INDEX_SOURCES_RULES['sort']);

/** The endpoint's applied bounds, for the spec that compares them against `lib/table/params.ts`'s
 *  mirrors. Read here rather than restated, so "who is the authority" has one answer. */
export const SOURCE_MAX_PER_PAGE: number | null = maxFromRule(INDEX_SOURCES_RULES['per_page']);
export const SOURCE_MAX_FILTER_LENGTH: number | null = maxFromRule(INDEX_SOURCES_RULES['filter']);

/**
 * The ONE free-text parameter this endpoint filters by, spelled exactly as `ListQuery::rules()`
 * spells it — so the URL, the request and the query key all say `filter` and there is no translation
 * layer for a typo to hide in.
 *
 * THERE IS NO STATUS FILTER ON THIS ENDPOINT, and inventing one here would be worse than not having
 * it: Laravel ignores an unvalidated query parameter, so a `?status=failed` chip would render, the
 * URL would say so, and the list would come back unfiltered with nothing reported anywhere. A
 * fifteen-state lifecycle is exactly where an operator would want that filter, which is why the
 * absence is written down rather than left to be rediscovered — it is reported to the control plane,
 * not worked around here.
 */
export const SOURCE_FILTER_PARAM = 'filter';

/**
 * The view configuration, at MODULE SCOPE because `useTableParams` requires a stable identity — an
 * object literal in a render body rebuilds the params, the filter Map and every callback each render.
 *
 * EVERY FIELD BUT ONE IS DERIVED. `sortableColumns` is the manifest's; `pageSizes` is bounded by
 * `MAX_PER_PAGE`, which `assertTableParamsConfig` refuses to exceed because the server would clamp
 * silently and the pager would then state a range nobody was served. `defaultSort` is the one
 * declared value, and it mirrors `IndexSourcesRequest`'s own default — `id` ascending, which is
 * creation order, so a page-one bookmark keeps meaning the same thing as documents are added. There
 * is deliberately no "unsorted" state: offset pagination over an unordered result set may repeat on
 * page 2 a row page 1 already showed.
 */
export const SOURCE_LIST_CONFIG: TableParamsConfig = {
  sortableColumns: SOURCE_SORTABLE_COLUMNS,
  defaultSort: { id: 'id', desc: false },
  filterNames: [SOURCE_FILTER_PARAM],
  pageSizes: [25, 50, MAX_PER_PAGE],
};

/**
 * `GET .../sources?page&per_page&sort&dir&filter` -> 200 `{data:{sources:[…],meta:{…}}}` | 403 | 404.
 *
 * `browserFetch` AND NOT `browserFetchData`, because `readPaginatedEnvelope` reads `data.sources` and
 * `data.meta` together and therefore takes the WHOLE body; the `data` unwrap happens inside it rather
 * than twice. The type argument still names `SourceCollectionResource`, so the shape this expects
 * stays linked to the drift-tested mirror while the runtime guard refuses anything else.
 *
 * IT THROWS ON AN UNREADABLE ENVELOPE RATHER THAN RETURNING ZERO ROWS. `{rows: [], rowCount: 0}`
 * would render the FIRST-RUN empty state — "No sources yet" — to an administrator whose organization
 * has two hundred documents, which is indistinguishable from data loss at a glance. The thrown value
 * is not a `KbError`, so it carries no `error_class`; no envelope parsed means unknown, and unknown
 * is permanently non-retryable, which is right — a shape mismatch will not fix itself on a retry.
 *
 * EVERY LIFECYCLE STATE COMES BACK, including rows whose purge is in flight. "Where did that document
 * go" is asked precisely while a deletion is running, and hiding the row would make the question
 * unanswerable from the console while the record still existed.
 *
 * `params` IS `view.requestParams` VERBATIM — the same object that forms the tail of the query key,
 * so a page-2 response can never be cached under the page-1 question. `signal` is forwarded because
 * `queryClient.cancelQueries()` is a NO-OP against a `queryFn` that drops it, and cancelling
 * in-flight reads is step 2 of both logout and the organization switch.
 */
export const fetchSourcePage = async (
  orgId: string,
  params: Readonly<Record<string, string>>,
  signal: AbortSignal,
): Promise<TablePage<SourceResource>> => {
  const query = new URLSearchParams(params).toString();
  const body = await browserFetch<ApiEnvelope<SourceCollectionResource>>({
    path: `${sourcesPath(orgId)}?${query}`,
    credential: await sessionCredential(),
    signal,
  });
  return readPaginatedEnvelope<SourceResource>(body, 'sources');
};

// ── THE DISPLAY VOCABULARIES ────────────────────────────────────────────────────────────────────

export interface SourceStatusDisplay {
  readonly kind: StatusKind;
  /** Sentence case. The WORD is the channel that survives greyscale and CVD, and it is never omitted. */
  readonly label: string;
}

/**
 * THE FIFTEEN LIFECYCLE STATES, each mapped onto the CLOSED status vocabulary of `<StatusPill>` (P8).
 *
 * Three assignments are decisions rather than transcription:
 *
 *   `ready_with_warnings` IS `degraded` — ITS OWN TONE, not a green with an asterisk. The two ready
 *   states are identical for retrieval and are two values precisely because collapsing them loses the
 *   only signal that says "this document parsed badly and published anyway" (the contract mirror says
 *   so in as many words). Painting it `ready` would collapse them in the UI after the API refused to
 *   collapse them on the wire, and the operator who needs to re-upload a bad scan would never learn
 *   of it. Painting it `failed` would be the opposite lie: the source is answering.
 *
 *   THE SIX PIPELINE STAGES ARE `running`, whose glyph SPINS, because each of them genuinely is work
 *   in flight and the row will move on its own within seconds to minutes. `queued` is NOT one of them
 *   — the server excludes it from `status_is_processing` deliberately (a source queued for an hour is
 *   a scheduling problem, one parsing for an hour is a document problem), so it takes the still,
 *   neutral `pending` bucket. It is still POLLED; see `sourcePollInterval`.
 *
 *   `deleting` IS `running` AND NOT `failed`. A purge in flight is the platform working, not the
 *   source being broken, and the row is on screen precisely so the operator can watch it finish.
 *   `deleted` then takes the inert `disabled` bucket beside `archived`.
 *
 * A `Record` keyed by the union, so a sixteenth state added to `SourceStatus` fails to typecheck HERE
 * rather than rendering as a bare wire string on the screen.
 */
const SOURCE_STATUS_DISPLAY = {
  draft: { kind: 'pending', label: 'Draft' },
  queued: { kind: 'pending', label: 'Queued' },
  fetching: { kind: 'running', label: 'Fetching' },
  parsing: { kind: 'running', label: 'Parsing' },
  normalizing: { kind: 'running', label: 'Normalizing' },
  chunking: { kind: 'running', label: 'Chunking' },
  embedding: { kind: 'running', label: 'Embedding' },
  indexing: { kind: 'running', label: 'Indexing' },
  ready: { kind: 'ready', label: 'Ready' },
  ready_with_warnings: { kind: 'degraded', label: 'Ready with warnings' },
  failed: { kind: 'failed', label: 'Failed' },
  disabled: { kind: 'disabled', label: 'Disabled' },
  deleting: { kind: 'running', label: 'Deleting' },
  deleted: { kind: 'disabled', label: 'Deleted' },
  archived: { kind: 'disabled', label: 'Archived' },
} as const satisfies Readonly<Record<SourceStatus, SourceStatusDisplay>>;

/**
 * A `Map`, and not the object above, for the LOOKUP: the key comes off the wire, and indexing a plain
 * object by a server-supplied value is the `security/detect-object-injection` sink. It also gives the
 * unknown-status case a natural answer.
 */
const SOURCE_STATUS_BY_NAME = new Map<string, SourceStatusDisplay>(
  Object.entries(SOURCE_STATUS_DISPLAY),
);

/**
 * A stored status -> the pill's kind and word, or THE VALUE ITSELF in the neutral bucket when this
 * build has not heard of it.
 *
 * THE FALLBACK IS THE POINT, not politeness. The server's lifecycle is fifteen values today and this
 * console is deployed separately from it — a sixteenth state reaches a browser running last week's
 * bundle, and the two failure modes without a fallback are a crash on an object lookup or a blank
 * cell where the status should be. Rendering the raw value in the neutral bucket is the honest
 * answer: it is what the row claims and what the server will act on, and inventing a label for it
 * would be worse than showing the wire word.
 */
export const sourceStatusDisplay = (status: string): SourceStatusDisplay =>
  SOURCE_STATUS_BY_NAME.get(status) ?? { kind: 'pending', label: status };

export interface SourceTypeDisplay {
  readonly label: string;
  /** A DECORATIVE, CATEGORICAL tint (P7). It never means good, bad or urgent — that is the pill's job. */
  readonly tone: ToneName;
}

/**
 * WHAT KIND OF THING A SOURCE IS — three values, and deliberately not what kind of FILE it is.
 *
 * ── THE TONE COMES FROM `SOURCE_TONE`, WHICH IS KEYED BY A DIFFERENT VOCABULARY ─────────────────
 * `components/tone.ts` already maps the product's categorical keys — document, spreadsheet,
 * presentation, website, image — onto the closed set of six tints, and that mapping is not restated
 * here. But those keys are FILE FORMATS, and the wire's `type` is the coarser three-value union the
 * API publishes: a PDF, a DOCX, a spreadsheet and a slide deck are all `file`, because the parser
 * decides from the sniffed MIME type rather than from a column somebody typed.
 *
 * So two of the three have exact counterparts and take them: `file` -> document, `url` -> website.
 * `text` — a pasted body — has none, and takes `slate`, the neutral member of the SAME closed set,
 * rather than doubling document's tint. Giving two of three values one colour would leave the column
 * tinted and non-distinguishing, which is decoration that reads as information.
 *
 * A tone is assigned from a STABLE KEY and never from a position: mapping by array index into a
 * sorted list means re-sorting the table recolours every row, which destroys the only thing the tint
 * was doing.
 */
const SOURCE_TYPE_DISPLAY = {
  file: { label: 'File', tone: SOURCE_TONE.document },
  url: { label: 'Website', tone: SOURCE_TONE.website },
  text: { label: 'Text', tone: 'slate' },
} as const satisfies Readonly<Record<SourceType, SourceTypeDisplay>>;

const SOURCE_TYPE_BY_NAME = new Map<string, SourceTypeDisplay>(Object.entries(SOURCE_TYPE_DISPLAY));

/**
 * A stored type -> its word and its tint, with the same fallback discipline as the status map: an
 * unknown value renders as itself, in the neutral tone, rather than crashing or rendering blank.
 */
export const sourceTypeDisplay = (type: string): SourceTypeDisplay =>
  SOURCE_TYPE_BY_NAME.get(type) ?? { label: type, tone: 'slate' };

// ── WHAT THIS VIEWER MAY BE OFFERED ─────────────────────────────────────────────────────────────

/**
 * AFFORDANCES, NEVER AUTHORIZATION. Laravel answers 403 whatever these return, every path here
 * handles that class, and a role that changed under a cached session shows up as that 403 rather than
 * as a silently missing control. What they buy is a screen that does not offer a control whose every
 * use would be refused, and — for the read side — a forbidden state that names the actual problem.
 *
 * BOTH ARE POSITIVE TESTS OVER A LISTED SET, so a fifth role added to `Role` defaults to holding
 * NEITHER. That is the direction a boolean expression gets right and an exhaustive `switch` would
 * turn into a typecheck failure demanding the decision be made here rather than in
 * `App\Enums\OrgRole::grants()`, which is the authority.
 *
 * ── THESE ARE THE ONE PLACE IN THE CONSOLE WHERE `analyst` IS GENUINELY LOCKED OUT ──────────────
 * All four roles hold `bots.view`, which is why the bot list names no required role. `sources.view`
 * is different: owner, admin and knowledge manager hold it and an ANALYST DOES NOT — and the sidebar
 * offers `/sources` to everybody, so an analyst reaching a 403 here is an ordinary Tuesday rather
 * than an exotic case. `sources.manage` happens to be granted to the same three roles today, and it
 * is a separate predicate because the two grants are separate on the server and may diverge.
 */
export const canViewSources = (role: Role | null): boolean =>
  role === 'owner' || role === 'admin' || role === 'knowledge_manager';

export const canManageSources = (role: Role | null): boolean =>
  role === 'owner' || role === 'admin' || role === 'knowledge_manager';

/**
 * The role to NAME in the forbidden state — the least-privileged role that can read this list, in the
 * words the console uses for it elsewhere (`roleLabel`, lower-cased for mid-sentence use).
 */
export const SOURCE_VIEW_ROLE = 'knowledge manager';

/**
 * The role to NAME in the forbidden state on the UPLOAD screen. The same words as `SOURCE_VIEW_ROLE`
 * today and a SEPARATE constant for the same reason `canManageSources` is a separate predicate: the
 * two grants are separate on the server (`sources.view` and `sources.manage` in `OrgRole::grants()`)
 * and may diverge. One constant serving both would make a divergence render a role that cannot do
 * the thing the sentence is about, which is worse than no sentence.
 */
export const SOURCE_MANAGE_ROLE = 'knowledge manager';

// ── POLLING ─────────────────────────────────────────────────────────────────────────────────────

/**
 * Five seconds, and the number is sized against its COST rather than against how fast it feels.
 *
 * Twenty admins with one tab each is 4 requests per second of pure status traffic, and every one of
 * them pays a session lookup, a membership re-check and a policy evaluation in Laravel against the
 * `throttle:admin` bucket. Anything faster buys nothing, because ingestion stages are seconds to
 * minutes. `refetchIntervalInBackground` is left at its default `false`, so a backgrounded tab stops
 * asking entirely.
 */
export const SOURCE_POLL_MS = 5_000;

/**
 * THE STATES THIS SCREEN POLLS THROUGH THAT `status_is_processing` DOES NOT COVER — a POSITIVE,
 * CLOSED set of exactly two, and the reason each is in it.
 *
 *   `queued`   accepted and not yet started. The server excludes it from `status_is_processing` on
 *              purpose, and the exclusion is right for what that flag means — but the row moves to
 *              `fetching` with nobody touching it, so a screen that polled on the flag alone would
 *              show a queued document as static until the operator reloaded.
 *   `deleting` phase 1 is done and the background purge is what moves the row to `deleted` and
 *              stamps `purged_at`. §8.17 requires an administrator to SEE deletion completion, and
 *              this is the only path by which they do.
 *
 * A POSITIVE MEMBERSHIP TEST, never "not ready and not failed". The contract mirror is explicit:
 * do not write a lifecycle predicate out of the union, because a check spelled as a negation admits
 * every state added later. The direction this fails in is stated under `sourcePollInterval`.
 */
const POLLED_BEYOND_PROCESSING: ReadonlySet<string> = new Set(['queued', 'deleting']);

/**
 * Whether a row will move on its own. The `status_is_processing` FLAG is read rather than derived
 * from `status` — it is published precisely so a client does not re-derive it, and a re-derivation
 * would be a sixteenth place to update when a stage is added.
 */
export const sourceIsSettling = (source: SourceResource): boolean =>
  source.status_is_processing || POLLED_BEYOND_PROCESSING.has(source.status);

/**
 * THE STOP CONDITION, IN FUNCTION FORM. `refetchInterval` as a bare number NEVER STOPS: a tab left
 * open on this route polls for hours, and twenty of them start rejecting real requests at the admin
 * limiter. So the interval is a function of the page in hand, and it returns `false` — not `0`, which
 * TanStack Query treats as "as fast as possible" — the moment no row on this page is going anywhere.
 *
 * IT IS THE PAGE, NOT THE COLLECTION. A source ingesting on page 3 does not keep page 1 polling, and
 * that is correct: this screen renders one page and can only observe one page.
 *
 * `undefined` rows (first load, or an error with nothing cached) yield `false` — there is nothing to
 * watch, and the query is either in flight already or has failed, where the retry policy owns what
 * happens next.
 *
 * ── WHICH WAY IT FAILS FOR A STATE THIS BUILD HAS NEVER HEARD OF ────────────────────────────────
 * A sixteenth lifecycle value is SETTLED here: unknown to `status_is_processing` (which the server
 * still answers, so this is really only the `POLLED_BEYOND_PROCESSING` half) and absent from a
 * two-member set. The row then sits until the operator refreshes — visible, recoverable, and cheap.
 * The alternative direction, "poll anything I do not recognise", turns an unmapped state into the
 * indefinite background traffic this whole function exists to prevent, and nothing would report it.
 */
export const sourcePollInterval = (
  rows: readonly SourceResource[] | undefined,
): number | false => {
  if (rows === undefined) return false;
  return rows.some(sourceIsSettling) ? SOURCE_POLL_MS : false;
};

// ── THE LIFECYCLE MUTATIONS ─────────────────────────────────────────────────────────────────────

const UPDATE_SOURCE_STATUS_RULES = (updateSourceStatusRules as FormRulesManifest).rules;

/**
 * The two values `PUT .../sources/{source}/status` accepts, read out of its dumped `Rule::in(...)`.
 *
 * ── THE SET IS DERIVED; WHICH MEMBER MEANS "ENABLE" IS NOT DERIVABLE ────────────────────────────
 * A rule list is a set of legal strings and carries no semantics, so the two constants below are
 * declared and then PINNED against this parse by `tests/unit/source-list.test.ts` — a server-side
 * change to the enum fails a test by name rather than 422ing a button in production. `satisfies
 * SourceStatus` additionally ties each to the contract's own lifecycle union, so a rename that
 * reached `@kb/contracts` fails the typecheck here.
 */
export const SOURCE_STATUS_TARGETS: readonly string[] = enumFromRule(
  UPDATE_SOURCE_STATUS_RULES['status'],
);

/** Withdraw the source from retrieval. One column; every vector is retained. */
export const SOURCE_DISABLE_TARGET = 'disabled' satisfies SourceStatus;

/**
 * Put it back. `ready` IS THE ONLY SPELLING OF "ENABLE" ON THIS WIRE, and the client does not choose
 * between the two ready flavours: `SourceService::readyFlavourFor()` reads the source's live versions
 * and answers `ready` or `ready_with_warnings` itself, because "did this document parse cleanly" is a
 * fact about the content rather than an option. The 200 body says which one it chose.
 */
export const SOURCE_ENABLE_TARGET = 'ready' satisfies SourceStatus;

/**
 * `PUT .../sources/{source}/status` -> 200 `{data: …}` | 403 | 404 | 409 | 422.
 *
 * ── FOUR REFUSALS, AND THE SCREEN MUST NOT PRE-JUDGE ANY OF THEM ────────────────────────────────
 * 409 for a suspended organization (its `message` is genuine end-user copy and is rendered verbatim
 * through `actionableConflictMessage`); 422 when the transition table has no edge for the move, which
 * covers a source that is mid-run, deleting, deleted, or already in the state asked for — a
 * transition is not a state assertion, and re-asserting the current status would write an audit row
 * describing a change that did not happen; 403/404 the usual `authorization` pair, byte-identical on
 * the wire.
 *
 * None of that is computable from a cached row, so the row action offers the move and lets the server
 * refuse. What the CLIENT decides is only WHICH VERB TO OFFER, and it reads
 * `status_permits_retrieval` for it rather than comparing `status` itself.
 *
 * NO OPTIMISTIC UPDATE, and no cache write from the response either: a lifecycle transition is
 * exactly what `tanstack-query-table` forbids optimism on, because the client cannot compute which
 * ready flavour an enable lands in. Invalidate and re-read.
 */
export const setSourceStatus = async (
  orgId: string,
  sourceId: string,
  status: typeof SOURCE_DISABLE_TARGET | typeof SOURCE_ENABLE_TARGET,
): Promise<SourceResource> =>
  browserFetchData<SourceResource>({
    path: sourceStatusPath(orgId, sourceId),
    method: 'PUT',
    body: { status },
    credential: await sessionCredential(),
  });

/**
 * `POST .../sources/{source}/reprocess` -> 202 `{data: …}` | 403 | 404 | 409 | 422.
 *
 * 202 RATHER THAN 200 BECAUSE NOTHING HAS BEEN REPROCESSED YET: the source moves to `queued`, the
 * pipeline reports progress onto `status`, and THE PREVIOUS VERSION KEEPS SERVING every query until
 * the new one is indexed AND verified. That is what the copy on the button has to mean.
 *
 * NO `Idempotency-Key`, AND THAT IS THE ENDPOINT'S OWN RULE RATHER THAN AN OMISSION. Every call mints
 * a new force nonce, which is the whole point — a resubmission of unchanged content with an
 * idempotency header would return the earlier response, which is precisely the dedupe the nonce
 * exists to defeat. Two presses therefore cost two runs, so the control is disabled while pending;
 * the queue-side `ShouldBeUniqueUntilProcessing` lock collapses a double-click that gets past it.
 */
export const reprocessSource = async (
  orgId: string,
  sourceId: string,
): Promise<SourceResource> =>
  browserFetchData<SourceResource>({
    path: sourceReprocessPath(orgId, sourceId),
    method: 'POST',
    credential: await sessionCredential(),
  });

/**
 * `DELETE .../sources/{source}` -> 200 `{data: …}` | 403 | 404 | 409 | 422.
 *
 * ── PHASE 1 OF TWO, AND THE RESPONSE IS THE SOURCE RATHER THAN AN ACKNOWLEDGEMENT ───────────────
 * The row SURVIVES: `deleted_at` is set, `status` becomes `deleting`, `purged_at` is still null
 * because nothing has been PROVEN removed. The exclusion from retrieval is immediate — one column,
 * effective on the next query, no job has to succeed first — and the purge that follows (vectors by
 * stable identifier, objects, the four Valkey families, and the verification of each) is dispatched
 * by NOTHING on this request. That is deliberate on the server: a purge dispatched from the delete
 * path would be a second implementation of an ordering that has to be right once.
 *
 * The dialog's copy is written against exactly that and must stay true to it: this call does not
 * remove anything, it stops a source answering and starts a removal somebody else finishes.
 *
 * A SECOND DELETE IS A 422, not a 200 — `deleting` has one legal edge and it is not to itself.
 * Never optimistic: the browser cannot know a purge succeeded, and §8.17 requires an administrator to
 * SEE completion, which a cache entry cannot prove.
 */
export const deleteSource = async (orgId: string, sourceId: string): Promise<SourceResource> =>
  browserFetchData<SourceResource>({
    path: sourcePath(orgId, sourceId),
    method: 'DELETE',
    credential: await sessionCredential(),
  });

// ── THE UPLOAD TRANSPORT ────────────────────────────────────────────────────────────────────────

/**
 * `GET .../sources/upload-limits` -> 200 `{data:{max_bytes, allowed_mime, max_batch}}` | 403 | 404.
 *
 * ── THE UPLOAD SCREEN CANNOT BE RENDERED WITHOUT THIS, AND THAT IS THE POINT ────────────────────
 * §8.10 makes the per-file ceiling and the accepted media types PER-ORGANIZATION, so there is no
 * byte constant and no MIME constant anywhere in this app: `uploadSchema` is a FACTORY over this DTO
 * and the picker's `accept=` is built from `allowed_mime`. A screen that rendered a dropzone before
 * this resolved would be enforcing SOME organization's limits — whichever one a fallback was copied
 * from — so the form is mounted only once this query has data, and the loading state is a skeleton
 * rather than a dropzone with a guessed cap.
 *
 * THE ANSWER IS ORGANIZATION-SCOPED, so it is cached under an ORG-NAMESPACED key and never on the
 * Next server: every Next cache is keyed by URL or by arguments and `/sources/upload` is one URL for
 * every organization an administrator belongs to. It inherits the client's 30 s `staleTime` — these
 * are deployment configuration and move on the order of a config change, not of a request — and an
 * organization switch REPLACES the QueryClient outright (`useResetQueryClient`), so a stale entry
 * cannot outlive the tenant it was fetched for even for the length of one render.
 *
 * `allowed_mime` IS NOT THE WHOLE ADMISSION RULE. The intake admits a part only if the SNIFFED type
 * is on this list AND the filename's final extension is on a SECOND allow-list the server does not
 * publish. The two sets are not in bijection — `.md` and `.csv` both sniff as `text/plain` — so a
 * picker built from this list OVER-ACCEPTS, and the refusal arrives as a 422 keyed on the part. The
 * screen renders it there; it does not guess the extension list, and it does not describe the
 * picker's filter to the user as what will be accepted (`OrgUploadLimits` in `@kb/contracts` carries
 * the full statement).
 */
export const fetchUploadLimits = async (
  orgId: string,
  signal: AbortSignal,
): Promise<OrgUploadLimits> =>
  browserFetchData<OrgUploadLimits>({
    path: sourceUploadLimitsPath(orgId),
    credential: await sessionCredential(),
    // Forwarded because `queryClient.cancelQueries()` is a no-op against a queryFn that drops it,
    // and cancelling in-flight reads is step 2 of both logout and the organization switch.
    signal,
  });

/**
 * THE MULTIPART PART NAME, AND IT IS INDEXED EVEN THOUGH EACH REQUEST CARRIES ONE FILE.
 *
 * `files[0]`, not `file`. One file per request is a CLIENT decision — it is what makes per-file
 * progress and per-file failure natural instead of something a caller has to demultiplex out of one
 * 422 — and the server should not have to know about it. Sending the array shape means the
 * FormRequest declares `files` and `files.*` whether it receives one part or five, and its 422 comes
 * back keyed `files.0`: a path `applyServerErrors` folds to `files.*`, matches against the form's
 * own vocabulary, and writes onto an RHF array path.
 *
 * The index on the WIRE is always 0, because the batch on the wire is always one file. The index in
 * FORM STATE is the row the user is looking at. `reindexFileErrors` below is the one line that
 * connects them, and it is the whole reason a per-file rejection lands on its own row rather than
 * on row 0 five times.
 */
const FILE_PART = 'files[0]';

export interface UploadOneOptions {
  /** Wired to `xhr.abort()` — a per-file Cancel, or the batch's controller aborting all of them. */
  readonly signal: AbortSignal;
  readonly on_progress: (progress: UploadProgress) => void;
  /** Present only once the endpoint honours one; absent means this must never be retried. */
  readonly idempotency_key?: string;
}

/**
 * `POST …/sources` (multipart) -> 201 `{data: …}` | 422 | 403 | 413 | 429.
 *
 * RESOLVES `void` EVEN THOUGH `SourceResource` NOW EXISTS, and that is a decision about what a caller
 * may believe rather than a missing type. The row a screen shows after an upload comes from re-reading
 * the list — which is the server's answer, arrives with the lifecycle state the pipeline has actually
 * reached, and is the only shape that can report a source that was accepted and then failed to parse.
 * A caller handed the 201 body would be holding a `draft`/`queued` snapshot and would be tempted to
 * render it as a row, which is the optimistic write `tanstack-query-table` forbids on this path.
 * `uploadFile<T>` is generic, so the day a caller genuinely needs the created id, the type is there.
 *
 * Rejects with the `KbError` from `@kb/contracts`, or with the signal's `AbortError` when the user
 * cancelled (`lib/api/upload.ts`).
 *
 * NO `Idempotency-Key` IS SENT BY DEFAULT, so this must never be retried by anything — including a
 * double-click, which is what the disabled Upload button is for. A replayed create stores the file
 * twice under two source ids, and the second one is invisible until somebody wonders why the index
 * answers the same passage twice. `lib/query/client.ts` is not in this path at all: the upload does
 * not go through a mutation, so there is no retry policy to inherit and no cache to write.
 */
export const uploadSourceFile = async (
  orgId: string,
  file: File,
  options: UploadOneOptions,
): Promise<void> => {
  await uploadFile<unknown>({
    path: sourcesPath(orgId),
    method: 'POST',
    file,
    field: FILE_PART,
    // NO scalar fields. `fields` is an explicit allow-list a caller types out (lib/api/upload.ts)
    // and this caller has nothing to put in it: the organization is the session's, the source kind
    // is the server's to sniff, and an ownership column here would be a tenant key a caller can set.
    credential: await sessionCredential(),
    signal: options.signal,
    on_progress: options.on_progress,
    ...(options.idempotency_key === undefined
      ? {}
      : { idempotency_key: options.idempotency_key }),
  });
};

/**
 * The paths this form RENDERS, which is what `knownPaths` means — a DIFFERENT set from "the paths
 * the FormRequest validates" (`lib/forms/known-paths.ts`).
 *
 * ── `StoreSourceRequest.json` HAS LANDED, AND THIS DELIBERATELY DOES NOT USE IT ─────────────────
 * This docblock used to end "when `StoreSourceRequest.json` lands, this becomes
 * `knownPathsFromRules(manifest)`". It has landed. DOING THAT CONVERSION WOULD BE A BUG, and the
 * measurement is why: the manifest describes a THREE-ARM body discriminated by `type` — `file`, `url`
 * and `text` — and yields eleven paths (`name`, `type`, `description`, `tags`, `tags.*`, `content`,
 * `origin_url`, `effective_at`, `expires_at`, `files`, `files.*`). THE FILE PICKER RENDERS TWO OF
 * THEM. Deriving from the manifest would register nine paths against controls that do not exist, and
 * `applyServerErrors` would then route a 422 on `name` or `origin_url` through `setError` onto a
 * field displayed nowhere — the failure the whole `knownPaths` idea exists to prevent, which a user
 * experiences as clicking Save and nothing happening, repeatedly. An orphan key reaching
 * `root.serverError` is visible; a key routed to a phantom control is not.
 *
 * `packages/contracts/src/forms/upload.ts` records the same measurement from the other side and is
 * why `StoreSourceRequest` is `NO_CLIENT_FORM` in `test/form-drift.test.ts`: eleven validated paths
 * against this schema's one object, a per-file cap the FormRequest states in KILOBYTES against a
 * per-organization cap this factory takes in BYTES, and a `files.*` rule (`file`) no JSON probe can
 * synthesize.
 *
 * WHAT WOULD HAVE TO CHANGE FIRST — and this note is inherited by the create screen, not discharged
 * by it: a form that RENDERS all three arms (name, type, tags, the retrieval window, and per-arm
 * `content` / `origin_url` / `files`) may derive its known paths from the manifest, because at that
 * point the two sets finally describe the same thing. Until such a form exists, the set is the
 * schema's object shape, subtracted from — never a hand-typed replacement for the derivation, which
 * `lib/forms/known-paths.ts` forbids in as many words.
 *
 * `files.*` IS THE ENTRY THAT MATTERS. Laravel keys array errors positionally (`files.0`) while a
 * rule set keys them with a wildcard (`files.*`), and `applyServerErrors` folds `.\d+` to `.*` for
 * the MEMBERSHIP TEST ONLY — the path it hands `setError` stays positional, because that is the row
 * the user is looking at. Without `files.*` here, every per-file message is an orphan and lands in
 * one batch banner.
 *
 * The limits are ARBITRARY: only the object's key set is read, and `.max()`/`.mime()` are
 * refinements that add no key. Passing a real `OrgUploadLimits` would suggest the vocabulary depends
 * on the organization, and it does not.
 */
const SHAPE_PROBE: OrgUploadLimits = { max_bytes: 1, allowed_mime: [], max_batch: 1 };

export const UPLOAD_KNOWN_PATHS: readonly string[] = Object.keys(
  uploadSchema(SHAPE_PROBE).shape,
).flatMap((key) => [key, `${key}.*`]);

/**
 * A per-file 422's `errors` map, re-keyed from the WIRE's index onto the ROW's index.
 *
 * Every request sends its file as `files[0]` (see `FILE_PART`), so every per-file rejection comes
 * back keyed `files.0` regardless of which row it belongs to. Handing that straight to
 * `applyServerErrors` writes five different files' messages onto row 0, one overwriting the next,
 * while the four rows that actually failed stay clean — a batch where the user is told about one
 * problem, fixes it, and is told about the same kind of problem again.
 *
 * Only the numeric segment moves. A key with no index (`files`, or a rule on some other field the
 * server validated) is passed through untouched: `files` is a real path this form renders (the
 * batch cap and the "at least one file" rule live there) and anything else is an orphan that
 * `applyServerErrors` routes to `root.serverError`, which is correct — a message the form cannot
 * display anywhere is the one failure mode that reads as "clicking Save does nothing".
 */
export function reindexFileErrors(
  errors: Readonly<Record<string, readonly string[]>>,
  index: number,
): Record<string, readonly string[]> {
  const reindexed: Record<string, readonly string[]> = {};

  for (const [key, messages] of Object.entries(errors)) {
    // `files.0.something` keeps its tail, which is why the replacement is anchored on the leading
    // segment pair rather than on the whole key: a nested per-file rule (`files.0.name`) is a shape
    // Laravel can emit and dropping its tail would produce a path no control is registered under.
    reindexed[key.replace(/^files\.\d+/, `files.${index}`)] = messages;
  }

  return reindexed;
}
