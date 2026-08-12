"""``ModelCapabilities`` is an inbound contract model, and JSON cannot express half of it.

The row mirrors ``provider_models`` (docs/11 §16.2) and **arrives from Laravel** — inside the
configuration snapshot on a chat request, and inside every candidate on
``POST /internal/v1/embedding/readiness``. So `pydantic-contracts` applies to it in full, and
two of its four fields sit on the fault line that skill names: ``supported`` is a
``frozenset`` of an enum, and JSON has neither type.

Three properties are pinned here, and each of them fails silently in a different direction:

* ``extra="forbid"`` — a renamed cap key must 422, not evaporate.
* ``strict=True`` on the scalars — ``"8192"`` must not become ``8192``.
* the ``supported`` exemption — an array of strings must still validate, or every real
  request 422s while ``model_validate_json`` in a unit test keeps passing.

Everything here validates a **Python dict**, never ``model_validate_json``, because that is
what FastAPI actually hands the model: it parses the body itself. Validating the bytes instead
is the exact substitution that makes this whole class of bug invisible.
"""

from __future__ import annotations

import pytest
from pydantic import ValidationError

from app.providers.contract import Capability, ModelCapabilities

#: The shape ``EmbeddingCandidate::toArray()`` emits, written out rather than dumped from the
#: model, so this file cannot assert that the model agrees with itself.
WIRE_ROW = {
    "supported": ["embedding"],
    "context_window": 8192,
    "max_output_tokens": 0,
    "on_unsupported": "reject",
}

#: A chat row, and the only fixture here with more than one flag. Everything about ordering
#: needs it: an *embedding* row can never carry a second flag (``assert_row_coherent`` refuses
#: a row that claims two task families), so a one-element set is sorted by accident and proves
#: nothing about the serializer.
MULTI_FLAG_ROW = {
    "supported": ["tool_use", "text", "structured_output", "image_input"],
    "context_window": 200_000,
    "max_output_tokens": 8192,
    "on_unsupported": "reject",
}


# ── the wire form validates at all ───────────────────────────────────────────


def test_an_array_of_strings_is_accepted_as_the_flag_set() -> None:
    """The wire form of ``frozenset[Capability]``.

    Under a bare ``strict=True`` this fails twice — ``frozen_set_type`` on the array and then
    ``is_instance_of`` on each member — and it fails only through HTTP, because
    ``model_validate_json`` succeeds on the same bytes. That asymmetry is why the exemption is
    declared on the field and why this assertion is written against a dict.
    """
    row = ModelCapabilities.model_validate(WIRE_ROW)
    assert row.supported == frozenset({Capability.EMBEDDING})


def test_the_flag_exemption_did_not_leak_onto_the_scalars() -> None:
    """The exemption is scoped to one field. Dropping ``strict`` model-wide to fix the array
    would silence coercion on every number on the row, and a row whose meaning changed during
    parsing makes ``X-KB-Config-Version``'s claim that both planes hold the same snapshot false
    without moving the version."""
    for field in ("context_window", "max_output_tokens"):
        with pytest.raises(ValidationError) as caught:
            ModelCapabilities.model_validate({**WIRE_ROW, field: "8192"})
        assert caught.value.errors()[0]["type"] == "int_type"


def test_an_unknown_flag_is_still_refused() -> None:
    """Lax on the *shape* of the collection is not lax on its *members*.

    A flag is never inferred from a model id and never invented; an unrecognised one means the
    control plane and this enum disagree about what a capability is, which is a three-part
    change half-shipped rather than a value to shrug at.
    """
    with pytest.raises(ValidationError) as caught:
        ModelCapabilities.model_validate({**WIRE_ROW, "supported": ["embeddings"]})
    assert caught.value.errors()[0]["type"] == "enum"


def test_a_bare_string_is_not_a_one_element_flag_set() -> None:
    """``"embedding"`` is not ``["embedding"]``. Lax mode does not treat a string as a sequence
    of members, so this stays a refusal — which matters because the alternative reading turns
    the string into a set of nine single characters."""
    with pytest.raises(ValidationError) as caught:
        ModelCapabilities.model_validate({**WIRE_ROW, "supported": "embedding"})
    assert caught.value.errors()[0]["type"] == "frozen_set_type"


def test_an_unknown_key_on_the_row_is_refused() -> None:
    """``extra="forbid"``: a field Laravel renames must fail here rather than evaporate.

    Without it a mis-spelled ``capability_flags`` leaves ``supported`` at whatever was parsed
    and the connection silently stops being embedding-eligible — the operator is then told to
    fix a connection that was never broken, which is finding C1 one layer lower.
    """
    with pytest.raises(ValidationError) as caught:
        ModelCapabilities.model_validate({**WIRE_ROW, "capability_flags": ["embedding"]})
    assert caught.value.errors()[0]["type"] == "extra_forbidden"


# ── the two serialization modes do different jobs ────────────────────────────


def test_json_mode_renders_the_flags_sorted() -> None:
    """A set has no order, so without this the capability chips on an admin screen shuffle
    between two loads of an unchanged configuration, and no response body at the seam can be
    compared byte for byte."""
    rendered = ModelCapabilities.model_validate(MULTI_FLAG_ROW).model_dump(mode="json")

    assert rendered["supported"] == sorted(MULTI_FLAG_ROW["supported"])
    assert rendered["supported"] != MULTI_FLAG_ROW["supported"], (
        "the fixture must not already be in sorted order, or this test passes without sorting"
    )


def test_python_mode_still_yields_a_frozenset() -> None:
    """The other half of ``when_used="json"``, and the reason it is not unconditional.

    A Python-mode dump is a faithful Python representation, and in-process readers treat it as
    one: ``ModelCapabilities(**row.model_dump())`` is a round trip and a row-to-row comparison
    depends on it. An unconditional serializer makes it lossy about both the container type
    and the enum members — and lossy silently, because a ``list[str]`` simply compares unequal
    to a ``frozenset[Capability]`` at whichever site happens to compare. It does **not** show
    up as a failing response: FastAPI 0.141.1's ``serialize_response`` validates the returned
    model instance rather than a dump of it, so nothing at the endpoint would notice.
    """
    dumped = ModelCapabilities.model_validate(MULTI_FLAG_ROW).model_dump()

    assert isinstance(dumped["supported"], frozenset)
    assert dumped["supported"] == frozenset(Capability(f) for f in MULTI_FLAG_ROW["supported"])
