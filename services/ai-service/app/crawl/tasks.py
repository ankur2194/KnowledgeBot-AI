"""Celery tasks on the ``crawl`` queue. One task per URL.

``kb.crawl.*`` is already routed to ``crawl`` in ``app/worker/config.py``; the names below
must keep that prefix or they land on ``maintenance`` (the default queue) and run beside
deletion sweeps with the wrong time limits.

**Budgets, and how they compose.** ``soft_time_limit=300`` and ``time_limit=360`` on every
task here, matching ``celery-workers``' value for this queue. Inside that, one URL gets
``fetch.FETCH_WALL_CLOCK_SECONDS`` = 20 s of wall clock, and a render gets
``render.RENDER_BUDGET_SECONDS`` = 60 s. The 20 s figure is what makes a fetch task's budget
comfortable rather than tight: a slow origin consumes its own 20 s and yields, instead of
holding a prefork child until the soft limit fires and kills a task that had already
succeeded on several items with nothing recorded. The 60 s that separates the soft and hard
limits is the task's window to report what it finished and re-raise before SIGKILL.

**One task per URL, never one task per site.** A 400-page crawl in one task fits no time
limit that is also small enough to be useful, and every failure inside it is a failure of the
whole site. Per-URL tasks make failure per-page — one 500 does not fail a 200-page source —
and let the per-host limiter and the per-org concurrency cap actually schedule work, because
each unit of work is small enough to wait its turn.

**This task never writes to PostgreSQL, Qdrant or object storage.** Not by convention: the
crawl worker is on ``application`` and ``observability`` and has no route to the ``data``
network, so those connections cannot be opened from here at all (``app/crawl/fetch.py``
explains why that is the design and not an inconvenience). Every result is handed to
``ai-api`` over the signed internal API, and ``ai-api`` — which does sit on ``data`` —
persists it and reports to Laravel, which owns version activation.

That internal call is the **one** outbound request in this package that does not go through
``guarded_get``, and it must not: it targets a private address that the guard would refuse,
correctly. What keeps it safe is a single invariant, and it is the invariant to defend in
review — the callback URL is composed from ``Settings`` plus a code-defined path, and **no
part of it is ever derived from crawled content, a redirect target, a tenant configuration
field, or a discovered URL**. The moment a fetched page can influence that URL, this package
has two guarded fetches and one unguarded one aimed at the internal network.

**Retries are ``kb-error-taxonomy``'s, never Celery's.** No ``autoretry_for``, no
``retry_backoff``, no bare ``self.retry()``. ``CrawlRejected`` is never retried on any of its
eight reasons; a 404 is never retried; a timeout or a 5xx is. Two retry engines on one call
multiply attempts, and ``autoretry_for`` dispatches on exception *type* while one ``KbError``
type spans both retryable and permanent classes — so type dispatch retries an SSRF rejection
to the attempt cap.
"""

from __future__ import annotations

from typing import Any, Final

__all__ = [
    "CRAWL_SOFT_TIME_LIMIT",
    "CRAWL_TIME_LIMIT",
    "IDEMPOTENCY_OPERATION",
    "MAX_DELIVERIES",
    "SLOT_WAIT_RETRY_SECONDS",
    "discover_source",
    "fetch_url",
    "finalize_run",
]


#: ``celery-workers``' value for this queue. Declared on every task decorator, not inherited:
#: the process-wide ceiling in `app/worker/config.py` is 900/960, which is three times what a
#: crawl task should ever be allowed to hold a child for.
CRAWL_SOFT_TIME_LIMIT: Final[int] = 300
CRAWL_TIME_LIMIT: Final[int] = 360

#: A requeue is not a retry: ``request.retries`` stays 0 across an OOM or a lost worker, so
#: ``max_retries`` never trips and a task that reliably kills its child loops forever, pinning
#: a slot on a queue every tenant shares. Deliveries are counted durably in ``background_jobs``
#: through ``ai-api``.
MAX_DELIVERIES: Final[int] = 3

#: When the per-host or per-org slot is unavailable within the task's remaining budget, the
#: task re-queues with this countdown rather than sleeping. A sleeping task holds a prefork
#: child, which converts one tenant's politeness wait into lost capacity for everyone.
#: Comfortably under ``retry_backoff_max`` (600 s) and far under the 7200 s visibility timeout.
SLOT_WAIT_RETRY_SECONDS: Final[int] = 30

#: ``idem:{org_id}:{operation}:{key}``, with the key being ``{run_id}:{url_hash}``. Claimed
#: before any side effect and released before a retry so the retry can reclaim it. At-least-
#: once delivery is the broker's only guarantee; idempotency is ours.
IDEMPOTENCY_OPERATION: Final[str] = "crawl.fetch"


def discover_source(
    self: Any,
    *,
    org_id: str,
    source_id: str,
    run_id: str,
    scope: dict[str, Any],
    traceparent: str,
) -> None:
    """``kb.crawl.discover`` — robots, sitemaps, link discovery, and the fan-out.

    Builds the frontier once (``app/crawl/discovery.py``), truncates it to the page budget,
    records whether truncation happened, then dispatches one ``fetch_url`` per URL and one
    ``finalize_run`` behind them. Discovery completing before any page is fetched is what lets
    the run compare a complete URL set against the known set at the end; interleaving the two
    makes the missing-page arithmetic read a partial set as a shrinking site.

    Starts a **new root span** with a link to the submitter's ``traceparent`` from the job
    payload — never a child of it. The submitter is Laravel's recrawl dispatcher, whose
    request ended at 202 minutes or hours ago; a child span arriving after its parent closed
    is dropped by the tail sampler and the entire crawl becomes invisible in Tempo.

    Recrawl is dispatched by the Laravel scheduler onto its own ``ai-dispatch`` queue. Celery
    beat must never schedule it: two dispatchers for one schedule means every site crawls
    twice a night, which is exactly the cost the change detection in ``app/crawl/recrawl.py``
    exists to avoid.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


def fetch_url(
    self: Any,
    *,
    org_id: str,
    source_id: str,
    run_id: str,
    item_url: str,
    known_hash: str | None,
    etag: str | None,
    last_modified: str | None,
    js_render_approved: bool,
    traceparent: str,
) -> None:
    """``kb.crawl.fetch_url`` — one URL, end to end, and the only task that touches a socket.

    The sequence, and the failure boundary at each step:

    1. Claim ``idem:{org_id}:crawl.fetch:{run_id}:{url_hash}``; a replay returns immediately.
    2. Take the org slot, then the host slot, in that order
       (``app/crawl/politeness.py``). Unavailable within budget: re-queue with
       ``SLOT_WAIT_RETRY_SECONDS``, which is a delay and not an error.
    3. Check robots. A refusal is ``PageDisposition.SKIPPED``, reported and done.
    4. ``guarded_get`` with the conditional headers we hold, inside the 20 s wall clock. A 304
       is ``UNCHANGED`` and resets the missing counter.
    5. ``extract_page`` on the body as ``raw:<html>``; ``should_render`` may escalate once for
       an approved source, and a render is recorded as ``render_mode`` on the result.
    6. ``normalize_for_hash`` and compare. Equal is ``UNCHANGED`` — no version, no embedding,
       no index write, which is the point of the whole exercise.
    7. Report one ``PageOutcome`` to ``ai-api``. Failing to report is
       ``INTERNAL_DEPENDENCY`` and retryable; failing to fetch is ``CRAWL`` and retryable only
       for the transient shapes.

    Failure is per page, always. This task raising does not fail the run: ``finalize_run``
    reads what was reported and decides. A crawl that indexes 3 of 200 pages and reports
    success is a worse outcome than a crawl that fails loudly, and the arithmetic that makes
    that distinction lives in ``recrawl.plan_run``.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


def finalize_run(
    self: Any,
    *,
    org_id: str,
    source_id: str,
    run_id: str,
    traceparent: str,
) -> None:
    """``kb.crawl.finalize`` — the run-level verdict, computed once over the whole run.

    Reads back every ``PageOutcome`` from ``ai-api``, builds a ``RecrawlPlan``, evaluates the
    circuit breaker, and reports the plan. Emits ``kb_crawl_runs_total{outcome}`` and
    ``kb_crawl_duration_seconds`` — run-level health on the shared ``outcome`` enum, while
    per-page results were counted on ``disposition`` (``app/crawl/recrawl.py`` states why the
    two labels differ, and it is not cosmetic).

    No metric emitted anywhere in this package carries the host or the URL as a label. A crawl
    target set is tenant-controlled and therefore unbounded, and one tenant with a large
    sitemap becomes a cardinality incident in Prometheus rather than a crawl.

    Applies no missing policy itself. It reports the plan; ``ai-api`` and Laravel act on it,
    because disabling a source is a control-plane decision with an audit row attached.
    """
    raise NotImplementedError("TODO(crawler-engineer)")


# TODO(crawler-engineer): decorate all three with
#   @celery_app.task(bind=True, name="kb.crawl.<n>", queue="crawl",
#                    soft_time_limit=CRAWL_SOFT_TIME_LIMIT, time_limit=CRAWL_TIME_LIMIT,
#                    max_retries=None)
# once the bodies exist. They are plain functions today so that importing this module in the
# API image registers nothing — `app/worker/__init__.py` autodiscovers `app.crawl`, and a
# task registered but unroutable is a job that hangs with no error.
