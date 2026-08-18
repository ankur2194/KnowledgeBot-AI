/**
 * FIRST IN THE DOM, visible on focus.
 *
 * Without it every admin page costs a keyboard user the whole sidebar before they reach content —
 * one of the six keyboard failures kb-ui-accessibility says are always the same six. It is
 * positioned off-screen rather than hidden, because `display: none` and `visibility: hidden` remove
 * an element from the focus order, which defeats the entire point.
 */
export function SkipLink({ targetId = 'main' }: { readonly targetId?: string }) {
  return (
    <a
      href={`#${targetId}`}
      className="sr-only rounded-lg bg-card px-3 py-2 text-base font-medium shadow-lg focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50"
    >
      Skip to content
    </a>
  );
}
