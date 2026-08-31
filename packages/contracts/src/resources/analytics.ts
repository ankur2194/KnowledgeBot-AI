/**
 * §8.23's usage-and-quality dashboard — `GET /api/v1/organizations/{organization}/analytics`.
 *
 * ── TYPES ONLY. ZERO RUNTIME VALUES. ────────────────────────────────────────────────────────────
 * Same rule as every other module under `src/resources/`: this one is re-exported from
 * `src/index.ts`, which is budgeted at <=1 kB brotli inside apps/widget's app shell, and only an
 * erased `export type` re-export keeps that free.
 *
 * ── ONE ENDPOINT, ELEVEN TILES, AND THAT IS THE SERVER'S DECISION RATHER THAN A CONVENIENCE ─────
 * The tiles are read together — they are one screen — so a tile per endpoint would be eleven round
 * trips, eleven authorization checks, and eleven chances for one of them to be scoped differently
 * from the other ten. `kb-tenancy-isolation` lists analytics as its own isolation layer precisely
 * because "group by bot_id and drop the redundant organization_id" is such an easy thing to talk
 * yourself into.
 *
 * ── NULL IS "NOT MEASURED" AND IT IS NEVER ZERO, ON EVERY NULLABLE FIELD BELOW ──────────────────
 * A latency percentile over a window with no successful streaming call is `null`; an error rate over
 * a window with no calls is `null`; a cost for a model with no recorded price is `null`. Rendering
 * any of them as `0` reports health nobody measured. The tile renders an em dash and says the window
 * is empty — `references/states.md`'s "defined zero" rule, applied to the case where there isn't one.
 *
 * ── snake_case verbatim ─────────────────────────────────────────────────────────────────────────
 * A straight carry of the JSON. A rename layer is a place for a typo to read `undefined` with
 * nothing thrown.
 */

/**
 * The RESOLVED window every number describes, echoed because `from` and `until` both have
 * server-side defaults — a caller that sent neither would otherwise not know what period it is
 * looking at, and two screenshots would not be comparable.
 */
export interface AnalyticsWindow {
  /** Inclusive start. The window is half-open `[from, until)`. ISO-8601 with an offset. */
  readonly from: string;
  /** Exclusive end. */
  readonly until: string;
  /**
   * The bot every tile was restricted to, or `null` for the whole organization.
   *
   * Echoed so a client can tell "all bots" from "one bot with no traffic" — two pages of identical
   * zeroes that mean completely different things.
   */
  readonly bot_id: string | null;
}

/**
 * Percentiles over SUCCEEDED provider attempts. Percentiles and not a mean: one 55-second timeout
 * among ninety-nine 900 ms answers averages to a number no user experienced.
 */
export interface AnalyticsLatency {
  /** Median time to first token. `null` means nothing to measure, which is not `0`. */
  readonly first_token_p50_ms: number | null;
  readonly first_token_p95_ms: number | null;
  readonly total_p50_ms: number | null;
  readonly total_p95_ms: number | null;
}

/**
 * Provider outcomes. `cancelled` and `pending` attempts are in NEITHER count: a user closing the tab
 * is not a provider error, and counting it as one hides real outages behind ordinary behaviour.
 */
export interface AnalyticsProviderCalls {
  readonly succeeded: number;
  readonly failed: number;
  /**
   * `failed / (succeeded + failed)`, or `null` when nothing finished.
   *
   * A NUMBER ON THE WIRE MAY ARRIVE AS AN INTEGER. The published schema is `["number","null"]`
   * deliberately: PHP drops a zero fraction unless `JSON_PRESERVE_ZERO_FRACTION` is passed and
   * Laravel does not pass it, so a rate of exactly 1.0 or 0.0 crosses as `1` or `0`. JSON Schema's
   * `number` admits an integer, so the contract is correct — but do not "fix" it with a cast.
   */
  readonly error_rate: number | null;
  /**
   * Attempts with empty `fallback_metadata` — one per turn, so this IS the turn count, and it does
   * not shrink when retention sweeps the conversations.
   */
  readonly primary_attempts: number;
  readonly fallback_attempts: number;
  /**
   * `fallback_attempts / primary_attempts`, or `null` when there were no turns.
   *
   * IT MAY LEGITIMATELY EXCEED 1.0 — a turn that fell back twice writes two attempts — and the
   * server deliberately does not clamp it, because clamping hides exactly the configuration worth
   * looking at. A meter rendered from this must handle >1 rather than assume a fraction.
   */
  readonly fallback_rate: number | null;
}

/**
 * The thumbs, split. TWO COUNTS AND NOT A RATIO: 1/0 and 40000/0 are both "100%" and only one of
 * them is evidence. Comments are deliberately absent — free text from a stranger does not belong in
 * an aggregate.
 */
export interface AnalyticsFeedback {
  readonly positive: number;
  readonly negative: number;
}

/** One `(provider, model, currency)` row. Input and output stay apart because they are priced
 *  apart; a single total cannot be costed. */
export interface AnalyticsModelUsage {
  readonly provider: string;
  /** The vendor model id, verbatim. Vendor-authored text: a JSX child, never markup. */
  readonly model: string;
  /** Total input tokens, CACHE READS AND WRITES INCLUDED — the two cache columns are a breakdown of
   *  this number, so adding them would double-count every cached token. */
  readonly input_tokens: number;
  /** Output tokens INCLUDING reasoning tokens: every vendor here bills reasoning at the output rate,
   *  so a figure over bare `output_tokens` under-reports a reasoning turn silently. */
  readonly output_tokens: number;
  /**
   * An exact decimal STRING, or `null` when the model has no recorded price — a different fact from
   * a cost of zero.
   *
   * A STRING AND NEVER A NUMBER. `numeric(14, 6)` does not survive a round trip through a double,
   * and an error in the sixth decimal per call is an error in the second by the end of a month.
   * Render it; never `parseFloat` it to add two rows up.
   */
  readonly estimated_cost: string | null;
  /** ISO 4217. It travels WITH the number because summing two currencies is a silent wrong answer,
   *  which is also why there is no organization-wide cost total on this shape. */
  readonly currency: string | null;
}

/**
 * Ingestion outcomes over source VERSIONS created in the window — not sources. A source is ingested
 * many times; counting sources answers a different question that never changes when an ingestion
 * fails.
 */
export interface AnalyticsIngestion {
  /** Versions that reached `ready` OR `ready_with_warnings`. A version with warnings IS searchable,
   *  so filing it as a failure would report an outage that is not happening. */
  readonly succeeded: number;
  readonly failed: number;
  /** Still moving. Published so `succeeded + failed + in_flight` accounts for every version created
   *  in the window. */
  readonly in_flight: number;
}

export interface AnalyticsResource {
  readonly window: AnalyticsWindow;
  /** Threads STARTED in the window. A thread started last month and answered today belongs to last
   *  month, so two months' totals never exceed the year's. */
  readonly conversations: number;
  /** Turns in the window, both roles, all statuses. */
  readonly messages: number;
  /** Distinct participants — the anonymous session token or the signed-in user, whichever the thread
   *  carries. A conversation holds exactly one of the two, which is what stops a signed-in visitor
   *  being counted twice. */
  readonly unique_sessions: number;
  readonly latency: AnalyticsLatency;
  readonly provider_calls: AnalyticsProviderCalls;
  readonly feedback: AnalyticsFeedback;
  /**
   * Answers that refused for want of evidence.
   *
   * THIS MEASURES A CORRECT REFUSAL RATHER THAN A FAILURE, which is why it is its own number and why
   * a tile rendering it must not sit in a failure-coloured family: a bot whose count is climbing
   * needs sources, not an engineer. Its polarity is `higherIsBetter: false` all the same — more
   * refusals is worse for the reader — and that is a statement about the corpus, not about health.
   */
  readonly insufficient_evidence_answers: number;
  readonly usage_by_model: readonly AnalyticsModelUsage[];
  readonly ingestion: AnalyticsIngestion;
  /**
   * Bytes currently occupied — a LEVEL, not a flow, so it is NOT windowed. It is the same ledger
   * figure the quota gate refuses against, so this page and the quota screen cannot disagree about
   * whether the organization is over its storage limit.
   */
  readonly storage_bytes_used: number;
}
