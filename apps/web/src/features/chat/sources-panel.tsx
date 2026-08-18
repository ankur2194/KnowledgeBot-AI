'use client';

import type { Citation } from '@kb/contracts';
import { ChevronDownIcon, ExternalLinkIcon, FileTextIcon } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';

/**
 * The evidence behind an answer.
 *
 * ── WHY THERE ARE NO INLINE MARKERS IN THE PROSE ─────────────────────────────────────────────────
 * `kb-ai-chat-ux` requires that a marker map to an evidence item the PIPELINE chose, and forbids the
 * UI from parsing `[1]` out of the answer and looking up what it might mean. The wire gives us the
 * evidence as a separate `citations` event, emitted BEFORE the first token — but it gives us no
 * mapping from a character offset in the answer to a citation index. Injecting markers would
 * therefore mean parsing the model's own text, which is precisely the fabricated-attribution failure
 * the rule exists to prevent.
 *
 * So the panel IS the citation surface: every item is numbered, focusable, and resolves. That
 * satisfies "every marker resolves" trivially — there is no marker that can dangle — and it is
 * honest about what the pipeline actually told us. Inline markers become possible the day the
 * contract carries offsets; until then this is the truthful rendering, not a reduced one.
 *
 * ── ACCESS ───────────────────────────────────────────────────────────────────────────────────────
 * A citation LINKS only where the viewer has access to the source detail. Access to a bot that
 * answers from a source is not access to the source itself, and on a public surface the difference
 * is never explained — it is simply not a link.
 */
export function SourcesPanel({ citations }: { readonly citations: readonly Citation[] }) {
  // Collapsed to one line when there are more than three.
  const [expanded, setExpanded] = useState(false);
  if (citations.length === 0) return null;

  const collapsible = citations.length > 3;
  const shown = collapsible && !expanded ? citations.slice(0, 3) : citations;

  return (
    <section aria-label="Sources" className="rounded-xl bg-card-inset p-card-pad-sm">
      <h3 className="mb-2 text-caption text-muted-foreground uppercase">Sources</h3>
      <ol className="flex flex-col gap-1">
        {shown.map((citation) => (
          <li key={citation.chunk_id} className="flex items-start gap-2 text-sm">
            <span
              aria-hidden
              className="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full bg-card text-caption tabular-nums"
            >
              {citation.index}
            </span>
            {citation.url === null ? (
              <span className="flex min-w-0 items-start gap-1.5">
                <FileTextIcon aria-hidden className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
                <span className="break-words">
                  <span className="sr-only">Source {citation.index}: </span>
                  {citation.title}
                </span>
              </span>
            ) : (
              <a
                href={citation.url}
                target="_blank"
                rel="noopener noreferrer nofollow ugc"
                className="flex min-w-0 items-start gap-1.5 text-primary underline-offset-4 hover:underline"
              >
                <ExternalLinkIcon aria-hidden className="mt-0.5 size-3.5 shrink-0" />
                <span className="break-words">
                  <span className="sr-only">Source {citation.index}: </span>
                  {citation.title}
                </span>
              </a>
            )}
          </li>
        ))}
      </ol>
      {collapsible ? (
        <Button
          variant="ghost"
          size="sm"
          className="mt-1"
          onClick={() => setExpanded((open) => !open)}
          aria-expanded={expanded}
        >
          <ChevronDownIcon aria-hidden className={expanded ? 'rotate-180' : undefined} />
          {expanded ? 'Show fewer' : `Show all ${citations.length}`}
        </Button>
      ) : null}
    </section>
  );
}
