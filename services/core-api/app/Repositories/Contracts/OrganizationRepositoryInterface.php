<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Exceptions\KbException;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Services\Embedding\EmbeddingDesignation;
use App\Services\Quotas\QuotaLimits;
use App\Services\Rerank\RerankDesignation;
use Closure;
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
     * ── IT CARRIES AN AUDIT CLOSURE, AND IT DID NOT USED TO ─────────────────────────────────
     *
     * The parameter was added when the gap it left was closed. Before it, this method wrote
     * `embedding_connection_id` / `embedding_model` with NO audit row and no way to pass one
     * through — so "who moved the vector space, and from what" had no answer in `audit_logs`, on the
     * one column pair whose `(provider, model)` value IS the vector space (ADR-031): re-designating
     * it renames the Qdrant collection and strands every indexed chunk until a re-index at a
     * provider's per-token price.
     *
     * The closure is REQUIRED rather than optional, and it is required for the same mechanical
     * reason `designateRerankConnection()`'s is: both `organization.embedding_designation.*`
     * operations are ON_FAILURE_ABORT, so a failed audit write must roll the designation back —
     * and `AuditLogger` opens no transaction of its own while `DB` is arch-pinned to
     * `App\Repositories\Eloquent`. It therefore has to run INSIDE this method's transaction, which
     * means it has to arrive as an argument.
     *
     * It is called with the organization row as it stands AFTER the write and with the PREVIOUS
     * pair, because neither half is recoverable from the other afterwards — and here the previous
     * pair is the only record of which vector space the existing corpus is in.
     *
     * @param  Closure(Organization, ?EmbeddingDesignation): void  $audit  invoked inside the
     *                                                                     transaction with the
     *                                                                     written row and the
     *                                                                     PREVIOUS pair
     *
     * @throws KbException `validation` (422) when the designation names a (connection, model) pair
     *                     this organization's catalog does not contain
     * @throws LogicException when the ambient tenant context is unbound or names another
     *                        organization — a caller defect, rendered 500 / `internal`
     */
    public function designateEmbeddingConnection(
        string $organizationId,
        ?EmbeddingDesignation $designation,
        Closure $audit,
    ): Organization;

    /**
     * Write (or clear) the rerank designation for ONE organization, and audit the change.
     *
     * Returns the refreshed organization.
     *
     * ── IT CARRIES AN AUDIT CLOSURE, AND SO DOES ITS EMBEDDING TWIN NOW ──────────────────────
     *
     * This paragraph used to record an asymmetry — "and `designateEmbeddingConnection()` does not"
     * — as a real gap: the embedding designation was written with no audit row at all. That gap is
     * closed and both methods take the closure for the identical mechanical reason. Both
     * `organization.*_designation.*` operations are ON_FAILURE_ABORT, so a failed audit write must
     * roll the designation back — and AuditLogger opens no transaction of its own while `DB` is
     * arch-pinned to App\Repositories\Eloquent. The closure is therefore REQUIRED and runs inside
     * this method's transaction, exactly as the four `provider.connection.*` operations do.
     *
     * It is called with the organization row as it stands AFTER the write and with the PREVIOUS
     * pair, because "who changed the reranker, from what, to what" is the whole content of the
     * record and neither half is recoverable from the other afterwards.
     *
     * ── THE CATALOG RE-VERIFICATION IS PART OF THE CONTRACT ───────────────────────────────────
     *
     * An implementation MUST re-verify the pair inside its own transaction. Nothing in the database
     * ties `(rerank_connection_id, rerank_model)` to a `provider_models` row — the composite
     * foreign key covers the CONNECTION only — so a catalog delete committing between the caller's
     * pre-flight check and this write would leave the organization naming a row that no longer
     * exists, with both requests returning 200. That failure is quieter than its embedding twin: it
     * does not break an upload, it turns reranking off, and the only symptom is answer quality.
     *
     * ── PRECONDITION: THE AMBIENT TENANT CONTEXT MUST BE BOUND AND MUST EQUAL $organizationId ───
     *
     * Identical to `designateEmbeddingConnection()`'s and for the identical reason: the
     * re-verification reads a `#[ScopedBy(OrganizationScope::class)]` model, and that scope fails
     * closed — no context appends `1 = 0`, a disagreeing context appends a predicate that conflicts
     * with the explicit one. Either way the query answers "empty" for a reason that has nothing to
     * do with the catalog, and a 422 built on that answer would tell the operator something false
     * about a table nobody touched. An implementation asserts the precondition and raises rather
     * than interpreting an empty result. `App\Http\Middleware\TenantContext` already satisfies it
     * for every request; a job or a console command wraps the call in
     * `TenantContext::runFor($organizationId, fn () => …)`.
     *
     * @param  Closure(Organization, ?RerankDesignation): void  $audit  invoked inside the
     *                                                                  transaction with the written
     *                                                                  row and the PREVIOUS pair
     *
     * @throws KbException `validation` (422) when the designation names a (connection, model) pair
     *                     this organization's catalog does not contain
     * @throws LogicException when the ambient tenant context is unbound or names another
     *                        organization — a caller defect, rendered 500 / `internal`
     */
    public function designateRerankConnection(
        string $organizationId,
        ?RerankDesignation $designation,
        Closure $audit,
    ): Organization;

    /**
     * Write all four quota ceilings for ONE organization, and audit the change.
     *
     * Returns the refreshed organization.
     *
     * ── ALL FOUR AT ONCE, ALWAYS, EVEN WHEN THE CALLER ONLY MEANT TO CHANGE ONE ──────────────
     *
     * `QuotaLimits` is a complete set and this method writes all four columns from it. A per-metric
     * variant would make "raise the storage limit" a read-modify-write in the CALLER, which is write
     * skew on a value another administrator can change between the read and the write — the same
     * hazard `designateEmbeddingConnection()` takes `lockForUpdate` for, with money attached instead
     * of a vector space. An implementation MUST take the row lock and read the PREVIOUS four values
     * under it.
     *
     * ── `null` IS UNLIMITED AND `0` IS "NOTHING IS ALLOWED" ─────────────────────────────────
     *
     * The distinction survives all the way down: an implementation writes nulls as nulls and never
     * coalesces them, because `QuotaGate` skips a null metric entirely and refuses everything on a
     * zero. Coalescing a null to zero here would freeze an unmetered organization, and coalescing a
     * zero to null would silently remove a ceiling somebody set deliberately.
     *
     * ── THE AUDIT CLOSURE IS REQUIRED, FOR THE SAME REASON THE TWO DESIGNATIONS' ARE ────────
     *
     * `organization.quota_limits.updated` is ON_FAILURE_ABORT — a ceiling that moved with nothing
     * recording who moved it is the one state that row exists to prevent — so it must be written
     * inside this method's transaction, before the COMMIT.
     *
     * ── PRECONDITION: THE AMBIENT TENANT CONTEXT MUST BE BOUND AND MUST EQUAL $organizationId ──
     *
     * Same as the two designation methods and for a related reason. There is no scoped catalog read
     * here, so the failure mode is different — but clearing or raising a quota under a context
     * naming ANOTHER organization is the stale-pooled-worker shape, on the row that decides what a
     * tenant may spend. An implementation asserts it and raises rather than proceeding.
     *
     * @param  Closure(Organization, QuotaLimits): void  $audit  invoked inside the transaction with
     *                                                           the written row and the PREVIOUS
     *                                                           four ceilings
     *
     * @throws LogicException when the ambient tenant context is unbound or names another
     *                        organization — a caller defect, rendered 500 / `internal`
     */
    public function setQuotaLimits(
        string $organizationId,
        QuotaLimits $limits,
        Closure $audit,
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
