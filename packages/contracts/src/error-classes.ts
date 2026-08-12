/**
 * The 18 error classes (kb-error-taxonomy). This tuple is the closed set: `error_class` is the
 * row key in that table, the discriminator in the error envelope, and the input to every retry,
 * fallback and breaker decision on both sides of the wire.
 *
 * Do not add a 19th. A class that is not in this list is not a class — it is an unmapped failure,
 * and unmapped means unknown, and unknown is permanently non-retryable.
 *
 * One row — `internal_dependency` — has a second axis and therefore two renderings. See the block
 * below `ErrorClass`; the count is still 18 and a sub-case is not a new member.
 */
export const ERROR_CLASSES = [
  'validation',
  'authentication',
  'authorization',
  'tenant_quota',
  'rate_limit',
  'provider_auth',
  'provider_rate_limit',
  'provider_billing',
  'provider_temporary',
  'provider_permanent_request',
  'retrieval',
  'parsing',
  'ocr',
  'crawl',
  'vector_indexing',
  'storage',
  // Two sub-cases, one class: 503/retryable for a real dependency, 500/not-retryable for our own
  // unmapped exception. Read the envelope's `retryable`, never this name. See below.
  'internal_dependency',
  'user_cancellation',
] as const;

export type ErrorClass = (typeof ERROR_CLASSES)[number];

/**
 * `internal_dependency` HAS TWO SUB-CASES, AND ONLY THE ENVELOPE CAN TELL THEM APART.
 *
 * The server splits this row on a second axis it calls `origin` (ADR-029, finding O1) — the same
 * idiom `authorization` already uses with `Surface`, one row whose *rendering* depends on a second
 * input while the class itself stays put:
 *
 *   | origin                | meaning                                    | status | retryable |
 *   |-----------------------|--------------------------------------------|--------|-----------|
 *   | `downstream` (default)| a dependency of ours is briefly unavailable |  503   |  true     |
 *   | `self`                | an unmapped exception in OUR code — a defect|  500   |  false    |
 *
 * `origin` IS NOT A WIRE FIELD and never will be. It selects the status and the `retryable` value,
 * both of which the envelope already carries, and a client cannot act on the distinction beyond
 * those two. Do not look for it in a response body; do not add it to `KbErrorEnvelope`.
 *
 * WHAT THIS MEANS FOR CLIENT CODE, and it is the whole point of this comment: a retry predicate
 * that decides from `error_class` ALONE is now WRONG for this one row. Reading
 * `error_class === 'internal_dependency'` as retryable retries a bug on a full backoff ladder
 * against a request that cannot succeed on any attempt — which is precisely the defect O1 opened.
 *
 *   WRONG: RETRYABLE_CLASSES.has(error.error_class)
 *   RIGHT: RETRYABLE_CLASSES.has(error.error_class) && error.retryable
 *
 * The envelope's `retryable` is the AUTHORITY on whether a retry is permitted at all; a client-side
 * class allow-list may only NARROW it further (apps/web and apps/mobile both narrow it, on purpose:
 * `provider_temporary` is retryable by the FastAPI adapter, not by the browser). Narrowing is safe;
 * overriding it is the bug.
 *
 * The count is still exactly 18. This is a sub-case, not a nineteenth class — see the note on
 * ERROR_CLASSES above, which stands unchanged. Nothing is exported for it, deliberately: a client
 * has no `origin` to branch on, and an export would only invite one.
 */

const ERROR_CLASS_SET: ReadonlySet<string> = new Set<string>(ERROR_CLASSES);

export function isErrorClass(value: unknown): value is ErrorClass {
  return typeof value === 'string' && ERROR_CLASS_SET.has(value);
}

/**
 * A CLIENT-LOCAL sentinel, deliberately typed apart from `ErrorClass`.
 *
 * It is not a 19th class and nothing serializes it: a server that can still write a terminal event
 * knows *why* the stream ended and names one of the 18. `stream_lost` is for the one case the
 * server cannot report — no terminal event arrived at all, because the connection itself died
 * (kb-internal-api-contracts). Clients may raise it internally; it never reaches an `error_class`
 * field on the wire, a metric label, or a span attribute.
 */
export const STREAM_LOST = 'stream_lost';
export type StreamLost = typeof STREAM_LOST;

/**
 * What a `KbError.error_class` may hold on a client: one of the 18, the client-local sentinel, or
 * null when no envelope parsed. Null is unknown, and unknown is permanent.
 */
export type ClientErrorClass = ErrorClass | StreamLost | null;
