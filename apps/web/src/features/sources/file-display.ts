import type { SourceKind } from '@/components/tone';

/**
 * The two DISPLAY derivations a file row needs: what to call its size, and which categorical tone
 * to tint its badge with. React-free on purpose, so a spec can call either directly.
 *
 * ── NEITHER OF THESE IS A LIMIT, AND THE DISTINCTION IS THE WHOLE REASON THIS FILE HAS A DOCBLOCK ─
 * §8.10 makes the maximum file size and the accepted MIME list PER-ORGANIZATION, so there is no byte
 * constant and no MIME constant anywhere in apps/web or packages/contracts: the cap comes from
 * `OrgUploadLimits.max_bytes`, the picker's `accept=` from `OrgUploadLimits.allowed_mime`, and the
 * schema is a factory over both (`@kb/contracts/forms`). Hard-code either and the form silently
 * enforces a different limit from the organization it is rendering for.
 *
 * Nothing below gates anything. `formatBytes` is a unit ladder, and `sourceKindForFile` picks a
 * TINT — an unrecognised file is `document`, never a refusal. If a reader ever finds themselves
 * comparing a value from this module against a limit, the bug is at the comparison.
 */

/**
 * `1024`, and it is the base of the unit rather than a threshold. `kB`/`MB`/`GB` at a 1024 divisor
 * are strictly `KiB`/`MiB`/`GiB`; every file manager and every upload UI a user has seen makes the
 * same trade, and "1.5 KiB" in a table cell costs more comprehension than it buys accuracy.
 */
const UNIT_BASE = 1024;

/**
 * The CLDR unit at each step. Stops at `gigabyte`: a file larger than 1024 GB is not an upload, and
 * a ladder that keeps going invents a rendering for a state the product does not have.
 */
const UNITS = ['byte', 'kilobyte', 'megabyte', 'gigabyte'] as const;

/**
 * Bytes → what the row shows beside the filename: `512B`, `1.5kB`, `11.8MB`.
 *
 * `Intl.NumberFormat` with `style: 'unit'` rather than a string concatenation, so the number is
 * grouped and the decimal separator is the READER's — `1,5MB` in de-DE — which a hand-rolled
 * `${value}MB` cannot be. `unitDisplay: 'narrow'` because `'short'` renders `512 byte` in en-US,
 * singular, for every count.
 *
 * `notation: 'compact'` is deliberately NOT used: it collides with the unit at gigabyte scale and
 * emits `3.2BB` (compact "B" for billion, narrow "B" for byte), which is not a size.
 *
 * WHOLE BYTES BELOW 1 kB, one decimal above. `0.9 kB` reads as an estimate; `921B` is the number.
 */
export function formatBytes(bytes: number): string {
  // Negative and NaN are not sizes. `File.size` is neither, but this is also fed from a
  // `ProgressEvent`, where `loaded` on a failed transfer has been observed as 0 and nothing else —
  // guarded so a broken input renders a dash rather than "-1B" or "NaNB".
  if (!Number.isFinite(bytes) || bytes < 0) return '—';

  let value = bytes;
  let step = 0;
  while (value >= UNIT_BASE && step < UNITS.length - 1) {
    value /= UNIT_BASE;
    step += 1;
  }

  return new Intl.NumberFormat(undefined, {
    style: 'unit',
    // `noUncheckedIndexedAccess` is on, and the `??` is not defensive padding: the loop's bound
    // already keeps `step` inside the array. It is what keeps the type a `SourceKind`-style union
    // rather than `… | undefined`, which `Intl.NumberFormat`'s `unit` option will not accept.
    unit: UNITS[step] ?? 'byte',
    unitDisplay: 'narrow',
    maximumFractionDigits: step === 0 ? 0 : 1,
  }).format(value);
}

/**
 * A file → one of the six categorical tones' keys, for the badge on its row.
 *
 * ── STRUCTURAL SUBSTRINGS, NOT A MIME TABLE, AND THAT IS BOTH RULES AT ONCE ─────────────────────
 * There is no MIME constant here: nothing below is a complete media type. `spreadsheet` and
 * `presentation` are the words IANA itself puts in the OOXML and OpenDocument subtypes
 * (`…-officedocument.spreadsheetml.sheet`, `…opendocument.presentation`), `image/` is a top-level
 * type, and `csv` and `html` are subtype stems. So this cannot be mistaken for — or drift into —
 * the organization's `allowed_mime` list, which is the only thing that decides what may be
 * uploaded.
 *
 * `File.type` IS FORGEABLE AND THAT DOES NOT MATTER HERE. The browser derives it from the extension
 * on most platforms and any caller can set it; a lie changes the colour of a 20px badge. The server
 * sniffs content independently of the filename, and that is the control (kb-security-baseline).
 *
 * `website` is unreachable from a file and that is correct — a crawled site arrives through a URL
 * form, not this dropzone. `document` is the fallback rather than a seventh "unknown" tone, because
 * the tone set is closed at six (`components/tone.ts`) and an unrecognised office file is a
 * document in every sense the user cares about.
 */
export function sourceKindForFile(file: File): SourceKind {
  const type = file.type.toLowerCase();

  if (type.startsWith('image/')) return 'image';
  if (type.includes('spreadsheet') || type.includes('csv') || type.includes('ms-excel')) {
    return 'spreadsheet';
  }
  if (type.includes('presentation') || type.includes('powerpoint')) return 'presentation';
  if (type.includes('html')) return 'website';

  return 'document';
}
