'use client';

import { SearchIcon } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * THE FREE-TEXT FILTER FOR A SERVER-DRIVEN TABLE — one debounced write into the URL, which is both
 * the table's state and the request.
 *
 * ── THIS FILE IS A MOVE. IT WAS `features/bots/bot-search-field.tsx` ────────────────────────────
 * Every list endpoint in this API takes the same single `filter` parameter (`ListQuery::rules()`),
 * so the second list screen needed the identical control with two different words in it. The repo's
 * rule for that is the one `lib/api/browser.ts` records itself following when `browserFetchData`
 * acquired a second caller: MOVE it, do not copy it. The subtle part below — the draft/applied sync
 * that does not clobber newer keystrokes — is exactly the kind of thing that gets fixed in one copy
 * and not the other.
 *
 * `features/bots/bot-search-field.tsx` is now a two-line wrapper supplying the bot list's copy, so
 * every existing locator, label and spec for it is unchanged.
 *
 * ── THE LABEL IS VISIBLE, NOT A PLACEHOLDER ─────────────────────────────────────────────────────
 * `kb-ui-accessibility`: every form control has a visible `<label>` bound to it, because a placeholder
 * disappears exactly when the user needs it. The placeholder says what the search MATCHES, which is
 * the thing a label has no room for and the thing that makes an empty result readable — so it is a
 * required prop rather than an optional flourish, and it must name the SERVER's filterable columns.
 *
 * The field's `--card-inset` fill sits on the table's header bar, which is `--card` — so the fill is
 * what identifies the control's boundary and the near-invisible `--input` ring is enough. A search
 * field dropped into a header that is ALREADY `--card-inset` needs `--border-strong` instead; this one
 * is not that case (`components/ui/input.tsx`).
 *
 * ── WHY THE URL IS WRITTEN ON A TIMER AND THE INPUT IS NOT ──────────────────────────────────────
 * The URL is the table's state AND the request, so every keystroke would otherwise be a navigation, a
 * query key and a round trip. The input holds a DRAFT and the applied value lands
 * `FILTER_DEBOUNCE_MS` after the last keystroke; `useTableParams` writes it with `replace`, so Back
 * does not walk the user backwards through their own typing.
 *
 * ── THE ONE SUBTLE BUG THIS SHAPE EXISTS TO AVOID ───────────────────────────────────────────────
 * A naive `useEffect(() => setDraft(applied), [applied])` clobbers the user's newer text: they type
 * `abcd`, the debounce for `abc` lands, the URL comes back as `abc`, and the effect overwrites the
 * `d` they already pressed. So the sync only fires when `applied` changed EXTERNALLY — a Clear
 * filters button, a Back navigation, a pasted URL — which is exactly "the incoming value is not the
 * one we last sent". `lastSent` is what distinguishes the two, and it is a ref because it must not
 * cause a render of its own.
 */

/** Long enough that ordinary typing produces one request, short enough to feel immediate. */
export const FILTER_DEBOUNCE_MS = 300;

export function TableSearchField({
  label,
  placeholder,
  applied,
  onApply,
  onClear,
}: {
  /**
   * The visible label, and it must be UNIQUE ON THE PAGE — "Search sources", not "Search". Role- and
   * label-name matching is a case-insensitive SUBSTRING in both Playwright and vitest-browser, so a
   * generic name resolves every search field on any screen that grows a second one.
   */
  readonly label: string;
  /** What the search MATCHES, named after the server's own filterable columns. */
  readonly placeholder: string;
  /** The filter as it stands in the URL — `''` when there is none. */
  readonly applied: string;
  readonly onApply: (value: string) => void;
  /**
   * Drops every filter and returns to the first page. Rendered only when something is applied (P9).
   *
   * ITS BUTTON IS NAMED "Clear all" AND NOT "Clear filters", which is P9's own wording and is also a
   * collision the screen would otherwise have: when a filter matches nothing, `FilteredEmptyState`
   * renders its OWN "Clear filters" button inside the same card. Two buttons with one accessible name
   * is two identical announcements for a screen-reader user and a strict-mode failure for every
   * locator that names either.
   */
  readonly onClear: () => void;
}) {
  const fieldId = useId();
  const [draft, setDraft] = useState(applied);

  // The callback identity changes on every navigation (it closes over the current params), and the
  // timer must not restart because of that. A ref keeps the effect's dependencies to the two values
  // that genuinely decide when to fire.
  const onApplyRef = useRef(onApply);
  useEffect(() => {
    onApplyRef.current = onApply;
  });

  const lastSent = useRef(applied);
  useEffect(() => {
    if (applied === lastSent.current) return;
    lastSent.current = applied;
    setDraft(applied);
  }, [applied]);

  useEffect(() => {
    if (draft === applied) return undefined;
    const timer = setTimeout(() => {
      lastSent.current = draft;
      onApplyRef.current(draft);
    }, FILTER_DEBOUNCE_MS);
    return () => {
      clearTimeout(timer);
    };
  }, [draft, applied]);

  return (
    <div className="flex flex-wrap items-center gap-2">
      <Label htmlFor={fieldId} className="text-sm text-muted-foreground">
        {label}
      </Label>
      <div className="relative">
        {/* Decorative: the label already names the control, so a second announcement of "search"
            would be noise rather than a channel. */}
        <SearchIcon
          aria-hidden
          className="pointer-events-none absolute top-1/2 left-2 size-4 -translate-y-1/2 text-muted-foreground"
        />
        <Input
          id={fieldId}
          // `search` rather than `text`: it gets the platform's clear affordance and the right
          // on-screen keyboard, and it is what the control actually is.
          type="search"
          value={draft}
          onChange={(event) => setDraft(event.target.value)}
          placeholder={placeholder}
          className="w-56 pl-8"
        />
      </div>
      {applied === '' ? null : (
        <Button variant="outline" size="sm" onClick={onClear}>
          Clear all
        </Button>
      )}
    </div>
  );
}
