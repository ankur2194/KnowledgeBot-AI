import type * as NextNavigation from 'next/navigation';
import { render } from 'vitest-browser-react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { useTableParams } from '@/lib/table/use-table-params';
import type { TableParamsConfig } from '@/lib/table/params';

import {
  MOCK_PATHNAME,
  navigationCalls,
  resetNavigation,
  useMockPathname,
  useMockRouter,
  useMockSearchParams,
} from '../support/mock-navigation';

/**
 * The URL-backed table state hook.
 *
 * ── THE ASSERTION THIS FILE EXISTS FOR ───────────────────────────────────────────────────────────
 * `manualPagination: true` auto-disables `autoResetPageIndex`, so nothing resets the page for you; a
 * reset performed in a SECOND state update is the bug that requests page 8 of a 2-page result and
 * renders "no matches" for a collection with hundreds of rows. Asserting the final URL is not enough
 * to catch that — a two-step version arrives at the same URL. So every test below asserts the NUMBER
 * of navigations as well as their content, which is the only observable that distinguishes them.
 *
 * `next/navigation` is mocked because `useRouter()` throws outside a mounted App Router. The mock is
 * a real store rather than a spy (see `tests/support/mock-navigation.ts`): the hook reads its state
 * back out of the URL it just wrote, so a spy that swallowed the href would leave every assertion
 * after the first click looking at the initial state.
 */
vi.mock('next/navigation', async (importOriginal) => ({
  ...(await importOriginal<typeof NextNavigation>()),
  useRouter: () => useMockRouter(),
  usePathname: () => useMockPathname(),
  useSearchParams: () => useMockSearchParams(),
}));

const CONFIG: TableParamsConfig = {
  sortableColumns: ['name', 'created_at'],
  defaultSort: { id: 'name', desc: false },
  filterNames: ['q', 'status'],
  pageSizes: [25, 50, 100],
};

function Harness() {
  const view = useTableParams(CONFIG);

  return (
    <div>
      <output data-testid="request">{JSON.stringify(view.requestParams)}</output>
      <span data-testid="filtered">{String(view.isFiltered)}</span>
      <button type="button" onClick={() => view.onSortingChange([{ id: 'created_at', desc: true }])}>
        sort by created
      </button>
      <button type="button" onClick={() => view.onSortingChange([])}>
        remove the sort
      </button>
      <button
        type="button"
        onClick={() => view.onPaginationChange((page) => ({ ...page, pageIndex: page.pageIndex + 1 }))}
      >
        next page
      </button>
      <button
        type="button"
        onClick={() => view.onPaginationChange((page) => ({ ...page, pageSize: 50 }))}
      >
        fifty per page
      </button>
      <button type="button" onClick={() => view.setFilter('q', 'invoice')}>
        search invoice
      </button>
      <button type="button" onClick={() => view.clearFilters()}>
        clear filters
      </button>
      <button type="button" onClick={() => view.goToFirstPage()}>
        first page
      </button>
    </div>
  );
}

/** The single navigation an interaction is allowed to cause, or a readable failure naming how many. */
function onlyCall() {
  const calls = navigationCalls();
  expect(calls).toHaveLength(1);
  const [call] = calls;
  if (call === undefined) throw new Error('unreachable: length asserted above');
  return { ...call, query: new URLSearchParams(call.href.split('?')[1] ?? '') };
}

const requestParams = (element: Element | null) =>
  JSON.parse(element?.textContent ?? '{}') as Record<string, string>;

beforeEach(() => {
  resetNavigation();
});

describe('the page reset happens in the SAME navigation as the change that caused it', () => {
  it('drops the page when the sort changes', async () => {
    resetNavigation('page=7&sort=name&dir=asc');
    const screen = await render(<Harness />);

    await screen.getByRole('button', { name: 'sort by created' }).click();

    const call = onlyCall();
    expect(call.query.get('sort')).toBe('created_at');
    expect(call.query.get('dir')).toBe('desc');
    // Absent rather than `page=1`: page 1 is the default and a canonical URL omits its defaults.
    expect(call.query.get('page')).toBeNull();
  });

  it('drops the page when a filter changes', async () => {
    resetNavigation('page=7');
    const screen = await render(<Harness />);

    await screen.getByRole('button', { name: 'search invoice' }).click();

    const call = onlyCall();
    expect(call.query.get('q')).toBe('invoice');
    expect(call.query.get('page')).toBeNull();
  });

  it('drops the page when the page SIZE changes, because page 4 means something else afterwards', async () => {
    resetNavigation('page=7');
    const screen = await render(<Harness />);

    await screen.getByRole('button', { name: 'fifty per page' }).click();

    const call = onlyCall();
    expect(call.query.get('per_page')).toBe('50');
    expect(call.query.get('page')).toBeNull();
  });

  it('drops the page when filters are cleared', async () => {
    resetNavigation('page=7&q=invoice');
    const screen = await render(<Harness />);

    await screen.getByRole('button', { name: 'clear filters' }).click();

    const call = onlyCall();
    expect(call.query.get('q')).toBeNull();
    expect(call.query.get('page')).toBeNull();
  });

  it('does NOT drop the page when only the page changes', async () => {
    const screen = await render(<Harness />);

    await screen.getByRole('button', { name: 'next page' }).click();

    expect(onlyCall().query.get('page')).toBe('2');
  });
});

describe('history entries follow what the user thinks they did', () => {
  it('pushes a page change, so Back returns to the page they came from', async () => {
    const screen = await render(<Harness />);

    await screen.getByRole('button', { name: 'next page' }).click();

    expect(onlyCall().history).toBe('push');
  });

  it.each([
    ['sort by created'],
    ['search invoice'],
    ['fifty per page'],
    ['clear filters'],
  ])('replaces on %s, so Back does not walk backwards through typing', async (name) => {
    const screen = await render(<Harness />);

    await screen.getByRole('button', { name }).click();

    expect(onlyCall().history).toBe('replace');
  });

  it('never scrolls to the top: a pager at the foot of a long table would be unreachable', async () => {
    const screen = await render(<Harness />);

    await screen.getByRole('button', { name: 'next page' }).click();

    expect(onlyCall().scroll).toBe(false);
  });
});

describe('the state round-trips through the URL', () => {
  it('re-reads what it wrote, so the next interaction starts from the new view', async () => {
    const screen = await render(<Harness />);

    await screen.getByRole('button', { name: 'search invoice' }).click();
    await expect.element(screen.getByTestId('filtered')).toHaveTextContent('true');

    // The SECOND interaction is the one that proves the round trip: `next page` computes from the
    // pageIndex the hook read back out of the URL, not from a stale closure.
    await screen.getByRole('button', { name: 'next page' }).click();

    const params = requestParams(screen.getByTestId('request').element());
    expect(params).toEqual({ page: '2', per_page: '25', sort: 'name', dir: 'asc', q: 'invoice' });
  });

  it('reports isFiltered false again after clearing', async () => {
    resetNavigation('q=invoice');
    const screen = await render(<Harness />);

    await expect.element(screen.getByTestId('filtered')).toHaveTextContent('true');
    await screen.getByRole('button', { name: 'clear filters' }).click();
    await expect.element(screen.getByTestId('filtered')).toHaveTextContent('false');
  });

  it('returns to a clean pathname when every value is back to its default', async () => {
    resetNavigation('page=7&q=invoice');
    const screen = await render(<Harness />);

    await screen.getByRole('button', { name: 'clear filters' }).click();

    expect(onlyCall().href).toBe(MOCK_PATHNAME);
  });
});

describe('a sort can never be removed', () => {
  it('falls back to the default rather than requesting an unordered page', async () => {
    // Offset pagination over an unordered result set may repeat a row on page 2 that page 1 already
    // showed. `enableSortingRemoval: false` on the table makes this unreachable by clicking; the
    // callback is public, so it is asserted here.
    resetNavigation('sort=created_at&dir=desc');
    const screen = await render(<Harness />);

    await screen.getByRole('button', { name: 'remove the sort' }).click();

    const params = requestParams(screen.getByTestId('request').element());
    expect(params['sort']).toBe('name');
    expect(params['dir']).toBe('asc');
  });
});
