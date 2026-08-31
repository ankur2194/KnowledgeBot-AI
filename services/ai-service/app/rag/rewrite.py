"""Stage 5 — turning a follow-up into a standalone query, without changing what was asked.

**Intent is immutable.** The rewrite resolves pronouns and elided subjects: "does it cover
accidental damage?" becomes "does the XR-400B cover accidental damage?". It does not add
constraints, guess a product, translate, or expand into a keyword bag. Retrieval-oriented
rewriting — keyword expansion, hypothetical documents — measurably hurts here by distorting
intent and over-weighting rare terms, and the damage is invisible in the trace because
retrieval genuinely succeeded, just for a question nobody asked.

**The guard is in code, not in the instruction.** A model told "keep the product code" keeps
it most of the time, and the times it does not are exactly the queries where the code was the
whole question. :func:`guard_entities` re-derives the code-like tokens from the original and
requires every one of them to survive; a rewrite that lost one is **discarded**, retrieval
runs on the original, and the fallback is recorded. Discarded rather than repaired: a rewrite
with a part number pasted back into it is a sentence no model produced and no evaluation run
has ever scored.

**Both queries are stored, and they are used for different things.** Retrieval runs on the
last user turn *concatenated with* the rewrite — never the rewrite alone — which preserves
the user's own phrasing for the lexical branch while adding the context the follow-up elided.
That concatenation is also the cheapest insurance against a bad rewrite that cleared the
entity guard. Generation is shown the **original** wording (:mod:`app.rag.prompt` reads
``Rewrite.original_query`` itself, and has no parameter that would accept the rewrite), so a
user reading the answer sees their own question quoted back.

**Facets are advisory and can only narrow.** A rewriter may extract a date, a product, a
category or a tag. Those become *optional* filter terms and they never touch the mandatory
org / bot / status / version filter — that set is a security boundary owned by
``app.retrieval.tenancy`` and is built from the authenticated scope, positionally, with no
keyword that relaxes it. A facet is a model-authored string; the day one is allowed to widen
a filter is the day a rewritten query is an authorization input.

**There is no fallback member meaning "the rewriter failed", and that is deliberate**, for
the reason ``RerankSkipReason`` is closed and failure-free: a provider outage recorded as a
degraded mode is an incident laundered into a configuration. This module never catches an
exception from the injected rewriter. The runner decides whether a failed rewrite is fatal or
whether the turn proceeds on the original query, and it records *that* decision as an error
on the trace, not as a rewrite outcome.
"""

from __future__ import annotations

from collections.abc import Mapping, Sequence
from dataclasses import dataclass, field
from enum import StrEnum
from typing import Final, Protocol

from app.rag.normalize import preserved_tokens

__all__ = [
    "FACET_KEYS",
    "MAX_SUBQUERIES",
    "Rewrite",
    "RewriteFallback",
    "RewriteProposal",
    "Rewriter",
    "entities_lost",
    "guard_entities",
    "retrieval_query",
    "rewrite_query",
]

#: The advisory facet names a rewriter may extract (§12.5). Closed, because a facet is a
#: payload key on the optional half of the filter and an unknown key is either ignored
#: silently or matched against nothing — and the two are indistinguishable in a trace.
FACET_KEYS: Final[frozenset[str]] = frozenset(
    {"lang", "content_type", "tag", "product", "category", "effective_date", "expiry_date"}
)

#: ``rewrite.max_subqueries``. One standalone query is the shipped configuration; the contract
#: allows several for a multi-part question, and each one costs a full dense + sparse round
#: trip inside a 1.5 s retrieval budget. Raising it is an evaluation experiment with a
#: configuration snapshot attached, not a code change.
MAX_SUBQUERIES: Final[int] = 1


class RewriteFallback(StrEnum):
    """Why retrieval is running on the original query. **Closed, and failure-free.**

    Every member is decidable from the rewriter's *output*, without reference to whether the
    call succeeded — the same rule ``RerankSkipReason`` follows, for the same reason. A
    rewriter outage is an error the runner classifies through ``kb-error-taxonomy``; it is
    never one of these, because an operator reading ``entity_dropped`` on a metric should be
    able to go and look at a rewrite that dropped an entity.
    """

    #: ``rewrite.enabled = false``. The stage still ran and still recorded a fragment; it is
    #: config-off, not a code path that jumped.
    DISABLED = "disabled"
    #: No conversation to resolve against. A first turn is already standalone, so there is
    #: nothing for a rewrite to add and every word it added would be invented.
    NO_HISTORY = "no_history"
    #: Stage 4 decided the turn needs no retrieval — a greeting or a thanks. There is no
    #: retrieval query for a rewrite to improve, so the round trip is not spent. Decidable
    #: from stage 4's output and therefore admissible under the failure-free rule above.
    NOT_NEEDED = "not_needed"
    #: The rewriter returned nothing, whitespace, or the original unchanged.
    NO_REWRITE_PRODUCED = "no_rewrite_produced"
    #: The entity guard fired. The rewrite existed and was thrown away.
    ENTITY_DROPPED = "entity_dropped"


@dataclass(frozen=True, slots=True)
class RewriteProposal:
    """What an injected rewriter returns: a candidate query and optional advisory facets.

    A proposal, not a rewrite. It has passed no guard yet, and the type name is the reminder —
    the shape that goes wrong is a rewriter whose output is assigned straight onto the trace
    field named ``rewritten_query``, at which point the guard has nothing left to discard.
    """

    query: str
    facets: Mapping[str, str] = field(default_factory=dict)


class Rewriter(Protocol):
    """The injected stage-5 model call.

    Async and injected rather than imported: it is a provider call through the adapter layer
    (``kb-provider-adapter-contract``), and this module must not depend on the adapters —
    they are a separate layer with their own error taxonomy, and unit tests for the entity
    guard must not need one.

    It receives the normalized question and the bounded history stage 3 prepared. It never
    receives retrieved content: nothing has been retrieved yet at stage 5, and a rewriter that
    saw evidence would be a second, unguarded place for source text to influence a query.
    """

    async def __call__(
        self, question: str, history: Sequence[str], /
    ) -> RewriteProposal | None: ...


@dataclass(frozen=True, slots=True)
class Rewrite:
    """Stage 5's output. Both queries, the query retrieval actually runs on, and why.

    ``rewritten_query`` is ``None`` whenever ``fallback`` is set, and the two are checked
    against each other at construction. Carrying a discarded rewrite in the field named
    ``rewritten_query`` is how a rewrite that failed the guard ends up in an evaluation replay
    as the query that was used.
    """

    #: The user's own wording, normalized. This is what generation is shown.
    original_query: str
    #: The standalone rewrite, or ``None`` when one is not in force.
    rewritten_query: str | None
    #: What retrieval runs on: the original concatenated with the rewrite, or the original
    #: alone. Never the rewrite alone.
    retrieval_query: str
    #: Advisory optional filter terms. Never merged into the mandatory filter.
    facets: Mapping[str, str]
    #: ``None`` when a rewrite is in force.
    fallback: RewriteFallback | None
    #: The code-like tokens the discarded rewrite lost, for the playground. Empty unless
    #: ``fallback`` is ``ENTITY_DROPPED``.
    dropped_entities: tuple[str, ...] = ()

    def __post_init__(self) -> None:
        if (self.rewritten_query is None) != (self.fallback is not None):
            raise ValueError(
                "a rewrite is either in force (a query, no fallback) or not (a fallback, no "
                "query). A discarded rewrite left in `rewritten_query` reads in an evaluation "
                "replay as the query retrieval ran on"
            )
        if self.dropped_entities and self.fallback is not RewriteFallback.ENTITY_DROPPED:
            raise ValueError("dropped_entities is only meaningful for the entity-guard fallback")
        unknown = set(self.facets) - FACET_KEYS
        if unknown:
            raise ValueError(
                f"facet key(s) {sorted(unknown)} are not in FACET_KEYS. An unknown optional "
                "filter term is either dropped silently or matched against nothing, and the "
                "two are indistinguishable in a trace"
            )


def entities_lost(original: str, candidate: str) -> tuple[str, ...]:
    """Code-like tokens present in ``original`` and absent from ``candidate``.

    Compared **case-insensitively**, and that is a decision with a cost. The lexical branch
    case-folds, so ``XR-400B`` → ``xr-400b`` is not a retrieval loss and failing the guard on
    it would discard good rewrites over a formatting difference. The cost is the one shape it
    cannot see: an acronym that is also an ordinary word (``IT`` → ``it``), where the case
    *was* the meaning. That is left to the reviewer rather than closed by tightening the
    comparison, because tightening it discards a rewrite on every ordinary capitalization
    change and the guard would be turned off within a week.

    Substring containment, not token equality: a rewrite that pluralizes or possessivizes a
    code (``XR-400B`` → ``XR-400B's``) still carries it, and the sparse analyzer will find it.
    """
    folded = candidate.casefold()
    return tuple(token for token in preserved_tokens(original) if token.casefold() not in folded)


def guard_entities(original: str, proposal: RewriteProposal) -> tuple[str, ...]:
    """The entity guard. Returns the lost tokens; empty means the rewrite may be used.

    A separate function from :func:`rewrite_query` so the evaluation harness and the
    playground can ask the question without running the stage, and so a test can prove the
    guard fires on a rewrite this module never produced.
    """
    return entities_lost(original, proposal.query)


def retrieval_query(original: str, rewritten: str | None) -> str:
    """The string stages 7 and 8 embed: the last user turn ‖ the rewrite.

    **Never the rewrite alone.** Concatenating keeps the user's phrasing — which is what the
    lexical branch scores on, and what a rewrite most often flattens — while adding the
    context the follow-up left out. It is also the containment on a bad rewrite that cleared
    the entity guard: the original's terms are still in the query, so the failure degrades
    recall instead of redirecting it.

    Returns the original unchanged when there is no rewrite, and when the rewrite is the
    original again — duplicating a query doubles nothing useful and doubles the token cost of
    the embedding call.
    """
    if rewritten is None or rewritten.strip() == original.strip():
        return original
    return f"{original} {rewritten}"


async def rewrite_query(
    original: str,
    history: Sequence[str],
    rewriter: Rewriter | None,
    *,
    enabled: bool,
    needs_retrieval: bool = True,
) -> Rewrite:
    """Stage 5. Propose, guard, and fall back to the original if the guard fires.

    ``enabled`` is a configuration value and this function is called on every turn regardless
    of it: ``rewrite.enabled = false`` produces a ``Rewrite`` carrying the ``DISABLED``
    fallback, a recorded stage fragment and a span, not a branch in the runner that jumps
    ahead. A stage that vanishes from the trace when it is switched off is a stage nobody can
    tell apart from a stage that crashed.

    ``rewriter`` may be ``None`` — an organization with no configured rewrite model — and that
    is reported as ``DISABLED`` rather than as a failure, because it is a configuration and
    the operator's remedy is to configure one.

    **Exceptions from ``rewriter`` propagate.** See the module docstring: a provider outage
    recorded as a degraded mode is an incident laundered into a configuration.

    Records ``kb.query.rewrite``... it does not, and the absence is deliberate: stage 5 uses
    the generative-AI semantic-convention span form, which carries a model id and therefore
    cannot be a constant, so ``STAGE_SPANS`` has no entry for it and ``span_for`` raises. The
    runner names that span from the adapter's model id, beside the provider call it wraps.
    """
    if not enabled or rewriter is None:
        return Rewrite(
            original_query=original,
            rewritten_query=None,
            retrieval_query=original,
            facets={},
            fallback=RewriteFallback.DISABLED,
        )
    if not needs_retrieval:
        # Stage 4 said this turn is complete without evidence. Rewriting "thanks" into a
        # standalone question would buy nothing and spend a provider round trip inside the
        # same request budget the retrieval leg draws on.
        return Rewrite(
            original_query=original,
            rewritten_query=None,
            retrieval_query=original,
            facets={},
            fallback=RewriteFallback.NOT_NEEDED,
        )
    if not history:
        # Nothing to resolve against. Every word a rewrite added here would be invented, and
        # an invented constraint is exactly the intent change the contract forbids.
        return Rewrite(
            original_query=original,
            rewritten_query=None,
            retrieval_query=original,
            facets={},
            fallback=RewriteFallback.NO_HISTORY,
        )

    proposal = await rewriter(original, history)
    if proposal is None or not proposal.query.strip() or proposal.query.strip() == original.strip():
        return Rewrite(
            original_query=original,
            rewritten_query=None,
            retrieval_query=original,
            facets={},
            fallback=RewriteFallback.NO_REWRITE_PRODUCED,
        )

    lost = guard_entities(original, proposal)
    if lost:
        # Discarded whole. Facets go with it: they came out of the same generation that lost
        # the identifier, so trusting the narrowing half of an output whose widening half was
        # just rejected is a distinction with nothing behind it.
        return Rewrite(
            original_query=original,
            rewritten_query=None,
            retrieval_query=original,
            facets={},
            fallback=RewriteFallback.ENTITY_DROPPED,
            dropped_entities=lost,
        )

    rewritten = proposal.query.strip()
    return Rewrite(
        original_query=original,
        rewritten_query=rewritten,
        retrieval_query=retrieval_query(original, rewritten),
        # Narrowed to the closed set here rather than raised on, because this is model output
        # and an unrecognised facet name is an ordinary generation miss. `Rewrite.__post_init__`
        # still raises on one, which is the check for OUR code: a facet key invented in the
        # runner or the playground is a bug, and a facet key invented by a model is Tuesday.
        facets={key: value for key, value in proposal.facets.items() if key in FACET_KEYS},
        fallback=None,
    )
