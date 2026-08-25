"""One ingestion callback frame, built from run state. The wire shape lives here and nowhere else.

``services/core-api/app/Http/Requests/IngestionCallbackRequest.php`` is the other half of this
file, and every rule it states is a rule this module has to satisfy before a frame leaves the
process. It is kept separate from ``callback.py`` — which signs and posts bytes — and from
``publish.py`` — which describes the publication sequence — because the three fail differently:
a transport bug is a 5xx anybody can see, a sequence bug is a version that never activates, and
a **frame** bug is a 200 with ``applied: false`` that looks exactly like the ordering guard
working normally.

THE THREE FIELDS THAT FAIL SILENTLY, AND WHY EACH IS A NAMED ARGUMENT HERE
---------------------------------------------------------------------------
* ``job_id`` is **Laravel's ingestion job id from the submission**, not the version id and not
  the item id. ``source_items.current_job_id`` is compared against it and a mismatch is
  ``stale_job`` — a 200, ``applied: false``, no error anywhere. A worker that sends the wrong
  value has every frame of every run discarded while the queue drains normally and the console
  shows a source stuck at ``queued`` forever.
* ``sequence`` is applied under ``WHERE sequence > progress_sequence``, so it must continue the
  item's existing count rather than restart at 1. A resumed run that restarts its counter gets
  its progress frames dropped — survivable, they are advisory — and then its **readiness** frame
  dropped too, which is not: the version verified, the points are on disk, and nothing will ever
  activate them. `Sequencer` is seeded from the row for exactly this reason.
* ``verified`` must be a JSON literal. ``LiteralBoolean`` refuses ``1`` and ``"true"`` (finding
  Q4), and the failure it replaced was a frame that validated, published nothing, and returned
  200 forever. Python's ``json`` emits real literals for ``bool``, so the rule here is simply
  never to compute this field as anything but a ``bool``.

WHAT IS DELIBERATELY NOT SENT
------------------------------
No ``X-KB-Bot-Id`` (ADR-067 — a knowledge source is organization-owned), no credential, no
document text, and no storage key. The frame carries identifiers, counts and a verdict.
"""

from __future__ import annotations

from collections.abc import Mapping
from dataclasses import dataclass
from typing import Any, Final

from app.core.errors import ErrorClass, KbError
from app.ingestion.states import SourceState

__all__ = [
    "MAX_STAGE_CHARS",
    "FrameScope",
    "Sequencer",
    "identity_payload",
    "progress_frame",
    "read_sequence_base",
    "readiness_frame",
]

#: ``stage`` is ``max:64`` on the far side. Truncated rather than refused, because the stage
#: label is an observability field and losing a whole frame — with its status and its sequence —
#: over a long label would trade a cosmetic problem for a real one.
MAX_STAGE_CHARS: Final[int] = 64


@dataclass(frozen=True, slots=True)
class FrameScope:
    """The four identifiers on every frame, resolved once per run and then never recomputed.

    ``source_version_id`` is **not** here and that is not an oversight: it is absent from the
    callback contract entirely. Laravel finds or creates the version row from the identity in
    the frame, keyed by ``(source_item_id, ingest_key)``, so a version id on the wire would be
    a second, unauthoritative name for a row this service does not own.
    """

    org_id: str
    #: From the submission. See the module docstring — this is the field whose wrong value is
    #: invisible.
    job_id: str
    source_id: str
    source_item_id: str


class Sequencer:
    """Strictly increasing frame sequence for one run, seeded from the item's existing count.

    Not a counter starting at 1, and not a timestamp. Starting at 1 loses a resumed run's
    readiness frame (module docstring); a timestamp is not guaranteed monotonic across a clock
    step and is bounded on the far side by ``MAX_SEQUENCE`` anyway.
    """

    __slots__ = ("_next",)

    def __init__(self, base: int) -> None:
        if base < 0:
            raise KbError(
                ErrorClass.VALIDATION,
                f"sequence base {base} is negative, so the first frame would be at or below "
                "the guard and every frame of this run would be discarded as out of order",
                retryable=False,
            )
        # `base + 1`: the guard is strictly greater-than and `progress_sequence` holds the LAST
        # applied value, so the next frame is one past it.
        self._next = base + 1

    def take(self) -> int:
        value = self._next
        self._next += 1
        return value

    def peek(self) -> int:
        return self._next


async def read_sequence_base(conn: Any, *, org_id: str, source_item_id: str) -> int:
    """``source_items.progress_sequence`` for this item. A READ of a control-plane table.

    Permitted and routine — the data plane reads the source of truth constantly, which is what
    makes Qdrant rebuildable. What it never does is write this table: ``source_items`` is served
    by the public API, so ADR-033 property 2 fails for it and it is not in ``ALLOWED_TABLES``.

    A missing row returns 0 rather than raising. The item may have been deleted mid-run, in
    which case every frame this run sends will be refused as ``unknown_item`` — which is the
    correct outcome and is reached without a second failure mode here.
    """
    async with conn.cursor() as cur:
        await cur.execute(
            "SELECT progress_sequence FROM source_items WHERE organization_id = %s AND id = %s",
            (org_id, source_item_id),
        )
        row = await cur.fetchone()
    return int(row[0]) if row and row[0] is not None else 0


def identity_payload(
    *,
    content_hash: str,
    ingest_key: str,
    parser_cfg_version: str,
    ocr_cfg_version: str,
    chunker_cfg_version: str,
    embedding_model_version: str,
) -> dict[str, str]:
    """The six components of ``version``, all six or none.

    Built by a function rather than a dict literal at the call site because the far side treats
    a PARTIAL identity as a 422 and an EMPTY one as absent — ``required_with`` reads ``[]`` as
    missing, so ``{"version": {}}`` validates clean, reaches ``VersionIdentity::fromArray()``
    and raises a 500 that the taxonomy marks retryable, redelivering a frame that will fail
    identically forever. Six required keyword arguments make the empty case unconstructible.
    """
    values = {
        "content_hash": content_hash,
        "ingest_key": ingest_key,
        "parser_cfg_version": parser_cfg_version,
        "ocr_cfg_version": ocr_cfg_version,
        "chunker_cfg_version": chunker_cfg_version,
        "embedding_model_version": embedding_model_version,
    }
    blank = sorted(name for name, value in values.items() if not value)
    if blank:
        raise KbError(
            ErrorClass.VALIDATION,
            f"the version identity is missing {blank}. A blank component is a component of the "
            "ingest key that contributes nothing, which makes the version trigger it represents "
            "permanently undetectable — the admin sees 'already processed' forever",
            retryable=False,
        )
    return values


def progress_frame(
    *,
    scope: FrameScope,
    sequence: int,
    stage: str,
    status: SourceState,
    chunk_count: int | None = None,
    warning_summary: Mapping[str, int] | None = None,
    identity: Mapping[str, str] | None = None,
    delivery_count: int | None = None,
) -> dict[str, Any]:
    """One mid-run frame. Optional fields are OMITTED when unset, never sent as ``null``.

    Omission and ``null`` are different on the far side for two of them. ``verified`` defaults
    to false by omission and is what gates ``Indexing -> Ready``; ``version`` fires six
    ``required_with`` rules the moment the key is present. Sending ``"version": null`` would
    make a frame that carries no identity indistinguishable, to a reader of the wire, from one
    that failed to build its identity.
    """
    frame: dict[str, Any] = {
        "job_id": scope.job_id,
        "source_id": scope.source_id,
        "source_item_id": scope.source_item_id,
        "sequence": sequence,
        "stage": stage[:MAX_STAGE_CHARS],
        "status": status.value,
    }
    if identity is not None:
        frame["version"] = dict(identity)
    if delivery_count is not None:
        frame["delivery_count"] = delivery_count
    if chunk_count is not None:
        frame["chunk_count"] = chunk_count
    if warning_summary:
        frame["warning_summary"] = dict(warning_summary)
    return frame


def readiness_frame(
    *,
    scope: FrameScope,
    sequence: int,
    verified: bool,
    chunk_total: int,
    identity: Mapping[str, str],
    warning_summary: Mapping[str, int] | None = None,
    delivery_count: int | None = None,
    error_class: str | None = None,
) -> dict[str, Any]:
    """The terminal frame: the one that can activate a version, or fail it.

    ``ReadinessReport.state`` is an INTERNAL vocabulary — ``indexed_verified`` is not a
    ``SourceState`` and never was — so this is where it becomes one of the fifteen the control
    plane's enum admits. The translation is the whole function and it has exactly three arms:

    * verified, no warnings   -> ``ready``               + ``verified: true``
    * verified, with warnings -> ``ready_with_warnings`` + ``verified: true``
    * not verified            -> ``failed``              + ``error_class``

    **``verified`` is never sent as true on a failing frame**, and the arms are written so that
    is structural rather than remembered: the flag and the status are computed from the same
    boolean. A frame that said ``failed`` while claiming verification would be refused at the
    transition table, which is the guard working — but a frame that said ``ready`` while the
    proof did not pass would publish an unindexed version, and nothing downstream would object.
    """
    if verified:
        status = SourceState.READY_WITH_WARNINGS if warning_summary else SourceState.READY
    else:
        status = SourceState.FAILED

    frame = progress_frame(
        scope=scope,
        sequence=sequence,
        stage="publish",
        status=status,
        chunk_count=chunk_total,
        warning_summary=warning_summary,
        identity=identity,
        delivery_count=delivery_count,
    )
    # A LITERAL `bool`, never an int and never a string. `LiteralBoolean` on the far side
    # refuses `1` and `"true"` (finding Q4), and the failure that rule was written for looked
    # exactly like success: 200, nothing published, forever.
    frame["verified"] = bool(verified)
    if not verified and error_class is not None:
        frame["error_class"] = error_class
    return frame
