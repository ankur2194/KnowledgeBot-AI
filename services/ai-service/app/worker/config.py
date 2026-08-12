"""Celery settings that are decisions, not defaults.

Anything Celery already defaults to correctly is absent. Every line here is present because
its default is wrong for this workload, and each carries what breaks without it.

The timing arithmetic is one chain, not five independent knobs:

    task_time_limit  <  visibility_timeout
    visibility_timeout >= 2 x (longest hard limit + retry_backoff_max + soft-shutdown)
                        = 2 x (960 + 600 + 300) = 3720, rounded up to 7200

Exceeding the visibility timeout redelivers a *still-running* task — two workers on one
source version, racing the active-version pointer.
"""

from __future__ import annotations

from typing import Any

# ── acknowledgement and replay ───────────────────────────────────────────────
task_acks_late = True  # ingestion is expensive; a crash must replay, not vanish
task_reject_on_worker_lost = True  # an OOM-killed child requeues instead of silently failing
task_acks_on_failure_or_timeout = True  # DEFAULT, kept deliberately: a hard-time-limit kill
#                                         must NOT replay, or a task that always exceeds its
#                                         limit runs forever

# ── fairness ─────────────────────────────────────────────────────────────────
# Default is 4. With long tasks, prefetching 4 means three jobs sit reserved on a busy
# worker while an idle worker has nothing — and the reserved ones are invisible to
# monitoring, so the queue looks empty while nothing progresses.
worker_prefetch_multiplier = 1
worker_eta_task_limit = 64  # bounds countdown-retry messages held in worker RAM

# ── shutdown ─────────────────────────────────────────────────────────────────
# Default False. Without it, a broker reconnect leaves the old task running against a
# connection that can no longer ack it, so it is redelivered and runs twice.
worker_cancel_long_running_tasks_on_connection_loss = True
# Requeue in-flight work on SIGTERM instead of stranding it. Every ai-worker-* container's
# stop_grace_period must exceed hard limit + this + margin.
worker_soft_shutdown_timeout = 300.0

# ── results ──────────────────────────────────────────────────────────────────
# There is no result backend. Job state is PostgreSQL plus the Laravel callbacks in
# kb-internal-api-contracts; a task's return value is discarded. A result backend here
# would be a second, unsynchronised source of truth for job status.
task_ignore_result = True
result_backend = None

# ── broker ───────────────────────────────────────────────────────────────────
# The URL is redis://, never valkey:// — kombu has no valkey transport alias. Valkey is a
# RESP-compatible drop-in behind the Redis transport, which issues only commands that
# predate the fork point.
#
# visibility_timeout must be set in ALL THREE places or the lowest wins. Where several apps
# share a broker DB the shortest configured value applies to all of them, INCLUDING an app
# that never set it and therefore contributes the 3600 s default.
broker_transport_options: dict[str, Any] = {"visibility_timeout": 7200, "socket_keepalive": True}
result_backend_transport_options: dict[str, Any] = {"visibility_timeout": 7200}
visibility_timeout = 7200
broker_connection_retry_on_startup = True

# ── routing ──────────────────────────────────────────────────────────────────
# A task with no route lands on `celery`, where no worker listens, and hangs with no error.
# Pointing the default at a queue that IS consumed turns that silent hang into a visible
# mis-routed job.
task_default_queue = "maintenance"

# One queue per cost class, one container per queue. Never route a 15-minute OCR job onto a
# queue a 5-second job shares.
#
# `maintenance` is separate from `evaluate` because deletion's purge and its verification
# reaper must not queue behind a 400-page crawl — a source stuck in Deleting is a visible
# product failure.
task_routes: dict[str, dict[str, str]] = {
    "kb.ingest.*": {"queue": "ingest"},
    "kb.crawl.*": {"queue": "crawl"},
    "kb.embed.*": {"queue": "embed"},
    # One task PER CASE, never per run: a 500-case run fits no time limit at all.
    "kb.evaluate.*": {"queue": "evaluate"},
    "kb.maintenance.*": {"queue": "maintenance"},
    "kb.deletion.*": {"queue": "maintenance"},
}

# ── time limits ──────────────────────────────────────────────────────────────
# Per-queue soft limits are set on the task decorator, not here, because they differ per
# queue: ingest 900, embed 600, crawl/evaluate/maintenance 300. Hard limit is soft + 60 s
# everywhere — that 60 s is the task's window to checkpoint and re-raise before SIGKILL.
# These process-wide values are the ceiling, so a task that forgets its own limits still
# cannot outrun the visibility timeout.
task_soft_time_limit = 900
task_time_limit = 960

# ── serialization ────────────────────────────────────────────────────────────
task_serializer = "json"
result_serializer = "json"
accept_content = ["json"]  # never pickle: a broker with pickle enabled is RCE by design
timezone = "UTC"
enable_utc = True
