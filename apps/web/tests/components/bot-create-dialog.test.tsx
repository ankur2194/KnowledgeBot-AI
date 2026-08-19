import type { BotResource, SessionResource } from '@kb/contracts';
import type { QueryClient } from '@tanstack/react-query';
import { useQueryClient } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import type * as NextNavigation from 'next/navigation';
import { useEffect } from 'react';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Providers } from '@/components/providers';
import { SessionProvider } from '@/features/auth/session-provider';
import { CreateBotDialog } from '@/features/bots/bot-create-dialog';

import { envelope, ORIGIN, sessionFixture } from '../msw/handlers';
import { worker } from '../msw/setup';
import { navigationCalls, resetNavigation, useMockRouter } from '../support/mock-navigation';

/**
 * The create path — `POST .../bots`, then straight to the editor.
 *
 * ── `next/navigation` IS MOCKED BECAUSE THE NAVIGATION IS THE FEATURE ────────────────────────────
 * `useRouter()` throws outside a mounted App Router, and here the push is not incidental: a new bot
 * is a draft with no model, no sources and no voice, so the next act is always configuring it. A spy
 * that swallowed the href would leave the most important assertion in this file unmakeable.
 * `usePathname`/`useSearchParams` are deliberately NOT mocked — this component reads neither, and
 * mocking a hook nothing calls would hide that fact.
 *
 * ── WHAT THIS SPEC MAY NOT CLAIM ─────────────────────────────────────────────────────────────────
 * Nothing about isolation, and nothing about authorization. `canManageBots` decides whether the
 * control RENDERS, which is an affordance; Laravel answers 403 whatever it returns, and proving that
 * needs a real server.
 */
vi.mock('next/navigation', async (importOriginal) => ({
  ...(await importOriginal<typeof NextNavigation>()),
  useRouter: () => useMockRouter(),
}));

/** `sessionFixture()`'s current organization — where the viewer is an OWNER. */
const ORG_A = '01JORGAAAAAAAAAAAAAAAAAAAA';

const botsUrl = (orgId: string) => `${ORIGIN}/api/v1/organizations/${orgId}/bots`;

const CREATED: BotResource = {
  id: '01JBOTNEWAAAAAAAAAAAAAAAAA',
  public_bot_id: 'pb_new',
  name: 'Support bot',
  slug: 'support-bot',
  description: null,
  welcome_message: null,
  placeholder_text: null,
  system_instruction: null,
  answer_style_instruction: null,
  instructions_visible: true,
  status: 'draft',
  access_mode: 'private',
  provider_connection_id: null,
  provider_model_id: null,
  answer_mode: 'strict',
  dense_top_k: 20,
  sparse_top_k: 20,
  rerank_candidates: 20,
  rerank_retain: 6,
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
  created_at: '2026-08-19T09:00:00+00:00',
  updated_at: '2026-08-19T09:00:00+00:00',
};

/** The same person, an ANALYST here and an owner elsewhere — the case a single-role fixture hides. */
const roleInCurrentOrg = (role: SessionResource['organizations'][number]['role']) => {
  const base = sessionFixture();
  return {
    ...base,
    organizations: base.organizations.map((organization) =>
      organization.id === ORG_A ? { ...organization, role } : organization,
    ),
  };
};

let captured: QueryClient | null = null;
function CaptureClient() {
  const queryClient = useQueryClient();
  useEffect(() => {
    captured = queryClient;
  }, [queryClient]);
  return null;
}

const renderDialog = (triggerLabel = 'Add bot') =>
  render(
    <Providers>
      <SessionProvider>
        <CaptureClient />
        <CreateBotDialog triggerLabel={triggerLabel} />
      </SessionProvider>
    </Providers>,
  );

beforeEach(() => {
  // MSW cannot set a cookie for a cross-origin host from a service worker, so without this every spec
  // takes the `refreshCsrfToken()` path and fails for a reason unrelated to what it is about.
  document.cookie = 'XSRF-TOKEN=test-token';
  captured = null;
  resetNavigation();
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

describe('the control is an affordance and renders only where it could work', () => {
  it('renders for an owner', async () => {
    const screen = await renderDialog();
    await expect.element(screen.getByRole('button', { name: 'Add bot' })).toBeVisible();
  });

  it('renders nothing for a viewer without bots.manage', async () => {
    // ADR-056: `bots.view` is held by all four roles and `bots.manage` by owner and admin. An analyst
    // can read the list and create nothing on it, so the empty state offers a sentence and no dead
    // control — which is why this returns null rather than rendering a disabled button.
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () =>
        HttpResponse.json({ data: roleInCurrentOrg('analyst') }),
      ),
    );

    const screen = await renderDialog();
    await vi.waitFor(() => {
      expect(captured?.getQueryData(['session'])).toBeDefined();
    });
    expect(screen.getByRole('button', { name: 'Add bot' }).elements()).toHaveLength(0);
  });

  it('renders nothing when the session has no current organization', async () => {
    // Reachable BY DESIGN: login succeeds with `current_organization_id: null` for a user whose
    // memberships are all `invited` or `suspended`. A create control that cannot address a tenant is
    // a control whose every use 404s — and `orgKey(undefined, …)` would be ONE cache namespace shared
    // by every org-less state on the platform.
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () =>
        HttpResponse.json({ data: { ...sessionFixture(), current_organization_id: null } }),
      ),
    );

    const screen = await renderDialog();
    await vi.waitFor(() => {
      expect(captured?.getQueryData(['session'])).toBeDefined();
    });
    expect(screen.getByRole('button', { name: 'Add bot' }).elements()).toHaveLength(0);
  });

  it('takes its trigger label from the caller, so two mounted triggers stay addressable', async () => {
    // Both are on screen at once in the first-run empty state — the page header's and the empty
    // state's. Role- and label-name matching is a case-insensitive SUBSTRING in both Playwright and
    // vitest-browser, so neither name may contain the other.
    const screen = await renderDialog('Create your first bot');
    await expect
      .element(screen.getByRole('button', { name: 'Create your first bot' }))
      .toBeVisible();
    expect('Create your first bot'.toLowerCase().includes('add bot')).toBe(false);
    expect('Add bot'.toLowerCase().includes('create your first bot')).toBe(false);
  });
});

describe('the POST, and where it lands', () => {
  it('sends the two typed fields under the schema`s own defaults, then opens the editor', async () => {
    const bodies: unknown[] = [];
    worker.use(
      http.post(botsUrl(ORG_A), async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ data: CREATED }, { status: 201 });
      }),
    );

    const screen = await renderDialog();
    await screen.getByRole('button', { name: 'Add bot' }).click();

    const dialog = screen.getByRole('dialog');
    await dialog.getByLabelText('Name').fill('Support bot');
    await dialog.getByLabelText('Handle').fill('support-bot');
    await dialog.getByRole('button', { name: 'Save bot' }).click();

    await vi.waitFor(() => {
      expect(bodies).toHaveLength(1);
    });

    const body = bodies[0] as Record<string, unknown>;
    expect(body.name).toBe('Support bot');
    expect(body.slug).toBe('support-bot');
    // A blank textarea becomes `null`, not `""`: `TrimStrings` then `ConvertEmptyStringsToNull` run
    // before any rule, so the server would store null anyway and `bots_text_not_blank` refuses the
    // empty string for every writer that is not an HTTP request.
    expect(body.description).toBeNull();
    // THE REST OF THE BODY IS `botCreateDefaults()`. Re-sending a value the server would have
    // defaulted to is free — `retrieval_configuration_version` moves only when a knob's VALUE
    // changes — and `rerank_retain`'s band starts at 6, so a blank control would be outside the
    // accepted range on a field this dialog does not render.
    expect(body.rerank_retain).toBe(6);
    expect(body.dense_top_k).toBe(20);
    // NO `status`. A bot is created `draft`, always: `StoreBotRequest` declares no rule for it and
    // `botCreateSchema` is a `strictObject`, so sending one is a parse failure rather than a silent
    // strip.
    expect(body).not.toHaveProperty('status');
    // …and no ownership column, which is unrepresentable in the schema by construction.
    expect(body).not.toHaveProperty('organization_id');

    // THE POINT OF THE CREATE PATH: a new bot is a draft with nothing configured, so this lands on
    // the editor rather than on a list row the operator then has to find. `push`, not `replace`, so
    // Back returns to the list they came from.
    await vi.waitFor(() => {
      expect(navigationCalls()).toEqual([
        { href: `/bots/${CREATED.id}`, history: 'push', scroll: undefined },
      ]);
    });
  });

  it('invalidates the whole list prefix rather than one page`s key', async () => {
    worker.use(
      http.post(botsUrl(ORG_A), () => HttpResponse.json({ data: CREATED }, { status: 201 })),
    );

    const screen = await renderDialog();
    // Two cache entries for two different views of the same list. A new bot can land on either under
    // `id` ascending, so invalidating the single key the operator happens to be looking at would
    // leave the other stale for the moment they page or search.
    captured?.setQueryData(['org', ORG_A, 'bots', { page: '1' }], { rows: [], rowCount: 0 });
    captured?.setQueryData(['org', ORG_A, 'bots', { page: '2' }], { rows: [], rowCount: 0 });

    await screen.getByRole('button', { name: 'Add bot' }).click();
    const dialog = screen.getByRole('dialog');
    await dialog.getByLabelText('Name').fill('Support bot');
    await dialog.getByLabelText('Handle').fill('support-bot');
    await dialog.getByRole('button', { name: 'Save bot' }).click();

    await vi.waitFor(() => {
      const stale = (captured?.getQueryCache().getAll() ?? [])
        .filter((query) => query.queryKey[2] === 'bots')
        .filter((query) => query.isStaleByTime(Number.POSITIVE_INFINITY));
      expect(stale.length).toBeGreaterThanOrEqual(2);
    });
  });
});

describe('the 422s', () => {
  it('puts the org-scoped slug conflict under the Handle field, not in the banner', async () => {
    // Uniqueness is enforced in `BotService`, not in `rules()`: it is per ORGANIZATION, and an
    // unscoped `unique:` rule would be an existence oracle over the whole platform rendered as a
    // validation error on a form. It still arrives as an ordinary per-field 422, and it has to land
    // on the control — routed to the banner it reads as a general failure and the operator resubmits
    // the same handle.
    worker.use(
      http.post(botsUrl(ORG_A), () =>
        HttpResponse.json(
          envelope('validation', {
            errors: { slug: ['A bot with this handle already exists in this organization.'] },
          }),
          { status: 422 },
        ),
      ),
    );

    const screen = await renderDialog();
    await screen.getByRole('button', { name: 'Add bot' }).click();

    const dialog = screen.getByRole('dialog');
    await dialog.getByLabelText('Name').fill('Support bot');
    await dialog.getByLabelText('Handle').fill('support-bot');
    await dialog.getByRole('button', { name: 'Save bot' }).click();

    await expect
      .element(screen.getByText('A bot with this handle already exists in this organization.'))
      .toBeVisible();
    // Validation MESSAGES are Laravel-translated end-user copy and are shown verbatim; the envelope's
    // own `message` never is — this fixture's copy names an internal host, so a spec that rendered it
    // fails here.
    await expect.element(dialog.getByLabelText('Handle')).toHaveAttribute('aria-invalid', 'true');
    expect(document.body.textContent).not.toContain('api-7.internal');
    // The dialog stays open on a 422: closing it would discard everything the operator typed.
    await expect.element(dialog).toBeVisible();
    expect(navigationCalls()).toEqual([]);
  });

  it('routes a 422 on a field this dialog does not render to the banner', async () => {
    // The dialog POSTs twenty-five fields and renders three. A key on one of the other twenty-two has
    // no control to land on, and writing it to a field that displays nowhere is a save where the
    // server rejects, nothing changes on screen, and the operator clicks again.
    worker.use(
      http.post(botsUrl(ORG_A), () =>
        HttpResponse.json(
          envelope('validation', {
            errors: {
              evidence_threshold: ['An evidence threshold and its scale are meaningless apart.'],
            },
          }),
          { status: 422 },
        ),
      ),
    );

    const screen = await renderDialog();
    await screen.getByRole('button', { name: 'Add bot' }).click();

    const dialog = screen.getByRole('dialog');
    await dialog.getByLabelText('Name').fill('Support bot');
    await dialog.getByLabelText('Handle').fill('support-bot');
    await dialog.getByRole('button', { name: 'Save bot' }).click();

    await expect
      .element(screen.getByText('An evidence threshold and its scale are meaningless apart.'))
      .toBeVisible();
    await expect
      .element(dialog.getByLabelText('Handle'))
      .not.toHaveAttribute('aria-invalid', 'true');
  });

  it('renders a class-mapped sentence and the request id for a quota refusal', async () => {
    worker.use(
      http.post(botsUrl(ORG_A), () => HttpResponse.json(envelope('tenant_quota'), { status: 403 })),
    );

    const screen = await renderDialog();
    await screen.getByRole('button', { name: 'Add bot' }).click();

    const dialog = screen.getByRole('dialog');
    await dialog.getByLabelText('Name').fill('Support bot');
    await dialog.getByLabelText('Handle').fill('support-bot');
    await dialog.getByRole('button', { name: 'Save bot' }).click();

    // Branching is on `error_class`, never on the HTTP status: one class renders several statuses by
    // surface, and `tenant_quota` is a 403 that has nothing to do with a role.
    await expect
      .element(
        screen.getByText(
          /This organization has reached its plan limit for this action\. \(ref 01JREQFROMLARAVEL\)/,
        ),
      )
      .toBeVisible();
    expect(document.body.textContent).not.toContain('api-7.internal');
  });
});
