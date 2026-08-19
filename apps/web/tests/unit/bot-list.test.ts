import indexBotsRules from '@kb/contracts/rules/IndexBotsRequest.json';
import { BOT_STATUSES } from '@kb/contracts/forms';
import { HttpResponse, http } from 'msw';
import { setupServer } from 'msw/node';
import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  BOT_FILTER_PARAM,
  BOT_LIST_CONFIG,
  BOT_MAX_FILTER_LENGTH,
  BOT_MAX_PER_PAGE,
  BOT_SORTABLE_COLUMNS,
  botAccessModeLabel,
  botStatusDisplay,
  botsPath,
  fetchBotPage,
} from '@/features/bots/api';
import {
  MAX_FILTER_LENGTH,
  MAX_PER_PAGE,
  assertTableParamsConfig,
  readTableParams,
  toRequestParams,
} from '@/lib/table/params';

/**
 * The bot list's TRANSPORT and its view configuration — the half of the screen that is not React.
 *
 * ── WHAT THIS SPEC IS ACTUALLY FOR ───────────────────────────────────────────────────────────────
 * Two of the values below are DERIVED from the server's own rules manifest rather than typed out, and
 * a derivation that silently yields nothing is worse than a hard-coded list: an empty sortable set
 * makes every header unclickable, and a wrong one makes every header a 422. So the parse is pinned
 * here, against the manifest `php artisan kb:dump-form-rules` writes, and a manifest whose SHAPE
 * changed fails by name.
 *
 * It also compares this app's mirrored bounds against the endpoint's published ones. `MAX_PER_PAGE`
 * and `MAX_FILTER_LENGTH` in `lib/table/params.ts` are mirrors of `ListQuery`'s constants, and the
 * mirror going stale is invisible at runtime — the server clamps silently and the pager states a
 * range nobody was served.
 *
 * ── WHAT IT MAY NOT CLAIM ────────────────────────────────────────────────────────────────────────
 * Nothing about tenant isolation. `fetchBotPage` takes an `orgId` and puts it in a PATH, where it is a
 * routing hint that Laravel re-derives from the session anyway; the org NAMESPACE lives in the query
 * key its caller builds. "Organization A's bots never appear after a switch" is Playwright's claim.
 */

const ORIGIN = 'http://api.invalid';
const ORG = '01JORGAAAAAAAAAAAAAAAAAAAA';

const server = setupServer();

/** `error`, not `warn`: an unhandled request here is a spec watching a DNS failure. */
beforeAll(() => {
  server.listen({ onUnhandledRequest: 'error' });
});
afterEach(() => {
  server.resetHandlers();
  vi.unstubAllGlobals();
});
afterAll(() => {
  server.close();
});

/** Node has no `document`, and `sessionCredential()` reads the XSRF cookie off it. Without this every
 *  fetcher below takes the `refreshCsrfToken()` path and throws for a reason unrelated to the spec. */
beforeEach(() => {
  vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=test-token' });
});

const botsUrl = `${ORIGIN}/api/v1/organizations/${ORG}/bots`;

/** One page, in the exact envelope `PaginatedCollection` writes: `meta` is a SIBLING of the array
 *  INSIDE `data`. A fixture shaped `{"data":[…]}` would make these specs pass against a body the
 *  server never sends. */
const page = (rows: readonly unknown[], meta: Record<string, unknown> = {}) => ({
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

describe('the sortable set is read out of the server rules manifest, never typed out', () => {
  it('parses the endpoint`s closed `in:` list', () => {
    // `Rule::in(IndexBotsRequest::SORTABLE)`, one column for each way an operator scans a bot list.
    expect(BOT_SORTABLE_COLUMNS).toEqual(['id', 'name', 'slug', 'status']);
  });

  it('does not offer `created_at`, which the endpoint deliberately withholds', () => {
    // `id` is a ULID under COLLATE "C", so its byte order IS creation order and a second index
    // expressing the same ordering was declined. A header offering `created_at` would be a 422.
    expect(BOT_SORTABLE_COLUMNS).not.toContain('created_at');
    // The parse is not vacuously right: it really read this manifest.
    expect(Object.keys(indexBotsRules.rules).sort()).toEqual([
      'dir',
      'filter',
      'page',
      'per_page',
      'sort',
    ]);
  });

  it('mirrors the endpoint`s own bounds rather than restating them', () => {
    // A stale mirror is invisible at runtime: the server clamps `per_page` silently, and the pager
    // then does its arithmetic with a page size nobody was served.
    expect(BOT_MAX_PER_PAGE).toBe(MAX_PER_PAGE);
    expect(BOT_MAX_FILTER_LENGTH).toBe(MAX_FILTER_LENGTH);
  });
});

describe('the view configuration', () => {
  it('is one `assertTableParamsConfig` would accept', () => {
    // The assertion runs on every render of the hook; a config it refuses is a thrown error on the
    // screen rather than a silently degraded view, and this is where that is checked cheaply.
    expect(() => assertTableParamsConfig(BOT_LIST_CONFIG)).not.toThrow();
  });

  it('defaults to `id` ascending, which is the endpoint`s own default and is creation order', () => {
    expect(BOT_LIST_CONFIG.defaultSort).toEqual({ id: 'id', desc: false });
    expect(BOT_SORTABLE_COLUMNS).toContain(BOT_LIST_CONFIG.defaultSort.id);
  });

  it('declares exactly one filter, spelled the way `ListQuery` reads it', () => {
    // There is no status filter on this endpoint. Inventing one would render a chip, write the URL,
    // and come back unfiltered — Laravel ignores a parameter no rule names.
    expect(BOT_LIST_CONFIG.filterNames).toEqual([BOT_FILTER_PARAM]);
    expect(BOT_FILTER_PARAM).toBe('filter');
  });

  it('offers no page size above the platform cap', () => {
    expect(Math.max(...BOT_LIST_CONFIG.pageSizes)).toBe(MAX_PER_PAGE);
  });
});

describe('the request the key is built from', () => {
  it('carries every server-visible value and NOT the organization', () => {
    const params = toRequestParams(
      readTableParams(
        new URLSearchParams('page=3&per_page=50&sort=name&dir=desc'),
        BOT_LIST_CONFIG,
      ),
    );

    expect(params).toEqual({ page: '3', per_page: '50', sort: 'name', dir: 'desc' });
    // The organization is a cache NAMESPACE, not a request parameter: Laravel derives the real one
    // from the session cookie and would ignore a client-supplied one.
    expect(Object.keys(params)).not.toContain('org');
    expect(Object.keys(params)).not.toContain('organization_id');
  });

  it('degrades a sort this endpoint does not offer instead of forwarding it', () => {
    // A stale bookmark from a previous release renders the default view; forwarding `created_at`
    // would 422 a URL somebody merely opened.
    const params = toRequestParams(
      readTableParams(new URLSearchParams('sort=created_at&dir=desc'), BOT_LIST_CONFIG),
    );
    expect(params['sort']).toBe('id');
    expect(params['dir']).toBe('asc');
  });
});

describe('fetchBotPage', () => {
  it('addresses the organization-scoped path and forwards the whole view as a query string', async () => {
    const seen: string[] = [];
    server.use(
      http.get(botsUrl, ({ request }) => {
        const url = new URL(request.url);
        seen.push(`${url.pathname}?${url.searchParams.toString()}`);
        return HttpResponse.json(page([]));
      }),
    );

    await fetchBotPage(
      ORG,
      { page: '2', per_page: '25', sort: 'name', dir: 'desc', filter: 'invoice' },
      new AbortController().signal,
    );

    expect(seen).toEqual([
      `/api/v1/organizations/${ORG}/bots?page=2&per_page=25&sort=name&dir=desc&filter=invoice`,
    ]);
    expect(botsPath(ORG)).toBe(`/api/v1/organizations/${ORG}/bots`);
  });

  it('reads the rows and the row count out of the envelope`s own meta', async () => {
    server.use(
      http.get(botsUrl, () =>
        HttpResponse.json(
          page([{ id: '01JBOT', name: 'Support' }], { page: 4, per_page: 25, total: 137 }),
        ),
      ),
    );

    const result = await fetchBotPage(ORG, { page: '4' }, new AbortController().signal);

    expect(result.rows).toHaveLength(1);
    // `rowCount` is `meta.total`. Deriving it from `rows.length` is what makes a pager read
    // "Page 1 of 1" over a 137-row set.
    expect(result.rowCount).toBe(137);
    // The meta page is 1-based and `TablePage.pageIndex` is 0-based; the conversion happens once.
    expect(result.pageIndex).toBe(3);
    expect(result.pageSize).toBe(25);
  });

  it('THROWS on an unreadable envelope rather than reporting zero rows', async () => {
    // Zero rows renders the FIRST-RUN empty state — "No bots yet" — to an administrator whose
    // organization has two hundred of them, which is indistinguishable from data loss at a glance.
    server.use(http.get(botsUrl, () => HttpResponse.json({ data: { bots: [] } })));

    await expect(fetchBotPage(ORG, {}, new AbortController().signal)).rejects.toThrow(/data\.meta/);
  });

  it('THROWS when the collection key is missing, for the same reason', async () => {
    server.use(http.get(botsUrl, () => HttpResponse.json({ data: { meta: { total: 0 } } })));

    await expect(fetchBotPage(ORG, {}, new AbortController().signal)).rejects.toThrow(/data\.bots/);
  });

  it('forwards the abort signal, so cancelQueries aborts the request and not just its result', async () => {
    server.use(http.get(botsUrl, () => HttpResponse.json(page([]))));
    const controller = new AbortController();
    controller.abort();

    await expect(fetchBotPage(ORG, {}, controller.signal)).rejects.toThrow();
  });
});

describe('the display vocabularies', () => {
  it('names every lifecycle state the contract publishes', () => {
    // A status with no entry would render as its own wire spelling; the `satisfies Record<BotStatus,…>`
    // in api.ts is what makes that a typecheck failure, and this is the runtime half of the same claim.
    for (const status of BOT_STATUSES) {
      expect(botStatusDisplay(status).label).not.toBe(status);
    }
  });

  it('reserves the success bucket for `published` alone', () => {
    // The one distinction an operator scans this list for: which bots are answering end users.
    expect(botStatusDisplay('published').kind).toBe('ready');
    expect(botStatusDisplay('paused').kind).toBe('degraded');
    expect(botStatusDisplay('archived').kind).toBe('disabled');
  });

  it('renders a status this build has never heard of verbatim, in the neutral bucket', () => {
    // The honest fallback: it is what the row claims and what the server will act on, and inventing a
    // label for it would be worse than showing it.
    expect(botStatusDisplay('quarantined')).toEqual({ kind: 'pending', label: 'quarantined' });
  });

  it('names both access modes and passes an unknown one through', () => {
    expect(botAccessModeLabel('public')).toBe('Public');
    expect(botAccessModeLabel('private')).toBe('Private');
    expect(botAccessModeLabel('federated')).toBe('federated');
  });
});
