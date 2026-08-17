<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Repositories\Contracts\OrganizationRepositoryInterface;
use App\Services\Embedding\EmbeddingDesignation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use SensitiveParameter;

final class EloquentOrganizationRepository implements OrganizationRepositoryInterface
{
    public function designateEmbeddingConnection(
        string $organizationId,
        ?EmbeddingDesignation $designation,
    ): Organization {
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
