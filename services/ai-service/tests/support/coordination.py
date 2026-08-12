"""An in-memory stand-in for the `valkey-core` coordination DB, and the protocol it must obey.

Two production call sites speak to `coordination_url`: ``app/api/deps.py``'s replay-nonce
store and ``app/core/idempotency.py``'s claim record. Between them they use three commands —
``SET … NX EX``, ``DELIFEQ`` and ``TTL`` — and nothing else, which is what lets a contract test
run the whole signature-verification path in-process without a container.

THE FAKE IS ONLY WORTH ANYTHING IF IT IS PINNED TO THE REAL SERVER. A hand-written double of a
datastore drifts silently: it returns ``True`` where redis-py returns ``None``, it treats
``ex=0`` as "no expiry", it deletes a key ``DELIFEQ`` would have left alone — and every one of
those makes a replay test green against a store that would have accepted the replay.

So the assertions live in ``COORDINATION_CONTRACT`` below rather than in a test file, and
``tests/integration/test_coordination_store.py`` runs the *same* callables against a real
Valkey while ``tests/contract/`` runs them against this class. A behaviour this fake gets wrong
fails in the merge-queue tier, named, instead of quietly weakening the tier that runs on
every push.
"""

from __future__ import annotations

import time
from collections.abc import Awaitable, Callable, Sequence
from dataclasses import dataclass, field
from typing import Any, Final

__all__ = ["COORDINATION_CONTRACT", "InMemoryCoordination"]


@dataclass
class InMemoryCoordination:
    """``SET NX EX`` / ``DELIFEQ`` / ``TTL`` over a dict, with redis-py's return shapes.

    Expiry is evaluated lazily on read, which is what a real server does too — an expired key
    stays resident until it is touched or sampled, so a test that asserts "the key is gone"
    the instant its TTL passes is asserting something Valkey does not promise either.
    """

    #: key -> (value, absolute monotonic expiry or None)
    _entries: dict[str, tuple[bytes, float | None]] = field(default_factory=dict)
    #: Every command issued, so a test can assert atomicity claims — that a claim was ONE
    #: `SET` and not a `GET` followed by a `SET`, which is the race this module exists for.
    commands: list[tuple[str, ...]] = field(default_factory=list)

    def _live(self, key: str) -> tuple[bytes, float | None] | None:
        entry = self._entries.get(key)
        if entry is None:
            return None
        if entry[1] is not None and entry[1] <= time.monotonic():
            del self._entries[key]
            return None
        return entry

    async def set(
        self,
        key: str,
        value: bytes | str,
        *,
        nx: bool = False,
        ex: int | None = None,
    ) -> bool | None:
        """redis-py's contract: ``True`` when the write happened, ``None`` when ``NX`` refused.

        Not ``False``. Code that branches on the result must treat the falsy value as "someone
        else holds it", and a fake returning ``False`` would let a buggy ``is True`` comparison
        pass here and fail against the server.
        """
        self.commands.append(("SET", key, "NX" if nx else "", f"EX {ex}" if ex else ""))
        if ex is not None and ex <= 0:
            msg = "invalid expire time in 'set' command"
            raise ValueError(msg)
        if nx and self._live(key) is not None:
            return None
        raw = value.encode() if isinstance(value, str) else value
        self._entries[key] = (raw, None if ex is None else time.monotonic() + ex)
        return True

    async def get(self, key: str) -> bytes | None:
        entry = self._live(key)
        return None if entry is None else entry[0]

    async def ttl(self, key: str) -> int:
        """``-2`` when the key does not exist, ``-1`` when it exists with no expiry."""
        entry = self._live(key)
        if entry is None:
            return -2
        if entry[1] is None:
            return -1
        return max(0, int(entry[1] - time.monotonic()))

    async def delete(self, key: str) -> int:
        return 1 if self._entries.pop(key, None) is not None else 0

    async def execute_command(self, *args: Any) -> Any:
        """Only ``DELIFEQ`` — redis-py has no binding for a Valkey-only verb."""
        self.commands.append(tuple(str(arg) for arg in args))
        name, *rest = args
        if str(name).upper() != "DELIFEQ":
            msg = f"unsupported command {name!r}"
            raise NotImplementedError(msg)
        key, token = str(rest[0]), rest[1]
        entry = self._live(key)
        expected = token.encode() if isinstance(token, str) else token
        if entry is None or entry[0] != expected:
            return 0
        del self._entries[key]
        return 1


#: The behaviours ``verify_hmac`` and ``idempotency`` actually depend on, as callables that run
#: against ANY store with this surface. Keyed by name so a failure names the property rather
#: than an index.
#:
#: Each takes the store and a key prefix unique to the caller, and raises ``AssertionError``.
COORDINATION_CONTRACT: Final[Sequence[tuple[str, Callable[[Any, str], Awaitable[None]]]]]


async def _set_nx_is_a_claim(store: Any, prefix: str) -> None:
    key = f"{prefix}:claim"
    first = await store.set(key, b"a", nx=True, ex=120)
    second = await store.set(key, b"b", nx=True, ex=120)

    assert first, "the first SET NX must report that it wrote"
    assert not second, "the second SET NX must report that it did not"
    assert await store.get(key) == b"a", "NX must not overwrite the incumbent"


async def _ex_is_applied_by_the_same_command(store: Any, prefix: str) -> None:
    """``SET`` then ``EXPIRE`` is a bug, not a shortcut: die in between and the key is immortal
    and that operation is suppressed forever."""
    key = f"{prefix}:ttl"
    await store.set(key, b"a", nx=True, ex=120)

    assert 0 < await store.ttl(key) <= 120


async def _a_non_positive_ttl_is_refused_rather_than_immortal(store: Any, prefix: str) -> None:
    """A zero or negative expiry is an error on Valkey and a key with no TTL on some clients.
    ``claim`` guards it before the call; this asserts the store does not quietly accept it."""
    key = f"{prefix}:zero"
    try:
        await store.set(key, b"a", nx=True, ex=0)
    except Exception:  # the server's own error type varies by client
        return
    raise AssertionError("ex=0 was accepted; a claim with no TTL is a permanent suppression")


async def _delifeq_only_deletes_the_holder_s_own_token(store: Any, prefix: str) -> None:
    """The whole reason ``release`` is not a ``DEL``. If the first execution's TTL lapsed while
    it was still running, a redelivery already holds the key — and a bare delete from the
    original's failure path would let a third copy start."""
    key = f"{prefix}:token"
    await store.set(key, b"mine", nx=True, ex=120)

    assert await store.execute_command("DELIFEQ", key, "theirs") == 0
    assert await store.get(key) == b"mine", "DELIFEQ deleted a key it did not own"
    assert await store.execute_command("DELIFEQ", key, "mine") == 1
    assert await store.get(key) is None


async def _delifeq_on_a_missing_key_is_not_an_error(store: Any, prefix: str) -> None:
    assert await store.execute_command("DELIFEQ", f"{prefix}:absent", "x") == 0


COORDINATION_CONTRACT = (
    ("set_nx_is_a_claim", _set_nx_is_a_claim),
    ("ex_is_applied_by_the_same_command", _ex_is_applied_by_the_same_command),
    ("a_non_positive_ttl_is_refused", _a_non_positive_ttl_is_refused_rather_than_immortal),
    ("delifeq_only_deletes_the_holders_own_token", _delifeq_only_deletes_the_holder_s_own_token),
    ("delifeq_on_a_missing_key_is_not_an_error", _delifeq_on_a_missing_key_is_not_an_error),
)
