<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Enums\Provider;
use App\Enums\QuotaMetric;
use App\Enums\UsageEventType;
use App\Models\ProviderCall;
use App\Repositories\Contracts\UsageEventRepositoryInterface;
use App\Services\Quotas\QuotaCounters;
use Carbon\CarbonImmutable;

/**
 * THE ONLY WRITER OF `usage_events`.
 *
 * ── ONE WRITER, FOR THE SAME REASON `AuditLogger` IS THE ONLY WRITER OF `audit_logs` ─────────
 *
 * Two rules have to hold on every row and neither is expressible as a constraint:
 * `occurred_at` must be DERIVED FROM THE SOURCE ROW rather than from the clock, and `dedupe_key`
 * must be the stable identity of the WORK. A second writer is a second chance to get either wrong,
 * and both failures are silent double-counting in a billing table. `RecordedUsage`'s constructor is
 * where they are enforced; this class is where the derivations from real rows live.
 *
 * ── EVERY WRITE ALSO MOVES THE VALKEY COUNTER, AND THAT ORDER IS NOT NEGOTIABLE ─────────────
 *
 * PostgreSQL first, Valkey second, always. The ledger is the truth; the counter is a lower bound in
 * front of it. Bumping first would create a window in which the counter is HIGH — the one direction
 * `QuotaCounters` relies on being impossible, because a refusal computed from a high counter refuses
 * a request that was inside its allowance, and nothing anywhere would say so.
 *
 * The bump is skipped when the insert was a no-op (a duplicate), which is what keeps a re-derivation
 * from inflating the counter even though it wrote no row.
 */
final readonly class UsageRecorder
{
    public function __construct(
        private UsageEventRepositoryInterface $ledger,
        private QuotaCounters $counters,
    ) {}

    /**
     * Record one already-specified usage fact.
     *
     * @return bool whether a row was written. False means this usage was already recorded — a normal
     *              outcome on any path that can run twice, and never an error.
     */
    public function record(string $organizationId, RecordedUsage $usage): bool
    {
        $written = $this->ledger->record($organizationId, $usage);

        if (! $written) {
            return false;
        }

        foreach ($this->metricsFor($usage->type) as $metric => $delta) {
            $this->counters->bump(
                $organizationId,
                QuotaMetric::from($metric),
                $delta * $usage->quantity,
                $usage->occurredAt,
            );
        }

        return true;
    }

    /**
     * Meter one finished provider attempt: up to two ledger rows, input and output.
     *
     * ── IT READS THE `provider_calls` ROW AND NOTHING ELSE ──────────────────────────────────
     *
     * `occurred_at` is the CALL'S `created_at`, not `now()`. That is what makes re-deriving the same
     * call collide on `usage_events_dedupe` instead of adding, which is the property
     * `kb:rollup-usage` depends on to be safe to run hourly forever. `dedupe_key` is the call's own
     * ULID for the same reason.
     *
     * ── THE ARITHMETIC IS `UsageArithmetic`'S AND IS NOT REPEATED HERE ──────────────────────
     *
     * Input is `input_tokens` ALONE — already the normalized total, cache INCLUDED — and output is
     * `output_tokens + reasoning_tokens`, because every vendor here bills reasoning at the output
     * rate. Both are the kind of wrong that produces a plausible number, and both are stated once,
     * in that class, beside the SQL twins the analytics aggregate uses.
     *
     * ── A ZERO-TOKEN SIDE WRITES NO ROW ────────────────────────────────────────────────────
     *
     * A call that produced no output (a failure before generation) writes an input row and no output
     * row. A zero row would be indistinguishable from a real measurement of zero and would put a
     * `(provider, model)` pair into the per-model breakdown with nothing behind it.
     *
     * ── A CALL THE PROVIDER TOLD US NOTHING ABOUT IS SKIPPED, NOT ZEROED ───────────────────
     *
     * All five token columns are nullable and NULL means "the provider told us nothing" — a stream
     * that died before its usage frame. `UsageArithmetic` coalesces to 0, so such a call produces no
     * rows at all here, which is correct: recording zero would assert a measurement nobody made and
     * would permanently dedupe the call, so a later `kb:rollup-usage` run could never record the
     * real numbers if they arrived.
     *
     * @return int how many ledger rows were written (0, 1 or 2)
     */
    public function recordProviderCall(ProviderCall $call, Provider $provider, string $model): int
    {
        $organizationId = $call->organization_id;
        $occurredAt = CarbonImmutable::instance($call->created_at);

        $written = 0;

        $input = UsageArithmetic::totalInputTokens($call);
        $output = UsageArithmetic::totalOutputTokens($call);

        $metadata = [
            // PROVENANCE, NOT A JOIN KEY. It records what this row was derived from so a number in a
            // billing report can be traced back; nothing queries it by predicate, which is what
            // keeps it inside the three jsonb shapes postgresql-patterns admits.
            'derived_from' => 'provider_call',
            'provider_call_id' => $call->id,
            'provider_call_status' => $call->status->value,
        ];

        if ($input > 0) {
            $written += (int) $this->record($organizationId, new RecordedUsage(
                type: UsageEventType::ChatTokensInput,
                quantity: $input,
                occurredAt: $occurredAt,
                dedupeKey: $call->id,
                botId: $call->bot_id,
                provider: $provider,
                model: $model,
                aggregationMetadata: $metadata,
            ));
        }

        if ($output > 0) {
            $written += (int) $this->record($organizationId, new RecordedUsage(
                type: UsageEventType::ChatTokensOutput,
                quantity: $output,
                occurredAt: $occurredAt,
                // THE SAME DEDUPE KEY AS THE INPUT ROW, AND THAT IS CORRECT rather than a collision:
                // `usage_events_dedupe` is `(organization_id, event_type, dedupe_key, occurred_at)`,
                // so the two rows differ in `event_type` and each still dedupes against its own
                // re-derivation. Making them differ — `{id}:out` — would work and would mean the
                // ledger no longer names the provider call by its own identifier, so a reconciliation
                // against `provider_calls` would need a string parse.
                dedupeKey: $call->id,
                botId: $call->bot_id,
                provider: $provider,
                model: $model,
                aggregationMetadata: $metadata,
            ));
        }

        return $written;
    }

    /**
     * Meter bytes an accepted upload put into object storage.
     *
     * `occurredAt` is the moment the item row was created and `dedupeKey` is the item's ULID, so a
     * retry of the same intake cannot charge twice. `botId` is deliberately null: storage is an
     * ORGANIZATION-level fact, and attributing it to whichever bot is later assigned the source
     * would put an upload's cost on a bot that did not exist when it happened.
     */
    public function recordStorageAdded(
        string $organizationId,
        string $sourceItemId,
        int $bytes,
        CarbonImmutable $occurredAt,
    ): bool {
        return $this->record($organizationId, new RecordedUsage(
            type: UsageEventType::StorageBytesAdded,
            quantity: $bytes,
            occurredAt: $occurredAt,
            dedupeKey: $sourceItemId,
            aggregationMetadata: [
                'derived_from' => 'source_item',
                'source_item_id' => $sourceItemId,
            ],
        ));
    }

    /**
     * Which quota metrics one event type moves, and in which direction.
     *
     * `+1` adds and `-1` subtracts, matching `QuotaMetric::ledgerTerms()` — the two are the same
     * table read from opposite ends, and `tests/Unit/QuotaMetricTest.php` asserts they agree. They
     * are not merged because they answer different questions: `ledgerTerms()` builds a SQL predicate
     * over a whole period, this builds an in-place delta for one row.
     *
     * @return array<string, int> keyed by `QuotaMetric->value`
     */
    private function metricsFor(UsageEventType $type): array
    {
        return match ($type) {
            UsageEventType::ChatTokensInput,
            UsageEventType::ChatTokensOutput => [QuotaMetric::MonthlyTokens->value => 1],
            UsageEventType::StorageBytesAdded => [QuotaMetric::StorageBytes->value => 1],
            UsageEventType::StorageBytesRemoved => [QuotaMetric::StorageBytes->value => -1],
        };
    }
}
