"""Corpus integrity — what makes a golden score attributable to specific bytes.

``samples/corpus/manifest.toml`` names every fixture document and records its digest. This
module recomputes those digests from the files on disk and refuses the run when any of them
disagrees. Without it the manifest is documentation: a fixture can be edited, re-exported
from a different Word version, or silently truncated by a bad checkout, and every score
produced afterwards is a measurement of bytes nobody can name.

WHY THIS IS A GATE AND NOT A WARNING
------------------------------------
A regression gate exists to answer one question — *did this change make retrieval worse* —
and it can only answer it if the corpus held still. A mismatched fixture changes the answer
to a question that changed, which is exactly the failure the corpus/code version split in
``samples/README.md`` was designed to prevent. Warning and continuing produces a number that
looks like every other number in the series and is not comparable to any of them.

THE VACUITY PROBLEM, WHICH IS PERMANENT AND IS THE REAL RISK HERE
-----------------------------------------------------------------
A verifier that iterates an empty list exits 0. In a log it is indistinguishable from one
that verified everything: same exit code, same "corpus verified" line, same green check.

That is a property of hash-checking loops, not a property of any particular state of this
repository, and it does not expire when the fixtures are authored. **The floors below are
therefore permanent, and a reader who finds the corpus complete and concludes they were
scaffolding for a transitional state is about to delete the only thing standing between a
green check and a check of nothing.**

Be precise about which failures are vacuous, because most are not and the distinction is what
makes the floors defensible rather than decorative. A missing file, a changed byte, an empty
directory fixture, a path escaping the corpus, a ``samples/`` the image never copied — every
one of those is *loud*, reported per fixture by ``_verify_fixture`` and ``load_manifest``
below, and none of them needs a floor. What is silent is anything that leaves the loop with
nothing to iterate:

* a manifest whose ``[[fixture]]`` array is truncated or gone — a badly resolved merge, an
  editor that rewrote the file, a generator run that wrote it empty. ``read_fixtures``
  returns an empty tuple, the per-fixture checks are never *reached*, and there is not one
  thing in the loop left to disagree with.
* a manifest holding two rows instead of ten, both of which verify perfectly.
* a ``CorpusVerification`` built by hand in a test fixture and handed to a run record as
  though it were evidence.
* any future rewrite of this loop that skips a malformed row rather than raising on it —
  ``read_fixtures`` deliberately refuses to, and says why. Skip enough rows and the loop is
  vacuous again with every individual check still intact.

In every one of those the loop runs to completion, finds nothing it disagrees with, and
reports success. A naive "hash every file under ``documents/`` and compare" check does the
same thing on a tree with no documents in it.

So there are three floors, and none of them is temporary:

1. ``MIN_FIXTURES`` — the manifest must declare at least this many entries. Catches a
   manifest truncated to two rows.
2. ``MIN_FILES_HASHED`` — at least this many files must have been *opened and read*. This is
   the one that separates "verified ten fixtures" from "found ten rows pointing at nothing":
   ``MIN_FIXTURES`` counts rows in a TOML file, and a row costs nothing to write.
3. ``CorpusVerification.__post_init__`` re-asserts both. The result object cannot be
   constructed empty, so a caller cannot hand a hollow verification to a run record and have
   the run believe the corpus was checked.

A floor is a number maintained by hand, and that is the point: raising it is a deliberate act
recorded in a diff, while a check that derives its expectations from whatever it happens to
find can only ever agree with itself.

**The floors are minima, not counts, and they are deliberately below the corpus's true
figures.** The exact numbers — ten fixtures, twenty-one files — are pinned in
``tests/unit/test_corpus_integrity.py``, which is versioned with the code and updated in the
same commit as any corpus change. Pinning them here instead would put the crawl fixture's page
count inside a constant in the service, so adding one page to ``site-kelpwright-www`` would
require a code edit — the corpus/code coupling ``samples/README.md`` exists to prevent. The
division is: the floors here refuse a vacuous run forever; the test asserts today's shape and
moves when the corpus does.

DIRECTORY FIXTURES
------------------
``site-kelpwright-www`` is a static HTML tree rather than one file, and its manifest entry's
digest is a **tree digest**. ``TREE_DIGEST_SCHEME`` and ``_tree_digest`` below define it
exactly, byte for byte, because the seeder and this verifier must agree and "a digest over
the sorted file list and per-file digests" admits half a dozen incompatible implementations.

WHERE THIS RUNS
---------------
``app/evaluation/tasks.py`` calls ``verify_corpus()`` as the first thing an evaluation run
does, before a single fixture is ingested — and ``EvaluationRunRecord`` (``app/evaluation/
run.py``) cannot be constructed without the ``CorpusVerification`` it returns. That second
part is what keeps the check from being skippable: there is no path to a comparable run
record that does not carry the proof.

It is also cheap enough to run outside a run. The module's whole import closure is
``hashlib``, ``tomllib``, ``dataclasses`` and ``pathlib`` — no Qdrant, no PostgreSQL, no
provider credential, no ``evaluation`` extra, no ``ragas`` — so ``verify_corpus()`` is a
standalone integrity check that any CI job with a checkout and an interpreter can run in
milliseconds. **Corpus integrity is not a retrieval regression gate**, and wiring one must not
be read as having delivered the other: this proves the corpus is the corpus the manifest
describes, and says nothing whatever about retrieval quality, which needs the query pipeline
to exist first.

FINDING THE CORPUS, AND THE BUG THAT TAUGHT US THE WALK IS NOT ENOUGH
---------------------------------------------------------------------
Three sources, in this precedence: an **explicit argument**, then ``$KB_SAMPLES_ROOT``, then
a **repository-relative walk** from ``__file__``. ``_resolve_samples_root`` carries the full
reasoning for the order; the short version is that the argument is a decision already made,
the variable is the deployment telling us, and the walk is the only one of the three that
guesses — so it goes last and it is never mandatory to skip it.

The walk used to be the only source, and it raised **``IndexError: 4``** in the
``runtime-evaluation`` image, where ``WORKDIR /app`` puts this module at
``/app/app/evaluation/corpus.py`` with four parents rather than the eight-or-so a checkout
has. It raised before ``load_manifest`` ran, so the operator got an index error naming no
corpus and no path, and ``kb.evaluate.start_run`` did not raise ``CorpusUnverified`` and
therefore did not map to ``validation`` in ``kb-error-taxonomy``.

Two things are worth keeping from that, because both generalise:

* **A fixed-depth walk encodes an assumption about where this file lives.** Resolving from
  ``__file__`` rather than the working directory moves that assumption; it does not remove
  it. The old docstring argued the walk was safe against a different invocation context and
  was then broken by a different invocation context.
* **The failure was unnamed, which is the part that actually cost something.** A corpus that
  cannot be found and a corpus that disagrees with its manifest are both refusals, and both
  must arrive as ``CorpusUnverified`` so one taxonomy mapping covers them.
  ``CorpusRootUnresolved`` is that error, it subclasses ``CorpusUnverified``, and it is
  required to carry every path it tried and why each one failed.

``services/ai-service/Dockerfile`` and ``infrastructure/docker/compose.yaml`` belong to
``platform-devops-engineer``; this package's job is to honour a root it is given and to fail
by name when it is given none.
"""

from __future__ import annotations

import hashlib
import os
import tomllib
from collections.abc import Iterator, Mapping
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Final

__all__ = [
    "MIN_FILES_HASHED",
    "MIN_FIXTURES",
    "PLACEHOLDER_DIGEST",
    "SAMPLES_ROOT_ENV",
    "TREE_DIGEST_SCHEME",
    "CorpusRootUnresolved",
    "CorpusUnverified",
    "CorpusVerification",
    "Fixture",
    "FixtureProblem",
    "load_manifest",
    "manifest_digest",
    "read_fixtures",
    "verify_corpus",
]

#: The literal a manifest entry carries while its fixture has not been authored. It is not a
#: digest and must never be compared against one — an entry holding it fails the run with its
#: own reason, so "the fixture does not exist yet" never reads as "the bytes changed".
#:
#: No entry carries it as of ``corpus-2026.08.1``, and that does not retire it. It is the
#: authoring affordance for fixture eleven and every fixture after: a new row lands with
#: ``sha256 = "TODO"``, ``_verify_fixture`` reports ``placeholder`` *and prints the digest the
#: file on disk actually hashes to*, and that printed line is what gets pasted into the
#: manifest. Delete this and a half-authored row is reported as a ``mismatch`` — a diagnosis
#: that is not merely less helpful but wrong, since nothing changed under anyone.
PLACEHOLDER_DIGEST: Final[str] = "TODO"

#: Environment variable naming the corpus root explicitly. Consulted after an explicit
#: argument and before the repository-relative walk.
#:
#: It exists because that walk is a **guess about directory layout**, and there is a shipped
#: deployment where the guess is not merely wrong but unrepresentable. In the
#: ``runtime-evaluation`` image ``WORKDIR`` is ``/app`` and this module sits at
#: ``/app/app/evaluation/corpus.py``, so ``Path(__file__).resolve().parents`` has exactly four
#: entries (indices 0–3) and ``parents[4]`` raised ``IndexError: 4`` — before ``load_manifest``
#: was reached, so nothing named a corpus, a path, or an expectation, and the failure did not
#: map to ``validation`` in ``kb-error-taxonomy`` because it was not a ``CorpusUnverified`` at
#: all. Measured in the real container by ``platform-devops-engineer``, not inferred.
#:
#: A mount alone cannot fix that: ``kb.evaluate.start_run`` calls ``verify_corpus()`` with no
#: argument, and the walk's depth is a property of ``WORKDIR``, not of what is mounted where.
#: The corpus has to be *named*, which is what this variable does.
SAMPLES_ROOT_ENV: Final[str] = "KB_SAMPLES_ROOT"

#: Parents between this module and the repository root: ``app/evaluation`` -> ``app`` ->
#: ``ai-service`` -> ``services`` -> the root. Named rather than inlined so the walk and the
#: bounds check that guards it cannot drift apart — they were one expression before, and the
#: expression had no bounds check.
_REPO_ROOT_DEPTH: Final[int] = 4

#: Versioned because it is a wire format between two programs — this verifier and whatever
#: produces the digest for a directory fixture. Changing the algorithm without changing this
#: string makes every tree digest silently wrong in one direction only.
TREE_DIGEST_SCHEME: Final[bytes] = b"kb-corpus-tree/v1\n"

#: The manifest must declare at least this many fixtures.
#:
#: 10 is the fixture count of ``corpus-2026.08.1`` and of ``samples/golden/dataset.toml``'s
#: ``fixture_count``, so this floor currently sits *exactly* at the corpus's own figure and
#: one removed row breaks it. That tightness is fine and is not what makes the floor work:
#: removing a fixture is legitimate, and it is then a two-line change — the manifest and this
#: number — which is a diff someone reviews. What the floor rules out is the version of that
#: edit nobody made on purpose.
#:
#: Stated as a literal rather than read from the manifest, because a floor derived from the
#: file it is checking agrees with that file by construction and rules out nothing.
MIN_FIXTURES: Final[int] = 10

#: At least this many files must have been opened and read for a verification to count.
#:
#: This is the floor that matters, and its job is anti-vacuity rather than anti-shrinkage.
#: ``MIN_FIXTURES`` counts rows in a TOML file, which cost nothing to write; this counts bytes
#: actually pulled off disk, and it is the only check in the module that can tell "verified
#: ten fixtures" from "found ten rows pointing at nothing".
#:
#: It is deliberately below the real figure and stays there. Each single-file fixture
#: contributes exactly one; ``site-kelpwright-www`` is a tree contributing its nine pages plus
#: ``sitemap.xml`` and ``robots.txt``, so ``corpus-2026.08.1`` hashes 21 files against this
#: floor of 10. **Do not "fix" that margin by raising this to 21.** The margin is the whole
#: reason the number can stay still while the crawl fixture gains a page — a floor pinned to
#: the tree's page count would make adding an HTML file a code change. Shrinkage is already
#: covered, per fixture and with a far better message, by the ``missing``, ``mismatch`` and
#: ``empty_tree`` problems below; the exact 21 is asserted in
#: ``tests/unit/test_corpus_integrity.py``, where it belongs.
MIN_FILES_HASHED: Final[int] = 10


@dataclass(frozen=True, slots=True)
class Fixture:
    """One ``[[fixture]]`` row, reduced to the fields integrity depends on.

    Deliberately not the whole row. ``format``, ``page_count``, ``licence`` and ``notes`` are
    documentation for a human authoring the artefact; carrying them here would invite this
    module to start validating them, and a page count that disagrees with a PDF is a fixture
    problem rather than an integrity failure.
    """

    fixture_id: str
    path: str
    sha256: str
    synthetic: bool

    @property
    def is_placeholder(self) -> bool:
        return self.sha256 == PLACEHOLDER_DIGEST


@dataclass(frozen=True, slots=True)
class FixtureProblem:
    """One reason the corpus is not the corpus the manifest describes.

    Problems are **collected**, never raised on the first one. A verifier that aborts on
    fixture 1 of 10 hides the state of the other nine, and the first thing anyone does with
    that output is fix one fixture and run it again — nine more times.
    """

    fixture_id: str
    path: str
    kind: str
    detail: str

    def __str__(self) -> str:
        return f"{self.fixture_id or '<manifest>'} [{self.kind}] {self.path}: {self.detail}"


class CorpusUnverified(Exception):
    """The corpus on disk is not the corpus the manifest describes, so no run may start.

    Carries every problem found, not the first. ``kb-error-taxonomy`` class is ``validation``
    at the call site: nothing is broken upstream, the inputs to this run are wrong, and
    retrying sends the identical bytes to the identical check.
    """

    def __init__(self, problems: tuple[FixtureProblem, ...]) -> None:
        self.problems = problems
        listing = "\n  ".join(str(problem) for problem in problems)
        super().__init__(
            f"corpus verification failed with {len(problems)} problem(s):\n  {listing}"
        )


class CorpusRootUnresolved(CorpusUnverified):
    """No ``samples/`` root could be determined at all — a *deployment* fault, not a corpus one.

    Every other problem in this module describes a corpus that was found and disagreed with
    the manifest. Here there was nothing to disagree with, and the distinction is the one an
    operator needs first: "your corpus changed under you" and "this process cannot see a
    corpus" have completely different fixes.

    It subclasses ``CorpusUnverified`` deliberately. That keeps one mapping to ``validation``
    in ``kb-error-taxonomy`` — nothing upstream is broken, this run's inputs are wrong, and a
    retry sends the identical configuration to the identical check — so no existing
    ``except CorpusUnverified`` has to learn a second type, while a caller that wants to tell
    an operator to set an environment variable can still catch this one specifically.

    ``attempts`` is ``(source, why it did not work)`` in the order tried, and it is required
    rather than optional: an error saying "corpus root not found" without saying where it
    looked is barely an improvement on the ``IndexError`` it replaced.
    """

    def __init__(self, attempts: tuple[tuple[str, str], ...]) -> None:
        if not attempts:
            raise ValueError("CorpusRootUnresolved must name what it tried")
        self.attempts = attempts
        tried = "; ".join(f"{source} -> {why}" for source, why in attempts)
        super().__init__(
            (
                FixtureProblem(
                    fixture_id="",
                    path="<no root resolved>",
                    kind="samples_root_unresolved",
                    detail=(
                        f"no corpus root could be resolved. Tried, in order: {tried}. "
                        f"Set {SAMPLES_ROOT_ENV} to the directory containing "
                        f"corpus/manifest.toml, or run from a repository checkout"
                    ),
                ),
            )
        )


@dataclass(frozen=True, slots=True)
class CorpusVerification:
    """Proof that a specific corpus was read and matched, and the provenance a run records.

    Only ``verify_corpus`` produces one legitimately, and ``__post_init__`` re-asserts the
    floors so a hand-built empty instance cannot stand in for a real check. That matters
    because this object is what ``EvaluationRunRecord`` accepts as evidence; a type that can
    be constructed empty is a type that will eventually be constructed empty in a fixture and
    then leak into a real path.

    ``manifest_sha256`` is recorded separately from the fixture digests, and both go into the
    baseline. The fixture digests catch changed bytes; the manifest digest catches a changed
    *expectation* — an edited threshold, a retired id, a swapped path — which leaves every
    fixture digest matching while the corpus means something different.
    """

    corpus_version: str
    manifest_version: int
    manifest_sha256: str
    fixture_digests: Mapping[str, str]
    files_hashed: int
    bytes_hashed: int

    def __post_init__(self) -> None:
        if len(self.fixture_digests) < MIN_FIXTURES:
            raise ValueError(
                f"a verification over {len(self.fixture_digests)} fixtures is below the "
                f"floor of {MIN_FIXTURES}; an empty check logs exactly like a passing one"
            )
        if self.files_hashed < MIN_FILES_HASHED:
            raise ValueError(
                f"a verification that read {self.files_hashed} files is below the floor of "
                f"{MIN_FILES_HASHED}; it verified nothing and must not be recorded as proof"
            )
        placeholders = sorted(
            fixture_id
            for fixture_id, digest in self.fixture_digests.items()
            if digest == PLACEHOLDER_DIGEST
        )
        if placeholders:
            raise ValueError(
                f"{placeholders} carry the placeholder digest — a verification may not record "
                f"{PLACEHOLDER_DIGEST!r} as though it were a hash"
            )


def _default_samples_root() -> Path:
    """``samples/`` as it sits in a repository checkout, or ``None``-safe failure by name.

    ``app/evaluation/corpus.py`` -> ``services/ai-service/app/evaluation`` -> repo root is
    ``_REPO_ROOT_DEPTH`` parents up. Resolved from ``__file__`` rather than from the working
    directory, because a check that only passes when it was invoked from the service
    directory fails in the one CI job that invokes it from the repository root.

    **The bounds check is the fix, not decoration.** This function previously indexed
    ``parents[4]`` unguarded, which is correct in every checkout and raises ``IndexError: 4``
    in the ``runtime-evaluation`` image — see ``SAMPLES_ROOT_ENV``. The irony is worth
    recording: the docstring argued that resolving from ``__file__`` protects against a
    different invocation context, and then broke in a different invocation context. A walk
    up a fixed number of parents encodes an assumption about where the file lives, and
    ``__file__`` moves that assumption rather than removing it.

    Raises ``CorpusRootUnresolved`` rather than ``IndexError`` when the walk cannot be made.
    """
    here = Path(__file__).resolve()
    if len(here.parents) <= _REPO_ROOT_DEPTH:
        raise CorpusRootUnresolved(
            (
                (
                    f"the repository-relative walk ({_REPO_ROOT_DEPTH} parents up from {here})",
                    f"only {len(here.parents)} parent directories exist, so this module is not "
                    f"inside a repository checkout — the usual cause is a container image "
                    f"whose WORKDIR puts the package near the filesystem root",
                ),
            )
        )
    return here.parents[_REPO_ROOT_DEPTH] / "samples"


def _resolve_samples_root(samples_root: Path | None) -> tuple[Path, str]:
    """The corpus root and a short description of **how it was chosen**.

    Precedence, and the reason for each position:

    1. **An explicit argument.** The caller has already decided; this function must never
       second-guess it. Every test and the ``verify_corpus(Path(...))`` call an operator
       makes by hand go through here, and a resolver that overrode them from the environment
       would make a test's behaviour depend on the shell that started it.
    2. **``$KB_SAMPLES_ROOT``.** The deployment's decision, and it outranks the walk because
       being *told* where the corpus is beats inferring it from directory layout. This is the
       position that makes the ``runtime-evaluation`` image work at all.
    3. **The repository-relative walk.** Last, because it is the only one of the three that
       guesses. It is kept, unchanged, so a checkout and CI keep working with no environment
       set — this is an addition, not a replacement, and making the variable mandatory would
       break the corpus-integrity gate the moment it landed.

    The description is returned rather than discarded because "the manifest is not there" is
    a different instruction to an operator depending on whether the root came from an
    environment variable they can fix or from a checkout they need to complete.
    """
    if samples_root is not None:
        return samples_root, "the explicit samples_root argument"

    raw = os.environ.get(SAMPLES_ROOT_ENV)
    if raw is not None and not raw.strip():
        # Set-but-empty is a broken deployment, and falling through to the walk would hide
        # it on precisely the machines where the walk happens to work — a developer's
        # checkout — while it stays broken in the image. `Path("")` is `Path(".")`, which is
        # the "something plausible" this module refuses to resolve to.
        raise CorpusRootUnresolved(
            (
                (
                    f"${SAMPLES_ROOT_ENV}",
                    "set but empty or whitespace; an explicitly configured root that would "
                    "resolve to the working directory is refused rather than ignored, "
                    "because ignoring it hides the misconfiguration everywhere it is not fatal",
                ),
            )
        )
    if raw is not None:
        return Path(raw), f"${SAMPLES_ROOT_ENV}"

    return _default_samples_root(), "the repository-relative walk"


def load_manifest(samples_root: Path | None = None) -> dict[str, Any]:
    """Parse ``corpus/manifest.toml``. Raises ``CorpusUnverified`` if it is not there.

    A missing manifest is an integrity failure and not a file-not-found: it means the run is
    about to score against a corpus whose contents nothing describes.

    The two ways that happens are reported as **different kinds**, because they are different
    instructions to whoever is reading. ``samples_root_missing`` means the root itself is not
    there — a mount that did not happen, or an environment variable with a typo in it.
    ``manifest_missing`` means the root exists and holds no manifest — a real directory that
    is not a corpus, which is what pointing the variable one level too high or too low looks
    like. Both name the root *and how it was chosen*, so the reader knows whether to fix a
    variable, a mount, or a checkout.
    """
    root, source = _resolve_samples_root(samples_root)
    return _load_manifest_at(root, source)


def _load_manifest_at(root: Path, source: str) -> dict[str, Any]:
    """``load_manifest`` with the provenance carried in rather than re-derived.

    This split exists because the obvious shape is wrong in a way that only shows up in the
    message. ``verify_corpus`` resolves the root once — it must, or a changed environment
    mid-run could give one verification two different roots — and then has to hand that root
    to this function. If it handed it to the *public* ``load_manifest``, resolution would run
    a second time, see an explicit argument, and truthfully report "the explicit samples_root
    argument" — so every failure from the entry point ``kb.evaluate.start_run`` actually calls
    would name the wrong source, and the operator would go looking for a caller that does not
    exist instead of at the environment variable they need to set.
    """
    path = root / "corpus" / "manifest.toml"
    if not path.is_file():
        if not root.is_dir():
            kind = "samples_root_missing"
            detail = (
                f"the corpus root {root} (from {source}) does not exist or is not a "
                f"directory. The eval image must ship or mount samples/, and "
                f"{SAMPLES_ROOT_ENV} must name it"
            )
        else:
            kind = "manifest_missing"
            detail = (
                f"the corpus root {root} (from {source}) exists but holds no "
                f"corpus/manifest.toml, so it is a directory rather than a corpus"
            )
        raise CorpusUnverified(
            (FixtureProblem(fixture_id="", path=str(path), kind=kind, detail=detail),)
        )
    return tomllib.loads(path.read_text(encoding="utf-8"))


def read_fixtures(manifest: Mapping[str, Any]) -> tuple[Fixture, ...]:
    """The ``[[fixture]]`` rows, in manifest order, with the required keys present.

    A row missing ``id``, ``path`` or ``sha256`` raises ``KeyError`` here rather than being
    skipped. Skipping is how a corpus quietly shrinks: the malformed row stops being verified
    and stops being ingested, several cases go unanswerable, and the score moves for a reason
    no diff shows.
    """
    rows = manifest.get("fixture", [])
    return tuple(
        Fixture(
            fixture_id=str(row["id"]),
            path=str(row["path"]),
            sha256=str(row["sha256"]),
            synthetic=bool(row.get("synthetic", False)),
        )
        for row in rows
    )


def manifest_digest(samples_root: Path | None = None) -> str:
    """sha256 over the manifest's bytes. Recorded beside the fixture digests, never instead."""
    root, _source = _resolve_samples_root(samples_root)
    return _file_digest(root / "corpus" / "manifest.toml")[0]


def _file_digest(path: Path) -> tuple[str, int]:
    """``(hex digest, bytes read)``, streamed so a large fixture is not held in memory."""
    digest = hashlib.sha256()
    size = 0
    with path.open("rb") as handle:
        while chunk := handle.read(1024 * 1024):
            digest.update(chunk)
            size += len(chunk)
    return digest.hexdigest(), size


def _tree_files(root: Path) -> Iterator[Path]:
    """Every regular file under ``root``, sorted by POSIX-relative path.

    Sorted on the *relative* path so the digest does not depend on where the checkout lives.
    Symlinks are excluded by ``is_file()`` following them — a symlink into the corpus is
    refused separately, in ``_verify_fixture``, because a fixture that resolves outside the
    corpus is not reproducible from this repository at all.
    """
    return iter(
        sorted(
            (p for p in root.rglob("*") if p.is_file()),
            key=lambda p: p.relative_to(root).as_posix(),
        )
    )


def _tree_digest(root: Path) -> tuple[str, int, int]:
    """``(hex digest, files hashed, bytes hashed)`` over a directory fixture.

    The algorithm, stated exactly because the seeder must reproduce it:

        h = sha256(TREE_DIGEST_SCHEME)
        for each regular file, in ascending POSIX relative-path order:
            h.update(relative path, UTF-8)
            h.update(b"\\0")
            h.update(the file's own sha256 hex digest, ASCII)
            h.update(b"\\n")

    The NUL separator is not decoration: without a delimiter, ``a/bc`` + digest and ``a/b`` +
    ``c`` + digest hash the same bytes, so two different trees collide. Empty directories are
    invisible to it, which is correct — an empty directory carries no content a question can
    be grounded in.
    """
    digest = hashlib.sha256()
    digest.update(TREE_DIGEST_SCHEME)
    files = 0
    total = 0
    for path in _tree_files(root):
        file_hex, size = _file_digest(path)
        digest.update(path.relative_to(root).as_posix().encode("utf-8"))
        digest.update(b"\0")
        digest.update(file_hex.encode("ascii"))
        digest.update(b"\n")
        files += 1
        total += size
    return digest.hexdigest(), files, total


def _verify_fixture(
    fixture: Fixture, samples_root: Path
) -> tuple[str | None, int, int, list[FixtureProblem]]:
    """One fixture: its computed digest, what it cost to compute, and what went wrong."""
    problems: list[FixtureProblem] = []
    candidate = (samples_root / fixture.path).resolve()
    root = samples_root.resolve()

    # The manifest is data. A path that escapes `samples/` would let an edit to a data file
    # aim the hasher at anything on the machine, and the digest it produced would then be
    # recorded as the corpus's provenance.
    if not candidate.is_relative_to(root):
        problems.append(
            FixtureProblem(
                fixture_id=fixture.fixture_id,
                path=fixture.path,
                kind="escapes_corpus",
                detail=f"resolves to {candidate}, outside {root}",
            )
        )
        return None, 0, 0, problems

    if not candidate.exists():
        problems.append(
            FixtureProblem(
                fixture_id=fixture.fixture_id,
                path=fixture.path,
                kind="missing",
                detail=(
                    "the manifest names a fixture that is not in the tree. This is a finding, "
                    "not a row to delete and not a digest to invent"
                ),
            )
        )
        return None, 0, 0, problems

    if candidate.is_dir():
        computed, files, size = _tree_digest(candidate)
        if files == 0:
            problems.append(
                FixtureProblem(
                    fixture_id=fixture.fixture_id,
                    path=fixture.path,
                    kind="empty_tree",
                    detail="directory fixture contains no files; its digest would be a constant",
                )
            )
    elif candidate.is_file():
        file_hex, size = _file_digest(candidate)
        computed, files = file_hex, 1
    else:
        problems.append(
            FixtureProblem(
                fixture_id=fixture.fixture_id,
                path=fixture.path,
                kind="not_a_file",
                detail="neither a regular file nor a directory",
            )
        )
        return None, 0, 0, problems

    if fixture.is_placeholder:
        # A file under a still-`TODO` entry is the inverse of a missing fixture and just as
        # bad: bytes are being ingested that no digest covers, so a later edit to them is
        # invisible. Report the computed digest in the detail — it is exactly what belongs in
        # the manifest, and printing it is the whole remedy.
        problems.append(
            FixtureProblem(
                fixture_id=fixture.fixture_id,
                path=fixture.path,
                kind="placeholder",
                detail=f"sha256 is {PLACEHOLDER_DIGEST!r}; the file on disk hashes to {computed}",
            )
        )
    elif computed != fixture.sha256:
        problems.append(
            FixtureProblem(
                fixture_id=fixture.fixture_id,
                path=fixture.path,
                kind="mismatch",
                detail=f"manifest {fixture.sha256}, on disk {computed}",
            )
        )

    if not fixture.synthetic:
        # `samples/README.md`: every fixture here is invented, and that is what lets CI run
        # against this corpus with no organization and therefore no §18.10 privacy switch to
        # honour. A row claiming otherwise is a decision, and a decision is not a commit.
        problems.append(
            FixtureProblem(
                fixture_id=fixture.fixture_id,
                path=fixture.path,
                kind="not_synthetic",
                detail="synthetic = false; tenant content in the platform corpus needs a "
                "recorded decision, never a manifest edit",
            )
        )

    return computed, files, size, problems


def verify_corpus(samples_root: Path | None = None) -> CorpusVerification:
    """Recompute every fixture digest and refuse the run unless all of them match.

    Raises ``CorpusUnverified`` listing **every** problem — missing fixtures, placeholder
    digests, mismatches, paths escaping the corpus, and floor violations. It never warns and
    continues: a run against a corpus that is not the corpus the manifest describes produces
    a number attributed to the wrong bytes, and that number is indistinguishable from a real
    one for as long as anyone cares to compare it.

    **Against ``corpus-2026.08.1`` this passes**, over 10 fixtures and 21 files, on a bare
    CPython with no third-party package installed at all — the module imports only ``hashlib``,
    ``tomllib``, ``dataclasses`` and ``pathlib``. It needs no Qdrant, no PostgreSQL, no
    provider credential and no ``evaluation`` extra, which is what makes it wireable as a CI
    step in any job that has a checkout and an interpreter. It flipped from failing to passing
    on the day the fixtures were authored with no edit here, and it will flip back the moment
    a byte moves.
    """
    # Resolved once, here, and then passed explicitly to everything below. Re-resolving per
    # helper would let one verification read two different roots if the environment changed
    # mid-run, and the digests would then be attributed to a corpus that was never whole.
    root, source = _resolve_samples_root(samples_root)
    manifest = _load_manifest_at(root, source)
    fixtures = read_fixtures(manifest)

    problems: list[FixtureProblem] = []
    digests: dict[str, str] = {}
    files_hashed = 0
    bytes_hashed = 0

    seen: set[str] = set()
    for fixture in fixtures:
        if fixture.fixture_id in seen:
            problems.append(
                FixtureProblem(
                    fixture_id=fixture.fixture_id,
                    path=fixture.path,
                    kind="duplicate_id",
                    detail="an id reused for different content makes historical runs lie",
                )
            )
            continue
        seen.add(fixture.fixture_id)

        computed, files, size, fixture_problems = _verify_fixture(fixture, root)
        problems.extend(fixture_problems)
        files_hashed += files
        bytes_hashed += size
        if computed is not None and not fixture_problems:
            digests[fixture.fixture_id] = computed

    # The non-vacuity floors, reported as problems rather than as a separate exception so one
    # run of the check tells you everything. "Verified 0 of 0 fixtures" must never be a line
    # that scrolls past looking like success.
    if len(fixtures) < MIN_FIXTURES:
        problems.append(
            FixtureProblem(
                fixture_id="",
                path=str(root / "corpus" / "manifest.toml"),
                kind="floor",
                detail=f"{len(fixtures)} fixtures declared, floor is {MIN_FIXTURES}",
            )
        )
    if files_hashed < MIN_FILES_HASHED:
        problems.append(
            FixtureProblem(
                fixture_id="",
                path=str(root / "corpus" / "documents"),
                kind="floor",
                detail=(
                    f"{files_hashed} files were read, floor is {MIN_FILES_HASHED}. A check "
                    f"that hashes nothing exits 0 and reads exactly like one that hashed "
                    f"everything — this floor is the only thing that tells them apart"
                ),
            )
        )

    if problems:
        raise CorpusUnverified(tuple(problems))

    return CorpusVerification(
        corpus_version=str(manifest["corpus_version"]),
        manifest_version=int(manifest["manifest_version"]),
        manifest_sha256=manifest_digest(root),
        fixture_digests=digests,
        files_hashed=files_hashed,
        bytes_hashed=bytes_hashed,
    )


# Import-time checks. A floor silently set to zero is the whole failure mode this module is
# arranged around, and it would otherwise be discovered as a green gate over an empty corpus.
assert MIN_FIXTURES > 0
assert MIN_FILES_HASHED > 0
assert PLACEHOLDER_DIGEST != ""
assert TREE_DIGEST_SCHEME.endswith(b"\n")
