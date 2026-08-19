<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy\OrgOwned;
use Database\Factories\BotStarterQuestionFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One suggested starter question, at a position the operator chose.
 *
 * TENANT-AUTHORED TEXT THAT REACHES A CHAT SURFACE. `question` is rendered as a suggestion chip on
 * hosted chat, inside the widget iframe, and in the mobile app, and it is escaped at every one of
 * them — this model neither sanitizes nor trusts it. It is stored exactly as entered so an operator
 * editing it sees what they typed; the render is where the escaping belongs, because that is the
 * only place that knows which context it is entering.
 *
 * `sort_order` IS ZERO-BASED AND UNIQUE PER BOT. The uniqueness is a database constraint
 * (`bot_starter_questions_org_bot_position`), which means a naive two-statement swap collides on
 * the first UPDATE: reordering is a service operation that writes the whole list through a
 * temporary offset or replaces it inside one transaction. The migration records why the constraint
 * is not `DEFERRABLE`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $bot_id
 * @property string $question
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[ScopedBy(OrganizationScope::class)]
final class BotStarterQuestion extends Model implements OrgOwned
{
    /** @use HasFactory<BotStarterQuestionFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'bot_starter_questions';

    /**
     * `organization_id` and `bot_id` are ABSENT for the reasons BotDomain states: the first is the
     * tenant key, the second is the ownership edge inside the tenant, and neither is a form field.
     *
     * `sort_order` IS fillable, unlike BotDomain's `status`, because the position genuinely is what
     * the operator is setting — it is the entire point of the row's existence beside its siblings.
     * The service still owns the REORDER, because the unique constraint means positions can only be
     * rewritten as a set.
     *
     * @var list<string>
     */
    protected $fillable = ['question', 'sort_order'];

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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
