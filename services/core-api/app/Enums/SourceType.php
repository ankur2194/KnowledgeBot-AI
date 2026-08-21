<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of thing a knowledge source IS — the admin's unit of intent (docs/03 §8.9,
 * kb-source-lifecycle "Three levels of identity").
 *
 * THREE VALUES, AND THE SET IS SMALLER THAN THE LIST OF THINGS WE INGEST, deliberately. A PDF, a
 * DOCX, a spreadsheet and a slide deck are all `file`: the parser decides what to do with the bytes
 * from the sniffed MIME type, never from a column somebody typed. Putting `pdf` and `docx` in this
 * enum would create a second, weaker statement of the file type that a client could set and that
 * `kb-security-baseline`'s upload rules explicitly refuse to trust — "MIME detection from content
 * (libmagic), never from the client's Content-Type or the extension".
 *
 * `text` is pasted content with no object behind it and no URL to refetch: it still gets a
 * `source_items` row and a `source_versions` row like everything else, because the pointer switch,
 * the citation provenance and the missing-page counter all key off `source_item_id` and code that
 * special-cases a one-item source will diverge the first time somebody adds a second thing to it.
 *
 * TEXT + CHECK IN THE MIGRATION, NEVER A NATIVE PG ENUM: `ALTER TYPE ... ADD VALUE` cannot be
 * rolled back, so a fourth kind would be an irreversible migration. `knowledge_sources_type_check`
 * is generated from `values()` so the two cannot drift.
 */
enum SourceType: string
{
    /** An uploaded object. The bytes live in object storage; the MIME is sniffed, never declared. */
    case File = 'file';

    /** A URL, a sitemap or a crawl root. Every discovered page becomes its own `source_items` row. */
    case Url = 'url';

    /** Pasted prose. No object, no URL, and still a full item/version chain. */
    case Text = 'text';

    /**
     * Whether a source of this kind is refetchable from its origin.
     *
     * Positively expressed, and it is the predicate the recrawl dispatcher asks rather than
     * `!== File`: a negative test admits the kind nobody has thought of yet, which here would mean
     * the scheduler trying to fetch a paste.
     */
    public function isRemotelyFetchable(): bool
    {
        return $this === self::Url;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
