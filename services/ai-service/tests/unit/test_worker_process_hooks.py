"""The Celery process lifecycle: three signals, two bootsteps, one warning filter.

Every assertion here is about something that fails SILENTLY when it is absent — a beat process
with no tracer, a prefork child whose spans are discarded at ``os._exit``, a decompression bomb
that decodes instead of being refused. None of them produces an error message anywhere, which is
why each one gets a test rather than a comment.
"""

from __future__ import annotations

import warnings
from types import SimpleNamespace
from typing import Any

import pytest
from celery import signals

from app.worker import process

#: A STAND-IN WITH A `key_ring`, not the bare string it used to be. `_on_process_init` reads
#: that attribute to build the callback transport, so a stub without one fails on the attribute
#: rather than on anything this file is about.
STUB_CLIENTS = SimpleNamespace(key_ring="key-ring")

# ─────────────────────────────────────────────────────────────────────────────
# The three signals
# ─────────────────────────────────────────────────────────────────────────────


def _receivers(signal: Any) -> set[Any]:
    """The functions connected to a Celery signal.

    ``receiver`` is the function itself when it was connected with ``weak=False`` and a
    ``weakref`` otherwise, so the two cases are told apart by TYPE. Testing ``callable(receiver)``
    instead would be true for both — and would *call* the strongly-held receiver, which for
    ``worker_process_init`` means actually building this process's runtime clients.
    """
    import weakref

    return {
        receiver() if isinstance(receiver, weakref.ReferenceType) else receiver
        for _, receiver in signal.receivers
    }


def test_importing_app_worker_connects_all_three_process_signals() -> None:
    """Connected at IMPORT, before Celery reaches its own setup — the same rule
    ``install_celery_logging`` follows, and for the same reason: connecting later is connecting
    after the thing you meant to intercept."""
    import app.worker  # noqa: F401  — the import IS the subject

    for signal in (
        signals.worker_process_init,
        signals.worker_process_shutdown,
        signals.beat_init,
    ):
        assert signal.receivers, f"{signal.name} has no receiver"


def test_beat_gets_its_own_signal_because_it_forks_nothing() -> None:
    """``ai-beat`` never fires ``worker_process_init``, so without ``beat_init`` it runs for the
    life of the deployment with a proxy tracer and exports nothing.

    Asserted as a DISTINCT receiver, not merely as "beat_init has receivers": something else
    connecting to that signal would satisfy a weaker check.
    """
    import app.worker  # noqa: F401

    assert process._on_beat_init in _receivers(signals.beat_init)
    assert process._on_process_init in _receivers(signals.worker_process_init)
    assert process._on_process_shutdown in _receivers(signals.worker_process_shutdown)


def test_the_receivers_are_held_strongly() -> None:
    """Celery's signals keep WEAK references by default, and a collected receiver is a silent
    regression to the unconfigured behaviour — the trap ``install_celery_logging`` documents at
    its own ``weak=False``.

    ASSERTED ON HOW THE RECEIVER IS STORED, NOT BY COLLECTING IT, and the first version of this
    test got that wrong. ``gc.collect()`` followed by "is it still connected?" passes either way:
    these receivers are module-level functions, so the module's own namespace keeps them alive
    and the weakref never dies in-process. Measured — flipping ``weak=False`` to the default left
    that test green. Celery stores the function itself for ``weak=False`` and a
    ``weakref.ReferenceType`` otherwise, and that difference is the mechanism.
    """
    import weakref

    import app.worker  # noqa: F401

    for signal in (
        signals.worker_process_init,
        signals.worker_process_shutdown,
        signals.beat_init,
    ):
        for _, receiver in signal.receivers:
            assert not isinstance(receiver, weakref.ReferenceType), (
                f"{signal.name} holds a weak reference to its receiver; a garbage collection at "
                f"the wrong moment silently disconnects it"
            )


def test_beat_builds_no_runtime_clients(monkeypatch: pytest.MonkeyPatch) -> None:
    """Beat publishes to the broker and touches nothing else.

    Giving it a pool would give ``ai-beat`` a reason to fail on a database it does not need, and
    beat is what schedules the sweeps that resolve tenant-visible states.
    """
    configured: list[bool] = []
    monkeypatch.setattr(
        process.otel, "configure", lambda **kwargs: configured.append(kwargs.get("in_worker"))
    )
    monkeypatch.setattr(
        process,
        "open_runtime_clients",
        _must_not_be_called("beat opened runtime clients"),
    )

    process._on_beat_init()

    assert configured == [True]


def _must_not_be_called(message: str) -> Any:
    def _fail(*args: Any, **kwargs: Any) -> Any:
        raise AssertionError(message)

    return _fail


@pytest.fixture
def child(monkeypatch: pytest.MonkeyPatch) -> list[str]:
    """Run ``_on_process_init`` / ``_on_process_shutdown`` without a real loop or real clients.

    ``asyncio`` is stubbed rather than left alone because the real handler calls
    ``asyncio.set_event_loop`` — which in a prefork child is correct and in the pytest process
    would replace the session loop underneath every other test in the run.
    """
    order: list[str] = []

    class _Loop:
        def run_until_complete(self, coro: Any) -> Any:
            coro.close()
            order.append("ran-on-the-child-loop")
            return STUB_CLIENTS

        def close(self) -> None:
            order.append("loop-closed")

    monkeypatch.setattr(
        process,
        "asyncio",
        SimpleNamespace(new_event_loop=_Loop, set_event_loop=lambda loop: None),
    )
    monkeypatch.setattr(
        process.otel, "configure", lambda **kwargs: order.append(f"configure:{kwargs}")
    )
    monkeypatch.setattr(process.otel, "shutdown", lambda: order.append("otel-shutdown"))
    monkeypatch.setattr(
        process, "_promote_decompression_bomb_warning", lambda: order.append("bomb-promoted")
    )
    monkeypatch.setattr(process, "get_settings", lambda: "settings")

    async def _open(settings: Any) -> Any:
        return STUB_CLIENTS

    async def _close(clients: Any) -> None:
        order.append("clients-closed")

    monkeypatch.setattr(process, "open_runtime_clients", _open)
    monkeypatch.setattr(process, "close_runtime_clients", _close)

    # THE CALLBACK TRANSPORT IS PART OF THE CHECKLIST NOW. It is patched on its own module
    # because `_on_process_init` imports it locally — deferring the import keeps `app.ingestion`
    # out of every process that reads worker configuration — so patching `process` would miss it
    # and the hook would build a real `httpx.Client` against a fake key ring.
    from app.ingestion import callback as callback_module

    class _Emitter:
        def close(self) -> None:
            order.append("emitter-closed")

    def _install(*, settings: Any, key_ring: Any) -> Any:
        order.append("emitters-installed")
        return _Emitter()

    monkeypatch.setattr(callback_module, "install_emitters", _install)
    monkeypatch.setattr(process, "_loop", None)
    monkeypatch.setattr(process, "_clients", None)
    monkeypatch.setattr(process, "_callback_emitter", None)
    return order


def test_process_init_does_all_five_things_and_in_this_order(child: list[str]) -> None:
    """A function that exists and is called by nothing is the shape of finding #54.

    Telemetry FIRST, so anything the rest logs already carries a trace id and a failure while
    opening a pool is itself a span. Everything else after ``fork()``, because that is the only
    correct point: a ``BatchSpanProcessor`` thread does not survive a fork and a pool opened
    before one hands two processes the same sockets.

    THE FIFTH STEP IS THE CALLBACK TRANSPORT, and it is asserted here rather than trusted
    because forgetting it is silent until the very end of a run: `publish.report_readiness`
    refuses to no-op, so a worker with no emitter parses, chunks, embeds, indexes and verifies a
    whole document and then raises at the last statement — every time, on every document, with a
    complete and correct point set on disk that nothing will ever activate.
    """
    process._on_process_init()

    assert child == [
        "configure:{'in_worker': True}",
        "bomb-promoted",
        "ran-on-the-child-loop",
        "emitters-installed",
    ]
    assert process.worker_clients() is STUB_CLIENTS


def test_process_shutdown_closes_the_clients_and_then_flushes_telemetry(
    child: list[str],
) -> None:
    """``otel.shutdown()`` is REQUIRED here and optional in the API: a billiard child exits
    through ``os._exit``, which runs no ``atexit`` hook, so the SDK's own shutdown never fires
    and whatever the span processor holds at that moment is discarded.

    Last, because closing the clients emits the lines and spans this flush is meant to carry.
    """
    process._on_process_init()
    child.clear()

    process._on_process_shutdown()

    # The emitter's HTTP client goes FIRST, before the loop it never ran on is closed, and
    # telemetry LAST because closing the clients emits the lines this flush is meant to carry.
    assert child == [
        "emitter-closed",
        "ran-on-the-child-loop",
        "loop-closed",
        "otel-shutdown",
    ]


def test_a_task_in_a_process_that_never_ran_process_init_gets_a_message_naming_the_cause(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(process, "_clients", None)

    with pytest.raises(RuntimeError, match="worker_process_init"):
        process.worker_clients()


# ─────────────────────────────────────────────────────────────────────────────
# The two bootsteps
# ─────────────────────────────────────────────────────────────────────────────


def test_both_bootsteps_are_registered_on_the_worker_blueprint() -> None:
    """``steps['worker']``, not ``steps['consumer']``: the consumer blueprint restarts on every
    broker reconnect, which would restart the heartbeat timer with it."""
    from app.worker import celery_app
    from app.worker.heartbeat import HeartbeatStep

    assert HeartbeatStep in celery_app.steps["worker"]
    assert process.OcrEngineAssertionStep in celery_app.steps["worker"]
    assert HeartbeatStep not in celery_app.steps["consumer"]


# ─────────────────────────────────────────────────────────────────────────────
# The decompression-bomb promotion
# ─────────────────────────────────────────────────────────────────────────────


def test_the_decompression_bomb_warning_becomes_an_error() -> None:
    """``app/ingestion/ocr/guarded.py:open_guarded`` catches ``DecompressionBombWarning`` in the
    same ``except`` as ``DecompressionBombError``, and its docstring names this promotion as what
    makes that arm reachable.

    Pillow only RAISES above its hard band; between ``MAX_IMAGE_PIXELS`` and twice that it merely
    warns — so without the promotion an image in that band is decoded, the warning goes to
    stderr, and the allocation the cap exists to prevent has already happened.
    """
    from PIL import Image

    with warnings.catch_warnings():
        process._promote_decompression_bomb_warning()

        with pytest.raises(Image.DecompressionBombWarning):
            warnings.warn("a very large image", Image.DecompressionBombWarning, stacklevel=1)


def test_the_promotion_is_scoped_to_one_category() -> None:
    """``filterwarnings("error")`` for everything would turn an upstream ``DeprecationWarning``
    into a failed tenant job."""
    with warnings.catch_warnings(record=True) as seen:
        process._promote_decompression_bomb_warning()

        warnings.warn("upstream is deprecating something", UserWarning, stacklevel=1)

    assert [type(w.message) for w in seen] == [UserWarning]


def test_guarded_still_translates_the_promoted_warning(tmp_path: Any) -> None:
    """The promotion is only safe because ``open_guarded`` catches it. Proven against the real
    function rather than against a reading of it."""
    from PIL import Image

    from app.ingestion.ocr.guarded import OcrRejected, open_guarded

    class _Bomb:
        def read(self, *args: Any) -> bytes:
            raise Image.DecompressionBombWarning("declared size is a bomb")

        def seek(self, *args: Any) -> int:
            return 0

        def tell(self) -> int:
            return 0

    with warnings.catch_warnings():
        process._promote_decompression_bomb_warning()

        with pytest.raises(OcrRejected):
            open_guarded(_Bomb())


# ─────────────────────────────────────────────────────────────────────────────
# The OCR engine assertion
# ─────────────────────────────────────────────────────────────────────────────


def test_the_pinned_ocr_engine_resolves_to_a_class_docling_actually_registers() -> None:
    """Docling's ``auto`` option picks an engine by IMPORT PROBING: a laptop and the Linux worker
    then emit different text for the same scan while the OCR config version string is identical,
    so nothing ever reprocesses. Pinning by class is the defence, and a pin nobody checks is a
    comment."""
    from app.ingestion.ocr.guarded import DEFAULT_ENGINE

    process._assert_ocr_engine(DEFAULT_ENGINE)  # must not raise


def test_an_unknown_engine_name_fails_the_container() -> None:
    with pytest.raises(RuntimeError, match="not pinned"):
        process._assert_ocr_engine("an-engine-nobody-configured")


def test_a_pin_that_no_longer_resolves_fails_the_container(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """An upstream rename is the failure this catches, and it is invisible otherwise: the
    pipeline falls back to its own default and every scanned page is read by a different
    engine."""
    monkeypatch.setattr(
        "app.ingestion.ocr.guarded.OCR_ENGINE_CLASSES",
        {"rapidocr": "docling.datamodel.pipeline_options.RenamedUpstreamOptions"},
    )

    with pytest.raises(RuntimeError, match="does not resolve"):
        process._assert_ocr_engine("rapidocr")


def test_a_class_docling_does_not_register_fails_the_container(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Resolving the dotted path is not enough. An options class that is not in the factory is
    never selected, so the pipeline silently uses its own default instead."""
    monkeypatch.setattr(
        "app.ingestion.ocr.guarded.OCR_ENGINE_CLASSES",
        {"rapidocr": "app.ingestion.ocr.guarded.OcrRejected"},
    )

    with pytest.raises(RuntimeError, match="not registered"):
        process._assert_ocr_engine("rapidocr")


def test_a_banned_option_class_can_never_be_selected(monkeypatch: pytest.MonkeyPatch) -> None:
    """``OcrAutoOptions`` resolves the engine per host; ``KserveV2OcrOptions`` is a network call
    from a container that has no network. Neither may reach the converter, and pointing the pin
    at one is the way that happens."""
    monkeypatch.setattr(
        "app.ingestion.ocr.guarded.OCR_ENGINE_CLASSES",
        {"rapidocr": "docling.datamodel.pipeline_options.OcrAutoOptions"},
    )

    with pytest.raises(RuntimeError, match="BANNED_OCR_OPTION_CLASSES"):
        process._assert_ocr_engine("rapidocr")


def test_the_assertion_only_runs_on_the_worker_that_parses_documents(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Resolving the class imports docling, which imports torch. Paying that in every prefork
    child of ``ai-worker-crawl`` — eight of them, none of which will ever see a page raster — is
    the exact cost ``app/ingestion/parsing/converter.py`` defers its own import to avoid."""
    monkeypatch.setattr(
        process, "_assert_ocr_engine", _must_not_be_called("the crawl worker ran the assertion")
    )

    # `worker.app.amqp.queues` is the selected queue set, exactly as Celery's own
    # `WorkController.setup_queues` leaves it. Built with SimpleNamespace rather than nested
    # classes so the attribute names can match Celery's lowercase ones without fighting the
    # linter over class naming.
    crawl_worker = SimpleNamespace(
        app=SimpleNamespace(amqp=SimpleNamespace(queues={"crawl": object()}))
    )

    process.OcrEngineAssertionStep(crawl_worker)
