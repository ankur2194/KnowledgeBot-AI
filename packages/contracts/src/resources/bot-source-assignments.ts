/**
 * The bot↔source grant — the three endpoints under
 * `/api/v1/organizations/{organization}/bots/{bot}/source-assignments` — as the console reads them.
 *
 * ── TYPES ONLY. ZERO RUNTIME VALUES. ─────────────────────────────────────────────────────────────
 * Same rule as every other module under `src/resources/`, for the same reason: this module is
 * re-exported from `src/index.ts`, budgeted at <=1 kB brotli inside apps/widget's app shell, and only
 * an erased `export type` re-export keeps that free. This one has nothing that even wants to be a
 * value — it declares no closed vocabulary at all, since `priority` is an integer and `enabled` is a
 * boolean — so the assertion in test/resource-drift.test.ts is the whole of the discipline here.
 *
 * ── WHY A THIRD MODULE RATHER THAN A SECTION OF `bots.ts` OR OF `sources.ts` ────────────────────
 * The shape is the JOIN of two surfaces: it embeds a `SourceResource` and it pages with the shared
 * `ListMetaResource`, which live in `sources.ts` and `bots.ts` respectively. `bots.ts` is the module
 * these endpoints' path most obviously belongs to — `BotDomainResource` and
 * `BotStarterQuestionResource` are child collections under a bot and they live there — but putting
 * this one there makes `bots.ts` import from `sources.ts` while `sources.ts` already imports
 * `ListMetaResource` from `bots.ts`, i.e. a MUTUAL import between two modules. It would be erased
 * today, because neither module may hold a runtime value; but "this cycle is harmless" would then be
 * a consequence of a rule enforced by one text assertion in one of the two files, which is a thin
 * thing for a cycle to rest on. `sources.ts` is worse still: its own docblock scopes it to the
 * `/sources` endpoints, and these are not those. So the join gets its own file, imports from both,
 * and neither of them learns about it.
 *
 * ── THE GRANT IS THE RESOURCE, NOT THE SOURCE ───────────────────────────────────────────────────
 * `DELETE` addresses the assignment's own ULID rather than the source's, because what is being
 * withdrawn is the permission and not the document. The distinction is the whole shape: removing an
 * assignment leaves the source, its versions, its chunks and its vectors exactly where they were and
 * changes only which bots may retrieve them — which is why this operation is instant and reversible
 * where `DELETE .../sources/{source}` is a two-phase removal that somebody has to prove finished.
 *
 * ── snake_case verbatim ─────────────────────────────────────────────────────────────────────────
 * A straight carry of the JSON, like every sibling module.
 */

import type { ListMetaResource } from './bots.js';
import type { SourceResource } from './sources.js';

/**
 * One grant: this bot may answer from this knowledge source.
 *
 * It is the row `bot_ids` is resolved from — one of the four mandatory vector-search filter terms
 * (`kb-tenancy-isolation` NN 2) — so it is what makes a corpus REACHABLE from a bot rather than a
 * preference about it. That also means it is never the whole answer on its own: see `enabled`.
 */
export interface BotSourceAssignmentResource {
  /**
   * ULID of the ASSIGNMENT, and the key `DELETE` addresses — not the source id. Withdrawing a grant
   * and deleting a document are different operations on different rows, and a client that routed the
   * first through the second's identifier would be one path parameter away from the second.
   */
  readonly id: string;
  /**
   * ULID of the granted source, identical to `source.id`. Published at the top level because it is
   * the value a client POSTS to create the equivalent grant on another bot — the duplication is the
   * server's, deliberately, so a "copy assignments to…" control never has to reach into the nested
   * object to build its request body.
   */
  readonly source_id: string;
  /**
   * The operator's preference between this bot's sources.
   *
   * A TIE-BREAK A RETRIEVAL STAGE MAY CONSULT AND NEVER A FILTER: a lower priority must not make a
   * source unretrievable, because "less important" and "not visible" are different statements and
   * only the second one is `enabled`. Zero is the default and the floor. NOTHING IN THIS PLATFORM
   * RANKS ON IT YET, so the honest rendering today is a stable display order — a UI that describes it
   * as affecting answers is describing a behaviour that does not exist.
   */
  readonly priority: number;
  /**
   * Whether this grant is live. Distinct from disabling the SOURCE, which removes it from every bot
   * at once; this switch is per bot.
   *
   * ONE TERM OF FOUR AND NEVER THE WHOLE ANSWER — reachability is the AND of the organization, this
   * flag, the source's status (`status_permits_retrieval`) and the item's active-version pointer
   * (`active_version_count` on `SourceDetailResource`). Do not render it as "this bot is answering
   * from this document"; a source can be granted, enabled, and still answering nothing because its
   * ingestion never completed.
   */
  readonly enabled: boolean;
  /** ISO 8601 with offset. When the grant was made. A string, not a `Date`. */
  readonly created_at: string | null;
  /**
   * ISO 8601 with offset. IN PRACTICE EQUAL TO `created_at`, and the reason is worth knowing before
   * a screen offers an edit control: there is no endpoint that edits an assignment, because the audit
   * catalog defines a created and a deleted operation and no updated one. Changing the priority or
   * the off switch is a delete and a re-create, and the trail then says both things happened.
   */
  readonly updated_at: string | null;
  /**
   * The granted source, IN THE SAME SHAPE the source list publishes — `SourceResource`, imported
   * rather than re-declared, and asserted to be a `$ref` to that component in
   * test/resource-drift.test.ts. A near-identical `AssignedSource` beside this interface is exactly
   * the duplication that suite exists to catch, and a nested resource is where it happens.
   *
   * NOT `SourceDetailResource`: the counts, the warnings and the content preview are the detail
   * endpoint's, and a list of grants that carried a text excerpt per row would be shipping tenant
   * document content into a table that has nowhere to escape it.
   *
   * ALWAYS PRESENT, so there is no null case to branch on: the composite foreign key
   * `bot_source_assignments_source_same_org` refuses an assignment whose source is absent or belongs
   * to another organization. That constraint is the tenancy guarantee this shape rests on — a grant
   * cannot point across organizations even if something upstream tried to make one.
   */
  readonly source: SourceResource;
}

/**
 * One page of the grants that decide which knowledge sources a bot may answer from.
 *
 * The array sits under a NAMED KEY beside `meta` — the same forced nesting every collection in this
 * package carries: `ResponseShape` maps a response key to a schema class and has no shape meaning "an
 * array of", and every published component must be `additionalProperties: false`, which an
 * array-typed schema cannot be. So `{"data": {"source_assignments": [...], "meta": {...}}}`.
 *
 * `meta` IS THE SHARED `ListMetaResource`, imported rather than re-declared — the third paginated
 * list in this package, and the third place a near-identical `PaginationMeta` would have been
 * declared beside the collection. test/resource-drift.test.ts reads the `$ref` for exactly that,
 * because a property-name comparison sees `meta` either way.
 *
 * DISABLED GRANTS ARE INCLUDED. A disabled row grants nothing, and hiding it would make "why is this
 * bot not answering from that document" unanswerable from the console while the row sat in the table
 * — the same argument `SourceCollectionResource` makes about sources whose purge is in flight.
 */
export interface BotSourceAssignmentCollectionResource {
  readonly source_assignments: readonly BotSourceAssignmentResource[];
  readonly meta: ListMetaResource;
}
