import type { SdkErrorPayload } from '../bridge/protocol.js';

/**
 * The CSP fallback.
 *
 * If the customer's policy lacks `frame-src https://<widget-domain>`, the iframe silently never
 * loads: no `error` event on the element, nothing in our logs, and the launcher renders a panel
 * that stays blank forever. Two signals are watched, because neither alone is reliable:
 *
 *   1. `securitypolicyviolation` on the host document — fires immediately, but only when the
 *      browser attributes the block to a policy on THIS document (an enforcing proxy or an
 *      extension may not surface one).
 *   2. A 10 s load timeout — catches everything else, including a frame that loaded the
 *      "not authorized for this domain" document and therefore fired `load` while never
 *      completing the handshake.
 *
 * The fallback is an anchor to the bot's hosted chat: same Laravel surface, same bot, no frame
 * required. It replaces the shadow root's contents, so the customer sees one affordance, not a
 * dead launcher next to a working link.
 */

/**
 * `chat.<domain>` is one of `traefik-routing`'s FOUR FIXED hostnames — it is not a new one, and
 * this is not a fourth `define` key.
 *
 * The loader is given exactly two baked constants on purpose (src/env.d.ts), so the hosted-chat
 * origin is derived by swapping the fixed `api.` label on `__KB_API_ORIGIN__`. The input is a
 * build-time constant, never `iframe.src`, `document.currentScript.src` or a `data-*` attribute —
 * all three of which the host page can rewrite before our code runs. `chat.<domain>` and
 * `api.<domain>` are siblings on the main registrable domain by rule, so the swap is total.
 *
 * (Flagged in the handover: the alternative is a fourth `define` key naming the chat origin. That
 * is a cleaner shape and a wider contract, and this file is the one place that would use it. Note
 * the name is deliberately NOT written out anywhere in src/ — the CI assertion is
 * `rg -o '__KB_[A-Z_]+__' apps/widget/src | sort -u` == the `define` keys, and that grep cannot
 * tell a comment from code, so a constant merely *discussed* in prose fails the build.)
 */
const HOSTED_CHAT_ORIGIN = __KB_API_ORIGIN__.replace('://api.', '://chat.');

/**
 * ONE `error` SDK event (§8.20), metadata only. The customer's analytics learns that the widget
 * could not frame — not why, and not from where.
 *
 * WHY `authorization`, AND WHY IT IS NOT `internal_dependency`.
 *
 * This event fires when the widget could not be framed. Both signals that reach here mean the
 * same thing: THIS PAGE IS NOT PERMITTED TO FRAME THE WIDGET. Either the customer's own policy
 * excludes us (their CSP has no `frame-src` for `<widget-domain>`), or ours excludes them (the
 * embedder's origin is not on the bot's allow-list, so Laravel served `frame-ancestors 'none'`
 * plus the "not authorized for this domain" document — which fires `load`, completes no
 * handshake, and lands on the 10 s timeout; `kb-security-baseline` §18.5). The loader cannot
 * always tell the two apart, and it does not need to: the class, the retry answer and the remedy
 * are identical for both. Fix the embedding configuration.
 *
 * Walking `kb-error-taxonomy`'s 18 rows, `authorization` is the only one that fits, and it fits
 * on every column, not just the name:
 *
 *   - Applies when: "authenticated but not permitted" — an allow-list decision about WHERE the
 *     caller is. That is exactly the `frame-ancestors` origin allow-list.
 *   - Client status: 403 admin / 404 on the PUBLIC and SDK surfaces. The widget is an SDK
 *     surface. (No HTTP status is rendered here — this is a client-side event — but the row's
 *     other three columns are what a consumer acts on, and all three are right.)
 *   - Retryable: NO. Correct, and the point of this fix: no number of attempts changes a CSP.
 *   - Fallback-eligible: no. Correct — and we do degrade, to hosted chat, which is a different
 *     surface rather than the taxonomy's provider-fallback ladder.
 *   - Page: no. Correct, and this is the operational harm the old value did.
 *
 * `internal_dependency` was wrong on every one of those. Its row reads "PostgreSQL, Valkey,
 * Laravel<->FastAPI, embedding or rerank service unreachable" — nothing downstream of us failed
 * and nothing of ours is sick. It is `bounded` retryable, which is a backoff ladder against a
 * request that cannot succeed on any attempt. And its Page column is **page**: every misconfigured
 * customer page in the fleet would have looked like our own outage. After ADR-029 it is also the
 * one class name that no longer answers the retry question at all — its `self` sub-case renders
 * 500/not-retryable while `downstream` renders 503/retryable — so it was simultaneously the wrong
 * class AND the most ambiguous one available.
 *
 * Rejected on the way: `validation` (422) claims the request was malformed; nothing was, and there
 * is no request. `authentication` (401) is the mint failing, which is a different event emitted in
 * loader/bridge.ts. The taxonomy stays at 18 — this needed no new row, only the right one.
 *
 * The annotation on FRAME_BLOCKED is what makes this enforced rather than merely correct today:
 * `onEvent`'s payload is `unknown`, so a bare object literal would typecheck with `retryable`
 * dropped. Naming `SdkErrorPayload` makes the omission a build failure.
 *
 * `Object.freeze`, and it is not decoration. `emitSdkEvent` hands this object BY REFERENCE to every
 * handler the host page registered, and `readonly` is a compile-time claim that erases. One hostile
 * — or merely careless — handler doing `p.retryable = true` on a shared module-scope constant
 * poisons every later event the loader emits from it. Frozen, the write is a silent no-op in sloppy
 * mode and a TypeError inside their own handler in strict mode; either way our value survives.
 */
export const FRAME_BLOCKED: SdkErrorPayload = Object.freeze({
  error_class: 'authorization',
  retryable: false,
  reason: 'frame_blocked',
});

/** Both signals can fire. The customer must not end up with two fallback links. */
let degraded = false;

export function degrade(
  root: ShadowRoot,
  publicBotId: string,
  position: 'left' | 'right',
  onEvent?: (type: string, payload?: unknown) => void,
): void {
  if (degraded) return;
  degraded = true;

  const link = document.createElement('a');
  link.className = 'kb-degraded';
  link.dataset['position'] = position;
  // encodeURIComponent, and a path built from a constant origin — never from a message, never
  // from a host-page value. `publicBotId` came from our own `data-kb-bot` attribute and is public
  // by construction, but it still goes through the encoder because the alternative is one
  // refactor away from a path-traversal-shaped bug in a URL on someone else's page.
  link.href = `${HOSTED_CHAT_ORIGIN}/c/${encodeURIComponent(publicBotId)}`;
  link.target = '_blank';
  // noopener stops reverse tabnabbing; noreferrer keeps the customer's page URL out of our own
  // access logs, which is a privacy commitment as much as a security one.
  link.rel = 'noopener noreferrer';
  link.textContent = 'Chat with us';

  root.replaceChildren(link);

  onEvent?.('error', FRAME_BLOCKED);
}

/** Test seam. The module-level latch is per-document, and a Playwright page reload gives a fresh
 *  one; this exists for the unit layer, which does not. */
export function resetDegradeForTest(): void {
  degraded = false;
}
