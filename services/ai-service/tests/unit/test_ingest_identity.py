"""The two identities `app/ingestion/identity.py` composes, and the properties they carry.

Both exist because the broker delivers at-least-once. The ingest key decides whether a
resubmission is a new version or a no-op; the point id decides whether a redelivered upsert
overwrites or duplicates. Neither failure raises anything: a key that dedupes through a config
change reports "already processed", and a non-deterministic point id reports a successful
upsert with twice the vectors.

**`point_id` stability is the property Non-negotiable 6 rests on.** Deletion addresses vectors
by this id and never by text match, so an id that is not reproducible from `(org, version,
seq)` is a vector that cannot be removed. `uuid5` is stable by specification; what this file
proves is that *this function* is — including across a fresh interpreter, where a hash
seeded per process (`hash()`, `id()`, a `set` iteration) would diverge and an in-process test
would never see it.
"""

from __future__ import annotations

import subprocess
import sys
import uuid
from typing import Any, Final

import pytest

from app.core.errors import ErrorClass, KbError
from app.ingestion.identity import (
    INGEST_KEY_PARTS,
    INGEST_KEY_SCHEME,
    POINT_NS,
    ingest_key,
    point_id,
)
from tests.support.ingestion import ulid

#: One complete, valid argument set. Every test below varies exactly one component of it, so a
#: failure names the component rather than the call.
BASE: Final[dict[str, str]] = {
    "org_id": ulid("orga"),
    "source_id": ulid("src"),
    "source_item_id": ulid("item"),
    "content_hash": "9" * 64,
    "parser_cfg_version": "parser/v1:docling2.118.0:0123456789ab",
    "ocr_cfg_version": "ocr/v1:rapidocr1.4.4:0123456789ab",
    "chunker_cfg_version": "chunker/v1:chunker/v1:0123456789ab",
    "embedding_model_version": "emb/v1:openai:text-embedding-3-large:d3072:9f2a1c4e77b1",
}


def test_every_declared_key_part_actually_changes_the_key() -> None:
    """`INGEST_KEY_PARTS` is data so a test can assert none was dropped. This is that test.

    A component declared and not hashed is the whole failure mode: the parser is retuned, the
    key is unchanged, the request dedupes against the completed run, and the new settings never
    reach a document. `ocr_cfg_version` is in here deliberately — the spec omits it in one
    section and makes an OCR change a version trigger in the next, and that gap is exactly how
    an OCR retune becomes a no-op.
    """
    baseline = ingest_key(**BASE)
    for part in INGEST_KEY_PARTS:
        if part in ("scheme", "force_nonce"):
            continue
        moved = dict(BASE)
        moved[part] = BASE[part] + "x"
        assert ingest_key(**moved) != baseline, f"{part} is declared but does not reach the hash"


def test_the_scheme_is_inside_the_key() -> None:
    """Bumping the scheme must invalidate every key in the system at once, which is only true
    if it is hashed. The check is indirect — the scheme is a constant — so this asserts the
    documented composition instead."""
    assert INGEST_KEY_SCHEME == "v1"
    assert INGEST_KEY_PARTS[0] == "scheme"


def test_the_force_nonce_is_the_explicit_reprocess() -> None:
    """The button for "nothing changed and I want it processed again"."""
    assert ingest_key(**BASE) == ingest_key(**BASE, force_nonce=None)
    assert ingest_key(**BASE) == ingest_key(**BASE, force_nonce="")
    assert ingest_key(**BASE) != ingest_key(**BASE, force_nonce="operator-requested")


def test_two_organizations_never_share_a_key() -> None:
    """Two organizations that upload byte-identical files. Everything except `org_id` agrees,
    and the keys must not: a shared key means the second organization's upload dedupes against
    the first's completed run and its content is never processed at all."""
    other = dict(BASE, org_id=ulid("orgb"))
    assert ingest_key(**other) != ingest_key(**BASE)


def test_a_separator_in_a_component_is_refused_rather_than_collided() -> None:
    """`("a|b", "c")` and `("a", "b|c")` join to the same string. Refusing the byte is the fix;
    escaping would be a second encoding to keep in step with the first."""
    with pytest.raises(KbError) as raised:
        ingest_key(**dict(BASE, source_id="a|b"))
    assert raised.value.error_class is ErrorClass.VALIDATION


def test_an_empty_component_is_refused() -> None:
    """Including a config version for a stage that did not run. An empty `ocr_cfg_version` is
    indistinguishable from an absent one, and every item carrying it survives an OCR retune."""
    with pytest.raises(KbError) as raised:
        ingest_key(**dict(BASE, ocr_cfg_version=""))
    assert "ocr_cfg_version" in raised.value.message


def test_a_bare_model_alias_and_a_measured_identity_are_different_keys() -> None:
    """The ADR-030 hazard, stated as a key property: a vendor re-points an alias, the canary
    digest moves, and every key in the corpus moves with it. A key built from the alias alone
    dedupes straight through the swap."""
    alias = dict(BASE, embedding_model_version="text-embedding-3-large")
    swapped = dict(BASE, embedding_model_version=BASE["embedding_model_version"][:-1] + "0")
    assert ingest_key(**alias) != ingest_key(**BASE)
    assert ingest_key(**swapped) != ingest_key(**BASE)


# ── point_id ─────────────────────────────────────────────────────────────────

ORG_A: Final[str] = ulid("orga")
ORG_B: Final[str] = ulid("orgb")
VERSION: Final[str] = ulid("verone")


def test_the_point_id_is_reproducible_in_process() -> None:
    assert point_id(org_id=ORG_A, source_version_id=VERSION, seq=7) == point_id(
        org_id=ORG_A, source_version_id=VERSION, seq=7
    )


def test_the_point_id_is_reproducible_in_a_fresh_interpreter() -> None:
    """The half an in-process test cannot see.

    A point id derived from anything seeded per process — `hash()`, `id()`, a `set` ordering,
    a `uuid4` sneaked in to get past Qdrant's id-type rejection — is stable inside one run and
    different in the next. In production that means a redelivered upsert writes a *second* set
    of points at HTTP 200, and `chunks.vector_point_id` names only one of them, so deletion
    leaves the other serving forever. Recomputing in a subprocess is the only cheap way to
    observe it.
    """
    program = (
        "from app.ingestion.identity import point_id;"
        f"print(point_id(org_id={ORG_A!r}, source_version_id={VERSION!r}, seq=7))"
    )
    finished = subprocess.run(  # noqa: S603 - the argv is this file's own literal
        [sys.executable, "-c", program],
        capture_output=True,
        text=True,
        check=True,
    )
    assert finished.stdout.strip() == point_id(org_id=ORG_A, source_version_id=VERSION, seq=7)


def test_the_point_id_is_the_documented_uuid5() -> None:
    """Stated as the composition rather than as "a hash", because two readings of "a hash over
    the identity" would disagree on every input and a rebuild has to reproduce these exactly."""
    expected = str(uuid.uuid5(POINT_NS, f"{ORG_A}:{VERSION}:7"))
    assert point_id(org_id=ORG_A, source_version_id=VERSION, seq=7) == expected


def test_every_component_moves_the_point_id() -> None:
    """Two organizations and two versions. If `org_id` dropped out of the readable key, one
    organization's upsert would overwrite another's vectors — silently, at the same totals."""
    base = point_id(org_id=ORG_A, source_version_id=VERSION, seq=7)
    assert point_id(org_id=ORG_B, source_version_id=VERSION, seq=7) != base
    assert point_id(org_id=ORG_A, source_version_id=ulid("vertwo"), seq=7) != base
    assert point_id(org_id=ORG_A, source_version_id=VERSION, seq=8) != base


def test_the_point_id_is_a_uuid_because_qdrant_accepts_nothing_else() -> None:
    """A ULID here is rejected outright by the server, which is why the readable key is folded
    through `uuid5` rather than passed through."""
    uuid.UUID(point_id(org_id=ORG_A, source_version_id=VERSION, seq=0))


@pytest.mark.parametrize("seq", [True, -1, "3", 1.0])
def test_a_seq_that_is_not_a_non_negative_int_is_refused(seq: Any) -> None:
    """`True` is the one that matters: `isinstance(True, int)` is true and `f"{True}"` is
    `"True"`, so a flag passed where a sequence number belongs mints a perfectly valid id for a
    point no rebuild and no deletion can ever name again."""
    with pytest.raises(KbError):
        point_id(org_id=ORG_A, source_version_id=VERSION, seq=seq)


@pytest.mark.parametrize("field", ["org_id", "source_version_id"])
def test_an_empty_or_separator_bearing_identity_component_is_refused(field: str) -> None:
    arguments = {"org_id": ORG_A, "source_version_id": VERSION, "seq": 0}
    with pytest.raises(KbError):
        point_id(**{**arguments, field: ""})  # type: ignore[arg-type]
    with pytest.raises(KbError):
        point_id(**{**arguments, field: "a:b"})  # type: ignore[arg-type]
