import type { Metadata } from 'next';
import type { ReactNode } from 'react';

import { Providers } from '@/components/providers';
import { fontClassName } from '../fonts';

import '../globals.css';

/**
 * ROOT LAYOUT #3 — the unauthenticated surface, served on `app.<domain>` alongside the admin console
 * (ADR-027). It owns its own <html> and shares no parent layout with `(admin)` or `(chat)`.
 *
 * WHY ITS OWN ROOT LAYOUT rather than a nested one under `(admin)`: the admin layout renders the nav
 * strip unconditionally, so a signed-out visitor at `/login` was shown Overview/Bots/Sources/Settings
 * links that every one of them 307s straight back to `/login`. That is the concrete bug this move
 * fixes — not tidiness.
 *
 * <Providers> IS mounted, and it is load-bearing rather than symmetric. Every screen beneath this
 * layout is a `useMutation`, and a 422 only reaches a form field through `applyServerErrors` called
 * from that mutation's `onError`. No QueryClientProvider means no useMutation means the entire
 * error-mapping doctrine is unimplementable here. `Providers` is credential-agnostic: it holds a
 * QueryClient in useState and adds next-themes, and nothing in it reads a session.
 *
 * <SessionProvider> IS NOT, and that is the most load-bearing boundary in this group. It mounts in
 * `(admin)/layout.tsx` only, so a signed-out visitor on `/login` fires ZERO `GET /api/v1/me`
 * requests — no 401 -> redirect -> render -> 401 loop, and no needless traffic against the most
 * rate-limited part of the product.
 *
 * Segment config on a LAYOUT applies to every route beneath it. `force-dynamic` here is not
 * cargo-cult: `/reset-password`, `/verify-email` and `/invitations/accept` all read request state
 * from `searchParams`, and `dynamic = 'force-static'` does NOT error on that — it makes those reads
 * return EMPTY values, so the Full Route Cache serves one signed-out shell to everyone with a
 * plausible-looking page and no error anywhere. ESLint bans the `'force-static'` literal in this
 * directory for that reason.
 */
export const dynamic = 'force-dynamic';
export const revalidate = 0;

// The per-segment fetch-cache override is NEVER set here — its 'force-cache'/'default-cache' values
// re-enable caching for fetches issued AFTER a request-time API, which is precisely the window
// force-dynamic is closing. Its identifier is deliberately unspelled anywhere under this route
// group, and that is enforced — by ESLint, in this workspace, not by a pipeline: eslint.config.mjs
// bans `VariableDeclarator[id.name='fetchCache']` for `src/app/(auth)/**`, the same block that
// bans the 'force-static' literal the docblock above refers to. `pnpm web:lint` is the run, and
// nothing runs it for you: `.github/` was deleted on 2026-08-17 and this repo has no CI.

export const metadata: Metadata = {
  title: 'KnowledgeBot AI',
  robots: { index: false, follow: false },
};

/**
 * THE HEADER POSTURE FOR THIS GROUP IS SPLIT IN TWO, which is easy to misread in `next.config.ts`.
 * `ADMIN_PATHS` covers `/login` and `/forgot-password`; the other four routes here —
 * `/reset-password`, `/verify-email`, `/register`, `/invitations/accept` — are EXCLUDED from that
 * pattern and matched by `TOKEN_LANDING_PATHS` instead. Both blocks share `Cache-Control: private,
 * no-store` and `X-Frame-Options: DENY` via BASE_ADMIN_HEADERS; they differ only in referrer policy.
 *
 * The sentence that used to stand here — that `Referrer-Policy: same-origin` is what keeps a reset or
 * invitation token out of `Referer` — was measured FALSE, and its being false is the whole reason
 * `no-referrer` now exists on those four paths (decision D51). `same-origin` sends the FULL URL,
 * query string included, on every same-origin subresource request. So in the window before
 * `useStripTokenFromUrl` runs, the capability travelled in `Referer` on each `_next` chunk fetch and
 * on the OTLP POST. No config change is needed here — said out loud so nobody "tidies" either list
 * back into one.
 */
export default function AuthRootLayout({ children }: { children: ReactNode }) {
  // Nothing rendered here varies by organization, because nothing here HAS an organization: no nav,
  // no org badge, no logout. A visitor at this layout is anonymous by construction.
  return (
    <html lang="en" suppressHydrationWarning>
      {/*
        `suppressHydrationWarning` ON <body> TOO, AND NOT FOR THE SAME REASON AS ON <html>.
        React applies this attribute to exactly ONE element — it covers that element's own attributes
        and its text children, and it does NOT cascade — so the copy on <html> above (which is there
        because next-themes writes a class before React runs; see providers.tsx) says nothing at all
        about <body>.

        Browser extensions write to <body>. Measured, from a real report against this line: Grammarly
        adds `data-new-gr-c-s-check-loaded` and `data-gr-ext-installed`, ColorZilla adds
        `cz-shortcut-listen="true"`. The server HTML carries none of them, so any visitor with one of
        those installed got a full-screen hydration error naming THIS FILE — on `/reset-password`,
        sitting directly above a genuine `ERR_CERT_AUTHORITY_INVALID`. Noise that crowds a real failure
        is why this is fixed rather than tolerated; it is not cosmetic.

        WHAT IT COSTS, stated because suppression is a real loss: a server/client divergence in
        <body>'s OWN attributes now goes silent here. Acceptable because `className` on this element is
        a literal constant string — no expression on it could differ between the two renders. Every
        descendant is unaffected and still reports mismatches normally, which is the half that matters.
      */}
      <body className={`${fontClassName} min-h-dvh`} suppressHydrationWarning>
        <Providers>
          {/* RULE 1, TWO PLANES: the page is the recessed --canvas and content sits on a raised
              --card. Content floating directly on the canvas is what makes a screen read as a
              different application, and an auth screen is the first one a customer ever sees. The
              card is --radius-2xl with --shadow-md, the standard content card (P5). */}
          <main className="mx-auto flex min-h-dvh w-full max-w-md flex-col justify-center px-gutter-sm py-12">
            <div className="flex flex-col gap-6 rounded-2xl bg-card p-card-pad-lg shadow-md">
              {children}
            </div>
          </main>
        </Providers>
      </body>
    </html>
  );
}
