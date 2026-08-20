<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\SortDirection;
use App\Support\Contracts\ProvidesOpenApiQueryParameters;
use App\Support\Http\ListQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The query string of `GET .../sources`.
 *
 * The rules are MERGED from `ListQuery::rules()` rather than inherited from a base `ListRequest`,
 * for the mechanical reason `IndexBotsRequest` records: `kb:dump-form-rules` SKIPS abstract
 * classes while `DumpFormRulesCommandTest` requires a dumped document for every
 * `is_subclass_of(FormRequest)` hit in the tree, so an abstract base would be counted and never
 * dumped and the contract gate would fail on correct code.
 *
 * ── THE SORTABLE SET ──────────────────────────────────────────────────────────────────────────
 *
 * `id`, `name`, `type`, `status` — one for each way an operator scans a source list, and each is
 * either an index range or a bounded sort of a single organization's rows.
 * `knowledge_sources_org_status_created` is `(organization_id, status, created_at DESC)` and
 * `knowledge_sources_org_scoped_key` is `(organization_id, id)`, both TENANT-LEADING, which is what
 * makes the tenant predicate the leading term rather than a filter over every organization's rows
 * in that state. PG 18's b-tree skip scan does not rescue a status-leading index at organization
 * scale, which is why the create migration built them this way round.
 *
 * `created_at` IS NOT OFFERED. `id` is a ULID whose leading 48 bits are a millisecond timestamp and
 * the column is `COLLATE "C"`, so lexicographic order IS byte order IS creation order — sorting by
 * `id` and sorting by `created_at` are the same ordering under two names, and publishing the second
 * would invite an index that duplicates the first.
 *
 * `type` sorts over three values and `status` over fifteen, so neither is selective — but a sort is
 * not a filter, and both are bounded by the organization predicate before the ORDER BY is reached.
 *
 * ── THE FILTER SEARCHES `name` AND `origin_url` ──────────────────────────────────────────────
 *
 * The second is not a convenience: "which sources cause us to make outbound requests to that host"
 * is asked with a hostname rather than a label, and it is the one query on this table a security
 * review runs. `EloquentKnowledgeSourceRepository::FILTERABLE` records why `description` and `tags`
 * are not searched.
 */
final class IndexSourcesRequest extends FormRequest implements ProvidesOpenApiQueryParameters
{
    /**
     * The columns this endpoint permits an `ORDER BY` on. Closed, because a caller-chosen `sort`
     * reaches an `ORDER BY` — an open set is a caller choosing which index the query uses at best
     * and injecting at worst.
     *
     * @var list<string>
     */
    public const SORTABLE = ['id', 'name', 'type', 'status'];

    /**
     * `id` ASCENDING, which is creation order — the oldest source first, so a page-one bookmark
     * keeps meaning the same thing as sources are added.
     */
    public const DEFAULT_SORT = 'id';

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
