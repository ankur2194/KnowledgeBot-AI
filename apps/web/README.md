# `@kb/web` — the admin console and hosted chat

One Next.js 16 App Router application serving **two** products on **two** hostnames, split by route
group (ADR-027):

|             | `src/app/(admin)`                            | `src/app/(chat)`                                  |
| ----------- | -------------------------------------------- | ------------------------------------------------- |
| Who         | an organization's staff                      | an end user who followed a link                   |
| Host        | the main domain, at `/*`                     | `chat.<domain>`, at `/c/[publicBotId]`            |
| Credential  | the Laravel cookie session, plus CSRF        | none — the bot is public, or it 404s              |
| API surface | `api/v1` (cookie session + CSRF)             | `rt/v1` (unauthenticated public runtime)          |

**The two must not be confused, and the failure is silent.** `api/v1` is the admin
cookie-session-plus-CSRF group; every route in it sits behind `auth:sanctum`, `surface:admin` and
`org.member` under `organizations/{organization}`. A public surface calling it gets a 401 that most
callers treat as "no data", which is how a bot theme fetch spent a while rendering every tenant's
bot in default colours with no error and no failing test. There is no `api/v1/public/*` surface and
none is planned.

## Where the boundaries are

- **`src/proxy.ts` is not the authorization gate**, and no change may make it one. Next 16 renamed
  `middleware.ts` to `proxy.ts`; a matcher copied from a Next 15 answer, in a file called
  `middleware.ts`, guards nothing at all. CVE-2025-29927 skipped every Next.js middleware with a
  single request header. **Laravel returning 401/403/404 is the gate.** What lives in `proxy.ts` is
  host→namespace routing, the hosted-chat CSP nonce (which must be per response), and a UX redirect
  for a visitor who does not *look* signed in — cookie presence is not a session.
- **This package holds no credential and no FastAPI hostname.** `src/lib/env.ts` allows exactly one
  `NEXT_PUBLIC_*` key, `NEXT_PUBLIC_API_ORIGIN`, and validates it as an origin with no trailing
  slash. Anything else with that prefix is either a mistake or a server secret on its way into a
  client bundle. Clients reach Laravel and only Laravel.
- **Every Next cache is keyed by URL, and the org is in the session.** A cached admin page is a
  cross-org serve waiting to happen; the caching directives in this app exist for that reason rather
  than for speed.
- **`packages/contracts` is imported, never forked.** The SSE frame parser, the 18 error classes and
  the `KbError` shape all have exactly one declaration. A second copy is the drift the contract
  review exists to catch.

## Local commands

**Prerequisites: Node 22 (`>=22.20.0 <23`) and pnpm 10 on the host.** `engine-strict` refuses an
install on any other major rather than resolving it quietly. If `node` is not found it is most
likely installed under `nvm` and absent from a non-interactive shell's `PATH` — `nvm use` (the repo
pins the major in `.nvmrc`) is usually the whole fix. **There is no container fallback**: the `web`
Compose service builds and serves the Next output, and its runtime image carries Node but not the
workspace or pnpm, so `docker compose exec web pnpm …` does not work.

```bash
pnpm contracts:build     # FIRST — see below. Not optional.
pnpm web:lint
pnpm web:typecheck
pnpm web:test            # Vitest: the `unit` project
pnpm web:e2e             # Playwright — currently zero tests; see below
```

**`pnpm contracts:build` must run before `pnpm web:test`.** `packages/contracts/dist/` is gitignored
and `@kb/contracts`' `exports` map points entirely into it, so a missing or stale `dist` does not
fail loudly — it fails as unresolved imports, or worse, silently hides an export that was added
after the last build. Measured: with `dist/` moved aside, five of six unit spec files fail to
resolve; with it present, `vitest run` is green. Any change under `packages/contracts/src/` needs
the rebuild before this package's tests mean anything.

### Two of these commands currently run nothing, and that is worth saying plainly

- **`pnpm web:e2e` runs zero tests.** `apps/web/tests/e2e/` holds only `.gitkeep`, and
  `pnpm exec playwright test --list` reports `Total: 0 tests in 0 files`. The repository's real
  browser coverage — cross-origin, real sockets, chunked SSE — lives in `apps/widget`, which is a
  different package with a different harness. Do not read a green `web:e2e` as evidence of anything.
- **The `components` Vitest project is empty.** `tests/components/` has no files, and
  `passWithNoTests: true` at `vitest.config.ts:16` is what keeps the run green. That flag's own
  comment says to delete it with the first component spec, and it should be.

`tests/unit/` is real and does carry the streaming assertions: `tests/fixtures/sse-server.ts` is a
`node:http` server on a real port, held byte-identical with `apps/mobile`'s twin by a drift test in
`packages/contracts`. In-process stream doubles are treated as false greens here, deliberately.

## Things that are not negotiable here

- Never call `api/v1` from anything under `(chat)`. Public runtime is `rt/v1`; the session mint is
  `sdk/v1`.
- Never make `proxy.ts` the thing that decides whether a request is allowed.
- Never add a second `NEXT_PUBLIC_*` key without deciding, in writing, that its value is public.
- Never fork anything out of `packages/contracts` — import it, or change it there.
- Never let a failed upstream fetch be cached as though it succeeded. A fail-open answer pinned into
  a CDN for five minutes cannot be evicted by the next success; failure responses are `no-store`.
- Never render tenant-supplied text without the sanitiser, and never let a bot's theme values reach
  CSS without passing the value-domain check in `src/lib/theme.ts`.
