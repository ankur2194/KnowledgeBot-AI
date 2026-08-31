<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /rt/v1/conversations` — open a conversation for the bot this session already names.
 *
 * ═══ THERE IS NO `bot_id` FIELD, AND ITS ABSENCE IS THE WHOLE SECURITY PROPERTY ════════════
 *
 * The bot comes from the resolved chat session, which came from a server-side Valkey record written
 * at mint time against an unforgeable `Origin`. A `bot_id` in this body would let any holder of any
 * session open a conversation against ANY bot — including a private one, including one in another
 * organization — and every downstream layer would agree with it, because the row would have told
 * them whose bot it was. `kb-internal-api-contracts` states the rule for the whole surface: a body
 * field naming an org, a bot, or a model is privilege escalation, not configuration.
 *
 * The same goes for the channel, the consent flag and the retention window: all three are resolved
 * server-side, and the first of the three is the one that matters most — `playground` is the channel
 * whose actor type unlocks `retrieval.trace` on the relay.
 *
 * ═══ `locale` IS THE ONE THING THE CLIENT MAY SAY, AND IT IS DISPLAY DATA ══════════════════
 *
 * BCP-47, and it selects nothing: it is stored so an operator reading a transcript knows which
 * language the visitor's browser was in. NULL means "we were not told", which is a real state and
 * different from `en` — so an absent field stays absent rather than being defaulted.
 *
 * The pattern is the same one `conversations_locale_shape` enforces in the database, so a value this
 * rule admits is a value the column will take, and a bound string cannot reach an unbounded `text`
 * column from an unauthenticated surface.
 */
final class StoreRuntimeConversationRequest extends FormRequest
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
            // `nullable` AND `sometimes` TOGETHER. `sometimes` means an absent key is not validated
            // at all; `nullable` means an explicit `null` is accepted rather than failing `regex`.
            // Both spellings mean "we were not told" and a client sending either must not 422.
            //
            // The regex mirrors `conversations_locale_shape` — two or three lower-case letters,
            // then up to three subtags — rather than accepting any string a browser might report.
            // `Accept-Language` is not a locale and is not accepted here.
            'locale' => ['sometimes', 'nullable', 'string', 'max:35', 'regex:/^[a-z]{2,3}(-[A-Za-z0-9]{2,8}){0,3}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'locale.regex' => 'The locale must be a BCP-47 language tag, such as `en` or `en-GB`.',
        ];
    }
}
