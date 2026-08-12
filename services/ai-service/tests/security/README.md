# `security/` — docs/17 §22.5, unconditionally

Marked `@pytest.mark.security`. **This tier runs in every CI tier and is never gated by
test-impact analysis.** Impact analysis follows import graphs; the dependencies that break isolation
cross a process boundary into Qdrant, Valkey and object storage, which it cannot see.

## Must cover

Cross-tenant API access · cross-tenant vector access · SSRF payloads · malicious filenames ·
oversized files · prompt-injection samples · XSS in source text · secret redaction · rate limits.

## The two fixture rules, and why each one exists

**Two organizations, always.** A one-organization fixture cannot fail an isolation test — with a
single tenant there is nothing to leak, so it passes against code with no filter at all. There is
deliberately no single-org helper here to reach for. The canary is planted in **Org B's** content and
asserted absent from **Org A's** raw response body, so a canary hiding in a citation title, an export
cell or a cached completion still trips it.

**The positive control comes first.** Assert the canary *is* returned to Org B, then assert it is not
returned to Org A. Without that line, a broken filter, an empty collection, a 500, or a `KbError`
raised before the query all satisfy "canary absent" — and it is the line people delete when the suite
gets slow.

The canary is regenerated per test, so a stale Qdrant point or a warm answer cache from a previous
run can never satisfy it.

## May **not**

- Run against `QdrantClient(":memory:")`. Enforced by `tests/unit/test_harness_guards.py`.
- Stub the retrieval call, the tenant filter, the HMAC verifier or the error envelope.
- Use any bypass, because none is built: no `allowed_version_ids=None`, no `internal=True`, no
  fixture that widens a filter. If a test is awkward without one, the production code is wrong.

## Secret redaction has the same shape as the isolation control

Assert the fixture credential **actually reached a provider call** — `fake_provider.requests` is
there for exactly this — and only then assert it appears in no log line, no span attribute, no audit
detail and no response body. A redaction test that passes because the key was never used is the same
bug as an isolation test that passes because nothing was returned.

```
uv run pytest tests/security -q -m security
```
