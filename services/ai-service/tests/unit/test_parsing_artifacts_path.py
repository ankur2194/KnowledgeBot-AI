"""Where the parser looks for its weights, and the four places that must agree about it.

`ARTIFACTS_PATH` read `/opt/docling-artifacts`, a value that existed nowhere else in the
repository. Everything that actually puts weights on disk uses `/models`: the Compose volume
mountpoint, `HF_HOME` in the image, `Settings.model_cache_dir`, and the build-time
`docling-tools models download`.

The disagreement is invisible in exactly the way that matters. With `HF_HUB_OFFLINE=1` set and
the weights present under `/models`, a converter pointed at the wrong directory does **not**
fall back to a slow download — it fails on the first page a worker tries to parse, in a
container with no network, long after startup, and reads as a corrupt document rather than as a
misconfigured path.

Asserted against the files themselves rather than against a restated constant. A copy of the
expected value here would agree with itself forever while the real ones drifted, which is the
failure this file exists to catch.
"""

from __future__ import annotations

import re
from pathlib import Path
from typing import Final

from app.core.config import Settings
from app.ingestion.parsing.converter import ARTIFACTS_PATH
from tests.support.tree import SERVICE_ROOT

_REPO_ROOT: Final[Path] = SERVICE_ROOT.parents[1]
_DOCKERFILE: Final[Path] = SERVICE_ROOT / "Dockerfile"
_COMPOSE: Final[Path] = _REPO_ROOT / "infrastructure" / "docker" / "compose.yaml"


def test_the_parser_and_the_settings_agree() -> None:
    """`Settings.model_cache_dir` is what `HF_HOME` is pointed at, and `HF_HOME` is the variable
    that actually drives caching for docling, docling-ibm-models and huggingface_hub. A parser
    reading a different directory is a parser reading an empty one."""
    assert Settings.model_fields["model_cache_dir"].default == ARTIFACTS_PATH


def test_the_image_bakes_the_weights_where_the_parser_looks() -> None:
    """`ENV HF_HOME=` in the runtime stage. The download step runs before `HF_HUB_OFFLINE=1`,
    so if these two disagree the build still succeeds and the first parse is what fails."""
    dockerfile = _DOCKERFILE.read_text(encoding="utf-8")
    declared = re.findall(r"^ENV HF_HOME=(\S+)", dockerfile, flags=re.MULTILINE)
    assert declared, "no `ENV HF_HOME=` in the Dockerfile — nothing points the libraries anywhere"
    assert all(Path(value) == ARTIFACTS_PATH for value in declared), declared


def test_the_compose_volume_mounts_onto_that_path() -> None:
    """The weights volume. A mount at any other target leaves the baked directory shadowed or
    the populated one unreachable, and neither says so."""
    compose = _COMPOSE.read_text(encoding="utf-8")
    assert f"models:{ARTIFACTS_PATH.as_posix()}" in compose


def test_the_stale_path_is_gone_from_the_service() -> None:
    """A regression guard with a name, because the old value is memorable and reappears in
    copy-pasted snippets. Prose in a comment explaining why it is wrong is fine; a constant is
    not."""
    for path in sorted((SERVICE_ROOT / "app").rglob("*.py")):
        source = path.read_text(encoding="utf-8")
        assert 'Path("/opt/docling-artifacts")' not in source, path
