"""Qdrant upsert and the count verification that precedes activation.

Points are written under the **new** version id, where retrieval's active-version filter makes
them unreachable — which is what lets the previous version keep serving until the totals are
proven. The collection name, the named vectors and the payload-index set are imported from
`app/retrieval/collection.py`; this package writes points into a collection it does not define.
"""
