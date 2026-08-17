---
name: opentelemetry-instrumentation
description: OTel SDK wiring for KnowledgeBot AI — the pinned PHP, Python and browser SDKs, auto-instrumentation packages, OTLP transport, sampler and Collector. Use whenever bootstrapping tracing in a service, adding an instrumentation package, configuring an exporter or sampler, writing the Collector pipeline, or when a trace splits at the PHP→Python seam or an SSE stream emits one span per token. It wires the SDKs and pins semconv; names belong to kb-observability-conventions. Pairs with prometheus-grafana-loki-tempo.
---

# OpenTelemetry Instrumentation — SDK and Collector Wiring

Python **1.44.0** SDK / **0.65b0** instrumentation (2026-07-16) · PHP SDK **1.15.0**, API **1.10.0**, `exporter-otlp` **1.4.0**, `opentelemetry-auto-laravel` **1.8.0**, `opentelemetry-auto-guzzle` **1.4.0**, `opentelemetry-auto-pdo` **0.5.0**, `open-telemetry/sem-conv` **1.38.0**, PECL `opentelemetry` **1.2.1** · JS `@opentelemetry/api` **1.9.1**, `sdk-trace-web` **2.10.0**, `exporter-trace-otlp-http` **0.221.0**, `instrumentation` **0.221.0**, `instrumentation-document-load` **0.66.0**, `instrumentation-fetch` **0.221.0**, `@vercel/otel` **2.1.3** · Collector `otel/opentelemetry-collector-contrib` **0.158.0**.
**Semconv pinned at 1.43.0** — `https://opentelemetry.io/schemas/1.43.0`.
**Authoritative spec:** docs/15-observability.md §20, docs/06-architecture.md §11.2, docs/05-tech-stack.md §9.14, docs/18-deployment-backup-cicd.md §24.2

## Non-negotiables

- **`OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT` is never set, in any environment, including local.** It gates `gen_ai.input.messages`, `gen_ai.output.messages`, `gen_ai.system_instructions`, and — new and aimed straight at us — `gen_ai.retrieval.query.text` and `gen_ai.retrieval.documents`. Those are the user's question and the retrieved tenant chunks. Turning it on moves customer documents into Tempo, which has no per-tenant access control and a fixed retention (`kb-security-baseline`, `kb-observability-conventions`). The bootstrap **fails closed** on it — see the example.
- **One trace spans client → Laravel → FastAPI → provider** (`kb-observability-conventions`, §20.1). `traceparent` is a required header on the seam (`kb-internal-api-contracts`); it is injected by `opentelemetry-auto-guzzle`, never by hand. If that package is absent every internal call starts a fresh root and nothing errors.
- **Queue hops start a new root with a link, never a child.** Laravel: implement `TracingLinked` — `opentelemetry-auto-laravel` 1.8's `Worker` hook calls `setParent($parentContext)` by default, so the default is parent-child. Celery: `CeleryInstrumentor().instrument(use_span_links=True)` — 0.65b0 still defaults `use_span_links` to `False`. Rationale, and the payload plumbing, in `kb-observability-conventions` rule 3, `laravel-queues-valkey`, `celery-workers`.
- **The semconv version is pinned in code and bumped deliberately.** Every `Resource.create` carries `schema_url=1.43.0`. Unpinned semconv is a silent rename of every attribute a dashboard queries; the SDK will happily emit the new name against the old panel.
- **Instruments are a closed catalog.** Any instrument reaching `/metrics` that is not in `kb-observability-conventions` fails the CI diff. Auto-instrumentation emits its own metrics — suppress them at the SDK or drop them at the Collector; adding a real one is a catalog PR **first**, then code.
- **Head sampling is 100% everywhere; the Collector decides.** `OTEL_TRACES_SAMPLER=parentbased_always_on` in every service. A head sampler drops the one request someone is investigating, and the drop is irreversible: non-recording spans never reach a processor, so no tail sampler can recover them.
- **No telemetry failure is allowed to fail a request** (§19.6). Exporters are fire-and-forget with a short timeout to a *local* Collector; the Collector is the only component permitted to buffer, retry, or block.

**Not defined here.** Span names, the metric catalog, the label allow-list, log fields, the telemetry-vs-audit split, health-endpoint semantics → `kb-observability-conventions`. Recording rules, alerts, dashboards, retention, Loki labels → `prometheus-grafana-loki-tempo`. Compose services, ports, volumes, networks → `docker-compose-stack`. Error classes → `kb-error-taxonomy`.

## How we use it

### Shared environment — identical keys in all four runtimes, and one value that is not

| Variable | Value | Why |
|---|---|---|
| `OTEL_SERVICE_NAME` | `core-api` \| `ai-api` \| `ai-worker` \| `web` | Must be one of the four values the `service` label is bounded to. Laravel queue workers are `core-api`. |
| `OTEL_RESOURCE_ATTRIBUTES` | `deployment.environment.name=$APP_ENV,service.version=$GIT_SHA` | `deployment.environment.name`, not the long-dead `deployment.environment`. |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | `http://otel-collector:4318` | Same host, private network. Never a remote endpoint — see the PHP-FPM gotcha. |
| `OTEL_EXPORTER_OTLP_PROTOCOL` | `http/protobuf` | Avoids `ext-grpc` in PHP and the grpc-plus-`fork()` deadlock in Celery prefork. |
| `OTEL_EXPORTER_OTLP_TIMEOUT` | `3000` | 10 s default; on PHP-FPM that is 10 s of user-visible latency when the Collector is down. |
| `OTEL_PROPAGATORS` | `tracecontext,baggage` | Doctrine. One service on B3 splits every trace silently. |
| `OTEL_TRACES_SAMPLER` | `parentbased_always_on` | Head 100%; tail sampling in the Collector. |
| `OTEL_METRICS_EXEMPLAR_FILTER` | `trace_based` (Python, browser) · **`with_sampled_trace` (PHP)** | Puts a trace id on histogram buckets, so a latency spike is one click from the trace. **The one key in this table that is not identical across runtimes** — see below. |
| `OTEL_LOGS_EXPORTER` | `none` | Logs go to stdout as JSON and are collected by the Collector's `file_log` receiver — the app never blocks on the log pipeline. |
| `OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT` | **unset** | Tenant data. Asserted at boot. |

#### The exemplar filter is the one value that differs, and getting it wrong is silent

The pinned PHP SDK (`open-telemetry/sdk` 1.15.0) predates the spec's rename. `KnownValues.php:171`
accepts exactly `with_sampled_trace | all | none`, and `MeterProviderFactory.php:64-77` falls through
to `default:` — a `logWarning` and **`NoneExemplarFilter`** — for anything else. So `trace_based` on
PHP does not warn-and-work, it **turns exemplars off for the whole control plane**, while Grafana's
`prometheus.yml` goes on configuring `exemplarTraceIdDestinations` and the panel renders the same
graph without the diamonds. Reproduced by calling the private factory through reflection inside
`knowledgebot/core-api:dev`:

```
trace_based          -> …[warning] Unknown exemplar filter: trace_based …MeterProviderFactory.php(74)
                        OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilter\NoneExemplarFilter
with_sampled_trace   -> OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilter\WithSampledTraceExemplarFilter
```

**The generalisable shape, which is why this is in a skill and not just a comment:** a telemetry SDK
that *recognises* a variable and *rejects* its value degrades to the safe default, and the safe
default for an exemplar filter is *none* — so a typo in an observability setting removes the surface
you would have used to notice it. Both spellings, in one command:
`grep -rn OTEL_METRICS_EXEMPLAR_FILTER infrastructure/docker/env services/*/.env*` — every `core-api`
line must read `with_sampled_trace`, every `ai-service` line `trace_based`. **Recheck on any SDK
bump in either runtime:** the accepted value set is an SDK property, not a specification one.

### The Python bootstrap — FastAPI and Celery share one module

```python
# services/ai-service/app/observability/otel.py
# Called from the FastAPI lifespan (`fastapi-service`) with `app`, and from Celery's
# worker_process_init signal (`celery-workers`) with `app=None, in_worker=True`.
import os
from opentelemetry import trace
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

SEMCONV = Schemas.V1_43_0.value          # the pin. Bumping it is a dashboard migration.

def configure(*, app=None, in_worker: bool = False) -> None:
    # Fail closed. An operator who exports one conversation to Tempo has exported all of them.
    if os.getenv("OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT"):
        raise RuntimeError("GenAI content capture is tenant data — kb-security-baseline")

    resource = Resource.create({"service.name": os.environ["OTEL_SERVICE_NAME"]},
                               schema_url=SEMCONV)
    provider = TracerProvider(resource=resource)   # sampler comes from OTEL_TRACES_SAMPLER
    provider.add_span_processor(BatchSpanProcessor(OTLPSpanExporter()))
    trace.set_tracer_provider(provider)
    set_meter_provider(MeterProvider(resource=resource, metric_readers=[
        PeriodicExportingMetricReader(OTLPMetricExporter())]))

    if app is not None:
        FastAPIInstrumentor.instrument_app(
            app,
            excluded_urls="health/live,health/ready,health/deps,metrics",  # probes swamp real traffic
            # SSE: without this, ASGI emits one `http send` child span PER FRAME —
            # an 800-token answer becomes an 800-span trace Tempo refuses to render.
            exclude_spans=["send", "receive"],
        )
    if in_worker:
        # use_span_links=True makes 0.65b0 call start_span(links=[…]) and skip context
        # attach, i.e. a real new root. Default is False = child of a request that ended.
        # NoOpMeterProvider suppresses `flower.task.runtime.seconds`, which is not in the
        # catalog and would fail the CI /metrics diff.
        CeleryInstrumentor().instrument(use_span_links=True,
                                        meter_provider=NoOpMeterProvider())

    HTTPXClientInstrumentor().instrument()   # Qdrant, object storage, provider calls
    RedisInstrumentor().instrument()
    PsycopgInstrumentor().instrument()
    LoggingInstrumentor().instrument(set_logging_format=False)  # our formatter owns the shape
```

Celery calls this from `worker_process_init`, never at import: `BatchSpanProcessor`'s background
thread does not survive `fork()`, so a provider built in the parent exports nothing (`celery-workers`).

### PHP — Laravel and the queue workers

`ext-opentelemetry` (PECL **1.2.1**) plus `OTEL_PHP_AUTOLOAD_ENABLED=true`; composer requires
`open-telemetry/sdk`, `exporter-otlp`, and the auto packages `-laravel`, **`-guzzle`** (the seam
depends on it), `-pdo`, `-psr18`. Verify with `php -m | grep opentelemetry` in the built image, in
CI — the extension missing emits an `E_USER_WARNING` and instruments nothing.

Streaming needs one hand-written span. `opentelemetry-auto-laravel` hooks `Kernel::handle` and ends
the server span in the `post` callback, which fires when `handle()` *returns the `StreamedResponse`
object* — before a single byte of the body is written. Open `kb.chat.relay` in the controller, end it
in the streamed callback's `finally`, and record `kb_chat_first_token_seconds` there
(`laravel-control-plane` owns the relay itself).

### Browser — `apps/web` only, traces only

`instrumentation-client.ts` (Next ≥ 15.3) runs after document load and before hydration — the only
hook that catches the first navigation. Server side uses `@vercel/otel`'s `registerOTel()` in
`instrumentation.ts`; the Next server does little beyond RSC and proxying, so a hand-rolled Node SDK
buys nothing.

- `DocumentLoadInstrumentation` and `FetchInstrumentation` only, depended on as the **two individual
  packages** — never `@opentelemetry/auto-instrumentations-web`. That meta-package depends on
  `instrumentation-user-interaction` and re-declares its **`zone.js`** peer at its own top level, so
  the peer is unsatisfiable by simply not importing the piece that needs it: with
  `auto-install-peers=false` the install warns forever, and "fixing" it means adding an Angular
  runtime shim that monkey-patches every async primitive into a React 19 concurrent-rendering app.
  **No `user-interaction`**: it spans every click with DOM targets, which triples trace volume and
  answers no on-call question. **No `xml-http-request`**: `apps/web` uses `fetch` only, and the
  browser's built-in SSE client is banned outright.
- **No browser metrics at all.** Per-visitor label cardinality has no bounded value set.
- `propagateTraceHeaderCorsUrls` is our API origin and nothing else — an unscoped regex adds a CORS
  preflight to every third-party request and leaks internal trace ids off-site. It must be an
  **anchored RegExp**, never the origin string: `urlMatches` in `@opentelemetry/core` compares a
  string pattern to the full request URL with `===`, so an origin string matches nothing and the
  browser→Laravel leg of the trace silently stops propagating.
- The exporter posts to a **same-origin** Next route handler that proxies to the Collector. The
  Collector is never published, and CSP stays `connect-src 'self'` (`kb-security-baseline`).
- Never captured: message text, header values, `document.cookie`, query strings, form fields. Set
  `ignoreNetworkEvents: true`, set `measureRequestSize: false` (it reads the request body, which on a
  chat send *is* the user's message), and add no custom attribute carrying user input. **Both
  instrumentations set `url.full` verbatim** — `location.href` for document load, the request URL for
  fetch — so the query string arrives by default; strip it in a span processor's `onEnd` rather than
  in `applyCustomAttributesOnSpan`, which is handed a URL-less `FetchError` on the failure path and
  never runs at all for CORS-preflight child spans.
- **The embedded widget gets no OTel.** It runs on a hostile third-party page; a cross-origin
  exporter is a data-exfiltration surface, and doctrine already forbids returning trace context to
  clients. Correlate with `X-KB-Request-Id` (`iframe-postmessage-bridge`).

### The Collector — yes, even on one host

One `otel-collector` container ships in every deployment including single-host self-hosted. It is not
optional plumbing: tail sampling cannot exist without it, it is the only endpoint a browser may post
to, it is the buffer that stops a Tempo restart from turning into dropped spans inside a PHP request,
and it is where the redaction backstop and the uncatalogued-metric filter live. One container,
~100 MB, versus losing every error trace.

Pipeline order: `otlp` (+ `file_log` for stdout JSON) → `memory_limiter` → `transform` (drop banned
attributes; a backstop, never the defence) → `filter/metrics` (allow `kb_*` plus the enumerated infra
families) → `tail_sampling` → `batch` → `otlp_http/tempo`, the `prometheus` exporter, `otlp_http/loki`.

**Two spelling traps in that sentence, both of which used to be wrong here.** `filelog` and `otlphttp`
are **deprecated aliases** in 0.158.0: they still resolve, so the only symptom is
`warn builders/builders.go:40 "filelog" alias is deprecated` once per named instance, and a component
that will stop resolving on some later release reads as working today. Canonical names come from
`otelcol-contrib components` inside the pinned image, not from documentation. And metrics leave over
the **`prometheus` exporter, which Prometheus scrapes** — never `prometheusremotewrite` and never
Prometheus's OTLP receiver, because the CI metric-catalog diff has to scrape **one** endpoint that has
seen every process's data: under PHP-FPM and Celery prefork each request lands in a different process,
so a per-service `/metrics` returns one worker's fragment and the gate passes while checking almost
nothing.
`tail_sampling` runs **before** `batch`, with a `status_code` ERROR policy, a `latency` policy and a
low `probabilistic` policy as three **top-level** entries — OR'd. `num_traces` must exceed
`traces/sec × decision_wait`. At one replica the `load_balancing` exporter is unnecessary; the moment
there are two it is mandatory, because every span of a trace must reach the same instance. Compose
wiring: `docker-compose-stack`. What the backends then do with it: `prometheus-grafana-loki-tempo`.

### Representing a stream without one span per token

`kb.chat.stream` is **one** INTERNAL span for the whole stream. Per-delta spans and per-delta span
events are both wrong: the SDK caps events at 128 per span (`OTEL_SPAN_EVENT_COUNT_LIMIT`) and drops
the rest into a counter nobody reads. Timing lives in `kb_provider_first_token_seconds`, counts land
as end-of-span attributes, and the span ends in a `finally` with `kb.finish_reason` — including on
`CancelledError` (`kb-observability-conventions`).

### Adding a metric

1. PR to `kb-observability-conventions` — name, type, labels, and what it answers. Names are permanent.
2. Then create the instrument at **module import**, so a freshly booted service exposes it.
3. Somebody scrapes `/metrics` and diffs it against the catalog by hand. This was specified as a CI
   gate and was never wired; there is no CI now, so the catalog is a convention held by review.

## Gotchas

- **A 900-token answer produces a 900-span trace and Tempo times out rendering it.** `opentelemetry-instrumentation-asgi` emits a child `http send` span per ASGI send message, and an SSE frame is one send. Pass `exclude_spans=["send", "receive"]`. The symptom on the metrics side is a Collector `batch` queue that never drains during peak chat.
- **Laravel's chat span reads 3 ms for an answer that took six seconds, and `kb_chat_first_token_seconds` is empty.** The auto-instrumentation ends the server span when `Kernel::handle` returns, which for a `StreamedResponse` is before the body streams. Every SSE endpoint needs its own span closed inside the streaming callback.
- **CI fails on a metric nobody in the repo wrote.** `CeleryInstrumentor` registers `flower.task.runtime.seconds` (name already ends in the unit *and* declares `unit="seconds"`), and the ASGI instrumentation registers `http.server.*`. Neither is in the catalog. Pass `meter_provider=NoOpMeterProvider()` to instrumentors whose metrics you do not want, and keep the Collector `filter/metrics` allow-list as the second line.
- **Celery workers export no spans at all, or the first task after a deploy hangs forever.** Two causes, both fatal and silent. The tracer provider was built at module import, so `BatchSpanProcessor`'s thread was created pre-`fork()` and does not exist in the child. Or the grpc OTLP exporter was used: grpc's background threads plus prefork `fork()` deadlock on the first export. Configure in `worker_process_init`; use `http/protobuf`.
- **Every PHP request slows by seconds the moment the Collector restarts.** PHP has no background threads, so the batch processor flushes inline at request shutdown and the OTLP HTTP call happens on the request's own clock <!-- UNVERIFIED: exact flush point in SDK 1.15.0 not read; the absence of a background thread is not in doubt -->. With the 10 s default timeout, a dead Collector is 10 s added to every page. Keep the endpoint on the same host, set `OTEL_EXPORTER_OTLP_TIMEOUT=3000`, and never point a PHP-FPM service at a remote OTLP endpoint.
- **A PHP attribute constant does not exist, or PHP and Python disagree about the schema.** `open-telemetry/sem-conv` is at **1.38.0** while the repo pin is 1.43.0 — anything added after 1.38.0 has no generated PHP constant and must be a string literal, and the PHP resource's `schema_url` will lag. Keep the literals in one `Attributes` class so the next `sem-conv` release is a single-file change.
- **A blog post's `gen_ai.*` attribute names no longer resolve, and there is no version to pin them to.** `gen_ai.*` left the main semconv repo at v1.42.0 for `open-telemetry/semantic-conventions-genai`, which today has **zero releases, zero tags, and a README whose Schema URL section reads `TODO`**. There is nothing to pin. Pin the *instrumentation package* version instead, record the date you mapped against, and re-check on every SDK bump (`kb-observability-conventions` owns the attribute list).
- **A tenant's question appears in a span and nobody opted in.** The GenAI conventions now define `gen_ai.retrieval.query.text` and `gen_ai.retrieval.documents` — Opt-In, gated by the same env var, and shaped exactly like our RAG stage. Do not set them, and do not hand-roll `kb.query.text` as a substitute: the allow-list is the defence, the Collector `transform` is only the backstop.
- **A subset of clients is missing from Tempo entirely and the sampling rate looks fine.** `parentbased_always_on` honours an inbound `traceparent` ending `-00`, so any client can suppress its own trace. Accepted — it hides only its own traffic, and metrics and access logs still count it — but it means contract tests must send `-01` explicitly, and it is a second reason the widget propagates nothing.
- **The provider sees our internal baggage.** `HTTPXClientInstrumentor` injects both `traceparent` and `baggage` into every outbound request, including calls to OpenAI, Anthropic and OpenRouter. `traceparent` is a random id and is worth sending (NVIDIA NIM adopts it for support correlation, `nvidia-nim-api`); `baggage` is arbitrary key/values from anywhere upstream. Give the provider clients a tracecontext-only propagator.
- **`/health/ready` is 90% of the trace store.** Probes run per replica per interval forever. `excluded_urls` covers the three health paths and `metrics`; forgetting it also poisons the tail sampler's `num_traces` budget with traces you will never read.

## Official docs

- [OTel zero-code PHP](https://opentelemetry.io/docs/zero-code/php/) — PECL extension, `OTEL_PHP_AUTOLOAD_ENABLED`, which `opentelemetry-auto-*` covers which library. [`opentelemetry-php/contrib-auto-laravel`](https://github.com/opentelemetry-php/contrib-auto-laravel) — the `Queue`/`Worker` hooks and the `TracingLinked`/`TracingIsolated`/`TracingParent` contracts.
- [OTel Python instrumentation](https://opentelemetry.io/docs/languages/python/instrumentation/) and [`opentelemetry-python-contrib`](https://github.com/open-telemetry/opentelemetry-python-contrib) — `excluded_urls`, `exclude_spans`, `use_span_links`, and each instrumentor's own metrics.
- [OTel JS browser](https://opentelemetry.io/docs/languages/js/getting-started/browser/) and [Next.js instrumentation](https://nextjs.org/docs/app/guides/instrumentation) + [`instrumentation-client`](https://nextjs.org/docs/app/api-reference/file-conventions/instrumentation-client) — the two hooks and their execution timing.
- [SDK environment variables](https://opentelemetry.io/docs/specs/otel/configuration/sdk-environment-variables/) — the table above is a subset; also the span/attribute limits behind the per-token-event trap.
- [Semantic conventions](https://github.com/open-telemetry/semantic-conventions) (core, pinned at v1.43.0), [GenAI conventions](https://github.com/open-telemetry/semantic-conventions-genai) (unreleased; content-capture opt-in), [Collector tail sampling](https://github.com/open-telemetry/opentelemetry-collector-contrib/tree/main/processor/tailsamplingprocessor) and its [load-balancing exporter](https://github.com/open-telemetry/opentelemetry-collector-contrib/tree/main/exporter/loadbalancingexporter).

## Definition of done

- [ ] A boot test asserts the service refuses to start with `OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT` set to anything; a span fixture asserts no `gen_ai.input.messages`, `gen_ai.output.messages`, `gen_ai.system_instructions`, `gen_ai.retrieval.query.text` or `gen_ai.retrieval.documents` is ever emitted.
- [ ] `Resource` carries `schema_url` 1.43.0 in Python and JS; the PHP literal-attribute class names the same version in a comment. A test fails when the SDK is bumped and the pin is not reviewed.
- [ ] An 800-token SSE response produces **one** `kb.chat.stream` span and no `http send` spans; the count is asserted, not eyeballed.
- [ ] `php -m` in the built image lists `opentelemetry`, and `composer show` lists `opentelemetry-auto-guzzle`; both are checked in CI, not at review.
- [ ] A Celery task's span has a link to the submitter and a different trace id; a Laravel queued job implementing `TracingLinked` does the same. Neither is a child.
- [ ] `/metrics` diff against the catalog is green with all auto-instrumentation active — `flower_task_runtime_seconds` and `http_server_*` are suppressed or filtered, not tolerated.
- [ ] With the Collector container stopped, a chat request still succeeds and its p95 moves by less than the OTLP timeout.
- [ ] The browser bundle exports traces to a same-origin path, exports no metrics, propagates trace headers only to our API origin, and the widget bundle contains no `@opentelemetry/*` code (asserted by the size-limit/bundle check).
