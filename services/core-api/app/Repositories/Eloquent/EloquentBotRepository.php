<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Enums\BotStatus;
use App\Models\Bot;
use App\Models\BotDomain;
use App\Models\BotFallbackEntry;
use App\Models\BotStarterQuestion;
use App\Repositories\Contracts\BotRepositoryInterface;
use App\Services\Bots\BotEdit;
use App\Services\Bots\NewBot;
use App\Support\Http\ListQuery;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class EloquentBotRepository implements BotRepositoryInterface
{
    /**
     * The columns a free-text filter searches.
     *
     * NAME AND SLUG, AND NOTHING ELSE. Both are what an operator types when they are looking for a
     * bot they already know exists, and both are short and indexed-adjacent. `description`,
     * `welcome_message` and `system_instruction` are deliberately absent: they are prose, an
     * `ILIKE '%…%'` over them is a sequential scan of every row's full text, and a match inside a
     * system instruction would surface a bot whose NAME has nothing to do with the search — which
     * reads as the filter being broken.
     *
     * @var list<string>
     */
    private const FILTERABLE = ['name', 'slug'];

    /**
     * @return LengthAwarePaginator<int, Bot>
     */
    public function paginate(string $organizationId, ListQuery $query): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, Bot> $page */
        $page = $this->scoped($organizationId)
            ->when(
                $query->filter !== null,
                // A CLOSURE GROUP AND NOT TWO CHAINED `orWhere`s. Without the grouping the SQL is
                // `organization_id = ? AND name ILIKE ? OR slug ILIKE ?`, and `AND` binds tighter
                // than `OR` — so the second disjunct carries NO TENANT PREDICATE and the endpoint
                // returns every organization's bots whose slug happens to match. It is one of the
                // few ways to write a cross-tenant leak that looks like a formatting choice, and it
                // is why tests/Security/BotEndpointAccessTest.php filters on a term BOTH
                // organizations match.
                fn (Builder $builder): Builder => $builder->where(
                    function (Builder $group) use ($query): void {
                        $term = '%'.$this->escapeLike((string) $query->filter).'%';

                        foreach (self::FILTERABLE as $column) {
                            // `ilike` because a human searching for "Support" means the bot called
                            // "support desk". PostgreSQL's own operator rather than
                            // `whereRaw('lower(...)')`, so the value stays a bound parameter and
                            // never becomes SQL.
                            $group->orWhere($column, 'ilike', $term);
                        }
                    },
                ),
            )
            ->orderBy($query->sort, $query->direction->value)
            // THE TIE-BREAK IS NOT OPTIONAL, and it is appended even when the sort column IS `id`.
            // `name` and `status` are both non-unique within an organization, so without a total
            // order PostgreSQL may legally return a row on page 2 that it already returned on page
            // 1 — and the duplicate is invisible until somebody counts. A redundant second term on
            // the `id` sort costs nothing and removes the branch where somebody has to remember.
            ->orderBy('id')
            ->paginate(perPage: $query->perPage, page: $query->page);

        return $page;
    }

    public function slugExists(string $organizationId, string $slug, ?string $exceptBotId = null): bool
    {
        return $this->scoped($organizationId)
            ->where('slug', '=', $slug)
            ->when(
                $exceptBotId !== null,
                fn (Builder $builder): Builder => $builder->whereKeyNot($exceptBotId),
            )
            ->exists();
    }

    /**
     * @param  Closure(Bot): void  $audit
     */
    public function create(
        string $organizationId,
        string $publicBotId,
        NewBot $input,
        Closure $audit,
    ): Bot {
        return DB::transaction(function () use ($organizationId, $publicBotId, $input, $audit): Bot {
            $bot = new Bot;

            // THE TWO COLUMNS OUTSIDE $fillable, ASSIGNED HERE AND ONLY HERE. `organization_id`
            // comes from the authenticated context passed in as an argument — never from the DTO,
            // which has no member for it, and never from request input. `public_bot_id` comes from
            // PublicBotIdentifier and is never editable again, so this line is the only write to it
            // in the whole application.
            $bot->organization_id = $organizationId;
            $bot->public_bot_id = $publicBotId;

            $bot->name = $input->name;
            $bot->slug = $input->slug;
            $bot->description = $input->description;
            $bot->welcome_message = $input->welcomeMessage;
            $bot->placeholder_text = $input->placeholderText;
            $bot->system_instruction = $input->systemInstruction;
            $bot->answer_style_instruction = $input->answerStyleInstruction;

            // `status` IS NOT TAKEN FROM INPUT — NewBot has no member that could carry one — and it
            // is still ASSIGNED rather than left to the column default, for a reason that is not
            // about defaults at all: an attribute the INSERT never mentioned is null on the model
            // afterwards, so the 201 body and the audit row would both read a null `status` off a
            // row the database has correctly stored as `draft`. Restating it here is what makes the
            // created object fully describe the created row without a second SELECT. The column
            // default stays the authority for every writer that is not this one.
            $bot->status = BotStatus::Draft;
            $bot->access_mode = $input->accessMode;

            $bot->provider_connection_id = $input->providerConnectionId;
            $bot->provider_model_id = $input->providerModelId;

            $bot->answer_mode = $input->answerMode;
            $bot->dense_top_k = $input->denseTopK;
            $bot->sparse_top_k = $input->sparseTopK;
            $bot->rerank_candidates = $input->rerankCandidates;
            $bot->rerank_retain = $input->rerankRetain;
            $bot->evidence_threshold = $input->evidenceThreshold;
            $bot->evidence_threshold_scale = $input->evidenceThresholdScale;

            $bot->allow_general_answers = $input->allowGeneralAnswers;
            $bot->theme = $input->theme;

            $bot->rate_limit_per_minute = $input->rateLimitPerMinute;
            $bot->rate_limit_per_day = $input->rateLimitPerDay;
            $bot->retention_days = $input->retentionDays;
            $bot->collect_end_user_data = $input->collectEndUserData;
            $bot->consent_text = $input->consentText;

            // `retrieval_configuration_version` STARTS AT 1 AND IS NOT TAKEN FROM INPUT: a client
            // that could set it could make two different configurations claim the same version,
            // which is precisely the identity the §21.5 regression gate replays against. It is
            // written out here for the same reason `status` above is — an unmentioned attribute is
            // null on the model after the INSERT, and the first retrieval trace this bot produces
            // would carry that null instead of the 1 the row actually holds.
            $bot->retrieval_configuration_version = 1;

            //
            // 23505 IS POSSIBLE HERE AND IS NOT HANDLED IN THIS FILE. The service performs a
            // scoped pre-flight existence check on the slug for the readable message and catches
            // the SQLSTATE for the race that check cannot win. `bots_org_slug_unique` is the
            // authority either way.
            $bot->save();

            // INSIDE the transaction, after the INSERT so the row has its ULID, before the COMMIT
            // so an ON_FAILURE_ABORT audit failure rethrows and takes the row with it.
            $audit($bot);

            return $bot;
        });
    }

    /**
     * @param  Closure(Bot): void  $audit
     */
    public function update(
        string $organizationId,
        string $botId,
        BotEdit $edit,
        Closure $audit,
    ): ?Bot {
        return DB::transaction(function () use ($organizationId, $botId, $edit, $audit): ?Bot {
            $bot = $this->lock($organizationId, $botId);

            if ($bot === null) {
                // The route binding already 404'd a foreign id long before this line; reaching here
                // means the row was deleted between the binding and this transaction. Null rather
                // than an exception, so the caller renders the same 404 the binding would have
                // rather than a 500 describing a race the caller cannot act on.
                return null;
            }

            // THE BUMP IS DECIDED BEFORE ANYTHING IS ASSIGNED, from the values read under the lock.
            // Deciding it afterwards from `isDirty()` would be the same answer today and would
            // silently start including every column the moment a knob is added to a cast or a
            // mutator changes a value on write.
            $bumpVersion = $this->movesRetrievalConfiguration($bot, $edit);

            foreach ($edit->columns() as $column => $value) {
                // `setAttribute` and not `fill()`: every column here has already passed
                // BotEdit's allow-list, and `fill()` would additionally consult `$fillable` — two
                // allow-lists deciding one write, where a column present in one and absent from the
                // other is dropped silently rather than refused.
                $bot->setAttribute($column, $value);
            }

            if ($bumpVersion) {
                $bot->retrieval_configuration_version = $bot->retrieval_configuration_version + 1;
            }

            $bot->save();

            $audit($bot);

            return $bot;
        });
    }

    /**
     * @param  Closure(Bot): void  $audit
     */
    public function delete(string $organizationId, string $botId, Closure $audit): bool
    {
        return DB::transaction(function () use ($organizationId, $botId, $audit): bool {
            $bot = $this->lock($organizationId, $botId);

            if ($bot === null) {
                return false;
            }

            // BEFORE the children and before the row, because after them there is nothing left to
            // describe: this is a hard delete and the audit row is the only surviving record of the
            // bot. It is also inside the transaction, so an ON_FAILURE_ABORT write failure leaves
            // the whole graph intact rather than removing it untraceably.
            $audit($bot);

            // THE THREE CHILD COLLECTIONS, IN CODE, BECAUSE EVERY ONE OF THEM REFERENCES
            // `bots (organization_id, id)` WITH `ON DELETE RESTRICT`. A bot with a single origin on
            // its allow-list would otherwise raise SQLSTATE 23503 on the DELETE below, rendered by
            // the error envelope as a 500 for a request that is entirely legitimate. See the
            // interface for why the keys are not CASCADE.
            //
            // BOTH PREDICATES ON EVERY CHILD DELETE. The organization term is redundant against the
            // composite foreign key and is written out anyway — this layer's property is that it is
            // correct on its own, not that it is correct because of a constraint in another file.
            foreach ([BotFallbackEntry::class, BotStarterQuestion::class, BotDomain::class] as $child) {
                $child::query()
                    ->where('organization_id', '=', $organizationId)
                    ->where('bot_id', '=', $botId)
                    ->delete();
            }

            $bot->delete();

            return true;
        });
    }

    /**
     * Whether this edit changes the VALUE of a retrieval knob, as opposed to merely naming one.
     *
     * A console that re-submits its whole form on every save names every knob on every request. If
     * presence were the test, every save would mint a new configuration identity for a
     * configuration that did not move — invalidating every cached answer and making the §21.5
     * regression gate compare traces that differ only by a number nobody changed.
     *
     * THE COMPARISON IS STRICT, AND THAT PUTS A REQUIREMENT ON THE OTHER SIDE OF THE SEAM.
     * `UpdateBotRequest::toData()` casts every value into the shape the model's own cast produces —
     * enums as enum cases, integers as integers, the threshold as a float — precisely so `!==` is
     * comparing like with like. Without that, an unchanged `answer_mode` arriving as the raw string
     * `'strict'` would never equal the `BotAnswerMode::Strict` case on the row, and every save
     * would mint a new configuration identity for a configuration that did not move. Two enum
     * references to the same case ARE identical, so the strict comparison is correct for them.
     */
    private function movesRetrievalConfiguration(Bot $bot, BotEdit $edit): bool
    {
        foreach (BotEdit::RETRIEVAL_KNOBS as $column) {
            if (! $edit->names($column)) {
                continue;
            }

            if ($bot->getAttribute($column) !== $edit->valueFor($column)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One bot of ONE organization, locked FOR UPDATE.
     *
     * The lock is what makes "read the row, decide, write it" a decision rather than a guess: two
     * PATCHes, or a PATCH racing a DELETE, serialise here instead of interleaving — which is what
     * the `retrieval_configuration_version` bump depends on to stay a total order. The ownership
     * predicate is on the SELECT rather than only on the model's global scope, because the lock has
     * to be taken on a row this organization actually owns: a lock acquired under a stale ambient
     * context would be a lock on somebody else's row.
     */
    private function lock(string $organizationId, string $botId): ?Bot
    {
        return $this->scoped($organizationId)
            ->whereKey($botId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * The organization predicate, written out once so no query in this class can be built without
     * it. `#[ScopedBy(OrganizationScope::class)]` adds the same term from the ambient
     * `TenantContext` and is the backstop; this is the mechanism.
     *
     * @return Builder<Bot>
     */
    private function scoped(string $organizationId): Builder
    {
        return Bot::query()->where('organization_id', '=', $organizationId);
    }

    /**
     * Neutralise the `LIKE` metacharacters in a free-text search term.
     *
     * `ListQuery` states the division of labour: the term is deliberately NOT pattern-constrained,
     * because constraining the character class of a string a human types would make the endpoint
     * refuse the values customers actually search for. What makes it safe is that it never reaches
     * SQL as SQL — the builder binds it as a parameter — and what bounds it is the length cap. This
     * method covers the remaining, non-security half: a search for `100%` or `a_b` would otherwise
     * be read as a WILDCARD and quietly match far more than the operator asked for.
     *
     * The backslash is escaped FIRST, or the escapes added afterwards would themselves be escaped.
     * PostgreSQL's default `LIKE` escape character is the backslash, so no `ESCAPE` clause is
     * needed and none is added — adding one would be a second statement of the same fact.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
