"""``celery_app.conf.imports`` must name modules that exist, and must reach every task.

An **unregistered task is not an import error.** The message is published, no worker
recognises the name, and the job sits in the queue while the source stays mid-lifecycle: no
exception, no failed job, no alert. The only symptom is an admin progress bar that stopped.
That is why the worker names every module explicitly instead of calling
``autodiscover_tasks``, which takes *packages* and imports ``<package>.tasks`` from each and
nothing else — any task defined in a sibling module is silently never registered.

The mirror-image failure is a name in that tuple pointing at a module that does not exist,
which *is* loud: the worker refuses to boot. Loud is the intended trade, and it only holds
while the tuple is correct.

Read with ``ast`` rather than imported: ``import app.worker`` constructs a Celery application
and reads the full ``KB_*`` environment, so the container-free tier could not run it — and
this assertion is most valuable exactly when the environment is not there.
"""

from __future__ import annotations

import ast
from pathlib import Path
from typing import Final

import pytest

from tests.support.tree import APP_ROOT, module_to_path

WORKER_INIT: Final[Path] = APP_ROOT / "worker" / "__init__.py"

#: Deliberately excluded from ``conf.imports`` and from the reachability closure below.
#: ``app.evaluation.tasks`` is imported by the evaluation worker's own command line instead,
#: because ``import ragas`` must **raise** in every other image for the dependency
#: containment to be real — a filesystem walk over ``app/`` would import it everywhere and
#: quietly turn ragas into a runtime dependency of the API container.
CONTAINED_MODULES: Final[frozenset[str]] = frozenset({"app.evaluation.tasks"})


def _parse(path: Path) -> ast.Module:
    return ast.parse(path.read_text(encoding="utf-8"), filename=str(path))


def _conf_imports() -> tuple[str, ...]:
    """Extract the literal assigned to ``celery_app.conf.imports``."""
    for node in _parse(WORKER_INIT).body:
        if not isinstance(node, ast.Assign) or len(node.targets) != 1:
            continue
        target = node.targets[0]
        if not isinstance(target, ast.Attribute) or target.attr != "imports":
            continue
        assert isinstance(node.value, (ast.Tuple, ast.List)), (
            "conf.imports must be a literal tuple of module names. A comprehension or a "
            "variable makes the set unreadable without importing Celery, which is exactly "
            "when it needs reading."
        )
        names: list[str] = []
        for element in node.value.elts:
            assert isinstance(element, ast.Constant) and isinstance(element.value, str), (
                f"non-literal entry in conf.imports at line {element.lineno}"
            )
            names.append(element.value)
        return tuple(names)
    msg = f"no `celery_app.conf.imports = (...)` assignment found in {WORKER_INIT}"
    raise AssertionError(msg)


CONF_IMPORTS: Final[tuple[str, ...]] = _conf_imports()


def _intra_app_imports(dotted: str) -> set[str]:
    """Modules under ``app.`` that ``dotted`` imports directly."""
    path = module_to_path(dotted)
    if not path.exists():
        return set()
    out: set[str] = set()
    for node in ast.walk(_parse(path)):
        if isinstance(node, ast.ImportFrom) and node.module and node.module.startswith("app"):
            out.add(node.module)
            # `from app.deletion import verification` names a module, not an attribute.
            for alias in node.names:
                candidate = f"{node.module}.{alias.name}"
                if module_to_path(candidate).exists():
                    out.add(candidate)
        elif isinstance(node, ast.Import):
            for alias in node.names:
                if alias.name.startswith("app"):
                    out.add(alias.name)
    return out


def _reachable_from_conf_imports() -> set[str]:
    """Transitive closure of ``conf.imports`` over intra-``app`` imports.

    A task module does not have to be *named* in ``conf.imports`` — being imported by one
    that is registers it just as well, which is how ``app.deletion.verification`` is
    reachable through ``app.deletion.tasks``. What matters is reachability, so that is what
    is computed.
    """
    seen: set[str] = set()
    stack = list(CONF_IMPORTS)
    while stack:
        current = stack.pop()
        if current in seen:
            continue
        seen.add(current)
        stack.extend(_intra_app_imports(current))
    return seen


def _task_defining_modules() -> set[str]:
    """Every module under ``app/`` with a ``@celery_app.task(...)`` decorator.

    Found through the AST, so the commented-out decorator block in ``app/crawl/tasks.py`` —
    which a text grep matches — is correctly not counted as a registered task.
    """
    found: set[str] = set()
    for path in sorted(APP_ROOT.rglob("*.py")):
        for node in ast.walk(_parse(path)):
            if not isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef)):
                continue
            for decorator in node.decorator_list:
                call = decorator.func if isinstance(decorator, ast.Call) else decorator
                if (
                    isinstance(call, ast.Attribute)
                    and call.attr == "task"
                    and isinstance(call.value, ast.Name)
                    and call.value.id == "celery_app"
                ):
                    rel = path.relative_to(APP_ROOT.parent).with_suffix("")
                    found.add(".".join(rel.parts))
    return found


def test_conf_imports_is_not_empty() -> None:
    """Positive control for every per-module test below: an empty tuple would make them all
    pass by iterating nothing, which is the shape of a suite that stopped testing."""
    assert CONF_IMPORTS


@pytest.mark.parametrize("dotted", CONF_IMPORTS)
def test_every_named_module_exists_on_disk(dotted: str) -> None:
    """A typo here is a worker that will not boot — the loud failure the explicit list buys.

    It stays loud only while the list is right, and it is checked here rather than at boot
    because a boot-time check is one that fires in production.
    """
    path = module_to_path(dotted)
    assert path.exists(), f"conf.imports names {dotted}, which would be {path}"


@pytest.mark.parametrize("dotted", CONF_IMPORTS)
def test_every_named_module_belongs_to_this_application(dotted: str) -> None:
    """Registering a third-party module's tasks onto our queues is how someone else's retry
    policy starts consuming our workers."""
    assert dotted.split(".")[0] == "app", dotted


def test_conf_imports_has_no_duplicates() -> None:
    """Harmless at runtime, but a duplicate is the residue of a merge that also dropped a
    different entry — worth reading as an edit rather than passing silently."""
    assert len(set(CONF_IMPORTS)) == len(CONF_IMPORTS), CONF_IMPORTS


def test_the_evaluation_tasks_are_deliberately_not_registered_here() -> None:
    """`import ragas` must raise outside ``ai-worker-evaluation``.

    ragas hard-depends on langchain, langchain-core, langchain-community, langchain_openai,
    datasets, networkx and scikit-network — none optional. Registering its tasks in the
    shared worker application would import all of that into the API image, and the
    containment test that asserts the import fails would then be the only thing standing
    between us and a 1 GB API container.
    """
    assert CONTAINED_MODULES.isdisjoint(CONF_IMPORTS)


def test_autodiscovery_is_not_used() -> None:
    """``autodiscover_tasks([...])`` imports ``<package>.tasks`` and nothing else.

    Under it, the embed-queue tasks and deletion's verification tasks — both defined in
    sibling modules — would never register, and an unregistered task hangs silently.
    """
    source = WORKER_INIT.read_text(encoding="utf-8")
    tree = _parse(WORKER_INIT)
    calls = {
        node.func.attr
        for node in ast.walk(tree)
        if isinstance(node, ast.Call) and isinstance(node.func, ast.Attribute)
    }
    assert "autodiscover_tasks" not in calls, source


def test_every_registered_task_module_is_reachable_from_conf_imports() -> None:
    """The assertion the whole file exists for.

    A new module defining ``@celery_app.task`` that nothing in the closure imports produces
    a task name no worker knows. The publish succeeds, the job never runs, and the lifecycle
    state it was going to advance never advances.
    """
    reachable = _reachable_from_conf_imports()
    defining = _task_defining_modules() - CONTAINED_MODULES
    assert defining, "no @celery_app.task decorator found anywhere — the scan is broken"
    unreachable = defining - reachable
    assert not unreachable, (
        f"these modules define Celery tasks that no worker will register: "
        f"{sorted(unreachable)}. Name them in celery_app.conf.imports, or import them from a "
        f"module that is named there."
    )
