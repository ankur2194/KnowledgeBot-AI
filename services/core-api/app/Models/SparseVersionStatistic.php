<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy\OrgOwned;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;

/**
 * How many chunks one source version contributed, under one analyzer. The numerator of the IDF
 * formula (finding C2).
 *
 * DERIVED, in the ADR-010 sense: a pure function of the version's chunk text. Nothing here is a
 * source of truth and a rebuild reproduces it exactly.
 *
 * @property string $organization_id
 * @property string $source_version_id
 * @property string $analyzer
 * @property int $document_total
 */
#[ScopedBy(OrganizationScope::class)]
final class SparseVersionStatistic extends Model implements OrgOwned
{
    protected $table = 'sparse_version_statistics';

    /**
     * Composite natural key. Eloquent has no native support, and nothing loads one of these by a
     * single-column key — the only read is an aggregate over a version SET.
     */
    public $incrementing = false;

    protected $primaryKey = 'organization_id';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['source_version_id', 'analyzer', 'document_total'];

    public function organizationId(): string
    {
        return $this->organization_id;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['document_total' => 'integer'];
    }
}
