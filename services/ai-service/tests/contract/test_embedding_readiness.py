"""``POST /internal/v1/embedding/readiness`` at the wire, where the two planes have to agree.

The subject is a JSON body, so this tier is the right one (`tests/contract/README.md`). What
is being pinned is the pair of properties Laravel's side depends on and cannot check for
itself:

* **The reply is ``embedding_readiness()`` verbatim.** Not "an endpoint that also concludes
  the organization cannot embed" — the same object, field for field. The C1 failure is a
  connection screen and an upload reaching different verdicts, and the only defence against
  that is one computation with no projection between it and the wire.
* **"Not ready" is a 200 and a malformed body is a 422**, and the two are never conflated in
  either direction. A readiness endpoint that errors when the answer is "not ready" is
  unusable as a banner, and a readiness endpoint that answers 200 "not ready" to a body it
  could not parse tells an operator to fix a configuration that was never examined.

EVERY REQUEST HERE IS SIGNED. THE OVERRIDES ARE GONE, AND SO IS THE TEST THAT DEMANDED THEY GO
------------------------------------------------------------------------------------------------
This file used to replace ``verify_hmac`` and ``request_context`` through FastAPI's
``dependency_overrides``, because both were ``NotImplementedError`` stubs and there was nothing
to sign *against*. ``test_the_dependency_overrides_below_are_still_necessary`` was written to
fail the moment either grew a body, and its message was the instruction: delete the override
and sign with ``tests/support/signing.py``. That is what happened, so the test is gone with the
thing it was watching — it existed to be deleted, and leaving it as an ``xfail`` would keep a
watchman on a door that is no longer there.

What replaced it is `tests/contract/conftest.py`: a real key ring on ``app.state``, a signer
holding the same secret, and a coordination store the replay check can claim against. The
transport's own behaviour — skew, replay, tampering, an unknown key id — is
`tests/contract/test_internal_transport.py`; this file is about the endpoint, and it signs
because there is no other way in.

``test_an_unverified_request_is_refused`` survives, and survives for the reason it was written
for: it asserts a refusal rather than a status number, so implementing the verifier did not make
it look like a regression. Read its own docstring for which refusal it now gets and why that is
not the 401 the transport file asserts.

NO ``LifespanManager``, DELIBERATELY, AND CONTRARY TO THIS TIER'S DEFAULT
--------------------------------------------------------------------------
The tier README makes the manager mandatory because ``ASGITransport`` does not run lifespan, so
neither state object is populated and every route that reads one is answering about a container
it knows nothing about. (The README said for a while that ``/health/ready`` would otherwise
report **200** against clients that were never built; it reports **503**, because an unpopulated
``request.state`` yields ``None`` and ``None`` is not ready. Corrected there.) This
route reads nothing from ``request.state``: it opens no pool, touches no Qdrant client, and
resolves nothing from storage — that last one is the contract, not an accident. Running
lifespan would additionally call ``get_settings()`` → ``check_environment(os.environ)``, which
fails on the ``KB_TEST_*`` variables the harness itself sets, so the manager would make this
file pass locally and error in CI for a reason unrelated to anything it asserts. The three
objects lifespan is supposed to install are put on ``app.state`` by the tier conftest instead.
"""

from __future__ import annotations

from collections.abc import AsyncIterator
from typing import Any

import httpx
import pytest

from app.api.deps import verify_hmac
from app.main import create_app
from app.providers.contract import Capability, ModelCapabilities
from app.providers.embedding_selection import (
    EmbeddingConnection,
    EmbeddingDesignation,
    EmbeddingIneligibility,
    embedding_readiness,
)
from tests.support.signing import Signer

PATH = "/internal/v1/embedding/readiness"


#: A ULID-shaped id per fixture connection, ordered by ``n`` so a test can say "the older
#: connection" and mean it — the step-5 tiebreak leans on ULIDs sorting by creation time.
def _ulid(n: int) -> str:
    return f"01J8ZQ9K7N000000000000{n:04d}"


#: The only sourced embedder in ``capabilities.PROVIDER_TASKS``.
OPENAI_EMBED = ("openai", "text-embedding-3-large")


def _caps(*flags: str, context_window: int = 8192, max_output_tokens: int = 0) -> dict[str, Any]:
    """A ``provider_models`` row as Laravel serializes it — the four keys, no more.

    Written as a raw dict rather than by dumping ``ModelCapabilities``, because a contract
    test that builds its payload from the model under test asserts only that the model agrees
    with itself. This is the shape ``EmbeddingCandidate::toArray()`` emits in
    ``services/core-api/app/Services/Embedding/EmbeddingCandidate.php``.
    """
    return {
        "supported": list(flags),
        "context_window": context_window,
        "max_output_tokens": max_output_tokens,
        "on_unsupported": "reject",
    }


def _wire(n: int, provider: str, model: str, caps: dict[str, Any]) -> dict[str, Any]:
    return {"connection_id": _ulid(n), "provider": provider, "model": model, "caps": caps}


def _embed_row(*extra: Capability) -> ModelCapabilities:
    return ModelCapabilities(
        supported=frozenset({Capability.EMBEDDING, *extra}),
        context_window=8192,
        max_output_tokens=0,
    )


def _chat_row() -> ModelCapabilities:
    return ModelCapabilities(
        supported=frozenset({Capability.TEXT, Capability.TOOL_USE}),
        context_window=200_000,
        max_output_tokens=8192,
    )


def _conn(n: int, provider: str, model: str, caps: ModelCapabilities) -> EmbeddingConnection:
    return EmbeddingConnection(connection_id=_ulid(n), provider=provider, model=model, caps=caps)


CHAT_CAPS = _caps("text", "tool_use", max_output_tokens=8192)
CHAT_WIRE = _wire(1, "anthropic", "claude-opus-5", CHAT_CAPS)
EMBED_WIRE = _wire(2, *OPENAI_EMBED, _caps("embedding"))
CHAT_MODEL = _conn(1, "anthropic", "claude-opus-5", _chat_row())
EMBED_MODEL = _conn(2, *OPENAI_EMBED, _embed_row())


# ── the harness ───────────────────────────────────────────────────────────────


@pytest.fixture
async def post(send: Any, signer: Signer) -> AsyncIterator[Any]:
    """POST a signed request carrying ``body`` as JSON.

    ``operation="embedding.readiness"`` and no ``X-KB-Bot-Id``, mirroring the real client: a
    source belongs to the organization and has not been assigned to a bot when it is embedded,
    which is why ``EmbeddingReadinessRequest`` carries no ``bot_id`` either.

    The body is serialized **once** by the signer and the same ``bytes`` object is hashed and
    sent. Passing ``json=`` alongside would let httpx re-encode it, and the signature would then
    cover different bytes than the socket carries — a 401 everyone blames on clock skew.
    """

    async def _call(body: Any) -> httpx.Response:
        return await send(signer.build("POST", PATH, body, operation="embedding.readiness"))

    yield _call


# ── the router refuses an unverified caller ───────────────────────────────────


def test_the_router_declares_signature_verification() -> None:
    """``verify_hmac`` is a ROUTER-level dependency so a new endpoint cannot forget it.

    Asserted against the resolved dependency graph rather than by reading the source, so a
    later endpoint added to the same module inherits the check and is covered by this test the
    moment it exists.
    """
    from app.api.internal.v1 import embedding as module

    route = next(r for r in module.router.routes if getattr(r, "path", None).endswith("readiness"))
    resolved = {dependency.call for dependency in route.dependant.dependencies}
    assert verify_hmac in resolved, (
        "the readiness router must carry dependencies=[Depends(verify_hmac)]; X-KB-Org-Id is "
        "only trustworthy because it is signed, and this endpoint is scoped by it"
    )


async def test_an_unverified_request_is_refused() -> None:
    """No signature, no answer — on an app with no transport state at all.

    This is **not** the test that fails if ``verify_hmac`` is dropped from the router, and that
    was verified rather than assumed: the handler also depends on ``request_context``, which
    reads headers this request does not carry, so removing one still leaves the request
    failing on the other. ``test_the_router_declares_signature_verification`` is what pins the
    dependency. What this test pins is the outcome — no verdict reaches an unverified caller.

    It deliberately does not assert a specific status. Its original docstring predicted this
    would become a plain 401 once the verifier landed, and against a *configured* app it does
    (`test_internal_transport.py`). Against this one — built by ``create_app()`` with nothing on
    ``app.state`` — it is a 500 with ``origin=SELF``, because a process that never loaded a key
    ring is our defect rather than a rejected caller. Both are refusals; pinning the number here
    would make either correct implementation look like a regression. What must never happen is a
    **verdict** reaching an unverified caller, so that is what is asserted.
    """
    transport = httpx.ASGITransport(app=create_app(), raise_app_exceptions=False)
    async with httpx.AsyncClient(transport=transport, base_url="http://ai-api") as client:
        response = await client.post(PATH, json={"connections": [EMBED_WIRE], "designated": None})

    assert response.status_code != 200
    assert "selected" not in response.json()


# ── a well-formed body always answers 200, including when the answer is no ────


async def test_an_org_that_cannot_embed_gets_a_200_and_a_reason(post: Any) -> None:
    """The C1 case over the wire. A blocking banner is not an error.

    If this ever became a 4xx, the control plane would have to catch an exception to render a
    page, and the reflexive fix for that is a second, looser local rule — which is the late
    discovery C1 is about.
    """
    response = await post({"connections": [CHAT_WIRE], "designated": None})

    assert response.status_code == 200
    payload = response.json()
    assert payload["selected"] is None
    assert "cannot ingest" in payload["explanation"]
    assert payload["eligible"] == []
    assert [r["reason"] for r in payload["rejected"]] == [
        EmbeddingIneligibility.VENDOR_HAS_NO_ENDPOINT.value
    ]
    assert payload["rejected"][0]["connection_id"] == _ulid(1)


async def test_an_org_with_no_connections_at_all_is_a_successful_no(post: Any) -> None:
    """Empty is not malformed. An organization that has configured nothing yet is the most
    common unready state there is, and it is the first thing the connection screen renders."""
    response = await post({"connections": [], "designated": None})

    assert response.status_code == 200
    assert response.json()["selected"] is None
    assert "no provider connections at all" in response.json()["explanation"]


async def test_a_ready_org_selects_the_embedding_connection(post: Any) -> None:
    response = await post({"connections": [CHAT_WIRE, EMBED_WIRE], "designated": None})

    assert response.status_code == 200
    payload = response.json()
    assert payload["selected"]["connection_id"] == _ulid(2)
    assert payload["selected"]["provider"], payload["selected"]["model"] == OPENAI_EMBED
    assert payload["explanation"] == ""
    # Present even on success: an operator asking "why is my Anthropic key not being used"
    # needs the answer whether or not some other connection saved the day.
    assert [r["connection_id"] for r in payload["rejected"]] == [_ulid(1)]


async def test_two_embedding_models_refuse_over_the_wire_rather_than_pick_one(post: Any) -> None:
    """Ambiguity is a 200 that says no, because ``(provider, model)`` IS the vector space.

    Breaking the tie here would let an unrelated connection edit move the space a corpus was
    indexed under: cosine distance is defined between any two vectors of equal width, so
    nothing raises and only ranking changes.
    """
    body = {
        "connections": [
            _wire(1, "openai", "text-embedding-3-large", _caps("embedding")),
            _wire(2, "openai", "text-embedding-3-small", _caps("embedding")),
        ],
        "designated": None,
    }
    response = await post(body)

    assert response.status_code == 200
    payload = response.json()
    assert payload["selected"] is None
    assert len(payload["eligible"]) == 2
    assert "text-embedding-3-large" in payload["explanation"]
    assert "text-embedding-3-small" in payload["explanation"]
    assert "Designate" in payload["explanation"]


async def test_a_designation_is_honoured_over_the_wire(post: Any) -> None:
    """The pair, not the connection: one credential can carry two spaces."""
    body = {
        "connections": [
            _wire(1, "openai", "text-embedding-3-large", _caps("embedding")),
            _wire(1, "openai", "text-embedding-3-small", _caps("embedding")),
        ],
        "designated": {"connection_id": _ulid(1), "model": "text-embedding-3-small"},
    }
    response = await post(body)

    assert response.status_code == 200
    assert response.json()["selected"]["model"] == "text-embedding-3-small"


async def test_a_designation_that_cannot_be_honoured_is_never_substituted(post: Any) -> None:
    body = {
        "connections": [EMBED_WIRE],
        "designated": {"connection_id": _ulid(99), "model": OPENAI_EMBED[1]},
    }
    response = await post(body)

    assert response.status_code == 200
    payload = response.json()
    assert payload["selected"] is None
    assert "never substituted" in payload["explanation"]
    # The eligible connection is still reported — it just was not silently promoted.
    assert [c["connection_id"] for c in payload["eligible"]] == [_ulid(2)]


# ── the reply is the function's own output, not a restatement of it ───────────


@pytest.mark.parametrize(
    "connections",
    [
        [],
        [CHAT_WIRE],
        [CHAT_WIRE, EMBED_WIRE],
        [
            _wire(1, "openai", "text-embedding-3-large", _caps("embedding")),
            _wire(2, "openai", "text-embedding-3-small", _caps("embedding")),
        ],
        # A row that claims two task families: eligible on both axes and still unusable.
        [_wire(3, *OPENAI_EMBED, _caps("embedding", "text"))],
    ],
)
async def test_the_reply_is_embedding_readiness_verbatim(post: Any, connections: Any) -> None:
    """Byte for byte against the function, over every shape of verdict it can produce.

    This is the assertion the whole endpoint exists to satisfy. Anything that re-derives,
    re-orders, filters or renames on the way out shows up here as a diff — including a
    "helpful" omission of ``rejected`` on the happy path, which is the field an operator
    actually opens the screen to read.
    """
    expected = embedding_readiness(
        tuple(
            EmbeddingConnection(
                connection_id=c["connection_id"],
                provider=c["provider"],
                model=c["model"],
                caps=ModelCapabilities(
                    supported=frozenset(Capability(f) for f in c["caps"]["supported"]),
                    context_window=c["caps"]["context_window"],
                    max_output_tokens=c["caps"]["max_output_tokens"],
                ),
            )
            for c in connections
        )
    )

    response = await post({"connections": connections, "designated": None})

    assert response.status_code == 200
    assert response.json() == expected.model_dump(mode="json")


async def test_the_verdict_does_not_depend_on_the_order_of_the_connections(post: Any) -> None:
    """The indexer, the query path and this screen build their candidate lists independently.

    If the answer moved with the order, one caller could index under a connection another
    never selects — and every test that happened to build the list the same way would agree.
    """
    third = _wire(3, *OPENAI_EMBED, _caps("embedding"))
    forward = await post({"connections": [CHAT_WIRE, EMBED_WIRE, third], "designated": None})
    reverse = await post({"connections": [third, EMBED_WIRE, CHAT_WIRE], "designated": None})

    assert forward.status_code == reverse.status_code == 200
    assert forward.json() == reverse.json()
    assert forward.json()["selected"]["connection_id"] == _ulid(2)


async def test_the_same_body_renders_the_same_bytes_twice(post: Any) -> None:
    """The whole reply is reproducible, which is what makes a byte comparison legal at a seam.

    Deliberately **not** the test for ``ModelCapabilities``' sorted-flags serializer, and the
    distinction matters. A rendered candidate here always carries exactly one flag, because
    ``assert_row_coherent`` refuses a row that claims embedding beside anything else — so an
    unsorted set of one is sorted by accident and this test would stay green with the
    serializer deleted. The serializer is pinned where it is observable, over a multi-flag row,
    in ``tests/unit/test_model_capabilities_wire_form.py``.
    """
    body = {"connections": [CHAT_WIRE, EMBED_WIRE], "designated": None}
    first = await post(body)
    second = await post(body)

    assert first.status_code == second.status_code == 200
    assert first.text == second.text


# ── malformed is 422, and is never dressed up as an unselected readiness ──────


async def test_an_unknown_key_in_the_body_is_a_validation_422(post: Any) -> None:
    """A field Laravel renames must 422 here rather than evaporate.

    And it must not arrive as a *verdict*: answering 200 "not ready" to a body we could not
    read would tell an operator to fix a configuration nothing examined.
    """
    response = await post(
        {"connections": [EMBED_WIRE], "designated": None, "embedding_model": "guessed"}
    )

    assert response.status_code == 422
    payload = response.json()
    assert payload["error_class"] == "validation"
    assert payload["retryable"] is False
    assert "selected" not in payload
    # The `errors` map is the superset only `validation` carries, keyed by field path.
    assert "embedding_model" in payload["errors"]


async def test_an_unknown_key_inside_caps_is_a_validation_422(post: Any) -> None:
    """``ModelCapabilities`` is an inbound model too, and it arrives one level down.

    Without ``extra="forbid"`` on it a mis-spelled cap key evaporates and the row keeps
    whatever ``supported`` it was left with — a connection that silently stops being eligible,
    with the operator told to fix something that was never broken.
    """
    caps = _caps("embedding")
    caps["capability_flags"] = ["embedding"]
    response = await post({"connections": [_wire(2, *OPENAI_EMBED, caps)], "designated": None})

    assert response.status_code == 422
    assert response.json()["error_class"] == "validation"


async def test_a_stringly_typed_context_window_is_a_validation_422(post: Any) -> None:
    """``strict=True``: ``"8192"`` must not become ``8192``.

    ``X-KB-Config-Version`` claims both planes hold the same snapshot; a value that changed
    shape during parsing makes that claim false without moving the version.
    """
    caps = _caps("embedding")
    caps["context_window"] = "8192"
    response = await post({"connections": [_wire(2, *OPENAI_EMBED, caps)], "designated": None})

    assert response.status_code == 422
    assert response.json()["error_class"] == "validation"


async def test_a_connection_id_that_is_not_a_ulid_is_a_validation_422(post: Any) -> None:
    bad = dict(EMBED_WIRE, connection_id="not-a-ulid")
    response = await post({"connections": [bad], "designated": None})

    assert response.status_code == 422
    assert response.json()["error_class"] == "validation"


async def test_a_candidate_set_past_the_ceiling_is_a_422_and_not_a_verdict(post: Any) -> None:
    """A refusal to look is not a finding about the configuration.

    Returning an unselected readiness here would put "this organization cannot embed" in front
    of an operator whose configuration was never examined.
    """
    from app.api.internal.v1.embedding import MAX_CANDIDATES

    oversized = [_wire(n, *OPENAI_EMBED, _caps("embedding")) for n in range(MAX_CANDIDATES + 1)]
    response = await post({"connections": oversized, "designated": None})

    assert response.status_code == 422
    assert "selected" not in response.json()


async def test_designated_may_be_omitted_entirely(post: Any) -> None:
    """``null`` and absent mean the same thing: this organization designated nothing.

    Additive tolerance in the direction that matters — a client that has not been updated to
    send the key must not 422 on a read.
    """
    response = await post({"connections": [CHAT_WIRE, EMBED_WIRE]})

    assert response.status_code == 200
    assert response.json()["selected"]["connection_id"] == _ulid(2)


# ── nothing on this wire may carry a credential ───────────────────────────────


async def test_a_credential_attached_to_a_candidate_is_refused_and_never_echoed(
    post: Any,
) -> None:
    """The reflexive edit — attach the decrypted key "so the caller does not have to look it
    up twice" — must fail at the wire, and the rejection must not quote the value back.

    Pydantic renders the offending input in ``ValidationError.errors()`` by default, so a 422
    is the exact place a secret lands in a response body, a log line and a support ticket.
    ``_handle_validation_error`` echoes only ``loc`` and ``msg``; this is the test that keeps
    it that way.
    """
    key = "sk-live-not-a-real-key-0123456789"
    poisoned = dict(EMBED_WIRE, provider_credential=key)

    response = await post({"connections": [poisoned], "designated": None})

    assert response.status_code == 422
    assert key not in response.text
    # Keyed by the dotted field path, the way Laravel spells a nested FormRequest error, so a
    # form can render the message against the input that caused it.
    assert response.json()["errors"] == {
        "connections.0.provider_credential": ["Extra inputs are not permitted"]
    }


async def test_no_reply_field_can_hold_a_credential(post: Any) -> None:
    """Belt to the import-time check's braces: assert it over an actual response body.

    ``rejected[].detail`` is the only free-text field on the reply and it is built from
    connection ids, vendor names, model ids and matrix sources. If a credential ever reached
    the selection models it would be serialized straight into this payload, which an admin
    screen renders.
    """
    response = await post({"connections": [CHAT_WIRE, EMBED_WIRE], "designated": None})

    rendered = response.text.lower()
    for banned in ("credential", "api_key", "secret", "password", "sk-"):
        assert banned not in rendered, f"{banned!r} appears in a readiness reply"


# ── the shapes the two planes were written against ────────────────────────────


def test_the_selection_models_accept_the_control_plane_s_serialization() -> None:
    """A direct check that the PHP ``toArray()`` shape validates, with no HTTP in the way.

    Mirrors ``EmbeddingCandidate::toArray()`` and ``EmbeddingDesignation::toArray()``. When
    this fails and the HTTP tests still pass, the drift is in the fixtures rather than the
    endpoint.
    """
    connection = EmbeddingConnection.model_validate(EMBED_WIRE)
    assert connection == EMBED_MODEL

    designation = EmbeddingDesignation.model_validate(
        {"connection_id": _ulid(2), "model": OPENAI_EMBED[1]}
    )
    assert (designation.connection_id, designation.model) == (_ulid(2), OPENAI_EMBED[1])


def test_the_chat_fixture_is_genuinely_ineligible() -> None:
    """Positive control for every "cannot embed" assertion above.

    A fixture that was eligible all along would make the refusal tests pass for the wrong
    reason, which is the failure mode a one-organization tenancy fixture has.
    """
    assert embedding_readiness((CHAT_MODEL,)).selected is None
    assert embedding_readiness((EMBED_MODEL,)).selected == EMBED_MODEL
