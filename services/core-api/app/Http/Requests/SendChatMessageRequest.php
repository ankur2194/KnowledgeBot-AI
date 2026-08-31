<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /rt/v1/conversations/{conversation}/messages` — the PUBLIC chat body.
 *
 * ═══ EXACTLY TWO KEYS, AND THE SHAPE IS PINNED SOMEWHERE ELSE ══════════════════════════════
 *
 * `{client_message_id, content}` and nothing else, per `kb-internal-api-contracts` ->
 * `references/public-chat-request-body.md`. Three shipped clients generate their type from
 * `packages/contracts/src/chat.ts`, which carries the same two fields, and both stream
 * implementations post exactly this body.
 *
 * ═══ `content`, NEVER `text` — AND THE FAILURE IS PER-CLIENT AND INVISIBLE ═════════════════
 *
 * `text` is the `token` EVENT's field name. One word meaning "the whole question" on the request and
 * "one delta" on the response is how a client ends up sending a token frame's shape, so it is not
 * accepted as an alias. A client that posts `text` gets a 422 with
 * `errors: {"content": ["The content field is required."]}` on EVERY send — a total outage for that
 * one client, invisible to the other two and to any suite that exercises only the surface that
 * happens to be right. That is the documented failure this file exists to make loud.
 *
 * `content` is also the vocabulary of `messages.content` (docs/11 §16) and of the provider adapter's
 * `Message.content`, so one name survives browser -> Laravel -> FastAPI -> provider -> row. The
 * INTERNAL body renames it to `query`, once, in `InternalAiClient::openChatStream()`; neither name
 * is an alias for the other.
 *
 * ═══ `client_message_id` IS THE IDEMPOTENCY FINGERPRINT AND SELECTS NOTHING ════════════════
 *
 * A client-minted ULID, stable across re-renders and retries OF THE SAME COMPOSED MESSAGE — not per
 * render. It is the fingerprint half of `chat.message`'s idempotency key and the durable half is
 * `messages.client_message_id` under `messages_conversation_client_message_unique`, so a double
 * submit collapses into one turn, one provider call and one bill instead of two.
 *
 * IT AUTHORIZES NOTHING AND ADDRESSES NOTHING. It is scoped to a conversation the caller has already
 * proved they own, so a guessed value collides at worst with the caller's own previous message.
 *
 * There is deliberately NO client-supplied `Idempotency-Key` HEADER on this surface: the id in the
 * body is the whole identity, and `apps/widget/src/app/stream.ts` says so from the other side.
 *
 * ═══ AN UNKNOWN KEY IS A 422, AND THAT IS NOT LARAVEL'S DEFAULT ════════════════════════════
 *
 * `extra="forbid"` is the far side's setting and this is its counterpart. Laravel IGNORES unknown
 * keys, so without the check below a client that renamed a field would have it silently dropped and
 * would see a plausible answer to a different question — which is `pydantic-contracts`' own argument
 * for `forbid`, arriving one plane earlier.
 *
 * IT IS IN `withValidator()` AND THEREFORE NOT IN THE DUMPED MANIFEST. `rules()` is the only thing
 * `kb:dump-form-rules` executes, and Laravel has no rule that expresses "no other keys" — so
 * `packages/contracts/rules/SendChatMessageRequest.json` describes the two fields and not the
 * closure. Recorded here rather than left to be discovered: a client generated from the manifest
 * alone will not know that a third key is refused.
 */
final class SendChatMessageRequest extends FormRequest
{
    /**
     * The keys this body may carry. ONE LIST, used by both `rules()` and the unknown-key check, so
     * the two cannot disagree about what "extra" means.
     *
     * @var list<string>
     */
    public const KEYS = ['client_message_id', 'content'];

    /**
     * Authorization is `ChatGate` in the controller, not here. `FormRequest::authorize()` runs
     * BEFORE validation, so a check placed in it decides on unvalidated input — and it cannot reach
     * checks 5 and 6 (entity status, rate limit and quota) at all, which on this surface are the two
     * that cost money.
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
            // ULID, 26 CHARACTERS OF CROCKFORD BASE32. `Str::isUlid()` is the framework's own check
            // and it is what `messages_client_message_id_shape` mirrors in the database, so a value
            // this rule admits is a value the column will take.
            'client_message_id' => ['bail', 'required', 'string', 'ulid'],

            // `required` IS WHAT REFUSES A WHITESPACE-ONLY QUESTION, and it only works because of the
            // global middleware chain: `TrimStrings` turns `"   "` into `""`,
            // `ConvertEmptyStringsToNull` turns that into `null`, and `required` refuses it. `min:1`
            // — the obvious spelling — would NOT catch it, because `"   "` is three characters long.
            //
            // `max` IS THE WIRE CEILING AND NOT THE PRODUCT RULE. `ChatExecuteRequest.query` admits
            // 32 000 characters, so anything longer is a 422 from the far side for a body this side
            // accepted — a refusal the visitor cannot act on. The PRODUCT rule is
            // `kb.retrieval.question_max_chars` (4 000), which the far side applies at stage 1 as a
            // cap rather than a refusal, so a long question is trimmed for retrieval and still
            // answered. Enforcing the product rule HERE would turn that trim into a rejection.
            'content' => ['bail', 'required', 'string', 'max:32000'],
        ];
    }

    /**
     * Refuse any key outside `KEYS` — the counterpart of the far side's `extra="forbid"`.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // `$this->all()` AND NOT `$this->validated()`: the point is to see what the caller
            // actually sent, and `validated()` has already discarded it. Route parameters are not in
            // `all()` for a JSON body, so `{conversation}` cannot trip this.
            $unknown = array_diff(array_keys($this->all()), self::KEYS);

            foreach ($unknown as $key) {
                // KEYED ON THE OFFENDING FIELD so the `errors` map is renderable against it. A key
                // that matches no rendered field must still surface somewhere — an error nobody can
                // display is an infinite retry loop the user drives by hand.
                //
                // THE KEY IS ECHOED AND IS BOUNDED FIRST. It is attacker-supplied and lands in a
                // response body; 64 characters is longer than any field this contract has.
                $validator->errors()->add(
                    mb_substr((string) $key, 0, 64),
                    'This field is not part of a chat message. Send only `client_message_id` and '
                    .'`content`.',
                );
            }
        });
    }
}
