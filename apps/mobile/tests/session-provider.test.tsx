import { afterEach, beforeEach, describe, expect, it, jest } from '@jest/globals';
import { act, renderHook, waitFor } from '@testing-library/react-native';
import type { ReactNode } from 'react';

import { handleAuthenticationFailure } from '@/api/client';
import { ensureFreshInstallIsSignedOut } from '@/auth/first-launch';
import { SessionProvider, useSession } from '@/auth/session-provider';
import { SECURE_STORE_KEYS, writeSession } from '@/auth/secure-store';
import { listConversations, migrate, upsertConversation } from '@/db/history';

import { openTestDatabase } from './fixtures/sqlite';
import { __secureStoreTestControl } from '../jest.setup';

/**
 * SIGN-OUT PURGES ALL THREE, OR IT PURGES NOTHING.
 *
 * The token, the in-memory query cache, and the on-disk transcript cache go together: one device
 * serves several people, and the one of the three that survives a reboot is the SQLite file. A
 * purge that nothing invokes is not a purge, and the failure is silent — `clearLocalState` carried
 * a comment saying the transcript cache was purged "here too" while calling nothing at all, which
 * reads as done to every reviewer and to every grep.
 *
 * WHAT IS DOUBLED AND WHAT IS NOT. `expo-sqlite` is a native module with no JS fallback, so the
 * module is mocked — but it is mocked to hand back a REAL SQLite engine (tests/fixtures/sqlite.ts),
 * so the production `purgeAll` executes its actual SQL against actual rows. Stubbing
 * `purgeHistoryDatabase` itself would only prove that a function this test wrote was called by a
 * function this test also wrote.
 */

const mockDatabase = openTestDatabase();

/**
 * `mockDeleteDatabaseFails` drives the FALLBACK half of `purgeHistoryDatabase`. The normal path
 * deletes every row; if that throws, it deletes the database FILE instead. Only when BOTH fail does
 * the purge reject — and that rejection is the only thing `signIn` can refuse on, so a test of
 * "a failed purge does not admit the new user" has to be able to produce it.
 */
let mockDeleteDatabaseFails = false;

jest.mock('expo-sqlite', () => ({
  openDatabaseAsync: async () => mockDatabase,
  deleteDatabaseAsync: async () => {
    if (mockDeleteDatabaseFails) throw new Error('unlink failed');
  },
}));

const LIVE_SESSION = {
  token: 'sanctum-plaintext-token',
  expires_at: new Date(Date.now() + 30 * 24 * 3600 * 1000).toISOString(),
  organization_id: '01JORGA',
  user_id: '01JUSERA',
};

const ORG_A = '01JORGA';
const USER_A = '01JUSERA';
/** A colleague in the SAME organization — the handover, and the case an org predicate cannot see. */
const USER_B = '01JUSERB';

function wrapper({ children }: { children: ReactNode }): ReactNode {
  return <SessionProvider>{children}</SessionProvider>;
}

beforeEach(async () => {
  // Forced rebuild, not just `migrate`. One `mockDatabase` is shared by the whole file (the module
  // mock is hoisted above every test), so without this a test that drops a table or leaves a row
  // behind decides the outcome of the next one — and `migrate` on its own takes the early return
  // once `user_version` matches.
  mockDeleteDatabaseFails = false;
  await mockDatabase.runAsync('PRAGMA user_version = 0');
  await migrate(mockDatabase);
  // LAUNCH FIRST, THEN SIGN IN, and that order is the app's rather than a convenience. The provider
  // runs `ensureFreshInstallIsSignedOut()` before its first SecureStore read, and jest.setup wipes
  // the AsyncStorage launch flag after every test — so without this the provider correctly treats
  // each test as a fresh install, purges the session the test just wrote, and comes up 'anonymous'.
  await ensureFreshInstallIsSignedOut();
});

afterEach(() => {
  __secureStoreTestControl.reset();
});

describe('signOut', () => {
  it('purges the on-disk transcript cache along with the token', async () => {
    await writeSession(LIVE_SESSION);
    await upsertConversation(mockDatabase, {
      id: '01JCONV',
      organization_id: ORG_A,
      user_id: USER_A,
      bot_id: '01JBOT',
      title: 'a cached transcript',
      updated_at: '2026-08-09T00:00:00Z',
    });
    expect(await listConversations(mockDatabase, ORG_A, USER_A)).toHaveLength(1);

    const { result } = renderHook(() => useSession(), { wrapper });
    await waitFor(() => {
      expect(result.current.status).toBe('authenticated');
    });

    await act(async () => {
      // `revokeCurrentToken` still throws — there is no revoke route in Laravel — and `signOut`
      // catches it on purpose: a revoke that fails must still purge locally. That the local purge
      // happens ANYWAY is half of what this asserts.
      await result.current.signOut();
    });

    // 1. The credential.
    for (const key of SECURE_STORE_KEYS) {
      expect(__secureStoreTestControl.raw().has(key)).toBe(false);
    }
    // 2. The session state, so the gate routes to login.
    await waitFor(() => {
      expect(result.current.status).toBe('anonymous');
    });
    expect(result.current.organizationId).toBeNull();
    // 3. THE ON-DISK CACHE. This is the one that survives a reboot, and the one that hands the next
    // user of a shared tablet a correctly rendered list of someone else's conversations.
    expect(await listConversations(mockDatabase, ORG_A, USER_A)).toEqual([]);
  });

  it('purges the on-disk transcript cache on a 401 as well as on an explicit sign-out', async () => {
    // The 401 path runs the same `clearLocalState` through the handler registered with the API
    // client — a token revoked from another device is a sign-out the user did not ask for, and it
    // must leave the device in the same state.
    //
    // `handleAuthenticationFailure` is imported STATICALLY at the top of this file. Babel leaves a
    // dynamic import intact and Jest runs this file as CommonJS, so `await import()` reaches the VM
    // as a real dynamic import and dies on `A dynamic import callback was invoked without
    // --experimental-vm-modules` — a failure about the module system, in a test about a purge.
    await writeSession(LIVE_SESSION);
    await upsertConversation(mockDatabase, {
      id: '01JCONV',
      organization_id: ORG_A,
      user_id: USER_A,
      bot_id: '01JBOT',
      title: 'a cached transcript',
      updated_at: '2026-08-09T00:00:00Z',
    });

    const { result } = renderHook(() => useSession(), { wrapper });
    await waitFor(() => {
      expect(result.current.status).toBe('authenticated');
    });

    await act(async () => {
      handleAuthenticationFailure();
      // The handler is fire-and-forget by design (a 401 arrives from whichever request happened to
      // be in flight, and there is no caller to report to), so the assertions wait rather than await.
    });

    await waitFor(async () => {
      expect(await listConversations(mockDatabase, ORG_A, USER_A)).toEqual([]);
    });
    expect(__secureStoreTestControl.raw().size).toBe(0);
  });
});

/**
 * SIGN-IN PURGES TOO, AND IT IS NOT SYMMETRY WITH SIGN-OUT — IT IS THE CASE SIGN-OUT CANNOT COVER.
 *
 * `signOut` purges. The 401 handler purges. A token that merely EXPIRED does neither: the cold start
 * reads it, `hasUsableSession()` says no, the provider comes up 'anonymous', and the previous user's
 * transcripts are still in the SQLite file when the next person types their password. Nothing in the
 * app runs between those two moments, so the purge has to happen on the way IN.
 */
describe('signIn', () => {
  it('purges a previous user rows before admitting a colleague in the same organization', async () => {
    // THE HANDOVER, END TO END. USER_A's transcript is on the disk with no session in SecureStore —
    // the exact state a silently expired token leaves behind — and USER_B, in the SAME organization,
    // signs in. The org predicate cannot help here: both rows are organization A's.
    await upsertConversation(mockDatabase, {
      id: '01JCONV_A',
      organization_id: ORG_A,
      user_id: USER_A,
      bot_id: '01JBOT',
      title: 'user A transcript',
      updated_at: '2026-08-09T00:00:00Z',
    });
    expect(await listConversations(mockDatabase, ORG_A, USER_A)).toHaveLength(1);

    const { result } = renderHook(() => useSession(), { wrapper });
    await waitFor(() => {
      expect(result.current.status).toBe('anonymous');
    });

    await act(async () => {
      await result.current.signIn({ ...LIVE_SESSION, user_id: USER_B });
    });

    expect(result.current.status).toBe('authenticated');
    expect(result.current.userId).toBe(USER_B);
    // Nothing of USER_A's survives, and it is asserted UNSCOPED as well as scoped: a row that is
    // merely unreadable through the current predicates is still a row on the disk, and the next
    // schema change or key bug makes it readable again.
    expect(await listConversations(mockDatabase, ORG_A, USER_A)).toEqual([]);
    expect(await mockDatabase.getAllAsync('SELECT id FROM conversations')).toEqual([]);
  });

  it('purges even when the SAME user signs in again', async () => {
    // THE PURGE IS UNCONDITIONAL, AND THIS TEST IS WHAT STOPS SOMEONE MAKING IT CONDITIONAL LATER.
    //
    // "Purge only when the incoming identity differs from the stored one" sounds strictly better and
    // is strictly worse: on the expiry path there IS no stored identity to compare against —
    // `readSession()` returns null for an expired token, for a half-written record and for an
    // Android decrypt failure alike — so the comparison reads `null` in precisely the states it
    // exists to catch, and skips the purge. The only cost of over-purging is one refetch from
    // Laravel, over a network that must already be up for a sign-in to have happened at all.
    await upsertConversation(mockDatabase, {
      id: '01JCONV_A',
      organization_id: ORG_A,
      user_id: USER_A,
      bot_id: '01JBOT',
      title: 'stale rows of unknown vintage',
      updated_at: '2026-08-09T00:00:00Z',
    });

    const { result } = renderHook(() => useSession(), { wrapper });
    await waitFor(() => {
      expect(result.current.status).toBe('anonymous');
    });

    await act(async () => {
      await result.current.signIn(LIVE_SESSION);
    });

    expect(result.current.userId).toBe(USER_A);
    expect(await listConversations(mockDatabase, ORG_A, USER_A)).toEqual([]);
  });

  it('refuses the sign-in rather than authenticating over a purge that failed', async () => {
    // THE ORDER IS THE ASSERTION: the purge runs BEFORE `writeSession`. Reverse the two and a purge
    // that fails leaves the device authenticated as the new user with the old user's rows still
    // underneath — a state nothing else in the app will ever revisit, because every later purge is
    // triggered by a sign-out or a 401 that has already happened.
    //
    // Both levels of `purgeHistoryDatabase` are made to fail here: the tables are dropped so
    // `purgeAll` throws, and the file delete throws too. Only then does the purge reject.
    await mockDatabase.execAsync('DROP TABLE messages; DROP TABLE conversations;');
    mockDeleteDatabaseFails = true;

    const { result } = renderHook(() => useSession(), { wrapper });
    await waitFor(() => {
      expect(result.current.status).toBe('anonymous');
    });

    await act(async () => {
      await expect(result.current.signIn(LIVE_SESSION)).rejects.toThrow('unlink failed');
    });

    // Still signed out, and — the part that matters — the token was never written, so no request
    // this app makes can carry the new user's credential over the old user's cache.
    expect(result.current.status).toBe('anonymous');
    expect(__secureStoreTestControl.raw().size).toBe(0);
  });
});
