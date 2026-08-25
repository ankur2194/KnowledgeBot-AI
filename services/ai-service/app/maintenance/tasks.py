"""Data-plane sweeps and reapers on the ``maintenance`` queue.

This module is currently **unowned**. It exists because `app/worker/__init__.py` names it in
``conf.imports``, and because the tasks below have no other home: they route to
``maintenance`` (so they are not `ingestion`'s), and they are periodic sweeps rather than
per-source work chained from a purge (so they are not `deletion`'s either).

`maintenance` is a separate queue from `evaluate` on purpose: a reaper must not sit behind a
400-page crawl or a 500-case eval run, because the states these tasks resolve are all
states a tenant can see.

Nothing is registered yet. Each task below is named, budgeted and assigned, and none is
declared — a beat entry naming a task that does not exist crashes beat on boot, silently,
and then nothing is scheduled at all.

Intended contents, with the owner each one needs:

    kb.maintenance.reap_stale_versions      hourly     — a version left mid-flight by a
                                                         worker that died between stages
    kb.maintenance.abort_stale_multipart    hourly     — object-storage multipart uploads
                                                         abandoned by a failed ingest; they
                                                         bill until aborted
    kb.maintenance.sweep_orphan_objects     daily      — objects with no surviving row. NARROWER
                                                         THAN IT READS, SINCE 2026-08-24: the
                                                         UPLOAD orphan — an object written by
                                                         `SourceService::create()` for a source that
                                                         never committed — is no longer this task's,
                                                         and cannot be. Its input is
                                                         `pending_source_objects`, a control-plane
                                                         table written before each object and
                                                         deleted after each commit, which nothing on
                                                         this plane can read; Laravel's
                                                         `kb:sweep-orphan-objects` owns it (security
                                                         finding S3). What is left here is the
                                                         post-purge residue — an object under a
                                                         prefix whose rows this plane deleted — and
                                                         it still has no owner.
    kb.maintenance.invalidate_caches        10 minutes — answer-cache eviction on config or
                                                         source change
    kb.maintenance.rebuild_index                       — the ADR-010 proof path; invoked by
                                                         hand and by CI, never by beat

Soft/hard limits are 300/360 on this queue. Every task here is idempotent and safe to run
twice, for the same reason every other task is: the broker guarantees at-least-once and
nothing else.
"""

from __future__ import annotations

__all__: list[str] = []

# TODO(unassigned): these tasks span ingestion, deletion and retrieval concerns without
# belonging to any one of them. Assign an owner before declaring any of them — a sweep
# written by whoever needed it first tends to acquire that subsystem's assumptions and
# then run against everyone's data.
