<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Casts\JsonObjectCast;
use App\Support\Tenancy\OrgOwned;
use Database\Factories\CitationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One footnote on one answer (docs/11 §16.6, docs/07 §12.15).
 *
 * ── EVERYTHING RENDERED IS ON THIS ROW; `chunk_id` IS A POINTER, NOT A SOURCE ─────────────────
 *
 * `label`, `display_title`, `location_metadata` and `excerpt` are DENORMALIZED so the transcript
 * stays readable after the source is purged — postgresql-patterns names this table as its worked
 * example of the cascade that blanks a customer's chat history. `chunk_id` is `ON DELETE SET NULL`
 * and survives only so "open this in the source viewer" works while the chunk does.
 *
 * THE DENORMALIZATION MUST NEVER BE REFRESHED FROM `chunks`. A citation is what the answer said at
 * the time it was given; re-reading the chunk would silently update a past answer's footnote to
 * match a document that has since been re-crawled. Same argument as
 * `Conversation::$consent_text_snapshot` and `RetrievalTrace::$retrieval_configuration_version`.
 *
 * FOLLOWING `chunk()` IS AN AUTHORIZED ACT. Resolving a chunk by id is an existence oracle
 * (kb-tenancy-isolation names Qdrant's `retrieve` by point id as exactly that), and this column has
 * no composite guard because the table has no `organization_id` to build one from. Any surface that
 * follows the pointer authorizes against the conversation's organization FIRST — which is what
 * `organizationId()` below resolves and what `Chunk`'s own `#[ScopedBy]` then re-checks.
 *
 * ── NO `#[ScopedBy]`: THERE IS NO COLUMN TO SCOPE ─────────────────────────────────────────────
 *
 * Same reason as `Message`, whose docblock carries it in full. The chain is
 * `citations -> messages -> conversations`, which kb-tenancy-isolation NN1 names by name.
 * tests/Arch/ConversationDoctrineTest.php pins the exemption.
 *
 * @property string $id
 * @property string $message_id
 * @property string|null $chunk_id
 * @property string $label
 * @property string $display_title
 * @property array<string, mixed> $location_metadata
 * @property string $excerpt
 * @property \Carbon\CarbonImmutable $created_at
 */
final class Citation extends Model implements OrgOwned
{
    /** @use HasFactory<CitationFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * `created_at` and no `updated_at`. A citation records what an answer cited; a row that could be
     * updated would rewrite the evidence for an answer already given. See the class docblock.
     */
    public const UPDATED_AT = null;

    protected $table = 'citations';

    /**
     * EMPTY, AND IT STAYS EMPTY.
     *
     * NON-NEGOTIABLE 8: citations come from retrieved evidence, assigned BEFORE generation — never
     * from free-form model output. A mass-assignable `label` or `excerpt` is precisely the door that
     * rule exists to close, because the most natural way to fill this table wrongly is to hand it
     * whatever the model emitted. The writer assigns every attribute explicitly, from the evidence
     * set the retrieval stage returned.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * The organization, resolved through `messages -> conversations`. TWO HOPS.
     *
     * THROWS RATHER THAN LAZY-LOADING — see `Message`'s docblock. Load with
     * `->with('message.conversation')`.
     */
    public function organizationId(): string
    {
        if (! $this->relationLoaded('message')) {
            throw new LogicException(
                'Citation::organizationId() needs its message loaded: a citation has no '
                .'organization_id column and reaches its organization through '
                .'`citations -> messages -> conversations`. Load it with '
                .'`->with(\'message.conversation\')` before authorizing.',
            );
        }

        $message = $this->getRelation('message');

        // LOADED AND NULL IS A REAL, REACHABLE STATE. `messages` carries no scope of its own, but
        // `->with('message.conversation')` resolves the SECOND hop through `Conversation`'s
        // `#[ScopedBy]`, which fails closed — so an unbound or wrongly-bound TenantContext makes the
        // chain resolve to null rather than raise. `Message::organizationId()` carries the full
        // explanation and raises its own named exception for the second hop; this guards the first.
        // `assert()` would be compiled out in production and leave a return-type TypeError instead.
        if (! $message instanceof Message) {
            throw new LogicException(
                'Citation::organizationId() loaded its message and got NULL. The chain is '
                .'`citations -> messages -> conversations`, and the conversation at the end of it is org-scoped by a scope that '
                .'FAILS CLOSED — so an unbound or wrongly-bound TenantContext hides the parent '
                .'rather than raising. Bind the right organization (TenantContext::runFor) before '
                .'resolving.',
            );
        }

        return $message->organizationId();
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * The chunk this footnote points at, WHILE IT STILL EXISTS.
     *
     * Null once the source is purged, and the footnote still renders — see the class docblock. Never
     * read for the rendered text; read only to open the live source. `Chunk` carries
     * `#[ScopedBy(OrganizationScope::class)]`, so a resolution with a bound context of the wrong
     * organization returns nothing rather than another tenant's chunk.
     *
     * @return BelongsTo<Chunk, $this>
     */
    public function chunk(): BelongsTo
    {
        return $this->belongsTo(Chunk::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // JsonObjectCast AND NOT `array`: a citation with no locators (a pasted-text source has
            // no page and no slide) is the common case, and the built-in cast would write `[]` for
            // it — refused by `citations_location_metadata_is_object`.
            'location_metadata' => JsonObjectCast::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
