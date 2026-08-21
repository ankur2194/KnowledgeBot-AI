import { uploadDefaults, uploadSchema } from '@kb/contracts/forms';
import { createFormControl } from 'react-hook-form';
import type { FieldErrors, Path, UseFormReturn } from 'react-hook-form';
import { describe, expect, it } from 'vitest';

import { UPLOAD_KNOWN_PATHS, reindexFileErrors } from '@/features/sources/api';
import { applyServerErrors } from '@/lib/forms/apply-server-errors';

/**
 * The upload form's server-error path, end to end, in the `unit` project (node, no DOM).
 *
 * ── THE THING THIS FILE EXISTS FOR ──────────────────────────────────────────────────────────────
 * Every upload request sends its file as `files[0]`, because one file per request is a CLIENT
 * decision and the server should not have to know about it (`features/sources/api.ts`). So every
 * per-file 422 comes back keyed `files.0` no matter which row it belongs to, and two separate
 * mechanisms have to line up before the message reaches the right control:
 *
 *   1. `reindexFileErrors` moves the WIRE's index onto the ROW's index.
 *   2. `applyServerErrors` folds `.\d+` to `.*` for the MEMBERSHIP TEST ONLY, so `files.3` matches
 *      the `files.*` in `UPLOAD_KNOWN_PATHS` while the path handed to `setError` stays `files.3` —
 *      which is the control the user is looking at.
 *
 * Break either one and the failure is silent in the way that matters: the request 422s, `setError`
 * writes to a name nothing renders (or to row 0 five times), the screen does not change, and the
 * user clicks Upload again. `createFormControl` is RHF's headless entry point — the same control
 * object `useForm` builds, without React — because everything under test is the error store and the
 * field registry, and none of it is rendering.
 */

interface UploadForm {
  files: File[];
}

interface Harness {
  readonly form: UseFormReturn<UploadForm, unknown, UploadForm>;
  readonly errors: () => FieldErrors<UploadForm>;
}

const csv = (name: string): File => new File([new Uint8Array(2)], name, { type: 'text/csv' });

/** Four chosen files, each registered as its own array path — the shape an upload form has once the
 *  user has dropped four things on it. */
function harness(): Harness {
  const control = createFormControl<UploadForm>({
    // THE DEFAULTS FACTORY, not a literal. It is the one path from "nothing chosen yet" into form
    // state, which is what keeps a `reset(someServerObject)` from ever being the shortcut here.
    defaultValues: uploadDefaults(),
  });
  const form = control as unknown as UseFormReturn<UploadForm, unknown, UploadForm>;

  form.setValue('files', [csv('alpha.csv'), csv('bravo.csv'), csv('charlie.csv'), csv('delta.csv')]);
  for (const index of [0, 1, 2, 3]) {
    const field = form.register(`files.${index}` as Path<UploadForm>);
    // Refs are installed by CALLING the callback `register` returns, exactly as React does. Without
    // one the field is mounted and unfocusable, and `shouldFocus` is silently spent on nothing.
    field.ref({ name: field.name, focus: () => {} } as unknown as HTMLInputElement);
  }

  return { form, errors: () => form.control._formState.errors };
}

describe('UPLOAD_KNOWN_PATHS', () => {
  it('is derived from the shared schema and carries the wildcard the fold needs', () => {
    expect([...UPLOAD_KNOWN_PATHS]).toEqual(['files', 'files.*']);
  });

  it('names every key the schema does, so a new field cannot be forgotten here', () => {
    // The limits are arbitrary — only the key set is read, and `.max()`/`.mime()` add no key. This
    // is the assertion that makes the derivation real rather than a coincidence: hand-type the list
    // and a second field added to `uploadSchema` becomes an orphan that renders in a batch banner.
    const shapeKeys = Object.keys(
      uploadSchema({ max_bytes: 1, allowed_mime: [], max_batch: 1 }).shape,
    );
    expect(shapeKeys.every((key) => UPLOAD_KNOWN_PATHS.includes(key))).toBe(true);
    expect(UPLOAD_KNOWN_PATHS).toHaveLength(shapeKeys.length * 2);
  });
});

describe('reindexFileErrors', () => {
  it('moves the wire’s index onto the row’s index', () => {
    expect(reindexFileErrors({ 'files.0': ['That file is empty.'] }, 3)).toEqual({
      'files.3': ['That file is empty.'],
    });
  });

  it('keeps a nested tail, because Laravel can key a per-file rule below the file', () => {
    expect(reindexFileErrors({ 'files.0.name': ['Too long.'] }, 2)).toEqual({
      'files.2.name': ['Too long.'],
    });
  });

  it('leaves an un-indexed key alone', () => {
    // `files` is a real path this form renders — the batch cap and the at-least-one rule live there
    // — so rewriting it would move a batch-level message onto one row.
    expect(reindexFileErrors({ files: ['Choose at least one file.'] }, 4)).toEqual({
      files: ['Choose at least one file.'],
    });
  });

  it('passes an unrelated key through untouched, so it can reach root.serverError', () => {
    expect(reindexFileErrors({ collection_id: ['No such collection.'] }, 1)).toEqual({
      collection_id: ['No such collection.'],
    });
  });

  it('rewrites only the LEADING segment pair, never an index deeper in the path', () => {
    expect(reindexFileErrors({ 'meta.files.0': ['nope'] }, 7)).toEqual({
      'meta.files.0': ['nope'],
    });
  });
});

describe('a per-file 422 lands on that file and not on the batch', () => {
  it('writes `files.0` from the wire onto the row that actually failed', () => {
    const { form, errors } = harness();

    // What the transport hands back: a validation KbError whose `errors` map is keyed `files.0`,
    // because THIS request's batch was one file long. The row is index 2.
    const fromWire = { 'files.0': ['A PDF that is really a ZIP is not accepted.'] };

    applyServerErrors(form, UPLOAD_KNOWN_PATHS, reindexFileErrors(fromWire, 2));

    const files = errors().files as unknown as Record<string, { message?: string }> | undefined;
    expect(files?.['2']?.message).toBe('A PDF that is really a ZIP is not accepted.');
    // THE HALF THAT MATTERS. Without the reindex every file's message lands here, one overwriting
    // the next, while the rows that failed stay clean.
    expect(files?.['0']).toBeUndefined();
    // And it is not a batch failure: `root.serverError` is the slot for a message no control
    // renders, and this one has a control.
    expect(errors().root?.serverError).toBeUndefined();
  });

  it('keeps four rows’ messages apart instead of collapsing them onto row 0', () => {
    const { form, errors } = harness();

    // Four requests, four rejections, all keyed `files.0` on the wire.
    applyServerErrors(form, UPLOAD_KNOWN_PATHS, reindexFileErrors({ 'files.0': ['Empty.'] }, 0));
    applyServerErrors(form, UPLOAD_KNOWN_PATHS, reindexFileErrors({ 'files.0': ['Too big.'] }, 1));
    applyServerErrors(form, UPLOAD_KNOWN_PATHS, reindexFileErrors({ 'files.0': ['Encrypted.'] }, 3));

    const files = errors().files as unknown as Record<string, { message?: string }> | undefined;
    expect(files?.['0']?.message).toBe('Empty.');
    expect(files?.['1']?.message).toBe('Too big.');
    // The row that succeeded has nothing on it — the negative control that makes the three above
    // mean something.
    expect(files?.['2']).toBeUndefined();
    expect(files?.['3']?.message).toBe('Encrypted.');
    expect(errors().root?.serverError).toBeUndefined();
  });

  it('routes a batch-level key to the batch and a key this form does not render to root', () => {
    const { form, errors } = harness();

    applyServerErrors(form, UPLOAD_KNOWN_PATHS, {
      // `files` with no index: the batch cap or the at-least-one rule.
      files: ['This organization accepts at most 3 files at a time.'],
      // A rule on a field this form does not render at all.
      retention_days: ['Must be a number.'],
    });

    expect(errors().files?.message).toBe('This organization accepts at most 3 files at a time.');
    // Validation MESSAGES are end-user copy — Laravel translates them — which is why the orphan is
    // rendered verbatim while the envelope's operator-facing `message` never is.
    expect(errors().root?.serverError?.message).toBe('Must be a number.');
  });

  it('would drop every per-file message without the wildcard, which is why the derivation carries it', () => {
    const { form, errors } = harness();

    // The negative control on `UPLOAD_KNOWN_PATHS` itself: the same call with only the bare `files`
    // path known. `files.2` folds to `files.*`, which is not in this set, so the message becomes an
    // orphan and lands in one banner — the "clicking Upload does nothing" loop.
    applyServerErrors(form, ['files'], { 'files.2': ['Encrypted.'] });

    const files = errors().files as unknown as Record<string, { message?: string }> | undefined;
    expect(files?.['2']).toBeUndefined();
    expect(errors().root?.serverError?.message).toBe('Encrypted.');
  });
});
