import type { renderMarkdown } from '../render/renderer.js';

/**
 * THE SEAM THAT MAKES `src/render/renderer.ts` A CHUNK.
 *
 * This file is part of the app SHELL and holds no Markdown code at all — it is a promise, a cache
 * and one `import()`. `src/render/renderer.ts` holds markdown-it and DOMPurify, and the ONLY edge
 * from the shell's module graph to it is the dynamic import below. That is not a stylistic choice;
 * it is what makes the emitted build match `.size-limit.json`:
 *
 *   - A dynamic `import()` and nothing else  → Rolldown emits `assets/renderer-<hash>.js`, the
 *     `renderer BROTLI` entry matches it, and the shell stays small.
 *   - A STATIC `import { renderMarkdown }`   → the module is folded into `assets/index-<hash>.js`,
 *     no `renderer-*.js` is emitted, and BOTH budgets react: the renderer entry fails with
 *     "can't find files" and the 30 kB `app shell BROTLI` entry fails on the added weight.
 *   - NO import at all (what shipped before)  → the module is unreachable, nothing is emitted, and
 *     the renderer entry fails with "can't find files".
 *
 * So the two size-limit entries are not decoration around a comment: between them they fail on
 * every way of getting this wrong, which is why the chunk boundary is expressed here, in code, and
 * enforced by the build gate rather than by a reviewer noticing a missing `await import`.
 *
 * WHY LAZY, in terms of what the visitor actually waits for. The launcher is chrome the visitor
 * clicks; the panel must paint immediately after that click. markdown-it plus DOMPurify is heavier
 * than the entire rest of the app, and nothing in the panel needs it until an assistant message
 * exists — which is at minimum a visitor typing a question plus a round trip to a model. Folding it
 * into the shell would multiply the bytes between "clicked the launcher" and "can type" several
 * times over to buy something not needed for seconds. Fetching it when the panel OPENS puts the
 * request in exactly that dead time, so it is resolved before the first token and the visitor never
 * waits on it at all.
 *
 * `import type` above is erased by TypeScript, so it creates no runtime edge and no chunk merge —
 * it exists only so `RenderMarkdown` cannot drift from the function it stands for.
 */
type RenderMarkdown = typeof renderMarkdown;

/**
 * The in-flight or settled chunk load. Module scope, so the second caller reuses the first
 * caller's request instead of racing it — `prefetchRenderer()` is called on every panel open and
 * again on the first assistant message, and both must be one network request.
 */
let pending: Promise<RenderMarkdown> | null = null;

/**
 * Warm the chunk. Idempotent, and safe to call before there is anything to render.
 *
 * A REJECTION IS NOT CACHED. `pending ??= …` would otherwise pin one transient network failure —
 * a dropped connection while the panel was opening — for the life of the document, and every later
 * assistant message would fall back to unformatted text with no way back. The rejection handler
 * clears the cache first, so the next call retries; it runs after the assignment completes, which
 * is why clearing `pending` inside it is not clearing the value it is about to be assigned.
 */
export function prefetchRenderer(): Promise<RenderMarkdown> {
  pending ??= import('../render/renderer.js').then(
    (module) => module.renderMarkdown,
    (cause: unknown) => {
      pending = null;
      throw cause;
    },
  );
  return pending;
}

/**
 * The one way assistant text reaches the DOM. `stream.ts`'s `token` and `message.complete` text is
 * untrusted model output and goes through here — never `innerHTML`, and never a second sanitizer
 * configuration (`kb-security-baseline`: "one renderer everywhere" is a rule about CONFIGURATION,
 * so a second call site with looser options forks it as completely as a second copy of the code).
 *
 * THE FALLBACK IS `textContent`, DELIBERATELY, AND IT IS NOT A SANITIZER BYPASS. If the chunk
 * cannot be fetched — a customer's flaky network, an offline tab, a CDN blip — we still have an
 * answer to show and no way to sanitize it. `textContent` never parses HTML: the assignment cannot
 * create an element, an attribute or a URL, so unrendered Markdown is the worst outcome. The
 * alternatives are both bugs: `innerHTML` would hand model output straight to the parser, and
 * dropping the message would lose an answer the visitor already paid for.
 */
export async function renderAssistantMarkdown(container: Element, markdown: string): Promise<void> {
  try {
    const render = await prefetchRenderer();
    render(container, markdown);
  } catch {
    container.textContent = markdown;
  }
}
