"""Laravel sends lowercase model keys and uppercase job ids, in the same body, and both must pass.

WHY THIS FILE EXISTS AND WHY 1,740 GREEN TESTS DID NOT COVER IT
--------------------------------------------------------------
`ULID_PATTERN` was `^[0-7][0-9A-HJKMNP-TV-Z]{25}$` in four modules at once — the ingestion router,
the maintenance router, `providers/contract.py` and `indexing/upserter.py`, which asserts it on
every point before a write. Crockford's base32 is defined case-INSENSITIVELY; that pattern is not.

Laravel uses both spellings and does so on one request:

* `HasUlids::newUniqueId()` lowercases, so every model key that crosses the seam is lowercase —
  `source.id`, `items[].id`, `current_version_id`.
* an id minted with `Str::ulid()` keeps the canonical uppercase — `source_items.current_job_id`,
  which travels as `job_id`.

So the FIRST real submission from the FIRST real tenant was refused with 422 on `source.id` and
`items.0.id` while `job_id` passed, on 2026-08-24 (`docs/22` § R6). The suite could not see it
because `tests/support/signing.ulid_like()` emits uppercase, so every fixture in this repository
agreed with the bug.

That is the lesson worth pinning: a shared test helper that produces one spelling makes a
case-sensitivity defect invisible across every test that uses it, however many there are.

WHAT IS *NOT* BEING RELAXED
---------------------------
The assertion's purpose is to catch a **UUID-shaped** identifier, which `upserter.py` documents at
length: a UUID in `org_id` satisfies no `must` term, so every bot in every organization retrieves
nothing at HTTP 200 with no exception and no log line. A UUID has hyphens and uses `i`/`l`/`o`/`u`,
none of which Crockford's alphabet contains, so admitting lowercase costs that check nothing — and
the negative controls below are here to keep it that way.

NOTHING IS NORMALISED, and that is deliberate. An id is compared byte-for-byte in a Qdrant filter
and in the `chunks` table, so lowering or upper-casing at the boundary would give one row two
identities — the same class of failure, arriving later and harder to see.
"""

from __future__ import annotations

import re
from collections.abc import Callable
from typing import Any

import pytest

from app.ingestion.indexing.upserter import ULID_PATTERN
from tests.support.signing import Signer, ulid_like

PATH = "/internal/v1/ingestion/jobs"

#: A real key, taken verbatim from the row `POST /api/v1/organizations/{org}/sources` wrote on
#: 2026-08-24. Not generated: the point is that this exact spelling came out of Laravel.
LARAVEL_MODEL_KEY = "01m0swft82zf81qx2d032qepb7"

#: From `source_items.current_job_id` in the same row, in the same second.
LARAVEL_JOB_ID = "01M0SWFTKSNR0MJ0WHQ39MBMVW"


def test_the_two_spellings_really_do_differ_only_in_case() -> None:
    """A vacuity control. If these ever became the same string the tests below prove nothing."""
    assert LARAVEL_MODEL_KEY != LARAVEL_JOB_ID
    assert LARAVEL_MODEL_KEY.lower() == LARAVEL_MODEL_KEY
    assert LARAVEL_JOB_ID.upper() == LARAVEL_JOB_ID
    assert ulid_like("anything").upper() == ulid_like("anything"), (
        "the shared fixture helper emits uppercase only, which is why it hid this for so long"
    )


@pytest.mark.parametrize("value", [LARAVEL_MODEL_KEY, LARAVEL_JOB_ID])
def test_the_write_side_pattern_admits_both_spellings(value: str) -> None:
    assert re.match(ULID_PATTERN, value) is not None


@pytest.mark.parametrize(
    "value",
    [
        "0e8a1c2d-3f4b-5a6c-8d9e-0f1a2b3c4d5e",  # a UUID, the shape the assert exists to catch
        "01m0swft82zf81qx2d032qepb",  # 25 characters
        "01m0swft82zf81qx2d032qepb77",  # 27 characters
        "81m0swft82zf81qx2d032qepb7",  # leading character above 7
        "01m0swflu2zf81qx2d032qepb7",  # `l` and `u`, absent from Crockford's alphabet
    ],
)
def test_the_widening_did_not_defeat_the_check(value: str) -> None:
    assert re.match(ULID_PATTERN, value) is None


@pytest.fixture
def captured_dispatch(monkeypatch: pytest.MonkeyPatch) -> list[dict[str, Any]]:
    """Intercept the broker publish. Declared here rather than imported: an `autouse` fixture in a
    sibling test module does not apply to this one, and without it a 202 would try to reach a real
    Celery connection."""
    from app.worker import celery_app

    sent: list[dict[str, Any]] = []

    def _send_task(name: str, **kwargs: Any) -> None:
        sent.append({"name": name, **kwargs})

    monkeypatch.setattr(celery_app, "send_task", _send_task)
    return sent


def _laravel_submission() -> dict[str, Any]:
    """`IngestionSubmission::toArray()`, with the identifiers spelled as Laravel spells them."""
    return {
        "job_id": LARAVEL_JOB_ID,
        "source": {
            "id": LARAVEL_MODEL_KEY,
            "type": "upload",
            "name": "W8 end-to-end proof",
            "origin_url": None,
            "tags": [],
            "effective_at": None,
            "expires_at": None,
        },
        "items": [
            {
                "id": "01m0swftm1kzvvj4wssvg96s13",
                "canonical_key": f"org/x/sources/{LARAVEL_MODEL_KEY}/original/" + "9" * 64,
                "url": None,
                "display_name": "w8-proof.md",
                "storage_key": f"org/x/sources/{LARAVEL_MODEL_KEY}/original/" + "9" * 64,
                "content_hash": "9" * 64,
                "mime": "text/plain",
                "byte_size": 106,
                "current_version_id": None,
            }
        ],
        "force_nonce": None,
    }


@pytest.mark.anyio
async def test_a_submission_spelled_the_way_laravel_spells_it_is_accepted(
    signer: Signer, send: Callable[..., Any], captured_dispatch: list[dict[str, Any]]
) -> None:
    """The regression, end to end over a real signature: lowercase ids and an uppercase job id in
    one body, which is the combination that arrived from the control plane and was refused."""
    body = _laravel_submission()

    response = await send(
        signer.build("POST", PATH, body, operation="ingestion.submit", idempotency_key="k" * 64)
    )

    assert response.status_code == 202, response.json()
    assert response.json() == {"job_id": LARAVEL_JOB_ID, "accepted_items": 1}
