import type {
  BotAccessMode,
  BotCollectionResource,
  BotResource,
  BotStatus,
  Role,
} from '@kb/contracts';
import {
  botFormDefaults,
  type BotCreateOut,
  type BotFormSource,
  type BotSettingsIn,
  type BotSettingsOut,
  type BotStatusTransitionOut,
} from '@kb/contracts/forms';
import indexBotsRules from '@kb/contracts/rules/IndexBotsRequest.json';
import storeBotRules from '@kb/contracts/rules/StoreBotRequest.json';
import updateBotRules from '@kb/contracts/rules/UpdateBotRequest.json';
import updateBotStatusRules from '@kb/contracts/rules/UpdateBotStatusRequest.json';

import type { StatusKind } from '@/components/status-pill';
import { organizationPath } from '@/features/providers/api';
import {
  browserFetch,
  browserFetchData,
  sessionCredential,
  type ApiEnvelope,
} from '@/lib/api/browser';
import { knownPathsFromRules, type FormRulesManifest } from '@/lib/forms/known-paths';
import { readPaginatedEnvelope, type TablePage } from '@/lib/table/envelope';
import { MAX_PER_PAGE, type TableParamsConfig } from '@/lib/table/params';
import { enumFromRule, maxFromRule } from '@/lib/table/rules';

/**
 * THE WHOLE BOT TRANSPORT — the list, the detail, the create and the PATCH — plus the view
 * configuration the URL is parsed against, the two display vocabularies the table renders, and the
 * FIELD PARTITION the editor's three tabs are built from. REACT-FREE on purpose: nothing here imports
 * a hook, so a spec can call a fetcher directly and a render site cannot accidentally acquire a second
 * copy of the envelope knowledge.
 *
 * ── IT IS ALSO THE EDITOR'S PUBLISHED SURFACE, AND THAT MAKES IT READ-ONLY TO THREE AGENTS ───────
 * `bot-identity-panel.tsx`, `bot-model-panel.tsx` and `bot-publishing-panel.tsx` are written
 * independently and may not edit this file or `bot-editor-screen.tsx`. Everything they need — the
 * paths, `updateBot`, the three field tuples, `botPanelDefaults`, `botPanelKnownPaths` — is exported
 * here so that "which fields are mine" and "how do I save" have one answer each rather than three.
 * Adding a fourth panel means adding a fourth tuple HERE, and the partition test will say so.
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
 * repo's rather than this feature's. `tests/unit/bot-list.test.ts` pins the parsed set and the
 * two bounds, so a manifest whose SHAPE changed (rather than its values) fails by name instead of
 * degrading to an empty set at render time.
 *
 * WHAT IS DELIBERATELY ABSENT FROM IT: `created_at`. `id` is a ULID whose leading 48 bits are a
 * millisecond timestamp, stored `COLLATE "C"`, so lexicographic order IS byte order IS creation
 * order — and the create migration declined the `(organization_id, created_at)` index for exactly
 * that reason. The console's "Created" column therefore sorts by `id`; see `bot-columns.tsx`.
 */
/**
 * ── THE TWO PARSERS MOVED OUT, AND THAT IS THE SECOND-CONSUMER RULE BEING APPLIED ───────────────
 * `enumFromRule` and `maxFromRule` were declared HERE while the bot list was the only server-driven
 * table in the console. The sources list is the second one, and a private copy of a manifest parser
 * in each feature is two places the manifest's grammar is known — the drift `lib/api/browser.ts`
 * records itself moving `browserFetchData` to avoid. They now live in `lib/table/rules.ts`, imported
 * above; nothing about the parse changed, and `tests/unit/bot-list.test.ts` still pins this list's
 * values through this module.
 */

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
 * pending/queued -> slate, running -> info AND MOVING, info -> info and still, ready/active ->
 * success, degraded/partial -> warning, failed -> destructive, disabled -> slate.
 *
 * ── `testing` IS INFO, AND THE COMPROMISE THAT USED TO BE RECORDED HERE IS RESOLVED ─────────────
 * A bot in `testing` is being exercised before publication — the info bucket by meaning. It used to
 * take the SLATE bucket instead, because the only info-coloured `StatusKind` was `running`, whose
 * glyph SPINS, and an endlessly spinning loader on a static list row reads as "this page is stuck"
 * rather than "this bot is being trialled". The note here asked for one entry in
 * `components/status-pill.tsx` — an info variant with a STILL glyph — and that entry now exists, so
 * this row is retuned onto it rather than still describing the workaround.
 *
 * `paused` is WARNING and not destructive: a paused bot is not answering and somebody did that on
 * purpose. `archived` takes the disabled bucket, which is the same slate the pending bucket renders;
 * `draft` and `archived` are therefore STILL separated by their word alone, which is the residue the
 * repair did not cover — `pending` and `disabled` share one glyph in the shared vocabulary, and
 * splitting them would move a bucket four other features render. Recorded in `status-pill.tsx`.
 *
 * A `Record` keyed by the union, so a sixth status added to `BotStatus` fails to typecheck HERE
 * rather than rendering as a bare wire string on the screen.
 */
const BOT_STATUS_DISPLAY = {
  draft: { kind: 'pending', label: 'Draft' },
  testing: { kind: 'info', label: 'Testing' },
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

// ── THE DETAIL AND MUTATION TRANSPORT ───────────────────────────────────────────────────────────

/**
 * `encodeURIComponent` on a ULID is a no-op today. It is here because the value comes off a server
 * response (or off a route segment a person can type) and is interpolated into a URL, and the habit
 * is what keeps the day it stops being a ULID from being an injected path segment. Same rule, same
 * spelling, as `modelPath` in `features/models/api.ts`.
 */
export const botPath = (orgId: string, botId: string): string =>
  `${botsPath(orgId)}/${encodeURIComponent(botId)}`;

/**
 * `GET .../bots/{bot}` -> 200 `{data: …}` | 403 | 404.
 *
 * ── EVERY FAILURE ON THIS ENDPOINT IS `authorization`, AND THE SCREEN MUST NOT GUESS WHICH ──────
 * The route is mounted with `->scopeBindings()`, so an id belonging to another organization — or to
 * no organization at all — 404s at BINDING time, before any policy runs, and `bootstrap/app.php`
 * renders 404 as `authorization`. A viewer who genuinely lacks the permission gets 403, also
 * `authorization`. The three cases are byte-identical on the wire ON PURPOSE (the deny split), so
 * the editor renders one class-mapped sentence for all of them and names no role — all four roles
 * hold `bots.view` (ADR-056), so a role gap is the one explanation that is never true here.
 *
 * ── TWO FIELDS ON THE RESPONSE ARE `null` FOR A REASON THAT IS NOT "UNSET" ──────────────────────
 * `system_instruction` and `answer_style_instruction` are a MANAGEMENT-ONLY PROJECTION: a caller
 * without `bots.manage` receives `null` for both, whatever is stored. Nothing may render a `null`
 * there as "empty" — the two facts are different and only one of them is the operator's to fix.
 *
 * WHICH OF THE TWO IT IS COMES OFF THE BODY, IN `instructions_visible`, and never off a role this
 * client holds. `botFormDefaults` refuses to seed either key when it is false, so a form cannot
 * PATCH the projection back over the stored prompts — see `botPanelDefaults` below.
 */
export const fetchBot = async (
  orgId: string,
  botId: string,
  signal: AbortSignal,
): Promise<BotResource> =>
  browserFetchData<BotResource>({
    path: botPath(orgId, botId),
    credential: await sessionCredential(),
    signal,
  });

/**
 * `POST .../bots` -> 201 `{data: …}` | 403 | 422.
 *
 * `name` and `slug` are the only required fields; the rest of the body is `botCreateDefaults()`,
 * which re-states `NewBot`'s own defaults. Re-sending a value the server would have defaulted to is
 * free — `retrieval_configuration_version` moves only when a knob's VALUE changes.
 *
 * SLUG UNIQUENESS IS NOT IN `rules()` AND CANNOT BE. It is per organization, and an unscoped
 * `unique:` would be an existence oracle over the whole platform rendered as a validation error;
 * `BotService` checks it through an org-scoped repository method and answers 422 keyed `slug`. So it
 * arrives as an ordinary per-field validation error and must land under the input — not in a banner,
 * which is where an unknown key would go.
 */
export const createBot = async (orgId: string, body: BotCreateOut): Promise<BotResource> =>
  browserFetchData<BotResource>({
    path: botsPath(orgId),
    method: 'POST',
    body,
    credential: await sessionCredential(),
  });

/**
 * `PATCH .../bots/{bot}` -> 200 `{data: …}` | 403 | 404 | 422.
 *
 * A PATCH, and `UpdateBotRequest` rules every field `sometimes`, so A BODY CARRYING ONE FIELD IS A
 * LEGITIMATE REQUEST. That is what lets the editor's three tabs each save their own partition
 * without re-sending the other two — and it is why nothing here may hand it `botFormDefaults(bot)`
 * wholesale as a convenience: see `botPanelDefaults` for the pick that keeps a panel's body to a
 * panel's fields, and `botFormDefaults`' own docblock for why `reset(resource)` is never the path
 * from server data into form state.
 */
export const updateBot = async (
  orgId: string,
  botId: string,
  body: BotSettingsOut,
): Promise<BotResource> =>
  browserFetchData<BotResource>({
    path: botPath(orgId, botId),
    method: 'PATCH',
    body,
    credential: await sessionCredential(),
  });

/** `PUT`, not `PATCH`: the body is the whole of the resource this endpoint owns. */
export const botStatusPath = (orgId: string, botId: string): string =>
  `${botPath(orgId, botId)}/status`;

/**
 * `PUT .../bots/{bot}/status` -> 200 `{data: …}` | 403 | 404 | 409 | 422.
 *
 * ── A LIFECYCLE MOVE IS NOT A FIELD, AND THE SERVER MADE THAT A RULE RATHER THAN A CONVENTION ───
 * `UpdateBotRequest` rules `status` as `["missing"]`, so sending it on the PATCH is a 422 keyed
 * `status` — for any value, `null` and `""` included. The rule is there rather than the field simply
 * being dropped from `rules()`, and the difference is the whole reason this function exists: an
 * ABSENT rule makes `validated()` discard the key in silence, so the console would publish a bot,
 * get a 200, and find it still in draft.
 *
 * `status` is therefore OUT of `BOT_PUBLISHING_FIELDS`, out of `botSettingsSchema` and out of
 * `botFormDefaults`. The Publishing tab calls this instead of `useBotSave`, with its own
 * `useMutation` — one that never carries a `retry`, exactly as every other mutation in this app.
 *
 * ── FOUR REFUSALS, AND ONLY ONE OF THEM IS A VALIDATION ERROR ───────────────────────────────────
 * `archived` is TERMINAL — an archived bot is read-only, including its status, so there is no
 * un-archive transition — and the PUBLISH GUARD refuses a move to `published` for a bot with no
 * provider connection and model, or with `answer_mode: 'rag_first'` and `allow_general_answers`
 * still false. Both are 409. A NO-OP is a 422: this is a transition rather than a state assertion,
 * and re-asserting the status a bot already holds would write an audit row describing a change that
 * did not happen. And 403/404 are the usual `authorization` pair, byte-identical on the wire.
 *
 * None of the first three is computable from a cached row, so the control must not pre-judge the
 * move: render every member of `BOT_STATUSES`, submit, and put the server's answer under the
 * control (`BOT_STATUS_KNOWN_PATHS`) or in the banner. Greying out the options a client BELIEVES
 * are unreachable is the publish guard reimplemented in the browser, and it fails by hiding a move
 * the server would have allowed.
 *
 * ── THE 200 BODY IS THE BOT, NOT AN ACKNOWLEDGEMENT ─────────────────────────────────────────────
 * Same `{data: BotResource}` shape as `updateBot`, with `system_instruction` and
 * `answer_style_instruction` populated — reaching this endpoint requires `bots.manage`, which is the
 * permission that projection is gated on. Write it into the detail key and invalidate the list
 * prefix, exactly as `useBotSave` does: a status change moves rows in a list this screen is not
 * looking at.
 */
export const updateBotStatus = async (
  orgId: string,
  botId: string,
  body: BotStatusTransitionOut,
): Promise<BotResource> =>
  browserFetchData<BotResource>({
    path: botStatusPath(orgId, botId),
    method: 'PUT',
    body,
    credential: await sessionCredential(),
  });

// ── THE FIELD PARTITION THE EDITOR'S THREE TABS ARE BUILT FROM ──────────────────────────────────

/**
 * One name from `botSettingsSchema`'s key set. `keyof` rather than a hand-written union, so the
 * three tuples below cannot name a field the schema does not have — `satisfies` reports it here.
 */
export type BotSettingsField = keyof BotSettingsIn;

/**
 * THE THREE PANELS' FIELDS, AND THE PARTITION IS THE CONTRACT.
 *
 * `/bots/{id}` is one resource edited through three tabs that three people build independently, so
 * "who owns which field" has to be a value both the shell and the panels read rather than a sentence
 * in three briefs. These three tuples are DISJOINT and their union is EXACTLY `botSettingsSchema`'s
 * key set; `tests/unit/bot-editor.test.ts` asserts both halves, so a field added to the server (and
 * mirrored into the schema) fails by name instead of silently belonging to nobody and being
 * unreachable in the console.
 *
 * ── THE PARTITION IS DRAWN SO THAT EVERY CROSS-FIELD RULE LANDS INSIDE ONE PANEL ────────────────
 * `botSettingsSchema` carries three `superRefine`s and the server carries two more checks that no
 * declarative rule can express. Every one of them relates fields that are in the SAME tuple:
 *
 *   evidence_threshold  <-> evidence_threshold_scale   both MODEL      (required_with, both ways)
 *   evidence_threshold  <-> its scale's bounds         both MODEL      (EvidenceThresholdWithinScale)
 *   provider_model_id   ->  provider_connection_id     both MODEL      (required_with, one way)
 *   collect_end_user_data <-> consent_text             both PUBLISHING (BotService, not rules())
 *
 * That is not a coincidence to be preserved by luck: a partition that split one of those pairs would
 * produce a panel whose form can never satisfy its own resolver, because the sibling it is judged
 * against is not in its `defaultValues`. Moving a field across tuples means re-checking this list.
 *
 * `theme` is IDENTITY rather than PUBLISHING: it is the bot's appearance in the same sense its
 * welcome message is, and its three sub-paths (`theme.primary`, `theme.accent`, `theme.radius`) come
 * with it through `botPanelKnownPaths`.
 *
 * ── `status` IS IN NO TUPLE, AND ITS ABSENCE IS THE PARTITION WORKING RATHER THAN A HOLE ────────
 * It used to be the first entry of `BOT_PUBLISHING_FIELDS`. `UpdateBotRequest` now rules it
 * `["missing"]` — a lifecycle move is `PUT .../bots/{bot}/status`, and `updateBotStatus` above is
 * the call — so it is not a `botSettingsSchema` key, `keyof BotSettingsIn` no longer admits it, and
 * the `satisfies` on this tuple is what reported that rather than a reviewer. The union below is
 * still EXACTLY the PATCH's key set; what changed is the key set.
 *
 * The Publishing tab therefore owns TWO saves: `useBotSave` for its six fields and its own mutation
 * over `updateBotStatus` for the transition. They are different requests with different failure
 * vocabularies — a transition can 409 where a field save cannot — and merging them into one submit
 * would put a publish attempt behind a button whose label says "Save changes".
 *
 * THE TWO CHILD COLLECTIONS (`.../domains`, `.../starter-questions`) are in no tuple either, for a
 * different reason: they are separate resources with their own endpoints and their own manifests,
 * not fields of this one. `packages/contracts` mirrors both — schemas behind `@kb/contracts/forms`,
 * resource types on the root entry — so whichever surface renders them has types and a resolver
 * without hand-writing either.
 */
export const BOT_IDENTITY_FIELDS = [
  'name',
  'slug',
  'description',
  'welcome_message',
  'placeholder_text',
  'system_instruction',
  'answer_style_instruction',
  'theme',
] as const satisfies readonly BotSettingsField[];

export const BOT_MODEL_FIELDS = [
  'provider_connection_id',
  'provider_model_id',
  'answer_mode',
  'allow_general_answers',
  'dense_top_k',
  'sparse_top_k',
  'rerank_candidates',
  'rerank_retain',
  'evidence_threshold',
  'evidence_threshold_scale',
] as const satisfies readonly BotSettingsField[];

export const BOT_PUBLISHING_FIELDS = [
  'access_mode',
  'rate_limit_per_minute',
  'rate_limit_per_day',
  'retention_days',
  'collect_end_user_data',
  'consent_text',
] as const satisfies readonly BotSettingsField[];

/**
 * `botFormDefaults(bot)` NARROWED TO ONE PANEL'S FIELDS — the only sanctioned way a panel seeds its
 * form, and the reason it exists rather than each panel spreading what it needs.
 *
 * `botFormDefaults` is already the one sanctioned path from server data into form state (never
 * `reset(resource)`, which round-trips `id`, `public_bot_id`, `retrieval_configuration_version` and
 * both timestamps into a 200 with no change). This narrows it once more, because the PATCH body is
 * `handleSubmit`'s output: a panel seeded with all 25 fields SENDS all 25 fields, and the identity
 * tab would then silently rewrite the retrieval knobs another tab is mid-edit on.
 *
 * ── IT RETURNS THE TUPLE *INTERSECTED WITH WHAT THE SERVER SHOWED US*, WHICH IS NOT THE SAME THING ─
 * A field the caller cannot SEE is not seeded, at all — omitted rather than seeded `null`. The one
 * instance is the management-only projection: on a row whose `instructions_visible` is false,
 * `system_instruction` and `answer_style_instruction` arrive `null` whatever is stored, and
 * `UpdateBotRequest` rules both `sometimes|nullable|string` — so an omitted key is left alone while a
 * present `null` CLEARS the column and returns 200. Seeding the projected null and saving a rename
 * therefore writes null over two operator-authored prompts.
 *
 * The refusal lives in `botFormDefaults` (`packages/contracts/src/forms/bot.ts`), which this filters,
 * so it holds for every caller of either function rather than for the panels that remembered. What
 * decides it is the SERVER'S `instructions_visible` and never `canManageBots` below: the grant is
 * resolved per record against that record's own organization, and the whole failure window is a row
 * fetched while the client's own answer was `true`.
 *
 * A panel that renders a control for a field this drops would put it straight back — RHF collects a
 * registered input's DOM value on submit whether or not `defaultValues` named it — so the same flag
 * gates the CONTROLS in `bot-identity-panel.tsx`. Omission here is the second line, not the only one.
 *
 * ── `Object.entries` + `Object.fromEntries`, NOT `all[field]` IN A LOOP ─────────────────────────
 * Indexing an object by a variable is `security/detect-object-injection`'s sink and reports as a
 * warning nobody can act on. Filtering entries reads the same and does not.
 *
 * The cast is the one place this file asserts something the compiler cannot: `Object.fromEntries`
 * types its result as `{[k: string]: unknown}` regardless of the input's key union. Every value in
 * it came out of a `BotSettingsIn` under a key from `BotSettingsField`, and every key of
 * `BotSettingsIn` is optional, so a subset genuinely is one.
 */
export const botPanelDefaults = (
  bot: BotFormSource,
  fields: readonly BotSettingsField[],
): BotSettingsIn => {
  const wanted = new Set<string>(fields);
  const all = botFormDefaults(bot);

  return Object.fromEntries(
    Object.entries(all).filter(([field]) => wanted.has(field)),
  ) as BotSettingsIn;
};

// ── `knownPaths`: WHICH 422 KEYS EACH SURFACE CAN PUT UNDER A CONTROL ───────────────────────────

/**
 * `knownPaths` is "the paths this form RENDERS", which is a DIFFERENT SET from "the paths the
 * FormRequest validates" (`lib/forms/known-paths.ts`). `applyServerErrors` routes a known key to
 * `setError(path)` and everything else to a single `root.serverError` write, so a key routed to a
 * control that is not on screen is a save where the server rejects, nothing visibly changes, and the
 * operator clicks again. A caller may SUBTRACT from the derived set; it may never hand-type a
 * replacement for it.
 */
/**
 * A path the FormRequest declares only in order to REFUSE it. `UpdateBotRequest.status` is the one
 * instance: `["missing"]`, because a lifecycle move is `PUT .../bots/{bot}/status`.
 *
 * ── THE RULE NAME MOVED, AND IT WAS NOT A RENAME ───────────────────────────────────────────────
 * It read `["prohibited"]` until the server corrected it. `validateProhibited` is literally
 * `! validateRequired`, so it PASSED for `null`, `""` and `[]` and `validated()` kept the key — which
 * reached a `NOT NULL` column and turned an accompanying rename into a lost edit behind a 500.
 * `validateMissing` asks whether the KEY is there at all, so every one of those four bodies is now a
 * 422. This module reads the rule NAME below, which is why it had to move with it.
 *
 * SUBTRACTED FROM THE DERIVED SET, and the reason is what `knownPaths` means. It is "the paths this
 * form RENDERS", and no panel renders a control for a field it may not send — so a 422 keyed
 * `status` on the PATCH has no control to land on, and `setError` against a name that displays
 * nowhere is a save where the server rejects, nothing changes on screen, and the operator clicks
 * again. It belongs in the banner, which is where `applyServerErrors` routes an unknown key.
 *
 * It should never arrive at all: the schema cannot express the field and the partition does not name
 * it, so a `status` 422 on the PATCH means a caller bypassed both. The banner is the honest place
 * for a failure nobody rendered a control for.
 *
 * DERIVED FROM THE MANIFEST, not a hard-coded `'status'`: a second such field added server-side is
 * subtracted the day it is dumped. The subtraction is local to this module rather than in
 * `lib/forms/known-paths.ts` because this is the only manifest in the repo carrying the rule today;
 * the second one is the case for lifting it.
 */
const UNSENDABLE_UPDATE_BOT_PATHS: ReadonlySet<string> = new Set(
  // `Object.entries` rather than `rules[path]` in a predicate, for the reason `botPanelDefaults`
  // gives: indexing an object by a variable is `security/detect-object-injection`'s sink and reports
  // as a warning nobody can act on.
  Object.entries((updateBotRules as FormRulesManifest).rules)
    .filter(([, rules]) => rules.some((rule) => rule.split(':')[0] === 'missing'))
    .map(([path]) => path),
);

const UPDATE_BOT_PATHS: readonly string[] = knownPathsFromRules(
  updateBotRules as FormRulesManifest,
).filter((path) => !UNSENDABLE_UPDATE_BOT_PATHS.has(path));

const STORE_BOT_PATHS: readonly string[] = knownPathsFromRules(storeBotRules as FormRulesManifest);

/** `theme.primary` -> `theme`. The panel tuples name TOP-LEVEL fields; the manifest keys nested ones. */
const rootSegment = (path: string): string => path.split('.')[0] ?? path;

/**
 * `UpdateBotRequest`'s vocabulary, narrowed to one panel's fields — the argument that panel passes to
 * `applyAuthError`.
 *
 * A 422 keyed OUTSIDE the panel's partition is not a bug and is not swallowed: it reaches the panel's
 * banner through `root.serverError` with Laravel's own translated sentence, which is exactly right
 * for the two rules whose verdict depends on the STORED row rather than on the body
 * (`bots_evidence_threshold_paired` re-checked against the stored threshold, and the consent pairing
 * `BotService` evaluates against the resulting row). Those can 422 on a field the operator did not
 * send and is not looking at, and a banner is the only honest place for that.
 */
export const botPanelKnownPaths = (fields: readonly BotSettingsField[]): readonly string[] => {
  const wanted = new Set<string>(fields);
  return UPDATE_BOT_PATHS.filter((path) => wanted.has(rootSegment(path)));
};

/**
 * `StoreBotRequest`'s vocabulary, narrowed to the THREE controls the create dialog renders.
 *
 * The dialog posts all 25 fields — `botCreateDefaults()` under the two the operator types — and
 * renders three of them, which is the case `knownPaths` exists to separate. A 422 on
 * `evidence_threshold` from a create body the operator never composed has no control to land on, and
 * writing it to a field that displays nowhere is the failure `HIDDEN_PATHS` was introduced for, one
 * form larger. It goes to the banner instead.
 *
 * DERIVED AND THEN FILTERED, never typed out: the filter is over the SERVER'S key set, so a field
 * this dialog renders that the server drops disappears from the set rather than silently becoming a
 * path Laravel cannot key. `tests/unit/bot-editor.test.ts` asserts the three rendered names are a
 * subset of the manifest, which is the direction a filter cannot report on its own.
 */
export const BOT_CREATE_RENDERED_FIELDS: readonly string[] = ['name', 'slug', 'description'];

export const BOT_CREATE_KNOWN_PATHS: readonly string[] = STORE_BOT_PATHS.filter((path) =>
  BOT_CREATE_RENDERED_FIELDS.includes(rootSegment(path)),
);

/**
 * `UpdateBotStatusRequest`'s vocabulary — the ONE name a transition 422 can be keyed to, and the
 * argument the Publishing tab's status mutation passes to `applyAuthError`.
 *
 * Derived like every other set here rather than typed out as `['status']`, so this stays a read of
 * the server's own dumped `rules()`. It is a one-element set today and the derivation costs nothing.
 *
 * A 422 on this endpoint is the NO-OP case — re-asserting the status a bot already holds, which
 * would write an audit row describing a change that did not happen — and it lands under the control.
 * The publish guard's refusals are 409s with no `errors` map at all, so they carry no field key and
 * reach the banner as a class-mapped sentence plus the `request_id`, which is the only honest place
 * for a refusal computed against a row the browser cannot see.
 */
export const BOT_STATUS_KNOWN_PATHS: readonly string[] = knownPathsFromRules(
  updateBotStatusRules as FormRulesManifest,
);

/**
 * Does this role hold `bots.manage`? Owner and admin, per ADR-056 — which also grants `bots.view` to
 * ALL FOUR roles, and that asymmetry is the whole reason this predicate exists as one exported
 * function rather than as an inline comparison in four render sites.
 *
 * ── IT IS AN AFFORDANCE, NEVER THE AUTHORITY, AND THE LINE IS NOW LOAD-BEARING ─────────────────
 * All it may decide is whether a control is OFFERED. Laravel answers 403 whatever it returns, every
 * mutation path handles that class, and a role that changed under a cached session shows up as that
 * 403 rather than as a silently missing control. What it buys is a screen that does not offer a
 * control whose every use would be refused.
 *
 * ── IT IS NOT THE PROJECTION FLAG, AND IT USED TO BE READ AS ONE ───────────────────────────────
 * This docblock used to continue "IT IS ALSO THE FLAG THAT DECIDES WHETHER TWO FIELDS MEAN
 * ANYTHING", and that sentence was the data-loss path. It is a HAND-WRITTEN THIRD SPELLING of a
 * grant `OrgRole::grants()` owns and can move without this line changing, and it answers a question
 * about the SESSION while the projection is decided PER RECORD against that record's own
 * organization. The two disagree in exactly the window that matters: a role promoted mid-session, a
 * cached detail row, any refetch skew — `true` here over a body whose instructions were withheld,
 * `null` seeded into two textareas, and a rename saved as a PATCH that clears both prompts with a
 * 200.
 *
 * `BotResource.instructions_visible` is the server's own statement of what it withheld, set from the
 * same flag that decided the projection. Read it for "may I see or edit these two fields"; this
 * predicate answers "should this screen offer an editor at all" and NOTHING may seed form state from
 * it (`botPanelDefaults`, and `botFormDefaults` behind it).
 *
 * A `switch` is not used because the union is not exhausted on purpose: a fifth role added to `Role`
 * should default to NOT holding a write permission, which is the direction a boolean expression gets
 * right and an exhaustive switch would turn into a typecheck failure demanding a decision here rather
 * than in `OrgRole::grants()`.
 */
export const canManageBots = (role: Role | null): boolean => role === 'owner' || role === 'admin';
