import { deleteDatabaseAsync, openDatabaseAsync, type SQLiteDatabase } from 'expo-sqlite';

import { migrate, purgeAll } from './history';

/**
 * The one handle to the offline-history database, and the only module that opens it.
 *
 * WHY THIS IS A SEPARATE FILE FROM src/db/history.ts. That module imports `SQLiteDatabase` as a
 * TYPE ONLY and takes the handle as its first parameter, so it has no runtime dependency on
 * expo-sqlite at all. That is deliberate and worth protecting: it is what lets tests/history.test.ts
 * drive the real SQL through a real SQLite engine in the test process, instead of through a
 * hand-written fake whose `WHERE` clause is whatever the fake decided to implement. Move
 * `openDatabaseAsync` into that file and importing it pulls in a native module, the suite dies on
 * `TurboModuleRegistry.getEnforcing`, and the isolation test has to be rewritten against a double.
 */

/**
 * The file lives in the app's private directory. Sandboxed, but NOT encrypted beyond whatever
 * full-disk encryption the OS provides — so this holds conversation text and nothing else. No
 * token, no `expires_at`, no credential; those are SecureStore's, and src/auth/secure-store.ts is
 * the only module that touches it.
 */
const DATABASE_NAME = 'kb-history.db';

/**
 * Memoized on the PROMISE, not on the resolved handle. Two screens mounting in the same tick both
 * call this before either has finished opening; caching the handle would run `migrate` twice
 * concurrently — and `migrate` drops tables.
 */
let opening: Promise<SQLiteDatabase> | null = null;

export async function openHistoryDatabase(): Promise<SQLiteDatabase> {
  opening ??= (async () => {
    const db = await openDatabaseAsync(DATABASE_NAME);
    // Every connection needs `PRAGMA foreign_keys = ON` — it is per connection, not per database —
    // which is one more reason opening goes through here rather than through a call site.
    await migrate(db);
    return db;
  })();

  try {
    return await opening;
  } catch (error) {
    // A failed open must not be cached, or the app is permanently without history until it is
    // relaunched.
    opening = null;
    throw error;
  }
}

/**
 * The logout / 401 purge. Called from src/auth/session-provider.tsx's `clearLocalState`, beside the
 * SecureStore purge and the QueryClient replacement — all three or none.
 *
 * TWO LEVELS, because a purge with a silent failure mode is not a purge. The normal path deletes
 * every row. If that throws for any reason — a corrupted file, a schema this build no longer
 * recognises, a locked database — the fallback deletes the DATABASE FILE, which is strictly
 * stronger and does not depend on the file being readable. Only if both fail does this reject, and
 * a rejection is a real signal: it means org-scoped transcripts are still on the disk of a device
 * that has just been signed out.
 */
export async function purgeHistoryDatabase(): Promise<void> {
  try {
    await purgeAll(await openHistoryDatabase());
    return;
  } catch {
    // Fall through to the stronger form.
  }

  const db = await opening?.catch(() => null);
  opening = null;
  await db?.closeAsync().catch(() => {});
  await deleteDatabaseAsync(DATABASE_NAME);
}
