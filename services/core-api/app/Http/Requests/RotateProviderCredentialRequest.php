<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Organization;
use App\Models\ProviderConnection;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Providers\ProviderCredentialRotation;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Replace the secret stored on one provider connection.
 *
 * ── CHECK 6 IN THE §18.3 SENSE: THE ACTOR RE-AUTHENTICATES ─────────────────────────────────────
 *
 * Rotation breaks every live bot on this provider the instant it commits, so kb-security-baseline
 * §18.3 requires a FRESH authentication rather than a valid session. `current_password:web` is
 * that check, and it is a validation rule rather than a service call for two reasons: it runs
 * BEFORE anything reads or writes the connection row, which is what makes "a failed password must
 * not touch the row" true by construction rather than by ordering discipline; and it lands in
 * packages/contracts/rules/RotateProviderCredentialRequest.json, so the admin console is told the
 * field exists instead of discovering it as a 422 on a form it never rendered.
 *
 * `:web` IS EXPLICIT AND IS NOT DECORATION. `current_password` with no argument resolves
 * `config('auth.defaults.guard')`, which is `web` today — but the route is named `auth:sanctum`,
 * and a reader checking whether the rule can possibly work has to know that Sanctum's guard chain
 * is `['web']` and that the SPA cookie is what actually authenticated. Naming the guard says so at
 * the call site. See config/auth.php's "THE GUARD CHAIN" note.
 *
 * ORDERING, STATED SO IT IS NOT MISREAD AS A GAP: a FormRequest validates before the controller
 * runs, so a member with the WRONG ROLE and the WRONG PASSWORD gets 422 rather than 403. That
 * leaks nothing — the only password this rule can test is the caller's own, which they already
 * know — and a non-member never reaches validation at all, because `org.member` is middleware and
 * denies upstream of route binding. The row is untouched on every one of those paths.
 *
 * ── WHAT NEVER APPEARS ─────────────────────────────────────────────────────────────────────────
 *
 * Neither field reaches a response, a log line, an audit detail or an exception message.
 * `current_password` and `credential` are both listed in bootstrap/app.php's `dontFlash()`, so a
 * ValidationException cannot flash either into the session store; `toData()` drops the password
 * entirely rather than carrying it into the service layer; and the DTO marks the key
 * `#[SensitiveParameter]`, so a throw from the vault or the driver renders it as
 * `Object(SensitiveParameterValue)` in the trace.
 *
 * `organization_id` appears in no rule and in no DTO. The connection is identified by the route,
 * resolved through a scoped binding, so there is no `exists:` rule here either — see
 * UpdateProviderConnectionRequest and DesignateEmbeddingConnectionRequest for the full argument
 * against querying a tenant-owned table from a validation rule.
 */
final class RotateProviderCredentialRequest extends FormRequest
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
     * ── THE MASK RULE, AND THE BUG IT EXISTS TO STOP ───────────────────────────────────────────
     *
     * ProviderConnectionResource renders `masked_key` as U+2026 (HORIZONTAL ELLIPSIS) followed by
     * the stored last four — `…4a91`. A console form that seeds its credential input from the
     * resource it just fetched, which is the obvious way to build an edit screen, would post that
     * display string back. Without this rule it passes every other check: it is a string, it is
     * over the length floor, and it would be sealed and stored as the tenant's new provider key.
     * The organization's next chat turn then fails with `provider_auth` against a key that is the
     * literal text `…4a91`, and nothing anywhere says why — the request was a 200.
     *
     * ANCHORED AT THE START rather than matching the character anywhere, because U+2026 is legal
     * in an arbitrary secret and refusing every key containing one would be a rule that rejects
     * valid input for a shape we invented. The only value this needs to refuse is one that BEGINS
     * with our own mask marker.
     *
     * A `not_regex` string rather than a closure rule: a closure has no stable string form, so
     * `kb:dump-form-rules` would record it as `Closure` — "a rule no client can be generated
     * from", which that command's own docblock names as a finding rather than noise. Written in
     * ARRAY form, which is mandatory for regex rules (a `|` inside the pattern would otherwise
     * split it).
     *
     * IT RUNS BEFORE THE LENGTH BOUNDS, and it did not used to — see the inline note in `rules()`
     * for why that made the guard dead code and its test green on the wrong rule.
     *
     * THE SAME RULE IS ON `StoreProviderConnectionRequest`. The hazard is not specific to rotation:
     * a create form seeded from a resource is the same mistake with the same 200 and the same
     * `provider_auth` afterwards, and ProviderConnectionResource's published schema describes it as
     * happening on "a create or rotate request" — which was true of the description and false of
     * the code until the create path grew the rule.
     *
     * The length bounds match StoreProviderConnectionRequest exactly, and for the same reason
     * stated there: shape only, never a vendor prefix regex. A rule that rejects anything not
     * matching `sk-…` breaks the day a vendor changes its key format, and reads to the tenant as
     * "our key is invalid" when their key is fine.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['bail', 'required', 'string', 'current_password:web'],
            'credential' => [
                'bail',
                'required',
                'string',
                // BEFORE THE LENGTH BOUNDS, AND THAT ORDER IS THE WHOLE RULE RATHER THAN A
                // PREFERENCE. `masked_key` is `'…'.$connection->last_four` — U+2026 plus four
                // characters, FIVE characters long — so under `bail` with `min:8` sitting above it
                // this guard never executed once: the mask failed the length floor, `bail` stopped,
                // and the message the operator got was "at least 8 characters" for a value whose
                // problem is that it is a display string. The test that covered it asserted only
                // that an `errors.credential` key existed, which `min` satisfies, so it was green
                // against a rule that had never run.
                //
                // Ordering it first also makes the guard correct for a mask that WOULD clear the
                // floor — a provider whose `last_four` column grows, or a future mask format — which
                // is the case where the length rule stops covering for it and nothing else would.
                'not_regex:/^\x{2026}/u',
                'min:8',
                'max:512',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'That password is not correct. Rotating a '
                .'provider credential breaks every bot using it, so it needs your password and not '
                .'just a signed-in session.',
            'credential.not_regex' => 'That looks like the masked display value (`…` followed by '
                .'the last four characters), not a credential. The mask cannot authenticate '
                .'anything; paste the full key from the provider.',
        ];
    }

    /**
     * A FAILED RE-AUTHENTICATION IS AN AUDIT EVENT, AND UNTIL THIS METHOD IT WAS NOT RECORDED
     * ANYWHERE.
     *
     * All four `provider.connection.*` operations are `OUTCOME_SUCCESS`, and `current_password:web`
     * living in `rules()` means a wrong password never reaches the service that writes them — so
     * every failed attempt on the one endpoint that changes a credential left no trace at all. The
     * login surface has `auth.login.failed` for exactly this; this one had no equivalent, and
     * §18.11 asks for credential changes to be auditable, which an ATTEMPT on the changing endpoint
     * is part of. The rate limiter bounds the guessing (`credential-rotation`); it does not record
     * it, and an incident timeline is built from `audit_logs`, not from a 429 counter.
     *
     * `failedValidation()` RATHER THAN A `Validator::after` HOOK. `after` does not run when an
     * earlier rule on the same attribute already failed under `bail`, and it would also fire for a
     * MISSING password, which is a malformed request rather than a wrong answer. This method sees
     * the resolved failure set, so it can key on the one rule that means "the actor could not prove
     * they are who the session says".
     *
     * IT KEYS ON THE RULE, NOT ON THE FIELD. `current_password` also carries `required` and
     * `string`, and a body with no password at all is a client bug, not an attempt — recording it
     * would put a row in an append-only table every time a form posts before it is filled in.
     * `$validator->failed()` names the studly rule (`CurrentPassword`), which is the only value
     * that means what this row claims.
     *
     * WHAT IS NOT RECORDED, AND CANNOT BE: the submitted password, any part of it, or a fingerprint
     * of it. AuditLogger's allow-list for this operation is `{provider, label, status}` and an
     * unlisted key is dropped and reported rather than written — but the call below does not even
     * offer one. A fingerprint would be worse than useless here: it would make `audit_logs` an
     * offline guessing oracle against the actor's own account password, in a store that outlives
     * the account.
     *
     * ORDER: the row is written BEFORE `parent::failedValidation()`, which throws. It has to be —
     * a ValidationException is thrown, not returned, so anything after that call is unreachable.
     * The operation is `ON_FAILURE_LOG`, so a failure of the audit INSERT itself is logged at ERROR
     * and the 422 still stands: a wrong password must not become a 500.
     */
    protected function failedValidation(Validator $validator): void
    {
        $this->recordFailedReauthentication($validator);

        parent::failedValidation($validator);
    }

    /**
     * The replacement key, as a type. The re-authentication password is deliberately NOT carried
     * into it — see the DTO's docblock.
     */
    public function toData(): ProviderCredentialRotation
    {
        /** @var string $credential */
        $credential = $this->validated('credential');

        return new ProviderCredentialRotation($credential);
    }

    /**
     * Write `provider.connection.credential_rotation_failed`, when — and only when — the
     * re-authentication rule is what refused the request.
     *
     * THE ROUTE MODELS ARE ALREADY BOUND. `SubstituteBindings` is middleware and runs long before a
     * FormRequest is resolved for the controller's signature, and the group calls
     * `->scopeBindings()`, so `{providerConnection}` was resolved through
     * `$organization->providerConnections()` and a foreign id 404'd before any of this ran. The
     * `instanceof` guards below are therefore for the impossible case, and they RETURN rather than
     * throw: a defensive check that turns a 422 into a 500 has made the endpoint worse.
     *
     * DETAILS COME FROM THE PERSISTED ROW, never from request input — which is what makes "nothing
     * the caller submitted can appear in this row" a property of the code rather than of anyone's
     * discipline.
     */
    private function recordFailedReauthentication(Validator $validator): void
    {
        /** @var array<string, array<string, mixed>> $failed */
        $failed = $validator->failed();

        if (! array_key_exists('CurrentPassword', $failed['current_password'] ?? [])) {
            return;
        }

        $organization = $this->route('organization');
        $connection = $this->route('providerConnection');

        if (! $organization instanceof Organization || ! $connection instanceof ProviderConnection) {
            return;
        }

        $actor = $this->user();

        app(AuditLogger::class)->record(
            AuditLogger::PROVIDER_CREDENTIAL_ROTATION_FAILED,
            organizationId: $organization->organizationId(),
            // The actor IS known here, unlike on a failed login: the session authenticated, and it
            // is the password behind it that did not. That asymmetry is the whole value of the row.
            actorId: $actor instanceof User ? $actor->id : null,
            details: [
                'provider' => $connection->provider->value,
                'label' => $connection->label,
                'status' => $connection->status->value,
            ],
            subjectType: ProviderConnection::class,
            subjectId: $connection->id,
            request: $this,
        );
    }
}
