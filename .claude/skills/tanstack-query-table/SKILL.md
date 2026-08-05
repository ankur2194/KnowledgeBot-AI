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

**Not ours.** The chat stream is `fetch()` + `getReader()` and never a query or mutation — `nextjs-app-router` owns it. Route structure, Router Cache, and `router.refresh()` → `nextjs-app-router`. Table markup, cells, and skeletons → `tailwind-shadcn`. Form state and 422 field mapping → `rhf-zod-forms`. Component and E2E tests → `vitest-playwright`. The `KbError` thrown by `apps/web/src/lib/api` and the `{error_class, message, retryable, request_id}` envelope → `kb-internal-api-contracts`; this skill requires two things of that wrapper — `error_class` readable as a property, and `retryAfter` parsed from the `Retry-After` **response header** (it is not in the JSON envelope). <!-- UNVERIFIED: `nextjs-app-router` constructs `new KbError(error_class, retryable)` with no retry-after; the two skills must be reconciled before either ships. -->

**Why v9, one day after release.** There is no application code to migrate, v8.21.3 has been feature-frozen since 2025-04-14, and starting on v8 schedules a migration on day one. Do **not** import `@tanstack/react-table/legacy` (`useLegacyTable`) or `stockFeatures` — both exist for migrations we do not have, and the migration guide states `stockFeatures` produces *"a larger bundle size than you even got with Table V8."* Pin exact; patch churn on a day-old major is expected.

### The retry policy, in one file

Measured worst case was 27×: SDK 3 × adapter 3 × client 3. Provider SDKs are now `max_retries=0` and the adapter owns 3 attempts, so the *default* TanStack Query policy (`retry: 3` → 4 attempts) still multiplies one click into 12 provider calls. One client retry makes it 6; a mutation at zero makes it 3, which is exactly the adapter's budget.

```ts
// apps/web/src/lib/query/client.ts — the ONLY place a retry count appears in apps/web.
import { QueryClient } from '@tanstack/react-query';
import { KbError } from '@/lib/api';

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
        // Retry-After is a floor, not a hint. `retryAfter` is read off the response header.
        retryDelay: (_a, error) =>
          error instanceof KbError && error.retryAfter ? error.retryAfter * 1000 : 1_000,
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

```tsx
// apps/web/src/features/sources/sources-table.tsx
'use client';
import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  useTable, tableFeatures, rowSortingFeature, rowPaginationFeature,
  createColumnHelper, FlexRender, type SortingState, type PaginationState,
} from '@tanstack/react-table';
import { api } from '@/lib/api'; import { useOrgId } from '@/lib/session';
import { useDebounced } from '@/lib/hooks';

type SourceRow = { id: string; title: string; status: string; chunk_count: number };
type Page = { rows: SourceRow[]; row_count: number; organization_id: string };

// Module scope: `features`, the helper and the fallback must be referentially stable or the core
// row model rebuilds every render. No sorted/paginated row model is registered — the server does both.
const features = tableFeatures({ rowSortingFeature, rowPaginationFeature });
const columnHelper = createColumnHelper<typeof features, SourceRow>();
const EMPTY: SourceRow[] = [];
const TERMINAL = new Set(['ready', 'ready_with_warnings', 'failed', 'disabled', 'deleted', 'archived']);

export function SourcesTable() {
  const orgId = useOrgId();                       // from the session bootstrap, not the URL
  const [sorting, setSorting] = useState<SortingState>([{ id: 'title', desc: false }]);
  const [pagination, setPagination] = useState<PaginationState>({ pageIndex: 0, pageSize: 25 });
  const [search, setSearch] = useState('');
  const q = useDebounced(search, 300);            // undebounced, each keystroke mints a key and a request

  const query = useQuery({
    // orgId first. Every value the server sorts, filters or pages by is in the key, or a page-2
    // response overwrites the page-1 entry and the table renders rows it did not ask for.
    queryKey: ['org', orgId, 'sources', { sorting, pagination, q }] as const,
    queryFn: ({ signal }) =>
      api.get<Page>('/v1/sources', {
        signal,                                   // without this, cancelQueries drops the result only
        params: { page: pagination.pageIndex + 1, per_page: pagination.pageSize,
                  sort: sorting.map((s) => (s.desc ? '-' : '') + s.id).join(','), q: q || undefined },
      }),
    // Keeps the previous page visible while the next loads, but only within one org: bare
    // `keepPreviousData` renders the previous *key*, which after a switch is org A's rows.
    placeholderData: (prev, prevQuery) => (prevQuery?.queryKey[1] === orgId ? prev : undefined),
    // Poll only while something is still ingesting; return false and it stops dead (§8.9).
    refetchInterval: (self) =>
      self.state.data?.rows.some((r) => !TERMINAL.has(r.status)) ? 5_000 : false,
  });

  // The response echoes the org Laravel scoped to (UNVERIFIED: no spec field; Laravel must add it).
  // A switch landing mid-flight resolves under the old key — refuse to render a mismatched org.
  const data = query.data?.organization_id === orgId ? query.data.rows : EMPTY;

  const columns = useMemo(() => [
    columnHelper.accessor('title', { header: 'Title' }),
    columnHelper.accessor('status', { header: 'Status', enableSorting: false }),
    columnHelper.accessor('chunk_count', { header: 'Chunks' }),
  ], []);

  const table = useTable({
    features, columns, data,
    manualSorting: true,
    manualPagination: true,
    rowCount: query.data?.row_count,   // the pager cannot know the last page without it
    state: { sorting, pagination },
    onSortingChange: (updater) => {
      setSorting(updater);
      // autoResetPageIndex is auto-disabled under manualPagination: reset by hand, or the next
      // request asks for page 8 of a 2-page result and the table renders "no sources found".
      setPagination((p) => ({ ...p, pageIndex: 0 }));
    },
    onPaginationChange: setPagination,
  });

  return (
    <table>
      <thead>{table.getHeaderGroups().map((hg) => <tr key={hg.id}>{hg.headers.map((h) => (
        <th key={h.id} onClick={h.column.getToggleSortingHandler()}><FlexRender header={h} /></th>
      ))}</tr>)}</thead>
      <tbody>{table.getRowModel().rows.map((row) => <tr key={row.id}>{row.getVisibleCells().map((c) => (
        <td key={c.id}><FlexRender cell={c} /></td>
      ))}</tr>)}</tbody>
    </table>
  );
}
```

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
- [ ] `rg -n "new QueryClient\(" apps/web/src` returns only `makeQueryClient` and its `useState` call site; no persister, no production devtools; every `queryFn` destructures and forwards `{ signal }`, with a test asserting `cancelQueries` aborts the request and not just its result.
- [ ] A Playwright test switches orgs with the network throttled and asserts org A's canary source title never appears on org B's list — including during the transition, not only after settle (`vitest-playwright`).
- [ ] Every table with `manualPagination` also passes `rowCount` and resets `pageIndex` in the same update as a sort or filter change; a test paginates to the last page, applies a filter, and asserts rows render.
- [ ] `features`, `columns`, and the empty-data fallback have stable references; no client row model (`createSortedRowModel`, `createPaginatedRowModel`, `createFilteredRowModel`) is registered on a server-driven table.
- [ ] Every polling query uses the function form of `refetchInterval` and returns `false` on terminal state; a test asserts polling stops.
- [ ] No optimistic update touches deletion, lifecycle state, or a quota-gated action; a test asserts a `tenant_quota` rejection leaves the cache unchanged.
- [ ] `@tanstack/react-store` is a *declared* dependency of `apps/web` (it is only transitive via the table, which pnpm's strict layout will not resolve), pinned exact alongside query and table.
