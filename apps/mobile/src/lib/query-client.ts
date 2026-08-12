import { KbError } from '@kb/contracts';
import { QueryClient } from '@tanstack/react-query';

/**
 * The ONLY place a retry count appears in apps/mobile — `rg -n "retry:" apps/mobile/src` must
 * return this file and nothing else, and ESLint enforces it for every other file under src/ and
 * app/.
 *
 * This is deliberately the same policy as apps/web/src/lib/query/client.ts. The server-state
 * conventions are identical across the two clients; a mobile-specific retry ladder would be a
 * second answer to a question the taxonomy already settled, and the two would drift.
 *
 * Measured worst case was 27x: SDK 3 x adapter 3 x client 3. Provider SDKs are now max_retries=0
 * and the FastAPI adapter owns 3 attempts, so TanStack Query's DEFAULT policy (retry: 3 -> 4
 * attempts) still turns one tap into 12 provider calls. One client retry makes it 6; a mutation at
 * zero makes it 3, which is exactly the adapter's budget.
 */

/**
 * Narrower than the envelope's `retryable` flag ON PURPOSE. `retryable: true` means *some* tier may
 * retry, and for `provider_temporary` that tier is the FastAPI adapter, which already spent its
 * ladder. Obeying the flag re-multiplies it. Every class absent here is never retried by the app.
 *
 * NARROWER, NEVER INSTEAD OF. This set is one half of an AND with `error.retryable`, and the
 * `internal_dependency` row below is why (ADR-029, finding O1). That class has two sub-cases and
 * the axis separating them is not on the wire: a genuine dependency brownout renders
 * 503/`retryable: true`, an unmapped exception in our own code renders 500/`retryable: false`.
 * Membership here on its own would retry a defect on the full ladder against a request that cannot
 * succeed on any attempt — and on a phone that ladder runs over a metered radio, for an answer that
 * was never going to arrive.
 */
const CLIENT_RETRYABLE: ReadonlySet<string> = new Set([
  // Only its `downstream` sub-case ever reaches the `&& error.retryable` below.
  'internal_dependency',
  'retrieval',
  'storage',
]);

export const makeQueryClient = (): QueryClient =>
  new QueryClient({
    defaultOptions: {
      queries: {
        retry: (failureCount, error) => {
          if (failureCount >= 1) return false; // one retry, two attempts, hard stop
          // No envelope parsed => unknown => permanent. `instanceof` is also what a forked KbError
          // breaks: a second copy of the class makes this false for every error at once, and every
          // retryable class becomes a dead end with no error anywhere. That is why this app
          // imports KbError from @kb/contracts and declares none of its own.
          if (!(error instanceof KbError)) return false;
          if (error.error_class === null) return false;
          // The envelope's own verdict comes FIRST and is never re-derived from the class name:
          // `internal_dependency` renders `retryable: false` for the self-origin sub-case, and the
          // class name cannot tell you which sub-case you are holding (ADR-029). The server's flag
          // is the authority and the veto; the allow-list below only narrows it further.
          if (!error.retryable) return false;
          // `rate_limit` is OUR limiter, and kb-error-taxonomy assigns its retry to the client.
          return CLIENT_RETRYABLE.has(error.error_class) || error.error_class === 'rate_limit';
        },
        // Retry-After is a FLOOR, not a hint, and `retry_after` is seconds off the response
        // header. Spelled in camelCase it reads undefined, the delay silently drops to 1 s, and
        // we retry inside the window we were told to wait.
        retryDelay: (_attempt, error) =>
          error instanceof KbError && error.retry_after !== null ? error.retry_after * 1000 : 1_000,
        staleTime: 30_000,

        /**
         * MOBILE-SPECIFIC, and the one genuine divergence from apps/web. A phone loses the network
         * for real — a lift, a tunnel, airplane mode — and that is not a server error. TanStack
         * Query pauses a query whose network is down rather than failing it, and resumes on
         * reconnect; `offlineFirst` keeps the first attempt from being suppressed when the online
         * manager has not yet observed the interface. What this must NOT do is surface as a
         * `internal_dependency` toast: "you are offline" is a different sentence with a different
         * remedy, and reporting it as a server failure trains users to retry into a dead radio.
         */
        networkMode: 'offlineFirst',

        // There is no window to focus on a phone, and a resume storm across every mounted screen
        // after unlocking the device is a self-inflicted 429.
        refetchOnWindowFocus: false,
      },
      // Already the default; restated because a POST is not replayable. A mutation that genuinely
      // must survive a retry carries an idempotency key; one without a key must never be retried by
      // anything, including a double-tap — so disable the control on isPending.
      mutations: { retry: false, networkMode: 'offlineFirst' },
    },
  });

/**
 * Every query key begins ['org', orgId, ...].
 *
 * ONE DEVICE SERVES SEVERAL PEOPLE, which is the shape this app has and the browser does not: a
 * shared tablet, a handover, a re-login as a colleague. `orgId` is a CACHE NAMESPACE, never a
 * request parameter — Laravel derives the real organization from the bearer token and would ignore
 * a client-supplied one. Without it, ['conversations'] is one cache entry that hands the previous
 * user's transcripts to the next one, and the symptom is a correctly rendered list, not an error.
 *
 * The key prefix is the invariant. The enforcement is that logout REPLACES the QueryClient
 * instance (see src/auth/session-provider.tsx) rather than calling `clear()`, which keeps the same
 * client and the same mounted observers — those observers immediately refetch, and a request that
 * started before the logout can still resolve into the "cleared" cache.
 */
export const orgKey = (orgId: string, ...rest: readonly unknown[]) =>
  ['org', orgId, ...rest] as const;
