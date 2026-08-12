"""The grounded-answer stage runner we own instead of a pipeline framework (ADR-016).

Twenty stages, in a fixed order, each a named function with typed input, typed output, its
own span from a closed catalogue, and its own share of the latency budget. The explicitness
is the product, not a style preference. **Adding a pipeline framework back is a bug, not a
refactor**, and reopening it needs an ADR superseding ADR-016 — never an import, and never a
line in a manifest.

The reasons are specific and were verified against integration source, not inferred:

* **No framework component can carry the tenant filter.** A retriever that accepts a
  filters dictionary accepts it as a *query parameter*, which means it is optional, which
  means it is one refactor away from absent — and an absent filter is a legal, successful,
  cross-tenant query returning HTTP 200. Our filter is positional, required, and raises on
  an empty scope, and no framework's component signature can express that.
* **Framework fusion discards what stage 9 must record.** A joiner returns one fused score
  per document. The per-branch rank *and* score are gone server- or component-side and are
  not recoverable, so the admin playground shows a fused number and dashes beside it. Stage
  9 exists to keep all five numbers.
* **A framework graph is a second naming scheme on one trace.** Its own pipeline and
  component spans arrive beside ``kb.retrieval.*`` as a second root, and neither matches a
  rule, alert, or dashboard query in this repo.
* **Its document model imposes a payload prefix tax** on a payload contract that deletion,
  citation, and the rebuild proof all read by exact key.

What a graph costs when it fails: an opaque runner cannot tell you which stage dropped a
candidate, and "excluded results *and reasons*" is a spec requirement, not a debugging
nicety. A stage that filters without recording makes the whole panel untrustworthy.

**Span names are never derived from this module's name.** There is no span domain called
after this package, however natural one looks from inside it; the catalogued query path is
``kb.query.normalize`` → ``kb.retrieval.filters`` → ``kb.retrieval.dense`` ‖
``kb.retrieval.sparse`` → ``kb.retrieval.fuse`` → ``kb.retrieval.dedupe`` →
``kb.retrieval.rerank`` → ``kb.retrieval.threshold`` → ``kb.context.pack`` →
``kb.prompt.build``, and ``stages.py`` holds it as a lookup that raises rather than minting
a name. The names that keep getting proposed instead are written out once, as the labelled
wrong answer, in ``haystack-pipelines`` — deliberately there and deliberately not here, so a
grep for them lands in the explanation rather than in code. An off-catalogue name errors
nowhere: it exports cleanly and matches no rule, alert, or dashboard query, so the failure
is a retrieval dashboard of empty panels and dead trace-to-logs links — silence in exactly
the surface you would open to debug retrieval.
"""
