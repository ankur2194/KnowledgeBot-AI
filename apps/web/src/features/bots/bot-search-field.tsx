'use client';

import { TableSearchField } from '@/components/table-search-field';

/**
 * The bot list's one filter: free text over `name` and `slug` (`EloquentBotRepository::FILTERABLE`).
 *
 * ── THE IMPLEMENTATION MOVED, THE COPY STAYED ───────────────────────────────────────────────────
 * The debounced draft/applied machinery this file used to hold is now
 * `components/table-search-field.tsx`, because the sources list needed the identical control with
 * different words in it and a second copy is how the draft-clobbering bug gets fixed in one of them.
 * What remains here is what is genuinely the bot list's: the label a screen reader announces and the
 * sentence naming which columns the server actually searches.
 *
 * Nothing observable changed — same label, same placeholder, same "Clear all" — so every locator and
 * spec that named this control still names it.
 */
export function BotSearchField({
  applied,
  onApply,
  onClear,
}: {
  /** The filter as it stands in the URL — `''` when there is none. */
  readonly applied: string;
  readonly onApply: (value: string) => void;
  /** Drops every filter and returns to the first page. Rendered only when something is applied. */
  readonly onClear: () => void;
}) {
  return (
    <TableSearchField
      label="Search bots"
      // What it MATCHES. The server searches these two columns and no others, so an empty result is
      // readable rather than mysterious.
      placeholder="Name or slug"
      applied={applied}
      onApply={onApply}
      onClear={onClear}
    />
  );
}
