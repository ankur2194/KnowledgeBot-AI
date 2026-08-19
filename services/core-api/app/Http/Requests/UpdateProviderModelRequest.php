<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Providers\ModelPricing;
use App\Services\Providers\ProviderModelEdit;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Replace a catalog row's mutable attributes.
 *
 * ── A PUT AND NOT A PATCH, AND THE REASON IS THE MANIFEST ─────────────────────────────────────
 *
 * `UpdateProviderConnectionRequest` is a PATCH over TWO fields and expresses "a body that changes
 * neither is refused" as `required_without` in both directions — one extra rule per field, which
 * is readable and, crucially, VISIBLE to `kb:dump-form-rules`. This row has SEVEN mutable
 * attributes. The same construction is `required_without_all` naming six siblings on each of seven
 * fields: forty-two rule fragments encoding one sentence, which nobody will keep correct through
 * the eighth field.
 *
 * The alternatives were both worse. `sometimes` on every field permits an empty body, which writes
 * nothing, returns 200 and leaves a `provider.model.updated` audit row describing an edit that did
 * not happen — the trail lying in the one direction nobody checks. A `withValidator`/`after()`
 * closure expresses it correctly and is INVISIBLE to the manifest in packages/contracts/rules/,
 * which is dumped from executing `rules()` and nothing else — so the generated client would never
 * be told the constraint exists (docs/22 finding 19).
 *
 * So the endpoint states the COMPLETE desired state and every field is required. A body is never
 * empty, "changed nothing" is a legitimate no-op the operator explicitly asked for, and every rule
 * is one line that the manifest can see. It also matches how the row is used: a client PUTs back
 * the object it just read from `show`, exactly as `PUT /embedding-configuration` does with its
 * pair.
 *
 * ── `model` IS NOT A FIELD HERE, AND THAT IS THE POINT ────────────────────────────────────────
 *
 * The official model identifier is immutable. It is half of the vector-space identity for
 * everything already embedded through this row (ADR-034), and `organizations.embedding_model`
 * stores it as a bare `text` column with NO foreign key — so nothing in the database would follow
 * a rename, and the designation would be left naming a row that no longer exists. The omission is
 * defended in three places rather than one: there is no rule here, `ProviderModelEdit` has no
 * member to hold one, and `EloquentProviderModelRepository::update()` never assigns the column. A
 * mistyped identifier is DELETED and re-created — and that path is guarded against exactly the
 * designation case a rename would have walked straight through.
 *
 * ── FIELDS THAT ARE ABSENT FOR THE USUAL REASON ───────────────────────────────────────────────
 *
 * `organization_id` and `provider_connection_id` are never validated, never posted and never in a
 * DTO. The first comes from the authenticated context, the second from the route's scoped binding.
 * Over-posting a tenant key is an authorization bug with a 200 response (laravel-rbac-policies
 * NN5).
 *
 * ── NO `exists:` RULE, ON ANY FIELD ───────────────────────────────────────────────────────────
 *
 * The row is identified by the ROUTE and resolved by a scoped binding through
 * `$providerConnection->models()`, so a foreign id 404s before this class is constructed. The
 * argument against reaching for `exists:` on a tenant-owned column is written out in
 * DesignateEmbeddingConnectionRequest and is the same here.
 */
final class UpdateProviderModelRequest extends FormRequest
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
     * Every field required, because the endpoint replaces the whole mutable state — see the class
     * docblock. `supported` and the three pricing fields are `present` rather than `required`
     * because their legitimate value is empty or null: "this row claims nothing" and "no price
     * recorded" are states an operator has to be able to SET, and `required` rejects both.
     *
     * `enabled` IS `required` here and only `sometimes` on the create path. Toggling a row off is
     * one of the two things this endpoint exists for, and a PUT that silently left it at whatever
     * it was would make the replacement partial in exactly the field an operator most often means
     * to change.
     *
     * The pricing rules are identical to StoreProviderModelRequest's, including the one-directional
     * `required_with` that mirrors `provider_models_price_needs_currency`. They are repeated rather
     * than shared through a trait: `kb:dump-form-rules` dumps each FormRequest's own manifest, and
     * two endpoints that must agree are better proved by two manifests a reviewer can diff than by
     * a shared method that makes the agreement invisible.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['bail', 'required', 'string', 'max:200'],

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

            'enabled' => ['bail', 'required', 'boolean'],

            'input_price_per_million' => [
                'bail', 'present', 'nullable', 'numeric', 'decimal:0,6', 'min:0', 'max:1000000',
            ],
            'output_price_per_million' => [
                'bail', 'present', 'nullable', 'numeric', 'decimal:0,6', 'min:0', 'max:1000000',
            ],
            'price_currency' => [
                'bail',
                'present',
                'nullable',
                'string',
                'size:3',
                'regex:/^[A-Z]{3}$/',
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
     * The validated edit, as a type. Every member is total — there is no "not supplied" state on a
     * PUT, so no member can be null-meaning-absent and no reader has to guess which null it is.
     */
    public function toData(): ProviderModelEdit
    {
        /** @var array{display_name: string, supported: array<int, string>, context_window: int, max_output_tokens: int, enabled: bool, input_price_per_million: string|float|int|null, output_price_per_million: string|float|int|null, price_currency: string|null} $data */
        $data = $this->validated();

        return new ProviderModelEdit(
            displayName: $data['display_name'],
            supported: array_values($data['supported']),
            contextWindow: $data['context_window'],
            maxOutputTokens: $data['max_output_tokens'],
            enabled: $data['enabled'],
            pricing: new ModelPricing(
                inputPerMillion: self::decimal($data['input_price_per_million']),
                outputPerMillion: self::decimal($data['output_price_per_million']),
                currency: $data['price_currency'],
            ),
        );
    }

    /**
     * A price as an exact DECIMAL STRING, never a float — same reason, same words, as
     * StoreProviderModelRequest::decimal(). `0.15` has no exact binary representation, so a value
     * that survives a round trip through a float is a value nobody can reproduce, and an estimate
     * summed over a month drifts by an amount nobody can explain.
     */
    private static function decimal(string|float|int|null $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
