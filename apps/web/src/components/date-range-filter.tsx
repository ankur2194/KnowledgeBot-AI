'use client';

import { useId } from 'react';

import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * A half-open `[from, until)` window, written into the URL as two `date` parameters.
 *
 * ── THE CROSS-FIELD RULE IS EXPRESSED BY THE CONTROL RATHER THAN BY A RESOLVER ──────────────────
 * Every endpoint that takes this pair rules `until` as `after:from` — and `after:` rather than
 * `after_or_equal:` deliberately, because the window is half-open, so `from == until` is EMPTY and
 * the page would read "nothing happened" for a range somebody thought was one day. That is the most
 * dangerous wrong answer an audit surface can give.
 *
 * A schema cannot express it per field and there is no form here anyway — these are query
 * parameters, not a body — so the constraint is expressed as the second picker's `min`, which is the
 * first picker's value plus nothing. The browser then refuses the invalid range at the control, and
 * the server refuses it again if a URL was typed. Neither is the other's substitute.
 *
 * ── `<input type="date">` EMITS `YYYY-MM-DD`, WHICH IS WHAT LARAVEL'S `date` RULE TAKES ─────────
 * No parsing, no formatting, no timezone arithmetic in this component. The SERVER resolves the
 * window and ECHOES it back (`AnalyticsResource.window`, and `meta` on the lists), so any screen that
 * wants to label the period reads the echo rather than these inputs — which is what keeps a label
 * from describing a window the numbers are not for.
 *
 * ── AN EMPTY VALUE CLEARS THE FILTER, IT IS NOT SENT AS `?from=` ────────────────────────────────
 * Laravel's `date` rule refuses an empty string, so sending one would 422 a page nobody asked to
 * filter. `useTableParams().setFilter` deletes a filter whose trimmed value is `''`, which is why
 * `onChange` passes the raw value straight through.
 */
/**
 * The day AFTER a `YYYY-MM-DD` string, or `undefined` for anything unparseable.
 *
 * ── ONE DAY, NOT ZERO, AND THAT IS THE WHOLE POINT OF THIS FUNCTION ────────────────────────────
 * `min={from}` would permit `until === from`, which the server refuses with `after:from` — so the
 * picker would offer a value the endpoint 422s, on a control we drew, for a range the user thought
 * was one day. Advancing by a day is what makes the comment on `min` below TRUE rather than
 * aspirational.
 *
 * UTC ARITHMETIC ON PURPOSE. `new Date('2026-08-27')` parses as UTC midnight per the ECMAScript
 * date-only form, and `setUTCDate` is the only advance that does not shift under a local timezone —
 * `setDate` on a machine west of Greenwich returns the SAME calendar day, which is the bug this
 * would otherwise have in exactly one half of the world.
 */
function shiftDay(date: string, delta: number): string | undefined {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return undefined;
  const parsed = new Date(`${date}T00:00:00Z`);
  if (Number.isNaN(parsed.getTime())) return undefined;
  parsed.setUTCDate(parsed.getUTCDate() + delta);
  return parsed.toISOString().slice(0, 10);
}

export function DateRangeFilter({
  fromLabel,
  untilLabel,
  from,
  until,
  onFrom,
  onUntil,
  className,
}: {
  /** Visible, bound, and UNIQUE on the page — "Audited from", not "From". */
  readonly fromLabel: string;
  readonly untilLabel: string;
  /** As it stands in the URL — `''` when there is none. */
  readonly from: string;
  readonly until: string;
  readonly onFrom: (value: string) => void;
  readonly onUntil: (value: string) => void;
  readonly className?: string;
}) {
  const fromId = useId();
  const untilId = useId();

  return (
    <div className={className}>
      <div className="flex flex-wrap items-end gap-2">
        <div>
          <Label htmlFor={fromId} className="text-sm text-muted-foreground">
            {fromLabel}
          </Label>
          <Input
            id={fromId}
            type="date"
            value={from}
            // The same rule from the other side, so the invalid range is unreachable from either
            // picker rather than only from the second one. The server still checks.
            max={until === '' ? undefined : shiftDay(until, -1)}
            onChange={(event) => onFrom(event.target.value)}
            className="mt-1 w-40"
          />
        </div>
        <div>
          <Label htmlFor={untilId} className="text-sm text-muted-foreground">
            {untilLabel}
          </Label>
          <Input
            id={untilId}
            type="date"
            value={until}
            // THE `after:from` RULE, AS A CONTROL, AND STRICTLY. `min={from}` would permit the equal
            // pair, which is EMPTY under a half-open window and which the server refuses — a 422 on
            // a value a picker we drew offered. The one-day shift is the difference.
            min={from === '' ? undefined : shiftDay(from, 1)}
            onChange={(event) => onUntil(event.target.value)}
            className="mt-1 w-40"
          />
        </div>
      </div>
    </div>
  );
}
