<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Providers\ModelPricing;
use App\Services\Providers\NewProviderModelEntry;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Register one model under an existing provider connection.
 *
 * ── `supported` IS A FREE LIST OF STRINGS AND IS NOT VALIDATED AGAINST A CLOSED SET ───────────
 *
 * DELIBERATELY THE SAME CHOICE StoreProviderConnectionRequest MADE for its nested
 * `models.*.supported.*`, and the reason is not laziness. The capability vocabulary is the
 * `Capability` enum in services/ai-service/app/providers/contract.py, and the matrix that decides
 * whether a claimed flag is HONOURED is services/ai-service/app/providers/capabilities.py, which
 * carries a source per cell. Copying either here would be a second copy that drifts, and the
 * drifting copy is always the one that ships.
 *
 * WHAT THAT MEANS FOR THE OPERATOR STARING AT A LATER REFUSAL: an unknown or incoherent flag set
 * is accepted HERE and rejected by the data plane as a `row_incoherent` readiness rejection, by
 * name, with the matrix cell quoted — which is exactly what that reason exists for. Accepting a
 * flag here is not a statement that the row will be honoured; it is a statement that we do not
 * hold the fact that would decide.
 *
 * ── NO `exists:` AND NO `unique:` RULE, ON ANY FIELD ──────────────────────────────────────────
 *
 * The connection is identified by the ROUTE and resolved by a scoped binding through
 * `$organization->providerConnections()`, so a foreign id 404s before this class is constructed.
 * And the DUPLICATE-model-identifier check is an org-scoped repository query in
 * ProviderModelService, not a `unique:provider_models,model` rule — the reasoning is written out
 * in DesignateEmbeddingConnectionRequest and holds identically: a `unique:` rule queries the table
 * with NO organization predicate unless somebody remembers to add one, which is the exact shape of
 * Filament CVE-2026-48067, where the select query was tenant-scoped and the validation rule for
 * the same field was not. Spelling the scope into the rule string by hand
 * (`unique:provider_models,model,NULL,id,organization_id,{...}`) would work and would also be a
 * tenant predicate assembled from route input inside a string, which is the thing that goes wrong.
 *
 * ── FIELDS THAT ARE ABSENT ────────────────────────────────────────────────────────────────────
 *
 * `organization_id` and `provider_connection_id` — never validated, never posted, never in a DTO.
 * The first comes from the authenticated context and the second from the route. Over-posting a
 * tenant key is an authorization bug with a 200 response (laravel-rbac-policies NN5), and
 * `Model::shouldBeStrict()` turns the silent drop into an exception rather than a shrug.
 */
final class StoreProviderModelRequest extends FormRequest
{
    /**
     * Authorization is Gate::authorize() in the controller, not here. FormRequest::authorize()
     * runs BEFORE validation, so a policy call placed in it decides on unvalidated input — and it
     * cannot reach checks 5 and 6 (entity status, rate limit) at all.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * ── `enabled` IS WRITABLE HERE AND IS NOT ON THE NESTED CREATE PATH ───────────────────────
     *
     * `StoreProviderConnectionRequest` has no rule for it and `attachModel()` hard-codes `true`,
     * because bootstrapping a connection with rows nobody can use is not a state anyone wants.
     * Registering a row on its own endpoint is the opposite case: an operator adding a model they
     * are not ready to expose to bots yet is the normal reason to use this endpoint twice. It is
     * `sometimes` rather than `required` so the common call stays short, and the DTO defaults it to
     * `true` — the same value the nested path always writes, so the two paths agree when neither
     * says otherwise.
     *
     * ── PRICING: `nullable` EVERYWHERE, `required_with` IN ONE DIRECTION ONLY ─────────────────
     *
     * The one-directional rule mirrors the `provider_models_price_needs_currency` CHECK exactly. A
     * price with no currency is not an amount — two organizations billed in different currencies
     * would store the same number and a spend report would add them — so a price REQUIRES a
     * currency. A currency with no prices is permitted, because that is the state of a row whose
     * operator recorded the vendor's billing currency before looking the prices up, and refusing it
     * would make the form unfillable in the order a human fills it.
     *
     * `decimal:0,6` bounds the FRACTIONAL DIGITS to what `numeric(14, 6)` stores. Without it,
     * `0.1234567` is accepted here and silently ROUNDED by PostgreSQL, so the value the operator
     * typed is not the value the report uses and nothing ever says so.
     *
     * `max:1000000` is an ABSURDITY bound and not a column bound, and the two are deliberately not
     * flush: the column reaches 99,999,999.999999, so the largest value this rule admits is still
     * comfortably inside it. Sizing the column to the rule would make the boundary value pass
     * validation and then raise SQLSTATE 22003 from the driver, rendered as a 500 — a bug report
     * about the server for a value the form said was fine. A refusal belongs where there is a field
     * to key it on.
     *
     * `regex` on the currency, and NOT a closed `Rule::in` over an ISO 4217 list: that list changes,
     * and pinning it here would make a new currency a code change. The database CHECK carries the
     * same shape rule for every writer that is not an HTTP request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The OFFICIAL model identifier, exactly as the vendor publishes it. It is half of the
            // vector-space identity for everything embedded through this row (ADR-034), which is
            // why it can be created and deleted but never edited — see ProviderModelEdit.
            'model' => ['bail', 'required', 'string', 'max:200'],
            'display_name' => ['bail', 'required', 'string', 'max:200'],

            // `present` and not `required`: an empty list is a legitimate, meaningful state — "this
            // row claims nothing yet" — and `required` rejects `[]`.
            'supported' => ['present', 'array', 'max:20'],
            // A CAPABILITY FLAG IS A LOWER-CASE IDENTIFIER, AND THE CHARACTER CLASS IS A
            // SECURITY RULE RATHER THAN TIDINESS. The value is joined into `capabilities` and
            // ECHOED into `provider.model.*` audit rows, which AuditLogger's own docblock calls
            // the security-relevant field of those operations — an `embedding` flag decides which
            // credential embeds the corpus. `sanitize()`'s shape backstop fires on anything
            // `KbJsonFormatter::redactValue()` recognises, so `supported: ["embedding",
            // "sk-aaaaaaaaaaaa"]` used to make the whole field unreadable in an APPEND-ONLY table
            // — a tenant blanking the field that identifies what they changed, from a form.
            //
            // The same string also crosses the internal seam as a `capability_flags` member, so
            // constraining it here keeps arbitrary text out of the data plane's row axis too.
            //
            // NOT A CLOSED `Rule::in` LIST. The capability matrix that matters is
            // services/ai-service/app/providers/capabilities.py, with a source per cell; a
            // vocabulary here would be a second copy, and the drifting copy is always the one that
            // ships. A CHARACTER CLASS constrains the shape without claiming to know the words.
            'supported.*' => ['bail', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],

            'context_window' => ['bail', 'required', 'integer', 'min:0', 'max:100000000'],
            'max_output_tokens' => ['bail', 'required', 'integer', 'min:0', 'max:100000000'],

            'enabled' => ['bail', 'sometimes', 'boolean'],

            'input_price_per_million' => [
                'bail', 'nullable', 'numeric', 'decimal:0,6', 'min:0', 'max:1000000',
            ],
            'output_price_per_million' => [
                'bail', 'nullable', 'numeric', 'decimal:0,6', 'min:0', 'max:1000000',
            ],
            'price_currency' => [
                'bail',
                'nullable',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
                // ONE DIRECTION ONLY, and it is the direction the database CHECK enforces: a price
                // requires a currency. `required_with` fires when ANY of the named attributes is
                // present, which is exactly the `num_nonnulls(...) = 0` half of the constraint.
                'required_with:input_price_per_million,output_price_per_million',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'supported.*.regex' => 'A capability flag is a lower-case identifier — letters, '
                .'digits and underscores, starting with a letter (`embedding`, `tool_use`). It is '
                .'echoed into an append-only audit row and crosses the internal API, so arbitrary '
                .'text is refused here rather than sanitized later.',
            'price_currency.required_with' => 'A price needs a currency. Without one, an '
                .'organization billed in EUR and one billed in USD store the same number and a '
                .'spend estimate adds them together.',
            'price_currency.regex' => 'A currency is three upper-case letters, ISO 4217 style '
                .'(USD, EUR, GBP).',
        ];
    }

    /**
     * The validated row, as a type.
     */
    public function toData(): NewProviderModelEntry
    {
        /** @var array{model: string, display_name: string, supported: array<int, string>, context_window: int, max_output_tokens: int, enabled?: bool, input_price_per_million?: string|float|int|null, output_price_per_million?: string|float|int|null, price_currency?: string|null} $data */
        $data = $this->validated();

        return new NewProviderModelEntry(
            model: $data['model'],
            displayName: $data['display_name'],
            supported: array_values($data['supported']),
            contextWindow: $data['context_window'],
            maxOutputTokens: $data['max_output_tokens'],
            // The nested create path's value when nobody says otherwise, so the two agree.
            enabled: $data['enabled'] ?? true,
            pricing: new ModelPricing(
                inputPerMillion: self::decimal($data['input_price_per_million'] ?? null),
                outputPerMillion: self::decimal($data['output_price_per_million'] ?? null),
                currency: $data['price_currency'] ?? null,
            ),
        );
    }

    /**
     * A price as an exact DECIMAL STRING, never a float.
     *
     * `numeric` validation accepts `15.5` as a JSON number, which arrives in PHP as a float — and
     * `0.15` has no exact binary representation, so a value that survives the round trip through a
     * float is a value nobody can reproduce. Casting to string HERE, at the boundary, means the
     * literal reaches `numeric(14, 6)` unrounded and every layer below holds a string.
     *
     * `(string) 1.0E-5` renders as `1.0E-5`, which PostgreSQL DOES accept as a numeric literal, so
     * exponent notation is not a hazard — and `decimal:0,6` has already refused anything with more
     * than six fractional digits, which is the only shape that could round.
     */
    private static function decimal(string|float|int|null $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
