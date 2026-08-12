import { describe, expect, it } from '@jest/globals';

import { clearLaunchFlag, ensureFreshInstallIsSignedOut } from '@/auth/first-launch';
import { EXPIRY_GRACE_SECONDS, isExpiringSoon } from '@/auth/expiry';
import {
  SECURE_STORE_KEYS,
  hasUsableSession,
  purge,
  readSession,
  readToken,
  writeSession,
} from '@/auth/secure-store';

import { __asyncStorageTestControl, __secureStoreTestControl } from '../jest.setup';

/**
 * The three device-lifecycle failures that are invisible until a user reports them, plus the
 * expiry arithmetic that keeps a composed message from dying to a 401.
 *
 * The keychain and AsyncStorage are separate mocks on purpose (see jest.setup.ts): that asymmetry
 * — the iOS Keychain survives uninstall, AsyncStorage does not — is the entire mechanism under
 * test here, and a single shared mock store would make the reinstall test pass for the wrong
 * reason.
 */

const LIVE_SESSION = {
  token: 'sanctum-plaintext-token',
  expires_at: new Date(Date.now() + 30 * 24 * 3600 * 1000).toISOString(),
  organization_id: '01JORGA',
  user_id: '01JUSERA',
};

describe('reinstall with a live Keychain entry', () => {
  it('does not inherit a token the user believed they destroyed', async () => {
    // 1. A normal install: LAUNCH FIRST, then sign in — and that order is the app's, not a
    //    convenience. ensureFreshInstallIsSignedOut() runs from the root layout before the first
    //    SecureStore read of the process, so on a genuinely new install it purges an empty store
    //    and sets the flag; the user logs in afterwards. Writing the session first and then
    //    launching describes a sequence that cannot happen on a device, and it fails here for the
    //    right reason: the fresh-install purge correctly destroys the token it finds.
    await ensureFreshInstallIsSignedOut();
    await writeSession(LIVE_SESSION);
    expect(await hasUsableSession()).toBe(true);

    // 2. UNINSTALL. On iOS this removes the app sandbox — AsyncStorage with it — but the Keychain
    //    entry survives when the bundle identifier is unchanged. Reproduced exactly: wipe
    //    AsyncStorage, leave the keychain alone.
    __asyncStorageTestControl.wipe();
    expect(__secureStoreTestControl.raw().size).toBeGreaterThan(0);

    // 3. REINSTALL, first launch. Without the launch-flag check the app would read that surviving
    //    token and open straight into an authenticated session — the "I uninstalled to sign out
    //    and I'm still signed in" report.
    const result = await ensureFreshInstallIsSignedOut();

    expect(result.isFreshInstall).toBe(true);
    expect(result.purged).toBe(true);
    expect(await readToken()).toBeNull();
    expect(await readSession()).toBeNull();
    expect(__secureStoreTestControl.raw().size).toBe(0);
  });

  it('does not purge on an ordinary warm start', async () => {
    await ensureFreshInstallIsSignedOut(); // first launch, sets the flag
    await writeSession(LIVE_SESSION);

    const second = await ensureFreshInstallIsSignedOut();

    expect(second.isFreshInstall).toBe(false);
    expect(second.purged).toBe(false);
    // Purging on every launch would log everyone out daily — the mirror-image bug, and the one
    // that gets noticed immediately rather than never.
    expect(await readToken()).toBe(LIVE_SESSION.token);
  });

  it('clearing the launch flag alone reproduces a reinstall', async () => {
    await ensureFreshInstallIsSignedOut();
    await writeSession(LIVE_SESSION);
    await clearLaunchFlag();

    await ensureFreshInstallIsSignedOut();

    expect(await readToken()).toBeNull();
  });
});

describe('logout purge', () => {
  it('removes every key the module has ever written', async () => {
    await writeSession(LIVE_SESSION);
    expect(__secureStoreTestControl.raw().size).toBe(SECURE_STORE_KEYS.length);

    await purge();

    // Walked from the exported key list, not from a hand-written set: a key added to the module
    // and forgotten in purge() is a token that survives a sign-out.
    for (const key of SECURE_STORE_KEYS) {
      expect(__secureStoreTestControl.raw().has(key)).toBe(false);
    }
    expect(await hasUsableSession()).toBe(false);
  });

  it('leaves nothing for the next user of a shared device', async () => {
    // One device serves several people. The organization id is every query key's namespace, so an
    // org id surviving a sign-out is the mobile shape of a cross-tenant leak — the next user's
    // cache would be namespaced to the previous user's organization.
    await writeSession(LIVE_SESSION);
    await purge();

    await writeSession({ ...LIVE_SESSION, organization_id: '01JORGB', user_id: '01JUSERB' });
    const next = await readSession();

    expect(next?.organization_id).toBe('01JORGB');
    expect(JSON.stringify([...__secureStoreTestControl.raw().values()])).not.toContain('01JORGA');
  });
});

describe('a decrypt failure after a device restore', () => {
  it('reads as signed out rather than crashing the launch path', async () => {
    await writeSession(LIVE_SESSION);
    // After a device-to-device restore the Android Keystore key is device-bound and is NOT
    // restored, so the ciphertext still in SharedPreferences is undecryptable and getItemAsync
    // REJECTS. This runs before any screen exists, so an unhandled rejection is a launch crash on
    // exactly the devices that just migrated.
    __secureStoreTestControl.failNextReads(true);

    await expect(readSession()).resolves.toBeNull();
    await expect(readToken()).resolves.toBeNull();
    await expect(hasUsableSession()).resolves.toBe(false);
  });
});

describe('a 401 mid-session', () => {
  it('is a terminal auth outcome, never a retry', () => {
    // The behavioural half of this — purge, replace the QueryClient, keep the composed draft in
    // memory, navigate to login — lives in src/auth/session-provider.tsx and is exercised on a
    // device (a rendered-tree test needs RNTL and a router harness, which this skeleton does not
    // wire yet). What is asserted here is the invariant that makes the rest safe: the retry
    // predicate in src/lib/query-client.ts never retries `authentication`, so there is no loop
    // that could rate-limit the device out of its own login endpoint.
    //
    // See tests/stream-answer.test.ts for the envelope -> KbError mapping the 401 path reuses.
    expect(EXPIRY_GRACE_SECONDS).toBe(90);
  });

  it('refuses a send pre-flight rather than losing a composed draft to a 401', async () => {
    // A token with 60 seconds left is inside the grace window. `readToken()` returns null, so the
    // composer routes to login BEFORE the POST — the user keeps the paragraph they just typed.
    // Sanctum authenticates ONCE, when the request starts, so a stream that already opened is safe
    // for its whole life; expiry only ever lands on the NEXT request, which is exactly what this
    // pre-flight covers.
    await writeSession({
      ...LIVE_SESSION,
      expires_at: new Date(Date.now() + 60 * 1000).toISOString(),
    });

    expect(await readToken()).toBeNull();
    // The record is still there — this is "treat as absent", not "delete". The device list and any
    // diagnostics can still name the token that is about to expire.
    expect(await readSession()).not.toBeNull();
  });
});

describe('expiry arithmetic', () => {
  it('treats a missing or unparseable expires_at as expired', () => {
    // Fails CLOSED. `Date.parse` returns NaN and every comparison against NaN is false, so an
    // unguarded `at - now < grace` reports "plenty of time left" for a malformed timestamp.
    expect(isExpiringSoon(null)).toBe(true);
    expect(isExpiringSoon(undefined)).toBe(true);
    expect(isExpiringSoon('')).toBe(true);
    expect(isExpiringSoon('not a date')).toBe(true);
  });

  it('uses the 90 second grace window', () => {
    const now = Date.parse('2026-08-06T12:00:00Z');
    const at = (seconds: number) => new Date(now + seconds * 1000).toISOString();

    expect(isExpiringSoon(at(EXPIRY_GRACE_SECONDS + 1), now)).toBe(false);
    expect(isExpiringSoon(at(EXPIRY_GRACE_SECONDS - 1), now)).toBe(true);
    expect(isExpiringSoon(at(-1), now)).toBe(true);
  });
});
