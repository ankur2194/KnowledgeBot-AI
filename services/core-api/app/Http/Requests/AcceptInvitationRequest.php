<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Kb\OpaqueToken;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Join an organization as an EXISTING, signed-in user.
 *
 * ONE FIELD, `token` (decision D13), for the reason PreviewInvitationRequest spells out: the
 * `invitation` rate limiter keys on that exact input name, so a different spelling silently collapses
 * every caller into one bucket.
 *
 * ── THE ADDRESS IS NOT A FIELD, AND ON THIS ENDPOINT THAT IS THE ENTIRE AUTHORIZATION DECISION ──
 *
 * The invitation's address is compared against the AUTHENTICATED USER'S — never against anything in
 * the body. Accepting an address here would let a signed-in user who intercepted somebody else's
 * invitation link name that somebody else and join an organization the invitation was never issued
 * to. The comparison lives in App\Services\Auth\RegistrationService::accept(), uses `hash_equals`,
 * and its refusal is byte-identical to an unknown token's.
 *
 * NO `organization_id`: the organization is a fact about the invitation row, not a parameter. A
 * caller who could name the organization could accept an invitation into a different one.
 */
final class AcceptInvitationRequest extends FormRequest
{
    /**
     * `auth:sanctum` on the route is check 1; everything else this endpoint decides needs the
     * invitation row in hand, which is the controller's and the service's job. Note that
     * FormRequest::authorize() runs before validation, so any lookup placed here would run on an
     * unvalidated token and render as a 403 rather than as the shared 422.
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
            'token' => ['bail', 'required', 'string', 'size:'.OpaqueToken::LENGTH],
        ];
    }

    public function token(): string
    {
        return $this->safe()->string('token')->value();
    }
}
