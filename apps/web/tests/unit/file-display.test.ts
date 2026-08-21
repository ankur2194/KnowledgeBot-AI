import { describe, expect, it } from 'vitest';

import { SOURCE_TONE } from '@/components/tone';
import { formatBytes, sourceKindForFile } from '@/features/sources/file-display';

/**
 * The two DISPLAY derivations behind a file row, in the `unit` project — no DOM needed, because
 * neither of them touches one.
 *
 * ── THE LOCALE IS THE RUNNER'S, SO THE ASSERTIONS ARE SHAPES RATHER THAN STRINGS ────────────────
 * `formatBytes` calls `Intl.NumberFormat(undefined, …)` on purpose: the decimal separator belongs to
 * the reader, and `1,5MB` is what a de-DE user must see. That makes an exact-string assertion a
 * test of the machine's `LANG` rather than of this function, and it would go red on a developer's
 * laptop while passing on everyone else's. The regexes below accept either separator and an
 * optional narrow space, which is the whole set of things `Intl` is allowed to vary here.
 */

describe('formatBytes', () => {
  it('renders whole bytes below a kilobyte and one decimal above it', () => {
    expect(formatBytes(0)).toMatch(/^0\s?B$/);
    expect(formatBytes(921)).toMatch(/^921\s?B$/);
    // `0.9 kB` reads as an estimate; `921B` is the number.
    expect(formatBytes(921)).not.toMatch(/k/i);

    expect(formatBytes(1536)).toMatch(/^1[.,]5\s?kB$/);
    expect(formatBytes(12_345_678)).toMatch(/^11[.,]8\s?MB$/);
  });

  it('stops climbing at gigabytes rather than inventing a unit for a size the product has not got', () => {
    expect(formatBytes(3 * 1024 ** 3)).toMatch(/^3\s?GB$/);
    // Two orders of magnitude past the top of the ladder still reads in GB. The alternative — a
    // terabyte step — is a rendering for a state that is not an upload.
    expect(formatBytes(4 * 1024 ** 4)).toMatch(/GB$/);
  });

  it('renders an em dash rather than a number for a value that is not a size', () => {
    // Not defensive padding: this is also fed from a ProgressEvent, and "-1B" or "NaNB" in a table
    // cell is a rendering bug that reads as a data bug.
    expect(formatBytes(Number.NaN)).toBe('—');
    expect(formatBytes(-1)).toBe('—');
    expect(formatBytes(Number.POSITIVE_INFINITY)).toBe('—');
  });

  it('never uses compact notation, which collides with the unit at gigabyte scale', () => {
    // `notation: 'compact'` emits `3.2BB` for three gigabytes — compact "B" for billion, narrow "B"
    // for byte — which is not a size. Asserted because the fix is one option away from being
    // reintroduced by someone shortening the output.
    expect(formatBytes(3 * 1024 ** 3)).not.toMatch(/BB/);
  });
});

describe('sourceKindForFile', () => {
  const file = (type: string, name = 'thing'): File => new File([], name, { type });

  it('classifies the five kinds a file can be', () => {
    expect(sourceKindForFile(file('image/png', 'diagram.png'))).toBe('image');
    expect(
      sourceKindForFile(
        file(
          'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
          'budget.xlsx',
        ),
      ),
    ).toBe('spreadsheet');
    expect(sourceKindForFile(file('application/vnd.ms-excel', 'legacy.xls'))).toBe('spreadsheet');
    expect(sourceKindForFile(file('text/csv', 'rows.csv'))).toBe('spreadsheet');
    expect(
      sourceKindForFile(
        file('application/vnd.openxmlformats-officedocument.presentationml.presentation', 'deck.pptx'),
      ),
    ).toBe('presentation');
    expect(sourceKindForFile(file('text/html', 'saved-page.html'))).toBe('website');
    expect(sourceKindForFile(file('application/pdf', 'report.pdf'))).toBe('document');
  });

  it('falls back to `document` rather than refusing, because this decides a TINT and nothing else', () => {
    // An empty `File.type` is the normal state for an extension the OS does not know, and a browser
    // that reports nothing must not produce an untinted badge with no icon.
    expect(sourceKindForFile(file(''))).toBe('document');
    expect(sourceKindForFile(file('application/x-something-nobody-registered'))).toBe('document');
    // A forged type changes the colour of a 20px badge and nothing else: the server sniffs content
    // independently of the filename, and that is the control.
    expect(sourceKindForFile(file('image/png', 'not-really.exe'))).toBe('image');
  });

  it('is case-insensitive, because `File.type` casing is the platform’s', () => {
    expect(sourceKindForFile(file('IMAGE/PNG'))).toBe('image');
    expect(sourceKindForFile(file('TEXT/CSV'))).toBe('spreadsheet');
  });

  it('returns a key the decided tone map already has, rather than a sixth vocabulary', () => {
    // `SOURCE_TONE` is the kind -> tone mapping and it is `satisfies Record<SourceKind, ToneName>`,
    // so this loop is the runtime half: every value this function can return has a tone.
    for (const type of ['image/png', 'text/csv', 'text/html', 'application/pdf']) {
      expect(SOURCE_TONE[sourceKindForFile(file(type))]).toBeTypeOf('string');
    }
  });
});
