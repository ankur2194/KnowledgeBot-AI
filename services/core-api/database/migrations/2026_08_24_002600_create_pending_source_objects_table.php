<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * THE WRITE-AHEAD LEDGER FOR OBJECT-STORAGE KEYS, and the input to the sweep that reclaims the
 * orphans the create path can leave behind. Security finding S3.
 *
 * ═══ THE DEFECT THIS TABLE EXISTS FOR ════════════════════════════════════════════════════════
 *
 * Object storage does not join a PostgreSQL transaction, so `SourceService::create()` has exactly
 * two orderings available and both leak: "orphan object, no row" or "row pointing at nothing". It
 * chooses the orphan — a row pointing at nothing fails ingestion on every attempt with
 * `error_class: storage` and needs an operator — and that choice USED TO COME WITH A RECOVERY
 * STORY THAT WAS FALSE IN BOTH HALVES. It claimed the orphan was "a byte-for-byte-identical object
 * at a content-addressed key that the next attempt overwrites and a sweep can collect":
 *
 *   NOTHING OVERWROTE IT. `ObjectKey::originalUpload()` is SOURCE-scoped —
 *   `org/{org}/sources/{sourceId}/original/{sha256}` — and `$sourceId` is minted per request, so a
 *   retry of the identical upload writes a DIFFERENT key. Orphans accumulate one per failed
 *   attempt.
 *
 *   NO SWEEP EXISTED. `kb.maintenance.sweep_orphan_objects` was a line in a docstring;
 *   `services/ai-service/app/maintenance/tasks.py` declares an empty `__all__`.
 *
 * So an orphan was PERMANENT, and permanent in the one shape non-negotiable 6 cannot survive: it
 * sits under `org/{org}/sources/{sourceId}/`, a prefix the phase-2 purge only ever visits for
 * sources that EXIST, so deletion verification certifies it clean while the bytes survive.
 *
 * ═══ WHY A LEDGER AND NOT "ROWS FIRST" ═══════════════════════════════════════════════════════
 *
 * `SourceObjectWriter::write()` named two options. (a) ROWS FIRST — insert `source_items` with a
 * not-yet-written marker, write the object, clear the marker — changes the failure mode of the
 * whole create path: every reader of `source_items` acquires a state in which the object may not
 * be there, and `source_items_stored_object_is_complete` would have to be relaxed to let the
 * half-written row exist at all. (b) A PENDING-KEY RECORD is purely additive: nothing that reads
 * `source_items` changes, no CHECK is weakened, and the only new failure mode is a row this table
 * keeps for longer than it should. (b) is what this is.
 *
 * ═══ THERE IS DELIBERATELY NO FOREIGN KEY TO `knowledge_sources` ═════════════════════════════
 *
 * `source_id` is a plain `char(26)` with no REFERENCES clause, and that is THE ENTIRE POINT OF THE
 * TABLE rather than an omission. The row is written BEFORE the source exists and is the record of
 * an intention that may never become one — a composite FK, which every other cross-entity
 * reference in this schema carries, would make the insert fail in precisely the case the ledger is
 * for. A reviewer reaching for `source_items_source_same_org`'s shape here should stop at this
 * paragraph.
 *
 * The ORGANIZATION reference is real, because the organization always exists first: the request is
 * authenticated against a membership before any byte is accepted.
 *
 * ═══ `ON DELETE RESTRICT`, WHICH IS THE UNCOMFORTABLE DIRECTION AND THE CORRECT ONE ══════════
 *
 * CASCADE reads as the tidy choice — a ledger entry is not tenant content, so why block an
 * organization delete on one? Because deleting the row does not delete the OBJECT. A cascade would
 * silently discard the only record naming bytes that are still on disk, outside every prefix the
 * purge sweeps, which is defect 1 returning by a shorter road. RESTRICT makes an unswept orphan
 * block the delete instead, loudly, which is what an operator needs to see. The window is bounded:
 * `kb:sweep-orphan-objects` drains this table on the hour.
 *
 * ═══ EVERY ROW IS SHORT-LIVED, AND THE INDEX SET SAYS SO ═════════════════════════════════════
 *
 * The happy path writes a row and deletes it within one request. Steady-state cardinality is the
 * number of uploads in flight, so the table is small enough that the sweep's `created_at` scan is
 * cheap and the unique index on `storage_key` costs nothing to maintain. `storage_key` is unique
 * GLOBALLY rather than per organization because it already begins with the organization prefix —
 * two tenants cannot collide, and a global unique index is what makes a double reservation of one
 * key (a retry of the same request) a 23505 rather than two rows the sweep processes twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->run(<<<'SQL'
            CREATE TABLE pending_source_objects (
                id                  char(26) COLLATE "C" PRIMARY KEY,
                organization_id     char(26) COLLATE "C" NOT NULL
                                    REFERENCES organizations (id) ON DELETE RESTRICT,

                -- NO FOREIGN KEY, DELIBERATELY. See the docblock: the source this key belongs to
                -- may never be created, and that case is the one the ledger exists to record.
                source_id           char(26) COLLATE "C" NOT NULL,

                -- The key the caller is ABOUT TO WRITE. Built by `App\Support\Kb\ObjectKey` and by
                -- nothing else, which is what makes the tenant-prefix CHECK below meaningful.
                storage_key         text COLLATE "C" NOT NULL,

                -- THE GRACE CLOCK. The sweep only considers rows older than a configured window,
                -- because a row younger than that may belong to a request that is still uploading
                -- and whose object is about to be claimed by a commit.
                created_at          timestamptz NOT NULL DEFAULT now(),

                -- ── the same tenant-prefix rule `source_items` carries, for the same reason ──
                -- Checked against THIS ROW'S OWN organization_id with a `||` concatenation, so a
                -- reservation for another tenant's prefix is refused by the INSERT. Without it the
                -- sweep would be a delete-any-object primitive whose argument comes from a table.
                CONSTRAINT pending_source_objects_key_is_tenant_scoped CHECK (
                    storage_key LIKE 'org/' || organization_id || '/%'
                ),

                CONSTRAINT pending_source_objects_key_shape CHECK (
                    btrim(storage_key) <> '' AND length(storage_key) <= 2048
                )
            )
        SQL);

        // ONE RESERVATION PER KEY. A second reservation of a key already pending is a 23505 rather
        // than a second row, so the sweep cannot process one object twice and a caller cannot
        // reserve a key another request is mid-write on.
        $this->run(
            'CREATE UNIQUE INDEX pending_source_objects_key ON pending_source_objects (storage_key)',
        );

        // THE SWEEP'S OWN INDEX. `created_at < cutoff ORDER BY created_at` over a table whose live
        // rows are the uploads in flight.
        $this->run(
            'CREATE INDEX pending_source_objects_created ON pending_source_objects (created_at)',
        );

        // THE RELEASE'S INDEX, and the FK-child index for `organization_id` in one object. The
        // happy path deletes by (organization_id, source_id) after the create transaction commits;
        // PostgreSQL's referential check when an `organizations` row is deleted names
        // `organization_id`, which is this index's leading column.
        $this->run(
            'CREATE INDEX pending_source_objects_org_source '
            .'ON pending_source_objects (organization_id, source_id)',
        );
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS pending_source_objects');
    }

    private function run(string $sql): void
    {
        Schema::getConnection()->statement($sql);
    }
};
