"""Idempotency claims.

The broker guarantees at-least-once and nothing else. Late acks, visibility-timeout
redelivery, ``reject_on_worker_lost`` and connection-loss replay all re-execute a task that
already ran, so idempotency is a property of *our* code — no broker or Celery setting
supplies it.

Key scheme: ``idem:{org_id}:{operation}:{key}``. The org id is first and is not optional:
an unscoped idempotency key lets one tenant's replay suppress another tenant's work.

``cache`` IS DELIBERATELY UNTYPED AND THIS MODULE IMPORTS NO CLIENT LIBRARY. Two commands
are used — ``SET … NX EX`` and ``DELIFEQ`` — and both go through the client's generic
surface, so a fake in a unit test is fifteen lines and the coordination client can be
swapped without touching this file.
"""

from __future__ import annotations

import secrets
from typing import Any

__all__ = ["claim", "idempotency_key", "new_claim_token", "release"]


def idempotency_key(*, org_id: str, operation: str, key: str) -> str:
    return f"idem:{org_id}:{operation}:{key}"


def new_claim_token() -> str:
    """A fresh token identifying ONE execution's hold on a claim.

    Minted per execution, never per key: two executions of the same task hold the same key
    at different times and must be distinguishable, which is the entire point of ``release``
    below taking one.
    """
    return secrets.token_hex(16)


async def claim(cache: Any, key: str, ttl_seconds: int, *, token: str) -> bool:
    """Claim the key, returning False if someone already holds it.

    Must be a single atomic SET NX EX. A GET-then-SET is a race whose losing side runs the
    work twice, which is precisely the case this module exists to prevent.

    The TTL must exceed the task's hard time limit. A claim that expires while the task is
    still running lets a redelivery start a second copy. **That invariant cannot be checked
    here** — this module knows nothing about time limits, and importing ``app/worker`` to
    find out would make every caller of a coordination primitive drag in Celery. It belongs
    at the call sites, against ``app/worker/config.py``: the process-wide hard limit is
    ``task_time_limit = 960``, with per-queue hard limits of 960 / 660 / 360. What IS
    checkable here is the degenerate case, and it is worth one line: a non-positive TTL
    makes ``SET … EX`` an error on some servers and an immortal key on others, and neither
    is a claim.

    ``token`` is keyword-only and required. There is no unguarded overload, because the
    failure it prevents is silent: see ``release``.
    """
    if ttl_seconds <= 0:
        msg = f"ttl_seconds must be positive; got {ttl_seconds}"
        raise ValueError(msg)
    # `nx=True` and `ex=` in ONE command. Never `SET` then `EXPIRE`: die in between and the
    # claim is immortal and that operation is suppressed forever (`valkey-keyspaces`).
    return bool(await cache.set(key, token, nx=True, ex=ttl_seconds))


async def release(cache: Any, key: str, *, token: str) -> None:
    """Release a claim after a *failure*, so a retry may proceed.

    Never release after success: the claim is what makes the replay a no-op.

    COMPARE-AND-DELETE, NEVER A BARE ``DELETE``. A plain ``DEL`` drops whatever holds the key
    *now*, which is not necessarily what this execution claimed: if the first execution's TTL
    lapsed while it was still running — a 15-minute OCR call, a GC pause, a frozen container
    — a redelivery has already claimed the key, and the original's failure path would then
    delete the live claim and let a third copy start. "Never release after success" prevents
    the common case; this closes the uncommon one.

    ``DELIFEQ`` (Valkey 9.0.0+) does it server-side in one command, so there is no Lua script
    to load, no ``EVALSHA`` fallback and no window between a read and a delete. It is spelled
    through ``execute_command`` because redis-py has no binding for a Valkey-only verb. A
    server without it answers with an unknown-command error rather than deleting anything,
    which is the safe direction: the claim survives its TTL and the operation is retried
    late rather than run twice.
    """
    await cache.execute_command("DELIFEQ", key, token)
