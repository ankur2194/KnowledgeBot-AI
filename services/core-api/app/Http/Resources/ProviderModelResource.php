<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ProviderModelEntry;
use App\Support\Contracts\ProvidesOpenApiSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `provider_models` row on the wire.
 *
 * ── THE CAPABILITY FLAGS ARE PUBLISHED FLAT, NOT AS THE STORED ENVELOPE ───────────────────────
 *
 * The column holds `{"supported": ["embedding"]}` — an OBJECT with a `supported` key — because
 * `ModelCapabilities` on the data-plane side is a structure with room for more than one axis, and
 * `EloquentProviderConnectionRepository::attachModel()` has written that envelope since the first
 * connection was stored. A client has no use for the wrapper: it needs the list. So this resource
 * emits `supported` as a flat array of strings, read through
 * `ProviderModelEntry::supportedCapabilities()` rather than by indexing the cast array directly.
 *
 * THAT ACCESSOR IS THE POINT, NOT A CONVENIENCE. It returns `[]` for anything that is not a list of
 * strings, so a row somebody hand-edited into a bare list — or into a string, or into a nested
 * object — renders as "claims nothing" instead of leaking a shape the published schema does not
 * describe and a generated client cannot parse. The correct reading of a malformed flag set is
 * "claims nothing" rather than "claims everything", and this is where that reading is applied on
 * the read path.
 *
 * The request field is spelled `supported` too, on both the standalone create and the nested one
 * inside StoreProviderConnectionRequest — so a client that can read this row can write it back
 * without a translation table.
 *
 * ── PRICING IS PUBLISHED AS A STRING, AND THAT IS NOT AN OVERSIGHT ────────────────────────────
 *
 * `numeric(14, 6)` is an exact decimal and IEEE 754 double is not. Publishing `type: number` would
 * hand every JSON parser in every language a float, and `0.15` has no exact binary representation
 * — so a per-million price multiplied by a token count in the millions and summed over a month
 * drifts by an amount nobody can reproduce or explain to a tenant. A decimal STRING crosses the
 * wire losslessly and forces the consumer to choose a decimal type deliberately. The Eloquent cast
 * is `decimal:6`, so the value is already a six-digit string here and no formatting happens in
 * this class.
 *
 * NULL IS A REAL VALUE AND IS NOT "FREE". It means no price has been recorded, which is the state
 * of almost every real row; a report that rendered it as 0 would claim an unpriced catalog costs
 * nothing.
 *
 * ── WHAT IS NOT RENDERED ──────────────────────────────────────────────────────────────────────
 *
 * `organization_id` — the client asked for this row through a URL that already named the
 * organization, so echoing the ownership column adds nothing and puts a tenant identifier into
 * every cached response body. Same call ProviderConnectionResource makes.
 *
 * NOTHING FROM THE PARENT CONNECTION beyond its id — no label, no provider, and above all no
 * `last_four`. A catalog listing is not a place to re-render a credential's masked form: it would
 * put the same string in two components for two different reasons, and the day one of them stops
 * being masked the other looks fine.
 *
 * @property-read ProviderModelEntry $resource
 */
final class ProviderModelResource extends JsonResource implements ProvidesOpenApiSchema
{
    public function __construct(ProviderModelEntry $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $model = $this->resource;

        return [
            'id' => $model->id,
            'connection_id' => $model->provider_connection_id,
            'model' => $model->model,
            'display_name' => $model->display_name,
            // Through the accessor, never `capability_flags['supported']` — see the class docblock.
            'supported' => $model->supportedCapabilities(),
            'context_window' => $model->context_window,
            'max_output_tokens' => $model->max_output_tokens,
            'enabled' => $model->enabled,
            'input_price_per_million' => $model->input_price_per_million,
            'output_price_per_million' => $model->output_price_per_million,
            'price_currency' => $model->price_currency,
            'created_at' => $model->created_at?->toIso8601String(),
        ];
    }

    /**
     * The published shape.
     *
     * `supported` IS PUBLISHED AS AN OPEN ARRAY OF STRINGS AND NOT AS AN ENUM, and that is the
     * same call StoreProviderConnectionRequest makes on the write side, deliberately. The
     * capability vocabulary is the `Capability` enum in
     * services/ai-service/app/providers/contract.py — the data plane's, not ours — and this side
     * validates the flags as free strings precisely so it is not a second copy of a list that
     * drifts. Publishing a closed enum here would re-create that copy inside the generated client,
     * where it would fail a typecheck on the day the data plane adds a member and nothing in this
     * repository changed.
     *
     * `enabled` is published, and it is the field a bot-configuration surface reads before offering
     * a model at all. `context_window` and `max_output_tokens` are real integers rather than
     * members of the flags object because they are compared, not read whole — the same reason they
     * are columns and not jsonb.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function openApiSchemas(): array
    {
        return [
            'ProviderModelResource' => [
                'type' => 'object',
                'additionalProperties' => false,
                'description' => 'One model in a provider connection\'s catalog: its official '
                    .'identifier, the capabilities the operator declared for it, its limits, and '
                    .'its list price. Carries nothing from the credential on the parent '
                    .'connection — not the masked form, not the vendor.',
                'required' => [
                    'id', 'connection_id', 'model', 'display_name', 'supported', 'context_window',
                    'max_output_tokens', 'enabled', 'input_price_per_million',
                    'output_price_per_million', 'price_currency', 'created_at',
                ],
                'properties' => [
                    'id' => [
                        'type' => 'string',
                        'description' => 'ULID of the catalog row. NOT the model identifier — that '
                            .'is `model`, and it is the vendor\'s.',
                    ],
                    'connection_id' => [
                        'type' => 'string',
                        'description' => 'ULID of the provider connection this row belongs to. '
                            .'Echoed so a flattened client-side list of models across several '
                            .'connections stays attributable without a second lookup.',
                    ],
                    'model' => [
                        'type' => 'string',
                        'description' => 'The official model identifier, exactly as the vendor '
                            .'publishes it. IMMUTABLE: it is half of the vector-space identity for '
                            .'everything already embedded through this row, so there is no edit '
                            .'path for it — a mistyped id is deleted and re-created.',
                    ],
                    'display_name' => [
                        'type' => 'string',
                        'description' => 'Operator-supplied name. Tenant-controlled text: escape it '
                            .'on render.',
                    ],
                    'supported' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'The capability flags this row CLAIMS, as the operator '
                            .'declared them. Deliberately an open string array and not an enum: '
                            .'the vocabulary belongs to the data plane, and a closed copy here '
                            .'would break a generated client the day that vocabulary grows. A '
                            .'claim is not a guarantee — whether a flag is honoured is the AND of '
                            .'this list and a sourced vendor fact, and an incoherent row is '
                            .'refused by the data plane with the reason named. An empty array '
                            .'means the row claims nothing, which is also what a malformed stored '
                            .'value renders as.',
                    ],
                    'context_window' => [
                        'type' => 'integer',
                        'description' => 'Total context window in tokens, entered from the '
                            .'vendor\'s documentation. 0 means "not recorded".',
                    ],
                    'max_output_tokens' => [
                        'type' => 'integer',
                        'description' => 'Maximum configured output in tokens. 0 means "not '
                            .'recorded"; it is not a limit of zero.',
                    ],
                    'enabled' => [
                        'type' => 'boolean',
                        'description' => 'Whether this row may be offered and used. A disabled row '
                            .'stays in the catalog and stays listed — an operator asking "why is '
                            .'this model missing from the dropdown" has to be able to find it.',
                    ],
                    'input_price_per_million' => [
                        'type' => ['string', 'null'],
                        'description' => 'List price per ONE MILLION input tokens, as an exact '
                            .'decimal STRING — never a JSON number, because a per-million price '
                            .'summed over a month of usage as an IEEE 754 double drifts by an '
                            .'amount nobody can reproduce. Null means no price has been recorded, '
                            .'which is NOT the same as free. Used only for estimated reporting; it '
                            .'authorizes nothing and gates nothing.',
                    ],
                    'output_price_per_million' => [
                        'type' => ['string', 'null'],
                        'description' => 'List price per ONE MILLION output tokens, same '
                            .'representation and same caveats as `input_price_per_million`.',
                    ],
                    'price_currency' => [
                        'type' => ['string', 'null'],
                        'description' => 'ISO 4217-shaped currency code, three upper-case letters. '
                            .'Non-null whenever either price is non-null — the database CHECK '
                            .'`provider_models_price_needs_currency` enforces that direction — and '
                            .'permitted on its own, which is the state of a row whose operator '
                            .'recorded the billing currency before the prices. The shape is '
                            .'enforced; membership of the real ISO list is not, because that list '
                            .'changes.',
                    ],
                    'created_at' => [
                        'type' => ['string', 'null'],
                        'format' => 'date-time',
                        'description' => 'ISO 8601 with offset. Null only for a record whose '
                            .'timestamp was never set.',
                    ],
                ],
            ],
        ];
    }
}
