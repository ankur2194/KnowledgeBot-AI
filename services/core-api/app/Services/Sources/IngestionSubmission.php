<?php

declare(strict_types=1);

namespace App\Services\Sources;

use App\Models\KnowledgeSource;
use App\Models\SourceItem;

/**
 * The body of one `ingestion.submit`, assembled SERVER-SIDE from rows this organization owns.
 *
 * ── NOTHING HERE IS A PARAMETER THE CALLER CHOSE ──────────────────────────────────────────────
 *
 * Every field below is read off `knowledge_sources` and `source_items` after the FormRequest, the
 * policy and the state machine have all passed. A relay that forwarded request input would be an
 * unvalidated proxy into the data plane, and the values that matter most here are precisely the
 * ones a client must never supply: `storage_key` is a path, `mime` is the sniffed type
 * (`kb-security-baseline` refuses the client's `Content-Type`), and `content_hash` is what the
 * published version will be checkable against.
 *
 * ── NO CREDENTIAL, AND NO CONTENT ─────────────────────────────────────────────────────────────
 *
 * The body carries the STORAGE KEY, never the bytes. The data plane reads the object itself — that
 * is what `error_class: storage` exists for on its side — and putting document text in a submission
 * body would put it in a Valkey job payload, in `failed_jobs`, and in every span that instruments
 * request bodies. `provider_credentials` is likewise absent: `app/ingestion/tasks.py` resolves and
 * decrypts at execution time through the provider layer's own accessor, and nothing under
 * `app/ingestion/` accepts a credential parameter.
 *
 * ── THE FINGERPRINT IS THE IDEMPOTENCY KEY'S INPUT, AND IT IS STABLE ─────────────────────────
 *
 * `kb-internal-api-contracts` fingerprints `ingestion.submit` on the source id, the version id, the
 * content hash and the three configuration versions. Laravel can supply the first three and cannot
 * supply the configuration versions or the version id — see `VersionIdentity` for why — so what it
 * signs instead is the source, every item's `(id, canonical_key, content_hash)` in a deterministic
 * order, and the force nonce. THE CONSEQUENCE IS NAMED RATHER THAN GLOSSED: a submission whose
 * content is unchanged and whose PARSER CONFIGURATION changed produces the same key on this side,
 * so the deduplication that must catch it is the data plane's `ingest_key`, which does carry the
 * configuration versions. That is the correct division — the config versions are only knowable
 * there — and it is why the two keys are not the same value.
 *
 * The force nonce is what makes an explicit reprocess reach a worker at all: without it a
 * resubmission of unchanged content is byte-identical to the previous one, and a replay of a stored
 * response is exactly what the admin did not ask for.
 */
final readonly class IngestionSubmission
{
    /**
     * @param  list<SourceItem>  $items
     */
    public function __construct(
        public string $jobId,
        public KnowledgeSource $source,
        public array $items,
        public ?string $forceNonce,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'job_id' => $this->jobId,
            'source' => [
                'id' => $this->source->id,
                'type' => $this->source->type->value,
                // The admin's label. It reaches the data plane so a warning or a failed-job record
                // can name the source a human recognises; it is tenant-authored free text and is
                // never interpolated into a path, a query or a prompt on either side.
                'name' => $this->source->name,
                'origin_url' => $this->source->origin_url,
                'tags' => $this->source->tags,
                'effective_at' => $this->source->effective_at?->toIso8601String(),
                'expires_at' => $this->source->expires_at?->toIso8601String(),
            ],
            'items' => array_map(
                static fn (SourceItem $item): array => [
                    'id' => $item->id,
                    'canonical_key' => $item->canonical_key,
                    'url' => $item->url,
                    'display_name' => $item->display_name,
                    'storage_key' => $item->storage_key,
                    'content_hash' => $item->content_hash,
                    'mime' => $item->mime,
                    'byte_size' => $item->byte_size,
                    // WHAT IS ALREADY LIVE, so the worker knows which version its publication will
                    // supersede without querying Laravel's tables — which ADR-012 forbids it doing.
                    'current_version_id' => $item->current_version_id,
                ],
                $this->items,
            ),
            'force_nonce' => $this->forceNonce,
        ];
    }

    /**
     * The stable fingerprint the idempotency key is derived from. See the class docblock.
     */
    public function fingerprint(): string
    {
        $parts = [$this->source->id];

        foreach ($this->items as $item) {
            // ORDERED BY THE CALLER (`itemsFor()` orders by `id`, which is a ULID under COLLATE "C"
            // and therefore creation order). A set serialized in planner order would produce a
            // different key for the same submission on two reads of an unchanged table, and a
            // retry that generates a new key is a duplicate job rather than a replay.
            $parts[] = $item->id;
            $parts[] = $item->canonical_key;
            $parts[] = (string) $item->content_hash;
        }

        $parts[] = (string) $this->forceNonce;

        // The separator is the unit separator rather than a printable byte: `|` is legal inside a
        // canonical key (a URL query happily contains one), and with a separator that can appear
        // inside a component, ("a|b", "c") and ("a", "b|c") hash identically — two different
        // submissions deduping against each other, the second silently never processed. The data
        // plane's own `ingest_key` guards the same hazard by REFUSING a component containing its
        // separator; here the components include a tenant-supplied URL, so a byte no URL can carry
        // is the answer instead.
        return implode("\x1f", $parts);
    }
}
