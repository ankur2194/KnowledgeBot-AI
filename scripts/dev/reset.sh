#!/usr/bin/env bash
# scripts/dev/reset.sh — destroy local state and start clean.
#
# ================================================================================================
# THIS IS DESTRUCTIVE AND IT WILL SAY SO BEFORE DOING ANYTHING.
# ================================================================================================
# It removes the named volumes: pgdata, seaweed, qdrant, valkey-core, beat-schedule, acme, and the
# telemetry volumes. Everything a local stack has ever ingested is gone.
#
# It does NOT touch:
#   * infrastructure/docker/secrets/  — regenerating the KEK makes every stored credential
#     ciphertext permanently undecryptable, which is a far more annoying reset than the one you
#     asked for. Use --secrets if that is genuinely what you want.
#   * the `models` volume, unless --models is passed — nothing in it is state, so keeping it is
#     free, but it is no longer expensive either. It used to be quoted at "~4.6 GB", which was
#     exactly the ~2.3 GB embedder plus the ~2.3 GB reranker that ADR-030 removed. What is left is
#     PARSING AND OCR ONLY, and it is small (sizes read from the hosts at the revisions pinned in
#     services/ai-service/models.manifest.toml, 2026-08-07):
#         docling-layout-heron  model.safetensors            172 MB
#         docling-models @v2.3.0  tableformer accurate+fast  358 MB
#         RapidOCR @v3.9.2  det + cls + rec ONNX              32 MB
#                                                          -------
#                                                           ~560 MB
#     Keep that number honest. An inflated one makes --models look like a decision, and a cache
#     nobody dares drop is how a stale weight survives a manifest change.
#   * anything outside this Compose project. It is scoped by project name, not by `docker system
#     prune`, which would take other projects' data with it.
#
# There is no production equivalent of this script and there will not be one.

set -euo pipefail

REPO_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$REPO_ROOT/infrastructure/docker"
PROJECT="knowledgebot"

DROP_MODELS=0
DROP_SECRETS=0
ASSUME_YES=0

for arg in "$@"; do
  case "$arg" in
    --models)  DROP_MODELS=1 ;;
    --secrets) DROP_SECRETS=1 ;;
    -y|--yes)  ASSUME_YES=1 ;;
    -h|--help)
      sed -n '2,25p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
      exit 0 ;;
    *) echo "unknown option: $arg" >&2; exit 64 ;;
  esac
done

cd "$DOCKER_DIR"

# ------------------------------------------------------------------------------------------------
# Refuse to run against anything that looks like a real deployment.
# ------------------------------------------------------------------------------------------------
# A dev script that can be pointed at production is a production incident waiting for a tired
# evening. Two independent checks, because either one alone is easy to satisfy by accident.
if [[ -f .env ]] && grep -qE '^DOMAIN=(?!.*example)' .env 2>/dev/null; then
  : # grep -P not portable; the real check is below
fi
if [[ -f .env ]]; then
  domain="$(grep -E '^DOMAIN=' .env | cut -d= -f2- || true)"
  case "$domain" in
    *example*|localhost*|*.localhost|*.test|*.local|"")
      ;;
    *)
      echo "REFUSING TO RUN: DOMAIN is '$domain', which does not look like a development domain." >&2
      echo "                 If this really is a throwaway environment, unset DOMAIN or use a" >&2
      echo "                 .example / .test / .localhost name." >&2
      exit 1 ;;
  esac
fi

echo
echo "This will PERMANENTLY DESTROY the local Compose project '$PROJECT':"
echo
docker compose ps --format '  running: {{.Service}}' 2>/dev/null || true
echo
echo "  volumes removed: pgdata seaweed qdrant valkey-core beat-schedule acme"
echo "                   prometheus alertmanager loki tempo grafana"
[[ $DROP_MODELS  -eq 1 ]] && echo "                   models  (--models: ~560 MB re-download, parsing/OCR only)"
[[ $DROP_SECRETS -eq 1 ]] && echo "  SECRETS REMOVED  (--secrets: a new KEK makes every stored"
[[ $DROP_SECRETS -eq 1 ]] && echo "                   credential ciphertext permanently unreadable)"
echo

if [[ $ASSUME_YES -ne 1 ]]; then
  read -r -p "Type 'destroy' to continue: " answer
  [[ "$answer" == "destroy" ]] || { echo "aborted"; exit 1; }
fi

# --volumes removes the volumes DECLARED IN THE COMPOSE FILES for this project only. It is not
# `docker volume prune`, which would take every other project's data on this machine.
# --remove-orphans catches containers left behind by a service that was renamed or removed.
echo "==> docker compose down --volumes --remove-orphans"
docker compose --profile observability --profile dev-tools --profile test \
  down --volumes --remove-orphans

if [[ $DROP_MODELS -eq 1 ]]; then
  docker volume rm -f "${PROJECT}_models" >/dev/null 2>&1 || true
  echo "==> removed the models volume"
else
  echo "==> KEPT the models volume (pass --models to drop it)"
fi

if [[ $DROP_SECRETS -eq 1 ]]; then
  rm -rf "$DOCKER_DIR/secrets"
  mkdir -p "$DOCKER_DIR/secrets"
  : > "$DOCKER_DIR/secrets/.gitkeep"
  # The two rendered credential files must go WITH the secrets they mirror, or the next bootstrap
  # run leaves a half-reset stack: identities.json would still hold the old S3 secret while
  # secrets/s3_secret_key holds a new one, and users.acl would still hold hashes whose plaintexts
  # no longer exist. Both files are gitignored and rendered from a committed *.example, so removing
  # them is safe and reversible — `git checkout --` cannot restore them any more, and bootstrap.sh
  # re-renders them from the template. Only reachable behind --secrets, and the stack is already
  # down by this point, so this is not the disk-versus-server split (nothing is holding the old
  # credential in memory).
  rm -f "$DOCKER_DIR/seaweedfs/identities.json" "$DOCKER_DIR/valkey/users.acl"
  echo "==> removed secrets/, seaweedfs/identities.json and valkey/users.acl."
  echo "    All three are regenerated by scripts/dev/bootstrap.sh: the two credential files are"
  echo "    re-rendered from their committed *.example templates, then rewritten with fresh values."
  echo "    DO NOT start the stack before running it — Valkey with no users.acl comes up as"
  echo "    \`user default on nopass +@all\`, wide open and unauthenticated."
fi

echo
echo "==> done. Next: scripts/dev/bootstrap.sh"
