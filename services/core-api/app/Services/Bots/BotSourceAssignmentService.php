<?php

declare(strict_types=1);

namespace App\Services\Bots;

use App\Enums\SourceState;
use App\Models\Bot;
use App\Models\BotSourceAssignment;
use App\Models\KnowledgeSource;
use App\Models\Organization;
use App\Repositories\Contracts\BotSourceAssignmentRepositoryInterface;
use App\Repositories\Contracts\KnowledgeSourceRepositoryInterface;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ListQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Which knowledge sources one bot may answer from: list, grant, withdraw.
 *
 * ── WHAT A ROW HERE DECIDES ───────────────────────────────────────────────────────────────────
 *
 * `bot_ids` is one of the four mandatory Qdrant filter terms (`kb-tenancy-isolation` NN3) and it is
 * resolved from this table, so an assignment is not a preference — it is the statement that makes a
 * corpus reachable from a bot. Every downstream check AGREES with it, because it has been told that
 * this source belongs to that bot. There is no later layer that catches a bad row.
 *
 * ── THE CROSS-ORGANIZATION ROW IS REFUSED TWICE, AND THE TWO REFUSALS ARE NOT THE SAME ───────
 *
 * This is the one row in the schema that can span two organizations, and both halves of the guard
 * are real and are asserted separately:
 *
 *   THE SERVICE   `add()` resolves `source_id` through
 *                 `KnowledgeSourceRepositoryInterface::find($organizationId, …)`, whose organization
 *                 predicate is a required positional argument. A source id belonging to another
 *                 organization resolves to null and becomes a 404 — byte-identical to a path with
 *                 no route — so the row is never attempted and the caller learns nothing about
 *                 whether that id exists.
 *   THE SCHEMA    `bot_source_assignments_bot_same_org` and `bot_source_assignments_source_same_org`
 *                 refuse the row itself. They are NOT a duplicate of the check above: an
 *                 authorization check cannot substitute for them, because a caller really can be a
 *                 legitimate admin of the organization whose bot is named, and any writer that
 *                 never ran this service — a repair script, a seeder, a console command, an
 *                 ingestion callback — reaches the table with the constraints as its only guard.
 *
 * `KnowledgeSourcePolicy::assign()` says the same thing from the policy side: *"NEITHER CHECK IS
 * THE TENANCY GUARD."* Neither is this service. The constraints are.
 *
 * ── THERE IS NO UPDATE, AND THAT IS A DECISION RATHER THAN AN OMISSION ───────────────────────
 *
 * `priority` and `enabled` are the only two things about a row an operator would edit, and there is
 * no endpoint that edits them. The reason is the audit catalog: `AuditLogger` defines
 * `bot.source_assignment.created` and `bot.source_assignment.deleted` and NOTHING ELSE, both
 * `ON_FAILURE_ABORT`, and §18.11 requires a configuration change to be audited. A PATCH would
 * either write no row — a retrieval-scope change with no record, which is the finding these two
 * operations exist to close — or invent an operation this file has no business inventing. So a
 * change of priority or of the off switch is a withdraw and a re-grant, and the trail then says
 * both things happened. It is the same call `BotDomainController` makes about an immutable
 * `origin`, and it is stated here rather than left as a missing verb.
 *
 * ── NOTHING HERE TOUCHES A CREDENTIAL, AND NOTHING HERE CAN ───────────────────────────────────
 *
 * This class does not import `App\Support\Crypto\CredentialVault` and no method it calls reaches
 * one. An assignment holds two ULIDs, an integer and a boolean.
 *
 * ── EVERY AUDIT ROW IS WRITTEN INSIDE THE REPOSITORY'S TRANSACTION ────────────────────────────
 *
 * Both operations are `ON_FAILURE_ABORT`, so a failed audit write must roll the grant back.
 * `AuditLogger` opens no transaction of its own and `DB` is arch-pinned to
 * `App\Repositories\Eloquent`, so each mutating repository method takes the audit call as a
 * REQUIRED closure and invokes it inside its own transaction. The closures below are what land
 * there.
 */
final readonly class BotSourceAssignmentService
{
    /**
     * How many sources one bot may be assigned.
     *
     * ── A BOUND, AND A DELIBERATELY GENEROUS ONE ──────────────────────────────────────────────
     *
     * A source is the ADMIN'S UNIT OF INTENT, not a document: one crawl of a four-hundred-page site
     * is ONE source, and so is a batch upload. So a bot with a handful of sources is the ordinary
     * shape and two hundred is far above every real deployment. What the cap actually bounds is the
     * resolution query behind every chat turn — the live version set is built from these rows — and
     * the `bot.deleted` path, which now writes one audit row per grant inside a single transaction.
     *
     * IT IS NOT A LICENCE TO PAGINATE AROUND. The list endpoint paginates regardless, because
     * `ListQuery::MAX_PER_PAGE` is 100 and a cap above it would otherwise make "the whole
     * assignment list" a two-request operation clients would get wrong.
     */
    public const MAX_PER_BOT = 200;

    /**
     * The states a source may NOT be granted from.
     *
     * CHECK 5, AND IT IS ABOUT THE SOURCE RATHER THAN THE ORGANIZATION. `Deleting` and `Deleted`
     * are the two halves of a removal: the first is phase 1, already excluded from retrieval and
     * with a purge in flight, and the second is a purge that has been VERIFIED. Granting a bot
     * access to either is granting access to something that is being destroyed — the row would be
     * legal, the composite keys would pass, and the console would show a bot pointed at a document
     * nobody can retrieve, which reads as a broken pipeline.
     *
     * EVERY OTHER STATE IS ALLOWED, INCLUDING `Draft`, `Queued`, THE SIX PROCESSING STATES AND
     * `Failed`. Assigning a source while it is still ingesting is the ordinary console flow — the
     * operator uploads and assigns in one sitting — and the assignment grants nothing until the
     * source has an active version anyway, because the version pointer is a separate one of the
     * four filter terms. Refusing it would force the operator to come back later for no gain.
     *
     * @var list<SourceState>
     */
    private const UNASSIGNABLE = [SourceState::Deleting, SourceState::Deleted];

    /** SQLSTATE 23505 — unique_violation. */
    private const UNIQUE_VIOLATION = '23505';

    /** SQLSTATE 23503 — foreign_key_violation. */
    private const FOREIGN_KEY_VIOLATION = '23503';

    public function __construct(
        private BotSourceAssignmentRepositoryInterface $assignments,
        private KnowledgeSourceRepositoryInterface $sources,
        private AuditLogger $audit,
    ) {}

    /**
     * One page of the sources this bot may answer from.
     *
     * NO AUDIT ROW. §18.11 audits credential changes, configuration changes and destructive
     * operations; reading a list is none of them, and auditing it would bury the rows that matter
     * under one per page load — the same call `BotDomainService::list()` makes.
     *
     * @return LengthAwarePaginator<int, BotSourceAssignment>
     */
    public function list(Organization $organization, Bot $bot, ListQuery $query): LengthAwarePaginator
    {
        return $this->assignments->paginate($organization->organizationId(), $bot->id, $query);
    }

    /**
     * Grant this bot access to one source.
     *
     * ── THE SOURCE IS RESOLVED BEFORE ANYTHING ELSE, AND THE 404 IS THE POINT ─────────────────
     *
     * `find()` carries the organization as a required positional argument, so a `source_id` naming
     * another organization's row resolves to null. That is a 404 and not a 422: a validation error
     * saying "no such source" is an existence oracle over every customer's document ids, and a 422
     * would also be the wrong shape — the caller addressed a resource that, as far as they are
     * entitled to know, does not exist.
     *
     * ── THE DUPLICATE IS A 422 AND NOT A 500, IN TWO LAYERS ──────────────────────────────────
     *
     * `bot_source_assignments_org_bot_source` is UNIQUE and a second row for one pair would surface
     * as SQLSTATE 23505 rendered as a 500 for what is plainly a bad request. The pre-flight check
     * is the readable message; the catch is the race it cannot win, two administrators pressing the
     * same button in the same instant. The index is the authority either way, and this is the same
     * construction `BotDomainService::add()` uses.
     *
     * ── AND 23503 IS CAUGHT TOO, ON A PATH THAT SHOULD NEVER REACH IT ────────────────────────
     *
     * The composite foreign keys can only fire here if the source resolved above stopped belonging
     * to this organization between the read and the insert. Nothing in this application can do that
     * — `organization_id` is outside every `$fillable` and no endpoint writes it — so this catch is
     * expected to be unreachable. It exists because the ONE failure on this surface that must never
     * be rendered as an opaque 500 is the cross-tenant refusal: a 500 there reads as a server bug
     * and gets retried, and a reviewer reading the log learns nothing about which guard fired.
     *
     * @throws NotFoundHttpException when `source_id` names no source of THIS organization
     * @throws ValidationException 422 for a duplicate, a full list, or a source being deleted
     */
    public function add(
        Organization $organization,
        Bot $bot,
        NewSourceAssignment $input,
        ?string $actorId = null,
        ?Request $request = null,
    ): BotSourceAssignment {
        $organizationId = $organization->organizationId();

        $source = $this->sources->find($organizationId, $input->sourceId);

        if ($source === null) {
            throw new NotFoundHttpException;
        }

        if (in_array($source->status, self::UNASSIGNABLE, true) || $source->deleted_at !== null) {
            // BOTH TERMS, because they are two different facts that usually agree. `status` is the
            // lifecycle position and `deleted_at` is the moment retrieval stopped; a source that
            // has been purged carries `Deleted` with a `deleted_at` from long before, and a row
            // mid-transition could legitimately carry one without the other for the width of a
            // transaction. Requiring both to be clear is the conservative reading, and the
            // conservative reading is the right one for a grant.
            throw ValidationException::withMessages([
                'source_id' => 'This source is being removed and cannot be assigned to a bot. '
                    .'Deletion is two-phase: the source stopped being retrievable the moment it '
                    .'was deleted, and the purge that proves its vectors, objects and cache '
                    .'entries are gone may still be running. A grant to it would be a bot pointed '
                    .'at a document nobody can retrieve, which is indistinguishable from a broken '
                    .'retrieval pipeline from the console.',
            ]);
        }

        if ($this->assignments->existsFor($organizationId, $bot->id, $source->id)) {
            throw $this->duplicate($source);
        }

        if ($this->assignments->countForBot($organizationId, $bot->id) >= self::MAX_PER_BOT) {
            throw ValidationException::withMessages([
                'source_id' => 'This bot already has the maximum of '.self::MAX_PER_BOT.' assigned '
                    .'sources. A source is the unit of INTENT rather than a document — one crawl '
                    .'of a whole site is one source, and so is a batch upload — so the cap is far '
                    .'above every real deployment. Remove an assignment it no longer needs, or '
                    .'consolidate several uploads into one source.',
            ]);
        }

        try {
            return $this->assignments->create(
                $organizationId,
                $bot->id,
                $source->id,
                $input->priority,
                $input->enabled,
                // A FULL CLOSURE AND NOT AN ARROW FUNCTION: `fn () => $this->record(…)` implicitly
                // RETURNS the call's value, `record()` is `void`, and the interface types the
                // callback as `Closure(BotSourceAssignment): void`.
                function (BotSourceAssignment $row) use ($organizationId, $source, $actorId, $request): void {
                    $this->record(
                        AuditLogger::BOT_SOURCE_ASSIGNMENT_CREATED,
                        $organizationId,
                        $actorId,
                        $row,
                        $source->name,
                        $request,
                    );
                },
            );
        } catch (QueryException $conflict) {
            if ($this->violates($conflict, self::UNIQUE_VIOLATION, 'bot_source_assignments_org_bot_source')) {
                throw $this->duplicate($source);
            }

            if (
                $this->violates($conflict, self::FOREIGN_KEY_VIOLATION, 'bot_source_assignments_bot_same_org')
                || $this->violates($conflict, self::FOREIGN_KEY_VIOLATION, 'bot_source_assignments_source_same_org')
            ) {
                throw ValidationException::withMessages([
                    'source_id' => 'The database refused this assignment because the bot and the '
                        .'source do not belong to the same organization. Reaching this message '
                        .'means the source resolved inside this organization a moment ago and does '
                        .'not now, which nothing in this application can cause — report it rather '
                        .'than retrying.',
                ]);
            }

            throw $conflict;
        }
    }

    /**
     * Withdraw one grant.
     *
     * A HARD DELETE, and the audit row is the only thing that survives it.
     *
     * DELETE IS NOT IDEMPOTENT. A second delete is a 404 rather than a 200, because an audit row
     * exists for the first one and a 200 for the second would claim this actor withdrew a grant the
     * trail does not record them withdrawing. The same call `BotDomainService::remove()` makes.
     *
     * THE SOURCE NAME IS READ BEFORE THE ROW GOES, with its own organization predicate, because
     * `bot.source_assignment.deleted` carries it and both parents are hard deletes — a row holding
     * only two ULIDs resolves to nothing on either end afterwards, and "which documents was this
     * bot allowed to answer from" is precisely the question asked after the fact.
     *
     * @throws NotFoundHttpException when the row disappeared between the binding and the write
     */
    public function remove(
        Organization $organization,
        Bot $bot,
        BotSourceAssignment $assignment,
        ?string $actorId = null,
        ?Request $request = null,
    ): void {
        $organizationId = $organization->organizationId();

        // Through the repository and not through `$assignment->source`: the relation would be a
        // lazy load, which `Model::shouldBeStrict()` refuses outright, and its scoping would come
        // from the ambient `TenantContext` rather than from the argument this method was given.
        $source = $this->sources->find($organizationId, $assignment->source_id);

        $deleted = $this->assignments->delete(
            $organizationId,
            $bot->id,
            $assignment->id,
            function (BotSourceAssignment $row) use ($organizationId, $source, $actorId, $request): void {
                $this->record(
                    AuditLogger::BOT_SOURCE_ASSIGNMENT_DELETED,
                    $organizationId,
                    $actorId,
                    $row,
                    $source?->name,
                    $request,
                );
            },
        );

        if (! $deleted) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * One audit row describing one grant.
     *
     * THE DETAILS ARE BUILT FROM THE PERSISTED ROW, NEVER FROM REQUEST INPUT, which is what makes
     * "nothing credential-shaped can appear here" a property of the code rather than of the
     * caller's discipline. A `BotSourceAssignment` has nothing to offer but two foreign keys, an
     * integer and a boolean, and the one free-text field on the row — the source's name — is read
     * from the persisted `knowledge_sources` row rather than from anything the caller sent.
     *
     * `organization_id` is NOT in `details`: it is a column on the audit row itself, and echoing it
     * into the payload would be a second copy a query could disagree with.
     *
     * A NULL `$sourceName` IS OMITTED RATHER THAN PASSED. `sanitize()` skips a null silently, so
     * passing it would work — it is omitted explicitly because "the source row is already gone" is
     * a statement about the world rather than a value that happened to be absent.
     */
    private function record(
        string $operation,
        string $organizationId,
        ?string $actorId,
        BotSourceAssignment $assignment,
        ?string $sourceName,
        ?Request $request,
    ): void {
        $details = [
            // `bot_id` and `source_id` are recorded even though both are derivable from the URL,
            // and they are what make these rows useful after the fact: `subject_id` is the
            // assignment's own ULID and resolves to nothing once either parent is hard-deleted.
            'bot_id' => $assignment->bot_id,
            'source_id' => $assignment->source_id,
            // A tie-break between this bot's sources, never a filter. Recorded because a re-grant
            // at a different priority is otherwise byte-identical to no change at all.
            'priority' => $assignment->priority,
            // WHETHER THE GRANT IS LIVE. A disabled assignment grants nothing, and the difference
            // is the whole reading of this row in an investigation.
            'enabled' => $assignment->enabled,
        ];

        if ($sourceName !== null) {
            // TENANT-CONTROLLED FREE TEXT, bounded by `AuditLogger::MAX_VALUE_LENGTH` and passing
            // the shape backstop like any other echoed string — including the designed degradation
            // the bot's own `name` and `slug` record, where a legal value that matches a vendor-key
            // pattern is FINGERPRINTED rather than echoed.
            $details['source_name'] = $sourceName;
        }

        $this->audit->record(
            $operation,
            organizationId: $organizationId,
            actorId: $actorId,
            details: $details,
            subjectType: BotSourceAssignment::class,
            subjectId: $assignment->id,
            request: $request,
        );
    }

    /**
     * The 422 a duplicate grant produces, keyed on the field the form renders.
     */
    private function duplicate(KnowledgeSource $source): ValidationException
    {
        return ValidationException::withMessages([
            'source_id' => 'This bot is already assigned "'.$source->name.'". An assignment is a '
                .'SET membership — the same source twice is not two grants, and a second row '
                .'carrying a different `enabled` would make "may this bot answer from that source" '
                .'ambiguous in a way no reader of the console could resolve. To change the priority '
                .'or the off switch, remove the assignment and add it again: there is no update '
                .'verb, because the audit catalog has no operation for one.',
        ]);
    }

    /**
     * Whether this exception is the named constraint failing with the named SQLSTATE.
     *
     * BOTH TERMS. The SQLSTATE alone would treat any unique violation as a duplicate assignment,
     * and the constraint name alone would match a message that merely mentioned it.
     */
    private function violates(QueryException $exception, string $sqlState, string $constraint): bool
    {
        return $exception->getCode() === $sqlState
            && str_contains($exception->getMessage(), $constraint);
    }
}
