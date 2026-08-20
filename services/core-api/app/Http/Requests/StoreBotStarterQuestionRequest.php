<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Bots\BotStarterQuestionService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add one suggested starter question to a bot.
 *
 * ── `sort_order` IS NOT A FIELD ON THE CREATE PATH ────────────────────────────────────────────
 *
 * A new question is APPENDED, and the position it lands at is the server's to decide. Two reasons,
 * and the second is the one that matters:
 *
 *   1. `bot_starter_questions_org_bot_position` is UNIQUE per bot and NOT deferrable, so a caller
 *      choosing a position that is already taken is a 23505 rendered as a 500 for a request the
 *      operator has every right to make. Inserting AT a position means shifting the rows after it,
 *      which is a whole-list rewrite; that is what `PATCH` is for, and it is where it lives.
 *   2. The set of positions is an invariant — 0..n-1 with no gaps and no duplicates — and an
 *      invariant that a client can name is an invariant a client can break. Every write on this
 *      surface re-sequences the whole list inside one transaction, so the column is only ever the
 *      server's arithmetic.
 *
 * ── `organization_id` AND `bot_id` ARE ABSENT, FOR THE USUAL REASON AND ONE MORE ──────────────
 *
 * The first comes from the authenticated context and the second from the route. `bot_id` is the
 * ownership edge of this row INSIDE the organization: a fillable one would let a PATCH move a
 * question between two bots of the same tenant, which the composite foreign key cannot object to
 * because both bots belong to that tenant.
 */
final class StoreBotStarterQuestionRequest extends FormRequest
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
     * ── `required` IS WHAT REFUSES A BLANK CHIP, AND IT ONLY WORKS BECAUSE OF TWO MIDDLEWARE ───
     *
     * `bot_starter_questions_not_blank` is `btrim(question) <> ''` in the database, and the reason
     * it exists is that a whitespace-only label renders as a control a user can see, can click, and
     * cannot read. The rule here reaches the same verdict through the global stack: `TrimStrings`
     * turns `"   "` into `""`, `ConvertEmptyStringsToNull` turns that into `null`, and `required`
     * refuses it. That chain is worth writing down because `min:1` — the obvious spelling — would
     * NOT catch it: `"   "` is three characters long.
     *
     * `max:200` matches `placeholder_text`'s bound rather than `description`'s, because a starter
     * question is a CHIP LABEL rendered at `--text-base` on a card pill (kb-ai-chat-ux), not prose.
     * A 2,000-character chip is a rendering nobody has a design for.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'question' => ['bail', 'required', 'string', 'max:'.BotStarterQuestionService::MAX_LENGTH],
        ];
    }

    public function toQuestion(): string
    {
        /** @var array{question: string} $data */
        $data = $this->validated();

        return $data['question'];
    }
}
