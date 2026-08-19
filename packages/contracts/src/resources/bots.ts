/**
 * The bot admin surface — the five endpoints under `/api/v1/organizations/{organization}/bots` — and
 * the pagination envelope every list in this API shares.
 *
 * ── TYPES ONLY. ZERO RUNTIME VALUES. ─────────────────────────────────────────────────────────────
 * Same rule as `session.ts`, `members.ts`, `providers.ts` and `provider-models.ts`, for the same
 * reason: this module is re-exported from `src/index.ts`, budgeted at <=1 kB brotli inside
 * apps/widget's app shell, and only an erased `export type` re-export keeps that free. So the five
 * closed vocabularies below are UNIONS and not tuples; their runtime spellings live behind
 * `@kb/contracts/forms` (`BOT_STATUSES` and friends in src/forms/bot.ts), exactly as `Role` is a
 * union here and `ORG_ROLES` is a tuple there. Each spelling is pinned to the server independently —
 * the unions by the enum comparison in test/resource-drift.test.ts, the tuples by the `in:` probes in
 * test/form-drift.test.ts — so neither can drift without a red suite.
 *
 * ── THIS IS THE ADMIN SHAPE AND IT CARRIES THE SYSTEM INSTRUCTION ───────────────────────────────
 * `BotResource` is an AUTHENTICATED-ONLY shape. The hosted-chat, widget and stylesheet surfaces
 * resolve a bot by `public_bot_id` and publish their own, much smaller, resources; nothing here
 * reaches them. Two fields make that non-negotiable rather than tidy: `system_instruction` is the
 * bot's own prompt, and `id` is a term in every vector query issued on the bot's behalf.
 *
 * ── NOTHING OF THE PARENT CONNECTION BEYOND ITS ULID ────────────────────────────────────────────
 * No vendor, no label, no masked credential — the same property `ProviderModelResource` holds, and
 * for the same reason: a client that could read key material is a client that could put it in a form.
 *
 * ── snake_case verbatim ─────────────────────────────────────────────────────────────────────────
 * A straight carry of the JSON. A rename layer is a place for a typo to read `undefined` with
 * nothing thrown.
 */

/** Lifecycle. Only `published` is reachable by an end user on a public channel. */
export type BotStatus = 'draft' | 'testing' | 'published' | 'paused' | 'archived';

/** Whether an anonymous end user may converse. NOT the origin allow-list and no substitute for one. */
export type BotAccessMode = 'public' | 'private';

/** Whether general model knowledge may be used at all. */
export type BotAnswerMode = 'strict' | 'rag_first';

/**
 * What an evidence threshold is measured in. `logit` is unbounded and signed; the other two are
 * bounded 0-1 and are NOT interchangeable — only the bounds transfer, and the bounds are not the
 * calibration. There is deliberately no `uncalibrated` member: that would be the statement that no
 * characterization exists, which makes a stored threshold a contradiction rather than a value.
 */
export type EvidenceThresholdScale = 'logit' | 'sigmoid' | 'unit_interval';

/**
 * The corner radius, matched EXACTLY against the values `packages/design-tokens` publishes. The
 * renderer compares the stored string against that set and drops anything else, so an arbitrary CSS
 * length is refused on write rather than silently ignored on render.
 */
export type BotThemeRadius = '0rem' | '0.25rem' | '0.5rem' | '0.625rem' | '0.75rem' | '1rem';

/**
 * The tenant-supplied theme: token VALUES, never CSS.
 *
 * THE ONLY TYPE IN THIS PACKAGE WITH OPTIONAL MEMBERS, and it is optional because the WIRE is: the
 * published component lists all three properties and requires none of them, which is the one place
 * `DumpOpenApiCommand` emits a partial object. Everywhere else "the client types have no `?` on any
 * member" is asserted for every mirrored component, and this object is not a component — it is
 * inlined into `BotResource.theme`, so the assertion does not reach it and this note is the record.
 *
 * ALWAYS AN OBJECT, never null and never an array: `{}` is the unthemed state, which is the normal
 * one and means "render the platform theme". Every other custom property the renderer writes — the
 * whole `-foreground` and accent-ramp family — is DERIVED from these at render time and is never
 * settable, because contrast is derived and never chosen (kb-design-language).
 *
 * BOTH COLOURS ARE TENANT-SUPPLIED STRINGS. They are validated against an exact `oklch()` grammar on
 * write and matched against it again at render; a value reaching `<style>` unchecked is CSS
 * injection with a tenant string, so treat these as data and never as markup.
 */
export interface BotTheme {
  /** The brand accent as a CSS `oklch()` triple. The whole accent ramp is derived from it. */
  readonly primary?: string;
  /** The neutral hover wash as a CSS `oklch()` triple. NOT a second brand colour. */
  readonly accent?: string;
  readonly radius?: BotThemeRadius;
}

/**
 * One bot as the ADMIN console sees it: identity, voice, lifecycle, model selection, retrieval
 * configuration, appearance, limits and consent.
 */
export interface BotResource {
  /**
   * ULID of the bot, and the key the ADMIN surface addresses it by. NEVER handed to an end user: it
   * is a term in every vector query issued on this bot's behalf, and its leading characters are a
   * millisecond timestamp.
   */
  readonly id: string;
  /**
   * The opaque token a hosted-chat URL, a widget snippet and a theme stylesheet request carry.
   * Globally unique, because it is resolved with no organization in hand. SERVER-MINTED ONCE AND
   * NEVER EDITABLE — changing it would break every live embed on the customer's own site — which is
   * why no form schema in this package names it. It authorizes nothing on its own.
   */
  readonly public_bot_id: string;
  /** Operator-supplied name. TENANT-CONTROLLED TEXT: interpolate it as a JSX child, never as HTML. */
  readonly name: string;
  /**
   * The human handle, lower-case with internal hyphens, unique PER ORGANIZATION. Another
   * organization using the same handle is not a conflict — which is why every cache key and
   * rate-limit counter derived from it carries the organization id first.
   */
  readonly slug: string;
  /** Internal note for the console. Null means not set; the empty string is not a second spelling. */
  readonly description: string | null;
  /** The first-run greeting. Null renders the platform default, which is a real state. */
  readonly welcome_message: string | null;
  /** The composer's placeholder. Null renders the platform default. */
  readonly placeholder_text: string | null;
  /**
   * The bot's own system prompt. AUTHENTICATED SURFACES ONLY — it appears on no public endpoint, and
   * retrieved source text can never alter it (that boundary is the data plane's).
   */
  readonly system_instruction: string | null;
  /**
   * Tone and formatting guidance, kept apart from the system instruction so a voice change is not a
   * change to the grounding rules.
   */
  readonly answer_style_instruction: string | null;
  /**
   * WHETHER THE TWO FIELDS ABOVE CARRY THEIR STORED VALUES IN *THIS* BODY. It is the server stating
   * its own projection, and it exists because the `null` above otherwise carries two facts a client
   * cannot tell apart.
   *
   * `false` means WITHHELD — the caller does not hold `bots.manage` on this bot's organization — so
   * the `null` is the projection rather than the bot's state. `true` means the null is the bot's own
   * "not set".
   *
   * ── THE WRONG READING IS DESTRUCTIVE, WHICH IS WHY THIS IS A FIELD AND NOT A CONVENTION ────────
   * `UpdateBotRequest` rules both instruction fields `sometimes|nullable|string`, so an omitted key
   * is left alone and a present `null` CLEARS THE COLUMN and returns 200. A form seeded from a
   * withheld body therefore writes `null` over an operator-authored prompt on the next save of any
   * unrelated field. `botFormDefaults` (src/forms/bot.ts) refuses to seed either key when this is
   * false, which is what makes the omission structural rather than remembered.
   *
   * ── AND IT IS NOT DERIVABLE FROM A ROLE THE CLIENT HOLDS ───────────────────────────────────────
   * The grant is resolved per record against the record's OWN organization, so a client that
   * re-derives it from a session role is answering a different question — and answering it from a
   * row that may have been fetched under a different membership. Read this key; never a role map.
   */
  readonly instructions_visible: boolean;
  readonly status: BotStatus;
  readonly access_mode: BotAccessMode;
  /**
   * ULID of the provider connection whose credential answers for this bot. A REFERENCE and nothing
   * more — the key itself is envelope-encrypted and decrypted only inside the request that uses it.
   * Null on a bot that has not been configured yet, which is the first state every bot is in.
   */
  readonly provider_connection_id: string | null;
  /**
   * ULID of the catalog row this bot answers with, which must be registered under
   * `provider_connection_id`. A model with no connection names no credential and is refused.
   */
  readonly provider_model_id: string | null;
  readonly answer_mode: BotAnswerMode;
  /** Candidates from the dense arm. A starting point, moved by an evaluation run and not by intuition. */
  readonly dense_top_k: number;
  /** Candidates from the BM25 arm, fused with the dense arm by RRF. */
  readonly sparse_top_k: number;
  readonly rerank_candidates: number;
  /** Never more than `rerank_candidates`: reranking can only reorder what retrieval handed it. */
  readonly rerank_retain: number;
  /**
   * The score below which evidence is treated as insufficient and the bot refuses. NULL WITH NO
   * PLATFORM DEFAULT, deliberately: the scale is a property of the (provider, model) pair, and
   * applying one scale's number to another moves only the refusal rate, only in aggregate, and
   * raises nothing anywhere. Always read beside `evidence_threshold_scale`; either without the other
   * is not a state this row can hold.
   */
  readonly evidence_threshold: number | null;
  readonly evidence_threshold_scale: EvidenceThresholdScale | null;
  /**
   * Increments when — and only when — a retrieval knob's VALUE changes. It travels into the
   * configuration snapshot and into every retrieval trace, and a trace without it cannot be
   * replayed. DERIVED, so no form schema here declares it: re-sending an unchanged value does not
   * move it, which is what lets the console submit its whole form on every save.
   */
  readonly retrieval_configuration_version: number;
  /**
   * The one field an operator must set on purpose before a bot may answer from anything but its
   * sources. Separate from `answer_mode` so that "RAG-first but not yet cleared to publish" stays
   * expressible; publishing the pair as `rag_first` + false is refused.
   */
  readonly allow_general_answers: boolean;
  readonly theme: BotTheme;
  /**
   * Per-bot message limit. NULL means the platform default applies, which is a different fact from a
   * configured limit that happens to equal it. Zero is not expressible: a limit of zero is a bot that
   * answers nobody and is a plausible typo for "no limit".
   */
  readonly rate_limit_per_minute: number | null;
  /** Same reading as the per-minute limit. */
  readonly rate_limit_per_day: number | null;
  /** Conversation retention override in days. NULL means the organization's own policy decides. */
  readonly retention_days: number | null;
  /** Whether the chat surface asks an end user for identifying details. */
  readonly collect_end_user_data: boolean;
  /**
   * The disclosure rendered before the first message when `collect_end_user_data` is true. Non-null
   * whenever that flag is true; permitted on its own, which is the state of a bot whose operator
   * wrote the disclosure before switching collection on.
   */
  readonly consent_text: string | null;
  /** RFC 3339 with offset. Null only for a record whose timestamp was never set. */
  readonly created_at: string | null;
  readonly updated_at: string | null;
}

/**
 * Pagination and applied-query state for ONE PAGE OF ANY LIST.
 *
 * IT IS NOT BOT-SPECIFIC, and it lives in this module only because bots is the first paginated list
 * this package mirrors. One `ListMetaResource` component serves every list endpoint in the API
 * (`App\Http\Resources\Concerns\PaginatedCollection`), so the SECOND paginated list imports it from
 * here rather than declaring a near-identical `PaginationMeta` beside itself — which is exactly the
 * duplication `test/resource-drift.test.ts` was rewritten to catch after `MemberResource` was
 * hand-written a second time in apps/web with no mechanical link to anything.
 *
 * ── THE FOUR QUERY FIELDS ARE WHAT THE SERVER APPLIED, NOT WHAT THE CLIENT ASKED FOR ────────────
 * `per_page` is clamped to the platform maximum and `sort`/`dir` fall back to the endpoint default,
 * so a client that recomputed a page count from its own request parameters would be wrong on every
 * clamped response. Read `total` and `total_pages` off this object; do not derive them.
 */
export interface ListMetaResource {
  /**
   * The 1-BASED page number this body is — matching Laravel's paginator and every `?page=` a client
   * sends back. TanStack Table's `PaginationState.pageIndex` is zero-based, so the conversion belongs
   * at the one place that reads this and nowhere else.
   */
  readonly page: number;
  /** Rows per page AS APPLIED. May be smaller than requested; the platform caps it. */
  readonly per_page: number;
  /**
   * Total matching rows across all pages, within this organization and after the filter. This is
   * TanStack Table's `rowCount` under `manualPagination`, and without it the pager reads
   * "Page 1 of 1" over a four-thousand-row set.
   */
  readonly total: number;
  /**
   * Total pages at the APPLIED `per_page`. Published rather than derived, because the client's
   * `per_page` may not be the one the server used. This is TanStack Table's `pageCount`. It is 1 and
   * not 0 for an empty list — page one exists and is empty.
   */
  readonly total_pages: number;
  /**
   * The column the rows are ordered by, AS APPLIED. Always one of the endpoint's own closed sortable
   * set — a caller-chosen value reaches an ORDER BY — so the set is published in that endpoint's
   * request rules rather than here, and this is typed `string` for that reason.
   */
  readonly sort: string;
  readonly dir: 'asc' | 'desc';
  /**
   * The free-text filter as applied: trimmed, and NULL rather than an empty string, because an empty
   * filter is no filter and two spellings of "unfiltered" would make an unfiltered list's cache key
   * depend on whether the client sent the parameter at all.
   */
  readonly filter: string | null;
}

/**
 * One page of an organization's bots.
 *
 * The array sits under a NAMED KEY beside `meta` — the same forced nesting
 * `ProviderModelCollectionResource` carries: `ResponseShape` maps a response key to a schema class
 * and has no shape meaning "an array of", and every published component must be
 * `additionalProperties: false`, which an array-typed schema cannot be. So
 * `{"data": {"bots": [...], "meta": {...}}}`.
 *
 * EVERY LIFECYCLE STATE IS INCLUDED, drafts and archived rows alike. An operator asking "where did
 * that bot go" has to be able to find it, and hiding a state would make the question unanswerable
 * from the console while the row sat in the table.
 *
 * `meta` IS PRESENT ON AN EMPTY PAGE TOO. A client that had to branch on its absence would be
 * branching on "did this list have results", which is the question `total` answers — and a missing
 * envelope is not an empty list: rendering the first-run empty state to an administrator whose
 * organization has two hundred bots is indistinguishable from data loss at a glance.
 */
export interface BotCollectionResource {
  readonly bots: readonly BotResource[];
  readonly meta: ListMetaResource;
}

/**
 * Lifecycle of one origin allow-list entry.
 *
 * `pending` is where every row starts and it grants nothing — whether an origin is under the
 * operator's control is not something the entry form knows. `active` is the ONE value that permits
 * an embed. `disabled` is a withdrawn row, kept so it can be turned back on without retyping and so
 * audit entries naming it still resolve.
 *
 * A union here and a tuple (`BOT_DOMAIN_STATUSES`) behind `@kb/contracts/forms`, for the reason
 * every vocabulary in this module is: this file is re-exported from the ROOT entry and must emit no
 * runtime value at all.
 */
export type BotDomainStatus = 'pending' | 'active' | 'disabled';

/**
 * One origin on a bot's widget allow-list.
 *
 * THE ROW IS A SECURITY CONTROL RATHER THAN A PREFERENCE: it is what lets a page on the public
 * internet boot a chat widget that speaks with this organization's credential, on its corpus,
 * against its quota.
 */
export interface BotDomainResource {
  /** ULID of the allow-list entry. */
  readonly id: string;
  /**
   * The exact origin in the serialisation a browser sends: scheme, host and non-default port,
   * lower-cased, with no path, no query, no fragment and no trailing slash.
   *
   * RENDER THIS, NEVER THE VALUE THAT WAS SUBMITTED. The server normalises on write — the scheme and
   * host are lower-cased, a single trailing slash is dropped, and a default port (`:80` for http,
   * `:443` for https) is dropped because the browser omits it — so echoing the input shows the
   * operator a string that is not what was stored, on the one screen where "what was stored" is the
   * whole question. A non-empty path is REFUSED rather than trimmed, because trimming it would widen
   * the grant from one page to a whole host.
   *
   * THERE IS NO WILDCARD GRAMMAR. The comparison is byte equality; a pattern would turn it into a
   * matcher, and a matcher is where the bypasses live.
   */
  readonly origin: string;
  readonly status: BotDomainStatus;
  /**
   * Whether a widget served from this origin may boot, as far as THIS ROW is concerned. True for
   * `active` and nothing else.
   *
   * READ THIS FIELD RATHER THAN COMPARING `status` YOURSELF: a check written as "not disabled"
   * admits `pending`, and a status added later would be admitted by every negative test in every
   * client. It is also only one term of the runtime decision — the bot's status, its access mode and
   * an exact match on the origin are the others, and an EMPTY allow-list denies every origin.
   */
  readonly permits_embedding: boolean;
  /** ISO 8601 with offset. Null only for a record whose timestamp was never set. */
  readonly created_at: string | null;
  /** Moves when the status changes; the origin itself is immutable. */
  readonly updated_at: string | null;
}

/**
 * One bot's widget origin allow-list. An OBJECT wrapping the array, the same forced nesting every
 * collection in this package carries, so pagination fields can join it later without moving the list
 * or versioning the endpoint.
 *
 * PENDING AND DISABLED ROWS ARE INCLUDED. Filtering them out would make "why is my widget refused on
 * this site" unanswerable from the console while the row sat in the table.
 *
 * AN EMPTY ARRAY DENIES EVERY ORIGIN. The embed decision is "some active row matches this exact
 * origin", which is false for the empty set — never read it as "unrestricted".
 */
export interface BotDomainCollectionResource {
  readonly domains: readonly BotDomainResource[];
}

/** One suggested starter question, at the position the operator set. */
export interface BotStarterQuestionResource {
  /** ULID of the question. */
  readonly id: string;
  /**
   * The chip label, exactly as the operator typed it. TENANT-AUTHORED TEXT: interpolate it as a JSX
   * child and escape it at render in every client, because only the renderer knows the context it is
   * entering. Never blank — the database refuses a whitespace-only value, since a chip with no label
   * is a control an end user can see, can click, and cannot read.
   */
  readonly question: string;
  /**
   * Zero-based position, unique within the bot. The set of positions is always 0..n-1 with no gaps:
   * every write re-sequences the whole list inside one transaction, so render straight from this and
   * treat a gap as a defect rather than as a state.
   *
   * THE CONSEQUENCE FOR A CLIENT: re-read the collection after a move or a delete. A client-side
   * splice desynchronises `sort_order` against a list the server has already re-sequenced, and the
   * symptom is a chip order that is right until the next reload.
   */
  readonly sort_order: number;
  /** ISO 8601 with offset. */
  readonly created_at: string | null;
  /**
   * Moves when the text or the position changes — INCLUDING when another question was moved past
   * this one, because a reorder rewrites every row in the list.
   */
  readonly updated_at: string | null;
}

/**
 * One bot's starter questions, ordered by `sort_order` ascending, which is the order they render in.
 *
 * At most six, matching the ceiling the chat surface renders (kb-ai-chat-ux: three to six chips), so
 * what is stored is what is shown and the console cannot promise a seventh chip no client draws. An
 * empty array is the default state of every new bot and simply means the first-run screen shows no
 * suggestions.
 */
export interface BotStarterQuestionCollectionResource {
  readonly starter_questions: readonly BotStarterQuestionResource[];
}
