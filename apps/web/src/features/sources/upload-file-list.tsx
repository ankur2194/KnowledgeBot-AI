'use client';

import { FilePlusIcon } from 'lucide-react';
import { useEffect, useRef } from 'react';

import { EmptyState } from '@/components/states';
import { Alert, AlertDescription } from '@/components/ui/alert';

import { UploadFileRow } from './upload-file-row';
import type { FileUploads } from './use-file-uploads';

/**
 * The chosen files, one row each, plus the two things a batch needs that no row can carry: the
 * receipt, and the announcement.
 *
 * ── THE FOUR STATES, ON A SURFACE THAT FETCHES NOTHING ──────────────────────────────────────────
 * This list renders LOCAL state, so three of the four land differently from a fetched surface and
 * the fourth does not exist. Said out loud rather than left for a reviewer to wonder about:
 *
 *   Loading   — per row, and it is the point of the whole component: `queued` -> `uploading` (a
 *               determinate bar, or an indeterminate one when the browser reports no total) ->
 *               `finishing`. There is no first-load skeleton, because there is no first load.
 *   Empty     — FIRST-RUN only, and it is `<EmptyState>` with the create affordance pointing at the
 *               dropzone above. There is no FILTERED empty: nothing here is filtered, and adding a
 *               "Clear filters" action to a list the user assembled by hand would be the exact
 *               conflation states.md splits the two empties to prevent.
 *   Error     — PER ROW, never per batch (see the partial-success note below). The batch-level slot
 *               below it is for a failure that belongs to no single file.
 *   Forbidden — NOT RENDERED HERE, and that is not an omission. A 403 on the upload endpoint is a
 *               per-file `authorization` error and renders in the row through the class-mapped
 *               sentence; the SCREEN is what refuses to show an upload surface at all to a member
 *               without the role, because a control a user may not use is HIDDEN rather than
 *               disabled (states.md). That screen is `upload-screen.tsx`, and it does exactly that.
 *
 * ── PARTIAL SUCCESS IS THE DEFAULT READING, NOT AN EDGE CASE ───────────────────────────────────
 * Eight of ten uploaded is eight successes and two failures with their own reasons, never one red
 * banner and never one green one. The receipt below counts what actually landed and the failures
 * stay on their rows with a scoped retry.
 */

export function UploadFileList({
  uploads,
  issues,
}: {
  readonly uploads: FileUploads;
  /**
   * ROW INDEX -> the message in that row's `files.<index>` form slot, for the rows that have one.
   *
   * A `Map` rather than an array or a record keyed by index: the caller builds it from
   * `formState.errors.files`, which is sparse, and a Map says "most rows have nothing" without a
   * hole-riddled array or a computed member read at every row (the object-injection shape this repo
   * lints for). Absent entirely on a list with no form behind it — `tests/components/
   * upload-dropzone.test.tsx` mounts exactly that, because a screen with no form is not a screen
   * with no error reporting.
   */
  readonly issues?: ReadonlyMap<number, string>;
}) {
  const { items, succeeded, failed, isPending } = uploads;

  /**
   * ── REMOVING A ROW MUST NOT DROP FOCUS TO `<body>` (WCAG 2.4.3, 3.2.1) ────────────────────────
   *
   * The Remove control lives INSIDE the row it removes, so activating it destroys the focused
   * element. The browser's fallback is `<body>`, and for a keyboard user that is not a small
   * annoyance: the next Tab restarts from the top of the document, so removing the fourth of five
   * files costs a walk back through the whole shell — sidebar, org badge, sign-out, theme toggle,
   * dropzone — to reach the fifth. A screen reader announces nothing at all, because focus did not
   * land on anything.
   *
   * `tests/e2e/admin/source-upload.spec.ts` predicted this in writing before it had ever run
   * ("the fix is in the app: move focus to the next row or to the drop zone before removing") and
   * the first real Playwright run, on 2026-08-24, failed on exactly that assertion.
   *
   * WHY AN EFFECT AND NOT A `.focus()` IN THE HANDLER. The successor row's control is the same DOM
   * node it already was, but React has not re-rendered when the click handler returns — and the
   * fallback case (the last row removed) unmounts this component entirely. Focusing after the
   * commit is the only ordering that works for both.
   *
   * `''` IS A THIRD STATE, NOT AN EMPTY ID. `null` means "no removal is pending"; `''` means "a
   * removal happened and there is no surviving row", which sends focus to the picker. Collapsing
   * the two would make the last removal leave focus on `<body>` — the bug this exists to fix,
   * surviving in the one case it is most visible.
   */
  const listRef = useRef<HTMLUListElement>(null);
  const focusAfterRemoval = useRef<string | null>(null);

  useEffect(() => {
    const target = focusAfterRemoval.current;
    if (target === null) return;
    focusAfterRemoval.current = null;

    const successor =
      target === ''
        ? null
        : (listRef.current?.querySelector<HTMLElement>(`[data-row-action="${target}"]`) ?? null);

    // THE PICKER IS THE FALLBACK, AND IT IS FOUND BY QUERY RATHER THAN BY A THREADED REF. The
    // dropzone generates its input id with `useId()`, so there is no stable id to pass down, and
    // there is exactly one `input[type="file"]` on this screen. A list mounted with no dropzone
    // above it — which `tests/components/upload-dropzone.test.tsx` does deliberately — finds
    // nothing and focuses nothing, which is correct rather than a silent failure: there is no
    // control to move to.
    (successor ?? document.querySelector<HTMLElement>('input[type="file"]'))?.focus();
  }, [items]);

  /**
   * Remove, having first written down where focus should land.
   *
   * The successor is the NEXT row, falling back to the PREVIOUS one — the ordering a list widget
   * uses everywhere, and the one that keeps a user deleting several files in a row deleting from
   * the same spot instead of walking backwards.
   */
  const removeAndKeepFocus = (id: string): void => {
    const index = items.findIndex((item) => item.id === id);
    const successor = items[index + 1] ?? items[index - 1];
    focusAfterRemoval.current = successor?.id ?? '';
    uploads.remove(id);
  };

  if (items.length === 0) {
    return (
      <EmptyState
        glyph={FilePlusIcon}
        title="No files chosen yet"
        body="Drag files onto the area above, or use Add files to this organization to pick them. Each one becomes a source your bots can answer from."
      />
    );
  }

  return (
    <div className="flex flex-col gap-3">
      {/* ── THE ANNOUNCEMENT ────────────────────────────────────────────────────────────────────
          `sr-only role="status"` — a polite live region carrying STATE TRANSITIONS, never the
          progress stream. A region wired to the percentage would announce on every progress event,
          which kb-ui-accessibility names as a reason to stop using the product; this changes only
          when a count changes, so a batch of five announces at most a handful of times.

          It is also the only channel that tells a screen-reader user the batch is done: the visual
          signal is a row of pills changing colour. */}
      <p role="status" aria-live="polite" className="sr-only">
        {isPending
          ? `Uploading. ${succeeded} of ${items.length} finished.`
          : `${succeeded} of ${items.length} uploaded${failed > 0 ? `, ${failed} failed` : ''}.`}
      </p>

      {/* ── THE RECEIPT ─────────────────────────────────────────────────────────────────────────
          An INLINE banner, not a toast, and the absence of a toast library is deliberate rather
          than a gap: `sonner` was considered and refused as a real new dependency. It is also the
          right surface on the merits — an outcome an operator may want to re-read, beside the rows
          it is counting, reachable by keyboard after it appears (P12).

          `aria-live="polite"` OVERRIDES `<Alert>`'s `role="alert"`, whose implicit politeness is
          ASSERTIVE. A finished upload interrupting whatever the user was reading is the wrong
          urgency for a thing that needs no decision. */}
      {!isPending && succeeded > 0 ? (
        <Alert variant="success" aria-live="polite">
          <AlertDescription>
            {succeeded === 1 ? '1 file was uploaded' : `${succeeded} files were uploaded`}
            {failed > 0
              ? `, and ${failed === 1 ? '1 did not' : `${failed} did not`}. The rows below say why.`
              : '. They will appear in the sources list once they finish indexing.'}
          </AlertDescription>
        </Alert>
      ) : null}

      {/* `<ul>`, so the count is announced and the rows are navigable as a list. A stack of divs
          reads as one run-on paragraph. */}
      <ul ref={listRef} className="rounded-xl bg-card shadow-hairline">
        {items.map((item, index) => (
          <UploadFileRow
            key={item.id}
            item={item}
            // THE ROW'S POSITION IS THE FORM PATH. `files.<index>` is where `applyServerErrors`
            // writes and where the resolver's per-file issues land, and the index is read HERE — at
            // render time — rather than captured when the row was added, because removing a row
            // renumbers every row after it.
            issue={issues?.get(index)}
            onCancel={uploads.cancel}
            onRemove={removeAndKeepFocus}
            onRetry={uploads.requeue}
          />
        ))}
      </ul>
    </div>
  );
}
