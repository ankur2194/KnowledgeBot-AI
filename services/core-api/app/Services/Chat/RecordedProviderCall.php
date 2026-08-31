<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Enums\ProviderCallStatus;

/**
 * One `provider_calls` row, built from ONE `provider.usage` frame.
 *
 * ═══ ONE ROW PER ATTEMPT, AND THAT IS WHY THE FRAME IS CONSUMED AT ALL ══════════════════════
 *
 * `ChatResult` on the data plane deliberately cannot say which connection produced an answer, so a
 * consumer reading only the terminal event cannot tell a fallback answer from a primary one — the
 * cost of falling back would be invisible. `FallbackRouter.stream` therefore emits one
 * `ProviderAttempt` per attempt and the endpoint turns each into a `provider.usage` frame. One turn
 * that fell back once is TWO of these, with different `connectionId` and `model`.
 *
 * An attempt that made no request produces no frame and therefore no row: a zero-token, zero-cost
 * row for a call that never happened puts a phantom line into billing reconciliation and makes the
 * fallback ladder look cheaper than it is.
 *
 * ═══ THE TOKEN CONVERSION, AND IT IS THE EXPENSIVE MISTAKE IN THIS WHOLE FILE ═══════════════
 *
 * `input_tokens` MEANS TWO DIFFERENT THINGS ON THE TWO PLANES.
 *
 *   DATA PLANE — `providers.contract.Usage.input_tokens` is DISJOINT: uncached input only. Cache
 *   reads and cache writes are separate fields, and the total is the derived
 *   `Usage.total_input_tokens`. The `provider.usage` frame carries the disjoint value under
 *   `input_tokens`, with `cache_read_tokens` and `cache_write_tokens` beside it.
 *
 *   THIS PLANE — `provider_calls.input_tokens` is the NORMALIZED TOTAL, cache INCLUDED, and the two
 *   cache columns are a BREAKDOWN of it. `provider_calls_cache_within_input` enforces exactly that:
 *   `coalesce(cache_read, 0) + coalesce(cache_write, 0) <= input_tokens`.
 *
 * So the conversion is `input_tokens := frame.input_tokens + cache_read + cache_write`, and it is
 * performed HERE, once, with this paragraph beside it. Copying `input_tokens -> input_tokens`
 * type-checks, satisfies the CHECK constraint (a disjoint value is always ≥ the sum only when the
 * cache fields are zero — and when they are not, the constraint fires, which is the ONE case that is
 * loud), and under-reports every cached token on every caching vendor. On Anthropic that is a
 * 200k-token cached document reported as the 50-token question.
 *
 * `UsageArithmetic` owns the READ side of the same rule — "input is `input_tokens` ALONE, because it
 * is already the total" — and the two are the same sentence read in opposite directions. If either
 * moves without the other, every bill is wrong by a percentage that varies per vendor.
 *
 * ═══ `reasoning_tokens` IS BILLED INSIDE `output_tokens` ON THE WIRE AND IS A SIBLING HERE ══
 *
 * The frame says so explicitly: reported separately for attribution, and adding it to the output
 * count double-bills every reasoning turn. `provider_calls` stores the two as DISJOINT columns and
 * `UsageArithmetic::totalOutputTokens()` sums them, so the conversion is a SUBTRACTION: the row's
 * `output_tokens` is the frame's output MINUS its reasoning, and the reasoning goes in its own
 * column. Copying both across unchanged would double-count every reasoning token in every aggregate.
 */
final readonly class RecordedProviderCall
{
    /**
     * @param  int  $ordinal  1-based across the whole turn, so a gap in the sequence is a dropped
     *                        record rather than a fallback nobody noticed.
     */
    public function __construct(
        public int $ordinal,
        public string $connectionId,
        public string $provider,
        public string $model,
        public ProviderCallStatus $status,
        public ?string $errorClass,
        public ?string $providerRequestId,
        public int $inputTokens,
        public int $cacheReadTokens,
        public int $cacheWriteTokens,
        public int $outputTokens,
        public int $reasoningTokens,
        public ?int $firstTokenLatencyMs,
        public ?int $totalLatencyMs,
        public bool $isFallback,
        public ?string $fellBackFrom,
        public ?string $fallbackTrigger,
    ) {}

    /**
     * Build one from a `provider.usage` frame's decoded payload. THE CONVERSION LIVES HERE.
     *
     * @param  array<string, mixed>  $frame
     * @param  array<string, mixed>|null  $fallback  the `provider.fallback` frame with the same
     *                                               ordinal, when one was emitted. It is a SEPARATE
     *                                               frame on the wire and is paired here rather than
     *                                               being read off the usage frame, which carries no
     *                                               fallback fields at all.
     */
    public static function fromFrame(array $frame, ?array $fallback = null): self
    {
        $cacheRead = self::int($frame, 'cache_read_tokens');
        $cacheWrite = self::int($frame, 'cache_write_tokens');

        // THE ROLLUP IS NOT USED. The frame also carries `cached_tokens`, which is the data plane's
        // own `cache_read + cache_write` sum. Reading it instead of the two parts would be one field
        // rather than two and would lose the split the CHECK constraint and the cost model both
        // need — Anthropic bills a cache WRITE at a premium and a cache READ at a discount, so a
        // consumer holding only the sum cannot price either.
        $rawOutput = self::int($frame, 'output_tokens');
        $reasoning = self::int($frame, 'reasoning_tokens');

        $outcome = is_string($frame['outcome'] ?? null) ? $frame['outcome'] : '';
        $errorClass = is_string($frame['error_class'] ?? null) && $frame['error_class'] !== ''
            ? $frame['error_class']
            : null;

        $status = self::status($outcome, $errorClass);

        // `provider_calls_error_class_paired_with_status` REFUSES a `failed` row with no class and a
        // `succeeded` row with one. Both halves are normalised here rather than left to the
        // database, because the write happens inside the finalizer's ONE transaction: a constraint
        // violation there loses the message row, the citations and the trace along with it — the
        // whole transcript of an answer the reader already saw, for a vendor accounting quirk.
        if ($status === ProviderCallStatus::Failed && $errorClass === null) {
            // The honest placeholder: it is what this plane already says when the far side answered
            // in a shape it cannot read, and it is in the taxonomy so the CHECK admits it.
            $errorClass = 'internal_dependency';
        }

        if ($status === ProviderCallStatus::Succeeded) {
            $errorClass = null;
        }

        return new self(
            ordinal: max(1, self::int($frame, 'ordinal')),
            connectionId: (string) ($frame['connection_id'] ?? ''),
            provider: (string) ($frame['provider'] ?? ''),
            model: (string) ($frame['model'] ?? ''),
            status: $status,
            errorClass: $errorClass,
            providerRequestId: is_string($frame['provider_request_id'] ?? null) && $frame['provider_request_id'] !== ''
                ? $frame['provider_request_id']
                : null,
            // ══ THE CONVERSION. Disjoint on the wire, total in the column. ══════════════════
            inputTokens: self::int($frame, 'input_tokens') + $cacheRead + $cacheWrite,
            cacheReadTokens: $cacheRead,
            cacheWriteTokens: $cacheWrite,
            // ══ AND THE OTHER HALF. Reasoning is billed INSIDE output on the wire and is its own
            //    column here, so it is subtracted out rather than copied twice. `max(0, …)` because
            //    a vendor that reports reasoning larger than output is reporting something we cannot
            //    model, and a negative token count violates
            //    `provider_calls_tokens_nonnegative` — a constraint failure inside the finalizer's
            //    transaction would lose the whole transcript for a vendor accounting quirk.
            outputTokens: max(0, $rawOutput - $reasoning),
            reasoningTokens: $reasoning,
            firstTokenLatencyMs: isset($frame['first_token_ms']) && is_int($frame['first_token_ms'])
                ? $frame['first_token_ms']
                : null,
            totalLatencyMs: self::int($frame, 'latency_ms'),
            isFallback: $fallback !== null,
            fellBackFrom: is_string($fallback['from_connection_id'] ?? null) ? $fallback['from_connection_id'] : null,
            fallbackTrigger: is_string($fallback['trigger'] ?? null) && $fallback['trigger'] !== ''
                ? $fallback['trigger']
                : null,
        );
    }

    /**
     * `provider_calls.fallback_metadata` — `{}` on a primary attempt.
     *
     * AN OBJECT AND NEVER AN ARRAY. `json_encode([])` is `[]`, a JSON array, and one array-shaped row
     * makes every `fallback_metadata->>'…'` query silently return nothing for it;
     * `provider_calls_fallback_metadata_is_object` refuses it in the database and
     * `App\Support\Casts\JsonObjectCast` is the writer-side half.
     *
     * @return array<string, mixed>
     */
    public function fallbackMetadata(): array
    {
        if (! $this->isFallback) {
            return [];
        }

        return [
            'ordinal' => $this->ordinal,
            'fell_back_from' => $this->fellBackFrom,
            // The ErrorClass that sent us here, as the data plane assigned it. NEVER an HTTP status:
            // a status is a rendering and one class renders differently per surface.
            'trigger' => $this->fallbackTrigger,
        ];
    }

    /**
     * The frame's `outcome` mapped onto the column's closed vocabulary.
     *
     * AN OUTCOME THIS METHOD CANNOT READ BECOMES `cancelled`, NOT `succeeded` OR `failed`, and the
     * choice is forced by `provider_calls_error_class_paired_with_status`: that constraint refuses a
     * `failed` row with no class and a `succeeded` row with one, and deliberately leaves `cancelled`
     * free in both directions — a cancelled call legitimately carries `user_cancellation` and
     * equally legitimately carries nothing. So it is the only status an unrecognised outcome can
     * take that is guaranteed not to fail the write, whichever way the class went.
     *
     * A SUCCESS CARRYING A CLASS IS RECORDED AS A FAILURE rather than dropped: the tokens were spent
     * either way, and a row that cannot be written is a bill that cannot be reconciled.
     */
    private static function status(string $outcome, ?string $errorClass): ProviderCallStatus
    {
        return match ($outcome) {
            'success', 'succeeded' => $errorClass === null
                ? ProviderCallStatus::Succeeded
                : ProviderCallStatus::Failed,
            'error', 'failed' => ProviderCallStatus::Failed,
            default => ProviderCallStatus::Cancelled,
        };
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private static function int(array $frame, string $key): int
    {
        $value = $frame[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }
}
