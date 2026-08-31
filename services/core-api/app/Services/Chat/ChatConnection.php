<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\ProviderConnection;
use App\Models\ProviderModelEntry;

/**
 * One `provider_connections` row joined to the `provider_models` row it will be used with, in the
 * shape `ConfigSnapshot.connection` expects.
 *
 * ═══ THERE IS NO CREDENTIAL ON THIS OBJECT AND NONE MAY BE ADDED ════════════════════════════
 *
 * `app/contracts/internal/chat.py::ProviderConnection` says the same thing on the other side of the
 * wire and says why: this object is hashed into `configuration_version` and is persisted by every
 * path that stores a snapshot — the playground record, `retrieval_traces`, a queued job body. A key
 * inside it would land in columns no redaction fixture covers, and it would move the version on
 * every rotation, invalidating every cached answer and stopping every replayed job reproducing
 * byte-identically (ADR-011).
 *
 * The credential travels in the top-level `provider_credentials` map, keyed by `connectionId`, built
 * in exactly one place — `InternalAiClient::openChatStream()`.
 *
 * ═══ `caps` IS THE ROW, NOT A DERIVATION FROM THE MODEL NAME ════════════════════════════════
 *
 * `ModelCapabilities` arrives from Laravel and is `strict` + `extra="forbid"` on the far side, so a
 * key we mis-spell is a 422 rather than a silently defaulted flag. `context_window` is where stage
 * 13's packing budget comes from and exists nowhere else on this wire; guessing it is the
 * mid-sentence truncation defect as a configuration.
 *
 * `supported` is read off `provider_models.capability_flags` and is sorted here, because a set has
 * no order and an unordered array is a different byte string on every request — which would move
 * `configuration_version`, and with it every answer-cache key, for a configuration that did not
 * change.
 */
final readonly class ChatConnection
{
    /**
     * @param  list<string>  $supported  capability flags read from the `provider_models` row, never
     *                                   parsed from the model id
     */
    private function __construct(
        public string $connectionId,
        public string $provider,
        public string $model,
        public ?string $baseUrl,
        public array $supported,
        public int $contextWindow,
        public int $maxOutputTokens,
        public bool $fallbackOnRateLimit,
    ) {}

    /**
     * Build one from the two rows.
     *
     * BOTH ROWS ARE PASSED IN RATHER THAN RESOLVED HERE. This class opens no query, so it can never
     * be the place a tenant scope is forgotten: the caller reads both through org-scoped repository
     * methods and hands them over already narrowed.
     *
     * `$fallbackOnRateLimit` is §8.7's per-connection switch and is OFF by default on the far side,
     * so a rate limit stays visible instead of quietly moving spend. It is passed explicitly rather
     * than defaulted here for the same reason.
     */
    public static function from(
        ProviderConnection $connection,
        ProviderModelEntry $model,
        bool $fallbackOnRateLimit = false,
    ): self {
        $supported = $model->supportedCapabilities();
        sort($supported, SORT_STRING);

        return new self(
            connectionId: (string) $connection->id,
            provider: $connection->provider->value,
            model: (string) $model->model,
            // ALWAYS NULL TODAY, AND THAT IS A SCHEMA GAP RATHER THAN A DEFAULT.
            // `provider_connections` has no `base_url` column (2026_08_07_000300), so there is
            // nothing to read — and reading a column that does not exist is a
            // MissingAttributeException under `Model::shouldBeStrict()`, not a null. The far side
            // types the field `str | None` and it exists for the self-hosted NIM container and for
            // an OpenAI-compatible gateway, neither of which this control plane can express yet.
            // NULL and not '': an empty string there is a base URL of zero length rather than "the
            // vendor's own endpoint". Reported rather than invented.
            baseUrl: null,
            supported: $supported,
            contextWindow: (int) $model->context_window,
            maxOutputTokens: (int) $model->max_output_tokens,
            fallbackOnRateLimit: $fallbackOnRateLimit,
        );
    }

    /**
     * @return array{connection_id: string, provider: string, model: string, base_url: string|null, caps: array{supported: list<string>, context_window: int, max_output_tokens: int, on_unsupported: string}, fallback_on_rate_limit: bool}
     */
    public function toArray(): array
    {
        return [
            'connection_id' => $this->connectionId,
            'provider' => $this->provider,
            // The official vendor id, VERBATIM. It is half of the model identity the data plane
            // validates capabilities against; an alias we shortened validates against a model the
            // tenant did not configure.
            'model' => $this->model,
            'base_url' => $this->baseUrl,
            'caps' => [
                'supported' => $this->supported,
                'context_window' => $this->contextWindow,
                'max_output_tokens' => $this->maxOutputTokens,
                // `reject` raises before the first byte goes out; `warn` strips the option. There is
                // deliberately no third setting — the one people reach for is "drop it quietly",
                // which is how a bot configured for structured output returns prose for a month.
                'on_unsupported' => 'reject',
            ],
            'fallback_on_rate_limit' => $this->fallbackOnRateLimit,
        ];
    }
}
