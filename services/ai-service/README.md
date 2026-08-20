# services/ai-service

The AI data plane. FastAPI serves the internal API; Celery workers do everything that takes
longer than a request. Laravel is the only caller — there is no Traefik router and no
published port for this service, in any environment.

**Read before changing anything here:** `.claude/skills/fastapi-service/SKILL.md` for the
application shape, `.claude/skills/celery-workers/SKILL.md` for tasks and queues, and
`.claude/skills/kb-architecture-map/SKILL.md` for what this service is and is not allowed to
own.

## Status

**Partly written.** The application boots and `/health/live` answers. Roughly a third of `app/`
is real code with tests; the rest is a typed placeholder with a `NotImplementedError` body and a
`TODO` naming its owning agent.

**Do not trust a list of which is which — it rots. Grep:**

```bash
grep -rc NotImplementedError app/<subtree>       # per file
```

- **Zero, and therefore written:** `core/`, `observability/`, `evaluation/`, `worker/`,
  `maintenance/`.
- **Dense, and therefore the deliberate stubs:** `providers/` (the five wire adapters),
  `crawl/`, `deletion/`'s tasks, verification and filters.
- **Mixed at file granularity — grep the file, not the directory:** `ingestion/`, `retrieval/`,
  `rag/`, `api/`. `retrieval/sparse.py` is the shape to expect: the BM25 arithmetic is complete
  and `tokenize` is not, so the sparse arm scores correctly over nothing.

Two consequences of that split are worth knowing before you read a traceback. `/health/ready`
returning 503 is **correct**, not a bug. And a module with zero `NotImplementedError` may still be
unreachable from a request path whose router is a stub — a written module and a wired one are
different claims.

## Layout

```
app/
  main.py            create_app(), lifespan, the three exception handlers
  api/
    deps.py          verify_hmac (router-level), request_context, deadline, app_state
    health.py        /health/live, /health/ready, /health/deps
    internal/v1/     one router per contract group — chat, providers, retrieval,
                     ingestion, crawl, deletion, evaluation
  core/
    config.py        pydantic-settings; KB_ prefix; extra="forbid"
    errors.py        the 18-class taxonomy, as data
    signing.py       the KB1 canonical string
    idempotency.py   idem:{org_id}:{operation}:{key}
  contracts/         Pydantic request/response models
  db/
    pool.py          psycopg async pool, built in lifespan
    writes.py        the ONLY module issuing SQL, and the table write allow-list
  ingestion/         parsing, ocr, chunking, embedding, indexing + Celery tasks
  retrieval/         Qdrant access and the four mandatory tenant filters
  rag/               the 20-stage grounded-answer runner we own instead of a framework
  crawl/             the guarded fetch
  deletion/          purge, verification, and the deletion key allow-list
  evaluation/        eval orchestration — the only place ragas may be imported
  providers/         openai_adapter.py, anthropic.py, deepseek.py, nim.py, openrouter.py
  maintenance/       sweeps, reapers, and the ADR-010 index rebuild
  observability/     OTel wiring and the metric instruments
  worker/            the Celery app, its settings, and beat's (empty) schedule
```

## Local commands

**Prerequisites:** `uv` on the host, and nothing else. `requires-python = "==3.13.*"` plus
`.python-version` mean `uv` provisions the interpreter itself, so the system Python's version does
not matter. **Without `uv`:** `make up`, then run the same commands inside the container —
`cd infrastructure/docker && docker compose exec ai-api <command>` (`make up` is the only place a
bare `docker compose` is correct; see the Makefile header for why).

```bash
uv sync                       # runtime + dev
uv sync --extra evaluation    # only what ai-worker-evaluation needs
uv run ruff check . && uv run ruff format --check .
uv run mypy
uv run pytest
uv run python -c "from app.main import app"        # the app builds
uv run celery -A app.worker inspect registered     # the worker resolves
```

## Sharp edges, and which of them anything actually enforces

This section used to be called "Things CI will fail you for". **There is no CI in this
repository** — `.github/` held the gates and was deleted on 2026-08-17, and nothing replaced
it. Some of what follows was a *design* rule that a gate happened to check; some was only ever
an accommodation of a grep. They have different half-lives, so each item below says what the
rule is and, separately, what enforces it: **nothing**, **a test**, or **Python itself**. Where
the answer is "nothing", that is the useful part of the sentence — an unenforced rule you
believe is enforced is worse than one you know is not.

**Never add an ORM or a migration tool under `app/`.** *Enforced by: nothing.* The rule is real
and is about ownership — Laravel owns every migration, and a second migration authority against
a schema we do not own is the failure it prevents. The check was a gate that grepped `app/`
case-insensitively for `create table` / `alter table` / `drop table` and for the names of
SQLAlchemy, SQLModel, Tortoise and Alembic. It is gone, so an `import alembic` under `app/`
would land with nothing objecting. `scripts/security/rules/kb-python-tenancy.yaml` carries a
semgrep rule matching those imports, but as of 2026-08-20 no Makefile target, script or workflow
invokes semgrep (finding **F7**), so it records the intent rather than holding it.

> **Retired, not merely unenforced:** the ban on *naming* one of those tools in prose. That
> gate had no `--include` filter, so a docstring or a comment under `app/` failed the build
> exactly like an import would, and every explanation that needed to name a tool was exiled to
> this file. That constraint no longer exists. `app/db/writes.py` now names four of them in its
> own docstring, deliberately, because the clearest way to say what is forbidden is to say it.

**The words `copy`, `update` and `delete from` in prose were write-gate matches.** *Enforced by:
nothing — and there is nothing left to enforce.* The gate grepped `app/` for
`(insert into|update|delete from|copy)\s+<word>`, so an ordinary English sentence — "a second
copy here", "loads its own copy of every model", "update the pointer" — failed the job exactly
like a stray `INSERT`. This was pure grep appeasement with no design behind it, and it is
**withdrawn in full**: prefer whichever word is clearest, including `copy` and `update`. Kept
only as history, because it explains a real artifact — a few lines in `app/` are worded
"duplicate"/"refresh"/"remove" where a plainer word would have read better, and that is why. If
you are reworking such a line, you may simply say what you mean.

**`# tenancy-exempt: <reason>` is a convention, no longer a gate token.** *Enforced by:
nothing.* The Qdrant-filter gate grepped `app/` for `count(` — which also matches
`str.count(`, `list.count(` and `Counter(...).count(` — and failed any hit outside
`app/retrieval/search.py` unless the line carried that marker. **Keep writing the marker.** It
survives on its own merit as style: an unfiltered `count(` on a Qdrant client is a tenancy bug
that reads as ordinary code, and the annotation is how the author states the org and version
scope they reasoned about, for the reviewer who is now the only check. It is live in the tree
(`app/ingestion/indexing/upserter.py:369`) and it means what it always meant. What changed is
that omitting it now costs you a review comment instead of a red build — and that a
false-positive match on `str.count(` no longer needs an annotation at all, so do not add one to
a line that never touched a vector store.

**Never add `app/providers/openai.py`.** *Enforced by: Python itself.* It shadows the `openai`
SDK on import, from inside the package that needs it, so the failure is immediate and
unmissable rather than a gate's opinion. The adapter is `openai_adapter.py`.

**A new table name in `app/db/writes.py` is a review stop.** *Enforced by: nothing.*
`ALLOWED_TABLES` is the list — do not look for it, or for how long it is, in prose. A name may
join it only by demonstrating all three properties its docstring sets out: the row is **derived
and rebuildable** in the ADR-010 sense, **no public API path reads or writes it**, and **Laravel
owns its migration**. Miss the second and the write lands beside Laravel's own writer with no
policy check, no audit row and no framework-applied tenant scope — and it fails nowhere. A
`KB_TABLE_REVIEW_PIN` check used to pin the reviewed set by name, deliberately not by count (a
count is bumped in the same commit that breaks the rule, and cannot see a swap or a rename at
all); it was deleted with the rest of CI on 2026-08-17. The reasoning for pinning by name still
stands and nothing applies it, so the tuple's contents are now a pure review decision.

**`import ragas` must fail everywhere except `ai-worker-evaluation`.** *Enforced by: a
build-time assertion and two tests* — the one item here that something still checks. `ragas` is
a wheel extra installed only by the `runtime-evaluation` image stage; `Dockerfile:372` runs an
`importlib.util.find_spec('ragas')` probe in the runtime stage and fails the build if it
resolves, and `tests/unit/test_evaluation_extra_pins.py` and
`tests/unit/test_evaluation_judge_path.py` assert the containment and that nothing imports it at
module scope. Import it inside the task body, never at module scope.
