/**
 * REQUIRED, not optional. Two independent consumers read this file and neither falls back:
 * Metro compiles every module in the app through it, and jest-expo's transform loads it to compile
 * the same modules for the test run. Delete it and the bundler fails on the first JSX file; delete
 * it after the fact and the suite fails on `import` in a way that reads like a module-format
 * problem rather than a missing Babel config.
 *
 * `babel-preset-expo` is the whole configuration. In particular it already contains the
 * expo-router transform — the separate `expo-router/babel` plugin was folded in and adding it back
 * is a duplicate-plugin error, not a no-op. It also handles the `EXPO_PUBLIC_*` inlining that
 * app.config.ts's allow-list guards, and the reanimated/worklets plugin ordering if those are ever
 * added (they must come LAST in `plugins`).
 *
 * `api.cache(true)` is safe here because this config depends on nothing that varies per build. The
 * moment it reads `process.env`, switch to `api.cache.using(() => process.env.X)` — a plain
 * `cache(true)` over an env-dependent config serves the first build's output to every later one.
 */
module.exports = function (api) {
  api.cache(true);
  return {
    presets: [['babel-preset-expo', { unstable_transformImportMeta: true }]],
  };
};
