<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SourceState;
use App\Models\Scopes\OrganizationScope;
use App\Support\Casts\JsonObjectCast;
use App\Support\Tenancy\OrgOwned;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ONE IMMUTABLE PROCESSING RESULT: a content hash, four configuration versions, a status, and — if
 * it ever went live — the two timestamps that bound its period of service.
 *
 * ── "IMMUTABLE" IS ABOUT THE INPUTS, NOT ABOUT THE ROW ───────────────────────────────────────
 *
 * `status`, `activated_at`, `retired_at` and `delivery_count` all move. What never moves is the
 * IDENTITY: the content hash, the three config versions, the embedding model version, and the
 * `ingest_key` that is a digest over all of them. Editing one of those in place would make the
 * ingest key describe a different run than the row it sits on, and the dedup index
 * `(source_item_id, ingest_key)` would then match a request against a version that is not what the
 * request asked for. A change to any of those inputs is a NEW ROW, which is the whole versioning
 * model.
 *
 * ── ACTIVATION IS A POINTER ON THE ITEM, NEVER A COLUMN HERE ─────────────────────────────────
 *
 * `activated_at` records WHEN this version went live and is half of the partial unique index that
 * makes at most one version live per item. It is not what MAKES it live: `SourceItem::
 * $current_version_id` is. Reading `activated_at IS NOT NULL AND retired_at IS NULL` as "this is
 * the live version" is correct today only because the index forbids the alternative — and it is the
 * reading that would survive somebody dropping the index. Ask the item.
 *
 * ── THE SIX VERSION TRIGGERS ARE THE SIX IDENTITY COLUMNS ────────────────────────────────────
 *
 * Content changed, parser config changed, OCR config changed, chunker config changed, embedding
 * model changed, administrator requested a reprocess. Each is a component of `ingest_key`, and a
 * trigger that is not in the key is a trigger that silently does nothing — the request dedupes
 * against the completed run and the admin sees "already processed". Ruling R2 exists because the
 * OCR component is the one both the spec and this repository's own factory docblock had dropped.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $source_item_id
 * @property int $version_number
 * @property string $content_hash
 * @property string $ingest_key
 * @property string $parser_cfg_version
 * @property string $ocr_cfg_version
 * @property string $chunker_cfg_version
 * @property string $embedding_model_version
 * @property SourceState $status
 * @property array<string, mixed> $warning_summary
 * @property int $delivery_count
 * @property \Carbon\CarbonImmutable|null $activated_at
 * @property \Carbon\CarbonImmutable|null $retired_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[ScopedBy(OrganizationScope::class)]
final class SourceVersion extends Model implements OrgOwned
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<self>> */
    use HasFactory;

    use HasUlids;

    protected $table = 'source_versions';

    /**
     * `$fillable` IS EMPTY, and on this table more emphatically than on any other in the cascade.
     *
     * There is no request body anywhere in this application that may name a column of this row.
     * The identity columns are computed by the ingestion pipeline, `status` is a lifecycle state
     * governed by `SourceState::transitionTable()`, `activated_at` and `retired_at` are written by
     * the activation transaction under a row lock, `warning_summary` arrives on the ingestion
     * callback, and `delivery_count` is the durable redelivery bound.
     *
     * A mass-assignable `activated_at` would be a way to publish an unverified version through a
     * PATCH — non-negotiable 5 defeated by a form field — and `Model::shouldBeStrict()` is what
     * turns an attempt into an exception rather than a silent drop.
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
     * @return BelongsTo<SourceItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(SourceItem::class, 'source_item_id');
    }

    /**
     * The parser's output for this run, in document order.
     *
     * `Model::shouldBeStrict()` forbids lazy loading, so the relation has to exist before
     * `with('elements')` can be written. Ordered here because document order is what the sequence
     * column exists for and a caller that forgot it would get planner order.
     *
     * @return HasMany<DocumentElement, $this>
     */
    public function elements(): HasMany
    {
        return $this->hasMany(DocumentElement::class, 'source_version_id')->orderBy('seq');
    }

    /**
     * @return HasMany<Chunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class, 'source_version_id')->orderBy('seq');
    }

    /**
     * Whether this row currently satisfies `activated_at IS NOT NULL AND retired_at IS NULL` — the
     * predicate of the partial unique index.
     *
     * NOT A SUBSTITUTE FOR THE POINTER. See the class docblock: this answers "was this version ever
     * activated and not yet retired", which the index guarantees is true of at most one version per
     * item. What decides which version a query sees is `SourceItem::$current_version_id`, and the
     * two can differ for the width of the activation transaction. Named `hasLivePeriod()` rather
     * than `isActive()` so no call site reads it as the authority it is not.
     */
    public function hasLivePeriod(): bool
    {
        return $this->activated_at !== null && $this->retired_at === null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // An ENUM CAST rather than a string, so every transition question has to go through
            // SourceState::canTransitionTo() — including the verification gate on the two Ready
            // edges, which a string comparison cannot express.
            'status' => SourceState::class,
            'version_number' => 'integer',
            'delivery_count' => 'integer',
            // JsonObjectCast AND NOT `array`. PHP cannot tell an empty array from an empty map, so
            // the built-in cast writes the no-warnings majority as `[]` — a JSON ARRAY — while
            // every warned version writes as `{}`. One column, two JSON types, decided by whether
            // the parse went badly. `source_versions_warning_summary_is_object` refuses the array
            // spelling outright, so with the built-in cast every clean version is rejected by the
            // database and the failure reads as a constraint bug rather than an encoding one.
            'warning_summary' => JsonObjectCast::class,
            'activated_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }
}
