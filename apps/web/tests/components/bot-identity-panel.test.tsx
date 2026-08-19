import type { BotResource, BotStarterQuestionResource } from '@kb/contracts';
import type { QueryClient } from '@tanstack/react-query';
import { useQueryClient } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { useEffect, useMemo, type ReactNode } from 'react';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Providers } from '@/components/providers';
import { useCurrentOrgId } from '@/features/auth/session-context';
import { SessionProvider } from '@/features/auth/session-provider';
import { BOT_IDENTITY_FIELDS } from '@/features/bots/api';
import {
  BotEditorContext,
  type BotEditorContextValue,
  type BotEditorPanelId,
} from '@/features/bots/bot-editor-context';
import { BotIdentityPanel } from '@/features/bots/bot-identity-panel';
import { orgKey } from '@/lib/query/client';

import { envelope, ORIGIN } from '../msw/handlers';
import { worker } from '../msw/setup';

/**
 * `/bots/{botId}` — TAB 1, "Identity & voice".
 *
 * ── WHAT THIS SPEC IS FOR ────────────────────────────────────────────────────────────────────────
 * The shell's spec owns the route, its four states and the tab strip. This one owns the controls: the
 * eight fields of `BOT_IDENTITY_FIELDS`, the two of them that are a MANAGEMENT-ONLY PROJECTION, the
 * partition the PATCH body has to respect, where a 422 lands, and the starter questions — which are
 * not a field at all but a child collection with its own endpoints and its own re-sequencing rule.
 *
 * ── WHAT IT MAY NOT CLAIM ────────────────────────────────────────────────────────────────────────
 * Nothing about isolation. A component spec that mocks the API cannot fail an isolation test and must
 * never be cited as isolation coverage — "organization A's bot never renders after switching to B" is
 * Playwright's claim, against a real server. What this DOES prove is the property that makes that
 * test possible: the starter-question key carries the organization even though the URL does not.
 *
 * ── THE PANEL IS MOUNTED IN THE CONTEXT RATHER THAN THROUGH THE SHELL ───────────────────────────
 * That is the contract, stated in `bot-editor-screen.tsx` §1: a panel takes NO PROPS and reads
 * `useBotEditor()`, so a spec wraps it in the context instead of assembling six props — and mounting
 * it through the shell would put a second agent's two panels in this file's blast radius.
 *
 * ── EVERY `render` IS AWAITED ────────────────────────────────────────────────────────────────────
 * `vitest-browser-react` wraps `render`/`rerender`/`unmount` in React's `act()`. Two overlapping
 * corrupts the act queue for the REST OF THE FILE — the offending test passes and every test after it
 * times out against an empty `<body>`. `tests/msw/setup.ts` turns that into this test's own failure
 * and `@typescript-eslint/no-floating-promises` catches the static half.
 */

/** `sessionFixture()`'s current organization, and the namespace every key here must carry. */
const ORG_A = '01JORGAAAAAAAAAAAAAAAAAAAA';
/** The other ACTIVE membership in the shared fixture. A one-organization fixture cannot fail a
 *  namespacing test. */
const ORG_B = '01JORGBBBBBBBBBBBBBBBBBBBB';

const BOT_ID = '01JBOTAAAAAAAAAAAAAAAAAAAA';

const botUrl = `${ORIGIN}/api/v1/organizations/${ORG_A}/bots/${BOT_ID}`;
const questionsUrl = `${botUrl}/starter-questions`;
const questionUrl = (id: string) => `${questionsUrl}/${id}`;

/**
 * The row as an OWNER receives it. Both instruction fields carry canary text, because a caller WITH
 * `bots.manage` really is sent them — and the read-only path must never render either, whatever it
 * was handed.
 */
const BOT: BotResource = {
  id: BOT_ID,
  public_bot_id: 'pb_support',
  name: 'Support bot',
  slug: 'support-bot',
  description: 'Answers billing and account questions.',
  welcome_message: 'Hello — ask me anything about your account.',
  placeholder_text: 'Ask about billing…',
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

const question = (
  index: number,
  text: string,
  id = `01JSQ${String(index)}AAAAAAAAAAAAAAAAAA`,
): BotStarterQuestionResource => ({
  id,
  question: text,
  sort_order: index,
  created_at: '2026-08-01T09:00:00+00:00',
  updated_at: '2026-08-01T09:00:00+00:00',
});

/** The collection endpoint is hit by BOTH the starter-questions card and the live preview, so it is
 *  installed in every test — the harness runs with `onUnhandledRequest: 'error'`, which under the
 *  service-worker transport answers `500 Request Handler Error` rather than rejecting. */
const questionsHandler = (rows: readonly BotStarterQuestionResource[] = []) =>
  http.get(questionsUrl, () => HttpResponse.json({ data: { starter_questions: rows } }));

let captured: QueryClient | null = null;
function CaptureClient() {
  const queryClient = useQueryClient();
  // In an EFFECT: `react-hooks/globals` is an error on reassigning a module-scope variable from a
  // render body.
  useEffect(() => {
    captured = queryClient;
  }, [queryClient]);
  return null;
}

const reportUnsaved = vi.fn<(panel: BotEditorPanelId, unsaved: boolean) => void>();

/**
 * The shell's context, assembled by hand. `orgKey` is the same builder `useOrgKey()` uses, so the
 * keys this spec asserts against are the ones production computes rather than a second spelling.
 */
function PanelInContext({
  bot,
  canManage,
}: {
  readonly bot: BotResource;
  readonly canManage: boolean;
}) {
  const orgId = useCurrentOrgId();

  // The SHELL gates on this: a panel mounts only inside a resolved query under a real organization,
  // and `useOrgKey()` throws rather than building a key from `undefined`.
  if (orgId === null) return null;

  return (
    <PanelProvider orgId={orgId} bot={bot} canManage={canManage}>
      <BotIdentityPanel />
    </PanelProvider>
  );
}

function PanelProvider({
  orgId,
  bot,
  canManage,
  children,
}: {
  readonly orgId: string;
  readonly bot: BotResource;
  readonly canManage: boolean;
  readonly children: ReactNode;
}) {
  const value = useMemo<BotEditorContextValue>(
    () => ({
      orgId,
      botId: bot.id,
      bot,
      botKey: orgKey(orgId, 'bots', bot.id),
      botsListKey: orgKey(orgId, 'bots'),
      canManage,
      reportUnsaved,
    }),
    [orgId, bot, canManage],
  );

  return <BotEditorContext.Provider value={value}>{children}</BotEditorContext.Provider>;
}

const renderPanel = ({ bot = BOT, canManage = true }: { bot?: BotResource; canManage?: boolean } = {}) =>
  render(
    <Providers>
      <SessionProvider>
        <CaptureClient />
        <PanelInContext bot={bot} canManage={canManage} />
      </SessionProvider>
    </Providers>,
  );

const cacheKeys = (): unknown[][] =>
  (captured?.getQueryCache().getAll() ?? []).map((query) => [...query.queryKey]);

beforeEach(() => {
  // `readCookie` reads the PAGE's cookie and MSW cannot set one for a cross-origin host from a
  // service worker, so without this every mutation takes the `refreshCsrfToken()` path.
  document.cookie = 'XSRF-TOKEN=test-token';
  captured = null;
  reportUnsaved.mockClear();
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

describe('the eight fields, and only the eight', () => {
  it('renders a labelled control for every member of BOT_IDENTITY_FIELDS', async () => {
    worker.use(questionsHandler());

    const screen = await renderPanel();

    // Every control has a VISIBLE label bound to it. A placeholder is not a label — it disappears
    // exactly when the user needs it.
    await expect.element(screen.getByLabelText('Name')).toHaveValue('Support bot');
    await expect.element(screen.getByLabelText('Handle')).toHaveValue('support-bot');
    await expect
      .element(screen.getByLabelText('Description'))
      .toHaveValue('Answers billing and account questions.');
    await expect
      .element(screen.getByLabelText('Welcome message'))
      .toHaveValue('Hello — ask me anything about your account.');
    await expect
      .element(screen.getByLabelText('Composer placeholder'))
      .toHaveValue('Ask about billing…');
    await expect
      .element(screen.getByLabelText('System instruction'))
      .toHaveValue('CANARY-SYSTEM-INSTRUCTION');
    await expect.element(screen.getByLabelText('Answer style')).toHaveValue('CANARY-ANSWER-STYLE');
    await expect.element(screen.getByLabelText('Brand colour')).toBeVisible();
    await expect.element(screen.getByLabelText('Highlight colour')).toBeVisible();
    // `theme.radius` is a Radix Select, which installs no `register` ref — hence the explicit id and
    // `htmlFor` rather than a `FormControl`-derived binding alone.
    await expect.element(screen.getByLabelText('Corner radius')).toBeVisible();
  });

  it('sends exactly this tab`s partition, so a save cannot rewrite another tab`s fields', async () => {
    const bodies: unknown[] = [];
    worker.use(
      questionsHandler(),
      http.patch(botUrl, async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ data: BOT });
      }),
    );

    const screen = await renderPanel();
    await screen.getByLabelText('Name').fill('Billing bot');
    await screen.getByRole('button', { name: 'Save changes' }).click();

    await vi.waitFor(() => {
      expect(bodies).toHaveLength(1);
    });

    const body = bodies[0] as Record<string, unknown>;
    // `botSettingsSchema` is a strictObject whose every field is optional, mirroring `sometimes` on
    // the PATCH, so a form seeded with ONE tuple parses to exactly that tuple. A panel seeded from
    // `botFormDefaults(bot)` wholesale would send all 25 and silently rewrite the retrieval knobs
    // another tab is mid-edit on.
    expect(Object.keys(body).sort()).toEqual([...BOT_IDENTITY_FIELDS].sort());
    expect(body.name).toBe('Billing bot');
    // The three shapes `reset(resource)` would have round-tripped, and the one the PATCH rules
    // `prohibited`.
    expect(body).not.toHaveProperty('id');
    expect(body).not.toHaveProperty('status');
    expect(body).not.toHaveProperty('retrieval_configuration_version');
    expect(body).not.toHaveProperty('dense_top_k');
  });

  it('clears a tenant colour by OMITTING the key rather than sending an empty string', async () => {
    const bodies: unknown[] = [];
    worker.use(
      questionsHandler(),
      http.patch(botUrl, async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ data: BOT });
      }),
    );

    const themed: BotResource = { ...BOT, theme: { primary: 'oklch(0.525 0.235 264)' } };
    const screen = await renderPanel({ bot: themed });

    await expect.element(screen.getByLabelText('Brand colour')).toHaveValue('oklch(0.525 0.235 264)');
    await screen.getByLabelText('Brand colour').fill('');
    await screen.getByRole('button', { name: 'Save changes' }).click();

    await vi.waitFor(() => {
      expect(bodies).toHaveLength(1);
    });

    // `ConvertEmptyStringsToNull` turns `""` into null before any rule runs and `theme.primary` is
    // not `nullable`, so `{primary: ""}` is a 422 on both sides. `theme` is replaced wholesale, so an
    // omitted member IS how a tenant colour goes back to the platform's.
    const body = bodies[0] as { theme?: Record<string, unknown> };
    expect(body.theme).toEqual({});
  });
});

describe('the management-only projection', () => {
  it('mounts no form at all without bots.manage, and says the two fields are hidden rather than empty', async () => {
    worker.use(questionsHandler());

    // The server's own projection: the KEYS stay and the values become null. A screen that read that
    // as "not set" would offer to fill in a field it is not allowed to see.
    const projected: BotResource = {
      ...BOT,
      system_instruction: null,
      answer_style_instruction: null,
    };

    const screen = await renderPanel({ bot: projected, canManage: false });

    await expect.element(screen.getByText('How it is told to answer')).toBeVisible();
    expect(document.body.textContent).toContain('are hidden from this view');
    expect(document.body.textContent).toContain('not empty');

    // NO FORM. A disabled input holding a value is a control an operator will keep clicking, and RHF
    // strips disabled names from a submit — so the "harmless" version of that mistake is a save that
    // silently drops half the body.
    expect(screen.getByRole('textbox').elements()).toHaveLength(0);
    expect(screen.getByRole('button', { name: 'Save changes' }).elements()).toHaveLength(0);
  });

  it('never renders a withheld instruction, even if the row somehow carries one', async () => {
    worker.use(questionsHandler());

    // The canary values are the OWNER's fixture. `canManage: false` is the whole condition, so a
    // read-only path that reached for the field at all fails here rather than in production against
    // a server that had not yet shipped the projection.
    await renderPanel({ bot: BOT, canManage: false });

    await vi.waitFor(() => {
      expect(document.body.textContent).toContain('are hidden from this view');
    });
    expect(document.body.textContent).not.toContain('CANARY-SYSTEM-INSTRUCTION');
    expect(document.body.textContent).not.toContain('CANARY-ANSWER-STYLE');
  });

  it('shows an empty control for a null instruction WITH bots.manage, because there null is unset', async () => {
    worker.use(questionsHandler());

    const unset: BotResource = { ...BOT, system_instruction: null, answer_style_instruction: null };
    const screen = await renderPanel({ bot: unset, canManage: true });

    // The same `null`, the opposite meaning — which is the whole reason the two paths are different
    // components rather than one with a flag.
    await expect.element(screen.getByLabelText('System instruction')).toHaveValue('');
    expect(document.body.textContent).not.toContain('are hidden from this view');
  });
});

describe('where a 422 lands', () => {
  it('puts the slug uniqueness refusal under the handle input, not in the banner', async () => {
    worker.use(
      questionsHandler(),
      http.patch(botUrl, () =>
        HttpResponse.json(
          envelope('validation', {
            errors: { slug: ['A bot with this handle already exists in this organization.'] },
          }),
          { status: 422 },
        ),
      ),
    );

    const screen = await renderPanel();
    await screen.getByLabelText('Handle').fill('support-bot');
    await screen.getByRole('button', { name: 'Save changes' }).click();

    // Uniqueness is per organization and is NOT in `rules()` — an unscoped `unique:` would be an
    // existence oracle over the whole platform rendered as a validation error — so it arrives as an
    // ordinary per-field 422 that `botPanelKnownPaths` routes under this control.
    await expect
      .element(screen.getByText('A bot with this handle already exists in this organization.'))
      .toBeVisible();
    await expect.element(screen.getByLabelText('Handle')).toHaveAttribute('aria-invalid', 'true');
    // Not the banner.
    expect(screen.getByText('This bot could not be saved').elements()).toHaveLength(0);
    // Validation MESSAGES are Laravel-translated end-user copy and are shown verbatim; the
    // envelope's own `message` never is, and this fixture's copy names an internal host.
    expect(document.body.textContent).not.toContain('api-7.internal');
  });

  it('routes a 422 on a field outside this partition to the banner', async () => {
    worker.use(
      questionsHandler(),
      http.patch(botUrl, () =>
        HttpResponse.json(
          envelope('validation', {
            errors: {
              consent_text: ['Consent text is required when end-user data is collected.'],
            },
          }),
          { status: 422 },
        ),
      ),
    );

    const screen = await renderPanel();
    await screen.getByLabelText('Name').fill('Billing bot');
    await screen.getByRole('button', { name: 'Save changes' }).click();

    // Two rules on this endpoint are evaluated against the STORED row rather than the body, so they
    // can 422 on a field the operator did not send and is not looking at. A banner is the only honest
    // place for that; `setError` against a name that displays nowhere is a save where the server
    // rejects, nothing changes on screen, and the operator clicks again.
    await expect.element(screen.getByText('This bot could not be saved')).toBeVisible();
    await expect
      .element(screen.getByText('Consent text is required when end-user data is collected.'))
      .toBeVisible();
  });

  it('renders a class-mapped sentence and the request_id for a 403, never the envelope message', async () => {
    worker.use(
      questionsHandler(),
      http.patch(botUrl, () =>
        HttpResponse.json(envelope('authorization'), { status: 403 }),
      ),
    );

    const screen = await renderPanel();
    await screen.getByLabelText('Name').fill('Billing bot');
    await screen.getByRole('button', { name: 'Save changes' }).click();

    // `canManage` is an AFFORDANCE, never authorization: Laravel answers 403 whatever the panel
    // rendered, and a role that changed under a cached session shows up as this rather than as a
    // silently missing control.
    await expect.element(screen.getByText('You do not have access to this.')).toBeVisible();
    expect(document.body.textContent).toContain('01JREQFROMLARAVEL');
    expect(document.body.textContent).not.toContain('api-7.internal');
  });
});

describe('the unsaved-edit report the shell reads', () => {
  it('reports the identity form`s own dirtiness', async () => {
    worker.use(questionsHandler());

    const screen = await renderPanel();
    await expect.element(screen.getByLabelText('Name')).toHaveValue('Support bot');
    expect(reportUnsaved).toHaveBeenLastCalledWith('identity', false);

    await screen.getByLabelText('Name').fill('Billing bot');

    await vi.waitFor(() => {
      expect(reportUnsaved).toHaveBeenLastCalledWith('identity', true);
    });
  });

  it('folds a half-typed starter question into the SAME report', async () => {
    worker.use(questionsHandler([question(0, 'How do I change my plan?')]));

    const screen = await renderPanel();
    await expect.element(screen.getByLabelText('Add a starter question')).toBeVisible();
    expect(reportUnsaved).toHaveBeenLastCalledWith('identity', false);

    // The starter questions are a separate resource but they are unmounted by the SAME tab change,
    // and the shell's registry takes one boolean per panel — so a second `useUnsavedBotEdits`
    // reporter would be a second writer for one key and the last effect to run would win.
    await screen.getByLabelText('Add a starter question').fill('Where are my invoices?');

    await vi.waitFor(() => {
      expect(reportUnsaved).toHaveBeenLastCalledWith('identity', true);
    });
  });
});

describe('the starter questions, which are a child collection rather than a field', () => {
  it('keys the collection on the organization even though the URL never carries one', async () => {
    worker.use(questionsHandler([question(0, 'How do I change my plan?')]));

    const screen = await renderPanel();
    await expect.element(screen.getByText('How do I change my plan?').first()).toBeVisible();

    await vi.waitFor(() => {
      expect(cacheKeys()).toContainEqual(['org', ORG_A, 'bots', BOT_ID, 'starter-questions']);
    });
    // `['session']` is the ONE legal exception to the org prefix — it is the PRODUCER of `orgId`.
    expect(cacheKeys().filter((key) => key[0] !== 'org')).toEqual([['session']]);
    expect(JSON.stringify(cacheKeys())).not.toContain(ORG_B);
  });

  it('shares one fetch between the collection card and the live preview', async () => {
    let gets = 0;
    worker.use(
      http.get(questionsUrl, () => {
        gets += 1;
        return HttpResponse.json({ data: { starter_questions: [question(0, 'Chip one')] } });
      }),
    );

    const screen = await renderPanel();
    await expect.element(screen.getByText('Chip one').first()).toBeVisible();

    // Two components read the same collection; two `useQuery` calls with the same key share one
    // fetch. Keys spelled out separately would be one typo away from two cache entries, one of which
    // never invalidates.
    await vi.waitFor(() => {
      expect(gets).toBe(1);
    });
    expect(screen.getByText('Chip one').elements()).toHaveLength(2);
  });

  it('renders a first-run empty state rather than a blank pane', async () => {
    worker.use(questionsHandler([]));

    const screen = await renderPanel();

    // There is no FILTERED empty here — no search, no sort, no page — so there is nothing to
    // distinguish this from, and inventing one would be a component nothing can reach.
    await expect.element(screen.getByText('No starter questions yet')).toBeVisible();
    expect(document.body.textContent).toContain('which is a legitimate configuration');
  });

  it('renders the error state`s class-mapped sentence, and names no role for an authorization failure', async () => {
    worker.use(
      http.get(questionsUrl, () =>
        HttpResponse.json(envelope('authorization'), { status: 404 }),
      ),
    );

    const screen = await renderPanel();

    // `index` demands `bots.view`, which all four roles hold, so this is never a role gap — it is a
    // bot this session can no longer address, which 404s at binding time and renders as
    // `authorization` by the deny split. Naming a role would point at the wrong door.
    await expect
      .element(screen.getByText('Starter questions could not be loaded'))
      .toBeVisible();
    expect(document.body.textContent).toContain('You do not have access to this.');
    expect(document.body.textContent).not.toContain('role to manage this');
    expect(document.body.textContent).not.toContain('api-7.internal');
  });

  it('appends a new question with no position field, then re-reads the list', async () => {
    let gets = 0;
    const posted: unknown[] = [];
    worker.use(
      http.get(questionsUrl, () => {
        gets += 1;
        return HttpResponse.json({ data: { starter_questions: [] } });
      }),
      http.post(questionsUrl, async ({ request }) => {
        posted.push(await request.json());
        return HttpResponse.json({ data: question(0, 'Where are my invoices?') }, { status: 201 });
      }),
    );

    const screen = await renderPanel();
    await screen.getByLabelText('Add a starter question').fill('Where are my invoices?');
    await screen.getByRole('button', { name: 'Add' }).click();

    await vi.waitFor(() => {
      expect(posted).toHaveLength(1);
    });
    // THE POSITION IS NOT A REQUEST FIELD: a new question is appended by the server, which is the
    // only position that cannot collide, and "0..n-1 with no gaps" is an invariant a client must not
    // be able to name.
    expect(posted[0]).toEqual({ question: 'Where are my invoices?' });

    await vi.waitFor(() => {
      expect(gets).toBeGreaterThan(1);
    });
  });

  it('puts the full-list refusal under the add input, because the server keys it `question`', async () => {
    worker.use(
      questionsHandler([]),
      http.post(questionsUrl, () =>
        HttpResponse.json(
          envelope('validation', {
            errors: { question: ['This bot already has the maximum of 6 starter questions.'] },
          }),
          { status: 422 },
        ),
      ),
    );

    const screen = await renderPanel();
    await screen.getByLabelText('Add a starter question').fill('A seventh one');
    await screen.getByRole('button', { name: 'Add' }).click();

    await expect
      .element(screen.getByText('This bot already has the maximum of 6 starter questions.'))
      .toBeVisible();
    await expect
      .element(screen.getByLabelText('Add a starter question'))
      .toHaveAttribute('aria-invalid', 'true');
  });

  it('hides the add form at the ceiling and says what the ceiling is', async () => {
    worker.use(
      questionsHandler(
        Array.from({ length: 6 }, (_, index) => question(index, `Question ${String(index)}`)),
      ),
    );

    const screen = await renderPanel();

    await expect
      .element(screen.getByText('This bot has the maximum of 6 starter questions'))
      .toBeVisible();
    expect(screen.getByLabelText('Add a starter question').elements()).toHaveLength(0);
  });

  it('sends a move as `sort_order` alone and re-reads rather than splicing', async () => {
    let gets = 0;
    const patched: unknown[] = [];
    const rows = [question(0, 'First chip'), question(1, 'Second chip')];

    worker.use(
      http.get(questionsUrl, () => {
        gets += 1;
        return HttpResponse.json({ data: { starter_questions: rows } });
      }),
      http.patch(questionUrl(rows[1]!.id), async ({ request }) => {
        patched.push(await request.json());
        return HttpResponse.json({ data: { ...rows[1]!, sort_order: 0 } });
      }),
    );

    const screen = await renderPanel();
    await screen.getByRole('button', { name: 'Move “Second chip” up' }).click();

    await vi.waitFor(() => {
      expect(patched).toHaveLength(1);
    });
    // ONE FIELD. `question` and `sort_order` are each other's `required_without` and the FormRequest
    // carries no `sometimes`, so a body with exactly one of them is the legitimate shape — and
    // re-sending the text would make a reorder indistinguishable from an edit in the audit trail.
    expect(patched[0]).toEqual({ sort_order: 0 });

    // The server re-sequences the WHOLE list inside one transaction, so the response is the single
    // edited row while every other row has moved. A client-side splice desynchronises against that.
    await vi.waitFor(() => {
      expect(gets).toBeGreaterThan(1);
    });
  });

  it('sends a rename as `question` alone, so a stale position cannot become a move', async () => {
    const patched: unknown[] = [];
    const rows = [question(0, 'First chip'), question(1, 'Second chip')];

    worker.use(
      questionsHandler(rows),
      http.patch(questionUrl(rows[1]!.id), async ({ request }) => {
        patched.push(await request.json());
        return HttpResponse.json({ data: { ...rows[1]!, question: 'Second chip, reworded' } });
      }),
    );

    const screen = await renderPanel();
    await screen.getByRole('button', { name: 'Edit “Second chip”' }).click();
    await screen.getByLabelText('Question 2').fill('Second chip, reworded');
    await screen.getByRole('button', { name: 'Save question' }).click();

    await vi.waitFor(() => {
      expect(patched).toHaveLength(1);
    });
    // `sort_order` on this endpoint means "move this to position N". A rename seeded with the
    // position the row held when the form OPENED would move it if the list changed underneath.
    expect(patched[0]).toEqual({ question: 'Second chip, reworded' });
  });

  it('confirms a removal without demanding the question be typed back', async () => {
    let deleted = 0;
    const rows = [question(0, 'First chip')];

    worker.use(
      questionsHandler(rows),
      http.delete(questionUrl(rows[0]!.id), () => {
        deleted += 1;
        // NEVER a 204: `browserFetch` short-circuits 204/205 before parsing, and this endpoint
        // answers 200 with an acknowledgement body.
        return HttpResponse.json({ data: { acknowledged: true } });
      }),
    );

    const screen = await renderPanel();
    await screen.getByRole('button', { name: 'Remove “First chip”' }).click();

    const dialog = screen.getByRole('dialog');
    await expect.element(dialog).toBeVisible();
    // NOT the typed confirmation: that one is right for an irreversible delete and absurd for a chip
    // label that takes five seconds to retype. The consequence is still stated in specifics.
    expect(dialog.elements()[0]?.textContent).toContain('moves up one place');
    await dialog.getByRole('button', { name: 'Remove question' }).click();

    await vi.waitFor(() => {
      expect(deleted).toBe(1);
    });
  });

  it('offers no write control at all to a reader without bots.manage', async () => {
    worker.use(questionsHandler([question(0, 'First chip')]));

    const screen = await renderPanel({ canManage: false });
    await expect.element(screen.getByText('First chip').first()).toBeVisible();

    // A user who cannot perform an action does not see a disabled button for it. Hide it — a
    // disabled control with no explanation is a puzzle, and one with an explanation is a permissions
    // disclosure.
    expect(screen.getByRole('button', { name: 'Remove “First chip”' }).elements()).toHaveLength(0);
    expect(screen.getByRole('button', { name: 'Edit “First chip”' }).elements()).toHaveLength(0);
    expect(screen.getByLabelText('Add a starter question').elements()).toHaveLength(0);
  });
});

describe('the preview', () => {
  /**
   * SCOPED BY `data-slot`, and not by an accessible-name query, for a reason this file discovered the
   * hard way: `getByRole('button', {name: 'How do I change my plan?'})` matches FOUR controls, because
   * the row's own buttons are labelled `Move “How do I change my plan?” up` and so on. Every locator
   * that names the previewed text also names the controls that act on it, so the container is the only
   * unambiguous handle.
   */
  const preview = (): Element | null => document.querySelector('[data-slot="bot-preview"]');

  it('draws the welcome message, the chips and the placeholder inside the preview', async () => {
    worker.use(questionsHandler([question(0, 'How do I change my plan?')]));

    const screen = await renderPanel();
    await expect.element(screen.getByText('Save changes')).toBeVisible();

    await vi.waitFor(() => {
      expect(preview()?.textContent).toContain('Hello — ask me anything about your account.');
    });
    expect(preview()?.textContent).toContain('Ask about billing…');
    expect(preview()?.textContent).toContain('How do I change my plan?');
    expect(preview()?.textContent).toContain('Support bot');
  });

  it('puts nothing focusable in the preview', async () => {
    worker.use(questionsHandler([question(0, 'How do I change my plan?')]));

    const screen = await renderPanel();
    await expect.element(screen.getByText('Save changes')).toBeVisible();

    // A preview containing real controls puts stops in the tab order that do nothing when activated,
    // which is worse for a keyboard user than no preview at all.
    await vi.waitFor(() => {
      expect(preview()).not.toBeNull();
    });
    expect(
      preview()?.querySelectorAll('a, button, input, textarea, select, [tabindex]'),
    ).toHaveLength(0);
  });

  it('follows the form as a value is typed, rather than the row that was fetched', async () => {
    worker.use(questionsHandler([]));

    const screen = await renderPanel();
    await vi.waitFor(() => {
      expect(preview()?.textContent).toContain('Hello — ask me anything about your account.');
    });

    await screen.getByLabelText('Welcome message').fill('Hi! What can I look up for you?');

    // The point of previewing rather than describing: five of this tab's fields are invisible on the
    // tab itself, and an operator should not learn what a welcome message looks like from a customer.
    await vi.waitFor(() => {
      expect(preview()?.textContent).toContain('Hi! What can I look up for you?');
    });
  });
});
