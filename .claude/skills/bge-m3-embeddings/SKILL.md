---
name: bge-m3-embeddings
description: BGE-M3 dense + sparse embedding for the KnowledgeBot AI service — the model pin, the loader choice, device/batch/precision config, and the lexical-weights-to-Qdrant mapping. Use whenever loading the embedder, embedding chunks or queries, tuning batch size, bumping `embedding_model_version`, or debugging recall that is fine for short chunks and bad for long ones. FlagEmbedding truncates passages at 512 tokens by default, silently. Pairs with qdrant-hybrid-search (where the vectors land) and kb-chunking-rules (what gets embedded).
---

# BGE-M3 Embeddings

`BAAI/bge-m3` @ revision `5617a9f61b028005a4858fdac845db406aefb181` (HF `lastModified` 2024-07-03; weights unchanged since 2024-04), loaded with **FlagEmbedding 1.4.0** `BGEM3FlagModel`. 1024-dim dense (CLS pooling, L2-normalized) + learned lexical weights. XLM-RoBERTa-large backbone, ~568M params, 8192-token position table.
**Authoritative spec:** docs/10-embedding-indexing.md §15.1–15.5, docs/05-tech-stack.md §9.12, docs/09-chunking.md §14.2

## Non-negotiables

- **`passage_max_length` must be asserted `>= MAX_TOKENS + 2` at process start.** FlagEmbedding tokenizes with `truncation=True` and no warning, so a 700-token chunk — the size `kb-chunking-rules` explicitly targets — is indexed from its first 512 tokens and the tail is unsearchable forever. There is no error, no log line, and no metric that moves. This is the single reason this file exists; see Gotchas for the failure symptom. The `+2` is `<s>`/`</s>`, which the chunker's `token_count` does not include.
- **The model id *and revision* are part of version identity.** `embedding_model_id` = `bge-m3@5617a9f` goes on every chunk (`kb-chunking-rules`) and into the `ingest_key` (`kb-source-lifecycle`). Changing the pin, `passage_max_length`, `normalize_embeddings`, or the pooling method invalidates the whole index — it is a re-embed under a new index version per §15.4, never an in-place config edit. A collection holding two embedding configs is silently wrong: cosine scores across them are not comparable and nothing raises.
- **We index dense and sparse. We do not index ColBERT vectors.** Justified below with the paper's own numbers. Turning multi-vector on is an ADR, not a config flag.
- **Never truncate, pad, or "clean" text before `encode()`.** The embed step raises on an over-length chunk; sizing is the chunker's job (`kb-chunking-rules`). A defensive truncate here converts a loud chunker bug into a silent retrieval bug.
- **Queries get no instruction prefix.** BGE-M3 is not BGE-v1.5: the model card states it "no longer requires adding instructions to the queries", and `query_instruction_for_retrieval` defaults to `None`. Adding one shifts the query embedding off the passage manifold and costs recall with no error.
- **`devices` is always explicit.** `devices=None` resolves to *every* visible CUDA device, and `encode()` then forks a multiprocess pool — inside a Celery worker that means N model copies and N× VRAM per worker process (`celery-workers`).

## How we use it

### Is bge-m3 still the right model in August 2026? Yes — verified, not assumed.

BAAI has shipped no successor for multilingual hybrid retrieval. The BGE releases after M3 are `bge-multilingual-gemma2` (9B, dense-only), `bge-en-icl` (English), `bge-code-v1`, `bge-reasoner-embed-qwen3-8b` (Oct 2025, 8B, reasoning-intensive retrieval), and the BGE-VL multimodal family (latest, Apr 2026). None of them emits lexical weights, and each is 8–16× the parameter count. `bge-m3` still pulls ~35M HF downloads/month.

`Qwen3-Embedding-8B` (Jun 2025) beats M3 on MTEB multilingual (70.6 vs ~63.0) — but it is dense-only and 8B params, so adopting it means running a *second* model for the sparse branch (SPLADE or BM25) and ~14× the inference cost. Our retrieval quality lever is the reranker (`bge-reranker`), not the first-stage dense encoder. **Recommendation: stay on bge-m3.** Revisit if the eval harness (docs/16-evaluation.md) shows first-stage recall, not rerank precision, is the binding constraint.

### Loader: FlagEmbedding, and there is no real alternative

| Loader | Dense | Sparse | Verdict |
|---|---|---|---|
| `FlagEmbedding.BGEM3FlagModel` **(ours)** | yes | yes | The only loader that runs `sparse_linear.pt`. Hostile defaults — pin all of them. |
| `sentence_transformers.SentenceTransformer` | yes | **no** | `modules.json` in the repo is `Transformer → Pooling → Normalize`. The sparse and ColBERT heads are never loaded. Also reads `max_seq_length: 8192` from `sentence_bert_config.json`, so it truncates at a *different length* than FlagEmbedding on the same weights. |
| Repo `onnx/model.onnx` | yes | **no** | Graph outputs are exactly `token_embeddings` and `sentence_embedding`. No sparse head was exported. |
| `fastembed` 0.8.0 | **no** | no | `BAAI/bge-m3` is not in its ONNX model registry ([#348](https://github.com/qdrant/fastembed/issues/348), still open). |

Dense output is *identical* between FlagEmbedding and sentence-transformers when max length matches — both do CLS pooling then L2-normalize (`1_Pooling/config.json`, `DEFAULT_POOLING_METHOD = "cls"`). So there is no quality argument for sentence-transformers, only a truncation hazard.

### Which of the three outputs we index

BGE-M3 paper (arXiv:2402.03216v3), our two relevant benchmarks:

| | MIRACL nDCG@10 | MKQA R@100 | MLDR nDCG@10 (long docs) |
|---|---|---|---|
| Dense | 67.8 | 75.3 | 52.5 |
| Sparse | 53.9 | 45.3 | **62.2** |
| Dense+Sparse **(ours)** | 68.9 | 75.3 | 64.8 |
| + Multi-vector (all) | 70.0 | 75.5 | 65.0 |

Sparse alone is far behind dense on short queries but *ahead* on long documents — the two branches fail on different things, which is exactly the case for RRF fusion (`kb-rag-query-contract` §12.9). ColBERT buys **+1.1 / +0.2 / +0.2** on top of dense+sparse and costs one 1024-dim vector *per token*: ~2.8 MB per 700-token chunk at fp32 versus 4 KB for the dense vector, ~700×. Our reranker does that job better for less storage. Not indexed.

### Layout and config

```
services/ai-service/app/embedding/
├── bge_m3.py        # loader, startup assertions, encode wrappers (below)
├── config.py        # the pinned constants; mirrored into the config snapshot
└── qdrant_shape.py  # lexical_weights -> models.SparseVector
```

```python
# services/ai-service/app/embedding/bge_m3.py
from __future__ import annotations

from dataclasses import dataclass
from functools import lru_cache

from FlagEmbedding import BGEM3FlagModel
from huggingface_hub import snapshot_download
from qdrant_client import models

MODEL_ID = "BAAI/bge-m3"
MODEL_REVISION = "5617a9f61b028005a4858fdac845db406aefb181"
EMBEDDING_MODEL_ID = "bge-m3@5617a9f"   # goes on every chunk + into ingest_key
DENSE_DIM = 1024
MAX_TOKENS = 700                # kb-chunking-rules; keep the two in lockstep
PASSAGE_MAX_LENGTH = 768        # >= MAX_TOKENS + <s> + </s>, with headroom
QUERY_MAX_LENGTH = 512          # rewritten queries are short; never truncated


@dataclass(frozen=True, slots=True)
class ChunkVectors:
    dense: list[float]
    sparse: models.SparseVector


@lru_cache(maxsize=1)
def get_model() -> BGEM3FlagModel:
    """Load once — FastAPI lifespan (`fastapi-service`) or Celery
    `worker_process_init`, never per request. ~2.3 GB of fp32 weights."""
    # BGEM3FlagModel does NOT forward `revision=` to from_pretrained; it setattr()s
    # unknown kwargs onto self and loads `main`. Resolve the pin ourselves.
    local_path = snapshot_download(MODEL_ID, revision=MODEL_REVISION)

    model = BGEM3FlagModel(
        local_path,
        normalize_embeddings=True,        # default, but it is index-identity
        use_fp16=True,                    # GPU only; silently upcast on CPU
        query_instruction_for_retrieval=None,  # bge-m3 takes NO query prefix
        devices="cuda:0",                 # None => every visible GPU + a fork pool
        pooling_method="cls",
        batch_size=16,                    # not the 256 default; see Gotchas
        query_max_length=QUERY_MAX_LENGTH,
        passage_max_length=PASSAGE_MAX_LENGTH,
        return_dense=True,
        return_sparse=True,               # defaults to False — hybrid dies quietly
        return_colbert_vecs=False,
    )

    # THE assertion. Without it a 700-token chunk is indexed from its first 512
    # tokens with no warning, no error, and no log line, forever.
    if model.passage_max_length < MAX_TOKENS + 2:
        raise RuntimeError(
            f"passage_max_length={model.passage_max_length} truncates chunks sized "
            f"to {MAX_TOKENS} tokens by kb-chunking-rules; need >= {MAX_TOKENS + 2}"
        )

    probe = model.encode_corpus(["dimension probe"], return_sparse=True)
    assert probe["dense_vecs"].shape[1] == DENSE_DIM, "wrong revision or head"
    assert probe["lexical_weights"][0], "sparse head did not load"
    return model


def _to_sparse(weights: dict[str, float]) -> models.SparseVector:
    """FlagEmbedding returns {token_id_as_str: weight}; Qdrant wants parallel
    u32 indices + f32 values. Keep the ids — never `convert_id_to_token`, which
    decodes to strings that no longer index anything."""
    if not weights:
        # All-stopword or whitespace chunk. An empty sparse vector matches
        # nothing and would silently halve the hybrid branch.
        raise ValueError("empty lexical weights")
    pairs = sorted((int(tok), float(w)) for tok, w in weights.items())
    return models.SparseVector(indices=[i for i, _ in pairs],
                               values=[w for _, w in pairs])


def embed_chunks(texts: list[str]) -> list[ChunkVectors]:
    """Ingestion path. `texts` are the exact strings `content_hash` covers —
    heading prefix included (`kb-chunking-rules`)."""
    model = get_model()
    out = model.encode_corpus(texts, return_dense=True, return_sparse=True,
                              return_colbert_vecs=False)
    return [ChunkVectors(dense=d.tolist(), sparse=_to_sparse(w))
            for d, w in zip(out["dense_vecs"], out["lexical_weights"])]


def embed_query(text: str) -> ChunkVectors:
    """Query path. Same model, no prefix, no asymmetry — encode_queries only
    differs from encode_corpus by the (absent) instruction and the max length."""
    out = get_model().encode_queries([text], return_dense=True, return_sparse=True)
    return ChunkVectors(dense=out["dense_vecs"][0].tolist(),
                        sparse=_to_sparse(out["lexical_weights"][0]))
```

Points, named vectors, and `sparse_vectors_config` are `qdrant-hybrid-search`'s. Emit `kb_embedding_chunks_total{model,kind}` and `kb_embedding_batch_duration_seconds{model,kind}` per branch, `model="bge-m3"` from the pinned catalog (`kb-observability-conventions`).

## Gotchas

- **Symptom: retrieval is fine for FAQ and policy chunks and quietly bad for prose and tables, with nothing in the logs.** `passage_max_length` left at its `512` default while the chunker emits up to 700 tokens; `truncation=True` discards the tail. Nothing raises, `kb_embedding_chunks_total` is unaffected, and a smoke test on short fixtures passes. Fix: the startup check above. Verify it with a >600-token fixture whose answer lives in the last sentence.
- **Symptom: the same chunk embeds to different vectors depending on which script indexed it.** Someone used `SentenceTransformer("BAAI/bge-m3")` for a backfill. It reads `max_seq_length: 8192` and does not truncate at all, so its vectors disagree with the FlagEmbedding ones on every long chunk — and it produced no sparse vector, so those points are dense-only and invisible to the sparse branch. One loader, one path.
- **Symptom: hybrid search returns exactly the dense results, and `sparse` rank is null for every candidate.** `return_sparse` defaults to **`False`** on the constructor *and* on every `encode*` method — if it is passed in only one of the two places, some code path silently drops it. `lexical_weights` is then simply absent from the returned dict. Assert on the probe at startup (above) and on the first ingestion batch.
- **Symptom: `TypeError: Object of type float16 is not JSON serializable` on upsert, or weights that round to 0.** With `use_fp16=True`, `lexical_weights` values are `np.float16`; FlagEmbedding's `_convert_to_numpy` upcasts only for **bf16**, not fp16. `float(w)` at the boundary — `_to_sparse` does it.
- **Symptom: `revision` is pinned in code and the model still changes after a rebuild.** `BGEM3FlagModel.__init__` forwards only `trust_remote_code` and `cache_dir` to `from_pretrained`; every other `**kwarg` is `setattr`'d onto the instance and ignored. `revision="…"` becomes a dead attribute. Resolve the pin with `snapshot_download(..., revision=...)` and pass the returned path.
- **Symptom: a worker OOMs on one tenant's upload and processes every other source fine.** `batch_size` defaults to **256**. FlagEmbedding sorts by length and *silently retries at 75% batch size* on `RuntimeError`/`OutOfMemoryError`, so it usually survives — by degrading throughput invisibly and unrepeatably. Set the batch explicitly (16 is a safe start on a 24 GB card at 768 tokens) so throughput is a constant you can measure. <!-- UNVERIFIED: the specific 16 / 24 GB pairing is an estimate; measure with `kb_embedding_batch_duration_seconds` before pinning -->
- **Symptom: CPU embedding is ~4× slower than the fp16 benchmark predicted.** `encode_single_device` calls `self.model.float()` whenever `device == "cpu"`, so `use_fp16=True` is a no-op there. A CPU fallback is an fp32 fallback; budget for it or refuse to start without a GPU.
- **Symptom: two Celery workers on one box exhaust VRAM and the third fails to start.** `devices=None` returns `[f"cuda:{i}" …]` for *all* devices, and `AbsEmbedder.encode` starts a multiprocess pool whenever `len(target_devices) > 1`. Inside a forked Celery worker this multiplies model copies. Pin one device per worker and let the queue provide parallelism (`celery-workers`).
- **Symptom: sparse recall collapses after switching to Qdrant's IDF modifier.** `modifier: idf` is for BM25-style vectors whose values are raw term frequencies needing corpus statistics. BGE-M3's weights are *already* learned term importance; multiplying by IDF double-counts rarity and buries common-but-decisive terms. Leave the modifier unset for the M3 sparse vector. <!-- UNVERIFIED: Qdrant's docs describe the modifier but do not explicitly rule it out for learned-weight models -->
- **Symptom: recall drops after someone "optimizes storage" by shrinking the dense vector.** FlagEmbedding 1.4.0 added `truncate_dim`. BGE-M3 was not trained with a Matryoshka objective, so truncated dimensions are not ordered by information content and quality falls off a cliff rather than degrading gracefully. Never set it. <!-- UNVERIFIED: the paper describes self-knowledge distillation across the three heads and no MRL loss; BAAI has not published MRL results for M3 -->
- **Symptom: an all-CJK or all-Devanagari document indexes with far more sparse terms than an English one of the same length.** Expected — SentencePiece over a 250k multilingual vocab fragments non-Latin scripts harder. Two consequences: token counts must come from the real tokenizer (`kb-chunking-rules`), and the sparse branch is naturally noisier for those languages, so do not tune `sparse.top_k` on an English-only eval set.
- **Symptom: a query in Hindi retrieves nothing from an English corpus that clearly answers it.** Not a bug in the loader — M3 does share a cross-lingual space (MKQA R@100 75.3 dense), but the *sparse* branch is near-useless cross-lingually (45.3) because token ids do not overlap across scripts. Cross-lingual retrieval rides on the dense branch alone; weight the RRF fusion accordingly or the sparse half contributes pure noise.

## Official docs

- [BAAI/bge-m3 model card](https://huggingface.co/BAAI/bge-m3) — the "no longer requires instructions" statement, the three output types, the hybrid+rerank recommendation.
- [FlagEmbedding `m3.py`](https://github.com/FlagOpen/FlagEmbedding/blob/master/FlagEmbedding/inference/embedder/encoder_only/m3.py) and [`AbsEmbedder.py`](https://github.com/FlagOpen/FlagEmbedding/blob/master/FlagEmbedding/abc/inference/AbsEmbedder.py) — every default cited above, plus `_process_token_weights`, the OOM batch shrink, and device fan-out.
- [BGE-M3 paper, arXiv:2402.03216](https://arxiv.org/abs/2402.03216) — the MIRACL / MKQA / MLDR tables behind the dense+sparse decision.
- [BGE documentation hub](https://bge-model.com/) — BAAI's own tutorials and the current model index; check here first when asking whether M3 has been superseded.
- [Sentence Transformers — SparseEncoder](https://sbert.net/docs/sparse_encoder/usage/usage.html) — what ST's sparse support actually covers (SPLADE/CSR), i.e. why it is not a route to M3's lexical weights.
- [Qdrant — sparse vectors](https://qdrant.tech/documentation/concepts/vectors/#sparse-vectors) and [IDF modifier](https://qdrant.tech/documentation/concepts/indexing/#idf-modifier) — the `indices`/`values` shape and the modifier we do not enable.

## Definition of done

- [ ] `get_model()` raises at import/lifespan time when `passage_max_length < MAX_TOKENS + 2`; a unit test flips `MAX_TOKENS` to 4096 and asserts the raise.
- [ ] An integration test embeds a >600-token fixture whose answer is in its final sentence and retrieves it by a query matching only that sentence.
- [ ] `MODEL_REVISION` is resolved through `snapshot_download`; a test asserts the loaded path contains that commit and that `dense_vecs.shape[1] == 1024`.
- [ ] Every ingestion batch produces a non-empty `lexical_weights` dict per chunk; an empty one fails the batch rather than upserting a dead sparse vector.
- [ ] Sparse values are Python `float`, indices Python `int`, sorted ascending; a round-trip test upserts and re-reads one point.
- [ ] No code path calls `SentenceTransformer`, `fastembed`, or the repo's ONNX export for bge-m3; enforced by a grep test.
- [ ] `embedding_model_id` (`bge-m3@5617a9f`) is written to every chunk and included in `ingest_key`; a test changes only the revision and asserts a new source version is minted (`kb-source-lifecycle`).
- [ ] `devices` is set from config to a single device; a test asserts `len(model.target_devices) == 1`.
- [ ] `kb_embedding_chunks_total` and `kb_embedding_batch_duration_seconds` are emitted with `kind` ∈ `dense`/`sparse` and a bounded `model` label.
- [ ] A multilingual fixture set (English, Hindi, Arabic, Chinese) embeds and retrieves; token counts come from the BGE-M3 tokenizer, not `len(text)//4`.
