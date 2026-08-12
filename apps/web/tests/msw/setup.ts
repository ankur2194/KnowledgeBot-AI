import { handlers } from './handlers';

/**
 * Setup file for the `components` project (Vitest Browser Mode, chromium).
 *
 * Browser Mode runs the tests IN a browser, so the mock transport is MSW's service worker
 * (`setupWorker` from 'msw/browser'), not `setupServer`. That worker needs `mockServiceWorker.js`
 * in `public/`, written by `npx msw init public --save`, which is a real setup step and not
 * something a skeleton should fake.
 *
 * Deliberately inert until the first component spec lands: an intercepting layer that is wired but
 * has no handlers silently swallows requests and turns an assertion failure into a timeout.
 */
void handlers;
