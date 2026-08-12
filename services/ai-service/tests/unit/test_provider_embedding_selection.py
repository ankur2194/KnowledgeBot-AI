"""Finding C1: which of an organization's connections supplies the embedding credential.

Two failures are being pinned here and they pull in opposite directions, which is why this
file exists rather than three more cases in ``test_provider_capability_matrix.py``:

* **An organization that cannot embed must find out loudly and early.** Ingestion is not
  degraded without embeddings, it is impossible — every chunk is embedded before it is
  indexed and there is no fused-order-instead path the way there is for reranking. The
  failure before this module was an ``AttributeError`` or a ``None`` callable somewhere below
  ``app/ingestion/``, after the parse and the OCR had already been paid for.
* **An organization that CAN embed must resolve the same connection everywhere.** An index
  written under one model and queried under another returns plausible neighbours that are
  simply wrong: cosine distance is defined between any two vectors of equal width, so nothing
  raises, no metric moves, and the only symptom is answer quality. That is why the ambiguous
  case refuses instead of picking, and why ``assert_selection_serves_space`` exists at all.

Nothing here touches a network and nothing here needs a credential — selection answers *which*
connection, never *with what key*, and ``test_no_model_in_this_module_can_carry_a_credential``
is what keeps it that way.
"""

from __future__ import annotations

import itertools

import pytest
from pydantic import SecretStr, ValidationError

from app.core.errors import FALLBACK_ELIGIBLE, RETRYABLE, ErrorClass, KbError, status_for
from app.providers.capabilities import providers_offering
from app.providers.contract import Capability, ModelCapabilities
from app.providers.embedding_selection import (
    EmbeddingConnection,
    EmbeddingDesignation,
    EmbeddingIneligibility,
    EmbeddingReadiness,
    assert_org_can_embed,
    assert_selection_serves_space,
    embedding_readiness,
    ineligibility,
    resolve_embedding_connection,
)
from app.providers.errors import ProviderSurface
from app.retrieval.collection import EmbeddingSpace


def _ulid(n: int) -> str:
    """A valid, deterministic, lexicographically ordered ULID for a fixture connection.

    Real ULIDs sort by creation time, which is what the tiebreak in step 5 of the selection
    rule leans on; these sort by ``n`` so a test can say "the older connection" and mean it.
    """
    return f"01J8ZQ9K7N000000000000{n:04d}"


def _embedding_row(*extra: Capability) -> ModelCapabilities:
    """A ``provider_models`` row for an embedding model.

    ``max_output_tokens=0`` deliberately: nothing is generated on this surface, and a row that
    carried a plausible chat-shaped ceiling would read as a chat row that also embeds — which
    ``capabilities.assert_row_coherent`` exists to refuse.
    """
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


#: The only sourced embedder in ``capabilities.PROVIDER_TASKS`` today, and the fixture every
#: "this works" case is built from. Read from the matrix rather than hardcoded would be nicer
#: still, but the *model* id has to be written down somewhere and this is the one place.
OPENAI_EMBED = ("openai", "text-embedding-3-large")


# ── the impossible organization ───────────────────────────────────────────────


def test_an_org_with_only_a_chat_provider_cannot_embed_and_says_so() -> None:
    """The C1 case, exactly: an Anthropic-only organization cannot ingest one document.

    Before this module nothing in the code said what happens. The assertion is not merely
    "it raises" — a bare failure here is a dead end for the operator, so the message has to
    name what is missing *and* what would fix it.
    """
    org = [_conn(1, "anthropic", "claude-opus-5", _chat_row())]

    with pytest.raises(KbError) as excinfo:
        resolve_embedding_connection(org)

    err = excinfo.value
    assert err.error_class is ErrorClass.VALIDATION
    assert err.retryable is False, "the next identical attempt fails identically"
    assert "cannot ingest" in err.message
    assert "anthropic" in err.message, "name the connection that was examined"
    assert _ulid(1) in err.message, "name WHICH connection, or the operator has to guess"
    assert EmbeddingIneligibility.VENDOR_HAS_NO_ENDPOINT.value in err.message


def test_the_refusal_names_every_vendor_that_could_serve_it() -> None:
    """ "No embedding provider" without saying which providers CAN embed is a dead end.

    Read from ``providers_offering`` rather than hardcoded, so a vendor gaining or losing an
    endpoint moves the message with the matrix instead of leaving a stale list in a string.
    """
    org = [_conn(1, "deepseek", "deepseek-v4-pro", _chat_row())]

    with pytest.raises(KbError) as excinfo:
        resolve_embedding_connection(org)

    message = excinfo.value.message
    capable = providers_offering(ProviderSurface.EMBEDDING)
    assert capable, "the fixture assumes at least one sourced embedder exists"
    for vendor in capable:
        assert vendor in message, f"the refusal must name {vendor} as a way out"
    assert "capability_flags.embedding" in message, "name the row change too, not just a vendor"


def test_an_org_with_no_connections_at_all_is_refused_the_same_way() -> None:
    """The empty case is not a special case — it is the same refusal with nothing examined."""
    with pytest.raises(KbError) as excinfo:
        resolve_embedding_connection([])
    assert excinfo.value.error_class is ErrorClass.VALIDATION
    assert "no provider connections at all" in excinfo.value.message


def test_a_row_carrying_the_flag_on_a_vendor_without_the_endpoint_is_refused() -> None:
    """Both axes, and the row axis alone is not enough.

    A ``provider_models`` row can be edited to claim ``capability_flags.embedding`` on
    Anthropic. Answering "yes" to that would send a request that 404s mid-ingest, after the
    parse and the OCR spend.
    """
    org = [_conn(1, "anthropic", "claude-embed-imaginary", _embedding_row())]
    with pytest.raises(KbError):
        resolve_embedding_connection(org)

    reason = ineligibility(org[0])
    assert reason is EmbeddingIneligibility.VENDOR_HAS_NO_ENDPOINT


def test_the_error_class_is_validation_and_carries_validation_s_policy() -> None:
    """Getting the class wrong makes clients retry something that can never succeed.

    ``validation`` is 422, never retried, never fallback-eligible. Asserted against the
    taxonomy tables rather than restated, so a change to either is a visible diff.
    """
    with pytest.raises(KbError) as excinfo:
        resolve_embedding_connection([_conn(1, "anthropic", "claude-opus-5", _chat_row())])

    cls = excinfo.value.error_class
    assert cls is ErrorClass.VALIDATION
    assert status_for(cls) == 422
    assert RETRYABLE[cls] is False
    assert FALLBACK_ELIGIBLE[cls] is False, (
        "falling back to another vendor for an embedding is a different vector space, and "
        "there is no vendor to fall back to when none was reachable in the first place"
    )


# ── the possible organization ─────────────────────────────────────────────────


def test_a_single_embedding_capable_connection_is_selected() -> None:
    provider, model = OPENAI_EMBED
    org = [
        _conn(1, "anthropic", "claude-opus-5", _chat_row()),
        _conn(2, provider, model, _embedding_row()),
    ]
    selected = resolve_embedding_connection(org)
    assert selected.space_key == (provider, model)
    assert selected.connection_id == _ulid(2)


def test_selection_is_a_function_of_the_set_and_not_of_the_order() -> None:
    """The indexer and the query path build their candidate lists independently.

    If the answer depended on iteration order, one could index under a connection the other
    never selects — and the two would agree on every test that happened to build the list the
    same way. Every permutation is checked, not a shuffled sample.
    """
    provider, model = OPENAI_EMBED
    org = [
        _conn(3, provider, model, _embedding_row()),
        _conn(1, "anthropic", "claude-opus-5", _chat_row()),
        _conn(2, provider, model, _embedding_row()),
    ]
    answers = {
        resolve_embedding_connection(list(order)).connection_id
        for order in itertools.permutations(org)
    }
    assert answers == {_ulid(2)}, "one answer, and it is the older of the two openai rows"


def test_duplicate_candidates_collapse_instead_of_becoming_ambiguous() -> None:
    """The same connection listed twice is one connection, not a disagreement."""
    provider, model = OPENAI_EMBED
    conn = _conn(1, provider, model, _embedding_row())
    readiness = embedding_readiness([conn, conn, conn])
    assert readiness.ready
    assert readiness.eligible == (conn,)


def test_two_connections_to_one_model_are_not_ambiguous() -> None:
    """Two credentials, one vector space. The tiebreak picks a payer, never a space."""
    provider, model = OPENAI_EMBED
    org = [
        _conn(5, provider, model, _embedding_row()),
        _conn(2, provider, model, _embedding_row()),
    ]
    assert resolve_embedding_connection(org).connection_id == _ulid(2)


# ── the ambiguous organization, which must refuse rather than guess ───────────


def test_two_embedding_models_refuse_rather_than_pick_one() -> None:
    """The tie that must never be broken by convention.

    ``(provider, model)`` IS the vector space — ``EmbeddingSpace`` derives the collection name
    from it. Picking "the first saved" or "the richest row" would let an unrelated connection
    edit move the space a corpus was indexed under, and nothing would raise.
    """
    org = [
        _conn(1, "openai", "text-embedding-3-large", _embedding_row()),
        _conn(2, "openai", "text-embedding-3-small", _embedding_row()),
    ]
    with pytest.raises(KbError) as excinfo:
        resolve_embedding_connection(org)

    message = excinfo.value.message
    assert excinfo.value.error_class is ErrorClass.VALIDATION
    assert "text-embedding-3-large" in message and "text-embedding-3-small" in message
    assert "Designate" in message, "the refusal has to say what closes it"


def test_a_designation_resolves_an_ambiguity_deterministically() -> None:
    org = [
        _conn(1, "openai", "text-embedding-3-large", _embedding_row()),
        _conn(2, "openai", "text-embedding-3-small", _embedding_row()),
    ]
    chosen = resolve_embedding_connection(
        org,
        designated=EmbeddingDesignation(connection_id=_ulid(2), model="text-embedding-3-small"),
    )
    assert chosen.space_key == ("openai", "text-embedding-3-small")


def test_a_designation_names_a_connection_and_also_a_model() -> None:
    """One connection can carry several embedding rows, so the connection alone is not enough.

    Designating only the connection would leave the space undecided — which is the same
    ambiguity one level down, and the reason ``EmbeddingDesignation`` is a pair.
    """
    org = [
        _conn(1, "openai", "text-embedding-3-large", _embedding_row()),
        _conn(1, "openai", "text-embedding-3-small", _embedding_row()),
    ]
    with pytest.raises(KbError):
        resolve_embedding_connection(org)

    for model in ("text-embedding-3-large", "text-embedding-3-small"):
        chosen = resolve_embedding_connection(
            org, designated=EmbeddingDesignation(connection_id=_ulid(1), model=model)
        )
        assert chosen.model == model


def test_a_designation_is_never_silently_substituted() -> None:
    """A designation that cannot be honoured fails; it does not fall through to a neighbour.

    Substituting is the exact failure this module prevents: it would embed through a different
    model than the one the organization designated, at no error, into a collection named for
    the wrong space.
    """
    provider, model = OPENAI_EMBED
    org = [_conn(1, provider, model, _embedding_row())]

    with pytest.raises(KbError) as excinfo:
        resolve_embedding_connection(
            org, designated=EmbeddingDesignation(connection_id=_ulid(9), model=model)
        )
    assert "not among this organization's connections" in excinfo.value.message
    assert "never substituted" in excinfo.value.message


def test_a_designation_pointing_at_an_ineligible_connection_reports_the_reason() -> None:
    org = [
        _conn(1, "anthropic", "claude-opus-5", _chat_row()),
        _conn(2, *OPENAI_EMBED, _embedding_row()),
    ]
    with pytest.raises(KbError) as excinfo:
        resolve_embedding_connection(
            org,
            designated=EmbeddingDesignation(connection_id=_ulid(1), model="claude-opus-5"),
        )
    message = excinfo.value.message
    assert EmbeddingIneligibility.VENDOR_HAS_NO_ENDPOINT.value in message
    assert "not substituted" in message


# ── the reasons, and their totality ───────────────────────────────────────────


def test_every_ineligibility_reason_is_reachable() -> None:
    """A closed enum with an unreachable member is documentation pretending to be a branch."""
    provider, model = OPENAI_EMBED
    cases = {
        EmbeddingIneligibility.VENDOR_HAS_NO_ENDPOINT: _conn(
            1, "deepseek", "deepseek-embed-imaginary", _embedding_row()
        ),
        EmbeddingIneligibility.ROW_LACKS_EMBEDDING_FLAG: _conn(
            2, provider, "gpt-5.6-sol", _chat_row()
        ),
        EmbeddingIneligibility.ROW_INCOHERENT: _conn(
            3, provider, model, _embedding_row(Capability.TEXT)
        ),
    }
    for expected, connection in cases.items():
        assert ineligibility(connection) is expected
    assert set(cases) == set(EmbeddingIneligibility)


def test_an_incoherent_row_is_rejected_rather_than_selected() -> None:
    """A row claiming embedding AND chat describes a model that does not exist.

    ``capabilities.assert_row_coherent`` refuses it at save time; here the same disagreement
    has to become a value, because this path also runs on a request.
    """
    provider, model = OPENAI_EMBED
    org = [_conn(1, provider, model, _embedding_row(Capability.TEXT))]
    with pytest.raises(KbError) as excinfo:
        resolve_embedding_connection(org)
    assert EmbeddingIneligibility.ROW_INCOHERENT.value in excinfo.value.message


def test_ineligibility_is_total_on_an_unknown_vendor() -> None:
    """Fail closed on a provider name that is not one of the five, and never raise."""
    conn = _conn(1, "cohere", "embed-v4", _embedding_row())
    assert ineligibility(conn) is EmbeddingIneligibility.VENDOR_HAS_NO_ENDPOINT


def test_readiness_never_raises_on_any_of_these_configurations() -> None:
    """The non-raising half is what a connection screen calls to draw a banner.

    It must be total: an exception here would force the control plane to catch an error in
    order to render a page, and the reflexive fix for that is a second, looser rule.
    """
    provider, model = OPENAI_EMBED
    configurations = [
        [],
        [_conn(1, "anthropic", "claude-opus-5", _chat_row())],
        [_conn(1, provider, model, _embedding_row())],
        [
            _conn(1, "openai", "text-embedding-3-large", _embedding_row()),
            _conn(2, "openai", "text-embedding-3-small", _embedding_row()),
        ],
        [_conn(1, "cohere", "embed-v4", _embedding_row())],
        [_conn(1, provider, model, _embedding_row(Capability.RERANK))],
    ]
    for org in configurations:
        readiness = embedding_readiness(org)
        assert isinstance(readiness, EmbeddingReadiness)
        assert readiness.ready == (readiness.selected is not None)
        assert bool(readiness.explanation) != readiness.ready


def test_readiness_and_resolution_are_one_computation() -> None:
    """The banner and the upload must never disagree about whether an org can ingest.

    Two rules would mean an operator sees a green connection screen and a failed upload, or
    the reverse — and the second is worse, because it is the one that gets "fixed" by relaxing
    the upload check.
    """
    provider, model = OPENAI_EMBED
    configurations = [
        [],
        [_conn(1, "anthropic", "claude-opus-5", _chat_row())],
        [_conn(1, provider, model, _embedding_row())],
        [
            _conn(1, "openai", "text-embedding-3-large", _embedding_row()),
            _conn(2, "openai", "text-embedding-3-small", _embedding_row()),
        ],
    ]
    for org in configurations:
        readiness = embedding_readiness(org)
        if readiness.ready:
            assert resolve_embedding_connection(org) == readiness.selected
            assert_org_can_embed(org)
        else:
            with pytest.raises(KbError) as excinfo:
                resolve_embedding_connection(org)
            assert excinfo.value.message == readiness.explanation
            with pytest.raises(KbError):
                assert_org_can_embed(org)


def test_a_readiness_cannot_carry_a_verdict_and_an_excuse() -> None:
    """Otherwise a caller could draw the banner and embed anyway."""
    provider, model = OPENAI_EMBED
    with pytest.raises(ValidationError):
        EmbeddingReadiness(
            selected=_conn(1, provider, model, _embedding_row()), explanation="but also no"
        )
    with pytest.raises(ValidationError):
        EmbeddingReadiness(selected=None)


# ── the indexer and the reader must agree with the CORPUS, not just with each other ──


def test_a_selection_that_does_not_serve_the_indexed_space_is_refused() -> None:
    """Resolution agreeing with itself is not enough; it has to agree with what was written.

    An organization that swaps its embedding model has a corpus in the old space. Embedding
    the question with the new model returns plausible neighbours and raises nothing — this is
    the check that turns that into an error.
    """
    indexed = EmbeddingSpace(provider="openai", model="text-embedding-3-large", dimensions=3072)
    now = _conn(1, "openai", "text-embedding-3-small", _embedding_row())

    with pytest.raises(KbError) as excinfo:
        assert_selection_serves_space(now, indexed)

    assert excinfo.value.error_class is ErrorClass.VALIDATION
    assert indexed.collection in excinfo.value.message
    assert "reindex" in excinfo.value.message


def test_a_selection_that_serves_the_indexed_space_passes_at_any_width() -> None:
    """Width is deliberately not compared: it is read off the provider's actual response.

    ``collection.assert_dimensions`` checks it at the point where the number exists. Comparing
    it here would mean inventing one, which is the registry-row-says-1024 failure.
    """
    conn = _conn(1, *OPENAI_EMBED, _embedding_row())
    for width in (256, 1024, 3072):
        space = EmbeddingSpace(provider="openai", model="text-embedding-3-large", dimensions=width)
        assert_selection_serves_space(conn, space)


def test_the_resolved_connection_and_the_space_it_names_agree_by_construction() -> None:
    """The happy path of the pair: resolve, then check against a space built from the result."""
    org = [
        _conn(1, "anthropic", "claude-opus-5", _chat_row()),
        _conn(2, *OPENAI_EMBED, _embedding_row()),
    ]
    selected = resolve_embedding_connection(org)
    space = EmbeddingSpace(provider=selected.provider, model=selected.model, dimensions=3072)
    assert_selection_serves_space(selected, space)


# ── the credential is not an input here, and cannot become one ────────────────


def test_no_model_in_this_module_can_carry_a_credential() -> None:
    """Selection answers *which* connection, never *with what key*.

    The reflexive edit is to attach the decrypted key "so the caller does not have to look it
    up twice", at which point it rides into a Celery payload, a span, and a failed-job record.
    Both gates are asserted: no secret-looking field name, and no ``SecretStr`` annotation.
    """
    from app.providers import embedding_selection

    models = (
        embedding_selection.EmbeddingConnection,
        embedding_selection.EmbeddingDesignation,
        embedding_selection.Rejection,
        embedding_selection.EmbeddingReadiness,
    )
    banned = ("credential", "api_key", "secret", "token", "password", "key")
    for model in models:
        for name, field in model.model_fields.items():
            assert not any(bad in name.lower() for bad in banned), f"{model.__name__}.{name}"
            assert field.annotation is not SecretStr, f"{model.__name__}.{name}"


def test_a_connection_rejects_an_attached_credential_outright() -> None:
    """``extra="forbid"`` is the runtime half of the same rule."""
    provider, model = OPENAI_EMBED
    with pytest.raises(ValidationError):
        EmbeddingConnection(
            connection_id=_ulid(1),
            provider=provider,
            model=model,
            caps=_embedding_row(),
            provider_credential="sk-not-a-real-key",  # type: ignore[call-arg]
        )


def test_a_connection_id_must_be_a_ulid() -> None:
    """Ids on this platform are ULIDs, and the tiebreak leans on their ordering."""
    provider, model = OPENAI_EMBED
    with pytest.raises(ValidationError):
        EmbeddingConnection(
            connection_id="not-a-ulid", provider=provider, model=model, caps=_embedding_row()
        )
