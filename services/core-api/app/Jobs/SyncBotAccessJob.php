<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\KbException;
use App\Repositories\Contracts\KnowledgeSourceRepositoryInterface;
use App\Services\Internal\InternalAiClient;
use App\Support\Kb\ErrorTaxonomy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\Middleware\FailOnException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Contracts\Queue\TracingLinked;
use Throwable;

/**
 * Add or remove one bot's id in the `bot_ids` list on the points it can or can no longer see.
 *
 * ── THE TRAP THIS JOB EXISTS TO NOT FALL INTO ────────────────────────────────────────────────
 *
 * `bot_ids` is one of the four mandatory Qdrant filter terms and it is a LIST on each point rather
 * than a row of its own — every bot the source is assigned to is in it. So revoking is a scroll
 * and a read-modify-write, never a delete-by-filter: a delete removes the chunks the OTHER bots
 * still answer from, at HTTP 200, first observed weeks later as a bot that stopped citing a clause
 * nobody edited. `BotService::delete()` names it at the call site and the far side is the half
 * that implements it; this class's contribution is that it can only ask for the safe operation,
 * because there is no shape of the request that expresses a delete.
 *
 * ── BOTH DIRECTIONS, AND THE GRANT IS NOT AN AFTERTHOUGHT ────────────────────────────────────
 *
 * Assigning a bot to an ALREADY-INDEXED source is the mirror gap and it is just as silent: the
 * points were written with the assignment set as it was at index time, so a bot added afterwards
 * satisfies no `bot_ids` term and retrieves nothing from a source its operator can see listed as
 * assigned. One job for both, because it is one read-modify-write with one verification shape and
 * two classes would drift on the half that is exercised less.
 *
 * ── `$sourceIds === null` IS THE DELETED-BOT CASE AND IS REVOKE-ONLY ────────────────────────
 *
 * By the time a bot delete's assignments are gone, nothing can enumerate what that bot could see —
 * so the revoke has to be able to say "every point in this organization carrying this id". A grant
 * with the same scope would assign a bot to every source the tenant owns; `InternalAiClient` and
 * the far side both refuse it, and the constructor refuses it here so the mistake never becomes a
 * signed request.
 *
 * ── NO COMPENSATION, AND THAT IS THE HONEST SHAPE RATHER THAN A GAP ─────────────────────────
 *
 * There is nothing to revert to. The `bot_source_assignments` row this job is carrying has already
 * been deleted (or created) inside a committed transaction with its own audit row, and re-creating
 * a withdrawn grant to "compensate" would restore retrieval scope an administrator removed — the
 * one thing `AuditLogger` marks these operations ON_FAILURE_ABORT to prevent. On a delete the row
 * is gone for good, so there is not even a row to restore. What `failed()` does instead is record
 * the divergence loudly, because that is the whole of what this side can truthfully do: the id is
 * still in a payload, and a future bot cannot inherit it (ULIDs are never reused), so the residue
 * is stale rather than dangerous until an operator re-runs the sync.
 */
#[Tries(5), Timeout(90), UniqueFor(1800)]
final class SyncBotAccessJob implements ShouldBeEncrypted, ShouldBeUniqueUntilProcessing, ShouldQueue, TracingLinked
{
    use Queueable;

    /**
     * @param  list<string>|null  $sourceIds  null = every point in the organization; revoke only
     */
    public function __construct(
        public readonly string $organizationId,
        public readonly string $botId,
        public readonly bool $grant,
        public readonly ?array $sourceIds,
        public readonly ?string $actorId = null,
    ) {
        if ($grant && $sourceIds === null) {
            // Refused in the CONSTRUCTOR, so an unscoped grant cannot even be serialized onto the
            // queue. Catching it at execution time would put a job in `failed_jobs` whose payload
            // describes an operation no contract offers, which reads to an operator as a transient
            // failure worth replaying.
            throw new InvalidArgumentException(
                'An organization-wide grant is not an operation this contract offers: '
                .'source_ids is required when granting.',
            );
        }

        $this->onConnection('valkey')->onQueue('ai-dispatch');
    }

    /**
     * ORG-PREFIXED, CARRYING THE DIRECTION AND THE SCOPE.
     *
     * A grant and a revoke for the same bot are different jobs, and so are two grants over
     * different source sets — `ShouldBeUniqueUntilProcessing` releases its lock at the START of
     * processing, so a key that collapsed them would DISCARD the second dispatch silently while
     * the first was still queued. The scope is hashed rather than interpolated because it can be a
     * long list and a unique key is a Valkey key.
     */
    public function uniqueId(): string
    {
        $scope = $this->sourceIds === null ? 'all' : implode(',', $this->sourceIds);

        return "{$this->organizationId}:{$this->botId}:"
            .($this->grant ? 'grant' : 'revoke').':'.hash('xxh128', $scope);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            // NOT keyed on the direction: a grant and a revoke for one bot must not scroll and
            // rewrite the same points concurrently, or the later `set_payload` wins by timing.
            // 240 > the connection's retry_after of 180 so a killed worker's lock self-heals.
            (new WithoutOverlapping("lock:{$this->organizationId}:bot-access-sync:{$this->botId}"))
                ->expireAfter(240)
                ->releaseAfter(90),

            new FailOnException(fn (Throwable $e): bool => $e instanceof KbException
                && ! ErrorTaxonomy::retryable($e->errorClass, $e->origin)),
        ];
    }

    public function handle(
        TenantContext $tenancy,
        KnowledgeSourceRepositoryInterface $sources,
        InternalAiClient $ai,
    ): void {
        Span::getCurrent()->setAttributes([
            'kb.org_id' => $this->organizationId,
            'kb.bot_id' => $this->botId,
            'kb.operation' => 'bot.access.sync',
            'messaging.message.attempt' => $this->attempts(),
        ]);

        $tenancy->runFor($this->organizationId, function () use ($sources, $ai): void {
            // THE COLLECTION SET IS RESOLVED FROM THE SOURCES IN SCOPE, and org-wide when the
            // scope is. A per-source resolution for the deleted-bot case is impossible by
            // construction — the assignments that named those sources are gone — which is exactly
            // why `embeddingIdentitiesForOrganization` exists as a separate method rather than as
            // a null-ish argument on the other one.
            $identities = $this->sourceIds === null
                ? $sources->embeddingIdentitiesForOrganization($this->organizationId)
                : $this->identitiesForSources($sources);

            if ($identities === []) {
                // Nothing in this organization has ever been indexed under any embedding identity,
                // so there is no collection to address. Decided here, where the reason is knowable,
                // because the far side refuses an empty collection set outright: zero collections
                // and zero points report the same counts.
                Span::getCurrent()->setAttribute('kb.no_indexed_versions', true);

                return;
            }

            $report = $ai->syncBotAccess(
                $this->organizationId,
                $this->botId,
                $this->grant,
                $this->sourceIds,
                $identities,
                $this->actorId,
            );

            Span::getCurrent()->setAttribute('kb.points_rewritten', $report['rewritten']);
        });
    }

    /**
     * The union of the embedding identities of every source in scope, de-duplicated and ordered.
     *
     * A UNION AND NOT THE FIRST SOURCE'S SET. Two sources of one organization can have been
     * indexed under different embedding models — a source ingested before a model change and one
     * after — so taking either alone would send the rewrite to a collection the other's points are
     * not in, and the far side would verify the collection it was given and report a pass.
     *
     * @return list<string>
     */
    private function identitiesForSources(KnowledgeSourceRepositoryInterface $sources): array
    {
        $identities = [];

        foreach ($this->sourceIds ?? [] as $sourceId) {
            foreach ($sources->embeddingIdentitiesFor($this->organizationId, $sourceId) as $value) {
                $identities[$value] = true;
            }
        }

        $values = array_keys($identities);
        sort($values);

        return $values;
    }

    /**
     * See the class docblock: there is nothing to revert to, so this records and stops.
     *
     * It swallows nothing and does nothing else on purpose. The `failed_jobs` row plus this line
     * is the record, and an operator's repair is to re-run the assignment change — which
     * re-dispatches this job against a payload that is convergent, so a partially-applied rewrite
     * finishes rather than double-applying.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('bot access sync did not reach the index', [
            'org_id' => $this->organizationId,
            'bot_id' => $this->botId,
            'reason' => $this->grant ? 'grant' : 'revoke',
            'count' => $this->sourceIds === null ? 0 : count($this->sourceIds),
            'outcome' => 'left-diverged',
        ]);
    }
}
