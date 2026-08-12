"""Structural properties of ``POST /internal/v1/embedding/readiness`` — no transport needed.

The behaviour of the endpoint is pinned in ``tests/contract/test_embedding_readiness.py``,
over HTTP, because that is the only place strict-mode-from-a-parsed-dict and the error
envelope are real. What is here is everything that is a property of the *module* rather than
of a response, and each one is a rule an ordinary-looking edit would break silently:

* the handler resolves nothing from storage;
* the reply model is the selection module's own verdict object, not a copy of it;
* the request model is an inbound contract model and cannot carry a credential.
"""

from __future__ import annotations

import ast
from typing import Final

import pytest

from app.api.internal.v1.embedding import (
    MAX_CANDIDATES,
    EmbeddingReadinessRequest,
    router,
)
from app.providers.embedding_selection import EmbeddingReadiness
from tests.support.tree import module_to_path

MODULE: Final = module_to_path("app.api.internal.v1.embedding")

#: Anything that would let this handler answer from a datastore instead of from the body.
#: ``kb-provider-adapter-contract`` says this layer "resolves nothing from storage", and
#: ``kb-security-baseline`` and `kb-architecture-map` both put ``provider_connections`` on
#: Laravel's side of the seam — so a read here is the same boundary violation as a write.
FORBIDDEN_IMPORT_ROOTS: Final[tuple[str, ...]] = (
    "app.db",
    "psycopg",
    "qdrant_client",
    "redis",
    "boto3",
    "httpx",
)


def _imported_modules() -> set[str]:
    tree = ast.parse(MODULE.read_text(encoding="utf-8"), filename=str(MODULE))
    modules: set[str] = set()
    for node in ast.walk(tree):
        if isinstance(node, ast.ImportFrom) and node.module:
            modules.add(node.module)
        elif isinstance(node, ast.Import):
            modules.update(alias.name for alias in node.names)
    return modules


def test_the_import_scan_found_the_selection_module() -> None:
    """Positive control: a "no forbidden import" assertion passes over an empty set."""
    assert "app.providers.embedding_selection" in _imported_modules()


@pytest.mark.parametrize("root", FORBIDDEN_IMPORT_ROOTS)
def test_the_endpoint_resolves_nothing_from_storage(root: str) -> None:
    """The candidate set arrives in the body; nothing is looked up.

    The reflexive edit is "take an ``org_id`` and fetch the connections here, so Laravel does
    not have to send them". That reads as a simplification and is a boundary violation: Laravel
    owns the relational store, the row it would read is the one that carries the encrypted
    credential, and a data-plane read of a control-plane table has no policy and no audit row
    behind it. The shape of the request is what makes that edit unnecessary — and this is what
    makes it fail.
    """
    offenders = [
        name for name in _imported_modules() if name == root or name.startswith(root + ".")
    ]
    assert not offenders, (
        f"{MODULE.name} imports {offenders}; the readiness endpoint answers from the request "
        f"body and must resolve nothing from storage"
    )


def test_the_reply_model_is_the_selection_verdict_itself() -> None:
    """``response_model is EmbeddingReadiness`` — identity, not "looks the same".

    A projection class would be a second definition of the verdict, and the two would drift the
    first time a field is added to one of them. ``EmbeddingReadiness`` also carries the
    "exactly one of ``selected`` and ``explanation``" model validator; a copy that omitted it
    could serialize a verdict a caller could both render as a banner and act on.
    """
    route = next(r for r in router.routes if getattr(r, "path", "").endswith("/readiness"))
    assert route.response_model is EmbeddingReadiness


def test_the_request_model_is_an_inbound_contract_model() -> None:
    """``strict`` + ``forbid`` + ``frozen``, each doing a different job (`pydantic-contracts`).

    Losing ``forbid`` is the one with no symptom: a field Laravel renames evaporates, the
    endpoint answers 200 against a body it half-read, and ``X-KB-Config-Version`` still claims
    both planes hold the same snapshot.
    """
    config = EmbeddingReadinessRequest.model_config
    assert config.get("strict") is True
    assert config.get("extra") == "forbid"
    assert config.get("frozen") is True


def test_the_request_model_cannot_carry_a_credential() -> None:
    """Mirrors the import-time assertion in the module, and in ``embedding_selection``.

    Selection answers *which* connection, never *with what key*. On this model the stake is a
    step higher than in memory: a credential field here would be deserialized from an HTTP
    body and, on a validation failure, echoed back into a response.
    """
    banned = ("credential", "api_key", "secret", "token", "password", "key")
    for name in EmbeddingReadinessRequest.model_fields:
        assert not any(bad in name.lower() for bad in banned), name


def test_the_candidate_ceiling_is_bounded_and_generous() -> None:
    """A bound, not a policy: past it the request is refused as malformed rather than answered
    with a verdict about a configuration nothing examined. It has to sit far above any real
    organization, or the refusal becomes the common case."""
    assert 20 <= MAX_CANDIDATES <= 1000
