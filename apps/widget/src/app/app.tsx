import type { JSX } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';

import type { SessionGrant, Theme } from './bridge.js';
import { attachFrameBridge } from './bridge.js';
import { prefetchRenderer } from './lazy-renderer.js';
import { detectTier, type ResumeTier } from './resume.js';
import { setSession } from './session.js';

/**
 * The chat surface, inside the frame, on our origin.
 *
 * Preact 10 with hooks — not React. `react` + `react-dom` is roughly the whole 30 kB app budget
 * before one component exists, and there is deliberately NO React compatibility alias configured,
 * so an accidental import from apps/web's component library fails the build with
 * `Failed to resolve import "react"` instead of silently adding ~12 kB. Do not add the alias to
 * "unblock" a build; the build error IS the feature. (The alias package is banned by name in
 * eslint.config.mjs and not repeated here — CI greps this tree for it.)
 */

type Phase =
  /** Before `init`. Nothing has authority, nothing is rendered but the shell. */
  | 'awaiting-handshake'
  /** Session in hand, conversation live. */
  | 'ready'
  /** `destroy` arrived. Idempotent, and the frame stays inert afterwards. */
  | 'destroyed';

export function App(): JSX.Element {
  const [phase, setPhase] = useState<Phase>('awaiting-handshake');
  const [theme, setTheme] = useState<Theme>('auto');
  const [tier, setTier] = useState<ResumeTier>('memory');
  const composer = useRef<HTMLTextAreaElement | null>(null);

  useEffect(() => {
    const bridge = attachFrameBridge({
      onInit: (grant: SessionGrant) => {
        setSession(grant);
        // TIER 3 IS A SHIPPED STATE. With neither the partitioned cookie nor sessionStorage, every
        // top-level navigation starts a NEW conversation: `conversation.started` fires again, the
        // composer is empty, and the UI promises no history it cannot restore. This is what Safari
        // before 18.4 and Chrome Incognito get, so it is the default path, not the edge case.
        const resumeTier = detectTier();
        setTier(resumeTier);
        setPhase('ready');
        // Metadata only. The tier is a diagnostic ('memory' is not a failure); no conversation id,
        // no token, no text.
        bridge.toHost('conversation.started', { resume_tier: resumeTier });
      },
      onSession: (grant: SessionGrant) => setSession(grant),
      onTheme: (next) => {
        setTheme(next);
        // dataset on our OWN document element, never setAttribute('style').
        document.documentElement.dataset['theme'] = next;
      },
      onLocale: () => {
        /* matched against the bot's configured list server-side; falls back to the bot default */
      },
      onPageContext: () => {
        /* kept as conversation METADATA when the bot enables it, delimited exactly like retrieved
           content — data, never instruction */
      },
      onPrefill: (text) => {
        // `.value`, never innerHTML, and NEVER auto-send: the visitor still presses send.
        if (composer.current !== null) composer.current.value = text;
      },
      onVisibility: (intent) => {
        /**
         * THE PREFETCH SEAM, and the only reason `assets/renderer-*.js` exists as a chunk.
         *
         * The panel is coming into view. The visitor has not typed yet, and the first assistant
         * token is a question plus a model round trip away — so this is the dead time the Markdown
         * chunk is fetched in, and by the time there is anything to render it is already there.
         * Prefetching at `init` instead would be wrong in the other direction: the frame is created
         * at page load, so `init` arrives while the host page is still loading its own scripts, and
         * we would be competing with the customer's page for bandwidth to render nothing.
         *
         * Idempotent — `prefetchRenderer()` caches the in-flight promise, so reopening the panel is
         * not a second request. `toggle` is warmed too because only the HOST knows whether a toggle
         * opened or closed the panel; warming on a toggle that closed costs one settled promise,
         * while missing the warm on a toggle that opened puts a network round trip in front of the
         * first answer.
         */
        if (intent !== 'close') void prefetchRenderer();
        bridge.toHost(intent === 'close' ? 'widget.closed' : 'widget.opened');
      },
      onDestroy: () => setPhase('destroyed'),
    });

    return () => bridge.destroy();
  }, []);

  if (phase === 'destroyed') return <div class="hidden" />;

  return (
    <div class="flex h-full flex-col bg-background text-foreground" data-phase={phase}>
      <header class="border-b border-border px-4 py-3 text-sm font-medium">Support</header>

      <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3" data-testid="kb-transcript">
        {/* Assistant text renders through `renderAssistantMarkdown()` in ./lazy-renderer.ts, which
            is the shell's only edge to the lazy chunk src/render/renderer.ts — warmed above when
            the panel opens. Never a static import of the renderer and never `innerHTML`:
            markdown-it plus DOMPurify does not fit beside a 30 kB app shell, and a static import
            fails both the `renderer` and the `app shell` size-limit entries. */}
        {phase === 'awaiting-handshake' ? (
          <p class="text-muted-foreground text-sm">Connecting…</p>
        ) : null}
      </div>

      <form
        class="border-t border-border p-3"
        onSubmit={(event) => {
          // Send from the SUBMIT HANDLER, never an effect.
          event.preventDefault();
          throw new Error('not implemented');
        }}
      >
        <textarea
          ref={composer}
          class="w-full resize-none rounded-lg border border-input bg-card p-2 text-sm"
          rows={2}
          placeholder="Ask a question"
          aria-label="Message"
          data-testid="kb-composer"
        />
      </form>

      {/* Diagnostics only, and never surfaced to the host page. */}
      <span hidden data-testid="kb-tier" data-tier={tier} data-theme={theme} />
    </div>
  );
}
