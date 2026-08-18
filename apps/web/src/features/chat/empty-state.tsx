'use client';

import { SparklesIcon } from 'lucide-react';

import { TONE, type ToneName } from '@/components/tone';
import { cn } from '@/lib/utils';

/**
 * The first-run hero and its suggestion chips — the thing that makes an empty chat useful instead of
 * intimidating.
 *
 * ── THE SUGGESTIONS COME FROM THE BOT'S CONFIGURATION ────────────────────────────────────────────
 * Never generated client-side from the corpus. Generating them would leak what the corpus CONTAINS
 * to anyone who can open the widget, which on a public bot is everyone.
 *
 * ── THE TONE IS ASSIGNED FROM A STABLE KEY, NOT A POSITION ───────────────────────────────────────
 * The badge tint is derived from the suggestion's own text, so the same question keeps the same
 * colour across reloads and across a reordering of the list. An array index would recolour every
 * chip the moment the bot's configuration changed order, which destroys the only thing the tint was
 * doing. And the lookup returns WHOLE class strings, because Tailwind's scanner is a plain-text pass
 * and a composed `bg-tone-${x}-surface` never exists.
 */
const TONE_ORDER: readonly ToneName[] = ['sky', 'mint', 'amber', 'violet', 'rose', 'slate'];

function toneFor(key: string): ToneName {
  // A cheap stable hash of the text. Stable is the requirement; cryptographic is not.
  let hash = 0;
  for (let i = 0; i < key.length; i += 1) hash = (hash * 31 + key.charCodeAt(i)) | 0;
  return TONE_ORDER[Math.abs(hash) % TONE_ORDER.length] ?? 'slate';
}

export function ChatEmptyState({
  botName,
  corpusLine,
  suggestions,
  onPick,
  disabled = false,
}: {
  readonly botName: string;
  /** One sentence on what this bot knows. */
  readonly corpusLine?: string;
  readonly suggestions: readonly string[];
  readonly onPick: (suggestion: string) => void;
  readonly disabled?: boolean;
}) {
  return (
    <div className="flex flex-1 flex-col items-center justify-center gap-4 px-gutter-sm py-12 text-center">
      <span
        aria-hidden
        className="flex size-12 items-center justify-center rounded-full bg-primary-soft text-primary-soft-foreground"
      >
        <SparklesIcon className="size-6" strokeWidth={1.5} />
      </span>
      <div className="flex flex-col gap-1">
        <h2 className="text-display">Ask anything about {botName}</h2>
        {corpusLine ? <p className="max-w-prose text-lg text-muted-foreground">{corpusLine}</p> : null}
      </div>

      {suggestions.length === 0 ? null : (
        <ul className="flex max-w-2xl flex-wrap justify-center gap-2 pt-2">
          {suggestions.slice(0, 6).map((suggestion) => {
            const tone = TONE[toneFor(suggestion)];
            return (
              <li key={suggestion}>
                {/* Clicking one FILLS THE COMPOSER AND SENDS. It does not open a menu. */}
                <button
                  type="button"
                  disabled={disabled}
                  onClick={() => onPick(suggestion)}
                  className={cn(
                    'flex items-center gap-2 rounded-full bg-card py-1.5 pr-4 pl-1.5 text-base shadow-sm',
                    'transition-[box-shadow,background-color] duration-(--dur-1) ease-out',
                    'hover:shadow-md active:bg-accent disabled:cursor-not-allowed disabled:opacity-60',
                  )}
                >
                  <span
                    aria-hidden
                    className={cn('flex size-6 items-center justify-center rounded-lg', tone.surface)}
                  >
                    <SparklesIcon className="size-3.5" />
                  </span>
                  {suggestion}
                </button>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
