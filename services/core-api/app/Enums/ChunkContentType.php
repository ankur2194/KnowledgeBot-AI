<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What KIND of content a chunk holds, which is what selects its size policy.
 *
 * SEVEN VALUES, TRANSCRIBED FROM `CONTENT_TYPES` in
 * `services/ai-service/app/ingestion/chunking/chunker.py:96-104`. Second statement of a data-plane
 * tuple, for the reason `DocumentElementKind` records: the data plane writes this column and this
 * repository owns its CHECK constraint.
 *
 * ── IT IS NOT THE ELEMENT KIND, AND THE TWO MUST NOT BE MERGED ───────────────────────────────
 *
 * `DocumentElementKind` says what the PARSER found; this says what the CHUNKER made. The mapping is
 * neither one-to-one nor onto: `faq` and `policy` are recognised across ordinary `text` elements
 * and have no element kind of their own, while `heading` produces no chunk at all. Merging them
 * would force one of those two facts to be expressed somewhere else.
 *
 * ── WHY IT IS A COLUMN AND NOT A KEY IN A jsonb BAG ──────────────────────────────────────────
 *
 * `postgresql-patterns`: anything queried by a predicate becomes a real column. This one selects
 * the size policy at chunking time and is the field an evaluation run groups by when a recall
 * regression turns out to be confined to tables.
 */
enum ChunkContentType: string
{
    /** A question and its answer, kept together. Small and self-contained. */
    case Faq = 'faq';

    /** A policy clause. Sized tighter than prose, with no overlap: the clause IS the unit. */
    case Policy = 'policy';

    /** Ordinary running text. The only type that carries an overlap fraction. */
    case Prose = 'prose';

    /** Rows of a table, serialized with the header repeated on every chunk of the run. */
    case TableRows = 'table_rows';

    /** A contiguous region of a spreadsheet, named so a citation can say which. */
    case SheetRegion = 'sheet_region';

    /** One slide, with its notes. */
    case Slide = 'slide';

    /** A code block, never re-wrapped. */
    case Code = 'code';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
