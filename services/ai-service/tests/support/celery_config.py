"""Load ``app/worker/config.py`` without constructing a Celery application.

``import app.worker.config`` first executes ``app/worker/__init__.py``, which imports
``celery`` and calls ``get_settings()`` — so it needs Celery installed and a fully populated
``KB_*`` environment before a single assertion runs. The module under test imports nothing
but ``typing``: it is a table of numbers whose relationships are the thing worth asserting,
and those relationships are wrong or right regardless of whether a broker is reachable.

Loading it by path keeps the timing-arithmetic tests in the container-free tier. The cost is
that the *path* is now part of the contract — if ``app/worker/config.py`` moves, this raises
a clear error rather than silently testing nothing, which is the failure mode that matters.
"""

from __future__ import annotations

import importlib.util
import sys
from types import ModuleType
from typing import Final

from tests.support.tree import APP_ROOT

__all__ = ["WORKER_CONFIG_PATH", "load_worker_config"]

WORKER_CONFIG_PATH: Final = APP_ROOT / "worker" / "config.py"

#: Deliberately not ``app.worker.config``: binding this into ``sys.modules`` under the real
#: name would make a later genuine ``import app.worker.config`` return this detached copy,
#: and the divergence would surface as a Celery app configured from nothing.
_MODULE_NAME: Final[str] = "kb_tests_worker_config"


def load_worker_config() -> ModuleType:
    """Return ``app/worker/config.py`` as a module object, loaded in isolation."""
    cached = sys.modules.get(_MODULE_NAME)
    if cached is not None:
        return cached

    if not WORKER_CONFIG_PATH.exists():
        msg = (
            f"{WORKER_CONFIG_PATH} does not exist. The Celery timing arithmetic is asserted "
            f"against that file by path; if the module moved, update "
            f"tests/support/celery_config.py rather than deleting the assertions."
        )
        raise FileNotFoundError(msg)

    spec = importlib.util.spec_from_file_location(_MODULE_NAME, WORKER_CONFIG_PATH)
    if spec is None or spec.loader is None:  # pragma: no cover - defensive
        msg = f"could not build an import spec for {WORKER_CONFIG_PATH}"
        raise ImportError(msg)

    module = importlib.util.module_from_spec(spec)
    sys.modules[_MODULE_NAME] = module
    spec.loader.exec_module(module)
    return module
