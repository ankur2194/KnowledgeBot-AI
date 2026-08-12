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

## Things CI will fail you for, that are not obvious

**Never name an ORM or a migration tool anywhere under `app/`.** The table allow-list gate
greps that package case-insensitively for `create table`, `alter table`, `drop table`, and
for the names of SQLAlchemy, SQLModel, Tortoise and Alembic. It has no `--include` filter,
so a docstring, a comment, or a README nested under `app/` fails the build exactly like real
code would. Keep prose of that kind in this file, which sits outside `app/`.

**The words `copy`, `update` and `delete from` in prose are write-gate matches.** The table
allow-list gate greps `app/` for `(insert into|update|delete from|copy)\s+<word>`, so an
ordinary English sentence — "a second copy here", "loads its own copy of every model",
"update the pointer" — fails the job exactly like a stray `INSERT`. Two lines in this
skeleton had to be reworded for this reason. Prefer "duplicate", "refresh" or "remove".

**`.count(` is a tenancy-gate match.** The Qdrant-filter gate greps for `count(`, which also
matches `str.count(`, `list.count(` and `Counter(...).count(`. Any of them outside
`app/retrieval/search.py` fails the job unless the line carries `# tenancy-exempt: <reason>`.

**Never add `app/providers/openai.py`.** It shadows the `openai` SDK on import, from inside
the package that needs it. The adapter is `openai_adapter.py`.

**A new table name in `app/db/writes.py` is a review stop.** `ALLOWED_TABLES` is the list —
do not look for it, or for how long it is, in prose. A name may join it only by demonstrating
all three properties its docstring sets out: the row is **derived and rebuildable** in the
ADR-010 sense, **no public API path reads or writes it**, and **Laravel owns its migration**.
Miss the second and the write lands beside Laravel's own writer with no policy check, no audit
row and no framework-applied tenant scope — and it fails nowhere. CI pins the reviewed set by
name (`KB_TABLE_REVIEW_PIN` in `gates.yml`), deliberately not by count: a count is bumped in
the same commit that breaks the rule, and it cannot see a swap or a rename at all.

**`import ragas` must fail everywhere except `ai-worker-evaluation`.** `ragas` is a wheel
extra, installed only by the `runtime-evaluation` image stage, and a test asserts the import
raises in the API image. Import it inside the task body, never at module scope.
