"""The Celery timing chain is arithmetic, not preference — so it is asserted, not reviewed.

    task_time_limit  <  visibility_timeout
    visibility_timeout >= 2 x (longest hard limit + retry_backoff_max + soft-shutdown)

Exceeding the visibility timeout redelivers a **still-running** task: two workers on one
source version, racing the active-version pointer. Nothing errors. The first symptom is a
source published from the loser's data, and by then the evidence is gone.

The five knobs live in four files (``app/worker/config.py`` plus the per-queue constants each
task module declares), so no reviewer sees the whole chain in one diff. That is what this
file is for.

It runs with no broker, no containers and no Celery installed: ``app/worker/config.py``
imports nothing but ``typing``, and the per-queue constants are read out of the source with
``ast``. See ``tests/support/celery_config.py`` for why loading by path is deliberate.
"""

from __future__ import annotations

import ast
from pathlib import Path
from typing import Final

import pytest

from tests.support.celery_config import load_worker_config
from tests.support.tree import APP_ROOT

config = load_worker_config()

#: The maximum retry backoff a task decorator may declare (`celery-workers`). Not in
#: ``config.py`` — it is a per-task decorator argument — so it is pinned here as the value the
#: visibility-timeout arithmetic was computed against. If a task ever declares a larger
#: backoff, this constant is the thing that has to move first, and moving it fails the chain
#: test below unless the visibility timeout moves with it.
RETRY_BACKOFF_MAX: Final[int] = 600

#: The gap between a soft and a hard limit, everywhere. It is the task's window to catch
#: ``SoftTimeLimitExceeded``, checkpoint its progress, and re-raise before SIGKILL arrives.
#: A smaller gap means a killed task loses the work it had done; a larger one means a wedged
#: task holds its slot longer than the queue's fairness assumptions allow.
CHECKPOINT_WINDOW: Final[int] = 60

#: The queues one container each consumes. `task_default_queue` must be one of these.
EXPECTED_QUEUES: Final[frozenset[str]] = frozenset(
    {"ingest", "crawl", "embed", "evaluate", "maintenance"}
)


# ─────────────────────────────────────────────────────────────────────────────
# Per-queue limits, read out of the task modules
# ─────────────────────────────────────────────────────────────────────────────


def _module_level_ints(path: Path) -> dict[str, int]:
    """Module-level ``NAME: Final[int] = <literal>`` assignments, without importing.

    Importing ``app.ingestion.tasks`` executes ``app/worker/__init__.py``, which needs Celery
    and a populated environment. The numbers are literals; ``ast`` reads them exactly.
    """
    tree = ast.parse(path.read_text(encoding="utf-8"), filename=str(path))
    found: dict[str, int] = {}
    for node in tree.body:
        target: ast.expr | None = None
        if isinstance(node, ast.AnnAssign):
            target = node.target
            value = node.value
        elif isinstance(node, ast.Assign) and len(node.targets) == 1:
            target = node.targets[0]
            value = node.value
        else:
            continue
        if (
            isinstance(target, ast.Name)
            and isinstance(value, ast.Constant)
            # `not isinstance(..., bool)`: `True` is an `int` in Python, and a flag picked up
            # as a time limit would compare as 1 second and pass every arithmetic check.
            and isinstance(value.value, int)
            and not isinstance(value.value, bool)
        ):
            found[target.id] = value.value
    return found


def _declared_limits() -> dict[str, tuple[int, int]]:
    """``{relative path: (soft, hard)}`` for every module declaring a time limit."""
    limits: dict[str, tuple[int, int]] = {}
    for path in sorted(APP_ROOT.rglob("*.py")):
        constants = _module_level_ints(path)
        if "SOFT_TIME_LIMIT" not in constants and "HARD_TIME_LIMIT" not in constants:
            continue
        rel = str(path.relative_to(APP_ROOT.parent))
        assert "SOFT_TIME_LIMIT" in constants, f"{rel} declares a hard limit and no soft limit"
        assert "HARD_TIME_LIMIT" in constants, f"{rel} declares a soft limit and no hard limit"
        limits[rel] = (constants["SOFT_TIME_LIMIT"], constants["HARD_TIME_LIMIT"])
    return limits


DECLARED_LIMITS: Final[dict[str, tuple[int, int]]] = _declared_limits()


def test_some_module_actually_declares_a_time_limit() -> None:
    """The positive control for every parametrized test below.

    Without it, a rename of ``SOFT_TIME_LIMIT`` empties the discovery and the whole
    per-queue section passes by iterating nothing — the exact shape of a suite that goes
    green because it stopped testing.
    """
    assert DECLARED_LIMITS, f"no SOFT_TIME_LIMIT/HARD_TIME_LIMIT pair found under {APP_ROOT}"


@pytest.mark.parametrize(("module", "limits"), sorted(DECLARED_LIMITS.items()))
def test_every_queue_pairs_a_soft_limit_with_a_hard_limit_sixty_seconds_later(
    module: str, limits: tuple[int, int]
) -> None:
    """soft + 60 == hard, on every queue.

    Get it backwards — hard below soft — and the process is killed before the soft signal
    ever fires, so no task ever checkpoints and every timeout loses its work. Set them equal
    and the same thing happens with no gap at all. Neither raises at configuration time.
    """
    soft, hard = limits
    assert hard == soft + CHECKPOINT_WINDOW, module
    assert 0 < soft < hard, module


@pytest.mark.parametrize(("module", "limits"), sorted(DECLARED_LIMITS.items()))
def test_no_queue_outruns_the_process_wide_ceiling(module: str, limits: tuple[int, int]) -> None:
    """The process-wide limits are a ceiling, not a suggestion.

    A task declaring a hard limit above ``task_time_limit`` is killed by the process limit
    first, at a point its own checkpoint window was never sized for.
    """
    _, hard = limits
    assert hard <= config.task_time_limit, module


# ─────────────────────────────────────────────────────────────────────────────
# The process-wide chain
# ─────────────────────────────────────────────────────────────────────────────


def test_the_process_wide_limits_use_the_same_checkpoint_window() -> None:
    assert config.task_time_limit == config.task_soft_time_limit + CHECKPOINT_WINDOW


def test_the_hard_limit_is_below_the_visibility_timeout() -> None:
    """The first link in the chain, and the one that redelivers a running task when broken."""
    assert config.task_time_limit < config.visibility_timeout


def test_the_visibility_timeout_covers_two_full_task_lifetimes() -> None:
    """``2 x (longest hard limit + retry_backoff_max + soft-shutdown)``.

    Two, not one: a task may be redelivered once and must be able to run to completion again
    inside the window, or the redelivery is itself redelivered and the queue amplifies.
    """
    longest_hard = max(
        [config.task_time_limit, *(hard for _soft, hard in DECLARED_LIMITS.values())]
    )
    required = 2 * (longest_hard + RETRY_BACKOFF_MAX + int(config.worker_soft_shutdown_timeout))
    assert config.visibility_timeout >= required, (
        f"visibility_timeout={config.visibility_timeout} but the chain needs {required} "
        f"(2 x ({longest_hard} + {RETRY_BACKOFF_MAX} + {int(config.worker_soft_shutdown_timeout)}))"
    )


def test_the_visibility_timeout_is_set_in_all_three_places_with_one_value() -> None:
    """Set it in one place and the lowest configured value wins — including the 3600 s
    default contributed by any other app sharing the broker DB that never set it at all."""
    assert config.broker_transport_options["visibility_timeout"] == config.visibility_timeout
    assert (
        config.result_backend_transport_options["visibility_timeout"] == config.visibility_timeout
    )


# ─────────────────────────────────────────────────────────────────────────────
# Routing
# ─────────────────────────────────────────────────────────────────────────────


def test_no_task_can_land_on_a_queue_no_worker_consumes() -> None:
    """Celery's default queue is ``celery`` and no container listens there.

    An unrouted task published to it does not error: it sits in the queue while the source
    stays mid-lifecycle and the admin progress bar stops moving. Pointing the default at a
    queue that *is* consumed turns that silent hang into a visibly mis-routed job.
    """
    assert config.task_default_queue != "celery"
    assert config.task_default_queue in EXPECTED_QUEUES


def test_every_route_targets_a_known_queue() -> None:
    """A queue invented in a route is a queue with no container and no ``QUEUE_DEPTH``
    series, so the backlog is invisible to the dashboard as well as to the worker."""
    targets = {route["queue"] for route in config.task_routes.values()}
    assert targets <= EXPECTED_QUEUES, targets - EXPECTED_QUEUES


def test_every_route_pattern_is_namespaced() -> None:
    """Task names carry the ``kb.<group>.<op>`` dot namespace; the routes match on it.

    A bare pattern would also match a third-party library's task name if one ever entered
    the app, and route someone else's work onto our queues.
    """
    for pattern in config.task_routes:
        assert pattern.startswith("kb."), pattern


def test_deletion_shares_maintenance_rather_than_a_cost_class_queue() -> None:
    """Deliberate: a source stuck in ``Deleting`` is a visible product failure, so the purge
    and its verification must not queue behind a 400-page crawl."""
    assert config.task_routes["kb.deletion.*"]["queue"] == "maintenance"
    assert config.task_routes["kb.maintenance.*"]["queue"] == "maintenance"
    assert config.task_routes["kb.crawl.*"]["queue"] == "crawl"


# ─────────────────────────────────────────────────────────────────────────────
# Safety settings whose defaults are wrong for this workload
# ─────────────────────────────────────────────────────────────────────────────


def test_pickle_is_not_accepted_anywhere() -> None:
    """A broker with pickle enabled is remote code execution by design: anything that can
    publish a message can run code in every worker."""
    assert config.accept_content == ["json"]
    assert config.task_serializer == "json"
    assert config.result_serializer == "json"


def test_expensive_work_replays_instead_of_vanishing() -> None:
    """``acks_late`` plus ``reject_on_worker_lost``: an OOM-killed child requeues.

    Without the pair, a worker killed mid-ingestion loses the job silently — the source sits
    in a processing state forever with no failed job to find.
    """
    assert config.task_acks_late is True
    assert config.task_reject_on_worker_lost is True


def test_a_hard_time_limit_kill_does_not_replay() -> None:
    """The counterweight to ``acks_late``. Without it, a task that *always* exceeds its limit
    is redelivered forever and occupies a worker slot permanently."""
    assert config.task_acks_on_failure_or_timeout is True


def test_a_broker_reconnect_cannot_leave_a_task_running_twice() -> None:
    """Default is ``False``: the old task keeps running against a connection that can no
    longer ack it, so the message is redelivered and the work happens twice."""
    assert config.worker_cancel_long_running_tasks_on_connection_loss is True


def test_long_tasks_are_not_hoarded_by_one_worker() -> None:
    """Default prefetch is 4. With 15-minute tasks that means three jobs sit reserved on a
    busy worker while an idle worker has nothing — and reserved jobs are invisible to queue
    monitoring, so the queue looks empty while nothing progresses."""
    assert config.worker_prefetch_multiplier == 1


def test_there_is_no_result_backend() -> None:
    """Job state is PostgreSQL plus the Laravel callbacks. A result backend would be a
    second, unsynchronised source of truth for job status."""
    assert config.result_backend is None
    assert config.task_ignore_result is True


def test_the_broker_url_scheme_is_not_asserted_here() -> None:
    """Documenting a deliberate gap: ``config.py`` holds no broker URL.

    The ``valkey://`` rejection lives on ``Settings`` and is asserted with the settings
    tests, not here. Restating it against a value this module does not own would be an
    assertion that passes forever regardless of the code.
    """
    assert not hasattr(config, "broker_url")


def test_times_are_utc() -> None:
    """A beat schedule in local time silently shifts twice a year, and the sweep that stops
    running is only noticed weeks later."""
    assert config.timezone == "UTC"
    assert config.enable_utc is True
