"""Where things are on disk.

Several container-free tests read the source tree rather than importing it — the Celery
settings module, the worker's ``conf.imports`` tuple, and the harness guards all do. They
read it because importing would drag in Celery, pydantic-settings and a populated
environment, and the whole point of the ``unit/`` tier is that it runs on a bare interpreter
the day a container is slow, which is the day it is needed.

Paths are resolved from this file rather than from ``os.getcwd()``: a test that only passes
when pytest was invoked from the service directory is a test that fails in the one CI job
that invokes it from the repository root.
"""

from __future__ import annotations

from pathlib import Path
from typing import Final

__all__ = ["APP_ROOT", "SERVICE_ROOT", "TESTS_ROOT", "module_to_path"]

#: ``services/ai-service``
SERVICE_ROOT: Final[Path] = Path(__file__).resolve().parents[2]
APP_ROOT: Final[Path] = SERVICE_ROOT / "app"
TESTS_ROOT: Final[Path] = SERVICE_ROOT / "tests"


def module_to_path(dotted: str) -> Path:
    """``"app.crawl.tasks"`` -> ``services/ai-service/app/crawl/tasks.py``.

    Returns the path a module *would* occupy; the caller decides what a missing file means.
    Packages are resolved to their ``__init__.py`` only if the plain module file is absent,
    so ``app.deletion.tasks`` never silently resolves to ``app/deletion/__init__.py``.
    """
    parts = dotted.split(".")
    plain = SERVICE_ROOT.joinpath(*parts).with_suffix(".py")
    if plain.exists():
        return plain
    return SERVICE_ROOT.joinpath(*parts, "__init__.py")
