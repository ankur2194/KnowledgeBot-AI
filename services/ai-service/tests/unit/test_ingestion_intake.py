"""The pre-identity phase — `docs/22` § Q9's gap, and the one answer that means "do nothing".

Two things are asserted here that no end-to-end run would surface as a failure:

* ``live_version_unchanged`` must **not** dispatch. The version row exists, so reading it back
  succeeds; dispatching on it re-parses and re-embeds a corpus at a provider's per-token price
  for a document that did not change, and every frame of that run reports success.
* the identity must be composed **once**. Two calls to the same composer would agree almost
  always, and the "almost" is a row created under a key nothing can reproduce.
"""

from __future__ import annotations

from typing import Any

import pytest

from app.core.errors import KbError
from app.ingestion import intake, publish
from app.ingestion.states import SourceState

ORG = "01JQZ0000000000000000000AA"
JOB = "01JQZ0000000000000000000BB"
SOURCE = "01JQZ0000000000000000000CC"
ITEM = intake.SubmittedItem(
    id="01JQZ0000000000000000000DD",
    canonical_key="handbook.pdf",
    storage_key="org/01JQZ.../sources/01JQZ.../original/" + "a" * 64,
    content_hash="a" * 64,
    mime="application/pdf",
    byte_size=1024,
)

EMB = "emb/v1:openai:text-embedding-3-large:d3072:9f2a1c4e77b1"


class _Cursor:
    def __init__(self, rows: dict[str, Any]) -> None:
        self._rows = rows
        self._row: Any = None
        self.statements: list[str] = []

    async def execute(self, sql: str, params: Any) -> None:
        self.statements.append(sql)
        for marker, row in self._rows.items():
            if marker in sql:
                self._row = row
                return
        self._row = None

    async def fetchone(self) -> Any:
        return self._row

    async def __aenter__(self) -> _Cursor:
        return self

    async def __aexit__(self, *exc: Any) -> None:
        return None


class _Conn:
    def __init__(self, rows: dict[str, Any]) -> None:
        self.cursor_obj = _Cursor(rows)

    def cursor(self) -> _Cursor:
        return self.cursor_obj


class _Pool:
    def __init__(self, rows: dict[str, Any]) -> None:
        self.rows = rows
        self.conns: list[_Conn] = []

    def connection(self) -> Any:
        pool = self

        class _Ctx:
            async def __aenter__(self) -> _Conn:
                conn = _Conn(pool.rows)
                pool.conns.append(conn)
                return conn

            async def __aexit__(self, *exc: Any) -> None:
                return None

        return _Ctx()


class _Settings:
    ocr_engine = "rapidocr"
    ocr_languages = ("en",)
    kek_path = None


class _Clients:
    def __init__(self, rows: dict[str, Any]) -> None:
        self.db_pool = _Pool(rows)
        self.settings = _Settings()


@pytest.fixture
def wired(monkeypatch: pytest.MonkeyPatch) -> list[tuple[dict[str, Any], str, str]]:
    """Replace the three expensive composers and capture what the emitter is handed.

    The parser and OCR composers are patched because reaching the real ones imports Docling and
    torch; the embedding one because it is a paid network call. What is NOT patched is
    `frames.identity_payload` or `identity.ingest_key` — the hashing under test.
    """
    monkeypatch.setattr(intake, "_parser_cfg_version", lambda: "parser/v1:docling2.118.0:aaaa")
    monkeypatch.setattr(intake, "_ocr_cfg_version", lambda settings: "ocr/v1:rapidocr:bbbb")

    async def _measured(conn: Any, **kwargs: Any) -> str:
        return EMB

    monkeypatch.setattr(intake, "_measure_embedding_identity", _measured)

    sent: list[tuple[dict[str, Any], str, str]] = []
    return sent


def _install(sent: list[Any], acknowledgement: dict[str, Any]) -> None:
    def emit(frame: dict[str, Any], org_id: str, operation: str) -> dict[str, Any]:
        sent.append((frame, org_id, operation))
        return acknowledgement

    publish.set_readiness_emitter(emit)


@pytest.fixture(autouse=True)
def _clear_emitters() -> Any:
    yield
    publish.set_readiness_emitter(None)
    publish.set_progress_emitter(None)


@pytest.mark.anyio
async def test_an_applied_frame_returns_the_version_read_back_by_ingest_key(
    wired: list[Any],
) -> None:
    """`IngestionAcknowledgementResource` deliberately returns no version id — "the data plane
    must not learn anything from this response it did not already send" — so the row is found by
    the key this service supplied, which is what `UNIQUE (source_item_id, ingest_key)` is for."""
    _install(wired, {"applied": True, "reason": "applied", "status": "parsing"})
    clients = _Clients({"progress_sequence": (4,), "FROM source_versions": ("01JQZVERSION",)})

    prepared = await intake.prepare_item(
        clients=clients, org_id=ORG, job_id=JOB, source_id=SOURCE, item=ITEM, force_nonce=None
    )

    assert prepared.source_version_id == "01JQZVERSION"
    assert prepared.reason == "applied"


@pytest.mark.anyio
async def test_the_identity_frame_names_the_submission_job_and_the_parsing_status(
    wired: list[Any],
) -> None:
    """PARSING and not QUEUED: `resolveVersion` births the row at the frame's own status, so
    `queued` would be a lie the moment this returns — a source that never leaves the queue in the
    console while a worker parses it."""
    _install(wired, {"applied": True, "reason": "applied"})
    clients = _Clients({"progress_sequence": (0,), "FROM source_versions": ("01JQZVERSION",)})

    await intake.prepare_item(
        clients=clients, org_id=ORG, job_id=JOB, source_id=SOURCE, item=ITEM, force_nonce=None
    )

    frame, org_id, operation = wired[0]
    assert org_id == ORG
    assert operation == "ingestion.identity"
    assert frame["job_id"] == JOB
    assert frame["status"] == SourceState.PARSING.value
    assert frame["stage"] == intake.PREPARE_STAGE
    assert frame["sequence"] == 1
    assert set(frame["version"]) == {
        "content_hash",
        "ingest_key",
        "parser_cfg_version",
        "ocr_cfg_version",
        "chunker_cfg_version",
        "embedding_model_version",
    }


@pytest.mark.anyio
async def test_the_frame_continues_the_items_existing_sequence(wired: list[Any]) -> None:
    _install(wired, {"applied": True, "reason": "applied"})
    clients = _Clients({"progress_sequence": (12,), "FROM source_versions": ("01JQZVERSION",)})

    await intake.prepare_item(
        clients=clients, org_id=ORG, job_id=JOB, source_id=SOURCE, item=ITEM, force_nonce=None
    )
    assert wired[0][0]["sequence"] == 13


@pytest.mark.anyio
async def test_live_version_unchanged_does_not_dispatch_even_though_the_row_exists(
    wired: list[Any],
) -> None:
    """THE EXPENSIVE NO-OP. The row is there and a read-back would find it, which is exactly why
    the answer is taken from the acknowledgement body instead of from the database."""
    _install(wired, {"applied": False, "reason": "live_version_unchanged"})
    clients = _Clients({"progress_sequence": (0,), "FROM source_versions": ("01JQZLIVE",)})

    prepared = await intake.prepare_item(
        clients=clients, org_id=ORG, job_id=JOB, source_id=SOURCE, item=ITEM, force_nonce=None
    )

    assert prepared.source_version_id is None
    assert prepared.reason == "live_version_unchanged"


@pytest.mark.anyio
@pytest.mark.parametrize("reason", ["stale_job", "unknown_item", "item_source_mismatch"])
async def test_a_refused_frame_does_not_dispatch(wired: list[Any], reason: str) -> None:
    _install(wired, {"applied": False, "reason": reason})
    clients = _Clients({"progress_sequence": (0,), "FROM source_versions": ("01JQZVERSION",)})

    prepared = await intake.prepare_item(
        clients=clients, org_id=ORG, job_id=JOB, source_id=SOURCE, item=ITEM, force_nonce=None
    )
    assert prepared.source_version_id is None
    assert prepared.reason == reason


@pytest.mark.anyio
async def test_an_applied_frame_with_no_matching_row_raises_rather_than_returning_none(
    wired: list[Any],
) -> None:
    """A silent `None` there would be indistinguishable from `live_version_unchanged`, which is
    the one case where doing nothing is correct."""
    _install(wired, {"applied": True, "reason": "applied"})
    clients = _Clients({"progress_sequence": (0,)})

    with pytest.raises(KbError, match="no version row carries the ingest key"):
        await intake.prepare_item(
            clients=clients, org_id=ORG, job_id=JOB, source_id=SOURCE, item=ITEM, force_nonce=None
        )


@pytest.mark.anyio
async def test_the_force_nonce_changes_the_ingest_key(wired: list[Any]) -> None:
    """Without it a resubmission of unchanged content composes the same key, dedupes against the
    completed version, and the admin sees "already processed" forever."""
    keys = []
    for nonce in (None, "reprocess-1"):
        sent: list[Any] = []
        _install(sent, {"applied": True, "reason": "applied"})
        clients = _Clients({"progress_sequence": (0,), "FROM source_versions": ("01JQZV",)})
        await intake.prepare_item(
            clients=clients,
            org_id=ORG,
            job_id=JOB,
            source_id=SOURCE,
            item=ITEM,
            force_nonce=nonce,
        )
        keys.append(sent[0][0]["version"]["ingest_key"])

    assert keys[0] != keys[1]


@pytest.mark.anyio
async def test_the_ingest_key_is_stable_for_the_same_inputs(wired: list[Any]) -> None:
    """Idempotency by construction rather than by a claim: a redelivery composes the same key,
    and Laravel's find-or-create resolves it to the same row."""
    keys = []
    for _ in range(2):
        sent: list[Any] = []
        _install(sent, {"applied": True, "reason": "applied"})
        clients = _Clients({"progress_sequence": (0,), "FROM source_versions": ("01JQZV",)})
        await intake.prepare_item(
            clients=clients, org_id=ORG, job_id=JOB, source_id=SOURCE, item=ITEM, force_nonce=None
        )
        keys.append(sent[0][0]["version"]["ingest_key"])

    assert keys[0] == keys[1]


@pytest.mark.anyio
async def test_no_emitter_raises_rather_than_silently_skipping_the_row(wired: list[Any]) -> None:
    """Every stage after this one writes against a version id, so there is nothing to degrade
    to: an unwired worker would run to the end of the pipeline and fail at the last statement."""
    publish.set_readiness_emitter(None)
    clients = _Clients({"progress_sequence": (0,)})

    with pytest.raises(KbError, match="no version row will ever exist"):
        await intake.prepare_item(
            clients=clients, org_id=ORG, job_id=JOB, source_id=SOURCE, item=ITEM, force_nonce=None
        )
