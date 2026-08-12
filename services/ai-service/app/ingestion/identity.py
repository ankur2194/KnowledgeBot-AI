"""Ingest-key composition and the deterministic point ids.

Two identities, one rule each, and both rules exist because Celery delivers at-least-once.

**The ingest key decides whether a source version already exists.** It covers content *and*
every processing configuration version. A content-only key silently swallows a parser, OCR,
chunker, or embedding-model change: the request dedupes against the completed run, the admin
sees "already processed", and the new settings never reach a document. `ocr_cfg_version` is
in the tuple below deliberately — §13.3 omits it while §13.4 makes an OCR change a version
trigger, and that gap is what makes an OCR retune a no-op. `force_nonce` is the same trap
reached from the other side: the explicit reprocess button when nothing else changed.

**`embedding_model_version` is the component that stopped being free.** It used to be a commit
sha out of a manifest — a local pinned model cannot change under a running worker, so the value
was stable by construction and the key part was bookkeeping. Embeddings are an API call now,
and a vendor model id is an alias: it can be re-trained or re-quantized behind our backs with
no diff anywhere in this repository. Cosine distance is defined between any two vectors of
equal width, so a swap raises nothing, moves no total, and shows up only as ranking that
degrades for the older half of a collection.

The value passed here is therefore `EmbeddingModelIdentity.version` from
`app/ingestion/embedding/embedder.py` — provider, model id, the width the provider actually
returned, and a digest over a fixed probe set — and never a bare model name. That is what makes
this key part carry its own weight again: when a vendor swaps weights, the digest moves, every
key in the corpus moves with it, and a resubmission is treated as the genuine change it is
rather than deduping against vectors from a space that no longer exists. A key built from the
alias alone would dedupe straight through the swap.

**The point id decides what a replayed upsert does.** A non-deterministic id turns a retry
into duplicate vectors — a partially-successful upsert that is redelivered writes a *second*
set of points for chunks it already wrote. Retrieval then returns the same passage twice, the
context budget is spent on duplicates, and deletion by `chunks.vector_point_id` removes only
one set. Nothing raises at any point: the upsert is a 200 and the totals look plausible.
`uuid5(POINT_NS, "org:version:seq")` makes the replay overwrite what it already wrote, and
makes a rebuild from PostgreSQL reproduce byte-identical ids — which is what ADR-010's rebuild
proof turns on.

`POINT_NS` is frozen forever. Rotating it does not migrate anything; it instantly orphans
every existing point behind an id no deletion query can name.
"""

from __future__ import annotations

import hashlib
import uuid
from typing import Final

from app.core.errors import ErrorClass, KbError, Origin

__all__ = [
    "INGEST_KEY_PARTS",
    "INGEST_KEY_SCHEME",
    "INGEST_KEY_SEPARATOR",
    "POINT_NS",
    "POINT_SEPARATOR",
    "ingest_key",
    "point_id",
]

#: Bump only to force a global reprocess — it invalidates every key in the system at once.
INGEST_KEY_SCHEME: Final[str] = "v1"

#: The ordered key components, as data, so a test can assert none was dropped rather than
#: hoping a reviewer notices. The order is part of the key: reordering changes every hash and
#: re-versions the whole corpus, exactly like bumping the scheme, but without saying so.
INGEST_KEY_PARTS: Final[tuple[str, ...]] = (
    "scheme",
    "org_id",
    "source_id",
    "source_item_id",
    "content_hash",
    "parser_cfg_version",
    "ocr_cfg_version",
    "chunker_cfg_version",
    "embedding_model_version",
    "force_nonce",
)

#: Frozen forever — it *is* point identity. See the module docstring.
POINT_NS: Final[uuid.UUID] = uuid.UUID("6f1e1b1e-0000-4000-8000-000000000001")

#: What joins the parts. A separator that can appear *inside* a part is not a separator: with
#: `|` legal in a component, `("a|b", "c")` and `("a", "b|c")` hash identically, so two
#: different identities dedupe against each other and the second one is silently never
#: processed. That is why the guard below rejects a component containing it rather than
#: escaping — escaping is a second encoding to keep in step with this one, and the values here
#: (ULIDs, hex digests, `scheme:label:digest` strings) have no legitimate use for the byte.
INGEST_KEY_SEPARATOR: Final[str] = "|"

#: The same argument one layer down, for the readable key folded through `uuid5`. `org_id` and
#: `source_version_id` are ULIDs and `seq` is an integer, so none of the three can contain a
#: colon — and if one ever could, `("a:b", "c", 1)` and `("a", "b:c", 1)` would name the same
#: point, which is a vector from one version overwriting a vector from another.
POINT_SEPARATOR: Final[str] = ":"


def ingest_key(
    *,
    org_id: str,
    source_id: str,
    source_item_id: str,
    content_hash: str,
    parser_cfg_version: str,
    ocr_cfg_version: str,
    chunker_cfg_version: str,
    embedding_model_version: str,
    force_nonce: str | None = None,
) -> str:
    """The sha256 over `INGEST_KEY_PARTS`, joined by `|`.

    Persisted on the version row; `UNIQUE (source_item_id, ingest_key)` is the dedup, so this
    value is what makes a resubmission cheap and a genuine change expensive.

    `content_hash` has two different meanings by source type and they are not
    interchangeable. Uploads hash the **raw bytes** — normalization happens downstream of the
    parser and `parser_cfg_version` already covers it separately. Crawls hash the
    **normalized** content, because raw HTML carries rotating CSRF tokens, render timestamps,
    ad slots and visitor counters, so every page of a 400-page site looks changed every night
    and re-embeds, re-indexes and re-publishes for nothing.

    `embedding_model_version` is `EmbeddingModelIdentity.version`, never a bare vendor model
    id — see the module docstring for why the difference is the whole value of the component.

    Every argument is keyword-only: a positional call site that swaps `source_id` and
    `source_item_id` produces a perfectly valid hash for the wrong identity.
    """
    values = {
        "scheme": INGEST_KEY_SCHEME,
        "org_id": org_id,
        "source_id": source_id,
        "source_item_id": source_item_id,
        "content_hash": content_hash,
        "parser_cfg_version": parser_cfg_version,
        "ocr_cfg_version": ocr_cfg_version,
        "chunker_cfg_version": chunker_cfg_version,
        "embedding_model_version": embedding_model_version,
        #: The only optional part, and the only one allowed to be empty: "no forced reprocess"
        #: has to hash to something, and it has to be the *same* something every time or an
        #: ordinary resubmission stops deduping against its own completed run.
        "force_nonce": force_nonce or "",
    }

    # `INGEST_KEY_PARTS` is the authority on both the membership and the ORDER, checked in both
    # directions. A part added to the tuple and not here would otherwise be silently absent from
    # the hash — the exact failure the tuple exists to make testable — and a value here that is
    # in no part is a component somebody believes is covered and is not.
    missing = [name for name in INGEST_KEY_PARTS if name not in values]
    unused = [name for name in values if name not in INGEST_KEY_PARTS]
    if missing or unused:
        raise KbError(
            ErrorClass.INTERNAL_DEPENDENCY,
            f"ingest key composition disagrees with INGEST_KEY_PARTS: missing {missing}, "
            f"unused {unused}. A component that is declared and not hashed is a configuration "
            "change that dedupes against the run it was supposed to invalidate",
            origin=Origin.SELF,
        )

    parts = []
    for name in INGEST_KEY_PARTS:
        value = values[name]
        if not value and name != "force_nonce":
            raise KbError(
                ErrorClass.VALIDATION,
                f"ingest key component {name!r} is empty. Every component is required, "
                "including the config versions of stages that did not run on this document: "
                "an empty ocr_cfg_version makes an OCR retune a no-op for every item that "
                "carried one, because the key it dedupes against is unchanged",
                origin=Origin.SELF,
            )
        if INGEST_KEY_SEPARATOR in value:
            raise KbError(
                ErrorClass.VALIDATION,
                f"ingest key component {name!r} contains {INGEST_KEY_SEPARATOR!r}, which is "
                "the separator. Two different identities would hash to one key and the second "
                "would be treated as already processed",
                origin=Origin.SELF,
            )
        parts.append(value)

    return hashlib.sha256(INGEST_KEY_SEPARATOR.join(parts).encode("utf-8")).hexdigest()


def point_id(*, org_id: str, source_version_id: str, seq: int) -> str:
    """`str(uuid5(POINT_NS, f"{org_id}:{source_version_id}:{seq}"))`.

    Qdrant accepts only an unsigned integer or a UUID as a point id, so the readable key is
    folded through uuid5 rather than passed through. This is deliberately **not** derived from
    `chunk_id`: chunk ids are ULIDs, which Qdrant rejects outright, and a chunk id minted per
    run is not stable across a replay. `chunk_id` rides in the payload instead, where
    retrieval, dedup and citation read it.

    Reaching for `uuid4()` to get past the id-type rejection is accepted, returns 200, logs
    nothing, and is the duplicate-vector bug in the module docstring.

    `seq` is document order from the chunker and must be a pure function of (elements,
    config). A nondeterministic chunker moves `seq`, which moves every id, which makes a
    replay write a full second set of points and makes the pre-activation verification fail
    intermittently on documents that succeed on retry.
    """
    # `bool` is a subclass of `int`, and `f"{True}"` is `"True"`, not `"1"` — so a caller that
    # passed a flag where a sequence number belongs would mint a perfectly valid id for a point
    # that no rebuild and no deletion can ever name again. Checked before the range test,
    # because `True >= 0` is true.
    if isinstance(seq, bool) or not isinstance(seq, int):
        raise KbError(
            ErrorClass.VALIDATION,
            f"point id seq must be an int, got {type(seq).__name__}; the id is the only handle "
            "deletion has on this vector, so a value that formats differently than it compares "
            "is unrecoverable",
            origin=Origin.SELF,
        )
    if seq < 0:
        raise KbError(
            ErrorClass.VALIDATION,
            f"point id seq must be non-negative, got {seq}; seq is document order from the "
            "chunker and a negative one means it was derived rather than counted",
            origin=Origin.SELF,
        )
    for name, value in (("org_id", org_id), ("source_version_id", source_version_id)):
        if not value:
            raise KbError(
                ErrorClass.VALIDATION,
                f"point id component {name!r} is empty; every version of every organization "
                "would then share one id space, so one tenant's upsert overwrites another's "
                "vectors and the point totals still verify",
                origin=Origin.SELF,
            )
        if POINT_SEPARATOR in value:
            raise KbError(
                ErrorClass.VALIDATION,
                f"point id component {name!r} contains {POINT_SEPARATOR!r}, which is the "
                "separator; two different (org, version) pairs would name the same point",
                origin=Origin.SELF,
            )

    readable = POINT_SEPARATOR.join((org_id, source_version_id, str(seq)))
    return str(uuid.uuid5(POINT_NS, readable))
