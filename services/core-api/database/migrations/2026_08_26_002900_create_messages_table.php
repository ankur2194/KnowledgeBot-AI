<?php

declare(strict_types=1);

use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * One turn (docs/11 §16.6).
 *
 * ═══ NO `organization_id`, AND THAT IS NN1 SATISFIED RATHER THAN SKIPPED ═════════════════════
 *
 * kb-tenancy-isolation NN1 admits two shapes: a column, or "a NOT NULL foreign-key chain no code
 * path can bypass". It names this exact chain — `citations/feedback -> messages -> conversations` —
 * as an example of the second. `conversation_id` is NOT NULL with a real foreign key, and
 * `conversations.organization_id` is NOT NULL with a real foreign key, so every message reaches
 * exactly one organization and no insert can produce one that does not.
 *
 * DENORMALIZING `organization_id` ONTO THIS TABLE WAS CONSIDERED AND REFUSED. It would buy an
 * org-leading index and cost the one thing this chain guarantees: a denormalized copy can DISAGREE
 * with its parent, and a message whose copy says Org A while its conversation says Org B is a row
 * that passes an org-scoped query in one organization and renders in the other's transcript. The
 * one place this schema does denormalize a tenant key — `bot_source_assignments` — does it because
 * that row legitimately spans two organizations and the copy is guarded by TWO composite foreign
 * keys that make the disagreement unrepresentable. There is no such guard available here without
 * making `conversations (organization_id, id)` a second unique index and carrying a composite key
 * on every child, which is a lot of machinery to avoid one join.
 *
 * CONSEQUENCE, STATED SO IT IS NOT DISCOVERED: `App\Models\Message` carries NO
 * `#[ScopedBy(OrganizationScope::class)]`, because that scope appends
 * `where messages.organization_id = ?` against a column that does not exist — a 42703 on every
 * read, from every surface. The isolation mechanism is the same one `OrganizationUser`,
 * `OrganizationInvitation` and `EmailVerificationToken` rely on under ADR-043: every read goes
 * through a repository method taking `organization_id` as a required positional argument, joined to
 * `conversations`. The model's docblock carries this in full.
 *
 * ═══ THE INDEXES LEAD WITH `conversation_id`, WHICH IS THE TENANT TERM ONE TABLE UP ══════════
 *
 * The house rule is that a tenant-owned index leads with `organization_id`. This table has none, so
 * the equivalent is to lead with the parent whose organization is fixed: every read of this table
 * is "the messages of THIS conversation", and a conversation belongs to exactly one organization,
 * so `(conversation_id, ...)` is a strictly narrower leading term than `organization_id` would have
 * been. The same reasoning applies to `retrieval_traces`, `citations` and `feedback`, each of which
 * leads with `message_id`.
 *
 * ═══ THE FOREIGN KEY ACTIONS ════════════════════════════════════════════════════════════════
 *
 *   conversation_id    -> conversations  CASCADE      postgresql-patterns blesses this one by name:
 *                                                     "messages -> conversations may cascade (same
 *                                                     lifetime, same retention)". A conversation
 *                                                     removed by the retention sweeper takes its
 *                                                     turns with it, because a thread with no turns
 *                                                     is not a shorter record, it is a wrong one.
 *   parent_message_id  -> messages       SET NULL     of ONE COLUMN. See below.
 *   provider_call_id   -> provider_calls SET NULL     added by 2026_08_26_003000, which creates the
 *                                                     table it points at. See that file.
 *
 * ═══ THE SELF-KEY IS COMPOSITE, AND THAT IS A TENANT GUARD ══════════════════════════════════
 *
 * `parent_message_id` is set when a turn is retried (§16.6). A SIMPLE key on `messages (id)` would
 * let a retry name a message in a DIFFERENT conversation — and since a conversation is what carries
 * the organization, that is a retry pointing at another tenant's message, with no column anywhere
 * on this row to contradict it. The composite `(conversation_id, parent_message_id) -> messages
 * (conversation_id, id)` makes it unrepresentable, in the same construction and for the same reason
 * as `bots_connection_same_org` one layer up. MATCH SIMPLE is what makes it correct on a row with no
 * parent: a multi-column foreign key is not checked at all when any referencing column is NULL.
 *
 * `ON DELETE SET NULL (parent_message_id)` NAMES ITS COLUMN, and the naming is load-bearing.
 * PostgreSQL's default is to null EVERY referencing column, which here includes `conversation_id` —
 * declared NOT NULL — so the unqualified form would turn a legal delete into a constraint violation
 * inside a cascade. The column-list form is PostgreSQL 15+; this schema pins 18.
 *
 * ═══ `content` IS NULLABLE ON PURPOSE ═══════════════════════════════════════════════════════
 *
 * An assistant row is inserted as `pending` BEFORE any token exists — that is the whole reason
 * `MessageStatus` has a pre-terminal half, and it is what gives first-token latency something to
 * attach to. NULL means "not produced yet" and is a different fact from an empty string, which
 * would mean "produced nothing"; `messages_content_not_blank` refuses the second spelling and
 * `messages_content_present_when_complete` refuses a settled answer that never arrived.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roles = $this->quotedList(MessageRole::values());
        $statuses = $this->quotedList(MessageStatus::values());
        $rolesWithoutProviderCalls = $this->quotedList(MessageRole::withoutProviderCalls());

        $this->run(<<<SQL
            CREATE TABLE messages (
                id                char(26) COLLATE "C" PRIMARY KEY,

                -- NOT NULL and CASCADE. This is the whole of NN1 for this table: no code path can
                -- produce a message that does not reach an organization.
                conversation_id   char(26) COLLATE "C" NOT NULL
                                  REFERENCES conversations (id) ON DELETE CASCADE,

                role              text NOT NULL,
                -- NULL until produced. See the docblock: it is a different fact from ''.
                content           text,
                status            text NOT NULL,

                -- Set when this turn is a RETRY of an earlier one. Composite-guarded below.
                parent_message_id char(26) COLLATE "C",

                -- The provider attempt that produced this answer. The foreign key is added by the
                -- NEXT migration, because `provider_calls` does not exist yet and the two tables
                -- reference each other: a provider call names the message it is answering, and the
                -- message names the call that settled it. The insert order is unambiguous and needs
                -- no deferral — the message row exists first, the call is recorded against it, then
                -- this column is filled — so the pair is a cycle in the schema and never in time.
                provider_call_id  char(26) COLLATE "C",

                created_at        timestamptz NOT NULL DEFAULT now(),
                -- §16.6 names only the created time, and this column is here anyway: a message row
                -- is written THREE times in a normal streaming turn (pending, streaming, settled),
                -- and without it "when did this answer finish" is unanswerable from the row that
                -- knows. The addition is named rather than left to be noticed.
                updated_at        timestamptz NOT NULL DEFAULT now(),

                -- ── the closed vocabularies, generated from the enums ────────────────────────
                CONSTRAINT messages_role_check   CHECK (role   IN ({$roles})),
                CONSTRAINT messages_status_check CHECK (status IN ({$statuses})),

                -- ── content ─────────────────────────────────────────────────────────────────
                CONSTRAINT messages_content_not_blank
                    CHECK (content IS NULL OR btrim(content) <> ''),
                -- A `complete` message with no content is a turn that reported success and said
                -- nothing. `failed` and `cancelled` may legitimately carry a partial answer or none.
                CONSTRAINT messages_content_present_when_complete
                    CHECK (status <> 'complete' OR content IS NOT NULL),

                -- ── a provider call belongs to an assistant turn ─────────────────────────────
                -- Generated from MessageRole::withoutProviderCalls(). A user turn costs nothing and
                -- a platform notice is produced here, so a provider call on either is a
                -- mis-attributed cost that §8.23's per-bot spend would report as real.
                CONSTRAINT messages_provider_call_only_on_assistant
                    CHECK (provider_call_id IS NULL OR role NOT IN ({$rolesWithoutProviderCalls})),

                -- ── a message is not its own retry ──────────────────────────────────────────
                -- A self-reference would be a cycle the transcript renderer walks forever, and the
                -- foreign key cannot see it: a row referencing itself satisfies the constraint.
                CONSTRAINT messages_parent_is_not_self
                    CHECK (parent_message_id IS NULL OR parent_message_id <> id)
            )
        SQL);

        // THE TARGET OF THE SELF-KEY ABOVE, and vacuous as a uniqueness claim because `id` is
        // already the primary key — exactly as `bots_org_scoped_key` is one table up. It exists so
        // "this retry's parent is in this retry's conversation" is a database fact.
        //
        // It also serves as the FK-child index for `conversation_id`, so the cascade from
        // `conversations` is an index scan. PostgreSQL indexes neither side of a foreign key for
        // you, and an unindexed child is what turns a retention delete into the 40-minute sequential
        // scan postgresql-patterns records for `chunks.source_version_id`.
        $this->run(<<<'SQL'
            CREATE UNIQUE INDEX messages_conversation_scoped_key
                ON messages (conversation_id, id)
        SQL);

        // THE SELF-KEY, AND IT HAS TO BE AN `ALTER TABLE` RATHER THAN A TABLE CONSTRAINT. A foreign
        // key needs its target unique index to EXIST when the constraint is declared, and the index
        // above is over the table being created — so spelling this inside CREATE TABLE fails with
        // 42830, "there is no unique constraint matching given keys for referenced table". The
        // ordering is the whole reason these three statements are separate.
        //
        // See the docblock: a SIMPLE key on `messages (id)` would let a retry name a message in
        // another conversation, and a conversation is what carries the organization. MATCH SIMPLE
        // (the default) is what makes it correct on a row with no parent — a multi-column foreign
        // key is not checked at all when any referencing column is NULL.
        //
        // `ON DELETE SET NULL (parent_message_id)` NAMES ITS COLUMN, and the naming is load-bearing:
        // PostgreSQL's default nulls EVERY referencing column, which here includes `conversation_id`
        // — declared NOT NULL — so the unqualified form would turn a legal delete into a constraint
        // violation inside a cascade. The column-list form is PostgreSQL 15+; this schema pins 18.
        $this->run(<<<'SQL'
            ALTER TABLE messages
                ADD CONSTRAINT messages_parent_same_conversation
                FOREIGN KEY (conversation_id, parent_message_id)
                REFERENCES messages (conversation_id, id)
                ON DELETE SET NULL (parent_message_id)
        SQL);

        // THE TRANSCRIPT READ, and the one index every surface in D3-D6 uses. It leads with
        // `conversation_id` for the reason the docblock gives: this table has no `organization_id`,
        // and the parent whose organization is fixed is the narrowest leading term available.
        //
        // `id` is the tie-break and it is not decoration: two messages can land in the same
        // microsecond (a user turn and its pending assistant row are written together), and without
        // a total order PostgreSQL may legally return them in a different order on a second read —
        // which renders as a transcript whose question follows its answer.
        $this->run(<<<'SQL'
            CREATE INDEX messages_conversation_created
                ON messages (conversation_id, created_at, id)
        SQL);

        // FK-child index for the composite self-key. Partial, because the overwhelming majority of
        // messages are not retries and indexing their NULLs buys nothing.
        $this->run(<<<'SQL'
            CREATE INDEX messages_conversation_parent
                ON messages (conversation_id, parent_message_id)
                WHERE parent_message_id IS NOT NULL
        SQL);

        // FK-child index for `provider_call_id`, whose key the next migration adds. NOT led by
        // `conversation_id`, for the same reason `conversations_user_referential` is not led by
        // `organization_id`: a referential check asks "does any row anywhere reference this call",
        // with no conversation in hand.
        $this->run(<<<'SQL'
            CREATE INDEX messages_provider_call_referential
                ON messages (provider_call_id)
                WHERE provider_call_id IS NOT NULL
        SQL);

        // The diagnostics panel's "which turns are still open" and the reaper that settles a
        // message whose stream died with the worker. Partial on the two pre-terminal states, so it
        // indexes the handful of rows that can ever be stuck rather than every message ever sent.
        $this->run(<<<'SQL'
            CREATE INDEX messages_unsettled
                ON messages (created_at)
                WHERE status IN ('pending', 'streaming')
        SQL);
    }

    public function down(): void
    {
        $this->run('DROP TABLE IF EXISTS messages');
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
