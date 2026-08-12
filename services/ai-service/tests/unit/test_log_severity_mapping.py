"""``severity`` must be a string the Collector's ``severity_parser`` actually understands.

The parser accepts, case-insensitively, exactly ``trace|debug|info|warn|error|fatal`` each with
an optional ``2``/``3``/``4`` suffix, plus the alias ``warning``. Everything else lands on
``SeverityNumber Unspecified(0)`` and reaches Loki with **no ``level`` stream label at all** — so
the lines are in the store and every "errors in the last hour" query and every level-faceted
panel reads zero.

Python's ``CRITICAL`` was in the "everything else" bucket. ``logging.critical`` and
``logger.fatal`` are its only producers, which means the level the platform reserves for the
worst thing that can happen was the one level with no label.

The mapping is the same table the PHP formatter carries, and the last test in this file is what
stops the two from drifting: two independent tables for one contract is exactly how one runtime
comes to call something ``ERROR2`` that the other calls ``CRITICAL``.
"""

from __future__ import annotations

import json
import logging
import re
from io import StringIO
from pathlib import Path
from typing import Any

import pytest

from app.observability.logging import SEVERITY, KbJsonFormatter, configure_logging

#: The parser's grammar, from the measurement recorded in
#: `services/core-api/app/Logging/KbJsonFormatter.php`.
_PARSEABLE = re.compile(r"(?i)\A(trace|debug|info|warn|warning|error|fatal)[234]?\Z")

_PHP_FORMATTER = (
    Path(__file__).resolve().parents[3] / "core-api" / "app" / "Logging" / "KbJsonFormatter.php"
)


def _rendered(level: int, level_name: str | None = None) -> dict[str, Any]:
    """One log line through the real formatter, as the dict the Collector will parse."""
    if level_name is not None:
        logging.addLevelName(level, level_name)
    record = logging.LogRecord(
        name="app.test",
        level=level,
        pathname=__file__,
        lineno=1,
        msg="probe",
        args=(),
        exc_info=None,
    )
    return json.loads(KbJsonFormatter(service="ai-api", env="ci").format(record))


@pytest.mark.parametrize(
    ("level", "expected"),
    [
        (logging.DEBUG, "DEBUG"),
        (logging.INFO, "INFO"),
        (logging.WARNING, "WARNING"),
        (logging.ERROR, "ERROR"),
        (logging.CRITICAL, "ERROR2"),
    ],
)
def test_every_stdlib_level_renders_a_severity_the_parser_accepts(
    level: int, expected: str
) -> None:
    assert _rendered(level)["severity"] == expected


def test_critical_is_the_one_that_was_broken() -> None:
    """Stated on its own, because the parametrized case above reads as one of five.

    ``CRITICAL`` verbatim is not in the parser's grammar. It was the only stdlib level that was
    not, and it is the level that matters most.
    """
    assert _rendered(logging.CRITICAL)["severity"] != "CRITICAL"
    assert _PARSEABLE.match(_rendered(logging.CRITICAL)["severity"])
    assert not _PARSEABLE.match("CRITICAL"), (
        "this test's premise is gone: the Collector grammar now accepts CRITICAL and the "
        "mapping may be unnecessary"
    )


def test_every_value_in_the_table_is_parseable() -> None:
    for name, value in SEVERITY.items():
        assert _PARSEABLE.match(value), f"{name} maps to {value!r}, which the parser rejects"


def test_the_mapping_preserves_ordering() -> None:
    """A mapping that lands two levels on one severity number is a mapping that makes
    ``severity_number >= 17`` stop meaning "an error or worse"."""
    numbers = {
        "DEBUG": 5,
        "INFO": 9,
        "INFO2": 10,
        "WARNING": 13,
        "ERROR": 17,
        "ERROR2": 18,
        "ERROR3": 19,
        "FATAL": 21,
    }
    ordered = [
        "DEBUG",
        "INFO",
        "NOTICE",
        "WARNING",
        "ERROR",
        "CRITICAL",
        "ALERT",
        "EMERGENCY",
    ]
    rendered = [numbers[SEVERITY[name]] for name in ordered]
    assert rendered == sorted(rendered)
    assert len(set(rendered)) == len(rendered), "two levels collapsed onto one severity number"


def test_an_unmapped_custom_level_passes_through_rather_than_being_coerced() -> None:
    """A level somebody registered with ``addLevelName`` is not this table's to rename.

    Silently rewriting it to ``INFO`` would be a lie nothing can detect; leaving it alone makes
    it visible in Grafana as a missing label.
    """
    assert _rendered(25, "AUDIT")["severity"] == "AUDIT"


def test_the_configured_logger_emits_the_mapped_value_end_to_end() -> None:
    """Through ``configure_logging`` and a real handler, not just the formatter in isolation."""
    stream = StringIO()
    configure_logging("DEBUG", stream=stream, force=True)
    try:
        logging.getLogger("app.test.severity").critical("the worst thing")
    finally:
        configure_logging("INFO", force=True)

    lines = [json.loads(line) for line in stream.getvalue().splitlines() if line.strip()]
    assert [line["severity"] for line in lines if line["message"] == "the worst thing"] == [
        "ERROR2"
    ]


def test_the_python_and_php_tables_are_the_same_table() -> None:
    """One contract, two runtimes, and the drift is silent.

    ``services/core-api/app/Logging/KbJsonFormatter.php`` carries ``const SEVERITY``. Parsing it
    here rather than restating it is the point: a restated copy is a third table.
    """
    source = _PHP_FORMATTER.read_text(encoding="utf-8")
    block = re.search(r"public const SEVERITY = \[(.*?)\];", source, re.DOTALL)
    assert block is not None, f"no `const SEVERITY` in {_PHP_FORMATTER}"

    php = dict(re.findall(r"'([A-Z]+)'\s*=>\s*'([A-Z0-9]+)'", block.group(1)))

    assert php == dict(SEVERITY), (
        "the Laravel and FastAPI severity tables disagree. They are one mapping of the RFC 5424 "
        "levels onto the OpenTelemetry logs data model, and a divergence means the same event "
        "carries a different level label depending on which plane emitted it."
    )
