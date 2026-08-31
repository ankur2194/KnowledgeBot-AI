<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Enums\SortDirection;
use App\Services\Conversations\ConversationFilter;
use App\Support\Contracts\ProvidesOpenApiQueryParameters;
use App\Support\Http\ListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The query string of `GET .../conversations`.
 *
 * The pagination rules are MERGED from `ListQuery::rules()` rather than inherited from a base
 * `ListRequest`, for the mechanical reason `IndexBotsRequest` and `IndexSourcesRequest` both
 * record: `kb:dump-form-rules` SKIPS abstract classes while `DumpFormRulesCommandTest` requires a
 * dumped document for every `is_subclass_of(FormRequest)` hit in the tree, so an abstract base
 * would be counted and never dumped and the contract gate would fail on correct code.
 *
 * ── THE SORTABLE SET IS TWO CLOCKS, AND `id` IS DELIBERATELY NOT OFFERED ─────────────────────
 *
 * `last_activity_at` (the default, descending) and `started_at`. Both have an org-leading composite
 * index whose trailing column they are — `conversations_org_bot_activity (organization_id, bot_id,
 * last_activity_at DESC)` and `conversations_org_status_started (organization_id, status,
 * started_at DESC)` — so with the matching filter applied each sort is an index range with the
 * order already satisfied, which is the console's normal read.
 *
 * WITHOUT THAT FILTER NEITHER INDEX IS AN EXACT MATCH (the middle column is a gap) and the read
 * becomes a bounded sort of ONE ORGANIZATION'S conversations. That is accepted rather than fixed
 * with a fifth index, and the precedent is stated rather than assumed: `IndexSourcesRequest` admits
 * `name`, `type` and `status` on exactly that basis — "either an index range or a bounded sort of a
 * single organization's rows" — and this table has the same shape with a page size that is already
 * capped. THE MEASUREMENT THAT RE-OPENS IT: a tenant whose conversation count makes the unfiltered
 * first page miss its latency budget. The migration that answers it is `CREATE INDEX CONCURRENTLY
 * conversations_org_activity (organization_id, last_activity_at DESC)`, and it should be budgeted
 * as a lock-safe deploy rather than added as a follow-up to something else.
 *
 * `id` IS NOT OFFERED even though it is a ULID and therefore creation-ordered. `started_at` IS the
 * creation time on this table (the migration has no separate `created_at`, and
 * `Conversation::CREATED_AT` points at it), so an `id` sort would be the same ordering under a
 * third name and would invite an index that duplicates one that exists.
 *
 * ── THERE IS NO FREE-TEXT `filter`, AND ITS ABSENCE IS DECLARED ─────────────────────────────
 *
 * `ListQuery::rules(..., freeText: false)`. The columns a reviewer narrows by are the bot, the
 * channel, the status, the participant and the window — all equality or range predicates over
 * indexed columns — and the only prose in a thread lives one table down in `messages`, where an
 * `ILIKE '%term%'` is a scan of every message this organization has ever exchanged. Searching
 * message text is a full-text feature with its own index, not a `filter` parameter that happens to
 * be slow. `meta.filter` still comes back null: it is a field of the shared `ListMetaResource`
 * component, and a client that had to branch on its presence would be branching on which endpoint
 * it called.
 *
 * ── NO `exists:` RULE ON ANY IDENTIFIER, AND THAT IS THE SECURITY-RELEVANT PART ─────────────
 *
 * `bot_id` and `user_id` are validated for SHAPE only. `exists:bots,id` is unscoped, so it would
 * answer "does this id exist anywhere in the platform" to anyone with a session in any
 * organization — the Filament CVE-2026-48067 shape, where the select query is tenant-scoped and the
 * validation rule for the same field is not. `ShowAnalyticsRequest` refuses the same rule for the
 * same reason. Ownership is enforced where it belongs: every repository predicate carries
 * `organization_id` AND the filtered column together, so a foreign id simply matches nothing and
 * the page reads empty. That is the correct answer to "show me another tenant's threads".
 *
 * `session_id` IS NOT PATTERN-CONSTRAINED TO THE COLUMN'S OWN GRAMMAR beyond a length bound. The
 * `conversations_anonymous_session_shape` CHECK refuses anything outside `[A-Za-z0-9_-]{16,128}` at
 * write time, so a value outside it cannot be stored and therefore cannot match — refusing it here
 * as well would only decide whether a hopeless search returns 422 or an empty page, and an empty
 * page is the honest answer to "find the threads for this session token".
 *
 * ── `organization_id` APPEARS IN NO RULE AND IN NO DTO ──────────────────────────────────────
 *
 * The organization comes from the bound route segment, which `org.member` has already proved this
 * caller belongs to. Over-posting a tenant key is an authorization bug with a 200 response
 * (`laravel-rbac-policies` NN5).
 */
final class IndexConversationsRequest extends FormRequest implements ProvidesOpenApiQueryParameters
{
    /**
     * The columns this endpoint permits an `ORDER BY` on. Closed, because a caller-chosen `sort`
     * reaches an `ORDER BY` — an open set is a caller choosing which index the query uses at best
     * and injecting at worst.
     *
     * @var list<string>
     */
    public const SORTABLE = ['last_activity_at', 'started_at'];

    /**
     * `last_activity_at` DESCENDING — the most recently active thread first.
     *
     * A conversation list is read to find out what is happening now, not what happened first, which
     * is the opposite of the source list's `id ASC` and is why the default is stated per endpoint
     * rather than in `ListQuery`.
     */
    public const DEFAULT_SORT = 'last_activity_at';

    /** The longest session token accepted in a filter. The column's own CHECK caps it at 128. */
    public const MAX_SESSION_LENGTH = 128;

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
            'bot_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'user_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'session_id' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_SESSION_LENGTH],
            // CLOSED VOCABULARIES, generated from the enums the CHECK constraints are generated
            // from, so a value this endpoint accepts is a value the column can hold.
            'status' => ['sometimes', 'nullable', 'string', Rule::in(ConversationStatus::values())],
            'channel' => ['sometimes', 'nullable', 'string', Rule::in(ConversationChannel::values())],
            'from' => ['sometimes', 'date'],
            // `after:from` and NOT `after_or_equal`: the window is half-open, so `from == until` is
            // empty and the page would read as "this bot has never been used" rather than as a bad
            // request. `ConversationFilter`'s constructor refuses the same shape one layer down, for
            // the service and job callers that never ran a FormRequest.
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
                    'name' => 'bot_id',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'Restrict to one bot. A bot id belonging to another '
                        .'organization is not an error and is not a leak: the query carries the '
                        .'organization AND the bot together, so it matches nothing and the page '
                        .'reads empty. Supplying it also makes the default sort an exact index '
                        .'range.',
                    'schema' => ['type' => 'string'],
                ],
                [
                    'name' => 'user_id',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'Restrict to the threads held by one AUTHENTICATED '
                        .'participant. This is the subject-access query — "everything this person '
                        .'said to us" — and it matches nothing for an anonymous visitor, who is '
                        .'identified by `session_id` instead. Exactly one of the two columns is '
                        .'populated on any conversation.',
                    'schema' => ['type' => 'string'],
                ],
                [
                    'name' => 'session_id',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'Restrict to the threads held by one ANONYMOUS session. The '
                        .'token is opaque and platform-minted; a value outside its stored grammar '
                        .'simply matches nothing.',
                    'schema' => ['type' => 'string', 'maxLength' => self::MAX_SESSION_LENGTH],
                ],
                [
                    'name' => 'status',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'Restrict to one lifecycle state.',
                    'schema' => ['type' => 'string', 'enum' => ConversationStatus::values()],
                ],
                [
                    'name' => 'channel',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'Restrict to one channel: hosted chat, the embedded widget, '
                        .'the mobile app, the API, or the admin playground.',
                    'schema' => ['type' => 'string', 'enum' => ConversationChannel::values()],
                ],
                [
                    'name' => 'from',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'Inclusive start of the window, as an ISO-8601 instant, '
                        .'applied to `started_at`. THE WINDOW SELECTS THREADS THAT STARTED IN IT '
                        .'and not threads that were active in it — the first partitions the '
                        .'timeline exactly and the second does not, and the analytics surface makes '
                        .'the same choice so the two count one population. Absent means "from '
                        .'always"; there is no default window and no maximum width, because a list '
                        .'is bounded by its page size.',
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

    public function toFilter(): ConversationFilter
    {
        /** @var array{bot_id?: string|null, user_id?: string|null, session_id?: string|null, status?: string|null, channel?: string|null, from?: string, until?: string} $validated */
        $validated = $this->validated();

        return new ConversationFilter(
            botId: $validated['bot_id'] ?? null,
            channel: isset($validated['channel'])
                ? ConversationChannel::tryFrom((string) $validated['channel'])
                : null,
            status: isset($validated['status'])
                ? ConversationStatus::tryFrom((string) $validated['status'])
                : null,
            userId: $validated['user_id'] ?? null,
            sessionId: $validated['session_id'] ?? null,
            from: isset($validated['from']) ? CarbonImmutable::parse($validated['from'])->utc() : null,
            until: isset($validated['until']) ? CarbonImmutable::parse($validated['until'])->utc() : null,
        );
    }
}
