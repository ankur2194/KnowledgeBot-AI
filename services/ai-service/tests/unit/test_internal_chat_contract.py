"""``app/contracts/internal/chat.py`` — the shape, the masking, and the hashing rules.

Model shape is what this tier may assert (`tests/unit/README.md`). Nothing here touches a
stream, a transport or a query result; the endpoint's behaviour is
``tests/contract/test_internal_chat_stream.py``'s.

Two properties are asserted with **more than one credential in the fixture**, deliberately. A
redaction assertion naming a single field, or a fixture holding one entry, passes vacuously
against a map that has three — and the map is the shape ADR-011 was amended to
(`kb-internal-api-contracts`, finding F12).
"""

from __future__ import annotations

import json
from typing import Any

import pytest
from pydantic import SecretStr, ValidationError

from app.contracts.internal.chat import (
    CLIENT_FORWARDED_EVENTS,
    EVENT_NAMES,
    INTERNAL_ONLY_EVENTS,
    STREAM_EVENT,
    ChatExecuteRequest,
    Citation,
    Citations,
    ConfigSnapshot,
    MessageComplete,
    MessageStart,
    ProviderConnection,
    ReasoningEffort,
    Status,
    StreamError,
    Token,
    Usage,
    assert_scope_agrees,
    credential_for,
    parse_request,
    snapshot_hash,
    sse_data,
)
from app.core.errors import ErrorClass, KbError

CHAT_CONNECTION = "01J8A4QK7QK7QK7QK7QK7QK7QK"
EMBED_CONNECTION = "01J8A4QK7QK7QK7QK7QK7QK7QM"
RERANK_CONNECTION = "01J8A4QK7QK7QK7QK7QK7QK7QN"

CAPS: dict[str, Any] = {
    "supported": ["text", "stream_usage"],
    "context_window": 8192,
    "max_output_tokens": 2048,
    "on_unsupported": "reject",
}


def connection(connection_id: str = CHAT_CONNECTION, **overrides: Any) -> dict[str, Any]:
    return {
        "connection_id": connection_id,
        "provider": "openai",
        "model": "gpt-5.6-sol",
        "caps": dict(CAPS),
        **overrides,
    }


def snapshot(**overrides: Any) -> dict[str, Any]:
    return {
        "config_version": 7,
        "retrieval_configuration_version": 3,
        "connection": connection(),
        "embedding_connection": connection(EMBED_CONNECTION, model="text-embedding-3-large"),
        "retrieval": {
            "dense_top_k": 20,
            "sparse_top_k": 20,
            "rerank_top_n": 25,
            "fusion_k": 60,
            "retain": 8,
        },
        "allowed_version_ids": ["01J8A4QK7QK7QK7QK7QK7QK7QV"],
        "embedding_model_version": "emb/v1:openai:text-embedding-3-large:d1024:0a1b2c",
        "max_output_tokens": 1024,
        **overrides,
    }


def body(**overrides: Any) -> dict[str, Any]:
    return {
        "org_id": "01J8A4QK7QK7QK7QK7QK7QK7QA",
        "bot_id": "01J8A4QK7QK7QK7QK7QK7QK7QB",
        "conversation_id": "01J8A4QK7QK7QK7QK7QK7QK7QC",
        "message_id": "01J8A4QK7QK7QK7QK7QK7QK7QD",
        "client_message_id": "01J8A4QK7QK7QK7QK7QK7QK7QE",
        "actor_type": "user",
        "query": "does the XR-400B cover accidental damage?",
        "deadline_epoch_ms": 1_786_000_000_000,
        "config": snapshot(),
        # THREE entries, not one. Every masking and hashing assertion below walks the values,
        # because a check written against a single field passes vacuously on a map.
        "provider_credentials": {
            CHAT_CONNECTION: "sk-chat-aaaaaaaaaaaaaaaa",
            EMBED_CONNECTION: "sk-embed-bbbbbbbbbbbbbbbb",
            RERANK_CONNECTION: "nvapi-cccccccccccccccc",
        },
        **overrides,
    }


def a_request(**overrides: Any) -> ChatExecuteRequest:
    # `model_validate` on a PYTHON dict, which is what FastAPI does after parsing the body.
    # `model_validate_json` is *looser* under strict mode and would let a shape through that
    # 422s against the running service.
    return ChatExecuteRequest.model_validate(body(**overrides))


# ── strict mode, at the boundary ──────────────────────────────────────────────


def test_a_string_integer_is_refused_rather_than_coerced() -> None:
    """`"8"` must not become `8`. `X-KB-Config-Version` claims both planes hold the same
    snapshot; coercion makes that claim false without moving the version."""
    with pytest.raises(ValidationError) as caught:
        ChatExecuteRequest.model_validate(
            body(config=snapshot(retrieval={**snapshot()["retrieval"], "dense_top_k": "8"}))
        )
    assert {error["type"] for error in caught.value.errors()} == {"int_type"}


def test_an_integer_boolean_is_refused_rather_than_coerced() -> None:
    """`rerank_enabled = 0` from a PHP cast is how reranking turns itself off with an empty
    config diff."""
    with pytest.raises(ValidationError) as caught:
        ChatExecuteRequest.model_validate(
            body(config=snapshot(retrieval={**snapshot()["retrieval"], "rewrite_enabled": 1}))
        )
    assert {error["type"] for error in caught.value.errors()} == {"bool_type"}


def test_an_integer_temperature_stays_legal_because_int_to_float_survives_strict() -> None:
    """The one documented exception in the policy, asserted so nobody 'fixes' it."""
    assert a_request(config=snapshot(temperature=1)).config.temperature == 1.0


def test_a_renamed_field_is_refused_and_never_silently_dropped() -> None:
    with pytest.raises(ValidationError) as caught:
        ChatExecuteRequest.model_validate(body(top_k=8))
    assert {error["type"] for error in caught.value.errors()} == {"extra_forbidden"}


def test_a_json_array_still_validates_into_the_tuple_fields() -> None:
    """The strict-mode container trap, pinned. FastAPI validates an already-parsed Python
    ``dict``, so a bare ``tuple[...]`` field would reject every real request with
    ``tuple_type`` while ``model_validate_json`` on the same bytes stayed green."""
    request = a_request(history=["user: hi", "assistant: hello"])
    assert request.history == ("user: hi", "assistant: hello")
    assert request.config.allowed_version_ids == ("01J8A4QK7QK7QK7QK7QK7QK7QV",)


def test_a_non_ulid_credential_key_is_a_validation_error_not_an_ignored_entry() -> None:
    """A ``dict`` has no ``extra="forbid"``; the key type is the only thing keeping the map
    from becoming a free-form bag."""
    with pytest.raises(ValidationError) as caught:
        ChatExecuteRequest.model_validate(
            body(provider_credentials={"not-a-ulid": "sk-x", CHAT_CONNECTION: "sk-y"})
        )
    assert "string_pattern_mismatch" in {error["type"] for error in caught.value.errors()}


def test_an_empty_allowed_version_set_is_refused_at_the_boundary() -> None:
    """``Filter(must=[])`` is a confirmed match-all and ``MatchAny(any=[])`` matches nothing
    without saying so, so ``tenant_filter`` raises on an empty scope. Refusing here turns that
    into a 422 naming the field instead of a 500 from inside stage 6."""
    with pytest.raises(ValidationError) as caught:
        ChatExecuteRequest.model_validate(body(config=snapshot(allowed_version_ids=[])))
    assert {error["type"] for error in caught.value.errors()} == {"too_short"}


def test_the_seven_reasoning_levels_are_all_present() -> None:
    """MINIMAL and XHIGH are not synonyms of their neighbours: an enum missing either rounds a
    vendor's recommended setting to the nearest level we happen to model."""
    assert [effort.value for effort in ReasoningEffort] == [
        "none",
        "minimal",
        "low",
        "medium",
        "high",
        "xhigh",
        "max",
    ]


# ── the credential map ────────────────────────────────────────────────────────


def test_every_credential_is_masked_in_every_rendering() -> None:
    """Walks the VALUES, with three entries present. A check that happens to catch only the
    first passes against a map and proves nothing."""
    request = a_request()
    plaintext = [
        "sk-chat-aaaaaaaaaaaaaaaa",
        "sk-embed-bbbbbbbbbbbbbbbb",
        "nvapi-cccccccccccccccc",
    ]
    renderings = [
        repr(request),
        str(request),
        str(request.model_dump()),
        request.model_dump_json(),
        repr(request.provider_credentials),
        str(request.provider_credentials),
    ]
    for rendering in renderings:
        for secret in plaintext:
            assert secret not in rendering
    assert request.model_dump_json().count("**********") == len(plaintext)


def test_get_secret_value_is_the_only_way_out_and_it_is_per_entry() -> None:
    secret = credential_for(a_request(), EMBED_CONNECTION, surface="embedding")
    assert isinstance(secret, SecretStr)
    assert secret.get_secret_value() == "sk-embed-bbbbbbbbbbbbbbbb"


def test_a_missing_credential_is_a_refusal_and_never_a_fallback_to_the_chat_key() -> None:
    """Falling back would send one tenant's key to a vendor they did not choose for that
    surface and would embed the question in the wrong vector space."""
    request = a_request(
        provider_credentials={CHAT_CONNECTION: "sk-chat-aaaaaaaaaaaaaaaa"},
    )
    with pytest.raises(KbError) as caught:
        credential_for(request, EMBED_CONNECTION, surface="embedding")
    assert caught.value.error_class is ErrorClass.VALIDATION
    assert "sk-chat" not in caught.value.message


def test_no_secret_is_reachable_from_the_configuration_snapshot() -> None:
    """Walks nested models **and container arguments**, because the credential now hides
    inside a ``dict`` value and a walk that only inspects top-level annotations would miss it."""

    def reachable(annotation: Any, seen: set[Any]) -> bool:
        if annotation is SecretStr:
            return True
        if annotation in seen:
            return False
        seen.add(annotation)
        fields = getattr(annotation, "model_fields", None)
        if fields is not None:
            return any(reachable(field.annotation, seen) for field in fields.values())
        return any(reachable(arg, seen) for arg in getattr(annotation, "__args__", ()))

    assert not reachable(ConfigSnapshot, set())
    assert reachable(ChatExecuteRequest, set())


def test_rotating_one_credential_moves_neither_the_snapshot_hash_nor_the_snapshot() -> None:
    """ADR-011 property 1. Hash the credential in and every rotation invalidates every cached
    answer and stops every replayed job reproducing byte-identically."""
    before = a_request()
    after = a_request(
        provider_credentials={
            CHAT_CONNECTION: "sk-chat-ROTATED-zzzzzzzz",
            EMBED_CONNECTION: "sk-embed-bbbbbbbbbbbbbbbb",
            RERANK_CONNECTION: "nvapi-cccccccccccccccc",
        }
    )
    assert snapshot_hash(before) == snapshot_hash(after)
    assert before.config == after.config
    assert hash(before.config) == hash(after.config)


def test_an_unused_optional_snapshot_field_does_not_move_the_hash_for_a_body_that_omits_it() -> (
    None
):
    """The idempotency-fingerprint rule, from the other side: a snapshot built from a body that
    omits a defaulted field must hash the same as one that states the default."""
    stated = ChatExecuteRequest.model_validate(body(config=snapshot(stream=True)))
    omitted = a_request()
    assert snapshot_hash(stated) == snapshot_hash(omitted)


# ── hashability ───────────────────────────────────────────────────────────────


def test_the_snapshot_is_hashable_and_the_request_deliberately_is_not() -> None:
    """The snapshot is the correct per-request cache key. Hashing the request would pull the
    credentials in, which is the exact mistake ``snapshot_hash`` exists to prevent."""
    request = a_request()
    assert isinstance(hash(request.config), int)
    with pytest.raises(TypeError, match="unhashable"):
        hash(request)


def test_no_snapshot_field_is_a_dict_or_a_list() -> None:
    """The property that keeps the snapshot hashable, asserted as the rule rather than as one
    instance: a ``list`` field added later removes hashability with nothing to notice."""
    for name, field in ConfigSnapshot.model_fields.items():
        origin = getattr(field.annotation, "__origin__", None)
        assert origin not in (dict, list), name


# ── scope ─────────────────────────────────────────────────────────────────────


def test_the_body_tenant_fields_are_checked_against_the_verified_headers() -> None:
    request = a_request()
    assert_scope_agrees(request, org_id=request.org_id, bot_id=request.bot_id)
    with pytest.raises(KbError) as caught:
        assert_scope_agrees(request, org_id="01J8A4QK7QK7QK7QK7QK7QK7ZZ", bot_id=request.bot_id)
    assert caught.value.error_class is ErrorClass.VALIDATION


def test_a_missing_bot_in_the_headers_disagrees_with_a_body_that_names_one() -> None:
    request = a_request()
    with pytest.raises(KbError):
        assert_scope_agrees(request, org_id=request.org_id, bot_id=None)


# ── parse_request's redaction ─────────────────────────────────────────────────


def test_parse_request_never_renders_the_rejected_input() -> None:
    """``errors()`` defaults to ``include_input=True``, so the rejected value — the snapshot,
    the tenant's question, the key — is rendered verbatim unless it is turned off."""
    raw = json.dumps(body(query="")).encode()
    with pytest.raises(KbError) as caught:
        parse_request(raw)
    assert caught.value.error_class is ErrorClass.VALIDATION
    for secret in ("sk-chat-aaaaaaaaaaaaaaaa", "sk-embed-bbbbbbbbbbbbbbbb"):
        assert secret not in caught.value.message


# ── the outbound union ────────────────────────────────────────────────────────


def test_the_nine_names_are_the_contract_and_the_six_forwarded_are_a_subset() -> None:
    assert len(EVENT_NAMES) == 9
    assert set(CLIENT_FORWARDED_EVENTS) | set(INTERNAL_ONLY_EVENTS) == set(EVENT_NAMES)
    assert not set(CLIENT_FORWARDED_EVENTS) & set(INTERNAL_ONLY_EVENTS)


def test_a_bad_discriminator_yields_exactly_one_union_tag_invalid() -> None:
    """An untagged union validates every member and reports every member's failure; during an
    incident the error names the wrong variant."""
    with pytest.raises(ValidationError) as caught:
        STREAM_EVENT.validate_python({"event": "token.v2", "text": "x"})
    errors = caught.value.errors()
    assert len(errors) == 1
    assert errors[0]["type"] == "union_tag_invalid"


def test_the_client_facing_frames_carry_the_published_key_sets() -> None:
    """The names are ``kb-internal-api-contracts``' and are mirrored in
    ``packages/contracts/src/sse/events.ts``; drift here is a client outage, and nothing else
    catches it — ``extra="forbid"`` only rejects keys coming *in*, and Laravel re-spells
    nothing."""
    assert json.loads(
        sse_data(
            MessageStart(
                message_id="01J" + "0" * 23,
                conversation_id="01J" + "1" * 23,
                created_at="2026-08-04T09:15:02Z",
            )
        )
    ).keys() == {"message_id", "conversation_id", "created_at"}
    assert json.loads(sse_data(Status(stage="retrieving"))).keys() == {"stage"}
    assert json.loads(sse_data(Token(text="Refunds are "))).keys() == {"text"}
    assert json.loads(
        sse_data(
            MessageComplete(
                message_id="01J" + "0" * 23,
                finish_reason="stop",
                usage=Usage(prompt_tokens=1841, completion_tokens=96),
            )
        )
    ) == {
        "message_id": "01J" + "0" * 23,
        "finish_reason": "stop",
        "usage": {"prompt_tokens": 1841, "completion_tokens": 96},
    }
    assert json.loads(
        sse_data(StreamError(error_class="provider_rate_limit", message="x", retryable=True))
    ).keys() == {"error_class", "message", "retryable"}
    citation = Citation(
        index=1,
        source_id="01J" + "2" * 23,
        source_version_id="01J" + "3" * 23,
        chunk_id="01J" + "4" * 23,
        title="Refund policy",
        url=None,
        score=0.83,
    )
    assert json.loads(sse_data(Citations(citations=[citation])))["citations"][0].keys() == {
        "index",
        "source_id",
        "source_version_id",
        "chunk_id",
        "title",
        "url",
        "score",
    }


def test_a_nested_usage_never_ships_an_internal_event_name_inside_a_terminal_frame() -> None:
    """The mirror-image leak: a ``Usage`` that inherited ``Event`` would put
    ``event: "provider.usage"`` inside ``message.complete``, shipping an internal name to every
    widget."""
    payload = json.loads(
        sse_data(
            MessageComplete(
                message_id="01J" + "0" * 23,
                finish_reason="stop",
                usage=Usage(prompt_tokens=1, completion_tokens=2),
            )
        )
    )
    assert "event" not in payload
    assert "event" not in payload["usage"]
    assert "cached_tokens" not in json.dumps(payload)


def test_the_discriminator_is_off_the_wire_payload_and_still_on_the_model() -> None:
    """``sse_data`` drops it because it is already on the frame's own ``event:`` line and no
    client type has a key for it — and a consumer puts it back to validate the union."""
    frame = Token(text="hi")
    assert json.loads(sse_data(frame)) == {"text": "hi"}
    rebuilt = STREAM_EVENT.validate_python(
        {**json.loads(sse_data(frame)), "event": frame.wire_name}
    )
    assert rebuilt == frame


def test_provider_connection_carries_no_credential_field() -> None:
    """ADR-011: moving it back inside the snapshot is the reflexive tidy-up."""
    banned = ("api_key", "credential", "secret", "token", "password")
    assert not [
        name for name in ProviderConnection.model_fields for word in banned if word in name.lower()
    ]
