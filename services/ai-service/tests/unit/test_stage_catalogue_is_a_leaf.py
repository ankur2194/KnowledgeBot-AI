"""``app/rag/stages.py`` must import nothing from ``app``, because one edge now depends on it.

``app/retrieval/search.py`` imports ``span_for`` from ``app/rag/stages.py`` — the one place a
module under ``app/retrieval/`` reaches into ``app/rag/``. The alternative was a second copy of
the span catalogue, and a span name that exists twice eventually exists in two spellings, at
which point one of them exports cleanly and matches no recording rule, alert or dashboard query.
The failure is empty panels on the dashboard you opened to debug retrieval, and nothing raises.

The edge is only safe while ``stages.py`` is a leaf. The moment it imports anything under
``app`` — a settings object, an error class, a candidate type — the graph closes and the
``app/rag`` ⇄ ``app/retrieval`` cycle becomes an ``ImportError`` whose message names whichever
module happened to be imported first, which is a different module in the worker than in the API.

Parsed from the source rather than probed at runtime: a cycle that exists is a cycle that has
already broken the import this test would perform.
"""

from __future__ import annotations

import ast
from pathlib import Path
from typing import Final

from tests.support.tree import SERVICE_ROOT

CATALOGUE: Final[Path] = SERVICE_ROOT / "app" / "rag" / "stages.py"


def imported_modules(source: Path) -> set[str]:
    found: set[str] = set()
    tree = ast.parse(source.read_text(encoding="utf-8"), filename=str(source))
    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            found.update(alias.name for alias in node.names)
        elif isinstance(node, ast.ImportFrom):
            # `level > 0` is a relative import, which is an app import by definition.
            found.add("." * node.level + (node.module or ""))
    return found


def test_the_stage_catalogue_imports_nothing_from_the_application() -> None:
    offending = {
        name
        for name in imported_modules(CATALOGUE)
        if name == "app" or name.startswith(("app.", "."))
    }
    assert not offending, (
        f"app/rag/stages.py imported {sorted(offending)}. It is imported by "
        "app/retrieval/search.py for the span catalogue, so it has to stay a leaf; an app "
        "import here closes the app/rag <-> app/retrieval cycle"
    )


def test_no_span_name_is_built_by_interpolation_anywhere_in_the_two_owned_packages() -> None:
    """``f"kb.retrieval.{using}"`` is the shape, and it is invisible in every output.

    It produces exactly the catalogued strings today, so no assertion on an *emitted* name can
    distinguish it from ``span_for(...)`` — and the day a branch or a stage is renamed it keeps
    producing a name, silently, that matches no recording rule, alert or dashboard query. The
    only place the difference exists is the source, so that is where it is checked.

    Parsed rather than grepped: a grep over these files hits the prose, and both packages are
    deliberately prose-heavy about exactly this rule.
    """
    offenders: list[str] = []
    for package in ("rag", "retrieval"):
        for source in sorted((SERVICE_ROOT / "app" / package).glob("*.py")):
            tree = ast.parse(source.read_text(encoding="utf-8"), filename=str(source))
            for node in ast.walk(tree):
                if not isinstance(node, ast.JoinedStr):
                    continue
                literal = "".join(
                    part.value
                    for part in node.values
                    if isinstance(part, ast.Constant) and isinstance(part.value, str)
                )
                if literal.startswith("kb."):
                    offenders.append(f"{source.name}:{node.lineno}")
    assert not offenders, (
        f"a span name is being interpolated at {offenders}. Span names come from "
        "app/rag/stages.py's closed lookup, which raises on an unknown stage; a derived name "
        "errors nowhere and matches nothing"
    )


def test_the_branch_span_names_resolve_through_the_catalogue() -> None:
    """The consumer side of the same edge: every branch maps to a catalogued stage.

    ``span_for`` raises ``KeyError`` on a stage that records no span, so this fails loudly if a
    branch is ever pointed at a stage name that is not in ``STAGE_SPANS`` — which is the shape
    a runtime-minted name takes once someone "fixes" the lookup.
    """
    from app.rag.stages import span_for
    from app.retrieval.search import BRANCH_STAGES

    assert {branch: span_for(stage) for branch, stage in BRANCH_STAGES.items()} == {
        "dense": "kb.retrieval.dense",
        "sparse": "kb.retrieval.sparse",
    }
