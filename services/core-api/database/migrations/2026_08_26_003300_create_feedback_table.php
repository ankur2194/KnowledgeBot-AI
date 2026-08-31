<?php

declare(strict_types=1);

use App\Enums\FeedbackRating;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The thumb, and what the person said about it (docs/11 §16.6, docs/04 §8.23).
 *
 * ═══ ONE VERDICT PER PERSON PER ANSWER ══════════════════════════════════════════════════════
 *
 * Two partial unique indexes — one per submitter column — so a visitor who clicks thumbs-down and
 * then thumbs-up has CHANGED THEIR MIND rather than voted twice. Without them §8.23's "positive and
 * negative feedback rate" is a count of clicks, which any bored visitor can move on their own, and
 * the number that is supposed to tell an operator whether the bot is working instead tells them how
 * many times a button was pressed.
 *
 * THEY ARE TWO INDEXES AND NOT ONE OVER A COALESCE, because a partial index on each column is
 * exactly what the exclusivity constraint below makes correct: every row populates precisely one of
 * the two, so each index covers a disjoint half of the table and neither can be defeated by a NULL.
 * A single `(message_id, coalesce(user_id, session))` index would collapse the two identity spaces
 * into one string comparison, where a session token that happened to equal a ULID would silently
 * take that user's vote.
 *
 * ═══ THE SUBMITTER IS EXACTLY ONE OF TWO, MIRRORING `conversations` ════════════════════════
 *
 * §16.6 says "Submitted by user or anonymous session", and `feedback_submitter_exclusive` reads it
 * the same way `conversations_participant_exclusive` does: EXACTLY ONE. The reasoning is there in
 * full — "both" is reachable for a signed-in visitor, and it is the state that makes two code paths
 * each pick a different column as the identity with neither being wrong.
 *
 * IT IS NOT REQUIRED TO MATCH THE CONVERSATION'S PARTICIPANT, and that is deliberate: an
 * administrator reviewing a transcript may rate an answer a customer received, which is a real and
 * useful action, and a constraint tying the two together would refuse it. What that means is that
 * `submitted_by_user_id` is NOT a tenant term — the organization comes from `messages ->
 * conversations`, as it does for every table in this half of the graph.
 *
 * `users` is RESTRICT, matching every other reference to that table in this schema.
 *
 * ═══ NO `organization_id`; THE CHAIN IS `messages -> conversations` ════════════════════════
 *
 * kb-tenancy-isolation NN1 names `citations/feedback -> messages -> conversations` as its example of
 * the NOT NULL FK chain. `App\Models\Feedback` therefore carries no `#[ScopedBy]` — see the model.
 *
 * ═══ THE COMMENT IS TENANT PROSE FROM AN UNAUTHENTICATED STRANGER ══════════════════════════
 *
 * Bounded at 4,000 characters by a CHECK, because it arrives from the widget on a customer's page
 * and nothing upstream of the FormRequest bounds it. It is stored here — behind the conversation's
 * retention deadline, cascaded away with the message — and it never reaches `audit_logs`, which is
 * append-only and outlives everything. It is untrusted data on the way out as well as in: whatever
 * renders it escapes it, exactly as retrieved content is escaped (non-negotiable 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        $ratings = $this->quotedList(FeedbackRating::values());

        $this->run(<<<SQL
            CREATE TABLE feedback (
                id                   char(26) COLLATE "C" PRIMARY KEY,

                -- NOT NULL and CASCADE: a verdict on nothing is not a record.
                -- "Deleting a message takes its feedback."
                message_id           char(26) COLLATE "C" NOT NULL
                                     REFERENCES messages (id) ON DELETE CASCADE,

                rating               text NOT NULL,

                -- Free text from a stranger. Bounded below; nullable, because most thumbs carry
                -- none and an empty string would be a second spelling of that.
                comment              text,

                -- ── the submitter: exactly one of these two ─────────────────────────────────
                submitted_by_user_id char(26) COLLATE "C"
                                     REFERENCES users (id) ON DELETE RESTRICT,
                -- The same opaque token grammar `conversations.anonymous_session_id` pins, and for
                -- the same reason: it is a bearer value this platform mints.
                submitted_by_session text COLLATE "C",

                created_at           timestamptz NOT NULL DEFAULT now(),
                updated_at           timestamptz NOT NULL DEFAULT now(),

                -- ── the closed vocabulary, generated from the enum ──────────────────────────
                CONSTRAINT feedback_rating_check CHECK (rating IN ({$ratings})),

                -- ── the submitter rules ─────────────────────────────────────────────────────
                CONSTRAINT feedback_submitter_exclusive
                    CHECK (num_nonnulls(submitted_by_user_id, submitted_by_session) = 1),
                CONSTRAINT feedback_session_shape
                    CHECK (submitted_by_session IS NULL
                           OR submitted_by_session ~ '^[A-Za-z0-9_-]{16,128}\$'),

                -- ── the comment ─────────────────────────────────────────────────────────────
                CONSTRAINT feedback_comment_not_blank
                    CHECK (comment IS NULL OR btrim(comment) <> ''),
                -- The backstop under the FormRequest, on the column that receives text from an
                -- unauthenticated visitor on somebody else's website.
                CONSTRAINT feedback_comment_bounded
                    CHECK (comment IS NULL OR length(comment) <= 4000)
            )
        SQL);

        // ONE VERDICT PER PERSON PER ANSWER. Two partial indexes over disjoint halves of the table —
        // see the docblock for why this is not one index over a coalesce.
        //
        // The first also serves as the FK-child index for the cascade from `messages`, which is why
        // it leads with `message_id`: this table has no `organization_id`, and the parent whose
        // organization is fixed is the narrowest leading term available.
        $this->run(<<<'SQL'
            CREATE UNIQUE INDEX feedback_message_user_unique
                ON feedback (message_id, submitted_by_user_id)
                WHERE submitted_by_user_id IS NOT NULL
        SQL);

        $this->run(<<<'SQL'
            CREATE UNIQUE INDEX feedback_message_session_unique
                ON feedback (message_id, submitted_by_session)
                WHERE submitted_by_session IS NOT NULL
        SQL);

        // The half of the table the first unique index does not cover, for the cascade from
        // `messages`: an anonymous thumb has a NULL `submitted_by_user_id`, so
        // `feedback_message_user_unique` holds none of those rows and the referential check for a
        // deleted message would fall back to a sequential scan on exactly the population that is
        // largest.
        $this->run(<<<'SQL'
            CREATE INDEX feedback_message_created
                ON feedback (message_id, created_at)
        SQL);

        // FK-CHILD INDEX for `submitted_by_user_id REFERENCES users (id) ON DELETE RESTRICT`. NOT
        // led by `message_id`, for the reason `conversations_user_referential` is not led by
        // `organization_id`: a referential check has no message in hand.
        $this->run(<<<'SQL'
            CREATE INDEX feedback_user_referential
                ON feedback (submitted_by_user_id) WHERE submitted_by_user_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS feedback');
    }

    /**
     * @param  list<string>  $values
     */
    private function quotedList(array $values): string
    {
        return implode(', ', array_map(static fn (string $v): string => "'{$v}'", $values));
    }

    private function run(string $sql): void
    {
        Schema::getConnection()->statement($sql);
    }
};
