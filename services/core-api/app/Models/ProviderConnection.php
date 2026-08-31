<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Provider;
use App\Enums\ProviderConnectionStatus;
use App\Models\Scopes\OrganizationScope;
use App\Support\Crypto\BinaryCast;
use App\Support\Tenancy\OrgOwned;
use Database\Factories\ProviderConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One stored provider credential.
 *
 * THE PLAINTEXT IS NOT ON THIS MODEL AND THERE IS NO ACCESSOR FOR IT. Decryption lives in
 * App\Support\Crypto\CredentialVault and returns into a local that dies with the method that asked;
 * an accessor here would put the key one `->toArray()`, one `dd()`, one exception trace and one
 * queued-job payload away from a log. `$hidden` is a second belt on the same trousers, not the
 * mechanism.
 *
 * `last_four` is the ONLY derived form of the key that may be rendered anywhere, and it is stored
 * rather than computed so that rendering it never requires a decryption.
 *
 * @property string $id
 * @property string $organization_id
 * @property Provider $provider
 * @property string $label
 * @property int $key_version
 * @property int $credential_version
 * @property string $last_four
 * @property ProviderConnectionStatus $status
 *
 * THE TWO CIPHERTEXT PROPERTIES ARE ANNOTATED, AND ANNOTATING THEM IS NOT A RELAXATION.
 * They are `bytea` columns holding the SEALED credential and the WRAPPED data key — neither is the
 * plaintext, and neither is usable without the KEK. They are read in exactly one place,
 * `EloquentProviderConnectionRepository::sealedFor()`, which hands them to the one method permitted
 * to open an envelope; the alternative to declaring them was `getAttribute()`, which returns
 * `mixed` and would have silenced the analyser by making the read UNTYPED rather than by describing
 * it. `$hidden` still keeps both out of `toArray()`, which is what stops them reaching a response.
 * @property string $credential_ciphertext
 * @property string $data_key_ciphertext
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[ScopedBy(OrganizationScope::class)]
final class ProviderConnection extends Model implements OrgOwned
{
    /** @use HasFactory<ProviderConnectionFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'provider_connections';

    /**
     * `organization_id` is ABSENT on purpose. Over-posting a tenant key is an authorization bug
     * with a 200 response, and Model::shouldBeStrict() turns the silent drop into an exception
     * (laravel-rbac-policies NN5). The ciphertext columns are absent for the same reason in the
     * other direction: they are written only by the vault.
     *
     * @var list<string>
     */
    protected $fillable = ['provider', 'label', 'status'];

    /** @var list<string> */
    protected $hidden = ['credential_ciphertext', 'data_key_ciphertext'];

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
     * @return HasMany<ProviderModelEntry, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(ProviderModelEntry::class, 'provider_connection_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'status' => ProviderConnectionStatus::class,
            'key_version' => 'integer',
            // TWO DIFFERENT NUMBERS, AND NEITHER IS THE OTHER. `key_version` names the KEK that
            // wrapped this row's data key and moves when the PLATFORM rotates that key;
            // `credential_version` counts how many times THIS TENANT has replaced this provider
            // key and moves on every rotation. The migration that adds the second one records why
            // the rotation endpoint may not increment the first.
            'credential_version' => 'integer',
            'last_tested_at' => 'immutable_datetime',
            // `bytea` has no PDO binding, so the bytes travel as PostgreSQL's own `\x` hex input
            // format. The alternative — a `text` column — appears to work and silently mangles
            // non-UTF-8 bytes across a restore. See BinaryCast.
            'credential_ciphertext' => BinaryCast::class,
            'data_key_ciphertext' => BinaryCast::class,
        ];
    }
}
