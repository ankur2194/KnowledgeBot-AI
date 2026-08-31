"""One adapter per vendor, one internal contract. Owner: provider-adapter-engineer.

**ADR-001: there is no LiteLLM gateway and no gateway of any kind.** Each of the five
vendors is called through its own official SDK or documented HTTP surface, and the cost we
accept for that is five modules to maintain against five independently moving APIs. The
cost we refuse is a third party sitting between us and every provider error, every token
count, and every stream event — the three things this platform's correctness, billing and
latency are made of. A gateway normalizes by discarding, and what it discards is exactly
the per-vendor divergence the layer below records.

(OpenRouter is a gateway, and is here as a *provider*, not as our abstraction. It fronts
hundreds of upstreams on one credential, which is why it is the only adapter whose failures
have to be attributed to somebody else before they can be classified.)

The whole job of this package is to make five genuinely different APIs present one shape
upward. Every divergence an adapter fails to absorb becomes a conditional somewhere in the
RAG pipeline, and the pipeline has no way to know which vendor it is talking to.

| Module | Vendor | Transport |
|---|---|---|
| ``openai_adapter.py`` | OpenAI | ``openai`` SDK, Responses API |
| ``anthropic.py`` | Anthropic | ``anthropic`` SDK, Messages API |
| ``deepseek.py`` | DeepSeek | ``openai`` SDK against ``api.deepseek.com`` |
| ``nim.py`` | NVIDIA NIM | ``openai`` SDK against the hosted API Catalog |
| ``openrouter.py`` | OpenRouter | raw ``httpx``, deliberately |

``openai_adapter.py`` is not named ``openai.py``: that filename shadows the SDK on import,
from inside the very package that imports it.

Shared, and imported by all five:

* ``contract.py`` — the request shape, the normalized response, the stream events, the
  capability flags, and the ``ProviderAdapter`` / ``EmbeddingAdapter`` / ``RerankAdapter``
  Protocols.
* ``errors.py`` — vendor code to ``ErrorClass``. Adapters classify; they never decide
  fallback and never call a second vendor themselves.
* ``capabilities.py`` — **which vendors can actually embed and rerank**, as data with a source
  per cell, plus the ``can_embed`` / ``can_rerank`` gate. Under ADR-030 this layer carries
  three task families, not one, and the three do not line up:

  **A table used to sit here and it is gone, which is ADR-036 applied to this file.** It read
  one sourced embedder and one sourced reranker with three ``unverified`` cells, and it went
  false on 2026-08-26 — the day the five wire adapters landed — while the paragraph beneath it
  correctly said ``capabilities.PROVIDER_TASKS`` was the fact and this was only documentation.
  That disclaimer is exactly why it drifted: a copy labelled "documentation" is still a copy,
  and nothing re-reads it. So the answer is now a command rather than a transcription::

      .venv/bin/python -c "from app.providers.capabilities import ProviderSurface, \
      providers_offering as o; print({s.value: sorted(o(s)) for s in ProviderSurface})"

  Two properties of the answer are worth stating because they are rules rather than values, and
  a rule does not go stale. **The three surfaces do not line up** — chat is universal, embedding
  is a subset, rerank is a smaller subset — which is the whole reason ADR-030 made this a
  per-surface question. And **offering a rerank endpoint is not the same as being usable for
  reranking**: ``capabilities.RERANK_SCALE`` decides whether a vendor's scores may be
  thresholded at all, and a provider absent from it is ``UNCALIBRATED``, which ``may_threshold``
  refuses. So the set that can rerank is strictly larger than the set a bot may cut evidence
  against, and reading the first as the second is the mistake this layer exists to prevent.

  ``tests/unit/test_provider_capability_matrix.py`` asserts every adapter's methods agree with
  ``PROVIDER_TASKS`` in both directions, and ``providers_offering`` is pinned by membership
  rather than by size — a two-element set is small enough that a length check passes for the
  wrong reason.
* ``embedding_selection.py`` — **which of an organization's connections supplies the embedding
  credential** (finding C1). ``capabilities.py`` answers whether a ``(vendor, model)`` pair can
  embed at all; this answers which of a tenant's saved connections is the one an ingest run and
  a query must both call. One resolution rule, because the ``(provider, model)`` pair is the
  vector space and a second path would index under one model and query under another with no
  error anywhere. An organization with no embedding-capable connection cannot ingest at all —
  that is ``KbError(ErrorClass.VALIDATION)``, raised at connection-save time as well as on the
  ingestion path, and never a degraded mode.

Read ``.claude/skills/kb-provider-adapter-contract/SKILL.md`` before changing anything here,
and the per-vendor skill for the adapter you are touching — each of those files is the
divergence list for one vendor, and each adapter docstring below restates the divergences
that survive into code.
"""
