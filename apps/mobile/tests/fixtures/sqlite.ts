import { DatabaseSync } from 'node:sqlite';

import type { SQLiteDatabase } from 'expo-sqlite';

/**
 * A REAL SQLite engine behind expo-sqlite's async surface.
 *
 * WHY NOT A FAKE. `src/db/history.ts` is a file whose entire content is SQL, and the bugs it exists
 * to prevent are SQL bugs: a missing `WHERE organization_id = ?`, an `ON CONFLICT DO UPDATE` that
 * rewrites a tenant, an `ON DELETE CASCADE` that is inert because a PRAGMA was never set. A
 * hand-written double answers those queries with whatever the double decided to implement, so
 * deleting the org predicate from the production SQL changes nothing about what the double returns
 * and the isolation test passes over the bug. The test doctrine's rule about in-process doubles is
 * about streaming; the same logic applies here for a different reason — a double for a query engine
 * IS the assertion, so it cannot also be the thing under test.
 *
 * `node:sqlite` is the same C library the device runs, driven synchronously and wrapped in the
 * async methods `history.ts` calls. That is the whole adapter: it translates a calling convention,
 * never a query.
 *
 * WHAT IT DOES NOT PROVE. expo-sqlite's own JS-to-native bridge, its connection cache, its WAL
 * settings, and the file's location in the app sandbox. Those are a device run.
 *
 * `node:sqlite` is still flagged experimental on Node 22 and prints an ExperimentalWarning on
 * first use; it is a TEST-ONLY dependency and appears in no bundle. If a future Node moves the API,
 * this file is the only thing that changes — `src/db/history.ts` takes the handle as a parameter
 * precisely so the engine behind it is swappable.
 */

/**
 * `enableForeignKeyConstraints: false` IS DELIBERATE AND IS LOAD-BEARING.
 *
 * node:sqlite turns foreign keys ON by default. expo-sqlite does not — its `SQLiteOpenOptions` has
 * no such flag, so a device gets SQLite's own default, which is OFF, per connection. Opening the
 * test database with node's default would arm the `ON DELETE CASCADE` for us, `migrate()`'s
 * `PRAGMA foreign_keys = ON` would become decoration, and deleting that line would break nothing
 * here while silently orphaning every message row on a real phone. So the fixture reproduces the
 * DEVICE's default and makes the production code responsible for changing it.
 */
export function openTestDatabase(): SQLiteDatabase & { closeSync(): void } {
  const db = new DatabaseSync(':memory:', { enableForeignKeyConstraints: false });

  const adapter = {
    async execAsync(source: string): Promise<void> {
      db.exec(source);
    },
    async runAsync(source: string, params: unknown[] = []): Promise<unknown> {
      return db.prepare(source).run(...(params as never[]));
    },
    async getFirstAsync<T>(source: string, params: unknown[] = []): Promise<T | null> {
      return (db.prepare(source).get(...(params as never[])) as T | undefined) ?? null;
    },
    async getAllAsync<T>(source: string, params: unknown[] = []): Promise<T[]> {
      return db.prepare(source).all(...(params as never[])) as T[];
    },
    /**
     * A real BEGIN/COMMIT, with ROLLBACK on a throw. `purgeAll` runs inside one, and a transaction
     * that silently degrades to "just run the statements" would hide a partial purge — the exact
     * half-state that leaves one table's org-scoped rows on disk after a sign-out.
     */
    async withTransactionAsync(task: () => Promise<void>): Promise<void> {
      db.exec('BEGIN');
      try {
        await task();
        db.exec('COMMIT');
      } catch (error) {
        db.exec('ROLLBACK');
        throw error;
      }
    },
    async closeAsync(): Promise<void> {
      db.close();
    },
    closeSync(): void {
      db.close();
    },
    /** Read straight off the connection, so a test can ask what the PRAGMA actually is. */
    __pragma(name: string): number {
      const row = db.prepare(`PRAGMA ${name}`).get() as Record<string, number> | undefined;
      return row === undefined ? -1 : (Object.values(row)[0] ?? -1);
    },
  };

  // The adapter implements the members history.ts uses, not the whole 40-method class. The cast is
  // the seam, and it is here rather than at each call site so there is exactly one of it.
  return adapter as unknown as SQLiteDatabase & { closeSync(): void };
}

/** Typed accessor for the escape hatch above, so tests do not each write their own cast. */
export function pragma(db: SQLiteDatabase, name: string): number {
  return (db as unknown as { __pragma(name: string): number }).__pragma(name);
}
