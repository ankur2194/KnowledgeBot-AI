import type { QueryClient } from '@tanstack/react-query';
import { useQueryClient } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { useEffect } from 'react';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Providers } from '@/components/providers';
import { SessionProvider } from '@/features/auth/session-provider';
import type { EmbeddingReadinessResource } from '@kb/contracts';
import { EmbeddingScreen } from '@/features/embedding/embedding-screen';

import { envelope, ORIGIN, sessionFixture } from '../msw/handlers';
import { worker } from '../msw/setup';

/**
 * `/settings/embedding` — the organization's embedding designation, and why it can or cannot ingest.
 *
 * ── WHAT THIS SPEC MAY NOT CLAIM ─────────────────────────────────────────────────────────────────
 * It proves that the query key IS org-namespaced and that the request path carries the organization.
 * It proves NOTHING about isolation: a component test that mocks the API cannot fail an isolation
 * test, and per the vitest-playwright boundary table it must never be cited as isolation coverage.
 * "Organization A's verdict never appears after switching to B" is Playwright's, and is recorded as
 * unproven by this step.
 *
 * ── THE HANDLERS FOR THIS ENDPOINT LIVE HERE, NOT IN tests/msw/handlers.ts ──────────────────────
 * That file is shared by every component spec; `worker.use(...)` inside a spec is the documented
 * override mechanism and `afterEach`'s `resetHandlers()` keeps it from leaking into the next file.
 * The harness runs with `onUnhandledRequest: 'error'`, which under the service-worker transport
 * answers `500 Request Handler Error` rather than rejecting — so a missing handler surfaces as an
 * error state the spec did not ask for rather than as a network failure. Every spec installs the GET.
 */

/** `sessionFixture()`'s current organization, and the namespace every key below must carry. */
const ORG_A = '01JORGAAAAAAAAAAAAAAAAAAAA';
/** The other ACTIVE membership in the same fixture. Present so the cache-key assertion is about the
 *  CURRENT organization rather than about the only one there is — a one-organization fixture cannot
 *  fail a namespacing test. */
const ORG_B = '01JORGBBBBBBBBBBBBBBBBBBBB';

/**
 * ── THESE CONNECTION IDS ARE NOT THE SIBLING SPECS' `01JCONN…`, AND THE DIFFERENCE IS LOAD-BEARING ─
 * This is the first screen that puts a connection id through a Zod schema rather than only into a
 * URL. `embeddingDesignationSchema` matches Laravel's `ulid` rule — Crockford base32, which EXCLUDES
 * I, L, O and U — and `01JCONNAAA…` contains an `O`. It is a perfectly good fixture for
 * `providers-screen.test.tsx`, where nothing validates it, and it would fail this form's resolver
 * with "Select a provider connection." before a single request left the browser. So: `01JCNN…`.
 */
const CONNECTION_LARGE = '01JCNNAAAAAAAAAAAAAAAAAAAA';
const CONNECTION_SMALL = '01JCNNBBBBBBBBBBBBBBBBBBBB';
const CONNECTION_CHAT = '01JCNNCCCCCCCCCCCCCCCCCCCC';

const configurationUrl = (orgId: string) =>
  `${ORIGIN}/api/v1/organizations/${orgId}/embedding-configuration`;

const LARGE = {
  connection_id: CONNECTION_LARGE,
  provider: 'openai',
  model: 'text-embedding-3-large',
} as const;

const SMALL = {
  connection_id: CONNECTION_SMALL,
  provider: 'openai',
  model: 'text-embedding-3-small',
} as const;

/**
 * The STORED pair, which is `EmbeddingDesignation` and NOT `EmbeddingCandidate`: no `provider`.
 * An organization stores a connection id and a model string; the vendor is a property of the
 * connection and is resolved at read time. Spreading `LARGE` here instead would have given the
 * fixture a field the wire does not carry, and a UI that read it would have looked correct against
 * the fixture and printed `undefined/…` against the server.
 */
const LARGE_DESIGNATION = {
  connection_id: CONNECTION_LARGE,
  model: 'text-embedding-3-large',
} as const;

/**
 * The ADR-031 paragraph, byte for byte as `services/ai-service/app/providers/embedding_selection.py`
 * `_ambiguous()` composes it.
 *
 * IT IS ASSERTED WHOLE, ON PURPOSE. The ingestion path raises this same string, so an operator who
 * reads it here and then reads it on a failed upload must see the same words — and a spec that
 * matched only a fragment would go green against a UI that truncated, summarised or rewrote it,
 * which is exactly the regression this screen exists to prevent.
 */
const AMBIGUOUS =
  'This organization has 2 embedding-capable connections that do not agree on (provider, model): ' +
  `${CONNECTION_LARGE} -> openai/text-embedding-3-large; ` +
  `${CONNECTION_SMALL} -> openai/text-embedding-3-small. ` +
  'Which one embeds is not a preference — that pair IS the vector space (EmbeddingSpace derives the ' +
  'Qdrant collection name from it), so breaking the tie by convention would let an unrelated ' +
  'connection edit move the space a corpus was indexed under. Cosine distance is defined between ' +
  'any two vectors of equal width, so nothing would raise and only ranking would change. Designate ' +
  'one connection and model explicitly.';

/** A ready verdict: one eligible pair, and it is the selected one. */
const READY: EmbeddingReadinessResource = {
  ready: true,
  blocks_ingestion: false,
  selected: LARGE,
  // STORED AND RESOLVING, and the two agree on connection and model — which is what the server
  // documents for a ready verdict that carries a designation.
  designated: LARGE_DESIGNATION,
  eligible: [LARGE],
  rejected: [],
  // The empty string when `ready` is true — exactly one of this and `selected` is populated.
  explanation: '',
};

/** The ADR-031 state: two eligible pairs that name two different vector spaces, so nothing resolves. */
const AMBIGUOUS_VERDICT: EmbeddingReadinessResource = {
  ready: false,
  blocks_ingestion: true,
  selected: null,
  // NOTHING STORED. That is what makes this the ambiguity: with a designation the resolver would
  // have used it or refused it by name, and either way there would be no tie to break.
  designated: null,
  eligible: [LARGE, SMALL],
  rejected: [],
  explanation: AMBIGUOUS,
};

/**
 * THE STATE THIS FILE COULD NOT EXPRESS BEFORE `designated` EXISTED: a pair IS stored and it no
 * longer resolves. The model row lost its embedding flag, so `selected` is null exactly as it is in
 * `AMBIGUOUS_VERDICT` — and the operator's next action is completely different.
 */
const DESIGNATION_UNRESOLVED_EXPLANATION =
  `The designated embedding connection ${CONNECTION_LARGE} -> text-embedding-3-large cannot embed: ` +
  'row_lacks_embedding_flag (capability_flags on this provider_models row does not include ' +
  'embedding). It is not substituted — silently embedding through a different connection would ' +
  'change the vector space under a corpus nobody reindexed.';

const DESIGNATED_BUT_UNRESOLVED: EmbeddingReadinessResource = {
  ready: false,
  blocks_ingestion: true,
  selected: null,
  designated: LARGE_DESIGNATION,
  // A pair IS eligible, and it is deliberately NOT the designated one: a designation is never
  // substituted, so an eligible alternative changes nothing about the verdict.
  eligible: [SMALL],
  rejected: [
    {
      connection_id: CONNECTION_LARGE,
      provider: 'openai',
      model: 'text-embedding-3-large',
      reason: 'row_lacks_embedding_flag',
      detail: 'capability_flags on this provider_models row does not include embedding.',
    },
  ],
  explanation: DESIGNATION_UNRESOLVED_EXPLANATION,
};

const showHandler = (readiness: EmbeddingReadinessResource) =>
  http.get(configurationUrl(ORG_A), () => HttpResponse.json({ data: readiness }));

/** Captures the tree's QueryClient so the KEYS themselves can be asserted, not just the request URLs.
 *  The organization is not in the URL of this screen — that is the whole hazard — so the key is the
 *  artifact worth reading. The write happens in an EFFECT and not during render: `react-hooks/globals`
 *  is an ERROR on reassigning a module-scope variable from a render body. */
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
        <EmbeddingScreen />
      </SessionProvider>
    </Providers>,
  );

const cacheKeys = (): unknown[][] =>
  (captured?.getQueryCache().getAll() ?? []).map((query) => [...query.queryKey]);

beforeEach(() => {
  // `readCookie` reads the PAGE's cookie and MSW cannot set one for a cross-origin host from a
  // service worker, so without this every spec takes the `refreshCsrfToken()` path and fails on a
  // missing cookie for a reason that has nothing to do with what it is about.
  document.cookie = 'XSRF-TOKEN=test-token';
  captured = null;
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

describe('every organization-scoped key comes from useOrgKey()', () => {
  it('reads the verdict under ["org", currentOrgId, …] and never a bare key', async () => {
    worker.use(showHandler(READY));

    const screen = await renderScreen();
    await expect.element(screen.getByText('Current embedding pair')).toBeVisible();

    await vi.waitFor(() => {
      expect(cacheKeys()).toContainEqual(['org', ORG_A, 'embedding-configuration']);
    });

    const keys = cacheKeys();
    // `['session']` is the ONE legal exception to the org prefix — it is the PRODUCER of `orgId`,
    // and namespacing the identity document by the organization it announces would be circular.
    expect(keys.filter((key) => key[0] !== 'org')).toEqual([['session']]);
    // Not one key mentions the organization the session is NOT currently acting in.
    expect(JSON.stringify(keys)).not.toContain(ORG_B);
  });

  it('addresses the org in the PATH too, which is a routing hint and not the scope', async () => {
    const urls: string[] = [];
    worker.use(
      http.get(configurationUrl(ORG_A), ({ request }) => {
        urls.push(new URL(request.url).pathname);
        return HttpResponse.json({ data: READY });
      }),
    );

    const screen = await renderScreen();
    await expect.element(screen.getByText('Current embedding pair')).toBeVisible();

    // Mounted under `organizations/{organization}`; `TenantContext` re-reads the membership row per
    // request and Laravel would ignore a client-supplied organization. The segment is a routing hint
    // — a foreign id 404s at binding time — and the SCOPE is the session.
    await vi.waitFor(() => {
      expect(urls).toEqual([`/api/v1/organizations/${ORG_A}/embedding-configuration`]);
    });
  });
});

describe('the verdict is the data plane’s own words, and only `ready` is branched on', () => {
  it('renders the ADR-031 explanation VERBATIM, whole, with the blocking treatment', async () => {
    worker.use(showHandler(AMBIGUOUS_VERDICT));

    const screen = await renderScreen();

    // BYTE FOR BYTE. Never paraphrased, never summarised, never composed from `rejected[]`.
    await expect.element(screen.getByText(AMBIGUOUS)).toBeVisible();

    // `blocks_ingestion: true` is the difference between "suboptimal" and "no document can be
    // ingested", and it has to be unmistakable: a title that says so, and the closed status
    // vocabulary's `failed` pill, which carries a glyph and a word as well as a colour.
    await expect.element(screen.getByText('No document can be ingested right now')).toBeVisible();
    await expect.element(screen.getByText('Ingestion blocked')).toBeVisible();
    // The consequence sentence is OURS and is the same on every blocked verdict — it never
    // substitutes for the explanation and never explains WHY.
    await expect.element(screen.getByText(/there is no degraded mode for it/)).toBeVisible();

    // Nothing resolved, so there is no pair to show.
    await expect.element(screen.getByText('Nothing is resolved. See the verdict above.')).toBeVisible();
  });

  it('shows the resolved pair and no blocking banner when ready', async () => {
    worker.use(showHandler(READY));

    const screen = await renderScreen();

    await expect.element(screen.getByText('Can embed')).toBeVisible();
    // Monospaced and verbatim: this pair IS the vector space, and an operator matches it character
    // by character against a vendor dashboard. It appears twice on the screen — in the selected card
    // and in the eligible list — so the assertion is on the RADIO's accessible name, which is unique.
    await expect
      .element(screen.getByLabelText('openai/text-embedding-3-large'))
      .toBeVisible();
    expect(document.body.textContent).toContain('openai/text-embedding-3-large');

    expect(screen.getByText('No document can be ingested right now').elements()).toHaveLength(0);
    expect(screen.getByText('Ingestion blocked').elements()).toHaveLength(0);
  });
});

/**
 * ── THE TWO STATES A NULL `selected` USED TO COLLAPSE INTO ONE ──────────────────────────────────
 * "You designated a pair and it is failing" and "you have never designated anything" both rendered
 * `selected: null` and the single line "Nothing is resolved. See the verdict above." The stored pair
 * reached the client only inside the free-text `explanation`, so telling them apart would have meant
 * parsing a paragraph the data plane owns and may reword.
 *
 * `EmbeddingReadinessResource.designated` is the field that makes it a branch. These specs assert
 * the two renderings DIFFER and that each names the operator's actual next action — which is the
 * whole reason the field was added, and is not provable by a type test.
 */
describe('`designated` tells the two null-`selected` states apart', () => {
  it('names the STORED pair when the designation no longer resolves', async () => {
    worker.use(showHandler(DESIGNATED_BUT_UNRESOLVED));

    const screen = await renderScreen();

    // The line about the RESOLVER is unchanged and still true — it is what the stored pair beside it
    // now qualifies.
    await expect
      .element(screen.getByText('Nothing is resolved. See the verdict above.'))
      .toBeVisible();

    // THE NEW CELL. The word carries the state as well as the colour does, so it survives greyscale
    // and reaches a screen reader that announces neither the background nor the glyph.
    await expect.element(screen.getByText('Designated model, no longer resolving')).toBeVisible();
    await expect.element(screen.getByText('Designated connection')).toBeVisible();

    // THE STORED PAIR, VERBATIM AND MONOSPACED: the operator is about to match these characters
    // against a `provider_models` row and a vendor dashboard.
    expect(screen.getByText('text-embedding-3-large').elements().length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText(CONNECTION_LARGE).elements().length).toBeGreaterThanOrEqual(1);

    // `EmbeddingDesignation` HAS NO `provider`. Rendering it through `describeCandidate` would need a
    // cast and would print `undefined/text-embedding-3-large`, on the one screen whose entire job is
    // to name the pair exactly.
    expect(document.body.textContent).not.toContain('undefined/');

    // The next action is to designate a DIFFERENT pair, not to choose for the first time.
    await expect.element(screen.getByText(/Designate a working pair below/)).toBeVisible();
    expect(document.body.textContent).not.toContain('there is no stored pair to repair');

    // …and the AUTHORITY is still the data plane's paragraph, verbatim, in the blocking banner. This
    // screen names the stored pair; it never explains why the pair stopped resolving.
    await expect.element(screen.getByText(DESIGNATION_UNRESOLVED_EXPLANATION)).toBeVisible();
  });

  it('says nothing is stored — and renders no stored pair — when there is no designation', async () => {
    // Byte-identical `selected: null`; the ONLY difference from the spec above is `designated`.
    worker.use(showHandler(AMBIGUOUS_VERDICT));

    const screen = await renderScreen();

    await expect
      .element(screen.getByText('Nothing is resolved. See the verdict above.'))
      .toBeVisible();
    await expect.element(screen.getByText(/there is no stored pair to repair/)).toBeVisible();

    // No stored pair means no cell for one — an empty labelled row would be a field with no value.
    expect(screen.getByText('Designated model, no longer resolving').elements()).toHaveLength(0);
    expect(screen.getByText('Designated connection').elements()).toHaveLength(0);
    expect(document.body.textContent).not.toContain('Designate a working pair below');
  });

  // TWO `it`s, AND THE FIRST EXPLANATION OF WHY WAS WRONG — the corrected one is worth the space
  // because it is the difference between a rule you can follow and a superstition.
  //
  // These two cases were written as ONE test that rendered, called `unmount()` without awaiting it,
  // and rendered again. It passed, and took the ELEVEN tests after it in this file down — every one
  // timing out at 15s against an empty `<body>`, which reads like a crash in the component and is
  // not one. This comment used to blame the second render and "state no `afterEach` reclaims".
  //
  // Both halves were wrong, and each is refuted by something in this repo:
  //   * `forgot-password-form.test.tsx:66-76` renders, awaits an unmount, and renders AGAIN inside
  //     one `it`, twice over, and is green. Two renders per test is not the hazard.
  //   * `vitest-browser-react` registers its `cleanup()` in `beforeEach`. There is no afterEach
  //     teardown for it to have missed.
  //
  // The measured cause is the MISSING `await`. `render`, `rerender` and `unmount` each wrap their
  // work in React's `act()` and return the promise for it; a second one starting while the first is
  // in flight corrupts React's act queue for the rest of the file. Same spec with
  // `await first.unmount()`: 4 of 4 pass in 2.5s. Same spec with the float and nothing after it:
  // green — so it is the overlap, not the float.
  //
  // The split is kept because two states deserve two names, not because one render per `it` is a
  // rule. The rule is: await every act-wrapping call. `tests/msw/setup.ts` now fails the test that
  // breaks it, and `eslint.config.mjs` catches the bare form before the suite runs.
  it('says a ready pair is pinned when it was designated explicitly', async () => {
    worker.use(showHandler(READY));
    const designated = await renderScreen();

    // Pinned explicitly: nothing is substituted for it, and the operator should know it is pinned.
    await expect.element(designated.getByText(/designated explicitly/)).toBeVisible();
    // The stored pair equals the resolved one on a ready verdict, so it is NOT repeated under a
    // second label — that would be the same pair twice, which reads as two configurations.
    expect(designated.getByText('Designated model, no longer resolving').elements()).toHaveLength(0);
  });

  it('says the same ready pair was resolved by rule when nothing is stored', async () => {
    // The same ready verdict with NOTHING stored is a genuinely more fragile state: one more
    // eligible connection naming a different pair turns it into the ADR-031 ambiguity with no edit
    // to this organization. Saying which one the operator is in is the point of the split.
    worker.use(showHandler({ ...READY, designated: null }));
    const byRule = await renderScreen();

    await expect.element(byRule.getByText(/this pair was resolved by rule/)).toBeVisible();
    expect(byRule.getByText(/designated explicitly/).elements()).toHaveLength(0);
  });
});

describe('a refused candidate is explained by its reason, with the detail as supplied', () => {
  /** All three members of the data plane's closed `EmbeddingIneligibility`, in one verdict. */
  const REJECTED: EmbeddingReadinessResource = {
    ready: true,
    blocks_ingestion: false,
    selected: LARGE,
    // Resolved BY RULE rather than designated — the other half of the ready verdict, so both ready
    // branches of `designationState` appear in this file against a real render.
    designated: null,
    eligible: [LARGE],
    // Listed even though the organization CAN embed, because an operator asking "why is my Anthropic
    // key not being used" needs the answer whether or not some other connection saved the day.
    rejected: [
      {
        connection_id: CONNECTION_CHAT,
        provider: 'anthropic',
        model: 'claude-opus-4-6',
        reason: 'vendor_has_no_endpoint',
        detail: 'PROVIDER_TASKS records anthropic as UNSUPPORTED on the embedding surface.',
      },
      {
        connection_id: CONNECTION_SMALL,
        provider: 'openai',
        model: 'gpt-5.6-sol',
        reason: 'row_lacks_embedding_flag',
        detail: 'capability_flags on this provider_models row does not include embedding.',
      },
      {
        connection_id: CONNECTION_LARGE,
        provider: 'openai',
        model: 'text-embedding-3-broken',
        reason: 'row_incoherent',
        detail: 'assert_row_coherent: the row claims both embedding and tool_use.',
      },
    ],
    explanation: '',
  };

  it('maps each closed reason to its sentence and renders the server detail verbatim', async () => {
    worker.use(showHandler(REJECTED));

    const screen = await renderScreen();

    await expect.element(screen.getByText(/This vendor publishes no embedding endpoint/)).toBeVisible();
    await expect.element(screen.getByText(/Add the embedding flag to it/)).toBeVisible();
    await expect.element(screen.getByText(/claims more than one task/)).toBeVisible();

    // `detail` is the data plane's own sentence quoting the capability-matrix cell. It renders as
    // supplied — a JSX child, never HTML.
    for (const rejection of REJECTED.rejected) {
      await expect.element(screen.getByText(rejection.detail)).toBeVisible();
      // The raw token too, so an operator can search for it.
      await expect.element(screen.getByText(rejection.reason)).toBeVisible();
    }
  });

  it('shows an unrecognised reason rather than inventing a sentence for it', async () => {
    // `reason` is typed `string` in @kb/contracts on purpose: the closed set is the DATA PLANE's and
    // neither Laravel nor that package validates it. A fourth member must reach the screen as
    // itself, beside the detail, not as a guess and not as silence.
    worker.use(
      showHandler({
        ...REJECTED,
        rejected: [
          {
            connection_id: CONNECTION_CHAT,
            provider: 'somevendor',
            model: 'embed-v9',
            reason: 'quota_exhausted',
            detail: 'A reason this build has never heard of.',
          },
        ],
      }),
    );

    const screen = await renderScreen();

    await expect.element(screen.getByText('quota_exhausted')).toBeVisible();
    await expect.element(screen.getByText('A reason this build has never heard of.')).toBeVisible();
  });

  it('renders the FIRST-RUN empty state when nothing was even examined', async () => {
    // Different from "everything was refused": no connection carries a model row, so there was
    // nothing to accept or refuse. There is deliberately no FILTERED empty state on this surface —
    // it has no filters, and the two are different components on purpose.
    worker.use(
      showHandler({
        ready: false,
        blocks_ingestion: true,
        selected: null,
        designated: null,
        eligible: [],
        rejected: [],
        explanation: 'This organization has no embedding-capable provider connection.',
      }),
    );

    const screen = await renderScreen();

    await expect.element(screen.getByText('No candidates were examined')).toBeVisible();
    expect(screen.getByText('No matches').elements()).toHaveLength(0);
  });
});

describe('the designation is submitted as a PAIR, and cleared as a pair', () => {
  it('posts both halves and nothing else — never the readiness object it was seeded from', async () => {
    const bodies: unknown[] = [];
    const saved: EmbeddingReadinessResource = { ...READY, selected: SMALL, eligible: [LARGE, SMALL] };
    worker.use(
      // STATEFUL, because `onSettled` invalidates and the panel RE-READS rather than being patched —
      // a handler that kept answering the pre-save verdict would make this spec pass against a UI
      // that had written the cache itself.
      http.get(configurationUrl(ORG_A), () =>
        HttpResponse.json({ data: bodies.length === 0 ? AMBIGUOUS_VERDICT : saved }),
      ),
      http.put(configurationUrl(ORG_A), async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ data: saved });
      }),
    );

    const screen = await renderScreen();

    // Nothing resolves, so the group starts with NOTHING checked and the submit is disabled until a
    // choice is made.
    await expect.element(screen.getByLabelText('openai/text-embedding-3-small')).toBeVisible();
    await screen.getByLabelText('openai/text-embedding-3-small').click();
    await screen.getByRole('button', { name: 'Save designation', exact: true }).click();

    await vi.waitFor(() => {
      expect(bodies).toEqual([
        { connection_id: CONNECTION_SMALL, model: 'text-embedding-3-small' },
      ]);
    });

    // THE FORM IS SEEDED BY A TWO-FIELD FACTORY, not by `reset(response)`. `reset()` keeps every key
    // it is handed and submit posts them back, so a spread of the readiness object would put these
    // four in the body against a `strictObject`.
    const body = JSON.stringify(bodies[0]);
    expect(Object.keys(bodies[0] as object).sort()).toEqual(['connection_id', 'model']);
    expect(body).not.toContain('eligible');
    expect(body).not.toContain('rejected');
    expect(body).not.toContain('explanation');
    expect(body).not.toContain('blocks_ingestion');
    // An ownership column in a request body is an authorization bug with a 200 response.
    expect(body).not.toContain('organization_id');

    await expect.element(screen.getByText(/Designated openai\/text-embedding-3-small/)).toBeVisible();
  });

  it('clears the designation with two nulls, through the same endpoint', async () => {
    const bodies: unknown[] = [];
    worker.use(
      showHandler(READY),
      http.put(configurationUrl(ORG_A), async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({
          data: { ...AMBIGUOUS_VERDICT, eligible: [LARGE], explanation: 'Nothing designated.' },
        });
      }),
    );

    const screen = await renderScreen();

    await screen.getByLabelText('No designation').click();
    await screen.getByRole('button', { name: 'Save designation', exact: true }).click();

    await vi.waitFor(() => {
      // BOTH KEYS PRESENT AND BOTH NULL. The rule is `present|nullable`, so an omitted key is a 422,
      // and half a designation is refused by a database CHECK — which is why there is one control
      // writing both and no UI that can express one half.
      expect(bodies).toEqual([{ connection_id: null, model: null }]);
    });

    await expect.element(screen.getByText(/The designation is cleared/)).toBeVisible();
  });

  it('offers no save until the choice differs from what is stored', async () => {
    worker.use(showHandler(READY));

    const screen = await renderScreen();

    // The seeded pair is checked and the submit is disabled: a PUT that changes nothing still costs
    // a write and an audit row.
    await expect
      .element(screen.getByRole('button', { name: 'Save designation', exact: true }))
      .toBeDisabled();
    await expect.element(screen.getByText('This is what is stored. Choose a different option to save.')).toBeVisible();
  });
});

describe('the ADR-031 refusal is surfaced as guidance with a next action', () => {
  it('renders the 422 paragraph verbatim, never the class-mapped sentence, never a field error', async () => {
    worker.use(
      showHandler(AMBIGUOUS_VERDICT),
      http.put(configurationUrl(ORG_A), () =>
        HttpResponse.json(
          // THE RESOLVER'S REFUSAL. `KbException::validation($readiness->explanation)` is a
          // RuntimeException rather than a ValidationException, so `bootstrap/app.php` attaches NO
          // `errors` key — `validation` with no map is, by construction, a deliberate refusal our own
          // code raised with a sentence written for a person. `envelope()` builds exactly that shape.
          envelope('validation', { message: AMBIGUOUS, retryable: false }),
          { status: 422 },
        ),
      ),
    );

    const screen = await renderScreen();

    await screen.getByLabelText('openai/text-embedding-3-large').click();
    await screen.getByRole('button', { name: 'Save designation', exact: true }).click();

    await expect.element(screen.getByText('That designation was refused')).toBeVisible();
    // The whole paragraph. It appears twice on this screen — once as the GET's `explanation` and once
    // as the 422's message — which is the point: they are the same string by construction, and the
    // count assertion is what would fail if either side started paraphrasing.
    await vi.waitFor(() => {
      expect(screen.getByText(AMBIGUOUS).elements().length).toBeGreaterThanOrEqual(2);
    });

    // NOT the class-mapped copy: `ERROR_COPY.validation` says nothing about which pair, why, or what
    // to do, and there is no field it could be pointing at.
    expect(document.body.textContent).not.toContain('Some details need fixing before this can be saved');
    // …and NOT the operator-facing envelope text from any other path.
    expect(document.body.textContent).not.toContain('api-7.internal');

    // THE NEXT ACTION, which is the whole difference between guidance and a raw 422 blob.
    await expect.element(screen.getByText(/Next: designate one of the eligible pairs below/)).toBeVisible();
    await expect.element(screen.getByRole('button', { name: 'Choose a pair' })).toBeVisible();
  });

  it('keeps the verdict beside the refusal, and clears the refusal on the next choice', async () => {
    // The second refusal shape: `_designation_failed`, raised when the pair NAMED cannot embed —
    // the model row lost its flag between the read and the save. A designation is NEVER substituted,
    // so the server refuses rather than quietly selecting the other eligible pair.
    const DESIGNATION_FAILED =
      `The designated embedding connection ${CONNECTION_LARGE} -> text-embedding-3-large cannot ` +
      'embed: row_lacks_embedding_flag (capability_flags on this provider_models row does not ' +
      'include embedding). It is not substituted — silently embedding through a different ' +
      'connection would change the vector space under a corpus nobody reindexed.';

    worker.use(
      showHandler(AMBIGUOUS_VERDICT),
      http.put(configurationUrl(ORG_A), () =>
        HttpResponse.json(envelope('validation', { message: DESIGNATION_FAILED, retryable: false }), {
          status: 422,
        }),
      ),
    );

    const screen = await renderScreen();

    await screen.getByLabelText('openai/text-embedding-3-large').click();
    await screen.getByRole('button', { name: 'Save designation', exact: true }).click();

    await expect.element(screen.getByText(DESIGNATION_FAILED)).toBeVisible();
    // THE VERDICT ABOVE IS UNTOUCHED. The refusal is about the CHOICE; whether the organization can
    // ingest at all is the panel's, and one page must not carry two banners disagreeing about it.
    await expect.element(screen.getByText(AMBIGUOUS)).toBeVisible();
    await expect.element(screen.getByText('No document can be ingested right now')).toBeVisible();

    // It is guidance, not a field error: neither radio is marked invalid, and nothing was written to
    // a control the operator would have to hunt for.
    await expect
      .element(screen.getByLabelText('openai/text-embedding-3-large'))
      .not.toHaveAttribute('aria-invalid', 'true');

    // Choosing again is the next action, so making it clears the stale refusal rather than leaving
    // the operator to read a rejection of a choice they have already changed.
    await screen.getByLabelText('openai/text-embedding-3-small').click();
    await vi.waitFor(() => {
      expect(screen.getByText('That designation was refused').elements()).toHaveLength(0);
    });
  });
});

describe('the role split is wider on the read than on the write', () => {
  it('shows an analyst the class-mapped 403 and no form at all', async () => {
    worker.use(
      // An ANALYST holds nothing in this catalog (§6.4) and is refused both endpoints.
      http.get(`${ORIGIN}/api/v1/me`, () =>
        HttpResponse.json({
          data: sessionFixture({
            organizations: [
              { id: ORG_A, name: 'Acme Research', slug: 'acme', role: 'analyst', status: 'active' },
              { id: ORG_B, name: 'Brightwater Legal', slug: 'bw', role: 'owner', status: 'active' },
            ],
          }),
        }),
      ),
      http.get(configurationUrl(ORG_A), () =>
        HttpResponse.json(envelope('authorization'), { status: 403 }),
      ),
    );

    const screen = await renderScreen();

    await expect.element(screen.getByText('Embedding readiness could not be loaded')).toBeVisible();
    await expect.element(screen.getByText('You do not have access to this.')).toBeVisible();
    // `authorization` is not retryable by class — a 403 is an ANSWER, not a failure to reattempt.
    expect(screen.getByRole('button', { name: 'Try again' }).elements()).toHaveLength(0);
    // The form is not mounted on the error path: a designation form for a verdict that failed to
    // load is a form whose every submit is the same 403.
    expect(
      screen.getByRole('button', { name: 'Save designation', exact: true }).elements(),
    ).toHaveLength(0);
    // The envelope's `message` is operator-facing and reaches no rendered string.
    expect(document.body.textContent).not.toContain('api-7.internal');
  });

  it('lets a knowledge manager read the verdict and offers them no controls', async () => {
    // `EmbeddingConfigurationTest` pins exactly this: 200 on the GET, 403 on the PUT. An ingestion
    // operator whose upload is blocked has to be able to see WHY.
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () =>
        HttpResponse.json({
          data: sessionFixture({
            organizations: [
              {
                id: ORG_A,
                name: 'Acme Research',
                slug: 'acme',
                role: 'knowledge_manager',
                status: 'active',
              },
            ],
          }),
        }),
      ),
      showHandler(AMBIGUOUS_VERDICT),
    );

    const screen = await renderScreen();

    // The whole reason they are allowed to read it.
    await expect.element(screen.getByText(AMBIGUOUS)).toBeVisible();
    await expect
      .element(screen.getByText(/Changing which connection embeds is an owner or admin action/))
      .toBeVisible();
    expect(
      screen.getByRole('button', { name: 'Save designation', exact: true }).elements(),
    ).toHaveLength(0);
    // NOT the forbidden state: nothing was refused, and a lock icon over a fully rendered verdict
    // would say the opposite of what happened.
    expect(screen.getByText("You don't have access to this").elements()).toHaveLength(0);
  });
});

describe('with no current organization it makes no org-scoped request at all', () => {
  it('says so, and builds no key from a null organization', async () => {
    // `useOrgKey()` THROWS on a null organization rather than returning `['org', undefined, …]`,
    // which would be ONE shared cache namespace for every org-less state on the platform. The gate is
    // expressed as a MOUNT condition, so the throw is unreachable rather than merely unlikely.
    const requests: string[] = [];
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () =>
        HttpResponse.json({
          data: sessionFixture({
            current_organization_id: null,
            organizations: [
              {
                id: ORG_B,
                name: 'Brightwater Legal',
                slug: 'bw',
                role: 'analyst',
                status: 'invited',
              },
            ],
          }),
        }),
      ),
      http.get(configurationUrl(ORG_A), () => {
        requests.push('embedding-configuration');
        return HttpResponse.json({ data: READY });
      }),
    );

    const screen = await renderScreen();

    await expect.element(screen.getByText('No organization selected')).toBeVisible();
    expect(requests).toEqual([]);
    expect(cacheKeys().filter((key) => key[0] === 'org')).toEqual([]);
  });
});
