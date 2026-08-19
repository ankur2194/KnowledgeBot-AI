<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy\OrgOwned;
use Database\Factories\BotFallbackEntryFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One link in a bot's fallback model chain: "if the model before this one could not answer, try
 * this one next".
 *
 * ── THE CLASS IS NOT CALLED `BotFallbackModel`, AND THE TABLE IS STILL `bot_fallback_models` ───
 *
 * `arch()->preset()->laravel()` refuses a `Model` suffix inside App\Models — "BotFallbackModel
 * extends Model" reads as a base class in every call site and in every stack trace. Exactly the
 * situation `ProviderModelEntry` is in, resolved the same way: `$table` is pinned explicitly rather
 * than derived, and `Entry` says what the row actually is.
 *
 * ── WHY THIS IS A MODEL AND NOT A `belongsToMany` PIVOT ───────────────────────────────────────
 *
 * `Bot::fallbackModels()` could have been `belongsToMany(ProviderModelEntry::class,
 * 'bot_fallback_models')->withPivot('position')`, and it would be wrong in a way that reads as
 * correct at the call site. `attach()` writes the two key columns and the pivot extras it is
 * given — and this table needs two more that no caller would think to pass: a ULID PRIMARY KEY, and
 * the DENORMALIZED `organization_id` that both composite foreign keys are built on. The insert
 * would fail on a NOT NULL violation, which is the good case; the bad case is a future
 * `withTimestamps()`-shaped convenience that fills them in from somewhere plausible.
 *
 * A real model also means the row is `#[ScopedBy]`, is addressable by an audit entry, and is
 * authorized like every other tenant-owned record instead of being invisible to the policy layer.
 *
 * ── THE CHAIN IS ORDERED, AND `position` IS UNIQUE PER BOT ────────────────────────────────────
 *
 * `Bot::fallbackModels()` orders by it in the relation, because an unordered fallback chain is not
 * a chain. The same reordering constraint `BotStarterQuestion` has applies here for the same
 * reason.
 *
 * WHETHER A FALLBACK IS ELIGIBLE AT ALL IS NOT THIS ROW'S DECISION. `kb-error-taxonomy` owns which
 * failures may fall back and `kb-provider-adapter-contract` owns whether the next model can serve
 * the request that failed; this row says only what the operator's preference order is. In
 * particular, a chain entry does not license a retry — the provider adapter owns retries, and
 * Laravel never retries its chat call.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $bot_id
 * @property string $provider_model_id
 * @property int $position
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[ScopedBy(OrganizationScope::class)]
final class BotFallbackEntry extends Model implements OrgOwned
{
    /** @use HasFactory<BotFallbackEntryFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'bot_fallback_models';

    /**
     * `organization_id` and `bot_id` are ABSENT for the reasons BotDomain states.
     *
     * `provider_model_id` IS fillable, and it is the one place in this file worth pausing on: it is
     * a foreign key a client supplies. That is safe here and only here because
     * `bot_fallback_models_model_same_org` refuses any value outside the caller's own organization
     * at the database, so the worst a hostile id can do is produce a 23503 — not a cross-tenant
     * row. The service still validates it against the organization's catalogue first, so the
     * operator gets a 422 naming the field rather than a constraint name.
     *
     * @var list<string>
     */
    protected $fillable = ['provider_model_id', 'position'];

    public function organizationId(): string
    {
        return $this->organization_id;
    }

    /**
     * @return BelongsTo<Bot, $this>
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /**
     * @return BelongsTo<ProviderModelEntry, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(ProviderModelEntry::class, 'provider_model_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }
}
