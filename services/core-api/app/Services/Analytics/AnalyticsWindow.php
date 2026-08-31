<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Support\Database\SqlTimestamp;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The half-open window every analytics tile is computed over, plus the optional bot filter.
 *
 * ── HALF-OPEN `[from, until)`, EVERYWHERE, WITHOUT EXCEPTION ─────────────────────────────────
 *
 * The same convention the partition bounds use, and for the same reason: two adjacent windows placed
 * end to end must partition the timeline exactly, with no instant in both and none in neither. A
 * closed upper bound double-counts the boundary instant, which is invisible at day granularity and
 * is exactly what makes a month-over-month comparison wrong by one turn.
 *
 * ── THE BOT FILTER IS PART OF THE WINDOW AND NOT A SEPARATE ARGUMENT ─────────────────────────
 *
 * Because it has to reach every tile or none. A dashboard that filters the conversation count by bot
 * and forgets the error rate is a screen where two numbers describe two different populations and
 * nothing says so. Carrying it here means each repository method takes ONE object and a new tile
 * cannot forget the filter without also failing to compile.
 *
 * THE ORGANIZATION IS DELIBERATELY NOT ON THIS OBJECT. It is a required positional argument on every
 * repository method, exactly as it is everywhere else in this layer, so that it cannot be defaulted,
 * cannot be null, and cannot be forgotten by a caller who constructed a window without one.
 */
final readonly class AnalyticsWindow
{
    /**
     * The `Y-m-d H:i:s.uP` renderings of the two bounds, for BINDING INTO A QUERY.
     *
     * ═══ WHY NOT BIND THE CARBON OBJECTS, WHICH IS WHAT EVERY OTHER QUERY IN THIS APPLICATION
     *     DOES ══════════════════════════════════════════════════════════════════════════════
     *
     * Because Laravel truncates them to the second, and on a HALF-OPEN upper bound that is not a
     * rounding error, it is a silently wrong answer.
     *
     * `Connection::prepareBindings()` formats a `DateTimeInterface` with
     * `$grammar->getDateFormat()`, which for PostgreSQL is `'Y-m-d H:i:s'` — NO MICROSECONDS. So an
     * `until` of `07:53:16.482` binds as `07:53:16.000`, and `created_at < until` then EXCLUDES
     * everything that happened in the current second. A dashboard defaulting to "until now" would
     * therefore never show the request that was just made — and, worse, two adjacent windows placed
     * end to end would both drop the sub-second remainder of their shared boundary, so the
     * timeline would have a hole at every seam instead of partitioning exactly.
     *
     * MEASURED, NOT REASONED ABOUT: five tiles in tests/Feature/AnalyticsEndpointTest.php returned
     * 0 against fixtures created milliseconds earlier, and every one of them was this.
     *
     * The lower bound is affected in the harmless direction (truncating down makes `>=` more
     * inclusive) and is rendered the same way anyway, because two spellings of one bound is how they
     * end up disagreeing.
     *
     * PostgreSQL parses `2026-08-27 07:53:16.482913+00:00` at full precision, and the offset is
     * explicit rather than implied by the session `TimeZone` — which is the same reason every column
     * in this schema is `timestamptz` and never `timestamp`.
     *
     * THE FORMAT STRING MOVED TO `App\Support\Database\SqlTimestamp` IN PHASE 6a and this class
     * calls it rather than repeating it. The reasoning above is unchanged and stays here, where the
     * measurement was made; what moved is the literal, because the conversation list and the audit
     * trail grew date filters over `timestamptz` columns and a second copy of `'Y-m-d H:i:s.uP'`
     * would be a second thing to get right — with a wrong answer rather than an exception as the
     * failure mode.
     */
    public string $fromBound;

    public string $untilBound;

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $until,
        public ?string $botId = null,
    ) {
        if ($until <= $from) {
            throw new InvalidArgumentException(
                'An analytics window is half-open [from, until) and must be non-empty. An inverted '
                .'or zero-width window produces a page of zeroes that looks like "no traffic" '
                .'rather than like a bad request.',
            );
        }

        $this->fromBound = SqlTimestamp::bind($from);
        $this->untilBound = SqlTimestamp::bind($until);
    }
}
