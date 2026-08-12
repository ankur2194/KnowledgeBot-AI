"""Celery beat entries — data-plane sweeps only.

Empty on purpose. An entry naming a task that does not exist makes beat crash on boot, and
beat crashing is silent: nothing is scheduled, no job fails, and the first symptom is a
sweep that quietly stopped running weeks ago.

**Recrawl is not here and never will be.** Recrawl scheduling and maintenance orchestration
belong to the Laravel scheduler. Two schedulers for one schedule means duplicate ticks, and
recrawl duplication means a source re-versioned twice a night.

The intended entries, to be uncommented by their owning agent as each task lands.

**This list is not yet reconciled.** `celery-workers` states beat owns exactly six entries
and names a different six from the ones the deletion and ingestion modules actually
registered — for instance `kb.deletion.verify_source_purged` is per-source and chained from
its purge, so it is not a sweep and does not belong here at all, and no
`sweep_orphan_objects` task was written. Do not populate `beat_schedule` from this comment;
reconcile it against the registered task names first. That reconciliation is an open item.

    kb.deletion.sweep_pending_purges     every 5 minutes   — deletion-engineer
    kb.maintenance.reap_stale_versions   hourly            — ingestion-engineer
    kb.maintenance.abort_stale_multipart hourly            — ingestion-engineer
    kb.maintenance.apply_retention       daily             — deletion-engineer
    kb.maintenance.invalidate_caches     every 10 minutes  — retrieval-engineer

Only one beat process exists (`ai-beat`, replicas: 1) and its schedule persists on the
`beat-schedule` volume.
"""

from __future__ import annotations

from typing import Any

__all__ = ["beat_schedule"]

beat_schedule: dict[str, dict[str, Any]] = {}
