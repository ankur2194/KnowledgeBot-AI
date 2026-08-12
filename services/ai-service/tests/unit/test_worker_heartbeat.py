"""The bootstep that writes the file ``kb-worker-healthcheck`` reads.

The probe has always taken its heartbeat arm when the file exists and is fresh. Nothing wrote
it, so all four workers sat ``unhealthy`` with a FailingStreak of 38-40 while a targeted
``celery inspect ping`` answered ``pong`` in 1.1 s. These tests are about the writer.

Two of them read the Dockerfile. That is deliberate: the probe is a standalone script that
imports nothing from ``app.`` — which is exactly what lets it answer while the worker is wedged
— so the path and the staleness window genuinely exist in two files and the only way to keep
them in step is to compare them.
"""

from __future__ import annotations

import re
from pathlib import Path
from typing import Any

import pytest

from app.worker import heartbeat

_DOCKERFILE = Path(__file__).resolve().parents[2] / "Dockerfile"


class _Timer:
    """Stands in for ``worker.timer``, recording what was scheduled."""

    def __init__(self) -> None:
        self.scheduled: list[tuple[float, Any, tuple[Any, ...]]] = []
        self.cancelled = 0

    def call_repeatedly(self, secs: float, fun: Any, args: tuple[Any, ...] = ()) -> Any:
        self.scheduled.append((secs, fun, args))
        timer = self

        class _Entry:
            def cancel(self) -> None:
                timer.cancelled += 1

        return _Entry()


class _Worker:
    def __init__(self) -> None:
        self.timer = _Timer()


@pytest.fixture
def at(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> Path:
    path = tmp_path / "kb-worker-heartbeat"
    monkeypatch.setenv("KB_WORKER_HEARTBEAT_PATH", str(path))
    return path


def test_the_file_exists_from_the_moment_the_worker_starts(at: Path) -> None:
    """Not 30 s later.

    ``call_repeatedly`` fires first AFTER one interval, so without the touch in ``start`` the
    probe spends the first half-minute of a worker's life on its fallback arm — which answers a
    different and weaker question, during precisely the window a slow start is examined.
    """
    worker = _Worker()
    assert not at.exists()

    heartbeat.HeartbeatStep(worker).start(worker)

    assert at.exists()


def test_the_heartbeat_is_driven_by_the_timer_and_not_by_task_traffic(at: Path) -> None:
    """A ``task_postrun``-driven heartbeat goes stale the moment a queue drains.

    That is the state in which the probe most needs to be honest, and it is the state in which
    such an implementation reports a perfectly healthy worker as unhealthy.
    """
    worker = _Worker()

    heartbeat.HeartbeatStep(worker).start(worker)

    assert len(worker.timer.scheduled) == 1
    secs, fun, args = worker.timer.scheduled[0]
    assert secs == heartbeat.INTERVAL_SECONDS
    assert args == (at,)

    at.unlink()
    fun(*args)
    assert at.exists(), "the scheduled callable does not touch the heartbeat"


def test_the_step_requires_the_timer_component(at: Path) -> None:
    """Without the dependency the blueprint may start this step before ``worker.timer`` exists,
    which is an ``AttributeError`` inside worker startup rather than a missing heartbeat."""
    assert heartbeat.HeartbeatStep.requires == {"celery.worker.components:Timer"}


def test_stopping_cancels_the_timer_but_leaves_the_file(at: Path) -> None:
    """A stopped worker whose heartbeat goes stale is what the probe is built to report.
    Deleting the file instead sends the probe down its fallback arm."""
    worker = _Worker()
    step = heartbeat.HeartbeatStep(worker)
    step.start(worker)

    step.stop(worker)

    assert worker.timer.cancelled == 1
    assert at.exists()


def test_a_write_failure_does_not_take_down_a_working_worker(
    tmp_path: Path, caplog: pytest.LogCaptureFixture
) -> None:
    """A full or read-only ``/tmp`` must make the heartbeat go STALE — which the probe reports —
    rather than raise out of the timer and kill a worker that is processing jobs fine."""
    unwritable = tmp_path / "no-such-directory" / "kb-worker-heartbeat"

    heartbeat.touch(unwritable)  # must not raise

    assert any("heartbeat" in record.message for record in caplog.records)


# ─────────────────────────────────────────────────────────────────────────────
# The two numbers that live in two files
# ─────────────────────────────────────────────────────────────────────────────


def _dockerfile_default(name: str) -> str:
    source = _DOCKERFILE.read_text(encoding="utf-8")
    match = re.search(rf'os\.environ\.get\("{name}",\s*"([^"]+)"\)', source)
    assert match is not None, f"{name} no longer has a literal default in {_DOCKERFILE}"
    return match.group(1)


def test_the_writer_and_the_probe_name_the_same_file() -> None:
    """The containers set no ``KB_WORKER_HEARTBEAT_PATH``, so both defaults are the live path.

    Two different defaults is a probe reading a file nothing writes — the exact state this whole
    item existed to end, reintroduced silently.
    """
    assert _dockerfile_default("KB_WORKER_HEARTBEAT_PATH") == heartbeat.DEFAULT_HEARTBEAT_PATH


def test_the_staleness_window_tolerates_missed_ticks() -> None:
    """The Dockerfile states the constraint at its own copy: ``MAX_AGE`` must be at least twice
    the touch interval, so a single slow tick is not an ``unhealthy`` container."""
    max_age = float(_dockerfile_default("KB_WORKER_HEARTBEAT_MAX_AGE"))

    assert max_age >= 2 * heartbeat.INTERVAL_SECONDS, (
        f"the probe tolerates {max_age}s of staleness while the bootstep touches every "
        f"{heartbeat.INTERVAL_SECONDS}s; one delayed tick would report a healthy worker as dead"
    )
