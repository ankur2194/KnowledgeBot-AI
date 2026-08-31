<?php

declare(strict_types=1);

namespace App\Services\Sources;

use App\Enums\SourceState;
use App\Enums\SourceType;
use App\Exceptions\IllegalSourceTransition;
use App\Jobs\SubmitIngestionJob;
use App\Jobs\SyncSourceStatusJob;
use App\Models\KnowledgeSource;
use App\Models\Organization;
use App\Models\SourceItem;
use App\Models\SourceVersion;
use App\Repositories\Contracts\KnowledgeSourceRepositoryInterface;
use App\Repositories\Contracts\PendingSourceObjectRepositoryInterface;
use App\Services\Audit\AuditLogger;
use App\Services\Sources\Upload\SourceObjectWriter;
use App\Services\Sources\Upload\UploadIntake;
use App\Services\Usage\UsageRecorder;
use App\Support\Http\ListQuery;
use App\Support\Kb\CanonicalKey;
use App\Support\Kb\ObjectKey;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The organization's knowledge sources: list, create, edit, disable, enable, reprocess, delete —
 * and the one place an ingestion run is dispatched from.
 *
 * ── WHAT A SOURCE ROW DECIDES, AND THEREFORE WHY EVERY WRITE HERE IS AUDITED ─────────────────
 *
 * `source_version_id` and `source_status` are two of the four mandatory Qdrant filter terms
 * (`kb-tenancy-isolation` NN3), and both are resolved from these tables. So this row does not
 * merely describe a document — it decides which documents a bot may answer from, and a mistake here
 * produces a correct-looking answer with a well-formed citation rather than an error. §18.11
 * requires destructive operations audited; on this surface the case extends to a DISABLE, because
 * "who turned this source off" is a question with real consequences and nothing else records it.
 *
 * ── C3's HEADLINE REQUIREMENT: DISABLE, AND WHICH HALF OF IT IS ACTUALLY WIRED ────────────────
 *
 * `disable()` moves the source to `SourceState::Disabled` and returns. THAT COLUMN IS THE DURABLE
 * HALF, AND IT IS THE ONLY HALF THIS SERVICE WRITES — this docblock claimed it was the whole
 * exclusion and that claim did not survive being checked against the other side of the seam.
 * PostgreSQL is the source of truth and it now records the intent; NOTHING IS DELETED and no job
 * has to succeed first, so every vector is retained and re-enabling stays a metadata write.
 *
 * THE RETRIEVAL-SCOPE HALF IS HALF BUILT, AND THIS PARAGRAPH SAYS WHICH HALF. `source_status`
 * matched positively against `['ready','ready_with_warnings']` is the right mechanism, and
 * `kb-tenancy-isolation` NN5 is why that direction matters — a `match` condition is not satisfied
 * by a point that lacks the value, so a positive filter fails closed. `source_status` is a QDRANT
 * PAYLOAD FIELD WRITTEN AT UPSERT TIME (`services/ai-service/app/ingestion/indexing/upserter.py`,
 * which says so itself and adds that "re-enabling is a payload write rather than a re-ingest"),
 * and `disable()` and `enable()` now DISPATCH that write: `SyncSourceStatusJob` calls
 * `source.status.sync` on the data plane, which rewrites the term on every point of the source in
 * every collection its versions live in and returns the filtered count that proves it.
 *
 * THE OTHER MECHANISM IS STILL OWED AND IS PHASE D'S. The resolved `allowed_version_ids` set
 * Laravel is meant to compute from `bot_source_assignments` joined to `knowledge_sources` and
 * `source_versions` and ship in the config snapshot — the thing that makes a disable take effect
 * AT ONCE without touching any payload — does not exist in this service. The only thing here that
 * takes that set, `SparseCorpusStatisticsRepositoryInterface`, receives it as an argument from a
 * caller nobody has written. The two are complementary rather than alternatives: the snapshot is
 * what makes a disable instant, and the payload rewrite is what makes it durable and is what makes
 * a re-enable put the source back without a re-ingest.
 *
 * NOTHING READS EITHER TODAY, because there is no chat path in this repository. What changed is
 * that the disable is now carried rather than deferred, so the failure shape to watch for is no
 * longer "the index was never told" but "the index was told and did not verify" — which is loud,
 * lands in `failed_jobs`, and is what `SyncSourceStatusJob::failed()` is about.
 *
 * THE CACHED ANSWER CARRIES THE SAME QUALIFICATION. `valkey-keyspaces` keys `ans:` on a fingerprint
 * of the RESOLVED retrieval scope — the set of source versions this bot may search right now — so
 * a disable changes the key rather than requiring a purge somebody has to remember. That is the
 * design and it is sound; the fingerprint is computed from the same unresolved set, and there is no
 * answer cache here yet to key.
 *
 * ── THE STATE MACHINE IS ASKED, NEVER RE-IMPLEMENTED ──────────────────────────────────────────
 *
 * Nothing in this class compares a status to a literal. Every move goes through the repository's
 * one `move()`, which asks `SourceState::canTransitionTo()`, and an illegal move raises
 * `IllegalSourceTransition` — converted here into a `validation` refusal keyed on `status`, because
 * there IS a field to key it on and `kb-error-taxonomy` renders that class as 422 with a per-field
 * map an admin console can display against the input.
 *
 * ── DISPATCH HAPPENS AFTER THE COMMIT, AND THE JOB ID IS MINTED BEFORE IT ────────────────────
 *
 * The `valkey` connection sets `after_commit => true`, so a dispatch issued from inside the
 * repository's transaction is held until the COMMIT lands — without it the worker pops the job
 * before the row exists and fails with a `ModelNotFoundException` for a row the dispatcher just
 * created. The job id is minted HERE, before the write, because it is stamped onto every item in
 * the same transaction: a callback naming any other job is a superseded run and is ignored outright
 * rather than compared, and a job id assigned after the fact could not be.
 */
final class SourceService
{
    /**
     * The MIME recorded for a pasted-text source.
     *
     * DERIVED AND NOT DECLARED, like every other MIME in this schema — but derived trivially,
     * because WE generated the bytes from a validated UTF-8 string rather than accepting them from
     * a client. `kb-security-baseline` refuses the caller's `Content-Type`; there is no caller's
     * `Content-Type` on this path at all.
     *
     * NO `; charset=utf-8` PARAMETER. `source_items_mime_shape` is
     * `^[[:alnum:]!#$&^_.+-]+/[[:alnum:]!#$&^_.+-]+$`, which admits neither the semicolon nor the
     * space — deliberately, since a MIME with parameters is two facts in one column and the
     * parameters are what a sniffer cannot establish.
     */
    public const TEXT_MIME = 'text/plain';

    public function __construct(
        private KnowledgeSourceRepositoryInterface $sources,
        private AuditLogger $audit,
        // THE INTAKE GATE AND THE WRITER, INJECTED RATHER THAN CONSTRUCTED, so a test can assert
        // this service's behaviour against a real gate and a faked disk without either one being
        // reachable from a route. Neither takes a constructor argument, so container resolution is
        // the whole wiring.
        private UploadIntake $intake,
        private SourceObjectWriter $objectWriter,
        // THE WRITE-AHEAD LEDGER (security finding S3). Every key this service is about to write is
        // recorded here first and released after the transaction commits, so a failure in between
        // leaves a row `kb:sweep-orphan-objects` can find. Without it an object written for a
        // source that never committed is permanent AND invisible — it sits under a prefix the
        // phase-2 purge only visits for sources that exist, so verification certifies it clean.
        private PendingSourceObjectRepositoryInterface $pendingObjects,
        // THE QUOTA LEDGER'S ONLY WRITER. Storage usage is recorded AFTER the transaction commits —
        // see `recordStorageUsage()` for why it cannot be inside it.
        private UsageRecorder $usage,
    ) {}

    /**
     * One page of this organization's sources.
     *
     * NO AUDIT ROW. §18.11 audits credential changes and destructive operations; reading a list is
     * neither, and auditing it would bury the rows that matter under one per page load.
     *
     * @return LengthAwarePaginator<int, KnowledgeSource>
     */
    public function list(Organization $organization, ListQuery $query): LengthAwarePaginator
    {
        return $this->sources->paginate($organization->organizationId(), $query);
    }

    /**
     * What is inside one source, as of its live versions.
     *
     * ── IT IS A SEPARATE CALL FROM `list()` AND MUST STAY ONE ─────────────────────────────────
     *
     * Four aggregate statements against `source_items`, `source_versions`, `document_elements` and
     * `chunks`, per source. On a detail page that is four queries; folded into the list it would be
     * a hundred and one for a page of twenty-five, which is why `SourceResource` publishes no counts
     * and `SourceDetailResource` exists.
     *
     * ── WHY THE CONSOLE NEEDED IT ─────────────────────────────────────────────────────────────
     *
     * `delete()` below builds a `SourceChildSummary` and writes it to the AUDIT ROW, where no client
     * can read it, so the sources list shipped a delete confirmation that could not state its
     * consequence in numbers while `kb-ui-patterns` requires exactly that. `chunk_count` is the
     * number that confirmation is asking for: it is how many vectors the deletion removes and how
     * many a rebuild would re-embed at a provider's per-token price.
     *
     * NO AUDIT ROW. §18.11 audits credential changes, configuration changes and destructive
     * operations; reading a source is none of them.
     */
    public function detail(Organization $organization, KnowledgeSource $source): SourceContentSummary
    {
        return $this->sources->contentSummary($organization->organizationId(), $source->id);
    }

    /**
     * Create one source, give it its first item, queue it, and dispatch the submission.
     *
     * ── THE SOURCE IS SUBMITTED, NOT LEFT IN `Draft` ──────────────────────────────────────────
     *
     * `Draft` means "created, never submitted; no version exists", and it is a real state the
     * schema and the factory both exercise — but it is not a state this endpoint can leave a caller
     * in, because a caller who has just handed us content has plainly submitted it. A source that
     * sat in `Draft` after an explicit create-with-content would be a console showing "nothing is
     * happening" for a document the operator believes they uploaded.
     *
     * ── THE PASTED TEXT IS PERSISTED BEFORE THE ROW IS WRITTEN ────────────────────────────────
     *
     * A `text` source has no object behind it and no URL to refetch, so if the bytes are not stored
     * they exist only in the request that carried them — and a reprocess, which is the whole point
     * of `force_nonce`, would have nothing to reprocess. They go to object storage under this
     * organization's own prefix, which `source_items_storage_key_is_tenant_scoped` enforces against
     * the ROW'S OWN tenant column rather than against the service's promise.
     *
     * WRITTEN BEFORE THE TRANSACTION, DELIBERATELY. Object storage does not participate in a
     * PostgreSQL transaction, so the only two orderings are "orphan object, no row" and "row
     * pointing at nothing". The second is a source whose ingestion fails on every attempt with
     * `error_class: storage` and needs an operator, so the orphan is chosen — and the orphan is now
     * RECOVERABLE, which it was not when this paragraph was written. It used to claim the orphan
     * was "a byte-for-byte-identical object at a content-addressed key that the next attempt
     * overwrites and a sweep can collect", and both halves were false: the key is SOURCE-scoped and
     * `$sourceId` is minted per request twenty lines below, so a retry writes a DIFFERENT key and
     * overwrites nothing, and `kb.maintenance.sweep_orphan_objects` was a docstring line in
     * `services/ai-service/app/maintenance/tasks.py`, whose `__all__` is empty. An orphan was
     * therefore permanent, in the shape `ObjectKey`'s docblock calls defect 1 — outside every
     * prefix the phase-2 purge visits, so verification certified it clean while the bytes survived.
     *
     * WHAT CLOSED IT IS THE WRITE-AHEAD LEDGER, not a change to the ordering. Every key this method
     * is about to write is reserved in `pending_source_objects` first and released after the
     * transaction commits, so a failure in between leaves a row naming the object;
     * `kb:sweep-orphan-objects` collects it once the grace window passes, after re-asking
     * `source_items` whether anything claims the key. `SourceObjectWriter::write()` carries the
     * argument in full, including why "rows first" was rejected; it is stated once there rather
     * than twice, because the ordering decision is one decision made in two places.
     *
     * ── THE UPLOADED FILES GO THROUGH THE GATE BEFORE ANY BYTE IS STORED ─────────────────────
     *
     * `UploadIntake::screen()` runs `kb-security-baseline`'s six-step gate over every part, IN
     * ORDER, and the whole batch is all-or-nothing: one refusal and nothing is written — no object,
     * no source row, no item. IT ALSO ENFORCES THE ORGANIZATION'S STORAGE QUOTA, before it touches a
     * file and again once the batch has passed; that refusal is `tenant_quota` (403) rather than an
     * `UploadRejected`, because it is a fact about the organization and not about the file. A partial success would leave a source whose name describes ten
     * documents and whose corpus holds seven, with nothing anywhere recording which three are
     * missing.
     *
     * EVERY REFUSAL WRITES `source.upload.rejected`, AND THAT IS THE POINT OF THE PAIR. Without it a
     * caller grinding at the gate with crafted files leaves no trace anywhere, because nothing was
     * written — `AuditLogger` says so at the constant. The row carries a CLOSED REASON TOKEN naming
     * which step refused, never the exception message, which is unbounded and could echo a parser's
     * reading of a hostile file into an append-only table.
     *
     * ── THE SOURCE ID IS MINTED HERE, BEFORE THE BYTES ARE WRITTEN ────────────────────────────
     *
     * Exactly like `$jobId` on the line below it, and for a stronger reason. The storage key is
     * SOURCE-SCOPED (`App\Support\Kb\ObjectKey`), so the id has to exist before the object does,
     * and the ordering that used to hold — write the object, then let the INSERT mint the id —
     * forced the key to be built out of values that existed at intake and produced a key nothing
     * could ever delete. A ULID is a value this service can mint as easily as the model can; the
     * id's ABSENCE at this point was an ordering choice, never a constraint.
     *
     * ONE GENERATION SITE, NOT TWO: the value comes from the model's own `newUniqueId()`, so the
     * format stays whatever `HasUlids` says it is (lowercase, 26 chars) and there is no second
     * definition to drift. `HasUniqueIds::setUniqueIds()` assigns only when the key is empty, so
     * passing a minted id means the trait's generator never runs for this row rather than running
     * and being overwritten.
     *
     * @param  array<int, UploadedFile>  $files  the multipart parts, keyed by the index the client
     *                                           sent them under, so a per-file 422 names `files.0`
     *                                           and the console renders it against the row the
     *                                           operator can see. Empty for every type but `file`,
     *                                           and `assertContentMatchesType()` refuses both
     *                                           mismatches rather than ignoring the field
     *
     * @throws ValidationException 422 for a body whose content does not match its type, and for a
     *                             batch any of whose files the intake gate refused
     */
    public function create(
        Organization $organization,
        NewSource $input,
        ?string $actorId = null,
        ?Request $request = null,
        array $files = [],
    ): KnowledgeSource {
        $organizationId = $organization->organizationId();

        $this->assertContentMatchesType($input, $files);

        // MINTED BEFORE THE OBJECT IS WRITTEN, because the object's key is scoped to it. See the
        // docblock: the model's own generator is asked, so there is one definition of what a
        // source id looks like and the trait never generates a competing one.
        $sourceId = (new KnowledgeSource)->newUniqueId();

        $items = match ($input->type) {
            SourceType::Text => [$this->pastedItem($organizationId, $sourceId, $input)],
            SourceType::File => $this->uploadedItems(
                $organization, $sourceId, $files, $actorId, $request,
            ),
            SourceType::Url => [new NewSourceItem(
                canonicalKey: CanonicalKey::forUrl((string) $input->originUrl),
                url: $input->originUrl,
                title: $input->name,
                displayName: null,
                // FOUR NULLS TOGETHER, which `source_items_stored_object_is_complete` requires: a
                // crawl target has no object until the crawler fetches one, and a half-populated
                // row is what that CHECK exists to refuse.
                storageKey: null,
                contentHash: null,
                mime: null,
                byteSize: null,
            )],
        };

        // MINTED BEFORE THE WRITE so it can be stamped onto every item inside the same transaction.
        $jobId = (string) Str::ulid();

        $source = $this->sources->create(
            $organizationId,
            $sourceId,
            $input,
            $actorId,
            $items,
            $jobId,
            /** @param list<SourceItem> $rows */
            function (KnowledgeSource $row, array $rows) use ($organizationId, $actorId, $request): void {
                $this->audit->record(
                    AuditLogger::SOURCE_CREATED,
                    organizationId: $organizationId,
                    actorId: $actorId,
                    details: $this->describe($row),
                    subjectType: KnowledgeSource::class,
                    subjectId: $row->id,
                    request: $request,
                );

                // ONE `source.upload.accepted` ROW PER FILE, INSIDE THE SAME TRANSACTION.
                //
                // It has to be here and it cannot be earlier: the operation is ON_FAILURE_ABORT, so
                // a failed audit write must roll the state change back, and it carries
                // `source_item_id` — a value that does not exist until these INSERTs have run. Its
                // `subject_id` is the SOURCE rather than the item, because that is what was
                // authorized and what a reader searches by; the item id is in `details`.
                //
                // KEYED ON `display_name` BEING PRESENT rather than on the source type, because
                // that column is what makes a row an upload: a paste and a crawl target both leave
                // it null, so this loop cannot emit an upload row for something that was not one
                // even if a future caller passes a mixed list.
                foreach ($rows as $item) {
                    if ($item->display_name === null) {
                        continue;
                    }

                    $this->audit->record(
                        AuditLogger::SOURCE_UPLOAD_ACCEPTED,
                        organizationId: $organizationId,
                        actorId: $actorId,
                        details: [
                            'source_item_id' => $item->id,
                            'display_name' => $item->display_name,
                            // THE SNIFFED TYPE. Recording the sniffed value is what makes the
                            // cross-check auditable after the fact — an investigator asking "what
                            // did this file actually turn out to be" has an answer that does not
                            // require re-reading the object.
                            'mime' => $item->mime,
                            'byte_size' => $item->byte_size,
                            'content_hash' => $item->content_hash,
                            // A GENERATED path under this organization's own prefix, never the
                            // uploaded filename. It names no object another tenant can reach.
                            'storage_key' => $item->storage_key,
                        ],
                        subjectType: KnowledgeSource::class,
                        subjectId: $row->id,
                        request: $request,
                    );
                }
            },
        );

        // RELEASED AFTER THE COMMIT, NEVER INSIDE IT (security finding S3). `$this->sources->create()`
        // has returned, so every object this request wrote is now named by a `source_items` row and
        // the ledger entries are stale. Releasing inside the transaction would be the one arrangement
        // that cannot work: a rollback would take the release with it (leaving rows for objects that
        // were never written is harmless) — but a commit that then failed on a later statement would
        // have discarded the ledger entry for an object nothing points at, which is the orphan
        // returning with its only witness deleted.
        $this->releaseReservations($organizationId, $sourceId);

        $this->recordStorageUsage($source);

        $this->dispatchSubmission($organizationId, $source->id, $jobId, null, $actorId);

        return $source;
    }

    /**
     * Meter the bytes this source put into object storage.
     *
     * ── AFTER THE COMMIT, AND THAT IS THE WHOLE OF THE DESIGN ─────────────────────────────────
     *
     * `UsageRecorder::record()` writes a `usage_events` row AND bumps the Valkey counter, and the
     * counter is not transactional. Inside the transaction, a later rollback would take the ledger
     * row back and leave the BUMP — which makes the counter HIGH, the one direction
     * `QuotaCounters` relies on being impossible. A high counter refuses an upload that was inside
     * its allowance, and nothing anywhere would say so. So this runs after `create()` has returned,
     * which is after COMMIT.
     *
     * ── THE COST IS A LOST EVENT, AND THAT IS RECOVERABLE BY DESIGN ──────────────────────────
     *
     * A crash between the commit and this call loses the metering: the items exist and nothing
     * charged for them. `kb:rollup-usage` closes that by re-deriving storage events from
     * `source_items` — the dedupe key is the ITEM'S OWN ULID, so a re-derivation collides with this
     * write instead of adding, which is what makes the reconciliation safe to run hourly forever.
     *
     * ── IT NEVER FAILS THE REQUEST ───────────────────────────────────────────────────────────
     *
     * Same asymmetry as `releaseReservations()` one method down, and for a stronger reason: the
     * source exists, its objects are written, the caller's work succeeded, and the only thing left
     * undone is a meter reading the hourly rollup will take anyway. Throwing here would turn a
     * healthy 201 into a 500 for a source that WAS created, and the retry it invites would create a
     * SECOND source with a second set of objects — and, this time, charge for both.
     *
     * ── `occurred_at` IS THE ITEM'S `created_at`, NOT `now()` ────────────────────────────────
     *
     * Because it is half of the dedupe identity: `usage_events_dedupe` must contain the partition
     * key, so the same item metered under two different instants would be two rows. The item row's
     * own timestamp is stable across every re-derivation.
     */
    private function recordStorageUsage(KnowledgeSource $source): void
    {
        try {
            foreach ($source->items as $item) {
                // KEYED ON `byte_size` BEING PRESENT rather than on the source type. A crawl target
                // has no object until the crawler fetches one — `storage_key`, `content_hash`,
                // `mime` and `byte_size` are four nulls together, which
                // `source_items_stored_object_is_complete` requires — so this loop cannot meter
                // bytes that do not exist yet, whatever the caller passed.
                if ($item->byte_size === null || $item->storage_key === null) {
                    continue;
                }

                // `created_at` IS NOT NULL IN THE DATABASE and Eloquent still types it nullable, so
                // the fallback is a type narrowing rather than a real branch. It falls back to NOW
                // and not to a sentinel: a row whose timestamp we somehow could not read still has
                // to be metered, and `occurred_at` only has to be STABLE across re-derivations for
                // the dedupe to work — which it is, because the next derivation reads the column.
                $createdAt = $item->created_at;

                $this->usage->recordStorageAdded(
                    $source->organization_id,
                    $item->id,
                    $item->byte_size,
                    $createdAt === null
                        ? CarbonImmutable::now('UTC')
                        : CarbonImmutable::instance($createdAt),
                );
            }
        } catch (Throwable $failure) {
            Log::warning('kb.source.usage.storage_record_failed', [
                'organization_id' => $source->organization_id,
                'source_id' => $source->id,
                'exception' => $failure::class,
            ]);
        }
    }

    /**
     * Drop this source's write-ahead ledger entries, and NEVER fail the request over it.
     *
     * ── THE ASYMMETRY WITH `reserve()` IS THE POINT ────────────────────────────────────────────
     *
     * A failed reservation fails the request, because nothing has happened yet and proceeding would
     * write an untracked object. A failed RELEASE is the opposite: the source exists, its rows are
     * committed, the caller's work succeeded, and the only thing left undone is deleting rows that
     * describe intentions which have since become facts. Throwing here would turn a healthy 201
     * into a 500 for a source that was created — and the retry it invites would create a SECOND
     * source with a second set of objects.
     *
     * AND THE LEFTOVER ROWS ARE HARMLESS, WHICH IS WHY SWALLOWING IS SAFE RATHER THAN LAZY.
     * `kb:sweep-orphan-objects` re-asks `source_items` before it deletes anything: it will find
     * these keys CLAIMED, leave the objects alone, and retire the rows. The sweep's claimed-count
     * going up is exactly what a lost release looks like from the outside.
     */
    private function releaseReservations(string $organizationId, string $sourceId): void
    {
        try {
            $this->pendingObjects->release($organizationId, $sourceId);
        } catch (Throwable $failure) {
            Log::warning('kb.source.pending_objects.release_failed', [
                'organization_id' => $organizationId,
                'source_id' => $sourceId,
                'exception' => $failure::class,
            ]);

            report($failure);
        }
    }

    /**
     * Edit one source's metadata. A PATCH: an absent field is left alone.
     *
     * NOTHING HERE RE-QUEUES ANYTHING, and that is worth stating because it looks like it should.
     * `name`, `description` and `tags` are labels; `effective_at` and `expires_at` are a retrieval
     * WINDOW, evaluated at query time against the chunk metadata the window is copied onto. None of
     * them is a component of the ingest key, so a reprocess triggered by an edit here would produce
     * the same key, dedupe against the completed run, and tell the admin "already processed" — the
     * exact silent no-op `force_nonce` exists to make impossible.
     *
     * WHICH IS PRECISELY WHY THE WINDOW HAS TO BE PROPAGATED RATHER THAN RE-DERIVED.
     * `EloquentKnowledgeSourceRepository::propagateRetrievalWindow()` carries an edited window down
     * onto this source's chunks inside the same transaction, because the reprocess that would
     * otherwise rebuild them is a no-op by construction. Read its docblock for what that does and
     * does not reach — PostgreSQL, from which Qdrant is rebuildable; not the live payload.
     *
     * @throws NotFoundHttpException when the row disappeared between the binding and the write
     */
    public function update(
        Organization $organization,
        KnowledgeSource $source,
        SourceEdit $edit,
        ?string $actorId = null,
        ?Request $request = null,
    ): KnowledgeSource {
        $organizationId = $organization->organizationId();

        $updated = $this->sources->update(
            $organizationId,
            $source->id,
            $edit,
            function (KnowledgeSource $row) use ($organizationId, $actorId, $request): void {
                $this->audit->record(
                    AuditLogger::SOURCE_UPDATED,
                    organizationId: $organizationId,
                    actorId: $actorId,
                    // The values AFTER the edit, every field on every row — including the ones this
                    // particular PATCH did not name — because a row that recorded only what changed
                    // would be unreadable next to the `created` and `deleted` rows for the same
                    // subject.
                    details: $this->describe($row),
                    subjectType: KnowledgeSource::class,
                    subjectId: $row->id,
                    request: $request,
                );
            },
        );

        if ($updated === null) {
            throw new NotFoundHttpException;
        }

        return $updated;
    }

    /**
     * Move this source to `Disabled`, keeping every vector.
     *
     * ONE COLUMN, AND IT IS THE DURABLE HALF OF C3's HEADLINE REQUIREMENT RATHER THAN THE WHOLE OF
     * IT — see the class docblock, which now says which half. `Disabled` is still not `Deleting`:
     * disabling is not a way to reclaim storage, and deleting is not a way to hide something for a
     * week.
     *
     * ── THE PAYLOAD REWRITE IS DISPATCHED, AND THE SNAPSHOT RESOLVER IS STILL PHASE D ────────
     *
     * (1) IS BUILT. `SyncSourceStatusJob` carries `source_status = disabled` to
     * `POST /internal/v1/maintenance/source-status`, which rewrites the payload term on every
     * point of this source in every collection its versions live in and returns the filtered count
     * that proves it. The dispatch is after the commit and has a compensation, below; the job's
     * own docblock explains why a failed DISABLE is deliberately not reverted.
     *
     * (2) IS NOT, AND IS NOT THIS PHASE'S. The config snapshot still owes the resolved
     * active-version set `tenant_filter()` takes as a required argument — that resolver is
     * Laravel's own and lands with the chat path. It is the mechanism that would make a disable
     * immediate WITHOUT any payload rewrite; the payload rewrite is what makes it effective in its
     * absence, and the two are complementary rather than alternatives. Nothing reads either today
     * because there is no chat path in this repository.
     *
     * @throws ValidationException 422 for a move the transition table forbids
     * @throws NotFoundHttpException when the row disappeared between the binding and the write
     */
    public function disable(
        Organization $organization,
        KnowledgeSource $source,
        ?string $actorId = null,
        ?Request $request = null,
    ): KnowledgeSource {
        $moved = $this->move(
            $organization,
            $source,
            SourceState::Disabled,
            AuditLogger::SOURCE_DISABLED,
            $actorId,
            $request,
        );

        // `revertTo: null` — a disable that never reaches the index must NOT put the row back to
        // `ready`. See `SyncSourceStatusJob`'s docblock: reverting would tell the operator the
        // source is ready, which is true of the index and the opposite of what they asked for, and
        // would discard the only durable record that they asked at all.
        $this->dispatchStatusSync(
            $organization->organizationId(),
            $moved->id,
            SourceState::Disabled->value,
            revertTo: null,
            actorId: $actorId,
        );

        return $moved;
    }

    /**
     * Put this source back into retrieval.
     *
     * ── THE TARGET FLAVOUR IS RESOLVED HERE AND IS NOT THE CALLER'S TO CHOOSE ─────────────────
     *
     * `Ready` and `ReadyWithWarnings` are identical for retrieval (§8.11: parser and OCR warnings
     * are advisory and never a retrieval predicate), and they differ in exactly one thing — whether
     * this source's live content parsed cleanly. That is a FACT ABOUT THE VERSIONS, not a
     * preference, so a client that could send either would be able to erase the only durable signal
     * that says "this document parsed badly and published anyway".
     *
     * So the request says "enabled" and this method reads the live versions to decide which of the
     * two it means.
     *
     * ── AND A SOURCE THAT HAS NEVER PUBLISHED CANNOT BE ENABLED AT ALL ────────────────────────
     *
     * `SourceState::transitionTable()` names `Failed -> Ready without a new run` as one of the four
     * transitions it refuses BY CONSTRUCTION, and `IllegalSourceTransition` says the same. It is
     * refused in ONE hop and it was reachable in TWO: `Failed -> Disabled` and `Disabled -> Ready`
     * are both edges, and `UpdateSourceStatusRequest` accepts exactly those two values. A source
     * whose only run failed therefore reached `status = ready` with `current_version_id` still
     * null — `isRetrievable()` true, the console pill green, and not one chunk behind it.
     *
     * This used to be argued away on the grounds that retrievability additionally requires an
     * active-version pointer, so the status alone cannot make the source answerable. That is true
     * of the QUERY and false of everything an operator sees: the pill, the list filter, and the bot
     * publish gate, which counts assignments rather than corpus. A state the machine documents as
     * unreachable must not be reachable by walking around it, so the enable is refused instead —
     * `POST .../sources/{source}/reprocess` is the route that turns a failed source into a ready
     * one, and it is the route that actually indexes something.
     *
     * ── THE MIRROR OF `disable()`, AND IT IS THE DIRECTION THAT GETS A COMPENSATION ──────────
     *
     * The payload rewrite is dispatched here too, with `source_status = ready`, and this is the
     * direction where a failure is repaired rather than recorded. An enable that does not reach
     * the index leaves the points carrying `disabled`: the console says ready, every question
     * about the source goes unanswered, and nothing raises — `kb-tenancy-isolation`'s
     * correct-filter-wrong-payload case. So `SyncSourceStatusJob` is given `revertTo` and puts the
     * row back to `disabled` on exhaustion, which makes the console agree with what the index will
     * actually do and makes the operator's obvious next action — enabling again — re-run the sync.
     *
     * `ready` AND NOT `$target->value` IN THE PAYLOAD. The two Ready flavours are identical for
     * retrieval (§8.11) and the indexer writes the plain `ready` on every point it indexes, so
     * `ready` is the value the index has actually held; sending `ready_with_warnings` would ask the
     * far side to write a value nothing has ever written, and it refuses exactly that.
     *
     * @throws ValidationException 422 for a move the transition table forbids
     * @throws NotFoundHttpException when the row disappeared between the binding and the write
     */
    public function enable(
        Organization $organization,
        KnowledgeSource $source,
        ?string $actorId = null,
        ?Request $request = null,
    ): KnowledgeSource {
        $target = $this->readyFlavourFor($organization, $source);

        // BEHIND THE TRANSITION TABLE, NEVER IN FRONT OF IT. A source in `deleting` or `deleted`
        // also has no active version, and it must be refused for the reason the TABLE gives — the
        // message that names both ends of the edge the caller asked for. Answering it with the
        // corpus message instead would tell an operator to press Reprocess on a row being purged.
        // So this only speaks about a move the table would otherwise allow.
        if (
            $source->status->canTransitionTo($target)
            && ! $this->sources->hasActiveVersion($organization->organizationId(), $source->id)
        ) {
            throw ValidationException::withMessages([
                'status' => 'This source has no indexed content, so enabling it would show it as '
                    .'ready while every question about it goes unanswered. Its last run either '
                    .'failed or has not published a version yet. Use Reprocess to run it again; '
                    .'it becomes ready on its own once a version indexes and verifies.',
            ]);
        }

        $moved = $this->move(
            $organization,
            $source,
            $target,
            AuditLogger::SOURCE_ENABLED,
            $actorId,
            $request,
        );

        $this->dispatchStatusSync(
            $organization->organizationId(),
            $moved->id,
            'ready',
            revertTo: SourceState::Disabled->value,
            actorId: $actorId,
        );

        return $moved;
    }

    /**
     * Ask for this source to be processed again.
     *
     * ── THE FORCE NONCE IS THE WHOLE MECHANISM ────────────────────────────────────────────────
     *
     * A resubmission of unchanged content with unchanged configuration produces an identical ingest
     * key, so `UNIQUE (source_item_id, ingest_key)` resolves it to the version that already exists
     * and the admin sees "already processed" — which is exactly right for a retry and exactly wrong
     * for a button labelled Reprocess. `force_nonce` is the one component of the key that changes
     * when nothing else did. It is minted here, echoed onto the audit row, and carried to the data
     * plane in the submission body.
     *
     * IT IS NOT A CREDENTIAL AND IT AUTHORIZES NOTHING. `AuditLogger` says so at the constant, and
     * it matters because a ULID sitting in an audit detail beside the word "nonce" invites exactly
     * the wrong redaction.
     *
     * ── AND IT IS A ULID RATHER THAN A TIMESTAMP OR A COUNTER ─────────────────────────────────
     *
     * A timestamp collides at second resolution for two operators pressing the button together, and
     * a counter needs a column. A ULID is unique, is already this schema's identifier shape, and —
     * unlike `random_bytes` — sorts in creation order, so the audit rows for three reprocesses of
     * one source read in the order they happened.
     *
     * @throws ValidationException 422 for a move the transition table forbids
     * @throws NotFoundHttpException when the row disappeared between the binding and the write
     */
    public function reprocess(
        Organization $organization,
        KnowledgeSource $source,
        ?string $actorId = null,
        ?Request $request = null,
    ): KnowledgeSource {
        $organizationId = $organization->organizationId();
        $jobId = (string) Str::ulid();
        $forceNonce = (string) Str::ulid();

        try {
            $requeued = $this->sources->requeue(
                $organizationId,
                $source->id,
                $jobId,
                function (KnowledgeSource $row, int $items) use (
                    $organizationId, $actorId, $forceNonce, $request,
                ): void {
                    $this->audit->record(
                        AuditLogger::SOURCE_REPROCESS_REQUESTED,
                        organizationId: $organizationId,
                        actorId: $actorId,
                        details: [
                            'name' => $row->name,
                            'status' => $row->status->value,
                            'force_nonce' => $forceNonce,
                            // HOW MUCH WORK WAS ASKED FOR. A reprocess of a 400-page crawl spends
                            // provider embedding tokens on every item, and this is the number that
                            // says so — counted from the rows actually claimed, inside the
                            // transaction that claimed them, so it cannot describe a different set
                            // than the one the run will walk.
                            'item_count' => $items,
                        ],
                        subjectType: KnowledgeSource::class,
                        subjectId: $row->id,
                        request: $request,
                    );
                },
            );
        } catch (IllegalSourceTransition $refused) {
            throw $this->illegal($refused);
        }

        if ($requeued === null) {
            throw new NotFoundHttpException;
        }

        $this->dispatchSubmission($organizationId, $requeued->id, $jobId, $forceNonce, $actorId);

        return $requeued;
    }

    /**
     * PHASE 1 OF THE TWO-PHASE DELETE.
     *
     * ── THIS IS NOT A HARD DELETE AND MUST NOT BECOME ONE ─────────────────────────────────────
     *
     * `kb-deletion-and-verification` splits removal into an immediate LOGICAL exclusion — which is
     * the only thing a customer experiences — and a background purge that PROVES the vectors,
     * objects and cache entries are gone. `deleted_at` is stamped and `purged_at` stays null,
     * because nothing has been proven yet, and `knowledge_sources_purge_follows_delete` refuses a
     * proof without a delete.
     *
     * PHASE 2 IS `deletion-engineer`'s, on both sides of the seam, and this method deliberately
     * dispatches nothing: a purge job dispatched from here would be a second implementation of an
     * ordering that has to be got right once. What this owes it is the state — `Deleting`, which is
     * the state the purge claims from — and the audit row with an ACTOR on it, which the purge
     * worker cannot write because there is no person behind it.
     *
     * ── THE AUDIT OPERATION IS `source.deleted` AND THE CONSTANT SAYS OTHERWISE ───────────────
     *
     * `AuditLogger::SOURCE_DELETED`'s docblock describes it as "a HARD delete of the source ROW, at
     * the end of the verified two-phase removal". There is no `source.delete.requested` operation
     * in the catalog and this change may not add one. Writing it HERE is the lesser wrong: phase 1
     * is the act a PERSON took, phase 2 has no actor, and an audit trail whose delete rows all
     * carry a null actor cannot answer the one question it is asked. The disagreement is reported
     * rather than resolved silently — `deletion-engineer` and this file must not both write a row
     * under this name.
     *
     * @throws ValidationException 422 for a move the transition table forbids
     * @throws NotFoundHttpException when the row disappeared between the binding and the write
     */
    public function delete(
        Organization $organization,
        KnowledgeSource $source,
        ?string $actorId = null,
        ?Request $request = null,
    ): KnowledgeSource {
        $organizationId = $organization->organizationId();

        try {
            $deleted = $this->sources->softDelete(
                $organizationId,
                $source->id,
                function (KnowledgeSource $row) use (
                    $organizationId, $actorId, $request,
                ): void {
                    $this->audit->record(
                        AuditLogger::SOURCE_DELETED,
                        organizationId: $organizationId,
                        actorId: $actorId,
                        // NOT `describe()`. `source.deleted`'s allow-list is deliberately
                        // NARROWER than `source.created`'s — it carries no `effective_at` and no
                        // `expires_at`, because a retrieval WINDOW is a property of a source that
                        // still answers and says nothing about a removal. Sending them anyway is
                        // not harmless: `sanitize()` drops an unlisted field and REPORTS the drop
                        // at WARNING, so every delete would emit a log line about an audit defect
                        // that is not one, and the real ones would be lost in it.
                        details: [
                            'name' => $row->name,
                            'type' => $row->type->value,
                            'status' => $row->status->value,
                            'origin_url' => $row->origin_url,
                        ] + $this->sources
                            ->childSummary($organizationId, $row->id)
                            ->toAuditDetails(),
                        subjectType: KnowledgeSource::class,
                        subjectId: $row->id,
                        request: $request,
                    );
                },
            );
        } catch (IllegalSourceTransition $refused) {
            throw $this->illegal($refused);
        }

        if ($deleted === null) {
            throw new NotFoundHttpException;
        }

        return $deleted;
    }

    /**
     * Apply one ingestion progress frame from the data plane.
     *
     * The guard, the version row, the activation transaction and the delivery counter all live in
     * the repository, because all four are one transaction under one row lock. What lives here is
     * the pair of audit rows the pointer switch owes: `source.version.activated` and, when a prior
     * version was superseded, `source.version.retired`.
     *
     * @throws ValidationException 422 when the frame names a transition the table forbids
     */
    public function applyProgress(string $organizationId, IngestionProgress $frame): IngestionApplication
    {
        try {
            return $this->sources->applyIngestionProgress(
                $organizationId,
                $frame,
                function (IngestionApplication $applied) use ($organizationId, $frame): void {
                    if (! $applied->activated || $applied->version === null) {
                        return;
                    }

                    $version = $applied->version;

                    // RETIRED FIRST, ACTIVATED SECOND, matching the order the transaction performed
                    // them. A trail that reads switch-then-retire describes an instant in which two
                    // versions were live, which the partial unique index makes impossible.
                    if ($applied->retired !== null) {
                        $this->audit->record(
                            AuditLogger::SOURCE_VERSION_RETIRED,
                            organizationId: $organizationId,
                            // NO ACTOR. The pointer switch is the pipeline's, reported by a signed
                            // internal callback; attributing it to the admin who pressed Reprocess
                            // forty minutes earlier would be a claim the trail cannot support.
                            actorId: null,
                            details: [
                                'source_item_id' => $frame->sourceItemId,
                                'version_number' => $applied->retired->version_number,
                                'status' => $applied->retired->status->value,
                                // PRESENT because this retirement WAS part of a publish. Absent
                                // when a version is simply withdrawn, and the key's absence is what
                                // carries the distinction — `sanitize()` skips a null silently.
                                'superseded_by_version_id' => $version->id,
                            ],
                            subjectType: SourceVersion::class,
                            subjectId: $applied->retired->id,
                        );
                    }

                    $this->audit->record(
                        AuditLogger::SOURCE_VERSION_ACTIVATED,
                        organizationId: $organizationId,
                        actorId: null,
                        details: [
                            'source_item_id' => $frame->sourceItemId,
                            'version_number' => $version->version_number,
                            'status' => $version->status->value,
                            'previous_version_id' => $applied->retired?->id,
                            // REPLAYABILITY: which content, which four configurations, which vector
                            // space. A sha256 hexdigest of public inputs and a
                            // provider/model/width/probe-digest string. Neither is a secret and
                            // neither identifies a person.
                            'ingest_key' => $version->ingest_key,
                            'embedding_model_version' => $version->embedding_model_version,
                            // THE VERIFIED TOTAL the data plane counted with `exact=True` — the
                            // number the whole verification gate turns on, written down where a
                            // later disagreement with the collection can be measured against it.
                            // No column holds it, on purpose: a denormalized copy would be a second
                            // number that can disagree with the first while both look
                            // authoritative.
                            'chunk_count' => $frame->chunkCount,
                        ],
                        subjectType: SourceVersion::class,
                        subjectId: $version->id,
                    );
                },
            );
        } catch (IllegalSourceTransition $refused) {
            throw $this->illegal($refused);
        }
    }

    /**
     * The shared body of `disable()` and `enable()`.
     *
     * ONE METHOD FOR BOTH so the two paths cannot drift in the one place drift would be invisible:
     * `previous_status` is read under the same row lock that writes the new value, and a second
     * implementation would eventually read it before taking the lock and produce a row naming a
     * status the source never held.
     *
     * @throws ValidationException
     * @throws NotFoundHttpException
     */
    private function move(
        Organization $organization,
        KnowledgeSource $source,
        SourceState $target,
        string $operation,
        ?string $actorId,
        ?Request $request,
    ): KnowledgeSource {
        $organizationId = $organization->organizationId();

        if ($source->status === $target) {
            // A TRANSITION IS NOT A STATE ASSERTION. A move to the state the row already holds
            // would return 200 and write an audit row describing a change that did not happen,
            // which makes the trail wrong in the one direction nobody checks it in. The same call
            // `BotService::transition()` makes, and it is keyed on `status` so a console can render
            // it against the control the operator used.
            throw ValidationException::withMessages([
                'status' => 'This source is already `'.$target->value.'`. A transition that changes '
                    .'nothing would still return 200 and would still write an audit row describing '
                    .'a change that did not happen. If the console showed a different state, it is '
                    .'looking at a stale row — re-read the source.',
            ]);
        }

        try {
            $moved = $this->sources->transition(
                $organizationId,
                $source->id,
                $target,
                // FALSE, AND IT IS NOT A SHORTCUT. The verification gate guards the two `Ready`
                // edges OUT OF `Indexing`, which is a move only the ingestion callback makes. A
                // human enabling a source comes from `Disabled`, whose `Ready` edges carry no such
                // condition — so passing true here would be claiming a verification that no
                // transition on this path is checked against, which is worse than useless.
                verified: false,
                audit: function (KnowledgeSource $row, SourceState $previous) use (
                    $organizationId, $operation, $actorId, $request,
                ): void {
                    $this->audit->record(
                        $operation,
                        organizationId: $organizationId,
                        actorId: $actorId,
                        details: [
                            'name' => $row->name,
                            'status' => $row->status->value,
                            'previous_status' => $previous->value,
                        ],
                        subjectType: KnowledgeSource::class,
                        subjectId: $row->id,
                        request: $request,
                    );
                },
            );
        } catch (IllegalSourceTransition $refused) {
            throw $this->illegal($refused);
        }

        if ($moved === null) {
            throw new NotFoundHttpException;
        }

        return $moved;
    }

    /**
     * Which of the two `Ready` flavours re-enabling this source means. See `enable()`.
     */
    private function readyFlavourFor(Organization $organization, KnowledgeSource $source): SourceState
    {
        return $this->sources->hasWarnedActiveVersion($organization->organizationId(), $source->id)
            ? SourceState::ReadyWithWarnings
            : SourceState::Ready;
    }

    /**
     * The single item behind a pasted-text source: hash the bytes, store them, describe the row.
     *
     * ── THE RAW BYTES, WHICH IS THE HASH RULE FOR AN UPLOAD ───────────────────────────────────
     *
     * A crawl hashes the NORMALIZED content instead, because raw HTML carries rotating CSRF tokens,
     * render timestamps and visitor counters, so every page of a site looks changed every night. A
     * paste is the upload case: normalization is downstream of the parser, and the parser
     * configuration is already a separate component of the ingest key.
     *
     * `display_name` IS NULL AND MUST STAY NULL. It is what makes a row an upload — the
     * `source.upload.accepted` loop keys on it rather than on the source type — and a paste has no
     * filename to record. Writing the source's name into it would put an upload row in the audit
     * trail for a document nobody uploaded.
     */
    private function pastedItem(string $organizationId, string $sourceId, NewSource $input): NewSourceItem
    {
        $content = (string) $input->content;
        $contentHash = hash('sha256', $content);

        return new NewSourceItem(
            canonicalKey: CanonicalKey::TEXT,
            url: null,
            title: $input->name,
            displayName: null,
            storageKey: $this->storeText($organizationId, $sourceId, $contentHash, $content),
            contentHash: $contentHash,
            // DERIVED AND NOT DECLARED, but derived trivially: we generated these bytes from a
            // validated UTF-8 string. `kb-security-baseline` refuses the caller's `Content-Type`;
            // there is no caller's `Content-Type` on this path at all.
            mime: self::TEXT_MIME,
            byteSize: strlen($content),
        );
    }

    /**
     * Run the intake gate over one multipart batch, store what passed, and describe the items.
     *
     * ── THE GATE FIRST, THE OBJECTS SECOND, THE ROWS THIRD, AND THE ORDER IS THE PROPERTY ────
     *
     * Nothing is written until EVERY file has passed. `kb-security-baseline`'s ordering argument is
     * about the steps within one file; this is the batch-level counterpart, and its failure mode is
     * different: a batch that stored three objects and then refused the fourth would leave three
     * orphans under a source id that never became a row, at keys no `source_items` value names, in
     * a prefix the phase-2 purge only ever visits for sources that exist. That is `ObjectKey`'s
     * defect 1 arriving by a different road — an object nothing can delete and verification will
     * certify clean over.
     *
     * THE LEDGER IS THE BACKSTOP, NOT THE MECHANISM, AND THE DISTINCTION MATTERS. Every key below
     * is reserved before it is written, so an orphan produced any other way — a database failure
     * between the last write and the commit, a SIGKILL, a 500 — is named and collectable. That is
     * NOT a licence to relax the all-or-nothing rule above: a partial batch that "the sweep will
     * clean up" still leaves the caller a 422 and the bucket a set of objects for hours, and the
     * gate is what makes the common case produce none at all.
     *
     * ── THE REJECTION ROWS ARE WRITTEN BEFORE THE 422 AND OUTSIDE ANY TRANSACTION ────────────
     *
     * `source.upload.rejected` is ON_FAILURE_LOG, and `AuditLogger` explains why in the terms of
     * its own rollback test: "there is no state change to undo. The refusal is already decided, no
     * row was written, and aborting would turn a rejected file into a 500 — both a lie to the caller
     * and still no audit row."
     *
     * @param  array<int, UploadedFile>  $files
     * @return non-empty-list<NewSourceItem>
     *
     * @throws ValidationException 422, with one entry per refused file, keyed `files.{index}`
     */
    private function uploadedItems(
        Organization $organization,
        string $sourceId,
        array $files,
        ?string $actorId,
        ?Request $request,
    ): array {
        $organizationId = $organization->organizationId();

        // THE GATE NOW TAKES THE ORGANIZATION, because it also enforces the STORAGE QUOTA — once
        // before it touches a file (is this organization already over?) and once after the batch has
        // passed (would this batch take it over?). A quota breach leaves `screen()` as a
        // `KbException` with class `tenant_quota`, NOT as an `UploadRejected`: it is a batch refusal
        // rather than a per-file one and it refused no step of the six, so it has no token in
        // `UploadRejectionReason` and no entry in the `files.{index}` map below. It propagates.
        $screening = $this->intake->screen($organization, $files);

        if ($screening->hasRejections()) {
            $errors = [];

            foreach ($screening->rejected as $index => $refusal) {
                $this->audit->record(
                    AuditLogger::SOURCE_UPLOAD_REJECTED,
                    organizationId: $organizationId,
                    actorId: $actorId,
                    details: $refusal->auditDetails(),
                    // NO SUBJECT. There is no row to point at — that is what a rejection means —
                    // and `AuditLogger::record()` refuses a type with no id, so naming the source
                    // class with a ULID that was minted and then thrown away would be a subject an
                    // investigator could never resolve.
                    request: $request,
                );

                // `files.{index}` AND NOT A FLAT `files`. `StoreSourceRequest` pins the part name as
                // `files[0]`, `files[1]`, … precisely so a per-file error renders against the row
                // the operator can see; a flat key can only produce a banner about "the upload".
                $errors['files.'.$index] = $refusal->getMessage();
            }

            throw ValidationException::withMessages($errors);
        }

        $items = [];

        foreach ($screening->inOrder() as $upload) {
            // THE KEY IS BUILT BY `ObjectKey` AND BY NOTHING ELSE. Source-scoped and
            // content-addressed: `org/{org}/sources/{source}/original/{sha256}`. The user's filename
            // is not in it, is not derivable from it, and goes to `display_name`, which is a column.
            $key = ObjectKey::originalUpload($organizationId, $sourceId, $upload->contentHash);

            // RESERVED BEFORE THE BYTES, WHICH IS THE ENTIRE ORDER (security finding S3). If this
            // process dies at any point from here until the transaction below commits, this row is
            // the ONLY thing that names the object — the key is under a source that does not exist,
            // so the phase-2 purge never visits it and deletion verification certifies it clean
            // over it. `kb:sweep-orphan-objects` collects it once the grace window passes.
            //
            // A FAILURE HERE FAILS THE REQUEST, deliberately and unlike the release below. Nothing
            // has been written yet, so the caller gets a 500 with no object stored and no row
            // created; writing the object anyway would recreate the untracked orphan this ledger
            // exists to eliminate, and would do it in the one case where we already know the
            // database is unhealthy.
            $this->pendingObjects->reserve($organizationId, $sourceId, $key);

            $this->objectWriter->write($key, $upload);

            $items[] = new NewSourceItem(
                // THE GENERATED OBJECT KEY IS THE CANONICAL KEY for an upload — the `source_items`
                // migration says so, and it is what makes `source_items_org_source_canonical` refuse
                // the same object twice inside one source.
                canonicalKey: $key,
                // NULL, and not the storage key wearing a URL's hat. `url` is what a citation links
                // to and what a human opens; an uploaded file has no such address, and putting an
                // object key there would fail `source_items_url_scheme` besides.
                url: null,
                title: $upload->displayName,
                displayName: $upload->displayName,
                storageKey: $key,
                contentHash: $upload->contentHash,
                // SNIFFED FROM CONTENT BY libmagic. Never the request's `Content-Type` and never
                // the extension.
                mime: $upload->mime,
                byteSize: $upload->byteSize,
            );
        }

        if ($items === []) {
            // UNREACHABLE THROUGH THE ONE CALLER — `assertContentMatchesType()` has already refused
            // an empty batch — and it is a guard rather than an assertion because the rule it
            // protects is structural: `KnowledgeSourceRepositoryInterface::create()` takes a
            // NON-EMPTY list, because "every source has at least one item" is the `source_items`
            // migration's rule and a source with none is a state nothing downstream can read.
            throw new RuntimeException(
                'An upload batch produced no items. Nothing has been written; a source with no item '
                    .'would be a row the pointer switch, the missing-page counter and citation '
                    .'provenance all key off and none of them could resolve.',
            );
        }

        return $items;
    }

    /**
     * Persist a pasted-text body and return its tenant-scoped storage key.
     *
     * ── THE KEY IS NOT BUILT HERE, AND THAT IS THE POINT ──────────────────────────────────────
     *
     * `App\Support\Kb\ObjectKey` owns every key this service writes — the Laravel twin
     * `seaweedfs-s3`:180 requires and did not have. This method used to interpolate its own string,
     * `org/{org}/sources/text/{hash}.txt`, and that string had two properties nobody could see from
     * here: it lived OUTSIDE the prefix the phase-2 purge sweeps, so a deleted source's pasted body
     * survived and verification certified the prefix clean over it; and it deduped ACROSS SOURCES
     * within the organization with no reference count, so deleting either of two identical pastes
     * took the other's body. Both are argued in full in `ObjectKey`'s docblock, together with the
     * departure from the skill's fixed layout that fixing them required and the ADR that is owed
     * for it.
     *
     * ── THE ORGANIZATION PREFIX IS STILL CHECKED BY THE DATABASE ──────────────────────────────
     *
     * `source_items_storage_key_is_tenant_scoped` compares the stored value against the ROW'S OWN
     * `organization_id` with a `||` concatenation, so a key built for another tenant is refused by
     * the INSERT. That check is unaffected by the new shape — it constrains the first two segments
     * and nothing after them — which is why this correction needed no migration.
     *
     * ── THE WHOLE-STRING `put()` IS THE EXCEPTION `seaweedfs-s3` NAMES, AND IT IS CORRECT HERE ──
     *
     * `seaweedfs-s3`'s Definition of done bans `Storage::put($key, $contents)` on a source object,
     * and `tests/Arch/StringLevelDoctrineTest.php` rule 6 enforces the ban over
     * `app/Services/Sources/Upload/` only. This line is outside that scope on purpose, and the
     * scope comment on the rule names this method so the exclusion reads as a decision rather than
     * as a gap. THE REASONING, so that a future reader can overturn it on its merits:
     *
     *   THERE IS NO STREAM TO STREAM FROM. The rule's hazard is an object whose size PHP does not
     *   know until it has read it — an uploaded file, arriving as a path. `$content` is a request
     *   FIELD: by the time this method exists the bytes are already a PHP string, already counted
     *   against `memory_limit`, already inside the parsed request body. `MultipartUploader` needs a
     *   stream, so using it here means `fopen('php://temp')` and writing the string into it —
     *   a COPY, which raises peak memory instead of lowering it. Streaming a value that is already
     *   resident is not streaming; it is buffering twice.
     *
     *   AND IT IS BOUNDED BEFORE IT GETS HERE. `StoreSourceRequest::MAX_TEXT_LENGTH` is 500,000
     *   CHARACTERS, applied by Laravel's `max:` — which measures a string with `mb_strlen`, so the
     *   byte ceiling is four times that at most, under 2 MB, for text that is entirely 4-byte
     *   UTF-8. An upload has no comparable bound at this layer: the per-file cap is 25 MB and ten
     *   of them can arrive at one FPM worker, which is the arithmetic the rule exists for.
     *
     * WHAT WOULD REOPEN IT: `MAX_TEXT_LENGTH` growing to a size where a copy matters, or a pasted
     * body ever arriving as anything other than a request field — a `text` source ingested from a
     * stream, say. Either one makes this a genuine buffering site and moves it inside the rule.
     */
    private function storeText(
        string $organizationId,
        string $sourceId,
        string $contentHash,
        string $content,
    ): string {
        $key = ObjectKey::originalText($organizationId, $sourceId, $contentHash);

        // THE SAME RESERVATION THE UPLOAD PATH MAKES, for the same reason. A pasted body is a
        // smaller object and an equally permanent orphan: `ObjectKey::originalText()` puts it under
        // the same source-scoped prefix, so a create that stores it and then fails to commit leaves
        // bytes nothing names and nothing sweeps. See `uploadedItems()` for the full argument.
        $this->pendingObjects->reserve($organizationId, $sourceId, $key);

        $this->objects()->put($key, $content);

        return $key;
    }

    private function objects(): Filesystem
    {
        return Storage::disk('s3');
    }

    /**
     * The `details` every `source.created`, `source.updated` and `source.deleted` row carries.
     *
     * ONE SHAPE FOR THREE OPERATIONS, ASSEMBLED HERE RATHER THAN SHARED THROUGH A CONSTANT in
     * `AuditLogger` — that file repeats each list on purpose so the three can DIVERGE, and this
     * method is a caller-side convenience that the allow-list still filters. A field added here
     * that the allow-list does not name is DROPPED and reported, which is the right direction.
     *
     * NO `description` AND NO `tags`. The first is unbounded tenant prose in an append-only table an
     * investigator has to be able to read; the second is an ARRAY, which `sanitize()` drops
     * outright, because a structure in `details` is how `$request->all()` gets in one nesting level
     * down and is also what would stop `details` json-encoding as an OBJECT, which the table CHECKs.
     *
     * @return array<string, mixed>
     */
    private function describe(KnowledgeSource $source): array
    {
        return [
            'name' => $source->name,
            'type' => $source->type->value,
            'status' => $source->status->value,
            // THE CRAWL TARGET, echoed and never fingerprinted: it is the SECURITY FACT itself
            // rather than a description of one. An investigation asks which URLs this platform was
            // told to fetch, and it asks without a candidate list to test against — which is the
            // only question a fingerprint could answer. Null on a file or text source, and a null
            // is skipped silently, so the key's absence is meaningful.
            'origin_url' => $source->origin_url,
            'effective_at' => $source->effective_at?->toIso8601String(),
            'expires_at' => $source->expires_at?->toIso8601String(),
        ];
    }

    /**
     * A body whose content does not match the type it declares.
     *
     * BOTH DIRECTIONS FOR BOTH FIELDS, and the prohibiting halves are the ones worth refusing
     * rather than ignoring: a `url` source carrying pasted prose is a caller who believes they
     * submitted text, and silently dropping the field would crawl the URL and never tell them the
     * paste went nowhere.
     *
     * ── THIS RESTATES `StoreSourceRequest`'s RULES AND IS NOT REDUNDANT ──────────────────────
     *
     * The FormRequest carries `required_if` / `prohibited_unless` on `content` and on `files`, and
     * this method is what makes the service TRUE ON ITS OWN — nothing here may assume a particular
     * caller ran a particular FormRequest. The `files` half additionally guards a structural rule
     * the FormRequest cannot state: a `file` source with no parts would reach the repository with an
     * EMPTY item list, and "every source has at least one item" is exactly the invariant the
     * `source_items` migration says must never acquire a special case.
     *
     * @param  array<int, UploadedFile>  $files
     *
     * @throws ValidationException
     */
    private function assertContentMatchesType(NewSource $input, array $files = []): void
    {
        if ($input->type === SourceType::Text && ($input->content === null || trim($input->content) === '')) {
            throw ValidationException::withMessages([
                'content' => 'A `text` source is its content: without it there is nothing to '
                    .'process, nothing to hash, and nothing a version could ever be published from.',
            ]);
        }

        if ($input->type !== SourceType::Text && $input->content !== null) {
            throw ValidationException::withMessages([
                'content' => 'Only a `text` source carries inline content. A `url` source is '
                    .'fetched from its origin, and accepting a paste alongside it would index text '
                    .'the URL does not serve while reporting the URL as the citation.',
            ]);
        }

        if ($input->type === SourceType::File && $files === []) {
            throw ValidationException::withMessages([
                'files' => 'A `file` source is its files: at least one part has to arrive with the '
                    .'request. The parts are named `files[0]`, `files[1]`, … — indexed even for a '
                    .'single file — and a request that declared `type: file` and carried none would '
                    .'otherwise create a source with no item, which is a state nothing downstream '
                    .'knows how to read.',
            ]);
        }

        if ($input->type !== SourceType::File && $files !== []) {
            throw ValidationException::withMessages([
                'files' => 'Only a `file` source carries uploaded parts. Accepting them alongside a '
                    .'`url` or `text` source would store bytes nothing would ever parse, under a '
                    .'source whose content came from somewhere else entirely.',
            ]);
        }
    }

    /**
     * Dispatch the submission. One call site, so the job's shape is decided once.
     *
     * ── THE DISPATCH IS OUTSIDE THE TRANSACTION, SO IT NEEDS A COMPENSATION ───────────────────
     *
     * By the time this runs, the source is COMMITTED at `queued` and the reprocess audit row is
     * written. A broker that is unreachable for the two seconds this takes therefore used to leave
     * a source queued for a job that does not exist — and `queued -> queued` is deliberately not an
     * edge of the transition table, so the operator's obvious repair, pressing Reprocess again,
     * 422s forever. Nothing else would ever run: `SubmitIngestionJob::failed()` is a handler for a
     * job that was never enqueued. The source was unrecoverable short of deleting it.
     *
     * `queued -> failed` IS an edge, and `failed -> queued` is the edge back, so moving the row to
     * `failed` here is both truthful and the thing that makes Reprocess work. Same shape as
     * `SubmitIngestionJob::failed()`, and the same reasoning about the audit trail: there is no
     * operation in the catalog for "the platform could not enqueue", the actor already has their
     * `source.created` or `source.reprocess.requested` row, and inventing one is not this method's
     * to invent.
     *
     * THE COMPENSATION SWALLOWS ITS OWN FAILURE AND THE ORIGINAL EXCEPTION IS RETHROWN. If the
     * database is unreachable too there is nothing left to write with, and losing the real cause to
     * a secondary error would leave the operator debugging the wrong outage.
     */
    /**
     * Hand the payload rewrite to a queued job, and compensate if the BROKER is the thing that
     * fails.
     *
     * ── TWO DIFFERENT FAILURES, AND ONLY ONE OF THEM IS THIS METHOD'S ────────────────────────
     *
     * A rewrite that runs and does not verify is `SyncSourceStatusJob::failed()`'s, after five
     * attempts, and it is handled there. What this method covers is the case where the job is
     * never enqueued at all: the status is COMMITTED and the audit row is written by the time we
     * get here, so an unreachable broker would otherwise leave a source whose row and whose index
     * disagree with nothing scheduled to reconcile them and no `failed_jobs` entry to say so —
     * `SyncSourceStatusJob::failed()` is a handler for a job that was never dispatched.
     *
     * SO THE COMPENSATION IS THE JOB'S OWN, APPLIED INLINE, and it follows the same asymmetry: an
     * enable is put back to `disabled` (which is what the index still says), and a disable is left
     * alone and rethrown. `$revertTo === null` carries that decision from the two call sites
     * rather than re-deriving it from the target, so the two places that know why cannot disagree.
     *
     * THE ORIGINAL EXCEPTION IS RETHROWN AND THE COMPENSATION SWALLOWS ITS OWN FAILURE — the same
     * shape as `dispatchSubmission()`: if the database is unreachable too, losing the real cause
     * to a secondary error leaves the operator debugging the wrong outage.
     */
    private function dispatchStatusSync(
        string $organizationId,
        string $sourceId,
        string $sourceStatus,
        ?string $revertTo,
        ?string $actorId,
    ): void {
        try {
            SyncSourceStatusJob::dispatch(
                $organizationId,
                $sourceId,
                $sourceStatus,
                $revertTo,
                $actorId,
            );
        } catch (Throwable $exception) {
            if ($revertTo !== null) {
                try {
                    $this->sources->transition(
                        $organizationId,
                        $sourceId,
                        SourceState::from($revertTo),
                        verified: false,
                        // Deliberately empty; see `dispatchSubmission()` on why there is no
                        // operation in the catalog for "the platform could not enqueue".
                        audit: static function (): void {},
                    );
                } catch (Throwable) {
                    // See the docblock: the original cause is the one worth having.
                }
            }

            throw $exception;
        }
    }

    private function dispatchSubmission(
        string $organizationId,
        string $sourceId,
        string $jobId,
        ?string $forceNonce,
        ?string $actorId,
    ): void {
        try {
            SubmitIngestionJob::dispatch($organizationId, $sourceId, $jobId, $forceNonce, $actorId);
        } catch (Throwable $exception) {
            try {
                $this->sources->transition(
                    $organizationId,
                    $sourceId,
                    SourceState::Failed,
                    verified: false,
                    // Deliberately empty; see the docblock.
                    audit: static function (): void {},
                );
            } catch (Throwable) {
                // See the docblock: the original cause is the one worth having.
            }

            throw $exception;
        }
    }

    /**
     * Convert a refused transition into the `validation` refusal the envelope publishes.
     *
     * ── `validation` AND NOT A 409 ────────────────────────────────────────────────────────────
     *
     * `kb-error-taxonomy` renders `validation` as 422, never retryable, never fallback-eligible —
     * which is exactly true of an illegal transition: no number of attempts makes `Deleted -> Ready`
     * legal. It also carries a per-field `errors` map, and there IS a field to key this on. A 409
     * renders as `internal_dependency` in this application's envelope, which would tell a client
     * that a DEPENDENCY was unwell when the request was simply wrong about the row's state.
     *
     * ── THE FIELD MESSAGE IS THE CLIENT'S, AND THE OPERATOR'S GOES TO THE LOG ─────────────────
     *
     * `errors.status` is rendered VERBATIM by `apps/web`, on the general premise that a Laravel
     * validation message is end-user copy. It carried `getMessage()` until now, which names
     * `App\Enums\SourceState::transitionTable()` — a PHP class shown to a tenant administrator.
     * `clientMessage()` is that same refusal written for the reader; the operator's sentence is
     * emitted here instead, where operators look.
     *
     * IT CANNOT RIDE ALONG AS A CHAINED EXCEPTION, which is the first thing to reach for.
     * `ValidationException::withMessages()` constructs through `new static($validator)` and that
     * constructor takes no `$previous`, so `$refused` cannot be attached to the exception that
     * replaces it. Nor does the envelope carry it some other way: `ValidationException::getMessage()`
     * is `summarize($validator)`, the FIRST field message, so the envelope's operator-facing
     * `message` is the client sentence too. Without this line the operator half exists nowhere.
     *
     * `notice` AND NOT `warning`, DELIBERATELY. Two callers reach this: an administrator acting on a
     * row that moved after their page rendered, which is ordinary traffic, and the data plane
     * sending a status a row cannot reach, which is a contract violation. Only the second deserves a
     * warning, and this method cannot tell them apart — nothing it is handed says who called. A
     * WARNING on every misclick is a line operators learn to filter, which costs more than the
     * severity buys. `NOTICE` maps to `Info2(10)` — "normal but significant" — through
     * `KbJsonFormatter::SEVERITY`, which is the honest reading of that pair.
     *
     * NO CONTEXT FIELDS. `request_id` and `operation` are on every line already (`LogContext`), and
     * `KbJsonFormatter::ALLOWED_EXTRA_FIELDS` holds no key meaning "the two ends of a refused
     * transition": `reason` belongs to the metric label allow-list, whose values are bounded by
     * construction, and a 225-combination string there would be borrowing a name that means
     * something else. The two states are in the message, which is where a free-form fact belongs.
     */
    private function illegal(IllegalSourceTransition $refused): ValidationException
    {
        Log::notice($refused->getMessage());

        return ValidationException::withMessages([
            'status' => $refused->clientMessage(),
        ]);
    }
}
