<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy\OrgOwned;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;

/**
 * One term's document frequency within one source version, under one analyzer (finding C2).
 *
 * `term_id` is a bigint holding an UNSIGNED 32-bit value: Qdrant sparse indices are u32 and
 * PostgreSQL has no unsigned types, so `integer` would reject roughly half of every analyzer's
 * term space at insert time.
 *
 * DERIVED. See SparseVersionStatistic.
 *
 * @property string $organization_id
 * @property string $source_version_id
 * @property string $analyzer
 * @property int $term_id
 * @property int $document_frequency
 */
#[ScopedBy(OrganizationScope::class)]
final class SparseTermFrequency extends Model implements OrgOwned
{
    protected $table = 'sparse_term_frequencies';

    public $incrementing = false;

    /**
     * No `created_at`/`updated_at` on this table, deliberately: it reaches term cardinality times
     * version count, and two timestamptz columns per row is 16 bytes of nothing on a table whose
     * every row is reproducible from the chunk text.
     */
    public $timestamps = false;

    protected $primaryKey = 'organization_id';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['source_version_id', 'analyzer', 'term_id', 'document_frequency'];

    public function organizationId(): string
    {
        return $this->organization_id;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'term_id' => 'integer',
            'document_frequency' => 'integer',
        ];
    }
}
