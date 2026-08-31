<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Analytics\AnalyticsWindow;
use App\Support\Contracts\ProvidesOpenApiQueryParameters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The query string of `GET .../analytics`: a half-open window and an optional bot.
 *
 * ── THE WINDOW IS BOUNDED IN BOTH DIRECTIONS AND THE BOUND IS ENFORCED HERE ──────────────────
 *
 * Every tile is an aggregate over a time range, so an unbounded `from` is an aggregate over the
 * whole table — for `provider_calls` and `messages` that is every row this organization has ever
 * produced, on a screen that renders on every dashboard load. `MAX_WINDOW_DAYS` is the ceiling and
 * it is a VALIDATION RULE rather than a clamp: silently narrowing a 400-day request to 366 days
 * would answer a different question from the one asked and label it with the caller's dates.
 *
 * ── `bot_id` IS VALIDATED FOR SHAPE AND NOT FOR OWNERSHIP, DELIBERATELY ──────────────────────
 *
 * There is no `exists:` rule on it, and adding one would be the Filament CVE-2026-48067 shape
 * exactly: the select query is tenant-scoped and the validation rule for the same field is not, so
 * `exists:bots,id` becomes an existence oracle over every organization's bots, reachable by anyone
 * with a valid session in any organization. Ownership is enforced where it belongs — every
 * repository predicate carries `organization_id` AND the bot id together, so a foreign bot id
 * simply matches nothing and every tile reads zero. That is the correct answer to "show me the
 * analytics for a bot that is not yours": not an error, not a leak, an empty page.
 *
 * ── `organization_id` APPEARS IN NO RULE AND IN NO DTO ───────────────────────────────────────
 *
 * The organization comes from the bound route segment, which `org.member` has already proved this
 * caller belongs to. Over-posting a tenant key is an authorization bug with a 200 response
 * (`laravel-rbac-policies` NN5).
 */
final class ShowAnalyticsRequest extends FormRequest implements ProvidesOpenApiQueryParameters
{
    /**
     * The widest window this endpoint will compute, in days.
     *
     * 366 rather than 365, so "the last year" spans a leap year without a caller having to know it
     * is one. It is a bound on WORK, not a retention statement: rows older than this are still there
     * and still exportable through the reporting queue (`config/queue.php`'s `exports`), which is
     * the surface that may take minutes.
     */
    public const MAX_WINDOW_DAYS = 366;

    /** Absent `from`/`until` mean "the last 30 days", which is what a dashboard opens on. */
    public const DEFAULT_WINDOW_DAYS = 30;

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
        return [
            // `date` and not `date_format:Y-m-d`: a dashboard legitimately asks for a window that
            // starts mid-day (an incident review), and pinning the format would refuse an ISO-8601
            // instant, which is what every client in this repository already produces.
            'from' => ['sometimes', 'date'],
            // `after:from` and NOT `after_or_equal`: the window is half-open, so `from == until` is
            // empty and every tile would read zero — a page of zeroes that looks like "no traffic"
            // rather than like a bad request. AnalyticsWindow refuses the same shape one layer down.
            'until' => ['sometimes', 'date', 'after:from'],
            // ULID SHAPE ONLY. No `exists:` — see the class docblock on why that rule would be an
            // existence oracle over every tenant's bots.
            'bot_id' => ['sometimes', 'nullable', 'string', 'ulid'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function openApiQueryParameters(): array
    {
        return [
            [
                'name' => 'from',
                'in' => 'query',
                'required' => false,
                'description' => 'Inclusive start of the window, as an ISO-8601 instant. Defaults '
                    .'to '.self::DEFAULT_WINDOW_DAYS.' days before `until`. The window is half-open '
                    .'[from, until), so two adjacent windows partition the timeline exactly.',
                'schema' => ['type' => 'string', 'format' => 'date-time'],
            ],
            [
                'name' => 'until',
                'in' => 'query',
                'required' => false,
                'description' => 'Exclusive end of the window, as an ISO-8601 instant. Defaults to '
                    .'now. Must be strictly after `from`; the window may not exceed '
                    .self::MAX_WINDOW_DAYS.' days.',
                'schema' => ['type' => 'string', 'format' => 'date-time'],
            ],
            [
                'name' => 'bot_id',
                'in' => 'query',
                'required' => false,
                'description' => 'Restrict every tile to one bot. A bot id belonging to another '
                    .'organization is not an error and is not a leak: every query carries the '
                    .'organization AND the bot together, so it matches nothing and the page reads '
                    .'zero.',
                'schema' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * The validated window, with the defaults applied HERE rather than in the rules.
     *
     * A validation rule cannot express a default — that is `ProvidesOpenApiQueryParameters`' own
     * argument for why the parameters are declared rather than derived — so the two absent-value
     * behaviours live in this method, next to the rules they complete.
     */
    public function toWindow(): AnalyticsWindow
    {
        /** @var array{from?: string, until?: string, bot_id?: string|null} $validated */
        $validated = $this->validated();

        $until = isset($validated['until'])
            ? CarbonImmutable::parse($validated['until'])->utc()
            : CarbonImmutable::now('UTC');

        $from = isset($validated['from'])
            ? CarbonImmutable::parse($validated['from'])->utc()
            : $until->subDays(self::DEFAULT_WINDOW_DAYS);

        return new AnalyticsWindow($from, $until, $validated['bot_id'] ?? null);
    }

    /**
     * The width bound, applied AFTER the two dates have been resolved.
     *
     * IT CANNOT BE A RULE, and that is why it is here. `from` and `until` both have defaults, so the
     * width of the window is not a property of the submitted fields — a request with neither is 30
     * days wide and a request with only `from` is "from then until now", which can be any width at
     * all. A `before:` rule would only bound the pair that were both submitted, which is the case a
     * caller is least likely to get wrong.
     *
     * It renders as a 422 with a per-field message keyed on `from`, so the console draws it against
     * the control the operator can move.
     */
    protected function passedValidation(): void
    {
        $window = $this->toWindow();

        if ($window->from->diffInDays($window->until) > self::MAX_WINDOW_DAYS) {
            $this->validator->errors()->add(
                'from',
                'An analytics window may span at most '.self::MAX_WINDOW_DAYS.' days. Every tile '
                .'on this page is an aggregate over that range, so a wider one is a full-table scan '
                .'on a screen that loads on every visit. Narrow the window, or use an export for a '
                .'longer period.',
            );

            $this->failedValidation($this->validator);
        }
    }
}
