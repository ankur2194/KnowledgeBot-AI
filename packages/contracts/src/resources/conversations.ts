/**
 * Conversation review — `GET .../conversations` and `GET .../conversations/{conversation}`.
 *
 * TYPES ONLY, ZERO RUNTIME VALUES: re-exported from `src/index.ts` under the <=1 kB brotli budget.
 *
 * ── TWO ENDPOINTS AND NO WRITE, AND THAT IS A PROPERTY OF THE SURFACE ───────────────────────────
 * A thread is opened by the public runtime, written by the relay's finalizer, and removed by the
 * retention sweeper. §18.11 treats a transcript as EVIDENCE, so a surface that could both read and
 * destroy it would be able to rewrite the record it exists to preserve.
 *
 * ── EVERYTHING BELOW THE HEADER IS UNTRUSTED TEXT ───────────────────────────────────────────────
 * Visitor questions, model output and quoted document passages — read by an ADMINISTRATOR, in a
 * session holding real privileges, which is what makes this the higher-stakes render rather than the
 * lower-stakes one. It goes through the SAME sanitizer as hosted chat and the widget
 * (`markdown-it html:false` → DOMPurify → `replaceChildren`), with no widened allow-list and no
 * auto-loaded remote image. `consent_text_snapshot` is operator-authored and `user_agent`'s
 * neighbours on the audit surface are attacker-controlled; none of them is markup here either.
 *
 * ── THE §18.10 PRIVACY SWITCH DOES NOT EXIST, AND A CLIENT MUST NOT INVENT ONE ──────────────────
 * The spec's per-organization "may administrators review conversations" toggle has NO COLUMN:
 * `organizations.settings` carries no key vocabulary and no reader, so `ConversationController`'s
 * docblock records the measurement and a TODO rather than inventing one. The transcript endpoint
 * therefore ships UNGATED. A UI control implying the organization has opted in would be describing a
 * decision nobody has made.
 *
 * ── snake_case verbatim ─────────────────────────────────────────────────────────────────────────
 */

import type { ListMetaResource } from './bots.js';

/**
 * Which surface the visitor used.
 *
 * DERIVED SERVER-SIDE FROM HOW THE CALLER AUTHENTICATED, never from a request field — a body field
 * naming the channel would let a widget claim `playground`, which is the actor type that unlocks
 * retrieval diagnostics on the relay.
 *
 * `mobile` is not reachable today and is not faked: nothing in the control plane mints a personal
 * access token, so the React Native client has no credential for the runtime surface. A channel
 * breakdown that showed a `mobile` slice would be showing a number that is simply untrue.
 */
export type ConversationChannel = 'hosted' | 'embedded' | 'mobile' | 'playground' | 'api';

/** Lifecycle state of the thread. Only `active` accepts messages. */
export type ConversationStatus = 'active' | 'ended' | 'expired';

/** One transcript entry's role. */
export type MessageRole = 'user' | 'assistant' | 'system';

/**
 * One transcript entry's state.
 *
 * EVERY status appears on the ADMIN surface, unlike the runtime transcript: `pending` and
 * `streaming` are turns still in flight, and a stuck one is what an operator opens this screen to
 * find. `cancelled` is a NORMAL outcome — a closed tab — and the tokens generated before it were
 * still billed, so it shares nothing with `failed`.
 */
export type MessageStatus = 'pending' | 'streaming' | 'complete' | 'failed' | 'cancelled';

/** One attempt's state. `cancelled` is not a failure; counting it as one makes the error-rate
 *  figure unusable. */
export type ProviderCallStatus = 'pending' | 'succeeded' | 'failed' | 'cancelled';

export type FeedbackRating = 'positive' | 'negative';

/**
 * One conversation thread — the header, without its messages.
 *
 * EXACTLY ONE OF `user_id` AND `anonymous_session_id` IS POPULATED. "Both" is reachable — a
 * signed-in visitor on hosted chat also carries a session cookie — and is the state that would make
 * one person count twice in the unique-session figure, so the database refuses it.
 */
export interface ConversationResource {
  readonly id: string;
  /**
   * NEVER NULL AND NEVER NULLED. The foreign key is `ON DELETE RESTRICT`, so a bot that has held a
   * conversation cannot be deleted and is ARCHIVED instead — which is what keeps a transcript
   * interpretable after the bot is withdrawn. A screen may therefore always name the bot, and must
   * handle the bot being archived rather than absent.
   */
  readonly bot_id: string;
  readonly channel: ConversationChannel;
  readonly status: ConversationStatus;
  readonly user_id: string | null;
  /**
   * The anonymous visitor's session handle.
   *
   * IT IS NOT A CREDENTIAL: what is stored is a one-way digest of the session bearer — derivable
   * from the token, useless without it — and the token itself never reaches a column. It is the
   * value `?session_id=` filters on, which is what makes "every thread from this visitor" answerable
   * without holding anything that could impersonate them.
   */
  readonly anonymous_session_id: string | null;
  /** BCP-47 as the client declared it. `null` means "we were not told", a different fact from `en`. */
  readonly locale: string | null;
  readonly consent_required: boolean;
  readonly consent_granted_at: string | null;
  /**
   * The exact wording the visitor was shown, copied at the moment they were shown it. Present
   * whenever `consent_required` is true — recording only the boolean would make the record mean
   * "they agreed to whatever it says today", and the bot's live consent text is editable.
   * OPERATOR-AUTHORED TEXT: escape at render.
   */
  readonly consent_text_snapshot: string | null;
  /** When the thread was opened. THIS IS THE CREATION TIME — there is no separate `created_at`,
   *  because two columns holding one fact is how they end up disagreeing. */
  readonly started_at: string;
  /**
   * When a turn was last TAKEN — a different fact from `updated_at`, which also moves on a status
   * flip or a consent record. This is the column the idle sweeper and the list's default sort read,
   * and it is never before `started_at`.
   */
  readonly last_activity_at: string;
  /** When the retention sweeper may remove this thread and everything under it. Resolved from the
   *  bot's setting AT CREATION, so a later edit cannot retroactively shorten or extend a
   *  conversation somebody has already had. `null` means the organization's own policy decides. */
  readonly retention_expires_at: string | null;
  readonly updated_at: string | null;
}

export interface ConversationCollectionResource {
  /**
   * The threads on this page, in the applied order — by default most recently active first, which is
   * what a reviewer opens the screen to see. EVERY status is included, including `expired` threads
   * the sweeper has marked and threads still in flight.
   */
  readonly conversations: readonly ConversationResource[];
  /** `meta.filter` is ALWAYS null here: this endpoint accepts no free-text term. The key remains
   *  because `meta` is one shared component across every list in this API. */
  readonly meta: ListMetaResource;
}

/**
 * One footnote behind one answer, as an admin reviewer sees it.
 *
 * EVERY FIELD BUT `chunk_id` IS DENORMALIZED ONTO THE CITATION ROW AT WRITE TIME, so a transcript
 * stays readable after the source it cited has been purged. That is also why conversation review
 * needs no `sources.view` grant: it reads nothing from `knowledge_sources`.
 */
export interface TranscriptCitationResource {
  /** The marker in the answer text — `1`, `2`. Unique within the message, 1-based in PACKED order,
   *  which is the order the model read the evidence in and is NOT reranked order. Assigned before
   *  generation from retrieved evidence, never parsed out of model output. */
  readonly label: string;
  /** `null` once the chunk has been purged. The footnote survives deliberately: it is the record of
   *  what was shown, and losing it would rewrite history. A citation with a null `chunk_id` links
   *  to nothing and must still render. */
  readonly chunk_id: string | null;
  /** The chunk's heading path, its URL, or a fixed fallback — as it was when the answer was given.
   *  IT IS NOT THE SOURCE'S NAME: the retrieval payload carries no source title. */
  readonly title: string;
  /** Whichever locators the chunk had — page, slide, sheet, row range, url, anchor, heading path.
   *  THE KEY SET DIFFERS BY SOURCE TYPE and an absent locator is an absent KEY, never a null: a null
   *  `page` on a slide is indistinguishable from page zero. Read defensively. */
  readonly location: Readonly<Record<string, unknown>>;
  /** The passage the model read. UPLOADED OR CRAWLED CONTENT — hostile data permanently. Same
   *  sanitizer as model output, on every surface including this one. */
  readonly excerpt: string;
  readonly created_at: string;
}

/**
 * One verdict on one answer. At most one per submitter — a visitor who changes their mind UPDATES
 * rather than appending, so a thumbs-down that became a thumbs-up is one row and the satisfaction
 * figure counts one opinion.
 */
export interface TranscriptFeedbackResource {
  readonly rating: FeedbackRating;
  /** UNTRUSTED END-USER TEXT, or `null` — an empty string is refused by the column, so there is only
   *  one spelling of "no comment". */
  readonly comment: string | null;
  /** The member who rated it — an administrator or analyst reviewing a transcript — or `null` when
   *  the verdict came from the visitor. EXACTLY ONE of this and `submitted_by_session`. */
  readonly submitted_by_user_id: string | null;
  readonly submitted_by_session: string | null;
  readonly created_at: string;
  /** Later than `created_at` when the submitter changed their mind, which is an UPDATE rather than a
   *  second row. */
  readonly updated_at: string | null;
}

/**
 * One attempt against a provider for one turn. A turn normally has exactly one; it has more when a
 * fallback was reached, and the extra rows are the record of what was tried first and why it was not
 * enough.
 *
 * THIS IS WHERE PER-TURN LATENCY, TOKENS AND FALLBACK EVENTS LIVE — not on the message. A transcript
 * screen showing "1.2 s, 840 tokens, fell back to gpt-5-mini" is reading this array.
 */
export interface ProviderCallResource {
  readonly id: string;
  /** WHICH CONNECTION WAS BILLED, as a reference and never as a credential: the key behind it never
   *  leaves the vault and no endpoint in this API can read it back. */
  readonly provider_connection_id: string;
  readonly model_id: string;
  /** The vendor's own identifier, echoed from their response — the one field here that lets somebody
   *  else reproduce a failure, and what a support ticket to the provider quotes. */
  readonly provider_request_id: string | null;
  readonly status: ProviderCallStatus;
  /**
   * The normalized class from the 18-member taxonomy, never the provider's own message. Always
   * present on a `failed` attempt and always absent on a `succeeded` one; `cancelled` and `pending`
   * are deliberately free, because a caller going away is not a fault to attribute.
   */
  readonly error_class: string | null;
  /** TOTAL input tokens, cache INCLUDED — the two cache columns are a breakdown of this number and
   *  never an addition to it. `null` means the provider told us nothing, which is not zero. */
  readonly input_tokens: number | null;
  readonly cache_read_tokens: number | null;
  readonly cache_write_tokens: number | null;
  readonly output_tokens: number | null;
  /** Hidden-reasoning tokens where the provider reports them separately. Billed INSIDE
   *  `output_tokens`; adding it double-bills every reasoning turn. */
  readonly reasoning_tokens: number | null;
  /** A FIXED-SCALE DECIMAL AS A STRING — `numeric(16, 8)` does not survive a round trip through a
   *  double. Never parse it into a float to add it up. */
  readonly estimated_cost: string | null;
  readonly estimated_cost_currency: string | null;
  /** Time to the first streamed token — the latency a user FEELS. `null` on a non-streaming call and
   *  on one that never produced a token. */
  readonly first_token_latency_ms: number | null;
  readonly total_latency_ms: number | null;
  /**
   * EMPTY `{}` ON A PRIMARY ATTEMPT. On a fallback it carries the attempt ordinal, the call and model
   * that were tried first, and the error class that made them ineligible — which is the whole reason
   * the column exists, because a fallback row with an empty object is indistinguishable from a first
   * attempt. Key set open; read defensively.
   */
  readonly fallback_metadata: Readonly<Record<string, unknown>>;
  readonly created_at: string;
}

/**
 * Why this answer said what it said. At most one per message, written in the same transaction as the
 * message it explains.
 *
 * NOT THE SAME SHAPE AS THE `retrieval.trace` SSE FRAME. This is Laravel's PERSISTED projection
 * (`retrieval_traces`), read by conversation review; the frame is the data plane's live record and
 * lives behind `@kb/contracts/admin`. Two shapes, two audiences, and neither is derived from the
 * other — which is why they are declared in different modules rather than one being reused for both.
 */
export interface RetrievalTraceResource {
  /** What the visitor actually typed. UNTRUSTED END-USER TEXT. */
  readonly original_query: string;
  /** The query the retriever ran, when it differed. `null` means the rewriting stage left the query
   *  alone — a real outcome, and different from a rewrite that produced the same string. MODEL
   *  OUTPUT: untrusted. */
  readonly rewritten_query: string | null;
  /**
   * The RESOLVED tenant filter this query ran under. All four mandatory terms are always named
   * (`org_id`, `bot_ids`, `source_status`, `source_version_id`); the column's CHECK refuses a row
   * that omits one.
   *
   * IT PROVES THE TRACE RECORDED FOUR TERMS, NOT THAT THE QUERY CARRIED THEM. Rendering it as
   * evidence of isolation would be overclaiming; it is what lets an incident BOUND which past
   * answers were affected.
   */
  readonly filters: Readonly<Record<string, unknown>>;
  /** The bot's retrieval configuration version at query time, copied so a later edit cannot rewrite
   *  the explanation of an answer already given. */
  readonly retrieval_configuration_version: number;
  /** What retrieval returned, IN RANKED ORDER — the order IS the ranking, which is why it is an
   *  array. Empty on a query that matched nothing. CONTAINS TENANT SOURCE TEXT: untrusted. */
  readonly candidate_summaries: readonly Readonly<Record<string, unknown>>[];
  /** The subset that survived the threshold and the packer, in packed order — the same order the
   *  citation labels are assigned in. ALWAYS EMPTY when `insufficient_evidence` is true; the
   *  column's CHECK enforces it, because a refusal made while holding evidence is a stored
   *  contradiction. */
  readonly selected_evidence: readonly Readonly<Record<string, unknown>>[];
  /** Whether the bot declined for want of evidence. A NORMAL OUTCOME and not an error — it is the
   *  refusal behaviour working, and it is the figure §8.23 counts. */
  readonly insufficient_evidence: boolean;
  /** Per-stage milliseconds. THE KEY SET IS THE DATA PLANE'S and is not enumerated on this side, so
   *  read defensively: a stage that did not run is an absent KEY rather than a zero. */
  readonly timing_breakdown: Readonly<Record<string, number>>;
  readonly created_at: string;
}

/**
 * One transcript entry with its citations, its retrieval trace, its feedback and every provider
 * attempt behind it.
 *
 * WIDER THAN THE RUNTIME'S MESSAGE SHAPE IN THREE DIRECTIONS — cost, the retry graph, and the
 * diagnostics — because the audience is the party that is BILLED for the call and ACCOUNTABLE for
 * the answer.
 */
export interface TranscriptMessageResource {
  /** For an assistant turn it is the same id the `message.start` and `message.complete` SSE frames
   *  carried, so a streamed answer and its stored entry are the same row. */
  readonly id: string;
  readonly role: MessageRole;
  /**
   * MODEL OUTPUT, downstream of retrieved source content, which is attacker-controlled. Render it
   * through the shared sanitizer — the same one on every surface INCLUDING THIS ONE — and never
   * auto-load an image it names.
   *
   * `null` when the turn has produced no text: a `pending` turn, or one that failed before saying
   * anything. NEVER AN EMPTY STRING, so `content === null` is the whole of the "nothing to render"
   * test.
   */
  readonly content: string | null;
  readonly status: MessageStatus;
  /**
   * Set when this turn is a RETRY of an earlier one, and null otherwise.
   *
   * IT MEANS RETRY AND NOTHING ELSE — it is NOT "the question this answers", which is deliberately
   * not a column: overloading it would make a resend indistinguishable from a retry in every later
   * read.
   */
  readonly parent_message_id: string | null;
  /** Which of the attempts in `provider_calls` actually SETTLED this turn — the last one, when a
   *  fallback was reached. `null` on a user or system turn, and on an assistant turn that never
   *  reached a provider. */
  readonly settling_provider_call_id: string | null;
  readonly created_at: string;
  /** An assistant row is written three times in a normal streaming turn — pending, streaming,
   *  settled — so this is "when the answer finished" and `created_at` is "when the turn opened". The
   *  gap between them is the wall clock the visitor waited. */
  readonly updated_at: string | null;
  /** The evidence behind this answer, in label order. Empty for a user turn, a system notice, and an
   *  answer given with no retrieval. */
  readonly citations: readonly TranscriptCitationResource[];
  /** At most one per message. `null` on a user turn, a system notice, and any assistant turn whose
   *  retrieval never ran — a turn that failed before the pipeline reached the trace-writing stage
   *  has none, which is itself diagnostic. */
  readonly retrieval_trace: RetrievalTraceResource | null;
  readonly feedback: readonly TranscriptFeedbackResource[];
  /** Every attempt behind this turn, oldest first. Empty for a user turn and a system notice, both
   *  of which cost nothing. */
  readonly provider_calls: readonly ProviderCallResource[];
}

/** One conversation and everything that was said in it. */
export interface ConversationTranscriptResource extends ConversationResource {
  /**
   * The thread, oldest first, in a TOTAL order — `(created_at, id)`. The tie-break is not
   * decoration: a user turn and the pending assistant row it opens are written in one transaction
   * and can share a microsecond, so without it a second read may legally return the answer before
   * the question.
   */
  readonly messages: readonly TranscriptMessageResource[];
  /**
   * True when the thread is longer than one transcript read returns and the tail was left unread.
   *
   * PUBLISHED RATHER THAN INFERRED FROM THE ARRAY LENGTH, because a client cannot tell a capped read
   * from a conversation that happened to be exactly that long — and a transcript that ends
   * mid-argument reads as a conversation that ended there. A screen must say so.
   */
  readonly messages_truncated: boolean;
}
