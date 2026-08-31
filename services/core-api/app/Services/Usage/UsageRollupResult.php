<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Enums\QuotaMetric;

/**
 * What one organization's reconciliation actually did.
 *
 * THE COUNTS ARE OF ROWS WRITTEN, NOT ROWS EXAMINED, and the difference is the whole value of the
 * output: the steady state of this command is ZERO on every line, because everything was already
 * metered on the request path. A non-zero `derivedTokenEvents` means a finalizer died somewhere,
 * which is a thing to look at rather than a thing to celebrate — so an operator reading the table
 * needs the number that means "we found a hole", not the number that means "we looked".
 */
final readonly class UsageRollupResult
{
    /**
     * @param  list<QuotaMetric>  $reconciledMetrics  the metrics whose Valkey counter was refreshed
     *                                                from PostgreSQL on this pass
     */
    public function __construct(
        public int $derivedTokenEvents,
        public int $derivedStorageEvents,
        public array $reconciledMetrics,
    ) {}

    /**
     * Nothing was found and nothing was refreshed — the expected outcome for an unmetered
     * organization, which is skipped entirely rather than reconciled.
     */
    public function isEmpty(): bool
    {
        return $this->derivedTokenEvents === 0
            && $this->derivedStorageEvents === 0
            && $this->reconciledMetrics === [];
    }
}
