'use client';

import { ArrowUpIcon, SquareIcon } from 'lucide-react';
import { useId, useRef, useSyncExternalStore, type FormEvent, type KeyboardEvent } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * `--card`, `--radius-xl`, `--shadow-md`, an auto-growing textarea, and the controls row inside the
 * same surface.
 *
 * ── THE GRADIENT RING IS DECORATION AND IS NOT THE FOCUS INDICATOR ───────────────────────────────
 * `kb-focus-mesh` (E5) is a masked conic background that appears on `:focus-within`. The keyboard
 * focus ring still applies to the <textarea> inside, from the global `:focus-visible` rule. E5 says
 * this explicitly because a reviewer who sees the gradient and assumes focus is handled is the
 * failure it exists to prevent — and it fails silently for anyone whose browser lacks
 * `mask-composite`.
 *
 * ── SEND BECOMES STOP, IN THE SAME POSITION ──────────────────────────────────────────────────────
 * Same place, so the muscle memory works. Stopping is a first-class outcome: it keeps the partial
 * answer rather than discarding work the tenant was already billed for.
 */
export function Composer({
  value,
  onChange,
  onSubmit,
  onStop,
  streaming,
  disabled = false,
  placeholder = 'Ask a question…',
}: {
  readonly value: string;
  readonly onChange: (value: string) => void;
  readonly onSubmit: () => void;
  readonly onStop: () => void;
  readonly streaming: boolean;
  readonly disabled?: boolean;
  readonly placeholder?: string;
}) {
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const hintId = useId();
  const sendOnEnter = useSendOnEnter();
  const empty = value.trim() === '';

  const submit = (event?: FormEvent) => {
    event?.preventDefault();
    if (empty || streaming || disabled) return;
    onSubmit();
  };

  const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
    // ENTER SENDS ON DESKTOP, SHIFT+ENTER NEWLINES. On a touch surface Enter NEWLINES and send is
    // the button — a phone keyboard's return key is not a submit key, and treating it as one loses
    // half-typed questions.
    if (event.key !== 'Enter' || event.shiftKey || !sendOnEnter) return;
    event.preventDefault();
    submit();
  };

  return (
    <form
      // `method="post"` on every form in this app, and it matters more here than anywhere: if the
      // page renders but never hydrates, no `onSubmit` is attached and the browser falls through to
      // a NATIVE submit. With no method that is a GET, which would put the visitor's question in the
      // query string — the address bar, browser history, the dev server's log and Traefik's access
      // log. On a chat surface the query string IS the user's content. POST puts it in the body,
      // where a route with no POST handler quietly re-renders and nothing is recorded anywhere.
      method="post"
      onSubmit={submit}
      className={cn(
        'kb-focus-mesh flex flex-col gap-2 rounded-xl bg-card p-2 shadow-md',
        disabled && 'opacity-60',
      )}
    >
      {/* "Your question", NOT "Message" — ACCESSIBLE NAMES ON ONE SCREEN ARE A NAMESPACE. Both
          Playwright and vitest-browser match a role's name by case-insensitive SUBSTRING, so a
          textarea named "Message" is also matched by every locator for the "Send message" button
          beside it: two elements, strict-mode failure, and for a screen-reader user two controls
          whose names contain one another. Measured here, and the same lesson
          `features/members/invitation-list.tsx` records for "Resend to …" versus "Send invitation". */}
      <label htmlFor="kb-composer" className="sr-only">
        Your question
      </label>
      <textarea
        id="kb-composer"
        ref={textareaRef}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        onKeyDown={onKeyDown}
        rows={2}
        disabled={disabled}
        placeholder={placeholder}
        aria-describedby={hintId}
        // Grows with its content up to ~40% of the viewport, then scrolls. `field-sizing-content`
        // does it in CSS, so there is no scroll-height measurement to fight with React.
        className="field-sizing-content max-h-[40dvh] w-full resize-none bg-transparent px-2 py-1.5 text-md placeholder:text-muted-foreground disabled:cursor-not-allowed"
      />

      <div className="flex items-center justify-between gap-2">
        <p id={hintId} className="px-2 text-caption text-muted-foreground">
          {sendOnEnter ? 'Enter to send, Shift + Enter for a new line' : 'Tap send when you are ready'}
        </p>

        {/* SAME POSITION for both. Stop is reachable by keyboard the entire time a stream runs. */}
        {streaming ? (
          <Button type="button" size="icon" className="rounded-full" onClick={onStop} aria-label="Stop generating">
            <SquareIcon aria-hidden />
          </Button>
        ) : (
          <Button
            type="submit"
            size="icon"
            className="rounded-full"
            disabled={empty || disabled}
            aria-label="Send message"
          >
            <ArrowUpIcon aria-hidden />
          </Button>
        )}
      </div>
    </form>
  );
}

/**
 * Whether Enter should submit: a real keyboard with a fine pointer, and nothing else.
 *
 * `useSyncExternalStore` rather than an effect — it subscribes to the media query and returns
 * `false` on the server, so the hint text and the key handler agree from the first paint without a
 * hydration mismatch and without a second render.
 */
function useSendOnEnter(): boolean {
  return useSyncExternalStore(
    (notify) => {
      const query = window.matchMedia('(hover: hover) and (pointer: fine)');
      query.addEventListener('change', notify);
      return () => query.removeEventListener('change', notify);
    },
    () => window.matchMedia('(hover: hover) and (pointer: fine)').matches,
    () => false,
  );
}
