"""The Celery application.

A package rather than a module because Compose runs `celery -A app.worker` and the settings
block is large enough to own its own file.
"""

from __future__ import annotations

from celery import Celery

from app.core.config import authenticated_valkey_url, get_settings
from app.observability.logging import install_celery_logging
from app.worker.heartbeat import HeartbeatStep
from app.worker.process import OcrEngineAssertionStep, install_worker_process_hooks

__all__ = ["celery_app"]

# BEFORE the Celery app is built, and at import rather than in a signal handler of our own.
#
# `celery -A app.worker` imports this module first and reaches `app.log.setup` afterwards, in
# both `celery worker` and `celery beat`. That call sends the `setup_logging` signal and then
# does `if not receivers:` around ALL of its own logging work — clearing `root.handlers`,
# clearing the `celery`/`celery.task`/`celery.redirected` handlers, installing its ColorFormatter
# and setting `celery.task.propagate = 0`. One connected receiver disables that hijack entirely
# (`worker_hijack_root_logger` defaults to True, so nothing else does).
#
# Connecting here rather than from `worker_process_init` is deliberate: `worker_process_init`
# fires in the prefork CHILDREN, long after the parent has already been hijacked, and `ai-beat`
# forks nothing at all so it would never fire there.
install_celery_logging()

# The same "connect at import, before Celery gets there" rule, for the three signals that build
# and tear down a process's telemetry and its runtime clients. Connecting is free and fires
# nothing; the receivers themselves are `app/worker/process.py`, which explains why there are
# three signals and not one.
install_worker_process_hooks()

_settings = get_settings()

# The credential is joined on here rather than living in KB_BROKER_URL. `users.acl` sets
# `user default off`, so an unauthenticated broker connection gets NOAUTH on its first
# command — which surfaces as a worker that starts, reports healthy, and consumes nothing.
celery_app = Celery(
    "kb",
    # `.get_secret_value()` on the line that builds the client and nowhere earlier: the joined
    # URL carries the ACL password, and Celery keeps `broker_url` verbatim in `app.conf`.
    broker=authenticated_valkey_url(
        _settings.broker_url,
        username=_settings.valkey_username,
        password_path=_settings.valkey_password_path,
    ).get_secret_value(),
)
celery_app.config_from_object("app.worker.config")
celery_app.conf.beat_schedule = {}  # populated from app.worker.schedule — see that module

# Explicit module paths, NOT `autodiscover_tasks([...])`.
#
# autodiscover_tasks takes *packages* and imports `<package>.tasks` from each — and nothing
# else. Any task defined in a sibling module (the embed-queue tasks, deletion's verification
# tasks) would silently never register, and an unregistered task is not an import error: the
# message is published, no worker recognises the name, and the job sits in the queue while
# the source stays mid-lifecycle. Naming every module here makes a missing one a startup
# ImportError instead.
#
# Deliberately NOT a filesystem walk over app/: that would import app.evaluation everywhere,
# and `import ragas` must RAISE outside ai-worker-evaluation for the containment to be real.
# app.evaluation.tasks is imported by the evaluation worker's own command line instead.
celery_app.conf.imports = (
    "app.ingestion.tasks",
    "app.crawl.tasks",
    "app.deletion.tasks",
    "app.maintenance.tasks",
)

# Two steps on the WORKER blueprint. `celery beat` has no worker blueprint, so neither runs
# there — which is correct for both: beat consumes no queue and its liveness is not the file
# `kb-worker-healthcheck` reads.
#
# `steps['worker']` is a set, so registering the same class twice is a no-op; this module is
# imported once per process by `celery -A app.worker`.
celery_app.steps["worker"].add(HeartbeatStep)
celery_app.steps["worker"].add(OcrEngineAssertionStep)
