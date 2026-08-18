/**
 * The sidebar's collapse preference, in a module with NO `'use client'` directive.
 *
 * It lives here rather than beside `<AppShell/>` because the layout that READS the cookie is a
 * server component and the shell that WRITES it is a client one. A plain function exported from a
 * `'use client'` module is not callable from the server — React only allows such an export to be
 * rendered as a component or passed as a prop — and the failure is a RUNTIME error on first request,
 * not a build error, so `next build` compiles it happily and the first page view is a red overlay.
 *
 * The preference is a display setting: not org-scoped, not a credential, and safe in a cookie the
 * layout can read before first paint. Reading it in an effect instead makes the sidebar visibly snap
 * to its remembered width after hydration on every navigation.
 */
export const SIDEBAR_COOKIE = 'kb_sidebar_collapsed';

/** Unset means expanded. */
export function readSidebarCollapsed(value: string | undefined): boolean {
  return value === '1';
}
