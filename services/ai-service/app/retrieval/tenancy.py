"""The one constructor of a Qdrant filter for a tenant-facing operation.

Four terms, always, in ``must``. Each one prevents a different concrete failure, and the
redundancy between them is the design rather than an oversight:

``org_id``
    The authenticated backstop. It is the term that survives an IDOR on ``bot_id``: if a
    caller ever supplies another organization's bot identifier, this term still holds the
    result set to the organization the signed internal request proved.
``bot_ids``
    Stops a bot answering out of a source that was never assigned to it — a support bot
    quoting the internal salary handbook that lives in the same organization.
``source_status``
    Stops a source that failed processing, is still processing, or was disabled from
    being retrieved out of a payload that has not been rewritten yet.
``source_version_id``
    Resolved per request from PostgreSQL and passed in, which is what makes a disable or a
    delete take effect *immediately* rather than after millions of payload rewrites. It is
    also what stops a retired version answering beside the version that replaced it.

Three shapes that look like this file's job and are not:

* ``must``, never ``should``. Qdrant merges filters by concatenating clause lists per
  type, so a tenant term dropped into ``should`` is appended to whatever ``should`` the
  sub-query already had and widens into ``tenant OR anything_else``.
* Positive, never negative. A ``match`` is not satisfied by a point missing the key
  entirely, so a point whose payload lost ``org_id`` — a rebuild that never joined far
  enough up the FK chain — is visible to nobody under a positive ``must`` and to
  *everybody* under a ``must_not``. One of those is a support ticket, the other is a
  breach, and the outage is the correct failure.
* One expression, never assembled inside an ``if``. A conditionally-built condition list
  that came out empty is not an error: ``Filter(must=[])`` is a confirmed **match-all**,
  because the server checks ``must`` with ``.all()`` and ``.all()`` of nothing is true.
  That is why there is no default value here and no ``Optional`` — an unfiltered call has
  to be unrepresentable, not merely discouraged.

**The branch people forget is the prefetch leaf.** A hybrid query built as prefetch
branches plus a server-side fusion query needs the filter on *every* leaf as ``filter=``,
in addition to the top-level ``query_filter=``; the two parameters have different names on
the two levels, which is most of why one gets missed. CI greps for the leaf constructor
separately for exactly this reason. The omission ships because against a real server it
usually works — the server propagates the root filter into the leaves, undocumented and
source-only — while ``QdrantClient(":memory:")`` ignores the root filter on the fusion path
entirely and retrieves by id unfiltered. So the broken shape **leaks under the in-memory
client most unit tests use and is safe in production only by an accident nobody wrote
down.** Isolation tests need two organizations, a canary string in the other one's content,
and a real Qdrant container.

``allowed_version_ids`` arrives as an argument precisely so this function has no database of
its own to get wrong: Laravel resolves it from ``bot_source_assignments`` joined to
``knowledge_sources`` and ``source_versions``, restricted to the caller's organization and
to versions active *right now*, and ships it in the config snapshot. This service never
queries Laravel's tables.
"""

from __future__ import annotations

from collections.abc import Sequence
from typing import Final, Protocol

# Imported at runtime rather than under ``TYPE_CHECKING``: this module does not merely
# annotate a ``Filter``, it constructs one, and the four ``FieldCondition`` calls below are
# also what the delete-key CI gate greps for. A type-only import would leave the gate with
# nothing to subtract and nothing to reject.
from qdrant_client import models

__all__ = [
    "ACTIVE_SOURCE_STATUSES",
    "MANDATORY_FILTER_KEYS",
    "EmptyScopeError",
    "TenantContext",
    "tenant_filter",
]

#: The four payload keys that appear in ``must`` on every tenant-facing operation, in the
#: order they are built. This tuple is the single statement of the mandatory set — the
#: isolation test reads it rather than restating four string literals that could drift.
#:
#: It is deliberately **not** the deletion key allow-list, which is these four plus
#: ``source_id``, ``source_item_id`` and ``chunk_id``. Deletion additionally addresses one
#: chunk by its own identity; ``chunk_id`` is never a query-filter term. Collapsing the two
#: sets removes single-chunk deletion in one direction and breaks retrieval in the other.
MANDATORY_FILTER_KEYS: Final[tuple[str, str, str, str]] = (
    "org_id",
    "bot_ids",
    "source_status",
    "source_version_id",
)

#: The only ``source_status`` values a query may retrieve. ``ready_with_warnings`` is a
#: successfully indexed source that logged a parsing or OCR warning — it is searchable.
#: Every other state (processing, failed, disabled, deleting) is excluded by absence, which
#: is why this list is matched positively rather than subtracted from.
ACTIVE_SOURCE_STATUSES: Final[tuple[str, ...]] = ("ready", "ready_with_warnings")


class EmptyScopeError(Exception):
    """Raised when a filter would be built with nothing to constrain.

    An empty scope is a valid *outcome* — a bot with no assigned sources retrieves nothing
    and refuses. Widening it is not. This is an exception rather than an empty filter
    because the empty filter is the match-all described above.
    """


class TenantContext(Protocol):
    """The authenticated scope, structurally satisfied by ``app.api.deps.RequestContext``.

    A protocol rather than an import so this module depends on nothing above it, and
    read-only members so a frozen dataclass satisfies it.

    Both fields come from verified headers on the signed internal request. An organization
    identifier a caller can set in a body is not a scope, it is a parameter.
    """

    @property
    def org_id(self) -> str: ...

    @property
    def bot_id(self) -> str | None: ...


def tenant_filter(ctx: TenantContext, allowed_version_ids: Sequence[str]) -> models.Filter:
    """Build the four mandatory terms as one ``must`` clause.

    Both arguments are positional and required. Neither has a default, and there is no
    keyword that relaxes any term — no ``internal=True``, no admin path, no test fixture
    that turns it off. "FastAPI is only reachable from Laravel" is network topology, not
    authorization, and Compose is one ``ports:`` line away from removing it.

    Raises:
        EmptyScopeError: if the organization is missing, the bot is missing, or the
            resolved active-version set is empty. Each of those would otherwise produce a
            filter that is narrower than intended in a way the server reads as wider.
    """
    # Checked before anything is built, and the bot is checked as hard as the organization.
    # ``MatchAny(any=[None])`` is a legal condition that matches no point at all, so a missing
    # bot would otherwise ship a filter producing an empty result set — a silent refusal that
    # reads as an empty corpus — instead of a loud one.
    missing = [
        name
        for name, present in (
            ("org_id", bool(ctx.org_id)),
            ("bot_id", bool(ctx.bot_id)),
            ("allowed_version_ids", bool(allowed_version_ids)),
        )
        if not present
    ]
    if missing:
        raise EmptyScopeError(
            f"refusing to build a tenant filter with {', '.join(missing)} unset. An empty "
            "scope is a valid outcome — a bot with no assigned sources retrieves nothing and "
            "refuses — but it is expressed by not querying, never by a filter. Filter(must=[]) "
            "is a match-all, and MatchAny(any=[None]) matches nothing without saying so"
        )

    # Bound first so every FieldCondition below fits on one physical line. That is not a style
    # choice: the delete-key CI gate greps ``FieldCondition\(\s*key=`` and grep is line-based,
    # so a call wrapped across lines by a formatter matches zero lines and the gate silently
    # goes vacuous. Keep ``key=`` on the same line as its ``FieldCondition(``.
    org = models.MatchValue(value=ctx.org_id)
    bots = models.MatchAny(any=[ctx.bot_id])
    statuses = models.MatchAny(any=list(ACTIVE_SOURCE_STATUSES))
    versions = models.MatchAny(any=list(allowed_version_ids))

    # One expression, in MANDATORY_FILTER_KEYS order, with no branch anywhere inside it. All
    # four in ``must`` and all four positive: ``should`` is an OR that Qdrant's per-clause merge
    # widens, and ``must_not`` makes a point that lost its payload keys visible to everybody.
    return models.Filter(
        must=[
            models.FieldCondition(key="org_id", match=org),
            models.FieldCondition(key="bot_ids", match=bots),
            models.FieldCondition(key="source_status", match=statuses),
            models.FieldCondition(key="source_version_id", match=versions),
        ]
    )
