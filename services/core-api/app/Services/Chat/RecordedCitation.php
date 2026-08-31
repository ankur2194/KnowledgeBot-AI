<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\Chunk;

/**
 * One `citations` row, built from a `citations` frame entry plus the `chunks` row it names.
 *
 * ═══ THE WIRE CARRIES SEVEN FIELDS AND THE ROW NEEDS NINE ══════════════════════════════════
 *
 * `Citation` on the data plane deliberately has no `excerpt` and no `locator`, and says so: *"the
 * transcript's `location_metadata` and `excerpt` are denormalized onto Laravel's `citations` row at
 * persist time from its own `chunks` table."* That is not an omission to work around — it is the
 * division of labour. The frame names WHICH chunk was cited; this plane owns `chunks` and is the
 * only place that can say what the chunk SAID.
 *
 * Both columns are NOT NULL, and `citations_excerpt_not_blank` refuses the empty spelling: a
 * citation with no excerpt is a claim with no evidence, and §12.15's whole contract is that the
 * evidence is shown.
 *
 * ═══ THE HYDRATION IS ORG-SCOPED AND THAT IS THE ONLY REASON IT IS SAFE ════════════════════
 *
 * The chunk ids arrive over the wire. A fetch keyed on chunk ids ALONE would have no tenant filter
 * of its own, and the one thing a citation must never need is a lookup that trusts an id it was
 * handed — the data plane's own `ChunkSource` protocol says exactly this about its side of the same
 * fetch. `EloquentConversationRepository` scopes the read to the organization AND to the turn's
 * resolved `allowed_version_ids`, so a chunk id from another tenant, or from a retired version,
 * resolves to nothing and the citation is DROPPED rather than persisted with a placeholder.
 *
 * ═══ THE LABEL IS THE WIRE'S INDEX, RENDERED ═══════════════════════════════════════════════
 *
 * `citations.label` is the marker in the answer text and is unique within the message. The frame's
 * `index` is 1-based in PACKED order — the order the model read the evidence in, which is not
 * reranked order — so the client's footnote marker and the identifier in the prose are the same
 * number by construction. Renumbering here would attach the model's sentence to a passage it did not
 * read.
 */
final readonly class RecordedCitation
{
    /**
     * @param  array<string, mixed>  $locationMetadata  page, slide, sheet, row range, url, anchor —
     *                                                  whichever locators the chunk had. An OBJECT
     *                                                  and never an array, including when empty:
     *                                                  `citations_location_metadata_is_object`
     *                                                  refuses the array spelling, and `json_encode([])`
     *                                                  produces exactly that.
     */
    public function __construct(
        public string $label,
        public ?string $chunkId,
        public string $displayTitle,
        public array $locationMetadata,
        public string $excerpt,
    ) {}

    /**
     * Build one from a wire entry and the chunk it names, or NULL when it cannot be built.
     *
     * @param  array<string, mixed>  $entry  one element of the `citations` frame's list
     * @param  Chunk|null  $chunk  the hydrated row, ALREADY scoped to the organization and to the
     *                             turn's `allowed_version_ids` by the repository. Null means the id
     *                             resolved to nothing under that scope, which covers a chunk from
     *                             another tenant, one from a retired version, and one that has been
     *                             deleted since the answer was generated.
     */
    public static function fromFrame(array $entry, ?Chunk $chunk): ?self
    {
        // NO CHUNK, NO CITATION. `citations.excerpt` is NOT NULL and `citations_excerpt_not_blank`
        // refuses a blank one, so there is no placeholder that satisfies the table — and inventing
        // one would be a footnote pointing at evidence this turn cannot show, which is exactly the
        // failure the whole label mechanism exists to prevent.
        if ($chunk === null) {
            return null;
        }

        $index = $entry['index'] ?? null;

        if (! is_int($index) || $index < 1) {
            return null;
        }

        $title = $entry['title'] ?? null;

        return new self(
            label: (string) $index,
            chunkId: (string) $chunk->id,
            // THE FRAME'S TITLE IS THE CHUNK'S HEADING PATH, not the source's name — the Qdrant
            // payload carries no source title and `_citation_title()` on the data plane says so at
            // length. It is used when present because it is the closest true statement the index can
            // make about where the sentence came from; the fallbacks are the chunk's own URL and
            // then a fixed string, never a ULID nobody can read.
            displayTitle: is_string($title) && trim($title) !== ''
                ? mb_substr(trim($title), 0, 500)
                : (is_string($chunk->url) && $chunk->url !== '' ? $chunk->url : 'Untitled source'),
            locationMetadata: self::locators($chunk, $entry),
            // BOUNDED. The excerpt is tenant text and the column is unbounded `text`; a chunk is a
            // few hundred tokens by construction, so this cap is a backstop rather than a trim.
            excerpt: mb_substr((string) $chunk->text, 0, 4_000),
        );
    }

    /**
     * Every locator the chunk actually has, and no keys for the ones it does not.
     *
     * THE KEY SET DIFFERS PER SOURCE TYPE — a PDF has a page, a deck has a slide, a spreadsheet has
     * a sheet and a row range, a crawled page has a URL and an anchor. Emitting a null for each
     * absent one would make `location_metadata->>'page'` return the JSON null for a slide, which a
     * renderer cannot tell from "page zero".
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private static function locators(Chunk $chunk, array $entry): array
    {
        $locators = [];

        foreach ([
            'page' => $chunk->page,
            'page_end' => $chunk->page_end,
            'slide' => $chunk->slide,
            'sheet' => $chunk->sheet,
            'table_ref' => $chunk->table_ref,
            'row_start' => $chunk->row_start,
            'row_end' => $chunk->row_end,
            'anchor' => $chunk->anchor,
        ] as $key => $value) {
            if ($value !== null && $value !== '') {
                $locators[$key] = $value;
            }
        }

        // THE URL COMES FROM THE FRAME FIRST AND THE CHUNK SECOND. The frame's is what the reader was
        // shown; the chunk's is what the index holds. They agree in every ordinary case, and when
        // they do not the transcript should say what was displayed.
        $url = $entry['url'] ?? null;
        $url = is_string($url) && $url !== '' ? $url : $chunk->url;

        if (is_string($url) && $url !== '') {
            $locators['url'] = $url;
        }

        $headings = $chunk->heading_path;

        if (is_array($headings) && $headings !== []) {
            $locators['heading_path'] = array_values($headings);
        }

        return $locators;
    }
}
