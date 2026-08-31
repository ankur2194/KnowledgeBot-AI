"""The only module in this service that issues SQL.

This service writes the PostgreSQL tables named in ``ALLOWED_TABLES`` below, and owns **no**
migration. They are derived, rebuildable artifacts whose schema lives in Laravel's
migrations: we write rows into a schema we do not define. **Read the tuple for the list.**
This docstring does not restate it, or its length, and neither should anything else
(ADR-036): a sentence carrying a count goes false without a diff ever touching it. The copies
in ``infrastructure/docker/env/ai-service.env.example`` and
``infrastructure/docker/postgres/initdb/00-extensions.sql`` were the worked example — they
still read "exactly four" long after the tuple had grown, and on 2026-08-20 they were retired
rather than corrected, because correcting a restated count only resets the clock on it.

*Any* name added here is a review stop, not a refactor, and there is no arithmetic to appeal
to. **The invariant is the three properties every name on the list has**, each a question
about that specific table and answerable on its own:

1. **The row is derived and rebuildable** in the ADR-010 sense: a pure function of content
   already held in PostgreSQL and object storage, reproducible exactly by a rebuild, with
   nothing on it that is a source of truth.
2. **The public API neither reads nor writes it.** This is the one that actually bites. A
   data-plane write into a table Laravel serves would land beside Laravel's own writer with
   no policy, no audit row, and no framework-applied tenant scope — and it fails nowhere.
   The row is simply there.
3. **Laravel owns the migration.** Schema is never defined here, in any form.

That reasoning is what admitted the two sparse-statistics tables (finding **C2**) rather
than an appeal to "one more is fine". ADR-030 removed the sparse retrieval arm along with
local model inference; C2 restored it with locally computed BM25, which CLAUDE.md permits
explicitly — a statistical ranking function is not a model. BM25 needs corpus statistics,
``app/retrieval/sparse.py`` splits the formula so that every corpus-dependent quantity sits
on the query side, and these two tables are where that side reads from. Every row in them is
a pure function of the version's chunk text under ``SPARSE_ANALYZER_VERSION`` — the same
property, for the same reason, as the ``chunks`` rows they are computed from. Nothing in the
public API touches either table, and
``services/core-api/database/migrations/2026_08_07_000600_create_sparse_corpus_statistics_tables.php``
defines them.

They are **two** names and not one, and the second is not a convenience. The per-version
document total is the numerator of the IDF formula and is meaningless outside the scope the
frequencies carry, so it is keyed identically —
``(organization_id, source_version_id, analyzer)`` — rather than stored as a column on
``source_versions``.

The argument this docstring once made from ``source_versions`` **not existing here** is no
longer available, and it was never the reason. It does exist: Phase C1 landed
``services/core-api/database/migrations/2026_08_20_002000_create_source_versions_table.php``
on 2026-08-20. The design is unchanged, because the grounds are the key and the ownership.

* **The analyzer.** ``source_versions`` has one row per version, so a column on it can hold
  one number per version — while this total is per version *and per analyzer*. A column would
  either collapse the two analyzers' totals into one or drag ``SPARSE_ANALYZER_VERSION`` into
  the lifecycle table's key. The collapse is the dangerous half, precisely because the count
  genuinely does not depend on the analyzer: the two rows carry equal values, so an
  analyzer-blind read returns twice a plausible-looking number, and no fixture that seeds both
  analyzers can distinguish a bound read from an unbound one by inspecting a single result.
  The primary key on ``sparse_version_statistics`` is that argument, made in the migration
  that creates it.
* **Whose table it is.** ``source_versions`` is `kb-source-lifecycle`'s and its schema is
  Laravel's, so hanging the IDF numerator on it would put a lifecycle migration inside a
  change about BM25 — property 3 above, from the other direction. That migration already
  refuses a column of this shape on its own account: its "what is not on this table" note
  declines ``chunk_count`` because a count over rows the data plane writes would be a second
  number that can disagree with the first while both look authoritative. The IDF numerator is
  that shape exactly.

Two further rules make the direct write safe:

* Chunk, element **and sparse-statistics** rows are written **only against the new,
  not-yet-active ``source_version_id``**, never against the version currently serving. That
  is why a direct write cannot corrupt live state: nothing reads those rows until Laravel
  flips the pointer. For the statistics that rule is load-bearing in an unobvious way —
  document frequencies that appeared before their version was published would change the IDF
  of terms in queries against the *previous* version, so the old version's ranking would
  shift under a query nobody re-ran. The read is scoped to the resolved active-version set,
  so an unpublished version contributes nothing, automatically.
* This service **never** assigns ``source_items.current_version_id``. It reports counts,
  checksum and readiness on the ingestion status callback, and Laravel activates. Two
  writers on the one column that decides which version is live turns a lifecycle bug into a
  constraint violation inside a Celery task, retried forever.

There is deliberately no ORM and no migration tool under ``app/``: schema is Laravel's, and a
second migration authority against a schema we do not own is what that rules out.

**Nothing in this repository enforces it.** It used to — a ``.github/workflows/gates.yml`` job
grepped this package case-insensitively for ``create table``/``alter table``/``drop table``
and for the names of SQLAlchemy, SQLModel, Tortoise and Alembic — but ``.github/`` was deleted
on 2026-08-17 and nothing replaced it, so the rule now holds by review alone: an ``import
alembic`` under ``app/`` would land here with nothing objecting. ``scripts/security/rules/``
does carry a semgrep rule matching those imports, and it is not a backstop — as of 2026-08-20
no Makefile target, script or workflow in this repository invokes semgrep (finding **F7**), so
that rule records the intent rather than holding it. Do not trust this paragraph either:
``ls .github`` and ``git grep -l semgrep`` are the two measurements, and a paragraph
describing a gate is exactly the kind that goes stale in place.

The gate's other legacy is a writing rule that is no longer real. Its grep had no
``--include`` filter, so a docstring or a comment naming one of those tools failed the build
exactly as an import would, and any explanation that needed to name one was pushed out to
``services/ai-service/README.md``. That constraint is gone — the paragraph above names four of
them. The README still describes the gate in the present tense, so read its "things CI will
fail you for" section as reasoning, not as a live enforcement claim.
"""

from __future__ import annotations

from collections.abc import Sequence
from typing import Any, Final

from app.core.errors import ErrorClass, KbError
from app.ingestion.identity import point_id

__all__ = [
    "ALLOWED_TABLES",
    "TableNotWritable",
    "assert_writable",
    "count_rows",
    "mark_chunks_indexed",
    "replace_chunks",
    "replace_document_elements",
    "replace_sparse_statistics",
]

#: The complete write allow-list, and the single place the list exists — do not restate it, or
#: its length, in a docstring, a comment, a config file or a test (ADR-036).
#:
#: **HALF OF THIS PARAGRAPH'S OLD WARNING IS NOW FALSE, AND KNOWING WHICH HALF IS THE POINT.**
#: It used to say "nothing checks that a statement's target is on it". That was true, and
#: ``docs/22`` § Q5 records the consequence: two indistinguishable failures, admitting a wrong
#: name and writing against a name that was never admitted, of which the deleted
#: ``gates.yml`` job only ever covered the first.
#:
#: ``assert_writable`` below now covers the **second**, at runtime, and every statement in this
#: module goes through it. What is still uncovered is the first: adding a name to this tuple
#: changes what the data plane is permitted to write, in a diff that will go green, and the only
#: thing between a wrong name and production is a reviewer working through the three properties
#: in the module docstring above. Treat a change to this tuple as the review stop it is.
ALLOWED_TABLES: Final[tuple[str, ...]] = (
    "chunks",
    "document_elements",
    # `retrieval_traces` WAS HERE AND CAME OFF ON 2026-08-27, before anything wrote it, and the
    # reason is ADR-033 property 2 rather than a change of mind about the row. D5's diagnostics
    # panel and D6's per-turn detail both *read* that table from an admin endpoint, which makes
    # the public API a reader of it — the property's own words, and the one it says actually
    # bites. A data-plane writer would then sit beside Laravel's reader with no policy, no audit
    # row and no framework-applied scope.
    #
    # The decisive comparison is `citations`, not the property in the abstract. Citations and
    # traces are the same thing — per-message diagnostics of one turn, written once when the
    # turn finalizes — and `citations` has always been Laravel's. Splitting the pair across the
    # planes was the anomaly; this removes it. `app/rag/runner.py` still *builds* the whole
    # trace (`RetrievalTrace` is every field §8.24 requires) and emits it as the `retrieval.trace`
    # frame; the relay's finalizer persists it in the same transaction as the message row, the
    # citation rows and the usage row, so the trace can never outlive or precede the message it
    # describes. Nothing was lost by the move: the frame was already on the wire, already
    # forwarded to admin actors by `ClientEvents::allows`, and already parsed.
    #
    # Consequence, recorded because it is invisible from here: `erase_data_subject`'s in-place
    # overwrite of `retrieval_traces.selected_evidence` is now a core-api-seam sweep like
    # `citations.excerpt`, not a local statement. `app/deletion/tasks.py` says so at its own
    # docstring; if that sentence and this one ever disagree, this tuple is the authority.
    "evaluation_results",
    # ── finding C2: the BM25 corpus statistics behind the restored sparse arm ──────────
    # Written in the SAME transaction as the version's `chunks` rows, against the new,
    # not-yet-active `source_version_id`, and upserted on their natural primary keys so a
    # redelivered Celery task overwrites rather than accumulates.
    #
    # `sparse_version_statistics` is the per-version document total: the IDF numerator. It is
    # keyed `(organization_id, source_version_id, analyzer)` — identical to the frequencies —
    # because the number is meaningless outside the scope they are summed over.
    #
    # THE SECOND JUSTIFICATION THIS COMMENT USED TO GIVE IS RETIRED, and the retirement is the
    # instructive half (`docs/22` § Q16). It said the total could not live as a column on
    # `source_versions` "because that table does not exist here" — true when written, false
    # since C1 created it on 2026-08-20. The key is unchanged and still correct for the reason
    # above; what changed is that the *other* reason was a statement about an absence, with
    # nothing watching for the absence ending. Note where it was found: the module docstring a
    # hundred lines up already corrected this exact premise, so the file had been swept on this
    # subject and the sweep missed one instance — which is why a partially corrected file reads
    # as a fully corrected one. The live reason to keep the total off `source_versions` is now
    # ADR-033 property 3 inverted: Laravel owns that table's migration AND writes its rows, so a
    # column the data plane must UPDATE per analyzer would put a second writer on a control-plane
    # row — the § Q6 shape, on the table § Q6 is about.
    "sparse_version_statistics",
    # `sparse_term_frequencies` is the per-term document frequency under one analyzer. The
    # analyzer is part of the key: term ids are `blake2b(term)` under a fixed personalization,
    # so two analyzers are two id spaces over the same text and summing across them yields a
    # number that is not a document frequency of anything, with nothing raised.
    "sparse_term_frequencies",
)


class TableNotWritable(KbError):
    """A statement named a table the data plane is not permitted to write.

    Its own class rather than a bare ``KbError`` so it cannot be caught by a handler reaching
    for something else, and so a test can assert on the mechanism rather than on a message.
    """


def assert_writable(table: str) -> str:
    """Gate every statement in this module on ``ALLOWED_TABLES``. Returns the table name.

    **THIS IS THE MECHANISM ``docs/22`` § Q5 SAYS DID NOT EXIST**, and it closes exactly one of
    the two failures recorded there. Q5's wording is the thing to keep: the allow-list was "a
    list a reviewer reads, not a check a statement passes", so writing against a name that was
    never admitted was indistinguishable from a green diff. It is a check a statement passes
    now. The *other* Q5 failure — admitting a wrong name to the tuple — is unchanged and
    unmechanised, because no runtime check can tell a correctly-admitted name from a
    wrongly-admitted one.

    IT RETURNS THE NAME RATHER THAN RETURNING NONE, and that is what makes it hard to bypass by
    accident: the interpolation site reads ``f"INSERT INTO {assert_writable('chunks')}"``, so
    the gate is *in the expression that builds the statement* rather than beside it. A guard on
    its own line is a guard someone deletes while moving code, and the statement still runs.

    Interpolating a table name at all is deliberate and is the one interpolation this module
    permits: PostgreSQL takes no parameter in a table position, so the alternative is a literal
    per statement — which is what the old code would have been and what nothing could check.
    Every *value* is bound, always, including values we produced ourselves.
    """
    if table not in ALLOWED_TABLES:
        raise TableNotWritable(
            ErrorClass.INTERNAL_DEPENDENCY,
            f"{table!r} is not on the data plane's write allow-list. The list is the three "
            "properties in app/db/writes.py: the row is derived and rebuildable, no public API "
            "path reads or writes it, and Laravel owns the migration. A table failing any of "
            "them is written by the control plane, never here",
        )
    return table


async def count_rows(
    conn: Any,
    *,
    table: str,
    org_id: str,
    source_version_id: str,
    index_status: str | None = None,
) -> int:
    """Count one version's rows in an allow-listed table. **The resume signal.**

    A READ in a module named ``writes``, and it belongs here rather than in a new ``reads.py``
    because this module's first sentence is "the only module in this service that issues SQL" —
    a claim that is worth more kept true than kept tidy. It goes through ``assert_writable`` for
    the same reason: the allow-list is where the tenancy scoping rules are written down, and a
    read that skipped the gate would be the one statement in the file nobody reviewed against
    them.

    ``organization_id`` is a required keyword, not an optional filter. An unscoped count over a
    derived table returns a plausible number drawn from every tenant, and the caller — the
    resume check — would read it as "this version is already parsed" and skip the stage.
    """
    name = assert_writable(table)
    clause = "organization_id = %s AND source_version_id = %s"
    params: list[Any] = [org_id, source_version_id]
    if index_status is not None:
        clause += " AND index_status = %s"
        params.append(index_status)
    async with conn.cursor() as cur:
        await cur.execute(f"SELECT count(*) FROM {name} WHERE {clause}", params)  # noqa: S608
        row = await cur.fetchone()
    return int(row[0]) if row else 0


async def mark_chunks_indexed(conn: Any, *, org_id: str, source_version_id: str) -> int:
    """Flip this version's chunk rows from ``pending`` to ``indexed``. Returns the row count.

    Called **after** the upsert returns and never before, because the row is the resume
    evidence: a row marked indexed whose point was never written makes the resume check skip
    the embed stage forever, and the version then fails verification on every attempt with
    nothing left that would re-drive the write.
    """
    table = assert_writable("chunks")
    async with conn.cursor() as cur:
        await cur.execute(
            f"UPDATE {table} SET index_status = 'indexed', updated_at = now() "  # noqa: S608
            "WHERE organization_id = %s AND source_version_id = %s AND index_status <> 'indexed'",
            (org_id, source_version_id),
        )
        return int(cur.rowcount)


async def replace_document_elements(
    conn: Any, *, org_id: str, source_version_id: str, elements: Sequence[Any]
) -> int:
    """Write one version's parsed elements, replacing anything a previous attempt left.

    DELETE-THEN-INSERT INSIDE THE CALLER'S TRANSACTION, and the delete is what makes a
    redelivery safe. Celery guarantees at-least-once and nothing else, so this runs twice as a
    normal Tuesday; an append would give the chunker the same page twice and every downstream
    count would be right about a document that does not exist. Scoped to
    ``(organization_id, source_version_id)`` — never to the version alone, because a derived
    table is still tenant data and an unscoped delete is a cross-tenant write.

    Only ever against the **new, not-yet-active** version. Nothing reads these rows until
    Laravel flips ``source_items.current_version_id``, which is why a destructive statement here
    cannot disturb the version currently serving.
    """
    table = assert_writable("document_elements")
    async with conn.cursor() as cur:
        await cur.execute(
            f"DELETE FROM {table} WHERE organization_id = %s AND source_version_id = %s",  # noqa: S608
            (org_id, source_version_id),
        )
        if not elements:
            return 0
        await cur.executemany(
            f"INSERT INTO {table} ("  # noqa: S608
            "id, organization_id, source_version_id, parent_element_id, seq, kind, text, "
            "page, slide, sheet, table_ref, url, anchor, char_start, char_end"
            ") VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)",
            [
                (
                    element.id,
                    org_id,
                    source_version_id,
                    element.parent_element_id,
                    element.seq,
                    element.kind,
                    element.text,
                    element.page,
                    element.slide,
                    element.sheet,
                    element.table_ref,
                    element.url,
                    element.anchor,
                    element.char_start,
                    element.char_end,
                )
                for element in elements
            ],
        )
    return len(elements)


async def replace_chunks(
    conn: Any, *, org_id: str, source_version_id: str, chunks: Sequence[Any]
) -> int:
    """Write one version's chunk rows, replacing anything a previous attempt left.

    Same delete-then-insert discipline and the same tenant scoping as the elements above, for
    the same reason. Two columns are worth naming because getting either wrong is silent:

    ``vector_point_id`` is ``identity.point_id(...)`` — deterministic in
    ``(org_id, source_version_id, seq)`` — which is what makes a replayed upsert overwrite the
    same Qdrant point instead of adding a second one. It is written here so the relational row
    and the vector can always be matched **by identifier**, which non-negotiable 6 requires:
    deletion never matches on text.

    ``index_status`` starts at ``pending`` and is not set to anything else by this function.
    A chunk row exists before its vector does, and the gap between the two is precisely the
    window a crash lands in; marking it indexed here would make the row claim a point that may
    never have been written.
    """
    table = assert_writable("chunks")
    async with conn.cursor() as cur:
        await cur.execute(
            f"DELETE FROM {table} WHERE organization_id = %s AND source_version_id = %s",  # noqa: S608
            (org_id, source_version_id),
        )
        if not chunks:
            return 0
        await cur.executemany(
            f"INSERT INTO {table} ("  # noqa: S608
            "id, organization_id, source_id, source_item_id, source_version_id, seq, "
            "document_element_id, element_ids, parent_element_id, heading_path, page, "
            "page_end, slide, sheet, table_ref, row_start, row_end, url, anchor, char_start, "
            "char_end, lang, content_type, token_count, content_hash, overlap_of, text, "
            "vector_point_id, index_status, embedding_model_id, parser_version, "
            "chunker_version, effective_at, expires_at"
            ") VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, "
            "%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)",
            [_chunk_row(chunk, org_id=org_id) for chunk in chunks],
        )
    return len(chunks)


def _chunk_row(chunk: Any, *, org_id: str) -> tuple[Any, ...]:
    """One ``chunks`` row from one ``Chunk``, in the column order above.

    ``org_id`` is passed in and **not** read off the metadata, even though the metadata carries
    it. The caller's organization is the one the transaction is scoped to; taking it from the
    row being written would let a chunk whose metadata was built under another tenant insert
    itself under that tenant's id, which is the one direction tenant isolation must never be
    inferred from data (non-negotiable 1).
    """
    meta = chunk.metadata
    row_start, row_end = meta.row_range if meta.row_range is not None else (None, None)
    return (
        meta.chunk_id,
        org_id,
        meta.source_id,
        meta.source_item_id,
        meta.source_version_id,
        meta.seq,
        meta.document_element_id,
        list(meta.element_ids),
        meta.parent_element_id,
        list(meta.heading_path),
        meta.page,
        meta.page_end,
        meta.slide,
        meta.sheet,
        meta.table_ref,
        row_start,
        row_end,
        meta.url,
        meta.anchor,
        meta.char_start,
        meta.char_end,
        meta.lang,
        meta.content_type,
        meta.token_count,
        meta.content_hash,
        meta.overlap_of,
        chunk.text,
        point_id(
            org_id=org_id,
            source_version_id=meta.source_version_id,
            seq=meta.seq,
        ),
        "pending",
        meta.embedding_model_id,
        meta.parser_version,
        meta.chunker_version,
        meta.effective_at,
        meta.expires_at,
    )


async def replace_sparse_statistics(
    conn: Any,
    *,
    org_id: str,
    source_version_id: str,
    analyzer: str,
    document_total: int,
    frequencies: dict[int, int],
) -> None:
    """Write this version's BM25 corpus statistics under one analyzer.

    Two tables, written together, in the caller's transaction and in the same one as the
    version's ``chunks`` rows. They are the IDF numerator and denominator and they are
    meaningless apart: a document total present without its frequencies weights every term as
    if it appeared in no document, which is not an error anywhere and simply inverts the
    ranking.

    THE ANALYZER IS PART OF BOTH KEYS AND IS NEVER DEFAULTED. Term ids are ``blake2b(term)``
    under a fixed personalization, so two analyzers are two id spaces over the same text —
    summing across them produces a number that is a document frequency of nothing, with nothing
    raised. The two rows for two analyzers carry *equal* document totals, which is exactly why
    an analyzer-blind read of the total looks plausible and returns double.
    """
    totals = assert_writable("sparse_version_statistics")
    terms = assert_writable("sparse_term_frequencies")
    async with conn.cursor() as cur:
        await cur.execute(
            f"INSERT INTO {totals} "  # noqa: S608
            "(organization_id, source_version_id, analyzer, document_total) "
            "VALUES (%s, %s, %s, %s) "
            "ON CONFLICT (organization_id, source_version_id, analyzer) "
            "DO UPDATE SET document_total = EXCLUDED.document_total, updated_at = now()",
            (org_id, source_version_id, analyzer, document_total),
        )
        # DELETE FIRST, because an upsert alone cannot remove a term that this attempt no
        # longer produces. A re-parse that drops a page leaves its terms behind under a pure
        # upsert, and their document frequencies keep weighting queries against text that is
        # not in the version any more.
        await cur.execute(
            f"DELETE FROM {terms} WHERE organization_id = %s AND source_version_id = %s "  # noqa: S608
            "AND analyzer = %s",
            (org_id, source_version_id, analyzer),
        )
        if not frequencies:
            return
        await cur.executemany(
            f"INSERT INTO {terms} "  # noqa: S608
            "(organization_id, source_version_id, analyzer, term_id, document_frequency) "
            "VALUES (%s, %s, %s, %s, %s)",
            [
                (org_id, source_version_id, analyzer, term, frequency)
                for term, frequency in sorted(frequencies.items())
            ],
        )
