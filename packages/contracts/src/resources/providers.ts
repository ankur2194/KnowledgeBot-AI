/**
 * The provider-connection resources the control plane returns from the six admin endpoints under
 * `/api/v1/organizations/{organization}/provider-connections`, plus the embedding-readiness verdict
 * that rides along on the create response.
 *
 * ── TYPES ONLY. ZERO RUNTIME VALUES. ─────────────────────────────────────────────────────────────
 * Same rule as `session.ts` and `members.ts`, for the same reason: this module is re-exported from
 * `src/index.ts`, budgeted at <=1 kB brotli inside apps/widget's app shell, and only an erased
 * `export type` re-export keeps that free. `ProviderKey` and `ProviderConnectionStatus` are therefore
 * UNIONS and not tuples. The runtime status tuple the edit form's `<Select>` iterates lives behind
 * the `./forms` subpath, beside the schema that validates it — see `src/forms/provider-connection.ts`.
 *
 * ── THE ONE FIELD THIS FILE EXISTS TO KEEP HONEST ────────────────────────────────────────────────
 * `masked_key` IS NOT A CREDENTIAL AND IS NOT AN INPUT VALUE. It is `…` (U+2026) plus the stored
 * last four, read from a column; it cannot authenticate anything, and posting it back sets the
 * tenant's key to the literal text `…4a91` — after which every provider call fails `provider_auth`
 * and the 200 that caused it says nothing. So it is typed here, rendered as TEXT, and there is
 * deliberately NO type in this package that pairs it with a credential field: the create and rotate
 * bodies have no shared schema at all (`NO_CLIENT_FORM` in test/form-drift.test.ts records both), and
 * the edit body cannot carry a credential because the FormRequest declares none.
 *
 * A `providerConnectionDefaults(resource)` that spread this resource into form state is the exact
 * bug the arrangement prevents, and it is one careless line away in any file that has both shapes in
 * scope. There is no such factory here; `providerConnectionEditDefaults` in `./forms` picks two
 * fields by name and cannot reach a third.
 *
 * ── snake_case verbatim ─────────────────────────────────────────────────────────────────────────
 * A straight carry of the JSON. A rename layer is a place for a typo to read `undefined` with
 * nothing thrown.
 */

/**
 * The vendor a connection authenticates against. CLOSED on this side because
 * `ProviderConnectionResource.provider` is published as an enum: the control plane validates it
 * against `App\Enums\Provider`, so a sixth vendor is a server change first and a red
 * `resource-drift` run second.
 *
 * NOT reused for `EmbeddingCandidate.provider` — see that interface. The two fields carry the same
 * vocabulary and have different authorities.
 */
export type ProviderKey = 'openai' | 'anthropic' | 'deepseek' | 'nvidia_nim' | 'openrouter';

/**
 * The connection's lifecycle state, as the OPERATOR declared it — never evidence that a key was
 * tested. `invalid` is what a failed connection check leaves behind and `revoked` is what an
 * operator sets when a key leaks; rotating a credential is the remedy for both and returns the row
 * to `active`, which is why the rotate endpoint deliberately does not refuse a non-active row.
 */
export type ProviderConnectionStatus = 'active' | 'invalid' | 'revoked';

/**
 * One stored provider connection. Carries no ciphertext, no key version and no decrypted material;
 * rendering a hundred of these performs zero vault calls.
 */
export interface ProviderConnectionResource {
  readonly id: string;
  readonly provider: ProviderKey;
  /** Operator-supplied name. TENANT-CONTROLLED TEXT: interpolate it as a JSX child, never as HTML. */
  readonly label: string;
  /**
   * `…` (U+2026) plus the last four characters of the key. A DISPLAY STRING — see the module
   * docblock. Four characters and never a prefix: a provider key prefix identifies the vendor and,
   * on several providers, the account.
   */
  readonly masked_key: string;
  readonly status: ProviderConnectionStatus;
  /** RFC 3339. Null only for a record whose timestamp was never set. */
  readonly created_at: string | null;
}

/**
 * The list body's extra nesting level, FORCED rather than chosen — the same argument
 * `MemberCollectionResource` carries: `ResponseShape` maps a response KEY to a schema class and has
 * no shape meaning "an array of", and every published component must be `additionalProperties:
 * false`, which an array-typed schema cannot be. So `{"data": {"connections": [...]}}`.
 *
 * Every connection is listed WHATEVER ITS STATUS. Revoked and invalid rows are included because an
 * operator diagnosing "why can I not ingest" has to be able to see the credential that stopped
 * working; hiding it makes the question unanswerable from the console.
 */
export interface ProviderConnectionCollectionResource {
  readonly connections: readonly ProviderConnectionResource[];
}

/**
 * One `(connection, provider, model)` triple that can embed.
 *
 * `provider` IS A BARE STRING HERE AND AN ENUM ON `ProviderConnectionResource`, and the asymmetry is
 * the server's rather than an oversight: this field is RELAYED from the data plane's own resolution
 * rule (`services/ai-service/app/providers/embedding_selection.py`) and is not validated on the
 * control plane's side, so the published component declares no enum. Narrowing it to `ProviderKey`
 * here would be this package inventing a guarantee no layer makes, and it would break the day the
 * data plane names a vendor Laravel does not store.
 *
 * The same reasoning applies to `EmbeddingRejection.reason`.
 */
export interface EmbeddingCandidate {
  readonly connection_id: string;
  readonly provider: string;
  /** The official vendor model id, verbatim. An alias names a different vector space (ADR-035). */
  readonly model: string;
}

/**
 * The `(connection, model)` pair an organization has STORED as its explicit embedding choice, read
 * off `organizations.embedding_connection_id` / `embedding_model`. A PAIR and not a bare connection
 * id: one connection legitimately carries several embedding rows, and `text-embedding-3-large` and
 * `text-embedding-3-small` on one credential are two vector spaces.
 *
 * NO `provider`, unlike `EmbeddingCandidate` — the organization stores a connection id and a model
 * string and nothing else. A renderer that reaches for `provider` here would print `undefined/model`;
 * the stored pair is shown as its two fields, and `describeCandidate` is not applicable to it.
 */
export interface EmbeddingDesignation {
  /** May name a connection that no longer resolves. This is what was stored, not what resolved. */
  readonly connection_id: string;
  readonly model: string;
}

/** One candidate the resolution rule refused, with the reason it gave. */
export interface EmbeddingRejection {
  readonly connection_id: string;
  readonly provider: string;
  readonly model: string;
  /** An `EmbeddingIneligibility` member, relayed verbatim. Not a closed enum — see EmbeddingCandidate. */
  readonly reason: string;
  /**
   * The data plane's own sentence, quoting the capability-matrix cell. Carries connection ids,
   * provider names, model ids and matrix sources only — never a credential, never tenant content.
   * It is END-USER-READABLE by construction and is rendered verbatim, which makes it one of the two
   * exceptions to "never render a server string" on this surface (the other is `explanation`).
   */
  readonly detail: string;
}

/**
 * Whether this organization can ingest a document, and why not.
 *
 * WHY THIS TYPE IS HERE ALREADY, when the designation screen is a later step: it is not only the body
 * of `GET/PUT /embedding-configuration`. `POST /provider-connections` returns it as a SECOND
 * TOP-LEVEL KEY beside `data` — one resource, two envelopes — so the create form on the providers
 * screen consumes it the moment a credential is stored, to say "you still cannot ingest anything,
 * and here is why" instead of leaving a green save on an organization that fails on its first upload.
 *
 * `ready` is the only field to branch on, and `explanation` is the data plane's own words: the upload
 * path raises the SAME string, so the banner and the error say the same thing. Render it; never
 * paraphrase it.
 */
export interface EmbeddingReadinessResource {
  readonly ready: boolean;
  /** The negation of `ready`, named for what the operator sees. Not redundant — only one is actionable. */
  readonly blocks_ingestion: boolean;
  /** Exactly one of this and a non-empty `explanation` is populated. */
  readonly selected: EmbeddingCandidate | null;
  /**
   * The pair this organization has STORED, or null when it has designated nothing. NOT derivable
   * from `selected`: a null `selected` means either "nothing designated and nothing resolved by
   * rule" or "the designation no longer resolves", and only this field tells them apart.
   */
  readonly designated: EmbeddingDesignation | null;
  readonly eligible: readonly EmbeddingCandidate[];
  readonly rejected: readonly EmbeddingRejection[];
  /** The empty string when `ready` is true. */
  readonly explanation: string;
}

/**
 * `POST /provider-connections` — the one response on this API whose payload is NOT entirely under
 * `data`. Declared here so the asymmetry is unwrapped against a shared type rather than an inline
 * literal at the fetcher, and so a reader meets it before writing `browserFetchData` and losing the
 * readiness verdict silently.
 */
export interface ProviderConnectionCreatedResponse {
  readonly data: ProviderConnectionResource;
  readonly embedding_readiness: EmbeddingReadinessResource;
}
