<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy\OrgOwned;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONE GRANT: this bot may answer from this source.
 *
 * ── THIS IS THE ONE ROW IN THE SCHEMA THAT CAN SPAN TWO ORGANIZATIONS ────────────────────────
 *
 * `bot_id` and `source_id` each inherit their own organization and nothing in the foreign-key graph
 * forces them to agree (kb-tenancy-isolation NN2). What forces them is the DENORMALIZED
 * `organization_id` on this row plus the two composite keys `bot_source_assignments_bot_same_org`
 * and `bot_source_assignments_source_same_org`, which both reference `(organization_id, id)` of
 * their parent — so this row's tenant must match the bot's AND the source's, which is only possible
 * when the bot and the source match each other.
 *
 * A MIS-SCOPED ROW IS NOT A BUG THE TENANT FILTER CATCHES; IT IS A BUG THE TENANT FILTER ENFORCES.
 * `bot_ids` is one of the four mandatory Qdrant filter terms and is resolved from this table, so one
 * wrong row makes a correctly-filtered query return another organization's documents at normal
 * latency with a well-formed citation and an HTTP 200.
 *
 * ── WHY THIS IS A MODEL AND NOT A `belongsToMany` PIVOT ──────────────────────────────────────
 *
 * `Bot::sources()` could have been `belongsToMany(KnowledgeSource::class, 'bot_source_assignments')
 * ->withPivot('priority', 'enabled')`, and it would be wrong in a way that reads as correct at the
 * call site. `attach()` writes the two key columns and the pivot extras it is given — and this table
 * needs two more that no caller would think to pass: a ULID PRIMARY KEY, and the denormalized
 * `organization_id` that both composite keys are built on. The insert would fail on a NOT NULL
 * violation, which is the good case; the bad case is a future `withTimestamps()`-shaped convenience
 * that fills them in from somewhere plausible — and "somewhere plausible" here means inferring the
 * tenant from one of the two sides, which is exactly the inference that makes the guard untestable.
 *
 * The identical argument is on `BotFallbackEntry`, one table over. This one has higher stakes: a
 * fallback entry that named another tenant's model row would bill the wrong account, and a source
 * assignment that names another tenant's source discloses their documents.
 *
 * ── `organization_id` IS NOT FILLABLE AND IS NOT INFERRED ────────────────────────────────────
 *
 * The writer states it and both keys check it. `KnowledgeSourceFactory::assignedTo()` states it
 * explicitly for the same reason and its docblock says why: if the fixture inferred it, then
 * `crossOrg()` — the state that exists only to be REFUSED — would be inexpressible, and the guard
 * would have nothing testing it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $bot_id
 * @property string $source_id
 * @property int $priority
 * @property bool $enabled
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[ScopedBy(OrganizationScope::class)]
final class BotSourceAssignment extends Model implements OrgOwned
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<self>> */
    use HasFactory;

    use HasUlids;

    protected $table = 'bot_source_assignments';

    /**
     * `organization_id`, `bot_id` AND `source_id` are all ABSENT.
     *
     * The first for the usual reason — over-posting a tenant key is an authorization bug with a 200
     * response. The other two because they are THE GRANT ITSELF: a fillable `bot_id` would let a
     * PATCH move a live assignment from one bot to another inside the same tenant, which neither
     * composite key can object to because both bots belong to that tenant. They come from the
     * route and from the validated request body, and the service writes them once at creation.
     *
     * `priority` and `enabled` are the only two things about this row an operator edits.
     *
     * @var list<string>
     */
    protected $fillable = ['priority', 'enabled'];

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
     * @return BelongsTo<Bot, $this>
     */
    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    /**
     * @return BelongsTo<KnowledgeSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'source_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'enabled' => 'boolean',
        ];
    }
}
