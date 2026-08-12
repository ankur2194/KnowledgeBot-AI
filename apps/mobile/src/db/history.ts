import type { SQLiteDatabase } from 'expo-sqlite';

/**
 * Offline conversation history.
 *
 * WHY SQLITE AND NOT SECURESTORE: SecureStore values have historically been rejected above roughly
 * 2 KB, and a transcript is not 2 KB. The database lives in the app's private directory, which is
 * sandboxed but NOT encrypted at rest beyond whatever full-disk encryption the OS provides — so
 * nothing that belongs in the keychain goes here. No token, no `expires_at`, no credential of any
 * kind. Conversation text only.
 *
 * WHY EVERY KEY CARRIES THE ORGANIZATION *AND* THE USER: one device serves several people. A shared
 * tablet, a handover, a re-login as a colleague — the browser's "one session per profile" assumption
 * does not hold here. Those are three cases, not one, and the organization predicate only covers two
 * of them:
 *
 *   - a different person in a DIFFERENT organization  -> `organization_id`
 *   - a different person in the SAME organization     -> `user_id`      <- the handover case
 *   - the same person signing in again                -> the purge, below
 *
 * The middle one is a colleague, at the next desk, on the same tablet, in the same tenant — the most
 * likely of the three to actually happen and the only one an org predicate cannot see. So both
 * columns are `NOT NULL` on every row and both appear in every predicate. docs/04 §8.22 makes the
 * server's own record carry "Authenticated user or anonymous session" alongside the organization,
 * and §8.22 lets an administrator read a colleague's conversations only when the organization's
 * privacy policy allows it — so user attribution is an axis of the model, not a client invention.
 * This is the client-side layer of that contract, never a substitute for it.
 *
 * WHY THE PURGE IS UNCONDITIONAL ON LOGOUT *AND ON SIGN-IN*: a scoped cache that survives an
 * identity change is the mobile shape of the cross-tenant leak. It is not enough to stop READING it
 * — a later build that changes a key, a restore, or a bug that resolves the org or the user
 * differently makes stale rows readable again. Sign-out deletes the file's contents; so does
 * sign-in, because a token that merely EXPIRED never ran a sign-out at all and the rows it left
 * behind are still on the disk when the next person logs in. There is no soft variant of either.
 */

/**
 * Bumped to force a full rebuild. A migration that half-applies leaves scoped rows behind.
 *
 * 2 — `user_id NOT NULL` added to both tables. THE BUMP IS THE MIGRATION: `migrate` drops and
 * recreates rather than `ALTER`ing (see below), so the cost of adding a scope column here is one
 * integer and a rebuild of a derived cache Laravel can refill. Leaving it at 1 is the expensive
 * mistake, not a cheap one: a device already carrying the v1 tables would take the early return,
 * skip the rebuild, and every statement below would fail at runtime on `no such column: user_id`.
 */
const SCHEMA_VERSION = 2;

/**
 * Every table is scoped in the SCHEMA, not merely in the queries, and it is scoped on BOTH axes. A
 * predicate can be forgotten in one query out of twenty; a NOT NULL column plus a composite index
 * makes the unscoped query fail to compile a plan anyone would accept in review.
 *
 * `user_id` is `NOT NULL` on `messages` too, even though no reader of that table exists yet. That is
 * the cheap moment to do it: the column costs nothing while there are no rows and no callers, and a
 * writer added later cannot insert a message without deciding whose it is. A `messages` table that
 * carried only `organization_id` would let the first read of a transcript be written unscoped by
 * omission rather than by decision.
 *
 *   CREATE TABLE conversations (
 *     id TEXT PRIMARY KEY NOT NULL,
 *     organization_id TEXT NOT NULL,
 *     user_id TEXT NOT NULL,
 *     bot_id TEXT NOT NULL,
 *     title TEXT,
 *     updated_at TEXT NOT NULL
 *   );
 *   CREATE INDEX conversations_org_user_updated
 *     ON conversations (organization_id, user_id, updated_at DESC);
 *
 *   CREATE TABLE messages (
 *     id TEXT PRIMARY KEY NOT NULL,
 *     organization_id TEXT NOT NULL,
 *     user_id TEXT NOT NULL,
 *     conversation_id TEXT NOT NULL REFERENCES conversations (id) ON DELETE CASCADE,
 *     role TEXT NOT NULL,
 *     content TEXT NOT NULL,
 *     created_at TEXT NOT NULL
 *   );
 *   CREATE INDEX messages_org_user_conversation
 *     ON messages (organization_id, user_id, conversation_id, created_at);
 */
const CREATE_SCHEMA = `
  CREATE TABLE conversations (
    id TEXT PRIMARY KEY NOT NULL,
    organization_id TEXT NOT NULL,
    user_id TEXT NOT NULL,
    bot_id TEXT NOT NULL,
    title TEXT,
    updated_at TEXT NOT NULL
  );
  CREATE INDEX conversations_org_user_updated
    ON conversations (organization_id, user_id, updated_at DESC);

  CREATE TABLE messages (
    id TEXT PRIMARY KEY NOT NULL,
    organization_id TEXT NOT NULL,
    user_id TEXT NOT NULL,
    conversation_id TEXT NOT NULL REFERENCES conversations (id) ON DELETE CASCADE,
    role TEXT NOT NULL,
    content TEXT NOT NULL,
    created_at TEXT NOT NULL
  );
  CREATE INDEX messages_org_user_conversation
    ON messages (organization_id, user_id, conversation_id, created_at);
`;

/**
 * Creates the schema, or REBUILDS it from scratch when `SCHEMA_VERSION` has moved.
 *
 * DROP AND RECREATE, NEVER `ALTER`. "Bumped to force a full rebuild" is the whole contract of that
 * constant: this cache is derived data that Laravel can hand back in full, so the cheap correct
 * answer to "the shape changed" is to have no rows at all. An `ALTER` migration is the expensive
 * wrong one — it carries rows written under the OLD scoping rules into a build with new ones, and a
 * half-applied migration leaves scoped rows behind under a schema that no longer describes them.
 * The v1 -> v2 bump is exactly that case: v1 rows carry no `user_id`, so there is no value an
 * `ALTER … ADD COLUMN user_id NOT NULL` could honestly give them. There is nothing here worth
 * saving; there is something here worth deleting.
 *
 * `PRAGMA foreign_keys = ON` IS THE LOAD-BEARING LINE IN THIS FILE. SQLite ships with foreign-key
 * enforcement OFF by default and per connection, so without it the `ON DELETE CASCADE` on
 * `messages.conversation_id` is a comment: the constraint parses, the table is created, every
 * statement succeeds, and deleting a conversation silently orphans its messages — rows that still
 * carry an organization_id and a transcript and are now reachable by nothing that would ever delete
 * them. That is precisely the leak this file exists to prevent, and it fails by being invisible.
 *
 * It is issued OUTSIDE any transaction, and that is not a style choice either: `PRAGMA foreign_keys`
 * is a NO-OP inside a transaction. Set it in a `withTransactionAsync` block and it silently does
 * nothing, which looks exactly like setting it correctly.
 */
export async function migrate(db: SQLiteDatabase): Promise<void> {
  await db.execAsync('PRAGMA foreign_keys = ON');

  const row = await db.getFirstAsync<{ user_version: number }>('PRAGMA user_version');
  if ((row?.user_version ?? 0) === SCHEMA_VERSION) return;

  // Child first: with foreign keys now ON, dropping `conversations` while `messages` still
  // references it is a constraint violation rather than a cascade.
  await db.execAsync(`
    DROP TABLE IF EXISTS messages;
    DROP TABLE IF EXISTS conversations;
    ${CREATE_SCHEMA}
  `);

  // Interpolated because SQLite does not accept a bound parameter in a PRAGMA. Safe by
  // construction: SCHEMA_VERSION is a module-level integer literal, not an input.
  await db.execAsync(`PRAGMA user_version = ${SCHEMA_VERSION}`);
}

export interface CachedConversation {
  readonly id: string;
  readonly organization_id: string;
  /** Whose conversation it is. See the header: the same-organization handover is the case the
   *  organization predicate cannot see. */
  readonly user_id: string;
  readonly bot_id: string;
  readonly title: string | null;
  readonly updated_at: string;
}

/**
 * `organizationId` and `userId` are both required and neither has a default. An optional scope
 * parameter is one call site away from an unscoped read, and the unscoped read SUCCEEDS — it returns
 * every conversation on the device, correctly rendered, with no error anywhere. That is the whole
 * shape of the bug: a device serves several people, so "all rows" and "my rows" are different
 * answers that look identical to whoever is holding the phone.
 *
 * BOTH predicates are assertions under test in tests/history.test.ts, and the fixture is 2x2 —
 * two organizations CROSSED WITH two users — because each predicate can only be proven by rows the
 * OTHER predicate would have returned. A two-organization / one-user fixture cannot fail when
 * `AND user_id = ?` is deleted, and a one-organization / two-user fixture cannot fail when
 * `WHERE organization_id = ?` is deleted. Each was deleted in turn and each failure observed.
 */
export async function listConversations(
  db: SQLiteDatabase,
  organizationId: string,
  userId: string,
): Promise<readonly CachedConversation[]> {
  return db.getAllAsync<CachedConversation>(
    `SELECT id, organization_id, user_id, bot_id, title, updated_at
       FROM conversations
      WHERE organization_id = ?
        AND user_id = ?
      ORDER BY updated_at DESC`,
    [organizationId, userId],
  );
}

/**
 * THE `DO UPDATE` LIST CONTAINS NEITHER `organization_id` NOR `user_id`, AND THAT IS THE POINT.
 *
 * `id` is server-minted and globally unique in theory. In practice this table is written from
 * whatever the last response said, and the one thing an upsert must never do is let a row arriving
 * under one identity rewrite the owner of a row already stored under another. Include either scope
 * column in the update list and a colliding id does exactly that — silently, in one statement, with
 * no delete and no insert to notice.
 *
 * The `WHERE conversations.organization_id = excluded.organization_id AND conversations.user_id =
 * excluded.user_id` guard goes one step further: a collision across EITHER axis updates NOTHING at
 * all, rather than rewriting the title and the timestamp of a row belonging to someone else while
 * leaving its ownership intact. Half-overwriting a foreign row is a smaller leak than re-owning it
 * and is still a leak. The two conjuncts are proven separately, by two fixtures that differ on one
 * axis each — a same-organization collision is the handover case and the organization conjunct is
 * blind to it.
 */
export async function upsertConversation(
  db: SQLiteDatabase,
  conversation: CachedConversation,
): Promise<void> {
  await db.runAsync(
    `INSERT INTO conversations (id, organization_id, user_id, bot_id, title, updated_at)
          VALUES (?, ?, ?, ?, ?, ?)
     ON CONFLICT(id) DO UPDATE SET
            bot_id = excluded.bot_id,
            title = excluded.title,
            updated_at = excluded.updated_at
          WHERE conversations.organization_id = excluded.organization_id
            AND conversations.user_id = excluded.user_id`,
    [
      conversation.id,
      conversation.organization_id,
      conversation.user_id,
      conversation.bot_id,
      conversation.title,
      conversation.updated_at,
    ],
  );
}

/**
 * Called on logout, on a 401, and on SIGN-IN, from the same place that purges SecureStore and
 * replaces the QueryClient. All three happen together or none of them is sufficient: the token is
 * gone, the in-memory cache is gone, and the on-disk cache is gone.
 *
 * UNCONDITIONAL. There is no scope predicate here and there must not be one: a purge scoped to "the
 * org and user we think we are signed in as" cannot remove rows written under an identity the app
 * has since forgotten, mis-resolved, or stopped writing — which is the state a logout is most likely
 * to be recovering from, and precisely the state a sign-in after a silent token expiry is ALWAYS in.
 * Both tables, every row, no soft variant.
 *
 * `messages` first, so the delete does not depend on the cascade being armed. The cascade IS armed
 * (see `migrate`), but a purge that only works when a PRAGMA was set correctly is a purge with a
 * silent failure mode, and this is the one operation that must not have one.
 */
export async function purgeAll(db: SQLiteDatabase): Promise<void> {
  await db.withTransactionAsync(async () => {
    await db.execAsync('DELETE FROM messages');
    await db.execAsync('DELETE FROM conversations');
  });
}
