'use client';

import { KbError } from '@kb/contracts';
import { useCallback, useEffect, useRef, useState } from 'react';

import type { UploadProgress } from '@/lib/api/upload';

/**
 * The per-file state machine behind the dropzone, and the one place `uploadFile` is actually driven.
 *
 * ── PER-FILE STATE, NEVER ONE STATE FOR THE BATCH ───────────────────────────────────────────────
 * A batch of ten where two fail is a PARTIAL SUCCESS, and a partial rendered as a failure loses
 * eight files of real work while a partial rendered as a success is a data-loss bug the user finds
 * out about later (kb-ui-patterns -> references/states.md). One phase, one progress reading and one
 * error PER FILE is what makes that renderable at all; a single `isUploading` boolean cannot express
 * it, and neither can a single error slot.
 *
 * ── THIS DOES NOT GO THROUGH TanStack Query, AND THAT IS DELIBERATE ─────────────────────────────
 * No `useMutation`, no cache write, and no optimistic pre-mutation callback — the one TanStack Query
 * fires before `mutationFn` so a caller can write a speculative row and roll it back. The grep for
 * that callback's name over `apps/web/src` returns zero, and that is doctrine rather than an
 * accident, which is also why this sentence describes it instead of spelling it: a comment naming
 * it would be the only hit the grep ever finds. In-flight feedback is the item's own phase plus
 * `aria-busy` plus an `sr-only role="status"` line in the list. An upload carries no
 * `Idempotency-Key`, so it must never be retried by anything; staying off the mutation path means
 * there is no retry option to get wrong and no `Property[key.name='retry']` for ESLint to find.
 *
 * The list a completed upload eventually refreshes is invalidated by the SCREEN that owns it, from
 * the org-namespaced key it already holds. That screen is `upload-screen.tsx`: it invalidates the
 * `['org', orgId, 'sources']` PREFIX once the batch has settled with at least one success, and again
 * after a row is retried into one. It writes nothing into the cache — a 201 body is a `draft` or
 * `queued` snapshot, and the row a list shows has to be the server's answer.
 */

/**
 * ── `finishing` IS THE FIFTH PHASE AND IT IS NOT PADDING ────────────────────────────────────────
 * The last `upload.progress` event fires when the last byte leaves the machine, and the response
 * arrives after the server has sniffed, stored and enqueued the file — seconds later for a large
 * one. Without this phase the bar sits at 100% in the `uploading` state for that whole window,
 * which is precisely the "determinate bar parked at 100%" that kb-ui-patterns P13 calls worse than
 * a spinner. The row says `Finishing` and stops claiming to measure something.
 */
export type UploadPhase = 'queued' | 'uploading' | 'finishing' | 'done' | 'failed' | 'cancelled';

export interface UploadItem {
  /** Stable for the life of the row. NOT the array index: removing a row would renumber the rest
   *  and React would reuse the wrong DOM node for a different file's progress bar. */
  readonly id: string;
  readonly file: File;
  readonly phase: UploadPhase;
  /** Null until the first progress event. `total: null` inside it means indeterminate. */
  readonly progress: UploadProgress | null;
  /** This file's own failure. A sibling's failure never appears here. */
  readonly error: KbError | null;
}

/**
 * The transport, as a parameter rather than an import.
 *
 * It is a seam and it is a small one: `features/sources/api.ts` supplies the real
 * `uploadSourceFile` bound to an organization, and a component spec supplies a stub that emits
 * progress on a schedule it controls. The alternative — importing `uploadSourceFile` here and
 * intercepting the network — makes every render test depend on the service worker's timing for a
 * thing that is not what the test is about, and makes "did the bar move" unassertable without a
 * real multi-megabyte body.
 */
export type FileUploader = (
  file: File,
  options: { signal: AbortSignal; on_progress: (progress: UploadProgress) => void },
) => Promise<void>;

export interface UseFileUploadsOptions {
  readonly upload: FileUploader;
  /**
   * A per-file 422's field map, RAW — keyed exactly as the wire keyed it — plus the row's index.
   *
   * IT IS NOT RE-KEYED HERE, and that is the boundary this hook is keeping. `files[0]` is
   * `features/sources/api.ts`'s multipart convention, not a property of "uploading files", so a hook
   * that folded the wire's index onto the row's would be a hook that knows one caller's part names.
   * The index is supplied instead, and the consumer composes:
   *
   *   onFileRejected: (index, errors) =>
   *     applyServerErrors(form, UPLOAD_KNOWN_PATHS, reindexFileErrors(errors, index))
   *
   * That is the seam the upload FORM hangs off, and it is why the message lands on the
   * `files.<index>` control rather than on row 0 once per failure.
   *
   * OPTIONAL, because the ROW already renders the same messages from `item.error` — a screen with no
   * form is not a screen with no error reporting.
   */
  readonly onFileRejected?: (
    index: number,
    errors: Readonly<Record<string, readonly string[]>>,
  ) => void;
}

export interface FileUploads {
  readonly items: readonly UploadItem[];
  /** Appends. Choosing a second time never replaces the first choice — a file input's `change`
   *  event carries only what THAT dialog selected, and a picker that discards prior rows is the
   *  most-reported bug in every upload UI. */
  readonly add: (files: readonly File[]) => void;
  /** Removes a row that has not started, or one that has finished. Cancels first if it is running. */
  readonly remove: (id: string) => void;
  readonly cancel: (id: string) => void;
  /**
   * Starts every `queued` row.
   *
   * TAKES NO ARGUMENT, AND KEEPING IT THAT WAY IS DELIBERATE: it is handed straight to an `onClick`
   * in more than one place, so an optional first parameter would silently receive a `MouseEvent`.
   * The single-row form is internal and `requeue` is its only caller.
   *
   * A row leaves `queued` SYNCHRONOUSLY as its request is opened, so `isPending` is true before
   * this returns and a second press cannot re-send a batch that is already on the wire.
   */
  readonly start: () => void;
  /**
   * Puts one settled row back in the queue and starts it. The USER-INITIATED retry, and it is a
   * second create rather than a replay: this request carries no `Idempotency-Key`, so nothing may
   * repeat it automatically — not the query client (it is not on that path), not a backoff loop,
   * and not a double-click. The row's error is cleared first so the previous failure is not still
   * on screen beside a running bar.
   */
  readonly requeue: (id: string) => void;
  /** True while any row is `uploading` or `finishing`. Drives `aria-busy` and the disabled submit. */
  readonly isPending: boolean;
  readonly succeeded: number;
  readonly failed: number;
}

export function useFileUploads(options: UseFileUploadsOptions): FileUploads {
  const [items, setItems] = useState<readonly UploadItem[]>([]);

  /**
   * THE SAME LIST, HELD IN A REF, AND THE REASON IS A REACT 19 HAZARD RATHER THAN CONVENIENCE.
   *
   * `start()` has to read the current rows and then DO something (open a request per row). Reading
   * them the obvious way — inside a `setItems(current => …)` updater — puts a side effect in a
   * function React is explicitly allowed to call twice, and does call twice under StrictMode in
   * development: every file would be uploaded twice, on a path with no idempotency key, and the
   * second copy is invisible until somebody wonders why the index answers the same passage twice.
   *
   * So every write goes through `commit`, which updates the ref SYNCHRONOUSLY and then schedules the
   * render. Every reader — `start`, and the async settle handlers — reads the ref. Two `patch` calls
   * in one tick therefore compose instead of the second overwriting the first, which is the bug the
   * functional-updater form usually exists to prevent.
   */
  const itemsRef = useRef<readonly UploadItem[]>([]);

  const commit = useCallback((next: readonly UploadItem[]): void => {
    itemsRef.current = next;
    setItems(next);
  }, []);

  /** One controller per RUNNING row. A ref, not state: aborting must not schedule a render, and the
   *  identity has to survive the renders the phase changes cause. */
  const controllers = useRef(new Map<string, AbortController>());

  /**
   * The uploader and the rejection callback, read through a ref at call time.
   *
   * `start()` closes over these, and a screen that passes an inline arrow (`upload={(f, o) => …}`)
   * hands a new identity every render. Closing over the VALUE would make `start` a new function
   * every render too, which re-runs every memo below it; closing over a ref keeps `start` stable and
   * still calls the newest handler.
   */
  const latest = useRef(options);
  useEffect(() => {
    latest.current = options;
  });

  useEffect(() => {
    // Unmount is a cancellation, not a completion. Without this a navigation away leaves N requests
    // in flight against a component that will never render their result, and the browser's own
    // per-host connection limit then delays whatever the next screen fetches.
    const running = controllers.current;
    return () => {
      for (const controller of running.values()) controller.abort();
      running.clear();
    };
  }, []);

  const patch = useCallback(
    (id: string, change: Partial<UploadItem>): void => {
      commit(itemsRef.current.map((item) => (item.id === id ? { ...item, ...change } : item)));
    },
    [commit],
  );

  const add = useCallback(
    (files: readonly File[]): void => {
      if (files.length === 0) return;
      commit([
        ...itemsRef.current,
        ...files.map((file) => ({
          // `crypto.randomUUID` is an identity here, never a security token.
          //
          // ITS AVAILABILITY IS GATED ON A SECURE CONTEXT, NOT ON A BROWSER VERSION, and this
          // comment used to say the opposite ("available on every browser this app supports"). Web
          // Crypto exposes `crypto.randomUUID` only where `window.isSecureContext` is true — https,
          // `localhost` or `127.0.0.1` — so on `http://app.example:3000` it is `undefined`, this
          // line throws `TypeError`, and the files the user just chose never become rows. Nothing
          // catches it and nothing on screen changes: the picker looks like it did nothing.
          //
          // That is not reachable in the shipped topology — Traefik has one entrypoint and it is
          // `websecure`, so every origin this app is served from is https. It IS reachable in a
          // harness, and it cost the first Playwright run five specs on 2026-08-24 before the run
          // was moved onto `http://localhost:3000`, which is potentially-trustworthy by spec.
          // Node >= 19 has it unconditionally, so both Vitest projects are unaffected.
          id: crypto.randomUUID(),
          file,
          phase: 'queued' as const,
          progress: null,
          error: null,
        })),
      ]);
    },
    [commit],
  );

  const cancel = useCallback((id: string): void => {
    const controller = controllers.current.get(id);
    // The abort drives the phase: `uploadFile` rejects with the signal's AbortError and `start`'s
    // catch writes `cancelled`. Writing the phase HERE as well would race that write and can leave
    // a row reading `cancelled` while its request is still on the wire.
    if (controller !== undefined) controller.abort();
  }, []);

  const remove = useCallback(
    (id: string): void => {
      cancel(id);
      controllers.current.delete(id);
      commit(itemsRef.current.filter((item) => item.id !== id));
    },
    [cancel, commit],
  );

  const launch = useCallback(
    (only?: string): void => {
      for (const item of itemsRef.current) {
        // Only `queued` starts. `failed` deliberately does NOT restart here: an upload carries no
        // idempotency key, so re-sending is a second source row, and that has to be a thing the user
        // asks for on a row they can see rather than something a batch-level Start does for them.
        if (item.phase !== 'queued') continue;

        // ONE ROW WHEN THE CALLER NAMED ONE. `requeue` used to call this with no argument, so
        // retrying a single failed row also uploaded every row the user had STAGED but not
        // submitted — bypassing `uploadSchema(limits)`, which runs inside `form.handleSubmit` and
        // nowhere else. An oversized or wrong-type file went on the wire while its own row was
        // still showing the red issue note that said it would not.
        if (only !== undefined && item.id !== only) continue;

        // ── THE PHASE IS WRITTEN BEFORE THE REQUEST, NOT BY ITS FIRST PROGRESS EVENT ─────────
        //
        // `uploadFile` opens the XHR and returns; the first `on_progress` lands a network round
        // trip later. Leaving the row at `queued` for that window left `isPending` false and the
        // submit button enabled, so a second click ~200 ms after the first re-entered this loop,
        // saw every row still `queued`, and sent the whole batch AGAIN. There is no
        // `Idempotency-Key` on this route by design, so that is a duplicate source row, a duplicate
        // parse, a duplicate embedding bill and duplicate passages in the index — and the second
        // `controllers.current.set` overwrote the first controller, so the first request could no
        // longer be cancelled either.
        //
        // `progress: null` stays null: the bar is indeterminate until there is something to
        // measure, which is exactly what null means here.
        patch(item.id, { phase: 'uploading', progress: null, error: null });

        const controller = new AbortController();
        controllers.current.set(item.id, controller);

        void latest.current
          .upload(item.file, {
            signal: controller.signal,
            on_progress: (progress) => {
              // The row goes to `finishing` the moment the last byte is out, so the bar stops
              // claiming to measure the server's half of the work.
              patch(item.id, {
                phase: progress.percent === 100 ? 'finishing' : 'uploading',
                progress,
              });
            },
          })
          .then(
            () => {
              patch(item.id, { phase: 'done', error: null });
            },
            (cause: unknown) => {
              // CANCELLATION IS AN OUTCOME, NOT A FAILURE — the same reading `stream-answer.ts`
              // gives it. No error state, no banner, no retry affordance: the user asked for this.
              if (cause instanceof Error && cause.name === 'AbortError') {
                patch(item.id, { phase: 'cancelled', error: null });
                return;
              }

              // Anything else is a `KbError` from `lib/api/upload.ts`. A non-KbError can only be a
              // defect in the uploader itself, and it renders as the null-class sentence, which is
              // the honest reading of "we do not know what happened".
              const error =
                cause instanceof KbError
                  ? cause
                  : new KbError(null, false, null, null, String(cause));

              patch(item.id, { phase: 'failed', error });

              // The 422 field map, handed on for the form to attach to `files.<index>`. Present
              // ONLY on `error_class: 'validation'` — never `{}` on any other class — so the guard
              // is on the map itself rather than on the class name.
              //
              // The index is read at REJECTION time, not captured when the request started: a row
              // removed while the batch was in flight renumbers the ones after it, and form state's
              // `files.<index>` is the position the control has NOW. `-1` means the row was removed
              // while its request was on the wire — there is no control to write to, so nothing is.
              if (error.errors !== null) {
                const index = itemsRef.current.findIndex((row) => row.id === item.id);
                if (index !== -1) latest.current.onFileRejected?.(index, error.errors);
              }
            },
          )
          .finally(() => {
            controllers.current.delete(item.id);
          });
      }
    },
    [patch],
  );

  const start = useCallback((): void => {
    launch();
  }, [launch]);

  const requeue = useCallback(
    (id: string): void => {
      // `progress: null` too, or the bar reappears at the percentage the failed attempt reached and
      // then jumps backwards when the new request's first event lands.
      patch(id, { phase: 'queued', error: null, progress: null });

      // THIS ROW AND NO OTHER. `requeue` used to call the batch-wide `start()`, which also
      // uploaded every row the user had STAGED but not submitted — bypassing `uploadSchema(limits)`,
      // which runs inside `form.handleSubmit` and nowhere else.
      launch(id);
    },
    [patch, launch],
  );

  return {
    items,
    add,
    remove,
    cancel,
    start,
    requeue,
    isPending: items.some((item) => item.phase === 'uploading' || item.phase === 'finishing'),
    succeeded: items.filter((item) => item.phase === 'done').length,
    failed: items.filter((item) => item.phase === 'failed').length,
  };
}
