"""A recording stand-in for ``AsyncQdrantClient``, for asserting on what went *out*.

**It is not a Qdrant and must never be used as one.** It evaluates no filter, so it cannot
answer any question about isolation: two organizations and a real server are what prove that
(`tests/integration/`, and `tests/unit/README.md` forbids asserting on a query result in the
unit tier at all). What it is for is the other half — that the call this service issued carried
the four filter terms, named its branch, projected the payload it meant to, and asked for no
vectors. Those are properties of the *request*, they are the ones that go wrong silently, and
they need no server to observe.

The distinction matters because the tempting stub is one that also returns points and lets a
test assert on ranking. Such a stub ranks by whatever the fixture author wrote, which is a test
of the fixture. This one returns exactly what it was given and nothing else.
"""

from __future__ import annotations

from collections.abc import Sequence
from dataclasses import dataclass, field
from typing import Any

from qdrant_client import models

# NOT ``models.QueryResponse``. ``qdrant_client.models`` re-exports a *fastembed* class of that
# name — ``id``/``embedding``/``document``/``score``, ``extra="forbid"``, no ``points`` field at
# all — while ``query_points`` returns ``qdrant_client.http.models.QueryResponse``. Two
# unrelated types, one name, one namespace, and the collision is only visible as a pydantic
# validation error at construction. ``common_types`` is the client's own alias module and is
# what its stubs annotate the call with.
from qdrant_client.conversions.common_types import QueryResponse

__all__ = ["RecordingClient", "point"]


def point(chunk_id: str, score: float, **payload: Any) -> models.ScoredPoint:
    """A ``ScoredPoint`` carrying the payload fields the query path projects.

    ``id`` is the deterministic ``uuid5`` point id in production and is deliberately a
    different value from ``chunk_id``; a fixture that makes them equal hides every place code
    reads the wrong one.
    """
    return models.ScoredPoint(
        id=f"00000000-0000-5000-8000-{abs(hash(chunk_id)) % 10**12:012d}",
        version=1,
        score=score,
        payload={"chunk_id": chunk_id, **payload},
    )


@dataclass
class RecordingClient:
    """Structurally enough of ``AsyncQdrantClient`` for ``branch_query`` to complete.

    ``responses`` maps a branch name to the points that branch should return. A branch with no
    entry returns nothing, which is a real state — a lexical branch that matched no posting —
    and not an error.
    """

    responses: dict[str, Sequence[models.ScoredPoint]] = field(default_factory=dict)
    calls: list[dict[str, Any]] = field(default_factory=list)

    async def query_points(self, **kwargs: Any) -> QueryResponse:
        self.calls.append(kwargs)
        using = kwargs.get("using")
        return QueryResponse(points=list(self.responses.get(str(using), ())))

    def call_for(self, using: str) -> dict[str, Any]:
        """The one call made on a branch. Raises if there was not exactly one.

        Exactly one, because "the sparse branch ran twice" and "the sparse branch ran once"
        both leave a non-empty list, and the first is an unbatched loop.
        """
        matching = [call for call in self.calls if call.get("using") == using]
        if len(matching) != 1:
            raise AssertionError(f"expected one {using!r} call, recorded {len(matching)}")
        return matching[0]
