<?php

declare(strict_types=1);

namespace App\Services\Providers;

use InvalidArgumentException;

/**
 * What one catalog row costs, as a type.
 *
 * docs/02 §8.4 scopes this precisely: *"Pricing metadata used only for estimated reporting."* It
 * authorizes nothing, gates nothing, and is never consulted on a chat turn — so nothing downstream
 * has to handle its absence as an error.
 *
 * ── THE PRICES ARE STRINGS, AND THAT IS DELIBERATE ─────────────────────────────────────────────
 *
 * The column is `numeric(14, 6)` and the Eloquent cast is `decimal:6`, both of which are exact.
 * `float` is not: `0.15` has no binary representation, so a per-million price multiplied by a
 * token count in the millions and summed over a month drifts by an amount nobody can reproduce or
 * explain to a tenant. Carrying the value as a decimal STRING from the request all the way to the
 * column means no step in the chain rounds — PHP never sees it as a float, and PostgreSQL parses
 * the literal exactly.
 *
 * ── A PRICE WITHOUT A CURRENCY IS UNCONSTRUCTIBLE ──────────────────────────────────────────────
 *
 * The same rule the `provider_models_price_needs_currency` CHECK enforces, one layer up, expressed
 * so a service cannot assemble the invalid pair at all. `15.00` is not an amount: an organization
 * billed by a reseller in EUR and one billed in USD would both store `15.00`, a report would add
 * them, and the sum would be a number in no currency.
 *
 * THE GUARD IS UNREACHABLE FROM A REQUEST and is here anyway. StoreProviderModelRequest and
 * UpdateProviderModelRequest both carry `required_with` in the price -> currency direction, so a
 * body that reaches this constructor has already been refused with a 422 keyed on the field. The
 * throw is for the OTHER caller — a seeder, a console command, a future importer — for which no
 * FormRequest runs and where the failure would otherwise be a constraint name from PostgreSQL.
 *
 * A CURRENCY WITH NO PRICES IS PERMITTED, one-directionally, matching the CHECK: it is the state
 * of a row whose operator recorded the vendor's billing currency before looking the prices up, and
 * refusing it would make the form unfillable in the order a human fills it.
 */
final readonly class ModelPricing
{
    /**
     * @param  string|null  $inputPerMillion  decimal string, per million input tokens
     * @param  string|null  $outputPerMillion  decimal string, per million output tokens
     * @param  string|null  $currency  ISO 4217-shaped, three upper-case letters
     */
    public function __construct(
        public ?string $inputPerMillion = null,
        public ?string $outputPerMillion = null,
        public ?string $currency = null,
    ) {
        if ($currency === null && ($inputPerMillion !== null || $outputPerMillion !== null)) {
            throw new InvalidArgumentException(
                'A price without a currency is not an amount: two organizations billed in '
                .'different currencies would store the same number, and a spend report would add '
                .'them. Supply a currency, or supply no price.',
            );
        }
    }

    /**
     * No pricing recorded — which is NOT "free", and the distinction is load-bearing. A spend
     * estimate over an unpriced catalog must be able to say "unknown" rather than report zero.
     */
    public static function none(): self
    {
        return new self;
    }

    /**
     * The column values, ready to assign. One place composes them, so a future fourth pricing
     * column cannot land in the repository and be forgotten in the factory.
     *
     * @return array{input_price_per_million: string|null, output_price_per_million: string|null, price_currency: string|null}
     */
    public function toColumns(): array
    {
        return [
            'input_price_per_million' => $this->inputPerMillion,
            'output_price_per_million' => $this->outputPerMillion,
            'price_currency' => $this->currency,
        ];
    }
}
