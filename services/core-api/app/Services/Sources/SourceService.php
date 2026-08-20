<?php

declare(strict_types=1);

namespace App\Services\Sources;

use App\Enums\SourceState;
use App\Enums\SourceType;
use App\Jobs\SubmitIngestionJob;
use App\Models\KnowledgeSource;
use App\Models\Organization;
use App\Models\SourceVersion;
use App\Repositories\Contracts\KnowledgeSourceRepositoryInterface;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ListQuery;
use App\Support\Kb\CanonicalKey;
use App\Support\Kb\ObjectKey;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
 * ── C3's HEADLINE REQUIREMENT: DISABLE IS IMMEDIATE ───────────────────────────────────────────
 *
 * `disable()` moves the source to `SourceState::Disabled` and returns. That single column change is
 * the whole exclusion, and it is exclusion at query time rather than a purge: `source_status` is
 * matched positively against `['ready','ready_with_warnings']` in every tenant filter, so a source
 * in any other state is unreachable by construction — `kb-tenancy-isolation` NN5 spells out that a
 * `match` condition is not satisfied by a point that lacks the value, which is what makes a
 * positive filter fail closed. NOTHING IS DELETED and no job has to succeed first: every vector is
 * retained, which is what makes re-enabling a metadata write.
 *
 * The one thing that could still answer after a disable is a CACHED ANSWER, and it cannot, for a
 * reason that lives in another file: `valkey-keyspaces` keys `ans:` on a fingerprint of the
 * RESOLVED retrieval scope — the set of source versions this bot may search right now — so a
 * disable changes the key rather than requiring a purge that somebody has to remember.
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
     * pointing at nothing". The first is a byte-for-byte-identical object at a content-addressed
     * key that the next attempt overwrites and a sweep can collect; the second is a source whose
     * ingestion fails on every attempt with `error_class: storage` and needs an operator. Choose
     * the orphan.
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
     * @throws ValidationException 422 for a body whose content does not match its type
     */
    public function create(
        Organization $organization,
        NewSource $input,
        ?string $actorId = null,
        ?Request $request = null,
    ): KnowledgeSource {
        $organizationId = $organization->organizationId();

        $this->assertContentMatchesType($input);

        $storageKey = null;
        $contentHash = null;
        $mime = null;
        $byteSize = null;
        $canonicalKey = CanonicalKey::forUrl((string) $input->originUrl);

        // MINTED BEFORE THE OBJECT IS WRITTEN, because the object's key is scoped to it. See the
        // docblock: the model's own generator is asked, so there is one definition of what a
        // source id looks like and the trait never generates a competing one.
        $sourceId = (new KnowledgeSource)->newUniqueId();

        if ($input->type === SourceType::Text) {
            $content = (string) $input->content;
            // THE RAW BYTES, which is the hash rule for an upload. A crawl hashes the NORMALIZED
            // content instead, because raw HTML carries rotating CSRF tokens, render timestamps and
            // visitor counters, so every page of a site looks changed every night. A paste is the
            // upload case: normalization is downstream of the parser, and the parser configuration
            // is already a separate component of the ingest key.
            $contentHash = hash('sha256', $content);
            $byteSize = strlen($content);
            $mime = self::TEXT_MIME;
            $canonicalKey = CanonicalKey::TEXT;
            $storageKey = $this->storeText($organizationId, $sourceId, $contentHash, $content);
        }

        // MINTED BEFORE THE WRITE so it can be stamped onto the item inside the same transaction.
        $jobId = (string) Str::ulid();

        $source = $this->sources->create(
            $organizationId,
            $sourceId,
            $input,
            $actorId,
            $canonicalKey,
            $storageKey,
            $contentHash,
            $mime,
            $byteSize,
            $jobId,
            function (KnowledgeSource $row) use ($organizationId, $actorId, $request): void {
                $this->audit->record(
                    AuditLogger::SOURCE_CREATED,
                    organizationId: $organizationId,
                    actorId: $actorId,
                    details: $this->describe($row),
                    subjectType: KnowledgeSource::class,
                    subjectId: $row->id,
                    request: $request,
                );
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
     * Exclude this source from retrieval, immediately, keeping every vector.
     *
     * See the class docblock: this is C3's headline requirement and it is one column. `Disabled` is
     * not `Deleting` — disabling is not a way to reclaim storage, and deleting is not a way to hide
     * something for a week.
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
     * BOTH DIRECTIONS, and the second is the one worth refusing rather than ignoring: a `url`
     * source carrying pasted prose is a caller who believes they submitted text, and silently
     * dropping the field would crawl the URL and never tell them the paste went nowhere.
     *
     * @throws ValidationException
     */
    private function assertContentMatchesType(NewSource $input): void
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
     */
    private function illegal(IllegalSourceTransition $refused): ValidationException
    {
        return ValidationException::withMessages([
            'status' => $refused->getMessage(),
        ]);
    }
}
