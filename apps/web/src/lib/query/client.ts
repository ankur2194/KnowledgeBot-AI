import { KbError } from '@kb/contracts';
import { QueryClient } from '@tanstack/react-query';

/**
 * The ONLY place a retry count appears in apps/web. The enforcement is the ESLint selector
 * `Property[key.name='retry']` under `no-restricted-syntax` in ../../../eslint.config.mjs, applied
 * to `src/**` with this file as the sole `ignores` entry. It reads the AST, so it catches
 * `retry` in any object literal and ignores every mention in a comment or a string. (It runs only
 * under `pnpm lint`. Next 16 removed `next lint` AND `next build` no longer lints, so a pipeline
 * that relies on the build to lint passes while checking nothing — `pnpm web:lint` has to be its
 * own CI step. Today `.github/workflows/` holds `gates.yml` only, which runs source greps and no
 * ESLint, so until `ci.yml` lands this rule fires in editors and on `pnpm web:lint` locally.)
 *
 * `grep -rn 'retry:' apps/web/src` is NOT that check and returns 5 hits across 2 files, three of
 * them prose. The closest text approximation, which drops comment lines and returns only the two
 * real ones below (verified):
 *
 *   grep -rn 'retry:' apps/web/src | grep -vE ':[0-9]+:[[:space:]]*(\*|//)'
 *
 * Measured worst case was 27x: SDK 3 x adapter 3 x client 3. Provider SDKs are now max_retries=0
 * and the FastAPI adapter owns 3 attempts, so TanStack Query's DEFAULT policy (retry: 3 -> 4
 * attempts) still turns one click into 12 provider calls. One client retry makes it 6; a mutation
 * at zero makes it 3, which is exactly the adapter's budget.
 */

/**
 * Narrower than the envelope's `retryable` flag ON PURPOSE. `retryable: true` means *some* tier may
 * retry, and for `provider_temporary` that tier is the FastAPI adapter, which already spent its
 * ladder. Obeying the flag re-multiplies it. Every class absent here is never retried by the
 * browser.
 *
 * NARROWER, NEVER INSTEAD OF. This set is one half of an AND with `error.retryable`, and the
 * `internal_dependency` row below is why (ADR-029, finding O1). That class has two sub-cases and
 * the axis separating them is not on the wire: a genuine dependency brownout renders
 * 503/`retryable: true`, an unmapped exception in our own code renders 500/`retryable: false`.
 * Membership here on its own would retry a defect on the full ladder against a request that cannot
 * succeed on any attempt — a retry storm the user pays for and nobody is paged about, because the
 * envelope already said not to.
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
          // breaks: a second copy of the class makes this false for every error at once.
          if (!(error instanceof KbError)) return false;
          if (error.error_class === null) return false;
          // The envelope's own verdict comes FIRST and is never re-derived from the class name:
          // `internal_dependency` renders `retryable: false` for the self-origin sub-case, and the
          // class name cannot tell you which sub-case you are holding (ADR-029).
          if (!error.retryable) return false;
          // `rate_limit` is OUR limiter, and kb-error-taxonomy assigns its retry to the client.
          return CLIENT_RETRYABLE.has(error.error_class) || error.error_class === 'rate_limit';
        },
        // Retry-After is a FLOOR, not a hint, and `retry_after` is seconds off the response
        // header. Every field on this envelope is snake_case; reach for a camelCase spelling
        // and it reads undefined, the delay silently drops to 1 s, and we retry inside the
        // window we were told to wait — which keeps consuming the provider's per-minute
        // request slot and never leaves the rejection window.
        retryDelay: (_attempt, error) =>
          error instanceof KbError && error.retry_after !== null ? error.retry_after * 1000 : 1_000,
        staleTime: 30_000,
        // A focus storm across 12 open admin tabs is a self-inflicted 429.
        refetchOnWindowFocus: false,
      },
      // Already the default; restated because a POST is not replayable. A mutation that genuinely
      // must survive a retry carries an Idempotency-Key; one without a key must never be retried
      // by anything, including a user double-click — so disable the button on isPending.
      mutations: { retry: false },
    },
  });

/**
 * Every query key begins ['org', orgId, ...]. `orgId` is a CACHE NAMESPACE, never a request
 * parameter — Laravel derives the real organization from the session cookie and would ignore a
 * client-supplied one. Without it, ['sources', 1] is one cache entry serving two organizations,
 * and the symptom is a correctly rendered list, not an error.
 *
 * No Next cache key can carry the org; a TanStack Query key is the one key in this app we design
 * ourselves, so it does.
 */
export const orgKey = (orgId: string, ...rest: readonly unknown[]) =>
  ['org', orgId, ...rest] as const;
