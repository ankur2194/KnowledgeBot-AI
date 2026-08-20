/**
 * The paginated LIST envelope, and the one function that turns it into what a table needs.
 *
 * ── THE SHAPE THIS ASSUMES, EXACTLY ──────────────────────────────────────────────────────────────
 *
 *   { "data": { "<collection>": [ …rows… ],
 *               "meta": { "page": 2, "per_page": 25, "total": 137, "total_pages": 6,
 *                         "sort": "name", "dir": "asc", "filter": null } } }
 *
 * Two properties of it are load-bearing and neither is negotiable from this side:
 *
 *   A LIST RESPONSE IS AN OBJECT WRAPPING THE ARRAY, NEVER A BARE ARRAY. A top-level JSON array has
 *   nowhere to put `meta`, so a bare array cannot carry a row count — and `rowCount` is what stops the
 *   pager reading "Page 1 of 1" on a 4,000-row set (`tanstack-query-table` gotcha 4).
 *
 *   THE ARRAY SITS UNDER A NAMED KEY inside `data`, matching the members and invitations endpoints
 *   already shipped (`{"data":{"members":[…]}}`). That convention exists because every published
 *   response component is `additionalProperties: false` and `ResponseShape` cannot express "an array
 *   of" for a response key.
 *
 * `meta` is a SIBLING of the collection inside `data`, not a sibling of `data`
 * (`App\Http\Resources\Concerns\PaginatedCollection`, `ListMetaResource`). One `ListMetaResource`
 * component serves every list in the API, so this reader serves every table in this app.
 *
 * THE FOUR QUERY FIELDS ARE WHAT THE SERVER APPLIED, not what the client asked for: `per_page` is
 * clamped to the platform maximum and `sort`/`dir` fall back to the endpoint's default. They are
 * returned here so a caller can notice a clamp; the pager does its arithmetic from the view's own
 * page size, which is why `lib/table/params.ts` refuses a `pageSizes` entry above the cap.
 *
 * ── WHY A MISMATCH THROWS RATHER THAN DEGRADING ──────────────────────────────────────────────────
 * An envelope we cannot read is not an empty list. Returning `{rows: [], rowCount: 0}` would render
 * the FIRST-RUN EMPTY state — "Create your first bot" — to an administrator whose organization has
 * two hundred of them, which is indistinguishable from data loss at a glance. Throwing puts it in the
 * error state instead. The thrown value is not a `KbError`, so it carries no `error_class`, and
 * "no envelope parsed" is unknown, and unknown is permanently non-retryable (`kb-error-taxonomy`) —
 * which is right: a shape mismatch will not fix itself on a second attempt.
 *
 * ── THE META TYPE IS IMPORTED, NOT DECLARED HERE ─────────────────────────────────────────────────
 * `ListMetaResource` is mirrored in `packages/contracts/src/resources/bots.ts` and compared field for
 * field against the generated OpenAPI document by `test/resource-drift.test.ts`. This file used to
 * carry a hand-written `PaginationMeta` that was identical to it — the duplication that mirror's own
 * docblock names, and the shape `resource-drift.test.ts` was rewritten to catch after `MemberResource`
 * was hand-written a second time here.
 *
 * The window a local copy opens is narrow and silent: a server-side change to the meta block turns
 * `@kb/contracts` red while this app compiles clean against a stale interface, and `readMeta` guards
 * only `page`/`per_page`/`total`, so the pager would read a field that is no longer what it says.
 * Importing it means the drift test is this file's drift test too.
 *
 * There is no local envelope type either. `BotCollectionResource` (and its sibling per list) already
 * spells `{data: {<collection>: [...], meta}}` in the mirrored package; a generic re-spelling of it
 * here would be a second thing to keep true with nothing checking it.
 */

import type { ListMetaResource } from '@kb/contracts';

export interface TablePage<TRow> {
  readonly rows: readonly TRow[];
  /** Straight to `useTable({ rowCount })`. Without it the pager cannot know the last page. */
  readonly rowCount: number;
  /** Zero-based, ready to compare against the requested `pageIndex`. */
  readonly pageIndex: number;
  /** APPLIED. Compare against the requested size to detect a clamp. */
  readonly pageSize: number;
}

function isRecord(value: unknown): value is Readonly<Record<string, unknown>> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/**
 * THE GUARD IS NARROWER THAN THE TYPE, deliberately: it requires the three numbers this app reads and
 * ignores the applied-query echo. A response that carried rows and a total but had not yet grown its
 * `sort` echo would otherwise render as an error, which is a worse answer than the rows.
 */
function readMeta(value: unknown): Pick<ListMetaResource, 'page' | 'per_page' | 'total'> | null {
  if (!isRecord(value)) return null;
  const total = value['total'];
  const page = value['page'];
  const perPage = value['per_page'];
  if (typeof total !== 'number' || typeof page !== 'number' || typeof perPage !== 'number') {
    return null;
  }
  return { page, per_page: perPage, total };
}

/**
 * `collectionKey` is a literal at every call site (`'bots'`), but it is a parameter here, so the read
 * is `Reflect.get` rather than `container[collectionKey]` — the bracket form is the
 * `security/detect-object-injection` sink and this module would trip it on every access.
 */
export function readPaginatedEnvelope<TRow>(body: unknown, collectionKey: string): TablePage<TRow> {
  const container = isRecord(body) ? body['data'] : undefined;
  if (!isRecord(container)) {
    throw new Error(`Paginated response has no "data" object (expected data.${collectionKey}).`);
  }

  const rows: unknown = Reflect.get(container, collectionKey);
  if (!Array.isArray(rows)) {
    throw new Error(`Paginated response has no array at data.${collectionKey}.`);
  }

  const meta = readMeta(Reflect.get(container, 'meta'));
  if (meta === null) {
    // Deliberately not "assume one page". A missing `meta` with 25 rows in hand would make the pager
    // claim 25 rows exist when the answer is unknown, and Next would be disabled on a set with more.
    throw new Error(
      `Paginated response has no data.meta {total, page, per_page} for data.${collectionKey}.`,
    );
  }

  return {
    rows: rows as readonly TRow[],
    rowCount: meta.total,
    pageIndex: Math.max(0, meta.page - 1),
    pageSize: meta.per_page,
  };
}
