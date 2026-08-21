import type { SourceDetailResource, SourceType } from '@kb/contracts';

/**
 * EVERYTHING `/sources/{sourceId}` HAS TO DECIDE BEFORE IT CAN RENDER — the count tiles it shows,
 * what "live" means for this particular source, and the words for an advisory warning code.
 *
 * ── A `.ts` WITH NO JSX, DELIBERATELY ──────────────────────────────────────────────────────────
 * The `unit` Vitest project runs in `node` with no react plugin and `jsx: "preserve"`, so any module
 * a `tests/unit/**` spec reaches transitively must be JSX-free or Vite fails the whole FILE with
 * "content contains invalid JS syntax" — naming this module rather than the spec
 * (`features/auth/session-context.ts` records the same constraint). Everything here is a pure
 * function of the resource, which is also what makes the arithmetic assertable without a browser.
 *
 * ── THE ONE THING TO CARRY OUT OF THE CONTRACT ─────────────────────────────────────────────────
 * EVERY COUNT ON `SourceDetailResource` IS OVER THE LIVE VERSIONS ONLY — the ones an item's
 * active-version pointer names, and nothing else. A source mid-ingestion reports ZEROES even though
 * rows for the unpublished version already exist. Nothing below renders any of them as progress.
 */

/**
 * A grouped integer in the VIEWER's locale.
 *
 * THE THIRD `Intl.NumberFormat()` CALL SITE IN THIS APP AND STILL NOT A SHARED HELPER, which is a
 * decision rather than an oversight. The other two are not reusable here: `server-data-table.tsx`
 * builds one privately for a pager's row range, and `features/models/api.ts`' `formatTokenCount`
 * maps `0` to "Not recorded" — correct for a max-output column and a false statement about a
 * content count, where zero is a real and important number (see `LiveState` below). What the three
 * share is one expression, not one meaning. Recorded here so a FOURTH is a move to `@/lib/format`
 * rather than a fourth paste (the `formatTimestamp` note in `features/providers/api.ts` sets the
 * precedent, and `asText` moved at five).
 *
 * There is no hydration hazard: every value formatted here arrived from a browser fetch, so this
 * subtree does not exist in the RSC payload and no server-rendered string can disagree with it.
 */
export const formatCount = (value: number): string => new Intl.NumberFormat().format(value);

/**
 * WHAT ONE ITEM OF THIS SOURCE IS, in the user's words.
 *
 * An "item" is the unit of independent versioning — one uploaded file, one crawled page, one paste —
 * and the word for it is different per type. "Item" itself is internal vocabulary and appears in no
 * rendered string.
 *
 * A `Map` and not a plain object for the lookup, because the key comes off the wire and indexing an
 * object by a server-supplied value is the `security/detect-object-injection` sink. The fallback is
 * the neutral pair rather than a crash: this console is deployed separately from the API, so a
 * fourth `type` reaches a browser running last week's bundle.
 */
const ITEM_NOUNS = new Map<string, readonly [string, string]>([
  ['file', ['file', 'files']],
  ['url', ['crawled page', 'crawled pages']],
  ['text', ['pasted text', 'pasted texts']],
] satisfies ReadonlyArray<readonly [SourceType, readonly [string, string]]>);

export const itemNoun = (type: string, count: number): string => {
  const pair = ITEM_NOUNS.get(type) ?? (['part', 'parts'] as const);
  return count === 1 ? pair[0] : pair[1];
};

/** One number the "What is in this source" card renders, with the word that makes it readable. */
export interface ContentCount {
  /** A stable key, for React and for a locator. Never an array index. */
  readonly id: string;
  /** `--text-caption` uppercase. Sentence case, and the plural agrees with the value. */
  readonly label: string;
  readonly value: number;
  /** One line under the number saying what it counts. Omitted where the label is self-evident. */
  readonly note?: string;
}

/**
 * THE COUNTS THIS PARTICULAR SOURCE SHOULD SHOW — not every count for every source.
 *
 * ── ZERO IS AMBIGUOUS FOR THE THREE STRUCTURAL COUNTS AND THE CONTRACT SAYS SO ─────────────────
 * "A format with no pages (a deck, a spreadsheet, a crawled page) reports zero exactly as a source
 * with no live content does, so render the count that fits the `type` rather than every count for
 * every source." A `type` is too coarse to pick between them — a `file` source is a batch upload and
 * can hold a PDF and a spreadsheet at once — so the rule here is POSITIVE: a structural count is
 * shown when it is non-zero, and a source that has none of the three simply shows none. That reads
 * as "this format has no pages", which is true, instead of "0 pages", which is a claim.
 *
 * The three that are ALWAYS shown are the ones whose zero is a fact rather than an ambiguity:
 * how many items there are, how much document was extracted, and how much of it is retrievable.
 */
export const contentCounts = (detail: SourceDetailResource): readonly ContentCount[] => {
  const counts: ContentCount[] = [
    {
      id: 'items',
      label: capitalize(itemNoun(detail.type, detail.item_count)),
      value: detail.item_count,
      note:
        detail.item_count === detail.active_version_count
          ? 'All of them are live.'
          : `${formatCount(detail.active_version_count)} live.`,
    },
  ];

  if (detail.page_count > 0) {
    counts.push({ id: 'pages', label: detail.page_count === 1 ? 'Page' : 'Pages', value: detail.page_count });
  }
  if (detail.slide_count > 0) {
    counts.push({ id: 'slides', label: detail.slide_count === 1 ? 'Slide' : 'Slides', value: detail.slide_count });
  }
  if (detail.sheet_count > 0) {
    counts.push({ id: 'sheets', label: detail.sheet_count === 1 ? 'Sheet' : 'Sheets', value: detail.sheet_count });
  }

  counts.push({
    id: 'elements',
    // "Element" is ours. What an operator has is headings, paragraphs, list items and table rows.
    label: 'Blocks of text',
    value: detail.element_count,
    note: 'Headings, paragraphs, list items and table rows.',
  });

  counts.push({
    id: 'chunks',
    // NOT "chunks". `references/states.md`: chunks, embeddings, collections, spans and queues are
    // ours; the user has documents, answers, sources and bots. This is the number a deletion removes
    // and a rebuild re-embeds at a provider's per-token price, and "excerpt" is the word the chat
    // surface already uses for the same object when it cites one.
    label: 'Searchable excerpts',
    value: detail.chunk_count,
    note: 'The pieces a bot can find and quote.',
  });

  return counts;
};

const capitalize = (word: string): string => word.charAt(0).toUpperCase() + word.slice(1);

/**
 * WHICH OF THREE THINGS THIS SCREEN CAN HONESTLY SAY ABOUT "the current version".
 *
 *   `single`  exactly one item, and it is pointing at a live version — `active_version` is populated
 *             and may be named. This is a single-file upload or a paste.
 *   `many`    more than one item. `active_version` is NULL and always will be: activation is a
 *             pointer on the ITEM, so "the current version" of a four-hundred-page crawl is a SET
 *             rather than a value and there is no honest scalar to publish. The pair of counts
 *             answers it instead.
 *   `none`    `active_version_count === 0`. NOTHING in this source is retrievable, whatever `status`
 *             says — the active-version pointer is one of the four mandatory filter terms, and an
 *             ingestion that never completed leaves it empty.
 *
 * ── THE TRAP THIS FUNCTION EXISTS TO CLOSE ─────────────────────────────────────────────────────
 * `active_version === null` DOES NOT MEAN "nothing is live" and `item_count === 1` does not mean the
 * pointer is populated. A four-hundred-page crawl with every page serving reports `null` here, and a
 * one-file source whose ingestion failed reports `null` too — for opposite reasons. A screen that
 * branched on the pointer alone is correct on single-file uploads and silently wrong on everything
 * else, which is the most likely defect on this page and the one the ordering below prevents:
 * `none` is decided FIRST, from the count, and the pointer is only read afterwards.
 */
export type LiveState = 'single' | 'many' | 'none';

export const liveState = (detail: SourceDetailResource): LiveState => {
  if (detail.active_version_count === 0) return 'none';
  return detail.active_version === null ? 'many' : 'single';
};

/**
 * THE ADVISORY WARNING CODES, IN THE WORDS OF SOMEBODY WHO DID NOT WRITE THE PARSER.
 *
 * ── THE SET IS OPEN AND THIS TABLE IS A LOOKUP WITH A FALLBACK, NEVER AN ENUM ──────────────────
 * The key set belongs to the ingestion service and grows with the parsers, so `@kb/contracts`
 * publishes no union and this app must not invent one. "Treat an unrecognised code as a code you
 * have no copy for, not as an error; it is a machine key rather than a sentence, so the shape that
 * works is a lookup table with a fallback to the raw string." That is what this is, and the fallback
 * is the render path a code that shipped this morning takes.
 *
 * A `Map`, because the key is a server-supplied string.
 *
 * ── WHAT NONE OF THESE SENTENCES MAY IMPLY ─────────────────────────────────────────────────────
 * A warning is NEVER a retrieval predicate: `ready` and `ready_with_warnings` are identical for
 * every query, so this list is never the answer to "why is this source not answering". Each sentence
 * is therefore about the CONTENT — what was read badly — and none of them says "not searchable"
 * unless that is literally what the code means (`ocr_text_unplaced`, where the text really did not
 * become an element).
 *
 * The VALUE behind each key is deliberately unpublished — it is unschema'd and can contain document
 * content, so the server sends a version COUNT instead. There is nothing here to interpolate.
 */
const WARNING_COPY = new Map<string, string>([
  [
    'ocr_low_coverage',
    'Part of a scanned page could not be read. Some text from it may be missing from answers.',
  ],
  [
    'ocr_coverage_unmeasurable',
    'How much of a scanned page was read could not be measured, so the quality of that page is unknown.',
  ],
  [
    'ocr_text_unplaced',
    'Text was read from a page but could not be placed in the document, so that page is not searchable.',
  ],
]);

/**
 * The sentence for a warning code, or `null` when this build has no copy for it.
 *
 * `null` rather than an invented sentence: the renderer shows the raw code in `--font-mono` instead,
 * which is what the pipeline actually reported and is the string a support engineer can grep. Making
 * something up for an unknown key would be worse than showing the key.
 */
export const warningCopy = (code: string): string | null => WARNING_COPY.get(code) ?? null;

/**
 * How many of this source's LIVE VERSIONS reported a code, in words — VERSIONS, never occurrences.
 *
 * The distinction matters at both ends and the contract states it: a `1` on a single-file source is
 * the only value it can ever hold, and a `1` on a crawl means one page carries the problem and says
 * NOTHING AT ALL about how badly. So the phrasing counts the thing the number actually counts, in
 * the noun this source's `type` uses for an item.
 */
export const warningScope = (detail: SourceDetailResource, versions: number): string =>
  detail.item_count === 1
    ? 'Reported for this source.'
    : `Reported for ${formatCount(versions)} of its ${formatCount(detail.item_count)} ${itemNoun(detail.type, detail.item_count)}.`;

/**
 * THE DELETE CONFIRMATION'S NUMBERS — the whole reason this screen fetches the detail resource
 * before offering the control.
 *
 * ── THE LIST SCREEN CANNOT SAY THIS AND DELIBERATELY DOES NOT TRY ──────────────────────────────
 * `source-row-actions.tsx` ships true prose instead, because `GET .../sources` carries no counts:
 * adding them would be five extra statements per source and a page of 25 would be 126 queries. Here
 * the numbers are already in hand, so `kb-ui-patterns`' requirement — state the consequence in
 * specific terms — is finally answerable rather than approximated.
 *
 * ── WHAT IT MAY NOT SAY, AND WHY THE BOT COUNT IS ABSENT ───────────────────────────────────────
 * "This removes 1,204 chunks from 3 bots" is the pattern's own example and the second half is
 * unavailable: no endpoint answers "which bots hold a grant on this source" (see
 * `source-assignment-api.ts`), and the assignment panel only knows about the bots on the page it is
 * showing. A number derived from that page would be a count of what happens to be visible, stated as
 * a fact about the organization. So the sentence names the CONTENT, which is exact, and says
 * "every bot it is assigned to" for the reach, which is true without being counted.
 *
 * ── ZERO IS THE INTERESTING CASE AND IT GETS ITS OWN SENTENCE ──────────────────────────────────
 * The counts are over the live versions only, so a source that never finished ingesting reports all
 * zeroes. "This removes 0 searchable excerpts" is technically true and reads as a bug; the honest
 * statement is that nothing of it is live, which is also the answer to "why is my bot not using it".
 */
export const deleteConsequenceCounts = (detail: SourceDetailResource): string => {
  if (detail.active_version_count === 0) {
    return 'Nothing in it is live right now, so no bot is answering from it today.';
  }

  const parts = [
    `${formatCount(detail.active_version_count)} ${itemNoun(detail.type, detail.active_version_count)}`,
  ];
  if (detail.page_count > 0) parts.push(`${formatCount(detail.page_count)} ${detail.page_count === 1 ? 'page' : 'pages'}`);
  if (detail.slide_count > 0) parts.push(`${formatCount(detail.slide_count)} ${detail.slide_count === 1 ? 'slide' : 'slides'}`);
  if (detail.sheet_count > 0) parts.push(`${formatCount(detail.sheet_count)} ${detail.sheet_count === 1 ? 'sheet' : 'sheets'}`);
  parts.push(
    `${formatCount(detail.chunk_count)} searchable ${detail.chunk_count === 1 ? 'excerpt' : 'excerpts'}`,
  );

  return `That is ${joinWithAnd(parts)}, and all of it leaves every bot it is assigned to.`;
};

/** "a, b and c" — the serial list this locale writes without an Oxford comma. `Intl.ListFormat` is
 *  deliberately not used: it needs a locale-aware conjunction for a sentence whose surrounding words
 *  are hard-coded English, so it would mix two languages in one sentence. */
const joinWithAnd = (parts: readonly string[]): string => {
  if (parts.length <= 1) return parts[0] ?? '';
  return `${parts.slice(0, -1).join(', ')} and ${parts[parts.length - 1]}`;
};
