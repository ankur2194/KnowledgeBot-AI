/**
 * The upload form, and the per-organization limits it is a function of — which are a PUBLISHED shape
 * now, defined once in `src/resources/sources.ts` and re-exported below rather than declared here.
 * §8.10 makes maximum file size and the accepted media types CONFIGURABLE, so there is no byte
 * constant and no MIME constant anywhere in apps/web or packages/contracts: the schema below is a
 * FACTORY over that DTO, and a hard-coded ceiling enforces one organization's limits on every other.
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
 * WHAT IS STILL NOT ASSERTED, said out loud rather than left to be rediscovered: NOTHING COMPARES
 * THIS SCHEMA TO LARAVEL'S `rules()`, and that is now a decision rather than a gap.
 *
 * The sentence here used to end "until `packages/contracts/rules/StoreSourceRequest.json` lands". It
 * landed — and `uploadSchema` did NOT become a `MIRRORS` entry, because the manifest turned out to
 * describe a THREE-ARM body (`file` / `url` / `text`, discriminated by `type`) of which this schema
 * covers one arm: eleven validated paths against this object's two, a per-file cap the FormRequest
 * states as a platform constant in KILOBYTES against a per-organization cap this factory takes in
 * BYTES, and a `files.*` rule (`file`) no JSON probe can synthesize at all. `StoreSourceRequest` is
 * therefore `NO_CLIENT_FORM`, recorded as OWED with the measurement and with what closes it —
 * the upload intake, the `OrgUploadLimits` endpoint that must publish the server's own constants, and
 * a create form covering all three arms. Read that entry before writing a mirror; the argument is
 * there rather than here because that is the file the assertion lives in.
 *
 * Until then, drift against the server is a reviewer check on this file. (A CI job used to run over
 * packages/, and only over packages/design-tokens/generated — nothing in it read this file — and it
 * is gone regardless.)
 *
 * ONE OF THOSE THREE CONDITIONS HAS SINCE BEEN MET, and saying which keeps the paragraph above from
 * reading as still-owed in full: the intake landed and `GET .../sources/upload-limits` publishes the
 * server's own constants as `OrgUploadLimitsResource`. `StoreSourceRequest` stays `NO_CLIENT_FORM`
 * as OWED, because the third condition — a create form covering all three arms — is what the
 * eleven-path measurement is actually about, and it does not exist.
 */

import { z } from 'zod';

import type { OrgUploadLimits } from '../resources/sources.js';

/**
 * THE INTERFACE MOVED TO `src/resources/sources.ts` AND THIS IS THE ONLY TYPE-LEVEL TRACE OF IT.
 *
 * Not a rename and not a second declaration: a published component may have exactly one mirror in
 * this package, and `test/resource-drift.test.ts` now holds `OrgUploadLimitsResource` in `MIRRORED`
 * with the reasoning. What used to be an interface hand-written HERE — as the parameter of the
 * factory below, invented by the client because no endpoint returned it — is the server's shape now,
 * so it lives beside every other mirrored response and is pinned the same three ways.
 *
 * THE RE-EXPORT IS THE POINT AND NOT AN AFTERTHOUGHT. `uploadSchema`'s signature is public API inside
 * this monorepo and apps/web already imports the parameter type from `@kb/contracts/forms` in both
 * source and test — `rg 'OrgUploadLimits' apps/web` is the list, and it is a list rather than a
 * number here on purpose — so the door stays where callers already knock rather than a rename being
 * charged to an app this package cannot edit. `export type` is erased under `verbatimModuleSyntax`,
 * so `@kb/contracts/forms` gains nothing at runtime and the root entry's <=1 kB budget never sees it.
 */
export type { OrgUploadLimits } from '../resources/sources.js';

/**
 * `.mime()` reads `File.type`, which the browser derives from the EXTENSION on most platforms and
 * which any caller can forge. It filters the picker and produces a fast message; the server's
 * extension allow-list, libmagic sniffing independent of filename, compression-ratio caps and
 * malware hook are the control (kb-security-baseline). A .pdf that is really a ZIP passes every
 * check here and must still be rejected server-side, so this form renders server field errors like
 * any other.
 *
 * `.mime(limits.allowed_mime)` IS ALSO NARROWER THAN THE SERVER'S ADMISSION RULE, and that gap is
 * now measurable rather than theoretical. The intake admits a part only if the sniffed type is on
 * this list AND the final extension is on a SECOND allow-list AND the two agree; the published shape
 * carries three fields and no `allowed_extensions`, so this schema — and any `accept=` built from the
 * same list — OVER-ACCEPTS wherever the two sets are not in bijection. `.md` and `.csv` both sniff as
 * `text/plain`, so a picker offering `text/plain` offers a `.txt` the extension step refuses. The
 * refusal arrives as a 422 keyed on the part and is the only place it can be seen. Render it; do not
 * guess the extension list here, and do not tell the user the picker's filter is what will be
 * accepted. See `OrgUploadLimits` in `src/resources/sources.ts` for the full statement.
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
