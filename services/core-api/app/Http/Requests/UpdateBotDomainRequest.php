<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\BotDomainStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Move one allow-list entry between `pending`, `active` and `disabled`.
 *
 * ── `origin` IS NOT EDITABLE, AND THE ABSENCE OF THE FIELD IS THE ENFORCEMENT ─────────────────
 *
 * There is no rule for it here, no argument for it in `BotDomainService::changeStatus()`, and no
 * assignment to the column outside the create path — three layers, because a missing validation
 * rule is one careless line away from coming back. Editing an origin IN PLACE would move a grant:
 * a row that an operator (or a future verification pass) promoted to `active` for `https://a.example`
 * would silently become an active grant for `https://b.example`, carrying the promotion with it.
 * Remove the row and add the other one; the audit trail then says both things happened.
 *
 * ── WHY THIS ENDPOINT EXISTS AT ALL, GIVEN WHAT `App\Models\BotDomain` SAYS ───────────────────
 *
 * That model keeps `status` out of `$fillable` because "promotion is a verification step, not a
 * form field", and this request is the promotion. The two are not in conflict and the distinction
 * is worth stating precisely, because it is the kind of thing that reads as a loophole later:
 *
 *   what the model refuses   ONE request that both enters an origin and marks it usable. That is
 *                            what `$fillable` controls, and it stays refused: `StoreBotDomainRequest`
 *                            has no `status` field and a created row is always `pending`.
 *   what this request is     A SECOND, deliberate, separately-audited action against a row that
 *                            already exists, taken by a caller holding `bots.manage`, which writes
 *                            a `bot.domain.status_changed` row naming the actor, the origin and
 *                            both statuses.
 *
 * WHAT IS STILL MISSING, NAMED RATHER THAN LEFT TO BE DISCOVERED: nobody has proved that the
 * operator controls the origin. An automated proof — a DNS TXT record or a well-known path — is the
 * thing that would make `active` mean "verified" rather than "confirmed by an administrator of this
 * organization". It does not exist yet, and this endpoint is not it. What bounds the gap today is
 * that a `bot_domains` row grants nothing until a PUBLIC RUNTIME SURFACE exists to read it, and
 * none does; when one lands, whoever builds it should read this paragraph and decide whether an
 * operator confirmation is still the right bar.
 */
final class UpdateBotDomainRequest extends FormRequest
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
     * `required` AND NOT `sometimes|required`, because `status` is the whole of the body. A PATCH
     * that named nothing would return 200 and write a `bot.domain.status_changed` audit row
     * describing a change that did not happen — the same defect `BotController::update()` refuses
     * an empty body for, expressible declaratively here because there is only one field.
     *
     * The vocabulary comes from `BotDomainStatus::values()` and never from a literal list, so the
     * rule, the enum and `bot_domains_status_check` cannot drift apart.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['bail', 'required', 'string', Rule::in(BotDomainStatus::values())],
        ];
    }

    public function toStatus(): BotDomainStatus
    {
        /** @var array{status: string} $data */
        $data = $this->validated();

        return BotDomainStatus::from($data['status']);
    }
}
