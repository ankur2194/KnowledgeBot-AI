<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Embedding\EmbeddingCandidate;
use App\Services\Embedding\EmbeddingReadiness;
use App\Services\Embedding\EmbeddingRejection;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The blocking banner on the ingestion surface, as data.
 *
 * @property-read EmbeddingReadiness $resource
 */
final class EmbeddingReadinessResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(EmbeddingReadiness $resource)
    {
        parent::__construct($resource);
    }

    /**
     * WHAT IS IN HERE AND WHY EACH FIELD EARNS ITS PLACE.
     *
     *   ready          the only field a client should branch on. `blocks_ingestion` is its
     *                  negation and is named for what the operator sees, because "not ready" and
     *                  "cannot upload anything at all" are the same fact and one of the two names
     *                  is actionable.
     *   selected       the resolved (connection, provider, model). The provider and the model are
     *                  half the EmbeddingSpace identity, so an operator debugging a recall problem
     *                  needs to see exactly which pair their corpus is indexed under.
     *   eligible       every candidate that passed. More than one is NOT an error by itself —
     *                  several connections to the same (provider, model) name one space and differ
     *                  only in which credential pays.
     *   rejected       present even on success, because an operator asking "why is my Anthropic
     *                  key not being used" needs the answer whether or not some other connection
     *                  saved the day.
     *   explanation    the data plane's own words, verbatim. NOT paraphrased here: the upload path
     *                  raises this same string, so the banner and the error say the same thing, and
     *                  a paraphrase is a second copy that drifts.
     *
     * WHAT IS NOT IN HERE. No credential, no ciphertext, no key version, not even `last_four` —
     * this resource answers a configuration question and a masked key would be a value on a screen
     * that had no reason to render one. No internal host, no matrix file path beyond what the data
     * plane's own `detail` already quotes, and no exception text.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $readiness = $this->resource;

        return [
            'ready' => $readiness->isReady(),
            'blocks_ingestion' => ! $readiness->isReady(),
            'selected' => $readiness->selected === null
                ? null
                : $this->candidate($readiness->selected),
            'eligible' => array_map($this->candidate(...), $readiness->eligible),
            'rejected' => array_map(
                static fn (EmbeddingRejection $r): array => $r->toArray(),
                $readiness->rejected,
            ),
            'explanation' => $readiness->explanation,
        ];
    }

    /**
     * The published shape, as JSON Schema 2020-12. Kept directly beneath `toArray()` so the two are
     * read together; tests/Contract/OpenApiDocumentTest.php asserts they agree, over a fixture
     * matrix that covers the ready verdict, the blocked verdict, and the empty collections.
     *
     * NO CREDENTIAL FIELD IS REPRESENTABLE HERE, and that is a property of the objects underneath
     * rather than a promise made by this method: EmbeddingCandidate and EmbeddingRejection have
     * nowhere to put a secret, and `candidate()` below emits three named keys. The contract test
     * additionally scans every published property name and every emitted value for credential
     * shapes, so the invariant is asserted rather than asserted-in-prose.
     *
     * `provider` and `reason` are DELIBERATELY NOT ENUMS. Both values are parsed out of the data
     * plane's readiness response by EmbeddingReadiness::fromResponse(), which validates neither —
     * publishing a closed set here would be a claim this code does not enforce, and a generated
     * TypeScript union that a real payload could violate. The known members are named in the
     * descriptions instead, with the owning source. Contrast ProviderConnectionResource::provider,
     * which comes off a PHP backed enum and IS published closed.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        $candidate = [
            'type' => 'object',
            'additionalProperties' => false,
            'description' => 'One (connection, provider, model) triple that can embed. The provider '
                .'and the model together are half the EmbeddingSpace identity, and therefore half '
                .'the Qdrant collection name — two connections differing only in credential name '
                .'the same space.',
            'required' => ['connection_id', 'provider', 'model'],
            'properties' => [
                'connection_id' => [
                    'type' => 'string',
                    'description' => 'ULID of the provider connection whose credential pays for the '
                        .'embedding call.',
                ],
                'provider' => [
                    'type' => 'string',
                    'description' => 'Vendor key, echoed from the data plane. One of `openai`, '
                        .'`anthropic`, `deepseek`, `nvidia_nim`, `openrouter` — not published as a '
                        .'closed enum because this field is relayed, not validated, on this side.',
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'The official vendor model id, verbatim. An alias or a '
                        .'shortened form names a different vector space that silently finds nothing.',
                ],
            ],
        ];

        return [
            'EmbeddingCandidate' => $candidate,

            'EmbeddingRejection' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One candidate the resolution rule refused, with the reason it '
                    .'gave. Present even on a ready verdict, because "why is my Anthropic key not '
                    .'being used" is a question an operator asks while everything works.',
                'required' => ['connection_id', 'provider', 'model', 'reason', 'detail'],
                'properties' => [
                    'connection_id' => $candidate['properties']['connection_id'],
                    'provider' => $candidate['properties']['provider'],
                    'model' => $candidate['properties']['model'],
                    'reason' => [
                        'type' => 'string',
                        'description' => 'The EmbeddingIneligibility member the data plane assigned: '
                            .'`vendor_has_no_endpoint`, `row_lacks_embedding_flag` or '
                            .'`row_incoherent`. Owned by '
                            .'services/ai-service/app/providers/embedding_selection.py and relayed '
                            .'verbatim, so it is not published as a closed enum.',
                    ],
                    'detail' => [
                        'type' => 'string',
                        'description' => 'The data plane\'s own sentence, quoting the capability '
                            .'matrix cell. Carries connection ids, provider names, model ids and '
                            .'matrix sources only — never a credential, never tenant content.',
                    ],
                ],
            ],

            'EmbeddingReadinessResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'Whether this organization can ingest a document, and why not. '
                    .'Computed by the data plane on every read and never cached: a stale `ready` '
                    .'would send an ingest run to pay for parsing and OCR before failing.',
                'required' => ['ready', 'blocks_ingestion', 'selected', 'eligible', 'rejected', 'explanation'],
                'properties' => [
                    'ready' => [
                        'type' => 'boolean',
                        'description' => 'The only field to branch on. True exactly when `selected` '
                            .'is non-null.',
                    ],
                    'blocks_ingestion' => [
                        'type' => 'boolean',
                        'description' => 'The negation of `ready`, named for what the operator sees. '
                            .'Not redundant: "not ready" and "cannot upload anything at all" are the '
                            .'same fact, and only one of the two names is actionable.',
                    ],
                    'selected' => [
                        'anyOf' => [
                            ['$ref' => '#/components/schemas/EmbeddingCandidate'],
                            ['type' => 'null'],
                        ],
                        'description' => 'The resolved candidate, or null. Exactly one of `selected` '
                            .'and a non-empty `explanation` is populated — a verdict carrying both '
                            .'would let a client draw the blocking banner AND proceed to embed.',
                    ],
                    'eligible' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/EmbeddingCandidate'],
                        'description' => 'Every candidate that passed. More than one is not an error '
                            .'by itself — several connections to the same (provider, model) name one '
                            .'space and differ only in which credential pays.',
                    ],
                    'rejected' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/EmbeddingRejection'],
                        'description' => 'Every candidate that was refused, with its reason.',
                    ],
                    'explanation' => [
                        'type' => 'string',
                        'description' => 'The data plane\'s own words, verbatim, when nothing '
                            .'resolved; the empty string when `ready` is true. The upload path '
                            .'raises this same string, so the banner and the error say the same '
                            .'thing — render it, never paraphrase it.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{connection_id: string, provider: string, model: string}
     */
    private function candidate(EmbeddingCandidate $candidate): array
    {
        // Capability flags and window sizes are deliberately not echoed. They went out on the
        // request; echoing them back would make this response look like a source of truth for the
        // provider_models row, which it is not.
        return [
            'connection_id' => $candidate->connectionId,
            'provider' => $candidate->provider,
            'model' => $candidate->model,
        ];
    }
}
