import { describe, expect, it } from 'vitest';

import {
  assertTableParamsConfig,
  formatDirection,
  hasActiveFilter,
  MAX_FILTER_LENGTH,
  parseSort,
  readTableParams,
  toRequestParams,
  writeTableParams,
  type TableParamsConfig,
} from '@/lib/table/params';

/**
 * The URL <-> view codec, with no React in it.
 *
 * WHAT THIS SPEC IS FOR: every value here reaches two places that must not take unbounded input — the
 * request Laravel validates, and the TanStack Query cache key. A `sort` the server rejects renders an
 * error screen for a URL somebody merely typed; an unbounded filter string is unbounded cache entries
 * in one tab. So the assertions below are mostly about what a MALFORMED url does, which is the half
 * that never gets exercised by clicking.
 */

const CONFIG: TableParamsConfig = {
  sortableColumns: ['name', 'created_at'],
  defaultSort: { id: 'name', desc: false },
  filterNames: ['q', 'status'],
  pageSizes: [25, 50, 100],
};

const read = (search: string) => readTableParams(new URLSearchParams(search), CONFIG);

describe('readTableParams degrades every malformed value instead of forwarding it', () => {
  it('returns the declared defaults for an empty query string', () => {
    const params = read('');

    expect(params.sort).toEqual({ id: 'name', desc: false });
    expect(params.pageIndex).toBe(0);
    expect(params.pageSize).toBe(25);
    expect(params.filters.size).toBe(0);
  });

  it('discards a sort column the server cannot order by', () => {
    // `Rule::in($sortable)` would 422 it. A stale bookmark from a release where `chunk_count` was
    // sortable renders the DEFAULT view rather than an error screen for a URL nobody typed by hand.
    expect(read('sort=chunk_count&dir=desc').sort).toEqual({ id: 'name', desc: false });
  });

  it('discards a multi-column sort', () => {
    // `SortingState` is an array, but two sort keys need the server to agree on the tie-break, and
    // the closed `Rule::in` set contains no comma-joined value.
    expect(read('sort=name,created_at').sort).toEqual({ id: 'name', desc: false });
  });

  it('reads the direction from `dir`, not from a prefix on the column', () => {
    expect(read('sort=created_at&dir=asc').sort).toEqual({ id: 'created_at', desc: false });
    expect(read('sort=created_at&dir=desc').sort).toEqual({ id: 'created_at', desc: true });
    // `-created_at` is a spelling this API does not have: the column is closed by `Rule::in`.
    expect(read('sort=-created_at&dir=desc').sort).toEqual({ id: 'name', desc: false });
  });

  it('keeps a valid column when the direction is unreadable', () => {
    // `ListQuery::fromValidated()` falls back to the endpoint default direction rather than dropping
    // the sort, so throwing away the half of the URL that was correct would be our own invention.
    expect(read('sort=created_at&dir=sideways').sort).toEqual({ id: 'created_at', desc: false });
  });

  it.each(['page=0', 'page=-3', 'page=abc', 'page=1e21', 'page='])(
    'falls back to the first page for %s',
    (query) => {
      expect(read(query).pageIndex).toBe(0);
    },
  );

  it('converts the one-based URL page to a zero-based pageIndex', () => {
    expect(read('page=7').pageIndex).toBe(6);
  });

  it('discards a per_page outside the offered sizes', () => {
    expect(read('per_page=100000').pageSize).toBe(25);
    expect(read('per_page=50').pageSize).toBe(50);
  });

  it('keeps only declared filters, trimmed, and truncates a very long value', () => {
    const params = read(`q=%20invoice%20&status=failed&secret=x&notafilter=1`);

    expect([...params.filters]).toEqual([
      ['q', 'invoice'],
      ['status', 'failed'],
    ]);

    const long = readTableParams(new URLSearchParams([['q', 'a'.repeat(500)]]), CONFIG);
    expect(long.filters.get('q')).toHaveLength(MAX_FILTER_LENGTH);
  });

  it('treats a whitespace-only filter as absent', () => {
    expect(hasActiveFilter(read('q=%20%20'))).toBe(false);
    expect(hasActiveFilter(read('q=invoice'))).toBe(true);
    // Neither a page nor a sort is a filter: neither can turn a populated collection into an empty
    // page, and "clear your filters" is the wrong advice for somebody who typed `?page=99`.
    expect(hasActiveFilter(read('page=9&sort=-created_at'))).toBe(false);
  });
});

describe('writeTableParams produces ONE canonical URL per view', () => {
  it('omits every default, so a pristine view has a clean address', () => {
    expect(writeTableParams(read(''), CONFIG)).toBe('');
  });

  it('writes only what differs from the defaults, and writes sort and dir together', () => {
    const params = { ...read(''), pageIndex: 2, sort: { id: 'created_at', desc: true } };
    // Both or neither: `sort` alone would leave the direction to the endpoint's default, which is not
    // necessarily the one the user is looking at.
    expect(writeTableParams(params, CONFIG)).toBe('sort=created_at&dir=desc&page=3');
  });

  it('preserves parameters it does not own and drops stale ones it does', () => {
    const base = new URLSearchParams('tab=archived&page=9&q=old&sort=created_at&dir=desc');
    const written = writeTableParams(read(''), CONFIG, base);

    // `tab` survives; every table-owned name is rewritten from the value, not merged with the URL.
    expect(written).toBe('tab=archived');
  });

  it('round-trips a fully specified view', () => {
    const original = read('sort=created_at&dir=desc&page=4&per_page=50&q=invoice&status=failed');
    expect(read(writeTableParams(original, CONFIG))).toEqual(original);
  });
});

describe('toRequestParams is the request AND the query key', () => {
  it('is explicit about every value, including the ones the URL omits', () => {
    // The URL's defaults are ours; the server's defaults are the server's, and they are not required
    // to agree. A key that omits a value the response depended on caches page 2 under page 1.
    expect(toRequestParams(read(''))).toEqual({
      page: '1',
      per_page: '25',
      sort: 'name',
      dir: 'asc',
    });
  });

  it('carries every server-visible sort, filter and page value', () => {
    expect(
      toRequestParams(read('sort=created_at&dir=desc&page=3&per_page=50&q=invoice&status=failed')),
    ).toEqual({
      page: '3',
      per_page: '50',
      sort: 'created_at',
      dir: 'desc',
      q: 'invoice',
      status: 'failed',
    });
  });

  it('never carries an organization', () => {
    // The org lives in the session cookie. Laravel derives it and would ignore a client-supplied one;
    // here it is a query-key NAMESPACE supplied by useOrgKey(), never a request parameter.
    const keys = Object.keys(toRequestParams(read('q=x')));
    expect(keys).not.toContain('organization_id');
    expect(keys).not.toContain('org');
  });
});

describe('assertTableParamsConfig refuses a config that cannot round-trip', () => {
  it.each(['page', 'per_page', 'sort', 'dir'])('rejects a filter named %s', (name) => {
    expect(() =>
      assertTableParamsConfig({ ...CONFIG, filterNames: [name] }),
    ).toThrow(/belongs to the pager/);
  });

  it('rejects a page size above the platform maximum the server clamps at', () => {
    // A clamped response is one whose applied page size is not the one this table does its range
    // arithmetic with, and the clamp is silent.
    expect(() => assertTableParamsConfig({ ...CONFIG, pageSizes: [25, 250] })).toThrow(/clamp/);
  });

  it('rejects a default sort the server cannot order by', () => {
    expect(() =>
      assertTableParamsConfig({ ...CONFIG, defaultSort: { id: 'nope', desc: false } }),
    ).toThrow(/sortableColumns/);
  });

  it('rejects an empty pageSizes list', () => {
    expect(() => assertTableParamsConfig({ ...CONFIG, pageSizes: [] })).toThrow(/pageSizes/);
  });
});

describe('parseSort and formatDirection round-trip the wire spelling', () => {
  it.each([
    ['name', 'asc', { id: 'name', desc: false }],
    ['created_at', 'desc', { id: 'created_at', desc: true }],
  ] as const)('sort=%s&dir=%s', (column, dir, expected) => {
    expect(parseSort(column, dir, CONFIG)).toEqual(expected);
    expect(formatDirection(expected)).toBe(dir);
  });

  it('returns null rather than a default, so the caller decides', () => {
    expect(parseSort(null, 'asc', CONFIG)).toBeNull();
    expect(parseSort('', null, CONFIG)).toBeNull();
  });
});
