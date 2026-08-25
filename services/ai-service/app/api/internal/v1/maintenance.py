"""``POST /internal/v1/maintenance/{source-status,bot-access}`` — carry a control-plane change
into the index.

Two facts on every point are owned by Laravel and mutable after the write: whether the source
is disabled, and which bots may see it. Both are mandatory query terms
(`app/retrieval/tenancy.py`), so until something rewrote them a disable was a column change
nobody's query noticed and an unassignment left a deleted bot's id in every payload. These are
the operations that close that, and `app/maintenance/payload.py` is what they call.

WHY THEY ANSWER SYNCHRONOUSLY, UNLIKE INGESTION NEXT DOOR
----------------------------------------------------------
``/ingestion/jobs`` answers 202 and reports over a signed callback because a run takes minutes
and outlives its request. These do not: a status rewrite is one filtered ``set_payload`` the
server performs internally, and a ``bot_ids`` rewrite is a scroll over the points of one
source. More importantly **the verification count is the response**. `kb-deletion-and-
verification`'s rule is that a filtered count is the only evidence any of this happened, and a
202 would hand the caller an acknowledgement instead — which is exactly the acknowledgement
``set_payload`` already returns whether it rewrote ten thousand points or none. The caller is
a Laravel queued job, not a browser, so it can wait; what it cannot do is compensate for a
failure it was never told about.

The handlers are therefore ``def`` and not ``async def``: the Qdrant client is synchronous, so
Starlette runs them in the threadpool and the event loop keeps serving. An ``async def`` here
would block the loop for the whole scroll.

WHAT THEY DO NOT DO
---------------------
* **No database.** The set of collections a source lives in comes from the *body*, resolved by
  the control plane from ``source_versions.embedding_model_version``, which it owns. A
  data-plane read to decide the scope of a data-plane write would be a second authority on
  what the scope is, and the two would disagree exactly when it mattered.
* **No authority check.** Whether this actor may disable this source was settled in Laravel
  behind the six protected-action checks. Re-deriving it here is the duplicated check that
  drifts.
* **No replay store, deliberately** — see `_idempotency_key`.

There is no ``X-KB-Bot-Id`` on the source-status route and it is *not* the bot scope on the
bot-access one either. A knowledge source is organization-owned (ADR-067), and on the
bot-access route the bot is the *subject* of the change rather than the scope of the request:
it travels in the signed body with the sources it applies to. The header set is inside the
canonical string, so a router that expected the header would fail every signature.
"""

from __future__ import annotations

import logging
from typing import Annotated, Any, Final

from fastapi import APIRouter, Depends, Request
from pydantic import BaseModel, ConfigDict, Field

from app.api.deps import RequestContext, from_request_state, request_context, verify_hmac
from app.core.errors import ErrorClass, KbError, Origin
from app.maintenance.payload import (
    SYNCABLE_STATUSES,
    CollectionOutcome,
    SyncOutcome,
    sync_bot_access,
    sync_source_status,
)
from app.retrieval.collection import EmbeddingSpace, space_from_identity

logger = logging.getLogger(__name__)

__all__ = ["BotAccessSyncRequest", "SourceStatusSyncRequest", "SyncReport", "router"]

#: ULIDs, the same pattern the write path asserts before it builds a point. Restated as a
#: string rather than imported so this module does not pull the indexing package into the API
#: process; `tests/contract/` pins the two against each other.
#: BOTH CASES, AND THE LOWERCASE HALF IS NOT COSMETIC. Crockford's alphabet is defined
#: case-insensitively and Laravel uses BOTH spellings on the same request: `HasUlids::newUniqueId()`
#: lowercases, so every model key crossing the seam is lowercase
#: (`01m0swft82zf81qx2d032qepb7`), while an id minted with `Str::ulid()` is uppercase — the
#: submission that proved it carried a lowercase `source.id` and an uppercase `job_id` in one body.
#: This pattern was uppercase-only in four modules at once, so the first real submission from the
#: first real tenant was refused 422 on `source.id` and `items.0.id` (2026-08-24, `docs/22` § R6).
#: Nothing is lowered or upper-cased on the way through: an id is compared byte-for-byte in Qdrant
#: filters and in the `chunks` table, so normalising here would be a second identity for one row.
ULID_PATTERN: Final[str] = r"^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$"

#: A ceiling on what one request may address, not a policy. An organization with more than this
#: many distinct embedding identities on one source has re-embedded it 32 times, which describes
#: a caller that has gone wrong rather than a tenant that has configured a lot.
MAX_SPACES: Final = 32

#: A source-scoped bot change names the sources it applies to. The bound is generous — an
#: organization's whole source list — and exceeding it is a caller defect, not a tenant that
#: owns a lot of documents: the org-wide form exists for the only case that legitimately means
#: "all of them", and it is spelled as an explicit flag rather than as a long list.
MAX_SOURCES: Final = 1000

#: ``dependencies=`` on the ROUTER, never on the path operation, so an endpoint added to this
#: module later cannot forget it. Both operations here are mutations of the retrieval index, and
#: ``X-KB-Org-Id`` is only trustworthy because it is signed — an unsigned caller reaching either
#: of these could disable another tenant's corpus.
router = APIRouter(prefix="/internal/v1", tags=["maintenance"], dependencies=[Depends(verify_hmac)])


class SourceStatusSyncRequest(BaseModel):
    """Which source, which status, and which collections it lives in.

    No ``org_id``: the organization is ``X-KB-Org-Id`` from the *verified* headers. A
    body-supplied tenant would be forgeable against a signature that did not cover it, which is
    why every ``x-kb-*`` header is inside the canonical string.
    """

    model_config = ConfigDict(strict=True, extra="forbid", frozen=True)

    source_id: str = Field(pattern=ULID_PATTERN)
    #: ``ready`` or ``disabled``. Constrained on the wire as well as in the module that applies
    #: it, so a control plane that grew a third lifecycle state gets a 422 naming the field
    #: rather than a 500 from a ``ValueError`` two layers down.
    source_status: str = Field(pattern=r"^(ready|disabled)$")
    #: ``source_versions.embedding_model_version``, distinct, for every version of this source —
    #: including the ones that are not active. A source re-indexed after its organization
    #: changed embedding model has points in two collections, and rewriting only the active
    #: version's leaves the other half of the corpus answering with the old status.
    embedding_model_versions: list[str] = Field(min_length=1, max_length=MAX_SPACES)


class BotAccessSyncRequest(BaseModel):
    """Add or remove one bot id in the ``bot_ids`` list on the points in scope.

    ``bot_id`` is the SUBJECT of the change and travels in the body rather than in
    ``X-KB-Bot-Id``: the header names the bot a request is being made *on behalf of*, and this
    request is made on behalf of an organization admin about a bot that may already be deleted.
    """

    model_config = ConfigDict(strict=True, extra="forbid", frozen=True)

    bot_id: str = Field(pattern=ULID_PATTERN)
    #: ``True`` adds the id, ``False`` removes it. One endpoint rather than two because it is
    #: one read-modify-write with one verification shape, and two would drift on the half that
    #: is exercised less.
    grant: bool
    #: ``None`` is NOT "no sources" — it is every point in the organization carrying this bot
    #: id, and it is the deleted-bot case: the assignment rows are gone, so the control plane
    #: cannot enumerate the sources. It is refused for a grant, where it would assign the bot to
    #: every source the tenant owns. Spelled as an explicit ``null`` rather than as an omitted
    #: field so that a serializer dropping an empty list cannot produce it by accident.
    source_ids: list[str] | None = Field(default=None, max_length=MAX_SOURCES)
    embedding_model_versions: list[str] = Field(min_length=1, max_length=MAX_SPACES)


class CollectionReport(BaseModel):
    """One collection's counts, as the response carries them."""

    model_config = ConfigDict(frozen=True)

    collection: str
    present: bool
    in_scope: int
    rewritten: int
    verified: int
    passed: bool


class SyncReport(BaseModel):
    """The proof, per collection and in total.

    Returned in full rather than as a bare ``ok`` because the counts are what an operator reads
    when a source is disabled in the control plane and still answering: they say which
    collection disagreed, and whether the scope was empty or the write was.
    """

    model_config = ConfigDict(frozen=True)

    operation: str
    passed: bool
    rewritten: int
    verified: int
    collections: list[CollectionReport]


def qdrant_client(request: Request) -> Any:
    """The lifespan-owned Qdrant client, or a deployment failure.

    ``internal_dependency`` and not ``downstream``: a ``None`` here is a container that was
    never given a Qdrant URL, and no number of retries provisions one. `from_request_state`
    is the guarded reader — it raises on a *name* this state object does not own, which is the
    typo that would otherwise read as an un-provisioned container.
    """
    client = from_request_state(request, "qdrant")
    if client is None:
        raise KbError(
            ErrorClass.INTERNAL_DEPENDENCY,
            "The service could not complete this request.",
            origin=Origin.SELF,
        )
    return client


def _idempotency_key(request: Request) -> str:
    """Require ``X-KB-Idempotency-Key`` on both mutations, and deliberately do not store it.

    Required because `kb-internal-api-contracts` requires it on every mutation and because it
    is inside the canonical string — a caller that omits it is a caller whose signer disagrees
    with ours, and finding that out here is better than finding it out as a signature failure
    with no field named.

    NOT stored, and that is the decision worth reading. A replay store short-circuits the
    second delivery and returns the first one's answer — but the answer here **is the
    verification count**, and a stale count is worse than no count: it tells the control plane
    that an index it has not looked at since is in a state it may no longer be in. Both
    operations are convergent (`app/maintenance/payload.py` selects the points still *needing*
    the change), so re-running one costs a filtered count and rewrites nothing. Idempotency is
    a property of the operation here rather than of a claim in Valkey.
    """
    key = request.headers.get("x-kb-idempotency-key", "")
    if not key:
        raise KbError(
            ErrorClass.VALIDATION,
            "X-KB-IDEMPOTENCY-KEY is required on every internal mutation",
        )
    return key


def _spaces(identities: list[str]) -> tuple[EmbeddingSpace, ...]:
    """``emb/v1:...`` strings into the spaces they name, or a 422 naming the bad one.

    `space_from_identity` raises ``ValueError`` because the schema module imports no taxonomy;
    an unparseable identity is the caller's malformed body and not our internal failure, so it
    renders 422 rather than 500. It is never defaulted: a default would name a *real*
    collection and rewrite someone else's vector space.
    """
    try:
        return tuple(space_from_identity(value) for value in identities)
    except ValueError as exc:
        raise KbError(ErrorClass.VALIDATION, str(exc)) from exc


def _report(outcome: SyncOutcome) -> SyncReport:
    def one(c: CollectionOutcome) -> CollectionReport:
        return CollectionReport(
            collection=c.collection,
            present=c.present,
            in_scope=c.in_scope,
            rewritten=c.rewritten,
            verified=c.verified,
            passed=c.passed,
        )

    return SyncReport(
        operation=outcome.operation,
        passed=outcome.passed,
        rewritten=outcome.rewritten,
        verified=outcome.verified,
        collections=[one(c) for c in outcome.collections],
    )


@router.post(
    "/maintenance/source-status",
    response_model=SyncReport,
    summary="Rewrite source_status on every indexed point of one source",
    # Explicit: FastAPI's default is derived from the function name and the path, and the
    # exported OpenAPI document generates a client method from it. The name matches the control
    # plane's own `InternalAiClient::syncSourceStatus`.
    operation_id="syncSourceStatus",
)
def sync_source_status_endpoint(
    body: SourceStatusSyncRequest,
    ctx: Annotated[RequestContext, Depends(request_context)],
    client: Annotated[Any, Depends(qdrant_client)],
    idempotency_key: Annotated[str, Depends(_idempotency_key)],
) -> SyncReport:
    """Apply the rewrite and return its verification counts. Raises if it did not verify.

    A failed verification is an exception rather than a ``passed: false`` body, and both halves
    of that matter: the control plane's queued job needs a non-2xx to retry and compensate, and
    a body it could ignore is a body it will ignore — which commits a status the index never
    heard about.
    """
    outcome = sync_source_status(
        client=client,
        spaces=_spaces(body.embedding_model_versions),
        org_id=ctx.org_id,
        source_id=body.source_id,
        source_status=body.source_status,
    )
    # `org_id`, `operation` and `request_id` are already bound for the life of this request by
    # `request_context`, so repeating them here would duplicate three fields on every line. The
    # identifiers that are NOT bound go in the message: `ALLOWED_EXTRA_FIELDS` is a closed
    # allow-list and a key outside it is dropped with only its name reported, so a `source_id`
    # in `extra=` would ship as the word "source_id" and nothing else.
    logger.info(
        "source %s synced to %s (%d verified)",
        body.source_id,
        body.source_status,
        outcome.verified,
        extra={"count": outcome.verified, "outcome": "verified"},
    )
    return _report(outcome)


@router.post(
    "/maintenance/bot-access",
    response_model=SyncReport,
    summary="Add or remove one bot id in the bot_ids list on the points in scope",
    operation_id="syncBotAccess",
)
def sync_bot_access_endpoint(
    body: BotAccessSyncRequest,
    ctx: Annotated[RequestContext, Depends(request_context)],
    client: Annotated[Any, Depends(qdrant_client)],
    idempotency_key: Annotated[str, Depends(_idempotency_key)],
) -> SyncReport:
    """Apply the ``bot_ids`` rewrite and return its verification counts.

    ``source_ids: null`` with ``grant: true`` is refused by `app/maintenance/payload.py` with a
    ``ValueError``, which renders 422 through the envelope — it is a malformed instruction, not
    an internal failure.
    """
    if body.grant and body.source_ids is None:
        raise KbError(
            ErrorClass.VALIDATION,
            "source_ids is required when grant is true: a null scope means every point this "
            "organization owns, and granting a bot access to every source is not an operation "
            "this contract offers",
        )
    outcome = sync_bot_access(
        client=client,
        spaces=_spaces(body.embedding_model_versions),
        org_id=ctx.org_id,
        bot_id=body.bot_id,
        source_ids=body.source_ids,
        grant=body.grant,
    )
    logger.info(
        "bot access %s applied (%d point(s) rewritten)",
        "granted" if body.grant else "revoked",
        outcome.rewritten,
        extra={"bot_id": body.bot_id, "count": outcome.verified, "outcome": "verified"},
    )
    return _report(outcome)


def _assert_no_credential_can_ride_this_endpoint() -> None:
    """Refuse at import any field whose name looks like a secret. Non-negotiable 9, mechanically.

    The same guard `embedding.py` and `ingestion.py` carry. Neither of these operations has any
    business near a provider credential — they rewrite two payload strings — so a field of that
    shape appearing here is an edit that went somewhere unrelated.
    """
    banned = ("credential", "secret", "api_key", "apikey", "token", "password", "private")
    for model in (SourceStatusSyncRequest, BotAccessSyncRequest, CollectionReport, SyncReport):
        for name in model.model_fields:
            if any(word in name.lower() for word in banned):
                raise KbError(
                    ErrorClass.INTERNAL_DEPENDENCY,
                    f"{model.__name__}.{name} names a credential-shaped field on a maintenance "
                    "contract. These operations rewrite two payload strings and never touch a "
                    "provider credential",
                )


_assert_no_credential_can_ride_this_endpoint()


# The wire pattern and the tuple that applies it, pinned at import. They are written twice on
# purpose — the pattern is what turns a third lifecycle state into a 422 naming the field
# instead of a 500 from a `ValueError` two layers down — and this is what stops the two copies
# from drifting apart, which would let the wire accept a value the applier refuses.
_PATTERN_STATUSES: Final = frozenset(("ready", "disabled"))
assert set(SYNCABLE_STATUSES) == _PATTERN_STATUSES, (
    "the source_status wire pattern and app.maintenance.payload.SYNCABLE_STATUSES disagree: "
    f"{sorted(_PATTERN_STATUSES)} vs {sorted(SYNCABLE_STATUSES)}"
)
