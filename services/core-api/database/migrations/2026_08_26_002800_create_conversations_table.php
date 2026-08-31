<?php

declare(strict_types=1);

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The thread (docs/04 §8.22, docs/11 §16.6). The root of the six-table conversation graph, and the
 * only one of the six that holds `organization_id` directly.
 *
 * Written as SQL through DB::statement() like every migration here (2026_08_07_000100 records the
 * reasoning: `char(26) COLLATE "C"`, `text` + CHECK instead of a native PG enum, `timestamptz`,
 * composite foreign keys — none of which the Schema builder can express).
 *
 * ═══ A TRANSCRIPT IS AN AUDIT RECORD, AND THE WHOLE FK GRAPH IS BUILT ON THAT ════════════════
 *
 * This is the sentence every `ON DELETE` action in these six migrations follows from. A customer
 * disputing an answer is answered by the transcript, the retrieval trace behind it and the provider
 * call that paid for it — and every one of those is worthless if some unrelated administrative
 * action could remove it silently. So the rule across the whole graph is: A ROW THAT APPEARS IN
 * HISTORY MAKES ITS PARENT UNDELETABLE, and the parent is withdrawn (archived, disabled) rather
 * than deleted.
 *
 *   organization_id  -> organizations       RESTRICT   org deletion is a purge worker in a defined
 *                                                      order, never one statement that takes an
 *                                                      ACCESS EXCLUSIVE lock on forty tables
 *                                                      (postgresql-patterns).
 *   (organization_id, bot_id) -> bots       RESTRICT   THE DECISION THIS MIGRATION EXISTS TO MAKE.
 *                                                      See the block below.
 *   user_id          -> users               RESTRICT   the house action for every `users` reference
 *                                                      in this schema (organization_users,
 *                                                      invitations, verification tokens,
 *                                                      knowledge_sources.created_by). A transcript
 *                                                      that lost its participant is a transcript
 *                                                      nobody can answer a subject-access request
 *                                                      from.
 *
 * ═══ THE BOT KEY IS `RESTRICT`, WHICH CLOSES `BotService::delete()`'s TODO(phase-e) ══════════
 *
 * That TODO named three candidate answers — refuse the delete, cascade, or null the bot reference
 * and keep the transcript — and predicted the answer. This migration makes it, and the reasoning is
 * worth stating here rather than only in the service, because the constraint is what enforces it
 * and the service is only what produces a readable error:
 *
 *   CASCADE is the wrong answer and it is the DANGEROUS wrong answer. Deleting a bot would silently
 *   destroy every conversation ever held with it, every provider call that billed the organization
 *   for them, and every piece of feedback a customer left — from a button whose label says "delete
 *   bot". The TODO says this in as many words: "the wrong fix — adding ON DELETE CASCADE to the
 *   conversation key — is the one that makes the data loss invisible."
 *
 *   SET NULL keeps the transcript and loses what it was a transcript OF. Every analytics aggregate
 *   in §8.23 groups by bot; a null bot id is a row that appears in the totals and in no breakdown,
 *   and the cost lands on whoever is reconciling a provider invoice months later. It also makes
 *   `bot_id` nullable, which removes this table from the NOT NULL FK chain that kb-tenancy-isolation
 *   NN1 requires — the organization would still be on the row, but the retrieval scope the
 *   conversation was held under would be unrecoverable.
 *
 *   RESTRICT refuses the delete, and `BotStatus::Archived` is what the operator does instead. That
 *   value already exists and its own docblock already says why: "Permanently withdrawn. The row
 *   survives so conversation history and audit entries resolve." An archived bot answers nobody, is
 *   read-only, and keeps every transcript resolvable. The cost is real and is accepted: a bot can
 *   never be fully deleted once it has held one conversation, and the console has to say so rather
 *   than showing a delete button that 409s. `App\Enums\BotDeletion::HasConversations` is that
 *   refusal, and `BotService::delete()` renders it with the sentence explaining archiving.
 *
 * IT IS THE COMPOSITE KEY `(organization_id, bot_id) -> bots (organization_id, id)`, not a simple
 * one, and that is the guard `bots_org_scoped_key` was created for: without it a conversation could
 * name another tenant's bot, and every downstream filter would AGREE, because the row would have
 * told them whose bot answered.
 *
 * ═══ ONE PARTICIPANT, NOT TWO ════════════════════════════════════════════════════════════════
 *
 * §16.6 says "User ID or anonymous session ID", and `conversations_participant_exclusive` enforces
 * the OR as EXACTLY ONE rather than AT LEAST ONE. A signed-in visitor on hosted chat also carries a
 * session cookie, so "both" is a reachable state and it is the one that must not be stored: with
 * both columns populated, §8.23's "unique sessions" counts the same person twice, and — worse — two
 * different code paths would each pick a different column as "the participant" and neither would be
 * wrong. The write path decides once: an authenticated visitor is a `user_id` conversation.
 *
 * `conversations_authenticated_channel` is the other half, generated from
 * `ConversationChannel::authenticatedOnly()`: the playground proves an identity through the admin
 * SPA session and the API through a Sanctum token that belongs to a user, so an anonymous
 * conversation on either is not a state this table will hold.
 *
 * ═══ CONSENT IS A SNAPSHOT, NOT A FLAG ══════════════════════════════════════════════════════
 *
 * `bots.consent_text` is editable, and a conversation that recorded only "consent: true" would be a
 * record of agreement to whatever the text says TODAY. `consent_text_snapshot` is what the visitor
 * was actually shown, copied at the moment they were shown it, and
 * `conversations_consent_snapshot_present` refuses a row that claims consent was required without
 * carrying it. That is the same argument `bots_consent_text_present_when_collecting` makes one
 * table up, moved to the row that has to survive an edit.
 *
 * ═══ WHAT IS NOT ON THIS TABLE, AND ONE CONTRADICTION SINCE RULED ON ════════════════════════
 *
 * NO `created_at`. `started_at` IS the creation time — §16.6 names it "Started time" — and
 * `App\Models\Conversation::CREATED_AT` points Eloquent at it. Two columns holding one fact is how
 * they end up disagreeing. `updated_at` and `last_activity_at` are NOT the same fact and both are
 * kept: `updated_at` moves when any column changes (a status flip, a consent record),
 * `last_activity_at` moves only when a turn is taken, and the second is what the idle sweeper and
 * the console's "last active" column read.
 *
 * THIS TABLE IS NOT RANGE-PARTITIONED, AND `postgresql-patterns`' DEFINITION OF DONE ASKS FOR
 * `messages` AND `provider_calls` TO BE. This paragraph reported that as an unresolved
 * contradiction when the table was written; it was RULED ON 2026-08-27 and the ruling is that
 * these two stay unpartitioned. Read the reasoning, not the outcome, because the outcome has a
 * stated expiry and the reasoning is what tells you whether it has arrived.
 *
 * The ruling is not "the skill is wrong". It is that the skill's precedent does not transfer, and
 * the precedent is the argument: `audit_logs` IS partitioned (ADR-041) and is the right shape for
 * it — append-only, no children, nothing holding a foreign key into it — so `PRIMARY KEY
 * (id, created_at)` costs it nothing. `messages` is the opposite shape. A partitioned table's
 * unique constraints must contain the partition key, so `messages` would take `PRIMARY KEY
 * (id, created_at)`, the three tables referencing it — `citations`, `retrieval_traces`,
 * `feedback` — would each carry a denormalized `message_created_at` and a composite foreign key,
 * and `provider_calls.message_id` could not be a foreign key at all. That trades FOUR referential-
 * integrity constraints for a retention mechanism this table already has by another route, and
 * `docs/22` § Q3 is this repository's own record of what a quietly weakened composite key costs:
 * a test wrote a row violating BOTH halves, asserted a disjunction, and dropping the constraint
 * left forty tests green. It also pulls in a partition-creation and pruning subsystem of the kind
 * `audit_logs` needed two commands and a scheduler entry for.
 *
 * What this table does instead is `retention_expires_at` with a partial index, so retention
 * deletes CONVERSATIONS (cascading to their messages) in bounded batches rather than sweeping
 * `messages` by date.
 *
 * HOW THIS RULING BECOMES WRONG, since one with no such statement is the shape `docs/22` § Q5 and
 * § Q6 are both about. It is weaker than `DETACH PARTITION CONCURRENTLY`, and the skill is right
 * that converting after rows exist is a full-table rewrite. So the trade re-opens on a
 * MEASUREMENT and never on a feeling: when a retention sweep's p99 stops fitting its batch window,
 * or the partial index stops being chosen for it. Whoever notices that owns re-opening this, and
 * the migration that does it is a rewrite budgeted as one — not a follow-up to something else.
 */
return new class extends Migration
{
    public function up(): void
    {
        $channels = $this->quotedList(ConversationChannel::values());
        $authenticatedOnly = $this->quotedList(ConversationChannel::authenticatedOnly());
        $statuses = $this->quotedList(ConversationStatus::values());

        $this->run(<<<SQL
            CREATE TABLE conversations (
                id                    char(26) COLLATE "C" PRIMARY KEY,
                organization_id       char(26) COLLATE "C" NOT NULL
                                      REFERENCES organizations (id) ON DELETE RESTRICT,

                -- NOT NULL, and RESTRICT through the composite key at the bottom. See the docblock:
                -- this is the decision BotService::delete()'s TODO(phase-e) was waiting for.
                bot_id                char(26) COLLATE "C" NOT NULL,

                -- ── the participant: exactly one of these two ────────────────────────────────
                -- `users` is RESTRICT, matching every other reference to that table in this schema.
                user_id               char(26) COLLATE "C" REFERENCES users (id) ON DELETE RESTRICT,
                -- An opaque token this platform mints and the client stores. COLLATE "C" because it
                -- is only ever compared for exact equality, so memcmp is the correct comparison and
                -- a libc/ICU upgrade cannot invalidate the index over it.
                anonymous_session_id  text COLLATE "C",

                channel               text NOT NULL,
                status                text NOT NULL DEFAULT 'active',

                -- BCP-47. Nullable: "we were not told" is a real state and is different from "en".
                locale                text COLLATE "C",

                -- ── consent, as a snapshot ──────────────────────────────────────────────────
                consent_required      boolean NOT NULL DEFAULT false,
                consent_granted_at    timestamptz,
                consent_text_snapshot text,

                -- ── the clock ───────────────────────────────────────────────────────────────
                -- `started_at` IS the creation time; there is no separate created_at. See docblock.
                started_at            timestamptz NOT NULL DEFAULT now(),
                last_activity_at      timestamptz NOT NULL DEFAULT now(),
                -- NULL means "the organization's own policy decides". A number of days on the bot
                -- resolves into a timestamp here at creation, so the sweeper reads one column.
                retention_expires_at  timestamptz,
                updated_at            timestamptz NOT NULL DEFAULT now(),

                -- ── the closed vocabularies, generated from the enums ────────────────────────
                CONSTRAINT conversations_channel_check CHECK (channel IN ({$channels})),
                CONSTRAINT conversations_status_check  CHECK (status  IN ({$statuses})),

                -- ── the participant rules ───────────────────────────────────────────────────
                -- EXACTLY ONE. See the docblock: "both" is reachable for a signed-in visitor on
                -- hosted chat and is the state that double-counts unique sessions.
                CONSTRAINT conversations_participant_exclusive
                    CHECK (num_nonnulls(user_id, anonymous_session_id) = 1),
                -- Generated from ConversationChannel::authenticatedOnly(), so the enum and the
                -- constraint cannot drift.
                CONSTRAINT conversations_authenticated_channel
                    CHECK (channel NOT IN ({$authenticatedOnly}) OR user_id IS NOT NULL),
                -- The token is ours, so its grammar is ours to pin. 16 characters is the floor
                -- below which it stops being unguessable; a session id is a bearer value.
                CONSTRAINT conversations_anonymous_session_shape
                    CHECK (anonymous_session_id IS NULL
                           OR anonymous_session_id ~ '^[A-Za-z0-9_-]{16,128}\$'),

                -- ── shape ───────────────────────────────────────────────────────────────────
                CONSTRAINT conversations_locale_shape
                    CHECK (locale IS NULL OR locale ~ '^[a-z]{2,3}(-[A-Za-z0-9]{2,8}){0,3}\$'),
                -- NULL means "not set" everywhere in this schema. An empty or whitespace-only
                -- string would be a SECOND spelling of it that every renderer has to test for
                -- separately, and one of them forgets.
                CONSTRAINT conversations_consent_text_not_blank
                    CHECK (consent_text_snapshot IS NULL OR btrim(consent_text_snapshot) <> ''),

                -- ── consent ─────────────────────────────────────────────────────────────────
                -- A row claiming consent was required must carry WHAT WAS SHOWN. Recording only a
                -- boolean makes the record mean "they agreed to whatever the bot says today".
                CONSTRAINT conversations_consent_snapshot_present
                    CHECK (consent_required = false OR consent_text_snapshot IS NOT NULL),
                -- Consent that was never asked for cannot have been granted.
                CONSTRAINT conversations_consent_not_granted_unasked
                    CHECK (consent_granted_at IS NULL OR consent_required = true),

                -- ── the clock is monotonic ──────────────────────────────────────────────────
                -- Activity before the start is a clock skew or a backfill bug, and it renders as a
                -- negative duration in every dashboard that subtracts them.
                CONSTRAINT conversations_activity_after_start
                    CHECK (last_activity_at >= started_at),
                CONSTRAINT conversations_retention_after_start
                    CHECK (retention_expires_at IS NULL OR retention_expires_at > started_at),

                -- ── the ownership guard ─────────────────────────────────────────────────────
                -- COMPOSITE, against `bots_org_scoped_key`. A simple key on `bot_id` would let a
                -- conversation name ANOTHER TENANT'S bot, and every downstream filter would agree
                -- with it — because the row would have told them whose bot answered.
                CONSTRAINT conversations_bot_same_org
                    FOREIGN KEY (organization_id, bot_id)
                    REFERENCES bots (organization_id, id) ON DELETE RESTRICT
            )
        SQL);

        // ── INDEXES ──────────────────────────────────────────────────────────────────────────
        //
        // ORG-LEADING, ALWAYS (postgresql-patterns). Never (bot_id, ...) or (status, ...): a
        // non-tenant-leading index makes the organization predicate a filter over every tenant's
        // rows in that bucket, and PG 18's skip scan does not rescue it at organization scale.

        // The console's conversation list: this organization's threads for this bot, most recently
        // active first. Also the FK-child index for `conversations_bot_same_org`, so a bot delete's
        // referential check is an index scan rather than a sequential scan of every conversation in
        // the platform — which is what would make the RESTRICT above cost 40 minutes instead of a
        // millisecond.
        $this->run(<<<'SQL'
            CREATE INDEX conversations_org_bot_activity
                ON conversations (organization_id, bot_id, last_activity_at DESC)
        SQL);

        // "This organization's open threads", and the analytics' date-ranged counts.
        $this->run(<<<'SQL'
            CREATE INDEX conversations_org_status_started
                ON conversations (organization_id, status, started_at DESC)
        SQL);

        // "Everything this person said to us" — the subject-access request, and the signed-in
        // visitor's own history. Partial: most rows on the public channels have no user.
        $this->run(<<<'SQL'
            CREATE INDEX conversations_org_user_started
                ON conversations (organization_id, user_id, started_at DESC)
                WHERE user_id IS NOT NULL
        SQL);

        // §8.23's "unique sessions", and the widget resuming a thread it already holds a token for.
        $this->run(<<<'SQL'
            CREATE INDEX conversations_org_session
                ON conversations (organization_id, anonymous_session_id)
                WHERE anonymous_session_id IS NOT NULL
        SQL);

        // NOT ORG-LEADING, AND THAT IS DELIBERATE. This one exists for the REFERENTIAL CHECK behind
        // `user_id REFERENCES users (id) ON DELETE RESTRICT`, and a referential check has no
        // organization in hand — PostgreSQL asks "does any row anywhere reference this user", so an
        // index whose leading column is `organization_id` cannot serve it and the check degrades to
        // a sequential scan of the whole table. It is an index over a tenant-owned table and leaks
        // nothing: an index is not a query, and every query in this application still goes through
        // one of the four above.
        $this->run(<<<'SQL'
            CREATE INDEX conversations_user_referential
                ON conversations (user_id) WHERE user_id IS NOT NULL
        SQL);

        // The retention sweeper's claim query. Partial on both terms, so it indexes only the rows
        // that can ever be due — a conversation with no deadline is never swept, and one already
        // `expired` has been.
        //
        // tenancy-exempt: the retention sweeper is org-agnostic by construction. It runs on a
        // schedule with no request and no tenant context, and asking "which conversations are due
        // across the platform" is the whole of its job; scoping it per organization would mean
        // enumerating organizations and issuing one query each, which is the same read with more
        // statements. The rows it finds are then processed one organization at a time, and every
        // WRITE it performs carries the organization it read off the row.
        $this->run(<<<'SQL'
            CREATE INDEX conversations_retention_due
                ON conversations (retention_expires_at)
                WHERE retention_expires_at IS NOT NULL AND status <> 'expired'
        SQL);
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS conversations');
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
        // Schema::getConnection() rather than the DB facade: an arch test pins that facade to
        // App\Repositories\Eloquent, where the organization scope is applied.
        Schema::getConnection()->statement($sql);
    }
};
