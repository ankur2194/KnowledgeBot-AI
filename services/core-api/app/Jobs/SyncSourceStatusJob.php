<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SourceState;
use App\Exceptions\KbException;
use App\Repositories\Contracts\KnowledgeSourceRepositoryInterface;
use App\Services\Internal\InternalAiClient;
use App\Support\Kb\ErrorTaxonomy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
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
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Contracts\Queue\TracingLinked;
use Throwable;

/**
 * Carry a source's disable or enable into the Qdrant payload, and prove it landed.
 *
 * ── WHY THIS IS A JOB AND NOT A CALL INSIDE `disable()` ───────────────────────────────────────
 *
 * The status column and the payload term are in two different stores and cannot be written in one
 * transaction. Something has to be committed first, and it has to be the column: a payload rewrite
 * that succeeded against a row that then failed to commit would leave the index disagreeing with a
 * source nobody disabled, which nothing on either side would ever notice. So the column commits,
 * the job carries the change, and the retries are the mechanism that closes the window rather than
 * an optimisation over an inline call.
 *
 * ── THE COMPENSATION IS ASYMMETRIC, AND THAT IS A DECISION RATHER THAN AN OVERSIGHT ──────────
 *
 * `failed()` reverts an ENABLE and never a DISABLE, because only one of the two reverts toward the
 * truth:
 *
 *   * An ENABLE that did not reach the index leaves the points carrying `disabled`. The console
 *     says ready, every question about the source goes unanswered, and the operator has no reason
 *     to look — `kb-tenancy-isolation`'s correct-filter-wrong-payload case. Reverting the row to
 *     `disabled` makes the console agree with what the index will actually do, and the operator's
 *     obvious next action, enabling again, re-runs this job.
 *   * A DISABLE that did not reach the index leaves the points carrying `ready`, so the source is
 *     still answering. Reverting the row would ALSO tell the operator it is ready — true of the
 *     index and the opposite of what they asked for — and would discard the only durable record
 *     that they asked at all. The row stays `disabled`, the `failed_jobs` row is the truth, and
 *     `failed()` logs the divergence at `error` with both ids in the message.
 *
 * There is no third option available in this repository today: the mechanism that would make a
 * disable immediate WITHOUT a payload rewrite is the resolved active-version set in the config
 * snapshot, and that resolver lands with the chat path (`SourceService::disable()`'s marker).
 *
 * ── THE THREE ATTRIBUTES ARE ONE ARITHMETIC ──────────────────────────────────────────────────
 *
 *     Timeout(90)  <  the `valkey` connection's retry_after (180)  <  UniqueFor(1800)
 *                                                                 <  WithoutOverlapping 240
 *
 * Every arrow that points the wrong way is a silent duplicate or a permanent wedge rather than an
 * error — see `SubmitIngestionJob`, which states the rule at length. 90 rather than 60 because the
 * far side counts before and after the rewrite and both counts are `exact`.
 *
 * ── `ShouldBeEncrypted`, AND ULIDs RATHER THAN MODELS ────────────────────────────────────────
 *
 * The payload carries `organization_id` and `source_id`, which are tenant-bearing, and it sits in
 * Valkey in plaintext and in `failed_jobs` indefinitely. `SerializesModels` would re-query the row
 * under the WORKER's pooled tenant context, which is the thing `handle()` exists to establish.
 */
#[Tries(5), Timeout(90), UniqueFor(1800)]
final class SyncSourceStatusJob implements ShouldBeEncrypted, ShouldBeUniqueUntilProcessing, ShouldQueue, TracingLinked
{
    use Queueable;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $sourceId,
        /** `ready` or `disabled` — the value the payload term must end up carrying. */
        public readonly string $sourceStatus,
        /** The status to put the row back to if this never lands. Null means "do not revert". */
        public readonly ?string $revertTo = null,
        /** The admin who asked, for `X-KB-Actor-Id`. Null for platform-initiated work. */
        public readonly ?string $actorId = null,
    ) {
        $this->onConnection('valkey')->onQueue('ai-dispatch');
    }

    /**
     * ORG-PREFIXED AND CARRYING THE TARGET STATUS.
     *
     * Keying on `{org}:{source}` alone would be wrong in the way `SubmitIngestionJob::uniqueId()`
     * documents: `ShouldBeUniqueUntilProcessing` releases at the START of processing, so a
     * disable followed quickly by an enable would have the second dispatch DISCARDED while the
     * first sat in the queue — no exception, no failed job — and the index would be left carrying
     * `disabled` for a source the console shows as ready. Including the target status means the
     * two dispatches are different jobs, and a genuine at-least-once redelivery of ONE of them
     * still collapses, which is all this key is for.
     */
    public function uniqueId(): string
    {
        return "{$this->organizationId}:{$this->sourceId}:{$this->sourceStatus}";
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            // 240 > the connection's retry_after of 180, so a worker killed at its timeout leaves
            // a lock that SELF-HEALS. `expireAfter` defaults to 0, which `Cache::lock($key, 0)`
            // reads as NEVER EXPIRES — the permanent wedge.
            //
            // NOT keyed on the target status, unlike `uniqueId()`. Two rewrites of the same
            // source's payload must not run concurrently in EITHER direction: they would scroll
            // and count the same points and the later `set_payload` would win by timing rather
            // than by intent.
            (new WithoutOverlapping("lock:{$this->organizationId}:source-status-sync:{$this->sourceId}"))
                ->expireAfter(240)
                ->releaseAfter(90),

            // A CLOSURE AND NEVER THE ARRAY FORM: one `KbException` spans both halves of the
            // taxonomy, so type dispatch would retry an `authorization` or `validation` refusal to
            // the attempt cap and again on every operator replay. The taxonomy dispatches on
            // `error_class`, so that is what this reads.
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
            'kb.operation' => 'source.status.sync',
            'messaging.message.attempt' => $this->attempts(),
        ]);

        $tenancy->runFor($this->organizationId, function () use ($sources, $ai): void {
            $source = $sources->find($this->organizationId, $this->sourceId);

            if ($source === null) {
                // Deleted between dispatch and execution. RETURN rather than fail: the purge path
                // removes the points outright, so there is no payload left to rewrite and nothing
                // an operator could do about a `failed_jobs` row describing it.
                return;
            }

            if ($source->status->value !== $this->sourceStatus) {
                // THE ROW MOVED ON. A disable followed by an enable leaves this job describing a
                // state the source is no longer in, and applying it would rewrite the payload
                // BACKWARDS — the far side would verify the rewrite and report a pass for a value
                // the control plane disagrees with. The row is the authority; the payload is
                // derived from it.
                Span::getCurrent()->setAttribute('kb.superseded_dispatch', true);

                return;
            }

            $identities = $sources->embeddingIdentitiesFor($this->organizationId, $this->sourceId);

            if ($identities === []) {
                // NEVER INDEXED, so there is no collection to address and no payload to rewrite.
                // This is not the same as a rewrite that found no points: the far side REFUSES an
                // empty collection set precisely because zero collections and zero points produce
                // the same counts, so the emptiness is decided here, where the reason is knowable.
                Span::getCurrent()->setAttribute('kb.no_indexed_versions', true);

                return;
            }

            $report = $ai->syncSourceStatus(
                $this->organizationId,
                $this->sourceId,
                $this->sourceStatus,
                $identities,
                $this->actorId,
            );

            Span::getCurrent()->setAttribute('kb.points_verified', $report['verified']);
        });
    }

    /**
     * Runs after the final attempt, OUTSIDE `handle()`'s scoped runner — so the tenant context has
     * to be established again.
     *
     * See the class docblock for why this reverts an enable and not a disable. Both branches are
     * best-effort and swallow their own failure: a `failed()` handler that throws loses the
     * `failed_jobs` row it was called about, so the durable record would be destroyed by the
     * attempt to annotate it.
     */
    public function failed(Throwable $exception): void
    {
        // Logged in BOTH directions and before anything else, because the divergence is the fact
        // worth having: the control plane and the index now disagree about this source, and on the
        // disable branch nothing below will change that.
        Log::error('source status sync did not reach the index', [
            'org_id' => $this->organizationId,
            'reason' => $this->sourceStatus,
            'outcome' => $this->revertTo === null ? 'left-diverged' : 'reverting',
        ]);

        if ($this->revertTo === null) {
            return;
        }

        try {
            /** @var TenantContext $tenancy */
            $tenancy = $this->container()->make(TenantContext::class);

            /** @var KnowledgeSourceRepositoryInterface $sources */
            $sources = $this->container()->make(KnowledgeSourceRepositoryInterface::class);

            $target = SourceState::from($this->revertTo);

            $tenancy->runFor($this->organizationId, function () use ($sources, $target): void {
                $source = $sources->find($this->organizationId, $this->sourceId);

                // THE SAME SUPERSESSION GUARD `handle()` APPLIES. `failed()` runs after the last
                // attempt, which is not the same thing as the source still being in the state this
                // job was dispatched for — an operator who pressed Enable, watched nothing happen
                // and pressed Disable would otherwise have their disable overwritten by this
                // revert, which is the one outcome worse than the divergence it is repairing.
                if ($source === null || $source->status->value !== $this->sourceStatus) {
                    return;
                }

                $sources->transition(
                    $this->organizationId,
                    $this->sourceId,
                    $target,
                    verified: false,
                    // REQUIRED BY THE INTERFACE AND DELIBERATELY EMPTY. There is no operation in
                    // `AuditLogger::OPERATIONS` for "the platform could not reach the index", the
                    // actor already has their `source.enabled` row, and inventing one is not this
                    // class's to invent — the same reasoning `SubmitIngestionJob::failed()` gives.
                    audit: static function (): void {},
                );
            });
        } catch (Throwable) {
            // See the docblock: losing the failed-job record in order to annotate it is worse.
        }
    }

    /**
     * The container, resolved through the facade root rather than the global helper — `failed()`
     * is invoked by the worker outside any request.
     */
    private function container(): Container
    {
        /** @var Container $container */
        $container = \Illuminate\Container\Container::getInstance();

        return $container;
    }
}
