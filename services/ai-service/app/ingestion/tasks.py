"""The `ingest` queue's Celery tasks.

Three properties every task in this module has, none of which any Celery setting supplies:

* **Idempotent, because the broker guarantees at-least-once and nothing else.** Late acks,
  visibility-timeout redelivery, `reject_on_worker_lost` and connection-loss replay all
  re-execute a task that already ran, and every one of those is a normal Tuesday rather than
  an incident. Idempotency is a property of *our* code: an idempotency claim before any side
  effect, a Valkey lock held for the whole run, stage outputs committed before a stage
  returns, and deterministic point ids so a replayed upsert overwrites instead of duplicating.
  Re-running a completed version must produce zero net writes and zero net points.
* **`kb-error-taxonomy` decides every retry, so `autoretry_for` is never used.** Two retry
  engines on one call multiply attempts, and `autoretry_for` dispatches on exception *type*
  while the taxonomy dispatches on `error_class` — one `KbError` type spans both retryable and
  never-retryable classes, so type dispatch cheerfully retries an unsupported file until the
  attempt cap and then again when an operator replays the failed job. Classify, look the class
  up, and only then decide. `retry_backoff` and a bare `self.retry()` are the same mistake
  wearing different clothes.
* **A task starts a NEW ROOT span with a link to the submitter, never a child.** The
  `traceparent` travels in the job payload, not in the ambient context. A child span arriving
  forty minutes after its parent's HTTP request closed at 202 is dropped by the tail sampler,
  and the whole job becomes invisible — no trace, no stage timings, and nothing to explain a
  version that took nine minutes. `CeleryInstrumentor` still parents to the submitter by
  default; instrument with `use_span_links=True` *and* start the root explicitly.

Delivery is not retry. A requeue after an OOM leaves `request.retries` at 0, so `max_retries`
never trips and a document that reliably kills the child loops forever, pinning a worker slot.
`max_retries=None` here is not "retry forever" — it hands the cap to the taxonomy — and the
durable delivery counter is what bounds redelivery.

**Routing.** `app/worker/config.py` routes the glob `kb.ingest.*` to the `ingest` queue, and
fnmatch requires the literal `kb.ingest.` prefix — a task named `kb.ingest_version` matches
nothing, falls to `task_default_queue`, and runs on the maintenance worker beside the deletion
reaper. Every name below carries the dot. `queue=` on the decorator is belt-and-braces; the
route is the mechanism.

The embedding, upsert and verification half of the pipeline is fanned out to `kb.embed.*` on
the `embed` queue, which has its own soft limit (600 s). It is a separate queue for a reason
that changed and did not go away: it used to be separate because it held the model weights, and
it is separate now because it is the only ingestion stage that spends **money** and the only one
whose throughput is set by somebody else's rate limit. A provider brownout must back up behind
its own queue rather than starving parsing. Those tasks are not in this module and must not
inherit these limits — see the TODO at the bottom.
"""

from __future__ import annotations

import logging
import time
from typing import Any, Final

from celery.exceptions import Ignore

from app.core.errors import ErrorClass, KbError
from app.ingestion import deliveries as deliveries_counter
from app.ingestion import intake, runner
from app.worker import celery_app
from app.worker.process import run_in_worker, worker_clients

logger = logging.getLogger(__name__)

__all__ = [
    "DEADLINE_RESERVE_SECONDS",
    "HARD_TIME_LIMIT",
    "IDEMPOTENCY_TTL_SECONDS",
    "LOCK_TTL_SECONDS",
    "MAX_DELIVERIES",
    "OCR_PAGES_PER_TASK",
    "PREPARE_HARD_TIME_LIMIT",
    "PREPARE_SOFT_TIME_LIMIT",
    "RETRY_BACKOFF_MAX_SECONDS",
    "SOFT_TIME_LIMIT",
    "ocr_page_batch",
    "prepare_item",
    "run_version",
]

#: §19.4's ingestion budget, via `kb-error-taxonomy`. The 300 s document-parse timeout nests
#: inside this; never set the inner one lower, because a truncated parse returns partial
#: success rather than raising and the missing pages simply never index.
SOFT_TIME_LIMIT: Final[int] = 900

#: Soft + 60 s, everywhere. That minute is the task's only window to checkpoint and re-raise
#: before SIGKILL. It is also the number the visibility-timeout arithmetic is built on:
#: 2 x (960 + 600 + 300) = 3720, rounded up to the configured 7200.
HARD_TIME_LIMIT: Final[int] = 960

#: The per-`source_item_id` lock, held for the whole run. Above the hard limit so a killed
#: worker's lock self-heals rather than wedging the item, and below the visibility timeout so
#: a genuine redelivery can eventually proceed.
LOCK_TTL_SECONDS: Final[int] = HARD_TIME_LIMIT + 60

#: The claim outlives the run by a wide margin: a claim that expires mid-run lets a redelivery
#: start a second run of the same version, which is the case the claim exists to prevent.
IDEMPOTENCY_TTL_SECONDS: Final[int] = 86_400

#: Time left un-spent before the soft limit so a stage boundary can be checkpointed. A stage
#: whose p95 does not fit in the remainder yields instead of being killed mid-upsert.
DEADLINE_RESERVE_SECONDS: Final[int] = 30

#: Retry countdowns are capped here, full-jitter. A countdown at or above the visibility
#: timeout re-executes forever — the ETA loop the Celery docs warn about.
RETRY_BACKOFF_MAX_SECONDS: Final[int] = 600

#: Durable, counted in VALKEY per unit of work — not `request.retries`, which a requeue does not
#: increment. Past the cap the version fails as `internal_dependency` and the task raises
#: `Ignore()`; re-raising would re-enter the retry path it is trying to leave.
#:
#: THE STORE MOVED, AND THE SENTENCE ABOVE USED TO SAY "IN PostgreSQL". It was counted by an
#: `UPDATE source_versions` — a write to a Laravel-owned table this service is not permitted to
#: write and which `IngestionProgress` says it must never be permitted to write.
#: `app/ingestion/deliveries.py` carries the whole account. The cap and its behaviour are
#: unchanged; only the writer is.
#:
#: IT NOW BOUNDS THE PRE-IDENTITY PHASE TOO (`docs/22` § Q9). `prepare_item` runs before any
#: version row exists, with `max_retries=None`, so its redeliveries had nowhere durable to be
#: counted and the give-up decision had no memory across worker restarts. A Valkey key does not
#: need the row, so both phases are bounded by this one number.
MAX_DELIVERIES: Final[int] = 3

#: The prepare phase's own budget. It reads configuration, makes ONE five-string probe call and
#: posts ONE frame — no page is opened and no chunk is embedded — so the 900 s document budget
#: would only ever mean a wedged provider call holding an `ingest` slot for fifteen minutes.
#: 90 s is generous for a single embedding round trip against a cold vendor.
PREPARE_SOFT_TIME_LIMIT: Final[int] = 90

#: Soft + 60 s, the same relationship every other pair in this module has, and for the same
#: reason: that minute is the task's only window to re-raise before SIGKILL.
PREPARE_HARD_TIME_LIMIT: Final[int] = PREPARE_SOFT_TIME_LIMIT + 60

#: 30 s/page x 10 pages = the 300 s document budget exactly. Beyond ten pages a scan fans out
#: rather than growing the task, because a task sized past its budget returns partial content
#: and publishes as a minor warning.
OCR_PAGES_PER_TASK: Final[int] = 10


# The ignore is for `disallow_untyped_decorators`: Celery ships no type information, so the
# decorator resolves to `Any` and would silently make every task body untyped under strict
# mypy. Remove it only if Celery starts shipping a py.typed marker — an unused ignore is itself
# an error under strict.
@celery_app.task(  # type: ignore[misc]
    bind=True,
    name="kb.ingest.run_version",
    queue="ingest",
    soft_time_limit=SOFT_TIME_LIMIT,
    time_limit=HARD_TIME_LIMIT,
    max_retries=None,
)
def run_version(
    self: Any,
    *,
    org_id: str,
    job_id: str,
    source_version_id: str,
    ingest_key: str,
    traceparent: str | None = None,
) -> None:
    """Run one source version to verified readiness. Safe to run twice; safe to kill anywhere.

    Stage order is fetch → parse → OCR-if-needed → normalize → chunk → embed → index → verify
    → report. Each stage is idempotent on `source_version_id` and commits its output to
    PostgreSQL or object storage before returning, which is what makes "complete" observable
    from the outside.

    **Resume, never restart.** On re-entry the first *incomplete* stage is the entry point.
    Restarting from the top re-parses a 300-page scan that already succeeded and burns the
    whole budget before reaching the stage that failed; restarting mid-stage re-reads a
    half-written element set. Those are the only two options if stage completion is not
    durable, which is why it is.

    The shape, in order, none of it optional:

    0. `job_id` is the SUBMISSION's, threaded from `prepare_item` and never re-read from
       `source_items.current_job_id`. Laravel compares the two and answers a mismatch with
       `stale_job` — a 200 with `applied: false` — so a run that reported under the current
       job id would have its frames applied as if it were the reprocess that superseded it.
    1. New root span, linked to `traceparent` from the payload. Attributes: `kb.org_id`,
       `kb.operation`, `kb.job_id`, `messaging.redelivered`.
    2. Bump the durable delivery counter; past `MAX_DELIVERIES`, fail the version and
       `Ignore()`.
    3. Claim `idem:{org_id}:ingestion.run:{ingest_key}` — a single atomic set-if-absent, never
       a read followed by a write, whose losing side runs the whole pipeline twice.
    4. Take the Valkey lock on the item for the whole run. Two workers on one version race the
       pointer, and the redelivery that produces them is invisible to both.
    5. Walk stages from the first incomplete one, checking the deadline before each.
    6. `publish_version(...)`: verify, then report readiness. Activation is Laravel's.

    On failure: classify, record `error_class` on the span, and consult the taxonomy. A
    non-retryable class or an exhausted cap fails the version — reviewable, prior version still
    serving — and raises `Ignore()`. A retryable class releases the idempotency claim *before*
    retrying, because the retry must be able to reclaim it, and backs off full-jitter capped at
    `RETRY_BACKOFF_MAX_SECONDS`.

    The classes this task raises: `storage` for an object-store read or write, `parsing` for an
    unreadable or rejected document, `ocr` for a page that raised inside the engine,
    `vector_indexing` for an upsert or verification failure, `validation` for a payload that
    fails the identifier shape check or a chunk measuring above `MAX_TOKENS`, and
    `internal_dependency` for everything unrecognized — unknown is permanent, never temporary.
    The embed fan-out adds the five `provider_*` classes, which arrive already classified from
    the provider layer and are never re-derived from an HTTP status here.

    Returns nothing, and the return value is discarded: there is no result backend, and job
    state lives in PostgreSQL and the Laravel callbacks.
    """
    clients = worker_clients()
    started = time.monotonic()

    with runner.root_span(
        "kb.ingest.run_version",
        traceparent=traceparent,
        attributes={
            "kb.org_id": org_id,
            "kb.operation": "ingestion.run",
            "kb.job_id": source_version_id,
            "messaging.redelivered": bool(getattr(self.request, "delivery_info", {}) or {}),
        },
    ) as span:
        try:
            run_in_worker(
                runner.run_one_version(
                    clients=clients,
                    org_id=org_id,
                    job_id=job_id,
                    source_version_id=source_version_id,
                    ingest_key=ingest_key,
                    deadline=lambda: SOFT_TIME_LIMIT
                    - DEADLINE_RESERVE_SECONDS
                    - (time.monotonic() - started),
                    max_deliveries=MAX_DELIVERIES,
                    lock_ttl_seconds=LOCK_TTL_SECONDS,
                    claim_ttl_seconds=IDEMPOTENCY_TTL_SECONDS,
                )
            )
        except Ignore:
            # Already terminal: the version was failed and reported inside the runner. Re-raising
            # anything else here would re-enter the retry path the runner just left.
            raise
        except BaseException as exc:
            failure = runner.classify(exc)
            span.set_attribute("kb.error_class", failure.error_class.value)

            if not failure.retryable:
                # A non-retryable class fails the version — reviewable, prior version still
                # serving — and raises `Ignore()` rather than propagating: propagating puts the
                # task back on the ladder it is trying to leave.
                run_in_worker(
                    runner.fail_version(
                        clients=clients,
                        org_id=org_id,
                        job_id=job_id,
                        source_version_id=source_version_id,
                        error_class=failure.error_class,
                    )
                )
                raise Ignore from exc

            # RELEASE THE CLAIM BEFORE RETRYING, and this ordering is the whole of it: the retry
            # must be able to RECLAIM the key, and a claim held across the countdown makes every
            # retry a no-op that looks like a successful replay.
            run_in_worker(
                runner.release_claim(clients=clients, org_id=org_id, ingest_key=ingest_key)
            )
            raise self.retry(
                exc=exc,
                countdown=runner.backoff(
                    attempt=self.request.retries, cap=RETRY_BACKOFF_MAX_SECONDS
                ),
            ) from exc


@celery_app.task(  # type: ignore[misc]
    bind=True,
    name="kb.ingest.prepare_item",
    queue="ingest",
    soft_time_limit=PREPARE_SOFT_TIME_LIMIT,
    time_limit=PREPARE_HARD_TIME_LIMIT,
    max_retries=None,
)
def prepare_item(
    self: Any,
    *,
    org_id: str,
    job_id: str,
    source_id: str,
    item: dict[str, Any],
    force_nonce: str | None = None,
    traceparent: str | None = None,
) -> None:
    """Establish one item's version identity, then dispatch the run that does the work.

    **The seam's step 0.** `app/ingestion/intake.py` explains at length why this phase exists
    and why it is short; the summary is that Laravel creates `source_versions` from a callback
    carrying six components only this service can compute, so nothing can be written against a
    version until one round trip has happened.

    ITS OWN TIME LIMITS, MUCH SHORTER THAN THE RUN'S. This task reads configuration, makes one
    five-string probe call and posts one frame. Nothing here is per-page or per-chunk, so
    inheriting the 900 s document budget would mean a wedged provider call holds an `ingest`
    slot for fifteen minutes while doing none of the work that budget was sized for.

    A DURABLE REDELIVERY BOUND, AND IT IS NOT AN IDEMPOTENCY CLAIM. The two are easy to confuse
    and do opposite things: a claim stops a SECOND copy running concurrently, and the counter
    stops an ENDLESS SERIES of copies running one after another. This task takes the second and
    refuses the first, for the reason in the next paragraph. `docs/22` § Q9 is the entry that
    asked whether the pre-identity phase should have the counter at all; it should, and the
    reason it did not was that the counter used to live on a row this phase has not created yet.

    NO IDEMPOTENCY CLAIM, DELIBERATELY. The claim belongs to the run and is keyed on the ingest
    key, which does not exist until this task has finished computing it. A claim taken here
    would have to be released before `run_version` could take its own, and a redelivery landing
    in that window would find a prepared item and do nothing with it — the version would sit at
    `parsing` forever with no worker attached. Repetition is safe instead: the identity is a
    pure function of content and configuration, so a second delivery resolves to the same row.

    A DUPLICATE DISPATCH IS ALSO SAFE, and that is what makes the previous paragraph affordable:
    two `run_version` messages for one version race for the claim `run_one_version` takes, and
    the loser returns without doing anything.
    """
    clients = worker_clients()

    with runner.root_span(
        "kb.ingest.prepare_item",
        traceparent=traceparent,
        attributes={
            "kb.org_id": org_id,
            "kb.operation": "ingestion.prepare",
            "kb.job_id": job_id,
            "messaging.redelivered": bool(getattr(self.request, "delivery_info", {}) or {}),
        },
    ) as span:
        # ── THE PRE-IDENTITY REDELIVERY BOUND (`docs/22` § Q9) ────────────────────────────────
        #
        # This task carries `max_retries=None` and there is no version row to hold a counter, so
        # a payload that fails retryably on every delivery retried forever with no durable memory
        # of having done so — the exact case a durable counter exists for, in the one phase that
        # did not have one. The counter is keyed on the JOB and the ITEM rather than on a version,
        # because that is the unit being redelivered here; `app/ingestion/deliveries.py` explains
        # why the store is Valkey and why that is what made this bound possible at all.
        #
        # PAST THE CAP THERE IS STILL NOTHING TO REPORT, and that asymmetry with `run_version` is
        # deliberate rather than an omission: no `source_versions` row exists, so there is no
        # `fail_version` to call and no frame to send. The item stays at `queued` — an admin sees
        # a source that did not start — and the log line below is the whole of the operator's
        # signal, for the same reason § R7 gave.
        deliveries = run_in_worker(
            deliveries_counter.bump(
                clients.cache,
                org_id=org_id,
                scope=deliveries_counter.prepare_scope(job_id=job_id, item_id=str(item.get("id"))),
            )
        )
        span.set_attribute("kb.ingest.delivery_count", deliveries)
        if deliveries > MAX_DELIVERIES:
            logger.error(
                "ingestion prepare abandoned",
                extra={
                    "org_id": org_id,
                    "job_id": job_id,
                    "source_id": source_id,
                    "delivery_count": deliveries,
                    "max_deliveries": MAX_DELIVERIES,
                },
            )
            span.set_attribute("kb.ingest.prepare_outcome", "abandoned")
            raise Ignore

        try:
            prepared = run_in_worker(
                intake.prepare_item(
                    clients=clients,
                    org_id=org_id,
                    job_id=job_id,
                    source_id=source_id,
                    item=intake.SubmittedItem(**item),
                    force_nonce=force_nonce,
                )
            )
        except BaseException as exc:
            failure = runner.classify(exc)
            span.set_attribute("kb.error_class", failure.error_class.value)
            if not failure.retryable:
                # NO `fail_version` CALL, AND ITS ABSENCE IS THE WHOLE DIFFERENCE FROM
                # `run_version`. There is no version row yet — that is what this task exists to
                # create — so there is nothing to mark failed and no `source_version_id` to name
                # in a frame. The item stays at whatever status it held, which is `queued`, and
                # an admin sees a source that did not start rather than one that half-ran.
                #
                # THE LOG LINE IS THE ONLY THING AN OPERATOR GETS, AND IT WAS MISSING. Because
                # this branch reports nothing to the control plane, the span attribute above was
                # the sole record — and a span goes to a collector, which is exactly what is not
                # running when somebody is trying to work out why a source sits at `queued`. The
                # first real submission on this stack died here and left `Task ... ignored` from
                # Celery and nothing else in any log, in either plane (`docs/22` § R7). The
                # decision to leave the item at `queued` is unchanged; only its visibility is.
                logger.error(
                    "ingestion prepare refused",
                    extra={
                        "org_id": org_id,
                        "job_id": job_id,
                        "source_id": source_id,
                        "error_class": failure.error_class.value,
                        # A `KbError`'s message is OURS — written in this repository, and the
                        # ingestion refusals are sentences meant to be read by an operator. Any
                        # other exception contributes its TYPE only: `str()` on an arbitrary
                        # third-party exception can carry a credential, a connection string or
                        # raw upstream provider text, and Non-negotiable 9 has no ingestion
                        # exemption.
                        "reason": str(exc) if isinstance(exc, KbError) else type(exc).__name__,
                    },
                )
                raise Ignore from exc
            raise self.retry(
                exc=exc,
                countdown=runner.backoff(
                    attempt=self.request.retries, cap=RETRY_BACKOFF_MAX_SECONDS
                ),
            ) from exc

        if prepared.source_version_id is None:
            # `live_version_unchanged`, or a frame the control plane refused. Either way there
            # is no work and dispatching would be the expensive mistake — see `intake`.
            span.set_attribute("kb.ingest.prepare_outcome", prepared.reason or "not_applied")
            return

        span.set_attribute("kb.ingest.prepare_outcome", "dispatched")
        run_version.apply_async(
            kwargs={
                "org_id": org_id,
                "job_id": job_id,
                "source_version_id": prepared.source_version_id,
                "ingest_key": prepared.ingest_key,
                "traceparent": traceparent,
            },
        )


@celery_app.task(  # type: ignore[misc]
    bind=True,
    name="kb.ingest.ocr_page_batch",
    queue="ingest",
    soft_time_limit=SOFT_TIME_LIMIT,
    time_limit=HARD_TIME_LIMIT,
    max_retries=None,
)
def ocr_page_batch(
    self: Any,
    *,
    org_id: str,
    source_version_id: str,
    page_numbers: list[int],
    ocr_cfg_version: str,
    traceparent: str | None = None,
) -> None:
    """OCR at most `OCR_PAGES_PER_TASK` pages of one version and commit their text.

    Fanned out by `run_version` when the page classifier finds more pages needing OCR than fit
    one document budget. Idempotent on `(source_version_id, page_number)`: a replay re-writes
    the same element rows for the same pages rather than appending a second set, so the
    chunker downstream cannot see a page twice.

    Both limits are declared, and both are load-bearing for different reasons. The soft limit
    raises from a Python signal handler, which a thread inside a Pillow, Leptonica or ONNX
    Runtime C loop never returns to the interpreter to receive — so on a pathological image the
    soft limit does nothing at all and the hard limit is the only thing that kills the child.
    Any subprocess spawned here carries its own timeout for the same reason.

    A page that raised is `error_class="ocr"` — retryable per page and bounded. A page that
    merely OCRed *badly* is not an error: it is a warning, the version still publishes, and the
    per-page result is `disposition="partial"`, which is deliberately not one of the four
    shared `outcome` values because a partial page must not read as a failed job.
    """
    if len(page_numbers) > OCR_PAGES_PER_TASK:
        # Refused rather than trimmed. A task sized past its budget returns partial content and
        # publishes as a minor warning — which is the failure this cap exists to prevent, so
        # silently taking the first ten would reproduce it while looking like a guard.
        raise KbError(
            ErrorClass.VALIDATION,
            f"{len(page_numbers)} pages exceeds OCR_PAGES_PER_TASK={OCR_PAGES_PER_TASK}. "
            "The fan-out is the caller's to size; trimming here would drop pages silently and "
            "the version would publish missing exactly the text OCR was run for",
            retryable=False,
        )

    clients = worker_clients()
    with runner.root_span(
        "kb.ingest.ocr_page_batch",
        traceparent=traceparent,
        attributes={
            "kb.org_id": org_id,
            "kb.operation": "ingestion.ocr",
            "kb.job_id": source_version_id,
            "kb.page_count": len(page_numbers),
        },
    ):
        run_in_worker(
            runner.ocr_pages(
                clients=clients,
                org_id=org_id,
                source_version_id=source_version_id,
                page_numbers=tuple(page_numbers),
                ocr_cfg_version=ocr_cfg_version,
            )
        )


# TODO(ingestion-engineer): the `kb.embed.*` half of the pipeline — embed a chunk batch, upsert
# it, verify the version total, report readiness. It belongs on the `embed` queue and it does
# NOT inherit the limits above: soft_time_limit=600 / time_limit=660 per `celery-workers`.
# Autodiscovery imports `app.ingestion.tasks` and no other module in this package, so those
# tasks land in this file with their own limits, or autodiscovery must name their module
# explicitly. Declaring them here at 900/960 would put an externally rate-limited task on the
# parse worker's budget and starve the parse queue behind it.
#
# Embedding is an external API call now, and that changes four things about these tasks. None
# of them is a Celery setting:
#
# 1. **A redelivery costs money.** Every other stage's replay wastes CPU; this one re-bills the
#    vendor for text already paid for. So the idempotency claim is per BATCH — the key is
#    `idem:{org_id}:embed:{source_version_id}:{batch_index}` and the batch index comes from
#    `plan_batches`, which is deterministic for exactly this reason. A completed batch's vectors
#    are committed before the task returns, so a replay re-upserts (free, deterministic ids) and
#    does not re-embed.
# 2. **A credential travels this path and must not travel in the payload.** Task arguments are
#    serialized to the broker, land in a failed-job record, and are read by anything that
#    instruments task args. Non-negotiable 9 has no ingestion exemption: the task carries the
#    `provider_connection_id`, resolves and decrypts at execution time through the provider
#    layer's own accessor, and holds the plaintext only inside the call. Nothing under
#    `app/ingestion/` accepts a credential parameter — see `EmbedCallable`, which has no field
#    for one. A provider error is classified and re-raised with the vendor's response body
#    DISCARDED: an embedding-error body routinely echoes the input, and the input is tenant
#    document text.
# 3. **Failure classes are the provider ones, and only two of them may be retried here.**
#    `provider_rate_limit` (honour Retry-After as a floor) and `provider_temporary` are
#    retryable and bounded; `provider_auth`, `provider_billing` and `provider_permanent_request`
#    fail the version immediately — an exhausted account and a wrong model id never self-heal,
#    and retrying them burns the delivery cap on a certainty. Fallback to a second provider is
#    NOT available on this path even for the two eligible classes: a different vendor is a
#    different vector space, so an embedding "fallback" writes incomparable vectors into the
#    collection and is the alias-drift failure committed on purpose. `embedder.resolve_identity`
#    pins which model this version was embedded under; a fallback would make that a lie.
# 4. **The identity is resolved once and pinned for the whole version.** Re-probing per batch
#    would let a mid-run vendor swap split one version across two spaces — half a document in
#    each — which no total-based verification can see, because the point total is still exactly
#    right.
