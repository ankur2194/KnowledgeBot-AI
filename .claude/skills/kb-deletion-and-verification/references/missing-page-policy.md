# Missing pages on crawled sources (§8.14)

Detail split out of `kb-deletion-and-verification/SKILL.md`. Four policies exist for a page that a recrawl no longer discovers; the default is *disable after repeated confirmation*, never delete, because one 503 or one truncated sitemap makes every page on a site look missing at the same instant.

- `source_items.missing_count` increments only on a crawl run that **succeeded**. A failed or partial run must increment nothing, or a single bad sitemap fetch disables the whole site.
- Any successful rediscovery resets `missing_count` to 0. Consecutive means consecutive.
- Add a run-level circuit breaker: if a run would mark more than a configured fraction of known items missing, fail the run and apply no policy. (`crawl4ai-crawler` sets the fraction; from deletion's side that run reads as an ordinary failure and no policy fires.)
- "Disable after N" is phase 1 only — logical exclusion, vectors intact, instantly reversible. Only the "delete after a retention period" policy is allowed to enqueue phase 2, and it enqueues it through the same `purge_source` path as an admin delete, with the same verification.

The observable failure this guards against: a site behind a maintenance page for one night, and every page of it disabled or queued for deletion by morning, with no single event in the log that looks wrong.
