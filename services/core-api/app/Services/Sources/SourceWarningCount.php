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
    public function __construct(
        public string $code,
        public int $versions,
    ) {}
}
