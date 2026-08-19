import { KbError } from '@kb/contracts';
import { PlusIcon } from 'lucide-react';
import type * as NextNavigation from 'next/navigation';
import { render } from 'vitest-browser-react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ServerDataTable, type ServerDataTableStatus } from '@/components/server-data-table';
import { EmptyState } from '@/components/states';
import { Button } from '@/components/ui/button';
import { createServerColumnHelper } from '@/lib/table/features';
import type { TableParamsConfig } from '@/lib/table/params';
import { useTableParams } from '@/lib/table/use-table-params';

import {
  MOCK_PATHNAME,
  navigationCalls,
  resetNavigation,
  useMockPathname,
  useMockRouter,
  useMockSearchParams,
} from '../support/mock-navigation';

/**
 * The generic server-driven table.
 *
 * ── WHAT THIS SPEC MAY NOT CLAIM ─────────────────────────────────────────────────────────────────
 * Nothing about tenant isolation. This component never fetches and holds no organization; the org
 * namespace lives in the query key its CALLER builds, and "organization A's rows never appear after a
 * switch" is Playwright's claim to make against a real server (`vitest-playwright` boundary table).
 *
 * ── NO CSS IS IMPORTED, ON PURPOSE ───────────────────────────────────────────────────────────────
 * Only `design-system-css.test.tsx` imports `globals.css`. Without it the `md:` breakpoint utilities
 * do nothing, so BOTH layouts are in the DOM at once — which is what makes "both layouts are fed from
 * one data source" assertable here at all. What CSS decides (which one is visible at which width) is
 * not this spec's claim and is not asserted; the responsive check is a Playwright/manual one.
 */
vi.mock('next/navigation', async (importOriginal) => ({
  ...(await importOriginal<typeof NextNavigation>()),
  useRouter: () => useMockRouter(),
  usePathname: () => useMockPathname(),
  useSearchParams: () => useMockSearchParams(),
}));

interface BotRow {
  readonly id: string;
  readonly name: string;
  readonly status: string;
  readonly chunk_count: number;
}

const helper = createServerColumnHelper<BotRow>();

/** Module scope, like every real call site: a fresh array each render rebuilds the column model. */
const columns = helper.columns([
  // The card layout's heading. Exactly one column carries it.
  helper.accessor('name', { header: 'Name', meta: { card: 'title' } }),
  // Not sortable: the server orders by `name` and `created_at` and nothing else, and a header that
  // offers an ordering the server will reject is a 422 one click away.
  helper.accessor('status', { header: 'Status', enableSorting: false }),
  helper.accessor('chunk_count', {
    header: 'Chunks',
    enableSorting: false,
    meta: { align: 'end', cardLabel: 'Chunks' },
  }),
  helper.display({
    id: 'actions',
    header: 'Actions',
    meta: { card: 'action' },
    cell: ({ row }) => <Button size="sm">Delete {row.original.name}</Button>,
  }),
]);

const CONFIG: TableParamsConfig = {
  sortableColumns: ['name', 'created_at'],
  defaultSort: { id: 'name', desc: false },
  // The endpoint's own free-text parameter name (`ListQuery::rules()`), not an invented `q`.
  filterNames: ['filter'],
  pageSizes: [25, 50],
};

const ROWS: readonly BotRow[] = [
  { id: 'bot-1', name: 'Support bot', status: 'Active', chunk_count: 1204 },
  { id: 'bot-2', name: 'Billing bot', status: 'Paused', chunk_count: 88 },
];

/**
 * `'loading'` and `'unknown'` are sentinels rather than `undefined`, and that is a real defect this
 * spec hit: a default parameter applies when the prop is `undefined`, so `rows={undefined}` silently
 * became `ROWS` and `rowCount={undefined}` silently became 137 — the pager test then asserted the
 * skeleton against a fully loaded pager and failed for the right reason with the wrong message.
 */
function Harness({
  rows = ROWS,
  rowCount = 137,
  status = 'success' as ServerDataTableStatus,
  error,
  isFetching = false,
}: {
  readonly rows?: readonly BotRow[] | 'loading';
  readonly rowCount?: number | 'unknown';
  readonly status?: ServerDataTableStatus;
  readonly error?: unknown;
  readonly isFetching?: boolean;
}) {
  const view = useTableParams(CONFIG);

  return (
    <ServerDataTable
      caption="Bots"
      columns={columns}
      rows={rows === 'loading' ? undefined : rows}
      rowCount={rowCount === 'unknown' ? undefined : rowCount}
      getRowId={(row) => row.id}
      status={status}
      error={error}
      isFetching={isFetching}
      requiredRole="Admin"
      organizationName="Acme Research"
      view={view}
      emptyState={
        <EmptyState
          glyph={PlusIcon}
          title="No bots yet"
          body="A bot answers from the sources you give it."
          action={<Button>Add bot</Button>}
        />
      }
      describeFilter={'No bots match "invoice".'}
    />
  );
}

const cards = () => document.querySelectorAll('[data-slot="data-table-card"]');

beforeEach(() => {
  resetNavigation();
});

describe('success: one data source, two layouts', () => {
  it('renders a real table with column headers, not a grid of divs', async () => {
    const screen = await render(<Harness />);

    // A `<table>` with `<th scope>`; a div grid with role="table" loses row and column announcements
    // in at least one screen reader (kb-ui-accessibility).
    const table = screen.getByRole('table', { name: 'Bots' });
    await expect.element(table).toBeInTheDocument();
    expect(document.querySelectorAll('th[scope="col"]')).toHaveLength(columns.length);
    expect(document.querySelectorAll('tbody tr')).toHaveLength(ROWS.length);
  });

  it('feeds the below-768px card layout from the same rows and the same column definitions', async () => {
    await render(<Harness />);

    expect(cards()).toHaveLength(ROWS.length);
    // The title comes from the column carrying `meta.card: 'title'`, and the labelled pairs from the
    // columns that do not — one set of definitions, so the two layouts cannot disagree.
    expect(cards()[0]?.textContent).toContain('Support bot');
    expect(cards()[0]?.textContent).toContain('Chunks');
    // The action cell is rendered outside the <dl>: a <button> between a <dt> and a <dd> is invalid
    // content for a description list.
    expect(cards()[0]?.querySelector('dl')?.textContent).not.toContain('Delete Support bot');
  });

  it('keeps rows on screen during a refetch instead of blanking them', async () => {
    const screen = await render(<Harness isFetching />);

    // Refetch is not a skeleton: data already on screen and still correct stays on screen.
    await expect.element(screen.getByRole('table', { name: 'Bots' })).toBeInTheDocument();
    expect(document.querySelectorAll('[aria-busy="true"]')).toHaveLength(0);
  });
});

describe('sorting is server-driven and announced', () => {
  it('makes a sortable header a button and leaves an unsortable one plain text', async () => {
    const screen = await render(<Harness />);

    await expect.element(screen.getByRole('button', { name: 'Name' })).toBeInTheDocument();
    // `Status` is a header with no button: a `<th>` with an onClick is not focusable, not activatable
    // by Enter or Space, and invisible to assistive technology.
    expect(screen.getByRole('button', { name: 'Status' }).elements()).toHaveLength(0);
  });

  it('carries aria-sort on the sorted column and none on the other sortable ones', async () => {
    await render(<Harness />);

    const sorted = document.querySelector('th[aria-sort]');
    expect(sorted?.getAttribute('aria-sort')).toBe('ascending');
    expect(sorted?.textContent).toContain('Name');
    // Unsortable columns get no aria-sort at all; "none" would claim they can be sorted.
    expect(document.querySelectorAll('th[aria-sort]')).toHaveLength(1);
  });

  it('resets the page in the SAME navigation when a header is clicked from page 3', async () => {
    resetNavigation('page=3');
    const screen = await render(<Harness />);

    await screen.getByRole('button', { name: 'Name' }).click();

    // ONE navigation. Two would arrive at the same URL and still be the bug: under manual pagination
    // a reset performed as a second update is what asks for page 8 of a 2-page result.
    expect(navigationCalls()).toHaveLength(1);
    const query = new URLSearchParams(navigationCalls()[0]?.href.split('?')[1] ?? '');
    expect(query.get('sort')).toBe('name');
    expect(query.get('dir')).toBe('desc');
    expect(query.get('page')).toBeNull();
  });

  it('flips the announced direction after the click', async () => {
    const screen = await render(<Harness />);

    await screen.getByRole('button', { name: 'Name' }).click();

    await expect
      .poll(() => document.querySelector('th[aria-sort]')?.getAttribute('aria-sort'))
      .toBe('descending');
  });
});

describe('loading', () => {
  it('renders a table-shaped skeleton and no table', async () => {
    const screen = await render(<Harness status="pending" rows="loading" rowCount="unknown" />);

    expect(document.querySelectorAll('[aria-busy="true"]').length).toBeGreaterThan(0);
    expect(screen.getByRole('table').elements()).toHaveLength(0);
    // A skeleton is never announced: the container carries aria-busy, the blocks are aria-hidden.
    expect(document.querySelectorAll('[data-slot="skeleton"]:not([aria-hidden])')).toHaveLength(0);
  });
});

describe('error', () => {
  it('renders the class-mapped sentence and the request id, never the envelope message', async () => {
    const error = new KbError(
      'internal_dependency',
      true,
      null,
      'req_01JXYZ',
      'pg-primary-3.internal: connection refused',
    );
    const screen = await render(<Harness status="error" rows="loading" error={error} />);

    await expect
      .element(screen.getByText('Something on our side is unavailable. Try again shortly.'))
      .toBeInTheDocument();
    await expect.element(screen.getByText('req_01JXYZ')).toBeInTheDocument();
    // The envelope's `message` is operator-facing: an internal hostname reaches no rendered string.
    expect(document.body.textContent).not.toContain('pg-primary-3.internal');
    await expect.element(screen.getByText('Bots could not be loaded')).toBeInTheDocument();
  });

  it('renders the forbidden state naming the role for an authorization class', async () => {
    const error = new KbError('authorization', false, null, 'req_denied', 'forbidden');
    const screen = await render(<Harness status="error" rows="loading" error={error} />);

    await expect.element(screen.getByText("You don't have access to this")).toBeInTheDocument();
    // Naming the role and who can grant it turns a dead end into an action.
    await expect.element(screen.getByText(/Admin role/)).toBeInTheDocument();
    await expect.element(screen.getByText(/Acme Research/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Try again' }).elements()).toHaveLength(0);
  });

  it('offers no retry for an unparsed envelope, because unknown is permanently non-retryable', async () => {
    const screen = await render(
      <Harness status="error" rows="loading" error={new KbError(null, false)} />,
    );

    // The `error_class: null` copy from ERROR_COPY.unknown, verbatim: no class was parsed, so no
    // class-specific sentence exists and none is invented.
    await expect
      .element(screen.getByText(/the reason was not reported/))
      .toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Try again' }).elements()).toHaveLength(0);
  });
});

describe('the empty split — the whole reason two components exist', () => {
  it('offers the create action when nothing has ever been created', async () => {
    const screen = await render(<Harness rows={[]} rowCount={0} />);

    await expect.element(screen.getByText('No bots yet')).toBeInTheDocument();
    await expect.element(screen.getByRole('button', { name: 'Add bot' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Clear filters' }).elements()).toHaveLength(0);
  });

  it('offers Clear filters — and NOT "Add bot" — when a filter matched nothing', async () => {
    resetNavigation('filter=invoice');
    const screen = await render(<Harness rows={[]} rowCount={0} />);

    await expect.element(screen.getByText('No matches')).toBeInTheDocument();
    await expect.element(screen.getByText('No bots match "invoice".')).toBeInTheDocument();
    // Offering "Create your first bot" to somebody whose search matched nothing is the bug this split
    // exists to prevent.
    expect(screen.getByRole('button', { name: 'Add bot' }).elements()).toHaveLength(0);
    await expect.element(screen.getByRole('button', { name: 'Clear filters' })).toBeInTheDocument();
  });

  it('clearing the filter from the empty state returns to the clean address', async () => {
    resetNavigation('filter=invoice&page=4');
    const screen = await render(<Harness rows={[]} rowCount={0} />);

    await screen.getByRole('button', { name: 'Clear filters' }).click();

    expect(navigationCalls()).toHaveLength(1);
    expect(navigationCalls()[0]?.href).toBe(MOCK_PATHNAME);
  });

  it('treats an out-of-range page as its own state, not as a filtered empty', async () => {
    // Unreachable by clicking — every in-app path resets the page — but `?page=99` is typeable.
    resetNavigation('page=99');
    const screen = await render(<Harness rows={[]} rowCount={137} />);

    await expect.element(screen.getByText('That page is empty')).toBeInTheDocument();
    // "Clear filters" is the wrong advice for someone whose filter is fine and whose page is not.
    expect(screen.getByRole('button', { name: 'Clear filters' }).elements()).toHaveLength(0);

    await screen.getByRole('button', { name: 'Go to first page' }).click();
    expect(navigationCalls()[0]?.href).toBe(MOCK_PATHNAME);
  });
});

describe('the pager', () => {
  it('states the range out of the envelope total', async () => {
    const screen = await render(<Harness />);

    // `rowCount` comes from meta.total. Without it the table treats data.length as everything.
    await expect.element(screen.getByText('1–2 of 137')).toBeInTheDocument();
  });

  it('renders a skeleton rather than a total it does not have', async () => {
    const screen = await render(<Harness rowCount="unknown" />);

    // Not "Page 1 of 1" and not 0: both are statements, and there is no answer yet.
    expect(screen.getByText(/of 137/).elements()).toHaveLength(0);
    expect(document.querySelectorAll('[aria-busy="true"]').length).toBeGreaterThan(0);
  });

  it('disables Previous on the first page', async () => {
    const screen = await render(<Harness />);

    await expect.element(screen.getByRole('button', { name: 'Previous page' })).toBeDisabled();
    await expect.element(screen.getByRole('button', { name: 'Next page' })).toBeEnabled();
  });

  it('disables Next once the range reaches the total', async () => {
    // Page 6 of 25 starts at row 126; two rows on it end at 127, which IS the total. `pageCount: -1`
    // or a missing `rowCount` would leave Next unconditionally enabled here.
    resetNavigation('page=6');
    const screen = await render(<Harness rowCount={127} />);

    await expect.element(screen.getByText('126–127 of 127')).toBeInTheDocument();
    await expect.element(screen.getByRole('button', { name: 'Next page' })).toBeDisabled();
    await expect.element(screen.getByRole('button', { name: 'Previous page' })).toBeEnabled();
  });

  it('pages forward with a single pushed navigation', async () => {
    const screen = await render(<Harness />);

    await screen.getByRole('button', { name: 'Next page' }).click();

    expect(navigationCalls()).toHaveLength(1);
    expect(navigationCalls()[0]?.history).toBe('push');
    expect(navigationCalls()[0]?.href).toBe(`${MOCK_PATHNAME}?page=2`);
  });

  it('offers only the page sizes the parser will accept', async () => {
    const screen = await render(<Harness />);

    const trigger = screen.getByRole('combobox', { name: /Rows per page/ });
    await expect.element(trigger).toHaveTextContent('25');
    await trigger.click();
    // A select offering a size the URL parser discards makes the address say one thing and the
    // request another.
    expect(screen.getByRole('option').elements()).toHaveLength(CONFIG.pageSizes.length);
  });

  it('resets to the first page when the page size changes, in one navigation', async () => {
    resetNavigation('page=4');
    const screen = await render(<Harness />);

    await screen.getByRole('combobox', { name: /Rows per page/ }).click();
    await screen.getByRole('option', { name: '50' }).click();

    // Page 4 of 25-row pages is not page 4 of 50-row pages, so holding the page number would show
    // rows the user never asked for — or none at all, past the new last page.
    expect(navigationCalls()).toHaveLength(1);
    expect(navigationCalls()[0]?.href).toBe(`${MOCK_PATHNAME}?per_page=50`);
  });
});
