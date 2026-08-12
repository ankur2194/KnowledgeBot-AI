"""The capability matrix and the five adapters must agree, in both directions.

This file is the whole reason ``app/providers/capabilities.py`` is data rather than prose.
Before it, "which vendors can embed and which can rerank" was a comment in three files that
did not agree with each other: ``contract.py`` claimed "only two of the five vendors can embed
and only two can rerank" and named neither pair, while forty lines below it named three
embedders; ``app/rag/rerank.py`` named NIM plus "OpenRouter via specific models". Nothing
checked, so nothing had to agree.

**The drift this file exists to catch is a one-sided edit**, and it comes in two shapes that
look nothing alike in review:

* Somebody adds ``embed`` to an adapter because a vendor shipped an endpoint, and does not
  move the matrix cell. ``can_embed`` keeps answering False, every ingest keeps refusing the
  configuration, and the method is dead code that reads as working code.
* Somebody flips a matrix cell to ``SUPPORTED`` because they are confident, and no method
  exists. ``can_embed`` answers True, the caller dispatches, and ``AttributeError`` arrives on
  the ingestion path — a class that is not in the taxonomy at all, in the one place a failure
  costs a re-parse.

Neither is possible while ``test_method_presence_matches_matrix`` is green.

Nothing here touches a network, and nothing here needs a credential: the matrix is static data
and the adapters are constructed with no key by design (`contract.ProviderAdapter.stream`
records why the credential is a per-call argument).
"""

from __future__ import annotations

import pytest

from app.core.errors import ErrorClass, KbError
from app.providers.anthropic import AnthropicAdapter
from app.providers.capabilities import (
    PROVIDER_TASKS,
    PROVIDERS,
    RERANK_SCALE,
    Support,
    assert_row_coherent,
    can_embed,
    can_rerank,
    provider_offers,
    providers_offering,
    rerank_scale,
    task_support,
)
from app.providers.contract import (
    Capability,
    ModelCapabilities,
    ProviderAdapter,
    RerankScale,
)
from app.providers.deepseek import DeepSeekAdapter
from app.providers.errors import ProviderSurface
from app.providers.nim import NimAdapter
from app.providers.openai_adapter import OpenAIAdapter
from app.providers.openrouter import OpenRouterAdapter

# Typed as the chat Protocol — the one surface all five satisfy — rather than as ``object``, so
# ``adapter.name`` type-checks. The non-chat methods are deliberately NOT on this type; that is
# the static gate, and reaching for them here is what ``hasattr`` is confined to.
#
# Constructed with no credential, which is itself the contract: an adapter that needed a key to
# exist would hold one org's key while serving another org's turn.
ADAPTERS: dict[str, ProviderAdapter] = {
    "openai": OpenAIAdapter(),
    "anthropic": AnthropicAdapter(),
    "deepseek": DeepSeekAdapter(),
    "nvidia_nim": NimAdapter(),
    "openrouter": OpenRouterAdapter(http=None, public_app_url="https://example.invalid"),
}

#: The method each non-chat surface is gated on. The surface is the key everywhere — in the
#: matrix, in ``errors.NON_CHAT_FALLBACK_ELIGIBLE``, and here — so a fourth task family adds
#: one row rather than a parallel enum.
SURFACE_METHOD: dict[ProviderSurface, str] = {
    ProviderSurface.EMBEDDING: "embed",
    ProviderSurface.RERANK: "rerank",
}


def _row(*flags: Capability) -> ModelCapabilities:
    """A ``provider_models`` row carrying exactly the given flags."""
    return ModelCapabilities(
        supported=frozenset(flags),
        context_window=8192,
        max_output_tokens=4096,
    )


# ── the matrix is total and self-consistent ───────────────────────────────────


def test_matrix_covers_every_provider_and_surface() -> None:
    """Fifteen cells, no holes. A hole is a cell somebody completes from memory."""
    assert set(PROVIDER_TASKS) == {(p, s) for p in PROVIDERS for s in ProviderSurface}
    assert len(PROVIDER_TASKS) == 15


def test_provider_names_are_the_adapter_names() -> None:
    """The matrix key is the adapter's own ``name``, not a second spelling of it.

    ``name`` is also the ``gen_ai.provider.name`` span attribute and the ``provider`` metric
    label, so a mismatch here would split one vendor across two label values as well as
    silently returning ``_UNKNOWN`` from every lookup.
    """
    assert set(PROVIDERS) == set(ADAPTERS)
    for name, adapter in ADAPTERS.items():
        assert adapter.name == name


def test_every_decided_cell_names_a_source() -> None:
    """A verdict without an authority is ``UNVERIFIED``, enforced by the model, checked here.

    This is what makes re-introducing the unsourced "two and two" impossible: marking a vendor
    ``SUPPORTED`` requires naming what says so, in the same diff.
    """
    for (provider, surface), cell in PROVIDER_TASKS.items():
        where = f"{provider}/{surface.value}"
        assert cell.note, f"{where}: every cell records what was found, including nothing"
        if cell.support is Support.UNVERIFIED:
            assert not cell.source, f"{where}: an unverified cell must not name a source"
        else:
            assert cell.source, f"{where}: {cell.support.value} is a claim; cite it"


def test_which_vendors_embed_and_which_rerank_named_not_counted() -> None:
    """The claim this whole task exists to correct, pinned so it cannot drift back silently.

    Three revisions of it are on record and every one was a **count**: ``contract.py`` said
    "two and two" and named nobody; this test then said "one and one" from a reading of the
    skills, which were merely silent about four cells; the vendors' own documentation says
    **three embed and two rerank**, and names them. The lesson is in the shape and not in the
    numbers — a count is unfalsifiable without a pair, so this test asserts sets by membership.

    Not by size. ``len(...) == 2`` would pass for ``{"openai", "deepseek"}``, and on a
    five-element universe with two- and three-element answers a size check is close to no
    check at all.

    A vendor genuinely gaining or losing an endpoint should change these assertions in a diff
    somebody reviews — that is the point of pinning them, not an obstacle to it.
    """
    assert providers_offering(ProviderSurface.EMBEDDING) == frozenset(
        {"openai", "nvidia_nim", "openrouter"}
    )
    assert providers_offering(ProviderSurface.RERANK) == frozenset({"nvidia_nim", "openrouter"})

    # Three sourced denials on rerank, two on embedding, and the embedding pair is the one
    # that costs an organization the ability to ingest at all.
    rerank_denied = {
        p
        for p in PROVIDERS
        if PROVIDER_TASKS[p, ProviderSurface.RERANK].support is Support.UNSUPPORTED
    }
    assert rerank_denied == {"openai", "anthropic", "deepseek"}
    embed_denied = {
        p
        for p in PROVIDERS
        if PROVIDER_TASKS[p, ProviderSurface.EMBEDDING].support is Support.UNSUPPORTED
    }
    assert embed_denied == {"anthropic", "deepseek"}


def test_no_cell_is_left_unverified() -> None:
    """Every one of the fifteen has been read off a vendor document. **This is the live one.**

    ``UNVERIFIED`` is the honest state for an unread cell and it gates exactly like an absent
    endpoint, which is precisely why an unread cell can sit there indefinitely without
    anything going red: four embedding cells did, and two of them turned out to be endpoints
    that existed. So the state stays in the enum for ``_UNKNOWN`` and for the next surface
    nobody has read, and this assertion is what stops it being used as a resting place.

    Failing this is not a defect in the matrix — it means somebody added a provider or a
    surface. The fix is to read the vendor's documentation and cite it, not to delete the test.
    """
    unread = {
        (p, s.value)
        for (p, s), cell in PROVIDER_TASKS.items()
        if cell.support is Support.UNVERIFIED
    }
    assert unread == set(), (
        f"unread cells: {sorted(unread)}. Read the vendor's own current documentation and cite "
        "it with the date; a skill's silence is not evidence in either direction."
    )


def test_every_verdict_cites_a_dated_vendor_document_or_a_skill() -> None:
    """A source has to be somewhere a reader can go, and a hosted verdict has to be dated.

    The date is the part that is easy to drop and expensive to lose. Four of these five
    vendors are catalogues that can gain or withdraw a family with no diff anywhere in this
    repository, so a verdict with no read-date cannot be aged and gets trusted forever.

    Scoped to *decided* cells rather than to all fifteen, and the distinction is not currently
    observable because none is undecided. It is written this way so that the day somebody
    legitimately adds an unread cell, exactly one test goes red — ``test_no_cell_is_left_
    unverified``, which is about the backlog — and this one, which is about citation quality,
    keeps meaning what its name says. Two tests failing for one cause is how the cause gets
    misread.
    """
    decided = {k: c for k, c in PROVIDER_TASKS.items() if c.support is not Support.UNVERIFIED}
    for (provider, surface), cell in decided.items():
        where = f"{provider}/{surface.value}"
        assert cell.source, f"{where}: every decided cell cites something"
        cites_vendor = "http" in cell.source
        cites_skill = ".claude/skills/" in cell.source
        assert cites_vendor or cites_skill, (
            f"{where}: {cell.source!r} points nowhere a reader can go"
        )
        if cites_vendor:
            assert "read 20" in cell.source, (
                f"{where}: a vendor citation carries the date it was read, or nobody can tell "
                "a current fact from a stale one"
            )


def test_every_vendor_chats() -> None:
    assert providers_offering(ProviderSurface.CHAT) == frozenset(PROVIDERS)


# ── the two gates agree: the matrix and the adapters ──────────────────────────


@pytest.mark.parametrize("provider", PROVIDERS)
@pytest.mark.parametrize("surface", list(SURFACE_METHOD))
def test_method_presence_matches_matrix(provider: str, surface: ProviderSurface) -> None:
    """**The drift test.** A method exists iff the matrix says the vendor offers the endpoint.

    Both directions are asserted because both one-sided edits are plausible and neither is
    visible in review: a method added without moving the cell is dead code that reads as live,
    and a cell flipped without a method is an ``AttributeError`` on the ingestion path — a
    failure with no class in the 18-entry taxonomy.

    ``hasattr`` is used deliberately, and only here. It is the runtime shadow of the static
    gate (``_assert_embeds`` / ``_assert_reranks``, which mypy checks), and confining it to
    this file is what keeps every other call site asking ``can_embed`` / ``can_rerank``
    instead.
    """
    adapter = ADAPTERS[provider]
    method = SURFACE_METHOD[surface]
    offered = PROVIDER_TASKS[provider, surface].support.available
    present = hasattr(adapter, method)

    assert present == offered, (
        f"{provider}.{method}() is {'present' if present else 'absent'} but PROVIDER_TASKS "
        f"records {provider} as {PROVIDER_TASKS[provider, surface].support.value} on the "
        f"{surface.value} surface. These two gates must move together: prove the endpoint with "
        "a recorded fixture, move the matrix cell, and wire the method in the same change."
    )


def test_each_adapters_non_chat_surfaces_are_the_expected_ones() -> None:
    """Named explicitly, so the drift test cannot pass vacuously if a parametrization breaks.

    Written as the full five-by-two grid rather than as a handful of interesting cases. The
    previous version of this test named one embedder and one reranker and asserted the other
    three adapters had neither method; it was correct about the code and it could not have
    noticed that two of those three had endpoints nobody had read. A grid at least fails
    loudly on the day the answer changes.
    """
    expected: dict[str, set[str]] = {
        "openai": {"embed"},
        "anthropic": set(),
        "deepseek": set(),
        "nvidia_nim": {"embed", "rerank"},
        "openrouter": {"embed", "rerank"},
    }
    actual = {
        name: {m for m in ("embed", "rerank") if hasattr(adapter, m)}
        for name, adapter in ADAPTERS.items()
    }
    assert actual == expected

    # The two that have neither are the two that CANNOT, and the distinction is the whole of
    # finding C1: an organization holding only one of these cannot ingest a document.
    assert {n for n, ms in actual.items() if not ms} == {"anthropic", "deepseek"}


def test_validate_helpers_travel_with_their_method() -> None:
    """A surface method without its validator satisfies no Protocol and skips every range
    check — including the per-input length limit, whose failure is a silently truncated passage
    rather than an error.

    Asserted over every adapter and both surfaces rather than at four spot-checked points: the
    pairing is what the Protocols require, so a partial check is a partial requirement.
    """
    for name, adapter in ADAPTERS.items():
        for method, validator in (("embed", "validate_embedding"), ("rerank", "validate_rerank")):
            assert hasattr(adapter, method) == hasattr(adapter, validator), (
                f"{name}: {method} and {validator} must travel together — a surface without its "
                "validator sends unchecked ceilings, and a validator without its surface is "
                "dead code that reads as a capability"
            )


# ── the gate answers, and never raises ────────────────────────────────────────


def test_capability_question_needs_both_axes() -> None:
    """Embedding: provider matrix AND row flag. Either alone is a False."""
    assert can_embed("openai", _row(Capability.EMBEDDING))
    assert not can_embed("openai", _row()), "the row must carry the flag"
    assert not can_embed("anthropic", _row(Capability.EMBEDDING)), "the vendor must offer it"

    assert can_rerank("nvidia_nim", _row(Capability.RERANK))
    assert not can_rerank("nvidia_nim", _row())
    assert not can_rerank("deepseek", _row(Capability.RERANK)), "the vendor must offer it"

    # OpenRouter is the pair that used to be False on the vendor axis for want of a citation
    # and is now True there. Kept as an explicit case because it is the one a reader is most
    # likely to remember wrongly — and the rerank half is now False again, for an entirely
    # different reason. See the third-axis tests below.
    assert can_embed("openrouter", _row(Capability.EMBEDDING))


def test_rerank_needs_a_third_axis_and_openrouter_fails_it() -> None:
    """**Finding #47.** The vendor publishes the endpoint; this platform cannot consume it.

    ``can_rerank`` is the AND of three things, and OpenRouter is True on the first two and
    False on the third: ``PROVIDER_TASKS`` records the route as ``SUPPORTED`` against the
    vendor's own docs, the row carries the flag, and ``RERANK_SCALE`` has no entry, which makes
    the scale ``UNCALIBRATED``.

    That third axis is not a quality preference, and the assertions below say why by reading
    the consuming module rather than by restating it: ``RerankCalibration`` refuses to be
    constructed on an unthresholdable scale, so stage 11 — which takes a calibration as a
    required argument — has no reachable call for such a provider, and stage 12 has no
    ordering-only mode to fall back to. Answering ``True`` here would send a configuration down
    a path whose only two endings are an exception at configuration time and an exception on
    every request.

    Imported inside the test for the reason ``test_there_is_exactly_one_rerank_scale_type``
    gives: this file must not create an ``app/providers`` -> ``app/rag`` dependency.
    """
    from app.rag.rerank import RerankCalibration

    assert provider_offers("openrouter", ProviderSurface.RERANK), "axis 1: the vendor does"
    assert Capability.RERANK in _row(Capability.RERANK).supported, "axis 2: the row does"
    assert rerank_scale("openrouter") is RerankScale.UNCALIBRATED, "axis 3: nobody has"

    assert not can_rerank("openrouter", _row(Capability.RERANK))
    assert can_rerank("nvidia_nim", _row(Capability.RERANK)), (
        "NIM is the contrast: LOGIT is thresholdable, so its CALIBRATIONS entry is one "
        "evaluation run away and nothing here refuses it"
    )

    # The consuming refusal, so the third axis is not this module agreeing with itself. A
    # calibration on OpenRouter's scale is unconstructible, which is why "ordering only" names
    # no path: there is nothing to hand stage 11.
    with pytest.raises(ValueError, match="may not be thresholded"):
        RerankCalibration(
            provider="openrouter",
            model="cohere/rerank-v3.5",
            scale=rerank_scale("openrouter"),
            min_score=0.30,
            max_passage_tokens=512,
            derived_from="a run that cannot exist",
        )


def test_a_provider_is_rerank_eligible_exactly_when_its_scale_is_declared() -> None:
    """The eligible set is ``RERANK_SCALE``'s keys, not ``providers_offering(RERANK)``.

    Stated as a set relation over all five so it cannot be satisfied by the one case anybody
    remembers. Before finding #47 these two sets were assumed equal; they are not, and the
    difference is exactly ``{"openrouter"}`` — a vendor that publishes a ranking route this
    pipeline has no way to threshold.
    """
    claims_rerank = _row(Capability.RERANK)
    eligible = {p for p in PROVIDERS if can_rerank(p, claims_rerank)}

    assert eligible == set(RERANK_SCALE)
    assert eligible == {"nvidia_nim"}
    assert providers_offering(ProviderSurface.RERANK) - eligible == {"openrouter"}
    assert eligible <= providers_offering(ProviderSurface.RERANK), (
        "eligibility can only ever be a subset of what the vendors publish — a provider "
        "eligible without an endpoint would be a scale declared for a route that does not "
        "exist, which the import-time assertion at the foot of capabilities.py also refuses"
    )


def test_an_incoherent_row_degrades_and_never_raises() -> None:
    """A row claiming a capability its vendor lacks must not manufacture a call failure.

    ``RerankSkipReason`` is closed and contains only pre-call reasons; a gate that raised would
    let an exception be caught and recorded as ``PROVIDER_LACKS_CAPABILITY``, which is an
    outage laundered into a skip — the failure whose only symptom is answer quality drifting
    for as long as nobody looks.
    """
    claims_everything = _row(Capability.EMBEDDING, Capability.RERANK)
    for provider in PROVIDERS:
        assert can_embed(provider, claims_everything) == (
            provider in providers_offering(ProviderSurface.EMBEDDING)
        )
        # Read from RERANK_SCALE and not from providers_offering: the two agree on four of the
        # five providers and disagree on OpenRouter, which is finding #47. This assertion used
        # to read `provider in providers_offering(RERANK)` and it was the two-axis assumption
        # written down.
        assert can_rerank(provider, claims_everything) == (provider in RERANK_SCALE)

    # Read from the matrix above rather than hardcoded, because what this test is about is
    # that the gate never RAISES on an incoherent row — not which vendors are in the set. The
    # membership is pinned once, in test_which_vendors_embed_and_which_rerank_named_not_counted,
    # and duplicating it here would mean two places to edit and one of them forgotten.
    assert "anthropic" not in providers_offering(ProviderSurface.EMBEDDING)


def test_gate_is_total_on_an_unknown_provider() -> None:
    """Fail closed, silently, on the read path. A ``KeyError`` inside a retrieval gate cannot
    be handled there — the same reason ``app/core/errors.py`` asserts its tables at import."""
    row = _row(Capability.EMBEDDING, Capability.RERANK)
    assert not can_embed("cohere", row)
    assert not can_rerank("cohere", row)
    assert not provider_offers("cohere", ProviderSurface.CHAT)
    assert task_support("cohere", ProviderSurface.CHAT).support is Support.UNVERIFIED


# ── the loud half, at configuration time ──────────────────────────────────────


def test_save_time_check_rejects_a_row_the_vendor_cannot_serve() -> None:
    """The same disagreement that degrades quietly at request time is an error at save time.

    ``VALIDATION``: 422, never retried, because the next attempt sends the identical row.
    """
    with pytest.raises(KbError) as excinfo:
        assert_row_coherent("anthropic", "claude-opus-5", _row(Capability.EMBEDDING))
    assert excinfo.value.error_class is ErrorClass.VALIDATION
    assert excinfo.value.retryable is False

    with pytest.raises(KbError):
        assert_row_coherent("openai", "gpt-5.6-sol", _row(Capability.RERANK))

    # The two sourced pairs save cleanly.
    assert_row_coherent("openai", "text-embedding-3-large", _row(Capability.EMBEDDING))
    assert_row_coherent("nvidia_nim", "nvidia/nv-rerankqa-mistral-4b-v3", _row(Capability.RERANK))


def test_save_time_check_rejects_a_rerank_row_whose_scale_nobody_has_characterized() -> None:
    """**Finding #47's loud half.** The vendor publishes it; the row can still never run.

    Distinct from the refusal above and worded differently on purpose: that one says the vendor
    does not offer the surface, this one says the vendor does and we cannot consume it. Merging
    the two messages would send an operator to OpenRouter's documentation to look for an
    endpoint that is right there.

    Refused at save time rather than degraded at request time because "degrade" is not on the
    menu: with the row saved, the bot's retrieval configuration raises ``RerankNotCalibrated``
    when it resolves, and a forced calibration would raise ``RerankScaleMismatch`` on every
    request. An operator with the model in front of them can act on a 422; neither exception is
    actionable from where it surfaces.
    """
    with pytest.raises(KbError) as excinfo:
        assert_row_coherent("openrouter", "cohere/rerank-v3.5", _row(Capability.RERANK))
    assert excinfo.value.error_class is ErrorClass.VALIDATION
    assert excinfo.value.retryable is False

    message = str(excinfo.value)
    assert "uncalibrated" in message, "the message names the scale, not just the provider"
    assert "RERANK_SCALE" in message, "and names the table an operator would have to change"

    # The vendor axis is untouched, and this is the assertion that stops the fix being
    # 'flip the matrix cell': PROVIDER_TASKS is a statement about the vendor, the vendor does
    # publish the route, and the adapter method that pairs with the cell is still there.
    assert provider_offers("openrouter", ProviderSurface.RERANK)
    assert hasattr(ADAPTERS["openrouter"], "rerank")

    # An openrouter EMBEDDING row is unaffected — the refusal is scoped to the rerank surface
    # and not to the vendor.
    assert_row_coherent("openrouter", "openai/text-embedding-3-large", _row(Capability.EMBEDDING))


def test_save_time_check_rejects_a_row_claiming_two_task_families() -> None:
    """Rows are task-exclusive: different products, different endpoints, different schemas.

    A row claiming both is validated against neither, so every range check downstream of it
    checks the wrong ceilings.
    """
    with pytest.raises(KbError):
        assert_row_coherent("openai", "impossible", _row(Capability.EMBEDDING, Capability.RERANK))


def test_save_time_check_rejects_chat_flags_on_a_non_chat_row() -> None:
    """``TEXT`` on an embedding row describes a model that does not exist."""
    with pytest.raises(KbError):
        assert_row_coherent(
            "openai", "text-embedding-3-large", _row(Capability.EMBEDDING, Capability.TEXT)
        )


def test_a_plain_chat_row_is_always_coherent() -> None:
    for provider in PROVIDERS:
        assert_row_coherent(provider, "some-chat-model", _row(Capability.TEXT, Capability.TOOL_USE))


# ── the score scale, which is the other thing a flag alone cannot carry ───────


def test_the_only_reranker_declares_an_unbounded_but_thresholdable_scale() -> None:
    """NVIDIA's ranking models return an unbounded signed logit; its own example ranks 0.226,
    -1.17, -1.52. The retired local cross-encoder returned a sigmoid in [0, 1] and
    ``evidence.min_score = 0.30`` was calibrated on that. ``0.30`` is a valid float on both and
    nothing raises when they are swapped — only the refusal rate moves, in aggregate.

    ``LOGIT`` is unbounded and **thresholdable**, and the pairing is the whole point. This
    assertion once read ``not LOGIT.may_threshold``, which pinned a rule that disabled the
    evidence gate for the entire platform: NIM is the only provider with a ranking endpoint, so
    ``LOGIT`` is the only scale that can ever arrive, and barring it from the threshold leaves
    stage 11 reordering candidates that stage 12 can never act on.
    """
    assert rerank_scale("nvidia_nim") is RerankScale.LOGIT
    assert not RerankScale.LOGIT.is_bounded, "a logit has no 0-1 range for a min_score to sit in"
    assert RerankScale.LOGIT.may_threshold, (
        "unbounded is not uncalibrated — a threshold is a percentile read off a measured "
        "distribution, not a fraction of a range, and the retired cross-encoder derived one "
        "on a raw logit"
    )


def test_an_uncharacterized_provider_is_uncalibrated_and_never_thresholdable() -> None:
    """``UNCALIBRATED`` is the default and is not a placeholder for a plausible guess.

    It is the **only** member that may not be thresholded, and it is the one the previous
    two-enum split could not carry downstream at all: the consuming enum had no counterpart for
    it, so an adapter reporting "nobody has characterized this scale" had to be widened by a
    guess onto ``SIGMOID`` or ``UNIT_INTERVAL``, at which point a ``min_score`` of 0.30 passed
    the range check against a distribution nobody had ever measured.

    What makes it unthresholdable is the absence of a measured distribution, not the absence of
    bounds — a gateway documenting no normalization across its upstreams returns numbers whose
    meaning varies per request, so there is no percentile to read.
    """
    assert rerank_scale("openrouter") is RerankScale.UNCALIBRATED
    assert rerank_scale("cohere") is RerankScale.UNCALIBRATED
    assert not RerankScale.UNCALIBRATED.may_threshold
    assert not RerankScale.UNCALIBRATED.is_bounded, (
        "an unknown scale is not a bounded scale; answering True here is what lets a "
        "min_score of 0.30 pass its own range check on a distribution nobody has measured"
    )


def test_only_an_uncalibrated_scale_may_not_be_thresholded() -> None:
    """``may_threshold`` and ``is_bounded`` are two properties and must stay two.

    They coincide on the two bounded members, which is why collapsing them passes every
    fixture that only exercises those. The disagreement is entirely on ``LOGIT`` — the one
    scale any configured provider can actually return — so a fixture set without it cannot
    tell the two definitions apart. This test asserts the full partition of both, so the
    collapse is caught by construction rather than by a case somebody remembered to add.
    """
    assert {s for s in RerankScale if s.may_threshold} == {
        RerankScale.LOGIT,
        RerankScale.SIGMOID,
        RerankScale.UNIT_INTERVAL,
    }
    assert {s for s in RerankScale if s.is_bounded} == {
        RerankScale.SIGMOID,
        RerankScale.UNIT_INTERVAL,
    }
    assert RerankScale.LOGIT.may_threshold != RerankScale.LOGIT.is_bounded, (
        "the two predicates must differ on at least one member, or one of them is redundant "
        "and the next edit will delete the wrong one"
    )


def test_the_two_bounded_scales_stay_distinct() -> None:
    """Both are 0–1, and the coincidence is exactly what invites treating a threshold as
    portable between them. Only the bounds transfer; the bounds are not the calibration."""
    assert RerankScale.SIGMOID is not RerankScale.UNIT_INTERVAL
    assert RerankScale.SIGMOID.is_bounded and RerankScale.UNIT_INTERVAL.is_bounded


def test_a_scale_is_only_declared_for_a_provider_that_reranks() -> None:
    """Otherwise the entry is a calibration waiting to be applied to an endpoint that does not
    exist — and it would read as characterization work already done."""
    assert set(RERANK_SCALE) <= providers_offering(ProviderSurface.RERANK)


def test_there_is_exactly_one_rerank_scale_type() -> None:
    """The two-``RerankScale`` defect, closed from this side of the boundary.

    This assertion used to read ``consumer < ours`` — a strict-subset check that described the
    transitional state where ``app/rag/rerank.py`` still defined its own ``LOGIT | SIGMOID |
    UNIT_INTERVAL``. That subset relation was what made the resolution a **deletion** rather
    than a translation: no member was lost and ``UNCALIBRATED``, the one state meaning "never
    threshold this", became representable downstream for the first time. The deletion has
    landed, so the relation to assert is now identity — a subset check would pass again the
    moment somebody re-introduced a local copy.

    Imported inside the test rather than at module scope so that this file does not create a
    ``app/providers`` -> ``app/rag`` dependency, which is the direction that must not exist.
    """
    from app.rag.rerank import RerankScale as ConsumerScale

    assert ConsumerScale is RerankScale, (
        "app/rag/rerank.py must import this enum rather than define one of the same name; two "
        "types with one name and no conversion is how a score's scale is lost at a boundary "
        "that type-checks"
    )
    assert "uncalibrated" in {s.value for s in ConsumerScale}
