#!/usr/bin/env bash
# scripts/ops/backup.sh
#
# ================================================================================================
# SCOPE: `pgdata` AND THE SEAWEEDFS VOLUME. NOTHING ELSE.
# ================================================================================================
# The scope IS the invariant. Every exclusion below is a decision, not an omission, and each one is
# wrong to "fix":
#
#   qdrant        Snapshotted for RTO elsewhere, but rebuildable BY CONTRACT (ADR-010). Including
#                 it here would be actively harmful: a snapshot restore does not prove the ADR, it
#                 COPIES THE DRIFT FORWARD. If someone wrote a payload key that exists in no
#                 PostgreSQL column — a curated title, a boost weight, a language guess — a restore
#                 reproduces it perfectly and the index has quietly become authoritative. Every
#                 vector is present, chunk counts match, and only the ranking moved. The restore
#                 drill rebuilds from PostgreSQL instead, which is the only thing that detects it.
#
#   valkey-core   Its AOF is RESTART durability, not a backup. Restoring it resurrects stale
#                 reservations, spent idempotency records, expired locks that now read as held, and
#                 circuit-breaker state describing an outage that ended weeks ago. Every one of
#                 those families is absence-sensitive: losing them fails open, restoring them
#                 stale fails wrong, and wrong is worse.
#
#   valkey-cache  Nothing to back up, BY CONSTRUCTION. It runs --save "" --appendonly no and has no
#                 volume, and that absence is the erasure control for the only tenant text in
#                 Valkey. A backup job that touched it would silently undo the one posture that
#                 makes an erasure request signable.
#
#   models        ~4.6 GB of public weights pinned by commit sha in models.manifest.toml. Re-fetch
#                 them; do not carry them in every restore.
#
#   beat-schedule, acme, telemetry volumes
#                 Regenerated on start. acme.json in particular should be re-issued rather than
#                 restored — a restored ACME account plus a changed hostname is a certificate that
#                 silently stays untrusted.
#
# WITHIN the SeaweedFS volume there are two tiers, and they are not equally important:
#   tier 1  original/ and derived/snapshot/  — the ONLY bytes in this system that cannot be
#           recomputed from anything else. These go offsite.
#   tier 2  everything under derived/        — recomputable from an original plus the pinned
#           parser/OCR/chunker config versions. Cheap to lose, NOT safe to lose: the §25.3 Qdrant
#           rebuild reads derived/parse/, so losing it converts a rebuild into a re-ingest.
# This script takes the whole volume; the offsite copy is what the tiering applies to.
#
# ------------------------------------------------------------------------------------------------
# Usage:  scripts/ops/backup.sh [DEST_DIR]
# Default DEST_DIR: ./backups/<UTC timestamp>
# ------------------------------------------------------------------------------------------------
# AN UNTESTED BACKUP IS AN ASSUMPTION. scripts/ops/restore-drill.md is the drill, and it is not
# optional: run it on a schedule, not after an incident.

set -euo pipefail

REPO_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$REPO_ROOT/infrastructure/docker"
PROJECT="knowledgebot"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
DEST="${1:-$REPO_ROOT/backups/$STAMP}"

say() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
die() { printf '\033[31mFATAL %s\033[0m\n' "$*" >&2; exit 1; }

command -v docker >/dev/null 2>&1 || die "docker not found"
cd "$DOCKER_DIR"

mkdir -p "$DEST"
# The dump contains every organization's data. 700 on the directory and 600 on the files, before
# anything is written into them.
chmod 700 "$DEST"

say "Backup destination: $DEST"

# ================================================================================================
# 1. PostgreSQL — a LOGICAL dump, not a copy of the pgdata directory.
# ================================================================================================
# Copying $PGDATA from a running server produces a torn, unrestorable directory: pages are written
# out of order relative to WAL, and the result usually restores far enough to look fine. pg_dump
# takes a consistent MVCC snapshot without blocking writers.
#
# --format=custom  restorable table-by-table, and compressed.
# --no-owner/--no-privileges  the restore target's role names are not guaranteed to match.
say "pg_dump"
docker compose exec -T postgres sh -c '
  set -eu
  export PGPASSWORD="$(cat /run/secrets/postgres_password)"
  pg_dump --username="$POSTGRES_USER" --dbname="$POSTGRES_DB" \
          --format=custom --compress=9 --no-owner --no-privileges
' > "$DEST/postgres.dump"
chmod 600 "$DEST/postgres.dump"

# A dump that failed halfway still exits 0 through a pipe if the container's shell did. Check the
# custom-format magic bytes rather than trusting the exit code.
head -c 5 "$DEST/postgres.dump" | grep -q 'PGDMP' \
  || die "postgres.dump does not begin with the PGDMP magic — the dump did not complete"
say "pg_dump OK ($(du -h "$DEST/postgres.dump" | cut -f1))"

# ================================================================================================
# 2. SeaweedFS — the volume, as a tar.
# ================================================================================================
# THERE IS NO CONSISTENT ONLINE SNAPSHOT of a SeaweedFS cluster; upstream guidance is to pause it.
# For a single-host deployment, stopping the gateway for the length of the tar is the honest option
# and is what this does. The alternative — `weed filer.backup` running continuously to a second
# cluster, WITH `-initialSnapshot` on its first run — is the production answer and belongs in the
# deployment, not in a script. Without -initialSnapshot you get changes from subscription time
# forward and the EXISTING TREE IS SILENTLY ABSENT.
#
# Note also that -defaultReplication is NOT backup: writes are strongly consistent, but SeaweedFS
# never re-establishes a lost replica on its own — volume.fix.replication is a manual command that
# has to be scheduled.
say "Stopping seaweedfs for a consistent copy"
docker compose stop seaweedfs
# The restart MUST be a trap, not the next statement. Under `set -euo pipefail` a failed tar
# exits before any following line runs, leaving the object store stopped — every upload and
# download 500s until a human notices, and the backup that caused it reported nothing.
# shellcheck disable=SC2064
trap "docker compose start seaweedfs >/dev/null 2>&1 || true" EXIT

# shellcheck disable=SC2016
docker run --rm \
  -v "${PROJECT}_seaweed:/data:ro" \
  -v "$DEST:/backup" \
  alpine:3.21 \
  tar -C /data -czf /backup/seaweed.tar.gz .

docker compose start seaweedfs
trap - EXIT
chmod 600 "$DEST/seaweed.tar.gz"

tar -tzf "$DEST/seaweed.tar.gz" >/dev/null \
  || die "seaweed.tar.gz does not list — the archive is truncated"
say "seaweed archive OK ($(du -h "$DEST/seaweed.tar.gz" | cut -f1))"

# ================================================================================================
# 3. Manifest — what this backup is, and what it deliberately is not.
# ================================================================================================
# The manifest exists so a restore does not have to guess, and so the exclusions are visible to
# whoever finds this directory in eighteen months without the script.
{
  echo "created_utc:  $STAMP"
  echo "project:      $PROJECT"
  echo "host:         $(hostname)"
  echo
  echo "contents:"
  echo "  postgres.dump    pg_dump --format=custom  (SOURCE OF TRUTH)"
  echo "  seaweed.tar.gz   the seaweed volume       (originals + crawl snapshots + derived)"
  echo
  echo "deliberately excluded, do NOT add:"
  echo "  qdrant        rebuildable by contract (ADR-010); a snapshot restore copies drift forward"
  echo "  valkey-core   AOF is restart durability; restoring it resurrects stale reservations"
  echo "  valkey-cache  nothing written, by construction — the erasure control"
  echo "  models        public weights, pinned by sha in models.manifest.toml"
  echo "  beat-schedule / acme / telemetry volumes   regenerated on start"
  echo
  echo "sha256:"
  ( cd "$DEST" && sha256sum postgres.dump seaweed.tar.gz )
} > "$DEST/MANIFEST.txt"
chmod 600 "$DEST/MANIFEST.txt"

say "Done: $DEST"
cat <<EOF

  Two things this script cannot do for you:

  1. GET IT OFF THIS HOST. A backup on the machine it backs up is not a backup. Ship
     postgres.dump and seaweed.tar.gz somewhere with different failure modes.

  2. PROVE IT RESTORES. Run scripts/ops/restore-drill.md against this directory, on a schedule.
     Note especially that the drill REBUILDS Qdrant from PostgreSQL rather than restoring a
     snapshot — that is the only step that can detect the index having quietly become
     authoritative.
EOF
