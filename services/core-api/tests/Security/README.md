# `Security/` — docs/17 §22.5, and it runs unconditionally

Never accelerated by Pest's test-impact analysis. Impact analysis follows PHP call graphs; the
riskiest dependencies here cross a process boundary into FastAPI and Qdrant, which it cannot see. A
change that breaks isolation can leave every PHP call graph untouched.

## The whole suite in two rules

**1. Every test calls `tenantPair()`. There is no single-organization helper, and there will not be
one.** A one-org fixture cannot fail an isolation test: with a single tenant there is nothing to
leak, so it passes against code with no filter at all. `tests/Unit/TenantHarnessShapeTest.php`
asserts that no such helper has appeared.

**2. The positive control comes first.**

```php
$t = tenantPair();
expect(readSurface($surface, $t->b, $t->botB, $t->actorB))->toContain($t->canary);   // FIRST
expect(readSurface($surface, $t->a, $t->botA, $t->actorA))->not->toContain($t->canary);
```

Without the first line, a broken export, an empty index, a 500 swallowed by a missing `assertOk`, or
a surface that returns nothing at all *all* satisfy "canary not present". It is the assertion people
delete when the suite gets slow, and deleting it is what converts this directory into decoration.

The assertion runs against the **raw response body**, so a canary hiding in a citation title, an
export cell, or a cached completion still trips it. The canary is fresh per test, so a stale Qdrant
point or a warm answer cache cannot satisfy it.

## Surfaces

Run the same body over the dataset — chat answer and citations, retrieval diagnostics, source list,
analytics aggregate, CSV export, warm answer cache. Adding a seventh surface to the product without
adding a row here is meant to look conspicuously incomplete.

## Also here

CORS and origin rejection · SSRF payloads · malicious filenames · oversized files · prompt-injection
samples · XSS in source content · secret redaction · rate limits · the `crossOrg()` assignment
rejected by **both** the service and the composite foreign key.

## May **not**

- `Http::fake()` — banned in this directory. A faked AI service means no tenant filter ever executed.
- `QdrantClient(":memory:")` on the Python side of any assertion made here.
- Any bypass: no env flag, no `internal=true`, no fixture that disables scoping. If a test is hard to
  write without one, the production code is wrong.

```
./vendor/bin/pest --testsuite=Security     # every tier, every run
```
