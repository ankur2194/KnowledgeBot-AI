# KnowledgeBot AI — Makefile
#
# ================================================================================================
# THIS IS DOCUMENTATION THAT EXECUTES. IT MUST NEVER BECOME A BUILD SYSTEM.
# ================================================================================================
# There are already three build systems in this repository — composer, uv, and pnpm — and each one
# owns its runtime's dependency graph, its lockfile, and its caching. A fourth that wraps them
# accumulates flags, drifts from what CI runs, and becomes the thing people debug instead of the
# thing it wraps. So: EVERY TARGET IS ONE COMMAND. No loops, no conditionals, no shell functions,
# no variables that change behaviour. If a target needs logic, it is a script in scripts/ and the
# target invokes it.
#
# ------------------------------------------------------------------------------------------------
# WHY IT EXISTS AT ALL, when `docker compose ...` is right there
# ------------------------------------------------------------------------------------------------
# One reason, and it is `deploy`.
#
# `compose.override.yaml` is auto-loaded whenever NO -f flag is passed. So a bare
# `docker compose up -d` in infrastructure/docker brings production up with bind-mounted source, a
# published PostgreSQL port, Let's Encrypt STAGING certificates, APP_DEBUG=true and `fastapi dev`.
# None of that errors. `docker compose ps` is green.
#
# A deploy script can drift to a bare `up -d` — someone edits it during an incident, or copies a
# line from the README. `make deploy` hardcodes `-f compose.yaml -f compose.prod.yaml` in one
# place, in version control, where a diff shows it. That is the whole justification.
#
# Keep this under 15 targets. It is a table of contents, not an interface.

COMPOSE_DIR := infrastructure/docker
COMPOSE     := docker compose --project-directory $(COMPOSE_DIR) -f $(COMPOSE_DIR)/compose.yaml
# The production pair, written ONCE. Every production target uses this variable so the two files
# cannot come apart.
PROD        := $(COMPOSE) -f $(COMPOSE_DIR)/compose.prod.yaml

.PHONY: bootstrap up down watch logs ps prod-config preflight deploy backup obs-up test-up promtool lint-compose
.DEFAULT_GOAL := help

## bootstrap: generate secrets, start dev, and VERIFY the S3 gateway rejects anonymous access
bootstrap:
	@bash scripts/dev/bootstrap.sh

## up: development stack (compose.override.yaml is auto-loaded — correct here and only here)
up:
	@cd $(COMPOSE_DIR) && docker compose up -d

## down: stop the ENTIRE development stack — every profile — keeping volumes
# ADR-044 / docs/22 § I1.
# `--profile '*'` is load-bearing and `--remove-orphans` is NOT the alternative. MEASURED on Compose
# v5.3.1 (2026-08-14) with a two-service scratch project, one service behind `profiles: [test]`:
#
#   docker compose down                    -> removed the unprofiled service; the profiled container
#                                             was left RUNNING, and the network then failed to remove
#                                             with "Resource is still in use"
#   docker compose down --remove-orphans   -> left it running too. A profiled service is NOT an
#                                             orphan: Compose can see it in the file, it is merely
#                                             not in the active profile set, so this flag never
#                                             looks at it. This is why `deploy` carrying the flag
#                                             says nothing about the case.
#   docker compose --profile '*' down      -> removed the container AND the network
#
# That is the whole reason `postgres-test` and `valkey-test` outlive a `make down` and sit in
# `docker ps -a` for days. The wildcard needs Compose >= 2.24; `COMPOSE_PROFILES='*'` is the
# equivalent for an older client. There is deliberately no `stop` target to go with this — the
# 15-target cap at the head of this file is a real constraint, and with dev's `restart: "no"`
# (compose.override.yaml, SHUTDOWN DETERMINISM) a plain `docker compose stop` now stays stopped.
down:
	@cd $(COMPOSE_DIR) && docker compose --profile '*' down

## watch: development with file syncing (needs a build: section and stat/mkdir/rmdir in the image)
watch:
	@cd $(COMPOSE_DIR) && docker compose watch

## logs: follow everything
logs:
	@cd $(COMPOSE_DIR) && docker compose logs -f --tail=200

## ps: what is running, and whether it is healthy
ps:
	@cd $(COMPOSE_DIR) && docker compose ps

## prod-config: render the PRODUCTION config. Read it before every deploy.
# `docker compose config` renders interpolated values IN FULL, so this output can contain anything
# reachable through ${...}. It must never be pasted into a ticket or echoed by CI. Secrets arrive
# as files at /run/secrets/ and are absent from it, which is exactly why they are files.
# What to look for: `ports:` only under traefik; no bind mount OUTSIDE the enumerated `:ro`
# third-party config set (a correct render carries 16 of them — Traefik, Valkey ×2, Postgres,
# Qdrant, SeaweedFS and the Collector's config, plus /var/run/docker.sock and
# /var/lib/docker/containers; the list is in infrastructure/docker/README.md, and anything under
# services/ or apps/ means the dev overlay loaded); and no Host(`api.`) — an empty ${DOMAIN}
# renders a router that is valid, healthy, and matches nothing. "No bind mounts" would be the
# wrong instruction: it fires on every correct config, and an instruction that always fires is
# one people learn to skip. The three greps in that README are the specific version.
prod-config:
	@$(PROD) config

## preflight: refuse-to-deploy checks — secrets, shipped dev credentials, hostnames, prod render
# Read-only, idempotent, safe to run any time, including against a live host.
preflight:
	@bash scripts/ops/preflight.sh

## deploy: THE production start. Explicit -f files, so the dev overlay cannot be auto-loaded.
# `preflight` is a PREREQUISITE, not the first line of this recipe. A line inside the recipe is one
# `git revert` or one "let me just skip that for now" away from being dropped, and the states it
# catches are states in which `up -d` SUCCEEDS: mount sources that Docker silently created as
# DIRECTORIES — which starts VALKEY wide open (`user default on nopass ~* &* +@all`, no log line)
# while it makes SEAWEEDFS crash-loop on a fatal config load, exit 255; and the CHANGE-ME /
# #0000…0000 template credentials that bootstrap.sh rewrites on a developer machine and that
# nothing rewrites here. The two services fail in OPPOSITE directions and this comment used to say
# the reverse — ADR-037, docs/19. Never make a seaweedfs crash-loop go away by writing `{}` into
# identities.json: THAT is the Allow-All state. As a prerequisite, a non-zero exit stops make
# before the deploy recipe runs at all.
deploy: preflight
	@$(PROD) up -d --remove-orphans

## backup: pgdata + the SeaweedFS volume, and nothing else — the scope is the invariant
backup:
	@bash scripts/ops/backup.sh

## obs-up: Prometheus, Alertmanager, Loki, Tempo, Grafana (the `observability` PROFILE)
# The otel-collector is NOT in this profile: it ships in every deployment (ADR-024).
obs-up:
	@cd $(COMPOSE_DIR) && docker compose --profile observability up -d

## test-up: ephemeral test databases + the local crawl fixture origin (the `test` PROFILE)
test-up:
	@cd $(COMPOSE_DIR) && docker compose --profile test up -d

## promtool: check and unit-test the Prometheus rules
# The working directory is load-bearing: `rule_files:` inside a unit-test file resolves relative to
# the CWD, not to the test file, so running this from the repo root silently finds nothing — and a
# passing run of ZERO tests looks identical to a passing run.
promtool:
	@cd infrastructure/observability/prometheus && promtool check rules rules/*.yml && promtool test rules tests/*.yml

## lint-compose: validate every -f combination parses and interpolates
# There is no GPU overlay any more — embeddings and reranking are provider API calls, so no
# service reserves a device and there is nothing for a `-f compose.gpu.yaml` to patch. The three
# remaining combinations are base, base+override (dev) and base+prod, and $(PROD)/$(COMPOSE)
# already cover them.
lint-compose:
	@$(COMPOSE) config --quiet && $(PROD) config --quiet && echo "compose OK"

help:
	@grep -hE '^## ' $(MAKEFILE_LIST) | sed 's/^## /  make /' | sort
