import { z } from 'zod';

/**
 * Per-organization upload limits, returned by the bootstrap/config endpoint. §8.10 makes maximum
 * file size and per-organization storage limits CONFIGURABLE, so there must be no byte constant
 * and no MIME constant anywhere in apps/web or packages/contracts — the schema below is a FACTORY
 * over this DTO. Hard-code either one and the form silently enforces a different limit from the
 * organization it is rendering for, which reads as a rejected upload nobody can explain.
 *
 * THIS IS ASSERTED NOW, AND THE SENTENCE THAT USED TO BE HERE WAS WRONG TWICE OVER. It read
 * "test/form-drift.test.ts does not import `uploadSchema` at all", which went false the day the
 * ownership loop stopped being a hand-written pair of names and started iterating `MIRRORS` plus
 * this factory; and it went on to name the case that would close the real property — one `File`
 * parsed against two different `OrgUploadLimits`, expecting opposite results. That case exists:
 * `describe('the upload form: the limits are the organization's, not this package's')`. It holds
 * the file fixed and varies the DTO on size, on the size BOUNDARY, on MIME and on the batch cap,
 * with the positive control first each time — because a factory quietly collapsed into a fixed
 * schema keeps every other test in this file green while enforcing somebody else's limits.
 *
 * WHAT IS STILL NOT ASSERTED, said out loud rather than left to be rediscovered: there is no dumped
 * manifest for this endpoint yet, so `uploadSchema` appears in neither `MIRRORS` nor
 * `NO_CLIENT_FORM` and NOTHING compares it to Laravel's own `rules()`. Drift against the server is
 * a reviewer check until `packages/contracts/rules/StoreSourceRequest.json` lands. (A CI job used to
 * run over packages/, and only over packages/design-tokens/generated — nothing in it read this file
 * — and it is gone regardless.)
 */
export interface OrgUploadLimits {
  readonly max_bytes: number;
  readonly allowed_mime: readonly string[];
  readonly max_batch: number;
}

/**
 * `.mime()` reads `File.type`, which the browser derives from the EXTENSION on most platforms and
 * which any caller can forge. It filters the picker and produces a fast message; the server's
 * extension allow-list, libmagic sniffing independent of filename, compression-ratio caps and
 * malware hook are the control (kb-security-baseline). A .pdf that is really a ZIP passes every
 * check here and must still be rejected server-side, so this form renders server field errors like
 * any other.
 *
 * `z.file()` over `z.instanceof(FileList)`: `FileList` is a DOM type absent from Node, and
 * `z.instanceof` evaluates at module load — importing this file from a server component or a
 * node-environment test would crash it outright.
 */
export const uploadSchema = (limits: OrgUploadLimits) =>
  z.strictObject({
    files: z
      .array(
        z
          .file()
          .max(limits.max_bytes)
          .mime([...limits.allowed_mime]),
      )
      .min(1)
      .max(limits.max_batch),
  });

export type UploadSchema = ReturnType<typeof uploadSchema>;
export type UploadIn = z.input<UploadSchema>;
export type UploadOut = z.output<UploadSchema>;

/**
 * An empty upload form. The only defaults factory in this directory that takes NO argument and
 * mirrors NO server row, and both halves are the point.
 *
 * NO ARGUMENT, because there is nothing to seed from: an upload creates, so there is no resource in
 * scope, and unlike `botDomainCreateDefaults()` there is not even a string to blank out. It exists
 * anyway, rather than being a `{ files: [] }` literal at the call site, for the reason every sibling
 * exists: the ONE path from "nothing chosen yet" into form state runs through this module, so a
 * caller cannot reach for `reset(someServerObject)` and there is exactly one place to change when
 * the form grows a second field.
 *
 * NOT `UploadIn`, and that is not a shortcut. `UploadIn` is `z.input<UploadSchema>` and
 * `UploadSchema` is `ReturnType<typeof uploadSchema>` — the return of the FACTORY, which TypeScript
 * resolves against one anonymous instantiation. The field type is identical for every
 * `OrgUploadLimits` (a `File[]`; `.max()`/`.mime()` are refinements and change no type), so the
 * annotation below says the same thing without pretending this value belongs to one organization's
 * limits. It does not: an empty list is valid input to every instantiation and is refused by all of
 * them, because `.min(1)` is the whole reason "no files chosen" is not a submittable form.
 *
 * `[]` IS DELIBERATELY NOT A SCHEMA-VALID VALUE, which makes this the one defaults factory whose
 * output fails its own schema — see the `.min(1)` above. That is correct: the Upload button is
 * disabled until the user picks something, and RHF's `isValid` is what disables it. A default that
 * parsed would mean an empty batch is postable.
 *
 * A FRESH ARRAY PER CALL, never a shared frozen constant. RHF takes ownership of `defaultValues`
 * and a module-level `[]` handed to two mounted forms is one array behind two file lists.
 */
export const uploadDefaults = (): { files: File[] } => ({ files: [] });
