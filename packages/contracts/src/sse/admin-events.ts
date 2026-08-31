import type { SseFrame } from './frame.js';
import type { KbEvent } from './events.js';
import { CLIENT_EVENT_NAMES, toKbEvent } from './events.js';

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *  THE ADMIN-ONLY SSE UNION. A SECOND UNION, ON PURPOSE — NOT `KbEvent` WITH ONE MORE MEMBER.
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `src/sse/events.ts:8` states the rule this module is built around, and states it as the reason
 * four frame names are absent from the published union: *"a client that can NAME them is a client
 * that can RENDER them. Cost data and internal topology stop at Laravel."*
 *
 * ── WHY `retrieval.trace` CANNOT JOIN `KbEvent` ─────────────────────────────────────────────────
 * `KbEvent` is imported by `apps/widget`, which runs inside an iframe on a page the CUSTOMER
 * controls and we do not. Adding `retrieval.trace` to it would put the name, the payload type and —
 * through `CLIENT_EVENT_NAMES`, which `toKbEvent` uses as its allow-list — the PARSE PATH into every
 * widget bundle on the internet. The trace carries candidate chunk ids, per-branch scores, the
 * resolved tenant filter naming the organization and every allowed version id, and the pipeline's
 * stage timings. A widget build that can name it is one relay bug away from rendering internal
 * topology on a stranger's marketing site.
 *
 * So the widening is a SEPARATE MODULE behind a SEPARATE SUBPATH (`@kb/contracts/admin`), reached by
 * exactly one consumer: `apps/web`'s admin playground. `apps/widget` cannot acquire it by editing an
 * import in a file it already has; it would have to add a new specifier, which is a review stop.
 *
 * ── THE DROP HAPPENS IN `toKbEvent()`, NEVER IN `parseFrame()` ──────────────────────────────────
 * `parseFrame` implements the WHATWG event-stream field rules, and its `default:` arm ignores
 * unknown FIELDS by design — that is the spec's behaviour and changing it would break the parser for
 * every client. What refuses an unknown event NAME is `toKbEvent`'s `isKbEventName` allow-list, one
 * layer up. This module therefore composes `toKbEvent` rather than forking anything: one frame
 * parser, one field-level reader, two name allow-lists.
 *
 * ── LARAVEL IS THE ENFORCEMENT AND IT IS ALREADY FAIL-CLOSED ────────────────────────────────────
 * `App\Support\Kb\ClientEvents::allows()` forwards `retrieval.trace` only when
 * `$name === DIAGNOSTIC && $actor === ActorType::User && $diagnostics` — an allow-list AND-ed with
 * an actor type resolved from the credential, defaulting to `false`. This union does not grant
 * anything; it lets one client READ what that gate may choose to send. A client-side type is never a
 * control.
 *
 * ── STATE OF THE SERVER, MEASURED 2026-08-27, BECAUSE IT CHANGES WHAT THIS TYPE IS FOR TODAY ────
 * No deployed path emits `retrieval.trace` to any client yet:
 *
 *   `services/core-api/app/Http/Controllers/Api/V1/Runtime/StreamChatMessageController.php:170`
 *       constructs its `ChatContext` with `diagnostics: false`, hard-coded, with a comment saying
 *       "The admin playground is what passes `true`, from a different credential."
 *   `services/core-api/app/Services/Sdk/WidgetSession.php:55`
 *       defaults `actorType` to `ActorType::AnonymousSession` and its docblock says the `user` path
 *       "is not built, so the value is the fail-closed one".
 *   `grep -rn 'ClientEvents::allows' services/core-api/app` → that controller and nothing else.
 *
 * So the playground's panel is written against this union and will light up the day the control
 * plane adds the admin-credentialed relay. Until then the panel says so in place rather than
 * rendering an empty table that reads as "this bot retrieved nothing".
 */

/** The one internal frame an admin actor may be sent. Byte-identical to `ClientEvents::DIAGNOSTIC`. */
export const RETRIEVAL_TRACE_EVENT = 'retrieval.trace' as const;

/**
 * The trace payload, and it is deliberately OPEN.
 *
 * `services/ai-service/app/contracts/internal/chat.py::RetrievalTraceFrame` types its `trace` as
 * `dict[str, Any]` and says why in as many words: the shape is `app/rag/runner.py::RetrievalTrace`,
 * *"which is the pipeline's own record and changes with the pipeline; a second transcription of it
 * here would be a second thing to keep in step, and the consumer is one admin panel rather than
 * three clients."*
 *
 * This module honours that. What it declares is the SUBSET §8.24 requires a panel to show — the
 * candidate table with all five numbers, the exclusions with their reasons, the packed order, and
 * the two facts that explain a degraded run — with every member OPTIONAL and every collection
 * defensively read. A field that moves on the data plane makes a cell read "—", never a crash.
 *
 * DO NOT ADD A REQUIRED MEMBER HERE. A required field is a promise about a shape whose author has
 * explicitly declined to pin it, and the failure mode is a panel that throws on the first pipeline
 * change nobody told this package about.
 */
export interface RetrievalTraceData {
  readonly trace?: RetrievalTrace;
}

/** One retrieved candidate, with every score the panel is required to show (§8.24). */
export interface RetrievalTraceCandidate {
  readonly chunk_id?: string;
  readonly source_id?: string | null;
  /**
   * `null` FOR A CANDIDATE THE OTHER BRANCH DID NOT FIND, and the asymmetry is the most diagnostic
   * field in the panel — `CandidateRow`'s own docstring calls it that and adds "Never fill it in
   * from anywhere." A missing rank is rendered as absent, never as rank 0 or as a score of 0.
   */
  readonly dense_rank?: number | null;
  readonly dense_score?: number | null;
  readonly sparse_rank?: number | null;
  readonly sparse_score?: number | null;
  readonly fused_score?: number | null;
  /** `null` on the degraded path, where stage 11 never ran. Same rule as `Citation.score`. */
  readonly rerank_score?: number | null;
  readonly rerank_scale?: string | null;
}

/**
 * One DROPPED candidate: what, why, at which stage, and with what score.
 *
 * `reason` is typed `string` and not a union even though the data plane's `ExclusionReason` is a
 * closed `StrEnum`. That is the same call `RetrievalTraceData` makes and for the same reason — the
 * vocabulary belongs to `app/rag/stages.py` and a transcription here is a second thing to keep in
 * step — and the panel's own copy map falls back to the raw wire word, which is the honest answer
 * for a reason this build has never heard of.
 */
export interface RetrievalTraceExclusion {
  readonly chunk_id?: string;
  readonly reason?: string;
  readonly score?: number | null;
  readonly stage?: string;
}

export interface RetrievalTrace {
  readonly retrieval_configuration_version?: string;
  readonly original_query?: string;
  readonly rewritten_query?: string | null;
  readonly retrieval_query?: string;
  readonly branches_queried?: readonly string[];
  readonly fusion_k?: number;
  readonly candidates?: readonly RetrievalTraceCandidate[];
  /** §8.24 requires excluded results AND THEIR REASONS. This is that half, and it is not optional
   *  in the product sense — only in the "the wire may not carry it yet" sense. */
  readonly exclusions?: readonly RetrievalTraceExclusion[];
  /** `[chunk_id, label]` pairs, in the order the model READ the evidence. */
  readonly packed_order?: readonly (readonly [string, string])[];
  readonly labels?: Readonly<Record<string, string>>;
  /** Why stage 11 did not run. Present exactly when reranking was skipped, which is the degraded
   *  path ADR-030 made ordinary rather than exceptional. */
  readonly rerank_skip_reason?: string | null;
  readonly rerank_scale?: string | null;
  readonly insufficient_evidence?: boolean;
  readonly embedding_provider?: string | null;
  readonly embedding_model?: string | null;
  readonly timings?: Readonly<Record<string, number>>;
  readonly [key: string]: unknown;
}

/**
 * THE ADMIN UNION: the six client-facing frames, plus the diagnostic one.
 *
 * It is a SUPERSET of `KbEvent` rather than a subtype, so anything typed `KbEvent` is assignable
 * here and nothing typed `KbAdminEvent` is assignable to `KbEvent`. That direction is the one that
 * matters: a value carrying a trace can never be passed to a function that promises to handle only
 * the public six.
 */
export type KbAdminEvent =
  | KbEvent
  | { readonly event: typeof RETRIEVAL_TRACE_EVENT; readonly data: RetrievalTraceData };

export const ADMIN_EVENT_NAMES = [...CLIENT_EVENT_NAMES, RETRIEVAL_TRACE_EVENT] as const;

export type KbAdminEventName = (typeof ADMIN_EVENT_NAMES)[number];

/**
 * Frame -> typed admin event, or `null` for anything even an admin client has no business
 * rendering.
 *
 * `toKbEvent` FIRST, so the six public frames go through exactly the path every other client uses —
 * one JSON parse, one name allow-list, one cast at a documented boundary. Only when that answers
 * `null` does this look at the diagnostic name, and it re-checks the name rather than assuming the
 * `null` meant "the trace": a malformed public frame and an unknown event name are both `null` from
 * `toKbEvent`, and treating either as a trace would put unparsed JSON into the panel.
 */
export function toKbAdminEvent(frame: SseFrame): KbAdminEvent | null {
  const publicEvent = toKbEvent(frame);
  if (publicEvent !== null) return publicEvent;

  if (frame.event !== RETRIEVAL_TRACE_EVENT) return null;

  let data: unknown;
  try {
    data = JSON.parse(frame.data);
  } catch {
    return null;
  }
  if (typeof data !== 'object' || data === null) return null;

  return { event: RETRIEVAL_TRACE_EVENT, data: data as RetrievalTraceData };
}

/** Narrows the union to the diagnostic member. The public six are `KbEvent` after this returns. */
export function isRetrievalTraceEvent(
  event: KbAdminEvent,
): event is { readonly event: typeof RETRIEVAL_TRACE_EVENT; readonly data: RetrievalTraceData } {
  return event.event === RETRIEVAL_TRACE_EVENT;
}
