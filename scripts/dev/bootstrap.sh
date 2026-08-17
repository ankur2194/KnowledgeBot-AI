#!/usr/bin/env bash
# scripts/dev/bootstrap.sh — first run on a developer machine.
#
# Generates the local secrets, rewrites the two files that ship with well-known development
# credentials, starts the stack, and then VERIFIES the object store is closed.
#
# That last step is the reason this script exists rather than a README section — but NOT for the
# reason this header used to give. Measured against the pinned chrislusf/seaweedfs:4.40 (ADR-037,
# docs/19): an ABSENT, directory-shaped or zero-byte identities.json is a FATAL config load, exit
# 255, so the container crash-loops. That path fails CLOSED and you cannot miss it.
#
# The Allow-All state is real and silent, and it has two other triggers: dropping -s3.config from
# the compose command, or a config that PARSES to `"identities": []`. In that state every
# operation succeeds, nothing is authenticated, and the gateway starts cleanly with no error and
# no log line. So never "repair" a seaweedfs crash-loop by writing `{}` into identities.json —
# that converts a loud, safe crash into a wide-open object store. You cannot notice Allow-All by
# looking. You can only assert it, which is what section 6 below does.
#
# The mirror image is valkey: an absent or zero-byte users.acl STARTS, as
# `user default on nopass ~* &* +@all`. That is why this script renders BOTH credential files
# before it brings the stack up.
#
# Safe to re-run. It never overwrites an existing secret.

set -euo pipefail

REPO_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$REPO_ROOT/infrastructure/docker"
SECRETS_DIR="$DOCKER_DIR/secrets"

say()  { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[33m    %s\033[0m\n' "$*"; }
die()  { printf '\033[31mFATAL %s\033[0m\n' "$*" >&2; exit 1; }

# The template-vs-rendered name-set comparison. ONE implementation, shared with
# scripts/ops/preflight.sh §2b — a drift checker that exists twice is the drift it was written to
# catch. The library prints nothing and exits nothing; see its header for why, and for why it
# deliberately offers no "repair" function.
# shellcheck source=../lib/env-drift.sh
. "$REPO_ROOT/scripts/lib/env-drift.sh"

# ------------------------------------------------------------------------------------------------
# DRIFT IS COLLECTED, REPORTED IN FULL AT THE END, AND EXITS NON-ZERO — BUT NOT EARLY.
# ------------------------------------------------------------------------------------------------
# The obvious implementation is `die` at the first missing key. It is wrong, and dangerously so:
# sections 3 and 4 below are what generate the secrets and render valkey/users.acl, and an ABSENT or
# zero-byte users.acl does not stop Valkey — it starts as `user default on nopass ~* &* +@all` and
# takes unauthenticated writes (ADR-037). Exiting at section 2 would therefore turn a reported
# configuration gap into a wide-open datastore. Every step whose omission is DANGEROUS runs first.
#
# What is withheld is `docker compose up`. A stack started with a known-missing key comes up green
# and 500s on login, which is the exact shape of failure this repository keeps filing findings
# about; and on a first run there is never drift (the files are created from the templates), so this
# only ever fires on a re-run against an existing deployment, where the stack is already whatever it
# already was. KB_BOOTSTRAP_IGNORE_ENV_DRIFT=1 is the documented escape hatch for an operator who
# has read the report and wants the stack anyway; it announces itself.
DRIFT_REPORT=()
DRIFT_FOUND=0
drift() {
  DRIFT_FOUND=1
  DRIFT_REPORT+=("$1")
}

# ------------------------------------------------------------------------------------------------
# 0. Preflight
# ------------------------------------------------------------------------------------------------
command -v docker >/dev/null 2>&1 || die "docker not found"
docker compose version >/dev/null 2>&1 || die "docker compose v2+ not found (this stack targets v5)"
command -v openssl >/dev/null 2>&1 || die "openssl not found (needed to generate secrets)"

cd "$DOCKER_DIR"

# ------------------------------------------------------------------------------------------------
# 1. .env — Compose INTERPOLATION, not container environment.
# ------------------------------------------------------------------------------------------------
# Compose reads .env from the PROJECT DIRECTORY, which is the directory of the first -f file. That
# is here. A .env at the repository root would look canonical and would never be read, and with
# DOMAIN unset the Traefik rule renders as Host(`api.`) — valid, healthy, matching nothing, logging
# nothing.
say "Compose interpolation file (.env)"
if [[ -f .env ]]; then
  echo "    .env exists — leaving it alone"
  # …and then CHECK it, which "leaving it alone" used to be a substitute for. Same gap as env/*.env
  # below: .env is rendered once and never updated, so a key added to .env.example afterwards never
  # reaches it. This file is Compose INTERPOLATION, so a missing key does not read as a default —
  # `${FOO}` renders as the empty string and the failure is downstream and shapeless (DOMAIN unset
  # makes the Traefik rule Host(`api.`), which is valid, healthy, and matches nothing).
  #
  # A COMMENTED-OUT KEY IN THE TEMPLATE IS NOT DRIFT: kb_env_names anchors to the start of the line,
  # so `# KB_EDGE_SUBNET=...` — an override whose absence is the supported state — does not count.
  _missing="$(kb_env_missing .env.example .env)"
  _extra="$(kb_env_extra .env.example .env)"
  if [[ -n "$_missing" || -n "$_extra" ]]; then
    warn ".env has DRIFTED from .env.example (names only; no value is compared or printed):"
    [[ -n "$_missing" ]] && warn "  in the template, ABSENT from .env:  $_missing"
    [[ -n "$_extra" ]]   && warn "  in .env, ABSENT from the template:  $_extra"
    drift ".env  <-  .env.example
    IN THE TEMPLATE, ABSENT FROM .env: ${_missing:-(none)}
    IN .env, ABSENT FROM THE TEMPLATE: ${_extra:-(none)}
    This file is read by Compose for \${...} interpolation. An absent key does NOT fall back to a
    default: it interpolates to the empty string, and the damage lands somewhere else entirely.
    Reconcile by hand — .env carries this deployment's real DOMAIN, ACME_EMAIL and UID/GID."
  fi
else
  cp .env.example .env
  # Match the host user so bind-mounted files are not root-owned.
  sed -i "s/^UID=.*/UID=$(id -u)/;s/^GID=.*/GID=$(id -g)/" .env
  echo "    created .env from .env.example (UID/GID set to $(id -u)/$(id -g))"
  warn "Set DOMAIN and WIDGET_DOMAIN before anything needs a certificate."
  warn "WIDGET_DOMAIN must be a DIFFERENT REGISTRABLE DOMAIN, not a subdomain of DOMAIN."
fi

# ------------------------------------------------------------------------------------------------
# 2. env/*.env — CONTAINER environment, via env_file:. A different directory, on purpose.
# ------------------------------------------------------------------------------------------------
# "EXISTS — LEAVING IT ALONE" WAS TRUE AND INSUFFICIENT, AND THAT SENTENCE IS THE DEFECT.
# Not overwriting is right: the rendered file is where an operator's per-deployment values live.
# But it was also the ONLY thing this section said, so a template that gained a key delivered it to
# new deployments and to nobody else, silently, forever. Measured on the development deployment
# 2026-08-13: env/core-api.env was missing FRONTEND_URL, MAIL_EHLO_DOMAIN, MAIL_FROM_ADDRESS,
# MAIL_FROM_NAME and SANCTUM_STATEFUL_DOMAINS — an entire deliverable's worth of configuration —
# while both files looked fine and the stack came up green.
#
# So: still never overwrite, but never stay quiet either. Names are compared, values are not.
say "Container environment files (env/*.env)"
shopt -s nullglob
EXAMPLES=(env/*.env.example)
shopt -u nullglob

# FAIL CLOSED on finding no templates. A check that silently checks nothing is worse than no check,
# because it is believed — and here it would also mean every `env_file:` target is about to be
# absent, which Compose refuses to start on.
(( ${#EXAMPLES[@]} > 0 )) || die "no *.env.example templates found in $DOCKER_DIR/env/.
      They are COMMITTED files. Either the layout moved or they were deleted; restore them with
      git checkout -- infrastructure/docker/env/"

for example in "${EXAMPLES[@]}"; do
  target="${example%.example}"
  base="$(basename "$target")"

  if [[ ! -f "$target" ]]; then
    cp "$example" "$target"
    echo "    created $base"
    continue
  fi

  missing="$(kb_env_missing "$example" "$target")"
  extra="$(kb_env_extra "$example" "$target")"

  if [[ -z "$missing" && -z "$extra" ]]; then
    echo "    $base exists and matches its template (variable names)"
    continue
  fi

  echo "    $base exists — leaving it alone, BUT IT HAS DRIFTED:"
  # Itemised one key per line, not a comma-run. A five-name list on one wrapped line is exactly
  # what an operator's eye slides over, and the whole point of this section is that it cannot be.
  if [[ -n "$missing" ]]; then
    warn "  MISSING — in $(basename "$example"), absent from $base:"
    for k in $missing; do warn "      $k"; done
  fi
  if [[ -n "$extra" ]]; then
    warn "  EXTRA — in $base, absent from $(basename "$example"):"
    for k in $extra; do warn "      $k"; done
  fi

  detail="$target  <-  $example"
  [[ -n "$missing" ]] && detail+="
    MISSING FROM THE DEPLOYMENT (the container never receives these):
$(for k in $missing; do printf '      %s\n' "$k"; done)"
  [[ -n "$extra" ]] && detail+="
    PRESENT ONLY IN THE DEPLOYMENT (a local addition, or the OLD SPELLING of a renamed key —
    indistinguishable from here, so decide by reading the template's history):
$(for k in $extra; do printf '      %s\n' "$k"; done)"
  detail+="
    Add the missing names by hand and CHOOSE each value. This script deliberately does not write
    them: see scripts/lib/env-drift.sh for the measurement of why there is no safe filler — for
    these keys 'present but empty' and 'absent' behave differently, in both directions, and the
    templates' own values are placeholders on a domain you do not own."
  drift "$detail"
done

# ------------------------------------------------------------------------------------------------
# 3. Secrets. Files, never environment variables.
# ------------------------------------------------------------------------------------------------
# `docker compose config` renders every interpolated value in full, so a password reachable through
# ${...} is a password in the CI log of whatever prints the rendered configuration. Compose secrets
# mount at /run/secrets/<name> and appear in no config dump and no `docker inspect`.
say "Secrets"
mkdir -p "$SECRETS_DIR"
chmod 700 "$SECRETS_DIR"

gen_secret() {
  local name="$1" bytes="${2:-32}" path="$SECRETS_DIR/$1"
  if [[ -s "$path" ]]; then
    echo "    $name exists — NOT regenerating"
    return
  fi
  openssl rand -base64 "$bytes" | tr -d '\n' > "$path"
  chmod 600 "$path"
  echo "    generated $name"
}

gen_secret kek 32

# ------------------------------------------------------------------------------------------------
# APP_KEY — Laravel's application key. ITS OWN GENERATOR, because the format is not generic.
# ------------------------------------------------------------------------------------------------
# `gen_secret` writes bare base64. Laravel needs the literal prefix `base64:` in front of it, and
# exactly 32 decoded bytes for AES-256-CBC. A bare random string is accepted by config() and by
# every boot; it throws "unsupported cipher or incorrect key length" at the FIRST DECRYPT — which
# reaches a human as a login loop, not as a configuration error. Hence a separate path rather than
# a reused gen_secret call.
#
# ============================================================================================
# NEVER REGENERATE app_key ON A DEPLOYMENT THAT HAS EVER STORED ANYTHING.
# ============================================================================================
# The "exists — NOT regenerating" guard below is not tidiness; it is the only thing standing
# between a re-run of this script and unrecoverable data loss. APP_KEY decrypts every `encrypted:`
# column — the wrapped provider DEKs among them — plus every session and cookie, and it is the HMAC
# key behind the audit `key_fingerprint`. Replace it without first appending the outgoing value to
# app_previous_keys and that data is gone: no restore, no failover and no rollback recovers it,
# because the ciphertext is intact and the only key that opens it no longer exists. A well-meant
# `rm secrets/app_key` here is not an outage.
#
# The supported rotation is three steps, in this order:
#   1. append the CURRENT app_key to secrets/app_previous_keys (comma-separated if not the first)
#   2. write the new key to secrets/app_key
#   3. deploy, then re-encrypt every `encrypted:` column so the old key can eventually be retired
gen_app_key() {
  local path="$SECRETS_DIR/app_key"
  if [[ -s "$path" ]]; then
    echo "    app_key exists — NOT regenerating (rotating it without app_previous_keys is permanent data loss)"
    return
  fi
  printf 'base64:%s' "$(openssl rand -base64 32)" > "$path"
  chmod 600 "$path"
  echo "    generated app_key (base64: + 32 bytes)"
}
gen_app_key

# app_previous_keys is created EMPTY, the same way alertmanager_webhook_url is — and for the
# opposite reason. An unrotated deployment genuinely has no previous keys, and an empty FILE is how
# "none" is spelled. Writing anything here (a comma, a newline, a placeholder) hands Laravel one
# EMPTY key to attempt on every decrypt failure. It is declared as a secret rather than left absent
# because Docker turns a missing `file:` source into a DIRECTORY, and a directory at
# /run/secrets/app_previous_keys is not readable as "no keys".
#
# NOTE FOR scripts/ops/preflight.sh: this file being zero bytes is the CORRECT state and must not
# be reported as an empty-secret or placeholder failure. preflight exempts it by name.
if [[ ! -f "$SECRETS_DIR/app_previous_keys" ]]; then
  : > "$SECRETS_DIR/app_previous_keys"
  chmod 600 "$SECRETS_DIR/app_previous_keys"
  echo "    created app_previous_keys (EMPTY — correct until the first APP_KEY rotation)"
fi

# ------------------------------------------------------------------------------------------------
# Internal signing keys — FOUR of them, named by KEY ID, all independently generated.
# ------------------------------------------------------------------------------------------------
# BOTH IDS IN A PAIR EXIST FROM DAY ONE. The verifier accepts every id it holds and the signer uses
# the active one, because the signer and the verifier deploy at different times — a single-key
# rotation is a guaranteed outage, and discovering that during the rotation is the wrong time.
#
# NAMED BY ID, NOT BY ROLE. The wire carries the id (`X-KB-Signature = key_id + ":" + hex(...)`),
# so `hmac_key_current` cannot tell a verifier which id it holds, and every rotation would have to
# rename the file — in lockstep with the one identifier that exists so it never has to change.
# Rotation here is: generate the next id's file, mount it, flip the active-id variable. Nothing is
# ever renamed. services/ai-service/app/core/keys.py derives the id FROM THE FILENAME, so these
# names are load-bearing, not decorative.
#
# TWO DIRECTIONS, DISJOINT SETS. k* signs Laravel -> FastAPI; c* signs FastAPI's callbacks back
# into Laravel. Each `gen_secret` call is its own `openssl rand` — never a transform of another
# key. Deriving c1 from k1 (hashing it, reversing it, appending to it) would mean a compromised
# outbound key yields the callback key in one operation, and forging a callback is exactly the
# capability the split exists to withhold.
gen_secret hmac_key_k1 32
gen_secret hmac_key_k2 32
gen_secret callback_hmac_key_c1 32
gen_secret callback_hmac_key_c2 32

# Pre-key-id installs have hmac_key_current / hmac_key_previous sitting in secrets/. They are no
# longer declared in compose, so they mount nowhere and are inert — but leaving them unmentioned
# invites someone to "restore" them. Say it once, and never touch them: they may still be the live
# keys on a running deployment, and deleting key material from under a running verifier is an
# outage this script is not entitled to cause.
for stale in hmac_key_current hmac_key_previous; do
  if [[ -e "$SECRETS_DIR/$stale" ]]; then
    warn "$stale is present but no longer declared in compose.yaml — the key-id files above"
    warn "  replace it. Roll the deployment onto k1/k2 first, THEN delete it by hand."
  fi
done

# THREE PostgreSQL passwords, one per role (D21). postgres_password is the POSTGRES_USER superuser —
# after the role split it is no longer a RUNTIME credential; only `postgres` itself and the
# `postgres-roles` one-shot mount it. The other two are the roles every container actually connects
# as: kb_migrate (NOSUPERUSER owner, laravel-migrate and the scheduler's DDL connection) and kb_app
# (NOSUPERUSER, owns nothing, everything else).
#
# WHY THE SPLIT AT ALL: `POSTGRES_USER` creates ONE role and makes it a SUPERUSER, and a superuser
# bypasses every ACL check. Measured on PostgreSQL 18.4 while everything connected as that one role:
# audit_logs' `REVOKE UPDATE, DELETE` landed correctly in `pg_class.relacl` on the parent and every
# partition, and `UPDATE audit_logs SET operation='tampered'` returned `UPDATE 1` anyway.
#
# `gen_secret` NEVER REGENERATES AN EXISTING FILE, and that guard is load-bearing here too: these two
# are the passwords of roles that already exist in the cluster, and this script cannot ALTER them.
# Rewriting the file would split disk from server — the container would then be handed a password the
# server has never been told, and the failure appears at the next recreate as `password
# authentication failed for user "kb_app"` from every service at once.
gen_secret postgres_password 24
gen_secret postgres_migrate_password 24
gen_secret postgres_app_password 24
gen_secret s3_secret_key 32
gen_secret qdrant_api_key 32

# The Alertmanager pager URL is a credential (anyone holding it can page, or can read the routing).
# It has no sensible generated default, so an empty placeholder is written and the observability
# profile is expected to fail loudly until someone fills it in.
if [[ ! -f "$SECRETS_DIR/alertmanager_webhook_url" ]]; then
  : > "$SECRETS_DIR/alertmanager_webhook_url"
  chmod 600 "$SECRETS_DIR/alertmanager_webhook_url"
  warn "alertmanager_webhook_url is EMPTY — the observability profile will not page until it is set."
fi

# ------------------------------------------------------------------------------------------------
# 4. Render the two credential files from their committed templates, then rewrite the placeholders.
# ------------------------------------------------------------------------------------------------
# Neither seaweedfs/identities.json nor valkey/users.acl is committed any more — each holds live
# credential material (a plaintext S3 secret key; three sha256 ACL password hashes) and each is
# gitignored. What IS committed is the `.example` template beside it, exactly as with env/*.env.
#
# THE COPY IS GUARDED ON ABSENCE, AND THAT GUARD IS THE WHOLE CONTRACT. Copying a template over an
# existing file would overwrite the credential a RUNNING server is authenticating, and neither
# server re-reads its file — SeaweedFS reads identities at gateway start, and nothing here can
# `ACL LOAD`. The damage would not appear until the next restart, for any reason at all, which is
# the disk-versus-server split this script exists to prevent. So: copy only when absent, then let
# the placeholder-rewrite logic below run. That logic was already idempotent, and both halves being
# absence-guarded keeps a second run of this script a no-op.
#
# What each file does when it is MISSING is the opposite of the folklore this script used to carry.
# Measured 2026-08-11 against the pinned tags, and recorded in identities.json.example:
#   * seaweedfs:4.40 — an absent bind source becomes a Docker-created DIRECTORY and the config load
#     is FATAL (exit 255); a zero-byte file is fatal too. It fails CLOSED. The Allow-All hazard is
#     real but is reached by dropping -s3.config or by a config parsing to zero identities, not by
#     an absent file.
#   * valkey:9.1.1 — an absent or zero-byte aclfile does NOT stop startup. The server comes up with
#     `user default on nopass ~* &* +@all` and takes unauthenticated writes. It fails OPEN. This is
#     the one to be careful with, and it is why the copy below happens before `docker compose up`.
say "Rendering credential files from their templates"

# THE MODES DIFFER, AND 0600 ON THE WRONG ONE IS A BOOT FAILURE. Both mounts are :ro bind mounts,
# so the file must be readable by whatever uid the container process ends up as — which is not the
# same answer for the two images (measured 2026-08-11):
#
#   seaweedfs  the `weed` process runs as ROOT, so 0600 owned by the host user is readable.
#              This file holds a PLAINTEXT S3 secret key, so it gets the tighter mode.
#   valkey     the image entrypoint drops privileges to uid 999 (`valkey`) before exec'ing
#              valkey-server. A 0600 file owned by host uid 1000 is then unreadable and the server
#              ABORTS: "Error loading ACLs, opening file '/etc/valkey/users.acl': Permission
#              denied". That is at least a loud failure rather than the silent fail-open an ABSENT
#              file produces — but it is still a stack that will not boot, so this one stays 0644.
#              It holds sha256 hashes, not plaintexts; the plaintexts are in secrets/ at 0600.
render_credential_file() {
  local example="$1" mode="$2" target="${1%.example}"
  if [[ ! -f "$DOCKER_DIR/$example" ]]; then
    die "$example is missing. It is a COMMITTED template; restore it with
      git checkout -- infrastructure/docker/$example"
  fi
  if [[ -e "$DOCKER_DIR/$target" ]]; then
    # -e then -f: a DIRECTORY here is the Docker-created-bind-source failure, and it must be
    # reported rather than left for `cp` to fail on obscurely.
    if [[ ! -f "$DOCKER_DIR/$target" ]]; then
      die "$target exists but is not a regular file. A directory at this path is what Docker
      creates when a bind source is missing and the stack was started before this script ran.
      Remove it — with the stack DOWN — and re-run."
    fi
    # NEVER copy over it. It may be the credential a running server is authenticating, and neither
    # server re-reads its file, so the damage would surface at the next restart instead of here.
    echo "    $target exists — leaving it alone (it may be the live credential)"
  else
    cp "$DOCKER_DIR/$example" "$DOCKER_DIR/$target"
    chmod "$mode" "$DOCKER_DIR/$target"
    echo "    rendered $target from its template, mode $mode (placeholders rewritten below)"
  fi
}

render_credential_file seaweedfs/identities.json.example 600
render_credential_file valkey/users.acl.example 644

# ------------------------------------------------------------------------------------------------
# THE SAME SKIP-IF-EXISTS GAP APPLIES HERE, AND HERE IT IS A GRANT THAT OUTLIVES ITS REMOVAL.
# ------------------------------------------------------------------------------------------------
# env/*.env drift means a container misses a variable. Drift in THESE two files means an IDENTITY or
# an ACL USER exists on one side and not the other — and the dangerous direction is the reverse of
# the env case. A principal DELETED from the template stays live in the rendered file forever,
# because nothing here removes anything.
#
# MEASURED on the development deployment 2026-08-13: identities.json still declares `kb-backup`,
# which was deliberately removed from the template on 2026-08-11 (see the comment above the python
# block below). It grants Read+List over the whole bucket, it has no consumer in compose.yaml,
# preflight.sh or backup.sh, and — this is the part that matters — its secretKey was only ever
# written into the rendered file, with no secrets/ twin, so a credential sweep over secrets/ cannot
# see it. Removing it from the template did not remove it from the deployment and nothing said so.
#
# NAMES ONLY, and reported rather than repaired: this script is not entitled to delete a credential
# a running gateway may be authenticating.
say "Credential-file principals vs their templates"

# Names of the JSON identities, one per line. python3 is already a hard dependency of this script.
ident_names() {
  python3 -c 'import json,sys
try:
    d = json.load(open(sys.argv[1]))
except Exception:
    sys.exit(0)
for i in d.get("identities", []):
    n = i.get("name")
    if n:
        print(n)' "$1" | sort -u
}

# `user <name> ...` lines. `default` is included on purpose — `user default off` is a load-bearing
# line and its disappearance from the rendered file is exactly the fail-open ADR-037 measured.
acl_user_names() {
  sed -nE 's/^user[[:space:]]+([^[:space:]]+).*/\1/p' "$1" | sort -u
}

check_principals() {
  local label="$1" target="$2" example="$3" lister="$4"
  [[ -f "$target" && -f "$example" ]] || return 0

  local gone new
  gone="$("$lister" "$example" | comm -23 - <("$lister" "$target") | tr '\n' ' ')"
  new="$("$lister" "$target" | comm -13 <("$lister" "$example") - | tr '\n' ' ')"
  gone="${gone% }"; new="${new% }"

  if [[ -z "$gone" && -z "$new" ]]; then
    echo "    $label: principals match the template"
    return 0
  fi

  # Paths relative to DOCKER_DIR: the report is read next to `cd infrastructure/docker`, and an
  # absolute path here wraps the heading line and hides the filename it exists to name.
  local detail="${target#"$DOCKER_DIR"/}  <-  ${example#"$DOCKER_DIR"/}"
  if [[ -n "$new" ]]; then
    warn "$label declares principals the template does NOT: $new"
    detail+="
    PRESENT IN THE DEPLOYMENT, ABSENT FROM THE TEMPLATE: $new
      A principal removed from the template is still LIVE here — a grant that outlived its own
      deletion. Verify it against the template's history before removing it, then remove it with
      the stack DOWN. Do NOT hand-edit the rendered file if you can avoid it: for identities.json
      the supported repair is to delete the file and re-run this script, which re-renders from the
      template and rewrites the placeholder secretKey from secrets/s3_secret_key — the SAME value
      the running gateway already holds, so the credential does not change."
  fi
  if [[ -n "$gone" ]]; then
    warn "$label is MISSING principals its template declares: $gone"
    detail+="
    IN THE TEMPLATE, ABSENT FROM THE DEPLOYMENT: $gone
      For valkey/users.acl a missing user line is NOT a startup error: the server comes up without
      that user and whichever service holds its password gets WRONGPASS, with no degraded mode
      because \`user default off\`. For identities.json it means a consumer's credential simply does
      not exist and every request it makes is refused."
  fi
  drift "$detail"
}

check_principals "seaweedfs/identities.json" \
  "$DOCKER_DIR/seaweedfs/identities.json" \
  "$DOCKER_DIR/seaweedfs/identities.json.example" ident_names
check_principals "valkey/users.acl" \
  "$DOCKER_DIR/valkey/users.acl" \
  "$DOCKER_DIR/valkey/users.acl.example" acl_user_names

say "Rewriting placeholder development credentials"

S3_SECRET="$(cat "$SECRETS_DIR/s3_secret_key")"
# ONE identity, ONE secret, and that secret has a twin in secrets/s3_secret_key so a credential
# sweep can find it. This block used to generate a second, independent key for a `kb-backup`
# identity. That identity was removed on 2026-08-11: it granted Read+List over the whole bucket,
# it appeared in zero lines of compose.yaml, preflight.sh and backup.sh, and backup.sh does not
# use the S3 API at all — it stops the gateway and tars the volume. Its secret was written ONLY
# here, into the rendered identities.json, with no secrets/ twin, which made it the one credential
# in this repository that a sweep over secrets/ could not see. See the __notes in
# seaweedfs/identities.json.example before re-adding any identity: it must arrive with a consumer,
# a secrets/ twin, and an update to preflight.sh's identity-set assertion.
python3 - "$DOCKER_DIR/seaweedfs/identities.json" "$S3_SECRET" <<'PY'
import json, sys
path, secret = sys.argv[1], sys.argv[2]
doc = json.load(open(path))
changed = False
unexpected = []
for ident in doc["identities"]:
    if ident.get("name") != "kb-app":
        # Do NOT invent a key for an identity this script does not know about. Silently
        # provisioning a working credential for a name nobody declared is how the kb-backup grant
        # stayed invisible for a week. Report it and let preflight.sh fail the deploy.
        unexpected.append(ident.get("name"))
        continue
    for cred in ident["credentials"]:
        if "CHANGE-ME" in cred["secretKey"]:
            cred["secretKey"] = secret
            changed = True
json.dump(doc, open(path, "w"), indent=2)
open(path, "a").write("\n")
print("    identities.json: " + ("secretKey rewritten" if changed else "already rewritten — untouched"))
if unexpected:
    print("    WARNING: identities.json declares identities this script does not provision: "
          + ", ".join(str(n) for n in unexpected))
    print("    They keep whatever secretKey is in the file. scripts/ops/preflight.sh fails on them.")
PY

# ACL passwords are stored as sha256 hex. `>plaintext` in a committed aclfile is a plaintext
# credential in git.
#
# THIS RUNS PER USER, NOT ONCE FOR THE FILE. The previous version gated the whole block on
# `grep -q '#0{64}'` and then regenerated all three credentials, which made two states unreachable
# and one actively destructive:
#
#   * PARTIAL PLACEHOLDERS. One user reverted to the template and two still live: the grep matched,
#     and all three passwords were regenerated — including the two that a running Valkey was still
#     authenticating.
#   * DRIFT. The hash in users.acl and the sha256 of the plaintext in secrets/ disagree. The grep
#     did NOT match, the script reported "already rewritten — untouched", and nothing looked wrong.
#     Nothing IS wrong until Valkey restarts, because the server's rules are whatever the file said
#     at container start and NOTHING here can `ACL LOAD` (no user has +acl|load — valkey/README.md).
#     The next `docker compose restart valkey-core`, for any reason at all, then hands WRONGPASS to
#     every service at once; `user default off` means there is no degraded mode. Observed live.
#
# The distinction that matters: a placeholder next to an EXISTING plaintext is repaired by
# re-deriving the hash, never by minting a new password. Regenerating there would destroy a
# credential the running stack is still using.
acl_hash() { printf '%s' "$1" | openssl dgst -sha256 -r | cut -d' ' -f1; }
ACL_FILE="$DOCKER_DIR/valkey/users.acl"
ZEROS='0000000000000000000000000000000000000000000000000000000000000000'

# The #<sha256> currently on that user's line, or empty if the user or hash is absent.
acl_hash_of_user() {
  sed -nE "s/^user $1 on #([0-9a-f]{64}) .*/\1/p" "$ACL_FILE" | head -1
}

# Replace the hash on ONE user's line. Anchored to `user <name> on #`, so it cannot touch a
# neighbour and does not depend on the users' order in the file.
acl_set_hash_of_user() {
  perl -pi -e "s/^(user \Q$1\E on #)[0-9a-f]{64}\b/\${1}$2/" "$ACL_FILE"
}

acl_changed=0
# Separate from acl_changed: a DRIFT or missing-plaintext finding changes nothing but must not be
# followed by an "all consistent" line. That reassurance is the failure mode being fixed here.
acl_findings=0
for user in kb-core kb-ai kb-observer; do
  f="$SECRETS_DIR/valkey_${user//-/_}_password"
  cur="$(acl_hash_of_user "$user")"

  if [[ -z "$cur" ]]; then
    warn "$user has no #<sha256> on its line in valkey/users.acl (or the line is missing)."
    warn "  A MALFORMED line is fatal at startup, but a MISSING user line is not — Valkey starts"
    warn "  happily without that user, and the service holding its password gets WRONGPASS. Do not"
    warn "  hand-edit: delete users.acl (stack DOWN) and re-run this script to re-render it from"
    warn "  valkey/users.acl.example, then re-provision the three passwords."
    acl_findings=1
    continue
  fi

  if [[ "$cur" == "$ZEROS" ]]; then
    if [[ -s "$f" ]]; then
      # Placeholder in the file, real plaintext on disk: re-derive, do NOT regenerate. This is the
      # repair for a users.acl reverted to the template while the stack is up.
      acl_set_hash_of_user "$user" "$(acl_hash "$(cat "$f")")"
      acl_changed=1
      warn "$user: users.acl held the placeholder hash but $(basename "$f") exists."
      warn "  Re-derived the hash from that plaintext instead of minting a new password — the"
      warn "  running Valkey may still be authenticating it. NOTE: the file now differs from what"
      warn "  the server loaded at start; that is the point, but see valkey/README.md before"
      warn "  restarting Valkey."
    else
      pw="$(openssl rand -base64 24 | tr -d '\n')"
      printf '%s' "$pw" > "$f"
      chmod 600 "$f"
      acl_set_hash_of_user "$user" "$(acl_hash "$pw")"
      acl_changed=1
      echo "    users.acl: $user password generated (plaintext in secrets/)"
    fi
    continue
  fi

  # A real hash is present. Both halves must exist AND agree.
  if [[ ! -s "$f" ]]; then
    warn "$(basename "$f") is missing or empty, but users.acl already holds a rewritten hash."
    warn "  Only the plaintext half is regenerable, and it is the half that is gone. Regenerate"
    warn "  BOTH together: write a new password to that file and replace $user's #<sha256> with"
    warn "  its sha256. (Full reset, with the stack DOWN: delete users.acl and the three"
    warn "  valkey_*_password files, then re-run this script — it re-renders users.acl from"
    warn "  valkey/users.acl.example and mints all three afresh.)"
    acl_findings=1
  elif [[ "$(acl_hash "$(cat "$f")")" != "$cur" ]]; then
    warn "DRIFT: $user's hash in valkey/users.acl is not the sha256 of $(basename "$f")."
    warn "  Nothing will fail until Valkey restarts. Then this user gets WRONGPASS and, because"
    warn "  \`user default off\`, cannot connect at all — for Laravel that is queues, sessions,"
    warn "  cache and Horizon in one second."
    warn "  Decide which half is authoritative and make the other match. If the RUNNING server"
    warn "  accepts the plaintext (test it: valkey-cli --user $user --pass \"\$(cat $f)\" PING),"
    warn "  the plaintext is authoritative — re-derive the hash into users.acl."
    warn "  Verify any candidate file against a THROWAWAY server before restarting the real one;"
    warn "  the recipe is in infrastructure/docker/valkey/README.md."
    acl_findings=1
  fi
done
if [[ $acl_changed -eq 0 && $acl_findings -eq 0 ]]; then
  echo "    users.acl: hashes present and consistent with secrets/ — untouched"
fi

# ------------------------------------------------------------------------------------------------
# 5. Start
# ------------------------------------------------------------------------------------------------
# A bare `docker compose up` — no -f — is CORRECT here and only here: it auto-loads
# compose.override.yaml, which is what dev wants. `make deploy` is the production path and passes
# explicit files precisely so it cannot land on this one.
# THE GATE. Everything above this line has run: the secrets exist, users.acl is rendered so Valkey
# cannot come up as `nopass`, and identities.json is rendered so the S3 gateway is not Allow-All.
# What is withheld is only the `up`.
if [[ "$DRIFT_FOUND" -eq 1 ]]; then
  if [[ "${KB_BOOTSTRAP_IGNORE_ENV_DRIFT:-0}" == "1" ]]; then
    warn "KB_BOOTSTRAP_IGNORE_ENV_DRIFT=1 — starting the stack DESPITE the drift reported above."
    warn "  The full report is repeated at the end of this run. If SANCTUM_STATEFUL_DOMAINS is one"
    warn "  of the missing names, every login will answer 500 and no cookie will authenticate"
    warn "  anything; that is not a bug you will find by reading logs."
  else
    printf '\n\033[31m%s\033[0m\n' "==> NOT STARTING THE STACK — rendered configuration has drifted from its templates."
    for entry in "${DRIFT_REPORT[@]}"; do
      printf '\n\033[31m  %s\033[0m\n' "${entry%%$'\n'*}"
      printf '%s\n' "${entry#*$'\n'}"
    done
    cat >&2 <<'EOF'

  WHY THIS IS A REFUSAL AND NOT A WARNING
  ---------------------------------------
  Every step whose omission is dangerous has already run: the secrets are generated, valkey/users.acl
  is rendered (an absent one starts Valkey as `user default on nopass` and takes unauthenticated
  writes), and seaweedfs/identities.json is rendered. Only `docker compose up` is withheld, and a
  stack started with a key missing is the failure this refusal exists to prevent — it comes up
  green. SANCTUM_STATEFUL_DOMAINS absent is the worst case and it is not subtle in effect, only in
  appearance: config/sanctum.php fails closed to [], EnsureFrontendRequestsAreStateful classifies
  every request third-party, EncryptCookies / StartSession / PreventRequestForgery /
  AuthenticateSession never run, and login reaches session()->regenerate() with no session bound —
  an unauthenticated 500, with no CSRF check and no way to invalidate sibling sessions.

  On a FIRST run this cannot fire: the files are created from the templates and match by
  construction. It fires only on a re-run against an existing deployment, which is the case the
  skip-if-exists guard was leaving unreported.

  WHAT TO DO
  ----------
    1. Add each MISSING name to the rendered file by hand and choose its value. Do not paste the
       template's — those are placeholders on a domain you do not own, and for FRONTEND_URL that
       means emailing password-reset links there.
    2. For each EXTRA name, decide whether it is a local addition or the old spelling of something
       the template renamed, and delete it if it is the latter.
    3. Re-run this script.

  If you have read the report and want the stack anyway:
       KB_BOOTSTRAP_IGNORE_ENV_DRIFT=1 scripts/dev/bootstrap.sh
EOF
    exit 1
  fi
fi

# ------------------------------------------------------------------------------------------------
# 5b. LOCALLY-TRUSTED TLS. Before the stack starts, because compose.override.yaml MOUNTS the output.
# ------------------------------------------------------------------------------------------------
# Run here rather than left to the operator because the dev overlay bind-mounts
# `traefik/certs/dev-tls.yaml`, and Docker's behaviour for a bind-mount whose source is missing is to
# CREATE IT AS A DIRECTORY — after which Traefik cannot parse its own dynamic configuration. Running
# the generator first means the file always exists as a file.
#
# It is idempotent and cheap: the CA is reused whenever it already exists (regenerating it would
# silently invalidate the trust the operator established in their OS store), and only the leaf is
# reissued. It prints the host-trust instructions every time, which is deliberate — that step is
# manual, per-machine, and the one people forget.
say "Development TLS certificate"
if ! "$REPO_ROOT/scripts/dev/tls-dev-cert.sh"; then
  warn "Could not issue the development certificate. The stack will still start, but Traefik will"
  warn "  serve 'CN = TRAEFIK DEFAULT CERT' and every browser fetch from app.<domain> to"
  warn "  api.<domain> will fail with ERR_CERT_AUTHORITY_INVALID — with no interstitial to click"
  warn "  through, because a subresource request never gets one. Fix it before using the console:"
  warn "    scripts/dev/tls-dev-cert.sh"
fi

say "Starting the stack (dev overlay auto-loaded)"
# 900s is NOT sized against ai-api any more. That container loads no model (ADR-030) and its dev
# start_period is 90s. What the budget actually covers on a first boot is the serial dependency
# chain and the one-time work at the end of it: postgres healthy -> laravel-migrate runs the full
# migration set to completion -> the ai-* workers (start_period 120s, the longest in the stack) ->
# `next dev`'s first compile in `web` against a cold pnpm store. Shrink this only after timing
# those, not by reasoning about ai-api.
docker compose up -d --wait --wait-timeout 900 || {
  warn "Some services did not become healthy inside 900s."
  warn "Usual first-boot causes, in order: laravel-migrate still running (everything else gates on"
  warn "  it), a worker's 120s start_period plus its first broker connection, or web's first"
  warn "  \`next dev\` compile. ai-api is NOT a model load — it starts in seconds, so if it is the"
  warn "  one still red it is genuinely broken, not slow."
  warn "Check: docker compose ps && docker compose logs ai-api"
}

# ------------------------------------------------------------------------------------------------
# 6. VERIFY. This is the part that is not optional.
# ------------------------------------------------------------------------------------------------
say "Verifying the object store rejects anonymous access"
# An anonymous ListBuckets MUST be refused. If it succeeds, the gateway is in Allow-All mode — a
# wide-open object store that started cleanly. Note what that does NOT mean (ADR-037): a wrong or
# unreadable -s3.config path is fatal, so the container would be crash-looping and this exec would
# fail instead. A 200 here means the flag was dropped, or the config parses to zero identities.
status="$(docker compose exec -T seaweedfs \
  wget -q -S -O /dev/null 'http://127.0.0.1:8333/' 2>&1 | awk '/HTTP\//{print $2}' | tail -1 || true)"

case "$status" in
  403|401)
    echo "    anonymous ListBuckets -> $status. Gateway is closed."
    ;;
  200)
    die "anonymous ListBuckets returned 200 — THE S3 GATEWAY IS IN ALLOW-ALL MODE.
      Every object in every organization is readable and deletable without credentials.
      There are exactly two ways to reach this state (measured against seaweedfs:4.40, ADR-037):
        1. -s3.config was dropped from the compose command. Check it:
               docker compose exec seaweedfs ps -o args
        2. the config PARSES but declares zero identities — '{}' or '\"identities\": []'. Check it:
               docker compose exec seaweedfs head -c 400 /etc/s3/identities.json
      A missing, directory-shaped or zero-byte config is NOT one of them: that is a fatal load
      (exit 255) and the container would be crash-looping instead of answering. So if someone
      recently silenced a seaweedfs crash-loop by writing '{}' into that file, this is the result —
      restore it from identities.json.example and re-run this script."
    ;;
  *)
    warn "could not determine the ListBuckets status (got '${status:-nothing}')."
    warn "DO NOT SKIP THIS. Re-run once seaweedfs is healthy:"
    warn "    aws --endpoint-url http://127.0.0.1:8333 --no-sign-request s3 ls   # expect AccessDenied"
    ;;
esac

if [[ "$DRIFT_FOUND" -eq 1 ]]; then
  # Reached only under KB_BOOTSTRAP_IGNORE_ENV_DRIFT=1, or when the drift is credential-principal
  # drift that this script reports without blocking on. Repeated at the very END on purpose: the
  # `docker compose up --wait` above emits a hundred lines, and a warning printed before them has
  # been scrolled off the screen by the time the operator looks.
  printf '\n\033[31m%s\033[0m\n' "==> THE STACK IS UP AND ITS CONFIGURATION HAS DRIFTED FROM ITS TEMPLATES."
  for entry in "${DRIFT_REPORT[@]}"; do
    printf '\n\033[31m  %s\033[0m\n' "${entry%%$'\n'*}"
    printf '%s\n' "${entry#*$'\n'}"
  done
  printf '\n\033[31m%s\033[0m\n' "    Exiting 1. Nothing was written on your behalf; reconcile the files listed above."
  exit 1
fi

say "Done"
cat <<'EOF'
    Next:
      make ps                 what is running
      make logs               follow everything
      cat scripts/dev/hosts.md    resolve the four hostnames locally

    Mailpit IS started by default and catches every outgoing mail: http://127.0.0.1:8025/
      Every password-reset, verification and invitation link lands there, not in a real inbox.

    NOT started by default (each is a profile):
      make obs-up             Prometheus/Alertmanager/Loki/Tempo/Grafana
      docker compose --profile dev-tools up -d adminer     database UI
      make test-up            ephemeral test databases + the crawl fixture origin
EOF
