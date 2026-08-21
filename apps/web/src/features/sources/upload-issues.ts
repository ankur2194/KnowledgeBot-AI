import type { FieldErrors } from 'react-hook-form';

/**
 * READING THE UPLOAD FORM'S ERROR STORE BACK OUT — the batch-level message, and the per-row map.
 *
 * REACT-FREE on purpose, like `file-display.ts` beside it, so both halves can be driven directly
 * from the `unit` project (node, no DOM) against a headless `createFormControl`. Nothing here
 * WRITES: `applyServerErrors` owns every write into this store and is not duplicated, extended or
 * second-guessed here.
 *
 * ── WHY READING IT NEEDS TWO FUNCTIONS AND NOT ONE ──────────────────────────────────────────────
 * `uploadSchema` has ONE field, `files`, and its errors arrive at two different altitudes with two
 * different renderings:
 *
 *   `files`          — the batch is wrong AS A BATCH. `.min(1)` with nothing chosen, `.max(max_batch)`
 *                      over the organization's cap, or a 422 keyed on the bare `files` attribute.
 *                      There is one of these and it belongs on the CONTROL — the file input — bound
 *                      with `aria-invalid` + `aria-describedby`.
 *   `files.<index>`  — ONE file is wrong. There can be five at once, they are about five different
 *                      things, and each belongs in the ROW it is about (states.md: a failed row is
 *                      an inline block in the row, not a banner over the batch).
 *
 * Collapsing them into one string is the "eight of ten uploaded" bug: five per-file refusals joined
 * into one red banner, with the five rows they name looking fine.
 *
 * ── WHY THE READS ARE STRUCTURAL RATHER THAN TYPED ──────────────────────────────────────────────
 * RHF stores an array field's errors as an ARRAY with holes, and a message about the array itself as
 * a sibling `message` (or, when a resolver wrote it, as `root`). Its published types describe that
 * union as `Merge<FieldError, FieldErrorsImpl<…>[]>`, which does not index cleanly and which
 * `tests/unit/upload-form.test.ts` already casts through to make its assertions. So the two readers
 * below narrow from `unknown` rather than trusting a shape — the same reason `isKbValidationEnvelope`
 * exists on the transport side, and the reason neither of them can throw on a store shape that moves
 * under a dependency bump: they return "no message" instead of a `TypeError` inside a render.
 */

/** The narrowest thing an RHF error node is, for the purposes of showing it to somebody. */
const messageOf = (node: unknown): string | undefined => {
  if (node === null || typeof node !== 'object') return undefined;
  const message = (node as { message?: unknown }).message;
  return typeof message === 'string' && message.length > 0 ? message : undefined;
};

/**
 * The message about the BATCH, or undefined when the batch itself is fine.
 *
 * `message` first, then `root.message`. BOTH SPELLINGS ARE REAL, and which one appears was measured
 * rather than assumed (2026-08-20, `@hookform/resolvers` 5.7.1 / RHF 7.84.0, dumped from a real
 * `trigger()` over the shared schema):
 *
 *   nothing chosen, no `files.N` registered   -> `{ message: 'Too small: … >=1 items' }`
 *   four files over a cap of three            -> `{ root: { message: 'Too big: … <=3 items' } }`
 *   four files, one also over `max_bytes`     -> `{ '1': {…}, root: { message: '… <=3 items' } }`
 *   two files, one over `max_bytes`           -> `[ null, { message: '… <=4096 bytes' } ]`
 *
 * The axis is not "are there per-index errors" — the second row has none and still uses `root`. It is
 * whether RHF knows `files` has CHILD FIELDS at all: `toNestErrors` moves an issue at the array path
 * to `root` once `files.0` … `files.n` are registered, because the slot is an array by then and
 * cannot also carry a scalar. Reading only one of the two makes the batch's own message invisible in
 * whichever half of the situations the reader did not pick, and the four rows above are exactly the
 * cases `tests/unit/upload-issues.test.ts` drives.
 */
export function batchIssue(errors: FieldErrors<{ files: File[] }>): string | undefined {
  const files: unknown = errors.files;
  if (files === undefined) return undefined;
  return messageOf(files) ?? messageOf((files as { root?: unknown }).root);
}

/**
 * ROW INDEX -> that row's message, for the rows that have one. Empty when none do.
 *
 * A `Map` rather than the array itself: the array is SPARSE (only the refused indices are set), the
 * consumer asks by index once per row, and a `Map.get` is not the computed member read on a
 * caller-supplied key that `security/detect-object-injection` exists to flag. `Object.entries` over
 * a sparse array yields only the present indices, so a batch of forty with one bad file iterates
 * once.
 *
 * NON-NUMERIC KEYS ARE SKIPPED, which is what keeps the batch message out of this map: `message`,
 * `type`, `ref` and `root` all sit beside the indices on the same node, and `Number('message')` is
 * `NaN`. Without that guard the batch's own sentence would render inside row 0.
 */
export function fileIssues(errors: FieldErrors<{ files: File[] }>): ReadonlyMap<number, string> {
  const issues = new Map<number, string>();
  const files: unknown = errors.files;
  if (files === null || typeof files !== 'object') return issues;

  for (const [key, node] of Object.entries(files)) {
    const index = Number(key);
    if (!Number.isInteger(index) || index < 0) continue;
    const message = messageOf(node);
    if (message !== undefined) issues.set(index, message);
  }

  return issues;
}
