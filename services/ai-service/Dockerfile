# syntax=docker/dockerfile:1.9
#
# services/ai-service/Dockerfile — the FastAPI AI data plane.
# Beside the service it builds (ADR-023). uv + pyproject.toml + uv.lock (ADR-022).
#
# ================================================================================================
# FOUR TARGETS, THREE SHIPPED IMAGES, AND THREE CONTAINMENTS THAT BUILD-TIME ASSERTIONS ENFORCE
# ================================================================================================
#   base              build tooling + system libraries. Never shipped.
#   runtime           ai-api, ai-worker-ingestion, ai-worker-embedding, ai-beat
#   runtime-crawl     ai-worker-crawl        + Playwright/Chromium (~500 MB)
#   runtime-evaluation ai-worker-evaluation  + the `evaluation` extra (ragas)
#
# NO GPU TARGET, AND NO LOCAL INFERENCE. Embeddings and reranking are provider API calls now, so
# nothing in any of these images loads a model we chose, and there is no CUDA kernel left to run.
# `runtime-gpu` and compose.gpu.yaml were deleted with that decision, not deferred.
#
# TORCH IS STILL INSTALLED, AND THAT IS NOT AN OVERSIGHT.
# ------------------------------------------------------------------------------------------------
#     docling -> docling-slim[standard] -> docling-ibm-models -> torch, torchvision
# docling-ibm-models is the layout model and TableFormer — the PDF-to-structured-elements step,
# which is kept. So the containment that is real here is CUDA, not torch: the stock PyPI `torch`
# wheel IS the CUDA build (fifteen `nvidia-*` wheels plus `triton`, MEASURED at 2.7 GB + 691 MB),
# and pyproject.toml redirects torch/torchvision to download.pytorch.org/whl/cpu to keep it out.
# `kb-assert-no-local-inference` below proves the result rather than trusting the manifest.
#
# `runtime` deliberately does NOT contain ragas, and a test asserts that `import ragas` FAILS in
# the API image. That containment has to be real, not aspirational: ragas is an LLM-judged
# evaluation harness that makes real provider calls against its own quota. Importable from the chat
# path, it is one careless import away from billing a tenant's credential for a quality score
# nobody asked for — and from a faithfulness computation sitting inside a request whose whole SLO
# is first-token latency.
#
# Chromium is confined the same way and for a different reason: it is ~500 MB, and it belongs only
# to the one worker that fetches attacker-chosen URLs. Putting it in the base image would put a
# headless browser inside `ai-api`, which is on the `data` network.
ARG PYTHON_IMAGE=python:3.13-slim-bookworm
ARG UV_IMAGE=ghcr.io/astral-sh/uv:0.9.7

# A named stage rather than `COPY --from=${UV_IMAGE}`: a global ARG is in scope for a FROM line but
# not for a COPY --from, so the inline form resolves to an empty reference in some BuildKit
# versions and fails with a confusing "invalid from flag".
FROM ${UV_IMAGE} AS uvbin

# ================================================================================================
# STAGE: base
# ================================================================================================
FROM ${PYTHON_IMAGE} AS base

COPY --from=uvbin /uv /uvx /usr/local/bin/

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    # Copy rather than symlink out of the uv cache: a symlinked venv breaks the moment the cache
    # mount goes away, which is on every layer boundary.
    UV_LINK_MODE=copy \
    UV_COMPILE_BYTECODE=1 \
    UV_PYTHON_DOWNLOADS=never \
    UV_PROJECT_ENVIRONMENT=/app/.venv \
    PATH=/app/.venv/bin:$PATH

# Runtime system libraries only. Build tooling is installed, used, and left behind in this stage.
#   libpq5        psycopg
#   libgomp1      onnxruntime / torch thread pool — missing, torch imports and then segfaults
#   libmagic1     MIME sniffing on the upload path
#   poppler/tesseract data are NOT here: OCR engine selection is an extra, and the tesseract path
#   is opt-in via the `ocr-tesseract` extra so the default image does not carry language packs.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        build-essential \
        libpq-dev \
        libpq5 \
        libgomp1 \
        libmagic1 \
        ca-certificates \
        curl \
    ; \
    rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Fixed uid/gid matching `user: "10001:10001"` in compose.prod.yaml. A mismatch surfaces as
# permission errors on /app/.venv at boot, which reads like a broken image rather than a config
# difference.
RUN set -eux; \
    groupadd -g 10001 app; \
    useradd -u 10001 -g app -m -d /home/app -s /usr/sbin/nologin app

# ------------------------------------------------------------------------------------------------
# EVERY DIRECTORY A NAMED VOLUME MOUNTS OVER MUST EXIST IN THE IMAGE, CREATED BEFORE `USER app`.
# ------------------------------------------------------------------------------------------------
# This is the rule, and /var/lib/celerybeat is the instance that cost us an outage.
#
# Docker populates an empty named volume from the image's contents at the mount path on first
# attach, and it copies that directory's OWNER AND MODE along with the bytes. If the path does not
# exist in the image there is nothing to copy, so the daemon creates the mountpoint itself as
# `root:root 0755` — and every process in the container runs as a non-root uid. `ai-beat` therefore
# died on its first start of a fresh deployment with:
#     PermissionError: [Errno 13] Permission denied: '/var/lib/celerybeat/schedule'
# A hand-run `chown` on the live volume fixes it permanently for THAT volume, which is precisely
# why the bug is invisible afterwards: it only reappears on a fresh deploy or a volume recreate,
# on someone else's machine, at the worst time.
#
# MODE 1777, NOT 0755 app:app, AND THAT IS DELIBERATE. The uid differs between deployments:
# compose.prod.yaml runs `user: "10001:10001"` (the `app` user above), while compose.override.yaml
# runs `user: "${UID:-1000}:${GID:-1000}"` so dev bind mounts stay writable by the developer.
# MEASURED: `docker run --user 1000:1000` gives `uid=1000 gid=1000 groups=1000` — no supplementary
# gid 0, so the OpenShift `chgrp 0 && chmod g+rwX` trick does not apply under plain Docker. An
# `app:app 0755` directory would fix production and leave every dev checkout broken exactly as it
# is today. 1777 is /tmp's mode and carries /tmp's reasoning: one uid per container, a
# root-only volume directory on the host, and the sticky bit so a file cannot be unlinked by a
# non-owner. Ownership is still set to app so a production `ls` reads correctly.
#
# It lives in `base` rather than in the three shipped targets because `runtime`, `runtime-crawl`
# and `runtime-evaluation` all descend from here: one mkdir, not three that drift. Only `ai-beat`
# mounts the volume today, and the directory costs nothing in the images that do not.
RUN set -eux; \
    mkdir -p /var/lib/celerybeat; \
    chown app:app /var/lib/celerybeat; \
    chmod 1777 /var/lib/celerybeat

# ------------------------------------------------------------------------------------------------
# kb-worker-healthcheck — the Celery worker liveness probe, in the image rather than inline in
# compose, for the same reason kb-assert-no-local-inference is.
# ------------------------------------------------------------------------------------------------
# The four `ai-worker-*` services previously carried this probe as a 200-character `python -c`
# one-liner, duplicated verbatim four times in compose.yaml:
#     p=pathlib.Path('/tmp/kb-worker-heartbeat'); exit(0 if fresh else 1)
# NOTHING HAS EVER WRITTEN THAT FILE. There is no bootstep, no signal handler and no task in
# services/ai-service/ that touches it (`grep -rn kb-worker-heartbeat services/ai-service/` is
# empty), so the probe could not pass, and it did not: MEASURED before this change, all four
# workers sat `unhealthy` with FailingStreak 38-40 while `celery inspect ping -d celery@$HOSTNAME`
# answered `pong` in 1.1 s. The probe also exited 1 with EMPTY output, so `docker inspect` showed
# a red container and no reason.
#
# THE HEARTBEAT FILE STAYS THE DESIGN. `celery-workers` specifies it: a heartbeat touched by a
# `bootsteps.StartStopStep` on the WORKER blueprint via `worker.timer.call_repeatedly`, which runs
# in the main process and therefore stays true while every prefork child is busy, with zero
# dependency I/O. Until that bootstep exists this script falls back to a weaker but TRUE signal
# and says so on every probe, rather than staying red forever and training an operator to ignore
# `unhealthy` on the day a worker is genuinely wedged.
#
# WHY NOT `celery inspect ping`, MEASURED IN THIS STACK RATHER THAN ASSUMED:
#   * UNTARGETED, it is wrong, not merely slow. `celery -A app.worker inspect ping` broadcasts on
#     the pidbox fanout and exits 0 if ANY node replies — from inside ai-worker-ingestion it
#     printed "4 nodes online" in 6.13 s. A dead worker with three live siblings reads healthy,
#     which is the exact inversion this whole item exists to remove.
#   * TARGETED (`-d celery@$HOSTNAME`) is correct but costs 1.1-1.8 s of a full Python + Celery +
#     app.worker import per probe, per worker, every interval, plus a broker round trip. And a
#     nodename that stops matching (anyone adding `-n` to a command line) fails as
#     `Error: No nodes replied within time constraint` after 6.13 s — longer than the 5 s
#     healthcheck `timeout`, so the probe is killed and the diagnosis never reaches the log.
#   * It couples container health to the Valkey ACL. pidbox needs the pub/sub channel grants
#     `&/1.celery.pidbox &/1.celeryev/worker.*` in valkey/users.acl — a too-narrow ACL grant is
#     what crash-looped all five Celery containers, and making Docker's health status depend on
#     the same line means the next ACL mistake also blanks every health signal we would use to
#     diagnose it.
# This script imports nothing from `app.`, opens no socket and issues no broker command.
RUN set -eux; cat > /usr/local/bin/kb-worker-healthcheck <<'SH' && chmod +x /usr/local/bin/kb-worker-healthcheck
#!/bin/sh
set -eu
python - <<'PYEOF'
import os
import pathlib
import sys
import time
from urllib.parse import urlsplit

HEARTBEAT = pathlib.Path(os.environ.get("KB_WORKER_HEARTBEAT_PATH", "/tmp/kb-worker-heartbeat"))
# Must be >= 2x the bootstep's touch interval. The bootstep is specified to touch every 30 s, so
# 120 s tolerates three consecutive missed ticks. Change one of these two numbers and you must
# change the other; they are written down together here for that reason.
MAX_AGE = int(os.environ.get("KB_WORKER_HEARTBEAT_MAX_AGE", "120"))


def broker_port() -> int:
    try:
        return urlsplit(os.environ.get("KB_BROKER_URL", "")).port or 6379
    except ValueError:
        return 6379


def established_broker_sockets(port: int) -> int:
    """Count ESTABLISHED sockets to <port> in this container's netns.

    Reads kernel state only: no connect(), no DNS, no AUTH, no broker command. State 01 is
    ESTABLISHED; 08 is CLOSE_WAIT and must NOT count -- the container carried a dozen CLOSE_WAIT
    entries to 6379 left over from the broker outage while consuming nothing.
    """
    want = f"{port:04X}"
    found = 0
    for path in ("/proc/net/tcp", "/proc/net/tcp6"):
        try:
            rows = pathlib.Path(path).read_text().splitlines()[1:]
        except OSError:
            continue
        for row in rows:
            cols = row.split()
            if len(cols) < 4:
                continue
            if cols[2].rsplit(":", 1)[-1].upper() == want and cols[3] == "01":
                found += 1
    return found


if HEARTBEAT.exists():
    age = time.time() - HEARTBEAT.stat().st_mtime
    if age <= MAX_AGE:
        print(f"heartbeat ok: age={age:.0f}s <= {MAX_AGE}s")
        raise SystemExit(0)
    print(
        f"UNHEALTHY: heartbeat {HEARTBEAT} is STALE -- age={age:.0f}s > {MAX_AGE}s.\n"
        "       The worker main process is no longer running its timer: it is wedged, not busy.\n"
        "       A worker with every prefork child saturated still ticks; this does not."
    )
    raise SystemExit(1)

# ---- Fallback. The heartbeat bootstep has not been implemented yet. -----------------------------
port = broker_port()
sockets = established_broker_sockets(port)
if sockets:
    print(
        f"DEGRADED but alive: broker socket ESTABLISHED (x{sockets}) to port {port}.\n"
        f"       {HEARTBEAT} does not exist because the liveness bootstep is NOT IMPLEMENTED.\n"
        "       OWED in services/ai-service/app/worker/heartbeat.py: a bootsteps.StartStopStep on\n"
        "       the WORKER blueprint, requires={'celery.worker.components:Timer'}, registered via\n"
        "       celery_app.steps['worker'].add(...), touching the file from\n"
        "       worker.timer.call_repeatedly(30.0, ...) so it advances on an IDLE worker too.\n"
        "       Until then this probe proves only that the consumer's TCP connection is up -- it\n"
        "       cannot see a worker that holds the socket and has stopped consuming."
    )
    raise SystemExit(0)

print(
    f"UNHEALTHY: no heartbeat file AND no ESTABLISHED socket to broker port {port}.\n"
    "       The worker is not connected to its broker: check the Valkey ACL for this user, the\n"
    "       `application` network, and valkey-core's own health."
)
raise SystemExit(1)
PYEOF
SH

# ------------------------------------------------------------------------------------------------
# kb-assert-no-local-inference — the containment this service's architecture now depends on.
# ------------------------------------------------------------------------------------------------
# THIS IS NOT A TORCH-ABSENCE CHECK, AND IT CANNOT BE ONE. torch is docling's: the layout model and
# TableFormer are PyTorch models, and document parsing is kept. Asserting "no torch" would fail
# every correct build. The three things that ARE true, and that each regress silently, are:
#
#   1. NO CUDA — zero `nvidia-*`, no `triton`. The PyPI torch wheel is the CUDA build and drags in
#      ~3.4 GB nothing here can execute. pyproject.toml redirects torch/torchvision to the
#      pytorch-cpu index to prevent it, and that redirect is fragile in a specific way: uv does NOT
#      apply [tool.uv.sources] to purely transitive dependencies, so it holds only while torch and
#      torchvision stay DECLARED. Someone "finishing the ML removal" by deleting those two lines
#      re-adds the whole CUDA stack, and the manifest still reads as though they had removed it.
#      (Observed here, by counting the lock — not hypothetical.)
#   2. torch is a `+cpu` local-version build — the same fact from the other side.
#   3. NO LOCAL EMBEDDER OR RERANKER — FlagEmbedding and sentence_transformers must not be
#      importable. Embeddings and reranking are provider API calls billed to the org's own
#      credential; a local model reappearing is an ARCHITECTURE regression rather than a size one,
#      and it would silently work, which is exactly why nothing else would catch it.
#
# IT QUERIES INSTALLED DISTRIBUTIONS, NOT THE FILESYSTEM, AND THAT DISTINCTION IS NOT COSMETIC.
# An earlier version globbed for directories named `nvidia*` under /app/.venv/lib with
# `find -maxdepth 2`. The nvidia wheels install into ONE namespace directory at
# site-packages/nvidia — depth THREE — so the glob matched nothing and the check passed on every
# image, CPU and CUDA alike. A containment check that can only pass is worse than no check: it
# reads as evidence. importlib.metadata reports what was actually installed, under the same
# distribution names uv.lock uses, so the count here and the count in the lock are the same number
# by construction.
#
# PROVE IT CAN FAIL before trusting it — each of the three arms, from a built image:
#   docker run --rm --entrypoint sh <img> -c 'uv pip install --no-deps triton >/dev/null 2>&1; kb-assert-no-local-inference'
#   docker run --rm --entrypoint sh <img> -c 'mkdir -p /app/.venv/lib/python3.13/site-packages/FlagEmbedding && touch $_/__init__.py; kb-assert-no-local-inference'
RUN set -eux; cat > /usr/local/bin/kb-assert-no-local-inference <<'SH' && chmod +x /usr/local/bin/kb-assert-no-local-inference
#!/bin/sh
set -eu
python - <<'PYEOF'
import importlib.util
from importlib.metadata import distributions

failures = []

installed = {d.metadata["Name"] for d in distributions() if d.metadata["Name"]}
cuda = sorted(n for n in installed if n.startswith("nvidia-") or n == "triton")
if cuda:
    failures.append(
        f"{len(cuda)} CUDA package(s) installed (~3.4 GB): {cuda}\n"
        "       torch resolved from the default index instead of pytorch-cpu. Most likely cause:\n"
        "       torch/torchvision were removed from [project] dependencies in pyproject.toml,\n"
        "       which silently disables the [tool.uv.sources] redirect."
    )

try:
    import torch
except Exception as exc:                      # noqa: BLE001 — a build assertion, not app code
    failures.append(f"torch is not importable: {exc} (docling's layout model needs it)")
else:
    if not torch.__version__.endswith("+cpu"):
        failures.append(f"torch {torch.__version__} is not a +cpu build")
    else:
        print(f"torch {torch.__version__}; cuda packages installed: 0")

for mod in ("FlagEmbedding", "sentence_transformers"):
    if importlib.util.find_spec(mod) is not None:
        failures.append(
            f"{mod} is importable — a LOCAL embedding/reranking model is back in the image.\n"
            "       Embeddings and reranking are provider API calls; nothing here may load a model."
        )

if failures:
    for item in failures:
        print(f"FATAL: {item}")
    raise SystemExit(1)

print("no-local-inference containment ok")
PYEOF
SH

# ================================================================================================
# STAGE: runtime — ai-api, ai-worker-ingestion, ai-worker-embedding, ai-beat
# ================================================================================================
FROM base AS runtime

# Dependency layer first, from the two files that define the graph — so editing a router does not
# reinstall torch.
#
# EVERYTHING THAT DEPENDS ONLY ON THE DEPENDENCY SET GOES BETWEEN HERE AND `COPY . .` — the two
# containment assertions and the ~700 MB model download included. That is not a build-time
# optimisation: with the download below the source COPY, `docker compose build ai-api` after ANY
# edit under services/ai-service/ re-ran the fetch, and the fetch then had to succeed every time.
# It did not (finding #82), so the image was unbuildable rather than slow.
COPY --link pyproject.toml uv.lock ./

# --frozen: fail if uv.lock does not match pyproject.toml, instead of silently re-resolving. A
#   resolver that quietly picks a different version makes the SBOM a description of a build nobody
#   reviewed.
# --no-dev: pytest, ruff and the fixtures are not shipped.
# --no-install-project: the application source is not here yet; only its dependencies.
#
# NO EXTRAS. That is the containment: `evaluation` (ragas) and `ocr-tesseract` are both absent.
# The `cpu` / `cuda` extras this line used to carry are gone with the GPU target — torch's index
# is now pinned unconditionally in pyproject.toml instead, because there is only one answer.
RUN --mount=type=cache,target=/root/.cache/uv \
    uv sync --frozen --no-dev --no-install-project

# ------------------------------------------------------------------------------------------------
# BUILD-TIME ASSERTIONS of both containments.
# ------------------------------------------------------------------------------------------------
# The runtime test asserts `import ragas` raises in the API image; this catches the same regression
# at build time, when the diff that caused it is still on screen. A dependency that pulls ragas in
# transitively is exactly how a containment stops being real without anyone deciding to remove it.
#
# kb-assert-no-local-inference (defined once in `base`) covers the other two: no CUDA payload, and
# no local embedding or reranking model. It reads the INSTALLED distribution list rather than the
# manifest, because every way either regresses leaves pyproject.toml looking correct.
#
# THEY RUN ABOVE `COPY . .`, AND THAT IS DELIBERATE — every one of them is a statement about the
# DEPENDENCY SET, and not one of them reads a file from the application source. Below the COPY
# they were re-executed on every source edit for no new information; above it, they are cached
# with the layer whose contents they describe and re-run exactly when pyproject.toml or uv.lock
# changes, which is the only way any of them can regress.
#
# WHAT MAKES THAT SOUND, since the shipped image is assembled two layers later: `uv sync --frozen`
# is an EXACT sync against the same lock. The step below it adds the project's own distribution
# and removes anything not in the lock; it cannot add a third-party distribution, because the lock
# it syncs from is the one asserted here. Containment can only tighten between this line and the
# CMD. (`runtime-evaluation` does add ragas, on purpose, in its own stage.)
RUN set -eux; \
    if python -c "import importlib.util,sys; sys.exit(0 if importlib.util.find_spec('ragas') else 1)"; then \
        echo "FATAL: ragas is importable in the runtime image. The evaluation extra leaked into the"; \
        echo "       default dependency set, or something now depends on it transitively."; \
        exit 1; \
    fi; \
    kb-assert-no-local-inference; \
    python -c "import fastapi, celery, qdrant_client, boto3; print('runtime deps ok')"

# ================================================================================================
# MODEL WEIGHTS — FETCHED HERE, AT BUILD TIME, AND NOWHERE ELSE
# ================================================================================================
# There was no download step at all before this. The image set HF_HOME and HF_HUB_OFFLINE=1 and
# then contained no weights, so the first page a no-network worker tried to parse failed or hung —
# the offline flag turning a missing weight into a hard error is only a good design once something
# has actually put the weights there.
#
# /models IS THE PATH, AND THE OTHER TWO CANDIDATES LOSE. It is already the Compose volume
# mountpoint in all three compose files (`models:/models`), KB_MODEL_CACHE_DIR in both env
# templates, and Settings.model_cache_dir's default. `/opt/docling-artifacts` appeared in exactly
# one place — app/ingestion/parsing/converter.py's ARTIFACTS_PATH — so that constant must change
# to /models. That file is the ingestion owner's; this Dockerfile is where the path is decided,
# and the two disagreeing is the bug.
#
# THE MODEL NAMES ARE LISTED EXPLICITLY, AND THAT IS A LICENCE CONTROL, NOT A SIZE OPTIMISATION.
# `docling-tools models download` with no arguments fetches docling 2.118's _default_models =
# [LAYOUT, TABLEFORMER, CODE_FORMULA, PICTURE_CLASSIFIER, RAPIDOCR] — verified by reading
# docling.cli.models in this image. We enable neither enrichment (do_picture_classification
# defaults False), so a bare invocation would put two model sets in every shipped image that
# models.manifest.toml does not declare and that scripts/security/license_gate.py therefore never
# reviews. Two more weight licences, shipped to self-hosters, unexamined. Adding a fourth name
# here without adding a [[model]] row is the regression this comment exists to stop.
#
# --rapidocr-backend-lang pins the checkpoint set to the one the manifest records: docling resolves
# lang=["en"] to PP-OCRv6 for detection and recognition and PP-OCRv4 for the direction classifier,
# and v5/v6 are different models, so a change here is a re-ingest rather than an upgrade.
#
# HF_HUB_OFFLINE is deliberately NOT yet set when this runs — it is set in the ENV below, after.
# An offline flag during the one step whose job is to download is the trap this ordering avoids.
#
# KNOWN GAP, NOT CLOSED HERE: docling requests the LAYOUT repo at revision "main"
# (layout_model_specs.py DOCLING_LAYOUT_HERON), so what this step bakes is main's head at build
# time, not the sha in models.manifest.toml. The manifest's own notes say the pin is only real if
# converter.py constructs the layout options with the revision explicitly. Until it does, this
# fetch is reproducible only to the day it ran.
#
# IT SITS ABOVE `COPY . .`, AND THAT ORDERING IS THE WHOLE POINT OF THIS STEP BEING SEPARATE.
# `docling-tools` is a console script installed by the `docling` DISTRIBUTION, not by us: it reads
# docling's own model specs and writes to /models, and it opens nothing under /app. So it needs
# the dependency layer and nothing else. Sitting below the source COPY it was ~700 MB of download
# re-run on every edit to any file in services/ai-service/ — which is not a slow build, it is an
# UNBUILDABLE one, because the fetch also has to succeed every time. Verified: `du -sh /models`
# below prints the same total whether or not the source has been copied.
RUN set -eux; \
    HF_HOME=/models docling-tools models download \
        --output-dir /models \
        --rapidocr-backend-lang onnxruntime:en \
        layout tableformer rapidocr; \
    du -sh /models

# HF weights live on a mounted volume (:ro in production) and are never fetched at runtime.
# HF_HUB_OFFLINE=1 turns a missing weight into an immediate, named error instead of a silent
# network fetch of an UNPINNED revision — which is how a model changes underneath a deployment.
#
# The named `models` volume in compose.yaml mounts OVER this directory. That is safe and is how
# the weights reach a running container: Docker populates an EMPTY NAMED volume from the image's
# contents at that path on first attach (this is named-volume behaviour only — a bind mount would
# instead hide them completely, which is why compose.override.yaml must keep using the named
# volume in dev). A pre-existing volume from an older image is NOT refreshed, so bumping a
# revision means `scripts/dev/reset.sh --models`, not just a rebuild.
ENV HF_HOME=/models \
    HF_HUB_OFFLINE=1

# ------------------------------------------------------------------------------------------------
# APPLICATION SOURCE — LAST, AND EVERYTHING ABOVE THIS LINE IS CACHED ACROSS A SOURCE EDIT.
# ------------------------------------------------------------------------------------------------
# These two layers are the ONLY ones a change under services/ai-service/ may invalidate. If you add
# a step here, ask which of the two inputs it reads — the dependency set or the application source —
# and put it on that side of this COPY. Anything that reads only the dependency set belongs above.
#
# The second `uv sync` differs from the first by dropping --no-install-project: it builds and
# installs the project's own wheel. It is still --frozen against the same lock, so it is an exact
# sync and cannot introduce a distribution the assertions above did not see.
COPY --link --chown=app:app . .

RUN --mount=type=cache,target=/root/.cache/uv \
    uv sync --frozen --no-dev

USER app
EXPOSE 8000

# One process per container, and the REASON HAS CHANGED — do not re-derive it from the old one.
# This used to be a memory argument: each forked worker loaded its own embedder (~2.3 GB) and
# reranker (~2.3 GB), so `--workers 4` meant ~18 GB. Those models are gone; embeddings and
# reranking are provider API calls, and the remaining resident cost is docling's layout model on
# the ingestion path, which ai-api does not load at all.
#
# It stays at one process anyway, for a different and weaker reason: this service is I/O-bound on
# provider calls, `fastapi run` gives each worker its own lifespan (so N workers open N Qdrant and
# N psycopg pools against limits sized for one), and replicas are the unit we already scale and
# schedule on. Raising it is now a legitimate question rather than an obvious mistake — but it must
# be answered together with the pool sizes, not alone.
#
# WEB_CONCURRENCY silently sets --workers even with no flag here, so it is pinned to "1" on the
# service in compose.yaml as well.
CMD ["fastapi", "run", "--host", "0.0.0.0", "--port", "8000", "app/main.py"]

# ================================================================================================
# STAGE: runtime-crawl — ai-worker-crawl ONLY
# ================================================================================================
# ~500 MB of Chromium and its shared libraries, confined to the one worker that needs it. This
# worker is the SSRF pivot: it fetches attacker-chosen URLs from inside the private network, which
# is why it is kept off the `data` network entirely and why a headless browser must not exist in
# any image that IS on `data`.
FROM runtime AS runtime-crawl

USER root

# crawl4ai-setup installs the Playwright browser and its OS dependencies. `--with-deps` pulls a
# long list of X/font/audio libraries; they are Chromium's, not ours, and are the reason this is a
# separate target rather than a conditional install in `runtime`.
# NOTE there is no `--extra crawl`: crawl4ai itself is a DEFAULT dependency (the guarded fetch and
# the HTML-to-Markdown extraction are ordinary imports). What this stage adds is the ~500 MB of
# browser binaries `crawl4ai-setup` downloads, which is the part worth confining.
RUN --mount=type=cache,target=/root/.cache/uv \
    set -eux; \
    crawl4ai-setup; \
    # Playwright caches under $HOME. Installed as root, it lands in /root and is unreadable by
    # uid 10001 — the failure is "browser executable not found" at first fetch, hours after the
    # build succeeded.
    mkdir -p /home/app/.cache; \
    if [ -d /root/.cache/ms-playwright ]; then \
        mv /root/.cache/ms-playwright /home/app/.cache/ms-playwright; \
    fi; \
    chown -R app:app /home/app/.cache; \
    rm -rf /var/lib/apt/lists/*

ENV PLAYWRIGHT_BROWSERS_PATH=/home/app/.cache/ms-playwright

USER app

CMD ["celery", "-A", "app.worker", "worker", "-Q", "crawl", "-c", "8", "--pool=prefork"]

# ================================================================================================
# STAGE: runtime-evaluation — ai-worker-evaluation ONLY (evaluate + maintenance queues)
# ================================================================================================
FROM runtime AS runtime-evaluation

USER root

# THIS is the only image in which `import ragas` may succeed.
#
RUN --mount=type=cache,target=/root/.cache/uv \
    uv sync --frozen --no-dev --extra evaluation

# Assert the extra actually landed. `uv sync --extra <name>` on a typo'd extra name is a hard error
# in uv, but a rename in pyproject.toml with a stale Dockerfile is not — and the failure mode
# without this check is an evaluation worker that starts, consumes jobs, and fails every one of
# them at import time with a message nobody sees until a scheduled run.
RUN python -c "import ragas; print('ragas', ragas.__version__)"

USER app

CMD ["celery", "-A", "app.worker", "worker", "-Q", "evaluate,maintenance", "-c", "2", "--pool=prefork"]
