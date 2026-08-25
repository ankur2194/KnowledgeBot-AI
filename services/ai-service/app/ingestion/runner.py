"""What ``tasks.run_version`` does, minus Celery. The half a test can drive.

``tasks.py`` holds the decorators, the limits and the retry call, because those are Celery's.
Everything they *wrap* is here: the delivery counter, the idempotency claim, the item lock, the
version read, the identity resolution, the stage walk's invocation, publication, and the
classification that decides whether any of it is retried.

The split is not tidiness. A Celery task body is reachable only through a worker or through a
test harness that fakes one, and a harness that fakes a worker mostly proves the harness. Every
function here takes its clients as arguments and returns or raises, so the interesting
behaviour — a redelivery finding a live claim, a non-retryable class failing the version, the
claim being released *before* a retry rather than after — is exercised directly.

THE THREE GUARDS, AND WHY THEIR ORDER IS FIXED
-----------------------------------------------
1. **The durable delivery counter**, first, because it is the only one that survives the
   process. ``request.retries`` is not it: a requeue after an OOM leaves ``retries`` at 0, so
   ``max_retries`` never trips and a document that reliably kills the child loops forever,
   pinning a worker slot. The counter is a column on ``source_versions``.
2. **The idempotency claim**, second, as a single atomic set-if-absent. A read followed by a
   write has a losing side that runs the whole pipeline twice — same parse, same embed spend,
   two versions racing the pointer.
3. **The item lock**, third and held for the whole run. The claim keys on the *ingest key* and
   the lock keys on the *item*, and they are not the same scope: two different ingest keys for
   one item — a reprocess arriving while the first run is mid-flight — pass the claim and must
   still not run concurrently.

Getting 1 and 2 the other way round is the subtle one: claiming before counting means an
exhausted delivery cap still burns a claim, and the claim outlives the run by a day.
"""

from __future__ import annotations

import contextlib
import logging
import random
from collections.abc import Callable, Iterator
from datetime import datetime
from typing import Any

from app.core import idempotency
from app.core.errors import RETRYABLE, ErrorClass, KbError
from app.ingestion import deliveries as deliveries_counter
from app.ingestion import frames, publish
from app.ingestion.embedding.binding import bind_embedder, load_candidates
from app.ingestion.embedding.embedder import check_window, resolve_identity
from app.ingestion.pipeline import StageProgress, VersionContext, run_pipeline
from app.providers.embedding_selection import resolve_embedding_connection
from app.providers.errors import ProviderCallFailed
from app.providers.registry import ADAPTERS

logger = logging.getLogger(__name__)

__all__ = [
    "Classified",
    "backoff",
    "classify",
    "fail_version",
    "release_claim",
    "root_span",
    "run_one_version",
]


class Classified:
    """One failure, reduced to the two facts the task body needs."""

    __slots__ = ("error_class", "retryable")

    def __init__(self, error_class: ErrorClass, *, retryable: bool) -> None:
        self.error_class = error_class
        self.retryable = retryable


def classify(exc: BaseException) -> Classified:
    """Map any exception onto the taxonomy, then read the retry decision off the table.

    **The class decides, never the exception type**, which is the whole reason ``autoretry_for``
    is unusable here: it dispatches on type, and one ``KbError`` type spans both retryable and
    never-retryable classes — so type dispatch cheerfully retries an unsupported file until the
    attempt cap, and again when an operator replays the failed job.

    An unrecognised exception is ``internal_dependency`` and **not** retryable. Unknown is
    permanent: a retryable default re-runs a crash that will crash identically, and it hides
    the fact that something raised a class nobody has mapped.
    """
    if isinstance(exc, ProviderCallFailed):
        # The provider layer already classified it, against the vendor's own code. Re-deriving
        # a class here from an HTTP status is how a billing failure becomes a brownout.
        return Classified(exc.error_class, retryable=_provider_retryable(exc.error_class))
    if isinstance(exc, KbError):
        return Classified(exc.error_class, retryable=exc.retryable)
    return Classified(ErrorClass.INTERNAL_DEPENDENCY, retryable=False)


def _provider_retryable(error_class: ErrorClass) -> bool:
    """Only two provider classes may be retried on this path.

    ``provider_rate_limit`` and ``provider_temporary`` self-heal. ``provider_auth``,
    ``provider_billing`` and ``provider_permanent_request`` do not: an exhausted account and a
    wrong model id never come right, and retrying them burns the delivery cap on a certainty.

    **Fallback to a second provider is not available here even for the two eligible classes.**
    A different vendor is a different vector space, so an embedding "fallback" writes
    incomparable vectors into the collection — the alias-drift failure committed on purpose,
    with no width mismatch for Qdrant to reject.
    """
    return error_class in {ErrorClass.PROVIDER_RATE_LIMIT, ErrorClass.PROVIDER_TEMPORARY}


def backoff(*, attempt: int, cap: int) -> int:
    """Full-jitter exponential backoff, capped.

    Full jitter rather than a fixed ladder because every worker that failed on the same
    provider brownout retries at the same moment otherwise, which is a thundering herd against
    a dependency that is already struggling.

    The cap is not cosmetic: a countdown at or above the visibility timeout re-executes
    forever — the ETA loop Celery's own documentation warns about.
    """
    ceiling = min(cap, 2 ** min(attempt, 16))
    return random.randint(0, max(1, ceiling))  # noqa: S311 - jitter, not a security decision


@contextlib.contextmanager
def root_span(name: str, *, traceparent: str | None, attributes: dict[str, Any]) -> Iterator[Any]:
    """A NEW ROOT span with a LINK to the submitter — never a child of it.

    A child span arriving forty minutes after its parent's HTTP request closed at 202 is
    dropped by the tail sampler, and the whole job becomes invisible: no trace, no stage
    timings, and nothing to explain a version that took nine minutes.

    ``CeleryInstrumentor`` still parents to the submitter by default, so the root has to be
    started explicitly; the ``traceparent`` travels in the job payload rather than in ambient
    context precisely so this choice is available.
    """
    from opentelemetry import trace
    from opentelemetry.context import Context
    from opentelemetry.trace import Link

    links = []
    if traceparent:
        parsed = _parse_traceparent(traceparent)
        if parsed is not None:
            links.append(Link(parsed))

    tracer = trace.get_tracer("app.ingestion")
    # `context=Context()` — an EMPTY context, which is what makes this a ROOT. Omitting it picks
    # up whatever CeleryInstrumentor left in ambient context, which is the submitter's span, and
    # the job then disappears from every trace the tail sampler keeps.
    with tracer.start_as_current_span(
        name, context=Context(), links=links, attributes=attributes
    ) as span:
        yield span


def _parse_traceparent(value: str) -> Any:
    """W3C ``traceparent`` to a ``SpanContext``, or None when it is not one.

    Returns None rather than raising on a malformed header. A job whose trace link cannot be
    built is still a job that must run — losing the link costs a graph edge, and refusing the
    work costs a document.
    """
    from opentelemetry.trace import SpanContext, TraceFlags

    parts = value.split("-")
    if len(parts) != 4 or len(parts[1]) != 32 or len(parts[2]) != 16:
        return None
    try:
        return SpanContext(
            trace_id=int(parts[1], 16),
            span_id=int(parts[2], 16),
            is_remote=True,
            trace_flags=TraceFlags(int(parts[3], 16)),
        )
    except ValueError:
        return None


async def run_one_version(
    *,
    clients: Any,
    org_id: str,
    job_id: str,
    source_version_id: str,
    ingest_key: str,
    deadline: Callable[[], float],
    max_deliveries: int,
    lock_ttl_seconds: int,
    claim_ttl_seconds: int,
) -> None:
    """The whole run, guards included. See the module docstring for why their order is fixed."""
    _require(clients.db_pool, "database pool")
    _require(clients.qdrant, "Qdrant client")
    _require(clients.s3, "object-storage client")
    _require(clients.cache, "cache")

    # THE COUNTER IS THE DATA PLANE'S OWN, IN VALKEY, AND USED TO BE AN `UPDATE source_versions`.
    # That statement wrote a Laravel-owned table this service is not permitted to write, past a
    # docstring twenty lines below it saying the service never does. `app/ingestion/deliveries.py`
    # carries the full account and why Valkey rather than a read-and-report. The number still rides
    # every frame, so `source_versions.delivery_count` shows an operator the same value with
    # exactly one writer.
    deliveries = await deliveries_counter.bump(
        clients.cache,
        org_id=org_id,
        scope=deliveries_counter.version_scope(source_version_id),
    )

    if deliveries > max_deliveries:
        # Past the cap the version fails as `internal_dependency`. The task raises `Ignore()`
        # afterwards; raising anything retryable would re-enter the path being left.
        await fail_version(
            clients=clients,
            org_id=org_id,
            job_id=job_id,
            source_version_id=source_version_id,
            error_class=ErrorClass.INTERNAL_DEPENDENCY,
            delivery_count=deliveries,
        )
        from celery.exceptions import Ignore

        raise Ignore

    claim_key = idempotency.idempotency_key(
        org_id=org_id, operation="ingestion.run", key=ingest_key
    )
    token = idempotency.new_claim_token()
    if not await idempotency.claim(clients.cache, claim_key, claim_ttl_seconds, token=token):
        # Somebody else holds it. Returning rather than raising is correct: this is the claim
        # doing its job, not a failure, and a raise here would be retried into a hot loop
        # against a claim that is deliberately long-lived.
        return

    async with (
        _item_lock(
            clients.cache, org_id=org_id, version_id=source_version_id, ttl=lock_ttl_seconds
        ),
        clients.db_pool.connection() as conn,
    ):
        ctx = await _read_version_context(conn, org_id=org_id, version_id=source_version_id)
        scope = frames.FrameScope(
            org_id=org_id,
            # FROM THE TASK PAYLOAD, NOT FROM THE ROW. `source_items.current_job_id` holds
            # whichever job is CURRENT, so reading it here would make a run superseded by a
            # reprocess report under its successor's id — and Laravel would apply the stale
            # run's frames as if they were the new one's. The payload carries the job this run
            # was dispatched for, which is exactly what `stale_job` is meant to detect.
            job_id=job_id,
            source_id=ctx.source_id,
            source_item_id=ctx.source_item_id,
        )
        sequencer = frames.Sequencer(
            await frames.read_sequence_base(conn, org_id=org_id, source_item_id=ctx.source_item_id)
        )

        # THE CREDENTIAL IS OPENED HERE AND LIVES IN A CLOSURE. `bind_embedder` returns the
        # narrowed `EmbedCallable`, which has no field for a key or a connection — so no stage
        # below can put one into a Celery payload, a span attribute or a log line.
        space = ctx_space(ctx)
        connections, designation = await load_candidates(conn, org_id=org_id)
        context_window = _context_window(connections, space)
        # BEFORE any customer text moves, and once per run rather than per batch: a per-batch
        # check is a per-batch chance to handle a too-small window by trimming.
        check_window(context_window, model=space.model)
        sealed_credential, sealed_data_key = await _sealed_credential(
            conn, org_id=org_id, connections=connections, designation=designation
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

        identity = resolve_identity(
            embed=embed,
            space=space,
            context_window=context_window,
            org_id=org_id,
            trace_id=source_version_id,
        )

        result = await run_pipeline(
            ctx=ctx,
            conn=conn,
            qdrant=clients.qdrant,
            s3=clients.s3,
            bucket=clients.settings.s3_bucket,
            identity=identity,
            embed=embed,
            measure=_measure,
            emit=_progress_emitter(scope=scope, sequencer=sequencer),
            deadline=deadline,
        )

    publish.report_readiness(
        publish.ReadinessReport(
            scope=scope,
            source_version_id=source_version_id,
            sequence=sequencer.take(),
            identity=_identity_of(ctx),
            verified=True,
            chunk_total=result.chunk_count,
            content_checksum=result.content_checksum,
            warning_summary=result.warnings,
            delivery_count=deliveries,
            error_class=None,
        )
    )


async def fail_version(
    *,
    clients: Any,
    org_id: str,
    job_id: str,
    source_version_id: str,
    error_class: ErrorClass,
    delivery_count: int | None = None,
) -> None:
    """Report the version as failed. **Reviewable, with the prior version still serving.**

    This is a report and not a write to the lifecycle table: the control plane owns the state
    machine, and a data-plane write to it would be a second author of the column that decides
    what a tenant's next query sees.

    IT RE-READS THE ROW RATHER THAN TAKING THE SCOPE AS AN ARGUMENT, and that is not laziness:
    this is called from the task's exception handler, where the failure may have happened
    *before* the context was ever assembled — a lock that could not be taken, a credential that
    would not open. Requiring the caller to pass identifiers it may not have would push the same
    read into the handler, where a second exception has nowhere to go.

    A version whose row cannot be read is the one case that reports nothing. That is the honest
    outcome and not a swallowed error: there is no ``source_item_id`` to address a frame to, so
    there is no frame to send, and raising from a failure handler would replace a recorded
    failure with an unrecorded one.
    """
    try:
        async with clients.db_pool.connection() as conn:
            ctx = await _read_version_context(conn, org_id=org_id, version_id=source_version_id)
            base = await frames.read_sequence_base(
                conn, org_id=org_id, source_item_id=ctx.source_item_id
            )
    except Exception:
        logger.exception(
            "cannot report failure for version %s: its row is unreadable, so the run has failed "
            "with no frame naming an item. The version stays in whatever state it was last "
            "reported in and the prior version keeps serving",
            source_version_id,
        )
        return

    publish.report_readiness(
        publish.ReadinessReport(
            scope=frames.FrameScope(
                org_id=org_id,
                job_id=job_id,
                source_id=ctx.source_id,
                source_item_id=ctx.source_item_id,
            ),
            source_version_id=source_version_id,
            sequence=frames.Sequencer(base).take(),
            identity=_identity_of(ctx),
            verified=False,
            chunk_total=0,
            content_checksum="",
            warning_summary={},
            delivery_count=delivery_count,
            error_class=error_class.value,
        )
    )


def _identity_of(ctx: VersionContext) -> dict[str, str]:
    """The six identity components, taken from the version's OWN row.

    Never recomposed from this process's configuration. The row is what the ingest key was
    built from; a worker that recomputed ``chunker_cfg_version`` here after a deploy would
    report an identity that does not match the key it claimed, and Laravel's find-or-create
    would mint a SECOND version row for one run — re-embedding the corpus at a provider's
    per-token price while the first row sits at whatever state it was last reported in.
    """
    return frames.identity_payload(
        content_hash=ctx.content_hash,
        ingest_key=ctx.ingest_key,
        parser_cfg_version=ctx.parser_cfg_version,
        ocr_cfg_version=ctx.ocr_cfg_version,
        chunker_cfg_version=ctx.chunker_cfg_version,
        embedding_model_version=ctx.embedding_model_version,
    )


async def release_claim(*, clients: Any, org_id: str, ingest_key: str) -> None:
    """Give the idempotency claim back so a retry can reclaim it.

    Called **before** the retry is scheduled and never after. A claim held across the countdown
    makes every retry a no-op that returns immediately and looks exactly like a successful
    replay — the version never progresses and nothing reports a failure.
    """
    key = idempotency.idempotency_key(org_id=org_id, operation="ingestion.run", key=ingest_key)
    # The token is not held across the retry boundary, so this releases by key. `release`
    # compares tokens where it has one; the task body is the only caller and it has none.
    await idempotency.release(clients.cache, key, token="")


async def ocr_pages(
    *,
    clients: Any,
    org_id: str,
    source_version_id: str,
    page_numbers: tuple[int, ...],
    ocr_cfg_version: str,
) -> None:
    """OCR one bounded page batch and commit its text.

    <!-- UNVERIFIED: the fan-out path is not exercised end to end. `run_pipeline` currently
    lets Docling run OCR inline through its own pipeline options, which covers every document
    that fits one parse budget — the fan-out exists for scans above `OCR_PAGES_PER_TASK` pages
    and nothing dispatches it yet. This body is the seam, and it raises rather than returning
    quietly so a caller that starts dispatching learns immediately. -->
    """
    raise KbError(
        ErrorClass.INTERNAL_DEPENDENCY,
        f"the OCR fan-out is not wired: {len(page_numbers)} pages for version "
        f"{source_version_id} under {ocr_cfg_version} were dispatched to a task with no "
        "producer. Inline OCR inside the parse covers every document within one parse budget; "
        "a document that needs the fan-out currently fails here rather than publishing with "
        "those pages silently missing",
        retryable=False,
    )


# ── internals ─────────────────────────────────────────────────────────────────


def _require(client: Any, name: str) -> None:
    """Refuse the run when a client this process was never provisioned with is needed.

    `RuntimeClients` holds `None` for anything whose secret is not mounted, and the warning it
    logs at startup is easy to miss. Failing here names the missing dependency; failing later
    is an `AttributeError` on `None` inside a stage, which classifies as
    `internal_dependency` and says nothing.
    """
    if client is None:
        raise KbError(
            ErrorClass.INTERNAL_DEPENDENCY,
            f"this worker has no {name}: it was not provisioned at startup, so the run cannot "
            "proceed. Check that the corresponding secret is mounted in this container",
            retryable=False,
        )


@contextlib.asynccontextmanager
async def _item_lock(cache: Any, *, org_id: str, version_id: str, ttl: int) -> Any:
    """The per-item Valkey lock, held for the whole run and released on every exit path.

    TTL above the hard time limit so a killed worker's lock self-heals rather than wedging the
    item, and below the visibility timeout so a genuine redelivery can eventually proceed.
    """
    key = f"lock:{org_id}:ingest:{version_id}"
    token = idempotency.new_claim_token()
    if not await idempotency.claim(cache, key, ttl, token=token):
        raise KbError(
            ErrorClass.INTERNAL_DEPENDENCY,
            f"another run holds the lock on version {version_id}. Two workers on one version "
            "race the pointer, and the redelivery that produces them is invisible to both",
            retryable=True,
        )
    try:
        yield
    finally:
        await idempotency.release(cache, key, token=token)


async def _read_version_context(conn: Any, *, org_id: str, version_id: str) -> VersionContext:
    """Assemble the run's facts from the control plane's own rows.

    A READ of Laravel-owned tables, which the write allow-list does not govern and must not be
    read as permitting: ``ALLOWED_TABLES`` is a *write* list, and the data plane reads the
    source of truth all the time — that is what makes Qdrant rebuildable. What it never does is
    write one of these tables, which is why this function has no companion that does.

    Every value the run needs is taken from here rather than from the job payload. The payload
    carries three ids and nothing else on purpose: a payload is serialized to the broker and
    read by anything that instruments task arguments, and a row is the authority anyway.
    """
    async with conn.cursor() as cur:
        await cur.execute(
            # `i.source_id` and NOT `i.knowledge_source_id`. The column on `source_items` is
            # `source_id` (`2026_08_20_001900_create_source_items_table.php`); the longer spelling
            # is what the table is NAMED after, not what the column is called, and PostgreSQL
            # answers a wrong column name with an error only once this query actually runs — which
            # is inside a Celery task, on the ingestion path, after the parse has already been paid
            # for.
            "SELECT v.id, v.source_item_id, i.source_id, v.content_hash, "
            "       v.ingest_key, v.parser_cfg_version, v.ocr_cfg_version, "
            "       v.chunker_cfg_version, v.embedding_model_version, i.url, "
            "       s.effective_at, s.expires_at, i.storage_key "
            "  FROM source_versions v "
            "  JOIN source_items i "
            "    ON i.id = v.source_item_id AND i.organization_id = v.organization_id "
            "  JOIN knowledge_sources s "
            "    ON s.id = i.source_id AND s.organization_id = v.organization_id "
            " WHERE v.organization_id = %s AND v.id = %s",
            (org_id, version_id),
        )
        row = await cur.fetchone()

    if row is None:
        raise KbError(
            ErrorClass.VALIDATION,
            f"version {version_id} has no row in organization {org_id}",
            retryable=False,
        )

    bot_ids = await _assigned_bot_ids(conn, org_id=org_id, source_id=row[2])

    return VersionContext(
        org_id=org_id,
        source_id=row[2],
        source_item_id=row[1],
        source_version_id=row[0],
        content_hash=row[3],
        ingest_key=row[4],
        parser_cfg_version=row[5],
        ocr_cfg_version=row[6],
        chunker_cfg_version=row[7],
        embedding_model_version=row[8],
        bot_ids=bot_ids,
        url=row[9],
        effective_at=_as_datetime(row[10]),
        expires_at=_as_datetime(row[11]),
        # THE KEY IS CARRIED TO BE COMPARED, NEVER TO BE USED. `app/db/objects.py` composes both
        # keys this layout permits for the version's content hash and checks that this is one of
        # them; a key is a path-traversal parameter and is never handed to the store. It is here
        # because there are TWO legal spellings — `{sha256}` for an upload and `{sha256}.txt` for
        # a pasted body — and nothing else on the row distinguishes them.
        storage_key=row[12],
    )


async def _assigned_bot_ids(conn: Any, *, org_id: str, source_id: str) -> tuple[str, ...]:
    """The bots this source is assigned to, ENABLED ones only.

    A disabled grant grants nothing — that is the whole of what the per-assignment off switch
    means — so writing a disabled bot's id into ``bot_ids`` would make the payload filter match
    for a bot whose operator has switched it off, at query time, with nothing to see.

    An empty tuple is legal and common: a source is organization-owned (ADR-067), so it exists
    before any bot is assigned to it, and bot access is a query-time filter rather than an
    index-time scope.
    """
    async with conn.cursor() as cur:
        await cur.execute(
            "SELECT bot_id FROM bot_source_assignments "
            " WHERE organization_id = %s AND source_id = %s AND enabled = true "
            " ORDER BY bot_id",
            (org_id, source_id),
        )
        rows = await cur.fetchall()
    return tuple(str(row[0]) for row in rows)


async def _sealed_credential(
    conn: Any, *, org_id: str, connections: list[Any], designation: Any
) -> tuple[bytes, bytes]:
    """The two ciphertexts for the connection selection will choose.

    Selection runs TWICE — once here to know whose key to read, once inside `bind_embedder` —
    and that is deliberate rather than wasteful: it is a pure function of its arguments, so two
    calls cannot disagree, and the alternative is this function choosing a connection and
    `bind_embedder` trusting it, which is a second implementation of ADR-031's refusal.
    """
    connection = resolve_embedding_connection(connections, designated=designation)
    async with conn.cursor() as cur:
        await cur.execute(
            "SELECT credential_ciphertext, data_key_ciphertext FROM provider_connections "
            " WHERE organization_id = %s AND id = %s",
            (org_id, connection.connection_id),
        )
        row = await cur.fetchone()
    if row is None or row[0] is None or row[1] is None:
        raise KbError(
            ErrorClass.VALIDATION,
            f"connection {connection.connection_id} holds no sealed credential. Selection chose "
            "it from this organization's own rows, so the row exists and its key does not — "
            "which is a control-plane state, never something to work around by choosing another",
            retryable=False,
        )
    return bytes(row[0]), bytes(row[1])


def _context_window(connections: list[Any], space: Any) -> int:
    """The window of the model this version was embedded under, off its own row.

    Matched on `(provider, model)` rather than taken from the first row, because an
    organization holding two connections has two windows and the one that matters is the one
    belonging to the space this VERSION carries — which may not be the one selection would
    choose today.
    """
    for connection in connections:
        if connection.provider == space.provider and connection.model == space.model:
            return int(connection.caps.context_window)
    raise KbError(
        ErrorClass.VALIDATION,
        f"no active connection in this organization serves {space.provider}/{space.model}, "
        "which is the space this version was embedded under. Re-embedding it under a different "
        "space would put two vector spaces in one collection, so this fails rather than falls "
        "back; the fix is to restore the connection or reprocess the source",
        retryable=False,
    )


def _as_datetime(value: Any) -> datetime | None:
    return value if isinstance(value, datetime) else None


def _measure(text: str) -> int:
    """The token estimator handed to the chunker and the batcher.

    **It must OVER-estimate**, which is why it is not a word count. There is no local model and
    therefore no authoritative tokenizer, and the vendor's own tokenizer is a different
    algorithm over a different vocabulary that diverges most on non-Latin scripts — exactly
    where a silent trim would be least noticed. Four characters per token is the conventional
    English ratio; dividing by three buys the margin, and `TOKEN_HEADROOM` buys more.
    """
    return max(1, (len(text) + 2) // 3)


def _progress_emitter(
    *, scope: frames.FrameScope, sequencer: frames.Sequencer
) -> Callable[[StageProgress], None]:
    """Bind one run's scope and sequence counter to the process-wide callback transport.

    The transport itself is installed by the worker bootstrap (`publish.set_progress_emitter`)
    so no signing key travels through the stage functions.

    ONE `Sequencer` FOR THE WHOLE RUN, shared with the readiness frame. `StageProgress` carries
    a ``sequence`` field of its own and it is deliberately ignored here: the pipeline numbers
    its stages from its own perspective, which restarts at 1 on every resume, and a frame
    numbered below the item's applied count is discarded with a 200.
    """

    def emit(progress: StageProgress) -> None:
        publish.report_progress(scope=scope, sequence=sequencer.take(), progress=progress)

    return emit


def ctx_space(ctx: VersionContext) -> Any:
    """The embedding space this version was created under, parsed from its recorded identity.

    ``embedding_model_version`` is ``emb/v1:provider:model:dNNNN:digest`` — provider, model id,
    returned width and a probe digest — and it is the version's OWN row rather than this
    process's configuration. That is the point: the reader must embed its query with the same
    provider, model and width the passages were embedded with, and a service-level setting lets
    the indexer and the reader disagree with nothing raised.

    The parse itself is `app.retrieval.collection.space_from_identity` and is deliberately not
    repeated here: the payload maintenance ops parse the same string to decide which collection
    to REWRITE, and a second, slightly more tolerant copy would send a status rewrite to a
    collection the indexer never wrote — which reports zero points updated and passes its own
    verification. This function's remaining job is the error class: the schema module raises
    ``ValueError`` because it imports no taxonomy, and the pipeline needs a non-retryable
    ``validation`` failure, because the next attempt reads the identical row.
    """
    from app.retrieval.collection import space_from_identity

    try:
        return space_from_identity(ctx.embedding_model_version)
    except ValueError as exc:
        raise KbError(ErrorClass.VALIDATION, str(exc), retryable=False) from exc


# Read the retry table once at import so a class added without a row fails loudly here rather
# than as a KeyError inside a failing task.
assert set(RETRYABLE) >= {ErrorClass.PROVIDER_RATE_LIMIT, ErrorClass.PROVIDER_TEMPORARY}
