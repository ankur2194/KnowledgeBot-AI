"""The four deletion-filter builders — the terms they name, and the four refusals.

Scoped by ``tests/unit/README.md``: this tier asserts on the **object** and never on a query
result. A filter that was built correctly and never executed proves nothing about isolation, so
the executed two-organization proof lives in ``tests/integration/test_deletion_filter_width.py``
against a real Qdrant. What is provable here is the shape — and the shape is where a deletion
filter goes wrong, because every one of the four failure modes below returns HTTP 200 and looks
in review exactly like the correct thing:

* a term in ``should``, which Qdrant's per-type clause merge widens into "this organization OR
  anything else";
* an empty ``must``, which the server evaluates with ``.all()`` and therefore matches every
  point of every tenant in the collection;
* a key that was skipped rather than refused, which drops a term and deletes wider;
* a narrowing argument that arrived empty and was tested for truthiness, which does not narrow
  less — it does not narrow at all.

Two organizations throughout, and they **share the identifiers below**. Distinct per-org ids
would let every assertion here pass against a builder that emits no organization term at all,
because nothing would collide; the collision is what makes the organization term load-bearing.
That is the same fixture design as ``tests/integration/test_sparse_statistics_purge.py``, for
the same reason: filter width is the one mistake in deletion with no repair.

The allow-list is read from ``DELETE_KEYS`` rather than restated. A test that restates it
drifts with the module it exists to pin and then agrees with whatever the module became.
"""

from __future__ import annotations

from typing import Final

import pytest
from qdrant_client import models

from app.deletion.filters import (
    DELETE_KEYS,
    assert_delete_key,
    deletion_filter,
    identity_filter,
    organization_filter,
)

#: Two organizations, and every identifier they SHARE. Org B is not decoration.
ORG_A: Final[str] = "01JQZ000000000000000000A"
ORG_B: Final[str] = "01JQZ000000000000000000B"
SOURCE: Final[str] = "01JQZ0000000000000000SRC"
ITEM: Final[str] = "01JQZ000000000000000ITEM"
VERSION_1: Final[str] = "01JQZ0000000000000000VE1"
VERSION_2: Final[str] = "01JQZ0000000000000000VE2"
CHUNK: Final[str] = "01JQZ00000000000000CHUNK"

#: Payload keys that name the material rather than an identifier. Not derived from
#: ``DELETE_KEYS`` — a derivation would agree with the tuple after somebody added one of these
#: to it, which is the edit these strings exist to catch.
CONTENT_ADDRESSED: Final[tuple[str, ...]] = (
    "text",
    "content",
    "content_hash",
    "chunk_text",
    "body",
    "title",
    "heading",
    "url",
    "name",
)


def keys_of(built: models.Filter) -> list[str]:
    """The payload keys of ``must``, in order.

    Asserts ``must`` is populated before reading it, so a builder that moved its terms into
    ``should`` — or emitted none at all — fails here rather than returning an empty list that
    every membership assertion below would then vacuously satisfy.
    """
    assert built.must is not None, "the filter has no `must` clause at all"
    assert built.must, "`must` is empty, and Filter(must=[]) is a match-all"
    return [
        condition.key for condition in built.must if isinstance(condition, models.FieldCondition)
    ]


def match_of(built: models.Filter, key: str) -> models.Match | None:
    for condition in built.must or []:
        if isinstance(condition, models.FieldCondition) and condition.key == key:
            return condition.match
    return None


# ── assert_delete_key: the check CI is structurally unable to perform ────────


def test_every_allow_listed_key_passes_through_unchanged() -> None:
    """It returns the key so it can be inlined at the point of use.

    Returning anything else — a normalized spelling, a default — would let the call site read
    as checked while the filter names something the allow-list never approved.
    """
    for key in DELETE_KEYS:
        assert assert_delete_key(key) == key


def test_a_key_outside_the_allow_list_raises_rather_than_being_returned() -> None:
    """The mechanism: **raise, never skip.**

    A skip — returning the key, returning a sentinel, returning early — drops one term from the
    ``must`` list, and a delete with one fewer term is a delete over a wider set. A
    narrower-than-intended delete is a retry; a wider one is unrecoverable, so this refusal is
    the one behaviour in the module that may not degrade.
    """
    for key in CONTENT_ADDRESSED:
        with pytest.raises(ValueError, match="is not a deletion key"):
            assert_delete_key(key)


def test_a_blank_key_is_refused_like_any_other_unknown_key() -> None:
    """``FieldCondition(key="")`` is accepted by the client and matches no point, so a blank key
    reaching the store is a term that silently stopped narrowing anything."""
    with pytest.raises(ValueError):
        assert_delete_key("")


def test_the_refusal_names_the_allow_list_so_the_next_person_can_act_on_it() -> None:
    with pytest.raises(ValueError) as raised:
        assert_delete_key("content_hash")
    message = str(raised.value)
    assert "content_hash" in message
    for key in DELETE_KEYS:
        assert key in message


# ── identity_filter: the bare identity the proof is built on ────────────────


def test_the_identity_filter_is_exactly_the_organization_and_the_source() -> None:
    assert keys_of(identity_filter(ORG_A, SOURCE)) == ["org_id", "source_id"]


def test_the_identity_filter_carries_no_status_and_no_version_term() -> None:
    """The tautology guard, and it is the whole reason this function is separate.

    Retrieval's filter already excludes inactive status and non-active versions. A proof built
    on either term asks "are any *active* points left for a source I marked inactive an hour
    ago?" — the answer is no before phase 2 runs at all. The check would then pass on day one,
    pass forever, and never again catch a purge that silently no-opped.
    """
    built = identity_filter(ORG_A, SOURCE)
    assert "source_status" not in keys_of(built)
    assert "source_version_id" not in keys_of(built)


def test_the_identity_filter_puts_both_terms_in_must_and_nothing_anywhere_else() -> None:
    built = identity_filter(ORG_A, SOURCE)
    assert built.should is None
    assert built.must_not is None
    assert built.min_should is None


def test_two_organizations_naming_the_same_source_build_different_identity_filters() -> None:
    """The organization term is redundant for correctness and mandatory for safety.

    Both organizations name the same ``source_id`` string here on purpose: with the
    organization term, a source id that belongs to somebody else matches nothing; without it,
    it matches their source, and that delete is not recoverable.
    """
    assert identity_filter(ORG_A, SOURCE) != identity_filter(ORG_B, SOURCE)
    assert match_of(identity_filter(ORG_A, SOURCE), "org_id") == models.MatchValue(value=ORG_A)
    assert match_of(identity_filter(ORG_B, SOURCE), "org_id") == models.MatchValue(value=ORG_B)


@pytest.mark.parametrize(("org_id", "source_id"), [("", SOURCE), (ORG_A, "")])
def test_a_blank_identifier_is_refused_rather_than_matched(org_id: str, source_id: str) -> None:
    with pytest.raises(ValueError, match="blank"):
        identity_filter(org_id, source_id)


# ── deletion_filter: narrowest intent, always ANDed with the organization ────


def test_every_narrowing_level_leads_with_the_organization_term() -> None:
    for built in (
        deletion_filter(ORG_A, source_id=SOURCE),
        deletion_filter(ORG_A, source_item_id=ITEM),
        deletion_filter(ORG_A, source_version_ids=[VERSION_1]),
        deletion_filter(ORG_A, chunk_id=CHUNK),
    ):
        assert keys_of(built)[0] == "org_id"
        assert match_of(built, "org_id") == models.MatchValue(value=ORG_A)


def test_each_narrowing_argument_contributes_exactly_its_own_term() -> None:
    assert keys_of(deletion_filter(ORG_A, source_id=SOURCE)) == ["org_id", "source_id"]
    assert keys_of(deletion_filter(ORG_A, source_item_id=ITEM)) == ["org_id", "source_item_id"]
    assert keys_of(deletion_filter(ORG_A, source_version_ids=[VERSION_1])) == [
        "org_id",
        "source_version_id",
    ]
    assert keys_of(deletion_filter(ORG_A, chunk_id=CHUNK)) == ["org_id", "chunk_id"]


def test_several_narrowing_arguments_are_all_conjoined_none_dropped() -> None:
    built = deletion_filter(
        ORG_A, source_id=SOURCE, source_item_id=ITEM, source_version_ids=[VERSION_1]
    )
    assert keys_of(built) == ["org_id", "source_id", "source_item_id", "source_version_id"]


def test_every_term_sits_in_must_and_never_in_should_or_must_not() -> None:
    """The widening this module exists to prevent.

    Qdrant merges two filters by concatenating their clause lists **per type**, so a tenant term
    that ends up in ``should`` is appended to whatever ``should`` the other filter carried and
    the conjunction becomes "this organization OR anything else". ``must_not`` fails the other
    way: a ``match`` is not satisfied by a point missing the key, so a point whose payload lost
    ``org_id`` is invisible to everyone under a positive term and visible to everyone under a
    negative one.
    """
    built = deletion_filter(ORG_A, source_id=SOURCE, source_version_ids=[VERSION_1, VERSION_2])
    assert built.must is not None
    assert len(built.must) == 3
    assert all(isinstance(condition, models.FieldCondition) for condition in built.must)
    assert built.should is None
    assert built.must_not is None
    assert built.min_should is None


def test_no_narrowing_argument_raises_rather_than_returning_a_match_all() -> None:
    """``Filter(must=[])`` is a **match-all**: the server checks ``must`` with ``.all()`` and
    ``.all()`` of an empty list is true.

    So the failure mode of returning here instead of raising is not "a filter that matches
    nothing" — it is a full-collection delete wearing the shape of a scoped one. Widening is a
    real operation and it has its own name; this function is not it.
    """
    with pytest.raises(ValueError, match="no narrowing term"):
        deletion_filter(ORG_A)


def test_a_narrowing_argument_that_arrived_empty_raises_rather_than_widening() -> None:
    """``""`` and ``[]`` are falsy, so a truthiness test in place of ``is not None`` skips the
    term entirely — and a request to remove one version leaves this function as a request to
    remove the organization. Supplied-but-empty is refused by name instead."""
    with pytest.raises(ValueError, match="blank source_id"):
        deletion_filter(ORG_A, source_id="")
    with pytest.raises(ValueError, match="blank chunk_id"):
        deletion_filter(ORG_A, chunk_id="")
    with pytest.raises(ValueError, match="empty or blank-valued source_version_id"):
        deletion_filter(ORG_A, source_version_ids=[])
    with pytest.raises(ValueError, match="empty or blank-valued source_version_id"):
        deletion_filter(ORG_A, source_version_ids=["", ""])


def test_an_empty_argument_beside_a_populated_one_is_refused_and_not_silently_dropped() -> None:
    """The combination is the dangerous one, and the single-argument cases above do not cover it.

    With one narrowing argument, a truthiness test still ends in a refusal — the wrong one, but a
    refusal. With two, the empty one is skipped and the populated one keeps the guard quiet, so
    the filter that reaches the store is missing a term nobody can see was requested. That is the
    widening, and it is the shape a resolver returning `""` for a source actually takes.
    """
    with pytest.raises(ValueError, match="blank source_id"):
        deletion_filter(ORG_A, source_id="", chunk_id=CHUNK)
    with pytest.raises(ValueError, match="empty or blank-valued source_version_id"):
        deletion_filter(ORG_A, source_id=SOURCE, source_version_ids=[])


def test_a_blank_organization_is_refused_even_when_a_narrowing_term_was_supplied() -> None:
    """The organization term is not the one that may be skipped because something else is
    present. A caller that reached here having lost its tenant is a caller whose next statement
    may not be so forgiving."""
    with pytest.raises(ValueError, match="blank org_id"):
        deletion_filter("", source_id=SOURCE)


def test_repeated_version_ids_collapse_and_keep_the_caller_order() -> None:
    """A resolver that returned a version twice must not produce two identical terms, and the
    order is preserved so a failure message reads in the order the caller resolved them."""
    built = deletion_filter(ORG_A, source_version_ids=[VERSION_2, VERSION_1, VERSION_2])
    assert match_of(built, "source_version_id") == models.MatchAny(any=[VERSION_2, VERSION_1])


def test_two_organizations_naming_the_same_source_build_different_deletion_filters() -> None:
    assert deletion_filter(ORG_A, source_id=SOURCE) != deletion_filter(ORG_B, source_id=SOURCE)


def test_no_builder_can_name_a_key_outside_the_allow_list() -> None:
    """Every key any builder emits is one ``assert_delete_key`` approved.

    This is the assertion CI cannot make: the gate subtracts this module by path precisely
    because its keys are built from variables, so the allow-list is enforced here or nowhere.
    """
    built = [
        identity_filter(ORG_A, SOURCE),
        organization_filter(ORG_A),
        deletion_filter(
            ORG_A,
            source_id=SOURCE,
            source_item_id=ITEM,
            source_version_ids=[VERSION_1],
            chunk_id=CHUNK,
        ),
    ]
    for filter_ in built:
        for key in keys_of(filter_):
            assert key in DELETE_KEYS
            assert key not in CONTENT_ADDRESSED


# ── organization_filter: the one that says what it does ─────────────────────


def test_the_organization_filter_is_one_positive_term_in_must() -> None:
    built = organization_filter(ORG_A)
    assert keys_of(built) == ["org_id"]
    assert match_of(built, "org_id") == models.MatchValue(value=ORG_A)
    assert built.should is None
    assert built.must_not is None


def test_the_organization_filter_refuses_a_blank_organization() -> None:
    """A blank organization is not a wildcard — ``MatchValue("")`` matches nothing — but it is
    also not an organization purge, and the two must not share a code path."""
    with pytest.raises(ValueError, match="blank org_id"):
        organization_filter("")


def test_org_wide_is_reachable_only_by_its_own_name() -> None:
    """The pair of behaviours, asserted together because the point is the contrast: defaulting
    every narrowing argument of ``deletion_filter`` raises, and the same intent spelled as
    ``organization_filter`` builds. A diff that widens a delete then has to contain the word."""
    with pytest.raises(ValueError):
        deletion_filter(ORG_A)
    assert keys_of(organization_filter(ORG_A)) == ["org_id"]
