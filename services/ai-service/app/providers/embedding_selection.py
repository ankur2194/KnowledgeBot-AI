"""Which of an organization's provider connections supplies the embedding credential.

ADR-030 moved embedding onto this adapter layer and left one question unanswered, which is
finding **C1**: given an organization's set of configured connections, *which one embeds?*
``capabilities.py`` answers "can this ``(vendor, model)`` embed at all" — a repository-level
fact with a source per cell. It cannot answer "which of the four connections this tenant
saved is the one an ingest run should call", because that is a question about a set of rows
belonging to one organization, and nothing in the tree asked it.

The consequence is not a degraded mode. **Two of the five vendors have no embedding endpoint
at all** — Anthropic says so in its own words, DeepSeek's complete API reference indexes five
routes and none of them embeds — so an organization whose only connection is one of those two
has a chat provider and **no embedding provider**, and therefore cannot ingest a single
document. Every chunk must be embedded before it can be indexed; there is no
fused-order-instead fallback the way there is for reranking. Before this module the failure had
no name, no selection rule and no save-time check: the first upload would parse, OCR, chunk,
and then fail on an ``AttributeError`` or a ``None`` callable somewhere below
``app/ingestion/``, after the spend.

The exact set is read from ``capabilities.providers_offering(EMBEDDING)`` and is deliberately
not restated as a literal anywhere below — including in the refusal message, which composes it
at call time so that a vendor gaining or losing an endpoint moves the operator's instructions
with the matrix instead of leaving a stale list inside a string. This paragraph named
``{"openai"}`` while the matrix had four unread cells; the set is now three, and the difference
between "one vendor can embed" and "two vendors cannot" is the whole of what an operator needs
to hear.

WHY THIS IS ITS OWN MODULE AND NOT MORE OF ``capabilities.py``
--------------------------------------------------------------
``capabilities.py`` is deliberately **static repository-level data with an authority per
cell**, plus two total predicates over it. Every input it takes is a fact about a vendor or a
``provider_models`` row, and its whole argument for existing is that those facts are checked
in, cited, and asserted total at import. This module's input is an *organization's* set of
connections — runtime, per tenant, unbounded, and carrying no authority at all. Folding a
per-tenant question into a module whose thesis is "fifteen sourced cells, asserted total"
would make the totality assertion meaningless and would put a tenant-scoped value on the same
page as a vendor fact somebody is meant to be able to trust on sight.

So the split is: ``capabilities.can_embed`` is the gate, asked here and asked nowhere else on
this path; this module is the *selection* over the answers. ``assert_org_can_embed`` sits
beside ``capabilities.assert_row_coherent`` rather than inside it for the same reason the two
functions differ in arity — one validates a row against a vendor, the other validates a
tenant's configuration against the ability to ingest at all.

THE SELECTION RULE, AND WHY IT IS CODE
---------------------------------------
``resolve_embedding_connection`` is the single answer, and every caller asks it: the indexer
that writes vectors and the query path that embeds the question. A second resolution path
would not be an inconsistency, it would be a **correctness bug** — ``EmbeddingSpace`` derives
the Qdrant collection name from ``(provider, model, width, distance, schema_version)``, so an
index written under one model and queried under another either finds nothing (different width,
different collection) or, in the case that actually happens, finds plausible neighbours that
are simply wrong. Cosine distance is defined between any two vectors of equal width. Nothing
raises, no metric moves, and the only symptom is answer quality.

The rule, in order, and every step is deterministic in the *set* of connections rather than in
the order they were supplied:

1. **An explicit designation wins.** If the caller passes an ``EmbeddingDesignation``, that
   ``(connection_id, model)`` pair is used or the resolution fails naming it. Nothing is
   silently substituted, because a substitution is the exact failure above.
2. **Ineligible candidates are removed, each with a recorded reason.** Eligibility is
   ``capabilities.can_embed`` — the vendor axis AND the row axis — plus row coherence, which
   is checked here through ``assert_row_coherent`` and caught, so that a row claiming both
   embedding and chat is refused rather than selected.
3. **No eligible candidate is a hard, named failure.** Not a warning, not a default: the
   organization cannot ingest, and the message says so, names every connection that was
   examined and why each was rejected, and names the vendors that do publish the endpoint.
4. **Eligible candidates that disagree on ``(provider, model)`` are a hard failure too.**
   That pair *is* the vector space. Breaking the tie by convention — first saved, richest
   capability row, alphabetical vendor — would let an unrelated connection edit move the space
   under a corpus, which is the silent failure this whole module is arranged to prevent. A
   designation is required instead.
5. **Otherwise the eligible candidates all name one space**, and the one with the smallest
   ``(connection_id, model, sorted flags)`` key is selected. That key is total, so the answer
   cannot depend on iteration order, and ULIDs sort by creation time, so it reads as
   "the oldest connection to that model". This step chooses a *credential*, never a space —
   which is why an arbitrary-looking tiebreak is acceptable here and unacceptable at step 4.

``embedding_readiness`` is the same computation with no exception attached: total, never
raising, returning the verdict and the explanation. It exists so the control plane can render
"this organization cannot ingest yet, and here is why" on a connection screen without having
to catch an error to draw a banner, while the ingestion path calls the raising wrapper. One
computation, two shapes — never two rules.

WHY ``validation`` AND NOT ONE OF THE OTHER SEVENTEEN
------------------------------------------------------
``ErrorClass.VALIDATION``: 422, **not retryable**, not fallback-eligible. An organization with
no embedding-capable connection is a configuration state, and the next identical attempt fails
identically. The alternatives were considered and each is wrong in a way that costs something:

* ``provider_permanent_request`` (502) — no provider was called. It renders as a vendor fault,
  and `kb-error-taxonomy` pages on it because "it is our bug". Paging an operator for a tenant
  that has not finished configuring itself trains the page to be ignored.
* ``tenant_quota`` (403) — nothing is exhausted; nothing would change at the next billing
  period.
* ``internal_dependency`` (503) — nothing is unreachable. Note ADR-029's ``Origin`` split
  before reaching for it: ``DOWNSTREAM`` renders 503 **and retryable**, which tells a client
  to retry a configuration that can never succeed, and ``SELF`` renders 500 and blames our own
  code for a row the tenant did not create. The class name alone does not tell you which, and
  neither reading fits.
* A nineteenth class — the taxonomy stays at 18 (`kb-error-taxonomy`). This condition is
  expressible: it is a configuration that fails validation.

``validation`` also puts this failure in the same envelope as its neighbours —
``capabilities.assert_row_coherent`` and ``embedder.check_window`` both raise it — so the
whole "your embedding configuration cannot work" family renders one way to one operator.

WHAT THIS MODULE DELIBERATELY DOES NOT DECIDE
----------------------------------------------
Three genuine policy questions belong to the control plane. The mechanism supports every
option; the choice is not this layer's to make, and is stated in the report rather than
silently taken here:

1. **Where the designation lives.** Options: (a) an explicit embedding connection + model on
   the **organization**; (b) derive it from the connection set and accept that a second
   embedding-capable connection breaks ingestion until somebody designates one; (c) put it on
   the bot. **Recommendation: (a).** (c) is wrong on the contract rather than on taste —
   ``EmbeddingRequest`` has no ``bot_id`` because a source belongs to the organization and has
   not been assigned to a bot when it is embedded, so a per-bot embedding model would put one
   source's chunks in several spaces at once.
2. **Whether an organization may configure a connection purely to embed.** Nothing here
   couples the embedding connection to the chat connection, and with one sourced embedder that
   decoupling is the only way an Anthropic-only organization can ingest at all.
   **Recommendation: permit it**, and show it as such in the UI rather than as a second chat
   provider nobody selected.
3. **Whether the platform offers a default embedding credential.** **Recommendation: no, and
   not merely on cost.** A platform-funded credential sends tenant chunk text to a vendor the
   organization never authorised, which is a privacy boundary rather than a convenience. If it
   is ever offered it is opt-in per organization and recorded in the audit trail.

NO CREDENTIAL IS AN INPUT HERE, AND THE MODELS CANNOT CARRY ONE
----------------------------------------------------------------
Selection answers *which* connection, never *with what key*. Every model in this file is
``extra="forbid"`` with no ``SecretStr`` field, and an import-time check refuses a field whose
name looks like a secret — so a well-meaning edit that attaches the decrypted key "so the
caller does not have to look it up twice" fails at import rather than in a log line. The
decrypted credential reaches the adapter as a per-call argument on ``embed()`` and nowhere
else (`kb-security-baseline`, ADR-011).

THE OUTPUT IS AN IDENTITY THE CONTROL PLANE CAN RESOLVE, NOT A FASTAPI-ONLY VALUE
----------------------------------------------------------------------------------
Deliberate, and it settled a wire question that is now closed (finding **F12** / **#27**, ruled
2026-08-12 as `docs/22` § **G7**). ``kb-internal-api-contracts`` used to pin the Laravel to
FastAPI body as ``{"config": {...}, "provider_credential": "sk-..."}`` — **singular** — while
post-ADR-030 one chat turn can need three credentials: the chat one, the *embedding* one to
embed the question at stage 5, and the *rerank* one. ``EmbeddingRequest`` and ``RerankRequest``
each carry their own ``provider_connection_id``, which is the contract already saying these may
be different connections.

The ruling is a **keyed map**: ``provider_credentials: {connection_id: SecretStr}``, a sibling of
``config`` and excluded from the snapshot hash exactly as the single field was — ADR-011's three
properties are unchanged and only the cardinality moved. So the paragraph below is now a
description rather than a prediction, and it is worth keeping in that tense: this module was
designed against a shape that did not exist yet, and the test of that design was whether it had
to change when the shape landed. It did not.

The resolution doctrine forbids is FastAPI reading ``provider_connections`` out of PostgreSQL
to fill the gap: `kb-provider-adapter-contract` ("resolves nothing from storage") and
`kb-security-baseline` both rule it out, because Laravel owns the relational store. So this
module is shaped so it can never be the thing that tempts anyone into it. It resolves to an
``EmbeddingConnection`` — a ``connection_id`` the control plane already knows how to turn into
a decrypted key, plus the ``(provider, model)`` pair that names the space — and it holds no
credential and looks nothing up. ``EmbeddingDesignation`` is the same identity in the shape a
*request* would carry it.

Concretely: the map's key is a ``connection_id``, and this function is what says which one
belongs under the embedding entry. Nothing here changed when that shape landed; had it needed
to, that would have been the signal that the selection was designed as a FastAPI-internal value
rather than a transmissible one.
"""

from __future__ import annotations

from collections.abc import Iterable
from enum import StrEnum
from typing import Final

from pydantic import BaseModel, ConfigDict, model_validator

from app.core.errors import ErrorClass, KbError
from app.providers.capabilities import (
    assert_row_coherent,
    can_embed,
    provider_offers,
    providers_offering,
    task_support,
)
from app.providers.contract import Capability, ModelCapabilities, Ulid
from app.providers.errors import ProviderSurface
from app.retrieval.collection import EmbeddingSpace

__all__ = [
    "EmbeddingConnection",
    "EmbeddingDesignation",
    "EmbeddingIneligibility",
    "EmbeddingReadiness",
    "Rejection",
    "assert_org_can_embed",
    "assert_selection_serves_space",
    "embedding_readiness",
    "ineligibility",
    "resolve_embedding_connection",
]


class EmbeddingIneligibility(StrEnum):
    """Why one connection cannot be the organization's embedder. **Closed, and pre-call.**

    The same discipline as ``app.rag.rerank.RerankSkipReason``, for the same reason: every
    member is decidable before any request goes out, and there is deliberately **no member
    meaning "the provider errored"**. A call that fails is an error in `kb-error-taxonomy`
    terms and propagates as one. If a vendor outage could be recorded here it would read as a
    configuration state on an admin screen, and an organization would be told to fix a
    connection that was never broken.

    The difference from ``RerankSkipReason`` is what happens next, and it is the whole of
    C1: a skipped rerank is a cheaper mode of operation, while nothing being eligible here
    means the organization cannot ingest at all. These reasons are therefore an *explanation
    attached to a refusal*, never a degraded path.
    """

    #: Axis 1 of ``can_embed``: ``capabilities.PROVIDER_TASKS`` does not record this vendor as
    #: ``SUPPORTED`` on the embedding surface. Not fixable by editing the row — it needs a
    #: recorded fixture and a matrix move, or a connection to a different vendor.
    VENDOR_HAS_NO_ENDPOINT = "vendor_has_no_endpoint"
    #: Axis 2: the ``provider_models`` row carries no ``capability_flags.embedding``. Fixable
    #: in the control plane, and the flag is never inferred from the model id string.
    ROW_LACKS_EMBEDDING_FLAG = "row_lacks_embedding_flag"
    #: Both axes pass and the row is still unusable, because it claims two task families or
    #: carries chat flags beside the embedding one. ``capabilities.assert_row_coherent``
    #: describes a model that does not exist; selecting it would validate every request
    #: against the wrong schema.
    ROW_INCOHERENT = "row_incoherent"


class EmbeddingConnection(BaseModel):
    """One candidate: a provider connection and the ``provider_models`` row under it.

    Not a wire model — the control plane owns the shape it sends — but ``strict=True`` all the
    same, because the one coercion that matters is silent: a ``model`` arriving as something
    other than the vendor id, verbatim, is a different vector space.

    **Deliberately absent: any credential field.** Selection answers *which* connection, and
    the decrypted key reaches the adapter as a per-call argument on ``embed()``. The
    import-time check at the bottom of this file refuses a field whose name looks like a
    secret, so attaching one is a startup failure rather than a log line nobody greps.
    """

    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

    connection_id: Ulid
    #: One of ``capabilities.PROVIDERS``. An unknown name resolves to ``_UNKNOWN`` and is
    #: rejected as ``VENDOR_HAS_NO_ENDPOINT``, which is the fail-closed direction.
    provider: str
    #: The official vendor id, verbatim, never an alias we shortened. It is half of the
    #: ``EmbeddingSpace`` identity and therefore half of the collection name.
    model: str
    #: The ``provider_models`` row, mirrored (docs/11 §16.2). Capability comes from here and
    #: never from parsing ``model``.
    caps: ModelCapabilities

    @property
    def space_key(self) -> tuple[str, str]:
        """The part of the ``EmbeddingSpace`` identity a connection determines.

        Width is missing on purpose: it is read off the provider's actual response, never
        predicted from the id (``EmbeddingResult.space``). Two connections agreeing on this
        pair still have to agree on width, and ``collection.assert_dimensions`` is what checks
        that — at a point where the number actually exists.
        """
        return (self.provider, self.model)

    @property
    def order_key(self) -> tuple[str, str, tuple[str, ...]]:
        """A **total** ordering key, so selection cannot depend on iteration order.

        ``connection_id`` first because ULIDs sort by creation time, which makes the tiebreak
        read as "the oldest connection to this model". ``model`` next because one connection
        legitimately carries several rows. The flag tuple last so that two rows agreeing on
        both still order deterministically — without it, a control plane that sent the same
        pair twice with different flags would resolve differently per call, which is precisely
        the indexer-versus-reader divergence this module exists to make impossible.
        """
        return (
            self.connection_id,
            self.model,
            tuple(sorted(flag.value for flag in self.caps.supported)),
        )


class EmbeddingDesignation(BaseModel):
    """The control plane's explicit answer to "which one embeds", when it has one.

    A pair rather than a bare ``connection_id``, because one connection can carry several
    embedding rows — ``text-embedding-3-large`` and ``text-embedding-3-small`` are two spaces
    on one credential, and designating the connection alone would leave the space undecided.
    """

    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

    connection_id: Ulid
    model: str


class Rejection(BaseModel):
    """One candidate that cannot embed, with the reason and what the reason was read from.

    ``detail`` exists so an operator screen can say something better than the enum member.
    It carries connection ids, provider names, model ids and matrix sources only — never a
    credential, and never tenant content.
    """

    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

    connection_id: Ulid
    provider: str
    model: str
    reason: EmbeddingIneligibility
    detail: str


class EmbeddingReadiness(BaseModel):
    """The verdict, computed once and shaped for both callers.

    ``selected`` is the answer; ``explanation`` is why there is none. Exactly one of the two
    is populated, enforced below rather than by convention, because a readiness object that
    could be both would let a caller render a banner *and* proceed to embed.
    """

    model_config = ConfigDict(frozen=True, extra="forbid", strict=True)

    selected: EmbeddingConnection | None
    #: Every candidate that passed both axes and the coherence check, in ``order_key`` order.
    #: More than one is not an error by itself — several connections to the same
    #: ``(provider, model)`` name one space and differ only in which credential pays.
    eligible: tuple[EmbeddingConnection, ...] = ()
    #: Every candidate that did not, with its reason. Present even on success, because an
    #: operator asking "why is my Anthropic key not being used" needs the answer whether or
    #: not some other connection saved the day.
    rejected: tuple[Rejection, ...] = ()
    #: Empty exactly when ``selected`` is populated. Otherwise the operator-facing message
    #: that ``resolve_embedding_connection`` raises verbatim, so the banner and the error say
    #: the same words.
    explanation: str = ""

    @property
    def ready(self) -> bool:
        return self.selected is not None

    @model_validator(mode="after")
    def _explanation_matches_verdict(self) -> EmbeddingReadiness:
        if (self.selected is None) == (not self.explanation):
            raise ValueError(
                "a readiness is either selected (with no explanation) or unselected (with "
                "one) — a verdict that carries both lets a caller draw the banner and embed"
            )
        return self


def _cell_detail(provider: str) -> str:
    """What ``PROVIDER_TASKS`` says about this vendor's embedding surface, quoted.

    The verdict plus its authority, so the rejection carries the same evidence the matrix
    does. An ``UNVERIFIED`` cell names no source by construction, and saying "no authority
    either way" out loud is the point of the third state: it is a fixture somebody owes, not
    a closed question.
    """
    cell = task_support(provider, ProviderSurface.EMBEDDING)
    authority = (
        cell.source
        or "no authority either way; treated as unavailable until a fixture proves otherwise"
    )
    return (
        f"capabilities.PROVIDER_TASKS records {provider} as {cell.support.value} on the "
        f"embedding surface ({authority})"
    )


def ineligibility(connection: EmbeddingConnection) -> EmbeddingIneligibility | None:
    """Why this connection cannot embed, or ``None`` when it can. **Total; never raises.**

    ``capabilities.can_embed`` is asked first and is the gate — the reasons below only
    *explain* its answer, so this function cannot drift away from the predicate every other
    caller in the platform uses. Reversing that (deriving the verdict from the reasons) is how
    two gates end up disagreeing while both read as correct.

    ``assert_row_coherent`` is invoked and its ``KbError`` caught, deliberately. That function
    is the loud save-time half and raising is right there; here the same disagreement has to
    become a value, because this function is also called on the request path, where an
    exception escaping a capability question could be caught upstream and recorded as a skip.
    """
    if can_embed(connection.provider, connection.caps):
        try:
            assert_row_coherent(connection.provider, connection.model, connection.caps)
        except KbError:
            return EmbeddingIneligibility.ROW_INCOHERENT
        return None
    if not provider_offers(connection.provider, ProviderSurface.EMBEDDING):
        return EmbeddingIneligibility.VENDOR_HAS_NO_ENDPOINT
    return EmbeddingIneligibility.ROW_LACKS_EMBEDDING_FLAG


def _reject(connection: EmbeddingConnection, reason: EmbeddingIneligibility) -> Rejection:
    if reason is EmbeddingIneligibility.VENDOR_HAS_NO_ENDPOINT:
        detail = _cell_detail(connection.provider)
    elif reason is EmbeddingIneligibility.ROW_LACKS_EMBEDDING_FLAG:
        detail = (
            f"the provider_models row for {connection.provider}/{connection.model} carries no "
            f"capability_flags.{Capability.EMBEDDING.value}. The flag is read from the row and "
            "never inferred from the model id, so a row that serves embeddings has to say so"
        )
    else:
        detail = (
            f"the provider_models row for {connection.provider}/{connection.model} is "
            "incoherent — it claims two task families, or carries chat flags beside the "
            "embedding one. capabilities.assert_row_coherent names which"
        )
    return Rejection(
        connection_id=connection.connection_id,
        provider=connection.provider,
        model=connection.model,
        reason=reason,
        detail=detail,
    )


def _capable_vendors() -> str:
    return ", ".join(sorted(providers_offering(ProviderSurface.EMBEDDING))) or "(none)"


def _describe(connection: EmbeddingConnection) -> str:
    return f"{connection.connection_id} -> {connection.provider}/{connection.model}"


def _nothing_can_embed(rejected: tuple[Rejection, ...]) -> str:
    if not rejected:
        examined = "This organization has no provider connections at all."
    else:
        lines = "; ".join(
            f"{r.connection_id} -> {r.provider}/{r.model}: {r.reason.value} ({r.detail})"
            for r in rejected
        )
        examined = f"Examined {len(rejected)} connection(s): {lines}."
    return (
        "This organization has no embedding-capable provider connection, so it cannot ingest "
        "any document. Every chunk is embedded before it is indexed and there is no degraded "
        "mode for it — unlike reranking, which may be skipped. "
        f"{examined} "
        f"Providers recorded as offering an embedding endpoint: {_capable_vendors()}. "
        "Fix: configure a connection to one of those vendors with a provider_models row "
        "carrying capability_flags.embedding, and designate it as this organization's "
        "embedding connection. That connection does not have to be the one serving chat."
    )


def _ambiguous(eligible: tuple[EmbeddingConnection, ...]) -> str:
    lines = "; ".join(_describe(c) for c in eligible)
    return (
        f"This organization has {len(eligible)} embedding-capable connections that do not "
        f"agree on (provider, model): {lines}. Which one embeds is not a preference — that "
        "pair IS the vector space (EmbeddingSpace derives the Qdrant collection name from it), "
        "so breaking the tie by convention would let an unrelated connection edit move the "
        "space a corpus was indexed under. Cosine distance is defined between any two vectors "
        "of equal width, so nothing would raise and only ranking would change. Designate one "
        "connection and model explicitly."
    )


def _designation_failed(
    designated: EmbeddingDesignation,
    eligible: tuple[EmbeddingConnection, ...],
    rejected: tuple[Rejection, ...],
) -> str:
    named = f"{designated.connection_id} -> {designated.model}"
    for r in rejected:
        if (r.connection_id, r.model) == (designated.connection_id, designated.model):
            return (
                f"The designated embedding connection {named} cannot embed: "
                f"{r.reason.value} ({r.detail}). It is not substituted — silently embedding "
                "through a different connection would change the vector space under a corpus "
                f"nobody reindexed. Providers offering an embedding endpoint: "
                f"{_capable_vendors()}."
            )
    available = "; ".join(_describe(c) for c in eligible) or "(none)"
    return (
        f"The designated embedding connection {named} is not among this organization's "
        f"connections. Embedding-capable connections available: {available}. The designation "
        "is never substituted: it names the vector space, and resolving it to something else "
        "would index and query a corpus under two different models with no error anywhere."
    )


def embedding_readiness(
    connections: Iterable[EmbeddingConnection],
    *,
    designated: EmbeddingDesignation | None = None,
) -> EmbeddingReadiness:
    """Resolve the organization's embedding connection. **Total; never raises.**

    The one computation. ``resolve_embedding_connection`` and ``assert_org_can_embed`` are
    both this function plus a raise, so a connection screen and an ingest run cannot reach
    different verdicts — which is the whole point, because they are the two sides of the
    index-written-here / query-embedded-there divergence.

    Duplicates collapse: candidates are de-duplicated as a set and ordered by ``order_key``
    before anything is decided, so the answer is a function of the *set* of connections and
    never of the order the caller happened to build the list in.
    """
    ordered = sorted(set(connections), key=lambda c: c.order_key)

    eligible: list[EmbeddingConnection] = []
    rejected: list[Rejection] = []
    for candidate in ordered:
        reason = ineligibility(candidate)
        if reason is None:
            eligible.append(candidate)
        else:
            rejected.append(_reject(candidate, reason))

    frozen_eligible = tuple(eligible)
    frozen_rejected = tuple(rejected)

    def unselected(explanation: str) -> EmbeddingReadiness:
        return EmbeddingReadiness(
            selected=None,
            eligible=frozen_eligible,
            rejected=frozen_rejected,
            explanation=explanation,
        )

    if designated is not None:
        for candidate in frozen_eligible:
            if (candidate.connection_id, candidate.model) == (
                designated.connection_id,
                designated.model,
            ):
                return EmbeddingReadiness(
                    selected=candidate,
                    eligible=frozen_eligible,
                    rejected=frozen_rejected,
                )
        return unselected(_designation_failed(designated, frozen_eligible, frozen_rejected))

    if not frozen_eligible:
        return unselected(_nothing_can_embed(frozen_rejected))

    if len({candidate.space_key for candidate in frozen_eligible}) > 1:
        return unselected(_ambiguous(frozen_eligible))

    return EmbeddingReadiness(
        selected=frozen_eligible[0],
        eligible=frozen_eligible,
        rejected=frozen_rejected,
    )


def resolve_embedding_connection(
    connections: Iterable[EmbeddingConnection],
    *,
    designated: EmbeddingDesignation | None = None,
) -> EmbeddingConnection:
    """The connection an embedding call must be made through, or a named refusal.

    Called by the indexer before the first batch and by the query path before the question is
    embedded. Both call **this**; neither reimplements step 5, because an index written with
    one model and queried with another returns plausible neighbours and raises nothing.

    Raises ``KbError(ErrorClass.VALIDATION, ...)`` — 422, never retried, never fallback-
    eligible. See the module docstring for why the other four candidate classes are wrong, and
    in particular why ``internal_dependency`` is wrong under both ADR-029 origins.
    """
    readiness = embedding_readiness(connections, designated=designated)
    if readiness.selected is None:
        raise KbError(ErrorClass.VALIDATION, readiness.explanation)
    return readiness.selected


def assert_org_can_embed(
    connections: Iterable[EmbeddingConnection],
    *,
    designated: EmbeddingDesignation | None = None,
) -> None:
    """The save-time name for the same resolution. Raises what an upload would have raised.

    Called when a provider connection or a model row is saved, beside
    ``capabilities.assert_row_coherent`` — the connection-save path already runs that one, and
    this is the org-level question the row-level one cannot ask. Deliberately the *same*
    computation rather than a looser "is there at least one embedder" check: a looser check
    would pass an ambiguous configuration at save time and fail it at the first upload, which
    is exactly the late discovery C1 is about.

    A caller that must not fail the save — refusing to store an organization's only chat
    connection because it cannot also embed would be worse than the disease — calls
    ``embedding_readiness`` instead and renders ``explanation`` as a blocking banner on the
    ingestion surface. Same words, same rule, no second implementation.
    """
    resolve_embedding_connection(connections, designated=designated)


def assert_selection_serves_space(
    selection: EmbeddingConnection,
    space: EmbeddingSpace,
) -> None:
    """Refuse to embed against a space this connection does not serve.

    The other half of "one resolution rule". Resolution alone guarantees that two callers
    running *now* agree; this guarantees that a caller running now agrees with the run that
    wrote the vectors. A source version records the ``EmbeddingSpace`` it was indexed under,
    and the query path must embed its question with that same provider and model — not with
    whatever the organization's configuration resolves to today.

    Width is not compared here and that is not an omission: it is read off the provider's
    actual response, so it does not exist yet at this point.
    ``collection.assert_dimensions`` is the check for it, at the point where the number is
    real.

    ``VALIDATION`` for the same reason as everything else in this module: changing embedding
    model is a reindex, not a configuration change, and no number of retries makes the old
    corpus readable by the new model.
    """
    if selection.space_key != (space.provider, space.model):
        raise KbError(
            ErrorClass.VALIDATION,
            f"the resolved embedding connection {selection.connection_id} serves "
            f"{selection.provider}/{selection.model}, but this content was indexed under "
            f"{space.provider}/{space.model} (collection {space.collection}). Embedding a "
            "query with a different model than the passages were embedded with returns "
            "plausible neighbours that are simply wrong — cosine distance is defined between "
            "any two vectors of equal width, so nothing raises and no metric moves. Changing "
            "embedding model is a reindex, not a configuration change.",
        )


#: Field-name substrings that must never appear on a model in this file. The credential is a
#: per-call argument to ``embed()`` and selection has no business holding one; this makes the
#: well-meaning edit that attaches it — "so the caller does not have to look it up twice" — an
#: import failure rather than a value that reaches a log, a span or a Celery payload.
_SECRET_LOOKING: Final[tuple[str, ...]] = ("credential", "api_key", "secret", "token", "password")

_MODELS: Final[tuple[type[BaseModel], ...]] = (
    EmbeddingConnection,
    EmbeddingDesignation,
    Rejection,
    EmbeddingReadiness,
)

# Checked at import, for the reason ``app/core/errors.py`` and ``capabilities.py`` check their
# own tables there rather than in a test: a model that can carry a secret must not reach a
# running process, and the failure it produces is invisible in review.
assert not [
    f"{model.__name__}.{name}"
    for model in _MODELS
    for name in model.model_fields
    for bad in _SECRET_LOOKING
    if bad in name.lower()
]
# Every reason has to be reachable from ``ineligibility``, or it is documentation pretending
# to be a branch. The three members map one-to-one onto the two ``can_embed`` axes plus
# coherence; a fourth member without a branch would read as a case somebody handles.
assert len(EmbeddingIneligibility) == 3
