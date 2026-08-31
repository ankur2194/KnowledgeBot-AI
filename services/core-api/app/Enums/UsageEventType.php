<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What one `usage_events` row COUNTS.
 *
 * ── FOUR CASES, AND THE SET IS SMALL ON PURPOSE ───────────────────────────────────────────────
 *
 * `usage_events` is the QUOTA LEDGER, not the analytics warehouse. §8.23's tiles — conversations,
 * unique sessions, latency percentiles, error and fallback rates, the feedback split, the
 * insufficient-evidence count, ingestion outcomes — are all aggregates over `conversations`,
 * `messages`, `provider_calls`, `retrieval_traces`, `feedback` and `knowledge_sources`, which are
 * the source of truth for each of them. Mirroring any of those into a second table would create a
 * second number for one fact and no statement of which one is right.
 *
 * So a case exists here only when the fact it counts is (a) ACCUMULATING over a period and (b)
 * something a quota is expressed in. That is exactly two facts — tokens and stored bytes — and each
 * is split by direction, because the two directions are billed and reconciled differently.
 *
 * THE OTHER TWO QUOTA METRICS ARE NOT EVENTS AND MUST NOT BECOME ONES. `bots` and `users` are
 * POINT-IN-TIME COUNTS: the question is "how many exist now", answered by `count(*)` over `bots`
 * and `organization_users`, and it goes DOWN when one is deleted. Expressing them as a ledger would
 * mean a `bot.created`/`bot.deleted` pair whose running sum is the answer — a second, derived copy
 * of a number the primary table already holds exactly, which drifts the first time a row is removed
 * by a cascade nobody instrumented. `App\Enums\QuotaMetric::isLedgerBacked()` is that split, stated
 * once.
 *
 * ── WHY INPUT AND OUTPUT ARE TWO CASES AND NOT ONE `chat.tokens` ──────────────────────────────
 *
 * They are priced differently by every vendor here, so a single case would make the ledger unable
 * to reproduce a cost estimate without joining back to `provider_calls` — which is precisely the
 * table the ledger exists to avoid re-scanning. Keeping them apart also makes the ledger's own
 * arithmetic checkable against `provider_calls`: input must equal `input_tokens` (the NORMALIZED
 * total, cache included) and output must equal `output_tokens + reasoning_tokens`.
 *
 * ── THE MONTHLY TOKEN QUOTA COUNTS BOTH, AND THAT IS A DECISION ───────────────────────────────
 *
 * `organizations.monthly_tokens_quota` is checked against input + output. The alternative — output
 * only — was rejected because a retrieval-augmented turn's cost is dominated by the packed context,
 * so an output-only quota would let a single organization spend an unbounded amount of the
 * platform's provider budget while its meter barely moved.
 */
enum UsageEventType: string
{
    /**
     * Input tokens billed for one provider attempt: the NORMALIZED total, cache reads and cache
     * writes INCLUDED.
     *
     * This is `provider_calls.input_tokens` verbatim, and that column's own contract is that it is
     * the total rather than the "uncached" part — `kb-anthropic` records that Anthropic's
     * `input_tokens` EXCLUDES cached tokens while every other provider here includes them, and the
     * adapter normalizes before the row is written. `provider_calls_cache_within_input` is the one
     * half of that normalization a constraint can check.
     */
    case ChatTokensInput = 'chat.tokens.input';

    /**
     * Output tokens billed for one provider attempt, REASONING TOKENS INCLUDED.
     *
     * ── THE ONE PIECE OF ARITHMETIC ON THIS ENUM THAT IS EASY TO GET WRONG AND SILENT ─────────
     *
     * `provider_calls` stores `output_tokens` and `reasoning_tokens` as DISJOINT counters, and every
     * vendor this platform speaks to bills reasoning AT THE OUTPUT RATE. An aggregate over bare
     * `output_tokens` therefore under-reports a reasoning turn — plausibly, by a factor that depends
     * on the model and the question, with no error anywhere and a number that still looks like a
     * token count. `App\Services\Usage\UsageArithmetic` is the single site that spells the sum, and
     * it is spelled beside the query that uses it.
     */
    case ChatTokensOutput = 'chat.tokens.output';

    /**
     * Bytes added to object storage by one accepted upload or pasted body.
     *
     * The quantity is `source_items.byte_size` for the item the write produced. It is recorded at
     * INTAKE, after the six-step gate has admitted the file and inside the transaction that creates
     * the rows — never from the object store's own accounting, which is eventually consistent and
     * cannot be read inside a transaction anyway (`postgresql-patterns`: no HTTP between BEGIN and
     * COMMIT).
     */
    case StorageBytesAdded = 'storage.bytes.added';

    /**
     * Bytes returned to the organization's storage budget when a source's objects are purged.
     *
     * A SEPARATE CASE RATHER THAN A NEGATIVE `storage.bytes.added`, and the reason is the CHECK on
     * `usage_events.quantity`: a ledger that admits negative quantities admits a row that silently
     * cancels somebody else's, and no constraint can tell that from a correction. Two positive
     * cases make "storage used" a subtraction written in ONE place — `QuotaMetric::StorageBytes` —
     * and make an over-refund visible as a negative total rather than as a plausible small number.
     *
     * NOTHING WRITES IT YET, and that is stated rather than left to be inferred: phase 2 of the
     * deletion path is `deletion-engineer`'s and the object purge is where the refund belongs. The
     * case exists now because the SUBTRACTION exists now — `QuotaMetric::StorageBytes` is defined as
     * added minus removed, and defining it as "added" today would be a number that silently stops
     * being storage-used the day the purge lands.
     */
    case StorageBytesRemoved = 'storage.bytes.removed';

    /**
     * Does a row of this type name the provider and model it was billed against?
     *
     * Generated into `usage_events_provider_attribution_paired`, so the enum and the constraint
     * cannot drift. Token events must carry both — a token count nobody can price is not a billing
     * record — and storage events must carry NEITHER, because object storage is ours and naming a
     * provider on one would attribute our own bytes to a vendor account.
     */
    public function attributedToProvider(): bool
    {
        return match ($this) {
            self::ChatTokensInput, self::ChatTokensOutput => true,
            self::StorageBytesAdded, self::StorageBytesRemoved => false,
        };
    }

    /**
     * The types whose rows carry a provider and a model, as strings for a CHECK constraint.
     *
     * @return list<string>
     */
    public static function providerAttributed(): array
    {
        return array_values(array_map(
            static fn (self $c): string => $c->value,
            array_filter(self::cases(), static fn (self $c): bool => $c->attributedToProvider()),
        ));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
