import type {
  BotResource,
  BotSourceAssignmentResource,
  SourceActiveVersionResource,
  SourceDetailResource,
} from '@kb/contracts';
import type { QueryClient } from '@tanstack/react-query';
import { useQueryClient } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import type * as NextNavigation from 'next/navigation';
import { useEffect } from 'react';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Providers } from '@/components/providers';
import { SessionProvider } from '@/features/auth/session-provider';
import { SourceDetailScreen } from '@/features/sources/source-detail-screen';

import { envelope, ORIGIN, sessionFixture } from '../msw/handlers';
import { worker } from '../msw/setup';
import {
  resetNavigation,
  useMockPathname,
  useMockRouter,
  useMockSearchParams,
} from '../support/mock-navigation';

/**
 * `/sources/{sourceId}` — the detail screen, its five cards, and the bot-assignment surface on it.
 *
 * ── WHAT THIS SPEC MAY NOT CLAIM ────────────────────────────────────────────────────────────────
 * It proves the query keys are org-namespaced and that every child collection hangs off the
 * source's own key. It proves NOTHING about isolation: a component test that mocks the API cannot
 * fail an isolation test, and per the `vitest-playwright` boundary table it must never be cited as
 * isolation coverage. "Organization A's document never appears after switching to B" is Playwright's
 * claim, against a real server.
 *
 * ── `next/navigation` IS MOCKED because `<Link>` and the back link need a router ─────────────────
 * `useRouter()` throws outside a mounted App Router. `tests/support/mock-navigation.ts` is an
 * observable store rather than a bare spy.
 *
 * ── NO CSS IS IMPORTED, ON PURPOSE ──────────────────────────────────────────────────────────────
 * Only `design-system-css.test.tsx` imports `globals.css`. Which card is visible at which width is
 * CSS's decision and is a Playwright/manual check; what is IN the DOM is this spec's.
 *
 * ── EVERY `render` IS AWAITED ───────────────────────────────────────────────────────────────────
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
/** The other ACTIVE membership in the same fixture, where this user is an ANALYST — the one role
 *  that does not hold `sources.view`. */
const ORG_B = '01JORGBBBBBBBBBBBBBBBBBBBB';

const SOURCE = '01JSOURCEAAAAAAAAAAAAAAAAA';
const BOT_ASSIGNED = '01JBOTAAAAAAAAAAAAAAAAAAAA';
const BOT_UNASSIGNED = '01JBOTBBBBBBBBBBBBBBBBBBBB';

const detailUrl = (orgId: string, sourceId = SOURCE) =>
  `${ORIGIN}/api/v1/organizations/${orgId}/sources/${sourceId}`;
const botsUrl = (orgId: string) => `${ORIGIN}/api/v1/organizations/${orgId}/bots`;
const assignmentsUrl = (orgId: string, botId: string) =>
  `${ORIGIN}/api/v1/organizations/${orgId}/bots/${botId}/source-assignments`;

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

/** A single-file upload with every field the resource publishes. */
const HANDBOOK: SourceDetailResource = {
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
  content_preview: 'Staff handbook\n\nSection 1. Working hours.',
  content_preview_truncated: true,
};

/** THE COMMON SHAPE: a crawl with hundreds of independently-versioned pages and NO active-version
 *  pointer. Designed for first, because `null` here does not mean "nothing is live". */
const CRAWL: SourceDetailResource = {
  ...HANDBOOK,
  type: 'url',
  name: 'Support centre',
  origin_url: 'https://support.example.test/help',
  status: 'ready_with_warnings',
  item_count: 419,
  active_version_count: 412,
  active_version: null,
  page_count: 0,
  element_count: 19_004,
  chunk_count: 47_311,
  warnings: [
    { code: 'ocr_low_coverage', versions: 6 },
    { code: 'table_structure_uncertain', versions: 2 },
  ],
  warnings_truncated: true,
};

const SUPPORT_BOT: BotResource = {
  id: BOT_ASSIGNED,
  public_bot_id: 'pb_support',
  name: 'Support bot',
  slug: 'support-bot',
  description: 'Answers billing and account questions.',
  welcome_message: null,
  placeholder_text: null,
  system_instruction: null,
  answer_style_instruction: null,
  instructions_visible: true,
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

const ONBOARDING_BOT: BotResource = {
  ...SUPPORT_BOT,
  id: BOT_UNASSIGNED,
  public_bot_id: 'pb_onboarding',
  name: 'Onboarding bot',
  slug: 'onboarding-bot',
  status: 'draft',
};

const GRANT: BotSourceAssignmentResource = {
  id: '01JGRANTAAAAAAAAAAAAAAAAAA',
  source_id: SOURCE,
  priority: 0,
  enabled: true,
  created_at: '2026-08-05T09:00:00+00:00',
  updated_at: '2026-08-05T09:00:00+00:00',
  source: HANDBOOK,
};

const botsBody = (rows: readonly BotResource[], meta: Record<string, unknown> = {}) => ({
  data: {
    bots: rows,
    meta: {
      page: 1,
      per_page: 10,
      total: rows.length,
      total_pages: 1,
      sort: 'name',
      dir: 'asc',
      filter: null,
      ...meta,
    },
  },
});

const assignmentsBody = (rows: readonly BotSourceAssignmentResource[]) => ({
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
    },
  },
});

/**
 * The whole happy path: one source, two bots, one of which holds a grant.
 *
 * Registered as a helper rather than in `beforeEach` so a spec about a failure can install its own
 * handler for exactly one of the four requests and leave the others working.
 */
const happyPath = (source: SourceDetailResource = HANDBOOK, bots = [SUPPORT_BOT, ONBOARDING_BOT]) => [
  http.get(detailUrl(ORG_A), () => HttpResponse.json({ data: source })),
  http.get(botsUrl(ORG_A), () => HttpResponse.json(botsBody(bots))),
  http.get(assignmentsUrl(ORG_A, BOT_ASSIGNED), () => HttpResponse.json(assignmentsBody([GRANT]))),
  http.get(assignmentsUrl(ORG_A, BOT_UNASSIGNED), () => HttpResponse.json(assignmentsBody([]))),
];

/** Captures the tree's QueryClient so the KEYS themselves can be asserted, not just request URLs.
 *  The organization is not in this screen's URL at all, so the key is the artifact worth reading. */
let captured: QueryClient | null = null;
function CaptureClient() {
  const queryClient = useQueryClient();
  useEffect(() => {
    captured = queryClient;
  }, [queryClient]);
  return null;
}

const renderScreen = (sourceId = SOURCE) =>
  render(
    <Providers>
      <SessionProvider>
        <CaptureClient />
        <SourceDetailScreen sourceId={sourceId} />
      </SessionProvider>
    </Providers>,
  );

const cacheKeys = (): unknown[][] =>
  (captured?.getQueryCache().getAll() ?? []).map((query) => [...query.queryKey]);

beforeEach(() => {
  // `readCookie` reads the PAGE's cookie and MSW cannot set one for a cross-origin host from a
  // service worker, so without this every spec takes the `refreshCsrfToken()` path and fails for a
  // reason that has nothing to do with what it is about.
  document.cookie = 'XSRF-TOKEN=test-token';
  captured = null;
  resetNavigation();
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

describe('the query keys are org-namespaced and every child hangs off the source', () => {
  it('keys the resource at [org, orgId, sources, sourceId] and each grant beneath it', async () => {
    worker.use(...happyPath());

    const screen = await renderScreen();
    await expect.element(screen.getByText('Q3 handbook.pdf')).toBeVisible();

    await vi.waitFor(() => {
      expect(cacheKeys()).toContainEqual(['org', ORG_A, 'sources', SOURCE]);
      // A PREFIX EXTENSION of the source's key, so invalidating the source invalidates the panel.
      expect(cacheKeys()).toContainEqual([
        'org',
        ORG_A,
        'sources',
        SOURCE,
        'assignments',
        BOT_ASSIGNED,
      ]);
    });

    const keys = cacheKeys();
    // `['session']` is the ONE legal exception to the org prefix — it is the PRODUCER of `orgId`.
    expect(keys.filter((key) => key[0] !== 'org')).toEqual([['session']]);
    // Not one key mentions the organization the session is NOT currently acting in.
    expect(JSON.stringify(keys)).not.toContain(ORG_B);
  });

  it('shares the bot list`s own key shape, so this panel and /bots are one cache entry', async () => {
    worker.use(...happyPath());

    const screen = await renderScreen();
    await expect.element(screen.getByText('Support bot')).toBeVisible();

    await vi.waitFor(() => {
      expect(cacheKeys()).toContainEqual([
        'org',
        ORG_A,
        'bots',
        { page: '1', per_page: '10', sort: 'name', dir: 'asc' },
      ]);
    });
  });
});

describe('“the current version”, which is a different fact for every shape of source', () => {
  it('names the version and its opaque configuration for a single-item source', async () => {
    worker.use(...happyPath());

    const screen = await renderScreen();

    await expect.element(screen.getByText('Version 3')).toBeVisible();
    // The four `*_cfg_version` strings are ingest-key components, rendered as opaque identifiers.
    // Splitting one to show a friendlier model name would be reading a format the data plane owns.
    await expect
      .element(screen.getByText('emb/v1:openai:text-embedding-3-large:d3072:9f2a1c4e77b1'))
      .toBeVisible();
    await expect.element(screen.getByText('docling/2.118:layout-v3')).toBeVisible();
  });

  it('renders the pair of counts for a crawl, and names no version at all', async () => {
    // THE NULL CASE, AND IT IS THE COMMON ONE. `active_version` is null for every crawl and every
    // multi-file upload — including this one, where 412 pages are answering right now.
    worker.use(...happyPath(CRAWL));

    const screen = await renderScreen();

    await expect.element(screen.getByText(/412 of 419 crawled pages are answering/)).toBeVisible();
    await expect.element(screen.getByText(/no single version to name/)).toBeVisible();
    expect(screen.getByText('Version 3').elements()).toHaveLength(0);
    expect(document.body.textContent).not.toContain('docling/2.118');
  });

  it('says nothing is live when the count is zero, whatever the status says', async () => {
    worker.use(
      ...happyPath({
        ...HANDBOOK,
        status: 'failed',
        active_version_count: 0,
        active_version: null,
        chunk_count: 0,
      }),
    );

    const screen = await renderScreen();

    await expect.element(screen.getByText('Nothing in this source is live yet')).toBeVisible();
    await expect.element(screen.getByText(/No bot can answer from it/)).toBeVisible();
  });

  it('drops a structural count that is zero instead of stating it', async () => {
    worker.use(...happyPath());

    const screen = await renderScreen();

    await expect.element(screen.getByText('Pages')).toBeVisible();
    // A file with no slides reports zero exactly as a source with no live content does. "0 slides"
    // is a claim; saying nothing is the truth.
    expect(screen.getByText('Slides').elements()).toHaveLength(0);
    expect(screen.getByText('Sheets').elements()).toHaveLength(0);
    // And the excerpt count never surfaces the word "chunk".
    await expect.element(screen.getByText('Searchable excerpts')).toBeVisible();
    await expect.element(screen.getByText('1,204')).toBeVisible();
  });
});

describe('advisory warnings', () => {
  it('renders each one as a degraded note, with our copy or the raw key', async () => {
    worker.use(...happyPath(CRAWL));

    const screen = await renderScreen();

    await expect.element(screen.getByText(/Part of a scanned page could not be read/)).toBeVisible();
    // A code this build has no copy for renders AS THE KEY, in --font-mono. Inventing a sentence
    // would be worse than showing what the pipeline actually reported.
    await expect.element(screen.getByText('table_structure_uncertain')).toBeVisible();
    // VERSIONS, never occurrences — and phrased in the noun this source's items are counted in.
    await expect.element(screen.getByText('Reported for 6 of its 419 crawled pages.')).toBeVisible();
    await expect.element(screen.getByText(/More kinds of note were recorded/)).toBeVisible();
    // NOT a live region: this has been true since page load, and `<DegradedNote>` is a <p>.
    expect(document.querySelectorAll('[role="alert"] [data-degraded]')).toHaveLength(0);
  });

  it('renders no warnings card at all when there are none', async () => {
    worker.use(...happyPath());

    const screen = await renderScreen();
    await expect.element(screen.getByText('Q3 handbook.pdf')).toBeVisible();

    // A card headed "Warnings" containing "None" makes an operator look for a problem that is not
    // there. There is no empty state here, deliberately.
    expect(screen.getByText('How well this was read').elements()).toHaveLength(0);
  });
});

describe('the extracted preview is tenant text and is never markup', () => {
  it('renders an injected tag as visible characters, with no element created', async () => {
    const hostile = '<img src=x onerror="alert(1)"> <b>bold</b>';
    worker.use(...happyPath({ ...HANDBOOK, content_preview: hostile }));

    const screen = await renderScreen();

    await expect.element(screen.getByText(hostile)).toBeVisible();
    // The server does not escape this field, deliberately — that makes the escaping the renderer's
    // job. React escapes a JSX child; `dangerouslySetInnerHTML` is banned repo-wide by ESLint.
    expect(document.querySelectorAll('img')).toHaveLength(0);
    expect(document.querySelectorAll('b')).toHaveLength(0);
  });

  it('says nothing has been extracted when the field is null, rather than showing a blank panel', async () => {
    worker.use(...happyPath({ ...HANDBOOK, content_preview: null, content_preview_truncated: false }));

    const screen = await renderScreen();

    await expect.element(screen.getByText(/Nothing has been extracted/)).toBeVisible();
  });

  it('marks a cut excerpt as a beginning rather than as a warning', async () => {
    worker.use(...happyPath());

    const screen = await renderScreen();

    await expect.element(screen.getByText(/this is the beginning only/)).toBeVisible();
  });
});

describe('the delete confirmation, which is the whole reason this screen fetches the counts', () => {
  it('states the consequence in the numbers the detail resource carries', async () => {
    worker.use(...happyPath());

    const screen = await renderScreen();
    await screen.getByRole('button', { name: 'Actions for Q3 handbook.pdf' }).click();
    await screen.getByRole('menuitem', { name: 'Delete' }).click();

    await expect
      .element(screen.getByText(/That is 1 file, 12 pages and 1,204 searchable excerpts/))
      .toBeVisible();
    await expect.element(screen.getByText(/This is phase 1 of 2/)).toBeVisible();
    // Typed confirmation, and the string to type is the document's own name.
    await expect.element(screen.getByRole('button', { name: 'Delete source' })).toBeDisabled();
  });

  it('states nothing-is-live as a sentence rather than as a row of zeroes', async () => {
    worker.use(
      ...happyPath({
        ...HANDBOOK,
        active_version_count: 0,
        active_version: null,
        chunk_count: 0,
        page_count: 0,
      }),
    );

    const screen = await renderScreen();
    await screen.getByRole('button', { name: 'Actions for Q3 handbook.pdf' }).click();
    await screen.getByRole('menuitem', { name: 'Delete' }).click();

    await expect.element(screen.getByText(/Nothing in it is live right now/)).toBeVisible();
  });
});

describe('the bot assignment surface', () => {
  it('tells an assigned bot from an unassigned one, and offers the matching verb', async () => {
    worker.use(...happyPath());

    const screen = await renderScreen();

    await expect.element(screen.getByText('Assigned. This bot may answer from this source.')).toBeVisible();
    await expect.element(screen.getByText(/Not assigned/)).toBeVisible();
    await expect
      .element(screen.getByRole('button', { name: 'Remove this source from Support bot' }))
      .toBeVisible();
    await expect
      .element(screen.getByRole('button', { name: 'Assign this source to Onboarding bot' }))
      .toBeVisible();
  });

  it('never renders “assigned” as “answering” when nothing in the source is live', async () => {
    worker.use(...happyPath({ ...HANDBOOK, active_version_count: 0, active_version: null }));

    const screen = await renderScreen();

    // Reachability is the AND of four terms and this row owns one. This is exactly the state an
    // operator opens the screen to explain.
    await expect
      .element(screen.getByText(/Assigned — but nothing in this source is live/))
      .toBeVisible();
  });

  it('posts only `source_id` and announces the write politely while it is in flight', async () => {
    let body: unknown = null;
    // `let release: (() => void) | null` infers `never` at the call site — TypeScript narrows it to
    // `null` because the only assignment it can see is the initializer, and the executor's write is
    // invisible to control-flow analysis. Declared as the union of what it holds instead.
    let release: () => void = () => {};
    const held = new Promise<void>((resolve) => {
      release = resolve;
    });

    worker.use(
      http.post(assignmentsUrl(ORG_A, BOT_UNASSIGNED), async ({ request }) => {
        body = await request.json();
        await held;
        return HttpResponse.json({ data: { ...GRANT, id: '01JNEW' } }, { status: 201 });
      }),
      ...happyPath(),
    );

    const screen = await renderScreen();
    await screen.getByRole('button', { name: 'Assign this source to Onboarding bot' }).click();

    // `mutation.variables` + `isPending` + `aria-busy` + an sr-only role="status" line, and NO
    // optimistic write: the cache still holds the server's answer throughout, so the row does not
    // claim a grant that may be refused.
    await expect
      .element(screen.getByRole('status', { includeHidden: true }))
      .toHaveTextContent('Assigning Q3 handbook.pdf for Onboarding bot…');
    await vi.waitFor(() => {
      expect(document.querySelectorAll('li[aria-busy="true"]')).toHaveLength(1);
    });
    // Still "Not assigned" underneath: nothing was written into the cache.
    await expect.element(screen.getByText(/Not assigned/)).toBeVisible();

    release();
    await vi.waitFor(() => {
      expect(body).toEqual({ source_id: SOURCE });
    });
  });

  it('renders a refusal as an inline banner and never the envelope`s operator message', async () => {
    worker.use(
      http.post(assignmentsUrl(ORG_A, BOT_UNASSIGNED), () =>
        HttpResponse.json(
          envelope('validation', {
            errors: { source_id: ['This bot already has the maximum of 200 assigned sources.'] },
          }),
          { status: 422 },
        ),
      ),
      ...happyPath(),
    );

    const screen = await renderScreen();
    await screen.getByRole('button', { name: 'Assign this source to Onboarding bot' }).click();

    await expect.element(screen.getByText('That change was not saved')).toBeVisible();
    // A `validation` envelope's per-field messages ARE end-user copy and are rendered; the
    // envelope's own `message` is operator-facing and names an internal host, so it never is.
    await expect.element(screen.getByText(/maximum of 200 assigned sources/)).toBeVisible();
    expect(document.body.textContent).not.toContain('api-7.internal');
  });

  it('says a failed lookup could not be checked, and offers no control for it', async () => {
    // THE OVERRIDE COMES FIRST. `worker.use()` prepends its arguments in order and the FIRST match
    // wins, so putting this after `happyPath()` — which already answers this URL with a 200 — would
    // silently leave the 503 unused and test the happy path twice.
    worker.use(
      http.get(assignmentsUrl(ORG_A, BOT_UNASSIGNED), () =>
        HttpResponse.json(envelope('internal_dependency'), { status: 503 }),
      ),
      ...happyPath(),
    );

    const screen = await renderScreen();

    // "Could not check" and "not assigned" are different facts. Rendering the second for the first
    // offers an Assign button whose click the server answers with a 422 duplicate.
    await expect.element(screen.getByText(/could not be checked/)).toBeVisible();
    expect(
      screen.getByRole('button', { name: 'Assign this source to Onboarding bot' }).elements(),
    ).toHaveLength(0);
    // The bot that resolved is unaffected.
    await expect
      .element(screen.getByRole('button', { name: 'Remove this source from Support bot' }))
      .toBeVisible();
  });

  it('offers the first-run empty state — never a filtered one — when there are no bots', async () => {
    worker.use(
      http.get(detailUrl(ORG_A), () => HttpResponse.json({ data: HANDBOOK })),
      http.get(botsUrl(ORG_A), () => HttpResponse.json(botsBody([]))),
    );

    const screen = await renderScreen();

    await expect.element(screen.getByText('No bots yet')).toBeVisible();
    // There is no filter on this panel, so "Clear filters" would be advice for a state that cannot
    // exist.
    expect(screen.getByRole('button', { name: 'Clear filters' }).elements()).toHaveLength(0);
  });
});

describe('error and forbidden', () => {
  it('names the role for the ONE viewer who genuinely lacks it', async () => {
    // The analyst membership in the shared fixture. `sources.view` is granted to three roles and not
    // to this one, and the deny split makes the refusal indistinguishable from a stale id ON THE
    // WIRE — so the screen tells them apart by the session's own role.
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () =>
        HttpResponse.json({ data: sessionFixture({ current_organization_id: ORG_B }) }),
      ),
      http.get(detailUrl(ORG_B), () => HttpResponse.json(envelope('authorization'), { status: 403 })),
    );

    const screen = await renderScreen();

    await expect.element(screen.getByText("You don't have access to this")).toBeVisible();
    await expect.element(screen.getByText(/knowledge manager role/)).toBeVisible();
  });

  it('gives an OWNER the class-mapped refusal instead, because a role is the wrong answer', async () => {
    // Same 403, different reader: for an owner this is a stale link or a revoked membership, both of
    // which 404 at binding time and render as `authorization`.
    worker.use(http.get(detailUrl(ORG_A), () => HttpResponse.json(envelope('authorization'), { status: 403 })));

    const screen = await renderScreen();

    await expect.element(screen.getByText('This source could not be loaded')).toBeVisible();
    expect(screen.getByText("You don't have access to this").elements()).toHaveLength(0);
    // The envelope's `message` names an internal host and reaches no rendered string.
    expect(document.body.textContent).not.toContain('api-7.internal');
  });

  it('keeps the way back in every state, including the one an unknown id lands on', async () => {
    worker.use(http.get(detailUrl(ORG_A), () => HttpResponse.json(envelope('authorization'), { status: 403 })));

    const screen = await renderScreen();

    await expect.element(screen.getByRole('link', { name: 'All sources' })).toBeVisible();
  });

  it('draws a skeleton and no cards while the first read is in flight', async () => {
    worker.use(http.get(detailUrl(ORG_A), () => new Promise<never>(() => {})));

    const screen = await renderScreen();

    await vi.waitFor(() => {
      expect(document.querySelectorAll('[aria-busy="true"]').length).toBeGreaterThan(0);
    });
    expect(screen.getByText('What is in this source').elements()).toHaveLength(0);
    // A skeleton is never announced: the container carries aria-busy, the blocks are aria-hidden.
    expect(document.querySelectorAll('[data-slot="skeleton"]:not([aria-hidden])')).toHaveLength(0);
  });
});
