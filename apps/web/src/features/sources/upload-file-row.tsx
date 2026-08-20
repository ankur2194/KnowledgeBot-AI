'use client';

import {
  AlertTriangleIcon,
  FileTextIcon,
  GlobeIcon,
  ImageIcon,
  PresentationIcon,
  SheetIcon,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { RequestId } from '@/components/states';
import { StatusPill, type StatusKind } from '@/components/status-pill';
import { SOURCE_TONE, TONE, type SourceKind } from '@/components/tone';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { endUserCopy } from '@/lib/forms/apply-server-errors';
import { cn } from '@/lib/utils';

import { formatBytes, sourceKindForFile } from './file-display';
import type { UploadItem } from './use-file-uploads';

/**
 * One file's row: a tone-tinted kind badge, the name, the size, its own progress and its own state.
 *
 * PER-FILE, NEVER PER-BATCH. Everything visible here is read off ONE `UploadItem`; this component
 * cannot express a batch-wide state even by accident, which is what keeps a partial success
 * renderable (kb-ui-patterns -> references/states.md, *Partial success*).
 */

/**
 * The kind glyph. A SECOND CHANNEL beside the tone, because a tint alone is unreadable in greyscale
 * and under CVD (kb-ui-accessibility, *Colour independence*) — and the tone here is decorative and
 * categorical, never a signal of good or bad.
 *
 * A `Record` over the closed `SourceKind` union, so a seventh kind is a typecheck failure here
 * rather than an untinted badge with no icon.
 */
const KIND_GLYPH: Readonly<Record<SourceKind, LucideIcon>> = Object.freeze({
  document: FileTextIcon,
  spreadsheet: SheetIcon,
  presentation: PresentationIcon,
  website: GlobeIcon,
  image: ImageIcon,
});

/**
 * Phase → the closed status vocabulary the pill renders (P8), plus the word.
 *
 * A `switch` rather than an object: an object indexed by a value read off a union is one refactor
 * from being indexed by a value read off the wire, which is an injection sink, and a switch makes a
 * seventh phase a typecheck failure at this exact line.
 *
 * `finishing` is `running` like `uploading` — the bytes are gone but the request is not settled, and
 * the two are the same KIND of state. What differs is the word and the bar, below.
 */
function phaseStatus(item: UploadItem): { readonly kind: StatusKind; readonly label: string } {
  switch (item.phase) {
    case 'queued':
      return { kind: 'pending', label: 'Queued' };
    case 'uploading':
      return { kind: 'running', label: 'Uploading' };
    case 'finishing':
      return { kind: 'running', label: 'Finishing' };
    case 'done':
      return { kind: 'ready', label: 'Uploaded' };
    case 'failed':
      return { kind: 'failed', label: 'Failed' };
    case 'cancelled':
      return { kind: 'disabled', label: 'Cancelled' };
  }
}

/**
 * THE ONE ROW-SCOPED NOTE BOX, and there is one of it because there are two callers.
 *
 * states.md scales the presentation to the blast radius: a whole page that failed is a centred
 * block, one card is a banner inside that card, and A FAILED ROW IS AN INLINE BLOCK IN THE ROW. Both
 * things a row can have to say — "this file will not be accepted" before it is sent, and "this file
 * was refused" after — are that shape, so they share the box rather than each carrying its own copy
 * of the class string. A second copy is where the dark-mode fix arrives in one place and not the
 * other.
 *
 * The glyph is the SECOND CHANNEL: an error that is only red is not an error state
 * (kb-ui-accessibility, *Colour independence*).
 */
function RowNote({ children }: { readonly children: ReactNode }) {
  return (
    <div className="flex flex-col gap-1 rounded-lg border-l-[3px] border-l-destructive bg-destructive-soft px-3 py-2 text-sm text-destructive-soft-foreground">
      {children}
    </div>
  );
}

export function UploadFileRow({
  item,
  issue,
  onCancel,
  onRemove,
  onRetry,
}: {
  readonly item: UploadItem;
  /**
   * THIS FILE'S MESSAGE FROM THE FORM'S `files.<index>` SLOT, or undefined when it has none.
   *
   * It is the client-side half: `uploadSchema(limits)` refused this file on the organization's own
   * `max_bytes` / `allowed_mime` before anything was sent, so the user is told BEFORE spending the
   * bytes. Client validation is a UX affordance and never a control — the server checks all of it
   * again, and checks an extension allow-list this app cannot see (rhf-zod-forms NN2).
   *
   * ── IT IS SUPPRESSED THE MOMENT THE ROW HAS A REAL FAILURE, AND THAT IS THE WHOLE RULE ────────
   * A server 422 arrives on `item.error` AND is written to the same `files.<index>` slot by
   * `applyServerErrors` (that write is what puts the message on the right ROW rather than on row 0
   * once per failure). Rendering both would print one refusal twice, three lines apart. `item.error`
   * wins because it carries what the slot cannot: the `request_id` and the retry affordance.
   */
  readonly issue?: string;
  readonly onCancel: (id: string) => void;
  readonly onRemove: (id: string) => void;
  /** Offered ONLY when the envelope says the failure is retryable — see the affordance below. */
  readonly onRetry: (id: string) => void;
}) {
  const kind = sourceKindForFile(item.file);
  const Glyph = KIND_GLYPH[kind];
  const tone = TONE[SOURCE_TONE[kind]];
  const status = phaseStatus(item);

  const running = item.phase === 'uploading' || item.phase === 'finishing';
  // `percent === null` means the browser reported `lengthComputable: false`. It is a REAL state and
  // is rendered differently — see the bar below.
  const percent = item.progress?.percent ?? null;

  // Validation MESSAGES are end-user copy: Laravel translates them, and they are the only server
  // strings this row renders verbatim. The envelope's `message` is not one of them and reaches no
  // rendered string here.
  const fieldMessages =
    item.error?.error_class === 'validation' && item.error.errors !== null
      ? Object.values(item.error.errors).flat()
      : [];

  return (
    <li
      data-phase={item.phase}
      // `data-*` rather than `aria-invalid`, which is only allowed on widget roles — putting it on a
      // `listitem` is an aria-allowed-attr violation axe would (rightly) flag. The message text and
      // its glyph are what actually announce the problem; this is for a spec to address the row by.
      data-invalid={item.error !== null || issue !== undefined}
      // `aria-busy` on the row that is busy, rather than one flag for the list: a screen reader
      // exploring a partially-finished batch is told which rows are still moving.
      aria-busy={running}
      className="flex flex-col gap-2 border-b border-border px-card-pad-sm py-3 last:border-0"
    >
      {/* ── `flex-wrap` AND A `basis`, BECAUSE THIS ROW BROKE AT 375px ───────────────────────────
          MEASURED, 2026-08-20, in a real cascade at three widths. The row was `flex items-center`
          with the name at `min-w-0 flex-1` and the action beside it. `break-all` on the name makes
          its MIN-CONTENT width one character; the action's label was a whole filename, one long
          unbroken word, so ITS min-content was ~44 characters. Flex resolves that by giving the
          button what it needs and the name what is left — and at 375px what is left was one
          character, so the filename rendered as a vertical column of single letters and the row was
          roughly 700px tall. It looked fine at 1280, which is why it shipped.

          Two changes, and both are needed. The action labels below are now short (see there), which
          removes the cause; and this line wraps, with the name claiming a `basis-48` so the status
          and the action drop to a second line rather than squeezing it. */}
      <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
        <span
          aria-hidden
          className={cn('flex size-8 shrink-0 items-center justify-center rounded-lg', tone.surface)}
        >
          <Glyph className="size-4" strokeWidth={1.5} />
        </span>

        <span className="min-w-0 flex-1 basis-48">
          {/* `break-all` rather than `truncate`: a filename is the only thing distinguishing two
              rows, and an ellipsis in the middle of `2026-q3-…-final.pdf` hides exactly the part
              that differs. It wraps instead, which also survives the 1.4.12 text-spacing overrides
              a fixed-height truncated row does not. */}
          <span className="block text-base break-all">{item.file.name}</span>
          <span className="block text-caption text-muted-foreground">
            {formatBytes(item.file.size)}
          </span>
        </span>

        {/* Grouped so the pill and the action wrap TOGETHER and stay together on the second line,
            rather than the pill staying up and the button dropping alone. */}
        <span className="ml-auto flex shrink-0 items-center gap-2">
          <StatusPill status={status.kind} label={status.label} />

          {/* ── THE FILENAME IS IN THE ACCESSIBLE NAME, NOT IN THE VISIBLE LABEL ─────────────────
              NOT ICON-ONLY — it is a text button. What changed on 2026-08-20 is where the filename
              lives: it used to be VISIBLE, so a batch of five drew five ~44-character buttons and
              starved the filename column at 375px (see the note above).

              `aria-label` keeps every property that decision was protecting. The accessible name is
              still unique per row, so `getByRole('button', { name: 'Remove …' })` still resolves to
              one control and a screen-reader user still hears which file each action belongs to.
              WCAG 2.5.3 Label in Name is satisfied deliberately rather than accidentally: the
              visible string is a contiguous prefix of the accessible name in all three cases —
              "Cancel", "Remove", and "Try again" in `Try again with <name>` — so speech input on
              the visible words still activates the control. */}
          {running ? (
            <Button
              variant="ghost"
              size="sm"
              aria-label={`Cancel ${item.file.name}`}
              onClick={() => onCancel(item.id)}
            >
              Cancel
            </Button>
          ) : (
            <Button
              variant="ghost"
              size="sm"
              aria-label={`Remove ${item.file.name}`}
              onClick={() => onRemove(item.id)}
            >
              Remove
            </Button>
          )}
        </span>
      </div>

      {running ? (
        percent === null ? (
          // INDETERMINATE, AND IT DELIBERATELY DOES NOT USE `<Progress>`. That component's
          // shadcn-inherited `value || 0` renders `undefined` and `null` as 0%, so a determinate bar
          // parked at 0% for the whole upload is what a "null" value actually paints — which P13
          // names as worse than a spinner. `kb-skeleton` is an indeterminate indicator that survives
          // reduced motion by design: the sweep stops and the tinted block stays, so the signal is
          // never deleted (kb-motion-and-effects E7).
          <>
            <div aria-hidden className="kb-skeleton h-1.5 w-full rounded-full" />
            <p className="text-caption text-muted-foreground">
              Uploading {item.file.name}. The total size was not reported, so there is no percentage
              to show.
            </p>
          </>
        ) : (
          <>
            <Progress
              value={percent}
              // RADIX SUPPLIES `role="progressbar"` AND `aria-valuenow` AND NOTHING ELSE. Without a
              // name per instance, a batch of five is five unnamed progress bars, and the one that
              // is stuck is unidentifiable. The filename is what makes each one unique.
              aria-label={`Upload progress for ${item.file.name}`}
            />
            {/* The label sits ABOVE/BESIDE the bar with the count, never inside it (P13). It is
                also the non-colour channel: the bar's fill is the only visual signal, and a
                percentage in text is readable in greyscale. */}
            <p className="text-caption text-muted-foreground">
              {item.phase === 'finishing'
                ? `Sent. Waiting for the server to accept ${item.file.name}.`
                : `${percent}% of ${formatBytes(item.file.size)}`}
            </p>
          </>
        )
      ) : null}

      {/* THE PRE-FLIGHT REFUSAL, and it renders only while there is no real failure to report — see
          the `issue` prop's docblock for why the two are exclusive rather than stacked. */}
      {item.error === null && issue !== undefined ? (
        <RowNote>
          <p className="flex items-start gap-2">
            <AlertTriangleIcon aria-hidden className="mt-0.5 size-4 shrink-0" strokeWidth={1.75} />
            <span>{issue}</span>
          </p>
        </RowNote>
      ) : null}

      {item.error !== null ? (
        // The row-scoped error presentation states.md asks for: the blast radius is one file, so it
        // is an inline block in the row rather than a banner over the batch.
        <RowNote>
          {fieldMessages.length > 0 ? (
            <ul className="flex flex-col gap-0.5">
              {fieldMessages.map((message) => (
                <li key={message}>{message}</li>
              ))}
            </ul>
          ) : (
            // The class-mapped sentence. `request_id: null` here because <RequestId> below renders
            // the same string monospaced and select-all; printing it twice is worse than either.
            <p>{endUserCopy({ error_class: item.error.error_class, request_id: null })}</p>
          )}

          {item.error.request_id !== null ? <RequestId value={item.error.request_id} /> : null}

          {/* GATED ON THE ENVELOPE'S `retryable`, never on whether the copy sounds temporary, and
              never on `error_class: null` — no envelope parsed means unknown, and unknown is
              permanently non-retryable, so offering a retry that cannot help is worse than offering
              nothing. It is a BUTTON the user presses, not an automatic loop: this request carries
              no Idempotency-Key, so a repeat is a second create and has to be asked for. */}
          {item.error.retryable ? (
            <div className="mt-2">
              <Button
                variant="outline"
                size="sm"
                // "Try again with <name>", not "Try <name> again": the visible "Try again" must be a
                // CONTIGUOUS substring of the accessible name for WCAG 2.5.3, and the second spelling
                // splits it in half.
                aria-label={`Try again with ${item.file.name}`}
                onClick={() => onRetry(item.id)}
              >
                Try again
              </Button>
            </div>
          ) : null}
        </RowNote>
      ) : null}
    </li>
  );
}
