<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Embedding\EmbeddingCandidate;
use App\Services\Embedding\EmbeddingDesignation;
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
    /**
     * ── THE SECOND ARGUMENT IS REQUIRED, WITH NO DEFAULT, AND THAT IS THE POINT ────────────────
     *
     * `?EmbeddingDesignation $designated` is nullable because "this organization designated
     * nothing" is a legitimate and common state — but it takes NO default value, so a call site
     * cannot omit it and silently publish `designated: null` for an organization that really did
     * designate a pair. Null must mean "nothing is stored", never "the renderer did not have the
     * organization to hand", or the whole field is worse than useless: it would tell the
     * designation screen that a failing designation had been cleared.
     *
     * IT IS THE STORED PAIR, NOT THE RESOLVER'S OPINION OF IT. Pass
     * `EmbeddingDesignation::fromOrganization($organization)` — the two columns as they sit in
     * `organizations` — and pass it from the organization row the WRITE returned, never the bound
     * one, on any path that just changed the designation.
     */
    public function __construct(
        EmbeddingReadiness $resource,
        private readonly ?EmbeddingDesignation $designated,
    ) {
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
     *   designated     the STORED (connection, model) pair from `organizations`, whatever the
     *                  resolver then made of it — see below.
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
     * ── WHY `designated` EXISTS BESIDE `selected`, AND WHY IT IS NOT DERIVABLE FROM IT ─────────
     *
     * `selected` is null in TWO different situations that the designation screen has to tell
     * apart: this organization designated nothing and the resolve-by-rule found no embedder, and
     * this organization designated a pair that no longer resolves. They call for opposite copy —
     * "choose a connection" versus "the connection you chose is failing, and here is which one" —
     * and before this field the only place the stored pair appeared was INSIDE `explanation`, as
     * prose. The render-verbatim rule makes that string safe to show and useless to branch on: a
     * client cannot parse an id out of a sentence the data plane owns and may reword.
     *
     * So this is the structural half of the same fact, read straight off
     * `organizations.embedding_connection_id` / `embedding_model`. It is ADDITIVE — every field
     * that was here is still here, with the same meaning — so a client that never reads it is
     * unaffected.
     *
     * IT IS NOT `selected` UNDER ANOTHER NAME AND MUST NEVER BE TREATED AS ONE. A designation is
     * never substituted (EmbeddingDesignationService's docblock says why: silently embedding
     * through a different connection changes the vector space under a corpus nobody reindexed),
     * so on a ready verdict where a designation exists the two agree on `(connection_id, model)`
     * and `selected` additionally carries the `provider` the data plane resolved. On a blocked
     * verdict they disagree by construction: `designated` is populated and `selected` is null,
     * which is exactly the case this field was added to make renderable.
     *
     * NO `provider` KEY HERE, deliberately, and the asymmetry with EmbeddingCandidate is the
     * point: `organizations` stores a connection id and a model string and nothing else. Adding a
     * provider would mean joining `provider_connections` and publishing a value the designation
     * itself does not contain — a third source for a fact `selected` already reports when it
     * resolves.
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
            // The STORED pair, not the resolver's verdict on it. `EmbeddingDesignation::toArray()`
            // emits exactly `connection_id` and `model`, so there is no third place this shape can
            // drift from.
            'designated' => $this->designated?->toArray(),
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
     * rather than a promise made by this method: EmbeddingCandidate, EmbeddingRejection and
     * EmbeddingDesignation have nowhere to put a secret — the designation is two columns of
     * `organizations` and the credential lives on `provider_connections`, which this path never
     * reads — and `candidate()` below emits three named keys. The contract test
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

            // A COMPONENT OF ITS OWN AND NOT A REUSE OF EmbeddingCandidate, because it is a
            // genuinely different shape: a designation is the two columns `organizations` stores
            // and carries NO `provider`. Declaring it as a candidate with an optional provider
            // would be unpublishable anyway — every component here is closed
            // (`additionalProperties: false`) and total (declared ⊆ required), so there are no
            // optional fields to have.
            'EmbeddingDesignation' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'The (connection, model) pair this organization has STORED as its '
                    .'explicit embedding choice, read from `organizations.embedding_connection_id` '
                    .'and `organizations.embedding_model`. A PAIR and not a bare connection id: one '
                    .'connection legitimately carries several embedding rows, and '
                    .'`text-embedding-3-large` and `text-embedding-3-small` on one credential are '
                    .'two vector spaces.',
                'required' => ['connection_id', 'model'],
                'properties' => [
                    'connection_id' => [
                        'type' => 'string',
                        'description' => 'ULID of the designated provider connection. It may name a '
                            .'connection that no longer resolves — this is what was stored, not what '
                            .'the resolver made of it.',
                    ],
                    'model' => $candidate['properties']['model'],
                ],
            ],

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
                'required' => [
                    'ready', 'blocks_ingestion', 'selected', 'designated', 'eligible', 'rejected',
                    'explanation',
                ],
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
                    'designated' => [
                        'anyOf' => [
                            ['$ref' => '#/components/schemas/EmbeddingDesignation'],
                            ['type' => 'null'],
                        ],
                        'description' => 'The pair this organization has STORED as its explicit '
                            .'choice, or null when it has designated nothing. NOT derivable from '
                            .'`selected`: a null `selected` means either "nothing designated and '
                            .'nothing resolved by rule" or "the designation no longer resolves", '
                            .'and only this field tells them apart. The stored pair is also named '
                            .'inside `explanation`, but that is prose the data plane owns and may '
                            .'reword — render it, do not parse it. On a ready verdict with a '
                            .'designation the two agree on connection and model, and `selected` '
                            .'additionally carries the resolved `provider`.',
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
