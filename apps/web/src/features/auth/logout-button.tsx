'use client';

import type { AcknowledgementResource } from '@kb/contracts';
import { useMutation, useQueryClient } from '@tanstack/react-query';

import { Button } from '@/components/ui/button';
import { useResetQueryClient } from '@/components/providers';
import { sessionCredential } from '@/lib/api/browser';

import { browserFetchData, browserNavigation, LOGOUT_PATH } from './session';

/**
 * Sign out, rendered from `(admin)/layout.tsx`'s nav.
 *
 * FOUR STEPS, AND EACH ONE IS THERE FOR A DIFFERENT CACHE. Reordering them or dropping one leaves a
 * window in which authenticated chrome renders against a dead session:
 *
 *  1. `await queryClient.cancelQueries()` — aborts in-flight authenticated reads so none of them can
 *     resolve AFTER the session dies. This only aborts anything because every `queryFn` in this app
 *     forwards its `signal` to fetch (src/lib/api/browser.ts:38-40); a queryFn that drops it makes
 *     this line a no-op that looks like a control.
 *  2. `await logout.mutateAsync()` — `POST /api/v1/auth/logout` (POST, not DELETE). The button is
 *     `disabled` while pending: a mutation with no `Idempotency-Key` must never be retried by
 *     anything, including a double-click.
 *  3. `resetQueryClient()` — REPLACE the client, NEVER `queryClient.clear()`.
 *     src/components/providers.tsx:14-17 is the argument: `clear()` empties the caches but keeps the
 *     same client and the same MOUNTED OBSERVERS, so an active observer immediately refetches into
 *     the window between step 2 resolving and the next document committing — tens of milliseconds,
 *     much longer on a slow link — and renders authenticated chrome against a session that no longer
 *     exists. A new client has no observers and nothing renders the old one.
 *  4. `browserNavigation.assign('/login')` — a FULL DOCUMENT navigation, never `router.push`. The
 *     Router Cache lives in this tab and is keyed by path, so a soft navigation leaves authenticated
 *     RSC payloads that the Back button will render.
 *
 * STEPS 3 AND 4 RUN FROM `onSettled`, NOT `onSuccess`. A sign-out button that leaves you signed in
 * because the network hiccuped is worse than one that navigates optimistically — and if the POST 419s
 * twice, `browserFetch` has already navigated to `/login` itself.
 *
 * `next.config.ts` sets `Cache-Control: private, no-store` on every non-`/c/` path, which is what
 * disqualifies these documents from the back/forward cache, so history navigation RE-REQUESTS the
 * route and `proxy.ts` — finding no `kb_session` cookie — bounces it to `/login`. That back-button
 * property is asserted by neither layer of this suite: a component spec may not assert navigation at
 * all, and Playwright is out of scope here. It is recorded as unproven, not as covered.
 */
export function LogoutButton() {
  const queryClient = useQueryClient();
  const resetQueryClient = useResetQueryClient();

  const logout = useMutation({
    mutationFn: async () =>
      browserFetchData<AcknowledgementResource>({
        path: LOGOUT_PATH,
        method: 'POST',
        credential: await sessionCredential(),
      }),
    onSettled: () => {
      resetQueryClient();
      browserNavigation.assign('/login');
    },
  });

  return (
    <Button
      type="button"
      variant="ghost"
      size="sm"
      disabled={logout.isPending}
      onClick={() => {
        void signOut();
      }}
    >
      {logout.isPending ? 'Signing out…' : 'Sign out'}
    </Button>
  );

  async function signOut(): Promise<void> {
    await queryClient.cancelQueries();
    // The rejection is deliberately swallowed HERE rather than left unhandled: `onSettled` has
    // already navigated, and an uncaught rejection from a button click is a console error nobody can
    // act on. The operator-facing detail is on the mutation's `error` either way.
    await logout.mutateAsync().catch(() => undefined);
  }
}
