<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Exceptions\KbException;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Services\Embedding\EmbeddingDesignation;
use LogicException;
use SensitiveParameter;

interface OrganizationRepositoryInterface
{
    /**
     * Write (or clear) the embedding designation for ONE organization.
     *
     * $organizationId is required and positional for the same reason every other repository method
     * takes it: the global scope reads the ambient context, so on a path where that context is
     * stale both layers fail together unless the argument is explicit.
     *
     * Returns the refreshed organization.
     *
     * AN IMPLEMENTATION MUST RE-VERIFY THE PAIR INSIDE ITS OWN TRANSACTION, and that is part of
     * the contract rather than an implementation detail: nothing in the database ties
     * `(embedding_connection_id, embedding_model)` to a `provider_models` row — the composite
     * foreign key covers the CONNECTION only — so a delete committing between the caller's
     * pre-flight resolution and this write would otherwise leave the organization naming a catalog
     * row that no longer exists, with both requests returning 200.
     *
     * ── PRECONDITION: THE AMBIENT TENANT CONTEXT MUST BE BOUND AND MUST EQUAL $organizationId ───
     *
     * READ THIS BEFORE CALLING FROM A JOB, A CONSOLE COMMAND OR A BACKFILL. The re-verification the
     * paragraph above mandates is a read of a `#[ScopedBy(OrganizationScope::class)]` model, and
     * that scope fails closed — no context appends `1 = 0`, a disagreeing context appends a
     * predicate that conflicts with the explicit one. Either way the check answers "empty" for a
     * reason that has nothing to do with the catalog, and a 422 built on that answer would tell the
     * operator something false about a table nobody touched.
     *
     * An implementation therefore ASSERTS the precondition and raises rather than interpreting an
     * empty result. Satisfying it is one line — `TenantContext::runFor($organizationId, fn () =>
     * …)` — which is exactly what App\Http\Middleware\TenantContext already does for every request
     * that reaches this method, so no HTTP caller has to do anything.
     *
     * The precondition does NOT make the ambient context an authorization layer or replace the
     * explicit argument: $organizationId remains the mechanism and the scope remains the backstop.
     * It only says that this method's caller must not have put the two in disagreement.
     *
     * @throws KbException `validation` (422) when the designation names a (connection, model) pair
     *                     this organization's catalog does not contain
     * @throws LogicException when the ambient tenant context is unbound or names another
     *                        organization — a caller defect, rendered 500 / `internal`
     */
    public function designateEmbeddingConnection(
        string $organizationId,
        ?EmbeddingDesignation $designation,
    ): Organization;

    /**
     * ONE organization's memberships, active and suspended, each with its `user` eager-loaded.
     *
     * `organization_users` deliberately carries no OrganizationScope — it is the table the scope is
     * derived FROM — so the `organization_id` argument here is not the backstop's companion, it is the
     * only layer. It is required and positional for that reason, and there is deliberately no
     * "search by email" or "across organizations" variant: either one turns a tenant-scoped list into
     * a global user directory, and the shape is not recoverable once a client depends on it.
     *
     * @return list<OrganizationUser> oldest membership first
     */
    public function membersOf(string $organizationId): array;

    /**
     * Whether ONE organization already has a membership row for an address — ANY status, not only
     * active.
     *
     * Any status, because acceptance INSERTs into `organization_users`, whose primary key is
     * (organization_id, user_id): a suspended member who was re-invited and accepted would collide
     * with 23505 rather than be reinstated. Restoring a suspended member is a role/status change, not
     * an invitation, and answering "active only" here would send an administrator down the one path
     * that cannot work.
     *
     * The join is to `users`, which has no tenant key of its own; the query is nonetheless
     * org-scoped, because it starts from this organization's memberships and asks about them.
     */
    public function hasMemberWithEmail(string $organizationId, string $email): bool;

    /**
     * Create the FIRST organization and its owner, atomically. The one caller is
     * App\Console\Commands\BootstrapOrganizationCommand.
     *
     * THE TRANSACTION LIVES HERE AND NOT IN THE COMMAND, and that is not a layering preference:
     * `tests/Arch/DoctrineTest.php:40-42` pins `Illuminate\Support\Facades\DB` to
     * `App\Repositories\Eloquent`, so a `DB::transaction()` in a Command fails the Arch suite. The
     * three rows — organization, user, membership — are useless individually: an organization with no
     * owner cannot be administered and an owner with no membership authorizes nothing.
     *
     * The owner is created VERIFIED (`email_verified_at = now()`), unlike an invitee. There is no
     * invitation to prove the address, the operator is asserting it out of band, and an unverified
     * owner cannot pass the `verified` gate on any org-scoped write route — i.e. cannot do the thing
     * they were created for. The password is a caller-supplied unusable placeholder; this method never
     * generates, prints or logs one.
     *
     * @param  string  $placeholderPassword  an unusable random value. The command mints it, never
     *                                       prints it, and immediately issues a password-reset token
     *                                       through the normal broker so the operator sets their own.
     * @return array{organization: Organization, owner: User, membership: OrganizationUser}
     */
    public function createWithOwner(
        string $name,
        string $slug,
        string $email,
        string $ownerName,
        bool $platformOwner,
        #[SensitiveParameter] string $placeholderPassword,
    ): array;

    /**
     * Whether ANY organization exists.
     *
     * The bootstrap command's refusal predicate. It is a repository read rather than
     * `Organization::query()->exists()` in the command for one reason worth stating: this is the
     * check that keeps a one-shot onboarding command from being a production backdoor, and it belongs
     * next to the write it guards, where a reviewer reads both at once.
     *
     * tenancy-exempt by definition — `organizations` IS the tenant root and has no organization_id.
     */
    public function anyExists(): bool;
}
