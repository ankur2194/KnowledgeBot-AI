<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Organization;
use App\Services\Rerank\RerankDesignation;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Which connection reranks this organization's retrieval candidates, as data.
 *
 * ── THE CONSTRUCTOR TAKES THE DESIGNATION, NOT THE ORGANIZATION ────────────────────────────────
 *
 * `?RerankDesignation` is nullable because "this organization has chosen not to rerank" is a
 * legitimate and expected state — but the parameter takes NO default, so a call site cannot omit it
 * and silently publish `designated: null` for an organization that really did designate a pair.
 * Null must mean "nothing is stored", never "the renderer did not have the row to hand", or the
 * field is worse than useless: it would tell the configuration screen that reranking is off when it
 * is on.
 *
 * PASS `RerankDesignation::fromOrganization($organization)` — the two columns as they sit in
 * `organizations` — and pass it from the organization row the WRITE returned, never the bound one,
 * on any path that just changed the designation. The bound row still holds the pre-write columns,
 * so rendering off it would echo the operator's PREVIOUS choice back at them on the very response
 * that changed it.
 *
 * @property-read Organization $resource
 */
final class RerankConfigurationResource extends JsonResource implements ProvidesOpenApiSchema
{
    /**
     * The `$resource` is the organization only so JsonResource has a subject; nothing is read off
     * it. Every published value comes from `$designated`, which is the pair as persisted.
     */
    public function __construct(
        Organization $resource,
        private readonly ?RerankDesignation $designated,
    ) {
        parent::__construct($resource);
    }

    /**
     * TWO FIELDS, AND THE SECOND IS NOT REDUNDANT WITH THE FIRST.
     *
     *   designated                the STORED (connection, model) pair from `organizations`, or null.
     *   degrades_to_fused_order   what null MEANS, named for what the operator sees.
     *
     * ── WHY `degrades_to_fused_order` EARNS ITS PLACE THOUGH A CLIENT COULD COMPUTE IT ─────────
     *
     * It is `designated === null`, exactly as `EmbeddingReadinessResource::blocks_ingestion` is the
     * negation of `ready`, and it exists for the identical reason: "nothing is designated" and
     * "answers are being ranked by fusion order alone" are the same fact, and only one of the two
     * names is actionable on a screen. The consequence is the whole reason a null designation is a
     * supported mode here rather than a misconfiguration — `rerank_gate` returns
     * `MODEL_NOT_CONFIGURED` before any call goes out and `evidence.select_unranked` selects on
     * branch agreement instead.
     *
     * ── ITS TRUTH IS ASYMMETRIC, AND THE ASYMMETRY IS PUBLISHED RATHER THAN HIDDEN ────────────
     *
     * `true` is a CERTAINTY: with no designation there is no model for stage 11 to call, so it
     * cannot run, and the control plane knows that on its own.
     *
     * `false` is NOT a promise that reranking runs. It says only that a pair is stored. Whether the
     * data plane can actually use it depends on three things this service deliberately does not
     * evaluate — whether the vendor publishes a ranking route, whether the model row claims the
     * capability, and whether this platform can threshold the score scale that comes back — and
     * that verdict is reached at query time and reported as `rerank_skip_reason` on the retrieval
     * trace. Naming the field `reranking_enabled` would have made the false direction into a claim
     * the control plane cannot support, which is why it is not called that.
     *
     * ── WHAT IS NOT IN HERE ───────────────────────────────────────────────────────────────────
     *
     * No credential, no ciphertext, no key version, not even `last_four` — this resource answers a
     * configuration question and a masked key would be a value on a screen with no reason to render
     * one. No `provider` key either, and the omission is the same one `EmbeddingDesignation` makes
     * for the same reason: `organizations` stores a connection id and a model string and nothing
     * else, so publishing a provider would mean joining `provider_connections` and emitting a value
     * the designation itself does not contain. And no capability verdict, no skip reason, no score
     * scale — those are the data plane's and they are per-query, not per-configuration.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'designated' => $this->designated?->toArray(),
            'degrades_to_fused_order' => $this->designated === null,
        ];
    }

    /**
     * The published shape, as JSON Schema 2020-12. Kept directly beneath `toArray()` so the two are
     * read together; tests/Contract/OpenApiDocumentTest.php asserts they agree over a fixture matrix
     * covering both the designated and the undesignated case.
     *
     * NO CREDENTIAL FIELD IS REPRESENTABLE HERE, and that is a property of the object underneath
     * rather than a promise made by this method: `RerankDesignation` is two columns of
     * `organizations` and has nowhere to put a secret, and the credential lives on
     * `provider_connections`, which this path never reads.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            // A COMPONENT OF ITS OWN AND NOT A REUSE OF `EmbeddingDesignation`, even though the two
            // are field-for-field identical today. They are different facts about different columns
            // with different meanings for null — `EmbeddingDesignation: null` means "resolve by
            // rule", this one means "do not rerank" — and a shared component would publish one
            // description that is false for one of the two. A generated client sharing the type
            // would also make a field rename on one surface silently rename the other.
            'RerankDesignation' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'The (connection, model) pair this organization has STORED as its '
                    .'reranker, read from `organizations.rerank_connection_id` and '
                    .'`organizations.rerank_model`. A PAIR and not a bare connection id: one '
                    .'connection can carry several ranking models, and their scores are different '
                    .'distributions with different thresholds.',
                'required' => ['connection_id', 'model'],
                'properties' => [
                    'connection_id' => [
                        'type' => 'string',
                        'description' => 'ULID of the designated provider connection, whose '
                            .'credential pays for the rerank call. It may name a connection this '
                            .'platform cannot rerank with — this is what was stored, not a verdict '
                            .'on it.',
                    ],
                    'model' => [
                        'type' => 'string',
                        'description' => 'The official vendor model id of the ranking model, '
                            .'verbatim. It must be a row in this connection\'s catalogue; whether '
                            .'that row can actually rerank is decided by the data plane at query '
                            .'time.',
                    ],
                ],
            ],

            'RerankConfigurationResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'Which connection reranks this organization\'s retrieval '
                    .'candidates. Reranking is OPTIONAL: no designation is a supported mode in '
                    .'which stage 11 is skipped and candidates are served in fused order, unlike '
                    .'the embedding designation, whose absence blocks ingestion entirely.',
                'required' => ['designated', 'degrades_to_fused_order'],
                'properties' => [
                    'designated' => [
                        'anyOf' => [
                            ['$ref' => '#/components/schemas/RerankDesignation'],
                            ['type' => 'null'],
                        ],
                        'description' => 'The pair this organization has stored, or null when it '
                            .'has designated nothing. Null is a CHOICE here — there is no '
                            .'resolve-by-rule for reranking — so it means "do not rerank" and not '
                            .'"somebody else decides".',
                    ],
                    'degrades_to_fused_order' => [
                        'type' => 'boolean',
                        'description' => 'True exactly when `designated` is null, named for what '
                            .'the operator sees. ITS TRUTH IS ASYMMETRIC: true is a certainty '
                            .'(with no model there is nothing for the rerank stage to call), while '
                            .'false says only that a pair is stored — whether the data plane can '
                            .'use it is decided per query and reported as `rerank_skip_reason` on '
                            .'the retrieval trace.',
                    ],
                ],
            ],
        ];
    }
}
