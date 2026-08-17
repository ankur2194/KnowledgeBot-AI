import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { stripComments, tsxFiles } from '../support/source-scan';

/**
 * EVERY `<body>` THIS APP RENDERS CARRIES `suppressHydrationWarning`.
 *
 * THE INCIDENT THIS ENCODES. `/reset-password` reported a full-screen Next.js hydration error naming
 * `(auth)/layout.tsx`, with the diff showing three attributes nothing in this repo writes:
 * `data-new-gr-c-s-check-loaded` and `data-gr-ext-installed` (Grammarly) and `cz-shortcut-listen`
 * (ColorZilla). Extensions mutate `<body>` before React hydrates, the server HTML has none of it, and
 * React reports the mismatch. It arrived stacked directly above a genuine
 * `ERR_CERT_AUTHORITY_INVALID`, which is the actual cost: an error nobody can act on, occupying the
 * screen next to one they must.
 *
 * WHY THE COPY ON `<html>` DID NOT COVER IT, since that is the assumption that produced the bug.
 * React applies `suppressHydrationWarning` to ONE element — its own attributes and its text children —
 * and it does NOT cascade to descendants. `(auth)` and `(admin)` both had it on `<html>` (for
 * next-themes, which writes a class there before React runs) and neither was thereby protected on
 * `<body>`. The two uses are independent and one of them is not evidence of the other.
 *
 * WHY A CLOSURE OVER THE TREE rather than three named assertions. Three specs naming three layouts say
 * nothing about the fourth root layout somebody adds, and this repo has three already — `(admin)`,
 * `(auth)` and `(chat)` — precisely because ADR-027 makes a new surface a new `<html>`. The next one
 * inherits the guard without anyone remembering it exists. Source text rather than rendered DOM on
 * purpose: no extension is installed in the test browser, so the mismatch this prevents is not
 * reproducible from a spec at all — only the attribute's presence is checkable, and it is checkable
 * before anything mounts.
 *
 * WHAT THIS DELIBERATELY DOES NOT ASSERT: that `<html>` carries it. `(chat)` mounts no next-themes and
 * correctly does not, so requiring it there would demand a suppression with no cause.
 */

const SRC = join(import.meta.dirname, '..', '..', 'src');

/**
 * The opening `<body` tag's attributes — everything up to the closing `>` of the tag itself.
 *
 * `[^>]*` is safe here for the same reason it is in `form-method.test.ts`: no attribute value on these
 * elements contains a `>`, and a negated character class matches newlines, so a tag broken across
 * lines by the formatter is still one match. Comments are stripped FIRST — the prose above each of the
 * three real tags necessarily writes `<body>` to explain itself, and without the strip all three
 * correct files failed on their own documentation. See `stripComments`.
 */
function openingBodyTags(source: string): readonly string[] {
  return [...stripComments(source).matchAll(/<body[^>]*>/g)].map((m) => m[0]);
}

describe('every rendered <body> suppresses hydration warnings', async () => {
  const files = await tsxFiles(SRC);
  const withBody = files.filter((file) => openingBodyTags(readFileSync(file, 'utf8')).length > 0);

  it('finds the root layouts at all, so an empty sweep cannot pass', () => {
    // A positive control, and a FLOOR rather than equality: a fourth root layout must fail below with
    // its own name, not here. This guards only the "matched nothing and passed" case — a moved `src/`,
    // a renamed layout convention, or a regex that quietly stopped matching.
    expect(withBody.length).toBeGreaterThanOrEqual(3);
  });

  it.for(withBody.map((file) => [file] as const))('%s', ([file]) => {
    for (const tag of openingBodyTags(readFileSync(file, 'utf8'))) {
      expect(tag, `<body> in ${file} is missing suppressHydrationWarning`).toMatch(
        /\bsuppressHydrationWarning\b/,
      );
    }
  });
});
