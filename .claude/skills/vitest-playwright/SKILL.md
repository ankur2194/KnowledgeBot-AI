---
name: vitest-playwright
description: Vitest 4 and Playwright 1.62 conventions for apps/web, and the browser-test rules apps/widget inherits. Use whenever writing a component, streaming, isolation or E2E spec, adding a Playwright project or storageState, or wiring the frontend CI shard — and whenever a streaming test is green while the UI paints the whole answer in one jump. route.fulfill() cannot chunk a text/event-stream body, so the chat path runs against a real fixture server. Pairs with pest-testing (the server-side half) and nextjs-app-router (the app under test).
---

# Vitest + Playwright — `apps/web`

Vitest **4.1.10** with `@vitest/browser-playwright` **4.1.10** and `vitest-browser-react` **2.2.0** · `@playwright/test` **1.62.1** · against Next.js **16.2.11** (the Active LTS line `nextjs-app-router` pins — the suite tests the version we ship, not the newest release) / React **19.2.8** / `@testing-library/dom` on Node **≥ 20.9** (Next's floor is the binding one; Vitest wants `^20 || ^22 || >=24`, Playwright `>=20`). Vite **8.2** pairs with `@vitejs/plugin-react` **6.x** — plugin-react 6 peers on Vite 8 *only*, so on Vite 7 you stay on plugin-react 5. Tests live in `apps/web/tests/`.
The Laravel half is `pest-testing` and the Python half is `pytest-ai-service`; RAG **quality** scoring is `ragas-evaluation` and is not correctness testing. `apps/mobile` runs Jest + React Native Testing Library (docs/05 §9.3) — what transfers there is the fixture server and the two-org harness, not the runner.
**Authoritative spec:** docs/17-testing-performance.md §22.1–22.6, docs/05-tech-stack.md §9.1, docs/13-security.md §18.5, docs/18-deployment-backup-cicd.md §26

## Non-negotiables

1. **Each layer owns a claim, and may not restate a neighbour's.** The table below is the contract. Its purpose is not tidiness — it is that the *client-side* half of tenancy (router cache, query cache, storageState) is invisible to Pest and mocked away by Vitest, so if Playwright does not own it, nothing does.
2. **Every isolation spec seeds two organizations and asserts the positive control first.** `pest-testing` NN1–NN2 applies unchanged on this side: assert *this* org's canary is visible before asserting the other's is absent, or a 500, a wrong selector, or an empty list satisfies the negative assertion and the suite is decoration. The web-specific case Pest cannot see is the **org switch**, where the previous organization's rows must not render (`nextjs-app-router` — the Router Cache and every query key are keyed by URL, and the org is in the session).
3. **Nothing fakes the tenant filter or authorization.** Playwright authenticates by driving the real login route against a real Laravel. No test-only login endpoint, no `?org=` override, no seeded superuser that skips membership (`kb-tenancy-isolation` NN4). A component test that mocks the API proves rendering, never isolation, and is never cited as isolation coverage.
4. **`route.fulfill()` is banned on any `text/event-stream` route, and no in-process mock stands in for the stream.** Verified in Playwright 1.62.1's source: fulfilment base64-encodes the whole body into one CDP `Fetch.fulfillRequest`, and Playwright registers interception at the Request stage only, so a fulfilled response can never arrive in pieces (`route.fetch()` buffers too; microsoft/playwright#33564 is open). This is the browser-side twin of `pest-testing` NN4 — `Http::fake()` there, a one-shot mock here, same green suite over the same broken read loop.
5. **The provider is always faked; the clock is faked on exactly one side at a time.** Asserting a real model's words is the flakiest test that can be written — use the fake provider adapter (docs/18 §24.6). `page.clock` fakes the **browser** clock only, so it can never expire a Laravel session, a `Retry-After`, or a retention cutoff.
6. **Untrusted-content rendering is proved in a real browser, not by a header assertion.** Pest can assert that a CSP header was sent; only Chromium can prove the frame was refused, the `onerror` never fired, and the exfiltration image was never fetched (`kb-security-baseline`).

## How we use it

### The boundary — what each layer may *not* assert

| Layer | Owns | May not assert |
|---|---|---|
| **Vitest `unit`** (`environment: 'node'`) | the SSE read loop over adversarial chunk boundaries, Zod schemas, query-key construction, the chat store's state machine, `error_class` → UI mapping | anything needing a DOM or layout; any API shape written by hand (types come from `packages/contracts/`); tenancy — a mocked transport cannot leak |
| **Vitest `components`** (browser mode, chromium) | rendering, accessible names and roles, focus and keyboard paths (§8.18), the markdown → DOMPurify sanitizer output, streaming *render* behaviour against the fixture | navigation between routes, auth, org resolution, anything server-rendered, and never a tenancy claim |
| **Playwright** | the flows of §22.4 against real Laravel + real FastAPI: login, **org switch**, upload → ready, ask → citation, stop-generation, widget origin accept/reject, back-button after logout | server-side status codes as its *primary* proof (`pest-testing` owns the 403/404 split), provider behaviour, database rows |
| **Pest / pytest** | authorization, the tenant filter, error taxonomy, rate limits, SSE relay mechanics at the HTTP level | what a browser renders, what a cache holds after a soft navigation |

**No jsdom project exists.** jsdom drops Node's `ReadableStream`, `TextDecoder`/`TextDecoderStream` and `Response` globals, which is exactly what the stream tests need, and it has no layout, which is exactly what the component tests need. Vitest 4 made Browser Mode GA and names it the recommended path for component testing; the browser binaries are already installed for Playwright, so the marginal cost is a launch. Config is `test.projects` in `vitest.config.ts` — `vitest.workspace.ts` was **removed** in Vitest 4 and a leftover file is silently ignored. Providers are factories now, not strings: `import { playwright } from '@vitest/browser-playwright'`; the old `@vitest/browser` package is no longer a dependency and `vitest/browser` replaces `@vitest/browser/context`.

**Component testing is Vitest's, not Playwright's.** Playwright 1.62.0 replaced `@playwright/experimental-ct-*` with a new stories-and-galleries model (`*.story.tsx` + a `window.mount()` gallery page). It is one release old and needs a second dev server; we are not running two component-test toolchains. Async Server Components stay out of Vitest entirely — Next's own guide says they are unsupported and to use E2E — which costs us almost nothing, since under `nextjs-app-router` NN1 server components render only org-neutral chrome.

**MSW 2.15 mocks plain JSON endpoints in component tests, and nothing on the chat path.** It *can* stream — `HttpResponse` takes a `ReadableStream`, and `sse()` (2.12+) is purpose-built — but it cannot fail the test that matters: a client `AbortController.abort()` does **not** reach the handler under `setupServer`, so `request.signal` never fires, the stream's `cancel()` never runs, and the consumer keeps receiving chunks after the stop button. That is the exact path §8.18's stop-generation action and the `finish_reason: "cancelled"` accounting exist for. Add that Playwright cannot use MSW at all, and one fixture driven by both layers is one fixture that cannot drift. <!-- UNVERIFIED: the abort gap was measured on msw 2.15.0 / Node 22, not stated in MSW's docs; the browser service-worker path is designed to propagate cancellation and was not measured -->

### The SSE fixture — one server, both layers

The fixture server in full — the `Stream` control surface (`send`, `split`, `ping`, `end`, `cut`), `setNoDelay` and `flushHeaders`, the awaited write callback — and the `unit` spec that drives it → **[references/sse-fixture-server.md](references/sse-fixture-server.md)**.

The *only* legal way to put that fixture in front of a browser is to redirect the request so the browser fetches it for real — `route.continue({ url })` leaves the response stage untouched, so the chunking survives (same protocol required, hence `http://` locally):
`await page.route('**/api/v1/chat/*/messages', (r) => r.continue({ url: \`${sse.url}/chat\` }));`

### The two-organization harness, and the org switch

```ts
// apps/web/tests/e2e/tenant-isolation.spec.ts
test('the org switcher never renders the previous organization\'s rows', async ({ page, orgs }) => {
  // `orgs` is a WORKER-scoped fixture: one fresh org pair per worker, each with its own canary,
  // seeded through Laravel's own API. A fixed "acme" tenant means shard 3's canary lands in
  // shard 1's assertion, and the failure only ever appears in CI (pest-testing, parallel hazard).
  await page.goto('/sources');
  await expect(page.getByText(orgs.b.canary)).toBeVisible();          // POSITIVE CONTROL, first

  // Every web-first assertion auto-retries, so `toHaveCount(0)` passes the moment the new org's
  // data lands — and the stale frame in between IS the bug. Holding the request open freezes
  // exactly the window under test; without this line the leak is green.
  let release!: () => void;
  const held = new Promise<void>((ok) => (release = ok));
  await page.route('**/api/v1/sources*', async (r) => { await held; await r.continue(); });

  await page.getByRole('button', { name: 'Switch organization' }).click();
  await page.getByRole('option', { name: orgs.a.name }).click();
  await expect(page.getByText(orgs.b.canary)).toHaveCount(0);         // the assertion that matters
  release();
  await expect(page.getByText(orgs.a.canary)).toBeVisible();          // and A's data does arrive
  // Then the Router Cache path: soft-navigate away and back, assert Org A's rows again — a hard
  // reload would hide it (nextjs-app-router).
});
```

Run the same body over a dataset of surfaces — source list, chat answer + citations, analytics, CSV export, warm answer cache — mirroring `pest-testing`'s `tenant surfaces`, so a surface added to the product without a row here looks conspicuously incomplete. Prefer the held route to network throttling: throttling makes the stale window *likely*, holding makes it *certain*.

### Authentication, and what is never faked

Two mechanisms, matching `laravel-sanctum-auth`. **Admin:** a `setup` project drives the real login form (`GET /sanctum/csrf-cookie` → submit → wait for the dashboard) and writes `playwright/.auth/w{workerIndex}-{a|b}.json`; every other project declares `dependencies: ['setup']` and `test.use({ storageState })`. `storageState` persists cookies — so `laravel_session` *and* `XSRF-TOKEN` both come back — plus `localStorage`, and optionally IndexedDB (`{ indexedDB: true }`, 1.51); **`sessionStorage` is never captured**, so anything kept there is restored with `context.addInitScript()` or not at all. **Public surfaces** (hosted chat, widget) hold no cookie: the SDK bootstrap mints an origin-bound token per page load, so those specs carry no storageState and instead run from a real allow-listed origin served by a `webServer` entry — which is what makes the unlisted-origin rejection a browser-enforced test rather than a header assertion.

**Never faked:** the tenant filter, Laravel's authorization, the SSE transport, server-side time. **Always faked:** the LLM provider (docs/18 §24.6). **Faked only where the app's own timers are the subject:** `page.clock.setFixedTime()` for relative-timestamp rendering, `install()` + `fastForward()` for an interval the app owns.

### CI

Playwright runs `--shard=i/N` with `fullyParallel: true` (without it whole *files* are assigned and shards imbalance), `reporter: 'blob'`, and one `npx playwright merge-reports` job; add `--fail-on-flaky-tests` (1.52) or a spec that only ever passes on retry exits 0 forever. Vitest shards the same way. Both run against `next build && next start`, never `next dev`, and slot between steps 5 and 9 of docs/18 §26.

## Gotchas

- **The streaming spec is green and the UI paints the whole answer in one jump.** `route.fulfill()` sends one buffered body (Playwright intercepts the Request stage only) and `route.fetch()` buffers before replaying, so neither can chunk. `route.continue({ url })` — or no route at all — is the only interception that preserves real streaming. The same failure has a second shape in Vitest: MSW's `sse()` resolver *streams* when it returns synchronously and **buffers the entire stream** when it is `async`, because MSW awaits the resolver before delivering the response — so `await sleep()` between `client.send()` calls, the obvious way to write it, silently collapses every gap to zero. <!-- UNVERIFIED: measured on msw 2.15.0, undocumented -->
- **`sse()` handlers never match and the request falls through as unhandled.** MSW requires `accept: text/event-stream` on the request, and constructing the handler throws outright when `globalThis.EventSource` is undefined — which it is in Vitest's `node` environment. Both are reasons the chat path uses the fixture server instead.
- **The parser passes every unit test and mangles production answers.** Every mocked chunk was a whole frame. Real segments split anywhere: between `\n` and `\n`, mid-`data:` line, and mid-UTF-8 codepoint — the last one only ever shows up in German, Hindi or an emoji, i.e. never in a fixture written in English. Use `split()` with a byte offset inside a multi-byte character.
- **The stream spec is green and every citation chip in the running app renders blank.** The fixture payloads were typed by hand, so they encode what the test author believed the wire says rather than what `pydantic-contracts` actually emits — a `citations` frame keyed `n`/`locator` instead of `index`/`title`/`url`/`score` proves only that the parser survives whatever it is fed. Build fixture payloads from `packages/contracts`' types (unit-layer rule in the table above) so a server-side rename fails the type-check here instead of shipping.
- **Two events sent back to back arrive as one read and a timing assertion flakes.** Nagle's algorithm. `res.socket.setNoDelay(true)` in the fixture — and never assert a chunk *count*; assert the rendered transcript.
- **`await fetch()` in the test hangs until the first token.** No `res.flushHeaders()`, so Node held the headers for the first body write. Nothing about the pre-token UI (status events, skeleton, stop button enabled) can be tested until this is fixed.
- **The mid-stream-cut spec passes but the UI shows a completed answer.** The fixture called `res.end()`. A clean FIN is a normal end-of-stream; only `res.socket.destroy()` (RST) reproduces a vanished peer and drives the `stream_lost` path the whole cancellation design exists for.
- **The isolation spec is green and the org switcher leaks.** `not.toBeVisible()` / `toHaveCount(0)` retry until they pass, so a stale render that lasts until the refetch lands satisfies them. Hold the new organization's request open and assert inside that window.
- **The isolation spec is green because the page never rendered.** A 500, a renamed test id, or an empty list all satisfy "canary absent". Assert this org's own canary is visible first (`pest-testing` NN2) — it is the line people delete when the suite gets slow.
- **`ReferenceError: TextDecoderStream is not defined`, or a stream test that quietly takes a different code path.** jsdom removes Node's stream and fetch globals. Do not polyfill your way back; stream tests belong in the `node` project and component tests in browser mode.
- **Playwright is green locally and 419s in CI.** The replayed `storageState` is fine; `SANCTUM_STATEFUL_DOMAINS` does not list the `baseURL` host **with its port**, so Sanctum classifies the request third-party and ignores the session. It surfaces as `419 CSRF token mismatch`, not 401, which sends everyone looking at the wrong handler (`laravel-sanctum-auth`).
- **Shard 3's chat specs 429 and pass on rerun.** The composite chat limiter keys on bot + origin + session + IP (`valkey-keyspaces`), and every shard shares the runner's IP. Give each worker its own org and bot, and run the rate-limit specs serially against a bot no other spec touches.
- **`page.clock.install()` and the test hangs.** It replaces `setTimeout`/`setInterval`/`requestAnimationFrame`, which freezes TanStack Query's refetch and retry timers and the rAF token-flush buffer. `setFixedTime()` moves `Date` only and is what date-formatting assertions want.
- **Screenshot and aria-snapshot diffs flake between a laptop and CI.** Fonts and rasterization differ per OS. Run visual specs only inside `mcr.microsoft.com/playwright:v1.62.1`, and set `reducedMotion: 'reduce'` — Radix/shadcn transitions are the usual source.
- **A test for an async Server Component cannot be made to work.** Next's guide states Vitest does not support them and to use E2E. If the component is tenant-shaped it also violates `nextjs-app-router` NN1 and the fix is in the app, not the test.

## Official docs

- [Vitest — Browser Mode](https://vitest.dev/guide/browser/) and [Component testing](https://vitest.dev/guide/browser/component-testing) — GA since v4, provider factories, `browser.instances`.
- [Vitest 4 blog](https://vitest.dev/blog/vitest-4) and [Migration guide](https://vitest.dev/guide/migration.html) — `workspace` → `projects`, dropping `@vitest/browser`, Node 20 / Vite 6 floors. [`vi` fake timers](https://vitest.dev/api/vi.html) — microtasks are *not* faked by default; use the `*Async` advance methods around async iteration.
- [Playwright — class Route](https://playwright.dev/docs/api/class-route) and [issue #33564](https://github.com/microsoft/playwright/issues/33564) — the fulfil body types, and the open request for a stream body.
- [Playwright — Authentication](https://playwright.dev/docs/auth) and [`storageState`](https://playwright.dev/docs/api/class-browsercontext#browser-context-storage-state) — setup projects, what is and is not persisted. [Sharding](https://playwright.dev/docs/test-sharding), [Retries](https://playwright.dev/docs/test-retries), [Clock](https://playwright.dev/docs/clock) — blob reports, `--fail-on-flaky-tests`, what the clock overrides.
- [Next.js — Vitest](https://nextjs.org/docs/app/guides/testing/vitest) and [Playwright](https://nextjs.org/docs/app/guides/testing/playwright) — the async-Server-Component limitation, and running against a production build. [Playwright — Component testing](https://playwright.dev/docs/test-components) is the 1.62 stories-and-galleries model that replaced the `-ct-` packages, for reference only.
- [MSW — Streaming responses](https://mswjs.io/docs/http/mocking-responses/streaming) and [`sse()`](https://mswjs.io/docs/api/sse/) — `ReadableStream` bodies, `TransformStream` latency injection, and the SSE namespace we deliberately do not use for chat.

## Definition of done

- [ ] `vitest.config.ts` declares `test.projects` (`unit` → `environment: 'node'`, `components` → browser mode with the `playwright()` provider factory); no `vitest.workspace.ts`, no jsdom, no `@vitest/browser` dependency.
- [ ] Exactly one SSE fixture exists; `rg -n "route\.fulfill" apps/web/tests` shows no match on a chat or `text/event-stream` route.
- [ ] Stream specs cover: a frame split mid-UTF-8 codepoint, a frame split between `\n` and `\n`, `\r\n` line endings, `: ping` not surfaced as an event, `citations` before the first `token`, and a `cut()` yielding `stream_lost` with a Retry affordance — never a completed answer.
- [ ] An abort mid-stream produces one request, and Laravel records one `finish_reason: "cancelled"` message row and one usage row (asserted on the Pest side; this suite asserts the UI state).
- [ ] Every SSE fixture payload and every error assertion is typed from `packages/contracts` — `rg -n "errorClass|retryAfter|locator|\bn:" apps/web/tests` is empty — and the hosted-chat E2E asserts the send carries `Authorization` and no session cookie while the admin send carries the cookie and `X-XSRF-TOKEN` (`nextjs-app-router` NN8).
- [ ] Every isolation spec uses the worker-scoped two-org fixture with a per-test canary and asserts the positive control before the negative one; `rg -n "toHaveCount\(0\)|not\.toBeVisible" apps/web/tests/e2e` shows each occurrence preceded by a positive control.
- [ ] The org-switch spec covers both caches — the in-flight window (request held open) and the Router Cache (soft navigation back), per `nextjs-app-router`. Playwright authenticates only through the real login route: `rg -n "test-login|__test|actingAs|\?org=" apps/web/tests` returns nothing.
- [ ] Browser-only §22.5 cases exist: a source containing `<img src=x onerror>` renders inert while its sanitized text is visible; an answer containing a remote image issues no request to that host (`page.on('request')`, EchoLeak); the widget is refused framing from an unlisted origin; an unlisted origin gets a byte-identical 404 from the SDK bootstrap.
- [ ] Each Playwright worker seeds its own organization pair and bot; rate-limit specs are serial against a dedicated bot; the suite passes at `--shard=1/4 … 4/4` with `--fail-on-flaky-tests`.
- [ ] E2E runs against `next build && next start`; visual specs run only in the pinned Playwright image with `reducedMotion: 'reduce'`. No spec calls `page.waitForTimeout()`, asserts on a chunk count, or depends on the current date without `page.clock.setFixedTime()`.
