---
name: bge-m3-embeddings
description: Embedding doctrine for KnowledgeBot AI, and the record of why BGE-M3 is no longer loaded here — ADR-030 moved embedding to provider APIs. Use whenever embedding chunks or queries, wiring the provider `embed()` capability, composing `embedding_model_version`, sizing a batch, or debugging recall that is fine for short chunks and bad for long ones. Also read it before proposing FlagEmbedding, sentence-transformers, or any local embedder: the truncation hazard did not go away, it moved onto the network where nothing here can see it. Pairs with qdrant-hybrid-search (where the vectors land) and kb-chunking-rules (what gets embedded).
---

# Embeddings

**There is no local embedding model in this repository.** ADR-030: every ML task is an external
API call. `FlagEmbedding`, `sentence-transformers` and a direct `transformers` dependency are
gone, along with `KB_EMBEDDING_DEVICE`, the GPU overlay, and the 2 GB resident embedder that
forced `ai-api` to `WEB_CONCURRENCY=1`.

Embedding is `embed(texts: list[str], *, model: str) -> list[list[float]]`, a capability on the
existing five-provider adapter layer (`kb-provider-adapter-contract`), called with the org's own
encrypted credential, accounted against the org's quota, and mapped into the same 18-class error
taxonomy as chat.

**Implementation:** `services/ai-service/app/ingestion/embedding/embedder.py` and
`app/retrieval/collection.py`.
**Authoritative spec:** docs/10-embedding-indexing.md §15.1–15.5 and docs/05-tech-stack.md §9.12
describe the local BGE-M3 design; both are **superseded by ADR-030** — see docs/22.

## Non-negotiables

- **Identity is measured, not declared.** A vendor model id is an *alias*. It can be re-pointed
  at re-trained or re-quantized weights with no diff in this repository, no error at the call
  site, and no metric anywhere — cosine distance is defined between any two vectors of equal
  width, so nothing raises and only the ranking of the older half of the corpus degrades. Never
  put a bare model name in `embedding_model_version`. Use `EmbeddingModelIdentity.version`, which
  composes provider, model, the width the provider **actually returned**, and a digest over a
  fixed in-repo probe set.
- **The probe digest must never enter the collection name.** `EmbeddingSpace` (provider, model,
  width, distance, schema version) derives the collection; `canary_digest` sits outside it. A
  digest inside the space means a vendor blip silently spawns a *second* collection, the corpus
  divides itself between two of them, and the automatic repair looks exactly like a healthy
  bootstrap.
- **A digest change is never repaired automatically.** It fails the run and pages.
  `CANARY_CONFIRMATIONS` consecutive disagreements are a vendor weight swap, and the response is
  an operator-scheduled reindex into a new collection — never a worker quietly writing a second
  space into the current one.
- **Never truncate, pad, or "clean" text before the call.** An over-length chunk raises; sizing
  is the chunker's job (`kb-chunking-rules`). A defensive trim converts a loud chunker bug into a
  silent retrieval bug. This rule is older than the API migration and survived it intact.
- **`PROVIDER_TRUNCATION_POLICY` is `"reject"` and stays that way.** Several vendors take a
  `truncate`/`truncation` option and at least one defaults it to "trim the end". We want the 400.
- **The context window is checked before any customer text is sent.** It is **not discoverable
  from a response** — a provider that trims a long input returns 200 and a plausible vector — so
  it comes from the model's control-plane capability row and is checked against
  `MAX_TOKENS + TOKEN_HEADROOM` once per run, in `check_window`.
- **Ingestion never holds a credential.** `app/providers/` resolves and decrypts; ingestion
  receives an already-bound callable. That is what keeps a secret out of a Celery payload on the
  broker, out of a `failed_jobs` record, and out of a span (non-negotiable 9, no exemption).

## How we use it

### What the migration actually changed, and what it did not

| | Local BGE-M3 (until ADR-030) | Provider API (now) |
|---|---|---|
| Dense vectors | yes, 1024-dim | yes, **provider- and model-dependent width** |
| Sparse vectors | yes, learned lexical weights | **no** — dense only. See C2 below |
| `embedding_model_version` stability | stable by construction (a commit sha, resolved to a local path, unable to change under a running worker) | only as stable as the vendor's alias |
| Truncation hazard | `passage_max_length` defaulting to 512, silently | a short context window, or a `truncate` parameter — neither visible from here |
| Cost of a redelivered task | GPU seconds | **billed tokens** |
| Query instruction prefix | none (M3 takes none) | per-model; some vendors distinguish query and passage inputs |

The two rows that matter most are the ones people assume the migration fixed. It did not fix
truncation — it moved it somewhere less inspectable. And it did not fix identity — it took away
the property that made identity free.

### Batching is a cost knob, not a throughput knob

`MAX_BATCH_TEXTS` is small and fixed rather than tuned. The batch is the **checkpoint and the
redelivery granularity**: a redelivered Celery task re-embeds and re-bills whatever a batch
holds. `plan_batches` is pure and deterministic — a function of `(texts, measure)` alone, no
clock, no set iteration, no concurrency — because a batch boundary that moves between runs
re-embeds text that was already paid for.

`measure` is injected and is now a **provider-dependent estimate**: there is no local tokenizer
to consult. It must therefore **over-estimate**, never under-estimate. An over-estimate costs a
smaller batch; an under-estimate costs a silently trimmed passage. <!-- UNVERIFIED: which
measurement supplies it is an open decision -->

### The probe set

`CANARY_TEXTS` is five short ASCII-and-Devanagari strings owned by nobody, checked into the
repo, deliberately dull — so the probe is never customer text and never leaves an org's content
on a vendor's servers for a reason the org did not ask for. Five, because one string is one point
on a manifold and a re-quantization can leave a single vector unmoved.

**Editing that tuple silently re-identifies the whole corpus**, because the digest is in the
ingest key. It is a fingerprint input, not a test fixture.

`CANARY_PRECISION` is the knob that absorbs vendor fleet noise: embedding APIs are not
bit-reproducible across their own hardware, so hashing raw floats produces a digest that changes
on *their* deploy and re-versions our corpus for nothing. Four decimal places is coarse enough to
absorb that and fine enough that a re-trained model cannot land inside it. <!-- UNVERIFIED: never
measured against a live provider. Run the probe hourly for a day against each configured provider
before the digest is allowed to gate anything; if it flaps, the digest belongs in telemetry only
and not in the ingest key. -->

## Open findings

- **C2 — hybrid search lost its sparse arm.** BGE-M3 produced dense *and* learned-sparse vectors
  from one pass, which is what made `qdrant-hybrid-search`'s dense+sparse+RRF design work. API
  embedding endpoints return **dense only**. So either the sparse arm is dropped (retrieval
  becomes dense-only and RRF has nothing to fuse), or sparse vectors come from somewhere else.
  **BM25 is a statistical ranking function, not a machine-learning model**, so computing it
  locally does not violate ADR-030 and is the obvious candidate. `to_sparse_vector` is retained
  unimplemented for exactly that reason. Ankur's call; see docs/22.
- **The IDF modifier reasoning must be revisited if BM25 lands.** `SPARSE_MODIFIER` in
  `app/retrieval/collection.py` is `None`, and that was decided for *learned* weights, where
  multiplying by IDF double-counts rarity. Raw term frequencies are precisely what IDF is *for*.
  The separate objection survives either way: Qdrant computes document frequencies
  **collection-wide across every tenant** by default, which is a relevance error and a weak
  cross-tenant oracle.
- **Changing embedding model is a reindex, not a config edit.** The collection is created at one
  fixed width. `assert_dimensions` enforces it; accepting a mismatch would put two incomparable
  spaces in one collection, which never errors.

## Gotchas

- **Symptom: retrieval is fine for FAQ and policy chunks and quietly bad for prose and tables,
  with nothing in the logs.** The same failure as the old `passage_max_length` default, reached
  from the network. `text-embedding-3-large` accepts 8192 tokens and errors above it — that is
  the *good* case. Plenty of API embedding models accept 512, and a 700-token chunk sent to one
  of those is indexed from its first 512 tokens exactly as before. Fix: `check_window` against
  the capability row. Verify with a >600-token fixture whose answer is in its last sentence.
- **Symptom: ranking degrades for documents older than some date, and no deploy explains it.**
  Alias drift. This is the failure the canary digest exists to detect and the reason
  `embedding_model_version` is no longer free. Nothing else in the system can see it: no error,
  no status code, no metric.
- **Symptom: the digest changes on a day nobody deployed anything.** Vendor fleet noise, not a
  weight swap — different accelerators, different batch shapes, different kernel versions move
  the last few bits. That is what `CANARY_PRECISION` rounds away and what `CANARY_CONFIRMATIONS`
  is for: one differing probe is a warning, not a reindex.
- **Symptom: a resubmission dedupes against a run made with a different model.** The ingest key
  was built from the bare alias instead of `EmbeddingModelIdentity.version`. The request matches
  the completed run, the admin sees "already processed", and the new vectors are never made.
- **Symptom: every chunk's vector is off by one position and the index is fully populated and
  fully wrong.** A provider returned fewer vectors than inputs and nothing checked. `embed_passages`
  asserts the returned total equals the batch size **per batch**, because the shift is silent.
- **Symptom: `dimensions` was set to save storage and recall fell off a cliff.** Several APIs take
  a width parameter that truncates the vector (Matryoshka) and returns 200. Whether truncated
  dimensions degrade gracefully depends entirely on whether *that* model was trained with an MRL
  objective — it is a per-model property, not a general one. Either way the same model at two
  widths is **two spaces**, and `dimensions` is part of `EmbeddingSpace` for that reason.
- **Symptom: a smoke test embeds fine and a real corpus 429s.** Batching bounded only by
  `MAX_BATCH_TEXTS` and not by the provider's per-request token ceiling. Both caps differ per
  vendor and a batch that exceeds either is a 400 — which is the loud failure we want, but only
  if the ceiling is actually consulted.
- **Symptom: an all-CJK or all-Devanagari document produces very different token totals from an
  English one of the same length.** Expected, and now worse than it was: the vendor tokenizes with
  its own algorithm over its own vocabulary, which is a *different* algorithm from whatever
  supplies `measure`. The gap is widest on non-Latin scripts — exactly where a silent trim is
  least likely to be noticed. That gap is what `TOKEN_HEADROOM` covers.
- **Symptom: cross-lingual retrieval works but lexical matching does not.** Historically the
  sparse branch was near-useless cross-lingually because token ids do not overlap across scripts,
  so cross-lingual retrieval rode on the dense branch alone. If C2 resolves toward BM25 this
  returns unchanged: term overlap across scripts is still zero.
- **Symptom: someone proposes warming the provider at startup or probing it in `/health/ready`.**
  Both are wrong and the second is dangerous. Traefik drops an unhealthy container and Compose's
  `restart:` reacts to a process exiting, not to a failing healthcheck — so a readiness probe
  that trips on a transient provider blip pulls *every* replica out of the edge at once with
  nothing able to put them back. Provider reachability is per-bot and per-credential anyway.

## Official docs

- [OpenAI embeddings](https://platform.openai.com/docs/guides/embeddings) — the `dimensions`
  parameter and the 8192-token window.
- [Qdrant — sparse vectors](https://qdrant.tech/documentation/concepts/vectors/#sparse-vectors)
  and [IDF modifier](https://qdrant.tech/documentation/concepts/indexing/#idf-modifier) — the
  `indices`/`values` shape and the modifier we do not enable.
- [BGE-M3 paper, arXiv:2402.03216](https://arxiv.org/abs/2402.03216) — historical. It is why the
  hybrid design assumed one model could emit both branches, which is the assumption C2 is about.

## Definition of done

- [ ] `embedding_model_version` is `EmbeddingModelIdentity.version`, never a bare model id; a test
      asserts two identities differing only in the digest are unequal.
- [ ] `canary_digest` is **not** a field of `EmbeddingSpace`; a test asserts it, because the
      failure it prevents (a second collection opening silently) looks like a healthy bootstrap.
- [ ] `check_window` runs once per run, before any customer text leaves the process, and raises
      `ErrorClass.VALIDATION` naming both numbers and the model.
- [ ] An integration test embeds a >600-token fixture whose answer is in its final sentence and
      retrieves it by a query matching only that sentence.
- [ ] `plan_batches` is deterministic; a test runs it twice over the same input and asserts
      identical boundaries, and asserts a single over-length text raises rather than splitting.
- [ ] `embed_passages` asserts returned-vector count equals batch size, per batch.
- [ ] No module under `app/ingestion/embedding/`, `chunking/` or `indexing/` imports `torch`,
      `FlagEmbedding`, `transformers`, `sentence_transformers` or `huggingface_hub`; enforced by
      an AST test, not a text grep — `parsing/` legitimately names torch in prose.
- [ ] No parameter on the ingestion path is named for a credential; enforced by the same AST test.
- [ ] `kb_embedding_chunks_total` and `kb_embedding_batch_duration_seconds` carry a **bounded**
      `model` label — `identity.version`, bounded by configured models, never by tenants.
- [ ] A multilingual fixture set (English, Hindi, Arabic, Chinese) embeds and retrieves, and
      `measure` over-estimates rather than under-estimates on every one of them.
