<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\BotAccessMode;
use App\Enums\BotAnswerMode;
use App\Enums\BotStatus;
use App\Enums\EvidenceThresholdScale;
use App\Rules\EvidenceThresholdWithinScale;
use App\Rules\ReadableThemeColor;
use App\Services\Bots\BotEdit;
use App\Support\Theme\ThemeVocabulary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit one bot. A PATCH: every field is optional and an absent field is left alone.
 *
 * ── `sometimes|required` IS THE OPERATIVE PAIR, AND IT DOES NOT MEAN "REQUIRED" ────────────────
 *
 * `sometimes` removes the whole rule set for a key that is not in the body; `required` then applies
 * to the keys that ARE. Together they read as "if you sent it, it must not be empty" — which is the
 * rule a PATCH actually needs for a NOT NULL column, and it is a rule that is easy to get wrong in
 * both directions. `required` alone would make every field mandatory on every edit and turn this
 * into a PUT with worse ergonomics. `sometimes` alone would accept `{"name": null}` and blank a
 * column the schema will not hold — `ConvertEmptyStringsToNull` turns `""` into null before this
 * class ever sees it, so "the client cleared the field" and "the client sent an empty box" arrive
 * identically and both must be refused for `name`.
 *
 * The NULLABLE columns take `sometimes|nullable` instead, and there the two states are genuinely
 * different instructions: an absent `description` is "leave it", and a null one is "clear it".
 * `BotEdit` is a column MAP rather than a set of nullable members precisely so those two survive
 * the trip to the repository; that class records why.
 *
 * ── A PATCH THAT NAMES NOTHING IS REFUSED, AND NOT BY A RULE ──────────────────────────────────
 *
 * `UpdateProviderConnectionRequest` refuses its empty body declaratively, with `required_without`
 * in both directions, and states why it matters: a request that changes nothing still returns 200
 * and still writes an `updated` audit row describing an edit that did not happen, which makes the
 * trail lie in the one direction nobody checks. The same defect exists here and the same fix does
 * not: with twenty-five fields the declarative spelling is `required_without_all` naming
 * twenty-four siblings on each of twenty-five fields, which is the exact construction
 * `UpdateProviderModelRequest` rejected as unreadable — and there the answer was to make the
 * endpoint a PUT, which is not available here because a bot's complete state includes columns this
 * endpoint must never accept.
 *
 * So the check lives in `BotController::update()` against `BotEdit::isEmpty()`, and it is raised as
 * a 422 with a real per-field map rather than as a bare `abort(422)`. That is not fussiness: the
 * error envelope's `errors` key is documented as "present only on `validation`, and only when a
 * producer made one", and `apps/web` discriminates the ADR-031 resolver refusal on the shape
 * `validation` WITH NO MAP. A second map-less 422 on this surface would be a second thing wearing
 * that signature.
 *
 * ── THE FIELDS THAT ARE ABSENT ────────────────────────────────────────────────────────────────
 *
 * `organization_id`, `public_bot_id` and `retrieval_configuration_version` have no rule here, no
 * member in `BotEdit`, and no assignment in the repository — three layers, because a missing
 * validation rule is one careless line away from coming back. `StoreBotRequest` records what each
 * of them would cost. `public_bot_id` is the one to note twice: a client that could CHANGE it would
 * break every live embed on the customer's own site and return 200 while doing it.
 */
final class UpdateBotRequest extends FormRequest
{
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
     * ── `status` IS WRITABLE HERE AND IS NOT ON THE CREATE PATH ────────────────────────────────
     *
     * A bot is created `draft`, always, and every transition is this endpoint. The full vocabulary
     * is accepted from `BotStatus::values()` and never from a literal — including `archived`, which
     * is terminal: `BotService` refuses every subsequent write to an archived bot, including one
     * that would change the status back, because an archived bot's configuration is the record of
     * what answered the conversations it produced.
     *
     * WHAT MAKES PUBLISHING STRICTER THAN A RENAME IS NOT A RULE HERE AND NOT A PERMISSION. Both
     * are `bots.manage`, and `BotPolicy` records why a `bots.publish` permission would be granted
     * to exactly the same two roles and would therefore fail silently in both directions. It is
     * CHECK 5, the publish guard in `BotService`, which is evaluated against the state this edit
     * LEAVES the bot in rather than against the body — so clearing the model on an already-published
     * bot is refused by the same check that refuses publishing a model-less draft.
     *
     * Every other rule below is byte-identical to `StoreBotRequest`'s apart from the `sometimes`
     * prefix, deliberately: two endpoints that disagreed about what a legal `slug` or a legal
     * `oklch()` is would be discovered by a customer who created a bot one way and edited it the
     * other.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'sometimes', 'required', 'string', 'min:1', 'max:120'],
            'slug' => [
                'bail', 'sometimes', 'required', 'string', 'max:64',
                'regex:/^[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?$/',
            ],

            'description' => ['bail', 'sometimes', 'nullable', 'string', 'max:2000'],
            'welcome_message' => ['bail', 'sometimes', 'nullable', 'string', 'max:2000'],
            'placeholder_text' => ['bail', 'sometimes', 'nullable', 'string', 'max:200'],
            'system_instruction' => ['bail', 'sometimes', 'nullable', 'string', 'max:8000'],
            'answer_style_instruction' => ['bail', 'sometimes', 'nullable', 'string', 'max:4000'],

            // `prohibited` AND NOT AN ABSENT RULE. A transition moved to
            // PUT /bots/{bot}/status, which is where CHECK 5 is impossible to miss — `status` is
            // the one field on a bot that decides whether an END USER can reach it, and two doors
            // to that column are two places a check has to be. Simply deleting the rule would make
            // `validated()` SILENTLY DISCARD the field, so a client that had not been updated
            // would publish a bot, receive a 200, and find it still in `draft`. This is a 422
            // naming the endpoint instead, and it appears in
            // packages/contracts/rules/UpdateBotRequest.json so a generated client is told.
            'status' => ['prohibited'],
            'access_mode' => ['bail', 'sometimes', 'required', 'string', Rule::in(BotAccessMode::values())],

            // BOTH NULLABLE, because clearing the model selection is a legitimate edit — a bot
            // moving back to "vendor not chosen" is how an operator undoes a mistake. The pairing
            // that matters is the RESULTING one and it is checked in BotService: a body that clears
            // only the connection while a model stays stored would pass every rule here.
            'provider_connection_id' => [
                'bail', 'sometimes', 'nullable', 'string', 'ulid', 'required_with:provider_model_id',
            ],
            'provider_model_id' => ['bail', 'sometimes', 'nullable', 'string', 'ulid'],

            'answer_mode' => ['bail', 'sometimes', 'required', 'string', Rule::in(BotAnswerMode::values())],
            'dense_top_k' => ['bail', 'sometimes', 'required', 'integer', 'min:1', 'max:200'],
            'sparse_top_k' => ['bail', 'sometimes', 'required', 'integer', 'min:1', 'max:200'],
            'rerank_candidates' => ['bail', 'sometimes', 'required', 'integer', 'min:20', 'max:30'],
            'rerank_retain' => ['bail', 'sometimes', 'required', 'integer', 'min:6', 'max:10'],

            // NO `sometimes` ON EITHER HALF — the same omission StoreBotRequest makes and for the
            // same reason: `sometimes` removes the whole rule set for an absent key INCLUDING the
            // implicit `required_with`, so a body carrying only one half would have the other's
            // rules skipped entirely and the pairing check would never fire. The cost of leaving it
            // off is nil, because `nullable` already lets an absent field pass everything else.
            'evidence_threshold' => [
                'bail',
                'nullable',
                'numeric',
                'min:-100', 'max:100',
                'required_with:evidence_threshold_scale',
                new EvidenceThresholdWithinScale,
            ],
            'evidence_threshold_scale' => [
                'bail',
                'nullable',
                'string',
                Rule::in(EvidenceThresholdScale::values()),
                'required_with:evidence_threshold',
            ],

            'allow_general_answers' => ['bail', 'sometimes', 'required', 'boolean'],

            // THE THEME IS REPLACED WHOLESALE, NOT MERGED, and that is why there is no way to clear
            // one key on its own. It is a configuration snapshot written once and read whole —
            // `postgresql-patterns` admits jsonb for exactly that shape — so a merge would need a
            // second vocabulary for "delete this key" and would make the stored value depend on
            // every previous request rather than on this one. Sending `{"theme": {}}` restores the
            // platform theme; omitting `theme` leaves it untouched.
            'theme' => ['bail', 'sometimes', ThemeVocabulary::arrayRule()],
            'theme.primary' => ['bail', 'sometimes', 'string', 'max:64', new ReadableThemeColor],
            'theme.accent' => ['bail', 'sometimes', 'string', 'max:64', new ReadableThemeColor],
            'theme.radius' => ['bail', 'sometimes', 'string', Rule::in(ThemeVocabulary::RADII)],

            'rate_limit_per_minute' => ['bail', 'sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'rate_limit_per_day' => ['bail', 'sometimes', 'nullable', 'integer', 'min:1', 'max:100000000'],
            'retention_days' => ['bail', 'sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],

            'collect_end_user_data' => ['bail', 'sometimes', 'required', 'boolean'],
            // The disclosure requirement is checked against the RESULTING row in BotService, not
            // with `required_if_accepted` here: enabling collection on a bot that already carries a
            // disclosure would otherwise be refused for a field the caller had no reason to resend.
            // StoreBotRequest states the whole argument.
            'consent_text' => ['bail', 'sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $pair = 'An evidence threshold and its scale are meaningless apart: the same float is an '
            .'unbounded logit on one provider and a bounded relevance score on another, and applying '
            .'one scale\'s number to the other moves only the refusal rate, only in aggregate, and '
            .'raises nothing anywhere. Send both, or neither.';

        return [
            'name.required' => 'A bot needs a name. Sending the field empty is how a form clears '
                .'it, and there is no nameless state for a row whose name is what identifies it in '
                .'the console and in the audit trail after it is deleted. Omit the field to leave '
                .'the current name alone.',
            'slug.regex' => 'A slug is lower-case letters, digits and internal hyphens — '
                .'`support-desk`, not `Support Desk` — and it may not start or end with a hyphen.',
            'provider_connection_id.required_with' => 'A model needs the connection it is '
                .'registered under. Clearing the connection while keeping the model would leave the '
                .'bot naming a model row that reaches no credential.',
            'evidence_threshold.required_with' => $pair,
            'evidence_threshold_scale.required_with' => $pair,
            'theme.array' => 'A theme carries `primary`, `accent` and `radius` and nothing else, '
                .'and it is REPLACED by what you send rather than merged into what is stored. Send '
                .'`{}` to restore the platform theme; omit the field to leave the current one.',
            'theme.radius.in' => 'A radius is one of the six values the design tokens publish. The '
                .'renderer matches this string exactly against that set and drops anything else.',
            'status.prohibited' => 'A lifecycle transition is PUT /bots/{bot}/status, not a field '
                .'on this edit. It is separate because publishing is the one change here that '
                .'decides whether an end user can reach the bot at all, and it is refused for a '
                .'bot with no provider model or for one in `rag_first` mode with '
                .'`allow_general_answers` still false — checks that belong on a transition rather '
                .'than beside a rename. Send the rest of this body without `status`, then call '
                .'that endpoint.',
        ];
    }

    /**
     * The validated edit, as a type.
     *
     * ── PRESENCE IS READ FROM `validated()`, WHICH IS THE ONLY HONEST SOURCE FOR IT ────────────
     *
     * `Validator::validated()` skips a key that was not in the request and KEEPS a key that was
     * present with a null value, which is exactly the distinction a PATCH turns on. Reading the raw
     * input instead would include fields that failed validation, and reading `$this->input($key)`
     * with a default would collapse "absent" and "null" into one answer — the collapse that makes a
     * PATCH silently clear a column the caller never mentioned.
     *
     * ── EVERY VALUE IS CAST TO THE TYPE THE MODEL'S OWN CAST PRODUCES ──────────────────────────
     *
     * Enums as enum cases, integers as integers, the threshold as a float. Two things depend on it,
     * and both fail quietly if it is skipped: the repository assigns these values straight onto the
     * model with `setAttribute()`, and it decides whether to bump
     * `retrieval_configuration_version` by comparing the incoming value against the CAST value
     * already on the row. A raw `'strict'` string compared against a `BotAnswerMode` case is never
     * equal, so an unchanged answer mode would mint a new configuration identity on every save.
     */
    public function toData(): BotEdit
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        $columns = [];

        foreach (BotEdit::WRITABLE as $column) {
            if (! array_key_exists($column, $data)) {
                continue;
            }

            $columns[$column] = $this->coerce($column, $data[$column]);
        }

        return new BotEdit($columns);
    }

    /**
     * One validated value, in the shape the model's cast would have produced.
     */
    private function coerce(string $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($column) {
            // UNREACHABLE TODAY AND KEPT DELIBERATELY. `status` is `prohibited` above, so it never
            // survives `validated()` and this arm never runs. It stays because deleting it makes
            // the FAILURE MODE of re-adding the rule silent: an uncoerced raw string reaching
            // `BotEdit` is not a `BotStatus`, so `statusAfter()` falls back to the STORED status
            // and the publish guard evaluates the wrong resulting state while every test that
            // asserts on the response body still passes.
            'status' => BotStatus::from((string) $value),
            'access_mode' => BotAccessMode::from((string) $value),
            'answer_mode' => BotAnswerMode::from((string) $value),
            'evidence_threshold_scale' => EvidenceThresholdScale::from((string) $value),
            'dense_top_k', 'sparse_top_k', 'rerank_candidates', 'rerank_retain',
            'rate_limit_per_minute', 'rate_limit_per_day', 'retention_days' => (int) $value,
            'evidence_threshold' => (float) $value,
            'allow_general_answers', 'collect_end_user_data' => (bool) $value,
            'theme' => self::theme($value),
            default => is_string($value) ? $value : null,
        };
    }

    /**
     * The theme as a map of strings, which is the only shape `bots_theme_vocabulary` will hold.
     *
     * The rules have already closed the key set and required each present value to be a string, so
     * this is a narrowing for the analyser rather than a second validation — written as a filter
     * and not a cast, because a value that somehow arrived as a non-string would otherwise reach
     * `jsonb` as a number or a boolean and be refused by the CHECK at 500 rather than 422.
     *
     * @return array<string, string>
     */
    private static function theme(mixed $theme): array
    {
        if (! is_array($theme)) {
            return [];
        }

        $clean = [];

        foreach (ThemeVocabulary::KEYS as $key) {
            $value = $theme[$key] ?? null;

            if (is_string($value)) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
