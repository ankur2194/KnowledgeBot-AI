"""Rewriting the two mutable payload terms on points that are already indexed.

Four payload fields decide what a query may see — ``org_id``, ``bot_ids``, ``source_status``
and ``source_version_id`` (`app/retrieval/tenancy.py`). Two of them are immutable facts about
the point: the tenant that owns it and the version it was built from. **The other two change
after the write, from the control plane, with no re-ingest** — an admin disables a source, an
admin unassigns a bot — and until this module existed nothing carried either change into the
index. The Laravel status column moved and the points kept answering.

WHY THIS IS A REWRITE AND NOT A DELETE
---------------------------------------
Both changes are reversible administrative acts and neither destroys knowledge. Disabling a
source is defined (`kb-source-lifecycle`) as excluding it from retrieval *immediately* while
every vector stays, so re-enabling is a payload write rather than a re-ingest — a delete would
turn a two-second toggle into a full reprocess at a provider's per-token price. Unassigning a
bot is the same shape one level down, and there it is worse than expensive: **``bot_ids`` is a
LIST on each point**, shared by every bot the source is assigned to, so a delete-by-filter on
``bot_ids`` destroys chunks that other bots still answer from. That is the trap this module
exists to not fall into, and `services/core-api/app/Services/Bots/BotService.php` names it at
the call site.

THE VERIFICATION COUNT IS THE ONLY EVIDENCE ANY OF THIS HAPPENED
------------------------------------------------------------------
`kb-deletion-and-verification`'s rule is not about deletion specifically; it is about
Qdrant acknowledging work it did not do. A ``set_payload`` against a filter that matches
nothing returns the same acknowledgement as one that rewrote ten thousand points, so every
operation here ends in a **filtered count** and reports it. Two ways to get a false pass are
closed deliberately:

* ``exact=True`` on every count. The approximate total is off by hundreds mid-optimization,
  which both false-passes and false-fails.
* The proof is a **positive** count of points carrying the intended value, compared against
  the total in scope — never "the write returned OK". A source whose points are in a
  collection this call never looked at counts zero and zero, and would pass a
  "did the write error?" check while every one of its chunks kept the old value.

ONE SOURCE CAN LIVE IN SEVERAL COLLECTIONS, AND THAT IS THE EASY THING TO GET WRONG
------------------------------------------------------------------------------------
The collection name encodes the embedding space (`app/retrieval/collection.py`), and a source
re-indexed after its organization changed embedding model has versions in two of them. So
these operations take a **set** of spaces, derived from the ``embedding_model_version`` of the
source's own version rows, and never a service-level default: a rewrite sent to
``COLLECTION`` when the corpus lives in ``kb_openai_..._3072_v1_...`` reports zero points
updated and passes anything that only checks for an error.

The spaces are resolved by the **control plane**, which owns ``source_versions``, and arrive in
the request body. This module opens no database connection, for the same reason
`app/api/internal/v1/embedding.py` does not: a data-plane read of a control-plane table to
decide the scope of a data-plane write is a second authority on what the scope is.

WHAT THIS MODULE MAY NAME
---------------------------
``PAYLOAD_KEYS`` is an allow-list over the fields a maintenance filter or a maintenance write
may mention, and it is enforced by `_assert_key` on every dynamically-built term. It is a
separate list from `app/deletion/filters.py`'s ``DELETE_KEYS`` on purpose, and not an import:
that module's keys are the ones a *delete* may address and its terms carry deletion's refusal
semantics, so sharing the list would mean a key added for one purpose silently widens the
other. What the two share is the rule, which is absolute in both places — **never target
chunk text, a payload string a user typed, or a content hash.** Headers, disclaimers and
pricing tables repeat verbatim across documents and across tenants, so a filter matching on
them cuts a hole in a source nobody touched, at HTTP 200, discovered weeks later.
"""

from __future__ import annotations

import logging
from collections.abc import Iterable, Mapping, Sequence
from dataclasses import dataclass
from typing import Any, Final

from qdrant_client import models

from app.core.errors import ErrorClass, KbError, Origin
from app.retrieval.collection import EmbeddingSpace

__all__ = [
    "MAX_REWRITE_PASSES",
    "PAYLOAD_KEYS",
    "SCROLL_BATCH",
    "SYNCABLE_STATUSES",
    "VERIFY_EXACT",
    "CollectionOutcome",
    "PayloadSyncFailed",
    "SyncOutcome",
    "sync_bot_access",
    "sync_source_status",
]

logger = logging.getLogger(__name__)

#: Every payload key a maintenance filter or write may name. Four, and they are the four
#: mandatory query terms minus ``source_version_id`` plus nothing: a maintenance op addresses a
#: whole source, never one version, because both facts it rewrites are properties of the source
#: rather than of a version. Adding ``source_version_id`` here would let a caller disable one
#: version of a source and leave its siblings answering, which is a state the control plane has
#: no column for and no screen that could show it.
PAYLOAD_KEYS: Final[tuple[str, ...]] = ("org_id", "source_id", "source_status", "bot_ids")

#: The only two values ``source_status`` may be rewritten *to*. The pipeline writes ``ready``
#: on every point it indexes (`app/ingestion/pipeline.py` — the payload term is the SOURCE's
#: status and the version is kept out of retrieval by the active-version filter, not by a
#: non-active status), so ``ready`` is what re-enabling restores and ``disabled`` is what
#: disabling writes.
#:
#: ``ready_with_warnings`` is deliberately NOT here even though retrieval accepts it. Nothing
#: writes it to a payload, so admitting it would let an enable land a value the index has never
#: held; the two are equivalent to every query anyway (`ACTIVE_SOURCE_STATUSES`). The states
#: this list excludes are excluded because they belong to somebody else: ``deleting`` and
#: ``deleted`` are `app/deletion/`'s, and the six processing states are a version's, not a
#: source's.
SYNCABLE_STATUSES: Final[tuple[str, ...]] = ("ready", "disabled")

#: Never ``False`` and never omitted, on every count in this module. See the module docstring:
#: the approximate total is off by hundreds mid-optimization, and this number is a proof.
VERIFY_EXACT: Final[bool] = True

#: Points read per scroll page in the ``bot_ids`` read-modify-write. Bounded because the page
#: is materialized in this process — with ``with_vectors=False`` and a single payload field it
#: is a few hundred bytes a point, so this is a memory ceiling rather than a throughput knob.
SCROLL_BATCH: Final[int] = 512

#: How many scroll-rewrite passes ``sync_bot_access`` will make before refusing to continue.
#:
#: The loop re-scrolls **from the beginning** every pass rather than paging with an offset, and
#: that is what makes it correct: a rewritten point no longer matches the filter, so paging
#: past it would skip the point that took its place. The consequence is that a pass which
#: rewrites nothing is an infinite loop, not a slow one — which happens if a ``set_payload``
#: is acknowledged without being applied. The cap converts that into a failure with a number in
#: it. It is high enough for two million points at ``SCROLL_BATCH``.
MAX_REWRITE_PASSES: Final[int] = 4096


class PayloadSyncFailed(KbError):
    """A rewrite whose verification count did not agree with its scope.

    ``vector_indexing`` and retryable: the failure mode this catches is a partially-applied
    write, and the operation is convergent, so running it again is the repair. It is raised
    rather than returned because a caller that received a `SyncOutcome` with ``passed=False``
    and did not look would commit a control-plane status the index never heard about — which
    is the exact silence the verification count exists to break.
    """

    def __init__(self, message: str) -> None:
        super().__init__(ErrorClass.VECTOR_INDEXING, message, origin=Origin.DOWNSTREAM)


@dataclass(frozen=True, slots=True)
class CollectionOutcome:
    """What one operation did in one collection, and the count that proves it."""

    collection: str
    #: ``False`` when the collection does not exist on the server. That is a legitimate state
    #: rather than an error — a version identity is recorded before its collection is created,
    #: and a space whose corpus has been fully purged leaves the name behind — and it is
    #: reported rather than swallowed so an operator can tell "nothing to do" apart from
    #: "addressed the wrong name", which are otherwise both a zero.
    present: bool
    #: Points in scope before the write.
    in_scope: int
    #: Points this call handed to ``set_payload``. For a status rewrite it is ``in_scope`` by
    #: construction; for ``bot_ids`` it is the number actually needing the change, which is
    #: ``0`` on a repeat run of an already-applied grant.
    rewritten: int
    #: Points that carry the intended value *after* the write, counted with a filter.
    verified: int
    passed: bool


@dataclass(frozen=True, slots=True)
class SyncOutcome:
    """One operation across every collection it addressed."""

    operation: str
    collections: tuple[CollectionOutcome, ...]

    @property
    def passed(self) -> bool:
        """True only if every collection passed. An empty tuple is False.

        A caller that addressed no collection at all did not verify anything, and reporting
        that as success is how a source whose spaces were resolved to an empty list gets
        disabled in Laravel and stays searchable forever.
        """
        return bool(self.collections) and all(c.passed for c in self.collections)

    @property
    def verified(self) -> int:
        return sum(c.verified for c in self.collections)

    @property
    def rewritten(self) -> int:
        return sum(c.rewritten for c in self.collections)


def _assert_key(key: str) -> str:
    """Gate every payload key before it reaches a filter or a write. Returns the key.

    Raise on an unknown key, never skip it: skipping drops a term from the ``must`` list and
    the operation proceeds *wider* than intended. A narrower-than-intended rewrite is a retry;
    a wider one has rewritten someone else's points.
    """
    if key not in PAYLOAD_KEYS:
        raise ValueError(
            f"{key!r} is not a maintenance payload key. The allow-list is {list(PAYLOAD_KEYS)}. "
            "If the key is genuinely new it goes in PAYLOAD_KEYS in the same change as the "
            "code that names it; far more often the answer is that this operation is trying to "
            "address something the material carries rather than an identifier PostgreSQL "
            "issued, and there is no key for that on purpose"
        )
    return key


def _value_term(key: str, value: str) -> models.FieldCondition:
    """One equality term, on an allow-listed key, with a value that is present at all.

    A blank identifier is refused rather than matched. ``MatchValue`` on ``""`` matches no
    point, so the operation is a no-op whose verification count agrees with it; and a later
    edit that tests the argument for truthiness instead of for ``None`` drops the term
    altogether, which is the widening. Refusing is the only outcome that says which happened.
    """
    if not value:
        raise ValueError(
            f"refusing to build a maintenance term on a blank {key}: an identifier that "
            "arrived empty is a caller that lost its scope"
        )
    return models.FieldCondition(key=_assert_key(key), match=models.MatchValue(value=value))


def _any_term(key: str, values: Sequence[str]) -> models.FieldCondition:
    """A membership term over identifiers, de-duplicated, caller order preserved.

    Empty raises for the same two reasons ``_value_term`` refuses a blank: an empty
    ``MatchAny`` matches nothing and its proof agrees, and an empty list is falsy, so a caller
    testing it rather than testing for ``None`` drops the term and scopes the rewrite to the
    whole organization.
    """
    ids = list(dict.fromkeys(values))
    if not ids or not all(ids):
        raise ValueError(
            f"refusing to build a maintenance term on an empty or blank-valued {key} list"
        )
    return models.FieldCondition(key=_assert_key(key), match=models.MatchAny(any=ids))


def _source_scope(org_id: str, source_ids: Sequence[str] | None) -> list[models.FieldCondition]:
    """``org_id``, plus the source scope when there is one.

    ``source_ids=None`` means every point in the organization and is only reachable from
    ``sync_bot_access`` on a revoke, where it is the correct scope for a deleted bot: its id
    must leave every point that carries it, including points of a source whose assignment row
    Laravel has already removed and can therefore no longer enumerate. The organization term is
    never optional and is not a parameter — there is no code path here that omits it.
    """
    scope = [_value_term("org_id", org_id)]
    if source_ids is not None:
        scope.append(_any_term("source_id", source_ids))
    return scope


def _count(client: Any, *, collection: str, scope: models.Filter, what: str) -> int:
    """One exact filtered count, with the client failure classified.

    ``# tenancy-exempt``: this is a maintenance write path, not a retrieval entry point. The
    four mandatory query filters express "what may this asker see" and none of them applies —
    the whole purpose of the call is to address points a query is currently excluding, and
    ``bot_ids`` is the term being changed. It returns an integer to a worker and no payload to
    any request, which is what keeps the exemption narrow.
    """
    try:
        result = client.count(  # tenancy-exempt: maintenance proof, org + explicit source scope
            collection_name=collection, count_filter=scope, exact=VERIFY_EXACT
        )
    except Exception as exc:
        raise KbError(
            ErrorClass.VECTOR_INDEXING,
            f"counting {collection} for {what} failed: {exc}",
            origin=Origin.DOWNSTREAM,
        ) from exc
    return int(result.count)


def _collection_present(client: Any, collection: str) -> bool:
    try:
        return bool(client.collection_exists(collection_name=collection))
    except Exception as exc:
        raise KbError(
            ErrorClass.VECTOR_INDEXING,
            f"could not determine whether {collection} exists: {exc}",
            origin=Origin.DOWNSTREAM,
        ) from exc


def _spaces_or_raise(spaces: Iterable[EmbeddingSpace]) -> tuple[EmbeddingSpace, ...]:
    """De-duplicate by collection name and refuse an empty set.

    Two version rows under the same embedding identity name one collection, and addressing it
    twice would double every count in the outcome — the second pass verifies a scope the first
    already rewrote, so the totals stop meaning "points in this source". Empty is refused
    because a rewrite that addressed nothing must not report success: `SyncOutcome.passed`
    already answers False for it, and raising here says *why* at the call site instead of at
    the caller's error branch.
    """
    unique: dict[str, EmbeddingSpace] = {}
    for space in spaces:
        unique.setdefault(space.collection, space)
    if not unique:
        raise ValueError(
            "no embedding space to address. The spaces come from the source's own version "
            "rows; an empty set means either that the source has never been indexed — in "
            "which case the caller must not treat the rewrite as owed — or that the "
            "resolution lost them, and a rewrite over zero collections cannot be told apart "
            "from a successful one by any count it reports"
        )
    return tuple(unique.values())


def sync_source_status(
    *,
    client: Any,
    spaces: Iterable[EmbeddingSpace],
    org_id: str,
    source_id: str,
    source_status: str,
) -> SyncOutcome:
    """Rewrite ``source_status`` on every indexed point of one source. Verified.

    This is what makes `SourceService::disable()` and `::enable()` change what a query sees.
    Laravel owns the column; retrieval reads the payload term; nothing connected the two until
    this call, so a disabled source went on answering with its status column set to
    ``disabled`` and every dashboard green.

    ONE ``set_payload`` PER COLLECTION, filtered — not a scroll. Unlike ``bot_ids`` this value
    is the same for every point in scope, so the server can do it in one operation with no page
    of ids crossing the wire. ``wait=True``, because the verification count that follows would
    otherwise read a collection still absorbing the write and pass on a warm collection under
    light load while failing under a bulk reindex.

    The proof is ``verified == in_scope`` where ``verified`` counts points *carrying the new
    value*. A source with no indexed points is ``0 == 0`` and passes, which is honest: there
    was nothing to rewrite. What it is not allowed to be is an empty **collection set** —
    `_spaces_or_raise` refuses that, because zero collections also produces zero and zero.
    """
    if source_status not in SYNCABLE_STATUSES:
        raise ValueError(
            f"source_status={source_status!r} is not one of {list(SYNCABLE_STATUSES)}. The "
            "processing states belong to a version rather than to a source, and the deletion "
            "states belong to app/deletion/ — writing one here would put the index into a "
            "state the control plane has no column for"
        )

    outcomes: list[CollectionOutcome] = []
    for space in _spaces_or_raise(spaces):
        collection = space.collection
        if not _collection_present(client, collection):
            outcomes.append(
                CollectionOutcome(
                    collection=collection,
                    present=False,
                    in_scope=0,
                    rewritten=0,
                    verified=0,
                    passed=True,
                )
            )
            continue

        scope = models.Filter(must=_source_scope(org_id, [source_id]))
        in_scope = _count(client, collection=collection, scope=scope, what=f"source {source_id}")

        if in_scope:
            try:
                client.set_payload(
                    collection_name=collection,
                    payload={_assert_key("source_status"): source_status},
                    points=scope,
                    wait=True,
                )
            except Exception as exc:
                raise KbError(
                    ErrorClass.VECTOR_INDEXING,
                    f"rewriting source_status on {collection} for source {source_id} failed: {exc}",
                    origin=Origin.DOWNSTREAM,
                ) from exc

        verified_scope = models.Filter(
            must=[*_source_scope(org_id, [source_id]), _value_term("source_status", source_status)]
        )
        verified = _count(
            client,
            collection=collection,
            scope=verified_scope,
            what=f"source {source_id} at {source_status}",
        )
        outcomes.append(
            CollectionOutcome(
                collection=collection,
                present=True,
                in_scope=in_scope,
                rewritten=in_scope,
                verified=verified,
                passed=verified == in_scope,
            )
        )

    outcome = SyncOutcome(operation="source.status.sync", collections=tuple(outcomes))
    _report(outcome, org_id=org_id, subject=source_id, detail=f"status={source_status}")
    _raise_unless_verified(outcome)
    return outcome


def sync_bot_access(
    *,
    client: Any,
    spaces: Iterable[EmbeddingSpace],
    org_id: str,
    bot_id: str,
    source_ids: Sequence[str] | None,
    grant: bool,
) -> SyncOutcome:
    """Add or remove one bot id in the ``bot_ids`` LIST on every point in scope. Verified.

    ``bot_ids`` is a list per point and every bot assigned to the source is in it, so this
    cannot be a filtered write the way `sync_source_status` can: the new value differs per
    point. It is a scroll, a per-point edit in this process, and a ``set_payload`` addressed to
    point **ids**. It is emphatically not a delete-by-filter — that removes the chunks other
    bots still answer from, at HTTP 200.

    ``source_ids=None`` revokes across the whole organization and is the deleted-bot case: the
    assignment rows are gone, so Laravel cannot enumerate the sources, and a bot id left in a
    payload is a term a future bot with a recycled id could match. It is refused for a grant,
    where an unscoped write would assign the bot to every source the tenant owns.

    THE LOOP RE-SCROLLS FROM THE BEGINNING EVERY PASS and that is deliberate: a rewritten point
    stops matching the filter, so paging past it with an offset skips whichever point moved up
    into its place. The cost is that a pass which rewrites nothing would spin, which is what
    ``MAX_REWRITE_PASSES`` converts into a failure with a number in it.

    Points are grouped by their **resulting** ``bot_ids`` value before writing, so a source
    whose points all carry the same assignment set — the ordinary case — is one ``set_payload``
    per page rather than one per point.
    """
    if grant and source_ids is None:
        raise ValueError(
            "refusing an organization-wide grant: source_ids=None means every point this "
            "tenant owns, and granting a bot access to every source is not something any "
            "caller asks for on purpose. Revoking org-wide is legitimate (a deleted bot) and "
            "is the only reason the unscoped form exists"
        )
    if not bot_id:
        raise ValueError("refusing to rewrite bot_ids for a blank bot id")

    outcomes: list[CollectionOutcome] = []
    for space in _spaces_or_raise(spaces):
        collection = space.collection
        if not _collection_present(client, collection):
            outcomes.append(
                CollectionOutcome(
                    collection=collection,
                    present=False,
                    in_scope=0,
                    rewritten=0,
                    verified=0,
                    passed=True,
                )
            )
            continue

        outcomes.append(
            _sync_bot_access_in(
                client,
                collection=collection,
                org_id=org_id,
                bot_id=bot_id,
                source_ids=source_ids,
                grant=grant,
            )
        )

    outcome = SyncOutcome(
        operation="bot.access.sync",
        collections=tuple(outcomes),
    )
    _report(
        outcome,
        org_id=org_id,
        subject=bot_id,
        detail=_scope_words(source_ids=source_ids, grant=grant),
    )
    _raise_unless_verified(outcome)
    return outcome


def _sync_bot_access_in(
    client: Any,
    *,
    collection: str,
    org_id: str,
    bot_id: str,
    source_ids: Sequence[str] | None,
    grant: bool,
) -> CollectionOutcome:
    """One collection's share of `sync_bot_access`."""
    carries_bot = _value_term("bot_ids", bot_id)
    scope = _source_scope(org_id, source_ids)

    # The points still NEEDING the change. On a grant that is the ones not already carrying the
    # id; on a revoke the ones that are. Written as the pending set rather than as "every point
    # in scope" so the operation is convergent: a redelivery finds nothing pending and rewrites
    # nothing, instead of rewriting every point back to the value it already holds.
    pending = (
        models.Filter(must=scope, must_not=[carries_bot])
        if grant
        else models.Filter(must=[*scope, carries_bot])
    )

    total = _count(client, collection=collection, scope=models.Filter(must=scope), what="scope")
    in_scope = _count(client, collection=collection, scope=pending, what=f"bot {bot_id} pending")

    rewritten = 0
    passes = 0
    while True:
        try:
            page, _ = client.scroll(  # tenancy-exempt: maintenance rewrite, org-scoped filter
                collection_name=collection,
                scroll_filter=pending,
                limit=SCROLL_BATCH,
                # The one field being edited, and no vectors. A page of 3072-float vectors is
                # 12 MB a point-thousand and none of it is read.
                with_payload=["bot_ids"],
                with_vectors=False,
            )
        except Exception as exc:
            raise KbError(
                ErrorClass.VECTOR_INDEXING,
                f"scrolling {collection} for bot {bot_id} failed: {exc}",
                origin=Origin.DOWNSTREAM,
            ) from exc

        if not page:
            break

        passes += 1
        if passes > MAX_REWRITE_PASSES:
            raise PayloadSyncFailed(
                f"{collection} still returned points carrying bot {bot_id} after "
                f"{MAX_REWRITE_PASSES} rewrite passes ({rewritten} points rewritten). The "
                "loop re-scrolls from the start every pass, so a page that keeps coming back "
                "means the set_payload was acknowledged without being applied"
            )

        grouped: dict[tuple[str, ...], list[Any]] = {}
        for point in page:
            current = _bot_ids_of(point)
            updated = _updated_bot_ids(current, bot_id=bot_id, grant=grant)
            grouped.setdefault(updated, []).append(point.id)

        for value, ids in grouped.items():
            try:
                client.set_payload(
                    collection_name=collection,
                    payload={_assert_key("bot_ids"): list(value)},
                    points=ids,
                    wait=True,
                )
            except Exception as exc:
                raise KbError(
                    ErrorClass.VECTOR_INDEXING,
                    f"rewriting bot_ids on {len(ids)} points in {collection} failed: {exc}",
                    origin=Origin.DOWNSTREAM,
                ) from exc
            rewritten += len(ids)

    # The proof, and it is a POSITIVE count in both directions rather than "the writes did not
    # error". After a revoke no point in scope may carry the id; after a grant every point in
    # scope must. Counting the same filter both ways would let a revoke that matched nothing
    # look identical to one that rewrote everything.
    carrying = _count(
        client,
        collection=collection,
        scope=models.Filter(must=[*scope, carries_bot]),
        what=f"bot {bot_id} after rewrite",
    )
    expected = total if grant else 0
    return CollectionOutcome(
        collection=collection,
        present=True,
        in_scope=in_scope,
        rewritten=rewritten,
        verified=carrying,
        passed=carrying == expected,
    )


def _bot_ids_of(point: Any) -> tuple[str, ...]:
    """The point's current ``bot_ids``, as a tuple of strings.

    A point with no ``bot_ids`` key reads as empty rather than raising. That is a real state —
    a source assigned to no bot at index time — and refusing it would make an org-wide revoke
    fail on the first unassigned source it met, halfway through, having already rewritten the
    points before it.

    A non-list value is refused, because the alternative is silently replacing whatever it was
    with a one-element list and calling that a repair.
    """
    payload: Mapping[str, Any] = point.payload or {}
    value = payload.get("bot_ids", [])
    if value is None:
        return ()
    if not isinstance(value, list):
        raise PayloadSyncFailed(
            f"point {point.id} carries a non-list bot_ids ({type(value).__name__}). The payload "
            "contract is a list per point and every bot assigned to the source is in it; "
            "rewriting this one would replace a value nothing here can interpret"
        )
    return tuple(str(item) for item in value)


def _updated_bot_ids(current: tuple[str, ...], *, bot_id: str, grant: bool) -> tuple[str, ...]:
    """``current`` with ``bot_id`` added or removed, de-duplicated, order preserved.

    Order is preserved rather than sorted so a rewrite is visible as the one-element change it
    is when a payload is read by hand. De-duplication is not cosmetic: ``MatchAny`` over a list
    payload is membership, so a duplicated id does not change what a query matches, but it does
    make a revoke that removed "the" id leave the other copy behind — which passes an
    ``if bot_id in current`` check and fails the verification count.
    """
    without = tuple(dict.fromkeys(item for item in current if item != bot_id))
    return (*without, bot_id) if grant else without


def _scope_words(*, source_ids: Sequence[str] | None, grant: bool) -> str:
    """The human half of `sync_bot_access`'s log line. ``None`` reads as what it means."""
    where = (
        "every source in the organization" if source_ids is None else f"{len(source_ids)} source(s)"
    )
    return f"{'grant' if grant else 'revoke'} over {where}"


def _raise_unless_verified(outcome: SyncOutcome) -> None:
    """Turn a failed verification into a raise, once, for both operations.

    Raised rather than returned. A `SyncOutcome` with ``passed=False`` handed back to a caller
    is a value that can be ignored, and the caller here is the control plane committing a
    status change: ignoring it commits a state the index never heard about, which is precisely
    the silence the verification count exists to break. The class is retryable because the
    failure it describes — a partially applied rewrite — is repaired by running the same
    convergent operation again.
    """
    if outcome.passed:
        return
    detail = ", ".join(
        f"{c.collection}: {c.verified}/{c.in_scope} verified"
        + ("" if c.present else " (collection absent)")
        for c in outcome.collections
    )
    raise PayloadSyncFailed(
        f"{outcome.operation} did not verify — "
        + (detail or "no collection was addressed, so nothing was measured")
    )


def _report(outcome: SyncOutcome, *, org_id: str, subject: str, detail: str) -> None:
    """One log line per operation, carrying the counts that are the evidence.

    **The counts are in the MESSAGE and not in ``extra=``, and that is not a style choice.**
    `app/observability/logging.py`'s ``ALLOWED_EXTRA_FIELDS`` is a closed allow-list: a key that
    is not on it is dropped and only its *name* is reported in ``dropped_fields``. So a line
    that put ``rewritten`` and ``verified`` in ``extra=`` would ship neither number — the
    operator greps for the proof and finds a field list. ``collection``, ``count``, ``outcome``,
    ``org_id`` and ``bot_id`` are on the list and are used; everything else is interpolated.

    No payload values and no tenant content — identifiers, collection names and integers.

    This runs BEFORE `_raise_unless_verified`, so the per-collection counts are on record even
    when the exception that follows is caught, logged as one line and retried by Celery.
    """
    fields = {
        "org_id": org_id,
        "collection": ",".join(c.collection for c in outcome.collections),
        "count": outcome.verified,
        "outcome": "verified" if outcome.passed else "unverified",
    }
    message = "%s %s: %s — rewrote %d, verified %d across %d collection(s)"
    args = (
        outcome.operation,
        subject,
        detail,
        outcome.rewritten,
        outcome.verified,
        len(outcome.collections),
    )
    if outcome.passed:
        logger.info(message, *args, extra=fields)
    else:
        logger.warning(message, *args, extra=fields)


# The allow-list is the four mandatory query terms minus the one a maintenance op may not
# address. Asserted at import rather than in a test: a key added here without a reader is
# harmless, but a key REMOVED silently turns every term that names it into a ValueError inside
# a worker, on the one path nobody exercises by hand.
assert set(PAYLOAD_KEYS) == {"org_id", "source_id", "source_status", "bot_ids"}
