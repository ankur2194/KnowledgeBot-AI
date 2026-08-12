# `samples/` — the platform golden evaluation corpus

Owner: `rag-eval-engineer`. Everyone else reads this directory; nobody else writes it.

This directory holds **the corpus and the questions**, not the harness. The harness lives in
`services/ai-service/app/evaluation/` and is versioned with the code. That split is deliberate:
a dataset edit that rides along in a code commit is how a "regression" turns out to be a changed
question. Corpus and code move independently, and every run records both.

```
samples/
  VERSION                     the corpus version string, bumped by hand
  corpus/manifest.toml        one entry per fixture document — the id space cases reference
  corpus/documents/<kind>/    the fixture documents, one directory per retrieval property
  golden/dataset.toml         dataset identity + the corpus VERSION it is valid against
  golden/schema.md            the case schema
  golden/cases/*.yaml         the ten §21.2 categories
  expected/baseline.json      the last-accepted aggregate the regression gate compares against
  expected/noise-floor.md     run-to-run spread, and why the tolerance band sits above it
  tools/generate_corpus.py    regenerates all ten fixtures, byte-for-byte
  tools/corpus_lib.py         the determinism plumbing and the PDF layout engine
```

---

## Regenerating the fixtures

The ten fixture documents are **generated from the specifications in
`corpus/documents/*/README.md`**, not hand-built, and they are byte-reproducible. Regenerating on
another machine, in another timezone, at another moment produces the same digests — which is what
lets `corpus/manifest.toml` mean anything at all.

The generators are **authoring tools, not runtime dependencies**. They are installed into a
throwaway container and must never enter `services/ai-service/pyproject.toml`; that service's
dependency closure does not grow so this directory can be rebuilt.

```sh
# One throwaway venv, outside the repository. numpy is needed for the seeded scan noise;
# Image.effect_noise is not seedable and would make the fixture's digest move on every run.
SCRATCH=/tmp/kb-corpus-tools
mkdir -p "$SCRATCH"
docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp \
  -v "$SCRATCH:/s" python:3.13-slim sh -lc \
  'python -m venv /s/venv && /s/venv/bin/pip -q install \
      reportlab python-docx openpyxl python-pptx pymupdf pillow numpy'

# Prove determinism BEFORE trusting a digest: generates the whole corpus twice into two
# directories and compares every one.
docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp \
  -v "$PWD:/repo" -v "$SCRATCH:/s" -w /repo/samples python:3.13-slim \
  /s/venv/bin/python tools/generate_corpus.py --verify

# Write them.
docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp \
  -v "$PWD:/repo" -v "$SCRATCH:/s" -w /repo/samples python:3.13-slim \
  /s/venv/bin/python tools/generate_corpus.py --out corpus/documents
```

Then paste the printed digests into `corpus/manifest.toml`, bump `VERSION`, and confirm with the
verifier that actually gates a run:

```sh
docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -v "$PWD:/repo" \
  -w /repo/services/ai-service python:3.13-slim ./.venv/bin/python \
  -c 'from app.evaluation.corpus import verify_corpus; v = verify_corpus(); print(v.files_hashed)'
```

**What fights back, and what was done about it.** Every one of these was measured, not assumed:

| Format | What drifts | Fix |
|---|---|---|
| PDF (reportlab) | `/CreationDate`, `/ModDate`, random `/ID` | `rl_config.invariant` + `Canvas(invariant=1)` |
| docx / xlsx / pptx | zip entry timestamps from `time.localtime()` | every entry rewritten at a fixed timestamp in sorted order |
| **xlsx** | **openpyxl overwrites `dcterms:modified` with the clock inside `save()`, discarding `wb.properties.modified`** | `docProps/core.xml` timestamps rewritten in the finished bytes, for all three OOXML formats |
| scanned page noise | `Image.effect_noise` has no seed | seeded `numpy.random.default_rng` |

The xlsx one is the trap: pinning the property does nothing and zip normalisation does not reach
it. Two runs of an identical workbook produced normalised digests `348ffacc3989aac1` and
`11ac5018b18f4b12` before `core.xml` was rewritten.

### Measuring the scanned fixture's OCR numbers

`corpus/documents/scanned/README.md` records mean confidence and coverage per page, and they are
engine-specific. Re-measure whenever the OCR engine, its version, or the render DPI changes —
rapidocr needs the headless OpenCV build, since `opencv-python` wants `libxcb.so.1`:

```sh
docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -v "$SCRATCH:/s" python:3.13-slim sh -lc \
  '/s/venv/bin/pip -q uninstall -y opencv-python; \
   /s/venv/bin/pip -q install "rapidocr==3.9.2" onnxruntime opencv-python-headless'
```

---

## Why this corpus is synthetic only

Every document here is **invented**. There is no real company, no real customer, no real person,
no real price, and no real support ticket in this tree. The fictional company is *Kelpwright
Instruments*; every name, address, part code, and figure was authored for this repository.

That is not squeamishness, it is what makes the corpus usable at all:

- **Content capture is a per-organization privacy decision (§18.10).** `evaluation_results` stores
  the question, the generated answer, and the retrieved evidence verbatim. For tenant data that
  storage is gated on the organization's privacy switches. CI has no organization, so it has no
  switch to consult — and a harness that "just runs anyway" has silently overridden a tenant
  setting. A synthetic corpus has no tenant and therefore no switch to honour, which is precisely
  why CI may run against this corpus **and only this corpus**.
- **It is committed to git.** Tenant content in a public-ish repository is a breach regardless of
  how good the questions are.
- **It is stable.** A tenant's documents are re-crawled, re-uploaded, and edited by their owners.
  A golden corpus that changes under you cannot support a comparison across time.

Per-tenant evaluation sets exist and are a different thing entirely: `evaluation_datasets`
(docs/11 §16.7), org-scoped like every other record, created by an admin from real conversations,
never committed here. **The two never mix.** If a real tenant's content ever needs to appear in
this tree, that is an explicit recorded decision with the tenant's consent attached — not a commit.

---

## The version, and what it forbids

`VERSION` currently reads:

```
corpus-2026.08.1
```

It is bumped **by hand**, in its own commit, touching nothing but `samples/`. Format is
`corpus-YYYY.MM.N`.

Bump it when *anything* that can change a score changes:

| Change | Bump |
|---|---|
| a fixture document's bytes change (its `sha256` moves) | yes |
| a fixture is added or removed | yes |
| a case is added, removed, or its `question` / `reference` / `expected_sources` change | yes |
| `grading_notes`, `tags`, or a README clarification with no scoring effect | no — say so in the commit message |
| `corpus/manifest.toml` prose only — a `notes` block, a comment, a `licence` string — with no `id`, `path` or `sha256` touched | no — but see below, the manifest digest moves |

**A manifest prose edit does not bump `VERSION`, and it does move `manifest_sha256`. Both are
correct, and they are two different signals rather than one that disagrees with itself.**
`VERSION` answers *can this change a score*, and a `notes` string cannot — `read_fixtures()`
parses only `id`, `path`, `sha256` and `synthetic`, so no other field in this file reaches any
code path. `manifest_sha256` is a deliberately stricter whole-file backstop that exists because
`VERSION` is bumped **by hand** and can therefore be forgotten; `baseline.json` says so in as
many words — *"catches an edited fixture even when someone forgot to bump VERSION"*. A backstop
that only fires when you remembered to fire it is not a backstop, so it is over-sensitive on
purpose, and a prose edit costing one re-baseline is the price of never missing a real one.

Bumping `VERSION` for prose would be the worse error, not the safer one. The version string is
what `golden/dataset.toml` pins against and what every run records, and comparisons across
corpus versions are **refused**. A `corpus-2026.08.2` whose fixture bytes are identical to
`.1` would refuse comparisons that are genuinely valid — inventing an incomparability to
document a typo fix.

**Comparisons across corpus versions are refused, not silently performed.** A run records the
`corpus_version` it executed against; the gate compares it to `expected/baseline.json`'s
`corpus_version` and, if they differ, **stops** with "incomparable: corpus version changed" rather
than emitting a delta. A changed question is not a regression, and a gate that cannot tell the
difference between the two teaches everyone to ignore it. The same rule applies to the judge:

**A run whose judge model id, judge temperature, or `ragas` version differs from
`expected/baseline.json` is REFUSED, not compared.** All three are recorded in the run and in the
baseline for exactly this reason. When the judge must change, every historical run you intend to
compare against is re-baselined against the new judge, or the series is broken. Scores are not
comparable across judges — an audit of judge stability found the strongest model flipping 14.7% of
its verdicts under nothing more than A/B order reversal.

Re-baselining is a deliberate act: it replaces the null-or-previous values in `baseline.json`,
records who accepted them and against which corpus version and judge, and is reviewed like any
other change to a release gate.

---

## Seeding the corpus

The fixtures are documents, not database rows. Nothing here is searchable until it has been
ingested by the real ingestion pipeline into a throwaway evaluation organization.

> **Step 1 exists and passes. Steps 2–5 do not exist.** The ten fixtures are authored and every
> digest in `corpus/manifest.toml` is real, and `services/ai-service/app/evaluation/` holds the
> corpus verifier, the run-identity types and the `evaluate`-queue task shells. There is still no
> seeder and no pipeline invocation — and `chat_pipeline.answer`, `rerank`, `embed_passages` and
> `resolve_identity` are all `NotImplementedError`, so nothing can run end to end and no baseline
> tolerance may be filled in. Read step 1 as a control that runs today and the rest as requirements
> on the seeder when it is written.

1. **Verify the corpus.** `app/evaluation/corpus.py`'s `verify_corpus()` recomputes the digest of
   every fixture named in `corpus/manifest.toml` and **aborts on any mismatch** — a run against a
   corpus that is not the corpus the manifest describes produces a number attributed to the wrong
   bytes. It never warns and continues. `kb.evaluate.start_run` calls it before anything else, and
   `EvaluationRunRecord` takes the `CorpusVerification` object it returns rather than a boolean, so
   there is no path to a comparable result that skipped it.

   **It passes today: 10 fixtures, 21 files, 877,761 bytes** against `corpus-2026.08.1`. Twenty-one
   rather than ten because `site-kelpwright-www` is a directory fixture — nine pages plus
   `robots.txt` and `sitemap.xml`.

   **Two non-vacuity floors, because a hash check over an empty corpus exits 0** and reads in a log
   exactly like one that verified everything: at least ten fixtures must be declared, and at least
   ten files must actually have been opened and read. `CorpusVerification` re-asserts both at
   construction, so a hollow proof cannot be handed to a run record either. The corpus clears the
   file floor by 2.1×, and the margin is reported rather than assumed.

   The biconditional **`sha256` is a placeholder if and only if the fixture's `path` does not
   exist** is asserted by `tests/unit/test_corpus_integrity.py`, which also asserts no entry still
   carries a placeholder. The same file builds a correct corpus in a temporary tree and proves the
   verifier passes it, then breaks it one way at a time — a flipped byte, a page added to the
   directory fixture, a manifest truncated below the floor, a path aimed outside `samples/` — so
   the check is known not to be a verifier that merely always passes.

   A directory fixture's digest is a **tree digest** over the sorted relative paths and their
   per-file digests; the algorithm is fixed byte-for-byte by `TREE_DIGEST_SCHEME` in that module and
   re-implemented independently in the test. Do not write a second definition of it.

2. **Serve the crawl fixture locally.** `corpus/documents/site/` is a static HTML tree served by
   the Compose `test` profile on the internal network. The crawl fixture is **never fetched from
   the public internet**: a live fetch makes the corpus non-reproducible, and the corpus would then
   fail its own hash check the first time the remote site changed a footer.
3. **Ingest through the real pipeline.** Upload each fixture as a source in the evaluation org and
   let parsing, OCR, chunking, embedding, and indexing run exactly as they do in production. The
   pipeline is the thing being measured; a seeding path that writes chunks directly measures a
   system we do not ship.
4. **Materialise the version pins.** Ingestion produces one `source_version_id` per fixture. The
   seeder writes the manifest-id → `source_version_id` mapping for this run into the
   `evaluation_runs` record, and materialises `evaluation_cases` rows whose `expected_sources`
   carry those version ids. See `golden/schema.md` — a file committed to git cannot pin a version
   id that does not exist yet, and conflating the two produces a corpus that cannot be checked in.
5. **Run.** The harness fans out one Celery task per case on the `evaluate` queue.

## Every run records the corpus version

Non-negotiable, and it is the reason this file exists. An evaluation run stores, alongside its
aggregate:

- `corpus_version` — the contents of `VERSION` at run time,
- `corpus_manifest_sha256` — a hash over `corpus/manifest.toml`, which catches an edited fixture
  even if someone forgot to bump `VERSION`,
- `dataset_version` — from `golden/dataset.toml`,
- the judge's resolved model id, its `system_fingerprint`, its temperature, and the `ragas` version,
- the retrieval configuration snapshot (`retrieval_configuration_version`),
- the manifest-id → `source_version_id` mapping the seeding step produced.

A result missing any of these cannot be reproduced and cannot be compared, so it is not a result.

## Where this does and does not gate

Ragas never gates a unit suite. The LLM-judged half runs on `schedule` and `workflow_dispatch`
only, in its own job, against this corpus. A faithfulness dip must not block an unrelated PR; the
deterministic half (retrieval hit rate, source recall, citation correctness, both refusal rates,
latency, cost) is cheap and free of judge calls and may run far more often. This README describes
that split; it does not build it.

## Reading order for anyone touching this directory

1. `golden/schema.md` — the case schema, written so a case can be reviewed without reading the harness.
2. `corpus/manifest.toml` — the id space. Cases reference fixtures **by manifest id, never by path**.
3. `expected/noise-floor.md` — before proposing any threshold.
