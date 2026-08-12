<?php

declare(strict_types=1);

namespace App\Services\Embedding;

use RuntimeException;

/**
 * The verdict, as the data plane computed it.
 *
 * THIS OBJECT IS PARSED, NEVER DERIVED. Every field comes from
 * `embedding_readiness()` in services/ai-service/app/providers/embedding_selection.py, and there
 * is deliberately no method on this class that could answer "is there at least one embedder"
 * without having asked. A looser local check is the whole failure C1 is about: it passes an
 * ambiguous configuration at save time and fails it at the first upload, after the parse and the
 * OCR have been paid for. It is also worse than that — the rule that matters is not "is one
 * eligible" but "do the eligible ones agree on (provider, model)", and a control plane cannot even
 * ask the second question, because the vendor half of the capability matrix lives on the other
 * side of the seam with a source per cell.
 *
 * The invariant the data-plane model enforces holds here too and is re-asserted on construction:
 * exactly one of `selected` and `explanation` is populated. A readiness that carried both would
 * let a caller draw the blocking banner AND proceed to embed.
 */
final readonly class EmbeddingReadiness
{
    /**
     * @param  list<EmbeddingCandidate>  $eligible
     * @param  list<EmbeddingRejection>  $rejected
     */
    public function __construct(
        public ?EmbeddingCandidate $selected,
        public array $eligible,
        public array $rejected,
        public string $explanation,
    ) {
        if (($selected === null) === ($explanation === '')) {
            throw new RuntimeException(
                'An embedding readiness is either selected (with no explanation) or unselected '
                .'(with one). A verdict carrying both lets a caller render the banner and embed.',
            );
        }
    }

    public function isReady(): bool
    {
        return $this->selected !== null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromResponse(array $payload): self
    {
        $selected = $payload['selected'] ?? null;
        $eligible = $payload['eligible'] ?? [];
        $rejected = $payload['rejected'] ?? [];
        $explanation = $payload['explanation'] ?? '';

        return new self(
            selected: is_array($selected) ? self::candidate($selected) : null,
            eligible: is_array($eligible) ? array_values(array_map(
                self::candidate(...),
                array_filter($eligible, 'is_array'),
            )) : [],
            rejected: is_array($rejected) ? array_values(array_map(
                EmbeddingRejection::fromArray(...),
                array_filter($rejected, 'is_array'),
            )) : [],
            explanation: is_string($explanation) ? $explanation : '',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function candidate(array $payload): EmbeddingCandidate
    {
        $caps = $payload['caps'] ?? [];
        $caps = is_array($caps) ? $caps : [];

        $supported = $caps['supported'] ?? [];
        $supported = is_array($supported) ? array_values(array_filter($supported, 'is_string')) : [];

        return new EmbeddingCandidate(
            connectionId: self::str($payload, 'connection_id'),
            provider: self::str($payload, 'provider'),
            model: self::str($payload, 'model'),
            supported: $supported,
            contextWindow: self::int($caps, 'context_window'),
            maxOutputTokens: self::int($caps, 'max_output_tokens'),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function str(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function int(array $payload, string $key): int
    {
        $value = $payload[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }
}
