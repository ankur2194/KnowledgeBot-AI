import { afterEach, jest } from '@jest/globals';

/**
 * src/lib/env.ts THROWS at module load when EXPO_PUBLIC_API_ORIGIN is absent, on purpose: a
 * missing origin is a build defect and there is no degraded mode worth shipping. In a real build
 * babel-preset-expo inlines the value from the matching `env` block in eas.json, and under
 * `expo start` it comes from a .env file — neither of which exists in a Jest process, so any
 * suite that transitively imports src/api/client.ts dies before its first assertion.
 *
 * Set here rather than in a committed .env, because a .env in the workspace would also be picked
 * up by `expo start` and would silently become an undeclared local default. This runs before the
 * test modules are required, which is what makes the assignment visible to that module-load check.
 *
 * The value is deliberately the unroutable-in-CI localhost origin from .env.example: no test may
 * reach a real host, and the ONE suite that speaks HTTP (tests/stream-answer.test.ts) binds its
 * own loopback SSE fixture and passes that origin in explicitly.
 */
process.env.EXPO_PUBLIC_API_ORIGIN ??= 'http://localhost:8080';

/**
 * Global test setup.
 *
 * `@jest/globals` is imported explicitly rather than relying on ambient `@types/jest`. That keeps
 * one more version out of the manifest (see the deliberately-unresolved note in package.json) and
 * it keeps `tsc --noEmit` honest: an ambient `jest` global makes a typo in a matcher name compile.
 *
 * Note what is NOT here: no `@testing-library/jest-native`. Its matchers merged into RNTL 12.4+,
 * so importing it double-registers them; the second registration wins and failure messages stop
 * matching the assertions that produced them.
 */

/**
 * expo-secure-store is a native module with no JS fallback, so every test that touches auth would
 * otherwise die on `TurboModuleRegistry.getEnforcing`. The mock below is an in-memory keychain
 * with the two behaviours that actually matter on a device, and both are load-bearing:
 *
 *  1. It PERSISTS ACROSS `AsyncStorage` BEING CLEARED. On iOS the Keychain survives app deletion
 *     when the bundle id is unchanged, so a user who "signs out by uninstalling" reinstalls and
 *     finds a live token. tests/auth.test.ts drives exactly that shape, and it only works because
 *     this store is separate from the AsyncStorage mock below.
 *  2. It can be told to THROW ON READ. After a device-to-device restore the Android Keystore key
 *     is device-bound and is not restored, so the ciphertext in SharedPreferences is
 *     undecryptable and `getItemAsync` rejects. That must read as "no token", never as a crash on
 *     the launch path.
 */
/**
 * THE `mock` PREFIX ON THESE TWO NAMES IS LOAD-BEARING, not a style choice.
 *
 * `jest.mock()` is hoisted above every import in the file, so its factory can run before any
 * module-scope `const` has been initialised. babel-plugin-jest-hoist guards against reading a
 * still-uninitialised binding by REFUSING the whole file — `ReferenceError: The module factory of
 * jest.mock() is not allowed to reference any out-of-scope variables` — with one documented
 * exception: identifiers whose name begins with `mock`, case-insensitive, which the plugin treats
 * as a deliberate opt-out. The factories below are only ever called lazily, when a test requires
 * expo-secure-store, so the opt-out is sound here.
 *
 * Getting this wrong does not fail a test. It fails every suite in the project at TRANSFORM time,
 * before a single assertion runs, because this file is `setupFilesAfterEach` for all of them.
 */
const mockSecureStore = new Map<string, string>();
let mockSecureStoreReadFails = false;

export const __secureStoreTestControl = {
  seed(key: string, value: string): void {
    mockSecureStore.set(key, value);
  },
  raw(): ReadonlyMap<string, string> {
    return mockSecureStore;
  },
  failNextReads(fail: boolean): void {
    mockSecureStoreReadFails = fail;
  },
  reset(): void {
    mockSecureStore.clear();
    mockSecureStoreReadFails = false;
  },
};

jest.mock('expo-secure-store', () => ({
  WHEN_UNLOCKED_THIS_DEVICE_ONLY: 'whenUnlockedThisDeviceOnly',
  WHEN_UNLOCKED: 'whenUnlocked',
  AFTER_FIRST_UNLOCK_THIS_DEVICE_ONLY: 'afterFirstUnlockThisDeviceOnly',
  getItemAsync: async (key: string): Promise<string | null> => {
    if (mockSecureStoreReadFails) {
      throw new Error('Could not decrypt the value for key "' + key + '"');
    }
    return mockSecureStore.has(key) ? (mockSecureStore.get(key) as string) : null;
  },
  setItemAsync: async (key: string, value: string): Promise<void> => {
    mockSecureStore.set(key, value);
  },
  deleteItemAsync: async (key: string): Promise<void> => {
    mockSecureStore.delete(key);
  },
  isAvailableAsync: async (): Promise<boolean> => true,
}));

/**
 * AsyncStorage is the OTHER half of the reinstall story: unlike the Keychain, it IS removed on
 * uninstall. That asymmetry is the entire mechanism behind src/auth/first-launch.ts, so the two
 * mocks are deliberately independent stores and `__asyncStorageTestControl.wipe()` simulates an
 * uninstall without touching the keychain.
 */
const mockAsyncStorage = new Map<string, string>();

export const __asyncStorageTestControl = {
  wipe(): void {
    mockAsyncStorage.clear();
  },
  raw(): ReadonlyMap<string, string> {
    return mockAsyncStorage;
  },
};

jest.mock('@react-native-async-storage/async-storage', () => ({
  __esModule: true,
  default: {
    getItem: async (key: string): Promise<string | null> =>
      mockAsyncStorage.has(key) ? (mockAsyncStorage.get(key) as string) : null,
    setItem: async (key: string, value: string): Promise<void> => {
      mockAsyncStorage.set(key, value);
    },
    removeItem: async (key: string): Promise<void> => {
      mockAsyncStorage.delete(key);
    },
    clear: async (): Promise<void> => {
      mockAsyncStorage.clear();
    },
  },
}));

afterEach(() => {
  __secureStoreTestControl.reset();
  __asyncStorageTestControl.wipe();
});
