---
name: expo-react-native
description: The Expo React Native client in apps/mobile — the streaming chat surface, the Sanctum token in SecureStore, EAS release policy, and deep linking. Use whenever adding a screen or API call under apps/mobile/, wiring token storage or logout, configuring EAS, or when an answer arrives all at once, a backgrounded app keeps billing tokens, or a reinstall is still signed in. Hermes' XHR fetch exposes no ReadableStream and fails silently; streaming uses expo/fetch. Pairs with laravel-sanctum-auth (the credential) and kb-internal-api-contracts (the SSE schema).
---

# Expo React Native Mobile Client

Expo SDK **57** (released 2026-06-30; `expo@57.0.10`) · React Native **0.86.2** · React **19.2** · `expo-router@57.0.10` · `expo-secure-store@57.0.1` · `@tanstack/react-query@5.101.4` · New Architecture only — the Legacy Architecture was dropped in SDK 55, so there is no `newArchEnabled` decision to make.
**Authoritative spec:** docs/04-functional-channels-chat.md §8.21 §8.22, docs/05-tech-stack.md §9.3, docs/12-api-areas.md §17.4, docs/06-architecture.md §11.1, docs/22-spec-findings-and-decisions.md (the `EventSource` row)

## Non-negotiables

1. **The app talks to Laravel and only to Laravel.** No FastAPI host, no `/internal/v1` path, no HMAC key ever ships in a build — a device that could sign an internal request would be a per-device copy of a service credential, and every authorization, quota, and rate-limit check lives on the other side of that seam (`kb-architecture-map`, docs/06 §11.1).
2. **`EventSource` is never used.** Its constructor's only option is `withCredentials`, so it cannot carry `Authorization`, and it is GET-only while sending a message is a POST with a body. Its automatic `Last-Event-ID` reconnect is separately forbidden: token streams are not resumable (`kb-internal-api-contracts`).
3. **Streaming uses the named `expo/fetch` import.** Hermes' XHR-backed `fetch` returns a `Response` whose `body` is `null`; it does not throw, it resolves once with the whole answer. Streaming silently ceases to exist while every assertion about the final text still passes.
4. **The bearer token lives in `expo-secure-store` and nowhere else** — never `AsyncStorage` (plaintext on disk), never a Redux/Zustand snapshot that gets persisted, never a log line, never a URL (`laravel-sanctum-auth` NN 4). SecureStore buys you: encryption at rest under the iOS Keychain and the Android Keystore, and exclusion from Android Auto Backup. It does **not** buy you: protection from a rooted or jailbroken device (the OS keystore hands the value to any process running as the app), from a Frida-style runtime hook, or — at the default `WHEN_UNLOCKED` — from an encrypted device backup restored onto different hardware. That residual is priced by the token's hard `expires_at` and the revocable device list, not by the storage API.
5. **A stream nobody is reading still bills tokens.** Navigating away, backgrounding, and the Stop button all abort the request; the abort is what makes Laravel's write fail, which makes it cancel its upstream call, which makes FastAPI see `http.disconnect` and stop the provider (`kb-internal-api-contracts`). Letting the socket die quietly instead is not equivalent and is not free.
6. **Every cache key and every persisted byte is scoped to the organization and purged on logout** (`kb-tenancy-isolation`). One device serves several people; a TanStack Query key of `['conversations']` hands the previous user's transcripts to the next one after a re-login.
7. **A deep link is untrusted input.** It may select *which* bot or conversation to open. It may never supply an org id, an API origin, or a credential — the app re-resolves the target against Laravel with its own token and renders the public 404 when the answer says so.

## How we use it

```
apps/mobile/
├── app/                     expo-router file routes; (auth)/ and (app)/ groups
│   └── (app)/_layout.tsx    redirects to /login when no valid token — every route here is deep-linkable
├── src/
│   ├── api/client.ts        base URL + Authorization; the ONLY module that reads the token
│   ├── auth/                SecureStore wrapper, expiry arithmetic, logout purge
│   └── features/chat/       stream-answer.ts + use-answer-stream.ts (below)
└── app.config.ts            scheme, associatedDomains, intentFilters, runtimeVersion
```

**Auth mechanism (owned by `laravel-sanctum-auth`, implemented here).** Login returns a Sanctum personal access token with explicit abilities (`chat:send`, `conversations:read`) and a hard `expires_at` — Sanctum has no refresh tokens, so lifetime is a straight trade: longer means a stolen device stays useful longer, shorter means more password prompts. 30 days is the pinned answer, and the thing that makes it affordable is that it is *revocable* — the settings screen lists devices and a revoke deletes that token row, so the blast radius has a kill switch rather than only a clock. Store the token with `keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY`, not the default; store `expires_at` beside it and treat the token as absent once `expires_at - now < 90s`.

**Token expiry never interrupts an open stream.** Sanctum authenticates once, when the request starts, so a stream that opened is safe for its whole life; expiry lands on the *next* request. That is what the pre-flight check above is for: if the token cannot outlive a full chat deadline (60 s, `kb-error-taxonomy`), route to login *before* the POST, so the user does not lose a composed message to a 401. If a 401 or `error_class: authentication` arrives anyway — the token was revoked from another device — clear SecureStore, purge the query cache, keep the drafted text in memory, navigate to login, and restore the draft afterwards. Never retry the send: `authentication` is non-retryable, and a retry loop against an expired token is how you get a device rate-limited out of its own login.

### The stream read loop

```ts
// apps/mobile/src/features/chat/stream-answer.ts — the ONE place this app reads a chat stream.
// Named import, deliberately: since SDK 56 expo/fetch IS globalThis.fetch on iOS and Android, but
// EXPO_PUBLIC_USE_RN_FETCH=1 anywhere in the env restores Hermes' XHR fetch and `res.body` becomes
// null. The named form cannot be switched off, and it is what CI greps for.
import { fetch } from 'expo/fetch';
// Runtime imports from the shared package — `parseFrame` and `KbError` are real code there, not
// types, and this app forks neither. A second `KbError` breaks `instanceof` and every retry
// decision made against it (`nextjs-app-router`, `tanstack-query-table`).
import { KbError, parseFrame, parseRetryAfter } from '@kb/contracts';
import type { KbEvent, ChatSendBody } from '@kb/contracts';

const FRAME = /\r\n\r\n|\n\n|\r\r/;   // SSE frame separator — proxies do rewrite line endings
const IDLE_MS = 45_000;               // three missed 15 s `: ping`s. NOT AbortSignal.timeout — see gotchas

export async function* streamAnswer(
  conversationId: string,
  body: ChatSendBody,        // {client_message_id, content} — kb-internal-api-contracts owns both
  token: string,             // names; `text` is the token event's field, not the request's, and
  signal: AbortSignal,       // posting it 422s every send on this client and no other.
): AsyncGenerator<KbEvent> {
  const res = await fetch(`${API_ORIGIN}/api/v1/chat/${conversationId}/messages`, {
    method: 'POST',                          // exactly why EventSource is unusable: GET-only, no headers
    headers: { Authorization: `Bearer ${token}`, Accept: 'text/event-stream',
               'Content-Type': 'application/json' },
    body: JSON.stringify(body),
    signal,                                  // aborting closes the socket → Laravel cancels upstream →
  });                                        // FastAPI sees http.disconnect → the provider call stops
  // A failure is the JSON error envelope, not SSE. Branch on error_class, never on the status: one
  // class renders as different statuses per surface (kb-error-taxonomy, the 403/404 footnote).
  if (!res.ok || !res.headers.get('content-type')?.startsWith('text/event-stream')) {
    const env: any = await res.json().catch(() => ({}));
    // Argument order is packages/contracts': (error_class, retryable, retry_after, request_id,
    // message). retry_after comes off the HEADER, never the envelope; drop it and a 429's own
    // instruction is ignored and the ladder retries inside the window it was told to wait.
    throw new KbError(env.error_class ?? null, env.retryable ?? false,   // null = unknown, and unknown is permanent. NOT 'internal_dependency': that class is retryable and pages, so a malformed response would wake someone up.
                      parseRetryAfter(res.headers.get('retry-after')), env.request_id ?? null, env.message);
  }
  if (!res.body) throw new KbError(null, false, null, null, 'no ReadableStream — this is not expo/fetch')   // our own client bug, not a dependency failure;

  const reader = res.body.getReader();
  const decoder = new TextDecoder();          // provided by the `expo` package's WinterCG runtime; UTF-8 only
  let buffer = '', sawTerminal = false, idle: ReturnType<typeof setTimeout>;
  const bump = () => { clearTimeout(idle); idle = setTimeout(() => void reader.cancel(), IDLE_MS); };

  try {
    bump();
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      bump();                                 // any byte proves the path is alive, including `: ping`
      // {stream:true} is load-bearing: without it a UTF-8 codepoint split across two TCP chunks is
      // decoded as U+FFFD. English looks perfect; Hindi, German and emoji rot in proportion to length.
      buffer += decoder.decode(value, { stream: true });
      for (let m = FRAME.exec(buffer); m; m = FRAME.exec(buffer)) {
        const frame = buffer.slice(0, m.index);
        buffer = buffer.slice(m.index + m[0].length);
        const ev = parseFrame(frame);         // shared; returns null for `: ping`, an SSE comment
        if (!ev) continue;
        if (ev.name === 'error') throw new KbError(ev.data.error_class, ev.data.retryable,
                                                   null, null, ev.data.message);  // the SSE error
        //   frame carries three keys; request_id and Retry-After exist only on the HTTP envelope
        if (ev.name === 'message.complete') sawTerminal = true;
        yield ev;                             // citations always arrive before the first token
      }
    }
    // Exactly one terminal event per stream is the contract. EOF without one means the connection died
    // mid-answer. Do NOT re-POST — that re-runs a paid provider call. Surface stream_lost; the caller
    // re-reads the persisted message from Laravel and only then offers Retry with a NEW client_message_id.
    if (!sawTerminal) throw new KbError('stream_lost', true);
  } catch (e: any) {
    // Stop, blur and background all land here as AbortError. Cancellation is an outcome (499,
    // user_cancellation), not a failure: no toast, no retry, no error span.
    if (e?.name === 'AbortError') return;
    throw e;
  } finally {
    clearTimeout(idle!);
    await reader.cancel().catch(() => {});    // an early `return` from the generator must still close the socket
  }
}
```

`parseFrame` is **not** defined here. It lives in `packages/contracts` as runtime code — the WHATWG field rules (`event`, multi-line `data`, exactly one leading space stripped, a leading `:` meaning comment-not-event) are one contract, and three clients implementing it three times is three chances to disagree about `: ping`. Import it; never fork it.

```ts
// apps/mobile/src/features/chat/use-answer-stream.ts — the three cancel sources, one controller.
export function useAnswerStream() {
  const ctrl = useRef<AbortController | null>(null);
  const stop = useCallback(() => ctrl.current?.abort(), []);
  useFocusEffect(useCallback(() => stop, [stop]));            // navigating away cancels
  useEffect(() => {                                          // iOS suspends the socket on background
    const sub = AppState.addEventListener('change', s => { if (s !== 'active') stop(); });
    return () => sub.remove();                               // anyway — the explicit abort is what tells
  }, [stop]);                                                // the server, so the tokens stop being billed
  /* start(): ctrl.current = new AbortController(); for await (const ev of streamAnswer(…, ctrl.current.signal)) … */
}
```

**TanStack Query owns REST, never the stream.** Bots, conversation lists and history are queries, keyed `['org', orgId, …]`. Sending a message is not a query and not a mutation with retries: set `retry: false`, because a retried POST with a fresh `client_message_id` is a second billed generation, and with the same id it replays a stored response the user has already seen. Offline history (docs/04 §8.21, later) persists to `expo-sqlite` in the app's private directory — not SecureStore, whose values have historically been rejected above ~2 KB — under a cache key carrying the org id, wiped on logout.

### Build and release

EAS Build with three profiles (`development` dev-client, `preview` internal distribution, `production` store). `runtimeVersion: { policy: "fingerprint" }` — it hashes everything that affects the native runtime, so adding a native module without a rebuild becomes a build-time error instead of a launch-time crash loop on real devices.

**EAS Update is appropriate here, but only with code signing enabled.** An OTA channel into a process holding a bearer token is a remote-code-execution path; without signing its trust anchor is our EAS account rather than store review, and a compromised publish would ship JS that reads the token and posts it anywhere. With `expo-updates codesigning:generate`, the certificate is embedded in the binary and the private key stays out of the repo and out of ordinary CI, so neither EAS nor a CDN can substitute a payload. It is off by default and gated behind a paid EAS plan — budget for it or do not ship OTA at all. <!-- UNVERIFIED: the plan gate was read from the code-signing doc page, not from the pricing page --> Roll out staged (`--rollout-percentage`) and rehearse `eas update:rollback`, because a bad JS update on this client can lock every user out of the login screen itself.

**What an OTA update must never be able to change**, and the enforcement that makes that true rather than aspirational: the API origin and the TLS posture. `EXPO_PUBLIC_*` values are inlined into the JS bundle, so an update *does* carry its own copy of the base URL — the real constraint is native. Pin the allowed origin in iOS ATS (`NSExceptionDomains`, `NSExceptionAllowsInsecureHTTPLoads: false`) and Android `network_security_config.xml` via a config plugin; both live in the binary and a JS-only update cannot touch them, so a substituted bundle cannot ship the token to a new host. <!-- UNVERIFIED: that these two files are reachable from an Expo config plugin under CNG was not re-checked against the SDK 57 config-plugin docs --> Alongside that: never ship a "server URL" debug screen or a `__DEV__` plaintext-HTTP escape hatch in a release build — either one hands a phishing host a live bearer token. And any diff touching `src/auth/**` or `src/api/client.ts` ships as a **build**, not an update.

### Deep linking

Custom schemes are for in-app navigation and dev only: on Android any app may register the same scheme, and on iOS the winner is undefined, so an external link over a scheme can be intercepted. **Every externally-originating link is a verified Universal Link / App Link** on our own domain — `associatedDomains` + `apple-app-site-association`, `intentFilters` with `autoVerify: true` + `assetlinks.json` carrying the production package name and release SHA-256 fingerprint. In particular, an auth callback over a custom scheme would be a token in a URL, which `laravel-sanctum-auth` NN 4 bans outright.

Expo Router makes **every** route deep-linkable automatically, so a link is a way into any screen you ever add. `app/(app)/_layout.tsx` is the gate: no valid token ⇒ redirect to `/login`, remember the intended href, resume after auth. A cold-start deep link that renders a stale cached screen before that check runs is a real leak; render nothing until the token has been read from SecureStore.

**Not defined here.** Token minting, abilities, revocation, the device list, org resolution → `laravel-sanctum-auth`. SSE event names, the error envelope, idempotency keys, cancellation semantics on the server → `kb-internal-api-contracts`. Error classes and retry policy → `kb-error-taxonomy`. Span and metric names → `kb-observability-conventions`. Query-key shape, retry policy and optimistic updates → `tanstack-query-table` (written for `apps/web`; the key namespacing and the single-source retry rule apply here unchanged). The browser's copy of this read loop → `nextjs-app-router` (`streamAnswer`). The frame parser, the client event union and `KbError` are **not** copies: there are three clients now, so they live in `packages/contracts` as real runtime code and this app imports them (`admin-web-engineer` owns that package; import it, never fork it).

## Gotchas

- **The answer appears all at once, several seconds late, and every test passes.** The request went through Hermes' XHR-backed `fetch`, whose `Response.body` is `null` — no throw, no warning, just one resolve at the end. Causes: `EXPO_PUBLIC_USE_RN_FETCH=1` left in a `.env`, or a fetch wrapper (Sentry, an API-client library, a mock) that closed over the global before `expo/fetch` installed itself. Import `fetch` from `expo/fetch` by name and assert `res.body !== null`; a test that only checks the final text can never catch this.
- **Cancel works in the UI and the bill says otherwise.** `AbortController.abort()` on a *streaming* `expo/fetch` request did not actually tear down the native request until the fix in expo/expo#33577 — the JS stopped reading, the socket stayed open, and the provider kept generating. It is fixed on SDK 57, but this is the one behaviour to verify end-to-end rather than assume: assert that an abort produces a server-side `finish_reason: "cancelled"` and a `user_cancellation` record, not just that the UI stopped.
- **Long answers show `` for accented characters, emoji and any non-Latin script, and short ones are fine.** `decoder.decode(value)` without `{ stream: true }` treats each chunk as a complete input and discards the trailing bytes of a codepoint that straddled the chunk boundary. Corruption scales with chunk count, so it surfaces exactly on the answers users read most carefully. Hermes' `TextDecoder` (shipped in the `expo` package since SDK 52) is UTF-8-only and not fully spec-compliant — fine for our wire, not for arbitrary encodings.
- **`JSON.parse` throws mid-answer, or tokens go missing.** A chunk is a TCP-sized read, not an SSE frame: one frame routinely spans two chunks and one chunk routinely carries three frames. Buffer and split on the frame separator; never parse a chunk. Match `\r\n\r\n` as well as `\n\n` — our server writes `\n\n` but an intermediary may not preserve it.
- **Long answers truncate at a fixed elapsed time.** Someone reached for `AbortSignal.timeout()`, which `expo/fetch` honours for the whole request including the streamed body — so it kills a healthy generation at the deadline. <!-- UNVERIFIED: expo/fetch is documented as supporting AbortSignal.timeout, but that it covers the streamed body rather than only the response headers was not confirmed against the source --> A streaming request has no total-duration timeout; the server owns that (`X-KB-Deadline`). Use an idle-gap watchdog reset on every chunk, sized off the 15 s `: ping`.
- **A user uninstalls the app to sign out, reinstalls, and is still signed in.** The iOS Keychain survives app deletion when the bundle id is unchanged, so SecureStore hands back a token the user believed they destroyed. Write a `hasLaunched` flag to `AsyncStorage` (which *is* removed on uninstall) and purge every SecureStore key when it is missing on launch.
- **After a device-to-device restore the app throws on every SecureStore read.** The Android Keystore key is device-bound and is not restored, so the ciphertext in SharedPreferences is undecryptable. `expo-secure-store` excludes itself from Auto Backup by default — but a config plugin that sets a custom `dataExtractionRules`/`fullBackupContent` re-includes it. Treat a decrypt failure as "no token", not as a crash.
- **Half the user base is silently logged out after a phone update, or an iCloud restore onto a new phone carries the token with it.** Two opposite failures of the same option. `requireAuthentication: true` invalidates the entry whenever the user's biometric enrolment changes — do not use it for the API token. The default `keychainAccessible: WHEN_UNLOCKED` (no `_THIS_DEVICE_ONLY`) is the mirror image: the item is eligible for an encrypted backup and can land on different hardware. Use `WHEN_UNLOCKED_THIS_DEVICE_ONLY`.
- **The chat feels laggy and the composer drops keystrokes while streaming.** One `setState` per `token` event is one React render per token over a growing `FlatList`. Buffer tokens and flush on an interval or `requestAnimationFrame`, and keep the composer in its own component so a transcript render cannot re-render its input.
- **An OTA update ships fine and the next native change bricks the app on real devices only.** A `runtimeVersion` that did not move (`appVersion` policy, or a hand-maintained string) let a JS bundle expecting a new native module load into a build that lacks it; `expo-updates` error-recovery then rolls back, so it reads as "the update didn't apply". Simulators often survive it. Use the `fingerprint` policy and let the mismatch be a build error.

## Official docs

- [Expo — `expo/fetch` API](https://docs.expo.dev/versions/latest/sdk/expo/) — the WinterCG fetch, the streaming `getReader()` example, global replacement and `EXPO_PUBLIC_USE_RN_FETCH`.
- [Expo — SecureStore](https://docs.expo.dev/versions/latest/sdk/securestore/) — `keychainAccessible` constants, `requireAuthentication`, size limits, Auto Backup exclusion, uninstall persistence on iOS.
- [Expo changelog — SDK 57](https://expo.dev/changelog/sdk-57) · [SDK 55](https://expo.dev/changelog/sdk-55) — the RN/React pins and the New Architecture-only cutover.
- [Expo — EAS Update runtime versions](https://docs.expo.dev/eas-update/runtime-versions/) and [code signing](https://docs.expo.dev/eas-update/code-signing/) — the four policies, what an update may not change, embedded-certificate verification.
- [Expo — Linking overview](https://docs.expo.dev/linking/overview/) — custom schemes vs verified Universal Links and App Links, AASA and `assetlinks.json`.
- [WHATWG HTML — Server-Sent Events](https://html.spec.whatwg.org/multipage/server-sent-events.html#event-stream-interpretation) — the field parsing rules `parseFrame` implements. [MDN — `TextDecoder.decode()`](https://developer.mozilla.org/en-US/docs/Web/API/TextDecoder/decode) — the `stream: true` contract.
- [expo/expo#33577](https://github.com/expo/expo/pull/33577) — the streaming-abort fix; the reason cancellation is verified, not assumed.

## Definition of done

- [ ] `rg -n "EventSource|from 'react-native'.*fetch|EXPO_PUBLIC_USE_RN_FETCH" apps/mobile` is empty, and every streaming call imports `fetch` from `expo/fetch`. A test asserts `res.body !== null`.
- [ ] `rg -n "internal/v1|ai-api|X-KB-" apps/mobile` returns nothing; no HMAC secret exists in the bundle or in `app.config.ts`.
- [ ] `rg -n "class KbError|function parseFrame|errorClass|retryAfter" apps/mobile` is empty — both come from `@kb/contracts`, snake_cased — and the send body is exactly `{client_message_id, content}` typed from the same package (`rg -n "text:" apps/mobile/src/features/chat` matches only the `token` event).
- [ ] An integration test against a fixture SSE server asserts inter-event wall-clock gaps (not just the final text), a frame split across two chunks reassembling, a multi-byte codepoint split across two chunks decoding intact, and exactly one terminal event.
- [ ] A cancellation test aborts mid-stream and asserts the server recorded `finish_reason: "cancelled"` / `user_cancellation` — separately for the Stop button, `router.back()`, and an `AppState` change to `background`.
- [ ] A stream cut without a terminal event surfaces `stream_lost`, re-reads the persisted message, and never re-POSTs the same `client_message_id`; no `Last-Event-ID` header appears anywhere.
- [ ] The token is written only through the auth module, with `WHEN_UNLOCKED_THIS_DEVICE_ONLY`, never with `requireAuthentication`; `rg -n "AsyncStorage" apps/mobile` shows no token or conversation content. First launch after a reinstall purges SecureStore.
- [ ] Logout deletes the token, calls the revoke endpoint, and clears the TanStack Query cache and the persisted history; a test logs in as user A, logs out, logs in as user B, and asserts none of A's conversations are readable.
- [ ] A 401 or `error_class: authentication` clears the token, preserves the composed draft, and routes to login without retrying the send. A send is refused pre-flight when `expires_at` is under 90 s away.
- [ ] `runtimeVersion` uses the `fingerprint` policy; EAS Update code signing is configured with the certificate embedded and the private key outside the repo; iOS ATS and the Android network security config pin the API origin and forbid cleartext.
- [ ] External links resolve only through verified Universal Links / App Links (AASA + `assetlinks.json` with the release fingerprint); `(app)/_layout.tsx` redirects unauthenticated deep links to login and renders nothing before the token read resolves.
