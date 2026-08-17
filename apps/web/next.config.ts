import type { NextConfig } from 'next';

import { API_ORIGIN, assertPublicEnv } from './src/lib/env';

// Build-time env validation. Next 16 REMOVED `next lint` and `next build` no longer lints, so a
// pipeline that relied on the build to catch a stray NEXT_PUBLIC_* key now passes while checking
// nothing. This call runs on every build and every dev boot.
assertPublicEnv();

// Everything that is not hosted chat and not a build asset. Route groups are not URL segments, so
// `headers()` cannot address `(admin)` by name — it has to be spelled as "not /c/ and not /_next/".
// ADR-027 is what makes that expressible at all: hosted chat lives under exactly one prefix.
/**
 * The four routes a CAPABILITY ARRIVES ON, in the query string.
 *
 * An email can only carry a URL, so `?token=` in the address bar is unavoidable on arrival — see
 * decision D2 for why it is the query string and never a path segment (Traefik access logs, `Referer`,
 * browser history). `useStripTokenFromUrl` removes it via `history.replaceState` on mount, but there is
 * a window before that effect runs, and during it EVERY same-origin subresource request carries the full
 * URL — token included — in `Referer`, because the admin block below sets `Referrer-Policy: same-origin`.
 * `_next` chunk fetches and the OTLP exporter POST to `/telemetry/v1/traces` both fall in that window.
 *
 * `/register` IS ONE OF THEM, and it is the one an audit brief listing "the three token landing routes"
 * left out: invitation-gated registration reads `/register?token=…` exactly as the other three do
 * (see that page's own docblock). A list of three would have left the longest-lived of these windows
 * uncovered, since the register form is the one a user sits on while typing a name and a password.
 */
const TOKEN_LANDING_PATHS = '/(reset-password|verify-email|register|invitations/accept)';

/**
 * Everything else the admin app serves. The token-landing routes are EXCLUDED rather than overridden:
 * Next applies every matching `headers()` entry, so leaving them in both blocks would emit two
 * `Referrer-Policy` values for one response and leave which one wins to header-merge order. Excluding
 * them makes exactly one rule match each path, which is a property a reader can check by looking.
 */
const ADMIN_PATHS = '/((?!c/|_next/|reset-password|verify-email|register|invitations/accept).*)';

/** The headers every admin-app response carries, whatever its referrer policy. */
const BASE_ADMIN_HEADERS = [
  // Next's defaults govern Next's caches; Traefik and the browser's bfcache are not among them.
  // Re-asserted at the edge (traefik-routing), because this header is the only thing standing between
  // a shared proxy and an org-scoped response. On the token routes it is also what keeps the
  // token-bearing document itself out of any shared cache.
  { key: 'Cache-Control', value: 'private, no-store' },
  { key: 'X-Content-Type-Options', value: 'nosniff' },
  // The admin console is never framed. Hosted chat's own frame-ancestors is per-response and lives
  // in proxy.ts.
  { key: 'X-Frame-Options', value: 'DENY' },
];

/**
 * The hostnames `next dev` will accept requests for its own dev resources from.
 *
 * WHAT BREAKS WITHOUT IT, and it is not a warning. Next >= 15.3 refuses cross-origin requests to
 * `/_next/*` dev endpoints and answers the HMR WebSocket handshake with a **500**, logging
 * `Blocked cross-origin request to Next.js dev resource`. "Cross-origin" here means "not the host
 * the dev server thinks it is", and this app is always reached through Traefik on `app.<domain>` —
 * never on localhost — so EVERY dev request qualifies. The Turbopack dev client then never
 * connects, and because the app-router dev bootstrap awaits that connection before `hydrateRoot`,
 * **the page never hydrates**: server HTML renders, no event handler is ever attached, and a form
 * submit falls through to the browser's native GET — putting whatever was typed into the query
 * string. That is how a password reached the address bar, the dev server's request log and
 * Traefik's access log. Silent in every layer: no page error, no failed request, HTTP 200.
 *
 * DERIVED, NEVER SPELLED. `DOMAIN` is a deployment fact that has already been a placeholder once,
 * and a literal here would go stale the day it changes while failing in a way nobody connects to
 * this file. The API origin is the one deployment host this process is given, so the domain is
 * read off it by dropping the leading `api.` label. Adding a NEXT_PUBLIC_* key instead is not an
 * option — `src/lib/env.ts` allow-lists exactly one and `assertPublicEnv()` fails the build on a
 * second (compose.yaml:553).
 *
 * Dev-only by construction: Next ignores this key in `next build`/`next start`, so it widens
 * nothing in production. It is an allow-list of two exact hostnames regardless.
 */
const allowedDevOrigins = ((): readonly string[] => {
  try {
    const domain = new URL(API_ORIGIN).hostname.replace(/^api\./, '');
    return [`app.${domain}`, `chat.${domain}`];
  } catch {
    // An unparseable origin is assertPublicEnv()'s finding to report, not this block's. Allowing
    // nothing extra leaves the documented default rather than guessing a hostname.
    return [];
  }
})();

const nextConfig: NextConfig = {
  reactStrictMode: true,
  poweredByHeader: false,

  // See the constant's docblock: without this the dev server 500s its own HMR socket and the app
  // never hydrates. Not cosmetic, and not a production setting.
  allowedDevOrigins: [...allowedDevOrigins],

  // The Dockerfile's runtime stage copies `.next/standalone`, which only exists when this is set:
  // Next traces the server's actual module graph and emits a self-contained server.js plus a pruned
  // node_modules. Without it the runtime stage copies a path that was never produced and the image
  // build fails at COPY, after the whole compile has already succeeded.
  //
  // It has no effect on `next dev` or on the caching decisions above — tracing is an output format,
  // not a rendering mode. Nothing under (admin) becomes prerenderable because of it.
  output: 'standalone',

  // DELIBERATELY ABSENT: `cacheComponents`. Enabling it REMOVES `dynamic`, `dynamicParams`,
  // `revalidate` and `fetchCache` from route segment config — deleting the coarse, greppable,
  // layout-inheritable kill switch (admin)/layout.tsx depends on — and makes `use cache` the
  // default, whose key is a function's ARGUMENTS and closed-over values. The organization is in
  // the session cookie and is in neither. nextjs-app-router NN1.

  images: {
    // Exact hosts only, and there are none: user-supplied files are served from the separate file
    // origin with Content-Disposition: attachment, never through next/image
    // (kb-security-baseline). `dangerouslyAllowSVG` and `dangerouslyAllowLocalIP` stay unset —
    // the first runs script on our origin, the second turns /_next/image into a private-network
    // proxy.
    remotePatterns: [],
  },

  async headers() {
    return [
      {
        source: ADMIN_PATHS,
        headers: [
          ...BASE_ADMIN_HEADERS,
          // `same-origin` is right for the rest of the console: an admin path is not a secret, and
          // keeping the referrer within the origin is what lets our own telemetry and navigation
          // timing attribute a request to the page that made it.
          { key: 'Referrer-Policy', value: 'same-origin' },
        ],
      },
      {
        // THE ONE PLACE `same-origin` IS THE WRONG ANSWER, because here the URL itself is the secret.
        // `same-origin` sends the FULL URL — query string and all — on every same-origin subresource
        // request, so during the window before `useStripTokenFromUrl` runs, the reset/verification/
        // invitation capability travels in `Referer` to our own origin on each `_next` chunk fetch and
        // on the OTLP exporter POST. `no-referrer` sends no referrer at all, which costs nothing these
        // four pages use: none of them attributes anything by referrer, and they are entered from an
        // email client rather than from another page of ours.
        //
        // The telemetry half was separately verified clean — `instrumentation-client.ts` strips the
        // query from `url.full` and the document-load instrumentation writes only that attribute — so
        // this closes the residual `Referer` REQUEST header rather than a known exporter leak. It is
        // defence in depth on a capability, which is where defence in depth is worth the line.
        source: TOKEN_LANDING_PATHS,
        headers: [...BASE_ADMIN_HEADERS, { key: 'Referrer-Policy', value: 'no-referrer' }],
      },
      {
        // The hosted-chat CSP is assembled per response in proxy.ts because it carries a nonce.
        // What is static about it lives here.
        source: '/c/:path*',
        headers: [
          { key: 'X-Content-Type-Options', value: 'nosniff' },
          { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
        ],
      },
    ];
  },
};

export default nextConfig;
