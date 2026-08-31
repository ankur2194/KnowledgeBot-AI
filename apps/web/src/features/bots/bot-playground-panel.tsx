'use client';

import type { RetrievalTraceData } from '@kb/contracts/admin';
import { toKbAdminEvent } from '@kb/contracts/admin';
import { useCallback, useMemo, useState } from 'react';

import { DegradedNote } from '@/components/states';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ChatSurface, type ChatConnection } from '@/features/chat/chat-surface';

import { useBotEditor } from './bot-editor-context';
import { createPlaygroundConnection, type PlaygroundConnection } from './playground-session';
import { RetrievalTracePanel } from './playground-trace';

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *  D5 — THE PLAYGROUND, AS THE FOURTH TAB OF `/bots/{botId}` AND NOT AS A NAV ITEM
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * A tab and not a route, because the playground is a VIEW OF ONE BOT rather than a place: it needs
 * the bot's saved configuration to say what a run used, and the three sibling tabs are where that
 * configuration is set. A nav item would need a bot picker, which is the bot list with extra steps.
 *
 * ── IT RENDERS THE SAME CHAT, IN A CARD ─────────────────────────────────────────────────────────
 * `<ChatSurface>` verbatim — the same turns, the same four visible states, the same sanitizer, the
 * same autoscroll and Stop. `kb-ai-chat-ux`'s surface-delta table gives the playground exactly two
 * differences from hosted chat: it sits inside the admin shell in a card, and it shows retrieval
 * detail beside the sources panel. Everything else being identical is the point — four surfaces
 * render THE SAME CHAT, not four interpretations of one.
 *
 * ── THE ONE THING THAT DIFFERS ON THE WIRE: A WIDER FRAME DECODER ───────────────────────────────
 * `connection.decode` is `toKbAdminEvent` from `@kb/contracts/admin` — the subpath `apps/widget`
 * does not import. It composes `toKbEvent` rather than forking it, so there is still ONE frame
 * parser and ONE public allow-list; what it adds is a single event name. The other three internal
 * frames (`provider.usage`, `provider.fallback`, `heartbeat`) are refused here exactly as they are
 * everywhere else, and it needed NO CHANGE when the server started forwarding the trace: the
 * server asserts that half, `playground-stream.test.ts` asserts this half.
 *
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *  THE CREDENTIAL, AND WHY THE ADMIN COOKIE IS NOT IT
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * This panel used to hand `sessionCredential()` — cookie plus `X-XSRF-TOKEN`, no bearer — straight
 * to `openRuntimeConversation`, and `ResolveChatSession::handle()` is
 * `$this->sessions->resolve($request->bearerToken())` and nothing else, so every send answered 401
 * `authentication`. THAT MIDDLEWARE IS UNCHANGED and still resolves exactly one credential type; the
 * four-mechanisms rule is intact. What landed is an ADMIN route that issues that one type to an
 * administrator — `POST /api/v1/organizations/{organization}/bots/{bot}/playground-session`, behind
 * `bots.manage` — whose stored record carries `kind: playground`, from which the server derives
 * `actorType: User` and `diagnostics: true`. Both conjuncts of `ClientEvents::allows()` are
 * therefore satisfied for `retrieval.trace` and for no other internal frame.
 *
 * `features/bots/playground-session.ts` owns the mint, the envelope unwrap and the re-mint; this
 * file owns what the operator sees. The token is never in this module's props, its state or its
 * render output.
 */
export function BotPlaygroundPanel() {
  const { orgId, botId } = useBotEditor();

  /**
   * THE KEY IS THE WHOLE BODY OF THIS COMPONENT, AND IT IS NOT A TIDINESS HABIT.
   *
   * The panel below holds a LIVE CHAT-SESSION BEARER and an open conversation in component state,
   * and neither is re-derivable from a prop. The other three tabs read the context on every render,
   * so a `botId` change simply re-renders them — but navigating from bot A to bot B keeps THIS
   * component mounted (same route pattern, same selected tab), and without the key the next send
   * would run bot B's question down bot A's thread on bot A's credential.
   *
   * A key here rather than at the shell's call site so the rule lives beside the state it protects:
   * `bot-editor-screen.tsx` renders every panel with no props at all, and a key that must be
   * maintained in a file the panel does not own is a key that gets dropped in the refactor that
   * adds a fifth tab.
   */
  return <PlaygroundPanel key={`${orgId}:${botId}`} />;
}

function PlaygroundPanel() {
  const { orgId, botId, bot, canManage } = useBotEditor();

  /**
   * THE TRACES, KEYED BY THE CLIENT-MINTED TURN ID.
   *
   * Keyed rather than kept as "the latest", because a reviewer comparing two phrasings is the whole
   * point of a playground: overwriting on every send would make the second run destroy the evidence
   * for the first. The id is the same one that reconciles the user turn with its answer, so a trace
   * and the turn it explains cannot drift apart.
   *
   * STATE AND NOT A REF, because the panel below renders from it. It is one write per turn — not per
   * token — so it costs one render per answer.
   */
  const [traces, setTraces] = useState<ReadonlyMap<string, RetrievalTraceData>>(new Map());
  const [lastTurnId, setLastTurnId] = useState<string | null>(null);

  const onTrace = useCallback((turnId: string, trace: RetrievalTraceData) => {
    setTraces((current) => new Map(current).set(turnId, trace));
    setLastTurnId(turnId);
  }, []);

  /**
   * THE MINT, THE CONVERSATION AND THE RE-MINT, IN ONE CLOSURE HELD FOR THE LIFE OF THIS PANEL.
   *
   * ── A LAZY `useState` AND NOT `useMemo`, AND NOT A REF ────────────────────────────────────────
   * `useMemo` is a performance hint: React is permitted to discard a memo and recompute it, and
   * recomputing THIS one throws away a live bearer and an open thread — the next send would mint a
   * second session and open a second conversation, billing one operator's test run to two rows in
   * the tenant's own analytics. State is guaranteed to survive a re-render, which is the property
   * actually required. A ref would do too and `react-hooks/refs` correctly refuses it: a ref read
   * during render is the shape that silently stops updating, and this value IS read during render
   * (the `chat` object below is a prop).
   *
   * IT IS NEVER RE-CREATED IN PLACE. The initializer runs once per mounted instance, and the
   * exported wrapper above keys this component on the bot — so a bot change discards the instance
   * rather than swapping a field inside it. `orgId` and `botId` are therefore captured once here and
   * are correct for the whole life of the closure.
   *
   * The bearer lives inside that closure and never in a state VALUE anything renders: nothing looks
   * different because a session has been minted, since the mint happens inside the send.
   */
  const [connection] = useState<PlaygroundConnection>(() =>
    createPlaygroundConnection(orgId, botId),
  );
  const { connect, reMint } = connection;

  /**
   * ── WHY THE COMPOSER IS OFFERED ON TWO CONDITIONS AND NOT ONE ─────────────────────────────────
   *
   * `canManage` is the permission half and was always here. The status half is new and is a
   * consequence of the mint being real: `PlaygroundSessionController` refuses a bot that is not
   * `testing` or `published` with a 409, and `bootstrap/app.php` renders every deliberate 409 as
   * `internal_dependency` — whose copy is "Something on our side is unavailable. Try again
   * shortly." That sentence is false and unactionable for a `draft` bot, and it is the same class of
   * mistake `ERROR_COPY.authentication` would have been before the re-mint landed.
   *
   * IT IS AN AFFORDANCE AND NOT AUTHORIZATION. The server refuses whatever this renders, the check
   * is a pure function of the row already on screen (`BotStatus::isPlaygroundReachable()`), and a
   * row that went stale under us still fails at the server — it does not pre-judge anything the
   * client cannot compute, which is the line `updateBotStatus`'s publish guard draws.
   */
  const reachable = bot.status === 'testing' || bot.status === 'published';

  const chat = useMemo<ChatConnection | null>(
    () => (canManage && reachable ? { connect, decode: toKbAdminEvent, reMint } : null),
    [canManage, reachable, connect, reMint],
  );

  const latestTrace = lastTurnId === null ? undefined : traces.get(lastTurnId);

  return (
    <div className="flex flex-col gap-6">
      <SavedConfiguration />

      <Card>
        <CardHeader>
          <CardTitle as="h3">Ask this bot something</CardTitle>
          <CardDescription>
            Runs against the bot&rsquo;s saved configuration, in this organization, as you. Turns
            taken here are recorded like any other conversation.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {/* The chat column, inside a card — `kb-ai-chat-ux`'s playground row. A fixed height so the
              transcript scrolls INSIDE its own box rather than scrolling the settings page under it,
              which is also what keeps the retrieval panel reachable without a long scroll back. */}
          <div className="flex h-[32rem] flex-col">
            <ChatSurface
              // TENANT-AUTHORED TEXT as a JSX child. The playground gets no tenant THEMING —
              // `kb-ai-chat-ux`'s surface table is explicit about that — because the console chrome
              // must stay neutral and a brand-coloured panel inside the admin shell is the one place
              // rule 3 breaks visibly.
              botName={bot.name}
              corpusLine={bot.description ?? undefined}
              connection={chat}
              onTrace={onTrace}
              // NO `onFeedback`, AND THE SERVER AGREES RATHER THAN MERELY TOLERATING IT. The
              // playground bearer carries `chat:send` and `chat:read` and deliberately not
              // `feedback:submit` (`WidgetSessionService::PLAYGROUND_ABILITIES`), so a thumb here
              // would be refused by `ChatGate` rather than caught in review — an operator rating
              // their own test run would otherwise put a reviewer's thumb into the satisfaction
              // figure §8.23 reports from real readers. `AnswerActions` renders no thumbs at all
              // without the handler, which is the honest shape rather than two buttons that swallow
              // a click.
              notConnected={
                canManage ? <NotRunnableNotice status={bot.status} /> : <ReadOnlyNotice />
              }
            />
          </div>
        </CardContent>
      </Card>

      {latestTrace === undefined ? (
        <NoTraceYet hadTurn={lastTurnId !== null} />
      ) : (
        <RetrievalTracePanel data={latestTrace} />
      )}
    </div>
  );
}

/**
 * THERE IS NO STANDING NOTICE ON THIS PANEL, AND ITS ABSENCE IS THE CHANGE RATHER THAN AN OMISSION.
 *
 * A `RuntimeContractNotice` used to sit above the composer naming two server-side gaps — that
 * `rt/v1` accepted no administrator credential, and that `retrieval.trace` was refused to every
 * client. Both are closed: `POST .../bots/{bot}/playground-session` issues the credential, and
 * `StreamChatMessageController` reads `diagnostics: $session->diagnostics` instead of a hard-coded
 * `false`. A notice about a gap that no longer exists is worse than no notice — it teaches an
 * operator to distrust a panel that works, so the next real warning is read as decoration too.
 *
 * What replaced it is nothing at all on the success path, and two SPECIFIC states below where a send
 * genuinely cannot happen.
 */

/**
 * WHAT THIS RUN USES — read-only, and adjacent to the three tabs that set it.
 *
 * ── THERE IS NO TEMPORARY MODEL OVERRIDE, AND A DISABLED CONTROL WOULD BE WORSE THAN NONE ───────
 * The brief for this tab asks for a temporary override kept *visibly separate from saved bot
 * settings*, and the separation is the easy half — this card is read-only and says "saved" in as
 * many words. The override itself is not expressible: `SendChatMessageRequest` validates exactly
 * `{client_message_id, content}` and its `withValidator()` REJECTS an `extra` key, so a body
 * carrying a model id 422s every send. `routes/api_public.php` states the two-key rule as a pinned
 * contract shared with three shipped clients.
 *
 * `ConfigSnapshot`'s docblock names the intent — *"the playground run a temporary override without
 * mutating anything"* — so the server side of this is designed and unbuilt, which is exactly the
 * shape that gets faked. A select that changed a local value and sent nothing would be the worst
 * available outcome: an operator would compare two models, read two answers from the same one, and
 * conclude something false about their configuration.
 *
 * So the card renders the SAVED configuration and says the run uses it. The contract required is
 * reported with the batch.
 */
function SavedConfiguration() {
  const { bot } = useBotEditor();

  return (
    <Card>
      <CardHeader>
        <CardTitle as="h3">This run uses the bot&rsquo;s saved settings</CardTitle>
        <CardDescription>
          Nothing on this tab changes them, and a temporary override is not available yet — every run
          below uses exactly what the Model &amp; retrieval tab has stored.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-2 xl:grid-cols-4">
          <SavedPair label="Answer mode" value={bot.answer_mode} />
          <SavedPair
            label="Model"
            // A ULID and not a name: the bot row publishes the id, and resolving it to a display
            // name is the Model tab's query rather than a second copy of it here.
            value={bot.provider_model_id ?? 'not configured'}
            mono={bot.provider_model_id !== null}
          />
          <SavedPair label="Dense top-k" value={String(bot.dense_top_k)} />
          <SavedPair label="Sparse top-k" value={String(bot.sparse_top_k)} />
          <SavedPair label="Rerank candidates" value={String(bot.rerank_candidates)} />
          <SavedPair label="Rerank retain" value={String(bot.rerank_retain)} />
          <SavedPair
            label="Evidence threshold"
            // `null` WITH NO PLATFORM DEFAULT, deliberately: the scale is a property of the
            // (provider, model) pair, so there is no number that means "unset but safe". Saying
            // "not set" is the honest rendering; a `0` would be a real threshold nobody chose.
            value={
              bot.evidence_threshold === null
                ? 'not set'
                : `${bot.evidence_threshold} (${bot.evidence_threshold_scale ?? 'unknown scale'})`
            }
          />
          <SavedPair
            label="General answers"
            value={bot.allow_general_answers ? 'allowed' : 'refused'}
          />
        </dl>
      </CardContent>
    </Card>
  );
}

function SavedPair({
  label,
  value,
  mono = false,
}: {
  readonly label: string;
  readonly value: string;
  readonly mono?: boolean;
}) {
  return (
    <div className="flex min-w-0 flex-col gap-0.5">
      <dt className="text-caption text-muted-foreground uppercase">{label}</dt>
      <dd className={mono ? 'truncate font-mono text-sm' : 'truncate text-base'} title={value}>
        {value}
      </dd>
    </div>
  );
}

/**
 * THE PANEL'S OWN EMPTY STATES, AND THEY ARE TWO DIFFERENT STATEMENTS.
 *
 * Before any turn it is a first-run empty: nothing has been asked, so there is nothing to explain.
 * After a turn that produced no trace it is a DEGRADED note, because the answer arrived and the
 * diagnostics did not — which is a real condition an operator has to be told about rather than an
 * empty card they read as a loading failure. `references/states.md`'s filtered-versus-first-run split
 * applied to a surface with no filter.
 *
 * THE DEGRADED COPY LOST ITS SECOND SENTENCE. It used to add that "the relay forwards diagnostics
 * only to a signed-in actor holding the diagnostics permission, and no path sets that today", which
 * described the closed gap and is now simply untrue: this panel's credential sets both. What is left
 * is the observation itself, which stays a real condition — the frame is emitted by the data plane
 * and a turn that never reached retrieval (a refusal on quota, a provider failure before the search)
 * legitimately produces none.
 */
function NoTraceYet({ hadTurn }: { readonly hadTurn: boolean }) {
  if (!hadTurn) {
    return (
      <Card>
        <CardHeader>
          <CardTitle as="h3">Retrieval detail</CardTitle>
          <CardDescription>
            Ask something above and every candidate this bot retrieved will be listed here, with the
            scores from each search branch and the reason anything was dropped.
          </CardDescription>
        </CardHeader>
      </Card>
    );
  }

  return <DegradedNote>That turn produced an answer and no retrieval detail.</DegradedNote>;
}

/**
 * THE BOT IS RUNNABLE FROM SOMEWHERE ELSE BUT NOT FROM HERE, and it names the move that fixes it.
 *
 * `PlaygroundSessionController` refuses `draft`, `paused` and `archived` with a 409 — `draft` on the
 * authority of its own case comment in `BotStatus`: *"Never reachable from any channel, including
 * the admin playground."* Rendering the composer anyway would produce that 409, and a deliberate 409
 * renders as `internal_dependency`: "Something on our side is unavailable. Try again shortly." That
 * sentence is false, unactionable, and would be blamed on the platform.
 *
 * `archived` is called out separately because it is TERMINAL — there is no un-archive transition, so
 * "move it to testing" is advice that cannot be taken.
 */
function NotRunnableNotice({ status }: { readonly status: string }) {
  return (
    <Alert variant="info">
      <AlertTitle>This bot cannot be run from the playground while it is {status}</AlertTitle>
      <AlertDescription>
        {status === 'archived'
          ? 'An archived bot is read-only, including its status, so there is no way back to a runnable state.'
          : 'Move it to testing or published on the Publishing tab, then come back.'}
      </AlertDescription>
    </Alert>
  );
}

/**
 * `canManage === false` MEANS NO SEND AT ALL, and the same rule the other three panels follow: the
 * shell already says why once, above the tabs.
 *
 * The gate is `bots.manage` rather than a chat permission, and that is a decision worth naming: a
 * playground turn spends the organization's provider quota and writes a conversation row, so it is a
 * WRITE wearing a chat control. `bots.view` is held by all four roles; letting an analyst spend
 * tokens from a settings screen they may only read would be the wrong default in the direction that
 * costs money.
 */
function ReadOnlyNotice() {
  return (
    <Alert variant="info">
      <AlertTitle>You can read this bot but not run it</AlertTitle>
      <AlertDescription>
        A playground turn spends this organization&rsquo;s provider quota, so it needs the same
        permission as changing the bot.
      </AlertDescription>
    </Alert>
  );
}
