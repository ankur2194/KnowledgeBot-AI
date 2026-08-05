# Restartable failure recovery

A stage is complete only when its output is committed to PostgreSQL or object storage. On restart, resume at the first stage whose output is missing — never mid-stage, never from the top.

| Safe boundary | Committed output | A retry after it must not |
|---|---|---|
| Acquisition + hashing | object in SeaweedFS, `content_hash` | refetch the URL or re-upload |
| Normalization | `document_elements` | reparse or re-OCR |
| Chunking | `chunks` rows | rechunk |
| Indexing | points under the new version id | re-embed (§13.7) |

Partial OCR success is a configurable outcome, not a failure (§13.7). A permanent unsupported-file error goes straight to `Failed` with no retry — retrying it forever burns a worker slot per source.

Three of those four committed outputs — `document_elements`, `chunks`, and the Qdrant points — are written by the data plane under ADR-012's four-table allow-list, and all of them are written against the **new, not-yet-active** `source_version_id`. That is what makes resumption safe to attempt at all: a half-finished version is invisible, so a retry that redoes work cannot be observed by a tenant. The one boundary that is *not* restartable in the worker is activation, which is Laravel's on the ingestion status callback.
