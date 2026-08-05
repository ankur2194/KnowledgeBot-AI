# The stream read loop — reference

Depth for `nextjs-app-router`. Spec: `docs/04-functional-channels-chat.md` §8.18–8.19, `docs/12-api-areas.md` §17.1–17.2.
The wire it reads is `kb-internal-api-contracts`; the credential it carries is `laravel-sanctum-auth`; the frame parser and `KbError` it imports are `packages/contracts` runtime code, never re-implemented here.

```ts
// apps/web/src/features/chat/stream-answer.ts — the ONE place any surface reads a chat stream.
import 'client-only';
// Runtime imports, not types: `parseFrame` and `KbError` are real code in packages/contracts and
// there is exactly one copy of each in the monorepo (preact-vite-library, expo-react-native).
import { KbError, parseFrame, parseRetryAfter } from '@kb/contracts';
// The approved client-facing subset of kb-internal-api-contracts. `provider.usage`,
// `retrieval.trace` and `provider.fallback` are internal-only and never appear here.
import type { KbEvent, ChatSendBody } from '@kb/contracts';

const FRAME = /\r\n\r\n|\n\n|\r\r/;            // SSE frame separator; proxies do rewrite line endings

// TWO SURFACES, TWO ORIGINS, TWO CREDENTIALS (laravel-sanctum-auth: four client classes, four
// mechanisms, no fifth). The admin console is app.<domain> and proves identity with the session
// cookie plus the XSRF header; hosted chat is chat.<domain>, where that cookie does not exist by
// design, and proves identity with the same opaque chat-session token the widget uses. One
// hard-coded `credentials: 'include'` serves the first and sends nothing at all on the second.
export type ChatCredential =
  | { kind: 'admin_session' }                    // app.<domain>: cookie + X-XSRF-TOKEN, no bearer
  | { kind: 'chat_session'; token: string };     // chat.<domain>: bearer, no cookie, no CSRF header

function authHeaders(credential: ChatCredential): Record<string, string> {
  if (credential.kind === 'chat_session') return { Authorization: `Bearer ${credential.token}` };
  // Laravel URL-encodes XSRF-TOKEN into the cookie; echoing it raw fails CSRF as a 419, which
  // the SPA renders as a generic error rather than a 401 (laravel-sanctum-auth).
  return {
    'X-XSRF-TOKEN': decodeURIComponent(
      document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1] ?? '',
    ),
  };
}

export async function* streamAnswer(opts: {
  conversationId: string;
  body: ChatSendBody;          // {client_message_id, content} — kb-internal-api-contracts owns those
  credential: ChatCredential;  //   two names. `text` is the token event's field, not the request's.
  signal: AbortSignal;         //   client_message_id is the idempotency fingerprint: a double-send
  apiOrigin?: string;          //   must not become two provider calls.
}): AsyncGenerator<KbEvent> {
  // apiOrigin defaults to the build-time constant. The ONLY caller that passes it is the SSE
  // fixture server in `vitest-playwright`; app code never does, and CI greps for that.
  const { conversationId, body, credential, signal, apiOrigin = API_ORIGIN } = opts;
  const res = await fetch(`${apiOrigin}/api/v1/chat/${conversationId}/messages`, {
    method: 'POST', signal,
    // Cookies only where a cookie exists. app.<domain> → api.<domain> is cross-ORIGIN, so the
    // admin surface needs `include`; on chat.<domain> `include` is not wrong, it is empty — which
    // is precisely why a missing Authorization there survives review and every local dev run.
    credentials: credential.kind === 'admin_session' ? 'include' : 'omit',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'text/event-stream',
      ...authHeaders(credential),
    },
    body: JSON.stringify(body),
  });

  // A failure is the JSON envelope, not SSE. Branch on error_class — never on res.status, which
  // differs per surface for one class (kb-error-taxonomy).
  if (!res.ok || !res.body) {
    const envelope = await res.json().catch(() => null);
    // `error_class` is null when no envelope parsed — NOT an invented class name. The taxonomy is
    // closed at 18; a client-side parse failure is not one of them, and mislabelling it
    // `internal_dependency` would page someone for a malformed response. Null means unknown, and
    // unknown is permanent: `tanstack-query-table`'s retry predicate treats it as non-retryable.
    // Retry-After comes off the RESPONSE HEADER; it is not in the envelope, and dropping it makes
    // the retry ladder ignore a 429's own instruction.
    throw new KbError(
      envelope?.error_class ?? null,
      envelope?.retryable ?? false,
      parseRetryAfter(res.headers.get('retry-after')),
      envelope?.request_id ?? null,
      envelope?.message,
    );
  }

  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '', sawTerminal = false;
  try {
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      // {stream:true} keeps a UTF-8 codepoint split across two chunks intact. Without it the answer
      // grows U+FFFD at random boundaries — invisible in any English-only test.
      buffer += decoder.decode(value, { stream: true });
      // A network chunk can end mid-frame: consume only complete frames, keep the remainder.
      for (let m: RegExpExecArray | null; (m = FRAME.exec(buffer)); ) {
        const event = parseFrame(buffer.slice(0, m.index));   // shared; `: ping` returns null
        buffer = buffer.slice(m.index + m[0].length);
        if (!event) continue;
        if (event.name === 'message.complete' || event.name === 'error') sawTerminal = true;
        yield event;
      }
    }
    // Exactly one terminal event per stream is the contract. EOF without one means the connection
    // died mid-answer: surface stream_lost and offer Retry. Never reconnect with Last-Event-ID.
    if (!sawTerminal) throw new KbError('stream_lost', true);
  } finally {
    // Closing the socket is what makes Laravel's connection_aborted() fire → FastAPI sees
    // http.disconnect → the provider call is cancelled and usage is finalized as
    // finish_reason:"cancelled". Skip this and the stop button leaves a billing tail.
    void reader.cancel().catch(() => {});
  }
}
```

Callers pick the credential from the route group, never from a runtime guess: `app/(admin)/` passes `{ kind: 'admin_session' }`, `app/(chat)/[publicBotId]/` passes the token it got from the chat-session mint. A bot in password-protected or authenticated-organization mode (§8.19) still mints a chat-session token — the gate changes who may mint one, not which credential the stream carries.
