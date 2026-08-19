<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\ProviderConnectionStatus;
use App\Services\Providers\ProviderConnectionEdit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edit a stored connection: its operator-visible LABEL and its lifecycle STATUS. Nothing else.
 *
 * ── THE FIELDS THAT ARE ABSENT, AND WHY EACH ONE IS ABSENT ─────────────────────────────────────
 *
 * `credential` — THIS ENDPOINT MAY NEVER ACCEPT A CREDENTIAL, and the omission is defended in
 * three places rather than one, because a missing validation rule is one careless line away from
 * coming back. There is no rule here; `toData()` returns App\Services\Providers\
 * ProviderConnectionEdit, which has no member to put a key in; and
 * ProviderConnectionService::update() never touches the vault. Replacing a key is a different
 * verb (`PUT …/provider-connections/{id}/credential`), a different DTO, a different audit
 * operation, and a §18.3 re-authentication — merging the two would mean a relabel and a
 * credential replacement shared one re-authentication policy, and the weaker one would win.
 *
 * `provider` — the vendor is not editable. It is half of the vector-space identity for every
 * chunk already embedded through this connection (ADR-034), and the composite foreign keys on
 * `provider_models` and on `organizations.embedding_connection_id` would happily follow a change
 * that silently re-pointed a live corpus at another vendor's account.
 *
 * `organization_id` — never validated, never posted, never in a DTO. Over-posting a tenant key is
 * an authorization bug with a 200 response (laravel-rbac-policies NN5), and `Model::shouldBeStrict()`
 * turns the silent drop into an exception rather than a shrug.
 *
 * `last_four`, `key_version`, `credential_version` — derived from the sealed key and written only
 * by the vault path. A client that could set `last_four` could make the console display a mask
 * belonging to a key that was never stored.
 *
 * ── NO `exists:` RULE, ON ANY FIELD ────────────────────────────────────────────────────────────
 *
 * The connection is identified by the ROUTE, not by the body, and it is resolved by a scoped
 * binding through `$organization->providerConnections()` — so a foreign id 404s before this class
 * is constructed. The reasoning against reaching for `exists:` on a tenant-owned column is written
 * out in DesignateEmbeddingConnectionRequest and is the same here: an `exists:` rule queries the
 * table with NO organization predicate unless somebody remembers to add one, which is the exact
 * shape of Filament CVE-2026-48067, where the select query was tenant-scoped and the validation
 * rule for the same field was not.
 */
final class UpdateProviderConnectionRequest extends FormRequest
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
     * ── WHY `required_without` IN BOTH DIRECTIONS RATHER THAN `sometimes` PLUS A HOOK ───────────
     *
     * The endpoint is a PATCH, so either field alone is a legitimate body — but a body carrying
     * NEITHER is not. It would write nothing, return 200, and leave a
     * `provider.connection.updated` audit row describing an edit that did not happen, which makes
     * the trail lie in the one direction nobody checks.
     *
     * `sometimes` cannot express that: it suppresses the whole rule set for an absent key, so a
     * body with neither field runs neither rule and passes. The alternative was a `withValidator`
     * or `after()` closure, which works but is INVISIBLE TO `kb:dump-form-rules` — the manifest in
     * packages/contracts/rules/ is dumped from executing `rules()` and nothing else, so a
     * constraint expressed in a hook is a constraint the generated client is never told about.
     * `required_without` in both directions is the same rule, expressed where the contract can see
     * it.
     *
     * Note what the mutual pair does NOT do: with both fields present, each one's `required_without`
     * is satisfied by the other's presence and both are then validated normally by the rules that
     * follow. With one present, the absent field's remaining rules are skipped, because a
     * non-implicit rule does not run against an attribute that is not there.
     *
     * `status` accepts the FULL enum, from `ProviderConnectionStatus::values()` and never from a
     * literal — including `invalid`. Narrowing the operator-assignable set to `active`/`revoked`
     * was considered and rejected as security theatre: the only thing it blocks is an operator
     * MARKING a working key invalid, while the transition that actually matters — `invalid` back
     * to `active`, asserting a credential is healthy without the controlled connection check ever
     * having run — is permitted by both spellings. The status column is not evidence of a test; it
     * is the operator's declared intent, and `last_tested_at` / `last_test_status` are the columns
     * that carry evidence.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `min:1`, AND ITS ABSENCE WAS A REAL HOLE RATHER THAN LAXITY.
            //
            // `required_without:status` is satisfied by `status` being PRESENT, and `string` and
            // `max:120` both pass on the empty string — so `{"label": "", "status": "active"}` was
            // accepted and blanked the label. `StoreProviderConnectionRequest` makes the same field
            // `required`, so the two endpoints disagreed about whether a connection may be nameless.
            //
            // WHAT IT COSTS IS AN AUDIT ROW, WHICH IS WHY IT IS HERE AND NOT A UX PREFERENCE.
            // `label` is ECHOED into `provider.connection.updated` AND into every later
            // `provider.connection.deleted` row for the same connection — and a delete is a HARD
            // delete, so that row is the only surviving description of what was removed and its
            // `subject_id` resolves to nothing afterwards. `AuditLogger::sanitize()` skips an empty
            // string SILENTLY (deliberately: `capabilities` legitimately renders as `''` for a model
            // row that claims no flags, and reporting that would warn on every unflagged write), so
            // the field simply vanished from an append-only table with no WARNING anywhere.
            //
            // `min:1` AND NOT `filled`, and the choice is not cosmetic. Both express the rule; only
            // `min:` is a name packages/contracts/test/form-drift.test.ts can probe. That harness
            // asserts every rule NAME in every dumped manifest is either probed or explicitly
            // recorded as unprobeable, so `filled` would fail the contracts suite by name — and
            // adding it to the unprobed list would buy a rule no client schema is ever checked
            // against, which is the opposite of what the manifest is for. `min:1` generates both of
            // its probes (a one-character label accepted, the empty string rejected) and holds the
            // Zod mirror to the same line.
            'label' => ['bail', 'required_without:status', 'string', 'min:1', 'max:120'],
            'status' => [
                'bail',
                'required_without:label',
                'string',
                Rule::in(ProviderConnectionStatus::values()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $both = 'An edit names a label, a status, or both. A request that changes neither would '
            .'write an audit row describing an edit that did not happen.';

        return [
            'label.required_without' => $both,
            'status.required_without' => $both,
            'label.min' => 'A connection needs a name. The label is the only thing that identifies '
                .'it in the audit trail after it is deleted, so it cannot be blanked.',
        ];
    }

    /**
     * The validated edit, as a type.
     *
     * A null member means THE FIELD WAS NOT SUPPLIED, never "set it to null": both columns are
     * NOT NULL in the schema, so neither can be cleared, and `rules()` refuses a body that
     * supplies neither — so an all-null ProviderConnectionEdit is unconstructible from a request.
     */
    public function toData(): ProviderConnectionEdit
    {
        /** @var array{label?: string, status?: string} $data */
        $data = $this->validated();

        return new ProviderConnectionEdit(
            label: array_key_exists('label', $data) ? $data['label'] : null,
            status: array_key_exists('status', $data)
                ? ProviderConnectionStatus::from($data['status'])
                : null,
        );
    }
}
