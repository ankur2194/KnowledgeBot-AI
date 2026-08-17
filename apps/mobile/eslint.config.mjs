import security from 'eslint-plugin-security';
import tseslint from 'typescript-eslint';

// The root manifest declares ZERO dependencies and ZERO devDependencies (a root dependency would
// write a fifth pnpm-lock.yaml — see the repo .npmrc), so every plugin above is installed HERE.
// eslint.base.mjs contributes plain data only and imports nothing, which is what makes it
// importable from a workspace that has its own plugin versions.
import { ignores, kbRestrictedSyntax, kbRules } from '../../eslint.base.mjs';

/**
 * The repo-wide restricted globals (today: `EventSource`), taken from `kbRules` as DATA rather than
 * restated, so the block below can extend the list without forking it. Flat config REPLACES a
 * rule's options, so any override that sets `no-restricted-globals` must spread this or it silently
 * un-bans `EventSource` for exactly the files it was written to tighten — the same trap the
 * `no-restricted-imports` comment above describes. `.slice(1)` drops the leading severity string.
 */
const kbRestrictedGlobals = kbRules['no-restricted-globals'].slice(1);

/**
 * Import bans that must survive every per-directory override. Flat config REPLACES a rule's
 * options rather than merging them, so an override block that re-declares `no-restricted-imports`
 * without spreading these silently un-bans reaching into `@kb/contracts/src` — for exactly the
 * files the override was written to tighten. Both arrays below are spread into every block.
 */
const mobileRestrictedImportPatterns = [
  {
    // Reaching past the exports map bypasses the `types`/`require` condition split and the
    // ./forms subpath (ADR-028). It also breaks the mobile job outright, which typechecks
    // against dist/, not src/.
    group: ['@kb/contracts/src/*', '@kb/contracts/dist/*'],
    message: 'Import the package entry point (@kb/contracts or @kb/contracts/forms).',
  },
];

/**
 * The two credential-store module bans, as data. They are declared once here and composed into
 * three blocks below, because each has a different single-file exemption and `no-restricted-imports`
 * cannot express two exemptions in one block — see the block comment where they are used for the
 * defect that shape caused.
 */
const secureStoreImportPath = {
  name: 'expo-secure-store',
  message: 'src/auth/secure-store.ts is the only module that may touch SecureStore.',
};
const asyncStorageImportPath = {
  name: '@react-native-async-storage/async-storage',
  message:
    'AsyncStorage is plaintext on disk. Only src/auth/first-launch.ts may use it, and only for the launch flag.',
};

/**
 * The `retry:` ban, as data, for the same reason: two blocks need it and a restated copy is how the
 * narrower one silently drops it.
 */
const retryPolicyRestrictedSyntax = {
  selector: "Property[key.name='retry']",
  message: 'Retry policy lives only in src/lib/query-client.ts (tanstack-query-table NN4).',
};

/**
 * THE STREAMING FETCH BINDING — the one constraint in this app that is load-bearing ONLY ON A
 * DEVICE, and therefore the one no test in this repo can defend.
 *
 * `src/features/chat/stream-answer.ts` imports `fetch` from `expo/fetch` BY NAME. On Hermes the
 * global `fetch` is XHR-backed: the request succeeds, the whole answer arrives in one piece, and
 * `res.body` is null. It does not throw and does not warn — Hermes fails by SUCCEEDING — so no
 * assertion about the final text can see it, and this suite runs on NODE, where the global streams.
 *
 * Three comments in this package used to say CI greps for the by-name import. It never did, and
 * there is no CI now in any case. Those three comments say what is true; this array is the
 * enforcement they described, written where the constraint actually lives, so it runs under
 * `pnpm lint` in this workspace — which is the only tier that exists.
 *
 * WHY NOT JUST LEAN ON JEST, WHICH ALSO GOES RED. Measured, do not re-derive: deleting the import
 * and calling the global fails 20 of the 69 tests — and every one of them reads `KbError: HTTP
 * undefined`, which points at the SERVER. That redness is an accident of the harness, not of the
 * constraint: what sits on `globalThis.fetch` under jest-expo is a Babel-transpiled stub with no
 * `status` (see `tests/mocks/expo-fetch.ts:6-13`). The day that stub delegates to Node's real fetch
 * — which streams, and whose `Response.body` is never null — the suite goes GREEN on a build that
 * cannot stream on a device, and the only signal left is this one. ESLint's error lands on the
 * import itself and names Hermes; it depends on nothing that might get fixed underneath it.
 *
 * WHAT IT CATCHES, mechanically, in `src/features/chat/` only:
 *   1. the global `fetch` (via `no-restricted-globals`, not this array) — a reference resolves to
 *      the declared global ONLY when the module has no `fetch` binding of its own, so it fires
 *      exactly when the import is gone and stays silent while it is there;
 *   2. `globalThis.fetch` / `global.fetch`, the same edit spelled to dodge (1);
 *   3. importing a name called `fetch` from any module other than `expo/fetch`.
 *
 * WHAT IT DOES NOT CATCH, stated rather than implied: it cannot prove `res.body !== null` on
 * Hermes, it does not defend the `response.body === null` throw at `stream-answer.ts:133`, and it
 * would not see a `fetch` destructured out of a variable that aliases the global. It is a cheap
 * mechanical guard against the realistic edit, not a substitute for the device run — which remains
 * UNPERFORMED (`docs/23` says so at length; a workflow comment used to as well).
 */
const streamingFetchRestrictedSyntax = [
  {
    selector: "MemberExpression[object.name=/^(?:globalThis|global)$/][property.name='fetch']",
    message:
      "Import { fetch } from 'expo/fetch'. Reaching the global through globalThis gets Hermes' XHR fetch, whose res.body is null.",
  },
  {
    selector:
      "ImportDeclaration[source.value!='expo/fetch'] > ImportSpecifier[imported.name='fetch']",
    message:
      "'fetch' in this directory comes from 'expo/fetch' and nowhere else — that named import is the only form that cannot be shadowed, or switched off by the Expo opt-out flag.",
  },
];

/**
 * Selectors specific to this app. Each encodes a failure that is silent: a long answer killed
 * mid-generation, and a keychain option that logs half the user base out after a phone update.
 */
const mobileRestrictedSyntax = [
  {
    // `AbortSignal.timeout()` is honoured by expo/fetch for the WHOLE request including the
    // streamed body, so it kills a healthy long generation at a fixed elapsed time. The symptom is
    // "long answers truncate at exactly N seconds" and it never reproduces on a short one. A
    // streaming request has no total-duration timeout; the server owns that. Use the idle-gap
    // watchdog in src/features/chat/stream-answer.ts.
    selector: "MemberExpression[object.name='AbortSignal'][property.name='timeout']",
    message:
      'AbortSignal.timeout kills a healthy stream at a fixed elapsed time. Use the idle-gap watchdog.',
  },
  {
    // The default keychainAccessible allows the item into an encrypted device backup, so it can be
    // restored onto DIFFERENT hardware. Its mirror-image failure, requireAuthentication, is banned
    // below: it invalidates the entry whenever biometric enrolment changes, which silently logs out
    // half the user base after a phone update.
    selector: "Property[key.name='requireAuthentication']",
    message:
      'Never requireAuthentication on the API token: a biometric re-enrolment invalidates it and logs the user out.',
  },
];

export default tseslint.config(
  {
    ignores: [...ignores, '.expo/**', 'ios/**', 'android/**', 'expo-env.d.ts'],
  },
  ...tseslint.configs.recommended,
  security.configs.recommended,
  {
    files: ['**/*.{ts,tsx,mjs}'],
    languageOptions: {
      // No `globals` package and no eslint-config-expo: both would add a version this workspace
      // cannot verify offline. This is the whole set the app actually reaches for.
      globals: {
        __DEV__: 'readonly',
        console: 'readonly',
        fetch: 'readonly',
        setTimeout: 'readonly',
        clearTimeout: 'readonly',
        setInterval: 'readonly',
        clearInterval: 'readonly',
        requestAnimationFrame: 'readonly',
        cancelAnimationFrame: 'readonly',
        TextDecoder: 'readonly',
        AbortController: 'readonly',
        AbortSignal: 'readonly',
        process: 'readonly',
        globalThis: 'readonly',
      },
    },
    rules: {
      ...kbRules,
      'no-restricted-syntax': ['error', ...kbRestrictedSyntax, ...mobileRestrictedSyntax],
      'no-restricted-imports': ['error', { patterns: [...mobileRestrictedImportPatterns] }],
      '@typescript-eslint/consistent-type-imports': 'error',
      '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
    },
  },
  {
    /**
     * BOTH credential-store bans in ONE block, and that is a fix rather than tidying.
     *
     * They used to be two consecutive blocks, each declaring `no-restricted-imports` with a single
     * `paths` entry and overlapping `files`. Flat config REPLACES a rule's options, so the second
     * block silently deleted the first's `paths` for every file both matched — which was all of
     * `src/**` and `app/**`. Measured before the fix: a probe file importing BOTH modules reported
     * only the AsyncStorage error, and a probe importing `expo-secure-store` was flagged in
     * `plugins/` alone — the one root that would never import it. So the ban whose comment says
     * "src/auth/secure-store.ts is the only module that may touch SecureStore" was, for two months,
     * enforced nowhere that could violate it. The header comment above warns about exactly this
     * trap for `patterns`; `paths` fell into it.
     *
     * The two exemptions therefore have to be per-file blocks below rather than `ignores` here,
     * because each file is exempt from ONE path and still subject to the other.
     *
     * expo-secure-store is a credential store, and "the ONE module that writes it" is only true if
     * it is also the one module that IMPORTS it. Without this, a second call site with the default
     * keychainAccessible (backup-eligible) or with requireAuthentication is one import away.
     *
     * AsyncStorage is PLAINTEXT ON DISK and is included in Android Auto Backup. It has exactly one
     * legitimate use in this app: the `hasLaunched` flag, whose entire value is that it is erased
     * on uninstall while the iOS Keychain is not. Nothing else — no token, no conversation text,
     * no org id — may be written there.
     */
    files: ['src/**/*.{ts,tsx}', 'app/**/*.tsx', 'plugins/**/*.ts'],
    rules: {
      'no-restricted-imports': [
        'error',
        {
          patterns: [...mobileRestrictedImportPatterns],
          paths: [secureStoreImportPath, asyncStorageImportPath],
        },
      ],
    },
  },
  {
    // The SecureStore wrapper may import SecureStore, and nothing else.
    files: ['src/auth/secure-store.ts'],
    rules: {
      'no-restricted-imports': [
        'error',
        { patterns: [...mobileRestrictedImportPatterns], paths: [asyncStorageImportPath] },
      ],
    },
  },
  {
    // The first-launch flag may import AsyncStorage, and nothing else.
    files: ['src/auth/first-launch.ts'],
    rules: {
      'no-restricted-imports': [
        'error',
        { patterns: [...mobileRestrictedImportPatterns], paths: [secureStoreImportPath] },
      ],
    },
  },
  {
    // Retry counts live in exactly one file. A per-call `retry:` re-multiplies attempts against the
    // FastAPI adapter's own ladder — 6 to 12 provider calls per user tap.
    files: ['src/**/*.{ts,tsx}', 'app/**/*.tsx'],
    ignores: ['src/lib/query-client.ts'],
    rules: {
      'no-restricted-syntax': [
        'error',
        // Spread, never replace: flat config REPLACES a rule's options, so re-declaring this rule
        // without the base selectors quietly un-bans the camelCased envelope-field selectors.
        ...kbRestrictedSyntax,
        ...mobileRestrictedSyntax,
        retryPolicyRestrictedSyntax,
      ],
    },
  },
  {
    /**
     * The streaming-fetch guard. See `streamingFetchRestrictedSyntax` above for what it catches and
     * — just as important — what it cannot.
     *
     * THIS BLOCK MUST STAY BELOW THE `retry:` BLOCK. That block matches all of `src/**`, so it
     * matches this directory too, and flat config REPLACES a rule's options. Placed above it, these
     * selectors would be deleted for exactly the files they exist to protect — which is the defect
     * that had already silently killed the `expo-secure-store` ban further up this file. Hence the
     * `retryPolicyRestrictedSyntax` spread here: the narrower block re-states everything the wider
     * one contributed, from the same constants, so neither can drift.
     */
    files: ['src/features/chat/**/*.{ts,tsx}'],
    rules: {
      'no-restricted-globals': [
        'error',
        ...kbRestrictedGlobals,
        {
          name: 'fetch',
          message:
            "Import { fetch } from 'expo/fetch'. The global fetch is XHR-backed on Hermes: res.body is null and the whole answer arrives at once, with no error and no warning. This suite runs on Node and cannot see that.",
        },
      ],
      'no-restricted-syntax': [
        'error',
        ...kbRestrictedSyntax,
        ...mobileRestrictedSyntax,
        retryPolicyRestrictedSyntax,
        ...streamingFetchRestrictedSyntax,
      ],
    },
  },
  {
    files: ['tests/**/*.{ts,tsx}', 'jest.setup.ts'],
    rules: {
      '@typescript-eslint/no-explicit-any': 'off',
      'security/detect-object-injection': 'off',
      'security/detect-non-literal-fs-filename': 'off',
    },
  },
  {
    /**
     * The three toolchain configs are CommonJS BY NECESSITY, not by preference, and `require()` in
     * them is correct code rather than a lapse.
     *
     * Metro loads metro.config.js through metro-config's cosmiconfig loader, which `require()`s the
     * file; Jest does the same for jest.config.js, and Babel for babel.config.js. None of the three
     * is bundled or transformed first, so an ESM `import` in any of them is a runtime failure in the
     * tool, not a style question. This is also why package.json deliberately omits
     * `"type": "module"` — flipping that field breaks all three at once with three unrelated-looking
     * errors, and its own description says so.
     *
     * So the fix is scoped to these three paths. It is NOT a blanket disable: everywhere else in
     * this package a `require()` is a genuine finding, because everything else is ESM TypeScript
     * that Metro and Babel do transform.
     */
    files: ['metro.config.js', 'jest.config.js', 'babel.config.js'],
    rules: {
      '@typescript-eslint/no-require-imports': 'off',
    },
  },
);
