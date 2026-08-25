"""``POST /internal/v1/ingestion/jobs`` — Laravel hands over one ingestion submission.

The seam's front door, and the only way work enters the ingestion pipeline. It answers **202**
with a job id and nothing else: the real result travels back over the signed callback, which is
what `kb-internal-api-contracts` means by an async flow and what makes the run survivable across
a worker restart.

WHAT THIS ENDPOINT REFUSES TO BE
---------------------------------
It is not a proxy for request input. Every field of the body is read off `knowledge_sources` and
`source_items` **server-side** by `IngestionSubmission::toArray()` after the FormRequest, the
policy and the state machine have all passed — and the fields that matter most are exactly the
ones a client must never supply: ``storage_key`` is a path into object storage, ``mime`` is the
sniffed type rather than the client's ``Content-Type``, and ``content_hash`` is what the
published version is checkable against. This router therefore validates *shape*, not authority:
authority was settled in the control plane, and re-deriving it here is the duplicated check that
drifts.

THERE IS NO ``X-KB-Bot-Id`` ON THIS ROUTE, AND A ROUTER WRITTEN FROM THE SKILL FILE WOULD 422
-----------------------------------------------------------------------------------------------
A knowledge source is **organization-owned** (ADR-067). Bot access is a query-time payload
filter, never an index-time scope, so there is no single bot this submission is "for".
``.claude/skills/kb-internal-api-contracts``:64 lists ingestion among the bot-scoped operations
and is wrong about it — and the consequence is not a cosmetic mismatch: the ``x-kb-*`` header
set is *inside* the canonical string, so a router that expected the header would reject every
submission from every tenant with a signature failure, and neither signer could be corrected
alone.

THE BODY CARRIES NO BYTES AND NO CREDENTIAL
---------------------------------------------
Items name a ``storage_key``; the worker reads the object itself. Putting document text in a
submission body would put it in a Valkey job payload, in ``failed_jobs``, and in every span that
instruments request bodies. ``provider_credentials`` is likewise absent — the worker resolves
and decrypts at execution time through the provider layer's own accessor, and nothing under
``app/ingestion/`` accepts a credential parameter.

TWO KEYS, AND THIS ENDPOINT ENFORCES NEITHER — WHICH IS CORRECT (ADR-068)
---------------------------------------------------------------------------
Laravel sends ``X-KB-Idempotency-Key``, derived from `IngestionSubmission::fingerprint()`. It is
signed, and it is not consulted here, and no replay store is added for it. That is the division
ADR-068 draws: the **transport** key dedupes *delivery* and holds none of the ``*_cfg_version``
strings, because the control plane structurally cannot know them; the **ingest** key dedupes
*work identity* and holds every one of them. Enforcing the transport key here would put the
weaker of the two in front of the stronger — a resubmission whose content is unchanged and whose
OCR configuration changed carries the SAME transport key, and refusing it as a replay is exactly
the silent no-op ADR-068 exists to prevent.

What makes that safe is that a double delivery converges rather than duplicating: the prepare
phase is a pure function of content and configuration, so both deliveries compose the same
ingest key and Laravel's find-or-create resolves them to one row, and the two ``run_version``
messages that follow race for a claim only one can hold.

ACCEPTANCE IS NOT COMPLETION, AND THE 202 SAYS SO PRECISELY
-------------------------------------------------------------
What this handler guarantees on return is that one ``kb.ingest.prepare_item`` message per item
is on the broker. It does **not** guarantee a version row exists — that is the prepare phase's
job, one round trip later — and it deliberately does not wait for one: a submission of ten items
would otherwise hold an HTTP worker for ten sequential provider probes.
"""

from __future__ import annotations

import logging
from typing import Annotated, Final

from fastapi import APIRouter, Depends, status
from pydantic import BaseModel, ConfigDict, Field

from app.api.deps import RequestContext, request_context, verify_hmac
from app.core.errors import ErrorClass, KbError

logger = logging.getLogger(__name__)

__all__ = ["MAX_ITEMS", "IngestionSubmissionRequest", "router"]

#: A configuration bound rather than a policy, and it matches the control plane's own batch
#: cap: `SourceState`'s rollup comment names ``MAX_BATCH`` as 10, which is how many items one
#: submission can carry. Exceeding it describes a caller that has gone wrong rather than a
#: tenant with a lot of files — a large upload is several submissions — so it is a `validation`
#: 422 and never a truncation. Trimming would enqueue some items and silently drop the rest,
#: and the source would sit half-indexed with every frame reporting success.
MAX_ITEMS: Final[int] = 10

#: ULID, Crockford base32, 26 characters. Stated as a pattern rather than a length because a
#: 26-character string that is not a ULID is an identifier for a row that cannot exist, and the
#: value flows into a Celery payload, a Valkey key and a `WHERE` clause.
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

#: `dependencies=` on the ROUTER, never on the individual path operation, so an endpoint added
#: to this module later cannot forget it. A route that answered 202 without a verified signature
#: would be an unauthenticated way to make this deployment spend a tenant's provider budget.
router = APIRouter(prefix="/internal/v1", tags=["ingestion"], dependencies=[Depends(verify_hmac)])


class SubmissionItem(BaseModel):
    """One row of ``items``, mirroring `IngestionSubmission::toArray()`'s per-item shape.

    ``strict=True, extra="forbid", frozen=True`` — the `pydantic-contracts` ``Inbound``
    configuration. ``forbid`` is the one doing real work here: a field Laravel renames must 422
    rather than evaporate, and the field most damaging to lose silently is ``content_hash``,
    which is a component of the ingest key. A submission that dropped it would compose a
    *valid-looking* key over an empty string, dedupe against nothing, and re-embed the corpus.
    """

    model_config = ConfigDict(strict=True, extra="forbid", frozen=True)

    id: str = Field(pattern=ULID_PATTERN)
    #: The item's stable name within its source — a filename or a URL. Tenant-authored, bounded,
    #: and never interpolated into a path or a query on either side.
    canonical_key: str = Field(min_length=1, max_length=2048)
    storage_key: str = Field(min_length=1, max_length=2048)
    #: sha256 of the raw bytes for an upload; of the normalized content for a crawl. The two
    #: meanings are not interchangeable and `identity.ingest_key` says why at length.
    content_hash: str = Field(pattern=r"^[0-9a-f]{64}$")
    #: The SNIFFED type. `kb-security-baseline` refuses the client's `Content-Type`, so this is
    #: a server-side fact by the time it reaches here.
    mime: str = Field(min_length=1, max_length=255)
    byte_size: int = Field(ge=0)
    display_name: str | None = Field(default=None, max_length=1024)
    url: str | None = Field(default=None, max_length=4096)
    #: What is already live for this item, so the worker knows what its publication supersedes
    #: without querying Laravel's tables — which ADR-012 forbids it doing.
    current_version_id: str | None = Field(default=None, pattern=ULID_PATTERN)


class SubmissionSource(BaseModel):
    """The source-level facts a worker needs to name and date the version it is building."""

    model_config = ConfigDict(strict=True, extra="forbid", frozen=True)

    id: str = Field(pattern=ULID_PATTERN)
    type: str = Field(min_length=1, max_length=64)
    #: The admin's label. It reaches the data plane so a warning or a failed-job record can name
    #: a source a human recognises; it is tenant-authored free text and is never interpolated
    #: into a path, a query or a prompt on either side.
    name: str = Field(min_length=1, max_length=512)
    origin_url: str | None = Field(default=None, max_length=4096)
    tags: list[str] = Field(default_factory=list, max_length=64)
    effective_at: str | None = None
    expires_at: str | None = None


class IngestionSubmissionRequest(BaseModel):
    """What Laravel posts. One source, its items, and the force nonce.

    There is **no ``org_id`` field and there must not be one**. The organization is
    ``X-KB-Org-Id``, taken from the *verified* headers; a body-supplied tenant would be forgeable
    against a signature that did not cover it, which is why every ``x-kb-*`` header is inside the
    canonical string in the first place.
    """

    model_config = ConfigDict(strict=True, extra="forbid", frozen=True)

    job_id: str = Field(pattern=ULID_PATTERN)
    source: SubmissionSource
    #: A `list` and not a `tuple`: FastAPI validates a parsed Python `dict`, and under
    #: `strict=True` a `tuple[...]` field rejects the JSON array on every real request while
    #: `model_validate_json` on the same bytes succeeds — so a unit test written that way stays
    #: green while production 422s. That is `pydantic-contracts`' "strict mode is looser from
    #: JSON than from Python" gotcha, and it is why contract tests go through an HTTP client.
    items: list[SubmissionItem] = Field(min_length=1, max_length=MAX_ITEMS)
    #: What makes an explicit reprocess reach a worker at all. Without it a resubmission of
    #: unchanged content is byte-identical to the previous one, and the ingest key it composes
    #: is the same — so the run dedupes against the completed version and the admin sees
    #: "already processed" forever, which is exactly what they did not ask for.
    force_nonce: str | None = Field(default=None, max_length=64)


class IngestionAccepted(BaseModel):
    """The 202 body. The job id back, and how many items were enqueued — nothing else.

    Deliberately not a projection of the source. This is an internal endpoint and a fuller
    response would quietly make it a read API for tenant data; the caller already holds
    everything it sent.
    """

    model_config = ConfigDict(frozen=True)

    job_id: str
    accepted_items: int


@router.post(
    "/ingestion/jobs",
    response_model=IngestionAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    summary="Accept one ingestion submission and enqueue its items",
    # Set explicitly: FastAPI's default is derived from the function name and the path, and the
    # exported OpenAPI document generates a client method from it — which would bake a local
    # identifier into a shipped `v1` shape. The name matches the control plane's own
    # `InternalAiClient::submitIngestion`.
    operation_id="submitIngestion",
)
async def submit_ingestion(
    body: IngestionSubmissionRequest,
    ctx: Annotated[RequestContext, Depends(request_context)],
) -> IngestionAccepted:
    """Enqueue one ``kb.ingest.prepare_item`` per item and return 202.

    ``async def`` with no ``await``: the handler's only work is a broker publish per item, which
    is the one thing here that can block. It is dispatched through `celery_app.send_task`, whose
    client is synchronous — so the publish happens inline and the 202 is not returned before the
    messages are actually on the broker. Answering 202 first and enqueuing after would be a
    submission the control plane believes was accepted and no worker will ever see.
    """
    from app.worker import celery_app

    # THE ORGANIZATION COMES FROM THE VERIFIED HEADER, NOT THE BODY. `ctx.org_id` was extracted
    # from headers that are inside the signature; the body's ids are scoped by it and never the
    # other way around.
    org_id = ctx.org_id

    traceparent = _traceparent()

    for item in body.items:
        celery_app.send_task(
            "kb.ingest.prepare_item",
            kwargs={
                "org_id": org_id,
                "job_id": body.job_id,
                "source_id": body.source.id,
                # `mode="json"` so the payload holds only JSON-native types. A Celery kwarg is
                # serialized by the broker's own codec, and a value it cannot render is a task
                # that fails at publish time — after some of this submission's siblings are
                # already enqueued, which is the one partial state this loop can produce.
                "item": item.model_dump(mode="json"),
                "force_nonce": body.force_nonce,
                "traceparent": traceparent,
            },
            queue="ingest",
        )

    logger.info(
        "accepted ingestion job %s for source %s: %d item(s)",
        body.job_id,
        body.source.id,
        len(body.items),
    )
    return IngestionAccepted(job_id=body.job_id, accepted_items=len(body.items))


def _traceparent() -> str | None:
    """This request's W3C traceparent, so the worker's root span can LINK back to it.

    A link and not a parent, which `runner.root_span` implements and explains: an ingestion run
    outlives its submitting request by minutes, and a child span whose parent ended long ago
    distorts every latency percentile the submitting service reports.

    Returns ``None`` when nothing is recording rather than synthesizing an id. A fabricated
    traceparent links the worker to a trace that does not exist, which is worse than no link:
    the trace view shows a gap where a real parent would be.
    """
    from opentelemetry import trace

    span = trace.get_current_span()
    context = span.get_span_context()
    if not context.is_valid:
        return None
    return f"00-{context.trace_id:032x}-{context.span_id:016x}-{context.trace_flags:02x}"


def _assert_no_credential_can_ride_this_endpoint() -> None:
    """Refuse at import any field whose name looks like a secret. Non-negotiable 9, mechanically.

    The same guard `embedding.py` carries, for the same reason and against the same edit: the
    well-meaning "attach the decrypted key so the worker does not have to look it up twice",
    which turns a startup failure into a plaintext credential in a Valkey job payload, in
    ``failed_jobs``, and in every span that instruments request bodies.
    """
    banned = ("credential", "secret", "api_key", "apikey", "token", "password", "private")
    for model in (SubmissionItem, SubmissionSource, IngestionSubmissionRequest, IngestionAccepted):
        for name in model.model_fields:
            lowered = name.lower()
            if any(word in lowered for word in banned):
                raise KbError(
                    ErrorClass.INTERNAL_DEPENDENCY,
                    f"{model.__name__}.{name} names a credential-shaped field on the ingestion "
                    "submission contract. No provider credential may reach this wire: the "
                    "worker resolves and decrypts at execution time, and a field here would "
                    "put plaintext in a broker payload",
                )


_assert_no_credential_can_ride_this_endpoint()
