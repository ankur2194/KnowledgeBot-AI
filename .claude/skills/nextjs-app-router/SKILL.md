---
name: nextjs-app-router
description: The Next.js App Router app in apps/web — admin console and hosted chat, the RSC/client boundary for streaming, and the caching config preventing cross-org serves. Use whenever adding a route, layout, server component, route handler, proxy matcher, or cache directive in apps/web, or when a page shows the wrong org after a switch or a server-rendered page 401s. Every Next cache is keyed by URL; the org lives in the session. Pairs with laravel-sanctum-auth (the credential it carries) and kb-tenancy-isolation (the leak).
---

# Next.js App Router — `apps/web`

Next.js **16.2.11** (Active LTS, 2026-07-21) on React **19.2.8**, Node ≥20.9, TypeScript ≥5.1, Turbopack, App Router, in `apps/web/`. Latest stable is **16.3.0** (2026-08-03); we hold the LTS line because everything this skill depends on landed in 16.0 and a two-day-old minor is not what a self-hostable product ships. The App Router runs Next's **vendored React canary** on the server regardless of the `react` in the lockfile, so a server-side React question is answered by the Next version, not by 19.2.8. **`cacheComponents` is deliberately off** — see Caching.
**Authoritative spec:** docs/05-tech-stack.md §9.1, docs/04-functional-channels-chat.md §8.18–8.19 §8.24, docs/12-api-areas.md §17.1–17.2, docs/06-architecture.md §11.1, docs/19-repo-structure-adrs.md §27

## Non-negotiables

1. **No organization-scoped byte is ever produced by the Next.js server.** Not "produced and marked `no-store`" — not produced. Every server-side cache Next has is keyed by URL, fetch arguments, or function arguments; `current_organization_id` lives in the session cookie (`laravel-sanctum-auth`) and is in none of them. A hit is a 200 with correct-looking data belonging to another tenant, with no exception and no log line — the silent shape `kb-tenancy-isolation` opens with.
2. **Laravel is the only origin this app talks to.** `apps/web` never reaches `ai-api`; it is deliberately off the `application` Docker network so a route handler *cannot* (`kb-architecture-map` NN 1, docs/06 §11.1). No provider credential, no internal HMAC key, and no FastAPI hostname exists in this package.
3. **`EventSource` is never used.** It is GET-only and its constructor's only option is `withCredentials`, so it carries neither our POST body nor a header; and its automatic `Last-Event-ID` reconnect is forbidden because token streams are not resumable (`kb-internal-api-contracts`). Every surface streams with `fetch()` + `response.body.getReader()`.
4. **`apps/web` has no Server Actions.** An action is a mutation path that does not pass Laravel's rate limiter, quota accounting, or audit log — exactly the "second independent business backend" docs/05 §9.1 rules out. Every mutation is a browser `fetch` to Laravel. This also retires `NEXT_SERVER_ACTIONS_ENCRYPTION_KEY`, action-id rotation, and `allowedOrigins` as things anyone here must get right.
5. **`proxy.ts` is never the authorization gate.** CVE-2025-29927 skipped all Next.js middleware with one request header; the proxy here only redirects an unauthenticated-*looking* visitor for UX. The gate is Laravel returning 401/403/404 (`laravel-rbac-policies`, `kb-error-taxonomy`).
6. **Model-generated Markdown renders through the sanitizer pipeline in `kb-security-baseline`** (markdown-it `html:false` → DOMPurify → `replaceChildren`), never `dangerouslySetInnerHTML`, and remote images are never auto-loaded (EchoLeak, CVE-2025-32711). Retrieved content is untrusted data.
7. **Clients branch on `error_class`, never on HTTP status** — one class maps to different statuses per surface (`kb-error-taxonomy`). The envelope's `message` is operator-facing and is never rendered verbatim to a user: a class-mapped sentence plus the `request_id` is what a user sees (`rhf-zod-forms`).
8. **This app is two surfaces on two origins carrying two different credentials, and no shared helper may assume one of them.** The admin console holds the session cookie; hosted chat holds the opaque chat-session token, the same one the widget uses (`laravel-sanctum-auth` — four client classes, four mechanisms, no fifth). Any function that talks to Laravel takes the credential as an argument.

## How we use it

```
apps/web/src/
├── app/(admin)/               admin console, app.<domain>  — own root layout, force-dynamic, zero caching
├── app/(chat)/[publicBotId]/  hosted chat, chat.<domain>   — own root layout, own CSP, cacheable shell
├── lib/api/server.ts          the ONE server-side Laravel caller — `server-only`, uncacheable by construction
├── lib/api/browser.ts         the ONE browser Laravel caller — credentials, XSRF header, error envelope
└── features/chat/             the streaming surface, shared by both groups
```

### One app, two route groups, two hostnames

§27 pins a single `apps/web`; keep it, but the two surfaces get separate root layouts, separate CSP, **opposite** caching postures, and separate hostnames routed to the same container. The hostname split is not cosmetic: hosted chat renders model-generated Markdown, the highest-risk sink in the product, and on a shared origin one XSS there executes with the admin session cookie attached. Scoping that cookie to `app.` and `api.` only (`laravel-sanctum-auth`) means an anonymous visitor's page never carries it. What the groups *do* share — `next.config.ts`, `proxy.ts`, the build — is therefore what must stay tenant-neutral: **no cache-enabling default is ever set globally; every one lives in `(chat)` segment config.**

### Caching — here, the URL is not a cache key

| Cache | Keyed by | Serves on a hit | Scope |
|---|---|---|---|
| Full Route Cache / prerender | route path (+ `generateStaticParams`) | the whole RSC payload and HTML | build output + revalidation, **per instance** |
| Data Cache | the `fetch()` URL and options | one upstream response | disk, survives deploys, per instance |
| `use cache` (needs `cacheComponents`) | the function's **arguments** and closed-over values | whatever it returned | shared server cache |
| Client Router Cache | route path | the RSC payload | one browser tab's memory |
| Traefik / browser | URL + `Vary` | the HTTP response | outside Next entirely |

None of those keys contains the organization. The configuration below makes tenant data *unreachable* by all five rather than opting each one out:

1. **`cacheComponents` stays off.** Turning it on **removes `dynamic`, `dynamicParams`, `revalidate` and `fetchCache` from route segment config** — deleting the coarse, greppable, layout-inheritable kill switch step 3 depends on — makes PPR the default so a prerendered shell is shared by every visitor of a route, and moves the failure mode from "a route was accidentally static" (loud, greppable) to "a `use cache` function closed over the org instead of taking it as an argument" (invisible in review). Vercel intends these behaviours to become default in a future major; **when that lands this entire section must be re-derived, not patched.**
2. **The browser is the fetcher.** Every tenant-scoped read and write goes from the browser to `api.<domain>` through TanStack Query (`tanstack-query-table`). The Next server never sees the bytes, so no Next server cache can hold them. Server components render only chrome that is byte-identical for every organization: navigation, route structure, skeletons, empty states.
3. **`app/(admin)/layout.tsx` exports `dynamic = 'force-dynamic'` and `revalidate = 0`.** Segment config on a layout applies to every route beneath it, so a page added later cannot opt in by omission. Belt, not mechanism. **`fetchCache` is never set** — `'force-cache'`/`'default-cache'` re-enable caching for fetches issued *after* a request-time API, which is precisely the window this is closing.
4. **Nothing under `app/(admin)/` uses `generateStaticParams`, `force-static`, `unstable_cache`, or `use cache`.** CI greps for all of them plus `'use server'`.
5. **The Router Cache survives all of the above** because it lives in the tab, not the server. Both org switch and logout must destroy it: `router.refresh()` after `POST /v1/session/organization` succeeds, and a **full document navigation** (`window.location.assign('/login')`) on logout. `experimental.staleTimes.dynamic` defaults to `0`, which closes most of this — but it is one "make navigation snappier" commit away from not being `0`, and Next documents no cache invalidation on sign-out at all. <!-- UNVERIFIED: no official page states whether a session change clears prefetched RSC payloads; treat back/forward after logout as an empirical check, not an assured behaviour. -->
6. **`Cache-Control: private, no-store` on every `(admin)` response**, set in `next.config` `headers()` and re-asserted at Traefik. Next's defaults govern Next's caches; the shared proxy and the browser's bfcache are not among them.

Worth naming so nobody rediscovers it: if the organization were a path segment (`/o/[orgId]/…`) every key above would contain it and most of this would be unnecessary. It is not, because the session owns the current org (`laravel-sanctum-auth` — do not re-litigate). The cost of that decision is paid here, in full.

**Hosted chat is the exact opposite, and that is correct.** `app/(chat)/[publicBotId]/` renders the public bot configuration — name, avatar, welcome message, starter questions, theme, page metadata (§8.19) — which is identical for every visitor and keyed by a public bot id that *is* in the URL. Cache it with `fetch(…, { next: { tags: [\`bot:${id}\`], revalidate: 60 } })` and expire on publish; note `revalidateTag(tag, profile)` takes a **required** `cacheLife` profile since 16.0 and the one-argument form is a type error. Everything past first paint — session token, conversation, stream — is per-visitor and browser-side. **The moment a bot is set to password-protected or authenticated-organization mode (§8.19) its content varies by something not in its URL and the route moves to the admin posture.** That is the whole test, applied per route.

### The RSC/client boundary for a streaming answer

```
app/(chat)/[publicBotId]/page.tsx   server: public bot config + generateMetadata. Cacheable. No visitor state.
  └── <ChatSurface>                 'use client' — the boundary, and it is the entire chat
        ├── transcript + composer state, AbortController (the §8.18 stop-generation action)
        └── streamAnswer()          fetch + getReader, straight to Laravel — never back through Next
```

The boundary sits at the first thing that varies per visitor. **Sending a message must never be a Server Action**, for four independent reasons: there is no supported way to abort an in-flight action, so the stop button would stop the UI while the provider keeps generating and usage is never finalized as `cancelled` (`kb-internal-api-contracts` makes cancellation a first-class terminal state); Next dispatches actions **sequentially, one at a time per client**, so one in-flight generation blocks every other action in the app for the length of the answer; React's documented serializable return values are primitives, plain objects and Promises — not `ReadableStream` and not async generators <!-- UNVERIFIED: absence from React's documented list, not an explicit prohibition --> so you lose `citations`-before-first-`token`, the `: ping` heartbeats and the `error_class` envelope every other client parses; and it puts the Next process in the middle of a minutes-long stream, adding a buffering surface and the `fromFrontend()` 401 below, for zero authorization benefit since the browser already holds the credential.

### The shared client runtime, and the one `KbError`

`packages/contracts` is **not** types-only. It carries the SSE frame parser, the client event union, and the error class as real runtime code, because three clients — `apps/web`, `apps/widget`, `apps/mobile` — parse the same frames and must not each own a copy that drifts (`preact-vite-library`, `expo-react-native`; `admin-web-engineer` owns the package, the other two import it).

```ts
// packages/contracts/src/errors.ts — the ONE KbError. Field names are the envelope's, verbatim.
export class KbError extends Error {
  constructor(
    readonly error_class: string | null,   // one of the 18 (kb-error-taxonomy), the client-local
                                           // sentinel 'stream_lost', or null when no envelope
                                           // parsed. Null is unknown, and unknown is permanent.
    readonly retryable: boolean,
    readonly retry_after: number | null = null,  // SECONDS, off the `Retry-After` RESPONSE HEADER —
                                                 // it is not in the JSON envelope
    readonly request_id: string | null = null,   // echoes X-KB-Request-Id: the one identifier a user
                                                 // is ever shown (rhf-zod-forms)
    message?: string,                            // operator-facing. Log it; never render it.
  ) { super(message ?? error_class ?? 'unknown'); }
}
export const parseRetryAfter = (h: string | null): number | null => { /* delta-seconds or HTTP-date */ };
```

**Snake_case throughout, deliberately:** every field is a straight carry of `{error_class, message, retryable, request_id}` plus one header, so the property name equals the JSON key and there is no rename layer for a typo to hide in — `error.errorClass` against a snake-cased instance is `undefined`, and `CLIENT_RETRYABLE.has(undefined)` is `false`, which turns every retryable class into a permanent failure with no error anywhere.

### The stream read loop

`streamAnswer()` in full — the credential union, the read loop, the frame-boundary and UTF-8 handling, the `KbError` construction and the `stream_lost` terminal check → **[references/stream-read-loop.md](references/stream-read-loop.md)**.

**Two surfaces, two origins, two credentials.** One `streamAnswer` serves both, but it takes the credential as an argument and never assumes the cookie: the admin console on `app.<domain>` sends the session cookie plus `X-XSRF-TOKEN`; hosted chat on `chat.<domain>` sends the opaque chat-session token as a bearer and no cookie at all, because the session cookie is scoped to `app.` and `api.` (§ *One app, two route groups* above, `laravel-sanctum-auth`). The signature is one options object — `{conversationId, body, credential, signal, apiOrigin?}` — so a caller cannot get the argument order wrong and the SSE fixture server has a supported way in (`vitest-playwright`).

**The request body is `{client_message_id, content}`**, typed from `packages/contracts` and owned by `kb-internal-api-contracts`. `text` is the `token` event's field name, not the request's; posting it 422s every send.

### Owned elsewhere — cite, never restate

Styling and components → `tailwind-shadcn`. Query client, mutations, client-state caching, tables → `tanstack-query-table`. Forms, form schemas, and 422 field mapping → `rhf-zod-forms`. **Build-time env validation stays here** — it is a Next build concern, not a form, and `rhf-zod-forms` scopes itself to forms. Component and E2E tests → `vitest-playwright`. Session cookie, CSRF, org switching, chat-session tokens → `laravel-sanctum-auth`. CSP, sanitization, the widget → `kb-security-baseline` and `widget-sdk-engineer`. SSE event schema and error envelope → `kb-internal-api-contracts`. Browser OTel wiring and span naming → `kb-observability-conventions`.

## Gotchas

- **A page 401s from the server while the identical request works in the browser.** A server-side `fetch()` sends no `Referer` and no `Origin`, so Sanctum's `fromFrontend()` classifies it third-party, session middleware never runs, and a valid cookie is ignored. It cannot reproduce in devtools. Forward the cookie *and* a synthetic `Origin` matching a `sanctum.stateful` entry from `lib/api/server.ts` — or, as here, keep authenticated fetching in the browser and the trap has no surface (`laravel-sanctum-auth`).
- **Every hosted-chat send 401s in production, and the same code is flawless in local development.** `streamAnswer` sent `credentials: 'include'` and an `X-XSRF-TOKEN` and no `Authorization`. In dev, `app`, `chat` and the API are all `localhost` on different ports, so the cookie is present and everything passes; in production the session cookie is scoped to `app.` and `api.` (`laravel-sanctum-auth`), so on `chat.<domain>` that POST carries **nothing** — `credentials: 'include'` with no matching cookie is silently empty, not an error. It survives review because the code visibly *has* a credential; it just has the wrong one for that origin. Pass the credential in, and assert both surfaces separately in E2E — a suite that only exercises the admin console cannot see this.
- **Every retryable failure renders as permanent and `Retry-After` is ignored.** Two spellings of the same field: something reads `error.errorClass` off an instance that declares `error_class` (or the reverse). The read is `undefined`, `CLIENT_RETRYABLE.has(undefined)` is `false`, and `tanstack-query-table`'s predicate downgrades a transient `rate_limit` or `internal_dependency` into a dead end — no retry, no backoff, no error, just a failed panel. TypeScript does not catch it across a boundary typed `any` or a hand-rolled second class. One `KbError`, one casing, imported from `@kb/contracts` everywhere; a per-app copy is the bug.
- **A logged-in page renders as if nobody is logged in, identically for every visitor.** `export const dynamic = 'force-static'` does not error when the route reads request state — it makes `cookies()`, `headers()` and `useSearchParams()` return **empty values**, so the page renders a plausible signed-out shell once and the Full Route Cache serves it to everyone. Ban it under `(admin)`; `force-dynamic` is the only value used there.
- **A user switches organization and the sources list shows the previous org's rows; a hard refresh fixes it.** The Client Router Cache served the RSC payload it already had for that path — the org is not in the path. Same user, wrong org, still a tenancy failure. `router.refresh()` on switch; a full document navigation on logout, or the back button renders authenticated payloads after sign-out.
- **A cached function serves Org A's data to Org B and no test can catch it.** A `use cache` / `unstable_cache` key is its **arguments plus closed-over values**, never the ambient request. `getBots()` reading the org from an outer async context has one entry for the whole platform; `getBots(orgId)` has one per org. A single-org fixture passes either way — the same blindness `kb-tenancy-isolation` names. This is why nothing under `(admin)` is cached at all: the correct-looking version is one refactor from the leaking one.
- **The same answer streams cleanly in English and grows `` in German or Hindi.** `TextDecoder.decode(value)` without `{ stream: true }` treats each chunk as a complete input and drops the trailing bytes of a codepoint split across a TCP boundary. Corruption scales with chunk count, so it worsens on exactly the long answers people notice.
- **Every message is sent twice in development, and occasionally in production.** The send lives in a `useEffect`, which React StrictMode invokes twice in dev and which re-runs on any remount — two conversations, two provider calls, two bills. Send from the submit handler, and make `client_message_id` a stable per-message UUID so Laravel's idempotency key collapses a genuine duplicate.
- **A published bot change appears on some page loads and not others.** §24.9 scales "Next.js instances" horizontally and the route/data caches are per-instance disk state, so an expiration reaches only the container that served the request. Either run a shared cache handler or keep `revalidate` short enough that the inconsistency is invisible — a second reason tenant data is never in there.
- **An authenticated page renders its shell for a request carrying a crafted header.** CVE-2025-29927: one internal header made Next.js skip middleware entirely. Any design where the proxy is the gate fails open on the next equivalent bug. Note `middleware.ts` was renamed to **`proxy.ts`** in 16.0 (Node runtime only, not configurable) and the old name is deprecated, so a matcher copied from a Next 15 answer silently guards nothing.
- **`view-source` shows fields the UI never renders.** Anything a server component fetched and passed to a client component is serialized into the RSC payload embedded in the HTML — the whole object, not the fields you read. Pass primitives or an explicitly narrowed shape; never spread an API resource into client props. Related: prefetching **executes** layouts and pages on the server for routes the user may never visit, so any server-side read there fires auth checks and audit writes for pages nobody opened.
- **`/_next/image?url=…` becomes a proxy into the private network, or an uploaded SVG runs script on our origin.** 16.0 hardened the defaults — `dangerouslyAllowLocalIP` blocks local-IP optimization, `maximumRedirects` is 3, `domains` is deprecated in favour of `remotePatterns` — so the remaining failures are all opt-ins. Leave `dangerouslyAllowSVG` and `dangerouslyAllowLocalIP` unset, keep `remotePatterns` to exact hosts, and never point `next/image` at user-supplied files: those are served from the separate file origin with `Content-Disposition: attachment` (`kb-security-baseline`).
- **The composer drops keystrokes while an answer streams.** One `setState` per `token` event is one React render per token over a growing transcript. Buffer tokens and flush on `requestAnimationFrame`; keep the composer uncontrolled or in its own component so a transcript render cannot touch it.
- **A password-protected bot's name is readable by anyone with the URL.** `generateMetadata` runs before any gate and §8.19 lets an org set a custom page title. For non-public access modes emit a generic title and let the gate render, or the `<title>` answers the question the 404 rule (`kb-error-taxonomy`) exists to refuse.
- **A server secret ships in the client bundle, and lint stopped running the day it did.** `NEXT_PUBLIC_` inlines a value at build time into every bundle referencing it, with no type safety behind the prefix; `apps/web` needs exactly one public value, the Laravel API origin. Separately, 16.0 removed `next lint` and the `eslint` config key, and **`next build` no longer lints** — a CI job that relied on the build to lint now passes while checking nothing. Run ESLint as its own step and validate env with a schema that rejects unknown `NEXT_PUBLIC_*` keys.

## Official docs

- [Caching (without Cache Components)](https://nextjs.org/docs/app/guides/caching-without-cache-components) — the model we are on: `fetch` uncached by default, Data Cache and Full Route Cache keys, what makes a route dynamic.
- [Cache Components](https://nextjs.org/docs/app/api-reference/config/next-config-js/cacheComponents) and [`use cache`](https://nextjs.org/docs/app/api-reference/directives/use-cache) — the model we are declining, and the argument-keyed cache that is why.
- [Route Segment Config](https://nextjs.org/docs/app/api-reference/file-conventions/route-segment-config) — `dynamic`, `revalidate`, `fetchCache`, layout inheritance, and which of them `cacheComponents` removes.
- [`staleTimes`](https://nextjs.org/docs/app/api-reference/config/next-config-js/staleTimes) (still experimental; `dynamic` 0s, `static` 300s) and [Prefetching](https://nextjs.org/docs/app/guides/prefetching) — the Router Cache and what populates it.
- [Upgrading to Next 16](https://nextjs.org/docs/app/guides/upgrading/version-16) — async request APIs, `middleware.ts` → `proxy.ts`, Turbopack default, image and lint removals.
- [Server Actions security](https://nextjs.org/docs/app/guides/server-actions) and [React `use server`](https://react.dev/reference/rsc/use-server) — action ids, closure encryption, serializable return types; read to understand what we decline.
- [CVE-2025-29927](https://github.com/vercel/next.js/security/advisories/GHSA-f82v-jwr5-mffw) and the [Next.js security release programme](https://nextjs.org/blog/next-security-release-program) — the middleware bypass, and the LTS lines we pin against.
- [WHATWG HTML — SSE event stream interpretation](https://html.spec.whatwg.org/multipage/server-sent-events.html#event-stream-interpretation) — the field rules `packages/contracts`' `parseFrame` implements. [MDN `TextDecoder.decode()`](https://developer.mozilla.org/en-US/docs/Web/API/TextDecoder/decode) — the `stream: true` contract.

## Definition of done

- [ ] `rg -n "'use server'|unstable_cache|use cache|generateStaticParams|force-static|fetchCache" apps/web/src/app/\(admin\)` is empty, and `rg -n "'use server'" apps/web` is empty everywhere.
- [ ] `rg -n "EventSource|ai-api|ai-service|:8000" apps/web` is empty. `next.config.ts` does not set `cacheComponents`; no route exports `runtime = 'edge'`.
- [ ] `app/(admin)/layout.tsx` exports `dynamic = 'force-dynamic'` and `revalidate = 0`; a test requests an admin route as two different orgs and asserts the second response shares no tenant string with the first.
- [ ] `lib/api/server.ts` is the only module importing `server-only` and calling Laravel server-side; it awaits `cookies()` first and passes `cache: 'no-store'` explicitly.
- [ ] Playwright: sign in as Org A, switch to Org B, **soft-navigate** back to a list route — assertion is on Org B's rows, on the Router Cache path, not after a reload (`vitest-playwright`). Sign out, press Back — no authenticated payload renders.
- [ ] Every `(admin)` response carries `Cache-Control: private, no-store`, asserted through Traefik and not only against `next start`.
- [ ] Stream tests run against a fixture server that splits frames mid-UTF-8 and mid-frame, emits `: ping`, and hangs up before the terminal event: transcript byte-correct, ping not rendered, hangup yields `stream_lost` with a Retry affordance.
- [ ] `KbError` and `parseFrame` are declared **only** in `packages/contracts`: `rg -n "class KbError|function parseFrame" apps/` is empty, and `rg -n "errorClass|retryAfter" apps/ packages/` returns nothing (one casing, `error_class` / `retry_after`). A test asserts a 429 with `Retry-After: 30` produces `retry_after === 30`.
- [ ] `streamAnswer` takes `{conversationId, body, credential, signal}`; a test asserts the `(admin)` send carries the cookie and `X-XSRF-TOKEN` and no `Authorization`, and the `(chat)` send carries `Authorization: Bearer` and no cookie. `rg -n "apiOrigin" apps/web/src` returns nothing outside the signature itself.
- [ ] The send body is exactly `{client_message_id, content}` typed from `packages/contracts`; `rg -n "\btext:" apps/web/src/features/chat` matches only the `token` event.
- [ ] Aborting mid-stream produces exactly one request, one `finish_reason: "cancelled"` message row, and one usage row on the Laravel side.
- [ ] Citations render only from the `citations` event, before the first token, never from parsed answer text (docs/07 §12.16–12.17).
- [ ] A fixture answer containing `<img src=x onerror=…>` and a remote-image exfiltration URL renders inert through the `kb-security-baseline` pipeline.
- [ ] `next.config.ts` pins `images.remotePatterns` to exact hosts with `dangerouslyAllowSVG` and `dangerouslyAllowLocalIP` unset; ESLint runs as its own CI step; the env schema rejects unknown `NEXT_PUBLIC_*` keys.
