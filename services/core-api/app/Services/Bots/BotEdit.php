<?php

declare(strict_types=1);

namespace App\Services\Bots;

use App\Enums\BotStatus;
use InvalidArgumentException;

/**
 * The validated input to a bot PATCH: the columns the caller named, and only those.
 *
 * ── WHY THIS ONE IS A COLUMN MAP WHEN `ProviderConnectionEdit` IS TYPED MEMBERS ────────────────
 *
 * Because the two endpoints have different problems, and copying the working shape would break
 * this one. `ProviderConnectionEdit` can use `null` to mean "not supplied" precisely because both
 * of its columns are NOT NULL in the schema — the ambiguity that usually makes a nullable-partial
 * DTO a bad idea cannot arise there, and it says so.
 *
 * A bot has TWELVE nullable columns. `description`, `welcome_message`, `system_instruction`,
 * `evidence_threshold`, `rate_limit_per_minute`, `retention_days`, `consent_text`,
 * `provider_model_id` and the rest are all legitimately clearable, so on this endpoint "the field
 * was absent" and "the field was sent as null" are two DIFFERENT instructions and a null member
 * cannot carry both. The alternatives were twenty-four nullable members beside twenty-four
 * booleans — which is the same map with worse ergonomics and one more thing to forget — or a
 * sentinel object, which puts a bespoke Option type in the one layer that should be boring.
 *
 * ── THE ALLOW-LIST IS WHAT MAKES THE MAP SAFE, AND IT IS ENFORCED IN THE CONSTRUCTOR ───────────
 *
 * A bare `array<string, mixed>` reaching a repository is exactly the shape that lets
 * `organization_id`, `public_bot_id` or `retrieval_configuration_version` ride in from request
 * input one careless `+ $request->all()` later. So the type refuses: an unlisted column is an
 * `InvalidArgumentException` at CONSTRUCTION, not a silently ignored key at write time, and the
 * three columns above are absent from `WRITABLE` for the reasons `NewBot` and `App\Models\Bot`
 * record. `Model::shouldBeStrict()` is the second layer, not the first.
 *
 * `status` IS writable here and is not on `NewBot`: a bot is always created `draft`, and every
 * transition — testing, published, paused, archived — is this endpoint. What makes publishing
 * stricter than a rename is not the permission (both are `bots.manage`) but CHECK 5, the publish
 * guard in `BotService`, which `OrgScopedPolicy::permit()` has no argument position for.
 */
final readonly class BotEdit
{
    /**
     * Every column a PATCH may name, and the complete set the repository will write.
     *
     * ORDERED AS THE TABLE DECLARES THEM, so a reader comparing this list against the migration is
     * doing a line-by-line diff rather than a set membership test.
     *
     * @var list<string>
     */
    public const WRITABLE = [
        'name',
        'slug',
        'description',
        'welcome_message',
        'placeholder_text',
        'system_instruction',
        'answer_style_instruction',
        'status',
        'access_mode',
        'provider_connection_id',
        'provider_model_id',
        'answer_mode',
        'dense_top_k',
        'sparse_top_k',
        'rerank_candidates',
        'rerank_retain',
        'evidence_threshold',
        'evidence_threshold_scale',
        'allow_general_answers',
        'theme',
        'rate_limit_per_minute',
        'rate_limit_per_day',
        'retention_days',
        'collect_end_user_data',
        'consent_text',
    ];

    /**
     * The columns whose value decides what RETRIEVAL DOES, and therefore what moves
     * `retrieval_configuration_version`.
     *
     * ── THE BOUNDARY IS NARROWER THAN "EVERY COLUMN THAT AFFECTS AN ANSWER", ON PURPOSE ────────
     *
     * The version exists so a retrieval trace can be REPLAYED against the §21.5 regression gate.
     * The four depths and the evidence pair are the retrieval pipeline's own parameters; the two
     * answer-behaviour fields are here because stage 12's refusal reads both — `answer_mode`
     * decides whether general knowledge is permitted at all and `allow_general_answers` is the
     * escape hatch that lets it happen, so a trace that cannot say which was in force cannot
     * explain a refusal that did or did not occur.
     *
     * `provider_connection_id` and `provider_model_id` are DELIBERATELY NOT HERE. They select which
     * credential generates the answer, which is a property of generation rather than of retrieval —
     * the same twenty candidates come back either way. Folding them in would make every model swap
     * invalidate every cached answer and every stored trace comparison for a pipeline that did not
     * change. The voice fields (`system_instruction`, `answer_style_instruction`) are absent for the
     * same reason one level further out.
     *
     * @var list<string>
     */
    public const RETRIEVAL_KNOBS = [
        'dense_top_k',
        'sparse_top_k',
        'rerank_candidates',
        'rerank_retain',
        'evidence_threshold',
        'evidence_threshold_scale',
        'answer_mode',
        'allow_general_answers',
    ];

    /**
     * @param  array<string, mixed>  $columns  column => value, for the columns the caller NAMED.
     *                                         A key present with a null value means "clear it"; an
     *                                         absent key means "leave it".
     *
     * @throws InvalidArgumentException when a column outside WRITABLE is named
     */
    public function __construct(private array $columns)
    {
        $unknown = array_diff(array_keys($columns), self::WRITABLE);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'BotEdit refuses the column(s) '.implode(', ', $unknown).'. The allow-list is the '
                .'type, not a convention: `organization_id`, `public_bot_id` and '
                .'`retrieval_configuration_version` are absent from it deliberately, and a map that '
                .'accepted an arbitrary key would put all three one careless line of request input '
                .'away from being writable.',
            );
        }
    }

    /**
     * Whether the caller NAMED this column at all — which is a different question from whether it
     * has a value.
     */
    public function names(string $column): bool
    {
        return array_key_exists($column, $this->columns);
    }

    public function valueFor(string $column): mixed
    {
        return $this->columns[$column] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * A PATCH that names nothing is refused by the FormRequest before this class is built; the
     * method exists for the non-HTTP callers a service may eventually gain, and for the assertion
     * that says so.
     */
    public function isEmpty(): bool
    {
        return $this->columns === [];
    }

    /**
     * The status this edit LEAVES the bot in, which is what the publish guard has to be asked
     * about — not the status the request happened to carry.
     *
     * A PATCH that renames a published bot without mentioning `status` is still a write to a
     * published bot, and one that sets `status` to a value it already holds is not a transition.
     * Both readings come out of this one method so no caller has to reconstruct them.
     */
    public function statusAfter(BotStatus $current): BotStatus
    {
        $requested = $this->columns['status'] ?? null;

        return $requested instanceof BotStatus ? $requested : $current;
    }
}
