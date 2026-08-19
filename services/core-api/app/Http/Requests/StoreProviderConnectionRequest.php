<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Provider;
use App\Services\Providers\NewProviderConnection;
use App\Services\Providers\NewProviderModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Store one provider connection and the model rows under it.
 *
 * `models` MAY CONTAIN ONLY EMBEDDING ROWS, and there is deliberately no rule requiring a chat
 * model. With one sourced embedding vendor, a connection configured purely to embed is the only
 * way an organization on any other vendor can ingest a document at all (finding C1, item 3).
 *
 * `models.*.supported` is a free list of capability flag strings and is NOT validated against a
 * local capability matrix. The matrix that matters is
 * services/ai-service/app/providers/capabilities.py, which carries a source per cell; duplicating
 * any part of it here would be a second copy that drifts, and the drifting copy is always the one
 * that ships.
 *
 * WHAT THAT MEANS FOR THE OPERATOR STARING AT A 422: `can_embed` is the AND of two axes, but
 * `can_rerank` is the AND of THREE, and `assert_row_coherent` refuses on any of them — the vendor
 * publishing no such endpoint; the vendor publishing it with a score scale this platform cannot
 * threshold (that is `openrouter` + `rerank`, refused today at capabilities.py:724, and no amount
 * of vendor documentation predicts it); or a row claiming embedding and rerank at once, which are
 * task-exclusive. Each comes back by name with the matrix cell quoted. So accepting a flag here
 * is not a statement that the row will save.
 *
 * `organization_id` appears nowhere: it comes from the authenticated context at the call site.
 */
final class StoreProviderConnectionRequest extends FormRequest
{
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
            'provider' => ['bail', 'required', 'string', Rule::in(Provider::values())],
            'label' => ['bail', 'required', 'string', 'max:120'],

            // The plaintext credential. It is validated for SHAPE only — a length floor and a
            // charset, never a vendor-specific prefix regex, because a rule that rejects anything
            // not matching `sk-...` breaks the day a vendor changes its key format and reads as
            // "our key is invalid" to a tenant whose key is fine.
            //
            // It never appears in any error message: `dontFlash` in bootstrap/app.php lists
            // `api_key`, `secret` and `provider_credential`, and this field is added there too.
            //
            // THE MASK GUARD IS HERE TOO, AND ITS ABSENCE WAS A REAL HOLE RATHER THAN A HARMLESS
            // ASYMMETRY. ProviderConnectionResource renders `masked_key` as `…4a91`, and a console
            // form seeded from a resource it just fetched posts that display string back —
            // `reset({...connection})` keeps every key it is handed. On the rotation path a
            // `not_regex` refuses it; on THIS path nothing did, and the value was rejected only by
            // coincidence, because `'…'.$last_four` happens to be five characters and `min:8` is
            // eight. That coincidence is one column change away from evaporating, and when it does
            // the organization's key becomes the literal text `…4a91`, the request is a 201, and
            // the first chat turn fails with `provider_auth` against something nothing explains.
            //
            // BEFORE THE LENGTH BOUNDS, for the reason RotateProviderCredentialRequest records at
            // length: under `bail` a guard placed after `min:8` never runs against the very value
            // it exists to refuse.
            //
            // ProviderConnectionResource::openApiSchemas() says posting the mask back into "a
            // create or rotate request" would set the tenant's key to the literal text. This is
            // what makes the first half of that sentence true.
            'credential' => [
                'bail',
                'required',
                'string',
                'not_regex:/^\x{2026}/u',
                'min:8',
                'max:512',
            ],

            'models' => ['present', 'array', 'max:50'],
            'models.*.model' => ['bail', 'required', 'string', 'max:200'],
            'models.*.display_name' => ['bail', 'required', 'string', 'max:200'],
            'models.*.supported' => ['present', 'array', 'max:20'],
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
            'models.*.supported.*' => ['bail', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'models.*.context_window' => ['required', 'integer', 'min:0', 'max:100000000'],
            'models.*.max_output_tokens' => ['required', 'integer', 'min:0', 'max:100000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // Byte-identical to RotateProviderCredentialRequest's. Two endpoints that must refuse
            // the same value should say the same sentence, whichever one the operator reached.
            'credential.not_regex' => 'That looks like the masked display value (`…` followed by '
                .'the last four characters), not a credential. The mask cannot authenticate '
                .'anything; paste the full key from the provider.',
            'models.*.supported.*.regex' => 'A capability flag is a lower-case identifier — '
                .'letters, digits and underscores, starting with a letter (`embedding`, '
                .'`tool_use`). It is echoed into an append-only audit row and crosses the internal '
                .'API, so arbitrary text is refused here rather than sanitized later.',
        ];
    }

    public function toData(): NewProviderConnection
    {
        /** @var array{provider: string, label: string, credential: string, models: array<int, array{model: string, display_name: string, supported: array<int, string>, context_window: int, max_output_tokens: int}>} $data */
        $data = $this->validated();

        return new NewProviderConnection(
            provider: Provider::from($data['provider']),
            label: $data['label'],
            credential: $data['credential'],
            models: array_map(
                static fn (array $model): NewProviderModel => new NewProviderModel(
                    model: $model['model'],
                    displayName: $model['display_name'],
                    supported: array_values($model['supported']),
                    contextWindow: $model['context_window'],
                    maxOutputTokens: $model['max_output_tokens'],
                ),
                array_values($data['models']),
            ),
        );
    }
}
