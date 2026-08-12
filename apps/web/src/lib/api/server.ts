import 'server-only';

import { cookies } from 'next/headers';

import { API_ORIGIN } from '../env';

/**
 * The ONLY module in apps/web that imports `server-only` and calls Laravel from the Next server.
 * ESLint bans the import everywhere else (eslint.base.mjs) and re-enables it for this file alone.
 *
 * Two things make it uncacheable by construction rather than by opt-out:
 *
 *  1. It awaits `cookies()` first. Reading a request API marks the calling segment dynamic, so no
 *     Full Route Cache entry can exist for anything that goes through here. Belt AND mechanism.
 *  2. `cache: 'no-store'` explicitly. The Data Cache is keyed by the fetch URL and its options,
 *     and the organization is in the session cookie, which is in neither.
 *
 * It does NOT forward the session cookie, and that is the point: a server-side fetch sends no
 * `Referer` and no `Origin`, so Sanctum's `fromFrontend()` classifies it third-party, session
 * middleware never runs, and a valid cookie is ignored — a 401 that cannot reproduce in devtools.
 * Authenticated fetching happens in the BROWSER (lib/api/browser.ts), which removes the trap
 * instead of working around it. Anything genuinely org-scoped must not be requested here at all.
 */
export async function serverFetch(path: string, init: RequestInit = {}): Promise<Response> {
  // Awaited FIRST, before any fetch is issued, and the value is deliberately unused: the call is
  // the dynamic-rendering opt-in, not a credential read.
  await cookies();

  return fetch(`${API_ORIGIN}${path}`, {
    ...init,
    cache: 'no-store',
    headers: {
      accept: 'application/json',
      ...init.headers,
    },
  });
}
