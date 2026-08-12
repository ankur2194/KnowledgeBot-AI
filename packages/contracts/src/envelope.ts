import type { ErrorClass } from './error-classes.js';
import { isErrorClass } from './error-classes.js';

/**
 * The non-streaming HTTP error envelope, byte-identical in shape to the `error` SSE event's data,
 * so one parser serves both surfaces (kb-internal-api-contracts).
 *
 * `message` is OPERATOR-FACING. It is safe to log and is the string a support engineer greps for;
 * it is never rendered verbatim to a user.
 */
export interface KbErrorEnvelope {
  readonly error_class: ErrorClass;
  readonly message: string;
  /**
   * THE AUTHORITY on whether a retry is permitted — not the class name.
   *
   * For 16 of the 18 classes this is a lookup the client could have done itself. For
   * `internal_dependency` it is not: that row has a `self` sub-case (an unmapped exception in our
   * own code) that renders 500/`false` where the `downstream` sub-case renders 503/`true`, and the
   * axis that separates them is deliberately NOT on the wire (ADR-029, finding O1). A client-side
   * allow-list keyed on `error_class` may only NARROW this flag; it may never stand in for it.
   */
  readonly retryable: boolean;
  /** Echoes `X-KB-Request-Id`. Absent on SSE `error` frames, present on HTTP envelopes. */
  readonly request_id?: string | null;
  /**
   * Present ONLY on `error_class: "validation"` — never null, never `{}` on any other class.
   * Keyed by input field name, already dot-pathed by Laravel ("retrieval.top_k",
   * "starter_questions.2"), which are valid react-hook-form names as-is.
   */
  readonly errors?: Readonly<Record<string, readonly string[]>>;
}

export interface KbValidationEnvelope extends KbErrorEnvelope {
  readonly error_class: 'validation';
  readonly errors: Readonly<Record<string, readonly string[]>>;
}

/** Structural guard. Anything that fails it is "no envelope parsed" — unknown, and permanent. */
export function isKbErrorEnvelope(value: unknown): value is KbErrorEnvelope {
  if (typeof value !== 'object' || value === null) return false;
  const candidate = value as Record<string, unknown>;
  return (
    isErrorClass(candidate['error_class']) &&
    typeof candidate['message'] === 'string' &&
    typeof candidate['retryable'] === 'boolean'
  );
}

export function isKbValidationEnvelope(value: unknown): value is KbValidationEnvelope {
  return (
    isKbErrorEnvelope(value) &&
    value.error_class === 'validation' &&
    typeof value.errors === 'object' &&
    value.errors !== null
  );
}
