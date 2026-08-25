"""The write allow-list, enforced over the tree rather than over one module.

``docs/22`` § Q5 recorded two failures that a green diff cannot distinguish:

1. **Admitting a wrong name** to ``ALLOWED_TABLES``. The deleted ``gates.yml`` covered this and
   nothing replaced it; no runtime check can, because a correctly-admitted name and a wrongly
   admitted one are the same string. It stays a review decision.
2. **Writing a statement against a name that was never admitted.** ``assert_writable`` covers this
   *inside* ``app/db/writes.py`` — but only there, and a statement does not have to be there.

This file is the second failure's real mechanism, and it is not hypothetical. It was written after
finding a live instance: ``app/ingestion/runner.py`` carried

    UPDATE source_versions SET delivery_count = delivery_count + 1 …

against a Laravel-owned table that ``App\\Services\\Sources\\IngestionProgress`` says in writing
"is not — and must never be — in ``ALLOWED_TABLES``", twenty lines below a docstring in the same
file asserting that this service never writes one of these tables. It passed 1,749 tests, mypy,
ruff and a human review, because nothing looked at statements.

═══ WHY IT IS AN AST WALK AND NOT A GREP ═══════════════════════════════════════════════════════

Three properties a grep cannot have, and each one decides a real case in this tree:

* **Docstrings and comments are prose and must not trip it.** This very module names the illegal
  statement above; so does ``app/ingestion/deliveries.py``, at length, because that is where the
  decision is recorded. A grep over the tree matches the explanation of the defect as readily as
  the defect — the self-tripping shape ``CLAUDE.md``'s own sweep command warns about. Comments are
  absent from the AST entirely, and docstrings are identified structurally and skipped.
* **An f-string's table position may be a GATE rather than a name.** ``writes.py`` writes
  ``table = assert_writable("chunks")`` and then ``f"DELETE FROM {table} …"``, deliberately, so
  that the gate is in the expression that produces the name rather than on a line beside the
  statement — a guard on its own line is a guard someone deletes while moving code. The walk
  therefore resolves the interpolated expression: it accepts a direct ``assert_writable(...)``
  call and a local bound from one **in the same function**, and nothing else. Per-function and
  not per-module: a name gated in one function tells you nothing about the same name in another.
* **Reads are not writes.** ``SELECT`` is unrestricted: the data plane reads the control plane's
  tables constantly, and that is what makes Qdrant rebuildable. Only INSERT / UPDATE / DELETE are
  in scope, which a grep for table names cannot express.
"""

from __future__ import annotations

import ast
import re
import textwrap
from pathlib import Path
from typing import Final

import pytest

from app.db.writes import ALLOWED_TABLES

APP: Final[Path] = Path(__file__).resolve().parents[2] / "app"

#: A placeholder standing in for one ``{...}`` inside an f-string. ``\x00`` cannot appear in
#: Python source, so it cannot collide with a real identifier.
HOLE: Final[str] = "\x00"

#: The three write verbs, each with enough following syntax that an English sentence containing
#: the word "update" in a log message is not mistaken for a statement.
STATEMENT: Final[re.Pattern[str]] = re.compile(
    r"\b(?:INSERT\s+INTO|DELETE\s+FROM)\s+([\w\x00]+)|\bUPDATE\s+([\w\x00]+)\s+SET\b",
    re.IGNORECASE,
)


def _python_files() -> list[Path]:
    return sorted(p for p in APP.rglob("*.py") if "__pycache__" not in p.parts)


def _docstring_nodes(tree: ast.AST) -> set[int]:
    """Every string Constant that is a docstring, by identity.

    A docstring is the first statement of a module, class or function body and nothing else. That
    is a structural test, not a heuristic, which is what lets prose describe an illegal statement
    without becoming one.
    """
    found: set[int] = set()
    for node in ast.walk(tree):
        if isinstance(node, ast.Module | ast.ClassDef | ast.FunctionDef | ast.AsyncFunctionDef):
            body = node.body
            if (
                body
                and isinstance(body[0], ast.Expr)
                and isinstance(body[0].value, ast.Constant)
                and isinstance(body[0].value.value, str)
            ):
                found.add(id(body[0].value))
    return found


def _gated_names(scope: ast.AST) -> set[str]:
    """Locals bound from ``assert_writable(...)`` anywhere in this scope's subtree.

    A nested function inherits its enclosing scope's bindings, so taking the whole subtree is the
    correct direction: it accepts a closure over a gated name and never invents a binding that
    does not exist.
    """
    names: set[str] = set()
    for node in ast.walk(scope):
        if not isinstance(node, ast.Assign) or not isinstance(node.value, ast.Call):
            continue
        func = node.value.func
        gate = (isinstance(func, ast.Name) and func.id == "assert_writable") or (
            isinstance(func, ast.Attribute) and func.attr == "assert_writable"
        )
        if gate:
            names.update(t.id for t in node.targets if isinstance(t, ast.Name))
    return names


def _scope_of(tree: ast.AST) -> dict[int, ast.AST]:
    """Every node's innermost enclosing function (or the module), by node identity."""
    owner: dict[int, ast.AST] = {}

    def descend(node: ast.AST, scope: ast.AST) -> None:
        for child in ast.iter_child_nodes(node):
            here = child if isinstance(child, ast.FunctionDef | ast.AsyncFunctionDef) else scope
            owner[id(child)] = here
            descend(child, here)

    owner[id(tree)] = tree
    descend(tree, tree)
    return owner


def _statements(tree: ast.AST) -> list[tuple[int, str, str | None]]:
    """Every write statement in the module, as ``(lineno, table, gate_expression)``.

    ``table`` is ``HOLE`` when the table position was interpolated; ``gate_expression`` is then
    the unparsed expression that filled it, with ``!gated`` appended when that expression resolves
    to a name bound from ``assert_writable`` in the enclosing function.
    """
    skip = _docstring_nodes(tree)
    owner = _scope_of(tree)
    gated_in: dict[int, set[str]] = {}
    out: list[tuple[int, str, str | None]] = []

    def gate_for(node: ast.AST, expression: str) -> str:
        scope = owner.get(id(node), tree)
        if id(scope) not in gated_in:
            gated_in[id(scope)] = _gated_names(scope)
        suffix = " !gated" if expression.strip() in gated_in[id(scope)] else ""
        return expression + suffix

    for node in ast.walk(tree):
        if isinstance(node, ast.Constant) and isinstance(node.value, str) and id(node) not in skip:
            for match in STATEMENT.finditer(node.value):
                out.append((node.lineno, match.group(1) or match.group(2), None))

        elif isinstance(node, ast.JoinedStr):
            text = ""
            holes: list[str] = []
            for part in node.values:
                if isinstance(part, ast.Constant) and isinstance(part.value, str):
                    text += part.value
                else:
                    text += HOLE
                    holes.append(
                        gate_for(node, ast.unparse(part.value))
                        if isinstance(part, ast.FormattedValue)
                        else ""
                    )
            index = 0
            for match in STATEMENT.finditer(text):
                table = match.group(1) or match.group(2)
                if HOLE in table:
                    # Which hole: count the holes appearing before this match.
                    index = text.count(HOLE, 0, match.start())
                    out.append((node.lineno, HOLE, holes[index] if index < len(holes) else ""))
                else:
                    out.append((node.lineno, table, None))

    return out


def test_the_scan_sees_the_statements_it_is_supposed_to_see() -> None:
    """The vacuity control, and it is not optional.

    A scan that silently matches nothing passes forever. `writes.py` is known to contain gated
    statements and `deletion/relational.py` is known to contain literal ones; if either stops
    being found, the walk has broken and every assertion below is worthless.
    """
    gated = _statements(ast.parse((APP / "db" / "writes.py").read_text()))
    literal = _statements(ast.parse((APP / "deletion" / "relational.py").read_text()))

    assert [s for s in gated if s[1] == HOLE], "no gated statement found in writes.py"
    assert [s for s in literal if s[1] != HOLE], "no literal statement found in relational.py"


def test_every_write_statement_names_an_allow_listed_table() -> None:
    violations: list[str] = []

    for path in _python_files():
        for lineno, table, gate in _statements(ast.parse(path.read_text())):
            where = f"{path.relative_to(APP.parent)}:{lineno}"
            if table == HOLE:
                # An interpolated table position is permitted only when the interpolation IS the
                # gate. `f"DELETE FROM {table}"` with a plain name is the shape this rejects.
                if "assert_writable" not in (gate or "") and "!gated" not in (gate or ""):
                    violations.append(f"{where}: interpolated table not gated: {gate!r}")
            elif table not in ALLOWED_TABLES:
                violations.append(f"{where}: writes {table!r}, which is not on the allow-list")

    assert not violations, "\n".join(
        [
            "Statements writing tables the data plane is not permitted to write.",
            "Either the statement belongs in the control plane, or the table needs admitting to",
            "ALLOWED_TABLES against ADR-033's three properties — which is a review decision and",
            "never a way to make this test pass.",
            *violations,
        ]
    )


@pytest.mark.parametrize("column", ["current_version_id", "activated_at", "retired_at"])
def test_no_statement_writes_the_active_version_pointer(column: str) -> None:
    """``docs/22`` § Q6: the rule whose only enforcement was a sentence.

    ``app/ingestion/publish.py`` opens **"THIS SERVICE NEVER ASSIGNS THE ACTIVE-VERSION
    POINTER."** Before Phase C1 that was unenforced *and unreachable* — the column did not exist,
    so a second writer failed with an undefined column in the first test that ran. C1 created
    ``source_items.current_version_id`` and the partial unique index
    ``source_versions_one_active_per_item``, which made the rule reachable and left a CONSTRAINT
    standing at it. **A constraint is the trap, not the guard.** A second writer finds one inside
    a Celery task: it raises an integrity error, retries, raises again, and burns a worker forever
    while the ingest reports success, the bot answers from the previous version and every
    dashboard stays green.

    The three columns here are the activation sequence, not just the pointer. A write to
    ``activated_at`` or ``retired_at`` from this tree is the same defect one step earlier: it puts
    a second author on the rows the partial index is computed over.

    The scan is deliberately coarser than the allow-list one — it looks for the column name
    ANYWHERE in a write statement, not only in a SET clause — because the failure it guards
    against is severe and silent, and a false positive here is a comment away from being resolved.
    """
    offenders: list[str] = []

    for path in _python_files():
        tree = ast.parse(path.read_text())
        skip = _docstring_nodes(tree)
        for node in ast.walk(tree):
            text = ""
            if (
                isinstance(node, ast.Constant)
                and isinstance(node.value, str)
                and id(node) not in skip
            ):
                text = node.value
            elif isinstance(node, ast.JoinedStr):
                text = "".join(
                    part.value
                    for part in node.values
                    if isinstance(part, ast.Constant) and isinstance(part.value, str)
                )
            if not text or column not in text:
                continue
            if STATEMENT.search(text):
                offenders.append(f"{path.relative_to(APP.parent)}:{node.lineno}")

    assert not offenders, f"a write statement in the data plane names {column!r}: " + ", ".join(
        offenders
    )


# ══════════════════════════════════════════════════════════════════════════════════════════════
# POSITIVE CONTROLS
#
# The scan above passes over a clean tree, which is the state it is supposed to report and also
# the state a broken scan reports. These four fix what "broken" would look like, against synthetic
# sources rather than against the tree, so they keep proving the walk after the tree changes.
# ══════════════════════════════════════════════════════════════════════════════════════════════


def test_it_reports_the_statement_that_was_actually_found_in_this_tree() -> None:
    """The exact defect this file was written after, reduced to its shape."""
    source = textwrap.dedent(
        '''
        async def bump(conn, org_id, version_id):
            """Increment and read the durable delivery counter in one statement."""
            await conn.execute(
                "UPDATE source_versions SET delivery_count = delivery_count + 1 "
                "WHERE organization_id = %s AND id = %s RETURNING delivery_count",
                (org_id, version_id),
            )
        '''
    )

    assert [table for _, table, _ in _statements(ast.parse(source))] == ["source_versions"]
    assert "source_versions" not in ALLOWED_TABLES


def test_prose_describing_an_illegal_statement_is_not_an_illegal_statement() -> None:
    """Without this the register that records the defect becomes the defect.

    `app/ingestion/deliveries.py` quotes the removed UPDATE in its module docstring, deliberately,
    because that is where the decision lives. A scan that cannot tell the account from the act
    forces the account to be deleted — which is how a repository loses the reason for a rule and
    keeps only the rule.
    """
    source = (
        '"""It used to run UPDATE source_versions SET delivery_count = delivery_count + 1."""\n'
    )
    assert _statements(ast.parse(source)) == []


def test_an_interpolated_table_is_refused_unless_it_came_through_the_gate() -> None:
    ungated = textwrap.dedent(
        """
        async def purge(conn, table, org_id):
            await conn.execute(f"DELETE FROM {table} WHERE organization_id = %s", (org_id,))
        """
    )
    gated = textwrap.dedent(
        """
        async def purge(conn, org_id):
            table = assert_writable("chunks")
            await conn.execute(f"DELETE FROM {table} WHERE organization_id = %s", (org_id,))
        """
    )

    [(_, table, gate)] = _statements(ast.parse(ungated))
    assert table == HOLE
    assert "!gated" not in (gate or "")

    [(_, table, gate)] = _statements(ast.parse(gated))
    assert table == HOLE
    assert "!gated" in (gate or "")


def test_a_gate_in_a_different_function_does_not_launder_the_name() -> None:
    """Per-function, and this is the case that decides it.

    Module-level name collection would accept the second function's `table` because the FIRST
    function gated a variable of that name — which is the kind of looseness that makes a scan
    reassuring rather than protective.
    """
    source = textwrap.dedent(
        """
        async def legitimate(conn, org_id):
            table = assert_writable("chunks")
            await conn.execute(f"DELETE FROM {table} WHERE organization_id = %s", (org_id,))

        async def not_legitimate(conn, table, org_id):
            await conn.execute(f"DELETE FROM {table} WHERE organization_id = %s", (org_id,))
        """
    )

    gates = [gate for _, _, gate in _statements(ast.parse(source))]
    assert sum("!gated" in (g or "") for g in gates) == 1
