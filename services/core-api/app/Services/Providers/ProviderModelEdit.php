<?php

declare(strict_types=1);

namespace App\Services\Providers;

/**
 * The validated input to a catalog-row EDIT, as a type rather than an array.
 *
 * ── THERE IS NO `$model` MEMBER, AND THAT IS THE POINT OF THE CLASS ────────────────────────────
 *
 * The official model identifier is NOT editable, and the omission is the same construction — and
 * the same argument — as `provider` being absent from ProviderConnectionEdit. `(provider, model)`
 * IS the vector space (ADR-034, and the create migration for
 * `organizations.embedding_connection_id` says so at length): every chunk already embedded through
 * this row was embedded under that pair, and the Qdrant collection name is derived from it.
 * Renaming the row would silently re-point a live corpus at a different space while every query
 * kept returning plausible neighbours — cosine distance is defined between any two vectors of
 * equal width, so there would be no error, no metric movement, and worse answers.
 *
 * Worse, the organization's embedding designation stores `embedding_model` as a bare STRING with
 * no foreign key to this table — nothing in the database would follow a rename, so an edit here
 * would leave the designation naming a row that no longer exists and the next upload would fail
 * with a resolution error instead of the operator seeing why at the moment they caused it.
 *
 * A model identifier that was typed wrong is DELETED and re-created. That path is guarded (a
 * designated row cannot be deleted), which is exactly the guard a rename would have bypassed.
 *
 * `organization_id` and `provider_connection_id` are not members either: the first comes from the
 * authenticated context, the second from the route's scoped binding. Neither is ever validated,
 * posted, or carried in a DTO (laravel-rbac-policies NN5).
 *
 * ── EVERY MEMBER IS TOTAL, BECAUSE THE ENDPOINT IS A PUT ───────────────────────────────────────
 *
 * There is no "not supplied" state here and no nullable-partial ambiguity. The endpoint replaces
 * the row's mutable attributes wholesale, so this object always describes the complete desired
 * state — see UpdateProviderModelRequest for why a PATCH was rejected. `$pricing` is total in the
 * same sense: `ModelPricing::none()` is an explicit "no price recorded", not an absent field.
 */
final readonly class ProviderModelEdit
{
    /**
     * @param  list<string>  $supported
     */
    public function __construct(
        public string $displayName,
        public array $supported,
        public int $contextWindow,
        public int $maxOutputTokens,
        public bool $enabled,
        public ModelPricing $pricing,
    ) {}
}
