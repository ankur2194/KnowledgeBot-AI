# The server-driven source list — reference

Depth for `tanstack-query-table`. Spec: docs/03-functional-knowledge-sources.md §8.9 §8.16 §8.17, docs/12-api-areas.md §17.1.
The complete `SourcesTable` component: the org-prefixed query key carrying every value the server sorts, filters or pages by; the `signal`-forwarding `queryFn`; the org-compared `placeholderData`; the terminal-state poll; and the `manualSorting` / `manualPagination` wiring a server-driven table needs. The retry policy it inherits, the five-step org switch, and the optimistic-update rules stay in `SKILL.md`.

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
