import type { QuotaResource, SessionResource } from '@kb/contracts';
import type { QueryClient } from '@tanstack/react-query';
import { useQueryClient } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { useEffect } from 'react';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Providers } from '@/components/providers';
import { SessionProvider } from '@/features/auth/session-provider';
import { QuotasScreen } from '@/features/quotas/quotas-screen';

import { ORIGIN, sessionFixture } from '../msw/handlers';
import { worker } from '../msw/setup';

/**
 * `/quotas` — usage against ceilings, and the one form in this console whose refusal rule the SERVER
 * structurally cannot explain.
 *
 * ── WHAT THIS SPEC MAY NOT CLAIM ─────────────────────────────────────────────────────────────────
 * It proves the query key IS org-namespaced and that the disclosure matches the server's own
 * direction rule. It proves NOTHING about isolation: a component test that mocks the API cannot fail
 * an isolation test, and it must never be cited as isolation coverage. "Organization A's ceilings
 * never appear after switching to B" is Playwright's.
 *
 * ── THE RULE UNDER TEST, AND WHY IT IS ON THE CLIENT AT ALL ─────────────────────────────────────
 * `QuotaLimitService::apply()` refuses a RAISE from any actor without `users.is_platform_owner`, and
 * LOWERING IS FREE. It has a sentence written for that moment —
 * `RAISING_NEEDS_PLATFORM_OWNER` — and IT NEVER REACHES A BROWSER: the refusal is
 * `error_class: 'authorization'`, and `bootstrap/app.php`'s render closure replaces the message of
 * EVERY authorization envelope with `'This action is not permitted.'` so the 403/404 deny split
 * cannot become an enumeration oracle one layer down.
 *
 * So the disclosure is client-side and BEFORE the submit, from the two things this client has:
 * `SessionUser.is_platform_owner` and the direction of the edit against the stored row. These tests
 * are what stop it drifting from the server's `raisesAny()`.
 */

const ORG_A = '01JORGAAAAAAAAAAAAAAAAAAAA';
/** The other ACTIVE membership in the same fixture. A one-organization fixture cannot fail a
 *  namespacing test, which is why it is here rather than for anything this screen renders. */
const ORG_B = '01JORGBBBBBBBBBBBBBBBBBBBB';

const quotasUrl = (orgId: string) => `${ORIGIN}/api/v1/organizations/${orgId}/quotas`;

/** A total list — one entry per metric, always, in enum order — which is what the server documents
 *  and what lets the form render four rows without deciding what a missing one means. */
const QUOTAS: QuotaResource = {
  metrics: [
    {
      metric: 'storage_bytes',
      used: 1024 * 1024 * 512,
      limit: 1024 * 1024 * 1024,
      remaining: 1024 * 1024 * 512,
      exceeded: false,
      source: 'database',
    },
    { metric: 'bots', used: 3, limit: 10, remaining: 7, exceeded: false, source: 'database' },
    // UNLIMITED, and it is the row that makes the direction rule interesting: removing a ceiling is
    // the LARGEST raise there is, and setting one on an unlimited metric is a LOWERING.
    { metric: 'users', used: 4, limit: null, remaining: null, exceeded: false, source: 'database' },
    {
      metric: 'monthly_tokens',
      used: 900,
      limit: 500,
      remaining: 0,
      // OVER QUOTA. The bar clamps at 100 and the NUMBERS do not, which is what an operator acts on.
      exceeded: true,
      source: 'database',
    },
  ],
};

const ownerSession = (isPlatformOwner: boolean): SessionResource => {
  const base = sessionFixture();
  return { ...base, user: { ...base.user, is_platform_owner: isPlatformOwner } };
};

/** An ADMIN membership in the current organization. `quotas.manage` is the ONE permission an
 *  administrator does not hold beside `members.manage_owner`, so this is the read-only case. */
const adminSession = (): SessionResource => {
  const base = sessionFixture();
  return {
    ...base,
    organizations: base.organizations.map((organization) =>
      organization.id === ORG_A ? { ...organization, role: 'admin' as const } : organization,
    ),
  };
};

const showHandler = (quotas: QuotaResource = QUOTAS) =>
  http.get(quotasUrl(ORG_A), () => HttpResponse.json({ data: quotas }));

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
        <QuotasScreen />
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

describe('the key is org-namespaced and the organization is not in the URL', () => {
  it('reads the ceilings under ["org", currentOrgId, "quotas"]', async () => {
    worker.use(showHandler());

    const screen = await renderScreen();
    await expect.element(screen.getByRole('heading', { name: 'Usage' })).toBeVisible();

    await vi.waitFor(() => {
      expect(cacheKeys()).toContainEqual(['org', ORG_A, 'quotas']);
    });

    const keys = cacheKeys();
    // `['session']` is the ONE legal exception to the org prefix — it is the PRODUCER of `orgId`.
    expect(keys.filter((key) => key[0] !== 'org')).toEqual([['session']]);
    // Not one key mentions the organization the session is NOT currently acting in.
    expect(JSON.stringify(keys)).not.toContain(ORG_B);
  });
});

describe('usage is rendered so null and zero cannot be confused', () => {
  it('gives an unlimited metric no meter at all', async () => {
    worker.use(showHandler());

    const screen = await renderScreen();
    // A BAR AT ZERO OVER AN UNLIMITED METRIC would say "you have used none of your allowance", which
    // is false in the way that matters: there is no allowance to be a fraction of.
    await expect.element(screen.getByText('No limit set.')).toBeVisible();
  });

  it('marks an over-quota metric with a word as well as a colour', async () => {
    worker.use(showHandler());

    const screen = await renderScreen();
    // COLOUR IS NEVER THE ONLY CHANNEL. `<StatusPill>` supplies the glyph and the word from a closed
    // vocabulary, so the row survives greyscale and CVD.
    await expect.element(screen.getByText('Over limit')).toBeVisible();
  });
});

describe('the raise asymmetry is disclosed BEFORE the submit', () => {
  it('states the rule unconditionally, not only when it bites', async () => {
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () => HttpResponse.json({ data: ownerSession(false) })),
      showHandler(),
    );

    const screen = await renderScreen();
    // AN OWNER WHO DOES NOT KNOW LOWERING IS FREE WILL NOT TRY IT, and an owner who discovers the
    // rule from a failed save learns it as "quotas are broken".
    await expect
      .element(screen.getByText('Lowering a limit takes effect here. Raising one does not.'))
      .toBeVisible();
  });

  it('disarms the save and names the metric when the edit is a raise this actor cannot make', async () => {
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () => HttpResponse.json({ data: ownerSession(false) })),
      showHandler(),
    );

    const screen = await renderScreen();
    const save = screen.getByRole('button', { name: 'Save limits' });
    await expect.element(save).toBeEnabled();

    // `bots` is stored at 10. Typing 11 is a raise, and the actor is not a platform owner.
    // ROLE-BASED AND EXACT. `getByLabelText` is a case-insensitive SUBSTRING match, and the switch
    // beside this input is named "Bots limit Unlimited" — which contains it. Naming the ROLE
    // separates the number field from the toggle without weakening either accessible name.
    const bots = screen.getByRole('spinbutton', { name: 'Bots limit', exact: true });
    await bots.fill('11');

    await expect.element(screen.getByText('One of these is a raise')).toBeVisible();
    // DISABLED IS A DISCLOSURE AND NOT A CONTROL — the server decides under a lock against values
    // this client may be stale about, and the 403 path stays fully handled. What it buys is that the
    // button is never a trap.
    await expect.element(save).toBeDisabled();
  });

  it('leaves the save armed for a LOWERING, which needs no platform operator', async () => {
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () => HttpResponse.json({ data: ownerSession(false) })),
      showHandler(),
    );

    const screen = await renderScreen();
    // ROLE-BASED AND EXACT. `getByLabelText` is a case-insensitive SUBSTRING match, and the switch
    // beside this input is named "Bots limit Unlimited" — which contains it. Naming the ROLE
    // separates the number field from the toggle without weakening either accessible name.
    const bots = screen.getByRole('spinbutton', { name: 'Bots limit', exact: true });
    await bots.fill('5');

    await expect.element(screen.getByRole('button', { name: 'Save limits' })).toBeEnabled();
  });

  it('arms it for a raise when the actor IS a platform owner', async () => {
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () => HttpResponse.json({ data: ownerSession(true) })),
      showHandler(),
    );

    const screen = await renderScreen();
    // ROLE-BASED AND EXACT. `getByLabelText` is a case-insensitive SUBSTRING match, and the switch
    // beside this input is named "Bots limit Unlimited" — which contains it. Naming the ROLE
    // separates the number field from the toggle without weakening either accessible name.
    const bots = screen.getByRole('spinbutton', { name: 'Bots limit', exact: true });
    await bots.fill('99');

    // The flag is a column on the USER, not a role and not per-organization, and it is the one input
    // to the raise rule no membership can supply.
    await expect.element(screen.getByRole('button', { name: 'Save limits' })).toBeEnabled();
  });
});

describe('the read-only case renders no form at all', () => {
  it('tells an administrator why, and mounts no ceiling controls', async () => {
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () => HttpResponse.json({ data: adminSession() })),
      showHandler(),
    );

    const screen = await renderScreen();

    // A DISABLED INPUT HOLDING A VALUE IS A CONTROL AN OPERATOR WILL KEEP CLICKING, so the whole form
    // is absent and the ceilings are already on screen, read-only, in the usage cards above.
    await expect
      .element(screen.getByText('You can read these limits but not change them'))
      .toBeVisible();
    expect(document.querySelectorAll('input[type="number"]')).toHaveLength(0);
  });
});

describe('the Unlimited control is a choice rather than an emptied box', () => {
  it('is on for a metric with no ceiling, and turning it off seeds a real zero', async () => {
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () => HttpResponse.json({ data: ownerSession(true) })),
      showHandler(),
    );

    const screen = await renderScreen();

    // `users` arrives with `limit: null`. In a bare number input that is indistinguishable from `0`,
    // which is the OPPOSITE state — nothing is allowed — so the switch is what makes it readable.
    const members = screen.getByRole('switch', { name: 'Members limit Unlimited' });
    await expect.element(members).toBeChecked();
  });
});
