import { setupWorker } from 'msw/browser';
import { afterAll, afterEach, beforeAll } from 'vitest';

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
