"""Embedding identity, and the one property the switch to an API endpoint took away.

A locally pinned model made `embedding_model_version` stable by construction: a commit sha,
resolved to a path on disk, unable to change under a running worker. A vendor model id is an
alias, and an alias can be re-pointed at different weights with no diff in this repository, no
error at the call site, and no metric anywhere. Two spaces then share one collection: cosine
distance is defined between any two vectors of equal width, so nothing raises and only the
ranking of the older half moves.

Nothing in this file can prove a vendor did not swap weights. What it can hold in place is the
*shape* of the detection: that the identity is composed from four measured parts rather than
one declared one, that each part is actually inside the string that reaches the chunk and the
ingest key, and that the pieces which used to pin a local model have not quietly reappeared.

Imports the constants and nothing else — no fixtures, no containers, no app factory. An
identity check that needs a running provider stops running on the day the provider is down,
which is the day it is needed.
"""

from __future__ import annotations

import ast
import inspect
from collections.abc import Iterator, Sequence
from dataclasses import replace
from pathlib import Path
from typing import Final

import pytest

from app.core.errors import ErrorClass, KbError, Origin
from app.ingestion.chunking.chunker import CHUNK_METADATA_FIELDS, MAX_TOKENS
from app.ingestion.embedding.embedder import (
    CANARY_CONFIRMATIONS,
    CANARY_DIGEST_CHARS,
    CANARY_INPUT_TYPE,
    CANARY_PRECISION,
    CANARY_TEXTS,
    IDENTITY_SCHEME,
    MAX_BATCH_TEXTS,
    PROVIDER_TRUNCATION_POLICY,
    TOKEN_HEADROOM,
    CanaryVerdict,
    EmbedCallable,
    EmbeddingModelIdentity,
    canary_digest,
    check_window,
    classify_canary,
    enforce_canary,
    resolve_identity,
)
from app.ingestion.identity import INGEST_KEY_PARTS
from app.providers.contract import (
    Diagnostics,
    EmbeddingInputType,
    EmbeddingResult,
    Usage,
)
from app.retrieval.collection import EmbeddingSpace
from tests.support.tree import APP_ROOT


def _identity(*, dimensions: int = 3072, digest: str = "9f2a1c4e77b1") -> EmbeddingModelIdentity:
    return EmbeddingModelIdentity(
        space=EmbeddingSpace(
            provider="openai", model="text-embedding-3-large", dimensions=dimensions
        ),
        canary_digest=digest,
    )


# ── the identity object ──────────────────────────────────────────────────────


def test_identity_carries_four_measured_parts() -> None:
    """Provider, model, returned width, probe digest — and no fifth thing.

    Each of the four is load-bearing and each has its own way of failing if dropped. Provider
    and model together are the only human-readable half; `dimensions` is what makes a
    Matryoshka width change a different space rather than the same model; `canary_digest` is
    the only signal available when the vendor changes weights behind the alias, because the
    served-model string it echoes back *is* the alias.

    Three of the four are reached through `space` rather than restated here, and that nesting
    is the point rather than an accident: `EmbeddingSpace` is what derives the collection name,
    so a second flat copy of provider/model/width would let the indexer and the reader compute
    different names from the same facts — which reads as an empty corpus, not as an error. The
    digest is the one part deliberately left OUT of the space, because a digest inside it would
    put a vendor blip into the collection name and silently open a second collection.
    """
    assert set(EmbeddingModelIdentity.__dataclass_fields__) == {"space", "canary_digest"}
    assert {"provider", "model", "dimensions"} <= set(EmbeddingSpace.__dataclass_fields__)
    assert "canary_digest" not in EmbeddingSpace.__dataclass_fields__


def test_identity_is_frozen() -> None:
    """It is folded into the ingest key. An identity that can be mutated after the key is
    composed produces points whose payload disagrees with the key that admitted them, and the
    disagreement is invisible: both values are plausible strings.

    Both levels are checked. Freezing only the outer object would leave the model id mutable
    through `identity.space.model`, which is the same defect one dereference further down.
    """
    identity = _identity(digest="a" * 12)
    with pytest.raises((AttributeError, TypeError)):
        identity.canary_digest = "b" * 12  # type: ignore[misc]
    with pytest.raises((AttributeError, TypeError)):
        identity.space.model = "text-embedding-3-small"  # type: ignore[misc]


def test_a_bare_model_alias_is_not_an_identity() -> None:
    """The regression this whole file exists for.

    Two identities that differ only in the digest — the exact shape of a silent vendor weight
    swap — must not be equal. If someone "simplifies" the identity down to provider plus model
    id, this is the assertion that fails, and it fails before any vector is written rather than
    six months later as ranking nobody can explain.

    Note the two `space` values ARE equal, and so name the same collection. That is correct and
    is the whole reason the digest sits outside the space: drift must be loud without being
    self-repairing.
    """
    before = _identity(digest="9f2a1c4e77b1")
    after = _identity(digest="0b41dd90e6c3")
    assert before != after
    assert before.space == after.space


def test_a_width_change_is_not_the_same_identity() -> None:
    """A `dimensions` request parameter truncates the vector (Matryoshka) and returns 200. The
    same model at two widths is two spaces, and the collection is created at one width."""
    wide = _identity(dimensions=3072)
    narrow = _identity(dimensions=1024)
    assert wide != narrow
    assert wide.space.collection != narrow.space.collection


# ── THE invariant: the digest must never name the collection ─────────────────


def test_the_canary_digest_is_not_in_the_collection_name() -> None:
    """The single most important assertion in this file, and the one most likely to be broken by
    someone trying to be helpful.

    Putting the digest in the collection name looks like an improvement — vectors from two
    different weight sets would land in two different collections, "automatically". What it
    actually produces is the failure with no symptom: a vendor blip splits the corpus across two
    collections, the new one bootstraps cleanly, every count is correct in both, and answers are
    drawn from whichever half happens to contain the match. The repair looks exactly like a
    healthy first ingest.

    So the space names the collection and the digest sits outside it, deliberately: drift must be
    **loud** and must never be self-repairing. Checked three ways, because the digest could leak
    in through the name, through the field list, or through the digest input.
    """
    digest = "beefbeefbeef"
    identity = _identity(digest=digest)

    assert digest not in identity.space.collection
    assert "canary_digest" not in EmbeddingSpace.__dataclass_fields__

    # And the name must not move when only the digest does — a leak through the blake2b input
    # would be invisible to both checks above.
    assert (
        _identity(digest="0" * 12).space.collection == _identity(digest="f" * 12).space.collection
    )


def test_the_collection_name_still_moves_for_everything_that_is_a_space() -> None:
    """The other half of the same invariant, and a live regression risk: `EmbeddingSpace` gained
    `sparse_analyzer` when the lexical arm landed, and it is folded into the name because two
    analyzers in one collection do not error either — term ids from another analyzer are legal
    integers that match no posting. A field added to the space and left out of the digest is the
    same silent corpus-mixing failure the digest-in-the-name change would cause, in reverse."""
    base = EmbeddingSpace(provider="openai", model="text-embedding-3-large", dimensions=3072)
    names = {
        base.collection,
        replace(base, provider="nvidia").collection,
        replace(base, model="text-embedding-3-small").collection,
        replace(base, dimensions=1024).collection,
        replace(base, distance="Dot").collection,
        replace(base, schema_version=2).collection,
        replace(base, sparse_analyzer="bm25/v2").collection,
    }
    assert len(names) == 7, sorted(names)


# ── the probe set ────────────────────────────────────────────────────────────


def test_canary_texts_are_fixed_distinct_and_ours() -> None:
    """The probe is a fingerprint input, not a test fixture.

    Editing it re-identifies the entire corpus, because the digest is in the ingest key. Three
    at minimum because one string is one point on a manifold and a re-quantization can leave a
    single vector unmoved; distinct because a duplicate probe adds a byte to the digest and no
    information to the detector.
    """
    assert len(CANARY_TEXTS) >= 3
    assert len(set(CANARY_TEXTS)) == len(CANARY_TEXTS)
    assert all(text.strip() for text in CANARY_TEXTS)


def test_probe_precision_and_confirmation_bracket_vendor_noise() -> None:
    """Embedding APIs are not bit-reproducible across their own fleet, so a digest over raw
    floats changes on *their* deploy and re-versions our corpus for nothing; a digest over too
    few places cannot see a re-trained model.

    Neither number has been measured against a live provider yet, which is why the confirmation
    count exists at all: a single differing probe is a warning, not a reindex.
    """
    assert 0 < CANARY_PRECISION < 8
    assert CANARY_CONFIRMATIONS >= 2


# ── truncation, moved from the library to the network ────────────────────────


def test_truncation_is_refused_not_configured() -> None:
    """FlagEmbedding trimmed at 512 tokens silently; several vendors offer to do the same on
    request, and at least one defaults to it. We want the 400.

    A request that silently fits is a chunk indexed from its first N tokens, with its tail
    unsearchable forever, reported as a successful run with an unchanged chunk total.
    """
    assert PROVIDER_TRUNCATION_POLICY == "reject"


def test_the_window_check_leaves_headroom_over_the_chunk_budget() -> None:
    """The provider tokenizes with its own algorithm over its own vocabulary, and the gap
    between its total and our estimate is widest on non-Latin scripts — where a silent trim is
    least likely to be noticed. The headroom is that gap plus the sentinel pair."""
    assert TOKEN_HEADROOM > 0
    assert MAX_TOKENS + TOKEN_HEADROOM > MAX_TOKENS


def test_batches_are_bounded() -> None:
    """The batch is the checkpoint and the redelivery unit. Unbounded, one redelivery re-bills
    a whole version's text."""
    assert 0 < MAX_BATCH_TEXTS <= 2048


# ── the two fields the identity string reaches ───────────────────────────────


def test_the_ingest_key_still_covers_the_embedding_model() -> None:
    """A content-only key dedupes straight through a model change — the request matches the
    completed run, the admin sees "already processed", and the new vectors are never made.

    This part matters more now than it did, not less: with a local pin, a model change was an
    edit somebody made deliberately. With an alias it can happen without anyone here acting.
    """
    assert "embedding_model_version" in INGEST_KEY_PARTS
    parts = list(INGEST_KEY_PARTS)
    assert parts.index("embedding_model_version") > parts.index("content_hash")


def test_the_chunk_schema_carries_the_embedding_identity_and_is_still_thirty_two_fields() -> None:
    """The identity is packed into the field that already existed rather than given a new one.

    The chunk metadata schema is fixed: citation reads it and deletion reads it, and a chunk
    missing a field can be neither cited nor reliably removed. So the four measured parts
    compose into one string in `embedding_model_id` — which is also written to the `chunks`
    row, and is therefore the durable record of what each vector was made by after a provider
    swap invalidates a collection.
    """
    assert "embedding_model_id" in CHUNK_METADATA_FIELDS
    assert len(CHUNK_METADATA_FIELDS) == 32


def test_the_identity_scheme_is_a_version_prefix() -> None:
    """It exists so the *composition* of the string can be corrected without the correction
    reading as vendor drift. Bumping it re-identifies everything at once, on purpose."""
    assert IDENTITY_SCHEME.startswith("emb/")


# ── the digest itself ────────────────────────────────────────────────────────

#: A width small enough to read in a failure message. The real spaces are 1024–3072 wide; none
#: of the properties below depend on the width, and one of them (`assert_dimensions` on *every*
#: probe rather than the first) depends on being able to make one probe differ.
_WIDTH: Final[int] = 8


def _space(dimensions: int = _WIDTH) -> EmbeddingSpace:
    return EmbeddingSpace(provider="openai", model="probe-model", dimensions=dimensions)


def _vector(seed: float, width: int = _WIDTH) -> list[float]:
    return [seed + index / 10_000 for index in range(width)]


def _probe_vectors(seed: float = 0.5) -> list[list[float]]:
    return [_vector(seed + probe) for probe in range(len(CANARY_TEXTS))]


def test_the_digest_is_deterministic_and_shaped() -> None:
    vectors = _probe_vectors()
    assert canary_digest(vectors) == canary_digest(vectors)
    digest = canary_digest(vectors)
    assert len(digest) == CANARY_DIGEST_CHARS
    assert set(digest) <= set("0123456789abcdef")


def test_the_digest_absorbs_fleet_noise_and_sees_a_retrained_model() -> None:
    """The two failure directions, and both are real.

    Embedding APIs are not bit-reproducible across their own hardware — different accelerators,
    batch shapes and kernel versions move the last bits — so a digest over raw floats changes on
    *their* deploy and re-versions our whole corpus for nothing, which is how an operator learns
    to ignore the detector. A digest over too few places cannot see a re-trained model at all.

    `CANARY_PRECISION = 4` is the line between them and has never been measured against a live
    provider; that is recorded as open and is why `CANARY_CONFIRMATIONS` exists.
    """
    baseline = _probe_vectors()
    noise = 10 ** -(CANARY_PRECISION + 2)
    jittered = [[value + noise for value in vector] for vector in baseline]
    assert canary_digest(jittered) == canary_digest(baseline)

    moved = [[value + 0.01 for value in vector] for vector in baseline]
    assert canary_digest(moved) != canary_digest(baseline)


def test_negative_zero_does_not_flip_the_digest() -> None:
    """`-0.00004` and `+0.00004` are the same vector at this precision and format to `-0.0000`
    and `0.0000`. Left alone, a component sitting on zero moves the digest on ordinary fleet
    noise — a false positive on the one signal that must be believed when it fires."""
    tiny = 10 ** -(CANARY_PRECISION + 2)
    positive = [[tiny] * _WIDTH for _ in CANARY_TEXTS]
    negative = [[-tiny] * _WIDTH for _ in CANARY_TEXTS]
    assert canary_digest(positive) == canary_digest(negative)


def test_a_reordered_response_cannot_collide_with_an_unchanged_one() -> None:
    """Out-of-order responses are documented behaviour on at least one vendor, which is why the
    probe index is inside the payload."""
    vectors = _probe_vectors()
    reordered = [vectors[1], vectors[0], *vectors[2:]]
    assert canary_digest(reordered) != canary_digest(vectors)


@pytest.mark.parametrize(
    ("vectors", "reason"),
    [
        pytest.param(_probe_vectors()[:-1], "probe count", id="short"),
        pytest.param([[] for _ in CANARY_TEXTS], "zero-width", id="empty"),
        pytest.param(
            [_vector(0.5), _vector(0.5, width=_WIDTH + 1), *[_vector(0.5)] * 3],
            "ragged",
            id="ragged",
        ),
        pytest.param([[float("nan")] * _WIDTH for _ in CANARY_TEXTS], "non-finite", id="nan"),
    ],
)
def test_a_malformed_response_raises_rather_than_being_fingerprinted(
    vectors: Sequence[Sequence[float]], reason: str
) -> None:
    """A digest computed over a malformed response is a stable fingerprint of a bug — and a NaN
    vector is the worst case, because it hashes perfectly reproducibly."""
    with pytest.raises(KbError) as caught:
        canary_digest(vectors)
    assert caught.value.error_class is ErrorClass.INTERNAL_DEPENDENCY
    assert caught.value.origin is Origin.DOWNSTREAM


def test_the_probe_input_type_is_fixed_and_is_the_passage_side() -> None:
    """A fingerprint input exactly like `CANARY_TEXTS`. Several vendors embed the same string
    differently in query and passage mode — one documents a large recall drop for the wrong
    value — so probing as `QUERY` one day and `PASSAGE` the next reads as a weight swap. It is
    `PASSAGE` because that is what ingestion embeds: a probe taken through a different path than
    the corpus can agree while the corpus's own path has changed."""
    assert CANARY_INPUT_TYPE is EmbeddingInputType.PASSAGE


# ── the identity string ──────────────────────────────────────────────────────


def test_the_version_string_carries_all_four_measured_parts() -> None:
    identity = _identity(digest="9f2a1c4e77b1")
    assert identity.version == "emb/v1:openai:text-embedding-3-large:d3072:9f2a1c4e77b1"


def test_an_identity_with_no_digest_cannot_produce_a_version() -> None:
    """An empty digest is a bare alias wearing the identity's name — the exact value that cannot
    detect a weight swap. It must not reach a chunk or an ingest key, and a chunk carrying one
    would look completely ordinary."""
    with pytest.raises(KbError, match="canary digest"):
        _ = EmbeddingModelIdentity(space=_space(), canary_digest="").version


# ── the window check ─────────────────────────────────────────────────────────


def test_the_window_check_passes_at_exactly_the_budget() -> None:
    check_window(MAX_TOKENS + TOKEN_HEADROOM, model="probe-model")


def test_the_window_check_refuses_a_model_that_would_truncate() -> None:
    """The direct successor of the local loader's `passage_max_length` assertion, and the only
    place the check can happen at all: a provider that trims a long input returns 200 with a
    perfectly plausible vector, so this is not discoverable from a response."""
    with pytest.raises(KbError) as caught:
        check_window(512, model="text-embedding-tiny")
    assert caught.value.error_class is ErrorClass.VALIDATION
    assert not caught.value.retryable
    message = str(caught.value)
    assert "512" in message and str(MAX_TOKENS) in message and "text-embedding-tiny" in message


def test_an_unpopulated_capability_row_says_so_rather_than_reading_as_too_small() -> None:
    with pytest.raises(KbError, match="no usable context window"):
        check_window(0, model="probe-model")


# ── resolve_identity ─────────────────────────────────────────────────────────


class _FakeEmbed:
    """A stand-in for the bound provider callable. This is why `EmbedCallable` is a Protocol:
    ADR-030 forbids a local embedder, so there is nothing to run here and nothing to fake at a
    lower level than the call itself."""

    def __init__(
        self,
        *,
        vectors: Sequence[Sequence[float]] | None = None,
        space: EmbeddingSpace | None = None,
    ) -> None:
        self.vectors = [list(vector) for vector in (vectors or _probe_vectors())]
        self.space = space or _space()
        self.calls: list[dict[str, object]] = []

    def __call__(
        self,
        texts: list[str],
        *,
        input_type: EmbeddingInputType,
        org_id: str,
        trace_id: str,
    ) -> EmbeddingResult:
        self.calls.append(
            {
                "texts": list(texts),
                "input_type": input_type,
                "org_id": org_id,
                "trace_id": trace_id,
            }
        )
        return EmbeddingResult(
            vectors=self.vectors,
            space=self.space,
            normalized=True,
            usage=Usage(input_tokens=17),
            total_ms=3,
            diagnostics=Diagnostics(provider=self.space.provider),
        )


def _resolve(
    embed: _FakeEmbed, *, window: int = MAX_TOKENS + TOKEN_HEADROOM
) -> EmbeddingModelIdentity:
    return resolve_identity(
        embed=embed,
        space=embed.space,
        context_window=window,
        org_id="01JBQ0000000000000000000AA",
        trace_id="trace-1",
    )


def test_resolving_probes_exactly_the_fixed_set_and_names_its_organization() -> None:
    """The S11 regression. The probe is our own text rather than a tenant's, but it still spends
    *an organization's* credential and quota, so the call has to be attributable — and a
    callable that cannot name its organization cannot be scoped, metered or traced."""
    embed = _FakeEmbed()
    identity = _resolve(embed)

    assert len(embed.calls) == 1
    call = embed.calls[0]
    assert call["texts"] == list(CANARY_TEXTS)
    assert call["input_type"] is CANARY_INPUT_TYPE
    assert call["org_id"] == "01JBQ0000000000000000000AA"
    assert call["trace_id"] == "trace-1"
    assert identity.canary_digest == canary_digest(embed.vectors)
    assert identity.space == embed.space


def test_the_window_is_checked_before_any_text_leaves_the_process() -> None:
    """Order is part of the specification, not an implementation detail. Reversed, the probe is
    sent to a model already known to be unusable — and on a provider that trims rather than
    erroring, that request also *succeeds*, so the run learns nothing and bills for it."""
    embed = _FakeEmbed()
    with pytest.raises(KbError, match="accepts 512 tokens"):
        _resolve(embed, window=512)
    assert embed.calls == []


def test_every_probe_vector_is_width_checked_not_just_the_first() -> None:
    """A vendor that truncates one long input truncates it alone; checking `vectors[0]` and
    trusting the rest is the shape of that miss. `DimensionMismatch` is translated into the
    taxonomy rather than propagated, so one stage has one error class.

    The message is asserted, not just the class. `canary_digest` **also** rejects a ragged
    response, so a version of this test that only checked the error class passed against an
    implementation that width-checked `vectors[0]` alone — it was catching the digest's guard
    and reporting it as the width check. Both guards are wanted; only one of them names the
    space, and that is the one under test here.
    """
    vectors = _probe_vectors()
    vectors[3] = _vector(3.5, width=_WIDTH - 1)
    embed = _FakeEmbed(vectors=vectors)
    with pytest.raises(KbError) as caught:
        _resolve(embed)
    assert caught.value.error_class is ErrorClass.INTERNAL_DEPENDENCY
    assert caught.value.origin is Origin.DOWNSTREAM
    assert "reindex, not a configuration change" in str(caught.value), str(caught.value)


def test_a_measured_space_that_disagrees_with_the_configured_one_stops_the_run() -> None:
    """The check `assert_dimensions` cannot make: two models that agree on width and on nothing
    else. `baai/bge-m3`, `nvidia/nv-embedqa-e5-v5` and `text-embedding-3-large` truncated to
    1024 are all 1024-wide, mutually meaningless, and every one of them upserts cleanly."""
    embed = _FakeEmbed(space=_space())
    with pytest.raises(KbError, match="two different collections"):
        resolve_identity(
            embed=embed,
            space=EmbeddingSpace(provider="nvidia", model="other-model", dimensions=_WIDTH),
            context_window=MAX_TOKENS + TOKEN_HEADROOM,
            org_id="01JBQ0000000000000000000AA",
            trace_id="trace-1",
        )


def test_a_short_probe_response_is_refused_before_it_is_fingerprinted() -> None:
    embed = _FakeEmbed(vectors=_probe_vectors()[:-1])
    with pytest.raises(KbError, match="probe vectors"):
        _resolve(embed)


def test_the_embed_seam_carries_no_credential_shaped_parameter() -> None:
    """The provider layer resolves and decrypts; ingestion receives a bound callable. Asserted on
    the Protocol rather than on a call site, because the Protocol is what a future binder is
    written against."""
    parameters = set(inspect.signature(EmbedCallable.__call__).parameters)
    assert {"texts", "input_type", "org_id", "trace_id"} <= parameters
    assert not parameters & {"credential", "api_key", "secret", "token", "key"}


# ── what happens when the canary moves ───────────────────────────────────────


def _observed(digest: str, space: EmbeddingSpace | None = None) -> EmbeddingModelIdentity:
    return EmbeddingModelIdentity(space=space or _space(), canary_digest=digest)


def test_a_first_observation_is_not_drift() -> None:
    """The first ingest into a new collection has nothing to compare against. Reporting it as
    drift would page on every new space, which is how an alert stops being read."""
    verdict = classify_canary(
        recorded=None, observed=_observed("aaaaaaaaaaaa"), consecutive_disagreements=0
    )
    assert verdict is CanaryVerdict.FIRST_OBSERVATION
    assert enforce_canary(verdict, identity=_observed("aaaaaaaaaaaa")) == []


def test_a_matching_digest_proceeds_silently() -> None:
    digest = "aaaaaaaaaaaa"
    verdict = classify_canary(
        recorded=_observed(digest), observed=_observed(digest), consecutive_disagreements=0
    )
    assert verdict is CanaryVerdict.MATCH
    assert enforce_canary(verdict, identity=_observed(digest)) == []


def test_a_reconfigured_model_is_a_different_space_and_never_reported_as_drift() -> None:
    """A configured model change moves the space *and* the digest, and it has its own
    collection, so nothing is mixed and nobody should be paged. The space is checked before the
    digest for exactly this reason."""
    verdict = classify_canary(
        recorded=_observed("aaaaaaaaaaaa"),
        observed=_observed(
            "bbbbbbbbbbbb",
            space=EmbeddingSpace(provider="nvidia", model="other", dimensions=_WIDTH),
        ),
        consecutive_disagreements=1,
    )
    assert verdict is CanaryVerdict.DIFFERENT_SPACE
    assert enforce_canary(verdict, identity=_observed("bbbbbbbbbbbb")) == []


def test_one_disagreement_warns_and_does_not_stop_ingestion() -> None:
    """Deliberately not a failure. Embedding APIs are not bit-reproducible across their own
    fleet, and halting platform-wide ingestion on a single differing probe hands the vendor an
    outage switch. The warning is what makes the second observation meaningful."""
    verdict = classify_canary(
        recorded=_observed("aaaaaaaaaaaa"),
        observed=_observed("bbbbbbbbbbbb"),
        consecutive_disagreements=1,
    )
    assert verdict is CanaryVerdict.SUSPECTED_DRIFT
    warnings = enforce_canary(verdict, identity=_observed("bbbbbbbbbbbb"))
    assert warnings == ["embedding_canary_suspected_drift:bbbbbbbbbbbb"]


def test_a_confirmed_drift_fails_the_run_and_is_never_repaired() -> None:
    """The whole point of the mechanism, and the assertion that catches the "helpful" fix.

    The tempting response to a moved alias is to re-embed into the current collection so
    retrieval keeps working. That is precisely the failure: two incomparable spaces in one
    collection, where cosine distance is defined between any two vectors of equal width, so
    nothing raises and only the ranking of the older half degrades, forever. The correct
    response is a new space, its own collection, and an operator-scheduled reindex — so this
    raises rather than returning a warning a caller could ignore.
    """
    verdict = classify_canary(
        recorded=_observed("aaaaaaaaaaaa"),
        observed=_observed("bbbbbbbbbbbb"),
        consecutive_disagreements=CANARY_CONFIRMATIONS,
    )
    assert verdict is CanaryVerdict.CONFIRMED_DRIFT

    with pytest.raises(KbError) as caught:
        enforce_canary(verdict, identity=_observed("bbbbbbbbbbbb"))
    assert caught.value.error_class is ErrorClass.INTERNAL_DEPENDENCY
    assert caught.value.origin is Origin.DOWNSTREAM
    assert not caught.value.retryable
    assert _space().collection in str(caught.value)


def test_the_comparison_is_pure() -> None:
    """No clock, no storage, no side effect — which is what lets the worker and the maintenance
    sweep reach the same conclusion from the same facts without sharing a code path. The
    disagreement counter is the caller's to persist: a counter kept in this process would reset
    on every worker restart, and a restart is the single most likely thing to happen during a
    provider incident."""
    arguments = {
        "recorded": _observed("aaaaaaaaaaaa"),
        "observed": _observed("bbbbbbbbbbbb"),
        "consecutive_disagreements": 1,
    }
    assert classify_canary(**arguments) is classify_canary(**arguments)  # type: ignore[arg-type]
    parameters = inspect.signature(classify_canary).parameters
    assert set(parameters) == {"recorded", "observed", "consecutive_disagreements"}
    assert all(p.kind is inspect.Parameter.KEYWORD_ONLY for p in parameters.values())


# ── no local model came back ─────────────────────────────────────────────────

#: Read through `ast`, not by matching text, so this file and the prose in `embedding/` may
#: name what they exclude. A text grep would find `torch` in `parsing/converter.py`'s
#: explanation of why Docling's import is deferred and fail on a docstring.
LOCAL_ML_MODULES: Final[frozenset[str]] = frozenset(
    {
        "torch",
        "FlagEmbedding",
        "transformers",
        "sentence_transformers",
        "flag_embedding",
        "huggingface_hub",
    }
)

#: Parsing and OCR keep their local weights — Docling's layout and TableFormer models and
#: RapidOCR's ONNX files — so they are outside this scan by design. The rule is not "no local
#: computation"; it is "no local model inference for embedding or reranking".
SCANNED: Final[tuple[str, ...]] = ("embedding", "chunking", "indexing")


def _ingestion_sources() -> Iterator[tuple[Path, ast.Module]]:
    roots = [APP_ROOT / "ingestion" / name for name in SCANNED]
    roots += [APP_ROOT / "ingestion" / f"{stem}.py" for stem in ("identity", "publish", "tasks")]
    for root in roots:
        paths = sorted(root.rglob("*.py")) if root.is_dir() else [root]
        for path in paths:
            if "__pycache__" in path.parts or not path.exists():
                continue
            yield path, ast.parse(path.read_text(encoding="utf-8"), filename=str(path))


def _imported_roots(tree: ast.Module) -> set[str]:
    roots: set[str] = set()
    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            roots.update(alias.name.split(".")[0] for alias in node.names)
        elif isinstance(node, ast.ImportFrom) and node.module and node.level == 0:
            roots.add(node.module.split(".")[0])
    return roots


SOURCES: Final[list[tuple[Path, ast.Module]]] = list(_ingestion_sources())


def test_the_scan_found_the_embedding_modules() -> None:
    """Positive control. The guard below is a "no file does X" assertion and passes trivially
    over an empty list — the same green-while-proving-nothing shape as an isolation test whose
    surface returned no rows."""
    names = {p.name for p, _ in SOURCES}
    assert "embedder.py" in names, sorted(names)
    assert len(SOURCES) >= 6, sorted(names)


def test_no_embedding_stage_module_imports_a_local_model_runtime() -> None:
    """Embeddings are an external API call. A local embedder reintroduced here would work — it
    would produce vectors of the right width and pass every count-based check — while spending
    GPU the deployment no longer provisions and, worse, writing vectors from a space that no
    chunk's `embedding_model_id` names.

    Deliberately scoped to the embed/chunk/index half. `parsing/` and `ocr/` keep their local
    weights and their `/models` cache, and a scan that swept them would be enforcing a rule
    nobody made.
    """
    offenders = [
        f"{path.relative_to(APP_ROOT)}: {sorted(LOCAL_ML_MODULES & _imported_roots(tree))}"
        for path, tree in SOURCES
        if LOCAL_ML_MODULES & _imported_roots(tree)
    ]
    assert not offenders, (
        f"local model runtime imported on the embedding path: {offenders}. Embedding goes "
        f"through app/providers/'s embed(); parsing and OCR are the ones that stay local."
    )


def test_the_embedder_does_not_accept_a_credential() -> None:
    """Non-negotiable 9 has no ingestion exemption, and a credential now travels this path.

    The provider layer resolves and decrypts; ingestion receives an already-bound callable. So
    no parameter under `app/ingestion/` is named for a secret — which is what keeps one out of
    a Celery payload on the broker, out of a failed-job record, and out of a span.
    """
    secretish = {"credential", "api_key", "apikey", "secret", "token", "authorization", "key"}
    offenders: list[str] = []
    for path, tree in SOURCES:
        for node in ast.walk(tree):
            if not isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef)):
                continue
            args = node.args
            names = [a.arg for a in (*args.posonlyargs, *args.args, *args.kwonlyargs)]
            offenders += [
                f"{path.relative_to(APP_ROOT)}:{node.name}({n})"
                for n in names
                if n.lower() in secretish
            ]
    assert not offenders, (
        f"a credential-shaped parameter on the ingestion path: {offenders}. The provider layer "
        f"owns credential resolution; ingestion takes a bound callable."
    )
