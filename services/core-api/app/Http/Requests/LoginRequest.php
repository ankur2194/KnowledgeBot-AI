<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * The login form.
 *
 * ── THE NORMALISATION IS THE MOST IMPORTANT THING IN THIS FILE, AND IT IS INVISIBLE IN THE
 *    GENERATED MANIFEST ────────────────────────────────────────────────────────────────────────────
 *
 * `prepareForValidation()` lowercases and trims `email`. That is MANDATORY, not cosmetic, and it is
 * not a `lowercase` validation rule for a reason:
 *
 *   * `users_email_unique` is a unique index on `lower(email)` (2026_08_07_000200), so the DATABASE
 *     treats `Bob@x.com` and `bob@x.com` as one account;
 *   * `Illuminate\Auth\EloquentUserProvider::retrieveByCredentials()` does an EXACT
 *     `where('email', …)` — no `lower()`, no collation help, because `text` is case-sensitive here;
 *   * so without normalisation a user who registered as `Bob@x.com` can never log in as
 *     `bob@x.com`, and — the same code path — can never reset their password either. The symptom is
 *     "my password is wrong" on a correct password, and nothing in any log says otherwise.
 *
 * A `lowercase` RULE WOULD NOT FIX IT: that rule REJECTS a mixed-case address with a 422 instead of
 * accepting it, which turns a silent failure into a loud, wrong one. Normalising the input is what
 * makes `bob@x.com` and `Bob@x.com` the same request.
 *
 * `php artisan kb:dump-form-rules` dumps ONLY `rules()`, so `packages/contracts/rules/LoginRequest.json`
 * cannot express any of the above. The Zod mirror in `packages/contracts` must lowercase and trim on
 * its own side (rhf-zod-forms), and this paragraph is the only place that requirement is written
 * down on this side of the boundary.
 *
 * ── WHAT IS DELIBERATELY ABSENT ─────────────────────────────────────────────────────────────────
 *
 * NO `remember` FIELD (decision D4). A "remember me" checkbox means a `remember_token` cookie that
 * survives the session cookie, which is a second, longer-lived credential on the admin surface —
 * exactly the "no fifth mechanism" that laravel-sanctum-auth NN2 forbids. Session idle lifetime is
 * the only lifetime.
 *
 * NO `exists:users,email` RULE. It would turn the login form into a live account-existence oracle
 * for the whole internet, answered before a password is even checked.
 *
 * NO AUTHENTICATION IN THIS CLASS. Breeze puts an `authenticate()` method here; ours cannot, because
 * a FAILED login has to be audited (`AuditLogger::LOGIN_FAILED`) and a `ValidationException` thrown
 * from a FormRequest never returns to the controller that would write that row. The controller
 * therefore calls the guard itself and this class stays what its name says it is.
 */
final class LoginRequest extends FormRequest
{
    /**
     * The one message for BOTH failure modes.
     *
     * It goes on the `email` key and never on `password`: a message under `password` says "the
     * address exists, the secret is wrong", which is the enumeration oracle the shared string exists
     * to close. See LoginController for why the status is 422 and not 401.
     */
    public const FAILED = 'These credentials do not match our records.';

    /**
     * Authorization is not a question a login form can ask — there is no identity yet. The permission
     * layer starts at `auth:sanctum` on the routes this one exists to make reachable.
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
            // `bail` on both, so one malformed field produces one message rather than a list that
            // hints at how the value was parsed.
            //
            // 254 is the RFC 5321 maximum for a forward path; `email:rfc,strict` is
            // RFCValidation + NoRFCWarningsValidation, which rejects the obsolete forms egulias
            // parses but nobody should accept. `dns` is deliberately NOT here: it would put a
            // network lookup with no timeout budget inside a login request.
            'email' => ['bail', 'required', 'string', 'email:rfc,strict', 'max:254'],

            // `max:255` and NO minimum, NO complexity rules. This is the form where an EXISTING
            // credential is presented, so a policy here could only reject a password the user
            // already has — locking out exactly the accounts that predate the policy. Complexity
            // belongs on the two forms that SET a password. Note that bcrypt truncates at 72 bytes,
            // so anything past that is equivalent to its first 72; that is a property of the
            // algorithm, not something to hide behind a shorter `max`.
            'password' => ['bail', 'required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // The SAME sentence for a malformed address as for a wrong one. Otherwise
            // `not-an-email` and `nobody@example.com` produce two distinguishable 422 bodies, and
            // the enumeration test that compares the failure modes byte for byte would be asserting
            // a property the endpoint does not have.
            'email.email' => self::FAILED,
        ];
    }

    /**
     * @return array{email: string, password: string}
     */
    public function credentials(): array
    {
        $safe = $this->safe();

        return [
            'email' => $safe->string('email')->value(),
            'password' => $safe->string('password')->value(),
        ];
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
