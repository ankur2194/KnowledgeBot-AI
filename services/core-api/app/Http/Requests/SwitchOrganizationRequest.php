<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Which organization the admin console should be pointed at next.
 *
 * ── THERE IS NO `exists:organizations,id` RULE, AND ITS ABSENCE IS THE WHOLE DESIGN ─────────────
 *
 * An `exists:` rule here would issue an UNSCOPED query against `organizations` on behalf of any
 * authenticated user, and its two outcomes are distinguishable from the outside: a 422 means "no such
 * organization", anything else means "it exists". That is a global org-existence oracle for every
 * ULID an attacker cares to try, delivered by the validation layer — the same shape as Filament
 * CVE-2026-48067, where the select query was tenant-scoped and the validation rule for the same field
 * was not.
 *
 * MEMBERSHIP IS THE CHECK, and it is made in the controller against `organization_users`, which
 * cannot answer a question about an organization the caller does not belong to. A non-existent
 * organization and one the caller is not a member of therefore produce the SAME 403 with the SAME
 * body, so this endpoint discloses nothing about the organization table.
 *
 * `ulid` IS A FORMAT RULE, NOT AN EXISTENCE RULE. It rejects a value the identifier column
 * (`char(26) COLLATE "C"`) could not hold anyway, which keeps a 300-character body out of an indexed
 * comparison. It leaks nothing: every well-formed ULID gets the same 403 as every other.
 *
 * `organization_id` IS THE ONLY FIELD. No `role`, no `status`, nothing else — switching the console's
 * pointer is not a place to accept a claim about what the caller may do once it is pointed.
 */
final class SwitchOrganizationRequest extends FormRequest
{
    /**
     * Authorization is in the controller, not here. FormRequest::authorize() runs BEFORE validation,
     * so a membership check placed in it would decide on an unvalidated identifier — and it would
     * render as a 403 for a malformed body, which is the wrong class.
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
            'organization_id' => ['bail', 'required', 'string', 'ulid'],
        ];
    }

    public function organizationId(): string
    {
        return $this->safe()->string('organization_id')->value();
    }
}
