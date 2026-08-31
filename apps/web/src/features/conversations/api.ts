import type {
  ConversationChannel,
  ConversationCollectionResource,
  ConversationResource,
  ConversationStatus,
  ConversationTranscriptResource,
  MessageStatus,
  ProviderCallResource,
  Role,
  TranscriptMessageResource,
} from '@kb/contracts';
import indexConversationsRules from '@kb/contracts/rules/IndexConversationsRequest.json';

import type { StatusKind } from '@/components/status-pill';
import type { ToneName } from '@/components/tone';
import { organizationPath } from '@/features/providers/api';
import {
  browserFetch,
  browserFetchData,
  sessionCredential,
  type ApiEnvelope,
} from '@/lib/api/browser';
import type { FormRulesManifest } from '@/lib/forms/known-paths';
import { readPaginatedEnvelope, type TablePage } from '@/lib/table/envelope';
import { MAX_PER_PAGE, type TableParamsConfig } from '@/lib/table/params';
import { enumFromRule } from '@/lib/table/rules';

/**
 * CONVERSATION REVIEW'S TRANSPORT — the paginated thread list and the one transcript read. There is
 * no write on this surface at all.
 *
 * REACT-FREE on purpose: nothing here imports a hook, so a spec can call a fetcher directly and a
 * render site cannot acquire a second copy of the envelope knowledge.
 *
 * ── TWO ENDPOINTS AND NO WRITE, AND THAT IS A PROPERTY OF THE SURFACE RATHER THAN A GAP ─────────
 * A thread is opened by the public runtime, written by the relay's finalizer, and removed by the
 * retention sweeper. §18.11 treats a transcript as EVIDENCE, so a surface that could both read and
 * destroy it would be able to rewrite the record it exists to preserve.
 *
 * ── THE §18.10 PRIVACY SWITCH DOES NOT EXIST, AND THIS CLIENT MUST NOT INVENT ONE ───────────────
 * The spec's per-organization "may administrators review conversations" toggle has NO COLUMN:
 * `organizations.settings` carries no key vocabulary and no reader, so `ConversationController`'s
 * docblock records the measurement and a TODO rather than inventing one. The transcript endpoint
 * therefore ships UNGATED, and there is deliberately no control anywhere in this feature implying an
 * organization opted in — a toggle that stores nothing would tell an operator they had made a
 * decision the platform has no way to honour.
 *
 * ── EVERYTHING BELOW THE HEADER IS UNTRUSTED TEXT, READ IN A PRIVILEGED SESSION ─────────────────
 * Visitor questions, model output and quoted document passages, rendered by an ADMINISTRATOR whose
 * session holds real permissions — which makes this the higher-stakes render rather than the
 * lower-stakes one. It goes through the SAME sanitizer as hosted chat and the widget, with no
 * widened allow-list and no auto-loaded remote image.
 */

export const conversationsPath = (orgId: string): string =>
  `${organizationPath(orgId)}/conversations`;

export const conversationPath = (orgId: string, conversationId: string): string =>
  `${conversationsPath(orgId)}/${encodeURIComponent(conversationId)}`;

const INDEX_CONVERSATIONS_RULES = (indexConversationsRules as FormRulesManifest).rules;

/**
 * The columns `GET .../conversations` permits an `ORDER BY` on — `last_activity_at` and
 * `started_at` at the time of writing, and WHATEVER THE MANIFEST SAYS TOMORROW.
 *
 * NOT TYPED OUT, EVER. `sort` reaches an `ORDER BY`, so the FormRequest closes the set with
 * `Rule::in(...)` and `php artisan kb:dump-form-rules` writes it here. A hand-copied list is a 422
 * one header click away, on a request the user made by clicking a control we drew.
 *
 * ── THE TWO ARE DIFFERENT QUESTIONS AND THE DEFAULT IS THE SECOND ONE ───────────────────────────
 * `started_at` is when the thread OPENED; `last_activity_at` is when a turn was last TAKEN. A
 * reviewer opening this screen is asking "what is happening now", and every stale-thread sweep is
 * built on the second column, so the default is `last_activity_at` descending.
 */
export const CONVERSATION_SORTABLE_COLUMNS: readonly string[] = enumFromRule(
  INDEX_CONVERSATIONS_RULES['sort'],
);

/**
 * The five channels, READ OUT OF THE ENDPOINT'S OWN `in:` RULE.
 *
 * A value outside this set is a VALIDATION ERROR rather than an empty result, which is why the
 * filter is a `<Select>` over this array and never a text input.
 *
 * ── `mobile` IS IN THE VOCABULARY AND UNREACHABLE IN PRACTICE, AND THAT IS THE SERVER SAYING SO ─
 * Nothing in the control plane mints a personal access token, so the React Native client has no
 * credential for the runtime surface — `RuntimeConversationController` says so in as many words and
 * declines to label those turns `hosted`, because that would put a number in the channel breakdown
 * that is simply untrue. So the option renders, and selecting it correctly returns nothing.
 */
export const CONVERSATION_CHANNELS: readonly string[] = enumFromRule(
  INDEX_CONVERSATIONS_RULES['channel'],
);

/** The three lifecycle states, from the same rule set and for the same reason. */
export const CONVERSATION_STATUSES: readonly string[] = enumFromRule(
  INDEX_CONVERSATIONS_RULES['status'],
);

/**
 * The filters this screen offers, spelled exactly as the FormRequest spells them — so the URL, the
 * request and the query key all say the same thing.
 *
 * ── WHAT IS DELIBERATELY ABSENT ─────────────────────────────────────────────────────────────────
 * `session_id` and `user_id`. Both are accepted by the endpoint and neither has a control here: a
 * session id is a one-way DIGEST of a bearer that nobody types or recognises, and a user id is a
 * ULID whose useful form is a picker — which is a different screen's affordance ("this person's
 * conversations"), not a filter bar's. They stay in `filterNames` so a URL carrying either survives
 * a page change and reaches the request; what is missing is only the control.
 *
 * THAT IS A DELIBERATE ASYMMETRY WITH THE AUDIT SCREEN, which does render an actor picker: there,
 * "who did this" is the question the surface exists to answer. Here the question is "what was said",
 * and the participant is usually anonymous.
 */
export const CONVERSATION_BOT_PARAM = 'bot_id';
export const CONVERSATION_CHANNEL_PARAM = 'channel';
export const CONVERSATION_STATUS_PARAM = 'status';
export const CONVERSATION_FROM_PARAM = 'from';
export const CONVERSATION_UNTIL_PARAM = 'until';
export const CONVERSATION_SESSION_PARAM = 'session_id';
export const CONVERSATION_USER_PARAM = 'user_id';

export const CONVERSATION_FILTER_PARAMS: readonly string[] = [
  CONVERSATION_BOT_PARAM,
  CONVERSATION_CHANNEL_PARAM,
  CONVERSATION_STATUS_PARAM,
  CONVERSATION_FROM_PARAM,
  CONVERSATION_UNTIL_PARAM,
  CONVERSATION_SESSION_PARAM,
  CONVERSATION_USER_PARAM,
];

/**
 * The view configuration, at MODULE SCOPE because `useTableParams` requires a stable identity.
 *
 * `defaultSort` mirrors `IndexConversationsRequest::DEFAULT_SORT` and its `SortDirection::Desc`:
 * most recently active first, which is what a reviewer opens the screen to see.
 */
export const CONVERSATION_LIST_CONFIG: TableParamsConfig = {
  sortableColumns: CONVERSATION_SORTABLE_COLUMNS,
  defaultSort: { id: 'last_activity_at', desc: true },
  filterNames: CONVERSATION_FILTER_PARAMS,
  pageSizes: [25, 50, MAX_PER_PAGE],
};

/**
 * `GET .../conversations?page&per_page&sort&dir&bot_id&channel&status&from&until&…`
 *   -> 200 `{data:{conversations:[…],meta:{…}}}` | 403 | 404 | 422.
 *
 * `browserFetch` AND NOT `browserFetchData`, because `readPaginatedEnvelope` reads the collection
 * and `meta` together and therefore takes the WHOLE body.
 *
 * EVERY STATUS COMES BACK, including `expired` threads the retention sweeper has marked and threads
 * still in flight. Hiding either would make "where did that conversation go" unanswerable from the
 * console while the record still existed.
 *
 * IT THROWS ON AN UNREADABLE ENVELOPE rather than returning zero rows: `{rows: [], rowCount: 0}`
 * would render the FIRST-RUN empty state to an organization with ten thousand threads, which is
 * indistinguishable from data loss at a glance.
 */
export const fetchConversationPage = async (
  orgId: string,
  params: Readonly<Record<string, string>>,
  signal: AbortSignal,
): Promise<TablePage<ConversationResource>> => {
  const query = new URLSearchParams(params).toString();
  const body = await browserFetch<ApiEnvelope<ConversationCollectionResource>>({
    path: `${conversationsPath(orgId)}?${query}`,
    credential: await sessionCredential(),
    signal,
  });
  return readPaginatedEnvelope<ConversationResource>(body, 'conversations');
};

/**
 * `GET .../conversations/{conversation}` -> 200 `{data: …}` | 403 | 404, unwrapped from `data`.
 *
 * ── THE SHAPE IS `ConversationTranscriptResource`, AND THE DIFFERENCE IS THE SCREEN ─────────────
 * It publishes every field the list row carries PLUS the messages, and each message carries its
 * citations, its retrieval trace, its feedback and EVERY PROVIDER ATTEMPT behind it — which is where
 * per-turn latency, token counts and fallback events actually live. `ConversationTranscriptResource
 * extends ConversationResource` in `@kb/contracts`, by `extends` rather than by a second flat
 * interface, so a field added to the list shape tomorrow is on this one the moment it is on that
 * one.
 *
 * ── `messages_truncated` IS PUBLISHED RATHER THAN INFERRED, AND THE SCREEN MUST RENDER IT ───────
 * A client cannot tell a capped read from a conversation that happened to be exactly that long, and
 * a transcript that ends mid-argument reads as a conversation that ended there. So the flag is a
 * state this screen renders, not a hint it may ignore.
 */
export const fetchConversationTranscript = async (
  orgId: string,
  conversationId: string,
  signal: AbortSignal,
): Promise<ConversationTranscriptResource> =>
  browserFetchData<ConversationTranscriptResource>({
    path: conversationPath(orgId, conversationId),
    credential: await sessionCredential(),
    // Forwarded because `queryClient.cancelQueries()` is a NO-OP against a `queryFn` that drops it,
    // and cancelling in-flight reads is step 2 of both logout and the organization switch — exactly
    // when another organization's transcript must not resolve.
    signal,
  });

/**
 * THE POLL, AND IT RETURNS `false`.
 *
 * ── A CONVERSATION LIST HAS NO TERMINAL STATE TO STOP ON, WHICH IS WHY IT MUST NOT POLL ─────────
 * The sources list polls because a source walks a fifteen-state lifecycle whose transient states end
 * — `sourcePollInterval` returns `false` the moment no row on the page is going anywhere, and that
 * stop condition is what keeps a tab left open from becoming permanent traffic.
 *
 * CONVERSATIONS DO NOT SETTLE. `active` is not transient: a thread stays active until a visitor
 * stops talking or the idle sweeper expires it, which can be hours. A `refetchInterval` here would
 * therefore have no stop condition at all — the function form would return a number for ever, which
 * is the bare-number failure wearing a function's shape. Twenty admins with one tab each is four
 * requests a second of pure list traffic, every one of them paying a session lookup, a membership
 * re-check and a policy evaluation against `throttle:admin`, for a table nobody is watching.
 *
 * So it is `false`, permanently, and the affordance is the ordinary refetch: the 30 s `staleTime`
 * from `lib/query/client.ts` means a navigation back to this screen re-reads it.
 */
export const conversationPollInterval = (): false => false;

// ── WHAT THIS VIEWER MAY BE OFFERED ─────────────────────────────────────────────────────────────

/**
 * AFFORDANCE, NEVER AUTHORIZATION. Laravel answers 403 whatever this returns.
 *
 * ── THE KNOWLEDGE MANAGER IS THE ONE ROLE WITHHELD, AND THE REASON IS NOT OBVIOUS ───────────────
 * `conversations.view` is held by owner, admin and ANALYST — §6.5's first two responsibilities are
 * "Review conversations" and "Add feedback", so this is the reporting role's stated job rather than
 * an inference from it. The KNOWLEDGE MANAGER does not hold it, and `Permission::ConversationsView`
 * says why: §6.4's "review parsed content" is the SOURCE detail projection that `sources.view`
 * already serves, and a transcript is verbatim END-USER text rather than parsed content.
 *
 * A POSITIVE TEST OVER A LISTED SET, so a fifth role added to `Role` defaults to holding nothing.
 */
export const canViewConversations = (role: Role | null): boolean =>
  role === 'owner' || role === 'admin' || role === 'analyst';

/** The role to NAME in the forbidden state — the least-privileged role that can read this list. */
export const CONVERSATION_VIEW_ROLE = 'analyst';

// ── THE DISPLAY VOCABULARIES ────────────────────────────────────────────────────────────────────

export interface ConversationStatusDisplay {
  readonly kind: StatusKind;
  /** Sentence case. The WORD is the channel that survives greyscale and CVD, and it is never
   *  omitted. */
  readonly label: string;
}

/**
 * The three thread states onto the CLOSED `<StatusPill>` vocabulary.
 *
 * `active` IS `running` — the glyph spins, because a thread that is active genuinely may gain a turn
 * while the reviewer is looking at it. `ended` is `ready`: the visitor finished, which is a normal
 * outcome and not a failure. `expired` is `disabled` and NOT `failed`: the retention sweeper marked
 * it, which is the platform doing what the organization asked, and a red pill would read as an
 * incident.
 *
 * A `Record` keyed by the union, so a fourth state added to `ConversationStatus` fails to typecheck
 * HERE rather than rendering as a bare wire string on the screen.
 */
const CONVERSATION_STATUS_DISPLAY = {
  active: { kind: 'running', label: 'Active' },
  ended: { kind: 'ready', label: 'Ended' },
  expired: { kind: 'disabled', label: 'Expired' },
} as const satisfies Readonly<Record<ConversationStatus, ConversationStatusDisplay>>;

/** A `Map`, and not the object above, for the LOOKUP: the key comes off the wire, and indexing a
 *  plain object by a server-supplied value is the `security/detect-object-injection` sink. */
const CONVERSATION_STATUS_BY_NAME = new Map<string, ConversationStatusDisplay>(
  Object.entries(CONVERSATION_STATUS_DISPLAY),
);

/**
 * A stored status -> the pill's kind and word, or THE VALUE ITSELF in the neutral bucket when this
 * build has not heard of it. The fallback is the point rather than politeness: this console is
 * deployed separately from the API, so a fourth state reaches a browser running last week's bundle,
 * and the alternatives are a crash on an object lookup or a blank cell.
 */
export const conversationStatusDisplay = (status: string): ConversationStatusDisplay =>
  CONVERSATION_STATUS_BY_NAME.get(status) ?? { kind: 'pending', label: status };

export interface ChannelDisplay {
  readonly label: string;
  /** A DECORATIVE, CATEGORICAL tint (P7). It never means good, bad or urgent — that is the pill's
   *  job — and it is assigned from a STABLE KEY rather than a position, so re-sorting the table does
   *  not recolour every row. */
  readonly tone: ToneName;
}

/**
 * WHERE THE VISITOR WAS, as a tint plus the word.
 *
 * `playground` gets `violet` and is worth naming: it is the channel whose ACTOR TYPE unlocks
 * `retrieval.trace` on the relay, which is why the server derives the channel from the credential
 * and never from a body field — a widget that could claim it would be asking for the pipeline's
 * internal topology.
 *
 * A `Record` keyed by the union, so a sixth channel fails to typecheck here.
 */
const CHANNEL_DISPLAY = {
  hosted: { label: 'Hosted chat', tone: 'sky' },
  embedded: { label: 'Widget', tone: 'mint' },
  mobile: { label: 'Mobile', tone: 'amber' },
  playground: { label: 'Playground', tone: 'violet' },
  api: { label: 'API', tone: 'slate' },
} as const satisfies Readonly<Record<ConversationChannel, ChannelDisplay>>;

const CHANNEL_BY_NAME = new Map<string, ChannelDisplay>(Object.entries(CHANNEL_DISPLAY));

export const channelDisplay = (channel: string): ChannelDisplay =>
  CHANNEL_BY_NAME.get(channel) ?? { label: channel, tone: 'slate' };

/**
 * A TURN's state onto the pill vocabulary.
 *
 * EVERY status appears on this surface, unlike the runtime transcript: `pending` and `streaming` are
 * turns still in flight, and A STUCK ONE IS WHAT AN OPERATOR OPENS THIS SCREEN TO FIND — so they get
 * the moving `running` treatment rather than being hidden.
 *
 * `cancelled` IS `disabled` AND NOT `failed`. A closed tab is a normal outcome, the tokens generated
 * before it were still billed, and painting it red would make an ordinary Tuesday look like an
 * incident — the same distinction `ProviderCallStatus` draws on the attempt beneath it.
 */
const MESSAGE_STATUS_DISPLAY = {
  pending: { kind: 'running', label: 'Waiting' },
  streaming: { kind: 'running', label: 'Streaming' },
  complete: { kind: 'ready', label: 'Complete' },
  failed: { kind: 'failed', label: 'Failed' },
  cancelled: { kind: 'disabled', label: 'Cancelled' },
} as const satisfies Readonly<Record<MessageStatus, ConversationStatusDisplay>>;

const MESSAGE_STATUS_BY_NAME = new Map<string, ConversationStatusDisplay>(
  Object.entries(MESSAGE_STATUS_DISPLAY),
);

export const messageStatusDisplay = (status: string): ConversationStatusDisplay =>
  MESSAGE_STATUS_BY_NAME.get(status) ?? { kind: 'pending', label: status };

// ── PER-TURN FACTS, DERIVED FROM THE ATTEMPTS RATHER THAN FROM THE MESSAGE ──────────────────────

export interface TurnMetrics {
  /** The attempt that SETTLED the turn — the last one, when a fallback was reached. */
  readonly settling: ProviderCallResource | null;
  /** Every attempt, oldest first. One on a normal answer; more when a fallback was reached. */
  readonly attempts: readonly ProviderCallResource[];
  /** Attempts whose `fallback_metadata` is non-empty. THE RECORD OF WHAT WAS TRIED FIRST. */
  readonly fallbacks: readonly ProviderCallResource[];
  /** Summed across attempts, `null` when no attempt reported one — which is not zero. */
  readonly inputTokens: number | null;
  readonly outputTokens: number | null;
}

/**
 * What a turn cost and how long it took, read off `provider_calls` rather than off the message.
 *
 * ── THE MESSAGE CARRIES NEITHER LATENCY NOR TOKENS, AND THAT IS THE SHAPE OF THE DATA ───────────
 * `TranscriptMessageResource` has `created_at` and `updated_at`, whose gap is the wall clock the
 * visitor waited — but the FIRST-TOKEN latency, the token counts, the cost and the fallback record
 * are all per-ATTEMPT, because a turn that fell back has two of each. Deriving them from the message
 * would report one number for a turn that made two calls.
 *
 * ── SUMMING TOKENS, AND WHY `null` SURVIVES ────────────────────────────────────────────────────
 * `null` means THE PROVIDER TOLD US NOTHING, which is different from zero. So the sum is `null` when
 * NO attempt reported a figure, and the sum of the reported ones when any did — treating an
 * unreported attempt as 0 would silently under-report a turn, and treating the whole turn as
 * unreported would discard a figure we have.
 *
 * ── COST IS NOT SUMMED HERE, DELIBERATELY ───────────────────────────────────────────────────────
 * `estimated_cost` is an exact decimal STRING and `estimated_cost_currency` travels with it. Adding
 * two of them means either parsing to a double — which is what `numeric(16,8)` exists to avoid — or
 * assuming one currency, which is a silent wrong answer the moment a fallback crosses vendors. The
 * screen renders each attempt's cost beside its own currency instead.
 */
export const turnMetrics = (message: TranscriptMessageResource): TurnMetrics => {
  const attempts = message.provider_calls;
  const settling =
    attempts.find((call) => call.id === message.settling_provider_call_id) ?? null;

  const sum = (read: (call: ProviderCallResource) => number | null): number | null => {
    const reported = attempts.map(read).filter((value): value is number => value !== null);
    return reported.length === 0 ? null : reported.reduce((total, value) => total + value, 0);
  };

  return {
    settling,
    attempts,
    // NON-EMPTY `fallback_metadata` IS THE ONLY SIGNAL. A fallback row with an empty object is
    // indistinguishable from a first attempt, which is the whole reason the column exists — so the
    // test is on the object's key count and never on the attempt's position in the array.
    fallbacks: attempts.filter((call) => Object.keys(call.fallback_metadata).length > 0),
    inputTokens: sum((call) => call.input_tokens),
    outputTokens: sum((call) => call.output_tokens),
  };
};
