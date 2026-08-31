import type { KbErrorEnvelope } from '../envelope.js';
import type { SseFrame } from './frame.js';

/**
 * The SIX client-facing SSE events (kb-internal-api-contracts). This union is exactly what Laravel
 * forwards, and it is what the widget, hosted chat, mobile and the playground all parse.
 *
 * DELIBERATELY ABSENT: `provider.usage`, `retrieval.trace`, `provider.fallback` and `heartbeat`.
 * They are internal-only — Laravel consumes them and does not forward them — and a client that can
 * *name* them is a client that can render them. Cost data and internal topology stop at Laravel.
 */
export type KbEvent =
  | { readonly event: 'message.start'; readonly data: MessageStartData }
  | { readonly event: 'status'; readonly data: StatusData }
  | { readonly event: 'citations'; readonly data: CitationsData }
  | { readonly event: 'token'; readonly data: TokenData }
  | { readonly event: 'message.complete'; readonly data: MessageCompleteData }
  | { readonly event: 'error'; readonly data: KbErrorEnvelope };

export interface MessageStartData {
  readonly message_id: string;
  readonly conversation_id: string;
  readonly created_at: string;
}

/** Retrieval and rerank run for seconds before generation starts; this is what the UI shows
 *  during that window, and it is why the pre-token surface is testable at all. */
export interface StatusData {
  readonly stage: 'retrieving' | 'reranking' | 'generating';
}

export interface Citation {
  readonly index: number;
  readonly source_id: string;
  readonly source_version_id: string;
  readonly chunk_id: string;
  readonly title: string;
  readonly url: string | null;
  /**
   * The rerank score, or NULL WHEN STAGE 11 DID NOT RUN.
   *
   * ── IT WAS `number`, AND THAT WAS A LIE IN EXACTLY THE MODE THE PIPELINE IS DESIGNED TO SERVE ──
   * ADR-030 moved reranking onto the provider adapter layer, which made it CAPABILITY-GATED rather
   * than guaranteed: a bot whose provider cannot `rerank()` still answers, over a fused candidate
   * set selected by branch agreement instead of by a threshold (`bge-reranker`,
   * `app/rag/stages.py::ExclusionReason::NO_BRANCH_AGREEMENT`). That is the DEGRADED path, it is a
   * supported outcome rather than a failure, and on it there is no score to send — so a non-null
   * type promised a number the wire cannot always carry.
   *
   * A client that read `score` as a `number` on that path got `null` typed as `number`, and the two
   * ways that shows up are both silent: `score.toFixed(2)` throws inside a render, or
   * `score > 0.5` is `false` and a sources panel quietly sorts every citation to the bottom.
   *
   * NULL IS "NOT MEASURED", NOT "MEASURED AS ZERO". Render an em dash or omit the column; never
   * coerce it to 0, which is a real score on every scale this platform uses.
   */
  readonly score: number | null;
}

/** Emitted BEFORE the first token: citations are assigned from retrieved evidence pre-generation,
 *  never parsed out of model output (docs/07 §12.16–12.17). */
export interface CitationsData {
  readonly citations: readonly Citation[];
}

/** `text` and nothing else. This is the only high-frequency event and every extra key is paid
 *  per token. Note this is the ONE legitimate `text:` in the whole chat path — the request body
 *  field is `content`. */
export interface TokenData {
  readonly text: string;
}

export type FinishReason = 'stop' | 'length' | 'cancelled' | 'insufficient_evidence' | 'error';

export interface MessageCompleteData {
  readonly message_id: string;
  readonly finish_reason: FinishReason;
  readonly usage: {
    readonly prompt_tokens: number;
    readonly completion_tokens: number;
  };
}

/** Exactly one terminal event per stream: `message.complete` or `error`. Never both, never
 *  neither — and "neither" is the case the client-local `stream_lost` sentinel exists for. */
export const TERMINAL_EVENTS = ['message.complete', 'error'] as const;

export const CLIENT_EVENT_NAMES = [
  'message.start',
  'status',
  'citations',
  'token',
  'message.complete',
  'error',
] as const;

export type KbEventName = (typeof CLIENT_EVENT_NAMES)[number];

const CLIENT_EVENT_SET: ReadonlySet<string> = new Set<string>(CLIENT_EVENT_NAMES);

export function isKbEventName(name: unknown): name is KbEventName {
  return typeof name === 'string' && CLIENT_EVENT_SET.has(name);
}

/**
 * `{ readonly event: string }` AND NOT `KbEvent`, and the widening is deliberate rather than lax.
 *
 * The admin console consumes a SECOND, WIDER union (`@kb/contracts/admin`) that adds
 * `retrieval.trace` to these six, and `KbAdminEvent` is a superset union rather than a subtype — so
 * a parameter typed `KbEvent` would refuse it and the only repairs available would be a cast at the
 * call site or a second copy of this predicate. Both are worse than one structural parameter: the
 * question this answers is "is this frame's NAME one of the two terminal names", which is true of
 * anything carrying an `event` string and is decided by the closed set below either way.
 */
export function isTerminalEvent(event: { readonly event: string }): boolean {
  return event.event === 'message.complete' || event.event === 'error';
}

/**
 * Frame -> typed event, or `null` for anything this client has no business rendering: a frame with
 * no `event:` field, an unrecognised name, or unparseable JSON.
 *
 * The cast is deliberate and the boundary is documented: FastAPI's Pydantic models and Laravel's
 * relay are the authority on payload shape, and re-validating every token frame in the browser
 * costs one parse per token for a check the wire already made. What this DOES enforce is the event
 * NAME allow-list, which is the part with a security consequence.
 */
export function toKbEvent(frame: SseFrame): KbEvent | null {
  if (frame.event === null || !isKbEventName(frame.event)) return null;
  let data: unknown;
  try {
    data = JSON.parse(frame.data);
  } catch {
    return null;
  }
  if (typeof data !== 'object' || data === null) return null;
  return { event: frame.event, data } as KbEvent;
}
