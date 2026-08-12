"""`DELETE_KEYS` is CI's deletion-key allow-list expressed in code. This is what checks it.

CI subtracts `app/deletion/filters.py` from the `FieldCondition(key=…)` allow-list grep by
literal path, because that module is the one place a payload key may be built from a
variable — and a variable key is invisible to a regex over string literals. The exemption is
only affordable while the tuple behind those dynamic keys is pinned, so this file is the
enforcement that the grep can no longer provide.

It imports no fixtures, no containers and no app factory. A test that needs a running service
to check an allow-list stops running the day a container is slow, which is the day it is
needed. It does import three module-level tuples from `app/`, because the whole subject below
is whether those three tuples still stand in the right relation to each other.

**All three of those modules import `qdrant_client` at module scope** — `collection.py:81`,
`tenancy.py:65`, `filters.py`. This paragraph used to call them "stdlib-only", which was
already untrue of two of them and became untrue of the third when `filters.py`'s builders were
written; the type-only import it had until then was a placeholder its own comment promised to
replace with the first real body. The property that actually matters is unchanged and is the
one worth restating: importing the client constructs nothing, resolves nothing and opens no
socket, so this file still runs on a machine with no Qdrant anywhere near it.
"""

from __future__ import annotations

from typing import Final

from app.deletion.filters import DELETE_KEYS
from app.retrieval.collection import PAYLOAD_INDEXES
from app.retrieval.tenancy import MANDATORY_FILTER_KEYS

#: Spelled out here independently of the module under test, in the order CI's allow-list
#: names them. Deriving this from `DELETE_KEYS` would make the test agree with any edit.
#:
#: This one stays a literal on purpose, and it is the only one that may be. CI's allow-list
#: is a shell variable in `.github/workflows/gates.yml`, not a Python constant — there is no
#: object to import, so restating it here is the only way to compare against it at all.
CI_ALLOW_LIST = (
    "org_id",
    "bot_ids",
    "source_id",
    "source_item_id",
    "source_version_id",
    "source_status",
    "chunk_id",
)

#: `kb-tenancy-isolation`'s payload contract — **read out of the module that owns it**, never
#: restated. These are the fields every point is indexed on, the payload floor that makes the
#: mandatory tenant filter expressible at all (`app/retrieval/collection.py`).
#:
#: It was a copy-pasted literal here until the assertions below were audited, and that is why
#: they could not fail. Two literals in one file, related by an equality already asserted
#: twenty lines above, cannot detect an edit to a third module: every mutation of
#: `DELETE_KEYS` that reached them also failed `test_delete_keys_is_exactly_the_ci_allow_list`
#: first and for the same reason, and no mutation of the *payload* side reached them at all.
#: Bound to `PAYLOAD_INDEXES`, the comparison is between two independently maintained lists in
#: two packages, which is the only arrangement in which either side moving is visible.
PAYLOAD_CONTRACT: Final[frozenset[str]] = frozenset(index.field for index in PAYLOAD_INDEXES)


def test_delete_keys_is_exactly_the_ci_allow_list() -> None:
    """Tuple equality, not set equality: it catches membership, length, duplication and order
    in one assertion, and any of those changing should read as a deliberate edit here.
    """
    assert DELETE_KEYS == CI_ALLOW_LIST


def test_the_two_contracts_this_file_compares_against_are_populated() -> None:
    """The positive control, and it is not decoration.

    Both assertions below are set *differences*, and a difference against an empty set is a
    statement about one operand only. Empty `PAYLOAD_INDEXES` and empty `MANDATORY_FILTER_KEYS`
    are each reachable from an ordinary refactor — a list comprehension whose source moved, a
    tuple emptied while its builder is rewritten — and each would turn one of the two tests
    below into a tautology rather than a failure. That is the exact defect this file was
    rewritten to remove, so it must not be reintroduced from the other side.
    """
    assert PAYLOAD_CONTRACT, "app/retrieval/collection.py PAYLOAD_INDEXES is empty"
    assert MANDATORY_FILTER_KEYS, "app/retrieval/tenancy.py MANDATORY_FILTER_KEYS is empty"


def test_delete_keys_is_the_payload_contract_plus_chunk_id() -> None:
    """The first of the two failure modes: collapsing deletion's keys down to the payload floor.

    Dropping `chunk_id` "to match the payload contract" silently removes single-chunk
    deletion — the builder then raises on an unrecognised key, at runtime, inside a worker, on
    the one path nobody exercises by hand.

    The comparison is now against `app/retrieval/collection.py`'s `PAYLOAD_INDEXES`, so it also
    sees the movement that no assertion in this file used to see: a payload field **added**
    over there without a matching delete key means a term deletion can never address, and a
    payload field **removed** means a delete key indexed nowhere — a filter that matches
    nothing and looks exactly like an empty corpus, HTTP 200 and no exception.
    """
    assert set(DELETE_KEYS) - PAYLOAD_CONTRACT == {"chunk_id"}
    assert PAYLOAD_CONTRACT - set(DELETE_KEYS) == frozenset()


def test_chunk_id_is_never_a_mandatory_query_filter_term() -> None:
    """The second failure mode, and it lives in a different module from the first.

    Promoting `chunk_id` into the mandatory retrieval filter breaks retrieval rather than
    deletion: no query knows a chunk id before it has retrieved the chunk, so the term can
    never be satisfied and every search returns nothing — HTTP 200, zero candidates, which
    reads as an empty corpus and not as a bug.

    `MANDATORY_FILTER_KEYS` (`app/retrieval/tenancy.py`) is the non-negotiable-#2 set that
    every tenant-facing Qdrant call carries. A proper subset, checked in that direction: every
    mandatory query term must be an addressable delete key, because deletion has to be able to
    reach anything retrieval can serve — and the containment has to be *strict*, because the
    two sets becoming equal is precisely `chunk_id` having escaped one side or the other.
    """
    assert "chunk_id" not in MANDATORY_FILTER_KEYS
    assert set(MANDATORY_FILTER_KEYS) < set(DELETE_KEYS)


def test_delete_keys_order_is_pinned() -> None:
    """Nothing in Qdrant depends on the order of terms in a `must` list, so this pins the
    order rather than relying on it: the tuple is compared positionally so that a reordering
    surfaces as an edit to this file instead of a diff nobody reads twice, and so that
    `DELETE_KEYS[0]` is always the org scope every deletion filter is ANDed with.
    """
    assert DELETE_KEYS[0] == "org_id"
    assert list(DELETE_KEYS) == list(CI_ALLOW_LIST)


def test_delete_keys_holds_no_duplicates_and_cannot_be_mutated() -> None:
    """A duplicate would keep every equality check above green if the comparison were a set,
    and a list would let an import-time extension add a key at runtime with no diff at all.
    """
    assert isinstance(DELETE_KEYS, tuple)
    assert len(set(DELETE_KEYS)) == len(DELETE_KEYS)


def test_no_content_addressed_key_is_allow_listed() -> None:
    """The rule the allow-list exists for: deletion targets stable identifiers PostgreSQL
    issued, never anything derived from the material being deleted.

    Boilerplate — headers, disclaimers, licence blocks, pricing tables — repeats verbatim
    across documents and across tenants, so a delete matched on content, issued against one
    source, cuts a hole in a different source in an organization nobody touched. It returns
    200 and leaves no trace back to itself.
    """
    content_addressed = {
        "text",
        "content",
        "content_hash",
        "chunk_text",
        "chunk_content",
        "body",
        "title",
        "heading",
        "element_text",
        "page_content",
        "excerpt",
        "payload_text",
        "url",
        "name",
        "display_title",
    }
    assert set(DELETE_KEYS).isdisjoint(content_addressed)


def test_every_delete_key_survives_a_reparse() -> None:
    """Every allow-listed key ends in `_id`, `_ids` or `_status` — the shape of an identifier
    or a lifecycle state, both of which PostgreSQL issues and neither of which is derived from
    the material.

    That is what makes them stable across a re-parse, a re-embed and an
    `embedding_model_version` bump: the same delete addresses the same points before and
    after. A key that fails this is a content match wearing a filter's clothes, whatever it is
    called.
    """
    for key in DELETE_KEYS:
        assert key.endswith(("_id", "_ids", "_status")), key
