import type { Envelope } from '../bridge/protocol.js';
import { KB, parse } from '../bridge/protocol.js';

/**
 * The frame half of the bridge. This code runs on OUR origin (`<widget-domain>`), inside the
 * iframe, under OUR CSP.
 *
 * Nothing at module scope touches `location`, `parent` or `addEventListener` — the value-domain
 * guards below are importable from a Node unit test, which is where the `set-theme` and `prefill`
 * cases are proven.
 */

/** Types the HOST may send. Everything absent from this set is dropped silently. */
const FROM_HOST: ReadonlySet<string> = new Set([
  'init',
  'session',
  'open',
  'close',
  'toggle',
  'set-theme',
  'set-locale',
  'set-page-context',
  'prefill',
  'destroy',
]);

/**
 * REFUSED with no exception and no "trusted embedder" flag: bot id, organization, session or
 * end-user token from the host page, any identity claim, provider or model selection, retrieval
 * parameters, source scope, the origin allow-list, and any CSS, HTML or URL for us to load.
 *
 * The bot binding comes from the URL that minted the session and is never re-read from a message.
 * A `set-bot` message turns one shared allow-list entry into cross-tenant access.
 */
export type Theme = 'light' | 'dark' | 'auto';

const THEMES: ReadonlySet<string> = new Set(['light', 'dark', 'auto']);

/** A CLOSED ENUM. Anything else is dropped, never coerced and never used as a class name. */
export function isTheme(value: unknown): value is Theme {
  return typeof value === 'string' && THEMES.has(value);
}

/**
 * The composer cap. There is no documented `postMessage` size limit, only a practical cliff, so a
 * hostile host page can hand us a megabyte. Cap the STRING before it reaches any DOM API — and
 * assign it to `.value`, never `innerHTML`, and never auto-send: the visitor still presses send.
 */
export const PREFILL_MAX = 2000;

export function clampPrefill(value: unknown): string | null {
  return typeof value === 'string' ? value.slice(0, PREFILL_MAX) : null;
}

/**
 * `set-page-context` is DATA, never instruction: it is kept as conversation metadata when the bot
 * enables it, delimited exactly like retrieved content. The URL is scheme-checked before it is
 * ever displayed, because a `javascript:` "page URL" rendered as a link in an admin's conversation
 * review is a stored XSS with real privileges attached.
 */
export function safePageUrl(value: unknown): string | null {
  if (typeof value !== 'string' || value.length > 2048) return null;
  try {
    const url = new URL(value);
    return url.protocol === 'https:' || url.protocol === 'http:' ? url.href : null;
  } catch {
    return null;
  }
}

export interface SessionGrant {
  readonly token: string;
  readonly expires_in: number;
}

/**
 * Everything the frame app must do in response to a host message. Passing these in keeps this
 * file free of the DOM and keeps the state machine — "nothing acts before `init`", "exactly one
 * `init`" — in one readable place.
 */
export interface FrameHandlers {
  /** Fires exactly once. `boot()` resumes via `__Host-kbresume` if it is there, else starts a new
   *  conversation — and a new conversation is a SHIPPED state, not a failure. */
  onInit(grant: SessionGrant, locale: string | null): void;
  /** A token SWAP, not a re-boot: replace the module-scoped bearer and nothing else. */
  onSession(grant: SessionGrant): void;
  onTheme(theme: Theme): void;
  onLocale(locale: string): void;
  onPageContext(url: string, title: string): void;
  onPrefill(text: string): void;
  onVisibility(intent: 'open' | 'close' | 'toggle'): void;
  onDestroy(): void;
}

export interface FrameBridge {
  /**
   * Outbound. Metadata only — see the never-forward list in `kb-internal-api-contracts`.
   *
   * WHEN THIS EMITS `'error'` — it does not yet; stream.ts is a signature — the payload MUST be
   * `SdkErrorPayload` from ../bridge/protocol.js, which makes `retryable` mandatory. Carry the SSE
   * `error` envelope's OWN `retryable` straight through; never re-derive it from `error_class`.
   * `internal_dependency` renders 503/retryable for its `downstream` sub-case and 500/not-retryable
   * for `self`, and the axis that separates them is deliberately not on the wire (ADR-029, finding
   * O1), so the class name cannot answer the question. A client allow-list may NARROW the flag; it
   * may never replace it. And never forward the envelope's `message`: it is operator-facing and can
   * carry an internal hostname or raw upstream provider text into a stranger's analytics.
   */
  toHost(type: string, payload?: unknown): void;
  /** Frame → host, the distinct type that renews a token without a second `init`. */
  requestSessionRefresh(): void;
  destroy(): void;
}

export function attachFrameBridge(handlers: FrameHandlers): FrameBridge {
  const query = new URLSearchParams(location.search);
  /**
   * Attacker-supplied, and ALREADY PROVEN by the time this line runs: the server echoed the
   * validated value into `Content-Security-Policy: frame-ancestors <that one origin>` on this very
   * response, so a page at https://evil.example claiming `origin=https://customer.example` got a
   * document the browser refused to render. This holds only because `frame-ancestors` is a real
   * response header on every /embed response — it cannot be set in <meta> — and because an
   * unregistered origin yields `frame-ancestors 'none'`, never a permissive default.
   *
   * An iframe navigation carries no header naming the embedder: `Origin` is not sent on a GET
   * navigation and `Referer` is suppressible by the embedder. There is nothing else available.
   */
  const HOST = query.get('origin') ?? '';
  const CH = query.get('ch') ?? '';

  let session: string | null = null;
  let destroyed = false;
  /**
   * `let`, and the disable is deliberate rather than laziness.
   *
   * The order below is the handshake contract: `onMessage` (which clears this timer on `init`) must
   * be DECLARED before it, `addEventListener` must run BEFORE the first `toHost('ready')` — a
   * message posted to a document with no listener is dropped, not queued — and the interval can only
   * be created after that. `const readyTimer = setInterval(…)` at the assignment site would satisfy
   * the rule while opening a temporal-dead-zone window between the listener registration and the
   * initialiser; anything later inserted into that window turns the frame's bootstrap into a
   * ReferenceError and the widget never appears. It would also make `destroy()`'s `!== undefined`
   * guard dead code, so the rule would cost two edits in handshake code to buy nothing.
   */
  // eslint-disable-next-line prefer-const -- assigned after addEventListener; see above
  let readyTimer: ReturnType<typeof setInterval> | undefined;
  let tries = 0;

  const toHost = (type: string, payload?: unknown): void => {
    // An OPAQUE origin serialises to "null" and cannot be targeted by name, so the only send that
    // would work is '*'. Refuse instead of degrading. Never allow-list "null": it is
    // indistinguishable from any data: or sandboxed frame on the internet.
    if (HOST === '' || HOST === 'null') return;
    parent.postMessage({ kb: KB, ch: CH, type, payload }, HOST);
  };

  const onMessage = (event: MessageEvent): void => {
    if (destroyed) return;
    // Excludes every SIBLING frame on the host's origin — their ad slot, their tag manager, an
    // about:blank frame they created which INHERITS their origin rather than being opaque.
    if (event.source !== parent) return;
    // Full-string equality against the one origin this document was authorized for.
    if (event.origin !== HOST) return;

    const message: Envelope | null = parse(event.data, CH, FROM_HOST);
    if (message === null) return;

    // THE GATE. Nothing acts before the handshake — a listener that acts on the first plausible
    // message it sees is a listener the host page drives at page load.
    if (session === null && message.type !== 'init') return;

    const payload = (message.payload ?? {}) as Record<string, unknown>;

    switch (message.type) {
      case 'init': {
        // Exactly one. A second `init` is a state-machine reset an attacker would enjoy.
        const grant = payload['session'] as { token?: unknown; expires_in?: unknown } | undefined;
        if (typeof grant?.token !== 'string') return;
        // Authority arrives ONCE, and it came from OUR server via the one request that carried an
        // unforgeable Origin. Everything else in this payload is ignored: no bot id, no org, no
        // ability, no identity claim.
        session = grant.token;
        if (readyTimer !== undefined) clearInterval(readyTimer);
        handlers.onInit(
          {
            token: grant.token,
            expires_in: typeof grant.expires_in === 'number' ? grant.expires_in : 0,
          },
          typeof payload['locale'] === 'string' ? payload['locale'] : null,
        );
        return;
      }
      case 'session': {
        const grant = payload['session'] as { token?: unknown; expires_in?: unknown } | undefined;
        if (typeof grant?.token !== 'string') return;
        session = grant.token;
        handlers.onSession({
          token: grant.token,
          expires_in: typeof grant.expires_in === 'number' ? grant.expires_in : 0,
        });
        return;
      }
      case 'set-theme':
        // dataset, never setAttribute('style'), never a class name built from the string.
        if (isTheme(payload['value'])) handlers.onTheme(payload['value']);
        return;
      case 'set-locale':
        // Matched against the bot's configured list downstream; an unmatched value falls back to
        // the bot default rather than being applied.
        if (typeof payload['locale'] === 'string' && payload['locale'].length <= 35) {
          handlers.onLocale(payload['locale']);
        }
        return;
      case 'set-page-context': {
        const url = safePageUrl(payload['url']);
        const title = typeof payload['title'] === 'string' ? payload['title'].slice(0, 300) : '';
        if (url !== null) handlers.onPageContext(url, title);
        return;
      }
      case 'prefill': {
        const text = clampPrefill(payload['text']);
        if (text !== null) handlers.onPrefill(text);
        return;
      }
      case 'open':
      case 'close':
      case 'toggle':
        handlers.onVisibility(message.type);
        return;
      case 'destroy':
        handlers.onDestroy();
        return;
      default:
        return;
    }
  };

  addEventListener('message', onMessage);

  /**
   * THE FRAME SPEAKS FIRST, ALWAYS.
   *
   * A message posted to a frame before its document has installed a listener is DROPPED, not
   * queued, with no error at either end — which is why the loader cannot open the conversation and
   * why the frame's own `load` event is not a usable signal either (it fires for the "not
   * authorized for this domain" page too). Re-post because an `async` loader may attach its
   * listener after we are already up; 20 attempts at 250 ms covers a slow host page without
   * becoming a message loop.
   */
  toHost('ready');
  readyTimer = setInterval(() => {
    if (session !== null || ++tries > 20) {
      clearInterval(readyTimer);
      return;
    }
    toHost('ready');
  }, 250);

  return {
    toHost,
    requestSessionRefresh: () => toHost('session-expiring'),
    destroy: () => {
      destroyed = true;
      if (readyTimer !== undefined) clearInterval(readyTimer);
      removeEventListener('message', onMessage);
    },
  };
}
