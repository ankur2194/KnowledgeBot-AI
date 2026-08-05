---
name: tanstack-query-table
description: Server state and data grids for the Next.js admin in apps/web — TanStack Query 5.101.4 and TanStack Table 9.0.0. Use whenever adding a query key, a mutation, a retry or polling rule, an optimistic update, or a server-paginated source, job, or conversation table under apps/web/. Query keys are org-namespaced because the admin switches organizations and the org is not in the URL. Pairs with kb-tenancy-isolation (the scope a key must carry) and kb-error-taxonomy (the retry policy it implements).
---

# TanStack Query + TanStack Table in apps/web

`@tanstack/react-query` **5.101.4**, `@tanstack/react-table` **9.0.0**, `@tanstack/react-store` **0.11.0** — all pinned exact, no caret.
**Authoritative spec:** docs/19-repo-structure-adrs.md §27, docs/12-api-areas.md §17.1, docs/03-functional-knowledge-sources.md §8.9, §8.16, §8.17

## Non-negotiables

1. **Every query key begins `['org', orgId, …]`.** `orgId` is a *cache namespace*, never a request parameter — Laravel derives the real organization from the session cookie and would ignore a client-supplied one (`kb-tenancy-isolation` NN 6, `laravel-sanctum-auth`). Without it, `['sources', 1]` is one cache entry serving two organizations, and the symptom is a correctly rendered list, not an error. No Next cache key can carry the org (`nextjs-app-router`); a TanStack Query key is the one key in this app we design ourselves, so it does.
2. **The client is created per React tree, never at module scope.** A `const queryClient = new QueryClient()` in a module is one cache shared by every user of the Node process. TanStack's own SSR guide: *"Besides being bad for performance, this also leaks any sensitive data."*
3. **The cache is never persisted.** No `persistQueryClient`, no localStorage/IndexedDB/sessionStorage persister, no devtools in a production bundle. Tenant content would outlive logout, survive an org switch, and sit unencrypted on a shared workstation (`kb-security-baseline`).
4. **Retry counts are configured in exactly one file.** Per-call `retry:` is banned. Exactly one tier retries a given call (`kb-error-taxonomy`); the browser's share is at most one attempt for reads and **zero** for mutations.
5. **Branch on `error_class`, never on HTTP status.** One class renders two statuses by surface — `authorization` is 403 on admin and 404 on public (`kb-error-taxonomy` fn. 1). Status-driven retry logic retries a `tenant_quota` 403 forever and reads an `authorization` 404 as "absent, so create it".
6. **The table never sorts, filters, or paginates client-side.** The server owns the tenant filter, so the browser holds one org-scoped page of a set it cannot see. Sorting 25 of 4,000 rows produces an ordering that is wrong and looks right.
7. **Nothing in the browser cache is authoritative.** Deletion completion, lifecycle state, and quota come from the server response — never from an optimistic write. §8.17 requires an administrator to *see* deletion completion; a cache entry cannot prove a purge (`kb-deletion-and-verification`).

## How we use it

**Not ours.** The chat stream is `fetch()` + `getReader()` and never a query or mutation — `nextjs-app-router` owns it. Route structure, Router Cache, and `router.refresh()` → `nextjs-app-router`. Table markup, cells, and skeletons → `tailwind-shadcn`. Form state and 422 field mapping → `rhf-zod-forms`. Component and E2E tests → `vitest-playwright`. The `{error_class, message, retryable, request_id}` envelope → `kb-internal-api-contracts`. **`KbError` is defined once, in `packages/contracts`** (`nextjs-app-router`), and imported here — never re-declared per app. Its five fields are all snake_case because each is a straight carry of the envelope plus one header: `error_class`, `retryable`, `retry_after` (seconds, parsed from the `Retry-After` **response header**; it is not in the JSON envelope), `request_id`, and the operator-facing `message`. This skill reads the first three.

**Why v9, one day after release.** There is no application code to migrate, v8.21.3 has been feature-frozen since 2025-04-14, and starting on v8 schedules a migration on day one. Do **not** import `@tanstack/react-table/legacy` (`useLegacyTable`) or `stockFeatures` — both exist for migrations we do not have, and the migration guide states `stockFeatures` produces *"a larger bundle size than you even got with Table V8."* Pin exact; patch churn on a day-old major is expected.

### The retry policy, in one file

Measured worst case was 27×: SDK 3 × adapter 3 × client 3. Provider SDKs are now `max_retries=0` and the adapter owns 3 attempts, so the *default* TanStack Query policy (`retry: 3` → 4 attempts) still multiplies one click into 12 provider calls. One client retry makes it 6; a mutation at zero makes it 3, which is exactly the adapter's budget.

```ts
// apps/web/src/lib/query/client.ts — the ONLY place a retry count appears in apps/web.
import { QueryClient } from '@tanstack/react-query';
import { KbError } from '@kb/contracts';   // the one definition; never a per-app copy

// Narrower than the envelope's `retryable` flag on purpose: `retryable: true` means *some* tier
// may retry, and for `provider_temporary` that tier is the FastAPI adapter, which already spent
// its ladder. Obeying the flag re-multiplies it. Every class absent here is never retried.
const CLIENT_RETRYABLE = new Set(['internal_dependency', 'retrieval', 'storage']);

export const makeQueryClient = () =>
  new QueryClient({
    defaultOptions: {
      queries: {
        retry: (failureCount, error) => {
          if (failureCount >= 1) return false;             // one retry, two attempts, hard stop
          if (!(error instanceof KbError)) return false;   // no envelope ⇒ unknown ⇒ permanent
          // `rate_limit` is *our* limiter, and kb-error-taxonomy assigns its retry to the client.
          return CLIENT_RETRYABLE.has(error.error_class) || error.error_class === 'rate_limit';
        },
        // Retry-After is a floor, not a hint. `retry_after` is seconds, off the response header.
        // Spelled `retryAfter` it reads `undefined`, the delay drops to 1 s with no error, and we
        // retry inside the window we were told to wait.
        retryDelay: (_a, error) =>
          error instanceof KbError && error.retry_after ? error.retry_after * 1000 : 1_000,
        staleTime: 30_000,
        refetchOnWindowFocus: false,   // a focus storm on 12 open tabs is a self-inflicted 429
      },
      mutations: { retry: false },     // already the default; restated because a POST is not replayable
    },
  });
```

### Switching organizations

The org lives in an `HttpOnly` session cookie and changes via `POST /v1/session/organization`. Five steps, in this order:

1. Navigate to a neutral shell first. A mounted data view renders the instant its cache has anything.
2. `await queryClient.cancelQueries()` — a no-op unless every `queryFn` forwards its `signal` to `fetch`.
3. `await switchOrg.mutateAsync(nextOrgId)` (`retry: false`; a replayed switch races itself).
4. **Replace** the client: `setClient(makeQueryClient())`. Not `queryClient.clear()` — see gotcha 5.
5. `router.refresh()` to drop the Router Cache (`nextjs-app-router` step 5).

Step 4 is the enforcement; the `orgId` key prefix is the invariant, and it is what still holds when a future refactor, a nested provider, or a test harness skips step 4.

### One complete example — server-driven source list

The component in full — the org-prefixed key carrying every server-visible sort, filter and page value, the `signal`-forwarding `queryFn`, the org-compared `placeholderData`, the terminal-state poll, and the `manualSorting` / `manualPagination` wiring → **[references/sources-table-example.md](references/sources-table-example.md)**.

### Optimistic updates

Optimistic writes are allowed **only** for values the server stores verbatim and no policy can reshape: source title, description, tags, bot appearance copy. `onMutate` cancels the affected key, snapshots, and writes; `onError` restores the snapshot; `onSettled` invalidates.

Never optimistic here: **deletion and disable** (two-phase and verified — the browser cannot know the purge succeeded, `kb-deletion-and-verification`); **any lifecycle transition** (the client cannot compute whether a reprocess lands in `Ready`, `Ready with warnings` or `Failed`, `kb-source-lifecycle`); **uploads, crawl starts, and anything a quota can reject** (`tenant_quota` is decided server-side, and an optimistic success that 403s tells the admin their document was ingested).

## Gotchas

- **A user switches org and the previous org's source list renders for ~200 ms before flipping.** The key was `['sources', page]` with no org, so org A's entry was a cache hit; Query serves stale data immediately and refetches behind it. `staleTime: 0` does not fix this — stale data is still *rendered* during the refetch; that is what the cache is for. Fix: `orgId` as key segment 2, plus the five-step switch.
- **Adding `placeholderData: keepPreviousData` for smooth paging brings the org leak back.** The helper deliberately renders the previous *key's* data while the new key loads, and once `orgId` is in the key the previous key is the previous org. Use the function form and compare `prevQuery.queryKey[1]` to the current `orgId`.
- **The table shows "No sources found" for an org with hundreds, immediately after typing a filter.** `autoResetPageIndex` is automatically disabled when `manualPagination: true`, so `pageIndex` stayed at 7 and the server returned an empty page 8. Reset `pageIndex` to 0 in the *same* state update as any sort or filter change; under manual mode the table will never do it.
- **The pager reads "Page 1 of 1" on a 4,000-row set, or Next never disables.** `rowCount`/`pageCount` was not passed, so the table treats `data.length` as everything; `pageCount: -1` makes `getCanNextPage()` unconditionally `true`. Pass `rowCount` from the envelope, and render a skeleton pager while `query.data` is undefined rather than defaulting it to `0`.
- **`queryClient.clear()` on the org switch, and org A's rows still appear.** `clear()` empties the caches but keeps the same client and the same mounted observers: active observers immediately refetch, and any request that started before the switch can still resolve into it. Replace the client instance instead — a new client has no observers and nothing renders the old one.
- **A second admin's data appears in a first admin's dashboard, and only in production.** A module-scope `new QueryClient()`. In dev the process turns over often enough to hide it; in production one Node process serves everyone. Create it in `useState(() => makeQueryClient())`. Note `nextjs-app-router` makes the browser the fetcher, so there is no server prefetch to hydrate — if anyone adds one, it needs a fresh client per request and an org-prefixed key.
- **Nothing ever retries, and a 429's `Retry-After` is ignored — with no error and no log line.** The retry predicate reads `error.error_class` and the delay reads `error.retry_after`; against an instance that spells either in camelCase both are `undefined`, `CLIENT_RETRYABLE.has(undefined)` is `false`, and every transient class renders as a permanent failure the user can only fix by reloading. It happens the moment a second `KbError` exists — a per-app copy, a mobile copy, a test double. Import the one in `packages/contracts`; `instanceof` against a forked class also fails, so the `!(error instanceof KbError)` guard above turns *everything* permanent at once.
- **The provider dashboard shows 6–12 calls per user click while our logs show one.** TanStack Query's `retry` default is 3 (4 attempts) and multiplies against the adapter's 3. The single `retry` function above is the fix; a per-call `retry:` silently reopens it, so grep for it.
- **An upload or delete executes twice, or the idempotency store returns 409.** Someone set `retry` on a mutation. Mutations default to `retry: 0` — keep it. A mutation that genuinely must survive a retry carries an `Idempotency-Key` (`kb-internal-api-contracts`); one without a key must never be retried by anything, including a user double-click, so disable the button on `isPending`.
- **Selection checkboxes ignore clicks and sort arrows go stale — only in the production build.** React Compiler memoized an extracted header or cell component against the stable `column`/`row` reference, and builder-pattern reads (`column.getIsSorted()`, `row.getIsSelected()`) hide their state dependency from it. This hits exactly the shadcn/ui habit of breaking cell renderers into named components. Wrap those reads in `<Subscribe source={table.atoms.…} selector={…}>` from `@tanstack/react-table`.
- **The grid janks and every row re-renders on unrelated state changes.** `data={query.data?.rows ?? []}` — the inline `?? []` is a fresh array identity each render and invalidates the core row model, and any inline `.filter()`/`.map()` does the same to an otherwise stable `query.data`. Hoist an `EMPTY` constant, `useMemo` the columns, define `features` at module scope.
- **A hidden tab polls for hours and the admin limiter starts rejecting real requests.** `refetchInterval` as a bare number never stops. Use the function form returning `false` once every row is terminal, leave `refetchIntervalInBackground` at its default `false`, and size the interval against its cost: 20 admins × 1 tab × 2 s is 10 rps of pure status polling, each paying a session lookup, a membership re-check and a policy evaluation in Laravel. 5 s while ingesting is the pin; anything faster buys nothing, because ingestion stages are seconds to minutes (`kb-error-taxonomy` budgets).
- **A crawl finishes but the detail drawer still says "Fetching" until a manual reload.** Only the list polled. Poll one cheap digest and *invalidate* the fan-out: when a poll sees a row leave a non-terminal state, `invalidateQueries` the siblings (`['org', orgId, 'sources', id]`, `['org', orgId, 'jobs']`) once. Polling every pane multiplies requests by the number of open panes, and there is no server push to fall back on — §17.5's ingestion status callback is FastAPI→Laravel, not Laravel→browser.

## Official docs

- [TanStack Query — Query Retries](https://tanstack.com/query/latest/docs/framework/react/guides/query-retries) — the `retry: 3` default and the `Math.min(1000 * 2 ** attemptIndex, 30000)` delay we override.
- [TanStack Query — SSR & Server Rendering](https://tanstack.com/query/latest/docs/framework/react/guides/ssr) — why the client is created inside the tree, and the "leaks any sensitive data" warning.
- [TanStack Query — QueryClient reference](https://tanstack.com/query/latest/docs/reference/QueryClient) and [Query Cancellation](https://tanstack.com/query/latest/docs/framework/react/guides/query-cancellation) — what `clear()`/`removeQueries`/`resetQueries` actually do, and the `signal` a `queryFn` must forward for `cancelQueries` to abort anything.
- [Announcing TanStack Table V9](https://tanstack.com/blog/announcing-tanstack-table-v9) (2026-08-04 stable) and the [React migration guide](https://tanstack.com/table/latest/docs/framework/react/guide/migrating) — the TanStack Store rewrite, `useReactTable` → `useTable`, `tableFeatures()`, and the `useLegacyTable`/`stockFeatures` escape hatches we refuse.
- [Table V9 — Pagination](https://tanstack.com/table/latest/docs/framework/react/guide/pagination) (`manualPagination`, `rowCount`/`pageCount`, the `autoResetPageIndex` auto-disable) and [Table State](https://tanstack.com/table/latest/docs/framework/react/guide/table-state) (atoms, `table.Subscribe`, the React Compiler section).

## Definition of done

- [ ] `rg -n "queryKey:" apps/web/src` shows every key beginning `['org', orgId,` — and every server-visible sort, filter, and page value inside it.
- [ ] `rg -n "retry:" apps/web/src` returns only `lib/query/client.ts`; `rg -n "\.status ===" apps/web/src` returns nothing in error-handling code.
- [ ] `KbError` is imported from `@kb/contracts` and declared nowhere else (`rg -n "class KbError" apps/ packages/` returns one hit); `rg -n "errorClass|retryAfter" apps/` returns nothing. A test throws a `KbError` with `retry_after: 30` and asserts the delay is 30 s, and another asserts an unknown class is not retried.
- [ ] `rg -n "new QueryClient\(" apps/web/src` returns only `makeQueryClient` and its `useState` call site; no persister, no production devtools; every `queryFn` destructures and forwards `{ signal }`, with a test asserting `cancelQueries` aborts the request and not just its result.
- [ ] A Playwright test switches orgs with the network throttled and asserts org A's canary source title never appears on org B's list — including during the transition, not only after settle (`vitest-playwright`).
- [ ] Every table with `manualPagination` also passes `rowCount` and resets `pageIndex` in the same update as a sort or filter change; a test paginates to the last page, applies a filter, and asserts rows render.
- [ ] `features`, `columns`, and the empty-data fallback have stable references; no client row model (`createSortedRowModel`, `createPaginatedRowModel`, `createFilteredRowModel`) is registered on a server-driven table.
- [ ] Every polling query uses the function form of `refetchInterval` and returns `false` on terminal state; a test asserts polling stops.
- [ ] No optimistic update touches deletion, lifecycle state, or a quota-gated action; a test asserts a `tenant_quota` rejection leaves the cache unchanged.
- [ ] `@tanstack/react-store` is a *declared* dependency of `apps/web` (it is only transitive via the table, which pnpm's strict layout will not resolve), pinned exact alongside query and table.
