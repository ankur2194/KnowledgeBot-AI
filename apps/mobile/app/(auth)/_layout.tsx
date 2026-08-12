import { Redirect, Stack } from 'expo-router';
import type { ReactNode } from 'react';

import { useSession } from '@/auth/session-provider';

/**
 * The unauthenticated group. Its only job beyond grouping is the mirror of the gate: an already
 * signed-in user who lands here — via a deep link, or via the back gesture after logging in —
 * goes to the app rather than being offered a second login. Two live sessions on one device is
 * two tokens, and the second one is invisible in the settings device list until it is used.
 */
export default function AuthLayout(): ReactNode {
  const { status } = useSession();

  if (status === 'loading') return null;
  if (status === 'authenticated') return <Redirect href="/" />;

  return <Stack screenOptions={{ headerShown: false }} />;
}
