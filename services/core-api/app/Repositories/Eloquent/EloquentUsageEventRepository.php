<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Enums\QuotaMetric;
use App\Enums\UsageEventType;
use App\Models\UsageEvent;
use App\Repositories\Contracts\UsageEventRepositoryInterface;
use App\Services\Usage\RecordedUsage;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The quota ledger, in SQL.
 *
 * ── THE WRITE IS `INSERT … ON CONFLICT DO NOTHING`, AND IT HAS TO BE ─────────────────────────
 *
 * `SELECT` then `INSERT` is not atomic at READ COMMITTED, so two workers both see nothing and both
 * insert (`postgresql-patterns`). On this table that is a duplicate charge. `ON CONFLICT DO NOTHING`
 * against `usage_events_dedupe` is the claim, and the row count is the answer to "was this new".
 *
 * `MERGE` is deliberately not used: it is a join rather than a speculative insert, and two
 * concurrent MERGEs that both take the NOT MATCHED branch raise a unique violation instead of
 * skipping — PostgreSQL's own notes point at `INSERT … ON CONFLICT` for exactly this.
 *
 * ── WHY THE INSERT IS RAW AND NOT `UsageEvent::create()` ─────────────────────────────────────
 *
 * Eloquent has no `ON CONFLICT DO NOTHING` that also reports whether it wrote — `insertOrIgnore()`
 * returns an affected-row count on the query builder and bypasses the model's casts, and
 * `firstOrCreate()` is the SELECT-then-INSERT race above. The model's `$fillable` is empty by design
 * anyway, so nothing here loses a guard that existed. The organization predicate is EXPLICIT in the
 * bindings, exactly as it is in every read below.
 *
 * ── EVERY READ CARRIES ITS ORGANIZATION EXPLICITLY ──────────────────────────────────────────
 *
 * `UsageEvent` carries `#[ScopedBy(OrganizationScope::class)]`, which is the backstop; the
 * `where('organization_id', ...)` in each method is the mechanism. On a queue worker or a console
 * command whose ambient context is stale, the scope reads that same stale context — so the two fail
 * together unless the argument is explicit. On this table the failure is one tenant's spend counted
 * against another tenant's quota, which nothing downstream would ever raise about.
 */
final class EloquentUsageEventRepository implements UsageEventRepositoryInterface
{
    public function record(string $organizationId, RecordedUsage $usage): bool
    {
        // ULID FROM THE MODEL'S OWN GENERATOR, so there is one definition of what an id looks like
        // and `HasUlids` never mints a competing one. It is generated OUTSIDE the statement because
        // a conflicting insert must not consume a fresh id per attempt in a way that is visible —
        // it does not matter here (ids are not sequential), and it keeps the statement pure.
        $id = (new UsageEvent)->newUniqueId();

        // A raw INSERT, with every value BOUND. The only interpolated text in this statement is the
        // table name, which is a literal.
        $affected = DB::affectingStatement(<<<'SQL'
            INSERT INTO usage_events (
                id, occurred_at, organization_id, bot_id, event_type, quantity,
                provider, model, dedupe_key, aggregation_metadata, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, now())
            ON CONFLICT (organization_id, event_type, dedupe_key, occurred_at) DO NOTHING
        SQL, [
            $id,
            $usage->occurredAt->toIso8601String(),
            $organizationId,
            $usage->botId,
            $usage->type->value,
            $usage->quantity,
            $usage->provider?->value,
            $usage->model,
            $usage->dedupeKey,
            // `JSON_FORCE_OBJECT` is NOT used and must not be: it would turn a list value nested
            // inside the metadata into an object with numeric keys. What the column requires is that
            // the TOP LEVEL is an object, which `(object)` casting an empty array gives us — an
            // empty PHP array encodes as `[]`, a JSON ARRAY, and
            // `usage_events_aggregation_metadata_is_object` refuses it.
            json_encode((object) $usage->aggregationMetadata, JSON_THROW_ON_ERROR),
        ]);

        return $affected > 0;
    }

    public function consumed(string $organizationId, QuotaMetric $metric, DateTimeImmutable $at): int
    {
        if (! $metric->isLedgerBacked()) {
            // A POINT-IN-TIME metric has no ledger terms, so the query below would carry an empty
            // `event_type IN ()` predicate — which PostgreSQL rejects and, worse, which a naive
            // implementation would "fix" by dropping the predicate and summing the whole table. A
            // plausible zero (or a plausible everything) is the worst possible answer to a quota
            // question, so this raises instead. `QuotaCounters` routes these to a `count(*)` over
            // the primary table.
            throw new InvalidArgumentException(
                "QuotaMetric::{$metric->name} is point-in-time, not ledger-backed: it is answered "
                .'by count(*) over its own table and goes DOWN when a row is deleted. Summing '
                .'usage_events for it would produce a running total that drifts from the truth the '
                .'first time a row leaves by a route nobody instrumented.',
            );
        }

        $terms = $metric->ledgerTerms();
        $periodStart = $metric->periodStart(
            DateTimeImmutable::createFromInterface($at),
        );

        $types = array_merge($terms['add'], $terms['subtract']);
        $subtract = array_map(static fn (UsageEventType $t): string => $t->value, $terms['subtract']);

        // ONE STATEMENT, NOT TWO. A separate SUM per direction would read the index twice and — the
        // part that matters — would make "added minus removed" a subtraction in PHP over two
        // independently-rounded reads taken at two different instants. A `CASE` inside one SUM is
        // one snapshot.
        //
        // `sum(...)` over no rows is NULL, not 0, so the coalesce is not decoration: without it a
        // brand-new organization's consumed storage is NULL and every comparison against it is NULL,
        // which in PHP becomes 0 by accident and in SQL becomes "no rows" by design.
        $direction = $subtract === []
            ? 'quantity'
            : 'CASE WHEN event_type IN ('
                .implode(', ', array_fill(0, count($subtract), '?'))
                .') THEN -quantity ELSE quantity END';

        $query = UsageEvent::query()
            ->where('organization_id', '=', $organizationId)
            ->whereIn('event_type', array_map(
                static fn (UsageEventType $t): string => $t->value,
                $types,
            ));

        if ($periodStart !== null) {
            // `>=` on the partition key, which is what lets PostgreSQL prune to the months the
            // period spans. A `date_trunc('month', occurred_at) = ...` predicate would be correct
            // and would prune NOTHING and use no index, because it is a function of the column.
            //
            // BOUND AS A `Y-m-d H:i:s.uP` STRING AND NOT AS A DateTimeInterface, for the reason
            // `AnalyticsWindow` records at length: `Connection::prepareBindings()` formats a
            // date object with the grammar's `'Y-m-d H:i:s'`, which DROPS MICROSECONDS. Harmless on
            // this particular bound — a period start is a month boundary, whose microseconds are
            // zero — and spelled the safe way anyway, because the day somebody introduces a
            // non-midnight period (a billing anniversary) the truncation becomes a real off-by-one
            // and nothing would report it.
            $query->where('occurred_at', '>=', $periodStart->format('Y-m-d H:i:s.uP'));
        }

        $total = $query->selectRaw(
            'coalesce(sum('.$direction.'), 0) AS total',
            $subtract,
        )->value('total');

        return is_numeric($total) ? (int) $total : 0;
    }

    public function tokensByModel(
        string $organizationId,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
    ): array {
        $rows = UsageEvent::query()
            ->where('organization_id', '=', $organizationId)
            ->whereIn('event_type', [
                UsageEventType::ChatTokensInput->value,
                UsageEventType::ChatTokensOutput->value,
            ])
            // Half-open [from, until), matching every other window in this application and matching
            // the partition bounds themselves. A closed upper bound double-counts the boundary
            // instant when two windows are placed end to end.
            // Rendered at microsecond precision — see `consumed()` above and `AnalyticsWindow`.
            // On THIS pair it is load-bearing rather than defensive: the upper bound is exclusive,
            // so a truncated `until` silently drops everything inside its final second.
            ->where('occurred_at', '>=', $from->format('Y-m-d H:i:s.uP'))
            ->where('occurred_at', '<', $until->format('Y-m-d H:i:s.uP'))
            ->groupBy('provider', 'model')
            ->orderBy('provider')
            ->orderBy('model')
            ->selectRaw(
                'provider, model,'
                .' coalesce(sum(quantity) FILTER (WHERE event_type = ?), 0) AS input_tokens,'
                .' coalesce(sum(quantity) FILTER (WHERE event_type = ?), 0) AS output_tokens',
                [
                    UsageEventType::ChatTokensInput->value,
                    UsageEventType::ChatTokensOutput->value,
                ],
            )
            ->get();

        $out = [];

        foreach ($rows as $row) {
            // Read through the attribute bag rather than through the model's casts: this is an
            // aggregate projection, so `provider` here is the GROUP BY key as PostgreSQL returned it
            // and the enum cast would be applied to a value that may legitimately be absent from a
            // future vocabulary. `(string)` keeps the wire shape stable either way.
            $attributes = $row->getAttributes();

            $out[] = [
                'provider' => (string) ($attributes['provider'] ?? ''),
                'model' => (string) ($attributes['model'] ?? ''),
                'input_tokens' => (int) ($attributes['input_tokens'] ?? 0),
                'output_tokens' => (int) ($attributes['output_tokens'] ?? 0),
            ];
        }

        return $out;
    }
}
