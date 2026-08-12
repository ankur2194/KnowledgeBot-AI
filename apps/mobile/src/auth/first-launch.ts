import AsyncStorage from '@react-native-async-storage/async-storage';

import { purge } from './secure-store';

/**
 * The reinstall detector.
 *
 * THE BUG IT EXISTS FOR: a user uninstalls the app to sign out, reinstalls it, and is still signed
 * in. On iOS the Keychain survives app deletion when the bundle identifier is unchanged, so
 * SecureStore hands back a bearer token the user believed they destroyed. Nothing about the read
 * looks unusual — it is indistinguishable from a normal warm start — so the app cannot detect this
 * from the keychain alone.
 *
 * THE MECHANISM: AsyncStorage is the opposite. It lives in the app's sandbox and IS removed on
 * uninstall. So a flag written to AsyncStorage on first launch is present on every subsequent
 * launch of the SAME install, and absent on the first launch of a NEW install — even when the
 * keychain is full. Missing flag ⇒ fresh install ⇒ purge every SecureStore key before anything
 * reads one.
 *
 * This is the one legitimate use of AsyncStorage in this app, and ESLint bans the import in every
 * other file. No token, no conversation text and no organization id is ever written here: it is
 * plaintext on disk and, unlike SecureStore, it is included in Android Auto Backup.
 *
 * Android note: the keychain does not survive uninstall there, so the purge is a no-op on that
 * platform. It runs anyway — a platform check would be one `Platform.OS` away from being wrong on
 * a future OS, and purging an already-empty store costs four deletes.
 */

/**
 * Versioned. Bumping the suffix forces a one-time purge across the whole install base — the
 * correct escape hatch if the stored session shape ever changes incompatibly, since a half-read
 * old record is exactly the partial state `readSession()` refuses.
 */
const HAS_LAUNCHED_KEY = 'kb.has_launched.v1';

export interface FirstLaunchResult {
  /** True when this is the first launch of this install (including after a reinstall). */
  readonly isFreshInstall: boolean;
  /** True when a purge actually ran. Equal to `isFreshInstall`; separate so a caller can log it
   *  without implying the two will always agree if this grows a second trigger. */
  readonly purged: boolean;
}

/**
 * Runs BEFORE the first SecureStore read of the process, from the root layout, which renders
 * nothing until this resolves. Ordering is the whole contract: a cold-start deep link that renders
 * a screen before this has run is a screen rendered against a token that should not exist.
 */
export async function ensureFreshInstallIsSignedOut(): Promise<FirstLaunchResult> {
  let flag: string | null = null;
  try {
    flag = await AsyncStorage.getItem(HAS_LAUNCHED_KEY);
  } catch {
    // An unreadable AsyncStorage is indistinguishable from an absent flag, and the safe reading of
    // "I cannot tell whether this is a fresh install" is "assume it is". The cost is one extra
    // login; the alternative cost is a token surviving a reinstall.
    flag = null;
  }

  if (flag === 'true') {
    return { isFreshInstall: false, purged: false };
  }

  await purge();

  try {
    await AsyncStorage.setItem(HAS_LAUNCHED_KEY, 'true');
  } catch {
    // If the flag cannot be written, the next launch purges again. Idempotent, and the failure
    // mode is "signed out more often", which is the direction we want to fail in.
  }

  return { isFreshInstall: true, purged: true };
}

/**
 * Test seam and a genuine operational one: clearing the flag makes the NEXT launch behave as a
 * fresh install. Used by tests/auth.test.ts to simulate an uninstall/reinstall without touching
 * the keychain mock.
 */
export async function clearLaunchFlag(): Promise<void> {
  await AsyncStorage.removeItem(HAS_LAUNCHED_KEY);
}
