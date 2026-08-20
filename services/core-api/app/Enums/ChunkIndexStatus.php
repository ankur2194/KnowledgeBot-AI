<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether ONE chunk row has a live counterpart in the vector collection.
 *
 * ── THIS IS NOT A SECOND COPY OF `SourceState`, AND CONFLATING THE TWO IS THE HAZARD ─────────
 *
 * `source_versions.status` says where the whole RUN is. This column says whether this particular
 * chunk's point was upserted, and it exists for exactly one reason: `chunks` is the table a rebuild
 * reads (ADR-010 — Qdrant is derived and rebuildable), so after a partial upsert somebody has to be
 * able to name the rows that did not land WITHOUT re-querying a collection that may not exist.
 *
 * IT IS NEVER A RETRIEVAL PREDICATE. Retrieval filters the four mandatory payload terms and nothing
 * else; a chunk marked `Indexed` whose version is not the active one is still unreachable, and a
 * chunk marked `Pending` in the active version would be a verification failure rather than a
 * quietly-degraded answer — `publish_version` counts points with `exact=True` before Laravel is
 * ever told the run succeeded. Reading this column in a query path would be a fifth filter term
 * that fails open on a stale value.
 *
 * TEXT + CHECK, generated from `values()`, never a native PG enum — the reason every other status
 * column in this schema gives.
 */
enum ChunkIndexStatus: string
{
    /** Written to PostgreSQL, not yet upserted. The state every chunk is born in. */
    case Pending = 'pending';

    /** Upserted under this chunk's `vector_point_id`. Says nothing about reachability. */
    case Indexed = 'indexed';

    /**
     * The upsert was attempted and did not land.
     *
     * A real state rather than "absent": a rebuild has to distinguish a chunk nobody has tried yet
     * from one that has already refused, or the retry loop is unbounded and silent.
     */
    case Failed = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
