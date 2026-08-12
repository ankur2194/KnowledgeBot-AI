import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import type { ReactNode } from 'react';

import { SessionProvider, useSession } from '@/auth/session-provider';

/**
 * The root layout, and the only place providers are mounted.
 *
 * EVERY ROUTE IN THIS DIRECTORY IS AUTOMATICALLY DEEP-LINKABLE. expo-router derives the link map
 * from the file tree, so a screen added under app/ next month is reachable from a URL the day it
 * lands, whether or not anyone thought about links. That is why the gate lives in a LAYOUT
 * (app/(app)/_layout.tsx) rather than in each screen: a per-screen check is a check somebody
 * forgets on the twentieth screen, and the twentieth screen is still linkable.
 *
 * External links arrive only over verified Universal Links / App Links on our own domain
 * (associatedDomains + apple-app-site-association on iOS, intentFilters with autoVerify:true +
 * assetlinks.json on Android; both configured in app.config.ts). The custom `knowledgebot://`
 * scheme in app.json is for in-app navigation and development ONLY — on Android any app may
 * register the same scheme and on iOS the winner is undefined, so an external link over a scheme
 * can be intercepted. An auth callback over a custom scheme would additionally be a token in a
 * URL, which is barred outright.
 *
 * THIS COMPONENT RENDERS NOTHING until the SecureStore read resolves. See <Gate> below.
 */
export default function RootLayout(): ReactNode {
  return (
    <SessionProvider>
      <StatusBar style="auto" />
      <Gate>
        <Stack screenOptions={{ headerShown: false }}>
          <Stack.Screen name="(auth)" />
          <Stack.Screen name="(app)" />
          <Stack.Screen name="+not-found" options={{ headerShown: true, title: 'Not found' }} />
        </Stack>
      </Gate>
    </SessionProvider>
  );
}

/**
 * Renders nothing — not a spinner, not a cached screen, NOTHING — until two things have finished,
 * in this order:
 *
 *   1. the fresh-install check, which purges SecureStore when the AsyncStorage launch flag is
 *      missing (the iOS Keychain survives uninstall, so a reinstall would otherwise start signed
 *      in with a token the user believed they destroyed);
 *   2. the SecureStore read, with the 90 s expiry grace window applied.
 *
 * A cold-start deep link is the case this exists for. The link names a screen, the router mounts
 * it, and if anything paints before step 2 resolves, an unauthenticated process has rendered a
 * screen from cache. "It only flashed for 200 ms" is still a disclosure, and on a cold start with
 * a slow keychain read it is longer than that.
 *
 * A splash screen is the right thing to show here once expo-splash-screen is wired
 * (`preventAutoHideAsync()` at module scope, `hideAsync()` when status leaves 'loading'). Until
 * then, null — which is correct, just ugly.
 */
function Gate({ children }: { children: ReactNode }): ReactNode {
  const { status } = useSession();
  if (status === 'loading') return null;
  return children;
}
