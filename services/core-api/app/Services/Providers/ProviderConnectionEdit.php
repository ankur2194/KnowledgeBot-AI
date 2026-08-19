<?php

declare(strict_types=1);

namespace App\Services\Providers;

use App\Enums\ProviderConnectionStatus;

/**
 * The validated input to a connection EDIT, as a type rather than an array.
 *
 * ── THERE IS NO `$credential` MEMBER, AND THAT IS THE POINT OF THE CLASS ────────────────────────
 *
 * The edit endpoint may never accept a credential. Expressing that as a missing rule in
 * UpdateProviderConnectionRequest would leave it one careless `+ $this->only('credential')` away
 * from being true again; expressing it as a TYPE means the service that performs the edit has
 * nowhere to put one. `ProviderConnectionService::update()` takes this object and never sees a
 * plaintext key, so it cannot reach the vault, and the repository method it calls writes only
 * `label` and `status`.
 *
 * Replacing a key is a different verb, a different route, a different DTO
 * (ProviderCredentialRotation), and a §18.3 re-authentication. Merging the two would mean a
 * relabel and a credential replacement shared one audit operation, one permission and one
 * re-authentication policy — and the weaker of each pair would win.
 *
 * ── NULL MEANS "NOT SUPPLIED", NOT "SET TO NULL" ───────────────────────────────────────────────
 *
 * Both columns are NOT NULL in the schema, so neither can be cleared and the ambiguity that
 * usually makes a nullable-partial DTO a bad idea does not arise. The FormRequest refuses a body
 * that supplies neither, so a `ProviderConnectionEdit` with both members null is unconstructible
 * from a request.
 *
 * `organization_id` is deliberately not a member here either. It is never validated, never posted
 * and never carried in a DTO; it comes from the authenticated context at the call site
 * (laravel-rbac-policies NN5).
 */
final readonly class ProviderConnectionEdit
{
    public function __construct(
        public ?string $label,
        public ?ProviderConnectionStatus $status,
    ) {}

    public function isEmpty(): bool
    {
        return $this->label === null && $this->status === null;
    }
}
