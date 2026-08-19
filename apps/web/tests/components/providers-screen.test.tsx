import type { QueryClient } from '@tanstack/react-query';
import { useQueryClient } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { useEffect } from 'react';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Providers } from '@/components/providers';
import { SessionProvider } from '@/features/auth/session-provider';
import type { ProviderConnectionResource } from '@kb/contracts';
import { ProvidersScreen } from '@/features/providers/providers-screen';

import { envelope, ORIGIN, sessionFixture } from '../msw/handlers';
import { worker } from '../msw/setup';

/**
 * The Settings → Providers screen: the one admin surface that handles key material.
 *
 * ── WHAT THIS SPEC MAY NOT CLAIM ─────────────────────────────────────────────────────────────────
 * It proves that the query key IS org-namespaced and that the request paths carry the organization. It
 * proves NOTHING about isolation: a component test that mocks the API cannot fail an isolation test,
 * and per the vitest-playwright boundary table it must never be cited as isolation coverage.
 * "Organization A's connections never appear after switching to B, including during the in-flight
 * window" is Playwright's, and is recorded as unproven by this batch.
 *
 * ── THE HANDLERS FOR THESE ENDPOINTS LIVE HERE, NOT IN tests/msw/handlers.ts ─────────────────────
 * That file is shared by every component spec; `worker.use(...)` inside a spec is the documented
 * override mechanism and `afterEach`'s `resetHandlers()` keeps it from leaking into the next file.
 */

/** `sessionFixture()`'s current organization, and the namespace every key below must carry. */
const ORG_A = '01JORGAAAAAAAAAAAAAAAAAAAA';
/** The other ACTIVE membership in the same fixture. Present so the cache-key assertion is about the
 *  CURRENT organization rather than about the only one there is — a one-organization fixture cannot
 *  fail a namespacing test. */
const ORG_B = '01JORGBBBBBBBBBBBBBBBBBBBB';

/**
 * THE SECRET THIS SPEC WATCHES. It is a distinctive literal so that
 * `document.body.textContent).not.toContain(SECRET)` is a real assertion rather than a coincidence,
 * and the POSITIVE CONTROL below proves the same string IS observable when it is supposed to be —
 * without that control, "the secret never appears" would also pass on a screen that renders nothing.
 */
const SECRET = 'sk-live-CANARY-must-never-be-rendered-4a91';

const ACTIVE: ProviderConnectionResource = {
  id: '01JCONNAAAAAAAAAAAAAAAAAAA',
  provider: 'openai',
  label: 'Primary OpenAI key',
  // `…` (U+2026) plus the last four. A DISPLAY STRING: it cannot authenticate anything, and posting it
  // back would set the tenant's key to this literal text.
  masked_key: '…4a91',
  status: 'active',
  created_at: '2026-08-01T09:00:00+00:00',
};

/** A dead row, LISTED on purpose: an operator diagnosing "why can I not ingest" has to be able to see
 *  the credential that stopped working. */
const REVOKED: ProviderConnectionResource = {
  ...ACTIVE,
  id: '01JCONNBBBBBBBBBBBBBBBBBBB',
  provider: 'anthropic',
  label: 'Old Anthropic key',
  masked_key: '…77bc',
  status: 'revoked',
};

const connectionsUrl = (orgId: string) =>
  `${ORIGIN}/api/v1/organizations/${orgId}/provider-connections`;

/** The list body nests the array under a NAMED KEY — `{"data":{"connections":[…]}}` — because
 *  `ResponseShape` cannot express "an array of" for a response key and every published component must
 *  be `additionalProperties: false`. A fixture returning `{"data":[…]}` would make these specs pass
 *  against a shape the server never sends. */
const listHandler = (connections: readonly ProviderConnectionResource[] = [ACTIVE]) =>
  http.get(connectionsUrl(ORG_A), () => HttpResponse.json({ data: { connections } }));

/** The embedding verdict that rides along on a 201. `ready: true` unless a spec says otherwise. */
const readiness = (overrides: Record<string, unknown> = {}) => ({
  ready: true,
  blocks_ingestion: false,
  selected: { connection_id: ACTIVE.id, provider: 'openai', model: 'text-embedding-3-large' },
  // The STORED pair, which has no `provider` — `EmbeddingDesignation`, not `EmbeddingCandidate`.
  // Present so the fixture matches the wire; this screen reads only `ready` and `explanation`.
  designated: { connection_id: ACTIVE.id, model: 'text-embedding-3-large' },
  eligible: [],
  rejected: [],
  explanation: '',
  ...overrides,
});

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
        <ProvidersScreen />
      </SessionProvider>
    </Providers>,
  );

const cacheKeys = (): unknown[][] =>
  (captured?.getQueryCache().getAll() ?? []).map((query) => [...query.queryKey]);

/**
 * EVERYTHING ON THE `QueryClient`, BOTH CACHES, AS ONE STRING.
 *
 * There are two, they share a client, and the second is the one the credential assertions above could
 * not see. `mutate(values)` writes its argument to `Mutation.state.variables`; on
 * @tanstack/react-query 5.101.4 a settled mutation is removed only after `gcTime` — 5 minutes by
 * default — once its last observer detaches, and a mounted form's observer never detaches. So the
 * plaintext provider key and the admin's account password were retained on a structure any script on
 * this origin can walk, which is the one thing an XSS does not otherwise obtain: no endpoint returns
 * either.
 *
 * Serialized rather than walked field by field, deliberately: the assertion is "this secret is not
 * anywhere on the client", and a walk that knew the shape would stop catching it the day the shape
 * changes. `data` and `context` are included for the same reason.
 */
const clientDump = (): string =>
  JSON.stringify({
    queries: (captured?.getQueryCache().getAll() ?? []).map((query) => ({
      key: query.queryKey,
      state: query.state,
    })),
    mutations: (captured?.getMutationCache().getAll() ?? []).map((mutation) => mutation.state),
  });

/** Every `<input>` currently in the document, with its live DOM value. The credential assertions read
 *  this rather than `textContent`, because an input's value is NOT text content — a prefilled secret
 *  is invisible to a `toContain` over the body. */
const inputValues = (): string[] =>
  [...document.querySelectorAll('input')].map((input) => input.value);

beforeEach(() => {
  // `readCookie` reads the PAGE's cookie and MSW cannot set one for a cross-origin host from a service
  // worker, so without this every spec takes the `refreshCsrfToken()` path and fails on a missing
  // cookie for a reason that has nothing to do with what it is about.
  document.cookie = 'XSRF-TOKEN=test-token';
  captured = null;
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

describe('every organization-scoped key comes from useOrgKey()', () => {
  it('reads the list under ["org", currentOrgId, …] and never a bare key', async () => {
    worker.use(listHandler());

    const screen = await renderScreen();
    // THE LINK RATHER THAN THE CELL, and the reason is the accessible-name namespace: role-name
    // matching is a case-insensitive SUBSTRING, and this row's ACTIONS cell contains three buttons
    // named "Edit Primary OpenAI key", "Replace key for Primary OpenAI key" and "Delete Primary
    // OpenAI key" — so a cell query for the label resolves to two cells and fails on strict mode. The
    // link is unique, and asserting on it also proves the model-catalogue route is linked.
    await expect.element(screen.getByRole('link', { name: 'Primary OpenAI key' })).toBeVisible();

    await vi.waitFor(() => {
      expect(cacheKeys()).toContainEqual(['org', ORG_A, 'provider-connections']);
    });

    const keys = cacheKeys();
    // `['session']` is the ONE legal exception to the org prefix — it is the PRODUCER of `orgId`, and
    // namespacing the identity document by the organization it announces would be circular.
    expect(keys.filter((key) => key[0] !== 'org')).toEqual([['session']]);
    // Not one key mentions the organization the session is NOT currently acting in.
    expect(JSON.stringify(keys)).not.toContain(ORG_B);
  });

  it('addresses the org in the PATH too, which is a routing hint and not the scope', async () => {
    const urls: string[] = [];
    worker.use(
      http.get(connectionsUrl(ORG_A), ({ request }) => {
        urls.push(new URL(request.url).pathname);
        return HttpResponse.json({ data: { connections: [ACTIVE] } });
      }),
    );

    const screen = await renderScreen();
    await expect.element(screen.getByRole('link', { name: 'Primary OpenAI key' })).toBeVisible();

    // Mounted under `organizations/{organization}` with `->scopeBindings()`; `TenantContext` re-reads
    // the membership row per request and Laravel would ignore a client-supplied organization. The
    // segment is a routing hint — a foreign id 404s at binding time — and the SCOPE is the session.
    await vi.waitFor(() => {
      expect(urls).toEqual([`/api/v1/organizations/${ORG_A}/provider-connections`]);
    });
  });
});

describe('the list renders what the server derived', () => {
  it('shows revoked rows too, with the mask as TEXT and never as an input', async () => {
    worker.use(listHandler([ACTIVE, REVOKED]));

    const screen = await renderScreen();

    // Scoped to the TABLE. Below 768px the same rows render as a stack of cards (P6), so each label
    // appears in two layouts of which CSS shows exactly one — `display: none` also removes the other
    // from the accessibility tree, so a reader hears each connection once.
    await expect.element(screen.getByRole('link', { name: 'Primary OpenAI key' })).toBeVisible();
    await expect.element(screen.getByRole('link', { name: 'Old Anthropic key' })).toBeVisible();
    // The status column, which no button name contains — so the cell query is unambiguous here.
    await expect.element(screen.getByRole('cell', { name: 'Revoked' })).toBeVisible();

    // THE MASK IS ON SCREEN — that is the point of the column, and it is the positive control for the
    // assertion right after it.
    expect(document.body.textContent).toContain('…4a91');
    // …and it is nowhere near an input. There is no form on this screen holding it, so the value that
    // must never be submitted has no control to be submitted from.
    expect(inputValues()).not.toContain('…4a91');
    expect(inputValues().some((value) => value.includes('…'))).toBe(false);
  });

  it('renders the first-run empty state rather than a table with no body', async () => {
    worker.use(listHandler([]));

    const screen = await renderScreen();

    // FIRST-RUN empty, not filtered empty: this list has no filters, and the two are different
    // components on purpose (components/states.tsx).
    await expect.element(screen.getByText('No provider connections yet')).toBeVisible();
    expect(screen.getByText('No matches').elements()).toHaveLength(0);
  });
});

describe('the credential is write-only, and the spec proves the check can see one', () => {
  it('POSITIVE CONTROL: the pasted key IS in the create body, so the absence checks mean something', async () => {
    const bodies: unknown[] = [];
    worker.use(
      listHandler([]),
      http.post(connectionsUrl(ORG_A), async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json(
          { data: ACTIVE, embedding_readiness: readiness() },
          { status: 201 },
        );
      }),
    );

    const screen = await renderScreen();
    await screen.getByLabelText('Label').fill('Primary OpenAI key');
    await screen.getByLabelText('API key').fill(SECRET);
    await screen.getByRole('button', { name: 'Store connection', exact: true }).click();

    await vi.waitFor(() => {
      // `models: []` is MANDATORY: the rule is `present|array`, so an omitted key is a 422 on a field
      // this form does not render. NO `organization_id` — it is an ownership column and a URL segment;
      // a body field would be a tenant key the caller can set, which is an authorization bug with a
      // 201.
      expect(bodies).toEqual([
        { provider: 'openai', label: 'Primary OpenAI key', credential: SECRET, models: [] },
      ]);
    });
  });

  it('clears the key from the form and never renders it back', async () => {
    worker.use(
      listHandler([]),
      http.post(connectionsUrl(ORG_A), () =>
        HttpResponse.json({ data: ACTIVE, embedding_readiness: readiness() }, { status: 201 }),
      ),
    );

    const screen = await renderScreen();
    await screen.getByLabelText('API key').fill(SECRET);
    await screen.getByLabelText('Label').fill('Primary OpenAI key');
    await screen.getByRole('button', { name: 'Store connection', exact: true }).click();

    // The receipt names the MASK, which is the only thing the operator ever sees again.
    await expect.element(screen.getByText(/Stored OpenAI key/)).toBeVisible();
    // `credential` is absent from `defaultValues`, so `reset()` empties it rather than restoring a
    // remembered secret.
    await vi.waitFor(() => {
      expect(inputValues()).not.toContain(SECRET);
    });
    expect(document.body.textContent).not.toContain(SECRET);
  });

  it('never seeds the edit form from the resource, so the mask cannot be submitted', async () => {
    const bodies: unknown[] = [];
    worker.use(
      listHandler(),
      http.patch(`${connectionsUrl(ORG_A)}/${ACTIVE.id}`, async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ data: { ...ACTIVE, label: 'Renamed' } });
      }),
    );

    const screen = await renderScreen();
    await screen.getByRole('button', { name: `Edit ${ACTIVE.label}` }).click();

    // THE FORM IS SEEDED BY A TWO-FIELD FACTORY, not by a spread of the resource. So the dialog has a
    // label and a status and no third input holding `…4a91`.
    await expect.element(screen.getByRole('heading', { name: 'Edit connection' })).toBeVisible();
    expect(inputValues().some((value) => value.includes('…'))).toBe(false);
    expect(inputValues()).toContain(ACTIVE.label);

    // "Connection label" and not "Label": the create form is still mounted behind this dialog with a
    // control named "Label", and accessible-name matching is a SUBSTRING — so the dialog's field is
    // named to be unambiguous for a locator and for a screen reader alike.
    await screen.getByLabelText('Connection label').fill('Renamed');
    await screen.getByRole('button', { name: 'Save changes', exact: true }).click();

    await vi.waitFor(() => {
      // TWO KEYS, and the endpoint could not accept a third: the schema is a `strictObject` over
      // {label, status} and the FormRequest declares no credential rule.
      expect(bodies).toEqual([{ label: 'Renamed', status: 'active' }]);
    });
    expect(JSON.stringify(bodies)).not.toContain('4a91');
    expect(JSON.stringify(bodies)).not.toContain('credential');
  });

  it('sends the rotation as its own request, with the actor password, and clears both fields', async () => {
    const bodies: unknown[] = [];
    worker.use(
      listHandler(),
      http.put(`${connectionsUrl(ORG_A)}/${ACTIVE.id}/credential`, async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ data: { ...ACTIVE, masked_key: '…9f00' } });
      }),
    );

    const screen = await renderScreen();
    await screen.getByRole('button', { name: `Replace key for ${ACTIVE.label}` }).click();

    // The mask is rendered in the dialog as TEXT, beside the input it must never seed.
    await expect.element(screen.getByText(/Currently ending in/)).toBeVisible();
    expect(inputValues().some((value) => value.includes('…'))).toBe(false);

    await screen.getByLabelText('New API key').fill(SECRET);
    await screen.getByLabelText('Your password').fill('correct horse battery');
    await screen.getByRole('button', { name: 'Replace key', exact: true }).click();

    await vi.waitFor(() => {
      // §18.3: the actor re-authenticates. `current_password:web` is a VALIDATION rule, so a wrong
      // password is a 422 before any row is read.
      expect(bodies).toEqual([{ credential: SECRET, current_password: 'correct horse battery' }]);
    });

    // Both secrets leave form state on success, and the dialog closes.
    await vi.waitFor(() => {
      expect(document.body.textContent).not.toContain(SECRET);
      expect(inputValues()).not.toContain(SECRET);
      expect(inputValues()).not.toContain('correct horse battery');
    });
  });
});

/**
 * THE SECOND CACHE, WHICH THE BLOCK ABOVE CANNOT SEE.
 *
 * Every assertion up there reads the DOM, the request body, or the query cache. None of them reads
 * `Mutation.state.variables`, and that is where both secrets used to live: `mutate(values)` stores its
 * argument, `form.reset()` does not touch it, and a settled mutation survives its `gcTime` (5 minutes
 * by default) after its last observer detaches — which, for a form that stays mounted, is never.
 *
 * The repair is that neither secret is a mutation variable at all: they are held in a `useRef`, read
 * inside `mutationFn`, and cleared in `onSettled`. The create form's variable is the PROVIDER and the
 * rotation dialog's is `void`. `gcTime: 0` would only have shortened the window, so it is not used.
 *
 * BOTH TESTS CARRY THEIR OWN POSITIVE CONTROL, because `clientDump()` returning `"{}"` — a client that
 * was never captured, a mutation that never ran — would make every `not.toContain` vacuously true.
 */
describe('no secret reaches the MUTATION cache, which shares a client with the query cache', () => {
  it('keeps the pasted key off the client after a create', async () => {
    worker.use(
      listHandler([]),
      http.post(connectionsUrl(ORG_A), () =>
        HttpResponse.json({ data: ACTIVE, embedding_readiness: readiness() }, { status: 201 }),
      ),
    );

    const screen = await renderScreen();
    await screen.getByLabelText('Label').fill('Primary OpenAI key');
    await screen.getByLabelText('API key').fill(SECRET);
    await screen.getByRole('button', { name: 'Store connection', exact: true }).click();

    await expect.element(screen.getByText(/Stored OpenAI key/)).toBeVisible();

    await vi.waitFor(() => {
      const dump = clientDump();
      // POSITIVE CONTROL: a mutation really did settle on the captured client, so the absence below
      // is a fact about this run rather than about an empty structure.
      expect(captured?.getMutationCache().getAll().length ?? 0).toBeGreaterThan(0);
      // The variable that IS kept: a `<Select>` value from a five-member union, and not a secret.
      expect(dump).toContain('openai');
      expect(dump).not.toContain(SECRET);
    });
  });

  it('keeps the new key AND the actor password off the client after a rotation', async () => {
    worker.use(
      listHandler(),
      http.put(`${connectionsUrl(ORG_A)}/${ACTIVE.id}/credential`, () =>
        HttpResponse.json({ data: { ...ACTIVE, masked_key: '…9f00' } }),
      ),
    );

    const screen = await renderScreen();
    await screen.getByRole('button', { name: `Replace key for ${ACTIVE.label}` }).click();

    await screen.getByLabelText('New API key').fill(SECRET);
    await screen.getByLabelText('Your password').fill('correct horse battery');
    await screen.getByRole('button', { name: 'Replace key', exact: true }).click();

    await vi.waitFor(() => {
      const dump = clientDump();
      expect(captured?.getMutationCache().getAll().length ?? 0).toBeGreaterThan(0);
      // The rotation's variable is `void`, so there is nothing at all for the cache to hold.
      expect(dump).not.toContain(SECRET);
      expect(dump).not.toContain('correct horse battery');
    });
  });

  it('…and the dump can see a secret, so both absences mean something', async () => {
    // THE HARNESS'S OWN POSITIVE CONTROL. `clientDump` reads two caches through two accessors; if it
    // read neither, or serialized a shape that dropped `variables`, every assertion above would pass
    // against a schema-correct lie. A mutation planted by hand proves the reader reaches the field.
    await renderScreen();
    await vi.waitFor(() => {
      expect(captured).not.toBeNull();
    });

    const client = captured as QueryClient;
    // A REAL mutation through the real cache, executed with the secret as its variable — which is
    // exactly the shape the two forms used to have. Nothing is stubbed, so a `clientDump` that
    // stopped reading `state.variables` fails here.
    const planted = client.getMutationCache().build(client, {
      mutationFn: (value: string) => Promise.resolve(value),
    });
    await planted.execute(SECRET);

    expect(clientDump()).toContain(SECRET);
  });
});

describe('a 422 lands on the field that owns it', () => {
  it('renders the server message under API key, verbatim, and never the envelope message', async () => {
    worker.use(
      listHandler([]),
      http.post(connectionsUrl(ORG_A), () =>
        HttpResponse.json(
          envelope('validation', {
            errors: {
              credential: ['That looks like the masked display value, not a credential.'],
            },
          }),
          { status: 422 },
        ),
      ),
    );

    const screen = await renderScreen();
    await screen.getByLabelText('Label').fill('Primary OpenAI key');
    await screen.getByLabelText('API key').fill('…4a91');
    await screen.getByRole('button', { name: 'Store connection', exact: true }).click();

    // Validation MESSAGES are Laravel-translated end-user copy and are shown verbatim; the envelope's
    // `message` is operator-facing and never is.
    await expect
      .element(screen.getByText('That looks like the masked display value, not a credential.'))
      .toBeVisible();
    await expect.element(screen.getByLabelText('API key')).toHaveAttribute('aria-invalid', 'true');
    expect(document.body.textContent).not.toContain('api-7.internal');
  });

  it('routes a 422 on `models.*` to the banner, because this form renders no such control', async () => {
    worker.use(
      listHandler([]),
      http.post(connectionsUrl(ORG_A), () =>
        HttpResponse.json(
          envelope('validation', {
            errors: { 'models.0.model': ['The models.0.model field is required.'] },
          }),
          { status: 422 },
        ),
      ),
    );

    const screen = await renderScreen();
    await screen.getByLabelText('Label').fill('Primary OpenAI key');
    await screen.getByLabelText('API key').fill(SECRET);
    await screen.getByRole('button', { name: 'Store connection', exact: true }).click();

    // `PROVIDER_CONNECTION_KNOWN_PATHS` subtracts the whole `models` sub-tree, so this is an orphan and
    // reaches `root.serverError`. Written to a field instead, it would display NOWHERE: the operator
    // clicks Save, the server rejects, nothing changes on screen, and they click again.
    await expect
      .element(screen.getByText('The models.0.model field is required.'))
      .toBeVisible();
  });
});

describe('the readiness verdict that rides along on the 201', () => {
  it('renders the data plane words verbatim when the organization still cannot ingest', async () => {
    const EXPLANATION =
      'No connection carries a model flagged for embedding. Add an embedding model to a connection, then designate it.';
    worker.use(
      listHandler([]),
      http.post(connectionsUrl(ORG_A), () =>
        HttpResponse.json(
          {
            data: ACTIVE,
            embedding_readiness: readiness({
              ready: false,
              blocks_ingestion: true,
              selected: null,
              // Nothing resolved AND nothing stored — the state this explanation describes.
              designated: null,
              explanation: EXPLANATION,
            }),
          },
          { status: 201 },
        ),
      ),
    );

    const screen = await renderScreen();
    await screen.getByLabelText('Label').fill('Primary OpenAI key');
    await screen.getByLabelText('API key').fill(SECRET);
    await screen.getByRole('button', { name: 'Store connection', exact: true }).click();

    // The upload path raises this SAME string, so the banner and the eventual error say the same
    // thing. It is not an error envelope — it arrives on a 201 — so there is no class to map and
    // nothing to paraphrase.
    await expect.element(screen.getByText(EXPLANATION)).toBeVisible();
    await expect
      .element(screen.getByText('This organization still cannot index documents'))
      .toBeVisible();
  });
});

describe('deleting the designated embedding connection', () => {
  /** The server's own sentence, which is the ONLY thing that tells the operator what to do next. */
  const DESIGNATED =
    "This connection supplies the organization's embedding credential, so deleting it would leave " +
    'every already-indexed chunk in a vector space nothing can reproduce. Clear the embedding ' +
    'designation first (PUT /embedding-configuration with connection_id and model both null), read ' +
    'the readiness verdict it returns, and delete the connection after that.';

  it('renders the 409 message VERBATIM and offers no retry', async () => {
    worker.use(
      listHandler(),
      http.delete(`${connectionsUrl(ORG_A)}/${ACTIVE.id}`, () =>
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
    await screen.getByRole('button', { name: `Delete ${ACTIVE.label}` }).click();

    // TYPED CONFIRMATION: the confirm button stays disabled until the connection's own name is typed.
    // NOT `getByText('Delete connection')` — that string is BOTH the dialog title and the confirm
    // button, so it resolves to two elements. The input is the thing this step is actually waiting for.
    await expect.element(screen.getByLabelText(`Type ${ACTIVE.label} to confirm`)).toBeVisible();
    await screen.getByLabelText(`Type ${ACTIVE.label} to confirm`).fill(ACTIVE.label);
    await screen.getByRole('button', { name: 'Delete connection', exact: true }).click();

    await expect.element(screen.getByText(DESIGNATED)).toBeVisible();
    // The class-mapped sentence would be false twice here — nothing is unavailable, and retrying never
    // works while the designation stands — so it is deliberately NOT what renders.
    expect(document.body.textContent).not.toContain('Something on our side is unavailable');
    expect(screen.getByRole('button', { name: 'Try again' }).elements()).toHaveLength(0);
  });

  it('falls back to the class-mapped sentence for a real failure, so no operator text leaks', async () => {
    worker.use(
      listHandler(),
      http.delete(`${connectionsUrl(ORG_A)}/${ACTIVE.id}`, () =>
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
    await screen.getByRole('button', { name: `Delete ${ACTIVE.label}` }).click();
    await screen.getByLabelText(`Type ${ACTIVE.label} to confirm`).fill(ACTIVE.label);
    await screen.getByRole('button', { name: 'Delete connection', exact: true }).click();

    await expect
      .element(screen.getByText(/Something on our side is unavailable/))
      .toBeVisible();
    expect(document.body.textContent).not.toContain('api-7.internal');
  });

  it('DELETEs under the organization path and re-reads rather than writing the cache', async () => {
    const deleted: string[] = [];
    let reads = 0;
    worker.use(
      http.get(connectionsUrl(ORG_A), () => {
        reads += 1;
        return HttpResponse.json({ data: { connections: reads === 1 ? [ACTIVE] : [] } });
      }),
      http.delete(`${connectionsUrl(ORG_A)}/${ACTIVE.id}`, ({ request }) => {
        deleted.push(new URL(request.url).pathname);
        return HttpResponse.json({ data: { acknowledged: true } });
      }),
    );

    const screen = await renderScreen();
    await screen.getByRole('button', { name: `Delete ${ACTIVE.label}` }).click();
    await screen.getByLabelText(`Type ${ACTIVE.label} to confirm`).fill(ACTIVE.label);
    await screen.getByRole('button', { name: 'Delete connection', exact: true }).click();

    await vi.waitFor(() => {
      expect(deleted).toEqual([
        `/api/v1/organizations/${ORG_A}/provider-connections/${ACTIVE.id}`,
      ]);
    });

    // ASSERTED BY ITS RESULT — the server's answer reaching the screen — rather than by a request
    // count. An exact count inside `waitFor` is a one-way ratchet: a single stray read makes it fail
    // for the whole timeout (measured on the members spec, 2026-08-14).
    await expect.element(screen.getByText('No provider connections yet')).toBeVisible();
  });
});

describe('the role split on this screen is WIDER than the members one', () => {
  it('shows an analyst the class-mapped 403 and no create form at all', async () => {
    worker.use(
      // An ANALYST holds nothing in this catalog (§6.4) and is refused all five endpoints. A
      // knowledge_manager would read the list fine and still see no controls — which is why the form is
      // gated on the ROLE here and not, as on the members screen, on the read having succeeded.
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
      http.get(connectionsUrl(ORG_A), () =>
        HttpResponse.json(envelope('authorization'), { status: 403 }),
      ),
    );

    const screen = await renderScreen();

    await expect
      .element(screen.getByText('Provider connections could not be loaded'))
      .toBeVisible();
    await expect.element(screen.getByText('You do not have access to this.')).toBeVisible();
    // The role is read PER ORGANIZATION: this viewer is an owner of the OTHER organization.
    expect(
      screen.getByRole('button', { name: 'Store connection', exact: true }).elements(),
    ).toHaveLength(0);
    // `authorization` is not retryable by class — a 403 is an ANSWER, not a failure to reattempt.
    expect(screen.getByRole('button', { name: 'Try again' }).elements()).toHaveLength(0);
    expect(document.body.textContent).not.toContain('api-7.internal');
  });
});

describe('with no current organization it makes no org-scoped request at all', () => {
  it('says so, and builds no key from a null organization', async () => {
    // `useOrgKey()` THROWS on a null organization rather than returning `['org', undefined, …]`, which
    // would be ONE shared cache namespace for every org-less state on the platform. The gate is
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
      http.get(connectionsUrl(ORG_A), () => {
        requests.push('connections');
        return HttpResponse.json({ data: { connections: [ACTIVE] } });
      }),
    );

    const screen = await renderScreen();

    await expect.element(screen.getByText('No organization selected')).toBeVisible();
    expect(requests).toEqual([]);
    expect(cacheKeys().filter((key) => key[0] === 'org')).toEqual([]);
  });
});
