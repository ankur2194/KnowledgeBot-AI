"""The golden corpus must be the corpus the manifest describes, and the check must not be vacuous.

Three parts, and the second is the one that matters.

The first asserts the **current state** of ``samples/``: ``corpus-2026.08.1`` is authored, so
``verify_corpus()`` passes over ten fixtures and twenty-one files and the exact counts are
pinned here. This paragraph previously described the opposite — every entry a ``"TODO"``
placeholder with no file behind it, ``verify_corpus()`` raising, the failure pinned as a
finding — and it was written to flip on the day the fixtures landed, which is what happened.
The exact counts live here rather than in ``corpus.py``'s floors on purpose: this file is
versioned with the code and updated in the same commit as a corpus change, while a floor that
tracked the corpus exactly would make adding one crawl page a service-code edit.

The second is the positive control, and without it the whole module is worthless. A verifier
that raises unconditionally would satisfy every assertion in the first part while proving
nothing, and a verifier that iterates an empty list exits 0 and logs exactly like one that
verified everything. So this file builds a real, correct corpus in a temporary tree, proves it
verifies, then breaks it one way at a time — a flipped byte, a file added to a directory
fixture, a manifest truncated below the floor, a path aimed outside the corpus — and proves
each one is caught.

The third pins how the corpus is **found**, which is a separate failure surface from whether it
matches. The repo-relative walk raised a bare ``IndexError: 4`` inside the ``runtime-evaluation``
image, where ``WORKDIR /app`` leaves four parent directories; it raised before any corpus code
ran, so the operator got an index error naming nothing and it was not a ``CorpusUnverified``,
which is what maps to ``validation``. Those tests reconstruct the container's path depth
directly, since an image cannot be built from a unit test.

The tree-digest algorithm is re-implemented here rather than imported. It is a wire format
between this verifier and whatever produces the digest for a directory fixture, and a test that
computes it with the code under test cannot tell a correct algorithm from a consistent one.
"""

from __future__ import annotations

import hashlib
import tomllib
from pathlib import Path
from typing import Final

import pytest

from app.evaluation.corpus import (
    MIN_FILES_HASHED,
    MIN_FIXTURES,
    PLACEHOLDER_DIGEST,
    SAMPLES_ROOT_ENV,
    TREE_DIGEST_SCHEME,
    CorpusRootUnresolved,
    CorpusUnverified,
    CorpusVerification,
    _default_samples_root,
    _resolve_samples_root,
    load_manifest,
    read_fixtures,
    verify_corpus,
)
from tests.support.tree import SERVICE_ROOT

#: ``samples/`` in this checkout. Computed from the service root rather than from the module
#: under test, so the arithmetic in ``_default_samples_root`` is something to assert rather
#: than something to inherit.
SAMPLES_ROOT: Final[Path] = SERVICE_ROOT.parents[1] / "samples"

#: The module path inside the `runtime-evaluation` image, reproduced exactly. `WORKDIR /app`
#: puts the package at `/app/app/`, so `Path(...).resolve().parents` yields four entries
#: (`/app/app/evaluation`, `/app/app`, `/app`, `/`) and the old unguarded `parents[4]` raised
#: `IndexError: 4`. Nothing here needs the path to exist — `resolve()` is non-strict — which
#: is what makes the container's failure reproducible in a unit test.
IMAGE_MODULE_PATH: Final[str] = "/app/app/evaluation/corpus.py"


@pytest.fixture(autouse=True)
def _no_ambient_samples_root(monkeypatch: pytest.MonkeyPatch) -> None:
    """Clear ``KB_SAMPLES_ROOT`` for every test in this module.

    Without this, a developer who exports it gets different results from CI, and the
    difference shows up as a corpus digest rather than as a configuration problem. Tests that
    want the variable set it themselves.
    """
    monkeypatch.delenv(SAMPLES_ROOT_ENV, raising=False)


# ─────────────────────────────────────────────────────────────────────────────
# The real corpus, as it stands
# ─────────────────────────────────────────────────────────────────────────────


def test_the_default_root_is_this_repositorys_samples_directory() -> None:
    """``_default_samples_root`` walks four parents up from ``app/evaluation/corpus.py``.

    Off-by-one here resolves to a directory that does not exist, and the run then fails with
    "no corpus manifest" for a reason that has nothing to do with the corpus.
    """
    assert _default_samples_root() == SAMPLES_ROOT
    assert (SAMPLES_ROOT / "corpus" / "manifest.toml").is_file()


def test_the_manifest_declares_at_least_the_floor() -> None:
    """Positive control for every per-fixture assertion below.

    All of them iterate the fixture list; an empty or truncated manifest would make the lot of
    them pass by iterating nothing.
    """
    fixtures = read_fixtures(load_manifest(SAMPLES_ROOT))
    assert len(fixtures) >= MIN_FIXTURES, len(fixtures)


def test_fixture_ids_and_paths_are_unique() -> None:
    """An id reused for different content makes every historical run lie, and two entries
    sharing a path make one of them unverifiable in principle."""
    fixtures = read_fixtures(load_manifest(SAMPLES_ROOT))
    ids = [fixture.fixture_id for fixture in fixtures]
    paths = [fixture.path for fixture in fixtures]
    assert len(set(ids)) == len(ids), sorted(ids)
    assert len(set(paths)) == len(paths), sorted(paths)


def test_every_fixture_is_synthetic() -> None:
    """`samples/README.md`: CI has no organization and therefore no §18.10 privacy switch to
    honour, which is exactly why it may run against this corpus and only this corpus. A row
    claiming otherwise is a recorded decision with consent attached, never a manifest edit."""
    fixtures = read_fixtures(load_manifest(SAMPLES_ROOT))
    assert all(fixture.synthetic for fixture in fixtures)


def test_placeholder_digests_and_missing_files_are_the_same_set() -> None:
    """``sha256 == "TODO"`` if and only if the fixture's path does not exist.

    Both directions are failures. A file present under a still-placeholder entry means bytes
    are being ingested that no digest covers, so a later edit to them is invisible; a real
    digest with no file means the corpus is incomplete and the run will score against a
    smaller corpus than the manifest claims.
    """
    fixtures = read_fixtures(load_manifest(SAMPLES_ROOT))
    inconsistent = [
        fixture.path
        for fixture in fixtures
        if fixture.is_placeholder != (not (SAMPLES_ROOT / fixture.path).exists())
    ]
    assert not inconsistent, inconsistent


def test_the_real_corpus_verifies() -> None:
    """The committed corpus is the corpus the manifest describes.

    This assertion replaced one that pinned the opposite: for as long as the ten fixtures were
    unauthored, it asserted that ``verify_corpus`` raised with ten ``missing`` problems and a
    floor violation reading "0 files were read". That was the honest state and it was written to
    fail the day the corpus landed, which is what happened.

    Twenty-one files rather than ten, because ``site-kelpwright-www`` is a directory fixture
    carrying nine pages plus ``robots.txt`` and ``sitemap.xml``. The count is asserted exactly
    so that a fixture silently disappearing from the tree fails here rather than reducing the
    corpus a run scores against.
    """
    verification = verify_corpus(SAMPLES_ROOT)

    assert verification.corpus_version == "corpus-2026.08.1"
    assert len(verification.fixture_digests) == 10
    assert verification.files_hashed == 21
    assert verification.bytes_hashed > 500_000
    assert sorted(verification.fixture_digests) == [
        "faq-es-v1",
        "handbook-de-v1",
        "handbook-en-v1",
        "pricing-catalog-v1",
        "quarterly-review-q2-2026",
        "scanned-po-88214",
        "site-kelpwright-www",
        "travel-policy-v3",
        "warranty-datasheet-2025",
        "warranty-terms-2026",
    ]


def test_verification_clears_both_non_vacuity_floors_with_margin() -> None:
    """A floor met exactly is a floor one deleted file breaks, which is a different problem
    from a floor that is comfortably clear. Both are reported so the margin is visible."""
    verification = verify_corpus(SAMPLES_ROOT)
    assert len(verification.fixture_digests) >= MIN_FIXTURES
    assert verification.files_hashed >= MIN_FILES_HASHED
    # 21 against a floor of 10. The margin exists because the crawl fixture is a tree.
    assert verification.files_hashed >= 2 * MIN_FILES_HASHED


def test_no_manifest_entry_still_carries_a_placeholder() -> None:
    """``verify_corpus`` already refuses a placeholder; this says so in one line, by id, so a
    half-authored corpus is a readable failure rather than a digest mismatch buried in a list."""
    fixtures = read_fixtures(load_manifest(SAMPLES_ROOT))
    placeholders = [fixture.fixture_id for fixture in fixtures if fixture.is_placeholder]
    assert not placeholders, placeholders


# ─────────────────────────────────────────────────────────────────────────────
# Non-vacuity, asserted on the result type as well as on the function
# ─────────────────────────────────────────────────────────────────────────────


def test_the_floors_are_not_zero() -> None:
    """A floor of zero is the whole failure mode this module exists to prevent, and it is one
    careless edit away at all times."""
    assert MIN_FIXTURES > 0
    assert MIN_FILES_HASHED > 0


def test_a_verification_over_nothing_cannot_be_constructed() -> None:
    """``CorpusVerification`` is the evidence ``EvaluationRunRecord`` accepts.

    If it could be built empty, a caller — or a fixture that later leaked into a real path —
    could hand a run a hollow proof and the run would believe the corpus was checked.
    """
    with pytest.raises(ValueError, match="below the floor"):
        CorpusVerification(
            corpus_version="corpus-2026.08.0",
            manifest_version=1,
            manifest_sha256="0" * 64,
            fixture_digests={},
            files_hashed=0,
            bytes_hashed=0,
        )


def test_a_verification_may_not_record_a_placeholder_as_a_hash() -> None:
    """``"TODO"`` is not a digest, and a verification carrying one would put it into the
    baseline's provenance where it would compare equal to itself forever."""
    digests = {f"fixture-{index}": "a" * 64 for index in range(MIN_FIXTURES - 1)}
    digests["fixture-placeholder"] = PLACEHOLDER_DIGEST
    with pytest.raises(ValueError, match="placeholder digest"):
        CorpusVerification(
            corpus_version="corpus-2026.08.0",
            manifest_version=1,
            manifest_sha256="0" * 64,
            fixture_digests=digests,
            files_hashed=MIN_FILES_HASHED,
            bytes_hashed=1,
        )


# ─────────────────────────────────────────────────────────────────────────────
# A corpus that actually exists — the positive control and the break-it cases
# ─────────────────────────────────────────────────────────────────────────────


def _sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def _sha256_tree(root: Path) -> str:
    """The tree digest, re-implemented from the documented algorithm.

    Independent of ``app.evaluation.corpus`` on purpose: computing the expected value with the
    code under test proves only that the code agrees with itself, and this digest is a format
    the seeder has to reproduce.
    """
    digest = hashlib.sha256()
    digest.update(TREE_DIGEST_SCHEME)
    files = sorted(
        (path for path in root.rglob("*") if path.is_file()),
        key=lambda path: path.relative_to(root).as_posix(),
    )
    for path in files:
        digest.update(path.relative_to(root).as_posix().encode("utf-8"))
        digest.update(b"\0")
        digest.update(_sha256_file(path).encode("ascii"))
        digest.update(b"\n")
    return digest.hexdigest()


def _write_manifest(root: Path, entries: list[tuple[str, str, str]]) -> None:
    lines = ["manifest_version = 1", 'corpus_version = "corpus-test.0"', ""]
    for fixture_id, rel_path, digest in entries:
        lines += [
            "[[fixture]]",
            f'id = "{fixture_id}"',
            f'path = "{rel_path}"',
            f'sha256 = "{digest}"',
            "synthetic = true",
            "",
        ]
    (root / "corpus").mkdir(parents=True, exist_ok=True)
    (root / "corpus" / "manifest.toml").write_text("\n".join(lines), encoding="utf-8")


def _build_corpus(root: Path, *, count: int = MIN_FIXTURES) -> list[tuple[str, str, str]]:
    """``count - 1`` single-file fixtures plus one directory fixture, all with real digests."""
    entries: list[tuple[str, str, str]] = []
    documents = root / "corpus" / "documents"
    for index in range(count - 1):
        rel = f"corpus/documents/doc-{index}/doc-{index}.txt"
        path = root / rel
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(f"fixture body {index}\n", encoding="utf-8")
        entries.append((f"doc-{index}", rel, _sha256_file(path)))

    site = documents / "site" / "www"
    site.mkdir(parents=True, exist_ok=True)
    (site / "index.html").write_text("<p>home</p>", encoding="utf-8")
    (site / "robots.txt").write_text("User-agent: *\n", encoding="utf-8")
    entries.append(("site-tree", "corpus/documents/site/www/", _sha256_tree(site)))

    _write_manifest(root, entries)
    return entries


def test_an_authored_corpus_verifies(tmp_path: Path) -> None:
    """The positive control. Without it, a verifier that raised unconditionally would pass
    every other assertion in this file."""
    _build_corpus(tmp_path)

    verification = verify_corpus(tmp_path)

    assert verification.corpus_version == "corpus-test.0"
    assert len(verification.fixture_digests) == MIN_FIXTURES
    # 9 single-file fixtures + 2 files inside the directory fixture.
    assert verification.files_hashed == 11
    assert verification.bytes_hashed > 0
    assert verification.manifest_sha256 == _sha256_file(tmp_path / "corpus" / "manifest.toml")


def test_one_flipped_byte_fails_verification(tmp_path: Path) -> None:
    """The property the whole manifest exists for. A fixture edited after its digest was
    recorded must abort the run, not warn."""
    _build_corpus(tmp_path)
    victim = tmp_path / "corpus" / "documents" / "doc-3" / "doc-3.txt"
    victim.write_text("fixture body 3 (edited)\n", encoding="utf-8")

    with pytest.raises(CorpusUnverified) as excinfo:
        verify_corpus(tmp_path)

    mismatches = [p for p in excinfo.value.problems if p.kind == "mismatch"]
    assert [p.fixture_id for p in mismatches] == ["doc-3"]


def test_a_directory_fixture_digest_covers_every_file_in_the_tree(tmp_path: Path) -> None:
    """A page added to the crawl fixture changes what a crawl case can retrieve, so it must
    change the digest. A tree digest over the file *list* alone would miss an edited page;
    one over contents alone would miss a renamed one."""
    _build_corpus(tmp_path)
    (tmp_path / "corpus" / "documents" / "site" / "www" / "extra.html").write_text(
        "<p>a page nobody recorded</p>", encoding="utf-8"
    )

    with pytest.raises(CorpusUnverified) as excinfo:
        verify_corpus(tmp_path)

    assert [p.fixture_id for p in excinfo.value.problems if p.kind == "mismatch"] == ["site-tree"]


def test_a_manifest_below_the_floor_fails_even_when_every_entry_matches(tmp_path: Path) -> None:
    """**The non-vacuity assertion.**

    Three fixtures, three correct digests, four files read: every comparison this verifier
    makes succeeds and nothing mismatches. It must still refuse, because "verified everything
    I was told about" is not the same claim as "verified the corpus" — and in a log the two
    are the same line.
    """
    _build_corpus(tmp_path, count=3)

    with pytest.raises(CorpusUnverified) as excinfo:
        verify_corpus(tmp_path)

    problems = excinfo.value.problems
    # Nothing is missing, nothing mismatched, nothing is a placeholder. The floors are the
    # ONLY reason this failed, which is exactly the case a floor-free check would pass.
    assert all(problem.kind == "floor" for problem in problems), [str(p) for p in problems]
    assert any("3 fixtures declared" in problem.detail for problem in problems), problems
    assert any("4 files were read" in problem.detail for problem in problems), problems


def test_a_fixture_path_escaping_the_corpus_is_refused(tmp_path: Path) -> None:
    """The manifest is data. A path that resolves outside ``samples/`` would aim the hasher at
    anything on the machine and record the result as the corpus's provenance."""
    entries = _build_corpus(tmp_path)
    entries[0] = ("doc-0", "../../etc/hostname", "0" * 64)
    _write_manifest(tmp_path, entries)

    with pytest.raises(CorpusUnverified) as excinfo:
        verify_corpus(tmp_path)

    assert [p.fixture_id for p in excinfo.value.problems if p.kind == "escapes_corpus"] == ["doc-0"]


def test_a_file_under_a_placeholder_entry_reports_the_digest_it_should_carry(
    tmp_path: Path,
) -> None:
    """The remedy is the computed digest, so the failure prints it.

    This is also the case that keeps ``PLACEHOLDER_DIGEST`` from being compared as though it
    were a hash: the reason is ``placeholder``, never ``mismatch``, so "not authored yet" never
    reads as "the bytes changed".
    """
    entries = _build_corpus(tmp_path)
    real_digest = entries[0][2]
    entries[0] = (entries[0][0], entries[0][1], PLACEHOLDER_DIGEST)
    _write_manifest(tmp_path, entries)

    with pytest.raises(CorpusUnverified) as excinfo:
        verify_corpus(tmp_path)

    placeholders = [p for p in excinfo.value.problems if p.kind == "placeholder"]
    assert len(placeholders) == 1
    assert real_digest in placeholders[0].detail


def test_a_missing_manifest_is_an_integrity_failure_not_a_file_not_found(tmp_path: Path) -> None:
    """The evaluation image has to ship ``samples/``. When it does not, the run must say the
    corpus is unverifiable rather than raising ``FileNotFoundError`` three frames deeper."""
    with pytest.raises(CorpusUnverified) as excinfo:
        verify_corpus(tmp_path)

    assert [p.kind for p in excinfo.value.problems] == ["manifest_missing"]


def test_the_manifest_parses_as_toml_and_pins_a_corpus_version() -> None:
    """``corpus_version`` is what the gate compares against ``expected/baseline.json``; a run
    whose corpus version differs is refused rather than compared."""
    raw = (SAMPLES_ROOT / "corpus" / "manifest.toml").read_bytes()
    manifest = tomllib.loads(raw.decode("utf-8"))
    assert manifest["corpus_version"] == (SAMPLES_ROOT / "VERSION").read_text().strip()


# ─────────────────────────────────────────────────────────────────────────────
# Finding the corpus — precedence, and the image's IndexError
#
# `_default_samples_root` walked `parents[4]` unguarded. That is right in every checkout and
# raised `IndexError: 4` in `runtime-evaluation`, where `WORKDIR /app` leaves four parents —
# measured in the real container. It raised BEFORE `load_manifest`, so the failure was not a
# `CorpusUnverified`, did not map to `validation` in `kb-error-taxonomy`, and named neither a
# corpus nor a path. These tests pin both directions: the walk still works where it always
# did, and where it cannot work the error has a name and says where it looked.
# ─────────────────────────────────────────────────────────────────────────────


def test_the_repo_relative_walk_is_unchanged_for_a_checkout() -> None:
    """The regression guard on the fix itself.

    `KB_SAMPLES_ROOT` is an ADDITION. Local runs and the CI corpus-integrity gate both call
    `verify_corpus()` with no argument and no variable set, so if the walk stopped being the
    fallback the gate would go red on every PR — which is the failure the gate was wired to
    replace, arriving from the other side.
    """
    root, source = _resolve_samples_root(None)

    assert root == SAMPLES_ROOT == _default_samples_root()
    assert source == "the repository-relative walk"
    assert (root / "corpus" / "manifest.toml").is_file()


def test_a_shallow_module_path_raises_a_named_error_and_never_indexerror(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """THE BUG. Reproduced by pointing the module at the path it has inside the image.

    `IndexError: 4` is the thing that must never come back: it names no corpus, no path and
    no expectation, and it is not a `CorpusUnverified`, so the operator gets an unclassified
    crash instead of a diagnosis.
    """
    monkeypatch.setattr("app.evaluation.corpus.__file__", IMAGE_MODULE_PATH)

    # Precondition: this really is the container's arithmetic, not a contrived shallow path.
    assert len(Path(IMAGE_MODULE_PATH).resolve().parents) == 4

    with pytest.raises(CorpusRootUnresolved) as excinfo:
        _default_samples_root()

    # Subclassing is load-bearing: one taxonomy mapping covers every refusal from this module.
    assert isinstance(excinfo.value, CorpusUnverified)
    assert [p.kind for p in excinfo.value.problems] == ["samples_root_unresolved"]


def test_the_named_error_says_where_it_looked(monkeypatch: pytest.MonkeyPatch) -> None:
    """ "Corpus root not found" without the paths tried is barely better than the IndexError.

    The message has to be actionable from a container log alone, with no repository to hand.
    """
    monkeypatch.setattr("app.evaluation.corpus.__file__", IMAGE_MODULE_PATH)

    with pytest.raises(CorpusRootUnresolved) as excinfo:
        verify_corpus()

    message = str(excinfo.value)
    assert IMAGE_MODULE_PATH in message  # where it actually looked
    assert SAMPLES_ROOT_ENV in message  # and what to set instead
    assert "4 parents up" in message
    assert excinfo.value.attempts  # never constructible without naming its attempts


def test_the_env_var_is_what_makes_the_image_work(monkeypatch: pytest.MonkeyPatch) -> None:
    """The whole point of the variable: a module too shallow to walk still finds the corpus.

    This is the `ai-worker-evaluation` shape — corpus mounted somewhere the package cannot
    infer, named explicitly — with the real corpus standing in for the mount.
    """
    monkeypatch.setattr("app.evaluation.corpus.__file__", IMAGE_MODULE_PATH)
    monkeypatch.setenv(SAMPLES_ROOT_ENV, str(SAMPLES_ROOT))

    root, source = _resolve_samples_root(None)
    assert root == SAMPLES_ROOT
    assert source == f"${SAMPLES_ROOT_ENV}"

    # And end to end, through the entry point `kb.evaluate.start_run` actually calls.
    assert verify_corpus().corpus_version == "corpus-2026.08.1"


def test_an_explicit_argument_outranks_the_environment(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    """A resolver that let the environment override an argument would make a test's result
    depend on the shell that started it."""
    monkeypatch.setenv(SAMPLES_ROOT_ENV, str(tmp_path))

    root, source = _resolve_samples_root(SAMPLES_ROOT)
    assert root == SAMPLES_ROOT
    assert source == "the explicit samples_root argument"


def test_an_empty_env_var_is_refused_rather_than_ignored(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """`Path("")` is `Path(".")` — the "something plausible" this module refuses to resolve to.

    Falling back to the walk would hide the misconfiguration on exactly the machines where the
    walk happens to work, while it stays fatal in the image.
    """
    monkeypatch.setenv(SAMPLES_ROOT_ENV, "   ")

    with pytest.raises(CorpusRootUnresolved) as excinfo:
        _resolve_samples_root(None)

    assert "set but empty" in str(excinfo.value)


def test_a_root_that_is_absent_and_a_root_that_is_not_a_corpus_are_different_kinds(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    """Two faults, two fixes. An absent root is a mount that did not happen or a typo in the
    variable; a root that exists and holds no manifest is the variable aimed one level off.
    Reporting both as one kind sends the reader to the wrong place half the time.
    """
    absent = tmp_path / "never-mounted"
    monkeypatch.setenv(SAMPLES_ROOT_ENV, str(absent))
    with pytest.raises(CorpusUnverified) as missing_root:
        verify_corpus()
    assert [p.kind for p in missing_root.value.problems] == ["samples_root_missing"]

    monkeypatch.setenv(SAMPLES_ROOT_ENV, str(tmp_path))  # exists, holds no corpus
    with pytest.raises(CorpusUnverified) as not_a_corpus:
        verify_corpus()
    assert [p.kind for p in not_a_corpus.value.problems] == ["manifest_missing"]

    # Both name the root AND how it was chosen, so the reader knows which knob to turn.
    for excinfo in (missing_root, not_a_corpus):
        assert f"${SAMPLES_ROOT_ENV}" in str(excinfo.value)
