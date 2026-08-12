"""Structured logging for every process that runs this service.

WHAT WAS HERE BEFORE THIS MODULE: NOTHING
=========================================
Five modules obtained a ``logging.getLogger(__name__)`` and nothing anywhere configured a
handler, a level or a format. The root logger therefore sat at its default ``WARNING`` with an
empty handler list, which produces two failures at once:

* every ``logger.info(...)`` and ``logger.debug(...)`` in the service was **discarded**, and
* everything at ``WARNING`` and above fell through to ``logging.lastResort`` — a bare
  ``StreamHandler(sys.stderr)`` at ``WARNING`` with no formatter — so the few lines that did
  escape were unformatted, untimestamped, uncorrelated text.

``Settings.log_level`` was declared and read by nobody, and ``otel.configure`` installed
``LoggingInstrumentor`` with a comment claiming "our JSON formatter owns the line shape" beside
a JSON formatter that did not exist.

THE CONTRACT THIS IMPLEMENTS
============================
``kb-observability-conventions/references/logs-health-audit.md`` owns the field names, and they
are as permanent as a metric name — a Loki query or an alert runbook that references one cannot
be renamed without breaking both:

* **One JSON object per line on stdout, RFC3339 UTC.**
* **Required on every line:** ``timestamp``, ``severity``, ``service``, ``env``, ``trace_id``,
  ``span_id``, ``request_id``, ``operation``.
* **When applicable:** ``org_id``, ``bot_id``, ``job_id``, ``error_class``, ``duration_ms``.
  ``org_id`` and ``bot_id`` are opaque ULIDs and belong in **logs** — §20.3's "where safe" means
  *not as metric labels*, not "omit them". They are what makes a tenant incident debuggable.
* **Durations are ``duration_ms`` in logs and seconds in metrics.** The asymmetry is deliberate;
  do not "fix" it.
* **Never logged, at any level, in any environment:** provider API keys, passwords,
  ``Authorization`` and ``X-KB-Signature`` values, session tokens and cookies, the user's
  question, retrieved chunk text, the assembled prompt, model output, file contents.

Loki stream labels are ``service``, ``env``, ``level`` and ``container`` and nothing else
(`prometheus-grafana-loki-tempo`). Every other field above stays **inside the JSON payload** and
is queried with ``| json | org_id="…"``. A Loki stream is one chunk per unique label set, so
promoting ``org_id`` or ``job_id`` to a label multiplies streams combinatorially, each holding an
under-filled chunk in memory, and the ingesters OOM days after the "small" logging change. The
line filter is fast because the label set has already narrowed the stream.

WHY THE DEFENCE IS AN ALLOW-LIST AND NOT A SCRUBBER
===================================================
The skill is explicit: *"Enforced by a field allow-list at the logger, not a regex scrubber
downstream. The scrubber is the backstop; the allow-list is the defence."* So
:data:`ALLOWED_EXTRA_FIELDS` is closed: a key in ``extra=`` that is not in it is **dropped**, and
its *name* (never its value) is reported in ``dropped_fields`` so the author notices instead of
believing the field shipped. The never-log list above is enforced by **exclusion**, not by
enumeration — a denylist only catches the cases somebody imagined, and the case nobody imagined
is the one that ships.

Redaction is NOT built on ``SecretStr``, and cannot be. ``app/core/config.py`` deliberately holds
**zero** ``SecretStr`` fields (asserted by ``test_no_setting_is_a_secret_value``): credentials are
mounted files read at their point of use, and the plaintext provider key arrives as a field on an
inbound request body. A defence keyed on a type would therefore protect nothing that matters.
:func:`redact` is the backstop for the one surface the allow-list cannot reach — the developer-
authored message string — and :data:`REDACTION_LIMITS` states plainly what it does not catch.

THE TWO PROCESS TYPES, AND WHY ONE ``dictConfig`` IS NOT ENOUGH
==============================================================
``create_app()`` covers ``ai-api`` only. The four ``ai-worker-*`` containers and ``ai-beat`` are
separate processes that never import ``app.main``, and Celery hijacks the root logger by default
(``worker_hijack_root_logger``) — it clears ``root.handlers`` and installs its own, so a
``dictConfig`` applied at Celery import time is stomped a moment later. The supported way out is
the ``setup_logging`` signal: ``celery.app.log.Logging.setup_logging_subsystem`` sends it and, if
**any** receiver is connected, skips its entire logging setup (verified against Celery 5.6.3,
``celery/app/log.py``). :func:`install_celery_logging` connects one. Both ``celery worker`` and
``celery beat`` route through ``app.log.setup``, so the single receiver covers all five
processes; prefork children inherit the configured root logger across ``fork()`` and Celery's own
``Logging._setup`` class flag (also inherited) stops them re-running it.

Uvicorn is the mirror image: it calls ``dictConfig`` on its own ``LOGGING_CONFIG`` in
``Config.__init__`` — *before* it imports the application — and leaves ``uvicorn``,
``uvicorn.error`` and ``uvicorn.access`` carrying their own handlers with ``propagate=False``.
Left alone that is either doubled lines or access logs that bypass this formatter entirely, so
:func:`configure_logging` names those three loggers explicitly, strips their handlers and turns
propagation back on.
"""

from __future__ import annotations

import json
import logging
import logging.config
import math
import os
import re
import sys
from collections.abc import Mapping
from contextlib import contextmanager
from contextvars import ContextVar
from datetime import UTC, datetime
from typing import TYPE_CHECKING, Any, Final

from opentelemetry import trace

if TYPE_CHECKING:  # pragma: no cover - typing only
    from collections.abc import Iterator

    from app.core.config import Settings

__all__ = [
    "ALLOWED_EXTRA_FIELDS",
    "FORMATTER_OWNED_FIELDS",
    "REDACTION_LIMITS",
    "REDACTION_MARKER",
    "SEVERITY",
    "KbJsonFormatter",
    "KbLogContextFilter",
    "bind_log_context",
    "configure_logging",
    "current_log_context",
    "install_celery_logging",
    "logging_is_configured",
    "redact",
    "reset_logging_for_tests",
]

# ─────────────────────────────────────────────────────────────────────────────
# Field contracts
# ─────────────────────────────────────────────────────────────────────────────

#: Emitted on **every** line and never settable from a call site. The first eight are the
#: required set from ``logs-health-audit.md``; the last four are structural — a log line without
#: a message is not a log line, ``logger`` names the emitting module (bounded: it is an import
#: path), ``exception`` carries the traceback, and ``dropped_fields`` is how the allow-list tells
#: an author their field did not ship rather than losing it in silence.
FORMATTER_OWNED_FIELDS: Final[tuple[str, ...]] = (
    "timestamp",
    "severity",
    "service",
    "env",
    "trace_id",
    "span_id",
    "request_id",
    "operation",
    "logger",
    "message",
    "exception",
    "dropped_fields",
)

#: What a call site may pass in ``extra=``. **Closed.** Anything else is dropped.
#:
#: Three buckets, and the distinction matters when extending it:
#:
#: 1. The catalogue's own *when applicable* fields. ``org_id``/``bot_id``/``job_id`` are banned as
#:    metric labels and blessed for logs — that is the whole point of the split.
#: 2. The **metric label allow-list** from ``kb-observability-conventions``. Every value in it is
#:    bounded by construction (that is why it is allowed to be a label at all), so it is
#:    trivially safe in a log payload too, and reusing the closed list avoids inventing a second
#:    vocabulary for the same facts. ``model`` is folded to ``other`` before it reaches an
#:    instrument; in a log line the raw string is acceptable because a log field is not a series.
#: 3. Log-only diagnostics that no metric label can express. Each one is here because a call site
#:    in this repository needs it; adding a fourth needs a line in
#:    ``references/logs-health-audit.md`` in the same change, exactly as a metric label does.
#:
#: NOTE WHAT IS ABSENT and must stay absent: ``source_id``, ``conversation_id``, ``message_id``,
#: ``chunk_id``, ``user_id``, ``url``, ``query``, ``prompt``, ``packed_context``, ``text``,
#: ``content``, ``messages``, ``provider_credential``, ``authorization``, ``x-kb-signature``.
#: The first group is not in the catalogue's *when applicable* list and admitting it is a
#: catalogue decision, not a code decision. The second group is tenant content or credential
#: material and is never admissible.
ALLOWED_EXTRA_FIELDS: Final[frozenset[str]] = frozenset(
    {
        # (1) logs-health-audit.md — "When applicable"
        "org_id",
        "bot_id",
        "job_id",
        "error_class",
        "duration_ms",
        # (2) the metric label allow-list, bounded by construction
        "outcome",
        "disposition",
        "provider",
        "model",
        "from_model",
        "to_model",
        "token_type",
        "finish_reason",
        "stage",
        "reason",
        "scale",
        "kind",
        "engine",
        "file_type",
        "queue",
        "collection",
        "task",
        "status_class",
        "dependency",
        "required",
        "version",
        "git_sha",
        "contract_version",
        # (3) log-only diagnostics
        # `attempt` and `count` are the two numbers a retry or a batch line is useless without,
        # and neither can be a label (an attempt number is unbounded-ish and a count is a value,
        # not a dimension).
        "attempt",
        "count",
        # The validation-rejection map from `app/main.py:_handle_validation_error`. It carries
        # field paths and Pydantic's own messages only — that handler already strips Pydantic's
        # `input` field, which for an internal request is tenant content.
        "errors",
        # `otel.configure` reports which of its two shapes it ran as. Two booleans.
        "in_worker",
        "instrumented_app",
    }
)

#: ``record.levelname`` -> the string the Collector's ``severity_parser`` actually understands.
#:
#: THE STRINGS WERE MEASURED, NOT GUESSED, AND THE MEASUREMENT IS RECORDED IN THE PHP TWIN.
#: ``otel/opentelemetry-collector-contrib:0.158.0`` was run against the operator block from
#: ``collector.yaml`` with one probe line per level name. The parser accepts, case-insensitively,
#: exactly ``trace|debug|info|warn|error|fatal`` each with an optional ``2``/``3``/``4`` suffix,
#: plus the alias ``warning``. Everything else lands on ``SeverityNumber Unspecified(0)`` and
#: therefore reaches Loki with **no ``level`` stream label at all**, so every "errors in the last
#: hour" query and every level-faceted panel reads zero while the lines sit in the store.
#:
#: Python's stdlib levels are a subset of Monolog's, so emitting ``record.levelname`` verbatim
#: was correct for four of the five and wrong for the most serious one: ``CRITICAL`` — which is
#: what ``logging.critical`` and ``logger.fatal`` both produce — was unlabelled.
#:
#: THIS TABLE IS THE SAME TABLE AS ``KbJsonFormatter::SEVERITY`` in
#: ``services/core-api/app/Logging/KbJsonFormatter.php``, deliberately including the three
#: RFC 5424 levels Python does not have. Python's ``logging`` lets an application register
#: ``NOTICE``/``ALERT``/``EMERGENCY`` with ``addLevelName``, and a library may already have; more
#: importantly, two independent tables for one mapping is how the two runtimes come to disagree
#: about what an ``ERROR2`` line means. The mapping goes through the OpenTelemetry logs data
#: model's syslog table, which is the specified answer rather than an invented one, and it is the
#: only one that preserves ORDERING: EMERGENCY(21) > ALERT(19) > CRITICAL(18) > ERROR(17) >
#: WARNING(13) > NOTICE(10) > INFO(9) > DEBUG(5).
#:
#: An unmapped name passes through unchanged rather than being coerced. A custom level somebody
#: registered is not this table's to rename, and a name the parser does not recognise is visible
#: in Grafana as a missing label — whereas silently rewriting it to ``INFO`` would be a lie that
#: nothing can detect.
SEVERITY: Final[Mapping[str, str]] = {
    "DEBUG": "DEBUG",  # Debug(5)
    "INFO": "INFO",  # Info(9)
    "NOTICE": "INFO2",  # Info2(10)  — "normal but significant"
    "WARNING": "WARNING",  # Warn(13)   — `warning` is a built-in alias for `warn`
    "ERROR": "ERROR",  # Error(17)
    "CRITICAL": "ERROR2",  # Error2(18)
    "ALERT": "ERROR3",  # Error3(19)
    "EMERGENCY": "FATAL",  # Fatal(21)
}

#: Attributes ``logging`` puts on every record. Anything on ``record.__dict__`` outside this set
#: and outside :data:`FORMATTER_OWNED_FIELDS` arrived from a call site's ``extra=``.
_RESERVED_RECORD_ATTRS: Final[frozenset[str]] = frozenset(
    {
        "args",
        "asctime",
        "created",
        "exc_info",
        "exc_text",
        "filename",
        "funcName",
        "levelname",
        "levelno",
        "lineno",
        "module",
        "msecs",
        "message",
        "msg",
        "name",
        "pathname",
        "process",
        "processName",
        "relativeCreated",
        "stack_info",
        "taskName",
        "thread",
        "threadName",
        # LoggingInstrumentor's record factory adds these three. They are consumed by
        # `_trace_ids` and never rendered under these names — the catalogue's names are
        # `trace_id` and `span_id`.
        "otelTraceID",
        "otelSpanID",
        "otelTraceSampled",
        "otelServiceName",
        # Celery's TaskFormatter sets these when a task is running. `task` is in the allow-list
        # under its catalogue spelling; `task_id` is a job identifier and rides in `job_id`.
        "task_id",
        "task_name",
        # Uvicorn puts an ANSI-coloured copy of the message on its own startup and access
        # records. Ignored rather than dropped: it is the framework's own plumbing, not a call
        # site's field, and reporting it in `dropped_fields` would put a permanent, meaningless
        # marker on every uvicorn line in every container.
        "color_message",
    }
)

# ─────────────────────────────────────────────────────────────────────────────
# Redaction — the BACKSTOP. The allow-list above is the defence.
# ─────────────────────────────────────────────────────────────────────────────

REDACTION_MARKER: Final[str] = "[REDACTED]"

#: Stated plainly, because a redactor that gives false confidence is worse than none.
#:
#: What :func:`redact` **does** catch, in the message string and the formatted traceback:
#:   * ``key: value`` / ``key=value`` for a credential-shaped key name;
#:   * ``Bearer …`` / ``Basic …`` and our own ``KB1 …`` signature scheme;
#:   * the five vendor key shapes we can actually pin (``sk-``, ``sk-ant-``, ``nvapi-``,
#:     ``AKIA…``, ``ghp_``);
#:   * the query string of any absolute http(s) URL — the route by which a customer's question
#:     reaches telemetry unnoticed, and the same key the Collector's ``transform`` deletes.
#:
#: What it **does not** catch, and cannot:
#:   * ``logger.info("answer: %s", completion)`` — model output interpolated into the message.
#:     Nothing in a formatter can tell prose from prose. The allow-list stops the *field*; only
#:     review stops the *message*.
#:   * a credential with no recognisable shape — a bare 32-character hex string from a provider
#:     that does not prefix its keys is indistinguishable from a request id.
#:   * retrieved chunk text, a packed prompt or a user question written into the message.
#:   * anything a third-party library logs on its own logger before it reaches us in a shape we
#:     recognise. ``httpx`` logs request URLs; the URL rule covers the query string and nothing
#:     more.
REDACTION_LIMITS: Final[str] = (
    "catches credential-shaped tokens, Authorization/X-KB-Signature/KB1 values and URL query "
    "strings in the message and traceback; does NOT catch tenant content, model output or an "
    "unshaped credential interpolated into a message string"
)

#: The optional auth SCHEME is captured and kept, and the token after it is what goes. Without
#: that group the key/value rule matches `Authorization: Bearer <token>`, replaces the word
#: `Bearer` (the next non-space run) and leaves the actual credential standing in the line —
#: which is worse than not matching at all, because the output looks redacted.
_CREDENTIAL_KEY_VALUE: Final[re.Pattern[str]] = re.compile(
    r"(?i)\b(authorization|x[-_]kb[-_]signature|x[-_]api[-_]key|api[-_]?key|apikey|"
    r"secret[-_]?key|access[-_]?key|password|passwd|secret|token|cookie|credential)"
    r"(\"?\s*[:=]\s*\"?)"
    r"((?:bearer|basic|kb1)\s+)?"
    r"[^\s,;\"'}\])]+"
)
_BEARER: Final[re.Pattern[str]] = re.compile(r"(?i)\b(bearer|basic)\s+[A-Za-z0-9._\-+/=]{8,}")
#: Our own signing scheme is `KB1 <key_id>:<signature>` (`kb-internal-api-contracts`), so the
#: colon has to be inside the character class. Without it the pattern matches `KB1 k1` — two
#: characters, below the length floor — and the signature after the colon survives intact.
_KB1_SIGNATURE: Final[re.Pattern[str]] = re.compile(r"\bKB1\s+[A-Za-z0-9+/=._:\-]{8,}")
_VENDOR_KEYS: Final[re.Pattern[str]] = re.compile(
    r"\b(?:sk-ant-[A-Za-z0-9_\-]{12,}"
    r"|sk-[A-Za-z0-9_\-]{12,}"
    r"|nvapi-[A-Za-z0-9_\-]{12,}"
    r"|ghp_[A-Za-z0-9]{20,}"
    r"|AKIA[0-9A-Z]{16})"
)
_URL_QUERY: Final[re.Pattern[str]] = re.compile(r"(?i)(https?://[^\s\"'<>]*?)\?[^\s\"'<>]*")


def redact(text: str) -> str:
    """Strip credential-shaped material from a free-text string.

    The backstop, never the defence — see :data:`REDACTION_LIMITS` for exactly what it misses.
    Applied to the rendered message, to the formatted traceback, and to every string value that
    survives the field allow-list.
    """
    if not text:
        return text
    text = _CREDENTIAL_KEY_VALUE.sub(rf"\1\2\3{REDACTION_MARKER}", text)
    text = _BEARER.sub(rf"\1 {REDACTION_MARKER}", text)
    text = _KB1_SIGNATURE.sub(f"KB1 {REDACTION_MARKER}", text)
    text = _VENDOR_KEYS.sub(REDACTION_MARKER, text)
    return _URL_QUERY.sub(rf"\1?{REDACTION_MARKER}", text)


# ─────────────────────────────────────────────────────────────────────────────
# Request-scoped context
# ─────────────────────────────────────────────────────────────────────────────
#
# `request_id` and `operation` are REQUIRED on every line, and neither is knowable from the
# LogRecord. They travel in context variables so a log call deep in the retrieval path needs no
# argument threading — the same reason the trace context does.
#
# TODO(fastapi-service / control-plane seam): `app/api/deps.py:request_context` is still
# `NotImplementedError`. When it lands it must open `bind_log_context(request_id=…,
# operation=…, org_id=…, bot_id=…)` around the request, and the Celery task base must do the
# same with `job_id`. Until then these render as `null`, which is the honest value: the field is
# present on every line, as the contract requires, and its absence is visible rather than
# implied.

_REQUEST_ID: ContextVar[str | None] = ContextVar("kb_log_request_id", default=None)
_OPERATION: ContextVar[str | None] = ContextVar("kb_log_operation", default=None)
_ORG_ID: ContextVar[str | None] = ContextVar("kb_log_org_id", default=None)
_BOT_ID: ContextVar[str | None] = ContextVar("kb_log_bot_id", default=None)
_JOB_ID: ContextVar[str | None] = ContextVar("kb_log_job_id", default=None)

_CONTEXT_VARS: Final[dict[str, ContextVar[str | None]]] = {
    "request_id": _REQUEST_ID,
    "operation": _OPERATION,
    "org_id": _ORG_ID,
    "bot_id": _BOT_ID,
    "job_id": _JOB_ID,
}


def current_log_context() -> dict[str, str | None]:
    """The context fields currently bound, for the formatter and for assertions."""
    return {name: var.get() for name, var in _CONTEXT_VARS.items()}


@contextmanager
def bind_log_context(**fields: str | None) -> Iterator[None]:
    """Bind ``request_id`` / ``operation`` / ``org_id`` / ``bot_id`` / ``job_id`` for a scope.

    Context variables rather than a thread local: the data plane is async, one event loop
    interleaves many requests on one thread, and a thread local would hand request B's org id to
    request A's log line. Tokens are reset in a ``finally`` so an exception cannot leave a
    previous request's identifiers bound to whatever runs next on this task.

    An unknown key is a ``ValueError`` rather than a silent no-op — a typo'd ``requestId`` that
    binds nothing would make every line in that scope claim it had no request id.
    """
    unknown = set(fields) - set(_CONTEXT_VARS)
    if unknown:
        msg = (
            f"unknown log context field(s): {sorted(unknown)}. "
            f"Permitted: {sorted(_CONTEXT_VARS)} (kb-observability-conventions)"
        )
        raise ValueError(msg)
    tokens = [
        (_CONTEXT_VARS[name], _CONTEXT_VARS[name].set(value)) for name, value in fields.items()
    ]
    try:
        yield
    finally:
        for var, token in reversed(tokens):
            var.reset(token)


class KbLogContextFilter(logging.Filter):
    """Stamp the context fields onto the record.

    A ``Filter`` rather than a ``LogRecordFactory`` so that installing it is part of the same
    ``dictConfig`` that installs the handler — one call configures logging, and there is no
    second global left behind for a test or a second ``configure_logging`` to disagree about.
    """

    def filter(self, record: logging.LogRecord) -> bool:
        for name, value in current_log_context().items():
            # setdefault semantics: an explicit `extra={"org_id": …}` at the call site wins over
            # the ambient binding. A deletion task iterating several orgs is the case.
            if getattr(record, name, None) is None:
                setattr(record, name, value)
        return True


# ─────────────────────────────────────────────────────────────────────────────
# Trace correlation
# ─────────────────────────────────────────────────────────────────────────────


def _trace_ids(record: logging.LogRecord) -> tuple[str | None, str | None]:
    """The active trace and span ids, as lowercase hex, or ``(None, None)``.

    Read from the **active span context** first and from ``LoggingInstrumentor``'s injected
    record attributes second. That order is deliberate and is what makes
    ``otel.py``'s ``set_logging_format=False`` comment true rather than aspirational:

    * the live span context is correct whether or not the instrumentor is installed, so
      correlation does not silently depend on one line in a bootstrap that a process may not
      have run; and
    * the instrumentor's attributes are still honoured, so removing this module does not remove
      the correlation and vice versa.

    ``LoggingInstrumentor`` writes the string ``"0"`` when no span is active, which is why the
    fallback tests for a valid id rather than for presence — taking ``"0"`` at face value would
    put a dead link in Grafana on every line emitted outside a span.
    """
    span_context = trace.get_current_span().get_span_context()
    if span_context.is_valid:
        return (
            trace.format_trace_id(span_context.trace_id),
            trace.format_span_id(span_context.span_id),
        )

    injected_trace = getattr(record, "otelTraceID", None)
    injected_span = getattr(record, "otelSpanID", None)
    if (
        isinstance(injected_trace, str)
        and isinstance(injected_span, str)
        and set(injected_trace) != {"0"}
        and set(injected_span) != {"0"}
    ):
        return injected_trace, injected_span
    return None, None


# ─────────────────────────────────────────────────────────────────────────────
# The formatter
# ─────────────────────────────────────────────────────────────────────────────


def _json_default(value: object) -> str:
    """Serialize the few non-JSON types worth rendering; refuse everything else by NAME.

    An unknown object becomes ``"<ClassName>"`` and never ``str(value)``. ``str`` on an arbitrary
    object is an open channel from any library's ``__str__`` into the log store — a provider
    exception whose repr echoes the request, a model holding a decrypted credential — and it is
    exactly the kind of leak the allow-list cannot see, because the *field name* was fine.
    """
    if isinstance(value, datetime):
        return value.isoformat()
    if isinstance(value, os.PathLike):
        return redact(os.fspath(value))
    if type(value).__module__ in {"uuid", "decimal", "enum"}:
        return redact(str(value))
    return f"<{type(value).__name__}>"


#: How many levels of container ``_normalize`` walks before it stops describing and starts
#: naming. Transcribed from ``KbJsonFormatter::MAX_DEPTH`` in the PHP twin, with the same
#: starting depth of 0, so the two planes truncate the same structure at the same place. It is a
#: bound on recursion as much as on output: an ``extra=`` value is caller-supplied and may be
#: self-referential, and a formatter that raises is a line that logging swallows into
#: ``--- Logging error ---`` on stderr.
_MAX_EXTRA_DEPTH: Final = 4


def _normalize(value: Any, depth: int) -> Any:
    """Render one allowed ``extra=`` value, redacting **every** string it contains.

    The transcription of ``App\\Logging\\KbJsonFormatter::normalize``, and it exists because the
    two planes had one vocabulary and two treatments: this side used to apply :func:`redact` to
    top-level strings only, so a ``dict`` or ``list`` under an allowed name reached
    ``json.dumps`` untouched while PHP walked it. The live shape is ``app/main.py``'s validation
    handler, whose ``errors`` extra is a ``dict[str, list[str]]`` — nested exactly deep enough
    that the redaction which fires on a bare string does not fire on the same string one level
    down.

    Leaves this side does not decide are returned as they are and land on ``_json_default``,
    which is the counterpart of the PHP ``scalarize`` and the one place an unknown object is
    refused by name. Containers past the cap are named the same way — ``"<dict>"``, ``"<list>"``
    — rather than PHP's single ``"<array>"``, because PHP has one container type and this side
    has two, and a reader of the line should be told which one was truncated.
    """
    if isinstance(value, str):
        return redact(value)
    if value is None or isinstance(value, bool | int):
        return value
    if isinstance(value, float):
        # Neither NaN nor an infinity is representable in JSON. `json.dumps` emits them as bare
        # `NaN`/`Infinity` literals, which parse in Python and in nothing else — the Collector's
        # JSON parser drops the line, so the field takes the whole log entry with it.
        return value if math.isfinite(value) else str(value)
    if isinstance(value, dict):
        if depth >= _MAX_EXTRA_DEPTH:
            return f"<{type(value).__name__}>"
        # Keys are stringified and NOT redacted, matching the PHP `(string) $key`. A key is a
        # field name from our own code; it is the values that carry caller-supplied text.
        return {str(key): _normalize(item, depth + 1) for key, item in value.items()}
    if isinstance(value, list | tuple):
        if depth >= _MAX_EXTRA_DEPTH:
            return f"<{type(value).__name__}>"
        return [_normalize(item, depth + 1) for item in value]
    return value


class KbJsonFormatter(logging.Formatter):
    """One JSON object per line, RFC3339 UTC, with the closed field set.

    ``service`` and ``env`` are resolved once at construction rather than per record: they are
    process-wide, they are two of the four permitted Loki stream labels, and reading the
    environment per line would let a mid-process mutation split one container's logs across two
    streams.
    """

    def __init__(self, *, service: str, env: str) -> None:
        super().__init__()
        self._service = service
        self._env = env

    def format(self, record: logging.LogRecord) -> str:
        created = datetime.fromtimestamp(record.created, tz=UTC)
        trace_id, span_id = _trace_ids(record)

        payload: dict[str, Any] = {
            # RFC3339 UTC with millisecond precision — the same shape the Collector's docker
            # `json_parser` layout uses, so a human comparing the two sees one format.
            "timestamp": f"{created:%Y-%m-%dT%H:%M:%S}.{created.microsecond // 1000:03d}Z",
            # `severity`, not `level`. The catalogue names this field; Loki's stream label is
            # derived from it by the Collector's `severity_parser`, which is why the value goes
            # through :data:`SEVERITY` instead of being `record.levelname` verbatim.
            "severity": SEVERITY.get(record.levelname, record.levelname),
            "service": self._service,
            "env": self._env,
            "trace_id": trace_id,
            "span_id": span_id,
            "request_id": getattr(record, "request_id", None),
            "operation": getattr(record, "operation", None),
            "logger": record.name,
            "message": redact(record.getMessage()),
        }

        extras, dropped = self._partition_extras(record)
        payload.update(extras)
        if dropped:
            payload["dropped_fields"] = dropped

        if record.exc_info:
            payload["exception"] = redact(self.formatException(record.exc_info))
        elif record.exc_text:
            payload["exception"] = redact(record.exc_text)
        if record.stack_info:
            payload["exception"] = redact(
                f"{payload.get('exception', '')}\n{self.formatStack(record.stack_info)}".strip()
            )

        # `default=` rather than a pre-pass: a value that cannot be serialized must not be able
        # to raise inside a handler, because logging swallows that into `--- Logging error ---`
        # on stderr and the line is lost.
        return json.dumps(payload, default=_json_default, ensure_ascii=False, separators=(",", ":"))

    def _partition_extras(self, record: logging.LogRecord) -> tuple[dict[str, Any], list[str]]:
        """Split a record's ``extra=`` fields into the allowed ones and the dropped names."""
        allowed: dict[str, Any] = {}
        dropped: list[str] = []
        for key, value in record.__dict__.items():
            if key in _RESERVED_RECORD_ATTRS or key in FORMATTER_OWNED_FIELDS:
                continue
            if key not in ALLOWED_EXTRA_FIELDS:
                # The NAME only. A dropped field's value is dropped precisely because it may be
                # the thing that must never be written down.
                dropped.append(key)
                continue
            if value is None:
                continue
            allowed[key] = _normalize(value, 0)
        return allowed, sorted(dropped)


# ─────────────────────────────────────────────────────────────────────────────
# Configuration
# ─────────────────────────────────────────────────────────────────────────────

_configured = False

#: Uvicorn calls ``dictConfig`` on its own ``LOGGING_CONFIG`` inside ``Config.__init__`` — before
#: it imports the application — and gives these three loggers their own handlers with
#: ``propagate=False``. Naming them here strips those handlers and restores propagation, so the
#: access log goes through this formatter instead of bypassing it, and nothing is emitted twice.
#: ``uvicorn.asgi`` is absent on purpose: it is not in uvicorn's config and has no handler to
#: strip.
#:
#: NAMING THE PARENT IS WHAT DOES THE WORK; the children are documentation. ``dictConfig``'s
#: ``_handle_existing_loggers`` resets every *existing* logger that is a descendant of a
#: configured one — ``level = NOTSET``, ``handlers = []``, ``propagate = True`` — so configuring
#: ``uvicorn`` alone already reclaims ``uvicorn.access``. Measured by mutation: deleting
#: ``"uvicorn.access"`` from this tuple changes nothing, deleting ``"uvicorn"`` breaks the access
#: log. They stay listed because a reader should not have to know that rule to see which loggers
#: this reclaims, and because the same is not true of ``celery.task``, whose ``propagate`` Celery
#: sets to the int ``0`` *after* any dictConfig we run — see the explicit fix-up in
#: :func:`configure_logging`.
_HIJACKED_LOGGERS: Final[tuple[str, ...]] = (
    "uvicorn",
    "uvicorn.error",
    "uvicorn.access",
    "fastapi",
    # Celery only reaches these if `install_celery_logging` was not connected in time; naming
    # them makes that failure mode produce correct lines rather than doubled ones.
    "celery",
    "celery.task",
    "celery.redirected",
)

#: Third-party loggers that are chatty at DEBUG and say nothing an on-call reader wants. Pinned
#: at INFO so ``KB_LOG_LEVEL=DEBUG`` gives *our* debug lines without ten thousand lines of
#: connection-pool and multipart-parser noise burying them.
_NOISY_LOGGERS: Final[tuple[str, ...]] = (
    "httpcore",
    "httpx",
    "urllib3",
    "asyncio",
    "botocore",
    "boto3",
    "s3transfer",
    "multipart",
    "filelock",
)


def _resolve_service() -> str:
    """The ``service`` field, bounded to the four values the Loki/Prometheus label allows.

    ``OTEL_SERVICE_NAME`` first, because that is what the OTel ``Resource`` uses and therefore
    what the ``service`` **metric** label will say. A log line whose ``service`` disagrees with
    the metric label for the same process is the quiet version of a broken dashboard: both
    queries succeed and they describe different fleets.
    """
    from_env = os.environ.get("OTEL_SERVICE_NAME")
    if from_env:
        return from_env
    settings = _settings_or_none()
    return settings.otel_service_name if settings is not None else "ai-api"


def _resolve_env() -> str:
    """The ``env`` field, from ``OTEL_RESOURCE_ATTRIBUTES`` and then ``KB_ENVIRONMENT``.

    Same reasoning as :func:`_resolve_service`: ``deployment.environment.name`` is what the
    Collector projects onto the ``env`` metric label, so reading it first guarantees the log
    field and the metric label agree. ``KB_ENVIRONMENT`` is the fallback and is the value
    ``Settings.environment`` validates.
    """
    raw = os.environ.get("OTEL_RESOURCE_ATTRIBUTES", "")
    for pair in raw.split(","):
        key, sep, value = pair.partition("=")
        if sep and key.strip() == "deployment.environment.name" and value.strip():
            return value.strip()
    settings = _settings_or_none()
    return settings.environment if settings is not None else "local"


def _settings_or_none() -> Settings | None:
    """``get_settings()``, or ``None`` if it refuses to build.

    Logging must be configurable **before** and **despite** configuration failing. ``Settings``
    construction runs ``check_environment``, which raises on an unrecognised ``KB_*`` variable —
    and that startup failure is precisely the event whose log line must be readable. Falling
    back to the field defaults means the traceback is emitted as JSON at INFO instead of
    vanishing into ``logging.lastResort``.
    """
    try:
        from app.core.config import get_settings

        return get_settings()
    except Exception:
        # Broad on purpose. `check_environment` raises RuntimeError, pydantic raises
        # ValidationError, a missing mount raises OSError — and none of them may stop this
        # process from having a logger, because the resulting traceback is the thing that has to
        # be readable.
        return None


def _resolve_level(explicit: str | None) -> str:
    """The level: an explicit argument, else ``Settings.log_level`` (``KB_LOG_LEVEL``).

    THIS IS THE ONLY SOURCE OF TRUTH, and it deliberately outranks ``celery --loglevel``.
    Celery's own value is passed to :func:`install_celery_logging`'s receiver and ignored there;
    one knob configuring five processes is worth more than honouring a flag no container in
    ``compose.yaml`` passes, and two knobs for one setting is how ``ai-worker-crawl`` ends up
    logging at a different level from ``ai-worker-ingest`` for a reason nobody can find.
    """
    if explicit is not None:
        return explicit.upper()
    settings = _settings_or_none()
    return settings.log_level if settings is not None else "INFO"


def logging_is_configured() -> bool:
    """Whether :func:`configure_logging` has run in this process."""
    return _configured


def configure_logging(
    level: str | None = None,
    *,
    stream: Any = None,
    force: bool = False,
) -> None:
    """Install the JSON handler on the root logger. Idempotent.

    Args:
        level: overrides ``Settings.log_level``. Used by the Celery receiver and by tests.
        stream: the handler's stream. Defaults to ``sys.stdout`` — the contract is *stdout*,
            and the Collector's ``file_log`` receiver reads the container's log file rather than
            the application blocking on an exporter (``OTEL_LOGS_EXPORTER=none``).
        force: reconfigure even if this process already did. For tests only; a second
            ``dictConfig`` in a live process replaces the root handler list, and a line emitted
            between the two is lost.

    Idempotent because it runs from three places — ``create_app()``, Celery's ``setup_logging``
    signal, and directly in a test — and any two of them may fire in one process. A second
    unguarded ``dictConfig`` would silently discard whatever a test had attached to the root
    logger, ``caplog`` included.
    """
    global _configured
    if _configured and not force:
        return

    resolved_level = _resolve_level(level)
    config: dict[str, Any] = {
        "version": 1,
        # False, not True: `logging.getLogger(__name__)` runs at import in five modules, long
        # before this call. `True` would disable every one of them and the service would go
        # silent in a way that looks exactly like the bug this module fixes.
        "disable_existing_loggers": False,
        "formatters": {
            "kb_json": {
                "()": KbJsonFormatter,
                "service": _resolve_service(),
                "env": _resolve_env(),
            }
        },
        "filters": {"kb_context": {"()": KbLogContextFilter}},
        "handlers": {
            "stdout": {
                "class": "logging.StreamHandler",
                "stream": stream if stream is not None else sys.stdout,
                "formatter": "kb_json",
                "filters": ["kb_context"],
            }
        },
        "root": {"level": resolved_level, "handlers": ["stdout"]},
        "loggers": {
            # Handlers stripped, propagation restored: one handler, one formatter, one line.
            **{name: {"handlers": [], "propagate": True} for name in _HIJACKED_LOGGERS},
            **{name: {"level": "INFO", "propagate": True} for name in _NOISY_LOGGERS},
        },
    }
    logging.config.dictConfig(config)
    # `celery.task` is given `propagate = 0` (an int, not a bool) by Celery's own
    # `setup_task_loggers`. dictConfig above sets it back to True, but only when this runs
    # after Celery — which the `setup_logging` receiver guarantees and a direct call does not.
    logging.getLogger("celery.task").propagate = True
    _configured = True


def reset_logging_for_tests() -> None:
    """Forget that this process configured logging. **Tests only.**

    Exists so a test can assert the pre-change behaviour — the whole point of the mutation proof
    is that a test which passes against an unconfigured tree is worthless.
    """
    global _configured
    _configured = False


# ─────────────────────────────────────────────────────────────────────────────
# Celery
# ─────────────────────────────────────────────────────────────────────────────


def install_celery_logging() -> None:
    """Connect the ``setup_logging`` signal so Celery configures nothing itself.

    ``celery.app.log.Logging.setup_logging_subsystem`` sends ``setup_logging`` and then does
    ``if not receivers:`` around **all** of its own work — clearing ``root.handlers``, clearing
    the ``celery`` / ``celery.task`` / ``celery.redirected`` handlers, installing its
    ``ColorFormatter``, and setting ``celery.task.propagate = 0``. Connecting one receiver
    therefore disables the hijack outright, which is both the documented mechanism and the only
    one that survives ``worker_hijack_root_logger`` defaulting to ``True``.

    Called at import of ``app.worker``, which is what ``celery -A app.worker`` loads first — well
    before the worker or the beat process reaches ``app.log.setup``. Connecting it later is
    connecting it after the hijack.

    ``weak=False`` matters: the receiver is a module-level function, but Celery's signal keeps
    weak references by default and a receiver that is garbage collected is a hijack that
    silently comes back.
    """
    from celery import signals

    signals.setup_logging.connect(_celery_setup_logging, weak=False)


def _celery_setup_logging(**kwargs: Any) -> None:
    """The ``setup_logging`` receiver. Deliberately ignores every argument Celery sends.

    Celery passes ``loglevel``, ``logfile``, ``format`` and ``colorize``. All four are declined:
    the level comes from ``KB_LOG_LEVEL`` (see :func:`_resolve_level`), the destination is
    stdout because a rotating file inside a container is written to a layer nobody reads, the
    format is this module's JSON, and colour codes in a log store are noise a query has to strip.

    ``**kwargs`` rather than the named parameters so that a Celery release adding a fifth
    argument does not turn worker startup into a ``TypeError`` inside a signal dispatch.
    """
    configure_logging()
