'use client';

import { useEffect } from 'react';

/**
 * Drop `?token=` from the address bar on mount, without navigating.
 *
 * ── WHY THE TOKEN IS IN A URL AT ALL, GIVEN THAT IT MUST NOT BE ───────────────────────────────────
 * The invitation token is a bearer capability and every one of our REQUESTS carries it in a POST body
 * for that reason (laravel-sanctum-auth NN4: a capability in a URL lands in access logs, in `Referer`
 * and in history). But the thing that delivers it is an EMAIL, and an email can only carry a URL. So
 * it is in the address bar for exactly one load, and this hook is what stops that from becoming a
 * permanent history entry the next person on the machine can arrow back to.
 *
 * ── WHAT IS ALREADY HANDLED ELSEWHERE, AND MUST STAY THAT WAY ─────────────────────────────────────
 * `next.config.ts` sets `Referrer-Policy: same-origin` on every non-`/c/` path, so while the token IS
 * in the URL no outbound navigation leaks it in a `Referer` header. That header list is load-bearing
 * for this flow — do not "tidy" it. `Cache-Control: private, no-store` from the same block keeps the
 * token-bearing document out of the shared and disk caches.
 *
 * ── `replaceState`, NOT `router.replace` ─────────────────────────────────────────────────────────
 * `router.replace(pathname)` is a soft navigation: it re-runs the route, discards the mounted client
 * component's state, and would therefore restart the preview query and blank a half-typed password.
 * `history.replaceState` rewrites the entry in place and React never re-renders. Next 15+ supports it
 * explicitly for shallow URL updates, and `window.history.state` is passed back UNCHANGED because the
 * App Router keeps its own routing state in that object — replacing it with `null` breaks the
 * subsequent back/forward navigation with nothing logged.
 *
 * It runs after paint, in an effect, which means the token is present during the first render. That is
 * unavoidable and harmless: the first render is where the value is read.
 *
 * Idempotent by construction, so the accept screen calling it and the register form it delegates to
 * calling it again is a no-op the second time — the parameter is gone.
 */
export function useStripTokenFromUrl(): void {
  useEffect(() => {
    const url = new URL(window.location.href);
    if (!url.searchParams.has('token')) return;

    url.searchParams.delete('token');
    // Re-serialized from `URL` rather than string-sliced, so a second parameter (`?token=x&foo=1`)
    // survives and a trailing `?` does not.
    const cleaned = `${url.pathname}${url.search}${url.hash}`;
    window.history.replaceState(window.history.state, '', cleaned);
  }, []);
}
