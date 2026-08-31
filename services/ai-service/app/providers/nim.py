"""NVIDIA NIM, against the **hosted API Catalog**. All three surfaces are implemented.

``https://integrate.api.nvidia.com/v1``, an OpenAI-shaped ``/v1/chat/completions`` driven by
the ``openai`` SDK as a documented drop-in.

## The two deployment shapes, and why only one is here

NIM is the only vendor in this package that ships in two forms, and they are different
products wearing one API:

| | Hosted API Catalog (what we call) | Self-hosted NIM container (what we do not run) |
|---|---|---|
| Endpoint | ``integrate.api.nvidia.com/v1`` | ``http://<host>:8000/v1`` |
| Auth | ``Authorization: Bearer nvapi-…`` ¹ | **none on inference endpoints** ² |
| Entitlement | developer account + trial credits | free tier, or an enterprise licence |
| Cold start | none | model download plus engine load, minutes, 503 throughout |

¹ <!-- UNVERIFIED: the header is the OpenAI-SDK default and every catalog snippet uses it,
but NVIDIA publishes no normative auth page. -->
² The NGC key authenticates model *downloads* only, which is why a self-hosted container is
never exposed beyond the internal network.

The self-hosted container is documented here and deliberately not operated. Two consequences
that are rules, not preferences: if one is ever added it is an **optional** dependency, so an
open breaker on it leaves ``/health/ready`` green; and it is **never a fallback target** —
its cold start turns a 20 s first-token budget into a hard failure, so it is a primary or
nothing. It would also sit on the internal network beside Qdrant and PostgreSQL and never
behind the edge proxy, because anyone who can reach its port can spend the GPU.

## Divergences this file exists to absorb

**Per-model request schemas genuinely disagree with each other, on one connection.**
``meta/llama-3.1-8b-instruct`` caps ``max_tokens`` at 4096 and ``temperature`` at 1.0 and
defaults ``stream`` to false; ``nvidia/nemotron-3-super-120b-a12b`` allows 32768 output
tokens and defaults ``stream`` to true; ``reasoning_effort`` is enumerated ``none|low|high``
on one model and ``low|medium|high`` on another. Out-of-range is a **422, not a clamp**. So a
bot configuration that validates against one NIM model is a hard failure on the next one
served by the same credential — which is the concrete reason ``ModelCapabilities`` is
per-model and ``stream`` is never defaulted.

**202 means queued, and the SDK has no branch for it.** Every hosted chat endpoint documents
``202 — Result is pending. Client should poll using the requestId``, resolved by
``GET /v1/status/{requestId}``. The OpenAI SDK cannot poll, so the request returns 200-shaped
success, the SSE body never arrives, and the turn hangs until the whole provider budget
expires. Check ``raw.status_code`` BEFORE parsing, classify 202 as ``provider_temporary``,
and let fallback take it — nothing was generated. Do not build a poller: polling inside a
45 s streaming budget only relocates the hang.

**Usage is null on every chunk unless ``stream_options={"include_usage": True}`` is sent**,
including the last one — so every billing row reads ``source="estimated"`` and cost reports
under-report by 100%. The same option creates the second half of the trap: the usage-bearing
chunk arrives with ``choices: []``, so ``chunk.choices[0]`` raises ``IndexError`` on exactly
the chunk that carries the number the option was added for.

**No usage detail beyond the two totals.** The catalog reports ``prompt_tokens`` and
``completion_tokens`` and nothing else: no cached amount, no reasoning amount. So
``cache_read_tokens``, ``cache_write_tokens`` and ``reasoning_tokens`` are all 0 here, and
``PROMPT_CACHING`` is off — not because caching is absent, but because it is unobservable,
and a bucket we cannot measure must read zero rather than be guessed at.

**``nvext`` was removed in NIM LLM 2.0.** The 1.x extension object that carried guided
decoding is gone and the hosted catalog documents no ``response_format`` at all — and
unknown top-level fields are **accepted and ignored**, so a schema sent anyway returns 200
and confident prose. Never set ``STRUCTURED_OUTPUT`` or ``JSON_MODE`` for a NIM model
without a recorded fixture proving enforcement. (``kb-provider-adapter-contract`` still
carries a line saying NIM prefers ``nvext.guided_json``; that was true for 1.x and is now
wrong — flagged there, not silently followed here.)

**``budget_tokens`` is ENFORCED here, uniquely.** NIM's ``reasoning_budget`` is a real
control (``-1`` disables), where every other vendor treats our ``budget_tokens`` as advisory
or rejects the shape outright. It maps, and the mapping is recorded in
``Diagnostics.extras``. It does **not** get a new ``Capability`` member — one vendor's
enforcement of an existing portable field is not a new capability.

**``provider_request_id`` is null unless we supply it.** NIM adopts an ``X-Request-Id`` we
send and forwards it as the backend request id, but it never synthesizes one — so sending it
is mandatory rather than optional, or NVIDIA support has nothing to look up on any row. The
container also forwards ``traceparent``, so the single-trace requirement survives the hop.

**No ``CONTEXT_EXCEEDED`` path exists.** An over-long prompt is rejected up front as a 422
and never stops generation mid-stream, so ``length`` is unambiguously OUR cap.

**TTFT lies by default.** The first streamed chunk is an empty role delta
(``{"role":"assistant","content":""}``) and the stream terminates with ``data: [DONE]``, not
with a usage-bearing final chunk. Time TTFT from the first delta with non-empty content.

**No published rate-limit header schema and no documented 429 body.**
``Diagnostics.rate_limit`` stays empty.
<!-- UNVERIFIED: NVIDIA publishes no rate-limit or 429-header schema for the catalog. -->

**Pinned ids rot on no published schedule.** The free tier publishes models within about 72
hours of upstream availability and retires them without notice, while older ids stay listed
and unmaintained. Re-list ``/v1/models`` on every connection test, store the result on the
connection row, and surface a stale-model warning in the admin panel — a 404 discovered in a
user's chat is the failure this avoids.

## Stream event -> internal event

| Chunk shape | Internal |
|---|---|
| HTTP 202 + ``requestId`` | never parsed; ``provider_temporary`` before any SSE reading |
| first chunk, empty role delta | nothing — it starts no timer and yields no event |
| ``choices[0].delta.content`` | ``Delta(kind="text")`` |
| ``choices[0].delta.reasoning_content`` | ``Delta(kind="reasoning")``, where present ³ |
| ``choices[0].delta.tool_calls[].function.arguments`` | ``Delta(kind="tool_args")`` |
| ``choices[0].finish_reason`` | mapped via ``STOP`` |
| chunk with ``usage`` and ``choices == []`` | usage; ``choices[0]`` here is the crash |
| ``data: [DONE]`` | end of stream; terminal ``ChatResult`` |

³ <!-- UNVERIFIED: the field name on reasoning-capable catalog models was not re-checked. -->

## Usage normalization

**Which of the three arithmetics this is: the OpenAI SUBSET pattern, degenerate.**
``prompt_tokens`` is the whole billed input and the subset OpenAI has to subtract
(``cached_tokens``) is never reported, so there is nothing to subtract. It is **not** the
Anthropic sibling pattern, and today the two are indistinguishable because the cached bucket
is always zero — which is exactly why the choice is written down. The day NVIDIA reports a
cached amount, a sibling reading would add it to an input count that already contains it and
over-bill by precisely that amount, in the one field billing reads.

Two totals, and honest zeros for everything else::

    Usage.input_tokens       = usage.prompt_tokens        # no cached amount is reported
    Usage.cache_read_tokens  = 0
    Usage.cache_write_tokens = 0
    Usage.output_tokens      = usage.completion_tokens
    Usage.reasoning_tokens   = 0                          # not broken out by the catalog

An absent ``usage`` object stays ``source="estimated"`` and is never aggregated into invoiced
cost.

## The ranking surface — the reason stage 11 is capability-gated at all

**Two of the five vendors rerank — NIM and OpenRouter — and only NIM's scores may ever be cut
against.** ADR-030 moved reranking off a local cross-encoder that always worked, which is why
``app/rag/rerank.py`` exists in the shape it does: a closed ``RerankSkipReason``, a
``rerank_gate`` that runs before any call, and a stage that serves the fused order rather than
failing when the capability is absent (`bge-reranker` finding C1, `nvidia-nim-api`
non-negotiable 1). Three vendors still cannot rerank at all, so the gate is not vestigial.

The two that can are asymmetric in the way that matters downstream, and finding #47 has since
made the asymmetry decisive rather than merely awkward. This adapter's scores are a ``LOGIT``
recorded in ``capabilities.RERANK_SCALE``, so a calibrated threshold is derivable from them.
OpenRouter's route fronts several upstream cross-encoders on one credential with no documented
normalization between them, so it is deliberately absent from that table and its scores are
``UNCALIBRATED``.

This paragraph used to end *"a bot on OpenRouter reranks without ever reaching stage 12's
cut"*, and **that mode does not exist.** Stage 11 takes a ``RerankCalibration`` as a required
argument, ``RerankCalibration`` refuses to be constructed on an unthresholdable scale, and
stage 12's only calibration-free route (``evidence.select_unranked``) selects on branch
agreement and never calls a reranker. So an uncharacterized scale is not a reduced capability;
``capabilities.can_rerank`` answers ``False`` for OpenRouter — which is the whole of it.
``assert_row_coherent`` would refuse the row, and this sentence used to add "at save time"; no
write path calls it (finding J1), so the row saves and simply never reranks.
**NIM is, in practice, the only rerank-eligible provider**, which
is what the skills' original half-sentence meant and got right for the wrong reason: not
because OpenRouter lacks the endpoint, but because a gateway's score scale cannot be
characterized per provider.

Three divergences, and the first is the one that moves numbers silently:

**The score scale is an UNBOUNDED SIGNED LOGIT.** NVIDIA's own example ranks ``0.226``,
``-1.17``, ``-1.52``. The retired cross-encoder returned a sigmoid in [0, 1] and
``evidence.min_score = 0.30`` was calibrated on that; ``0.30`` is a valid float on both scales
and nothing raises when they are swapped — only the refusal rate moves, and only in aggregate,
months later. So ``RerankResult.scale`` is filled from
``capabilities.RERANK_SCALE["nvidia_nim"]`` and is ``RerankScale.LOGIT``.

``LOGIT`` **is** thresholdable — this sentence said "not" for a while, and it is the same
one-word defect that once made ``may_threshold`` an alias of ``is_bounded`` and disabled the
evidence gate platform-wide. A threshold is a percentile read off a *measured* distribution,
so it needs an ordering and not a range, and the retired local cross-encoder derived one on a
raw logit. What is missing is a *measurement*, not a permission: ``app/rag/rerank.py``'s
``CALIBRATIONS`` table is empty on purpose, so ``calibration_for("nvidia_nim", …)`` raises
``RerankNotCalibrated`` until an evaluation run fills it. That is one run away, which is
exactly what makes NIM different from OpenRouter, whose scale cannot be measured per provider
at all (finding #47).

**The ranking request schema is unrelated to the chat one and is not the OpenAI shape.** It
takes a query object and a list of passages, caps the passages accepted per request, and caps
the tokens per query-plus-passage pair. A candidate depth above the passage cap becomes several
sequential round-trips unless they are issued concurrently, which is a property of this adapter
and not of the stage — an unbatched loop over 25 candidates is 25 round-trips inside a 1.5 s
retrieval leg.

**The pair-length limit truncates silently, by documented default.** That is the one with no
error to catch: a truncated pair scores off the distribution the threshold was calibrated on,
it fires hardest on the largest and most information-dense chunks, and the only symptom is a
refusal rate that moved in aggregate. ``validate_rerank`` enforces it before the call rather
than trusting the vendor to reject.

**Chat and ranking must fail independently.** A ranking outage degrades ranking for every org
configured against NIM while chat continues on a fallback — and cross-provider fallback is
banned outright on this surface (``errors.NON_CHAT_FALLBACK_ELIGIBLE``) because a different
provider's scale silently changes what the evidence threshold means.

<!-- UNVERIFIED: the ranking endpoint path, the exact request field names, the per-request
passage cap and the per-pair token ceiling are not stated in nvidia-nim-api/SKILL.md, which
sources the endpoint's existence and its logit scale and nothing further. They are read from
the model's own catalog page at implementation time and pinned per model row, exactly as the
chat schemas are — the ceilings genuinely disagree between ranking models the same way
``max_tokens`` does between chat models.

They are now pinned as named constants rather than as literals buried in a body builder, so
the guess is greppable: ``RANKING_PATH``, ``RANKING_RESULTS_FIELD``, ``RANKING_INDEX_FIELD``,
``RANKING_SCORE_FIELD`` and ``MAX_RERANK_PASSAGES``. One recorded response from each pinned id
discharges all five. ``RANKING_SCORE_FIELD`` is the one that must not be read loosely — a
tolerant reader accepting ``score`` as well as ``logit`` would admit a differently-scaled
number into a threshold comparison, which is the failure this whole file's scale plumbing
exists to make impossible. -->

**The pair-length check refuses rather than estimating quietly, and it can refuse a legal
request.** There is no tokenizer in this process, so ``ESTIMATED_CHARS_PER_TOKEN`` deliberately
over-states the token count of a string. Against a 512-token ranking model that means a
full-size chunk (``chunker.MAX_TOKENS`` is 700) is refused before the call. **That is the
designed outcome and it is also a live configuration finding**: a 700-token chunk against a
512-token pair window WOULD be truncated, silently, on every request, and the only fix is a
ranking model with a larger window or a smaller chunk. Reported upward rather than absorbed —
``bge-reranker`` is explicit that latency is bought by cutting candidate depth and never
passage length.
"""

from __future__ import annotations

import asyncio
import math
import time
from collections.abc import AsyncIterator, Iterator, Mapping
from typing import Any, Final, Literal

import httpx
import openai
from pydantic import SecretStr

from app.core.errors import ErrorClass, KbError
from app.providers.capabilities import rerank_scale
from app.providers.contract import (
    Capability,
    CapabilityWarning,
    ChatRequest,
    ChatResult,
    Delta,
    Diagnostics,
    EmbeddingAdapter,
    EmbeddingInputType,
    EmbeddingRequest,
    EmbeddingResult,
    ModelCapabilities,
    ProviderAdapter,
    RerankAdapter,
    RerankRequest,
    RerankResult,
    StopReason,
    StreamEvent,
    Usage,
)
from app.providers.errors import UNMAPPED, ProviderCallFailed
from app.retrieval.collection import EmbeddingSpace

__all__ = ["NimAdapter", "NimStatusError"]

#: The four ``Delta.kind`` values, named once so the translation table and the generator that
#: reads it cannot drift on the literal set.
DeltaKind = Literal["text", "reasoning", "tool_args", "refusal"]

BASE_URL: Final[str] = "https://integrate.api.nvidia.com/v1"

#: Verified against docs.api.nvidia.com/nim/reference/llm-apis on 2026-08-04, where each
#: model publishes its own body schema. The per-model ceilings live on
#: ``provider_models.capability_flags`` rows, not here — the note beside each id records why
#: two rows on one connection cannot share a validator.
PINNED_MODELS: Final[tuple[str, ...]] = (
    "meta/llama-3.1-8b-instruct",  # max_tokens <= 4096, temperature <= 1.0, stream default false
    "nvidia/nemotron-3-super-120b-a12b",  # max_tokens <= 32768, stream default true
)

#: The ranking surface. A different endpoint, a different request schema, a different score
#: scale, and rows that are **task-exclusive**: a ranking row never also carries ``TEXT``,
#: ``TOOL_USE`` or ``REASONING``, and ``capabilities.assert_row_coherent`` rejects one that
#: does. Documentation and connection-save validation only.
#:
#: Catalog churn applies here exactly as it does to the chat ids — the free tier retires model
#: ids on no published schedule while leaving them listed — so the connection test re-lists
#: ``/v1/models`` and surfaces a stale-model warning rather than letting a 404 arrive on the
#: retrieval path, where it would be an error and not the skip stage 11 knows how to take.
#:
#: Both ids were read off the catalog's "Retrieval" family on **2026-08-07**
#: (docs.api.nvidia.com/nim/reference), where each is published as "Rank passages by their
#: relation to a query" — so the previous marker here, which said these were "recalled, not
#: read", is resolved for the ids. The family lists several more (``rerank-qa-mistral-4b``,
#: ``llama-3.2-nemoretriever-500m-rerank-v2``, ``llama-nemotron-rerank-1b-v2`` and a VL
#: variant); only these two are pinned, because a pin is a commitment to a request schema and
#: not a catalogue mirror.
#:
#: <!-- UNVERIFIED: the unbounded-logit SCORE SCALE is still sourced only to
#: nvidia-nim-api/SKILL.md and bge-reranker, not to a response read from either of these two
#: ids. The open question is narrow and it is the one that decides whether stage 12 may cut:
#: does `nv-rerankqa-mistral-4b-v3` return the same scale as `llama-3.2-nv-rerankqa-1b-v2`?
#: `capabilities.RERANK_SCALE` is keyed per PROVIDER, so if the two disagree the key is wrong,
#: not the value. A recorded fixture per id closes it; this is what remains of docs/23
#: row 157. -->
RERANK_MODELS: Final[tuple[str, ...]] = (
    "nvidia/nv-rerankqa-mistral-4b-v3",
    "nvidia/llama-3.2-nv-rerankqa-1b-v2",
)

#: The embedding surface, ``POST {BASE_URL}/embeddings``. Read off the same "Retrieval" family
#: on 2026-08-07, where the reference page for the first id is titled "Creates an embedding
#: vector from the input text" and publishes the body schema quoted in ``validate_embedding``.
#:
#: Task-exclusive like the ranking rows: an embedding row never carries ``TEXT``, ``TOOL_USE``
#: or ``REASONING``, and never carries ``RERANK`` either — they are different products on
#: different routes, and ``capabilities.assert_row_coherent`` rejects a row claiming both.
#:
#: One pin, deliberately. The family is large (``baai/bge-m3``, ``nvidia/nv-embed-v1``,
#: ``snowflake/arctic-embed-l``, the ``llama-3.2-nemoretriever`` line) and every one of them is
#: a **different vector space**, so breadth here is not a convenience — each additional id is a
#: collection that has to be built, verified and re-embedded from scratch. Width matters for a
#: second reason: ``nv-embedqa-e5-v5`` is 1024-wide, which is the same width as ``baai/bge-m3``
#: and as ``text-embedding-3-large`` truncated to 1024, and Qdrant accepts all three into one
#: collection without complaint. That coincidence is why ``EmbeddingSpace`` is keyed on the
#: model id and not on the width.
EMBED_MODELS: Final[tuple[str, ...]] = ("nvidia/nv-embedqa-e5-v5",)

#: vLLM's vocabulary. There is no context-window value: an over-long prompt is a 422 up
#: front. Anything absent maps to ``ERROR`` with ``native_stop_reason`` preserved.
STOP: Final[dict[str, StopReason]] = {
    "stop": StopReason.COMPLETE,
    "length": StopReason.MAX_OUTPUT,
    "tool_calls": StopReason.TOOL_USE,
    "content_filter": StopReason.REFUSAL,
}

#: NIM evidence -> class. Never ``INTERNAL_DEPENDENCY``: that class pages, and NIM is
#: external.
#:
#: 404 on a previously valid id is ``PROVIDER_PERMANENT_REQUEST`` and does **not** fall back
#: (ADR-014). Catalog churn looks like a lifecycle event, but the id came from the bot's own
#: configuration snapshot, so falling back would answer from a model the tenant never chose
#: on a bot that still reads ``Ready``. The remedy is the stale-model warning on the
#: connection, raised at connection-test time, not at chat time.
STATUS_TO_CLASS: Final[dict[int, ErrorClass]] = {
    #: Queued, nothing generated. Fallback-eligible.
    202: ErrorClass.PROVIDER_TEMPORARY,
    401: ErrorClass.PROVIDER_AUTH,
    403: ErrorClass.PROVIDER_AUTH,
    404: ErrorClass.PROVIDER_PERMANENT_REQUEST,
    422: ErrorClass.PROVIDER_PERMANENT_REQUEST,
    429: ErrorClass.PROVIDER_RATE_LIMIT,
    500: ErrorClass.PROVIDER_TEMPORARY,
    #: Model loading, or scaled to zero. This is the capacity signal §8.7 actually means.
    503: ErrorClass.PROVIDER_TEMPORARY,
}

#: HTTP 202 on this vendor is "queued", not "accepted and streaming". It has to be read off
#: the raw response BEFORE any SSE parsing: the OpenAI SDK has no 202 branch, so a queued
#: response looks like a 200 whose body never arrives and the turn hangs for the whole
#: provider budget.
HTTP_ACCEPTED: Final[int] = 202
HTTP_OK: Final[int] = 200

#: MANDATORY on every request, on all three surfaces. NIM adopts an ``X-Request-Id`` we send
#: and forwards it as the backend request id, and it **never synthesizes one** — so omitting
#: it leaves ``provider_request_id`` permanently null and NVIDIA support with nothing to look
#: up on any row we could ever ask about.
REQUEST_ID_HEADER: Final[str] = "X-Request-Id"

#: The echo, lowercased, as httpx normalizes header names.
REQUEST_ID_ECHO_HEADER: Final[str] = "x-request-id"

#: The seven portable effort levels mapped onto what this vendor accepts. **Three of them
#: round**, and rounding is never silent: ``validate()`` emits a ``CapabilityWarning`` for
#: every key whose value differs from it, because the contract says a vendor lacking a level
#: maps to the nearest supported step AND says so.
#:
#: The per-model enumerations genuinely disagree (``none|low|high`` on Nemotron Super,
#: ``low|medium|high`` on gpt-oss-120b) and one boolean ``REASONING`` flag cannot express
#: which. A level a given model does not publish comes back as a 422, which classifies as
#: ``PROVIDER_PERMANENT_REQUEST`` and names the level — the loud direction.
REASONING_EFFORT: Final[Mapping[str, str]] = {
    "none": "none",
    "minimal": "low",
    "low": "low",
    "medium": "medium",
    "high": "high",
    "xhigh": "high",
    "max": "high",
}

#: The levels with no step of their own here. Derived from the table rather than restated, so
#: a mapping change cannot leave a stale list behind.
ROUNDED_EFFORTS: Final[frozenset[str]] = frozenset(
    level for level, sent in REASONING_EFFORT.items() if level != sent
)

#: ``delta`` field -> ``Delta.kind``. A table rather than an ``if`` ladder so a test can read
#: the mapping instead of restating it. ``tool_calls`` is absent on purpose: its text lives two
#: levels down (``tool_calls[].function.arguments``) and is handled by ``_delta_events``.
#:
#: <!-- UNVERIFIED: ``reasoning_content`` is the field name DeepSeek and vLLM's OpenAI-
#: compatible server use for a returned reasoning trace; it was not re-read off a NIM catalog
#: response for a reasoning-capable model. Reading a field this vendor does not populate costs
#: an empty pane, never a wrong answer, so it is carried rather than dropped. -->
DELTA_FIELDS: Final[Mapping[str, DeltaKind]] = {
    "reasoning_content": "reasoning",
    "content": "text",
    "refusal": "refusal",
}

#: How one retrieved block is rendered. Untrusted data: it becomes a user-role turn and never
#: reaches the system message. NIM documents a ``context`` message role; we do not use it,
#: because its handling is per-model and undocumented.
CONTEXT_BLOCK_TEMPLATE: Final[str] = "[{index}] {title}\n{text}"

#: The catalog's per-model sampling ceiling. OpenAI allows 2.0; NIM answers **422** rather
#: than clamping, so the range is checked in ``validate()`` and never in flight.
MAX_TEMPERATURE: Final[float] = 1.0
MIN_TEMPERATURE: Final[float] = 0.0

#: ``POST {BASE_URL}/embeddings`` ceilings, from the catalog body schema quoted in
#: ``capabilities.PROVIDER_TASKS[("nvidia_nim", EMBEDDING)]``: the ``input`` array is capped at
#: 4096 items and each item at 8192 tokens. Item count and summed length are different limits.
MAX_EMBEDDING_INPUTS: Final[int] = 4096
MAX_EMBEDDING_INPUT_TOKENS: Final[int] = 8192

#: ``truncate`` is pinned to ``NONE`` on **both** non-chat surfaces and is never sent as
#: anything else. The documented values are ``NONE | START | END``; ``NONE`` errors when the
#: input exceeds the model's maximum and the other two discard text until it fits. ``NONE`` is
#: also the vendor's default on the embedding route, so this is a pin against a default moving
#: rather than an override — and the pin is what makes ``PROVIDER_TRUNCATION_POLICY =
#: "reject"`` true on this vendor rather than merely hoped for.
TRUNCATE_POLICY: Final[str] = "NONE"

#: ``EmbeddingInputType`` -> the wire value. An identity map today, written out rather than
#: derived from ``.value`` so that a vendor renaming its enumeration is a one-line change here
#: instead of a silent recall loss: nothing errors when this is wrong. NVIDIA's own schema
#: says failing to use the correct one "will result in large drops in retrieval accuracy".
EMBEDDING_INPUT_TYPES: Final[Mapping[EmbeddingInputType, str]] = {
    EmbeddingInputType.QUERY: "query",
    EmbeddingInputType.PASSAGE: "passage",
}

#: The ranking route, relative to ``BASE_URL``, and the request/response field names.
#:
#: <!-- UNVERIFIED: the path and the three field names below are transcribed from NVIDIA's
#: NeMo Retriever reranking schema as recalled at implementation time; neither
#: nvidia-nim-api/SKILL.md nor bge-reranker states them, and no response has been recorded
#: from either pinned id on this host. `RANKING_SCORE_FIELD` is the one that must not be
#: guessed loosely: `logit` is the vendor's own name for the number, and accepting a fallback
#: key such as `score` would silently admit a differently-scaled quantity into a threshold
#: comparison. A wrong name here fails loudly on the first call, which is the correct
#: direction; a tolerant reader would fail quietly on the thousandth. -->
RANKING_PATH: Final[str] = "/ranking"
RANKING_RESULTS_FIELD: Final[str] = "rankings"
RANKING_INDEX_FIELD: Final[str] = "index"
RANKING_SCORE_FIELD: Final[str] = "logit"

#: The per-request passage ceiling on the ranking route. Over it is a rejection in
#: ``validate_rerank`` and never a silent batch-and-merge: depth is a retrieval decision
#: (`kb-rag-query-contract`) and an adapter that quietly split one call into five would make
#: the retrieval budget a function of a number the stage never chose.
#:
#: <!-- UNVERIFIED: 512 is the ceiling `contract.RerankRequest` records as "the per-model
#: ceiling (512 on NVIDIA)". It is per-model on this vendor like every other limit, so the
#: row's own value is what a connection should carry; this is the backstop. -->
MAX_RERANK_PASSAGES: Final[int] = 512

#: Characters per token, used ONLY to decide whether a text is certainly too long for a
#: window. There is no authoritative tokenizer in this process — the vendor tokenizes with its
#: own algorithm over its own vocabulary — so this is deliberately **below** the ~4 characters
#: per token English averages, which makes the derived token count an OVER-estimate.
#:
#: The direction is the whole point and it is the chunker's (`chunker.MAX_TOKENS`): an
#: over-estimate costs a loud refusal on a text the vendor would have accepted; an
#: under-estimate costs a passage the vendor trims with no error and no metric, scored off the
#: distribution the evidence threshold was calibrated on. On the rerank surface there is no
#: second chance — the truncation is the documented default behaviour of the pair-length limit.
#:
#: <!-- UNVERIFIED: no tokenizer here can confirm any ratio. `TRUNCATE_POLICY` is the
#: authority on the wire; this only decides what is refused before the wire. -->
ESTIMATED_CHARS_PER_TOKEN: Final[float] = 3.0


class NimStatusError(Exception):
    """A status read off a raw response rather than raised by a client.

    The SDK raises for 4xx and 5xx, so the one status that reaches this class in practice is
    **202** — a 2xx the SDK is perfectly happy with and which carries no stream at all. It
    exists so that the 202 branch and the ordinary failure branch converge on one
    classification path (``classify``) instead of one of them growing its own vocabulary.

    Carries no body and no vendor message: 422 bodies on this vendor echo the request, and the
    request carries the packed prompt or the tenant's chunk text.
    """

    def __init__(self, status_code: int, *, request_id: str | None = None) -> None:
        super().__init__(f"NVIDIA NIM answered HTTP {status_code}")
        self.status_code = status_code
        #: Read by ``classify`` under the name every other exception here uses.
        self.request_id = request_id


def _status_of(exc: BaseException) -> int | None:
    """The HTTP status behind a failure, whatever raised it.

    Three shapes reach ``classify`` on this vendor and they spell the status differently: the
    OpenAI SDK's ``APIStatusError.status_code`` (chat and embedding), ``httpx``'s
    ``HTTPStatusError.response.status_code`` (ranking), and ``NimStatusError`` (the 202).
    """
    status = getattr(exc, "status_code", None)
    if isinstance(status, int):
        return status
    status = getattr(getattr(exc, "response", None), "status_code", None)
    return status if isinstance(status, int) else None


def _native_code(exc: BaseException) -> str | None:
    """The vendor's own code, when there is one, for the record and never for the branch.

    Defensive at every hop: the SDK types ``body`` as ``object``, a proxy error can answer HTML
    under a JSON content type, and a gateway 502 has no body at all — none of which is a reason
    to fail while classifying a failure.

    **NIM publishes no error-code vocabulary**, so unlike the OpenAI adapter there is no
    ``BODY_CODE_TO_CLASS`` here and this value never selects a class. It is carried on
    ``ProviderCallFailed.native_code`` so that the day a code table becomes documentable, the
    evidence for writing it is already in the incident record rather than in a vendor's prose.
    """
    body = getattr(exc, "body", None)
    if isinstance(body, dict):
        for key in ("type", "code"):
            value = body.get(key)
            if isinstance(value, str) and value:
                return value
        error = body.get("error")
        if isinstance(error, dict):
            value = error.get("code")
            if isinstance(value, str) and value:
                return value
    return None


def _adopted_request_id(headers: Any, sent: str) -> str:
    """What NIM will answer support questions about.

    The echoed header when it is present, and otherwise the id we sent — which is the same
    value, because NIM *adopts* ``X-Request-Id`` rather than generating one. Falling back is
    not a guess: it is the only handle that exists, and a null here is the exact failure the
    header was made mandatory to avoid.
    """
    echoed = None
    if headers is not None:
        try:
            echoed = headers.get(REQUEST_ID_ECHO_HEADER)
        except AttributeError:  # pragma: no cover - a header container without `.get`
            echoed = None
    return echoed if isinstance(echoed, str) and echoed else sent


def _timeout(connect: float, read: float, total: float) -> httpx.Timeout:
    """The per-call budget, never the client's constructed default.

    ``read`` is BETWEEN CHUNKS on a stream, so a model emitting one token every fifteen seconds
    never trips it; the caller's absolute deadline is what bounds the total.
    """
    return httpx.Timeout(total, connect=connect, read=read, write=connect, pool=connect)


def _estimated_tokens(text: str) -> int:
    """An OVER-estimate of the tokens in a string. See ``ESTIMATED_CHARS_PER_TOKEN``."""
    return math.ceil(len(text) / ESTIMATED_CHARS_PER_TOKEN)


def _estimated_usage(deltas_emitted: int) -> Usage:
    """What a turn that never reached its usage chunk can honestly claim.

    Usage arrives on this vendor only in the final ``stream_options`` chunk, so a cancelled or
    mid-stream-failed turn has no input count at all and inventing one would be a fabricated
    bill. What it does have is the number of non-empty deltas it forwarded, and on this API a
    delta is approximately one token.

    ``source="estimated"``, which is what keeps it out of invoiced cost. The alternative is a
    ``Usage()`` of zeros, and a free call and a cancelled call then look identical — which is
    how billing silently under-counts every abandoned turn.
    """
    return Usage(output_tokens=deltas_emitted, source="estimated")


def _delta_events(delta: Any) -> Iterator[tuple[DeltaKind, str]]:
    """Every piece of text on one ``choices[0].delta``, in a fixed order.

    Reasoning before content before tool arguments, so a chunk carrying two of them cannot
    change the order of the stream between runs. Empty strings are skipped: the first streamed
    chunk on this vendor is an empty role delta, and starting the first-token clock on it
    reports a TTFT that flatters every dashboard we own while the user watches a blank box.
    """
    for field, kind in DELTA_FIELDS.items():
        text = getattr(delta, field, None)
        if isinstance(text, str) and text:
            yield kind, text
    for call in getattr(delta, "tool_calls", None) or []:
        arguments = getattr(getattr(call, "function", None), "arguments", None)
        if isinstance(arguments, str) and arguments:
            yield "tool_args", arguments


def _scores_in_input_order(rankings: Any, *, expected: int) -> list[float]:
    """Scatter the ranking response back onto the passages that were sent.

    **The positional read is the bug this exists to prevent.** The response is sorted by
    relevance and identifies each entry only by its original position, so reading it
    positionally attaches perfectly plausible floats to the wrong passages — a stage that
    "barely changes the order and looks pointless", or worse, an answer citing a chunk that
    scored well only because it was third in the request.

    A short, duplicated or out-of-range cover raises rather than returning what arrived.
    Silently dropped passages become evidence that vanished, and the answer that follows is
    grounded in a subset nobody chose.
    """
    if not isinstance(rankings, list):
        raise KbError(
            ErrorClass.PROVIDER_PERMANENT_REQUEST,
            f"the NVIDIA NIM ranking response carried no {RANKING_RESULTS_FIELD!r} list; it "
            "does not describe the request that was sent, and reading it positionally would "
            "be silent",
        )

    by_index: dict[int, float] = {}
    for entry in rankings:
        index = entry.get(RANKING_INDEX_FIELD) if isinstance(entry, dict) else None
        score = entry.get(RANKING_SCORE_FIELD) if isinstance(entry, dict) else None
        if not isinstance(index, int) or isinstance(index, bool) or not 0 <= index < expected:
            raise KbError(
                ErrorClass.PROVIDER_PERMANENT_REQUEST,
                f"NVIDIA NIM ranked a passage at index {index!r}, which is outside the "
                f"{expected} passages that were sent",
            )
        if index in by_index:
            raise KbError(
                ErrorClass.PROVIDER_PERMANENT_REQUEST,
                f"NVIDIA NIM returned two scores for index {index}; the response does not "
                "describe the request that was sent, and a positional read of it would be "
                "silent",
            )
        if isinstance(score, bool) or not isinstance(score, int | float):
            raise KbError(
                ErrorClass.PROVIDER_PERMANENT_REQUEST,
                f"NVIDIA NIM returned a non-numeric {RANKING_SCORE_FIELD!r} for index "
                f"{index}. A sentinel score is never substituted: it would rank a passage "
                "that was not scored against passages that were",
            )
        by_index[index] = float(score)

    if len(by_index) != expected:
        missing = sorted(set(range(expected)) - by_index.keys())
        raise KbError(
            ErrorClass.PROVIDER_PERMANENT_REQUEST,
            f"NVIDIA NIM returned {len(by_index)} scores for {expected} passages "
            f"(missing {missing[:8]}). A response covering fewer entries than it was given is "
            "a failure and not a partial answer",
        )

    return [by_index[index] for index in range(expected)]


def _vectors_in_input_order(data: Any, *, expected: int) -> list[list[float]]:
    """Re-sort the embedding response on each entry's ``index`` and prove the cover is exact.

    Response order is not input order, and ``EmbeddingResult.vectors[i]`` is contractually the
    embedding of ``texts[i]``. A positional read produces a fully populated, fully wrong index:
    every chunk carries some other chunk's vector, nothing raises, every count matches, and
    retrieval simply returns plausible neighbours that are not neighbours at all.

    Not shared with the ranking scatter above, even though the shapes rhyme. One reads
    ``index``/``embedding`` off an SDK-parsed object and the other reads ``index``/``logit``
    out of raw JSON, and the messages name different failures; a shared helper would have to
    take both field names and a container kind as arguments, which is a worse thing to read at
    three in the morning than two twenty-line functions.
    """
    by_index: dict[int, list[float]] = {}
    for entry in data:
        index = getattr(entry, "index", None)
        if not isinstance(index, int) or isinstance(index, bool) or not 0 <= index < expected:
            raise KbError(
                ErrorClass.PROVIDER_PERMANENT_REQUEST,
                f"NVIDIA NIM returned an embedding at index {index!r}, which is outside the "
                f"{expected} inputs that were sent",
            )
        if index in by_index:
            raise KbError(
                ErrorClass.PROVIDER_PERMANENT_REQUEST,
                f"NVIDIA NIM returned two embeddings for index {index}; the response does not "
                "describe the batch that was sent, and a positional read of it would be silent",
            )
        by_index[index] = [float(component) for component in entry.embedding]

    if len(by_index) != expected:
        missing = sorted(set(range(expected)) - by_index.keys())
        raise KbError(
            ErrorClass.PROVIDER_PERMANENT_REQUEST,
            f"NVIDIA NIM returned {len(by_index)} embeddings for {expected} inputs "
            f"(missing {missing[:8]}). A partial batch is never returned as a partial result: "
            "it would write half a source version's vectors and the version would publish as "
            "complete",
        )

    return [by_index[index] for index in range(expected)]


def _is_unit_norm(vector: list[float]) -> bool:
    """Whether the vendor returned a unit-norm vector, **as observed on this response**.

    Not read from the vendor's documentation, which is the point: ``DENSE_DISTANCE`` is fixed
    to ``"Cosine"`` and folded into the collection name because every embedding API here
    documents its vectors as normalized, and this is the only evidence the claim holds for the
    vectors actually returned. The tolerance is loose deliberately — the indexer L2-normalizes
    regardless, so this is a finding to record rather than a failure to raise.
    """
    if not vector:
        return False
    magnitude = math.sqrt(sum(component * component for component in vector))
    return abs(magnitude - 1.0) < 1e-3


class NimAdapter:
    """Satisfies ``ProviderAdapter``.

    Capability flags expected on a catalog row: ``TEXT``, ``TOOL_USE`` and ``SAMPLING``
    (within the model's own range — 0.0 to 1.0 on the catalog, not OpenAI's 2.0),
    ``STREAM_USAGE``, and ``REASONING`` on the models that publish ``reasoning_effort``. OFF
    everywhere until a recorded fixture proves otherwise: ``STRUCTURED_OUTPUT`` and
    ``JSON_MODE`` (no surface, and unknown fields are ignored rather than rejected),
    ``PROMPT_CACHING`` (unobservable), ``EARLY_INPUT_USAGE``, ``IMAGE_INPUT``.
    """

    name = "nvidia_nim"
    min_useful_seconds = 5.0

    def _client(self, credential: SecretStr, req: ChatRequest) -> Any:
        """``AsyncOpenAI(base_url=BASE_URL, max_retries=0, timeout=...)``.

        The ``max_retries=0`` line is easy to remember for the OpenAI adapter and easy to
        forget here, because this is "the NVIDIA provider" — and it is the same SDK with the
        same default of two invisible retries.

        The ``nvapi-`` credential is broader than a per-project provider key: the same
        credential shape pulls container images and model weights, so a leak exceeds one
        org's token bill. Masked as ``nvapi-…4a91`` anywhere it is referenced at all.
        """
        return openai.AsyncOpenAI(
            # THE ONE `get_secret_value()` ON THIS SURFACE. Extracting the key is a greppable
            # act rather than an accident of serialization; it is never logged, never a span
            # attribute, and never re-read from this client afterwards.
            api_key=credential.get_secret_value(),
            base_url=BASE_URL,
            # ALWAYS 0. The SDK retries twice by default, invisibly, inside one `await`, so
            # the span records one call while NVIDIA's dashboard records three.
            max_retries=0,
            timeout=_timeout(req.timeouts.connect, req.timeouts.first_token, req.timeouts.total),
        )

    def _embedding_client(self, credential: SecretStr, req: EmbeddingRequest) -> Any:
        """The embedding client. Separate construction site, same two rules.

        A sibling of ``_client`` rather than a shared one because the two surfaces take
        different request types and therefore different budgets: ``first_token`` is meaningless
        where there is no stream, so ``read`` here is the whole request.

        Separate for a second reason that outranks tidiness: ``get_secret_value()`` is called
        exactly **once per surface**, so each call site is a place a reviewer can stand.
        """
        return openai.AsyncOpenAI(
            api_key=credential.get_secret_value(),
            base_url=BASE_URL,
            max_retries=0,
            timeout=_timeout(req.timeouts.connect, req.timeouts.total, req.timeouts.total),
        )

    def _ranking_client(self, credential: SecretStr, req: RerankRequest) -> Any:
        """The ranking client, and it is not the OpenAI SDK.

        The ranking route is not OpenAI-shaped — a query object and a passage list in, an
        index-and-logit list out — so there is no SDK method to call and driving it through
        ``client.post`` would buy nothing but a cast. ``httpx.AsyncClient`` performs no retries
        of its own, which is the same property ``max_retries=0`` buys on the other two.

        The caller closes it. An unclosed client leaks a connection pool per rerank call, which
        on the retrieval path is per query.
        """
        return httpx.AsyncClient(
            base_url=BASE_URL,
            headers={
                # THE ONE `get_secret_value()` ON THIS SURFACE.
                "Authorization": f"Bearer {credential.get_secret_value()}",
                "Accept": "application/json",
            },
            timeout=_timeout(req.timeouts.connect, req.timeouts.total, req.timeouts.total),
        )

    def _translate_in(self, req: ChatRequest, caps: ModelCapabilities) -> dict[str, Any]:
        """Build the chat-completions body.

        Always: explicit ``stream`` (never defaulted — the vendor default differs per model),
        ``stream_options={"include_usage": True}``, and ``max_tokens`` already validated
        against this model's own ceiling.

        NIM documents a ``context`` message role. We do not use it: its handling is per-model
        and undocumented, and retrieved evidence is packed into the user turn by the query
        contract.
        """
        messages: list[dict[str, Any]] = [{"role": "system", "content": req.system}]

        if req.context_blocks:
            # A USER TURN, NEVER THE SYSTEM MESSAGE. Source text that can reach the instruction
            # slot is prompt injection with our own retrieval pipeline as the delivery
            # mechanism. The order is the retrieval stage's and is preserved verbatim.
            messages.append(
                {
                    "role": "user",
                    "content": "\n\n".join(
                        CONTEXT_BLOCK_TEMPLATE.format(
                            index=block.index, title=block.title, text=block.text
                        )
                        for block in req.context_blocks
                    ),
                }
            )

        for message in req.messages:
            messages.append({"role": message.role, "content": message.content})

        if req.images and Capability.IMAGE_INPUT in caps.supported:
            # Base64 data URLs only — `ImageInput` has deliberately no URL form, because a URL
            # the vendor fetches is a tenant-supplied fetch we cannot guard.
            parts: list[dict[str, Any]] = [
                {
                    "type": "image_url",
                    "image_url": {"url": f"data:{image.media_type};base64,{image.data_b64}"},
                }
                for image in req.images
            ]
            messages.append({"role": "user", "content": parts})

        body: dict[str, Any] = {
            "model": req.model,
            "messages": messages,
            "max_tokens": req.max_output_tokens,
            # NEVER DEFAULTED. `stream` defaults to false on some catalog models and true on
            # others, so an omitted value is a different request per model id.
            "stream": True,
            # Without this, `usage` is null on EVERY chunk including the last one, every
            # billing row lands as source="estimated", and cost reports under-report by 100%.
            "stream_options": {"include_usage": True},
        }

        if (
            req.temperature is not None
            and Capability.SAMPLING in caps.supported
            and MIN_TEMPERATURE <= req.temperature <= MAX_TEMPERATURE
        ):
            # The range guard is repeated from `validate()` rather than trusted from it: under
            # `on_unsupported="warn"` validate RECORDS the out-of-range value and does not
            # raise, and sending it anyway is a 422 on a request that would otherwise work.
            body["temperature"] = req.temperature

        if req.reasoning is not None and Capability.REASONING in caps.supported:
            # ENFORCED HERE, UNIQUELY. `reasoning_budget` is a real control on this vendor
            # (`-1` disables) where every other one treats `budget_tokens` as advisory or
            # rejects the shape outright. NO `nvext`: the 1.x extension object that carried
            # guided decoding was REMOVED in NIM LLM 2.0 and the migration guide directs these
            # to top-level fields. An adapter still writing `nvext.guided_json` is writing to a
            # surface that no longer exists, and unknown top-level keys are ignored rather than
            # rejected — so it would return 200 and confident prose.
            body["reasoning_effort"] = REASONING_EFFORT[req.reasoning.effort]
            if req.reasoning.budget_tokens is not None:
                body["reasoning_budget"] = req.reasoning.budget_tokens

        if req.tools and Capability.TOOL_USE in caps.supported:
            body["tools"] = [
                {
                    "type": "function",
                    "function": {
                        "name": tool.name,
                        "description": tool.description,
                        "parameters": tool.parameters,
                    },
                }
                for tool in req.tools
            ]

        return body

    def _unsupported(
        self, caps: ModelCapabilities, *, option: str, detail: str
    ) -> CapabilityWarning:
        """``reject`` raises before a byte goes out; ``warn`` strips the option and records it.

        There is no third branch, because the third branch people reach for is "drop it
        quietly" — which is how a bot configured for structured output returns prose for a
        month with nothing to read in a log.
        """
        if caps.on_unsupported == "reject":
            raise KbError(ErrorClass.VALIDATION, detail)
        return CapabilityWarning(option=option, action="ignored", detail=detail)

    def validate(self, req: ChatRequest, caps: ModelCapabilities) -> list[CapabilityWarning]:
        """Range checks run HERE, not in flight, because out-of-range is a 422.

        At minimum: ``temperature`` outside 0.0–1.0, ``max_output_tokens`` above the row's
        ceiling, and any ``response_schema`` at all while ``STRUCTURED_OUTPUT`` is off.
        """
        warnings: list[CapabilityWarning] = []

        if not req.stream:
            warnings.append(
                self._unsupported(
                    caps,
                    option="stream",
                    detail=(
                        f"stream=False is not honoured for {req.model!r}: this adapter always "
                        "sends stream=True because the Laravel relay measures time-to-first-"
                        "token and a buffered turn reports one it did not achieve. The answer "
                        "is identical; only the delivery differs"
                    ),
                )
            )

        if req.temperature is not None:
            if Capability.SAMPLING not in caps.supported:
                warnings.append(
                    self._unsupported(
                        caps,
                        option="temperature",
                        detail=(
                            f"{req.model!r} does not declare Capability.SAMPLING, so "
                            f"temperature={req.temperature} cannot be sent"
                        ),
                    )
                )
            elif not MIN_TEMPERATURE <= req.temperature <= MAX_TEMPERATURE:
                warnings.append(
                    self._unsupported(
                        caps,
                        option="temperature",
                        detail=(
                            f"NVIDIA's catalog accepts {MIN_TEMPERATURE} to {MAX_TEMPERATURE} on "
                            f"{req.model!r} and answers 422 rather than clamping, so "
                            f"temperature={req.temperature} — which is legal on OpenAI, whose "
                            "ceiling is 2.0 — cannot be sent"
                        ),
                    )
                )

        if req.response_schema is not None:
            if Capability.STRUCTURED_OUTPUT in caps.supported:
                # ALWAYS A RAISE, on both settings, and the only place this adapter refuses a
                # row rather than a request. NIM LLM 2.0 removed `nvext` and the hosted catalog
                # documents no `response_format`, so a row claiming STRUCTURED_OUTPUT describes
                # a surface the vendor does not publish. Honouring it would send a field this
                # vendor IGNORES rather than rejects — 200, confident prose, and a bot that
                # quietly stops producing structure for a month.
                raise KbError(
                    ErrorClass.VALIDATION,
                    f"the provider_models row for {req.model!r} declares "
                    "Capability.STRUCTURED_OUTPUT, and NVIDIA's hosted catalog publishes no "
                    "json_schema surface at all — `nvext` was removed in NIM LLM 2.0. Set the "
                    "flag only against a recorded fixture proving enforcement",
                )
            warnings.append(
                self._unsupported(
                    caps,
                    option="response_schema",
                    detail=(
                        f"{req.model!r} does not declare Capability.STRUCTURED_OUTPUT and NIM "
                        "publishes no json_schema surface. Unknown top-level fields are "
                        "ACCEPTED AND IGNORED here, so a schema sent anyway returns 200 and "
                        "prose — the silent drop §8.6 forbids"
                    ),
                )
            )

        if req.reasoning is not None:
            if Capability.REASONING not in caps.supported:
                warnings.append(
                    self._unsupported(
                        caps,
                        option="reasoning",
                        detail=(
                            f"{req.model!r} does not declare Capability.REASONING, so "
                            f"effort={req.reasoning.effort!r} cannot be requested. "
                            "budget_tokens goes with it: NIM is the one vendor that ENFORCES "
                            "it, so a caller reading it as portable would size a budget that "
                            "silently does nothing"
                        ),
                    )
                )
            else:
                if req.reasoning.effort in ROUNDED_EFFORTS:
                    # DELIBERATELY ALWAYS A WARNING AND NEVER A REJECTION — one of two places
                    # in this adapter that does not obey `on_unsupported`. The contract states
                    # the behaviour directly: a vendor lacking a level maps to its nearest
                    # supported step AND emits a CapabilityWarning; it never silently rounds.
                    # Rejecting instead would refuse every `max`-effort request on a vendor
                    # that has a perfectly good `high`.
                    warnings.append(
                        CapabilityWarning(
                            option="reasoning.effort",
                            action="ignored",
                            detail=(
                                f"effort={req.reasoning.effort!r} has no step on NVIDIA NIM "
                                f"and is sent as "
                                f"{REASONING_EFFORT[req.reasoning.effort]!r}. Recorded rather "
                                "than rounded silently, because a recommended setting quietly "
                                "becoming its neighbour has no diff and no error"
                            ),
                        )
                    )
                if req.reasoning.include_trace and Capability.REASONING_TRACE not in caps.supported:
                    warnings.append(
                        self._unsupported(
                            caps,
                            option="reasoning.include_trace",
                            detail=(
                                f"{req.model!r} declares REASONING without REASONING_TRACE, so "
                                "the reasoning pane stays empty while the trace is still "
                                "billed inside output_tokens"
                            ),
                        )
                    )

        if req.images and Capability.IMAGE_INPUT not in caps.supported:
            warnings.append(
                self._unsupported(
                    caps,
                    option="images",
                    detail=(
                        f"{req.model!r} does not declare Capability.IMAGE_INPUT, so "
                        f"{len(req.images)} image(s) cannot be sent. Dropping them silently "
                        "would leave the model answering a question about a picture it never "
                        "saw, in prose that never says so"
                    ),
                )
            )

        if req.tools and Capability.TOOL_USE not in caps.supported:
            warnings.append(
                self._unsupported(
                    caps,
                    option="tools",
                    detail=(
                        f"{req.model!r} does not declare Capability.TOOL_USE, so "
                        f"{len(req.tools)} tool definition(s) cannot be sent"
                    ),
                )
            )

        if req.cache_hint == "prefix" and Capability.PROMPT_CACHING not in caps.supported:
            warnings.append(
                self._unsupported(
                    caps,
                    option="cache_hint",
                    detail=(
                        "NVIDIA's catalog reports no cached amount on any usage object, so "
                        "prompt caching is UNOBSERVABLE here rather than absent. No cost "
                        "reduction should be expected and none will be reported: a bucket we "
                        "cannot measure reads zero rather than being guessed at"
                    ),
                )
            )

        if req.max_output_tokens > caps.max_output_tokens:
            # A RAISE on both settings: there is no request to make with the option removed,
            # and `max_tokens` has no default we could fall back to that would not silently
            # change the answer length the tenant configured. The ceilings genuinely disagree
            # per model here — 4096 on llama-3.1-8b-instruct, 32768 on nemotron-3-super — so a
            # bot configuration that validates against one NIM model is a 422 on the next one
            # served by the same credential.
            raise KbError(
                ErrorClass.VALIDATION,
                f"max_output_tokens={req.max_output_tokens} exceeds the ceiling "
                f"{caps.max_output_tokens} recorded for {req.model!r}. NVIDIA answers 422 "
                "rather than clamping, and the ceiling is per model on this vendor",
            )

        return warnings

    async def stream(
        self,
        req: ChatRequest,
        caps: ModelCapabilities,
        credential: SecretStr,
    ) -> AsyncIterator[StreamEvent]:
        """Translate the chat-completions stream.

        Uses the raw-response form so ``status_code`` is readable before parsing: a 202 must
        be classified before a single SSE line is read. Sends ``X-Request-Id`` from
        ``req.trace_id`` on every attempt, including retries — it is the only way this vendor
        has a request id at all.
        """
        started = time.perf_counter()

        # BEFORE THE FIRST BYTE. Under `on_unsupported="reject"` this raises out of the
        # generator without a ChatResult, which is correct: nothing was sent, nothing was
        # billed, and there is no turn to finalize.
        warnings = self.validate(req, caps)

        first_token_ms: int | None = None
        parts: list[str] = []
        deltas_emitted = 0
        usage = Usage()
        stop = StopReason.ERROR
        error_class: str | None = None
        native_stop_reason: str | None = None
        # NEVER None on this vendor: NIM adopts the id we send and never synthesizes one, so
        # the id we sent is the id support will look up even if the response is never read.
        request_id: str = req.trace_id
        extras: dict[str, Any] = {}

        try:
            body = self._translate_in(req, caps)
            # Allow-listed at the parse site, never a raw dump: an effort level and an integer
            # budget. No prompt, no message, no credential, no vendor prose.
            for option in ("reasoning_effort", "reasoning_budget"):
                if option in body:
                    extras[option] = body[option]

            client = self._client(credential, req)
            raw = await client.chat.completions.with_raw_response.create(
                **body,
                extra_headers={REQUEST_ID_HEADER: req.trace_id},
                timeout=_timeout(
                    req.timeouts.connect, req.timeouts.first_token, req.timeouts.total
                ),
            )
            request_id = _adopted_request_id(getattr(raw, "headers", None), req.trace_id)

            if raw.status_code == HTTP_ACCEPTED:
                # BEFORE `parse()`. 202 means queued, resolved by GET /v1/status/{requestId},
                # and the SDK has no polling path and no 202 branch — so parsing it yields a
                # stream that never produces a line and the turn hangs for the whole provider
                # budget. Classified as temporary because NOTHING WAS GENERATED, which is what
                # makes it fallback-eligible. Do not build a poller: polling inside a 45 s
                # streaming budget only relocates the hang.
                raise NimStatusError(raw.status_code, request_id=request_id)

            async for chunk in raw.parse():
                chunk_usage = getattr(chunk, "usage", None)
                if chunk_usage is not None:
                    usage = self._usage(chunk_usage)

                served = getattr(chunk, "model", None)
                if isinstance(served, str) and served:
                    extras["response_model"] = served

                choices = getattr(chunk, "choices", None) or []
                if not choices:
                    # THE USAGE CHUNK. It arrives with `choices: []`, so `chunk.choices[0]`
                    # raises IndexError on exactly the chunk carrying the number that
                    # `stream_options` was added to obtain.
                    continue

                choice = choices[0]
                finish = getattr(choice, "finish_reason", None)
                if isinstance(finish, str) and finish:
                    # THE VENDOR'S OWN WORD, kept even when it maps to ERROR: it is what tells
                    # us NVIDIA added a stop reason under its own versioning policy.
                    native_stop_reason = finish
                    stop = STOP.get(finish, StopReason.ERROR)
                    if stop is StopReason.ERROR:
                        # Unknown is PERMANENT, never temporary: a temporary default retries a
                        # request that will never succeed and hides the new value.
                        error_class = ErrorClass.PROVIDER_PERMANENT_REQUEST.value

                delta = getattr(choice, "delta", None)
                if delta is None:
                    continue
                for kind, text in _delta_events(delta):
                    if first_token_ms is None:
                        first_token_ms = int((time.perf_counter() - started) * 1000)
                    deltas_emitted += 1
                    if kind == "text":
                        parts.append(text)
                    yield Delta(kind=kind, text=text, index=getattr(choice, "index", 0) or 0)
        except asyncio.CancelledError:
            # HERE, AND THEN RE-RAISE. Never from `finally`: an async generator that yields
            # while GeneratorExit unwinds raises RuntimeError, the ASGI layer swallows it, and
            # the only symptom is a missing usage row for a turn NVIDIA billed in full.
            yield self._terminal(
                parts=parts,
                stop=StopReason.CANCELLED,
                usage=_estimated_usage(deltas_emitted),
                error_class=ErrorClass.USER_CANCELLATION.value,
                request_id=request_id,
                first_token_ms=first_token_ms,
                started=started,
                native_stop_reason=native_stop_reason,
                warnings=warnings,
                extras=extras,
            )
            raise
        except Exception as exc:
            # One terminal event on EVERY path, so a failure is reported through `error_class`
            # rather than by raising out of the generator and losing the turn's accounting. A
            # KbError already carries our own classification — re-classifying it would file a
            # validation defect of ours as NVIDIA's.
            failure = (
                exc
                if isinstance(exc, KbError)
                else self.classify(exc, tokens_emitted=deltas_emitted)
            )
            error_class = failure.error_class.value
            request_id = getattr(failure, "provider_request_id", None) or request_id
            yield self._terminal(
                parts=parts,
                stop=StopReason.ERROR,
                usage=_estimated_usage(deltas_emitted),
                error_class=error_class,
                request_id=request_id,
                first_token_ms=first_token_ms,
                started=started,
                native_stop_reason=native_stop_reason,
                warnings=warnings,
                extras=extras,
            )
            return

        yield self._terminal(
            parts=parts,
            stop=stop,
            usage=usage,
            error_class=error_class,
            request_id=request_id,
            first_token_ms=first_token_ms,
            started=started,
            native_stop_reason=native_stop_reason,
            warnings=warnings,
            extras=extras,
        )

    def _terminal(
        self,
        *,
        parts: list[str],
        stop: StopReason,
        usage: Usage,
        error_class: str | None,
        request_id: str | None,
        first_token_ms: int | None,
        started: float,
        native_stop_reason: str | None,
        warnings: list[CapabilityWarning],
        extras: dict[str, Any],
    ) -> ChatResult:
        """Build the one terminal event. Every exit from ``stream()`` comes through here.

        One construction site, so the three paths cannot drift on what a ``ChatResult``
        carries — the drift that shows up as a cancelled turn with no request id, discovered
        while reading an incident.
        """
        return ChatResult(
            text="".join(parts),
            stop_reason=stop,
            usage=usage,
            provider_request_id=request_id,
            first_token_ms=first_token_ms,
            total_ms=int((time.perf_counter() - started) * 1000),
            error_class=error_class,
            diagnostics=Diagnostics(
                provider=self.name,
                native_stop_reason=native_stop_reason,
                # LEFT EMPTY, ALWAYS. NVIDIA publishes no rate-limit header schema and no 429
                # body for the hosted catalog (`errors.NO_RESET_HEADER_SCHEMA` records the same
                # fact), and an invented reset time outranks the jittered backoff and pins us
                # inside the rejection window.
                rate_limit={},
                warnings=warnings,
                extras=extras,
            ),
        )

    @staticmethod
    def _usage(raw: Any) -> Usage:
        """Two totals; the other three buckets stay zero rather than being inferred."""
        # WHICH ARITHMETIC THIS IS, of the three the contract enumerates: the OpenAI SUBSET
        # pattern, in its degenerate form. The catalog reports `prompt_tokens` and
        # `completion_tokens` and nothing else — no cached amount, no reasoning amount — so
        # `prompt_tokens` IS the whole billed input and the subtraction OpenAI needs has
        # nothing to subtract. It is emphatically NOT the Anthropic SIBLING pattern: today the
        # two are indistinguishable because the cached bucket is always zero, and the day
        # NVIDIA reports one, reading it as a sibling would ADD it to an input count that
        # already contained it and over-bill by exactly the cached amount. Billing reads
        # `total_input_tokens`, so that error would be invisible in every other field.
        prompt_tokens = getattr(raw, "prompt_tokens", None)
        completion_tokens = getattr(raw, "completion_tokens", None)
        if not isinstance(prompt_tokens, int) or not isinstance(completion_tokens, int):
            # `estimated` rows are never aggregated into invoiced cost, so an absent or
            # unreadable usage object degrades ATTRIBUTION and never the bill. Inventing a
            # number here would be indistinguishable from a measurement.
            return Usage(source="estimated")
        return Usage(
            input_tokens=prompt_tokens,
            # NOT REPORTED BY THIS VENDOR. Zero because it is unobservable, not because
            # caching is absent — which is why PROMPT_CACHING is off on every NIM row.
            cache_read_tokens=0,
            cache_write_tokens=0,
            output_tokens=completion_tokens,
            # Billed INSIDE output_tokens everywhere; not broken out at all here.
            reasoning_tokens=0,
            source="provider_final",
        )

    def classify(self, exc: BaseException, *, tokens_emitted: int) -> ProviderCallFailed:
        """Status -> class via ``STATUS_TO_CLASS``.

        Two shapes with no HTTP status to read, both ``PROVIDER_TEMPORARY``: a transport-level
        connection refusal (self-hosted only — GPU exhaustion kills the container at boot and
        there is no error body to classify), and no first token inside the budget.

        Credit exhaustion has no documented status or body, so it currently lands wherever its
        status lands. If it turns out to be distinguishable it belongs in ``PROVIDER_BILLING``
        with the other never-self-healing account states.
        <!-- UNVERIFIED: NVIDIA documents no status or body for credit exhaustion. -->

        Shared with the ranking surface: same credential, same account, same status list, so
        ``STATUS_TO_CLASS`` is read the same way and 202/503 stay ``PROVIDER_TEMPORARY``. Two
        differences the caller must respect and this method cannot express:

        * ``tokens_emitted`` is always 0 from ``rerank`` — nothing streams — so the "once a
          token is out we are billed" gate never fires and a retry against the SAME connection
          is legitimate under ``RETRYABLE``.
        * Cross-provider fallback is banned regardless of class
          (``errors.NON_CHAT_FALLBACK_ELIGIBLE``). A failed rerank is an **error** and must
          never be recorded as a ``RerankSkipReason``: the skip enum is closed and contains
          only pre-call reasons, and an outage laundered into a skip degrades ranking *and*
          hides the incident.
        """
        request_id = getattr(exc, "request_id", None) or getattr(exc, "provider_request_id", None)
        if not isinstance(request_id, str):
            request_id = None

        if isinstance(
            exc,
            openai.APITimeoutError | openai.APIConnectionError | httpx.TransportError,
        ):
            # Nothing arrived, so there is no status and no body — the absence IS the evidence,
            # and it is always temporary. On a self-hosted container this is the whole of GPU
            # OOM: fail-fast supervision takes the container down and there is no HTTP error to
            # read, so classifying it here is what lets the breaker open instead of running a
            # retry ladder into a crash loop.
            return ProviderCallFailed(
                ErrorClass.PROVIDER_TEMPORARY,
                "the NVIDIA NIM request did not complete: no response was received",
                tokens_emitted=tokens_emitted,
                native_code=type(exc).__name__,
                provider_request_id=request_id,
            )

        status = _status_of(exc)
        # THE STATUS IS THE WHOLE BRANCH ON THIS VENDOR, and that is a recorded limitation
        # rather than a shortcut: NVIDIA publishes no error-code vocabulary for the hosted
        # catalog, so there is no `BODY_CODE_TO_CLASS` to read first. What is never read is the
        # MESSAGE — vendor prose is not a contract, and a substring test for "unavailable"
        # reclassifies a tenant's mistyped model id as capacity.
        error_class = STATUS_TO_CLASS.get(status or 0, UNMAPPED)
        return ProviderCallFailed(
            error_class,
            f"NVIDIA NIM refused the call (status {status})"
            if status is not None
            else "the NVIDIA NIM call failed and carried no status",
            tokens_emitted=tokens_emitted,
            native_code=_native_code(exc),
            provider_request_id=request_id,
            # NO `retry_after`. NVIDIA publishes no rate-limit or 429-header schema for the
            # catalog, so there is no reset time to read and a guessed one outranks the
            # jittered backoff (`errors.NO_RESET_HEADER_SCHEMA`).
        )

    # ── reranking (ADR-030) ───────────────────────────────────────────────────

    def validate_rerank(
        self, req: RerankRequest, caps: ModelCapabilities
    ) -> list[CapabilityWarning]:
        """Enforce the per-model passage cap and the pair-length limit BEFORE the call.

        Range checks belong here for the same reason the chat ones do — out of range is a 422
        on this vendor, not a clamp — plus one reason unique to this surface: the pair-length
        limit is enforced by **silent truncation**, so it is the one check with no error to
        catch. A truncated query-plus-passage pair scores off the distribution the threshold
        was calibrated on, it fires hardest on the largest chunks, and the only symptom is a
        refusal rate that moved months later.

        At minimum: ``len(req.passages)`` against the model row's per-request cap, and each
        query-plus-passage pair against the row's ``context_window``. Both are per-model on
        this vendor and neither is portable between two ranking rows on one connection.

        Never emits a ``CapabilityWarning`` with ``action="ignored"`` for an over-long passage.
        The only correct actions are to reject it or to have sized the chunk correctly; a
        warned truncation is still a truncation, and ``bge-reranker`` is explicit that latency
        is bought by cutting candidate depth and never passage length.
        """
        if Capability.RERANK not in caps.supported:
            # A RAISE on both settings: there is no rerank request to make without the
            # capability. `capabilities.can_rerank` is what keeps this unreachable in the
            # pipeline; reaching it means something bound a Reranker the gate refused.
            raise KbError(
                ErrorClass.VALIDATION,
                f"the provider_models row for {req.model!r} does not declare "
                "Capability.RERANK, so it cannot serve the ranking endpoint",
            )

        if len(req.passages) > MAX_RERANK_PASSAGES:
            # NEVER SPLIT INTO SEVERAL CALLS. Depth is a retrieval decision, and an adapter
            # that quietly issued five round-trips would make the retrieval budget a function
            # of a number the stage never chose.
            raise KbError(
                ErrorClass.VALIDATION,
                f"{len(req.passages)} passages exceeds the per-request ceiling of "
                f"{MAX_RERANK_PASSAGES} on NVIDIA's ranking endpoint. Candidate depth is a "
                "retrieval-stage decision and is cut there, not batched here",
            )

        if not req.query.strip():
            raise KbError(
                ErrorClass.VALIDATION,
                "the rerank query is empty. Every passage would receive a real number scored "
                "against nothing, and those numbers would then be cut against a threshold",
            )

        blank = next((i for i, text in enumerate(req.passages) if not text.strip()), None)
        if blank is not None:
            # No sentinel is ever substituted for a passage that cannot be scored: an empty
            # passage scored against a query returns a perfectly real number, and it then ranks
            # a missing passage against present ones.
            raise KbError(
                ErrorClass.VALIDATION,
                f"passages[{blank}] is empty or whitespace-only. That is a chunker defect and "
                "must surface as one rather than as a plausible score",
            )

        # THE PAIR, NOT THE PASSAGE. The window has to hold the query AND the passage, and the
        # query is charged against every one of them.
        budget = min(caps.context_window, MAX_EMBEDDING_INPUT_TOKENS)
        query_tokens = _estimated_tokens(req.query)
        for position, passage in enumerate(req.passages):
            estimated = query_tokens + _estimated_tokens(passage)
            if estimated > budget:
                raise KbError(
                    ErrorClass.VALIDATION,
                    f"the query plus passages[{position}] is an estimated {estimated} tokens "
                    f"against a {budget}-token window on {req.model!r}. This vendor TRUNCATES "
                    "the pair silently by documented default, which scores the passage off "
                    "the distribution the evidence threshold was calibrated on; the fixes are "
                    "a ranking model with a larger window or a smaller chunk, never a warning",
                )

        return []

    async def rerank(
        self,
        req: RerankRequest,
        caps: ModelCapabilities,
        credential: SecretStr,
    ) -> RerankResult:
        """Score every passage against the query. Scores in INPUT order, scale attached.

        Three things this must get right, each of which produces plausible floats when wrong:

        **Argument order.** Query first, passages second. Ranking APIs are order-sensitive and
        return perfectly reasonable numbers when reversed; the symptom is a stage that "barely
        changes the order and looks pointless". Locked by an asymmetric fixture, not by review.

        **The scatter.** The response is sorted by relevance and identifies each entry by its
        original position. Scatter it back so ``scores[i]`` is the relevance of
        ``passages[i]``, and assert the result covers every input exactly once. Reading the
        list positionally attaches sensible scores to the wrong passages. A response covering
        fewer entries than it was given is a **failure**, not a partial answer: silently
        dropped passages become evidence that vanished, and the answer is then grounded in a
        subset nobody chose.

        **The scale.** ``RerankResult.scale`` is ``capabilities.rerank_scale(self.name)``, i.e.
        ``RerankScale.LOGIT``, and it is never omitted or defaulted. It is what lets stage 12
        assert instead of assume. ``LOGIT.may_threshold`` is **True** — an unbounded scale is
        still a thresholdable one, since a threshold is a percentile read off a measured
        distribution and not a fraction of a range. What stops these scores being cut against
        today is the calibration gate, not the scale: ``CALIBRATIONS`` is empty on purpose, so
        ``calibration_for`` raises ``RerankNotCalibrated`` until an evaluation run over the
        golden corpus produces a threshold for this exact ``(provider, model)`` pair.

        ``RerankResult.usage`` is all zeros with ``source="estimated"``: the ranking response
        carries no usage object at all, so nothing here may be aggregated into invoiced cost.
        That is a contract gap and it is recorded as one — see ``RerankResult.usage``.

        No ``top_n`` is ever sent, even though the endpoint offers one: a truncated, re-sorted
        response breaks the input alignment the return type promises, and depth is a retrieval
        decision (`kb-rag-query-contract`), not a wire parameter.

        Raises ``ProviderCallFailed``. Never returns a sentinel score for a passage it could
        not score — an empty passage scored against a query returns a real number that ranks a
        missing passage against present ones.
        """
        warnings = self.validate_rerank(req, caps)

        body: dict[str, Any] = {
            "model": req.model,
            # QUERY FIRST, PASSAGES SECOND, and they are different shapes on the wire so the
            # reversal that produces plausible-but-meaningless numbers is at least typed.
            "query": {"text": req.query},
            "passages": [{"text": passage} for passage in req.passages],
            # Pinned, never sent as START or END: the other two discard text until the pair
            # fits and return 200 with a score computed on what survived.
            "truncate": TRUNCATE_POLICY,
            # NO `top_n`. The endpoint offers one and sending it would truncate and re-sort the
            # response, at which point `scores[i]` no longer refers to `passages[i]`.
        }

        client = self._ranking_client(credential, req)
        started = time.perf_counter()
        try:
            response = await client.post(
                RANKING_PATH, json=body, headers={REQUEST_ID_HEADER: req.trace_id}
            )
        finally:
            # An unclosed client leaks a connection pool per rerank call, and on the retrieval
            # path that is once per query.
            await client.aclose()
        total_ms = int((time.perf_counter() - started) * 1000)

        request_id = _adopted_request_id(getattr(response, "headers", None), req.trace_id)
        if response.status_code != HTTP_OK:
            # 202 included, and it is the reason this is not `>= 400`: a queued ranking call
            # has no scores in it and must not be read as an empty result. Every status goes
            # through the one classifier, so chat and ranking cannot drift on what a 503 means.
            raise self.classify(
                NimStatusError(response.status_code, request_id=request_id), tokens_emitted=0
            )

        payload = response.json()
        rankings = payload.get(RANKING_RESULTS_FIELD) if isinstance(payload, dict) else None
        scores = _scores_in_input_order(rankings, expected=len(req.passages))

        return RerankResult(
            scores=scores,
            # NEVER DEFAULTED AND NEVER LOCAL. The same lookup `capabilities.can_rerank` reads,
            # so the value the pipeline gates on and the value it receives cannot disagree.
            scale=rerank_scale(self.name),
            # ALL ZEROS, `estimated`. The ranking response carries no usage object at all, so
            # rerank spend is invisible to the per-org quota ADR-030 says it shares with chat.
            # Recorded as a contract gap on `RerankResult.usage` rather than papered over: a
            # token count invented from passage lengths would look real.
            usage=Usage(source="estimated"),
            provider_request_id=request_id,
            total_ms=total_ms,
            diagnostics=Diagnostics(
                provider=self.name,
                rate_limit={},
                warnings=warnings,
                extras={"truncate": TRUNCATE_POLICY, "passages": len(req.passages)},
            ),
        )

    # ── embedding (ADR-030) ───────────────────────────────────────────────────

    def validate_embedding(
        self, req: EmbeddingRequest, caps: ModelCapabilities
    ) -> list[CapabilityWarning]:
        """Reject or warn per ``caps.on_unsupported``, against a schema NVIDIA publishes.

        Four checks, and the first two are unique to this vendor among the three that embed:

        * **``input_type`` is required and is never inferred.** The catalog schema enumerates
          ``passage | query`` and says in its own description that failing to use the correct
          one "will result in large drops in retrieval accuracy". Nothing errors when it is
          wrong — recall simply falls, uniformly, for the life of the index — so a row on this
          vendor carries ``Capability.EMBEDDING_INPUT_TYPE`` and a request that omits
          ``req.input_type`` is a rejection here rather than a default applied downward.
          ``EmbeddingInputType`` records why only the caller can know which side it is on.
        * **``truncate`` is sent as ``"NONE"`` and is never sent as anything else.** The
          parameter's documented values are ``NONE | START | END``; ``NONE`` returns an error
          when the input exceeds the model's maximum, and the other two discard text until it
          fits. ``NONE`` is also the vendor's default, so this is a pin against a default
          moving rather than an override — and the pin is what makes
          ``PROVIDER_TRUNCATION_POLICY = "reject"`` true on this vendor instead of merely
          hoped for. An adapter that let ``END`` through would index a passage from its head
          and leave its tail unsearchable forever, with a 200 and a plausible vector.
        * The batch ceiling: the ``input`` array is capped at **4096 items**. Item count and
          summed tokens are different limits and both are checked.
        * The per-input length limit, **8192 tokens**, against the row's ``context_window``.
          A hard rejection, never a truncation — see the previous bullet for what the
          alternative silently costs.

        ``dimensions`` is not a parameter on this endpoint at all. A request carrying one on a
        row without ``EMBEDDING_DIMENSIONS`` follows the usual reject-or-warn path; it must
        never be forwarded, because this vendor ignores unknown body fields rather than
        rejecting them, and an ignored ``dimensions`` produces vectors of the wrong width that
        are discovered at upsert — after the whole batch has been paid for.
        """
        warnings: list[CapabilityWarning] = []

        if Capability.EMBEDDING_INPUT_TYPE not in caps.supported:
            # A RAISE ON BOTH SETTINGS, and the mirror image of the OpenAI adapter's handling
            # of the same field. There it is always a warning and never a rejection, because
            # OpenAI's models are symmetric and the parameter is inert. Here the parameter is
            # REQUIRED and load-bearing, so there is no request to make without it — and a row
            # that does not declare the flag on this vendor is describing a model that does not
            # exist. Sending it anyway on an undeclared row would be the silent drop in
            # reverse: correct on the wire, wrong in the record.
            raise KbError(
                ErrorClass.VALIDATION,
                f"the provider_models row for {req.model!r} does not declare "
                "Capability.EMBEDDING_INPUT_TYPE, and NVIDIA's embedding schema REQUIRES "
                "input_type. Its own description says failing to use the correct one 'will "
                "result in large drops in retrieval accuracy', and nothing errors when it is "
                "wrong — recall just falls, uniformly, for the life of the index",
            )

        if req.dimensions is not None:
            # ALWAYS, flag or no flag: `dimensions` is not a parameter on this endpoint. A row
            # declaring EMBEDDING_DIMENSIONS on this vendor is itself incoherent, and the
            # failure mode is the quiet one — unknown body fields are IGNORED rather than
            # rejected, so a forwarded `dimensions` returns the native width with a 200 and is
            # discovered at upsert, after the whole batch has been paid for.
            warnings.append(
                self._unsupported(
                    caps,
                    option="dimensions",
                    detail=(
                        f"NVIDIA's embedding endpoint takes no `dimensions` parameter, so "
                        f"dimensions={req.dimensions} cannot be honoured for {req.model!r}. It "
                        "is never forwarded: this vendor ignores unknown body fields rather "
                        "than rejecting them, so the request would return the model's native "
                        "width with a 200 and fail at upsert"
                    ),
                )
            )

        if len(req.texts) > MAX_EMBEDDING_INPUTS:
            raise KbError(
                ErrorClass.VALIDATION,
                f"{len(req.texts)} inputs exceeds NVIDIA's ceiling of {MAX_EMBEDDING_INPUTS} "
                "items for one embeddings request. Ingestion batches at "
                "embedder.MAX_BATCH_TEXTS and never reaches this, so a caller that does is "
                "not batching at all",
            )

        blank = next((i for i, text in enumerate(req.texts) if not text.strip()), None)
        if blank is not None:
            raise KbError(
                ErrorClass.VALIDATION,
                f"texts[{blank}] is empty or whitespace-only. An empty chunk has no embedding "
                "and no lexical vector either; it is a chunker defect and must surface as one "
                "rather than as a vendor 422 on a batch of 64",
            )

        # THE ITEM LENGTH, WHICH IS A DIFFERENT LIMIT FROM THE ITEM COUNT. Estimated, and
        # deliberately over-estimated (`ESTIMATED_CHARS_PER_TOKEN`): a false refusal costs one
        # loud error, and a false pass costs a passage indexed from its head with its tail
        # unsearchable forever. `truncate="NONE"` is the authority on the wire; this is what
        # names the offending position instead of returning a batch-wide 422.
        budget = min(caps.context_window, MAX_EMBEDDING_INPUT_TOKENS)
        for position, text in enumerate(req.texts):
            estimated = _estimated_tokens(text)
            if estimated > budget:
                raise KbError(
                    ErrorClass.VALIDATION,
                    f"texts[{position}] is an estimated {estimated} tokens against a "
                    f"{budget}-token per-input limit on {req.model!r}. This is a hard "
                    "rejection and never a truncation: START and END discard text until it "
                    "fits and return a plausible vector for a passage whose tail is then "
                    "unsearchable forever",
                )

        return warnings

    async def embed(
        self,
        req: EmbeddingRequest,
        caps: ModelCapabilities,
        credential: SecretStr,
    ) -> EmbeddingResult:
        """``POST /v1/embeddings``. Vectors in INPUT order, never the vendor's response order.

        Re-sorts the response on each entry's ``index`` and asserts the result covers every
        input exactly once. Never returns a partial result: a batch that half succeeded would
        write half a source version's vectors and the version would publish as complete.

        ``EmbeddingResult.space`` is filled from ``app.retrieval.collection.EmbeddingSpace``
        with the width **actually returned** — 1024 on ``nv-embedqa-e5-v5``, which is also the
        width of ``baai/bge-m3`` and of a truncated ``text-embedding-3-large``, so the width is
        never the identity — and ``normalized`` from what the response actually contains rather
        than from the documentation.

        The 202 is this endpoint's alone among the three embedders and it must be classified
        before the body is parsed: the catalog's Retrieval family publishes a companion GET
        that "gets the result of an earlier function invocation request that returned a status
        of 202", so a 202 is a queued call with no vectors in it. It maps to
        ``PROVIDER_TEMPORARY`` through ``STATUS_TO_CLASS`` like every other queued response
        here, and it may retry the SAME connection; what it may never do is fall back to
        another vendor, because a different vendor is a different vector space
        (``errors.NON_CHAT_FALLBACK_ELIGIBLE``).
        """
        warnings = self.validate_embedding(req, caps)

        input_type = EMBEDDING_INPUT_TYPES[req.input_type]
        client = self._embedding_client(credential, req)
        started = time.perf_counter()
        raw = await client.embeddings.with_raw_response.create(
            model=req.model,
            input=list(req.texts),
            # `input_type` and `truncate` are NVIDIA's, not OpenAI's, so they travel in the
            # SDK's documented escape hatch rather than as keyword arguments it would reject.
            # NO `dimensions`, ever: `validate_embedding` has already refused it and this
            # vendor would ignore it rather than reject it.
            extra_body={"input_type": input_type, "truncate": TRUNCATE_POLICY},
            extra_headers={REQUEST_ID_HEADER: req.trace_id},
            timeout=_timeout(req.timeouts.connect, req.timeouts.total, req.timeouts.total),
        )
        total_ms = int((time.perf_counter() - started) * 1000)

        request_id = _adopted_request_id(getattr(raw, "headers", None), req.trace_id)
        if raw.status_code == HTTP_ACCEPTED:
            # BEFORE `parse()`. A 202 is a queued invocation with no vectors in it; parsing it
            # would produce an empty `data` and a cover assertion that fires with the wrong
            # explanation.
            raise self.classify(
                NimStatusError(raw.status_code, request_id=request_id), tokens_emitted=0
            )

        response = raw.parse()
        vectors = _vectors_in_input_order(response.data, expected=len(req.texts))
        width = len(vectors[0])

        # EVERY VECTOR, NOT THE FIRST. A batch whose widths disagree is a vendor bug we would
        # otherwise launder into a collection: the first width names the space, the rest upsert
        # against it, and Qdrant rejects only the ones that differ — mid-run, after the spend.
        for position, vector in enumerate(vectors):
            if len(vector) != width:
                raise KbError(
                    ErrorClass.PROVIDER_PERMANENT_REQUEST,
                    f"NVIDIA NIM returned vectors of differing widths in one batch: "
                    f"texts[0] is {width}-wide and texts[{position}] is {len(vector)}-wide. "
                    "One batch is one embedding space by definition",
                )

        served = getattr(response, "model", None)
        return EmbeddingResult(
            vectors=vectors,
            space=EmbeddingSpace(
                provider=self.name,
                # The SERVED id when the response carries one. The catalog retires ids on no
                # published schedule, so what answered is not necessarily what was asked for,
                # and the space is what names the collection.
                model=served if isinstance(served, str) and served else req.model,
                # THE WIDTH ACTUALLY RETURNED, never a constant per model id: 1024 here is
                # also `baai/bge-m3`'s width and a truncated `text-embedding-3-large`'s, and
                # all three upsert cleanly into one collection while meaning nothing to
                # each other.
                dimensions=width,
            ),
            normalized=_is_unit_norm(vectors[0]),
            usage=self._embedding_usage(getattr(response, "usage", None)),
            provider_request_id=request_id,
            total_ms=total_ms,
            diagnostics=Diagnostics(
                provider=self.name,
                rate_limit={},
                warnings=warnings,
                # The two parameters that decide whether this index is correct, recorded so a
                # recall investigation can read what was actually sent. Neither is tenant text.
                extras={"input_type": input_type, "truncate": TRUNCATE_POLICY},
            ),
        )

    @staticmethod
    def _embedding_usage(raw: Any) -> Usage:
        """``prompt_tokens`` is the entire cost. There is no cached bucket to subtract.

        Separate from ``_usage`` on purpose, even though both read the same two field names
        off this vendor. The chat response's ``completion_tokens`` is meaningless here —
        nothing is generated — and the day NVIDIA adds a cached amount to one surface and not
        the other, a shared helper would apply the wrong arithmetic to whichever it was not
        written for::

            Usage.input_tokens       = usage.prompt_tokens
            Usage.cache_read_tokens  = 0     # not reported on this endpoint
            Usage.cache_write_tokens = 0
            Usage.output_tokens      = 0     # nothing is generated
            Usage.reasoning_tokens   = 0
            Usage.source             = "provider_final"

        The response also carries ``total_tokens``. It is deliberately not read: it is a
        derived sum, and taking it would let a vendor-side change to what the sum includes
        become a billing change here with nothing to compare against. Billing reads
        ``total_input_tokens``.
        """
        prompt_tokens = getattr(raw, "prompt_tokens", None)
        if not isinstance(prompt_tokens, int):
            # `estimated` rows are never aggregated into invoiced cost, so an unreadable usage
            # block degrades the ATTRIBUTION and never the bill.
            return Usage(source="estimated")
        return Usage(
            input_tokens=prompt_tokens,
            cache_read_tokens=0,
            cache_write_tokens=0,
            # NOTHING IS GENERATED HERE. Copying `_usage` would read `completion_tokens` off a
            # response that has none and report the bucket as estimated for a call that was
            # exactly measured.
            output_tokens=0,
            reasoning_tokens=0,
            source="provider_final",
        )


def _assert_conforms(adapter: NimAdapter) -> ProviderAdapter:
    """Static conformance, checked by mypy and costing nothing at runtime.

    If a method here drifts from the Protocol — a renamed parameter, a changed return type —
    this return is the error. Without it the drift surfaces at the one call site that
    matters, in the streaming path, at request time.
    """
    return adapter


def _assert_reranks(adapter: NimAdapter) -> RerankAdapter:
    """The static half of the rerank capability gate.

    This function existing **is** the claim that NIM reranks, and it is the claim mypy can
    check. The queryable half is ``capabilities.PROVIDER_TASKS[("nvidia_nim", RERANK)]``, and
    ``tests/unit/test_provider_capability_matrix.py`` asserts the two agree in both directions.

    One of two such claims in the package: ``OpenRouterAdapter`` carries the other. The two
    are not interchangeable and the difference is the score scale, not the route — NIM's is a
    characterized ``LOGIT`` in ``capabilities.RERANK_SCALE`` and OpenRouter's is deliberately
    absent from it, so only one of the two can ever feed an evidence threshold.
    """
    return adapter


def _assert_embeds(adapter: NimAdapter) -> EmbeddingAdapter:
    """The static half of the embedding capability gate.

    This module is the only one in the package that carries **both** non-chat gates, which is
    the concrete reason ``assert_row_coherent`` refuses a row claiming two task families: one
    connection here reaches an embedding endpoint and a ranking endpoint that share nothing
    but a credential — different routes, different request schemas, different response shapes,
    and a score on one and a vector on the other.

    This function did not exist while the matrix recorded NIM's embedding cell as
    ``UNVERIFIED``, and the cell was ``UNVERIFIED`` only because nobody had read the catalog.
    ``bge-reranker`` titles its official-docs entry "NVIDIA NIM retrieval / ranking models" and
    'retrieval' is NVIDIA's own word for this family — the hint was right there and a link
    title was correctly refused as a source. The catalog reference page now cited in
    ``capabilities.PROVIDER_TASKS`` is one, and the method and the cell moved together.
    """
    return adapter
