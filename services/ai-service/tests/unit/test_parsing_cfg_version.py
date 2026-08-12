"""`parser_cfg_version` — the string that decides whether a parser change reaches a document.

It is a component of the ingest key. If a tunable is outside it, changing that tunable leaves
the content hash unchanged, the resubmission matches the completed run on `UNIQUE
(source_item_id, ingest_key)`, the admin sees "already processed", and the new setting never
touches a document. Nothing raises and no total moves; the only symptom is a corpus parsed under
settings nobody is using any more.

The model revisions are the half that used to be impossible to check. Docling pins every one of
its own model specs to `revision="main"` — a moving branch — so before the shas were resolved, a
rebuilt image could parse the same corpus differently while `docling.__version__`, every option
constant, and therefore the whole config version were unchanged. Two tests below are about
exactly that and both fail if the pins come back out.
"""

from __future__ import annotations

from typing import Final

import pytest

from app.core.errors import KbError
from app.ingestion.cfg_version import canonicalize
from app.ingestion.parsing import converter
from app.ingestion.parsing.converter import (
    PARSER_CFG_EXCLUDED,
    PARSER_CFG_INPUTS,
    PARSER_CFG_SCHEME,
    PARSER_MODEL_PINS,
    parser_cfg_version,
)

#: Names in `__all__` that declare the composition rather than being options themselves. Listing
#: them is what lets the coverage test below be an equality rather than a subset — a subset
#: check passes when a new constant is added to neither list, which is the whole failure.
_META: Final[frozenset[str]] = frozenset(
    {"PARSER_CFG_EXCLUDED", "PARSER_CFG_INPUTS", "PARSER_CFG_SCHEME", "PARSER_MODEL_PINS"}
)


def _option_constants() -> set[str]:
    return {
        name
        for name in converter.__all__
        if name.isupper() and name not in _META and not name.startswith("_")
    }


# ── the composition covers everything ────────────────────────────────────────


def test_every_option_constant_is_either_folded_in_or_excluded_with_a_reason() -> None:
    """The test that catches the next constant somebody adds.

    A new option that is in neither list is a tunable outside the config version, and the
    failure it produces is a retune that silently never re-ingests. Being *excluded* is fine —
    `ARTIFACTS_PATH` genuinely is — but it has to be a decision on the record with a reason
    beside it, not an omission.
    """
    declared = set(PARSER_CFG_INPUTS) | set(PARSER_CFG_EXCLUDED)
    options = _option_constants()
    assert declared == options, (
        f"unclassified: {sorted(options - declared)}; "
        f"named but not a module constant: {sorted(declared - options)}"
    )
    assert not set(PARSER_CFG_INPUTS) & set(PARSER_CFG_EXCLUDED)
    assert all(reason.strip() for reason in PARSER_CFG_EXCLUDED.values())


def test_the_four_loss_prevention_options_are_all_inside() -> None:
    """Each of these reads as a harmless default and each one silently degrades the index:
    flat heading levels collapse small-to-big retrieval, the body-only walk drops speaker notes
    and page footers, fast table mode mismatches header rows to values, and the default format
    list is every backend including the ones carrying XXE and traversal CVEs."""
    for name in (
        "HEADING_HIERARCHY_ENABLED",
        "INCLUDED_CONTENT_LAYERS",
        "TABLE_STRUCTURE_MODE",
        "ALLOWED_FORMATS",
    ):
        assert name in PARSER_CFG_INPUTS


def test_the_table_serializer_version_is_inside() -> None:
    """It changes the text that is embedded without changing any Docling option — the one way
    this stage can move the index while every other constant stays put."""
    assert "TABLE_SERIALIZER_VERSION" in PARSER_CFG_INPUTS


# ── the string itself ────────────────────────────────────────────────────────


def test_it_is_deterministic_and_readable() -> None:
    """Determinism is not decoration: publication verifies an expected chunk total before it
    activates a version, and a config version that moved between two calls in one run would
    make a document that succeeds on retry fail intermittently."""
    first = parser_cfg_version()
    assert first == parser_cfg_version()
    scheme, label, digest = first.split(":")
    assert scheme + ":" + label.split("docling")[0] == PARSER_CFG_SCHEME.split(":")[0] + ":"
    assert first.startswith(f"{PARSER_CFG_SCHEME}:docling")
    assert len(digest) == 12 and set(digest) <= set("0123456789abcdef")


def test_the_docling_version_is_in_the_string() -> None:
    """Read from distribution metadata rather than `docling.__version__`: the attribute costs a
    multi-second torch import to learn a string, in a module every worker imports at startup."""
    from importlib.metadata import version

    assert f"docling{version('docling')}" in parser_cfg_version()


@pytest.mark.parametrize(
    ("name", "value"),
    [
        pytest.param("HEADING_HIERARCHY_ENABLED", False, id="heading-hierarchy"),
        pytest.param("TABLE_STRUCTURE_MODE", "FAST", id="table-mode"),
        pytest.param("DOCUMENT_TIMEOUT_SECONDS", 180.0, id="document-timeout"),
        pytest.param("INCLUDED_CONTENT_LAYERS", ("BODY",), id="content-layers"),
        pytest.param("TABLE_SERIALIZER_VERSION", "rowwise/v2", id="serializer"),
        pytest.param("ALLOWED_FORMATS", ("PDF",), id="allowed-formats"),
    ],
)
def test_changing_one_option_mints_a_new_version(
    monkeypatch: pytest.MonkeyPatch, name: str, value: object
) -> None:
    before = parser_cfg_version()
    monkeypatch.setattr(converter, name, value)
    assert parser_cfg_version() != before, f"{name} changed and the config version did not"


@pytest.mark.parametrize("name", sorted(PARSER_CFG_EXCLUDED))
def test_changing_an_excluded_constant_does_not_reprocess_the_corpus(
    monkeypatch: pytest.MonkeyPatch, name: str
) -> None:
    """The other direction, and it costs real money. Moving the artifacts directory or
    re-labelling a confidence band must not re-parse, re-embed and re-index every document in
    the platform."""
    before = parser_cfg_version()
    current = getattr(converter, name)
    monkeypatch.setattr(
        converter, name, {"changed": 1.0} if isinstance(current, dict) else type(current)("x")
    )
    assert parser_cfg_version() == before


# ── the model revisions ──────────────────────────────────────────────────────


def test_a_moved_model_revision_mints_a_new_version(monkeypatch: pytest.MonkeyPatch) -> None:
    """The property finding O4 was blocking. Before the shas were resolved this could not be
    stated at all, let alone tested: the revision was a placeholder, and the alternative
    upstream default is a branch name that names different weights on different days."""
    from app.ingestion.model_pins import ModelPin

    before = parser_cfg_version()
    moved = ModelPin(
        id="docling-layout",
        repo="docling-project/docling-layout-heron",
        repo_host="huggingface",
        revision="0" * 40,
        license="Apache-2.0",
        format="safetensors",
        shipped=True,
    )
    monkeypatch.setattr(
        converter, "pin", lambda name: moved if name == "docling-layout" else _real_pin(name)
    )
    assert parser_cfg_version() != before


def _real_pin(name: str) -> object:
    from app.ingestion.model_pins import pin

    return pin(name)


def test_both_pipeline_models_are_folded_in() -> None:
    """Layout and TableFormer are separate repositories with separate revisions — they were one
    row pointing at one repo until the shas were resolved and the layout model turned out to
    have moved out of it. Dropping either leaves a model whose weights can change with no
    reprocess."""
    assert set(PARSER_MODEL_PINS) == {"docling-layout", "docling-tableformer"}


# ── the canonicalizer these rest on ──────────────────────────────────────────


def test_canonical_forms_do_not_collide_across_types() -> None:
    """`300` and `"300"` and `True` and `1` are otherwise the same bytes, and a config value
    that changed type changed."""
    forms = [canonicalize(v) for v in (300, "300", 300.0, True, 1, None, "")]
    assert len(set(forms)) == len(forms), forms


def test_mapping_key_order_is_irrelevant_and_sequence_order_is_not() -> None:
    """Asymmetric on purpose. A `dict` is unordered by contract, so reordering one must not
    re-version a corpus; a sequence may be an ordered pipeline — the OCR preprocessing chain is
    — and a sort cannot tell that apart from a set-like tuple, so order counts."""
    assert canonicalize({"a": 1, "b": 2}) == canonicalize({"b": 2, "a": 1})
    assert canonicalize(("a", "b")) != canonicalize(("b", "a"))


def test_an_unsupported_type_raises_rather_than_defaulting_to_str() -> None:
    """A `str()` fallback renders any ordinary object through the default repr, which contains a
    memory address — so the config version would change on every process start, re-ingesting the
    whole corpus on every deploy."""
    with pytest.raises(KbError, match="no canonical form"):
        canonicalize(object())
