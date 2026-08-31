<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Support\Database\SqlTimestamp;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * How a reader narrows the audit trail: who, what, how it went, against which record, and when.
 *
 * ── TWO OF THESE FIVE ARE CLOSED VOCABULARIES AND THREE ARE NOT, AND THE SPLIT IS NOT A STYLE
 *    CHOICE ────────────────────────────────────────────────────────────────────────────────
 *
 * `operation` and `outcome` are closed AT THE WRITER: `AuditLogger::record()` throws
 * `InvalidArgumentException` on an operation that is not a key of `AuditLogger::OPERATIONS`, and
 * `outcome` is DERIVED from that map rather than passed in, so no row can exist outside either set.
 * A filter value outside them therefore cannot match anything that will ever be written, which
 * makes it a malformed request rather than an empty result — `validation` (422), and
 * `IndexAuditLogsRequest` closes both with `Rule::in()`.
 *
 * `subject_type` is NOT closed, and closing it would be a defect. The migration says so in as many
 * words: *"It has no CHECK, for the same reason `operation` has none: the set is open and a refused
 * audit row is worse than a row nobody wrote rules for."* Eleven classes appear as subjects today
 * and the twelfth needs no schema change — so a `Rule::in()` here would 422 a legitimate query the
 * day a new subject is audited, which is a false refusal on the surface an investigation uses. It
 * is validated for SHAPE only, and a value nobody has ever written simply matches nothing.
 *
 * `actor_id` is a ULID with NO `exists:` RULE, for the reason `ShowAnalyticsRequest` records at
 * length: `exists:users,id` is unscoped, so it would turn this endpoint into an existence oracle
 * over every organization's users — the Filament CVE-2026-48067 shape, where the select query is
 * tenant-scoped and the validation rule for the same field is not. A foreign actor id matches
 * nothing, which is the correct answer.
 *
 * ── `subject_id` REQUIRES `subject_type`, AND THAT IS AN INDEX FACT ────────────────────────
 *
 * `audit_logs_org_subject_created` is `(organization_id, subject_type, subject_id, created_at DESC)
 * WHERE subject_id IS NOT NULL`. Given both, the leading three columns are constrained and the read
 * is an index range with the sort already satisfied — this is "the audit trail for THIS record",
 * the query the index was created for and the one an admin UI opens with. Given only `subject_id`,
 * the second column is a gap and PostgreSQL would have to skip-scan or fall back; the migration
 * that built these indexes explicitly declines to rely on PG 18's skip scan at organization scale,
 * so the pairing is enforced in the FormRequest instead of hoped for in the planner.
 *
 * Given only `subject_type`, the index is *not* used — the query cannot prove `subject_id IS NOT
 * NULL`, which a partial index requires — and the read falls to `audit_logs_org_created` with a
 * filter. That is a scan of one organization's rows in the window and is accepted; it is written
 * down here so nobody reads the pairing rule above as a claim that every subject filter is an
 * index range.
 *
 * ── THE ORGANIZATION IS NOT ON THIS OBJECT ─────────────────────────────────────────────────
 *
 * It is a required positional argument on `AuditLogRepositoryInterface::paginate()`. On this table
 * that is not merely the house rule: `AuditLog` carries no `#[ScopedBy]` (the scope's predicate is
 * FALSE for the NULL-org platform rows and would hide them from every surface forever), so the
 * explicit argument is the ONLY tenancy layer there is. A nullable field on a filter object is a
 * field a caller can leave unset; a positional argument is not.
 */
final readonly class AuditLogFilter
{
    /** The two bounds rendered for binding. See `SqlTimestamp` for why a Carbon may not be bound. */
    public ?string $fromBound;

    public ?string $untilBound;

    public function __construct(
        public ?string $actorId = null,
        public ?string $operation = null,
        public ?string $outcome = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $until = null,
    ) {
        if ($subjectId !== null && $subjectType === null) {
            throw new InvalidArgumentException(
                'An audit subject is a (type, id) pair. An id with no type is ambiguous across '
                .'every table — the database says the same thing in audit_logs_subject_paired — and '
                .'it also leaves a gap in the middle of audit_logs_org_subject_created, so the read '
                .'stops being an index range.',
            );
        }

        if ($from !== null && $until !== null && $until <= $from) {
            throw new InvalidArgumentException(
                'An audit window is half-open [from, until) and must be non-empty. An inverted or '
                .'zero-width window selects nothing and renders as "nothing happened" on the one '
                .'surface where that is the most dangerous wrong answer.',
            );
        }

        $this->fromBound = SqlTimestamp::bindOrNull($from);
        $this->untilBound = SqlTimestamp::bindOrNull($until);
    }
}
