"""The OpenTelemetry bootstrap: when it runs, where it runs, and what it refuses to do.

``otel.configure()`` was called by nothing at all before this, so every one of these is a first
assertion rather than a regression guard.

WHY ALMOST EVERYTHING HERE IS STUBBED, AND WHY THAT IS NOT A WEAKER TEST
------------------------------------------------------------------------
``trace.set_tracer_provider`` and ``metrics.set_meter_provider`` are **one-shot process
globals**: the second call logs "Overriding of current TracerProvider is not allowed" and is
otherwise ignored, and every proxy instrument handed out before the first call is permanently
bound to whatever that first call installed. A test that let ``configure()`` install a real SDK
provider would therefore poison every later test in the session AND start a
``BatchSpanProcessor`` thread plus a metric reader thread aimed at a Collector that is not
running.

That is the same fact ``app/observability/instruments.py`` records as the reason there can be no
honest ``reset_telemetry_for_tests()``. So the SDK constructors are replaced in the module
namespace and the assertions are about *which* objects ``configure`` builds and with what —
which is the part that can actually be wrong.
"""

from __future__ import annotations

from typing import Any

import pytest

from app.observability import otel

#: ``(what was instrumented, with which keyword arguments)``, in call order.
_INSTRUMENTED: list[tuple[str, dict[str, Any]]] = []


class _Recorder:
    """Stands in for an SDK provider, an exporter or an instrumentor."""

    def __init__(self, *args: Any, **kwargs: Any) -> None:
        self.args = args
        self.kwargs = kwargs
        self.processors: list[Any] = []

    def add_span_processor(self, processor: Any) -> None:
        self.processors.append(processor)

    def instrument(self, **kwargs: Any) -> None:
        _INSTRUMENTED.append((type(self).__name__, kwargs))

    @classmethod
    def instrument_app(cls, app: Any, **kwargs: Any) -> None:
        # A CLASSMETHOD because the real one is a `@staticmethod` on `FastAPIInstrumentor` and
        # is called on the class, never on an instance. Getting this wrong here would make the
        # stub reject a call the production code makes correctly.
        _INSTRUMENTED.append((f"{cls.__name__}.instrument_app", kwargs))


def _named(name: str) -> type[_Recorder]:
    """A distinct stub class per replaced name, so assertions can say which one was called."""
    return type(name, (_Recorder,), {})


@pytest.fixture
def stubbed(monkeypatch: pytest.MonkeyPatch) -> dict[str, Any]:
    """Replace every SDK object ``configure`` builds, and reset the module's one-shot flag."""
    _INSTRUMENTED.clear()
    installed: dict[str, Any] = {}

    monkeypatch.setattr(otel, "_configured", False)
    for name in (
        "TracerProvider",
        "BatchSpanProcessor",
        "OTLPSpanExporter",
        "OTLPMetricExporter",
        "PeriodicExportingMetricReader",
        "MeterProvider",
        "NoOpMeterProvider",
        "FastAPIInstrumentor",
        "HTTPXClientInstrumentor",
        "RedisInstrumentor",
        "PsycopgInstrumentor",
        "LoggingInstrumentor",
        "CeleryInstrumentor",
    ):
        monkeypatch.setattr(otel, name, _named(name))

    monkeypatch.setattr(
        otel.trace, "set_tracer_provider", lambda p: installed.__setitem__("tracer", p)
    )
    monkeypatch.setattr(otel, "set_meter_provider", lambda p: installed.__setitem__("meter", p))
    return installed


# ─────────────────────────────────────────────────────────────────────────────
# The endpoint gate
# ─────────────────────────────────────────────────────────────────────────────


def test_configure_is_a_no_op_without_an_otlp_endpoint(
    stubbed: dict[str, Any], monkeypatch: pytest.MonkeyPatch
) -> None:
    """No endpoint, no providers — because ``app = create_app()`` runs at IMPORT.

    Without this gate every ``create_app()`` in the suite starts a ``BatchSpanProcessor`` thread
    and a ``PeriodicExportingMetricReader`` thread pointed at a Collector that is not there, in
    the pytest process, per test file.
    """
    monkeypatch.delenv(otel.ENDPOINT_ENV, raising=False)

    otel.configure()

    assert stubbed == {}, "a provider was installed with no OTLP endpoint configured"
    assert _INSTRUMENTED == [], "auto-instrumentation was installed with no OTLP endpoint"
    assert not otel.telemetry_is_configured()


def test_an_empty_endpoint_is_treated_as_absent(
    stubbed: dict[str, Any], monkeypatch: pytest.MonkeyPatch
) -> None:
    """``OTEL_EXPORTER_OTLP_ENDPOINT=`` does not fall back to a default endpoint — the exporter
    builds a URL that resolves to nothing — so an empty value must gate the same as an unset
    one."""
    monkeypatch.setenv(otel.ENDPOINT_ENV, "")

    otel.configure()

    assert stubbed == {}
    assert not otel.telemetry_is_configured()


def test_configure_installs_both_providers_when_an_endpoint_is_set(
    stubbed: dict[str, Any], monkeypatch: pytest.MonkeyPatch
) -> None:
    monkeypatch.setenv(otel.ENDPOINT_ENV, "http://otel-collector:4318")

    otel.configure()

    assert set(stubbed) == {"tracer", "meter"}
    assert otel.telemetry_is_configured()


# ─────────────────────────────────────────────────────────────────────────────
# The service name
# ─────────────────────────────────────────────────────────────────────────────


def test_the_resource_service_name_comes_from_the_logging_module_s_resolver(
    stubbed: dict[str, Any], monkeypatch: pytest.MonkeyPatch
) -> None:
    """One resolver, not two.

    This used to be ``os.environ["OTEL_SERVICE_NAME"]`` — a second statement of a precedence
    ``app/observability/logging.py:_resolve_service`` already implements with its reasoning
    written out. Two spellings is how the ``service`` LOG field and the ``service`` METRIC label
    come to name different fleets while both queries succeed.
    """
    captured: dict[str, Any] = {}
    monkeypatch.setenv(otel.ENDPOINT_ENV, "http://otel-collector:4318")
    monkeypatch.setattr(
        otel.Resource,
        "create",
        staticmethod(lambda attributes, schema_url=None: captured.update(attributes) or object()),
    )
    monkeypatch.setattr(otel, "_resolve_service", lambda: "ai-worker-from-the-resolver")

    otel.configure()

    assert captured["service.name"] == "ai-worker-from-the-resolver"


def test_a_missing_service_name_variable_does_not_raise(
    stubbed: dict[str, Any], monkeypatch: pytest.MonkeyPatch
) -> None:
    """The old ``os.environ[...]`` subscript raised ``KeyError`` when the variable was absent.

    Every container sets it, so that failure only ever appeared where nobody was looking: a
    one-shot management command, a shell in the image, a developer running the app directly.
    """
    monkeypatch.setenv(otel.ENDPOINT_ENV, "http://otel-collector:4318")
    monkeypatch.delenv("OTEL_SERVICE_NAME", raising=False)

    otel.configure()

    assert otel.telemetry_is_configured()


# ─────────────────────────────────────────────────────────────────────────────
# The worker shape
# ─────────────────────────────────────────────────────────────────────────────


def test_the_worker_shape_suppresses_the_celery_instrumentation_s_own_metric(
    stubbed: dict[str, Any], monkeypatch: pytest.MonkeyPatch
) -> None:
    """``flower.task.runtime.seconds`` is registered by the instrumentation itself.

    It is not in the catalog, its name already ends in its unit *and* it declares one, and the
    catalog diff scrapes the Collector and fails on the first family it cannot find. Suppression
    at the SDK is the defence; the Collector's allow-list is the backstop.
    """
    monkeypatch.setenv(otel.ENDPOINT_ENV, "http://otel-collector:4318")

    otel.configure(in_worker=True)

    celery = dict(_INSTRUMENTED)["CeleryInstrumentor"]
    assert celery["use_span_links"] is True, (
        "without span links the instrumentation makes a job's spans a CHILD of a request that "
        "closed at 202 minutes earlier, and the tail sampler's decision window has expired"
    )
    assert isinstance(celery["meter_provider"], otel.NoOpMeterProvider), (
        "the Celery instrumentation registers flower.task.runtime.seconds on its own; it is not "
        "in the catalog and fails the CI diff on the first scrape"
    )


def test_the_api_shape_instruments_the_application_and_the_worker_shape_does_not(
    stubbed: dict[str, Any], monkeypatch: pytest.MonkeyPatch
) -> None:
    monkeypatch.setenv(otel.ENDPOINT_ENV, "http://otel-collector:4318")

    otel.configure(app=object())

    instrumented = dict(_INSTRUMENTED)
    assert "FastAPIInstrumentor.instrument_app" in instrumented
    assert "CeleryInstrumentor" not in instrumented
    assert instrumented["FastAPIInstrumentor.instrument_app"]["exclude_spans"] == [
        "send",
        "receive",
    ], "an 800-token SSE answer becomes an 800-span trace without this"


# ─────────────────────────────────────────────────────────────────────────────
# Content capture — the one refusal
# ─────────────────────────────────────────────────────────────────────────────


def test_content_capture_is_refused_even_when_telemetry_is_otherwise_a_no_op(
    stubbed: dict[str, Any], monkeypatch: pytest.MonkeyPatch
) -> None:
    """The endpoint gate must not become a way to smuggle the capture flag in.

    "There is no Collector configured yet" is not a reason to tolerate a switch that captures
    user questions, assembled prompts and retrieved tenant chunks — the next person to edit that
    env file finds a name that looks like a supported option.
    """
    monkeypatch.delenv(otel.ENDPOINT_ENV, raising=False)
    monkeypatch.setenv(otel.CONTENT_CAPTURE_ENV, "false")

    with pytest.raises(otel.GenAiContentCaptureError):
        otel.configure()


# ─────────────────────────────────────────────────────────────────────────────
# shutdown / force_flush against the PROXY providers
# ─────────────────────────────────────────────────────────────────────────────


def test_shutdown_is_safe_before_configure_has_ever_run() -> None:
    """The proxy providers have neither ``shutdown`` nor ``force_flush``.

    ``shutdown()`` is called from Celery's ``worker_process_shutdown`` in every worker,
    including the ones where ``configure()`` deliberately no-opped. Without the guard that is an
    ``AttributeError`` inside Celery's own shutdown on every single stop.
    """
    provider = otel.trace.get_tracer_provider()
    assert not hasattr(provider, "shutdown"), (
        "this test's premise is gone: the global tracer provider is no longer a proxy, so some "
        "other test installed a real one and this can no longer prove the guard"
    )

    otel.shutdown()  # must not raise


def test_force_flush_reports_success_when_there_is_nothing_to_flush() -> None:
    assert otel.force_flush() is True


def test_shutdown_swallows_a_provider_that_fails_to_flush(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """A Collector that has already gone away is the ordinary case during a stack restart, and
    it must never be the reason a process cannot stop."""

    class _Angry:
        def shutdown(self) -> None:
            raise RuntimeError("collector is gone")

    monkeypatch.setattr(otel.trace, "get_tracer_provider", lambda: _Angry())

    otel.shutdown()  # must not raise


def test_force_flush_reports_failure_when_a_provider_reports_failure(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    class _Slow:
        def force_flush(self, timeout_millis: int) -> bool:
            return False

    monkeypatch.setattr(otel.trace, "get_tracer_provider", lambda: _Slow())

    assert otel.force_flush(10) is False
