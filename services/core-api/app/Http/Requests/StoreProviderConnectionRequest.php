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
            'credential' => ['bail', 'required', 'string', 'min:8', 'max:512'],

            'models' => ['present', 'array', 'max:50'],
            'models.*.model' => ['bail', 'required', 'string', 'max:200'],
            'models.*.display_name' => ['bail', 'required', 'string', 'max:200'],
            'models.*.supported' => ['present', 'array', 'max:20'],
            'models.*.supported.*' => ['string', 'max:64'],
            'models.*.context_window' => ['required', 'integer', 'min:0', 'max:100000000'],
            'models.*.max_output_tokens' => ['required', 'integer', 'min:0', 'max:100000000'],
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
