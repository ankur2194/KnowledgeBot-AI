<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\KnowledgeSource;
use App\Services\Sources\SourceContentSummary;
use App\Services\Sources\SourceWarningCount;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One knowledge source with what is actually INSIDE it — the shape `GET .../sources/{source}`
 * returns.
 *
 * ── WHY THERE ARE TWO COMPONENTS AND NOT ONE WIDER ONE ───────────────────────────────────────
 *
 * `SourceResource` is what a LIST row is, and its fields are all columns of one table. Everything
 * added here costs four extra statements against `source_items`, `source_versions`,
 * `document_elements` and `chunks` — per row. Folding them into the list shape would turn a page of
 * twenty-five sources into a hundred and one queries, so the detail projection is its own component
 * and the list is untouched.
 *
 * THE LIST FIELDS ARE DERIVED FROM `SourceResource` RATHER THAN RESTATED, in `toArray()` and in
 * `openApiSchemas()` alike. A field added to the list therefore reaches the detail with no second
 * edit, and the two can never disagree about a description — which is the drift a hand-copied
 * property map produces about six months in.
 *
 * ── THE ONE PLACE THIS PROJECTION AND `SourceResource`'s DOCBLOCK RUB ────────────────────────
 *
 * `SourceResource` says, of itself: *"there is no active-version pointer on this shape and there
 * never will be one: a crawl gives one source hundreds of independently-versioned items, so 'the
 * current version of this source' is a set and a join rather than a value."* That is true and it is
 * not being reversed. `active_version` here is populated ONLY for a source with exactly one item,
 * where the set has one member; for every other source it is null and `active_version_count` is the
 * answer. The sentence that would be false is "this source's current version"; the sentence this
 * publishes is "the single item of this single-item source points at this version".
 *
 * The alternative — publishing an array of per-item pointers — was not built, and the reason is
 * that it is a paginated collection wearing a field's clothes: a four-hundred-page crawl would put
 * four hundred version objects inside one source object, and the console that needed them would
 * need them a page at a time. When a per-item view exists it gets its own endpoint.
 *
 * ── THE PREVIEW IS UNTRUSTED DATA ────────────────────────────────────────────────────────────
 *
 * `content_preview` is text a tenant uploaded, extracted by a parser, rendered on a page an
 * administrator reads (non-negotiable 7). It is BOUNDED in the repository — a cap on elements read
 * and a `substr()` per element, both in SQL — and it is NOT escaped here, because escaping belongs
 * to the renderer: only the renderer knows the context the string is entering. `content_preview` is
 * never markdown, never HTML, and never an instruction; a client that interpolates it into a prompt
 * has recreated the injection surface the whole prompt-assembly defence exists for.
 *
 * ── NOTHING HERE IS A SECRET AND NOTHING HERE IS CREDENTIAL-SHAPED ───────────────────────────
 *
 * A source carries no credential. The only field on this shape that names a provider at all is
 * `active_version.embedding_model_version`, which is a provider name, a model id, a vector width
 * and a digest over a fixed public probe set.
 */
final class SourceDetailResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(
        KnowledgeSource $resource,
        private readonly SourceContentSummary $content,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $version = $this->content->activeVersion;

        return (new SourceResource($this->resource))->toArray($request) + [
            'item_count' => $this->content->itemCount,
            'active_version_count' => $this->content->activeVersionCount,
            'active_version' => $version === null
                ? null
                : (new SourceActiveVersionResource($version))->toArray($request),
            'page_count' => $this->content->pageCount,
            'slide_count' => $this->content->slideCount,
            'sheet_count' => $this->content->sheetCount,
            'element_count' => $this->content->elementCount,
            'chunk_count' => $this->content->chunkCount,
            'warnings' => array_map(
                fn (SourceWarningCount $warning): array => (new SourceWarningResource($warning))->toArray($request),
                $this->content->warnings,
            ),
            'warnings_truncated' => $this->content->warningsTruncated,
            'content_preview' => $this->content->preview,
            'content_preview_truncated' => $this->content->previewTruncated,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        $base = SourceResource::openApiSchemas();

        /** @var array<string, mixed> $source */
        $source = $base['SourceResource'];

        /** @var array<string, array<string, mixed>> $properties */
        $properties = is_array($source['properties'] ?? null) ? $source['properties'] : [];

        /** @var list<string> $required */
        $required = is_array($source['required'] ?? null) ? array_values($source['required']) : [];

        $added = [
            'item_count' => [
                'type' => 'integer',
                'minimum' => 0,
                'description' => 'Independently-versioned items under this source: one per uploaded '
                    .'file, one per crawled page, one for a paste. A submitted source always has at '
                    .'least one; a source still in `draft` has none, because nothing has been '
                    .'submitted into it yet.',
            ],
            'active_version_count' => [
                'type' => 'integer',
                'minimum' => 0,
                'description' => 'How many of those items currently point at a live version. ZERO '
                    .'MEANS NOTHING ABOUT THIS SOURCE IS RETRIEVABLE, whatever `status` says — the '
                    .'active-version pointer is one of the four mandatory filter terms, and an '
                    .'ingestion that has never completed leaves it empty. It is also why every '
                    .'count below can legitimately be 0 on a source that looks busy.',
            ],
            'active_version' => [
                'anyOf' => [
                    ['$ref' => '#/components/schemas/SourceActiveVersionResource'],
                    ['type' => 'null'],
                ],
                'description' => 'The live version, for a source with EXACTLY ONE ITEM. Null for '
                    .'every other source, including a multi-item upload and every crawl: activation '
                    .'is a pointer on the ITEM, there is no source-level pointer, and "the current '
                    .'version" of a four-hundred-page crawl is a set rather than a value. Null does '
                    .'NOT mean "nothing is live" — read `active_version_count` for that.',
            ],
            'page_count' => [
                'type' => 'integer',
                'minimum' => 0,
                'description' => 'Distinct pages across this source\'s live versions, summed per '
                    .'version so a two-file upload of ten pages each reports twenty. Zero for a '
                    .'format with no pages — a slide deck, a spreadsheet, a crawled page — as much '
                    .'as for a source with no live content.',
            ],
            'slide_count' => [
                'type' => 'integer',
                'minimum' => 0,
                'description' => 'Distinct slides, on the same basis as `page_count`.',
            ],
            'sheet_count' => [
                'type' => 'integer',
                'minimum' => 0,
                'description' => 'Distinct spreadsheet sheets, on the same basis as `page_count`.',
            ],
            'element_count' => [
                'type' => 'integer',
                'minimum' => 0,
                'description' => 'Structural elements — headings, paragraphs, list items, table '
                    .'rows, captions — extracted from this source\'s live versions. The unit '
                    .'citations locate against, and the honest measure of "how much document is '
                    .'here" for formats that have no pages.',
            ],
            'chunk_count' => [
                'type' => 'integer',
                'minimum' => 0,
                'description' => 'Retrievable chunks derived from this source\'s live versions — '
                    .'the number of vectors a deletion would remove, and the number a rebuild would '
                    .'re-embed at a provider\'s per-token price. This is the figure a delete '
                    .'confirmation should state.',
            ],
            'warnings' => [
                'type' => 'array',
                'items' => ['$ref' => '#/components/schemas/SourceWarningResource'],
                'description' => 'Advisory parser and OCR warnings reported while the live content '
                    .'was produced, as CODES with a version count each. Always present and empty '
                    .'when there are none. ADVISORY ALWAYS: a warning never gates retrieval, so do '
                    .'not render this as a reason the source is not answering.',
            ],
            'warnings_truncated' => [
                'type' => 'boolean',
                'description' => 'Whether more distinct warning codes exist than this list '
                    .'publishes. The key set belongs to the ingestion service and is not enumerated '
                    .'here, so the list is capped rather than unbounded.',
            ],
            'content_preview' => [
                'type' => ['string', 'null'],
                'description' => 'A BOUNDED excerpt of the extracted text of the live content, in '
                    .'document order, elements separated by a blank line. Null when nothing has '
                    .'been extracted yet — never an empty string, because "no content" and "the '
                    .'first element is blank" are different facts. IT IS UNTRUSTED TENANT DATA: '
                    .'escape it at render, in every client, and never interpolate it into a prompt '
                    .'or interpret it as markup.',
            ],
            'content_preview_truncated' => [
                'type' => 'boolean',
                'description' => 'Whether the excerpt was cut — by the character cap or by the '
                    .'element cap. True is the ordinary case for anything longer than a page; '
                    .'render an ellipsis rather than implying the document is that short.',
            ],
        ];

        return $base
            + SourceActiveVersionResource::openApiSchemas()
            + SourceWarningResource::openApiSchemas()
            + [
                'SourceDetailResource' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'description' => 'One knowledge source with what is inside it: every field of '
                        .'`SourceResource`, plus the live version, the page/slide/sheet/element/'
                        .'chunk counts, the advisory parser and OCR warnings, and a bounded preview '
                        .'of the extracted text. EVERY NUMBER IS OVER THE LIVE VERSIONS ONLY — the '
                        .'ones an item\'s active-version pointer names — so a source mid-ingestion '
                        .'reports zeroes even though rows for an unpublished version exist. That is '
                        .'the correct reading for a delete confirmation, which is asking what is '
                        .'reachable and about to stop being.',
                    'required' => [...$required, ...array_keys($added)],
                    'properties' => $properties + $added,
                ],
            ];
    }
}
