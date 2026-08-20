<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Bots\BotStarterQuestionService;
use App\Services\Bots\StarterQuestionEdit;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit one starter question: its text, its position, or both.
 *
 * ── `sort_order` HERE MEANS "MOVE THIS QUESTION TO POSITION N", NOT "WRITE N INTO THE COLUMN" ──
 *
 * That distinction is the whole reason the reorder lives on this endpoint rather than on a bulk
 * `PUT` of the list. `bot_starter_questions_org_bot_position` is UNIQUE per bot and DELIBERATELY
 * NOT DEFERRABLE — the migration records the trade, and the consequence is that a naive
 * "set this row's sort_order to 2" collides with whichever row already holds 2, as SQLSTATE 23505
 * rendered as a 500. So the service reads the list under a lock, applies the move in memory, and
 * re-sequences every row to 0..n-1 inside one transaction. The column is arithmetic; this field is
 * an intent.
 *
 * ── A BODY THAT NAMES NEITHER FIELD IS REFUSED DECLARATIVELY ──────────────────────────────────
 *
 * `required_without` in both directions, which is the spelling `UpdateProviderConnectionRequest`
 * uses and the one `UpdateBotRequest` could not use because it has twenty-five fields. With two,
 * it is two lines and it is visible to `kb:dump-form-rules`, so the generated client is told the
 * constraint exists (docs/22 finding 19). What it prevents: a request that changes nothing still
 * returning 200, which on a surface that audits its writes would mean a trail describing an edit
 * that did not happen.
 */
final class UpdateBotStarterQuestionRequest extends FormRequest
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
     * `max` ON `sort_order` IS THE PLATFORM CEILING AND NOT THE LIST'S LENGTH, which a rule cannot
     * know: the list belongs to a bot resolved from the route, and reading it here would be a
     * database query inside `rules()` on a request whose authorization has not run yet (finding
     * L5 — validation precedes `Gate::authorize`). The real bound is `count - 1` and it is enforced
     * in `BotStarterQuestionService`, which holds the list under the same lock it writes it with.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // ── `required_without` WITHOUT `sometimes`, AND THE PAIRING IS NOT INTERCHANGEABLE ──
            //
            // `sometimes` SUPPRESSES THE WHOLE RULE SET for a key that is not in the body — including
            // `required_without` itself — so `sometimes` beside it means a request carrying NEITHER
            // field runs neither rule and passes with a 200. That is the exact defect this pair
            // exists to prevent, and it fails silently: the endpoint returns the unedited row and
            // writes an audit entry claiming an edit happened. `UpdateProviderConnectionRequest`
            // records the same finding on its own two fields.
            //
            // Without `sometimes`, an absent key still skips `string`, `integer`, `max` and the
            // rest, because a NON-IMPLICIT rule does not run against an attribute that is not
            // there. `required_without` is implicit and does run, which is the whole point.
            //
            // `min:1` IS NOT NEEDED ON `question` AND ITS ABSENCE IS NOT THE HOLE IT WAS THERE:
            // TrimStrings plus ConvertEmptyStringsToNull turn `"   "` into null before this class
            // sees it, and `string` refuses a null — so a blank chip is refused whether or not the
            // sibling is present, which is what `bot_starter_questions_not_blank` requires.
            'question' => [
                'bail',
                'required_without:sort_order',
                'string',
                'max:'.BotStarterQuestionService::MAX_LENGTH,
            ],
            'sort_order' => [
                'bail',
                'required_without:question',
                'integer',
                'min:0',
                'max:'.(BotStarterQuestionService::MAX_PER_BOT - 1),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $both = 'An edit has to name the question, its position, or both. A request that changes '
            .'nothing would still return 200 and would still claim an edit happened.';

        return [
            'question.required_without' => $both,
            'sort_order.required_without' => $both,
            'question.string' => 'A starter question needs text. A blank chip is a control an end '
                .'user can see, can click, and cannot read — which is why the database refuses it '
                .'too (`bot_starter_questions_not_blank`). An all-whitespace value arrives here as '
                .'null, which is why this reads as a type error rather than as a missing field.',
        ];
    }

    public function toData(): StarterQuestionEdit
    {
        /** @var array{question?: string, sort_order?: int} $data */
        $data = $this->validated();

        return new StarterQuestionEdit(
            question: $data['question'] ?? null,
            position: $data['sort_order'] ?? null,
        );
    }
}
