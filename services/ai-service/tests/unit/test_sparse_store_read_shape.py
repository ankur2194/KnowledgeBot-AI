"""``CorpusStatisticsStore`` documents **two** reads, and the second is a strict prefix of the
first — which is why stating only the first read as complete.

Finding #45. ``load`` returns a ``document_frequencies`` mapping *and* a ``document_total``,
from two tables under two different aggregations, and the Protocol's docstring described only
the frequencies statement. There is no Python implementation, so the docstring **is** the
specification: whoever writes the store reads one of the three statements of this contract and
implements what it says. A half-stated contract yields a store that either cannot populate the
value it returns, or fills the total from somewhere unscoped.

The vacuity trap is the whole reason this file exists. The totals predicate

    organization_id = $1 AND source_version_id = ANY($2) AND analyzer = $3

is a **strict prefix** of the frequencies predicate, so ``totals in docstring`` is satisfied by
the frequencies statement alone. A naive containment check therefore passes against exactly the
text that provoked the finding. Every assertion below that concerns the totals read requires a
*standalone* occurrence — one not continued by ``AND term_id`` — and
``test_the_prefix_check_would_pass_against_the_defect_it_exists_to_catch`` proves the naive form
is worthless by running it against a constructed half-statement.

The migration is read as data rather than described, on the precedent of
``test_write_allow_list.py``: the migration's own header says the statements *"must agree
placeholder for placeholder, because whoever implements the store reads only one of them"*, and
an agreement nothing compares is a sentence rather than a guarantee.
"""

from __future__ import annotations

import re
from pathlib import Path
from typing import Final

from app.retrieval.sparse import CorpusStatisticsStore
from tests.support.tree import SERVICE_ROOT

#: Laravel owns the schema, so the other statement of this contract lives over there.
MIGRATION: Final[Path] = (
    SERVICE_ROOT.parent
    / "core-api"
    / "database"
    / "migrations"
    / "2026_08_07_000600_create_sparse_corpus_statistics_tables.php"
)

#: The document-frequency read: four placeholders, summed per term.
FREQUENCIES_READ: Final[str] = (
    "organization_id = $1 AND source_version_id = ANY($2) AND analyzer = $3 AND term_id = ANY($4)"
)

#: The document-total read: the same scope without the term. A strict prefix of the above, and
#: that is the property every assertion here has to work around rather than around which it may
#: be written.
TOTALS_READ: Final[str] = "organization_id = $1 AND source_version_id = ANY($2) AND analyzer = $3"

#: The two tables, named so a read can be attributed to one. Spelled independently of
#: ``ALLOWED_TABLES`` — this file is about what the docstring says, not about what may be
#: written.
FREQUENCIES_TABLE: Final[str] = "sparse_term_frequencies"
TOTALS_TABLE: Final[str] = "sparse_version_statistics"

_PHP_COMMENT_PREFIX = re.compile(r"^\s*(?:\*|//)\s?", re.MULTILINE)


def _normalize(text: str) -> str:
    """Collapse to one line so a predicate wrapped by a formatter still compares equal.

    Backticks go too. reStructuredText marks code with ``double`` backticks and PHPDoc with
    `single` ones, and a contract that agreed everywhere except on its quoting would fail here
    for a reason nobody would believe.
    """
    return " ".join(text.replace("`", " ").split())


def _standalone_totals_occurrences(text: str) -> int:
    """Occurrences of the totals predicate that are **not** the head of the frequencies one."""
    normalized = _normalize(text)
    return sum(
        1
        for match in re.finditer(re.escape(TOTALS_READ), normalized)
        if not normalized[match.end() :].lstrip().startswith("AND term_id")
    )


def _docstring() -> str:
    doc = CorpusStatisticsStore.__doc__
    assert doc is not None
    return _normalize(doc)


def _migration() -> str:
    return _normalize(_PHP_COMMENT_PREFIX.sub("", MIGRATION.read_text(encoding="utf-8")))


# ── positive controls, because every assertion below is "this text contains X" ──


def test_the_migration_was_actually_found_and_read() -> None:
    """Both cross-plane assertions pass trivially against an empty string."""
    assert MIGRATION.is_file(), MIGRATION
    assert "CREATE TABLE sparse_term_frequencies" in _migration()
    assert "CREATE TABLE sparse_version_statistics" in _migration()


def test_the_prefix_check_would_pass_against_the_defect_it_exists_to_catch() -> None:
    """The naive containment test, run against a document that states only one read.

    This is the shape ``CorpusStatisticsStore`` shipped with: the frequencies statement and
    nothing else. ``TOTALS_READ in half`` is ``True`` — the totals predicate is a prefix — so a
    check written that way reports a fully-documented store. The standalone counter is what
    tells the two apart, and without this test nothing would show that the difference is real.
    """
    half = f"Read with {FREQUENCIES_READ}, summed per term."
    assert TOTALS_READ in _normalize(half)
    assert _standalone_totals_occurrences(half) == 0


# ── the two reads, on this side of the boundary ─────────────────────────────────


def test_the_store_documents_the_document_frequency_read() -> None:
    assert FREQUENCIES_READ in _docstring()


def test_the_store_documents_the_document_total_read_as_a_read_of_its_own() -> None:
    """Finding #45 itself. ``load`` cannot construct its return value without this statement,
    and a store written from a docstring that omits it fills ``document_total`` from wherever
    seems reasonable — the organization's whole corpus being the reasonable-looking one, and the
    cross-bot oracle the version-set scoping exists to close."""
    assert _standalone_totals_occurrences(_docstring()) >= 1


def test_the_store_attributes_each_read_to_the_table_it_hits() -> None:
    """Two predicates with no table names is one read stated twice as far as a reader is
    concerned, and the tables have different aggregations."""
    doc = _docstring()
    assert FREQUENCIES_TABLE in doc
    assert TOTALS_TABLE in doc


def test_the_store_documents_the_two_different_aggregations() -> None:
    """``sum(document_frequency)`` is grouped by term; ``sum(document_total)`` is not grouped at
    all. A store that grouped the total by anything would return a row per version and use the
    first."""
    doc = _docstring()
    assert "sum(document_frequency)" in doc
    assert "sum(document_total)" in doc
    assert "grouped by term_id" in doc


def test_the_store_states_that_the_totals_read_is_unconditional() -> None:
    """``term_ids`` may legitimately be empty — a query that analyzed to no terms is a dense-only
    run — and the total is still real. It is what distinguishes a scope holding no documents from
    a lookup that failed, and only one of those two is ``SparseStatisticsUnavailable``."""
    assert "unconditional" in _docstring()


def test_the_store_states_what_load_must_stamp_on_what_it_returns() -> None:
    """``for_scope`` compares three fields and has nothing else to compare against. A store that
    derived ``evidence_fp`` from anything but the list it bound to ``$2`` turns that check into
    one that passes on statistics read over a wider scope than the query is filtered to."""
    doc = _docstring()
    assert "evidence_fingerprint(allowed_version_ids)" in doc
    assert "org_id" in doc and "analyzer" in doc


# ── and the same two statements, on the other side of it ────────────────────────


def test_the_migration_states_both_reads_too() -> None:
    """The control-plane half of "placeholder for placeholder". If this fails, the migration
    moved and the assertion below is comparing this docstring against nothing."""
    migration = _migration()
    assert FREQUENCIES_READ in migration
    assert _standalone_totals_occurrences(migration) >= 1


def test_the_two_planes_state_the_same_two_reads() -> None:
    """The drift the migration's header warns about, made into a failing test.

    The implementer reads one of the two files. A predicate that gains a placeholder here and
    not there — or loses ``analyzer`` on the totals, whose wrong answer is a clean doubling of
    the IDF numerator with no row missing and nothing raised — is caught by nothing else.
    """
    doc, migration = _docstring(), _migration()
    assert (FREQUENCIES_READ in doc) == (FREQUENCIES_READ in migration)
    assert (_standalone_totals_occurrences(doc) >= 1) == (
        _standalone_totals_occurrences(migration) >= 1
    )
    assert FREQUENCIES_READ in doc
