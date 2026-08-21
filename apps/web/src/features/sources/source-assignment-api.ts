import type {
  AcknowledgementResource,
  BotSourceAssignmentCollectionResource,
  BotSourceAssignmentResource,
  Role,
  SourceResource,
} from '@kb/contracts';
import indexAssignmentsRules from '@kb/contracts/rules/IndexBotSourceAssignmentsRequest.json';

import { botPath } from '@/features/bots/api';
import {
  browserFetch,
  browserFetchData,
  sessionCredential,
  type ApiEnvelope,
} from '@/lib/api/browser';
import type { FormRulesManifest } from '@/lib/forms/known-paths';
import { readPaginatedEnvelope } from '@/lib/table/envelope';
import { maxFromRule } from '@/lib/table/rules';

/**
 * THE BOT↔SOURCE GRANT, READ AND WRITTEN FROM THE SOURCE'S SIDE.
 *
 * ── THE ENDPOINTS ARE HUNG OFF THE BOT AND THIS SCREEN ASKS FROM THE OTHER END ─────────────────
 * There are exactly three routes and all three are children of a bot:
 *
 *     GET    …/bots/{bot}/source-assignments            one page of that bot's grants
 *     POST   …/bots/{bot}/source-assignments            grant, body `{source_id}`
 *     DELETE …/bots/{bot}/source-assignments/{id}       withdraw, by the GRANT's id
 *
 * `/sources/{sourceId}` asks the opposite question — *which bots may answer from this document* —
 * and NO ENDPOINT ANSWERS IT. There is no `GET …/sources/{source}/bot-assignments`, `source_id` is
 * not a filter on the index (`IndexBotSourceAssignmentsRequest` closes `sort` to `id`, `priority`,
 * `enabled` and its `filter` searches the SOURCE's name and crawl URL, because the grant row itself
 * holds no text), and there is no `?bot_id=` on the sources index either.
 *
 * So the direction is inverted HERE, at the cost of one request per bot in view. That cost is the
 * finding: the panel pages the bot list deliberately small so the fan-out is bounded, and the
 * missing endpoint is reported to `control-plane-engineer` rather than papered over. It is not an
 * argument for putting the screen on the bot instead — the server's own permission catalog says the
 * opposite in as many words (`OrgRole::grants()`: "`sources.assign` is C6's, and it is what
 * `bots.view` was granted to this role FOR: the assignment screen is a list of bots").
 *
 * REACT-FREE on purpose, like `./api.ts`: nothing here imports a hook, so a spec can call a fetcher
 * directly and a render site cannot acquire a second copy of the envelope knowledge.
 */

/** `…/bots/{bot}/source-assignments`. `botPath` is `features/bots/api.ts`', imported rather than
 *  re-interpolated — the same rule that makes `sourcesPath` build on `organizationPath`. */
export const botSourceAssignmentsPath = (orgId: string, botId: string): string =>
  `${botPath(orgId, botId)}/source-assignments`;

/**
 * `…/bots/{bot}/source-assignments/{sourceAssignment}`.
 *
 * THE SEGMENT IS THE GRANT'S OWN ULID AND NEVER THE SOURCE'S. What a DELETE withdraws is the
 * permission, not the document: the source, its versions, its chunks and its vectors stay exactly
 * where they were. Routing this through the source id would be one path parameter away from the
 * two-phase removal that somebody has to prove finished.
 */
export const botSourceAssignmentPath = (
  orgId: string,
  botId: string,
  assignmentId: string,
): string => `${botSourceAssignmentsPath(orgId, botId)}/${encodeURIComponent(assignmentId)}`;

const INDEX_ASSIGNMENTS_RULES = (indexAssignmentsRules as FormRulesManifest).rules;

/**
 * The endpoint's own applied bounds, read out of the dumped `FormRequest` rather than restated.
 *
 * `filter` reaches a `LIKE` over the source's name, and a term longer than the rule's `max:` is a
 * 422 on a request the user made by opening a page — so the term below is TRUNCATED to this length
 * rather than sent whole. Truncation is safe for the lookup and the reason is worth stating: the
 * comparison is `%term%`, so a PREFIX of the name still matches the name. It can only ever match
 * MORE rows, never fewer, and the scan below keys on `source_id`.
 */
export const ASSIGNMENT_MAX_FILTER_LENGTH: number | null = maxFromRule(
  INDEX_ASSIGNMENTS_RULES['filter'],
);

/** The endpoint's page ceiling, read from the same manifest. The lookup asks for the largest page
 *  the server will serve, because a smaller one only means more requests for the same answer. */
export const ASSIGNMENT_MAX_PER_PAGE: number | null = maxFromRule(
  INDEX_ASSIGNMENTS_RULES['per_page'],
);

/** The fallback when the manifest cannot be parsed — `lib/table/params.ts`'s platform ceiling, which
 *  is the same number `ListQuery::MAX_PER_PAGE` enforces. A `null` from `maxFromRule` means the rule
 *  shape moved, and asking for 100 is refused with a 422 rather than silently clamped, which is the
 *  loud direction. */
const PER_PAGE = String(ASSIGNMENT_MAX_PER_PAGE ?? 100);

/**
 * HOW MANY PAGES THE LOOKUP WILL WALK BEFORE IT GIVES UP AND SAYS SO.
 *
 * `BotSourceAssignmentService::MAX_PER_BOT` is 200 and the page ceiling is 100, so two pages is the
 * whole of any bot's grants and a third is unreachable. The cap is three rather than two because
 * that constant is a server-side value this package must not restate: if it moves, the lookup
 * degrades to `null` for the affected bot — an honest "we could not tell" — instead of walking a
 * list of unknown length one request at a time from a render.
 */
const MAX_LOOKUP_PAGES = 3;

/**
 * The filter term for one source: its own name, trimmed and truncated to the rule's ceiling.
 *
 * Exported for `tests/unit/source-detail.test.ts`, which pins the truncation against the manifest —
 * a rule whose `max:` shrinks tomorrow otherwise 422s this page for every long-named document.
 */
export const assignmentFilterTerm = (sourceName: string): string => {
  const trimmed = sourceName.trim();
  const limit = ASSIGNMENT_MAX_FILTER_LENGTH;
  return limit === null ? trimmed : trimmed.slice(0, limit);
};

/**
 * ONE PAGE of a bot's grants. `browserFetch` and not `browserFetchData`, because
 * `readPaginatedEnvelope` reads `data.source_assignments` and `data.meta` TOGETHER and therefore
 * takes the whole body; the `data` unwrap happens inside it rather than twice.
 *
 * It THROWS on an unreadable envelope rather than returning zero rows, for the reason the source
 * list gives: `{rows: [], rowCount: 0}` would render "this bot is not assigned this source" for a
 * bot that is, which is a false statement about a permission.
 */
const fetchAssignmentPage = async (
  orgId: string,
  botId: string,
  params: Readonly<Record<string, string>>,
  signal: AbortSignal,
) => {
  const query = new URLSearchParams(params).toString();
  const body = await browserFetch<ApiEnvelope<BotSourceAssignmentCollectionResource>>({
    path: `${botSourceAssignmentsPath(orgId, botId)}?${query}`,
    credential: await sessionCredential(),
    signal,
  });
  return readPaginatedEnvelope<BotSourceAssignmentResource>(body, 'source_assignments');
};

/**
 * The answer to "does THIS bot hold a grant on THIS source", and the grant itself when it does.
 *
 * ── `null` MEANS NO GRANT; A THROW MEANS WE DO NOT KNOW ────────────────────────────────────────
 * Two different facts, and collapsing them would render "not assigned" for a request that failed —
 * on a control whose next click creates a duplicate the server answers with a 422. So the caller
 * gets a resolved `null` only when the pages were actually read to the end.
 *
 * `null` AND NOT `undefined`, AND THAT IS TANSTACK QUERY'S RULE RATHER THAN A PREFERENCE: a
 * `queryFn` resolving `undefined` is rejected with "data is undefined", so the query lands in its
 * ERROR state and the row renders "we could not check" for a request that succeeded and said no.
 * The symptom is the exact confusion the paragraph above exists to prevent, arriving by a different
 * door.
 *
 * ── WHY IT PAGES AT ALL ────────────────────────────────────────────────────────────────────────
 * `filter` is a substring match on the source's NAME, so it usually returns exactly the one row
 * this is looking for. It can also over-match — two documents sharing a word, or a name containing
 * `%` or `_`, which `LIKE` reads as wildcards — and an over-match pushes the row we want onto page
 * two. Over-matching is SAFE and under-matching is not, which is why the term is the untouched name
 * rather than something escaped or normalised here: the scan below keys on `source_id`, so extra
 * rows cost bytes and never correctness, and the loop is bounded by the envelope's own `meta.total`.
 *
 * `signal` is forwarded on every page — `queryClient.cancelQueries()` is a no-op against a `queryFn`
 * that drops it, and a multi-page walk is exactly where an abandoned organization switch would
 * otherwise keep issuing requests for a tenant the admin has left.
 */
export const fetchGrantForSource = async (
  orgId: string,
  botId: string,
  source: Pick<SourceResource, 'id' | 'name'>,
  signal: AbortSignal,
): Promise<BotSourceAssignmentResource | null> => {
  const filter = assignmentFilterTerm(source.name);

  for (let page = 1; page <= MAX_LOOKUP_PAGES; page += 1) {
    const params: Record<string, string> = {
      page: String(page),
      per_page: PER_PAGE,
      // `id` ascending is creation order (a ULID's leading 48 bits are a millisecond timestamp) and
      // it is the one sort in this endpoint's closed set that is STABLE under a concurrent grant.
      // Paging over `priority` — the endpoint's default — can repeat or skip a row when two grants
      // share a priority, which is the ordinary case since zero is the default and the floor.
      sort: 'id',
      dir: 'asc',
    };
    // An empty term is omitted rather than sent: the server treats a whitespace-only `filter` as no
    // filter at all, so sending one would be a second spelling of the same request.
    if (filter !== '') params['filter'] = filter;

    const { rows, rowCount, pageSize } = await fetchAssignmentPage(orgId, botId, params, signal);

    const grant = rows.find((row) => row.source_id === source.id);
    if (grant !== undefined) return grant;

    // THE APPLIED PAGE SIZE, NOT THE REQUESTED ONE. `meta.per_page` is what the server actually
    // served, and it is the only value the arithmetic below can be right about — the endpoint
    // refuses an over-large `per_page` rather than clamping it, but the default it falls back to on
    // an unparsed manifest is its own. A short page is the last page; a full one may still be.
    if (rows.length < pageSize || page * pageSize >= rowCount) return null;
  }

  // Only reachable if a bot holds more grants than three full pages, which the server-side cap makes
  // impossible today. A throw rather than `null`, because "we did not finish looking" must not
  // render as "there is no grant" — see the docblock.
  throw new Error(
    `Could not decide whether bot ${botId} is assigned this source: more assignment pages than ${MAX_LOOKUP_PAGES}.`,
  );
};

/**
 * `POST …/bots/{bot}/source-assignments` -> 201 `{data: …}` | 403 | 404 | 409 | 422.
 *
 * ── THE BODY IS `{source_id}` AND NOTHING ELSE, DELIBERATELY ───────────────────────────────────
 * `StoreBotSourceAssignmentRequest` also accepts `priority` and `enabled`, and this screen sends
 * neither. `priority` is a tie-break NOTHING IN THIS PLATFORM RANKS ON YET — the contract says so in
 * as many words — so a control for it would describe a behaviour that does not exist; `enabled`
 * defaults to true, and a grant created switched off is a grant nobody asked for. Both are one
 * `withdraw and re-grant` away if a later screen needs them, which is the only edit path the server
 * has: there is no endpoint that updates an assignment, because the audit catalog defines a created
 * and a deleted operation and no updated one.
 *
 * ── FOUR REFUSALS, AND THE SCREEN PRE-JUDGES NONE OF THEM ──────────────────────────────────────
 * 409 for a suspended organization; 422 when the bot already holds this source, when the bot is at
 * its cap, or when the source is `deleting`/`deleted`; 403 for a viewer without `sources.assign`;
 * 404 for a source id that names nothing in this organization — never a validation error, which
 * would be an existence oracle over every customer's document ids rendered as a form message.
 *
 * NO OPTIMISTIC WRITE, and none is possible: a grant is one of the four mandatory vector-search
 * filter terms, and claiming one the server may have refused is claiming a bot can read a document
 * it cannot. Invalidate and re-read.
 */
export const createBotSourceAssignment = async (
  orgId: string,
  botId: string,
  sourceId: string,
): Promise<BotSourceAssignmentResource> =>
  browserFetchData<BotSourceAssignmentResource>({
    path: botSourceAssignmentsPath(orgId, botId),
    method: 'POST',
    body: { source_id: sourceId },
    credential: await sessionCredential(),
  });

/**
 * `DELETE …/bots/{bot}/source-assignments/{sourceAssignment}` -> 200 `{data:{acknowledged:true}}`.
 *
 * AN ACKNOWLEDGEMENT AND NOT THE ROW: there is nothing left to return. DELETE IS NOT IDEMPOTENT
 * here — a second one is a 404 rather than a 200, because an audit row exists for the first and a
 * 200 for the second would claim this actor withdrew a grant the trail does not record them
 * withdrawing. So the control is disabled while pending, and the failure renders rather than being
 * swallowed as "already gone".
 *
 * NOT DESTRUCTIVE IN THE `<ConfirmDestructiveDialog>` SENSE, and that is a deliberate reading rather
 * than an omission: withdrawing a grant leaves the source, its versions and its vectors untouched
 * and every other bot assigned to it keeps answering, and re-granting it is one request. Typed
 * confirmation is for the irreversible; spending it here would train people to type through it on
 * the delete that is.
 */
export const deleteBotSourceAssignment = async (
  orgId: string,
  botId: string,
  assignmentId: string,
): Promise<AcknowledgementResource> =>
  browserFetchData<AcknowledgementResource>({
    path: botSourceAssignmentPath(orgId, botId, assignmentId),
    method: 'DELETE',
    credential: await sessionCredential(),
  });

/**
 * Whether this viewer holds `sources.assign` in this organization.
 *
 * AN AFFORDANCE, NEVER AUTHORIZATION. Laravel answers 403 whatever this returns, this screen renders
 * that class, and a role that changed under a cached session shows up as the 403 rather than as a
 * silently missing control.
 *
 * A SEPARATE PREDICATE FROM `canManageSources`, for the reason that file gives about its own pair:
 * `sources.manage` and `sources.assign` are separate grants in `App\Enums\OrgRole::grants()` and may
 * diverge. They agree today — owner, admin and knowledge manager hold both, and an ANALYST holds
 * neither — and one predicate serving both would make a divergence render a control whose every use
 * is refused.
 *
 * A POSITIVE TEST OVER A LISTED SET, so a fifth role added to `Role` defaults to holding NEITHER.
 */
export const canAssignSources = (role: Role | null): boolean =>
  role === 'owner' || role === 'admin' || role === 'knowledge_manager';

/**
 * The role to NAME when this viewer cannot assign — the least-privileged role that holds
 * `sources.assign`, in the words the console uses elsewhere (`roleLabel`, lower-cased for
 * mid-sentence use). A separate constant from `SOURCE_MANAGE_ROLE` for the same reason the predicate
 * is separate.
 */
export const SOURCE_ASSIGN_ROLE = 'knowledge manager';
