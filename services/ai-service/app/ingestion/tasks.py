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

from typing import Any, Final

from app.worker import celery_app

__all__ = [
    "DEADLINE_RESERVE_SECONDS",
    "HARD_TIME_LIMIT",
    "IDEMPOTENCY_TTL_SECONDS",
    "LOCK_TTL_SECONDS",
    "MAX_DELIVERIES",
    "OCR_PAGES_PER_TASK",
    "RETRY_BACKOFF_MAX_SECONDS",
    "SOFT_TIME_LIMIT",
    "ocr_page_batch",
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

#: Durable, counted in PostgreSQL per version — not `request.retries`, which a requeue does
#: not increment. Past the cap the version fails as `internal_dependency` and the task raises
#: `Ignore()`; re-raising would re-enter the retry path it is trying to leave.
MAX_DELIVERIES: Final[int] = 3

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
    raise NotImplementedError


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
    raise NotImplementedError


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
