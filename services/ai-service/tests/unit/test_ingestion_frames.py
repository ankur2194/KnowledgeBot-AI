"""The callback frame layer: the three fields whose wrong value produces a 200 and no work.

Every assertion here is aimed at a failure that is invisible in the logs of both planes. A frame
with the wrong ``job_id`` is answered ``stale_job``; one with a low ``sequence`` is answered
``out_of_order``; one whose ``verified`` is an ``int`` validated and published nothing for eight
days (finding Q4). All three are HTTP 200 with a body nobody reads, which is why they are tested
here rather than left to a green end-to-end run.
"""

from __future__ import annotations

import json
from typing import Any

import pytest

from app.core.errors import KbError
from app.ingestion.frames import (
    MAX_STAGE_CHARS,
    FrameScope,
    Sequencer,
    identity_payload,
    progress_frame,
    read_sequence_base,
    readiness_frame,
)
from app.ingestion.states import SourceState

SCOPE = FrameScope(
    org_id="01JQZ0000000000000000000AA",
    job_id="01JQZ0000000000000000000BB",
    source_id="01JQZ0000000000000000000CC",
    source_item_id="01JQZ0000000000000000000DD",
)

IDENTITY = identity_payload(
    content_hash="a" * 64,
    ingest_key="b" * 64,
    parser_cfg_version="parser/v1:docling2.118.0:6a1f0c9e33bd",
    ocr_cfg_version="ocr/v1:rapidocr:1f0c9e33bd4a",
    chunker_cfg_version="chunker/v1:t700:fcd710e0c32d",
    embedding_model_version="emb/v1:openai:text-embedding-3-large:d3072:9f2a1c4e77b1",
)


class _Cursor:
    def __init__(self, row: Any) -> None:
        self._row = row
        self.executed: list[tuple[str, Any]] = []

    async def execute(self, sql: str, params: Any) -> None:
        self.executed.append((sql, params))

    async def fetchone(self) -> Any:
        return self._row

    async def __aenter__(self) -> _Cursor:
        return self

    async def __aexit__(self, *exc: Any) -> None:
        return None


class _Conn:
    def __init__(self, row: Any) -> None:
        self._cursor = _Cursor(row)

    def cursor(self) -> _Cursor:
        return self._cursor


# ─────────────────────────── the sequence guard ────────────────────────────


def test_the_sequencer_starts_one_past_the_applied_count() -> None:
    """The guard is strictly greater-than, so the first frame is base + 1 and never base."""
    assert Sequencer(0).take() == 1
    assert Sequencer(7).take() == 8


def test_the_sequencer_never_repeats() -> None:
    seq = Sequencer(3)
    assert [seq.take() for _ in range(4)] == [4, 5, 6, 7]


def test_a_resumed_run_does_not_restart_at_one() -> None:
    """THE FAILURE THIS EXISTS FOR: a resumed run whose counter restarts loses its READINESS
    frame, not just its progress frames. The version verified, the points are on disk, and
    nothing activates them — the state `publish.report_readiness` refuses to no-op into."""
    first_run_last = Sequencer(0)
    for _ in range(5):
        applied = first_run_last.take()

    resumed = Sequencer(applied)
    assert resumed.take() > applied


def test_a_negative_base_is_refused_rather_than_clamped() -> None:
    with pytest.raises(KbError, match="every frame of this run would be discarded"):
        Sequencer(-1)


@pytest.mark.anyio
async def test_the_sequence_base_comes_from_the_item_row() -> None:
    conn = _Conn((11,))
    assert await read_sequence_base(conn, org_id=SCOPE.org_id, source_item_id="x") == 11


@pytest.mark.anyio
async def test_a_missing_item_row_reads_as_zero_rather_than_raising() -> None:
    """The item may have been deleted mid-run. Every frame will then be refused as
    `unknown_item`, which is correct — and is reached without a second failure mode here."""
    assert await read_sequence_base(_Conn(None), org_id=SCOPE.org_id, source_item_id="x") == 0


@pytest.mark.anyio
async def test_a_null_progress_sequence_reads_as_zero() -> None:
    assert await read_sequence_base(_Conn((None,)), org_id=SCOPE.org_id, source_item_id="x") == 0


# ─────────────────────────── the identity payload ───────────────────────────


def test_the_identity_carries_exactly_the_six_components() -> None:
    assert set(IDENTITY) == {
        "content_hash",
        "ingest_key",
        "parser_cfg_version",
        "ocr_cfg_version",
        "chunker_cfg_version",
        "embedding_model_version",
    }


def test_a_blank_component_is_refused() -> None:
    """A blank component contributes nothing to the ingest key, which makes the version trigger
    it represents permanently undetectable — the admin sees "already processed" forever."""
    with pytest.raises(KbError, match="ocr_cfg_version"):
        identity_payload(
            content_hash="a" * 64,
            ingest_key="b" * 64,
            parser_cfg_version="parser/v1:x:y",
            ocr_cfg_version="",
            chunker_cfg_version="chunker/v1:t700:z",
            embedding_model_version="emb/v1:p:m:d1:z",
        )


# ─────────────────────────── the progress frame ────────────────────────────


def test_the_frame_carries_the_submission_job_id() -> None:
    """NOT the version id. Laravel compares this against `source_items.current_job_id` and
    answers a mismatch with `stale_job` — 200, applied:false, nothing raised anywhere."""
    frame = progress_frame(scope=SCOPE, sequence=1, stage="chunk", status=SourceState.CHUNKING)
    assert frame["job_id"] == SCOPE.job_id
    assert frame["job_id"] != SCOPE.source_item_id


def test_unset_optional_fields_are_omitted_and_never_null() -> None:
    """`verified` defaults to false BY OMISSION and `version` fires six `required_with` rules
    the moment the key is present, so `null` and absent are different on the far side."""
    frame = progress_frame(scope=SCOPE, sequence=1, stage="parse", status=SourceState.PARSING)
    for absent in ("version", "delivery_count", "chunk_count", "warning_summary", "verified"):
        assert absent not in frame


def test_an_empty_warning_map_is_omitted_rather_than_sent_as_an_empty_object() -> None:
    frame = progress_frame(
        scope=SCOPE, sequence=1, stage="parse", status=SourceState.PARSING, warning_summary={}
    )
    assert "warning_summary" not in frame


def test_a_zero_chunk_count_is_sent_because_zero_is_a_measurement() -> None:
    frame = progress_frame(
        scope=SCOPE, sequence=1, stage="chunk", status=SourceState.CHUNKING, chunk_count=0
    )
    assert frame["chunk_count"] == 0


def test_a_long_stage_label_is_truncated_rather_than_losing_the_frame() -> None:
    """`stage` is `max:64` on the far side and is an OBSERVABILITY field. Losing a whole frame —
    its status and its sequence with it — over a long label trades cosmetics for a real loss."""
    frame = progress_frame(scope=SCOPE, sequence=1, stage="s" * 200, status=SourceState.PARSING)
    assert len(frame["stage"]) == MAX_STAGE_CHARS


def test_no_bot_id_rides_any_frame() -> None:
    """ADR-067: a knowledge source is organization-owned, so there is no bot this is 'for'."""
    frame = progress_frame(
        scope=SCOPE, sequence=1, stage="parse", status=SourceState.PARSING, identity=IDENTITY
    )
    assert not [key for key in frame if "bot" in key.lower()]


# ─────────────────────────── the readiness frame ───────────────────────────


def test_verified_with_no_warnings_publishes_as_ready() -> None:
    frame = readiness_frame(
        scope=SCOPE, sequence=9, verified=True, chunk_total=12, identity=IDENTITY
    )
    assert frame["status"] == SourceState.READY.value
    assert frame["verified"] is True


def test_verified_with_warnings_publishes_as_ready_with_warnings() -> None:
    """A document that parsed at 40% OCR confidence answers exactly like a clean one. The
    warning state is the only trace that says why its answers are poor."""
    frame = readiness_frame(
        scope=SCOPE,
        sequence=9,
        verified=True,
        chunk_total=12,
        identity=IDENTITY,
        warning_summary={"ocr_low_confidence": 3},
    )
    assert frame["status"] == SourceState.READY_WITH_WARNINGS.value
    assert frame["verified"] is True
    assert frame["warning_summary"] == {"ocr_low_confidence": 3}


def test_an_unverified_frame_fails_and_never_claims_verification() -> None:
    frame = readiness_frame(
        scope=SCOPE,
        sequence=9,
        verified=False,
        chunk_total=0,
        identity=IDENTITY,
        error_class="vector_indexing",
    )
    assert frame["status"] == SourceState.FAILED.value
    assert frame["verified"] is False
    assert frame["error_class"] == "vector_indexing"


def test_an_unverified_frame_with_warnings_still_fails() -> None:
    """The warning arm must not be able to steal a failing frame into a Ready flavour: the flag
    and the status are computed from the same boolean, so this is structural."""
    frame = readiness_frame(
        scope=SCOPE,
        sequence=9,
        verified=False,
        chunk_total=0,
        identity=IDENTITY,
        warning_summary={"ocr_low_confidence": 1},
    )
    assert frame["status"] == SourceState.FAILED.value


def test_a_passing_frame_carries_no_error_class() -> None:
    frame = readiness_frame(
        scope=SCOPE,
        sequence=9,
        verified=True,
        chunk_total=1,
        identity=IDENTITY,
        error_class="vector_indexing",
    )
    assert "error_class" not in frame


def test_verified_serializes_as_a_json_literal_and_not_as_one() -> None:
    """FINDING Q4. Laravel's `boolean` rule accepts `1`; `LiteralBoolean` does not, and the
    strict read on the far side compares against `true`. A frame carrying `1` validated as a
    well-formed verification claim, published nothing, and returned 200 forever."""
    frame = readiness_frame(
        scope=SCOPE, sequence=9, verified=True, chunk_total=1, identity=IDENTITY
    )
    assert json.dumps(frame["verified"]) == "true"
    assert not isinstance(frame["verified"], int) or isinstance(frame["verified"], bool)


def test_the_readiness_frame_always_carries_the_full_identity() -> None:
    """Laravel finds-or-creates the version row from it. A readiness frame without one names no
    row, and the run's whole point set has nothing to attach to."""
    frame = readiness_frame(
        scope=SCOPE, sequence=9, verified=True, chunk_total=1, identity=IDENTITY
    )
    assert frame["version"] == dict(IDENTITY)


def test_the_delivery_count_rides_the_readiness_frame() -> None:
    """The worker owns the number and cannot write the column: `source_versions` is not in
    `ALLOWED_TABLES` and never may be, so the callback is the only way it lands."""
    frame = readiness_frame(
        scope=SCOPE,
        sequence=9,
        verified=True,
        chunk_total=1,
        identity=IDENTITY,
        delivery_count=2,
    )
    assert frame["delivery_count"] == 2


def test_the_whole_frame_is_json_serializable() -> None:
    """It is signed as bytes before it is sent, so a value `json` cannot render is a run that
    fails at the transport rather than at the field that produced it."""
    frame = readiness_frame(
        scope=SCOPE,
        sequence=9,
        verified=True,
        chunk_total=4,
        identity=IDENTITY,
        warning_summary={"ocr_low_confidence": 1},
        delivery_count=1,
    )
    assert json.loads(json.dumps(frame, sort_keys=True)) == frame
