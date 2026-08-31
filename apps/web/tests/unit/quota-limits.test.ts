import type { QuotaMetricUsage, QuotaResource } from '@kb/contracts';
import {
  QUOTA_METRICS,
  quotaFieldFor,
  quotaLimitsFormDefaults,
  quotaLimitsSchema,
  type QuotaLimitsOut,
} from '@kb/contracts/forms';
import { describe, expect, it } from 'vitest';

import {
  QUOTA_KNOWN_PATHS,
  canManageQuotas,
  quotaFill,
  quotaMetricDisplay,
  quotaRaises,
} from '@/features/quotas/api';

/**
 * THE QUOTA SCREEN'S TWO NON-OBVIOUS PIECES: the direction rule it discloses, and the null/zero
 * distinction the form has to keep apart.
 *
 * Neither is enforcement. `QuotaLimitService` decides, under a lock, against the values the
 * repository actually read; what is tested here is that the CLIENT explains the same rule the server
 * applies, because the server's own explanation structurally cannot reach a browser — the refusal is
 * `error_class: 'authorization'` and `bootstrap/app.php` rewrites every authorization message to one
 * constant so the deny split cannot become an enumeration oracle.
 */

const row = (
  metric: QuotaMetricUsage['metric'],
  limit: number | null,
  used = 0,
): QuotaMetricUsage => ({
  metric,
  used,
  limit,
  remaining: limit === null ? null : Math.max(0, limit - used),
  exceeded: limit !== null && used >= limit,
  source: 'database',
});

const resource = (rows: readonly QuotaMetricUsage[]): QuotaResource => ({ metrics: rows });

const proposal = (values: Partial<QuotaLimitsOut>): QuotaLimitsOut => ({
  storage_bytes_quota: null,
  bots_quota: null,
  users_quota: null,
  monthly_tokens_quota: null,
  ...values,
});

describe('the four metric names and their request fields are one vocabulary', () => {
  it('derives the field from the metric rather than declaring a second list', () => {
    // The request's four keys ARE the four metric names plus a suffix. Spelling them out twice is
    // how a fifth metric lands with three of its four spellings updated.
    expect(QUOTA_METRICS.map(quotaFieldFor)).toEqual([
      'storage_bytes_quota',
      'bots_quota',
      'users_quota',
      'monthly_tokens_quota',
    ]);
  });

  it('renders exactly the paths the FormRequest validates', () => {
    // DERIVED from the dumped manifest and not hand-typed — this is the case `knownPathsFromRules`
    // exists for, unlike the upload form where the manifest describes eleven paths against a picker
    // that renders two.
    expect([...QUOTA_KNOWN_PATHS].sort()).toEqual([...QUOTA_METRICS.map(quotaFieldFor)].sort());
  });

  it('gives every metric a label and a formatter', () => {
    for (const metric of QUOTA_METRICS) {
      const display = quotaMetricDisplay(metric);
      expect(display.label.length).toBeGreaterThan(0);
      expect(display.description.length).toBeGreaterThan(0);
    }
    // ...and a metric this build has never heard of renders as itself rather than crashing an object
    // lookup or blanking a card.
    expect(quotaMetricDisplay('seats').label).toBe('seats');
  });
});

describe('the schema keeps "omitted" and "null" apart, which is the whole reason it is a PUT', () => {
  it('refuses an omitted key', () => {
    // `present` on all four. An omitted key and a null key would otherwise mean the same thing while
    // meaning OPPOSITE things — "leave it alone" and "remove this ceiling entirely" — so a client
    // that dropped a key would silently make an organization unlimited.
    const missing = { bots_quota: 5, users_quota: 5, monthly_tokens_quota: 5 };
    expect(quotaLimitsSchema.safeParse(missing).success).toBe(false);
  });

  it('accepts a null as unlimited and a zero as nothing-is-allowed', () => {
    const unlimited = quotaLimitsSchema.safeParse(proposal({}));
    expect(unlimited.success).toBe(true);
    expect(unlimited.success && unlimited.data.bots_quota).toBeNull();

    const stopped = quotaLimitsSchema.safeParse(proposal({ bots_quota: 0 }));
    expect(stopped.success).toBe(true);
    // NOT COLLAPSED INTO NULL. `0` is a real, legal ceiling and it is the opposite of unlimited.
    expect(stopped.success && stopped.data.bots_quota).toBe(0);
  });

  it('reads an emptied number input as unlimited rather than as zero', () => {
    // `Number("")` is `0`, so a cleared field under a bare `z.coerce.number()` would submit a HARD
    // STOP where the user meant "no limit". Laravel's `ConvertEmptyStringsToNull` does the same
    // mapping on the other side, so the two agree about the empty case by construction.
    const cleared = quotaLimitsSchema.safeParse(proposal({ bots_quota: '' as unknown as number }));
    expect(cleared.success).toBe(true);
    expect(cleared.success && cleared.data.bots_quota).toBeNull();
  });

  it('refuses a negative and a fraction', () => {
    expect(quotaLimitsSchema.safeParse(proposal({ bots_quota: -1 })).success).toBe(false);
    expect(quotaLimitsSchema.safeParse(proposal({ bots_quota: 1.5 })).success).toBe(false);
  });
});

describe('the defaults builder is the only path from server data into form state', () => {
  it('picks the four ceilings and nothing else', () => {
    const defaults = quotaLimitsFormDefaults(
      resource([row('storage_bytes', 1024, 512), row('bots', null), row('users', 0)]),
    );

    expect(defaults).toEqual({
      storage_bytes_quota: 1024,
      bots_quota: null,
      users_quota: 0,
      // A metric MISSING from the response resolves to null — unlimited — which is the server's own
      // reading of an absent ceiling. The list is documented as TOTAL, so this branch should be
      // unreachable; it exists because a builder that threw on a short list would take the whole
      // screen down over a field the form can express perfectly well.
      monthly_tokens_quota: null,
    });

    // NEVER `reset(quotaResource)`: `used`, `remaining`, `exceeded` and `source` are on every row and
    // RHF keeps every key it is handed, so a `reset()` would post a `metrics` array to a PUT that
    // validates four scalars.
    expect(Object.keys(defaults)).toHaveLength(4);
  });
});

describe('the raise rule, disclosed client-side because the server’s sentence cannot reach a browser', () => {
  const current = resource([
    row('storage_bytes', 1000),
    row('bots', null),
    row('users', 10),
    row('monthly_tokens', 500),
  ]);

  it('treats a higher number as a raise and a lower one as free', () => {
    expect(quotaRaises(current, proposal({ users_quota: 11, monthly_tokens_quota: 500, storage_bytes_quota: 1000 }))).toEqual(
      ['users'],
    );
    expect(
      quotaRaises(
        current,
        proposal({ users_quota: 9, monthly_tokens_quota: 500, storage_bytes_quota: 1000 }),
      ),
    ).toEqual([]);
  });

  it('treats removing a ceiling as the LARGEST raise there is', () => {
    // `null` means UNLIMITED, so `1000 → null` is a bigger raise than `1000 → 10_000`, and the
    // server's `raisesAny()` treats it as one. A client that only compared numbers would let the
    // biggest raise available through with no warning at all.
    expect(
      quotaRaises(
        current,
        proposal({ storage_bytes_quota: null, users_quota: 10, monthly_tokens_quota: 500 }),
      ),
    ).toEqual(['storage_bytes']);
  });

  it('treats unlimited → unlimited as unchanged, and unlimited → a number as a LOWERING', () => {
    // The case that reads backwards, and the one a naive `next > stored` would get wrong by
    // comparing a number against null. Nothing is higher than unlimited.
    const unchanged = quotaRaises(
      current,
      proposal({ bots_quota: null, storage_bytes_quota: 1000, users_quota: 10, monthly_tokens_quota: 500 }),
    );
    expect(unchanged).toEqual([]);

    const lowered = quotaRaises(
      current,
      proposal({ bots_quota: 3, storage_bytes_quota: 1000, users_quota: 10, monthly_tokens_quota: 500 }),
    );
    expect(lowered).toEqual([]);
  });

  it('reports every raised metric rather than the first one', () => {
    // The screen names them all in one sentence; reporting the first would make an owner fix one and
    // be refused again on the next save.
    expect(
      quotaRaises(
        current,
        proposal({ storage_bytes_quota: 2000, users_quota: 99, monthly_tokens_quota: 500 }),
      ),
    ).toEqual(['storage_bytes', 'users']);
  });

  it('returns metrics in QUOTA_METRICS order, so the sentence reads the same every time', () => {
    const raised = quotaRaises(
      current,
      proposal({ monthly_tokens_quota: 900, storage_bytes_quota: 2000, users_quota: 10 }),
    );
    expect(raised).toEqual(['storage_bytes', 'monthly_tokens']);
  });
});

describe('the meter', () => {
  it('has no value for an unlimited metric, rather than reading zero', () => {
    // A bar at zero over an unlimited metric says "you have used none of your allowance", which is
    // false in the way that matters — there is no allowance to be a fraction of.
    expect(quotaFill(row('bots', null, 4))).toBeNull();
  });

  it('clamps the BAR at 100 and leaves the numbers alone', () => {
    // An over-quota organization is genuinely past its ceiling and the exact figure is `used` beside
    // `limit`; a progress bar that overflows its track is a rendering bug rather than information.
    expect(quotaFill(row('bots', 10, 25))).toBe(100);
    expect(quotaFill(row('bots', 10, 5))).toBe(50);
  });

  it('reads a zero ceiling as full rather than dividing by zero', () => {
    // `0` means NOTHING IS ALLOWED, so any usage at all is over — and `used / 0` is `Infinity` or
    // `NaN`, either of which reaches a `style` as a broken width.
    expect(quotaFill(row('bots', 0, 0))).toBe(100);
    expect(quotaFill(row('bots', 0, 3))).toBe(100);
  });
});

describe('who may change a ceiling', () => {
  it('is the owner alone among the four organization roles', () => {
    // `quotas.manage` is the ONLY permission in the catalogue an ADMINISTRATOR does not hold, beside
    // `members.manage_owner`: §6.3 says an administrator "may not own BILLING or destructive
    // organization-level actions", and a ceiling is the billing boundary wearing an operational hat.
    expect(canManageQuotas('owner')).toBe(true);
    expect(canManageQuotas('admin')).toBe(false);
    expect(canManageQuotas('knowledge_manager')).toBe(false);
    expect(canManageQuotas('analyst')).toBe(false);
    expect(canManageQuotas(null)).toBe(false);
  });
});
