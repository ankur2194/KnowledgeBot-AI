<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\PendingSourceObject;
use App\Repositories\Contracts\PendingSourceObjectRepositoryInterface;
use Carbon\CarbonImmutable;

/**
 * The ledger, against `pending_source_objects`.
 *
 * `PendingSourceObject` carries NO `OrganizationScope` — see its docblock; a scoped model would
 * make the sweep read zero rows forever — so every method here states the organization in its own
 * predicate. That is not a weaker arrangement than the scope, it is the arrangement the scope was
 * always a backstop for; what is lost is the backstop, and `collectable()` is the one method that
 * genuinely wants it gone.
 */
final class EloquentPendingSourceObjectRepository implements PendingSourceObjectRepositoryInterface
{
    public function reserve(string $organizationId, string $sourceId, string $storageKey): void
    {
        $row = new PendingSourceObject;

        // ASSIGNED, NOT FILLED. `$fillable` is empty on every model here and
        // `Model::shouldBeStrict()` makes `fill()` throw rather than silently drop; `forceFill()`
        // is banned outright by tests/Arch/StringLevelDoctrineTest.php.
        $row->id = $row->newUniqueId();
        $row->organization_id = $organizationId;
        $row->source_id = $sourceId;
        $row->storage_key = $storageKey;

        $row->save();
    }

    public function release(string $organizationId, string $sourceId): int
    {
        return (int) PendingSourceObject::query()
            ->where('organization_id', $organizationId)
            ->where('source_id', $sourceId)
            ->delete();
    }

    /**
     * @return list<PendingSourceObject>
     */
    public function collectable(CarbonImmutable $cutoff, int $limit): array
    {
        // tenancy-exempt: the orphan sweep, deliberately across every organization. There is no
        // tenant to scope it by — the caller is a console command with no request and no session —
        // and the rows it returns carry their own `organization_id`, which the sweep binds as a
        // TenantContext before it consults anything else. Narrowing this by organization would
        // mean iterating every organization to visit a table whose live cardinality is the number
        // of uploads currently in flight.
        //
        // OLDEST FIRST, so a backlog drains in the order it accumulated and a row can never be
        // starved by a steady arrival rate at the head of the index.
        // `array_values()` because `Collection::all()` is `array<int, T>` and the contract promises
        // a `list<T>`. Eloquent's keys happen to be sequential today; the cast is what makes that a
        // guarantee rather than an observation.
        return array_values(
            PendingSourceObject::query()
                ->where('created_at', '<', $cutoff)
                ->orderBy('created_at')
                ->limit($limit)
                ->get()
                ->all()
        );
    }

    public function forget(string $organizationId, string $storageKey): int
    {
        return (int) PendingSourceObject::query()
            ->where('organization_id', $organizationId)
            ->where('storage_key', $storageKey)
            ->delete();
    }
}
