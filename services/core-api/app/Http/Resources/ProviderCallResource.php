<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\ProviderCallStatus;
use App\Models\ProviderCall;
use App\Support\Contracts\ProvidesOpenApiSchema;
use App\Support\Kb\ErrorTaxonomy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One attempt against a provider: what it cost, how long it took, and — when it was a fallback —
 * what it was reached after.
 *
 * ═══ THIS SHAPE EXISTS ONLY ON THE ADMIN SURFACE AND MUST STAY THERE ═════════════════════════
 *
 * `RuntimeMessageResource` names `provider_call_id` as one of four columns it deliberately omits:
 * *"Cost data stops at Laravel — the same rule that keeps `provider.usage` off the wire."* That
 * rule is about END USERS, and this resource is the other side of it: an operator who is billed for
 * these calls has to be able to see them, which is exactly why the admin transcript publishes the
 * settling call id and the runtime one does not.
 *
 * ═══ NO CREDENTIAL AND NO PROVIDER MESSAGE REACHES THIS BODY ════════════════════════════════
 *
 * `provider_connection_id` and `model_id` are REFERENCES to rows this organization owns; neither is
 * a key and neither is derivable into one. There is no field here for a provider's own error text,
 * and that is deliberate rather than an omission — `kb-error-taxonomy` forbids rendering a provider
 * message to a caller, and `error_class` is the normalized answer. The vendor's `provider_request_id`
 * IS published because it is the only field that lets somebody else reproduce our failure in a
 * support ticket, and it identifies a request rather than an account.
 *
 * ═══ NULL IS A REAL VALUE ON EVERY TOKEN AND LATENCY FIELD ══════════════════════════════════
 *
 * It means "the provider told us nothing" — a stream that died before its usage frame — and it is a
 * different fact from zero. A client that coalesces the two turns an outage into a free request.
 */
final class ProviderCallResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(ProviderCall $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $call = $this->resource;

        return [
            'id' => $call->id,
            'provider_connection_id' => $call->provider_connection_id,
            'model_id' => $call->model_id,
            'provider_request_id' => $call->provider_request_id,
            'status' => $call->status->value,
            'error_class' => $call->error_class,
            'input_tokens' => $call->input_tokens,
            'cache_read_tokens' => $call->cache_read_tokens,
            'cache_write_tokens' => $call->cache_write_tokens,
            'output_tokens' => $call->output_tokens,
            'reasoning_tokens' => $call->reasoning_tokens,
            // A FIXED-SCALE DECIMAL AS A STRING, never a float. `numeric(16, 8)` does not survive a
            // round trip through an IEEE-754 double, and a cost report that is wrong in the eighth
            // decimal place per call is wrong in the second by the end of a month.
            'estimated_cost' => $call->estimated_cost,
            'estimated_cost_currency' => $call->estimated_cost_currency,
            'first_token_latency_ms' => $call->first_token_latency_ms,
            'total_latency_ms' => $call->total_latency_ms,
            'fallback_metadata' => $call->fallback_metadata,
            'created_at' => $call->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'ProviderCallResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One attempt against a provider for one turn. A turn normally has '
                    .'exactly one; it has more when a fallback was reached, and the extra rows are '
                    .'the record of what was tried first and why it was not enough.',
                'required' => [
                    'id', 'provider_connection_id', 'model_id', 'provider_request_id', 'status',
                    'error_class', 'input_tokens', 'cache_read_tokens', 'cache_write_tokens',
                    'output_tokens', 'reasoning_tokens', 'estimated_cost',
                    'estimated_cost_currency', 'first_token_latency_ms', 'total_latency_ms',
                    'fallback_metadata', 'created_at',
                ],
                'properties' => [
                    'id' => ['type' => 'string', 'description' => 'ULID of the attempt.'],
                    'provider_connection_id' => [
                        'type' => 'string',
                        'description' => 'Which of this organization\'s provider connections was '
                            .'billed. A REFERENCE and never a credential: the key behind the '
                            .'connection never leaves the vault, and no endpoint in this API can '
                            .'read it back.',
                    ],
                    'model_id' => [
                        'type' => 'string',
                        'description' => 'Which `provider_models` row answered. A reference to a row '
                            .'this organization owns; the composite foreign key makes naming '
                            .'another tenant\'s model a database error rather than a mis-attributed '
                            .'cost.',
                    ],
                    'provider_request_id' => [
                        'type' => ['string', 'null'],
                        'description' => 'The vendor\'s own identifier for the request, echoed from '
                            .'their response. The one field here that lets somebody else reproduce '
                            .'a failure — it is what a support ticket to the provider quotes. Null '
                            .'when the vendor sent none, which a failed connection usually does.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ProviderCallStatus::values(),
                        'description' => '`cancelled` is a NORMAL outcome — a closed tab — and is '
                            .'not a failure: the tokens generated before it were still billed, and '
                            .'counting it as a provider error makes the error-rate figure unusable.',
                    ],
                    'error_class' => [
                        'type' => ['string', 'null'],
                        'enum' => [...array_keys(ErrorTaxonomy::RETRYABLE), null],
                        'description' => 'The normalized class from the platform\'s taxonomy, never '
                            .'the provider\'s own message. Always present on a `failed` attempt and '
                            .'always absent on a `succeeded` one; `cancelled` and `pending` are '
                            .'deliberately free, because a caller going away is not a fault to '
                            .'attribute.',
                    ],
                    'input_tokens' => [
                        'type' => ['integer', 'null'],
                        'minimum' => 0,
                        'description' => 'TOTAL input tokens, cache included — the two cache '
                            .'columns are a breakdown of this number and never an addition to it. '
                            .'Null means the provider told us nothing, which is different from '
                            .'zero.',
                    ],
                    'cache_read_tokens' => [
                        'type' => ['integer', 'null'],
                        'minimum' => 0,
                        'description' => 'Part of `input_tokens` served from the provider\'s prompt '
                            .'cache.',
                    ],
                    'cache_write_tokens' => [
                        'type' => ['integer', 'null'],
                        'minimum' => 0,
                        'description' => 'Part of `input_tokens` written into the provider\'s prompt '
                            .'cache.',
                    ],
                    'output_tokens' => ['type' => ['integer', 'null'], 'minimum' => 0],
                    'reasoning_tokens' => [
                        'type' => ['integer', 'null'],
                        'minimum' => 0,
                        'description' => 'Tokens spent on hidden reasoning, where the provider '
                            .'reports them separately.',
                    ],
                    'estimated_cost' => [
                        'type' => ['string', 'null'],
                        'description' => 'A FIXED-SCALE DECIMAL AS A STRING — `numeric(16, 8)` does '
                            .'not survive a round trip through a double, and an error in the eighth '
                            .'decimal per call is an error in the second by the end of a month. '
                            .'Never parse it into a float to add it up.',
                    ],
                    'estimated_cost_currency' => [
                        'type' => ['string', 'null'],
                        'description' => 'ISO 4217. Present whenever `estimated_cost` is: a cost '
                            .'with no currency is a number nobody may add to another number.',
                    ],
                    'first_token_latency_ms' => [
                        'type' => ['integer', 'null'],
                        'minimum' => 0,
                        'description' => 'Time to the first streamed token — the latency a user '
                            .'feels. Null on a non-streaming call and on one that never produced a '
                            .'token.',
                    ],
                    'total_latency_ms' => [
                        'type' => ['integer', 'null'],
                        'minimum' => 0,
                        'description' => 'Wall clock for the whole attempt. Never less than '
                            .'`first_token_latency_ms` when both are present.',
                    ],
                    'fallback_metadata' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                        'description' => 'EMPTY `{}` ON A PRIMARY ATTEMPT. On a fallback it carries '
                            .'the attempt ordinal, the call and model that were tried first, and '
                            .'the error class that made them ineligible — which is the whole reason '
                            .'the column exists, because a fallback row with an empty object is '
                            .'indistinguishable from a first attempt.',
                    ],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
        ];
    }
}
