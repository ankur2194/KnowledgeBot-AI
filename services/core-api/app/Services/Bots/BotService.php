<?php

declare(strict_types=1);

namespace App\Services\Bots;

use App\Enums\BotAnswerMode;
use App\Enums\BotDeletion;
use App\Enums\BotStatus;
use App\Jobs\SyncBotAccessJob;
use App\Models\Bot;
use App\Models\BotSourceAssignment;
use App\Models\Organization;
use App\Repositories\Contracts\BotRepositoryInterface;
use App\Repositories\Contracts\BotSourceAssignmentRepositoryInterface;
use App\Repositories\Contracts\ProviderConnectionRepositoryInterface;
use App\Repositories\Contracts\ProviderModelRepositoryInterface;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ListQuery;
use App\Support\Kb\PublicBotIdentifier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The organization's bots: list, create, edit, delete.
 *
 * ── WHAT A BOT ROW DECIDES, AND THEREFORE WHY EVERY WRITE HERE IS AUDITED ─────────────────────
 *
 * A bot is the RETRIEVAL SCOPE. `bot_ids` is one of the four mandatory Qdrant filter terms
 * (kb-tenancy-isolation NN3), so this row's identity is a term in every vector query the platform
 * issues on its behalf. It also names the credential that pays for its answers, the rate limits
 * that bound them, and whether an anonymous visitor may reach it at all. §18.11 requires
 * destructive operations audited; the case for auditing a RENAME is made on what the row decides
 * rather than on what it holds, exactly as it was for the provider-model catalog:
 *
 *   * `access_mode` moving from `private` to `public` makes a bot answerable with no account and no
 *     invitation, and nothing else in the system records who did that or when.
 *   * `status` moving to `published` exposes it on every channel its access mode and origin
 *     allow-list permit.
 *   * `provider_connection_id` / `provider_model_id` decide which credential is billed and which
 *     vendor sees the tenant's questions.
 *   * a DELETE is a hard delete that takes the origin allow-list, the starter questions and the
 *     fallback chain with it, and leaves the audit row as the only surviving description.
 *
 * ── NOTHING HERE TOUCHES A CREDENTIAL, AND NOTHING HERE CAN ───────────────────────────────────
 *
 * This class does not import `App\Support\Crypto\CredentialVault` and no method it calls reaches
 * one. A bot holds a REFERENCE to a connection — a ULID — and the key behind it never leaves the
 * vault, is decrypted only inside the request that uses it, and is decrypted only by
 * `InternalAiClient`. `BotResource` renders the reference and nothing else of the parent.
 *
 * ── EVERY AUDIT ROW IS WRITTEN INSIDE THE REPOSITORY'S TRANSACTION ────────────────────────────
 *
 * All three `bot.*` operations are ON_FAILURE_ABORT, so a failed audit write must roll the change
 * back. `AuditLogger` opens no transaction of its own and `DB` is arch-pinned to
 * `App\Repositories\Eloquent`, so each mutating repository method takes the audit call as a
 * REQUIRED closure and invokes it inside its own transaction. The closures below are what land
 * there.
 */
final readonly class BotService
{
    /**
     * CHECK 5 (entity status) for a transition into `published`, when the bot has no model.
     *
     * A CONSTANT BECAUSE THE SENTENCE IS THE WHOLE VALUE OF THE REFUSAL. A published bot with no
     * model does not fail at publish time — it fails at the first end-user question, as a
     * resolution error in the data plane, on a customer's website, minutes or days after the action
     * that caused it. That is the late discovery this guard exists to remove, so the message names
     * the missing thing and the endpoint that supplies it.
     */
    /**
     * The 409 a delete gets when the bot has held a conversation.
     *
     * A CONSTANT BECAUSE THE SENTENCE IS THE WHOLE VALUE OF THE REFUSAL, as it is for the three
     * publish guards below. An operator pressing "delete" has a goal — get this bot out of the way —
     * and a bare "cannot delete" leaves them looking for a workaround, which in this schema is
     * unpicking a transcript by hand. The message names the route that achieves the goal.
     *
     * A 409 AND NOT A 422, because there is no FIELD to key it on: the request is a bare DELETE.
     * That is the distinction `ProviderModelController::destroy()` draws and the same one
     * `duplicateSlug()` draws from the other side.
     */
    public const DELETE_BLOCKED_BY_CONVERSATIONS = 'This bot cannot be deleted because it has held '
        .'at least one conversation. A transcript is an audit record: it is what answers a customer '
        .'who disputes an answer, and it is what a provider invoice is reconciled against, so '
        .'deleting the bot would take that history with it. Set the bot\'s status to `archived` '
        .'instead — an archived bot answers nobody, is read-only, and keeps every conversation, '
        .'citation and provider call resolvable.';

    /** The closed token `bot.delete.refused` records, in the shape `source.upload.rejected` uses. */
    public const DELETE_REFUSED_REASON_CONVERSATIONS = 'has_conversations';

    public const PUBLISH_NEEDS_MODEL = 'This bot cannot be published because it has no provider '
        .'connection and model. A published bot is reachable by end users, and one with no model '
        .'would accept a question and fail inside the data plane rather than here — which is a '
        .'resolution error on a customer\'s website, minutes or days after the action that caused '
        .'it. Set `provider_connection_id` and `provider_model_id` first, from '
        .'GET /provider-connections/{connection}/models, and publish after that.';

    /**
     * CHECK 5 for a transition into `published` while the bot is in RAG-first mode with the escape
     * hatch still closed.
     *
     * The two fields are deliberately separate columns — the migration records why — and this is
     * the combination that makes them contradict each other. `rag_first` says "use sources first
     * and allow general model knowledge when clearly disclosed"; `allow_general_answers = false`
     * says the general half is forbidden. The result is a bot that behaves exactly like `strict`
     * while its configuration screen claims otherwise, and the only symptom is a refusal rate
     * nobody can explain from the console.
     */
    public const PUBLISH_RAG_FIRST_NEEDS_ESCAPE_HATCH = 'This bot cannot be published in '
        .'`rag_first` answer mode while `allow_general_answers` is false. The mode says general '
        .'model knowledge may be used when it is clearly disclosed; the flag is the switch that '
        .'permits it, and it is a separate field so that "RAG-first but not yet cleared" stays a '
        .'state a draft can hold. Published, the pair is a contradiction: the bot would answer '
        .'exactly like `strict` while its configuration says otherwise. Set '
        .'`allow_general_answers` to true, or set `answer_mode` to `strict`.';

    /**
     * CHECK 5 for a transition into `published` when no ENABLED source is assigned to the bot.
     *
     * The third of the three refusals `BotPolicy` names, and the one that carried a `TODO(phase-c)`
     * until `bot_source_assignments` and its endpoints existed. The message describes BOTH failure
     * shapes rather than the stricter one, because the two answer modes fail differently and only
     * one of them is silent: `strict` refuses every question, and `rag_first` — whose escape hatch
     * the refusal above has already forced open — answers every question from general model
     * knowledge, with no sources and no citations, from a product whose premise is grounded
     * answers. Neither is a state an end user should meet, and neither announces itself from the
     * console.
     */
    public const PUBLISH_NEEDS_ASSIGNED_SOURCE = 'This bot cannot be published because no enabled '
        .'knowledge source is assigned to it. A published bot is reachable by end users, and one '
        .'with no corpus does not fail at publish time: in `strict` mode it refuses every question, '
        .'which reads from the console exactly like a broken retrieval pipeline, and in `rag_first` '
        .'mode it answers every question from general model knowledge with no sources and no '
        .'citations. Assign a source first, at '
        .'POST /organizations/{organization}/bots/{bot}/source-assignments, and publish after that. '
        .'An assignment that exists but is switched off does not count: a disabled grant is what '
        .'`enabled: false` means.';

    /**
     * CHECK 5 for any write to an ARCHIVED bot, including a status change.
     *
     * `BotStatus::isEditable()` is the rule and `BotStatus` itself is where the reasoning lives:
     * an archived bot's configuration is the record of what answered, and editing it rewrites the
     * explanation of past conversations without changing the conversations. That applies to the
     * `status` column too — `Archived` is "permanently withdrawn", not "paused for longer" — so
     * there is deliberately no un-archive transition, and the message says so rather than leaving a
     * caller to discover it by trying every value.
     */
    public const ARCHIVED_IS_READ_ONLY = 'An archived bot is read-only, including its status. Its '
        .'configuration is the record of what answered the conversations it produced, and editing '
        .'it would rewrite the explanation of those conversations without changing them. Archiving '
        .'is permanent by design: `paused` is the state for a bot that is expected back. Create a '
        .'new bot, or delete this one.';

    /** SQLSTATE 23505 — unique_violation. */
    private const UNIQUE_VIOLATION = '23505';

    public function __construct(
        private BotRepositoryInterface $bots,
        private ProviderConnectionRepositoryInterface $connections,
        private ProviderModelRepositoryInterface $models,
        // THE PUBLISH GUARD'S THIRD REFUSAL, and nothing else on this class reaches it. A count
        // rather than the assignment service, deliberately: this class has no business being able
        // to CREATE a grant, and a dependency on the service would give it one.
        private BotSourceAssignmentRepositoryInterface $assignments,
        private AuditLogger $audit,
    ) {}

    /**
     * One page of this organization's bots.
     *
     * NO AUDIT ROW. §18.11 audits credential changes and destructive operations; reading a list is
     * neither, and auditing it would bury the rows that matter under one per page load — the same
     * call `ProviderModelService::list()` makes.
     *
     * @return LengthAwarePaginator<int, Bot>
     */
    public function list(Organization $organization, ListQuery $query): LengthAwarePaginator
    {
        return $this->bots->paginate($organization->organizationId(), $query);
    }

    /**
     * Create one bot, in `draft`, with a freshly minted public identifier.
     *
     * ── THE DUPLICATE SLUG IS A 422 AND NOT A 500, IN TWO LAYERS ──────────────────────────────
     *
     * `bots_org_slug_unique` is UNIQUE on (organization, slug), and a second bot with the same
     * handle would otherwise surface as SQLSTATE 23505 rendered by the error envelope as
     * `internal_dependency` / 500 — a bug report about the server for what is plainly a bad
     * request. The PRE-FLIGHT check is an org-scoped repository query for the readable message; the
     * CATCH is the race it cannot win, two administrators creating the same handle in the same
     * instant. The index is the authority and the check is the good message, which is the same
     * construction `ProviderModelService::create()` uses.
     *
     * @throws ValidationException 422 for a duplicate slug or an unusable (connection, model) pair
     */
    public function create(
        Organization $organization,
        NewBot $input,
        ?string $actorId = null,
        ?Request $request = null,
    ): Bot {
        $organizationId = $organization->organizationId();

        if ($this->bots->slugExists($organizationId, $input->slug)) {
            throw $this->duplicateSlug($input->slug);
        }

        $this->assertModelSelection(
            $organizationId,
            $input->providerConnectionId,
            $input->providerModelId,
        );

        $this->assertRepresentable(
            $input->evidenceThreshold,
            $input->evidenceThresholdScale,
            $input->collectEndUserData,
            $input->consentText,
        );

        try {
            return $this->bots->create(
                $organizationId,
                PublicBotIdentifier::mint(),
                $input,
                // A FULL CLOSURE AND NOT AN ARROW FUNCTION: `fn () => $this->record(...)` implicitly
                // RETURNS the call's value, `record()` is `void`, and the interface types the
                // callback as `Closure(Bot): void`.
                function (Bot $bot) use ($organizationId, $actorId, $request): void {
                    $this->record(AuditLogger::BOT_CREATED, $organizationId, $actorId, $bot, $request);
                },
            );
        } catch (QueryException $conflict) {
            if ($this->violates($conflict, 'bots_org_slug_unique')) {
                throw $this->duplicateSlug($input->slug);
            }

            throw $conflict;
        }
    }

    /**
     * Apply a partial edit.
     *
     * ── CHECK 5 IS EVALUATED AGAINST THE RESULTING STATE, NEVER AGAINST THE REQUEST BODY ──────
     *
     * A PATCH that sets `status: published` and nothing else must be judged against the model the
     * bot ALREADY has; a PATCH that clears `provider_model_id` on an already-published bot must be
     * judged against the status it already has. Reading either half from the request alone gets one
     * of those two wrong, and the one it gets wrong is the one that publishes a bot that cannot
     * answer. `BotEdit::statusAfter()` and `resolved()` below are the two halves of that reading.
     *
     * @throws ValidationException 422 for a duplicate slug or an unusable (connection, model) pair
     * @throws ConflictHttpException 409 for an archived bot or a publish the guard refuses
     * @throws NotFoundHttpException when the row disappeared between the binding and the write
     */
    public function update(
        Organization $organization,
        Bot $bot,
        BotEdit $edit,
        ?string $actorId = null,
        ?Request $request = null,
    ): Bot {
        $organizationId = $organization->organizationId();

        // CHECK 5, FIRST HALF: the bot's own lifecycle state. Before anything else, because an
        // archived bot refuses every field and reporting a slug collision on a row that cannot be
        // written either way would be answering the wrong question.
        if (! $bot->status->isEditable()) {
            throw new ConflictHttpException(self::ARCHIVED_IS_READ_ONLY);
        }

        $slug = $edit->valueFor('slug');

        if ($edit->names('slug') && is_string($slug)
            && $this->bots->slugExists($organizationId, $slug, $bot->id)) {
            // EXCLUDING THIS BOT'S OWN ROW, or a console that re-submits the whole form would
            // report every save as a duplicate of itself.
            throw $this->duplicateSlug($slug);
        }

        $connectionId = $this->resolved($bot, $edit, 'provider_connection_id');
        $modelId = $this->resolved($bot, $edit, 'provider_model_id');

        $this->assertModelSelection(
            $organizationId,
            is_string($connectionId) ? $connectionId : null,
            is_string($modelId) ? $modelId : null,
        );

        $this->assertRepresentable(
            $this->resolved($bot, $edit, 'evidence_threshold'),
            $this->resolved($bot, $edit, 'evidence_threshold_scale'),
            $this->resolved($bot, $edit, 'collect_end_user_data') === true,
            $this->resolved($bot, $edit, 'consent_text'),
        );

        // CHECK 5, SECOND HALF: the publish guard, on the state this edit LEAVES the bot in.
        $this->assertPublishable($bot, $edit, $connectionId, $modelId);

        try {
            $updated = $this->bots->update(
                $organizationId,
                $bot->id,
                $edit,
                function (Bot $row) use ($organizationId, $actorId, $request): void {
                    $this->record(AuditLogger::BOT_UPDATED, $organizationId, $actorId, $row, $request);
                },
            );
        } catch (QueryException $conflict) {
            if ($this->violates($conflict, 'bots_org_slug_unique')) {
                throw $this->duplicateSlug(is_string($slug) ? $slug : '');
            }

            throw $conflict;
        }

        if ($updated === null) {
            // Deleted between the route binding and the transaction. The same 404 the binding would
            // have produced, not a 500 describing a race the caller cannot act on.
            throw new NotFoundHttpException;
        }

        return $updated;
    }

    /**
     * Move one bot to a lifecycle state.
     *
     * ── IT IS `update()` WITH A ONE-COLUMN EDIT, AND THAT IS THE WHOLE DESIGN ──────────────────
     *
     * The transition endpoint exists because `status` is the one field on a bot that decides
     * whether an END USER can reach it, and two doors to that column are two places a check has to
     * be. What it must NOT become is a second implementation of the guard: `assertPublishable()` is
     * subtle — it runs on the state the write LEAVES the bot in rather than on the transition, which
     * is what makes "clear the model on an already-published bot" refuse by the same check that
     * refuses "publish a model-less draft" — and a copy of it here would be the copy that drifts.
     *
     * So this method builds a `BotEdit` naming exactly one column and hands it to `update()`. Every
     * refusal, the archived read-only rule, the row lock, the version-bump decision and the
     * `bot.updated` audit row are the same code on both paths, by construction.
     *
     * ── THE ONE THING IT ADDS: A NO-OP TRANSITION IS REFUSED ───────────────────────────────────
     *
     * `PUT status: paused` on an already-paused bot changes nothing, and letting it succeed would
     * write a `bot.updated` audit row describing an edit that did not happen — the trail wrong in
     * the one direction nobody checks it in, which is the same defect `BotController::update()`
     * refuses an empty body for. It costs strict PUT idempotency and the trade is deliberate: this
     * is a TRANSITION endpoint rather than a state assertion, and a no-op means the console was
     * looking at a stale row, which is worth saying out loud.
     *
     * A 422 KEYED ON `status` and not a 409, for the reason `duplicateSlug()` records: there IS a
     * field to key it on, so the message lands under the control the operator pressed rather than
     * as a banner about a request that is plainly about one field.
     *
     * @throws ValidationException 422 when the bot already holds this status
     * @throws ConflictHttpException 409 for an archived bot or a publish the guard refuses
     * @throws NotFoundHttpException when the row disappeared between the binding and the write
     */
    public function transition(
        Organization $organization,
        Bot $bot,
        BotStatus $status,
        ?string $actorId = null,
        ?Request $request = null,
    ): Bot {
        if ($bot->status === $status) {
            throw ValidationException::withMessages([
                'status' => 'This bot is already `'.$status->value.'`. A transition that changes '
                    .'nothing would still return 200 and would still write a `bot.updated` audit '
                    .'row describing a change that did not happen. If the console showed a '
                    .'different state, it is looking at a stale row — re-read the bot.',
            ]);
        }

        return $this->update($organization, $bot, new BotEdit(['status' => $status]), $actorId, $request);
    }

    /**
     * Hard-delete one bot and everything that exists only to describe it.
     *
     * ── WHY THIS IS A HARD DELETE AND WHAT WILL HAVE TO CHANGE ────────────────────────────────
     *
     * Nothing in the schema references a bot today except the three child tables the repository
     * removes with it, all of which are wholly owned by it: an origin allow-list, a set of starter
     * questions and a fallback chain are meaningless without the bot they belong to.
     *
     * ── WHAT THIS DELETE DOES NOT REACH YET, AND MUST, BEFORE THOSE THINGS EXIST ──────────────
     *
     * Everything below is named NOW, while nothing is orphaned, because each item becomes a silent
     * leftover the moment its producer ships and none of them raises when it is missed. Nothing
     * here is a live defect today: a bot owns no vectors until Phase C, holds no answer or session
     * state until the runtime surface exists, and ULIDs are never reused — so a stale payload term
     * or a stranded counter is unaddressable rather than dangerous. That is exactly the window in
     * which to widen the list, because after Phase C the same omission is a deleted bot whose
     * corpus is still filtered on and whose quota is still counted.
     *
     * THE QDRANT PAYLOAD IS REACHED, AND THIS IS THE ITEM THAT IS DONE. `bot_ids` is one of the
     * four mandatory filter terms (kb-tenancy-isolation), it is a LIST on each point rather than a
     * row of its own, and a deleted bot's id is removed from the points that name it by
     * `SyncBotAccessJob` -> `bot.access.sync`, dispatched below. It is a scroll and a
     * read-modify-write and never a delete-by-filter — a delete would destroy the chunks the other
     * bots assigned to those sources still answer from — and it ends in a filtered count, because
     * a count is the only evidence any of it happened (kb-deletion-and-verification).
     *
     * ORGANIZATION-WIDE AND NOT SOURCE-BY-SOURCE, and the reason is the ordering above: the
     * assignments are withdrawn inside the transaction, so by the time the dispatch happens there
     * is nothing left to enumerate. The revoke therefore addresses every point in the organization
     * carrying this bot id, which is also the only scope that catches a source whose grant was
     * withdrawn earlier and whose payload still holds the id.
     *
     * TODO(phase-c/d): the VALKEY FAMILIES, all of which key on `{org_id}:{bot_id}` and therefore
     * strand on exactly this operation — `ans:{org}:{bot}:…` (answer cache) and its `ansidx:`
     * index, `sess:{org}:{bot}:…` (chat sessions, which must be invalidated rather than left to
     * expire, or a live widget keeps talking to a bot that no longer exists), and
     * `rl:{org}:{bot}:…` (the per-bot rate-limit counters). Purge by FAMILY PREFIX from the
     * catalog, never by `SCAN MATCH` — SCAN can miss a key written mid-iteration, which is
     * precisely the read-repopulate case.
     *
     * ── CONVERSATIONS: DECIDED, AND THE DECISION IS A REFUSAL ────────────────────────────────
     *
     * This paragraph was `TODO(phase-e)` until `conversations` landed. It named three candidates and
     * predicted the answer, and the answer is the one it predicted: A BOT THAT HAS HELD A
     * CONVERSATION CANNOT BE DELETED. `conversations.bot_id` references `bots (organization_id, id)`
     * with `ON DELETE RESTRICT`, `EloquentBotRepository::delete()` counts under the row lock and
     * returns `BotDeletion::HasConversations`, and this method turns that into a 409 naming
     * archiving. `BotStatus::Archived` already existed for exactly this — its own docblock reads
     * "the row survives so conversation history and audit entries resolve".
     *
     * The two rejected alternatives, kept because the reasoning is what stops them coming back:
     * CASCADE destroys every transcript, every provider call that billed for one and every piece of
     * feedback, from a button labelled "delete bot" — the TODO's own words were that it "makes the
     * data loss invisible" — and SET NULL keeps the transcript while losing what it was a transcript
     * OF, so every §8.23 aggregate gains a bucket that appears in the totals and in no breakdown.
     * Migration 2026_08_26_002800 carries the full argument.
     *
     * THE COST IS REAL AND IS ACCEPTED: a bot can never be fully removed once it has answered
     * anybody, and the console has to say so rather than offering a delete button that 409s.
     *
     * The shape of the answer is two-phase and is already doctrine: the relational delete is
     * immediate and the derived stores are purged by a job that PROVES the removal. It is
     * `deletion-engineer`'s seam and not this file's to invent — what this file owes is the
     * enumeration above, so the job is written against a complete list rather than against
     * whatever the author remembered.
     *
     * ── THE GRANTS GET A ROW EACH, AND THAT IS NOT SYMMETRY WITH THE OTHER COLLECTIONS ───────
     *
     * `bot_source_assignments` is the FOURTH child collection this delete destroys — it landed with
     * Phase C1 and nothing wrote to it until C6, so this path was complete for exactly as long as
     * the table was empty. Adding it to the repository's cascade is what stops the first grant from
     * turning every delete of that bot into SQLSTATE 23503 rendered as a 500.
     *
     * Removing them SILENTLY would be finding L2 with more to lose. `AuditLogger` makes both
     * `bot.source_assignment.*` operations ON_FAILURE_ABORT on the explicit ground that a
     * retrieval-scope grant reaching DOCUMENTS is the allow-list's argument with higher stakes, and
     * the destruction of a grant is exactly as much a change to the retrieval scope as its creation
     * was. So each one gets its own `bot.source_assignment.deleted` row, inside the same
     * transaction, before anything is removed — and the two counts on the `bot.deleted` row are the
     * tripwire that sends a reader looking for them.
     *
     * ORDER: THE GRANTS FIRST, THEN THE BOT. Both are inside one transaction so nothing observes a
     * partial state, and the ordering is what makes a trail read forwards — the grants are
     * withdrawn and then the bot goes, which is the sequence that actually happened.
     *
     * DELETE IS NOT IDEMPOTENT HERE, ON PURPOSE. An audit row exists for the first delete, and a
     * 200 for the second would claim this actor performed a deletion the trail does not record.
     *
     * @throws ConflictHttpException 409 when the bot has held a conversation — see the block above
     * @throws NotFoundHttpException when the row disappeared between the binding and the write
     */
    public function delete(
        Organization $organization,
        Bot $bot,
        ?string $actorId = null,
        ?Request $request = null,
    ): void {
        $organizationId = $organization->organizationId();

        $outcome = $this->bots->delete(
            $organizationId,
            $bot->id,
            /** @param  list<RemovedSourceAssignment>  $assignments */
            function (Bot $row, array $assignments) use ($organizationId, $actorId, $request): void {
                foreach ($assignments as $assignment) {
                    $this->audit->record(
                        AuditLogger::BOT_SOURCE_ASSIGNMENT_DELETED,
                        organizationId: $organizationId,
                        actorId: $actorId,
                        details: $assignment->toAuditDetails(),
                        subjectType: BotSourceAssignment::class,
                        subjectId: $assignment->id,
                        request: $request,
                    );
                }

                $this->record(AuditLogger::BOT_DELETED, $organizationId, $actorId, $row, $request);
            },
        );

        match ($outcome) {
            BotDeletion::Deleted => null,

            // A TRANSCRIPT IS AN AUDIT RECORD. The repository counted under the row lock and
            // refused; nothing was changed and no `bot.deleted` row was written. The 409 names
            // archiving, because an operator pressing "delete" has a goal and "cannot delete" alone
            // leaves them looking for a workaround.
            BotDeletion::HasConversations => throw $this->refuseDelete(
                $organizationId,
                $bot,
                $actorId,
                $request,
            ),

            // Deleted between the binding and the transaction. DELETE is not idempotent here on
            // purpose: an audit row exists for the first delete, and a 200 for the second would
            // claim this actor performed a deletion the trail does not record.
            BotDeletion::Missing => throw new NotFoundHttpException,
        };

        // AFTER the commit, and outside the closure above: the closure runs INSIDE the repository's
        // transaction, and a job dispatched from there would be popped by a worker before the
        // delete landed — a revoke for a bot that still exists, followed by a delete nothing tells
        // the index about.
        //
        // BEST-EFFORT AND LOUD, never rethrown. The bot is gone and its audit row is written; a
        // 500 here would tell the operator the delete failed when it succeeded, and their repair —
        // deleting again — is a 404 by design. A bot id left in a payload is stale rather than
        // dangerous: ULIDs are never reused, so no future bot can inherit the term.
        try {
            SyncBotAccessJob::dispatch($organizationId, $bot->id, false, null, $actorId);
        } catch (Throwable $exception) {
            Log::error('bot access revoke could not be enqueued after delete', [
                'org_id' => $organizationId,
                'bot_id' => $bot->id,
                'reason' => 'revoke',
                'outcome' => 'not-enqueued',
            ]);
            report($exception);
        }
    }

    /**
     * Record the refused delete and build the 409.
     *
     * ── IT RETURNS THE EXCEPTION RATHER THAN THROWING IT ──────────────────────────────────────
     *
     * So the call site above reads `=> throw $this->refuseDelete(...)` inside the `match`, which is
     * what keeps all three outcomes in one exhaustive expression a reader can check against
     * `BotDeletion`'s three cases. A helper that threw would make the match arm `void` and the
     * exhaustiveness would stop being visible.
     *
     * ── THE AUDIT ROW IS `ON_FAILURE_LOG`, SO IT IS WRITTEN OUTSIDE ANY TRANSACTION ───────────
     *
     * `AuditLogger`'s test is "can this still be rolled back", and the answer is no because there is
     * nothing to roll back — the refusal is already decided and no row was written. Aborting on a
     * failed audit write would turn a legitimate 409 into a 500: a lie to the caller AND still no
     * audit row. That is `source.upload.rejected`'s reasoning, unmodified.
     *
     * ── `conversation_count` IS READ AFTER THE LOCK IS GONE, AND IS THEREFORE A FLOOR ─────────
     *
     * The count that CAUSED the refusal was taken under `lockForUpdate()`; this one is taken
     * afterwards, so a conversation started in between is included. The interface states it. The
     * field answers a question of MAGNITUDE — three test threads or four hundred thousand customer
     * conversations — and the error direction is upwards, so it can never under-report what was at
     * stake.
     */
    private function refuseDelete(
        string $organizationId,
        Bot $bot,
        ?string $actorId,
        ?Request $request,
    ): ConflictHttpException {
        $this->audit->record(
            AuditLogger::BOT_DELETE_REFUSED,
            organizationId: $organizationId,
            actorId: $actorId,
            details: [
                // The same surviving identification `bot.deleted` carries — and here the bot is NOT
                // gone, which is the point: a reader has a row to go and look at.
                'name' => $bot->name,
                'slug' => $bot->slug,
                'status' => $bot->status->value,
                'conversation_count' => $this->bots->conversationCount($organizationId, $bot->id),
                // A CLOSED TOKEN, never an exception message or a constraint name. See the
                // operation's docblock in AuditLogger.
                'reason' => self::DELETE_REFUSED_REASON_CONVERSATIONS,
            ],
            subjectType: Bot::class,
            subjectId: $bot->id,
            request: $request,
        );

        return new ConflictHttpException(self::DELETE_BLOCKED_BY_CONVERSATIONS);
    }

    /**
     * The value a column will HOLD after this edit is applied.
     *
     * The one operation that makes check 5 answerable on a PATCH. A column the edit does not name
     * keeps the stored value; a column it names as null is being cleared and the resulting value is
     * null, which is exactly the case `?:` and `??` would both get wrong.
     */
    private function resolved(Bot $bot, BotEdit $edit, string $column): mixed
    {
        return $edit->names($column) ? $edit->valueFor($column) : $bot->getAttribute($column);
    }

    /**
     * The resulting (connection, model) pair must be one this organization can actually use.
     *
     * ── THREE DIFFERENT WRONG PAIRS, AND ONLY ONE OF THEM IS REFUSED BY THE DATABASE ──────────
     *
     *   another tenant's connection or model   refused by `bots_connection_same_org` /
     *                                          `bots_model_same_org` as SQLSTATE 23503 — so the
     *                                          guarantee is the database's and this check only
     *                                          decides whether the caller gets a 422 or a 500.
     *   a model under a DIFFERENT connection   refused by NOTHING. The two composite keys check
     *   of the same organization                each reference against the ORGANIZATION and neither
     *                                          checks them against each other, so this check IS the
     *                                          authority. `BotFactory::usingModel()` refuses the
     *                                          same pairing in fixtures and says the same sentence.
     *   a model with no connection             refused by nothing, and it is not a database
     *                                          question at all: the row is legal and the
     *                                          configuration is meaningless, because a model row
     *                                          names a credential only through its connection.
     *
     * A 422 KEYED ON THE FIELD rather than a 409, because there IS a field to key it on — which is
     * the distinction `ProviderModelController::destroy()` draws for the case where there is not.
     *
     * @throws ValidationException
     */
    private function assertModelSelection(
        string $organizationId,
        ?string $connectionId,
        ?string $modelId,
    ): void {
        if ($connectionId === null && $modelId === null) {
            // A bot with no model chosen yet is the FIRST state every bot is in, and the two
            // columns are nullable precisely so it is expressible. The publish guard, not a NOT
            // NULL constraint, is what refuses to expose one.
            return;
        }

        if ($connectionId === null) {
            throw ValidationException::withMessages([
                'provider_connection_id' => 'A model needs the connection it is registered under. '
                    .'A `provider_models` row names a credential only through its parent '
                    .'connection, so a bot naming a model with no connection names no credential — '
                    .'and nothing in the database refuses that row, because the two ownership keys '
                    .'check each reference against the organization and neither checks them '
                    .'against each other.',
            ]);
        }

        if ($modelId === null) {
            if (! $this->connections->existsForOrg($organizationId, $connectionId)) {
                throw ValidationException::withMessages([
                    'provider_connection_id' => $this->unknownConnection(),
                ]);
            }

            return;
        }

        if (! $this->models->entryExists($organizationId, $connectionId, $modelId)) {
            // ONE MESSAGE FOR "NO SUCH ROW" AND FOR "A ROW UNDER A DIFFERENT CONNECTION", and the
            // conflation is the point: telling the caller which of the two it was would say whether
            // a ULID they do not own exists, which is the enumeration oracle the 404-at-binding
            // rule removes from every other endpoint on this surface.
            throw ValidationException::withMessages([
                'provider_model_id' => 'No model with that id is registered under that connection '
                    .'in this organization. A bot names a (connection, model) PAIR and both halves '
                    .'must agree: a model row registered under a different connection of the same '
                    .'organization is refused here, because no constraint on `bots` refuses it and '
                    .'the resulting configuration would name a credential from one account and a '
                    .'model from another. Read the pair from '
                    .'GET /provider-connections/{connection}/models.',
            ]);
        }
    }

    /**
     * The RESULTING row must be one the table will actually hold.
     *
     * ── TWO CHECK CONSTRAINTS THAT A FormRequest CAN ONLY HALF-EXPRESS, AND WHY ────────────────
     *
     * Both of these are already enforced by the database, and a violation there is SQLSTATE 23514
     * rendered by the error envelope as a 500 — a bug report about the server for what is plainly a
     * bad request, on fields the form has inputs for. The FormRequest carries the half it can see
     * (a mutual `required_with` on the evidence pair) and cannot carry the other half at all,
     * because a PATCH's outcome depends on the STORED row:
     *
     *   bots_evidence_threshold_paired                a body that clears only
     *                                                 `evidence_threshold_scale` passes every rule
     *                                                 and leaves a stored threshold with no scale.
     *   bots_consent_text_present_when_collecting     a body that sets `collect_end_user_data` to
     *                                                 true passes every rule and leaves a bot
     *                                                 collecting end-user data with no disclosure —
     *                                                 while `required_if_accepted` would have been
     *                                                 WRONG in the other direction, refusing the
     *                                                 same edit on a bot that already carries one.
     *
     * So the whole of both checks lives here, evaluated against the resulting values on BOTH paths,
     * and the create path uses it too rather than relying on a rule that is only correct there. One
     * statement of each rule, correct everywhere it is reached.
     *
     * @throws ValidationException 422, keyed on the field the caller can act on
     */
    private function assertRepresentable(
        mixed $evidenceThreshold,
        mixed $evidenceThresholdScale,
        bool $collectEndUserData,
        mixed $consentText,
    ): void {
        $hasThreshold = $evidenceThreshold !== null;
        $hasScale = $evidenceThresholdScale !== null;

        if ($hasThreshold !== $hasScale) {
            // KEYED ON THE MISSING HALF, so the message lands under the input the operator has to
            // fill in rather than under the one they just changed.
            throw ValidationException::withMessages([
                $hasThreshold ? 'evidence_threshold_scale' : 'evidence_threshold' => 'An evidence threshold and its scale are stored together or not at all, and '
                    .'this edit would leave one without the other. The same float is an unbounded '
                    .'logit on one provider and a bounded relevance score on another, so a number '
                    .'with no scale is not a number — it is three different instructions wearing '
                    .'the same value. Send both, or clear both.',
            ]);
        }

        if ($collectEndUserData && ! is_string($consentText)) {
            throw ValidationException::withMessages([
                'consent_text' => 'A bot that collects end-user data needs the consent text that '
                    .'discloses it. The disclosure is what the chat surface renders before the '
                    .'first message, so collecting without one is not a configuration this platform '
                    .'will store. If the bot already has a disclosure, this edit is clearing it '
                    .'while collection stays on.',
            ]);
        }
    }

    /**
     * The publish guard: CHECK 5 for a transition into, or a write to, a bot that will be
     * `published`.
     *
     * ── IT RUNS ON THE RESULTING STATUS, NOT ON A TRANSITION ──────────────────────────────────
     *
     * A PATCH that CLEARS `provider_model_id` on an already-published bot is exactly as dangerous
     * as one that publishes a model-less draft, and a transition-only guard misses it entirely
     * while looking correct. So the question is "will this bot be published when this write
     * commits", and the answer is checked against the resulting configuration.
     *
     * ── ALL THREE REFUSALS ARE HERE NOW ───────────────────────────────────────────────────────
     *
     * `BotPolicy`'s docblock names three: no model, no ASSIGNED SOURCE, and `allow_general_answers`
     * false in RAG-first mode. The middle one carried a `TODO(phase-c)` because
     * `bot_source_assignments` did not exist, so a check for it would either be a no-op that read
     * as a check or a query against a table that was not there. The table landed with Phase C1 and
     * the endpoints that write it landed with C6, so the refusal is implemented and the marker is
     * gone.
     *
     * IT COUNTS **ENABLED** ASSIGNMENTS AND NOT ASSIGNMENTS. A disabled row grants nothing — that
     * is the whole of what the per-assignment off switch means — so a bot whose every grant is
     * switched off has exactly as much corpus as one with none, and a check that counted rows would
     * pass on precisely the configuration it exists to refuse.
     *
     * IT IS NOT GATED ON `answer_mode`, AND THE REASON IS WORTH STATING BECAUSE THE OBVIOUS READING
     * OF THE OLD MARKER WAS NARROWER. That marker said a published bot with no assigned source
     * *"answers nothing and refuses every question"*, which is exactly true in `strict` mode and NOT
     * true in `rag_first` — where the escape hatch the refusal above already forces open lets the
     * model answer from general knowledge. So the two modes fail differently: `strict` refuses
     * everything, and `rag_first` answers everything ungrounded, with no citations, from a product
     * whose entire premise is source-grounded answers. Both are configurations an operator reaches
     * by accident and neither is one an end user should meet, so the guard refuses both and
     * `PUBLISH_NEEDS_ASSIGNED_SOURCE` describes each outcome rather than claiming the stricter one
     * for both.
     *
     * WHAT IT DOES NOT PROMISE: that the bot can actually retrieve anything. Reachability is the AND
     * of the organization, the grant's `enabled`, the source's status and the item's active-version
     * pointer, and this guard sees only the second. A bot published against a source that is still
     * ingesting is a legitimate state — the operator uploads, assigns and publishes in one sitting —
     * and the guard runs on the resulting configuration AT WRITE TIME, not continuously: a source
     * disabled or deleted after publication is not re-checked here, and nothing in this repository
     * re-checks it anywhere else either.
     *
     * `access_mode` IS ALSO NOT GUARDED, and that too is deliberate — but it is the one omission a
     * later reader is most likely to mistake for a check that exists. `published` + `public` is
     * exactly what makes a bot answerable ANONYMOUSLY, and this guard never looks at it: publishing
     * a public bot before its origin allow-list has any rows is a legitimate intermediate state,
     * and hosted chat serves it correctly. The consequence lands on the runtime surface instead,
     * and `BotDomainStatus::permitsEmbedding()` carries it: an EMPTY `bot_domains` list must deny
     * every origin rather than read as "unrestricted".
     *
     * `testing` is NOT guarded, and that is a decision rather than an oversight: it is reachable
     * only from the admin playground by a member of the owning organization, who is the person
     * configuring the bot and is the right audience for a data-plane resolution error. The guard
     * exists to stop an END USER meeting one.
     *
     * @throws ConflictHttpException
     */
    private function assertPublishable(
        Bot $bot,
        BotEdit $edit,
        mixed $connectionId,
        mixed $modelId,
    ): void {
        if ($edit->statusAfter($bot->status) !== BotStatus::Published) {
            return;
        }

        if (! is_string($connectionId) || ! is_string($modelId)) {
            throw new ConflictHttpException(self::PUBLISH_NEEDS_MODEL);
        }

        $answerMode = $this->resolved($bot, $edit, 'answer_mode');
        $allowGeneral = $this->resolved($bot, $edit, 'allow_general_answers');

        if ($answerMode === BotAnswerMode::RagFirst && $allowGeneral !== true) {
            throw new ConflictHttpException(self::PUBLISH_RAG_FIRST_NEEDS_ESCAPE_HATCH);
        }

        // THE THIRD REFUSAL. Read from the database rather than from a relation the caller happened
        // to have loaded, and counted with both tenant predicates as required positional arguments
        // — the grant is not a property of the `Bot` object in hand, and `Model::shouldBeStrict()`
        // would refuse the lazy load that pretending otherwise requires.
        if ($this->assignments->countEnabledForBot($bot->organizationId(), $bot->id) === 0) {
            throw new ConflictHttpException(self::PUBLISH_NEEDS_ASSIGNED_SOURCE);
        }
    }

    /**
     * One audit row describing a bot.
     *
     * ── THE DETAILS ARE BUILT FROM THE PERSISTED ROW, NEVER FROM REQUEST INPUT ────────────────
     *
     * Which is what makes "nothing credential-shaped can appear here" a property of the code rather
     * than of the caller's discipline. A `Bot` has no credential to offer: it holds a connection
     * ULID and the key behind it is not reachable from this object.
     *
     * The child summary at the end is read from the DATABASE for the same reason, through
     * `BotRepositoryInterface::childSummary()` — never from a relation the caller happened to have
     * loaded, which would report whatever was true when the request started rather than what is
     * true inside the transaction that is about to destroy it.
     *
     * ── WHAT IS DELIBERATELY ABSENT, AND IT IS MOSTLY PROSE ───────────────────────────────────
     *
     * `system_instruction` and `answer_style_instruction` are absent and their absence is the one
     * worth stating: they are the bot's operator-authored PROMPT, they are unbounded tenant text,
     * and echoing one wholesale into an append-only table puts a system instruction — the exact
     * string a prompt-injection review is about — into a store nobody redacts and everybody
     * exports. `description`, `welcome_message`, `placeholder_text` and `consent_text` are absent
     * for the weaker version of the same reason: they are prose that decides nothing, and an audit
     * trail that carries them is one an investigator has to page through.
     *
     * `theme` is absent because it is an ARRAY, which `AuditLogger::sanitize()` drops outright — a
     * structure in `details` is how `$request->all()` gets in one nesting level down, and it is
     * also what would stop `details` json-encoding as an OBJECT, which the table CHECKs. It decides
     * nothing that matters to an investigation either way.
     *
     * `public_bot_id` is absent DELIBERATELY and it is the one a reviewer will ask about. It is not
     * a secret — it is printed into the customer's own page source — but it is a token-shaped
     * string that identifies a bot on the unauthenticated surfaces, and the trail has no question
     * it answers that `name` and `slug` do not. An append-only table is the wrong place to
     * accumulate an identifier whose only use is addressing something anonymously.
     *
     * A NULL IS SKIPPED BY THE SANITIZER WITHOUT BEING REPORTED, so an unconfigured bot simply
     * omits `provider_connection_id`, the evidence pair and the three limits rather than writing
     * nulls or producing a warning on every write.
     */
    private function record(
        string $operation,
        string $organizationId,
        ?string $actorId,
        Bot $bot,
        ?Request $request,
    ): void {
        $this->audit->record(
            $operation,
            organizationId: $organizationId,
            actorId: $actorId,
            details: [
                // A HARD DELETE LEAVES THIS ROW AS THE ONLY DESCRIPTION OF THE BOT, and
                // `subject_id` then resolves to nothing — so `name` and `slug` are load-bearing
                // here rather than decorative. Both are tenant-controlled free text, bounded by
                // MAX_VALUE_LENGTH and passing the shape backstop like any other echoed string.
                //
                // AND BECAUSE THEY ARE LOAD-BEARING, ONE DEGRADATION IS WORTH NAMING: a LEGAL slug
                // can match the vendor-key pattern the log redactor and the audit sanitizer both
                // carry — `sk-` followed by twelve characters is `sk-abcdefghijkl`, which is a
                // valid slug — so such a value is FINGERPRINTED rather than echoed. On a
                // `bot.deleted` row that can reduce the bot's only surviving description to
                // `name_fingerprint` / `slug_fingerprint` and a set of ids — and a whole-value
                // match leaves no `_redacted` sibling either, because the safe rendering would be
                // the bare marker. This is tenant self-harm (the operator chose the
                // slug), the degradation is the designed one (a fingerprint still answers "was it
                // THIS bot", which is the question an investigation asks), and the alternative —
                // exempting this field from the shape backstop — is how the backstop stops being
                // one. Named, not fixed.
                'name' => $bot->name,
                'slug' => $bot->slug,

                // THE THREE FIELDS THAT DECIDE WHO CAN REACH THIS BOT. "Who made this bot
                // answerable by the internet, and when" is exactly the question an incident asks,
                // and without these the trail answers it with the `created` row from months
                // earlier.
                'status' => $bot->status->value,
                'access_mode' => $bot->access_mode->value,
                'answer_mode' => $bot->answer_mode->value,
                'allow_general_answers' => $bot->allow_general_answers,

                // WHICH CREDENTIAL IS BILLED AND WHICH VENDOR SEES THE TENANT'S QUESTIONS. A
                // reference, never a key: the ULID resolves to a `provider_connections` row whose
                // credential is envelope-encrypted and is not reachable from this object.
                'provider_connection_id' => $bot->provider_connection_id,
                'provider_model_id' => $bot->provider_model_id,

                // THE RETRIEVAL CONFIGURATION AND THE VERSION THAT MAKES IT REPLAYABLE. A trace
                // without the version cannot be replayed and is worthless to the §21.5 regression
                // gate; recording the version beside the knobs is what lets an investigation ask
                // "which configuration was version 7" of a table that survives the row.
                'dense_top_k' => $bot->dense_top_k,
                'sparse_top_k' => $bot->sparse_top_k,
                'rerank_candidates' => $bot->rerank_candidates,
                'rerank_retain' => $bot->rerank_retain,
                // A FLOAT, kept by sanitize()'s `is_float()` path — which refuses NAN and INF,
                // because neither is representable in JSON and either would fail the insert and
                // take the whole audit row with it.
                'evidence_threshold' => $bot->evidence_threshold,
                // The scale is meaningless apart from the number and vice versa; both or neither,
                // matching `bots_evidence_threshold_paired`.
                'evidence_threshold_scale' => $bot->evidence_threshold_scale?->value,
                'retrieval_configuration_version' => $bot->retrieval_configuration_version,

                // THE LIMITS AND THE RETENTION OVERRIDE. Integers, so sanitize() keeps them by the
                // `is_int()` path and a value of 0 would record as 0 rather than being skipped the
                // way an empty string is — although the CHECK constraints refuse 0 on all three.
                'rate_limit_per_minute' => $bot->rate_limit_per_minute,
                'rate_limit_per_day' => $bot->rate_limit_per_day,
                'retention_days' => $bot->retention_days,

                // A COMPLIANCE FLAG AND THEREFORE AN AUDIT FIELD. The consent TEXT it requires is
                // not echoed — it is prose, and `bots_consent_text_present_when_collecting` already
                // guarantees it exists whenever this is true.
                'collect_end_user_data' => $bot->collect_end_user_data,
                // ── THE THREE CHILD COLLECTIONS. THIS IS FINDING L2. ──────────────────────────
                //
                // A hard delete takes the origin allow-list, the starter questions and the fallback
                // chain with it, and `bot_domains` justifies its own ON DELETE RESTRICT by saying a
                // security review may later need to RECONSTRUCT the allow-list. Until this line the
                // trail could not: `bot.deleted` described the bot in full and said nothing about
                // what it permitted. `BotChildSummary::toAuditDetails()` decides what may be
                // recorded and states why the origins are echoed while the questions are only
                // counted; `AuditLogger`'s allow-list is what enforces it.
                //
                // READ THROUGH THE REPOSITORY AND FROM INSIDE THE AUDIT CLOSURE, which is what
                // makes the numbers true: on the delete path this closure runs BEFORE the children
                // are removed, so the read sees the list that is about to be destroyed. A read
                // taken anywhere else would record zeroes for exactly the row that needs them most.
                ...$this->bots->childSummary($organizationId, $bot->id)->toAuditDetails(),
            ],
            subjectType: Bot::class,
            subjectId: $bot->id,
            request: $request,
        );
    }

    /**
     * The 422 a duplicate slug produces, keyed on the field the form renders.
     *
     * KEYED ON `slug` AND NOT RAISED AS A BARE 409, because there IS a field to key it on. The
     * SPA's `applyServerErrors` puts this under the input the operator typed into, which is where
     * it belongs; a 409 would surface as a banner about a request that is plainly about one field.
     */
    private function duplicateSlug(string $slug): ValidationException
    {
        return ValidationException::withMessages([
            'slug' => 'This organization already has a bot with the handle "'.$slug.'". A slug is '
                .'unique per organization — it is what an operator addresses a bot by in the '
                .'console, so two bots sharing one would make every such reference ambiguous. '
                .'Another organization using the same handle is not a conflict and does not reach '
                .'this message.',
        ]);
    }

    private function unknownConnection(): string
    {
        return 'No provider connection with that id exists in this organization. A bot may only '
            .'name a credential its own organization stored — the composite foreign key '
            .'`bots_connection_same_org` refuses anything else at the database, and this message '
            .'is what turns that refusal into something a form can show. A foreign id and an '
            .'unknown id produce the same message deliberately.';
    }

    private function violates(QueryException $exception, string $constraint): bool
    {
        return $exception->getCode() === self::UNIQUE_VIOLATION
            && str_contains($exception->getMessage(), $constraint);
    }
}
