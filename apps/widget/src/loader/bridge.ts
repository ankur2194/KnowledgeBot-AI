import type { LoaderOptions, SdkErrorPayload } from '../bridge/protocol.js';
import { KB, parse } from '../bridge/protocol.js';

/**
 * The host-page half of the bridge. This code runs on the CUSTOMER's origin, in a document we do
 * not control, alongside scripts we have never seen.
 *
 * It carries NO AUTHORITY. Authority is the opaque, origin-bound session token Laravel mints; this
 * file transports it once and then transports presentation requests. No message here sets
 * organization, bot, end-user identity, provider, model, retrieval scope or the origin allow-list.
 */

/**
 * The closed allow-list of types the FRAME may send. Note `resize` and `session-expiring` point
 * frame → host: only the frame knows its content height, and only the frame sees a 401.
 *
 * The remainder are the §8.20 SDK events, forwarded to the customer's `onEvent` verbatim. They are
 * metadata only — ids, counts, durations, `error_class`. `kb-internal-api-contracts`' never-forward
 * list (`provider.usage`, `retrieval.trace`, `provider.fallback`) binds this channel exactly as it
 * binds the SSE relay, and so does the rule that no message text or citation URL crosses it.
 */
const FROM_FRAME: ReadonlySet<string> = new Set([
  'ready',
  'resize',
  'session-expiring',
  'error',
  'widget.opened',
  'widget.closed',
  'conversation.started',
  'message.sent',
  'response.completed',
  'citation.opened',
  'feedback.submitted',
]);

/**
 * The mint failed. `authentication` is `kb-error-taxonomy`'s row for a rejected credential, and that
 * row is `retryable: no` — STATED, not left to the consumer, because after ADR-029 a class name is
 * no longer a retry answer and this payload lands in a stranger's analytics with no envelope beside
 * it to consult.
 *
 * "Not retryable BY THE HOST PAGE" is the claim, and it is right on both call sites: a rejected mint
 * is an allow-list or signed-identity decision that a second identical POST cannot change, and the
 * loader has already spent the caller's `sdk-bootstrap` composite rate-limit slot making it. The
 * frame re-posts `ready` on its own schedule and the refresh path has its own single-flight gate;
 * neither wants the customer's analytics driving a retry loop.
 *
 * KNOWN IMPRECISION, flagged rather than silently widened: `mint()` collapses a 404 rejection and a
 * `TypeError` from a `connect-src` that omits `api.<domain>` into the same `null`. The second is
 * arguably a transient transport failure. It is reported as `authentication`/not-retryable anyway,
 * because guessing the other way hands a hostile page a retry ladder against our mint endpoint, and
 * because the taxonomy's own rule is that an unknown failure classifies as permanent, never
 * temporary. Splitting them needs `mint()` to distinguish a status from a throw, which is a
 * behaviour change, not a field.
 *
 * Module scope and EXPORTED so the unit layer asserts the exact object the loader emits, rather than
 * a re-typed copy of it. A test that restates the literal it is checking proves only that someone
 * can type it twice.
 *
 * `Object.freeze` because `emitSdkEvent` hands this BY REFERENCE to every handler the host page
 * registered, and `readonly` erases at compile time. One handler writing `p.retryable = true` to a
 * shared module-scope constant would poison every later event emitted from it.
 */
export const MINT_FAILED: SdkErrorPayload = Object.freeze({
  error_class: 'authentication',
  retryable: false,
  reason: 'mint_failed',
});

/** Same class, same retry answer, different `reason`: the host page can tell a failed first mint
 *  from a failed token renewal without either being retryable. */
export const REFRESH_FAILED: SdkErrorPayload = Object.freeze({
  error_class: 'authentication',
  retryable: false,
  reason: 'refresh_failed',
});

export interface BridgeHandle {
  open(): void;
  close(): void;
  toggle(): void;
  setTheme(value: 'light' | 'dark' | 'auto'): void;
  prefill(text: string): void;
  /** Idempotent. Removes the listener so a `destroy()`d widget cannot be revived by a message. */
  destroy(): void;
}

/**
 * THREE ARGUMENTS, and the arity is the contract.
 *
 * `ch` is the same uuid the loader put in the frame URL. Call this with the frame alone and `ch`
 * arrives `undefined`, so `parse()` rejects every envelope, the handshake times out into
 * `degrade()`, and the widget renders a launcher that does nothing — no exception, nothing in
 * either console. `o` arriving `undefined` throws on the first `ready` instead. Both symptoms are
 * the same defect, which is why `LoaderOptions` is exported from protocol.ts and both call sites
 * are type-checked in CI.
 */
export function attachBridge(
  frame: HTMLIFrameElement,
  ch: string,
  options: LoaderOptions,
): BridgeHandle {
  let initialised = false;
  let destroyed = false;
  /** One re-mint at a time. Two simultaneous triggers must produce one mint, not two — two would
   *  burn the `sdk-bootstrap` composite rate limit and orphan a session. */
  let refreshing: Promise<void> | null = null;

  /**
   * EXPLICIT TARGET ORIGIN, ALWAYS.
   *
   * `'*'` delivers to whatever document currently occupies the frame — including one the host page
   * swapped in after we created it. The constant is baked by Vite `define`; it is never read from
   * `frame.src`, which the host page can rewrite.
   */
  const send = (type: string, payload?: unknown): void => {
    frame.contentWindow?.postMessage({ kb: KB, ch, type, payload }, __KB_WIDGET_ORIGIN__);
  };

  /**
   * The ONE request in the entire system that carries an unforgeable `Origin:
   * https://customer.example`. The browser sets it and page script cannot forge it — which is why
   * the LOADER mints and the frame never can: an XHR from the frame carries
   * `Origin: https://<widget-domain>`, our own origin, proving nothing about who is embedding us.
   *
   * `__KB_API_ORIGIN__` is `https://api.<domain>`, on the MAIN registrable domain, deliberately not
   * a neighbour of `__KB_WIDGET_ORIGIN__`.
   *
   * Every SDK rejection is a 404 with a byte-identical body (`laravel-sanctum-auth`). Never branch
   * on the status beyond ok/not-ok — a 403 on a foreign id confirms the row exists.
   *
   * THE PREFIX IS `sdk/v1`, NOT `api/v1`, AND THE DIFFERENCE IS NOT COSMETIC.
   *
   * `services/core-api/bootstrap/app.php` mounts four disjoint groups: `api/v1` (admin, the ONLY
   * group on Laravel's `api` middleware group, which `statefulApi()` prepends
   * EnsureFrontendRequestsAreStateful to), `rt/v1` (public chat runtime), `sdk/v1` (this one) and
   * `internal/v1`. Posting this mint to `api/v1/...` would hand a request made from a HOSTILE
   * customer page to the admin group's session, CSRF and cookie stack — the exact inheritance the
   * four-group split exists to make impossible.
   *
   * `sdk/*` sits outside `api/` deliberately, and `services/core-api/config/cors.php` lists it by
   * name for that reason: Laravel 11+ leaves cors.php unpublished with `paths` defaulting to
   * ['api/*', 'sanctum/csrf-cookie'], so this route needs an explicit entry or the browser rejects
   * the preflight before any controller runs and the server log is empty.
   *
   * The route itself does not exist yet (routes/api_sdk.php is a TODO). The prefix is fixed here
   * anyway, because a wrong path with a green fixture over it is how "unreachable" becomes
   * "confidently wrong" — the harness faked the wrong path too, so the whole e2e suite was green
   * over it.
   */
  const mint = async (): Promise<{ token: string; expires_in: number } | null> => {
    try {
      const response = await fetch(`${__KB_API_ORIGIN__}/sdk/v1/session`, {
        method: 'POST',
        mode: 'cors',
        // No cookie is wanted or usable here, and `include` would make the CORS check stricter for
        // no benefit.
        credentials: 'omit',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ bot_id: options.botId, user_token: options.userToken ?? null }),
      });
      if (!response.ok) return null;
      return (await response.json()) as { token: string; expires_in: number };
    } catch {
      // A customer whose `connect-src` omits api.<domain> gets a TypeError here, not a status.
      return null;
    }
  };

  const onMessage = (event: MessageEvent): void => {
    if (destroyed) return;

    // 1. SOURCE FIRST. Origin names which ORIGIN spoke, not which WINDOW. Any frame on the
    //    customer's own origin — their ad slot, their tag manager, an `about:blank` frame they
    //    created, which INHERITS their origin rather than being opaque — passes an origin-only
    //    test and can drive this whole protocol. `event.source` is a WindowProxy set by the
    //    browser from the actual sending context and cannot be forged.
    if (event.source !== frame.contentWindow) return;

    // 2. THEN ORIGIN, full-string `===` against one baked constant.
    //    A prefix test admits https://<widget-domain>.attacker.net; a suffix test admits
    //    https://evil<widget-domain>; an unanchored regex admits both; and OWASP names the
    //    index-of form "very insecure". There is NO substring form of this check that is safe.
    //    eslint.config.mjs bans every one of them by name, and CI greps this file for them —
    //    which is why the method names are described here rather than written out.
    if (event.origin !== __KB_WIDGET_ORIGIN__) return;

    // 3. THEN STRUCTURE. Nothing below reads a payload field before this returns non-null.
    const message = parse(event.data, ch, FROM_FRAME);
    if (message === null) return;

    if (message.type === 'ready') {
      // Exactly one `init` per frame instance. The frame re-posts `ready` every 250 ms up to 20
      // times, because an `async` loader may attach this listener after the frame is already up —
      // so duplicate `ready`s are NORMAL, and re-initialising on one is a state-machine reset an
      // attacker would enjoy.
      if (initialised) return;
      initialised = true;
      void mint().then((session) => {
        if (destroyed) return;
        if (session === null) {
          options.onEvent?.('error', MINT_FAILED);
          return;
        }
        send('init', { session, locale: navigator.language });
      });
      return;
    }

    if (message.type === 'resize') {
      // Untrusted arithmetic. Clamp before it reaches a style property: `height: NaNpx` is
      // ignored, `height: 1e9px` is a scrollbar the customer cannot get rid of.
      const height = (message.payload as { height?: unknown } | undefined)?.height;
      if (typeof height === 'number' && Number.isFinite(height)) {
        frame.style.height = `${Math.min(Math.max(Math.round(height), 96), innerHeight - 32)}px`;
      }
      return;
    }

    if (message.type === 'session-expiring') {
      // A DISTINCT TYPE, not a second `init`: the one-`init`-per-frame rule stays untouched,
      // because a token swap is not a state-machine reset.
      //
      // Nothing in the outgoing request comes from this message. It is a ping, not a request with
      // parameters — the body is rebuilt from our own boot configuration, and the POST is made
      // fresh from the customer's page so the `Origin` is produced AT REFRESH TIME rather than
      // replayed from mint time.
      if (refreshing !== null) return;
      refreshing = mint().then((session) => {
        refreshing = null;
        if (destroyed) return;
        if (session === null) {
          options.onEvent?.('error', REFRESH_FAILED);
          return;
        }
        send('session', { session });
      });
      return;
    }

    // Everything else is a §8.20 SDK event on its way to the customer's analytics. Metadata only.
    options.onEvent?.(message.type, message.payload);
  };

  addEventListener('message', onMessage);

  return {
    open: () => send('open'),
    close: () => send('close'),
    toggle: () => send('toggle'),
    setTheme: (value) => send('set-theme', { value }),
    prefill: (text) => send('prefill', { text }),
    destroy: () => {
      if (destroyed) return;
      destroyed = true;
      send('destroy');
      removeEventListener('message', onMessage);
    },
  };
}
