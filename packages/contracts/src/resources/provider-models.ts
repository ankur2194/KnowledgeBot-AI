/**
 * The model catalog under one provider connection — the five admin endpoints under
 * `/api/v1/organizations/{organization}/provider-connections/{providerConnection}/models`.
 *
 * ── TYPES ONLY. ZERO RUNTIME VALUES. ─────────────────────────────────────────────────────────────
 * Same rule as `session.ts`, `members.ts` and `providers.ts`, for the same reason: this module is
 * re-exported from `src/index.ts`, budgeted at <=1 kB brotli inside apps/widget's app shell, and only
 * an erased `export type` re-export keeps that free. There is therefore no capability tuple here, and
 * that absence is argued for below rather than being an oversight.
 *
 * ── THE CAPABILITY VOCABULARY IS NOT DECLARED IN THIS PACKAGE, AND THAT IS THE SERVER'S DOING ────
 * `supported` is published as `array<string>` with NO enum, deliberately: the vocabulary belongs to
 * the data plane's `Capability` StrEnum, the control plane validates the members as `string|max:64`
 * and nothing more, and a closed copy here would be this package inventing a guarantee no layer
 * makes — the identical argument `EmbeddingCandidate.provider` carries in `providers.ts`. The day the
 * data plane adds a member, a row carrying it must still round-trip through every client that never
 * heard of it.
 *
 * The admin console needs a closed list anyway, to render checkboxes rather than a free-text field.
 * It declares one LOCALLY (`apps/web/src/features/models/api.ts`), renders an unrecognised flag
 * verbatim, and preserves it on save. That is the correct place for it: it is a UI affordance over an
 * open wire vocabulary, not a contract.
 *
 * ── THE PRICES ARE STRINGS AND MUST STAY STRINGS ────────────────────────────────────────────────
 * `decimal:6` on a `numeric(14, 6)` column, serialized as an exact decimal STRING. `'0.02'` comes
 * back as `'0.020000'`, and the six trailing digits are the point: a per-million price multiplied by
 * a month of token counts through an IEEE 754 double drifts by an amount nobody can reproduce or
 * explain to a tenant. Typed `string | null` here so a `number` cannot be assigned by accident, and
 * so `JSON.parse`-ing one into a float to "normalize" it is a typecheck failure rather than a
 * rounding nobody sees. Format for display; submit what the operator typed.
 *
 * ── snake_case verbatim ─────────────────────────────────────────────────────────────────────────
 * A straight carry of the JSON. A rename layer is a place for a typo to read `undefined` with nothing
 * thrown.
 */

/**
 * One row in one connection's catalog. Carries NOTHING from the credential on the parent connection
 * — not the masked form, not the vendor — so rendering a hundred of these performs zero vault calls
 * and there is no shape in this package pairing a model row with key material.
 */
export interface ProviderModelResource {
  /** ULID of the CATALOG ROW. Not the model identifier — that is `model`, and it is the vendor's. */
  readonly id: string;
  /**
   * ULID of the parent connection. Echoed so a client-side list flattened across several connections
   * stays attributable without a second lookup.
   */
  readonly connection_id: string;
  /**
   * The official model identifier, exactly as the vendor publishes it. IMMUTABLE: it is half of the
   * vector-space identity for everything already embedded through this row, so there is no edit path
   * for it — `UpdateProviderModelRequest` declares no such field and a mistyped id is deleted and
   * re-created. An alias names a different vector space (ADR-035).
   */
  readonly model: string;
  /** Operator-supplied name. TENANT-CONTROLLED TEXT: interpolate it as a JSX child, never as HTML. */
  readonly display_name: string;
  /**
   * The capability flags this row CLAIMS, as the operator declared them — an OPEN string array on
   * purpose (see the module docblock). A claim is not a guarantee: whether a flag is honoured is the
   * AND of this list and a sourced vendor fact, and an incoherent row is refused by the data plane
   * with the reason named. An empty array means the row claims nothing, which is also what a
   * malformed stored value renders as.
   */
  readonly supported: readonly string[];
  /** Total context window in tokens, from the vendor's documentation. 0 means "not recorded". */
  readonly context_window: number;
  /** Maximum configured output in tokens. 0 means "not recorded"; it is NOT a limit of zero. */
  readonly max_output_tokens: number;
  /**
   * Whether this row may be offered and used. A disabled row STAYS in the catalog and stays listed —
   * an operator asking "why is this model missing from the dropdown" has to be able to find it.
   */
  readonly enabled: boolean;
  /**
   * List price per ONE MILLION input tokens, as an exact decimal STRING — see the module docblock.
   * Null means no price has been recorded, which is NOT the same as free. Used only for estimated
   * reporting: it authorizes nothing and gates nothing.
   */
  readonly input_price_per_million: string | null;
  /** Same representation and same caveats as `input_price_per_million`. */
  readonly output_price_per_million: string | null;
  /**
   * ISO 4217-SHAPED code: three upper-case letters. Non-null whenever either price is non-null — the
   * database CHECK `provider_models_price_needs_currency` enforces that direction — and permitted on
   * its own, which is the state of a row whose operator recorded the billing currency before the
   * prices. The SHAPE is enforced; membership of the real ISO list is not, because that list changes.
   */
  readonly price_currency: string | null;
  /** RFC 3339. Null only for a record whose timestamp was never set. */
  readonly created_at: string | null;
}

/**
 * The list body's extra nesting level, FORCED rather than chosen — the same argument
 * `ProviderConnectionCollectionResource` carries: `ResponseShape` maps a response KEY to a schema
 * class and has no shape meaning "an array of", and every published component must be
 * `additionalProperties: false`, which an array-typed schema cannot be. So
 * `{"data": {"models": [...]}}`.
 *
 * Every row is listed, ENABLED OR NOT, ordered by model identifier. Disabled rows are included for
 * the reason `enabled` states: hiding one makes "why is this model missing from the bot's dropdown"
 * unanswerable from the console while the row sits in the table.
 */
export interface ProviderModelCollectionResource {
  readonly models: readonly ProviderModelResource[];
}
