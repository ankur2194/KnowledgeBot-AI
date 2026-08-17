<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrgRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Invite somebody into THIS organization.
 *
 * ── THE NORMALISATION IS MANDATORY AND INVISIBLE IN THE GENERATED MANIFEST ──────────────────────
 *
 * `prepareForValidation()` lowercases and trims `email`, for the same reason LoginRequest does and
 * with one extra consequence here: `organization_invitations_email_lowercase` is a database CHECK, so
 * a mixed-case address would not merely be hard to look up — the INSERT would fail with a constraint
 * name, and the admin would see a 500 for typing `Bob@x.com`. It is not a `lowercase` VALIDATION rule
 * because that rule REJECTS a mixed-case address instead of accepting it, turning a normalisation
 * into a 422 the person filling the form cannot act on.
 *
 * `kb:dump-form-rules` records only `rules()`, so `packages/contracts/rules/StoreInvitationRequest.json`
 * cannot express any of the above.
 *
 * THE ZOD MIRROR TRIMS AND DELIBERATELY DOES NOT LOWERCASE, and this sentence used to ask for both.
 * It lost, and the reasoning is at `packages/contracts/src/forms/auth.ts`'s `emailField`: normalisation
 * is not validation. The server lowercases UNCONDITIONALLY here, so a mixed-case address submitted
 * verbatim already works, and lowercasing client-side would only mean the user watches their own input
 * rewrite itself as they type. Trimming IS mirrored, because `max:254` is checked after `TrimStrings`
 * server-side and a client that checked length first would reject an address this request accepts.
 * The instruction is corrected rather than deleted because a docblock asking for the losing behaviour
 * is one somebody will implement.
 *
 * ── `role` IS VALIDATED AGAINST THE ENUM, AND VALIDATION IS NOT AUTHORIZATION ───────────────────
 *
 * `Rule::in(OrgRole::values())` says the value NAMES a role. It says nothing about whether this
 * caller may GRANT it, and it deliberately cannot: `owner` is a legal value in this rule set and is
 * refused by a second `Gate::authorize('inviteOwner', $organization)` in the controller, mapped to
 * Permission::MembersManageOwner. Encoding "not owner" as a validation rule instead would render
 * privilege escalation as a 422 `validation` — a class that tells the client to fix its input — when
 * the correct answer is 403 `authorization`.
 *
 * The list is derived from the enum rather than written out, so a new role cannot be invitable-by-
 * omission or forgotten. `Rule::in` stringifies as `in:"owner","admin",…`, which
 * `packages/contracts/test/form-drift.test.ts` already un-quotes when it probes members.
 *
 * ── WHAT IS DELIBERATELY ABSENT ────────────────────────────────────────────────────────────────
 *
 * NO `organization_id`. It is a URL segment resolved by route binding under `{organization}`; a body
 * field would be a tenant key a caller can set, which is an authorization bug with a 201 response.
 *
 * NO `expires_at`. The TTL comes from `config('kb.invitation_ttl_hours')`. A caller-chosen expiry is
 * a caller-chosen validity window for a bearer capability.
 *
 * NO `exists:` OR `unique:` RULE ON `email`. "Already a member" and "already invited" are both real
 * refusals and both are 422s — but they are decided in the service against THIS organization, and a
 * `unique:users,email` rule would instead answer a question about the global user table. The
 * disclosure an org administrator does get is bounded to their own organization, which they can read
 * from `GET /invitations` anyway.
 */
final class StoreInvitationRequest extends FormRequest
{
    /**
     * Authorization is the controller's `Gate::authorize()` pair, not this method. Two reasons:
     * FormRequest::authorize() runs BEFORE validation, so it would have to decide the owner-
     * escalation question against an unvalidated `role`; and a `false` here renders as a bare 403
     * with no policy involved, which bypasses the org-scoped decision OrgScopedPolicy exists to make.
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
            // 254 is the RFC 5321 maximum for a forward path. `email:rfc,strict` is
            // RFCValidation + NoRFCWarningsValidation; `dns` is deliberately absent, because it puts
            // a network lookup with no timeout budget inside an admin request.
            'email' => ['bail', 'required', 'string', 'email:rfc,strict', 'max:254'],
            'role' => ['bail', 'required', 'string', Rule::in(OrgRole::values())],
        ];
    }

    public function email(): string
    {
        return $this->safe()->string('email')->value();
    }

    /**
     * The requested role, already known to be one of the four by the time this is reachable.
     */
    public function role(): OrgRole
    {
        return OrgRole::from($this->safe()->string('role')->value());
    }

    /**
     * See the class docblock. Guarded on `is_string` because a client may post `email` as an ARRAY,
     * and `(string) []` is an "Array to string conversion" warning followed by the literal `Array` —
     * which would then satisfy the `string` rule it was supposed to fail.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }
}
