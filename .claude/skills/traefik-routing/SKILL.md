---
name: traefik-routing
description: Traefik v3 as the only public edge for KnowledgeBot AI — the four public hostnames, ACME issuance, the settings that keep an SSE answer streaming, and the two latches that keep ai-api and Horizon unroutable. Use whenever editing infrastructure/docker/traefik/, adding a router, middleware or certificate, or when a stream buffers only in production or a container turns out reachable by Host header. Compose services and networks are docker-compose-stack. Pairs with kb-architecture-map (what may be routed at all).
---

# Traefik Routing — the public edge

Traefik **v3.7.x** (pin the minor, e.g. `traefik:v3.7`; latest patch at time of writing `v3.7.10`, 2026-07-31), Docker provider, Compose. Config surface is v3 — **a v2 recipe silently mis-parses here** (see Gotchas).
**Authoritative spec:** docs/18-deployment-backup-cicd.md §24.1–24.4, docs/06-architecture.md §10.2 §11.1, docs/13-security.md §18.1 §18.5, docs/22-spec-findings-and-decisions.md defect 17

## Non-negotiables

- **`ai-api`, every `ai-worker-*`, and Horizon's dashboard have no route from the edge — enforced by two independent latches, not by omission.** `providers.docker.exposedByDefault: false` *and* a `constraints` expression requiring an explicit `kb.edge=true` label. One latch is not enough: `traefik.enable=true` is the most copy-pasted line in the ecosystem, and the moment `ai-api` carries it every authorization, quota and credential check in Laravel has a bypass (`kb-architecture-map`, `kb-internal-api-contracts`). Horizon renders every tenant's job payloads side by side (docs/22), so it is the same class of breach with a nicer UI.
- **Four hostnames, three of them separate origins by security requirement.** `app.<domain>` (admin), `chat.<domain>` (hosted chat), `<widget-domain>` (a **separate registrable domain**), plus `api.<domain>` for Laravel. Hosted chat renders model-generated Markdown — the highest-risk sink in the product — so sharing an origin with the admin lets one XSS there run with the admin session cookie attached; and a shared *parent* domain puts the wildcard-scoped admin cookie on the widget origin, so an iframe on a customer page would carry a real admin credential (docs/22 defect 17, `laravel-sanctum-auth`). Collapsing any of these is a security regression, not a simplification.
- **Nothing at this layer may buffer or bound `text/event-stream`.** Traefik does not buffer by default; every SSE failure here is something someone added. The exact set is `compress` without an exclusion, the `buffering` middleware, and a non-zero `respondingTimeouts.writeTimeout` — see *Streaming*.
- **Rate limiting stays in Laravel.** It keys on organization, bot, session **and** IP together (`laravel-sanctum-auth`, `valkey-keyspaces`); Traefik's `rateLimit` defaults to the remote address alone, which throttles one NAT'd office for one abuser and is trivially evaded from a second IP. The edge limits **bytes, not identities** — a request-body cap on the upload route and nothing else.
- **Security headers, CSP and `frame-ancestors` are not Traefik's.** The widget's `frame-ancestors` is derived per bot from a live allow-list on every request, so it cannot be a static `headers` middleware; it is emitted by Laravel (`kb-security-baseline` → `references/widget-embedding-and-output.md`). That is why the widget domain has two backends, below.
- **The access log is a telemetry sink with no per-tenant access control.** It inherits `kb-observability-conventions`' rule: a field allow-list at the writer, not a scrubber downstream. `RequestPath` is `url.String()` — it **includes the query string** — so it is dropped, not kept.

## How we use it

| Host | Backend | Notes |
|---|---|---|
| `api.<domain>` | `laravel-api`, and the chat-stream paths to `laravel-api-stream` | the only door inward; `/internal/*` and `/horizon` blocked at the edge |
| `app.<domain>` | `web` | admin SPA |
| `chat.<domain>` | `web` | hosted chat — same container, deliberately different origin |
| `<widget-domain>` | `sdk` | loader + static iframe assets, separate registrable domain |
| `<widget-domain>/embed/*` | `laravel-api` | the iframe **document**, because its `frame-ancestors` is per-bot and dynamic |

Compose services, networks and healthchecks are `docker-compose-stack`; only `traefik` publishes 80/443 (§24.1, §24.4). Traefik must join `edge` only — a Traefik on the `application` network can reach `ai-api` regardless of labels.

### `infrastructure/docker/traefik/traefik.yaml` (static configuration)

```yaml
# Traefik v3.7. Every option below is v3 spelling; see Gotchas for the v2 words that look right.
entryPoints:
  web:
    address: ":80"
    asDefault: false                      # never rely on the implicit default — bind routers explicitly
    http:
      redirections:
        entryPoint: { to: websecure, scheme: https, permanent: true }
    # Port 80 stays OPEN — HTTP-01 is answered here, ahead of the redirect. Firewalling it "because
    # everything is HTTPS" breaks renewal ~30 days later, not today.
  websecure:
    address: ":443"
    asDefault: false
    http:
      tls: { certResolver: le }
    transport:
      respondingTimeouts:
        readTimeout: 60s                  # request read only; chat bodies arrive instantly
        writeTimeout: 0s                  # 0 = no deadline. MUST stay 0 — see Gotcha 3
        idleTimeout: 180s                 # keep-alive BETWEEN requests; does not touch a live stream
      lifeCycle:
        graceTimeOut: 120s                # a Traefik restart cuts in-flight streams at this value
    forwardedHeaders:
      trustedIPs: []                      # empty = trust nobody. Populate ONLY when behind another proxy

providers:
  docker:
    exposedByDefault: false               # latch 1
    constraints: "Label(`kb.edge`,`true`)" # latch 2 — an explicit opt-in traefik.enable cannot fake
    network: edge

certificatesResolvers:
  le:
    acme:
      email: "${ACME_EMAIL}"
      storage: /acme/acme.json            # NAMED VOLUME, not a bind mount — see Gotcha 5
      httpChallenge: { entryPoint: web }  # HTTP-01 issues all four hostnames incl. the widget domain
      # caServer: https://acme-staging-v02.api.letsencrypt.org/directory   # uncomment while iterating

accessLog:
  format: json
  fields:
    defaultMode: drop                     # allow-list, not deny-list
    # ClientHost is the edge's only abuse signal; retention belongs to the log stack. RequestPath is
    # deliberately absent — it carries the query string, so one GET with ?q=<question> puts tenant text
    # in Loki. "What was the request" is Laravel's field-allow-listed log (kb-observability-conventions).
    names: { StartUTC: keep, Duration: keep, RouterName: keep, ServiceName: keep, ClientHost: keep,
             RequestMethod: keep, RequestHost: keep, OriginStatus: keep, DownstreamStatus: keep }
    headers:
      defaultMode: drop                   # Authorization and X-KB-Signature must never be logged
      names: { traceparent: keep }        # correlates the edge line to the app trace
log: { level: INFO, format: json }
# `api:` is absent on purpose — the dashboard maps the whole internal topology, and `api.insecure`
# (port 8080) is never set. Same rule as Horizon.
```

### Router labels (on services `docker-compose-stack` defines)

Every routed service carries `kb.edge: "true"` **and** `traefik.enable: "true"`; every router carries `…entrypoints: websecure` and `…tls.certresolver: le`. Both pairs are shown once and elided after — eliding them in real configuration is Gotchas 1 and 4.

```yaml
laravel-api:
  labels:
    kb.edge: "true"                       # required by `constraints`; traefik.enable alone cannot fake it
    traefik.enable: "true"
    traefik.http.services.api.loadbalancer.server.port: "8080"
    traefik.http.routers.api.rule: "Host(`api.${DOMAIN}`)"
    traefik.http.routers.api.entrypoints: websecure
    traefik.http.routers.api.tls.certresolver: le
    traefik.http.routers.api.middlewares: "compress-safe@docker"
    traefik.http.middlewares.compress-safe.compress.excludedcontenttypes: "text/event-stream"
    # Nothing legitimate reaches /internal/* or /horizon via the edge — FastAPI calls Laravel over
    # `application`, Horizon is operator-only. Explicit priority so a rule edit cannot reorder these.
    traefik.http.routers.api-denied.rule: "Host(`api.${DOMAIN}`) && (PathPrefix(`/internal`) || PathPrefix(`/horizon`))"
    traefik.http.routers.api-denied.priority: "1000"
    traefik.http.routers.api-denied.middlewares: "ops-only@docker"
    traefik.http.middlewares.ops-only.ipallowlist.sourcerange: "127.0.0.1/32"  # v3 name; v2 was ipwhitelist
    # The edge enforces BYTES, not identities; Laravel enforces the same cap again (kb-security-baseline
    # → file-upload-safety.md). `buffering` is on THIS router only — never on a streaming route.
    traefik.http.routers.api-upload.rule: "Host(`api.${DOMAIN}`) && PathPrefix(`/api/v1/sources/upload`)"
    traefik.http.routers.api-upload.priority: "900"
    traefik.http.routers.api-upload.middlewares: "upload-cap@docker"
    traefik.http.middlewares.upload-cap.buffering.maxrequestbodybytes: "104857600"

laravel-api-stream:                       # its own FPM pool: one stream pins one child (docker-compose-stack)
  labels:
    traefik.http.services.stream.loadbalancer.server.port: "8080"
    # PathRegexp, because v3 removed `{id}` placeholders from PathPrefix (Gotcha 2).
    traefik.http.routers.stream.rule: "Host(`api.${DOMAIN}`) && PathRegexp(`^/api/v1/chat/[^/]+/messages$`)"
    traefik.http.routers.stream.priority: "950"
    # No middlewares at all. This is the one router serving text/event-stream.

web:                                      # one container, two deliberately separate origins
  labels:
    traefik.http.services.web.loadbalancer.server.port: "3000"
    traefik.http.routers.admin.rule: "Host(`app.${DOMAIN}`)"
    traefik.http.routers.admin.middlewares: "compress-safe@docker"
    traefik.http.routers.hostedchat.rule: "Host(`chat.${DOMAIN}`)"
    traefik.http.routers.hostedchat.middlewares: "compress-safe@docker"

sdk:
  labels:
    traefik.http.services.sdk.loadbalancer.server.port: "8080"
    traefik.http.routers.widget.rule: "Host(`${WIDGET_DOMAIN}`)"     # separate REGISTRABLE domain
    traefik.http.routers.widget.priority: "10"
    traefik.http.routers.widget.middlewares: "compress-safe@docker"
    # The iframe DOCUMENT is dynamic — its CSP frame-ancestors is derived per bot from the live
    # allow-list — so it is neither a static file nor a Traefik `headers` middleware. Laravel serves it.
    traefik.http.routers.widget-embed.rule: "Host(`${WIDGET_DOMAIN}`) && PathPrefix(`/embed`)"
    traefik.http.routers.widget-embed.priority: "100"
    traefik.http.routers.widget-embed.service: "api@docker"
# ai-api, every ai-worker-*, laravel-worker (Horizon) and every data service: `kb.edge: "false"`,
# no traefik.enable, no `ports:` — in the base file and every overlay (docker-compose-stack, Gotcha 1).
```

### Streaming — what is safe and what is not

`text/event-stream` passes through untouched **by default**. Traefik's proxy uses Go's `httputil.ReverseProxy`, and the docs are explicit: *"The `FlushInterval` is ignored when ReverseProxy recognizes a response as a streaming response; for such responses, writes are flushed to the client immediately."* So the `responseForwarding.flushInterval` default of `100ms` is **not** the bug, and setting it to `-1` is the cargo-cult fix that changes nothing. Three settings actually break it, all opt-in:

| Setting | What it does to a stream |
|---|---|
| `compress` with no `excludedContentTypes` | withholds the head of the stream — `minResponseBodyBytes` is `1024`, so nothing ships until a kilobyte exists, then it arrives compressed and chunk-buffered |
| `buffering` (`maxResponseBodyBytes` / `memResponseBodyBytes`) | reads the entire response before forwarding — the answer lands in one blob at the end, or `500` past the cap |
| `respondingTimeouts.writeTimeout` ≠ `0s` | Go sets the write deadline **once**, covering the whole response, so every answer is cut at exactly that wall-clock with no error line anywhere |

Timeouts that do **not** cut a stream, contrary to widespread belief: `idleTimeout` (keep-alive idle *between* requests — an in-flight response is not idle) and an active-service `healthCheck` failure (Traefik stops sending *new* requests to the server; established connections are untouched). What does cut one is `lifeCycle.graceTimeOut`, at its `10s` default, when Traefik itself is restarted — hence `120s` above. Heartbeats are still mandatory, but for reasons that live one layer up: PHP only detects a departed client on a failed write, and any proxy a self-hoster owns has its own idle timer (`kb-internal-api-contracts`).

### Behind an existing reverse proxy (self-hosters)

Give Traefik the `web` entrypoint only, drop `certificatesResolvers` and the `websecure` TLS block, terminate TLS upstream, then — in order of how often each is missed:

1. `entryPoints.web.forwardedHeaders.trustedIPs: ["<upstream CIDR>"]`. Without it Traefik rewrites `X-Forwarded-Proto` to `http`, Laravel builds `http://` URLs, `Secure` cookies are refused, and the admin login loops with no error. **Never `insecure: true`** — that trusts a client-supplied `X-Forwarded-For` from anyone on the internet.
2. `ipStrategy.depth` on `ops-only` (and any future `ipAllowList`), or every request presents the upstream proxy's address and the allow-list matches for the whole internet or for nobody.
3. Turn buffering off upstream, per proxy: nginx `proxy_buffering off` plus `proxy_read_timeout` well above the longest answer; Apache `mod_proxy` `flushpackets=on`; Caddy needs nothing. Cloudflare's free tier buffers and enforces a 100 s origin-response window — put the API host on grey cloud (DNS-only). Certificate issuance and renewal move upstream with TLS, and port 80 must stay reachable there.

## Gotchas

- **A container with no Traefik labels and no published port is reachable from the internet with one `curl -H 'Host: …'`.** The Docker provider's `exposedByDefault` is `true` and its `defaultRule` is ``Host(`{{ normalize .Name }}`)``, so with defaults every container gets a router keyed on its own name. There is no DNS record and nothing looks wrong in `docker compose ps`; a spoofed `Host` header against the public IP is the entire attack, and it lands on `ai-api`, which trusts its caller by design. Set `exposedByDefault: false` **and** the `kb.edge` constraint, then prove it: `curl -sk -H 'Host: ai-api' https://<public-ip>/internal/v1/health` must not return 200.
- **A v2 config file loads without complaint and half of it does nothing.** v3 renamed and removed real surface: `IPWhiteList` → `IPAllowList`; `Headers`/`HeadersRegexp` matchers → `Header`/`HeaderRegexp`; `HostHeader` removed (use `Host`); `PathPrefix` is no longer a regex and `{id}`-style placeholders are gone (use `PathRegexp`); the Headers middleware lost `sslRedirect`, `sslTemporaryRedirect`, `sslHost`, `sslForceHost`, `featurePolicy`; `StripPrefix` lost `forceSlash`; Docker lost Swarm support (separate `swarm` provider) and `tls.caOptional`; `experimental.http3` moved onto the entrypoint. A dropped middleware option is not an error — it is a security control that stopped existing. `core.defaultRuleSyntax: v2` exists as a temporary bridge; do not use it here, it hides exactly the mismatch you need to see.
- **Streaming works locally and arrives as one blob in production, or every answer truncates at the same number of seconds.** Blob = `compress` without `excludedContentTypes: text/event-stream`, or a `buffering` middleware on the chat router. Identical truncation = a non-zero `writeTimeout`. Neither logs anything: the compressor is behaving correctly and the write deadline closes the connection cleanly, so the client sees a normal end-of-stream and renders a short answer as complete. The test must assert inter-event wall-clock gaps through the real edge, not the final body (`kb-internal-api-contracts`).
- **A router that was matching yesterday stops matching after an unrelated rule edit — or answers on an entrypoint nobody assigned it.** Two implicit defaults. Precedence is rule **length** (*"the priority is directly equal to the length of the rule"*), so adding `&& Method(\`POST\`)` to one router can push it ahead of another that was winning; set `priority` explicitly on every router sharing a host, here `api`/`api-denied`/`api-upload` and `widget`/`widget-embed`. And a router with no `entrypoints` option attaches to **every** entrypoint unless some entrypoint is marked `asDefault: true`, so it can end up answering on `:80` unredirected; set `entrypoints` on every router and leave `asDefault: false` everywhere.
- **Every request is served with the "TRAEFIK DEFAULT CERT" self-signed certificate and the ACME log is empty.** The `acme.json` path was bind-mounted from a host file that did not exist, so Docker created a **directory** there and the store can never be written. Use a named volume. Traefik does not refuse to start over this — it writes with `0600` and does not audit the path — so the only symptom is browser warnings. Related: **issuance then stops with a rate-limit error that has nothing to do with the real fault.** Let's Encrypt allows *"up to 5 authorization failures per identifier ... every hour"*, so a Compose restart loop against a misconfigured DNS record exhausts that budget in minutes and every message after it describes the wrong problem. Also 50 certificates per registered domain per 7 days and 5 per identical identifier set per 7 days — the widget's separate registrable domain gets its own budget, which is one small upside of the split. Iterate against `caServer: …acme-staging-v02…`, then remove the line **and delete `acme.json`**, or the staging account persists and every certificate stays untrusted.
- **An access log line contains a customer's question, or a tenant identifier.** `RequestPath` is built from `urlCopy.String()` and therefore carries the query string; the access log ships to Loki, which has no per-tenant access control and long retention (`kb-observability-conventions`). Dropping the field is the fix, not a regex scrubber. The same line also carries `Authorization` and `X-KB-Signature` if `fields.headers.defaultMode` is `keep`.
- **An edge rate limit throttles a whole customer.** `rateLimit`'s default `sourceCriterion` is *"the request's remote address field (as an `ipStrategy`)"*, so one abuser behind an office NAT rate-limits their colleagues, and the same abuser on a phone hotspot is unaffected. The composite bot+origin+session+IP limiter in Laravel is the control (`laravel-sanctum-auth`); do not add a second, weaker one here that fires first and produces a `429` with no `error_class` for the client to branch on (`kb-error-taxonomy`). `inFlightReq` has the same defect and additionally caps concurrent chats per IP, which is exactly what a shared office should not hit.
- **Traefik is a root-equivalent process on the public internet.** It reads `/var/run/docker.sock`, and write access to that socket is host root. Mount it `:ro` (which limits nothing about the API but blocks socket replacement) and prefer a read-only socket proxy exposing only container/event endpoints. Traefik's own dashboard is the same class of exposure: it enumerates every router, service and middleware, which is a map of the internal topology.
- **CORS on the SDK routes is intermittently wrong for one tenant.** Traefik OSS has no HTTP cache, so it cannot be the cause — but any CDN a self-hoster puts in front will merge per-origin `Access-Control-Allow-Origin` responses unless `Vary: Origin` is present, which Laravel sets (`kb-security-baseline`). Verify the header survives the edge rather than adding a Traefik `headers` middleware to re-add it.

## Official docs

- [Traefik v3 docs root](https://doc.traefik.io/traefik/) (pin the version selector to your tag — v2 pages rank higher in search and are wrong here), [migrating v2 → v3](https://doc.traefik.io/traefik/migrate/v2-to-v3/) and [the detailed change list](https://doc.traefik.io/traefik/migrate/v2-to-v3-details/) behind Gotcha 2.
- [EntryPoints reference](https://doc.traefik.io/traefik/reference/install-configuration/entrypoints/) — `respondingTimeouts`, `lifeCycle.graceTimeOut`, `forwardedHeaders`, `http.redirections`, `asDefault`, with defaults.
- [Docker provider](https://doc.traefik.io/traefik/reference/install-configuration/providers/docker/) — `exposedByDefault`, `defaultRule`, `constraints`, `network`. [Routers: rules and priority](https://doc.traefik.io/traefik/reference/routing-configuration/http/routing/rules-and-priority/) — the v3 matcher set and length-based precedence. [Service load balancing](https://doc.traefik.io/traefik/reference/routing-configuration/http/load-balancing/service/) — `responseForwarding.flushInterval` and the streaming-response exemption.
- [Compress](https://doc.traefik.io/traefik/reference/routing-configuration/http/middlewares/compress/), [Buffering](https://doc.traefik.io/traefik/reference/routing-configuration/http/middlewares/buffering/), [RateLimit](https://doc.traefik.io/traefik/reference/routing-configuration/http/middlewares/ratelimit/) — the three middlewares that break streaming or tenancy fairness.
- [ACME certificate resolvers](https://doc.traefik.io/traefik/reference/install-configuration/tls/certificate-resolvers/acme/) and [Let's Encrypt rate limits](https://letsencrypt.org/docs/rate-limits/) — challenge types, the wildcard/DNS-01 constraint, the failure budgets in Gotcha 5. [Access logs](https://doc.traefik.io/traefik/reference/install-configuration/observability/logs-and-accesslogs/) — field and header modes, filters, the field-name catalog.

## Definition of done

- [ ] `docker compose config` shows `kb.edge: "false"` (never `"true"`), no `traefik.enable=true` and no `ports:` on `ai-api`, any `ai-worker-*`, or any data service, in the base file or any override.
- [ ] `curl -sk -H 'Host: ai-api' https://<public-ip>/` and the same with the container's generated name both fail to reach FastAPI; `https://api.<domain>/internal/v1/health` and `…/horizon` both return `403` from the edge, independently of Laravel's `viewHorizon` gate.
- [ ] All four hostnames resolve, serve a Let's Encrypt certificate (not `TRAEFIK DEFAULT CERT`), and `acme.json` lives on a named volume; the widget hostname is on a **different registrable domain** than `app.`/`chat.`/`api.`, asserted by a test that compares eTLD+1.
- [ ] An SSE integration test through the **real edge** asserts inter-event wall-clock gaps and a >120 s total stream; repeat with `compress-safe` swapped for a bare `compress` and confirm it fails (that proves the exclusion is what is working). `respondingTimeouts.writeTimeout` is `0s`, `lifeCycle.graceTimeOut` ≥ the p99 answer duration, and no `buffering` middleware is attached to any router serving `text/event-stream`.
- [ ] Every router sets `entrypoints` explicitly; every router sharing a host with another sets `priority` explicitly; the chat-stream router carries no middlewares at all.
- [ ] A request carrying `?q=secret&org=01J…` produces an access log line containing neither; `fields.headers.defaultMode` is `drop` and a fixture asserts no `Authorization`/`X-KB-Signature` value reaches the log.
- [ ] No `rateLimit` or `inFlightReq` middleware exists on any API or chat router; the only edge limit is `upload-cap`, and Laravel enforces the same byte limit independently.
- [ ] The Traefik API/dashboard is not enabled, `api.insecure` is unset, and the Docker socket is mounted read-only or fronted by a socket proxy. Config is v3 spelling throughout: `grep -riE 'ipwhitelist|sslredirect|forceslash|hostheader|Headers\(|experimental\.http3|swarmMode' infrastructure/docker/` is empty, and `core.defaultRuleSyntax` is unset.
