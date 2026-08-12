"""The Definition-of-done greps, as tests that fail instead of as prose nobody runs.

`pytest-ai-service` closes with a list of ``grep -rn ...`` invocations. A grep in a checklist
is a grep that runs on the day someone remembers; the four rules below each protect a suite
from going green while proving nothing, so they run every time.

Everything here is read through ``ast`` rather than by matching text, which matters more than
it sounds: a text search finds the tokens in *this file's* own prose, in every docstring that
warns against them, and in the commented-out example that the warning was written for. The
AST sees identifiers and calls — so a module can explain at length why ``ASGITransport``
buffers an SSE body without tripping the rule that bans using it.

This file excludes itself from its own scan. It is the one module that has to name the
forbidden constructs as data.
"""

from __future__ import annotations

import ast
from collections.abc import Iterator
from pathlib import Path
from typing import Final

import pytest

from tests.support.tree import TESTS_ROOT

SELF: Final[Path] = Path(__file__).resolve()


def _test_sources() -> Iterator[tuple[Path, ast.Module]]:
    for path in sorted(TESTS_ROOT.rglob("*.py")):
        if path.resolve() == SELF or "__pycache__" in path.parts:
            continue
        yield path, ast.parse(path.read_text(encoding="utf-8"), filename=str(path))


def _identifiers(tree: ast.Module) -> set[str]:
    """Every name the code actually *uses*: bare names, attribute tails, imported names.

    Deliberately excludes string constants and comments, which is what makes the
    self-documenting docstrings above legal.
    """
    names: set[str] = set()
    for node in ast.walk(tree):
        if isinstance(node, ast.Name):
            names.add(node.id)
        elif isinstance(node, ast.Attribute):
            names.add(node.attr)
        elif isinstance(node, ast.ImportFrom):
            names.update(alias.name for alias in node.names)
        elif isinstance(node, ast.Import):
            names.update(alias.name.split(".")[-1] for alias in node.names)
        elif isinstance(node, ast.keyword) and node.arg:
            names.add(node.arg)
    return names


def _function_names(tree: ast.Module) -> set[str]:
    return {
        node.name
        for node in ast.walk(tree)
        if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef))
    }


SOURCES: Final[list[tuple[Path, ast.Module]]] = list(_test_sources())


def test_the_scan_actually_found_test_modules() -> None:
    """Positive control. Every guard below is a "no file does X" assertion, and all of them
    pass trivially over an empty list — the same failure shape as an isolation test whose
    surface returned nothing."""
    assert len(SOURCES) >= 2, [str(p) for p, _ in SOURCES]


def test_no_event_loop_or_event_loop_policy_fixture_exists() -> None:
    """``event_loop`` was **removed** in pytest-asyncio 1.0.0, and overriding
    ``event_loop_policy`` is deprecated as of 1.4.0 in favour of the
    ``pytest_asyncio_loop_factories`` hook.

    Most async-pytest answers online predate both, so the override gets pasted back in
    whenever someone hits a loop error — where it does nothing, or errors on import, while
    the actual cause (the two loop-scope options defaulting differently) stays unfixed.
    """
    offenders = [
        str(path)
        for path, tree in SOURCES
        if {"event_loop", "event_loop_policy"} & _function_names(tree)
    ]
    assert not offenders, (
        f"{offenders} define a removed/deprecated pytest-asyncio fixture. Loop scoping is "
        f"configured once, in pyproject.toml, with asyncio_default_fixture_loop_scope and "
        f"asyncio_default_test_loop_scope set to the same value."
    )


@pytest.mark.parametrize("forbidden", ["ASGITransport", "TestClient"])
def test_in_process_transports_appear_only_under_contract(forbidden: str) -> None:
    """Both buffer the entire response body and cannot deliver a mid-stream disconnect.

    ``httpx.ASGITransport`` joins every ``http.response.body`` into one chunk and yields
    ``http.disconnect`` only after the response completed; ``TestClient`` writes into a
    ``BytesIO`` and does the same. A streaming test over either passes against an
    implementation that streams nothing — the Python twin of ``Http::fake()``.

    They remain legal under ``contract/``, where the subject is a JSON body: HMAC
    verification, the missing-header 400, the one error envelope, the emitted OpenAPI.
    """
    offenders = [
        str(path.relative_to(TESTS_ROOT))
        for path, tree in SOURCES
        if forbidden in _identifiers(tree) and path.parent.name != "contract"
    ]
    assert not offenders, (
        f"{forbidden} used outside tests/contract/: {offenders}. Streaming and cancellation "
        f"tests bind a real Uvicorn — see tests/support/live_server.py."
    )


def test_no_in_memory_qdrant_client_outside_the_unit_tier() -> None:
    """``QdrantClient(":memory:")`` does not propagate the root filter into prefetch
    branches — that propagation is server-side only.

    So the leaky hybrid query of `kb-tenancy-isolation` *leaks in memory and is safe in
    production*, exactly inverting what an isolation test proves. In-memory is legal for
    ``Filter`` construction and chunk-payload shape; it is never legal for a §22.5 claim.
    """
    offenders: list[str] = []
    for path, tree in SOURCES:
        if path.parent.name == "unit":
            continue
        for node in ast.walk(tree):
            if not isinstance(node, ast.Call):
                continue
            func = node.func
            name = func.attr if isinstance(func, ast.Attribute) else getattr(func, "id", None)
            if name != "QdrantClient":
                continue
            if any(isinstance(arg, ast.Constant) and arg.value == ":memory:" for arg in node.args):
                offenders.append(f"{path.relative_to(TESTS_ROOT)}:{node.lineno}")
    assert not offenders, (
        f"in-memory Qdrant outside tests/unit/: {offenders}. Isolation and retrieval "
        f"assertions run against a real container — see the qdrant_url fixture."
    )


def test_task_always_eager_appears_only_in_the_unit_tier() -> None:
    """Eager execution skips the broker entirely.

    JSON serialization of the payload never runs, ``acks_late`` /
    ``reject_on_worker_lost`` / redelivery never happen, ``request.delivery_info`` is empty,
    and soft and hard time limits do not fire — they need SIGUSR1 and the prefork pool. So
    an eager test may assert stage logic and lifecycle transitions, and may never be the
    evidence for a serialization, retry, time-limit or redelivery claim.
    """
    offenders: list[str] = []
    for path, tree in SOURCES:
        if path.parent.name == "unit":
            continue
        if "task_always_eager" in _identifiers(tree):
            offenders.append(str(path.relative_to(TESTS_ROOT)))
            continue
        for node in ast.walk(tree):
            if isinstance(node, ast.Constant) and node.value == "task_always_eager":
                offenders.append(f"{path.relative_to(TESTS_ROOT)}:{node.lineno}")
    assert not offenders, offenders


@pytest.mark.parametrize("bypass", ["allowed_version_ids", "verify_hmac", "internal"])
def test_no_test_passes_a_bypass_keyword(bypass: str) -> None:
    """No bypass exists to be exercised, because none is built.

    `kb-tenancy-isolation` NN4: no ``allowed_version_ids=None``, no ``verify_hmac`` skip
    flag, no ``internal=True`` kwarg, no fixture that widens a filter. This checks the
    *shape* people reach for — a keyword argument set to ``None`` or ``True`` at a call site
    in the test suite. If a test is awkward without one, the production code is wrong and
    the finding goes to the owning agent, not into a fixture.
    """
    offenders: list[str] = []
    for path, tree in SOURCES:
        for node in ast.walk(tree):
            if not isinstance(node, ast.Call):
                continue
            for keyword in node.keywords:
                if keyword.arg != bypass:
                    continue
                if isinstance(keyword.value, ast.Constant) and keyword.value.value in (
                    None,
                    True,
                ):
                    offenders.append(f"{path.relative_to(TESTS_ROOT)}:{node.lineno}")
    assert not offenders, (
        f"a test widens the tenant scope via {bypass}=: {offenders}. There is no bypass to "
        f"call; if one appears in the application, that is the finding."
    )
