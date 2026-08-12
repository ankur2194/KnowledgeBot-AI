import { afterEach, beforeEach, describe, expect, it } from '@jest/globals';
import type { SQLiteDatabase } from 'expo-sqlite';

import {
  listConversations,
  migrate,
  purgeAll,
  upsertConversation,
  type CachedConversation,
} from '@/db/history';

import { openTestDatabase, pragma } from './fixtures/sqlite';

/**
 * The on-disk transcript cache, against a real SQLite engine.
 *
 * TWO ORGANIZATIONS *CROSSED WITH* TWO USERS IN EVERY ISOLATION FIXTURE, and that is not
 * thoroughness — it is the difference between a test that can fail and one that cannot. With one
 * organization's rows in the table, `SELECT … WHERE organization_id = ?` and `SELECT …` return the
 * same list, so deleting that predicate leaves this file green; with one user's rows in the table
 * the identical argument applies to `AND user_id = ?`. Neither axis can be proven by a fixture that
 * does not vary it, so the seed below varies both and every quadrant is populated. The canary is
 * asserted VISIBLE to its own scope first, because a `WHERE` clause that matches nothing also hides
 * everything, and a test that only proves absence passes just as well against a query that is simply
 * broken.
 *
 * WHY THIS TABLE MATTERS AT ALL. One device serves several people — a shared tablet, a handover, a
 * re-login as a colleague — which is the assumption this app has and a browser profile does not.
 * Note that those are three different cases: the third is a colleague INSIDE THE SAME ORGANIZATION,
 * which the organization predicate cannot see at all, and it is the likeliest of the three. The
 * server enforces tenancy; this is the client-side layer of the same contract, and its failure mode
 * is a correctly rendered list of someone else's conversations, with no error anywhere.
 */

const ORG_A = '01JORGA0000000000000000000';
const ORG_B = '01JORGB0000000000000000000';
const USER_1 = '01JUSER10000000000000000000';
const USER_2 = '01JUSER20000000000000000000';

const conversation = (
  id: string,
  organizationId: string,
  userId: string,
  overrides: Partial<CachedConversation> = {},
): CachedConversation => ({
  id,
  organization_id: organizationId,
  user_id: userId,
  bot_id: '01JBOT00000000000000000000',
  title: `conversation ${id}`,
  updated_at: '2026-08-06T09:00:00Z',
  ...overrides,
});

/**
 * All four quadrants of (organization x user). Each row's title names its quadrant, so a leaked row
 * is identifiable in the failure message rather than being just an unexpected id.
 *
 * The two rows that matter most are `A1` and `A2`: same organization, different people. That pair is
 * the handover, and it is invisible to any assertion written against `organization_id` alone.
 */
async function seedFourQuadrants(database: SQLiteDatabase): Promise<void> {
  await upsertConversation(database, conversation('01JCONV_A1', ORG_A, USER_1, { title: 'A1' }));
  await upsertConversation(database, conversation('01JCONV_A2', ORG_A, USER_2, { title: 'A2' }));
  await upsertConversation(database, conversation('01JCONV_B1', ORG_B, USER_1, { title: 'B1' }));
  await upsertConversation(database, conversation('01JCONV_B2', ORG_B, USER_2, { title: 'B2' }));
}

let db: SQLiteDatabase & { closeSync(): void };

beforeEach(async () => {
  db = openTestDatabase();
  await migrate(db);
});

afterEach(() => {
  db.closeSync();
});

describe('migrate', () => {
  it('arms the ON DELETE CASCADE by turning foreign keys on', async () => {
    // SQLite ships foreign-key enforcement OFF, per connection. Without `PRAGMA foreign_keys = ON`
    // the `REFERENCES … ON DELETE CASCADE` on messages.conversation_id parses, the table is
    // created, every statement succeeds — and deleting a conversation leaves its messages behind,
    // rows still carrying an organization_id and a transcript, now reachable by nothing that would
    // ever delete them. It fails by being invisible, which is why it is asserted directly.
    expect(pragma(db, 'foreign_keys')).toBe(1);
  });

  it('cascades a conversation delete to its messages', async () => {
    // The behavioural half of the assertion above: the PRAGMA is only interesting because of this.
    await upsertConversation(db, conversation('01JCONV1', ORG_A, USER_1));
    await db.runAsync(
      `INSERT INTO messages (id, organization_id, user_id, conversation_id, role, content, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)`,
      [
        '01JMSG1',
        ORG_A,
        USER_1,
        '01JCONV1',
        'assistant',
        'cached answer text',
        '2026-08-06T09:00:01Z',
      ],
    );

    await db.runAsync('DELETE FROM conversations WHERE id = ?', ['01JCONV1']);

    const orphans = await db.getAllAsync('SELECT id FROM messages');
    expect(orphans).toEqual([]);
  });

  it('is a drop-and-recreate, so a rebuild leaves no rows from the previous schema', async () => {
    // "Bumped to force a full rebuild" is the constant's whole contract. An ALTER-style migration
    // carries rows written under the OLD scoping rules into a build with new ones; there is
    // nothing in a derived cache worth saving and something in it worth deleting.
    await upsertConversation(db, conversation('01JCONV1', ORG_A, USER_1));
    await db.runAsync('PRAGMA user_version = 0');

    await migrate(db);

    expect(await listConversations(db, ORG_A, USER_1)).toEqual([]);
  });

  it('rebuilds a device still carrying the v1 schema, which had no user_id at all', async () => {
    // THE UPGRADE PATH, AND THE REASON `SCHEMA_VERSION` MOVED TO 2 RATHER THAN STAYING AT 1.
    //
    // A build already installed on a phone or a simulator holds the v1 tables and `user_version = 1`.
    // Leave the constant at 1 and `migrate` takes its early return, the v1 tables survive, and every
    // statement in src/db/history.ts fails at runtime on `no such column: user_id` — after the
    // release, on a device, not here. Bumping is what turns that into a rebuild, and the rebuild is
    // free because the cache is derived data Laravel can refill.
    //
    // The v1 rows are recreated here VERBATIM rather than by calling an old `migrate`, because the
    // whole point is that they were written by a build this one no longer contains.
    await db.execAsync(`
      DROP TABLE IF EXISTS messages;
      DROP TABLE IF EXISTS conversations;
      CREATE TABLE conversations (
        id TEXT PRIMARY KEY NOT NULL,
        organization_id TEXT NOT NULL,
        bot_id TEXT NOT NULL,
        title TEXT,
        updated_at TEXT NOT NULL
      );
      CREATE TABLE messages (
        id TEXT PRIMARY KEY NOT NULL,
        organization_id TEXT NOT NULL,
        conversation_id TEXT NOT NULL REFERENCES conversations (id) ON DELETE CASCADE,
        role TEXT NOT NULL,
        content TEXT NOT NULL,
        created_at TEXT NOT NULL
      );
      PRAGMA user_version = 1;
    `);
    await db.runAsync(
      `INSERT INTO conversations (id, organization_id, bot_id, title, updated_at)
            VALUES (?, ?, ?, ?, ?)`,
      ['01JCONV_V1', ORG_A, '01JBOT', 'written under v1, owner unknown', '2026-08-06T09:00:00Z'],
    );

    await migrate(db);

    // 1. The unattributable row is gone. There is no value an `ALTER … ADD COLUMN user_id NOT NULL`
    //    could honestly have given it, which is exactly why this is a rebuild and not an ALTER.
    expect(await db.getAllAsync('SELECT id FROM conversations')).toEqual([]);
    // 2. The new column exists on BOTH tables, so a v1 device lands on the v2 schema rather than on
    //    a half-applied one.
    const columns = async (table: string): Promise<string[]> =>
      (await db.getAllAsync<{ name: string }>(`PRAGMA table_info(${table})`)).map((c) => c.name);
    expect(await columns('conversations')).toContain('user_id');
    expect(await columns('messages')).toContain('user_id');
    // 3. And the version was written, so the next launch is a warm start rather than another wipe.
    expect(pragma(db, 'user_version')).toBe(2);
  });

  it('is idempotent across a warm start', async () => {
    // The mirror-image bug: a migrate that rebuilds unconditionally wipes the cache on every launch,
    // which is not a leak but is a cache that never caches.
    await upsertConversation(db, conversation('01JCONV1', ORG_A, USER_1));

    await migrate(db);

    expect(await listConversations(db, ORG_A, USER_1)).toHaveLength(1);
  });
});

describe('listConversations', () => {
  it('does not return another organization rows to a user of this one', async () => {
    await seedFourQuadrants(db);

    // FIRST: the canary is visible to its OWN scope. Without this, a `WHERE` clause that matched
    // nothing at all — a typo, a bound parameter that never arrived — would pass the isolation
    // assertions below for entirely the wrong reason.
    expect((await listConversations(db, ORG_B, USER_1)).map((row) => row.id)).toEqual([
      '01JCONV_B1',
    ]);

    // THEN: organization B is invisible to organization A. `B1` is the discriminating row — it
    // differs from the caller's own row on the ORGANIZATION axis only, so it can be returned by
    // exactly one bug: a missing `WHERE organization_id = ?`. Proven by deleting that predicate.
    const forA1 = await listConversations(db, ORG_A, USER_1);
    expect(forA1.map((row) => row.id)).toEqual(['01JCONV_A1']);
    expect(JSON.stringify(forA1)).not.toContain(ORG_B);
    expect(JSON.stringify(forA1)).not.toContain('B1');
  });

  it('does not return a colleague rows inside the SAME organization', async () => {
    // THE HANDOVER. Two people, one tablet, one tenant — the middle of the three cases the header
    // names, and the one an organization predicate is structurally blind to. Before `user_id`
    // existed this test could not be written at all: both rows below were "organization A's rows"
    // and the list screen would have rendered the colleague's conversations, correctly, with no
    // error anywhere.
    await seedFourQuadrants(db);

    // Canary first, same reason as above.
    expect((await listConversations(db, ORG_A, USER_2)).map((row) => row.id)).toEqual([
      '01JCONV_A2',
    ]);

    // `A2` is the discriminating row: same organization, different person. It can be returned by
    // exactly one bug — a missing `AND user_id = ?`. Proven by deleting that predicate.
    const forA1 = await listConversations(db, ORG_A, USER_1);
    expect(forA1.map((row) => row.id)).toEqual(['01JCONV_A1']);
    expect(JSON.stringify(forA1)).not.toContain(USER_2);
    expect(JSON.stringify(forA1)).not.toContain('A2');
  });

  it('orders by updated_at descending', async () => {
    await upsertConversation(
      db,
      conversation('01JOLD', ORG_A, USER_1, { updated_at: '2026-08-01T00:00:00Z' }),
    );
    await upsertConversation(
      db,
      conversation('01JNEW', ORG_A, USER_1, { updated_at: '2026-08-09T00:00:00Z' }),
    );

    const rows = await listConversations(db, ORG_A, USER_1);

    expect(rows.map((row) => row.id)).toEqual(['01JNEW', '01JOLD']);
  });
});

describe('upsertConversation', () => {
  it('updates a row it already owns', async () => {
    await upsertConversation(db, conversation('01JCONV1', ORG_A, USER_1, { title: 'first title' }));
    await upsertConversation(
      db,
      conversation('01JCONV1', ORG_A, USER_1, {
        title: 'renamed',
        updated_at: '2026-08-09T12:00:00Z',
      }),
    );

    const rows = await listConversations(db, ORG_A, USER_1);
    expect(rows).toHaveLength(1);
    expect(rows[0]?.title).toBe('renamed');
    expect(rows[0]?.updated_at).toBe('2026-08-09T12:00:00Z');
  });

  it('never lets a colliding id rewrite the organization on the stored row', async () => {
    // THE BUG: put `organization_id = excluded.organization_id` in the DO UPDATE list and this one
    // statement re-tenants an existing row — no delete, no insert, no error, and afterwards
    // organization B's list contains organization A's conversation.
    //
    // BOTH WRITES ARE THE SAME USER, deliberately: the only difference between them is the
    // organization, so `AND conversations.user_id = excluded.user_id` matches and cannot be what
    // rejects the write. This test therefore proves the ORGANIZATION conjunct on its own.
    await upsertConversation(
      db,
      conversation('01JCOLLIDE', ORG_A, USER_1, { title: 'org A only' }),
    );

    await upsertConversation(
      db,
      conversation('01JCOLLIDE', ORG_B, USER_1, { title: 'org B overwrite' }),
    );

    const forB = await listConversations(db, ORG_B, USER_1);
    expect(forB).toEqual([]);

    const forA = await listConversations(db, ORG_A, USER_1);
    expect(forA).toHaveLength(1);
    expect(forA[0]?.organization_id).toBe(ORG_A);
    // And the foreign write did not half-succeed either: re-tenanting is the worse bug, but
    // overwriting the title of a row belonging to someone else is still a write across the boundary.
    expect(forA[0]?.title).toBe('org A only');
  });

  it('never lets a colliding id rewrite the user on a row in the same organization', async () => {
    // The mirror image, one axis over, and the one the organization conjunct alone cannot reject:
    // both writes are inside organization A, so `conversations.organization_id =
    // excluded.organization_id` is TRUE and the row would be re-attributed to the colleague who
    // happened to sync second. This test proves the USER conjunct on its own.
    await upsertConversation(
      db,
      conversation('01JCOLLIDE', ORG_A, USER_1, { title: 'user 1 only' }),
    );

    await upsertConversation(
      db,
      conversation('01JCOLLIDE', ORG_A, USER_2, { title: 'user 2 overwrite' }),
    );

    const forUser2 = await listConversations(db, ORG_A, USER_2);
    expect(forUser2).toEqual([]);

    const forUser1 = await listConversations(db, ORG_A, USER_1);
    expect(forUser1).toHaveLength(1);
    expect(forUser1[0]?.user_id).toBe(USER_1);
    expect(forUser1[0]?.title).toBe('user 1 only');
  });
});

describe('purgeAll', () => {
  it('leaves nothing for the next user of a shared device', async () => {
    await seedFourQuadrants(db);
    await db.runAsync(
      `INSERT INTO messages (id, organization_id, user_id, conversation_id, role, content, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)`,
      ['01JMSG1', ORG_A, USER_1, '01JCONV_A1', 'assistant', 'a transcript', '2026-08-06T09:00:01Z'],
    );

    await purgeAll(db);

    // UNCONDITIONAL, and asserted across all four quadrants. A purge scoped to "the org and user we
    // think we are signed in as" cannot remove rows written under an identity the app has since
    // forgotten or mis-resolved — which is exactly the state a logout is most likely to be
    // recovering from, and the state a sign-in after a silent token expiry is always in.
    expect(await listConversations(db, ORG_A, USER_1)).toEqual([]);
    expect(await listConversations(db, ORG_A, USER_2)).toEqual([]);
    expect(await listConversations(db, ORG_B, USER_1)).toEqual([]);
    expect(await listConversations(db, ORG_B, USER_2)).toEqual([]);
    expect(await db.getAllAsync('SELECT id FROM messages')).toEqual([]);
    // Both tables, not just the one the list screen reads: an orphaned message row is a transcript
    // on the disk of a device that has been signed out.
    const counts = await db.getFirstAsync<{ total: number }>(
      `SELECT (SELECT COUNT(*) FROM conversations) + (SELECT COUNT(*) FROM messages) AS total`,
    );
    expect(counts?.total).toBe(0);
  });

  it('deletes messages even when the cascade is not armed', async () => {
    // THE BELT TO THE CASCADE'S BRACES, and the reason `purgeAll` names both tables rather than
    // leaning on `ON DELETE CASCADE`. `PRAGMA foreign_keys` is per CONNECTION and is a no-op inside
    // a transaction, so there are several ordinary ways for it to be off on the connection a purge
    // happens to run on — a handle opened somewhere other than src/db/open-history.ts, a future
    // migrate that sets it inside a transaction, a SQLite build compiled without it. Every one of
    // those turns "delete the conversations and the messages follow" into "leave every transcript
    // on the disk of a device that has just been signed out", with no error.
    await upsertConversation(db, conversation('01JCONV_A', ORG_A, USER_1));
    await db.runAsync(
      `INSERT INTO messages (id, organization_id, user_id, conversation_id, role, content, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)`,
      ['01JMSG1', ORG_A, USER_1, '01JCONV_A', 'assistant', 'a transcript', '2026-08-06T09:00:01Z'],
    );
    await db.execAsync('PRAGMA foreign_keys = OFF');
    expect(pragma(db, 'foreign_keys')).toBe(0);

    await purgeAll(db);

    expect(await db.getAllAsync('SELECT id FROM messages')).toEqual([]);
    expect(await listConversations(db, ORG_A, USER_1)).toEqual([]);
  });
});
