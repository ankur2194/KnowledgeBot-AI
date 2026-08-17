#!/usr/bin/env bash
# scripts/ops/postgres-roles.sh
#
# ==================================================================================================
# OPERATOR ENTRY POINT for the PostgreSQL role split (D21). Idempotent. Restarts nothing.
# ==================================================================================================
# THE SQL LIVES IN ONE PLACE AND IT IS NOT HERE:
#     infrastructure/docker/postgres/roles/apply-roles.sh
# That script is what the `postgres-roles` one-shot Compose service runs on every `docker compose
# up`, and read its header for the whole design and every measurement behind it — why it is a
# one-shot rather than an initdb script, why `ALTER DEFAULT PRIVILEGES` needs `FOR ROLE`, why
# TRUNCATE is never granted rather than revoked, and why `CREATE ROLE ... PASSWORD` must not go
# through `psql -c`.
#
# This wrapper exists for the case the one-shot cannot cover on its own: applying the split to a
# cluster that is ALREADY RUNNING, without bringing the rest of the stack up, and then VERIFYING the
# properties rather than the statements. Those two halves are the reason it is a separate file —
# `docker compose up postgres-roles` would do the first half and none of the second.
#
#   ./scripts/ops/postgres-roles.sh              apply, then verify
#   ./scripts/ops/postgres-roles.sh --verify-only assert the properties, change nothing
#   ./scripts/ops/postgres-roles.sh --dry-run     print the SQL (passwords redacted), execute nothing
#
# ── IT DOES NOT RESTART ANYTHING, AND THAT IS THE POINT ───────────────────────────────────────────
# Every statement runs against the already-running server. No container is recreated, no config file
# is re-read, no volume is touched. The `postgres` service must be UP; nothing else needs to be.
#
# ── BUT THE ROLLOUT IS TWO STEPS AND THE ORDER IS NOT SYMMETRIC ───────────────────────────────────
#   roles first, then containers  -> in between, every container is still connecting as the
#                                    POSTGRES_USER superuser, which still works — it is still a
#                                    superuser with every privilege. Nothing breaks. SAFE.
#   containers first, then roles  -> the containers connect as a role that does not exist:
#                                    `FATAL: role "kb_app" does not exist`. Every Laravel service
#                                    and laravel-migrate fail, and ~ten services gated on
#                                    `service_completed_successfully` never leave Created.
#
# On a fresh `docker compose up` the ordering is enforced by compose itself: the `postgres-roles`
# one-shot sits between `postgres: service_healthy` and everything that needs a role, and
# `laravel-migrate` gates on its `service_completed_successfully`. On an EXISTING deployment run this
# script first. `scripts/ops/preflight.sh` §8 enforces the same order from the other side — it fails
# the deploy while the compose files name a role the cluster does not have, so `make deploy`
# withholds `docker compose up` instead of discovering it live.
#
# ── WHAT IT DELIBERATELY DOES NOT DO ──────────────────────────────────────────────────────────────
#   * It never DROPs the POSTGRES_USER superuser. That role owns the database, is what initdb and
#     CREATE EXTENSION need, and is the break-glass account. It stops being a RUNTIME credential; it
#     does not stop existing.
#   * It never rotates postgres_password, or either new password once created. Rotating a password
#     while the stack is up splits disk from server — the secret file changes, the server does not
#     re-read it — and the damage appears at the next restart, for any reason at all.
#   * It writes no backup. Ownership changes are catalogue-only and this same script re-applies them
#     idempotently, but `pg_dump` before a first run is cheap and this is not a substitute for it.
# ==================================================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
DOCKER_DIR="$REPO_ROOT/infrastructure/docker"
SECRETS_DIR="$DOCKER_DIR/secrets"
APPLY_SH="$DOCKER_DIR/postgres/roles/apply-roles.sh"

# These three must stay in step with apply-roles.sh's defaults and compose.yaml's per-service
# environment. A CI check used to compare all three by name and was deleted with `.github/`, so the
# only remaining comparison is `preflight.sh`'s, at deploy time.
MIGRATE_ROLE="${POSTGRES_MIGRATE_USER:-kb_migrate}"
APP_ROLE="${POSTGRES_APP_USER:-kb_app}"
PARENT="audit_logs"

PG_CONTAINER="${PG_CONTAINER:-knowledgebot-postgres-1}"
PG_DB="${POSTGRES_DB:-knowledgebot}"
PG_SUPERUSER="${POSTGRES_USER:-knowledgebot}"

MODE="apply"
case "${1:-}" in
  --dry-run)     MODE="dry-run" ;;
  --verify-only) MODE="verify"  ;;
  "")            ;;
  *) printf 'usage: %s [--dry-run|--verify-only]\n' "$0" >&2; exit 2 ;;
esac

say()  { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
ok()   { printf '\033[32m    OK   %s\033[0m\n' "$*"; }
warn() { printf '\033[33m    WARN %s\033[0m\n' "$*"; }
die()  { printf '\033[31mFATAL %s\033[0m\n' "$*" >&2; exit 1; }

say "PostgreSQL role split ($MODE) — container $PG_CONTAINER, database $PG_DB"

command -v docker >/dev/null 2>&1 || die "docker is not on PATH"
[ -r "$APPLY_SH" ] || die "$APPLY_SH is missing. It is the single implementation; this file is a wrapper."

psql_super() {
  docker exec -i "$PG_CONTAINER" \
    psql -v ON_ERROR_STOP=1 --no-password --no-psqlrc -U "$PG_SUPERUSER" -d "$PG_DB" "$@"
}

# ==================================================================================================
# APPLY
# ==================================================================================================
# HOW THE SCRIPT GETS ITS INPUTS HERE. `apply-roles.sh` reads the three passwords from files, and in
# the one-shot service those are Compose secrets at /run/secrets/. There is no such mount inside the
# running `postgres` container, so this wrapper bind-mounts secrets/ read-only into a THROWAWAY
# container off the same image and runs the script there. `--user 0:0`: a Compose file-secret keeps
# the host file's 0600/1000:1000 mode and `uid:`/`mode:` on the secret reference are silently
# ignored (both measured — see apply-roles.sh's header), so root is the only uid that can read them,
# and this is the reason the one-shot service pins the same uid.
#
# NOT `docker exec` INTO THE postgres CONTAINER. That would work for the SQL, but it would also mean
# the operator path and the compose path run the script under different assumptions about where the
# secrets are, which is how two paths that are supposed to be one drift.
if [ "$MODE" != "verify" ]; then
  docker inspect -f '{{.State.Running}}' "$PG_CONTAINER" 2>/dev/null | grep -q true \
    || die "$PG_CONTAINER is not running. Start ONLY postgres and re-run:
      cd infrastructure/docker && docker compose up -d postgres
  This script needs nothing else, and starting the whole stack before the roles exist is the wrong
  order (see the header)."

  for f in postgres_password postgres_migrate_password postgres_app_password; do
    [ -s "$SECRETS_DIR/$f" ] \
      || die "$SECRETS_DIR/$f is missing or empty. Run scripts/dev/bootstrap.sh first — it generates
  all three and regenerates nothing that exists."
  done

  # The network the postgres container is on, so the throwaway can resolve it by service name. `data`
  # is `internal: true`; joining it grants no egress.
  PG_NET="$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{"\n"}}{{end}}' \
              "$PG_CONTAINER" | head -1)"
  [ -n "$PG_NET" ] || die "could not determine $PG_CONTAINER's network"

  # The image tag is read from the RUNNING container rather than written here: `psql` must be the
  # same major as the server, and a second literal is a second thing to bump.
  PG_IMAGE="$(docker inspect -f '{{.Config.Image}}' "$PG_CONTAINER")"

  DOCKER_ARGS=(run --rm --user 0:0 --network "$PG_NET"
    -e "PGHOST=$(docker inspect -f '{{.Config.Hostname}}' "$PG_CONTAINER")"
    -e "PGDATABASE=$PG_DB" -e "PGUSER=$PG_SUPERUSER"
    -e "POSTGRES_MIGRATE_USER=$MIGRATE_ROLE" -e "POSTGRES_APP_USER=$APP_ROLE"
    -v "$SECRETS_DIR:/run/secrets:ro"
    -v "$APPLY_SH:/apply-roles.sh:ro"
    --entrypoint /bin/sh "$PG_IMAGE")

  if [ "$MODE" = "dry-run" ]; then
    warn "--dry-run: printing the SQL apply-roles.sh would send, with the passwords redacted."
    warn "  Redaction is done by pointing psql at /bin/cat, so what you see is the exact byte stream"
    warn "  minus the three interpolated secrets."
    MPW="$(tr -d '\r\n' < "$SECRETS_DIR/postgres_migrate_password")"
    APW="$(tr -d '\r\n' < "$SECRETS_DIR/postgres_app_password")"
    docker "${DOCKER_ARGS[@]}" -c '
      mkdir -p /shim && printf "#!/bin/sh\nexec cat\n" > /shim/psql && chmod +x /shim/psql
      PATH=/shim:$PATH exec /bin/sh /apply-roles.sh' 2>&1 \
      | sed -e "s|${MPW//|/\\|}|<postgres_migrate_password>|g" \
            -e "s|${APW//|/\\|}|<postgres_app_password>|g"
    unset MPW APW
    warn "nothing was executed."
    exit 0
  fi

  say "Applying (infrastructure/docker/postgres/roles/apply-roles.sh, in a throwaway $PG_IMAGE)"
  docker "${DOCKER_ARGS[@]}" -c 'exec /bin/sh /apply-roles.sh' \
    || die "the role split did not apply. It runs in ONE transaction, so the cluster is unchanged."
fi

# ==================================================================================================
# VERIFY — the properties, not the statements.
# ==================================================================================================
say "Verifying"

exists=$(psql_super -tAc "SELECT count(*) FROM pg_roles WHERE rolname IN ('$MIGRATE_ROLE','$APP_ROLE')")
[ "$exists" = "2" ] || die "expected both $MIGRATE_ROLE and $APP_ROLE to exist; found $exists.
  Run this script without --verify-only."

supers=$(psql_super -tAc "SELECT coalesce(string_agg(rolname, ' '), '') FROM pg_roles
                           WHERE rolname IN ('$MIGRATE_ROLE','$APP_ROLE') AND rolsuper")
[ -z "${supers// /}" ] || die "these roles are SUPERUSERS: $supers
  A superuser bypasses every ACL check, so the audit REVOKE is decoration again — and every ACL
  assertion in the Pest suite still passes, which is exactly the state this change exists to leave.
  Repair with \`ALTER ROLE <name> NOSUPERUSER\` and re-run."
ok "$MIGRATE_ROLE and $APP_ROLE exist and neither is a superuser"

appcreate=$(psql_super -tAc "SELECT has_schema_privilege('$APP_ROLE','public','CREATE')")
[ "$appcreate" = "f" ] \
  && ok "$APP_ROLE has no CREATE on schema public (so it cannot create an audit partition)" \
  || die "$APP_ROLE holds CREATE on schema public. It could then create and OWN a table, and an owner
  may GRANT itself anything on what it owns."

notowned=$(psql_super -tAc "SELECT count(*) FROM pg_class c JOIN pg_namespace ns ON ns.oid=c.relnamespace
                             WHERE ns.nspname='public' AND c.relkind IN ('r','p','v','m','S')
                               AND pg_get_userbyid(c.relowner) <> '$MIGRATE_ROLE'")
[ "$notowned" = "0" ] \
  && ok "every relation and sequence in public is owned by $MIGRATE_ROLE" \
  || warn "$notowned relation(s) in public are owned by someone else. Re-run to reassign, or find out
    which second authority created them."

# THE SWEEP. Same query as preflight.sh §8 and the same property the Pest test asserts. It is the one
# that catches the failure this design actually has: a NEW audit partition arrives WRITABLE, because
# the default privileges grant UPDATE/DELETE and privileges are not inherited through the partition
# hierarchy. Only the per-partition REVOKE takes it away.
#
# NOTE THE HONEST LIMIT OF RUNNING IT HERE: in `apply` mode this sweep runs seconds after the revoke
# that guarantees it, so it can only fail on something genuinely strange. The version that has teeth
# is `--verify-only` (and preflight.sh §8), run at a moment nothing has just revoked.
say "audit_logs write-privilege sweep (parent + every partition)"
if [ "$(psql_super -tAc "SELECT to_regclass('public.$PARENT') IS NULL")" = "t" ]; then
  warn "$PARENT does not exist yet — the sweep has nothing to check. Re-run after \`artisan migrate\`."
else
  offenders=$(psql_super -tAc "
    SELECT coalesce(string_agg(rel || '=' || privs, ' '), '')
      FROM (
        SELECT c.oid::regclass::text AS rel,
               (SELECT string_agg(DISTINCT a.privilege_type, ',' ORDER BY a.privilege_type)
                  FROM aclexplode(c.relacl) a
                 WHERE a.grantee = (SELECT oid FROM pg_roles WHERE rolname='$APP_ROLE')
                   AND a.privilege_type IN ('UPDATE','DELETE','TRUNCATE')) AS privs
          FROM pg_class c
         WHERE c.oid = to_regclass('public.$PARENT')
            OR c.oid IN (SELECT inhrelid FROM pg_inherits WHERE inhparent = to_regclass('public.$PARENT'))
      ) AS s
     WHERE s.privs IS NOT NULL")
  [ -z "${offenders// /}" ] || die "$APP_ROLE still holds write privileges on: $offenders
  Append-only is NOT enforced on those relations. If they are partitions created since the last run,
  the cause is that the partition-creating path did not revoke: a parent-only revoke does nothing for
  a partition, because privileges are not inherited through the hierarchy. Re-run this script to
  repair, and fix the creating path — App\\Repositories\\Eloquent\\EloquentAuditLogPartitionRepository
  must revoke on every partition it creates, naming the APPLICATION role and not CURRENT_USER."
  n=$(psql_super -tAc "SELECT 1 + count(*) FROM pg_inherits WHERE inhparent = to_regclass('public.$PARENT')")
  ok "$n audit relation(s): $APP_ROLE holds no UPDATE, DELETE or TRUNCATE on any of them"
fi

cat <<EOF

  ------------------------------------------------------------------------------------------------
  STILL OWED, and none of it is provable from here:
  ------------------------------------------------------------------------------------------------
    1. Recreate the containers so they connect as $APP_ROLE. Until then they are still connecting as
       the $PG_SUPERUSER superuser and the REVOKE above is still bypassed:
         cd infrastructure/docker && docker compose up -d --force-recreate \\
           laravel-migrate laravel-api laravel-api-stream laravel-worker laravel-worker-long \\
           laravel-scheduler ai-api ai-worker-ingestion ai-worker-embedding ai-worker-crawl \\
           ai-worker-evaluation ai-beat
    2. THE ACCEPTANCE TEST, by hand, because a config review is not the assertion:
         docker exec -i $PG_CONTAINER psql -U $APP_ROLE -d $PG_DB \\
           -c "UPDATE audit_logs SET operation='tampered'"
       That must answer \`ERROR: permission denied for table audit_logs\`. Before this change it
       answered \`UPDATE 1\`.
    3. \`make preflight\` — §8 re-asserts all of the above at deploy time.

  WHAT NO AMOUNT OF THIS CAN FIX: $MIGRATE_ROLE OWNS audit_logs, and a table owner may always GRANT
  the privilege back to itself (measured). Real immutability needs an owner nothing logs in as, or
  the table in its own schema with its own default-privilege regime. Both are recorded as open.
EOF
