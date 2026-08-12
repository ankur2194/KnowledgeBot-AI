"""The guarded fetch and recrawl. Owner: crawler-engineer.

The one component in the platform that takes a URL from a tenant and connects to it, from
inside the private network. Read ``fetch.py`` before anything else here: it holds the egress
envelope, and every other module in this package is arranged around not bypassing it.

    fetch.py        THE guarded fetch — scheme/port/address validation, per-hop redirect
                    revalidation, byte and content-type caps. Every outbound tenant-directed
                    request in this package goes through it.
    discovery.py    robots sitemaps, sitemap indexes, link discovery, and the scope budget.
    politeness.py   per-HOST rate limiting and robots handling; per-ORG concurrency. Two
                    different limiters, for two different reasons.
    render.py       the JS-render policy. Off by default, opt-in per source, Chromium only in
                    the `runtime-crawl` image stage.
    extract.py      conservative HTML-to-Markdown. No content filter ever touches indexed
                    text; chrome is labelled, never removed.
    recrawl.py      change detection, version decisions, and the missing-page policy.
    tasks.py        `kb.crawl.*` on the `crawl` queue, one task per URL.

Three invariants that hold across all of them:

* Crawl4AI never fetches. It receives ``raw:<html>`` we already hold, and is a markdown
  normalizer plus a Playwright driver — nothing more.
* This worker sits on ``application`` and ``observability``, and deliberately not on
  ``data``. Results reach PostgreSQL, Qdrant and object storage only by way of ``ai-api``.
* Failure is per page. One 500 does not fail a 200-page site, and a run that indexed a
  fraction of a source never reports success.
"""
