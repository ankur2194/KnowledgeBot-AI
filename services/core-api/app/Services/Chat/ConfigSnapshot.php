<?php

declare(strict_types=1);

namespace App\Services\Chat;

/**
 * The whole configuration a chat turn is executed against — `docs/06` §11.2's snapshot, as the
 * body's `config` object.
 *
 * ═══ WHY THE WHOLE THING CROSSES THE WIRE ═══════════════════════════════════════════════════
 *
 * FastAPI never queries Laravel's tables and never caches configuration across requests. The
 * snapshot IS the input, which is what makes a replayed job reproduce byte-identically and what lets
 * the playground run a temporary override without mutating anything (`kb-internal-api-contracts`).
 *
 * ═══ THE VERSION IS A HASH OF THIS OBJECT, AND THE CHICKEN-AND-EGG IS RESOLVED HERE ═════════
 *
 * `ConfigSnapshot.config_version` on the far side is a field OF the snapshot that MIRRORS
 * `X-KB-Config-Version`, which is a hash OF the snapshot. So the hash is taken over the snapshot
 * WITHOUT that field — `hashableArray()` — and the number is then written into `toArray()`. Both the
 * header and the body therefore carry the same value, and the value describes the configuration
 * rather than describing itself.
 *
 * `InternalAiClient::snapshotVersion()` owns the derivation (a 56-bit slice of `sha256`, 56 bits and
 * not 64 so the decimal always fits the ≤18-digit bound `app/api/deps.py` parses it against). This
 * class asks for it rather than restating it, which is why the version arrives as a constructor
 * argument on `withVersion()` instead of being computed here.
 *
 * ═══ NO CREDENTIAL IS IN THIS OBJECT AND NONE MAY EVER BE ADDED ═════════════════════════════
 *
 * ADR-011, and it is the single rule this file exists to hold. Two things follow from a key inside
 * the snapshot, both silent:
 *
 *   1. It is hashed into `configuration_version`, so it becomes part of every cache key and of
 *      replay identity — rotate a key and every cached answer misses and every replayed job stops
 *      reproducing.
 *   2. The snapshot is DESIGNED to be persisted and replayed, so every path that stores one — the
 *      playground request record, `retrieval_traces`, a queued job body — stores the key with it,
 *      into columns no redaction fixture covers and no operator greps.
 *
 * The credential map is a SIBLING of this object on the wire, keyed by `connection_id`, built in
 * exactly one place. `connectionIdsInPlay()` below is what tells that one place which entries the
 * turn needs — and it is deliberately a method on the snapshot rather than a list the caller
 * assembles, so a fourth surface added to this class cannot be forgotten at the decryption site.
 *
 * ═══ KEY ORDER IS PART OF THE IDENTITY ══════════════════════════════════════════════════════
 *
 * `toArray()` writes its keys in a fixed literal order and every nested `toArray()` does the same.
 * That is not tidiness: the version is a hash of the serialized bytes, so a key order that varied
 * between two identical configurations would produce two versions, two cache keys and two answer
 * caches for one bot. Never build this array by merging a dynamic map into it.
 */
final readonly class ConfigSnapshot
{
    /**
     * @param  list<ChatConnection>  $fallbackConnections  §8.7's ordered chain, after `connection`.
     *                                                     Empty means no fallback, which is a real
     *                                                     state and not a missing one.
     * @param  list<string>  $allowedVersionIds  non-negotiable 2's fourth Qdrant filter term.
     *                                           Never empty — see `RetrievalScope`.
     * @param  int  $version  0 until `withVersion()` runs. `toArray()` refuses to render a 0, so a
     *                        snapshot that skipped the derivation cannot reach the wire: the far
     *                        side bounds the field `ge=1`, and 0 would be a 422 for a body that is
     *                        otherwise correct.
     */
    public function __construct(
        public int $retrievalConfigurationVersion,
        public ChatConnection $connection,
        public ChatConnection $embeddingConnection,
        public ?ChatConnection $rerankConnection,
        public array $fallbackConnections,
        public RetrievalSettings $retrieval,
        public array $allowedVersionIds,
        public string $embeddingModelVersion,
        public string $botInstructions,
        public int $maxOutputTokens,
        public ?float $temperature,
        public string $reasoningEffort,
        public int $version = 0,
    ) {}

    /**
     * The same snapshot carrying its derived version. Returns a new instance because the object is
     * readonly and because a snapshot whose version was assigned in place could be mutated after it
     * had been hashed.
     */
    public function withVersion(int $version): self
    {
        return new self(
            $this->retrievalConfigurationVersion,
            $this->connection,
            $this->embeddingConnection,
            $this->rerankConnection,
            $this->fallbackConnections,
            $this->retrieval,
            $this->allowedVersionIds,
            $this->embeddingModelVersion,
            $this->botInstructions,
            $this->maxOutputTokens,
            $this->temperature,
            $this->reasoningEffort,
            max(1, $version),
        );
    }

    /**
     * Every `connection_id` this turn needs a credential for, deduplicated, in a stable order.
     *
     * THE SINGLE-VENDOR CASE PRODUCES ONE ENTRY AND THAT IS THE COMMON CASE. ADR-031 explicitly
     * permits the embedding connection to be a different one from the chat connection, and the
     * rerank designation adds a possible third; `array_unique` collapses them when they agree.
     *
     * THE FALLBACK CHAIN IS INCLUDED, and it has to be: a fallback that fires with no entry in the
     * map is refused by `credential_for()` on the far side MID-TURN, after retrieval has run and the
     * primary has already failed — which turns a recoverable brownout into a failed answer.
     *
     * @return list<string>
     */
    public function connectionIdsInPlay(): array
    {
        $ids = [$this->connection->connectionId, $this->embeddingConnection->connectionId];

        if ($this->rerankConnection !== null) {
            $ids[] = $this->rerankConnection->connectionId;
        }

        foreach ($this->fallbackConnections as $fallback) {
            $ids[] = $fallback->connectionId;
        }

        return array_values(array_unique($ids));
    }

    /**
     * The snapshot MINUS its own version — the bytes `configuration_version` is a hash of.
     *
     * Identical to `toArray()` in every other respect, and written as one array with the version
     * key added by `toArray()` rather than as two literals, so the two cannot drift: a field added
     * to one and not the other would either be excluded from the identity (a configuration change
     * that does not move the version) or present in the hash and absent from the wire (a version
     * nobody can reproduce).
     *
     * @return array<string, mixed>
     */
    public function hashableArray(): array
    {
        return [
            'retrieval_configuration_version' => $this->retrievalConfigurationVersion,
            'connection' => $this->connection->toArray(),
            'embedding_connection' => $this->embeddingConnection->toArray(),
            'rerank_connection' => $this->rerankConnection?->toArray(),
            'fallback_connections' => array_map(
                static fn (ChatConnection $c): array => $c->toArray(),
                $this->fallbackConnections,
            ),
            'retrieval' => $this->retrieval->toArray(),
            'allowed_version_ids' => $this->allowedVersionIds,
            'embedding_model_version' => $this->embeddingModelVersion,
            'bot_instructions' => $this->botInstructions,
            'max_output_tokens' => $this->maxOutputTokens,
            'temperature' => $this->temperature,
            'reasoning_effort' => $this->reasoningEffort,
            // `true` always. The field exists on the far side so a non-streaming caller — the
            // evaluation harness — can say so; this relay is streaming by construction, and a
            // literal here is the honest answer rather than a setting nobody can reach.
            'stream' => true,
        ];
    }

    /**
     * The `config` object as it goes on the wire.
     *
     * `config_version` FIRST, because the far side's model declares it first and a reader diffing a
     * captured body against `app/contracts/internal/chat.py` should see the same order.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['config_version' => max(1, $this->version)] + $this->hashableArray();
    }
}
