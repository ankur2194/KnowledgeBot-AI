"""``tenant_filter`` construction — the four terms, ``must``, and the empty-scope refusal.

Scoped by ``tests/unit/README.md``: this tier asserts on the **object** and never on a query
result. A filter that was built correctly and never executed proves nothing about isolation,
so the two-organization leak test belongs to ``tests/security/`` against a real Qdrant. What
is provable here is the shape, and the shape is where every reviewed-and-still-wrong filter
in the literature goes wrong: a term in ``should``, a term expressed negatively, a term
appended inside an ``if``, or an empty ``must`` that the server reads as match-all.

The keys are read from ``MANDATORY_FILTER_KEYS`` rather than restated as four string
literals. A test that restates them drifts with the module it is meant to pin and then agrees
with whatever the module became.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Final

import pytest
from qdrant_client import models

from app.retrieval.tenancy import (
    ACTIVE_SOURCE_STATUSES,
    MANDATORY_FILTER_KEYS,
    EmptyScopeError,
    tenant_filter,
)

VERSIONS: Final[tuple[str, ...]] = ("01JQZ0000000000000000VER1", "01JQZ0000000000000000VER2")


@dataclass(frozen=True)
class Ctx:
    """Structurally a ``TenantContext``.

    Two organizations are constructed below and neither is a default. A one-organization
    fixture cannot fail a scoping test — it passes while proving nothing — and although the
    isolation claim itself is not this tier's to make, the fixture habit is.
    """

    org_id: str
    bot_id: str | None = "01JQZ00000000000000000BOT"


ORG_A: Final = Ctx(org_id="01JQZ000000000000000000A")
ORG_B: Final = Ctx(org_id="01JQZ000000000000000000B")


def keys_of(built: models.Filter) -> list[str]:
    assert built.must is not None
    return [
        condition.key for condition in built.must if isinstance(condition, models.FieldCondition)
    ]


# ── the four terms, in the order the module states them ──────────────────────


def test_all_four_mandatory_terms_are_present() -> None:
    assert keys_of(tenant_filter(ORG_A, VERSIONS)) == list(MANDATORY_FILTER_KEYS)


def test_the_four_terms_are_the_whole_of_must() -> None:
    """No fifth condition, and no nested ``Filter`` smuggling one of the four somewhere else.

    A term that reaches the server inside a nested clause is a term a reviewer counted and the
    server evaluated differently.
    """
    built = tenant_filter(ORG_A, VERSIONS)
    assert built.must is not None
    assert len(built.must) == len(MANDATORY_FILTER_KEYS)
    assert all(isinstance(condition, models.FieldCondition) for condition in built.must)


def test_every_term_sits_in_must_and_nothing_sits_in_should_or_must_not() -> None:
    """``should`` is an OR, and Qdrant merges filters by concatenating clause lists per type.

    A tenant term dropped into ``should`` is appended to whatever ``should`` a sub-query
    already carried and widens into ``tenant OR anything_else``. ``must_not`` is worse: a
    ``match`` is not satisfied by a point missing the key, so a point whose payload lost
    ``org_id`` is visible to nobody under a positive filter and to everybody under a negative
    one.
    """
    built = tenant_filter(ORG_A, VERSIONS)
    assert built.should is None
    assert built.must_not is None
    assert built.min_should is None


def test_the_organization_term_matches_the_authenticated_organization_exactly() -> None:
    built = tenant_filter(ORG_A, VERSIONS)
    assert built.must is not None
    org = built.must[0]
    assert isinstance(org, models.FieldCondition)
    assert org.match == models.MatchValue(value=ORG_A.org_id)


def test_two_organizations_build_two_different_filters() -> None:
    """The construction is a pure function of the scope it was handed, and of nothing ambient.

    A worker process is pooled, so the failure this guards is not "no organization set" — that
    one is loud — but the previous request's organization still being set.
    """
    assert tenant_filter(ORG_A, VERSIONS) != tenant_filter(ORG_B, VERSIONS)


def test_the_bot_term_is_a_membership_match_against_the_payload_array() -> None:
    built = tenant_filter(ORG_A, VERSIONS)
    assert built.must is not None
    bots = built.must[1]
    assert isinstance(bots, models.FieldCondition)
    assert bots.match == models.MatchAny(any=[ORG_A.bot_id])


def test_the_status_term_names_the_active_statuses_positively() -> None:
    """Positively, never as a subtraction of the failed and disabled states.

    A state nobody has thought of yet — a new lifecycle value, a payload rewritten by a
    half-finished job — is excluded by absence under a positive match and admitted by default
    under a negative one.
    """
    built = tenant_filter(ORG_A, VERSIONS)
    assert built.must is not None
    statuses = built.must[2]
    assert isinstance(statuses, models.FieldCondition)
    assert statuses.match == models.MatchAny(any=list(ACTIVE_SOURCE_STATUSES))


def test_the_version_term_carries_the_resolved_active_version_set() -> None:
    built = tenant_filter(ORG_A, VERSIONS)
    assert built.must is not None
    versions = built.must[3]
    assert isinstance(versions, models.FieldCondition)
    assert versions.match == models.MatchAny(any=list(VERSIONS))


def test_the_version_set_is_copied_rather_than_aliased() -> None:
    """The caller's sequence must not be able to change a filter that was already built.

    ``allowed_version_ids`` is resolved per request and handed in; a filter holding the
    caller's own list is one ``.clear()`` away from a match-nothing condition, and no code
    path would raise.

    **The copy is pydantic's, not ours** — ``MatchAny`` validates ``any`` into a fresh list,
    so removing the ``list(...)`` in ``tenant_filter`` does not break this. Stated rather than
    quietly relied on, because it is exactly the kind of guarantee that a switch to
    ``model_construct`` (which skips validation and would alias) removes without a diff to the
    filter itself. This test is the thing that would notice.
    """
    mutable = list(VERSIONS)
    built = tenant_filter(ORG_A, mutable)
    mutable.clear()
    assert built.must is not None
    versions = built.must[3]
    assert isinstance(versions, models.FieldCondition)
    assert versions.match == models.MatchAny(any=list(VERSIONS))


# ── the empty scope, which is a refusal and never a wider filter ─────────────


def test_a_missing_organization_raises_rather_than_widening() -> None:
    with pytest.raises(EmptyScopeError, match="org_id"):
        tenant_filter(Ctx(org_id=""), VERSIONS)


def test_a_missing_bot_raises_rather_than_matching_nothing() -> None:
    """``MatchAny(any=[None])`` is a legal condition that matches no point.

    It ships a filter, passes every structural check, and returns zero candidates forever —
    which reads in a trace exactly like a bot whose sources have not finished processing. The
    refusal has to happen here, where it can still say why.
    """
    with pytest.raises(EmptyScopeError, match="bot_id"):
        tenant_filter(Ctx(org_id=ORG_A.org_id, bot_id=None), VERSIONS)


def test_an_empty_bot_identifier_is_as_missing_as_an_absent_one() -> None:
    with pytest.raises(EmptyScopeError, match="bot_id"):
        tenant_filter(Ctx(org_id=ORG_A.org_id, bot_id=""), VERSIONS)


def test_an_empty_active_version_set_raises_rather_than_omitting_the_term() -> None:
    """The one that produces a match-all if it is handled by omission instead.

    Dropping the term leaves three conditions in ``must``, which is a filter that reviews as
    correct and retrieves every version the organization ever indexed, including the ones a
    delete was supposed to have removed from the answer path.
    """
    with pytest.raises(EmptyScopeError, match="allowed_version_ids"):
        tenant_filter(ORG_A, [])


def test_every_missing_part_of_the_scope_is_named_in_one_refusal() -> None:
    """A caller that lost the whole scope should not have to fix it one exception at a time."""
    with pytest.raises(EmptyScopeError) as raised:
        tenant_filter(Ctx(org_id="", bot_id=None), [])
    message = str(raised.value)
    assert "org_id" in message
    assert "bot_id" in message
    assert "allowed_version_ids" in message


# ── the shape of the signature, which is the part a future caller can undo ────


def test_the_filter_cannot_be_built_without_being_handed_a_scope() -> None:
    """No default, no ``Optional``, no relaxing keyword. An unfiltered call is unrepresentable.

    Reflection rather than prose, because the property that matters is that nobody can add a
    default later without this failing.
    """
    import inspect

    parameters = inspect.signature(tenant_filter).parameters
    assert list(parameters) == ["ctx", "allowed_version_ids"]
    for parameter in parameters.values():
        assert parameter.default is inspect.Parameter.empty, parameter.name
        assert parameter.kind is inspect.Parameter.POSITIONAL_OR_KEYWORD, parameter.name
