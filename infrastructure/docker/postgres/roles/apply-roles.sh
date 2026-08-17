#!/usr/bin/env bash
# infrastructure/docker/postgres/roles/apply-roles.sh
#
# ==================================================================================================
# THE ROLE SPLIT (D21) — the ONE implementation, run by the `postgres-roles` one-shot service.
# ==================================================================================================
# WHAT THIS CLOSES. `POSTGRES_USER` creates exactly one role and makes it a SUPERUSER, and a
# superuser BYPASSES EVERY ACL CHECK. So with one role, `audit_logs`' `REVOKE UPDATE, DELETE` is an
# audit artifact and not a control: measured on PostgreSQL 18.4, `pg_class.relacl` showed no UPDATE
# and no DELETE for the role on the parent or on any partition, and
# `UPDATE audit_logs SET operation='tampered'` then returned `UPDATE 1`. Append-only rested on three
# soft controls — an auditable ACL, App\Models\AuditLog refusing in PHP, and no code path issuing the
# statement — and zero hard ones.
#
# THE TWO ROLES, AND WHY IT IS TWO AND NOT ONE.
#   kb_migrate  LOGIN, NOSUPERUSER. OWNS every relation. Runs `artisan migrate` and the audit
#               partition DDL. Has CREATE on schema public.
#   kb_app      LOGIN, NOSUPERUSER. OWNS NOTHING. Every request-serving container and every worker
#               connects as this. USAGE on schema public and no CREATE.
#
# A TABLE OWNER CAN GRANT THE PRIVILEGE BACK TO ITSELF, which is the whole reason the application
# role must not own `audit_logs`. Measured in both directions on 18.4:
#   * as the owner: `REVOKE UPDATE ... FROM CURRENT_USER` then `UPDATE` -> `permission denied`;
#     then `GRANT UPDATE ON audit_logs TO kb_migrate` -> `GRANT`, and `UPDATE` -> `UPDATE 1`.
#   * as kb_app (owns nothing): `GRANT UPDATE ON audit_logs TO kb_app` emits
#     `WARNING: no privileges were granted` and the statement STILL REPORTS `GRANT`. It is a
#     warning, not an error — a test asserting that the GRANT throws will fail on correct
#     behaviour. What proves the property is that the following UPDATE is still refused.
#
# TRUNCATE IS NOT REVOKED — IT IS NEVER GRANTED. `GRANT SELECT, INSERT, UPDATE, DELETE` does not
# include TRUNCATE, and PostgreSQL grants a non-owner nothing implicitly, so kb_app holds no TRUNCATE
# on ANY table (measured: `TRUNCATE users_probe` as kb_app -> `permission denied`). That settles the
# question the migration left open. The Integration suite's `DatabaseTruncation` is unaffected: it
# runs against `postgres-test` as `kb_test`, which POSTGRES_USER still makes a superuser-owner of its
# own throwaway database, and never as this role. Production's application role simply never receives
# the privilege — a stronger statement than a REVOKE on one table, and it needs no exception list.
#
# ==================================================================================================
# WHY THIS IS A ONE-SHOT SERVICE AND NOT /docker-entrypoint-initdb.d — BOTH MEASURED.
# ==================================================================================================
# It was written as `postgres/initdb/01-roles.sh` first. That does not work, for two reasons, and
# neither is guessable from the documentation:
#
# (1) AN INIT SCRIPT CANNOT READ A COMPOSE SECRET. The postgres entrypoint calls `docker_setup_env`
#     (which is where `file_env` reads `POSTGRES_PASSWORD_FILE`) while still root, and only THEN
#     `exec gosu postgres "$BASH_SOURCE"`. Everything after that — including
#     `docker_process_init_files /docker-entrypoint-initdb.d/*` — runs as uid 70. A Compose
#     file-secret arrives with the HOST file's ownership and mode, which for `secrets/` is
#     `-rw------- 1000 1000`. Measured, verbatim, from a real boot through the real entrypoint:
#         /docker-entrypoint-initdb.d/01-roles.sh: line 90:
#           /run/secrets/postgres_migrate_password: Permission denied
#     `POSTGRES_PASSWORD_FILE` works only because it is read on the other side of the gosu.
#
# (2) `uid:`/`gid:`/`mode:` ON A FILE SECRET ARE SILENTLY IGNORED. The obvious repair is the long
#     secret syntax. Measured on Compose v5.3.1: a service declaring
#     `{source: owned, target: owned, uid: "70", gid: "70", mode: 0400}` received
#     `-rw------- 1 1000 1000 owned`, identical to the plain form, with NO warning and NO error, and
#     the container user could read neither. A repair that reports success and changes nothing is
#     worse than the original failure.
#
# And (3), which is the reason to be glad of it: init scripts run ONLY on an empty data directory, so
# that path could never have covered the deployment that already exists. A role split present on
# every fresh machine and absent from every existing one is precisely the divergence
# `initdb/00-extensions.sql`'s header warns about. A one-shot service runs on both, every time, and
# this script is idempotent so running it every time is free.
#
# ==================================================================================================
# `CREATE ROLE ... PASSWORD '<plaintext>'` IS WRITTEN TO THE SERVER LOG. MEASURED.
# ==================================================================================================
# postgresql.conf.d/kb.conf sets `log_statement = 'ddl'`, and CREATE/ALTER ROLE is DDL. Measured on
# 18.4, verbatim:
#     LOG:  statement: CREATE ROLE kb_leak_probe LOGIN PASSWORD 'sekrit-app-pw-42';
# That log is stderr, therefore `docker logs`, therefore Loki — the Collector's filelog receiver
# includes every container on this host. A database password in the log pipeline is a credential
# living under a different retention policy with a different set of readers.
#
# AND THE ONE-LINER FIX DOES NOT WORK. `psql -c "SET log_statement='none'; CREATE ROLE ..."` sends
# both statements as ONE simple-query string, which is logged in full BEFORE the SET takes effect —
# measured, the plaintext appeared anyway. The `SET` must be its own round trip. With it on its own
# round trip, re-measured: zero occurrences of the plaintext in the server log. That is why
# everything below goes through one heredoc session and never through `psql -c`.
# ==================================================================================================

set -euo pipefail

# Role names. Literals with an env override, not values read from `.env`: a role NAME is not a
# credential and not deployment-specific, and adding a key to infrastructure/docker/.env.example
# would put the key-set drift check (`preflight.sh` §2b — a CI gate did this too until `.github/`
# was deleted) red on every existing deployment for a value nobody will ever change. Nothing now
# asserts these names match the ones compose.yaml hands the containers, so a rename is one PR with
# nothing but review on it.
MIGRATE_ROLE="${POSTGRES_MIGRATE_USER:-kb_migrate}"
APP_ROLE="${POSTGRES_APP_USER:-kb_app}"
MIGRATE_PW_FILE="${POSTGRES_MIGRATE_PASSWORD_FILE:-/run/secrets/postgres_migrate_password}"
APP_PW_FILE="${POSTGRES_APP_PASSWORD_FILE:-/run/secrets/postgres_app_password}"
PARENT="${KB_AUDIT_PARENT:-audit_logs}"

# Connection: PG* environment, so the same body works from the one-shot container (PGHOST=postgres)
# and from an operator's `docker exec` (PGHOST unset -> the unix socket).
export PGHOST="${PGHOST:-postgres}"
export PGPORT="${PGPORT:-5432}"
export PGDATABASE="${PGDATABASE:-${POSTGRES_DB:-knowledgebot}}"
export PGUSER="${PGUSER:-${POSTGRES_USER:-knowledgebot}}"

log()  { printf '  apply-roles: %s\n' "$*"; }
die()  { printf 'apply-roles: FATAL: %s\n' "$*" >&2; exit 1; }

# ── The superuser password. Read as ROOT (this container runs `user: "0:0"` for exactly this
# reason — see the header's measurement (1)); root ignores the 0600 mode the host file carries.
SUPER_PW_FILE="${POSTGRES_PASSWORD_FILE:-/run/secrets/postgres_password}"
if [ -z "${PGPASSWORD:-}" ]; then
  [ -r "$SUPER_PW_FILE" ] || die "cannot read $SUPER_PW_FILE. This container must run as uid 0: a
  Compose file-secret keeps the host file's 0600/1000:1000 mode and \`uid:\`/\`mode:\` on the secret
  reference are silently ignored (both measured — see the header)."
  PGPASSWORD="$(tr -d '\r\n' < "$SUPER_PW_FILE")"
  export PGPASSWORD
fi

# ── The two role passwords. `-r`, not just `-s`: an UNREADABLE file passes `-s` (which only needs a
# stat) and then fails at the read, which is exactly how the initdb version failed — it reported the
# file as present and died three lines later on `Permission denied`.
for f in "$MIGRATE_PW_FILE" "$APP_PW_FILE"; do
  [ -e "$f" ] || die "$f does not exist. Run scripts/dev/bootstrap.sh, which generates both and
  regenerates neither, and check that the \`postgres-roles\` service declares both under \`secrets:\`."
  [ -r "$f" ] || die "$f exists but is not readable by uid $(id -u). See the header: this service
  must run as uid 0."
  [ -s "$f" ] || die "$f is empty. Refusing to create a passwordless LOGIN role: scram-sha-256
  refuses it at connect time, so the failure would surface from twelve containers at once."
done

MIGRATE_PW="$(tr -d '\r\n' < "$MIGRATE_PW_FILE")"
APP_PW="$(tr -d '\r\n' < "$APP_PW_FILE")"

# These values are interpolated into CREATE/ALTER ROLE. bootstrap.sh generates base64 (alphabet
# A-Za-z0-9+/=), so this can only fire on a hand-written password — which is when it must.
case "$MIGRATE_PW$APP_PW" in
  *"'"*|*'\'*|*'"'*) die "a role password contains a quote or a backslash. Use the base64 form
  bootstrap.sh generates; these values are interpolated into CREATE ROLE." ;;
esac

log "target $PGUSER@$PGHOST:$PGPORT/$PGDATABASE — owner role $MIGRATE_ROLE, application role $APP_ROLE"

# ==================================================================================================
# ONE heredoc, ONE session, ONE transaction.
# ==================================================================================================
# ONE TRANSACTION because ownership changes, grants and revokes are all transactional DDL in
# PostgreSQL: a failure halfway leaves the cluster exactly as it was. A series of `psql -c` calls
# leaves the tables half-reassigned if the fifth fails, and the output does not say which half.
psql -v ON_ERROR_STOP=1 --no-psqlrc -f - <<SQL
SET log_statement = 'none';
SET log_min_duration_statement = -1;

BEGIN;

-- ── 1. The roles. ────────────────────────────────────────────────────────────────────────────────
-- CREATE-if-absent, then ALTER unconditionally, so a re-run REPAIRS a role someone created by hand
-- with the wrong attributes rather than printing "exists" and leaving a superuser in place.
DO \$do\$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '$MIGRATE_ROLE') THEN
        EXECUTE format('CREATE ROLE %I LOGIN PASSWORD %L', '$MIGRATE_ROLE', '$MIGRATE_PW');
        RAISE NOTICE 'created role %', '$MIGRATE_ROLE';
    ELSE
        RAISE NOTICE 'role % already exists (password NOT changed)', '$MIGRATE_ROLE';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '$APP_ROLE') THEN
        EXECUTE format('CREATE ROLE %I LOGIN PASSWORD %L', '$APP_ROLE', '$APP_PW');
        RAISE NOTICE 'created role %', '$APP_ROLE';
    ELSE
        RAISE NOTICE 'role % already exists (password NOT changed)', '$APP_ROLE';
    END IF;
END
\$do\$;

-- NOSUPERUSER IS ASSERTED ON EVERY RUN, not only at creation. It is the entire point of this
-- change: a superuser bypasses every ACL check, so a role that regained it silently undoes the
-- whole control WHILE EVERY ACL ASSERTION IN THE TEST SUITE STILL PASSES — which is the state this
-- deployment was in before today.
ALTER ROLE $MIGRATE_ROLE NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT LOGIN;
ALTER ROLE $APP_ROLE     NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS NOINHERIT LOGIN;

RESET log_statement;
RESET log_min_duration_statement;

-- ── 2. Schema privileges. ────────────────────────────────────────────────────────────────────────
-- PG15 removed PUBLIC's CREATE on schema \`public\`, so the owner role needs it explicitly.
-- USAGE AND NOT CREATE for the application role, and the measured consequence is what shapes the
-- Laravel side: as kb_app, \`CREATE TABLE ... PARTITION OF audit_logs\` fails at
-- \`permission denied for schema public\` — BEFORE it ever reaches the ownership check. So
-- kb:create-audit-partitions cannot run on the application connection, and needs a DDL connection.
GRANT CREATE, USAGE ON SCHEMA public TO $MIGRATE_ROLE;
GRANT USAGE ON SCHEMA public TO $APP_ROLE;
-- AND THE REVOKE, WHICH IS WHAT MAKES THIS SCRIPT ACTUALLY REPAIRING RATHER THAN ONLY ADDITIVE.
-- Found by testing it: with only the GRANT above, a CREATE that someone had granted by hand
-- SURVIVED a re-run, so the verifier failed forever and re-running the script fixed nothing —
-- an "idempotent repair" tool that cannot repair the one property it checks. Every other statement
-- in this file is either CREATE-if-absent, an unconditional ALTER, or a REVOKE; this was the one
-- one-way GRANT, and that asymmetry was the bug.
--
-- FROM PUBLIC as well: PG15 already removed PUBLIC's CREATE on \`public\`, so it is a no-op today.
-- It is here to state the intent against a future \`GRANT ALL ON SCHEMA public TO PUBLIC\` in an ops
-- script, which would otherwise hand the application role table-creation rights — and an owner may
-- GRANT itself anything on what it owns — with nothing to notice it.
REVOKE CREATE ON SCHEMA public FROM $APP_ROLE, PUBLIC;

-- ── 3. Ownership. The half a fresh volume does not need. ────────────────────────────────────────
-- Every relation on an existing deployment was created by the POSTGRES_USER superuser and is owned
-- by it. What moving ownership to kb_migrate buys is not that kb_app stops being the owner (it never
-- was) but that the OWNER becomes a role no request-serving container holds a password for.
--
-- relkind IN ('r','p'): 'p' is the partitioned PARENT, 'r' its partitions. \`ALTER TABLE\` on a
-- parent does NOT recurse for ownership, so both must be walked. 'v','m' are included so a view
-- added later is not a silent gap; there are none today.
DO \$do\$
DECLARE r record; n int := 0;
BEGIN
    FOR r IN SELECT c.oid::regclass AS rel
               FROM pg_class c JOIN pg_namespace ns ON ns.oid = c.relnamespace
              WHERE ns.nspname = 'public' AND c.relkind IN ('r','p','v','m')
                AND pg_get_userbyid(c.relowner) <> '$MIGRATE_ROLE'
              ORDER BY 1
    LOOP
        EXECUTE format('ALTER TABLE %s OWNER TO %I', r.rel, '$MIGRATE_ROLE');
        n := n + 1;
    END LOOP;
    RAISE NOTICE 'reassigned % relation(s) to %', n, '$MIGRATE_ROLE';
END
\$do\$;

DO \$do\$
DECLARE r record; n int := 0;
BEGIN
    FOR r IN SELECT c.oid::regclass AS rel
               FROM pg_class c JOIN pg_namespace ns ON ns.oid = c.relnamespace
              WHERE ns.nspname = 'public' AND c.relkind = 'S'
                AND pg_get_userbyid(c.relowner) <> '$MIGRATE_ROLE'
              ORDER BY 1
    LOOP
        EXECUTE format('ALTER SEQUENCE %s OWNER TO %I', r.rel, '$MIGRATE_ROLE');
        n := n + 1;
    END LOOP;
    RAISE NOTICE 'reassigned % sequence(s) to %', n, '$MIGRATE_ROLE';
END
\$do\$;

-- ── 4. Default privileges for FUTURE objects. THE \`FOR ROLE\` IS THE WHOLE CLAUSE. ─────────────
-- \`ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ...\` records \`defaclrole = current_user\`, so
-- run as the superuser it applies ONLY to tables that superuser creates. MEASURED on 18.4: with the
-- plain form set, three tables created by kb_migrate came out \`relacl = NULL\` with kb_app holding
-- NOTHING. The symptom is every query returning 42501 the first time the app connects after the
-- next migration, and the repair that suggests itself under pressure is to widen kb_app rather than
-- to fix the clause. \`FOR ROLE $MIGRATE_ROLE\` is what makes a table the MIGRATION creates
-- reachable by the APPLICATION.
ALTER DEFAULT PRIVILEGES FOR ROLE $MIGRATE_ROLE IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO $APP_ROLE;
ALTER DEFAULT PRIVILEGES FOR ROLE $MIGRATE_ROLE IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO $APP_ROLE;
-- NOTE WHAT IS ABSENT: TRUNCATE, TRIGGER, REFERENCES, MAINTAIN. TRUNCATE for the reason in the
-- header. REFERENCES would let the app role add a foreign key into a table it does not own.

-- ── 5. Grants on what already exists. NO TRUNCATE, EVER. ────────────────────────────────────────
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO $APP_ROLE;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO $APP_ROLE;

-- ── 6. The audit exception: the parent AND EVERY PARTITION. ─────────────────────────────────────
-- PRIVILEGES ARE NOT INHERITED THROUGH THE PARTITION HIERARCHY. A query routed through the parent
-- checks only the parent, but \`UPDATE audit_logs_2026_08 SET ...\` checks the partition — and step
-- 5 just granted UPDATE and DELETE on every one of them. Revoking only on the parent leaves a fully
-- writable back door named one underscore away.
--
-- AND THE PART THAT IS COUNTER-INTUITIVE: step 4's default privileges mean EVERY FUTURE PARTITION
-- ARRIVES WRITABLE. Measured — a partition created by kb_migrate after this revoke came out
-- \`kb_app=arwd/kb_migrate\`. Under the old single-superuser deployment the per-partition REVOKE in
-- EloquentAuditLogPartitionRepository was cosmetic; under this split IT IS THE CONTROL, and a
-- partition created by any path that skips it is writable. preflight.sh §8 sweeps pg_inherits for
-- exactly that, and a re-run of this script repairs it.
--
-- Walked from pg_inherits rather than named, so it covers partitions added by hand. \`to_regclass\`
-- returning NULL is the not-yet-migrated check that does not throw.
DO \$do\$
DECLARE r record; n int := 0;
BEGIN
    IF to_regclass('public.$PARENT') IS NULL THEN
        RAISE NOTICE '% does not exist yet; nothing to revoke. This script is idempotent — it runs '
                     'again on every \`compose up\`, and preflight.sh reports the gap meanwhile.',
                     '$PARENT';
        RETURN;
    END IF;
    FOR r IN SELECT to_regclass('public.$PARENT') AS rel
             UNION ALL
             SELECT inhrelid::regclass FROM pg_inherits
              WHERE inhparent = to_regclass('public.$PARENT')
    LOOP
        EXECUTE format('REVOKE UPDATE, DELETE, TRUNCATE ON TABLE %s FROM PUBLIC, %I',
                       r.rel, '$APP_ROLE');
        n := n + 1;
    END LOOP;
    RAISE NOTICE 'revoked UPDATE/DELETE/TRUNCATE from % on % audit relation(s)', '$APP_ROLE', n;
END
\$do\$;

-- ── 7. Per-role session settings (postgresql-patterns Definition of done). ──────────────────────
-- kb.conf sets these cluster-wide and is therefore wrong in one direction for each role: a
-- migration that scans a large table must not die at 60 s, and a request-serving connection must
-- not sit in a transaction for five minutes. A role split is the first point at which they can
-- differ, which is why they move here rather than staying one global value.
ALTER ROLE $APP_ROLE     SET statement_timeout = '60s';
ALTER ROLE $APP_ROLE     SET idle_in_transaction_session_timeout = '15s';
ALTER ROLE $APP_ROLE     SET lock_timeout = '3s';
ALTER ROLE $MIGRATE_ROLE SET statement_timeout = 0;
ALTER ROLE $MIGRATE_ROLE SET idle_in_transaction_session_timeout = '5min';
ALTER ROLE $MIGRATE_ROLE SET lock_timeout = '3s';

COMMIT;
SQL

unset MIGRATE_PW APP_PW PGPASSWORD

log "applied. \`artisan migrate\` runs as $MIGRATE_ROLE; every runtime container connects as $APP_ROLE."
