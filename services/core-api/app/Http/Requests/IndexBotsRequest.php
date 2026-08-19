<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\SortDirection;
use App\Support\Http\ListQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The query string of `GET .../bots` — the first paginated list in this API.
 *
 * ── THE RULES ARE MERGED FROM `ListQuery`, NOT INHERITED FROM A BASE `ListRequest` ────────────
 *
 * `ListQuery::rules()` takes this endpoint's sortable columns as a REQUIRED POSITIONAL ARGUMENT and
 * closes the set with `Rule::in(...)`, and that class records why the argument is required: a
 * caller-chosen `sort` reaches an `ORDER BY`, so an open set is a caller choosing which index the
 * query uses at best and injecting at worst. Making it positional means an endpoint cannot get a
 * working list query without stating its own sortable columns out loud.
 *
 * COMPOSITION AND NOT AN ABSTRACT BASE CLASS, and the reason is mechanical rather than aesthetic:
 * `kb:dump-form-rules` SKIPS abstract classes, while `DumpFormRulesCommandTest` requires a dumped
 * document for every `is_subclass_of(FormRequest)` hit in the tree — so an abstract `ListRequest`
 * would be counted and never dumped, and the contract gate would fail on correct code. Composition
 * also keeps each endpoint's manifest complete on its own, which is what a generated client reads.
 *
 * ── THE SORTABLE SET, AND THE COLUMN THAT IS DELIBERATELY NOT IN IT ───────────────────────────
 *
 * `id`, `name`, `slug`, `status` — one for each way an operator actually scans a bot list, and each
 * one is either an index range or a bounded sort of a single organization's rows. `bots_org_status`
 * is `(organization_id, status)` and `bots_org_scoped_key` is `(organization_id, id)`, both
 * tenant-leading, which is what makes the tenant predicate the leading term rather than a filter
 * over every organization's rows.
 *
 * `created_at` IS NOT OFFERED, and its absence is a decision rather than an omission. `id` is a
 * ULID, whose leading 48 bits are a millisecond timestamp, and the column is `COLLATE "C"` so
 * lexicographic order IS byte order IS creation order — sorting by `id` and sorting by `created_at`
 * are the same ordering under two names. The create migration declines to add an
 * `(organization_id, created_at)` index for exactly that reason ("a second copy of that ordering"),
 * so publishing `created_at` here would publish a sort with no index behind it and invite the index
 * the migration argued against. A console that wants a "Created" column sorts it by `id`.
 *
 * ── THE FILTER IS FREE TEXT AND IS DELIBERATELY NOT PATTERN-CONSTRAINED ───────────────────────
 *
 * `ListQuery` states the division of labour: constraining the character class of a string a human
 * types would make the endpoint refuse the values customers actually search for. What makes it safe
 * is that it never reaches SQL as SQL — the repository binds it as a parameter and neutralises the
 * `LIKE` metacharacters itself — and what bounds it is `MAX_FILTER_LENGTH`. It searches `name` and
 * `slug`; `EloquentBotRepository::FILTERABLE` records why the prose columns are not searched.
 */
final class IndexBotsRequest extends FormRequest
{
    /**
     * The columns this endpoint permits an `ORDER BY` on. Closed, and published in this endpoint's
     * own rules manifest rather than in a shared vocabulary that would drift across endpoints.
     *
     * @var list<string>
     */
    public const SORTABLE = ['id', 'name', 'slug', 'status'];

    /**
     * The order two reads of an unchanged set come back in when the caller says nothing.
     *
     * `id` ASCENDING, which is creation order — the oldest bot first, so a page-one bookmark keeps
     * meaning the same thing as bots are added. `ListQuery::fromValidated()` has no default for
     * this argument on purpose: every list needs a deterministic order and the column that provides
     * it is the endpoint's decision, so defaulting it in the primitive would let an endpoint ship
     * with an unspecified order and only discover it when a page boundary duplicated a row.
     */
    public const DEFAULT_SORT = 'id';

    /**
     * Authorization is Gate::authorize() in the controller, not here. FormRequest::authorize() runs
     * BEFORE validation, so a policy call placed in it decides on unvalidated input — and it cannot
     * reach checks 5 and 6 (entity status, rate limit) at all.
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
     * The validated query, as a type.
     *
     * `per_page` IS CLAMPED AGAIN INSIDE `fromValidated()`, and the redundancy is deliberate: the
     * rule above has already produced a 422 for the only caller that has a field to key one on, and
     * the clamp is what protects the service and job callers that never ran a FormRequest.
     */
    public function toQuery(): ListQuery
    {
        return ListQuery::fromValidated(
            $this->validated(),
            defaultSort: self::DEFAULT_SORT,
            defaultDirection: SortDirection::Asc,
        );
    }
}
