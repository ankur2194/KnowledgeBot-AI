import type { BotResource } from '@kb/contracts';
import type { QueryClient } from '@tanstack/react-query';
import { useQueryClient } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import type * as NextNavigation from 'next/navigation';
import { useEffect } from 'react';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Providers } from '@/components/providers';
import { SessionProvider } from '@/features/auth/session-provider';
import { BotsScreen } from '@/features/bots/bots-screen';

import { envelope, ORIGIN } from '../msw/handlers';
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
 * `/bots` — the server-driven bot list.
 *
 * ── WHAT THIS SPEC MAY NOT CLAIM ─────────────────────────────────────────────────────────────────
 * It proves the query key is org-namespaced AND carries every server-visible sort, filter and page
 * value, and that the same values reach the request. It proves NOTHING about isolation: a component
 * test that mocks the API cannot fail an isolation test, and per the `vitest-playwright` boundary
 * table it must never be cited as isolation coverage. "Organization A's bots never appear after
 * switching to B" is Playwright's claim, against a real server, and is recorded as unproven here.
 *
 * ── `next/navigation` IS MOCKED BECAUSE THE URL IS THE TABLE'S STATE ─────────────────────────────
 * `useRouter()` throws outside a mounted App Router, and a bare spy would swallow the href — leaving
 * `useTableParams` with nothing to read, so every assertion after the first click would be asserting
 * the initial state. `tests/support/mock-navigation.ts` is an observable store instead, and its
 * `MOCK_PATHNAME` is already `/bots`.
 *
 * ── NO CSS IS IMPORTED, ON PURPOSE ───────────────────────────────────────────────────────────────
 * Only `design-system-css.test.tsx` imports `globals.css`. Without it the `md:` utilities do nothing,
 * so BOTH layouts — the table above 768px and the row-cards below it — are in the DOM at once. That is
 * what makes "one data source, two layouts" assertable here; which one is VISIBLE at which width is
 * CSS's decision and is a Playwright/manual check.
 */
vi.mock('next/navigation', async (importOriginal) => ({
  ...(await importOriginal<typeof NextNavigation>()),
  useRouter: () => useMockRouter(),
  usePathname: () => useMockPathname(),
  useSearchParams: () => useMockSearchParams(),
}));

/** `sessionFixture()`'s current organization, and the namespace every key below must carry. */
const ORG_A = '01JORGAAAAAAAAAAAAAAAAAAAA';
/** The other ACTIVE membership in the same fixture. Present so the namespacing assertion is about the
 *  CURRENT organization rather than about the only one there is — a one-organization fixture cannot
 *  fail a namespacing test. */
const ORG_B = '01JORGBBBBBBBBBBBBBBBBBBBB';

const botsUrl = (orgId: string) => `${ORIGIN}/api/v1/organizations/${orgId}/bots`;

/**
 * A published bot with every field the resource publishes.
 *
 * `system_instruction` and `answer_style_instruction` carry canary text: they are the bot's own prompt
 * and its voice guidance, they are moving to a management-only projection where the keys stay and
 * become `null`, and no cell may render either. A `null` there will mean "not shown to you", which is
 * a different fact from "not set" — so a column over it would lie to half its readers.
 */
const SUPPORT_BOT: BotResource = {
  id: '01JBOTAAAAAAAAAAAAAAAAAAAA',
  public_bot_id: 'pb_support',
  name: 'Support bot',
  slug: 'support-bot',
  description: 'Answers billing and account questions.',
  welcome_message: null,
  placeholder_text: null,
  system_instruction: 'CANARY-SYSTEM-INSTRUCTION',
  answer_style_instruction: 'CANARY-ANSWER-STYLE',
  status: 'published',
  access_mode: 'public',
  provider_connection_id: '01JCONNAAAAAAAAAAAAAAAAAAA',
  provider_model_id: '01JMODELAAAAAAAAAAAAAAAAAA',
  answer_mode: 'strict',
  dense_top_k: 40,
  sparse_top_k: 40,
  rerank_candidates: 40,
  rerank_retain: 8,
  evidence_threshold: null,
  evidence_threshold_scale: null,
  retrieval_configuration_version: 1,
  allow_general_answers: false,
  theme: {},
  rate_limit_per_minute: null,
  rate_limit_per_day: null,
  retention_days: null,
  collect_end_user_data: false,
  consent_text: null,
  created_at: '2026-08-01T09:00:00+00:00',
  updated_at: '2026-08-02T09:00:00+00:00',
};

/** A DRAFT bot, listed on purpose: every lifecycle state is included, drafts and archived rows alike,
 *  because an operator asking "where did that bot go" has to be able to find it. */
const DRAFT_BOT: BotResource = {
  ...SUPPORT_BOT,
  id: '01JBOTBBBBBBBBBBBBBBBBBBBB',
  public_bot_id: 'pb_onboarding',
  name: 'Onboarding bot',
  slug: 'onboarding-bot',
  status: 'draft',
  access_mode: 'private',
  provider_model_id: null,
  created_at: '2026-08-03T09:00:00+00:00',
};

/** The envelope `PaginatedCollection` writes: `meta` is a SIBLING of the array INSIDE `data`. */
const pageBody = (rows: readonly BotResource[], meta: Record<string, unknown> = {}) => ({
  data: {
    bots: rows,
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

const listHandler = (rows: readonly BotResource[] = [SUPPORT_BOT, DRAFT_BOT], meta = {}) =>
  http.get(botsUrl(ORG_A), () => HttpResponse.json(pageBody(rows, meta)));

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
        <BotsScreen />
      </SessionProvider>
    </Providers>,
  );

const cacheKeys = (): unknown[][] =>
  (captured?.getQueryCache().getAll() ?? []).map((query) => [...query.queryKey]);

const cards = () => document.querySelectorAll('[data-slot="data-table-card"]');

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
  it('puts every server-visible sort, filter and page value after `[org, orgId, bots]`', async () => {
    resetNavigation('page=2&per_page=50&sort=name&dir=desc&filter=invoice');
    worker.use(listHandler([SUPPORT_BOT], { page: 2, per_page: 50, total: 60, total_pages: 2 }));

    const screen = await renderScreen();
    await expect.element(screen.getByRole('cell', { name: 'support-bot' })).toBeVisible();

    await vi.waitFor(() => {
      expect(cacheKeys()).toContainEqual([
        'org',
        ORG_A,
        'bots',
        { page: '2', per_page: '50', sort: 'name', dir: 'desc', filter: 'invoice' },
      ]);
    });

    const keys = cacheKeys();
    // `['session']` is the ONE legal exception to the org prefix — it is the PRODUCER of `orgId`, and
    // namespacing the identity document by the organization it announces would be circular.
    expect(keys.filter((key) => key[0] !== 'org')).toEqual([['session']]);
    // Not one key mentions the organization the session is NOT currently acting in.
    expect(JSON.stringify(keys)).not.toContain(ORG_B);
  });

  it('sends those same values on the wire, and never the organization as a parameter', async () => {
    resetNavigation('page=2&sort=slug&dir=asc');
    const queries: string[] = [];
    worker.use(
      http.get(botsUrl(ORG_A), ({ request }) => {
        queries.push(new URL(request.url).search);
        return HttpResponse.json(pageBody([SUPPORT_BOT], { page: 2, total: 30, total_pages: 2 }));
      }),
    );

    const screen = await renderScreen();
    await expect.element(screen.getByRole('cell', { name: 'support-bot' })).toBeVisible();

    await vi.waitFor(() => {
      expect(queries).toEqual(['?page=2&per_page=25&sort=slug&dir=asc']);
    });
    // The organization is a cache NAMESPACE, not a request parameter: it is in the PATH as a routing
    // hint, and Laravel derives the real one from the session cookie either way.
    expect(queries[0]).not.toContain('org');
  });
});

describe('success: one data source, two layouts', () => {
  it('renders a real table with column headers, and the same rows as cards', async () => {
    worker.use(listHandler());

    const screen = await renderScreen();

    const table = screen.getByRole('table', { name: 'Bots' });
    await expect.element(table).toBeInTheDocument();
    expect(document.querySelectorAll('tbody tr')).toHaveLength(2);
    // Five columns: Name, Slug, Status, Access, Created.
    expect(document.querySelectorAll('th[scope="col"]')).toHaveLength(5);

    // The card layout is fed from the SAME rows and the SAME column definitions.
    expect(cards()).toHaveLength(2);
    expect(cards()[0]?.textContent).toContain('Support bot');
    // `Created` carries `card: 'hidden'`: present in the table, dropped from the card, because a card
    // with five labelled pairs is a table with extra steps.
    expect(cards()[0]?.textContent).not.toContain('Created');
  });

  it('renders every lifecycle state, with the word as well as the colour', async () => {
    worker.use(listHandler());

    const screen = await renderScreen();

    // Drafts are listed, not hidden: an operator asking "where did that bot go" has to find it.
    await expect.element(screen.getByRole('cell', { name: 'Published' })).toBeVisible();
    await expect.element(screen.getByRole('cell', { name: 'Draft' })).toBeVisible();
    // Access mode is plain text, never a second coloured pill competing with the lifecycle one.
    await expect.element(screen.getByRole('cell', { name: 'Public' })).toBeVisible();
    await expect.element(screen.getByRole('cell', { name: 'Private' })).toBeVisible();
  });

  it('renders neither instruction field anywhere on the page', async () => {
    worker.use(listHandler());

    const screen = await renderScreen();
    await expect.element(screen.getByRole('cell', { name: 'support-bot' })).toBeVisible();

    // Both are moving to a management-only projection where the key stays and the value becomes null,
    // so `null` there will mean "not shown to you" rather than "not set". Neither belongs in a list.
    expect(document.body.textContent).not.toContain('CANARY-SYSTEM-INSTRUCTION');
    expect(document.body.textContent).not.toContain('CANARY-ANSWER-STYLE');
  });

  it('states the range from the envelope`s own total rather than from the rows in hand', async () => {
    worker.use(listHandler([SUPPORT_BOT, DRAFT_BOT], { total: 137, total_pages: 6 }));

    const screen = await renderScreen();

    // Without `rowCount` the table treats `data.length` as everything and the pager reads
    // "Page 1 of 1" over a 137-row set.
    await expect.element(screen.getByText('1–2 of 137')).toBeInTheDocument();
    await expect.element(screen.getByRole('button', { name: 'Next page' })).toBeEnabled();
  });
});

describe('sorting is server-driven', () => {
  it('offers a header button only for a column the endpoint will order by', async () => {
    worker.use(listHandler());

    const screen = await renderScreen();
    await expect.element(screen.getByRole('cell', { name: 'support-bot' })).toBeVisible();

    // `id`, `name`, `slug`, `status` — read out of the server's own rules manifest.
    await expect.element(screen.getByRole('button', { name: 'Name' })).toBeInTheDocument();
    await expect.element(screen.getByRole('button', { name: 'Created' })).toBeInTheDocument();
    // `Access` is not in the sortable set, so its header is plain text: a header that offers an
    // ordering `Rule::in($sortable)` rejects is a 422 one click away.
    expect(screen.getByRole('button', { name: 'Access' }).elements()).toHaveLength(0);
  });

  it('announces the default ordering on the column that carries it', async () => {
    worker.use(listHandler());

    const screen = await renderScreen();
    await expect.element(screen.getByRole('cell', { name: 'support-bot' })).toBeVisible();

    // `id` ascending is the endpoint's default and IS creation order, so the arrow belongs on the
    // Created column — which accesses `created_at` and identifies as `id` for exactly this reason.
    const sorted = [...document.querySelectorAll('th[aria-sort]')].filter(
      (cell) => cell.getAttribute('aria-sort') !== 'none',
    );
    expect(sorted).toHaveLength(1);
    expect(sorted[0]?.getAttribute('aria-sort')).toBe('ascending');
    expect(sorted[0]?.textContent).toContain('Created');

    // The other three sortable columns carry `none` rather than nothing: that tells a screen-reader
    // user the column CAN be sorted and currently is not, which an omitted attribute does not. The
    // unsortable `Access` column carries no `aria-sort` at all, because it cannot be sorted.
    expect(document.querySelectorAll('th[aria-sort]')).toHaveLength(4);
  });

  it('resets the page in the SAME navigation as the sort', async () => {
    resetNavigation('page=3');
    worker.use(listHandler([SUPPORT_BOT], { page: 3, total: 137, total_pages: 6 }));

    const screen = await renderScreen();
    await screen.getByRole('button', { name: 'Name' }).click();

    // ONE navigation. Two would arrive at the same URL and still be the bug: under manual pagination
    // the table never resets the page itself, and doing it in a second update is what asks for page 8
    // of a 2-page result.
    expect(navigationCalls()).toHaveLength(1);
    expect(navigationCalls()[0]?.href).toBe(`${MOCK_PATHNAME}?sort=name&dir=asc`);
    expect(navigationCalls()[0]?.history).toBe('replace');
  });
});

describe('the filter', () => {
  it('writes one debounced navigation that carries the filter and drops the page', async () => {
    resetNavigation('page=3');
    worker.use(listHandler([], { page: 3, total: 137, total_pages: 6 }));

    const screen = await renderScreen();
    await screen.getByLabelText('Search bots').fill('invoice');

    // Debounced, so ordinary typing is one request rather than one per keystroke — and `replace`, so
    // Back does not walk the user backwards through their own typing.
    await vi.waitFor(() => {
      expect(navigationCalls()).toHaveLength(1);
    });
    expect(navigationCalls()[0]?.href).toBe(`${MOCK_PATHNAME}?filter=invoice`);
    expect(navigationCalls()[0]?.history).toBe('replace');
  });

  it('offers Clear all only once something is applied, and returns to the clean address', async () => {
    worker.use(listHandler());

    const screen = await renderScreen();
    await expect.element(screen.getByRole('cell', { name: 'support-bot' })).toBeVisible();
    expect(screen.getByRole('button', { name: 'Clear all' }).elements()).toHaveLength(0);

    resetNavigation('filter=invoice&page=4');
    await expect.element(screen.getByRole('button', { name: 'Clear all' })).toBeInTheDocument();
    await screen.getByRole('button', { name: 'Clear all' }).click();

    expect(navigationCalls()).toHaveLength(1);
    expect(navigationCalls()[0]?.href).toBe(MOCK_PATHNAME);
  });

  it('seeds the field from the URL, so a shared or reloaded view shows its own query', async () => {
    resetNavigation('filter=invoice');
    worker.use(listHandler([SUPPORT_BOT], { filter: 'invoice' }));

    const screen = await renderScreen();

    await expect.element(screen.getByLabelText('Search bots')).toHaveValue('invoice');
    // And it caused no navigation of its own: seeding is a read, not a write.
    expect(navigationCalls()).toHaveLength(0);
  });
});

describe('the empty split — the whole reason two components exist', () => {
  it('shows the first-run state when nothing has ever been created', async () => {
    worker.use(listHandler([], { total: 0 }));

    const screen = await renderScreen();

    await expect.element(screen.getByText('No bots yet')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Clear filters' }).elements()).toHaveLength(0);
  });

  it('restates the query and offers Clear filters when a filter matched nothing', async () => {
    resetNavigation('filter=invoice');
    worker.use(listHandler([], { total: 0, filter: 'invoice' }));

    const screen = await renderScreen();

    await expect.element(screen.getByText('No matches')).toBeInTheDocument();
    // A user who cannot see their own query cannot tell a too-narrow filter from a broken screen.
    await expect.element(screen.getByText('No bots match “invoice”.')).toBeInTheDocument();
    // Offering "create your first bot" to somebody whose search matched nothing is the bug this split
    // exists to prevent.
    expect(screen.getByText('No bots yet').elements()).toHaveLength(0);
    await expect.element(screen.getByRole('button', { name: 'Clear filters' })).toBeInTheDocument();
  });

  it('treats an out-of-range page as its own state, not as a filtered empty', async () => {
    // Unreachable by clicking — every in-app path resets the page — but `?page=99` is typeable and
    // bookmarkable, and a stale bookmark is not a bug in the bookmark.
    resetNavigation('page=99');
    worker.use(listHandler([], { page: 99, total: 137, total_pages: 6 }));

    const screen = await renderScreen();

    await expect.element(screen.getByText('That page is empty')).toBeInTheDocument();
    // "Clear filters" is the wrong advice for someone whose filter is fine and whose page is not.
    expect(screen.getByRole('button', { name: 'Clear filters' }).elements()).toHaveLength(0);
  });
});

describe('error', () => {
  it('renders the class-mapped sentence and the request id, never the envelope message', async () => {
    worker.use(
      http.get(botsUrl(ORG_A), () =>
        HttpResponse.json(envelope('internal_dependency', { retryable: true }), { status: 503 }),
      ),
    );

    const screen = await renderScreen();

    await expect
      .element(screen.getByText('Something on our side is unavailable. Try again shortly.'))
      .toBeInTheDocument();
    await expect.element(screen.getByText('Bots could not be loaded')).toBeInTheDocument();
    await expect.element(screen.getByText('01JREQFROMLARAVEL')).toBeInTheDocument();
    // The envelope's `message` is OPERATOR-facing: it names an internal host and reaches no rendered
    // string. Log it; show the class-mapped sentence and the request id.
    expect(document.body.textContent).not.toContain('api-7.internal');
  });

  it('renders the class-mapped refusal for `authorization`, and NOT a role the list does not need', async () => {
    // All four roles hold `bots.view`, so an authorization failure here is never a role problem — it is
    // a stale organization id or a revoked membership, both of which 404 at binding time and are
    // rendered as `authorization` by the deny split. Naming a role would point at the wrong door.
    worker.use(
      http.get(botsUrl(ORG_A), () => HttpResponse.json(envelope('authorization'), { status: 403 })),
    );

    const screen = await renderScreen();

    await expect.element(screen.getByText('You do not have access to this.')).toBeInTheDocument();
    expect(screen.getByText("You don't have access to this").elements()).toHaveLength(0);
    // Not retryable by class, so no button that cannot help.
    expect(screen.getByRole('button', { name: 'Try again' }).elements()).toHaveLength(0);
  });

  it('shows the error state — NOT the first-run empty — when the envelope cannot be read', async () => {
    // `{rows: [], rowCount: 0}` would render "No bots yet" to an administrator whose organization has
    // two hundred of them, which is indistinguishable from data loss at a glance. The thrown value is
    // not a `KbError`, so no class was parsed, and unknown is permanently non-retryable.
    worker.use(http.get(botsUrl(ORG_A), () => HttpResponse.json({ data: { bots: [] } })));

    const screen = await renderScreen();

    await expect.element(screen.getByText(/the reason was not reported/)).toBeInTheDocument();
    expect(screen.getByText('No bots yet').elements()).toHaveLength(0);
    expect(screen.getByRole('button', { name: 'Try again' }).elements()).toHaveLength(0);
  });
});

describe('loading', () => {
  it('draws a table-shaped skeleton and no table while the first page is in flight', async () => {
    // A never-resolving handler: the point is what is on screen BEFORE an answer.
    worker.use(http.get(botsUrl(ORG_A), () => new Promise<never>(() => {})));

    const screen = await renderScreen();

    await vi.waitFor(() => {
      expect(document.querySelectorAll('[aria-busy="true"]').length).toBeGreaterThan(0);
    });
    expect(screen.getByRole('table').elements()).toHaveLength(0);
    // A skeleton is never announced: the container carries aria-busy, the blocks are aria-hidden.
    expect(document.querySelectorAll('[data-slot="skeleton"]:not([aria-hidden])')).toHaveLength(0);
  });
});
