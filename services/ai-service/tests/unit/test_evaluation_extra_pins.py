"""The ``evaluation`` extra must keep ragas importable, and keep it out of every other image.

Two properties, one dependency block, and both of them fail *late* without this file.

**Importability.** ragas 0.4.3 declares ``langchain-community`` with no version specifier and
then does, unconditionally at ``ragas/llms/base.py:12``,
``from langchain_community.chat_models.vertexai import ChatVertexAI``. That module is present
in langchain-community 0.4.1 and **gone from 0.4.2**, which sunset the package. Unbounded, uv
resolves 0.4.2 and ``import ragas`` raises ``ModuleNotFoundError`` — so ``ai-worker-evaluation``
is dead at boot and every job on the ``evaluate`` queue fails at import. The ceiling in
pyproject.toml is what prevents that.

**Containment.** ragas makes real, billable provider calls. It is an extra so that
``import ragas`` *raises* in ``ai-api`` and the ingestion workers; that containment stops being
real the moment it is promoted to a base dependency.

Both are asserted at image build time by ``services/ai-service/Dockerfile`` — and today
``.github/workflows/gates.yml`` is a gate-only tier that builds no image, so those assertions
run only on a developer's machine. Until an image-build job exists, this file is the automated
guard. It reads pyproject.toml with ``tomllib`` and imports nothing, so it runs on a bare
interpreter with no dependency installed at all — which is the only tier that *can* check a
dependency deliberately absent from the test environment.

**Delete the langchain-community assertions here the same day the pin goes.** When ragas ships
a release that no longer imports the vertexai shim, the ceiling and these two tests come out
together; leaving a test that outlives its constraint is how a stale pin acquires the authority
of a passing check.
"""

from __future__ import annotations

import tomllib
from typing import Any, Final

from tests.support.tree import SERVICE_ROOT

PYPROJECT: Final[dict[str, Any]] = tomllib.loads(
    (SERVICE_ROOT / "pyproject.toml").read_text(encoding="utf-8")
)

#: The release that removed ``langchain_community/chat_models/vertexai.py``. Verified against the
#: published wheels: present in 0.3.31, 0.4 and 0.4.1; absent from 0.4.2.
VERTEXAI_REMOVED_IN: Final[str] = "0.4.2"


def _extras() -> dict[str, list[str]]:
    return dict(PYPROJECT["project"]["optional-dependencies"])


def _requirement_names(specs: list[str]) -> set[str]:
    """Distribution names out of a requirement list, normalised per PEP 503.

    Crude on purpose — no ``packaging`` import, because this tier runs on a bare interpreter.
    Splitting on the first specifier/marker character is enough for a hand-written extra.
    """
    names: set[str] = set()
    for spec in specs:
        head = spec.split(";", 1)[0]
        for sep in ("==", ">=", "<=", "~=", "!=", ">", "<", "[", " "):
            head = head.split(sep, 1)[0]
        names.add(head.strip().lower().replace("_", "-"))
    return names


def test_the_evaluation_extra_exists() -> None:
    """Positive control. A renamed extra passes every assertion below by vacuity, and the
    Dockerfile's ``uv sync --extra evaluation`` would then be the thing that notices."""
    assert "evaluation" in _extras()


def test_ragas_is_not_a_base_dependency() -> None:
    """The containment, read off the manifest rather than off a built image.

    ragas in ``[project.dependencies]`` puts an LLM-judged harness that spends a tenant's
    provider quota one stray import away from the chat request path — whose entire SLO is
    first-token latency.
    """
    base = _requirement_names(list(PYPROJECT["project"]["dependencies"]))
    assert "ragas" not in base, (
        "ragas must stay in the `evaluation` extra. As a base dependency it ships in ai-api "
        "and every ingestion worker, and the Dockerfile assertion that `import ragas` fails "
        "in `runtime` will start failing the build."
    )


def test_ragas_is_pinned_exactly() -> None:
    """A judge harness on a floating version silently changes what a score means.

    ragas' metric internals move between patch releases (0.4 moved every metric to
    ``ragas.metrics.collections``), so a range here would let a rebuild change the number a
    regression gate compares against, with no diff to point at.
    """
    specs = _extras()["evaluation"]
    ragas = [s for s in specs if _requirement_names([s]) == {"ragas"}]
    assert len(ragas) == 1, specs
    assert "==" in ragas[0], f"ragas must be pinned exactly, not {ragas[0]!r}"


def test_langchain_community_is_bounded_below_the_release_that_broke_ragas() -> None:
    """Without this ceiling the evaluation image builds and then cannot import ragas.

    ragas declares ``langchain-community`` unversioned, so nothing upstream constrains it; the
    bound has to live here. See the prose beside the pin in pyproject.toml for why it is a
    ceiling and not a bump: 0.4.3 is the newest ragas and still imports the removed module.
    """
    specs = _extras()["evaluation"]
    bounds = [s for s in specs if _requirement_names([s]) == {"langchain-community"}]
    assert len(bounds) == 1, (
        "the `evaluation` extra must carry exactly one langchain-community bound; found "
        f"{bounds!r} in {specs!r}"
    )
    assert f"<{VERTEXAI_REMOVED_IN}" in bounds[0], (
        f"langchain-community must be held below {VERTEXAI_REMOVED_IN}, which removed "
        f"`langchain_community.chat_models.vertexai` — the module ragas 0.4.3 imports "
        f"unconditionally at ragas/llms/base.py:12. Found {bounds[0]!r}."
    )


def test_langchain_community_is_not_pulled_into_the_base_image() -> None:
    """The ceiling is scoped to the extra, so the whole langchain tree stays extra-gated.

    Declaring langchain-community at top level to 'fix the resolution' would put ~30 MB of
    sunset integration code into ai-api and the crawl worker, neither of which imports it.
    """
    base = _requirement_names(list(PYPROJECT["project"]["dependencies"]))
    assert "langchain-community" not in base
    assert not {n for n in base if n.startswith("langchain")}, sorted(base)
