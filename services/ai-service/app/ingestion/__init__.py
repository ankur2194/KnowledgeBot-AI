"""Upload intake through indexing. Owner: ingestion-engineer.

A stored object becomes searchable vectors through a sequence of resumable stages —
fetch, parse, OCR-if-needed, normalize, chunk, embed, index, verify — each idempotent on the
source-version identity, because Celery reruns stages as a certainty rather than a risk.

Two properties define everything in this package:

* **A source version becomes searchable only when it is fully indexed and verified.** The
  previous version serves until then, and there is no intermediate state in which half a
  document is answerable. The data plane proves the point total and reports readiness; the
  control plane performs the activation (`publish.py`).
* **Every chunk carries the full metadata schema.** Citation and deletion both read it, so a
  chunk missing a field can neither be cited nor reliably removed (`chunking/chunker.py`).

Crawled pages enter this pipeline at the same point an upload does, from `app/crawl/`.

**Parsing and OCR are local; embedding is not.** `parsing/` and `ocr/` run Docling and RapidOCR
against weights in the image — they need no credential, cost nothing per call, and never send a
customer document to a third party. `embedding/` holds no model at all: it calls
`app/providers/`'s `embed` and its real job is deciding what a vector's identity is, since a
vendor alias is not a pin the way a commit sha was.
"""
