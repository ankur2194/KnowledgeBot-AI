<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentElementKind;
use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy\OrgOwned;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ONE STRUCTURAL ELEMENT OF A PARSED DOCUMENT — a heading, a paragraph, a table, a slide.
 *
 * ── LARAVEL OWNS THE MIGRATION; THE DATA PLANE OWNS THE ROWS ─────────────────────────────────
 *
 * This is one of the two tables in `services/ai-service/app/db/writes.py`'s `ALLOWED_TABLES`, so
 * the ingestion worker inserts into it directly. That is admissible under ADR-033 for the three
 * reasons that migration records: every row is derived and rebuildable, no public API path writes
 * it, and Laravel owns the schema. THIS MODEL IS THEREFORE READ-MOSTLY — `$fillable` is empty, and
 * the only Laravel writes are the deletion paths, which are `deletion-engineer`'s.
 *
 * Until 2026-08-20 the table those inserts addressed DID NOT EXIST in any migration in this
 * repository. That was finding #79, pinned by ruling; the Phase C1 cascade closes it.
 *
 * ── THE ROW IS UNTRUSTED DATA ────────────────────────────────────────────────────────────────
 *
 * `text` is whatever was inside a customer's document, and non-negotiable 7 is absolute about it:
 * source text can never alter system or bot instructions. Nothing on this model sanitizes anything
 * — the defense is layered in prompt assembly — and it is said here because this is the object a
 * developer holds when they are tempted to interpolate its text into something.
 *
 * ── SMALL-TO-BIG IS WHY `parent` AND `children` BOTH EXIST ───────────────────────────────────
 *
 * Retrieval matches a child element and returns the enclosing section, so both directions of the
 * self-reference are walked. `Model::shouldBeStrict()` forbids lazy loading, so each has to exist
 * as a relation before `with('parent')` can be written at all.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $source_version_id
 * @property string|null $parent_element_id
 * @property int $seq
 * @property DocumentElementKind $kind
 * @property string $text
 * @property int|null $page
 * @property int|null $slide
 * @property string|null $sheet
 * @property string|null $table_ref
 * @property string|null $url
 * @property string|null $anchor
 * @property int $char_start
 * @property int $char_end
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[ScopedBy(OrganizationScope::class)]
final class DocumentElement extends Model implements OrgOwned
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<self>> */
    use HasFactory;

    use HasUlids;

    protected $table = 'document_elements';

    /**
     * EMPTY, because nothing in the control plane creates one of these from a request.
     *
     * The writer is the ingestion worker, through `app/db/writes.py`'s allow-listed direct path,
     * and it names every column explicitly. A fillable list here would be an invitation to build a
     * "repair" endpoint that writes parsed content the parser did not produce — which would put a
     * public API path on a table admitted to `ALLOWED_TABLES` precisely because none exists, and
     * would break ADR-033 property 2 from this side.
     *
     * @var list<string>
     */
    protected $fillable = [];

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
     * @return BelongsTo<SourceVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(SourceVersion::class, 'source_version_id');
    }

    /**
     * The enclosing element — the "big" of small-to-big.
     *
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_element_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_element_id')->orderBy('seq');
    }

    /**
     * @return HasMany<Chunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class, 'document_element_id')->orderBy('seq');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DocumentElementKind::class,
            'seq' => 'integer',
            'page' => 'integer',
            'slide' => 'integer',
            'char_start' => 'integer',
            'char_end' => 'integer',
        ];
    }
}
