"""Purge and proof. Owner: deletion-engineer.

Phase 2 of the two-phase delete, and nothing else. Phase 1 — status to `Deleting`, the
active-version pointer cleared, every version retired, bot assignments deactivated, caches
invalidated, all in ONE committed transaction — belongs to `services/core-api/` and has
already returned to the admin before any task here is allowed to start. That ordering is
what makes phase 2 safe to run asynchronously: no query path can reach the source any more,
because the active-version and status terms of the retrieval filter already exclude it, so
taking minutes to destroy the artifacts costs correctness nothing.

Run the phases the other way round and a query already past the filter stage retrieves a
half-purged version — chunk rows gone but points alive, or points alive but the object
holding the citation excerpt already swept. The user sees a citation that resolves to
nothing, which is worse than the stale answer the delete was meant to prevent.

    filters.py       the seven-key allow-list, and the only module that may build a payload
                     key from a variable. A deleted CI job exempted it by literal path; two
                     unit tests and one runtime guard are what hold it now
    tasks.py         the ordered purge, its reaper, retention and legal hold
    verification.py  the proof. `Deleted` is written by a passing verification pass, never
                     by a store returning 200

Nothing here reports a deletion complete on its own. A purge with no proof step is
indistinguishable from a purge that silently no-opped, and both look like success.
"""
