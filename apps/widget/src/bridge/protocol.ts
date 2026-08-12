/**
 * The cross-document envelope, imported by BOTH documents. One shape, one source of truth.
 *
 * Nothing here touches the DOM, `window`, `location` or a `__KB_*` constant, so it is importable
 * from a Node unit test and from the 5 kB loader alike. It is also the only file in this app that
 * both bundles contain — keep it that way.
 */

// TYPE-ONLY, and that is load-bearing: `import type` erases entirely, so the loader still contains
// no `@kb/contracts` runtime code and its 5 kB budget is untouched. A value import of
// `ERROR_CLASSES` here would pull the package's runtime into the loader bundle
// (`preact-vite-library`: "the loader never imports it at all").
import type { ErrorClass } from '@kb/contracts';

/**
 * The ENVELOPE version, not the widget version.
 *
 * A customer pins `<script src=".../v1/kb-widget.js">` and may hold that build in their CDN and
 * their visitors' caches for months while the frame app deploys weekly, so the two halves are
 * permanently mismatched BY DESIGN. That is the entire reason this constant exists on day one:
 * versioning cannot be added later to a protocol already running on pages we do not control.
 *
 * Bump it only for a change no older loader could survive. Everything additive — a new `type`, a
 * new optional payload field — is absorbed by the "unknown type → ignore" rule below.
 */
export const KB = 1 as const;

export interface Envelope {
  readonly kb: typeof KB;
  /** The `crypto`-random channel id the loader generated once, put in the frame URL, and repeats
   *  in every envelope. It scopes the channel to THIS frame instance, which is what stops two
   *  widgets on one page — or a frame torn down and recreated — from answering each other. */
  readonly ch: string;
  readonly type: string;
  readonly payload?: unknown;
}

/**
 * `attachBridge`'s THIRD argument. Exported from here rather than declared inline so both call
 * sites are type-checked in CI: the signature is `attachBridge(frame, ch, options)` and calling it
 * with the frame alone leaves `ch` undefined, so every envelope fails `parse()`, the handshake
 * times out into `degrade()`, and the widget renders a launcher that does nothing — with no
 * exception and nothing in either console.
 */
export interface LoaderOptions {
  /** The PUBLIC bot id. Public by construction; it identifies a bot and authorizes nothing. */
  readonly botId: string;
  /**
   * The customer BACKEND's HMAC-signed end-user identity, drained from `window.kbq` — never a
   * `data-*` attribute, which any script on the host page can rewrite before we run. It is
   * verified server-side at mint; an identity claim arriving over the bridge is ignored entirely.
   */
  readonly userToken?: string | undefined;
  /** The §8.20 SDK event dispatcher. METADATA ONLY — ids, counts, durations, `error_class`. Never
   *  message text, citation excerpts or URLs, token usage, or cost. */
  readonly onEvent?: ((type: string, payload?: unknown) => void) | undefined;
}

/**
 * THE PAYLOAD OF THE §8.20 `error` SDK EVENT — the one event a stranger's analytics acts on.
 *
 * `retryable` IS MANDATORY, and it is mandatory because of ADR-029 (finding O1). `internal_dependency`
 * now splits on an `origin` axis: `downstream` renders 503/retryable, `self` renders 500/not-retryable.
 * The axis is deliberately NOT on the wire, so a class name alone no longer answers "may I retry" —
 * that one row means one of two opposite things, and a consumer reading only `error_class` has no way
 * to resolve it. Every other error surface in this repo states `retryable` explicitly
 * (`KbErrorEnvelope.retryable`, `KbError.retryable`); this one now does too.
 *
 * Declared as an interface rather than a loose object literal so the COMPILER is what forces the
 * field at every emit site. A future `onEvent('error', { error_class: 'x' })` is then a typecheck
 * failure, not a silently ambiguous payload on a customer's page.
 *
 * `error_class` is typed as the closed 18 (`kb-error-taxonomy`), so a nineteenth invented at a call
 * site does not compile either.
 *
 * METADATA ONLY. No `message` field: the envelope's `message` is OPERATOR-FACING and can carry an
 * internal hostname or raw upstream provider text, and this payload lands in a stranger's page.
 */
export interface SdkErrorPayload {
  readonly error_class: ErrorClass;
  /**
   * THE AUTHORITY on whether the host page may retry — never re-derived from `error_class`.
   * A consumer may narrow this further; it may never stand in for it.
   */
  readonly retryable: boolean;
  /** A closed, widget-local discriminator naming WHICH failure produced the class. Never free text,
   *  never anything derived from a message, a URL or an exception. */
  readonly reason?: 'frame_blocked' | 'mint_failed' | 'refresh_failed';
}

/**
 * One `console.warn` per document for an unrecognised envelope version, not one per message: the
 * host page is hostile and can post in a loop, and the console it floods is the CUSTOMER's.
 */
let warnedUnknownVersion = false;

/**
 * STRUCTURE ONLY. The caller has ALREADY checked `event.source`, THEN `event.origin === <constant>`
 * — that order is the contract, and this function cannot enforce it because it never sees the
 * MessageEvent.
 *
 * What it does enforce: the envelope version, the channel id, and a closed allow-list of `type`.
 * What it deliberately does NOT enforce is the payload's value domain — a 1 MB `prefill` string
 * and `set-theme: "<img onerror=…>"` both parse cleanly here and are rejected by the handler that
 * knows what those fields mean. Value-domain guards live next to their use, because a guard far
 * from its field is a guard that stops matching it.
 *
 * Never throws. An exception raised inside a `message` listener on the host page lands in the
 * CUSTOMER's console with our filename on it.
 */
export function parse(data: unknown, ch: string, ok: ReadonlySet<string>): Envelope | null {
  // Other SDKs on the page post strings, and one of them posts `"webpackHotUpdate"`.
  if (typeof data !== 'object' || data === null) return null;
  // A document that never learned its own channel id accepts nothing. Without this, a frame whose
  // `?ch=` was stripped would match every envelope carrying `ch: ''`.
  if (ch === '') return null;

  const envelope = data as Record<string, unknown>;

  if (envelope['kb'] !== KB) {
    if (typeof envelope['kb'] === 'number' && !warnedUnknownVersion) {
      warnedUnknownVersion = true;
      console.warn(`[kb] ignoring envelope version ${envelope['kb']}; this build speaks ${KB}`);
    }
    return null;
  }

  // Full-string equality. `==` here, or a check dropped "because it was always undefined in dev",
  // turns the channel id into decoration.
  if (typeof envelope['ch'] !== 'string' || envelope['ch'] !== ch) return null;

  const type = envelope['type'];
  // Unknown type → ignore, silently. This is what lets the frame app add an event while a loader
  // from eighteen months ago is still live on a customer's page.
  if (typeof type !== 'string' || !ok.has(type)) return null;

  return envelope as unknown as Envelope;
}
