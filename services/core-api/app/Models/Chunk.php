<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ChunkContentType;
use App\Enums\ChunkIndexStatus;
use App\Models\Scopes\OrganizationScope;
use App\Support\Casts\PostgresTextArrayCast;
use App\Support\Tenancy\OrgOwned;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONE RETRIEVABLE PASSAGE, and the relational half of one Qdrant point.
 *
 * ── LARAVEL OWNS THE MIGRATION; THE DATA PLANE OWNS THE ROWS ─────────────────────────────────
 *
 * `chunks` is in `services/ai-service/app/db/writes.py`'s `ALLOWED_TABLES`, so the ingestion worker
 * inserts directly and this model is READ-MOSTLY. `$fillable` is empty and the only Laravel writes
 * are the deletion paths, which belong to `deletion-engineer`. Until 2026-08-20 the table those
 * inserts addressed did not exist in any migration here — finding #79, closed by the Phase C1
 * cascade.
 *
 * ── `vector_point_id` IS THE ONLY `uuid` IN THIS DATABASE ────────────────────────────────────
 *
 * Everything else is a ULID in `char(26) COLLATE "C"`. Qdrant point ids may only be u64 or UUID, so
 * the readable key `org:version:seq` is folded through `uuid5(POINT_NS, ...)`. That derivation is
 * DETERMINISTIC on purpose: a redelivered upsert overwrites what it already wrote instead of adding
 * a second copy, and deletion targets this stable identifier rather than a text match
 * (non-negotiable 6). NEVER ROTATE `POINT_NS` — every existing point instantly becomes an orphan no
 * deletion query can reach.
 *
 * ── THERE IS NO `bot_ids` ON THIS MODEL, AND ITS ABSENCE IS DELIBERATE ───────────────────────
 *
 * `ChunkMetadata` carries one and the Qdrant payload carries one; the AUTHORITATIVE source of that
 * grant is `bot_source_assignments`, which has the two composite foreign keys that make a
 * cross-organization assignment impossible. A denormalized copy here would be a second statement of
 * the grant with nothing able to hold the two in step, and assigning a source to one more bot would
 * become an UPDATE over every chunk of every version of every item of that source. The migration's
 * docblock carries the whole argument. Read the assignment through
 * `$chunk->source->assignments`.
 *
 * ── `text` IS UNTRUSTED DATA ─────────────────────────────────────────────────────────────────
 *
 * It is a customer's document, verbatim, and non-negotiable 7 is absolute: source text can never
 * alter system or bot instructions. Nothing on this model sanitizes it. It is also MANDATORY rather
 * than a cache — ADR-010 makes Qdrant derived, so a rebuild re-embeds from this column.
 *
 * ── CITATIONS DO NOT DEPEND ON THIS ROW SURVIVING ────────────────────────────────────────────
 *
 * `citations.chunk_id` is `ON DELETE SET NULL` with the label, title, location and excerpt
 * denormalized onto the citation row, so deleting a source does not blank a customer's chat
 * history. That is stated here because this is the model somebody holds when they consider making
 * a citation read its text through this relation.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $source_id
 * @property string $source_item_id
 * @property string $source_version_id
 * @property int $seq
 * @property string $document_element_id
 * @property list<string> $element_ids
 * @property string|null $parent_element_id
 * @property list<string> $heading_path
 * @property int|null $page
 * @property int|null $page_end
 * @property int|null $slide
 * @property string|null $sheet
 * @property string|null $table_ref
 * @property int|null $row_start
 * @property int|null $row_end
 * @property string|null $url
 * @property string|null $anchor
 * @property int $char_start
 * @property int $char_end
 * @property string $lang
 * @property ChunkContentType $content_type
 * @property int $token_count
 * @property string $content_hash
 * @property string|null $overlap_of
 * @property string $text
 * @property string $vector_point_id
 * @property ChunkIndexStatus $index_status
 * @property string $embedding_model_id
 * @property string $parser_version
 * @property string $chunker_version
 * @property \Carbon\CarbonImmutable|null $effective_at
 * @property \Carbon\CarbonImmutable|null $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[ScopedBy(OrganizationScope::class)]
final class Chunk extends Model implements OrgOwned
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<self>> */
    use HasFactory;

    use HasUlids;

    protected $table = 'chunks';

    /**
     * EMPTY, for the reason `DocumentElement` states: nothing in the control plane creates one of
     * these from a request, and a fillable list would invite an endpoint that writes retrievable
     * content the chunker did not produce — a public API path on a table admitted to
     * `ALLOWED_TABLES` precisely because none exists.
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
     * @return BelongsTo<KnowledgeSource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'source_id');
    }

    /**
     * @return BelongsTo<SourceItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(SourceItem::class, 'source_item_id');
    }

    /**
     * @return BelongsTo<SourceVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(SourceVersion::class, 'source_version_id');
    }

    /**
     * The PRIMARY element this chunk came from. A prose chunk spans several, and the full set is
     * `element_ids` — a column and not a relation, because it is read whole with the row and
     * nothing joins on its members.
     *
     * @return BelongsTo<DocumentElement, $this>
     */
    public function element(): BelongsTo
    {
        return $this->belongsTo(DocumentElement::class, 'document_element_id');
    }

    /**
     * The enclosing section — the "big" of small-to-big.
     *
     * @return BelongsTo<DocumentElement, $this>
     */
    public function parentElement(): BelongsTo
    {
        return $this->belongsTo(DocumentElement::class, 'parent_element_id');
    }

    /**
     * The chunk this one overlaps, so packing can drop a near-duplicate instead of spending two
     * evidence slots on the same paragraph.
     *
     * @return BelongsTo<self, $this>
     */
    public function overlaps(): BelongsTo
    {
        return $this->belongsTo(self::class, 'overlap_of');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            // PostgresTextArrayCast AND NOT `array`, which is JSON and would write `["a","b"]`
            // into a `char(26)[]` column. The failure is silent in both directions and the symptom
            // is a citation whose heading path is a JSON fragment.
            'element_ids' => PostgresTextArrayCast::class,
            'heading_path' => PostgresTextArrayCast::class,
            'page' => 'integer',
            'page_end' => 'integer',
            'slide' => 'integer',
            'row_start' => 'integer',
            'row_end' => 'integer',
            'char_start' => 'integer',
            'char_end' => 'integer',
            'content_type' => ChunkContentType::class,
            'token_count' => 'integer',
            // An enum cast rather than a string, so a value this application has no meaning for
            // cannot be read back silently. See ChunkIndexStatus for why it is never a retrieval
            // predicate.
            'index_status' => ChunkIndexStatus::class,
            // NO CAST ON `vector_point_id`, deliberately: it is a `uuid` column and it reaches PHP
            // as the canonical 36-character string, which is exactly the form the Qdrant client
            // takes and the form a deletion statement binds. A cast to some UUID value object would
            // add a conversion at every read of the highest-cardinality table here, to produce a
            // type nothing downstream asks for.
            'effective_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
