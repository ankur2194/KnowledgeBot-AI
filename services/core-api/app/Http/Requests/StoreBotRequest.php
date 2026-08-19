<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\BotAccessMode;
use App\Enums\BotAnswerMode;
use App\Enums\EvidenceThresholdScale;
use App\Rules\EvidenceThresholdWithinScale;
use App\Rules\ReadableThemeColor;
use App\Services\Bots\NewBot;
use App\Support\Theme\ThemeVocabulary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create one bot.
 *
 * ── THE FIELDS THAT ARE ABSENT, AND WHY EACH ONE IS ABSENT ────────────────────────────────────
 *
 * `organization_id` — never validated, never posted, never in a DTO. It comes from the
 * authenticated context as a positional argument on the repository method. Over-posting a tenant
 * key is an authorization bug with a 200 response (laravel-rbac-policies NN5), and
 * `Model::shouldBeStrict()` turns the silent drop into an exception rather than a shrug.
 *
 * `public_bot_id` — SERVER-MINTED AND NOT SETTABLE, EVER. There is no rule here, `NewBot` has no
 * member to hold one, and `App\Support\Kb\PublicBotIdentifier` is the only thing that produces one.
 * A client that could SET it could collide with another organization's token — the global unique
 * index would refuse the write, which turns the column into an existence oracle over the whole
 * platform — and a client that could CHANGE it would break every live embed on the customer's own
 * site with a 200. The omission is defended in three places rather than one because a missing
 * validation rule is one careless line away from coming back.
 *
 * `status` — a bot is created `draft`, always. Creating one directly into `published` would run the
 * publish guard against a source assignment that cannot exist yet, so the create path would carry a
 * second, weaker copy of a check `PATCH /bots/{bot}` already owns.
 *
 * `retrieval_configuration_version` — DERIVED. It starts at 1 and moves only when `BotService`
 * writes a retrieval knob; a client that could set it could make two different configurations claim
 * the same version, which is precisely the identity the §21.5 regression gate replays against.
 *
 * ── NO `exists:` AND NO `unique:` RULE, ON ANY FIELD ──────────────────────────────────────────
 *
 * The slug's uniqueness and the (connection, model) pair's ownership are BOTH checked, and neither
 * is checked here. The reasoning is written out in `DesignateEmbeddingConnectionRequest` and holds
 * identically: those rules query their table with NO organization predicate unless somebody
 * remembers to add one, which is the exact shape of Filament CVE-2026-48067 — the select query was
 * tenant-scoped and the validation rule for the same field was not. Spelling the scope into the
 * rule string by hand would work and would also be a tenant predicate assembled from route input
 * inside a string, which is the thing that goes wrong.
 *
 * There is a second reason here that there was not there, and it is the stronger one: the slug is
 * unique PER ORGANIZATION. An unscoped `unique:bots,slug` would refuse a handle another tenant
 * happens to have taken — an existence oracle over the whole platform, rendered as a validation
 * error on a form. `BotService` performs both checks through org-scoped repository methods.
 *
 * ── EVERY BOUND BELOW IS AN ABSURDITY BOUND OR A SPECIFICATION BAND, AND THE TWO ARE DIFFERENT ─
 *
 * The four retrieval depths carry docs/07 §12.7-12.12's own bands, which the CHECK constraints also
 * carry, because a value outside them is not a tuning choice — it is a typo that changes what the
 * §21.5 regression gate is comparing. The text lengths are absurdity bounds with no column
 * equivalent (`text` is unbounded in PostgreSQL) and are deliberately generous: they exist so one
 * request cannot put a megabyte of prose in a row that is read whole on every chat turn.
 */
final class StoreBotRequest extends FormRequest
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
     * ── WHY THE EVIDENCE PAIR IS `required_with` IN BOTH DIRECTIONS AND STILL NOT THE AUTHORITY ─
     *
     * `bots_evidence_threshold_paired` CHECKs `num_nonnulls(threshold, scale) <> 1`: a number with
     * no scale, or a scale with no number, is uninterpretable and the table will not hold it. The
     * mutual `required_with` is the same rule expressed where the generated client can see it, and
     * it is exactly right for a CREATE, where the resulting row is the request.
     *
     * It is not sufficient on the PATCH — a body that clears only the scale would pass every rule
     * and violate the CHECK against the stored threshold — so `BotService` re-checks the RESULTING
     * pair on both paths and is the authority. Both statements are kept: one is the good message
     * for the common case and the shape a client is generated from, the other is the one that is
     * correct on every path.
     *
     * ── `provider_connection_id` IS `required_with:provider_model_id` AND NOT THE REVERSE ──────
     *
     * One direction only, and it mirrors what the schema will and will not hold. A connection with
     * no model is a real, common state — "I have chosen the vendor, not the model yet" — and both
     * columns are nullable with `MATCH SIMPLE` foreign keys precisely so a half-configured draft is
     * expressible. A MODEL with no connection is not a state at all: a `provider_models` row names
     * a credential only through its parent, so the pair would name no credential. Nothing in the
     * database refuses it, which is why `BotService::assertModelSelection()` does.
     *
     * ── THE THEME'S KEY SET IS CLOSED HERE AND ITS VALUE GRAMMAR IS CHECKED HERE ───────────────
     *
     * `bots_theme_vocabulary` constrains the key set and the value TYPES and stops there. Whether
     * `primary` is a legal `oklch()` triple, whether `radius` is one of the six values
     * `packages/design-tokens` publishes, and whether the supplied colour can be given readable
     * text at all are this class's job — the create migration says so by name. The third is the one
     * that is easy to miss and is the reason `ReadableThemeColor` exists: a colour in the
     * unreachable contrast band is REFUSED by the renderer, so a grammar that accepted it here
     * would tell the customer their colour was accepted and then quietly serve the platform
     * default.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'min:1', 'max:120'],
            // The handle, unique PER ORGANIZATION. The pattern is `bots_slug_shape` verbatim:
            // lower-case alphanumerics and internal hyphens, 1-64 characters, never starting or
            // ending with a hyphen. Two independent statements of one grammar; a change to either
            // is a change to both.
            'slug' => ['bail', 'required', 'string', 'max:64', 'regex:/^[a-z0-9]([a-z0-9-]{0,62}[a-z0-9])?$/'],

            // ALL NULLABLE, and null is the real state rather than a missing one: a bot with no
            // welcome message renders the platform default. The empty string is not a second
            // spelling of it — `ConvertEmptyStringsToNull` turns `""` into null before this class
            // sees it, and `bots_text_not_blank` refuses the blank spelling for every writer that
            // is not an HTTP request.
            'description' => ['bail', 'nullable', 'string', 'max:2000'],
            'welcome_message' => ['bail', 'nullable', 'string', 'max:2000'],
            'placeholder_text' => ['bail', 'nullable', 'string', 'max:200'],
            // THE BOT'S OWN SYSTEM PROMPT. Bounded generously because operators write real
            // instructions here, and bounded at all because it is read whole into the configuration
            // snapshot on every chat turn. It is never echoed into an audit row — see
            // BotService::record().
            'system_instruction' => ['bail', 'nullable', 'string', 'max:8000'],
            'answer_style_instruction' => ['bail', 'nullable', 'string', 'max:4000'],

            'access_mode' => ['bail', 'sometimes', 'string', Rule::in(BotAccessMode::values())],

            'provider_connection_id' => [
                'bail', 'nullable', 'string', 'ulid', 'required_with:provider_model_id',
            ],
            'provider_model_id' => ['bail', 'nullable', 'string', 'ulid'],

            'answer_mode' => ['bail', 'sometimes', 'string', Rule::in(BotAnswerMode::values())],
            // docs/07 §12.7-12.12's own bands, the same numbers `bots_dense_top_k_range` and its
            // three siblings carry.
            'dense_top_k' => ['bail', 'sometimes', 'integer', 'min:1', 'max:200'],
            'sparse_top_k' => ['bail', 'sometimes', 'integer', 'min:1', 'max:200'],
            'rerank_candidates' => ['bail', 'sometimes', 'integer', 'min:20', 'max:30'],
            'rerank_retain' => ['bail', 'sometimes', 'integer', 'min:6', 'max:10'],

            // NO `sometimes` ON EITHER HALF, AND THE OMISSION IS LOAD-BEARING. `sometimes` removes
            // the whole rule set for an absent key, INCLUDING the implicit `required_with` — so a
            // body carrying only `evidence_threshold` would have the scale's rules skipped entirely
            // and the pairing check would never fire. Without it, an implicit rule still runs
            // against a missing attribute, which is exactly the behaviour the pair needs. `nullable`
            // is what keeps an absent (or explicitly null) field from failing the other rules.
            //
            // `min:`/`max:` PAIRS EVERYWHERE RATHER THAN `between:`, ON EVERY NUMERIC FIELD IN
            // THIS FILE. The two express the same constraint and only one of them is a name
            // `packages/contracts/test/form-drift.test.ts` can probe: that harness generates a
            // boundary case and an off-by-one for `min:` and `max:` and has no generator for
            // `between:`, so the `between:` spelling would have to be added to `UNPROBED_RULES` —
            // buying a rule no generated client schema is ever checked against. It is the same call
            // `UpdateProviderConnectionRequest` records for `min:1` over `filled`.
            //
            // `min:-100` / `max:100` IS AN ABSURDITY BOUND AND NOT A COLUMN BOUND. The column is
            // `double precision`; a logit is roughly ±10 in practice and a bounded score is 0-1, so
            // anything outside this is a typo or a unit confusion rather than a calibration. The
            // SCALE-DEPENDENT bound — [0, 1] on a bounded scale — is EvidenceThresholdWithinScale,
            // because no declarative rule can express "between 0 and 1 when a sibling equals X".
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

            'allow_general_answers' => ['bail', 'sometimes', 'boolean'],

            // `array:primary,accent,radius` CLOSES THE KEY SET, which is what makes an unknown key
            // a 422 instead of a value stored forever and rendered nowhere. The argument list is
            // built from ThemeVocabulary::KEYS rather than written out, so the rule, the DTO and
            // the constant cannot drift.
            'theme' => ['bail', 'sometimes', ThemeVocabulary::arrayRule()],
            'theme.primary' => ['bail', 'sometimes', 'string', 'max:64', new ReadableThemeColor],
            'theme.accent' => ['bail', 'sometimes', 'string', 'max:64', new ReadableThemeColor],
            'theme.radius' => ['bail', 'sometimes', 'string', Rule::in(ThemeVocabulary::RADII)],

            // NULL MEANS "THE PLATFORM DEFAULT APPLIES", which is a different fact from a
            // configured limit that happens to equal it — an operator asking whether somebody set
            // this has to be able to tell them apart. A limit of ZERO is not a limit, it is a bot
            // that answers nobody, and it is a plausible typo for "no limit"; `min:1` here and
            // `bots_rate_limits_positive` in the database both refuse it.
            'rate_limit_per_minute' => ['bail', 'nullable', 'integer', 'min:1', 'max:100000'],
            'rate_limit_per_day' => ['bail', 'nullable', 'integer', 'min:1', 'max:100000000'],
            // Ten years, which is an absurdity bound rather than a policy: retention beyond it is
            // indistinguishable from "keep forever", and that is spelled null.
            'retention_days' => ['bail', 'nullable', 'integer', 'min:1', 'max:3650'],

            'collect_end_user_data' => ['bail', 'sometimes', 'boolean'],
            // NO `required_if_accepted:collect_end_user_data` HERE, and its absence is deliberate.
            // The rule would be correct on this endpoint and WRONG on the PATCH, where enabling
            // collection on a bot that already carries a disclosure would be refused for a field
            // the caller had no reason to resend. One rule stated in two places, correct in one of
            // them, is how the two drift — so the whole check lives in `BotService`, evaluated
            // against the RESULTING row on both paths, and raised as a 422 keyed on this field.
            // `bots_consent_text_present_when_collecting` is the database's copy.
            'consent_text' => ['bail', 'nullable', 'string', 'max:2000'],
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
            'slug.regex' => 'A slug is lower-case letters, digits and internal hyphens — '
                .'`support-desk`, not `Support Desk` — and it may not start or end with a hyphen. '
                .'It is the handle an operator addresses this bot by in the console, and it is '
                .'unique within this organization only: another organization using the same handle '
                .'is not a conflict.',
            'provider_connection_id.required_with' => 'A model needs the connection it is '
                .'registered under. A `provider_models` row names a credential only through its '
                .'parent connection, so a bot naming a model with no connection names no '
                .'credential. A connection with no model is fine — that is a bot whose vendor is '
                .'chosen and whose model is not.',
            'evidence_threshold.required_with' => $pair,
            'evidence_threshold_scale.required_with' => $pair,
            'theme.array' => 'A theme carries `primary`, `accent` and `radius` and nothing else. '
                .'Every other custom property — the whole `-foreground` and accent-ramp family — is '
                .'DERIVED at render time and is never form-settable, because contrast is derived '
                .'and never chosen. A fourth key here would be a value stored forever and rendered '
                .'nowhere.',
            'theme.radius.in' => 'A radius is one of the six values the design tokens publish. The '
                .'renderer matches this string exactly against that set and drops anything else, so '
                .'an arbitrary length would be accepted here and silently ignored there.',
        ];
    }

    /**
     * The validated row, as a type.
     *
     * ABSENT OPTIONAL FIELDS FALL TO `NewBot`'s OWN DEFAULTS rather than being written as null,
     * which is what keeps "the caller said nothing" and "the caller cleared it" apart on the one
     * endpoint where they happen to mean the same thing. `NewBot` records why each default is
     * restated there rather than left to the column.
     */
    public function toData(): NewBot
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return new NewBot(
            name: $this->stringValue($data, 'name') ?? '',
            slug: $this->stringValue($data, 'slug') ?? '',
            description: $this->stringValue($data, 'description'),
            welcomeMessage: $this->stringValue($data, 'welcome_message'),
            placeholderText: $this->stringValue($data, 'placeholder_text'),
            systemInstruction: $this->stringValue($data, 'system_instruction'),
            answerStyleInstruction: $this->stringValue($data, 'answer_style_instruction'),
            accessMode: BotAccessMode::tryFrom((string) ($data['access_mode'] ?? '')) ?? BotAccessMode::Private,
            providerConnectionId: $this->stringValue($data, 'provider_connection_id'),
            providerModelId: $this->stringValue($data, 'provider_model_id'),
            answerMode: BotAnswerMode::tryFrom((string) ($data['answer_mode'] ?? '')) ?? BotAnswerMode::Strict,
            denseTopK: $this->intValue($data, 'dense_top_k') ?? 20,
            sparseTopK: $this->intValue($data, 'sparse_top_k') ?? 20,
            rerankCandidates: $this->intValue($data, 'rerank_candidates') ?? 20,
            rerankRetain: $this->intValue($data, 'rerank_retain') ?? 6,
            evidenceThreshold: isset($data['evidence_threshold']) && is_numeric($data['evidence_threshold'])
                ? (float) $data['evidence_threshold']
                : null,
            evidenceThresholdScale: EvidenceThresholdScale::tryFrom(
                (string) ($data['evidence_threshold_scale'] ?? ''),
            ),
            allowGeneralAnswers: (bool) ($data['allow_general_answers'] ?? false),
            theme: self::theme($data['theme'] ?? []),
            rateLimitPerMinute: $this->intValue($data, 'rate_limit_per_minute'),
            rateLimitPerDay: $this->intValue($data, 'rate_limit_per_day'),
            retentionDays: $this->intValue($data, 'retention_days'),
            collectEndUserData: (bool) ($data['collect_end_user_data'] ?? false),
            consentText: $this->stringValue($data, 'consent_text'),
        );
    }

    /**
     * The theme as a map of strings, which is the only shape `bots_theme_vocabulary` will hold.
     *
     * The rules have already closed the key set and required each present value to be a string, so
     * this is a NARROWING for the analyser rather than a second validation — but it is written as a
     * filter and not a cast, because a value that somehow arrived as a non-string would otherwise
     * reach `jsonb` as a number or a boolean and be refused by the CHECK at 500 rather than 422.
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

    /**
     * @param  array<string, mixed>  $data
     */
    private function stringValue(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function intValue(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
