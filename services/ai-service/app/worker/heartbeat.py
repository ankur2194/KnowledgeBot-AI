"""The file the worker healthcheck reads, and the only thing that writes it.

``services/ai-service/Dockerfile`` bakes ``kb-worker-healthcheck`` into the image and every
``ai-worker-*`` service in ``compose.yaml`` runs it. That probe has always taken a heartbeat arm
when the file exists and is fresh — what did not exist was a writer. Measured before this
module: all four workers sat ``unhealthy`` with a FailingStreak of 38-40 while
``celery inspect ping -d celery@$HOSTNAME`` answered ``pong`` in 1.1 s, because
``grep -rn kb-worker-heartbeat services/ai-service/`` matched nothing outside the Dockerfile.

WHY A TIMER BOOTSTEP AND NOT A TASK SIGNAL
------------------------------------------
The question the probe asks is *"is the worker main process still running its own loop?"*, and
the answer has to stay true on an **idle** worker. A heartbeat driven by ``task_prerun`` /
``task_postrun`` goes stale the moment a queue drains — which is exactly the state in which an
operator most needs the probe to be honest, and exactly the state that makes a healthy worker
report ``unhealthy`` and train everyone to ignore the signal.

``worker.timer`` runs in the **main** process, not in the prefork children, so this also stays
true while every child is saturated by a fifteen-minute OCR job. A worker whose main loop has
wedged stops touching the file within one interval; a worker that is merely busy does not. That
is the one distinction the probe exists to make.

The step requires ``celery.worker.components:Timer``: without that dependency the blueprint may
start this step before ``worker.timer`` exists, and ``None.call_repeatedly`` is an
``AttributeError`` inside worker startup rather than a missing heartbeat.

THE TWO NUMBERS ARE ONE NUMBER, WRITTEN DOWN IN TWO PLACES
----------------------------------------------------------
:data:`INTERVAL_SECONDS` is 30 and the probe's ``KB_WORKER_HEARTBEAT_MAX_AGE`` defaults to 120.
The Dockerfile states the constraint at its own copy — *"Must be >= 2x the bootstep's touch
interval. The bootstep is specified to touch every 30 s"* — so 120 s tolerates three
consecutive missed ticks. **Changing either number requires changing the other**, and they
cannot be shared through one constant because the probe is a standalone script that deliberately
imports nothing from ``app.``: that is precisely what lets it answer while the worker is wedged.

``KB_WORKER_HEARTBEAT_PATH`` is read from the environment rather than from ``Settings`` for the
same reason, and is recorded in ``EXTERNALLY_READ_VARIABLES`` in ``app/core/config.py``. Reading
it there keeps the writer and the reader pointed at one file when an operator retargets it.
"""

from __future__ import annotations

import logging
import os
from pathlib import Path
from typing import Any, ClassVar, Final

from celery import bootsteps

__all__ = ["DEFAULT_HEARTBEAT_PATH", "INTERVAL_SECONDS", "HeartbeatStep", "heartbeat_path"]

logger = logging.getLogger(__name__)

#: Must match ``kb-worker-healthcheck``'s own default in ``services/ai-service/Dockerfile``. The
#: containers do not set ``KB_WORKER_HEARTBEAT_PATH``, so this default is the live path, not a
#: fallback.
#:
#: noqa S108: this is not "a temporary file in a shared directory" in the sense the rule means.
#: The path is fixed by a probe baked into the image, the container filesystem is not shared, and
#: ``/tmp`` is the one writable location every one of these images has — the worker user does not
#: own ``/var/run``. Moving it means editing the Dockerfile in the same change.
DEFAULT_HEARTBEAT_PATH: Final[str] = "/tmp/kb-worker-heartbeat"  # noqa: S108

#: Seconds between touches. See the module docstring: this and the probe's 120 s staleness
#: ceiling are one decision recorded in two files.
INTERVAL_SECONDS: Final[float] = 30.0


def heartbeat_path() -> Path:
    """Where this process writes its heartbeat."""
    return Path(os.environ.get("KB_WORKER_HEARTBEAT_PATH") or DEFAULT_HEARTBEAT_PATH)


def touch(path: Path) -> None:
    """Advance the file's mtime, creating it if it is not there.

    ``Path.touch()`` rather than a write: the probe reads ``st_mtime`` and nothing else, so
    there is no content to keep consistent and no partial write to race.

    Failures are logged and swallowed. A read-only or full ``/tmp`` is a real condition, and the
    correct consequence is that the file goes stale and the probe reports unhealthy — not that
    an exception propagates out of the timer and takes down a worker that is otherwise
    processing jobs perfectly well.
    """
    try:
        path.touch()
    except OSError:
        logger.warning("could not touch the worker heartbeat at %s", path, exc_info=True)


# `celery.*` is under `ignore_missing_imports` in pyproject.toml — Celery ships no `py.typed` —
# so `bootsteps.StartStopStep` resolves to `Any` and `--strict` rejects subclassing it. Scoped to
# this one code on this one line rather than a module-level override: `warn_unused_ignores` is
# implied by `strict`, so the day Celery ships type information mypy fails on the now-redundant
# ignore and it gets deleted.
class HeartbeatStep(bootsteps.StartStopStep):  # type: ignore[misc]
    """Touch the heartbeat file every :data:`INTERVAL_SECONDS` from the worker's own timer."""

    #: NOT decoration. Celery's blueprint resolves start order from this, and without it the
    #: step can start before ``worker.timer`` exists — which is an ``AttributeError`` inside
    #: worker startup rather than a heartbeat that quietly never ticks.
    requires: ClassVar[set[str]] = {"celery.worker.components:Timer"}

    def __init__(self, worker: Any, **kwargs: Any) -> None:
        super().__init__(worker, **kwargs)
        self.path = heartbeat_path()
        self.tref: Any = None

    def start(self, worker: Any) -> None:
        # Touched once here as well as on the interval. Without it the file does not exist for
        # the first 30 s of a worker's life, and the probe's fallback arm — not its heartbeat
        # arm — is what answers during the window a slow start is most likely to be examined.
        touch(self.path)
        self.tref = worker.timer.call_repeatedly(INTERVAL_SECONDS, touch, (self.path,))
        logger.info("worker heartbeat started at %s every %ss", self.path, INTERVAL_SECONDS)

    def stop(self, worker: Any) -> None:
        """Cancel the timer entry.

        The file is deliberately NOT removed. A stopped worker whose heartbeat then goes stale
        is what the probe is built to report; deleting the file instead sends the probe down its
        fallback arm, which answers a different and weaker question.
        """
        if self.tref is not None:
            self.tref.cancel()
            self.tref = None
