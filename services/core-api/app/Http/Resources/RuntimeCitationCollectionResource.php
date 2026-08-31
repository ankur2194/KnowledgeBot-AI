<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Citation;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The evidence behind one answer — what a visitor sees when they open a footnote.
 *
 * ═══ THIS IS THE ONE SURFACE THAT DELIBERATELY RETURNS TENANT SOURCE TEXT ══════════════════
 *
 * §12.15's contract is that the evidence is SHOWN, so `excerpt` is the passage the model actually
 * read. Two properties make that safe rather than a leak, and both are upstream of this class:
 *
 *   THE ROWS WERE WRITTEN UNDER THE TURN'S OWN SCOPE. `StreamFinalizer` hydrates each citation from
 *   `chunks` filtered by organization AND by the turn's resolved `allowed_version_ids`, so a chunk
 *   from another tenant — or from a RETIRED version of this tenant's own source — never became a
 *   row. There is no path by which a citation names evidence this bot was not permitted to search.
 *
 *   THE READ IS SCOPED TO THE PARTICIPANT. `citationsFor()` joins up to `conversations` and the gate
 *   has already established that the calling session owns it.
 *
 * `chunk_id` IS PUBLISHED AND `source_id` IS NOT. The chunk id is what a client uses to ask for the
 * same evidence again and is scoped to a message the caller owns; the source id is an admin-surface
 * identifier and would let a visitor correlate answers across conversations to enumerate a tenant's
 * corpus.
 *
 * ═══ THE EXCERPT IS ATTACKER-CONTROLLED TEXT AND IS NOT SANITIZED HERE ═════════════════════
 *
 * It came out of an uploaded or crawled document, which is hostile data permanently. It is rendered
 * through the same sanitizer as model output, in every client including the admin console.
 */
final class RuntimeCitationCollectionResource extends JsonResource implements ProvidesOpenApiSchema
{
    /**
     * @param  list<Citation>  $resource
     */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'citations' => array_map(
                static fn (Citation $citation): array => [
                    'label' => $citation->label,
                    // NULLABLE, AND THE NULL IS THE POINT OF THE COLUMN'S `ON DELETE SET NULL`: the
                    // chunk can be purged after the answer was given, and the footnote must survive
                    // as a record of what was shown rather than disappearing from a transcript.
                    'chunk_id' => $citation->chunk_id,
                    'title' => $citation->display_title,
                    'location' => $citation->location_metadata,
                    'excerpt' => $citation->excerpt,
                ],
                $this->resource,
            ),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'RuntimeCitationCollectionResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'The evidence behind one answer, in label order. Every row was '
                    .'written under the turn\'s own organization and active-version scope, so a '
                    .'citation can never name evidence the bot was not permitted to search.',
                'required' => ['citations'],
                'properties' => [
                    'citations' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['label', 'chunk_id', 'title', 'location', 'excerpt'],
                            'properties' => [
                                'label' => [
                                    'type' => 'string',
                                    'description' => 'The marker in the answer text — `1`, `2`. '
                                        .'Unique within the message, and 1-based in PACKED order, '
                                        .'which is the order the model read the evidence in and is '
                                        .'NOT reranked order. Assigned before generation from '
                                        .'retrieved evidence, never parsed out of model output.',
                                ],
                                'chunk_id' => [
                                    'type' => ['string', 'null'],
                                    'description' => 'Null once the chunk has been purged. The '
                                        .'footnote survives deliberately: it is the record of what '
                                        .'was shown, and losing it would rewrite history.',
                                ],
                                'title' => [
                                    'type' => 'string',
                                    'description' => 'The chunk\'s heading path, its URL, or a '
                                        .'fixed fallback — in that order. It is NOT the source\'s '
                                        .'name: the retrieval payload carries no source title, and '
                                        .'this is the closest true statement the index can make '
                                        .'about where the sentence came from.',
                                ],
                                'location' => [
                                    'type' => 'object',
                                    'additionalProperties' => true,
                                    'description' => 'Whichever locators the chunk had — page, '
                                        .'slide, sheet, row range, url, anchor, heading path. THE '
                                        .'KEY SET DIFFERS BY SOURCE TYPE and an absent locator is '
                                        .'an absent KEY, never a null: a null `page` on a slide is '
                                        .'indistinguishable from page zero.',
                                ],
                                'excerpt' => [
                                    'type' => 'string',
                                    'description' => 'The passage the model read. UPLOADED OR '
                                        .'CRAWLED CONTENT, which is hostile data permanently — '
                                        .'render it through the same sanitizer as model output, on '
                                        .'every surface including the admin console.',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
