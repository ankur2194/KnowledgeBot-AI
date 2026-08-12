import { promises as fs } from 'node:fs';
import path from 'node:path';

import {
  AndroidConfig,
  withAndroidManifest,
  withDangerousMod,
  withInfoPlist,
  type ConfigPlugin,
} from 'expo/config-plugins';

/**
 * Pins the API origin into the NATIVE layer, on both platforms.
 *
 * WHY NATIVE. `EXPO_PUBLIC_API_ORIGIN` is inlined into the JS bundle, so an OTA update carries its
 * own copy of the base URL — a substituted bundle could point every request, bearer token
 * included, at a host of the attacker's choosing. iOS App Transport Security and the Android
 * network security config live in the BINARY. A JS-only update cannot touch either, so even a
 * fully compromised update channel cannot ship the token to a new host: the connection is refused
 * by the platform before any of our code runs. That is the only mechanism in this app that an OTA
 * update genuinely cannot weaken, which is why the API origin is pinned here and not in JS.
 *
 * `expo/config-plugins` rather than a separate `@expo/config-plugins` dependency: the SDK
 * re-exports the plugin API on that subpath precisely so a plugin cannot drift to a config-plugins
 * major that disagrees with the installed Expo.
 *
 * Both mods are idempotent — `expo prebuild` runs them on a tree that may already contain the
 * previous run's output, and a plugin that appends rather than replaces produces a manifest that
 * grows a duplicate entry per build.
 */

export interface ApiOriginPinningOptions {
  /**
   * Bare http(s) origins the app is permitted to reach, e.g. `https://api.knowledgebot.ai`.
   * Validated here rather than trusted: a malformed value would otherwise be written into the
   * native config as a domain key that matches nothing, which fails OPEN on Android (no `<domain>`
   * entry means the base config applies) and is invisible in review.
   */
  readonly origins: readonly string[];
  /**
   * Development profile only. Permits cleartext to the LOOPBACK hosts hard-coded below — never to
   * whatever origin happens to be configured, because "allow cleartext to the configured host" is
   * one env var away from allowing it in production.
   */
  readonly allowCleartextLoopback?: boolean;
}

/** Metro/adb loopback hosts. `10.0.2.2` is the Android emulator's alias for the host machine. */
const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '10.0.2.2'] as const;

interface ParsedOrigin {
  readonly host: string;
  readonly secure: boolean;
}

function parseOrigins(origins: readonly string[]): ParsedOrigin[] {
  if (origins.length === 0) {
    throw new Error('with-api-origin-pinning: `origins` must contain at least one origin.');
  }
  return origins.map((origin) => {
    const match = /^(https?):\/\/([A-Za-z0-9.-]+)(?::\d+)?$/.exec(origin);
    if (match === null) {
      throw new Error(
        `with-api-origin-pinning: "${origin}" is not a bare http(s) origin ` +
          `(scheme + host + optional port, no path, no trailing slash).`,
      );
    }
    const scheme = match[1] as string;
    const host = match[2] as string;
    return { host, secure: scheme === 'https' };
  });
}

/* -------------------------------------------------------------------------------------------- */
/* iOS — App Transport Security                                                                   */
/* -------------------------------------------------------------------------------------------- */

const withIosAts: ConfigPlugin<ApiOriginPinningOptions> = (config, options) =>
  withInfoPlist(config, (mod) => {
    const parsed = parseOrigins(options.origins);
    const exceptionDomains: Record<string, Record<string, boolean | string>> = {};

    for (const { host, secure } of parsed) {
      if (!secure && options.allowCleartextLoopback !== true) {
        throw new Error(
          `with-api-origin-pinning: refusing to pin cleartext origin "${host}" outside the ` +
            `development profile.`,
        );
      }
      exceptionDomains[host] = {
        // The point of the whole plugin. `false` means: this domain gets no cleartext exemption,
        // so an http:// URL to it is refused by the OS. Setting it to `true` for "just staging"
        // is the change that quietly re-opens the token to a downgrade.
        NSExceptionAllowsInsecureHTTPLoads: false,
        // Subdomains are NOT included: the pin is the exact host we talk to. Including them would
        // extend the exemption to any host a DNS takeover could produce under the parent.
        NSIncludesSubdomains: false,
        NSExceptionMinimumTLSVersion: 'TLSv1.2',
        NSExceptionRequiresForwardSecrecy: true,
      };
    }

    if (options.allowCleartextLoopback === true) {
      for (const host of LOOPBACK_HOSTS) {
        exceptionDomains[host] = {
          // Development only, loopback only. `NSAllowsLocalNetworking` would be the broader knob
          // and is deliberately not used: it covers the whole local network, which on a shared
          // office Wi-Fi is not meaningfully narrower than allowing cleartext outright.
          NSExceptionAllowsInsecureHTTPLoads: true,
          NSIncludesSubdomains: true,
        };
      }
    }

    mod.modResults.NSAppTransportSecurity = {
      // Assigned wholesale rather than merged, so a removed origin actually disappears instead of
      // surviving in the previous prebuild's plist.
      NSAllowsArbitraryLoads: false,
      NSAllowsArbitraryLoadsInWebContent: false,
      NSExceptionDomains: exceptionDomains,
    };

    return mod;
  });

/* -------------------------------------------------------------------------------------------- */
/* Android — network_security_config.xml                                                          */
/* -------------------------------------------------------------------------------------------- */

const NETWORK_SECURITY_CONFIG_RESOURCE = '@xml/network_security_config';

function buildNetworkSecurityConfig(
  parsed: readonly ParsedOrigin[],
  allowCleartextLoopback: boolean,
): string {
  const pinned = parsed
    .map(({ host }) => `    <domain includeSubdomains="false">${host}</domain>`)
    .join('\n');

  const loopback = allowCleartextLoopback
    ? `
  <!-- DEVELOPMENT PROFILE ONLY. Loopback hosts, hard-coded — never the configured origin. -->
  <domain-config cleartextTrafficPermitted="true">
${LOOPBACK_HOSTS.map((host) => `    <domain includeSubdomains="false">${host}</domain>`).join('\n')}
  </domain-config>`
    : '';

  return `<?xml version="1.0" encoding="utf-8"?>
<!--
  GENERATED by apps/mobile/plugins/with-api-origin-pinning.ts on \`expo prebuild\`.
  Do not edit: android/ is gitignored and this file is rewritten on every prebuild.

  base-config denies cleartext for everything, so a mistake fails CLOSED. The per-domain block
  below re-states it for the pinned API host, which is what a reviewer looks for. Android's
  network security config is part of the APK, so an OTA JS update cannot relax it.
-->
<network-security-config>
  <base-config cleartextTrafficPermitted="false">
    <trust-anchors>
      <!-- System CAs only. \`user\` is deliberately absent: including it lets anyone who can
           install a certificate on the device (an MDM, a "debug proxy", malware) read every
           request, bearer token included. -->
      <certificates src="system" />
    </trust-anchors>
  </base-config>

  <domain-config cleartextTrafficPermitted="false">
${pinned}
    <trust-anchors>
      <certificates src="system" />
    </trust-anchors>
  </domain-config>${loopback}
</network-security-config>
`;
}

const withAndroidNetworkSecurityConfig: ConfigPlugin<ApiOriginPinningOptions> = (
  config,
  options,
) => {
  // 1. Point the manifest at the resource, and turn off the global cleartext permission.
  const withManifest = withAndroidManifest(config, (mod) => {
    const application = AndroidConfig.Manifest.getMainApplicationOrThrow(mod.modResults);
    application.$['android:networkSecurityConfig'] = NETWORK_SECURITY_CONFIG_RESOURCE;
    // Belt and braces: the manifest attribute is what applies on API < 24, where
    // network_security_config.xml is ignored entirely.
    application.$['android:usesCleartextTraffic'] =
      options.allowCleartextLoopback === true ? 'true' : 'false';
    return mod;
  });

  // 2. Write the resource itself. There is no typed mod for res/xml, so this is a dangerous mod —
  //    the one place in this plugin that touches the filesystem directly.
  return withDangerousMod(withManifest, [
    'android',
    async (mod) => {
      const parsed = parseOrigins(options.origins);
      for (const { secure } of parsed) {
        if (!secure && options.allowCleartextLoopback !== true) {
          throw new Error(
            'with-api-origin-pinning: refusing to pin a cleartext origin outside the development profile.',
          );
        }
      }

      const xmlDir = path.join(mod.modRequest.platformProjectRoot, 'app/src/main/res/xml');
      await fs.mkdir(xmlDir, { recursive: true });
      await fs.writeFile(
        path.join(xmlDir, 'network_security_config.xml'),
        buildNetworkSecurityConfig(parsed, options.allowCleartextLoopback === true),
        'utf8',
      );
      return mod;
    },
  ]);
};

const withApiOriginPinning: ConfigPlugin<ApiOriginPinningOptions> = (config, options) =>
  withAndroidNetworkSecurityConfig(withIosAts(config, options), options);

export default withApiOriginPinning;
