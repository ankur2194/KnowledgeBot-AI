<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Embedding\EmbeddingDesignation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Which connection and model supply this organization's embedding credential.
 *
 * ── WHAT THIS FORM DELIBERATELY DOES NOT VALIDATE ──────────────────────────────────────────────
 *
 * It does NOT check that `connection_id` belongs to this organization, and it does NOT check that
 * the pair can embed. Both are checked, and neither belongs here:
 *
 *   - Ownership is enforced by the ORG-SCOPED CANDIDATE QUERY (a foreign connection is simply
 *     absent from the set that reaches the resolver) and, underneath it, by the composite foreign
 *     key on the write. An `exists:` rule would be a third check that queries `provider_connections`
 *     with no organization predicate unless somebody remembers to add one — which is exactly the
 *     shape of Filament CVE-2026-48067, where the select query was tenant-scoped and the
 *     validation rule for the same field was not.
 *   - Capability is the resolver's, and asking it here would be the second implementation of the
 *     rule that embedding_selection.py exists to be the only copy of.
 *
 * `organization_id` appears in no rule and in no DTO. Over-posting a tenant key is an
 * authorization bug with a 200 response (laravel-rbac-policies NN5).
 */
final class DesignateEmbeddingConnectionRequest extends FormRequest
{
    /**
     * Authorization is Gate::authorize() in the controller, not here. FormRequest::authorize()
     * runs BEFORE validation, so a policy call placed in it decides on unvalidated input — and it
     * cannot reach checks 5 and 6 (entity status, quota) at all.
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
            // Both nullable, because clearing the designation is a legitimate action expressed as
            // {"connection_id": null, "model": null} rather than as a second endpoint.
            // `required_with` in BOTH directions: half a designation names either no credential or
            // no vector space, and a half-designation would be discovered at the first upload —
            // the late discovery this whole finding exists to remove. The database CHECK
            // num_nonnulls(...) <> 1 is the same rule one layer down.
            'connection_id' => ['present', 'nullable', 'string', 'ulid', 'required_with:model'],
            'model' => ['present', 'nullable', 'string', 'max:200', 'required_with:connection_id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'model.required_with' => 'An embedding designation names a connection AND a model: one '
                .'connection can carry two embedding models, and those are two different vector '
                .'spaces.',
            'connection_id.required_with' => 'An embedding designation names a connection AND a '
                .'model: a model with no connection names no credential.',
        ];
    }

    /**
     * The validated pair, or null when the organization is clearing its designation.
     */
    public function designation(): ?EmbeddingDesignation
    {
        /** @var string|null $connectionId */
        $connectionId = $this->validated('connection_id');
        /** @var string|null $model */
        $model = $this->validated('model');

        if ($connectionId === null || $model === null) {
            return null;
        }

        return new EmbeddingDesignation($connectionId, $model);
    }
}
