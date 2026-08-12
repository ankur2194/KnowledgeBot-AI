<?php

declare(strict_types=1);

namespace App\Services\Embedding;

/**
 * One candidate the resolution rule refused, with the reason it gave.
 *
 * Carries connection ids, provider names, model ids and matrix sources only — never a credential,
 * never tenant content. That is a property of the data-plane `Rejection` model and this class
 * preserves it by construction: it has nowhere to put anything else.
 */
final readonly class EmbeddingRejection
{
    public function __construct(
        public string $connectionId,
        public string $provider,
        public string $model,
        /** One of EmbeddingIneligibility: vendor_has_no_endpoint | row_lacks_embedding_flag | row_incoherent. */
        public string $reason,
        public string $detail,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            connectionId: self::str($payload, 'connection_id'),
            provider: self::str($payload, 'provider'),
            model: self::str($payload, 'model'),
            reason: self::str($payload, 'reason'),
            detail: self::str($payload, 'detail'),
        );
    }

    /**
     * @return array{connection_id: string, provider: string, model: string, reason: string, detail: string}
     */
    public function toArray(): array
    {
        return [
            'connection_id' => $this->connectionId,
            'provider' => $this->provider,
            'model' => $this->model,
            'reason' => $this->reason,
            'detail' => $this->detail,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function str(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
