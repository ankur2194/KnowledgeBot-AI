import { uploadDefaults, uploadSchema, type OrgUploadLimits } from '@kb/contracts/forms';
import { zodResolver } from '@hookform/resolvers/zod';
import { createFormControl } from 'react-hook-form';
import type { FieldErrors, Path, UseFormReturn } from 'react-hook-form';
import { describe, expect, it } from 'vitest';

import { UPLOAD_KNOWN_PATHS, reindexFileErrors } from '@/features/sources/api';
import { batchIssue, fileIssues } from '@/features/sources/upload-issues';
import { applyServerErrors } from '@/lib/forms/apply-server-errors';

/**
 * READING the upload form's error store back out, in the `unit` project (node, no DOM).
 *
 * ── THE THING THIS FILE EXISTS FOR ──────────────────────────────────────────────────────────────
 * `tests/unit/upload-form.test.ts` proves the WRITE half — that a 422 keyed `files.0` on the wire
 * lands on the row it belongs to rather than on row 0 five times. This proves the read half, and the
 * two failures it guards are both silent:
 *
 *   1. A BATCH message rendered inside row 0. `message`, `type`, `ref` and `root` sit beside the
 *      numeric indices on the same node, so a reader that trusts `Object.entries` without checking
 *      the key is a number puts "choose at least one file" in the first file's row.
 *   2. A batch message rendered NOWHERE. `setError('files', …)` writes a sibling `message`, while a
 *      resolver issue at the array path is folded to `root` the moment `files.0` … `files.n` are
 *      registered. Reading one spelling makes the message invisible in exactly half the situations
 *      that produce it — and the two `describe` blocks below cover one half each, so a reader that
 *      picked either alone fails.
 *
 * Both render as a screen that looks fine and says the wrong thing, which is why they are asserted
 * against a REAL resolver run over the REAL shared schema rather than against a hand-built error
 * object: an object typed here encodes what the author believed RHF stores.
 */

interface UploadForm {
  files: File[];
}

const LIMITS: OrgUploadLimits = {
  max_bytes: 4096,
  allowed_mime: ['application/pdf', 'text/csv'],
  max_batch: 3,
};

const pdf = (name: string, bytes = 32): File =>
  new File([new Uint8Array(bytes)], name, { type: 'application/pdf' });

/** RHF's headless entry point — the same control object `useForm` builds, without React, because
 *  everything under test is the error store and none of it is rendering. */
function harness(files: readonly File[]): UseFormReturn<UploadForm, unknown, UploadForm> {
  const control = createFormControl<UploadForm>({
    // The defaults FACTORY, not a literal: it is the one path from "nothing chosen yet" into form
    // state, which is what keeps `reset(someServerObject)` from ever being the shortcut here.
    defaultValues: uploadDefaults(),
    resolver: zodResolver(uploadSchema(LIMITS)) as never,
  });
  const form = control as unknown as UseFormReturn<UploadForm, unknown, UploadForm>;
  form.setValue('files', [...files]);
  for (const index of files.keys()) {
    const field = form.register(`files.${index}` as Path<UploadForm>);
    // Refs are installed by CALLING the callback `register` returns, exactly as React does.
    field.ref({ name: field.name, focus: () => {} } as unknown as HTMLInputElement);
  }
  return form;
}

const errorsOf = (form: UseFormReturn<UploadForm, unknown, UploadForm>): FieldErrors<UploadForm> =>
  form.control._formState.errors;

describe('nothing wrong reads as nothing wrong', () => {
  it('returns undefined and an empty map on a clean store', () => {
    const form = harness([pdf('alpha.pdf')]);

    expect(batchIssue(errorsOf(form))).toBeUndefined();
    expect(fileIssues(errorsOf(form)).size).toBe(0);
  });
});

describe('the two altitudes are read apart', () => {
  it('keeps a BATCH message out of row 0', () => {
    const form = harness([pdf('alpha.pdf'), pdf('bravo.pdf')]);

    // The shape `applyServerErrors` writes for a 422 keyed on the bare attribute — the batch cap or
    // the at-least-one rule.
    applyServerErrors(form, UPLOAD_KNOWN_PATHS, {
      files: ['This organization accepts at most 3 files at a time.'],
    });

    expect(batchIssue(errorsOf(form))).toBe('This organization accepts at most 3 files at a time.');
    // THE HALF THAT MATTERS: `message` is a key on the same node as the indices, and a reader that
    // does not check `Number.isInteger` renders the batch's sentence inside the first file's row.
    expect(fileIssues(errorsOf(form)).size).toBe(0);
  });

  it('keeps per-file messages apart and out of the batch banner', () => {
    const form = harness([pdf('alpha.pdf'), pdf('bravo.pdf'), pdf('charlie.pdf')]);

    // Three requests, three rejections, all keyed `files.0` on the wire — one file per request.
    applyServerErrors(form, UPLOAD_KNOWN_PATHS, reindexFileErrors({ 'files.0': ['Empty.'] }, 0));
    applyServerErrors(
      form,
      UPLOAD_KNOWN_PATHS,
      reindexFileErrors({ 'files.0': ['Encrypted.'] }, 2),
    );

    const issues = fileIssues(errorsOf(form));
    expect(issues.get(0)).toBe('Empty.');
    // The row that succeeded has nothing — the negative control that makes the other two mean
    // something.
    expect(issues.get(1)).toBeUndefined();
    expect(issues.get(2)).toBe('Encrypted.');
    expect(batchIssue(errorsOf(form))).toBeUndefined();
  });
});

describe('against a real resolver run over the shared schema', () => {
  it('reports the file over THIS organization’s ceiling, on that file’s index only', async () => {
    const form = harness([pdf('alpha.pdf', 32), pdf('bravo.pdf', 5000)]);

    await form.trigger();

    const issues = fileIssues(errorsOf(form));
    // 5000 > `max_bytes` 4096. The number is the DTO's; nothing in `apps/web` knows it.
    expect(issues.get(1)).toBeDefined();
    expect(issues.get(0)).toBeUndefined();
  });

  it('reports the EMPTY batch, which is the case `.min(1)` exists for', async () => {
    const form = harness([]);

    await form.trigger();

    // `uploadDefaults()` is deliberately not schema-valid: an empty batch is not postable, and this
    // is the message that says so. If `batchIssue` read only one of the two spellings RHF uses, this
    // is the assertion that would go quietly undefined.
    expect(batchIssue(errorsOf(form))).toBeDefined();
    expect(fileIssues(errorsOf(form)).size).toBe(0);
  });

  it('reports the batch cap AND the offending file together, without either hiding the other', async () => {
    // Four files against `max_batch: 3`, one of them also over `max_bytes`. Both altitudes fire in
    // ONE resolver run, and the array-level issue lands under `root` rather than as a sibling
    // `message` — measured, and the reason `batchIssue` reads both spellings. The EMPTY case above
    // is the other one: with no `files.N` registered it is a scalar `message`, so a reader that
    // picked either spelling alone passes one of these two tests and fails the other.
    const form = harness([
      pdf('alpha.pdf', 32),
      pdf('bravo.pdf', 5000),
      pdf('charlie.pdf', 32),
      pdf('delta.pdf', 32),
    ]);

    await form.trigger();

    expect(batchIssue(errorsOf(form))).toBeDefined();
    expect(fileIssues(errorsOf(form)).get(1)).toBeDefined();
    expect(fileIssues(errorsOf(form)).get(0)).toBeUndefined();
  });
});
