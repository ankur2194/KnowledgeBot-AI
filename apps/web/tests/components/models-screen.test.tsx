import type { QueryClient } from '@tanstack/react-query';
import { useQueryClient } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { useEffect } from 'react';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Providers } from '@/components/providers';
import { SessionProvider } from '@/features/auth/session-provider';
import type { ProviderConnectionResource, ProviderModelResource } from '@kb/contracts';
import { ModelsScreen } from '@/features/models/models-screen';

import { envelope, ORIGIN, sessionFixture } from '../msw/handlers';
import { worker } from '../msw/setup';

/**
 * `/settings/providers/[connectionId]` — one connection's model catalogue.
 *
 * ── WHAT THIS SPEC MAY NOT CLAIM ─────────────────────────────────────────────────────────────────
 * It proves that both query keys are org-namespaced AND connection-scoped, and that both segments
 * reach the request path. It proves NOTHING about isolation: a component test that mocks the API
 * cannot fail an isolation test, and per the vitest-playwright boundary table it must never be cited
 * as isolation coverage. "Organization A's catalogue never appears after switching to B" is
 * Playwright's, and is recorded as unproven by this step.
 *
 * ── THE HANDLERS FOR THESE ENDPOINTS LIVE HERE, NOT IN tests/msw/handlers.ts ─────────────────────
 * That file is shared by every component spec; `worker.use(...)` inside a spec is the documented
 * override mechanism and `afterEach`'s `resetHandlers()` keeps it from leaking into the next file.
 *
 * EVERY SPEC INSTALLS BOTH GETs. The screen reads the parent connection AND its catalogue, and the
 * harness runs with `onUnhandledRequest: 'error'` — which under the service-worker transport answers
 * `500 Request Handler Error` rather than rejecting, so a missing handler surfaces as an error state
 * the spec did not ask for rather than as a network failure.
 */

/** `sessionFixture()`'s current organization, and the namespace every key below must carry. */
const ORG_A = '01JORGAAAAAAAAAAAAAAAAAAAA';
/** The other ACTIVE membership in the same fixture. Present so the cache-key assertion is about the
 *  CURRENT organization rather than about the only one there is — a one-organization fixture cannot
 *  fail a namespacing test. */
const ORG_B = '01JORGBBBBBBBBBBBBBBBBBBBB';

/** The connection whose catalogue this screen renders. It is a ROUTE PARAM, so it is in the URL —
 *  which is exactly why the query keys have to carry it as well as the organization: two connections
 *  of the SAME organization must not share a cache entry. */
const CONNECTION = '01JCONNAAAAAAAAAAAAAAAAAAA';
/** A second connection of the same organization, used to prove the key is connection-scoped. */
const OTHER_CONNECTION = '01JCONNBBBBBBBBBBBBBBBBBBB';

const CONNECTION_ROW: ProviderConnectionResource = {
  id: CONNECTION,
  provider: 'openai',
  label: 'Primary OpenAI key',
  // `…` (U+2026) plus the last four. A DISPLAY STRING, rendered as text in the header card; there is
  // no input anywhere on this screen that could receive it.
  masked_key: '…4a91',
  status: 'active',
  created_at: '2026-08-01T09:00:00+00:00',
};

/**
 * A chat row with BOTH prices and a currency, at the scale the column casts to.
 *
 * `'1.250000'` and not `'1.25'`: the cast is `decimal:6` over `numeric(14, 6)`, so this is what the
 * wire really carries — and it is the value the PUT below must re-send BYTE FOR BYTE.
 */
const CHAT_MODEL: ProviderModelResource = {
  id: '01JMODELAAAAAAAAAAAAAAAAAA',
  connection_id: CONNECTION,
  model: 'gpt-5.6-sol',
  display_name: 'GPT-5.6 Sol',
  supported: ['text', 'tool_use'],
  context_window: 400_000,
  max_output_tokens: 128_000,
  enabled: true,
  input_price_per_million: '1.250000',
  output_price_per_million: '10.000000',
  price_currency: 'USD',
  created_at: '2026-08-02T09:00:00+00:00',
};

/** A DISABLED row, listed on purpose: an operator asking "why is this model missing from the bot's
 *  dropdown" has to be able to find it. Also unpriced, which is not the same as free. */
const DISABLED_MODEL: ProviderModelResource = {
  ...CHAT_MODEL,
  id: '01JMODELBBBBBBBBBBBBBBBBBB',
  model: 'text-embedding-3-large',
  display_name: 'Embedding 3 Large',
  supported: ['embedding', 'embedding_dimensions'],
  enabled: false,
  input_price_per_million: null,
  output_price_per_million: null,
  price_currency: null,
};

/** A row carrying a flag this build has never heard of. The vocabulary is the DATA PLANE's and the
 *  wire type is an open string array, so this is a legal row and the console must not drop the flag. */
const FUTURE_MODEL: ProviderModelResource = {
  ...CHAT_MODEL,
  id: '01JMODELCCCCCCCCCCCCCCCCCC',
  model: 'gpt-5.7-preview',
  display_name: 'GPT-5.7 Preview',
  supported: ['text', 'speculative_decoding'],
};

/**
 * A row carrying one flag the data plane REFUSES beside a task flag (`text`) and one it PERMITS
 * (`stream_usage`). The two live in the same `chat` FAMILY in this console and are treated
 * differently on a task change, which is the distinction this fixture exists to make testable.
 *
 * `assert_row_coherent` forbids `embedding`/`rerank` beside `{TEXT, TOOL_USE, REASONING}` and nothing
 * else — `stream_usage` on an embedding row is a true statement about a provider that reports usage on
 * an embedding response. The console used to strip it anyway, which is a client stricter than the
 * server removing functionality with nothing reported.
 */
const MIXED_MODEL: ProviderModelResource = {
  ...CHAT_MODEL,
  id: '01JMODELDDDDDDDDDDDDDDDDDD',
  model: 'gpt-5.6-mixed',
  display_name: 'GPT-5.6 Mixed',
  supported: ['text', 'stream_usage', 'speculative_decoding'],
};

const connectionUrl = (orgId: string, connectionId: string) =>
  `${ORIGIN}/api/v1/organizations/${orgId}/provider-connections/${connectionId}`;

const modelsUrl = (orgId: string, connectionId: string) =>
  `${connectionUrl(orgId, connectionId)}/models`;

const connectionHandler = (row: ProviderConnectionResource = CONNECTION_ROW) =>
  http.get(connectionUrl(ORG_A, CONNECTION), () => HttpResponse.json({ data: row }));

/** The list body nests the array under a NAMED KEY — `{"data":{"models":[…]}}` — because
 *  `ResponseShape` cannot express "an array of" for a response key and every published component must
 *  be `additionalProperties: false`. A fixture returning `{"data":[…]}` would make these specs pass
 *  against a shape the server never sends. */
const listHandler = (models: readonly ProviderModelResource[] = [CHAT_MODEL]) =>
  http.get(modelsUrl(ORG_A, CONNECTION), () => HttpResponse.json({ data: { models } }));

/** Captures the tree's QueryClient so the KEYS themselves can be asserted, not just the request URLs.
 *  The organization is not in the URL of this screen — the CONNECTION is, which is the trap — so the
 *  key is the artifact worth reading. The write happens in an EFFECT and not during render:
 *  `react-hooks/globals` is an ERROR on reassigning a module-scope variable from a render body. */
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
        <ModelsScreen connectionId={CONNECTION} />
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

describe('both keys carry the organization AND the connection', () => {
  it('namespaces by org and scopes by connection, and never builds a bare key', async () => {
    worker.use(connectionHandler(), listHandler());

    const screen = await renderScreen();
    // THE IDENTIFIER CELL AND NOT THE DISPLAY NAME, and the reason is the accessible-name namespace:
    // a cell's name is computed from everything inside it, and this row's ACTIONS cell contains
    // buttons named "Edit GPT-5.6 Sol" and "Delete GPT-5.6 Sol" — so a cell query for the display
    // name resolves to two cells and fails on strict mode. `gpt-5.6-sol` appears only in the model
    // cell: the hyphen is what makes it distinct from "GPT-5.6 Sol" under case-insensitive matching.
    await expect.element(screen.getByRole('cell', { name: 'gpt-5.6-sol' })).toBeVisible();

    await vi.waitFor(() => {
      expect(cacheKeys()).toContainEqual(['org', ORG_A, 'provider-connections', CONNECTION]);
      expect(cacheKeys()).toContainEqual([
        'org',
        ORG_A,
        'provider-connections',
        CONNECTION,
        'models',
      ]);
    });

    const keys = cacheKeys();
    // `['session']` is the ONE legal exception to the org prefix — it is the PRODUCER of `orgId`, and
    // namespacing the identity document by the organization it announces would be circular.
    expect(keys.filter((key) => key[0] !== 'org')).toEqual([['session']]);
    // Not one key mentions the organization the session is NOT currently acting in…
    expect(JSON.stringify(keys)).not.toContain(ORG_B);
    // …and not one mentions a connection this screen is not showing. Dropping the connection segment
    // would make two credentials of the SAME organization share one cache entry, and the symptom
    // would be a correctly rendered catalogue belonging to the other one.
    expect(JSON.stringify(keys)).not.toContain(OTHER_CONNECTION);
  });

  it('addresses both segments in the PATH too, which are routing hints and not the scope', async () => {
    const urls: string[] = [];
    worker.use(
      http.get(connectionUrl(ORG_A, CONNECTION), ({ request }) => {
        urls.push(new URL(request.url).pathname);
        return HttpResponse.json({ data: CONNECTION_ROW });
      }),
      http.get(modelsUrl(ORG_A, CONNECTION), ({ request }) => {
        urls.push(new URL(request.url).pathname);
        return HttpResponse.json({ data: { models: [CHAT_MODEL] } });
      }),
    );

    const screen = await renderScreen();
    await expect.element(screen.getByRole('cell', { name: 'gpt-5.6-sol' })).toBeVisible();

    // Mounted under `organizations/{organization}/provider-connections/{providerConnection}` with
    // `->scopeBindings()`; `TenantContext` re-reads the membership row per request. Both segments are
    // routing hints — a foreign organization, a foreign connection, or a model under a DIFFERENT
    // connection of the same organization all 404 at BINDING time — and the SCOPE is the session.
    await vi.waitFor(() => {
      expect(urls).toEqual([
        `/api/v1/organizations/${ORG_A}/provider-connections/${CONNECTION}`,
        `/api/v1/organizations/${ORG_A}/provider-connections/${CONNECTION}/models`,
      ]);
    });
  });
});

describe('the list renders what the server derived', () => {
  it('shows disabled rows too, and says when a price is not recorded', async () => {
    worker.use(connectionHandler(), listHandler([CHAT_MODEL, DISABLED_MODEL]));

    const screen = await renderScreen();

    // Scoped to the TABLE by `getByRole`, which reads the accessibility tree: below 768px the same
    // rows render as a stack of cards (P6), and `display: none` removes whichever layout the
    // viewport is not using — so each model is announced once.
    await expect.element(screen.getByRole('cell', { name: 'gpt-5.6-sol' })).toBeVisible();
    await expect
      .element(screen.getByRole('cell', { name: 'text-embedding-3-large' }))
      .toBeVisible();

    // A disabled row keeps its switch, in the OFF state, rather than disappearing.
    await expect
      .element(screen.getByRole('switch', { name: 'Available to bots: Embedding 3 Large' }))
      .not.toBeChecked();

    // The trailing zeros of the `decimal:6` cast are trimmed by string surgery — never by a
    // `Number()` round-trip, which is the first step of re-scaling the value on the way back.
    expect(document.body.textContent).toContain('1.25 USD');
    expect(document.body.textContent).toContain('10 USD');
    expect(document.body.textContent).not.toContain('1.250000');
    // A null price is an em dash and NOT "free" or "0": null means no price has been recorded.
    expect(document.body.textContent).toContain('—');
    // A token count is GROUPED, in the viewer's locale — so the separator is not asserted, only that
    // one is there. (0 would render as "Not recorded" rather than as a bare zero, which reads as a
    // limit of nothing; no row in this fixture carries one.)
    expect(document.body.textContent).toMatch(/400.000/);
  });

  it('renders a capability flag it has never heard of, verbatim', async () => {
    worker.use(connectionHandler(), listHandler([FUTURE_MODEL]));

    const screen = await renderScreen();
    await expect.element(screen.getByRole('cell', { name: 'gpt-5.7-preview' })).toBeVisible();

    // The vocabulary is the data plane's `Capability` enum; the control plane validates
    // `string|max:64` and publishes no OpenAPI enum. A console that hid a flag it could not name
    // would make the row unreadable in exactly the case where somebody needs to read it.
    expect(document.body.textContent).toContain('speculative_decoding');
    // …and the ones it does know are shown by their words rather than by their wire value.
    expect(document.body.textContent).toContain('Text generation');
  });

  it('renders the first-run empty state rather than a table with no body', async () => {
    worker.use(connectionHandler(), listHandler([]));

    const screen = await renderScreen();

    // FIRST-RUN empty, not filtered empty: this list has no filters, and the two are different
    // components on purpose (components/states.tsx).
    await expect
      .element(screen.getByText('No models registered on this connection'))
      .toBeVisible();
    expect(screen.getByText('No matches').elements()).toHaveLength(0);
    // The primary action is in the section header, ONCE. A second control with the same accessible
    // name would resolve to two elements for a locator and be two indistinguishable choices for a
    // screen-reader user.
    expect(screen.getByRole('button', { name: 'Register model' }).elements()).toHaveLength(1);
  });
});

describe('the enabled toggle sends the WHOLE row, because the endpoint is a PUT', () => {
  it('re-sends all eight attributes with the prices byte for byte', async () => {
    const bodies: unknown[] = [];
    let reads = 0;
    worker.use(
      connectionHandler(),
      http.get(modelsUrl(ORG_A, CONNECTION), () => {
        reads += 1;
        return HttpResponse.json({
          data: { models: [reads === 1 ? CHAT_MODEL : { ...CHAT_MODEL, enabled: false }] },
        });
      }),
      http.put(`${modelsUrl(ORG_A, CONNECTION)}/${CHAT_MODEL.id}`, async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ data: { ...CHAT_MODEL, enabled: false } });
      }),
    );

    const screen = await renderScreen();
    await screen.getByRole('switch', { name: `Available to bots: ${CHAT_MODEL.display_name}` }).click();

    await vi.waitFor(() => {
      // ALL EIGHT MUTABLE ATTRIBUTES. `UpdateProviderModelRequest` rules every field `required` or
      // `present`, so a body carrying only `{enabled}` is a 422 on seven fields the operator never
      // saw. `providerModelEditDefaults(row)` is what makes that structural rather than remembered.
      expect(bodies).toEqual([
        {
          display_name: 'GPT-5.6 Sol',
          supported: ['text', 'tool_use'],
          context_window: 400_000,
          max_output_tokens: 128_000,
          enabled: false,
          // BYTE FOR BYTE. A `Number()` round-trip would send `1.25`, which is the same amount and a
          // different string — in an audit row recording a price change nobody made.
          input_price_per_million: '1.250000',
          output_price_per_million: '10.000000',
          price_currency: 'USD',
        },
      ]);
    });

    // NO `model`, NO `id`, NO `connection_id`, NO `created_at`: the identifier is immutable and the
    // rest are ownership-adjacent columns a client may never submit.
    expect(JSON.stringify(bodies)).not.toContain('gpt-5.6-sol');
    expect(JSON.stringify(bodies)).not.toContain('connection_id');

    // NOT OPTIMISTIC: the switch follows the server's answer through an invalidation, so what is on
    // screen is what is stored.
    await expect
      .element(screen.getByRole('switch', { name: `Available to bots: ${CHAT_MODEL.display_name}` }))
      .not.toBeChecked();
  });

  it('renders a class-mapped sentence when the toggle is refused, and never the envelope message', async () => {
    worker.use(
      connectionHandler(),
      listHandler(),
      http.put(`${modelsUrl(ORG_A, CONNECTION)}/${CHAT_MODEL.id}`, () =>
        HttpResponse.json(envelope('authorization'), { status: 403 }),
      ),
    );

    const screen = await renderScreen();
    await screen.getByRole('switch', { name: `Available to bots: ${CHAT_MODEL.display_name}` }).click();

    await expect.element(screen.getByText('You do not have access to this.')).toBeVisible();
    // The envelope's `message` is operator-facing — it can carry an internal hostname or raw text
    // from an upstream provider — and reaches no rendered string.
    expect(document.body.textContent).not.toContain('api-7.internal');
  });
});

describe('registering a model', () => {
  it('posts the body the schema produced, with the prices as the operator typed them', async () => {
    const bodies: unknown[] = [];
    worker.use(
      connectionHandler(),
      listHandler([]),
      http.post(modelsUrl(ORG_A, CONNECTION), async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ data: CHAT_MODEL }, { status: 201 });
      }),
    );

    const screen = await renderScreen();
    await screen.getByRole('button', { name: 'Register model' }).click();

    // SCOPED TO THE DIALOG for every field below. The create and edit forms render the SAME eight
    // labelled controls — one component, so they cannot drift by a word — and only one is ever
    // mounted, which is what keeps the accessible names unambiguous. Scoping here says so out loud.
    const dialog = screen.getByRole('dialog');
    await expect.element(screen.getByRole('heading', { name: 'Register a model' })).toBeVisible();

    await dialog.getByLabelText('Model identifier').fill('gpt-5.6-sol');
    await dialog.getByLabelText('Display name').fill('GPT-5.6 Sol');
    await dialog.getByLabelText('Text generation').click();
    await dialog.getByLabelText('Context window').fill('400000');
    await dialog.getByLabelText('Max output tokens').fill('128000');
    await dialog.getByLabelText('Input price').fill('1.25');
    await dialog.getByLabelText('Output price').fill('10');
    await dialog.getByLabelText('Currency').fill('USD');
    await dialog.getByRole('button', { name: 'Save model' }).click();

    await vi.waitFor(() => {
      // NO `organization_id` AND NO `connection_id`: both are URL segments resolved by route
      // binding, and a body field would be a tenant key the caller can set — an authorization bug
      // with a 201. The schema is a `strictObject` and could not express either.
      expect(bodies).toEqual([
        {
          model: 'gpt-5.6-sol',
          display_name: 'GPT-5.6 Sol',
          supported: ['text'],
          context_window: 400_000,
          max_output_tokens: 128_000,
          enabled: true,
          // WHAT THE OPERATOR TYPED, not a float and not the column's scale. The server answers
          // `'1.250000'`; this is the request.
          input_price_per_million: '1.25',
          output_price_per_million: '10',
          price_currency: 'USD',
        },
      ]);
    });

    // The dialog closes and the receipt stays on screen, in a polite live region — the table below
    // is not a live region and its new row is otherwise silent.
    await expect.element(screen.getByText(/Registered gpt-5.6-sol/)).toBeVisible();
  });

  it('refuses a price with no currency BEFORE any request is made', async () => {
    const posts: unknown[] = [];
    worker.use(
      connectionHandler(),
      listHandler([]),
      http.post(modelsUrl(ORG_A, CONNECTION), async ({ request }) => {
        posts.push(await request.json());
        return HttpResponse.json({ data: CHAT_MODEL }, { status: 201 });
      }),
    );

    const screen = await renderScreen();
    await screen.getByRole('button', { name: 'Register model' }).click();

    const dialog = screen.getByRole('dialog');
    await dialog.getByLabelText('Model identifier').fill('gpt-5.6-sol');
    await dialog.getByLabelText('Display name').fill('GPT-5.6 Sol');
    await dialog.getByLabelText('Input price').fill('1.25');
    // Currency deliberately left empty. The server would refuse this with `required_with`, and the
    // database repeats it as `provider_models_price_needs_currency` — two organizations billed in
    // different currencies would both store `1.25`, and a spend estimate would add them.
    await dialog.getByRole('button', { name: 'Save model' }).click();

    await expect.element(screen.getByText(/A price needs a currency/)).toBeVisible();
    // The client check is a UX AFFORDANCE and the FormRequest is still the authority — but a refusal
    // the client can make locally should not cost a round trip.
    expect(posts).toEqual([]);

    // …and a currency ON ITS OWN is legal, which is the half a symmetric rule would get wrong: it is
    // the state of an operator who recorded the billing currency before looking the prices up.
    await dialog.getByLabelText('Input price').fill('');
    await dialog.getByLabelText('Currency').fill('EUR');
    await dialog.getByRole('button', { name: 'Save model' }).click();
    await vi.waitFor(() => {
      expect(posts).toHaveLength(1);
    });
  });

  it('lands a 422 on the field that owns it, and an element error on the banner', async () => {
    worker.use(
      connectionHandler(),
      listHandler([]),
      http.post(modelsUrl(ORG_A, CONNECTION), () =>
        HttpResponse.json(
          envelope('validation', {
            errors: {
              model: ['A model with this identifier is already registered on this connection.'],
            },
          }),
          { status: 422 },
        ),
      ),
    );

    const screen = await renderScreen();
    await screen.getByRole('button', { name: 'Register model' }).click();

    const dialog = screen.getByRole('dialog');
    await dialog.getByLabelText('Model identifier').fill('gpt-5.6-sol');
    await dialog.getByLabelText('Display name').fill('GPT-5.6 Sol');
    await dialog.getByRole('button', { name: 'Save model' }).click();

    // A DUPLICATE IS A 422 KEYED ON `model` — an org-scoped pre-flight for the readable message plus
    // a catch of SQLSTATE 23505 for the race it cannot win. Validation MESSAGES are
    // Laravel-translated end-user copy and are shown verbatim; the envelope's `message` never is.
    await expect
      .element(
        screen.getByText('A model with this identifier is already registered on this connection.'),
      )
      .toBeVisible();
    await expect
      .element(dialog.getByLabelText('Model identifier'))
      .toHaveAttribute('aria-invalid', 'true');
    expect(document.body.textContent).not.toContain('api-7.internal');
  });

  it('routes a 422 on `supported.*` to the banner, because no control has that name', async () => {
    worker.use(
      connectionHandler(),
      listHandler([]),
      http.post(modelsUrl(ORG_A, CONNECTION), () =>
        HttpResponse.json(
          envelope('validation', {
            errors: { 'supported.0': ['The supported.0 field must be a string.'] },
          }),
          { status: 422 },
        ),
      ),
    );

    const screen = await renderScreen();
    await screen.getByRole('button', { name: 'Register model' }).click();

    const dialog = screen.getByRole('dialog');
    await dialog.getByLabelText('Model identifier').fill('gpt-5.6-sol');
    await dialog.getByLabelText('Display name').fill('GPT-5.6 Sol');
    await dialog.getByRole('button', { name: 'Save model' }).click();

    // `PROVIDER_MODEL_KNOWN_PATHS` subtracts `supported.*`, so this is an orphan and reaches
    // `root.serverError`. Written to `supported.0` instead it would display NOWHERE: the capability
    // group is one Controller over `supported` and `<FormMessage>` reads the error at its OWN name.
    await expect
      .element(screen.getByText('The supported.0 field must be a string.'))
      .toBeVisible();
  });
});

describe('editing a model', () => {
  it('seeds every control from the row and never offers the identifier as an input', async () => {
    const bodies: unknown[] = [];
    worker.use(
      connectionHandler(),
      listHandler(),
      http.put(`${modelsUrl(ORG_A, CONNECTION)}/${CHAT_MODEL.id}`, async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ data: { ...CHAT_MODEL, display_name: 'Renamed' } });
      }),
    );

    const screen = await renderScreen();
    await screen.getByRole('button', { name: `Edit ${CHAT_MODEL.display_name}` }).click();

    const dialog = screen.getByRole('dialog');
    await expect.element(screen.getByRole('heading', { name: 'Edit model' })).toBeVisible();
    // THE IDENTIFIER IS TEXT, NOT A CONTROL. It is not in the schema, not in the defaults factory and
    // not in the request body: it is half of the vector-space identity for everything already
    // embedded through this row.
    expect(dialog.getByLabelText('Model identifier').elements()).toHaveLength(0);
    expect(dialog.elements()[0]?.textContent).toContain('gpt-5.6-sol');

    await expect.element(dialog.getByLabelText('Display name')).toHaveValue('GPT-5.6 Sol');
    // The price is seeded at the COLUMN's scale, exactly as it arrived, so an edit that does not
    // touch it re-sends the identical string.
    await expect.element(dialog.getByLabelText('Input price')).toHaveValue('1.250000');

    await dialog.getByLabelText('Display name').fill('Renamed');
    await dialog.getByRole('button', { name: 'Save changes' }).click();

    await vi.waitFor(() => {
      expect(bodies).toEqual([
        {
          display_name: 'Renamed',
          supported: ['text', 'tool_use'],
          context_window: 400_000,
          max_output_tokens: 128_000,
          enabled: true,
          input_price_per_million: '1.250000',
          output_price_per_million: '10.000000',
          price_currency: 'USD',
        },
      ]);
    });
    expect(JSON.stringify(bodies)).not.toContain('"model"');
  });

  it('keeps a flag it does not recognise through an edit rather than dropping it', async () => {
    const bodies: unknown[] = [];
    worker.use(
      connectionHandler(),
      listHandler([FUTURE_MODEL]),
      http.put(`${modelsUrl(ORG_A, CONNECTION)}/${FUTURE_MODEL.id}`, async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ data: FUTURE_MODEL });
      }),
    );

    const screen = await renderScreen();
    await screen.getByRole('button', { name: `Edit ${FUTURE_MODEL.display_name}` }).click();

    const dialog = screen.getByRole('dialog');
    // It is SHOWN rather than hidden — an operator who cannot see it cannot reason about the row —
    // and the sentence beside it says what will happen to it.
    await expect.element(screen.getByText(/does not recognise/)).toBeVisible();

    await dialog.getByLabelText('Display name').fill('GPT-5.7 Preview (checked)');
    await dialog.getByRole('button', { name: 'Save changes' }).click();

    await vi.waitFor(() => {
      // SILENTLY DROPPING IT would strip a capability the platform depends on, with a 200 on the
      // request that did it.
      expect(bodies).toHaveLength(1);
      expect((bodies[0] as { supported: string[] }).supported).toContain('speculative_decoding');
    });
  });

  it('drops the REFUSED chat flags when the task changes, and keeps the unknown one', async () => {
    const bodies: unknown[] = [];
    worker.use(
      connectionHandler(),
      listHandler([FUTURE_MODEL]),
      http.put(`${modelsUrl(ORG_A, CONNECTION)}/${FUTURE_MODEL.id}`, async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ data: FUTURE_MODEL });
      }),
    );

    const screen = await renderScreen();
    await screen.getByRole('button', { name: `Edit ${FUTURE_MODEL.display_name}` }).click();

    const dialog = screen.getByRole('dialog');
    // `text` is one of the three flags `assert_row_coherent` refuses beside an embedding flag, so
    // switching the task drops it. `speculative_decoding` belongs to a vocabulary this console does
    // not own and survives untouched.
    await dialog.getByLabelText('Embedding').click();
    await dialog.getByRole('button', { name: 'Save changes' }).click();

    await vi.waitFor(() => {
      const supported = (bodies[0] as { supported: string[] }).supported;
      expect(supported).not.toContain('text');
      expect(supported).toContain('speculative_decoding');
    });
  });

  /**
   * THE OTHER HALF OF THE SAME RULE, and the one the console used to get wrong.
   *
   * `changeFamily` stripped every KNOWN flag outside the target family, and the family is a UI
   * arrangement rather than a server rule: eleven flags sit in this console's `chat` family and
   * `assert_row_coherent` refuses three. So an embedding row that legitimately carried `stream_usage`
   * lost it the moment an operator touched the task radio for any reason — a client STRICTER than the
   * server, removing functionality with a 200 on the request that did it.
   *
   * `tests/unit/model-catalogue.test.ts` asserts the exclusion SET against `capabilities.py`; this
   * asserts the BEHAVIOUR, because a correct set reached through a filter that ignores it would pass
   * there and fail here.
   */
  it('keeps a chat flag the data plane PERMITS on a non-chat row', async () => {
    const bodies: unknown[] = [];
    worker.use(
      connectionHandler(),
      listHandler([MIXED_MODEL]),
      http.put(`${modelsUrl(ORG_A, CONNECTION)}/${MIXED_MODEL.id}`, async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ data: MIXED_MODEL });
      }),
    );

    const screen = await renderScreen();
    await screen.getByRole('button', { name: `Edit ${MIXED_MODEL.display_name}` }).click();

    const dialog = screen.getByRole('dialog');
    await dialog.getByLabelText('Embedding').click();
    await dialog.getByRole('button', { name: 'Save changes' }).click();

    await vi.waitFor(() => {
      const supported = (bodies[0] as { supported: string[] }).supported;
      // REFUSED beside an embedding flag, so it goes.
      expect(supported).not.toContain('text');
      // PERMITTED beside one, so it stays — this is the assertion the finding is about.
      expect(supported).toContain('stream_usage');
      // Unknown, so it stays for the separate reason the open-vocabulary design gives.
      expect(supported).toContain('speculative_decoding');
    });
  });

  it('shows every held flag the chosen task does not offer, rather than carrying it invisibly', async () => {
    // Keeping a flag in the payload with no control and no badge is the same silent-loss failure one
    // step further along: the operator cannot see it, cannot remove it, and submits it anyway. So the
    // badge list is "everything this task does not offer", not "everything unrecognised".
    worker.use(connectionHandler(), listHandler([MIXED_MODEL]));

    const screen = await renderScreen();
    await screen.getByRole('button', { name: `Edit ${MIXED_MODEL.display_name}` }).click();

    const dialog = screen.getByRole('dialog');
    await dialog.getByLabelText('Embedding').click();

    await expect.element(screen.getByText(/this task does not offer/)).toBeVisible();
    await expect.element(dialog.getByText('stream_usage', { exact: true })).toBeVisible();
    await expect.element(dialog.getByText('speculative_decoding', { exact: true })).toBeVisible();
  });
});

describe('deleting the designated embedding model', () => {
  /** The server's own sentence, which is the ONLY thing that tells the operator what to do next. */
  const DESIGNATED =
    "This model is the organization's designated embedding model, so deleting it would leave the " +
    'designation naming a catalog row that no longer exists and the next upload would fail with a ' +
    'resolution error instead of anyone seeing why now. Clear the embedding designation first (PUT ' +
    '/embedding-configuration with connection_id and model both null), read the readiness verdict ' +
    'it returns, and delete the row after that.';

  it('renders the 409 message VERBATIM and offers no retry', async () => {
    worker.use(
      connectionHandler(),
      listHandler(),
      http.delete(`${modelsUrl(ORG_A, CONNECTION)}/${CHAT_MODEL.id}`, () =>
        HttpResponse.json(
          // THE ENVELOPE FOR THIS 409 IS `internal_dependency` / `retryable: false`, and that is
          // deliberate and documented: the taxonomy has no 409 row, so an unclassified 4xx keeps its
          // own status and its own message. It is NOT a server fault and there is nothing to retry.
          // `actionable: true` IS THE 409's DISTINGUISHING FIELD, not decoration on the fixture.
          // A deliberate 4xx and a defect share `(internal_dependency, retryable: false)`; this flag
          // is what `deleteConflictMessage` reads, and a fixture omitting it would exercise the
          // fallback path while claiming to test the conflict one (finding J2).
          envelope('internal_dependency', {
            message: DESIGNATED,
            retryable: false,
            actionable: true,
          }),
          { status: 409 },
        ),
      ),
    );

    const screen = await renderScreen();
    await screen.getByRole('button', { name: `Delete ${CHAT_MODEL.display_name}` }).click();

    // TYPED CONFIRMATION, and the string is the MODEL IDENTIFIER rather than the display name: it is
    // the value the deletion is really about, and it is what the operator will have to re-type to
    // recreate the row.
    await expect.element(screen.getByLabelText(`Type ${CHAT_MODEL.model} to confirm`)).toBeVisible();
    await screen.getByLabelText(`Type ${CHAT_MODEL.model} to confirm`).fill(CHAT_MODEL.model);
    await screen.getByRole('button', { name: 'Delete model' }).click();

    await expect.element(screen.getByText(DESIGNATED)).toBeVisible();
    // The class-mapped sentence would be false twice here — nothing is unavailable, and retrying
    // never works while the designation stands — so it is deliberately NOT what renders.
    expect(document.body.textContent).not.toContain('Something on our side is unavailable');
    expect(screen.getByRole('button', { name: 'Try again' }).elements()).toHaveLength(0);
  });

  it('falls back to the class-mapped sentence for a real failure, so no operator text leaks', async () => {
    worker.use(
      connectionHandler(),
      listHandler(),
      http.delete(`${modelsUrl(ORG_A, CONNECTION)}/${CHAT_MODEL.id}`, () =>
        HttpResponse.json(
          // DELIBERATELY OPERATOR-SHAPED, AND THAT IS A STRONGER TEST THAN IT USED TO BE. This
          // fixture used to carry the exact 5xx constant, because `deleteConflictMessage` told a
          // deliberate 4xx from a defect by COMPARING the message against a copy of that string —
          // so the assertion below could not fail, whatever the client did. Since finding J2 the
          // discriminator is the envelope's `actionable` flag, which `envelope()` defaults to
          // false, so a 500 may now carry a hostname and the assertion is real: what keeps it off
          // the screen is the flag, not the wording.
          envelope('internal_dependency', {
            message: 'api-7.internal refused the request: ECONNRESET',
            retryable: false,
          }),
          { status: 500 },
        ),
      ),
    );

    const screen = await renderScreen();
    await screen.getByRole('button', { name: `Delete ${CHAT_MODEL.display_name}` }).click();
    await screen.getByLabelText(`Type ${CHAT_MODEL.model} to confirm`).fill(CHAT_MODEL.model);
    await screen.getByRole('button', { name: 'Delete model' }).click();

    await expect.element(screen.getByText(/Something on our side is unavailable/)).toBeVisible();
    expect(document.body.textContent).not.toContain('api-7.internal');
  });

  it('DELETEs under both path segments and re-reads rather than writing the cache', async () => {
    const deleted: string[] = [];
    let reads = 0;
    worker.use(
      connectionHandler(),
      http.get(modelsUrl(ORG_A, CONNECTION), () => {
        reads += 1;
        return HttpResponse.json({ data: { models: reads === 1 ? [CHAT_MODEL] : [] } });
      }),
      http.delete(`${modelsUrl(ORG_A, CONNECTION)}/${CHAT_MODEL.id}`, ({ request }) => {
        deleted.push(new URL(request.url).pathname);
        return HttpResponse.json({ data: { acknowledged: true } });
      }),
    );

    const screen = await renderScreen();
    await screen.getByRole('button', { name: `Delete ${CHAT_MODEL.display_name}` }).click();
    await screen.getByLabelText(`Type ${CHAT_MODEL.model} to confirm`).fill(CHAT_MODEL.model);
    await screen.getByRole('button', { name: 'Delete model' }).click();

    await vi.waitFor(() => {
      expect(deleted).toEqual([
        `/api/v1/organizations/${ORG_A}/provider-connections/${CONNECTION}/models/${CHAT_MODEL.id}`,
      ]);
    });

    // ASSERTED BY ITS RESULT — the server's answer reaching the screen — rather than by a request
    // count. An exact count inside `waitFor` is a one-way ratchet: a single stray read makes it fail
    // for the whole timeout (measured on the members spec, 2026-08-14).
    await expect
      .element(screen.getByText('No models registered on this connection'))
      .toBeVisible();
  });
});

describe('the role split, and the refusals that are not this screen’s fault', () => {
  it('shows an analyst the class-mapped 403 and no create affordance', async () => {
    let modelReads = 0;
    worker.use(
      // An ANALYST holds nothing in this catalog (§6.4) and is refused all five endpoints. A
      // knowledge_manager would read it fine and still see no controls — which is why the create
      // affordance is gated on the ROLE here and not on the read having succeeded.
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
      http.get(connectionUrl(ORG_A, CONNECTION), () =>
        HttpResponse.json(envelope('authorization'), { status: 403 }),
      ),
      http.get(modelsUrl(ORG_A, CONNECTION), () => {
        modelReads += 1;
        return HttpResponse.json({ data: { models: [CHAT_MODEL] } });
      }),
    );

    const screen = await renderScreen();

    await expect
      .element(screen.getByText('This provider connection could not be loaded'))
      .toBeVisible();
    await expect.element(screen.getByText('You do not have access to this.')).toBeVisible();
    // The role is read PER ORGANIZATION: this viewer is an owner of the OTHER organization.
    expect(screen.getByRole('button', { name: 'Register model' }).elements()).toHaveLength(0);
    // `authorization` is not retryable by class — a 403 is an ANSWER, not a failure to reattempt.
    expect(screen.getByRole('button', { name: 'Try again' }).elements()).toHaveLength(0);
    expect(document.body.textContent).not.toContain('api-7.internal');
    // The catalogue read is gated on the parent resolving, so a refused connection does not spend a
    // second request that would be refused identically.
    expect(modelReads).toBe(0);
  });

  it('renders the same refusal for a foreign connection id, because 404 IS `authorization`', async () => {
    worker.use(
      http.get(connectionUrl(ORG_A, CONNECTION), () =>
        HttpResponse.json(envelope('authorization'), { status: 404 }),
      ),
      http.get(modelsUrl(ORG_A, CONNECTION), () =>
        HttpResponse.json({ data: { models: [] } }),
      ),
    );

    const screen = await renderScreen();

    // The deny split renders 404 as `authorization` on the admin surface, so the client CANNOT tell
    // "you may not" from "it does not exist" — which is the point, and is also what an id belonging
    // to the organization the admin just switched away from looks like.
    await expect.element(screen.getByText('You do not have access to this.')).toBeVisible();
    expect(screen.getByRole('button', { name: 'Register model' }).elements()).toHaveLength(0);
  });
});

describe('with no current organization it makes no org-scoped request at all', () => {
  it('says so, and builds no key from a null organization', async () => {
    // `useOrgKey()` THROWS on a null organization rather than returning `['org', undefined, …]`,
    // which would be ONE shared cache namespace for every org-less state on the platform. The gate
    // is expressed as a MOUNT condition, so the throw is unreachable rather than merely unlikely.
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
      http.get(connectionUrl(ORG_A, CONNECTION), () => {
        requests.push('connection');
        return HttpResponse.json({ data: CONNECTION_ROW });
      }),
      http.get(modelsUrl(ORG_A, CONNECTION), () => {
        requests.push('models');
        return HttpResponse.json({ data: { models: [CHAT_MODEL] } });
      }),
    );

    const screen = await renderScreen();

    await expect.element(screen.getByText('No organization selected')).toBeVisible();
    expect(requests).toEqual([]);
    expect(cacheKeys().filter((key) => key[0] === 'org')).toEqual([]);
  });
});
