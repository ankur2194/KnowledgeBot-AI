<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Kb\OpaqueToken;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create an account by accepting an invitation. There is no open sign-up.
 *
 * ── THERE IS NO `email` FIELD, AND THAT IS DECISION D3 OVERRIDING THE DESIGN DOCUMENT ───────────
 *
 * The approved design (§3, endpoint 5) had `email` required and `hash_equals`-compared against the
 * invitation's address, with a mismatch collapsing into the shared invalid-token 422. That is
 * superseded, and the reason is security rather than convenience: while a submitted address is
 * *checked*, it is still an address the caller chose, and the whole class of "did the comparison run
 * on every path" questions disappears when the field does not exist. The invitation row is the SOLE
 * authority for the address, so an invitee cannot register under anyone else's — not even by
 * accident, and not if a future refactor drops the comparison.
 *
 * Two consequences worth stating, because both are load-bearing:
 *
 *   * the design's failure-ordering steps 3 and 4 collapse into one. There is no "submitted address
 *     does not match the invitation" branch left to get wrong; the only address in play comes off
 *     the row;
 *   * `packages/contracts/src/forms/auth.ts`'s `registerSchema` — already committed — is
 *     `{token, name, password, password_confirmation}`, and `packages/contracts/test/form-drift.test.ts`
 *     asserts PATH-SET EQUALITY between that schema and this class's dumped manifest. Adding `email`
 *     here breaks a committed contract in CI rather than merely disagreeing with it.
 *
 * No `prepareForValidation()` normalisation is therefore needed or present: the address never
 * arrives from the client, and the row it comes from is guaranteed lower-case by the
 * `organization_invitations_email_lowercase` CHECK.
 *
 * ── WHAT ELSE IS DELIBERATELY ABSENT ───────────────────────────────────────────────────────────
 *
 * NO `unique:users,email` RULE — the field it would guard does not exist, and even with the design's
 * `email` field it would have been wrong: a `unique` rule turns registration into a live
 * account-existence oracle for anyone who can POST. Uniqueness is enforced by `users_email_unique`
 * and reported as a 422 on `email` only AFTER the caller has proven possession of a token bound to
 * that exact address.
 *
 * NO `organization_id` AND NO `role`. Both come off the invitation row. Over-posting a tenant key or
 * a role is an authorization bug with a 200 response (laravel-rbac-policies NN5), and the columns are
 * absent from every `$fillable` as the second layer.
 *
 * NO `terms` / `marketing_opt_in` / anything else. A field accepted here is a field the register form
 * can be made to submit from a hostile page.
 *
 * ── THE PASSWORD POLICY IS EXPLICIT STRING RULES, NEVER `Password::defaults()` ──────────────────
 *
 * Two independent reasons, and either is sufficient. (a) `Illuminate\Validation\Rules\Password` has
 * no `__toString()`, so `DumpFormRulesCommand::normalize()` records it as the literal class name
 * `"Illuminate\\Validation\\Rules\\Password"` — deterministic, opaque, and unmirrorable by the Zod
 * schema, which means `form-drift.test.ts` cannot compare the two and the form silently drifts from
 * the server. (b) `Password::uncompromised()` makes an unbudgeted HaveIBeenPwned HTTP call INSIDE a
 * password endpoint, and it FAILS OPEN — a network dependency with no timeout in
 * `kb-error-taxonomy`'s arithmetic and no owner in its retry table.
 *
 * `\p{Ll}` / `\p{Lu}` / `\d` with the `u` flag rather than `[a-z]` / `[A-Z]`: an ASCII class would
 * reject a password whose only lower-case letters are non-Latin, which is a policy nobody wrote.
 *
 * `max:255` with a note rather than a shorter cap: bcrypt truncates at 72 bytes, so a 200-character
 * passphrase is silently equivalent to its first 72. That is a property of the algorithm and is
 * stated rather than hidden behind a `max:72` nobody could explain.
 */
final class RegisterRequest extends FormRequest
{
    /**
     * Authorization is possession of the invitation token, and resolving it is the controller's job:
     * FormRequest::authorize() runs BEFORE validation, so a lookup here would probe the table with
     * an unvalidated string and would render its refusal as a 403 rather than as the shared 422.
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
            // D13: `token`, never `invitation_token`. See PreviewInvitationRequest for why the name
            // is load-bearing (the `invitation` rate limiter keys on it).
            'token' => ['bail', 'required', 'string', 'size:'.OpaqueToken::LENGTH],

            // 120 matches the Zod mirror and leaves room for a real name in any script. `min:1`
            // rather than relying on `required`: the global TrimStrings middleware turns "   " into
            // "", and `required` already rejects "" — the explicit minimum is what makes the
            // manifest say so to a client that does not trim.
            'name' => ['bail', 'required', 'string', 'min:1', 'max:120'],

            'password' => [
                'bail', 'required', 'string', 'min:12', 'max:255', 'confirmed',
                'regex:/\p{Ll}/u', 'regex:/\p{Lu}/u', 'regex:/\d/u',
            ],

            // Declared even though `confirmed` already requires it, so the field appears in the
            // dumped manifest and the Zod mirror's path set matches. No `min` and no `max`: a
            // constraint here that the `password` field does not have would reject a confirmation
            // that matches a password the server accepts.
            'password_confirmation' => ['bail', 'required', 'string'],
        ];
    }

    public function token(): string
    {
        return $this->safe()->string('token')->value();
    }

    public function displayName(): string
    {
        return $this->safe()->string('name')->value();
    }

    /**
     * The plaintext, handed straight to the model whose `password` cast hashes it. It is never
     * logged, never audited and never echoed; `bootstrap/app.php`'s `dontFlash()` list carries both
     * password fields so a ValidationException cannot flash it into the session either.
     */
    public function plaintextPassword(): string
    {
        return $this->safe()->string('password')->value();
    }
}
