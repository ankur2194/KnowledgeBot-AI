<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BotAccessMode;
use App\Enums\BotAnswerMode;
use App\Enums\BotStatus;
use App\Enums\EvidenceThresholdScale;
use App\Models\Scopes\OrganizationScope;
use App\Support\Casts\JsonObjectCast;
use App\Support\Tenancy\OrgOwned;
use Database\Factories\BotFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One bot: the retrieval scope, the voice, and the channel configuration (docs/02 §8.3).
 *
 * THIS ROW'S IDENTITY IS A TERM IN EVERY VECTOR QUERY. `bot_ids` is one of the four mandatory
 * Qdrant filter terms (kb-tenancy-isolation NN3), so a bot resolved out of the wrong organization
 * does not produce a wrong answer — it produces a correct-looking answer citing a document the
 * organization never uploaded, at normal latency, with a 200. `#[ScopedBy]` is the backstop; the
 * mechanism is the explicit `forOrg($orgId)` argument every repository method takes.
 *
 * ── THE PUBLIC IDENTIFIER IS NOT THE ROUTE KEY, AND `getRouteKeyName()` IS NOT OVERRIDDEN ─────
 *
 * `public_bot_id` addresses this bot on the UNAUTHENTICATED surfaces — hosted chat, the widget
 * bootstrap, the theme stylesheet. `id` addresses it on the admin surface, where the route nests
 * under `{organization}` with `->scopeBindings()` so a foreign id 404s at BINDING time, before any
 * policy runs and before the row is in memory (laravel-rbac-policies).
 *
 * Overriding `getRouteKeyName()` to return `public_bot_id` would make ONE key serve both, and that
 * is precisely the merge this schema exists to prevent: the public token would then be the string
 * the admin surface authorizes against, so one leaked widget snippet would address the
 * configuration endpoint too. The public surfaces resolve their own binding explicitly, in Phase
 * B's runtime controllers, and they resolve it with an organization-agnostic lookup ON PURPOSE —
 * there is no organization in hand at that point, which is exactly why the token is globally
 * unique and opaque.
 *
 * ── WHAT IS DELIBERATELY NOT AN ACCESSOR HERE ─────────────────────────────────────────────────
 *
 * There is no `isReachable()` on this model. Reachability is the AND of the status, the access
 * mode, the origin allow-list and the surface, and three of those four live somewhere else; an
 * accessor that answered it from this row alone would be a check that looks complete and consults
 * one term. Each enum answers its own half (`BotStatus::isRetrievable()`,
 * `BotAccessMode::allowsAnonymous()`, `BotDomainStatus::permitsEmbedding()`) and the channel
 * boundary ANDs them where all four are in scope.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $public_bot_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $welcome_message
 * @property string|null $placeholder_text
 * @property string|null $system_instruction
 * @property string|null $answer_style_instruction
 * @property BotStatus $status
 * @property BotAccessMode $access_mode
 * @property string|null $provider_connection_id
 * @property string|null $provider_model_id
 * @property BotAnswerMode $answer_mode
 * @property int $dense_top_k
 * @property int $sparse_top_k
 * @property int $rerank_candidates
 * @property int $rerank_retain
 * @property float|null $evidence_threshold
 * @property EvidenceThresholdScale|null $evidence_threshold_scale
 * @property int $retrieval_configuration_version
 * @property bool $allow_general_answers
 * @property array<string, mixed> $theme
 * @property int|null $rate_limit_per_minute
 * @property int|null $rate_limit_per_day
 * @property int|null $retention_days
 * @property bool $collect_end_user_data
 * @property string|null $consent_text
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[ScopedBy(OrganizationScope::class)]
final class Bot extends Model implements OrgOwned
{
    /** @use HasFactory<BotFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'bots';

    /**
     * `organization_id` is ABSENT on purpose, and so is `public_bot_id`.
     *
     * The first is the column that decides which tenant owns this row: over-posting a tenant key is
     * an authorization bug with a 200 response, and `Model::shouldBeStrict()` turns the silent drop
     * into an exception rather than a shrug (laravel-rbac-policies NN5). The repository assigns it
     * explicitly from its own `$organizationId` argument, which is the only place it can be set.
     *
     * The second is absent for a different reason and it is worth stating separately, because a
     * future reader will read the paragraph above and conclude the rule is only about tenancy.
     * `public_bot_id` is the token every published widget snippet, every hosted-chat bookmark and
     * every cached theme stylesheet already carries. A client that could set it could COLLIDE with
     * another organization's token — the unique index would refuse the write, which turns the
     * column into an existence oracle over the whole platform — and a client that could CHANGE it
     * would break every live embed on the customer's own site with a 200. It is minted server-side
     * once and is not editable at all.
     *
     * `retrieval_configuration_version` is absent for a third reason: it is DERIVED. The service
     * bumps it when it writes a retrieval knob, and a client that could set it could make two
     * different configurations claim the same version, which is precisely the identity the §21.5
     * regression gate replays against.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name', 'slug', 'description',
        'welcome_message', 'placeholder_text', 'system_instruction', 'answer_style_instruction',
        'status', 'access_mode',
        'provider_connection_id', 'provider_model_id',
        'answer_mode', 'dense_top_k', 'sparse_top_k', 'rerank_candidates', 'rerank_retain',
        'evidence_threshold', 'evidence_threshold_scale',
        'allow_general_answers', 'theme',
        'rate_limit_per_minute', 'rate_limit_per_day',
        'retention_days', 'collect_end_user_data', 'consent_text',
    ];

    public function organizationId(): string
    {
        return $this->organization_id;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<ProviderConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class, 'provider_connection_id');
    }

    /**
     * The PRIMARY model. The chain that replaces it when a call fails is `fallbackModels()`, and
     * the two are separate relations because they are separate decisions — see BotFallbackEntry.
     *
     * @return BelongsTo<ProviderModelEntry, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(ProviderModelEntry::class, 'provider_model_id');
    }

    /**
     * The widget origin allow-list.
     *
     * Unordered on purpose: an allow-list is a SET, and any ordering imposed here would be read as
     * a precedence that does not exist. The lookup is an exact match, never a first-match walk.
     *
     * @return HasMany<BotDomain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(BotDomain::class);
    }

    /**
     * The starter questions, IN THE ORDER THE OPERATOR SET.
     *
     * Ordered in the relation rather than at the call site because `sort_order` is the only reason
     * the column exists, and a caller that forgot the `orderBy` would render a list whose order
     * depends on the planner — a rendering that differs between two reads of an unchanged set.
     * `Model::shouldBeStrict()` forbids lazy loading, so this relation has to exist before
     * `with('starterQuestions')` can be written at all.
     *
     * @return HasMany<BotStarterQuestion, $this>
     */
    public function starterQuestions(): HasMany
    {
        return $this->hasMany(BotStarterQuestion::class)->orderBy('sort_order');
    }

    /**
     * The fallback model chain, in order.
     *
     * A `HasMany` over a real model rather than a `belongsToMany` pivot, and the reason is in
     * BotFallbackEntry's docblock: `attach()` writes neither the ULID primary key nor the
     * denormalized `organization_id` that both composite foreign keys need, so the pivot spelling
     * produces rows the schema refuses while reading as correct at the call site.
     *
     * @return HasMany<BotFallbackEntry, $this>
     */
    public function fallbackModels(): HasMany
    {
        return $this->hasMany(BotFallbackEntry::class)->orderBy('position');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BotStatus::class,
            'access_mode' => BotAccessMode::class,
            'answer_mode' => BotAnswerMode::class,
            // The scale is an ENUM CAST and not a string, so a value the database would accept but
            // this application has no meaning for cannot be read back silently. `uncalibrated` is
            // the case in point: a real member of the data plane's RerankScale, deliberately not a
            // case here, and refused by `bots_evidence_threshold_scale_check` as well.
            'evidence_threshold_scale' => EvidenceThresholdScale::class,
            'dense_top_k' => 'integer',
            'sparse_top_k' => 'integer',
            'rerank_candidates' => 'integer',
            'rerank_retain' => 'integer',
            // `float` AND NOT `decimal:n`, which is the OPPOSITE call ProviderModelEntry makes for
            // its prices — and the difference is what the number is for. A price is an exact
            // decimal quantity that gets summed over a month, so a binary float drifts by an amount
            // nobody can reproduce. A threshold is a CUTOFF compared once against a score the
            // provider returned as an IEEE 754 double; representing it as a decimal string would
            // force a conversion back to a double at every comparison and would claim a precision
            // the score itself does not have. `double precision` in the column, `float` here.
            'evidence_threshold' => 'float',
            'retrieval_configuration_version' => 'integer',
            'allow_general_answers' => 'boolean',
            // JsonObjectCast AND NOT `array`, and the difference is the empty case. PHP cannot
            // tell an empty array from an empty map, so the built-in cast writes the unthemed
            // majority of bots as `[]` — a JSON ARRAY — while every themed bot writes as `{}`. One
            // column, two JSON types, decided by whether anybody has themed the bot.
            // `bots_theme_vocabulary` refuses the array spelling outright, so with the built-in
            // cast every unthemed bot is rejected by the database. The cast class records the
            // rest, including why AsArrayObject was not the answer.
            'theme' => JsonObjectCast::class,
            'rate_limit_per_minute' => 'integer',
            'rate_limit_per_day' => 'integer',
            'retention_days' => 'integer',
            'collect_end_user_data' => 'boolean',
        ];
    }
}
