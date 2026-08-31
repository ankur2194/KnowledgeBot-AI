"""OpenAI, through the **Responses** API. Skeleton; signatures are final.

The filename is not ``openai.py`` on purpose: inside this package, that name shadows the
``openai`` SDK for every module that imports it, and the failure is an ``ImportError`` from
a module that looks correct.

SDK ``openai==2.53.0``, endpoint ``POST /v1/responses``. Chat Completions is not used —
every new capability lands on Responses first, and OpenAI measures materially better cache
utilisation there. Chat Completions is only the shape found in stale blog posts, and its
parameter nesting differs enough to 400 if copied in.

## Divergences this file exists to absorb

**``cached_tokens`` is a SUBSET of ``input_tokens``.** OpenAI's own wording: it is "part of
the total input_tokens count". Our buckets are disjoint, so the adapter SUBTRACTS. This is
the opposite of Anthropic, where the same normalization step is a verbatim assignment, and
getting the direction wrong on a 200k-token cached document is a five-figure reporting error
in whichever direction was guessed.

**Cache writes are reported when the SDK reports them, and derived — never synthesized.**
This paragraph said *"the API returns no write amount at all — only reads,*
``cache_write_tokens`` *stays 0"* until 2026-08-27, and it was contradicting ``_usage`` a
thousand lines below in the same file: `openai==2.53.0` added
``InputTokensDetails.cache_write_tokens`` and ``_usage`` reads it. A write bills at 1.25x
uncached input, so getting this wrong under-reports the first request against each new prefix.
What has NOT changed is the rule the old wording was protecting: **a write amount is never
invented.** Absent the field the bucket is 0 and the shortfall is reconciled against the billing
export. And whether the reported amount is a *subset* of ``input_tokens`` or a *sibling* of it is
decided from the numbers rather than guessed — see ``_usage``, which falls back to the
subset-free arithmetic when ``cached + written`` cannot fit inside the reported input. Both
branches leave ``total_input_tokens`` equal to the vendor's billed input, so a wrong guess costs
attribution and never the bill.

**``max_output_tokens`` is a cap on visible output PLUS reasoning tokens, and reasoning is
generated first.** At high effort a small cap is consumed entirely by invisible thinking:
empty answer, ``status: "incomplete"``, full bill. A ``MAX_OUTPUT`` stop with zero text is
an error, not an answer.

**Truncation hides in ``status``.** ``incomplete_details`` has been observed arriving empty
while the output is genuinely truncated, so ``status: "incomplete"`` maps to ``MAX_OUTPUT``
by default and only a ``content_filter`` reason redirects it to ``REFUSAL``.

**Refusals are HTTP 200.** A ``refusal`` content part inside ``response.output``, or
``incomplete`` with ``reason: "content_filter"``. Classifying on status alone marks both
COMPLETE, and a router seeing empty text falls back and re-asks the banned question.

**Sampling is a hard 400 on the reasoning line**, not an ignore, so it looks like an outage
on the fallback dashboard rather than a configuration error. Gate on ``Capability.SAMPLING``.

**``store`` defaults to TRUE.** Left alone, the tenant's retrieved document text and the end
user's question are retained on OpenAI's servers for 30 days and readable from the org
dashboard — tenant content crossing a boundary the org's privacy switches never authorised.
Every request sends ``store=False``, which also forecloses ``previous_response_id`` and
``conversation``: PostgreSQL is the source of truth for conversation state, not OpenAI.

**``truncation`` defaults to dropping items from the START of ``input``** — exactly where
the retrieved evidence sits. The model then answers from a context the citation map no
longer describes, and citations point at text that was never sent. Always ``"disabled"``.

**``x-request-id`` is only reachable from the stream object's ``.response.headers``**, and
only if captured before iterating. On an error it is ``exc.request_id``. Capture it
immediately after ``create()`` returns, with the rate-limit headers, or it is missing on
precisely the failures support asks about.

## Stream event -> internal event

| Responses event | Internal |
|---|---|
| ``response.output_text.delta`` | ``Delta(kind="text")`` — see note below |
| ``response.reasoning_summary_text.delta`` | ``Delta(kind="reasoning")``, if trace is on |
| ``response.refusal.delta`` | ``Delta(kind="refusal")`` |
| ``response.function_call_arguments.delta`` | ``Delta(kind="tool_args")`` ¹ |
| ``response.completed`` / ``.incomplete`` / ``.failed`` | terminal ``ChatResult`` |
| SDK exception, or cancellation | terminal ``ChatResult`` + ``error_class`` |

Empty text deltas are common at the head of a stream, so TTFT starts at the first NON-empty
one; usage and the stop reason are read from the terminal event and nowhere else.

¹ Verified against ``openai==2.53.0``: ``ResponseFunctionCallArgumentsDeltaEvent.type`` is the
literal ``"response.function_call_arguments.delta"``, and every other name in this table is the
``type`` literal of a member of that release's ``ResponseStreamEvent`` union. The marker that
used to sit here asked for a re-check against the prose event reference; the SDK's generated
types come from OpenAI's own OpenAPI description and are the stronger source. The live table is
``DELTA_EVENTS`` below — this one is documentation and the code reads the other.

## Usage normalization

``input_tokens`` here **includes** the cached prefix, so::

    Usage.input_tokens      = usage.input_tokens - input_tokens_details.cached_tokens
    Usage.cache_read_tokens = input_tokens_details.cached_tokens
    Usage.cache_write_tokens = 0                      # never reported
    Usage.output_tokens     = usage.output_tokens     # reasoning is already inside this
    Usage.reasoning_tokens  = output_tokens_details.reasoning_tokens

A fixture test asserts ``input_tokens + cache_read_tokens`` equals the vendor's own
``usage.input_tokens``.

## The embedding surface — a different endpoint, not a different parameter

``POST /v1/embeddings``, present in OpenAI's own published ``openapi.yaml``. **Three of the
five vendors embed** — OpenAI, NVIDIA NIM and OpenRouter — so this adapter is one of three
implementations of ``EmbeddingAdapter`` and not the sole one; this paragraph said "the only
one" while four cells in ``capabilities.PROVIDER_TASKS`` were ``UNVERIFIED``, and two of those
four turned out to be endpoints nobody had read the documentation for.

The three are not interchangeable and none of the differences is stylistic. ``input_type`` is
required on NIM and does not exist here. ``dimensions`` is this vendor's alone. And an
over-window input errors here and on NIM (which pins ``truncate=NONE``) while OpenRouter
documents that it "will be truncated **or** rejected" with no stated default — so the
window check below is a backstop on this vendor and the primary enforcement on that one.

**Not of ``EmbedCallable``, and the distinction is the seam rather than a nicety.**
``EmbeddingAdapter.embed(req, caps, credential)`` is what this class implements: it takes an
``EmbeddingRequest`` carrying ``org_id``, ``provider_connection_id`` and ``input_type``, plus
the decrypted key as a per-call argument. ``app.ingestion.embedding.embedder.EmbedCallable`` is
``__call__(texts, *, model)`` — no credential, no org, no connection — and neither signature
satisfies the other. That is deliberate: **something binds the organization's connection and
its credential to this method and hands ingestion the narrowed callable**, which is exactly why
ingestion cannot hold a secret, log one, or serialize one into a Celery payload. A comment
claiming these are one type tells the next reader the binding does not exist.

Which connection gets bound is finding C1, and it is now answered in code by
``app/providers/embedding_selection.py``: ``resolve_embedding_connection`` picks one
``(connection_id, provider, model)`` for an organization, deterministically, and refuses when
there is none. The refusal is narrower than it was: the organizations that cannot ingest are
the ones holding **only Anthropic or only DeepSeek**, the two vendors with no embedding
endpoint at all, rather than everyone who is not on OpenAI (``docs/23`` row 158). What that
module deliberately does **not** decide is where the designation is stored and whether a second
connection may be added purely to embed; those are control-plane policy and are stated as
options there.

Four divergences that are not shared with the chat surface:

**The response may arrive out of order.** OpenAI documents an ``index`` on every data entry
for exactly this reason. ``EmbeddingResult.vectors[i]`` is contractually the embedding of
``texts[i]``, so the adapter re-sorts on ``index`` and asserts the result covers every input
exactly once. Reading the list positionally produces a fully populated, fully wrong index that
raises nothing — the same shape as the rerank scatter bug one surface over.

**``dimensions`` truncates the vector (Matryoshka) and returns 200.** Whether truncation
degrades gracefully depends on whether *that* model was trained with an MRL objective; it is a
per-model property. Either way the same model at two widths is **two ``EmbeddingSpace``s** and
therefore two collections, which is why ``dimensions`` is gated on
``Capability.EMBEDDING_DIMENSIONS`` and the width is read off the response rather than
predicted from the id.

**Over-window input is an error, and we want it to stay one.** ``text-embedding-3-large``
accepts 8192 tokens and errors above it, which is the good case and the one
``PROVIDER_TRUNCATION_POLICY = "reject"`` exists to preserve. Never send a truncation option;
a request that silently fits is a chunk indexed from its head whose tail is unsearchable
forever, reported as success.

**No cache, no reasoning, no stream.** ``cache_read_tokens``, ``cache_write_tokens``,
``reasoning_tokens`` and ``output_tokens`` are all 0 and stay 0 rather than being inferred;
``usage.prompt_tokens`` is the entire cost. There is no cached bucket on this endpoint, so the
subtraction the chat surface performs has nothing to subtract — copying ``_usage`` across is
the obvious and wrong move.

Model ids on this surface are **aliases with no dated snapshot**, which is the whole of finding
C3: nothing here can detect a re-trained model behind a stable id, and
``embedder.canary_digest`` is the only detector that exists.
"""

from __future__ import annotations

import asyncio
import hashlib
import math
import time
from collections.abc import AsyncIterator, Mapping
from typing import Any, Final, Literal

import httpx
import openai
from pydantic import SecretStr

from app.core.errors import ErrorClass, KbError
from app.providers.contract import (
    Capability,
    CapabilityWarning,
    ChatRequest,
    ChatResult,
    Delta,
    Diagnostics,
    EmbeddingAdapter,
    EmbeddingRequest,
    EmbeddingResult,
    ModelCapabilities,
    ProviderAdapter,
    StopReason,
    StreamEvent,
    Timeouts,
    Usage,
)
from app.providers.errors import ProviderCallFailed, retry_after_seconds
from app.retrieval.collection import EmbeddingSpace

__all__ = ["OpenAIAdapter"]

#: Explicit ids, verified against developers.openai.com/api/docs/models on 2026-08-04.
#: ``gpt-5.6`` is an alias that floats to ``gpt-5.6-sol`` and there are no dated snapshots
#: on this line, so the alias is never pinned: an alias reassignment moves answers, price
#: and capability flags with no diff here. The served id is read back off ``response.model``
#: and recorded as ``gen_ai.response.model``.
#:
#: Documentation only. Capabilities come from ``provider_models.capability_flags`` — this
#: tuple exists so a connection save can reject a typo, not so code can infer behaviour.
PINNED_MODELS: Final[tuple[str, ...]] = (
    "gpt-5.6-sol",
    "gpt-5.6-terra",
    "gpt-5.6-luna",
)

#: The embedding surface, pinned separately because it is a different endpoint and a different
#: product line. Rows carrying these ids are **task-exclusive**: they never also carry
#: ``TEXT``, ``TOOL_USE`` or ``REASONING``, and ``capabilities.assert_row_coherent`` rejects a
#: row that claims both (`contract.Capability`).
#:
#: Documentation and connection-save validation only, as with ``PINNED_MODELS``. Two
#: differences from the chat line that matter here:
#:
#: * There is **no dated snapshot to pin to** on any of these, so the id is an alias by
#:   construction and finding C3 applies in full. ``embedder.canary_digest`` is the detector.
#: * The **width is not implied by the id**: ``dimensions`` truncates, so the served width is
#:   read off the response and stored on the ``provider_models`` row at connection-test time.
#:   A constant here that disagreed with the served model would be discovered at the first
#:   upsert of a full ingest run, after the parse and the embed spend.
#:
#: Both ids appear in the ``CreateEmbeddingRequest.model`` enum of OpenAI's own published
#: ``openapi.yaml`` (github.com/openai/openai-openapi, read 2026-08-07), alongside the retired
#: ``text-embedding-ada-002``, and the same document states that ``dimensions`` is "only
#: supported in ``text-embedding-3`` and later models" — which is the source for
#: ``Capability.EMBEDDING_DIMENSIONS`` being on for these two rows and off for anything older.
#: The previous marker here said neither had been re-checked against a current catalog; they
#: have been, against the vendor's machine-readable description rather than a marketing page.
#:
#: <!-- UNVERIFIED: the WIDTHS are still not read off a response. 3072 native for
#: `-3-large` and 1536 for `-3-small` come from `bge-m3-embeddings` and `kb-chunking-rules`,
#: and the openapi document does not state either — `dimensions` is typed as a plain integer
#: with no per-model maximum. This is finding C3's live half and the bootstrap probe in
#: `ensure_collection` is what closes it: embed one short string and assert the length equals
#: `space.dimensions`. Until it runs, a width in this tree is a quotation, not a measurement. -->
EMBEDDING_MODELS: Final[tuple[str, ...]] = (
    "text-embedding-3-large",
    "text-embedding-3-small",
)

#: Responses stream event -> ``Delta.kind``. A table rather than an ``if`` ladder so a test can
#: read the mapping instead of restating it, and so an event this adapter does not translate is
#: a missing key rather than a branch nobody notices.
#:
#: Every name here is verified against ``openai==2.53.0``'s own ``ResponseStreamEvent`` union —
#: each is the ``type`` literal of a member class (``ResponseTextDeltaEvent``,
#: ``ResponseReasoningSummaryTextDeltaEvent``, ``ResponseRefusalDeltaEvent``,
#: ``ResponseFunctionCallArgumentsDeltaEvent``). That discharges the marker this module used to
#: carry over ``response.function_call_arguments.delta``: the SDK's generated types come from
#: OpenAI's own OpenAPI description, which is a stronger source than a prose event reference.
#:
#: ``response.reasoning_text.delta`` is deliberately ABSENT. It carries the raw chain of
#: thought on models that emit one, and the summary event is what a ``REASONING_TRACE`` pane
#: renders; translating both would emit the same thinking twice, once verbatim.
DELTA_EVENTS: Final[Mapping[str, Literal["text", "reasoning", "tool_args", "refusal"]]] = {
    "response.output_text.delta": "text",
    "response.reasoning_summary_text.delta": "reasoning",
    "response.refusal.delta": "refusal",
    "response.function_call_arguments.delta": "tool_args",
}

#: The three events that carry a terminal ``response`` object, and the only place usage and the
#: stop reason are read from. ``response.created`` and ``response.in_progress`` carry a
#: ``response`` too — with ``status: "in_progress"`` and no usage — so matching on the presence
#: of the attribute rather than on this tuple would read a stop reason off the FIRST event of
#: every stream and report ``ERROR`` on every successful turn.
TERMINAL_EVENTS: Final[tuple[str, ...]] = (
    "response.completed",
    "response.incomplete",
    "response.failed",
)

#: How one retrieved block is rendered into its own ``input_text`` part. Untrusted data: it is a
#: part of a user-role message and never reaches ``instructions``. The layout is fixed and the
#: ordering is the caller's, because prefix caching is exact string matching at this vendor and
#: a re-rendered or re-sorted evidence list is a full cache miss that costs 10x and looks normal.
CONTEXT_BLOCK_TEMPLATE: Final[str] = "[{index}] {title}\n{text}"

#: ``text.format.name`` is required by the Responses schema shape and is not a free-text field
#: (a-z, A-Z, 0-9, underscore, dash; 64 max). Constant rather than derived from anything
#: per-request: it sits inside the cacheable prefix.
STRUCTURED_OUTPUT_NAME: Final[str] = "answer"

#: ``strict`` on a FUNCTION tool, which is a different question from ``strict`` on the response
#: format. A tool schema arrives from a bot's configuration and is not ours to constrain to
#: OpenAI's subset; asking for enforcement it cannot satisfy is a 400 on a request that would
#: otherwise work. The response schema IS ours to check, and ``validate()`` does check it.
TOOL_STRICT: Final[bool] = False

#: ``detail`` is required on an ``input_image`` part. ``"auto"`` lets the vendor choose the
#: tiling; pinning ``"high"`` multiplies image token cost with nothing in the contract asking
#: for it.
IMAGE_DETAIL: Final[str] = "auto"

#: The ``safety_identifier`` is a one-way hash truncated to this many hex characters — an
#: abuse-signal handle and nothing more. Never an email, a user id or an org id in the clear:
#: all three would leave our boundary as plaintext (`kb-security-baseline`).
SAFETY_IDENTIFIER_LENGTH: Final[int] = 32

#: OpenAI strict structured-output keywords that are rejected outright. A schema carrying one
#: is a 400 at request time, after the retrieval spend, so it is refused before the first byte.
STRICT_FORBIDDEN_KEYWORDS: Final[frozenset[str]] = frozenset(
    {"allOf", "not", "if", "then", "else", "dependentRequired"}
)

#: OpenAI's published strict-mode ceilings. Checked here because the failure is a 400 whose body
#: we may not log, on a request the tenant assembled from a schema that passed review.
STRICT_MAX_DEPTH: Final[int] = 10
STRICT_MAX_PROPERTIES: Final[int] = 5000
STRICT_MAX_ENUM_VALUES: Final[int] = 1000

#: Read off the live response before iterating, and tenant-safe. Parsed into
#: ``Diagnostics.rate_limit``; the SDK reads none of them for us.
RATE_LIMIT_HEADERS: Final[tuple[str, ...]] = (
    "x-ratelimit-remaining-requests",
    "x-ratelimit-reset-requests",
    "x-ratelimit-remaining-tokens",
    "x-ratelimit-reset-tokens",
    "retry-after",
)

#: SDK exception + body ``code`` -> class. The 429 row is the one that costs money: both
#: values arrive as ``RateLimitError``, so a bare ``except RateLimitError`` treats an
#: exhausted account as a transient limit — full backoff ladder, then a silent fallback onto
#: a second account, and no page.
#:
#: | Signal | class |
#: |---|---|
#: | ``APIConnectionError``, ``APITimeoutError``, no first token | ``provider_temporary`` |
#: | 500 ``server_error``, 503 overloaded | ``provider_temporary`` |
#: | 429 ``rate_limit_exceeded`` | ``provider_rate_limit`` |
#: | 429 ``insufficient_quota`` | ``provider_billing`` |
#: | 401, 403 | ``provider_auth`` |
#: | 404 ``model_not_found`` | ``provider_permanent_request`` — never falls back (ADR-014) |
#: | 400 ``context_length_exceeded`` / ``unsupported_value`` | ``provider_permanent_request`` |
#: | ``status="failed"``, or any unmapped code | ``provider_permanent_request`` + alert |
BODY_CODE_TO_CLASS: Final[dict[str, ErrorClass]] = {
    "rate_limit_exceeded": ErrorClass.PROVIDER_RATE_LIMIT,
    "insufficient_quota": ErrorClass.PROVIDER_BILLING,
    "server_error": ErrorClass.PROVIDER_TEMPORARY,
    "model_not_found": ErrorClass.PROVIDER_PERMANENT_REQUEST,
    "context_length_exceeded": ErrorClass.PROVIDER_PERMANENT_REQUEST,
    "unsupported_value": ErrorClass.PROVIDER_PERMANENT_REQUEST,
}

#: The tiebreak, consulted **only** when the body carried no code we recognise. It is
#: deliberately not the primary axis: the 429 row above splits on a vendor code that the
#: status cannot distinguish, and every entry here would get that split wrong.
#:
#: An unmapped status lands on ``PROVIDER_PERMANENT_REQUEST`` via the caller's default, which
#: is ``errors.UNMAPPED`` — unknown is permanent, never temporary, because a temporary default
#: retries a request that will never succeed and hides the fact that a vendor added a code.
_STATUS_TO_CLASS: Final[dict[int, ErrorClass]] = {
    401: ErrorClass.PROVIDER_AUTH,
    403: ErrorClass.PROVIDER_AUTH,
    429: ErrorClass.PROVIDER_RATE_LIMIT,
    500: ErrorClass.PROVIDER_TEMPORARY,
    502: ErrorClass.PROVIDER_TEMPORARY,
    503: ErrorClass.PROVIDER_TEMPORARY,
    504: ErrorClass.PROVIDER_TEMPORARY,
}

#: The vendor's own ceiling on one ``POST /v1/embeddings`` array. Ingestion never approaches it
#: — ``embedder.MAX_BATCH_TEXTS`` is 64 — so this is a backstop against a caller that is not
#: ingestion, and it exists so the refusal names the limit rather than arriving as a 400 whose
#: body we may not log.
#:
#: <!-- UNVERIFIED: 2048 is OpenAI's documented array limit as of the 2026-08-07 reading of
#: their published openapi.yaml; it is not re-checked per release, and the vendor's 400 remains
#: the authority. -->
MAX_EMBEDDING_INPUTS: Final[int] = 2048


def _body_code(exc: BaseException) -> str | None:
    """The vendor's ``error.code`` from an SDK exception, or None.

    Defensive at every hop on purpose. The SDK exposes ``body`` as ``object``, a proxy error
    can return HTML with a JSON content type, and a gateway 502 has no body at all — none of
    which is a reason to fail while classifying a failure. A ``None`` here means "no code",
    which the caller handles by falling through to the status.
    """
    body = getattr(exc, "body", None)
    if isinstance(body, dict):
        error = body.get("error")
        if isinstance(error, dict):
            code = error.get("code")
            if isinstance(code, str) and code:
                return code
    code = getattr(exc, "code", None)
    return code if isinstance(code, str) and code else None


def _estimated_usage(deltas_emitted: int) -> Usage:
    """What a turn that never reached its terminal event can honestly claim.

    Usage arrives only in the terminal event on this vendor — ``EARLY_INPUT_USAGE`` is OFF for
    exactly this reason — so a cancelled or mid-stream-failed turn has no input count at all
    and inventing one would be a fabricated bill. What it does have is the number of non-empty
    deltas it forwarded, and on this API a text delta is approximately one token, so that is
    the output estimate.

    ``source="estimated"``, which is what keeps it out of invoiced cost. The alternative is a
    ``Usage()`` of zeros, and a free call and a cancelled call then look identical — which is
    how billing silently under-counts every abandoned turn.
    """
    return Usage(output_tokens=deltas_emitted, source="estimated")


def _strict_schema_violations(schema: dict[str, Any]) -> list[str]:
    """OpenAI strict-mode subset check, run before the first byte.

    The rules are narrow and none of them is guessable from a schema that "looks fine": an
    object at the root (not ``anyOf``), every property listed in ``required``,
    ``additionalProperties: false`` on every object, no ``allOf``/``not``/``if``/``then``/
    ``else``/``dependentRequired``, and the published depth, property and enum ceilings.

    **A Pydantic model is not a schema that passes this.** An ``Optional[str]`` field emits
    neither a ``required`` entry nor a null union unless configured to, so the generated
    document is rejected at request time — after retrieval has been paid for, with a message
    body we are not allowed to log. Checking here converts that into a validation error naming
    the property.

    Returns every violation rather than the first: a schema with four problems should be fixed
    once, not four times.
    """
    violations: list[str] = []
    properties_seen = 0

    def walk(node: Any, path: str, depth: int) -> None:
        nonlocal properties_seen
        if not isinstance(node, dict):
            return
        if depth > STRICT_MAX_DEPTH:
            violations.append(f"{path} nests deeper than the {STRICT_MAX_DEPTH}-level ceiling")
            return

        forbidden = sorted(STRICT_FORBIDDEN_KEYWORDS & node.keys())
        if forbidden:
            violations.append(f"{path} uses {', '.join(forbidden)}, which strict mode rejects")

        enum = node.get("enum")
        if isinstance(enum, list) and len(enum) > STRICT_MAX_ENUM_VALUES:
            violations.append(
                f"{path} has {len(enum)} enum values, above the {STRICT_MAX_ENUM_VALUES} ceiling"
            )

        if node.get("type") == "object":
            if node.get("additionalProperties") is not False:
                violations.append(f"{path} must set additionalProperties to false")
            properties = node.get("properties")
            if isinstance(properties, dict):
                properties_seen += len(properties)
                required = node.get("required")
                missing = sorted(
                    properties.keys() - set(required if isinstance(required, list) else [])
                )
                if missing:
                    violations.append(
                        f"{path} omits {', '.join(missing)} from required; strict mode requires "
                        "EVERY property to be listed, and an optional field is expressed as a "
                        "null union instead"
                    )
                for name, child in properties.items():
                    walk(child, f"{path}.{name}", depth + 1)

        for keyword in ("items", "prefixItems", "contains"):
            child = node.get(keyword)
            if isinstance(child, dict):
                walk(child, f"{path}[{keyword}]", depth + 1)
            elif isinstance(child, list):
                for position, member in enumerate(child):
                    walk(member, f"{path}[{keyword}][{position}]", depth + 1)

        for keyword in ("anyOf", "oneOf"):
            members = node.get(keyword)
            if isinstance(members, list):
                for position, member in enumerate(members):
                    walk(member, f"{path}.{keyword}[{position}]", depth)

        for container in ("$defs", "definitions"):
            defs = node.get(container)
            if isinstance(defs, dict):
                for name, child in defs.items():
                    walk(child, f"{container}.{name}", depth)

    if schema.get("type") != "object":
        violations.append("the root must be an object; anyOf at the root is rejected")
    walk(schema, "root", 1)

    if properties_seen > STRICT_MAX_PROPERTIES:
        violations.append(
            f"{properties_seen} properties, above the {STRICT_MAX_PROPERTIES} ceiling"
        )
    return violations


class OpenAIAdapter:
    """Satisfies ``ProviderAdapter``.

    Capability flags this adapter expects on a 5.6-line row, and why each is a decision and
    not a description: ``TEXT``, ``IMAGE_INPUT``, ``TOOL_USE``, ``STRUCTURED_OUTPUT`` (native
    strict ``json_schema``, so ``JSON_MODE`` is redundant and stays off), ``REASONING``,
    ``REASONING_TRACE`` (summaries are returned, unlike Anthropic's default),
    ``PROMPT_CACHING``, ``STREAM_USAGE``. ``SAMPLING`` is OFF for the reasoning line — the
    models 400 on ``temperature`` rather than ignoring it. ``EARLY_INPUT_USAGE`` is OFF:
    usage arrives only in the terminal event, so a cancelled turn is always estimated.
    """

    name = "openai"
    #: A first token has to be plausible inside the remaining budget or the attempt bills
    #: for a completion nobody will read.
    min_useful_seconds = 5.0

    def __init__(self, base_url: str | None = None) -> None:
        """No credential here. The key is a ``stream()`` argument (`contract`).

        Holds nothing per-org, so one instance serves every tenant.
        """
        self._base_url = base_url

    def _client(self, credential: SecretStr) -> Any:
        """Build ``AsyncOpenAI`` for one call.

        ``max_retries=0``, always. The SDK retries twice by default, invisibly, inside one
        ``await``, so the span records one call; the adapter policy loop retries twice; the
        browser used to retry three times. Left alone that is 27 billed calls from one click.

        Timeout is an explicit ``httpx.Timeout(connect=..., read=first_token, ...)``, never
        the SDK's flat 600 s default — 13x our provider budget. ``read`` is between chunks,
        not total, so a model emitting one token every fifteen seconds never trips it; the
        caller's absolute deadline is what enforces the total.

        THE TIMEOUT HERE IS A FLOOR, NOT THE POLICY. The per-call values live on
        ``req.timeouts`` and this signature takes no request — it is shared by both surfaces —
        so the client is built with the contract's defaults and each call passes its own
        ``timeout=`` override. That is the SDK's documented per-request escape hatch and it
        keeps one client shape for chat and embedding; what it must never become is a call
        with no override at all, which would silently inherit the defaults below rather than
        the tenant's configured budget.
        """
        defaults = Timeouts()
        return openai.AsyncOpenAI(
            api_key=credential.get_secret_value(),
            base_url=self._base_url,
            # ALWAYS 0. The SDK retries twice by default, invisibly, inside one `await`.
            max_retries=0,
            timeout=httpx.Timeout(
                defaults.total,
                connect=defaults.connect,
                read=defaults.first_token,
                write=defaults.connect,
                pool=defaults.connect,
            ),
        )

    def _translate_in(self, req: ChatRequest, caps: ModelCapabilities) -> dict[str, Any]:
        """Build the Responses body.

        Mandatory on every request, each for a reason recorded in the module docstring:
        ``store=False``, ``truncation="disabled"``, ``stream=True``, a ``prompt_cache_key``
        keyed on the bot (not the conversation, too sparse to ever hit; not the org, too hot
        for one key), and a one-way hashed ``safety_identifier`` — never an email, a user id
        or an org id, all of which would leave our boundary as plaintext.

        Never present on the chat path: ``previous_response_id``, ``conversation``,
        ``background``. The last one detaches generation from the connection, so cancelling
        stops nothing and the spend continues after the tab closes.

        Structured output nests differently from Chat Completions — no ``json_schema``
        wrapper object and ``name`` is required. The strict subset is narrow: every property
        in ``required``, ``additionalProperties: false`` on every object, an object at the
        root, and no ``allOf``/``not``/``if``. A Pydantic model does not emit that by
        default, so the schema is validated in ``validate()``, not assumed.
        """
        body: dict[str, Any] = {
            "model": req.model,
            # THE BOT INSTRUCTION ONLY. Retrieved text is a user-role data part below; source
            # text that can reach the instruction slot is prompt injection with our own
            # retrieval pipeline as the delivery mechanism.
            "instructions": req.system,
            "input": self._input_items(req),
            # INCLUDES reasoning tokens, which are generated first. See the module docstring:
            # at high effort a small cap is consumed entirely by invisible thinking.
            "max_output_tokens": req.max_output_tokens,
            "stream": True,
            # The API default is True, which retains the tenant's retrieved document text and
            # the end user's question on OpenAI's servers for 30 days.
            "store": False,
            # The default drops items from the START of `input`, which is exactly where the
            # retrieved evidence sits — citations would then point at text never sent.
            "truncation": "disabled",
            # Keyed on the bot, because the bot's instruction plus context layout is what is
            # actually identical across requests. Not the conversation (too sparse to ever hit)
            # and not the org (one key would take far more than the per-key request rate).
            "prompt_cache_key": f"kb:{req.bot_id}",
            "safety_identifier": hashlib.sha256(req.org_id.encode()).hexdigest()[
                :SAFETY_IDENTIFIER_LENGTH
            ],
        }

        if req.reasoning is not None and Capability.REASONING in caps.supported:
            # The seven contract levels are OpenAI's seven levels, so this is an identity map
            # and not a rounding. Per-model SUBSETS are real and are not knowable from here —
            # `capability_flags` carries one REASONING boolean, not a ladder — so a model that
            # rejects a level answers 400 `unsupported_value`, which classifies as
            # PROVIDER_PERMANENT_REQUEST and names the level. That is the loud direction.
            reasoning: dict[str, Any] = {"effort": req.reasoning.effort}
            if req.reasoning.include_trace and Capability.REASONING_TRACE in caps.supported:
                # Only when a UI will actually render it: the trace is billed either way, and
                # asking for a summary the pane never shows is spend with no reader.
                reasoning["summary"] = "auto"
            body["reasoning"] = reasoning
            # NO `budget_tokens` ANYWHERE. OpenAI has no equivalent control; `validate()`
            # reports it rather than this method dropping it.

        if req.temperature is not None and Capability.SAMPLING in caps.supported:
            body["temperature"] = req.temperature

        if req.response_schema is not None and Capability.STRUCTURED_OUTPUT in caps.supported:
            # Responses nests differently from Chat Completions: no `json_schema` WRAPPER
            # object, and `name` is required. Copying the Chat Completions shape here is a 400.
            body["text"] = {
                "format": {
                    "type": "json_schema",
                    "name": STRUCTURED_OUTPUT_NAME,
                    "strict": True,
                    "schema": req.response_schema,
                }
            }

        if req.tools and Capability.TOOL_USE in caps.supported:
            # Function tools are FLAT on Responses — no nested `function` object, which is the
            # Chat Completions shape and a 400 here.
            body["tools"] = [
                {
                    "type": "function",
                    "name": tool.name,
                    "description": tool.description,
                    "parameters": tool.parameters,
                    "strict": TOOL_STRICT,
                }
                for tool in req.tools
            ]

        return body

    @staticmethod
    def _input_items(req: ChatRequest) -> list[dict[str, Any]]:
        """Evidence first, conversation second, images last — and the order is load-bearing.

        Prefix caching at this vendor is exact string matching over the head of the request, so
        the stable material (the bot's blocks, in the order the retrieval stage assigned) goes
        first and the per-turn material goes last. A re-sorted evidence list is a full cache
        miss that costs roughly ten times as much and reports as a perfectly normal request.

        Assistant turns carry a plain string rather than a content list on purpose: the list
        form of an input message accepts ``input_*`` parts only, and ``output_text`` belongs to
        a different item shape that also requires an ``id`` and a ``status`` we do not have for
        a turn we replayed out of PostgreSQL.
        """
        items: list[dict[str, Any]] = []

        if req.context_blocks:
            items.append(
                {
                    "role": "user",
                    "content": [
                        {
                            "type": "input_text",
                            "text": CONTEXT_BLOCK_TEMPLATE.format(
                                index=block.index, title=block.title, text=block.text
                            ),
                        }
                        for block in req.context_blocks
                    ],
                }
            )

        for message in req.messages:
            if message.role == "assistant":
                items.append({"role": "assistant", "content": message.content})
            else:
                items.append(
                    {
                        "role": "user",
                        "content": [{"type": "input_text", "text": message.content}],
                    }
                )

        if req.images:
            # Base64 data URLs only. `ImageInput` has deliberately no URL form: a URL the
            # vendor fetches is a tenant-supplied fetch we cannot guard.
            parts = [
                {
                    "type": "input_image",
                    "detail": IMAGE_DETAIL,
                    "image_url": f"data:{image.media_type};base64,{image.data_b64}",
                }
                for image in req.images
            ]
            for item in reversed(items):
                if item["role"] == "user" and isinstance(item["content"], list):
                    item["content"] = [*item["content"], *parts]
                    break
            else:
                items.append({"role": "user", "content": parts})

        return items

    def validate(self, req: ChatRequest, caps: ModelCapabilities) -> list[CapabilityWarning]:
        """Reject or warn per ``caps.on_unsupported``; never drop an option silently.

        Every optional field on ``ChatRequest`` appears below, including the two that read as
        infrastructure rather than as options — ``stream`` and ``cache_hint`` — because a field
        the caller set and this adapter ignored is a silent drop whatever the field is named.

        ``max_output_tokens`` and the strict schema are RAISES rather than warnings, and the
        difference is that neither has a "send it without the option" form: there is no request
        to make once the cap exceeds the model's ceiling, and a schema outside the strict subset
        is a 400 whose body we may not log, arriving after the whole retrieval spend.
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
                        "token and a buffered turn reports one that is indistinguishable from "
                        "a stall. The answer is identical; only the delivery differs"
                    ),
                )
            )

        if req.temperature is not None and Capability.SAMPLING not in caps.supported:
            warnings.append(
                self._unsupported(
                    caps,
                    option="temperature",
                    detail=(
                        f"{req.model!r} does not declare Capability.SAMPLING, so "
                        f"temperature={req.temperature} cannot be sent. The 5.x reasoning line "
                        "REJECTS sampling parameters with 400 unsupported_value rather than "
                        "ignoring them, so sending it anyway is a hard failure at request time "
                        "that reads as an outage on the fallback dashboard"
                    ),
                )
            )

        if req.response_schema is not None:
            if Capability.STRUCTURED_OUTPUT not in caps.supported:
                warnings.append(
                    self._unsupported(
                        caps,
                        option="response_schema",
                        detail=(
                            f"{req.model!r} does not declare Capability.STRUCTURED_OUTPUT. "
                            "JSON_MODE is not a substitute — it produces valid JSON with no "
                            "schema enforcement — and a bot configured for structured output "
                            "that quietly returns prose is not noticed for a month"
                        ),
                    )
                )
            else:
                violations = _strict_schema_violations(req.response_schema)
                if violations:
                    # ALWAYS A RAISE, on both `on_unsupported` settings. There is no
                    # "send it without the schema" form of this: the caller asked for enforced
                    # structure, and the alternative to refusing here is a 400 after retrieval
                    # has been paid for, carrying a message we are not allowed to log.
                    raise KbError(
                        ErrorClass.VALIDATION,
                        f"response_schema is outside OpenAI's strict subset for {req.model!r}: "
                        + "; ".join(violations),
                    )

        if req.reasoning is not None:
            if Capability.REASONING not in caps.supported:
                warnings.append(
                    self._unsupported(
                        caps,
                        option="reasoning",
                        detail=(
                            f"{req.model!r} does not declare Capability.REASONING, so "
                            f"effort={req.reasoning.effort!r} cannot be requested"
                        ),
                    )
                )
            else:
                if req.reasoning.budget_tokens is not None:
                    warnings.append(
                        self._unsupported(
                            caps,
                            option="reasoning.budget_tokens",
                            detail=(
                                "OpenAI has no reasoning budget control — effort is the only "
                                f"dial — so budget_tokens={req.reasoning.budget_tokens} cannot "
                                "be expressed. It is reported rather than dropped because NIM "
                                "ENFORCES it, so a caller reading it as portable would size a "
                                "budget here that silently does nothing"
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
                                "no summary is requested and the reasoning pane stays empty "
                                "while the trace is still billed inside output_tokens"
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
                        f"{req.model!r} does not declare Capability.PROMPT_CACHING, so a "
                        "cacheable prefix cannot be claimed. prompt_cache_key is still sent — "
                        "it is a routing key and is inert without caching — but no cost "
                        "reduction should be expected and none will be reported"
                    ),
                )
            )

        if req.max_output_tokens > caps.max_output_tokens:
            # A RAISE on both settings, for the same reason as the schema: there is no request
            # to make with the option removed. `max_output_tokens` has no default we could fall
            # back to that would not silently change the answer length the tenant configured.
            raise KbError(
                ErrorClass.VALIDATION,
                f"max_output_tokens={req.max_output_tokens} exceeds the ceiling "
                f"{caps.max_output_tokens} recorded for {req.model!r}. The cap also has to "
                "cover reasoning tokens, which are generated first, so a request sized against "
                "the wrong ceiling returns an empty answer and a full bill",
            )

        return warnings

    async def stream(
        self,
        req: ChatRequest,
        caps: ModelCapabilities,
        credential: SecretStr,
    ) -> AsyncIterator[StreamEvent]:
        """Translate the Responses event stream.

        Implemented as an ``async def`` generator — see the Protocol's note on why this is
        declared ``def``.

        Order of operations that is not negotiable: capture ``x-request-id`` and the
        rate-limit headers off ``stream.response.headers`` immediately after ``create()``
        returns and BEFORE the first iteration, because a stream that dies mid-flight takes
        them with it.

        Cancellation yields the terminal ``ChatResult`` from the ``except
        asyncio.CancelledError`` branch and then re-raises. Never from ``finally``: an async
        generator that yields while ``GeneratorExit`` unwinds raises ``RuntimeError``, the
        ASGI layer swallows it, and the usage row disappears for a turn OpenAI still billed.

        A vendor failure ends the stream with a terminal ``ChatResult`` carrying an
        ``error_class`` rather than by raising, because ``ChatResult`` is the single channel
        the contract gives this generator and a raise leaves the turn with no usage row at
        all. The classification is ``classify()``'s and the fallback decision is the router's;
        this method never picks a second vendor.
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
        request_id: str | None = None
        rate_limit: dict[str, str] = {}
        extras: dict[str, Any] = {}
        native_stop_reason: str | None = None

        try:
            client = self._client(credential)
            events = await client.responses.create(
                **self._translate_in(req, caps),
                # The per-call budget, never the client's constructed default. `read` is
                # BETWEEN CHUNKS, so a model emitting one token every fifteen seconds never
                # trips it; the caller's absolute deadline is what bounds the total.
                timeout=httpx.Timeout(
                    req.timeouts.total,
                    connect=req.timeouts.connect,
                    read=req.timeouts.first_token,
                    write=req.timeouts.connect,
                    pool=req.timeouts.connect,
                ),
            )

            # IMMEDIATELY, AND BEFORE THE FIRST ITERATION. `.response` is the live httpx
            # response: `x-request-id` and the rate-limit headers are readable here and are
            # gone with the connection if the stream dies mid-flight — which is precisely the
            # failure vendor support asks about.
            headers = getattr(getattr(events, "response", None), "headers", None) or {}
            request_id = headers.get("x-request-id")
            rate_limit = {
                header: headers[header] for header in RATE_LIMIT_HEADERS if header in headers
            }

            async for event in events:
                event_type = getattr(event, "type", None)
                kind = DELTA_EVENTS.get(event_type or "")
                if kind is not None:
                    text = getattr(event, "delta", "") or ""
                    if not text:
                        # Empty deltas are common at the head of a stream. Starting TTFT on
                        # one reports a first token that carried nothing.
                        continue
                    if first_token_ms is None:
                        first_token_ms = int((time.perf_counter() - started) * 1000)
                    deltas_emitted += 1
                    if kind == "text":
                        parts.append(text)
                    yield Delta(kind=kind, text=text, index=getattr(event, "output_index", 0) or 0)
                elif event_type in TERMINAL_EVENTS:
                    terminal = event.response
                    usage = self._usage(getattr(terminal, "usage", None))
                    stop = self._stop(terminal)
                    native_stop_reason = getattr(terminal, "status", None)
                    served = getattr(terminal, "model", None)
                    if served is not None:
                        # The SERVED id. `gpt-5.6` floats to `gpt-5.6-sol` and there are no
                        # dated snapshots on that line, so what answered is not necessarily
                        # what was asked for.
                        extras["response_model"] = served
                    if stop is StopReason.ERROR:
                        # `status: "failed"`, or a status this adapter does not map. Unknown is
                        # PERMANENT, never temporary: a temporary default retries a request
                        # that will never succeed and hides that the vendor added a value.
                        error_class = ErrorClass.PROVIDER_PERMANENT_REQUEST.value
        except asyncio.CancelledError:
            # HERE, AND THEN RE-RAISE. Never from `finally`: an async generator that yields
            # while GeneratorExit unwinds raises RuntimeError, the ASGI layer swallows it, and
            # the only symptom is a missing usage row for a turn OpenAI billed in full.
            yield self._terminal(
                parts=parts,
                stop=StopReason.CANCELLED,
                usage=_estimated_usage(deltas_emitted),
                error_class=ErrorClass.USER_CANCELLATION.value,
                request_id=request_id,
                first_token_ms=first_token_ms,
                started=started,
                native_stop_reason=native_stop_reason,
                rate_limit=rate_limit,
                warnings=warnings,
                extras=extras,
            )
            raise
        except Exception as exc:
            # One terminal event on EVERY path, so a failure is reported through `error_class`
            # rather than by raising out of the generator and losing the turn's accounting.
            # A KbError already carries our own classification — re-classifying it as a vendor
            # fault would file a validation defect of ours as OpenAI's.
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
                rate_limit=rate_limit,
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
            rate_limit=rate_limit,
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
        rate_limit: dict[str, str],
        warnings: list[CapabilityWarning],
        extras: dict[str, Any],
    ) -> ChatResult:
        """Build the one terminal event. Every exit from ``stream()`` comes through here.

        One construction site, so the three paths cannot drift on what a ``ChatResult``
        carries — the drift that shows up as a cancelled turn with no rate-limit headers or an
        errored turn with no request id, both discovered while reading an incident.
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
                # THE VENDOR'S OWN WORD, kept even when it maps to ERROR. It is what tells us
                # OpenAI added a status.
                native_stop_reason=native_stop_reason,
                rate_limit=rate_limit,
                warnings=warnings,
                extras=extras,
            ),
        )

    @staticmethod
    def _usage(raw: Any) -> Usage:
        """Normalize usage. SUBTRACT the cached amount — it is a subset here.

        ``usage.input_tokens_details.cached_tokens`` is, in OpenAI's own wording, "part of the
        total input_tokens count". Our buckets are disjoint and billing reads
        ``total_input_tokens``, so the subtraction is what keeps the sum equal to the vendor's
        own billed input. Anthropic's buckets are siblings and the same step there is a
        verbatim assignment; getting the direction wrong on a 200k-token cached document is a
        five-figure reporting error in whichever direction was guessed.

        ``cache_write_tokens`` IS READ, AND THE READING IS DERIVED RATHER THAN ASSUMED.
        `openai==2.53.0` added `InputTokensDetails.cache_write_tokens`, which this module's
        docstring and `openai-api/SKILL.md` both still say is never reported — both are stale.
        Whether it is a subset of `input_tokens` (as `cached_tokens` is) or a sibling of it
        decides whether populating the bucket must also subtract, and reading it under the
        wrong assumption moves the billed total, which is the exact error the subtraction above
        exists to prevent. So this method does not hold an opinion: the SDK declares both
        fields inside a container it documents as "a detailed breakdown of the input tokens",
        which is the subset reading, and the arithmetic below TESTS that reading against the
        numbers in hand and falls back when it fails. `total_input_tokens` equals the vendor's
        billed input on both branches, so the worst case is the under-attribution this module
        already documented, never a wrong bill.

        <!-- UNVERIFIED: that `cache_write_tokens` is a subset rather than a sibling is read
        off the SDK's own field documentation, not off current official vendor documentation,
        which this host cannot reach. The fallback below is what makes being wrong survivable
        rather than silent. -->
        """
        if raw is None:
            # `estimated` rows are never aggregated into invoiced cost, so an absent usage
            # block degrades ATTRIBUTION and never the bill. Inventing a number here would be
            # indistinguishable from a measurement.
            return Usage(source="estimated")

        input_tokens = getattr(raw, "input_tokens", None)
        output_tokens = getattr(raw, "output_tokens", None)
        if not isinstance(input_tokens, int) or not isinstance(output_tokens, int):
            return Usage(source="estimated")

        details = getattr(raw, "input_tokens_details", None)
        cached = getattr(details, "cached_tokens", 0) or 0
        written = getattr(details, "cache_write_tokens", 0) or 0
        reasoning = getattr(getattr(raw, "output_tokens_details", None), "reasoning_tokens", 0) or 0

        # SUBSET OR SIBLING, DECIDED FROM THE NUMBERS. Under the subset reading both cached and
        # written are components of `input_tokens`, so their sum cannot exceed it. When that
        # holds, subtract both and the three buckets partition the billed input exactly. When it
        # does not, the subset reading is false for this response and taking it would report a
        # `total_input_tokens` SMALLER than the vendor billed — so drop back to the reading that
        # has always been verified (cached ⊆ input, writes left folded into uncached input),
        # which under-attributes and never under-bills.
        if cached + written <= input_tokens:
            uncached, write_bucket = input_tokens - cached - written, written
        else:
            uncached, write_bucket = max(input_tokens - cached, 0), 0

        return Usage(
            # `max` above is a floor and not a correction — a vendor reporting more cached than
            # input is a bug, and a negative bucket would make `total_input_tokens` smaller than
            # the billed input rather than louder.
            input_tokens=uncached,
            cache_read_tokens=cached,
            cache_write_tokens=write_bucket,
            output_tokens=output_tokens,
            # Billed INSIDE output_tokens. Adding it would double-bill every reasoning turn.
            reasoning_tokens=reasoning,
            source="provider_final",
        )

    @staticmethod
    def _stop(response: Any) -> StopReason:
        """Map the terminal response onto a stop reason.

        Checked in this order, because each earlier check would otherwise be swallowed by a
        later one: a ``refusal`` content part anywhere in ``output`` -> ``REFUSAL``;
        ``status == "incomplete"`` with ``reason == "content_filter"`` -> ``REFUSAL``; any
        other ``incomplete``, INCLUDING an empty ``incomplete_details`` -> ``MAX_OUTPUT``;
        ``status == "failed"`` -> ``ERROR``; only ``completed`` reaches ``COMPLETE``.

        Two notes on what is NOT here.

        ``CONTEXT_EXCEEDED`` is unreachable on this vendor: an over-window request is refused
        at request time with 400 ``context_length_exceeded``, which ``classify()`` files as
        ``PROVIDER_PERMANENT_REQUEST``. It never arrives as a status.

        ``TOOL_USE`` is reached from ``completed`` and is the one addition to the enumeration
        above. Responses has no ``finish_reason``; a model asking for a tool returns
        ``completed`` with a ``function_call`` item in ``output`` and no text. Reporting that
        as ``COMPLETE`` hands the router an empty answer with nothing to distinguish it from a
        model that had nothing to say — and this adapter already emits ``tool_args`` deltas, so
        a stop reason that cannot say so is incoherent with its own stream.
        """
        status = getattr(response, "status", None)
        output = getattr(response, "output", None) or []

        # FIRST, because a refusal arrives as HTTP 200 with a `completed` status and would
        # otherwise be filed as a successful empty answer — at which point a router seeing no
        # text falls back and re-asks the banned question on another vendor, billing twice.
        for item in output:
            for part in getattr(item, "content", None) or []:
                if getattr(part, "type", None) == "refusal":
                    return StopReason.REFUSAL

        if status == "incomplete":
            reason = getattr(getattr(response, "incomplete_details", None), "reason", None)
            if reason == "content_filter":
                return StopReason.REFUSAL
            # EVERY OTHER `incomplete`, INCLUDING AN EMPTY `incomplete_details`. The details
            # object has been observed arriving empty while the output was genuinely truncated,
            # so truncation is never decided from the presence of a reason. A truncated answer
            # reported as COMPLETE is the worst failure this layer can cause: the user reads a
            # confident half-sentence and nothing errors anywhere.
            return StopReason.MAX_OUTPUT

        if status == "completed":
            if any(getattr(item, "type", None) == "function_call" for item in output):
                return StopReason.TOOL_USE
            return StopReason.COMPLETE

        # `failed`, `cancelled`, `in_progress`, `queued`, a value OpenAI adds next quarter, or
        # nothing at all. ERROR, with the vendor's own word preserved on
        # `Diagnostics.native_stop_reason` by the caller — never a fall-through to COMPLETE.
        return StopReason.ERROR

    def classify(self, exc: BaseException, *, tokens_emitted: int) -> ProviderCallFailed:
        """SDK exception plus the body's ``code`` — never the status alone.

        See ``BODY_CODE_TO_CLASS``. The 429 split is decided on ``insufficient_quota``, a
        vendor code, and never on the message.

        Shared with the embedding surface deliberately: it is the same account, the same key
        and the same error envelope, so the same codes mean the same things. ``tokens_emitted``
        is always 0 from ``embed`` — there is no stream and therefore no point past which a
        retry re-bills a partial completion, which is why an embedding call may retry the SAME
        connection under ``RETRYABLE`` while a mid-stream chat failure may not.

        What is NOT shared is the fallback verdict. ``errors.fallback_eligible`` takes
        ``ProviderSurface`` as a second axis and returns False for every class on the embedding
        surface: a different provider is a different vector space, and falling back
        mid-ingestion writes points whose cosine distance to the rest of the collection is
        meaningless — with no width mismatch to make Qdrant reject them.

        THE VENDOR'S MESSAGE NEVER CROSSES INTO THE RAISED ERROR. 401 and 422 bodies routinely
        echo the request, and on this service the request carries the packed prompt or the
        tenant's chunk text. What crosses is the vendor's ``code``, which is a closed
        vocabulary, plus our own sentence.
        """
        code = _body_code(exc)
        request_id = getattr(exc, "request_id", None)
        headers = getattr(getattr(exc, "response", None), "headers", None)
        retry_after = retry_after_seconds(headers) if headers is not None else None

        if isinstance(exc, openai.APITimeoutError | openai.APIConnectionError):
            # Nothing arrived. There is no body and therefore no code — the absence IS the
            # evidence, and it is always temporary.
            return ProviderCallFailed(
                ErrorClass.PROVIDER_TEMPORARY,
                "the OpenAI request did not complete: no response was received",
                tokens_emitted=tokens_emitted,
                native_code=type(exc).__name__,
                provider_request_id=request_id,
            )

        status = getattr(exc, "status_code", None)

        # THE CODE FIRST AND THE STATUS ONLY AS A TIEBREAK. Both values of the 429 arrive as
        # `RateLimitError`, so a status-first reading treats an exhausted account as a
        # transient limit: full backoff ladder, silent fallback onto a second account, no page.
        error_class = BODY_CODE_TO_CLASS.get(code or "")
        if error_class is None:
            error_class = _STATUS_TO_CLASS.get(status or 0, ErrorClass.PROVIDER_PERMANENT_REQUEST)

        return ProviderCallFailed(
            error_class,
            f"OpenAI refused the call (status {status}, code {code!r})"
            if status is not None
            else "the OpenAI call failed and carried no status",
            tokens_emitted=tokens_emitted,
            native_code=code,
            provider_request_id=request_id,
            retry_after=retry_after,
        )

    # ── embedding (ADR-030) ───────────────────────────────────────────────────

    def validate_embedding(
        self, req: EmbeddingRequest, caps: ModelCapabilities
    ) -> list[CapabilityWarning]:
        """Reject or warn per ``caps.on_unsupported``; never drop an option silently.

        At minimum, and each for a failure with no error to catch:

        * ``dimensions`` set while ``Capability.EMBEDDING_DIMENSIONS`` is off. An *ignored*
          ``dimensions`` produces vectors the collection cannot hold, discovered at upsert; an
          *honoured* one produces a second ``EmbeddingSpace`` under the same model id.
        * ``input_type``. OpenAI's models are symmetric and take no query/passage
          discriminator, so ``EMBEDDING_INPUT_TYPE`` is off on these rows and the field is
          ignored — visibly, with a warning, because it is honoured on other vendors and a
          silent ignore here would read as a portable field that is not.
        * The batch ceilings, item count AND summed tokens. They are different limits and the
          token one is the one that surprises: a smoke test embeds fine and a real corpus 400s.
        * The per-input length limit, against the row's ``context_window``. A hard rejection,
          never a truncation — ``embedder.check_window`` performs the same check once per run
          before any customer text leaves the process, and this is the per-request backstop.

        THE TOKEN CEILING IS NOT CHECKED HERE, AND SAYING SO IS BETTER THAN FAKING IT. Counting
        tokens needs the vendor's own tokenizer, which this process does not have and must not
        approximate: a character- or word-based estimate is wrong in the dangerous direction on
        exactly the scripts where a silent trim would be least noticed. Two things already
        cover it and neither is this function — ``embedder.check_window`` refuses a model whose
        window cannot hold a maximum chunk, once per run, before any customer text leaves the
        process, and ``PROVIDER_TRUNCATION_POLICY = "reject"`` means the vendor's own 400 is
        the enforcement rather than a fallback. **The failure mode being avoided is a check
        that passes wrongly**, which would convert a loud 400 into an indexed chunk whose tail
        is unsearchable forever and whose run reported success.
        """
        warnings: list[CapabilityWarning] = []

        if req.dimensions is not None and Capability.EMBEDDING_DIMENSIONS not in caps.supported:
            warnings.append(
                self._unsupported(
                    caps,
                    option="dimensions",
                    detail=(
                        f"{req.model!r} does not declare Capability.EMBEDDING_DIMENSIONS, so "
                        f"dimensions={req.dimensions} cannot be honoured. An IGNORED dimensions "
                        "produces vectors of the model's native width, which the collection "
                        "cannot hold and which is discovered at upsert after the whole embed "
                        "spend; an HONOURED one produces a second EmbeddingSpace under the same "
                        "model id, which upserts cleanly and is meaningless"
                    ),
                )
            )

        if Capability.EMBEDDING_INPUT_TYPE not in caps.supported:
            # DELIBERATELY ALWAYS A WARNING, NEVER A REJECTION, AND THIS IS THE ONE PLACE THAT
            # DOES NOT OBEY `on_unsupported`. `EmbeddingRequest.input_type` has no default and
            # is required, precisely so no caller can omit it and be silently right on two
            # vendors and silently wrong on a third. Rejecting it here would therefore refuse
            # EVERY embedding call to a symmetric vendor — the field is unavoidable and its
            # being ignored is correct behaviour, not a misconfiguration.
            warnings.append(
                CapabilityWarning(
                    option="input_type",
                    action="ignored",
                    detail=(
                        f"OpenAI's embedding models are symmetric and take no query/passage "
                        f"discriminator, so input_type={req.input_type.value!r} is not sent. "
                        "It is reported rather than dropped because it IS honoured on NVIDIA "
                        "NIM, where a wrong value costs recall with nothing raised"
                    ),
                )
            )

        if len(req.texts) > MAX_EMBEDDING_INPUTS:
            raise KbError(
                ErrorClass.VALIDATION,
                f"{len(req.texts)} inputs exceeds OpenAI's ceiling of {MAX_EMBEDDING_INPUTS} "
                "for one embeddings request. Ingestion batches at embedder.MAX_BATCH_TEXTS and "
                "never reaches this, so a caller that does is not batching at all",
            )

        if any(not text.strip() for text in req.texts):
            # An empty input is a 400 from the vendor, and finding it here names WHICH position
            # rather than returning a batch-wide refusal for a chunker defect.
            blank = next(i for i, text in enumerate(req.texts) if not text.strip())
            raise KbError(
                ErrorClass.VALIDATION,
                f"texts[{blank}] is empty or whitespace-only. An empty chunk has no embedding "
                "and no lexical vector either; it is a chunker defect and must surface as one "
                "rather than as a vendor 400 on a batch of 64",
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

    async def embed(
        self,
        req: EmbeddingRequest,
        caps: ModelCapabilities,
        credential: SecretStr,
    ) -> EmbeddingResult:
        """``POST /v1/embeddings``. Vectors in INPUT order, never the vendor's response order.

        ``async def`` here and plain ``def`` on ``stream()`` — see the Protocol's note. This
        one really is a coroutine: one request, one response, nothing to yield.

        Re-sorts the response on each entry's ``index`` and asserts the result covers every
        input exactly once. Never returns a partial result: a batch that half succeeded would
        write half a source version's vectors, and the version would then publish as complete.

        Fills ``EmbeddingResult.space`` from ``app.retrieval.collection.EmbeddingSpace`` with
        the width **actually returned**, and ``normalized`` from what the response actually
        contains — not from the vendor's documentation, which is the claim
        ``collection.DENSE_DISTANCE = "Cosine"`` rests on and the only thing that can check it.

        ``max_retries=0`` and an explicit timeout, as on the chat client, and for the sharper
        reason here: an SDK retry inside one ``await`` re-bills the whole batch and the span
        records one call.

        ``with_raw_response`` rather than the plain call, for the reason the module docstring
        gives about the chat surface: ``x-request-id`` and the rate-limit headers are only
        reachable from the raw response, they are the only handle vendor support accepts, and
        they are missing on precisely the failures worth asking about if they are not captured
        here.
        """
        warnings = self.validate_embedding(req, caps)

        body: dict[str, Any] = {"model": req.model, "input": list(req.texts)}
        if req.dimensions is not None and Capability.EMBEDDING_DIMENSIONS in caps.supported:
            body["dimensions"] = req.dimensions
        # NO `input_type`, and no truncation option of any kind — see PROVIDER_TRUNCATION_POLICY.

        client = self._client(credential)
        started = time.perf_counter()
        raw = await client.embeddings.with_raw_response.create(
            **body,
            # `total` and not `first_token`: there is no stream on this surface, so the only
            # meaningful bound is the whole request.
            timeout=httpx.Timeout(
                req.timeouts.total,
                connect=req.timeouts.connect,
                read=req.timeouts.total,
                write=req.timeouts.connect,
                pool=req.timeouts.connect,
            ),
        )
        total_ms = int((time.perf_counter() - started) * 1000)

        response = raw.parse()
        request_id = raw.headers.get("x-request-id")
        rate_limit = {
            header: raw.headers[header] for header in RATE_LIMIT_HEADERS if header in raw.headers
        }

        vectors = _vectors_in_input_order(response.data, expected=len(req.texts))
        width = len(vectors[0])

        # EVERY VECTOR, NOT THE FIRST. A batch whose widths disagree is a vendor bug we would
        # otherwise launder into a collection: the first width names the space, the rest upsert
        # against it, and Qdrant rejects only the ones that differ — mid-run, after the spend,
        # with a partially indexed version.
        for position, vector in enumerate(vectors):
            if len(vector) != width:
                raise KbError(
                    ErrorClass.PROVIDER_PERMANENT_REQUEST,
                    f"OpenAI returned vectors of differing widths in one batch: "
                    f"texts[0] is {width}-wide and texts[{position}] is {len(vector)}-wide. "
                    "One batch is one embedding space by definition",
                )

        return EmbeddingResult(
            vectors=vectors,
            space=EmbeddingSpace(
                provider=self.name,
                # THE SERVED ID, off the response. `req.model` is an alias with no dated
                # snapshot on this surface (finding C3), and the space is what names the
                # collection — so it is built from what answered, never from what was asked.
                model=response.model,
                # THE WIDTH ACTUALLY RETURNED. `dimensions` truncates and several ids ship at
                # more than one width, so the id does not imply it.
                dimensions=width,
            ),
            normalized=_is_unit_norm(vectors[0]),
            usage=self._embedding_usage(response.usage),
            provider_request_id=request_id,
            total_ms=total_ms,
            diagnostics=Diagnostics(
                provider=self.name,
                rate_limit=rate_limit,
                warnings=warnings,
            ),
        )

    @staticmethod
    def _embedding_usage(raw: Any) -> Usage:
        """``prompt_tokens`` is the entire cost. Nothing to subtract — there is no cache here.

        Separate from ``_usage`` on purpose. ``_usage`` subtracts ``cached_tokens`` out of
        ``input_tokens`` because on the chat surface the cached amount is a SUBSET; this
        endpoint reports no cached amount at all, so the same code path would subtract zero
        today and silently start subtracting the wrong thing the day OpenAI adds one::

            Usage.input_tokens       = usage.prompt_tokens
            Usage.cache_read_tokens  = 0     # not reported on this endpoint
            Usage.cache_write_tokens = 0
            Usage.output_tokens      = 0     # nothing is generated
            Usage.reasoning_tokens   = 0
            Usage.source             = "provider_final"

        Billing reads ``total_input_tokens`` here as everywhere else.
        """
        prompt_tokens = getattr(raw, "prompt_tokens", None)
        if not isinstance(prompt_tokens, int):
            # `estimated` rows are never aggregated into invoiced cost, so an unreadable usage
            # block degrades the ATTRIBUTION and never the bill. Inventing a number here would
            # be worse than reporting none: it would be indistinguishable from a measurement.
            return Usage(source="estimated")
        return Usage(
            input_tokens=prompt_tokens,
            # NOT COPIED FROM `_usage`. That method subtracts `cached_tokens` because on the
            # chat surface the cached amount is a SUBSET of the input; this endpoint reports no
            # cached amount at all, so the same code would subtract zero today and start
            # subtracting the wrong thing the day OpenAI adds one.
            cache_read_tokens=0,
            cache_write_tokens=0,
            output_tokens=0,
            reasoning_tokens=0,
            source="provider_final",
        )


def _vectors_in_input_order(data: Any, *, expected: int) -> list[list[float]]:
    """Re-sort the response on each entry's ``index`` and prove the cover is exact.

    **The positional read is the bug this exists to prevent**, and it is the quietest one on
    this surface: OpenAI documents that entries may arrive out of order, and
    ``EmbeddingResult.vectors[i]`` is contractually the embedding of ``texts[i]``. Reading the
    list positionally produces a fully populated, fully wrong index — every chunk carries some
    other chunk's vector, nothing raises, every count matches, and retrieval simply returns
    plausible neighbours that are not neighbours at all.

    A partial or duplicated cover raises rather than returning what arrived. Half a batch
    written is half a source version's vectors, and the version publishes as complete.
    """
    by_index: dict[int, list[float]] = {}
    for entry in data:
        index = entry.index
        if not isinstance(index, int) or not 0 <= index < expected:
            raise KbError(
                ErrorClass.PROVIDER_PERMANENT_REQUEST,
                f"OpenAI returned an embedding at index {index!r}, which is outside the "
                f"{expected} inputs that were sent",
            )
        if index in by_index:
            raise KbError(
                ErrorClass.PROVIDER_PERMANENT_REQUEST,
                f"OpenAI returned two embeddings for index {index}; the response does not "
                "describe the batch that was sent, and a positional read of it would be silent",
            )
        by_index[index] = list(entry.embedding)

    if len(by_index) != expected:
        missing = sorted(set(range(expected)) - by_index.keys())
        raise KbError(
            ErrorClass.PROVIDER_PERMANENT_REQUEST,
            f"OpenAI returned {len(by_index)} embeddings for {expected} inputs "
            f"(missing {missing[:8]}). A partial batch is never returned as a partial result: "
            "it would write half a source version's vectors and the version would publish as "
            "complete",
        )

    return [by_index[index] for index in range(expected)]


def _is_unit_norm(vector: list[float]) -> bool:
    """Whether the vendor returned a unit-norm vector, **as observed on this response**.

    Not read from the vendor's documentation, which is the whole point: ``DENSE_DISTANCE`` is
    fixed to ``"Cosine"`` and folded into the collection name because "every embedding API here
    documents its vectors as normalized", and this is the only evidence that the claim holds
    for the vectors actually returned. Cosine and dot product coincide only while it does.

    The tolerance is loose deliberately. This is a recorded finding rather than a raised
    failure — the indexer L2-normalizes regardless (``embedder.NORMALIZE_EMBEDDINGS``) — so the
    cost of a false negative is a misleading record and the cost of a tight bound is a false
    negative on every float32 round trip.
    """
    if not vector:
        return False
    magnitude = math.sqrt(sum(component * component for component in vector))
    return abs(magnitude - 1.0) < 1e-3


def _assert_conforms(adapter: OpenAIAdapter) -> ProviderAdapter:
    """Static conformance, checked by mypy and costing nothing at runtime.

    If a method here drifts from the Protocol — a renamed parameter, a changed return type —
    this return is the error. Without it the drift surfaces at the one call site that
    matters, in the streaming path, at request time.
    """
    return adapter


def _assert_embeds(adapter: OpenAIAdapter) -> EmbeddingAdapter:
    """The static half of the embedding capability gate.

    This function existing **is** the claim that OpenAI embeds, and it is the claim mypy can
    check. The other half is ``capabilities.PROVIDER_TASKS[("openai", EMBEDDING)]``, which is
    the claim a caller can *query*; ``tests/unit/test_provider_capability_matrix.py`` asserts
    the two agree, in both directions, for all five adapters.

    There is deliberately no ``_assert_reranks`` in this module: OpenAI is recorded
    ``UNSUPPORTED`` on the rerank surface by ``bge-reranker`` and ``nvidia-nim-api``, and
    corroborated by enumeration — its published ``openapi.yaml`` describes 182 paths and none
    of them ranks. Adding the method here without moving the matrix cell fails that test.
    """
    return adapter
