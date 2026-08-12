/**
 * Conversation continuity across a top-level navigation, in three tiers — and the third tier is a
 * SHIPPED STATE, not a workaround.
 *
 * The frame is third-party on every customer site, so it relies on nothing a partitioned or
 * blocked storage backend can take away. Chrome still allows third-party cookies by default and
 * did not deprecate them; Firefox has partitioned them per top-level site since 103; Safari has
 * blocked them outright since 13.1 and only added opt-in `Partitioned` support in 18.4. Relying on
 * an unpartitioned cookie is relying on the one browser that has not moved.
 *
 * TIER 1 — `__Host-kbresume`, a PARTITIONED cookie our API sets on the frame's OWN requests:
 *   `Set-Cookie: __Host-kbresume=<opaque>; Secure; SameSite=None; Path=/; Partitioned; Max-Age=1800`
 *   `Partitioned` (CHIPS) keys it to the TOP-LEVEL SITE, not the origin and not the full ancestor
 *   chain, so `customer.example` and `shop.customer.example` share one continuity context while
 *   `customer-a.example` and `customer-b.example` never can. That is both the correct privacy
 *   boundary and the correct product boundary.
 *
 *   The frame never READS it. It is not the session token, it carries no authority, and it is
 *   useless without a live bearer. It names a conversation the frame may ASK to resume; the server
 *   decides. And it never crosses the bridge in either direction — a host page that can name a
 *   conversation can name ANOTHER VISITOR's.
 *
 * TIER 2 — `sessionStorage` in the frame. Per-tab, survives a reload, and is NOT blocked by
 *   Firefox's Total Cookie Protection. Every access is wrapped, because a third-party frame on a
 *   tracker list throws `SecurityError` on the property access itself, not on the method call.
 *
 * TIER 3 — MEMORY. Every top-level navigation starts a NEW conversation: `conversation.started`
 *   fires again, the composer is empty, and the UI promises no history it cannot restore. Build
 *   and test this path first — it is what Safari before 18.4 gets, what Chrome Incognito gets, and
 *   a UI that renders a "restoring…" spinner it can never resolve is the actual bug.
 *
 * RULED OUT, with reasons: the origin-persistent web-storage API and IndexedDB (barred for the
 * token anyway, and both throw under Firefox TCP); the Storage Access API (needs transient user
 * activation and grants UNPARTITIONED access we actively do not want, since it would let one
 * customer's site reach a session established on another's); Related Website Sets (requires proven
 * common administrative ownership of every member domain, which our customers by definition do not
 * share); the cross-tab broadcast channel (throws under TCP, and there is nothing to broadcast).
 * All four are banned by name in eslint.config.mjs, which is the enforcement; naming them again
 * here would only trip the CI grep that scans this tree for them.
 */

export type ResumeTier = 'cookie' | 'session-storage' | 'memory';

const KEY = 'kb.conversation';

/** Memory tier backing store. Lives and dies with the document, which is exactly the promise. */
let inMemoryConversationId: string | null = null;

/**
 * `sessionStorage` may THROW ON PROPERTY ACCESS, before any method is called: a third-party frame
 * whose domain is on Mozilla's tracker list gets `SecurityError` from the getter itself. Every
 * touch goes through here.
 */
function withSessionStorage<T>(fn: (storage: Storage) => T, fallback: T): T {
  try {
    // This module is the ONE place `sessionStorage` is reachable; every other file in the
    // workspace has the identifier banned outright (eslint.config.mjs).
    const storage = sessionStorage;
    return fn(storage);
  } catch {
    return fallback;
  }
}

export function rememberConversation(conversationId: string): void {
  inMemoryConversationId = conversationId;
  withSessionStorage((storage) => storage.setItem(KEY, conversationId), undefined);
}

export function forgetConversation(): void {
  inMemoryConversationId = null;
  withSessionStorage((storage) => storage.removeItem(KEY), undefined);
}

/** Tier 2 and tier 3 only. Tier 1 is a cookie the browser attaches to our own requests and that
 *  this document never reads. */
export function localConversationId(): string | null {
  return withSessionStorage((storage) => storage.getItem(KEY), null) ?? inMemoryConversationId;
}

/** Which tier this document actually got. Reported to nobody outside the frame — it is a
 *  diagnostic, and "memory" is not an error. */
export function detectTier(): ResumeTier {
  // Parse and compare the NAME exactly. A substring test on the whole cookie string would also
  // match a cookie the server never set, e.g. `x__Host-kbresume=`, and report a continuity tier we
  // do not actually have — which surfaces as a "restoring…" affordance that can never resolve.
  const named = (name: string): boolean =>
    document.cookie.split('; ').some((pair) => pair.split('=')[0] === name);

  if (typeof document !== 'undefined' && named('__Host-kbresume')) {
    return 'cookie';
  }
  return withSessionStorage(() => 'session-storage' as const, 'memory' as const);
}

/**
 * Ask the server to resume. Authorization is the opaque resumption token the server put in
 * partitioned storage plus the live bearer — never a conversation id supplied by anyone else.
 *
 * Needs a live Laravel, so it is a signature here. When implemented it must resolve `null`
 * (= start fresh) on ANY failure, silently: a resume that 404s because the partition is new is the
 * normal case, not an incident.
 */
export function resumeConversation(): Promise<{ conversation_id: string } | null> {
  throw new Error('not implemented');
}
