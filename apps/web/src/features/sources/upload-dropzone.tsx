'use client';

import type { OrgUploadLimits } from '@kb/contracts/forms';
import { UploadCloudIcon } from 'lucide-react';
import { useId, useState } from 'react';

import { cn } from '@/lib/utils';

/**
 * The drop target, and the file input that makes it usable without a pointer.
 *
 * ── BOTH, ALWAYS. A DROPZONE WITH NO KEYBOARD-REACHABLE INPUT IS NOT A CONTROL ──────────────────
 * Drag-and-drop is a pointer gesture with a precise path, which kb-ui-accessibility rules out as
 * the ONLY way to do anything. So the real `<input type="file">` is not hidden behind a button and
 * not `display: none` — it is stretched over the whole zone at `opacity-0`. Three properties fall
 * out of that one decision and none of them needs a line of JavaScript:
 *
 *   1. TAB REACHES IT, and the global `:focus-visible` outline in globals.css paints around the
 *      input's box — which IS the zone's box, because the input fills it. One focus ring, from the
 *      one rule, with no per-component re-implementation and no `outline-none` to replace.
 *   2. CLICK ANYWHERE OPENS THE PICKER, natively. No `ref.current.click()`, which is the usual
 *      shape and the one that breaks the moment the visible element is not a `<label>`.
 *   3. ENTER AND SPACE OPEN THE PICKER, because that is what a focused file input does. A
 *      `div[role="button"]` with `tabindex="0"` scans clean in axe and does none of this.
 *
 * The visible `<label>` is bound with `htmlFor`, so the control has a VISIBLE bound label rather
 * than a placeholder or an `aria-label` nobody can see.
 *
 * ── `Array.from(event.dataTransfer.files)`, NOT THE `FileList` ──────────────────────────────────
 * `uploadSchema`'s field is `z.array(z.file())`. `FileList` is a DOM type absent from Node and
 * `z.instanceof(FileList)` evaluates at module load, so a schema written against it crashes any
 * server component or node-environment test that imports it (rhf-zod-forms). Both entry points here
 * convert immediately and nothing downstream ever sees a `FileList`.
 */

export function UploadDropzone({
  limits,
  selectedCount,
  onFilesChosen,
  disabled = false,
  invalidMessageId,
  className,
}: {
  /** Per-organization, from the bootstrap config. THE ONLY SOURCE of the accept list and the batch
   *  cap: §8.10 makes both configurable, so neither may be a constant in this app. */
  readonly limits: OrgUploadLimits;
  /** How many rows the list already holds, so the cap is on the BATCH rather than on one drop. */
  readonly selectedCount: number;
  readonly onFilesChosen: (files: readonly File[]) => void;
  readonly disabled?: boolean;
  /**
   * The id of the element rendering the BATCH-level form error, when there is one.
   *
   * THE FILE INPUT IS THE `files` FIELD'S CONTROL, so a message about the batch as a whole — "choose
   * at least one file", "this organization accepts at most 3 at a time" — has to be bound to it or
   * it is an error that is only red (kb-ui-accessibility, *Live regions*). Passing an id rather than
   * the text keeps the message's PRESENTATION with the caller, which owns whether it came from the
   * resolver or from a 422, while the two ARIA attributes that make it an error state live here with
   * the control they describe.
   *
   * PER-FILE messages are NOT bound here: those belong to `files.<index>`, are rendered in the row
   * they are about, and pointing this input at five of them would announce the whole batch's
   * problems on one control.
   */
  readonly invalidMessageId?: string;
  readonly className?: string;
}) {
  const inputId = useId();
  const capId = useId();
  const [dragging, setDragging] = useState(false);
  const [overflowed, setOverflowed] = useState(0);

  const remaining = Math.max(0, limits.max_batch - selectedCount);
  const full = remaining === 0;
  const inert = disabled || full;

  /**
   * The one path in. Trims to the remaining capacity and REPORTS the trim rather than silently
   * dropping the tail — a picker that accepts twenty files and uploads five, saying nothing, is the
   * complaint nobody can reproduce because the browser dialog is gone by the time they notice.
   */
  const choose = (files: readonly File[]): void => {
    if (files.length === 0) return;
    const accepted = files.slice(0, remaining);
    setOverflowed(files.length - accepted.length);
    if (accepted.length > 0) onFilesChosen(accepted);
  };

  return (
    <div className={cn('flex flex-col gap-2', className)}>
      <div
        // `data-*` rather than a conditional class string, so the dragging and full states are
        // readable from the DOM in a spec and stay out of the className soup.
        data-dragging={dragging}
        data-full={full}
        onDragEnter={(event) => {
          event.preventDefault();
          if (!inert) setDragging(true);
        }}
        onDragOver={(event) => {
          // MANDATORY, and its absence is the classic "the browser navigated to the file instead of
          // dropping it": without preventDefault on dragover the element is not a drop target at
          // all, `onDrop` never fires, and the default action opens the file in the tab — which on
          // this screen throws away everything already queued.
          event.preventDefault();
          if (!inert) event.dataTransfer.dropEffect = 'copy';
        }}
        onDragLeave={(event) => {
          // `currentTarget.contains(relatedTarget)` guards the flicker: dragging across a child
          // fires `dragleave` on the parent, so a naive handler turns the highlight off and on
          // several times per second while the pointer is still inside the zone.
          if (event.currentTarget.contains(event.relatedTarget as Node | null)) return;
          setDragging(false);
        }}
        onDrop={(event) => {
          // preventDefault during BUBBLING still cancels the default action, and the default here is
          // the file input's own drop handling — which would apply neither the batch cap nor the
          // trim message below.
          event.preventDefault();
          setDragging(false);
          if (inert) return;
          choose(Array.from(event.dataTransfer.files));
        }}
        className={cn(
          'relative flex flex-col items-center gap-2 rounded-2xl border-2 border-dashed p-card-pad-md text-center',
          // Colour is a token transition on a small-ish surface at --dur-1, which is the sanctioned
          // exception to transform-and-opacity-only (kb-motion-and-effects rule 2).
          'transition-colors duration-(--dur-1) ease-out',
          'border-border bg-card-inset',
          // Hover is MEDIA-GATED by Tailwind v4 and therefore never fires on touch; the drag state
          // and the pressed state below are what a touch user actually gets.
          'hover:border-border-strong',
          // E9: the pressed state is mandatory, not a nicety.
          'has-[input:active]:bg-primary-soft',
          'data-[dragging=true]:border-primary data-[dragging=true]:bg-primary-soft',
          'data-[full=true]:opacity-60',
        )}
      >
        <UploadCloudIcon aria-hidden className="size-6 text-muted-foreground" strokeWidth={1.5} />

        <label htmlFor={inputId} className="text-base font-medium">
          Add files to this organization
        </label>

        <p id={capId} className="text-sm text-muted-foreground">
          {full
            ? `This batch is full at ${limits.max_batch} files. Upload or remove some before adding more.`
            : `Drag files here, or choose them. ${remaining} more can be added to this batch.`}
        </p>

        <input
          id={inputId}
          type="file"
          multiple
          // BUILT FROM THE ORGANIZATION'S LIST, never from a constant. It filters the picker and
          // nothing else: `accept` is not enforced on a drop in every engine, `File.type` is
          // forgeable, and the server's sniffing is the control (kb-security-baseline).
          accept={limits.allowed_mime.join(',')}
          disabled={inert}
          // The cap sentence ALWAYS, the error only when there is one — in that order, so a screen
          // reader hears what the control is before it hears what is wrong with it.
          aria-describedby={invalidMessageId === undefined ? capId : `${capId} ${invalidMessageId}`}
          // `undefined`, not `false`, when there is nothing wrong: React omits the attribute
          // entirely for `undefined` and RENDERS `aria-invalid="false"` for `false`. Both are
          // spec-legal, and the second is a control permanently announcing a validity state it was
          // never asked about — noise in the a11y tree, and a spec asserting "no error is bound"
          // that can only ever check a string.
          aria-invalid={invalidMessageId === undefined ? undefined : true}
          onChange={(event) => {
            choose(Array.from(event.target.files ?? []));
            // The input is CLEARED after every read. Without it, choosing the same file twice in a
            // row fires no `change` event at all — the value did not change — and the second attempt
            // does nothing with no error anywhere.
            event.target.value = '';
          }}
          className={cn(
            // Stretched over the zone rather than `sr-only`: see the module docblock. `opacity-0`
            // and not `visibility: hidden`, because a visibility-hidden control is not focusable.
            'absolute inset-0 h-full w-full cursor-pointer opacity-0',
            'disabled:cursor-not-allowed',
          )}
        />
      </div>

      {overflowed > 0 ? (
        // `role="status"`, not `role="alert"`: nothing is broken and the user has to do nothing.
        // It is announced politely because the visual change — a shorter list than the dialog
        // offered — is invisible to a screen-reader user who never saw the dialog.
        <p role="status" className="text-sm text-warning-soft-foreground">
          {overflowed} {overflowed === 1 ? 'file was' : 'files were'} left out: this batch holds at
          most {limits.max_batch}.
        </p>
      ) : null}
    </div>
  );
}
