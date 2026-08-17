<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Kb\OpaqueToken;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The emailed verification token, echoed back by the SPA.
 *
 * ── THE FIELD IS `token`, AND NEVER `invitation_token` (decision D13) ───────────────────────────
 *
 * The two names belong to two different flows. `invitation_token` exists on exactly one request —
 * RegisterRequest, where the body ALSO carries an `email`, a `name` and a password, so a bare `token`
 * there would read as "the token for this registration" and be ambiguous about which capability it
 * is. Every other capability-consuming request on this surface (invitation preview, invitation accept,
 * password reset, this one) posts a single token under the single name `token`. Renaming this field to
 * match RegisterRequest would break the Zod mirror in `packages/contracts`, would silently 422 every
 * link already in flight in somebody's mailbox, and would put two spellings of one concept on one
 * surface.
 *
 * ── `size:64` AND NOT A RANGE ───────────────────────────────────────────────────────────────────
 *
 * App\Support\Kb\OpaqueToken mints 32 bytes from the CSPRNG, hex-encoded — exactly 64 characters,
 * always. An exact length is therefore the honest rule, and it is the same rule the other three
 * token-consuming requests use. It rejects a 6-character guess and a 4 KB payload before either
 * reaches an indexed `bytea` comparison, which is the only work this rule saves; it is NOT a security
 * boundary and must not be read as one — every well-formed 64-character string gets the same 404.
 *
 * `bail` so a malformed token produces ONE message. There is no `regex:/^[0-9a-f]{64}$/` rule on top:
 * it would split "not a real token" into two distinguishable 422 bodies (wrong length versus wrong
 * alphabet), and a non-hex string simply digests to a hash that matches no row — the 404 path — which
 * is where every other invalid token already lands.
 *
 * NO OTHER FIELD. Not `email`, not `id`, not a `hash`: the token identifies the row, and the row
 * carries the user and the address it was issued for. Accepting an address here would let a caller
 * assert WHICH address a token verifies, which is precisely the check the row's own `email` column
 * exists to make.
 */
final class VerifyEmailRequest extends FormRequest
{
    /**
     * Guest-reachable on purpose — the link is opened from a mail client, frequently in a different
     * browser from the one that registered. The token is the credential, and whether it is a valid one
     * is decided in the service, not here: a FormRequest that answered it would render a 403 for a
     * malformed body, which is the wrong error class.
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
            // FROM THE CONSTANT, like all three siblings. This used to be the literal `'size:64'`,
            // justified as keeping the manifest reading the same as the rule — which is FALSE, and
            // PreviewInvitationRequest's own docblock says so: `kb:dump-form-rules` records the
            // EVALUATED string, so `AcceptInvitationRequest.json` already reads `size:64` from the
            // concatenated form. The literal bought nothing and cost the coupling: changing
            // OpaqueToken::LENGTH would have moved three requests and silently left this one behind,
            // rejecting every token the minter produces.
            'token' => ['bail', 'required', 'string', 'size:'.OpaqueToken::LENGTH],
        ];
    }

    public function token(): string
    {
        return $this->safe()->string('token')->value();
    }
}
