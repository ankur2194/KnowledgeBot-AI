import { describe, expect, it } from 'vitest';

import { readPaginatedEnvelope } from '@/lib/table/envelope';

/**
 * The list envelope reader.
 *
 * The body below is the one `App\Http\Resources\Concerns\PaginatedCollection` renders, field for
 * field, including the four applied-query fields this app does not read. It is spelled out in full
 * rather than reduced to the three numbers under assertion, because the value of this fixture is that
 * it goes stale loudly when the server's shape moves.
 */
const BODY = {
  data: {
    bots: [
      { id: 'bot-1', name: 'Support bot' },
      { id: 'bot-2', name: 'Billing bot' },
    ],
    meta: {
      page: 3,
      per_page: 25,
      total: 137,
      total_pages: 6,
      sort: 'name',
      dir: 'asc',
      filter: null,
    },
  },
};

interface BotRow {
  readonly id: string;
  readonly name: string;
}

describe('readPaginatedEnvelope', () => {
  it('reads the rows, the row count and the applied page', () => {
    const page = readPaginatedEnvelope<BotRow>(BODY, 'bots');

    expect(page.rows).toHaveLength(2);
    expect(page.rowCount).toBe(137);
    // The wire is 1-based (Laravel's paginator and every `?page=` a client sends); `pageIndex` is
    // 0-based because that is what `PaginationState` holds. One conversion, here.
    expect(page.pageIndex).toBe(2);
    expect(page.pageSize).toBe(25);
  });

  it('ignores the applied-query echo it does not read, so the shape can grow', () => {
    const withoutEcho = {
      data: { bots: [], meta: { page: 1, per_page: 25, total: 0 } },
    };

    expect(readPaginatedEnvelope<BotRow>(withoutEcho, 'bots').rowCount).toBe(0);
  });

  it.each([
    ['no data object', { bots: [] }],
    ['a bare array where the envelope should be', [{ id: 'bot-1' }]],
    ['the collection under the wrong key', { data: { items: [], meta: { page: 1, per_page: 25, total: 0 } } }],
    ['no meta at all', { data: { bots: [] } }],
    ['a meta with a missing total', { data: { bots: [], meta: { page: 1, per_page: 25 } } }],
  ])('throws rather than reporting an empty list for %s', (_case, body) => {
    // AN ENVELOPE WE CANNOT READ IS NOT AN EMPTY LIST. Returning zero rows would render the FIRST-RUN
    // empty state — "Create your first bot" — to an administrator whose organization has two hundred
    // of them, which at a glance is indistinguishable from data loss.
    expect(() => readPaginatedEnvelope<BotRow>(body, 'bots')).toThrow();
  });

  it('throws something that is NOT a KbError, so nothing retries it', () => {
    // No envelope parsed means unknown, and unknown is permanently non-retryable. A shape mismatch
    // will not fix itself on a second attempt.
    try {
      readPaginatedEnvelope<BotRow>({ data: {} }, 'bots');
      expect.unreachable('should have thrown');
    } catch (error) {
      expect(error).toBeInstanceOf(Error);
      expect((error as Error).name).toBe('Error');
      expect((error as Error).message).toContain('data.bots');
    }
  });
});
