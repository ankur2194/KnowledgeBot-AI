"""`-W error::DeprecationWarning` is a dependency gate, and it was also a parser bug.

The suite promotes `DeprecationWarning` to an exception, and three separate things depend on
that staying true: the `langchain-community < 0.4.2` ceiling is *enforced* by 0.4.2's sunset
warning failing the suite rather than merely declared in `pyproject.toml`; the testcontainers
top-level import shims warn and would otherwise be used silently; and
`app/ingestion/parsing/converter.py` relies on it to stop a deprecated `LayoutOptions` being
constructed without anyone noticing.

It also turned two upstream advisories into parse failures. The second one is the expensive
one: `docling_core`'s "ListItem parent must be a list group" fires during document *assembly*,
inside the pipeline's own `except Exception`, so the document comes back
`ConversionStatus.FAILURE` and `parse_document` raises `error_class="parsing"`,
**non-retryable**, on a document that parsed perfectly. Measured against the corpus's scanned
purchase order in `knowledgebot/ai-service:dev`: `KbError: docling returned failure for
scanned-po-88214.pdf: ListItem parent must be a list group` before, 36 elements after.

This file is the guard on the narrowing. Every assertion runs against the **ambient** filter
stack pytest built from `addopts` — no `catch_warnings`, no `simplefilter` — because what is
being tested is the configuration, not the warnings module.

WHY `warn_explicit` AND NOT `warnings.warn`
-------------------------------------------
A filter's `module` field is matched against the *dotted module name* of the frame that warned,
which `warnings.warn` reads from that frame's `__name__`. There is no way to raise a warning
that claims to come from `docling_core.types.doc.document` through `warn`, so the module is
passed explicitly. `warn_explicit` needs its own registry too: pass a fresh dict each time, or
the `default` action's once-per-location dedup makes the second assertion in a run vacuous.
"""

from __future__ import annotations

import tomllib
import warnings
from pathlib import Path
from typing import Final

import pytest

from tests.support.tree import SERVICE_ROOT

_PYPROJECT: Final[Path] = SERVICE_ROOT / "pyproject.toml"

#: The two upstream advisories that must pass, each with the module it must come from.
_EXEMPT: Final[tuple[tuple[str, str], ...]] = (
    ("deprecated", "docling.models.stages.ocr.rapid_ocr_model"),
    (
        "ListItem parent must be a list group, creating one on the fly.",
        "docling_core.types.doc.document",
    ),
)

#: Warnings that must still fail the suite. The first two are the dependency gates; the third is
#: our own code, which is the whole reason the exemptions are scoped by module at all.
_MUST_STILL_RAISE: Final[tuple[tuple[str, str], ...]] = (
    ("langchain-community is being sunset", "langchain_community"),
    ("The testcontainers.qdrant module is deprecated", "testcontainers.qdrant"),
    ("deprecated", "app.ingestion.parsing.converter"),
    ("ListItem parent must be a list group, creating one on the fly.", "app.ingestion.chunking"),
)


def _emit(message: str, module: str) -> None:
    warnings.warn_explicit(
        message,
        DeprecationWarning,
        filename=f"{module.replace('.', '/')}.py",
        lineno=1,
        module=module,
        registry={},
    )


def _addopts() -> str:
    with _PYPROJECT.open("rb") as handle:
        return tomllib.load(handle)["tool"]["pytest"]["ini_options"]["addopts"]


def test_the_strict_flag_is_still_there() -> None:
    """Deleting it is the fix nobody should take: it would restore the parser and quietly
    un-enforce the `langchain-community` ceiling, which has no other mechanism."""
    assert "-W error::DeprecationWarning" in _addopts()


def test_the_exemptions_are_command_line_filters_not_ini_filters() -> None:
    """**Precedence is the trap.** pytest applies ini `filterwarnings` first and command-line
    `-W` second, and every filter is prepended — so the last applied wins. Written as a
    `filterwarnings` list these exemptions would lose to `-W error::DeprecationWarning` and
    change nothing, which looks exactly like a fix and is not one.
    """
    options = _addopts()
    error_at = options.index("-W error::DeprecationWarning")
    for _message, module in _EXEMPT:
        assert module in options, f"{module} is not exempted at all"
        assert options.index(module) > error_at, (
            f"the {module} exemption is applied before -W error and will be overridden"
        )

    with _PYPROJECT.open("rb") as handle:
        ini = tomllib.load(handle)["tool"]["pytest"]["ini_options"]
    assert "filterwarnings" not in ini, (
        "an ini `filterwarnings` list is applied BEFORE -W and cannot exempt anything from it; "
        "put the exemption in addopts, after the -W error"
    )


@pytest.mark.parametrize(("message", "module"), _EXEMPT, ids=lambda value: value.split(".")[-1])
def test_an_exempted_upstream_advisory_does_not_fail_the_suite(message: str, module: str) -> None:
    """If either of these raises, every document that reaches it reads as a parse failure."""
    _emit(message, module)


@pytest.mark.parametrize(
    ("message", "module"), _MUST_STILL_RAISE, ids=lambda value: value.split(" ")[0]
)
def test_everything_else_still_fails_the_suite(message: str, module: str) -> None:
    """The exemptions are scoped by message **and** by module. The last two rows are the same
    two messages raised from our own package: an exemption matched on the message alone would
    let a deprecation in this repository through, which is the failure mode of narrowing."""
    with pytest.raises(DeprecationWarning):
        _emit(message, module)
