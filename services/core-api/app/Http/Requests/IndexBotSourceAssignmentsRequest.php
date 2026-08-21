<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\SortDirection;
use App\Support\Contracts\ProvidesOpenApiQueryParameters;
use App\Support\Http\ListQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The query string of `GET .../bots/{bot}/source-assignments`.
 *
 * The rules are MERGED from `ListQuery::rules()` rather than inherited from a base `ListRequest`,
 * for the mechanical reason `IndexSourcesRequest` records: `kb:dump-form-rules` SKIPS abstract
 * classes while `DumpFormRulesCommandTest` requires a dumped document for every
 * `is_subclass_of(FormRequest)` hit in the tree, so an abstract base would be counted and never
 * dumped and the contract gate would fail on correct code.
 *
 * ── THE SORTABLE SET ──────────────────────────────────────────────────────────────────────────
 *
 * `id`, `priority`, `enabled` — three columns of the assignment row itself, and deliberately
 * nothing on the SOURCE. Sorting by the source's name would mean an ORDER BY across a join, on a
 * column no index on this table can reach, for a list the organization predicate has already
 * bounded to one bot's grants; the console sorts a page it already holds if it wants that. Every
 * member here is reachable from `bot_source_assignments_org_bot_priority`'s leading pair or from
 * the primary key.
 *
 * `created_at` IS NOT OFFERED. `id` is a ULID whose leading 48 bits are a millisecond timestamp and
 * the column is `COLLATE "C"`, so lexicographic order IS byte order IS creation order — sorting by
 * `id` and sorting by `created_at` are the same ordering under two names, and publishing the second
 * would invite an index that duplicates the first.
 *
 * `source_id` IS NOT OFFERED EITHER, and it is the one a reader may expect. It is a ULID, so
 * ordering by it is the order the SOURCES were created in — a fact about another table wearing this
 * one's clothes, which no operator would recognise as an ordering and which `id` already
 * approximates for the grants themselves.
 *
 * ── THE DEFAULT IS `priority` ASCENDING, AND WHAT THAT DOES *NOT* CLAIM ───────────────────────
 *
 * `bot_source_assignments_org_bot_priority` is `(organization_id, bot_id, priority) WHERE enabled`,
 * and the create migration says a negative priority is refused because it is "a typo for a value
 * somebody meant to be first" — so ascending is the direction the schema was built for and this
 * endpoint publishes it. WHETHER A LOWER NUMBER OUTRANKS A HIGHER ONE IN RETRIEVAL IS NOT DECIDED
 * ANYWHERE IN THIS REPOSITORY YET: the migration calls priority "a TIE-BREAK the retrieval stage
 * may consult, never a filter", and no retrieval stage exists on this side of the seam. This is a
 * stable display order, not a statement about ranking.
 *
 * ── THE FILTER SEARCHES THE SOURCE, WHICH IS THE ONLY TEXT IN REACH ──────────────────────────
 *
 * An assignment row is two ULIDs, an integer and a boolean — there is nothing on it a human would
 * type. `EloquentBotSourceAssignmentRepository::FILTERABLE` searches `knowledge_sources.name` and
 * `origin_url` through an org-scoped sub-query, which is the same pair and the same reasoning as
 * the sources list itself.
 */
final class IndexBotSourceAssignmentsRequest extends FormRequest implements ProvidesOpenApiQueryParameters
{
    /**
     * The columns this endpoint permits an `ORDER BY` on. Closed, because a caller-chosen `sort`
     * reaches an `ORDER BY` — an open set is a caller choosing which index the query uses at best
     * and injecting at worst.
     *
     * @var list<string>
     */
    public const SORTABLE = ['id', 'priority', 'enabled'];

    /** The operator's own order over this bot's grants. See the class docblock for what it is not. */
    public const DEFAULT_SORT = 'priority';

    /**
     * Authorization is `Gate::authorize()` in the controller, not here. `FormRequest::authorize()`
     * runs BEFORE validation, so a policy call placed in it decides on unvalidated input — and it
     * cannot reach checks 5 and 6 (entity status, rate limit) at all.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ListQuery::rules(self::SORTABLE);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function openApiQueryParameters(): array
    {
        return ListQuery::openApiQueryParameters(
            self::SORTABLE,
            defaultSort: self::DEFAULT_SORT,
            defaultDirection: SortDirection::Asc,
        );
    }

    public function toQuery(): ListQuery
    {
        return ListQuery::fromValidated(
            $this->validated(),
            defaultSort: self::DEFAULT_SORT,
            defaultDirection: SortDirection::Asc,
        );
    }
}
