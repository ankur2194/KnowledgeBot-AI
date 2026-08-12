<?php

declare(strict_types=1);

namespace App\Services\Providers;

/**
 * One `provider_models` row being registered under a connection.
 *
 * `$supported` is the capability flag list AS THE OPERATOR DECLARED IT, and nothing here draws a
 * conclusion from it. Whether the flag is honoured is the AND of this row and a sourced vendor
 * fact in services/ai-service/app/providers/capabilities.py — so a row that claims `embedding` on
 * a vendor with no embedding endpoint is refused there, by name, with the matrix cell quoted.
 * Inferring capability from `$model` (the "it has text-embedding in the name" shortcut) is the one
 * thing this class must never grow.
 */
final readonly class NewProviderModel
{
    /**
     * @param  list<string>  $supported
     */
    public function __construct(
        public string $model,
        public string $displayName,
        public array $supported,
        public int $contextWindow,
        public int $maxOutputTokens,
    ) {}
}
