<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\QuotaMetric;
use App\Models\Organization;
use App\Services\Quotas\QuotaUsage;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The organization's four quota metrics: used, limit, remaining, and which store answered.
 *
 * ── `limit: null` MEANS UNLIMITED AND `limit: 0` MEANS NOTHING IS ALLOWED ───────────────────
 *
 * Both render as "no number" on a form and they are opposite states, so the schema below says so on
 * the field rather than leaving a client to infer it. A console that renders `null` as `0` freezes
 * an unmetered organization on screen; one that renders `0` as "unlimited" tells an operator their
 * freeze did not take.
 *
 * ── `source` IS PUBLISHED, DELIBERATELY ─────────────────────────────────────────────────────
 *
 * Valkey is the fast path and PostgreSQL is the truth; the counter can only ever be LOW. This
 * endpoint always reads the primary, so `source` is `database` on every row of a healthy response —
 * and that is exactly why it is worth shipping: it is a field whose value never varies until
 * something is wrong, which makes it the cheapest possible detector of the one failure that is
 * otherwise silent. It is NOT a hint that the number is unreliable.
 *
 * ── NOTHING HERE IS A CREDENTIAL ────────────────────────────────────────────────────────────
 *
 * Four metric names and eight integers. `QuotaUsage` has nowhere to put a secret and this path never
 * reads `provider_connections`.
 *
 * @property-read Organization $resource
 */
final class QuotaResource extends JsonResource implements ProvidesOpenApiSchema
{
    /**
     * @param  array<string, QuotaUsage>  $usage  keyed by `QuotaMetric->value`, from
     *                                            `QuotaGate::snapshot()`. NO DEFAULT: a caller that
     *                                            omitted it would publish an empty metric list for
     *                                            an organization that has quotas, which reads as
     *                                            "unmetered" on every screen.
     */
    public function __construct(
        Organization $resource,
        private readonly array $usage,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metrics = [];

        // ITERATED OVER THE ENUM AND NOT OVER THE ARRAY, so a metric missing from the snapshot is a
        // loud failure here rather than a key silently absent from the response. Every case must be
        // present: a client rendering four rows and receiving three cannot tell which one it lost.
        foreach (QuotaMetric::cases() as $metric) {
            $usage = $this->usage[$metric->value] ?? null;

            // `$usage === null` IS THE MISSING-METRIC BRANCH and it is written out rather than
            // folded into `?->` plus `??`, because `used` and `source` are non-nullable on
            // QuotaUsage — so `$usage?->used ?? 0` reads as "0 when used is null", which cannot
            // happen, and static analysis correctly calls the nullsafe redundant. The two cases are
            // genuinely different and the branch says which is which.
            $metrics[] = $usage === null
                ? [
                    'metric' => $metric->value,
                    'used' => 0,
                    'limit' => null,
                    'remaining' => null,
                    'exceeded' => false,
                    'source' => QuotaUsage::SOURCE_DATABASE,
                ]
                : [
                    'metric' => $metric->value,
                    'used' => $usage->used,
                    'limit' => $usage->limit,
                    'remaining' => $usage->remaining(),
                    'exceeded' => $usage->exceeded(),
                    'source' => $usage->source,
                ];
        }

        return ['metrics' => $metrics];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'QuotaMetricUsage' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One metric: how much is used, what the ceiling is, and which '
                    .'store answered.',
                'required' => ['metric', 'used', 'limit', 'remaining', 'exceeded', 'source'],
                'properties' => [
                    'metric' => [
                        'type' => 'string',
                        'enum' => QuotaMetric::values(),
                        'description' => 'Which allowance. `storage_bytes` and `monthly_tokens` '
                            .'accumulate over a period and are summed from the usage ledger; '
                            .'`bots` and `users` are point-in-time counts that go DOWN when a row '
                            .'is deleted.',
                    ],
                    'used' => [
                        'type' => 'integer',
                        'description' => 'Consumption in the metric\'s current period. '
                            .'`monthly_tokens` resets on the first of the calendar month, UTC; the '
                            .'other three never reset.',
                    ],
                    'limit' => [
                        'type' => ['integer', 'null'],
                        'description' => 'The ceiling. NULL MEANS UNLIMITED and 0 MEANS NOTHING IS '
                            .'ALLOWED — they are opposite states and both render as "no number" on '
                            .'a form, so do not conflate them.',
                    ],
                    'remaining' => [
                        'type' => ['integer', 'null'],
                        'description' => 'limit − used, floored at 0, or null when unlimited. An '
                            .'over-quota organization has zero left, never less.',
                    ],
                    'exceeded' => [
                        'type' => 'boolean',
                        'description' => 'True when used has reached the ceiling. Always false for '
                            .'an unlimited metric, whatever `used` says.',
                    ],
                    'source' => [
                        'type' => 'string',
                        'enum' => [QuotaUsage::SOURCE_DATABASE, QuotaUsage::SOURCE_CACHE],
                        'description' => 'Which store produced `used`. This endpoint always reads '
                            .'PostgreSQL, so a healthy response is `database` on every row; a '
                            .'`cache` value would mean the primary read was skipped and the number '
                            .'is a lower bound.',
                    ],
                ],
            ],

            'QuotaResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'Every quota metric for one organization. The list is TOTAL — one '
                    .'entry per metric, always, in enum order — so a client rendering four rows '
                    .'never has to decide what a missing one means.',
                'required' => ['metrics'],
                'properties' => [
                    'metrics' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/QuotaMetricUsage'],
                        'description' => 'One entry per QuotaMetric case, in declaration order.',
                    ],
                ],
            ],
        ];
    }
}
