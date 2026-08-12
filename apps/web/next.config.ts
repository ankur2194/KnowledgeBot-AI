import type { NextConfig } from 'next';

import { assertPublicEnv } from './src/lib/env';

// Build-time env validation. Next 16 REMOVED `next lint` and `next build` no longer lints, so a
// pipeline that relied on the build to catch a stray NEXT_PUBLIC_* key now passes while checking
// nothing. This call runs on every build and every dev boot.
assertPublicEnv();

// Everything that is not hosted chat and not a build asset. Route groups are not URL segments, so
// `headers()` cannot address `(admin)` by name — it has to be spelled as "not /c/ and not /_next/".
// ADR-027 is what makes that expressible at all: hosted chat lives under exactly one prefix.
const ADMIN_PATHS = '/((?!c/|_next/).*)';

const nextConfig: NextConfig = {
  reactStrictMode: true,
  poweredByHeader: false,

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
          // Next's defaults govern Next's caches; Traefik and the browser's bfcache are not among
          // them. Re-asserted at the edge (traefik-routing), because this header is the only thing
          // standing between a shared proxy and an org-scoped response.
          { key: 'Cache-Control', value: 'private, no-store' },
          { key: 'X-Content-Type-Options', value: 'nosniff' },
          { key: 'Referrer-Policy', value: 'same-origin' },
          // The admin console is never framed. Hosted chat's own frame-ancestors is per-response
          // and lives in proxy.ts.
          { key: 'X-Frame-Options', value: 'DENY' },
        ],
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
