/**
 * apps/widget/src/loader/index.ts — THE FILE THE CUSTOMER PASTES ON THEIR PAGE.
 *
 * It runs in a hostile document: possibly compromised, possibly a competitor's, certainly full of
 * scripts we have never seen. Everything it touches is reachable by that page, which is why it
 * carries no authority and why the actual chat runs in an iframe on our origin.
 *
 * Its whole job: read its own configuration, create a launcher in a CLOSED shadow root, create the
 * iframe, hand off to the bridge, and get out of the way. No Preact, no @kb/contracts runtime code,
 * no dynamic import, no second network request beyond the one session mint.
 */
import launcherCss from './launcher.css?inline';

import type { LoaderOptions } from '../bridge/protocol.js';
import { attachBridge } from './bridge.js';
import { degrade } from './degrade.js';
import { buildLauncher } from './launcher.js';

const VERSION = __KB_VERSION__;
const SENTINEL = '__kbWidget';

/**
 * `document.currentScript` is non-null ONLY at a classic script's synchronous top level. It is
 * null inside a `DOMContentLoaded` handler, a `setTimeout`, a promise callback, and — always —
 * for `type="module"`. Read it NOW and close over it, or lose the configuration forever.
 *
 * This is also why the loader is built `formats: ['iife']` and never `['es']`.
 */
const tag = document.currentScript as HTMLScriptElement | null;

interface WidgetGlobal {
  readonly version: string;
  destroy(): void;
}

type KbqEntry = [string, Record<string, unknown>];

const host = window as unknown as Record<string, unknown>;

/**
 * DOUBLE INJECTION IS NORMAL, not exotic: a tag manager fires the snippet twice, or a CMS renders
 * the footer partial on both the layout and the page. Without this guard the customer gets two
 * launchers, two mints against the composite rate limit, two bridges, and every message sends
 * twice.
 *
 * It is checked BEFORE any DOM is created. It is also why the shadow host is a plain <div> and not
 * a custom element: `customElements.define()` throws `NotSupportedError` on a name already
 * registered, so a second copy at a different version would die instead of warning.
 */
// `security/detect-object-injection` flags every computed member access. `SENTINEL` is a
// module-scope string literal constant, never user input and never reachable from a message, so
// there is no key to inject. Same reasoning at the write and the delete below.
// eslint-disable-next-line security/detect-object-injection -- SENTINEL is a literal const, not input
const prior = host[SENTINEL] as WidgetGlobal | undefined;
if (prior !== undefined) {
  console.warn(`[kb] already loaded (${prior.version}); ignoring ${VERSION}`);
} else if (tag !== null && typeof tag.dataset['kbBot'] === 'string') {
  boot(tag.dataset['kbBot'], tag.dataset['kbPosition'] === 'left' ? 'left' : 'right');
}

/**
 * `crypto.randomUUID()` is exposed only in SECURE CONTEXTS, and a customer still serving
 * `http://` gets `undefined` — a TypeError before the launcher paints, on exactly the pages least
 * likely to be re-tested. `crypto.getRandomValues` has no such restriction.
 */
function channelId(): string {
  if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  let out = '';
  for (const byte of bytes) out += byte.toString(16).padStart(2, '0');
  return out;
}

function boot(publicBotId: string, position: 'left' | 'right'): void {
  const div = document.createElement('div');
  /**
   * `:host` rules lose to ANY host-page selector matching this element, and
   * `* { position: static !important }` resets are real, shipped, and common in older CSS
   * frameworks. Layout that must survive goes on the ELEMENT, with priority.
   */
  const hostStyles: Record<string, string> = {
    position: 'fixed',
    bottom: '0',
    [position]: '0',
    width: '0',
    height: '0',
    'z-index': '2147483000',
    'color-scheme': 'light dark',
  };
  // `Object.entries`, not `Object.keys` + a computed read. This is a genuine simplification rather
  // than a warning suppressed: it drops the dynamic index AND the `as string` cast that only existed
  // to talk `noUncheckedIndexedAccess` out of the `| undefined` the index introduced.
  for (const [key, value] of Object.entries(hostStyles)) {
    div.style.setProperty(key, value, 'important');
  }

  // CLOSED: the host page's script cannot walk our chrome, read the launcher, or reach the frame
  // element through `.shadowRoot`. It is not a security boundary against a determined page — the
  // iframe is — but it removes the accidental ones.
  const root = div.attachShadow({ mode: 'closed' });

  /**
   * Constructed stylesheets are NOT gated by `style-src`. A <style> element and a style=""
   * attribute are both inline CSS and need 'unsafe-inline'; this is why the launcher survives a
   * strict host CSP. The fallback exists only for a browser without the constructor — and the
   * stylesheet's own comment records that the launcher must stay legible with none applied.
   */
  try {
    const sheet = new CSSStyleSheet();
    sheet.replaceSync(launcherCss);
    root.adoptedStyleSheets = [sheet];
  } catch {
    const style = document.createElement('style');
    style.textContent = launcherCss;
    root.append(style);
  }

  const frame = document.createElement('iframe');
  frame.className = 'kb-frame';
  frame.dataset['position'] = position;

  /**
   * ONE channel id, generated once and reused: it goes in the URL and in every envelope, and
   * `parse()` drops anything that disagrees. Omitting it is not cosmetic — every envelope fails
   * the comparison, the handshake never completes, and the widget renders a launcher that does
   * nothing at all.
   *
   * Three query parameters, all load-bearing and none of them a credential. `bot` is public by
   * construction. `origin` is attacker-controlled AND self-defeating: the server echoes the
   * VALIDATED value into `Content-Security-Policy: frame-ancestors <that one origin>` on this very
   * response, so a page that lied about it never gets to render us. The session token is never
   * here — not in the query, not in the fragment.
   */
  const ch = channelId();
  frame.src =
    `${__KB_WIDGET_ORIGIN__}/embed?bot=${encodeURIComponent(publicBotId)}` +
    `&origin=${encodeURIComponent(location.origin)}&ch=${encodeURIComponent(ch)}`;

  // `allow-same-origin` is PRESENT and must stay. Without it the frame gets an OPAQUE origin:
  // `event.origin` becomes the literal string "null" (indistinguishable from any data: or
  // sandboxed frame anywhere), `postMessage` can only be targeted with '*', and storage throws.
  // The `allow-scripts` + `allow-same-origin` escape exists only when the framed document is
  // same-origin WITH THE EMBEDDER, which our cross-origin widget never is — hence the standing
  // rule never to frame this widget from a page on our own widget origin.
  // Deliberately absent: every `allow-top-navigation` variant (the anti-phishing control),
  // `allow-downloads`, `allow-modals`.
  frame.setAttribute(
    'sandbox',
    'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox',
  );
  frame.setAttribute('referrerpolicy', 'strict-origin');
  frame.title = 'Support chat';
  /**
   * NO `loading = 'lazy'` HERE, AND IT MUST NOT COME BACK.
   *
   * It was here, and it broke the widget on every healthy page. `.kb-frame` is `display: none`
   * until the panel opens (launcher.css — `display`, not `opacity`, so a hidden frame cannot
   * swallow the host page's clicks). A `display: none` iframe is never near the viewport, so a
   * LAZY frame is never fetched at all: `load` never fires, `loaded` stays false, and the 10 s
   * timer below concludes the frame was blocked.
   *
   * Measured against the harness on a page with NO CSP, everything permitted:
   *   [resp 200] /v1/kb-widget.js        ← loader runs
   *   (no request for /embed, ever)      ← lazy + display:none
   *   after 10 s → error { error_class: 'authorization', reason: 'frame_blocked' }, mints: 0
   *
   * So every visitor who did not open the chat within ten seconds watched the launcher turn into a
   * "Chat with us" link, and the customer's analytics received a `frame_blocked` naming a CSP
   * problem that did not exist. Eager is also what the rest of the design already assumes: the
   * frame speaks first (`iframe-postmessage-bridge`), the session is minted during that handshake,
   * and the degrade timer is only meaningful if a load was actually attempted.
   *
   * A `display: none` iframe DOES load eagerly — visibility gates lazy loading, not loading itself
   * — so the panel stays hidden and costs the host page nothing beyond one small same-origin-to-us
   * document. Laziness belongs to the MARKDOWN RENDERER CHUNK inside the frame, which is where the
   * budget actually is, not to the frame element.
   */

  const kbq = (host['kbq'] ?? []) as KbqEntry[];
  const init = kbq.find((entry) => entry[0] === 'init')?.[1];
  const options: LoaderOptions = {
    botId: publicBotId,
    // The queue, never a data-* attribute: any script on the host page can rewrite an attribute
    // before we read it, and a signed identity token handed over that way is a token an XSS on the
    // customer's marketing site can swap.
    userToken: typeof init?.['userToken'] === 'string' ? init['userToken'] : undefined,
    onEvent: emitSdkEvent,
  };

  const bridge = attachBridge(frame, ch, options);
  const launcher = buildLauncher(root, frame, (open) => (open ? bridge.open() : bridge.close()));

  /**
   * If the customer's CSP lacks `frame-src`, the frame silently never loads: no `error` event on
   * the element, nothing in our logs. Watch BOTH signals. We deliberately do not treat the frame's
   * `load` event as success — it also fires for the "not authorized for this domain" document the
   * server returns for an unregistered origin, and handing a session token to that page would be
   * handing it to a document that is ours but is not the app.
   */
  let loaded = false;
  frame.addEventListener('load', () => void (loaded = true), { once: true });
  const onViolation = (event: SecurityPolicyViolationEvent): void => {
    // A prefix test on a full origin is a bug; a prefix test on a blocked *URI*, which is a URL
    // under our origin, is the intended comparison. It is not an origin check and is never used
    // to decide whether to trust a message.
    if (event.blockedURI.startsWith(__KB_WIDGET_ORIGIN__)) {
      degrade(root, publicBotId, position, emitSdkEvent);
    }
  };
  document.addEventListener('securitypolicyviolation', onViolation);
  const timer = setTimeout(() => {
    if (!loaded) degrade(root, publicBotId, position, emitSdkEvent);
  }, 10_000);

  root.append(launcher.button, frame);
  document.body.appendChild(div);

  // eslint-disable-next-line security/detect-object-injection -- SENTINEL is a literal const, not input
  host[SENTINEL] = {
    version: VERSION,
    destroy: () => {
      clearTimeout(timer);
      document.removeEventListener('securitypolicyviolation', onViolation);
      bridge.destroy();
      div.remove();
      // eslint-disable-next-line security/detect-object-injection -- SENTINEL is a literal const, not input
      delete host[SENTINEL];
    },
  } satisfies WidgetGlobal;
}

/**
 * The §8.20 SDK event dispatcher — a PUBLIC API SURFACE, and therefore a commitment to strangers'
 * code. Keep it small, name it carefully, and treat any change as breaking.
 *
 * The queue form is the documented entry point for a reason: the script is `async`, so on a fast
 * connection a direct `KB.on(...)` runs before we exist and throws in the customer's console.
 */
function emitSdkEvent(type: string, payload?: unknown): void {
  const kbq = (host['kbq'] ?? []) as KbqEntry[];
  for (const entry of kbq) {
    if (entry[0] !== 'on') continue;
    const wanted = entry[1]['event'];
    const handler = entry[1]['handler'];
    if (wanted !== type || typeof handler !== 'function') continue;
    try {
      (handler as (payload?: unknown) => void)(payload);
    } catch {
      // A throw in the CUSTOMER's handler is theirs, and it must not abort our dispatch loop or
      // surface with our filename on it.
    }
  }
}
