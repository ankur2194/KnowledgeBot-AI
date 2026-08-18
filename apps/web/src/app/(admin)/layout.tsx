import type { Metadata } from 'next';
import { cookies } from 'next/headers';
import type { ReactNode } from 'react';

import { AppShell } from '@/components/app-shell';
import { Providers } from '@/components/providers';
import { CurrentOrgBadge } from '@/features/auth/current-org-badge';
import { LogoutButton } from '@/features/auth/logout-button';
import { SessionNotice, SessionProvider } from '@/features/auth/session-provider';
import { UnverifiedBanner } from '@/features/auth/unverified-banner';
import { SIDEBAR_COOKIE, readSidebarCollapsed } from '@/lib/sidebar';

import { fontClassName } from '../fonts';

import '../globals.css';

/**
 * ROOT LAYOUT #1 — the admin console, served on `app.<domain>` at `/*` (ADR-027). It owns its own
 * <html>, its own caching posture, and its own credential (the session cookie). Hosted chat's root
 * layout is the exact opposite on every one of those, which is why they are separate route groups
 * with no shared parent layout.
 *
 * Segment config on a LAYOUT applies to every route beneath it, so a page added later cannot opt
 * into caching by omission. This is belt, not mechanism — the mechanism is that the browser is the
 * fetcher and the Next server never sees an org-scoped byte at all.
 */
export const dynamic = 'force-dynamic';
export const revalidate = 0;

// The per-segment fetch-cache override is NEVER set here — its 'force-cache'/'default-cache'
// values re-enable caching for fetches issued AFTER a request-time API, which is precisely the
// window force-dynamic is closing. Its identifier is deliberately unspelled anywhere under this
// route group, because CI greps this directory for it.

export const metadata: Metadata = {
  title: 'KnowledgeBot AI',
  robots: { index: false, follow: false },
};

// The nav model and the ONE active-state function live in `lib/nav.ts`, shared with the breadcrumb
// so the two cannot disagree about which item is current.

/**
 * `<SessionProvider>` MOUNTS HERE, INSIDE `<Providers>`, WRAPPING THE NAV AND `{children}` — not in
 * `providers.tsx` and not in `(auth)/layout.tsx`. Every consequence of that placement is intended:
 *
 *  - a signed-out visitor on `/login` fires ZERO `GET /api/v1/me` requests, so there is no
 *    401 -> redirect -> render -> 401 loop and no needless traffic against the most rate-limited part
 *    of the product;
 *  - `providers.tsx` needs no edit at all, so `useResetQueryClient` and its `useState` client are
 *    CONSUMED here rather than modified there;
 *  - the admin surface has exactly one place identity enters the tree.
 *
 * The nav is still byte-identical for every organization AS THE SERVER RENDERS IT: `<CurrentOrgBadge/>`
 * and `<LogoutButton/>` are client components that render nothing until a browser fetch answers, so
 * the RSC payload this layout produces carries no organization-scoped byte. That is the property, not
 * the appearance — the Next server has no cache key that could hold one.
 *
 * `<UnverifiedBanner/>` MOUNTS IN THIS LAYOUT AND NOWHERE ELSE, because it has to be on every admin
 * route. Laravel's `verified` middleware guards the org-scoped routes and its 403 CANNOT say why —
 * the render closure rewrites every `authorization` message to one constant string, so "your email is
 * unverified" and "your role does not permit this" arrive as byte-identical errors. `email_verified`
 * on `GET /api/v1/me` is the only signal that separates them, and this banner is the only place it is
 * ever said. There is deliberately NO force-redirect to `/verify-email`: the gating is Laravel's, and
 * a redirect would trap a user on the one screen whose own endpoints it needs to keep reachable. See
 * unverified-banner.tsx for the full argument.
 */
export default async function AdminRootLayout({ children }: { children: ReactNode }) {
  // Read SERVER-SIDE so the first paint already has the remembered width. Reading it in an effect
  // makes the sidebar visibly snap after hydration on every navigation. It is a display preference
  // and carries nothing org-scoped, so it does not widen what this layout knows about the visitor.
  const collapsed = readSidebarCollapsed((await cookies()).get(SIDEBAR_COOKIE)?.value);

  return (
    <html lang="en" suppressHydrationWarning>
      {/* <body> carries its own `suppressHydrationWarning` because the attribute does not cascade and
          browser extensions write to this element. Full reasoning in `(auth)/layout.tsx`. */}
      <body className={`${fontClassName} min-h-dvh`} suppressHydrationWarning>
        <Providers>
          <SessionProvider>
            <AppShell
              defaultCollapsed={collapsed}
              brand={<span className="text-h3 whitespace-nowrap">KnowledgeBot</span>}
              sidebarFooter={
                <>
                  <CurrentOrgBadge />
                  <LogoutButton />
                </>
              }
            >
              {/* Both notices render INSIDE the shell's main column. Before <AppShell/> they were
                  siblings of the shell and painted on the page background, belonging to no plane. */}
              <SessionNotice />
              <UnverifiedBanner />
              {children}
            </AppShell>
          </SessionProvider>
        </Providers>
      </body>
    </html>
  );
}
