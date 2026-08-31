import type {
  ChatSessionResource,
  RuntimeBotResource,
  RuntimeConversationResource,
  RuntimeMessageCollectionResource,
  RuntimeMessageResource,
} from '@kb/contracts';

import {
  browserFetchData,
  browserFetchPublicData,
  type Credential,
} from '@/lib/api/browser';

/**
 * THE PUBLIC CHAT RUNTIME TRANSPORT — the `sdk/v1` handshake and the four non-streaming `rt/v1`
 * calls. The streaming send is `stream-answer.ts`'s and is deliberately not here.
 *
 * REACT-FREE on purpose: nothing here imports a hook, so a spec can call a fetcher directly and a
 * render site cannot acquire a second copy of the envelope knowledge.
 *
 * ── THESE PATHS ARE NOT UNDER `api/v1`, AND THE PREFIX IS LOAD-BEARING ──────────────────────────
 * `api/*` is the ADMIN group: `statefulApi()` prepends `EnsureFrontendRequestsAreStateful`, so a
 * request from a stateful origin acquires the whole session stack — and `RejectBearerToken` refuses
 * a bearer there by design. `rt/v1` and `sdk/v1` sit outside it for exactly that reason, and
 * `config/cors.php` lists `sdk/*` explicitly because Laravel's unpublished default `paths` is
 * `['api/*', 'sanctum/csrf-cookie']`: an SDK route mounted anywhere else gets no CORS headers, the
 * browser rejects the preflight before any controller runs, and the server log is empty.
 *
 * ── THE THREE-STEP HANDSHAKE, AND WHY EACH STEP IS SEPARATE ─────────────────────────────────────
 *
 *   1. `POST /sdk/v1/bootstrap`  the bot's public configuration, with NO credential. A launcher has
 *      to be drawn before a session exists, and drawing a button is not a reason to issue a
 *      credential — a page that loads and never opens the chat would otherwise burn one session per
 *      view. Nothing is written, so the POST needs no idempotency key: it is a read that happens to
 *      carry its argument in a body.
 *   2. `POST /sdk/v1/session`    the mint. THE ONE REQUEST IN THE SYSTEM CARRYING AN UNFORGEABLE
 *      EMBEDDER `Origin`, because it is made by a document on the embedding page itself. Every later
 *      request comes from our own origin and proves nothing about who is embedding us, which is why
 *      the server re-validates the STORED origin rather than the requesting one.
 *   3. `POST /rt/v1/conversations`  the thread, opened with the bearer from step 2.
 *
 * ── THE ONE DEPLOYMENT FACT THAT MAKES HOSTED CHAT 404, AND IT IS NOT A CLIENT BUG ──────────────
 * Both `sdk/v1` routes validate the request's `Origin` header against the bot's ACTIVE `bot_domains`
 * rows — byte for byte, `hash_equals`, no wildcard grammar (`BotDomainMatcher`, and
 * `ExactOrigin::parse()` refuses `*` at the point of storage). Hosted chat runs on
 * `chat.<domain>`, which is OUR origin and not the customer's, so **the hosted-chat origin must
 * itself be an Active domain on the bot** or bootstrap answers 404 like any other unlisted embedder.
 *
 * That is a configuration step an operator takes on `/bots/{botId}` → Publishing, and it is written
 * here rather than left to be rediscovered because the symptom is indistinguishable from "this bot
 * does not exist": every rejection on this surface is a byte-identical 404 by design, so the client
 * cannot tell an unlisted origin from an unknown id and MUST NOT WRITE COPY THAT GUESSES. The chat
 * surface therefore says what is true — this bot is not available at this address — and points at
 * the two things an operator can check.
 *
 * ── THE TOKEN IS HELD IN MEMORY AND NOWHERE ELSE ────────────────────────────────────────────────
 * `ChatSessionResource.token` is a LIVE CREDENTIAL. It is never logged, never put in a URL or a
 * fragment, and never persisted: `tanstack-query-table` NN3 bans a persister for tenant content and
 * this is stronger than tenant content. A reload mints a new one, which costs one request and is the
 * only design in which closing a laptop does not leave a bearer on the disk.
 */

/** The two `sdk/v1` routes. Origin-validated, unauthenticated, and outside `api/`. */
const SDK_BOOTSTRAP_PATH = '/sdk/v1/bootstrap';
const SDK_SESSION_PATH = '/sdk/v1/session';

/** The public runtime surface. NOT `api/v1` — see the module docblock. */
export const runtimeConversationsPath = (): string => '/rt/v1/conversations';

export const runtimeTranscriptPath = (conversationId: string): string =>
  `/rt/v1/conversations/${encodeURIComponent(conversationId)}/messages`;

export const runtimeFeedbackPath = (messageId: string): string =>
  `/rt/v1/messages/${encodeURIComponent(messageId)}/feedback`;

/**
 * `POST /sdk/v1/bootstrap` -> 200 `{data: RuntimeBotResource}` | 404 | 422 | 429.
 *
 * NO CREDENTIAL. What authorizes it is the `Origin` header the browser sets and page script cannot
 * forge; the bot id travels in the BODY rather than the path so a public identifier does not land in
 * Traefik access logs, in `Referer` on every navigation away, and in browser history.
 *
 * `user_token` IS DELIBERATELY NOT SENT. `MintChatSessionRequest` accepts it and
 * `WidgetSessionService` IGNORES it — there is no per-bot signing secret and no rotation story for
 * one, so `verifyUserToken()` is unwritten. Sending an unverifiable identity claim would be a
 * downgrade to anonymous wearing an authenticated name.
 */
export const bootstrapPublicBot = async (
  publicBotId: string,
  signal: AbortSignal,
): Promise<RuntimeBotResource> =>
  browserFetchPublicData<RuntimeBotResource>({
    path: SDK_BOOTSTRAP_PATH,
    method: 'POST',
    body: { bot_id: publicBotId },
    signal,
  });

/**
 * `POST /sdk/v1/session` -> 201 `{data: ChatSessionResource}` | 404 | 422 | 429.
 *
 * The minted token is an opaque `kbw_` bearer granting exactly three abilities against exactly one
 * bot. It can never carry an admin ability: it is minted with NO HUMAN AUTHENTICATION AT ALL, so
 * anyone who can put the loader on an allow-listed page gets one.
 *
 * NO `Idempotency-Key`, and therefore this must never be retried by anything. A replayed mint is a
 * second live credential for the same visitor — harmless in itself, and exactly the shape that makes
 * a session-count metric lie. The caller mints once per document.
 */
export const mintChatSession = async (
  publicBotId: string,
  signal: AbortSignal,
): Promise<ChatSessionResource> =>
  browserFetchPublicData<ChatSessionResource>({
    path: SDK_SESSION_PATH,
    method: 'POST',
    body: { bot_id: publicBotId },
    signal,
  });

/** The bearer, in the shape `browserFetch` and `streamAnswer` both take. */
export const chatSessionCredential = (token: string): Credential => ({
  kind: 'chat_session',
  token,
});

/**
 * `POST /rt/v1/conversations` -> 201 `{data: RuntimeConversationResource}` | 401 | 404 | 422 | 429.
 *
 * ── CALLED FROM THE SUBMIT HANDLER, NEVER FROM AN EFFECT ────────────────────────────────────────
 * This WRITES A ROW. React StrictMode invokes an effect twice in development and it re-runs on any
 * remount, so opening the thread on mount produces two conversations — one of them empty, forever,
 * in the customer's own analytics. Opening it lazily on the first send also means a visitor who
 * loads the page and leaves costs nothing.
 *
 * ── THE CHANNEL AND THE CONSENT SNAPSHOT ARE THE SERVER'S ───────────────────────────────────────
 * The only thing this body may say is the locale, and it selects nothing. `channel` is derived from
 * how the caller authenticated — a body field naming it would let a widget claim `playground`, which
 * is the actor type that unlocks retrieval diagnostics on the relay — and the consent text is
 * snapshotted from the bot's own row so a later edit cannot rewrite what a visitor agreed to.
 *
 * `locale` IS OMITTED RATHER THAN GUESSED when the browser gives nothing usable: `null` means "we
 * were not told", which is a real state and a different fact from `en`.
 */
export const openRuntimeConversation = async (
  credential: Credential,
  locale: string | null,
  signal: AbortSignal,
): Promise<RuntimeConversationResource> =>
  browserFetchData<RuntimeConversationResource>({
    path: runtimeConversationsPath(),
    method: 'POST',
    credential,
    ...(locale === null ? {} : { body: { locale } }),
    signal,
  });

/**
 * `GET /rt/v1/conversations/{conversation}/messages` -> 200 `{data:{messages}}` | 401 | 404 | 429.
 *
 * SETTLED ENTRIES ONLY. A `pending` row has no content and would render as a bubble that never
 * fills, because this endpoint is a POLL and not a stream — so a client resuming a document
 * mid-answer sees the question and not the half-written answer. That is correct: the answer was on
 * the stream it just lost, and re-posting the same `client_message_id` replays the persisted state
 * rather than re-billing a generation.
 *
 * The named key is unwrapped HERE, once, beside the `data` unwrap — never at a render site.
 */
export const fetchRuntimeTranscript = async (
  credential: Credential,
  conversationId: string,
  signal: AbortSignal,
): Promise<readonly RuntimeMessageResource[]> => {
  const body = await browserFetchData<RuntimeMessageCollectionResource>({
    path: runtimeTranscriptPath(conversationId),
    credential,
    signal,
  });
  return body.messages;
};

/**
 * `POST /rt/v1/messages/{message}/feedback` -> 200 `{data:{acknowledged}}` | 401 | 404 | 422 | 429.
 *
 * AT MOST ONE VERDICT PER SUBMITTER: a visitor who changes their mind UPDATES rather than appending,
 * so a second call is not a duplicate and needs no idempotency key. What it must not be is
 * automatic — the thumbs are the only thing on this surface that is unambiguously the reader's own
 * opinion.
 *
 * `rating` is one of two values chosen by WHICH BUTTON was pressed, and `comment` is one of the four
 * reason chips, because an unqualified downvote is not usable signal for evaluation. Neither is a
 * free-text field, which is why this body has no schema and no resolver.
 */
export const submitRuntimeFeedback = async (
  credential: Credential,
  messageId: string,
  rating: 'positive' | 'negative',
  comment: string | null,
): Promise<void> => {
  await browserFetchData<{ readonly acknowledged: boolean }>({
    path: runtimeFeedbackPath(messageId),
    method: 'POST',
    credential,
    body: comment === null ? { rating } : { rating, comment },
  });
};

/**
 * The browser's declared language, or `null`.
 *
 * `null` RATHER THAN A GUESS. `StoreRuntimeConversationRequest` bounds the grammar with a BCP-47
 * regex and `max:35`, and a value that fails either 422s the whole conversation-open — so a runtime
 * that reports something unusual must cost the visitor nothing. Absent means "we were not told",
 * which the server models as its own state.
 *
 * ── IT IS A FILTER, NOT A VALIDATION ────────────────────────────────────────────────────────────
 * The client's job is to omit a value it knows will be refused, never to decide what is legal. The
 * server's rule is the authority; this only stops a `zh-Hans-CN-x-private` or an `en_US` from
 * turning "open a conversation" into a 422 the visitor cannot act on.
 *
 * ── SPELLED AS TWO BOUNDED CHECKS RATHER THAN ONE NESTED-QUANTIFIER REGEX ───────────────────────
 * The obvious mirror of the server's pattern is `^[a-z]{2,3}(-[A-Za-z0-9]{2,8}){0,3}$`, and
 * `security/detect-unsafe-regex` reports it: a quantified group inside a quantifier is the shape
 * that backtracks super-linearly. It is bounded here — three repetitions of at most eight characters
 * over an input already capped at 35 — so the concrete risk is nil, and that is exactly the argument
 * that trains a reader to wave the rule through the next time it fires on something that is not
 * bounded. Splitting on `-` is the same check with no nesting at all, and it reads better.
 */
const PRIMARY_SUBTAG = /^[a-z]{2,3}$/;
const EXTENSION_SUBTAG = /^[A-Za-z0-9]{2,8}$/;
const MAX_LOCALE_LENGTH = 35;
const MAX_EXTENSION_SUBTAGS = 3;

export const declaredLocale = (): string | null => {
  if (typeof navigator === 'undefined') return null;
  const language: unknown = navigator.language;
  if (typeof language !== 'string' || language.length > MAX_LOCALE_LENGTH) return null;

  const [primary, ...rest] = language.split('-');
  if (primary === undefined || !PRIMARY_SUBTAG.test(primary)) return null;
  if (rest.length > MAX_EXTENSION_SUBTAGS) return null;
  if (!rest.every((subtag) => EXTENSION_SUBTAG.test(subtag))) return null;

  return language;
};
