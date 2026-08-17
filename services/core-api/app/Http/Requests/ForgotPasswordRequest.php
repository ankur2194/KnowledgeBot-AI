<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * The forgot-password form: one field, and the field is the entire attack surface.
 *
 * ── THE NORMALISATION IS THE MOST IMPORTANT THING IN THIS FILE, AND IT IS INVISIBLE IN THE
 *    GENERATED MANIFEST ────────────────────────────────────────────────────────────────────────────
 *
 * `prepareForValidation()` lowercases and trims `email`. That is MANDATORY, not cosmetic, and it is
 * the same requirement LoginRequest carries for the same mechanical reason — here it bites TWICE:
 *
 *   * `users_email_unique` is a unique index on `lower(email)` (2026_08_07_000200), so the DATABASE
 *     treats `Bob@x.com` and `bob@x.com` as one account;
 *   * `Illuminate\Auth\EloquentUserProvider::retrieveByCredentials()` — which
 *     `PasswordBroker::getUser()` calls after stripping `token` — does an EXACT `where('email', …)`,
 *     so a mixed-case submission finds NO user and the endpoint answers with the same acknowledgement
 *     it gives an address that never existed. The user gets a 200 and no email, forever;
 *   * and `password_reset_tokens.email` is the PRIMARY KEY of the token row (2026_08_13_000700,
 *     `text COLLATE "C"`, so equality is `memcmp` on bytes). The row is written under whatever
 *     address the broker resolved and read back under whatever address the reset form submits. Two
 *     spellings are two rows, and only one of them can ever be found.
 *
 * A `lowercase` RULE WOULD NOT FIX IT: that rule REJECTS a mixed-case address with a 422 instead of
 * accepting it — which on THIS endpoint is strictly worse than on login, because a 422 here is
 * distinguishable from the 200 every other input gets, and the 200 is the account-enumeration
 * defence. Normalising the input is what makes `bob@x.com` and `Bob@x.com` the same request.
 *
 * `php artisan kb:dump-form-rules` dumps ONLY `rules()`, so `packages/contracts/rules/
 * ForgotPasswordRequest.json` cannot express any of the above. `forgotPasswordSchema` in
 * `packages/contracts/src/forms/auth.ts` deliberately does NOT lowercase on its side (normalisation
 * is not validation, and a form that rewrites what the user typed is its own bug report); this
 * paragraph is the only place the server-side requirement is written down.
 *
 * ── WHAT IS DELIBERATELY ABSENT ─────────────────────────────────────────────────────────────────
 *
 * NO `exists:users,email` RULE. It would turn this form into a live account-existence oracle for the
 * whole internet — a 422 for "no such account" against a 200 for everything else — which is exactly
 * the distinction PasswordResetLinkController collapses three broker outcomes to avoid.
 *
 * NO `messages()` OVERRIDE, unlike LoginRequest. There, `email.email` had to be flattened into the
 * one credential sentence so that a malformed address and a wrong password were byte-identical. Here
 * the two populations are not comparable in the first place: a malformed address is a 422 and every
 * well-formed one is a 200, and no malformed string can correspond to an account, so nothing about
 * account existence is learnable from the difference. The field-level message stays, because it is
 * the only thing that tells a user they typed their address wrong.
 *
 * NO FIELD BUT `email`. `forgotPasswordSchema` is `{ email }` and `test/form-drift.test.ts` asserts
 * PATH-SET EQUALITY between that schema and this manifest, so a second rule key here fails the
 * contract suite rather than merely going unmirrored.
 */
final class ForgotPasswordRequest extends FormRequest
{
    /**
     * Authorization is not a question this form can ask: it exists to help someone who cannot
     * authenticate at all, so there is no identity to authorize. The permission layer starts at
     * `auth:sanctum` on the routes a reset makes reachable again.
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
            // Character for character the rule LoginRequest uses, and that is deliberate rather than
            // duplicated by accident: the two endpoints must accept exactly the same set of strings
            // as "an address", or an address that can log in cannot be reset (or the reverse).
            //
            // 254 is the RFC 5321 maximum for a forward path; `email:rfc,strict` is RFCValidation +
            // NoRFCWarningsValidation, which rejects the obsolete forms egulias parses but nobody
            // should accept. `dns` is deliberately NOT here: it would put an unbounded network lookup
            // INSIDE `PasswordBroker::sendResetLink()`'s 200 ms timebox, and a DNS resolution whose
            // latency depends on the domain the caller submitted is a timing oracle about the domain
            // — with no budget in kb-error-taxonomy's timeout arithmetic to pay for it.
            'email' => ['bail', 'required', 'string', 'email:rfc,strict', 'max:254'],
        ];
    }

    /**
     * The broker's credential array. Shaped here rather than at the call site so the ONE key the
     * broker is handed is visible in the class that validated it: `PasswordBroker::getUser()` passes
     * this straight to `retrieveByCredentials()`, which builds a WHERE clause out of every key it
     * does not recognise as a password — so an extra key here is an extra predicate on the user
     * lookup, silently.
     *
     * @return array{email: string}
     */
    public function credentials(): array
    {
        return ['email' => $this->safe()->string('email')->value()];
    }

    /**
     * See the class docblock. Guarded on `is_string` because a client may post `email` as an array,
     * and `(string) []` is an "Array to string conversion" warning followed by the literal string
     * `Array` — which would then pass the `string` rule it was supposed to fail.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }
}
