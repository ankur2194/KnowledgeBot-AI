import * as SecureStore from 'expo-secure-store';

import { isExpiringSoon } from './expiry';

/**
 * The ONE module in this app that touches expo-secure-store. ESLint bans the import everywhere
 * else, because "we always pass WHEN_UNLOCKED_THIS_DEVICE_ONLY" is only true if there is one call
 * site to check.
 *
 * WHAT SecureStore BUYS: encryption at rest under the iOS Keychain and the Android Keystore, and
 * exclusion from Android Auto Backup.
 * WHAT IT DOES NOT BUY: protection from a rooted or jailbroken device (the OS keystore hands the
 * value to any process running as the app), from a Frida-style runtime hook, or — at the DEFAULT
 * accessibility — from an encrypted device backup restored onto different hardware. That residual
 * is meant to be priced by the token's hard 30-day `expires_at` and by a revocable device list,
 * not by this API — and NEITHER OF THOSE TWO EXISTS YET. Verified 2026-08-11: no mint route, so no
 * `expires_at` is ever issued; no device-list route, controller or column anywhere in
 * services/core-api. Read that sentence as the intended pricing, not as a control in place, and
 * see the note at `signOut` in src/auth/session-provider.tsx. Never AsyncStorage (plaintext on
 * disk), never a persisted store, never a log line, never a URL.
 */

const TOKEN_KEY = 'kb.auth.token';
const EXPIRES_AT_KEY = 'kb.auth.expires_at';
const ORGANIZATION_ID_KEY = 'kb.auth.organization_id';
const USER_ID_KEY = 'kb.auth.user_id';

/**
 * Every key this module has ever written. `purge()` walks this list, and so does the fresh-install
 * check in first-launch.ts — which is why the list is exported rather than inlined: a key added
 * here and forgotten there is a token that survives a reinstall.
 */
export const SECURE_STORE_KEYS = [
  TOKEN_KEY,
  EXPIRES_AT_KEY,
  ORGANIZATION_ID_KEY,
  USER_ID_KEY,
] as const;

/**
 * `WHEN_UNLOCKED_THIS_DEVICE_ONLY`, and the two halves of that choice are opposite failures of the
 * same option:
 *
 *  - The DEFAULT (`WHEN_UNLOCKED`, without `_THIS_DEVICE_ONLY`) makes the item eligible for an
 *    encrypted device backup, so an iCloud restore onto a new phone carries a live bearer token
 *    with it. `_THIS_DEVICE_ONLY` excludes it from the backup.
 *  - `requireAuthentication: true` is the mirror image and is NEVER used here: it invalidates the
 *    entry whenever the user's biometric enrolment changes, so half the user base is silently
 *    logged out after a phone update. It is banned by an ESLint selector, not just by convention.
 */
const OPTIONS: SecureStore.SecureStoreOptions = {
  keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
};

/** What a successful login persists. There is no other shape. */
export interface StoredSession {
  /** The Sanctum personal access token. Abilities are `chat:send` and `conversations:read`. */
  readonly token: string;
  /** ISO-8601, issued by Laravel. Stored BESIDE the token so expiry is decidable offline. */
  readonly expires_at: string;
  /** The organization this token is scoped to. A CACHE NAMESPACE on the client — Laravel derives
   *  the real one from the token and ignores anything we send. */
  readonly organization_id: string;
  readonly user_id: string;
}

/**
 * Reads one key, mapping every failure to `null`.
 *
 * BEHAVIOUR 1 OF 2 THAT IS NOT OBVIOUS — a decrypt failure means "no token", never a crash.
 * After a device-to-device restore the Android Keystore key is device-bound and is NOT restored,
 * so the ciphertext still sitting in SharedPreferences is undecryptable and `getItemAsync`
 * REJECTS. This runs on the cold-start path, before any screen exists, so an unhandled rejection
 * there is a launch crash on exactly the devices that just migrated — reported as "the app won't
 * open after I got my new phone", with no stack anyone can reproduce.
 *
 * (expo-secure-store excludes itself from Android Auto Backup by default. A config plugin that
 * sets a custom `dataExtractionRules`/`fullBackupContent` re-includes it, which is how the
 * undecryptable ciphertext gets there in the first place. We ship no such plugin.)
 *
 * Covered by tests/auth.test.ts › "a decrypt failure after a device restore reads as signed out".
 */
async function readKey(key: string): Promise<string | null> {
  try {
    return await SecureStore.getItemAsync(key, OPTIONS);
  } catch {
    // Deliberately swallowed and deliberately not logged with the key's value. The correct
    // recovery is the same as "never signed in": show login. Callers must not distinguish the two.
    return null;
  }
}

/**
 * The raw persisted session, with NO expiry judgement applied. Use it for the device list and for
 * diagnostics. Screens and the route gate use `hasUsableSession()`; the API client uses
 * `readToken()`.
 */
export async function readSession(): Promise<StoredSession | null> {
  const [token, expiresAt, organizationId, userId] = await Promise.all([
    readKey(TOKEN_KEY),
    readKey(EXPIRES_AT_KEY),
    readKey(ORGANIZATION_ID_KEY),
    readKey(USER_ID_KEY),
  ]);

  // Partial state is no state. A half-written record (an app killed between two setItemAsync
  // calls) must not produce a token with an unknown org, because the org is every cache key's
  // namespace and a wrong one is the mobile shape of a cross-tenant leak.
  if (token === null || expiresAt === null || organizationId === null || userId === null) {
    return null;
  }

  return { token, expires_at: expiresAt, organization_id: organizationId, user_id: userId };
}

/**
 * The token, or null when there is none or it is within the expiry grace window.
 *
 * "Treat as absent" is literal: this returns null rather than an expired string, so no caller can
 * decide to try it anyway. src/api/client.ts is the only module that calls this.
 */
export async function readToken(now: number = Date.now()): Promise<string | null> {
  const session = await readSession();
  if (session === null) return null;
  if (isExpiringSoon(session.expires_at, now)) return null;
  return session.token;
}

/** Cheap enough for the route gate, and returns no secret. */
export async function hasUsableSession(now: number = Date.now()): Promise<boolean> {
  const session = await readSession();
  return session !== null && !isExpiringSoon(session.expires_at, now);
}

/**
 * Writes a freshly minted session.
 *
 * Purges first so a shorter new value cannot leave a longer old one behind under a key this build
 * stopped writing — the shape of bug that leaves a previous user's org id next to the current
 * user's token.
 */
export async function writeSession(session: StoredSession): Promise<void> {
  await purge();
  await Promise.all([
    SecureStore.setItemAsync(TOKEN_KEY, session.token, OPTIONS),
    SecureStore.setItemAsync(EXPIRES_AT_KEY, session.expires_at, OPTIONS),
    SecureStore.setItemAsync(ORGANIZATION_ID_KEY, session.organization_id, OPTIONS),
    SecureStore.setItemAsync(USER_ID_KEY, session.user_id, OPTIONS),
  ]);
}

/**
 * Named `writeToken` for symmetry with `readToken`; takes the whole record because a token without
 * its `expires_at` and its organization is not storable — see `readSession`.
 */
export const writeToken = writeSession;

/**
 * Removes every key this module owns. Called on logout, on a 401, and — this is
 *
 * BEHAVIOUR 2 OF 2 THAT IS NOT OBVIOUS — on the FIRST LAUNCH AFTER A REINSTALL.
 * On iOS the Keychain SURVIVES app deletion when the bundle identifier is unchanged. A user who
 * "signs out by uninstalling" therefore reinstalls and is still signed in, holding a token they
 * believe they destroyed. Nothing in this module can detect that on its own — the keychain looks
 * exactly like a normal warm start. src/auth/first-launch.ts is the detector: it keys off a flag
 * in AsyncStorage, which IS removed on uninstall, and calls this function when the flag is missing.
 * That asymmetry is the entire mechanism, and it is why first-launch.ts exists as a separate file
 * rather than as three lines in the root layout.
 *
 * Covered by tests/auth.test.ts › "a reinstall does not inherit a live Keychain token".
 *
 * Errors are swallowed per key: a purge that stops halfway because one key was already absent is
 * worse than one that over-deletes.
 */
export async function purge(): Promise<void> {
  await Promise.all(
    SECURE_STORE_KEYS.map(async (key) => {
      try {
        await SecureStore.deleteItemAsync(key, OPTIONS);
      } catch {
        // Already gone, or unreadable. Both are the outcome we wanted.
      }
    }),
  );
}

export { isExpiringSoon };
