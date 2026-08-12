"""Qdrant access and the four mandatory tenant filters. Owner: retrieval-engineer.

Three modules, and the split between them is enforced by CI rather than by review:

* ``tenancy.py`` builds the only ``Filter`` a tenant-facing operation may carry —
  organization, bot access, active source status, active source version — with all four
  terms in ``must``, on every call, with no bypass because none is built.
* ``search.py`` is the **only** module permitted to issue a Qdrant read. The Qdrant-filter
  gate excludes exactly that one path by literal string, so the same call shape written
  anywhere else under ``app/`` fails the build instead of reaching a reviewer.
* ``collection.py`` holds the collection name, both vector configurations, and the payload
  index list in one module, so a Qdrant migration is a single edit rather than a hunt.

What breaks if the boundary is treated as a convention: an unfiltered vector read is a
*legal, successful* query. HTTP 200, normal latency, normal candidate totals, a well-formed
answer carrying well-formed citations that point at a document the organization never
uploaded. No exception, no log line, no metric moves. It is found by a customer rather than
by monitoring, and without the resolved filter recorded on the trace you cannot even bound
which past answers were affected. Nothing below us helps: Qdrant removed payload-restricted
JWT claims in v1.16, and ``is_tenant=True`` is a disk-locality hint that enforces nothing.

``app/ingestion/`` writes points into this collection and ``app/deletion/`` removes them.
Neither may alter its schema — the schema is defined here, in one file, on purpose.
"""
