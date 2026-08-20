<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The structural role the parser labels a document element with.
 *
 * TEN VALUES, TRANSCRIBED FROM `ELEMENT_KINDS` in
 * `services/ai-service/app/ingestion/chunking/chunker.py:117-128`. That tuple is the authority and
 * this enum is a second statement of it, for the same reason `SourceState` is a second statement of
 * `states.py`: the value crosses the process boundary as a bare string written by the data plane
 * into a table whose CHECK constraint this repository owns. A divergence is not a type error
 * anywhere — it is an insert that fails inside a Celery task, at scale, mid-ingestion.
 *
 * ── THESE ARE WHAT MAKE A CHUNK BOUNDARY STRUCTURAL RATHER THAN ARITHMETIC ───────────────────
 *
 * Without them the only edge available to the chunker is a character offset, which is the failure
 * the whole chunking module is arranged against — a paragraph cut mid-sentence retrieves as two
 * passages that each answer half a question.
 *
 * ── `page_header`, `page_footer` AND `footnote` ARE KEPT, NOT DROPPED ────────────────────────
 *
 * The parser labels them precisely so the decision is REVERSIBLE, and dropping by label would
 * delete a per-page legal disclaimer from every document that carries one — the highest-cost
 * mistake in the boilerplate direction, and unfalsifiable afterwards because nothing records what
 * went missing. Frequency-based repeated-block removal is a separate module's job.
 *
 * ── `heading` CONTRIBUTES NO CHUNK OF ITS OWN ────────────────────────────────────────────────
 *
 * Its text is already in the `heading_path` of everything beneath it, which is what the embedded
 * prefix and the citation both read. Emitting it as a chunk as well would index the same words
 * twice and produce a chunk whose whole content is its own title. That rule lives in the chunker;
 * it is noted here because a reader of this enum will otherwise expect a one-to-one mapping between
 * kinds and chunk content types, and there is none.
 */
enum DocumentElementKind: string
{
    /** A section title. Never a chunk of its own — see the class docblock. */
    case Heading = 'heading';

    /** Ordinary prose. */
    case Text = 'text';

    /** The introducing stem of a list, repeated at the head of every chunk of the run. */
    case ListStem = 'list_stem';

    /** One item of a list, bound to its stem. */
    case ListItem = 'list_item';

    /** A table. Serialized row-wise with the header repeated, by the table chunker. */
    case Table = 'table';

    /** A code block, which is never re-wrapped and never split on a character count. */
    case Code = 'code';

    /** One slide of a presentation, including its speaker notes. */
    case Slide = 'slide';

    /** A running page header. KEPT, deliberately. */
    case PageHeader = 'page_header';

    /** A running page footer. KEPT, deliberately. */
    case PageFooter = 'page_footer';

    /** A footnote. KEPT, deliberately — it is where a document's exceptions live. */
    case Footnote = 'footnote';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
