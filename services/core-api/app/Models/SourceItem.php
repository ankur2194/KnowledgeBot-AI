<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy\OrgOwned;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * THE UNIT OF INDEPENDENT VERSIONING: one page, one uploaded file.
 *
 * ── `current_version_id` IS THE ACTIVE-VERSION POINTER, AND IT IS THE ONLY ONE ────────────────
 *
 * Ruling R1. `knowledge_sources` carries no counterpart, because a source with four hundred crawled
 * pages has no single current version. This column is what makes a version live, and the constraint
 * that makes the switch atomic is `source_versions_one_active_per_item` — a PARTIAL UNIQUE INDEX,
 * not a boolean, because two concurrent publishes both read "no active version" and a boolean has
 * no single statement that flips them together.
 *
 * IT IS NOT FILLABLE AND IT IS NOT WRITTEN FROM A SERVICE THAT HAPPENS TO HOLD A VERSION. The whole
 * activation sequence is one transaction — lock the item row, mark the version ready, switch the
 * pointer, retire the prior version — and it belongs to Laravel because Laravel is where the audit
 * row, the policy check and the retention clock already are. `app/db/writes.py` states the other
 * half: the data plane never assigns this column, and two writers on it turn a lifecycle bug into
 * an integrity error inside a Celery task that retries forever.
 *
 * ── THERE IS NO `status` COLUMN, WHICH DIVERGES FROM docs/11 §16.4 ───────────────────────────
 *
 * Ruling R3 names exactly two columns that carry the fifteen-value vocabulary and this is not one
 * of them. The item's real state is `current_version_id` (is anything live at all) plus that
 * version's own `status`, both one join away. A third denormalized rollup is a third thing to keep
 * in step across two runtimes on a callback path. Recorded rather than resolved silently — see the
 * migration.
 *
 * ── EVERY SOURCE HAS ONE OF THESE, INCLUDING A SINGLE-FILE UPLOAD ────────────────────────────
 *
 * Do not special-case a one-item source. The pointer switch, the missing-page counter and citation
 * provenance all key off `source_item_id`, and code that reads a version pointer off the SOURCE for
 * uploads and off the ITEM for crawls diverges the first time somebody adds a second file — and it
 * diverges silently, because each branch works alone.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $source_id
 * @property string $canonical_key
 * @property string|null $url
 * @property string|null $title
 * @property string|null $display_name
 * @property string|null $storage_key
 * @property string|null $content_hash
 * @property string|null $mime
 * @property int|null $byte_size
 * @property string|null $current_version_id
 * @property \Carbon\CarbonImmutable|null $last_discovered_at
 * @property int $missing_count
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[ScopedBy(OrganizationScope::class)]
final class SourceItem extends Model implements OrgOwned
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<self>> */
    use HasFactory;

    use HasUlids;

    protected $table = 'source_items';

    /**
     * `$fillable` IS EMPTY, AND THAT IS THE HONEST ANSWER RATHER THAN AN OVERSIGHT.
     *
     * Not one column on this table is a form field. `organization_id` and `source_id` are the
     * ownership edges; `canonical_key`, `storage_key`, `content_hash`, `mime` and `byte_size` are
     * produced by the upload or crawl pipeline and are the values `kb-security-baseline`'s upload
     * rules require to be DERIVED rather than declared — a client-settable `mime` is precisely the
     * `Content-Type` header §8.10 says never to trust, and a client-settable `storage_key` is a
     * path traversal with a 201. `display_name` is the one string the user supplies and it arrives
     * from the multipart part rather than from a JSON body. `current_version_id` is the pointer.
     * `last_discovered_at` and `missing_count` belong to the recrawl dispatcher.
     *
     * An empty list means every write goes through a repository that assigns columns explicitly,
     * which is what `Model::shouldBeStrict()` makes enforceable: a `fill()` naming any of these
     * throws instead of silently dropping it.
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
     * Every version of this item, newest first.
     *
     * Ordered in the relation rather than at the call site because an unordered version history is
     * unreadable and because a caller who forgot the `orderBy` would get planner order, which
     * differs between two reads of an unchanged set.
     *
     * @return HasMany<SourceVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(SourceVersion::class, 'source_item_id')->orderByDesc('version_number');
    }

    /**
     * THE LIVE VERSION, or null while nothing has been published yet.
     *
     * A `BelongsTo` over the pointer column and NOT a `hasOne(...)->where('activated_at', ...)`.
     * The two would agree almost always, and the case where they disagree is the one that matters:
     * a version activated by a racing publish that lost the partial unique index would satisfy a
     * predicate-based relation for the instant before its transaction rolled back. The pointer is
     * the definition of live; anything else is an inference from it.
     *
     * @return BelongsTo<SourceVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(SourceVersion::class, 'current_version_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'missing_count' => 'integer',
            'last_discovered_at' => 'immutable_datetime',
        ];
    }
}
