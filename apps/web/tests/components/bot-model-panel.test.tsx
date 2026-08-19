import type { BotResource, ProviderConnectionResource, ProviderModelResource } from '@kb/contracts';
import {
  botSettingsSchema,
  EVIDENCE_THRESHOLD_SCALES,
  type BotSettingsIn,
} from '@kb/contracts/forms';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Providers } from '@/components/providers';
import { useCurrentOrgId } from '@/features/auth/session-context';
import { SessionProvider } from '@/features/auth/session-provider';
import { BOT_MODEL_FIELDS } from '@/features/bots/api';
import { BotEditorContext } from '@/features/bots/bot-editor-context';
import { BotModelPanel } from '@/features/bots/bot-model-panel';
import {
  boundFor,
  isBoundedScale,
  RETRIEVAL_DEPTHS,
} from '@/features/bots/bot-model-shared';

import { envelope, ORIGIN } from '../msw/handlers';
import { worker } from '../msw/setup';

/**
 * `/bots/{botId}` → tab 2, "Model & retrieval".
 *
 * ── WHAT THIS SPEC IS FOR ────────────────────────────────────────────────────────────────────────
 * The shell's spec owns the query key, the four states and the tab strip. This one owns the CONTROLS,
 * and above all the one field this tab exists to get right: an evidence threshold has NO PORTABLE
 * DEFAULT, so the uncalibrated case must render as guidance rather than as a number input with a
 * seeded value. That assertion is first in the file because it is the one whose absence would be
 * invisible — a seeded `0.30` is a valid float on every scale, and nothing anywhere raises.
 *
 * ── WHAT IT MAY NOT CLAIM ────────────────────────────────────────────────────────────────────────
 * Nothing about isolation. A component spec that mocks the API cannot fail an isolation test. What it
 * DOES prove is the property the Playwright test is built on: every key this panel adds carries the
 * organization even though the URL does not.
 *
 * ── THE PANEL IS MOUNTED DIRECTLY, INSIDE ITS OWN CONTEXT ───────────────────────────────────────
 * That is the contract the shell publishes — a panel takes NO PROPS and reads `useBotEditor()` — and
 * mounting it this way keeps this file independent of the two sibling panels being written at the
 * same time in the same directory.
 *
 * `<OrgGate>` below is not scaffolding for its own sake: `useOrgKey()` THROWS while the session query
 * is still in flight, because a key built from `undefined` is one namespace shared by every org-less
 * state. In the real screen the shell is what gates that; here the gate has to be re-created, or the
 * first render of `<ModelSelectionCard>` throws before any assertion runs.
 */

const ORG_A = '01JORGAAAAAAAAAAAAAAAAAAAA';
const ORG_B = '01JORGBBBBBBBBBBBBBBBBBBBB';
const BOT_ID = '01JBOTAAAAAAAAAAAAAAAAAAAA';
const CONNECTION_A = '01JCNNAAAAAAAAAAAAAAAAAAAA';
const CONNECTION_B = '01JCNNBBBBBBBBBBBBBBBBBBBB';
const MODEL_A = '01JMDXAAAAAAAAAAAAAAAAAAAA';
const MODEL_DISABLED = '01JMDXDDDDDDDDDDDDDDDDDDDD';

const botUrl = `${ORIGIN}/api/v1/organizations/${ORG_A}/bots/${BOT_ID}`;
const connectionsUrl = `${ORIGIN}/api/v1/organizations/${ORG_A}/provider-connections`;
const modelsUrl = (connectionId: string) =>
  `${ORIGIN}/api/v1/organizations/${ORG_A}/provider-connections/${connectionId}/models`;

/** The stored row. `evidence_threshold` is null WITH ITS SCALE — the ordinary state of every bot,
 *  and the state this tab has to render as an explanation rather than as an empty box. */
const BOT: BotResource = {
  id: BOT_ID,
  public_bot_id: 'pb_support',
  name: 'Support bot',
  slug: 'support-bot',
  description: null,
  welcome_message: null,
  placeholder_text: null,
  system_instruction: 'CANARY-SYSTEM-INSTRUCTION',
  answer_style_instruction: 'CANARY-ANSWER-STYLE',
  instructions_visible: true,
  status: 'testing',
  access_mode: 'public',
  provider_connection_id: CONNECTION_A,
  provider_model_id: MODEL_A,
  answer_mode: 'strict',
  dense_top_k: 40,
  sparse_top_k: 40,
  rerank_candidates: 25,
  rerank_retain: 8,
  evidence_threshold: null,
  evidence_threshold_scale: null,
  retrieval_configuration_version: 3,
  allow_general_answers: false,
  theme: {},
  rate_limit_per_minute: null,
  rate_limit_per_day: null,
  retention_days: 90,
  collect_end_user_data: false,
  consent_text: null,
  created_at: '2026-08-01T09:00:00+00:00',
  updated_at: '2026-08-02T09:00:00+00:00',
};

const connection = (
  id: string,
  label: string,
  overrides: Partial<ProviderConnectionResource> = {},
): ProviderConnectionResource => ({
  id,
  provider: 'openai',
  label,
  masked_key: '…abcd',
  status: 'active',
  created_at: '2026-07-01T09:00:00+00:00',
  ...overrides,
});

const model = (
  id: string,
  name: string,
  overrides: Partial<ProviderModelResource> = {},
): ProviderModelResource => ({
  id,
  connection_id: CONNECTION_A,
  model: 'gpt-5.1',
  display_name: name,
  supported: ['text'],
  context_window: 400_000,
  max_output_tokens: 128_000,
  enabled: true,
  input_price_per_million: null,
  output_price_per_million: null,
  price_currency: null,
  created_at: '2026-07-02T09:00:00+00:00',
  ...overrides,
});

const CONNECTIONS = [
  connection(CONNECTION_A, 'Primary key'),
  connection(CONNECTION_B, 'Spare key', { provider: 'anthropic' }),
];

const MODELS = [
  model(MODEL_A, 'GPT-5.1'),
  // Listed rather than hidden, and SELECTABLE: `entryExists` — the only predicate the server applies
  // — checks organization, connection and key and nothing else, so disabling it here would make the
  // console stricter than the server.
  model(MODEL_DISABLED, 'Retired model', { model: 'gpt-4.1', enabled: false }),
];

const readOnlyHandlers = () => [
  http.get(connectionsUrl, () => HttpResponse.json({ data: { connections: CONNECTIONS } })),
  http.get(modelsUrl(CONNECTION_A), () => HttpResponse.json({ data: { models: MODELS } })),
  http.get(modelsUrl(CONNECTION_B), () => HttpResponse.json({ data: { models: [] } })),
];

/**
 * The context the shell would publish. `reportUnsaved` is a spy rather than a no-op: the panel's one
 * obligation to the shell is to call it, and a panel that quietly dropped `useUnsavedBotEdits` would
 * still render perfectly.
 */
const reportUnsaved = vi.fn();

function OrgGate({ children }: { readonly children: ReactNode }) {
  // `useOrgKey()` throws with no current organization, so nothing that builds a key may render
  // before the session resolves. The real screen gates the same way, in the shell.
  return useCurrentOrgId() === null ? null : <>{children}</>;
}

const renderPanel = (bot: BotResource = BOT, canManage = true) =>
  render(
    <Providers>
      <SessionProvider>
        <OrgGate>
          <BotEditorContext.Provider
            value={{
              orgId: ORG_A,
              botId: BOT_ID,
              bot,
              botKey: ['org', ORG_A, 'bots', BOT_ID],
              botsListKey: ['org', ORG_A, 'bots'],
              canManage,
              reportUnsaved,
            }}
          >
            <BotModelPanel />
          </BotEditorContext.Provider>
        </OrgGate>
      </SessionProvider>
    </Providers>,
  );

/**
 * The panel, with its own two reads SETTLED.
 *
 * A test that asserts on the evidence card alone finishes while the connection list is still in
 * flight, and the response then lands after `worker.resetHandlers()` has run — MSW answers it
 * `500 Request Handler Error` and logs an unhandled request that belongs to no test. Waiting for the
 * list is also the more honest assertion: the panel a person sees is the one whose queries resolved.
 */
const renderSettled = async (bot: BotResource = BOT) => {
  const screen = await renderPanel(bot);
  await expect
    .element(screen.getByRole('combobox', { name: 'Provider connection' }))
    .toHaveTextContent('Primary key');
  return screen;
};

/** The PATCH body, captured. The body is `handleSubmit`'s output verbatim, so it is the artifact
 *  that proves the panel sends its partition and nothing else. */
const capturePatch = () => {
  const bodies: Record<string, unknown>[] = [];

  worker.use(
    http.patch(botUrl, async ({ request }) => {
      bodies.push((await request.json()) as Record<string, unknown>);
      return HttpResponse.json({ data: BOT });
    }),
  );

  return bodies;
};

beforeEach(() => {
  // `readCookie` reads the PAGE's cookie and MSW cannot set one for a cross-origin host from a
  // service worker, so without this every mutation takes the `refreshCsrfToken()` path and fails for
  // a reason that has nothing to do with what the spec is about.
  document.cookie = 'XSRF-TOKEN=test-token';
  reportUnsaved.mockClear();
  worker.use(...readOnlyHandlers());
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

describe('the evidence threshold, uncalibrated', () => {
  it('renders the refusal as guidance and offers no number control at all', async () => {
    const screen = await renderSettled();

    await expect
      .element(screen.getByText('No threshold is recorded, and there is no default.'))
      .toBeVisible();

    // THE ASSERTION THIS FILE EXISTS FOR. No threshold input, no scale select, and therefore no
    // seeded number: `0.30` is a valid float on every scale, so a seeded default would move only the
    // refusal rate, only in aggregate, and fail no test anywhere.
    expect(screen.getByLabelText('Threshold').elements()).toHaveLength(0);
    expect(screen.getByRole('combobox', { name: 'Scale' }).elements()).toHaveLength(0);

    // The guidance names the next step rather than the failure. It is the `features/embedding`
    // pattern: a refusal an operator can act on is prose naming what to do.
    expect(document.body.textContent).toContain(
      'at least 50 answerable and 50 unanswerable questions',
    );
    expect(document.body.textContent).toContain('5th percentile');
  });

  it('reveals an empty pair, scale first, only when the operator says they have a run', async () => {
    const screen = await renderSettled();

    await screen.getByRole('button', { name: 'Record an evaluated threshold' }).click();

    // Nothing is seeded on either half. The placeholder asks which scale the RUN measured on — the
    // scale is a property of the (provider, model) pair, not a preference.
    await expect
      .element(screen.getByRole('combobox', { name: 'Scale' }))
      .toHaveTextContent('Choose the scale that run measured on');
    await expect.element(screen.getByLabelText('Threshold')).toHaveValue(null);
  });

  it('states the scope: the threshold sits on a rerank score and never on the fused one', async () => {
    const screen = await renderSettled();
    // Awaited BEFORE reading `document.body.textContent`: the panel mounts behind the session query,
    // so the first render is `<OrgGate>`'s null and a bare textContent read sees an empty document.
    await expect
      .element(screen.getByText('No threshold is recorded, and there is no default.'))
      .toBeVisible();

    expect(document.body.textContent).toContain(
      'On a turn where reranking was skipped there is no score and therefore no threshold',
    );
  });
});

describe('the evidence threshold, recorded', () => {
  const calibrated: BotResource = {
    ...BOT,
    evidence_threshold: 0.42,
    evidence_threshold_scale: 'unit_interval',
  };

  it('renders the stored pair with the bound its scale implies', async () => {
    const screen = await renderSettled(calibrated);

    await expect.element(screen.getByLabelText('Threshold')).toHaveValue(0.42);
    await expect
      .element(screen.getByRole('combobox', { name: 'Scale' }))
      .toHaveTextContent('Unit interval');
    // The scale-dependent bound is not expressible as an input attribute, so it is stated.
    expect(document.body.textContent).toContain(
      'On the unit_interval scale a threshold lies between 0 and 1',
    );
  });

  it('clears BOTH halves with one control, because half a pair is a 422 on a field nobody sent', async () => {
    const bodies = capturePatch();
    const screen = await renderSettled(calibrated);

    await screen.getByRole('button', { name: 'Clear both' }).click();

    // Back to the guidance: both halves are null again.
    await expect
      .element(screen.getByText('No threshold is recorded, and there is no default.'))
      .toBeVisible();

    await screen.getByRole('button', { name: 'Save changes' }).click();

    await vi.waitFor(() => {
      expect(bodies).toHaveLength(1);
    });
    // BOTH keys, both null. Clearing only the scale passes every declarative rule and violates the
    // CHECK against the STORED threshold — a 422 keyed to a field the operator did not touch.
    expect(bodies[0]?.['evidence_threshold']).toBeNull();
    expect(bodies[0]?.['evidence_threshold_scale']).toBeNull();
  });
});

describe('the bounds this panel renders are the server’s own', () => {
  it('reads every depth bound from the dumped rules and agrees with botSettingsSchema', () => {
    // The manifest is what Laravel enforces; `botSettingsSchema` mirrors it. This asserts the two in
    // BOTH directions at the boundary, which is the check `test/form-drift.test.ts` cannot make for
    // a control's `min`/`max` attributes because it never renders one.
    for (const depth of RETRIEVAL_DEPTHS) {
      const bound = boundFor(depth.field);
      expect(bound, `no bound published for ${depth.field}`).not.toBeNull();
      if (bound === null) continue;

      const parse = (value: number) =>
        botSettingsSchema.safeParse({ [depth.field]: value } as BotSettingsIn).success;

      expect(parse(bound.min), `${depth.field} rejected its own min`).toBe(true);
      expect(parse(bound.max), `${depth.field} rejected its own max`).toBe(true);
      expect(parse(bound.min - 1), `${depth.field} accepted below its min`).toBe(false);
      expect(parse(bound.max + 1), `${depth.field} accepted above its max`).toBe(false);
    }
  });

  it('agrees with the schema about which scales are bounded', () => {
    // `isBoundedScale` is a third spelling of a set that lives in `EvidenceThresholdScale::isBounded`
    // and in `botSettingsSchema`'s `BOUNDED_SCALES`. `1.7` is the one value that tells them apart:
    // refused on a bounded scale, accepted on a logit.
    for (const scale of EVIDENCE_THRESHOLD_SCALES) {
      const accepted = botSettingsSchema.safeParse({
        evidence_threshold: 1.7,
        evidence_threshold_scale: scale,
      }).success;

      expect(accepted, `disagreement about ${scale}`).toBe(!isBoundedScale(scale));
    }
  });

  it('puts those bounds on the controls rather than numbers of its own', async () => {
    const screen = await renderSettled();

    for (const depth of RETRIEVAL_DEPTHS) {
      const bound = boundFor(depth.field);
      if (bound === null) continue;

      const input = screen.getByLabelText(depth.label);
      await expect.element(input).toHaveAttribute('min', String(bound.min));
      await expect.element(input).toHaveAttribute('max', String(bound.max));
    }
  });
});

describe('the retrieval depths', () => {
  it('seeds from the row and says out loud that reranking may never run', async () => {
    const screen = await renderSettled();

    await expect.element(screen.getByLabelText('Dense candidates')).toHaveValue(40);
    await expect.element(screen.getByLabelText('Retained after reranking')).toHaveValue(8);

    // Beside the controls, in the page text — not in a `title` attribute nobody hovers. A provider
    // that cannot rerank produces a traced SKIP and not an error, so these two numbers can be saved,
    // stored and never used.
    expect(document.body.textContent).toContain('capability-gated rather than guaranteed');
    expect(document.body.textContent).toContain('traced skip and not an');
    // And the standing instruction the depths carry.
    expect(document.body.textContent).toContain('never by intuition');
  });
});

describe('the PATCH body is this panel’s partition and nothing else', () => {
  it('sends exactly BOT_MODEL_FIELDS, so a save cannot rewrite another tab', async () => {
    const bodies = capturePatch();
    const screen = await renderSettled();

    await screen.getByLabelText('Dense candidates').fill('60');
    await screen.getByRole('button', { name: 'Save changes' }).click();

    await vi.waitFor(() => {
      expect(bodies).toHaveLength(1);
    });

    expect(Object.keys(bodies[0] ?? {}).sort()).toEqual([...BOT_MODEL_FIELDS].sort());
    expect(bodies[0]?.['dense_top_k']).toBe(60);
    // Not one key from another tab's tuple, and no server-derived field.
    expect(bodies[0]).not.toHaveProperty('name');
    expect(bodies[0]).not.toHaveProperty('status');
    expect(bodies[0]).not.toHaveProperty('retrieval_configuration_version');
  });

  it('reports its dirty state to the shell and stops reporting when it unmounts', async () => {
    const screen = await renderSettled();
    await expect.element(screen.getByLabelText('Dense candidates')).toHaveValue(40);

    reportUnsaved.mockClear();
    await screen.getByLabelText('Dense candidates').fill('60');

    await vi.waitFor(() => {
      expect(reportUnsaved).toHaveBeenCalledWith('model', true);
    });

    // The cleanup is what makes an ordinary tab change leave nothing behind — the outgoing panel is
    // unmounted by `TabsContent` and is not around to object.
    await screen.unmount();
    expect(reportUnsaved).toHaveBeenLastCalledWith('model', false);
  });
});

describe('the model selection', () => {
  it('keys both reads on the organization, which is in no URL on this screen', async () => {
    const screen = await renderSettled();

    // The list resolved, which is the observable half; the key itself is `useOrgKey()`'s and is
    // asserted through the URL it produced — both segments are in the path the fixture matched.
    await expect
      .element(screen.getByRole('combobox', { name: 'Provider connection' }))
      .toHaveTextContent('Primary key');
    expect(document.body.textContent).not.toContain(ORG_B);
  });

  it('lists a row that is not available to bots rather than hiding it', async () => {
    const screen = await renderSettled();

    await screen.getByRole('combobox', { name: 'Model' }).click();

    await expect
      .element(screen.getByRole('option', { name: /Retired model/ }))
      .toHaveTextContent('not available to bots');
  });

  it('clears the model when the connection changes, because a row belongs to one connection', async () => {
    const bodies = capturePatch();
    const screen = await renderSettled();

    await screen.getByRole('combobox', { name: 'Provider connection' }).click();
    await screen.getByRole('option', { name: /Spare key/ }).click();

    // The pair the server would refuse never gets composed: `assertModelSelection` answers 422 keyed
    // `provider_model_id` for a model registered under a different connection.
    await expect.element(screen.getByRole('combobox', { name: 'Model' })).toHaveTextContent(
      'Not configured',
    );

    await screen.getByRole('button', { name: 'Save changes' }).click();
    await vi.waitFor(() => {
      expect(bodies).toHaveLength(1);
    });
    expect(bodies[0]?.['provider_connection_id']).toBe(CONNECTION_B);
    expect(bodies[0]?.['provider_model_id']).toBeNull();
  });

  it('keeps a stored connection on screen when the list no longer contains it', async () => {
    worker.use(
      http.get(connectionsUrl, () =>
        HttpResponse.json({ data: { connections: [connection(CONNECTION_B, 'Spare key')] } }),
      ),
    );

    const screen = await renderPanel();

    // A revoked or deleted connection would otherwise render as an empty trigger — the bot reading
    // as unconfigured while its row still names a credential.
    await expect
      .element(screen.getByRole('combobox', { name: 'Provider connection' }))
      .toHaveTextContent(CONNECTION_A);
    // The model read is settled too, so nothing from this test lands after the harness resets its
    // handlers — an unhandled request there is answered `500 Request Handler Error` and reported
    // against whichever test happens to be running.
    await expect.element(screen.getByRole('combobox', { name: 'Model' })).toHaveTextContent('GPT-5.1');
  });

  it('renders a failed connection read as a class-mapped sentence, never the envelope’s message', async () => {
    worker.use(
      http.get(connectionsUrl, () =>
        HttpResponse.json(envelope('authorization'), { status: 403 }),
      ),
    );

    const screen = await renderPanel();

    await expect
      .element(screen.getByText('Provider connections could not be loaded'))
      .toBeVisible();
    await expect.element(screen.getByText('You do not have access to this.')).toBeVisible();
    // That field is operator-facing and this fixture's copy names an internal host.
    expect(document.body.textContent).not.toContain('api-7.internal');
    // `authorization` is not retryable by class, so no affordance is offered.
    expect(screen.getByRole('button', { name: 'Try again' }).elements()).toHaveLength(0);
    // The stored connection is still named, so the model read below it resolves — settled here for
    // the same reason `renderSettled` exists.
    await expect.element(screen.getByRole('combobox', { name: 'Model' })).toHaveTextContent('GPT-5.1');
  });

  it('says the fallback chain is not editable here rather than leaving a hole', async () => {
    const screen = await renderSettled();
    await expect
      .element(screen.getByRole('combobox', { name: 'Provider connection' }))
      .toBeVisible();

    expect(document.body.textContent).toContain('The fallback model chain is not editable here');
    // A statement about the CONSOLE. "No fallback models are configured" is a claim this screen
    // cannot make: the table reaches no resource and no endpoint.
    expect(document.body.textContent).toContain('no read or write endpoint yet');
  });
});

describe('the server’s verdict', () => {
  it('puts a 422 inside this tuple under its control and one outside it in the banner', async () => {
    worker.use(
      http.patch(botUrl, () =>
        HttpResponse.json(
          {
            ...envelope('validation'),
            errors: {
              evidence_threshold: ['A threshold on the sigmoid scale is between 0 and 1.'],
              // OUTSIDE this panel's partition — the consent pairing `BotService` evaluates against
              // the RESULTING row, which can 422 on a field this tab never sent.
              consent_text: ['A disclosure is required while end-user data is collected.'],
            },
          },
          { status: 422 },
        ),
      ),
    );

    const screen = await renderSettled();
    await screen.getByLabelText('Dense candidates').fill('60');
    await screen.getByRole('button', { name: 'Save changes' }).click();

    // Laravel translates validation messages, so they are end-user copy by construction and render
    // verbatim — the one exception to "never render the envelope's own strings".
    //
    // AND THE THRESHOLD CARD OPENS TO RECEIVE IT. `botPanelKnownPaths` routes this key to
    // `setError` because it is in this panel's tuple, and `knownPaths` means "the paths this form
    // RENDERS" — so a refusal arriving while the pair is collapsed behind its guidance has to bring
    // the control back, or the server rejects, nothing changes on screen, and the operator clicks
    // Save again.
    await expect
      .element(screen.getByText('A threshold on the sigmoid scale is between 0 and 1.'))
      .toBeVisible();
    await expect
      .element(screen.getByText('A disclosure is required while end-user data is collected.'))
      .toBeVisible();
  });

  it('renders no form at all without bots.manage, and states the threshold’s absence', async () => {
    const screen = await renderPanel(BOT, false);

    await expect.element(screen.getByText('None recorded, and there is no default.')).toBeVisible();

    // Rule (a) from the shell: a disabled input holding a value is a control an operator will keep
    // clicking. There is no form, no submit and no select.
    expect(screen.getByRole('button', { name: 'Save changes' }).elements()).toHaveLength(0);
    expect(screen.getByLabelText('Dense candidates').elements()).toHaveLength(0);
    expect(screen.getByRole('combobox').elements()).toHaveLength(0);
    // And no provider read is issued for a viewer whose only possible answer would be 403 — the
    // connection labels are nowhere on screen.
    expect(document.body.textContent).not.toContain('Primary key');
    // The two instruction fields belong to the identity tab and to nothing else.
    expect(document.body.textContent).not.toContain('CANARY-SYSTEM-INSTRUCTION');
  });
});
