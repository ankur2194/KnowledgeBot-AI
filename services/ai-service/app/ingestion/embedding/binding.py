"""Binding one organization's connection and credential into the narrow ``EmbedCallable``.

``EmbedCallable``'s docstring names this module without naming it: *"something binds the
organization's connection and its credential to this method and hands ingestion the narrowed
callable, which is exactly why ingestion cannot hold a secret, log one, or serialize one into a
Celery payload."* This is that something, and it is a separate file so the property is
structural rather than a convention — nothing under ``app/ingestion/`` other than this module
imports ``app.core.credentials``, and a grep proves it.

WHAT THE NARROWING BUYS
-----------------------
``EmbeddingAdapter.embed(req, caps, credential)`` takes the key as a per-call argument.
``EmbedCallable.__call__(texts, *, input_type, org_id, trace_id)`` has no field for one and no
field for a connection. The stage functions downstream take the second type, so a stage
**cannot** put a credential into a Celery payload, a span attribute, or a log line: it does not
have one. That is a stronger guarantee than a rule, and it is the reason the two signatures
were never unified.

The credential is opened once per bind and lives in a closure for the duration of one run. It
is never returned, never attached to the callable as an attribute, and never placed on the
``EmbeddingRequest`` — the adapter takes it as an argument at the moment of the call.

ADR-031 IS NOT RE-DECIDED HERE
-------------------------------
Which connection embeds is ``resolve_embedding_connection``'s answer and this module asks it
rather than choosing. Two eligible connections that disagree on ``(provider, model)`` are a
**refusal**, not a tiebreak, because that pair *is* the vector space — and a tiebreak here
would be a second, quieter implementation of a rule whose whole point is that there is one.
"""

from __future__ import annotations

from typing import Any

from app.core.credentials import open_credential, read_kek
from app.core.errors import ErrorClass, KbError
from app.providers.capabilities import can_embed
from app.providers.contract import (
    Capability,
    EmbeddingInputType,
    EmbeddingRequest,
    EmbeddingResult,
    ModelCapabilities,
)
from app.providers.embedding_selection import (
    EmbeddingConnection,
    EmbeddingDesignation,
    resolve_embedding_connection,
)

__all__ = ["bind_embedder", "load_candidates"]


async def load_candidates(
    conn: Any, *, org_id: str
) -> tuple[list[EmbeddingConnection], EmbeddingDesignation | None]:
    """This organization's embedding-capable connections and its designation, if it has one.

    A READ of control-plane tables. The write allow-list does not govern reads and must not be
    read as permitting them either way — the data plane reads the source of truth constantly,
    which is what makes Qdrant rebuildable; what it never does is write these tables.

    ``active`` connections only. A connection in any other state is one an operator has taken
    out of service, and embedding through it would spend against a key they believe is unused.
    """
    async with conn.cursor() as cur:
        await cur.execute(
            # `m.model` and NOT `m.model_id`. The column on `provider_models` is `model`
            # (`2026_08_07_000400_create_provider_models_table.php`) — the `_id` spelling is what
            # the *payload* field is called on the wire, and PostgreSQL reports the difference
            # only when this query runs, which is inside a Celery task on the ingestion path.
            "SELECT c.id, c.provider, m.model, m.capability_flags, m.context_window "
            "  FROM provider_connections c "
            "  JOIN provider_models m "
            "    ON m.provider_connection_id = c.id AND m.organization_id = c.organization_id "
            " WHERE c.organization_id = %s AND c.status = 'active' AND m.enabled = true "
            " ORDER BY c.id, m.model",
            (org_id,),
        )
        rows = await cur.fetchall()

        # THE DESIGNATION IS TWO COLUMNS ON `organizations`, NOT A TABLE. This query used to
        # name `embedding_designations`, which no migration creates — the control-plane half of
        # finding C1 put the designation on the organization row
        # (`2026_08_07_000500_add_embedding_designation_to_organizations_table.php`), with a
        # `num_nonnulls(...) <> 1` CHECK so the pair is set together or not at all. A missing
        # table does not degrade: it raises `UndefinedTable` and takes down every ingestion run
        # for every tenant, designated or not.
        await cur.execute(
            "SELECT embedding_connection_id, embedding_model FROM organizations WHERE id = %s",
            (org_id,),
        )
        designated_row = await cur.fetchone()

    connections = [
        EmbeddingConnection(
            connection_id=str(row[0]),
            provider=str(row[1]),
            model=str(row[2]),
            caps=ModelCapabilities(
                supported=frozenset(_capabilities(row[3])),
                context_window=int(row[4] or 0),
                # Zero, and it is not a placeholder: nothing is GENERATED on this surface, so a
                # non-zero value here would be a claim about a capability the endpoint does not
                # have. `check_window` reads `context_window` and nothing reads this.
                max_output_tokens=0,
            ),
        )
        for row in rows
    ]
    # BOTH COLUMNS OR NEITHER. The row always exists — it is the organization — so the presence
    # of `designated_row` says nothing; what says "no designation yet" is the pair being NULL,
    # which the CHECK constraint guarantees is all-or-nothing. Testing the row instead would
    # build `EmbeddingDesignation(connection_id="None", model="None")`, a designation naming a
    # connection that does not exist, which `resolve_embedding_connection` refuses as an
    # unusable designation rather than falling through to the ordinary one-candidate answer.
    designation = (
        EmbeddingDesignation(connection_id=str(designated_row[0]), model=str(designated_row[1]))
        if designated_row is not None
        and designated_row[0] is not None
        and designated_row[1] is not None
        else None
    )
    return connections, designation


def _capabilities(flags: Any) -> set[Capability]:
    """Capability flags from the row, dropping anything the enum does not know.

    Dropped rather than raising: a control plane that has learned a new flag before this
    service has is a normal deployment ordering, and refusing every model on that account would
    take an organization's ingestion down for a rolling release.
    """
    # `{"supported": [...]}` — an OBJECT, and the list is under one key. `ProviderModelEntry`
    # reads `capability_flags['supported']` on the other side and the column defaults to `{}`, so
    # a bare-list reading finds nothing on every row: every model loses every capability, no
    # connection can embed, and the organization is told its configuration cannot embed when the
    # truth is that we read the wrong shape. The list form is still accepted because it costs a
    # branch and the alternative is this function being right about only one of two spellings.
    if isinstance(flags, dict):
        supported = flags.get("supported")
        names = supported if isinstance(supported, list) else []
    else:
        names = flags if isinstance(flags, list) else []
    known = set()
    for name in names:
        try:
            known.add(Capability(str(name)))
        except ValueError:
            continue
    return known


def bind_embedder(
    *,
    connections: list[EmbeddingConnection],
    designation: EmbeddingDesignation | None,
    sealed_credential: bytes,
    sealed_data_key: bytes,
    kek_path: Any,
    context_window: int,
    adapters: dict[str, Any],
) -> Any:
    """Resolve the connection, open the credential, and return the narrowed callable.

    Raises rather than returning a callable that fails later. Everything that can be decided
    before a tenant's text moves is decided here: which connection, whether its vendor can
    embed at all, and whether the key opens.
    """
    connection = resolve_embedding_connection(connections, designated=designation)

    adapter = adapters.get(connection.provider)
    if adapter is None or not can_embed(connection.provider, connection.caps):
        raise KbError(
            ErrorClass.VALIDATION,
            f"{connection.provider!r} has no embedding surface in this build. Selection chose "
            "it because the organization's rows say it embeds, so the disagreement is between "
            "the capability matrix and the connection row — never resolved by falling back to "
            "another vendor, which would be a different vector space",
            retryable=False,
        )

    credential = open_credential(
        credential_ciphertext=sealed_credential,
        data_key_ciphertext=sealed_data_key,
        kek=read_kek(kek_path),
    )

    # THE ROW'S FLAGS WITH THE RUN'S WINDOW. The flags are the operator's configuration and
    # are taken as they are; `context_window` is passed in because `check_window` has already
    # compared it against `MAX_TOKENS + TOKEN_HEADROOM` for this run, and re-reading it off the
    # row here would let the two disagree.
    caps = ModelCapabilities(
        supported=connection.caps.supported,
        context_window=context_window,
        max_output_tokens=0,
    )

    def embed(
        texts: list[str],
        *,
        input_type: EmbeddingInputType,
        org_id: str,
        trace_id: str,
    ) -> EmbeddingResult:
        """One embedding call. The credential is in the closure and in no argument.

        Synchronous by signature — `EmbedCallable` is not a coroutine, deliberately, because
        ingestion runs inside a Celery task and a package that reached for `asyncio.run` here
        would be starting a second loop inside the worker's own. The adapter's `embed` IS a
        coroutine, so it is driven on the worker's loop rather than on a new one.
        """
        from app.worker.process import run_in_worker

        result: EmbeddingResult = run_in_worker(
            adapter.embed(
                EmbeddingRequest(
                    org_id=org_id,
                    trace_id=trace_id,
                    provider_connection_id=connection.connection_id,
                    model=connection.model,
                    texts=texts,
                    input_type=input_type,
                ),
                caps,
                credential,
            )
        )
        return result

    return embed
