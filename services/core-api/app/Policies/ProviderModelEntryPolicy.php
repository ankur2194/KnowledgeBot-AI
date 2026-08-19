<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ProviderModelEntry;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Auto-discovered: App\Models\ProviderModelEntry -> App\Policies\ProviderModelEntryPolicy. No
 * Gate::policy() call and no provider edit — the `Surface` OrgScopedPolicy needs is
 * constructor-injected by the container binding and set per request by `surface:admin`.
 *
 * THE CLASS NAME CARRIES THE MODEL'S `Entry` SUFFIX, and it has to: policy discovery derives
 * `App\Policies\{class_basename($model)}Policy`, so a `ProviderModelPolicy` would never be found
 * and every Gate call would fall through to "no policy" — which denies, silently, in a way that
 * looks like a role problem. The model is called `ProviderModelEntry` because
 * `arch()->preset()->laravel()` refuses a `Model` suffix inside App\Models; the table is still
 * `provider_models`.
 *
 * Every ability here authorizes a row the caller ALREADY HOLDS, and the organization comes from
 * that row through OrgOwned. The complementary halves — "may this user list the catalog under this
 * connection" and "may they add a row to it", neither of which has a catalog row yet — are
 * `ProviderConnectionPolicy::view()` and `ProviderConnectionPolicy::createModel()`, because the
 * parent connection is the correct scope for both and is the record the write is about to be
 * checked against by the composite foreign key. The same split ProviderConnectionPolicy makes
 * against OrganizationPolicy, one level down.
 *
 * THESE ABILITIES ARE LAYER 3 OF SEVEN. A passing policy does not license an unscoped query: the
 * routes nest `{model}` under `{providerConnection}` under `{organization}` with
 * `->scopeBindings()`, so a foreign or unknown id at EITHER level 404s at BINDING time — before
 * this class is constructed and before the row is in memory — and every repository method still
 * takes `organization_id` AND `provider_connection_id` as required positional arguments. This
 * policy refuses the one case those cannot see: the row IS under this organization's connection
 * and the caller's role is wrong.
 *
 * ── THE ROLE SPLIT IS THE PARENT'S, AND IT IS NOT "OWNER AND ADMIN ONLY" ───────────────────────
 *
 * `view` is `providers.view` and every write is `providers.manage`. §6.4 gives a Knowledge Manager
 * `providers.view` and nothing else — `OrgRole::grants()` reads
 * `self::KnowledgeManager => $permission === Permission::ProvidersView` — on the stated ground that
 * an ingestion operator has to be able to see whether the organization can embed at all, and the
 * capability flags on THESE rows are half of that answer. So a knowledge_manager reads the catalog
 * and is refused every write on it. An analyst holds nothing in this catalog and is refused all
 * five. That asymmetry is asserted per action in tests/Security/ProviderModelAccessTest.php rather
 * than assumed from a uniform dataset, because a uniform one would go green against a `view` that
 * had silently been mapped to `providers.manage`.
 *
 * ── WHY EVERY WRITE IS THE SAME PERMISSION, INCLUDING THE DELETE ──────────────────────────────
 *
 * A catalog row is not a credential and cannot become one, so there is no `providers.rotate`-shaped
 * argument for a stricter permission on any single verb. What makes the DELETE stricter than the
 * others is not the permission — it is the refusal the controller and the repository both perform
 * when the row is the organization's designated embedding model, which is a check this class has
 * no argument position for.
 */
final class ProviderModelEntryPolicy extends OrgScopedPolicy
{
    public function view(?User $user, ProviderModelEntry $model): Response
    {
        return $this->permit($user, $model, Permission::ProvidersView);
    }

    public function update(?User $user, ProviderModelEntry $model): Response
    {
        return $this->permit($user, $model, Permission::ProvidersManage);
    }

    public function delete(?User $user, ProviderModelEntry $model): Response
    {
        return $this->permit($user, $model, Permission::ProvidersManage);
    }
}
