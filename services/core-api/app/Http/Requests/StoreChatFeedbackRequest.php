<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\FeedbackRating;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /rt/v1/messages/{message}/feedback` — a visitor's verdict on one answer.
 *
 * ═══ THE COMMENT IS FREE TEXT FROM A STRANGER ON SOMEBODY ELSE'S WEBSITE ═══════════════════
 *
 * It is the only free-text column on this surface that is not a question, and it goes into a `text`
 * column with no length of its own. `feedback_comment_bounded` caps it at 4 000 characters in the
 * database; this is the layer that turns the same limit into a 422 with a sentence rather than a
 * CHECK violation rendered as a 500.
 *
 * IT IS NEVER RENDERED AS HTML BY ANYTHING THIS SERVICE OWNS, and that is not this file's guarantee
 * to make: the admin console renders stored conversation content through the SAME sanitizer as the
 * widget, deliberately, because an administrator reading a stored conversation is reading
 * attacker-controlled text in a session with real privileges (`kb-security-baseline` §18.9). No
 * escaping happens here — escaping at the boundary and then again at the renderer is how a comment
 * ends up displaying `&amp;lt;`.
 *
 * ═══ THE SUBMITTER IS NOT A FIELD ═════════════════════════════════════════════════════════
 *
 * `submitted_by_session` and `submitted_by_user_id` come from the resolved session, and
 * `feedback_submitter_exclusive` refuses a row carrying both or neither. A body field naming either
 * would let one visitor cast a verdict as another, which is the cheapest possible way to move a
 * satisfaction metric.
 */
final class StoreChatFeedbackRequest extends FormRequest
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
            // FROM THE ENUM, never a literal list. `feedback_rating_check` is generated from
            // `FeedbackRating::values()` too, so a third rating added in PHP reaches the rule and the
            // constraint in one edit — and a value spelled out here would silently stop matching the
            // day one is renamed.
            'rating' => ['bail', 'required', 'string', Rule::in(FeedbackRating::values())],

            // `nullable` AND `sometimes`: most thumbs carry no comment, and an empty box must CLEAR
            // a previous comment rather than store `''`. `ConvertEmptyStringsToNull` turns the empty
            // box into `null` before this rule sees it, and `feedback_comment_not_blank` refuses the
            // `''` spelling in the database — so there is exactly one way to say "no comment".
            'comment' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ];
    }
}
