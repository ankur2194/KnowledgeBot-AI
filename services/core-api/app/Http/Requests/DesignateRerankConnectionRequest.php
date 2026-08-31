<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Rerank\RerankDesignation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Which connection and model rerank this organization's retrieval candidates.
 *
 * ── WHAT THIS FORM DELIBERATELY DOES NOT VALIDATE ──────────────────────────────────────────────
 *
 * It does NOT check that `connection_id` belongs to this organization, and it does NOT check that
 * the pair can rerank. Both are handled, and neither belongs here:
 *
 *   - Ownership is enforced by an ORG-SCOPED READ in RerankDesignationService, by the catalog
 *     re-verification inside the repository's transaction, and underneath both by the composite
 *     foreign key on the write. An `exists:` rule would be a THIRD check that queries
 *     `provider_connections` with no organization predicate unless somebody remembers to add one —
 *     which is exactly the shape of Filament CVE-2026-48067, where the select query was
 *     tenant-scoped and the validation rule for the same field was not.
 *   - Capability is the data plane's, decided by `capabilities.can_rerank` from three axes, and
 *     asking any part of it here would be a second implementation of a rule that exists to have one
 *     copy. See RerankDesignationService for the whole argument.
 *
 * `organization_id` appears in no rule and in no DTO. Over-posting a tenant key is an authorization
 * bug with a 200 response (laravel-rbac-policies NN5).
 */
final class DesignateRerankConnectionRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Both nullable, because turning reranking OFF is the most common legitimate action on
            // this endpoint and is expressed as {"connection_id": null, "model": null} rather than
            // as a second endpoint or a DELETE. Null is a supported operating mode here, not an
            // unconfigured one: stage 11 does not run and fused order is served.
            //
            // `present` on both, so a body that omits a key is a 422 rather than a silent partial
            // update — this is a PUT of a complete pair, and "the field was missing" and "the field
            // was null" mean opposite things on it.
            //
            // `required_with` in BOTH directions: half a designation names either no credential or
            // no reranker, and a half-designation reaches `rerank_gate` as `model = null`, i.e. as
            // "reranking is off" — the operator's choice discarded with no error anywhere. The
            // database CHECK num_nonnulls(...) <> 1 is the same rule one layer down.
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
            'model.required_with' => 'A rerank designation names a connection AND a model: one '
                .'connection can carry two ranking models, and their scores are two different '
                .'distributions with two different thresholds.',
            'connection_id.required_with' => 'A rerank designation names a connection AND a model: '
                .'a model with no connection names no credential.',
        ];
    }

    /**
     * The validated pair, or null when the organization is turning reranking off.
     */
    public function designation(): ?RerankDesignation
    {
        /** @var string|null $connectionId */
        $connectionId = $this->validated('connection_id');
        /** @var string|null $model */
        $model = $this->validated('model');

        if ($connectionId === null || $model === null) {
            return null;
        }

        return new RerankDesignation($connectionId, $model);
    }
}
