"""Anthropic Claude, through the Messages API. Skeleton; signatures are final.

SDK ``anthropic==0.120.2``, API version header ``2023-06-01``. Named for its vendor, like
three of its four siblings; ``openai_adapter.py`` is the one that breaks the pattern, for
the reason recorded in ``services/ai-service/README.md``.

## Divergences this file exists to absorb

**``input_tokens`` EXCLUDES cached tokens.** ``cache_read_input_tokens`` and
``cache_creation_input_tokens`` are *siblings*, not subsets, and total input is the sum of
all three. This is the exact opposite of OpenAI, and it is the reason usage normalization
cannot be shared code written once: here the mapping is a verbatim assignment into our
already-disjoint buckets, and the bug is "helpfully" subtracting. Read ``input_tokens`` as
"the input" and a 200k-token cached document with a 50-token question reports 50 input
tokens — under-billing by the entire prefix, in the opposite direction from the OpenAI
mistake.

**A third number exists for rate limiting.** ``cache_read_input_tokens`` does not count
toward ITPM on any model we ship, so ITPM headroom is ``input_tokens +
cache_creation_input_tokens`` — neither our ``total_input_tokens`` nor the raw
``input_tokens``. A dashboard built on either of those disagrees with the 429s.

**``message_delta.usage`` is CUMULATIVE, not incremental.** Assign; never accumulate.
Accumulating triple-counts output on a long stream, and the error grows with answer length,
so it is invisible in short test fixtures.

**Anthropic is the only vendor here that reports input usage BEFORE generation.**
``message_start`` carries ``input_tokens``, so an aborted turn still bills exactly — that
path sets ``Capability.EARLY_INPUT_USAGE`` and ``source="provider_partial"`` instead of the
``"estimated"`` every other vendor is stuck with.

**``effort`` is nested inside ``output_config``**, never a top-level parameter. Top-level is
a ``TypeError`` from the SDK signature and a 400 on raw HTTP. ``output_config`` also carries
``format`` for structured output, so it is not a thinking-only object.

**Extended thinking is ``thinking={"type": "adaptive"}``.** The
``{"type": "enabled", "budget_tokens": N}`` shape is a 400 on every model we ship.
``ReasoningOption.budget_tokens`` therefore never becomes a request field here — it becomes
a ``CapabilityWarning``.

**Thinking is billed but not returned by default.** ``thinking.display`` defaults to
``"omitted"``: the thinking block opens, receives one ``signature_delta``, and closes with
no ``thinking_delta`` at all, while the full trace is charged. That is why
``REASONING_TRACE`` is a separate flag from ``REASONING`` — a pane gated on ``REASONING``
renders empty forever and the cost line still climbs.

**``effort: "none"`` is the one lossy step in our ladder.** Opus 5 accepts
``thinking={"type": "disabled"}`` only at effort <= high, so ``none`` pins effort to
``"high"`` and warns. ``xhigh`` maps one-to-one — that is precisely why the portable enum
carries it.

**The SDK emits every token twice.** Iterating the stream fires the raw
``content_block_delta`` AND a synthetic helper event (``text``, ``thinking``, ``signature``,
``input_json``, ``citation``) built from the same delta. Match raw event types only, or the
widget renders every token twice.

**A mid-stream overload reads as HTTP 200.** An SSE ``event: error`` is raised against the
ORIGINAL 200 response, so it downgrades to a bare ``APIStatusError`` with
``status_code == 200`` and escapes ``except OverloadedError`` entirely. Branch on
``exc.type``, or routine capacity blips are filed as our bug and page somebody.

**Sampling is a 400 on every request**, thinking or not, on recent models — not an ignore.

**``max_tokens`` caps thinking plus visible text**, and recent models accept an over-budget
value at validation time and stop mid-generation instead of erroring. A route that never set
``thinking`` and sized ``max_tokens`` tightly starts truncating silently the moment it moves
to a model where thinking is on by default.

**Cache breakpoints belong on the last SYSTEM block only.** Placed on the retrieved context
blocks, every turn writes a fresh entry at ~1.25x and never reads one: hit rate 0%, cost up,
no error. Second cause with identical symptoms — the prefix is below the model's minimum
(512 tokens on Opus 5, 1024 on Sonnet 5, 4096 on the 4.6 line and Haiku 4.5).

**The request id header has no prefix**: ``request-id``, plus ``request_id`` in the error
body. OpenAI spells it ``x-request-id``.

## Stream event -> internal event

| Messages event | Internal |
|---|---|
| ``message_start`` | no delta; seeds ``Usage`` with exact input, ``source="provider_partial"`` |
| ``content_block_start`` | nothing — the text is empty, and TTFT timed from it is fiction |
| ``content_block_delta`` / ``text_delta`` | ``Delta(kind="text", index=event.index)`` |
| ``content_block_delta`` / ``thinking_delta`` | ``Delta(kind="reasoning")``; absent if omitted |
| ``content_block_delta`` / ``input_json_delta`` | ``Delta(kind="tool_args")`` |
| ``content_block_delta`` / ``signature_delta`` | dropped; it is an integrity token, not content |
| ``message_delta`` | cumulative usage (ASSIGN), and the stop reason |
| ``message_stop`` | terminal ``ChatResult`` |
| ``ping`` | ignored |
| ``error`` (mid-stream, inside a 200) | terminal ``ChatResult``, class from ``exc.type`` |

## Usage normalization

Siblings, so a verbatim assignment::

    Usage.input_tokens       = usage.input_tokens              # already excludes cache
    Usage.cache_read_tokens  = usage.cache_read_input_tokens
    Usage.cache_write_tokens = usage.cache_creation_input_tokens
    Usage.output_tokens      = usage.output_tokens
    Usage.reasoning_tokens   = <thinking, inside output_tokens>

Input fields are present on some models and absent on others in ``message_delta``, so the
merge takes only non-None fields — an absent field is "unchanged", not zero.
"""

from __future__ import annotations

import asyncio
import time
from collections.abc import AsyncIterator, Mapping
from typing import Any, Final, Literal, cast

import anthropic
import httpx
from pydantic import SecretStr

from app.core.errors import ErrorClass, KbError
from app.providers.contract import (
    Capability,
    CapabilityWarning,
    ChatRequest,
    ChatResult,
    Delta,
    Diagnostics,
    ModelCapabilities,
    ProviderAdapter,
    StopReason,
    StreamEvent,
    Timeouts,
    Usage,
)
from app.providers.errors import UNMAPPED, ProviderCallFailed, retry_after_seconds

__all__ = ["AnthropicAdapter"]

#: The three values ``Usage.source`` may take. Aliased so ``_usage``'s ``source: str``
#: parameter — whose signature is fixed by the skeleton — has one place to be narrowed.
#: The cast is safe because ``Usage`` is a Pydantic model and validates on construction: a
#: value outside this set raises rather than being stored.
UsageSource = Literal["provider_final", "provider_partial", "estimated"]

#: Verified against platform.claude.com/docs model documentation on 2026-08-04.
#: Documentation and connection-save validation only; capabilities come from
#: ``provider_models.capability_flags``.
#:
#: ``claude-haiku-4-5`` is catalogued deliberately WITHOUT ``Capability.REASONING``:
#: ``output_config.effort`` errors on the pre-4.6 line, and the deprecated ``budget_tokens``
#: shape is one we never ship. Without the flag the adapter omits ``thinking`` and ``effort``
#: both, and the question does not arise.
PINNED_MODELS: Final[tuple[str, ...]] = (
    "claude-opus-5",
    "claude-sonnet-5",
    "claude-haiku-4-5",
)

#: Our seven-level portable effort onto Anthropic's five. ``minimal`` and ``none`` both
#: collapse upward because Opus 5 rejects disabled thinking above effort ``high`` — both
#: emit a ``CapabilityWarning`` rather than rounding in silence.
EFFORT: Final[dict[str, str]] = {
    "none": "high",
    "minimal": "high",
    "low": "low",
    "medium": "medium",
    "high": "high",
    "xhigh": "xhigh",
    "max": "max",
}

#: Native stop reason -> ours. Anything absent becomes ``StopReason.ERROR`` with
#: ``native_stop_reason`` preserved: Anthropic adds stop reasons under its versioning policy
#: (``refusal`` and ``pause_turn`` both arrived that way), and a fall-through to ``COMPLETE``
#: would ship a truncated answer as a finished one.
STOP: Final[dict[str, StopReason]] = {
    "end_turn": StopReason.COMPLETE,
    "stop_sequence": StopReason.STOP_SEQUENCE,
    "tool_use": StopReason.TOOL_USE,
    "pause_turn": StopReason.TOOL_USE,
    "refusal": StopReason.REFUSAL,
    #: OUR cap. The answer is truncated.
    "max_tokens": StopReason.MAX_OUTPUT,
    #: The MODEL's window, mid-generation.
    "model_context_window_exceeded": StopReason.CONTEXT_EXCEEDED,
}

#: ``error.type`` from the body -> class. The body type is read FIRST and the status code
#: only as a tiebreak, because a mid-stream error carries the original 200.
#:
#: ``billing_error`` (402) is ``PROVIDER_BILLING``, not ``PROVIDER_AUTH``. The retry and
#: fallback policy is identical, but the class is what a runbook and a dashboard key on, and
#: an exhausted balance must not read as a revoked key. The `anthropic-api` skill still maps
#: it to ``provider_auth``, which predates the billing class; the taxonomy wins.
#:
#: ``permission_error`` (403) covers BOTH "this key lacks permission" and "your org is not
#: entitled to this model". Default to ``PROVIDER_AUTH`` and reclassify to
#: ``PROVIDER_PERMANENT_REQUEST`` only when ``models.retrieve(req.model)`` 404s under the
#: same credential — neither retries nor falls back, so the only cost of confusing them is
#: paging the wrong person and leaving a dead model in the catalog.
ERROR_TYPE_TO_CLASS: Final[dict[str, ErrorClass]] = {
    "overloaded_error": ErrorClass.PROVIDER_TEMPORARY,
    "api_error": ErrorClass.PROVIDER_TEMPORARY,
    "timeout_error": ErrorClass.PROVIDER_TEMPORARY,
    "rate_limit_error": ErrorClass.PROVIDER_RATE_LIMIT,
    "authentication_error": ErrorClass.PROVIDER_AUTH,
    "permission_error": ErrorClass.PROVIDER_AUTH,
    "billing_error": ErrorClass.PROVIDER_BILLING,
    "not_found_error": ErrorClass.PROVIDER_PERMANENT_REQUEST,
    "invalid_request_error": ErrorClass.PROVIDER_PERMANENT_REQUEST,
}

#: The TIEBREAK, consulted only when the body carried no ``error.type`` we recognise. It is
#: deliberately secondary and never primary: a mid-stream ``event: error`` is raised against
#: the ORIGINAL 200 response, so ``status_code`` reads 200 on precisely the overload this
#: adapter most needs to call temporary. A status-first reading files a routine capacity blip
#: as our own defect and pages somebody.
#:
#: 200 is absent on purpose. An error carrying a 200 and no recognised type is not evidence of
#: anything, so it falls through to ``errors.UNMAPPED`` — permanent, alertable, and never a
#: temporary default that would retry a request which will never succeed.
_STATUS_TO_CLASS: Final[dict[int, ErrorClass]] = {
    401: ErrorClass.PROVIDER_AUTH,
    402: ErrorClass.PROVIDER_BILLING,
    403: ErrorClass.PROVIDER_AUTH,
    404: ErrorClass.PROVIDER_PERMANENT_REQUEST,
    413: ErrorClass.PROVIDER_PERMANENT_REQUEST,
    429: ErrorClass.PROVIDER_RATE_LIMIT,
    500: ErrorClass.PROVIDER_TEMPORARY,
    502: ErrorClass.PROVIDER_TEMPORARY,
    503: ErrorClass.PROVIDER_TEMPORARY,
    504: ErrorClass.PROVIDER_TEMPORARY,
    529: ErrorClass.PROVIDER_TEMPORARY,
}

#: The two portable effort levels Anthropic has no step for. Both round UP to ``high`` in
#: ``EFFORT`` — Opus 5 rejects disabled thinking above that — and both emit a warning, because
#: a level that silently becomes a different level is a configuration the tenant chose and
#: never received.
LOSSY_EFFORT: Final[frozenset[str]] = frozenset({"none", "minimal"})

#: Captured off the 200 response as well as off a 429 — the limits are published on every
#: response, and the one that matters is the one read before a stream dies mid-flight.
#:
#: A PREFIX rather than a fixed tuple, because Anthropic documents a header *family*:
#: ``anthropic-ratelimit-{bucket}-reset``, bucket an infix and ``-reset`` a suffix. That is the
#: mirror image of OpenAI's ``x-ratelimit-reset-{bucket}``, and a matcher written against the
#: OpenAI shape reads ZERO headers here with no error at all — ``retry_after_seconds`` just
#: returns None and the caller silently loses its backoff floor (`app/providers/errors.py`).
RATE_LIMIT_HEADER_PREFIX: Final[str] = "anthropic-ratelimit-"

#: The raw Messages event types this adapter translates. Matched EXCLUSIVELY, because the SDK
#: fires a synthetic helper event (``text``, ``thinking``, ``signature``, ``input_json``,
#: ``citation``) built from the same delta — matching both renders every token twice.
RAW_EVENT_TYPES: Final[frozenset[str]] = frozenset(
    {"message_start", "message_delta", "message_stop", "content_block_delta"}
)


def _text_blocks(req: ChatRequest) -> list[dict[str, Any]]:
    """The ``system`` parameter: the bot instruction, and nothing else.

    A list of text blocks rather than a bare string so a cache breakpoint has somewhere to
    sit. Retrieved evidence is NEVER concatenated in here — source text that reaches the
    instruction slot is prompt injection with our own retrieval pipeline as the delivery
    mechanism (`kb-security-baseline`, `kb-rag-query-contract` stage 14).
    """
    return [{"type": "text", "text": req.system}]


def _messages(req: ChatRequest) -> list[dict[str, Any]]:
    """Evidence first as its own user turn, then the conversation, in a stable order.

    Ordering is load-bearing rather than cosmetic. Prefix caching is exact string matching, so
    anything volatile at the front — a re-sorted evidence list, a timestamp — is a total cache
    miss that costs more and reports as normal. ``context_blocks`` are emitted in ``index``
    order, which is the order assigned from the evidence set before generation and therefore
    the order the citation map describes.

    The evidence turn is a ``user`` message carrying one text block per chunk. It is data, not
    instruction: nothing here tells the model what to do with it.
    """
    messages: list[dict[str, Any]] = []
    if req.context_blocks:
        messages.append(
            {
                "role": "user",
                "content": [
                    {
                        "type": "text",
                        "text": f'<document index="{block.index}" '
                        f'title="{block.title}">\n{block.text}\n</document>',
                    }
                    for block in sorted(req.context_blocks, key=lambda b: b.index)
                ],
            }
        )
    for message in req.messages:
        content: list[dict[str, Any]] = [{"type": "text", "text": message.content}]
        messages.append({"role": message.role, "content": content})
    if req.images and messages and messages[-1]["role"] == "user":
        # Base64 only — a URL the vendor fetches is a tenant-supplied fetch outside our own
        # hardened fetch path (`ImageInput`).
        messages[-1]["content"].extend(
            {
                "type": "image",
                "source": {
                    "type": "base64",
                    "media_type": image.media_type,
                    "data": image.data_b64,
                },
            }
            for image in req.images
        )
    return messages


def _rate_limit(headers: Any) -> dict[str, str]:
    """The tenant-safe subset of the response headers. Never the whole header set.

    A raw header dump carries ``anthropic-organization-id`` and, on some proxies, the request
    echo — and ``Diagnostics`` is rendered into a conversation panel.
    """
    if not isinstance(headers, Mapping):
        return {}
    captured: dict[str, str] = {}
    for key, value in headers.items():
        if not isinstance(key, str) or not isinstance(value, str):
            continue
        name = key.lower()
        if name.startswith(RATE_LIMIT_HEADER_PREFIX) or name == "retry-after":
            captured[name] = value
    return captured


def _request_id(exc: BaseException) -> str | None:
    """``request-id`` — no ``x-`` prefix, unlike every other vendor here.

    Read from the SDK's own attribute first (which lifts it off the header), then from
    ``request_id`` in the error body, then from the header directly. Three sources because it
    is the only handle Anthropic support accepts and it is missing on exactly the failures
    worth asking about if any one of them is skipped.
    """
    direct = getattr(exc, "request_id", None)
    if isinstance(direct, str) and direct:
        return direct
    body = getattr(exc, "body", None)
    if isinstance(body, Mapping):
        from_body = body.get("request_id")
        if isinstance(from_body, str) and from_body:
            return from_body
    headers = getattr(getattr(exc, "response", None), "headers", None)
    if isinstance(headers, Mapping):
        from_header = headers.get("request-id")
        if isinstance(from_header, str) and from_header:
            return from_header
    return None


def _error_type(exc: BaseException) -> str | None:
    """The vendor's own ``error.type``, which is the primary classification axis.

    ``APIStatusError`` parses it off the body into ``.type`` for us, but only when the body
    was valid JSON with the expected shape — a proxy that returns HTML with a JSON content
    type leaves it None — so the body is read as a fallback. Never the message text: vendor
    prose is not a contract and is reworded without notice.
    """
    attribute = getattr(exc, "type", None)
    if isinstance(attribute, str) and attribute:
        return attribute
    body = getattr(exc, "body", None)
    if isinstance(body, Mapping):
        error = body.get("error")
        if isinstance(error, Mapping):
            kind = error.get("type")
            if isinstance(kind, str) and kind:
                return kind
    return None


def _count(value: Any) -> int:
    """One reported token count, or 0 when the field is absent.

    ``bool`` is excluded explicitly because ``isinstance(True, int)`` is True in Python and a
    truthy flag arriving where a count is expected would be billed as one token.
    """
    return value if isinstance(value, int) and not isinstance(value, bool) else 0


def _stream_metadata(vendor_stream: Any) -> tuple[str | None, dict[str, str]]:
    """Request id and rate-limit headers, read BEFORE the first iteration.

    A stream that dies mid-flight takes them with it, and they are what a support ticket and
    the backoff floor are both built from. Defensive at every hop: this runs before we know
    whether anything about the response is well formed.
    """
    response = getattr(vendor_stream, "response", None)
    request_id = getattr(vendor_stream, "request_id", None)
    if not isinstance(request_id, str) or not request_id:
        request_id = None
    return request_id, _rate_limit(getattr(response, "headers", None))


class AnthropicAdapter:
    """Satisfies ``ProviderAdapter``.

    Capability flags expected on an Opus 5 / Sonnet 5 row: ``TEXT``, ``IMAGE_INPUT``,
    ``TOOL_USE``, ``STRUCTURED_OUTPUT`` (``output_config.format``; the accepted schema subset
    differs from OpenAI's — ``allOf`` and ``const`` are fine, recursion and numeric bounds
    are not), ``REASONING``, ``PROMPT_CACHING``, ``STREAM_USAGE``, and — uniquely —
    ``EARLY_INPUT_USAGE``. ``SAMPLING`` is OFF: recent models reject non-default sampling on
    every request. ``REASONING_TRACE`` is ON only where a summarized display is actually
    requested and returned.

    **No ``embed`` and no ``rerank``. Both are now closed questions, sourced to Anthropic.**

    * Rerank is ``UNSUPPORTED``: ``bge-reranker`` and ``nvidia-nim-api`` both state "OpenAI,
      Anthropic and DeepSeek do not offer one", and the SDK's own resource index lists three
      families — Messages, Models, Beta — with no ranking route.
    * Embedding is ``UNSUPPORTED``, in the vendor's own words. Under "How to get embeddings
      with Anthropic", ``docs.claude.com/en/docs/build-with-claude/embeddings`` says
      **"Anthropic does not offer its own embedding model"** and recommends a third party.
      This cell used to be ``UNVERIFIED`` — nobody had read the page — and the difference
      matters, because the verdict is the same and the *consequence* is not: unchecked is a
      fixture somebody owes, while this is permanent.

    That second bullet is the sharp end of finding C1 and it belongs in this file rather than
    only in the matrix, because this is the adapter an operator is looking at when they ask
    why their upload failed. **An organization whose only connection is Anthropic cannot
    ingest a single document.** Not slower, not unreranked: every chunk must be embedded
    before it can be indexed, there is no serve-the-fused-order fallback the way there is for
    reranking, and no amount of editing the ``provider_models`` row can conjure an endpoint.
    The organization needs a second connection to a vendor that embeds — today OpenAI, NVIDIA
    NIM or OpenRouter — and ``embedding_selection.resolve_embedding_connection`` is what says
    so, at save time, before a parse is paid for.

    Adding either method here without first moving its matrix cell fails
    ``tests/unit/test_provider_capability_matrix.py``, which is the point: the two gates —
    absence of the method, and the matrix — are asserted against each other so that neither can
    move alone.
    """

    name = "anthropic"
    min_useful_seconds = 5.0

    def __init__(self, http_client: Any | None = None) -> None:
        """One shared connection pool, process-wide; the client wrapper is per call.

        A fresh ``AsyncAnthropic()`` per turn builds a fresh httpx pool, so every turn pays a
        TCP and TLS handshake against the first-token budget — measured at roughly 150 ms.
        Share one ``DefaultAsyncHttpxClient``, construct the wrapper per call so the key stays
        request-scoped, and never close the wrapper: closing it closes the shared pool for
        every other in-flight turn.
        """
        self._http_client = http_client

    def _client(self, credential: SecretStr) -> Any:
        """``AsyncAnthropic(api_key=..., max_retries=0, http_client=<shared pool>)``.

        ``max_retries`` defaults to 2 and those attempts never appear in our spans.

        THE ONE ``get_secret_value`` CALL IN THIS MODULE — and it is written without parentheses
        here so a grep for the call site finds the code and not this sentence. Extracting the
        key is a greppable act
        rather than an accident of serialization, and the plaintext exists only inside this
        frame: the wrapper is not stored on the adapter, and nothing else here touches the
        ``SecretStr``.

        The timeout here is a FLOOR, not the policy. This signature takes no request — the
        per-call values live on ``req.timeouts`` and each call passes its own ``timeout=``
        override, which is the SDK's documented per-request escape hatch. What it must never
        become is a call with no override, silently inheriting the contract defaults below
        instead of the tenant's configured budget.
        """
        defaults = Timeouts()
        return anthropic.AsyncAnthropic(
            api_key=credential.get_secret_value(),
            # ALWAYS 0. Three tiers x three attempts is 27 provider calls from one click, and
            # 26 of them are invisible in our own logs. Retry belongs to `call_with_policy`.
            max_retries=0,
            # One pool for the process, the wrapper per call. A fresh `AsyncAnthropic()` builds
            # a fresh httpx pool, so every turn pays a TCP and TLS handshake against the
            # first-token budget — and the pool is never closed here, because closing it closes
            # it for every other in-flight turn.
            http_client=self._http_client,
            timeout=httpx.Timeout(
                defaults.total,
                connect=defaults.connect,
                # Between chunks, not total. A model emitting one token every fifteen seconds
                # never trips this; the caller's absolute deadline is what enforces the total.
                read=defaults.first_token,
                write=defaults.connect,
                pool=defaults.connect,
            ),
        )

    def _build(self, req: ChatRequest, caps: ModelCapabilities) -> dict[str, Any]:
        """Build the Messages kwargs.

        ``system`` is the bot instruction only, as a list of text blocks so a cache
        breakpoint has somewhere to sit. Retrieved evidence is rendered into the message
        turns as data blocks and never into ``system``.

        ``thinking`` is ``{"type": "adaptive"}`` with ``display`` set to ``"summarized"``
        only when ``include_trace`` is set AND ``REASONING_TRACE`` is on; otherwise omitted,
        which is the vendor default and returns nothing while billing everything.

        ``effort`` lives at ``output_config["effort"]`` and nowhere else. A request-shape
        test asserts no top-level ``effort`` and no ``budget_tokens`` in any built payload.
        """
        system = _text_blocks(req)
        if req.cache_hint == "prefix" and Capability.PROMPT_CACHING in caps.supported:
            # THE LAST SYSTEM BLOCK, AND NOWHERE ELSE. Placed on the retrieved context blocks
            # instead, every turn writes a fresh entry at ~1.25x and reads none: hit rate 0%,
            # cost up, no error anywhere, because evidence differs per question.
            system[-1]["cache_control"] = {"type": "ephemeral"}

        kwargs: dict[str, Any] = {
            "model": req.model,
            # Caps thinking PLUS visible text, and recent models accept an over-budget value at
            # validation time and truncate mid-generation rather than erroring. The stop reason
            # is the only thing that says so — see `STOP["max_tokens"]`.
            "max_tokens": req.max_output_tokens,
            "system": system,
            "messages": _messages(req),
        }

        if Capability.REASONING in caps.supported:
            trace = bool(req.reasoning and req.reasoning.include_trace)
            kwargs["thinking"] = {
                # NOT {"type": "enabled", "budget_tokens": N} — that shape is a 400 on every
                # model we ship, which is why `ReasoningOption.budget_tokens` becomes a warning
                # in `validate()` and never a request field.
                "type": "adaptive",
                # Only ask for the trace when a pane will actually render it. The trace is
                # billed in full either way, so `display` changes what we receive and not what
                # we pay — and `REASONING_TRACE` is the flag that says the pane exists.
                "display": "summarized"
                if trace and Capability.REASONING_TRACE in caps.supported
                else "omitted",
            }
            # NESTED, NEVER TOP-LEVEL. A top-level `effort=` is a TypeError from the SDK
            # signature and a 400 on raw HTTP.
            kwargs["output_config"] = {
                "effort": EFFORT[req.reasoning.effort if req.reasoning else "high"]
            }

        if req.response_schema is not None and Capability.STRUCTURED_OUTPUT in caps.supported:
            # `output_config` carries `format` as well as `effort`, so it is not a thinking-only
            # object and must be extended rather than replaced.
            output_config: dict[str, Any] = kwargs.setdefault("output_config", {})
            output_config["format"] = {"type": "json_schema", "schema": req.response_schema}

        if req.temperature is not None and Capability.SAMPLING in caps.supported:
            kwargs["temperature"] = req.temperature

        if req.tools and Capability.TOOL_USE in caps.supported:
            # `input_schema`, not `parameters` — the field is named differently here from the
            # OpenAI-shaped vendors, and a wrong name is a 400 rather than an ignore.
            kwargs["tools"] = [
                {
                    "name": tool.name,
                    "description": tool.description,
                    "input_schema": tool.parameters,
                }
                for tool in req.tools
            ]

        return kwargs

    def validate(self, req: ChatRequest, caps: ModelCapabilities) -> list[CapabilityWarning]:
        """Reject or warn per ``caps.on_unsupported``.

        Always warns on ``reasoning.budget_tokens``: the shape that carried it is a 400 here,
        so it is dropped — visibly.
        """
        warnings: list[CapabilityWarning] = []

        # SAMPLING FIRST, because it is the one people assume is an ignore. Recent Claude
        # models reject non-default temperature/top_p/top_k on EVERY request, thinking or not,
        # with a 400 — so an ungated house default looks like an outage on the fallback
        # dashboard rather than like the configuration error it is.
        if req.temperature is not None and Capability.SAMPLING not in caps.supported:
            warnings.append(
                self._unsupported(
                    caps,
                    option="temperature",
                    detail=f"{req.model} rejects non-default sampling on every request, "
                    "thinking or not; it is a 400 and not an ignore",
                )
            )

        if req.response_schema is not None and Capability.STRUCTURED_OUTPUT not in caps.supported:
            warnings.append(
                self._unsupported(
                    caps,
                    option="response_schema",
                    detail=f"{req.model} does not accept output_config.format, so a schema "
                    "would be dropped and the answer would come back as prose",
                )
            )

        if req.images and Capability.IMAGE_INPUT not in caps.supported:
            warnings.append(
                self._unsupported(
                    caps,
                    option="images",
                    detail=f"{req.model} does not accept image blocks",
                )
            )

        if req.tools and Capability.TOOL_USE not in caps.supported:
            warnings.append(
                self._unsupported(
                    caps,
                    option="tools",
                    detail=f"{req.model} does not accept tool definitions",
                )
            )

        if req.cache_hint == "prefix" and Capability.PROMPT_CACHING not in caps.supported:
            warnings.append(
                self._unsupported(
                    caps,
                    option="cache_hint",
                    detail=f"{req.model} does not accept a cache_control breakpoint",
                )
            )

        if req.reasoning is not None and Capability.REASONING not in caps.supported:
            warnings.append(
                self._unsupported(
                    caps,
                    option="reasoning",
                    detail=f"{req.model} is catalogued without REASONING, so thinking and "
                    "output_config.effort are both omitted — effort errors on the pre-4.6 line",
                )
            )

        if req.reasoning is not None:
            if req.reasoning.budget_tokens is not None:
                # ALWAYS a warning and never a rejection, whatever `on_unsupported` says. This
                # is not an unsupported option, it is an option whose only wire form
                # ({"type": "enabled", "budget_tokens": N}) is a 400 on every model we ship;
                # rejecting the request would make a portable field unusable against Anthropic.
                warnings.append(
                    CapabilityWarning(
                        option="reasoning.budget_tokens",
                        action="ignored",
                        detail="removed on the Claude 4.7 line and after; effort is the only "
                        "depth control, and the shape that carried a budget is a 400",
                    )
                )
            if (
                req.reasoning.effort in LOSSY_EFFORT and Capability.REASONING in caps.supported
                # Rounding is only observable when an effort is actually sent.
            ):
                warnings.append(
                    CapabilityWarning(
                        option="reasoning.effort",
                        action="ignored",
                        detail=f"{req.reasoning.effort!r} has no Anthropic step and is raised to "
                        f"{EFFORT[req.reasoning.effort]!r}: disabled thinking is a 400 above "
                        "effort high, so the ladder collapses upward rather than down",
                    )
                )
            if (
                req.reasoning.include_trace
                and Capability.REASONING in caps.supported
                and Capability.REASONING_TRACE not in caps.supported
            ):
                # NOT a rejection even under `reject`, and the asymmetry is deliberate: the
                # request still succeeds and still bills the full trace, so refusing it would
                # deny an answer over a display preference. What must not happen is silence —
                # a pane gated on REASONING alone renders blank forever while the cost climbs.
                warnings.append(
                    CapabilityWarning(
                        option="reasoning.include_trace",
                        action="ignored",
                        detail=f"{req.model} returns thinking blocks with an empty thinking "
                        "field and a signature, and no thinking_delta events at all, while "
                        "billing the whole trace; reasoning_tokens still reports it",
                    )
                )

        return warnings

    @staticmethod
    def _unsupported(caps: ModelCapabilities, *, option: str, detail: str) -> CapabilityWarning:
        """``reject`` raises before a byte goes out; ``warn`` strips the option and records it.

        There is no third branch, because the third branch people reach for is "drop it
        quietly" — which is how a bot configured for structured output returns prose for a
        month with nothing to read in a log.
        """
        if caps.on_unsupported == "reject":
            raise KbError(ErrorClass.VALIDATION, detail)
        return CapabilityWarning(option=option, action="ignored", detail=detail)

    async def stream(
        self,
        req: ChatRequest,
        caps: ModelCapabilities,
        credential: SecretStr,
    ) -> AsyncIterator[StreamEvent]:
        """Translate the Messages event stream.

        Match RAW event types only. The SDK fires a synthetic helper event for the same
        token, and matching both emits every token twice.

        Rate-limit headers are parsed off the 200 response too, not only off a 429 —
        ``anthropic-ratelimit-*`` plus ``retry-after``.

        Cancellation builds the terminal ``ChatResult`` inside the
        ``except asyncio.CancelledError`` branch, hands it to the finalizer, and re-raises —
        never swallows, because the span has to unwind. Anthropic is the one vendor where
        that result still carries exact input tokens, from ``message_start``.

        Written ``async def`` with ``yield`` — an async *generator* function, which is an
        ordinary callable returning an ``AsyncIterator`` and therefore satisfies the Protocol's
        plain ``def`` declaration. See ``ProviderAdapter.stream`` for why the Protocol is
        spelled the other way round.
        """
        # BEFORE THE FIRST BYTE. Under `on_unsupported="reject"` this raises, which is the
        # point: a rejection must not cost a provider call.
        warnings = self.validate(req, caps)

        started = time.perf_counter()
        parts: list[str] = []
        usage = Usage()
        stop = StopReason.ERROR
        native_stop: str | None = None
        tokens_emitted = 0
        first_token_ms: int | None = None
        request_id: str | None = None
        rate_limit: dict[str, str] = {}
        extras: dict[str, Any] = {}
        error_class: str | None = None

        client = self._client(credential)
        try:
            async with client.messages.stream(
                **self._build(req, caps),
                timeout=httpx.Timeout(
                    req.timeouts.total,
                    connect=req.timeouts.connect,
                    read=req.timeouts.first_token,
                    write=req.timeouts.connect,
                    pool=req.timeouts.connect,
                ),
            ) as vendor_stream:
                # IMMEDIATELY, AND BEFORE ITERATING. A stream that dies mid-flight takes the
                # headers with it, and they carry both the support handle and the backoff floor.
                request_id, rate_limit = _stream_metadata(vendor_stream)

                async for event in vendor_stream:
                    kind = getattr(event, "type", None)
                    if kind not in RAW_EVENT_TYPES:
                        # Every synthetic helper (`text`, `thinking`, `signature`, `input_json`,
                        # `citation`) lands here, alongside `ping`, `content_block_start` and
                        # `content_block_stop`. Dropping them is what stops each token being
                        # rendered twice; `content_block_start` in particular carries empty text,
                        # so a TTFT timed from it is fiction.
                        continue

                    if kind == "message_start":
                        # THE DIVERGENCE WORTH EXPLOITING. Anthropic is the only vendor here
                        # that reports input usage before generation, so an aborted turn still
                        # bills exactly — `Capability.EARLY_INPUT_USAGE` is the flag that
                        # records this on the model row, and `provider_partial` is what makes
                        # a cancelled turn's input cost invoiceable rather than estimated.
                        usage = self._usage(
                            getattr(event.message, "usage", None), "provider_partial"
                        )
                    elif kind == "message_delta":
                        # CUMULATIVE, NOT INCREMENTAL. Assign; never `+=`. Accumulating
                        # triple-counts output on a long stream and the error grows with the
                        # answer, so it is invisible in a short fixture.
                        usage = self._merge_usage(usage, getattr(event, "usage", None))
                        native_stop = getattr(event.delta, "stop_reason", None)
                        # An unmapped native value becomes ERROR with the vendor's own word
                        # preserved — never a fall-through to COMPLETE.
                        stop = STOP.get(native_stop or "", StopReason.ERROR)
                    elif kind == "content_block_delta":
                        delta = event.delta
                        delta_type = getattr(delta, "type", None)
                        index = getattr(event, "index", 0)
                        if delta_type == "text_delta":
                            text = getattr(delta, "text", "")
                            if not text:
                                continue
                            if first_token_ms is None:
                                first_token_ms = int((time.perf_counter() - started) * 1000)
                            tokens_emitted += 1
                            parts.append(text)
                            yield Delta(kind="text", text=text, index=index)
                        elif delta_type == "thinking_delta":
                            # Only when the trace is ACTUALLY present. Under the default
                            # `display: "omitted"` these events never arrive at all while the
                            # trace is billed in full, which is why `reasoning_tokens` is
                            # reported from usage and not counted from here.
                            thinking = getattr(delta, "thinking", "")
                            if thinking:
                                yield Delta(kind="reasoning", text=thinking, index=index)
                        elif delta_type == "input_json_delta":
                            partial = getattr(delta, "partial_json", "")
                            if partial:
                                yield Delta(kind="tool_args", text=partial, index=index)
                        # `signature_delta` is dropped: an integrity token, not content.
        except asyncio.CancelledError:
            # BUILT HERE AND NOT IN `finally`. Yielding while `GeneratorExit` unwinds raises
            # `RuntimeError: async generator ignored GeneratorExit`, ASGI swallows it, and the
            # only symptom is a missing usage row for a turn Anthropic still billed.
            yield self._result(
                parts=parts,
                stop=StopReason.CANCELLED,
                # Exact, not estimated, whenever `message_start` arrived — the whole reason
                # this vendor's cancellation path is different from the other four.
                usage=usage,
                error_class=ErrorClass.USER_CANCELLATION.value,
                native_stop=native_stop,
                request_id=request_id,
                rate_limit=rate_limit,
                extras=extras,
                warnings=warnings,
                first_token_ms=first_token_ms,
                started=started,
            )
            # ALWAYS re-raise. Swallowing leaves the span unclosed and tells the ASGI layer the
            # generator finished normally.
            raise
        except (anthropic.AnthropicError, httpx.HTTPError) as exc:
            # Classify and absorb — never re-raise past a yield. The terminal event IS the
            # failure report, and the adapter does not decide fallback: the class it records
            # is what `call_with_policy` and the eligibility matrix read.
            failure = self.classify(exc, tokens_emitted=tokens_emitted)
            error_class = failure.error_class.value
            stop = StopReason.ERROR
            request_id = failure.provider_request_id or request_id
            if failure.native_code:
                extras["error_type"] = failure.native_code
            if failure.retry_after is not None:
                extras["retry_after_seconds"] = failure.retry_after
            rate_limit = (
                _rate_limit(getattr(getattr(exc, "response", None), "headers", None)) or rate_limit
            )

        yield self._result(
            parts=parts,
            stop=stop,
            usage=usage,
            error_class=error_class,
            native_stop=native_stop,
            request_id=request_id,
            rate_limit=rate_limit,
            extras=extras,
            warnings=warnings,
            first_token_ms=first_token_ms,
            started=started,
        )

    def _result(
        self,
        *,
        parts: list[str],
        stop: StopReason,
        usage: Usage,
        error_class: str | None,
        native_stop: str | None,
        request_id: str | None,
        rate_limit: dict[str, str],
        extras: dict[str, Any],
        warnings: list[CapabilityWarning],
        first_token_ms: int | None,
        started: float,
    ) -> ChatResult:
        """The one terminal event, built in one place so no path can invent a second shape.

        ``Diagnostics`` is assembled from an allow-list here rather than scrubbed afterwards:
        nothing in it may carry the credential, the system prompt, a message, or a retrieved
        chunk, and a scrubber misses the case nobody imagined.
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
                # Preserved even when it mapped to ERROR: this is what tells us Anthropic
                # added a stop reason under its versioning policy.
                native_stop_reason=native_stop,
                rate_limit=rate_limit,
                warnings=warnings,
                extras=extras,
            ),
        )

    @staticmethod
    def _usage(raw: Any, source: str) -> Usage:
        """Verbatim assignment into disjoint buckets — never a subtraction.

        ``message_delta`` usage is cumulative: assign, never accumulate. Merge only non-None
        fields; an absent input field means unchanged, not zero.

        The merge half lives in ``_merge_usage`` because this signature carries no previous
        value to merge against — see the note there. This function normalizes ONE raw usage
        object and reports an absent field as 0.

        The assignment is verbatim because Anthropic's cache buckets are SIBLINGS of
        ``input_tokens`` rather than a subset of it; the SDK's own ``MessageDeltaUsage``
        docstring says so — "Total input tokens in a request is the summation of
        ``input_tokens``, ``cache_creation_input_tokens``, and ``cache_read_input_tokens``".
        Subtracting here is the mirror image of the OpenAI bug and under-bills a cached turn
        by the entire prefix.
        """
        if raw is None:
            # `estimated` rows are never aggregated into invoiced cost, so an unreadable usage
            # block degrades attribution and never the bill. Inventing a number would be worse:
            # it would be indistinguishable from a measurement.
            return Usage(source="estimated")
        details = getattr(raw, "output_tokens_details", None)
        return Usage(
            # ALREADY EXCLUDES the cached prefix. Nothing to subtract.
            input_tokens=_count(getattr(raw, "input_tokens", None)),
            cache_read_tokens=_count(getattr(raw, "cache_read_input_tokens", None)),
            cache_write_tokens=_count(getattr(raw, "cache_creation_input_tokens", None)),
            output_tokens=_count(getattr(raw, "output_tokens", None)),
            # Billed INSIDE `output_tokens`; reported for attribution only. Adding it to the
            # output count double-bills every thinking turn — and it is populated even when
            # `display: "omitted"` returned no trace at all.
            reasoning_tokens=_count(getattr(details, "thinking_tokens", None)),
            source=cast(UsageSource, source),
        )

    @classmethod
    def _merge_usage(cls, previous: Usage, raw: Any) -> Usage:
        """Fold a cumulative ``message_delta.usage`` onto what ``message_start`` reported.

        Split from ``_usage`` because ``_usage``'s signature — fixed by the skeleton — takes no
        previous value, and the merge needs one. Two rules, and getting either wrong is silent:

        * **The incoming numbers are cumulative totals, not increments.** Every reported field
          is ASSIGNED. ``+=`` triple-counts output on a long stream.
        * **An ABSENT field means unchanged, not zero.** ``MessageDeltaUsage`` types every
          input field ``Optional``, and several models omit them; reading an absent
          ``input_tokens`` as 0 discards the exact input count ``message_start`` already gave
          us and turns a precisely-billed turn into a free one.
        """
        if raw is None:
            return previous
        incoming = cls._usage(raw, "provider_final")
        details = getattr(raw, "output_tokens_details", None)
        return Usage(
            input_tokens=incoming.input_tokens
            if getattr(raw, "input_tokens", None) is not None
            else previous.input_tokens,
            cache_read_tokens=incoming.cache_read_tokens
            if getattr(raw, "cache_read_input_tokens", None) is not None
            else previous.cache_read_tokens,
            cache_write_tokens=incoming.cache_write_tokens
            if getattr(raw, "cache_creation_input_tokens", None) is not None
            else previous.cache_write_tokens,
            output_tokens=incoming.output_tokens
            if getattr(raw, "output_tokens", None) is not None
            else previous.output_tokens,
            reasoning_tokens=incoming.reasoning_tokens
            if details is not None
            else previous.reasoning_tokens,
            source="provider_final",
        )

    def classify(self, exc: BaseException, *, tokens_emitted: int) -> ProviderCallFailed:
        """``exc.type`` first, status second — see ``ERROR_TYPE_TO_CLASS``.

        Logs ``exc.type``, ``exc.status_code`` and ``exc.request_id``, never the exception
        object: its ``repr`` can carry request state, and request state carries the prompt.

        ``permission_error`` stays ``PROVIDER_AUTH`` here. The catalogue-entitlement
        reclassification the module header describes needs a ``models.retrieve`` call under the
        same credential, and this method is synchronous and receives only an exception — so it
        belongs to whatever handles the alert, not to a classifier that must never make a
        network call while classifying a network failure. Both readings are terminal and
        neither falls back, so the only cost is which team gets paged.
        """
        request_id = _request_id(exc)
        headers = getattr(getattr(exc, "response", None), "headers", None)
        retry_after = retry_after_seconds(headers)

        if isinstance(exc, anthropic.APIConnectionError | httpx.HTTPError):
            # `APITimeoutError` subclasses `APIConnectionError`. Nothing arrived, so there is no
            # body and no code — the absence IS the evidence, and it is always temporary.
            return ProviderCallFailed(
                ErrorClass.PROVIDER_TEMPORARY,
                "the Anthropic request did not complete: no response was received",
                tokens_emitted=tokens_emitted,
                native_code=type(exc).__name__,
                provider_request_id=request_id,
                retry_after=retry_after,
            )

        native = _error_type(exc)
        status = getattr(exc, "status_code", None)

        # THE TYPE FIRST AND THE STATUS ONLY AS A TIEBREAK. A mid-stream `event: error` is
        # raised against the ORIGINAL 200 response, so `status_code` reads 200 on exactly the
        # overloaded_error this split exists to call temporary — and a status-first reading
        # files a routine capacity blip as a permanent request defect and pages somebody.
        error_class = ERROR_TYPE_TO_CLASS.get(native or "")
        if error_class is None:
            error_class = _STATUS_TO_CLASS.get(status if isinstance(status, int) else 0, UNMAPPED)

        # THE VENDOR'S MESSAGE NEVER CROSSES INTO THE RAISED ERROR. Anthropic's 400 and 401
        # bodies echo request state, and on this service the request carries the packed prompt.
        # What crosses is `error.type`, which is a closed vocabulary, plus our own sentence.
        return ProviderCallFailed(
            error_class,
            f"Anthropic refused the call (status {status}, type {native!r})"
            if status is not None
            else f"the Anthropic call failed with type {native!r} and no status",
            tokens_emitted=tokens_emitted,
            native_code=native,
            provider_request_id=request_id,
            retry_after=retry_after,
        )


def _assert_conforms(adapter: AnthropicAdapter) -> ProviderAdapter:
    """Static conformance, checked by mypy and costing nothing at runtime.

    If a method here drifts from the Protocol — a renamed parameter, a changed return type —
    this return is the error. Without it the drift surfaces at the one call site that
    matters, in the streaming path, at request time.
    """
    return adapter
