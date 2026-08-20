import type { SourceResource } from '@kb/contracts';
import type { QueryClient } from '@tanstack/react-query';
import { useQueryClient } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import type * as NextNavigation from 'next/navigation';
import { useEffect } from 'react';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Providers } from '@/components/providers';
import { SessionProvider } from '@/features/auth/session-provider';
import { SOURCE_POLL_MS } from '@/features/sources/api';
import { SourcesScreen } from '@/features/sources/sources-screen';

import { envelope, ORIGIN, sessionFixture } from '../msw/handlers';
import { worker } from '../msw/setup';
import {
  MOCK_PATHNAME,
  navigationCalls,
  resetNavigation,
  useMockPathname,
  useMockRouter,
  useMockSearchParams,
} from '../support/mock-navigation';

/**
 * `/sources` — the server-driven, POLLING source list, its three lifecycle mutations and its states.
 *
 * ── WHAT THIS SPEC MAY NOT CLAIM ─────────────────────────────────────────────────────────────────
 * It proves the query key is org-namespaced AND carries every server-visible sort, filter and page
 * value, and that the same values reach the request. It proves NOTHING about isolation: a component
 * test that mocks the API cannot fail an isolation test, and per the `vitest-playwright` boundary
 * table it must never be cited as isolation coverage. "Organization A's sources never appear after
 * switching to B" is Playwright's claim, against a real server.
 *
 * ── `next/navigation` IS MOCKED BECAUSE THE URL IS THE TABLE'S STATE ─────────────────────────────
 * `useRouter()` throws outside a mounted App Router, and a bare spy would swallow the href — leaving
 * `useTableParams` with nothing to read, so every assertion after the first click would be asserting
 * the initial state. `tests/support/mock-navigation.ts` is an observable store instead.
 *
 * ── NO CSS IS IMPORTED, ON PURPOSE ───────────────────────────────────────────────────────────────
 * Only `design-system-css.test.tsx` imports `globals.css`. Without it the `md:` utilities do nothing,
 * so BOTH layouts — the table above 768px and the row-cards below it — are in the DOM at once, which
 * is what makes "one data source, two layouts" assertable here. Which one is VISIBLE at which width
 * is CSS's decision and is a Playwright/manual check.
 *
 * ── EVERY `render` IS AWAITED ────────────────────────────────────────────────────────────────────
 * `vitest-browser-react`'s `render` returns a promise. An un-awaited one PASSES and then times out
 * every test after it against an empty `<body>`, and ESLint cannot see it — a cluster of timeouts in
 * this file means the spec that passed just before them forgot the `await`.
 */
vi.mock('next/navigation', async (importOriginal) => ({
  ...(await importOriginal<typeof NextNavigation>()),
  useRouter: () => useMockRouter(),
  usePathname: () => useMockPathname(),
  useSearchParams: () => useMockSearchParams(),
}));

/** `sessionFixture()`'s current organization, and the namespace every key below must carry. */
const ORG_A = '01JORGAAAAAAAAAAAAAAAAAAAA';
/** The other ACTIVE membership in the same fixture, where this user is an ANALYST — the one role that
 *  does not hold `sources.view`. Present so the namespacing assertion is about the CURRENT
 *  organization rather than the only one there is, and so the forbidden state has a real subject. */
const ORG_B = '01JORGBBBBBBBBBBBBBBBBBBBB';

const sourcesUrl = (orgId: string) => `${ORIGIN}/api/v1/organizations/${orgId}/sources`;

/** A ready file source with every field the resource publishes. */
const HANDBOOK: SourceResource = {
  id: '01JSOURCEAAAAAAAAAAAAAAAAA',
  type: 'file',
  name: 'Q3 handbook.pdf',
  description: 'The quarterly staff handbook.',
  origin_url: null,
  status: 'ready',
  status_permits_retrieval: true,
  status_is_processing: false,
  tags: ['hr'],
  effective_at: null,
  expires_at: null,
  created_by: '01JUSERAAAAAAAAAAAAAAAAAAA',
  created_at: '2026-08-01T09:00:00+00:00',
  updated_at: '2026-08-02T09:00:00+00:00',
  deleted_at: null,
  purged_at: null,
};

/** A crawl that published WITH warnings, and is therefore answering — the state whose whole reason to
 *  exist is that it is not the same as `ready`. */
const CRAWL: SourceResource = {
  ...HANDBOOK,
  id: '01JSOURCEBBBBBBBBBBBBBBBBB',
  type: 'url',
  name: 'Support centre',
  origin_url: 'https://support.example.test/help',
  status: 'ready_with_warnings',
  tags: [],
};

/** Mid-run: the state the poll exists for. */
const INGESTING: SourceResource = {
  ...HANDBOOK,
  id: '01JSOURCECCCCCCCCCCCCCCCCC',
  name: 'Pricing deck',
  status: 'embedding',
  status_permits_retrieval: false,
  status_is_processing: true,
};

/** Withdrawn by hand — the row whose action verb is Enable rather than Disable. */
const DISABLED: SourceResource = {
  ...HANDBOOK,
  id: '01JSOURCEDDDDDDDDDDDDDDDDD',
  name: 'Old policy',
  status: 'disabled',
  status_permits_retrieval: false,
};

/** Phase 1 done, purge in flight. */
const DELETING: SourceResource = {
  ...HANDBOOK,
  id: '01JSOURCEEEEEEEEEEEEEEEEEE',
  name: 'Retired FAQ',
  status: 'deleting',
  status_permits_retrieval: false,
  deleted_at: '2026-08-20T10:00:00+00:00',
  purged_at: null,
};

/** The envelope `PaginatedCollection` writes: `meta` is a SIBLING of the array INSIDE `data`. */
const pageBody = (rows: readonly SourceResource[], meta: Record<string, unknown> = {}) => ({
  data: {
    sources: rows,
    meta: {
      page: 1,
      per_page: 25,
      total: rows.length,
      total_pages: 1,
      sort: 'id',
      dir: 'asc',
      filter: null,
      ...meta,
    },
  },
});

const listHandler = (rows: readonly SourceResource[] = [HANDBOOK, CRAWL], meta = {}) =>
  http.get(sourcesUrl(ORG_A), () => HttpResponse.json(pageBody(rows, meta)));

/** Captures the tree's QueryClient so the KEYS themselves can be asserted, not just request URLs. The
 *  organization is not in this screen's URL at all, so the key is the artifact worth reading. The
 *  write happens in an EFFECT: `react-hooks/globals` is an error on reassigning a module-scope
 *  variable from a render body. */
let captured: QueryClient | null = null;
function CaptureClient() {
  const queryClient = useQueryClient();
  useEffect(() => {
    captured = queryClient;
  }, [queryClient]);
  return null;
}

const renderScreen = () =>
  render(
    <Providers>
      <SessionProvider>
        <CaptureClient />
        <SourcesScreen />
      </SessionProvider>
    </Providers>,
  );

const cacheKeys = (): unknown[][] =>
  (captured?.getQueryCache().getAll() ?? []).map((query) => [...query.queryKey]);

const cards = () => document.querySelectorAll('[data-slot="data-table-card"]');

/**
 * Open one row's overflow menu and click an item.
 *
 * THE ROW IS ADDRESSED BY THE TRIGGER'S NAME AND THE ACTION BY THE ITEM'S. The three row actions live
 * in a menu rather than in three buttons because at 768px three text controls pushed the actions
 * column out of the table's own scroller — see `source-row-actions.tsx`. `.first()` because no CSS is
 * imported here, so the table and the row-card layouts are both in the DOM.
 */
const chooseRowAction = async (
  screen: Awaited<ReturnType<typeof renderScreen>>,
  sourceName: string,
  action: string,
) => {
  await screen.getByRole('button', { name: `Actions for ${sourceName}` }).first().click();
  await screen.getByRole('menuitem', { name: action }).click();
};

beforeEach(() => {
  // `readCookie` reads the PAGE's cookie and MSW cannot set one for a cross-origin host from a service
  // worker, so without this every spec takes the `refreshCsrfToken()` path and fails for a reason that
  // has nothing to do with what it is about.
  document.cookie = 'XSRF-TOKEN=test-token';
  captured = null;
  resetNavigation();
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

describe('the query key is org-namespaced and carries the whole view', () => {
  it('puts every server-visible sort, filter and page value after `[org, orgId, sources]`', async () => {
    resetNavigation('page=2&per_page=50&sort=name&dir=desc&filter=handbook');
    worker.use(listHandler([HANDBOOK], { page: 2, per_page: 50, total: 60, total_pages: 2 }));

    const screen = await renderScreen();
    await expect.element(screen.getByText('Q3 handbook.pdf').first()).toBeVisible();

    await vi.waitFor(() => {
      expect(cacheKeys()).toContainEqual([
        'org',
        ORG_A,
        'sources',
        { page: '2', per_page: '50', sort: 'name', dir: 'desc', filter: 'handbook' },
      ]);
    });

    const keys = cacheKeys();
    // `['session']` is the ONE legal exception to the org prefix — it is the PRODUCER of `orgId`.
    expect(keys.filter((key) => key[0] !== 'org')).toEqual([['session']]);
    // Not one key mentions the organization the session is NOT currently acting in.
    expect(JSON.stringify(keys)).not.toContain(ORG_B);
  });

  it('sends those same values on the wire, and never the organization as a parameter', async () => {
    resetNavigation('page=2&sort=status&dir=asc');
    const queries: string[] = [];
    worker.use(
      http.get(sourcesUrl(ORG_A), ({ request }) => {
        queries.push(new URL(request.url).search);
        return HttpResponse.json(pageBody([HANDBOOK], { page: 2, total: 30, total_pages: 2 }));
      }),
    );

    const screen = await renderScreen();
    await expect.element(screen.getByText('Q3 handbook.pdf').first()).toBeVisible();

    await vi.waitFor(() => {
      expect(queries).toEqual(['?page=2&per_page=25&sort=status&dir=asc']);
    });
    expect(queries[0]).not.toContain('org');
  });
});

describe('success: one data source, two layouts', () => {
  it('renders a real table with column headers, and the same rows as cards', async () => {
    worker.use(listHandler());

    const screen = await renderScreen();

    const table = screen.getByRole('table', { name: 'Knowledge sources' });
    await expect.element(table).toBeInTheDocument();
    expect(document.querySelectorAll('tbody tr')).toHaveLength(2);
    // Five columns: Name, Type, Status, Added, and the row actions — whose header is a visually
    // hidden "Actions" rather than an empty `<th>`, because an unlabelled column is unreadable to a
    // screen-reader user even when its cells are self-describing.
    expect(document.querySelectorAll('th[scope="col"]')).toHaveLength(5);

    expect(cards()).toHaveLength(2);
    expect(cards()[0]?.textContent).toContain('Q3 handbook.pdf');
    // `card: 'hidden'` on Added: present in the table, dropped from the card, because a card with
    // five labelled pairs is a table with extra steps.
    expect(cards()[0]?.textContent).not.toContain('Added');
  });

  it('renders `ready_with_warnings` as its OWN outcome, never as a green tick', async () => {
    worker.use(listHandler());

    const screen = await renderScreen();

    // `exact`, because role-name matching is a case-insensitive SUBSTRING: a locator for "Ready"
    // otherwise resolves the "Ready with warnings" cell too, which is the very distinction this test
    // is about. `.first()` on the rest, because no CSS is imported here and BOTH layouts — the table
    // and the row-cards — are in the DOM at once.
    await expect
      .element(screen.getByRole('cell', { name: 'Ready', exact: true }).first())
      .toBeVisible();
    // The word is the channel that survives greyscale and CVD; the tone (`degraded`) is the second.
    // Collapsing this into `Ready` would lose the only signal that says "this parsed badly and
    // published anyway".
    await expect
      .element(screen.getByRole('cell', { name: 'Ready with warnings' }).first())
      .toBeVisible();
  });

  it('renders a crawl`s origin URL as TEXT, never as a link', async () => {
    worker.use(listHandler());

    const screen = await renderScreen();
    await expect.element(screen.getByText('Support centre').first()).toBeVisible();

    // `origin_url` is a URL A STRANGER CHOSE — the column that answers "which sources make this
    // platform issue outbound requests". Making it clickable is a decision about following a
    // tenant-supplied URL from an authenticated admin origin, and this list does not take it.
    expect(document.querySelectorAll('a[href*="support.example.test"]')).toHaveLength(0);
    await expect
      .element(screen.getByText('https://support.example.test/help').first())
      .toBeVisible();
  });

  it('states the range from the envelope`s own total rather than from the rows in hand', async () => {
    worker.use(listHandler([HANDBOOK, CRAWL], { total: 137, total_pages: 6 }));

    const screen = await renderScreen();

    await expect.element(screen.getByText('1–2 of 137')).toBeInTheDocument();
    await expect.element(screen.getByRole('button', { name: 'Next page' })).toBeEnabled();
  });
});

describe('sorting is server-driven and its offer comes from the manifest', () => {
  it('offers a header button only for a column the endpoint will order by', async () => {
    worker.use(listHandler());

    const screen = await renderScreen();
    await expect.element(screen.getByText('Q3 handbook.pdf').first()).toBeVisible();

    // `id`, `name`, `type`, `status` — read out of `IndexSourcesRequest.json`, so a header can never
    // offer an ordering `Rule::in($sortable)` would reject.
    await expect.element(screen.getByRole('button', { name: 'Name' })).toBeInTheDocument();
    await expect.element(screen.getByRole('button', { name: 'Type' })).toBeInTheDocument();
    await expect.element(screen.getByRole('button', { name: 'Status' })).toBeInTheDocument();
    // `Added` accesses `created_at` and IDENTIFIES as `id`, which is what the database can actually
    // order by — the ULID's byte order IS creation order.
    await expect.element(screen.getByRole('button', { name: 'Added' })).toBeInTheDocument();
  });

  it('resets the page in the SAME navigation as the sort', async () => {
    resetNavigation('page=3');
    worker.use(listHandler([HANDBOOK], { page: 3, total: 137, total_pages: 6 }));

    const screen = await renderScreen();
    await screen.getByRole('button', { name: 'Type' }).click();

    // ONE navigation. Two would arrive at the same URL and still be the bug: under manual pagination
    // the table never resets the page itself.
    expect(navigationCalls()).toHaveLength(1);
    expect(navigationCalls()[0]?.href).toBe(`${MOCK_PATHNAME}?sort=type&dir=asc`);
    expect(navigationCalls()[0]?.history).toBe('replace');
  });
});

describe('the poll, and the stop condition that is the whole reason it is a function', () => {
  /**
   * These read the MOUNTED query's own `refetchInterval` and call it with its query, which is the
   * wiring assertion: that the option is a FUNCTION of the page in hand rather than a bare number.
   * The predicate itself — which of the fifteen states are settled, and why `queued` and `deleting`
   * are polled while `status_is_processing` is false — is pinned exhaustively in
   * `tests/unit/source-list.test.ts`. Advancing five seconds of real time per case here would buy the
   * same claim for thirty seconds of suite time.
   */
  const listQuery = () =>
    (captured?.getQueryCache().getAll() ?? []).find((query) => query.queryKey[2] === 'sources');

  /**
   * Read off the OBSERVER's options rather than the query's. `Query.options` carries the merged value
   * at runtime but is typed `QueryOptions`, which has no `refetchInterval` — polling is an observer
   * concern, because it belongs to a mounted component rather than to a cache entry. Reading it where
   * it is declared keeps this typed instead of cast.
   */
  const pollOption = () => listQuery()?.observers[0]?.options.refetchInterval;

  it('polls while a row is mid-run', async () => {
    worker.use(listHandler([HANDBOOK, INGESTING]));

    const screen = await renderScreen();
    await expect.element(screen.getByText('Pricing deck').first()).toBeVisible();

    const query = listQuery();
    const interval = pollOption();
    // THE FUNCTION FORM IS THE ASSERTION. A bare number would satisfy every other test in this file
    // and would never stop.
    expect(typeof interval).toBe('function');
    expect(typeof interval === 'function' && query !== undefined ? interval(query) : null).toBe(
      SOURCE_POLL_MS,
    );
  });

  it('STOPS once every row on the page is terminal', async () => {
    worker.use(listHandler([HANDBOOK, CRAWL, DISABLED]));

    const screen = await renderScreen();
    await expect.element(screen.getByText('Old policy').first()).toBeVisible();

    const query = listQuery();
    const interval = pollOption();
    // `false`, not `0` — `0` means "as fast as possible", which is the worst poll in the app. A bare
    // number never stops at all, and a hidden tab polling for hours is what starts rejecting real
    // requests at the admin limiter.
    expect(typeof interval === 'function' && query !== undefined ? interval(query) : null).toBe(
      false,
    );
  });
});

describe('the row actions', () => {
  it('offers Disable for a source that is answering, and sends the disable target', async () => {
    const bodies: unknown[] = [];
    worker.use(
      listHandler([HANDBOOK]),
      http.put(`${sourcesUrl(ORG_A)}/${HANDBOOK.id}/status`, async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ data: { ...HANDBOOK, status: 'disabled' } });
      }),
    );

    const screen = await renderScreen();
    // THE TRIGGER'S ACCESSIBLE NAME CARRIES THE SOURCE'S NAME: twenty-five triggers called "Actions"
    // is twenty-five identical announcements and a strict-mode failure for every locator. The ITEM
    // inside the menu carries only the verb, which is unambiguous once a menu is open.
    await chooseRowAction(screen, 'Q3 handbook.pdf', 'Disable');

    await vi.waitFor(() => {
      expect(bodies).toEqual([{ status: 'disabled' }]);
    });
  });

  it('offers Enable for a withdrawn source, and never picks a ready flavour itself', async () => {
    const bodies: unknown[] = [];
    worker.use(
      listHandler([DISABLED]),
      http.put(`${sourcesUrl(ORG_A)}/${DISABLED.id}/status`, async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ data: { ...DISABLED, status: 'ready_with_warnings' } });
      }),
    );

    const screen = await renderScreen();
    await chooseRowAction(screen, 'Old policy', 'Enable');

    await vi.waitFor(() => {
      // `ready` is the ONE spelling of enable on this wire; which of the two ready states it lands in
      // is read from the source's live versions by the server.
      expect(bodies).toEqual([{ status: 'ready' }]);
    });
  });

  it('submits a reprocess and re-reads the list rather than writing the row itself', async () => {
    let listCalls = 0;
    let reprocessed = 0;
    worker.use(
      http.get(sourcesUrl(ORG_A), () => {
        listCalls += 1;
        return HttpResponse.json(pageBody([HANDBOOK]));
      }),
      http.post(`${sourcesUrl(ORG_A)}/${HANDBOOK.id}/reprocess`, () => {
        reprocessed += 1;
        return HttpResponse.json({ data: { ...HANDBOOK, status: 'queued' } }, { status: 202 });
      }),
    );

    const screen = await renderScreen();
    await expect.element(screen.getByText('Q3 handbook.pdf').first()).toBeVisible();
    const before = listCalls;

    await chooseRowAction(screen, 'Q3 handbook.pdf', 'Reprocess');

    await vi.waitFor(() => {
      expect(reprocessed).toBe(1);
      // Invalidate-and-re-read, never a cache write: the client cannot compute which state a run
      // lands in, so the row the operator ends up looking at is the server's answer.
      expect(listCalls).toBeGreaterThan(before);
    });
  });

  it('offers NO action for a source whose removal is under way, and says which half it is in', async () => {
    worker.use(listHandler([DELETING]));

    const screen = await renderScreen();
    await expect.element(screen.getByText('Retired FAQ').first()).toBeVisible();

    // `deleted_at` says we removed it; `purged_at` says we PROVED it. §8.17 requires an administrator
    // to see deletion completion, and this caption plus the poll is the whole of how they do. It is
    // rendered under the status pill and NOWHERE ELSE — the actions cell used to repeat it, which put
    // the same sentence twice in one row.
    await expect.element(screen.getByText('Purge in progress').first()).toBeVisible();
    expect(
      screen.getByRole('button', { name: 'Actions for Retired FAQ' }).elements(),
    ).toHaveLength(0);
  });
});

describe('deleting a source', () => {
  it('states the consequence in terms the server will actually honour, and demands the name typed', async () => {
    worker.use(listHandler([HANDBOOK]));

    const screen = await renderScreen();
    await chooseRowAction(screen, 'Q3 handbook.pdf', 'Delete');

    const dialog = screen.getByRole('dialog');
    await expect.element(dialog).toBeVisible();
    // PHASE 1 OF 2, and every clause is true of what `destroy` does: one column, effective on the next
    // question, no purge dispatched by this request, and no way back.
    await expect.element(screen.getByText(/This is phase 1 of 2/)).toBeVisible();
    await expect.element(screen.getByText(/stops answering questions immediately/)).toBeVisible();
    await expect.element(screen.getByText(/Nothing has been removed yet/)).toBeVisible();

    // A SECOND CLICK IN THE SAME POSITION IS MUSCLE MEMORY; TYPING A NAME IS NOT.
    const confirm = screen.getByRole('button', { name: 'Delete source' });
    await expect.element(confirm).toBeDisabled();
  });

  it('sends the DELETE once the name is typed, and re-reads the list afterwards', async () => {
    let listCalls = 0;
    let deleted = 0;
    worker.use(
      http.get(sourcesUrl(ORG_A), () => {
        listCalls += 1;
        return HttpResponse.json(pageBody([HANDBOOK]));
      }),
      http.delete(`${sourcesUrl(ORG_A)}/${HANDBOOK.id}`, () => {
        deleted += 1;
        return HttpResponse.json({
          data: { ...HANDBOOK, status: 'deleting', deleted_at: '2026-08-20T10:00:00+00:00' },
        });
      }),
    );

    const screen = await renderScreen();
    await chooseRowAction(screen, 'Q3 handbook.pdf', 'Delete');
    await screen.getByLabelText(/to confirm/).fill(HANDBOOK.name);
    const before = listCalls;
    await screen.getByRole('button', { name: 'Delete source' }).click();

    await vi.waitFor(() => {
      expect(deleted).toBe(1);
      // NOT OPTIMISTIC. The browser cannot know a purge succeeded, and §8.17 requires an
      // administrator to SEE completion — which a cache entry cannot prove.
      expect(listCalls).toBeGreaterThan(before);
    });
  });
});

describe('a refused lifecycle move', () => {
  it('renders a sentence written for a reader, never the transition table`s own words', async () => {
    worker.use(
      listHandler([HANDBOOK]),
      http.put(`${sourcesUrl(ORG_A)}/${HANDBOOK.id}/status`, () =>
        HttpResponse.json(
          envelope('validation', {
            errors: {
              status: [
                'A row in `deleting` cannot move to `ready`. The legal moves are in ' +
                  'App\\Enums\\SourceState::transitionTable(), which is the only statement of the machine.',
              ],
            },
          }),
          { status: 422 },
        ),
      ),
    );

    const screen = await renderScreen();
    await chooseRowAction(screen, 'Q3 handbook.pdf', 'Disable');

    await expect
      .element(screen.getByText(/cannot do that from the state it is in now/))
      .toBeVisible();
    // The server's 422 message on THIS path names a PHP class. `actionErrorCopy` renders validation
    // messages verbatim on the ground that they are end-user copy, and on these three endpoints they
    // are not — so this path maps the class itself.
    expect(document.body.textContent).not.toContain('transitionTable');
    expect(document.body.textContent).not.toContain('App\\Enums');
  });

  it('renders the suspended-organization refusal VERBATIM, because it is written for the operator', async () => {
    worker.use(
      listHandler([HANDBOOK]),
      http.post(`${sourcesUrl(ORG_A)}/${HANDBOOK.id}/reprocess`, () =>
        HttpResponse.json(
          envelope('internal_dependency', {
            retryable: false,
            actionable: true,
            message:
              'This organization is suspended, so its configuration is read-only. Contact whoever ' +
              'operates this deployment to have it lifted.',
          }),
          { status: 409 },
        ),
      ),
    );

    const screen = await renderScreen();
    await chooseRowAction(screen, 'Q3 handbook.pdf', 'Reprocess');

    // The `actionable` FLAG is what separates a deliberate 4xx from a defect: both arrive as
    // `internal_dependency` + `retryable: false`, and the class-mapped sentence for that pair —
    // "something on our side is unavailable" — would be false twice over here.
    await expect.element(screen.getByText(/This organization is suspended/)).toBeVisible();
  });
});

describe('the empty split — the whole reason two components exist', () => {
  it('shows the first-run state when nothing has ever been added', async () => {
    worker.use(listHandler([], { total: 0 }));

    const screen = await renderScreen();

    await expect.element(screen.getByText('No sources yet')).toBeInTheDocument();
    await expect.element(screen.getByText(/Upload a document/)).toBeVisible();
    expect(screen.getByRole('button', { name: 'Clear filters' }).elements()).toHaveLength(0);
  });

  it('restates the query and offers Clear filters when a filter matched nothing', async () => {
    resetNavigation('filter=handbook');
    worker.use(listHandler([], { total: 0, filter: 'handbook' }));

    const screen = await renderScreen();

    await expect.element(screen.getByText('No matches')).toBeInTheDocument();
    // A user who cannot see their own query cannot tell a too-narrow filter from a broken screen.
    await expect.element(screen.getByText('No sources match “handbook”.')).toBeInTheDocument();
    // Offering "upload your first document" to somebody whose search matched nothing is the bug this
    // split exists to prevent.
    expect(screen.getByText('No sources yet').elements()).toHaveLength(0);
    await expect.element(screen.getByRole('button', { name: 'Clear filters' })).toBeInTheDocument();
  });

  it('treats an out-of-range page as its own state, not as a filtered empty', async () => {
    resetNavigation('page=99');
    worker.use(listHandler([], { page: 99, total: 137, total_pages: 6 }));

    const screen = await renderScreen();

    await expect.element(screen.getByText('That page is empty')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Clear filters' }).elements()).toHaveLength(0);
  });
});

describe('error and forbidden', () => {
  it('renders the class-mapped sentence and the request id, never the envelope message', async () => {
    worker.use(
      http.get(sourcesUrl(ORG_A), () =>
        HttpResponse.json(envelope('internal_dependency', { retryable: true }), { status: 503 }),
      ),
    );

    const screen = await renderScreen();

    await expect
      .element(screen.getByText('Something on our side is unavailable. Try again shortly.'))
      .toBeInTheDocument();
    await expect
      .element(screen.getByText('Knowledge sources could not be loaded'))
      .toBeInTheDocument();
    await expect.element(screen.getByText('01JREQFROMLARAVEL')).toBeInTheDocument();
    // The envelope's `message` is OPERATOR-facing: it names an internal host and reaches no rendered
    // string.
    expect(document.body.textContent).not.toContain('api-7.internal');
  });

  it('shows the error state — NOT the first-run empty — when the envelope cannot be read', async () => {
    worker.use(http.get(sourcesUrl(ORG_A), () => HttpResponse.json({ data: { sources: [] } })));

    const screen = await renderScreen();

    await expect.element(screen.getByText(/the reason was not reported/)).toBeInTheDocument();
    expect(screen.getByText('No sources yet').elements()).toHaveLength(0);
    // Unknown class means permanently non-retryable, so no button that cannot help.
    expect(screen.getByRole('button', { name: 'Try again' }).elements()).toHaveLength(0);
  });

  it('names the role for the ONE viewer who genuinely lacks it, and nobody else', async () => {
    // The analyst membership in the shared fixture. `sources.view` is granted to three roles and not
    // to this one, while the sidebar offers `/sources` to everybody — so this is the ordinary case
    // rather than an exotic one, and the deny split makes it indistinguishable from a stale
    // organization id ON THE WIRE. The screen tells them apart by the session's own role.
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () =>
        HttpResponse.json({ data: sessionFixture({ current_organization_id: ORG_B }) }),
      ),
      http.get(sourcesUrl(ORG_B), () =>
        HttpResponse.json(envelope('authorization'), { status: 403 }),
      ),
    );

    const screen = await renderScreen();

    await expect.element(screen.getByText("You don't have access to this")).toBeInTheDocument();
    await expect.element(screen.getByText(/knowledge manager role/)).toBeVisible();
    await expect.element(screen.getByText(/Brightwater Legal/)).toBeVisible();
  });

  it('gives an OWNER the class-mapped refusal instead, because a role would be the wrong answer', async () => {
    // Same 403, different reader. For an owner this is a stale organization id or a revoked
    // membership — both of which 404 at binding time and render as `authorization` — so naming a role
    // would point at the wrong door.
    worker.use(
      http.get(sourcesUrl(ORG_A), () =>
        HttpResponse.json(envelope('authorization'), { status: 403 }),
      ),
    );

    const screen = await renderScreen();

    await expect.element(screen.getByText('You do not have access to this.')).toBeInTheDocument();
    expect(screen.getByText("You don't have access to this").elements()).toHaveLength(0);
  });
});

describe('loading', () => {
  it('draws a table-shaped skeleton and no table while the first page is in flight', async () => {
    // A never-resolving handler: the point is what is on screen BEFORE an answer.
    worker.use(http.get(sourcesUrl(ORG_A), () => new Promise<never>(() => {})));

    const screen = await renderScreen();

    await vi.waitFor(() => {
      expect(document.querySelectorAll('[aria-busy="true"]').length).toBeGreaterThan(0);
    });
    expect(screen.getByRole('table').elements()).toHaveLength(0);
    // A skeleton is never announced: the container carries aria-busy, the blocks are aria-hidden.
    expect(document.querySelectorAll('[data-slot="skeleton"]:not([aria-hidden])')).toHaveLength(0);
  });
});
