import type { Metadata } from 'next';
import type { ReactNode } from 'react';

import '../globals.css';

/**
 * ROOT LAYOUT #2 — hosted chat, served on `chat.<domain>` under `/c/[publicBotId]` (ADR-027). It
 * owns its own <html> and its own posture, and it shares no parent layout with the admin console.
 *
 * The hostname split is not cosmetic: this surface renders model-generated Markdown, the
 * highest-risk sink in the product, and on a shared origin one XSS here would execute with the
 * admin session cookie attached. Scoping that cookie to `app.` and `api.` means an anonymous
 * visitor's page never carries it — and it is why nothing under this group may assume a cookie
 * credential.
 *
 * THE CSP IS SET IN proxy.ts, not here: it carries a per-response nonce that Next reads off the
 * REQUEST's Content-Security-Policy header and applies to its own inline bootstrap scripts. A
 * <meta http-equiv> tag in this layout cannot express `frame-ancestors` and cannot be per-response.
 *
 * No QueryClientProvider and no next-themes: this surface holds one bot for one anonymous visitor,
 * its light/dark mode comes from the bot's `default_mode`, and its palette arrives as a real
 * stylesheet (`theme.css`) that `style-src 'self'` covers with no nonce.
 */
export const metadata: Metadata = {
  // Deliberately generic at the layout level. A per-bot title is composed in generateMetadata,
  // which runs BEFORE any access gate — so for anything other than a fully public bot the title
  // must stay generic, or <title> answers the question the 404 rule exists to refuse.
  title: 'Chat',
  robots: { index: false, follow: false },
};

export default function ChatRootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="en">
      {/* <html> deliberately has NO `suppressHydrationWarning` — there is no next-themes here, so
          nothing writes to it before React runs. <body> needs it for the unrelated reason that browser
          extensions write to this element and the attribute does not cascade from a parent that has
          it. Full reasoning in `(auth)/layout.tsx`. */}
      <body
        className="bg-background text-foreground min-h-dvh antialiased"
        suppressHydrationWarning
      >
        {children}
      </body>
    </html>
  );
}
