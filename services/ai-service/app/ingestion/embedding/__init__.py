"""Passage embedding via the provider adapter layer. No local model, no weights, no device.

Every ML task on this platform is an external API call, so this package holds no embedder. It
calls `app/providers/`'s `embed(texts, *, model)` through an injected callable and never
touches a vendor client or a credential itself.

What it does own is **vector identity**. A local pinned model made `embedding_model_version`
stable by construction; a vendor alias does not. So identity is measured — provider, model id,
the width actually returned, and a digest over a fixed probe set that is the only detector of
a silent weight swap behind an alias. That string rides on every chunk and inside the ingest
key, so a genuine drift re-versions instead of deduping against vectors from a space that no
longer exists.

The silent-truncation defect did not leave with FlagEmbedding — it moved to the model's input
window and to vendor truncation parameters, neither of which is visible in a response. Both
are refused up front rather than discovered as recall that is fine for short chunks and bad
for long ones.

The sparse branch has no producer: API embedding endpoints return dense only. That is finding
C2 and it is open.
"""
