"""The exported internal contract, and the properties a consumer is entitled to rely on.

`docs/22` § Q7: the control plane's OpenAPI dumper excludes `internal/` because "that seam is
FastAPI's own exported document and lives beside this one" — and that document did not exist, so
the ingestion submission body was specified by `IngestionSubmission::toArray()` and by nothing
else, on either plane.

`app/contracts/internal/spec.py` exports it now. These tests hold the three things that make an
exported artifact worth more than a comment: it is CURRENT (a drift check that can fail), it is
NARROW (only the internal seam), and it MATCHES THE PRODUCER on the two points Q7 named.
"""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any, Final

import pytest

from app.api.internal.v1.ingestion import IngestionSubmissionRequest
from app.contracts.internal import spec


@pytest.fixture(scope="module")
def document() -> dict[str, Any]:
    return json.loads(spec.ARTIFACT.read_text(encoding="utf-8"))


def test_the_committed_artifact_is_current() -> None:
    """The generate-and-diff gate, run as a test because there is no CI to run it as a job.

    `.github/` was deleted on 2026-08-17, so every generate-and-diff contract in this repository
    is now enforced by a suite or by nobody. This is the ai-service one.
    """
    assert spec.main(["--check"]) == 0, (
        "the internal contract document is out of date: the routers changed and the exported "
        "artifact did not. Run `uv run python -m app.contracts.internal.spec` and commit it"
    )


def test_the_drift_check_can_actually_fail(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    """The control. A `--check` that always returns 0 is worse than no check: it reports the
    artifact as current forever, which is the exact reassurance-without-enforcement shape
    `docs/22` § Q5 and § Q15 are both about."""
    stale = tmp_path / "openapi.json"
    stale.write_text('{"openapi": "3.1.0", "paths": {}}\n', encoding="utf-8")
    monkeypatch.setattr(spec, "ARTIFACT", stale)

    assert spec.main(["--check"]) == 1

    missing = tmp_path / "absent.json"
    monkeypatch.setattr(spec, "ARTIFACT", missing)
    assert spec.main(["--check"]) == 1


def test_it_describes_the_internal_seam_and_nothing_else(document: dict[str, Any]) -> None:
    """Non-negotiable 3 as an assertion about the published document.

    Clients never reach FastAPI. A path here that is not under `/internal/` is not a documentation
    problem — it is a route a client could call, published in a file that invites them to.
    """
    paths = list(document["paths"])

    assert paths, "the document describes no paths at all, which is a broken export"
    for path in paths:
        assert path.startswith("/internal/"), path


def test_the_ingestion_submission_body_is_described(document: dict[str, Any]) -> None:
    """The body Q7 is about, and the three properties that make it a contract rather than a sample.

    A consumer generating a client from a SAMPLE PAYLOAD gets optionality wrong in the permissive
    direction every time — a field that happened to be null in the example becomes optional, and a
    field that happened to be absent becomes unknown. These three say otherwise in the artifact.
    """
    schemas = document["components"]["schemas"]

    submission = schemas["IngestionSubmissionRequest"]
    item = schemas["SubmissionItem"]

    # 1. `extra="forbid"` reaches the document. Without it a consumer may add fields and believe
    #    they are ignored, when in fact the router 422s the whole submission.
    assert submission["additionalProperties"] is False
    assert item["additionalProperties"] is False

    # 2. What is required is stated, per model rather than inferred from a payload.
    assert set(submission["required"]) >= {"job_id", "source", "items"}
    assert set(item["required"]) >= {"id", "canonical_key", "storage_key", "content_hash"}

    # 3. The nullable fields Q7 enumerated are describable AS nullable, which is what a flat
    #    `Optional[str]` buys and is the limit of what it buys: the CONDITIONAL non-nullability in
    #    `source_items_stored_object_is_complete` cannot be expressed here, and a consumer must
    #    not read "nullable" as "independently optional".
    assert "display_name" in item["properties"]
    assert "url" in item["properties"]


@pytest.mark.parametrize(
    "spelling",
    [
        # What `pydantic-contracts`:91 specifies.
        "2026-08-24T00:00:00Z",
        # What `Carbon::toIso8601String()` — the ONLY producer — actually emits.
        "2026-08-24T00:00:00+00:00",
        # And the offset form, because a producer that stops normalizing to UTC would otherwise
        # break the consumer silently rather than here.
        "2026-08-24T05:30:00+05:30",
    ],
)
def test_both_timestamp_spellings_are_accepted(spelling: str) -> None:
    """Q7's first divergence, resolved by making the seam tolerant rather than by moving a wire.

    The convention says `Z`; the producer emits `+00:00`; both are valid RFC 3339. Changing the
    producer was rejected because the same `Carbon` call appears in eight CLIENT-FACING resources,
    so it is a public wire change made to satisfy an internal convention. What must not happen is
    a fixture written from one document and a payload written from the other, so this asserts the
    tolerance directly.
    """
    body: Final[dict[str, Any]] = {
        "job_id": "01jqz0000000000000000000aa",
        "source": {
            "id": "01jqz0000000000000000000bb",
            "type": "upload",
            "name": "a source",
            "effective_at": spelling,
            "expires_at": None,
        },
        "items": [
            {
                "id": "01jqz0000000000000000000cc",
                "canonical_key": "k",
                "storage_key": "org/x/sources/y/original/" + "a" * 64,
                "content_hash": "a" * 64,
                "mime": "text/plain",
                "byte_size": 3,
            }
        ],
    }

    parsed = IngestionSubmissionRequest.model_validate(body)
    assert parsed.source.effective_at == spelling
