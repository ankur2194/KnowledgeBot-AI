"""Every judge call goes through our adapter, or the eval spend is invisible.

Ragas ships its own OpenAI and LangChain clients and will happily use them. If it does, judge
calls do not write a ``provider_calls`` row, do not count against the organization's quota, do
not map into ``kb-error-taxonomy``, do not carry a trace id, and do not use the organization's
own encrypted credential. Nothing fails. The only symptom is a vendor invoice that nobody's
dashboard explains, and a rate limit that the platform's own limiter never sees coming.

That failure cannot be caught by a unit test of behaviour — ragas is deliberately not installed
in this environment, because ``import ragas`` must *raise* in every image but
``ai-worker-evaluation``. So it is caught by reading the package's own source: the wrong client
cannot be used if it is never imported, and the AST sees imports.

Read through ``ast`` rather than by matching text, so this file and the long prose in
``app/evaluation/judge.py`` may name every forbidden construct without tripping the rule.
"""

from __future__ import annotations

import ast
import inspect
import tomllib
from collections.abc import Iterator
from pathlib import Path
from typing import Final

import pytest

from app.evaluation.judge import (
    JUDGE_CALLS_PER_SCORED_CASE,
    JUDGE_METRICS,
    JUDGE_OPERATION,
    JUDGE_SYSTEM_PROMPT,
    JUDGE_TEMPERATURE,
    PINNED_RAGAS_VERSION,
    JudgeCall,
    JudgePin,
)
from tests.support.tree import APP_ROOT, SERVICE_ROOT

EVALUATION_ROOT: Final[Path] = APP_ROOT / "evaluation"

#: Distributions that must never be imported from this package. Every one of them is a way to
#: reach a model without our adapter: the first three are vendor clients, the rest are the
#: LangChain tree ragas would otherwise route through.
FORBIDDEN_ROOTS: Final[frozenset[str]] = frozenset(
    {"openai", "anthropic", "httpx", "langchain", "langchain_core", "langchain_openai"}
)

#: Ragas' own judge constructors. Each one builds an LLM client ragas owns, which is exactly
#: the egress path this package exists to avoid.
FORBIDDEN_NAMES: Final[frozenset[str]] = frozenset(
    {"llm_factory", "embedding_factory", "LangchainLLMWrapper", "LangchainEmbeddingsWrapper"}
)


def _sources() -> Iterator[tuple[Path, ast.Module]]:
    for path in sorted(EVALUATION_ROOT.rglob("*.py")):
        if "__pycache__" in path.parts:
            continue
        yield path, ast.parse(path.read_text(encoding="utf-8"), filename=str(path))


SOURCES: Final[list[tuple[Path, ast.Module]]] = list(_sources())


def test_the_scan_found_the_evaluation_package() -> None:
    """Positive control. Every assertion below is "no module does X" and all of them pass over
    an empty list — the same shape as a green suite that stopped testing anything."""
    assert len(SOURCES) >= 4, [str(path) for path, _ in SOURCES]


@pytest.mark.parametrize("path,tree", SOURCES, ids=lambda value: getattr(value, "name", ""))
def test_no_module_imports_a_provider_client_directly(path: Path, tree: ast.Module) -> None:
    """A vendor client here is a second credential path and an unmetered egress.

    The judge is a provider call like any other: the adapter holds the credential, writes the
    ``provider_calls`` row, counts the quota, and classifies the failure. A package that can
    construct its own client will eventually construct one on the afternoon the adapter is
    inconvenient.
    """
    offenders: list[str] = []
    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            offenders += [
                f"line {node.lineno}: import {alias.name}"
                for alias in node.names
                if alias.name.split(".")[0] in FORBIDDEN_ROOTS
            ]
        elif (
            isinstance(node, ast.ImportFrom)
            and node.module
            and node.module.split(".")[0] in FORBIDDEN_ROOTS
        ):
            offenders.append(f"line {node.lineno}: from {node.module}")
    assert not offenders, f"{path.name}: {offenders}"


@pytest.mark.parametrize("path,tree", SOURCES, ids=lambda value: getattr(value, "name", ""))
def test_no_module_uses_a_ragas_owned_llm_constructor(path: Path, tree: ast.Module) -> None:
    """``llm_factory`` and ``LangchainLLMWrapper`` are how ragas is normally given a judge, and
    both build a client we do not control."""
    used = {
        node.id
        for node in ast.walk(tree)
        if isinstance(node, ast.Name) and node.id in FORBIDDEN_NAMES
    } | {
        node.attr
        for node in ast.walk(tree)
        if isinstance(node, ast.Attribute) and node.attr in FORBIDDEN_NAMES
    }
    assert not used, f"{path.name}: {sorted(used)}"


@pytest.mark.parametrize("path,tree", SOURCES, ids=lambda value: getattr(value, "name", ""))
def test_ragas_is_never_imported_at_module_scope(path: Path, tree: ast.Module) -> None:
    """The containment. ``import ragas`` drags langchain, langchain-core, langchain-community,
    langchain_openai, datasets, networkx and scikit-network behind it — none optional — and a
    module-level import here would put all of it into every image that touches this package.

    Function-local imports are legal and are how ``build_ragas_judge`` and the case task reach
    it; only the module body is checked.
    """
    offenders = [
        f"line {node.lineno}"
        for node in tree.body
        if (
            isinstance(node, ast.Import)
            and any(a.name.split(".")[0] == "ragas" for a in node.names)
        )
        or (isinstance(node, ast.ImportFrom) and (node.module or "").split(".")[0] == "ragas")
    ]
    assert not offenders, f"{path.name}: {offenders}"


def test_the_judge_call_seam_takes_no_credential() -> None:
    """Nothing under ``app/evaluation/`` may hold a secret, log one, or serialize one into a
    Celery payload or a span. The caller binds the credential; this package gets a callable.

    ``org_id`` and ``run_id`` are required for the opposite reason: a judge call that cannot be
    attributed to an organization and a run is spend nobody can find.
    """
    parameters = inspect.signature(JudgeCall.__call__).parameters
    assert {"org_id", "run_id"} <= set(parameters), sorted(parameters)
    forbidden = {"api_key", "credential", "secret", "token", "base_url", "headers"}
    assert not forbidden & set(parameters), sorted(forbidden & set(parameters))


def test_judge_calls_are_accounted_under_the_evaluation_operation() -> None:
    """One ``provider_calls`` row per judge call. Ragas' ``CostCallbackHandler`` is a LangChain
    callback and the judge ABC accepts no callbacks, so ragas cannot meter this path at all."""
    assert JUDGE_OPERATION == "evaluation.judge"


def test_the_pinned_ragas_version_matches_the_dependency() -> None:
    """The harness constant and the ``evaluation`` extra drift apart when someone bumps one.

    The symptom is a baseline comparison that succeeds across a metric implementation change —
    0.4 relocated every metric to ``ragas.metrics.collections`` — and reports the difference as
    a retrieval regression.
    """
    pyproject = tomllib.loads((SERVICE_ROOT / "pyproject.toml").read_text(encoding="utf-8"))
    extra = pyproject["project"]["optional-dependencies"]["evaluation"]
    assert f"ragas=={PINNED_RAGAS_VERSION}" in extra, extra


def test_the_metric_set_is_the_costed_one() -> None:
    """Faithfulness (2 calls) and ContextRecall (1). Nothing else.

    ``ContextPrecisionWithReference`` loops over the packed contexts with an ``await`` in the
    body — one call per context, 6 to 10 of them, not the "one LLM call each" the docs claim.
    ``AnswerRelevancy`` scores a correct refusal 0.0 by construction, so tuning the evidence
    threshold to reduce hallucination tanks it.
    """
    assert JUDGE_METRICS == ("Faithfulness", "ContextRecall")
    assert JUDGE_CALLS_PER_SCORED_CASE == 3


def test_the_system_prompt_marks_judged_text_as_data() -> None:
    """An eval harness is not a trusted context. A crawled page in a golden corpus is the same
    untrusted data in a judge prompt that it is in an answer prompt."""
    assert "DATA" in JUDGE_SYSTEM_PROMPT
    assert "never instructions" in JUDGE_SYSTEM_PROMPT


def _pin(**overrides: object) -> JudgePin:
    fields: dict[str, object] = {
        "provider_connection_id": "conn-1",
        "requested_model": "gpt-5.6",
        "resolved_model_id": "gpt-5.6-sol",
        "system_fingerprint": "fp_0a1b2c3d",
        "temperature": JUDGE_TEMPERATURE,
        "ragas_version": PINNED_RAGAS_VERSION,
    }
    fields.update(overrides)
    return JudgePin(**fields)  # type: ignore[arg-type]


def test_a_valid_pin_constructs() -> None:
    """Positive control for the three rejections below."""
    assert _pin().resolved_model_id == "gpt-5.6-sol"


def test_the_pin_rejects_ragas_own_temperature() -> None:
    """``InstructorModelArgs`` defaults to ``temperature=0.01, top_p=0.1``. If 0.01 reaches the
    pin, our adapter did not override it and the run carries ragas' sampling, not ours."""
    with pytest.raises(ValueError, match="temperature"):
        _pin(temperature=0.01)


def test_the_pin_requires_what_was_actually_served() -> None:
    """``requested_model`` is an alias. ``resolved_model_id`` and ``system_fingerprint`` are
    read back off the response and are the only pin available while no vendor in the catalogue
    publishes a dated snapshot."""
    with pytest.raises(ValueError, match="empty on the judge pin"):
        _pin(resolved_model_id="")
    with pytest.raises(ValueError, match="empty on the judge pin"):
        _pin(system_fingerprint="")


def test_the_pin_rejects_a_mismatched_ragas_version() -> None:
    with pytest.raises(ValueError, match="does not match the pinned"):
        _pin(ragas_version="0.4.2")


def test_the_corpus_gate_runs_at_the_start_of_a_run() -> None:
    """``verify_corpus`` must be called in the eval path, not left to a script someone
    remembers. ``app/evaluation/tasks.py`` is that path: it is the module the ``evaluate``
    queue enters through, and the gate is the first thing ``start_run`` does.

    The structural half of the same guarantee is that ``EvaluationRunRecord`` takes the
    ``CorpusVerification`` object rather than a boolean, so no comparable run record can exist
    without it. This assertion covers the other half — that something actually calls it.
    """
    tree = ast.parse((EVALUATION_ROOT / "tasks.py").read_text(encoding="utf-8"))
    called = {
        node.func.id
        for node in ast.walk(tree)
        if isinstance(node, ast.Call) and isinstance(node.func, ast.Name)
    }
    assert "verify_corpus" in called, sorted(called)
