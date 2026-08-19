<?php

declare(strict_types=1);

namespace App\Services\Providers;

/**
 * One `provider_models` row being registered on its OWN endpoint, as a type rather than an array.
 *
 * ── WHY THIS IS NOT `NewProviderModel` ─────────────────────────────────────────────────────────
 *
 * `NewProviderModel` is the nested-create shape: it is one element of
 * `StoreProviderConnectionRequest`'s `models` array, and it carries no `enabled` and no pricing
 * because that path has no rule for either — every row it creates is enabled and unpriced. This
 * one is the standalone shape, where the operator is editing the catalog rather than bootstrapping
 * a connection, so both are theirs to state.
 *
 * MERGING THE TWO WAS CONSIDERED AND REJECTED. Adding `enabled` and pricing to `NewProviderModel`
 * would make three fields optional-with-a-default on the nested path, and a default that only one
 * of two callers relies on is a default that changes meaning the day the other caller starts
 * passing it. Two types, each total for its own path, is one fact per class.
 *
 * `organization_id` is deliberately NOT a member, and neither is `provider_connection_id`. The
 * organization comes from the authenticated context at the call site and the connection comes from
 * the ROUTE — a scoped binding through `$organization->providerConnections()`, so a foreign or
 * unknown id 404s before this object exists. Carrying either in a validated body is the
 * over-posting shape laravel-rbac-policies NN5 names: an authorization bug with a 200 response.
 *
 * `$supported` is the capability flag list AS THE OPERATOR DECLARED IT, and nothing here draws a
 * conclusion from it — same rule, same reason, as NewProviderModel. Whether a flag is honoured is
 * the AND of this row and a sourced vendor fact in
 * services/ai-service/app/providers/capabilities.py, which refuses an incoherent row by name with
 * the matrix cell quoted. Inferring capability from `$model` — the "it has text-embedding in the
 * name" shortcut — is the one thing this class must never grow.
 */
final readonly class NewProviderModelEntry
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
        public bool $enabled,
        public ModelPricing $pricing,
    ) {}
}
