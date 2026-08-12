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
 * The two embedding columns are finding C1's storage. They hold a REFERENCE — a connection id and
 * a model id — and never key material; the credential itself stays envelope-encrypted on
 * `provider_connections` and is decrypted only inside the request that uses it.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property OrganizationStatus $status
 * @property array<string, mixed> $settings
 * @property string|null $embedding_connection_id
 * @property string|null $embedding_model
 */
final class Organization extends Model implements OrgOwned
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'organizations';

    /**
     * `embedding_connection_id` and `embedding_model` are ABSENT, and so is every ownership
     * column. The designation is written only through
     * App\Services\Embedding\EmbeddingDesignationService, which validates the pair against this
     * organization's own connections first; a mass-assignable designation would let a PATCH on
     * some unrelated settings form repoint the vector space.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'slug', 'status', 'settings'];

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
     * @return HasMany<OrganizationUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationUser::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'settings' => 'array',
        ];
    }
}
