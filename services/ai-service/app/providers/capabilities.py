"""Which of the five vendors can actually embed, and which can actually rerank.

Before this module the answer lived in prose, in three places, and the three did not agree.
``contract.py`` said *"only two of the five vendors can embed and only two can rerank"* and
named neither pair; forty lines further down the same file said embedding was "verified
present on OpenAI, NVIDIA NIM and OpenRouter", which is three; ``app/rag/rerank.py`` named
NIM plus "OpenRouter via specific models"; and ``docs/23-unverified-claims.md`` rows 157–158
record that **no adapter has ever called any of these endpoints**, so none of it was ever
evidence. A capability matrix that exists only as a comment is one nobody can query, test or
trust, and two other subtrees already depend on knowing it:

Worth recording, because it is the argument for citations rather than for confidence: of those
three disagreeing statements, the deleted one naming *"OpenAI, NVIDIA NIM and OpenRouter"* was
**correct** — the vendor documentation below says exactly that trio — and the count that
replaced it was not. It was still right to delete: an uncited claim that happens to be true is
indistinguishable from an uncited claim that is not, and the platform had no way to tell which
of the three it was holding.

* ``app/rag/rerank.py`` gates stage 11 on ``rerank_gate(provider_supports=...)``.
* ``app/ingestion/embedding/embedder.py`` needs ``EmbedCallable`` to be satisfiable at all —
  a bot on a provider that cannot embed **cannot ingest**, which makes this a
  connection-save-time validity question rather than a degradation.

So the matrix is data here, with a **source per cell**, and the gate is a function.

## The count is three and two, and every cell now names vendor documentation

The first version of this module read the *skills* and found "one sourced embedder, one
sourced reranker", with four embedding cells and one rerank cell ``UNVERIFIED``. That was an
honest reading of the tree and a wrong statement about the world: the skills were simply
silent, and silence had been recorded as a gap rather than resolved by reading the vendors.
Every cell below has since been checked against the vendor's own current documentation, and
the date it was read is part of the citation because four of these five vendors are hosted
services whose catalogue can move without a diff anywhere in this repository.

===========  ===========  ===========  ==================================================
Provider     ``embed``    ``rerank``   Authority (vendor docs, read 2026-08-07)
===========  ===========  ===========  ==================================================
openai       SUPPORTED    UNSUPPORTED  official openapi.yaml: ``/embeddings``, no rerank
anthropic    UNSUPPORTED  UNSUPPORTED  "Anthropic does not offer its own embedding model"
deepseek     UNSUPPORTED  UNSUPPORTED  complete API reference: five routes, neither here
nvidia_nim   SUPPORTED    SUPPORTED    API Catalog "Retrieval" family: both endpoints
openrouter   SUPPORTED    SUPPORTED    ``/api/v1/embeddings`` and ``/api/v1/rerank``
===========  ===========  ===========  ==================================================

**Nothing is ``UNVERIFIED`` any more.** That state is kept, and kept meaningful, for the
unknown-provider fallback (``_UNKNOWN``) and for the next vendor or surface nobody has read
yet. It is not a hedge and it is not the same as ``UNSUPPORTED``: it says the claim has no
authority behind it and is therefore treated as unavailable until documentation or a recorded
fixture says otherwise, which is the same rule ``nvidia-nim-api`` already applies to
``STRUCTURED_OUTPUT`` on NIM.

The two ``UNSUPPORTED`` embedding cells are the ones that carry consequences, and they are
consequences of a different kind from the rerank denials. **An organization whose only
connection is Anthropic or DeepSeek cannot ingest a single document** — not degraded, not
slower: it has a chat provider and no vector space. That is finding C1, and it is now a
permanent fact about two named vendors rather than an open question about four.

Failing closed is still the correct direction for both surfaces and for opposite reasons. An
optimistic rerank cell is a 404 on the request path where a skip was available. An optimistic
embed cell is worse: it lets an organization save a connection it cannot ingest with, and the
failure surfaces after the parse and the OCR spend.

## Two axes for embedding, THREE for rerank, and all of them must agree

A capability question here is really two questions, and collapsing them is how a row claiming
``Capability.RERANK`` on an OpenAI model reaches a request:

1. **Does the vendor publish the endpoint at all?** Repository-level, static, sourced from
   vendor documentation, and recorded in ``PROVIDER_TASKS`` below.
2. **Does this particular model serve it?** Per row, from ``provider_models.capability_flags``
   (``docs/11`` §16.2) — never parsed from the model id string, for the reason
   ``Capability`` records at length.

``can_embed`` is the AND of those two. **``can_rerank`` is the AND of three**, and the third
is finding #47 — see the next section. Nothing else in the platform should ask either question
a second way.

## The third axis: can this platform CONSUME the endpoint? (finding #47)

The two axes above are both statements about the vendor. Reranking needs one more, and it is
a statement about us, which is why it was missed: **a rerank result this pipeline cannot
threshold is a rerank this pipeline cannot run at all.**

Read out of ``app/rag/`` rather than assumed, and every step is a hard refusal rather than a
preference:

* ``rerank.RerankCalibration.__post_init__`` (``rerank.py:183-189``) raises ``ValueError`` on
  any scale whose ``may_threshold`` is False. ``UNCALIBRATED`` is the only such member, so a
  calibration for an uncalibrated provider is **unconstructible** — not missing, not awaiting
  an evaluation run: unconstructible.
* ``rerank.rerank`` (stage 11) takes a ``RerankCalibration`` as a required argument
  (``rerank.py:398-410``), and ``rerank.calibration_for`` raises ``RerankNotCalibrated`` when
  there is none.
* ``evidence.apply_threshold`` (stage 12) refuses a skipped outcome outright and raises
  ``RerankScaleMismatch`` when ``Scored.scale`` differs from ``calibration.scale``
  (``evidence.py:454-470``).

So there is **no ordering-only path**. Four places in this package used to say an
``UNCALIBRATED`` score was "usable for ordering, never for thresholding"; the first half of
that sentence describes a code path that does not exist and never did. The only
calibration-free route through stage 12 is ``evidence.select_unranked``, which selects on
branch agreement and never calls a reranker. A provider with an uncharacterized scale
therefore has exactly two possible outcomes today: a ``RerankNotCalibrated`` when the bot's
configuration is resolved, or — if a calibration were forced into existence — a
``RerankScaleMismatch`` on every single request. Neither is "ordering".

**OpenRouter is the whole of that finding, and its answer is not "not yet".**
``PROVIDER_TASKS[("openrouter", RERANK)]`` stays ``SUPPORTED``, because that cell is a
statement about the vendor and the vendor does publish ``POST /api/v1/rerank``; flipping it to
``UNSUPPORTED`` would be recording a denial nobody made, which ``Support.UNSUPPORTED``
explicitly forbids. ``OpenRouterAdapter.rerank`` stays for the same reason — the method-versus-
matrix drift test asserts the two move together. What changes is that ``can_rerank`` and
``assert_row_coherent`` now ask the third question, so an ``openrouter`` rerank row is refused
when it is EXAMINED rather than degrading into an exception on a request path -- but read
``assert_row_coherent``'s own docstring before relying on that sentence, because the only thing
that examines a row is the embedding-readiness walk. A rerank row is examined by nothing
(finding J1).

**And the ineligibility is structural rather than pending.** ``RERANK_SCALE`` is keyed by
*provider*. On a gateway the score scale is not a property of the provider: OpenRouter's
``/api/v1/rerank`` fronts several upstream cross-encoders on one credential and documents no
normalization between them, so ``relevance_score`` means whatever the upstream that served
*this request* meant by it. No evaluation run over the golden corpus can fill a per-provider
cell for a quantity that is per-upstream. Making OpenRouter rerank-eligible is therefore a
contract change — re-keying ``RERANK_SCALE`` on ``(provider, model)`` or on the resolved
upstream, and pinning the upstream hard enough that the measurement stays true — and not a
missing table entry. That is reported as a contract gap, not worked around here.

NVIDIA NIM is the contrast that makes the line worth drawing: its ``LOGIT`` is thresholdable,
its ``CALIBRATIONS`` entry is genuinely one evaluation run away, and nothing here refuses it.

## Why this is a table and not a Protocol method that raises

``contract.py`` already argues, correctly, that a ``Protocol`` whose method raises
``NotImplementedError`` on four of five implementations is a lie that type-checks: it turns a
capability *question* into an exception-handling problem at every call site, and the call site
that forgets the ``except`` is the ingestion path where a failure costs a re-parse.

The complementary gate it chose — **absence of the method** — is the strongest statement
available to mypy, and it is kept: ``AnthropicAdapter`` does not define ``embed``, so
``_assert_embeds(AnthropicAdapter())`` does not compile. But absence of a method is not a
question a caller can *ask*; asking it means ``hasattr`` or ``isinstance``, which is the same
runtime branch in a different costume, and neither can carry a source or a score scale.

So the two gates are deliberately redundant and are asserted **against each other**:
``tests/unit/test_provider_capability_matrix.py`` fails if any adapter defines a method the
matrix says it should not have, or omits one the matrix says it should. That mutual check is
the drift this module exists to prevent — a flag can be set to ``True`` by an edit that
changes no behaviour, and a method can be added by an edit that updates no flag. Neither is
possible on its own without a red test.

## What this must never do

**A capability answer may never manufacture a call failure.** ``can_embed`` and ``can_rerank``
are total: they return ``False`` for an unknown provider, an incoherent row, or a missing
cell, and they raise nothing on any input. ``RerankSkipReason`` in ``app/rag/rerank.py`` is a
closed enum of *pre-call* reasons with deliberately no member for "the provider errored", and
a gate that could raise would let an exception be caught and recorded as
``PROVIDER_LACKS_CAPABILITY`` — an outage laundered into a skip, whose only symptom is answer
quality drifting for as long as nobody looks.

The loud path is separate and runs at a different time: ``assert_row_coherent`` raises
``KbError(ErrorClass.VALIDATION, ...)`` when a row's flags disagree with the vendor matrix,
and it is called when a connection is saved — not on the request path.

## Resolved: there is now one ``RerankScale``, and one ``may_threshold``

``contract.RerankScale`` reads ``LOGIT | SIGMOID | UNIT_INTERVAL | UNCALIBRATED``.
``app/rag/rerank.py`` used to define a second type of the same name (``LOGIT | SIGMOID |
UNIT_INTERVAL``) while this one read ``PROBABILITY | LOGIT | UNCALIBRATED`` — two types sharing
one member, no conversion, across a boundary that type-checked. Widening this enum to a strict
superset turned the fix into a deletion, and ``retrieval-engineer`` has since deleted the local
copy and imported this one.

One predicate on it was wrong for a while and is worth keeping the record of, because it is the
same defect one level down. ``may_threshold`` was written as an alias of ``is_bounded``, which
answers ``False`` for ``LOGIT`` — and ``LOGIT`` is the scale of the only provider in
``RERANK_SCALE``. Taken literally it made the evidence threshold unreachable for every
organization: stage 11 reorders, stage 12 can never cut, and the bot answers from whatever the
reranker put first with no gate anywhere. Boundedness is a question about the **range** a
threshold lies in; thresholdability is a question about **calibration**. ``may_threshold`` is
now ``self is not UNCALIBRATED``.

The distinction earns its keep on the second reranker. OpenRouter's ranking endpoint is
``SUPPORTED`` on the vendor axis, and it is deliberately **absent from ``RERANK_SCALE``**,
which makes it ``UNCALIBRATED``. That is not a placeholder awaiting a plausible guess: the
vendor is a gateway whose ``/api/v1/rerank`` fronts several upstream cross-encoders on one
credential and documents no normalization across them, so the meaning of ``relevance_score``
varies with which upstream served the request. A scale is a property of a measured
distribution, and there is no single distribution here to measure.

What that costs it is the whole of finding #47, above: ``UNCALIBRATED`` was described here and
in three other files as "ordering only", and there is no ordering-only path in the pipeline
that consumes this. So an uncharacterized scale is not a reduced capability, it is the absence
of one, and ``can_rerank`` says so.

One item still belongs to ``retrieval-engineer`` and is stated in the report rather than
reached across for: ``Scored.scale`` must be populated from ``RerankResult.scale`` — the
adapter's own report — rather than from ``calibration.scale``, or
``evidence.apply_threshold``'s scale assertion compares a value to itself and never sees what
the adapter actually returned.
"""

from __future__ import annotations

from collections.abc import Mapping
from enum import StrEnum
from typing import Final

from pydantic import BaseModel, ConfigDict, model_validator

from app.core.errors import ErrorClass, KbError
from app.providers.contract import Capability, ModelCapabilities, RerankScale
from app.providers.errors import ProviderSurface

__all__ = [
    "PROVIDERS",
    "PROVIDER_TASKS",
    "RERANK_SCALE",
    "Support",
    "TaskSupport",
    "assert_row_coherent",
    "can_embed",
    "can_rerank",
    "provider_offers",
    "providers_offering",
    "rerank_scale",
    "task_support",
]

#: Every adapter's ``name``, and the only legal first component of a matrix key. Bounded at
#: five by ADR-001, which is what makes it safe as a metric label
#: (`kb-observability-conventions` bans unbounded ones). A sixth entry is an ADR, not an edit.
PROVIDERS: Final[tuple[str, ...]] = (
    "openai",
    "anthropic",
    "deepseek",
    "nvidia_nim",
    "openrouter",
)


class Support(StrEnum):
    """What we actually know about one (vendor, task) cell. **Three states, not two.**

    The third state is the whole point. Collapsing ``UNVERIFIED`` into ``UNSUPPORTED`` loses
    the difference between "the vendor documents that it does not do this" and "nobody has
    checked", and those need opposite follow-up: the first is closed, the second is a fixture
    somebody owes. Collapsing it into ``SUPPORTED`` is worse — it ships an unchecked claim as
    a fact, which is precisely how ``contract.py`` came to assert an embedding matrix that
    contradicted itself two definitions apart.

    Both non-supported states gate identically at runtime, so an unverified cell degrades
    exactly like an absent endpoint and never like an error.
    """

    #: A skill or vendor document positively states the endpoint exists. ``source`` names it.
    SUPPORTED = "supported"
    #: The endpoint is positively absent. ``source`` names the authority, and there are two
    #: admissible kinds. The first is a **denial**: a sentence saying the vendor does not
    #: offer this ("Anthropic does not offer its own embedding model"). The second is an
    #: **enumerated absence**: the vendor's own complete, machine-readable surface list — an
    #: OpenAPI document, an API-reference index — in which the route does not appear. Both are
    #: checkable and both are dated, which is what separates them from silence; an enumeration
    #: is the weaker of the two only in that a vendor can add a route without retracting
    #: anything, so the note records what was enumerated and when. This is a finding, not an
    #: absence of one.
    UNSUPPORTED = "unsupported"
    #: No authority either way. Treated as unavailable until a recorded fixture says
    #: otherwise; never treated as an error.
    UNVERIFIED = "unverified"

    @property
    def available(self) -> bool:
        """The one place the three states collapse to two. Only ``SUPPORTED`` is usable."""
        return self is Support.SUPPORTED


class TaskSupport(BaseModel):
    """One cell of the matrix: a verdict, its authority, and what the authority actually said.

    ``source`` is mandatory on a decided cell and forbidden on an undecided one, enforced
    below rather than in review. That asymmetry is the load-bearing part: it makes marking a
    vendor ``SUPPORTED`` impossible without naming what says so, which is the edit that would
    otherwise silently re-introduce the unsourced "two and two" this module replaced.
    """

    model_config = ConfigDict(frozen=True, extra="forbid")

    support: Support
    #: The skill file, section, or vendor document the verdict comes from. Empty **only** on
    #: ``UNVERIFIED``.
    source: str
    #: What that authority says, verbatim where it is short enough, plus the divergence a
    #: reader needs before writing the adapter method. On an ``UNVERIFIED`` cell this records
    #: what was searched and came back empty — otherwise the next person searches again.
    note: str

    @model_validator(mode="after")
    def _source_matches_verdict(self) -> TaskSupport:
        if self.support is Support.UNVERIFIED:
            if self.source:
                raise ValueError(
                    "an UNVERIFIED cell must not name a source — if there is one, the cell is "
                    "SUPPORTED or UNSUPPORTED"
                )
        elif not self.source:
            raise ValueError(
                f"{self.support.value} is a claim; name the skill, section or vendor document "
                "behind it. An uncited claim is UNVERIFIED"
            )
        if not self.note:
            raise ValueError("every cell records what was found, including when nothing was")
        return self


#: What is returned for a provider name that is not one of the five, or for a cell that
#: somehow escaped the totality assertion at the bottom of this file. Fail closed and never
#: raise: these functions are called from the retrieval gate, and an exception there could be
#: caught and recorded as a skip, which is the outage-laundered-into-a-skip failure
#: ``app/rag/rerank.py`` is arranged to prevent.
_UNKNOWN: Final[TaskSupport] = TaskSupport(
    support=Support.UNVERIFIED,
    source="",
    note="not a KnowledgeBot provider, or a cell missing from PROVIDER_TASKS",
)

_SKILL_NIM = ".claude/skills/nvidia-nim-api/SKILL.md § Non-negotiables (1st bullet)"
_SKILL_RERANK = ".claude/skills/bge-reranker/SKILL.md § Non-negotiables (1st bullet)"
_SKILL_EMBED = ".claude/skills/bge-m3-embeddings/SKILL.md § Gotchas + § Official docs"

# ── vendor documentation, with the date it was read ───────────────────────────
# A skill is a statement about this platform; these are statements by the vendor about its own
# product, and on a hosted API only the second kind can settle whether a route exists. The date
# is part of the citation and is not decoration: four of these five are catalogues that can gain
# or lose a family between two deploys of ours, with no diff anywhere in this repository. Re-read
# them when a cell is challenged rather than trusting the verdict's age.
_READ = "read 2026-08-07"
_DOC_OPENAI_SURFACES = (
    f"https://github.com/openai/openai-openapi -> openapi.yaml ({_READ}); "
    "OpenAI's own published API description"
)
_DOC_ANTHROPIC_EMBED = f"https://docs.claude.com/en/docs/build-with-claude/embeddings ({_READ})"
_DOC_ANTHROPIC_SURFACES = (
    f"https://github.com/anthropics/anthropic-sdk-python -> api.md ({_READ}); "
    "Anthropic's own generated resource index"
)
_DOC_DEEPSEEK_SURFACES = (
    f"https://api-docs.deepseek.com/api/deepseek-api + /sitemap.xml ({_READ}); "
    "the complete API-reference index"
)
_DOC_NIM_EMBED = (
    f"https://docs.api.nvidia.com/nim/reference/nvidia-nv-embedqa-e5-v5-infer ({_READ})"
)
_DOC_OR_EMBED = f"https://openrouter.ai/docs/api_reference/embeddings ({_READ})"
_DOC_OR_RERANK = (
    "https://openrouter.ai/docs/cookbook/evaluate-and-optimize/rag + "
    f"https://openrouter.ai/docs/client-sdks/python/sdks/rerank ({_READ})"
)

#: **The matrix.** Fifteen cells: five vendors x three call families. Total by assertion at
#: import, because a lookup that raises ``KeyError`` inside the retrieval gate cannot be
#: handled there — the same reason ``app/core/errors.py`` checks its own tables at import.
#:
#: Keyed on ``ProviderSurface`` rather than a local enum so that the axis the fallback ban in
#: ``errors.NON_CHAT_FALLBACK_ELIGIBLE`` is keyed on and the axis capability is keyed on are
#: the same axis. Two parallel enums for "which family of provider call is this" would drift
#: the first time a fourth family is added.
PROVIDER_TASKS: Final[Mapping[tuple[str, ProviderSurface], TaskSupport]] = {
    # ── chat ──────────────────────────────────────────────────────────────────
    # Uncontroversial and listed anyway: a matrix with a hole in it is a matrix somebody
    # completes from memory, and the totality assertion is what keeps the hole impossible.
    ("openai", ProviderSurface.CHAT): TaskSupport(
        support=Support.SUPPORTED,
        source=".claude/skills/openai-api/SKILL.md",
        note="Responses API, POST /v1/responses. See openai_adapter.py.",
    ),
    ("anthropic", ProviderSurface.CHAT): TaskSupport(
        support=Support.SUPPORTED,
        source=".claude/skills/anthropic-api/SKILL.md",
        note="Messages API. See anthropic.py.",
    ),
    ("deepseek", ProviderSurface.CHAT): TaskSupport(
        support=Support.SUPPORTED,
        source=".claude/skills/deepseek-api/SKILL.md",
        note="OpenAI-shaped chat/completions at api.deepseek.com. See deepseek.py.",
    ),
    ("nvidia_nim", ProviderSurface.CHAT): TaskSupport(
        support=Support.SUPPORTED,
        source=_SKILL_NIM,
        note="Hosted API Catalog, OpenAI-shaped chat/completions. See nim.py.",
    ),
    ("openrouter", ProviderSurface.CHAT): TaskSupport(
        support=Support.SUPPORTED,
        source=".claude/skills/openrouter-api/SKILL.md",
        note="Gateway over hundreds of upstreams, one credential. See openrouter.py.",
    ),
    # ── embedding ─────────────────────────────────────────────────────────────
    # Three sourced, two positively denied, none unread. This is the consequential column:
    # a provider that cannot embed cannot ingest at all (docs/23 row 158), so these verdicts
    # are connection-save validity rather than degradation, and the two denials below are the
    # whole of finding C1 with the vendors finally named.
    ("openai", ProviderSurface.EMBEDDING): TaskSupport(
        support=Support.SUPPORTED,
        source=f"{_DOC_OPENAI_SURFACES}; {_SKILL_EMBED}",
        note=(
            "POST /v1/embeddings, present in OpenAI's published openapi.yaml. bge-m3-embeddings "
            "adds the two behaviours the pipeline depends on: text-embedding-3-large accepts "
            "8192 tokens and ERRORS above it — the loud over-window failure "
            "PROVIDER_TRUNCATION_POLICY='reject' depends on — and `dimensions` truncates the "
            "vector (Matryoshka), which is why the same model at two widths is two "
            "EmbeddingSpaces. kb-chunking-rules' worked identity example is "
            "`emb/v1:openai:text-embedding-3-large:d3072:...`."
        ),
    ),
    ("anthropic", ProviderSurface.EMBEDDING): TaskSupport(
        support=Support.UNSUPPORTED,
        source=_DOC_ANTHROPIC_EMBED,
        note=(
            "A denial in the vendor's own words, under the heading 'How to get embeddings with "
            "Anthropic': 'Anthropic does not offer its own embedding model.' The page then "
            "recommends a third party (Voyage AI), which is a different vendor, a different "
            "credential and a sixth adapter — an ADR, not an edit. Corroborated by the SDK's "
            f"resource index ({_DOC_ANTHROPIC_SURFACES}), whose only families are Messages, "
            "Models and Beta. This is the permanent half of finding C1: an Anthropic-only "
            "organization has a chat provider and no vector space, today and on any schedule "
            "this platform controls."
        ),
    ),
    ("deepseek", ProviderSurface.EMBEDDING): TaskSupport(
        support=Support.UNSUPPORTED,
        source=_DOC_DEEPSEEK_SURFACES,
        note=(
            "An enumerated absence rather than a denial sentence, and the enumeration is "
            "complete: DeepSeek's API reference indexes exactly five operations — create chat "
            "completion, create completion (FIM beta), create response, get user balance, list "
            "models — and neither an embeddings nor a rerank route is among them. The docs also "
            "advertise an /anthropic compatibility surface, which is a second shape of the chat "
            "endpoint and not a new family. Re-check the index rather than the verdict if this "
            "is ever challenged: an added route retracts nothing, so age is the only way this "
            "cell goes wrong."
        ),
    ),
    ("nvidia_nim", ProviderSurface.EMBEDDING): TaskSupport(
        support=Support.SUPPORTED,
        source=_DOC_NIM_EMBED,
        note=(
            "POST https://integrate.api.nvidia.com/v1/embeddings on the hosted API Catalog, "
            "whose 'Retrieval' family holds both the embedding and the ranking models. Pin "
            "`nvidia/nv-embedqa-e5-v5` (1024-wide). Three divergences the adapter must carry: "
            "`input_type` is REQUIRED and takes 'passage' or 'query' — the vendor's own words "
            "are that failing to use the correct one 'will result in large drops in retrieval "
            "accuracy', which is a silent quality failure, so the row needs "
            "Capability.EMBEDDING_INPUT_TYPE; `truncate` defaults to 'NONE', which ERRORS on an "
            "over-window input rather than trimming it (the other values, START and END, "
            "discard text silently and are never sent); and the input array is capped at 4096 "
            "items with a per-input maximum of 8192 tokens. Usage comes back as "
            "{prompt_tokens, total_tokens} and there is no cached bucket."
        ),
    ),
    ("openrouter", ProviderSurface.EMBEDDING): TaskSupport(
        support=Support.SUPPORTED,
        source=_DOC_OR_EMBED,
        note=(
            "POST https://openrouter.ai/api/v1/embeddings, OpenAI-shaped, with the same "
            "`provider` routing block as chat and the same one-credential-many-upstreams "
            "attribution problem. Model ids are namespaced upstreams — the vendor's examples "
            "are `openai/text-embedding-3-small`, `openai/text-embedding-3-large` and "
            "`qwen/qwen3-embedding-0.6b` — and the catalogue is enumerable only with a "
            "credential (GET /api/v1/embeddings/models), so WHICH models exist is not sourced "
            "here; that the endpoint exists is. One documented behaviour outranks the "
            "convenience: under Limitations the vendor says texts over a model's maximum "
            "'will be truncated OR rejected', with no stated default and no per-model table. "
            "That is the silent-truncation hazard PROVIDER_TRUNCATION_POLICY exists for, and "
            "it means an over-window passage may return a 200 and a plausible vector here "
            "where OpenAI and NIM both return an error. The adapter must enforce the window "
            "itself and never rely on the vendor to refuse."
        ),
    ),
    # ── rerank ────────────────────────────────────────────────────────────────
    # Three positive denials and two affirmations. The column reads "two", which is what
    # contract.py used to assert for BOTH columns without naming a pair — it happened to be
    # right here and wrong about embedding, which is why a count is never the claim: the pair
    # is.
    ("openai", ProviderSurface.RERANK): TaskSupport(
        support=Support.UNSUPPORTED,
        source=f"{_SKILL_RERANK}; {_DOC_OPENAI_SURFACES}",
        note=(
            "'OpenAI, Anthropic and DeepSeek do not offer one.' Same wording in nvidia-nim-api, "
            "and corroborated by enumeration: OpenAI's published openapi.yaml describes 182 "
            "paths and none of them is a ranking route."
        ),
    ),
    ("anthropic", ProviderSurface.RERANK): TaskSupport(
        support=Support.UNSUPPORTED,
        source=f"{_SKILL_RERANK}; {_DOC_ANTHROPIC_SURFACES}",
        note=(
            "'OpenAI, Anthropic and DeepSeek do not offer one.' Same wording in nvidia-nim-api, "
            "and corroborated by enumeration: the SDK's resource index has three families — "
            "Messages, Models, Beta — and no ranking route."
        ),
    ),
    ("deepseek", ProviderSurface.RERANK): TaskSupport(
        support=Support.UNSUPPORTED,
        source=f"{_SKILL_RERANK}; {_DOC_DEEPSEEK_SURFACES}",
        note=(
            "'OpenAI, Anthropic and DeepSeek do not offer one.' Same wording in nvidia-nim-api, "
            "and corroborated by the same five-route enumeration that denies the embedding "
            "cell above."
        ),
    ),
    ("nvidia_nim", ProviderSurface.RERANK): TaskSupport(
        support=Support.SUPPORTED,
        source=f"{_SKILL_NIM}; {_SKILL_RERANK}",
        note=(
            "'Of the five configured providers only NVIDIA NIM exposes a ranking endpoint.' "
            "The ranking models carry their own request schema, unrelated to the chat schema, "
            "and an UNBOUNDED LOGIT score scale — see RERANK_SCALE. NIM chat and NIM rerank "
            "must fail independently: a ranking outage degrades ranking for every org "
            "configured against it while chat continues on a fallback."
        ),
    ),
    ("openrouter", ProviderSurface.RERANK): TaskSupport(
        support=Support.SUPPORTED,
        source=_DOC_OR_RERANK,
        note=(
            "POST https://openrouter.ai/api/v1/rerank, Cohere-shaped: {model, query, documents, "
            "top_n} in, `results[]` of {index, relevance_score, document} out, with the same "
            "`provider` routing block as chat. The skills' half-sentence — 'OpenRouter reaches "
            "one only through specific models' (nvidia-nim-api, bge-reranker) — turns out to be "
            "right and to have been contradicted, in the same bullet, by the claim that NIM is "
            "'the sole implementation of the rerank() capability'. The vendor documents a "
            "first-class route; the model is what is namespaced (its example is "
            "`cohere/rerank-v3.5`). "
            "WHAT IS STILL NOT SOURCED IS THE SCORE SCALE, and that is why this provider is "
            "deliberately absent from RERANK_SCALE and therefore UNCALIBRATED: the route fronts "
            "several upstream cross-encoders on one credential and no normalization across them "
            "is documented anywhere, so `relevance_score` means whatever the upstream that "
            "served this particular request meant by it. "
            "CONSEQUENCE, finding #47: this cell stays SUPPORTED because it is a statement "
            "about the VENDOR and the vendor does publish the route — but openrouter is NOT "
            "rerank-eligible, because RERANK_SCALE has no entry for it and there is no "
            "ordering-only path in the pipeline that consumes this. can_rerank answers False "
            "for it. (assert_row_coherent would refuse such a row, but nothing calls it on a "
            "write path -- finding J1 -- so the row saves and simply never reranks.) "
            "The earlier wording here "
            "was 'ordering only, never a threshold, until an evaluation run characterizes one "
            "exact (provider, model) pair', and both halves were wrong: nothing consumes an "
            "ordering-only rerank, and no evaluation run can fill a per-provider cell for a "
            "per-upstream quantity. See RERANK_SCALE's note for the contract change that "
            "would make it eligible."
        ),
    ),
}

#: What a provider's rerank scores MEAN, known before the call rather than read off the
#: response. Pre-call because ``app/rag/rerank.py`` resolves a ``RerankCalibration`` when a
#: bot's configuration is resolved, and a calibration whose scale disagrees with the adapter's
#: is the "0.30 applied to a logit" failure: nothing raises, every score stays a plausible
#: float, and only the refusal rate moves.
#:
#: Anything unlisted is ``UNCALIBRATED``. **Membership of this table is what makes a provider
#: rerank-eligible** (finding #47): an unlisted provider cannot be thresholded, stage 12 cannot
#: be skipped, and there is no ordering-only path, so ``can_rerank`` and ``assert_row_coherent``
#: both read it. The default is not a placeholder to be filled in with a plausible guess — an
#: unlisted provider is one whose scale nobody has characterized, and adding a row here is a
#: claim about a measured distribution.
RERANK_SCALE: Final[Mapping[str, RerankScale]] = {
    #: NVIDIA's ranking endpoint returns an unbounded signed logit; its own published example
    #: ranks 0.226, -1.17, -1.52. A bounded threshold applied to this passes almost
    #: everything (`bge-reranker`, `nvidia-nim-api`).
    "nvidia_nim": RerankScale.LOGIT,
    # `openrouter` is SUPPORTED on the rerank surface and is deliberately NOT listed, which
    # under finding #47 means it is NOT rerank-eligible — the vendor publishes the route and
    # this platform cannot consume it. Its route fronts several upstream cross-encoders on one
    # credential with no documented normalization between them, so there is no single
    # distribution to characterize and `UNCALIBRATED` is the true answer rather than a missing
    # one. Adding it here — even to UNIT_INTERVAL, which is what Cohere-shaped
    # `relevance_score` looks like — would assert a calibration nobody measured and would make
    # a threshold constructible against it.
    #
    # NOTE THE SHAPE OF THE FIX, because it is not an evaluation run. This table is keyed by
    # PROVIDER, and on a gateway the scale is a property of the upstream that served the
    # request. Characterizing `openrouter` as such is not possible at any measurement budget;
    # re-keying this table on (provider, model) — with the upstream pinned hard enough by
    # `provider.order` + `allow_fallbacks: false` for the measurement to stay true — is the
    # contract change that would make it possible. Recorded as a gap, not attempted here.
}


def task_support(provider: str, surface: ProviderSurface) -> TaskSupport:
    """The matrix cell, with its source. Total: never raises, never returns ``None``."""
    return PROVIDER_TASKS.get((provider, surface), _UNKNOWN)


def provider_offers(provider: str, surface: ProviderSurface) -> bool:
    """Does this VENDOR publish the endpoint at all? Axis 1 — of two for embedding, THREE for
    rerank (see this module's header): the third is whether this platform can consume the scale
    the vendor returns, and ``assert_row_coherent`` refuses on it independently."""
    return task_support(provider, surface).support.available


def providers_offering(surface: ProviderSurface) -> frozenset[str]:
    """Every provider with a sourced endpoint on this surface.

    Pinned by a test, so the sets — ``{"openai", "nvidia_nim", "openrouter"}`` for embedding
    and ``{"nvidia_nim", "openrouter"}`` for rerank — cannot change without a visible diff in
    an assertion. That is what makes a vendor adding or withdrawing an endpoint a contract
    change somebody reviews rather than a behaviour change nobody sees (`docs/23` rows
    157–158). The sets are asserted by membership and not by size: a two-element set is small
    enough that a length check passes for the wrong reason.
    """
    return frozenset(name for name in PROVIDERS if PROVIDER_TASKS[name, surface].support.available)


def can_embed(provider: str, caps: ModelCapabilities) -> bool:
    """Can this ``(provider, model)`` embed? Both axes, no ``try``, no vendor knowledge.

    The AND is not a formality. A row carrying ``Capability.EMBEDDING`` on a vendor with no
    embedding endpoint is a configuration defect, and answering ``True`` to it would send a
    request that 404s in the middle of an ingest run, after the parse and the OCR have been
    paid for. Answering ``False`` refuses the configuration instead, which is the failure the
    operator can act on.

    **This answers "can this pair embed", never "which of the organization's connections
    embeds".** That second question is finding C1 and it lives in
    ``app/providers/embedding_selection.py``, which asks this predicate and asks it once. It
    is a separate module because its input is a per-tenant set of connections rather than a
    sourced vendor fact, and mixing the two would dilute the totality assertion at the bottom
    of this file. Nothing else in the platform may resolve an embedding connection its own
    way: the ``(provider, model)`` pair is the vector space, so two resolution paths are a
    correctness bug rather than an inconsistency.
    """
    return (
        provider_offers(provider, ProviderSurface.EMBEDDING)
        and Capability.EMBEDDING in caps.supported
    )


def can_rerank(provider: str, caps: ModelCapabilities) -> bool:
    """Can this ``(provider, model)`` rerank? Feeds ``rerank_gate(provider_supports=...)``.

    **Three axes, not two, and the third is finding #47.** The vendor must publish the
    endpoint, the row must claim it, *and* the provider's score scale must be one this
    pipeline can threshold. The third is not a quality preference: ``RerankCalibration``
    refuses to be constructed on an unthresholdable scale, stage 11 requires a calibration and
    stage 12 asserts against it, so a provider with an ``UNCALIBRATED`` scale has no reachable
    path through reranking at all. Answering ``True`` for one sends the request down a route
    whose only two endings are ``RerankNotCalibrated`` at configuration time and
    ``RerankScaleMismatch`` on every request — see the module docstring, which reads the three
    refusals out of ``app/rag/`` with line numbers.

    ``rerank_scale`` is the same lookup ``RerankResult.scale`` is built from in every adapter,
    so this predicate and the value the provider will actually report cannot disagree.

    **Returns a verdict on every input and raises on none.** ``RerankSkipReason`` is closed
    and failure-free by design; a gate that could raise would let an exception be caught and
    recorded as ``PROVIDER_LACKS_CAPABILITY``, which turns an incident into a degraded mode
    nobody investigates.

    One honest imprecision, handed to ``retrieval-engineer`` rather than papered over here:
    when this returns ``False`` for an uncharacterized scale, ``rerank_gate`` reports
    ``PROVIDER_LACKS_CAPABILITY``, and the provider does have the endpoint. The accurate reason
    is a fifth ``RerankSkipReason`` member, which lives in ``app/rag/rerank.py`` and is not
    this module's to add. It is a defensive path either way — though not for the
    reason this paragraph used to give. It said ``assert_row_coherent`` refuses the row when it
    is saved, "so a request carrying one has bypassed save-time validation". There is no
    save-time validation (finding J1): nothing calls that function on a write path, and a
    rerank row never reaches it at all. What makes the branch defensive is this predicate
    itself — ``can_rerank`` answers ``False``, so nothing binds a ``Reranker`` — while the row
    stays perfectly savable.
    """
    return (
        provider_offers(provider, ProviderSurface.RERANK)
        and Capability.RERANK in caps.supported
        and rerank_scale(provider).may_threshold
    )


def rerank_scale(provider: str) -> RerankScale:
    """What this provider's rerank scores mean. ``UNCALIBRATED`` when nobody has said."""
    return RERANK_SCALE.get(provider, RerankScale.UNCALIBRATED)


def assert_row_coherent(provider: str, model: str, caps: ModelCapabilities) -> None:
    """The loud half. Raises when a ``provider_models`` row claims what the vendor cannot do.

    ── WHO ACTUALLY CALLS THIS, AND IT IS NOT THE SAVE PATH (finding J1) ─────────────────
    This docstring said for a long time that it is "called when a provider connection or a
    bot's model selection is **saved**". It is not, and it never was.
    ``grep -rn 'assert_row_coherent(' services/ai-service/app`` returns exactly one call site:
    ``embedding_selection.ineligibility()``, which invokes it inside a ``try`` and converts the
    ``KbError`` into an ``EmbeddingIneligibility.ROW_INCOHERENT`` rejection. That is correct
    where it stands — a capability question on a request path must become a value rather than
    an exception — but it means this function only ever runs over the connections
    ``embedding_readiness`` walks, i.e. **embedding candidates**.

    The catalogue itself is written by Laravel (``ProviderModelService``), which does not cross
    the seam for a metadata edit, deliberately: a coherence call there would fail an edit
    whenever ``ai-api`` is briefly down, for an operation that has no dependency today. So the
    reachability is split, and the second half is the one to remember:

    * an incoherent **embedding** row is reported, by name, in the readiness verdict's
      ``rejected[]`` — which is what every "refused when it is saved" sentence in this package
      was reaching for and got right only for this family;
    * an incoherent **rerank** row is reported **nowhere**. It saves with a 200, appears in no
      ``rejected[]``, and reranking silently never happens.

    That asymmetry is a recorded, accepted cost rather than a bug to fix locally — see finding
    J1 and its ADR in ``docs/22-spec-findings-and-decisions.md``. Do not repair it by adding a
    caller here; the decision is about the write path, not about this function.

    The RAISE-versus-degrade split below is still real, and is the same one
    ``app/rag/rerank.py`` draws between a skip and a failure: where this function runs, a
    mismatch is an error somebody can fix with the model in front of them; on the request path
    the same disagreement degrades quietly through ``can_rerank`` instead.

    Also rejects a row that claims two task families at once. ``Capability`` records that rows
    are task-exclusive — ``text-embedding-3-large`` and ``gpt-5.6-sol`` are different products
    reached through different endpoints — and a row claiming both describes a model that does
    not exist, so every validator downstream of it validates against the wrong schema.

    **And rejects a rerank row on a provider whose score scale nobody has characterized**
    (finding #47), which is a refusal about this platform rather than about the vendor and is
    worded that way. The alternative is worse than a quiet degradation: the row saves, the
    admin console shows reranking configured, and the bot then either fails to resolve its
    retrieval configuration (``RerankNotCalibrated``) or raises ``RerankScaleMismatch`` on
    every request. This is exactly the split the docstring above draws, applied to the one case
    where "did not run" is not available — and it is also, per finding J1, the branch nothing
    currently reaches: a rerank row never passes through this function at all.

    The check reads ``RERANK_SCALE`` rather than restating a provider name, so it lifts by
    itself the day a scale is characterized. For OpenRouter that day needs a contract change
    and not an evaluation run — ``RERANK_SCALE`` is keyed by provider and a gateway's scale is
    a property of the upstream — and the module docstring says so.

    Raises ``KbError(ErrorClass.VALIDATION, ...)``: 422, never retried, because the next
    attempt sends the identical row. Carries the provider, the model and the flag, and nothing
    else — a credential is never an input to this function and must never become one.
    """
    claimed = {
        ProviderSurface.EMBEDDING: Capability.EMBEDDING in caps.supported,
        ProviderSurface.RERANK: Capability.RERANK in caps.supported,
    }
    for surface, is_claimed in claimed.items():
        if not is_claimed:
            continue
        cell = task_support(provider, surface)
        if not cell.support.available:
            raise KbError(
                ErrorClass.VALIDATION,
                f"provider_models row {provider}/{model} carries "
                f"capability_flags.{surface.value} but {provider} is recorded as "
                f"{cell.support.value} on that surface in PROVIDER_TASKS. Prove the endpoint "
                "with a recorded fixture and move the matrix cell, rather than setting the "
                "flag — a flag can be set by an edit that changes no behaviour.",
            )

    if claimed[ProviderSurface.RERANK] and not rerank_scale(provider).may_threshold:
        raise KbError(
            ErrorClass.VALIDATION,
            f"provider_models row {provider}/{model} carries capability_flags.rerank, and "
            f"{provider} does publish a ranking endpoint — but its score scale is "
            f"{rerank_scale(provider).value}, and RerankCalibration cannot be constructed on "
            "a scale that may not be thresholded. Stage 12 is not optional and there is no "
            "ordering-only path, so this row can never produce an answer: it would raise "
            "RerankNotCalibrated when the bot's retrieval configuration is resolved. "
            "Characterize the (provider, model) pair and add it to RERANK_SCALE; on a gateway "
            "that also needs RERANK_SCALE re-keyed, because the scale belongs to the upstream "
            "that served the request and not to the credential in front of it.",
        )

    if claimed[ProviderSurface.EMBEDDING] and claimed[ProviderSurface.RERANK]:
        raise KbError(
            ErrorClass.VALIDATION,
            f"provider_models row {provider}/{model} claims both embedding and rerank. Rows "
            "are task-exclusive: they are different products on different endpoints with "
            "different request schemas, and a row claiming both is validated against neither.",
        )

    chat_flags = frozenset({Capability.TEXT, Capability.TOOL_USE, Capability.REASONING})
    if any(claimed.values()) and caps.supported & chat_flags:
        raise KbError(
            ErrorClass.VALIDATION,
            f"provider_models row {provider}/{model} carries chat capability flags alongside "
            "an embedding or rerank flag. The chat flags are meaningless on a non-chat row "
            "rather than merely false; see Capability's note on task-exclusive rows.",
        )


# Import-time checks, for the reason ``app/core/errors.py`` states for its own: a partially
# populated table must not reach a running process, and the lookups above are called from a
# retrieval gate where a KeyError could be mistaken for a skip.
assert set(PROVIDER_TASKS) == {(p, s) for p in PROVIDERS for s in ProviderSurface}
assert len(PROVIDER_TASKS) == len(PROVIDERS) * len(ProviderSurface) == 15
# Every vendor chats. If this ever fails, something removed an adapter rather than a flag.
assert all(PROVIDER_TASKS[p, ProviderSurface.CHAT].support.available for p in PROVIDERS)
# A scale may only be declared for a provider that actually reranks — otherwise the entry is
# a calibration waiting to be applied to an endpoint that does not exist.
assert set(RERANK_SCALE) <= providers_offering(ProviderSurface.RERANK)
