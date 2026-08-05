---
name: crawl4ai-crawler
description: Website crawling for KnowledgeBot AI — the guarded fetch, sitemap and link discovery, politeness, JS-render policy, conservative HTML-to-Markdown extraction, and recrawl change detection in services/ai-service/app/crawl/. Use whenever fetching a tenant-supplied URL, configuring Crawl4AI, tuning the crawl queue, or debugging a page that came back empty, gutted, or re-versioned nightly. Crawl4AI never fetches for us and its content filters never touch indexed text. Pairs with kb-security-baseline (the SSRF envelope this implements).
---

# Website Crawling — Crawl4AI, Guarded

Crawl4AI **0.9.2** (2026, library mode only — the Docker API server is never deployed), driven from Celery's `crawl` queue. Every network byte is fetched by **httpx** under our own validator; CPython **≥ 3.12.4** (`kb-security-baseline`). Playwright browsers installed with `crawl4ai-setup` at image build. <!-- UNVERIFIED: exact Playwright version range crawl4ai 0.9.2 pins -->
**Authoritative spec:** docs/03-functional-knowledge-sources.md §8.12 §8.13 §8.14, docs/05-tech-stack.md §9.11, docs/08-ingestion-pipeline.md §13, docs/09-chunking.md §14.5, docs/13-security.md §18.8, docs/15-observability.md §20.2

## Non-negotiables

- **A tenant URL is never passed to `crawler.arun()`.** We resolve, validate, pin, and fetch it ourselves; Crawl4AI only ever sees `raw:<html>` we already hold. Handing it a URL hands DNS, redirects, and the scheme allow-list to a library whose 2026 advisory list includes local-file inclusion via `file://` and SSRF filter bypass via IPv6 transition forms. The seven-step pipeline is `kb-security-baseline` → `references/ssrf-and-crawling.md`; this skill implements it, never restates it.
- **No content filter ever produces indexed text.** `PruningContentFilter` decomposes `nav`/`footer`/`header`/`aside`/`form` outright and scores `<a>`/`<strong>` at weight 0. §14.5 requires conservative removal; a filtered page loses exactly the disclaimers, pricing caveats, and policy links the bot exists to cite. We index `raw_markdown` and **label** chrome instead of deleting it (`kb-chunking-rules`).
- **Extraction removes nothing that the chunker cannot put back.** Every dropped block is a `removed_block` record with its text, so a removal is auditable and reversible. Removal decisions need cross-page frequency, which a single page-fetch does not have — so the crawler contributes the *structural* half of the signal and defers the verdict.
- **The crawl worker has no route to the internal data network** and its egress denies private ranges (`kb-architecture-map`, `kb-security-baseline`). Application validation covers code we wrote; a headless browser's subresource fetches are issued by Chromium and never consult our validator.
- **A failed or partial crawl run marks nothing missing.** The missing-page *policy* is `kb-deletion-and-verification` §8.14; the counter mechanics and the run-level circuit breaker below are ours. One 503 must never disable a site.
- **Hash normalized content, never raw HTML** (`kb-source-lifecycle`). CSRF tokens, render timestamps, and "N users online" make every page look changed every night.

## How we use it

### What Crawl4AI is, and is not, allowed to do

| Job | Owner | Why |
|---|---|---|
| Fetching bytes, DNS, redirects, TLS | **our httpx client** | the validator must sit between resolve and connect |
| robots.txt, `Crawl-delay`, sitemap discovery | **ours** (`urllib.robotparser`, `lxml`) over the guarded fetch | `check_robots_txt=True` makes Crawl4AI fetch robots itself, unvalidated, into a local SQLite cache |
| Concurrency, politeness, backoff | **Celery `crawl` queue + a Valkey token bucket** | `MemoryAdaptiveDispatcher`/`RateLimiter` are in-process; we run 8 processes per container across N containers |
| Response caching | **PostgreSQL `source_versions`** | Crawl4AI's cache is a per-container SQLite file — a cache hit on one worker is a miss on the other seven |
| HTML → Markdown normalization | **Crawl4AI** | html2text tuning, table/list handling, link reference generation — real value, tedious to reimplement |
| JS rendering, scroll/lazy-load | **Crawl4AI (Playwright)** | browser lifecycle, `scan_full_page`, `wait_for` — the other real value |
| Content filtering / `fit_markdown` | **nobody** | see Non-negotiables |

**The verdict on the tool:** keep it, demoted to a normalizer plus a Playwright driver. The alternative — driving Playwright directly plus trafilatura or jusText — does not solve the problem that motivates it, because trafilatura hard-deletes `<footer>`/`<aside>`/`<nav>` by tag in `MANUALLY_CLEANED` before any scoring (no `favor_recall` recovers them) and jusText marks sub-70-character and high-link-density blocks bad and treats document edges as bad. All three extractors delete in the same place; switching libraries buys nothing and costs the browser lifecycle. Crawl4AI's *library* API (`AsyncWebCrawler`, `BrowserConfig`, `CrawlerRunConfig`) survived the 0.9.0 break unchanged — every breaking change in 0.9.0 was scoped to the Docker API server we do not run, as were nine of its ten 2026 advisories.

### The guarded fetch, and conservative extraction

```python
# services/ai-service/app/crawl/fetch.py
import asyncio, ipaddress, socket
from urllib.parse import urljoin, urlsplit, urlunsplit
import httpx
from crawl4ai import AsyncWebCrawler, CacheMode, CrawlerRunConfig
from crawl4ai.markdown_generation_strategy import DefaultMarkdownGenerator

ALLOWED_SCHEMES = frozenset({"http", "https"})
ALLOWED_TYPES   = frozenset({"text/html", "application/xhtml+xml", "text/xml", "application/xml", "text/plain"})
MAX_BYTES, MAX_HOPS, WALL_CLOCK = 10 << 20, 5, 20.0     # §20.2 tracks fetch latency against the 20 s budget

def _validate_all_records(host: str, port: int) -> str:
    # One resolution, every record checked, and we connect to a member of THAT set — closing the
    # two-lookup DNS-rebinding gap. A public A record beside a private AAAA is an attack, not a typo.
    addrs = [i[4][0] for i in socket.getaddrinfo(host, port, type=socket.SOCK_STREAM)]
    if not addrs:
        raise CrawlRejected("dns")
    for a in addrs:
        ip = ipaddress.ip_address(a)
        if (m := getattr(ip, "ipv4_mapped", None)) is not None:
            ip = m                     # ::ffff:169.254.169.254 must be judged as IPv4
        if not ip.is_global:           # NOT `is_private` — 100.64.1.1 is False for both (CVE-2024-4032)
            raise CrawlRejected("private_ip")
    return addrs[0]

async def guarded_get(client: httpx.AsyncClient, url: str) -> "FetchResult":
    for _ in range(MAX_HOPS + 1):
        p = urlsplit(url)
        if p.scheme not in ALLOWED_SCHEMES:   raise CrawlRejected("scheme")
        if p.username or p.password:          raise CrawlRejected("credentials")
        if not p.hostname:                    raise CrawlRejected("dns")   # one of the catalog's eight
        port = p.port or (443 if p.scheme == "https" else 80)
        ip = _validate_all_records(p.hostname, port)
        literal = f"[{ip}]" if ":" in ip else ip
        req = client.build_request(
            "GET", urlunsplit((p.scheme, f"{literal}:{port}", p.path or "/", p.query, "")),
            headers={"Host": p.netloc, "User-Agent": CRAWLER_UA},
            # The certificate must still be validated against the NAME. Pinning without this
            # trades SSRF for a MITM; httpx's `sni_hostname` extension is the seam.
            extensions={"sni_hostname": p.hostname},
        )
        r = await client.send(req, stream=True, follow_redirects=False)   # the client must never follow
        if r.is_redirect:
            await r.aclose()
            url = urljoin(url, r.headers.get("location", ""))   # resolve against the NAME url, not the pinned one
            continue                                            # ...and re-run every check above
        media = r.headers.get("content-type", "").split(";")[0].strip().lower()
        if media not in ALLOWED_TYPES:
            await r.aclose(); raise CrawlRejected("content_type")
        body, n = bytearray(), 0
        async for chunk in r.aiter_bytes():        # DECOMPRESSED bytes: a 1 MB gzip body is 10 GB of RAM
            n += len(chunk)
            if n > MAX_BYTES:
                await r.aclose(); raise CrawlRejected("size")
            body += chunk
        await r.aclose()
        return FetchResult(url, r.status_code, r.headers, bytes(body))
    raise CrawlRejected("redirect")

CONSERVATIVE = CrawlerRunConfig(
    cache_mode=CacheMode.BYPASS,          # PostgreSQL owns freshness; the SQLite cache is per-container
    markdown_generator=DefaultMarkdownGenerator(
        content_filter=None,              # the decision this whole skill exists for
        content_source="cleaned_html",    # strips script/style/meta/link/noscript ONLY — footers survive
        options={"ignore_links": False, "ignore_images": False, "body_width": 0},
    ),
    excluded_tags=[],                     # NOT ["nav","footer","aside"] — that is where the disclaimer lives
    word_count_threshold=1, exclude_external_links=False,
    check_robots_txt=False, verbose=False,
)

async def extract(crawler: AsyncWebCrawler, html: str) -> "ExtractedPage":
    res = await crawler.arun(url="raw:" + html, config=CONSERVATIVE)   # `raw:` = no navigation, no DNS
    return ExtractedPage(
        markdown=res.markdown.raw_markdown,                  # never .fit_markdown (it is "" here anyway)
        # Structural chrome, LABELLED not removed. kb-chunking-rules' boilerplate pass needs
        # "high-frequency AND structurally chrome"; this supplies the second half.
        chrome_blocks=chrome_text_blocks(res.cleaned_html),  # lxml: nav, footer, header, aside subtrees
    )

client = httpx.AsyncClient(follow_redirects=False, http2=False,
                           timeout=httpx.Timeout(connect=5.0, read=10.0, write=5.0, pool=5.0))
page = await asyncio.wait_for(guarded_get(client, url), timeout=WALL_CLOCK)  # per-hop timeouts ≠ wall clock
```

### Discovery, politeness, and the JS-render decision

Discovery is ours: fetch `/robots.txt` through `guarded_get`, take its `Sitemap:` lines plus the configured sitemap URL, parse sitemap indexes recursively (cap nesting at 3 and total URLs at the source's max-page count), then same-domain BFS to the configured depth for sources without a sitemap. Apply include/exclude globs, canonicalize (`<link rel=canonical>`, trailing slash, `utf-8` case, strip the configured tracking-parameter set), and fold duplicates onto one `source_item`. Crawl4AI's `AsyncUrlSeeder` and `BFSDeepCrawlStrategy` are not used — both fetch on their own.

Politeness is a Valkey token bucket keyed `crawl:rl:{org_id}:{registrable_domain}`, refilled at `min(configured_delay, robots Crawl-delay)`, taken before the fetch and released on the failure path. Per-org concurrency is a second bucket so one tenant's 50 000-URL sitemap cannot starve the queue.

**JS rendering is opt-in per source and costs ~20× a plain fetch** in wall clock and ~100× in memory. Default off. Auto-escalate exactly once per item: if the fetched HTML yields under `JS_ESCALATE_MIN_WORDS` (300) of markdown *and* the source has JS rendering approved, re-run through the browser and record `render_mode` on the version. Never escalate on a source that has not been approved — that is the difference between fetching a document and executing a tenant-supplied program in our network.

### Browser lifecycle in the Celery child

One `AsyncWebCrawler` **per prefork child**, created lazily on first use inside the child, never in the parent — a Playwright/Chromium handle created before `fork()` is unusable in the child. Bounded by `worker_max_tasks_per_child=100` on the `crawl` queue (`celery-workers`), so a leaked context lives at most 100 tasks. A browser per *task* OOMs the container at `-c 8`; a browser shared across children cannot exist, because they are separate processes.

Each child owns **one persistent event loop** created in `worker_process_init` and driven with `loop.run_until_complete()`. `asyncio.run()` closes the loop it created, and the crawler's Playwright objects are bound to it.

### Recrawl change detection and the missing-page counter

Per §8.14: re-read the URL set → conditional `GET` with stored `If-None-Match` / `If-Modified-Since` → on 304, skip → otherwise normalize, hash, compare, and create a version only on a differing hash. Store `etag` and `last_modified` on `source_items`, not on the version. In practice ETags are absent or rotate per-response on most CMS-backed sites, so **the normalized-content hash is the real gate and conditional headers are the optimization** — a recrawl that trusts headers alone both misses changes and re-versions unchanged pages.

Counter mechanics (policy: `kb-deletion-and-verification` §8.14):

- Increment `source_items.missing_count` **only** when the run reached a successful terminal state and the item was neither rediscovered nor conditionally confirmed. A 5xx, a timeout, or a `CrawlRejected` on an item is a fetch *error* — the counter does not move.
- Any rediscovery — presence in the URL set, a 200, or a 304 — resets `missing_count` to 0 in the same transaction. "Consecutive" must mean consecutive.
- **Run-level circuit breaker:** compute the missing set for the whole run first; if `len(missing) / len(known_items) > CRAWL_MISSING_FRACTION_MAX` (0.20), fail the run with `kb_crawl_runs_total{outcome="error"}`, apply no policy, and touch no counter. A truncated sitemap or a site behind a maintenance page otherwise disables every page at once.
- A page that 301s to another known item is a *canonical fold*, not a missing page: retire the redirecting item against the target and leave both counters alone.

Metrics are `kb_crawl_*` from `kb-observability-conventions` → `references/metric-catalog.md`; emit `kb_crawl_pages_total{disposition}` once per item per run — `disposition`, **not** `outcome`, because the six per-page results are not the shared four-value outcome enum and an `outcome` label would hide `failed` and `missing` from the global error-rate matcher — and `kb_crawl_robots_blocked_total{reason}` on every `CrawlRejected`. Run-level success stays `kb_crawl_runs_total{outcome}`, which *is* the shared enum. **Never label with the host or URL** — a tenant-controlled target set is an unbounded label.

## Gotchas

- **Every crawled page indexes as an empty string and the source publishes zero chunks.** `DefaultMarkdownGenerator` with no `content_filter` sets `fit_markdown = ""` — not a copy of `raw_markdown`. Code written against a tutorial that read `result.markdown.fit_markdown` silently indexes nothing, and verification passes because zero chunks were expected. Read `raw_markdown`; assert non-empty before hashing.
- **The bot cannot quote a disclaimer that is visibly on every page.** `PruningContentFilter._remove_unwanted_tags()` decomposes `nav`, `footer`, `header`, `aside`, `form`, `iframe` before scoring, and its `tag_weights` dict has no entry for `a` or `strong` — they score 0 and vanish *mid-sentence*, leaving grammatical, gutted prose (issue #582). `BM25ContentFilter` is worse: it needs a query, and ingestion has none. `LLMContentFilter` is non-deterministic, so the same page hashes differently on each recrawl. All three are banned from the indexed path.
- **`excluded_tags=["nav","footer","aside"]` is in every tutorial and deletes the legal text by hand.** Crawl4AI's own `cleaned_html` removes only `script`, `style`, `link`, `meta`, `noscript` — the footer survives *until you configure it away*. Leave `excluded_tags` empty and let `kb-chunking-rules` decide with cross-page frequency.
- **The SSRF suite is green and the crawler still reads `169.254.169.254`.** Either `follow_redirects=True` was left on the httpx client, or the URL reached `crawler.arun()` and Chromium resolved it. Only the first is fixable in Python; the second is why the crawl worker's network is separate and its egress denies private ranges.
- **A crawled page probes Qdrant from inside the container.** Once a page is rendered, its `<img>`, `<iframe>`, `fetch()`, and `<link>` requests come from the browser process and never touch the validator. `page.route("**/*")` with the same check aborts most of them and is worth wiring — but it is best-effort, not a boundary. <!-- UNVERIFIED: no authoritative confirmation that route interception covers 100% of browser-initiated subresource types -->
- **The second crawl task in a worker child raises `Event loop is closed` or `Future attached to a different loop`.** The task called `asyncio.run()`, which closed the loop the reused `AsyncWebCrawler` was bound to. One loop per child, created in `worker_process_init`, driven with `run_until_complete`.
- **Chromium launches even for `raw:` input.** `async with AsyncWebCrawler(...)` calls the strategy's `__aenter__`, which starts the browser eagerly regardless of what the first `arun` turns out to be. That cost is why the instance is per-child and long-lived, not per-task — but a container sized for "we mostly don't render" will still OOM at `-c 8`. Size for 8 browsers or move markdown-only extraction onto a second crawler built with `AsyncHTTPCrawlerStrategy`.
- **Every page re-versions every night and the embedding bill triples.** The hash was taken over `raw_markdown` including link reference footnotes, or over the raw HTML. Hash the normalized text after chrome labelling and after the invisible-Unicode strip (`kb-security-baseline`), and hash exactly the bytes the embedder sees (`kb-chunking-rules`).
- **Politeness is configured and the target still rate-limits us.** `RateLimiter` and `MemoryAdaptiveDispatcher` throttle within one Python process; the `crawl` queue runs `-c 8` prefork children per container. Eight independent limiters is no limiter. The token bucket must live in Valkey.
- **A crawl task is killed at 300 s with nothing written.** `celery-workers` sets `soft_time_limit=300` on `crawl`, and a single JS render with `scan_full_page` plus a slow site can pass it. Keep the per-URL wall clock at 20 s and the render budget under 60 s, so one task is always many URLs' worth of headroom, and checkpoint each item as it completes.
- **A tenant's crawl config reaches `BrowserConfig.extra_args` and becomes RCE.** GHSA-r253-r9jw-qg44 (critical, Jun 2026) is Chromium launch-argument injection through exactly that field. `extra_args` is code-defined and constant; no tenant string may reach it, `proxy_config`, `js_code`, or `hooks`. Same reason `accept_downloads` stays `False` (GHSA-2jq4-q6vv-4cp3, path traversal in the download path).
- **The SSRF guard looks quieter than it is, and the rejections that vanish are the security-relevant ones.** `kb_crawl_robots_blocked_total`'s `reason` enum was written short — `robots private_ip redirect content_type size` — so a `CrawlRejected("scheme")`, `("credentials")` or `("dns")` increments no counter at all. Nothing errors: the guard still refuses the fetch, the log line still exists, and the dashboard simply shows fewer blocks than happened, so a tenant probing `file://`, embedded credentials, or a rebinding host reads as zero traffic. The enum is **eight** values — `robots` `scheme` `credentials` `dns` `private_ip` `redirect` `content_type` `size` — exactly as `kb-observability-conventions` → `references/metric-catalog.md` lists it, and every `CrawlRejected` reason must be one of them. A guard that needs a ninth is a catalog PR first, never a new label value invented at the call site.
- **A crawl failure is invisible to the error-rate alert.** `kb_crawl_pages_total` is labelled `disposition`, not `outcome`, because its six per-page results include `failed` and `missing`, which no shared `outcome=~"error|timeout"` matcher covers. Reusing `outcome` for a richer enum is valid PromQL that silently under-reports: the series exist, the sum is just smaller, and the dashboard reads healthy while pages fail. Run-level health lives on `kb_crawl_runs_total{outcome}` and is what the alerts consume (`kb-observability-conventions`).

## Official docs

- [Crawl4AI — markdown generation and content filters](https://docs.crawl4ai.com/core/markdown-generation/) — `MarkdownGenerationResult` fields, `content_source`, the filter strategies we refuse.
- [Crawl4AI — `CrawlerRunConfig` / `BrowserConfig` parameters](https://docs.crawl4ai.com/api/parameters/) and the [complete SDK reference](https://docs.crawl4ai.com/complete-sdk-reference/) — every default quoted above.
- [Crawl4AI — local files and raw HTML](https://docs.crawl4ai.com/core/local-files/) — the `raw:` prefix the guarded fetch depends on.
- [Crawl4AI — CHANGELOG](https://github.com/unclecode/crawl4ai/blob/main/CHANGELOG.md) — 0.9.0's breaking changes, all scoped to the Docker API server.
- [Crawl4AI — security advisories](https://github.com/unclecode/crawl4ai/security/advisories) — the 2026 set; read before enabling any request-supplied configuration.
- [Crawl4AI issue #582](https://github.com/unclecode/crawl4ai/issues/582) — `<a>`/`<strong>` deleted mid-sentence by the pruning filter.
- [Playwright — network routing](https://playwright.dev/python/docs/network) — `page.route` and request abortion for subresource filtering.
- [httpx — transports and request extensions](https://www.python-httpx.org/advanced/transports/) — `sni_hostname`, streaming responses, timeout composition.
- [RFC 9309 — Robots Exclusion Protocol](https://www.rfc-editor.org/rfc/rfc9309.html) — matching rules, and why `Crawl-delay` is not in the standard.

## Definition of done

- [ ] No call site passes a tenant URL to `arun()`; a grep proves every `arun(` argument starts with `raw:`.
- [ ] `guarded_get` refuses the §22.5 fixture set: loopback, RFC 1918, link-local, IPv4-mapped link-local, CGNAT, `nip.io`, `metadata.google.internal`, embedded credentials, `file:`/`gopher:`, redirect-to-metadata at hop 1 and hop 4.
- [ ] Every resolved record is validated, not just the first; a fixture host with one public A and one private AAAA is refused.
- [ ] TLS certificates validate against the hostname after IP pinning; a fixture with a valid cert for the name and a pinned IP succeeds, and a hostname mismatch fails.
- [ ] Byte cap fires on decompressed bytes: a 1 MB gzip bomb declaring 10 GB is refused with `reason="size"`.
- [ ] No `PruningContentFilter`, `BM25ContentFilter`, `LLMContentFilter`, or non-empty `excluded_tags` anywhere in `services/ai-service/app/crawl/`; a grep is in CI.
- [ ] A crawl fixture whose only copy of a legal disclaimer sits in `<footer>` produces that text in `raw_markdown`, and the disclaimer is retrievable end to end after chunking.
- [ ] `chrome_blocks` is emitted for every page and consumed by the boilerplate pass; nothing is deleted in `app/crawl/`.
- [ ] robots.txt is fetched through `guarded_get`, honoured by default, and `check_robots_txt` is `False` in every `CrawlerRunConfig`.
- [ ] Per-domain and per-org token buckets live in Valkey; a two-container test shows the aggregate rate honouring the configured delay.
- [ ] One `AsyncWebCrawler` and one event loop per prefork child; a test runs 3 tasks in one child and asserts one browser launch and no loop errors.
- [ ] Recrawl of an unchanged 50-page site creates zero new versions and issues zero embedding calls; changing one page creates exactly one.
- [ ] A run in which the origin returns 503 for every URL marks nothing missing and increments `kb_crawl_runs_total{outcome="error"}`.
- [ ] The circuit breaker trips at >20% missing and applies no policy; a test asserts `missing_count` is unchanged for every item.
- [ ] `missing_count` resets to 0 on a 304 as well as a 200; a 5xx leaves it unchanged.
- [ ] `kb_crawl_*` metrics carry no host or URL label; a test asserts the emitted label set is a subset of the catalog's, that pages are counted on `disposition` and runs on `outcome`, and that every `CrawlRejected` reason raised in `app/crawl/` is one of the catalog's eight `reason` values.
