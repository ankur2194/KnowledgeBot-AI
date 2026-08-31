'use client';

import { useId } from 'react';

import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

/**
 * A one-of-many filter over a CLOSED, SERVER-PUBLISHED vocabulary, writing into the URL.
 *
 * ── WHY THIS EXISTS RATHER THAN A TEXT INPUT ────────────────────────────────────────────────────
 * Every filter this serves is `Rule::in(...)` server-side, which means a value outside the set is a
 * VALIDATION ERROR and not an empty result. A text input would let a user type `?outcome=succeeded`
 * (the operation vocabulary's word) against a rule that says `success`, and the answer would be a
 * 422 rendered as an error screen for a URL somebody merely typed. A picker built from the endpoint's
 * own dumped `in:` rule cannot produce one.
 *
 * ── THE "ANY" OPTION IS A SENTINEL, BECAUSE RADIX REFUSES AN EMPTY VALUE ────────────────────────
 * `SelectItem` throws on `value=""` — the empty string is how Radix spells "nothing is selected", so
 * an item carrying it would be indistinguishable from the placeholder. So "Any" is `__any` on the
 * control and `''` on the wire, converted here in ONE place. A caller that did the conversion itself
 * would eventually send `?outcome=__any`, which the server would 422 — the exact failure this
 * component exists to make unreachable.
 *
 * ── THE URL IS THE STATE ────────────────────────────────────────────────────────────────────────
 * `onChange` goes straight to `useTableParams().setFilter`, which resets the page in the SAME
 * navigation — under `manualPagination` the table never resets `pageIndex` for you, and doing it in a
 * second `setState` is what requests page 8 of a 2-page result and renders "no matches" for a
 * collection with hundreds of rows.
 *
 * ── THE LABEL IS VISIBLE AND MUST BE UNIQUE ON THE PAGE ─────────────────────────────────────────
 * `kb-ui-accessibility`: every form control has a visible `<label>` bound to it. Role- and label-name
 * matching is a case-insensitive SUBSTRING in both Playwright and vitest-browser, so a generic name
 * resolves every select on any screen that grows a second one.
 */
export const ANY_VALUE = '__any';

export interface FilterOption {
  readonly value: string;
  readonly label: string;
}

export function FilterSelect({
  label,
  anyLabel,
  options,
  applied,
  onChange,
  className,
}: {
  /** Visible, bound, and UNIQUE on the page — "Outcome", not "Status". */
  readonly label: string;
  /** What "no filter" reads as. "Any outcome" rather than "All", because the row it describes is a
   *  filter that is not applied rather than a set that is complete. */
  readonly anyLabel: string;
  readonly options: readonly FilterOption[];
  /** The value as it stands in the URL — `''` when there is none. */
  readonly applied: string;
  /** `''` clears the filter. Never `null`: `setFilter` accepts both and one spelling is enough. */
  readonly onChange: (value: string) => void;
  readonly className?: string;
}) {
  const fieldId = useId();

  return (
    <div className={className}>
      <Label htmlFor={fieldId} className="text-sm text-muted-foreground">
        {label}
      </Label>
      <Select
        value={applied === '' ? ANY_VALUE : applied}
        onValueChange={(next) => onChange(next === ANY_VALUE ? '' : next)}
      >
        <SelectTrigger id={fieldId} size="sm" className="mt-1 w-full">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value={ANY_VALUE}>{anyLabel}</SelectItem>
          {options.map((option) => (
            <SelectItem key={option.value} value={option.value}>
              {option.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  );
}
