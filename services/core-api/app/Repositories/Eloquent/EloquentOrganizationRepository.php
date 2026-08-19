<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Exceptions\KbException;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\ProviderModelEntry;
use App\Models\User;
use App\Repositories\Contracts\OrganizationRepositoryInterface;
use App\Services\Embedding\EmbeddingDesignation;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use SensitiveParameter;

final class EloquentOrganizationRepository implements OrganizationRepositoryInterface
{
    /**
     * CONSTRUCTOR-INJECTED, unlike OrganizationScope's deliberate `app()` call. That class resolves
     * the context inside apply() because `HasGlobalScopes::addGlobalScope()` caches a `new $scope`
     * in a STATIC array keyed by model class, so an injected dependency there would outlive every
     * request in the worker. This class is `bind()`-registered, not `singleton()`, so it is
     * constructed fresh per resolution and the injected object is the container's live singleton —
     * whose mutable state `runFor()` updates in place. Reading it is a call-time read either way.
     */
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Set or clear the (connection, model) pair that supplies this organization's embedding
     * credential.
     *
     * ── THE RACE THIS CLOSES, AND THE DIRECTION IT USED TO MISS ────────────────────────────────
     *
     * `EloquentProviderModelRepository::delete()` locks the model row, then `organizations`, then
     * re-reads the designation — so DESIGNATE-THEN-DELETE has always been caught. The mirror image
     * was not. This method locked `organizations` and performed NO existence check on the pair, and
     * `organizations.embedding_model` is a bare `text` column with no foreign key to
     * `provider_models`, so DELETE-THEN-DESIGNATE left the organization naming a catalog row that
     * no longer existed — with BOTH requests returning 200 and nothing raising until the next
     * upload, as a resolution error nobody can connect to an action taken days earlier.
     *
     * The pre-flight resolution in EmbeddingDesignationService runs OUTSIDE both transactions (it
     * makes an HTTP call, which must never sit between BEGIN and COMMIT), so it loses the same race
     * and cannot be the authority.
     *
     * ── WHY A PLAIN SELECT AND NOT `lockForUpdate()` ON THE MODEL ROW ──────────────────────────
     *
     * The `organizations` row lock above IS the mutual exclusion: the delete path takes the same
     * lock, so exactly one of the two transactions is inside this section at a time. Under READ
     * COMMITTED each statement takes a fresh snapshot, and this SELECT begins only after the lock
     * wait ends — i.e. after the other transaction committed — so it sees the delete or it does
     * not, and never a stale in-between.
     *
     * Locking the model row here as well would ORDER THE TWO LOCKS IN THE OPPOSITE DIRECTION from
     * the delete path (model → organizations there, organizations → model here), which is the
     * textbook ABBA deadlock: both requests fail with 40P01 instead of one of them being refused
     * for a reason it can act on. The lock that serialises is already held; a second one buys
     * nothing and costs correctness.
     *
     * ── PRECONDITION: A BOUND TENANT CONTEXT THAT AGREES WITH $organizationId ──────────────────
     *
     * Asserted below rather than assumed, and the assertion is what keeps the 422 honest. See the
     * interface for the contract; see the guard for why it fails loudly instead of silently.
     *
     * @throws KbException `validation` (422) when the pair names a catalog row this organization
     *                     does not have
     * @throws LogicException when no tenant context is bound, or the bound one names a different
     *                        organization than $organizationId
     */
    public function designateEmbeddingConnection(
        string $organizationId,
        ?EmbeddingDesignation $designation,
    ): Organization {
        // ── THE PRECONDITION, CHECKED BEFORE THE TRANSACTION OPENS ────────────────────────────
        //
        // WHY THIS EXISTS AT ALL. The catalog re-verification below reads ProviderModelEntry, which
        // carries #[ScopedBy(OrganizationScope::class)], and that scope FAILS CLOSED: with no bound
        // context it appends `1 = 0`, and with a context naming another organization it appends a
        // predicate that conflicts with the explicit one on the next line. Either way the query is
        // unconditionally empty — not because the catalog row is gone, but because the query could
        // not ask the question. Everywhere else in this layer an empty scoped read renders as
        // "not found", which is a benign and roughly true thing to say. HERE it would be
        // INTERPRETED, into a specific 422 asserting that somebody deleted the model row while the
        // operator's form was open. That sentence would be false, and it would send whoever read it
        // to look at the wrong table.
        //
        // So the two conditions are separated before the interpretation happens: a caller in the
        // wrong context gets a LogicException naming the real defect, and `! $exists` below is left
        // meaning only the one thing it can now mean.
        //
        // THIS IS NOT A THIRD ISOLATION LAYER AND MUST NOT BE READ AS ONE. It never widens a query
        // and it grants nothing — the explicit `organization_id` predicate is still the mechanism
        // and the global scope is still the backstop, exactly as the interface describes. It is a
        // caller-contract check, in the same register as the LogicExceptions in App\Models\AuditLog.
        //
        // CHECKED UNCONDITIONALLY, including when $designation is null and no scoped query runs. A
        // precondition that depends on the value of an argument is the same trap in a smaller size,
        // and it would rot the first time somebody adds a second scoped read here. Clearing a
        // designation under a context that names a DIFFERENT organization is also not a harmless
        // no-op worth waving through: it is the stale-pooled-worker shape, and the record it
        // rewrites decides the vector space of everything indexed afterwards.
        //
        // NO PRODUCTION PATH CAN TRIP IT. App\Http\Middleware\TenantContext binds
        // `$request->route('organization')` and EmbeddingConfigurationController::update() passes
        // the id of the model bound from that same segment, so the two agree by construction. A
        // console command, a queued job or a backfill must wrap the call in
        // `TenantContext::runFor($organizationId, ...)` — one line, and the same line the request
        // path already executes.
        if (! $this->tenants->isBound() || $this->tenants->orgId() !== $organizationId) {
            throw new LogicException(
                'designateEmbeddingConnection() was called for organization ['.$organizationId
                .'] with no matching tenant context bound. The catalog re-verification inside it '
                .'reads a #[ScopedBy(OrganizationScope::class)] model, which fails closed, so '
                .'without an agreeing context the check answers "empty" for a reason that has '
                .'nothing to do with the catalog. Wrap the call in '
                .'TenantContext::runFor($organizationId, ...) — the request path already does.',
            );
        }

        return DB::transaction(function () use ($organizationId, $designation): Organization {
            // lockForUpdate, because this is a read-modify-write on a value another transaction
            // can change between the read and the write — two administrators designating at once
            // is textbook write skew (postgresql-patterns, "Isolation"). What is at stake is not a
            // counter: the losing write decides which vector space every future corpus is indexed
            // under, and nothing downstream would ever raise about it.
            $organization = Organization::query()
                ->whereKey($organizationId)
                ->lockForUpdate()
                ->first();

            if (! $organization instanceof Organization) {
                throw new RuntimeException("Organization [{$organizationId}] no longer exists.");
            }

            // RE-VERIFIED UNDER THE LOCK, and this is the authority rather than a second opinion:
            // no foreign key backs `(embedding_connection_id, embedding_model)`, so if this query
            // does not refuse it, nothing will. Both ownership predicates are explicit — the
            // organization AND the connection — so a pair naming another tenant's catalog row is
            // simply absent rather than "rejected by a comparison we wrote", which is the same
            // shape EmbeddingDesignationService uses when it builds the candidate set. The model's
            // #[ScopedBy(OrganizationScope::class)] is the backstop under both.
            //
            // EXISTENCE ONLY, deliberately. Whether the row can EMBED is the data plane's question
            // and was answered before this transaction opened (EmbeddingDesignationService refuses
            // an unready designation); re-asking it here would need a second HTTP call, and an HTTP
            // call between BEGIN and COMMIT pins xmin and stops autovacuum reclaiming dead tuples
            // database-wide. What this closes is narrower and is the whole gap: the row was there
            // when the resolver looked and is gone now.
            if ($designation !== null) {
                $exists = ProviderModelEntry::query()
                    ->where('organization_id', '=', $organizationId)
                    ->where('provider_connection_id', '=', $designation->connectionId)
                    ->where('model', '=', $designation->model)
                    ->exists();

                if (! $exists) {
                    // `validation` and not a 409: this IS about a field of the submitted body —
                    // the pair the operator named — which is exactly what distinguishes it from
                    // the delete path's 409 about the state of a different record.
                    throw KbException::validation(
                        'That model is no longer in this connection\'s catalog, so it cannot be '
                        .'designated as the embedding model. It was most likely removed while this '
                        .'form was open. Reload the provider configuration and choose again.',
                    );
                }
            }

            // forceFill is NOT used and the columns are NOT fillable: the designation is written
            // here, through one method, and nowhere else. Assigning the attributes directly keeps
            // Model::shouldBeStrict()'s guard on mass assignment intact for every other path.
            $organization->embedding_connection_id = $designation?->connectionId;
            $organization->embedding_model = $designation?->model;

            // The database has the last word. The composite foreign key
            // (id, embedding_connection_id) -> provider_connections (organization_id, id) rejects
            // a designation that names another organization's connection even if every layer
            // above it were bypassed, and the CHECK rejects half a designation.
            $organization->save();

            return $organization;
        });
    }

    /**
     * @return list<OrganizationUser>
     */
    public function membersOf(string $organizationId): array
    {
        // tenancy-exempt: `organization_users` carries no OrganizationScope by construction (see
        // App\Models\OrganizationUser) — it is the table that ESTABLISHES the tenant scope, so scoping
        // it by the context it produces is circular. The explicit `organization_id` below is therefore
        // the ONLY layer on this read, which is why the interface makes it required and positional.
        $memberships = OrganizationUser::query()
            ->where('organization_id', $organizationId)
            // MemberResource throws without it, and shouldBeStrict() would throw first.
            ->with('user')
            // Total and deterministic: `user_id` is `char(26) COLLATE "C"`, so ULID byte order is a
            // reproducible tiebreak for two memberships created in the same microsecond. An unordered
            // list reorders itself between requests and makes a table in the console flicker.
            ->orderBy('created_at')
            ->orderBy('user_id')
            ->get()
            ->all();

        return array_values($memberships);
    }

    public function hasMemberWithEmail(string $organizationId, string $email): bool
    {
        // TWO STATEMENTS RATHER THAN A `whereHas`, and the reason is not style: a `whereHas` closure
        // takes an `Eloquent\Builder<User>`, and a closure parameter with an unparameterised generic
        // is a PHPStan level-8 error that can only be silenced with an inline ignore. Two indexed
        // lookups also make the tenant predicate visible on its own line.
        //
        // tenancy-exempt on this first read: `users` has no organization_id, because a user is not
        // owned by an organization. It discloses nothing — the address comes from an administrator of
        // this organization, and a user who exists but is not a member produces the same `false` as
        // an address that has no account at all.
        $userId = User::query()->where('email', $email)->value('id');

        if (! is_string($userId)) {
            return false;
        }

        return OrganizationUser::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * @return array{organization: Organization, owner: User, membership: OrganizationUser}
     */
    public function createWithOwner(
        string $name,
        string $slug,
        string $email,
        string $ownerName,
        bool $platformOwner,
        #[SensitiveParameter] string $placeholderPassword,
    ): array {
        return DB::transaction(function () use (
            $name,
            $slug,
            $email,
            $ownerName,
            $platformOwner,
            $placeholderPassword,
        ): array {
            // One clock read for all three rows. Two reads inside one transaction can disagree, and
            // the first organization's creation timestamp disagreeing with its owner's is a fact
            // somebody would eventually have to explain.
            $now = CarbonImmutable::now();

            $organization = new Organization;
            $organization->name = $name;
            $organization->slug = $slug;
            $organization->status = OrganizationStatus::Active;
            // `settings` IS DELIBERATELY NOT ASSIGNED, so the column's own
            // `NOT NULL DEFAULT '{}'::jsonb` applies. PHP's `[]` json-encodes as `[]` — a jsonb ARRAY,
            // not an object — and the two are different values to every `->>` and `jsonb_set` that
            // ever reads this column. Letting the database supply its default is the only way to store
            // the empty OBJECT from here without a cast that does not exist.
            $organization->save();

            $owner = new User;
            $owner->name = $ownerName;
            $owner->email = $email;
            // `setAttribute` rather than direct assignment: neither column is declared in
            // App\Models\User's `@property` block, so `$owner->password = …` is a PHPStan level-8
            // `property.notFound`. It is the same code path `__set` takes, so the `hashed` cast still
            // applies. The value is an unusable random the command never prints; the operator sets a
            // real one through the password-reset flow every other user uses.
            $owner->setAttribute('password', $placeholderPassword);
            // VERIFIED, unlike an invitee — see the interface. There is no invitation to prove the
            // address, and an unverified owner cannot pass the `verified` gate on any org-scoped write
            // route, which is every route they were created to use.
            $owner->setAttribute('email_verified_at', $now);
            $owner->is_platform_owner = $platformOwner;
            $owner->save();

            $membership = new OrganizationUser;
            $membership->organization_id = $organization->id;
            $membership->user_id = $owner->id;
            $membership->role = OrgRole::Owner;
            $membership->status = MembershipStatus::Active;
            // `setAttribute`, because the `@property` block types these as `Illuminate\Support\Carbon`
            // and this is a CarbonImmutable. One clock read for all three rows — see above.
            $membership->setAttribute('created_at', $now);
            $membership->setAttribute('updated_at', $now);
            $membership->save();

            return [
                'organization' => $organization,
                'owner' => $owner,
                'membership' => $membership,
            ];
        });
    }

    public function anyExists(): bool
    {
        // tenancy-exempt: `organizations` IS the tenant root and has no organization_id. This is the
        // bootstrap command's refusal predicate — see the interface for why it lives beside the write.
        return Organization::query()->exists();
    }
}
