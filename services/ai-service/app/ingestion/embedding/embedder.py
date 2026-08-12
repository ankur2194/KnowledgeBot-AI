"""Passage embedding through the provider adapter layer. There is no local model here.

Every ML task in this platform is an external API call, so the BGE-M3 loader this module used
to be is gone: no `FlagEmbedding`, no `transformers`, no weights, no device. What remains is
the part that was never about PyTorch — deciding what a vector's *identity* is, refusing to
index text the provider may have silently shortened, and batching deterministically so a
replay costs nothing extra.

The provider call itself is not made here. `app/providers/contract.py` exposes
`EmbeddingAdapter.embed(req: EmbeddingRequest, caps, credential) -> EmbeddingResult`, and this
module depends on that seam through `EmbedCallable` below — the same call with the credential,
the capability row and everything fixed for the run already bound. Ingestion never constructs a
vendor client, never reads a credential, and never branches on which vendor is configured.

The conceptual sketch for this family is written `embed(texts, *, model)`, and both this module
and the contract widen it in the same direction and for the same reason: **a call that cannot
name its organization cannot be scoped, metered or traced**, so `org_id` and `trace_id` are on
the signature rather than captured in a closure, and the return is an `EmbeddingResult` rather
than a bare list, because embedding is billed per token against a per-org quota and a bare list
is an unbilled call.

THE PROBLEM THIS MODULE NOW EXISTS FOR: ALIAS DRIFT
---------------------------------------------------
With a local pinned model, `embedding_model_version` was stable *by construction* — a commit
sha in a manifest, resolved to a local path, unable to change under a running worker. With an
API it is only as stable as the vendor's **alias**, and embedding model ids are almost always
aliases: `text-embedding-3-large` has no dated snapshot to pin to. If a vendor re-trains or
re-quantizes behind that name, every vector already in Qdrant is from a different space than
every vector written afterwards. Cosine distance is defined between any two vectors of equal
width, so nothing raises, no total moves, and no metric changes — the only symptom is ranking
that quietly degrades for the older half of the corpus, forever. That is the same failure
`app/retrieval/collection.py` describes for "a name reused across embedding models", except
that here nobody made a decision to trigger it.

So identity is **measured, not declared**, and it has two parts, in `EmbeddingModelIdentity`:

1. `space` — `retrieval-engineer`'s `EmbeddingSpace`: provider, model id, width, distance and
   schema version. It is imported and never restated, because it is what derives the collection
   name, and a second definition of it would let the indexer and the reader compute different
   names from the same facts.
2. `canary_digest` — a hash over the vectors the provider returns for `CANARY_TEXTS`, a fixed
   in-repo probe set. **This is the only available detector of a silent weight swap**, because
   the served-model string is itself just the alias echoed back.

The split is deliberate and it is the load-bearing part of this design. The space is what makes
vectors *comparable* and therefore what names a collection; the digest is what tells us the
space's claim stopped being true. **The digest must never enter the collection name.** If it
did, a vendor blip would silently spawn a second collection, the corpus would divide itself
between two of them, and the automatic repair would look exactly like a healthy bootstrap.

`version` composes both into the one string written to `ChunkMetadata.embedding_model_id` and
folded into the ingest key as `embedding_model_version`. One string, one field, no schema change
— and because it is in the ingest key, a genuine drift makes every resubmission a new version
under a new identity instead of deduping against vectors from a space that no longer exists.

**A digest change is never repaired automatically.** Writing new-space vectors into the existing
collection is the exact failure above; the correct response is a new `EmbeddingSpace`, its own
collection, and a full reindex — an operator decision, not a worker's.

Detection and response are three separate functions on purpose, because each has a different
right place to live: `resolve_identity` measures (one round trip, no memory), `classify_canary`
compares (pure, so the worker and the maintenance sweep cannot reach different conclusions from
the same facts), and `enforce_canary` acts (warn once, fail on confirmation, never repair). What
none of them do is *persist* — the last-known-good digest is already carried by the most recently
activated source version's `embedding_model_version`, and a second store for it would be a second
truth. Paging and the reindex runbook are likewise the caller's; see `enforce_canary`.

THE TRUNCATION GOTCHA, MOVED TO THE NETWORK
-------------------------------------------
FlagEmbedding truncated passages at 512 tokens silently. That defect did not go away with the
library — it moved to two new places and both are worse, because neither is inspectable from
here:

* **A short context window.** `text-embedding-3-large` accepts 8192 tokens and errors above
  it, which is the good case. Plenty of API embedding models accept 512, and a 700-token chunk
  sent to one of those is indexed from its first 512 tokens exactly as before. The window is
  not discoverable from a response, so it is read from the model's control-plane capability row
  and checked against `MAX_TOKENS` in `check_window` before any text is sent.
* **A truncation parameter.** Several vendors take a `truncate`/`truncation` option, and at
  least one defaults it to "trim the end". `PROVIDER_TRUNCATION_POLICY` is `"reject"` and must
  stay that way: we want the 400. A request that silently fits is a chunk whose tail is
  unsearchable and a run that reports success.

Never truncate here either. Sizing is the chunker's job and an over-length chunk must raise —
a defensive trim converts a loud chunker bug into a silent retrieval bug.

THE SPARSE ARM, NOW THAT C2 IS CLOSED
-------------------------------------
BGE-M3 emitted dense *and* learned-sparse vectors from one local model, which is what made the
hybrid design work; API embedding endpoints return dense only. C2 resolved toward local BM25 —
a statistical ranking function, not a model, so ADR-030 does not reach it — and the analyzer,
the pinned parameters and `SPARSE_ANALYZER_VERSION` all live in `app/retrieval/sparse.py`.
`to_sparse_vector` below is a one-line delegation to it and must stay one: a second mapping here
would produce legal `SparseVector`s that match no posting, which raises nothing on either side.
"""

from __future__ import annotations

import hashlib
import math
import time
from collections.abc import Callable, Sequence
from dataclasses import dataclass
from enum import StrEnum
from typing import TYPE_CHECKING, Final, Protocol

from app.core.errors import ErrorClass, KbError, Origin
from app.ingestion.chunking.chunker import MAX_TOKENS
from app.observability.instruments import EMBEDDING_BATCH_DURATION, EMBEDDING_CHUNKS
from app.providers.contract import EmbeddingInputType, EmbeddingResult
from app.retrieval.collection import (
    MAX_DIMENSIONS,
    DimensionMismatch,
    EmbeddingSpace,
    assert_dimensions,
)
from app.retrieval.sparse import EmptySparsePassage, encode_passage

if TYPE_CHECKING:  # pragma: no cover - typing only
    from qdrant_client import models

__all__ = [
    "CANARY_CONFIRMATIONS",
    "CANARY_DIGEST_CHARS",
    "CANARY_INPUT_TYPE",
    "CANARY_PRECISION",
    "CANARY_TEXTS",
    "IDENTITY_SCHEME",
    "MAX_BATCH_TEXTS",
    "NORMALIZE_EMBEDDINGS",
    "PROVIDER_TRUNCATION_POLICY",
    "TOKEN_HEADROOM",
    "CanaryVerdict",
    "ChunkVectors",
    "EmbedCallable",
    "EmbeddingModelIdentity",
    "canary_digest",
    "check_window",
    "classify_canary",
    "embed_passages",
    "enforce_canary",
    "plan_batches",
    "resolve_identity",
    "to_sparse_vector",
]

#: Bump to force every existing vector to be re-identified — it changes every chunk's
#: `embedding_model_id` and therefore every ingest key at once. It exists so the *composition*
#: of the identity string can be corrected without the correction being mistaken for drift.
IDENTITY_SCHEME: Final[str] = "emb/v1"

#: The drift probe. Fixed forever, checked into the repo, and deliberately dull: short ASCII
#: strings owned by nobody, so the probe is never customer text and never leaves an org's
#: content on a vendor's servers for a reason the org did not ask for. Five of them because one
#: string is one point on a manifold and a re-quantization can leave a single vector unmoved.
#:
#: Editing this tuple silently re-identifies the whole corpus. Do not tune it; it is a
#: fingerprint input, not a test fixture.
CANARY_TEXTS: Final[tuple[str, ...]] = (
    "the quick brown fox jumps over the lazy dog",
    "refund policy for annual subscriptions",
    "Row 1 - Plan: Pro; Monthly price: 4999; Seats: 25",
    "SELECT 1",
    "नमस्ते दुनिया",
)

#: **Fixed forever, exactly like `CANARY_TEXTS`, and for the same reason.** Several vendors
#: embed the same string differently depending on which side of the retrieval pair it is
#: declared to be — NVIDIA's models run in query or passage mode and their own schema warns that
#: the wrong value causes "large drops in retrieval accuracy". So the input type is a *fingerprint
#: input*: probing as `QUERY` one day and `PASSAGE` the next moves every component of every probe
#: vector and reads as a vendor weight swap.
#:
#: `PASSAGE`, because that is what ingestion embeds. A probe taken through a different code path
#: than the corpus is a probe that can agree while the corpus's own path has changed.
CANARY_INPUT_TYPE: Final[EmbeddingInputType] = EmbeddingInputType.PASSAGE

#: Decimal places kept before hashing a probe vector. Embedding APIs are not bit-reproducible
#: across their own fleet — different accelerators, different batch shapes and different kernel
#: versions move the last few bits — so hashing raw floats produces a digest that changes on
#: every deploy of *theirs* and re-versions our whole corpus for nothing. Four places is coarse
#: enough to absorb that and fine enough that a re-trained model cannot land inside it.
#:
#: This number is the one input here that has not been measured against a live provider. Before
#: the digest is allowed to gate anything, run the probe hourly for a day against each
#: configured provider and confirm the digest is constant; if it flaps, this is the knob, and
#: the finding is that the digest belongs in telemetry only.
CANARY_PRECISION: Final[int] = 4

#: How many consecutive probes must agree on a *new* digest before it is accepted as a genuine
#: identity change rather than a blip. One differing probe is a warning; `CANARY_CONFIRMATIONS`
#: of them is a reindex conversation. Neither is ever an automatic re-embed.
CANARY_CONFIRMATIONS: Final[int] = 2

#: Hex characters kept from the sha256. Enough that two genuinely different models cannot
#: collide in practice, short enough that `EmbeddingModelIdentity.version` stays readable in a
#: log line and a `chunks` row. The digest is only ever *compared*, never searched for, so this
#: is a legibility choice rather than a security one — but it is a **fingerprint input**: cutting
#: the string at a different length re-identifies the whole corpus exactly as editing
#: `CANARY_TEXTS` would.
CANARY_DIGEST_CHARS: Final[int] = 12

#: Texts per provider request. Small and fixed rather than tuned, because batching here is a
#: **cost** knob, not a throughput knob: a redelivered task re-embeds and re-bills whatever a
#: batch holds, so the batch is the checkpoint granularity. Providers additionally cap the
#: array length and the per-request token total, and both caps differ per vendor — a batch that
#: exceeds either is a 400, which is the loud failure we want.
MAX_BATCH_TEXTS: Final[int] = 64

#: Tokens left un-spent below the model's window when `check_window` compares it against
#: `MAX_TOKENS`. Covers the sentinel pair every tokenizer adds plus the gap between our
#: measured total and the vendor's own tokenizer, which is a different algorithm over a
#: different vocabulary and diverges most on non-Latin scripts — exactly where a silent trim
#: would be least noticed.
TOKEN_HEADROOM: Final[int] = 64

#: We want the vendor's 400. Never `"end"`, `"start"` or `"auto"`: a request that silently fits
#: is a chunk indexed from its first N tokens with its tail unsearchable, reported as success.
PROVIDER_TRUNCATION_POLICY: Final[str] = "reject"

#: Vectors are L2-normalized before they are written, on our side, whatever the provider does.
#: It is part of index identity — flipping it invalidates every vector in the collection while
#: every score still looks like a plausible number — and it is the only way two providers'
#: outputs can share one cosine collection at all.
NORMALIZE_EMBEDDINGS: Final[bool] = True


class EmbedCallable(Protocol):
    """The one provider-layer function this package calls, with its credential already bound.

    A **structural view** of `app/providers/contract.py`'s `EmbeddingAdapter.embed`, not a
    second definition of it: it exists so ingestion can be typed and tested before a binder
    lands, and so the seam is greppable. Every parameter below is a field of the contract's
    `EmbeddingRequest` and the return type is the contract's `EmbeddingResult`, imported rather
    than restated. The moment `app/providers/` exports a bound-callable type of its own, this is
    replaced by an import of it.

    WHAT IS BOUND, AND WHAT IS NOT
    ------------------------------
    Bound by the caller, because all of it is fixed for the whole run and must be **identical
    for the drift probe and for every passage** — a probe taken through a different request
    shape than the corpus is a probe that can agree while the corpus's own path has changed:
    `model`, `dimensions`, `provider_connection_id`, `timeouts`, the capability row, and the
    credential.

    Passed explicitly, because each one varies per call and something downstream reads it:

    * `org_id` — **tenant isolation is enforced in code at every layer, and a call that cannot
      name its organization cannot be scoped, metered or traced.** This parameter was missing,
      with the scoping left implicit in a closure; that is `contract.py`'s stated objection to a
      bare `(texts, model)` signature and it applied here word for word. Note that it applies
      even to the canary probe, whose text is ours rather than a tenant's: the probe still
      spends *an organization's* credential and quota, so it must be attributed to one.
    * `trace_id` — one trace across both planes; a span that cannot be joined is a span nobody
      reads during an incident.
    * `input_type` — no default, deliberately, upstream and here. Several vendors embed the same
      string differently in query and passage mode and at least one documents a large recall
      drop for the wrong value, while others take no such parameter at all — so a default is
      correct on two vendors, silently wrong on a third, and invisible on all three.

    Still absent, and this has not changed: **no credential.** Resolving and decrypting one is
    the provider layer's business and the caller passes an already-bound callable in, so nothing
    under `app/ingestion/` can hold a secret, log one, or serialize one into a Celery payload on
    the broker, a `failed_jobs` record, or a span.

    Synchronous, while the adapter's `embed` is a coroutine. Bridging the two is the binder's
    job: ingestion runs inside a Celery task, and a package that reached for `asyncio.run` here
    would own an event-loop policy it has no business owning.
    """

    def __call__(
        self,
        texts: list[str],
        *,
        input_type: EmbeddingInputType,
        org_id: str,
        trace_id: str,
    ) -> EmbeddingResult:
        """Dense vectors, one per input text, **in input order**, plus the measured space.

        `EmbeddingResult` rather than a bare `list[list[float]]` for two reasons that are not
        stylistic: a bare list discards `usage`, and embedding is billed per token against the
        same per-org quota as chat — so a bare list is an unbilled call. And `result.space`
        carries the width the provider *actually returned*, which is the only measurement
        `resolve_identity` has to check a configured space against.
        """
        ...


@dataclass(frozen=True, slots=True)
class EmbeddingModelIdentity:
    """What a vector was made by — measured, not declared. See the module docstring.

    Frozen because it is folded into the ingest key: an identity that can be mutated after the
    key is composed produces points whose payload disagrees with the key that admitted them.
    """

    #: `retrieval-engineer`'s `EmbeddingSpace` — provider, model, width, distance, schema
    #: version. Imported, never restated: it is what derives the collection name, and two
    #: definitions of it would let the indexer and the reader compute different names from the
    #: same facts, which reads as an empty corpus rather than as an error.
    space: EmbeddingSpace
    #: `canary_digest(...)` over `CANARY_TEXTS`. The drift detector, and deliberately NOT part
    #: of `space`: a digest inside the space would put its value in the collection name, and a
    #: vendor blip would then silently open a second collection instead of failing.
    canary_digest: str

    @property
    def version(self) -> str:
        """`emb/v1:openai:text-embedding-3-large:d3072:9f2a1c4e77b1`

        Written verbatim as `ChunkMetadata.embedding_model_id`, and passed verbatim as the
        `embedding_model_version` component of `app.ingestion.identity.ingest_key`. One value,
        one meaning, two field names that already existed — deliberately not a new metadata
        field, because the chunk schema is fixed and a partially-populated schema is the one
        defect that breaks citation and deletion at the same time.

        Readable rather than `space.collection`, and carrying the digest the collection name
        must not. Every component is here on purpose: dropping the width lets a Matryoshka
        change pass as the same model, and dropping the digest is the alias-drift hole this
        module is arranged around. The collection remains derivable at any time through
        `space.collection`, which is the authority on where these vectors live.

        The string is **compared, never parsed back**. A vendor model id may itself contain a
        colon, so splitting this on `:` is not reliable — every component is already available
        individually on `space` and `canary_digest`, which is where a reader should take them
        from.
        """
        if not self.canary_digest:
            raise KbError(
                ErrorClass.INTERNAL_DEPENDENCY,
                "an embedding identity with an empty canary digest is a bare alias wearing "
                "the identity's name: it is exactly the value that cannot detect a vendor "
                "weight swap, and it must not reach a chunk or an ingest key",
                origin=Origin.SELF,
            )
        return (
            f"{IDENTITY_SCHEME}:{self.space.provider}:{self.space.model}"
            f":d{self.space.dimensions}:{self.canary_digest}"
        )


@dataclass(frozen=True, slots=True)
class ChunkVectors:
    """The vectors for one chunk. They ride on a single point, so the branches can never
    disagree about which chunks exist.

    `sparse` is `None`-able for exactly **one** reason, and it is no longer "pending finding
    C2": C2 is closed and `app/retrieval/sparse.py` produces the document-side vector from a
    local BM25 analyzer — a statistical ranking function, not a model, so ADR-030 does not reach
    it. The remaining `None` is `EmptySparsePassage`: a chunk that analyzes to no terms at all,
    which is a real case (an image-only chunk, a table of bare numerals) and not a defect.

    A `None` must therefore be a **decision that reached the upsert**, recorded as one, never a
    silently missing branch. `indexing/upserter.py` enforces the distinction, because an *empty*
    sparse vector is accepted by Qdrant, matches nothing forever, and halves the hybrid branch
    for that chunk with no error on either side.
    """

    dense: list[float]
    sparse: models.SparseVector | None


def canary_digest(vectors: Sequence[Sequence[float]]) -> str:
    """Fingerprint the provider's actual output over `CANARY_TEXTS`.

    sha256 over each vector's components rounded to `CANARY_PRECISION` decimal places, in
    probe order then component order, with the probe index and the vector width mixed in so a
    reordered or re-widened response cannot collide with an unchanged one.

    Rounding is the whole design: see `CANARY_PRECISION`. Hashing raw floats detects vendor
    fleet noise as a model change; hashing nothing detects a model change as nothing.

    Raises on a vector width that differs between probes, on an empty vector, and on a probe
    total that is not `len(CANARY_TEXTS)` — each of those means the response was not what was
    asked for, and a digest computed over a malformed response is a fingerprint of a bug.

    THE EXACT COMPOSITION, because "a hash over the probe vectors" is not a specification and
    two implementations of that sentence would disagree on every input:

        line[i] = f"{i}:{width}:" + ",".join(component)   # probe order, then component order
        payload = f"{IDENTITY_SCHEME}|{probes}|{CANARY_PRECISION}\\n" + "\\n".join(line)
        digest  = sha256(payload.encode("utf-8")).hexdigest()[:CANARY_DIGEST_CHARS]

    where `component` is `format(value, f".{CANARY_PRECISION}f")` with negative zero folded
    onto positive zero. Four details in there are load-bearing and each has a way of failing
    quietly:

    * **Fixed-point formatting, not `round()`.** `repr(round(x, 4))` is shortest-repr and emits
      `0.1` for one float and `0.09999999999999999` for its neighbour, so the "rounded" digest
      would still change on the last bits — which is the entire failure `CANARY_PRECISION`
      exists to absorb.
    * **Negative zero is folded.** `-0.00004` and `+0.00004` are the same vector to this
      precision, but format to `-0.0000` and `0.0000`. Left alone, a component sitting on zero
      flips the digest on vendor fleet noise, which is the false positive that makes an
      operator stop believing the detector.
    * **The probe index and the width are inside the payload**, so a reordered or re-widened
      response cannot collide with an unchanged one. A vendor that returns vectors out of order
      is a real documented case, and one that silently truncates the width is precisely what
      this whole module is about.
    * **The scheme and precision are inside the payload**, so changing either is a deliberate,
      visible re-identification rather than a digest that shifts for a reason nobody recorded.

    Non-finite components raise: a NaN formats to a perfectly stable `nan` and would give a
    reproducible fingerprint of garbage.
    """
    probes = list(vectors)
    if len(probes) != len(CANARY_TEXTS):
        raise _probe_malformed(f"expected {len(CANARY_TEXTS)} probe vectors, got {len(probes)}")

    width = len(probes[0])
    if width == 0:
        raise _probe_malformed("probe 0 came back as a zero-width vector")

    lines: list[str] = []
    for index, vector in enumerate(probes):
        if len(vector) != width:
            raise _probe_malformed(
                f"probe {index} is {len(vector)}-dim while probe 0 is {width}-dim; a digest "
                "over a ragged response fingerprints the bug, not the model"
            )
        components: list[str] = []
        for value in vector:
            number = float(value)
            if not math.isfinite(number):
                raise _probe_malformed(
                    f"probe {index} contains a non-finite component ({number!r})"
                )
            text = format(number, f".{CANARY_PRECISION}f")
            if float(text) == 0.0:  # folds "-0.0000" onto "0.0000"
                text = format(0.0, f".{CANARY_PRECISION}f")
            components.append(text)
        lines.append(f"{index}:{width}:" + ",".join(components))

    payload = f"{IDENTITY_SCHEME}|{len(probes)}|{CANARY_PRECISION}\n" + "\n".join(lines)
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()[:CANARY_DIGEST_CHARS]


def _probe_malformed(detail: str) -> KbError:
    """`internal_dependency` / `DOWNSTREAM`: the dependency changed shape under us.

    Not `provider_permanent_request` — nothing about the request was wrong — and not retryable
    in practice, because the next probe sends the identical five strings and gets the identical
    answer back.
    """
    return KbError(
        ErrorClass.INTERNAL_DEPENDENCY,
        f"embedding probe response is malformed: {detail}",
        origin=Origin.DOWNSTREAM,
    )


def check_window(context_window: int, *, model: str) -> None:
    """Refuse a model whose input window cannot hold a maximum-size chunk.

    `context_window` comes from the model's control-plane capability row
    (`ModelCapabilities.context_window`), because it is **not discoverable from a response**: a
    provider that trims a long input returns HTTP 200 and a perfectly plausible vector. This is
    the direct successor of the load-time `passage_max_length` assertion the local loader
    carried, and it is the only place the check can happen at all.

    Raises `KbError(ErrorClass.VALIDATION, ...)` when `context_window` is below
    `MAX_TOKENS + TOKEN_HEADROOM`, naming both numbers and the model. Validation, not
    `provider_permanent_request`: nothing about the vendor is broken, our configuration is —
    and it is never retryable, because the next attempt sends the identical request.

    Called from `resolve_identity`, once, before any customer text is sent. Deliberately not
    per batch: it cannot change under a run, and a per-batch check is a per-batch chance to
    handle it by trimming.

    A non-positive window is rejected separately and says so: it means the capability row was
    never populated, and a zero silently compared as "too small" would report a configured
    model as under-specified rather than as unconfigured.
    """
    if context_window <= 0:
        raise KbError(
            ErrorClass.VALIDATION,
            f"{model!r} has no usable context window ({context_window}); the model's "
            "capability row was never populated, so nothing here can tell whether a chunk "
            "fits — and a provider that trims a long input answers 200 with a plausible "
            "vector, so this cannot be discovered later from a response",
        )
    required = MAX_TOKENS + TOKEN_HEADROOM
    if context_window < required:
        raise KbError(
            ErrorClass.VALIDATION,
            f"{model!r} accepts {context_window} tokens but a chunk may reach "
            f"{MAX_TOKENS} plus {TOKEN_HEADROOM} tokens of headroom ({required}); indexing "
            "with it would silently store each over-length chunk from its first "
            f"{context_window} tokens, with the tail unsearchable forever and the run "
            "reporting success. Configure a model with a larger window or lower MAX_TOKENS "
            "and reprocess — never trim here",
        )


def resolve_identity(
    *,
    embed: EmbedCallable,
    space: EmbeddingSpace,
    context_window: int,
    org_id: str,
    trace_id: str,
) -> EmbeddingModelIdentity:
    """Probe the configured model and return the identity every chunk of this run carries.

    Five short strings and one round trip. It runs once per worker process and again on the
    maintenance sweep — never per document and never per batch, which is what keeps the drift
    detector affordable.

    THE ORDER IS PART OF THE SPECIFICATION, not an implementation detail — steps 1 and 2 in
    the other order send customer-shaped traffic to a model already known to be unusable, and
    steps 3 and 4 in the other order fingerprint a response that was never valid:

    1. `check_window(context_window, model=space.model)` — before any text leaves the process.
    2. `embed(list(CANARY_TEXTS), input_type=CANARY_INPUT_TYPE, ...)`. Exactly the five probes,
       in tuple order, through the same bound callable the passages will use.
    3. `len(result.vectors) == len(CANARY_TEXTS)`, then
       `collection.assert_dimensions(space, len(vector))` on **every** probe vector — not just
       the first, because a vendor that truncates one long input truncates it alone — then
       `result.space == space`. The space is fixed when its collection is created, so a
       mismatch is not a configuration change, it is a reindex, and that function's message
       already says so. Accepting it here would put two incomparable spaces in one collection,
       which never errors.
    4. `canary_digest(result.vectors)`.

    The `result.space == space` check is the strongest of the four and is only possible because
    the provider layer measures the width off its own response rather than looking it up by
    model id. It catches the case `assert_dimensions` cannot: two models that agree on width
    and on nothing else — `baai/bge-m3`, `nvidia/nv-embedqa-e5-v5` and `text-embedding-3-large`
    truncated to 1024 are all 1024-wide, mutually meaningless, and every one of them upserts
    cleanly.

    **A digest that differs from the recorded one is not detected here.** This function has no
    memory: it returns what the provider *is* today, and comparing that against what it was is
    `classify_canary`, which is pure and separate precisely so the comparison can be made by
    whichever caller holds the recorded value. What is guaranteed here is that a digest is
    always produced, so there is always something to compare.

    Failure classes, all from the provider layer's own mapping: `provider_auth`,
    `provider_rate_limit`, `provider_billing`, `provider_temporary`,
    `provider_permanent_request`. A width or space mismatch is `internal_dependency` with
    `Origin.DOWNSTREAM` — the dependency changed under us — and is not retryable in practice
    because the next probe returns the same new vectors. `DimensionMismatch` from the
    collection module is translated rather than propagated, so one taxonomy covers the stage.

    `result.normalized` is deliberately not acted on: the indexer L2-normalizes on our side
    regardless (`NORMALIZE_EMBEDDINGS`), so a False is a finding for the caller to record, not
    a failure to raise — and it must never enter the identity, for the same reason the digest
    must never enter the space.
    """
    check_window(context_window, model=space.model)

    result = embed(
        list(CANARY_TEXTS),
        input_type=CANARY_INPUT_TYPE,
        org_id=org_id,
        trace_id=trace_id,
    )

    if len(result.vectors) != len(CANARY_TEXTS):
        raise _probe_malformed(
            f"asked for {len(CANARY_TEXTS)} probe vectors and got {len(result.vectors)}"
        )
    try:
        for vector in result.vectors:
            assert_dimensions(space, len(vector))
    except DimensionMismatch as exc:
        raise KbError(ErrorClass.INTERNAL_DEPENDENCY, str(exc), origin=Origin.DOWNSTREAM) from exc
    if result.space != space:
        raise KbError(
            ErrorClass.INTERNAL_DEPENDENCY,
            f"the provider measured its own output as {result.space!r} while this run is "
            f"configured for {space!r}; those name two different collections "
            f"({result.space.collection} and {space.collection}), and writing one space's "
            "vectors into the other's collection never raises — it only ranks wrongly",
            origin=Origin.DOWNSTREAM,
        )

    return EmbeddingModelIdentity(space=space, canary_digest=canary_digest(result.vectors))


class CanaryVerdict(StrEnum):
    """What a freshly measured identity means next to the last recorded one.

    Five values rather than a boolean, because "the digests differ" covers three situations
    with three different correct responses, and collapsing them is how a vendor blip becomes a
    corpus split.
    """

    #: Nothing recorded yet for this space. Not drift and not evidence of anything: it is the
    #: first ingest into a new collection, and the caller's job is to record the digest.
    FIRST_OBSERVATION = "first_observation"
    #: Same space, same digest. The ordinary path.
    MATCH = "match"
    #: **Different space** — provider, model, width, distance or schema version moved. This is a
    #: configured change with its own collection, not drift, and it must never be reported as
    #: drift: the two spaces are *supposed* to differ and the old one keeps serving until the
    #: new one is fully indexed and activated.
    DIFFERENT_SPACE = "different_space"
    #: Same space, different digest, seen fewer than `CANARY_CONFIRMATIONS` times in a row.
    #: A warning. Embedding APIs are not bit-reproducible across their own fleet, so one
    #: disagreement is at least as likely to be their deploy as their retraining.
    SUSPECTED_DRIFT = "suspected_drift"
    #: Same space, different digest, `CANARY_CONFIRMATIONS` consecutive times. The alias moved.
    CONFIRMED_DRIFT = "confirmed_drift"


def classify_canary(
    *,
    recorded: EmbeddingModelIdentity | None,
    observed: EmbeddingModelIdentity,
    consecutive_disagreements: int,
) -> CanaryVerdict:
    """Compare a freshly resolved identity against the last recorded one. Pure.

    No clock, no storage, no I/O and no side effect, so the same three inputs always give the
    same verdict — which is what lets the worker and the maintenance sweep reach the same
    conclusion from the same facts without sharing code paths.

    `consecutive_disagreements` counts probes that have already disagreed **including this
    one**, and is the caller's to persist: a counter this function kept would reset on every
    worker restart, and a worker restart is the single most likely thing to happen during a
    provider incident.

    `DIFFERENT_SPACE` is checked before the digest and that order is the whole point. A
    reconfigured model changes the space *and* the digest, and reporting it as drift would page
    someone for a change an operator made deliberately five minutes earlier — while the reverse
    mistake, reporting genuine drift as a reconfiguration, is the corpus-splitting failure this
    module is arranged around.
    """
    if recorded is None:
        return CanaryVerdict.FIRST_OBSERVATION
    if recorded.space != observed.space:
        return CanaryVerdict.DIFFERENT_SPACE
    if recorded.canary_digest == observed.canary_digest:
        return CanaryVerdict.MATCH
    if consecutive_disagreements >= CANARY_CONFIRMATIONS:
        return CanaryVerdict.CONFIRMED_DRIFT
    return CanaryVerdict.SUSPECTED_DRIFT


def enforce_canary(verdict: CanaryVerdict, *, identity: EmbeddingModelIdentity) -> list[str]:
    """Turn a verdict into the run's outcome. Returns warnings; raises to stop the run.

    **This never repairs anything, and that is the point.** The tempting response to a moved
    alias — re-embed into the current collection so retrieval keeps working — is precisely the
    failure: it puts two incomparable spaces in one collection, where cosine distance is defined
    between any two vectors of equal width, so nothing raises and only the ranking of the older
    half degrades, forever. The correct response is a new `EmbeddingSpace`, its own collection,
    and a full reindex, published atomically like any other version. That is an operator
    decision with a cost, not something a worker may take on its own.

    What each verdict does here:

    * `FIRST_OBSERVATION`, `MATCH` — proceed, no warning.
    * `DIFFERENT_SPACE` — proceed, no warning. It is a different collection; nothing is mixed.
    * `SUSPECTED_DRIFT` — proceed **and warn**. Deliberately not a failure: one disagreement is
      more likely to be the vendor's own fleet noise, and stopping ingestion platform-wide on
      it hands the vendor an outage switch. The warning is what makes the second observation
      meaningful instead of surprising.
    * `CONFIRMED_DRIFT` — raise. `internal_dependency` / `DOWNSTREAM`, and not retryable in
      practice: the next probe returns the same new vectors.

    WHAT THIS FUNCTION DOES NOT DO, AND WHY IT IS LEFT TO THE CALLER
    ---------------------------------------------------------------
    * **Persist the digest or the disagreement counter.** Both are rows, and rows are
      PostgreSQL's. Ingestion inventing a table here would be a second source of truth for
      something the source version's own `embedding_model_version` already records — that
      column *is* the last-known-good digest, for every space that has ever published.
    * **Page.** Alerting reads the metric and the `error_class`; a module that paged directly
      would page from every worker replica at once.
    * **Decide when a reindex happens.** That is a runbook and it does not exist yet; it is
      what decides whether a tenant's bot answers from a half-migrated corpus in the meantime.
    """
    if verdict is CanaryVerdict.SUSPECTED_DRIFT:
        return [f"embedding_canary_suspected_drift:{identity.canary_digest}"]
    if verdict is CanaryVerdict.CONFIRMED_DRIFT:
        raise KbError(
            ErrorClass.INTERNAL_DEPENDENCY,
            f"the embedding model behind {identity.space.provider}/{identity.space.model} "
            f"returned different vectors for the fixed probe set {CANARY_CONFIRMATIONS} "
            f"consecutive times (now {identity.canary_digest}). The alias has been re-pointed "
            f"at different weights: every vector already in {identity.space.collection} came "
            "from a model that no longer exists. Do NOT re-embed into that collection — cosine "
            "distance is defined between any two vectors of equal width, so mixing the two "
            "spaces raises nothing and only degrades ranking for the older half. This needs an "
            "operator-scheduled reindex into a new space",
            origin=Origin.DOWNSTREAM,
            retryable=False,
        )
    return []


def plan_batches(texts: Sequence[str], measure: Callable[[str], int]) -> list[tuple[int, int]]:
    """Half-open `[start, stop)` index ranges over `texts`, in document order.

    Pure, deterministic, and a function of `(texts, measure)` alone — no clock, no set
    iteration, no concurrency. Determinism is a **cost** property here as much as a
    correctness one: the batch is the checkpoint and the redelivery unit, so a batch boundary
    that moves between runs re-embeds and re-bills text that was already paid for.

    Bounded by `MAX_BATCH_TEXTS`. Raises `KbError(ErrorClass.VALIDATION, ...)` on any single
    text measuring above `MAX_TOKENS` — that is a chunker defect and it must surface as one,
    loudly, rather than as a batch that quietly splits around it. The check runs over **every**
    text before any range is produced, so a defective chunker is reported as itself rather than
    as the first batch that happened to contain its output.

    THERE IS NO PER-REQUEST TOKEN BOUND HERE, AND THAT IS A DECISION
    ---------------------------------------------------------------
    This used to promise bounding "by the model's per-request token ceiling, whichever binds
    first". **That ceiling exists nowhere reachable.** This function takes no capability row,
    and `ModelCapabilities` — which `validate_embedding`'s own docstring claims carries the
    batch caps — is `frozen, strict, extra="forbid"` with four fields and no such caps on it.
    That model arrives *from Laravel*, so `extra="forbid"` makes adding a field a migration, a
    serializer change and an `X-KB-Config-Version` bump on both planes. A clause no code can
    keep is worse than an absent one: it reads as a bound that is being enforced.

    Measured against the majors this is not currently close: 64 texts x `MAX_TOKENS` is under
    45 000 tokens per request, where OpenAI's embeddings endpoint documents 300 000 and the
    array caps that do bite (Cohere's 96) sit above `MAX_BATCH_TEXTS`. If a configured provider
    ever caps lower, the fix is a `MAX_BATCH_TOKENS` module constant here — a pure cost knob,
    folded into nothing, changing no vector — and **not** a capability-row field.

    `measure` is injected, as it is into the chunker, and it is now a **provider-dependent**
    estimate rather than the model's own tokenizer: there is no local tokenizer to consult. It
    must therefore over-estimate rather than under-estimate — an over-estimate costs a smaller
    batch, an under-estimate costs a silently trimmed passage. Which measurement supplies it is
    an open decision; see the report.
    """
    for index, text in enumerate(texts):
        tokens = measure(text)
        if tokens > MAX_TOKENS:
            raise KbError(
                ErrorClass.VALIDATION,
                f"chunk {index} measures {tokens} tokens against a {MAX_TOKENS} ceiling. "
                "Sizing is the chunker's job and this is a chunker defect: splitting the batch "
                "around it would send the over-length text anyway, and trimming it here would "
                "convert a loud bug into a chunk whose tail is unsearchable forever",
            )

    total = len(texts)
    return [
        (start, min(start + MAX_BATCH_TEXTS, total)) for start in range(0, total, MAX_BATCH_TEXTS)
    ]


def embed_passages(
    texts: Sequence[str],
    *,
    embed: EmbedCallable,
    identity: EmbeddingModelIdentity,
    measure: Callable[[str], int],
    org_id: str,
    trace_id: str,
) -> list[ChunkVectors]:
    """Embed chunk texts in document order. `texts` are the exact strings `content_hash`
    covers, heading prefix included.

    `org_id` and `trace_id` travel per call rather than being captured once, for the reason
    `EmbedCallable` records: a call that cannot name its organization cannot be scoped, metered
    or traced, and this is the call that spends a tenant's embedding budget. `input_type` is
    fixed to `CANARY_INPUT_TYPE`'s value here — this *is* the passage side, and it must match
    the probe's, or the drift detector fingerprints a request shape the corpus never uses.

    Batches through `plan_batches`, calls `embed` per batch, and checks per batch that the
    returned vector total equals the batch size and that every width passes
    `collection.assert_dimensions(identity.space, ...)`. A provider that returns fewer vectors
    than inputs would otherwise shift every subsequent chunk's vector onto the wrong chunk — an
    off-by-one that produces a fully populated, fully wrong index and raises nothing.

    Normalizes when `NORMALIZE_EMBEDDINGS`, and never trims: an over-length text raises from
    `plan_batches` before the request is built.

    Emits `kb_embedding_chunks_total{model,kind}` and `kb_embedding_batch_duration_seconds`,
    with `model` bounded — the label is `identity.version`, which is bounded by the number of
    configured models, not by the number of tenants.

    Re-running this for the same version is safe and produces the same *points* but not
    necessarily bit-identical *vectors*: providers are not bit-reproducible. That is fine and
    is why verification counts points rather than comparing vectors, and why the readiness
    checksum is taken over chunk content hashes rather than over embeddings.

    THE SPARSE BRANCH RIDES ALONG, AND HAS TO
    -----------------------------------------
    `ChunkVectors` pairs the two vectors so the branches can never disagree about which chunks
    exist, and this is the only function that builds one. Producing dense here and sparse
    somewhere else would make that pairing a convention rather than a guarantee. So each chunk's
    sparse vector is computed from the **exact string that was embedded**, and an
    `EmptySparsePassage` becomes a recorded `None` — a decision that reached the upsert, never a
    silently missing branch. An *empty* sparse vector is accepted by Qdrant, matches nothing
    forever, and halves the hybrid arm for that chunk with no error on either side.

    **This function has no production caller yet**, and that is expected rather than a gap: the
    binder that turns `EmbeddingAdapter.embed` (a coroutine, with the credential and capability
    row bound) into an `EmbedCallable` does not exist, and no adapter implements `embed()` yet.
    The body is complete against the Protocol, which is why `EmbedCallable` is a Protocol.
    """
    plan = plan_batches(texts, measure)
    label = {"model": identity.version, "kind": "dense"}

    dense: list[list[float]] = []
    for start, stop in plan:
        batch = list(texts[start:stop])
        began = time.perf_counter()
        result = embed(
            batch,
            input_type=CANARY_INPUT_TYPE,
            org_id=org_id,
            trace_id=trace_id,
        )
        EMBEDDING_BATCH_DURATION.record(time.perf_counter() - began, label)

        if len(result.vectors) != len(batch):
            raise KbError(
                ErrorClass.INTERNAL_DEPENDENCY,
                f"the provider returned {len(result.vectors)} vectors for a batch of "
                f"{len(batch)} texts (chunks {start}..{stop}). Accepting the short list would "
                "shift every subsequent chunk's vector onto the wrong chunk — a fully "
                "populated, fully wrong index that raises nothing and counts correctly",
                origin=Origin.DOWNSTREAM,
            )
        try:
            for vector in result.vectors:
                assert_dimensions(identity.space, len(vector))
        except DimensionMismatch as exc:
            raise KbError(
                ErrorClass.INTERNAL_DEPENDENCY, str(exc), origin=Origin.DOWNSTREAM
            ) from exc
        dense.extend(_unit_vector(vector, identity) for vector in result.vectors)

    vectors: list[ChunkVectors] = []
    empty_sparse = 0
    for text, embedding in zip(texts, dense, strict=True):
        try:
            sparse: models.SparseVector | None = to_sparse_vector(text)
        except EmptySparsePassage:
            sparse = None
            empty_sparse += 1
        vectors.append(ChunkVectors(dense=embedding, sparse=sparse))

    EMBEDDING_CHUNKS.add(len(vectors), label)
    EMBEDDING_CHUNKS.add(len(vectors) - empty_sparse, {"model": identity.version, "kind": "sparse"})
    return vectors


def _unit_vector(vector: Sequence[float], identity: EmbeddingModelIdentity) -> list[float]:
    """L2-normalize when `NORMALIZE_EMBEDDINGS`, and validate the norm either way.

    The norm is computed regardless of the flag because computing it *is* the validation, and
    both things it catches are silent. A zero vector has no direction, so every cosine score
    against it is 0 or undefined and the chunk simply never ranks. A non-finite component makes
    every score `NaN`, and `NaN` compares false against every threshold — so the chunk is
    dropped from every result set with no error, on a provider response that was HTTP 200.
    Summing the squares touches every component anyway, so the check costs nothing extra.

    Never a trim and never a substitution. A vector we cannot use is a failed batch, not a
    quietly zeroed row in the index.
    """
    norm = math.sqrt(sum(float(component) * float(component) for component in vector))
    if not math.isfinite(norm) or norm == 0.0:
        raise KbError(
            ErrorClass.INTERNAL_DEPENDENCY,
            f"{identity.space.provider}/{identity.space.model} returned a vector whose L2 norm "
            f"is {norm}; a zero or non-finite vector is accepted by every upsert and then "
            "ranks against nothing forever, with no error on either side",
            origin=Origin.DOWNSTREAM,
        )
    if not NORMALIZE_EMBEDDINGS:
        return [float(component) for component in vector]
    return [float(component) / norm for component in vector]


def to_sparse_vector(text: str) -> models.SparseVector:
    """The chunk's document-side lexical vector — **delegated, never computed here.**

    This used to be `to_sparse_vector(weights: dict[str, float])`, a second definition of an
    encoder that now exists, with the term ids typed as `str` where they are `int`. Finding C2
    is closed: `app/retrieval/sparse.py` owns the analyzer, the three pinned BM25 parameters,
    and `SPARSE_ANALYZER_VERSION`, and a second mapping here could disagree with it while both
    produced legal `SparseVector`s — term ids from another analyzer are legal integers that
    simply match no posting, so nothing errors and the lexical arm silently returns nothing.

    Call it on the **exact string that was embedded**, heading prefix included, so the lexical
    and dense arms describe the same document.

    **Do not bake IDF in here.** The document side carries term saturation and length
    normalization only — no `N`, no `df`, no live `avgdl` — and IDF is applied to the *query*
    vector from statistics scoped to `(org_id, allowed_version_ids)`. Moving it to index time is
    the obvious optimization, takes work off the request path, and breaks ADR-010: the document
    vector would stop being a pure function of *(chunk text, analyzer version, three pinned
    parameters)*, so a rebuild would produce a different index that passes every count and
    checksum the rebuild drill specifies while only the ranking moves. There is a guard test on
    that side; this is the note explaining why routing around it is not a shortcut.

    Raises `EmptySparsePassage` when the chunk analyzes to no terms — an image-only chunk, or a
    table of bare numerals. That is a decision for the upsert (`ChunkVectors.sparse = None`,
    recorded), never an empty vector: an empty one is accepted by Qdrant and matches nothing
    forever.
    """
    return encode_passage(text)


# Import-time checks. Each one is a mis-set constant that would otherwise be discovered as a
# corpus-wide re-identification or a silently trimmed passage, months later.
#
# Note what is deliberately NOT asserted any more: a literal dense width. It used to read
# `DENSE_DIMENSIONS == 1024`, which was true only because one local model was pinned. The width
# is provider- and model-dependent now, so it lives on the `EmbeddingSpace` and is checked
# against what the provider actually returned, at runtime, in `resolve_identity`.
assert MAX_DIMENSIONS > 0
assert MAX_TOKENS > 0
assert TOKEN_HEADROOM > 0
assert MAX_BATCH_TEXTS > 0
assert len(CANARY_TEXTS) >= 3
assert len(set(CANARY_TEXTS)) == len(CANARY_TEXTS)
assert 0 < CANARY_PRECISION < 8
assert CANARY_CONFIRMATIONS >= 2
assert PROVIDER_TRUNCATION_POLICY == "reject"
