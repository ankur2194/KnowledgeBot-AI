<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Enums\BotDomainStatus;
use App\Models\BotDomain;
use App\Repositories\Contracts\BotDomainRepositoryInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class EloquentBotDomainRepository implements BotDomainRepositoryInterface
{
    /**
     * @return list<BotDomain>
     */
    public function forBot(string $organizationId, string $botId): array
    {
        return array_values($this->scoped($organizationId, $botId)
            // BY ORIGIN, so two reads of an unchanged set are byte-identical. `origin` is
            // COLLATE "C" on the column, so this is byte order rather than a locale order a base
            // image bump could move — which is what makes the determinism a property of the schema
            // rather than of the container. The order carries no precedence: the lookup is an exact
            // match, never a first-match walk.
            ->orderBy('origin')
            ->get()
            // `array_values()` rather than a `@var list<…>` annotation: `Collection::all()` is
            // typed `array<int, T>`, and asserting a list in a docblock claims something the call
            // cannot prove, where re-indexing makes it true.
            ->all());
    }

    public function countForBot(string $organizationId, string $botId): int
    {
        return $this->scoped($organizationId, $botId)->count();
    }

    public function originExists(string $organizationId, string $botId, string $origin): bool
    {
        return $this->scoped($organizationId, $botId)
            ->where('origin', '=', $origin)
            ->exists();
    }

    /**
     * @param  Closure(BotDomain): void  $audit
     */
    public function create(
        string $organizationId,
        string $botId,
        string $origin,
        Closure $audit,
    ): BotDomain {
        return DB::transaction(function () use ($organizationId, $botId, $origin, $audit): BotDomain {
            $domain = new BotDomain;

            // THE THREE COLUMNS OUTSIDE $fillable, ASSIGNED HERE AND ONLY HERE. `organization_id`
            // comes from the authenticated context passed in as an argument, `bot_id` from the
            // route, and `status` from this line — never from request input, which has no field for
            // any of them. `setAttribute` and not `fill()`, so `$fillable` is not a second
            // allow-list deciding the same write.
            $domain->organization_id = $organizationId;
            $domain->bot_id = $botId;
            $domain->origin = $origin;

            // ALWAYS `Pending`, AND ASSIGNED RATHER THAN LEFT TO THE COLUMN DEFAULT. The column
            // defaults to `pending` too and stays the authority for every writer that is not this
            // one; the assignment is here because an attribute the INSERT never mentioned is null
            // on the model afterwards, so the 201 body and the audit row would both read a null
            // status off a row the database has correctly stored as `pending`. The same reason
            // `EloquentBotRepository::create()` restates `status` and
            // `retrieval_configuration_version`.
            $domain->status = BotDomainStatus::Pending;

            // 23505 IS POSSIBLE HERE AND IS NOT HANDLED IN THIS FILE. The service performs a scoped
            // pre-flight existence check for the readable message and catches the SQLSTATE for the
            // race that check cannot win. `bot_domains_org_bot_origin` is the authority either way.
            $domain->save();

            // INSIDE the transaction, after the INSERT so the row has its ULID, before the COMMIT
            // so an ON_FAILURE_ABORT audit failure rethrows and takes the grant with it. A granted
            // origin with no audit row is precisely finding L2.
            $audit($domain);

            return $domain;
        });
    }

    /**
     * @param  Closure(BotDomain, BotDomainStatus): void  $audit
     */
    public function changeStatus(
        string $organizationId,
        string $botId,
        string $domainId,
        BotDomainStatus $status,
        Closure $audit,
    ): ?BotDomain {
        return DB::transaction(
            function () use ($organizationId, $botId, $domainId, $status, $audit): ?BotDomain {
                $domain = $this->lock($organizationId, $botId, $domainId);

                if ($domain === null) {
                    // The route binding already 404'd a foreign id long before this line; reaching
                    // here means the row was deleted between the binding and this transaction. Null
                    // rather than an exception, so the caller renders the same 404 the binding
                    // would have rather than a 500 describing a race it cannot act on.
                    return null;
                }

                // READ UNDER THE LOCK, before the write. Two concurrent promotions serialise here,
                // so neither audit row can claim a transition from a status the row never held.
                $previous = $domain->status;

                $domain->status = $status;
                $domain->save();

                $audit($domain, $previous);

                return $domain;
            },
        );
    }

    /**
     * @param  Closure(BotDomain): void  $audit
     */
    public function delete(string $organizationId, string $botId, string $domainId, Closure $audit): bool
    {
        return DB::transaction(function () use ($organizationId, $botId, $domainId, $audit): bool {
            $domain = $this->lock($organizationId, $botId, $domainId);

            if ($domain === null) {
                return false;
            }

            // BEFORE the row goes, because after it there is nothing left to describe — and inside
            // the transaction, so an ON_FAILURE_ABORT write failure leaves the grant in place
            // rather than removing it untraceably.
            $audit($domain);

            $domain->delete();

            return true;
        });
    }

    /**
     * One entry of ONE bot of ONE organization, locked FOR UPDATE.
     *
     * The ownership predicates are on the SELECT rather than only on the model's global scope,
     * because the lock has to be taken on a row this organization actually owns: a lock acquired
     * under a stale ambient context would be a lock on somebody else's row.
     */
    private function lock(string $organizationId, string $botId, string $domainId): ?BotDomain
    {
        return $this->scoped($organizationId, $botId)
            ->whereKey($domainId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * The two ownership predicates, written out once so no query in this class can be built without
     * them. `#[ScopedBy(OrganizationScope::class)]` adds the organization term from the ambient
     * `TenantContext` and is the backstop; this is the mechanism. The bot term has no backstop at
     * all — nothing in the model layer knows which bot a request is about — so it exists only here.
     *
     * @return Builder<BotDomain>
     */
    private function scoped(string $organizationId, string $botId): Builder
    {
        return BotDomain::query()
            ->where('organization_id', '=', $organizationId)
            ->where('bot_id', '=', $botId);
    }
}
