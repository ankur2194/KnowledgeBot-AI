---
name: kb-observability-conventions
description: The naming and propagation contract for traces, metrics, and structured logs across the Laravel, FastAPI, Celery, and TypeScript services. Use whenever adding a span, creating a metric, writing a log line, defining a health endpoint, or naming anything a dashboard, alert, or PromQL query will reference. Owns the single-trace requirement, the metric catalog, the label allow-list, and the telemetry-versus-audit split. Pairs with kb-error-taxonomy (supplies error_class) and opentelemetry-instrumentation (SDK wiring).
---

# Observability Conventions — Traces, Metrics, Logs

Twelve implementation agents, three languages, one namespace. OpenTelemetry SDKs in PHP (Laravel), Python (FastAPI + Celery) and TypeScript (Next.js); OTLP into an OpenTelemetry Collector, then Tempo, Prometheus, Loki. Naming decided ad hoc is naming that cannot be dashboarded.
**Authoritative spec:** docs/15-observability.md §20, docs/06-architecture.md §11.2, docs/13-security.md §18.2/§18.11, docs/14-reliability.md §19.6, docs/17-testing-performance.md §23

## Non-negotiables

- **One user request is one trace, from the browser to the database write** (§20.1). Twelve hops — client, Laravel validation, FastAPI call, rewriting, dense, sparse, fusion, rerank, context build, provider, streaming, DB writes — share one trace id. A trace that breaks at the PHP→Python seam leaves two half-traces and neither can answer "why was this answer slow", which is the only question the trace exists to answer.
- **No metric label may take an unbounded set of values.** `org_id`, `bot_id`, `user_id`, `conversation_id`, `message_id`, `source_id`, `job_id`, `chunk_id`, `url`, and any user question, query, or filename are **banned as labels** — no exceptions, no "just for this dashboard". Per-tenant numbers come from PostgreSQL aggregates (§8.22 analytics, the `usage` table); per-request detail comes from traces and logs. Metrics answer "is the system healthy", never "what did org X do".
- **Metric and span names are permanent** — they compile into recording rules, alerts, dashboards and runbooks, so renaming one is a migration with a dual-write window, not an edit. Get the name right in the catalog before the first `create_counter` call.
- **Telemetry and audit logs are different systems and never share a store.** Telemetry is sampled, TTL'd, and may fail silently (§19.6). Audit is complete, append-only, transactional, and its write failure fails the operation (§18.11). Conflating them destroys both — see below.
- **Logs carry no raw API key, no password, no full `Authorization` or `X-KB-Signature` header, and no unredacted user content** (§20.3, §18.2). Enforced by a field **allow-list** at the logger, not a regex scrubber downstream. The scrubber is the backstop; the allow-list is the defence.
- **First-token latency is measured from Laravel's receipt of the client request to the first `token` event flushed to the client.** Any other start point flatters the number and hides the 1.5 s retrieval budget (§23).

**Not defined here.** OTel SDK setup, exporters, auto-instrumentation, samplers, Collector pipelines → `opentelemetry-instrumentation`. Deploying and configuring Prometheus/Grafana/Loki/Tempo → `prometheus-grafana-loki-tempo`. The 18 `error_class` values and their retry/fallback/page policy → `kb-error-taxonomy` (this skill only says where the field goes). What an audit entry must capture → `kb-security-baseline`. The header table that physically carries `traceparent` → `kb-internal-api-contracts`.

## How we use it

### The single trace — span names and shape

Spans we create are `kb.<domain>.<operation>`, lowercase, dot-delimited, **no identifiers in the name**
(`kb.retrieval.dense`, never `kb.retrieval.dense org_01J…`; ids are attributes). HTTP and DB spans keep their stable semconv names; LLM spans keep the semconv `{operation} {model}` form.
**Span domains are a closed list too, and deliberately not the metric domains below:** `chat`, `query`,
`retrieval`, `context`, `prompt`, `usage`, `ingestion`, `vector` — the union of the tree below and its
ingestion mirror. Spans follow the request through stages that emit no metric of their own, so the two
lists are different on purpose; never reconcile them into one.

```
POST /api/v1/chat/{conversation}/messages       SERVER    Laravel — trace starts here
├─ kb.chat.authorize → kb.chat.config_snapshot  INTERNAL  identity/org/bot/quota, then resolve
├─ kb.chat.relay                                INTERNAL  the Laravel→FastAPI relay hop. The auto
│  │                                                      server span ends when handle() returns the
│  │                                                      StreamedResponse, before a byte of body —
│  │                                                      this one ends in the stream's finally
│  └─ POST /internal/v1/chat/stream             CLIENT    Laravel → FastAPI, injects traceparent
│     └─ POST /internal/v1/chat/stream          SERVER    FastAPI, remote parent — the seam
│        ├─ kb.chat.pipeline                    INTERNAL  the 20 stages of docs/07 §12.1
│        │  ├─ kb.query.normalize → chat {rewrite_model}  (gen_ai, only if rewriting is on)
│        │  ├─ kb.retrieval.filters             INTERNAL  the four mandatory terms
│        │  ├─ embeddings {embed_model}         CLIENT    gen_ai
│        │  ├─ kb.retrieval.dense / .sparse     CLIENT    concurrent siblings
│        │  └─ kb.retrieval.fuse / .dedupe / .rerank / .threshold → kb.context.pack → kb.prompt.build
│        ├─ chat {model}                        CLIENT    gen_ai — the provider call
│        │  └─ kb.chat.stream                   INTERNAL  first token → terminal event
│        └─ kb.usage.record
└─ kb.chat.finalize                             INTERNAL  message row + usage row + analytics
```

Ingestion mirrors it: `kb.ingestion.job` root, children `kb.ingestion.<stage>` reusing the metric
`stage` values (`acquire` … `invalidate`), plus `kb.vector.upsert` / `kb.vector.verify`.
**Attributes:** stable semconv where it exists (`error.type`, `http.*`, `db.*`); for LLM work
`gen_ai.operation.name`, `gen_ai.provider.name`, `gen_ai.request.model`, `gen_ai.response.model`,
`gen_ai.usage.input_tokens`/`output_tokens`, `gen_ai.conversation.id`; ours under `kb.*` — `kb.org_id`,
`kb.bot_id`, `kb.request_id`, `kb.operation`, `kb.config_version`, `kb.error_class`, `kb.finish_reason`,
`kb.evidence_count`, `kb.degraded`. **`gen_ai.*` is unstable** (Gotchas) — worth it on spans because
every vendor trace UI keys off it and remapping an attribute is cheap; not worth it in a metric name,
which is why our metrics are `kb_*`.

### Context propagation — the four rules that keep the trace whole

1. **W3C Trace Context only, injected by the client middleware and never by hand.** `OTEL_PROPAGATORS=tracecontext,baggage` in every service, and the propagator registered once on Laravel's `Http` client factory. One service on B3, or a hand-built Guzzle client inside a service class, and the header is never read — the trace splits silently, because a failed extraction is not an error in any SDK, it is a new root span.
2. **The sampling decision is made once, at Laravel's ingress, and travels in the `traceparent` flags.** Downstream samples `ParentBased(root=…)` and never re-decides. Two independent decisions produce half-traces at exactly the rate you configured.
3. **Queue hops carry `traceparent` in the job payload and start a NEW ROOT with a span link** to the submitter — not a child. The submitting request already ended; a child arriving forty minutes later cannot be sampled with its parent. Applies to every Celery task and Laravel queued job.
4. **SSE streams do not carry trace context to the client, and stream spans end in `finally`.** No `traceparent` response header to a widget on a customer's site — it leaks internal topology. Correlate client-side by echoing `X-KB-Request-Id` instead.

```python
# services/ai-service/app/observability/chat.py — module-level singletons, dotted suffix-free names.
PROVIDER_TTFT = meter.create_histogram("kb.provider.first_token", unit="s")
CHAT_REQUESTS = meter.create_counter("kb.chat.requests")

async def stream_answer(req, ctx):
    # The SERVER span already exists: ASGI instrumentation extracted the remote parent from
    # traceparent. This is the provider child, named per the gen_ai convention.
    with tracer.start_as_current_span(f"chat {ctx.model}", kind=trace.SpanKind.CLIENT) as span:
        span.set_attributes({"gen_ai.operation.name": "chat", "gen_ai.request.model": ctx.model,
                             "gen_ai.provider.name": ctx.provider,  # NOT gen_ai.system — renamed
                             "kb.org_id": ctx.org_id,               # attribute, never a label
                             "kb.operation": ctx.operation})
        outcome, error_class, started, first = "success", "none", time.monotonic(), None
        try:
            async for chunk in provider.stream(req):
                if first is None and chunk.text:    # the first chunk is a role/empty delta on
                    first = time.monotonic()        # most providers — timing it under-reports
                    PROVIDER_TTFT.record(first - started, {"provider": ctx.provider,
                                                          "model": ctx.model})
                yield chunk
        except asyncio.CancelledError:              # client hung up; never swallow — must unwind
            outcome, error_class = "cancelled", "user_cancellation"; raise
        except KbError as exc:
            outcome, error_class = "error", exc.error_class
            span.set_attribute("error.type", exc.error_class)   # stable semconv attribute
            span.set_status(trace.Status(trace.StatusCode.ERROR)); raise
        finally:   # a cancelled stream never reaches the happy path, and an unended span is
            span.set_attributes({"kb.error_class": error_class,     # never exported — the request
                                 "kb.finish_reason": outcome})      # you are chasing just vanishes
            CHAT_REQUESTS.add(1, {"operation": ctx.operation, "outcome": outcome,
                                  "error_class": error_class})   # cancelled is an outcome, not an error
```

### Metric naming and the label allow-list

`kb_<domain>_<thing>_<unit>` for gauges and histograms, `kb_<domain>_<thing>_total` for counters.
Domains: `chat`, `retrieval`, `provider`, `ingestion`, `embedding`, `vector`, `crawl`, `internal`.

- **Base units always — `_seconds`, `_bytes`; counters end `_total` and count events, never rates.** No `_ms`, no `_mb`, no `_percent`: a proportion is `_ratio` in 0–1 (Prometheus's own base unit), and "pages per minute" and "error rate" are `rate()` expressions. A stored rate cannot be re-windowed or summed.
- **Declare the unit on the instrument; never write it into the name.** `kb.chat.first_token` + `unit="s"` exports as `kb_chat_first_token_seconds`. The OTLP→Prometheus rule only *SHOULD* skip a suffix the name already ends with, so writing it yourself is how you get `…_seconds_seconds`. Names stay underscore-style even though Prometheus 3.x accepts dotted UTF-8 — Grafana and client-library support still lag.
- **One metric name, one label set, one owning service** — otherwise `sum by (…)` silently drops half the data and PromQL does not warn.

**The allow-list is the union of every `Extra labels` column in the catalog, and the two are one
artifact** — adding a label to a metric means adding it here in the same change, or the allow-list
unit test at the instrument wrapper fails. The list is closed *and* it is a bounded-cardinality rule:
**no label may carry a tenant id, a user id, a URL, a query string, or free text**, and that outranks
the list — a proposed label whose values cannot be enumerated is refused, not appended.

Permitted labels, and nothing else: `service` (4) · `env` (3) · `operation` (`X-KB-Operation`, ~12) ·
`outcome` (`success` `error` `timeout` `cancelled`, plus `skipped` on the scheduler family only) ·
`disposition` (a per-family result set that is deliberately *not* the shared four) · `error_class`
(18 + `none`, from `kb-error-taxonomy`) · `provider` (5 adapters) · `model` / `from_model` /
`to_model` · `token_type` (`input` `output`) · `finish_reason` (the SSE terminal enum,
`kb-internal-api-contracts`) · `stage` (17 ingestion + 7 retrieval, code-defined) · `reason` (two
disjoint closed enums — retrieval 3, crawl 8) · `kind` (`dense` `sparse`) · `engine` (the code-defined
OCR set, `ocr-pipeline`) · `file_type` (supported formats, else `other`) · `queue` (the 9 named
queues, never a connection name) · `collection` (one per embedding model) · `task` (the ~6
code-defined scheduled-task names) · `status_class` (`2xx` `3xx` `4xx` `5xx` `network`) ·
`dependency` (the fixed 8) · `required` (`true`/`false`) · `version`, `git_sha`, `contract_version`
(**`kb_build_info` only** — the `_info` pattern, one series per running build).

**`model` comes from the pinned model catalog; an unrecognised string maps to `other`** — a tenant can
type anything into a provider connection, so the value is bounded only if you bound it, and the fold
happens before the instrument, not in PromQL. `git_sha` is the one permitted churning value, which is
why it is confined to a single always-`1` gauge. Budget: no family exceeds **10,000 series**; a
histogram costs `buckets + 2` series per label combination.

**`outcome` has four values and only one is excluded from the error rate.** `timeout` is separate from
`error` because `kb-error-taxonomy` has no dedicated timeout class — timeouts arrive as
`provider_temporary` or `internal_dependency` — so this label is the only way to answer §20.2's
timeout-rate bullet without splitting a class. The consequence is a trap: **the error rate is
`outcome=~"error|timeout"`, never `outcome="error"`**, which silently omits every timeout. `cancelled`
is the only outcome legitimately outside the error rate, because a user hanging up is not a failure of
ours. Write the recording rule once and let alerts reference it rather than re-deriving the matcher.

**A family whose results are not those four names its label `disposition`, never `outcome`.** A crawl
page is `discovered` / `changed` / `unchanged` / `skipped` / `missing` / `failed`; an OCR page is
`success` / `partial` / `error`. Overloading `outcome` there is not a naming nit — it drops the family
out of `outcome=~"error|timeout"` (Gotchas). `disposition` is opt-out-by-name: the family declares it
has its own vocabulary and therefore needs its own rule.

**`skipped` is the fifth `outcome` value and exists only on `kb_internal_scheduler_tasks_total`** — why
it is outside the error rate yet still needs its own alert: `references/metric-catalog.md`.

### The catalog, the log shape, and the health endpoints

**Every `kb_*` family — type, labels, and the question it answers — is in
`references/metric-catalog.md`**, one file so no row drifts out of sight of the allow-list above;
every family carries `service` and `env` implicitly. **`references/logs-health-audit.md`** holds the
other three contracts, which share one rule (telemetry is lossy, audit is not): the required and
forbidden fields on every JSON log line (`org_id` belongs in a log and never in a label; prompts,
chunks and credentials belong in neither), the store/completeness/write-coupling table that keeps
`audit_logs` out of Loki and telemetry out of PostgreSQL, and the liveness-vs-readiness matrix (zero
dependency I/O on `/health/live`, a 5 s cached check on `/health/ready`, optional-dependency breakers
degraded but ready).

## Gotchas

- **Prometheus memory climbs for hours after a "harmless" label was added, then the pod OOMs — or the metrics for that job disappear entirely.** Someone put `org_id` on `kb_chat_requests_total` for a per-tenant dashboard. Series count is the *product* of every label's cardinality times replicas, and a histogram multiplies that again by `buckets + 2`: 800 orgs on an 11-bucket histogram is ~10k series from one metric, at roughly 2 GiB of head RAM per million series. Prometheus has no per-tenant series cap, so the only guardrail is `sample_limit` — and that is a cliff, not a throttle: exceed it and **the entire scrape is failed**, so one bad deploy deletes the job's metrics rather than degrading them. It does not recover on revert either; the series sit in the head block for the retention window. Churning labels (`conversation_id`, `request_id`) are strictly worse than large-but-static ones, because every value is seen once and never receives a second sample. Per-tenant numbers come from PostgreSQL.
- **FastAPI's work appears in Tempo as its own root trace, seconds away from the Laravel one.** Extraction failed, and no SDK raises on a missing or malformed `traceparent` — it starts a new root and everything looks healthy. In this stack, in order of likelihood: `opentelemetry-auto-laravel` **does not instrument the HTTP client** — `Http::post()` propagates only because `opentelemetry-auto-guzzle` hooks `GuzzleHttp\Client::transfer()`, so if that package is missing every internal call is unparented; the PECL `opentelemetry` extension is not loaded or `OTEL_PHP_AUTOLOAD_ENABLED` is unset, which emits an `E_USER_WARNING` nobody reads and instruments nothing; `OTEL_PROPAGATORS` differs across services (PHP silently degrades to a noop propagator, Python raises at import — so the PHP side is the one that fails quietly); or the header is uppercase hex, which Python's `traceparent` regex rejects without a word. Assert it in the contract test: the FastAPI server span's trace id must *equal* the Laravel client span's, not merely "a trace exists on both sides".
- **The trace is intact but sampling-derived rates are wrong, and the vendor's service map is missing edges.** A proxy or WAF header allow-list permits `traceparent` and drops `tracestate`. W3C makes forwarding it mandatory, and OTel packs consistent-probability sampling state (`ot=th:…;rv:…`) into it — lose that and downstream sampling decisions diverge and span-count extrapolation silently produces the wrong request rate. Allow-list both headers, everywhere.
- **First-token latency reads 900 ms on the dashboard and users say the bot takes six seconds.** Two independent bugs, usually both present. It was timed from the provider call, so the retrieval leg (§23 budgets 1.5 s for it alone) is invisible; and it was timed on the provider's first streamed chunk, which carries no text — Anthropic opens with `message_start`, `content_block_start` (`text: ""`) and possibly a `ping` before the first `content_block_delta`, and OpenAI's first chunk is an empty role delta. NVIDIA's benchmark rule is the right one: discard chunks with no content, because TTFT computed on an empty first response is meaningless. OTel encodes the same concession by naming the server metric `time_to_first_*token*` and the client metric `time_to_first_*chunk*`. Measure from the Laravel SERVER span start to the flush of the first `token` event with non-empty text; on a reasoning model decide explicitly whether a `thinking_delta` counts, and never let `kb_provider_first_token_seconds` be the SLO number.
- **Exactly the traces you need are the ones missing.** Head sampling at 10% drops 90% of your errors too, and the decision is a one-way door: `ParentBased` maps a `traceparent` ending `-00` to `AlwaysOff`, non-recording spans never reach a span processor, and a tail sampler cannot decide about spans it never receives. Sample head at 100% at the Laravel edge and let the Collector's `tail_sampling` processor decide — a `status_code` ERROR policy, a `latency` policy and a low `probabilistic` policy as three **top-level** entries, which are OR'd; wrapping them in `and` gives you 5% of your errors, the exact bug you were fixing. Two configuration traps: `num_traces` defaults to 50000 while the buffer needs `traces/sec × decision_wait` (30 s by default), so watch `otelcol_processor_tail_sampling_sampling_trace_dropped_too_early`; and every span of a trace must reach one Collector instance, so a **`load_balancing`** exporter (renamed from `loadbalancing`) keyed on trace id sits in front.
- **A support log line contains a customer's contract text.** Someone logged the packed prompt at DEBUG to debug grounding. That payload is retrieved tenant content plus the user's question — the most sensitive object in the system — and it landed in a store with no per-tenant access control and long retention. The same trap arrives free from auto-instrumentation: OTel GenAI's `gen_ai.input.messages`/`gen_ai.output.messages` are opt-in and must stay off (`OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT` unset — the name OTel Python contrib uses, though semconv only cites it as a non-normative example <!-- UNVERIFIED: confirm against the SDK version you pin -->), or every conversation is shipped to Tempo. Allow-list the fields; treat the scrubber as a backstop that will miss the case you did not imagine.
- **The chat request is missing from Tempo entirely, and it is the one that failed — meanwhile the pod's memory creeps up.** Spans export only on `end()`, and the streaming span was ended on the happy path only. The ASGI instrumentation will not save you: it ends the server span on the final `http.response.body` message and has no `http.disconnect` handling at all, so an SSE generator that swallows the disconnect and keeps looping leaks the span and its context token for the life of the process. Use `start_span` plus an explicit `finally` — not a context manager wrapped around a generator, whose exit depends on GC — record `finish_reason` there, and re-raise `CancelledError` rather than swallowing it (`kb-internal-api-contracts`).
- **An ingestion job's spans are dropped, or attached to an HTTP request that ended forty minutes earlier.** `opentelemetry-instrumentation-celery` still defaults to making the task a **child** of the submitting span; links are opt-in via `CeleryInstrumentor().instrument(use_span_links=True)`. With the default, the parent closed at `202`, Tempo already flushed the trace (`max_trace_idle` 5 s, `max_trace_live` 30 s) and the tail sampler's `decision_wait` expired long before the job's spans arrived. Set `use_span_links=True`, carry `traceparent` in the job payload, start a new root, link the submitter.
- **The error-rate panel reads healthy through a crawl outage in which a third of pages fail.** A family reused `outcome` for a result set that is not the shared four — `kb_crawl_pages_total` counting `failed` and `missing`, OCR counting `partial` — so every one of those samples falls outside `outcome=~"error|timeout"` and the error rate under-reports by exactly the traffic that failed. Nothing errors and nothing looks wrong: the matcher is valid PromQL, the series exist, the sum is simply smaller, and the family drops out of the one rule that was supposed to catch it. Any family whose results are not `success`/`error`/`timeout`/`cancelled` names its label **`disposition`** and carries its own recording rule; CI asserts every emitted `outcome` value is in the closed set (five, counting `skipped` on the scheduler family).
- **PromQL returns half the expected value, or the alert never fires at all — with no error either way.** Three variants of one root cause. Two services export one metric name with different label sets, so `sum by (operation)` drops the series lacking the label. A histogram named `kb_x_seconds` already produces `kb_x_seconds_count`, so your own counter with that name collides and one silently wins. And an instrument created inside the request handler, or a name written with its unit suffix that the exporter then suffixes again, yields `/metrics` output no query in the repo matches. One name, one owner, one label set; CI scrapes each service's `/metrics` and diffs the name set against the catalog — that test is what makes the catalog a contract rather than a document.
- **A Qdrant blip takes every FastAPI replica out of the load balancer at once and a partial degradation becomes a total outage.** Readiness flapped in lockstep because all replicas poll the same shared dependency on the same interval. Give it a `failureThreshold` that outlives a normal blip, cache the check 5 s, and keep liveness free of dependency I/O — otherwise a failed readiness escalates into a fleet-wide restart loop.
- **The GenAI attribute names copied from a blog post are already dead.** `gen_ai.*` is **Development/experimental — nothing in it is stable**, and it has churned hard: `gen_ai.system` → `gen_ai.provider.name`, `gen_ai.usage.prompt_tokens`/`completion_tokens` → `input_tokens`/`output_tokens`, and in semconv **v1.42.0 (June 2026) the whole `gen_ai.*` namespace was deprecated in the main repo and moved to `open-telemetry/semantic-conventions-genai`**, which as of v1.43.0 has no releases and no schema URL. Hence: `gen_ai.*` for span attributes (one adapter module, cheap to remap, every vendor UI reads them), `kb_*` for every metric name (compiled into alerts, expensive to remap). Pin the semconv version you mapped against and re-check on every SDK bump.

## Official docs

- [W3C Trace Context](https://www.w3.org/TR/trace-context/) — `traceparent`/`tracestate` format and the sampled flag that carries the head decision across the seam. [OpenTelemetry semantic conventions](https://opentelemetry.io/docs/specs/semconv/) — per-domain stability; HTTP and database metrics are Stable, messaging is not.
- [OpenTelemetry GenAI semantic conventions](https://github.com/open-telemetry/semantic-conventions-genai) — `gen_ai.*` spans, attributes, metrics and content-capture opt-in. All Development status; moved out of the main repo at semconv v1.42.0.
- [Prometheus — metric and label naming](https://prometheus.io/docs/practices/naming/) (base units, `_total`, `_info`, why a name must not encode a dimension), [instrumentation practices](https://prometheus.io/docs/practices/instrumentation/) and [histograms & summaries](https://prometheus.io/docs/practices/histograms/) — cardinality guidance and bucket cost.
- [OTel Collector — tail sampling processor](https://github.com/open-telemetry/opentelemetry-collector-contrib/tree/main/processor/tailsamplingprocessor) + [load-balancing exporter](https://github.com/open-telemetry/opentelemetry-collector-contrib/tree/main/exporter/loadbalancingexporter) — the pairing that keeps error traces; policy list, `decision_wait`/`num_traces`, and the one-instance-per-trace requirement.
- [OTel — zero-code PHP instrumentation](https://opentelemetry.io/docs/zero-code/php/) — the PECL extension, `OTEL_PHP_AUTOLOAD_ENABLED`, and which `opentelemetry-auto-*` package covers which library.

## Definition of done

- [ ] An end-to-end test asserts **one** trace id spans the Laravel server span, the FastAPI server span, the retrieval spans, the provider span and the finalizing DB write — and that the FastAPI span's parent is the Laravel client span.
- [ ] Every new metric is in the catalog before it is in code; the CI catalog diff is green. **That diff scrapes the OTel Collector's `prometheus` exporter, never a service's own `/metrics`** — transport is OTLP push everywhere, and under PHP-FPM and Celery prefork each request lands in a different process, so a per-service endpoint returns one worker's fragment and the gate passes while checking almost nothing (`github-actions-pipeline`, `opentelemetry-instrumentation`). Counters end `_total`, durations end `_seconds`, proportions end `_ratio` in 0–1, and no name contains `ms`, `percent` or `rate`.
- [ ] A unit test asserts the allow-list at the instrument wrapper **and** that the allow-list is exactly the union of the `Extra labels` column in `references/metric-catalog.md` — the two drift apart silently otherwise. No banned label exists anywhere, and every `outcome` value emitted is in the closed set; a family with a richer result set uses `disposition`.
- [ ] Every streaming and job span ends in a `finally`; aborting a stream mid-flight still exports the span with `kb.finish_reason="cancelled"`. Celery and Laravel queued jobs start a new root with a span link, never a child of the submitter.
- [ ] Sampling is head-100% with tail sampling in the Collector (`load_balancing` exporter in front); a test asserts a 5xx trace survives while a routine 200 is dropped. `opentelemetry-auto-guzzle` is installed and Celery runs with `use_span_links=True`.
- [ ] A log fixture containing a known API key, password, prompt and retrieved chunk asserts none reach any sink; `OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT` is unset in every environment.
- [ ] `/health/live` makes zero network calls (asserted with all dependencies blackholed); `/health/ready` fails for required dependencies only, and an open reranker breaker leaves it ready.
- [ ] Audit entries are written inside the operation's transaction to `audit_logs`, never to the log pipeline; a test asserts an audit write failure aborts the operation.
