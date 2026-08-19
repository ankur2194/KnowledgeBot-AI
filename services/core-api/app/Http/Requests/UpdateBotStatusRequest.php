<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\BotStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Move one bot between lifecycle states.
 *
 * ── WHY THE TRANSITION LEFT `UpdateBotRequest` ────────────────────────────────────────────────
 *
 * `status` used to be one of twenty-five optional fields on the bot PATCH, and it was the only one
 * of them that decides whether an END USER can reach the bot at all. Two doors to one column is
 * two places a check has to be, and the interesting property of this particular column is that its
 * guard is not a permission — both routes are `bots.manage` — but CHECK 5, entity status, which
 * `OrgScopedPolicy::permit()` has no argument position for. A transition endpoint is where that
 * check is impossible to miss.
 *
 * The PATCH now declares `status` as `missing` rather than simply dropping the rule, and the
 * difference matters: an absent rule means `validated()` SILENTLY DISCARDS the field, so a client
 * that had not been updated would publish a bot, receive a 200, and find it still in `draft`. The
 * rule is a 422 naming this endpoint. It is `missing` and NOT `prohibited` because `prohibited`
 * does not mean "must not be present" — it passes for `null`, `""` and `[]`, which is precisely
 * what a stale client emits for a cleared control; `UpdateBotRequest`'s rule set carries the
 * measurement.
 *
 * ── THE GUARD IS NOT HERE, AND IT CANNOT BE ───────────────────────────────────────────────────
 *
 * `rules()` sees a status and nothing else. Whether the bot may HOLD that status depends on the
 * row — its model selection, its answer mode, whether it is archived — and on the state the write
 * LEAVES it in rather than on the transition, which is what makes "clear the model on a published
 * bot" refuse by the same check that refuses "publish a model-less draft". All of it lives in
 * `BotService::assertPublishable()`, reached through `BotService::transition()`, so this endpoint
 * and the PATCH are judged by one implementation of the rule.
 */
final class UpdateBotStatusRequest extends FormRequest
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
     * The full vocabulary from `BotStatus::values()` and never a literal list, so this rule,
     * the enum and `bots_status_check` cannot drift.
     *
     * `archived` IS ADMITTED HERE, and it is terminal: `BotService` refuses every subsequent write
     * to an archived bot, including one that would change the status back, because an archived
     * bot's configuration is the record of what answered the conversations it produced. Refusing
     * the value at this layer instead would make archiving unreachable; refusing the RETURN is the
     * rule, and it belongs where the stored row can be seen.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['bail', 'required', 'string', Rule::in(BotStatus::values())],
        ];
    }

    public function toStatus(): BotStatus
    {
        /** @var array{status: string} $data */
        $data = $this->validated();

        return BotStatus::from($data['status']);
    }
}
