"""The service's logging configuration, as tests that fail against the tree that had none.

EVERY TEST HERE IS WRITTEN TO FAIL AGAINST "NO LOGGING CONFIGURED", WHICH WAS THE STATE
=======================================================================================
That is the trap this suite exists to avoid. Before ``app/observability/logging.py`` the root
logger sat at its default ``WARNING`` with an empty handler list, so:

* a test that logs at WARNING and asserts "something appeared on stderr" is **green against no
  configuration at all** — ``logging.lastResort`` emits it;
* a test that only checks a formatter in isolation never notices that nothing installs it;
* a test that asserts ``Settings.log_level`` has a valid value is green while nothing reads it.

So the assertions below are all of the form "this line, at this level, through the ROOT logger,
arrived at OUR handler in OUR shape" — which is false unless something configured logging, and
false unless the thing that configured it is wired into the process entry point.

Fixtures are local to this module on purpose: ``tests/conftest.py`` is shared with every other
tier and a global logging fixture there would reconfigure logging for suites that are asserting
something else.
"""

from __future__ import annotations

import io
import json
import logging
import pathlib
from typing import TYPE_CHECKING, Any

import pytest

if TYPE_CHECKING:  # pragma: no cover - typing only
    from collections.abc import Iterator

# The composite never-log fixture from `kb-observability-conventions`' definition of done:
# "a log fixture containing a known API key, password, prompt and retrieved chunk asserts none
# reach any sink". Distinctive strings so a grep over the emitted bytes is unambiguous.
KNOWN_API_KEY = "sk-proj-CANARYCANARYCANARYCANARY"
KNOWN_PASSWORD = "hunter2-CANARYPASSWORD"
KNOWN_SIGNATURE = "KB1 k1:CANARYSIGNATUREVALUE0123456789"
KNOWN_PROMPT = "You are a helpful assistant. CANARYPROMPT context follows."
KNOWN_CHUNK = "CANARYCHUNK the acquisition closed on 12 March for 4.2bn"
KNOWN_QUESTION = "CANARYQUESTION who signed the master services agreement"


@pytest.fixture(autouse=True)
def _restore_logging() -> Iterator[None]:
    """Snapshot and restore the root logger, so these tests cannot leak into the rest of the run.

    ``dictConfig`` replaces the root handler list outright. Without this, one test here would
    strip whatever pytest or another module had attached and the damage would surface as an
    unrelated failure three files later.
    """
    from app.observability import logging as kb_logging

    root = logging.getLogger()
    saved_handlers = list(root.handlers)
    saved_level = root.level
    saved_configured = kb_logging.logging_is_configured()
    saved_named = {
        name: (logger.handlers[:], logger.level, logger.propagate)
        for name, logger in logging.root.manager.loggerDict.items()
        if isinstance(logger, logging.Logger)
    }
    try:
        yield
    finally:
        root.handlers = saved_handlers
        root.setLevel(saved_level)
        for name, (handlers, level, propagate) in saved_named.items():
            logger = logging.getLogger(name)
            logger.handlers = handlers
            logger.setLevel(level)
            logger.propagate = propagate
        if not saved_configured:
            kb_logging.reset_logging_for_tests()


@pytest.fixture
def sink() -> io.StringIO:
    """The stream the handler writes to, standing in for the container's stdout."""
    return io.StringIO()


def _lines(sink: io.StringIO) -> list[dict[str, Any]]:
    """Every emitted line, parsed. Fails loudly if a line is not one JSON object."""
    out = []
    for raw in sink.getvalue().splitlines():
        if not raw.strip():
            continue
        out.append(json.loads(raw))
    return out


def _configure(sink: io.StringIO, level: str | None = None) -> None:
    from app.observability.logging import configure_logging

    configure_logging(level, stream=sink, force=True)


# ─────────────────────────────────────────────────────────────────────────────
# The wiring. These are the tests that fail against an unconfigured tree.
# ─────────────────────────────────────────────────────────────────────────────


def test_create_app_configures_logging_for_the_api_process() -> None:
    """``ai-api``'s entry point must leave the ROOT logger holding our JSON handler.

    Against the pre-change tree this fails on the first assertion: ``root.handlers`` is ``[]``,
    which is exactly why every ``logger.info`` in the service was discarded.
    """
    from app.main import create_app
    from app.observability.logging import KbJsonFormatter, reset_logging_for_tests

    reset_logging_for_tests()
    logging.getLogger().handlers = []

    create_app()

    root = logging.getLogger()
    assert root.handlers, "create_app() left the root logger with no handler"
    assert any(isinstance(h.formatter, KbJsonFormatter) for h in root.handlers), (
        "root logger has handlers but none of them renders the KB JSON line shape"
    )


def test_an_info_line_reaches_the_handler_as_one_json_object(sink: io.StringIO) -> None:
    """INFO is below the unconfigured root's default WARNING, so this line did not exist before."""
    _configure(sink, "INFO")

    logging.getLogger("app.retrieval.search").info("retrieval finished")

    lines = _lines(sink)
    assert len(lines) == 1
    assert lines[0]["message"] == "retrieval finished"
    assert lines[0]["logger"] == "app.retrieval.search"


def test_every_required_field_is_present_on_every_line(sink: io.StringIO) -> None:
    """The eight required names from ``logs-health-audit.md``, present even when null.

    ``request_id`` and ``operation`` render as ``null`` until ``app/api/deps.py`` binds them.
    Null is the honest value and the key is still there: a query that groups on ``operation``
    keeps working, and the gap is visible rather than implied by absence.
    """
    _configure(sink, "INFO")

    logging.getLogger("app.demo").info("a line")

    required = {
        "timestamp",
        "severity",
        "service",
        "env",
        "trace_id",
        "span_id",
        "request_id",
        "operation",
    }
    assert required <= set(_lines(sink)[0])


def test_the_timestamp_is_rfc3339_utc(sink: io.StringIO) -> None:
    from datetime import UTC, datetime

    _configure(sink, "INFO")
    logging.getLogger("app.demo").info("a line")

    stamp = _lines(sink)[0]["timestamp"]
    assert stamp.endswith("Z"), stamp
    parsed = datetime.fromisoformat(stamp.replace("Z", "+00:00"))
    assert parsed.tzinfo is not None
    assert parsed.utcoffset() == datetime.now(UTC).utcoffset()


def test_severity_is_the_field_name_and_carries_the_level(sink: io.StringIO) -> None:
    """``severity``, not ``level``. The Collector's ``severity_parser`` reads this key."""
    _configure(sink, "DEBUG")
    log = logging.getLogger("app.demo")
    log.debug("d")
    log.info("i")
    log.warning("w")
    log.error("e")

    assert [line["severity"] for line in _lines(sink)] == ["DEBUG", "INFO", "WARNING", "ERROR"]
    assert all("level" not in line for line in _lines(sink))


# ─────────────────────────────────────────────────────────────────────────────
# Settings.log_level must be load-bearing
# ─────────────────────────────────────────────────────────────────────────────


@pytest.mark.parametrize(
    ("configured", "emitted", "suppressed"),
    [
        ("DEBUG", ["DEBUG", "INFO", "WARNING", "ERROR"], []),
        ("INFO", ["INFO", "WARNING", "ERROR"], ["DEBUG"]),
        ("WARNING", ["WARNING", "ERROR"], ["DEBUG", "INFO"]),
        ("ERROR", ["ERROR"], ["DEBUG", "INFO", "WARNING"]),
    ],
)
def test_kb_log_level_changes_what_is_emitted(
    sink: io.StringIO,
    monkeypatch: pytest.MonkeyPatch,
    configured: str,
    emitted: list[str],
    suppressed: list[str],
) -> None:
    """``KB_LOG_LEVEL`` -> ``Settings.log_level`` -> the root level, with nothing in between.

    Deliberately goes through ``get_settings()`` rather than passing the level as an argument:
    the finding was that ``Settings.log_level`` was declared and read by nobody, and a test that
    passes the level directly would still pass with the field unread.
    """
    from app.core.config import get_settings
    from app.observability.logging import configure_logging

    monkeypatch.setenv("KB_LOG_LEVEL", configured)
    get_settings.cache_clear()
    try:
        configure_logging(stream=sink, force=True)

        log = logging.getLogger("app.demo")
        log.debug("DEBUG")
        log.info("INFO")
        log.warning("WARNING")
        log.error("ERROR")

        seen = [line["message"] for line in _lines(sink)]
        assert seen == emitted
        assert not set(suppressed) & set(seen)
    finally:
        get_settings.cache_clear()


# ─────────────────────────────────────────────────────────────────────────────
# Trace correlation
# ─────────────────────────────────────────────────────────────────────────────


def test_trace_and_span_ids_render_inside_a_span(sink: io.StringIO) -> None:
    """``otel.py``'s ``set_logging_format=False`` comment, as an assertion.

    A tracer provider is installed here rather than relying on the process's: the point is that
    a log line emitted inside a recording span carries the ids a Grafana Loki-to-Tempo link
    needs, under the names the catalogue specifies.
    """
    from opentelemetry import trace
    from opentelemetry.sdk.trace import TracerProvider

    provider = TracerProvider()
    tracer = provider.get_tracer("test")

    _configure(sink, "INFO")
    log = logging.getLogger("app.demo")

    log.info("outside")
    with tracer.start_as_current_span("kb.chat.stream") as span:
        log.info("inside")
        expected = span.get_span_context()

    outside, inside = _lines(sink)
    assert outside["trace_id"] is None
    assert outside["span_id"] is None
    assert inside["trace_id"] == trace.format_trace_id(expected.trace_id)
    assert inside["span_id"] == trace.format_span_id(expected.span_id)
    assert len(inside["trace_id"]) == 32
    assert len(inside["span_id"]) == 16


def test_logging_instrumentor_record_attributes_are_honoured_as_a_fallback(
    sink: io.StringIO,
) -> None:
    """``LoggingInstrumentor`` injects ``otelTraceID``/``otelSpanID``; we read them if valid."""
    _configure(sink, "INFO")

    logging.getLogger("app.demo").info(
        "from an instrumented record",
        extra={"otelTraceID": "b" * 32, "otelSpanID": "c" * 16},
    )

    line = _lines(sink)[0]
    assert line["trace_id"] == "b" * 32
    assert line["span_id"] == "c" * 16
    # The injected attribute names themselves never appear: the catalogue's names are the
    # catalogue's names, and a Loki query cannot match two spellings of one field.
    assert "otelTraceID" not in line
    assert "dropped_fields" not in line


def test_the_instrumentors_no_span_sentinel_does_not_become_a_dead_link(
    sink: io.StringIO,
) -> None:
    """``LoggingInstrumentor`` writes ``"0"`` when no span is active. That is not a trace id."""
    _configure(sink, "INFO")

    logging.getLogger("app.demo").info(
        "no span", extra={"otelTraceID": "0" * 32, "otelSpanID": "0" * 16}
    )

    line = _lines(sink)[0]
    assert line["trace_id"] is None
    assert line["span_id"] is None


# ─────────────────────────────────────────────────────────────────────────────
# The field allow-list — the DEFENCE
# ─────────────────────────────────────────────────────────────────────────────


@pytest.mark.security
@pytest.mark.parametrize(
    "field",
    [
        "prompt",
        "packed_context",
        "chunk_text",
        "query",
        "question",
        "completion",
        "messages",
        "provider_credential",
        "authorization",
        "x_kb_signature",
        "api_key",
        "password",
        "conversation_id",
        "message_id",
        "source_id",
        "chunk_id",
        "user_id",
        "url",
    ],
)
def test_a_field_outside_the_allow_list_is_dropped(sink: io.StringIO, field: str) -> None:
    """The value never reaches the line; only the NAME is reported back to the author."""
    _configure(sink, "INFO")

    logging.getLogger("app.demo").info("a line", extra={field: "CANARYVALUE"})

    line = _lines(sink)[0]
    assert field not in line
    assert "CANARYVALUE" not in json.dumps(line)
    assert line["dropped_fields"] == [field]


def test_filename_and_module_cannot_be_smuggled_in_as_extras(sink: io.StringIO) -> None:
    """``filename`` is not in the allow-list, and it never gets that far.

    ``Logger.makeRecord`` raises ``KeyError`` for any ``extra`` key that would overwrite a
    standard ``LogRecord`` attribute. Recorded as a test because it is the reason those names
    are absent from the parametrized drop list above rather than an oversight — and because
    ``filename`` is one of the names a well-meaning author reaches for when logging an upload.
    """
    _configure(sink, "INFO")

    with pytest.raises(KeyError):
        logging.getLogger("app.demo").info("upload", extra={"filename": "contract.pdf"})

    assert sink.getvalue() == ""


def test_allowed_extra_fields_survive(sink: io.StringIO) -> None:
    _configure(sink, "INFO")

    logging.getLogger("app.demo").info(
        "provider call finished",
        extra={
            "org_id": "org_01JABC",
            "bot_id": "bot_01JABC",
            "job_id": "job_01JABC",
            "error_class": "provider_temporary",
            "duration_ms": 1234,
            "provider": "anthropic",
            "outcome": "error",
        },
    )

    line = _lines(sink)[0]
    assert line["org_id"] == "org_01JABC"
    assert line["bot_id"] == "bot_01JABC"
    assert line["job_id"] == "job_01JABC"
    assert line["error_class"] == "provider_temporary"
    assert line["duration_ms"] == 1234
    assert line["outcome"] == "error"
    assert "dropped_fields" not in line


def test_the_allow_list_admits_no_banned_label_or_content_field() -> None:
    """A static assertion on the constant, so an addition has to be argued for in review."""
    from app.observability.logging import ALLOWED_EXTRA_FIELDS

    banned = {
        "user_id",
        "conversation_id",
        "message_id",
        "source_id",
        "chunk_id",
        "url",
        "query",
        "question",
        "prompt",
        "packed_context",
        "completion",
        "text",
        "content",
        "messages",
        "provider_credential",
        "authorization",
        "password",
        "api_key",
        "filename",
    }
    assert not (banned & ALLOWED_EXTRA_FIELDS)


def test_the_catalogues_when_applicable_names_are_all_admitted() -> None:
    """The five *When applicable* fields from ``logs-health-audit.md``, exactly as spelled.

    The pairing with the test above: one asserts nothing forbidden got in, this asserts nothing
    required got left out. A field the catalogue promises but the allow-list drops is a Loki
    query that returns rows with the column permanently missing.
    """
    from app.observability.logging import ALLOWED_EXTRA_FIELDS

    assert {"org_id", "bot_id", "job_id", "error_class", "duration_ms"} <= ALLOWED_EXTRA_FIELDS


def test_an_unserializable_value_is_rendered_by_type_name_never_by_str(
    sink: io.StringIO,
) -> None:
    """``str(obj)`` is an open channel from any library's ``__str__`` into the log store."""

    class Credential:
        def __str__(self) -> str:  # pragma: no cover - must never be called
            return KNOWN_API_KEY

        __repr__ = __str__

    _configure(sink, "INFO")
    logging.getLogger("app.demo").info("built a client", extra={"provider": Credential()})

    line = _lines(sink)[0]
    assert line["provider"] == "<Credential>"
    assert KNOWN_API_KEY not in json.dumps(line)


# ─────────────────────────────────────────────────────────────────────────────
# Redaction — the BACKSTOP
# ─────────────────────────────────────────────────────────────────────────────


@pytest.mark.security
@pytest.mark.parametrize(
    ("raw", "must_not_contain"),
    [
        ("Authorization: Bearer abcdefghijklmnopqrstuv", "abcdefghijklmnopqrstuv"),
        (f"key={KNOWN_API_KEY}", KNOWN_API_KEY),
        (f"calling with {KNOWN_API_KEY}", KNOWN_API_KEY),
        (f"X-KB-Signature: {KNOWN_SIGNATURE}", "CANARYSIGNATUREVALUE0123456789"),
        (f"password={KNOWN_PASSWORD}", KNOWN_PASSWORD),
        ("token: nvapi-AAAAAAAAAAAAAAAAAAAA", "nvapi-AAAAAAAAAAAAAAAAAAAA"),
        ("aws key AKIAIOSFODNN7EXAMPLE rotated", "AKIAIOSFODNN7EXAMPLE"),
        (
            "GET https://api.example.com/search?q=CANARYQUESTION+who+signed",
            "CANARYQUESTION",
        ),
    ],
)
def test_credential_shapes_in_the_message_are_redacted(
    sink: io.StringIO, raw: str, must_not_contain: str
) -> None:
    _configure(sink, "INFO")

    logging.getLogger("app.demo").info(raw)

    emitted = sink.getvalue()
    assert must_not_contain not in emitted, emitted
    assert "[REDACTED]" in emitted


@pytest.mark.security
def test_the_composite_never_log_fixture_reaches_no_sink(sink: io.StringIO) -> None:
    """The definition-of-done fixture: an API key, a password, a prompt and a retrieved chunk.

    Note what this asserts and what it cannot. The key, the password and the signature are
    caught because they have a *shape*. The prompt and the chunk are caught because they were
    passed as FIELDS and the allow-list refused them — not because anything recognised them as
    prose. Interpolated into the message string they would survive, which is what
    ``REDACTION_LIMITS`` says and why the allow-list is the defence.
    """
    _configure(sink, "DEBUG")

    logging.getLogger("app.demo").debug(
        f"provider call: key={KNOWN_API_KEY} password={KNOWN_PASSWORD} sig={KNOWN_SIGNATURE}",
        extra={
            "prompt": KNOWN_PROMPT,
            "packed_context": KNOWN_CHUNK,
            "query": KNOWN_QUESTION,
            "provider_credential": KNOWN_API_KEY,
        },
    )

    emitted = sink.getvalue()
    for canary in (
        KNOWN_API_KEY,
        KNOWN_PASSWORD,
        "CANARYSIGNATUREVALUE",
        "CANARYPROMPT",
        "CANARYCHUNK",
        "CANARYQUESTION",
    ):
        assert canary not in emitted, f"{canary} reached the log line:\n{emitted}"
    assert json.loads(emitted)["dropped_fields"] == [
        "packed_context",
        "prompt",
        "provider_credential",
        "query",
    ]


@pytest.mark.security
def test_a_credential_inside_a_traceback_is_redacted(sink: io.StringIO) -> None:
    _configure(sink, "INFO")

    try:
        raise ValueError(f"upstream rejected api_key={KNOWN_API_KEY}")
    except ValueError:
        logging.getLogger("app.demo").exception("unhandled exception")

    line = _lines(sink)[0]
    assert KNOWN_API_KEY not in sink.getvalue()
    assert "[REDACTED]" in line["exception"]
    assert "ValueError" in line["exception"]


def test_redaction_limits_are_documented_rather_than_implied() -> None:
    """A redactor that gives false confidence is worse than none, so the gap is a constant."""
    from app.observability.logging import REDACTION_LIMITS

    assert "does NOT catch" in REDACTION_LIMITS


# ─────────────────────────────────────────────────────────────────────────────
# Redaction reaches NESTED values, the way the PHP twin's `normalize` does
# ─────────────────────────────────────────────────────────────────────────────
#
# The formatter used to apply `redact` to top-level strings only, so a `dict` or `list` under
# an allowed name went to `json.dumps` untouched — no recursion, no cap. The same string WAS
# redacted at top level, which is what made it a gap rather than a documented limit, and
# `services/core-api/app/Logging/KbJsonFormatter.php` recursed to `MAX_DEPTH = 4` on the other
# plane the whole time. `LogFieldContractTest.php` pinned field-name sets and severity spelling
# and nothing about treatment, so "two runtimes, one vocabulary" was true and "two runtimes,
# one treatment" was not.
#
# The live shape is `app/main.py:_handle_validation_error`, whose `errors` extra is a
# `dict[str, list[str]]` — nested exactly deep enough that the redaction firing on a bare
# string does not fire on the same string one level down.

NESTED_CANARY = "Authorization: Bearer sk-live-CANARYNESTEDTOKEN0123"

#: The other plane's copy of this formatter. Spelled the same way
#: ``tests/unit/test_log_severity_mapping.py`` spells it, and for the same reason: the depth cap
#: is a shared constant, so it is read out of the PHP source rather than transcribed here.
PHP_FORMATTER = (
    pathlib.Path(__file__).resolve().parents[3]
    / "core-api"
    / "app"
    / "Logging"
    / "KbJsonFormatter.php"
)


@pytest.mark.security
def test_a_credential_nested_under_an_allowed_field_is_redacted(sink: io.StringIO) -> None:
    """The exact shape `app/main.py:174` logs: ``{"errors": {"<field>": ["<message>"]}}``."""
    _configure(sink, "INFO")

    logging.getLogger("app.demo").warning(
        "validation rejected",
        extra={"errors": {"body.credentials": [NESTED_CANARY], "body.model": ["is required"]}},
    )

    emitted = sink.getvalue()
    assert "CANARYNESTEDTOKEN0123" not in emitted, emitted
    line = _lines(sink)[0]
    # Still a map of field path -> list of messages. A fix that stringified the whole value
    # would also pass the assertion above and would break every consumer of the field.
    assert line["errors"]["body.credentials"] == ["Authorization: Bearer [REDACTED]"]
    assert line["errors"]["body.model"] == ["is required"]


@pytest.mark.security
def test_the_same_string_at_the_top_level_was_never_the_gap(sink: io.StringIO) -> None:
    """The positive control that makes the test above a *gap* rather than a limit.

    If this one failed too, the finding would be "redaction does not catch this shape" and the
    fix would belong in `redact`. It passes, and passed before, which localises the defect to
    the traversal.
    """
    _configure(sink, "INFO")

    logging.getLogger("app.demo").warning("validation rejected", extra={"reason": NESTED_CANARY})

    assert "CANARYNESTEDTOKEN0123" not in sink.getvalue()


@pytest.mark.security
def test_redaction_survives_every_level_the_depth_cap_admits(sink: io.StringIO) -> None:
    """A list inside a map inside a list, which is past what any hand-rolled one-level fix
    reaches and inside the cap both planes share."""
    _configure(sink, "INFO")

    logging.getLogger("app.demo").warning(
        "validation rejected",
        extra={"errors": {"body": [{"nested": [NESTED_CANARY]}]}},
    )

    assert "CANARYNESTEDTOKEN0123" not in sink.getvalue()


def test_a_structure_past_the_depth_cap_is_named_rather_than_rendered(sink: io.StringIO) -> None:
    """The cap is a bound on recursion as much as on output.

    An ``extra=`` value is caller-supplied and may be self-referential; a formatter that
    recursed forever would raise inside a handler, which logging swallows into
    ``--- Logging error ---`` on stderr, losing the line. Truncation names the container type
    — ``_json_default``'s convention — instead of PHP's single ``<array>``, because PHP has one
    container type and this side has two.
    """
    _configure(sink, "INFO")

    logging.getLogger("app.demo").warning(
        "validation rejected",
        extra={"errors": {"a": {"b": {"c": {"d": {"e": NESTED_CANARY}}}}}},
    )

    emitted = sink.getvalue()
    assert "CANARYNESTEDTOKEN0123" not in emitted, emitted
    line = _lines(sink)[0]
    # The allowed value itself is depth 0, so four levels are walked and the fifth is named —
    # the same structure truncated at the same place as `normalize($value, 0)` on the PHP side.
    assert line["errors"]["a"]["b"]["c"]["d"] == "<dict>"


def test_the_depth_cap_matches_the_php_twins_constant() -> None:
    """Read from the PHP source rather than restated, so the two cannot drift apart silently.

    The count assertion is what stops the regex going vacuous: an unmatched pattern yields no
    number, and no number compares equal to nothing.
    """
    import re

    from app.observability.logging import _MAX_EXTRA_DEPTH

    php = PHP_FORMATTER.read_text(encoding="utf-8")
    found = re.findall(r"private const MAX_DEPTH = (\d+);", php)
    assert len(found) == 1, f"expected exactly one MAX_DEPTH in {PHP_FORMATTER}, found {found}"
    assert int(found[0]) == _MAX_EXTRA_DEPTH


def test_a_non_finite_float_does_not_cost_the_whole_line(sink: io.StringIO) -> None:
    """``json.dumps`` emits bare ``NaN``/``Infinity``, which parse in Python and in nothing
    else — the Collector's JSON parser drops the line, so one field takes the entry with it.
    PHP's ``json_encode`` fails outright on the same value, which is why its ``normalize``
    stringifies it and why this side now does too."""
    _configure(sink, "INFO")

    logging.getLogger("app.demo").info(
        "batch finished", extra={"duration_ms": float("inf"), "count": 3}
    )

    raw = sink.getvalue()

    def _reject(constant: str) -> None:
        raise AssertionError(f"the line carries a bare JSON `{constant}` literal:\n{raw}")

    # `parse_constant` fires on exactly `NaN`, `Infinity` and `-Infinity`, which is what every
    # parser but Python's own refuses.
    line = json.loads(raw, parse_constant=_reject)
    assert line["duration_ms"] == "inf"
    assert line["count"] == 3


# ─────────────────────────────────────────────────────────────────────────────
# Request context
# ─────────────────────────────────────────────────────────────────────────────


def test_bound_context_appears_on_every_line_in_scope(sink: io.StringIO) -> None:
    from app.observability.logging import bind_log_context

    _configure(sink, "INFO")
    log = logging.getLogger("app.demo")

    log.info("before")
    with bind_log_context(request_id="req_01J", operation="chat.stream", org_id="org_01J"):
        log.info("during")
    log.info("after")

    before, during, after = _lines(sink)
    assert before["request_id"] is None
    assert during["request_id"] == "req_01J"
    assert during["operation"] == "chat.stream"
    assert during["org_id"] == "org_01J"
    assert after["request_id"] is None, "context leaked past its scope"


def test_context_is_reset_even_when_the_scope_raises(sink: io.StringIO) -> None:
    """A leaked binding hands request A's org id to whatever runs next on this task."""
    from app.observability.logging import bind_log_context, current_log_context

    _configure(sink, "INFO")

    with pytest.raises(RuntimeError), bind_log_context(request_id="req_01J", org_id="org_01J"):
        raise RuntimeError("boom")

    assert current_log_context() == dict.fromkeys(
        ["request_id", "operation", "org_id", "bot_id", "job_id"]
    )


def test_an_explicit_extra_outranks_the_ambient_binding(sink: io.StringIO) -> None:
    """A maintenance task iterating several orgs names the one it is working on."""
    from app.observability.logging import bind_log_context

    _configure(sink, "INFO")
    with bind_log_context(org_id="org_AMBIENT"):
        logging.getLogger("app.demo").info("purging", extra={"org_id": "org_EXPLICIT"})

    assert _lines(sink)[0]["org_id"] == "org_EXPLICIT"


def test_bind_log_context_refuses_an_unknown_field() -> None:
    """A typo'd ``requestId`` that binds nothing makes every line claim it had no request id."""
    from app.observability.logging import bind_log_context

    # The raise happens on entry, so the body never runs; `pytest.raises` is the assertion.
    with (
        pytest.raises(ValueError, match="unknown log context field"),
        bind_log_context(requestId="req_01J"),
    ):
        pass


# ─────────────────────────────────────────────────────────────────────────────
# The other four processes: Celery, and uvicorn's pre-configured loggers
# ─────────────────────────────────────────────────────────────────────────────


def test_importing_the_celery_app_connects_the_setup_logging_receiver() -> None:
    """Without a receiver, ``worker_hijack_root_logger`` clears root and installs Celery's own.

    Asserted on ``app.worker`` rather than on ``install_celery_logging`` directly: the failure
    this guards against is not "the function is wrong", it is "nothing calls it before Celery
    reaches ``app.log.setup``".
    """
    from celery import signals

    import app.worker  # noqa: F401 - imported for its signal-connecting side effect

    assert signals.setup_logging.receivers, (
        "no setup_logging receiver: Celery will hijack the root logger in every ai-worker-* "
        "container and in ai-beat"
    )


def test_celery_does_not_hijack_the_root_logger(sink: io.StringIO) -> None:
    """Drive Celery's own logging setup and assert OUR handler is what survives it.

    ``setup_logging_subsystem`` is the exact call every ``celery worker`` and ``celery beat``
    process makes. With our receiver connected it must return the receiver list and leave the
    root logger alone; without one it clears ``root.handlers`` and installs a ``ColorFormatter``.
    """
    from app.observability import logging as kb_logging
    from app.worker import celery_app

    _configure(sink, "INFO")
    ours = logging.getLogger().handlers[:]

    celery_app.log.__class__._setup = False  # the "already configured" flag, per process
    try:
        receivers = celery_app.log.setup_logging_subsystem(loglevel=logging.WARNING)
    finally:
        celery_app.log.__class__._setup = False

    assert receivers, "Celery found no setup_logging receiver and configured logging itself"
    root = logging.getLogger()
    assert root.handlers, "Celery cleared the root handlers"
    assert all(isinstance(h.formatter, kb_logging.KbJsonFormatter) for h in root.handlers)
    assert [type(h) for h in root.handlers] == [type(h) for h in ours]


def test_a_celery_task_logger_line_lands_in_our_formatter(sink: io.StringIO) -> None:
    """``get_task_logger`` parents its logger on ``celery.task``, which Celery sets
    ``propagate = 0`` on — an int, not a bool — in ``setup_task_loggers``.

    That is the one Celery-specific way a task's own log lines go missing: the task logger is
    configured, the root logger is configured, and the link between them is severed. Asserting
    the line arrives is asserting the link is intact.
    """
    from celery.utils.log import get_task_logger

    _configure(sink, "INFO")
    task_logger = get_task_logger("kb.ingest.parse")

    task_logger.info("stage finished", extra={"stage": "parse"})

    assert task_logger.parent is not None
    assert task_logger.parent.name == "celery.task"
    assert logging.getLogger("celery.task").propagate is True
    line = _lines(sink)[0]
    assert line["message"] == "stage finished"
    assert line["stage"] == "parse"
    assert line["logger"] == "kb.ingest.parse"


def test_uvicorns_own_loggers_neither_double_emit_nor_bypass_the_formatter(
    sink: io.StringIO,
) -> None:
    """Uvicorn gives these their own handlers with ``propagate=False`` before we ever load.

    Simulated exactly: attach a second handler and switch propagation off, then configure. One
    line must arrive, in our shape — not two, and not one in uvicorn's plain format.
    """
    other = io.StringIO()
    for name in ("uvicorn", "uvicorn.error", "uvicorn.access"):
        logger = logging.getLogger(name)
        logger.handlers = [logging.StreamHandler(other)]
        logger.propagate = False

    _configure(sink, "INFO")

    logging.getLogger("uvicorn.access").info('%s - "%s %s" %d', "127.0.0.1", "GET", "/x", 200)
    logging.getLogger("uvicorn.error").info("Application startup complete.")

    assert other.getvalue() == "", "a uvicorn handler survived and is emitting a second copy"
    lines = _lines(sink)
    assert [line["logger"] for line in lines] == ["uvicorn.access", "uvicorn.error"]
    assert lines[0]["message"] == '127.0.0.1 - "GET /x" 200'


def test_configure_logging_is_idempotent(sink: io.StringIO) -> None:
    """It runs from three places and any two of them may fire in one process."""
    from app.observability.logging import configure_logging

    _configure(sink, "INFO")
    before = len(logging.getLogger().handlers)

    configure_logging(stream=io.StringIO())

    assert len(logging.getLogger().handlers) == before
    logging.getLogger("app.demo").info("still going to the first stream")
    assert len(_lines(sink)) == 1
