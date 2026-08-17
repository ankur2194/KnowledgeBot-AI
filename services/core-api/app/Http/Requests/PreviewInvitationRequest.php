<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Kb\OpaqueToken;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "What is this invitation link for?" — asked by a guest holding the link.
 *
 * ONE FIELD, AND IT IS CALLED `token` (decision D13, which supersedes the design document's
 * `invitation_token`). Three FormRequests accept an invitation token — this one, RegisterRequest and
 * AcceptInvitationRequest — and all three MUST use the same field name, because
 * `AppServiceProvider::defineRateLimiters()`'s `invitation` limiter keys its per-caller bucket on
 * `hash('sha256', $request->input('token'))`. A route posting `invitation_token` would present no
 * `token` at all, every request would share the digest-of-empty-string bucket, and the per-token
 * budget would silently become one global 10/minute choke for the whole internet.
 *
 * `size:64` AND NOT A RANGE. App\Support\Kb\OpaqueToken mints 32 CSPRNG bytes hex-encoded, so a
 * valid token is exactly OpaqueToken::LENGTH characters — every other length is a value this
 * application never issued, and rejecting it here keeps a 4 KB body out of an indexed `bytea`
 * comparison. The rule is composed from the constant rather than the literal `64` so the two cannot
 * drift; `kb:dump-form-rules` records the evaluated string, so the manifest still reads `size:64`.
 *
 * THERE IS NO `exists:` RULE AND THERE CANNOT BE ONE. The stored column is `token_hash` — 32 raw
 * bytes of sha256 — so an `exists:organization_invitations,token_hash` rule would compare a
 * plaintext against a digest and reject every valid token. More importantly, the four invalid
 * states (unknown, expired, accepted, revoked) must produce ONE 404 with one body, and a validation
 * rule would produce a distinguishable 422 for the "unknown" case alone. Resolution is the
 * controller's job, on purpose.
 *
 * NO `email` FIELD. The token identifies the invitation and the invitation names the address; a
 * caller-supplied address on this endpoint would be an input to a lookup that has no use for one.
 */
final class PreviewInvitationRequest extends FormRequest
{
    /**
     * Authorization is possession of the token, which is not a question this class can ask — the
     * lookup is what answers it. Returning false here would render a 403 for a malformed body,
     * which is both the wrong class and a different response from the 404 an unknown token gets.
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
