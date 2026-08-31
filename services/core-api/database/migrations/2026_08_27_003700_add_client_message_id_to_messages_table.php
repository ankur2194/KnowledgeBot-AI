<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * `messages.client_message_id` — the durable half of `chat.message`'s idempotency.
 *
 * ═══ WHY THE COLUMN EXISTS AT ALL ═══════════════════════════════════════════════════════════
 *
 * The public chat body is exactly `{client_message_id, content}` and the id is a CLIENT-MINTED
 * ULID, stable across re-renders and retries of the same composed message
 * (`kb-internal-api-contracts`). Its whole purpose is that a double submit — a StrictMode double
 * effect, an impatient second click, a queued send flushed after a session refresh — collapses into
 * one conversation turn, one provider call and one bill.
 *
 * Without a column the only place that could happen is Valkey, and `kb-error-taxonomy` is explicit
 * that the Valkey record is 24 h of cache "PLUS a durable record". A cache-only dedupe fails OPEN:
 * an evicted or lost key turns the second submit into a second generation, silently, at exactly the
 * moment the platform is unwell.
 *
 * ═══ A PARTIAL UNIQUE INDEX, AND EVERY PART OF THAT PHRASE IS LOAD-BEARING ══════════════════
 *
 * NULLABLE, because the column is meaningless on the two message roles a client never submits: an
 * assistant turn is minted here, and a system notice has no client at all. Backfilling those with a
 * generated value would make the column mean two different things.
 *
 * PARTIAL (`WHERE client_message_id IS NOT NULL`) rather than relying on NULL's behaviour under a
 * plain unique index. PostgreSQL does treat two NULLs as distinct so a plain index would also admit
 * many assistant rows — but that is a property of NULL semantics rather than of the rule, and
 * `NULLS NOT DISTINCT` exists since 15 and would silently invert it. Spelling the predicate says
 * what is intended and makes the index smaller: it holds only the rows a client actually submitted.
 *
 * SCOPED TO THE CONVERSATION, NOT TO THE ORGANIZATION AND NOT GLOBAL. `messages` has no
 * `organization_id` column — it reaches its tenant through `conversations` — and a conversation
 * belongs to exactly one organization, so `(conversation_id, client_message_id)` is already
 * tenant-scoped by construction. A GLOBAL unique index would be a cross-tenant collision surface:
 * two organizations whose clients happened to mint the same ULID would refuse each other's
 * messages, which is a denial of service one tenant could aim at another by guessing.
 *
 * ═══ THE SHAPE CHECK IS A ULID AND NOT A FREE STRING ════════════════════════════════════════
 *
 * The value is client-supplied, and this column is compared for exact equality against a value an
 * attacker also supplies. Bounding its grammar means an unbounded string cannot reach a `text`-shaped
 * index, and it keeps the FormRequest and the database saying the same thing about what a message id
 * is. `char(26) COLLATE "C"` for the same reason every other id in this schema is: memcmp is the
 * correct comparison and a libc or ICU upgrade cannot invalidate the index.
 *
 * THE CHECK IS `upper(...)` AND THAT IS NOT A RELAXATION. `Str::ulid()` returns upper case and
 * `HasUlids::newUniqueId()` lower-cases it, so BOTH spellings exist in this system, and the framework
 * rule the FormRequest applies (`ulid` -> `Str::isUlid()`) is case-insensitive. An uppercase-only
 * CHECK would refuse an id minted by half the clients in the product, as a 500 from a constraint,
 * on a value the validator had already accepted.
 *
 * THE UNIQUENESS IS STILL CASE-SENSITIVE, deliberately: the index is on the raw column under
 * `COLLATE "C"`, so two spellings of one ULID are two rows. That is the correct trade — a client
 * that re-sends its own message re-sends its own bytes, and folding case in the index would make the
 * dedupe key a normalisation nobody else in the schema performs.
 *
 * ═══ LOCK BEHAVIOUR ═════════════════════════════════════════════════════════════════════════
 *
 * `ADD COLUMN` of a NULLABLE column with no default is metadata-only on PostgreSQL 11+ and takes an
 * ACCESS EXCLUSIVE lock for the duration of the catalogue write, which is microseconds.
 * `CREATE INDEX` — WITHOUT `CONCURRENTLY` — takes a SHARE lock that blocks writes for the build. On
 * this table today that is nothing, because `messages` is empty until this phase ships; the note is
 * here so the next index on this table is written `CONCURRENTLY` and outside a transaction, which is
 * what `postgresql-patterns` requires once the table is live.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->run(<<<'SQL'
            ALTER TABLE messages
                ADD COLUMN client_message_id char(26) COLLATE "C"
        SQL);

        $this->run(<<<'SQL'
            ALTER TABLE messages
                ADD CONSTRAINT messages_client_message_id_shape
                CHECK (
                    client_message_id IS NULL
                    OR upper(client_message_id) ~ '^[0-9A-HJKMNP-TV-Z]{26}$'
                )
        SQL);

        // A USER TURN CARRIES ONE AND NOTHING ELSE DOES. The assistant row is minted here and a
        // system notice has no client, so a value on either is a row whose provenance is a lie —
        // and it would occupy the unique index slot the client's own retry needs.
        $this->run(<<<'SQL'
            ALTER TABLE messages
                ADD CONSTRAINT messages_client_message_id_only_on_user
                CHECK (client_message_id IS NULL OR role = 'user')
        SQL);

        $this->run(<<<'SQL'
            CREATE UNIQUE INDEX messages_conversation_client_message_unique
                ON messages (conversation_id, client_message_id)
                WHERE client_message_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        // REVERSIBLE, AND IN THE OPPOSITE ORDER. Dropping the column would take the index and both
        // constraints with it, but naming each one means a partial rollback — an index that was
        // created by hand during an incident, say — still leaves this migration able to run.
        $this->run('DROP INDEX IF EXISTS messages_conversation_client_message_unique');
        $this->run('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_client_message_id_only_on_user');
        $this->run('ALTER TABLE messages DROP CONSTRAINT IF EXISTS messages_client_message_id_shape');
        $this->run('ALTER TABLE messages DROP COLUMN IF EXISTS client_message_id');
    }

    private function run(string $sql): void
    {
        Schema::getConnection()->statement($sql);
    }
};
