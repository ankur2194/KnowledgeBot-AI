'use client';

import { CheckIcon, CopyIcon, RefreshCwIcon, ThumbsDownIcon, ThumbsUpIcon } from 'lucide-react';
import { useState } from 'react';

import { RequestId } from '@/components/states';
import { Button } from '@/components/ui/button';
import type { KbError } from '@kb/contracts';
import { endUserCopy } from '@/lib/forms/apply-server-errors';

/**
 * A REFUSAL IS A DESIGNED STATE, NOT AN ERROR.
 *
 * When evidence is below the threshold the bot says it does not know, and that answer is CORRECT
 * BEHAVIOUR — a grounded system refusing is the feature, not a failure of it. So: a normal assistant
 * turn with a calm `--tone-slate` note and a next step. No red, no error glyph, no retry button, and
 * nothing shared with the failure treatment. Styling this like an error is what generates support
 * tickets about a product that is working.
 */
export function RefusalNote() {
  return (
    <div className="flex flex-col gap-2 rounded-xl bg-tone-slate-surface p-card-pad-sm text-tone-slate-foreground">
      <p className="text-md">I couldn’t find enough in this bot’s sources to answer that.</p>
      <p className="text-sm">Try rephrasing the question, or add a source that covers it.</p>
    </div>
  );
}

/** Stopping keeps the partial answer and offers Regenerate. */
export function StoppedNote({ onRegenerate }: { readonly onRegenerate?: () => void }) {
  return (
    <div className="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
      <span>You stopped this answer.</span>
      {onRegenerate ? (
        <Button variant="outline" size="sm" onClick={onRegenerate}>
          <RefreshCwIcon aria-hidden />
          Regenerate
        </Button>
      ) : null}
    </div>
  );
}

/**
 * The failure treatment, and the only one of the four that is red.
 *
 * The class-mapped sentence plus the `request_id`; the envelope's `message` reaches no rendered
 * string. Retry appears ONLY when the envelope says the class is retryable — `error_class: null` is
 * unknown, unknown is permanently non-retryable, and a retry that cannot help is worse than none.
 */
export function FailureNote({ error, onRetry }: { readonly error: KbError; readonly onRetry?: () => void }) {
  return (
    <div
      role="alert"
      className="flex flex-col items-start gap-2 rounded-xl border-l-[3px] border-l-destructive bg-destructive-soft p-card-pad-sm text-destructive-soft-foreground"
    >
      <p className="text-md">{endUserCopy({ error_class: error.error_class, request_id: null })}</p>
      {error.request_id === null ? null : <RequestId value={error.request_id} />}
      {error.retryable && onRetry ? (
        <Button variant="outline" size="sm" onClick={onRetry}>
          Try again
        </Button>
      ) : null}
    </div>
  );
}

/**
 * Actions on a SETTLED turn only. On desktop they may appear on hover; on touch they are always
 * visible, because there is no hover — so they are simply always rendered rather than hover-gated,
 * which is the version that works everywhere.
 *
 * Thumbs-down opens a reason picker, because an unqualified downvote is not usable signal for
 * evaluation. The reasons are the fixed four.
 *
 * ── THE THUMBS ARE NOT RENDERED WITHOUT AN `onFeedback` HANDLER ─────────────────────────────────
 * They used to render unconditionally, calling `onFeedback?.(…)` — so on every surface that had not
 * wired the endpoint they were a pair of buttons that swallowed a click. A rating control that does
 * nothing is worse than an absent one twice over: the reader believes they have told us something,
 * and the satisfaction figure is missing exactly the opinions somebody bothered to give.
 *
 * The gate is the handler and not a boolean, so a surface cannot render them and forget to wire one.
 * Copy and Regenerate are unaffected — copying is client-side, and Regenerate has its own prop.
 */
export function AnswerActions({
  text,
  onRegenerate,
  onFeedback,
}: {
  readonly text: string;
  readonly onRegenerate?: () => void;
  readonly onFeedback?: (verdict: 'up' | 'down', reason?: string) => void;
}) {
  const [copied, setCopied] = useState(false);
  const [askingWhy, setAskingWhy] = useState(false);

  const REASONS = ['Inaccurate', 'Not from my sources', 'Incomplete', 'Unsafe'] as const;

  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap items-center gap-1">
        <Button
          variant="ghost"
          size="sm"
          onClick={() => {
            void navigator.clipboard.writeText(text).then(() => setCopied(true));
          }}
        >
          {copied ? <CheckIcon aria-hidden /> : <CopyIcon aria-hidden />}
          {copied ? 'Copied' : 'Copy'}
        </Button>
        {onRegenerate ? (
          <Button variant="ghost" size="sm" onClick={onRegenerate}>
            <RefreshCwIcon aria-hidden />
            Regenerate
          </Button>
        ) : null}
        {onFeedback === undefined ? null : (
          <>
            <Button
              variant="ghost"
              size="icon-sm"
              aria-label="Good answer"
              onClick={() => onFeedback('up')}
            >
              <ThumbsUpIcon aria-hidden />
            </Button>
            <Button
              variant="ghost"
              size="icon-sm"
              aria-label="Bad answer"
              aria-expanded={askingWhy}
              onClick={() => setAskingWhy((open) => !open)}
            >
              <ThumbsDownIcon aria-hidden />
            </Button>
          </>
        )}
      </div>

      {askingWhy && onFeedback !== undefined ? (
        <fieldset className="flex flex-wrap items-center gap-2">
          <legend className="mb-1 text-caption text-muted-foreground">What was wrong?</legend>
          {REASONS.map((reason) => (
            <Button
              key={reason}
              variant="outline"
              size="sm"
              onClick={() => {
                onFeedback?.('down', reason);
                setAskingWhy(false);
              }}
            >
              {reason}
            </Button>
          ))}
        </fieldset>
      ) : null}
    </div>
  );
}

/**
 * `--text-caption` in `--muted-foreground`: the model name where the bot exposes it, and the elapsed
 * time.
 *
 * NOT the token count — that is billing detail on a customer's customer's screen. NOT the request id
 * — that appears on errors, where it is a handle. And nothing from the internal envelope.
 */
export function MetadataLine({ model, elapsedMs }: { readonly model?: string; readonly elapsedMs?: number }) {
  const parts = [model, elapsedMs === undefined ? undefined : `${(elapsedMs / 1000).toFixed(1)}s`].filter(Boolean);
  if (parts.length === 0) return null;

  return <p className="text-caption text-muted-foreground tabular-nums">{parts.join(' · ')}</p>;
}
