"""Stage 11's call into the provider layer must be able to name its tenant (finding S11).

``app/providers/contract.py`` rejects the bare ``(query, passages, model)`` shape by name and
says why: *tenant isolation is enforced in code at every layer and a call that cannot name its
organization cannot be scoped, metered or traced.* ``app/rag/rerank.py`` declared a narrower
structural twin that did exactly that, and the organization survived only inside whichever
closure happened to build the callable.

Nothing leaked, because nothing binds these callables yet — which is the entire reason to fix
the shape now rather than after something does. Reranking sends a tenant's document text to a
vendor and bills that tenant's quota for it; all three identifiers belong on the request.

The credential is a different question and the answer has not changed: it is bound by the
provider layer and never reaches ``app/rag/``. These tests hold both halves at once — the
request names the tenant, and no parameter here names a secret.
"""

from __future__ import annotations

import inspect
import typing

from app.providers.contract import RerankRequest, RerankResult
from app.rag import rerank as stage11


def test_the_reranker_callable_takes_a_request_that_names_its_tenant() -> None:
    hints = typing.get_type_hints(stage11.Reranker.rerank)
    assert hints["req"] is RerankRequest
    assert hints["return"] is RerankResult


def test_the_reranker_callable_does_not_re_declare_the_wire_parameters() -> None:
    """A local twin of ``RerankAdapter.rerank`` is a second definition that can drift.

    The one that drifted carried ``query``, ``passages`` and ``model`` as loose arguments, which
    left no place for ``org_id``, ``trace_id`` or ``provider_connection_id``. Taking the
    contract's own request object removes the twin along with the gap — the per-vendor passage
    ceiling, the input-order guarantee, and the deliberate absence of ``top_n`` all come with it.
    """
    parameters = set(inspect.signature(stage11.Reranker.rerank).parameters)
    assert parameters == {"self", "req"}


def test_the_request_carries_every_identifier_a_scoped_call_needs() -> None:
    fields = set(RerankRequest.model_fields)
    assert {"org_id", "trace_id", "provider_connection_id"} <= fields


def test_stage_11_receives_the_scope_it_must_put_on_the_request() -> None:
    """The stage builds the ``RerankRequest``, so it has to be handed the three identifiers.

    They are ordinary arguments rather than something read from a module-level context, for the
    same reason ``tenant_filter`` takes its scope positionally: a worker process is pooled and
    an ambient organization is the previous request's organization until something overwrites
    it. *No* tenant set is the loud failure; the *previous* tenant still set is the silent one.
    """
    parameters = inspect.signature(stage11.rerank).parameters
    assert {"ctx", "trace_id", "provider_connection_id"} <= set(parameters)


def test_no_parameter_on_this_stage_is_named_for_a_credential() -> None:
    """Resolving and decrypting a provider credential is the provider layer's business, so
    nothing under ``app/rag/`` can hold a secret, log one, or serialize one into a span. The
    request object deliberately has no credential field either."""
    forbidden = {"credential", "api_key", "apikey", "secret", "token", "password"}
    for function in (stage11.rerank, stage11.rerank_gate, stage11.calibration_for):
        assert not forbidden & {
            name.casefold() for name in inspect.signature(function).parameters
        }, function.__name__
    assert not forbidden & {name.casefold() for name in RerankRequest.model_fields}
