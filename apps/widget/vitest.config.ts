import { defineConfig } from 'vitest/config';

/**
 * `test.projects`, NEVER vitest.workspace.ts — that file was REMOVED in Vitest 4 and a leftover
 * copy is silently ignored, so the suite runs with whatever defaults remain and nobody is told.
 *
 * WHAT THIS LAYER CAN AND CANNOT PROVE. It proves the pure parts: the envelope table, the
 * value-domain guards, the `structuredClone` round trip. It cannot prove an origin check — a
 * same-origin (or no-origin) test passes with every origin check REMOVED, which makes it worse
 * than no test. Those live in tests/e2e against the two-origin Playwright harness, and the
 * streaming path lives against a fixture server that emits over time, because a mocked response
 * cannot chunk a `text/event-stream`.
 */
export default defineConfig({
  test: {
    projects: [
      {
        extends: true,
        test: {
          name: 'unit',
          // node, not jsdom: these modules are written so nothing touches the DOM at import time,
          // and jsdom would supply a `location` that hides an accidental module-scope read.
          environment: 'node',
          include: ['tests/unit/**/*.test.ts'],
        },
      },
    ],
  },
  define: {
    // The unit layer must never import a module that reads a `__KB_*` constant without these being
    // substituted — `define` is a text substitution and an unsubstituted identifier is a
    // ReferenceError, not a type error.
    //
    // The two fixture origins differ in eTLD+1 (`kb-widget.test` vs `kb.test`), which is the
    // property the whole widget/API domain split rests on. Every fixture in this workspace keeps
    // that difference; a fixture where they matched would let the collapse ship.
    __KB_VERSION__: JSON.stringify('0.0.0-test'),
    __KB_WIDGET_ORIGIN__: JSON.stringify('https://kb-widget.test'),
    __KB_API_ORIGIN__: JSON.stringify('https://api.kb.test'),
  },
});
