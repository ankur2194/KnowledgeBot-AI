<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Casts\JsonObjectCast;
use App\Support\Tenancy\OrgOwned;
use Database\Factories\RetrievalTraceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * What retrieval actually did for one answer (docs/11 §16.6, docs/07 §12).
 *
 * ── THIS ROW IS THE ONLY EVIDENCE THAT A QUERY WAS TENANT-FILTERED ────────────────────────────
 *
 * kb-tenancy-isolation, on what the leak looks like: HTTP 200, normal latency, a well-formed answer
 * citing a document the organization never uploaded, and *"without `retrieval_traces.filters` you
 * cannot even bound which past answers were affected."* `filters` is NOT NULL and its four
 * mandatory keys are checked by the database. Read the migration before relying on that: it proves
 * the trace RECORDS four terms, not that the query CARRIED them.
 *
 * ── NO `#[ScopedBy]`: THERE IS NO COLUMN TO SCOPE ─────────────────────────────────────────────
 *
 * Same reason as `Message`, whose docblock carries it in full — `retrieval_traces` has no
 * `organization_id`, so the scope would append a predicate on a column PostgreSQL does not have.
 * The chain is `retrieval_traces -> messages -> conversations`, NOT NULL at both hops, and every
 * read goes through a repository method taking `organization_id` as a required positional argument.
 * tests/Arch/ConversationDoctrineTest.php pins the exemption by name.
 *
 * ── THE TWO ARRAY COLUMNS ARE NOT `JsonObjectCast`, AND THAT IS LOAD-BEARING ──────────────────
 *
 * `candidate_summaries` and `selected_evidence` are ORDERED LISTS whose order IS the ranking, and
 * their CHECK constraints demand `jsonb_typeof = 'array'`. `JsonObjectCast` casts through
 * `(object)`, which turns `[0 => …, 1 => …]` into `{"0":…,"1":…}` — refused by those constraints,
 * loudly, which is the good outcome; the bad one would be a schema without the constraints, where
 * the ranking silently becomes a property of key iteration order that JSON does not promise.
 * `filters` and `timing_breakdown` are maps and DO use it, for the mirror-image reason: the built-in
 * `array` cast writes `[]` for an empty map and the object CHECK refuses that.
 *
 * ── THE QUERY TEXT LIVES HERE AND NOWHERE ELSE ───────────────────────────────────────────────
 *
 * `original_query` is what a human typed. It is the most sensitive free text on this row, it sits
 * behind the conversation's retention deadline and is cascaded away with the message, and it must
 * never reach `audit_logs`, which is append-only and outlives everything. `AuditLogger`'s
 * knowledge-source block states the same rule for document text.
 *
 * @property string $id
 * @property string $message_id
 * @property string $original_query
 * @property string|null $rewritten_query
 * @property array<string, mixed> $filters
 * @property int $retrieval_configuration_version
 * @property array<int, mixed> $candidate_summaries
 * @property array<int, mixed> $selected_evidence
 * @property bool $insufficient_evidence
 * @property array<string, mixed> $timing_breakdown
 * @property \Carbon\CarbonImmutable $created_at
 */
final class RetrievalTrace extends Model implements OrgOwned
{
    /** @use HasFactory<RetrievalTraceFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * The table has `created_at` and no `updated_at`. A trace describes one query that has already
     * happened; a row that could be updated would be an explanation edited after the fact, which is
     * the one thing the §21.5 regression gate cannot tolerate in the rows it replays.
     */
    public const UPDATED_AT = null;

    protected $table = 'retrieval_traces';

    /**
     * EMPTY, AND IT STAYS EMPTY.
     *
     * NO CLIENT INPUT REACHES THIS TABLE. `original_query` is the one column that ORIGINATES with a
     * caller, and it arrives as the chat request's question — recorded here by the service that ran
     * the query, never posted to this row. Everything else is a measurement or a snapshot the
     * retrieval pipeline produces. A mass-assignable `filters` in particular would be a caller
     * writing the record of what their own query was scoped to.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * The organization, resolved through `messages -> conversations`. TWO HOPS.
     *
     * THROWS RATHER THAN LAZY-LOADING when either hop is missing — see `Message`'s docblock for the
     * whole argument. Load with `->with('message.conversation')`.
     */
    public function organizationId(): string
    {
        if (! $this->relationLoaded('message')) {
            throw new LogicException(
                'RetrievalTrace::organizationId() needs its message loaded: a trace has no '
                .'organization_id column and reaches its organization through '
                .'`retrieval_traces -> messages -> conversations`. Load it with '
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
                'RetrievalTrace::organizationId() loaded its message and got NULL. The chain is '
                .'`retrieval_traces -> messages -> conversations`, and the conversation at the end of it is org-scoped by a scope that '
                .'FAILS CLOSED — so an unbound or wrongly-bound TenantContext hides the parent '
                .'rather than raising. Bind the right organization (TenantContext::runFor) before '
                .'resolving.',
            );
        }

        // Message::organizationId() raises its own named exception when the second hop is missing.
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // MAPS — JsonObjectCast, so an empty one writes `{}` and not `[]`.
            'filters' => JsonObjectCast::class,
            'timing_breakdown' => JsonObjectCast::class,
            // ORDERED LISTS — the built-in cast, so an empty one writes `[]`. See the class
            // docblock: JsonObjectCast here would produce a map with numeric string keys, which the
            // column's array CHECK refuses.
            'candidate_summaries' => 'array',
            'selected_evidence' => 'array',
            'retrieval_configuration_version' => 'integer',
            'insufficient_evidence' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }
}
