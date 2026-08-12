"""``POST /internal/v1/embedding/readiness`` — the one embedding-selection rule, over the wire.

FINDING C1, THE SEAM. ADR-030 made embedding a provider API call, and only some of the five
configured vendors publish an embedding endpoint. An organization whose only connection cannot
embed therefore cannot ingest a single document — not a degraded mode, an impossibility, since
every chunk is embedded before it is indexed and there is no fused-order-instead fallback the
way there is for reranking. ``app/providers/embedding_selection.py`` is the rule that decides
which connection embeds and why none does; this module is that rule answering a question the
control plane asks.

WHY THE CONTROL PLANE ASKS INSTEAD OF COMPUTING
-----------------------------------------------
Laravel deliberately does not reimplement the rule, and the reason is not tidiness. Two
independent halves of it are unavailable on that side:

* **The vendor axis is repository-level data with a source per cell.**
  ``capabilities.PROVIDER_TASKS`` records which vendors have a *sourced* embedding endpoint,
  and it lives here. Laravel cannot answer "does Anthropic publish one" from a
  ``provider_connections`` row.
* **The question that actually decides ingestion is not "is one eligible".** It is "do the
  eligible ones agree on ``(provider, model)``", because that pair *is* the vector space —
  ``EmbeddingSpace`` derives the Qdrant collection name from it. A looser local check would
  pass an ambiguous configuration at save time and fail it at the first upload, after the
  parse and the OCR spend, which is exactly the late discovery C1 is about.

So the handler is a **verbatim** call to ``embedding_readiness()``. Not a re-derivation, not a
loosened variant, not "at least one eligible": the same function the indexer and the query path
reach through ``resolve_embedding_connection``. That is the whole value of the round trip — a
connection screen and an upload cannot reach different verdicts, because there is one
computation.

"NOT READY" IS A 200, AND THAT IS THE LOAD-BEARING DECISION HERE
-----------------------------------------------------------------
Two failures look similar from the outside and are answered differently:

* A **malformed body** — an unknown key, a coerced type, a candidate with no ``connection_id``
  — is ``validation`` (422). The caller sent something this contract cannot read.
* A **well-formed body describing an organization that cannot embed** is a **successful
  computation whose result is "no"**: HTTP 200, ``selected: null``, ``explanation`` populated.

Collapsing the second into the first would make the endpoint unusable for the thing it exists
for. The caller is a banner on an admin screen and a save handler; a readiness endpoint that
errors whenever the answer is "not ready" forces the control plane to catch an exception in
order to draw a page, and the reflexive fix for *that* is a second, looser local rule — which
is the exact defect C1 names. ``embedding_readiness`` is total and non-raising for precisely
this reason, and this endpoint preserves that property across the wire rather than re-adding
the raise that ``resolve_embedding_connection`` puts back for the ingestion path.

NOTHING IS RESOLVED FROM STORAGE, AND NO CREDENTIAL IS ON EITHER SIDE OF THIS WIRE
-----------------------------------------------------------------------------------
The candidate set arrives **in the body**. This handler opens no database connection and
reads no ``provider_connections`` row: Laravel owns the relational store
(`kb-provider-adapter-contract`: the adapter layer "resolves nothing from storage"), and a
data-plane read of a control-plane table would be the same boundary violation as a write.

The request carries ``connection_id``s, never credentials, and the reply carries none either.
``EmbeddingConnection`` refuses at import any field whose name looks like a secret, and
``_assert_no_credential_can_ride_this_endpoint`` at the bottom of this file re-asserts the same
property over the two models this module adds — so the well-meaning edit that attaches the
decrypted key "so the caller does not have to look it up twice" is a startup failure rather
than a plaintext value in a log line, a span attribute, or an admin-screen JSON payload
(CLAUDE.md non-negotiable 9, `kb-security-baseline`, ADR-011).

THE WIRE MODELS ARE THE DOMAIN MODELS, DELIBERATELY
-----------------------------------------------------
``EmbeddingConnection``, ``EmbeddingDesignation`` and ``EmbeddingReadiness`` are used *as* the
request and response shapes rather than being projected onto endpoint-local copies. All three
already carry ``ConfigDict(frozen=True, extra="forbid", strict=True)`` — which is exactly
`pydantic-contracts`' ``Inbound`` configuration, arrived at independently there for the same
reasons — so a projection would buy nothing and cost the one thing that matters: a second
definition of the readiness shape is a second place for the verdict's field names to drift from
the verdict. ``EmbeddingReadiness`` also enforces "exactly one of ``selected`` and
``explanation``" in a model validator, and returning it directly is what carries that invariant
onto the wire instead of restating it in a serializer.

The consequence to know: this endpoint's response shape changes when
``embedding_selection``'s models change. That is intended. It is a `v1` shape, so the change
that is allowed is additive (`kb-internal-api-contracts`); a removal or a rename is
``/internal/v2``.
"""

from __future__ import annotations

import logging
from typing import Annotated, Final

from fastapi import APIRouter, Depends
from pydantic import BaseModel, ConfigDict, Field

from app.api.deps import RequestContext, request_context, verify_hmac
from app.providers.embedding_selection import (
    EmbeddingConnection,
    EmbeddingDesignation,
    EmbeddingReadiness,
    embedding_readiness,
)

logger = logging.getLogger(__name__)

__all__ = ["EmbeddingReadinessRequest", "router"]

#: A configuration bound, not a policy. Selection is O(n log n) in the candidate set and the
#: body is signed, so this is not a throughput control — it is a ceiling on what a single
#: internal request may allocate. 200 is far past any real organization (the largest plausible
#: configuration is a handful of vendors times a handful of rows), so exceeding it describes a
#: caller that has gone wrong rather than a tenant that has configured a lot. Exceeding it is
#: therefore a `validation` 422 — a malformed request — and NOT an unselected readiness, which
#: would tell an operator their configuration cannot embed when the truth is that we refused to
#: look at it.
MAX_CANDIDATES: Final = 200

#: ``dependencies=`` on the ROUTER, never on the individual path operation, so an endpoint added
#: to this module later cannot forget it (`fastapi-service`). Both are declared here rather than
#: one at the router and one at the handler, because a signature check that a handler can omit
#: is a signature check that a handler will omit.
#:
#: BOTH ARE IMPLEMENTED and this endpoint serves Laravel: ``verify_hmac`` checks the KB1
#: canonical string (`app/core/signing.py`) against the mounted key ring and claims a replay
#: nonce in the coordination Valkey, and ``request_context`` builds the scope from the verified
#: headers (`app/api/deps.py`). This note used to say the opposite — that both were
#: ``NotImplementedError`` stubs and every request here rendered 500 — which was true when the
#: router was written and sends a reader debugging a 500 after a stub that no longer exists.
#: What has not changed is why the dependency is declared rather than worked around: a router
#: that answers 200 without a verified signature is a tenant-scope oracle, because
#: ``X-KB-Org-Id`` is only trustworthy because it is signed.
router = APIRouter(prefix="/internal/v1", tags=["embedding"], dependencies=[Depends(verify_hmac)])


class EmbeddingReadinessRequest(BaseModel):
    """What Laravel posts: the organization's candidate set, plus its designation if it has one.

    ``strict=True, extra="forbid", frozen=True`` — `pydantic-contracts`' ``Inbound``
    configuration, and each setting answers a different failure:

    * ``forbid`` — a field Laravel renames must 422 here rather than evaporate. A dropped
      ``designated`` would silently resolve an ambiguous organization by the step-5 tiebreak
      instead of by the operator's explicit choice, which moves the vector space under a corpus
      with nothing raised anywhere.
    * ``strict`` — no coercion. ``connection_id`` is a ULID ``str`` and ``model`` is the vendor
      id verbatim; a value that changed shape during parsing is a different vector space.
    * ``frozen`` — a request is evidence, not scratch space. It does not buy hashability here
      because ``connections`` is a ``list`` (see below), and nothing hashes a readiness
      request.

    There is no ``org_id`` field and there must not be one. The organization is
    ``X-KB-Org-Id``, taken from the *verified* headers; a body-supplied tenant is forgeable
    against a signature that covers only method, path and body, which is why every ``X-KB-*``
    header is inside the canonical string in the first place (`kb-internal-api-contracts`).
    """

    model_config = ConfigDict(strict=True, extra="forbid", frozen=True)

    #: A ``list`` and NOT a ``tuple``, and that is a wire fact rather than a preference.
    #: FastAPI parses the body itself and validates a Python ``dict``, so under ``strict=True``
    #: a ``tuple[...]`` field rejects the JSON array with ``tuple_type`` on **every** real
    #: request — while ``model_validate_json`` on the same bytes succeeds, so a unit test
    #: written that way stays green. That is `pydantic-contracts`' "strict mode is looser from
    #: JSON than from Python" gotcha, and it is why the tier README insists contract tests go
    #: through an HTTP client.
    #:
    #: Order is irrelevant by construction — ``embedding_readiness`` de-duplicates as a set and
    #: sorts by ``order_key`` before deciding anything — so two callers who build the same set
    #: in different orders get the same verdict.
    connections: list[EmbeddingConnection] = Field(max_length=MAX_CANDIDATES)
    #: ``null`` is the ordinary state, not an omission: an organization with exactly one
    #: embedding-capable connection never needs to designate anything and the rule answers from
    #: the set. It is a ``(connection_id, model)`` PAIR because one connection can carry two
    #: embedding rows — ``text-embedding-3-large`` and ``text-embedding-3-small`` on one
    #: credential are two vector spaces, and naming the connection alone leaves the space
    #: undecided.
    designated: EmbeddingDesignation | None = None


@router.post(
    "/embedding/readiness",
    response_model=EmbeddingReadiness,
    summary="Which of an organization's connections supplies the embedding credential",
    # Set explicitly, because FastAPI's default is derived from the Python function name and
    # the path — ``embedding_readiness_endpoint_internal_v1_embedding_readiness_post`` — and
    # the exported OpenAPI document generates a client method from it (`kb-internal-api-
    # contracts`). That would bake a local identifier into a shipped `v1` shape, where renaming
    # the handler becomes a wire change. The name matches the method the control plane already
    # calls, ``InternalAiClient::embeddingReadiness``, rather than inventing a third spelling.
    operation_id="embeddingReadiness",
)
async def embedding_readiness_endpoint(
    body: EmbeddingReadinessRequest,
    ctx: Annotated[RequestContext, Depends(request_context)],
) -> EmbeddingReadiness:
    """Return ``embedding_readiness(...)`` for the candidate set in the body. Always 200.

    ``async def`` and not ``def``: the whole handler is pure Python over a bounded list with no
    I/O at all, so there is nothing to offload and a threadpool hop would only spend one of the
    shared ``CapacityLimiter(40)`` slots that parsing and OCR need (`fastapi-service`).

    ``POST`` for a read, matching what the control plane sends, because the candidate set is the
    input and it does not fit in a query string. It is still a read: it creates nothing, stores
    nothing and bills nothing, which is why there is no ``X-KB-Idempotency-Key`` — that header
    is required on mutations, and a replay record here would be a Valkey key per admin page view
    with no operation to deduplicate.

    ``ctx`` is injected and deliberately not used to *compute* anything. It is here for two
    reasons and both are structural: it is what makes a missing ``X-KB-Org-Id`` a 400 at the
    dependency rather than an unscoped success, and it is the only source of the organization —
    an endpoint that read the tenant from ``body`` would accept whichever organization the body
    named, which is the substitution the signed header exists to prevent.
    """
    readiness = embedding_readiness(body.connections, designated=body.designated)

    # No `explanation` in the log line. It carries no credential and no tenant content — only
    # connection ids, vendor names, model ids and matrix sources — but it is a paragraph, and a
    # paragraph per admin page view is a log nobody reads. The counts and the distinct reasons
    # are what an operator greps; `explanation` is already in the response the operator is
    # looking at.
    logger.info(
        "embedding readiness resolved",
        extra={
            "kb.org_id": ctx.org_id,
            "kb.operation": ctx.operation,
            "kb.request_id": ctx.request_id,
            "kb.embedding.ready": readiness.ready,
            "kb.embedding.eligible_count": len(readiness.eligible),
            "kb.embedding.rejected_count": len(readiness.rejected),
            "kb.embedding.rejection_reasons": sorted(
                {rejection.reason.value for rejection in readiness.rejected}
            ),
        },
    )
    return readiness


#: Field-name substrings that must never appear on a model this module adds. The same list, and
#: the same import-time check, as ``app/providers/embedding_selection.py`` — restated here rather
#: than imported because the two files must be able to fail independently: this one is the
#: *wire*, and a credential that reached this shape would be serialized into an HTTP response
#: body rather than merely held in memory.
_SECRET_LOOKING: Final[tuple[str, ...]] = ("credential", "api_key", "secret", "token", "password")

assert not [
    f"{EmbeddingReadinessRequest.__name__}.{name}"
    for name in EmbeddingReadinessRequest.model_fields
    for bad in _SECRET_LOOKING
    if bad in name.lower()
]
