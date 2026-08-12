"""The ``evaluate`` queue — where an eval run starts, and where the corpus gate sits.

Deliberately **not** in ``app/worker/__init__.py``'s ``conf.imports``. This module is imported
by the evaluation worker's own command line and by nothing else, because ``import ragas`` must
raise in ``ai-api`` and in every ingestion worker for the dependency containment to be real —
ragas drags langchain, langchain-core, langchain-community, langchain_openai, datasets,
networkx and scikit-network behind it, none of them optional.
``tests/unit/test_worker_imports.py`` holds that exclusion in place.

**Fan out one task per case, never one per run.** A 500-case run at three judge calls each
fits inside no sane ``task_time_limit``, and a hard-limit kill loses the whole run rather than
one case. The result backend is off (``task_ignore_result``), so there is no chord: each case
task writes its own ``evaluation_results`` row and the last one to decrement a durable counter
chains the aggregation task explicitly, exactly as ``kb-deletion-and-verification`` chains
``verify`` after ``purge``.

**Why the corpus gate is here and not in a script.** ``start_run`` calls ``verify_corpus()``
before it does anything else — before a fixture is ingested, before a case is dispatched,
before a single judge call spends the organization's quota. A verification script that runs
"when someone remembers" protects nothing, and the specific way it fails is silent: the corpus
drifts, the score moves, and the movement gets attributed to whatever landed that week. The
gate is doubled structurally by ``EvaluationRunRecord``, which takes the ``CorpusVerification``
object rather than a boolean, so there is no path to a comparable run record that skipped it.

**The ``evaluate`` queue must not starve ingestion or crawl.** It is its own queue on its own
container for that reason (``app/worker/config.py``), and the limits below are the 300 s
soft / 360 s hard the queue's cost class carries. A run is long; a *case* is not, and the case
is the unit that runs here.
"""

from __future__ import annotations

from typing import Any, Final

from app.evaluation.corpus import verify_corpus
from app.worker import celery_app

__all__ = ["HARD_TIME_LIMIT", "SOFT_TIME_LIMIT", "aggregate_run", "score_case", "start_run"]

#: The ``evaluate`` cost class. Hard is soft + 60 s everywhere — that minute is the task's
#: window to checkpoint and re-raise before SIGKILL.
SOFT_TIME_LIMIT: Final[int] = 300
HARD_TIME_LIMIT: Final[int] = 360


@celery_app.task(  # type: ignore[misc]
    bind=True,
    name="kb.evaluate.start_run",
    queue="evaluate",
    soft_time_limit=SOFT_TIME_LIMIT,
    time_limit=HARD_TIME_LIMIT,
    max_retries=0,
)
def start_run(self: Any, *, org_id: str, dataset_id: str, run_id: str) -> None:
    """Begin an evaluation run. ``kb.evaluate.start_run``, queue ``evaluate``.

    ``max_retries=0`` on purpose. Every way this task fails is a configuration or corpus
    problem that the identical retry reproduces exactly, and a retried run start is a second
    run id spending a second run's worth of judge quota.

    Order, and the first step is the gate:

    1. ``verify_corpus()`` — recompute every fixture digest and refuse on any mismatch,
       missing fixture, placeholder digest, or non-vacuity floor violation. Raises
       ``CorpusUnverified``, which maps to ``validation`` in ``kb-error-taxonomy``: nothing
       upstream is broken, this run's inputs are wrong, and it is never retryable.
    2. Seed the corpus into the evaluation organization through the **real** ingestion
       pipeline, and materialise the manifest-id -> ``source_version_id`` pins.
    3. Resolve the embedding identity and the rerank capability for the org's connection, and
       build the ``EvaluationRunRecord`` — which cannot be constructed without step 1's
       ``CorpusVerification``.
    4. Show the run's estimated judge spend, then fan out one ``score_case`` per case.

    Every judge call this run makes goes through the organization's own provider connection
    and writes a ``provider_calls`` row with ``operation="evaluation.judge"`` and this
    ``run_id``. There is deliberately no platform-level judge credential: a second credential
    path is spend no tenant dashboard shows and no quota bounds.
    """
    verification = verify_corpus()
    raise NotImplementedError(
        f"run {run_id} for org {org_id}, dataset {dataset_id}: corpus "
        f"{verification.corpus_version} verified over {verification.files_hashed} files; "
        "seeding, identity resolution and case fan-out are not implemented"
    )


@celery_app.task(  # type: ignore[misc]
    bind=True,
    name="kb.evaluate.score_case",
    queue="evaluate",
    soft_time_limit=SOFT_TIME_LIMIT,
    time_limit=HARD_TIME_LIMIT,
    max_retries=2,
)
def score_case(self: Any, *, org_id: str, run_id: str, case_id: str) -> None:
    """Score one golden case. ``kb.evaluate.score_case``, queue ``evaluate``.

    Runs the **production chat entry point**, not a hand-rolled retrieval call, so all 20
    stages of ``kb-rag-query-contract`` execute and the four mandatory Qdrant filters are
    built by production code. A harness that queries Qdrant directly — or passes
    ``allowed_version_ids=None`` "because it is just eval" — measures a system we do not ship
    and searches a corpus wider than any tenant can see, so retrieval quality reads *better*
    than production.

    The deterministic half is computed first and costs nothing: refusal, false refusal, false
    answer, the CRAG +1/0/-1, source recall, citation validity, latency. Then, and only for a
    case that was answered, the two judged metrics.

    A refusal is **skipped**, not scored. Ragas returns ``nan`` when statement extraction
    yields nothing — exactly what a refusal produces — and its legacy aggregator drops those
    rows via ``np.nanmean``, so a run with more refusals reports a *higher* faithfulness and
    improving refusal behaviour raises the score. The skip is recorded so the denominator
    stays honest, and the gate fails when the skip count moves at all.

    ``import ragas`` happens inside this body and nowhere else in the package.
    """
    raise NotImplementedError(
        f"case {case_id} of run {run_id} for org {org_id}: kb-rag-query-contract stages 1-20 "
        "through the production entry point, then the judged metrics"
    )


@celery_app.task(  # type: ignore[misc]
    bind=True,
    name="kb.evaluate.aggregate_run",
    queue="evaluate",
    soft_time_limit=SOFT_TIME_LIMIT,
    time_limit=HARD_TIME_LIMIT,
    max_retries=0,
)
def aggregate_run(self: Any, *, org_id: str, run_id: str) -> None:
    """Aggregate a finished run and decide whether it produced a publishable score.

    Chained explicitly by the last case task, never a chord — there is no result backend to
    hold one, and a ``GroupResult`` over 500 tasks is state we would have to keep alive for
    the length of the run.

    **Never ``np.nanmean``.** A dropped row is a finding, not a missing value: every mean is
    reported with its ``n`` and its skip count beside it. A run with any judge error produces
    no score at all — not a lower one — which is enforced by ``EvaluationRunRecord.
    baseline_key`` refusing a record whose ``judge_calls_errored`` is non-zero.

    Comparison against ``samples/expected/baseline.json`` goes through
    ``app.evaluation.run.compare_or_refuse``, which refuses rather than emitting a delta when
    the corpus version, the manifest digest, the dataset version, the embedding identity, the
    rerank configuration or the judge pin differ. A run that skipped reranking has no baseline
    key at all and is recorded without being compared.
    """
    raise NotImplementedError(
        f"aggregation of run {run_id} for org {org_id}: paired differences with standard "
        "errors clustered by source_version_id, per arXiv:2411.00640"
    )


assert HARD_TIME_LIMIT == SOFT_TIME_LIMIT + 60
