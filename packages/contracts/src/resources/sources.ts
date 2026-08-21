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

/**
 * One advisory parser or OCR warning reported while a source's LIVE content was produced.
 *
 * ADVISORY ALWAYS, AND THAT IS THE ONLY THING A RENDERER MUST GET RIGHT. A warning is never a
 * retrieval predicate — `ready_with_warnings` and `ready` are identical for every query — so this
 * list is never the answer to "why is this source not answering". It is a quality signal beside the
 * content, not a fault beside the status, and the tone families in `kb-design-language` have a
 * separate one for exactly that difference.
 *
 * ── `code` IS AN OPEN VOCABULARY AND THERE IS NO UNION HERE ─────────────────────────────────────
 * The key set belongs to the ingestion service and grows with the parsers, so the document publishes
 * no enum and this package must not invent one — a closed copy would refuse to render a code that
 * shipped this morning, which is the argument `tags` makes on `SourceResource` reaching a second
 * field. `warnings_truncated` on the detail shape exists for the same reason: an open set cannot be
 * bounded server-side by enumerating it, only by capping the list. Treat an unrecognised code as a
 * code you have no copy for, not as an error; it is a machine key rather than a sentence, so the
 * shape that works is a lookup table with a fallback to the raw string.
 *
 * ── THE VALUE BEHIND THE KEY IS DELIBERATELY NOT PUBLISHED ──────────────────────────────────────
 * It is unschema'd and can contain document content, so the server sends a COUNT instead. Do not ask
 * for the payload as a convenience: that is tenant text arriving on a shape nobody wrote an escape
 * for, which is the failure `SourceResource`'s tenant-authored-text note is about.
 */
export interface SourceWarningResource {
  /** The warning key as the ingestion pipeline wrote it. Render verbatim; see the docblock. */
  readonly code: string;
  /**
   * How many of the source's LIVE versions reported this code — VERSIONS, never occurrences. How
   * many pages inside one version were affected lives in the value this shape does not read, so a
   * `1` on a single-file source is the only value it can ever hold and a `1` on a crawl means one
   * page carries the problem and says nothing at all about how badly.
   */
  readonly versions: number;
}

/**
 * The immutable processing result a SINGLE-ITEM source is currently serving.
 *
 * It is what an ITEM's active-version pointer names, and the item — one uploaded file, one crawled
 * page, one paste — is the unit of independent versioning. There is no source-level pointer, so this
 * shape reaches a client only through `SourceDetailResource.active_version`, and only for a source
 * whose item count is exactly one.
 *
 * ── ITS `status` IS NOT THE SOURCE'S `status`, AND CONFLATING THEM IS THE OBVIOUS BUG ───────────
 * The source shows the state of the run IN FLIGHT; this shows the state of what is currently
 * SERVING. A source reading `parsing` above a version reading `ready` is the normal, correct state
 * during a reprocess — the previous version answers every query until the new one is indexed and
 * verified (`kb-source-lifecycle`) — so a screen that renders one status for both makes an atomic
 * publication look like an outage.
 *
 * ── THE FOUR `*_cfg_version` STRINGS ARE INGEST-KEY COMPONENTS, NOT DIAGNOSTICS ─────────────────
 * They are what makes a reprocess produce a NEW version rather than deduplicate against this one, so
 * they are the honest answer to "why did re-uploading the same file do something this time". Render
 * them as opaque identifiers; nothing in this package parses them, and a client that split one on
 * `:` to show a friendlier name would be reading a format the data plane owns.
 */
export interface SourceActiveVersionResource {
  /** ULID of the version. */
  readonly id: string;
  /** ULID of the item this version belongs to — the unit of versioning, and not the source. */
  readonly source_item_id: string;
  /**
   * Monotonic per item, starting at 1. A GAP IS INFORMATION: it means a version was created and
   * never activated, which is what a failed run leaves behind, so "v1 then v3" is a history to show
   * rather than a sequence to renumber.
   */
  readonly version_number: number;
  /** The version's own lifecycle state, which may differ from the source's — see the docblock. */
  readonly status: SourceStatus;
  /**
   * ISO 8601 with offset. When this version became the one answering.
   *
   * A STRING, NOT A `Date`, like every other timestamp in this package. Nullable because the column
   * is, and never null in practice for a version an item points at: the pointer switch and this
   * timestamp are written in one transaction, so a null here would be a row that says it is serving
   * and cannot say since when.
   */
  readonly activated_at: string | null;
  /** The document-parser configuration this version was produced under. */
  readonly parser_cfg_version: string;
  /**
   * The OCR configuration, and a SEPARATE field from the parser's on purpose: a content-only ingest
   * key would make an OCR retune a silent no-op that reports "already processed".
   */
  readonly ocr_cfg_version: string;
  /** The chunking configuration this version was split under. */
  readonly chunker_cfg_version: string;
  /**
   * Provider, model id, returned vector width and a digest over a fixed probe set — e.g.
   * `emb/v1:openai:text-embedding-3-large:d3072:9f2a1c4e77b1`.
   *
   * NOT A BARE VENDOR MODEL NAME, and the distinction is ADR-035's: a vendor alias can be re-pointed
   * at different weights with no diff anywhere, so identity is MEASURED rather than declared. Two
   * versions with different values here are in different vector spaces and their scores are not
   * comparable — which is a sentence a UI may show and must never act on.
   */
  readonly embedding_model_version: string;
}

/**
 * One knowledge source WITH WHAT IS INSIDE IT — `GET .../sources/{source}`, unwrapped from `data`.
 *
 * ── IT EXTENDS `SourceResource`; IT DOES NOT RESTATE IT ─────────────────────────────────────────
 * The server composes the same way — the detail resource builds on the list resource's array and
 * adds to it rather than transcribing its sixteen fields — and `extends` is the only spelling on
 * this side that keeps the two in step without anybody remembering to. A seventeenth field added to
 * `SourceResource` tomorrow is on this interface the moment it is on that one; a second flat
 * interface would go on compiling while missing it, and the detail screen would render `undefined`
 * where the list screen renders a value, which is a bug with no error and no failing type.
 *
 * That is a property rather than a promise. test/resource-drift.test.ts pins the extension at the
 * TYPE level (assignable in one direction and not the other) and pins the same relationship on the
 * WIRE, requiring every property published on `SourceResource` to be published AND required on
 * `SourceDetailResource` too; and the MIRRORED register derives this component's key list from
 * `SourceResource`'s by spread rather than as a second hand-written list. So the day the server adds
 * a field to one shape and forgets the other is a red suite naming the field.
 *
 * ── THE CONTRADICTION THIS SHAPE MAKES VISIBLE, RECORDED RATHER THAN RESOLVED ───────────────────
 * The `SourceResource` docblock at the top of this module says there is no active-version pointer on
 * that shape "and there never will be one". Nothing here weakens it — no field was added to
 * `SourceResource`, and the type-level pin is what proves that — but the sentence reads as a claim
 * about SOURCES rather than about a shape, and `active_version` below is a version pointer on a
 * source. The server's reconciliation is deliberately narrow and is stated on the field: populated
 * ONLY when the source has exactly one item, null for every crawl and every multi-file upload, with
 * `active_version_count` answering the question in the general case. Whether the absolute sentence
 * should be softened is `control-plane-engineer`'s call and not this package's; it is written down
 * here so the next reader finds the tension instead of rediscovering it.
 *
 * ── EVERY COUNT BELOW IS OVER THE LIVE VERSIONS ONLY ────────────────────────────────────────────
 * The ones an item's active-version pointer names, and nothing else. A source mid-ingestion reports
 * ZEROES even though rows for the unpublished version already exist, which looks wrong on a progress
 * screen and is exactly right on a delete confirmation: the question there is what is reachable and
 * about to stop being. Do not render any of these as ingestion progress — `status_is_processing` is
 * the field for that, and a count that climbs is not what these numbers do.
 */
export interface SourceDetailResource extends SourceResource {
  /**
   * Independently-versioned items under this source: one per uploaded file, one per crawled page,
   * one for a paste. A submitted source always has at least one; a `draft` has none, because nothing
   * has been submitted into it yet. This is the number that decides whether `active_version` can be
   * populated at all.
   */
  readonly item_count: number;
  /**
   * How many of those items currently point at a live version.
   *
   * ZERO MEANS NOTHING ABOUT THIS SOURCE IS RETRIEVABLE, whatever `status` says — the active-version
   * pointer is one of the four mandatory filter terms (`kb-tenancy-isolation` NN 2) and an ingestion
   * that never completed leaves it empty. Read THIS, not `active_version`, to answer "is any of this
   * live": the pointer field is null for every multi-item source, including ones serving hundreds of
   * pages.
   */
  readonly active_version_count: number;
  /**
   * The live version, FOR A SOURCE WITH EXACTLY ONE ITEM. Null for every other source.
   *
   * NULL DOES NOT MEAN "NOTHING IS LIVE" — that is `active_version_count === 0` — and the two are
   * routinely different: a four-hundred-page crawl with every page serving reports `null` here.
   * Activation is a pointer on the ITEM and "the current version" of a multi-item source is a set
   * rather than a value, so there is no honest scalar to publish. A screen that renders this as the
   * source's version is correct on single-file uploads and silently wrong on everything else.
   */
  readonly active_version: SourceActiveVersionResource | null;
  /**
   * Distinct pages across the live versions, summed per version — a two-file upload of ten pages
   * each reports twenty. ZERO IS AMBIGUOUS BY DESIGN and the ambiguity is cheap: a format with no
   * pages (a deck, a spreadsheet, a crawled page) reports zero exactly as a source with no live
   * content does, so render the count that fits the `type` rather than every count for every source.
   */
  readonly page_count: number;
  /** Distinct slides, on the same basis as `page_count`. */
  readonly slide_count: number;
  /** Distinct spreadsheet sheets, on the same basis as `page_count`. */
  readonly sheet_count: number;
  /**
   * Structural elements — headings, paragraphs, list items, table rows, captions — extracted from
   * the live versions. The unit citations locate against, and the honest measure of "how much
   * document is here" for the formats whose page count is structurally zero.
   */
  readonly element_count: number;
  /**
   * Retrievable chunks derived from the live versions: the number of vectors a deletion removes and
   * the number a rebuild re-embeds at a provider's per-token price (ADR-030 — embedding is a metered
   * API call now, not a local model). THIS IS THE FIGURE A DELETE CONFIRMATION SHOULD STATE.
   */
  readonly chunk_count: number;
  /**
   * Advisory parser and OCR warnings from the live content, as codes with a version count each.
   * ALWAYS PRESENT, empty when there are none — never null, for the reason `tags` is never null.
   */
  readonly warnings: readonly SourceWarningResource[];
  /**
   * Whether more distinct warning codes exist than the list publishes. The code set is open (see
   * `SourceWarningResource`), so the list is capped rather than unbounded, and a true here is "there
   * is more of this kind of thing" rather than "something is hidden from you".
   */
  readonly warnings_truncated: boolean;
  /**
   * A BOUNDED excerpt of the extracted text of the live content, in document order, elements
   * separated by a blank line. Null when nothing has been extracted yet — never an empty string,
   * because "no content" and "the first element is blank" are different facts and a renderer that
   * conflates them shows a blank panel for both.
   *
   * IT IS UNTRUSTED TENANT DATA AND IT IS THE LEAST OBVIOUSLY UNTRUSTED FIELD ON THIS SHAPE, because
   * it reads as our own output rather than as somebody's input. It is document text: interpolate it
   * as a JSX child, never as HTML, never into a `style`, and never into a prompt. `kb-security-
   * baseline` owns the rendering rule and it is unconditional here — a CSP nonce does not cover
   * `dangerouslySetInnerHTML` and never covered `style=""`.
   */
  readonly content_preview: string | null;
  /**
   * Whether the excerpt was cut, by the character cap or by the element cap. TRUE IS THE ORDINARY
   * CASE for anything longer than a page, so render an ellipsis rather than a warning, and never
   * imply the document is as short as the excerpt.
   */
  readonly content_preview_truncated: boolean;
}

/**
 * The upload ceilings this deployment enforces, from
 * `GET /api/v1/organizations/{organization}/sources/upload-limits`, unwrapped from `data`.
 *
 * ── THE PUBLISHED COMPONENT IS `OrgUploadLimitsResource`; THIS TYPE IS `OrgUploadLimits` ────────
 * The same asymmetry `InvitationPreviewResource` ↔ `InvitationPreview` already carries, kept for the
 * same reason: the suffix names a PHP class, and nothing on a client is a "resource". The mapping is
 * not left to this sentence — the register in test/resource-drift.test.ts is keyed by WIRE name, so
 * the day the two spellings stop describing one shape is a red test rather than a reading.
 *
 * ── IT MOVED HERE FROM `src/forms/upload.ts`, AND WHY IT COULD LIVE THERE UNTIL NOW IS THE POINT ─
 * It was hand-written as the PARAMETER of a schema factory before any endpoint returned it: a shape
 * the client had invented so that `uploadSchema` could be a function of the organization's limits
 * rather than of a constant. Nothing published it, so nothing could pin it, and there was no second
 * copy for it to disagree with. Both facts changed with the intake. It is a published component now,
 * so the shape is the SERVER's; a mirror of a published response belongs where every other one lives,
 * under the three pins, and not behind the Zod subpath where only a form can reach it.
 *
 * ONE DEFINITION, TWO DOORS. `src/forms/upload.ts` re-exports the type (erased, so it costs the
 * `/forms` bundle nothing) and `uploadSchema`'s signature is byte-for-byte what it was — the four
 * call sites in apps/web that import `OrgUploadLimits` from `@kb/contracts/forms` keep working. What
 * is gone is the possibility of two spellings: there is no `OrgUploadLimitsResource` interface in
 * this package and there must not be one.
 *
 * ── READ THESE NUMBERS; NEVER RESTATE THEM ──────────────────────────────────────────────────────
 * §8.10 makes the per-file cap and the accepted media types PER-ORGANIZATION, so there is no byte
 * constant and no MIME constant anywhere in this package or in apps/web. A hard-coded ceiling renders
 * a form that accepts what the server refuses, or refuses what it accepts, for every organization but
 * the one the constant was copied from.
 *
 * ── `allowed_mime` IS NOT THE WHOLE ADMISSION RULE, AND A PICKER BUILT FROM IT ALONE OVER-ACCEPTS ─
 * The server admits a part only if the SNIFFED type is on this list AND the filename's final
 * extension is on a SECOND allow-list AND the two agree. The second list is not published on this
 * shape and this package must not invent it: three fields is what the component declares, and a
 * fourth here would be a client claiming to know a server rule nobody sent it.
 *
 * The consequence is concrete rather than theoretical, because the two sets are not in bijection:
 * distinct extensions share one sniffed type (`.md` and `.csv` both read as `text/plain` through
 * libmagic), so `accept="…,text/plain,…"` invites a `.txt` the extension step then refuses. That
 * refusal is a 422 keyed on the part, like any other server field error, and it is the ONLY place the
 * mismatch is visible — no client-side check can anticipate it. Render it; do not pre-empt it with a
 * guessed extension list, and do not describe the picker's filter to the user as what will be
 * accepted. If the over-acceptance is worth closing, it is closed by the server publishing the
 * extension allow-list on this component, which is `control-plane-engineer`'s call and not ours.
 */
export interface OrgUploadLimits {
  /**
   * The per-file ceiling in BYTES, directly comparable with a browser `File.size`.
   *
   * BYTES ON THIS WIRE EVEN THOUGH THE RULE IS ENFORCED IN KIBIBYTES — Laravel's file `max:` rule
   * speaks kibibytes and the conversion happens once, on the server, so this number and the enforced
   * one are the same number. A client that converts again enforces a ceiling 1024× off in whichever
   * direction it guessed.
   */
  readonly max_bytes: number;
  /**
   * Every media type the server may read out of an uploaded file's CONTENT, sorted.
   *
   * THESE ARE libmagic's ANSWERS, NOT THE BROWSER'S. `File.type` is derived from the extension on
   * most platforms and is forgeable by any caller; the server never reads it. So this list is an
   * `accept` hint and a fast local message, never the check — and never the whole rule even
   * server-side, per the extension paragraph above.
   */
  readonly allowed_mime: readonly string[];
  /**
   * The most files one `POST .../sources` may carry, as parts named `files[0]`, `files[1]`, … —
   * indexed even for a single file.
   *
   * A BATCH WITH ANY REFUSED PART CREATES NOTHING AT ALL, so this is a cap on what the user may
   * assemble before submitting rather than a number to trim a response against. A dropzone that lets
   * more than this be selected is offering an all-or-nothing request that cannot succeed.
   */
  readonly max_batch: number;
}
