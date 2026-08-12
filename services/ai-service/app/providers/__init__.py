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

  ===========  ======  ==========  ==============
  Provider     chat    ``embed``   ``rerank``
  ===========  ======  ==========  ==============
  openai       yes     yes         no
  anthropic    yes     unverified  no
  deepseek     yes     unverified  no
  nvidia_nim   yes     unverified  **yes**
  openrouter   yes     unverified  unverified
  ===========  ======  ==========  ==============

  One sourced embedder and one sourced reranker — not the "two and two" ``contract.py`` used
  to assert without naming either pair. ``unverified`` is a third state and not a synonym for
  ``no``: it gates identically but means the claim has no authority behind it, which is a
  fixture somebody owes rather than a closed question (`docs/23` rows 157–158). The table
  above is documentation; ``capabilities.PROVIDER_TASKS`` is the fact, and
  ``tests/unit/test_provider_capability_matrix.py`` asserts every adapter's methods agree with
  it in both directions.
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
