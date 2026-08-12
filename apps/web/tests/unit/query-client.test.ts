import { KbError } from '@kb/contracts';
import { describe, expect, it } from 'vitest';

import { makeQueryClient, orgKey } from '@/lib/query/client';

const retryPredicate = () => {
  const options = makeQueryClient().getDefaultOptions().queries;
  const retry = options?.retry;
  if (typeof retry !== 'function') throw new Error('retry must be the predicate function');
  return retry as (failureCount: number, error: Error) => boolean;
};

const retryDelayFn = () => {
  const options = makeQueryClient().getDefaultOptions().queries;
  const retryDelay = options?.retryDelay;
  if (typeof retryDelay !== 'function') throw new Error('retryDelay must be a function');
  return retryDelay as (attempt: number, error: Error) => number;
};

describe('the single retry policy', () => {
  it('retries a rate_limit exactly once', () => {
    const retry = retryPredicate();
    const error = new KbError('rate_limit', true, 30, '01J');
    expect(retry(0, error)).toBe(true);
    expect(retry(1, error)).toBe(false);
  });

  it('does not retry an unknown class — no envelope means permanent', () => {
    const retry = retryPredicate();
    expect(retry(0, new KbError(null, true))).toBe(false);
    expect(retry(0, new Error('network'))).toBe(false);
  });

  it('does not retry provider_temporary — the FastAPI adapter already spent its ladder', () => {
    expect(retryPredicate()(0, new KbError('provider_temporary', true))).toBe(false);
  });

  /**
   * ADR-029 / finding O1. `internal_dependency` is one class with two renderings, and the axis that
   * separates them is deliberately NOT on the wire: the predicate has only `retryable` to go on.
   * Both cases below carry the SAME `error_class`, which is the entire point — a predicate that
   * decides from the class name passes the first and fails the second, and the failure is a full
   * backoff ladder against a defect that cannot succeed on any attempt.
   */
  describe('internal_dependency, which has two sub-cases and one class name', () => {
    it('retries the downstream sub-case — 503, retryable: true', () => {
      expect(retryPredicate()(0, new KbError('internal_dependency', true))).toBe(true);
    });

    it('does NOT retry the self sub-case — 500, retryable: false, our own defect', () => {
      expect(retryPredicate()(0, new KbError('internal_dependency', false))).toBe(false);
    });
  });

  it('never retries past the envelope: retryable:false is final for every class', () => {
    const retry = retryPredicate();
    // The allow-list may only NARROW `retryable`, never stand in for it.
    expect(retry(0, new KbError('retrieval', false))).toBe(false);
    expect(retry(0, new KbError('storage', false))).toBe(false);
    expect(retry(0, new KbError('rate_limit', false))).toBe(false);
  });

  it('honours Retry-After as a floor, in seconds', () => {
    expect(retryDelayFn()(0, new KbError('rate_limit', true, 30))).toBe(30_000);
    expect(retryDelayFn()(0, new KbError('retrieval', true, null))).toBe(1_000);
  });

  it('never retries a mutation', () => {
    expect(makeQueryClient().getDefaultOptions().mutations?.retry).toBe(false);
  });
});

describe('query keys', () => {
  it('is namespaced by organization, because the org is not in the URL', () => {
    expect(orgKey('01ORGA', 'sources', { page: 1 })).toEqual([
      'org',
      '01ORGA',
      'sources',
      { page: 1 },
    ]);
    expect(orgKey('01ORGA', 'sources')).not.toEqual(orgKey('01ORGB', 'sources'));
  });
});
