<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Kb\PublicBotIdentifier;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /sdk/v1/session` — the loader exchanges a public bot id for a chat-session token.
 *
 * ═══ THE BODY IS THE SHIPPED LOADER'S, NOT A NEW ONE ═══════════════════════════════════════
 *
 * `apps/widget/src/loader/bridge.ts` posts `{bot_id, user_token}` and pinned that shape before this
 * route existed, deliberately: *"a wrong path with a green fixture over it is how 'unreachable'
 * becomes 'confidently wrong'"*. Both names are honoured here rather than renamed to something
 * tidier.
 *
 * ═══ `bot_id` IS THE PUBLIC IDENTIFIER AND IT AUTHORIZES NOTHING ═══════════════════════════
 *
 * It is `bots.public_bot_id` — printed into a customer's page source, bookmarked, cached in a
 * stylesheet URL — and NOT `bots.id`. `PublicBotIdentifier`'s docblock carries the argument: a ULID
 * leaks the creation time of every bot in its leading characters and is the identifier the ADMIN
 * surface authorizes against, so publishing it would mean one leaked widget snippet addresses the
 * configuration endpoint too.
 *
 * Whether the bot answers is the AND of its status, its access mode and the origin allow-list, none
 * of which this string carries. So validation here is a GRAMMAR check and nothing else — an
 * `exists:` rule would query the table with no organization predicate (there is none to have) and
 * would turn the endpoint into an existence oracle over every tenant's bots, distinguishing "this id
 * is real but your domain is not listed" from "this id does not exist". Both must be the same 404.
 *
 * ═══ `user_token` IS ACCEPTED AND IGNORED, AND SAYING SO IS THE POINT ══════════════════════
 *
 * Control 3 — the customer's backend HMAC-signing `{sub,name,email,iat,exp}` with a per-bot shared
 * secret — is OUT OF SCOPE: there is no per-bot secret column and no rotation story for one. The
 * field is validated for shape and discarded, so the shipped loader's body does not 422, and the
 * session is anonymous.
 *
 * IT IS NOT "BEST-EFFORT VERIFIED", AND THAT IS THE DECISION. A half-built verification would be a
 * downgrade to anonymous wearing an authenticated name: identity claims presented as plain loader
 * config are attacker-controlled BY DEFINITION (§8.20), so a token that were parsed but not
 * cryptographically checked is strictly worse than one that is ignored. When control 3 lands, the
 * rule is that a bad signature, `alg: none`, an unknown `kid`, an expired `exp` or an `iat` in the
 * future are each a REJECTION — never a silent downgrade.
 */
final class MintChatSessionRequest extends FormRequest
{
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
            // THE GRAMMAR IS `bots_public_bot_id_shape`'s, byte for byte, and
            // `apps/web/src/app/(chat)/c/[publicBotId]/theme.css/route.ts` refuses to forward
            // anything else as a path segment. Three places, one pattern; a value this rule admits
            // is a value the column will take and the web app will route.
            'bot_id' => [
                'bail',
                'required',
                'string',
                'max:64',
                'regex:/^[A-Za-z0-9_-]{1,64}$/',
            ],

            // ACCEPTED, BOUNDED, AND DISCARDED — see the class docblock. `nullable` because the
            // loader sends an explicit `null` when the customer configured no identity callback, and
            // `sometimes` because a caller that omits it entirely is equally correct.
            //
            // The bound is generous for a compact JWS (header.payload.signature) and is here so an
            // unauthenticated caller cannot post a megabyte to a field nothing reads.
            'user_token' => ['sometimes', 'nullable', 'string', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bot_id.regex' => 'The bot id must be the public identifier from your embed snippet '
                .'(it starts with `'.PublicBotIdentifier::PREFIX.'`).',
        ];
    }
}
