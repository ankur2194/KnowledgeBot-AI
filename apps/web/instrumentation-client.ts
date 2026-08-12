import type { Context } from '@opentelemetry/api';
import { OTLPTraceExporter } from '@opentelemetry/exporter-trace-otlp-http';
import { registerInstrumentations } from '@opentelemetry/instrumentation';
import { DocumentLoadInstrumentation } from '@opentelemetry/instrumentation-document-load';
import { FetchInstrumentation } from '@opentelemetry/instrumentation-fetch';
import type { ReadableSpan, Span, SpanProcessor } from '@opentelemetry/sdk-trace-web';
import { BatchSpanProcessor, WebTracerProvider } from '@opentelemetry/sdk-trace-web';

import { API_ORIGIN } from '@/lib/env';

/**
 * Browser telemetry. `instrumentation-client.ts` (Next >= 15.3) runs after document load and
 * before hydration — the only hook that catches the FIRST navigation.
 *
 * Traces only, and a deliberately small set of them:
 *  - No browser METRICS at all. Per-visitor label cardinality has no bounded value set.
 *  - No `user-interaction` instrumentation: it spans every click with DOM targets, triples trace
 *    volume, and answers no on-call question. It is also the sole reason
 *    `@opentelemetry/auto-instrumentations-web` declares a `zone.js` peer — see below.
 *  - No `xml-http-request` instrumentation: nothing in this app uses XHR. Every request goes
 *    through `fetch` (`src/lib/api/browser.ts`, `features/chat/stream-answer.ts`), and the
 *    browser's built-in SSE client is banned outright (nextjs-app-router NN3).
 *  - Never captured: message text, header values, document.cookie, query strings, form fields.
 *  - The exporter posts SAME-ORIGIN to a Next route handler that proxies to the Collector, so the
 *    Collector is never published and CSP stays `connect-src 'self'` plus the API origin.
 *  - apps/widget gets no OTel at all — it runs on a hostile third-party page.
 *
 * WHY THE INDIVIDUAL PACKAGES AND NOT THE META-PACKAGE. `auto-instrumentations-web` depends on
 * `instrumentation-user-interaction`, which peer-depends on **zone.js**, and re-declares that peer
 * at its own top level — so the peer is unsatisfiable by not importing the piece that needs it.
 * zone.js is an Angular runtime shim that monkey-patches every async primitive in the browser; in a
 * React 19 concurrent-rendering app it is a source of scheduling bugs that reproduce nowhere else.
 * We want exactly two of the meta-package's four instrumentations, so depending on those two
 * directly satisfies every peer, drops the other two from the bundle, and matches this repo's
 * preference for closed explicit sets over auto-discovery
 * (`opentelemetry-instrumentation`: "DocumentLoadInstrumentation and FetchInstrumentation only").
 */

/** Same-origin. A cross-origin exporter endpoint is a CSP hole and an exfiltration surface. */
const EXPORTER_PATH = '/telemetry/v1/traces';

const escapeRe = (value: string): string => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/**
 * The ONLY origin trace headers are propagated to. A bare string will not work: `urlMatches` in
 * @opentelemetry/core compares a string pattern with `===` against the FULL request URL, so an
 * origin string never matches and the browser->Laravel leg silently stops propagating — verified.
 * It has to be an anchored RegExp, and the trailing `/` is load-bearing: without it
 * `http://localhost:8080.evil.test/` matches the prefix and we propagate off-site.
 */
const API_ORIGIN_ONLY = new RegExp(`^${escapeRe(API_ORIGIN)}/`);

/**
 * semconv 1.43.0 `url.full`. Spelled as a literal rather than pulling in
 * @opentelemetry/semantic-conventions purely for one constant; the pin is the comment.
 */
const ATTR_URL_FULL = 'url.full';

/**
 * Strip the query string and fragment from a URL.
 *
 * Both instrumentations set `url.full` verbatim — `location.href` for document load, the request
 * URL for fetch — and a query string in this app carries user input: a source search box, a
 * conversation filter, a page cursor. "Never captured: query strings" is an allow-list rule at the
 * emitter, not something to leave to the Collector's `transform` backstop.
 */
function stripQuery(raw: string): string {
  try {
    const url = new URL(raw, window.location.origin);
    return `${url.origin}${url.pathname}`;
  } catch {
    // Unparseable means we cannot prove what is in it. Drop the value rather than export it.
    return '';
  }
}

/**
 * Rewrites `url.full` on every span regardless of which instrumentation produced it.
 *
 * The per-instrumentation `applyCustomAttributesOnSpan` hooks cannot cover all of it: on the fetch
 * error path the hook is handed a `FetchError` with no URL, and CORS-preflight child spans do not
 * run the hook at all. `onEnd` sees every span exactly once, before `BatchSpanProcessor` buffers it
 * (a MultiSpanProcessor calls its children in array order, and both hold the same object anyway).
 * `span.setAttribute` is a no-op after `end()`, so the attribute bag is mutated directly.
 */
class UrlRedactingSpanProcessor implements SpanProcessor {
  onStart(_span: Span, _parentContext: Context): void {}

  onEnd(span: ReadableSpan): void {
    const value = span.attributes[ATTR_URL_FULL];
    if (typeof value === 'string' && /[?#]/.test(value)) {
      (span.attributes as Record<string, unknown>)[ATTR_URL_FULL] = stripQuery(value);
    }
  }

  async forceFlush(): Promise<void> {}

  async shutdown(): Promise<void> {}
}

const provider = new WebTracerProvider({
  spanProcessors: [
    new UrlRedactingSpanProcessor(),
    new BatchSpanProcessor(
      new OTLPTraceExporter({
        url: EXPORTER_PATH,
        // No custom headers: anything here is readable in devtools by definition.
      }),
    ),
  ],
});

provider.register();

registerInstrumentations({
  tracerProvider: provider,
  instrumentations: [
    new DocumentLoadInstrumentation({
      // Twenty-odd PerformanceResourceTiming span events per document and per subresource. They
      // are the whole navigation waterfall re-encoded as span events — volume with no on-call
      // question behind it, and the paint events are already covered by the browser's own RUM.
      ignoreNetworkEvents: true,
      ignorePerformancePaintEvents: true,
    }),
    new FetchInstrumentation({
      ignoreNetworkEvents: true,
      // Scoped to our API origin and nothing else. `EXPORTER_PATH` is same-origin and would
      // otherwise be traced by the very instrumentation that produced the span — a self-feeding
      // loop of one export span per export.
      propagateTraceHeaderCorsUrls: API_ORIGIN_ONLY,
      ignoreUrls: [new RegExp(`^${escapeRe(window.location.origin + EXPORTER_PATH)}`)],
      // Reads the request body to size it. The request body IS the user's message.
      measureRequestSize: false,
    }),
  ],
});
