<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationStatus;
use App\Support\Tenancy\OrgOwned;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tenant root.
 *
 * NO #[ScopedBy(OrganizationScope::class)] HERE, and the absence is correct rather than an
 * oversight: the scope filters an `organization_id` column and this table has none. Authorization
 * for an organization row comes from the membership re-read out of PostgreSQL by the
 * TenantContext middleware, and from OrganizationPolicy.
 *
 * The two embedding columns are finding C1's storage, and the two rerank columns beside them are
 * the same fact for the second surface. All four hold a REFERENCE — a connection id and a model id
 * — and never key material; the credential itself stays envelope-encrypted on
 * `provider_connections` and is decrypted only inside the request that uses it.
 *
 * NO CAST ON ANY OF THE FOUR. `char(26)` and `text` arrive as PHP strings already, and a cast here
 * would be a second declaration of a shape the migration owns. The `@property` block below types
 * them nullable because the columns are nullable, and for the rerank pair the null is a CHOICE
 * rather than an absence — see App\Services\Rerank\RerankDesignation.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property OrganizationStatus $status
 * @property array<string, mixed> $settings
 * @property string|null $embedding_connection_id
 * @property string|null $embedding_model
 * @property string|null $rerank_connection_id
 * @property string|null $rerank_model
 * @property int|null $storage_bytes_quota
 * @property int|null $bots_quota
 * @property int|null $users_quota
 * @property int|null $monthly_tokens_quota
 */
final class Organization extends Model implements OrgOwned
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'organizations';

    /**
     * All FOUR designation columns are ABSENT, and so is every ownership column. Each designation
     * is written only through its own service — App\Services\Embedding\EmbeddingDesignationService
     * and App\Services\Rerank\RerankDesignationService — which validate the pair against this
     * organization's own connections first. A mass-assignable designation would let a PATCH on some
     * unrelated settings form repoint the vector space, or silently move which tenant's provider
     * account sees this tenant's user questions.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'slug', 'status', 'settings'];

    /**
     * THE FOUR QUOTA LIMITS ARE NOT FILLABLE EITHER, AND FOR THE SAME REASON THE DESIGNATIONS ARE
     * NOT. They are written only through App\Services\Quotas\QuotaLimitService, which decides who
     * may RAISE one as opposed to lower it (§6.1 gives limit control to the platform owner; §6.2
     * gives the org owner "manage organization settings" — the migration records the contradiction).
     * A mass-assignable quota would let a PATCH on some unrelated settings form remove the ceiling
     * on an organization's spend, which is the one edit on this row that pays for itself.
     *
     * NULL IN ANY OF THE FOUR MEANS UNLIMITED, AND 0 MEANS NOTHING IS ALLOWED. The `@property`
     * block types them `int|null` because the columns are nullable, and the null is a CHOICE rather
     * than an absence — see App\Enums\QuotaMetric and the migration.
     */

    /**
     * The organization of an Organization is itself. Stated explicitly so OrgScopedPolicy has one
     * argument shape for every record it authorizes.
     */
    public function organizationId(): string
    {
        return $this->id;
    }

    /**
     * @return HasMany<ProviderConnection, $this>
     */
    public function providerConnections(): HasMany
    {
        return $this->hasMany(ProviderConnection::class);
    }

    /**
     * The designated embedding connection, or null when the organization has not designated one
     * and resolution therefore falls to the rule in
     * services/ai-service/app/providers/embedding_selection.py.
     *
     * @return BelongsTo<ProviderConnection, $this>
     */
    public function embeddingConnection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class, 'embedding_connection_id');
    }

    /**
     * The designated rerank connection, or null when the organization has chosen not to rerank.
     *
     * THE NULL MEANS SOMETHING DIFFERENT FROM `embeddingConnection()`'s and the asymmetry is the
     * point: there is no resolve-by-rule for reranking on either side of the seam. Null here is not
     * "somebody else decides", it is "stage 11 does not run" — `rerank_gate` returns
     * `MODEL_NOT_CONFIGURED` before any call goes out and `evidence.select_unranked` serves fused
     * order.
     *
     * @return BelongsTo<ProviderConnection, $this>
     */
    public function rerankConnection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class, 'rerank_connection_id');
    }

    /**
     * REQUIRED BY ROUTE BINDING, exactly as `invitations()` is.
     *
     * `->scopeBindings()` resolves a child through its parent's relation, and the relation name is
     * DERIVED rather than declared: `Model::childRouteBindingRelationshipName()` is
     * `Str::plural(Str::camel($childType))`, so the `{bot}` segment on every admin bot route
     * resolves through THIS method. Without it the nested binding has nothing to scope by and every
     * bot route 404s — including for the organization that owns the row. With it, a bot id
     * belonging to another organization 404s at BINDING time, before any policy is constructed and
     * before the row is in memory, which is the enumeration-safe order.
     *
     * @return HasMany<Bot, $this>
     */
    public function bots(): HasMany
    {
        return $this->hasMany(Bot::class);
    }

    /**
     * REQUIRED BY ROUTE BINDING, not merely convenient. Every admin source route is mounted under
     * `organizations/{organization}` with `->scopeBindings()`, and
     * `Model::childRouteBindingRelationshipName()` is `Str::plural(Str::camel($childType))` — so
     * the `{source}` segment resolves through THIS method. Without it the nested binding has
     * nothing to scope by and every source route 404s, including for the organization that owns the
     * row. With it, a source id belonging to another organization 404s at BINDING time, before any
     * policy is constructed and before the row is in memory, which is the enumeration-safe order.
     *
     * THE SEGMENT IS `{source}` AND NOT `{knowledgeSource}` for exactly that derivation:
     * `{knowledgeSource}` would look for `knowledgeSources()`, which does not exist.
     *
     * @return HasMany<KnowledgeSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(KnowledgeSource::class);
    }

    /**
     * REQUIRED BY ROUTE BINDING, not merely convenient — the same rule `sources()` states one
     * method up. `GET .../conversations/{conversation}` is mounted under
     * `organizations/{organization}` with `->scopeBindings()`, and
     * `Model::childRouteBindingRelationshipName()` is `Str::plural(Str::camel($childType))`, so the
     * `{conversation}` segment resolves through THIS method. Without it the nested binding has
     * nothing to scope by and the transcript route 404s for the organization that owns the thread;
     * with it, a conversation id belonging to another organization 404s at BINDING time, before
     * `ConversationPolicy` is constructed and before the row is in memory.
     *
     * IT IS ALSO THE ONLY RELATION IN THE SIX-TABLE CONVERSATION GRAPH THAT AN ORGANIZATION CAN
     * HAVE. `messages`, `retrieval_traces`, `citations` and `feedback` hold no `organization_id`,
     * so a `hasManyThrough` from here would be a second expression of the join predicate that
     * `EloquentConversationRepository::scopedMessages()` owns — and two expressions of one tenancy
     * rule is how the two drift. Nothing below `conversations` is reachable from this model.
     *
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * @return HasMany<OrganizationUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationUser::class);
    }

    /**
     * REQUIRED BY ROUTE BINDING, not merely convenient. Every admin invitation route is mounted
     * under `organizations/{organization}` with `->scopeBindings()`, which resolves `{invitation}`
     * through *this relation* rather than through a global query. Without it the nested binding has
     * nothing to scope by and every one of those routes 404s — including for the org that owns the
     * row. With it, an invitation id belonging to another organization 404s at binding time, before
     * any policy runs, which is the enumeration-safe order.
     *
     * @return HasMany<OrganizationInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'settings' => 'array',
            // `integer` against `bigint` for two of the four: PHP integers are 64-bit on every
            // platform this runs on, so a byte or token ceiling past 2^31 survives the round trip.
            // Without the cast PDO hands back a STRING for a bigint, and `'2147483648' > 100` is a
            // numeric-string comparison that happens to work while `null` handling does not —
            // a quota read as a string is a quota compared inconsistently.
            'storage_bytes_quota' => 'integer',
            'bots_quota' => 'integer',
            'users_quota' => 'integer',
            'monthly_tokens_quota' => 'integer',
        ];
    }
}
