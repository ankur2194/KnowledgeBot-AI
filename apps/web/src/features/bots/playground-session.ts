import type { ChatSessionResource } from '@kb/contracts';

import {
  chatSessionCredential,
  declaredLocale,
  openRuntimeConversation,
} from '@/features/chat/runtime-api';
import { browserFetchData, sessionCredential, type Credential } from '@/lib/api/browser';

import { botPath } from './api';

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *  THE D5 PLAYGROUND'S CREDENTIAL — mint with the admin session, then talk `rt/v1` as a BEARER
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * REACT-FREE on purpose, exactly like `features/chat/runtime-api.ts` and `features/bots/api.ts`:
 * nothing here imports a hook, so `tests/unit/playground-stream.test.ts` can drive the whole
 * mint → bearer → stream sequence directly and a render site cannot acquire a second copy of the
 * envelope knowledge.
 *
 * ── THE RUNTIME STILL RESOLVES EXACTLY ONE CREDENTIAL, AND THAT IS WHY THIS FILE EXISTS ─────────
 * `ResolveChatSession::handle()` is still `$this->sessions->resolve($request->bearerToken())` and
 * nothing else — `git diff` on it is empty. The panel used to hand `sessionCredential()` (cookie plus
 * `X-XSRF-TOKEN`, no bearer) straight to `openRuntimeConversation`, so `bearerToken()` was null and
 * every send answered 401 `authentication`. The fix is NOT a second mechanism on `rt/v1`; it is an
 * ADMIN route that issues the one mechanism to an administrator:
 *
 *   POST /api/v1/organizations/{organization}/bots/{bot}/playground-session
 *     -> 201 {data: {token, expires_in}}, `Cache-Control: no-store`
 *
 * behind `bots.manage` and refusing a bot that is not `testing` or `published` (409). The token is
 * the same `kbw_` shape the widget's is; what differs is the STORED RECORD — `kind: playground`,
 * from which `WidgetSessionService::resolve()` derives `actorType: User` and `diagnostics: true`.
 * That single stored field is what makes `ClientEvents::allows()` forward `retrieval.trace`, and it
 * is why nothing on this client asks for diagnostics: a client that could ask is a client a widget
 * could imitate.
 *
 * ── IT CARRIES `chat:send` AND `chat:read`, AND DELIBERATELY NOT `feedback:submit` ──────────────
 * `WidgetSessionService::PLAYGROUND_ABILITIES` is two names. §8.23 reports a satisfaction figure
 * from END-USER feedback, and an operator rating their own test run puts a reviewer's thumb into a
 * number that is supposed to describe readers. The panel therefore passes no `onFeedback` and
 * `AnswerActions` renders no thumbs at all — and the tightening is asserted SERVER-side, so a UI
 * that offered them would fail at the server rather than in review.
 *
 * ── THE TOKEN LIVES IN THIS CLOSURE AND NOWHERE ELSE ────────────────────────────────────────────
 * Never logged, never in a URL or a fragment, never in `localStorage`, `sessionStorage` or
 * IndexedDB, and never in a TanStack Query cache — `tanstack-query-table` NN3 bans a persister for
 * tenant content and a live bearer is stronger than tenant content. The mint response is `no-store`
 * so no intermediary holds it either. A reload mints a new one, which costs one request and is the
 * only design in which closing a laptop leaves no bearer on the disk.
 */

/** `POST` only. The route is `bots.playground-session.store`, nested under the organization. */
export const playgroundSessionPath = (orgId: string, botId: string): string =>
  `${botPath(orgId, botId)}/playground-session`;

/**
 * `POST .../bots/{bot}/playground-session` -> 201 `{data: ChatSessionResource}` | 401 | 403 | 404 |
 * 409 | 429.
 *
 * ── THE ENVELOPE IS THE POINT, AND READING IT AT THE TOP LEVEL FAILS SILENTLY ───────────────────
 * The body is `{data: {token, expires_in}}`. `browserFetchData` is the ONE unwrap — beside
 * `browserFetch`, at the fetch boundary, never at a call site — so `data` is read exactly once. A
 * caller that reached for `body.token` would get `undefined`, send `Authorization: Bearer undefined`,
 * and receive the same 401 this whole change exists to remove; that is the shape that broke the
 * widget loader against this same resource yesterday, and it produces no error anywhere near the
 * defect. `tests/unit/playground-stream.test.ts` asserts against the WRAPPED fixture for that reason:
 * a test written against an unwrapped one passes while the panel fails.
 *
 * SENT WITH THE ORDINARY ADMIN SESSION CREDENTIAL — cookie plus `X-XSRF-TOKEN`, re-read from the
 * cookie on every call rather than cached, so a rotated token is picked up without a reload. This is
 * an `api/v1` route, which is the group `RejectBearerToken` refuses a bearer on; the bearer it
 * RETURNS is for `rt/v1`, which is a different group on a different prefix.
 *
 * NO `Idempotency-Key`, and therefore this must never be retried by anything. A replayed mint is a
 * second live credential for the same administrator — harmless in itself, and exactly the shape that
 * makes a session count lie. The two call sites below each mint at most once per user action.
 */
export const mintPlaygroundSession = async (
  orgId: string,
  botId: string,
  signal?: AbortSignal,
): Promise<ChatSessionResource> =>
  browserFetchData<ChatSessionResource>({
    path: playgroundSessionPath(orgId, botId),
    method: 'POST',
    credential: await sessionCredential(),
    ...(signal === undefined ? {} : { signal }),
  });

/** What `ChatConnection.connect` and `ChatConnection.reMint` both resolve with. */
export interface PlaygroundConnection {
  readonly connect: () => Promise<{ conversationId: string; credential: Credential }>;
  readonly reMint: () => Promise<{ conversationId: string; credential: Credential }>;
}

/**
 * The panel's connection: one conversation, one bearer, and a way to replace the bearer without
 * replacing the conversation.
 *
 * ── `connect()` IS IDEMPOTENT ACROSS CALLS, AND A BOOLEAN GUARD WOULD NOT MAKE IT SO ────────────
 * `ChatSurface` calls it once per send and requires the SAME pair every time. The window that
 * matters is between the first `await` and the closure variable being written: a second caller
 * arriving inside it must WAIT for the first rather than skip, so the guard holds the in-flight
 * PROMISE. A rejected promise is cleared either way — keeping one would make every later send
 * re-throw the first failure without retrying, which is a panel permanently broken because one
 * request failed once.
 *
 * ── `reMint()` KEEPS THE CONVERSATION AND REPLACES ONLY THE CREDENTIAL ──────────────────────────
 * `expires_in` is 900 s and SLIDES on every authorized request, so it is the floor for an IDLE tab
 * rather than a promise about an active one — nothing here schedules a re-mint against it. What
 * triggers one is the server saying so: `401` + `error_class: authentication`, the same trigger the
 * widget loader uses (`apps/widget/src/app/stream.ts`). An admin who leaves the tab open over lunch
 * and then asks a question would otherwise be shown `ERROR_COPY.authentication` — "Your session has
 * ended. Sign in again to continue." — which is false about the console session they are holding and
 * sends them round a sign-out loop over an expired chat bearer.
 *
 * KEEPING THE CONVERSATION IS CORRECT AND IS A PROPERTY OF THE SERVER, NOT A GUESS. A playground
 * conversation is owned by `conversations.user_id` (`conversations_participant_exclusive` refuses a
 * row carrying both participant columns), and `ChatGate::authorizeSubmission` looks it up through
 * `findForParticipant(..., $session->anonymousSessionId(), $session->userId)` — which for a
 * playground session is the USER id. A fresh mint for the same administrator therefore owns the same
 * thread; opening a second conversation would fragment one operator's test run across two rows in
 * the tenant's own analytics for no reason.
 *
 * ── AND IT IS A FACTORY RATHER THAN A HOOK ──────────────────────────────────────────────────────
 * So a spec can drive it. The panel holds one instance in a ref for the life of the mounted tab; the
 * two ids are captured at construction, which is what keeps a bot switch from re-using another bot's
 * bearer — a new bot id is a new instance, and the old closure goes with the unmounted panel.
 */
export function createPlaygroundConnection(orgId: string, botId: string): PlaygroundConnection {
  let opened: { conversationId: string; credential: Credential } | null = null;
  let opening: Promise<{ conversationId: string; credential: Credential }> | null = null;

  const connect = async (): Promise<{ conversationId: string; credential: Credential }> => {
    if (opened !== null) return opened;
    if (opening !== null) return opening;

    const attempt = (async () => {
      /**
       * NOT WIRED TO A CLEANUP SIGNAL, DELIBERATELY, for the reason `hosted-chat.tsx` gives: this
       * runs inside a send whose own `AbortController` belongs to the composer's Stop button, and a
       * separate signal here would let an unrelated unmount cancel a mint whose token the in-flight
       * send is about to need.
       *
       * Two requests and no more: the mint, then the thread. Neither carries an `Idempotency-Key`,
       * so neither may be retried by anything — which is what the promise guard is for.
       */
      const session = await mintPlaygroundSession(orgId, botId);
      const credential = chatSessionCredential(session.token);

      const conversation = await openRuntimeConversation(
        credential,
        declaredLocale(),
        new AbortController().signal,
      );

      const result = { conversationId: conversation.id, credential };
      opened = result;
      return result;
    })();

    opening = attempt;
    try {
      return await attempt;
    } finally {
      opening = null;
    }
  };

  const reMint = async (): Promise<{ conversationId: string; credential: Credential }> => {
    // NOTHING OPEN MEANS NOTHING TO REPLACE. Reachable only if a caller re-mints before its first
    // `connect()`, which is a caller bug rather than a state — so it does the honest thing and opens
    // the connection normally rather than minting a bearer with no thread to use it on.
    const current = opened;
    if (current === null) return connect();

    const session = await mintPlaygroundSession(orgId, botId);
    const result = {
      conversationId: current.conversationId,
      credential: chatSessionCredential(session.token),
    };
    opened = result;
    return result;
  };

  return { connect, reMint };
}
