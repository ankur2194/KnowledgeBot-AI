import { uploadSchema, type OrgUploadLimits } from '@kb/contracts/forms';

import { sessionCredential } from '@/lib/api/browser';
import { uploadFile, type UploadProgress } from '@/lib/api/upload';

/**
 * The source-upload transport, one file per request. REACT-FREE on purpose — nothing here imports a
 * hook, so a spec can call the fetcher directly and a render site cannot acquire a second copy of
 * the envelope knowledge.
 *
 * ── THE ENDPOINT DOES NOT EXIST YET, AND THAT IS REPORTED RATHER THAN GUESSED AROUND ────────────
 * `POST …/sources` is the shape this plumbing is built against; the controller, the FormRequest and
 * its dumped manifest (`packages/contracts/rules/StoreSourceRequest.json`) are owed by the control
 * plane. Two consequences are deliberate and visible below:
 *
 *   1. NO HAND-WRITTEN RESOURCE TYPE. `uploadSourceFile` resolves `void`. There is no
 *      `SourceResource` in `@kb/contracts` yet, and declaring one here would start the exact chain
 *      this codebase has already paid for once — fixture -> hand-written type -> PHP resource, with
 *      no assertion at any step. `uploadFile<T>` is generic and is waiting; the row a screen renders
 *      after an upload comes from re-reading the list, which is the server's answer rather than
 *      ours.
 *   2. NO MANIFEST, SO `UPLOAD_KNOWN_PATHS` IS DERIVED FROM THE SHARED SCHEMA rather than typed out
 *      — see its docblock.
 *
 * ── THE ORGANIZATION IS IN THE PATH, AND THAT IS NOT A TENANCY VIOLATION ────────────────────────
 * Every route under `organizations/{organization}` uses `->scopeBindings()`, and `TenantContext`
 * re-reads the membership row from PostgreSQL on EVERY request. The segment is a ROUTING HINT, never
 * a scope: Laravel derives the real organization from the session and would ignore a client-supplied
 * one. `encodeURIComponent` on a ULID is a no-op today and is what keeps the day it stops being a
 * ULID from being an injected path segment.
 */

export const sourcesPath = (orgId: string): string =>
  `/api/v1/organizations/${encodeURIComponent(orgId)}/sources`;

/**
 * THE MULTIPART PART NAME, AND IT IS INDEXED EVEN THOUGH EACH REQUEST CARRIES ONE FILE.
 *
 * `files[0]`, not `file`. One file per request is a CLIENT decision — it is what makes per-file
 * progress and per-file failure natural instead of something a caller has to demultiplex out of one
 * 422 — and the server should not have to know about it. Sending the array shape means the
 * FormRequest declares `files` and `files.*` whether it receives one part or five, and its 422 comes
 * back keyed `files.0`: a path `applyServerErrors` folds to `files.*`, matches against the form's
 * own vocabulary, and writes onto an RHF array path.
 *
 * The index on the WIRE is always 0, because the batch on the wire is always one file. The index in
 * FORM STATE is the row the user is looking at. `reindexFileErrors` below is the one line that
 * connects them, and it is the whole reason a per-file rejection lands on its own row rather than
 * on row 0 five times.
 */
const FILE_PART = 'files[0]';

export interface UploadOneOptions {
  /** Wired to `xhr.abort()` — a per-file Cancel, or the batch's controller aborting all of them. */
  readonly signal: AbortSignal;
  readonly on_progress: (progress: UploadProgress) => void;
  /** Present only once the endpoint honours one; absent means this must never be retried. */
  readonly idempotency_key?: string;
}

/**
 * `POST …/sources` (multipart) -> 201 `{data: …}` | 422 | 403 | 413 | 429.
 *
 * Resolves `void` — see the module docblock. Rejects with the `KbError` from `@kb/contracts`, or
 * with the signal's `AbortError` when the user cancelled (`lib/api/upload.ts`).
 *
 * NO `Idempotency-Key` IS SENT BY DEFAULT, so this must never be retried by anything — including a
 * double-click, which is what the disabled Upload button is for. A replayed create stores the file
 * twice under two source ids, and the second one is invisible until somebody wonders why the index
 * answers the same passage twice. `lib/query/client.ts` is not in this path at all: the upload does
 * not go through a mutation, so there is no retry policy to inherit and no cache to write.
 */
export const uploadSourceFile = async (
  orgId: string,
  file: File,
  options: UploadOneOptions,
): Promise<void> => {
  await uploadFile<unknown>({
    path: sourcesPath(orgId),
    method: 'POST',
    file,
    field: FILE_PART,
    // NO scalar fields. `fields` is an explicit allow-list a caller types out (lib/api/upload.ts)
    // and this caller has nothing to put in it: the organization is the session's, the source kind
    // is the server's to sniff, and an ownership column here would be a tenant key a caller can set.
    credential: await sessionCredential(),
    signal: options.signal,
    on_progress: options.on_progress,
    ...(options.idempotency_key === undefined
      ? {}
      : { idempotency_key: options.idempotency_key }),
  });
};

/**
 * The paths this form RENDERS, which is what `knownPaths` means — a DIFFERENT set from "the paths
 * the FormRequest validates" (`lib/forms/known-paths.ts`).
 *
 * DERIVED FROM THE SHARED SCHEMA, NOT TYPED OUT. Every other screen derives it from the dumped
 * manifest through `knownPathsFromRules`, and there is no manifest for this endpoint yet — so
 * rather than hand-type a replacement for the derivation (which that module forbids in as many
 * words), this reads the one artifact that does exist and is shared with the server's own probes:
 * `uploadSchema`'s object shape. When `StoreSourceRequest.json` lands, this becomes
 * `knownPathsFromRules(manifest)` and the wildcard below stops being ours to state.
 *
 * `files.*` IS THE ENTRY THAT MATTERS. Laravel keys array errors positionally (`files.0`) while a
 * rule set keys them with a wildcard (`files.*`), and `applyServerErrors` folds `.\d+` to `.*` for
 * the MEMBERSHIP TEST ONLY — the path it hands `setError` stays positional, because that is the row
 * the user is looking at. Without `files.*` here, every per-file message is an orphan and lands in
 * one batch banner.
 *
 * The limits are ARBITRARY: only the object's key set is read, and `.max()`/`.mime()` are
 * refinements that add no key. Passing a real `OrgUploadLimits` would suggest the vocabulary depends
 * on the organization, and it does not.
 */
const SHAPE_PROBE: OrgUploadLimits = { max_bytes: 1, allowed_mime: [], max_batch: 1 };

export const UPLOAD_KNOWN_PATHS: readonly string[] = Object.keys(
  uploadSchema(SHAPE_PROBE).shape,
).flatMap((key) => [key, `${key}.*`]);

/**
 * A per-file 422's `errors` map, re-keyed from the WIRE's index onto the ROW's index.
 *
 * Every request sends its file as `files[0]` (see `FILE_PART`), so every per-file rejection comes
 * back keyed `files.0` regardless of which row it belongs to. Handing that straight to
 * `applyServerErrors` writes five different files' messages onto row 0, one overwriting the next,
 * while the four rows that actually failed stay clean — a batch where the user is told about one
 * problem, fixes it, and is told about the same kind of problem again.
 *
 * Only the numeric segment moves. A key with no index (`files`, or a rule on some other field the
 * server validated) is passed through untouched: `files` is a real path this form renders (the
 * batch cap and the "at least one file" rule live there) and anything else is an orphan that
 * `applyServerErrors` routes to `root.serverError`, which is correct — a message the form cannot
 * display anywhere is the one failure mode that reads as "clicking Save does nothing".
 */
export function reindexFileErrors(
  errors: Readonly<Record<string, readonly string[]>>,
  index: number,
): Record<string, readonly string[]> {
  const reindexed: Record<string, readonly string[]> = {};

  for (const [key, messages] of Object.entries(errors)) {
    // `files.0.something` keeps its tail, which is why the replacement is anchored on the leading
    // segment pair rather than on the whole key: a nested per-file rule (`files.0.name`) is a shape
    // Laravel can emit and dropping its tail would produce a path no control is registered under.
    reindexed[key.replace(/^files\.\d+/, `files.${index}`)] = messages;
  }

  return reindexed;
}
