/**
 * The knowledge-source admin surface — the endpoints under
 * `/api/v1/organizations/{organization}/sources` — as the console reads them.
 *
 * ── TYPES ONLY. ZERO RUNTIME VALUES. ─────────────────────────────────────────────────────────────
 * Same rule as `session.ts`, `members.ts`, `providers.ts`, `provider-models.ts` and `bots.ts`, for
 * the same reason: this module is re-exported from `src/index.ts`, budgeted at <=1 kB brotli inside
 * apps/widget's app shell, and only an erased `export type` re-export keeps that free. So the two
 * closed vocabularies below are UNIONS and not tuples.
 *
 * THE ASYMMETRY WITH `bots.ts` IS DELIBERATE AND IT IS THE INTERESTING PART: there is no
 * `SOURCE_STATUSES` tuple behind `@kb/contracts/forms` to match `BOT_STATUSES`, because no source
 * form ships yet — `UpdateSourceStatusRequest` is NO_CLIENT_FORM in test/form-drift.test.ts, recorded
 * as OWED. Writing the tuple now would be a runtime value nothing iterates and nothing compares to
 * the server; the union below IS compared, member for member, against the document's inlined enum in
 * test/resource-drift.test.ts. When the enable/disable control lands it brings the tuple, its schema
 * and its MIRRORS entry together, and both spellings are pinned independently from that day.
 *
 * ── THE SOURCE IS THE UNIT OF INTENT, NOT THE UNIT OF VERSIONING ────────────────────────────────
 * One upload, one sitemap, one crawl configuration, one paste. A crawl gives ONE source hundreds of
 * independently-versioned items, so there is no active-version pointer on this shape and there never
 * will be one: `status` here is the source's own lifecycle, and what a bot can actually retrieve is
 * decided per item, per version, inside the data plane.
 *
 * ── EVERY STRING ON THIS SHAPE IS TENANT-AUTHORED ───────────────────────────────────────────────
 * `name`, `description`, `origin_url` and every element of `tags` were typed by an operator.
 * Interpolate them as JSX children; never as HTML, never into a `style`, never into a `href` without
 * deciding what scheme you will follow. `origin_url` in particular is a URL a stranger chose.
 *
 * ── snake_case verbatim ─────────────────────────────────────────────────────────────────────────
 * A straight carry of the JSON. A rename layer is a place for a typo to read `undefined` with
 * nothing thrown.
 */

import type { ListMetaResource } from './bots.js';

/**
 * What kind of thing a source is — and deliberately NOT what kind of file it is. A PDF, a DOCX, a
 * spreadsheet and a slide deck are all `file`, because the parser decides from the sniffed MIME type
 * rather than from a column somebody typed; a client-settable file format would be exactly the input
 * the upload intake refuses to trust.
 */
export type SourceType = 'file' | 'url' | 'text';

/**
 * The fifteen lifecycle states, in contract order.
 *
 * `ready` and `ready_with_warnings` are IDENTICAL for retrieval — the warnings are advisory and never
 * a retrieval predicate — and they are two values rather than one because collapsing them loses the
 * only signal that says "this document parsed badly and published anyway". Render them as one
 * outcome with a warning affordance, never as two different answers to "is this source live".
 *
 * DO NOT WRITE A LIFECYCLE PREDICATE OUT OF THIS UNION. `status_permits_retrieval` and
 * `status_is_processing` are published precisely so a client does not re-derive them, and a check
 * spelled "not failed" or "not disabled" admits every state added later.
 */
export type SourceStatus =
  | 'draft'
  | 'queued'
  | 'fetching'
  | 'parsing'
  | 'normalizing'
  | 'chunking'
  | 'embedding'
  | 'indexing'
  | 'ready'
  | 'ready_with_warnings'
  | 'failed'
  | 'disabled'
  | 'deleting'
  | 'deleted'
  | 'archived';

/**
 * One knowledge source as the admin console sees it.
 */
export interface SourceResource {
  /** ULID of the source, and the key every source route addresses it by. */
  readonly id: string;
  readonly type: SourceType;
  /**
   * The operator's label for the whole source. TENANT-AUTHORED TEXT: interpolate it as a JSX child
   * and escape it at render, because only the renderer knows the context it is entering. Never
   * blank — the server refuses a whitespace-only name, since a row with no label is a row nobody can
   * find again.
   */
  readonly name: string;
  /**
   * Optional operator note. Null means not set; it is never an empty string, because two spellings of
   * "unset" are two branches every renderer has to have and one of them forgets.
   */
  readonly description: string | null;
  /**
   * The crawl target. Present exactly when `type` is `url` and null otherwise — the database enforces
   * the equality in BOTH directions, so a `file` source can never carry one and a `url` source can
   * never lack one. This is the column that answers "which sources cause this platform to make
   * outbound requests", so render it as text and treat any decision to make it clickable as a
   * decision about a URL a tenant chose.
   */
  readonly origin_url: string | null;
  readonly status: SourceStatus;
  /**
   * Whether the STATUS term of the retrieval filter is satisfied — ONE OF FOUR, and never the whole
   * answer. Reachability is the AND of this, the item's active-version pointer, the bot assignment
   * and the organization (`kb-tenancy-isolation` NN 2). Do not render it as "this source is
   * answering"; render it as "this source's state does not exclude it".
   *
   * READ THIS RATHER THAN COMPARING `status` YOURSELF, for the reason `permits_embedding` exists on
   * `BotDomainResource`: a check written as "not disabled" admits `deleting`, and a sixteenth state
   * would be admitted by every negative test in every client.
   */
  readonly status_permits_retrieval: boolean;
  /**
   * Whether an ingestion run is in flight. `queued` is deliberately NOT included: a source queued for
   * an hour is a scheduling problem and one parsing for an hour is a document problem, and the
   * distinction is what a stuck-run sweep keys off. It is also what a list screen polls on — see
   * `tanstack-query-table` for the interval, and stop polling when this is false for every row rather
   * than polling a whole page forever.
   */
  readonly status_is_processing: boolean;
  /**
   * Operator labels. ALWAYS PRESENT, empty when nobody has tagged the source; never null, and never
   * containing a blank element — so a renderer maps it without a nullish guard and a filter UI never
   * offers a chip nobody can read. The server refuses duplicates (`distinct`), so a repeated tag is a
   * defect rather than a state to de-duplicate at render.
   */
  readonly tags: readonly string[];
  /**
   * Start of the retrieval window, ISO 8601 with offset. Null means "from always".
   *
   * A STRING, NOT A `Date`. Everything on this wire is the JSON the server sent; parsing belongs at
   * the one place that formats it, and a type promising a `Date` is a type that lies about every
   * value `JSON.parse` ever produced.
   */
  readonly effective_at: string | null;
  /**
   * End of the retrieval window. Null means "until further notice"; when set it is always after
   * `effective_at`, which the server enforces both as a rule and as a check constraint.
   */
  readonly expires_at: string | null;
  /**
   * ULID of the administrator who added the source. Null for anything the platform created without a
   * person behind it.
   *
   * READABLE, NEVER WRITABLE, and it is the one field on this shape where that is enforced by more
   * than convention: `created_by` is a member of `OWNERSHIP_KEYS` (src/forms/ownership.ts), so it is
   * unrepresentable in every form schema in this package and a body carrying one is a privilege
   * escalation the server answers with a silent 200 (`rhf-zod-forms` NN1).
   */
  readonly created_by: string | null;
  /** ISO 8601 with offset. */
  readonly created_at: string | null;
  /**
   * ISO 8601 with offset. Moves on a metadata edit AND on every lifecycle transition, including the
   * ones the ingestion pipeline reports — so it is a freshness signal rather than an edit log, and a
   * screen that renders it as "last edited by a person" is wrong on every processing run.
   */
  readonly updated_at: string | null;
  /**
   * When the source stopped being retrievable — phase 1 of a two-phase removal, immediate, and the
   * only half a customer experiences. Non-null with a null `purged_at` means the purge is still in
   * flight, which is a state the list renders rather than hides.
   */
  readonly deleted_at: string | null;
  /**
   * When the background purge was VERIFIED — vectors, objects and cache entries confirmed gone. This
   * is the timestamp a retention or erasure obligation is measured against, and it is a DIFFERENT
   * CLAIM from `deleted_at`: one says we removed it, the other says we proved it. Never render the
   * first as the second.
   */
  readonly purged_at: string | null;
}

/**
 * One page of an organization's knowledge sources.
 *
 * The array sits under a NAMED KEY beside `meta` — the same forced nesting every collection in this
 * package carries: `ResponseShape` maps a response key to a schema class and has no shape meaning "an
 * array of", and every published component must be `additionalProperties: false`, which an
 * array-typed schema cannot be. So `{"data": {"sources": [...], "meta": {...}}}`.
 *
 * `meta` IS THE SHARED `ListMetaResource`, imported rather than re-declared. One component serves
 * every list endpoint in the API, and a near-identical `PaginationMeta` beside this interface is
 * exactly the duplication test/resource-drift.test.ts was rewritten to catch.
 *
 * EVERY LIFECYCLE STATE IS INCLUDED, including sources whose purge is in flight: "where did that
 * source go" is asked precisely while a deletion is running, and hiding the row would make the
 * question unanswerable from the console while the record still existed.
 */
export interface SourceCollectionResource {
  readonly sources: readonly SourceResource[];
  readonly meta: ListMetaResource;
}
