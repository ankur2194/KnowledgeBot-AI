"""Celery process lifecycle: telemetry, runtime clients, and two startup assertions.

``app/main.py``'s ``lifespan`` is the API's version of this file. Everything either of them
builds comes from ``app/core/runtime.py``, so the two process shapes cannot drift into holding
differently-configured clients.

THREE SIGNALS, AND EACH ONE COVERS A CASE THE OTHERS DO NOT
------------------------------------------------------------
=========================  =====================================================================
``worker_process_init``    The only point that runs **after** ``fork()`` in a prefork child, and
                           therefore the only correct place to build anything with a thread or a
                           socket in it. ``BatchSpanProcessor`` runs a background thread and a
                           thread does not survive ``fork()``; a psycopg pool opened before the
                           fork hands two processes the same sockets, which reads as random
                           query corruption rather than as an error.
``worker_process_shutdown``Flushes telemetry. **Required**, not optional: a billiard child exits
                           through ``os._exit``, which runs no ``atexit`` hook, so the SDK's own
                           registered shutdown never fires and whatever the span processor was
                           holding is discarded.
``beat_init``              ``ai-beat`` FORKS NOTHING, so ``worker_process_init`` never fires
                           there and beat would run for the life of the deployment with a proxy
                           tracer. The identical reasoning is already written out for logging in
                           ``app/worker/__init__.py``; this is the same gap in the same shape.
=========================  =====================================================================

All three connect with ``weak=False``, matching ``install_celery_logging``: the receivers are
module-level functions, but Celery's signals keep weak references by default and a garbage-
collected receiver is a silent regression to the unconfigured behaviour.

THE CHILD-OWNED EVENT LOOP, AND WHAT IT IS DELIBERATELY NOT
------------------------------------------------------------
Celery tasks are synchronous and every client in ``app/core/runtime.py`` is asynchronous, so a
prefork child needs one long-lived loop to build them on and to close them on. It is created
here, per child, and :func:`run_in_worker` is the only supported way to use it.

It is **not** the async-to-sync binder the provider adapters need, and it must not be grown into
one here. That binder has to carry per-call deadlines, cancellation and the retry policy from
``kb-error-taxonomy``; this loop carries none of those and exists so that a shared client opened
in ``worker_process_init`` can be closed in ``worker_process_shutdown`` on the same loop it was
opened on. Opening a pool on one loop and awaiting it on another is the "attached to a different
loop" failure, and it appears only under load.
"""

from __future__ import annotations

import asyncio
import logging
import warnings
from typing import TYPE_CHECKING, Any, Final

from celery import bootsteps, signals

from app.core.config import get_settings
from app.core.runtime import close_runtime_clients, open_runtime_clients
from app.observability import otel

if TYPE_CHECKING:  # pragma: no cover - typing only
    from collections.abc import Coroutine

    from app.core.runtime import RuntimeClients

__all__ = [
    "OcrEngineAssertionStep",
    "install_worker_process_hooks",
    "run_in_worker",
    "worker_clients",
]

logger = logging.getLogger(__name__)

#: Per prefork child. Module globals rather than an attribute on ``celery_app`` because the
#: whole point is that these are NOT shared: each child builds its own after ``fork()``, and a
#: value hanging off the app object would be inherited from the parent and look built.
_loop: asyncio.AbstractEventLoop | None = None
_clients: RuntimeClients | None = None

#: This child's outbound callback transport, installed beside the clients and closed with them.
#: Held here rather than on `RuntimeClients` because it is the WORKER's alone: the API process
#: opens the same pools and never signs a callback, and putting an `httpx.Client` on the shared
#: record would give it one it must not use.
_callback_emitter: Any = None

#: The queue whose worker parses documents, and therefore the only one where the OCR engine
#: assertion applies. Named here rather than inferred: ``app/worker/config.py`` routes
#: ``kb.ingest.*`` to it.
_OCR_QUEUE: Final[str] = "ingest"


def worker_clients() -> RuntimeClients:
    """The clients this child built, or a loud failure.

    Raises rather than returning ``None`` so a task that reaches for a client in a process that
    never built one gets a message naming the cause, instead of ``AttributeError: 'NoneType'``
    forty frames deeper.
    """
    if _clients is None:
        msg = (
            "no runtime clients in this process: worker_process_init did not run, or ran and "
            "found no credentials mounted. Tasks must not construct their own clients "
            "(app/core/runtime.py)."
        )
        raise RuntimeError(msg)
    return _clients


def run_in_worker[T](coro: Coroutine[Any, Any, T]) -> T:
    """Run one coroutine on this child's loop. See the module docstring for the boundary."""
    if _loop is None:
        msg = "no event loop in this process: worker_process_init did not run"
        raise RuntimeError(msg)
    return _loop.run_until_complete(coro)


def _promote_decompression_bomb_warning() -> None:
    """Turn Pillow's ``DecompressionBombWarning`` into an exception for this process.

    Verified against ``app/ingestion/ocr/guarded.py`` before adding it, because promoting a
    warning to an error is only safe if somebody catches it. ``open_guarded`` does:

        except (Image.DecompressionBombError, Image.DecompressionBombWarning) as exc:
            raise OcrRejected(...)

    and its docstring names this promotion as the thing that makes the second half of that
    ``except`` reachable. Pillow raises ``DecompressionBombError`` only above its hard band and
    merely *warns* in the band between ``MAX_IMAGE_PIXELS`` and twice that — so without this
    filter a tenant image in the warning band is decoded, the warning goes to stderr, and the
    allocation the cap exists to prevent has already happened. With it, that image becomes an
    ``OcrRejected`` (``parsing``, permanent) like every other undecodable upload.

    Our own :data:`~app.ingestion.ocr.guarded.MAX_PIXELS` sits deliberately *below* Pillow's warn
    band and is checked from the header before anything is decoded, so this is the second latch
    rather than the first.

    ``simplefilter`` with a category, not ``filterwarnings("error")``: promoting every warning in
    a worker turns an upstream ``DeprecationWarning`` into a failed tenant job.

    Pillow is imported lazily, and a failure to import it is not fatal here for the same reason
    ``guarded.py`` defers its own import: the constants and cell-side statistics stay usable in
    an image whose imaging stack is broken, and the startup assertion below is what reports that
    breakage.
    """
    try:
        from PIL import Image
    except ImportError:
        logger.warning("Pillow is not importable; the decompression-bomb warning stays a warning")
        return
    warnings.simplefilter("error", Image.DecompressionBombWarning)


def _assert_ocr_engine(engine: str) -> None:
    """Fail the container if the pinned OCR options class is not what Docling would resolve.

    ``app/ingestion/ocr/guarded.py`` states this as a requirement in its own module docstring —
    *"The resolved engine class is asserted at worker startup, and a mismatch fails the
    container"* — and gives the two failures it prevents: a developer laptop and the Linux worker
    emitting different text for the same scan while the OCR config version string is identical,
    so nothing ever reprocesses; and an image missing every backend publishing every scanned
    source as ready with zero chunks. Docling's ``auto`` option picks an engine by import
    probing, logs at WARNING when it finds none, and then passes pages through untouched.

    WHAT THIS ASSERTS, EXACTLY: the dotted path in ``OCR_ENGINE_CLASSES`` still imports; the
    class it names is registered in Docling's OWN OCR factory (so an upstream rename, a moved
    module or a re-registration fails here rather than at the first scanned page); and the
    resolved class is not one of ``BANNED_OCR_OPTION_CLASSES``.

    ``allow_external_plugins=False`` is passed for the same reason the class is pinned at all: an
    external plugin registering itself under a known kind is a way for the engine to change
    without any configuration changing.

    WHAT IT CANNOT ASSERT YET, AND THIS IS A REAL GAP RATHER THAN AN OMISSION. The other half of
    that requirement is a check on the CONSTRUCTED converter —
    ``converter.format_to_options[InputFormat.PDF].pipeline_options.ocr_options``, alongside the
    layout-revision and ``REMOTE_ACCESS_FLAGS`` assertions ``app/ingestion/parsing/converter.py``
    lists in its docstring. ``build_converter`` raises ``NotImplementedError`` today, so there is
    no constructed object to read, and asserting against the source constants instead would prove
    only that this file agrees with itself. It belongs with ``build_converter``, in one change,
    and is flagged for ``ingestion-engineer`` rather than approximated here.
    """
    import importlib

    from docling.models.factories import get_ocr_factory

    from app.ingestion.ocr.guarded import BANNED_OCR_OPTION_CLASSES, OCR_ENGINE_CLASSES

    dotted = OCR_ENGINE_CLASSES.get(engine)
    if dotted is None:
        msg = (
            f"OCR engine {engine!r} is not pinned: OCR_ENGINE_CLASSES holds "
            f"{sorted(OCR_ENGINE_CLASSES)}"
        )
        raise RuntimeError(msg)

    module_name, _, class_name = dotted.rpartition(".")
    try:
        resolved = getattr(importlib.import_module(module_name), class_name)
    except (ImportError, AttributeError) as exc:
        msg = (
            f"the pinned OCR options class {dotted} does not resolve ({exc}). Docling has moved "
            f"or renamed it; pinning by class is what stops the engine changing under us, so "
            f"this fails the container rather than falling back to import probing."
        )
        raise RuntimeError(msg) from exc

    if class_name in BANNED_OCR_OPTION_CLASSES:
        msg = (
            f"{class_name} is in BANNED_OCR_OPTION_CLASSES: it resolves the engine per host by "
            f"import probing, or calls out of a container that has no network."
        )
        raise RuntimeError(msg)

    registered = get_ocr_factory(allow_external_plugins=False).registered_meta
    if resolved not in registered:
        msg = (
            f"{dotted} is not registered in Docling's OCR factory. Registered: "
            f"{sorted(cls.__name__ for cls in registered)}. An unregistered options class is "
            f"never selected, so the pipeline would silently fall back to its own default."
        )
        raise RuntimeError(msg)

    logger.info("OCR engine assertion passed", extra={"engine": engine, "count": len(registered)})


# `type: ignore[misc]` for the reason recorded at `app/worker/heartbeat.py`'s step: `celery.*`
# is under `ignore_missing_imports`, so the base class is `Any` and `--strict` refuses it.
class OcrEngineAssertionStep(bootsteps.Step):  # type: ignore[misc]
    """Run :func:`_assert_ocr_engine` once, in the worker main process, on the ``ingest`` worker.

    A bootstep rather than a ``worker_process_init`` receiver, and the reason is cost. Resolving
    the class imports ``docling``, which imports ``torch``; doing that in every prefork child of
    every worker would add a multi-second import to eight crawl children that will never see a
    page raster — the exact cost ``app/ingestion/parsing/converter.py`` defers its own Docling
    import to avoid. A worker bootstep runs once, in the parent, where the queue list is
    knowable and where the import is inherited by the children through copy-on-write.

    Raising here fails worker startup, which is what ``guarded.py`` asks for: failing the
    *container* rather than the document, because failing the document hides a broken image
    behind a stream of per-document warnings.
    """

    def __init__(self, worker: Any, **kwargs: Any) -> None:
        super().__init__(worker, **kwargs)
        from app.ingestion.ocr.guarded import DEFAULT_ENGINE

        if _OCR_QUEUE not in set(worker.app.amqp.queues):
            logger.debug("not the %s worker; the OCR engine assertion does not apply", _OCR_QUEUE)
            return
        _assert_ocr_engine(DEFAULT_ENGINE)


def _on_process_init(**kwargs: Any) -> None:
    """Build everything this prefork child owns. See the module docstring for the ordering."""
    global _loop, _clients, _callback_emitter

    # Telemetry first, so that anything the client construction below logs already carries a
    # trace id, and so a failure while opening a pool is itself a span.
    otel.configure(in_worker=True)

    _promote_decompression_bomb_warning()

    _loop = asyncio.new_event_loop()
    # Set as THIS child's loop as well as held here: a library that reaches for
    # `asyncio.get_event_loop()` must find the same loop the clients were opened on, or it
    # creates a second one and the pool's connections belong to neither.
    asyncio.set_event_loop(_loop)
    settings = get_settings()
    _clients = _loop.run_until_complete(open_runtime_clients(settings))

    # THE CALLBACK TRANSPORT, AND IT IS NOT OPTIONAL FOR AN INGEST WORKER. Without it every
    # run reaches the end of the pipeline and raises at the last statement: `report_readiness`
    # refuses to no-op, because a verified point set that was never reported is invisible to
    # retrieval AND to deletion, forever. Installed here rather than lazily at first use so a
    # misconfigured ring fails the child at startup, next to the key-ring error that explains
    # it, rather than fifteen minutes into a document.
    from app.ingestion.callback import install_emitters

    _callback_emitter = install_emitters(settings=settings, key_ring=_clients.key_ring)


def _on_process_shutdown(**kwargs: Any) -> None:
    """Close what the child opened, then flush telemetry.

    Telemetry LAST, because closing the clients emits the log lines and spans this flush is
    meant to carry. ``otel.shutdown()`` is required here and optional in the API — see its
    docstring for the ``os._exit`` reason.
    """
    global _loop, _clients, _callback_emitter

    if _callback_emitter is not None:
        try:
            _callback_emitter.close()
        except Exception:
            logger.warning("the callback emitter did not close cleanly", exc_info=True)
        _callback_emitter = None

    if _clients is not None and _loop is not None:
        try:
            _loop.run_until_complete(close_runtime_clients(_clients))
        except Exception:
            logger.warning("runtime clients did not close cleanly", exc_info=True)
    _clients = None
    if _loop is not None:
        _loop.close()
        _loop = None

    otel.shutdown()


def _on_beat_init(**kwargs: Any) -> None:
    """``ai-beat`` gets telemetry and nothing else.

    It forks nothing, so ``worker_process_init`` never fires here — and it holds no clients on
    purpose: beat publishes messages to the broker and touches no other dependency. Building a
    pool it would never use would give ``ai-beat`` a reason to fail on a database it does not
    need.
    """
    otel.configure(in_worker=True)


def install_worker_process_hooks() -> None:
    """Connect the three signals. Called at import of ``app.worker``.

    Idempotent through Celery's own signal dispatch: connecting the same function object twice
    replaces the existing receiver rather than adding a second one.
    """
    signals.worker_process_init.connect(_on_process_init, weak=False)
    signals.worker_process_shutdown.connect(_on_process_shutdown, weak=False)
    signals.beat_init.connect(_on_beat_init, weak=False)
