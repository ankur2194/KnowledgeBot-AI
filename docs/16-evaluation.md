# RAG Quality Evaluation

> Part of the **KnowledgeBot AI** specification — §21, extracted verbatim from `KnowledgeBot-AI.md`.
> Index: [00-index.md](00-index.md)

---

## 21. RAG Quality Evaluation

## 21.1 Why Evaluation Is Required

RAG quality cannot be proven only through a few successful demonstrations.

The project must provide a repeatable evaluation process.

## 21.2 Evaluation Dataset

Create representative questions across:

- Direct factual lookup.
- Exact names and codes.
- Multi-document synthesis.
- Table questions.
- Date-sensitive questions.
- Ambiguous questions.
- Unanswerable questions.
- Conflicting-source questions.
- Follow-up questions.
- Multilingual questions if supported.

Each case should optionally define:

- Expected answer.
- Required facts.
- Expected source or sources.
- Forbidden unsupported claims.
- Grading notes.

## 21.3 Metrics

Track metrics such as:

- Retrieval hit rate.
- Source recall.
- Context precision.
- Answer relevance.
- Faithfulness or groundedness.
- Citation correctness.
- Citation completeness.
- Unanswerable-question refusal accuracy.
- Latency.
- Cost.

Automated metrics must be supplemented with human review.

## 21.4 Experiments

Compare:

- Dense-only versus hybrid retrieval.
- Different chunk sizes.
- Different overlap.
- Different embedding models.
- Different rerankers.
- Different top-K values.
- Query rewriting enabled or disabled.
- Different LLMs.
- Different prompt versions.

Every experiment must record an immutable configuration snapshot.

## 21.5 Regression Gate

A release affecting ingestion, retrieval, prompts, or providers should run a minimum evaluation suite.

A release should be blocked or reviewed when:

- Groundedness decreases beyond tolerance.
- Citation accuracy decreases.
- Unanswerable-question hallucination increases.
- Retrieval hit rate decreases.
- Latency or cost increases unexpectedly.

