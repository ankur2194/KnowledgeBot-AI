<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Citation;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One footnote behind one answer, RESOLVED TO THE PASSAGE THE MODEL ACTUALLY READ.
 *
 * ── THE EXCERPT IS ON THE CITATION ROW, NOT FETCHED FROM `chunks` ────────────────────────────
 *
 * `citations` denormalizes `display_title`, `location_metadata` and `excerpt` at write time, and
 * `chunk_id` is `ON DELETE SET NULL`. That is the design of the table rather than a convenience:
 * resolving the excerpt through the chunk would make a transcript go blank the moment the source
 * behind it was purged, which rewrites history on the one surface whose whole job is to say what
 * was shown. It is also why `Permission::SourcesView` is refused to the Analyst — conversation
 * review reads NOTHING from `knowledge_sources`, and this resource is the reason that is true.
 *
 * ── IT IS THE SAME EVIDENCE THE VISITOR SAW, AND THE FIELD SET DIFFERS FROM THE RUNTIME'S ───
 *
 * `RuntimeCitationCollectionResource` publishes `label`, `chunk_id`, `title`, `location` and
 * `excerpt` to the visitor who owns the thread. This adds `created_at` and nothing else: an admin
 * reviewer legitimately needs to know when a footnote was written relative to the turn, and the
 * visitor does not. THE TWO ARE SEPARATE COMPONENTS RATHER THAN ONE SHARED ONE precisely so that
 * adding a field for an operator cannot widen what a widget receives — the runtime resource's
 * docblock calls itself "the one surface that deliberately returns tenant source text", and a
 * shared component would make every future field on this one a decision about that surface too.
 *
 * ── THE EXCERPT IS ATTACKER-CONTROLLED TEXT AND IS NOT SANITIZED HERE ───────────────────────
 *
 * It came out of an uploaded or crawled document, which is hostile data permanently
 * (non-negotiable 7). It is rendered through the same sanitizer as model output, in every client
 * INCLUDING THE ADMIN CONSOLE — an administrator reading a stored conversation is reading
 * attacker-controlled text in a session with real privileges, so the admin UI must not get a more
 * permissive renderer than the widget.
 */
final class TranscriptCitationResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(Citation $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $citation = $this->resource;

        return [
            'label' => $citation->label,
            'chunk_id' => $citation->chunk_id,
            'title' => $citation->display_title,
            'location' => $citation->location_metadata,
            'excerpt' => $citation->excerpt,
            'created_at' => $citation->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'TranscriptCitationResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One footnote behind one answer, as an admin reviewer sees it. '
                    .'Every field but `chunk_id` is denormalized onto the citation row at write '
                    .'time, so a transcript stays readable after the source it cited has been '
                    .'purged.',
                'required' => ['label', 'chunk_id', 'title', 'location', 'excerpt', 'created_at'],
                'properties' => [
                    'label' => [
                        'type' => 'string',
                        'description' => 'The marker in the answer text — `1`, `2`. Unique within '
                            .'the message, and 1-based in PACKED order, which is the order the '
                            .'model read the evidence in and is NOT reranked order. Assigned before '
                            .'generation from retrieved evidence, never parsed out of model output.',
                    ],
                    'chunk_id' => [
                        'type' => ['string', 'null'],
                        'description' => 'The retrievable chunk this footnote pointed at, or null '
                            .'once that chunk has been purged. The footnote survives deliberately: '
                            .'it is the record of what was shown, and losing it would rewrite '
                            .'history.',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'The chunk\'s heading path, its URL, or a fixed fallback — '
                            .'in that order, as it was when the answer was given. It is NOT the '
                            .'source\'s name: the retrieval payload carries no source title, and '
                            .'this is the closest true statement the index can make about where the '
                            .'sentence came from.',
                    ],
                    'location' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                        'description' => 'Whichever locators the chunk had — page, slide, sheet, '
                            .'row range, url, anchor, heading path. THE KEY SET DIFFERS BY SOURCE '
                            .'TYPE and an absent locator is an absent KEY, never a null: a null '
                            .'`page` on a slide is indistinguishable from page zero.',
                    ],
                    'excerpt' => [
                        'type' => 'string',
                        'description' => 'The passage the model read. UPLOADED OR CRAWLED CONTENT, '
                            .'which is hostile data permanently — render it through the same '
                            .'sanitizer as model output, on every surface including the admin '
                            .'console, and never auto-load an image it names.',
                    ],
                    'created_at' => [
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => 'When the footnote was persisted, which is when the turn '
                            .'finalized rather than when the evidence was retrieved.',
                    ],
                ],
            ],
        ];
    }
}
