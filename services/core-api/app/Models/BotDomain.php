<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BotDomainStatus;
use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy\OrgOwned;
use Database\Factories\BotDomainFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a bot's widget origin allow-list.
 *
 * THE ROW IS A SECURITY CONTROL. It is what lets a page on the public internet boot a chat widget
 * that speaks with this organization's credential, on this organization's corpus, against this
 * organization's quota. Two properties keep that honest and both are enforced below the service
 * layer: the origin is an EXACT string with no wildcard grammar (`bot_domains_origin_exact`), and
 * the row cannot name a bot in another organization (`bot_domains_bot_same_org`).
 *
 * `origin` IS FILLABLE AND `status` IS NOT, and that asymmetry is the design. An operator types an
 * origin; whether that origin is under their control is not something a form knows, so a row starts
 * at `pending` and is promoted by a verification path that has to run server-side. Making `status`
 * mass-assignable would let the same form that entered an unverified origin mark it verified, which
 * collapses the two steps into one and removes the only thing standing between a typo and an open
 * grant on somebody else's domain.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $bot_id
 * @property string $origin
 * @property BotDomainStatus $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[ScopedBy(OrganizationScope::class)]
final class BotDomain extends Model implements OrgOwned
{
    /** @use HasFactory<BotDomainFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'bot_domains';

    /**
     * `organization_id` and `bot_id` are both ABSENT.
     *
     * The first for the usual reason — over-posting a tenant key is an authorization bug with a 200
     * response. The second because it is the OWNERSHIP EDGE of this row within the organization: a
     * fillable `bot_id` would let a PATCH move a verified origin from one bot to another inside the
     * same tenant, which the composite foreign key cannot object to because both bots belong to
     * that tenant. It comes from the route, which nests this row under its bot.
     *
     * `status` is absent because promotion is a verification step, not a form field. See the class
     * docblock.
     *
     * @var list<string>
     */
    protected $fillable = ['origin'];

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
            'status' => BotDomainStatus::class,
        ];
    }
}
