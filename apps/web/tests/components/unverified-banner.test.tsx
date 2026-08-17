import { KbError } from '@kb/contracts';
import { render } from 'vitest-browser-react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { SessionState } from '@/features/auth/session';
import { SessionContext } from '@/features/auth/session-context';
import { UnverifiedBanner } from '@/features/auth/unverified-banner';

import { sessionFixture } from '../msw/handlers';

/**
 * The unverified-email banner, across ALL FOUR `useSession()` states.
 *
 * ── WHY THIS DRIVES `SessionContext` DIRECTLY INSTEAD OF MOUNTING `<SessionProvider>` ────────────
 * The banner's whole contract is "given a SessionState, render or do not render", and there are four
 * states plus a boolean — nine-ish combinations that MSW can only reach indirectly and two of which
 * (`loading`, `unavailable`) are timing- or fault-shaped. Driving the context makes each one a
 * deterministic input rather than a race, and it keeps `SessionProvider`'s own behaviour — the
 * `anonymous` bounce, the membership notice — out of a spec that is not about either. Those are
 * asserted in session-provider.test.tsx, where they belong.
 *
 * A component spec may not assert authentication, navigation or isolation (the vitest-playwright
 * boundary table), and nothing here is cited as coverage for any of them.
 */

beforeEach(() => {
  // Uniform with every other component spec even though this component issues no request: the day it
  // grows one, the absence of this line would fail it for the wrong reason.
  document.cookie = 'XSRF-TOKEN=test-token';
});

afterEach(() => {
  document.cookie = 'XSRF-TOKEN=; max-age=0';
  vi.restoreAllMocks();
});

const renderWith = (state: SessionState) =>
  render(
    <SessionContext.Provider value={state}>
      <UnverifiedBanner />
    </SessionContext.Provider>,
  );

/** `authenticated`, parameterised on the one field that decides everything. */
const authenticated = (emailVerified: boolean): SessionState => {
  const session = sessionFixture({
    user: { ...sessionFixture().user, email_verified: emailVerified },
  });
  return {
    status: 'authenticated',
    user: session.user,
    organizations: session.organizations,
    orgId: session.current_organization_id,
  };
};

const HEADING = 'Confirm your email address to finish setting up';

describe('email_verified: false — the one state that renders', () => {
  it('renders the banner with a link to the verification screen', async () => {
    const screen = await renderWith(authenticated(false));

    await expect.element(screen.getByRole('alert')).toHaveTextContent(HEADING);
    // A plain anchor, because `/verify-email` is under a different ROOT LAYOUT and Next serves that
    // as a full document load however the navigation is expressed.
    await expect
      .element(screen.getByRole('link', { name: 'Confirm your email address' }))
      .toHaveAttribute('href', '/verify-email');
  });

  it('says that a refusal cannot name this as its reason — the sentence the whole banner exists for', async () => {
    // Laravel's `verified` middleware guards the org-scoped routes, and the render closure rewrites
    // EVERY `authorization` message to one constant string. So the envelope cannot distinguish "your
    // email is unverified" from "your role does not permit this": both are an identical 403.
    // `email_verified` on `GET /api/v1/me` is the only signal that separates them, which is why
    // `verified` is deliberately not applied to `/me`. Without this sentence a user reads the banner
    // as housekeeping, hits a permission error, and has no reason to connect the two.
    const screen = await renderWith(authenticated(false));

    await expect.element(screen.getByRole('alert')).toBeVisible();
    expect(document.body.textContent).toContain('it cannot name');
    expect(document.body.textContent).toContain('this banner is the only place it is said');
  });

  it('does NOT redirect — it is a banner, and the gating is Laravel’s', async () => {
    // A force-redirect to /verify-email would be a second, weaker gate in the layer that is never the
    // authorization gate, and it would trap the user: `/me` and the resend endpoint have to stay
    // reachable. The banner renders a LINK; nothing here navigates.
    const assign = vi.spyOn(window.history, 'replaceState');
    const screen = await renderWith(authenticated(false));

    await expect.element(screen.getByRole('alert')).toBeVisible();
    expect(assign).not.toHaveBeenCalled();
    expect(window.location.pathname).not.toBe('/verify-email');
  });
});

describe('the three other states, and a verified user, render nothing at all', () => {
  it('renders nothing for email_verified: true', async () => {
    const screen = await renderWith(authenticated(true));
    expect(screen.getByRole('alert').elements()).toHaveLength(0);
    expect(document.body.textContent).not.toContain(HEADING);
  });

  it('renders nothing while the session is loading', async () => {
    // A banner that flashes on every page load and then vanishes for a verified user is worse than a
    // banner that arrives one round trip late.
    const screen = await renderWith({ status: 'loading' });
    expect(screen.getByRole('alert').elements()).toHaveLength(0);
  });

  it('renders nothing for anonymous', async () => {
    // `anonymous` means Laravel said `authentication` and nothing else; the provider is already
    // bouncing to /login.
    const screen = await renderWith({ status: 'anonymous' });
    expect(screen.getByRole('alert').elements()).toHaveLength(0);
  });

  it('renders nothing for unavailable — a server fault is not evidence about this user', async () => {
    // THE STATE THIS ASSERTION EXISTS FOR. `unavailable` is 3D's fourth member precisely so a 502, a
    // throttle or an `internal_dependency` cannot masquerade as an identity answer. We do not know
    // whether this user is verified, so telling a verified admin to go and confirm an address they
    // confirmed months ago would be inventing a fact out of a network failure.
    const screen = await renderWith({
      status: 'unavailable',
      error: new KbError('internal_dependency', false, null, '01JREQ', 'operator detail'),
    });

    expect(screen.getByRole('alert').elements()).toHaveLength(0);
    expect(document.body.textContent).not.toContain(HEADING);
    // And nothing here renders the envelope's operator-facing `message` either.
    expect(document.body.textContent).not.toContain('operator detail');
  });
});
