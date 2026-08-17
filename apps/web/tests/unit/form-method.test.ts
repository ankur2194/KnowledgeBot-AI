import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { stripComments, tsxFiles } from '../support/source-scan';

/**
 * EVERY `<form>` IN THIS APP DECLARES `method="post"`.
 *
 * THE INCIDENT THIS ENCODES. The Turbopack dev client could not open its HMR socket — `proxy.ts`'s
 * matcher redirected `/_next/webpack-hmr` to `/login` and Next's cross-origin dev guard 500'd it —
 * and the app-router dev bootstrap awaits that connection before `hydrateRoot`. So the page rendered,
 * looked correct, and hydrated NEVER: no `onSubmit` was ever attached. Submitting then fell through
 * to the browser's native form submission, which with no `method` is a **GET**, which put
 * `password=` and `password_confirmation=` in the query string — the address bar, browser history,
 * the dev server's request log and Traefik's access log. Every layer was silent: HTTP 200, no page
 * error, no failed request.
 *
 * `method="post"` does not fix hydration and is not meant to. It bounds the blast radius of the
 * general class — a click before hydration completes, a chunk that 404s, a module that throws — none
 * of which this app can prevent, all of which end in a native submit.
 *
 * WHAT POST DOES INSTEAD, MEASURED. A page route has no POST handler, and this is NOT a 405: the
 * request re-renders the page with 200, an empty form, and the submitted values echoed nowhere. The
 * fields travel in the request BODY, so the access log records `POST /login 200` where it previously
 * recorded `GET /login?email=…&password=…`. Nothing reaches the URL bar, history, or either log. The
 * fallback is therefore a silent no-op rather than a visible error — the right trade for a path that
 * should be unreachable, and worth knowing before someone reads a blank form as a second bug.
 *
 * WHY THIS IS A CLOSURE OVER THE TREE rather than five per-component assertions. Five specs asserting
 * five known files say nothing about the sixth form somebody adds next quarter, and the leak is
 * invisible until it happens in production. This walks `src/` and requires the attribute of every
 * `<form` it finds, so a new form is covered by existing without anybody remembering. It reads source
 * text on purpose: a rendered-DOM assertion would need the components project and its browser, and
 * this property is a source-level invariant that holds before anything mounts.
 */

const SRC = join(import.meta.dirname, '..', '..', 'src');

/**
 * The opening `<form` tag's attributes — everything up to the closing `>` of the tag itself.
 *
 * The forms here span many lines and carry comments, so the naive single-line match does not work.
 * `[^>]*` stops at the first `>`, which for these components is the end of the opening tag: no
 * attribute value in the tree contains one, and a `>` inside a child element is necessarily after it.
 *
 * Comments are stripped FIRST, and this direction of the hazard is the dangerous one: a docblock
 * containing the text `<form method="post">` — which the explanation of this rule invites somebody to
 * write — would satisfy the assertion below on behalf of a real form that lacked the attribute. That is
 * a PASS for a leaking file. Found while adding `body-hydration.test.ts`, where the same regex shape
 * matched its own prose and failed loudly; the loud direction is what exposed the quiet one.
 */
function openingFormTags(source: string): readonly string[] {
  return [...stripComments(source).matchAll(/<form[^>]*>/g)].map((m) => m[0]);
}

describe('every form declares method="post"', async () => {
  const files = await tsxFiles(SRC);
  const withForms = files.filter((file) => openingFormTags(readFileSync(file, 'utf8')).length > 0);

  it('finds the forms at all, so an empty sweep cannot pass', () => {
    // A positive control. A broken glob, a moved `src/`, or a regex that stopped matching would
    // otherwise leave this file green while asserting nothing — the failure mode the whole suite
    // exists to catch. The count is deliberately a FLOOR, not equality: a new form must not fail
    // here (it fails below, with its own name), and this only guards the "found nothing" case.
    expect(withForms.length).toBeGreaterThanOrEqual(5);
  });

  it.for(files.map((file) => [file] as const))('%s', ([file]) => {
    for (const tag of openingFormTags(readFileSync(file, 'utf8'))) {
      // Asserted on the TAG, not the file: a component holding two forms must carry it on both.
      expect(tag, `<form> in ${file} is missing method="post"`).toMatch(/\bmethod="post"/);
    }
  });
});
