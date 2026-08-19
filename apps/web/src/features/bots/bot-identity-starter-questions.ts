import type { BotStarterQuestionCollectionResource, BotStarterQuestionResource } from '@kb/contracts';
import {
  starterQuestionUpdateDefaults,
  type StarterQuestionCreateOut,
  type StarterQuestionSource,
  type StarterQuestionUpdateIn,
  type StarterQuestionUpdateOut,
} from '@kb/contracts/forms';
import storeStarterQuestionRules from '@kb/contracts/rules/StoreBotStarterQuestionRequest.json';
import updateStarterQuestionRules from '@kb/contracts/rules/UpdateBotStarterQuestionRequest.json';

import { browserFetchData, sessionCredential } from '@/lib/api/browser';
import { knownPathsFromRules, type FormRulesManifest } from '@/lib/forms/known-paths';

import { botPath } from './api';

/**
 * THE STARTER-QUESTION TRANSPORT — four requests, two `knownPaths` sets, two bounds read off the
 * server's own dumped rules, and one narrowing from a stored row into a rename form.
 *
 * ── WHY IT IS A SEPARATE MODULE FROM `./api.ts` AND FROM `bot-starter-questions.tsx` ────────────
 * `./api.ts` is the editor's PUBLISHED SURFACE and is read-only to the three panel agents — the
 * starter questions belong to one tab, so a fourth export block there would be a shared file edited
 * for one panel's benefit. This is the private module the panel contract sanctions instead, and it is
 * a `.ts` rather than a `.tsx` for the reason `./api.ts` gives about itself: REACT-FREE, so a spec
 * can call a fetcher directly and a render site cannot acquire a second copy of the envelope
 * knowledge. `bot-starter-questions.tsx` is the UI and imports this; nothing here imports a hook.
 *
 * ── THE PATHS ARE BUILT FROM `botPath`, NOT RESPELLED ──────────────────────────────────────────
 * `/organizations/{org}/bots/{bot}` already exists exactly once in this feature. A second literal
 * here would be a second place the organization segment is composed, which is the one segment that
 * must never be got wrong — so the child path is the parent path plus a suffix.
 *
 * ── EVERY BOUND BELOW IS READ FROM THE MANIFEST, NEVER TYPED OUT ────────────────────────────────
 * `packages/contracts/rules/*.json` is dumped from the FormRequests' own `rules()`, so the length
 * cap and the position ceiling are the server's numbers by construction. A literal `200` or `6` here
 * would be a third spelling — after the FormRequest and `@kb/contracts/forms` — and the one nothing
 * compares against anything. `null` is the honest answer when the rule carries no numeric bound: the
 * affordance simply disappears and the server's 422 becomes the only statement of the limit, which
 * is a degradation rather than a wrong number.
 */

/** `max:200` -> 200. `null` when the rule carries no numeric bound. Same spelling as `./api.ts`'s,
 *  and deliberately not imported from it: that one is not exported, and exporting it would be an
 *  edit to a file this panel may not change. */
function maxFromRule(rules: readonly string[] | undefined): number | null {
  const rule = rules?.find((entry) => entry.startsWith('max:'));
  if (rule === undefined) return null;
  const bound = Number.parseInt(rule.slice('max:'.length), 10);
  return Number.isSafeInteger(bound) ? bound : null;
}

const STORE_MANIFEST = storeStarterQuestionRules as FormRulesManifest;
const UPDATE_MANIFEST = updateStarterQuestionRules as FormRulesManifest;

/**
 * `bail|required|string|max:200` — the chip label's cap, as a `maxLength` affordance on the control.
 *
 * IT IS AN AFFORDANCE AND NOT THE AUTHORITY. `maxLength` stops the keystroke; the FormRequest is what
 * refuses a paste that arrives through some other path, and `.trim()` inside
 * `starterQuestionCreateSchema` is what keeps the browser's count and Laravel's count the same after
 * `TrimStrings` has run.
 */
export const STARTER_QUESTION_MAX_LENGTH: number | null = maxFromRule(
  STORE_MANIFEST.rules['question'],
);

/**
 * `min:0|max:5` on `sort_order` — the last legal POSITION, which is a ceiling on the LIST expressed
 * as a bound on one row.
 *
 * Positions are zero-based and always 0..n-1 with no gaps, so `max:5` means at most six questions —
 * which is the number `kb-ai-chat-ux` says the chat surface renders, so what is stored is what is
 * shown. The ceiling is derived rather than restated for the reason above.
 */
const STARTER_QUESTION_MAX_POSITION: number | null = maxFromRule(
  UPDATE_MANIFEST.rules['sort_order'],
);

/**
 * How many questions one bot may have. `null` when the position rule carried no bound, in which case
 * the console offers the add form unconditionally and the server's 422 — keyed `question`, which is a
 * control this panel renders — is what states the limit.
 *
 * THERE IS NO FLOOR HERE AND THERE MUST NOT BE. `kb-ai-chat-ux` asks for three to six chips and the
 * server enforces only the ceiling, deliberately: a bot with one question is legitimate and zero is
 * every bot's default state. A client-side minimum would refuse a configuration the server accepts,
 * which is the invisible half of the drift asymmetry — functionality removed with nothing reported.
 */
export const STARTER_QUESTIONS_MAX: number | null =
  STARTER_QUESTION_MAX_POSITION === null ? null : STARTER_QUESTION_MAX_POSITION + 1;

/**
 * The org-scoped query key's SUFFIX — `orgKeyFor(...starterQuestionsKeyParts(botId))` is the whole
 * key, and `useOrgKey()` supplies the `['org', orgId]` prefix that no URL on this screen carries.
 *
 * IT IS A FUNCTION HERE BECAUSE TWO COMPONENTS READ THE SAME COLLECTION: the starter-questions card
 * and the identity tab's live preview, which draws the chips beside the welcome message they sit
 * under. Two `useQuery` calls with the same key share one fetch and one cache entry; two calls with
 * keys that were spelled out separately would be one silent typo away from two entries, one of which
 * never invalidates.
 */
export const starterQuestionsKeyParts = (botId: string): readonly unknown[] => [
  'bots',
  botId,
  'starter-questions',
];

/** `.../bots/{bot}/starter-questions`. */
export const starterQuestionsPath = (orgId: string, botId: string): string =>
  `${botPath(orgId, botId)}/starter-questions`;

/** `.../starter-questions/{starterQuestion}`. `encodeURIComponent` for the reason `botPath` gives:
 *  the value comes off a server response and is interpolated into a URL. */
export const starterQuestionPath = (orgId: string, botId: string, questionId: string): string =>
  `${starterQuestionsPath(orgId, botId)}/${encodeURIComponent(questionId)}`;

/**
 * `GET .../starter-questions` -> 200 `{data: {starter_questions: [...]}}` | 403 | 404.
 *
 * `index` demands `bots.view`, which ALL FOUR roles hold, so this resolves for a reader who cannot
 * change a single row — which is why the read-only branch of the identity panel still renders the
 * list rather than hiding it.
 *
 * THE EXTRA NESTING LEVEL IS THE SERVER'S AND IS UNWRAPPED HERE, ONCE. `browserFetchData` removes the
 * `data` envelope; this removes the collection wrapper, so no render site knows about either. The
 * wrapper exists so pagination fields can join it later without moving the list or versioning the
 * endpoint.
 *
 * ORDERED BY `sort_order` ASCENDING, which is the order they render in, and the positions are always
 * 0..n-1 with no gaps. Render straight from the array and treat a gap as a defect rather than as a
 * state.
 */
export const fetchStarterQuestions = async (
  orgId: string,
  botId: string,
  signal: AbortSignal,
): Promise<readonly BotStarterQuestionResource[]> => {
  const collection = await browserFetchData<BotStarterQuestionCollectionResource>({
    path: starterQuestionsPath(orgId, botId),
    credential: await sessionCredential(),
    signal,
  });

  return collection.starter_questions;
};

/**
 * `POST .../starter-questions` -> 201 `{data: …}` | 403 | 404 | 409 | 422.
 *
 * THE POSITION IS NOT A REQUEST FIELD. A new question is appended to the end by the server, which is
 * the only position that cannot collide with an existing one — `starterQuestionCreateSchema` is a
 * `strictObject` over `{question}` alone, so a form that tried to choose one is a parse failure
 * rather than a silent strip.
 *
 * The FULL-LIST refusal arrives here as a 422 KEYED `question`
 * (`BotStarterQuestionService::add`), so it lands under the control the operator just typed into
 * rather than in a banner.
 */
export const createStarterQuestion = async (
  orgId: string,
  botId: string,
  body: StarterQuestionCreateOut,
): Promise<BotStarterQuestionResource> =>
  browserFetchData<BotStarterQuestionResource>({
    path: starterQuestionsPath(orgId, botId),
    method: 'POST',
    body,
    credential: await sessionCredential(),
  });

/**
 * `PATCH .../starter-questions/{starterQuestion}` -> 200 `{data: …}` | 403 | 404 | 409 | 422.
 *
 * ONE ENDPOINT, TWO GESTURES: a rename sends `{question}`, a move sends `{sort_order}`, and each
 * field is the other's `required_without`. The FormRequest deliberately carries NO `sometimes` —
 * `sometimes` short-circuits every remaining rule for an absent key, `required_without` included, and
 * with it on both fields an empty PATCH body once satisfied everything and returned 200 having
 * changed nothing. Mirror the manifest, not the symmetry.
 *
 * A MOVE IS AN INTENT, NOT A COLUMN WRITE. The unique position index is not deferrable, so the
 * service reads the list under the bot's row lock and re-sequences EVERY row inside one transaction.
 * The response is the single edited row while the other rows have silently moved, which is why every
 * caller re-reads the collection instead of splicing.
 */
export const updateStarterQuestion = async (
  orgId: string,
  botId: string,
  questionId: string,
  body: StarterQuestionUpdateOut,
): Promise<BotStarterQuestionResource> =>
  browserFetchData<BotStarterQuestionResource>({
    path: starterQuestionPath(orgId, botId, questionId),
    method: 'PATCH',
    body,
    credential: await sessionCredential(),
  });

/**
 * `DELETE .../starter-questions/{starterQuestion}` -> 200 `{data: {acknowledged: true}}`.
 *
 * NEVER a 204: `browserFetch` short-circuits 204/205 to `undefined` before parsing, and this endpoint
 * answers 200 with an acknowledgement body (decision D8). The return value is discarded — there is no
 * `false` — and the delete CLOSES THE GAP it leaves, so every surviving question after it has moved
 * and the collection must be re-read.
 *
 * DELETE IS NOT IDEMPOTENT HERE. A second delete is a 404 rather than a 200, because an audit row
 * exists for the first one; the caller therefore disables the control while the request is in flight
 * rather than relying on a replay being harmless.
 */
export const deleteStarterQuestion = async (
  orgId: string,
  botId: string,
  questionId: string,
): Promise<void> => {
  await browserFetchData<{ readonly acknowledged: boolean }>({
    path: starterQuestionPath(orgId, botId, questionId),
    method: 'DELETE',
    credential: await sessionCredential(),
  });
};

/**
 * The ADD form's vocabulary — `['question']`, derived rather than typed.
 *
 * `knownPaths` is "the paths this form RENDERS". The add form renders exactly the one field the POST
 * declares, so the derived set needs no subtraction and the full-list 422 lands under the input.
 */
export const STARTER_QUESTION_CREATE_KNOWN_PATHS: readonly string[] =
  knownPathsFromRules(STORE_MANIFEST);

/**
 * The RENAME form's vocabulary, and the subtraction is the whole point.
 *
 * `UpdateBotStarterQuestionRequest` declares two paths; the rename form renders ONE. `sort_order` is
 * moved by two buttons that carry no control of their own, so a 422 keyed `sort_order` — the "that
 * position is past the end of the list, you are working from a stale read" refusal — has no field to
 * land on, and `setError` against a name that displays nowhere is a save where the server rejects,
 * nothing changes on screen, and the operator clicks again. It reaches the banner instead, which is
 * where `applyServerErrors` routes an unknown key.
 *
 * FILTERED OVER THE SERVER'S OWN KEY SET rather than written as `['question']`, so a field this form
 * renders that the server drops disappears from the set instead of becoming a path Laravel cannot
 * key.
 */
export const STARTER_QUESTION_RENAME_KNOWN_PATHS: readonly string[] = knownPathsFromRules(
  UPDATE_MANIFEST,
).filter((path) => path === 'question');

/**
 * The ONE path from a stored question into the rename form's state, and it is `starterQuestionUpdateDefaults`
 * NARROWED — the same relationship `botPanelDefaults` has to `botFormDefaults`, for the same reason.
 *
 * `starterQuestionUpdateDefaults` returns BOTH fields, and the PATCH body is `handleSubmit`'s output
 * verbatim: a rename seeded with both would post the `sort_order` the row carried WHEN THE FORM
 * OPENED. That is not a harmless echo — `sort_order` on this endpoint is "move this to position N",
 * so a rename typed while another question was deleted in a second tab would silently move the row to
 * a position the list no longer has, and the reachable outcomes are a 422 nobody predicted or a
 * reorder nobody asked for.
 *
 * DERIVED THROUGH THE SANCTIONED BUILDER rather than reading `row.question` directly, so there is
 * still exactly one place that knows how a stored row becomes form state.
 */
export const starterQuestionRenameDefaults = (
  starterQuestion: StarterQuestionSource,
): StarterQuestionUpdateIn => ({
  question: starterQuestionUpdateDefaults(starterQuestion).question,
});
