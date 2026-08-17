# Structured logs, the telemetry/audit split, and health endpoints

Companion to `kb-observability-conventions/SKILL.md`, which owns the rules these sections mechanise.
Field names below are as permanent as metric and span names: a log field a Loki query or an alert
runbook references cannot be renamed without breaking both.

## Structured logs

One JSON object per line on stdout, RFC3339 UTC. **Required on every line:** `timestamp`, `severity`,
`service`, `env`, `trace_id`, `span_id`, `request_id`, `operation`. **When applicable:** `org_id`,
`bot_id`, `job_id`, `error_class`, `duration_ms`. `org_id` and `bot_id` are opaque ULIDs and belong in
logs — that is what makes a tenant incident debuggable; §20.3's "where safe" means *not as metric
labels* and *not shipped outside the DPA*, not "omit them". Durations are `duration_ms` in logs and
seconds in metrics; the asymmetry is deliberate. **Never logged, at any level, in any environment:**
provider API keys, passwords, `Authorization` and `X-KB-Signature` values, session tokens and cookies,
the user's question, retrieved chunk text, the assembled prompt, model output, file contents. Evaluation
content capture goes to the eval store behind the org's privacy switch (§18.10) — never to Loki, which has no per-tenant access control.

### Where the allow-list lives, and how it is extended

The rule in `SKILL.md` — *enforced by a field allow-list at the logger, not a regex scrubber
downstream* — is implemented per runtime, and the Python one is
`services/ai-service/app/observability/logging.py:ALLOWED_EXTRA_FIELDS`. A key passed in
`extra=` that is not in it is **dropped**, and only its *name* is echoed back in a
`dropped_fields` array so the author sees it did not ship. The never-log list above is enforced
by **exclusion**: enumerating forbidden names is a denylist, and a denylist only catches the
cases somebody imagined.

The list is closed and has exactly three buckets. Extending it means editing this file in the
same change, the same way adding a metric label does:

1. the *when applicable* names above;
2. **the metric label allow-list** from `SKILL.md`. Every value in it is bounded by
   construction — that is why it is allowed to be a label — so it is safe in a log payload, and
   reusing the closed list avoids a second vocabulary for the same facts;
3. log-only diagnostics no metric label can express. Today: `attempt`, `count`, `errors`,
   `in_worker`, `instrumented_app`. Each is there because a call site needs it.

Note what bucket 1 does **not** contain, and therefore what a log line may not carry:
`source_id`, `conversation_id`, `message_id`, `chunk_id`, `user_id`, `url`. `org_id`, `bot_id`
and `job_id` are blessed for logs; the rest are not, and admitting one is a change to this file
rather than to a code constant.

**A provider credential is not on that allow-list under any name, and the allow-list is why that
survives a wire change.** The internal request body's credential field became a **map** keyed by
`connection_id` (`kb-internal-api-contracts`, docs/22 finding F12), so a rule phrased as "the field
named `provider_credential` never reaches a log" now covers nothing, while a closed allow-list of
permitted field names covers the map's values for free — a credential cannot be logged because it is
not an admitted name, not because a filter recognised it. The `connection_id` **keys** are ordinary
ULIDs and are not secret; they are also not in bucket 1, so they are not loggable as a field either.

Redaction of the *message string* is a **backstop and is documented as one** — see
`REDACTION_LIMITS` in that module. It catches credential-shaped tokens, `Authorization` /
`X-KB-Signature` / `KB1` values and URL query strings; it cannot catch tenant content or model
output interpolated into a message, because nothing in a formatter can tell prose from prose. Do not
promote it to the guarantee: a filter that has to *recognise* a value has already had that value in
a string, and unwrapping a credential map into a plain `dict[str, str]` before interpolating it is
exactly the shape that defeats a token-shaped matcher.

**A VALUE is not a message, and the `key=value` rule does not transfer to one.** An
`audit_logs.details` value is redacted by `KbJsonFormatter::redactValue()`
(`VALUE_REDACTION_LIMITS`), never by `redact()`: the field name is already the caller's map key, so a
`key=value` *inside* the value is incidental text, and a rule whose whole discriminating power is a
key name therefore fires on registrable email addresses — `=` is legal `atext` in a dot-atom, so
`token=abc@example.com` passes `email:rfc,strict` (measured) and used to strip the only identifier a
failed-login audit row has, since that row carries `organization_id = NULL` and `actor_id = NULL` by
design. The message rule set stays a strict **superset** of the value rule set (scheme values, `KB1`,
the pinned vendor prefixes, URL query strings — the shapes a credential has *itself*), and the
argument for every rule in and out is on that method rather than in this file.

The `severity` field is what the Collector's `file_log` `severity_parser` reads to derive Loki's
`level` stream label; a service that spells it `level` or `level_name` produces log lines whose
`level` label is absent and whose level-faceted panels read zero.

## Telemetry vs audit

| | Telemetry (traces, metrics, logs) | Audit log |
|---|---|---|
| Store / lifetime | Tempo, Prometheus, Loki; retention window, then gone | PostgreSQL `audit_logs`, append-only, outlives the record it describes |
| Completeness | **Sampled and lossy by design** | **Every event, never sampled** |
| Write coupling | Fire-and-forget; failure degrades silently (§19.6) | In the operation's transaction; failure fails the operation |
| Reader | On-call, mid-incident | Compliance, the tenant, an investigator months later |

Put audit rows in Loki and sampling plus retention delete the compliance record silently — you find out
when someone asks who deleted a source in March. Put telemetry in `audit_logs` and the audit table
becomes the hottest write path in the system, unqueryable exactly when it is needed. What audit
captures: `kb-security-baseline`.

## Liveness vs readiness

| Service | `/health/live` — process only, **zero dependency I/O** | `/health/ready` — required deps | Degraded, does **not** fail readiness |
|---|---|---|---|
| Laravel `core-api` | PHP-FPM answers | PostgreSQL, Valkey | FastAPI, object storage, any provider |
| FastAPI `ai-api` | event loop responsive | Qdrant, Valkey, PostgreSQL | object storage, and **every** provider call — embedding, reranking, chat |
| Celery / queue worker | heartbeat file touched within 2× the longest poll | broker reachable, `celery inspect ping` | object storage, OCR engine, every provider call |

**The `ai-api` required set is code, not a list to maintain here** — read it with
`grep -n 'checks: dict\[str, bool\]' services/ai-service/app/api/health.py`, which is the one
line naming the whole set. Two corrections that row has already needed: `embedding model loaded`
left with the model (ADR-030 — embedding is a provider call, and by the rule below a provider call
is exactly what readiness must not test), and PostgreSQL was **missing** from the probe until
2026-08-11, so an unreachable source of truth was reported by nothing at all.

**The rule that decides which column a dependency goes in is who the failure belongs to, not how
many replicas go down.** A provider outage is one tenant's credential and the container can still
serve everyone else, so it is degraded — and probing it would pull every replica out of the edge at
once, with nothing able to put them back (Traefik drops an unhealthy container; Compose's `restart:`
reacts to a process exiting, not to a failing healthcheck). An unreachable source of truth means no
request on any replica can be served, so it is required, and pulling them all out is the correct
answer rather than the feared one.

**Required means one real round trip per probe interval, never a presence check.** Every client
built in `lifespan` connects lazily — `AsyncQdrantClient` and `redis.asyncio` both do, and
`AsyncConnectionPool.open()` returns before its first connection exists (measured: 0.00 s against
TEST-NET-3 with `pool_size=2`, `pool_available=0`, because `pool_size` counts slots being filled,
not live connections) — so a container whose dependency is unreachable holds a perfectly
well-formed client object, and `is not None` reports green while every request fails. Each
dependency gets its own key in the 503 body so a red probe names the failing one.

Readiness caches its dependency check for 5 s — uncached, probe interval × replica count is a
self-inflicted load test on PostgreSQL. The checks run **concurrently**, so adding one does not
multiply the worst case past the healthcheck `timeout`. `/health/deps` returns per-dependency
detail and is **admin-authenticated**; it maps the topology. Optional-dependency breakers report
degraded (§19.3) and never fail readiness (`kb-error-taxonomy`).

