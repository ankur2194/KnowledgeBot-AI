import type { BotResource, SessionResource } from '@kb/contracts';
import type { QueryClient } from '@tanstack/react-query';
import { useQueryClient } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { useEffect } from 'react';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Providers } from '@/components/providers';
import { SessionProvider } from '@/features/auth/session-provider';
import { BotEditorScreen } from '@/features/bots/bot-editor-screen';

import { envelope, ORIGIN, sessionFixture } from '../msw/handlers';
import { worker } from '../msw/setup';

/**
 * `/bots/{botId}` — the editor shell.
 *
 * ── WHAT THIS SPEC IS FOR ────────────────────────────────────────────────────────────────────────
 * The shell renders no control. What it owns is the query key, the four states, the tab strip and the
 * two facts every panel is built on top of — `canManage`, and the management-only projection of the
 * two instruction fields — so those are what is asserted here. The panels' own controls belong to
 * their own specs, written alongside them.
 *
 * ── WHAT IT MAY NOT CLAIM ────────────────────────────────────────────────────────────────────────
 * Nothing about isolation. A component test that mocks the API cannot fail an isolation test, and per
 * the `vitest-playwright` boundary table it must never be cited as isolation coverage. "Organization
 * A's bot never renders after switching to B" is Playwright's claim, against a real server, and is
 * recorded as unproven here. What this DOES prove is the property that makes the Playwright test
 * possible: the key carries the organization even though the URL does not.
 *
 * ── THE HANDLER LIVES HERE, NOT IN tests/msw/handlers.ts ────────────────────────────────────────
 * That file is shared by every component spec; `worker.use(...)` is the documented override and
 * `resetHandlers()` keeps it from leaking. The harness runs with `onUnhandledRequest: 'error'`, which
 * under the service-worker transport answers `500 Request Handler Error` rather than rejecting — so a
 * missing handler surfaces as an error state the spec did not ask for.
 */

/** `sessionFixture()`'s current organization, and the namespace the key must carry. */
const ORG_A = '01JORGAAAAAAAAAAAAAAAAAAAA';
/** The other ACTIVE membership in the same fixture. A one-organization fixture cannot fail a
 *  namespacing test, which is why the shared fixture carries two. */
const ORG_B = '01JORGBBBBBBBBBBBBBBBBBBBB';

const BOT_ID = '01JBOTAAAAAAAAAAAAAAAAAAAA';

const botUrl = (orgId: string, botId: string) =>
  `${ORIGIN}/api/v1/organizations/${orgId}/bots/${botId}`;

/**
 * The row as an OWNER receives it. Both instruction fields carry canary text, because a caller WITH
 * `bots.manage` really is sent them — and the shell still renders neither, since they belong to the
 * identity tab and to nothing else.
 */
const BOT: BotResource = {
  id: BOT_ID,
  public_bot_id: 'pb_support',
  name: 'Support bot',
  slug: 'support-bot',
  description: 'Answers billing and account questions.',
  welcome_message: null,
  placeholder_text: null,
  system_instruction: 'CANARY-SYSTEM-INSTRUCTION',
  answer_style_instruction: 'CANARY-ANSWER-STYLE',
  status: 'testing',
  access_mode: 'public',
  provider_connection_id: '01JCONNAAAAAAAAAAAAAAAAAAA',
  provider_model_id: '01JMODELAAAAAAAAAAAAAAAAAA',
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

const botHandler = (bot: BotResource = BOT) =>
  http.get(botUrl(ORG_A, BOT_ID), () => HttpResponse.json({ data: bot }));

/** The same person, an ANALYST here and an owner elsewhere — the case a single-role fixture hides. */
const analystSession = (): SessionResource => {
  const base = sessionFixture();
  return {
    ...base,
    organizations: base.organizations.map((organization) =>
      organization.id === ORG_A ? { ...organization, role: 'analyst' as const } : organization,
    ),
  };
};

/** Captures the tree's QueryClient so the KEYS themselves can be asserted, not just request URLs —
 *  the organization is not in this screen's URL at all, so the key is the artifact worth reading.
 *  The write happens in an EFFECT: `react-hooks/globals` is an error on reassigning a module-scope
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
        <BotEditorScreen botId={BOT_ID} />
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
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

describe('the detail query is org-namespaced even though the URL is not', () => {
  it('keys the row on [org, orgId, bots, botId] and mentions no other organization', async () => {
    worker.use(botHandler());

    const screen = await renderScreen();
    await expect.element(screen.getByRole('heading', { name: /Support bot/ })).toBeVisible();

    await vi.waitFor(() => {
      expect(cacheKeys()).toContainEqual(['org', ORG_A, 'bots', BOT_ID]);
    });

    const keys = cacheKeys();
    // `['session']` is the ONE legal exception to the org prefix — it is the PRODUCER of `orgId`, and
    // namespacing the identity document by the organization it announces would be circular.
    expect(keys.filter((key) => key[0] !== 'org')).toEqual([['session']]);
    // Not one key mentions the organization the session is NOT currently acting in.
    expect(JSON.stringify(keys)).not.toContain(ORG_B);
  });
});

describe('the four states', () => {
  it('draws a skeleton before the row arrives, and never a blank pane', async () => {
    worker.use(
      http.get(botUrl(ORG_A, BOT_ID), async () => {
        await new Promise((resolve) => setTimeout(resolve, 60));
        return HttpResponse.json({ data: BOT });
      }),
    );

    const screen = await renderScreen();
    // `aria-busy` on the container, `aria-hidden` on every block: a skeleton is not announced.
    await vi.waitFor(() => {
      expect(document.querySelector('[aria-busy="true"]')).not.toBeNull();
    });
    await expect.element(screen.getByRole('heading', { name: /Support bot/ })).toBeVisible();
  });

  it('renders an unknown id as `authorization`, names no role, and leaves a way out', async () => {
    // A foreign id, an unknown id and a revoked membership all 404 at BINDING time and are rendered
    // as `authorization` by the deny split — byte-identical on the wire on purpose. ALL FOUR roles
    // hold `bots.view` (ADR-056), so a role gap is the one explanation that is never true; naming a
    // role would be a dead end pointing at the wrong door.
    worker.use(
      http.get(botUrl(ORG_A, BOT_ID), () =>
        HttpResponse.json(envelope('authorization'), { status: 404 }),
      ),
    );

    const screen = await renderScreen();

    await expect.element(screen.getByText('You do not have access to this.')).toBeVisible();
    await expect.element(screen.getByText('01JREQFROMLARAVEL')).toBeVisible();
    // The class-mapped sentence, never the envelope's `message` — that field is operator-facing and
    // this fixture's copy names an internal host so a spec that rendered it fails here.
    expect(document.body.textContent).not.toContain('api-7.internal');
    // No role is named anywhere.
    expect(document.body.textContent).not.toContain('role to manage this');
    // The back link renders in EVERY state, and it matters most in this one: an operator who arrived
    // from a bookmark or from the create dialog's push has no history to go back through.
    await expect.element(screen.getByRole('link', { name: 'All bots' })).toBeVisible();
    // Nothing below the failure renders: no tabs for a bot that may not exist.
    expect(screen.getByRole('tab', { name: 'Identity & voice' }).elements()).toHaveLength(0);
  });

  it('offers no retry on an unknown class, because unknown is permanently non-retryable', async () => {
    worker.use(
      http.get(botUrl(ORG_A, BOT_ID), () => HttpResponse.text('gateway blew up', { status: 502 })),
    );

    const screen = await renderScreen();

    await expect
      .element(
        screen.getByText(
          'That did not go through, and the reason was not reported. If it keeps happening, quote the reference below.',
        ),
      )
      .toBeVisible();
    expect(screen.getByRole('button', { name: 'Try again' }).elements()).toHaveLength(0);
  });
});

describe('the shell says once what every panel then obeys', () => {
  it('renders the bot`s own identity from the fetched row rather than from the URL', async () => {
    worker.use(botHandler());

    const screen = await renderScreen();

    // The `h1` is the ROUTE's ("Bot settings", server-rendered and byte-identical for every tenant);
    // this is the `h2` that says which bot. A screen that trusted the path segment would render a
    // heading for a bot it never read — which is exactly what an organization switch produces.
    await expect.element(screen.getByRole('heading', { name: /Support bot/ })).toBeVisible();
    // Scoped to the SUMMARY card rather than to the page: the identity panel names the same bot, and
    // a bare `getByText('support-bot')` resolves two elements and fails on strict mode. That is the
    // same substring hazard the two create triggers are named around, met from the other side.
    const summary = document.querySelector('h2')?.closest('[data-slot="card"]');
    expect(summary?.textContent).toContain('support-bot');
    expect(summary?.textContent).toContain('Public');
    // The status pill carries its WORD as well as its colour, so it survives greyscale and CVD.
    await expect.element(screen.getByText('Testing')).toBeVisible();
  });

  it('renders neither instruction field, for a viewer who is sent both', async () => {
    worker.use(botHandler());

    const screen = await renderScreen();
    await expect.element(screen.getByRole('heading', { name: /Support bot/ })).toBeVisible();

    // They belong to the identity tab and to nothing else. The shell renders a summary, and a summary
    // that quoted a bot's own prompt would put it on a screen the projection exists to control.
    //
    // SCOPED TO THE SUMMARY CARD, and the whole-body form this replaced was passing VACUOUSLY. It
    // held only while `BotIdentityPanel` was a stub: the shell opens on the identity tab, that tab
    // renders `system_instruction` in a controlled `<textarea>` for a caller who may see it — which
    // is its entire job — and React emits a controlled textarea's value as a child node, so it is in
    // `document.body.textContent` by construction. A whole-body assertion here therefore forbids the
    // feature rather than the leak, and the first correct panel turns it red.
    //
    // The property it was written to defend is unchanged and still asserted: the SUMMARY never
    // quotes the prompt. This is the same scoping, for the same reason, that the sibling assertion
    // above already applies to `support-bot`.
    //
    // Nothing is lost by narrowing it. The case this test's NAME evokes — a viewer who may not see
    // the fields — is the projection, and that is the next test in this file: the server nulls both
    // keys for a caller without `bots.manage`, and the panel branches to a component that mounts no
    // form at all, so there is no path on which a withheld value can seed a control.
    const summary = document.querySelector('h2')?.closest('[data-slot="card"]');
    expect(summary?.textContent).not.toContain('CANARY-SYSTEM-INSTRUCTION');
    expect(summary?.textContent).not.toContain('CANARY-ANSWER-STYLE');
  });

  it('tells a reader without bots.manage that the fields are hidden rather than empty', async () => {
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () => HttpResponse.json({ data: analystSession() })),
      // THE SERVER'S OWN PROJECTION: the keys stay and the values become null for a caller without
      // `bots.manage`. A screen that read that as "not set" would offer to fill in a field it is not
      // allowed to see.
      botHandler({ ...BOT, system_instruction: null, answer_style_instruction: null }),
    );

    const screen = await renderScreen();

    await expect.element(screen.getByText('You can read this bot but not change it')).toBeVisible();
    // Said ONCE, by the shell, rather than three times by three panels — and said as a fact about
    // this organization rather than as an error, because reading is exactly what an analyst may do.
    expect(document.body.textContent).toContain('they are not empty');
  });
});

describe('the tab strip', () => {
  it('offers the three tabs and shows one panel at a time', async () => {
    worker.use(botHandler());

    const screen = await renderScreen();
    await expect.element(screen.getByRole('heading', { name: /Support bot/ })).toBeVisible();

    await expect.element(screen.getByRole('tab', { name: 'Identity & voice' })).toBeVisible();
    await expect.element(screen.getByRole('tab', { name: 'Model & retrieval' })).toBeVisible();
    await expect.element(screen.getByRole('tab', { name: 'Publishing' })).toBeVisible();

    // ── WHAT RADIX ACTUALLY DOES, MEASURED HERE RATHER THAN ASSUMED ──────────────────────────
    // All three `role="tabpanel"` ELEMENTS exist; only the selected one is un-`hidden`, and only the
    // selected one has CHILDREN — `TabsContent` renders `present && children`. So a panel's component
    // is unmounted when its tab is not selected, which destroys its form state, which is exactly why
    // the shell owns the unsaved-edit guard: the outgoing panel is gone by the time anyone could ask.
    // `forceMount` is not the escape hatch it looks like — it makes `present` unconditionally true
    // and `hidden` is `!present`, so all three would become VISIBLE at once.
    expect(document.querySelectorAll('[role="tabpanel"]')).toHaveLength(3);
    expect(document.querySelectorAll('[role="tabpanel"]:not([hidden])')).toHaveLength(1);
    expect(document.body.textContent).toContain('Identity & voice');
  });

  it('switches panels, and the tab is not in the URL', async () => {
    worker.use(botHandler());

    const screen = await renderScreen();
    await expect.element(screen.getByRole('heading', { name: /Support bot/ })).toBeVisible();

    await screen.getByRole('tab', { name: 'Publishing' }).click();

    await expect
      .element(screen.getByRole('tab', { name: 'Publishing' }))
      .toHaveAttribute('aria-selected', 'true');
    // The three panels are three halves of one settings screen rather than three views of a
    // collection, so there is no `?tab=` to bookmark and no second reader of `useSearchParams` on a
    // route that has none. This spec renders no navigation mock at all, which is the assertion.
    await expect.element(screen.getByRole('tabpanel')).toBeVisible();
  });
});
