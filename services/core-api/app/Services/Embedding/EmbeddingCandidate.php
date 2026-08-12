<?php

declare(strict_types=1);

namespace App\Services\Embedding;

/**
 * One (connection, provider_models row) pair offered to the resolution rule.
 *
 * Mirrors `EmbeddingConnection` in services/ai-service/app/providers/embedding_selection.py, which
 * is `strict=True` and `extra="forbid"` — so toArray() emits exactly four keys and every one of
 * them is a string or a nested map, never a coerced int and never a Carbon.
 *
 * NO CREDENTIAL FIELD, AND THERE NEVER WILL BE ONE. The data-plane model refuses at import time
 * any field whose name looks like a secret, precisely so the well-meaning edit that attaches the
 * decrypted key "so the caller does not have to look it up twice" is a startup failure rather than
 * a value in a log. This class holds the same line on this side: selection answers WHICH
 * connection, and the decrypted key is attached later, by InternalAiClient, as an entry in the
 * top-level `provider_credentials` map on the request that actually embeds — keyed by
 * `connection_id`, which is the identity $connectionId below already carries.
 *
 * THE MAP IS PLURAL, AND IT USED TO BE SINGULAR HERE. Finding F12 / #27, ruled 2026-08-12 as
 * `docs/22` § G7: one chat turn can need up to three keys — chat, embedding and rerank — and a
 * wire field named for one of them cannot carry three. Nothing in this class changes as a result,
 * which was the argument for the keyed map: it emits an identity the control plane resolves, so
 * it is indifferent to how many credentials the request that follows ends up carrying.
 */
final readonly class EmbeddingCandidate
{
    /**
     * @param  list<string>  $supported  capability flags read from the row, never parsed from $model
     */
    public function __construct(
        public string $connectionId,
        public string $provider,
        public string $model,
        public array $supported,
        public int $contextWindow,
        public int $maxOutputTokens,
    ) {}

    /**
     * @return array{connection_id: string, provider: string, model: string, caps: array{supported: list<string>, context_window: int, max_output_tokens: int, on_unsupported: string}}
     */
    public function toArray(): array
    {
        return [
            'connection_id' => $this->connectionId,
            'provider' => $this->provider,
            // The official vendor id, verbatim. It is half of the EmbeddingSpace identity and
            // therefore half of the Qdrant collection name; an alias we shortened is a different
            // space that silently finds nothing.
            'model' => $this->model,
            'caps' => [
                'supported' => $this->supported,
                'context_window' => $this->contextWindow,
                'max_output_tokens' => $this->maxOutputTokens,
                // `reject` raises before the first byte goes out. `warn` strips the option, which
                // on an embedding row would mean silently embedding a truncated chunk — the exact
                // recall failure bge-m3-embeddings warns about, now invisible on the network.
                'on_unsupported' => 'reject',
            ],
        ];
    }
}
