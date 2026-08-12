import type { ConfigContext, ExpoConfig } from 'expo/config';

/**
 * The DYNAMIC half of the app config. `app.json` is the static half.
 *
 * Both files exist because Expo documents exactly this pattern: when `app.config.ts` exports a
 * function, Expo reads `app.json` first and passes it in as `config`. Static identity — name,
 * slug, scheme, icons, bundle identifier, package name — belongs in `app.json` because it is
 * inspectable by tools that never execute JavaScript (EAS, store submission, `expo config`
 * consumers, a reviewer with `jq`). Anything that must be *computed* — a fingerprint runtime
 * version, the verified-link domains, the config-plugin list, ATS pinning derived from the build
 * profile — can only live here.
 *
 * Splitting it the other way (everything dynamic) is the common mistake: it makes the bundle id
 * and the package name invisible to `assetlinks.json` review and to anyone diffing a release.
 *
 * NOTHING SECRET GOES HERE. This file is evaluated at build time and everything it returns is
 * embedded in the app manifest, which ships inside the binary and is readable by anyone who
 * downloads it. No provider credential, no internal HMAC key, no FastAPI hostname
 * (kb-architecture-map NN1: this app talks to Laravel and only to Laravel).
 */

/**
 * The one public value this app publishes. `EXPO_PUBLIC_*` is inlined into the JS bundle by
 * babel-preset-expo, so the prefix is not a namespace — it is a publication decision, and every
 * key under it is readable by anyone who downloads the app.
 *
 * The allow-list below is the enforcement. It runs here, in Node at config time, because in the
 * app `process.env` is not an object at all — it is a set of inlined string literals, so
 * `Object.keys(process.env)` in the bundle is empty and can prove nothing.
 *
 * One name is deliberately NOT mentioned anywhere in apps/mobile, including in comments: the
 * documented opt-out flag that restores Hermes' XHR-backed fetch and makes `Response.body` null.
 * CI greps this directory for it, and a comment explaining the grep would fail the grep. The
 * allow-list catches it without naming it: any unexpected `EXPO_PUBLIC_*` key fails the config.
 */
const ALLOWED_PUBLIC_KEYS = ['EXPO_PUBLIC_API_ORIGIN'] as const;

function assertPublicEnv(): string {
  const allowed = new Set<string>(ALLOWED_PUBLIC_KEYS);
  const unexpected = Object.keys(process.env).filter(
    (key) => key.startsWith('EXPO_PUBLIC_') && !allowed.has(key),
  );
  if (unexpected.length > 0) {
    throw new Error(
      `Unexpected EXPO_PUBLIC_* variable(s): ${unexpected.join(', ')}. ` +
        `apps/mobile publishes exactly one: ${ALLOWED_PUBLIC_KEYS.join(', ')}. ` +
        `Everything under that prefix is inlined into the bundle and is readable by anyone ` +
        `who downloads the app.`,
    );
  }

  const origin = process.env.EXPO_PUBLIC_API_ORIGIN;
  if (origin === undefined || origin === '') {
    throw new Error('EXPO_PUBLIC_API_ORIGIN is required. See .env.example.');
  }
  if (!/^https?:\/\/[^/]+$/.test(origin)) {
    throw new Error(
      `EXPO_PUBLIC_API_ORIGIN must be a bare http(s) origin with no path and no trailing slash. ` +
        `Got: ${origin}`,
    );
  }
  return origin;
}

/**
 * `EAS_BUILD_PROFILE` is set by EAS Build from eas.json. Locally it is undefined, which we treat
 * as `development` — the only profile permitted to talk to a cleartext localhost.
 */
const profile = process.env.EAS_BUILD_PROFILE ?? 'development';
const isDevelopmentProfile = profile === 'development';

/** The domain that owns the verified Universal Links / App Links. Not the API origin. */
const LINK_DOMAIN = 'app.knowledgebot.ai';

export default ({ config }: ConfigContext): ExpoConfig => {
  const apiOrigin = assertPublicEnv();

  return {
    ...config,
    // ConfigContext types `name`/`slug` as optional; app.json supplies both and these fallbacks
    // exist only to satisfy ExpoConfig's required fields.
    name: config.name ?? 'KnowledgeBot',
    slug: config.slug ?? 'knowledgebot',

    /**
     * `fingerprint`, never `appVersion` and never a hand-maintained string. It hashes everything
     * that affects the NATIVE runtime, so adding a native module without a rebuild becomes a
     * build-time error instead of a launch-time crash loop that only reproduces on real devices
     * (simulators frequently survive it) and reads as "the update didn't apply", because
     * expo-updates' error recovery quietly rolls back.
     */
    runtimeVersion: { policy: 'fingerprint' },

    /**
     * EAS Update is OFF until code signing exists.
     *
     * An OTA channel into a process holding a bearer token is a remote-code-execution path. With
     * no signing, the trust anchor is our EAS account rather than store review, so one compromised
     * publish ships JS that reads the token out of SecureStore and posts it anywhere. Turning this
     * on requires, in order: `npx expo-updates codesigning:generate`, the certificate committed
     * into the binary, the private key held OUTSIDE this repo and outside ordinary CI, and
     * `eas update:rollback` rehearsed — a bad JS update on this client can lock every user out of
     * the login screen itself.
     *
     *   updates: {
     *     url: 'https://u.expo.dev/<project-id>',
     *     fallbackToCacheTimeout: 0,
     *     codeSigningCertificate: './certs/certificate.pem',
     *     codeSigningMetadata: { keyid: 'main', alg: 'rsa-v1_5-sha256' },
     *   },
     *
     * Independently of signing: any diff touching src/auth/** or src/api/client.ts ships as a
     * BUILD, not an update.
     */
    updates: {
      enabled: false,
      checkAutomatically: 'ON_LOAD',
      fallbackToCacheTimeout: 0,
    },

    ios: {
      ...config.ios,
      /**
       * Verified Universal Links. A custom scheme (`knowledgebot://`, declared in app.json) is for
       * IN-APP navigation and dev only: on Android any app may register the same scheme and on iOS
       * the winner is undefined, so an external link over a scheme can be intercepted by another
       * app. Requires apple-app-site-association served from the domain below.
       */
      associatedDomains: [`applinks:${LINK_DOMAIN}`],
    },

    android: {
      ...config.android,
      /**
       * Verified App Links. `autoVerify: true` is the whole point — without it this is an ordinary
       * intent filter that a disambiguation dialog lets any app win. Requires assetlinks.json on
       * the domain carrying the PRODUCTION package name and the RELEASE signing SHA-256, not the
       * debug one.
       */
      intentFilters: [
        {
          action: 'VIEW',
          autoVerify: true,
          data: [{ scheme: 'https', host: LINK_DOMAIN, pathPrefix: '/' }],
          category: ['BROWSABLE', 'DEFAULT'],
        },
      ],
    },

    plugins: [
      'expo-router',
      'expo-secure-store',
      'expo-sqlite',
      [
        './plugins/with-api-origin-pinning',
        {
          origins: [apiOrigin],
          // Cleartext is permitted ONLY on the development profile, and only for the loopback
          // hosts the plugin hard-codes. There is no runtime toggle and no "server URL" debug
          // screen anywhere in this app: either one hands a phishing host a live bearer token.
          allowCleartextLoopback: isDevelopmentProfile,
        },
      ],
    ],

    extra: {
      ...config.extra,
      // Read by nothing at runtime — the app reads EXPO_PUBLIC_API_ORIGIN directly, inlined. This
      // is here so `expo config --json` shows a reviewer which origin a binary was pinned to.
      buildProfile: profile,
      pinnedApiOrigin: apiOrigin,
    },
  };
};
