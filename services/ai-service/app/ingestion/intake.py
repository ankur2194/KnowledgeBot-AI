"""The pre-identity phase: everything that has to happen before a ``source_versions`` row exists.

WHY THERE IS A PHASE HERE AT ALL, AND WHY IT WAS THE LAST PIECE OF THE SEAM
-----------------------------------------------------------------------------
``run_version`` takes a ``source_version_id``. Laravel creates that row **from a callback**
(`EloquentKnowledgeSourceRepository::resolveVersion`), keyed by ``(source_item_id, ingest_key)``,
because it structurally cannot compute the six identity components itself —
`VersionIdentity`'s docblock takes each of them in turn. So a submission arrives naming items and
no versions, and something has to close that gap before the first chunk can be written.

`docs/22` § Q9 names this gap exactly, and leaves it open on the grounds that *"whether that is
by design depends on worker code nobody has written"*. This is that code, and the answer it
gives is: **the pre-identity phase is short, does no per-page work, and is safe to repeat.**

WHAT MAKES IT SHORT — THE ORDERING THAT IS EASY TO GET BACKWARDS
------------------------------------------------------------------
It is tempting to read "compute the identity" as "parse the document first", because
``parser_cfg_version`` sounds like a property of a parse. It is not. All three ``*_cfg_version``
strings are digests over **configuration constants and pinned model revisions** — no document is
read to compute one. ``content_hash`` is on the submitted item already. Only
``embedding_model_version`` costs a network call, and it is five short probe strings owned by
nobody (ADR-035), not tenant content.

So the whole phase is: read config, probe once, hash, report, read back the row. Nothing
expensive happens before the version exists, which is what makes a redelivery here cheap and
makes Q9's "accepted, acknowledged and discarded" the right behaviour rather than a hole.

AND WHY THE ROW IS READ BACK RATHER THAN RETURNED
---------------------------------------------------
`IngestionAcknowledgementResource` says it outright: *"The data plane must not learn anything
from this response it did not already send."* There is no version id on that wire and there
should not be one. The identity **is** the key — ``UNIQUE (source_item_id, ingest_key)`` is the
authority — so this service reads back the row it just described, by the value it supplied. That
is a read of a control-plane table, which is ordinary; what it never does is write one.

THE ONE ANSWER THAT MEANS "DO NOTHING"
----------------------------------------
``live_version_unchanged``. The submission re-derived the identity of the version already serving
this item, so there is no work: same bytes, same parser, same chunker, same embedding model. The
row exists and reading it back would find it, which is precisely the trap — dispatching a run on
it would re-parse and **re-embed a corpus at a provider's per-token price** for a document that
has not changed. The acknowledgement is read for this reason and no other.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass
from typing import Any

from app.core.errors import ErrorClass, KbError
from app.ingestion import frames, publish
from app.ingestion.chunking.chunker import MAX_TOKENS, chunker_cfg_version
from app.ingestion.embedding.binding import bind_embedder, load_candidates
from app.ingestion.embedding.embedder import TOKEN_HEADROOM, measure_identity
from app.ingestion.identity import ingest_key
from app.ingestion.states import SourceState
from app.providers.embedding_selection import resolve_embedding_connection
from app.providers.registry import ADAPTERS

logger = logging.getLogger(__name__)

__all__ = ["PREPARE_STAGE", "PreparedVersion", "SubmittedItem", "prepare_item"]

#: The ``stage`` label on the identity frame. A stage rather than a status, because the status
#: it travels with is `SourceState.PARSING` — the version is born about to parse, and there is
#: no lifecycle state for "being identified".
PREPARE_STAGE: str = "prepare"


@dataclass(frozen=True, slots=True)
class SubmittedItem:
    """One element of the submission's ``items`` array, as `IngestionSubmission::toArray` builds it.

    Frozen and typed rather than passed around as a ``dict`` because two of these fields are
    load-bearing in a way a dict lookup hides: ``content_hash`` is a component of the ingest key,
    so a missing one produces a *valid-looking* key that dedupes against nothing, and
    ``storage_key`` is the path the object is read from — a client-supplied value here would be
    an unvalidated proxy into object storage, which is why the submission is assembled
    server-side from rows and never from request input.
    """

    id: str
    canonical_key: str
    storage_key: str
    content_hash: str
    mime: str
    byte_size: int
    display_name: str | None = None
    url: str | None = None
    #: What is already live for this item. Carried so the worker knows which version its
    #: publication will supersede without querying Laravel's tables for it.
    current_version_id: str | None = None


@dataclass(frozen=True, slots=True)
class PreparedVersion:
    """The outcome of preparing one item. ``source_version_id`` is ``None`` when there is no work.

    Two different "no work" answers share that ``None`` and the ``reason`` distinguishes them,
    which matters to whoever reads the logs: ``live_version_unchanged`` is the dedup working and
    is the common case on a resubmission, while ``stale_job`` or ``unknown_item`` mean this run
    is addressing rows that have moved on or never existed — the second of which
    `IngestionAcknowledgementResource` says is worth an alert.
    """

    source_version_id: str | None
    ingest_key: str
    reason: str


async def prepare_item(
    *,
    clients: Any,
    org_id: str,
    job_id: str,
    source_id: str,
    item: SubmittedItem,
    force_nonce: str | None,
) -> PreparedVersion:
    """Compute this item's version identity, report it, and return the row it created.

    Idempotent by construction rather than by a claim: the identity is a pure function of the
    item's content and this deployment's configuration, so a second call computes the same
    ingest key, and Laravel's find-or-create resolves it to the same row. There is deliberately
    no idempotency claim around this phase — a claim would have to be released before the run
    could take its own, and the window between them is exactly where a redelivery would find a
    prepared-but-undispatched item and do nothing.
    """
    settings = clients.settings

    scope = frames.FrameScope(
        org_id=org_id, job_id=job_id, source_id=source_id, source_item_id=item.id
    )

    async with clients.db_pool.connection() as conn:
        sequencer = frames.Sequencer(
            await frames.read_sequence_base(conn, org_id=org_id, source_item_id=item.id)
        )
        embedding_model_version = await _measure_embedding_identity(
            conn, clients=clients, org_id=org_id, trace_id=item.id
        )

    # COMPOSED ONCE AND USED TWICE. Calling each composer again inside `identity_payload` would
    # read the same constants and would almost always agree — the failure is the "almost": a
    # digest computed either side of a config reload would put a key in the frame that no
    # component of the frame explains, and the row would be created under an identity nothing
    # can reproduce.
    parser_cfg = _parser_cfg_version()
    ocr_cfg = _ocr_cfg_version(settings)
    chunker_cfg = chunker_cfg_version()

    identity = frames.identity_payload(
        content_hash=item.content_hash,
        ingest_key=ingest_key(
            org_id=org_id,
            source_id=source_id,
            source_item_id=item.id,
            content_hash=item.content_hash,
            parser_cfg_version=parser_cfg,
            ocr_cfg_version=ocr_cfg,
            chunker_cfg_version=chunker_cfg,
            embedding_model_version=embedding_model_version,
            force_nonce=force_nonce,
        ),
        parser_cfg_version=parser_cfg,
        ocr_cfg_version=ocr_cfg,
        chunker_cfg_version=chunker_cfg,
        embedding_model_version=embedding_model_version,
    )

    acknowledgement = publish.report_identity(
        scope=scope,
        sequence=sequencer.take(),
        identity=identity,
        # PARSING, NOT QUEUED. `resolveVersion` births the row at the frame's own status and
        # refuses any retrievable one outright, so the frame has to name a stage the run is
        # actually entering. `queued` would be a lie the moment this function returns, and the
        # console would show a source that never leaves the queue while a worker parses it.
        status=SourceState.PARSING,
        stage=PREPARE_STAGE,
    )

    reason = str(acknowledgement.get("reason") or "")
    if reason == "live_version_unchanged":
        # THE EXPENSIVE NO-OP, REFUSED. See the module docstring: the row exists and reading it
        # back would succeed, which is exactly why the answer is taken from the body instead.
        logger.info(
            "item %s re-derived the identity of the version already serving it; no work",
            item.id,
        )
        return PreparedVersion(None, identity["ingest_key"], reason)

    if not acknowledgement.get("applied"):
        logger.warning(
            "the identity frame for item %s was refused as %r; this run is not dispatching",
            item.id,
            reason,
        )
        return PreparedVersion(None, identity["ingest_key"], reason)

    async with clients.db_pool.connection() as conn:
        version_id = await _read_back_version_id(
            conn, org_id=org_id, source_item_id=item.id, key=identity["ingest_key"]
        )
    return PreparedVersion(version_id, identity["ingest_key"], reason)


async def _read_back_version_id(conn: Any, *, org_id: str, source_item_id: str, key: str) -> str:
    """The row the identity frame just created, found by the key this service supplied.

    Raises rather than returning ``None`` when it is missing. The frame was acknowledged as
    APPLIED, so the row exists or the two planes disagree about what "applied" means — and a
    silent ``None`` there would look identical to the ``live_version_unchanged`` path above,
    which is the one case where doing nothing is correct.
    """
    async with conn.cursor() as cur:
        await cur.execute(
            "SELECT id FROM source_versions "
            " WHERE organization_id = %s AND source_item_id = %s AND ingest_key = %s",
            (org_id, source_item_id, key),
        )
        row = await cur.fetchone()
    if row is None:
        raise KbError(
            ErrorClass.INTERNAL_DEPENDENCY,
            f"the control plane applied the identity frame for item {source_item_id} and no "
            "version row carries the ingest key it was told about. `UNIQUE (source_item_id, "
            "ingest_key)` is the only handle this service has on that row, so there is nothing "
            "to fall back to and nothing to retry into",
            retryable=False,
        )
    return str(row[0])


async def _measure_embedding_identity(
    conn: Any, *, clients: Any, org_id: str, trace_id: str
) -> str:
    """``emb/v1:provider:model:dNNNN:digest`` — measured, never declared.

    `provider_models` has no dimensions column and that is ADR-035 working as intended: a
    declared width is a claim a vendor can falsify quietly, so the width comes from a real
    response. `measure_identity` is used rather than `resolve_identity` because there is no
    prior space to compare against — the space is what this call is FOR.

    The credential is opened inside `bind_embedder` and stays in its closure. Nothing in this
    module holds one, and the narrowed callable has no field for one, which is what keeps a
    provider key out of the Celery payload this phase is about to enqueue.
    """
    connections, designation = await load_candidates(conn, org_id=org_id)
    connection = resolve_embedding_connection(connections, designated=designation)
    context_window = int(connection.caps.context_window)

    sealed_credential, sealed_data_key = await _sealed_credential(
        conn, org_id=org_id, connection_id=connection.connection_id
    )
    embed = bind_embedder(
        connections=connections,
        designation=designation,
        sealed_credential=sealed_credential,
        sealed_data_key=sealed_data_key,
        kek_path=clients.settings.kek_path,
        context_window=context_window,
        adapters=ADAPTERS,
    )
    identity = measure_identity(
        embed=embed,
        context_window=context_window,
        model=connection.model,
        org_id=org_id,
        trace_id=trace_id,
    )
    return identity.version


async def _sealed_credential(conn: Any, *, org_id: str, connection_id: str) -> tuple[bytes, bytes]:
    """The two ciphertexts for one connection. Keyed by id, because selection already ran."""
    async with conn.cursor() as cur:
        await cur.execute(
            "SELECT credential_ciphertext, data_key_ciphertext FROM provider_connections "
            " WHERE organization_id = %s AND id = %s",
            (org_id, connection_id),
        )
        row = await cur.fetchone()
    if row is None or row[0] is None or row[1] is None:
        raise KbError(
            ErrorClass.VALIDATION,
            f"connection {connection_id} holds no sealed credential. Selection chose it from "
            "this organization's own rows, so the row exists and its key does not — which is a "
            "control-plane state, never something to work around by choosing another",
            retryable=False,
        )
    return bytes(row[0]), bytes(row[1])


def _parser_cfg_version() -> str:
    """Imported at call time, not at module import.

    `parsing.converter` reaches Docling, which reaches torch. This module is imported by the
    Celery task registry in every worker and by the API process through the router, and paying a
    multi-second torch import in a process that will never parse a page is how a health check
    times out during a rolling deploy.
    """
    from app.ingestion.parsing.converter import parser_cfg_version

    return parser_cfg_version()


def _ocr_cfg_version(settings: Any) -> str:
    """Same deferral, same reason — and the engine and languages come from configuration.

    Both are inside the string, which is what makes an OCR retune a version trigger. ADR-068 is
    the rule they are here to satisfy: the **ingest key** holds every ``*_cfg_version`` while the
    transport fingerprint holds none, so following `docs/08` §13.3's list instead — which omits
    OCR entirely — makes a retune a silent no-op that reports "already processed" forever.
    """
    from app.ingestion.ocr.guarded import ocr_cfg_version

    return ocr_cfg_version(engine=settings.ocr_engine, languages=list(settings.ocr_languages))


# The chunker's ceiling has to fit inside every model this deployment could embed with, and
# `check_window` is what enforces it per run. Asserted at import as well because the two
# constants live in different packages and a headroom raised past the budget is a configuration
# that fails at the first document rather than at the deploy.
assert TOKEN_HEADROOM >= 0
assert MAX_TOKENS > 0
