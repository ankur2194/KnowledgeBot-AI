import showAnalyticsRules from '@kb/contracts/rules/ShowAnalyticsRequest.json';
import { describe, expect, it } from 'vitest';

import {
  ANALYTICS_PARAM_NAMES,
  EM_DASH,
  canViewAnalytics,
  formatBytes,
  formatCount,
  formatDuration,
  formatRate,
  formatWindow,
} from '@/features/analytics/api';

/**
 * THE DASHBOARD'S FORMATTERS, AND THE ONE PROPERTY THEY ALL SHARE: `null` IS NOT ZERO.
 *
 * Every nullable field on `AnalyticsResource` means NOT MEASURED — a latency percentile over a
 * window with no successful streaming call, a rate over a window with no calls, a cost for a model
 * with no recorded price. Rendering any of them as `0` reports health nobody measured, and `0 ms` in
 * particular reads as "instant" rather than as "we did not measure".
 *
 * That is the whole of what these tests are for. The digit grouping is `Intl`'s and is not asserted
 * against a locale, because the suite's locale is not the reader's.
 */

describe('every formatter has a not-measured arm and it is an em dash', () => {
  it('answers null with an em dash rather than a zero', () => {
    expect(formatDuration(null)).toBe(EM_DASH);
    expect(formatRate(null)).toBe(EM_DASH);
    expect(formatBytes(null)).toBe(EM_DASH);
  });

  it('and answers a real zero with a real zero', () => {
    // THE OTHER HALF, and the one that makes the first meaningful: a measured zero is a fact and has
    // to render as one. A formatter that answered both with an em dash would be as wrong as one that
    // answered both with `0`.
    expect(formatDuration(0)).toBe('0 ms');
    expect(formatRate(0)).toBe('0.0%');
    expect(formatBytes(0)).toBe('0 B');
  });
});

describe('durations', () => {
  it('stays in milliseconds below a second and promotes above it', () => {
    // A p95 of 12400 is a number nobody reads at a glance.
    expect(formatDuration(940)).toBe('940 ms');
    expect(formatDuration(1000)).toBe('1.0 s');
    expect(formatDuration(12_400)).toBe('12.4 s');
  });
});

describe('rates', () => {
  it('does NOT clamp a fallback rate above 100%', () => {
    // A turn that fell back TWICE writes two attempts, so `fallback_rate` may legitimately exceed
    // 1.0 — and the server deliberately does not clamp it, because clamping hides exactly the
    // configuration worth looking at. A client that clamped would undo that decision one layer
    // later, and the operator would never see the number that says a model ladder is misconfigured.
    expect(formatRate(1.4)).toBe('140.0%');
  });

  it('renders an ordinary rate to one decimal', () => {
    expect(formatRate(0.0234)).toBe('2.3%');
    expect(formatRate(1)).toBe('100.0%');
  });
});

describe('bytes', () => {
  it('uses binary units with binary names', () => {
    // `storage_bytes_used` is the same ledger figure the QUOTA gate refuses against, and the quota
    // screen renders the ceiling through this same function — so the two screens cannot disagree
    // about what "1 GiB" means.
    expect(formatBytes(512)).toBe('512 B');
    expect(formatBytes(1024)).toBe('1.0 KiB');
    expect(formatBytes(1024 * 1024 * 1024)).toBe('1.0 GiB');
  });

  it('drops the decimal once the figure is large enough not to need one', () => {
    expect(formatBytes(1024 * 1024 * 25)).toBe('25 MiB');
  });

  it('stops at the largest unit it knows rather than running off the end of the table', () => {
    // Guarding the index rather than the value: `BYTE_UNITS` is a closed list, and an organization
    // with an exabyte of storage should read as a very large number of PiB rather than `undefined`.
    expect(formatBytes(1024 ** 6)).toContain('PiB');
  });
});

describe('the window is labelled from the SERVER’s echo', () => {
  it('formats a resolved range', () => {
    const label = formatWindow('2026-08-01T00:00:00+00:00', '2026-08-27T00:00:00+00:00');
    expect(label).toContain('–');
    expect(label.length).toBeGreaterThan(5);
  });

  it('renders an unparseable pair verbatim rather than throwing inside a header', () => {
    // A defensive arm rather than a case: the wire is ISO-8601 with an offset. What it buys is that
    // a malformed value degrades to text instead of taking the page down.
    expect(formatWindow('not-a-date', 'also-not')).toBe('not-a-date – also-not');
  });
});

describe('the query parameters are the endpoint’s own', () => {
  it('are read out of the dumped rules rather than typed here', () => {
    const declared = Object.keys(
      (showAnalyticsRules as { rules: Record<string, unknown> }).rules,
    ).sort();
    expect([...ANALYTICS_PARAM_NAMES].sort()).toEqual(declared);
    // Laravel IGNORES an unvalidated query parameter, so a filter this screen invented would render
    // as applied, the URL would say so, and the numbers would come back unfiltered with nothing
    // reported anywhere.
    expect(ANALYTICS_PARAM_NAMES).toEqual(expect.arrayContaining(['bot_id', 'from', 'until']));
  });

  it('has no pager parameters — this is an aggregate, not a list', () => {
    expect(ANALYTICS_PARAM_NAMES).not.toContain('page');
    expect(ANALYTICS_PARAM_NAMES).not.toContain('sort');
  });
});

describe('who may read the dashboard', () => {
  it('is the owner, the admin and the analyst — and not the knowledge manager', () => {
    expect(canViewAnalytics('owner')).toBe(true);
    expect(canViewAnalytics('admin')).toBe(true);
    // §6.5 is reporting-only and this IS the reporting: the feedback split, the
    // insufficient-evidence count and the latency percentiles.
    expect(canViewAnalytics('analyst')).toBe(true);
    // The knowledge manager's six permissions are all `sources.*` plus `providers.view` and
    // `bots.view`; none of them is a report.
    expect(canViewAnalytics('knowledge_manager')).toBe(false);
    expect(canViewAnalytics(null)).toBe(false);
  });
});

describe('counts', () => {
  it('groups digits through Intl rather than by hand', () => {
    // Asserted only for LENGTH and digits, because the separator is the reader's locale and this
    // suite's is not theirs. What matters is that it goes through `Intl` at all — a hand-rolled
    // grouping is the thing that renders `1,234` to a reader whose locale writes `1.234`.
    expect(formatCount(1234567)).toMatch(/^1\D?234\D?567$/);
    expect(formatCount(0)).toBe('0');
  });
});
