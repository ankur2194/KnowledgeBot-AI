<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\SortDirection;
use App\Services\Audit\AuditLogFilter;
use App\Services\Audit\AuditLogger;
use App\Support\Contracts\ProvidesOpenApiQueryParameters;
use App\Support\Http\ListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The query string of `GET .../audit-logs`.
 *
 * The pagination rules are MERGED from `ListQuery::rules()` rather than inherited from a base
 * `ListRequest`, for the mechanical reason `IndexSourcesRequest` records: `kb:dump-form-rules` SKIPS
 * abstract classes while `DumpFormRulesCommandTest` requires a dumped document for every
 * `is_subclass_of(FormRequest)` hit in the tree, so an abstract base would be counted and never
 * dumped and the contract gate would fail on correct code.
 *
 * ── `operation` AND `outcome` ARE CLOSED HERE BECAUSE THEY ARE CLOSED AT THE WRITER ─────────
 *
 * `AuditLogger::record()` THROWS on an operation that is not a key of `AuditLogger::OPERATIONS` —
 * "an operation name that is not in the map is a typo or a missing map entry, and both mean nobody
 * has decided what this event may record" — and `outcome` is DERIVED from that same map rather than
 * passed in, so no row can exist outside either set. A filter value outside them therefore cannot
 * match anything that will ever be written: it is a malformed request, `validation` (422), and not
 * an empty result set that a caller would read as "this never happened".
 *
 * BOTH ENUMS ARE READ FROM `AuditLogger` RATHER THAN RESTATED. The operation list is
 * `array_keys(AuditLogger::OPERATIONS)`, so an operation added to the map is filterable and
 * documented in the same commit and cannot be forgotten here; the outcomes are the two constants,
 * not two string literals.
 *
 * ── `subject_type` IS **NOT** CLOSED, AND CLOSING IT WOULD BE A DEFECT ──────────────────────
 *
 * The migration says so about the column: *"It has no CHECK, for the same reason `operation` has
 * none: the set is open and a refused audit row is worse than a row nobody wrote rules for."*
 * Eleven model classes appear as subjects today and the twelfth needs no schema change, so a
 * `Rule::in()` here would 422 a legitimate query on the day a new subject is first audited —
 * a false refusal on the surface an investigation uses. Shape only; a value nobody has written
 * matches nothing.
 *
 * IT IS A FULLY-QUALIFIED CLASS NAME, AND THAT IS WHAT THE COLUMN HOLDS. `App\Models\Bot`, written
 * as `Bot::class` at every call site. Publishing it means the API exposes a PHP namespace, which is
 * a real cost and is accepted for three reasons: it is what the data already is (the migration
 * corrected the opposite claim precisely because "two spellings of one fact means a query for a
 * subject finds half its rows"); a short-token mapping would be a second open vocabulary to keep in
 * step with a set the writer chooses; and on an authenticated admin surface `App\Models\Bot`
 * discloses nothing a caller cannot infer from `/api/v1/organizations/{organization}/bots`. The
 * resource publishes the same string, so a client filters with a value it was given.
 *
 * ── `subject_id` REQUIRES `subject_type`, AND THAT IS AN INDEX FACT RATHER THAN TIDINESS ────
 *
 * `audit_logs_org_subject_created` is `(organization_id, subject_type, subject_id, created_at DESC)
 * WHERE subject_id IS NOT NULL`. With both terms the leading three columns are constrained and the
 * DESC suffix already satisfies this endpoint's default sort, so "the audit trail for THIS record"
 * — the query the index was built for and the one an admin UI opens with — is an index range. With
 * only `subject_id` the second column is a gap; the migration that created these indexes explicitly
 * declines to lean on PG 18's skip scan at organization scale, so the pairing is required here
 * rather than hoped for in the planner. It is also what `audit_logs_subject_paired` says about the
 * stored row, in the same direction.
 *
 * ── NO `exists:` RULE ON `actor_id` ────────────────────────────────────────────────────────
 *
 * `exists:users,id` is unscoped, so it would turn this endpoint into an existence oracle over every
 * organization's users — the Filament CVE-2026-48067 shape, where the select query is tenant-scoped
 * and the validation rule for the same field is not. `ShowAnalyticsRequest` refuses the same rule
 * for the same reason. A foreign actor id matches nothing, which is the correct answer.
 *
 * ── THERE IS NO FREE-TEXT `filter`, AND ITS ABSENCE IS DECLARED ─────────────────────────────
 *
 * `ListQuery::rules(..., freeText: false)`. Every filter this endpoint offers is an equality or a
 * range over an indexed column; the only free-text targets `audit_logs` has are `user_agent` and
 * the `details` jsonb, where an `ILIKE '%term%'` is a sequential scan of a partitioned append-only
 * table that grows with every state change in the platform. Publishing a `filter` the repository
 * ignored would be worse than omitting it — a silent no-op is undiscoverable where an absence is
 * not. `meta.filter` still comes back null, because it is a field of the shared `ListMetaResource`
 * component.
 *
 * ── `organization_id` APPEARS IN NO RULE AND IN NO DTO ──────────────────────────────────────
 *
 * The organization comes from the bound route segment, which `org.member` has already proved this
 * caller belongs to. On this table that matters more than usual: `AuditLog` carries no `#[ScopedBy]`
 * backstop, so the repository's positional argument is the only tenancy there is and a filterable
 * organization would be that argument taking client input.
 */
final class IndexAuditLogsRequest extends FormRequest implements ProvidesOpenApiQueryParameters
{
    /**
     * The one column this endpoint permits an `ORDER BY` on.
     *
     * A SET OF ONE, WHICH IS UNUSUAL AND IS THE HONEST SHAPE. All four indexes on this table end in
     * `created_at DESC` and there is no other sortable candidate: `id` is a ULID and therefore
     * carries the same ordering, but it has no index of its own here — the primary key is the
     * composite `(id, created_at)` a partitioned table requires — so offering it would publish a
     * sort with nothing behind it that happens to agree with the one that does.
     *
     * @var list<string>
     */
    public const SORTABLE = ['created_at'];

    /**
     * `created_at` DESCENDING — newest first.
     *
     * The opposite of the source list's `id ASC`, and deliberately: an audit trail is read from the
     * present backwards ("what just happened", "who did that"), every index on the table is built
     * `created_at DESC` for exactly that, and page one of an append-only table under an ascending
     * sort is a bookmark to the oldest login in the organization's history.
     */
    public const DEFAULT_SORT = 'created_at';

    /**
     * The longest `subject_type` accepted. It is a PHP class name; the column is unbounded `text`
     * and the writer supplies `::class`, so this bounds a hostile query string rather than our own
     * data.
     */
    public const MAX_SUBJECT_TYPE_LENGTH = 255;

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
        return ListQuery::rules(self::SORTABLE, freeText: false) + [
            // ULID SHAPE ONLY, NO `exists:` — see the class docblock.
            'actor_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            // THE CLOSED VOCABULARY, READ FROM THE WRITER'S OWN MAP.
            'operation' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(AuditLogger::OPERATIONS))],
            'outcome' => ['sometimes', 'nullable', 'string', Rule::in([
                AuditLogger::OUTCOME_SUCCESS,
                AuditLogger::OUTCOME_FAILURE,
            ])],
            // OPEN, deliberately — see the class docblock. `required_with:subject_id` is the
            // pairing rule, stated on the TYPE so that an id with no type is the refusal: the
            // reverse (a type with no id) is a legitimate, if unindexed, query.
            //
            // NOTE THE ABSENT `sometimes`, WHICH EVERY OTHER OPTIONAL FIELD HERE CARRIES. It is the
            // one rule on this request that has to run when the field is ABSENT, and `sometimes`
            // means "skip every rule for this field unless it is present in the input" — so with it
            // the pairing rule fires only when `subject_type` was already sent, which is exactly
            // the case that needs no check. Measured: a `?subject_id=` with no type reached
            // `AuditLogFilter`'s constructor and became a 500 instead of a 422. `nullable` is what
            // keeps the absent-and-unpaired case legal.
            'subject_type' => [
                'bail',
                'required_with:subject_id',
                'nullable',
                'string',
                'max:'.self::MAX_SUBJECT_TYPE_LENGTH,
            ],
            'subject_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'from' => ['sometimes', 'date'],
            // `after:from` and NOT `after_or_equal`: the window is half-open, so `from == until` is
            // empty and the page would read as "nothing happened" — the most dangerous wrong answer
            // this particular surface can give.
            'until' => ['sometimes', 'date', 'after:from'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function openApiQueryParameters(): array
    {
        return array_merge(
            ListQuery::openApiQueryParameters(
                self::SORTABLE,
                defaultSort: self::DEFAULT_SORT,
                defaultDirection: SortDirection::Desc,
                freeText: false,
            ),
            [
                [
                    'name' => 'actor_id',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'Restrict to the actions of one member — "what did this person '
                        .'do". Rows with no actor (an unauthenticated event, or a platform action) '
                        .'are excluded by the predicate itself. An actor id belonging to another '
                        .'organization is not an error and is not a leak: the query carries the '
                        .'organization AND the actor together, so it matches nothing.',
                    'schema' => ['type' => 'string'],
                ],
                [
                    'name' => 'operation',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'Restrict to one audited operation. THE SET IS CLOSED: the '
                        .'writer refuses an operation outside it, so a value outside it cannot '
                        .'match any row that will ever exist and is answered with 422 rather than '
                        .'with an empty page a caller would read as "this never happened".',
                    'schema' => ['type' => 'string', 'enum' => array_keys(AuditLogger::OPERATIONS)],
                ],
                [
                    'name' => 'outcome',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'Restrict to successes or to failures. The outcome is DERIVED '
                        .'from the operation and never submitted, so no row can claim '
                        .'`auth.login.failed` with `outcome = success`; filtering on both at once '
                        .'is therefore a redundant but harmless narrowing.',
                    'schema' => [
                        'type' => 'string',
                        'enum' => [AuditLogger::OUTCOME_SUCCESS, AuditLogger::OUTCOME_FAILURE],
                    ],
                ],
                [
                    'name' => 'subject_type',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'Restrict to actions against one KIND of record, as the '
                        .'fully-qualified model class the trail stores — for example '
                        .'`App\\Models\\Bot`. THE SET IS OPEN, unlike `operation`: a new kind of '
                        .'subject needs no schema change, so an unknown value is an empty result '
                        .'rather than a 422. Read the value off `subject_type` on any row rather '
                        .'than composing it. Required when `subject_id` is given.',
                    'schema' => ['type' => 'string', 'maxLength' => self::MAX_SUBJECT_TYPE_LENGTH],
                ],
                [
                    'name' => 'subject_id',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'Restrict to one record — "the audit trail for THIS bot". MUST '
                        .'be sent with `subject_type`: an id with no type is ambiguous across every '
                        .'table, and the pair is what makes the read an index range rather than a '
                        .'scan.',
                    'schema' => ['type' => 'string'],
                ],
                [
                    'name' => 'from',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'Inclusive start of the window, as an ISO-8601 instant. Absent '
                        .'means "from always"; there is no default window and no maximum width, '
                        .'because a list is bounded by its page size rather than by its range.',
                    'schema' => ['type' => 'string', 'format' => 'date-time'],
                ],
                [
                    'name' => 'until',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'Exclusive end of the window, as an ISO-8601 instant. The '
                        .'window is half-open [from, until), so two adjacent windows partition the '
                        .'timeline exactly. Must be strictly after `from`.',
                    'schema' => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
        );
    }

    public function toQuery(): ListQuery
    {
        return ListQuery::fromValidated(
            $this->validated(),
            defaultSort: self::DEFAULT_SORT,
            defaultDirection: SortDirection::Desc,
        );
    }

    public function toFilter(): AuditLogFilter
    {
        /** @var array{actor_id?: string|null, operation?: string|null, outcome?: string|null, subject_type?: string|null, subject_id?: string|null, from?: string, until?: string} $validated */
        $validated = $this->validated();

        return new AuditLogFilter(
            actorId: $validated['actor_id'] ?? null,
            operation: $validated['operation'] ?? null,
            outcome: $validated['outcome'] ?? null,
            subjectType: $validated['subject_type'] ?? null,
            subjectId: $validated['subject_id'] ?? null,
            from: isset($validated['from']) ? CarbonImmutable::parse($validated['from'])->utc() : null,
            until: isset($validated['until']) ? CarbonImmutable::parse($validated['until'])->utc() : null,
        );
    }
}
