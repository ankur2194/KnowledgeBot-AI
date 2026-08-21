<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SourceState;
use App\Exceptions\KbException;
use App\Repositories\Contracts\KnowledgeSourceRepositoryInterface;
use App\Services\Internal\InternalAiClient;
use App\Services\Sources\IngestionSubmission;
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
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Contracts\Queue\TracingLinked;
use Throwable;

/**
 * Hand one source to the data plane. THE FIRST JOB CLASS IN THIS APPLICATION.
 *
 * ── THE THREE ATTRIBUTES ARE ONE ARITHMETIC, AND EVERY ARROW MATTERS ──────────────────────────
 *
 *     Timeout(60)  <  the `valkey` connection's retry_after (180)  <  UniqueFor(1800)
 *                                                                 <  WithoutOverlapping 240
 *
 * `laravel-queues-valkey` states the rule as `--timeout < retry_after < lock TTL`, and every arrow
 * that points the wrong way produces a SILENT duplicate or a permanent wedge rather than an error.
 * A timeout above `retry_after` lets the reservation expire while the job is still running, so a
 * second worker pops it and submits the same source twice; a lock TTL below `retry_after` lets the
 * redelivery in while the first holder is still going, which is the same failure reached from the
 * other side. `expireAfter` and `UniqueFor` both DEFAULT TO NEVER EXPIRING, and a lock a SIGKILLed
 * worker never released is a source that can never be ingested again with nothing in `failed_jobs`
 * to say so.
 *
 * UNDER HORIZON THERE IS NO `queue:work --timeout` TO SET. The supervisor owns the left-hand side
 * (120 s in `config/horizon.php`), and `#[Timeout(60)]` is this job's own tighter ceiling — a
 * submission that has not returned in a minute is not going to.
 *
 * ── `ShouldBeEncrypted` IS NOT OPTIONAL ON THIS PAYLOAD ───────────────────────────────────────
 *
 * The payload sits in Valkey in plaintext and in `failed_jobs` INDEFINITELY until
 * `queue:prune-failed` runs. It carries `organization_id` and `source_id`, which are exactly the
 * tenant-bearing identifiers `kb-security-baseline` puts inside the retention scope. It carries no
 * credential and no document text — both of those are resolved at execution time, the credential by
 * the data plane's own provider accessor and the content by the object store — but a job that is
 * tenant-bearing at all is encrypted, because the rule is a property of the class rather than an
 * assessment of its current fields.
 *
 * ── ULIDs, NEVER ELOQUENT MODELS ──────────────────────────────────────────────────────────────
 *
 * `SerializesModels` re-queries the model at handle time, which means the row is read under
 * whatever tenant scope the WORKER has — and the worker's scope is the pooled, possibly-stale one
 * this job sets explicitly in `handle()`. Passing a model would make the ownership of the row
 * depend on the very thing the job exists to establish.
 *
 * ── THE TENANT CONTEXT IS SET ON ENTRY AND CLEARED IN A `finally` ─────────────────────────────
 *
 * `kb-tenancy-isolation`: worker processes are pooled, so tenant context is a resource that retains
 * its previous occupant. NO tenant set is the loud failure — `OrganizationScope` fails closed with
 * `whereRaw('1 = 0')`. The PREVIOUS tenant still set is the silent one, and it is the shape of
 * CVE-2023-28859. The `finally` is what makes the second case unreachable; the explicit
 * `organization_id` argument on every repository call is what makes it survivable if it ever is.
 *
 * ── RETRY OWNERSHIP: THIS JOB, AND NOTHING ELSE ON THIS PATH ──────────────────────────────────
 *
 * `kb-error-taxonomy`'s ownership table permits exactly one retrying tier for the Laravel->FastAPI
 * call, and for JOB SUBMISSION it is the job. `InternalAiClient::submitIngestion()` carries no
 * `->retry()` for that reason: attempts multiply across tiers, and 5 x 3 is fifteen submissions
 * from one button.
 */
#[Tries(5), Timeout(60), UniqueFor(1800)]
final class SubmitIngestionJob implements ShouldBeEncrypted, ShouldBeUniqueUntilProcessing, ShouldQueue, TracingLinked
{
    use Queueable;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $sourceId,
        /** The dispatch identity, stamped onto every item so a superseded run's callbacks are ignored. */
        public readonly string $jobId,
        /** The reprocess request id. Null on a first submission; a ULID on an explicit reprocess. */
        public readonly ?string $forceNonce = null,
        /** The admin who asked, for `X-KB-Actor-Id`. Null for scheduler-initiated work. */
        public readonly ?string $actorId = null,
    ) {
        $this->onConnection('valkey')->onQueue('ai-dispatch');
    }

    /**
     * ORG-PREFIXED, ALWAYS. A bare source id collides across tenants, and this key resolves through
     * the DEFAULT cache store — which is `valkey-core`, the `noeviction` instance, precisely because
     * an evicted lock key reads as "lock is free" with nothing raised.
     *
     * ── IT CARRIES THE JOB ID, AND KEYING IT ON THE SOURCE INSTEAD IS A SILENT DROP ───────────
     *
     * The obvious key is `{org}:{source}`, so that an operator pressing Reprocess twice enqueues
     * once. It is wrong, and the failure is invisible: `ShouldBeUniqueUntilProcessing` releases its
     * lock when processing BEGINS, so while a submission sits in the queue a SECOND, legitimate
     * dispatch for the same source is DISCARDED — no exception, no failed job, nothing in the log.
     * The reprocess has already re-stamped `current_job_id` on every item and moved the source to
     * `queued` by then, so the row ends up claimed for a run nobody will ever submit, and the
     * console shows "queued" forever. Measured, not reasoned: the first version of this class keyed
     * on the source and `Queue::assertPushed` saw exactly one job for two reprocesses.
     *
     * The double-press it was meant to stop is already refused, one layer up and loudly:
     * `queued -> queued` is not an edge of `SourceState::transitionTable()`, so the second press is
     * a 422 that names the field rather than a 202 for a job that was thrown away. What this key
     * still buys is the genuine at-least-once case — the identical dispatch delivered twice —
     * because two deliveries of ONE run share a job id and two runs never do.
     */
    public function uniqueId(): string
    {
        return "{$this->organizationId}:{$this->jobId}";
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            // 240 > the connection's retry_after of 180, so a worker killed at its timeout leaves a
            // lock that SELF-HEALS instead of wedging the source forever. `expireAfter` defaults to
            // 0, which `Cache::lock($key, 0)` reads as NEVER EXPIRES — the permanent wedge.
            //
            // The stored key is `xxh128(job display name) . this string` unless `shared()` is
            // called, so the literal below is not what is in Valkey; grep for the job class name
            // when looking for it. The `lock:{org}:…` grammar is still written out because it is
            // what `valkey-keyspaces` catalogues and what an org-wide purge walks by prefix.
            (new WithoutOverlapping("lock:{$this->organizationId}:ingest-submit:{$this->sourceId}"))
                ->expireAfter(240)
                ->releaseAfter(60),

            // A CLOSURE AND NEVER THE ARRAY FORM. `FailOnException`'s array form dispatches on
            // exception TYPE, and one `KbException` spans both halves of the taxonomy — so type
            // dispatch would retry an `authorization` or a `validation` refusal to the attempt cap
            // and then again when an operator replays the failed job. The taxonomy dispatches on
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
        // Auto-instrumentation injected `traceparent` at dispatch and `TracingLinked` made this a
        // LINKED ROOT rather than a child — a child span arriving after its dispatching request
        // closed is dropped by the tail sampler, and the whole submission becomes invisible. Only
        // the attributes are ours, and `kb.org_id` is an ATTRIBUTE and never a metric label.
        Span::getCurrent()->setAttributes([
            'kb.org_id' => $this->organizationId,
            'kb.operation' => 'ingestion.submit',
            'messaging.message.attempt' => $this->attempts(),
        ]);

        // `runFor()` AND NOT A SETTER PAIR. The class offers only the scoped runner, and its own
        // docblock says why: a setter can be called and not unset, and the code that forgets is a
        // queue worker whose next job then reads and writes as the previous tenant. The `finally`
        // is inside `runFor()`, so there is no path out of this closure that leaves the context
        // bound — including the `FailOnException` middleware rethrowing through it.
        $tenancy->runFor($this->organizationId, function () use ($sources, $ai): void {
            $source = $sources->find($this->organizationId, $this->sourceId);

            if ($source === null) {
                // The source was deleted between dispatch and execution — a reprocess raced a
                // delete, which is ordinary. RETURN rather than fail: there is nothing to submit
                // and nothing an operator could do about a `failed_jobs` row describing it.
                return;
            }

            if ($source->status !== SourceState::Queued) {
                // THE RUN ALREADY MOVED ON. A redelivery of this job after the data plane's first
                // callback landed would otherwise submit a second time and mint a second Celery
                // task for a version already parsing. `Queued` is the only state a submission is
                // meaningful from, and the check reads the ROW rather than trusting the payload.
                Span::getCurrent()->setAttribute('kb.idempotent_replay', true);

                return;
            }

            $items = $sources->itemsFor($this->organizationId, $this->sourceId);

            if ($items === []) {
                // A submitted source with no item is the one shape `kb-source-lifecycle` forbids,
                // and the repository creates the item in the same transaction as the source — so
                // reaching here means the graph was torn down under us. Nothing to submit.
                return;
            }

            if ($items[0]->current_job_id !== $this->jobId) {
                // SUPERSEDED BEFORE IT RAN. A second reprocess re-stamped every item while this
                // dispatch sat in the queue; submitting now would put two live runs on one item
                // and both would race the active-version pointer.
                Span::getCurrent()->setAttribute('kb.superseded_dispatch', true);

                return;
            }

            $ai->submitIngestion(
                $this->organizationId,
                new IngestionSubmission($this->jobId, $source, $items, $this->forceNonce),
                $this->actorId,
            );
        });
    }

    /**
     * Runs after the final attempt, OUTSIDE `handle()`'s scoped runner — so no tenant context is
     * bound here and it has to be established again.
     *
     * ── THE SOURCE IS MOVED TO `Failed`, AND THAT IS THE POINT OF THIS METHOD ─────────────────
     *
     * A submission that never reached the data plane leaves a source sitting in `Queued` forever,
     * which reads in the console as "still working" and is indistinguishable from a slow document.
     * `Queued -> Failed` is an edge of the table, it is reviewable, and — this is the part that
     * matters — the PRIOR VERSION KEEPS SERVING, because failure is terminal for the RUN and not
     * for the item.
     *
     * NO AUDIT ROW, AND THE ABSENCE IS A DECISION. Every `source.*` operation in the catalog is an
     * act a PERSON took, and this is the platform failing to complete one they already have a
     * `source.created` or `source.reprocess.requested` row for. The `failed_jobs` row plus the
     * state change is the record. Inventing an operation would mean adding one to
     * `AuditLogger::OPERATIONS`, which is not this change's to add — it is reported instead.
     *
     * IT SWALLOWS ITS OWN FAILURE. A `failed()` handler that throws loses the `failed_jobs` row it
     * was called about, so the durable record would be destroyed by the attempt to annotate it.
     * The state change is best-effort; the failed job is the truth.
     */
    public function failed(Throwable $exception): void
    {
        try {
            /** @var TenantContext $tenancy */
            $tenancy = $this->container()->make(TenantContext::class);

            /** @var KnowledgeSourceRepositoryInterface $sources */
            $sources = $this->container()->make(KnowledgeSourceRepositoryInterface::class);

            $tenancy->runFor($this->organizationId, function () use ($sources): void {
                // ── THE SAME TWO GUARDS `handle()` APPLIES, FOR THE SAME REASON ──────────────
                //
                // `failed()` runs after the LAST attempt, which is not the same thing as the run
                // having failed. The submission has a 20 s timeout and the far side does not
                // rollback: a 202 lost to a timeout means the Celery task IS running, its first
                // callback has already moved this source to `parsing`, and forcing `failed` on top
                // of it is worse than the state it replaces. `Failed -> Parsing` is not an edge of
                // the table, so every subsequent frame 422s as `validation` — non-retryable, so
                // the worker gives up — the run completes on the far side, and nothing is ever
                // activated. The source is stuck with no operator action that recovers it.
                //
                // `Queued` is therefore the only state this may write from, exactly as it is the
                // only state a submission is meaningful from. Reading the ROW rather than trusting
                // that the attempt count implies anything about the far side.
                $source = $sources->find($this->organizationId, $this->sourceId);

                if ($source === null || $source->status !== SourceState::Queued) {
                    return;
                }

                // AND SUPERSESSION. A second reprocess re-stamped every item while this dispatch
                // was exhausting its attempts; the source is `queued` for the NEW run, and failing
                // it here would kill a submission that has not been tried yet.
                $items = $sources->itemsFor($this->organizationId, $this->sourceId);

                if ($items === [] || $items[0]->current_job_id !== $this->jobId) {
                    return;
                }

                $sources->transition(
                    $this->organizationId,
                    $this->sourceId,
                    SourceState::Failed,
                    verified: false,
                    // REQUIRED BY THE INTERFACE AND DELIBERATELY EMPTY. The closure is required so
                    // no caller can write a state change with no row; here there is no operation in
                    // the catalog to write, and a no-op that says so in a comment is more honest
                    // than an interface that makes the argument optional for everyone.
                    audit: static function (): void {},
                );
            });
        } catch (Throwable) {
            // See the docblock: losing the failed-job record to annotate it is strictly worse.
        }
    }

    /**
     * The container, resolved through the facade root rather than the global helper.
     *
     * `failed()` is invoked by the worker outside any request, and `app()` is the same lookup with
     * less to say about it. Kept as one private method so a test can see the single resolution
     * point, and so `handle()`'s constructor injection stays the normal path.
     */
    private function container(): Container
    {
        /** @var Container $container */
        $container = \Illuminate\Container\Container::getInstance();

        return $container;
    }
}
