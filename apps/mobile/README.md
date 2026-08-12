# `@kb/mobile` — the React Native client

Expo SDK 57, expo-router, Hermes. One product: sign in to an organization, list conversations, hold
a streamed, cited conversation with a bot. It talks to **Laravel only** — there is no path from this
package to FastAPI, and there is no provider credential anywhere in it.

```
app/                 expo-router file routes
  (auth)/login       the only unauthenticated screen
  (app)/             everything behind a session
src/
  api/client.ts      the one fetch wrapper; attaches the PAT, handles 401
  auth/              SecureStore, expiry, first-launch, the session provider
  db/history.ts      SQLite conversation history, org- AND user-scoped
  features/chat/     the SSE read loop and the answer-stream hook
  lib/env.ts         the single public value, validated at module load
plugins/             config plugins (ATS / network-security posture)
```

## Three things that will bite

- **Hermes' `fetch` is XHR-backed and has no `ReadableStream`.** Streaming uses `expo/fetch`, which
  does; `src/features/chat/stream-answer.ts:6` imports it **by name**, and asserts
  `res.body !== null` at runtime so the degradation is visible on the device rather than silent.
  *`jest.config.js:35` says the by-name import is "what CI greps for". **It is not** — no grep in
  either workflow enforces it, verified 2026-08-11 (`grep -rn 'expo/fetch' .github/workflows/`
  returns one comment and no check). Recorded in
  [`docs/22`](../../docs/22-spec-findings-and-decisions.md) § F6; do not cite the claim as a
  control until a check exists.*
- **The credential is a Sanctum personal access token in SecureStore**, never in AsyncStorage and
  never in a "server URL" debug screen — there is no runtime origin override in any build type,
  because one hands a phishing host a live bearer token. The origin is inlined per EAS build profile
  and validated at module load; a missing one throws at app start rather than sending every request
  to `undefined/rt/v1/...`.
- **Chat posts to `rt/v1`, not `api/v1`.** `api/v1` is the admin cookie-session-plus-CSRF group and
  is built to reject the bearer this app sends; `routes/api_public.php` names mobile as the PAT
  exception on `rt/v1`.

**Local history is scoped by organization *and* by user.** The threat model named in
`src/db/history.ts` is a shared tablet, a handover, a re-login as a colleague — so the org key alone
is not enough, and both tables carry `user_id NOT NULL`. The database is also purged
**unconditionally on `signIn`**, before the new session is written: the state that must be caught is
a token that *expired*, where no sign-out and no 401 ever ran, and on that path a stored-identity
guard reads `null` in exactly the cases it exists to catch. A purge that fails rejects the sign-in
rather than leaving the device authenticated as a new user over the old user's rows.

## Local commands

**Prerequisites: Node 22 (`>=22.20.0 <23`) and pnpm 10 on the host.** `engine-strict` refuses an
install on any other major rather than resolving it quietly. If `node` is not found it is most
likely installed under `nvm` and absent from a non-interactive shell's `PATH` — `nvm use` (the repo
pins the major in `.nvmrc`) is usually the whole fix. **There is no container fallback for this
package at all**: it has no Compose service and no image. Native work additionally needs the
platform toolchain (Xcode / Android SDK) or an EAS build.

Run these from `apps/mobile`, which is where this workspace's scripts live — the repo root's
`package.json` has `web:*` and `contracts:*` scripts but none for mobile:

```bash
pnpm lint
pnpm typecheck
pnpm test            # jest-expo; `pnpm exec jest --listTests` names the suites
pnpm start           # Metro, for a dev client
```

**The suite count is deliberately not written here.** It has moved twice in a single session; run
`pnpm exec jest --listTests` for the suites and `pnpm test` for the totals. What *is* stable is the
shape: every spec lives under `tests/`, matched by `tests/**/*.test.ts{,x}`.

## Why a green suite is not a working app, and what closes the gap

`jest.config.js` says this at the top of the file, and it is the most important sentence in the
package:

> this suite executes on **Node**, not on Hermes.

Node has `ReadableStream`, a spec-complete `TextDecoder`, and a `fetch` whose `Response.body` is
never null. Under Node, `expo/fetch` is mapped to a shim. So these tests can prove the **parser** is
correct — chunk boundaries, split codepoints, heartbeat comments, exactly one terminal event — and
**can never prove that streaming works on a device**. Hermes fails by *succeeding*: the request goes
through, the whole answer arrives at once, and every assertion about the final text still passes.

The shim is a *transport* double and not a convenience: it preserves socket chunk boundaries and
errors the stream on RST, because a vanished peer sends RST rather than FIN and a double that calls
`close()` instead of `error()` normalises away the one distinction the test exists to make.

**Nothing in this repository has been verified on a device or a simulator.** That includes the
streaming read loop and the SQLite v1→v2 history rebuild, which is proven as SQL against
`node:sqlite` and not through expo-sqlite's bridge. The closing move is a run on the **`preview`**
EAS profile — `eas.json` names it as the profile that must reproduce a production streaming session,
because a simulator on `development` can hide an ATS mistake. It is a checklist item, not a test
file. Tracked in [`docs/23-unverified-claims.md`](../../docs/23-unverified-claims.md)
§ *Raised by the stub-completion effort*.

## Things that are not negotiable here

- Never import `fetch` from anywhere but `'expo/fetch'` on the streaming path, and never through an
  indirection CI's grep cannot see.
- Never put a token in AsyncStorage, in a log line, or in a deep link.
- Never add a runtime API-origin override, a "staging" toggle, or a debug server picker.
- Never widen `EXPO_PUBLIC_*`: every value in an `eas.json` `env` block is inlined into the bundle
  and readable by anyone who downloads the app. The allow-list that fails the build on a new key
  lives in `app.config.ts`, because in the bundle `process.env` is not an object and cannot be
  enumerated at runtime.
- Never write a conversation row without both `organization_id` and `user_id`.
- Never call `api/v1`, and never call FastAPI.
