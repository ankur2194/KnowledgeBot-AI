'use client';

import { useEffect, useRef } from 'react';

import { cn } from '@/lib/utils';

import { renderMarkdown } from './markdown';

/**
 * THE USER TURN IS A BUBBLE AND THE ASSISTANT TURN IS NOT, and the asymmetry is the point: it is
 * what makes it obvious at a glance who is speaking, without an avatar column the answer needs.
 *
 * Right-aligned, `--primary-soft` on `--primary-soft-foreground`, `--radius-2xl` with the
 * bottom-right corner at `--radius-sm` for the tail, max 80% width. It is a bubble because it is
 * short and attributable.
 */
export function UserTurn({ text }: { readonly text: string }) {
  return (
    <div className="flex justify-end">
      <div className="max-w-[80%] rounded-2xl rounded-br-sm bg-primary-soft px-4 py-2 text-md text-primary-soft-foreground">
        {/* Interpolated as a JSX child, which React escapes. The user's own text is still text. */}
        {text}
      </div>
    </div>
  );
}

/**
 * FULL WIDTH, NO BUBBLE, NO BACKGROUND. A long grounded answer in a bubble is unreadable, and this
 * is the surface a reader spends their time on.
 *
 * The markdown goes in through the sanitizer as a NODE (`replaceChildren`), never as a string —
 * `./markdown.ts` carries the reasoning. `dangerouslySetInnerHTML` is an ESLint error repo-wide and
 * would defeat the mXSS protection the fragment path exists for.
 */
export function AssistantProse({ text, className }: { readonly text: string; readonly className?: string }) {
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const element = ref.current;
    if (element) renderMarkdown(element, text);
  }, [text]);

  // `kb-prose` styles what the sanitizer emits and never re-opens it: no prose plugin that wants raw
  // HTML, no `img` added back, and no hooks on attributes the model can write.
  return <div ref={ref} className={cn('kb-prose', className)} />;
}

/**
 * E8. ONE caret for the whole stream, appended after the last rendered character and removed on the
 * terminal event. Nothing else in the streaming path animates — a fade or a slide per arriving token
 * turns a 400-token answer into 400 animations, drops frames on a mid-range phone, and makes text
 * unreadable while it is being read.
 *
 * Under reduced motion it stops blinking and stays SOLID. A caret that vanishes removes the only
 * signal that the answer is still arriving.
 */
export function StreamCaret() {
  return <span aria-hidden className="kb-caret align-baseline" />;
}
