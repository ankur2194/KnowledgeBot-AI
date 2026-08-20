import type { SourceResource } from '@kb/contracts';
import indexSourcesRules from '@kb/contracts/rules/IndexSourcesRequest.json';
import updateSourceStatusRules from '@kb/contracts/rules/UpdateSourceStatusRequest.json';
import { HttpResponse, http } from 'msw';
import { setupServer } from 'msw/node';
import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  SOURCE_DISABLE_TARGET,
  SOURCE_ENABLE_TARGET,
  SOURCE_FILTER_PARAM,
  SOURCE_LIST_CONFIG,
  SOURCE_MAX_FILTER_LENGTH,
  SOURCE_MAX_PER_PAGE,
  SOURCE_POLL_MS,
  SOURCE_SORTABLE_COLUMNS,
  SOURCE_STATUS_TARGETS,
  canManageSources,
  canViewSources,
  deleteSource,
  fetchSourcePage,
  reprocessSource,
  setSourceStatus,
  sourceIsSettling,
  sourcePath,
  sourcePollInterval,
  sourceReprocessPath,
  sourceStatusDisplay,
  sourceStatusPath,
  sourceTypeDisplay,
  sourcesPath,
} from '@/features/sources/api';
import {
  MAX_FILTER_LENGTH,
  MAX_PER_PAGE,
  assertTableParamsConfig,
  readTableParams,
  toRequestParams,
} from '@/lib/table/params';
import { enumFromRule, maxFromRule } from '@/lib/table/rules';

/**
 * The source list's TRANSPORT, its view configuration and its two display vocabularies — the half of
 * the screen that is not React.
 *
 * ── WHAT THIS SPEC IS ACTUALLY FOR ───────────────────────────────────────────────────────────────
 * Three things here are DERIVED from the server's own dumped rules rather than typed out, and a
 * derivation that silently yields nothing is worse than a hard-coded list: an empty sortable set makes
 * every header unclickable, and a wrong one makes every header a 422. So each parse is pinned against
 * the manifest `php artisan kb:dump-form-rules` writes, and a manifest whose SHAPE changed fails here
 * by name rather than at render time.
 *
 * It also pins the POLL PREDICATE, which is the one piece of logic on this screen whose failure is
 * invisible in a browser: a poll that never stops looks identical to a poll that stops correctly,
 * until an admin limiter starts rejecting real work.
 *
 * ── WHAT IT MAY NOT CLAIM ────────────────────────────────────────────────────────────────────────
 * Nothing about tenant isolation. `fetchSourcePage` takes an `orgId` and puts it in a PATH, where it
 * is a routing hint Laravel re-derives from the session anyway; the org NAMESPACE lives in the query
 * key its caller builds. "Organization A's sources never appear after a switch" is Playwright's claim.
 */

const ORIGIN = 'http://api.invalid';
const ORG = '01JORGAAAAAAAAAAAAAAAAAAAA';
const SOURCE = '01JSOURCEAAAAAAAAAAAAAAAAA';

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

const sourcesUrl = `${ORIGIN}/api/v1/organizations/${ORG}/sources`;

/** One page, in the exact envelope `PaginatedCollection` writes: `meta` is a SIBLING of the array
 *  INSIDE `data`. A fixture shaped `{"data":[…]}` would make these specs pass against a body the
 *  server never sends. */
const page = (rows: readonly unknown[], meta: Record<string, unknown> = {}) => ({
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

/** A row with every field the resource publishes, so a predicate reading one of them is reading the
 *  real shape rather than a convenient subset. */
const row = (overrides: Partial<SourceResource> = {}): SourceResource => ({
  id: SOURCE,
  type: 'file',
  name: 'Q3 handbook.pdf',
  description: null,
  origin_url: null,
  status: 'ready',
  status_permits_retrieval: true,
  status_is_processing: false,
  tags: [],
  effective_at: null,
  expires_at: null,
  created_by: '01JUSERAAAAAAAAAAAAAAAAAAA',
  created_at: '2026-08-01T09:00:00+00:00',
  updated_at: '2026-08-02T09:00:00+00:00',
  deleted_at: null,
  purged_at: null,
  ...overrides,
});

describe('the sortable set is read out of the server rules manifest, never typed out', () => {
  it('parses the endpoint`s closed `in:` list', () => {
    expect(SOURCE_SORTABLE_COLUMNS).toEqual(['id', 'name', 'type', 'status']);
  });

  it('does not offer `created_at`, which the endpoint deliberately withholds', () => {
    // `id` is a ULID under COLLATE "C", so its byte order IS creation order. A header offering
    // `created_at` would be a 422 on a request the user made by clicking a control we drew.
    expect(SOURCE_SORTABLE_COLUMNS).not.toContain('created_at');
    // The parse is not vacuously right: it really read this manifest.
    expect(Object.keys(indexSourcesRules.rules).sort()).toEqual([
      'dir',
      'filter',
      'page',
      'per_page',
      'sort',
    ]);
  });

  it('is the SAME parse the bot list uses, applied to a different manifest', () => {
    // The two lists share one parser (`lib/table/rules.ts`) precisely so the manifest's grammar is
    // known in one place. Reading it directly here proves the module export and the derived constant
    // agree — a `SOURCE_SORTABLE_COLUMNS` that had been hand-typed to the same four strings would
    // pass the first test in this block and fail this one the day the endpoint's set moved.
    expect(SOURCE_SORTABLE_COLUMNS).toEqual(enumFromRule(indexSourcesRules.rules.sort));
  });

  it('mirrors the endpoint`s own bounds rather than restating them', () => {
    // A stale mirror is invisible at runtime: the server clamps `per_page` silently, and the pager
    // then does its arithmetic with a page size nobody was served.
    expect(SOURCE_MAX_PER_PAGE).toBe(MAX_PER_PAGE);
    expect(SOURCE_MAX_FILTER_LENGTH).toBe(MAX_FILTER_LENGTH);
    expect(maxFromRule(indexSourcesRules.rules.per_page)).toBe(SOURCE_MAX_PER_PAGE);
  });
});

describe('the view configuration', () => {
  it('is one `assertTableParamsConfig` would accept', () => {
    // The assertion runs on every render of the hook; a config it refuses is a thrown error on the
    // screen rather than a silently degraded view, and this is where that is checked cheaply.
    expect(() => assertTableParamsConfig(SOURCE_LIST_CONFIG)).not.toThrow();
  });

  it('takes its sortable set FROM the manifest rather than beside it', () => {
    // The property that answers "what happens if the manifest gains a sortable column tomorrow":
    // this array is the same object, so a re-dump moves the config, the URL parser and the headers
    // together with no edit to any of them.
    expect(SOURCE_LIST_CONFIG.sortableColumns).toBe(SOURCE_SORTABLE_COLUMNS);
  });

  it('defaults to `id` ascending, which is the endpoint`s own default and is creation order', () => {
    expect(SOURCE_LIST_CONFIG.defaultSort).toEqual({ id: 'id', desc: false });
    expect(SOURCE_SORTABLE_COLUMNS).toContain(SOURCE_LIST_CONFIG.defaultSort.id);
  });

  it('declares exactly one filter, spelled the way `ListQuery` reads it', () => {
    // There is no status filter on this endpoint — and a fifteen-state lifecycle is exactly where one
    // would be wanted. Inventing it would render a chip, write the URL, and come back unfiltered.
    expect(SOURCE_LIST_CONFIG.filterNames).toEqual([SOURCE_FILTER_PARAM]);
    expect(SOURCE_FILTER_PARAM).toBe('filter');
  });

  it('offers no page size above the platform cap', () => {
    expect(Math.max(...SOURCE_LIST_CONFIG.pageSizes)).toBe(MAX_PER_PAGE);
  });

  it('degrades a sort this endpoint does not offer instead of forwarding it', () => {
    const params = toRequestParams(
      readTableParams(new URLSearchParams('sort=created_at&dir=desc'), SOURCE_LIST_CONFIG),
    );
    expect(params['sort']).toBe('id');
    expect(params['dir']).toBe('asc');
  });

  it('carries every server-visible value into the request and NOT the organization', () => {
    const params = toRequestParams(
      readTableParams(
        new URLSearchParams('page=3&per_page=50&sort=type&dir=desc&filter=handbook'),
        SOURCE_LIST_CONFIG,
      ),
    );

    expect(params).toEqual({
      page: '3',
      per_page: '50',
      sort: 'type',
      dir: 'desc',
      filter: 'handbook',
    });
    expect(Object.keys(params)).not.toContain('org');
    expect(Object.keys(params)).not.toContain('organization_id');
  });
});

describe('fetchSourcePage', () => {
  it('addresses the organization-scoped path and forwards the whole view as a query string', async () => {
    const seen: string[] = [];
    server.use(
      http.get(sourcesUrl, ({ request }) => {
        const url = new URL(request.url);
        seen.push(`${url.pathname}?${url.searchParams.toString()}`);
        return HttpResponse.json(page([]));
      }),
    );

    await fetchSourcePage(
      ORG,
      { page: '2', per_page: '25', sort: 'name', dir: 'desc', filter: 'handbook' },
      new AbortController().signal,
    );

    expect(seen).toEqual([
      `/api/v1/organizations/${ORG}/sources?page=2&per_page=25&sort=name&dir=desc&filter=handbook`,
    ]);
    expect(sourcesPath(ORG)).toBe(`/api/v1/organizations/${ORG}/sources`);
  });

  it('reads the rows and the row count out of the envelope`s own meta', async () => {
    server.use(
      http.get(sourcesUrl, () =>
        HttpResponse.json(page([row()], { page: 4, per_page: 25, total: 137 })),
      ),
    );

    const result = await fetchSourcePage(ORG, { page: '4' }, new AbortController().signal);

    expect(result.rows).toHaveLength(1);
    // `rowCount` is `meta.total`. Deriving it from `rows.length` is what makes a pager read
    // "Page 1 of 1" over a 137-row set.
    expect(result.rowCount).toBe(137);
    expect(result.pageIndex).toBe(3);
    expect(result.pageSize).toBe(25);
  });

  it('THROWS on an unreadable envelope rather than reporting zero rows', async () => {
    // Zero rows renders the FIRST-RUN empty state — "No sources yet" — to an administrator whose
    // organization has two hundred documents, which is indistinguishable from data loss at a glance.
    server.use(http.get(sourcesUrl, () => HttpResponse.json({ data: { sources: [] } })));

    await expect(fetchSourcePage(ORG, {}, new AbortController().signal)).rejects.toThrow(
      /data\.meta/,
    );
  });

  it('THROWS when the collection key is missing, for the same reason', async () => {
    server.use(http.get(sourcesUrl, () => HttpResponse.json({ data: { meta: { total: 0 } } })));

    await expect(fetchSourcePage(ORG, {}, new AbortController().signal)).rejects.toThrow(
      /data\.sources/,
    );
  });

  it('forwards the abort signal, so cancelQueries aborts the request and not just its result', async () => {
    server.use(http.get(sourcesUrl, () => HttpResponse.json(page([]))));
    const controller = new AbortController();
    controller.abort();

    await expect(fetchSourcePage(ORG, {}, controller.signal)).rejects.toThrow();
  });
});

describe('the lifecycle mutations', () => {
  it('sends the ENABLE target the manifest accepts, and never picks a ready flavour itself', async () => {
    const bodies: unknown[] = [];
    server.use(
      http.put(`${sourcesUrl}/${SOURCE}/status`, async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ data: row({ status: 'ready_with_warnings' }) });
      }),
    );

    const result = await setSourceStatus(ORG, SOURCE, SOURCE_ENABLE_TARGET);

    // ONE spelling of "enable" on this wire. Which of the two ready states it lands in is read from
    // the source's live versions by `SourceService::readyFlavourFor()` — a fact about the content
    // rather than an option — and the response says which one it chose.
    expect(bodies).toEqual([{ status: 'ready' }]);
    expect(result.status).toBe('ready_with_warnings');
  });

  it('pins both targets against the endpoint`s dumped `in:` rule', () => {
    // The SET is derived; which member means "enable" is not derivable from a rule list, so the two
    // constants are declared and pinned here. A server-side rename fails this by name rather than
    // 422ing a button in production.
    expect(SOURCE_STATUS_TARGETS).toEqual(enumFromRule(updateSourceStatusRules.rules.status));
    expect(SOURCE_STATUS_TARGETS).toContain(SOURCE_ENABLE_TARGET);
    expect(SOURCE_STATUS_TARGETS).toContain(SOURCE_DISABLE_TARGET);
    expect(SOURCE_STATUS_TARGETS).toHaveLength(2);
  });

  it('POSTs the reprocess submission with no body and no idempotency key', async () => {
    const seen: { method: string; idempotency: string | null; body: string }[] = [];
    server.use(
      http.post(`${sourcesUrl}/${SOURCE}/reprocess`, async ({ request }) => {
        seen.push({
          method: request.method,
          idempotency: request.headers.get('Idempotency-Key'),
          body: await request.text(),
        });
        return HttpResponse.json({ data: row({ status: 'queued' }) }, { status: 202 });
      }),
    );

    await reprocessSource(ORG, SOURCE);

    // NO `Idempotency-Key`, and that is the endpoint's own rule: every call mints a new force nonce,
    // so a header meaning "return the earlier response" is exactly the dedupe the nonce defeats.
    expect(seen).toEqual([{ method: 'POST', idempotency: null, body: '' }]);
  });

  it('DELETEs to the source path and returns the source, not an acknowledgement', async () => {
    server.use(
      http.delete(`${sourcesUrl}/${SOURCE}`, () =>
        HttpResponse.json({
          data: row({
            status: 'deleting',
            status_permits_retrieval: false,
            deleted_at: '2026-08-20T10:00:00+00:00',
          }),
        }),
      ),
    );

    const result = await deleteSource(ORG, SOURCE);

    // PHASE 1: the row survives with `deleted_at` set and `purged_at` still null, because nothing has
    // been PROVEN removed. A console that had been handed an acknowledgement could not answer "is it
    // gone yet" at all.
    expect(result.status).toBe('deleting');
    expect(result.deleted_at).not.toBeNull();
    expect(result.purged_at).toBeNull();
  });

  it('builds every mutation path off the one source path', () => {
    expect(sourcePath(ORG, SOURCE)).toBe(`/api/v1/organizations/${ORG}/sources/${SOURCE}`);
    expect(sourceStatusPath(ORG, SOURCE)).toBe(`${sourcePath(ORG, SOURCE)}/status`);
    expect(sourceReprocessPath(ORG, SOURCE)).toBe(`${sourcePath(ORG, SOURCE)}/reprocess`);
    // A path segment is encoded even though a ULID needs no encoding: the habit is what keeps the day
    // it stops being a ULID from being an injected path segment.
    expect(sourcePath(ORG, 'a/b')).toContain('a%2Fb');
  });
});

describe('the poll stops, which is the whole reason it is a function', () => {
  it('polls while any row on the page is mid-run, reading the SERVER`s flag', () => {
    const running = row({ status: 'parsing', status_is_processing: true });
    expect(sourceIsSettling(running)).toBe(true);
    expect(sourcePollInterval([row(), running])).toBe(SOURCE_POLL_MS);
  });

  it('polls a QUEUED row, which `status_is_processing` deliberately excludes', () => {
    // The server excludes `queued` on purpose — a source queued for an hour is a scheduling problem
    // and one parsing for an hour is a document problem — but the row moves to `fetching` with nobody
    // touching it, so a screen polling on the flag alone would show it as static until a reload.
    const queued = row({ status: 'queued', status_is_processing: false });
    expect(queued.status_is_processing).toBe(false);
    expect(sourceIsSettling(queued)).toBe(true);
  });

  it('polls a DELETING row until the purge finishes, which is how §8.17 is met', () => {
    const deleting = row({
      status: 'deleting',
      status_is_processing: false,
      deleted_at: '2026-08-20T10:00:00+00:00',
    });
    expect(sourceIsSettling(deleting)).toBe(true);

    // …and stops once the purge has been VERIFIED, which is a different claim from `deleted_at`.
    const purged = row({
      status: 'deleted',
      status_is_processing: false,
      deleted_at: '2026-08-20T10:00:00+00:00',
      purged_at: '2026-08-20T10:04:00+00:00',
    });
    expect(sourceIsSettling(purged)).toBe(false);
  });

  it('returns FALSE — not 0 — once every row on the page is settled', () => {
    // `0` means "as fast as possible" to TanStack Query, so the difference between the two values is
    // the difference between a stopped poll and the worst poll in the app.
    const settled = sourcePollInterval([
      row({ status: 'ready' }),
      row({ status: 'failed', status_permits_retrieval: false }),
      row({ status: 'disabled', status_permits_retrieval: false }),
      row({ status: 'archived', status_permits_retrieval: false }),
      row({ status: 'draft', status_permits_retrieval: false }),
    ]);
    expect(settled).toBe(false);
  });

  it('does not poll while there is no page in hand', () => {
    // First load, or an error with nothing cached: nothing to watch, and the retry policy owns what
    // happens next.
    expect(sourcePollInterval(undefined)).toBe(false);
    expect(sourcePollInterval([])).toBe(false);
  });

  it('treats a state this build has never heard of as SETTLED, and that direction is deliberate', () => {
    // A sixteenth lifecycle value stops the poll rather than starting an indefinite one. The row then
    // sits until a refresh — visible and cheap — where the other direction is background traffic
    // nobody would be told about.
    expect(sourceIsSettling(row({ status: 'quarantined' as never }))).toBe(false);
  });
});

describe('the display vocabularies', () => {
  it('names all fifteen lifecycle states', () => {
    // The `satisfies Record<SourceStatus, …>` in api.ts makes a missing entry a typecheck failure;
    // this is the runtime half of the same claim, over the contract's own list.
    const statuses = [
      'draft',
      'queued',
      'fetching',
      'parsing',
      'normalizing',
      'chunking',
      'embedding',
      'indexing',
      'ready',
      'ready_with_warnings',
      'failed',
      'disabled',
      'deleting',
      'deleted',
      'archived',
    ] as const;

    for (const status of statuses) {
      expect(sourceStatusDisplay(status).label).not.toBe(status);
    }
    expect(statuses).toHaveLength(15);
  });

  it('gives `ready_with_warnings` ITS OWN TONE rather than a green with an asterisk', () => {
    // The API refused to collapse the two ready states; collapsing them here would lose the only
    // signal that says "this document parsed badly and published anyway".
    expect(sourceStatusDisplay('ready').kind).toBe('ready');
    expect(sourceStatusDisplay('ready_with_warnings').kind).toBe('degraded');
    expect(sourceStatusDisplay('ready_with_warnings').label).toBe('Ready with warnings');
    expect(sourceStatusDisplay('failed').kind).toBe('failed');
  });

  it('moves the pipeline stages and leaves `queued` still', () => {
    expect(sourceStatusDisplay('embedding').kind).toBe('running');
    expect(sourceStatusDisplay('deleting').kind).toBe('running');
    // An endlessly spinning glyph on a row that is merely accepted reads as "this page is stuck".
    expect(sourceStatusDisplay('queued').kind).toBe('pending');
  });

  it('renders a status this build has never heard of verbatim, in the neutral bucket', () => {
    // THE FALLBACK IS THE POINT: this console is deployed separately from the server, so a sixteenth
    // state reaches a browser running last week's bundle. Neither a crash nor a blank cell.
    expect(sourceStatusDisplay('quarantined')).toEqual({ kind: 'pending', label: 'quarantined' });
  });

  it('tints the three source types from the shared tone map, without restating it', () => {
    expect(sourceTypeDisplay('file')).toEqual({ label: 'File', tone: 'sky' });
    expect(sourceTypeDisplay('url')).toEqual({ label: 'Website', tone: 'violet' });
    // `text` has no counterpart among the tone map's file-format keys, so it takes the neutral member
    // of the same closed set rather than doubling document's tint.
    expect(sourceTypeDisplay('text')).toEqual({ label: 'Text', tone: 'slate' });
    expect(sourceTypeDisplay('hologram')).toEqual({ label: 'hologram', tone: 'slate' });
  });
});

describe('the role affordances', () => {
  it('withholds this list from an analyst, which is the one role that cannot read it', () => {
    // All four roles hold `bots.view`; `sources.view` is granted to three. The sidebar offers
    // `/sources` to everybody, so an analyst reaching a 403 here is the ordinary case.
    expect(canViewSources('analyst')).toBe(false);
    expect(canViewSources('knowledge_manager')).toBe(true);
    expect(canViewSources('owner')).toBe(true);
    expect(canViewSources('admin')).toBe(true);
    expect(canViewSources(null)).toBe(false);
  });

  it('grants management to the same three roles, through a SEPARATE predicate', () => {
    // Separate because the two grants are separate on the server (`sources.view` and
    // `sources.manage`) and may diverge; identical today is a fact, not a reason to share one.
    expect(canManageSources('knowledge_manager')).toBe(true);
    expect(canManageSources('analyst')).toBe(false);
    expect(canManageSources(null)).toBe(false);
  });
});
