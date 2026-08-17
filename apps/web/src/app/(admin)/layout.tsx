import type { Metadata } from 'next';
import Link from 'next/link';
import type { ReactNode } from 'react';

import { Providers } from '@/components/providers';
import { CurrentOrgBadge } from '@/features/auth/current-org-badge';
import { LogoutButton } from '@/features/auth/logout-button';
import { SessionProvider } from '@/features/auth/session-provider';
import { UnverifiedBanner } from '@/features/auth/unverified-banner';

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

const NAV = [
  { href: '/', label: 'Overview' },
  { href: '/bots', label: 'Bots' },
  { href: '/sources', label: 'Sources' },
  { href: '/settings', label: 'Settings' },
] as const;

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
export default function AdminRootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="en" suppressHydrationWarning>
      {/* <body> carries its own `suppressHydrationWarning` because the attribute does not cascade and
          browser extensions write to this element. Full reasoning in `(auth)/layout.tsx`. */}
      <body className="min-h-dvh antialiased" suppressHydrationWarning>
        <Providers>
          <SessionProvider>
            <div className="flex min-h-dvh flex-col">
              <header className="border-b">
                <nav
                  aria-label="Main"
                  className="mx-auto flex max-w-6xl items-center gap-4 px-6 py-4"
                >
                  {NAV.map((item) => (
                    <Link key={item.href} href={item.href} className="text-sm hover:underline">
                      {item.label}
                    </Link>
                  ))}
                  <CurrentOrgBadge />
                  <LogoutButton />
                </nav>
              </header>
              <UnverifiedBanner />
              <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-8">{children}</main>
            </div>
          </SessionProvider>
        </Providers>
      </body>
    </html>
  );
}
