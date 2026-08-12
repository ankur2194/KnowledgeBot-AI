import { KbError } from '@kb/contracts';
import { describe, expect, it } from '@jest/globals';

import { makeQueryClient, orgKey } from '@/lib/query-client';

/**
 * The retry policy, pinned.
 *
 * This runs on Node, which is fine here and only here: the predicate is pure logic over a KbError
 * and has no Hermes dimension at all — unlike tests/stream-answer.test.ts, whose green run proves
 * the parser and never that streaming works on a device.
 *
 * The policy is read back off a real QueryClient rather than exported separately, because the
 * defect this file exists to catch lives in the wiring: a predicate that is correct in isolation
 * and never installed retries on TanStack Query's default ladder (retry: 3 -> 4 attempts) with
 * nothing to show for it.
 */

const retryPredicate = () => {
  const retry = makeQueryClient().getDefaultOptions().queries?.retry;
  if (typeof retry !== 'function') throw new Error('retry must be the predicate function');
  return retry as (failureCount: number, error: Error) => boolean;
};

const retryDelayFn = () => {
  const retryDelay = makeQueryClient().getDefaultOptions().queries?.retryDelay;
  if (typeof retryDelay !== 'function') throw new Error('retryDelay must be a function');
  return retryDelay as (attempt: number, error: Error) => number;
};

describe('the single retry policy', () => {
  it('retries a rate_limit exactly once — one retry, two attempts, hard stop', () => {
    const retry = retryPredicate();
    const error = new KbError('rate_limit', true, 30, '01J');
    expect(retry(0, error)).toBe(true);
    expect(retry(1, error)).toBe(false);
  });

  it('does not retry an unknown class — no envelope means permanent', () => {
    const retry = retryPredicate();
    expect(retry(0, new KbError(null, true))).toBe(false);
    // A forked KbError, a test double, or a bare TypeError from a dead radio all land here.
    expect(retry(0, new Error('Network request failed'))).toBe(false);
  });

  it('does not retry provider_temporary — the FastAPI adapter already spent its ladder', () => {
    // `retryable: true` and still no: the allow-list is the NARROWING half of the AND.
    expect(retryPredicate()(0, new KbError('provider_temporary', true))).toBe(false);
  });

  /**
   * ADR-029 / finding O1, and the reason this file exists.
   *
   * `internal_dependency` is ONE class with TWO renderings, and the axis that separates them —
   * `origin` — is deliberately not a wire field. Both cases below carry the SAME `error_class`, so
   * the predicate has only `retryable` to go on. A predicate that decides from the class name
   * passes the first of these and fails the second, and that failure is a full backoff ladder run
   * against our own unhandled exception: a request that cannot succeed on any attempt, retried over
   * a phone's metered radio, billed to the tenant, and paged to nobody because the envelope already
   * said not to.
   *
   * Both planes render this today — services/core-api/bootstrap/app.php in PHP, and
   * services/ai-service/app/core/errors.py (`Origin`, `status_for()`, `retryable_for()`) with
   * app/main.py::_handle_unexpected in Python — so a `retryable: false` internal_dependency is a
   * response this client can actually receive, not a hypothetical.
   */
  describe('internal_dependency, which has two sub-cases and one class name', () => {
    it('retries the downstream sub-case — 503, retryable: true, a dependency is briefly sick', () => {
      expect(retryPredicate()(0, new KbError('internal_dependency', true))).toBe(true);
    });

    it('does NOT retry the self sub-case — 500, retryable: false, our own defect', () => {
      expect(retryPredicate()(0, new KbError('internal_dependency', false))).toBe(false);
    });
  });

  it('never retries past the envelope: retryable:false is final for every class', () => {
    const retry = retryPredicate();
    // The server's flag is the AUTHORITY and the VETO. The allow-list may only narrow it; an OR
    // that can override a `false` is the same bug wearing a different class name.
    expect(retry(0, new KbError('retrieval', false))).toBe(false);
    expect(retry(0, new KbError('storage', false))).toBe(false);
    expect(retry(0, new KbError('rate_limit', false))).toBe(false);
  });

  it('honours Retry-After as a floor, in seconds', () => {
    expect(retryDelayFn()(0, new KbError('rate_limit', true, 30))).toBe(30_000);
    expect(retryDelayFn()(0, new KbError('retrieval', true, null))).toBe(1_000);
  });

  it('never retries a mutation — a POST without an idempotency key is not replayable', () => {
    expect(makeQueryClient().getDefaultOptions().mutations?.retry).toBe(false);
  });

  /**
   * MOBILE-SPECIFIC. A phone loses the network for real, and "you are offline" is a different
   * sentence with a different remedy than "something went wrong on our side". `offlineFirst` pauses
   * and resumes those rather than burning the one retry above on a dead radio.
   */
  it('pauses rather than fails when the network is genuinely gone', () => {
    const options = makeQueryClient().getDefaultOptions();
    expect(options.queries?.networkMode).toBe('offlineFirst');
    expect(options.mutations?.networkMode).toBe('offlineFirst');
  });
});

describe('query keys', () => {
  it('is namespaced by organization, because one device serves several people', () => {
    expect(orgKey('01ORGA', 'conversations', { page: 1 })).toEqual([
      'org',
      '01ORGA',
      'conversations',
      { page: 1 },
    ]);
    expect(orgKey('01ORGA', 'conversations')).not.toEqual(orgKey('01ORGB', 'conversations'));
  });
});
