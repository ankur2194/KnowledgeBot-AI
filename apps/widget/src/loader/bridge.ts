import type { LoaderOptions, SdkErrorPayload, SessionGrant } from '../bridge/protocol.js';
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
 * `TypeError` from a `connect-src` that omits `api.<domain>` into the same `'rejected'`. The second
 * is arguably a transient transport failure. It is reported as `authentication`/not-retryable anyway,
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

/**
 * THE MINT ANSWERED, AND THIS BUILD CANNOT READ WHAT IT SAID.
 *
 * A 200 whose body does not satisfy the contract in `readSessionGrant` below. It is emitted for the
 * `ready` mint and for the `session-expiring` refresh alike, deliberately as ONE constant: the
 * remedy is identical (fix the server, or ship a loader that understands the new body) and it is
 * ours in both cases, whereas `mint_failed`/`refresh_failed` are the customer's embedding
 * configuration. Timing already distinguishes the two for anyone who cares — one arrives before the
 * widget ever works, the other about fifteen minutes in.
 *
 * WHY `authentication`, walking `kb-error-taxonomy`'s 18 rows rather than picking a plausible name:
 *
 *   - The OUTCOME is exactly the row's: the widget holds no valid session credential. Client status
 *     401, retryable NO, fallback no, page no — all four columns are right, and they are the same
 *     four the two constants above already claim for the same outcome reached another way.
 *   - `internal_dependency` is the tempting one and it is wrong for the reasons degrade.ts already
 *     wrote out at length: its row is about a dependency being UNREACHABLE, and this one answered
 *     200 in time. It is `bounded` retryable, which is a backoff ladder against a request that
 *     cannot succeed on any attempt. And after ADR-029 it is the single class name that no longer
 *     answers "may I retry" at all — `self` renders 500/not-retryable, `downstream` 503/retryable —
 *     so it is simultaneously the wrong class and the most ambiguous one available.
 *   - `validation` (422) claims the REQUEST was malformed. The request was fine; the response was
 *     not, and telling a customer their loader sent something invalid would send them hunting in
 *     the one place there is nothing to find.
 *
 * The taxonomy stays at 18. What is new is the `reason`, which is what `reason` is for.
 *
 * Frozen for the same reason as its neighbours: `emitSdkEvent` hands this object BY REFERENCE to
 * every handler the host page registered, and `readonly` erases at compile time.
 */
export const SESSION_MALFORMED: SdkErrorPayload = Object.freeze({
  error_class: 'authentication',
  retryable: false,
  reason: 'session_malformed',
});

/**
 * ═══ THE MINT RESPONSE IS WRAPPED, AND THIS IS THE ONLY PLACE IT IS UNWRAPPED ═══════════════
 *
 * `POST sdk/v1/session` answers `{"data": {"token": "kbw_…", "expires_in": 900}}`. The wrapper is
 * not this endpoint's quirk and is not negotiable from the client: `DumpOpenApiCommand` refuses to
 * publish an operation whose response has no `data` key, so EVERY published operation on that
 * surface is wrapped. The client is the side that adapts.
 *
 * IT IS STRICT, AND IN PARTICULAR IT DOES NOT ALSO ACCEPT THE UNWRAPPED SHAPE.
 *
 * `data.token ?? body.token` is the tempting "be liberal" version and it is how this defect returns:
 * a client that silently accepts both shapes can never tell anyone the server moved, so the next
 * envelope change is silent too — and it would make the regression test below untestable, because
 * the old body would keep passing. A body that does not match is a REFUSAL with a named reason.
 *
 * WHY EVERY FIELD IS CHECKED HERE, AT THE BOUNDARY. The original defect read `token` at the top
 * level and got `undefined`, and `undefined` DOES NOT THROW. It travelled: over the bridge, into
 * the frame, toward a header reading the literal `Bearer undefined` — 401 on every request
 * afterwards, with nothing in the host page's console and nothing in the frame's. That is
 * indistinguishable from a wrong bot id and from an unlisted origin, both of which are deliberately
 * silent 404s on this surface, so there was nothing to tell the three cases apart. A value that
 * cannot be validated must not leave this function.
 *
 * `expires_in` is validated as strictly as the token, and it is not decoration. The frame's `init`
 * handler substitutes `0` for a non-number, which sets its expiry to "now": `isExpiringSoon()` is
 * then true on the first tick and the proactive refresh becomes a loop against the `sdk-bootstrap`
 * composite limiter. `NaN` is the mirror image — every comparison against it is false, so the token
 * is never renewed at all and the conversation dies at the real expiry.
 *
 * Exported so the unit layer asserts THIS function against a wrapped fixture and against the
 * unwrapped one, rather than a re-typed copy of the parser it is checking.
 */
export function readSessionGrant(body: unknown): SessionGrant | null {
  if (typeof body !== 'object' || body === null) return null;
  const data = (body as { data?: unknown }).data;
  if (typeof data !== 'object' || data === null) return null;
  const grant = data as { token?: unknown; expires_in?: unknown };
  // Non-EMPTY string: `''` passes a `typeof` check and yields `Bearer ` with nothing after it,
  // which is the same silent 401 wearing a different costume.
  if (typeof grant.token !== 'string' || grant.token === '') return null;
  if (
    typeof grant.expires_in !== 'number' ||
    !Number.isFinite(grant.expires_in) ||
    grant.expires_in <= 0
  ) {
    return null;
  }
  // A fresh object literal, so nothing else the body carried — a field we do not understand, a
  // prototype-shaped key — crosses the bridge with it.
  return { token: grant.token, expires_in: grant.expires_in };
}

/**
 * ONE `console.warn` per document, and the console it lands in is the CUSTOMER's — which is exactly
 * why it is latched. `no-console` in eslint.config.mjs allows `warn` and nothing else, and this
 * matches the two existing uses (`[kb] already loaded …`, `[kb] ignoring envelope version …`).
 *
 * It is the SECOND channel, not the first: the §8.20 `error` event carrying `SESSION_MALFORMED` is
 * what the customer's analytics acts on. This line exists because the failure it names is the one
 * where a human opening devtools previously saw nothing at all, on either side of the frame.
 *
 * Module scope rather than per-bridge: one message per document is the promise, and a page that
 * tore down and re-created the widget in a loop would otherwise get one per instance.
 */
let warnedMalformed = false;

function malformedSession(): 'malformed' {
  if (!warnedMalformed) {
    warnedMalformed = true;
    console.warn('[kb] session mint returned a body this loader cannot read; chat is unavailable');
  }
  return 'malformed';
}

/** Test seam for the module-level latch above, which is per-document and has no natural reset in a
 *  Node unit run. Never called by the loader. */
export function resetMalformedWarningForTest(): void {
  warnedMalformed = false;
}

/**
 * WHY A MINT PRODUCED NO SESSION. Two values, because they have two different owners.
 *
 * `rejected` — the server said no, or the request never arrived. Every SDK rejection is a 404 with
 * a byte-identical body, and a `connect-src` that omits api.<domain> is a `TypeError` with no
 * status at all; both are folded here, and the KNOWN IMPRECISION note on MINT_FAILED explains why
 * that fold is deliberate. The remedy is the customer's embedding configuration.
 *
 * `malformed` — the server answered 200 and this build could not read the body. The remedy is ours.
 *
 * A string union rather than a discriminated object: `typeof outcome === 'string'` narrows
 * `SessionGrant | MintFailure` in one comparison, and this file is billed against a 5 kB budget the
 * customer's page pays on every navigation.
 */
type MintFailure = 'rejected' | 'malformed';

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
  const mint = async (): Promise<SessionGrant | MintFailure> => {
    let response: Response;
    try {
      response = await fetch(`${__KB_API_ORIGIN__}/sdk/v1/session`, {
        method: 'POST',
        mode: 'cors',
        // No cookie is wanted or usable here, and `include` would make the CORS check stricter for
        // no benefit.
        credentials: 'omit',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ bot_id: options.botId, user_token: options.userToken ?? null }),
      });
    } catch {
      // A customer whose `connect-src` omits api.<domain> gets a TypeError here, not a status.
      return 'rejected';
    }

    if (!response.ok) return 'rejected';

    let body: unknown;
    try {
      body = await response.json();
    } catch {
      // A 200 that is not JSON at all: a captive portal, an intercepting proxy, an HTML error page
      // from something sitting in front of Laravel. Unreadable is unreadable — same treatment as a
      // wrong shape, and emphatically not "rejected", which would send the customer looking at
      // their allow-list for a problem that is not there.
      return malformedSession();
    }

    // THE ONE UNWRAP, reached by BOTH call sites below. Doing it at only one of them yields a
    // widget that works until the first renewal and then dies just as quietly as before.
    const grant = readSessionGrant(body);
    return grant ?? malformedSession();
  };

  /**
   * Emit the right `error` payload for a mint that produced no session, WITHOUT re-deciding
   * anything: the failure mode was already determined at the boundary, and `whenRejected` is only
   * which of the two customer-facing constants applies at this call site.
   */
  const reportMintFailure = (failure: MintFailure, whenRejected: SdkErrorPayload): void => {
    options.onEvent?.('error', failure === 'malformed' ? SESSION_MALFORMED : whenRejected);
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
      void mint().then((outcome) => {
        if (destroyed) return;
        if (typeof outcome === 'string') {
          reportMintFailure(outcome, MINT_FAILED);
          return;
        }
        // A validated grant, or nothing. `outcome` is a fresh object this file built field by
        // field, so what crosses the bridge is exactly `{token, expires_in}` and never whatever
        // else the response body happened to carry.
        send('init', { session: outcome, locale: navigator.language });
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
      refreshing = mint().then((outcome) => {
        refreshing = null;
        if (destroyed) return;
        if (typeof outcome === 'string') {
          reportMintFailure(outcome, REFRESH_FAILED);
          return;
        }
        // THE SECOND CALL SITE, and the reason the unwrap lives inside `mint()` rather than beside
        // the first one. A fix applied only at `ready` produces a widget that works for the length
        // of one grant and then dies exactly as silently as it used to.
        send('session', { session: outcome });
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
