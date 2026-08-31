'use client';

import type { RuntimeBotResource } from '@kb/contracts';
import { useCallback, useEffect, useRef, useState } from 'react';

import { ErrorState, SkeletonLines } from '@/components/states';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import type { Credential } from '@/lib/api/browser';

import { ChatSurface } from './chat-surface';
import {
  bootstrapPublicBot,
  chatSessionCredential,
  declaredLocale,
  mintChatSession,
  openRuntimeConversation,
  submitRuntimeFeedback,
} from './runtime-api';

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *  HOSTED CHAT'S CLIENT HOST — the boundary between a cacheable public shell and a per-visitor one
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `(chat)/c/[publicBotId]/page.tsx` is a server component that fetches nothing per visitor: it
 * renders the column, the theme stylesheet link and this component. Everything past first paint —
 * the bot configuration, the session token, the conversation and the stream — is per-visitor and
 * lives here, in the browser, talking straight to Laravel.
 *
 * ── THERE IS NO `QueryClientProvider` ON THIS SURFACE, AND THAT IS DELIBERATE ────────────────────
 * `(chat)/layout.tsx` says so in as many words: one bot, one anonymous visitor, no organization
 * switch, no cache to namespace. So the handshake is `useState` plus one effect rather than a query,
 * which is also what makes `tanstack-query-table` NN2 ("never at module scope") and NN3 ("never
 * persisted") trivially true here — there is no cache at all, and the bearer never leaves this
 * component's memory.
 *
 * ── THE BOOTSTRAP IS AN EFFECT AND NOTHING ELSE IS, AND THE ASYMMETRY IS THE RULE ───────────────
 * `POST /sdk/v1/bootstrap` WRITES NOTHING — its own controller says so — so running it twice under
 * StrictMode costs one extra read and changes no state anybody can observe. Minting a session and
 * opening a conversation both create server-side records, so both are deferred to the first send,
 * where a user action gates them. The abort on cleanup is what makes even the read honest: a visitor
 * who navigates away mid-flight cancels it rather than resolving into an unmounted tree.
 *
 * ── WHAT A 404 HERE MEANS, AND WHY THIS SCREEN MUST NOT GUESS ───────────────────────────────────
 * Every rejection on `sdk/v1` is byte-identical: unknown bot id, a bot in another organization, a
 * bot that is not live, an absent `Origin`, `Origin: null`, an unlisted origin,
 * `https://<allowed>.evil.com`. That is an anti-enumeration control, not an oversight, so this
 * component renders ONE sentence for all of them and names no cause. The operator-facing detail —
 * that the hosted-chat origin must itself be an ACTIVE domain on the bot — is in `runtime-api.ts`'s
 * docblock, where somebody debugging it will read it, and NOT on a page an anonymous visitor is
 * looking at.
 */

type Handshake =
  | { readonly status: 'loading' }
  | { readonly status: 'ready'; readonly bot: RuntimeBotResource }
  | { readonly status: 'unavailable'; readonly error: unknown };

export function HostedChat({ publicBotId }: { readonly publicBotId: string }) {
  const [handshake, setHandshake] = useState<Handshake>({ status: 'loading' });

  useEffect(() => {
    const controller = new AbortController();

    bootstrapPublicBot(publicBotId, controller.signal)
      .then((bot) => setHandshake({ status: 'ready', bot }))
      .catch((error: unknown) => {
        // An abort is a teardown, not a failure: the component is going away, and setting state on
        // it is the React warning everyone learns to ignore.
        if (controller.signal.aborted) return;
        setHandshake({ status: 'unavailable', error });
      });

    return () => controller.abort();
  }, [publicBotId]);

  if (handshake.status === 'loading') {
    // A skeleton at the loaded layout's shape, so the column does not reflow when the configuration
    // lands. `aria-busy` and `aria-hidden` are `<SkeletonLines>`'s; a skeleton is not announced.
    return (
      <div className="flex flex-1 flex-col justify-center gap-4 px-gutter-sm py-12">
        <SkeletonLines lines={3} />
      </div>
    );
  }

  if (handshake.status === 'unavailable') {
    return <BotUnavailable error={handshake.error} />;
  }

  return <ConnectedChat publicBotId={publicBotId} bot={handshake.bot} />;
}

/**
 * The one failure state, and it says nothing about WHY.
 *
 * `<ErrorState>` maps `error_class` to a sentence and shows the `request_id`; the envelope's own
 * `message` reaches no rendered string. For the 404 the class is `authorization`, whose copy is
 * "You do not have access to this." — accurate for a private bot and misleading for an unlisted
 * origin, which is why the TITLE says the thing that is true of every case and the class-mapped
 * sentence sits under it.
 *
 * NO RETRY AFFORDANCE IS FORCED: `<ErrorState>` gates it on the envelope's own `retryable`, so a
 * 404 gets none (retrying cannot change an allow-list) and a 429 gets one.
 */
function BotUnavailable({ error }: { readonly error: unknown }) {
  return (
    <div className="flex flex-1 flex-col justify-center gap-4 px-gutter-sm py-12">
      <ErrorState error={error} title="This chat is not available at this address" />
      <p className="text-base text-muted-foreground">
        If an organization gave you this link, ask them to check that the assistant is still
        published.
      </p>
    </div>
  );
}

/** Mounted only with a real bot configuration. It owns the CREDENTIAL and the CONVERSATION, and
 *  both are created lazily on the first send. */
function ConnectedChat({
  publicBotId,
  bot,
}: {
  readonly publicBotId: string;
  readonly bot: RuntimeBotResource;
}) {
  /**
   * THE BEARER AND THE THREAD ID, IN REFS RATHER THAN STATE.
   *
   * Nothing renders differently because a session has been minted — the composer is enabled either
   * way, because the mint happens inside the send — so holding them in state would re-render the
   * whole transcript on the first send of the conversation for no visual change.
   *
   * A REF ALSO GIVES `connect()` THE PROPERTY IT PROMISES. It must return the SAME pair on every
   * call; reading a `useState` value from a callback captured in an earlier render returns the value
   * as of THAT render, so the second send would mint a second session and open a second thread. A
   * ref is read at call time, which is what "idempotent across calls" actually requires.
   *
   * The token is never persisted and never logged: it is a live credential, `kbw_`-prefixed, and a
   * reload mints a new one. That costs one request and is the only design in which closing a laptop
   * does not leave a bearer on the disk.
   */
  const openedRef = useRef<{
    readonly conversationId: string;
    readonly credential: Credential;
  } | null>(null);
  /**
   * The IN-FLIGHT handshake, so two rapid sends cannot mint two sessions and open two threads.
   *
   * A boolean guard would not do it: the window is between the first `await` and the ref being
   * written, and a second caller arriving inside it must WAIT for the first rather than skip. Holding
   * the promise is what makes the second caller await the same work.
   */
  const openingRef = useRef<Promise<{
    readonly conversationId: string;
    readonly credential: Credential;
  }> | null>(null);

  const connect = useCallback(async () => {
    const opened = openedRef.current;
    if (opened !== null) return opened;
    const pending = openingRef.current;
    if (pending !== null) return pending;

    const opening = (async () => {
      /**
       * NOT WIRED TO A CLEANUP SIGNAL, DELIBERATELY.
       *
       * This runs inside a send whose own `AbortController` belongs to the composer's Stop button. A
       * separate signal here would let an unrelated unmount cancel a mint whose token the in-flight
       * send is about to need, and `AbortSignal.timeout()` would be worse for the reason
       * `stream-answer.ts` gives about the stream itself: a total-duration abort kills healthy work
       * at a fixed elapsed time and never reproduces on a fast connection.
       *
       * Two requests and no more: the mint, then the thread. Neither carries an `Idempotency-Key`,
       * so neither may be retried by anything — which is what the promise guard above is for.
       */
      const session = await mintChatSession(publicBotId, new AbortController().signal);
      const credential = chatSessionCredential(session.token);

      const conversation = await openRuntimeConversation(
        credential,
        declaredLocale(),
        new AbortController().signal,
      );

      const result = { conversationId: conversation.id, credential };
      openedRef.current = result;
      return result;
    })();

    openingRef.current = opening;
    try {
      return await opening;
    } finally {
      // Cleared EITHER WAY. Holding a rejected promise here would make every later send re-throw the
      // first failure without ever retrying the handshake — a chat permanently broken because one
      // request failed once, with the same class-mapped sentence for ever.
      openingRef.current = null;
    }
  }, [publicBotId]);

  const onFeedback = useCallback(
    (messageId: string, verdict: 'up' | 'down', reason?: string) => {
      const opened = openedRef.current;
      // Unreachable in practice — the thumbs are gated on `turn.messageId`, which only exists after
      // a stream, which only happens after `connect()` — and it is a guard rather than an assertion
      // because the alternative is a `!` that would still compile the day the gate moves.
      if (opened === null) return;

      // FIRE AND FORGET, AND NO RETRY. A verdict is not worth interrupting a reader for, and a
      // failed one must not be replayed: the endpoint UPDATES rather than appends, so the server
      // side of a retry is harmless and the retry LOOP would still be traffic nobody asked for. The
      // failure is swallowed deliberately rather than rendered — there is no action a reader could
      // take, and a red banner over a thumbs-up is a worse outcome than a lost thumbs-up.
      void submitRuntimeFeedback(
        opened.credential,
        messageId,
        verdict === 'up' ? 'positive' : 'negative',
        reason ?? null,
      ).catch(() => {});
    },
    [],
  );

  /**
   * ── A CONSENT-REQUIRED BOT IS NOT CHATTABLE FROM HERE, AND THIS SAYS SO RATHER THAN FAKING IT ──
   *
   * `consent_required` is true when the bot collects end-user data AND has text to show, and
   * `RuntimeConversationResource.consent_granted_at` is the record that it was agreed to. But
   * `POST /rt/v1/conversations` accepts NO consent field — `StoreRuntimeConversationRequest`'s whole
   * rule set is `{locale?}` — so there is no endpoint by which this client can record agreement.
   *
   * Showing the text and opening the thread anyway would produce a row whose `consent_text_snapshot`
   * is populated and whose `consent_granted_at` is null: a record claiming a visitor was shown a
   * disclosure and never agreed to it. §18.10 treats a transcript as EVIDENCE, so a false record is
   * worse than a missing conversation — and a checkbox that records nothing is the worst of the
   * three, because the visitor believes they answered.
   *
   * The contract this needs is reported with the batch: a `consent_granted` boolean on the create
   * body, or `POST /rt/v1/conversations/{conversation}/consent`.
   */
  const consentBlocks = bot.consent_required;

  return (
    <ChatSurface
      // TENANT-AUTHORED TEXT, as a JSX child in every case. `null` means the client renders its own
      // default, which is a real state rather than a missing one.
      botName={bot.name}
      corpusLine={bot.description ?? undefined}
      suggestions={bot.starter_questions}
      connection={consentBlocks ? null : { connect }}
      onFeedback={onFeedback}
      notConnected={consentBlocks ? <ConsentPending bot={bot} /> : undefined}
    />
  );
}

function ConsentPending({ bot }: { readonly bot: RuntimeBotResource }) {
  return (
    <Alert variant="info">
      <AlertTitle>This assistant needs your agreement before it can start</AlertTitle>
      <AlertDescription>
        {/* OPERATOR-AUTHORED TEXT and a JSX child, never markup. */}
        {bot.consent_text ??
          'This assistant records the conversation, and there is no way to agree to that here yet.'}
      </AlertDescription>
    </Alert>
  );
}
