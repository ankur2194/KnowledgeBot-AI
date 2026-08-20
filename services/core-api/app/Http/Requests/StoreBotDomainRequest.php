<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\ExactWidgetOrigin;
use App\Support\Web\ExactOrigin;
use Illuminate\Foundation\Http\FormRequest;
use RuntimeException;

/**
 * Add one origin to a bot's widget allow-list.
 *
 * ── ONE FIELD, AND EVERYTHING THAT IS NOT HERE IS THE INTERESTING PART ────────────────────────
 *
 * `organization_id` and `bot_id` are never validated, never posted and never in a DTO: the first
 * comes from the authenticated context and the second from the route. Over-posting a tenant key is
 * an authorization bug with a 200 response (laravel-rbac-policies NN5), and `bot_id` is worse than
 * the usual case here — it is the OWNERSHIP EDGE of the row inside the organization, so a fillable
 * one would let a form move a verified origin from one bot to another while the composite foreign
 * key agreed, because both bots belong to that tenant.
 *
 * `status` IS NOT A FIELD ON THIS ENDPOINT, and that is the design rather than an omission. A row
 * starts `pending` and grants nothing; promotion is `PATCH /bot-domains/{domain}`, a separate
 * request. `App\Models\BotDomain` keeps `status` out of `$fillable` for the same reason and states
 * it: letting the form that ENTERED an unverified origin also mark it verified collapses the two
 * steps into one and removes the only thing standing between a typo and an open grant on somebody
 * else's domain.
 *
 * ── NO `unique:` RULE, ON THE ONE FIELD THAT LOOKS LIKE IT WANTS ONE ──────────────────────────
 *
 * The duplicate check is an org-scoped repository query in `BotDomainService`, not
 * `unique:bot_domains,origin`. The reasoning is the house rule `DesignateEmbeddingConnectionRequest`
 * writes out at length and it applies twice over here: a `unique:` rule queries the table with NO
 * organization predicate unless somebody remembers to add one — the exact shape of Filament
 * CVE-2026-48067, where the select query was tenant-scoped and the validation rule for the same
 * field was not — and an unscoped rule on THIS column would additionally refuse an origin another
 * tenant happens to have listed, which is an existence oracle over every customer's embed sites
 * rendered as a validation error on a form.
 *
 * ── THE VALUE THAT IS STORED IS NOT THE VALUE THAT WAS SENT, AND THAT IS DELIBERATE ───────────
 *
 * `toOrigin()` returns the RFC 6454 serialisation: lower-cased, one trailing slash dropped, the
 * default port dropped. `ExactOrigin` records why each of those three is safe and why a non-empty
 * path is REFUSED rather than trimmed — trimming it would broaden the grant from one page to a
 * whole host, which is the one direction an allow-list must never move on its own.
 */
final class StoreBotDomainRequest extends FormRequest
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
     * `max` BEFORE the rule object, and `bail` before both, so a 10 KB body is refused on its
     * length rather than run through a parser. `ExactOrigin::MAX_LENGTH` rather than a literal, so
     * the rule and the parser cannot disagree about the ceiling.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'origin' => [
                'bail',
                'required',
                'string',
                'max:'.ExactOrigin::MAX_LENGTH,
                new ExactWidgetOrigin,
            ],
        ];
    }

    /**
     * The origin, in the exact serialisation a browser sends.
     *
     * `ExactOrigin::parse()` runs a second time here rather than the rule stashing its result: the
     * validator constructs the rule object and this class never sees that instance, and a
     * `prepareForValidation()` that mutated the input would report a 422 about a value the caller
     * did not send. Two calls to a pure parser is the cheap half of that trade.
     *
     * @throws RuntimeException when validation admitted a value the parser refuses — impossible
     *                          while both go through `ExactOrigin`, and loud rather than silent if
     *                          somebody ever splits them
     */
    public function toOrigin(): string
    {
        /** @var array{origin: string} $data */
        $data = $this->validated();

        $parsed = ExactOrigin::parse($data['origin']);

        if (is_string($parsed)) {
            throw new RuntimeException(
                'StoreBotDomainRequest validated an origin that ExactOrigin refuses: '.$parsed
                .' The rule and this method must both go through ExactOrigin::parse(), or the '
                .'endpoint accepts values the allow-list cannot store.',
            );
        }

        return (string) $parsed;
    }
}
