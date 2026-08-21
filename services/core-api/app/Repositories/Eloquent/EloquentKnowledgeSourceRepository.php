<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Enums\SourceState;
use App\Models\Chunk;
use App\Models\DocumentElement;
use App\Models\KnowledgeSource;
use App\Models\SourceItem;
use App\Models\SourceVersion;
use App\Repositories\Contracts\KnowledgeSourceRepositoryInterface;
use App\Services\Sources\ActiveSourceVersion;
use App\Services\Sources\IllegalSourceTransition;
use App\Services\Sources\IngestionApplication;
use App\Services\Sources\IngestionProgress;
use App\Services\Sources\NewSource;
use App\Services\Sources\NewSourceItem;
use App\Services\Sources\SourceChildSummary;
use App\Services\Sources\SourceContentSummary;
use App\Services\Sources\SourceEdit;
use App\Services\Sources\SourceWarningCount;
use App\Services\Sources\VersionIdentity;
use App\Support\Http\ListQuery;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class EloquentKnowledgeSourceRepository implements KnowledgeSourceRepositoryInterface
{
    /**
     * The columns a free-text filter searches.
     *
     * NAME AND ORIGIN URL. `name` is what an operator types when looking for a source they know
     * exists; `origin_url` is what a SECURITY reviewer types, because "which sources cause us to
     * make outbound requests to that host" is the question this column exists to answer and it is
     * asked with a hostname rather than a label.
     *
     * `description` is deliberately absent: it is prose, an `ILIKE '%…%'` over it is a sequential
     * scan of every row's full text, and a match inside a description surfaces a source whose NAME
     * has nothing to do with the search, which reads as the filter being broken. `tags` is absent
     * for a different reason — it is a `text[]`, so an `ILIKE` cannot address it at all and the
     * correct operator is array containment, which is a filter of its own rather than free text.
     *
     * @var list<string>
     */
    private const FILTERABLE = ['name', 'origin_url'];

    /**
     * The ceiling on how many `document_elements` rows the preview reads.
     *
     * TWENTY is a couple of pages of prose and far more than any preview pane renders. It bounds the
     * ROW count; `PREVIEW_MAX_CHARACTERS` bounds each row, and both bounds are in the SQL because a
     * cap applied only in PHP still transfers the whole column across the wire.
     */
    private const PREVIEW_MAX_ELEMENTS = 20;

    /**
     * The ceiling on the preview, in characters, applied per element in SQL and again to the joined
     * string in PHP.
     *
     * `substr()` in PostgreSQL counts CHARACTERS rather than bytes, so a cut never lands inside a
     * multi-byte sequence and what comes back is always valid UTF-8. The PHP pass uses `mb_substr()`
     * for the same property on the joined string.
     */
    private const PREVIEW_MAX_CHARACTERS = 1000;

    /**
     * The separator between elements in the preview.
     *
     * A BLANK LINE, because the elements it joins are a heading, a paragraph, a list item and a
     * serialized table row — adjacent in document order and unrelated as prose. Running them
     * together with a single newline produces a paragraph that says something none of them said,
     * which on a page an administrator reads to decide whether a document parsed correctly is
     * exactly the wrong artefact.
     */
    private const PREVIEW_SEPARATOR = "\n\n";

    /**
     * The ceiling on how many distinct warning CODES the projection publishes.
     *
     * The key set of `warning_summary` belongs to the data plane and is enumerated nowhere on this
     * side, so it is unbounded by construction — which makes an unbounded projection of it an
     * unbounded response body. Twenty-five is far past any real parse and short enough that a
     * console can render the whole list.
     */
    private const MAX_WARNING_CODES = 25;

    /**
     * The ceiling on one warning code's length, in characters.
     *
     * A bound on OUR OWN data plane rather than on hostile input — these keys are written by
     * `services/ai-service` and not by a tenant — and it is here because "the key set is the data
     * plane's" cuts both ways: nothing on this side constrains what it may write, so nothing on
     * this side may assume a length.
     */
    private const MAX_WARNING_CODE_LENGTH = 128;

    /**
     * @return LengthAwarePaginator<int, KnowledgeSource>
     */
    public function paginate(string $organizationId, ListQuery $query): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, KnowledgeSource> $page */
        $page = $this->scoped($organizationId)
            ->when(
                $query->filter !== null,
                // A CLOSURE GROUP AND NOT TWO CHAINED `orWhere`s. Without the grouping the SQL is
                // `organization_id = ? AND name ILIKE ? OR origin_url ILIKE ?`, and `AND` binds
                // tighter than `OR` — so the second disjunct carries NO TENANT PREDICATE and the
                // endpoint returns every organization's sources whose crawl target happens to
                // match. It is one of the few ways to write a cross-tenant leak that looks like a
                // formatting choice, which is why the isolation test filters on a term BOTH
                // organizations match.
                fn (Builder $builder): Builder => $builder->where(
                    function (Builder $group) use ($query): void {
                        $term = '%'.$this->escapeLike((string) $query->filter).'%';

                        foreach (self::FILTERABLE as $column) {
                            // PostgreSQL's own operator rather than `whereRaw('lower(...)')`, so
                            // the value stays a bound parameter and never becomes SQL.
                            $group->orWhere($column, 'ilike', $term);
                        }
                    },
                ),
            )
            ->orderBy($query->sort, $query->direction->value)
            // THE TIE-BREAK IS NOT OPTIONAL, and it is appended even when the sort column IS `id`.
            // `name`, `type` and `status` are all non-unique within an organization, so without a
            // total order PostgreSQL may legally return a row on page 2 that it already returned on
            // page 1 — and the duplicate is invisible until somebody counts.
            ->orderBy('id')
            ->paginate(perPage: $query->perPage, page: $query->page);

        return $page;
    }

    public function find(string $organizationId, string $sourceId): ?KnowledgeSource
    {
        return $this->scoped($organizationId)->whereKey($sourceId)->first();
    }

    /**
     * @param  non-empty-list<NewSourceItem>  $items
     * @param  Closure(KnowledgeSource, list<SourceItem>): void  $audit
     */
    public function create(
        string $organizationId,
        string $sourceId,
        NewSource $input,
        ?string $createdBy,
        array $items,
        string $jobId,
        Closure $audit,
    ): KnowledgeSource {
        return DB::transaction(function () use (
            $organizationId, $sourceId, $input, $createdBy, $items, $jobId, $audit,
        ): KnowledgeSource {
            $source = new KnowledgeSource;

            // ── THE KEY IS ASSIGNED, NOT GENERATED, AND THAT IS STILL ONE GENERATION SITE ────
            //
            // `HasUniqueIds::setUniqueIds()` runs on `creating` and assigns only when the column is
            // EMPTY, so a value provided here means the trait's generator is never reached for this
            // row — it is not generating an id that is then thrown away. The caller minted this
            // value by asking the model itself (`KnowledgeSource::newUniqueId()`), so the format is
            // defined in exactly one place even though the call now happens one layer up.
            //
            // WHY IT HAPPENS ONE LAYER UP AT ALL: the pasted body is written to object storage
            // BEFORE this transaction opens, because object storage cannot join it, and its key is
            // scoped to the source (`App\Support\Kb\ObjectKey`). An id that first existed at INSERT
            // time could not appear in that key, and the workaround for that — a key built only
            // from values available at intake — is precisely what produced an object the deletion
            // sweep could not reach.
            $source->id = $sourceId;

            // THE FIVE COLUMNS OUTSIDE $fillable, ASSIGNED HERE AND ONLY HERE. `organization_id`
            // comes from the authenticated context passed in as an argument — never from the DTO,
            // which has no member for it, and never from request input. `created_by` is an
            // ATTRIBUTION and an attribution a client can set is not one. `status` is a lifecycle
            // state. `deleted_at` and `purged_at` are the two halves of a verified deletion and are
            // never written here.
            $source->organization_id = $organizationId;
            $source->created_by = $createdBy;

            $source->type = $input->type;
            $source->name = $input->name;
            $source->description = $input->description;
            $source->origin_url = $input->originUrl;
            $source->tags = $input->tags;
            $source->effective_at = $input->effectiveAt;
            $source->expires_at = $input->expiresAt;

            // ASSIGNED RATHER THAN LEFT TO THE COLUMN DEFAULT, for the reason
            // `EloquentBotRepository::create()` records: an attribute the INSERT never mentioned is
            // null on the model afterwards, so the 201 body and the audit row would both read a
            // null `status` off a row the database has correctly stored as `draft`. The column
            // default stays the authority for every writer that is not this one.
            $source->status = SourceState::Draft;

            $source->save();

            // ── THE ITEMS. EVERY SOURCE HAS AT LEAST ONE, INCLUDING A SINGLE-FILE UPLOAD ────
            //
            // ONE LOOP AND NO ONE-ITEM SHORTCUT ANYWHERE. A paste arrives here as a list of one and
            // takes the same statements a ten-file batch takes, which is what the `source_items`
            // migration means by "there is no special case for a one-item source and there must
            // never be one".
            //
            // `$fillable` on SourceItem is EMPTY on purpose — not one column on that table is a
            // form field — so every column is assigned explicitly and `Model::shouldBeStrict()`
            // turns a `fill()` naming any of them into an exception rather than a silent drop.
            //
            // CREATED IN THE CALLER'S ORDER, one INSERT each rather than a bulk insert, because
            // `HasUlids` mints the id in PHP per model: a ULID sorts by creation time, `itemsFor()`
            // reads them back ordered by `id`, and `IngestionSubmission::fingerprint()` hashes them
            // in that order. A bulk insert would not run the trait at all.
            $rows = [];

            foreach ($items as $item) {
                $row = new SourceItem;
                $row->organization_id = $organizationId;
                $row->source_id = $source->id;
                $row->canonical_key = $item->canonicalKey;
                // The live URL, separate from the canonical key because normalization is lossy on
                // purpose and a citation has to link to something a human can open. Null for an
                // upload: a file has no origin to link back to.
                $row->url = $item->url;
                $row->title = $item->title;
                // THE USER'S FILENAME, AND NEVER A PATH. Null for anything that did not arrive as a
                // file; `source_items_display_name_is_not_a_path` refuses a separator, a control
                // character and the two directory-relative names on the way in, and `UploadIntake`
                // refuses the same shapes one layer earlier so the refusal is a 422 rather than a
                // constraint violation.
                $row->display_name = $item->displayName;
                $row->storage_key = $item->storageKey;
                $row->content_hash = $item->contentHash;
                $row->mime = $item->mime;
                $row->byte_size = $item->byteSize;
                $row->current_version_id = null;
                $row->last_discovered_at = null;
                $row->missing_count = 0;
                // THE CALLBACK GUARD, CLAIMED BY THE SAME STATEMENT THAT CREATES THE ROW. A job id
                // with no sequence reset, or a reset with no job id, is half a guard. EVERY item of
                // the batch carries the same job id, because one submission walks all of them.
                $row->current_job_id = $jobId;
                $row->progress_sequence = 0;
                $row->save();

                $rows[] = $row;
            }

            // `Draft -> Queued` — an edge of the table, asked through the table. `$verified` is
            // false and it does not matter here: the verification gate is on the two `Ready` edges
            // out of `Indexing` and nowhere else, which is exactly why threading the flag through
            // every call site is cheap.
            $this->move($source, SourceState::Queued, verified: false);
            $source->save();

            // INSIDE the transaction, after the INSERTs so every row has its ULID, before the
            // COMMIT so an ON_FAILURE_ABORT audit failure rethrows and takes the graph with it. The
            // items go with it because `source.upload.accepted` is one row per file and names
            // `source_item_id`.
            $audit($source, $rows);

            return $source;
        });
    }

    /**
     * @param  Closure(KnowledgeSource): void  $audit
     */
    public function update(
        string $organizationId,
        string $sourceId,
        SourceEdit $edit,
        Closure $audit,
    ): ?KnowledgeSource {
        return DB::transaction(function () use ($organizationId, $sourceId, $edit, $audit): ?KnowledgeSource {
            $source = $this->lock($organizationId, $sourceId);

            if ($source === null) {
                // The route binding already 404'd a foreign id long before this line; reaching here
                // means the row was deleted between the binding and this transaction. Null rather
                // than an exception, so the caller renders the same 404 the binding would have.
                return null;
            }

            foreach ($edit->columns() as $column => $value) {
                // `setAttribute` and not `fill()`: every column here has already passed
                // SourceEdit's allow-list, and `fill()` would additionally consult `$fillable` —
                // two allow-lists deciding one write, where a column present in one and absent from
                // the other is dropped silently rather than refused.
                $source->setAttribute($column, $value);
            }

            $source->save();

            // GATED ON THE ROW HAVING ACTUALLY MOVED. A console that re-submits its whole form on
            // every save is a supported shape; what it must not produce is a `source.updated` row
            // asserting an edit that did not happen. `wasChanged()` and not `isDirty()`, because
            // `save()` reaches `performUpdate()` only when the model is dirty, so an unchanged row
            // issues no UPDATE at all and leaves `$changes` empty.
            if ($source->wasChanged()) {
                $audit($source);
            }

            return $source;
        });
    }

    /**
     * @param  Closure(KnowledgeSource, SourceState): void  $audit
     */
    public function transition(
        string $organizationId,
        string $sourceId,
        SourceState $target,
        bool $verified,
        Closure $audit,
    ): ?KnowledgeSource {
        return DB::transaction(function () use (
            $organizationId, $sourceId, $target, $verified, $audit,
        ): ?KnowledgeSource {
            $source = $this->lock($organizationId, $sourceId);

            if ($source === null) {
                return null;
            }

            // READ UNDER THE LOCK, BEFORE THE WRITE. This is the value both `source.disabled` and
            // `source.enabled` echo as `previous_status`, and reading it anywhere else would let
            // two concurrent moves produce a row naming a status this source never held.
            $previous = $source->status;

            $this->move($source, $target, $verified);
            $source->save();

            $audit($source, $previous);

            return $source;
        });
    }

    /**
     * @param  Closure(KnowledgeSource, int): void  $audit
     */
    public function requeue(
        string $organizationId,
        string $sourceId,
        string $jobId,
        Closure $audit,
    ): ?KnowledgeSource {
        return DB::transaction(function () use (
            $organizationId, $sourceId, $jobId, $audit,
        ): ?KnowledgeSource {
            $source = $this->lock($organizationId, $sourceId);

            if ($source === null) {
                return null;
            }

            $this->move($source, SourceState::Queued, verified: false);
            $source->save();

            // EVERY ITEM IS CLAIMED FOR THE NEW JOB, AND THE SEQUENCE IS RESET WITH IT. Both
            // predicates on the UPDATE: the organization term is redundant against the composite
            // foreign key and is written out anyway, because this layer's property is that it is
            // correct on its own rather than correct because of a constraint in another file.
            $claimed = SourceItem::query()
                ->where('organization_id', '=', $organizationId)
                ->where('source_id', '=', $sourceId)
                ->update(['current_job_id' => $jobId, 'progress_sequence' => 0]);

            $audit($source, $claimed);

            return $source;
        });
    }

    /**
     * @param  Closure(KnowledgeSource): void  $audit
     */
    public function softDelete(string $organizationId, string $sourceId, Closure $audit): ?KnowledgeSource
    {
        return DB::transaction(function () use ($organizationId, $sourceId, $audit): ?KnowledgeSource {
            $source = $this->lock($organizationId, $sourceId);

            if ($source === null) {
                return null;
            }

            // THE STATUS MOVES FIRST AND THE TIMESTAMP FOLLOWS IT, in one statement either way.
            // `Deleting` is what the retrieval status filter excludes; `deleted_at` is when it
            // stopped answering. `purged_at` stays NULL — `knowledge_sources_purge_follows_delete`
            // refuses a proof without a delete, and this method has proven nothing.
            $this->move($source, SourceState::Deleting, verified: false);
            $source->deleted_at = now()->toImmutable();
            $source->save();

            // BEFORE the purge and inside the transaction: the child counts this closure reads are
            // the rows that are about to be removed by phase 2, and a read taken afterwards would
            // record two zeroes onto exactly the row that needs them most.
            $audit($source);

            return $source;
        });
    }

    public function contentSummary(string $organizationId, string $sourceId): SourceContentSummary
    {
        // THE ITEMS OF THIS SOURCE, SCOPED. Cloned per use rather than re-declared, so no branch
        // below can be built without both predicates.
        $items = SourceItem::query()
            ->where('organization_id', '=', $organizationId)
            ->where('source_id', '=', $sourceId);

        $itemCount = (clone $items)->count();
        $activeVersionCount = (clone $items)->whereNotNull('current_version_id')->count();

        $elements = $this->elementAggregates($organizationId, $sourceId);
        $warnings = $this->warningCodes($organizationId, $sourceId);
        $preview = $this->contentPreview($organizationId, $sourceId);

        return new SourceContentSummary(
            itemCount: $itemCount,
            activeVersionCount: $activeVersionCount,
            // ONLY FOR A SINGLE-ITEM SOURCE. See ActiveSourceVersion: there is no source-level
            // pointer, and naming one for a crawl would be an approximation dressed as a value.
            activeVersion: $itemCount === 1
                ? $this->singleActiveVersion($organizationId, $sourceId)
                : null,
            pageCount: $elements['pages'],
            slideCount: $elements['slides'],
            sheetCount: $elements['sheets'],
            elementCount: $elements['elements'],
            chunkCount: Chunk::query()
                ->where('organization_id', '=', $organizationId)
                // `chunks.source_id` is DENORMALIZED down the chain and the composite foreign key
                // `chunks_source_same_org` is what makes the copy checkable, so stating it is a
                // predicate rather than a shortcut — and it is what lets this count use
                // `chunks_org_source` instead of walking the version set.
                ->where('source_id', '=', $sourceId)
                ->whereIn('source_version_id', $this->activeVersionIds($organizationId, $sourceId))
                ->count(),
            warnings: $warnings['codes'],
            warningsTruncated: $warnings['truncated'],
            preview: $preview['text'],
            previewTruncated: $preview['truncated'] || $preview['elements'] < $elements['elements'],
        );
    }

    /**
     * The live version ids of one source, as a SUB-QUERY rather than a list.
     *
     * A crawl gives one source hundreds of items, so materialising the pointers into an `IN (…)`
     * list would put hundreds of bound parameters into each of four statements. Read through
     * `source_items.current_version_id` — THE POINTER IS THE DEFINITION OF LIVE — and never through
     * `activated_at IS NOT NULL AND retired_at IS NULL`, which is an inference from a partial unique
     * index and the reading that would survive somebody dropping it.
     *
     * Both tenant predicates are on the sub-query itself, so a caller cannot compose it into a
     * statement that has lost them.
     *
     * @return Builder<SourceItem>
     */
    private function activeVersionIds(string $organizationId, string $sourceId): Builder
    {
        return SourceItem::query()
            ->where('organization_id', '=', $organizationId)
            ->where('source_id', '=', $sourceId)
            ->whereNotNull('current_version_id')
            ->select('current_version_id');
    }

    /**
     * The live version of a source that has exactly ONE item, or null when that item has no pointer.
     *
     * The caller has already established `itemCount === 1`, so `first()` here is not an arbitrary
     * pick out of a set — there is at most one row for it to return.
     */
    private function singleActiveVersion(string $organizationId, string $sourceId): ?ActiveSourceVersion
    {
        $version = SourceVersion::query()
            ->where('organization_id', '=', $organizationId)
            ->whereIn('id', $this->activeVersionIds($organizationId, $sourceId))
            ->first();

        if ($version === null) {
            return null;
        }

        return new ActiveSourceVersion(
            id: $version->id,
            sourceItemId: $version->source_item_id,
            versionNumber: $version->version_number,
            status: $version->status,
            activatedAt: $version->activated_at,
            parserCfgVersion: $version->parser_cfg_version,
            ocrCfgVersion: $version->ocr_cfg_version,
            chunkerCfgVersion: $version->chunker_cfg_version,
            embeddingModelVersion: $version->embedding_model_version,
        );
    }

    /**
     * Pages, slides, sheets and elements across every LIVE version of one source.
     *
     * ── THE DISTINCT COUNTS ARE PER VERSION AND THEN SUMMED, WHICH IS NOT THE SAME AS A GLOBAL
     *    DISTINCT ──────────────────────────────────────────────────────────────────────────────
     *
     * `count(distinct page)` over the whole set would collapse page 1 of the first file into page 1
     * of the second, because a locator is only unique WITHIN a version. Grouping by
     * `source_version_id` first and summing after is what makes "a two-file upload of ten pages
     * each" report twenty rather than ten. For the ordinary single-item source the two are
     * identical, which is why the wrong one is easy to ship.
     *
     * NULLS ARE IGNORED BY `count(distinct …)` BY DEFINITION, and that is the behaviour this wants:
     * a PDF has pages and no slides, a deck has slides and no pages, a crawled page has neither. The
     * columns are nullable for exactly that reason and a zero here means "this format does not have
     * that locator" as much as it means "there are none".
     *
     * @return array{pages: int, slides: int, sheets: int, elements: int}
     */
    private function elementAggregates(string $organizationId, string $sourceId): array
    {
        $rows = DocumentElement::query()
            ->where('organization_id', '=', $organizationId)
            ->whereIn('source_version_id', $this->activeVersionIds($organizationId, $sourceId))
            ->groupBy('source_version_id')
            // A FIXED STRING WITH NO INTERPOLATION AND NO BINDINGS. Every identifier here is a
            // column of the table this query is already scoped to; nothing a caller supplied
            // reaches it.
            ->selectRaw(
                'source_version_id, count(*) as element_total, count(distinct page) as page_total, '
                .'count(distinct slide) as slide_total, count(distinct sheet) as sheet_total',
            )
            ->get();

        $totals = ['pages' => 0, 'slides' => 0, 'sheets' => 0, 'elements' => 0];

        foreach ($rows as $row) {
            $totals['pages'] += $this->intAttribute($row, 'page_total');
            $totals['slides'] += $this->intAttribute($row, 'slide_total');
            $totals['sheets'] += $this->intAttribute($row, 'sheet_total');
            $totals['elements'] += $this->intAttribute($row, 'element_total');
        }

        return $totals;
    }

    /**
     * The advisory warning CODES across a source's live versions, with a version count each.
     *
     * ── THE KEYS ARE AGGREGATED IN SQL, AND THE VALUES ARE NEVER READ ────────────────────────
     *
     * `jsonb_object_keys()` is a set-returning function, and a function call in `FROM` is
     * IMPLICITLY LATERAL in PostgreSQL — so it may reference `source_versions.warning_summary` from
     * the row being joined without the word `LATERAL` appearing. That is what lets the whole
     * projection be one grouped statement instead of hundreds of jsonb blobs crossing the wire for
     * a crawl, and it is why the ordering and the cap can be the database's rather than PHP's.
     *
     * THE VALUES BEHIND THE KEYS ARE NOT SELECTED, ANYWHERE. `SourceWarningCount`'s docblock
     * carries the reasoning: the key set is the data plane's, the value shapes are unenumerated on
     * this side, and a warning about an unplaced table naturally contains the table.
     *
     * ORDERED BY COUNT DESCENDING AND THEN BY CODE, so two reads of an unchanged source are
     * byte-identical — a list whose order the planner chooses makes a contract test a coin flip.
     *
     * @return array{codes: list<SourceWarningCount>, truncated: bool} `truncated` says more codes
     *                                                                 exist than the cap
     *                                                                 publishes
     */
    private function warningCodes(string $organizationId, string $sourceId): array
    {
        $rows = SourceVersion::query()
            ->where('organization_id', '=', $organizationId)
            ->whereIn('id', $this->activeVersionIds($organizationId, $sourceId))
            // ── THE ONE RAW EXPRESSION IN THIS FILE, AND IT CARRIES NO PREDICATE ────────────
            //
            // A FIXED STRING WITH NO INTERPOLATION AND NO BINDINGS: every identifier in it is a
            // column of the table this query is already scoped to, and nothing a caller supplied
            // reaches it. It is a JOIN TARGET rather than a query — the organization predicate and
            // the live-version sub-query are both on the Eloquent builder above it, and the model's
            // `#[ScopedBy(OrganizationScope::class)]` still applies — so it is NOT `tenancy-exempt`
            // and must not be marked as such: nothing about it needs an exemption, and a marker
            // claiming one would tell a reviewer the opposite of what is true. It is also not
            // `DB::table(`, `DB::select(`, `DB::statement(` or `withoutGlobalScopes(`, which are
            // the four shapes `scripts/security/rules/kb-php-tenancy.yaml` names.
            ->crossJoin(DB::raw('jsonb_object_keys(source_versions.warning_summary) as warning_code'))
            ->groupBy('warning_code')
            ->selectRaw('warning_code, count(*) as version_total')
            ->orderByDesc('version_total')
            ->orderBy('warning_code')
            // ONE MORE THAN THE CAP, so "there are more" is something this query ANSWERS rather
            // than something a second count has to be run for and could disagree with.
            ->limit(self::MAX_WARNING_CODES + 1)
            ->get();

        $codes = [];

        foreach ($rows->take(self::MAX_WARNING_CODES) as $row) {
            $code = $row->getAttribute('warning_code');

            $codes[] = new SourceWarningCount(
                code: mb_substr(is_string($code) ? $code : '', 0, self::MAX_WARNING_CODE_LENGTH),
                versions: $this->intAttribute($row, 'version_total'),
            );
        }

        return ['codes' => $codes, 'truncated' => $rows->count() > self::MAX_WARNING_CODES];
    }

    /**
     * A bounded excerpt of the extracted text of a source's live versions.
     *
     * ── IT IS UNTRUSTED DATA AND IT IS BOUNDED TWICE, IN SQL ─────────────────────────────────
     *
     * `document_elements.text` is content a tenant uploaded, extracted by a parser, rendered on a
     * page an administrator reads (non-negotiable 7). One serialized table can be megabytes, so the
     * row cap alone is not a bound — `substr()` in the SELECT is what stops the column crossing the
     * wire whole. The column is table-qualified in that expression on purpose: `text` unqualified
     * is also a type name, and qualifying it removes the question entirely.
     *
     * NOTHING HERE ESCAPES THE RESULT. Escaping belongs to the renderer, because only the renderer
     * knows the context the string is entering.
     *
     * ── THE ORDER IS DOCUMENT ORDER WITHIN A VERSION, AND VERSION ORDER ACROSS THEM ──────────
     *
     * `source_version_id` then `seq`, which is exactly `document_elements_org_version_seq`'s
     * trailing pair, so the read walks an index rather than sorting. Version ids are ULIDs under
     * `COLLATE "C"`, so their order is creation order — for a multi-item source the preview is
     * therefore the beginning of the OLDEST live version rather than an arbitrary one.
     *
     * @return array{text: string|null, truncated: bool, elements: int}
     */
    private function contentPreview(string $organizationId, string $sourceId): array
    {
        $rows = DocumentElement::query()
            ->where('organization_id', '=', $organizationId)
            ->whereIn('source_version_id', $this->activeVersionIds($organizationId, $sourceId))
            ->orderBy('source_version_id')
            ->orderBy('seq')
            ->limit(self::PREVIEW_MAX_ELEMENTS)
            // ONE MORE CHARACTER THAN THE CAP, so a value that exactly fills it is distinguishable
            // from one that was cut.
            ->selectRaw(
                'substr(document_elements.text, 1, '.(self::PREVIEW_MAX_CHARACTERS + 1).') as excerpt',
            )
            ->get();

        if ($rows->isEmpty()) {
            // NULL AND NOT AN EMPTY STRING. "Nothing has been extracted yet" and "the first element
            // is blank" are different facts, and two spellings of the first is a branch every
            // renderer has to have and one of them forgets.
            return ['text' => null, 'truncated' => false, 'elements' => 0];
        }

        $joined = implode(self::PREVIEW_SEPARATOR, array_map(
            static function (DocumentElement $row): string {
                $excerpt = $row->getAttribute('excerpt');

                return is_string($excerpt) ? $excerpt : '';
            },
            $rows->all(),
        ));

        $truncated = mb_strlen($joined) > self::PREVIEW_MAX_CHARACTERS;

        return [
            'text' => $truncated ? mb_substr($joined, 0, self::PREVIEW_MAX_CHARACTERS) : $joined,
            'truncated' => $truncated,
            'elements' => $rows->count(),
        ];
    }

    /**
     * One aggregate column off a row that was selected with `selectRaw`.
     *
     * PostgreSQL returns `count(*)` as `bigint`, which PDO hands back as a STRING on this driver, so
     * a bare `(int)` cast on a `mixed` is what PHPStan objects to and `is_numeric` is what makes the
     * conversion honest. A non-numeric value here would mean the aliases below and the select list
     * above disagree, which is a code error rather than a data one — zero is the safe reading and
     * the count that is visibly wrong is better than a fatal on a read-only projection.
     */
    private function intAttribute(Model $row, string $key): int
    {
        $value = $row->getAttribute($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function childSummary(string $organizationId, string $sourceId): SourceChildSummary
    {
        $items = SourceItem::query()
            ->where('organization_id', '=', $organizationId)
            ->where('source_id', '=', $sourceId);

        return new SourceChildSummary(
            itemCount: (clone $items)->count(),
            // Both predicates, and the version count goes through the item ids of THIS
            // organization's rows rather than through a bare `whereIn` over a subquery nobody
            // scoped. `source_versions` carries its own `organization_id`, so the tenant term is
            // stated directly rather than inherited from the join.
            versionCount: SourceVersion::query()
                ->where('organization_id', '=', $organizationId)
                ->whereIn('source_item_id', (clone $items)->select('id'))
                ->count(),
        );
    }

    public function hasWarnedActiveVersion(string $organizationId, string $sourceId): bool
    {
        return SourceVersion::query()
            ->where('organization_id', '=', $organizationId)
            ->where('status', '=', SourceState::ReadyWithWarnings->value)
            // THE POINTER IS THE PREDICATE. `whereIn` over the `current_version_id` column of this
            // source's items, both terms scoped: a version is live because an item names it, not
            // because its own timestamps happen to satisfy the index predicate.
            ->whereIn('id', SourceItem::query()
                ->where('organization_id', '=', $organizationId)
                ->where('source_id', '=', $sourceId)
                ->whereNotNull('current_version_id')
                ->select('current_version_id'))
            ->exists();
    }

    /**
     * @return list<SourceItem>
     */
    public function itemsFor(string $organizationId, string $sourceId): array
    {
        return array_values(SourceItem::query()
            ->where('organization_id', '=', $organizationId)
            ->where('source_id', '=', $sourceId)
            // Deterministic, and it is creation order: `id` is a ULID under COLLATE "C", so
            // lexicographic order IS byte order IS creation order.
            ->orderBy('id')
            ->get()
            ->all());
    }

    /**
     * @param  Closure(IngestionApplication): void  $audit
     */
    public function applyIngestionProgress(
        string $organizationId,
        IngestionProgress $frame,
        Closure $audit,
    ): IngestionApplication {
        return DB::transaction(function () use ($organizationId, $frame, $audit): IngestionApplication {
            // ── THE GUARD, IN ORDER, ALL OF IT UNDER ONE LOCK ────────────────────────────────
            $item = SourceItem::query()
                ->where('organization_id', '=', $organizationId)
                ->whereKey($frame->sourceItemId)
                ->lockForUpdate()
                ->first();

            if ($item === null) {
                // NOT AN ERROR RESPONSE. The data plane is describing a row this organization does
                // not have — which is what a callback aimed at the wrong tenant looks like, and
                // what a callback arriving after a purge also looks like. Refused and named.
                return IngestionApplication::refused(IngestionApplication::UNKNOWN_ITEM);
            }

            if ($item->source_id !== $frame->sourceId) {
                return IngestionApplication::refused(IngestionApplication::ITEM_SOURCE_MISMATCH);
            }

            if ($item->current_job_id !== $frame->jobId) {
                // A SUPERSEDED RUN. A reprocess dispatched while an earlier run was in flight
                // re-stamped this column, and the older run's frames must be ignored OUTRIGHT
                // rather than compared — its sequence numbers restart at 1 and would otherwise
                // read as the future.
                return IngestionApplication::refused(IngestionApplication::STALE_JOB);
            }

            if ($frame->sequence <= $item->progress_sequence) {
                // `WHERE sequence > progress_sequence`. This is the line that stops a Celery retry
                // re-emitting stage 6 after stage 9 from flipping a `ready` source back to
                // `processing` and taking an already-published version out of retrieval.
                return IngestionApplication::refused(IngestionApplication::OUT_OF_ORDER);
            }

            $source = $this->lock($organizationId, $frame->sourceId);

            if ($source === null) {
                return IngestionApplication::refused(IngestionApplication::ITEM_SOURCE_MISMATCH);
            }

            $identity = $frame->identity;

            // NO IDENTITY ON THIS FRAME MEANS THIS FRAME IS ABOUT NO VERSION AT ALL, and falling
            // back to whatever is currently LIVE is the bug that reading looks like a convenience:
            // a `fetching` frame arrives before the bytes have been hashed, so on the SECOND run
            // over an item the fallback would hand back the PUBLISHED version and try to walk it
            // backwards from `ready` to `fetching` — refusing a perfectly ordinary re-ingestion,
            // and, had the table allowed it, dragging a live version out of retrieval. The rollup
            // onto the SOURCE still happens; there is simply no version row for this frame to
            // describe, which is the honest reading of a run that has not identified itself yet.
            $version = $identity === null
                ? null
                : $this->resolveVersion($organizationId, $item, $frame, $identity);

            if ($version !== null) {
                // THE DELIVERY COUNTER, APPLIED AS A CEILING RATHER THAN AN INCREMENT. The frame
                // carries the worker's own absolute count, so a replayed frame that got past the
                // sequence guard once cannot double-count — and a frame that somehow reports a
                // LOWER value cannot rewind a counter whose whole purpose is to bound redelivery.
                // No CHECK at MAX_DELIVERIES exists and none may be added: the worker reads a value
                // ABOVE the cap to decide to give up, so a constraint at 3 would make the bump
                // raise inside the task that is trying to leave the retry loop.
                if ($frame->deliveryCount !== null && $frame->deliveryCount > $version->delivery_count) {
                    $version->delivery_count = $frame->deliveryCount;
                }

                if ($frame->warningSummary !== []) {
                    $version->warning_summary = $frame->warningSummary;
                }

                // A ROW THAT WAS JUST CREATED IS NOT TRANSITIONING — it is arriving. `resolveVersion()`
                // has already set its status to the frame's own, with the one refusal that matters
                // (a birth straight into a Ready flavour) applied there. Running `moveVersion()`
                // over it as well would ask the table for an edge out of a state the row was never
                // in, and the answer would be no for every legitimate mid-run identity frame — a
                // crawl resolves its content hash only after fetching, so its version's first
                // status is `parsing`, which `queued` cannot reach.
                if (! $version->wasRecentlyCreated && $version->status !== $frame->status) {
                    $this->moveVersion($version, $frame->status, $frame->verified);
                }
            }

            $activated = false;
            $retired = null;

            if ($version !== null && $frame->status->isRetrievable() && $frame->verified) {
                // ── THE ACTIVATION SEQUENCE (kb-source-lifecycle steps 4-6) ──────────────────
                //
                // Verify before ready, ready before switch, switch before retire. The verification
                // is the data plane's — only the worker that wrote the points can count them with
                // `exact=True` — and it arrives as `$frame->verified`, which `canTransitionTo()`
                // has already refused to publish without. The pointer flip is Laravel's, because
                // Laravel is where the audit row, the policy check and the retention clock are, and
                // because two writers on `current_version_id` turn a lifecycle bug into an
                // IntegrityError inside a Celery task that retries forever.
                $priorId = $item->current_version_id;

                if ($priorId !== null && $priorId !== $version->id) {
                    $retired = SourceVersion::query()
                        ->where('organization_id', '=', $organizationId)
                        ->whereKey($priorId)
                        ->lockForUpdate()
                        ->first();

                    if ($retired !== null) {
                        $retired->retired_at = now()->toImmutable();
                        $retired->save();
                    }
                }

                if ($version->activated_at === null) {
                    $version->activated_at = now()->toImmutable();
                }

                $item->current_version_id = $version->id;
                $activated = true;
            }

            $version?->save();

            if ($source->status !== $frame->status) {
                // THE ROLLUP. Ruling R3: `knowledge_sources.status` and `source_versions.status`
                // share one fifteen-value vocabulary precisely so the version's processing state
                // can be displayed ON the source. It is a real transition and it goes through the
                // same table — a source in `deleting` cannot be dragged back to `parsing` by a
                // frame from a run that was already superseded.
                $this->move($source, $frame->status, $frame->verified);
                $source->save();
            }

            $item->progress_sequence = $frame->sequence;
            $item->save();

            $application = new IngestionApplication(
                applied: true,
                reason: IngestionApplication::APPLIED,
                source: $source,
                version: $version,
                retired: $retired,
                activated: $activated,
            );

            $audit($application);

            return $application;
        });
    }

    /**
     * The version this frame is about — found by `(source_item_id, ingest_key)` or created.
     *
     * ── FIND-OR-CREATE IS THE DEDUP, AND THE INDEX IS THE AUTHORITY ───────────────────────────
     *
     * `source_versions_item_ingest_key` is UNIQUE on exactly that pair, which is what makes a
     * re-request for content and configuration that have not changed resolve to the EXISTING
     * version instead of minting a second one. The lookup here is the readable path; the index is
     * what holds under a redelivered frame racing itself, and the losing INSERT raises 23505 inside
     * a transaction that rolls back whole.
     */
    private function resolveVersion(
        string $organizationId,
        SourceItem $item,
        IngestionProgress $frame,
        VersionIdentity $identity,
    ): SourceVersion {
        $existing = SourceVersion::query()
            ->where('organization_id', '=', $organizationId)
            ->where('source_item_id', '=', $item->id)
            ->where('ingest_key', '=', $identity->ingestKey)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $version = new SourceVersion;
        $version->organization_id = $organizationId;
        $version->source_item_id = $item->id;
        // Monotonic within the item, read under the item's lock so two concurrent runs cannot both
        // claim a number. `source_versions_item_version_number` is the authority; this is the value
        // that satisfies it.
        $version->version_number = 1 + (int) SourceVersion::query()
            ->where('organization_id', '=', $organizationId)
            ->where('source_item_id', '=', $item->id)
            ->max('version_number');
        $version->content_hash = $identity->contentHash;
        $version->ingest_key = $identity->ingestKey;
        $version->parser_cfg_version = $identity->parserCfgVersion;
        $version->ocr_cfg_version = $identity->ocrCfgVersion;
        $version->chunker_cfg_version = $identity->chunkerCfgVersion;
        $version->embedding_model_version = $identity->embeddingModelVersion;
        // A NEW VERSION IS BORN `Queued`, never at the frame's own status. The frame is then a
        // TRANSITION out of it, checked against the table like every other — so a first frame
        // claiming `ready` is refused rather than publishing a version that never indexed.
        // BORN AT THE STATUS THE FRAME REPORTS, because there is no prior state to transition
        // from — the row did not exist a statement ago. The one exception is the one that matters:
        // a version may NEVER be born into a Ready flavour. Publication is the act of superseding
        // something, and nothing was verified against a row that did not exist; allowing it would
        // let a single fabricated frame put an unindexed version in front of every query.
        // `resolveVersion()` is reached only for a frame carrying a full identity, so the refusal
        // is expressed against `Draft` — the state a source has before anything was submitted.
        if ($frame->status->isRetrievable()) {
            throw new IllegalSourceTransition(SourceState::Draft, $frame->status, $frame->verified);
        }

        $version->status = $frame->status;
        $version->warning_summary = [];
        $version->delivery_count = 0;
        $version->activated_at = null;
        $version->retired_at = null;
        $version->save();

        return $version;
    }

    /**
     * The ONE place a source's status changes, and it asks the table first.
     *
     * @throws IllegalSourceTransition
     */
    private function move(KnowledgeSource $source, SourceState $target, bool $verified): void
    {
        // NO SAME-STATE EARLY RETURN, AND ITS ABSENCE IS LOAD-BEARING. `X -> X` is not an edge of
        // the transition table for any X, so treating it as a no-op here would make three real
        // refusals silently succeed: a SECOND DELETE would re-stamp `deleted_at` and write a second
        // `source.deleted` row for one removal; a REPROCESS of a source already `queued` would
        // re-claim every item for a job whose predecessor is still in flight; and an enable of an
        // already-enabled source would write an audit row describing a change that did not happen.
        // Callers that legitimately need "set it if it differs" — the status rollup on the
        // ingestion callback — ask that question themselves, where the answer is visible.
        if (! $source->status->canTransitionTo($target, $verified)) {
            throw new IllegalSourceTransition($source->status, $target, $verified);
        }

        $source->status = $target;
    }

    /**
     * @throws IllegalSourceTransition
     */
    private function moveVersion(SourceVersion $version, SourceState $target, bool $verified): void
    {
        // Same rule as `move()` above, and the caller already asked the equality question.
        if (! $version->status->canTransitionTo($target, $verified)) {
            throw new IllegalSourceTransition($version->status, $target, $verified);
        }

        $version->status = $target;
    }

    /**
     * One source of ONE organization, locked FOR UPDATE.
     *
     * The lock is what makes "read the row, decide, write it" a decision rather than a guess, and
     * on this table it is also what makes `previous_status` true on both audit rows that carry it.
     * The ownership predicate is on the SELECT rather than only on the model's global scope,
     * because the lock has to be taken on a row this organization actually owns: a lock acquired
     * under a stale ambient context would be a lock on somebody else's row — and this surface has a
     * queue worker, which is exactly where a stale context comes from.
     */
    private function lock(string $organizationId, string $sourceId): ?KnowledgeSource
    {
        return $this->scoped($organizationId)
            ->whereKey($sourceId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * The organization predicate, written out once so no query in this class can be built without
     * it. `#[ScopedBy(OrganizationScope::class)]` adds the same term from the ambient
     * `TenantContext` and is the backstop; this is the mechanism.
     *
     * @return Builder<KnowledgeSource>
     */
    private function scoped(string $organizationId): Builder
    {
        return KnowledgeSource::query()->where('organization_id', '=', $organizationId);
    }

    /**
     * Neutralise the `LIKE` metacharacters in a free-text search term.
     *
     * The term is deliberately not pattern-constrained — it is a string a human types — and what
     * makes it safe is that it never reaches SQL as SQL. This covers the remaining, non-security
     * half: a search for `100%` or `a_b` would otherwise be read as a WILDCARD.
     *
     * The backslash is escaped FIRST, or the escapes added afterwards would themselves be escaped.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
