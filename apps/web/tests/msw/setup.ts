import { setupWorker } from 'msw/browser';
import { afterAll, afterEach, beforeAll, beforeEach } from 'vitest';

import { handlers } from './handlers';

/**
 * Setup file for the `components` project (Vitest Browser Mode, chromium).
 *
 * Browser Mode runs the tests IN a browser, so the mock transport is MSW's service worker
 * (`setupWorker` from 'msw/browser'), not `setupServer`. That worker needs `mockServiceWorker.js`
 * in `public/`, written by `npx msw init public`, which is a real setup step and not something a
 * skeleton can fake — the file is now there and committed.
 *
 * `msw init --save` additionally writes an `msw.workerDirectory` key into package.json (and strips
 * that file's trailing newline while doing it). This batch is required to leave apps/web/package.json
 * byte-identical, so the key is NOT persisted. The only thing it buys is letting a bare `npx msw
 * init` find this directory again; an msw version bump without a re-init is caught at runtime
 * anyway — the worker script compares its own version against the package's and logs loudly.
 */
export const worker = setupWorker(...handlers);

beforeAll(async () => {
  // `onUnhandledRequest: 'error'` and not 'warn'. An intercepting layer that silently passes an
  // unmatched request through turns an assertion failure into a TIMEOUT: the spec waits for a render
  // that never comes, and the reported failure names the wrong thing entirely. The same reason
  // tests/unit/browser-fetch.test.ts gives for its own strict handler set.
  //
  // What 'error' actually does under the SERVICE WORKER transport is NOT to reject the fetch: the
  // worker answers `500 Request Handler Error`. Measured, and asserted in
  // tests/components/msw-harness.test.tsx, because a spec written against the intuitive behaviour
  // goes red on a correctly configured harness — and the usual repair for that is to delete the
  // guard.
  await worker.start({ onUnhandledRequest: 'error', quiet: true });
});

afterEach(() => {
  // Per-spec `worker.use(...)` overrides do not survive into the next test. Without this, a spec
  // that installed a 429 leaks it into the next one and the failure surfaces in whichever spec
  // happens to run afterwards.
  worker.resetHandlers();
});

/**
 * ── THE OVERLAPPING-`act()` GUARD ────────────────────────────────────────────────────────────────
 *
 * `vitest-browser-react` wraps `render`, `rerender` and `unmount` in React's `act()` and returns the
 * promise for it (`node_modules/vitest-browser-react/dist/pure-*.js`). Start a second one while the
 * first is still in flight and React's act queue is corrupted FOR THE REST OF THE FILE — the
 * offending test still passes, and every test after it in the same file renders into an empty
 * container and times out at 15s against an empty `<body>`.
 *
 * That symptom is the reason this exists rather than a comment somewhere. An empty `<body>` and a
 * 15s timeout is what a component that throws on mount looks like, so the reader debugs the wrong
 * file — the eleven failures name eleven innocent tests and never the one that broke them.
 *
 * WHAT IT DOES AND DOES NOT DO. It cannot repair the page: once the queue is corrupted the trailing
 * tests still fail. What it does is turn the culprit from a PASS into the run's FIRST failure,
 * carrying the cause and the fix. Verified against the reproduction: before the guard, 1 passed and
 * 3 timed out; after it, the offending test fails on this message and the three still time out.
 *
 * MEASURED, both directions, 2026-08-19. `void first.unmount()` followed by a second `render()`
 * inside one `it`: the `it` passes, the three after it fail, 48s, React logging "You seem to have
 * overlapping act() calls" twice. The same spec with `await first.unmount()`: 4 of 4 pass, 2.5s. A
 * floating unmount with nothing after it is harmless, so the hazard is the OVERLAP and not the
 * float — which also rules out the two explanations that look right and are not: it is neither the
 * second render (`forgot-password-form.test.tsx` renders twice in one `it` and is green) nor a
 * stuck `IS_REACT_ACT_ENVIRONMENT` (probed in a poisoned test: `false`).
 *
 * WHY A CONSOLE PATCH AND NOT A STATE ASSERTION. React's act queue is module-private and
 * `activeActs` is a closure variable inside vitest-browser-react; neither is reachable. The
 * overlap warning is the only observable, it is emitted synchronously at the moment of the overlap,
 * and therefore inside the test that caused it — which is the whole point. The comparison is
 * against React's own message rather than a local copy of one, so this fails loudly (a stale
 * needle stops matching) rather than silently.
 *
 * `eslint.config.mjs` carries the static half — `@typescript-eslint/no-floating-promises` over
 * `tests/components/**`. Neither is redundant: the rule catches the bare call at lint time and
 * cannot see `void promise`, while this catches every overlap however it was spelled and only once
 * the suite runs.
 */
const OVERLAPPING_ACT = 'overlapping act() calls';

let overlapped: string | null = null;
let passThrough: typeof console.error = console.error;

beforeAll(() => {
  passThrough = console.error;
  console.error = (...args: unknown[]): void => {
    const first = args[0];
    if (typeof first === 'string' && first.includes(OVERLAPPING_ACT)) {
      overlapped ??= first.trim();
    }
    // Still printed. A guard that swallowed the message would make a suite run with this file
    // removed report LESS than one without it, which is the wrong direction for a diagnostic.
    passThrough(...args);
  };
});

beforeEach(() => {
  overlapped = null;
});

afterEach(() => {
  const seen = overlapped;
  // Cleared BEFORE the throw, or one overlap fails every subsequent test in the file and reproduces
  // the exact cascade this guard exists to end.
  overlapped = null;

  if (seen !== null) {
    throw new Error(
      `React reported overlapping act() calls during this test:\n\n  ${seen}\n\n` +
        'A `render()`, `rerender()` or `unmount()` from vitest-browser-react was not awaited before ' +
        'the next one began. Left alone this corrupts React\'s act queue for the rest of the FILE: ' +
        'this test passes and every test after it times out against an empty <body>. Await the ' +
        'call. `void promise` silences the lint rule and does not fix this.',
    );
  }
});

afterAll(() => {
  console.error = passThrough;
});

afterAll(() => {
  // KNOWN COSMETIC WARNING, and it is not a bug to fix by deleting this line. The worker's
  // REGISTRATION lives in the browser profile rather than in the page, so with more than one spec
  // file the second teardown finds it already inactive and MSW prints "Found a redundant
  // worker.stop() call". Interception is unaffected — msw-harness.test.tsx proves the worker is live
  // in the second file — and the alternative (no teardown at all) leaves an intercepting layer
  // running for whatever loads next. Measured on 2026-08-13: one warning per run, no failures. A
  // per-module `startedHere` flag was tried and does NOT suppress it, because each spec file gets a
  // fresh module graph and therefore a fresh flag.
  worker.stop();
});
