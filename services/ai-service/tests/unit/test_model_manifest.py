"""The pinned local model weights, and the two ways a pin stops being one.

`models.manifest.toml` is the last content-addressed pin in this service. ADR-030 moved
embedding and reranking onto provider APIs, where a model id is an alias a vendor may re-point
without telling anyone; parsing and OCR stayed local, so their weights are still named by commit
sha and "these weights produced this index" is still a falsifiable claim.

Two failures make it stop being one, and neither shows up as an error anywhere:

* **An unresolved or moving revision.** `revision = "main"` is a branch. `docling-tools models
  download` snapshots whatever it is at build time, so a rebuilt image parses the same corpus
  differently while `docling.__version__` is unchanged and nothing reprocesses. The licence gate
  rejects it; these tests reject it too, closer to the code, and additionally require lowercase,
  because the sha is *hashed* into `parser_cfg_version` and `8F39AD…` would be a different config
  version for identical weights.
* **A revision nothing reads.** A sha in a manifest that no code hands to a downloader and no
  config version folds in is documentation. The last test here is the one that catches that: the
  ids `parsing/converter.py` and `ocr/guarded.py` name must all exist in the manifest, so the two
  files cannot drift apart in either direction.

Imports the constants and reads the file. No containers, no network — a pin check that needs
either stops running on the day it is needed.
"""

from __future__ import annotations

import tomllib
from collections.abc import Iterator
from pathlib import Path
from typing import Any, Final

import pytest

from app.core.errors import ErrorClass, KbError, Origin
from app.ingestion.model_pins import (
    MANIFEST_PATH,
    REQUIRED_PINS,
    REVISION_LENGTH,
    load_pins,
    pin,
)
from app.ingestion.ocr.guarded import OCR_MODEL_PIN
from app.ingestion.parsing.converter import PARSER_MODEL_PINS

#: Mirrors `license_gate.py`'s `NON_PICKLE_FORMATS`, deliberately as a copy rather than an
#: import: the gate lives in `scripts/` and is a separate tool with its own owner. What the copy
#: buys is the assertion below — if the two ever disagree, the failure is a manifest whose
#: exemption the gate does not grant, which reads as a green build until the build runs.
#:
#: Do NOT add `.bin`, `.pt`, `.pth` or `.ckpt`. Those are pickles, `torch.load` on one is
#: arbitrary code execution, and there is no dependency for a scanner to notice.
NON_PICKLE_FORMATS: Final[frozenset[str]] = frozenset({"safetensors", "onnx"})

#: Provenance fields this repository requires beyond what the gate checks. A sha with no record
#: of where it came from cannot be re-derived, and "resolve it again" is then a research task
#: rather than a command.
PROVENANCE_FIELDS: Final[tuple[str, ...]] = ("repo", "repo_host", "resolved_on", "resolved_from")

#: The licences the three shipped rows declare today, pinned so a change is deliberate.
#:
#: `CDLA-Permissive-2.0` is **not** in `scripts/security/policy/licences.toml`'s allow list, so
#: the licence gate blocks on it right now. That is the correct state: the row previously
#: claimed MIT, which is not the licence of those weights, and a dishonest green is worse than
#: an honest red. The policy file belongs to another owner and the required one-line addition is
#: reported rather than made. If this set changes, someone changed which terms we ship to
#: self-hosters, and that is a review rather than a lint.
DECLARED_LICENCES: Final[frozenset[str]] = frozenset({"Apache-2.0", "CDLA-Permissive-2.0"})


def _manifest() -> dict[str, Any]:
    return tomllib.loads(MANIFEST_PATH.read_text(encoding="utf-8"))


def _rows() -> list[dict[str, Any]]:
    return list(_manifest().get("model") or [])


@pytest.fixture
def isolated_pins() -> Iterator[None]:
    """`load_pins` is `@cache`d because the manifest cannot change under a running process.
    Tests that point it at a temporary file must therefore clear it on both sides, or the
    first such test poisons every later reader in the session."""
    load_pins.cache_clear()
    yield
    load_pins.cache_clear()


# ── the file itself ──────────────────────────────────────────────────────────


def test_the_manifest_exists_and_parses() -> None:
    """Its absence is not a skip. The model arm of the licence gate says so in as many words:
    weights are dependencies with no package manager, and a gate that silently checks nothing
    is worse than no gate."""
    assert MANIFEST_PATH.is_file(), MANIFEST_PATH
    assert _rows(), "no [[model]] entries"


def test_every_required_model_has_a_row() -> None:
    """The set is declared in `model_pins`, so a row quietly deleted from the manifest fails
    here rather than producing a config version that silently stopped covering that model."""
    ids = [row.get("id") for row in _rows()]
    assert len(ids) == len(set(ids)), f"duplicate model id in the manifest: {ids}"
    for required in REQUIRED_PINS:
        assert required in ids, f"{required} has no [[model]] entry; found {ids}"


def test_every_revision_is_a_resolved_lowercase_commit_sha() -> None:
    """The test that fails on `TODO-RESOLVE-SHA`, on `main`, and on a tag name.

    A branch or a tag can be moved after the fact; a commit sha cannot. Lowercase is ours and
    not the gate's — see the module docstring.
    """
    for row in _rows():
        revision = str(row["revision"])
        assert len(revision) == REVISION_LENGTH, (
            f"{row['id']}: revision {revision!r} is {len(revision)} characters, not "
            f"{REVISION_LENGTH} — a branch name, a tag, or an unresolved placeholder"
        )
        assert set(revision) <= set("0123456789abcdef"), (
            f"{row['id']}: revision {revision!r} is not lowercase hex"
        )


def test_the_picklescan_exemption_matches_what_the_revision_actually_ships() -> None:
    """`format` is a claim about the container, and the exemption rides on it.

    safetensors is a length-prefixed tensor blob and ONNX is protobuf; neither executes on load.
    A row claiming one of those while the pinned revision ships a `.bin` would exempt a pickle
    from scanning, which is a supply-chain path no dependency scanner watches because there is
    no dependency. Anything outside the exempt set must carry a recorded picklescan pass.
    """
    for row in _rows():
        if row["format"] in NON_PICKLE_FORMATS:
            continue
        assert row.get("picklescan_clean"), (
            f"{row['id']}: format {row['format']!r} is a pickle container and has no recorded "
            "picklescan pass"
        )


def test_every_shipped_row_records_where_its_sha_came_from() -> None:
    """A sha nobody can re-derive is a sha nobody will ever update.

    `resolved_from` is the load-bearing one: `docling-tableformer` is pinned to the commit its
    *tag* names because that is the revision docling's own downloader requests, and without the
    field that choice looks like main's head being out of date.
    """
    for row in _rows():
        if not row.get("shipped"):
            continue
        for field in PROVENANCE_FIELDS:
            assert row.get(field), f"{row['id']}: missing provenance field `{field}`"
        assert row.get("notes"), f"{row['id']}: no notes recording how the pin was verified"


def test_the_declared_licences_are_the_reviewed_ones() -> None:
    """Weight licences are invisible to every dependency scanner, because there is no
    dependency. Surya is the standing example of code and weights carrying different terms, and
    two rows here claimed MIT — docling's *source* licence — for weights that are Apache-2.0 and
    CDLA-Permissive-2.0. See `DECLARED_LICENCES` for the policy consequence."""
    shipped = {row["license"] for row in _rows() if row.get("shipped")}
    assert shipped == DECLARED_LICENCES, (
        f"the shipped weight licences changed to {sorted(shipped)}; that is a decision about "
        "terms a self-hoster inherits without agreeing to them, not a lint"
    )


# ── the loader ───────────────────────────────────────────────────────────────


def test_the_loader_returns_the_shas_the_file_holds() -> None:
    pins = load_pins()
    by_id = {row["id"]: row for row in _rows()}
    assert set(pins) == set(REQUIRED_PINS)
    for identifier, loaded in pins.items():
        assert loaded.revision == by_id[identifier]["revision"]
        assert loaded.repo == by_id[identifier]["repo"]


def test_a_fingerprint_carries_the_repo_as_well_as_the_sha() -> None:
    """Forty hex characters mean nothing without the repository they index into — and the
    `docling-layout` row has already pointed at the wrong repository once, at which point a
    perfectly valid sha would have named perfectly valid weights that are not the layout model.
    """
    layout = pin("docling-layout")
    assert layout.revision in layout.fingerprint
    assert layout.repo in layout.fingerprint
    assert layout.repo_host in layout.fingerprint


def test_an_unknown_pin_raises_rather_than_returning_none() -> None:
    """A caller treating a missing pin as "no revision to fold in" composes a config version
    that cannot detect a weight change — the exact defect the pin exists to prevent."""
    with pytest.raises(KbError) as caught:
        pin("bge-m3")
    assert caught.value.error_class is ErrorClass.INTERNAL_DEPENDENCY
    assert caught.value.origin is Origin.SELF
    assert not caught.value.retryable


@pytest.mark.parametrize(
    ("mutation", "expected"),
    [
        pytest.param('revision = "TODO-RESOLVE-SHA"', "commit sha", id="placeholder"),
        pytest.param('revision = "main"', "commit sha", id="branch"),
        pytest.param(
            'revision = "8F39AD3C0B4C58E9C2D2C84A38465ABF757272D8"', "commit sha", id="uppercase"
        ),
        pytest.param("shipped = false", "shipped = false", id="unshipped"),
    ],
)
def test_the_loader_rejects_a_pin_that_is_not_one(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
    isolated_pins: None,
    mutation: str,
    expected: str,
) -> None:
    """Each mutation is a real state this file has been in or could reach, and each one type-
    checks, parses as TOML and reads as a filled-in row."""
    text = MANIFEST_PATH.read_text(encoding="utf-8")
    original_line = next(
        line for line in text.splitlines() if line.startswith(mutation.split(" =")[0] + " =")
    )
    broken = tmp_path / "models.manifest.toml"
    broken.write_text(text.replace(original_line, mutation, 1), encoding="utf-8")

    monkeypatch.setattr("app.ingestion.model_pins.MANIFEST_PATH", broken)
    with pytest.raises(KbError) as caught:
        load_pins()
    assert expected in str(caught.value)
    assert caught.value.error_class is ErrorClass.INTERNAL_DEPENDENCY


def test_a_missing_row_is_named_not_silently_skipped(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch, isolated_pins: None
) -> None:
    """The dangerous shape of this failure: a `parser_cfg_version` that still composes fine and
    simply stopped covering one model's weights."""
    text = MANIFEST_PATH.read_text(encoding="utf-8")
    broken = tmp_path / "models.manifest.toml"
    broken.write_text(text.replace('id = "rapidocr-onnx"', 'id = "rapidocr-onnx-old"', 1), "utf-8")

    monkeypatch.setattr("app.ingestion.model_pins.MANIFEST_PATH", broken)
    with pytest.raises(KbError, match="rapidocr-onnx"):
        load_pins()


# ── the manifest and the code that reads it ──────────────────────────────────


def test_every_pin_the_ingestion_code_names_exists_in_the_manifest() -> None:
    """The drift test, and the reason `PARSER_MODEL_PINS` and `OCR_MODEL_PIN` are declared as
    data at all. A renamed manifest row with no matching rename here produces a stage that pins
    nothing; a stage naming an id the manifest lost produces the same. Both are silent."""
    ids = {row["id"] for row in _rows()}
    for identifier in (*PARSER_MODEL_PINS, OCR_MODEL_PIN):
        assert identifier in ids, f"{identifier!r} is read by ingestion but has no manifest row"


def test_the_required_set_and_the_stages_agree() -> None:
    """`REQUIRED_PINS` is what the loader insists on; the two stage tuples are what the stages
    fold in. A pin required but folded into nothing is a revision that changes no config
    version — which is a pin in name only."""
    assert set(REQUIRED_PINS) == {*PARSER_MODEL_PINS, OCR_MODEL_PIN}
