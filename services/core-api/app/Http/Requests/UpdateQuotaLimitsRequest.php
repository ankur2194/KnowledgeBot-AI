<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Quotas\QuotaLimits;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The body of `PUT .../quotas`: all four ceilings, as a complete set.
 *
 * ── A PUT OF FOUR KEYS, AND `present` ON EVERY ONE ──────────────────────────────────────────
 *
 * `present` and `nullable` together are what make "the field was omitted" and "the field was null"
 * two different requests. On this body they mean opposite things and the difference is money:
 *
 *     omitted   →  422. The caller sent a partial set and does not know what the other three are.
 *     null      →  UNLIMITED. Remove this ceiling entirely.
 *
 * A PATCH shape would collapse those two, so a client that dropped a key from its payload would
 * silently remove a ceiling. That is the same argument the two designation endpoints make for being
 * PUTs, with a larger blast radius: there the omitted half is refused by a database CHECK, here
 * there is no constraint that can tell an intentional null from a missing key.
 *
 * ── `min:0` AND NOT `min:1` ─────────────────────────────────────────────────────────────────
 *
 * ZERO IS A LEGITIMATE CEILING and it means "nothing is allowed" — it is how an operator freezes an
 * organization without deleting anything. `organizations_quotas_nonnegative` admits it for the same
 * reason. Refusing zero here would mean the only way to stop a runaway tenant is to suspend the
 * whole organization, which also stops them reading their own data.
 *
 * ── WHAT THIS FORM DELIBERATELY DOES NOT VALIDATE ───────────────────────────────────────────
 *
 * IT DOES NOT CHECK THAT A CEILING IS ABOVE CURRENT USAGE. Setting a limit BELOW what an
 * organization has already consumed is legal and is the point — `QuotaGate` then refuses further
 * metered actions while leaving what exists in place.
 *
 * IT DOES NOT CHECK WHO MAY RAISE A LIMIT. That is a comparison between the submitted numbers and
 * the persisted ones, which a rule cannot see and which `App\Services\Quotas\QuotaLimitService`
 * owns: an organization owner may LOWER a ceiling, and raising or removing one needs
 * `users.is_platform_owner`. The house split is exact — the FormRequest validates shape, the Policy
 * decides permission, the Service decides behaviour.
 *
 * `organization_id` appears in no rule and in no DTO. Over-posting a tenant key is an authorization
 * bug with a 200 response (`laravel-rbac-policies` NN5).
 */
final class UpdateQuotaLimitsRequest extends FormRequest
{
    /**
     * The upper bound on a submitted ceiling.
     *
     * ── IT EXISTS FOR THE `bigint` COLUMN, NOT FOR PLAUSIBILITY ─────────────────────────────
     *
     * `storage_bytes_quota` and `monthly_tokens_quota` are `bigint`, so PostgreSQL's own ceiling is
     * 2^63 − 1 — and a value above it is a 22003 arriving from inside a transaction as a driver
     * error rather than as a per-field 422. More to the point, `QuotaGate` compares
     * `used + additional > limit` in PHP, and a limit near PHP_INT_MAX makes that sum overflow into
     * a float, where the comparison silently stops being exact.
     *
     * 2^53 − 1 is the largest integer every JSON consumer in this platform can round-trip exactly
     * (a JavaScript `Number` is a double, and `apps/web` reads this value), so it is the real bound
     * and it is stated as one rather than being discovered as a rendering bug. It is also ~9
     * petabytes and ~9 quadrillion tokens: an operator who needs more should be using `null`.
     */
    public const MAX_LIMIT = 9_007_199_254_740_991;

    /**
     * Authorization is `Gate::authorize()` in the controller, not here. `FormRequest::authorize()`
     * runs BEFORE validation, so a policy call placed in it decides on unvalidated input — and it
     * cannot reach checks 5 and 6 (entity status, rate limit) at all.
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
        $limit = ['present', 'nullable', 'integer', 'min:0', 'max:'.self::MAX_LIMIT];

        return [
            'storage_bytes_quota' => $limit,
            'bots_quota' => $limit,
            'users_quota' => $limit,
            'monthly_tokens_quota' => $limit,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $present = 'A quota update is a complete set of all four ceilings. Send the field with a '
            .'null to remove the limit, or with its current value to leave it alone — an omitted '
            .'key would be indistinguishable from "remove this ceiling", and the difference is what '
            .'an organization is allowed to spend.';

        return [
            'storage_bytes_quota.present' => $present,
            'bots_quota.present' => $present,
            'users_quota.present' => $present,
            'monthly_tokens_quota.present' => $present,
        ];
    }

    /**
     * The validated set. Nulls survive as nulls all the way to the column — see `QuotaLimits`.
     */
    public function toLimits(): QuotaLimits
    {
        /**
         * @var array{
         *     storage_bytes_quota: int|null,
         *     bots_quota: int|null,
         *     users_quota: int|null,
         *     monthly_tokens_quota: int|null,
         * } $validated
         */
        $validated = $this->validated();

        return new QuotaLimits(
            storageBytes: $validated['storage_bytes_quota'],
            bots: $validated['bots_quota'],
            users: $validated['users_quota'],
            monthlyTokens: $validated['monthly_tokens_quota'],
        );
    }
}
