import { useSyncExternalStore } from 'react';

/**
 * A minimal, OBSERVABLE stand-in for the three `next/navigation` hooks a URL-backed table needs.
 *
 * `useRouter()` throws outside a mounted App Router ("invariant expected app router to be mounted"),
 * so a component spec must mock it either way. This module exists so the mock is not just a pair of
 * spies: `useTableParams` writes the whole view into the URL and reads it back on the next render, so
 * a spy that swallows the href leaves the hook with nothing to read and every assertion after the
 * first click asserts the initial state.
 *
 * Two things it makes assertable that a spy cannot:
 *
 *   HOW MANY NAVIGATIONS ONE INTERACTION CAUSED. The whole point of putting the page reset in the
 *   same value as the sort is that it is ONE navigation. Two entries in `calls` for one click is the
 *   bug, even when the final URL is right.
 *
 *   PUSH VERSUS REPLACE. Paging pushes so Back returns to the previous page; sorting and filtering
 *   replace so Back does not walk backwards through somebody's typing.
 *
 * The store is module-scope and shared by every spec that imports it, so `resetNavigation()` belongs
 * in `beforeEach`.
 */
export interface NavigationCall {
  readonly href: string;
  readonly history: 'push' | 'replace';
  readonly scroll: boolean | undefined;
}

const listeners = new Set<() => void>();
let search = '';
let calls: NavigationCall[] = [];

/** The pathname every mocked navigation is relative to. Specs assert against it. */
export const MOCK_PATHNAME = '/bots';

function emit(): void {
  for (const listener of listeners) listener();
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

function navigate(href: string, history: 'push' | 'replace', scroll: boolean | undefined): void {
  calls.push({ href, history, scroll });
  const query = href.indexOf('?');
  search = query === -1 ? '' : href.slice(query + 1);
  emit();
}

export function resetNavigation(initialSearch = ''): void {
  search = initialSearch;
  calls = [];
  emit();
}

/** Every navigation since the last reset, in order. */
export function navigationCalls(): readonly NavigationCall[] {
  return calls;
}

/** The query string the mocked router currently holds, without the leading `?`. */
export function currentSearch(): string {
  return search;
}

/**
 * `useSyncExternalStore` rather than a plain read: a navigation happens inside a click handler, and a
 * hook that only reads a module variable would leave the component rendering the pre-click URL until
 * something else re-rendered it.
 */
export function useMockSearchParams(): URLSearchParams {
  const snapshot = useSyncExternalStore(subscribe, () => search, () => search);
  return new URLSearchParams(snapshot);
}

export function useMockPathname(): string {
  return MOCK_PATHNAME;
}

export function useMockRouter() {
  return {
    push: (href: string, options?: { scroll?: boolean }) =>
      navigate(href, 'push', options?.scroll),
    replace: (href: string, options?: { scroll?: boolean }) =>
      navigate(href, 'replace', options?.scroll),
    refresh: () => {},
    back: () => {},
    forward: () => {},
    prefetch: () => {},
  };
}
