import { z } from 'zod';

/**
 * Per-organization upload limits, returned by the bootstrap/config endpoint. §8.10 makes maximum
 * file size and per-organization storage limits CONFIGURABLE, so there must be no byte constant
 * and no MIME constant anywhere in apps/web or packages/contracts — the schema below is a FACTORY
 * over this DTO. Hard-code either one and the form silently enforces a different limit from the
 * organization it is rendering for, which reads as a rejected upload nobody can explain.
 *
 * NOTHING ASSERTS THIS. test/form-drift.test.ts does not import `uploadSchema` at all (it covers
 * the manifest count and the ownership keys). gates.yml's `repo-artifact-consistency` job does run
 * over packages/, but only over packages/design-tokens/generated — nothing there reads this file.
 * It is a reviewer check until the drift suite gains an upload case that parses the same File
 * against two different `OrgUploadLimits` and expects opposite results.
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
