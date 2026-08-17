import { KbError, type SessionMembership } from '@kb/contracts';
import type * as NextLink from 'next/link';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { CurrentOrgBadge } from '@/features/auth/current-org-badge';
import type { SessionState } from '@/features/auth/session';
import { SessionContext } from '@/features/auth/session-context';

import { sessionFixture } from '../msw/handlers';

/**
 * `next/link` IS REPLACED BY A PLAIN ANCHOR, and the reason is a harness limit rather than a choice.
 *
 * Mounting the real one throws `TypeError: Cannot read properties of null (reading 'useContext')` from
 * inside `next/link` — Vite pre-bundles that module against `node_modules/.pnpm/react@19.2.8` while the
 * renderer uses the React copy bundled into `vitest-browser-react`'s optimized dep, so there are two
 * React instances and the hook dispatcher is null in one of them. It is not a defect in the component:
 * `pnpm web:build` compiles and every other spec that asserts a link asserts a plain `<a>` because
 * every other link in this feature IS one.
 *
 * What this costs, stated rather than glossed: the spec proves the anchor's `href` and accessible name,
 * which is the whole of what this component decides. Prefetching and soft navigation are `next/link`'s
 * own behaviour, they belong to Playwright, and nothing here is cited as covering them.
 */
vi.mock('next/link', async (importOriginal) => ({
  // `importOriginal` + SPREAD, not a bare factory. The same rule the `next/navigation` mocks in this
  // suite follow: a factory returning only `{ default }` — with or without `__esModule: true` — is
  // handed to `import Link from 'next/link'` as the whole namespace object, and React reports
  // "Element type is invalid ... but got: object". Both spellings were tried; this is the one that
  // works.
  ...(await importOriginal<typeof NextLink>()),
  default: ({
    href,
    children,
    ...rest
  }: AnchorHTMLAttributes<HTMLAnchorElement> & { href: string; children: ReactNode }) => (
    <a href={href} {...rest}>
      {children}
    </a>
  ),
}));

/**
 * The nav-strip badge, across all four `useSession()` states plus the one state that is neither an
 * error nor a happy path: a session naming a `current_organization_id` whose membership is no longer
 * active.
 *
 * ── WHY IT DRIVES `SessionContext` DIRECTLY ──────────────────────────────────────────────────────
 * Same reason `unverified-banner.test.tsx` does: the contract is "given a SessionState, render or do
 * not render", the states include two that are timing- or fault-shaped, and `SessionProvider`'s own
 * behaviour belongs in its own spec.
 *
 * A component spec may not assert isolation, authentication or cross-route navigation (the
 * vitest-playwright boundary table), and nothing here is cited as coverage for any of them — including
 * the org-switch claim, which is Playwright's and is restated as UNPROVEN in the batch report.
 */

beforeEach(() => {
  document.cookie = 'XSRF-TOKEN=test-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

const ORG_ID = '01JORGAAAAAAAAAAAAAAAAAAAA';
const OTHER_ORG_ID = '01JORGBBBBBBBBBBBBBBBBBBBB';

const renderWith = (state: SessionState) =>
  render(
    <SessionContext.Provider value={state}>
      <CurrentOrgBadge />
    </SessionContext.Provider>,
  );

/** An `authenticated` state built from the shared fixture, with the membership list swapped. */
const authenticated = (
  organizations: readonly SessionMembership[],
  orgId: string | null = ORG_ID,
): SessionState => ({
  status: 'authenticated',
  user: sessionFixture().user,
  organizations,
  orgId,
});

/** Typed from @kb/contracts, so a server-side rename fails this typecheck rather than shipping. */
const membership = (
  id: string,
  name: string,
  status: SessionMembership['status'],
): SessionMembership => ({
  id,
  name,
  slug: name.toLowerCase().replaceAll(' ', '-'),
  role: 'analyst',
  status,
});

describe('the one state that renders', () => {
  it('shows the current organization name as a link to /settings', async () => {
    const screen = await renderWith(
      authenticated([membership(ORG_ID, 'Acme Research', 'active')]),
    );

    const link = screen.getByRole('link', {
      name: 'Current organization: Acme Research. Change it in settings.',
    });
    await expect.element(link).toHaveTextContent('Acme Research');
    await expect.element(link).toHaveAttribute('href', '/settings');
  });

  it('picks the CURRENT organization out of several, not the first one', async () => {
    // The list is every membership, in the server's order. Rendering `organizations[0]` would show a
    // different organization's name than the one the admin is acting in — a wrong label on the one
    // control whose whole job is to say which tenant you are in.
    const screen = await renderWith(
      authenticated(
        [
          membership(OTHER_ORG_ID, 'Brightwater Legal', 'active'),
          membership(ORG_ID, 'Acme Research', 'active'),
        ],
        ORG_ID,
      ),
    );

    await expect.element(screen.getByRole('link')).toHaveTextContent('Acme Research');
    expect(document.body.textContent).not.toContain('Brightwater Legal');
  });

  it('is NOT a second switcher — one link, no button, no listbox', async () => {
    // The switch is a five-step sequence whose first step is "navigate to a neutral shell", and this
    // component is mounted in the LAYOUT, on every route including the org-scoped ones. A control here
    // is the mounted-data-view race that step 1 exists to avoid.
    const screen = await renderWith(
      authenticated([membership(ORG_ID, 'Acme Research', 'active')]),
    );

    await expect.element(screen.getByRole('link')).toBeVisible();
    expect(screen.getByRole('button').elements()).toHaveLength(0);
    expect(screen.getByRole('combobox').elements()).toHaveLength(0);
    expect(screen.getByRole('listbox').elements()).toHaveLength(0);
  });
});

describe('a current organization whose membership is no longer active', () => {
  it('renders NOTHING rather than putting a ULID in the nav', async () => {
    // THE SPEC THIS FILE WAS WRITTEN FOR. The server sends the whole membership list precisely so the
    // client can see this: `current_organization_id` still names an organization the user was
    // suspended from. The tempting fallback — render `session.orgId` when the lookup misses — puts a
    // raw identifier in the chrome of every page, which is both meaningless to the reader and an
    // identifier we never chose to display.
    const screen = await renderWith(
      authenticated([membership(ORG_ID, 'Acme Research', 'suspended')], ORG_ID),
    );

    expect(screen.getByRole('link').elements()).toHaveLength(0);
    expect(document.body.textContent).not.toContain(ORG_ID);
    expect(document.body.textContent).not.toContain('Acme Research');
    // And no placeholder either: the nav is chrome the server rendered byte-identically for every
    // organization, so a "—" that turns into a name is a layout shift on every page load.
    expect(document.body.textContent?.trim()).toBe('');
  });

  it('renders nothing when the id names an organization absent from the list entirely', async () => {
    const screen = await renderWith(
      authenticated([membership(OTHER_ORG_ID, 'Brightwater Legal', 'active')], ORG_ID),
    );

    expect(screen.getByRole('link').elements()).toHaveLength(0);
    expect(document.body.textContent).not.toContain(ORG_ID);
    // Not the other organization's name either — a "nearest match" fallback would label the nav with a
    // tenant the admin is not acting in.
    expect(document.body.textContent).not.toContain('Brightwater Legal');
  });
});

describe('the three states that carry no current organization', () => {
  it('renders nothing while the session is loading', async () => {
    const screen = await renderWith({ status: 'loading' });
    expect(screen.getByRole('link').elements()).toHaveLength(0);
  });

  it('renders nothing for anonymous', async () => {
    const screen = await renderWith({ status: 'anonymous' });
    expect(screen.getByRole('link').elements()).toHaveLength(0);
  });

  it('renders nothing for unavailable, and never the envelope message', async () => {
    // A server fault is not an answer about this user, and `message` is operator-facing.
    const screen = await renderWith({
      status: 'unavailable',
      error: new KbError('internal_dependency', false, null, '01JREQ', 'operator detail'),
    });

    expect(screen.getByRole('link').elements()).toHaveLength(0);
    expect(document.body.textContent).not.toContain('operator detail');
  });

  it('renders nothing for an authenticated user with orgId null', async () => {
    // Reachable by design: login succeeds with `current_organization_id: null` so the resend and
    // accept-invitation endpoints stay usable.
    const screen = await renderWith(authenticated([], null));
    expect(screen.getByRole('link').elements()).toHaveLength(0);
  });
});
