'use client';

import { FileTextIcon, ImageIcon, PresentationIcon, SheetIcon, GlobeIcon } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

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

export function UploadFileRow({
  item,
  onCancel,
  onRemove,
  onRetry,
}: {
  readonly item: UploadItem;
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
      // `aria-busy` on the row that is busy, rather than one flag for the list: a screen reader
      // exploring a partially-finished batch is told which rows are still moving.
      aria-busy={running}
      className="flex flex-col gap-2 border-b border-border px-card-pad-sm py-3 last:border-0"
    >
      <div className="flex items-center gap-3">
        <span
          aria-hidden
          className={cn('flex size-8 shrink-0 items-center justify-center rounded-lg', tone.surface)}
        >
          <Glyph className="size-4" strokeWidth={1.5} />
        </span>

        <span className="min-w-0 flex-1">
          {/* `break-all` rather than `truncate`: a filename is the only thing distinguishing two
              rows, and an ellipsis in the middle of `2026-q3-…-final.pdf` hides exactly the part
              that differs. It wraps instead, which also survives the 1.4.12 text-spacing overrides
              a fixed-height truncated row does not. */}
          <span className="block text-base break-all">{item.file.name}</span>
          <span className="block text-caption text-muted-foreground">
            {formatBytes(item.file.size)}
          </span>
        </span>

        <StatusPill status={status.kind} label={status.label} />

        {running ? (
          <Button variant="ghost" size="sm" onClick={() => onCancel(item.id)}>
            {/* NOT ICON-ONLY, and the name carries the filename: role/name matching is a
                case-insensitive SUBSTRING, so "Cancel" on five rows resolves to five controls and
                a keyboard user hears the same word five times with nothing to tell them apart. */}
            Cancel {item.file.name}
          </Button>
        ) : (
          <Button variant="ghost" size="sm" onClick={() => onRemove(item.id)}>
            Remove {item.file.name}
          </Button>
        )}
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

      {item.error !== null ? (
        <div
          // The row-scoped error presentation states.md asks for: the blast radius is one file, so
          // it is an inline block in the row rather than a banner over the batch.
          className="rounded-lg border-l-[3px] border-l-destructive bg-destructive-soft px-3 py-2 text-sm text-destructive-soft-foreground"
        >
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
            <Button variant="outline" size="sm" className="mt-2" onClick={() => onRetry(item.id)}>
              Try {item.file.name} again
            </Button>
          ) : null}
        </div>
      ) : null}
    </li>
  );
}
