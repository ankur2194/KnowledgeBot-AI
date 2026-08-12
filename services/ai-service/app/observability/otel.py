"""OpenTelemetry bootstrap for the FastAPI data plane and its Celery workers.

One module, two shapes, and **four** call sites:

* FastAPI — from ``create_app()`` in ``app/main.py``, as its **last statement**:
  ``configure(app=app)``.
* Celery — from ``worker_process_init`` and from ``beat_init``:
  ``configure(app=None, in_worker=True)``, with ``shutdown()`` on ``worker_process_shutdown``.

WHY ``configure(app=app)`` IS THE LAST STATEMENT OF ``create_app()`` AND NOT A LIFESPAN CALL
---------------------------------------------------------------------------------------------
This docstring used to say "from the lifespan in ``app/main.py``". **That was wrong, and its
failure mode is silence rather than an exception**, which is why it is written out here.

Measured against the pinned ``opentelemetry-instrumentation-fastapi==0.65b0`` and
``starlette==1.3.1``:

* ``Starlette.__call__`` does ``if self.middleware_stack is None: self.middleware_stack =
  self.build_middleware_stack()`` — and **the lifespan scope is a ``__call__``**, so the stack
  is already built and cached by the time a lifespan body runs.
* ``FastAPIInstrumentor.instrument_app`` on this version does **not** call ``add_middleware``
  (which would have raised ``Cannot add middleware after an application has started``). It
  replaces ``app.build_middleware_stack`` with its own wrapper. Since that method is called
  exactly once, per application, on the first ``__call__``, replacing it afterwards has **no
  effect at all**: no exception, no warning, and the app serves requests untraced forever.
* Reproduced both ways on this tree: instrumenting from the lifespan produced **zero** spans for
  a subsequent ``GET``; instrumenting inside ``create_app()`` produced ``GET /x`` plus its two
  ``http send`` children (which is what ``exclude_spans`` below removes).

``configure_logging()`` stays the FIRST statement of ``create_app()`` for a reason specific to
uvicorn's ``dictConfig`` (see ``app/main.py``). That reason does not transfer to telemetry, and
the two calls are deliberately not unified: one has to happen before uvicorn logs anything, the
other has to happen before the ASGI app is first called.

**Celery must call this from ``worker_process_init``, never at import.**
``BatchSpanProcessor`` runs a background thread, and a thread does not survive ``fork()``. A
provider built in the parent process exports precisely nothing from the children, silently, for
the life of the deployment. The same reasoning bans the gRPC OTLP exporter here: gRPC's own
background threads plus prefork ``fork()`` deadlock on the first export, so the transport is
``http/protobuf`` everywhere.

``beat_init`` is the third signal and is not redundant: **``ai-beat`` forks nothing**, so
``worker_process_init`` never fires there and beat would run with a proxy tracer forever. The
identical reasoning is already written out for logging at ``app/worker/__init__.py``.

Environment is identical in all four runtimes and is set in the Compose env files, not here —
``OTEL_SERVICE_NAME``, ``OTEL_EXPORTER_OTLP_ENDPOINT``, ``OTEL_EXPORTER_OTLP_PROTOCOL``,
``OTEL_EXPORTER_OTLP_TIMEOUT``, ``OTEL_PROPAGATORS``, ``OTEL_TRACES_SAMPLER``,
``OTEL_METRICS_EXEMPLAR_FILTER``, ``OTEL_LOGS_EXPORTER``. This module reads only the values
whose absence or presence is a correctness question rather than a configuration one.

Sampling is head-100% at every service and the decision is made once, at Laravel's ingress, then
carried in the ``traceparent`` flags. The Collector's tail sampler decides what is kept. Head
sampling is a one-way door: non-recording spans never reach a span processor, so no tail sampler
can recover the one request someone is investigating.
"""

from __future__ import annotations

import logging
import os
from typing import TYPE_CHECKING, Final

from opentelemetry import metrics, trace
from opentelemetry.exporter.otlp.proto.http.metric_exporter import OTLPMetricExporter
from opentelemetry.exporter.otlp.proto.http.trace_exporter import OTLPSpanExporter
from opentelemetry.instrumentation.celery import CeleryInstrumentor
from opentelemetry.instrumentation.fastapi import FastAPIInstrumentor
from opentelemetry.instrumentation.httpx import HTTPXClientInstrumentor
from opentelemetry.instrumentation.logging import LoggingInstrumentor
from opentelemetry.instrumentation.psycopg import PsycopgInstrumentor
from opentelemetry.instrumentation.redis import RedisInstrumentor
from opentelemetry.metrics import NoOpMeterProvider, set_meter_provider
from opentelemetry.sdk.metrics import MeterProvider
from opentelemetry.sdk.metrics.export import PeriodicExportingMetricReader
from opentelemetry.sdk.resources import Resource
from opentelemetry.sdk.trace import TracerProvider
from opentelemetry.sdk.trace.export import BatchSpanProcessor
from opentelemetry.semconv.schemas import Schemas

# Importing the package registers every instrument. Explicit here so that a process which
# configures telemetry cannot end up with providers and no instruments.
#
# NOTHING IN `instruments.py` NEEDED CHANGING FOR ANY OF THIS, AND THAT WAS VERIFIED RATHER THAN
# ASSUMED. The API's `get_meter()` hands back a `_ProxyMeter` whose instruments — counters,
# histograms, up-down counters AND the four observable gauges — re-bind to the real ones when
# `set_meter_provider` installs an SDK provider, so "instruments at import, providers after
# fork()" works with no registration step.
#
# THE CONSEQUENCE, WRITTEN DOWN BECAUSE SOMEBODY WILL TRY TO WRITE THE OPPOSITE:
# `trace.set_tracer_provider` and `metrics.set_meter_provider` are ONE-SHOT process globals. A
# second call logs "Overriding of current TracerProvider is not allowed" and is otherwise
# ignored, and the proxy objects handed out before the first call are permanently bound to that
# first provider. So there can be no honest `reset_telemetry_for_tests()` mirroring
# `reset_logging_for_tests()`: resetting the `_configured` flag would let `configure()` build a
# second provider that nothing can ever reach, and every span would keep going to the first one
# while the test believed otherwise. A test that needs a provider it can read must install its
# own BEFORE anything calls `configure()`, or run in a subprocess.
from app.observability import instruments as instruments
from app.observability.logging import _resolve_service

if TYPE_CHECKING:  # pragma: no cover
    from fastapi import FastAPI

logger = logging.getLogger(__name__)

__all__ = [
    "CONTENT_CAPTURE_ENV",
    "ENDPOINT_ENV",
    "SEMCONV",
    "configure",
    "force_flush",
    "shutdown",
    "telemetry_is_configured",
]


#: The pin. Every ``Resource`` carries this schema URL, and bumping it is a dashboard migration:
#: unpinned semconv is a silent rename of every attribute a panel queries, and the SDK will
#: happily emit the new name against the old panel with no error anywhere.
#:
#: ``gen_ai.*`` is deliberately NOT covered by this pin — that namespace left the main semconv
#: repository at v1.42.0 for a repository with no releases and no schema URL. There is nothing to
#: pin, which is exactly why ``gen_ai.*`` is used for span attributes (one adapter module, cheap
#: to remap, every vendor trace UI reads them) and never for a metric name (compiled into alerts,
#: expensive to remap). Our metrics are ``kb_*``.
SEMCONV: Final[str] = Schemas.V1_43_0.value

#: The one environment variable this service refuses to start with.
CONTENT_CAPTURE_ENV: Final[str] = "OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT"

#: The switch that decides whether this process has telemetry at all.
#:
#: ``configure()`` is a NO-OP when it is unset, and that is a correctness requirement rather than
#: a convenience: ``app = create_app()`` sits at module level in ``app/main.py``, so every
#: `create_app()` in the test suite would otherwise start a ``BatchSpanProcessor`` thread and a
#: ``PeriodicExportingMetricReader`` thread aimed at a Collector that is not running — per test
#: file, in-process, with a 30 s export interval and a retrying HTTP exporter behind it. Under
#: ``fastapi run`` the variable is set by ``env/ai-service.env`` and telemetry comes up.
#:
#: The gate is PRESENCE-AND-NON-EMPTY, matching the SDK's own reading of the variable: an
#: exporter constructed against ``""`` does not fall back to the default endpoint, it builds a
#: URL that resolves to nothing.
ENDPOINT_ENV: Final[str] = "OTEL_EXPORTER_OTLP_ENDPOINT"

#: Probes run per replica per interval forever and would otherwise be most of the trace store —
#: and they poison the tail sampler's ``num_traces`` budget with traces nobody will ever read.
_EXCLUDED_URLS: Final[str] = "health/live,health/ready,health/deps,metrics"

_configured = False


class GenAiContentCaptureError(RuntimeError):
    """Raised at boot when GenAI content capture is switched on.

    Its own class so a boot test can assert the reason rather than matching on message text.
    """


def _fail_closed_on_content_capture() -> None:
    """Refuse to start if GenAI message-content capture is enabled.

    ``OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT`` gates ``gen_ai.input.messages``,
    ``gen_ai.output.messages``, ``gen_ai.system_instructions`` and — aimed straight at a RAG
    system — ``gen_ai.retrieval.query.text`` and ``gen_ai.retrieval.documents``. Those are the
    user's question, the assembled prompt, and the retrieved tenant chunks: the most sensitive
    objects this platform handles. Switching it on ships them to Tempo, which has no per-tenant
    access control and a fixed retention window.

    PRESENCE is the test, not truthiness. ``...=false`` still raises, because the variable being
    present at all means somebody reached for this switch, and the next person to edit that env
    file will find a name that looks like a supported option. There is no supported value.

    This is a boot-time refusal rather than a runtime filter because the failure is silent and
    unbounded: an operator who exports one conversation to the trace store has exported all of
    them, and the data cannot be recalled.
    """
    if CONTENT_CAPTURE_ENV in os.environ:
        raise GenAiContentCaptureError(
            f"{CONTENT_CAPTURE_ENV} is set. It captures user questions, assembled prompts and "
            "retrieved tenant chunks into spans. Remove it from the environment; there is no "
            "permitted value (kb-security-baseline, kb-observability-conventions)."
        )


def configure(*, app: FastAPI | None = None, in_worker: bool = False) -> None:
    """Install tracing, metrics and auto-instrumentation for this process.

    Args:
        app: the FastAPI application to instrument, or ``None`` in a worker process.
        in_worker: ``True`` when called from Celery's ``worker_process_init``.

    Idempotent: a second call is a no-op. Two tracer providers means half the spans go to a
    provider whose processor nobody flushes.

    **A no-op when :data:`ENDPOINT_ENV` is unset** — see that constant for why that gate exists
    and why it is presence-and-non-empty. The content-capture refusal still runs first, because
    "there is no Collector configured" is not a reason to tolerate a switch that would ship
    tenant prompts into whatever Collector is configured next.
    """
    global _configured

    # First, before anything else in the process is wired.
    _fail_closed_on_content_capture()

    if _configured:
        logger.debug("telemetry already configured for this process")
        return

    if not os.environ.get(ENDPOINT_ENV):
        # INFO, not WARNING: this is the normal state under pytest and for anyone running the
        # service without the observability profile. It is logged at all so that "no traces
        # anywhere" has a one-line explanation in the container log rather than being a silence
        # that looks identical to a broken exporter.
        logger.info(
            "telemetry not configured: %s is unset, so no tracer or meter provider is "
            "installed and every instrument stays a no-op proxy",
            ENDPOINT_ENV,
        )
        return

    resource = Resource.create(
        # `_resolve_service()`, not `os.environ["OTEL_SERVICE_NAME"]`. That KeyError-on-absence
        # read was a second, disagreeing statement of a precedence the logging module already
        # implements with its reasoning written out: OTEL_SERVICE_NAME, then
        # `Settings.otel_service_name`, then "ai-api". Restating it here is exactly how the
        # `service` LOG field and the `service` METRIC label come to describe different fleets —
        # both queries succeed and neither is right.
        {"service.name": _resolve_service()},
        schema_url=SEMCONV,
    )

    # The sampler comes from OTEL_TRACES_SAMPLER (parentbased_always_on) and is never constructed
    # here — a sampler in code is a sampler that differs between environments.
    provider = TracerProvider(resource=resource)
    provider.add_span_processor(BatchSpanProcessor(OTLPSpanExporter()))
    trace.set_tracer_provider(provider)

    set_meter_provider(
        MeterProvider(
            resource=resource,
            metric_readers=[PeriodicExportingMetricReader(OTLPMetricExporter())],
        )
    )

    if app is not None:
        FastAPIInstrumentor.instrument_app(
            app,
            excluded_urls=_EXCLUDED_URLS,
            # SSE: without this the ASGI layer emits one ``http send`` child span PER FRAME, so
            # an 800-token answer becomes an 800-span trace that Tempo times out rendering. The
            # symptom on the metrics side is a Collector batch queue that never drains at peak.
            exclude_spans=["send", "receive"],
        )

    if in_worker:
        # use_span_links=True makes the instrumentation call ``start_span(links=[...])`` and skip
        # the context attach — a real new root, linked back to the submitter. The default is
        # False, i.e. a CHILD of a request that already ended: the parent closed at 202, the
        # trace was flushed minutes before the job's spans arrived, and the tail sampler's
        # decision window expired long ago. Those spans are dropped or attached to a request from
        # forty minutes earlier.
        #
        # NoOpMeterProvider suppresses ``flower.task.runtime.seconds``, which the instrumentation
        # registers on its own. It is not in the catalog, its name already ends in its unit AND
        # it declares one, and it fails the CI catalog diff on the first scrape. Suppression at
        # the SDK is the defence; the Collector's metric allow-list is the backstop.
        #
        # The ignore is on the CONSTRUCTOR, not the call. ``opentelemetry/instrumentation/`` is a
        # namespace directory carrying one ``py.typed`` from the core distribution, so mypy treats
        # every sibling under it as typed — including ``…/celery/__init__.py``, which ships no
        # ``py.typed`` of its own and whose ``def __init__(self):`` is unannotated. Hence
        # ``no-untyped-call`` here and not on the four instrumentors below, which inherit
        # ``BaseInstrumentor.__init__``. Note ``ignore_missing_imports`` cannot reach this: the
        # module is found and read, it is the SIGNATURE that is missing.
        #
        # Scoped to the one error code on the one line rather than
        # ``disallow_untyped_calls = false`` for the module — a module-level override would also
        # swallow the next untyped call someone adds here, and this suppression must not outlive
        # its cause. ``strict = true`` implies ``warn_unused_ignores``, so the day upstream
        # annotates that ``__init__`` mypy fails on the now-redundant ignore and it gets deleted.
        CeleryInstrumentor().instrument(  # type: ignore[no-untyped-call]
            use_span_links=True,
            meter_provider=NoOpMeterProvider(),
        )

    # Qdrant, object storage and every provider call travel over httpx.
    #
    # TODO(provider-adapter-engineer): the provider clients need a TRACECONTEXT-ONLY propagator.
    # This instrumentation injects both ``traceparent`` and ``baggage`` into every outbound
    # request, including calls to OpenAI, Anthropic, DeepSeek, NIM and OpenRouter. ``traceparent``
    # is a random id and is worth sending — NVIDIA adopts it for support correlation — but
    # ``baggage`` carries arbitrary key/values set anywhere upstream, and it leaves our boundary.
    HTTPXClientInstrumentor().instrument()

    RedisInstrumentor().instrument()
    PsycopgInstrumentor().instrument()

    # set_logging_format=False: the JSON formatter owns the line shape, and it now EXISTS —
    # `app/observability/logging.py:KbJsonFormatter`, installed by `configure_logging()` from
    # `create_app()` and from Celery's `setup_logging` signal. This comment previously described
    # a formatter that had never been written, so the trace ids this call injects were handed to
    # a formatter that rendered nothing and the Loki-to-Tempo link did not exist.
    #
    # What this call contributes now is narrow, and saying so is the point. It adds
    # `otelTraceID` / `otelSpanID` / `otelTraceSampled` to every LogRecord. Our formatter reads
    # the ACTIVE SPAN CONTEXT first and treats those attributes only as a fallback, because the
    # formatter must not depend on a bootstrap the process may not have run — and there are
    # still processes that do not run it, because `configure()` no-ops without
    # OTEL_EXPORTER_OTLP_ENDPOINT. Correlation therefore works from the moment a tracer provider
    # exists, with or without this line; this line keeps working for any third-party formatter
    # that reads the record attributes instead.
    #
    # Log RECORDS still go to stdout as JSON and are collected by the Collector's ``file_log``
    # receiver — OTEL_LOGS_EXPORTER=none — so the application never blocks on the log pipeline.
    LoggingInstrumentor().instrument(set_logging_format=False)

    _configured = True
    logger.info(
        "telemetry configured",
        extra={"in_worker": in_worker, "instrumented_app": app is not None},
    )


def telemetry_is_configured() -> bool:
    """Whether :func:`configure` installed providers in this process.

    ``False`` after a gated no-op, which is the point: it answers "does this process export?",
    not "was ``configure`` called?".
    """
    return _configured


def shutdown() -> None:
    """Flush and stop the providers.

    **Optional for ``ai-api``** and deliberately so: a telemetry flush must never extend a
    shutdown that is racing in-flight streams. ``ai-api`` has a 90 s stop grace period against a
    60 s chat deadline precisely so the last answer lands, and the span for a request nobody
    reads is not worth a second of that.

    **REQUIRED in the Celery prefork children**, from ``worker_process_shutdown``, and that
    asymmetry is not a preference. A billiard child exits through ``os._exit``, which runs no
    ``atexit`` hook — so the SDK's own registered shutdown never fires, and everything the
    ``BatchSpanProcessor`` is holding at that moment is discarded. The spans lost that way are
    the last ones a task emitted before the worker was told to stop, which is precisely the
    window an operator is looking at.

    THE GUARDS ARE NOT DEFENSIVE PADDING. Before ``configure()`` runs — and in every process
    where it deliberately no-ops — the globals hold ``ProxyTracerProvider`` and
    ``_ProxyMeterProvider``, which have **no** ``shutdown`` and **no** ``force_flush`` method at
    all. Calling this from a signal handler in an unconfigured process would raise
    ``AttributeError`` inside Celery's shutdown, which turns "no telemetry" into "the worker
    logs a traceback on every stop".
    """
    tracer_provider = trace.get_tracer_provider()
    meter_provider = metrics.get_meter_provider()
    for provider in (tracer_provider, meter_provider):
        stop = getattr(provider, "shutdown", None)
        if stop is None:
            continue
        try:
            stop()
        except Exception:
            # Never let a telemetry teardown be the reason a process fails to stop. The
            # exporter is fire-and-forget by contract at every other point in its life, and a
            # Collector that has already gone away is the ordinary case during a stack restart.
            logger.warning("telemetry shutdown failed", exc_info=True)


def force_flush(timeout_millis: int = 3000) -> bool:
    """Flush pending spans and metrics. For tests and one-shot management commands only.

    Never on a request path: the exporter is fire-and-forget by contract, and a synchronous
    flush on a request is how a Collector restart turns into user-visible latency.

    Returns ``True`` when every provider that *can* be flushed reported success — and ``True``
    in an unconfigured process, where there is nothing pending and nothing to report. The same
    proxy-provider guard as :func:`shutdown` applies for the same reason.
    """
    flushed = True
    for provider in (trace.get_tracer_provider(), metrics.get_meter_provider()):
        flush = getattr(provider, "force_flush", None)
        if flush is None:
            continue
        flushed = bool(flush(timeout_millis)) and flushed
    return flushed


# TODO(retrieval-engineer / provider-adapter-engineer): the streaming span lives in a sibling
# module (``app/observability/chat.py``), not here, and it must be hand-written.
#
# ``kb.chat.stream`` is ONE internal span for the whole stream. Per-delta spans and per-delta span
# EVENTS are both wrong: the SDK caps events at 128 per span and discards the rest into a counter
# nobody reads, so a per-token event stream loses data and inflates cost for nothing. Timing lives
# in ``kb_provider_first_token_seconds``; counts land as end-of-span attributes.
#
# It must be opened with ``start_span`` and closed in an explicit ``finally`` — never a context
# manager wrapped around a generator, whose exit depends on garbage collection. A span that is
# never ended is never exported, so the request that failed is the one missing from the trace
# store, and its context token leaks for the life of the process. ``CancelledError`` is re-raised
# rather than swallowed, and ``kb.finish_reason`` is recorded in that same ``finally``.
