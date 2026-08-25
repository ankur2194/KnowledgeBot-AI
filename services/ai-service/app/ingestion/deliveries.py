"""The durable redelivery counter, and the reason it is not a column.

The broker guarantees at-least-once. A task that is redelivered forever — a visibility timeout
shorter than the work, a worker killed by the OOM reaper on every attempt, a poison payload —
burns a slot indefinitely unless something *remembers across worker restarts* how many times it
has been handed out. Celery's own retry counter does not: it lives on the message, so a
redelivery arrives with it reset. That memory is what this module is.

═══ WHY VALKEY AND NOT ``source_versions.delivery_count`` ══════════════════════════════════════

The column exists and is the right place to *read* the number from an admin screen. It is not a
place this service may write, and the runner used to write it anyway::

    UPDATE source_versions SET delivery_count = delivery_count + 1 …   # removed

That statement was a live violation of three rules at once, and it is worth naming all three
because each would have caught it alone:

1. ``source_versions`` is not in ``app/db/writes.py``'s ``ALLOWED_TABLES`` and — per
   ``App\\Services\\Sources\\IngestionProgress``'s own docblock — "must never be", because
   ADR-033 property 2 fails the moment Laravel serves the row.
2. `IngestionProgress` states the design in the opposite direction: the worker "cannot write the
   column it depends on", so the count "rides here", on the callback, applied by Laravel as a
   **ceiling** under the same sequence guard and row lock as every other field on the frame.
3. ``app/ingestion/runner.py``'s own neighbouring docstring said the data plane reads these
   tables and "never writes one of them, which is why this function has no companion that does".
   The companion was twenty lines above it.

The statement bypassed ``assert_writable`` because it was not in ``writes.py`` at all — which is
exactly the second of the two failures ``docs/22`` § Q5 describes, the one no gate ever covered.
``tests/unit/test_write_allow_list_scan.py`` covers it now, by reading the tree.

═══ WHAT MOVING IT COSTS AND WHAT IT BUYS ══════════════════════════════════════════════════════

The obvious alternative — read the column, add one, report the sum, let Laravel persist it — was
rejected on a measurement of the flow rather than on taste: ``run_one_version`` emits its first
frame *after* the claim, the lock and the context read, so a run that dies before then advances
nothing and the bound never tightens. That is precisely the runaway this counter exists for.

Valkey advances on the delivery itself, before any of that, and it is the data plane's own store:
the number is derived, rebuildable and owned here, which is the same test ``ALLOWED_TABLES``
applies to a table. The value still rides the callback, so ``source_versions.delivery_count``
keeps showing an operator the same number — as a **mirror**, with exactly one writer.

═══ THE PRE-IDENTITY PHASE, WHICH IS `docs/22` § Q9 ════════════════════════════════════════════

Q9 recorded an open question: ``delivery_count`` is applied by Laravel only inside ``if ($version
!== null)``, and a ``fetching`` frame carries no identity, so redeliveries during the phase that
runs *before* a version exists were accepted, acknowledged and discarded. Both readings in that
entry were defensible **because the counter was tied to a row**. Once it is tied to a Valkey key
instead, the question dissolves: the key does not need the version to exist, it needs a name for
the unit of work being redelivered. ``prepare_item`` has one — the job and the item — so the
pre-identity phase gets the same bound as the run, and the answer to Q9 is *it was a gap, and the
gap was an artifact of where the counter lived*.

Two different scopes, one shape, and they must not collide: ``prepare:{job_id}:{item_id}`` and
``version:{source_version_id}``. A prepare that is redelivered and then succeeds leaves its key
behind for the TTL; that is harmless, because the run's counter is a different key.
"""

from __future__ import annotations

from typing import Any, Final

__all__ = ["DELIVERY_TTL_SECONDS", "bump", "delivery_key", "prepare_scope", "version_scope"]

#: How long a counter survives with no further deliveries. Longer than any single run's hard time
#: limit by a wide margin (``task_time_limit`` is 960 s) and longer than a plausible broker
#: redelivery cycle, so a version that is being redelivered slowly still accumulates. Short enough
#: that a version which failed for good does not hold a key for a week.
#:
#: THE TTL SLIDES ON EVERY BUMP, deliberately. A fixed window from the first delivery would let a
#: redelivery cycle slower than the window reset the bound to zero every time — which is the one
#: failure mode a redelivery bound cannot have.
DELIVERY_TTL_SECONDS: Final[int] = 6 * 60 * 60


def delivery_key(*, org_id: str, scope: str) -> str:
    """``deliveries:{org_id}:{scope}``.

    The organization is first and is not optional, for the same reason it is first in
    ``idempotency_key``: an unscoped counter lets one tenant's redeliveries exhaust another
    tenant's budget, and a shared key space is a cross-tenant channel however boring the value.
    """
    return f"deliveries:{org_id}:{scope}"


def version_scope(source_version_id: str) -> str:
    return f"version:{source_version_id}"


def prepare_scope(*, job_id: str, item_id: str) -> str:
    return f"prepare:{job_id}:{item_id}"


async def bump(
    cache: Any, *, org_id: str, scope: str, ttl_seconds: int = DELIVERY_TTL_SECONDS
) -> int:
    """Count this delivery and return the running total, starting at 1.

    ``INCR`` then ``EXPIRE``, in that order and not atomically, and the asymmetry is deliberate.
    ``app/core/idempotency.py`` refuses the same split for a *claim* because dying between the two
    leaves an immortal key that suppresses an operation forever. A counter fails the other way: an
    immortal key holds a number that only ever makes the cap arrive sooner, the cap path reports a
    failure an operator sees, and the next bump re-applies the TTL. Costing a stranded key is
    acceptable; costing a suppressed document is not.

    ``cache`` is untyped and no client library is imported here, matching ``idempotency``: two
    generic commands, so a fake in a unit test is a few lines.
    """
    if ttl_seconds <= 0:
        msg = f"ttl_seconds must be positive; got {ttl_seconds}"
        raise ValueError(msg)

    key = delivery_key(org_id=org_id, scope=scope)
    count = int(await cache.incr(key))
    await cache.expire(key, ttl_seconds)
    return count
