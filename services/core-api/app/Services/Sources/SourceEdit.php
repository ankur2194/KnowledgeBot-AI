<?php

declare(strict_types=1);

namespace App\Services\Sources;

use InvalidArgumentException;

/**
 * A partial edit of a source, as a closed column map.
 *
 * ── THE ALLOW-LIST IS THE TYPE, NOT A CONVENTION ──────────────────────────────────────────────
 *
 * `BotEdit` states the argument and it holds identically here, with one column that makes it
 * sharper: `status` is ABSENT. A mass-assignable status is a `PATCH {"status":"ready"}` that
 * publishes a version nothing verified — non-negotiable 5 defeated by a form field. The lifecycle
 * lives on its own route, driven by `SourceState::transitionTable()`.
 *
 * `type` is absent for a different reason. It is not a preference, it is which PIPELINE processes
 * the source and which of `knowledge_sources_origin_url_matches_type`'s two halves applies; a
 * `file` source that became a `url` source would have items and versions produced by a parser that
 * has nothing to do with the bytes they were built from. A source of the wrong kind is a new
 * source.
 *
 * `origin_url` is absent for the same reason once removed: it is half of that CHECK, and editing it
 * on a live crawl source changes what this platform fetches without re-running the SSRF envelope
 * that admitted the original value. A new target is a new source.
 */
final readonly class SourceEdit
{
    /**
     * @var list<string>
     */
    public const WRITABLE = [
        'name',
        'description',
        'tags',
        'effective_at',
        'expires_at',
    ];

    /**
     * @param  array<string, mixed>  $columns
     */
    public function __construct(private array $columns)
    {
        $unknown = array_diff(array_keys($columns), self::WRITABLE);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'SourceEdit refuses the column(s) '.implode(', ', $unknown).'. `organization_id`, '
                .'`status`, `type`, `origin_url`, `created_by`, `deleted_at` and `purged_at` are '
                .'absent from the allow-list deliberately — a map that accepted an arbitrary key '
                .'would put every one of them one careless line of request input away from being '
                .'writable, and two of them are the difference between "we removed it" and "we '
                .'proved we removed it".',
            );
        }
    }

    public function names(string $column): bool
    {
        return array_key_exists($column, $this->columns);
    }

    public function valueFor(string $column): mixed
    {
        return $this->columns[$column] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    public function isEmpty(): bool
    {
        return $this->columns === [];
    }
}
