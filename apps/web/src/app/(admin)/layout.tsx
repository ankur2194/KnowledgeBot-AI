import type { Metadata } from 'next';
import Link from 'next/link';
import type { ReactNode } from 'react';

import { Providers } from '@/components/providers';

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

export default function AdminRootLayout({ children }: { children: ReactNode }) {
  // Everything rendered here is byte-identical for every organization: navigation, route
  // structure, skeletons, empty states. Any tenant-scoped read belongs in a client component
  // fetching from the browser through TanStack Query.
  return (
    <html lang="en" suppressHydrationWarning>
      <body className="min-h-dvh antialiased">
        <Providers>
          <div className="flex min-h-dvh flex-col">
            <header className="border-b">
              <nav aria-label="Main" className="mx-auto flex max-w-6xl gap-4 px-6 py-4">
                {NAV.map((item) => (
                  <Link key={item.href} href={item.href} className="text-sm hover:underline">
                    {item.label}
                  </Link>
                ))}
              </nav>
            </header>
            <main className="mx-auto w-full max-w-6xl flex-1 px-6 py-8">{children}</main>
          </div>
        </Providers>
      </body>
    </html>
  );
}
