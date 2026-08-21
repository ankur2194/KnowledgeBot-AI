<?php

declare(strict_types=1);

namespace App\Services\Sources;

/**
 * One advisory parser/OCR warning code, and how many of a source's live versions reported it.
 *
 * ── THE CODE IS PUBLISHED AND THE VALUE BEHIND IT IS NOT ─────────────────────────────────────
 *
 * `source_versions.warning_summary` is a jsonb OBJECT whose key set the DATA PLANE owns — it is
 * one of the three shapes `postgresql-patterns` admits jsonb for: written once, read whole, never
 * queried by predicate. Laravel has no enumeration of those keys and no schema for their values,
 * and the values can legitimately be structures containing document text (a warning about an
 * unplaced table naturally carries the table).
 *
 * So this projection publishes the KEYS and a count, and never the values. That is a bounded,
 * enumerable, renderable fact — "three of this source's live versions reported
 * `ocr_text_unplaced`" — where the alternative is an unbounded blob of tenant-derived content on an
 * admin page, which is non-negotiable 7's territory and not something a console can render safely
 * without knowing what it is looking at.
 *
 * THE COUNT IS OF VERSIONS, NOT OF OCCURRENCES. A version either carries a key or does not; how
 * many pages inside it were affected is inside the value this type deliberately does not read.
 * `versions` is therefore an upper bound on "how much of this source is affected" expressed in the
 * only unit the key set makes available.
 *
 * ── WHAT A WARNING IS NOT ────────────────────────────────────────────────────────────────────
 *
 * Advisory, always (§8.11). `ready` and `ready_with_warnings` are IDENTICAL for retrieval — a
 * warning is never a retrieval predicate — so a console must not render this list as a reason a
 * document is not answering. It is a reason a document may answer BADLY.
 */
final readonly class SourceWarningCount
{
    /**
     * The width of one warning code, in CHARACTERS — the SAME number at both ends of the column.
     *
     * ── ONE CONSTANT BECAUSE THE TWO BOUNDS MUST BE ONE NUMBER ───────────────────────────────
     *
     * `IngestionCallbackRequest` refuses a longer key at INGRESS and
     * `EloquentKnowledgeSourceRepository` `mb_substr`s at this width on the way OUT. If the two ever
     * differed, the read-side cut would fire on a key the write side had just accepted, and an
     * administrator would read a truncated code that nothing on either plane ever emitted — a code
     * indistinguishable from a real one, because the vocabulary belongs to the data plane and
     * `SourceWarningResource` correctly puts no enum on it. Two literals could drift; one cannot,
     * and that is why this lives here rather than in either of them: this type is what both layers
     * are already allowed to see, and neither of them may see the other.
     *
     * ── WHERE 128 COMES FROM ─────────────────────────────────────────────────────────────────
     *
     * Not from the key set, which is the data plane's and unbounded by construction. From the
     * READER: it is the longest code a console can be handed and still render as a label rather
     * than as a paragraph. For scale, the longest code the data plane emits today is
     * `ocr_coverage_unmeasurable`, at 25 characters — so this is five times anything that exists,
     * which is the room the "the key set is theirs and it grows" property actually needs.
     *
     * The read-side cut stays even though ingress now makes it unreachable: rows written before the
     * ingress bound existed can still be longer, and a projection that assumes otherwise is a
     * projection that breaks on old data.
     */
    public const MAX_CODE_LENGTH = 128;

    public function __construct(
        public string $code,
        public int $versions,
    ) {}
}
