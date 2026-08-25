<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\PendingSourceObject;
use Carbon\CarbonImmutable;

/**
 * The write-ahead ledger of object-storage keys — security finding S3.
 *
 * ── THE PROTOCOL, WHICH IS THREE CALLS AND AN ORDER ──────────────────────────────────────────
 *
 *   1. `reserve()` BEFORE the bytes are written. If the process dies at any point after this, the
 *      row is what names the object.
 *   2. `release()` AFTER the transaction that wrote the `source_items` rows has COMMITTED. Not
 *      inside it: a release that rolls back with its transaction is a release that never happened,
 *      and a release that commits with a transaction which then fails on a later statement would
 *      discard the ledger entry for an object nothing points at.
 *   3. `collectable()` is the sweep's input, and everything it returns is a candidate rather than a
 *      verdict — the sweep re-checks `source_items` before deleting anything.
 *
 * `release()` and `forget()` are BOTH deletes and they are deliberately two methods. The first is
 * the happy path, keyed on the source, and it is allowed to delete several rows at once; the second
 * is the sweep retiring ONE row it has finished with, keyed on the key itself, and it must not be
 * able to take a sibling reservation with it.
 */
interface PendingSourceObjectRepositoryInterface
{
    /**
     * Record that `$storageKey` is about to be written under `$sourceId`.
     *
     * THE ORGANIZATION IS A REQUIRED POSITIONAL ARGUMENT, as on every method in this layer. Here it
     * is also load-bearing in the database: `pending_source_objects_key_is_tenant_scoped` compares
     * the key against THIS ROW'S OWN organization column, so a key built for the wrong tenant is
     * refused by the INSERT rather than reserved and later swept.
     *
     * @throws \Illuminate\Database\QueryException 23505 when the key is already reserved. That is
     *                                             the correct outcome: two concurrent writers of
     *                                             one key would otherwise leave two rows, and the
     *                                             sweep would process the object twice.
     */
    public function reserve(string $organizationId, string $sourceId, string $storageKey): void;

    /**
     * Drop every reservation for `$sourceId`, because its rows are committed and the objects are
     * now named by `source_items.storage_key`.
     *
     * @return int rows deleted — reported by the caller rather than asserted, because a create that
     *             stored nothing (a crawl target) legitimately releases zero
     */
    public function release(string $organizationId, string $sourceId): int;

    /**
     * Reservations older than `$cutoff`, oldest first, capped at `$limit`.
     *
     * CROSS-TENANT ON PURPOSE — this is the sweep's input and there is no organization to scope it
     * by; the sweep binds one per row before acting. `$cutoff` is the grace window, and it is a
     * parameter rather than a constant here because the value is a deployment decision
     * (`config('kb.uploads.orphan_grace_minutes')`) and this layer should not read config.
     *
     * @return list<PendingSourceObject>
     */
    public function collectable(CarbonImmutable $cutoff, int $limit): array;

    /**
     * Retire exactly one reservation, by its key.
     *
     * @return int 1 normally, 0 if a concurrent release got there first — which is not an error,
     *             and is why this returns a count rather than void
     */
    public function forget(string $organizationId, string $storageKey): int;
}
