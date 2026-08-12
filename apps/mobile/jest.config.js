/**
 * jest-expo's preset supplies the RN/Expo module mocks, the Babel transform (via babel.config.js)
 * and the platform-aware resolver. Its MAJOR must equal the Expo SDK major — see package.json.
 *
 * READ THIS BEFORE TRUSTING A GREEN RUN: this suite executes on NODE, not on Hermes. Node has
 * `ReadableStream`, a spec-complete `TextDecoder`, and a fetch whose `Response.body` is never
 * null. Hermes' XHR-backed fetch has none of that and fails by *succeeding* — the request goes
 * through, the whole answer arrives at once, and every assertion about the final text still
 * passes. So the tests here can prove the PARSER is correct (chunk boundaries, split codepoints,
 * heartbeat comments, exactly one terminal event) and can never prove that streaming works on a
 * device. That second proof is a device run, and it is a checklist item, not a test file.
 */

/** @type {import('jest').Config} */
module.exports = {
  preset: 'jest-expo',

  // The parser and auth tests are pure logic over strings and promises; a DOM environment would
  // replace Node's ReadableStream/TextDecoder/Response globals with jsdom's partial versions,
  // which is the opposite of what these tests need. Component tests, when they arrive, override
  // this per-file with a docblock pragma rather than flipping it globally.
  testEnvironment: 'node',

  setupFilesAfterEnv: ['<rootDir>/jest.setup.ts'],
  testMatch: ['<rootDir>/tests/**/*.test.ts', '<rootDir>/tests/**/*.test.tsx'],

  moduleNameMapper: {
    '^@/(.*)$': '<rootDir>/src/$1',

    /**
     * `expo/fetch` does not exist outside the Expo runtime, so under Node it maps to a shim that
     * re-exports Node's global fetch. This mapping is EXACTLY why a green run here is not evidence
     * of a working stream on a device: the shim hands the parser a real ReadableStream that Hermes
     * would not have provided. The production module still imports `fetch` from 'expo/fetch' by
     * name and asserts `res.body !== null` at runtime, so the degradation is visible on the device
     * rather than silent.
     *
     * WHAT ENFORCES THAT NAMED IMPORT, precisely, because this comment used to name the wrong
     * thing. It said CI greps for it. NO SUCH GREP HAS EVER EXISTED — the only `expo/fetch` under
     * `.github/workflows/` is prose at `ci.yml:691`, and there is none in `gates.yml`, none in
     * `eslint.base.mjs`, none anywhere. It is enforced by `eslint.config.mjs`, in the
     * `src/features/chat/**` block, which bans the global `fetch`, `globalThis.fetch`, and a
     * `fetch` imported by name from any other module. That runs under `pnpm lint`, which CI does
     * run. What still enforces NOTHING is the runtime half: no test in this repo can observe
     * `res.body === null`, because nothing here executes on Hermes.
     */
    '^expo/fetch$': '<rootDir>/tests/mocks/expo-fetch.ts',
  },

  /**
   * transformIgnorePatterns is "do NOT transform anything matching", and the regex is unanchored,
   * so it is tested against EVERY `node_modules/` segment in a path. Under pnpm's isolated linker
   * a real path looks like:
   *
   *   .../node_modules/.pnpm/expo-router@57.0.10/node_modules/expo-router/build/index.js
   *
   * The naive RN pattern matches at the FIRST segment (because `.pnpm` is not in its allow-list),
   * marks the file untransformed, and the run dies on `SyntaxError: Cannot use import statement
   * outside a module` from inside a package nobody edited. Adding `\.pnpm` to the negative
   * lookahead lets the outer segment through so the inner one is judged on the package name.
   */
  transformIgnorePatterns: [
    'node_modules/(?!(?:\\.pnpm|(?:jest-)?react-native|@react-native(?:-community)?|expo|expo-.*|@expo(?:nent)?|@react-navigation|react-native-.*|@testing-library|@kb)/)',
  ],

  collectCoverageFrom: ['src/**/*.{ts,tsx}', 'app/**/*.tsx', '!**/*.d.ts'],
  clearMocks: true,
  restoreMocks: true,
};
