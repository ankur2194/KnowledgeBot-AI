<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Bot;
use App\Models\BotStarterQuestion;
use App\Repositories\Contracts\BotStarterQuestionRepositoryInterface;
use App\Services\Bots\StarterQuestionEdit;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class EloquentBotStarterQuestionRepository implements BotStarterQuestionRepositoryInterface
{
    /**
     * @return list<BotStarterQuestion>
     */
    public function forBot(string $organizationId, string $botId): array
    {
        // `array_values()` rather than a `@var list<…>` annotation: `Collection::all()` is typed
        // `array<int, T>`, and asserting a list in a docblock claims something the call cannot
        // prove, where re-indexing makes it true.
        return array_values($this->ordered($organizationId, $botId)->get()->all());
    }

    public function countForBot(string $organizationId, string $botId): int
    {
        return $this->scoped($organizationId, $botId)->count();
    }

    /**
     * @param  Closure(BotStarterQuestion, int): void  $audit
     */
    public function create(
        string $organizationId,
        string $botId,
        string $question,
        Closure $audit,
    ): BotStarterQuestion {
        return DB::transaction(
            function () use ($organizationId, $botId, $question, $audit): BotStarterQuestion {
                // THE BOT ROW IS THE MUTEX FOR THE WHOLE CHILD LIST, and it has to be, because the
                // thing being serialised is a property of the SET rather than of any row: "the next
                // free position". Locking the existing question rows cannot do it — the row that
                // collides is the one that does not exist yet, so there is nothing to lock. Two
                // concurrent appends that both read "the list has 3" would both write 3 and the
                // second would be SQLSTATE 23505 rendered as a 500.
                $this->lockBot($organizationId, $botId);

                $existing = $this->ordered($organizationId, $botId)->get()->all();

                $row = new BotStarterQuestion;

                // `organization_id` and `bot_id` are outside $fillable and are assigned here and
                // only here: the first comes from the authenticated context passed in as an
                // argument, the second from the route. Neither has a request field.
                $row->organization_id = $organizationId;
                $row->bot_id = $botId;
                $row->question = $question;
                // APPENDED. The list is compacted after every write on this surface, so the count
                // IS the next free position — but it is computed from the rows read under the lock
                // rather than from a `max()+1`, so a list that somehow held a gap still produces a
                // position nothing occupies.
                $row->sort_order = count($existing);

                $row->save();

                $audit($row, count($existing) + 1);

                return $row;
            },
        );
    }

    /**
     * @param  Closure(BotStarterQuestion, string, int): void  $audit
     */
    public function update(
        string $organizationId,
        string $botId,
        string $questionId,
        StarterQuestionEdit $edit,
        Closure $audit,
    ): ?BotStarterQuestion {
        return DB::transaction(
            function () use ($organizationId, $botId, $questionId, $edit, $audit): ?BotStarterQuestion {
                $this->lockBot($organizationId, $botId);

                $rows = array_values($this->ordered($organizationId, $botId)->get()->all());

                $subject = null;

                foreach ($rows as $row) {
                    if ($row->id === $questionId) {
                        $subject = $row;
                    }
                }

                if ($subject === null) {
                    // The route binding already 404'd a foreign id long before this line; reaching
                    // here means the row was deleted between the binding and this transaction.
                    return null;
                }

                $changed = [];

                if ($edit->question !== null && $edit->question !== $subject->question) {
                    $subject->question = $edit->question;
                    $subject->save();
                    $changed[] = 'question';
                }

                if ($edit->position !== null && $edit->position !== $subject->sort_order) {
                    $this->resequence($rows, $subject, $edit->position);
                    $changed[] = 'sort_order';
                }

                // REFRESHED FROM THE ROWS THIS TRANSACTION WROTE. `resequence()` writes through the
                // query builder, so the in-memory `$subject` still holds its old `sort_order` and
                // the 200 body would report the position the caller asked to move away from.
                $subject->refresh();

                $audit($subject, implode(',', $changed), count($rows));

                return $subject;
            },
        );
    }

    /**
     * @param  Closure(BotStarterQuestion, int): void  $audit
     */
    public function delete(
        string $organizationId,
        string $botId,
        string $questionId,
        Closure $audit,
    ): bool {
        return DB::transaction(
            function () use ($organizationId, $botId, $questionId, $audit): bool {
                $this->lockBot($organizationId, $botId);

                $rows = array_values($this->ordered($organizationId, $botId)->get()->all());

                $subject = null;
                $survivors = [];

                foreach ($rows as $row) {
                    if ($row->id === $questionId) {
                        $subject = $row;

                        continue;
                    }

                    $survivors[] = $row;
                }

                if ($subject === null) {
                    return false;
                }

                // BEFORE the row goes and inside the transaction, so an ON_FAILURE_ABORT write
                // failure leaves the list intact rather than shortening it untraceably.
                $audit($subject, count($survivors));

                $subject->delete();

                // THE GAP IS CLOSED. `sort_order` is published as a renderable index and
                // `BotStarterQuestionResource` promises there are no gaps, so a delete that left
                // 0,1,3 would make that promise false everywhere at once.
                //
                // ONLY WHEN THERE IS ACTUALLY A GAP, and the test is against the survivors' own
                // positions rather than against the deleted row's. Deleting the LAST question
                // leaves 0..n-2 already compact and rewriting it would move `updated_at` on every
                // surviving row for nothing — but so would assuming compactness of a list that a
                // seeder or a fixture put positions 0, 2, 5 into, which is the case the cheaper
                // test (`$subject->sort_order < count($survivors)`) gets wrong in the direction
                // that leaves the published invariant false.
                if (! $this->isCompact($survivors)) {
                    $this->writePositions($organizationId, $botId, $survivors);
                }

                return true;
            },
        );
    }

    /**
     * Whether these rows, in the order given, already occupy `0..n-1`.
     *
     * @param  list<BotStarterQuestion>  $rows
     */
    private function isCompact(array $rows): bool
    {
        foreach ($rows as $index => $row) {
            if ($row->sort_order !== $index) {
                return false;
            }
        }

        return true;
    }

    /**
     * Move one row to `$position` and renumber the whole list around it.
     *
     * @param  list<BotStarterQuestion>  $rows  the list as it stands, in position order
     */
    private function resequence(array $rows, BotStarterQuestion $subject, int $position): void
    {
        $others = [];

        foreach ($rows as $row) {
            if ($row->id !== $subject->id) {
                $others[] = $row;
            }
        }

        // CLAMPED RATHER THAN REFUSED, and only against the list's own length — the service has
        // already refused a position outside it with a 422 naming the field. This is the
        // belt-and-braces half: `array_splice` with an out-of-range offset appends silently, and an
        // append is not what "move to position 9" asked for.
        $target = max(0, min($position, count($others)));

        array_splice($others, $target, 0, [$subject]);

        $this->writePositions(
            $subject->organization_id,
            $subject->bot_id,
            $others,
        );
    }

    /**
     * Write `0..n-1` onto `$rows` in the order given, in TWO PASSES.
     *
     * ── WHY TWO PASSES, AND WHY THE FIRST ONE'S OFFSET IS WHAT IT IS ──────────────────────────
     *
     * `bot_starter_questions_org_bot_position` is a UNIQUE INDEX, and PostgreSQL checks a unique
     * index PER ROW as the update visits it — not at statement end, which is what a DEFERRABLE
     * constraint would buy and what the migration deliberately did not take. So a single pass
     * writing final positions collides the moment two rows swap: whichever is visited first tries
     * to take a value the second still holds.
     *
     * Pass one shifts every row by `$offset = max(existing) + 1`, which puts every NEW value in
     * `[offset, offset + max]` while every OLD value is in `[0, max]`. The two ranges are disjoint
     * by construction, and the shift is injective, so no intermediate state can collide however
     * PostgreSQL orders the rows. Pass two then writes `0..n-1`, which is disjoint from the shifted
     * range for the same reason.
     *
     * The offset is read from the table rather than assumed, so a list somebody seeded with
     * positions 0, 5, 100 re-sequences correctly instead of relying on the compaction invariant
     * this method is what maintains.
     *
     * @param  list<BotStarterQuestion>  $rows  in the order they should end up
     */
    private function writePositions(string $organizationId, string $botId, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $offset = (int) $this->scoped($organizationId, $botId)->max('sort_order') + 1;

        // PASS ONE — clear of the range every final value will occupy. `increment` on the query
        // builder issues one `SET sort_order = sort_order + ?`, so this is a single statement.
        $this->scoped($organizationId, $botId)->increment('sort_order', $offset);

        // PASS TWO — the final positions, one statement per row. A list of at most
        // BotStarterQuestionService::MAX_PER_BOT rows, so the row count is a constant rather than a
        // scan, and each write is keyed so the two tenant predicates still apply.
        foreach ($rows as $index => $row) {
            $this->scoped($organizationId, $botId)
                ->whereKey($row->id)
                ->update(['sort_order' => $index]);
        }
    }

    /**
     * Take the BOT row FOR UPDATE, as the mutex for its whole starter-question list.
     *
     * The organization predicate is on the SELECT rather than only on the model's global scope,
     * because a lock acquired under a stale ambient context would be a lock on somebody else's row.
     * The return value is deliberately unused: what is wanted is the lock, and the row's existence
     * has already been established by the route binding.
     */
    private function lockBot(string $organizationId, string $botId): void
    {
        Bot::query()
            ->where('organization_id', '=', $organizationId)
            ->whereKey($botId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @return Builder<BotStarterQuestion>
     */
    private function ordered(string $organizationId, string $botId): Builder
    {
        return $this->scoped($organizationId, $botId)->orderBy('sort_order');
    }

    /**
     * The two ownership predicates, written out once so no query in this class can be built without
     * them. `#[ScopedBy(OrganizationScope::class)]` adds the organization term from the ambient
     * `TenantContext` and is the backstop; this is the mechanism. The bot term has no backstop.
     *
     * @return Builder<BotStarterQuestion>
     */
    private function scoped(string $organizationId, string $botId): Builder
    {
        return BotStarterQuestion::query()
            ->where('organization_id', '=', $organizationId)
            ->where('bot_id', '=', $botId);
    }
}
