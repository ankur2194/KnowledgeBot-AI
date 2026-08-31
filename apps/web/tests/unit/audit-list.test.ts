import indexAuditLogsRules from '@kb/contracts/rules/IndexAuditLogsRequest.json';
import { describe, expect, it } from 'vitest';

import {
  AUDIT_FILTER_PARAMS,
  AUDIT_LIST_CONFIG,
  AUDIT_OPERATIONS,
  AUDIT_OUTCOMES,
  AUDIT_SORTABLE_COLUMNS,
  auditDetailEntries,
  auditOperationLabel,
  auditOutcomeKind,
  canViewAudit,
} from '@/features/audit/api';
import { assertTableParamsConfig, MAX_PER_PAGE } from '@/lib/table/params';

/**
 * THE AUDIT LIST'S DERIVATIONS, PINNED AGAINST THE MANIFEST THEY COME FROM.
 *
 * The point of these is not that the numbers are right today. It is that a manifest whose SHAPE
 * changed — a renamed `in:` rule, a `sort` that stopped being an enum — fails HERE by name, rather
 * than degrading to an EMPTY SET at render time, which produces a filter with no options and a
 * header that cannot be clicked, with nothing red anywhere.
 */

const RULES = (indexAuditLogsRules as { rules: Record<string, readonly string[]> }).rules;

describe('the sortable set comes from the endpoint, not from this app', () => {
  it('is a set of one, and that is the honest shape', () => {
    // ALL FOUR INDEXES on `audit_logs` end in `created_at DESC` and there is no other candidate:
    // `id` is a ULID carrying the same ordering, but it has no index of its own (the primary key is
    // the composite a partitioned table requires), so offering it would publish a sort with nothing
    // behind it that happens to agree with the one that does.
    expect(AUDIT_SORTABLE_COLUMNS).toEqual(['created_at']);
  });

  it('is derived rather than declared — a positive control on the parse', () => {
    // If `enumFromRule` ever stopped matching, this array would be EMPTY and every assertion above
    // would still be a comparison against something. This one fails instead.
    expect(AUDIT_SORTABLE_COLUMNS.length).toBeGreaterThan(0);
    expect(RULES['sort']?.some((rule) => rule.startsWith('in:'))).toBe(true);
  });

  it('defaults to newest first, mirroring IndexAuditLogsRequest', () => {
    // The OPPOSITE of the source list's `id ASC`, and deliberately: an audit trail is read from the
    // present backwards, and page one under an ascending sort is a bookmark to the oldest login in
    // the organization's history.
    expect(AUDIT_LIST_CONFIG.defaultSort).toEqual({ id: 'created_at', desc: true });
  });

  it('declares a config the params module will accept', () => {
    // `assertTableParamsConfig` refuses a `pageSizes` entry above the platform cap, a `defaultSort`
    // outside the sortable set, and a filter named after a pager parameter. Calling it here means a
    // bad config fails a spec rather than throwing on first render.
    expect(() => assertTableParamsConfig(AUDIT_LIST_CONFIG)).not.toThrow();
    expect(Math.max(...AUDIT_LIST_CONFIG.pageSizes)).toBeLessThanOrEqual(MAX_PER_PAGE);
  });
});

describe('the operation vocabulary is the endpoint’s own closed set', () => {
  it('has every member the manifest lists, and nothing else', () => {
    // THE ENTRY THAT MATTERS MOST. A value outside this set is a VALIDATION ERROR rather than an
    // empty result, which is why the filter is a select over it. A hand-copied 45-name list would be
    // a filter that silently cannot find a class of event — an operator asking "who rotated that
    // credential" gets no option and concludes it was never recorded.
    const declared = RULES['operation']
      ?.find((rule) => rule.startsWith('in:'))
      ?.slice('in:'.length)
      .split(',')
      .map((value) => value.trim().replace(/^"(.*)"$/, '$1'));

    expect(AUDIT_OPERATIONS).toEqual(declared);
    expect(AUDIT_OPERATIONS.length).toBeGreaterThan(20);
  });

  it('includes the operations a reviewer opens this screen for', () => {
    // Chosen from three different subject families, so a partial dump fails here rather than
    // passing on a list that happens to contain the one name somebody checked.
    expect(AUDIT_OPERATIONS).toContain('auth.login.failed');
    expect(AUDIT_OPERATIONS).toContain('provider.connection.credential_rotated');
    expect(AUDIT_OPERATIONS).toContain('organization.member.role_changed');
  });

  it('offers exactly two outcomes', () => {
    expect(AUDIT_OUTCOMES).toEqual(['success', 'failure']);
  });
});

describe('the filter set is the subset this screen renders a control for', () => {
  it('offers actor, operation, outcome and the window, and not the subject pair', () => {
    expect(AUDIT_FILTER_PARAMS).toEqual(['actor_id', 'operation', 'outcome', 'from', 'until']);
  });

  it('names only parameters the endpoint actually validates', () => {
    // Laravel IGNORES an unvalidated query parameter, so a filter this app invented would render as
    // applied, the URL would say so, and the list would come back unfiltered with nothing reported
    // anywhere. This is the check that a filter name is the server's.
    for (const name of AUDIT_FILTER_PARAMS) {
      expect(Object.keys(RULES)).toContain(name);
    }
  });

  it('leaves subject_type and subject_id out, because they are a cross-field pair over an open set', () => {
    expect(AUDIT_FILTER_PARAMS).not.toContain('subject_type');
    expect(AUDIT_FILTER_PARAMS).not.toContain('subject_id');
    // ...and the manifest still validates them, which is why the omission is a decision rather than
    // an oversight: a URL carrying them is legal and simply has no control here.
    expect(Object.keys(RULES)).toContain('subject_type');
  });
});

describe('the display vocabulary', () => {
  it('reads a dotted operation as words without title-casing the server’s own', () => {
    expect(auditOperationLabel('bot.domain.status_changed')).toBe('Bot · domain · status changed');
    expect(auditOperationLabel('auth.login.succeeded')).toBe('Auth · login · succeeded');
  });

  it('renders a name this build has never heard of rather than blanking the cell', () => {
    // The console is deployed separately from the API, so a 46th operation reaches a browser running
    // last week's bundle. A table would render blank or crash an object lookup; this reads.
    expect(auditOperationLabel('future.thing.happened')).toBe('Future · thing · happened');
    expect(auditOperationLabel('nodots')).toBe('Nodots');
  });

  it('paints a failure as failed rather than degraded, and an unknown outcome neutrally', () => {
    expect(auditOutcomeKind('success')).toBe('ready');
    // A failed login or a failed rotation is a refusal that HAPPENED, not a partial success.
    expect(auditOutcomeKind('failure')).toBe('failed');
    expect(auditOutcomeKind('something-new')).toBe('pending');
  });
});

describe('the details object is read defensively and never re-filtered', () => {
  it('sorts by key so two rows of one operation line up', () => {
    expect(auditDetailEntries({ zulu: 1, alpha: 'a' })).toEqual([
      ['alpha', 'a'],
      ['zulu', '1'],
    ]);
  });

  it('renders an empty object as no entries — the records-nothing state', () => {
    // `{}` is the honest shape for something like a logout, and the column renders a WORD for it
    // rather than a blank cell, which would read as a rendering failure.
    expect(auditDetailEntries({})).toEqual([]);
  });

  it('stringifies a nested value rather than expanding it', () => {
    expect(auditDetailEntries({ changed: { from: 'a', to: 'b' } })).toEqual([
      ['changed', '{"from":"a","to":"b"}'],
    ]);
  });

  it('keeps a fingerprint key rather than filtering it out', () => {
    // A CLIENT-SIDE DENY-LIST OVER AN OPEN KEY SET IS A CONTROL THAT READS AS ONE AND IS NOT: it
    // would pass anything spelled slightly differently and would give the next reader a reason to
    // believe the filter is what keeps secrets out. The WRITER allow-lists per operation and admits
    // a bearer only as `<name>_fingerprint` — a keyed HMAC that is not derivable back to the value —
    // so the fingerprint is safe to render and is the evidence an investigator wants.
    expect(auditDetailEntries({ token_fingerprint: 'ab12' })).toEqual([
      ['token_fingerprint', 'ab12'],
    ]);
  });

  it('renders a null as the word rather than dropping the key', () => {
    // Dropping it would make "recorded as null" and "not recorded" the same rendering, on a surface
    // whose whole job is to say what was recorded.
    expect(auditDetailEntries({ previous_role: null })).toEqual([['previous_role', 'null']]);
  });
});

describe('who may read the trail', () => {
  it('is the owner and the administrator, and nobody else', () => {
    // The NARROWEST grant in the console, and narrower than `conversations.view` on purpose: an
    // audit row names a COLLEAGUE and carries their IP address and user agent.
    expect(canViewAudit('owner')).toBe(true);
    expect(canViewAudit('admin')).toBe(true);
    expect(canViewAudit('knowledge_manager')).toBe(false);
    expect(canViewAudit('analyst')).toBe(false);
    // A POSITIVE TEST OVER A LISTED SET: no role, and any role added later, defaults to holding
    // nothing.
    expect(canViewAudit(null)).toBe(false);
  });
});
