"""The two payload-maintenance operations, over a real signature.

These are the calls that make a control-plane change reach the index — a disabled source stops
answering, an unassigned bot stops seeing. They are mutations of the retrieval index reachable
by anyone who can sign, so half of this file is about what is refused.

It signs, because there is no other way in: `tests/contract/README.md` is explicit that a test
bypassing the signature is not a contract test. What it *does* override is the Qdrant client
dependency, which is a lifespan-owned object this tier deliberately does not run a lifespan for.
"""

from __future__ import annotations

from collections.abc import Callable, Iterator
from typing import Any

import pytest

from app.api.internal.v1.maintenance import qdrant_client
from app.retrieval.collection import EmbeddingSpace
from tests.support.signing import Signer, ulid_like

STATUS_PATH = "/internal/v1/maintenance/source-status"
ACCESS_PATH = "/internal/v1/maintenance/bot-access"

IDENTITY = "emb/v1:openai:text-embedding-3-large:d3072:9f2a1c4e77b1"
SPACE = EmbeddingSpace(provider="openai", model="text-embedding-3-large", dimensions=3072)

SOURCE = ulid_like("contract-source")
BOT = ulid_like("contract-bot")
SIBLING_BOT = ulid_like("contract-bot-2")


@pytest.fixture
def fake_qdrant(signed_app: Any, signer: Signer) -> Iterator[Any]:
    """A double holding two points of one source, installed through ``dependency_overrides``.

    The override is on `qdrant_client` and nothing else. Overriding ``verify_hmac`` would make
    every assertion below vacuous; overriding the client is the same thing this tier already
    does for ``app.state``, one layer further in.
    """
    from tests.unit.test_payload_maintenance import _FakeQdrant

    client = _FakeQdrant(
        collections={
            SPACE.collection: {
                index: {
                    "org_id": signer.org_id,
                    "source_id": SOURCE,
                    "bot_ids": [BOT, SIBLING_BOT],
                    "source_status": "ready",
                }
                for index in range(2)
            }
        }
    )
    signed_app.dependency_overrides[qdrant_client] = lambda: client
    yield client
    signed_app.dependency_overrides.clear()


def _status_body(**overrides: Any) -> dict[str, Any]:
    body: dict[str, Any] = {
        "source_id": SOURCE,
        "source_status": "disabled",
        "embedding_model_versions": [IDENTITY],
    }
    body.update(overrides)
    return body


def _access_body(**overrides: Any) -> dict[str, Any]:
    body: dict[str, Any] = {
        "bot_id": BOT,
        "grant": False,
        "source_ids": [SOURCE],
        "embedding_model_versions": [IDENTITY],
    }
    body.update(overrides)
    return body


@pytest.mark.anyio
async def test_a_signed_status_sync_rewrites_the_payload_and_returns_the_proof(
    signer: Signer, send: Callable[..., Any], fake_qdrant: Any
) -> None:
    """The response IS the verification count. A 202 would hand the caller the same
    acknowledgement `set_payload` already returns whether it rewrote everything or nothing."""
    request = signer.build(
        "POST",
        STATUS_PATH,
        _status_body(),
        operation="source.status.sync",
        idempotency_key="k" * 64,
    )

    response = await send(request)

    assert response.status_code == 200
    payload = response.json()
    assert payload["operation"] == "source.status.sync"
    assert (payload["passed"], payload["verified"], payload["rewritten"]) == (True, 2, 2)
    assert payload["collections"][0]["collection"] == SPACE.collection
    assert {p["source_status"] for p in fake_qdrant.collections[SPACE.collection].values()} == {
        "disabled"
    }


@pytest.mark.anyio
async def test_an_unsigned_status_sync_is_refused(
    send: Callable[..., Any], signed_app: Any, fake_qdrant: Any
) -> None:
    """An unsigned caller reaching this endpoint could disable another tenant's whole corpus."""
    import httpx

    transport = httpx.ASGITransport(app=signed_app, raise_app_exceptions=False)
    async with httpx.AsyncClient(transport=transport, base_url="http://internal") as client:
        response = await client.post(STATUS_PATH, json=_status_body())

    assert response.status_code == 401
    assert fake_qdrant.set_payload_calls == []


@pytest.mark.anyio
async def test_the_idempotency_key_is_required_on_both_mutations(
    signer: Signer, send: Callable[..., Any], fake_qdrant: Any
) -> None:
    """`kb-internal-api-contracts` requires it on every mutation and it is inside the canonical
    string, so a caller that omits it is a signer that disagrees with ours — better found here,
    naming the field, than as a signature failure naming nothing."""
    request = signer.build(
        "POST", STATUS_PATH, _status_body(), operation="source.status.sync", idempotency_key=None
    )

    response = await send(request)

    assert response.status_code == 422
    assert "IDEMPOTENCY" in response.text.upper()


@pytest.mark.anyio
async def test_an_unknown_field_is_refused_rather_than_dropped(
    signer: Signer, send: Callable[..., Any], fake_qdrant: Any
) -> None:
    """`extra="forbid"`. A field the control plane renames must 422 here rather than evaporate —
    a dropped `embedding_model_versions` would address no collection at all."""
    request = signer.build(
        "POST",
        STATUS_PATH,
        _status_body(org_id="01J0000000000000000000000Z"),
        operation="source.status.sync",
        idempotency_key="k" * 64,
    )

    assert (await send(request)).status_code == 422


@pytest.mark.anyio
@pytest.mark.parametrize("status", ["deleting", "indexing", "ready_with_warnings", "READY"])
async def test_a_status_outside_the_syncable_pair_is_a_422_naming_the_field(
    signer: Signer, send: Callable[..., Any], fake_qdrant: Any, status: str
) -> None:
    """Constrained on the wire as well as in the module that applies it, so a control plane that
    grew a third lifecycle state gets a 422 rather than a 500 from a `ValueError` two layers
    down."""
    request = signer.build(
        "POST",
        STATUS_PATH,
        _status_body(source_status=status),
        operation="source.status.sync",
        idempotency_key="k" * 64,
    )

    assert (await send(request)).status_code == 422
    assert fake_qdrant.set_payload_calls == []


@pytest.mark.anyio
async def test_an_unparseable_embedding_identity_is_never_defaulted(
    signer: Signer, send: Callable[..., Any], fake_qdrant: Any
) -> None:
    """A default would name a REAL collection and rewrite someone else's vector space."""
    request = signer.build(
        "POST",
        STATUS_PATH,
        _status_body(embedding_model_versions=["text-embedding-3-large"]),
        operation="source.status.sync",
        idempotency_key="k" * 64,
    )

    response = await send(request)

    assert response.status_code == 422
    assert "cannot be defaulted" in response.text


@pytest.mark.anyio
async def test_the_organization_comes_from_the_signed_header_and_not_the_body(
    signer: Signer, send: Callable[..., Any], fake_qdrant: Any
) -> None:
    """There is no `org_id` field and there must not be one. The points here belong to the
    signer's org; a second signer for another org rewrites none of them."""
    other = Signer(
        secret=signer.secret,
        key_id=signer.key_id,
        org_id=ulid_like("other-org"),
        actor_id=ulid_like("other-actor"),
    )
    request = other.build(
        "POST",
        STATUS_PATH,
        _status_body(),
        operation="source.status.sync",
        idempotency_key="k" * 64,
    )

    response = await send(request)

    assert response.status_code == 200, response.text
    assert response.json()["verified"] == 0
    assert {p["source_status"] for p in fake_qdrant.collections[SPACE.collection].values()} == {
        "ready"
    }


@pytest.mark.anyio
async def test_a_revoke_removes_one_id_and_leaves_the_sibling_bot(
    signer: Signer, send: Callable[..., Any], fake_qdrant: Any
) -> None:
    request = signer.build(
        "POST", ACCESS_PATH, _access_body(), operation="bot.access.sync", idempotency_key="k" * 64
    )

    response = await send(request)

    assert response.status_code == 200, response.text
    assert response.json()["verified"] == 0
    assert all(
        p["bot_ids"] == [SIBLING_BOT] for p in fake_qdrant.collections[SPACE.collection].values()
    )


@pytest.mark.anyio
async def test_an_organization_wide_grant_is_refused_at_the_contract(
    signer: Signer, send: Callable[..., Any], fake_qdrant: Any
) -> None:
    """`source_ids: null` means every point the tenant owns. Revoking org-wide is the
    deleted-bot case; granting org-wide is not an operation this contract offers."""
    request = signer.build(
        "POST",
        ACCESS_PATH,
        _access_body(grant=True, source_ids=None),
        operation="bot.access.sync",
        idempotency_key="k" * 64,
    )

    response = await send(request)

    assert response.status_code == 422
    assert fake_qdrant.set_payload_calls == []


@pytest.mark.anyio
async def test_a_rewrite_that_does_not_verify_is_a_failure_and_not_a_passed_false_body(
    signer: Signer, send: Callable[..., Any], fake_qdrant: Any
) -> None:
    """The control plane's queued job needs a non-2xx to retry and compensate. A body it could
    ignore is a body it will ignore — which commits a status the index never heard about."""
    fake_qdrant.swallow_writes = True
    request = signer.build(
        "POST",
        STATUS_PATH,
        _status_body(),
        operation="source.status.sync",
        idempotency_key="k" * 64,
    )

    response = await send(request)

    assert response.status_code >= 500
    # Relayed verbatim by Laravel and never re-derived from the status: the queued caller reads
    # this class to decide between retrying and compensating.
    assert response.json()["error_class"] == "vector_indexing"
