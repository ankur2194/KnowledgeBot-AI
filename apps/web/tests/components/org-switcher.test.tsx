import type { SessionMembership } from '@kb/contracts';
import { http, HttpResponse } from 'msw';
import type * as NextNavigation from 'next/navigation';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Providers } from '@/components/providers';
import { OrgSwitcher } from '@/features/auth/org-switcher';
import { SessionProvider } from '@/features/auth/session-provider';

import { envelope, ORIGIN, sessionFixture } from '../msw/handlers';
import { worker } from '../msw/setup';

/**
 * The organization switcher.
 *
 * ── WHAT THIS SPEC MAY NOT CLAIM ─────────────────────────────────────────────────────────────────
 * It proves RENDERING and the request the control sends. It proves NOTHING about isolation: a
 * component test that mocks the API cannot fail an isolation test, and per the vitest-playwright
 * boundary table it must never be cited as isolation coverage. The claim "org A's rows never appear
 * after switching to org B, including during the in-flight window" is Playwright's and is recorded as
 * unproven by this batch. The ORDER of the five steps is asserted in tests/unit/switch-organization.test.ts.
 *
 * `next/navigation` is mocked because `useRouter()` throws outside a mounted App Router
 * ("invariant expected app router to be mounted"). Mocking it also makes steps 1 and 5 — the neutral
 * navigation and `router.refresh()` — observable here, which is the only reason it is worth doing.
 */

const push = vi.fn();
const refresh = vi.fn();

// `importOriginal` and a SPREAD, not a bare factory: a factory listing only the two hooks makes the
// mocked module miss every other export `next/navigation` has — including the `default` the pre-bundled
// dependency is re-exported through — and the spec then fails to IMPORT with
// "does not provide an export named 'default'" (measured) rather than failing an assertion.
vi.mock('next/navigation', async (importOriginal) => ({
  ...(await importOriginal<typeof NextNavigation>()),
  useRouter: () => ({ push, refresh }),
  // The switcher lives on `/settings`, which is what makes step 1 a no-op in production.
  usePathname: () => '/settings',
}));

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-token';
  push.mockClear();
  refresh.mockClear();
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

const ORG_A = '01JORGAAAAAAAAAAAAAAAAAAAA';
const ORG_B = '01JORGBBBBBBBBBBBBBBBBBBBB';

const renderSwitcher = () =>
  render(
    <Providers>
      <SessionProvider>
        <OrgSwitcher />
      </SessionProvider>
    </Providers>,
  );

/** Radix's Select trigger is a `<button role="combobox">`, so the role is combobox and not button. The
 *  NAME is the contract — the future Playwright spec looks the control up by it. */
const SWITCHER = { name: 'Switch organization' } as const;

describe('it offers one option per ACTIVE membership', () => {
  it('shows the current organization and both choices', async () => {
    const screen = await renderSwitcher();

    const trigger = screen.getByRole('combobox', SWITCHER);
    await expect.element(trigger).toHaveTextContent('Acme Research');

    await trigger.click();
    await expect.element(screen.getByRole('option', { name: 'Acme Research' })).toBeVisible();
    await expect.element(screen.getByRole('option', { name: 'Brightwater Legal' })).toBeVisible();
    expect(screen.getByRole('option').elements()).toHaveLength(2);
  });

  it('does NOT offer a suspended or invited membership', async () => {
    // Offering one would be offering a 403. The server sends them so the UI can EXPLAIN them, which the
    // provider's notice does — not so the switcher can list them.
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () =>
        HttpResponse.json({
          data: sessionFixture({
            organizations: [
              { id: ORG_A, name: 'Acme Research', slug: 'acme', role: 'owner', status: 'active' },
              {
                id: ORG_B,
                name: 'Brightwater Legal',
                slug: 'bw',
                role: 'analyst',
                status: 'active',
              },
              {
                id: '01JORGCCC',
                name: 'Cinder Co',
                slug: 'cinder',
                role: 'admin',
                status: 'suspended',
              },
              {
                id: '01JORGDDD',
                name: 'Dune Ltd',
                slug: 'dune',
                role: 'analyst',
                status: 'invited',
              },
            ],
          }),
        }),
      ),
    );

    const screen = await renderSwitcher();
    await screen.getByRole('combobox', SWITCHER).click();

    expect(screen.getByRole('option').elements()).toHaveLength(2);
    expect(screen.getByRole('option', { name: 'Cinder Co' }).elements()).toHaveLength(0);
    expect(screen.getByRole('option', { name: 'Dune Ltd' }).elements()).toHaveLength(0);
  });
});

describe('it renders NOTHING below two active memberships', () => {
  it('is absent for a single active membership', async () => {
    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () =>
        HttpResponse.json({
          data: sessionFixture({
            organizations: [
              { id: ORG_A, name: 'Acme Research', slug: 'acme', role: 'owner', status: 'active' },
            ],
          }),
        }),
      ),
    );

    const screen = await renderSwitcher();

    // A control that cannot do anything is a question the user has to answer, so there is no control at
    // all rather than a disabled one.
    await vi.waitFor(() => {
      expect(screen.getByRole('combobox').elements()).toHaveLength(0);
    });
  });

  it('is absent for a user with only inactive memberships', async () => {
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
    );

    const screen = await renderSwitcher();

    await vi.waitFor(() => {
      expect(screen.getByRole('combobox').elements()).toHaveLength(0);
    });
  });
});

describe('choosing an organization posts the id the server itself handed us', () => {
  it('POSTs {organization_id} and then refreshes the Router Cache', async () => {
    const bodies: unknown[] = [];
    worker.use(
      http.post(`${ORIGIN}/api/v1/session/organization`, async ({ request }) => {
        bodies.push(await request.json());
        return HttpResponse.json({ data: sessionFixture({ current_organization_id: ORG_B }) });
      }),
    );

    const screen = await renderSwitcher();
    await screen.getByRole('combobox', SWITCHER).click();
    await screen.getByRole('option', { name: 'Brightwater Legal' }).click();

    await vi.waitFor(() => {
      // `organization_id` is an ownership column, which is exactly why this is not a form and has no
      // Zod schema. It is legitimate here because this endpoint's subject IS the ownership relation, it
      // re-checks membership server-side, and the value came from the server's own membership list.
      expect(bodies).toEqual([{ organization_id: ORG_B }]);
    });

    // Step 5. The Router Cache lives in the tab and is keyed by path, and the organization is not in
    // the path — so a soft navigation would replay the previous organization's RSC payloads.
    await vi.waitFor(() => {
      expect(refresh).toHaveBeenCalledTimes(1);
    });
    // Step 1 is a no-op from `/settings`, which is the whole reason the control lives there.
    expect(push).not.toHaveBeenCalled();
  });
});

describe('a 403 is the "removed from the org you just picked" case', () => {
  it('renders the class-mapped sentence and stops offering the option that failed', async () => {
    let meCalls = 0;
    // FLIPPED BY THE 403, so what `/me` returns depends on whether the failed switch HAPPENED rather than
    // on how many times the session has been read. A read counter is the wrong discriminator here — see
    // the block above the final assertions.
    let removed = false;
    // Typed from the wire rather than `as const`: `SessionMembership` is what the server publishes, so a
    // role or status this fixture invents is a compile error instead of a green test about a shape that
    // cannot occur.
    const ACME: SessionMembership = {
      id: ORG_A,
      name: 'Acme Research',
      slug: 'acme',
      role: 'owner',
      status: 'active',
    };
    const CINDER: SessionMembership = {
      id: '01JORGCCC',
      name: 'Cinder Co',
      slug: 'cinder',
      role: 'admin',
      status: 'active',
    };

    worker.use(
      http.get(`${ORIGIN}/api/v1/me`, () => {
        meCalls += 1;
        return HttpResponse.json({
          data: sessionFixture({
            organizations: [
              ACME,
              // THE WHOLE POINT OF THE INVALIDATE, expressed as data: the membership this control was
              // rendered from is stale, and the fresh document says so. `suspended` rather than absent so
              // TWO active memberships remain and the switcher still renders — a single active membership
              // returns `null` and would take the alert down with it, testing something else entirely.
              {
                id: ORG_B,
                name: 'Brightwater Legal',
                slug: 'bw',
                role: 'analyst',
                status: removed ? 'suspended' : 'active',
              },
              CINDER,
            ],
          }),
        });
      }),
      http.post(`${ORIGIN}/api/v1/session/organization`, () => {
        removed = true;
        return HttpResponse.json(envelope('authorization'), { status: 403 });
      }),
    );

    const screen = await renderSwitcher();
    const trigger = screen.getByRole('combobox', SWITCHER);
    // The session has ARRIVED — asserted by what it rendered rather than by a request count.
    await expect.element(trigger).toHaveTextContent('Acme Research');
    const readsBeforeSwitch = meCalls;

    await trigger.click();
    // NEGATIVE CONTROL, and it is what stops the final assertion passing for the wrong reason: the stale
    // option IS on offer before the failed switch. Without it, an absence afterwards proves nothing — the
    // option could have been missing from the start, or the list could have failed to render at all.
    await expect.element(screen.getByRole('option', { name: 'Brightwater Legal' })).toBeVisible();
    expect(screen.getByRole('option').elements()).toHaveLength(3);

    await screen.getByRole('option', { name: 'Brightwater Legal' }).click();

    await expect
      .element(screen.getByRole('alert'))
      .toHaveTextContent('You do not have access to this.');

    // THE ONE PLACE AN ERROR TRIGGERS AN INVALIDATE — asserted by its RESULT, not by a request count.
    //
    // THIS USED TO BE `expect(meCalls).toBe(2)` INSIDE `vi.waitFor`, WHICH IS A ONE-WAY RATCHET: `waitFor`
    // retries until the assertion holds, so an exact count that OVERSHOOTS can never come back — one stray
    // session read burns the whole timeout and reports `expected 3 to be 2`. It is the same shape that made
    // the members-screen invite spec flaky (see that file, and `docs/22` § I4, where six candidate causes
    // are recorded as tested and refuted). This one had not failed yet, which is exactly what the other one
    // did for weeks first.
    //
    // What replaces it is what the invalidate is FOR, in the words of the hook's own comment — "otherwise
    // the user keeps picking an option that keeps failing". The refetched document no longer lists that
    // membership as active, so the option is gone; the popover is reopened first and the assertion is
    // inside the waiter, because the list re-renders under an open popover when the refetch lands. The
    // client cannot fake this: nothing in the 403 envelope names an organization, so only the server's
    // fresh membership list can remove an option.
    await trigger.click();
    await vi.waitFor(() => {
      expect(screen.getByRole('option', { name: 'Brightwater Legal' }).elements()).toHaveLength(0);
      expect(screen.getByRole('option').elements()).toHaveLength(2);
    });
    // Kept, and monotonic on purpose: it states the intent — the session was re-read after the failure —
    // in a form no additional read can invalidate. `toBeGreaterThan` never un-satisfies once true.
    expect(meCalls).toBeGreaterThan(readsBeforeSwitch);
    // Neither cache is touched on a failure: resetting would discard the error the user has to read.
    expect(refresh).not.toHaveBeenCalled();
    expect(document.body.textContent).not.toContain('api-7.internal');
  });
});
