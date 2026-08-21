"""The delete-key allow-list, and the one module allowed to name a payload key from a variable.

**What used to enforce this, and what does now.** A `gates.yml` job greped the whole data plane
for `FieldCondition(` with a `key=`, extracted every key literal named on the matched line and
tested each one against `DELETE_KEYS` separately — per occurrence rather than per line, which was
itself a fix: the earlier subtraction dropped a whole line because *something* on it was
allow-listed, so a `must=[…]` holding an allow-listed condition and a content-addressed one, the
way a formatter leaves a short list, laundered the second key behind the first. **That job was
deleted with `.github/` on 2026-08-17 and nothing replaced it.** No scan of any kind now reads the
rest of the data plane looking for a payload key that names content. This paragraph is history,
and it is kept because the arrangement it describes is still the arrangement — what changed is
who checks it.

What checks it today, exhaustively:

* `assert_delete_key` below, at runtime, on every key any builder here emits. It is the only
  check that can see a key built from a variable at all — see its own docstring.
* The two `assert` statements at the foot of this file, which run at import in the process whose
  job is destroying data.
* `tests/unit/test_delete_keys.py`, which pins the tuple positionally, holds it against
  `app/retrieval/collection.py`'s `PAYLOAD_INDEXES` and `app/retrieval/tenancy.py`'s
  `MANDATORY_FILTER_KEYS`, and asserts no content-addressed name is a member.
* `tests/unit/test_deletion_filters.py`, which walks every filter every builder here returns and
  asserts each key it finds is allow-listed and is not content-addressed.

**Checked by nothing:** a `FieldCondition(key="text")`, a `MatchText`, or a delete or exact-count
call taking a text or content-hash keyword argument, written anywhere in the data plane outside
this module. That is a correct statement of the state, not a to-do this file can discharge — the
four checks above are all in-process or in-suite and none of them can see another package's
source. Two files still shape their code for the vanished grep, keeping `key=` on the same
physical line as its `FieldCondition(` because "grep is line-based":
`app/retrieval/tenancy.py:158` and `app/ingestion/indexing/upserter.py:354`. Neither is this
module's to edit; both are recorded here rather than corrected.

Two properties of the old exemption that outlive it, because the tests above are written against
the same arrangement the gate was:

* **The path is load-bearing.** The gate subtracted this module by its literal path
  (`grep -vF 'app/deletion/filters.py'`). Nothing subtracts anything now, but every reference
  above names this module by path or by import; move or rename it and they go stale together.
* **A second module that builds a payload key from a variable is a review stop, not a merge.**
  The arrangement was affordable because exactly one module can construct keys and that module is
  unit-tested. With no scan left, "exactly one" is the whole of the remaining structure.

What earns that position is `DELETE_KEYS` together with `tests/unit/test_delete_keys.py`. The
tuple is the allow-list expressed in code; that test is the only thing in the repository that
fails when someone edits it, and its own docstring records the same deletion from the other side.
"""

from __future__ import annotations

from collections.abc import Sequence
from typing import Final

# A runtime import, as the comment that stood here said it would become "in the same change as
# the first real body". It was type-only while the bodies raised, so that `DELETE_KEYS` — the
# allow-list, which a deleted CI job read and two unit tests read now — could be imported by a
# unit test without pulling a vector client into a test that never talks to a vector store. The
# builders below *construct* `models.Filter` objects rather than merely annotating one, so the
# name has to exist when a worker calls them.
# The tuple is still importable and the test still opens no socket: importing `qdrant_client`
# connects to nothing.
from qdrant_client import models

__all__ = [
    "DELETE_KEYS",
    "assert_delete_key",
    "deletion_filter",
    "identity_filter",
    "organization_filter",
]

#: Every payload key a deletion filter may name. SEVEN — and the seventh is the whole point
#: of writing the list down.
#:
#: Six of them are `kb-tenancy-isolation`'s payload contract: the fields every point carries
#: so that a tenant filter is expressible at all. The seventh, `chunk_id`, is deliberately not
#: one of them. It rides in the §14.6 payload superset, never in the mandatory query terms,
#: because deletion addresses a single chunk **by its own identity** — one element lost to a
#: re-parse, one chunk withdrawn by hand — and no query ever can: a query does not know a
#: chunk id until it has already retrieved the chunk.
#:
#: The two numbers are unequal on purpose, and reconciling them breaks something either way.
#: Drop `chunk_id` to "simplify this down to the payload contract" and single-chunk deletion
#: disappears silently — the builder raises on an unrecognised key, at runtime, inside a
#: worker, on the one path nobody exercises by hand. Promote `chunk_id` into the mandatory
#: retrieval filter instead and retrieval breaks, because the term can never be satisfied.
#: Neither is visible in a diff. `tests/unit/test_delete_keys.py` is the only thing that
#: catches either, which is why that test exists and why it asserts against the seven.
#:
#: `bot_ids` is here because it belongs to the payload contract, not because unassigning a
#: bot is a delete. Unassigning is never a delete: the assignment row changes and the points
#: stay, so re-assigning is instant.
DELETE_KEYS: Final[tuple[str, ...]] = (
    "org_id",
    "bot_ids",
    "source_id",
    "source_item_id",
    "source_version_id",
    "source_status",
    "chunk_id",
)

# ── the rule the allow-list exists to enforce ────────────────────────────────
# NEVER target a deletion at chunk contents, at any payload string a user typed, or at a
# content hash. Headers, disclaimers, licence blocks and pricing tables repeat verbatim
# across documents and across tenants, so a filter that matches on them, issued against one
# source, cuts a hole in a different source in an organization nobody touched. Nothing
# errors. The delete returns 200, every remaining tally stays plausible, and it is first
# observed weeks later as a bot that stopped citing a clause nobody edited — with no trace
# back to the delete that did it, because the delete recorded identifiers it never used.
#
# This was enforced separately from the allow-list above, because a variable key is invisible
# to an allow-list over literals: a second and a third grep looked across the whole data plane
# for Qdrant's full-text match condition, and for any delete or exact-count call taking a text,
# content or content-hash keyword argument. Both ran over this file too — the path exemption
# covered only the `FieldCondition` key check. **Both went with `.github/` on 2026-08-17 and
# nothing performs them now.** Inside this module the rule is enforced by `assert_delete_key`,
# which refuses any key that is not an identifier PostgreSQL issued, and by the two tests the
# module docstring names. Outside it, this rule is carried by review alone: no test in the
# repository reads another package's source, so a full-text condition written in `app/retrieval/`
# or `app/ingestion/` would be caught by a reader or not at all.
#
# The same rule is why verification never searches for the removed content to prove it is
# gone: that is this banned shape wearing a proof's clothes. Absence is asserted against the
# identifier, always.


def assert_delete_key(key: str) -> str:
    """Gate every dynamically constructed payload key. Returns the key, so it can be inlined.

    **A grep over string literals is structurally unable to perform this check**, and that point
    survives the gate that used to make it: a key built from a variable — a loop over field names,
    an argument, a value read from config — names anything at all and matches no pattern over
    source text. It is why this module was the one path the deleted CI job exempted, and it is why
    this function is now the whole of the enforcement rather than the part of it that ran in the
    worker. Enforce the allow-list here, once, before a key can reach a filter.

    Raise on an unknown key — never skip it. Skipping drops one term from the `must` list and
    the delete then proceeds *wider* than intended, which is the exact failure this module
    exists to make impossible. A narrower-than-intended delete is a retry; a wider one is
    unrecoverable.
    """
    if key not in DELETE_KEYS:
        raise ValueError(
            f"{key!r} is not a deletion key. The allow-list is {list(DELETE_KEYS)} and it is an "
            "allow-list rather than a deny-list because the next payload field somebody adds "
            "cannot be anticipated. If the key is genuinely new, add it to DELETE_KEYS and to "
            "CI_ALLOW_LIST in tests/unit/test_delete_keys.py in the same change; far more often "
            "the answer is that this deletion is addressing something the material carries "
            "rather than an identifier PostgreSQL issued, and there is no key for that on "
            "purpose"
        )
    return key


def _value_term(key: str, value: str) -> models.FieldCondition:
    """One equality term: an allow-listed key, and a value that is present at all.

    The blank check is not defensive noise, and it is here rather than at each call site so
    that there is one of it. A blank identifier reaching a delete is a caller that lost its
    scope somewhere upstream, and both of the quiet ways to treat it are wrong in opposite
    directions: `MatchValue` on `""` matches no point, so the purge is a no-op and the tally
    that proves it reports zero and agrees; while a caller — or a later edit here — that tests
    the argument for truthiness instead of for `None` drops the term altogether, and a dropped
    term is the widening. Refusing is the only outcome that says which of the two happened.
    """
    if not value:
        raise ValueError(
            f"refusing to build a deletion term on a blank {key}: an identifier that arrived "
            "empty is a caller that lost its scope, and the filter it produces is either a "
            "silent no-op whose proof agrees with it, or — if the term is dropped instead of "
            "built — wider than anything the caller asked for"
        )
    return models.FieldCondition(key=assert_delete_key(key), match=models.MatchValue(value=value))


def _any_term(key: str, values: Sequence[str]) -> models.FieldCondition:
    """One membership term over identifiers, deduplicated with the caller's order preserved.

    An empty sequence raises rather than becoming `MatchAny(any=[])`. Two reasons, and the
    second is the one with no repair. An empty membership term matches no point, so the delete
    removes nothing and the tally that proves it reports zero — the purge and its proof agreeing
    on an answer neither measured, which is the same refusal `relational.version_scope` makes
    for `= ANY('{}')`. And an empty list is *falsy*: a caller testing it for truthiness rather
    than for `None` skips the term, and the filter that reaches the store is then scoped to the
    organization alone by a delete that asked for one version.
    """
    ids = list(dict.fromkeys(values))
    if not ids or not all(ids):
        raise ValueError(
            f"refusing to build a deletion term on an empty or blank-valued {key} list: a "
            "resolution that came back with nothing is not a scope. The delete would address no "
            "point and the tally proving it would report zero, so the purge and its proof would "
            "agree without either of them having measured anything"
        )
    return models.FieldCondition(key=assert_delete_key(key), match=models.MatchAny(any=ids))


def identity_filter(org_id: str, source_id: str) -> models.Filter:
    """`org_id` AND `source_id`, and nothing else — the bare identity.

    Used in exactly two places: the orphan sweep that follows the id-addressed vector delete,
    and the proof that runs afterwards. It must carry no `source_status` and no
    `source_version_id` term.

    Adding either turns verification into a tautology that passes forever. Retrieval's filter
    already excludes inactive status and non-active versions, so a proof built on it asks
    "are any *active* points left for a source I already marked inactive?" — the answer is no
    before phase 2 runs at all, and the check has never caught anything since.

    Deliberately not written as `deletion_filter(org_id, source_id=source_id)`, which produces
    the identical two terms today. The two functions would then share a fate: a term added to
    the general builder — a `source_status`, a default anyone thinks is a safe narrowing —
    would silently propagate into the proof and make it the tautology described above, with no
    diff on this function at all. Two terms, spelled out, is the version that cannot drift.
    """
    return models.Filter(must=[_value_term("org_id", org_id), _value_term("source_id", source_id)])


def deletion_filter(
    org_id: str,
    *,
    source_id: str | None = None,
    source_item_id: str | None = None,
    source_version_ids: Sequence[str] | None = None,
    chunk_id: str | None = None,
) -> models.Filter:
    """Build the narrowest filter expressing the intent, always ANDed with `org_id`.

    Target the narrowest level that matches the intent: `source_version_id` to retire a prior
    version after a recrawl (the id is exact and known), `source_item_id` for one crawled page
    that disappeared, `source_id` for a source delete — **not** its list of version ids, which
    goes stale the moment a recrawl activates a new one.

    `org_id` is redundant for correctness once a `source_id` is in hand, and mandatory for
    safety: with it, a wrong `source_id` matches nothing; without it, a wrong `source_id`
    matches someone else's source, and that mistake is not recoverable.

    Every term goes in `must`. Never `should` — Qdrant merges filters by concatenating clause
    lists per type, so a tenant term appended to a `should` widens into "this org OR anything
    else". And `Filter(must=[])` is a **match-all**, because `all()` over an empty list is
    true, so this raises when every narrowing argument is None rather than quietly returning
    a filter that selects the collection. Widening lives in `organization_filter`, under its
    own name, where a reviewer can see the word.
    """
    # Built first, so a caller that lost its organization is refused before any narrowing term
    # is constructed and regardless of which of them it supplied.
    org_term = _value_term("org_id", org_id)

    # `is not None`, never truthiness. `""` and `[]` are falsy, and a truthiness test on a
    # supplied-but-empty narrowing argument does not narrow *less* — it does not narrow at all,
    # and the request to remove one version leaves as a request to remove the organization.
    # Supplied-but-empty is refused inside the term builders instead, by name.
    narrowing: list[models.FieldCondition] = []
    if source_id is not None:
        narrowing.append(_value_term("source_id", source_id))
    if source_item_id is not None:
        narrowing.append(_value_term("source_item_id", source_item_id))
    if source_version_ids is not None:
        narrowing.append(_any_term("source_version_id", source_version_ids))
    if chunk_id is not None:
        narrowing.append(_value_term("chunk_id", chunk_id))

    if not narrowing:
        raise ValueError(
            "refusing to build a deletion filter with no narrowing term: every optional "
            "argument is None, so the result would address an entire organization. That is a "
            "real operation with a real caller and it has its own name — `organization_filter` "
            "— so that the widening is a word in the diff instead of four defaults nobody "
            "reads. Dropping the organization term as well would be worse again: the server "
            "checks `must` with `.all()`, `.all()` of an empty list is true, and `Filter"
            "(must=[])` is therefore a match-all over every tenant in the collection"
        )

    # One `must`, never `should`. Qdrant merges two filters by concatenating their clause lists
    # per type, so a tenant term that ends up in `should` is appended to whatever `should` the
    # other filter already carried and the conjunction becomes "this organization OR anything
    # else" — a widening that no field in the result distinguishes from a correct query.
    return models.Filter(must=[org_term, *narrowing])


def organization_filter(org_id: str) -> models.Filter:
    """The whole organization, for an organization deletion — the one filter here with no
    narrowing term.

    A separate function on purpose. Reaching org-wide by passing None to every narrowing
    argument of `deletion_filter` would read as a simplification in a diff; this reads as what
    it is. It stays reachable only from the organization-erasure path, which fans out per
    source through the checked path first and uses this as the final orphan sweep.

    One term, in `must`, and it still goes through the same builder as every other term: the
    organization scope is not the term that may be skipped because it is obvious. A blank
    organization here is refused rather than matched, because `Filter(must=[])` is a match-all
    and a blank `MatchValue` is a silent no-op, and neither of those is an organization purge.
    """
    return models.Filter(must=[_value_term("org_id", org_id)])


# Checked at import rather than only in the test, because a duplicated or truncated
# allow-list must not survive into a running worker — the process that would use it is the
# one destroying data. The test owns the stronger assertion: the exact seven, in order.
assert len(DELETE_KEYS) == 7
assert len(set(DELETE_KEYS)) == len(DELETE_KEYS)
