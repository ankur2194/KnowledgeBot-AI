import { QueryClientProvider, type QueryClient } from '@tanstack/react-query';
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import type { ReactNode } from 'react';

import { revokeCurrentToken, setUnauthenticatedHandler } from '@/api/client';
import { purgeHistoryDatabase } from '@/db/open-history';
import { makeQueryClient } from '@/lib/query-client';
import { isSafeInternalHref } from '@/lib/safe-href';

import { ensureFreshInstallIsSignedOut } from './first-launch';
import { hasUsableSession, purge, readSession, writeSession } from './secure-store';
import type { StoredSession } from './secure-store';

/**
 * Session state, the QueryClient's lifetime, and the intended-href memory, in one place — because
 * all three have to change together and any one of them left behind is a leak.
 *
 * COLD START ORDER, and it is not negotiable:
 *   1. `ensureFreshInstallIsSignedOut()` — purge SecureStore if the AsyncStorage launch flag is
 *      missing, i.e. this is the first launch of a NEW install. The iOS Keychain survives
 *      uninstall, so this must run BEFORE anything reads a token.
 *   2. read the stored session, applying the 90 s expiry grace window.
 *   3. only then render children.
 *
 * `status: 'loading'` renders NOTHING. A cold-start deep link that paints a cached screen before
 * step 2 resolves is a real leak — expo-router makes every route deep-linkable, so the link may
 * point at any screen in the app, and "it flashed for 200 ms" is still a disclosure.
 */

export type SessionStatus = 'loading' | 'authenticated' | 'anonymous';

interface SessionContextValue {
  readonly status: SessionStatus;
  /** Never the token. Screens that need the token do not exist; src/api/client.ts is the only
   *  module that reads it. */
  readonly organizationId: string | null;
  readonly userId: string | null;
  /** The route the user was trying to reach when the gate bounced them. Held IN MEMORY, never in a
   *  query string — a redirect target that survives in a URL is a redirect target an attacker can
   *  supply through a deep link. */
  readonly pendingHref: string | null;
  rememberIntendedHref(href: string): void;
  takeIntendedHref(): string | null;
  signIn(session: StoredSession): Promise<void>;
  signOut(): Promise<void>;
}

const SessionContext = createContext<SessionContextValue | null>(null);

export function useSession(): SessionContextValue {
  const value = useContext(SessionContext);
  if (value === null) throw new Error('useSession must be used inside <SessionProvider>');
  return value;
}

export function SessionProvider({ children }: { children: ReactNode }): ReactNode {
  const [status, setStatus] = useState<SessionStatus>('loading');
  const [organizationId, setOrganizationId] = useState<string | null>(null);
  const [userId, setUserId] = useState<string | null>(null);
  const pendingHref = useRef<string | null>(null);

  /**
   * Created INSIDE the tree and REPLACED on every identity change.
   *
   * `queryClient.clear()` is the wrong tool and looks right: it empties the caches but keeps the
   * same client and the same mounted observers, so active observers immediately refetch and any
   * request that started before the sign-out can still resolve into the "cleared" cache. A new
   * instance has no observers, so nothing renders the old one. The `orgKey` prefix is the
   * invariant; replacing the client is the enforcement that still holds when a future refactor
   * skips a step.
   */
  const [queryClient, setQueryClient] = useState<QueryClient>(() => makeQueryClient());

  const clearLocalState = useCallback(async (): Promise<void> => {
    // THE CREDENTIAL FIRST. Whatever else fails below, a device holding no token cannot make a
    // request, so this is the step whose failure would matter most and it is the step with the
    // fewest ways to fail.
    await purge();

    // Then the in-memory cache, by REPLACEMENT rather than `clear()` — see the note on the state
    // declaration above. These are synchronous, so they cannot be stranded by an await that throws.
    setQueryClient(makeQueryClient());
    setOrganizationId(null);
    setUserId(null);
    setStatus('anonymous');

    // AND THEN THE ON-DISK TRANSCRIPT CACHE. All three go together or none of them is sufficient:
    // an org-scoped SQLite file that survives a sign-out is the mobile shape of the cross-tenant
    // leak, and it is the one of the three that is still there after a reboot. It is awaited, not
    // fired and forgotten — a purge still in flight is a purge that has not happened — and it is
    // LAST so that a failure here cannot strand the session teardown above it. A rejection
    // propagates: `signOut` reports it, because "your transcripts are still on this device" is
    // something the next user of a shared tablet is entitled to have been told.
    await purgeHistoryDatabase();
  }, []);

  // Cold start. Runs exactly once; `status` stays 'loading' and the gate renders nothing until it
  // resolves.
  useEffect(() => {
    let cancelled = false;

    void (async () => {
      await ensureFreshInstallIsSignedOut();
      const session = (await hasUsableSession()) ? await readSession() : null;
      if (cancelled) return;
      setOrganizationId(session?.organization_id ?? null);
      setUserId(session?.user_id ?? null);
      setStatus(session === null ? 'anonymous' : 'authenticated');
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  /**
   * The 401 path, registered once. A 401 means the token is GONE — expired, or revoked from
   * another device. It is never retried: `authentication` is non-retryable, and a retry loop
   * against a dead token rate-limits the device out of its own login endpoint.
   *
   * What survives the purge: the composed draft, which lives in the composer's own state and is
   * restored after re-auth. Losing a paragraph the user just typed is the part everyone forgets.
   */
  useEffect(() => {
    setUnauthenticatedHandler(() => {
      // No caller to report to on this path — a 401 arrives from whichever request happened to be
      // in flight — and by the time anything below could reject, the token and the in-memory cache
      // are already gone. The disk purge's own fallback (delete the database file) is in
      // src/db/open-history.ts, so this catch is the third line of defence, not the first.
      void clearLocalState().catch(() => {});
    });
    return () => {
      setUnauthenticatedHandler(null);
    };
  }, [clearLocalState]);

  const value = useMemo<SessionContextValue>(
    () => ({
      status,
      organizationId,
      userId,
      pendingHref: pendingHref.current,
      rememberIntendedHref(href) {
        // Validated on the way IN as well as on the way out: the gate is the only caller today,
        // but a link handler added later would be the natural place to introduce an attacker-
        // supplied destination.
        pendingHref.current = isSafeInternalHref(href) ? href : null;
      },
      takeIntendedHref() {
        const href = pendingHref.current;
        pendingHref.current = null;
        return href !== null && isSafeInternalHref(href) ? href : null;
      },
      async signIn(session) {
        /**
         * THE ON-DISK CACHE IS PURGED BEFORE THE NEW SESSION IS WRITTEN, and this is not symmetry
         * with `signOut` — it is the case `signOut` cannot cover at all.
         *
         * A sign-out purges. A 401 purges. But a token that merely EXPIRED runs neither: the cold
         * start reads it, `hasUsableSession()` says no, the provider comes up 'anonymous', and the
         * previous user's transcripts are still in the SQLite file when the next person types their
         * password. That path leaves no trace to condition on — `readSession()` returns null on an
         * expired token, on a half-written record and on an Android decrypt failure alike, so the
         * app does not know WHOSE rows those are. "Purge when the incoming identity differs from the
         * stored one" is a guard that reads `null` in exactly the states it exists to catch.
         *
         * So it is unconditional, including when the same user signs in again. The only cost of
         * over-purging is one refetch from Laravel — over a network that must already be up, since
         * a sign-in is a round trip. The cost of under-purging is a colleague's transcripts.
         *
         * BEFORE `writeSession`, so a purge that fails cannot leave the device AUTHENTICATED as the
         * new user with the old user's rows underneath it. `purgeHistoryDatabase` already falls back
         * to deleting the database file, so a rejection here means both forms failed; `signIn` then
         * rejects, no session is written, and the login screen reports it. Refusing a sign-in is the
         * conservative outcome and the caller can retry it.
         */
        await purgeHistoryDatabase();

        await writeSession(session);
        // A fresh client for the incoming identity, so nothing the previous user loaded can be a
        // cache hit for this one.
        setQueryClient(makeQueryClient());
        setOrganizationId(session.organization_id);
        setUserId(session.user_id);
        setStatus('authenticated');
      },
      async signOut() {
        /**
         * SIGN-OUT IS LOCALLY COMPLETE AND SERVER-SIDE ABSENT. Do not read this function as "the
         * token is dead" — read it as "this device no longer holds a token". Those are different
         * claims and only the second one is true today.
         *
         * What IS enforced, and asserted by tests/session-provider.test.tsx: the credential leaves
         * SecureStore, the QueryClient is replaced, the on-disk transcript cache is purged, and the
         * gate routes to login. All of that happens whether or not the revoke below succeeds.
         *
         * What is NOT enforced, verified against services/core-api on 2026-08-11 rather than
         * assumed: `revokeCurrentToken()` throws `not implemented`, and it has to, because there is
         * nothing for it to call. The whole Laravel app registers FIVE routes and none is a login,
         * a logout, a revoke or a device list; `routes/api_public.php`'s `rt/v1` group — the
         * surface this app's PAT is documented to use — is an empty `Route::group` with a TODO;
         * `User` does not `use HasApiTokens`; and of the six migrations, none creates
         * `personal_access_tokens`. So there is no token row to outlive the device yet. The gap is
         * therefore CONDITIONAL and lands the moment minting does: whoever writes the mint route
         * must write the revoke route in the same change, or every sign-out from that day forward
         * leaves a live token on the server for the rest of its lifetime (`config/sanctum.php:39`
         * plans 30 days for mobile, passed per-token to `createToken`, since `sanctum.expiration`
         * is null on purpose).
         *
         * The device-list kill switch that would price that lifetime does not exist either — no
         * route, no controller, no column. It is named in `src/auth/secure-store.ts:15` as a
         * mitigation; treat that as a design intent, not a control that is in place.
         *
         * NOT BUILDABLE FROM HERE, and not a matter of effort: closing it needs a table, a model
         * trait, and three routes with controllers, none of which exist — every one of the four
         * things the skeleton-only scope rule says stop for. Owner: `control-plane-engineer`.
         *
         * The call stays, and stays FIRST, for when that lands: a purge-first logout that then
         * fails the network leaves a live token on the server with no way for this device to name
         * it. The `catch` is deliberate — a revoke that fails must never be a reason to keep the
         * credential on the device.
         */
        try {
          await revokeCurrentToken();
        } catch {
          // Reported by the caller; never a reason to keep the token on the device.
        }
        await clearLocalState();
      },
    }),
    [status, organizationId, userId, clearLocalState],
  );

  return (
    <SessionContext.Provider value={value}>
      <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
    </SessionContext.Provider>
  );
}
