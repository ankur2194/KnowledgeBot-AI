/**
 * `@kb/contracts/admin` — the SECOND SSE union, reachable only by the admin console.
 *
 * ── THIS SUBPATH EXISTS SO ONE IMPORT SPECIFIER IS THE REVIEW STOP ──────────────────────────────
 * `apps/widget` imports `@kb/contracts` and `@kb/contracts/forms`. It cannot acquire `retrieval.trace`
 * by editing a file it already has — it would have to add a THIRD specifier, which is a diff nobody
 * merges by accident. That is the whole mechanism, and it is the same one ADR-028 uses to keep Zod
 * out of the widget's 30 kB brotli shell: a subpath is a boundary a bundler and a reviewer can both
 * see, where a barrel export is neither.
 *
 * The root entry is deliberately NOT widened. `src/index.ts` re-exports nothing from here, so the
 * <=1 kB budget on the root entry is untouched by construction — `toKbAdminEvent` is 40 lines of
 * runtime code that no widget bundle should carry, and `treeshake` removing it is luck rather than a
 * guarantee (`preact-vite-library`'s silently-passing size gate).
 *
 * WHY NOT PUT IT IN `./forms`. That subpath is the Zod boundary; putting an SSE union behind it
 * would make "the schemas subpath" mean two things and would drag zod's optional-peer resolution
 * into a module that has no schemas in it.
 */
export {
  ADMIN_EVENT_NAMES,
  RETRIEVAL_TRACE_EVENT,
  isRetrievalTraceEvent,
  toKbAdminEvent,
} from './sse/admin-events.js';
export type {
  KbAdminEvent,
  KbAdminEventName,
  RetrievalTrace,
  RetrievalTraceCandidate,
  RetrievalTraceData,
  RetrievalTraceExclusion,
} from './sse/admin-events.js';
