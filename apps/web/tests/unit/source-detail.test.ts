import type { SourceActiveVersionResource, SourceDetailResource } from '@kb/contracts';
import indexAssignmentsRules from '@kb/contracts/rules/IndexBotSourceAssignmentsRequest.json';
import { HttpResponse, http } from 'msw';
import { setupServer } from 'msw/node';
import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

import { BOT_SORTABLE_COLUMNS } from '@/features/bots/api';
import { fetchSourceDetail, sourcePollInterval } from '@/features/sources/api';
import {
  ASSIGNMENT_MAX_FILTER_LENGTH,
  ASSIGNMENT_MAX_PER_PAGE,
  assignmentFilterTerm,
  botSourceAssignmentPath,
  botSourceAssignmentsPath,
  canAssignSources,
  createBotSourceAssignment,
  deleteBotSourceAssignment,
  fetchGrantForSource,
} from '@/features/sources/source-assignment-api';
import {
  contentCounts,
  deleteConsequenceCounts,
  itemNoun,
  liveState,
  warningCopy,
  warningScope,
} from '@/features/sources/source-detail';
import { maxFromRule } from '@/lib/table/rules';

/**
 * `/sources/{sourceId}` — THE HALF OF THE DETAIL SCREEN THAT IS NOT REACT.
 *
 * ── WHAT THIS SPEC IS ACTUALLY FOR ──────────────────────────────────────────────────────────────
 * Three things, and each of them fails silently in a browser:
 *
 *   1. THE `active_version` NULL CASE. `active_version` is populated only when the source has one
 *      item, and `null` for every crawl and every multi-file upload — INCLUDING ones where hundreds
 *      of pages are live. A screen that branched on the pointer alone is correct on single-file
 *      uploads and silently wrong on everything else, and both fixtures render without an error.
 *   2. THE DELETE CONFIRMATION'S NUMBERS. They are the reason this screen fetches the detail
 *      resource at all, and a wrong one is a sentence that reads perfectly.
 *   3. THE GRANT LOOKUP'S PAGE WALK. The API has no source-side assignment endpoint, so "is this bot
 *      assigned this source" is answered by filtering the bot's own grants — and the case that
 *      matters is the one where the row is NOT on the first page, which never happens with a small
 *      fixture and does happen in a tenant with many similarly-named documents.
 *
 * ── WHAT IT MAY NOT CLAIM ───────────────────────────────────────────────────────────────────────
 * Nothing about tenant isolation. Every fetcher here takes an `orgId` and puts it in a PATH, where
 * it is a routing hint Laravel re-derives from the session anyway; the org NAMESPACE lives in the
 * query key its caller builds. "Organization A's document never appears after a switch" is
 * Playwright's claim, against a real server.
 */

const ORIGIN = 'http://api.invalid';
const ORG = '01JORGAAAAAAAAAAAAAAAAAAAA';
const SOURCE = '01JSOURCEAAAAAAAAAAAAAAAAA';
const BOT = '01JBOTAAAAAAAAAAAAAAAAAAAA';

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

/** Node has no `document`, and `sessionCredential()` reads the XSRF cookie off it. Without this
 *  every fetcher below takes the `refreshCsrfToken()` path and throws for a reason unrelated to the
 *  spec. */
beforeEach(() => {
  vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=test-token' });
});

const VERSION: SourceActiveVersionResource = {
  id: '01JVERSIONAAAAAAAAAAAAAAAA',
  source_item_id: '01JITEMAAAAAAAAAAAAAAAAAAA',
  version_number: 3,
  status: 'ready',
  activated_at: '2026-08-19T10:00:00+00:00',
  parser_cfg_version: 'docling/2.118:layout-v3',
  ocr_cfg_version: 'rapidocr/1.4:dpi300',
  chunker_cfg_version: 'structure/v2:800',
  embedding_model_version: 'emb/v1:openai:text-embedding-3-large:d3072:9f2a1c4e77b1',
};

/** A single-file upload with everything the resource publishes, so a function reading one field is
 *  reading the real shape rather than a convenient subset. */
const detail = (overrides: Partial<SourceDetailResource> = {}): SourceDetailResource => ({
  id: SOURCE,
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
  item_count: 1,
  active_version_count: 1,
  active_version: VERSION,
  page_count: 12,
  slide_count: 0,
  sheet_count: 0,
  element_count: 486,
  chunk_count: 1204,
  warnings: [],
  warnings_truncated: false,
  content_preview: 'Staff handbook\n\nSection 1.',
  content_preview_truncated: true,
  ...overrides,
});

/** A four-hundred-page crawl: many items, every one of them live, and NO active-version pointer.
 *  This is the common shape and the one the screen is designed against first. */
const crawl = (overrides: Partial<SourceDetailResource> = {}): SourceDetailResource =>
  detail({
    type: 'url',
    name: 'Support centre',
    origin_url: 'https://support.example.test/help',
    item_count: 419,
    active_version_count: 412,
    active_version: null,
    page_count: 0,
    element_count: 19_004,
    chunk_count: 47_311,
    ...overrides,
  });

describe('what "the current version" means, which is different for every shape of source', () => {
  it('names a version only for a single-item source that has one', () => {
    expect(liveState(detail())).toBe('single');
  });

  it('reports `many` for a crawl whose pointer is null and whose pages ARE live', () => {
    // THE TRAP. `active_version === null` does not mean "nothing is live": a four-hundred-page crawl
    // with every page serving reports null, because activation is a pointer on the ITEM and there is
    // no honest scalar for a set.
    expect(liveState(crawl())).toBe('many');
    expect(crawl().active_version).toBeNull();
    expect(crawl().active_version_count).toBeGreaterThan(0);
  });

  it('reports `none` from the COUNT, before the pointer is read at all', () => {
    // A one-file source whose ingestion never completed: one item, no pointer, nothing retrievable.
    // Reading the pointer first would call this `many`, which is the opposite of true.
    expect(liveState(detail({ item_count: 1, active_version_count: 0, active_version: null }))).toBe(
      'none',
    );
    // And a crawl in the same state, where the counts are the only signal there is.
    expect(liveState(crawl({ active_version_count: 0 }))).toBe('none');
  });
});

describe('the count tiles show what fits this source rather than every count for every source', () => {
  it('drops a structural count that is zero rather than stating it', () => {
    // Zero is AMBIGUOUS for these three by design: a deck, a spreadsheet and a crawled page all
    // report zero pages exactly as a source with no live content does. "0 slides" is a claim; saying
    // nothing is the truth.
    const ids = contentCounts(detail()).map((count) => count.id);
    expect(ids).toContain('pages');
    expect(ids).not.toContain('slides');
    expect(ids).not.toContain('sheets');
  });

  it('always states the three whose zero is a fact rather than an ambiguity', () => {
    const ids = contentCounts(crawl({ element_count: 0, chunk_count: 0 })).map((c) => c.id);
    expect(ids).toEqual(['items', 'elements', 'chunks']);
  });

  it('never labels the searchable excerpts as chunks', () => {
    // Chunks, embeddings, collections, spans and queues are ours; the user has documents, answers,
    // sources and bots (references/states.md). This is the one number most likely to leak the word.
    const rendered = JSON.stringify(contentCounts(detail()));
    expect(rendered.toLowerCase()).not.toContain('chunk"');
    expect(rendered).toContain('Searchable excerpts');
  });

  it('uses the noun this kind of source is measured in, and agrees its plural', () => {
    expect(itemNoun('file', 1)).toBe('file');
    expect(itemNoun('file', 2)).toBe('files');
    expect(itemNoun('url', 2)).toBe('crawled pages');
    expect(itemNoun('text', 1)).toBe('pasted text');
    // A `type` this build has never heard of renders as a neutral word rather than crashing: the
    // console is deployed separately from the API.
    expect(itemNoun('hologram', 2)).toBe('parts');
  });

  it('says how many of the items are live, without claiming all of them are', () => {
    const items = contentCounts(crawl()).find((count) => count.id === 'items');
    expect(items?.value).toBe(419);
    expect(items?.note).toBe('412 live.');
    expect(contentCounts(detail()).find((c) => c.id === 'items')?.note).toBe('All of them are live.');
  });
});

describe('the delete confirmation states its consequence in numbers the server will honour', () => {
  it('names the live items, the structure and the excerpts', () => {
    const sentence = deleteConsequenceCounts(detail());
    expect(sentence).toContain('1 file');
    expect(sentence).toContain('12 pages');
    expect(sentence).toContain('1,204 searchable excerpts');
    expect(sentence).toContain('every bot it is assigned to');
  });

  it('counts the LIVE items rather than every item, on a crawl', () => {
    const sentence = deleteConsequenceCounts(crawl());
    // 412 live of 419 — the seven that never published are not about to stop answering, because they
    // never started.
    expect(sentence).toContain('412 crawled pages');
    expect(sentence).not.toContain('419');
  });

  it('states nothing-is-live as a sentence rather than as a row of zeroes', () => {
    // "This removes 0 searchable excerpts" is technically true and reads as a bug.
    const sentence = deleteConsequenceCounts(
      detail({ active_version_count: 0, active_version: null, chunk_count: 0, page_count: 0 }),
    );
    expect(sentence).toBe('Nothing in it is live right now, so no bot is answering from it today.');
  });

  it('never states a bot count, because no endpoint publishes one', () => {
    // `kb-ui-patterns`' own example is "removes 1,204 chunks from 3 bots" and the second half is
    // unavailable: the assignment panel only knows about the bots on the page it is showing, and a
    // count derived from that would be a fact about what is visible dressed as a fact about the
    // organization.
    expect(deleteConsequenceCounts(detail())).not.toMatch(/\d+ bots?/);
  });
});

describe('advisory warnings are an open vocabulary with a fallback, never an enum', () => {
  it('has copy for the codes the ingestion service writes today', () => {
    expect(warningCopy('ocr_low_coverage')).toContain('scanned page');
    expect(warningCopy('ocr_text_unplaced')).toContain('not searchable');
    expect(warningCopy('ocr_coverage_unmeasurable')).toContain('could not be measured');
  });

  it('returns null for a code that shipped this morning, rather than inventing a sentence', () => {
    // The renderer shows the raw key in --font-mono for this case. An invented sentence would be
    // worse than the key, which is at least what the pipeline actually reported.
    expect(warningCopy('table_structure_uncertain')).toBeNull();
  });

  it('counts VERSIONS and says so in the noun this source uses', () => {
    // A `1` on a single-file source is the only value it can hold; a `1` on a crawl means one page
    // carries the problem and says nothing about how badly.
    expect(warningScope(detail(), 1)).toBe('Reported for this source.');
    expect(warningScope(crawl(), 6)).toBe('Reported for 6 of its 419 crawled pages.');
  });
});

describe('the detail transport', () => {
  it('reads the resource out of `data` and keeps every count', async () => {
    server.use(
      http.get(`${ORIGIN}/api/v1/organizations/${ORG}/sources/${SOURCE}`, () =>
        HttpResponse.json({ data: detail() }),
      ),
    );

    const resource = await fetchSourceDetail(ORG, SOURCE, new AbortController().signal);

    expect(resource.chunk_count).toBe(1204);
    expect(resource.active_version?.version_number).toBe(3);
    // `SourceDetailResource extends SourceResource`, so the list's own fields are here too.
    expect(resource.status_permits_retrieval).toBe(true);
  });

  it('is polled by the SAME predicate the list uses, over a one-row page', async () => {
    // The detail row IS a row as far as `sourcePollInterval` is concerned, so the two screens cannot
    // disagree about which states move on their own — and it returns `false`, never `0`, which
    // TanStack Query reads as "as fast as possible".
    expect(sourcePollInterval([detail({ status: 'embedding', status_is_processing: true })])).toBe(
      5_000,
    );
    expect(sourcePollInterval([detail()])).toBe(false);
    expect(sourcePollInterval(undefined)).toBe(false);
  });
});

describe('the grant lookup, which exists because the API has no source-side assignment endpoint', () => {
  const assignmentsUrl = `${ORIGIN}/api/v1/organizations/${ORG}/bots/${BOT}/source-assignments`;

  const grant = (sourceId: string, id: string) => ({
    id,
    source_id: sourceId,
    priority: 0,
    enabled: true,
    created_at: '2026-08-05T09:00:00+00:00',
    updated_at: '2026-08-05T09:00:00+00:00',
    source: detail(),
  });

  const page = (rows: readonly unknown[], meta: Record<string, unknown> = {}) => ({
    data: {
      source_assignments: rows,
      meta: {
        page: 1,
        per_page: 100,
        total: rows.length,
        total_pages: 1,
        sort: 'id',
        dir: 'asc',
        filter: null,
        ...meta,
      },
    },
  });

  it('builds the two paths off the BOT, and addresses a delete by the GRANT id', () => {
    expect(botSourceAssignmentsPath(ORG, BOT)).toBe(
      `/api/v1/organizations/${ORG}/bots/${BOT}/source-assignments`,
    );
    // NOT the source id. Withdrawing a permission and deleting a document are different operations
    // on different rows, and routing the first through the second's identifier would be one path
    // parameter away from the second.
    expect(botSourceAssignmentPath(ORG, BOT, '01JGRANT')).toBe(
      `/api/v1/organizations/${ORG}/bots/${BOT}/source-assignments/01JGRANT`,
    );
  });

  it('reads its bounds out of the dumped FormRequest rather than restating them', () => {
    expect(ASSIGNMENT_MAX_FILTER_LENGTH).toBe(maxFromRule(indexAssignmentsRules.rules.filter));
    expect(ASSIGNMENT_MAX_PER_PAGE).toBe(maxFromRule(indexAssignmentsRules.rules.per_page));
    // Not vacuously right: the parse really read this manifest.
    expect(ASSIGNMENT_MAX_FILTER_LENGTH).toBe(200);
    expect(ASSIGNMENT_MAX_PER_PAGE).toBe(100);
  });

  it('truncates the filter term to that ceiling instead of 422ing on a long name', () => {
    const long = 'x'.repeat(500);
    expect(assignmentFilterTerm(long)).toHaveLength(ASSIGNMENT_MAX_FILTER_LENGTH ?? 0);
    // A PREFIX still matches the name, because the comparison is `%term%`. Truncation can only ever
    // match MORE rows, never fewer, and the scan keys on `source_id`.
    expect(long.includes(assignmentFilterTerm(long))).toBe(true);
    expect(assignmentFilterTerm('  spaced  ')).toBe('spaced');
  });

  it('finds the grant on the first page and sends the source name as the filter', async () => {
    const queries: string[] = [];
    server.use(
      http.get(assignmentsUrl, ({ request }) => {
        queries.push(new URL(request.url).search);
        return HttpResponse.json(page([grant(SOURCE, '01JGRANTAAAA')]));
      }),
    );

    const found = await fetchGrantForSource(
      ORG,
      BOT,
      { id: SOURCE, name: 'Q3 handbook.pdf' },
      new AbortController().signal,
    );

    expect(found?.id).toBe('01JGRANTAAAA');
    expect(queries).toHaveLength(1);
    expect(queries[0]).toContain('filter=Q3+handbook.pdf');
    // `id` ascending, not the endpoint's `priority` default: paging over a column where every row
    // shares the value zero can repeat or skip a row between pages.
    expect(queries[0]).toContain('sort=id');
    expect(queries[0]).toContain('per_page=100');
  });

  it('walks to the next page when an over-matching filter pushed the row off the first', async () => {
    // Two documents sharing a word — or a name containing `%`, which LIKE reads as a wildcard — put
    // more rows in front of the one being looked for. Over-matching is safe and under-matching is
    // not, which is why the term is the untouched name and the loop is bounded by `meta.total`.
    const filler = Array.from({ length: 100 }, (_, i) => grant(`01JOTHER${i}`, `01JG${i}`));
    server.use(
      http.get(assignmentsUrl, ({ request }) => {
        const page1 = new URL(request.url).searchParams.get('page') === '1';
        return HttpResponse.json(
          page1
            ? page(filler, { total: 140, per_page: 100, page: 1 })
            : page([grant(SOURCE, '01JGRANTONPAGE2')], { total: 140, per_page: 100, page: 2 }),
        );
      }),
    );

    const found = await fetchGrantForSource(
      ORG,
      BOT,
      { id: SOURCE, name: 'handbook' },
      new AbortController().signal,
    );

    expect(found?.id).toBe('01JGRANTONPAGE2');
  });

  it('resolves NULL — not undefined — only after the pages were read to the end', async () => {
    server.use(http.get(assignmentsUrl, () => HttpResponse.json(page([]))));

    // `null` is TanStack Query's rule rather than a preference: a `queryFn` that resolves
    // `undefined` is rejected with "data is undefined", so the query lands in its ERROR state and
    // the row renders "we could not check this" for a request that succeeded and said no — which is
    // exactly the confusion the throw above exists to prevent, arriving by a different door.
    await expect(
      fetchGrantForSource(ORG, BOT, { id: SOURCE, name: 'nothing' }, new AbortController().signal),
    ).resolves.toBeNull();
  });

  it('THROWS rather than answering "not assigned" when the request failed', async () => {
    // The two are different facts, and collapsing them renders an Assign button whose click the
    // server answers with a 422 duplicate — on a control that decides what a bot may read.
    server.use(
      http.get(assignmentsUrl, () =>
        HttpResponse.json(
          { error_class: 'internal_dependency', message: 'x', retryable: false, request_id: 'r' },
          { status: 500 },
        ),
      ),
    );

    await expect(
      fetchGrantForSource(ORG, BOT, { id: SOURCE, name: 'handbook' }, new AbortController().signal),
    ).rejects.toBeTruthy();
  });

  it('posts only `source_id`, and never a priority nothing ranks on', async () => {
    let body: unknown = null;
    server.use(
      http.post(assignmentsUrl, async ({ request }) => {
        body = await request.json();
        return HttpResponse.json({ data: grant(SOURCE, '01JNEW') }, { status: 201 });
      }),
    );

    await createBotSourceAssignment(ORG, BOT, SOURCE);

    expect(body).toEqual({ source_id: SOURCE });
  });

  it('withdraws by the grant id and reads the acknowledgement', async () => {
    server.use(
      http.delete(`${assignmentsUrl}/01JGRANTAAAA`, () =>
        HttpResponse.json({ data: { acknowledged: true } }),
      ),
    );

    await expect(deleteBotSourceAssignment(ORG, BOT, '01JGRANTAAAA')).resolves.toEqual({
      acknowledged: true,
    });
  });
});

describe('what this viewer may be offered', () => {
  it('grants `sources.assign` to the three roles that hold it and to no one else', () => {
    expect(canAssignSources('owner')).toBe(true);
    expect(canAssignSources('admin')).toBe(true);
    expect(canAssignSources('knowledge_manager')).toBe(true);
    // An analyst holds `bots.view` and nothing else — including no `sources.view`, so they cannot
    // reach this screen at all today. The predicate is still positive, so a fifth role added to
    // `Role` defaults to holding nothing.
    expect(canAssignSources('analyst')).toBe(false);
    expect(canAssignSources(null)).toBe(false);
  });

  it('can still order the assignment panel`s bot list by name', () => {
    // The panel derives its `sort` from this array and falls back if `name` ever leaves the closed
    // set, because a hand-written value the manifest stops permitting is a 422 on a request the user
    // made by opening a page. This asserts the fallback is not silently in use today.
    expect(BOT_SORTABLE_COLUMNS).toContain('name');
  });
});
