'use client';

import { QueryClientProvider } from '@tanstack/react-query';
import { ThemeProvider } from 'next-themes';
import { createContext, useCallback, useContext, useState, type ReactNode } from 'react';

import { makeQueryClient } from '@/lib/query/client';

/**
 * Replaces the QueryClient instance. This is the ENFORCEMENT half of an organization switch;
 * the `['org', orgId, …]` key prefix is the invariant that still holds when a future refactor, a
 * nested provider, or a test harness skips it.
 *
 * `queryClient.clear()` is NOT sufficient: it empties the caches but keeps the same client and the
 * same mounted observers, so active observers immediately refetch and any request that started
 * before the switch can still resolve into it. A new client has no observers and nothing renders
 * the old one.
 */
const ResetQueryClientContext = createContext<() => void>(() => {
  throw new Error('useResetQueryClient used outside <Providers>');
});

export function useResetQueryClient(): () => void {
  return useContext(ResetQueryClientContext);
}

export function Providers({ children }: { children: ReactNode }) {
  // Created inside the React tree, never at module scope: a module-level `new QueryClient()` is
  // one cache shared by every user of the Node process. TanStack's own SSR guide — "besides being
  // bad for performance, this also leaks any sensitive data." In development the process turns
  // over often enough to hide it; in production one process serves everyone.
  const [queryClient, setQueryClient] = useState(() => makeQueryClient());

  const reset = useCallback(() => {
    setQueryClient(makeQueryClient());
  }, []);

  // No persister, ever: no localStorage, sessionStorage or IndexedDB persistence. Tenant content
  // would outlive logout, survive an org switch, and sit unencrypted on a shared workstation.
  // No devtools in a production bundle either.
  return (
    <QueryClientProvider client={queryClient}>
      <ResetQueryClientContext.Provider value={reset}>
        {/* attribute="class" pairs with @custom-variant dark (&:where(.dark, .dark *)) in
            globals.css, and <html> carries suppressHydrationWarning because next-themes writes
            the class before hydration. */}
        <ThemeProvider attribute="class" defaultTheme="system" enableSystem>
          {children}
        </ThemeProvider>
      </ResetQueryClientContext.Provider>
    </QueryClientProvider>
  );
}
