<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SourceState;
use App\Enums\SourceType;
use App\Models\Scopes\OrganizationScope;
use App\Support\Casts\PostgresTextArrayCast;
use App\Support\Tenancy\OrgOwned;
use Database\Factories\KnowledgeSourceFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The admin's unit of intent: one upload, one sitemap, one crawl configuration, one paste.
 *
 * ── THIS ROW IS THE ROOT OF WHAT A BOT MAY SAY ────────────────────────────────────────────────
 *
 * A source reached out of the wrong organization does not produce a wrong answer. It produces a
 * correct-looking answer, at normal latency, with a well-formed citation, pointing at a document
 * the organization never uploaded. `#[ScopedBy]` is the backstop; the mechanism is the explicit
 * `forOrg($orgId)` argument every repository method takes, and below both of those is the
 * composite foreign key on every child in the cascade.
 *
 * ── THERE IS NO `current_version_id` ON THIS MODEL, AND THAT IS RULING R1 ─────────────────────
 *
 * The active-version pointer is `SourceItem::$current_version_id` and nothing else. A crawl gives
 * one source hundreds of independently-versioned items, so "the current version of this source" is
 * not a value — it is a set, and it is a join. The migration's docblock carries the full argument;
 * `services/ai-service/app/db/writes.py:50` states from the data plane's side that it never assigns
 * the pointer at all.
 *
 * ── WHAT IS DELIBERATELY NOT AN ACCESSOR HERE ────────────────────────────────────────────────
 *
 * There is no `isRetrievable()` on this model. Retrievability is the AND of the source status, the
 * item's ACTIVE-VERSION POINTER, the version's own status, the bot assignment and the organization
 * — five terms, four of which live somewhere other than this row. An accessor that answered it from
 * here would be a check that looks complete and consults one term, which is the shape `Bot` refuses
 * for exactly the same reason. `SourceState::isRetrievable()` answers its own term and says so.
 *
 * @property string $id
 * @property string $organization_id
 * @property SourceType $type
 * @property string $name
 * @property string|null $description
 * @property string|null $origin_url
 * @property SourceState $status
 * @property list<string> $tags
 * @property \Carbon\CarbonImmutable|null $effective_at
 * @property \Carbon\CarbonImmutable|null $expires_at
 * @property string|null $created_by
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property \Carbon\CarbonImmutable|null $purged_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[ScopedBy(OrganizationScope::class)]
final class KnowledgeSource extends Model implements OrgOwned
{
    /** @use HasFactory<KnowledgeSourceFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'knowledge_sources';

    /**
     * `organization_id` is ABSENT, and so are `status`, `created_by`, `deleted_at` and `purged_at`.
     *
     * The first for the reason every model here states: over-posting a tenant key is an
     * authorization bug with a 200 response, and `Model::shouldBeStrict()` turns the silent drop
     * into an exception rather than a shrug.
     *
     * `status` is absent because it is a LIFECYCLE STATE, not a form field. Every legal move
     * between the fifteen values is in `SourceState::transitionTable()`, and a mass-assignable
     * status is a `PATCH {"status":"ready"}` that publishes an unverified version — bypassing not
     * only the table but the verification gate that is the whole of non-negotiable 5. It is written
     * by the ingestion callback and by the disable/enable/delete services, each of which asks the
     * transition table first.
     *
     * `created_by` is absent because it is an ATTRIBUTION, and an attribution a client can set is
     * not one. It comes from the authenticated actor.
     *
     * `deleted_at` and `purged_at` are absent because they are the two halves of a verified
     * deletion (kb-deletion-and-verification). A client that could set `purged_at` could assert
     * that a purge succeeded without one having run, which is the one claim in this schema that
     * exists to be defensible.
     *
     * @var list<string>
     */
    protected $fillable = [
        'type', 'name', 'description', 'origin_url', 'tags', 'effective_at', 'expires_at',
    ];

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
     * The actor who added this source. Nullable: the bootstrap command and any future
     * system-created source have no user behind them.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The independently-versioned items. EVERY SOURCE HAS AT LEAST ONE, including a single-file
     * upload — there is no special case and there must never be one.
     *
     * `Model::shouldBeStrict()` forbids lazy loading, so this relation has to exist before
     * `with('items')` can be written at all.
     *
     * @return HasMany<SourceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SourceItem::class, 'source_id');
    }

    /**
     * Which bots may answer from this source.
     *
     * A `HasMany` over a real model rather than a `belongsToMany` pivot, and the reason is
     * BotSourceAssignment's own docblock: `attach()` writes neither the ULID primary key nor the
     * denormalized `organization_id` that both composite foreign keys are built on — and on THIS
     * table that column is not a convenience, it is the only thing standing between an operator's
     * checkbox and a cross-tenant leak the filter would agree with.
     *
     * @return HasMany<BotSourceAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(BotSourceAssignment::class, 'source_id');
    }

    /**
     * The chunks derived from this source, across every item and every version.
     *
     * DENORMALIZED, WHICH IS WHY THIS RELATION EXISTS AT ALL: `chunks.source_id` is a copy carried
     * for the Qdrant payload, and the composite foreign key `chunks_source_same_org` is what makes
     * the copy checkable. Read it for a count or a rebuild, never as a retrieval path — retrieval
     * goes through the active-version pointer and the four mandatory filter terms.
     *
     * @return HasMany<Chunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class, 'source_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SourceType::class,
            // AN ENUM CAST AND NOT A STRING, so a value the database would accept but this
            // application has no meaning for cannot be read back silently — and so that every
            // transition question goes through SourceState::canTransitionTo() rather than through a
            // string comparison somebody wrote at a call site.
            'status' => SourceState::class,
            // PostgresTextArrayCast AND NOT `array`. The built-in cast is JSON: applied to a
            // `text[]` column it writes `["a","b"]`, which reads back as a ONE-element array whose
            // single element is that JSON fragment. Nothing raises. The cast class records the rest.
            'tags' => PostgresTextArrayCast::class,
            'effective_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
        ];
    }
}
