<?php

declare(strict_types=1);

namespace App\Services\Bots;

/**
 * One retrieval-scope grant that a bot delete is about to destroy, flattened into scalars.
 *
 * ── IT EXISTS BECAUSE A BOT DELETE REACHES ROWS THE REQUEST NEVER NAMED ──────────────────────
 *
 * `bot_source_assignments` references `bots (organization_id, id)` with `ON DELETE RESTRICT`, so
 * `EloquentBotRepository::delete()` removes these rows itself — exactly as it already does for the
 * origin allow-list, the starter questions and the fallback chain — or a bot with a single assigned
 * source raises SQLSTATE 23503 on a request that is entirely legitimate.
 *
 * Removing them SILENTLY would be finding L2 one entity over, and `AuditLogger`'s own docblock for
 * the two assignment operations says so: *"A LOG policy on `created` would permit a retrieval-scope
 * grant to exist with no record of who made it, which is the whole of finding L2 restated one
 * entity over — and with higher stakes, because this grant reaches documents rather than a page
 * that may embed a widget."* The same argument applies to the destruction of one. So the delete
 * writes a `bot.source_assignment.deleted` row per grant, and this type is what carries the values
 * from the repository's transaction out to the closure that writes them.
 *
 * ── WHY IT IS A TYPE AND NOT THE ELOQUENT MODEL ──────────────────────────────────────────────
 *
 * `source_name` is not a column on `bot_source_assignments`. It is read from `knowledge_sources`
 * with its own explicit organization predicate — never through a lazy relation, which
 * `Model::shouldBeStrict()` forbids, and never through an eager load whose scoping would come from
 * the ambient `TenantContext` rather than from the argument this layer was given. Once that read
 * has happened the pairing has to travel somewhere, and a model with an ad-hoc attribute bolted on
 * is a model whose shape depends on which method produced it.
 *
 * `source_name` IS NULLABLE, and the null is a real state rather than defensive typing: nothing in
 * the schema stops a `knowledge_sources` row from having been hard-deleted between the grant being
 * written and the bot being deleted. `AuditLogger::sanitize()` skips a null silently, so such a row
 * records the two ULIDs and omits the name — which is strictly better than the row not existing.
 */
final readonly class RemovedSourceAssignment
{
    public function __construct(
        public string $id,
        public string $botId,
        public string $sourceId,
        public ?string $sourceName,
        public int $priority,
        public bool $enabled,
    ) {}

    /**
     * The audit `details` payload for one `bot.source_assignment.deleted` row.
     *
     * EVERY KEY IS IN THAT OPERATION'S ALLOW-LIST AND NOTHING ELSE IS. `sanitize()` drops an
     * unlisted field and REPORTS the drop at WARNING, so an extra key here would emit a log line
     * about an audit defect that is not one on every bot delete, and the real ones would be lost in
     * it — the same trap `SourceService::delete()` records for `source.deleted`.
     *
     * @return array<string, bool|int|string>
     */
    public function toAuditDetails(): array
    {
        $details = [
            // Load-bearing rather than redundant: `subject_id` is this assignment's own ULID and
            // both parents are hard deletes, so a row carrying only that resolves to nothing on
            // either end afterwards — and "which documents was this bot allowed to answer from" is
            // precisely the question asked after the fact.
            'bot_id' => $this->botId,
            'source_id' => $this->sourceId,
            'priority' => $this->priority,
            // Whether it was LIVE when it went. A removed disabled row granted nothing; a removed
            // enabled one did, and that difference is the whole reading of this row.
            'enabled' => $this->enabled,
        ];

        if ($this->sourceName !== null) {
            // Omitted rather than passed as null, because "the source row was already gone" is a
            // statement about the world rather than a value that happened to be absent — and a null
            // would be skipped silently either way, leaving no way to tell the two apart in code.
            $details['source_name'] = $this->sourceName;
        }

        return $details;
    }
}
