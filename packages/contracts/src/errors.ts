import type { ClientErrorClass } from './error-classes.js';
import { isKbErrorEnvelope } from './envelope.js';

/**
 * The ONE KbError. Declared here and nowhere else: a per-app copy breaks `instanceof`, and the
 * retry predicate in apps/web/src/lib/query/client.ts then treats every error as permanent,
 * silently.
 *
 * NOT enforced by anything today. There is no lint rule for it, and gates.yml — which DOES have
 * steps over apps/ and packages/ (`boundary-greps` bans `'use server'` under apps/web;
 * `repo-artifact-consistency` diffs packages/design-tokens/generated and the two SSE fixture
 * servers) — has no check for a duplicated KbError. Reviewer check, anchored on the DECLARATION so
 * it does not match prose mentioning the rule (the unanchored `rg "class KbError" apps/ packages/`
 * also matched this comment):
 *
 *   grep -rnE '^export class KbError' apps/ packages/   # exactly one hit, this file (verified)
 *
 * Field names are the envelope's, verbatim and snake_case, because each is a straight carry of
 * `{error_class, message, retryable, request_id}` plus one response header. There is no rename
 * layer for a typo to hide in: `error.errorClass` reads `undefined`, `SET.has(undefined)` is
 * `false`, and every retryable class becomes a dead end with no error anywhere.
 */
export class KbError extends Error {
  constructor(
    /** One of the 18 (kb-error-taxonomy), the client-local `stream_lost`, or null when no
     *  envelope parsed. Null is unknown, and unknown is permanently non-retryable. */
    readonly error_class: ClientErrorClass,
    /** Straight carry of the envelope's `retryable`, and the AUTHORITY on retry — a predicate that
     *  re-derives this from `error_class` is wrong for `internal_dependency`, whose `self` sub-case
     *  renders 500/false while its `downstream` sub-case renders 503/true (ADR-029, finding O1).
     *  A client class allow-list may narrow this flag and must never replace it. */
    readonly retryable: boolean,
    /** SECONDS, taken off the `Retry-After` RESPONSE HEADER. It is not in the JSON envelope. */
    readonly retry_after: number | null = null,
    /** Echoes `X-KB-Request-Id`. The one identifier a user is ever shown. */
    readonly request_id: string | null = null,
    /** Operator-facing: it can carry an internal hostname or raw upstream provider text.
     *  Log it; never render it. Users see a class-mapped sentence plus `request_id`. */
    message?: string,
  ) {
    super(message ?? error_class ?? 'unknown');
    this.name = 'KbError';
  }
}

/**
 * `Retry-After` per RFC 9110 §10.2.3: either delta-seconds or an HTTP-date.
 * Returns seconds, or null when the header is absent or unparseable.
 *
 * The header is a FLOOR, not a hint — a client that retries inside the window it was told to wait
 * keeps consuming the provider's per-minute request slot and never leaves the rejection window.
 */
export function parseRetryAfter(header: string | null | undefined): number | null {
  if (header === null || header === undefined) return null;
  const raw = header.trim();
  if (raw === '') return null;

  // delta-seconds. A bare `Number()` would also accept "1e3", "0x10" and " 12 ".
  if (/^\d+$/.test(raw)) {
    const seconds = Number(raw);
    return Number.isSafeInteger(seconds) ? seconds : null;
  }

  const at = Date.parse(raw);
  if (Number.isNaN(at)) return null;
  // A date already in the past means "retry now", not "retry in the past".
  return Math.max(0, Math.ceil((at - Date.now()) / 1000));
}

/**
 * The three members `toKbError` reads, and nothing else.
 *
 * STRUCTURAL, NOT `Response`. `apps/mobile` streams through `expo/fetch`, which returns its own
 * WinterCG response type rather than the DOM `Response` — a nominal parameter would force a cast at
 * the one call site that matters most, the streaming path. `apps/web` passes a real `Response` and
 * satisfies this structurally.
 */
export interface ErrorResponseLike {
  readonly status: number;
  readonly headers: { get(name: string): string | null };
  json(): Promise<unknown>;
}

/**
 * A non-2xx response -> the ONE `KbError`. Declared here because the mapping is now needed
 * identically by `apps/web` and `apps/mobile`, and a per-app copy is how the two drift apart on the
 * part that has no visible symptom: `retry_after` comes off a HEADER, not the JSON, so an app that
 * forgets it retries inside the window it was told to wait, forever, with nothing logged.
 *
 * Built from the `{error_class, message, retryable, request_id}` envelope plus `retry_after` parsed
 * off the `Retry-After` RESPONSE HEADER. `retryable` is a STRAIGHT CARRY of the envelope's flag and
 * is never re-derived from the class name — `internal_dependency` renders `false` for its `self`
 * sub-case and `true` for `downstream`, and the axis is deliberately not on the wire (ADR-029).
 *
 * A response with no parseable envelope yields `error_class: null`: unknown, and unknown is
 * permanently non-retryable. Never invent a class to fill the slot, and in particular never reach
 * for `internal_dependency`, which is retryable AND pages — a malformed response would wake
 * somebody up.
 */
export async function toKbError(response: ErrorResponseLike): Promise<KbError> {
  // BOTH read BEFORE the body, and for the same reason: `json()` can reject, and the headers are
  // valid either way. `X-KB-Request-Id` is the ONLY thing that survives the failures people
  // actually have to debug — a proxy's 502 page, a truncated body, a response that never had an
  // envelope — and it is the string a support engineer greps across both planes.
  const retrySeconds = parseRetryAfter(response.headers.get('retry-after'));
  const headerRequestId = normalizeRequestId(response.headers.get('x-kb-request-id'));

  let payload: unknown;
  try {
    payload = await response.json();
  } catch {
    payload = null;
  }

  if (isKbErrorEnvelope(payload)) {
    return new KbError(
      payload.error_class,
      payload.retryable,
      retrySeconds,
      // THE ENVELOPE WINS, and the header is the fallback — not the other way round. Both are
      // minted by the same middleware, so they agree by construction; when they do NOT, the
      // envelope's value was written by the code that classified the failure and logged it under
      // that id, while the header can be re-stamped by any hop in between (a relay, a reverse
      // proxy minting its own). The more specific, application-authored value is the one support
      // can grep for.
      //
      // `??`, NOT `||`: an envelope carrying `request_id: null` explicitly is not a disagreement,
      // it is an ABSENCE — FastAPI's authentication-failure envelopes are exactly that today
      // (verify_hmac runs before request_context stamps the id), which is the one case where the
      // header is all there is. An empty string would be an absence too, which is why
      // normalizeRequestId returns null for one rather than letting `??` keep it.
      payload.request_id ?? headerRequestId,
      payload.message,
    );
  }

  // `HTTP 503` is a MESSAGE, not a class. It is operator-facing like every other message on this
  // type and is never rendered — the user sees ERROR_COPY's `unknown` sentence.
  //
  // THIS BRANCH IS PRECISELY WHY THE HEADER IS READ. It exists for the proxy page and the truncated
  // body, and a hardcoded `null` here means the one failure a user is asked to report carries no
  // reference at all: `endUserCopy` drops the "(ref …)" suffix and support is handed "Something
  // went wrong" with nothing to search.
  return new KbError(null, false, retrySeconds, headerRequestId, `HTTP ${response.status}`);
}

/**
 * A request id, or null when the header is absent or blank.
 *
 * Trimmed and emptiness-checked for the same reason `parseRetryAfter` is: a header that is present
 * but empty is an absence, and `''` is truthy enough for `??` to keep it, producing `(ref )` in the
 * one sentence a user is asked to quote back.
 */
function normalizeRequestId(header: string | null | undefined): string | null {
  if (header === null || header === undefined) return null;
  const raw = header.trim();
  return raw === '' ? null : raw;
}
