"""Every table and column this service names in SQL exists in a Laravel migration.

PostgreSQL is the source of truth and **Laravel owns every migration** (ADR-033). The data
plane reads those tables constantly — that is what makes Qdrant rebuildable — and it does so
through hand-written SQL in string literals, which nothing checks: a wrong column name is
valid Python, passes ruff, passes mypy, and raises `UndefinedColumn` only when the statement
actually executes. On the ingestion path that is inside a Celery task, after the parse and the
OCR have already been paid for, on a run that then retries to its delivery cap.

**This is not hypothetical and it is why this file exists.** Four defects of exactly that shape
were live in this tree at once, and none of them had a test:

* ``source_items.knowledge_source_id`` — the column is ``source_id``; the longer spelling is
  what the *table* is named after. It appeared twice in one query in `app/ingestion/runner.py`.
* ``bot_source_assignments.knowledge_source_id`` — the same mistake, same file, so no bot id
  would ever have reached a payload.
* ``provider_models.model_id`` — the column is ``model``; ``model_id`` is the *wire* field name.
* ``embedding_designations`` — **a table no migration creates.** The designation is two columns
  on ``organizations`` (the control-plane half of finding C1), so this one does not degrade: it
  raises ``UndefinedTable`` and takes down ingestion for every tenant, designated or not.

WHAT IT CHECKS AND WHAT IT CANNOT
-----------------------------------
It parses the migrations for ``CREATE TABLE`` and ``ADD COLUMN``, and parses this package's
Python for SQL string literals. Then three things: every table named exists, every
``alias.column`` resolves, and — for a statement naming exactly one table — every bare
identifier that is not a keyword, a parameter or a function is a column of it.

It is a **syntactic** check and it proves nothing about semantics: a query that reads the right
column with the wrong meaning passes. It also cannot see a statement assembled at runtime from
values, which is why `_statements` resolves the two static forms it can (a table name
interpolated through ``assert_writable`` and a local bound to one) and **counts what it skipped**
— a silent skip would make this file's coverage shrink invisibly as more SQL is written.
"""

from __future__ import annotations

import ast
import re
from pathlib import Path
from typing import Final

import pytest

APP = Path(__file__).resolve().parents[2] / "app"
MIGRATIONS = Path(__file__).resolve().parents[3] / "core-api" / "database" / "migrations"

#: SQL words that are never a column. Deliberately generous — a keyword wrongly listed here
#: only weakens the check, while a column wrongly matched as a keyword would be a false pass.
KEYWORDS: Final[frozenset[str]] = frozenset(
    [
        "all",
        "and",
        "any",
        "as",
        "asc",
        "by",
        "case",
        "conflict",
        "current_timestamp",
        "default",
        "delete",
        "desc",
        "distinct",
        "do",
        "else",
        "end",
        "excluded",
        "exists",
        "false",
        "for",
        "from",
        "full",
        "group",
        "having",
        "in",
        "inner",
        "insert",
        "interval",
        "into",
        "is",
        "join",
        "left",
        "limit",
        "not",
        "nothing",
        "nowait",
        "null",
        "offset",
        "on",
        "or",
        "order",
        "outer",
        "returning",
        "right",
        "select",
        "set",
        "share",
        "then",
        "true",
        "union",
        "update",
        "using",
        "values",
        "when",
        "where",
        "with",
    ]
)

#: Pseudo-tables and prefixes a qualified reference may legally carry that are not aliases.
NON_ALIAS_QUALIFIERS: Final[frozenset[str]] = frozenset({"excluded"})

_CREATE = re.compile(r"CREATE TABLE (?:IF NOT EXISTS )?(\w+)\s*\((.*?)\n\s*\)\s*\n", re.S)
_COLUMN = re.compile(
    r"^(\w+)\s+(char|varchar|text|integer|int|bigint|smallint|boolean|jsonb|json|timestamptz|"
    r"timestamp|numeric|uuid|bytea|real|double|inet|date)\b"
)
_ALTER = re.compile(r"ALTER TABLE (\w+)")
_ADD_COLUMN = re.compile(r"ADD COLUMN (\w+)")
# CASE-INSENSITIVE, and the first version of this file was not — which made it silently blind.
# `[a-z_][a-z0-9_]*` matched the leading lowercase RUN of a mixed-case word, so a column
# misspelled `progress_sequenceX` matched as `progress_sequence`, found it in the schema, and
# passed. A checker that reports a pass for a name that does not exist is the failure mode this
# whole file was written to remove, so the regex is wide and the keyword comparison is folded.
_IDENTIFIER = re.compile(r"[A-Za-z_][A-Za-z0-9_]*")


def _schema() -> dict[str, set[str]]:
    """``{table: {column, ...}}`` from the migrations, by reading their SQL.

    ``ADD COLUMN`` is attributed to the nearest preceding ``ALTER TABLE`` in the same file,
    which over-attributes when one file alters two tables. That direction is deliberate: an
    over-attributed column makes this check more permissive and can only produce a false pass,
    never a false failure that sends someone renaming a correct column.
    """
    tables: dict[str, set[str]] = {}
    for path in sorted(MIGRATIONS.iterdir()):
        if path.suffix != ".php":
            continue
        text = path.read_text()
        for name, body in _CREATE.findall(text):
            columns = tables.setdefault(name, set())
            for raw in body.splitlines():
                line = raw.strip()
                if not line or line.startswith("--"):
                    continue
                match = _COLUMN.match(line)
                if match:
                    columns.add(match.group(1))
        parts = _ALTER.split(text)
        # `split` on a capturing group yields [before, table, chunk, table, chunk, ...].
        for index in range(1, len(parts) - 1, 2):
            table, chunk = parts[index], parts[index + 1]
            tables.setdefault(table, set()).update(_ADD_COLUMN.findall(chunk))
    return tables


def _table_locals(scope: ast.AST) -> dict[str, str]:
    """Locals bound to ``assert_writable("t")`` **within one scope**, by name.

    `app/db/writes.py` interpolates its table name so one statement serves the allow-list gate;
    resolving the binding is what keeps those statements inside this check instead of in the
    skipped pile.

    PER FUNCTION AND NOT PER MODULE, which this file got wrong first and which produced a
    *false failure* rather than a false pass: `writes.py` binds a local called ``table`` in two
    functions, to ``document_elements`` in one and ``chunks`` in the other, so a module-wide map
    kept whichever came last and reported every ``document_elements`` column as missing from
    ``chunks``. A checker whose failures cannot be trusted is worse than no checker.
    """
    bound: dict[str, str] = {}
    for node in ast.walk(scope):
        if not isinstance(node, ast.Assign) or len(node.targets) != 1:
            continue
        target, value = node.targets[0], node.value
        if not isinstance(target, ast.Name) or not isinstance(value, ast.Call):
            continue
        if getattr(value.func, "id", None) != "assert_writable":
            continue
        if value.args and isinstance(value.args[0], ast.Constant):
            bound[target.id] = str(value.args[0].value)
    return bound


def _flatten(node: ast.JoinedStr, bound: dict[str, str]) -> str:
    parts: list[str] = []
    for piece in node.values:
        if isinstance(piece, ast.Constant):
            parts.append(str(piece.value))
            continue
        if isinstance(piece, ast.FormattedValue):
            value = piece.value
            if (
                isinstance(value, ast.Call)
                and getattr(value.func, "id", None) == "assert_writable"
                and value.args
                and isinstance(value.args[0], ast.Constant)
            ):
                parts.append(str(value.args[0].value))
            elif isinstance(value, ast.Name) and value.id in bound:
                parts.append(bound[value.id])
            else:
                parts.append("\x00")
    return "".join(parts)


def _statements() -> list[tuple[str, int, str]]:
    """Every SQL statement this package writes, as ``(file, line, one-line sql)``.

    Only strings that *begin* with a statement keyword — Python concatenates adjacent literals
    at parse time, so a multi-line query is one constant and a docstring that happens to mention
    SELECT is not a statement. A string still holding an unresolved interpolation is marked with
    a NUL so the tests below can count it as skipped rather than parse a hole.
    """
    best: dict[tuple[str, int], str] = {}
    for path in sorted(APP.rglob("*.py")):
        tree = ast.parse(path.read_text())
        inside_fstring = {
            id(piece)
            for node in ast.walk(tree)
            if isinstance(node, ast.JoinedStr)
            for piece in node.values
        }
        where = str(path.relative_to(APP.parent))
        # The module first, then every function. A statement inside a function is reached by
        # both walks; the module's bindings leave a NUL where the function's resolve the table,
        # so the two copies are collapsed below by preferring the resolved one. Keyed on
        # (file, line) because that pair identifies the statement and the two walks agree on it.
        scopes: list[ast.AST] = [
            tree,
            *(
                node
                for node in ast.walk(tree)
                if isinstance(node, ast.FunctionDef | ast.AsyncFunctionDef)
            ),
        ]
        for scope in scopes:
            bound = _table_locals(scope)
            for node in ast.walk(scope):
                if isinstance(node, ast.JoinedStr):
                    text = _flatten(node, bound)
                elif (
                    isinstance(node, ast.Constant)
                    and isinstance(node.value, str)
                    and id(node) not in inside_fstring
                ):
                    text = node.value
                else:
                    continue
                if not re.match(r"^\s*(SELECT|INSERT INTO|UPDATE|DELETE FROM)\b", text):
                    continue
                key = (where, node.lineno)
                sql = " ".join(text.split())
                if "\x00" not in sql or key not in best:
                    best[key] = sql
    return [(where, line, sql) for (where, line), sql in sorted(best.items())]


def _sources(sql: str) -> dict[str, str]:
    """``{alias-or-table: table}`` for every table this statement names."""
    sources: dict[str, str] = {}
    for table, alias in re.findall(
        r"\b(?:FROM|JOIN|INTO|UPDATE)\s+([a-z_][a-z0-9_]*)(?:\s+(?!ON\b|SET\b|WHERE\b|VALUES\b)"
        r"([a-z_][a-z0-9_]*))?",
        sql,
    ):
        sources[table] = table
        if alias:
            sources[alias] = table
    return sources


def _strip_values(sql: str) -> str:
    """Remove quoted literals and bound parameters so neither is mistaken for a column."""
    sql = re.sub(r"'[^']*'", " ", sql)
    return re.sub(r"%\((\w+)\)s|%s", " ", sql)


SCHEMA = _schema()
STATEMENTS = _statements()


def test_the_migrations_parsed_into_a_schema_at_all() -> None:
    """A guard on this file's own premise. If the parser silently found nothing, every check
    below passes vacuously — which is the one way a schema test can be worse than no test."""
    assert MIGRATIONS.is_dir(), f"no migrations at {MIGRATIONS}"
    assert len(SCHEMA) >= 15, f"parsed only {len(SCHEMA)} tables out of the migrations"
    assert "source_id" in SCHEMA["source_items"]
    assert "model" in SCHEMA["provider_models"]
    assert "embedding_connection_id" in SCHEMA["organizations"], "ADD COLUMN was not parsed"


def test_the_scanner_found_the_statements_it_is_supposed_to_check() -> None:
    """The mirror guard: a scanner that matched nothing would also pass everything."""
    assert len(STATEMENTS) >= 20, f"found only {len(STATEMENTS)} statements in {APP}"
    assert any("bot_source_assignments" in sql for _, _, sql in STATEMENTS)
    assert any("provider_models" in sql for _, _, sql in STATEMENTS)


def test_every_table_the_data_plane_names_exists_in_a_migration() -> None:
    """`embedding_designations` was named by a live query and created by nothing. A missing
    table does not degrade — it raises `UndefinedTable` for every tenant on every run."""
    missing = [
        f"{where}:{line} names table {table!r} — no migration creates it :: {sql[:90]}"
        for where, line, sql in STATEMENTS
        if "\x00" not in sql
        for table in set(_sources(sql).values())
        if table not in SCHEMA
    ]
    assert missing == [], "\n".join(missing)


def test_every_qualified_column_reference_resolves() -> None:
    """`i.knowledge_source_id` and `m.model_id` were both this shape: an alias that resolves and
    a column that does not."""
    problems: list[str] = []
    for where, line, sql in STATEMENTS:
        if "\x00" in sql:
            continue
        sources = _sources(sql)
        for qualifier, column in re.findall(r"\b([a-z_][a-z0-9_]*)\.([a-z_][a-z0-9_]*)\b", sql):
            if qualifier in NON_ALIAS_QUALIFIERS:
                continue
            table = sources.get(qualifier)
            if table is None:
                problems.append(f"{where}:{line} qualifier {qualifier!r} names no table in {sql}")
            elif column not in SCHEMA.get(table, set()):
                problems.append(f"{where}:{line} {table}.{column} does not exist :: {sql[:110]}")
    assert problems == [], "\n".join(problems)


#: An identifier introduced by ``AS``, with the keyword immediately before it. Anchored at the
#: end so it can be searched against the slice ending at the identifier, which keeps the scan
#: O(1) per match rather than re-parsing the statement.
_PRECEDED_BY_AS: Final = re.compile(r"\bAS\s+$", re.IGNORECASE)


def test_every_bare_column_in_a_single_table_statement_exists() -> None:
    """The unqualified half — `WHERE knowledge_source_id = %s` against a table whose column is
    `source_id`. Only for statements naming ONE table, because a bare name in a join is
    genuinely ambiguous and guessing which side it belongs to would invent failures."""
    problems: list[str] = []
    for where, line, sql in STATEMENTS:
        if "\x00" in sql:
            continue
        sources = _sources(sql)
        tables = set(sources.values())
        if len(tables) != 1:
            continue
        table = next(iter(tables))
        columns = SCHEMA.get(table, set())
        cleaned = _strip_values(sql)
        for match in _IDENTIFIER.finditer(cleaned):
            name = match.group(0)
            if name.lower() in KEYWORDS or name in sources or name in columns:
                continue
            after = cleaned[match.end() : match.end() + 1]
            before = cleaned[max(0, match.start() - 1) : match.start()]
            if after == "(" or before == ".":
                continue  # a function call, or the qualified half checked above
            if _PRECEDED_BY_AS.search(cleaned, 0, match.start()):
                # An OUTPUT ALIAS, which is a name being *introduced* rather than referenced —
                # `sum(document_frequency) AS df`. Added 2026-08-27, when `app/retrieval/
                # statistics.py` became the first data-plane statement to alias an aggregate and
                # this check reported `sparse_term_frequencies.df does not exist` twice. Both
                # reports were false and the statements were correct.
                #
                # It is worth being clear about which of the two failure directions this trades
                # into, because a checker that stops reporting is the one nobody notices. The
                # aliased EXPRESSION is still fully checked — `document_frequency` above is an
                # ordinary bare column and goes through the loop like any other — so what is
                # skipped is only the new name on the left of nothing, which by construction
                # cannot resolve to a column and never could. The cost is that a statement which
                # aliases something and then *references the alias* elsewhere gets no check on
                # that reference; PostgreSQL does not permit that in WHERE anyway, and GROUP BY /
                # ORDER BY are where it is legal, which is the narrow gap this leaves open.
                continue
            problems.append(f"{where}:{line} {table}.{name} does not exist :: {sql[:110]}")
    assert problems == [], "\n".join(problems)


def test_what_could_not_be_checked_is_visible_rather_than_silent() -> None:
    """A statement whose table is assembled at runtime is skipped, and the count is asserted so
    the skipped pile cannot grow without somebody deciding it may."""
    skipped = [f"{w}:{line}" for w, line, sql in STATEMENTS if "\x00" in sql]
    assert len(skipped) <= 2, (
        "more SQL is now unresolvable than this test was written for; either resolve the "
        "interpolation the way `_table_locals` does, or raise this bound on purpose: "
        + ", ".join(skipped)
    )


@pytest.mark.parametrize(
    ("table", "column"),
    [
        ("source_items", "source_id"),
        ("source_items", "progress_sequence"),
        ("source_items", "current_job_id"),
        ("source_versions", "ingest_key"),
        ("source_versions", "embedding_model_version"),
        ("bot_source_assignments", "source_id"),
        ("bot_source_assignments", "enabled"),
        ("provider_connections", "credential_ciphertext"),
        ("provider_models", "capability_flags"),
        ("organizations", "embedding_model"),
    ],
)
def test_the_columns_the_ingestion_path_depends_on_are_named_correctly(
    table: str, column: str
) -> None:
    """Pinned individually as well as scanned, so a rename in a migration fails HERE — naming
    the column — rather than as a scanner miss somewhere in a 200-character statement."""
    assert column in SCHEMA[table]
