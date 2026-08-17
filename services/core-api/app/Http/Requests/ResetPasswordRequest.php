<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * The reset form: the emailed token, the address it was issued to, and the new password twice.
 *
 * ── THE PASSWORD POLICY IS EXPLICIT STRING RULES, NEVER `Password::defaults()` ───────────────────
 *
 * Two independent reasons, either of which alone decides it:
 *
 *   (a) `Illuminate\Validation\Rules\Password` HAS NO `__toString`. `DumpFormRulesCommand::normalize()`
 *       records a rule OBJECT by its class name, so the manifest would publish the literal string
 *       `"Illuminate\\Validation\\Rules\\Password"` — deterministic, and completely opaque. The Zod
 *       mirror in `packages/contracts/src/forms/auth.ts` could then not reproduce the policy, and the
 *       failure that ships is the quiet one: the form accepts `aaaaaaaaaaaa`, the server 422s on a
 *       rule the user was never shown, and the message arrives on `password` after the round trip.
 *   (b) `Password::uncompromised()` makes an HTTP call to HaveIBeenPwned INSIDE a password endpoint,
 *       and it FAILS OPEN. It has no timeout budget in kb-error-taxonomy's arithmetic, no entry in the
 *       retry-ownership table, and no error_class — so its slow path is an unowned latency source on
 *       the one endpoint whose latency profile is a security property elsewhere in this file.
 *
 * The four constraints below are mirrored constraint for constraint by `newPasswordField()` in
 * `packages/contracts/src/forms/auth.ts`, `\p{Ll}`/`\p{Lu}` included — `[a-z]` would reject `ärger`
 * where `\p{Ll}` accepts it, and a form stricter than the server is the failure nobody reports.
 *
 * ── THE NORMALISATION, WHICH IS INVISIBLE IN THE GENERATED MANIFEST ──────────────────────────────
 *
 * `prepareForValidation()` lowercases and trims `email` — MANDATORY, for the reason LoginRequest and
 * ForgotPasswordRequest both spell out, and here it decides whether the reset can work AT ALL:
 * `password_reset_tokens.email` is the PRIMARY KEY of the token row (`text COLLATE "C"`, so equality
 * is `memcmp` on bytes), and `DatabaseTokenRepository::exists()` reads the row by the address
 * `PasswordBroker::getUser()` resolved from an EXACT `where('email', …)`. Submit `Bob@x.com` against
 * a row written for `bob@x.com` and the token cannot be found — which this endpoint reports as the
 * deliberately indistinguishable "no longer valid", so the user is told their link is broken and
 * nothing anywhere says otherwise.
 *
 * It is NOT a `lowercase` validation rule: that rule REJECTS a mixed-case address rather than
 * accepting it. `kb:dump-form-rules` dumps only `rules()`, so the manifest cannot express this and
 * this paragraph is the only record of it on the server side.
 *
 * `password` AND `password_confirmation` ARE NOT TRIMMED, BY ANYONE. Laravel's global `TrimStrings`
 * middleware carries an `$except` list and both are on it — the framework refuses to alter a
 * credential in transit. Do not add trimming here either: registration would store the hash of
 * `"hunter2 "` while a trimming login submits `"hunter2"`, and the user is locked out of an account
 * they just created with input that looks correct.
 *
 * ── THE FOUR RULE KEYS ARE A CONTRACT, NOT A CHOICE ──────────────────────────────────────────────
 *
 * `resetPasswordSchema` is `{ token, email, password, password_confirmation }` and
 * `test/form-drift.test.ts` asserts PATH-SET EQUALITY against this manifest. `password_confirmation`
 * therefore needs its own rule row even though `confirmed` on `password` already reads it — a
 * cross-field rule is not a path. Adding or dropping a key here fails the contract suite.
 *
 * NO `exists:users,email` RULE, and no `size:` on the token. Both would answer, before the broker is
 * ever consulted, a question this endpoint exists to refuse to answer: whether the address is real,
 * and whether a guessed token is the right SHAPE.
 */
final class ResetPasswordRequest extends FormRequest
{
    /**
     * THE ONE MESSAGE FOR EVERY WAY THE CREDENTIAL CAN FAIL.
     *
     * `PasswordBroker::validateReset()` distinguishes `INVALID_USER` (no such address) from
     * `INVALID_TOKEN` (wrong, expired, or already consumed). Both arrive here, on the `token` key, as
     * this sentence. Keying it on `email` for the first case would tell a prober that the address is
     * unknown; keying it on `token` for both says only that the link does not work, which is the only
     * thing the holder of a broken link needs to know.
     *
     * It names the remedy because the honest failure — a link older than 60 minutes — is the common
     * one, and a message that only says "invalid" sends the user to support instead of to
     * `/forgot-password`.
     */
    public const INVALID = 'This password reset link is no longer valid. Request a new one.';

    /**
     * The TOKEN is the credential on this route, and it is verified by the broker in the controller —
     * not here. `FormRequest::authorize()` runs BEFORE validation, so a token check placed in it would
     * decide on an unvalidated value and would render as a 403 `authorization`, announcing to a
     * prober that the token was the thing that failed. The 422 `validation` on `token` is the whole
     * design.
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
            // `max:255` and no `size:`. `password_reset_tokens.token` holds a BCRYPT HASH of a
            // 64-hex-character token (`DatabaseTokenRepository::getPayload()`), so the plaintext
            // length is knowable — and pinning it here would reject a wrong-length guess LOCALLY,
            // with a different message from a wrong-value guess. One shape of failure, one response.
            'token' => ['bail', 'required', 'string', 'max:255'],

            // Character for character ForgotPasswordRequest's rule: the address that asked for the
            // link and the address that redeems it must be the same set of accepted strings, or a
            // link can be issued and never redeemed.
            'email' => ['bail', 'required', 'string', 'email:rfc,strict', 'max:254'],

            'password' => [
                'bail',
                'required',
                'string',
                // 12, not 8. This is a form that SETS a credential, so a policy here costs a user one
                // keystroke; the same policy on LoginRequest would lock out every account created
                // before it. That asymmetry is why LoginRequest carries no minimum at all.
                'min:12',
                // bcrypt truncates at 72 bytes, so a 200-character passphrase is silently equivalent
                // to its first 72. That is a property of the algorithm, stated rather than hidden
                // behind a shorter `max` that would reject passphrases people actually use.
                'max:255',
                // Reads `password_confirmation` from the raw input. Both fields are exempt from
                // TrimStrings, so the comparison is byte-exact on both sides and cannot be broken by
                // one of them being normalised and the other not.
                'confirmed',
                // Unicode classes with the `u` flag, not `[a-z]`/`[A-Z]`/`[0-9]`. No comma appears in
                // any pattern, which matters: Laravel splits a rule's parameters on `,`, so a
                // quantifier like `{2,}` inside a `regex:` string rule would be silently truncated
                // into a broken pattern. Keep these single-class and comma-free, or move to
                // Rule::forEach / an array rule object — never a comma in a string rule.
                'regex:/\p{Ll}/u',
                'regex:/\p{Lu}/u',
                'regex:/\d/u',
            ],

            // Its own key so the manifest path set matches the Zod schema (see the class docblock).
            // No `max`, no `min` beyond `required` — `confirmed` already forces it to equal
            // `password`, and a second length bound here could only disagree with the first.
            'password_confirmation' => ['bail', 'required', 'string'],
        ];
    }

    /**
     * The broker's credential array, in the framework's own shape.
     *
     * ALL FOUR KEYS GO IN, INCLUDING BOTH PASSWORD FIELDS, and that is safe rather than sloppy:
     * `PasswordBroker::getUser()` strips `token` and hands the rest to
     * `EloquentUserProvider::retrieveByCredentials()`, which SKIPS every key containing the substring
     * `password` before building its WHERE clause. So the user lookup is `where('email', …)` and
     * nothing else. Any key added here that does NOT contain `password` becomes a silent extra
     * predicate on that lookup.
     *
     * @return array{token: string, email: string, password: string, password_confirmation: string}
     */
    public function credentials(): array
    {
        $safe = $this->safe();

        return [
            'token' => $safe->string('token')->value(),
            'email' => $safe->string('email')->value(),
            'password' => $safe->string('password')->value(),
            'password_confirmation' => $safe->string('password_confirmation')->value(),
        ];
    }

    /**
     * See the class docblock. `email` ONLY — never `password`. Guarded on `is_string` because a client
     * may post `email` as an array, and `(string) []` is an "Array to string conversion" warning
     * followed by the literal string `Array`, which would then pass the `string` rule it was supposed
     * to fail.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }
}
