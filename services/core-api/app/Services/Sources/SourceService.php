<?php

declare(strict_types=1);

namespace App\Services\Sources;

use App\Enums\SourceState;
use App\Enums\SourceType;
use App\Jobs\SubmitIngestionJob;
use App\Models\KnowledgeSource;
use App\Models\Organization;
use App\Models\SourceItem;
use App\Models\SourceVersion;
use App\Repositories\Contracts\KnowledgeSourceRepositoryInterface;
use App\Services\Audit\AuditLogger;
use App\Services\Sources\Upload\SourceObjectWriter;
use App\Services\Sources\Upload\UploadIntake;
use App\Support\Http\ListQuery;
use App\Support\Kb\CanonicalKey;
use App\Support\Kb\ObjectKey;
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
 * THE RETRIEVAL-SCOPE HALF IS OWED. `source_status` matched positively against
 * `['ready','ready_with_warnings']` is the right mechanism, and `kb-tenancy-isolation` NN5 is why
 * that direction matters — a `match` condition is not satisfied by a point that lacks the value,
 * so a positive filter fails closed. But `source_status` is a QDRANT PAYLOAD FIELD WRITTEN AT
 * UPSERT TIME (`services/ai-service/app/ingestion/indexing/upserter.py`, which says so itself and
 * adds that "re-enabling is a payload write rather than a re-ingest"), and this path issues no
 * payload write and dispatches no job that would. The other mechanism that could carry a disable —
 * the resolved `allowed_version_ids` set Laravel is meant to compute from `bot_source_assignments`
 * joined to `knowledge_sources` and `source_versions` and ship in the config snapshot, which is
 * what makes a disable take effect AT ONCE without touching any payload — does not exist in this
 * service either. The only thing here that takes that set,
 * `SparseCorpusStatisticsRepositoryInterface`, receives it as an argument from a caller nobody has
 * written.
 *
 * IT IS LATENT AND NOT LIVE, which is why `disable()` and `enable()` carry a TODO rather than this
 * class carrying a defect: there is no chat path in this repository, so nothing can read a stale
 * payload today. It goes live with the first one, and the failure shape is the silent one — a
 * disabled source answering at normal latency with a well-formed citation and an HTTP 200.
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
     * `error_class: storage` and needs an operator, so the orphan is chosen — but ON THE STRENGTH OF
     * THAT COMPARISON ALONE, AND NOT ON THE RECOVERY PATH THIS PARAGRAPH USED TO CLAIM. It said the
     * orphan was "a byte-for-byte-identical object at a content-addressed key that the next attempt
     * overwrites and a sweep can collect", and both halves are false: the key is SOURCE-scoped and
     * `$sourceId` is minted per request twenty lines below, so a retry writes a DIFFERENT key and
     * overwrites nothing, and `kb.maintenance.sweep_orphan_objects` is a docstring line in
     * `services/ai-service/app/maintenance/tasks.py`, whose `__all__` is empty and whose
     * `TODO(unassigned)` says these tasks have no owner. An orphan on this path is permanent, and it
     * is permanent in the shape `ObjectKey`'s docblock calls defect 1 — outside every prefix the
     * phase-2 purge visits, so verification certifies it clean while the bytes survive.
     * `SourceObjectWriter::write()` carries the same correction and the `TODO(phase-c)` naming the
     * two real fixes; it is stated once there rather than twice, because the ordering decision is
     * one decision made in two places.
     *
     * ── THE UPLOADED FILES GO THROUGH THE GATE BEFORE ANY BYTE IS STORED ─────────────────────
     *
     * `UploadIntake::screen()` runs `kb-security-baseline`'s six-step gate over every part, IN
     * ORDER, and the whole batch is all-or-nothing: one refusal and nothing is written — no object,
     * no source row, no item. A partial success would leave a source whose name describes ten
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
                $organizationId, $sourceId, $files, $actorId, $request,
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

        $this->dispatchSubmission($organizationId, $source->id, $jobId, null, $actorId);

        return $source;
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
     * TODO(phase-c): NOTHING A RETRIEVAL QUERY CAN SEE IS CHANGED BY THIS METHOD, and neither piece
     * that would change one belongs to this service to build. (1) The data plane owes the
     * `set_payload` that rewrites `source_status` on this source's points — the field is written at
     * upsert in `services/ai-service/app/ingestion/indexing/upserter.py`, so the rewrite is
     * `ingestion-engineer`'s — together with the internal operation Laravel would call to ask for
     * it, which is an addition to `kb-internal-api-contracts` and is absent from
     * `InternalAiClient`. (2) The config snapshot owes the resolved active-version set that
     * `tenant_filter()` takes as a required argument and that `retrieval-engineer` consumes; that
     * resolver is Laravel's own and lands with the chat path, and it is the mechanism that makes a
     * disable immediate WITHOUT any payload rewrite. Latent while no chat path exists; live the day
     * one does.
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
        return $this->move(
            $organization,
            $source,
            SourceState::Disabled,
            AuditLogger::SOURCE_DISABLED,
            $actorId,
            $request,
        );
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
     * two it means. A source with no live version at all re-enables as `Ready`: there is nothing
     * warned about, and the state is honest — retrievability additionally requires an active-version
     * pointer, which such a source does not have, so the status alone cannot make it answerable.
     *
     * TODO(phase-c): THE MIRROR OF `disable()`'s MARKER, AND IT IS THE MORE DANGEROUS DIRECTION.
     * Re-enabling is a metadata write here and nowhere else: the same `set_payload` and the same
     * resolved active-version set are what would put this source back INTO a retrieval query, and
     * both are unwritten (`ingestion-engineer` for the payload rewrite plus its internal operation,
     * this service's own snapshot resolver for the version set, `retrieval-engineer` for the
     * consumer). A disable that does not reach the index leaves a source answering; an enable that
     * does not reach it leaves a source silently absent from its own organization's answers — the
     * failure `kb-tenancy-isolation` describes as the correct-filter-wrong-payload case.
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
        return $this->move(
            $organization,
            $source,
            $this->readyFlavourFor($organization, $source),
            AuditLogger::SOURCE_ENABLED,
            $actorId,
            $request,
        );
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
        string $organizationId,
        string $sourceId,
        array $files,
        ?string $actorId,
        ?Request $request,
    ): array {
        $screening = $this->intake->screen($files);

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
     */
    private function dispatchSubmission(
        string $organizationId,
        string $sourceId,
        string $jobId,
        ?string $forceNonce,
        ?string $actorId,
    ): void {
        SubmitIngestionJob::dispatch($organizationId, $sourceId, $jobId, $forceNonce, $actorId);
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
