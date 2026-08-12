/**
 * The one public value this app reads at runtime.
 *
 * `process.env.EXPO_PUBLIC_API_ORIGIN` is written LITERALLY on the next line, and that is
 * load-bearing: babel-preset-expo inlines the value by matching the member-expression text.
 * `process.env[key]` is not inlined and reads `undefined` in the bundle — with no error, so the
 * first symptom is every request going to `undefined/rt/v1/...`.
 *
 * The allow-list that fails a build on any OTHER `EXPO_PUBLIC_*` key lives in app.config.ts, not
 * here, because in the bundle `process.env` is not an object at all — it is a set of inlined
 * string literals, so `Object.keys(process.env)` is empty and can prove nothing. The check has to
 * run in Node at config time or it does not run.
 */
const rawApiOrigin = process.env.EXPO_PUBLIC_API_ORIGIN;

if (rawApiOrigin === undefined || rawApiOrigin === '') {
  // Thrown at module load, i.e. at app start, on purpose. A missing origin is a build defect and
  // there is no degraded mode worth shipping — every screen in this app needs Laravel.
  throw new Error(
    'EXPO_PUBLIC_API_ORIGIN is missing. It is set per build profile in eas.json; see .env.example.',
  );
}

/**
 * Origin only — scheme, host, optional port. Every request path is concatenated onto it with a
 * leading slash, so a trailing slash here produces `//rt/v1/...`, which some proxies normalise
 * and some route to a 404 that looks like a missing endpoint.
 *
 * There is no runtime override and no "server URL" debug screen anywhere in this app, in any build
 * type. Either one hands a phishing host a live bearer token, and the native pinning in
 * plugins/with-api-origin-pinning.ts exists precisely so that even a substituted JS bundle cannot
 * reach a different host.
 */
export const API_ORIGIN: string = rawApiOrigin;
