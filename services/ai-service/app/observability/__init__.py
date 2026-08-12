"""OTel wiring and metric instruments. Instruments register at module import.

Two modules, two jobs:

* ``otel`` — :func:`app.observability.otel.configure`, called once per process. From the
  FastAPI lifespan with ``app=...``, and from Celery's ``worker_process_init`` signal with
  ``app=None, in_worker=True``. It builds the providers, installs auto-instrumentation, and
  refuses to start the process if GenAI content capture is switched on.
* ``instruments`` — every metric instrument this service owns, all of them created at module
  import.

Importing this package imports ``instruments``, on purpose. Instrument registration must be a
side effect of the package existing, not of the first request touching a code path: the CI
catalog diff scrapes the Collector and compares the exposed name set against
``kb-observability-conventions/references/metric-catalog.md``, and an instrument that has never
been touched exposes no series at all. Lazy registration therefore makes that diff pass
trivially — it green-lights a service that emits nothing.

Creating instruments before :func:`configure` runs is safe: the OTel API hands out proxy
instruments that re-bind to the real ``MeterProvider`` when ``set_meter_provider`` is called.
That ordering is load-bearing in the Celery workers, where the provider cannot be built until
after ``fork()`` while the module graph is imported long before it.

**Names are decided elsewhere and are permanent.** Span names, metric names, the label
allow-list, log fields and the telemetry-versus-audit split belong to
``kb-observability-conventions``; SDK versions, exporters and the sampler belong to
``opentelemetry-instrumentation``. Nothing here may introduce a name that is not already in the
catalog — a name compiles into recording rules, alerts, dashboards and runbooks, so renaming one
is a migration with a dual-write window rather than an edit.

**No telemetry failure may fail a request.** Exporters are fire-and-forget with a short timeout
to a local Collector; the Collector is the only component permitted to buffer, retry or block.
"""

from app.observability import instruments as instruments

__all__ = ["instruments"]
