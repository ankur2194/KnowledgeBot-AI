<?php

declare(strict_types=1);

namespace App\Services\Bots;

use App\Models\Bot;
use App\Models\BotStarterQuestion;
use App\Models\Organization;
use App\Repositories\Contracts\BotStarterQuestionRepositoryInterface;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A bot's suggested starter questions: list, add, edit or move, remove.
 *
 * ── WHAT THESE ROWS ARE, AND WHAT THEY ARE NOT ────────────────────────────────────────────────
 *
 * They are the first-run affordance: three to six chips on an empty chat surface, from the bot's
 * configuration and NEVER generated client-side from the corpus — which would leak what the corpus
 * contains to anyone who can open the widget (kb-ai-chat-ux). They authorize nobody, bill nothing,
 * and change no retrieval behaviour, which is why the audit rows this class writes deliberately do
 * NOT carry the question text: §18.11 requires bot config changes audited, and `AuditLogger` refuses
 * unbounded tenant prose from the same table on the same page. The rows say who changed the
 * suggestions, which one, in which direction, and how many there are afterwards.
 *
 * ── THE POSITION IS AN INVARIANT, NOT A FIELD ─────────────────────────────────────────────────
 *
 * `sort_order` is 0..n-1 with no gaps and no duplicates after every write on this surface, and it
 * is enforced one layer down: `bot_starter_questions_org_bot_position` is UNIQUE per bot and
 * DELIBERATELY NOT DEFERRABLE, so every mutation re-sequences the whole list inside one transaction
 * rather than writing one row. `BotStarterQuestionRepositoryInterface` carries the reasoning and
 * `EloquentBotStarterQuestionRepository` carries the two-pass arithmetic that makes it collision-
 * free. What this class owns is the ceiling and the refusals.
 *
 * ── NOTHING HERE TOUCHES A CREDENTIAL, AND NOTHING HERE CAN ───────────────────────────────────
 *
 * This class does not import `App\Support\Crypto\CredentialVault` and no method it calls reaches
 * one. A starter question is a string and a number.
 */
final readonly class BotStarterQuestionService
{
    /**
     * How many starter questions one bot may have.
     *
     * ── SIX, WHICH IS THE MAXIMUM THE CHAT SURFACE RENDERS, AND THE MATCH IS THE POINT ────────
     *
     * `kb-ai-chat-ux` specifies "three to six" suggestion chips. Storing more than six would make
     * the console promise a seventh chip that no client draws — a configuration screen that lies,
     * with no error anywhere, and a support question ("why doesn't my last suggestion show up?")
     * whose answer lives in a design document. Capping at what is rendered means what is stored is
     * what is shown.
     *
     * THE LOWER BOUND OF THREE IS NOT ENFORCED, and that is deliberate: a bot with one question is
     * a legitimate configuration and a bot with none is the default state of every bot ever
     * created. "Three to six" is a rendering guideline for a populated list, not a minimum this
     * endpoint may refuse a save over.
     */
    public const MAX_PER_BOT = 6;

    /**
     * How long one question may be.
     *
     * Matched to `placeholder_text` rather than to `description`, because a starter question is a
     * CHIP LABEL rendered at `--text-base` on a card pill — not prose. A 2,000-character chip is a
     * rendering nobody has a design for.
     */
    public const MAX_LENGTH = 200;

    public function __construct(
        private BotStarterQuestionRepositoryInterface $questions,
        private AuditLogger $audit,
    ) {}

    /**
     * Every starter question of this bot, in the order the operator set.
     *
     * NO AUDIT ROW. §18.11 audits credential changes, config changes and destructive operations;
     * reading a list is none of them, and auditing it would bury the rows that matter under one per
     * page load.
     *
     * @return list<BotStarterQuestion>
     */
    public function list(Organization $organization, Bot $bot): array
    {
        return $this->questions->forBot($organization->organizationId(), $bot->id);
    }

    /**
     * Append one question to the end of the list.
     *
     * THE CEILING IS CHECKED HERE AND RACED IN THE REPOSITORY, and the race is left open on
     * purpose. Two simultaneous adds against a five-question list can both pass this check and
     * produce six and seven; the repository serialises them on the bot row so both rows are
     * well-formed and correctly positioned, and the seventh is a chip nobody renders rather than a
     * defect. Refusing it properly would mean re-reading the count under the lock and raising a
     * ValidationException from inside a transaction, which turns a cosmetic ceiling into a rollback
     * path — a worse trade than the extra chip. The cap that MATTERS is the origin allow-list's,
     * and that one is a security bound rather than a rendering one.
     *
     * @throws ValidationException 422 when the list is already full
     */
    public function add(
        Organization $organization,
        Bot $bot,
        string $question,
        ?string $actorId = null,
        ?Request $request = null,
    ): BotStarterQuestion {
        $organizationId = $organization->organizationId();

        if ($this->questions->countForBot($organizationId, $bot->id) >= self::MAX_PER_BOT) {
            throw ValidationException::withMessages([
                'question' => 'This bot already has the maximum of '.self::MAX_PER_BOT.' starter '
                    .'questions, which is as many as the chat surface renders. Edit one of the '
                    .'existing questions or remove one first — storing a seventh would put a '
                    .'suggestion in the console that no client draws.',
            ]);
        }

        return $this->questions->create(
            $organizationId,
            $bot->id,
            $question,
            // A FULL CLOSURE AND NOT AN ARROW FUNCTION: an arrow function implicitly RETURNS the
            // call's value, `record()` is `void`, and the interface types the callback as returning
            // void.
            function (BotStarterQuestion $row, int $count) use ($organizationId, $actorId, $request): void {
                $this->record(
                    AuditLogger::BOT_STARTER_QUESTION_CREATED,
                    $organizationId,
                    $actorId,
                    $row,
                    $count,
                    $request,
                );
            },
        );
    }

    /**
     * Edit one question's text, move it, or both.
     *
     * ── THE POSITION IS BOUNDED AGAINST THE LIST, WHICH A VALIDATION RULE CANNOT DO ───────────
     *
     * `UpdateBotStarterQuestionRequest` bounds `sort_order` at the PLATFORM ceiling because that is
     * all a rule can know: the list belongs to a bot resolved from the route, and reading it inside
     * `rules()` would be a database query on a request whose authorization has not run yet (finding
     * L5 — validation precedes `Gate::authorize`). The real bound is `count - 1` and it is here,
     * one layer above the transaction that will apply it.
     *
     * IT IS A REFUSAL RATHER THAN A CLAMP, deliberately. "Move this to position 9" against a
     * three-item list is a console working from a stale read, and silently appending it instead
     * would return 200 for an instruction that was not carried out.
     *
     * @throws ValidationException 422 for a position outside the list
     * @throws NotFoundHttpException when the row disappeared between the binding and the write
     */
    public function edit(
        Organization $organization,
        Bot $bot,
        BotStarterQuestion $question,
        StarterQuestionEdit $edit,
        ?string $actorId = null,
        ?Request $request = null,
    ): BotStarterQuestion {
        $organizationId = $organization->organizationId();

        if ($edit->position !== null) {
            $count = $this->questions->countForBot($organizationId, $bot->id);

            if ($edit->position > $count - 1) {
                throw ValidationException::withMessages([
                    'sort_order' => 'This bot has '.$count.' starter question'.($count === 1 ? '' : 's')
                        .', so the positions are 0 to '.max(0, $count - 1).'. Positions are '
                        .'contiguous by construction — every write re-sequences the whole list — so '
                        .'a position past the end is a console working from a stale read rather '
                        .'than a gap to fill.',
                ]);
            }
        }

        $updated = $this->questions->update(
            $organizationId,
            $bot->id,
            $question->id,
            $edit,
            function (BotStarterQuestion $row, string $changed, int $count) use ($organizationId, $actorId, $request): void {
                $this->record(
                    AuditLogger::BOT_STARTER_QUESTION_UPDATED,
                    $organizationId,
                    $actorId,
                    $row,
                    $count,
                    $request,
                    $changed,
                );
            },
        );

        if ($updated === null) {
            // Deleted between the route binding and the transaction. The same 404 the binding would
            // have produced, not a 500 describing a race the caller cannot act on.
            throw new NotFoundHttpException;
        }

        return $updated;
    }

    /**
     * Remove one question and close the gap it leaves.
     *
     * DELETE IS NOT IDEMPOTENT. A second delete is a 404 rather than a 200, because an audit row
     * exists for the first one and a 200 for the second would claim this actor performed a deletion
     * the trail does not record.
     *
     * @throws NotFoundHttpException when the row disappeared between the binding and the write
     */
    public function remove(
        Organization $organization,
        Bot $bot,
        BotStarterQuestion $question,
        ?string $actorId = null,
        ?Request $request = null,
    ): void {
        $organizationId = $organization->organizationId();

        $deleted = $this->questions->delete(
            $organizationId,
            $bot->id,
            $question->id,
            function (BotStarterQuestion $row, int $count) use ($organizationId, $actorId, $request): void {
                $this->record(
                    AuditLogger::BOT_STARTER_QUESTION_DELETED,
                    $organizationId,
                    $actorId,
                    $row,
                    $count,
                    $request,
                );
            },
        );

        if (! $deleted) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * One audit row describing one starter question.
     *
     * THE QUESTION TEXT IS NOT HERE AND THAT IS THE DECISION, not an omission — `AuditLogger`'s
     * `BOT_STARTER_QUESTION_CREATED` docblock carries the argument. `bot_id` is recorded even
     * though it is in the URL, for the reason it is on the domain rows: `subject_id` is this row's
     * own ULID and resolves to nothing after the bot is hard-deleted.
     *
     * `$changed` is a comma-joined scalar rather than an array, because `sanitize()` drops arrays
     * outright — the same `capabilities` shape. An empty string (nothing actually moved) is skipped
     * silently by the sanitizer, which is the documented behaviour and the right one: a PATCH that
     * re-sent the values it already held did change nothing, and the row saying so by omission is
     * accurate.
     */
    private function record(
        string $operation,
        string $organizationId,
        ?string $actorId,
        BotStarterQuestion $question,
        int $count,
        ?Request $request,
        ?string $changed = null,
    ): void {
        $details = [
            'bot_id' => $question->bot_id,
            'sort_order' => $question->sort_order,
            'question_count' => $count,
        ];

        if ($changed !== null) {
            $details['changed'] = $changed;
        }

        $this->audit->record(
            $operation,
            organizationId: $organizationId,
            actorId: $actorId,
            details: $details,
            subjectType: BotStarterQuestion::class,
            subjectId: $question->id,
            request: $request,
        );
    }
}
