<?php

declare(strict_types=1);

namespace App\Support\Kb;

/**
 * `emb/v1:provider:model:dNNNN:digest` — the embedding-space identity, parsed.
 *
 * ═══ WHY THIS PLANE PARSES A STRING THE OTHER PLANE COMPOSES ════════════════════════════════
 *
 * A chat turn must embed its QUESTION in the same space its PASSAGES were embedded in, and the only
 * authority on which space that was is the `source_versions` row (`bge-m3-embeddings`, ADR-035). So
 * the chat snapshot's `embedding_connection` cannot be read off whatever the organization designates
 * today: a designation that moved after those versions were indexed would embed the question with a
 * model the corpus was never indexed under, and the failure is zero candidates or plausible wrong
 * ones — never an error.
 *
 * `space_from_identity()` in `services/ai-service/app/retrieval/collection.py` is the authoritative
 * inverse and this is the second transcription of it. That is a real cost and it is taken
 * deliberately: the alternative is a round trip per chat turn inside the 1.5 s retrieval budget, to
 * ask the data plane a question about a string this plane already holds.
 * `tests/Contract/EmbeddingIdentityParityTest.php` reads that module and asserts the two agree on
 * the scheme prefix and on the field positions, so the copy is watched rather than trusted.
 *
 * ═══ REFUSING IS THE ONLY SAFE ANSWER TO AN UNPARSEABLE VALUE ═══════════════════════════════
 *
 * The collection name is derived from this string, so a default here would name a REAL collection
 * and search someone else's vector space. `parse()` returns null and every caller refuses.
 */
final readonly class EmbeddingSpaceIdentity
{
    /**
     * The scheme prefix, byte-for-byte `IDENTITY_SCHEME` in
     * `services/ai-service/app/retrieval/collection.py`. A bump there is a re-identification of
     * every vector, and this constant is what makes the bump visible on this side.
     */
    public const SCHEME = 'emb/v1';

    private function __construct(
        public string $provider,
        public string $model,
        public int $dimensions,
    ) {}

    /**
     * Parse one identity, or null when it is not one.
     *
     * THE SPLIT IS BY POSITION AND MATCHES THE FAR SIDE'S EXACTLY — `parts[1]` is the provider,
     * `parts[2]` the model, `parts[3]` the `dNNNN` width. The digest is deliberately NOT validated
     * beyond being present: it is ADR-035's canary digest, its length is that function's business,
     * and a stricter check here would reject a version the indexer itself wrote the moment the probe
     * changed shape.
     */
    public static function parse(string $identity): ?self
    {
        $parts = explode(':', $identity);

        if (count($parts) < 5 || $parts[0] !== self::SCHEME || ! str_starts_with($parts[3], 'd')) {
            return null;
        }

        $width = substr($parts[3], 1);

        // `ctype_digit` and not `(int)`: `(int) 'dabc'` is 0, which is a plausible width and a real
        // collection name. The width is part of the space identity and cannot be defaulted.
        if ($width === '' || ! ctype_digit($width)) {
            return null;
        }

        if ($parts[1] === '' || $parts[2] === '') {
            return null;
        }

        return new self($parts[1], $parts[2], (int) $width);
    }
}
