import { Redirect, Stack, usePathname } from 'expo-router';
import { useEffect } from 'react';
import type { ReactNode } from 'react';

import { useSession } from '@/auth/session-provider';

/**
 * THE GATE. Every authenticated screen in this app is a descendant of this layout, and every one
 * of them is automatically deep-linkable — so this is the single place that decides whether a link
 * gets to render anything.
 *
 * Why a layout and not a per-screen check: expo-router derives the deep-link map from the file
 * tree, so the twentieth screen someone adds is linkable the moment the file exists. A convention
 * that each screen checks its own auth is a convention that fails on the screen nobody remembered.
 *
 * The three cases, in order:
 *
 *  1. status 'loading' — render NOTHING. The root layout already blocks on this, and the
 *     redundancy is deliberate: this component must be safe to mount on its own, because a future
 *     refactor that moves the provider is otherwise a silent regression. Rendering a cached screen
 *     for one frame before the token read resolves is the leak.
 *  2. not authenticated — remember the intended href IN MEMORY and redirect to /login. Not through
 *     a `?next=` query parameter: a redirect target that survives in a URL is a redirect target an
 *     attacker can supply through a deep link, and this app's links come from outside it.
 *  3. authenticated — render.
 *
 * What this gate does NOT do is treat the link's own parameters as trust. A verified Universal
 * Link proves the DOMAIN, not the bearer's entitlement to the resource named in it. Each screen
 * re-resolves its target against Laravel with this device's own token and renders the public
 * not-found when the answer says so — never a 403, which on a foreign identifier confirms the row
 * exists and turns the screen into an enumeration oracle.
 */
export default function AppLayout(): ReactNode {
  const { status, rememberIntendedHref } = useSession();
  const pathname = usePathname();

  // Recorded in an effect rather than during render: a ref write during render is a side effect
  // React is allowed to run twice.
  useEffect(() => {
    if (status === 'anonymous') {
      rememberIntendedHref(pathname);
    }
  }, [status, pathname, rememberIntendedHref]);

  if (status === 'loading') return null;
  if (status !== 'authenticated') return <Redirect href="/login" />;

  return <Stack screenOptions={{ headerShown: true }} />;
}
