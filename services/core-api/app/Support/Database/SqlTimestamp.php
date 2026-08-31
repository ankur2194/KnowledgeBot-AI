<?php

declare(strict_types=1);

namespace App\Support\Database;

use Carbon\CarbonImmutable;

/**
 * The one rendering of an instant that may be bound into a query in this application.
 *
 * ═══ WHY BINDING A CARBON OBJECT IS WRONG, AND WHY IT IS WRONG SILENTLY ══════════════════════
 *
 * `Connection::prepareBindings()` formats a `DateTimeInterface` with `$grammar->getDateFormat()`,
 * which for PostgreSQL is `'Y-m-d H:i:s'` — NO MICROSECONDS. So an upper bound of `07:53:16.482`
 * binds as `07:53:16.000`, and a half-open `created_at < until` then EXCLUDES everything that
 * happened in the current second. Nothing raises: the query is legal, the plan is the same, and the
 * answer is short by however much traffic landed in that fraction of a second.
 *
 * IT WAS MEASURED RATHER THAN REASONED ABOUT. `App\Services\Analytics\AnalyticsWindow`'s docblock
 * carries the record: five tiles in tests/Feature/AnalyticsEndpointTest.php returned 0 against
 * fixtures created milliseconds earlier, and every one of them was this.
 *
 * ═══ WHY IT IS A CLASS AND NOT A `format()` CALL AT EACH SITE ════════════════════════════════
 *
 * `AnalyticsWindow` owned this format string alone until Phase 6a, when the conversation list and
 * the audit trail both grew date filters over `timestamptz` columns. A second literal
 * `'Y-m-d H:i:s.uP'` is a second thing to get right, and the failure mode of getting it wrong is
 * not an exception — it is a list that quietly omits its newest rows. One constant, three callers.
 *
 * THE OFFSET IS EXPLICIT (`P`) AND NOT IMPLIED BY THE SESSION `TimeZone`, which is the same reason
 * every timestamp column in this schema is `timestamptz` and never `timestamp`: a bound whose zone
 * depends on a connection setting is a bound that changes meaning when a worker connects
 * differently from a web request.
 */
final readonly class SqlTimestamp
{
    /**
     * `Y-m-d H:i:s.uP` — microseconds, then an explicit offset. PostgreSQL parses
     * `2026-08-27 07:53:16.482913+00:00` at full precision.
     */
    public const FORMAT = 'Y-m-d H:i:s.uP';

    /**
     * Render an instant for binding. Always UTC first: the columns are `timestamptz` and the
     * application's own clock is UTC everywhere, so converting here means a caller that parsed a
     * client's `+05:30` cannot bind a bound in a zone nothing else in the query uses.
     */
    public static function bind(CarbonImmutable $at): string
    {
        return $at->utc()->format(self::FORMAT);
    }

    /** The nullable form, for an optional filter bound. */
    public static function bindOrNull(?CarbonImmutable $at): ?string
    {
        return $at === null ? null : self::bind($at);
    }
}
