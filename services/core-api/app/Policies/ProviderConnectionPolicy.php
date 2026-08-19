<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ProviderConnection;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Auto-discovered: App\Models\ProviderConnection -> App\Policies\ProviderConnectionPolicy. No
 * Gate::policy() call and no provider edit — the `Surface` OrgScopedPolicy needs is
 * constructor-injected by the container binding and set per request by `surface:admin`.
 *
 * Every ability here authorizes a row the caller ALREADY HOLDS, and the organization comes from
 * that row through OrgOwned. The complementary half — "may this user list connections at all",
 * which has no row yet — is `OrganizationPolicy::viewProviderConnections()`, because there is no
 * connection to take an organization from before the list is fetched. The same split the
 * invitation policies make, for the same reason.
 *
 * THESE ABILITIES ARE LAYER 3 OF SEVEN. A passing policy does not license an unscoped query: the
 * routes nest under `{organization}` with `->scopeBindings()`, so a foreign or unknown
 * `{providerConnection}` 404s at BINDING time — before this class is constructed and before the
 * row is in memory — and every repository method still takes `organization_id` as a required
 * positional argument. This policy refuses the one case those two cannot see: the row IS in this
 * organization and the caller's role is wrong.
 *
 * ── THE ONE PLACE THE ROLE SPLIT IS NOT UNIFORM ─────────────────────────────────────────────────
 *
 * `view` is `providers.view` and everything else is `providers.manage`, which is NOT the same as
 * "owner and admin only". §6.4 gives a Knowledge Manager `providers.view` and nothing else, on the
 * stated ground that an ingestion operator has to be able to see whether the organization can
 * embed at all — so a knowledge_manager reads this resource and is refused every write on it,
 * including the credential rotation. An analyst holds nothing in this catalog and is refused all
 * five. That asymmetry is asserted per action in tests/Security/ProviderConnectionAccessTest.php
 * rather than assumed from a uniform dataset, because a uniform one would go green against a
 * `view` that had silently been mapped to `providers.manage`.
 */
final class ProviderConnectionPolicy extends OrgScopedPolicy
{
    public function view(?User $user, ProviderConnection $connection): Response
    {
        return $this->permit($user, $connection, Permission::ProvidersView);
    }

    public function update(?User $user, ProviderConnection $connection): Response
    {
        return $this->permit($user, $connection, Permission::ProvidersManage);
    }

    public function delete(?User $user, ProviderConnection $connection): Response
    {
        return $this->permit($user, $connection, Permission::ProvidersManage);
    }

    /**
     * Replace the stored secret.
     *
     * Behind `providers.manage` like every other write, and NOT behind a permission of its own.
     * §6.4 already excludes provider credentials from the Knowledge Manager wholesale, so a
     * `providers.rotate` case would be granted to exactly the same two roles as `providers.manage`
     * — a permission nobody grants differently is a permission that fails silently in both
     * directions (App\Enums\Permission's own docblock). What makes rotation stricter than a
     * relabel is not the permission: it is the §18.3 re-authentication the endpoint performs,
     * which is a check this class has no argument position for.
     */
    public function rotateCredential(?User $user, ProviderConnection $connection): Response
    {
        return $this->permit($user, $connection, Permission::ProvidersManage);
    }

    /**
     * Register a `provider_models` row UNDER this connection.
     *
     * The record is the PARENT, deliberately. A model row does not exist yet, so there is nothing
     * to take an organization from — and the parent connection is the correct scope anyway, since
     * the composite foreign key `(organization_id, provider_connection_id)` is what the write is
     * about to be checked against. Authorizing against the organization instead would let this
     * ability pass for a caller who is a member of the right organization but is addressing a
     * connection in it that they were never shown.
     */
    public function createModel(?User $user, ProviderConnection $connection): Response
    {
        return $this->permit($user, $connection, Permission::ProvidersManage);
    }
}
