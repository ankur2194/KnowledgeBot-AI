<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Enums\SortDirection;
use Illuminate\Validation\Rule;

/**
 * A validated list request: which page, how big, ordered by what, filtered how.
 *
 * ── WHY THIS IS A PRIMITIVE AND NOT A FIELD SET COPIED INTO EACH FormRequest ──────────────────
 *
 * Nothing in this application paginates yet: every index endpoint returns a whole collection, and
 * `ProviderConnectionCollectionResource` and `ProviderModelCollectionResource` both say so
 * explicitly while leaving room in the envelope for the day one does. Phase B introduces the first
 * paginated list and Phases C4, D and E2 reuse it, so the rules live in ONE place from the start.
 *
 * The alternative — five FormRequests each spelling out `page`, `per_page`, `sort`, `dir`, `filter`
 * — is not merely repetitive. Each copy is a chance for one endpoint to admit `per_page=100000` and
 * become a denial-of-service on the database, or to leave `sort` open and become an ORDER BY over
 * an unindexed column, or to drift on the meaning of `page` (0-based on one endpoint, 1-based on
 * the next) in a way no client can discover except by getting it wrong.
 *
 * ── THE SORTABLE COLUMN LIST IS AN ARGUMENT, AND THAT IS THE SECURITY-RELEVANT PART ───────────
 *
 * `rules()` takes the columns THIS endpoint permits and closes the set with `Rule::in(...)`. There
 * is no default and no wildcard: a caller-chosen `sort` reaches an `ORDER BY`, so an open set is a
 * caller choosing which index the query uses at best and injecting at worst. Making the argument
 * required and positional means an endpoint cannot get a working list query without stating its own
 * sortable columns out loud.
 *
 * The DIRECTION is closed by `SortDirection` for the same reason, one layer earlier.
 *
 * ── `per_page` IS BOUNDED IN TWO PLACES AND THE TWO ARE NOT FLUSH ─────────────────────────────
 *
 * The rule caps it at `MAX_PER_PAGE`, and `fromValidated()` clamps it again. That is not
 * belt-and-braces theatre: `fromValidated()` is reachable from a service or a job that never ran a
 * FormRequest, and a clamp that only exists in a validation rule is a clamp that does not exist for
 * any non-HTTP caller. The clamp is silent because the rule has already produced a 422 for the only
 * caller that has a field to key one on.
 *
 * ── PAGES ARE 1-BASED, MATCHING `LengthAwarePaginator` ────────────────────────────────────────
 *
 * Laravel's paginator, every `?page=` link it generates, and the `meta.page` this envelope
 * publishes all agree. `offset()` is the only place the conversion to a 0-based row offset happens,
 * so no repository does that arithmetic itself — which is the arithmetic that produces an
 * off-by-one nobody notices until page two of a list is missing its first row.
 */
final readonly class ListQuery
{
    /**
     * What a caller gets when they ask for a list and say nothing else.
     *
     * 25 rather than 10 or 50 for a boring reason: it is a screenful in the admin console's table
     * density without being a screenful the browser has to virtualize.
     */
    public const DEFAULT_PER_PAGE = 25;

    /**
     * The ceiling. A list endpoint is a query the caller controls the cost of, so this is the
     * number that stops "give me everything" from being expressible.
     */
    public const MAX_PER_PAGE = 100;

    /** The longest free-text filter accepted, in characters. Longer is not a search, it is a probe. */
    public const MAX_FILTER_LENGTH = 200;

    public function __construct(
        public int $page,
        public int $perPage,
        public string $sort,
        public SortDirection $direction,
        public ?string $filter,
    ) {}

    /**
     * The validation rules for a list query, closed to this endpoint's sortable columns.
     *
     * MERGED INTO A FormRequest's OWN `rules()` RATHER THAN INHERITED FROM A BASE CLASS. An abstract
     * `ListRequest` would be skipped by `kb:dump-form-rules` (which ignores abstract classes) while
     * still being counted by the test that asserts every FormRequest in the tree has a dumped
     * document — so the base-class spelling breaks the contract gate. Composition also keeps each
     * endpoint's document complete on its own, which is what a generated client reads.
     *
     * `filter` IS DELIBERATELY NOT PATTERN-CONSTRAINED. It is a free-text search term a human types;
     * constraining its character class here would make the endpoint refuse the strings customers
     * actually search for. What makes it safe is that it never reaches SQL as SQL — the repository
     * binds it as a parameter and escapes the `LIKE` metacharacters itself — and what bounds it is
     * the length cap. A pattern rule would be security theatre that also breaks apostrophes.
     *
     * @param  list<string>  $sortable  the columns this endpoint permits an ORDER BY on
     * @return array<string, mixed>
     */
    public static function rules(array $sortable, int $maxPerPage = self::MAX_PER_PAGE): array
    {
        return [
            'page' => ['bail', 'sometimes', 'integer', 'min:1'],
            'per_page' => ['bail', 'sometimes', 'integer', 'min:1', 'max:'.$maxPerPage],
            // No default in the RULE, because a validation rule cannot express one; the default
            // sort is `fromValidated()`'s argument, and it is the endpoint's decision.
            'sort' => ['bail', 'sometimes', 'string', Rule::in($sortable)],
            'dir' => ['bail', 'sometimes', 'string', Rule::in(SortDirection::values())],
            'filter' => ['bail', 'sometimes', 'nullable', 'string', 'max:'.self::MAX_FILTER_LENGTH],
        ];
    }

    /**
     * Build a query from a validated payload.
     *
     * `$defaultSort` HAS NO DEFAULT VALUE ON PURPOSE. Every list has a deterministic order or two
     * reads of an unchanged set are not byte-identical, and the column that provides it is the
     * endpoint's decision — for most tables here it is `id`, which is a ULID and therefore already
     * creation order under `COLLATE "C"`. Defaulting it in this class would let an endpoint ship
     * with an unspecified order and only discover it when a page boundary duplicated a row.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(
        array $validated,
        string $defaultSort,
        SortDirection $defaultDirection = SortDirection::Asc,
        int $defaultPerPage = self::DEFAULT_PER_PAGE,
    ): self {
        $page = is_numeric($validated['page'] ?? null) ? (int) $validated['page'] : 1;
        $perPage = is_numeric($validated['per_page'] ?? null) ? (int) $validated['per_page'] : $defaultPerPage;
        $sort = is_string($validated['sort'] ?? null) ? $validated['sort'] : $defaultSort;
        $dir = is_string($validated['dir'] ?? null) ? SortDirection::tryFrom($validated['dir']) : null;
        $filter = is_string($validated['filter'] ?? null) ? trim($validated['filter']) : null;

        return new self(
            page: max(1, $page),
            // Clamped again here, not only in the rule — see the class docblock: a service or a job
            // that never ran a FormRequest reaches this constructor too.
            perPage: min(self::MAX_PER_PAGE, max(1, $perPage)),
            sort: $sort,
            direction: $dir ?? $defaultDirection,
            // An empty filter is NO filter, not a filter matching the empty string. Two spellings
            // of "unfiltered" would make the cache key of an unfiltered list depend on whether the
            // client sent the parameter.
            filter: ($filter === null || $filter === '') ? null : $filter,
        );
    }

    /**
     * The 0-based row offset this page starts at.
     *
     * The ONLY place the 1-based page number becomes an offset. A repository that did this
     * arithmetic itself would be a second place to get it wrong, and the symptom — page two missing
     * its first row — looks like a data problem rather than an arithmetic one.
     */
    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
