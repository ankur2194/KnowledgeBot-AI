"""``POST /internal/v1/ingestion/jobs`` — the shape Laravel actually sends, over a real signature.

The body here is built to mirror `IngestionSubmission::toArray()` field for field. That is the
whole value of this file: the two planes are separate codebases, and the way this endpoint fails
is not with an exception but with a 422 that the control plane logs as a failed submission while
the queue stays empty and the source sits at ``queued``.

It signs, because there is no other way in — `tests/contract/README.md` is explicit that a test
bypassing the signature is not a contract test.
"""

from __future__ import annotations

from collections.abc import Callable
from typing import Any

import pytest

from tests.support.signing import Signer, ulid_like

PATH = "/internal/v1/ingestion/jobs"


@pytest.fixture(autouse=True)
def captured_dispatch(monkeypatch: pytest.MonkeyPatch) -> list[dict[str, Any]]:
    """Intercept the broker publish. Nothing here should reach a real Celery connection."""
    from app.worker import celery_app

    sent: list[dict[str, Any]] = []

    def _send_task(name: str, **kwargs: Any) -> None:
        sent.append({"name": name, **kwargs})

    monkeypatch.setattr(celery_app, "send_task", _send_task)
    return sent


def _submission(**overrides: Any) -> dict[str, Any]:
    """One upload of one PDF, exactly as the control plane assembles it server-side."""
    body: dict[str, Any] = {
        "job_id": ulid_like("job"),
        "source": {
            "id": ulid_like("source"),
            "type": "upload",
            "name": "Employee handbook",
            "origin_url": None,
            "tags": ["hr", "policy"],
            "effective_at": "2026-08-01T00:00:00+00:00",
            "expires_at": None,
        },
        "items": [
            {
                "id": ulid_like("item"),
                "canonical_key": "handbook.pdf",
                "url": None,
                "display_name": "handbook.pdf",
                "storage_key": "org/x/sources/y/original/" + "a" * 64,
                "content_hash": "a" * 64,
                "mime": "application/pdf",
                "byte_size": 918_273,
                "current_version_id": None,
            }
        ],
        "force_nonce": None,
    }
    body.update(overrides)
    return body


@pytest.mark.anyio
async def test_a_signed_submission_is_accepted_with_202(
    signer: Signer, send: Callable[..., Any], captured_dispatch: list[dict[str, Any]]
) -> None:
    body = _submission()
    request = signer.build(
        "POST", PATH, body, operation="ingestion.submit", idempotency_key="k" * 64
    )

    response = await send(request)

    assert response.status_code == 202
    assert response.json() == {"job_id": body["job_id"], "accepted_items": 1}


@pytest.mark.anyio
async def test_acceptance_puts_one_prepare_message_per_item_on_the_broker(
    signer: Signer, send: Callable[..., Any], captured_dispatch: list[dict[str, Any]]
) -> None:
    """The 202 promises the messages are enqueued, not that a version exists. Answering first and
    enqueuing after would be a submission the control plane believes was accepted and no worker
    will ever see."""
    body = _submission()
    body["items"] = [body["items"][0], {**body["items"][0], "id": ulid_like("item-2")}]

    response = await send(
        signer.build("POST", PATH, body, operation="ingestion.submit", idempotency_key="k" * 64)
    )

    assert response.status_code == 202
    assert [message["name"] for message in captured_dispatch] == [
        "kb.ingest.prepare_item",
        "kb.ingest.prepare_item",
    ]
    assert {message["queue"] for message in captured_dispatch} == {"ingest"}


@pytest.mark.anyio
async def test_the_organization_comes_from_the_signed_header_and_not_the_body(
    signer: Signer, send: Callable[..., Any], captured_dispatch: list[dict[str, Any]]
) -> None:
    """A body-supplied tenant is forgeable against a signature that does not cover it, which is
    why every `x-kb-*` header is inside the canonical string in the first place."""
    await send(
        signer.build(
            "POST", PATH, _submission(), operation="ingestion.submit", idempotency_key="k" * 64
        )
    )
    assert captured_dispatch[0]["kwargs"]["org_id"] == signer.org_id


@pytest.mark.anyio
async def test_the_dispatched_payload_carries_no_credential_shaped_key(
    signer: Signer, send: Callable[..., Any], captured_dispatch: list[dict[str, Any]]
) -> None:
    """Task arguments are serialized to the broker, land in a failed-job record, and are read by
    anything that instruments task args. Non-negotiable 9 has no ingestion exemption."""
    await send(
        signer.build(
            "POST", PATH, _submission(), operation="ingestion.submit", idempotency_key="k" * 64
        )
    )
    flattened = repr(captured_dispatch[0]).lower()
    for word in ("credential", "secret", "api_key", "password", "private"):
        assert word not in flattened


@pytest.mark.anyio
async def test_an_unknown_field_is_refused_rather_than_dropped(
    signer: Signer, send: Callable[..., Any], captured_dispatch: list[dict[str, Any]]
) -> None:
    """`extra="forbid"`. A field Laravel renames must 422 here rather than evaporate — and the
    field most damaging to lose silently is `content_hash`, a component of the ingest key: a
    submission that dropped it would compose a valid-looking key over an empty string, dedupe
    against nothing, and re-embed the corpus."""
    body = _submission()
    body["items"][0]["contentHash"] = "b" * 64

    response = await send(
        signer.build("POST", PATH, body, operation="ingestion.submit", idempotency_key="k" * 64)
    )

    assert response.status_code == 422
    assert not captured_dispatch


@pytest.mark.anyio
async def test_a_malformed_content_hash_is_refused(
    signer: Signer, send: Callable[..., Any], captured_dispatch: list[dict[str, Any]]
) -> None:
    body = _submission()
    body["items"][0]["content_hash"] = "not-a-sha256"

    response = await send(
        signer.build("POST", PATH, body, operation="ingestion.submit", idempotency_key="k" * 64)
    )
    assert response.status_code == 422
    assert not captured_dispatch


@pytest.mark.anyio
async def test_a_non_ulid_identifier_is_refused(
    signer: Signer, send: Callable[..., Any], captured_dispatch: list[dict[str, Any]]
) -> None:
    """The value flows into a Celery payload, a Valkey key and a `WHERE` clause."""
    body = _submission()
    body["items"][0]["id"] = "../../etc/passwd"

    response = await send(
        signer.build("POST", PATH, body, operation="ingestion.submit", idempotency_key="k" * 64)
    )
    assert response.status_code == 422


@pytest.mark.anyio
async def test_an_empty_item_list_is_refused(
    signer: Signer, send: Callable[..., Any], captured_dispatch: list[dict[str, Any]]
) -> None:
    """A submission with no items would 202 and enqueue nothing, and the source would wait on a
    run that was never dispatched."""
    response = await send(
        signer.build(
            "POST",
            PATH,
            _submission(items=[]),
            operation="ingestion.submit",
            idempotency_key="k" * 64,
        )
    )
    assert response.status_code == 422


@pytest.mark.anyio
async def test_too_many_items_is_refused_rather_than_trimmed(
    signer: Signer, send: Callable[..., Any], captured_dispatch: list[dict[str, Any]]
) -> None:
    """Trimming would enqueue some items and silently drop the rest, and the source would sit
    half-indexed with every frame reporting success."""
    from app.api.internal.v1.ingestion import MAX_ITEMS

    body = _submission()
    template = body["items"][0]
    body["items"] = [{**template, "id": ulid_like(f"item-{n}")} for n in range(MAX_ITEMS + 1)]

    response = await send(
        signer.build("POST", PATH, body, operation="ingestion.submit", idempotency_key="k" * 64)
    )
    assert response.status_code == 422
    assert not captured_dispatch


@pytest.mark.anyio
async def test_an_unsigned_submission_never_reaches_the_broker(
    send: Callable[..., Any], captured_dispatch: list[dict[str, Any]]
) -> None:
    """The refusal's status belongs to `test_internal_transport.py`; what this asserts is that
    an unverified caller cannot make this deployment spend a tenant's provider budget."""
    import httpx

    from app.main import create_app

    transport = httpx.ASGITransport(app=create_app(), raise_app_exceptions=False)
    async with httpx.AsyncClient(transport=transport, base_url="http://ai-api") as client:
        response = await client.post(PATH, json=_submission())

    assert response.status_code >= 400
    assert not captured_dispatch


@pytest.mark.anyio
async def test_a_bot_id_header_is_not_required_by_this_route(
    signer: Signer, send: Callable[..., Any], captured_dispatch: list[dict[str, Any]]
) -> None:
    """ADR-067. A knowledge source is organization-owned, so ingestion carries no bot scope —
    `kb-internal-api-contracts`:64 lists it among the bot-scoped operations and is wrong. The
    header set is INSIDE the canonical string, so a router that expected one would reject every
    submission from every tenant with a signature failure."""
    assert signer.bot_id is None

    response = await send(
        signer.build(
            "POST", PATH, _submission(), operation="ingestion.submit", idempotency_key="k" * 64
        )
    )
    assert response.status_code == 202
