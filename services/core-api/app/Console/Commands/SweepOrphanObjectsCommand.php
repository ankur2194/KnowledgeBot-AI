<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PendingSourceObject;
use App\Repositories\Contracts\KnowledgeSourceRepositoryInterface;
use App\Repositories\Contracts\PendingSourceObjectRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Collect the object-storage orphans the source-create path can leave behind. SECURITY FINDING S3,
 * and this command is the half of it that had no owner.
 *
 * ── WHAT AN ORPHAN IS HERE, AND WHY NOTHING ELSE WILL EVER FIND IT ─────────────────────────────
 *
 * `SourceService::create()` writes the bytes BEFORE the rows, because object storage does not join
 * a PostgreSQL transaction and the alternative ordering — a committed row pointing at nothing —
 * fails ingestion forever with `error_class: storage` and needs an operator. The cost of that
 * choice is that a failure between the write and the commit leaves an object at
 * `org/{org}/sources/{sourceId}/original/{sha256}` for a `sourceId` that never became a row.
 *
 * NOTHING ELSE SWEEPS IT, AND THAT IS STRUCTURAL RATHER THAN A GAP IN COVERAGE. The phase-2 purge
 * walks the prefixes of sources that EXIST; this key is under a source that does not, so the purge
 * never visits it and `deletion/verification.py` — which enumerates only under the prefixes it was
 * given — CERTIFIES THE DELETION CLEAN while the bytes survive. That is non-negotiable 6 failing in
 * the one direction that produces a signed proof of a deletion that did not happen, which is why
 * this is a security finding and not a housekeeping ticket.
 *
 * The key is also not self-healing: `ObjectKey::originalUpload()` is SOURCE-scoped and
 * `SourceService::create()` mints the source id per request, so retrying the identical upload
 * writes a DIFFERENT key. Orphans accumulate one per failed attempt.
 *
 * ── THE LEDGER IS THE INPUT, BECAUSE LISTING THE BUCKET IS NOT AN OPTION ───────────────────────
 *
 * The other conceivable implementation is "walk every `org/*` prefix and delete what `source_items`
 * does not name". It is rejected: it is O(objects) against SeaweedFS on every tick, it cannot see
 * abandoned multipart parts anyway (`ListObjectsV2` does not return them — that is the data plane's
 * separate 24-hour sweep), and its failure mode is a full-bucket delete-anything primitive driven
 * by a query. `pending_source_objects` is written before each object and deleted after the commit,
 * so an unmatched row is the whole input and the blast radius is exactly the keys this application
 * reserved.
 *
 * ── THE THREE GUARDS, IN THE ORDER THEY RUN ────────────────────────────────────────────────────
 *
 *   1. THE GRACE WINDOW. `config('kb.upload_orphan_grace_minutes')`, six hours by default. A
 *      reservation younger than that may belong to a request still in flight — object written,
 *      rows not yet committed — and in that instant guard 2 legitimately says "nothing claims
 *      this". `MINIMUM_GRACE_MINUTES` below refuses to run at all under a misconfigured window,
 *      rather than running with one.
 *   2. THE CLAIM CHECK. `storageKeyIsClaimed()` asks whether any `source_items` row names the key.
 *      Claimed means the commit happened and only the release was lost, so the ROW is retired and
 *      the object is left alone.
 *   3. THE TENANT CONTEXT. `SourceItem` is `#[ScopedBy(OrganizationScope::class)]` and that scope
 *      FAILS CLOSED — unbound, it applies `whereRaw('1 = 0')`. Guard 2 asked from a console
 *      command with no context would therefore answer "not claimed" FOR EVERY KEY IN THE DATABASE,
 *      and this command would delete every live original it had a reservation for. So each row is
 *      processed inside `TenantContext::runFor($row->organization_id, …)`, whose `finally` clears
 *      it again — the pooled-context failure that class exists for is exactly what a long-running
 *      loop over many tenants would otherwise reproduce.
 *
 * ── AND ONE DETECTOR, BECAUSE GUARD 2 IS A CHECK-THEN-ACT ──────────────────────────────────────
 *
 * Between "not claimed" and `delete()` there is a window in which a create transaction can commit.
 * The grace window makes it vanishingly unlikely rather than impossible, so the claim is re-asked
 * AFTER the delete: a key that is claimed now was claimed by a commit that raced us, and the bytes
 * it points at are gone. Nothing can undo that, which is precisely why it must not be silent — it
 * is reported, counted, and turns the exit code non-zero so the ScheduledTaskFailed listener fires.
 *
 * ── NO AUDIT ROW, AND THE PRECEDENT IS `SubmitIngestionJob` ────────────────────────────────────
 *
 * `AuditLogger::OPERATIONS` is a CLOSED catalog and this command has no actor, no request and no
 * subject that survives — the source it would name is the one that never existed. Inventing an
 * operation for an unattended sweep over unreferenced bytes would put a row in an append-only
 * compliance table for something no person did. The record is the exit code, the log line, and the
 * ledger row's disappearance.
 *
 * ── IDEMPOTENT, AND SAFE TO RUN TWICE ──────────────────────────────────────────────────────────
 *
 * A second run finds nothing: the rows are gone and an S3 delete of a missing key succeeds anyway.
 * It is scheduled, so it will run twice on a bad day, and a sweep that failed on re-run would be a
 * failure alert everyone learns to ignore.
 *
 * The class carries the `Command` suffix for `arch()->preset()->laravel()`. The artisan signature —
 * the part that is a contract, pinned by `tests/Feature/ScheduleTest.php` — is
 * `kb:sweep-orphan-objects`.
 */
final class SweepOrphanObjectsCommand extends Command
{
    /**
     * THE FLOOR THE CONFIGURED GRACE WINDOW MAY NOT GO UNDER, and a refusal rather than a clamp.
     *
     * A clamp would let a deployment set `KB_UPLOAD_ORPHAN_GRACE_MINUTES=0`, believe it had, and
     * get fifteen minutes — which is the wrong number for both the operator and the reader. A
     * refusal names the misconfiguration once an hour in a failing task, which is the only way a
     * value this destructive gets looked at.
     *
     * Fifteen minutes is not a recommendation. It is an assertion that no create request on this
     * application takes longer than that, which PHP-FPM's `request_terminate_timeout` and the
     * 25 MB per-file cap already make true by a wide margin.
     */
    private const MINIMUM_GRACE_MINUTES = 15;

    protected $signature = 'kb:sweep-orphan-objects
                            {--dry-run : Report what would be collected and delete nothing}';

    protected $description = 'Delete stored objects whose source rows were never committed (finding S3).';

    public function handle(
        PendingSourceObjectRepositoryInterface $ledger,
        KnowledgeSourceRepositoryInterface $sources,
        TenantContext $tenants,
    ): int {
        $grace = (int) config('kb.upload_orphan_grace_minutes');
        $limit = (int) config('kb.upload_orphan_sweep_limit');

        if ($grace < self::MINIMUM_GRACE_MINUTES) {
            $this->error(
                'kb.upload_orphan_grace_minutes is '.$grace.', below the '.self::MINIMUM_GRACE_MINUTES
                .'-minute floor. Nothing has been swept. A grace window shorter than the longest '
                .'create request lets this command delete the object of a source whose rows are '
                .'about to commit, which is the failure the write-before-row ordering exists to '
                .'avoid.',
            );

            return self::FAILURE;
        }

        if ($limit < 1) {
            $this->error('kb.upload_orphan_sweep_limit is '.$limit.'; a tick must be allowed at least one row.');

            return self::FAILURE;
        }

        // ONE CLOCK READING for the whole tick. Two calls to now() cannot disagree by much, but a
        // row on the boundary counted by one query and skipped by the next is a discrepancy
        // somebody would have to explain. UTC explicitly, matching the other sweeps: `app.timezone`
        // is UTC today and a future change must not silently move the boundary of a delete.
        $cutoff = CarbonImmutable::now('UTC')->subMinutes($grace);
        $dryRun = (bool) $this->option('dry-run');

        $collected = 0;
        $claimed = 0;
        $failed = 0;
        $raced = 0;

        foreach ($ledger->collectable($cutoff, $limit) as $reservation) {
            try {
                // THE TENANT CONTEXT IS BOUND HERE AND NOWHERE ELSE. See guard 3 in the docblock:
                // `storageKeyIsClaimed()` reads a scoped model, and the scope fails CLOSED, so
                // asking it outside this closure answers "not claimed" for every key in the
                // database. `runFor()`'s `finally` restores the previous value, so one tenant's
                // context cannot survive into the next row.
                $outcome = $tenants->runFor(
                    $reservation->organization_id,
                    fn (): string => $this->sweep($reservation, $ledger, $sources, $dryRun),
                );
            } catch (Throwable $failure) {
                // ONE ROW'S FAILURE IS NOT THE TICK'S. A SeaweedFS blip on one object must not stop
                // the loop, or a single unreachable key wedges the whole sweep every hour and the
                // backlog behind it never drains.
                $this->error($reservation->storage_key.': '.$failure->getMessage());
                $failed++;

                continue;
            }

            match ($outcome) {
                'collected' => $collected++,
                'claimed' => $claimed++,
                'raced' => $raced++,
                default => null,
            };
        }

        $this->table(
            ['outcome', 'objects'],
            [
                [$dryRun ? 'would delete' : 'deleted', $collected],
                ['claimed, reservation retired', $claimed],
                ['LOST TO A RACING COMMIT', $raced],
                ['failed', $failed],
            ],
        );

        if ($dryRun) {
            $this->comment('--dry-run: nothing was deleted and no reservation was retired.');
        }

        // A RACE IS A FAILING TICK. The bytes of a live source are gone and no retry recovers them;
        // the exit code is what reaches the ScheduledTaskFailed listener, and a sweep that reported
        // SUCCESS after destroying a live original would be indistinguishable from one that worked.
        return ($failed > 0 || $raced > 0) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * One reservation, inside its own tenant context.
     *
     * @return string one of `collected`, `claimed`, `raced`, `skipped`
     */
    private function sweep(
        PendingSourceObject $reservation,
        PendingSourceObjectRepositoryInterface $ledger,
        KnowledgeSourceRepositoryInterface $sources,
        bool $dryRun,
    ): string {
        $organizationId = $reservation->organization_id;
        $key = $reservation->storage_key;

        if ($sources->storageKeyIsClaimed($organizationId, $key)) {
            // THE COMMIT HAPPENED AND ONLY THE RELEASE WAS LOST — a crash between the transaction
            // and `release()`, or a release that itself failed. The object belongs to a live source
            // and must not be touched; the reservation is what is stale.
            if (! $dryRun) {
                $ledger->forget($organizationId, $key);
            }

            return 'claimed';
        }

        if ($dryRun) {
            $this->line('would delete '.$key);

            return 'collected';
        }

        // THE ONLY DESTRUCTIVE STATEMENT IN THIS FILE. `$key` came out of the ledger, where the
        // database refused to store a value outside this row's own organization prefix
        // (`pending_source_objects_key_is_tenant_scoped`), and it was put there by
        // `App\Support\Kb\ObjectKey`. It is never derived from anything a client sent.
        Storage::disk('s3')->delete($key);

        // THE DETECTOR. Guard 2 is a check-then-act and the grace window is what makes the gap
        // between them negligible rather than empty. If a commit landed inside that gap the bytes
        // of a live source are now gone, nothing can undo it, and the one thing that must not
        // happen is silence.
        if ($sources->storageKeyIsClaimed($organizationId, $key)) {
            $ledger->forget($organizationId, $key);

            $message = 'kb:sweep-orphan-objects deleted '.$key.' and a source_items row claimed it '
                .'immediately afterwards. A create transaction committed inside the check-then-act '
                .'window, so a live source now points at an object that is gone and its ingestion '
                .'will fail with error_class: storage. Re-upload the file. If this recurs, the '
                .'grace window (kb.upload_orphan_grace_minutes) is shorter than the longest create '
                .'request on this deployment.';

            $this->error($message);
            report(new RuntimeException($message));

            return 'raced';
        }

        $ledger->forget($organizationId, $key);

        return 'collected';
    }
}
