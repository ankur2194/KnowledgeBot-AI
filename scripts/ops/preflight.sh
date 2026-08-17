#!/usr/bin/env bash
# scripts/ops/preflight.sh — the gate in front of `make deploy`.
#
# ================================================================================================
# THIS SCRIPT EXISTS BECAUSE THE WORST FAILURES IN THIS STACK ARE SILENT — AND BECAUSE WHICH
# SERVICE FAILS WHICH WAY IS THE OPPOSITE OF THIS FILE'S ORIGINAL BELIEF. SEE ADR-037 (docs/19).
# ================================================================================================
# `make deploy` used to be one line: `docker compose -f compose.yaml -f compose.prod.yaml up -d`.
# On a fresh clone that command succeeds, `docker compose ps` is green, and the deployment is
# either wide open or crash-looping. Neither state produces a useful error, and the two services
# involved fail in OPPOSITE directions — measured against the pinned tags on 2026-08-11, not
# reasoned from vendor documentation, which is what produced the inverted claim in the first place:
#
#   1. MISSING MOUNT SOURCES, WHICH DOCKER TURNS INTO DIRECTORIES. `infrastructure/docker/secrets/`
#      ships holding only .gitkeep, and compose declares its `secrets:` entries with `file:`
#      sources inside it. Docker CREATES A DIRECTORY at a missing bind source rather than failing.
#      What happens next differs per consumer, and the difference is the whole point:
#
#        * seaweedfs — `-s3.config=/etc/s3/identities.json` resolving to a directory, or to a
#          zero-byte file, is a FATAL config load: `fail to load config file ...: is a directory`,
#          then exit 255. The container CRASH-LOOPS. It FAILS CLOSED. Loud, and recoverable.
#        * valkey — `aclfile /etc/valkey/users.acl` absent or zero-byte STARTS CLEANLY as
#          `user default on nopass ~* &* +@all`. An unauthenticated `SET pwned 1` returns OK.
#          THIS is the silent wide-open one: every queue, session, lock, rate limiter and cache
#          entry of every tenant, unauthenticated. See section 7.
#
#      The SeaweedFS Allow-All state is real, but it has two OTHER triggers: dropping -s3.config
#      from the compose command, or a config that PARSES to `"identities": []`. Which means the
#      likeliest wrong move is the dangerous one: NEVER silence a seaweedfs crash-loop by writing
#      `{}` into identities.json. `{}` and `{"identities": []}` start cleanly and serve anonymous
#      ListBuckets with 200. The crash is the safe state; Allow-All is not.
#
#   2. UNPROVISIONED DEVELOPMENT CREDENTIALS. `seaweedfs/identities.json.example` ships with
#      CHANGE-ME secret keys and `valkey/users.acl.example` with `#0000…0000` password hashes, both
#      deliberately (see the headers of those two files). The rendered files they produce are
#      GITIGNORED and are written by `scripts/dev/bootstrap.sh` on a developer machine. NOTHING
#      guarantees bootstrap ran before a production deploy. A deployment whose S3 secret key is a
#      string published on GitHub is Allow-All with extra steps.
#
# A third, cheaper failure is in scope for the same reason: an unset ${DOMAIN} renders the Traefik
# router rule as Host(`api.`) — a syntactically valid hostname no client will ever send. The router
# is created, it is healthy, it appears in the dashboard, it matches nothing, and Traefik logs
# nothing because nothing is wrong.
#
# ------------------------------------------------------------------------------------------------
# RULES THIS SCRIPT OBEYS
# ------------------------------------------------------------------------------------------------
#   * IT NEVER ECHOES A SECRET VALUE. Not a prefix, not a fingerprint, not on failure. Everything
#     below reports by secret NAME and PATH only. `docker compose config` renders interpolated
#     values in full, so it is only ever invoked with --quiet and with stdout discarded.
#   * IT MUTATES NOTHING. Read-only, idempotent, safe to run standalone at any time, including
#     against a running production host.
#   * IT ACCUMULATES. Every check runs even after an earlier one fails, and all findings are
#     reported together at the end. A deploy blocked twice in a row for two different reasons
#     burns two maintenance windows.
#   * IT FAILS CLOSED. If a check cannot be performed — the parser finds no secrets, the compose
#     file cannot be read — that is a failure, not a skip. A preflight that silently checks nothing
#     is worse than no preflight, because it is believed.
#
# Usage:  scripts/ops/preflight.sh          (or: make preflight)
# Exit:   0 = clean, non-zero = do not deploy.

set -euo pipefail

REPO_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$REPO_ROOT/infrastructure/docker"
SECRETS_DIR="$DOCKER_DIR/secrets"
ENV_FILE="$DOCKER_DIR/.env"
ENV_EXAMPLE="$DOCKER_DIR/.env.example"

say()  { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[33m    %s\033[0m\n' "$*"; }
ok()   { printf '    %s\n' "$*"; }
die()  { printf '\033[31mFATAL %s\033[0m\n' "$*" >&2; exit 1; }

# The template-vs-rendered name-set comparison used by §2b, shared with scripts/dev/bootstrap.sh.
# ONE implementation: a drift checker that exists in two copies is the drift it was written to catch.
# shellcheck source=../lib/env-drift.sh
. "$REPO_ROOT/scripts/lib/env-drift.sh"

# fail <one-line title> <explanation…>
#
# Prints a marker in context so the operator sees WHERE it broke, and stores the full explanation
# for the summary. `die` is reserved for the structural cases where no further check is meaningful.
FAILURES=()
fail() {
  local title="$1"; shift
  printf '\033[31m    FAIL  %s\033[0m\n' "$title"
  FAILURES+=("$title"$'\n'"$(printf '%s\n' "$@")")
}

# ================================================================================================
# 0. Tooling. Nothing below can be checked without these.
# ================================================================================================
command -v docker >/dev/null 2>&1 || die "docker not found — this host is about to deploy with it"
docker compose version >/dev/null 2>&1 || die "docker compose not found (this stack targets v5)"
[[ -f "$DOCKER_DIR/compose.yaml" ]]      || die "missing $DOCKER_DIR/compose.yaml"
[[ -f "$DOCKER_DIR/compose.prod.yaml" ]] || die "missing $DOCKER_DIR/compose.prod.yaml"

say "Preflight for $DOCKER_DIR"

# ------------------------------------------------------------------------------------------------
# Helpers
# ------------------------------------------------------------------------------------------------

# Permission bits as an octal string. GNU first, BSD second; anything else and we fail closed
# rather than assume 600.
mode_of() { stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1" 2>/dev/null; }

# True when the mode has ANY group or other bit set. `chmod 640` on a secret is not a smaller
# problem than `chmod 644` — both mean a second uid on the host can read the KEK.
looser_than_owner_only() {
  local m="$1"
  [[ "$m" =~ ^[0-7]+$ ]] || return 0     # unparseable mode: treat as loose, fail closed
  (( 8#$m & 8#077 ))
}

# Read one value out of a .env-style file WITHOUT sourcing it. Sourcing runs whatever is in there,
# and would also clobber this script's own variables. Last assignment wins, which is how Compose
# reads it. Strips surrounding quotes and trailing whitespace.
env_value() {
  local file="$1" key="$2"
  [[ -f "$file" ]] || return 0
  sed -nE "s/^[[:space:]]*(export[[:space:]]+)?${key}=//p" "$file" \
    | tail -1 \
    | sed -E 's/[[:space:]]+$//; s/^"(.*)"$/\1/; s/^'"'"'(.*)'"'"'$/\1/'
}

# eTLD+1, approximately. The Public Suffix List is the correct answer and is not available on a
# deploy host with no network, so this handles the common two-part suffixes explicitly and takes
# the last two labels otherwise. It is used only to REFUSE an obviously-shared registrable domain;
# an exotic suffix it gets wrong (e.g. a private PSL entry) would under-report, never over-report.
registrable_domain() {
  printf '%s\n' "$1" | tr '[:upper:]' '[:lower:]' | awk -F. '
    { n = NF
      if (n < 2) { print $0; next }
      two = $(n-1) "." $n
      if (n >= 3 && two ~ /^(co|com|net|org|gov|edu|ac|or|ne|in|geek)\.[a-z][a-z]$/)
           print $(n-2) "." two
      else print two
    }'
}

# `docker compose config` prints interpolated values in full; even its ERROR output can quote a
# line of a rendered file. Everything that could be a credential is masked before it reaches the
# terminal or a CI log.
redact() {
  sed -E 's/([A-Za-z0-9_]*(PASS|PASSWORD|SECRET|KEY|TOKEN|URL|DSN|pass|password|secret|key|token|url|dsn)[A-Za-z0-9_]*["]?[[:space:]]*[=:][[:space:]]*)[^[:space:],}]+/\1<redacted>/g'
}

# ================================================================================================
# 1. The declared secret list — DERIVED from the compose files, never hardcoded here.
# ================================================================================================
# A second copy of the list in this script would drift the moment someone adds an eighth secret,
# and it would drift in the direction that matters: the new secret would be the unchecked one.
# Both files are parsed, because the production overlay is allowed to declare its own.
#
# Parsed textually rather than with `docker compose config`, on purpose. This script has to work
# on exactly the trees where `config` cannot render — no .env, unset digests — which is most of
# the trees it exists to reject.
declared_secret_names() {
  awk '
    FNR == 1        { insec = 0 }
    /^secrets:[[:space:]]*$/ { insec = 1; next }
    insec && /^[^[:space:]#]/ { insec = 0 }
    insec && /^  [A-Za-z0-9_.-]+:/ {
      n = $0; sub(/^[[:space:]]+/, "", n); sub(/:.*/, "", n); print n
    }
  ' "$@" | awk '!seen[$0]++'
}

declared_secret_files() {
  awk '
    FNR == 1        { insec = 0; name = "" }
    /^secrets:[[:space:]]*$/ { insec = 1; next }
    insec && /^[^[:space:]#]/ { insec = 0 }
    insec && /^  [A-Za-z0-9_.-]+:/ {
      name = $0; sub(/^[[:space:]]+/, "", name); sub(/:.*/, "", name)
    }
    insec && /file:[[:space:]]*/ {
      p = $0
      sub(/^.*file:[[:space:]]*/, "", p)
      sub(/[},].*$/, "", p)
      gsub(/["'"'"']/, "", p)
      sub(/[[:space:]]+$/, "", p)
      if (name != "" && p != "") { print name "\t" p; name = "" }
    }
  ' "$@" | awk '!seen[$1]++'
}

say "Declared secrets (from compose.yaml + compose.prod.yaml)"
SECRET_NAMES="$(declared_secret_names "$DOCKER_DIR/compose.yaml" "$DOCKER_DIR/compose.prod.yaml")"
SECRET_FILES="$(declared_secret_files "$DOCKER_DIR/compose.yaml" "$DOCKER_DIR/compose.prod.yaml")"
n_names=$(printf '%s' "$SECRET_NAMES" | grep -c . || true)
n_files=$(printf '%s' "$SECRET_FILES" | grep -c . || true)

# Fail closed on the parser itself. A preflight that found zero secrets would sail past every
# check below and report a clean tree — the exact false green this script exists to prevent.
[[ "$n_names" -gt 0 ]] || die "parsed 0 secrets from the compose files.
      The top-level \`secrets:\` block moved, was renamed, or changed indentation, and this
      script would otherwise pass a tree it has not checked. Fix the parser in
      declared_secret_names()/declared_secret_files() before deploying."
[[ "$n_names" -eq "$n_files" ]] || die "parsed $n_names secret names but only $n_files file sources.
      A declared secret has a non-file source (or an unrecognised layout), so preflight cannot
      inspect it. Every secret in this stack is a \`file:\` source by design — an \`environment:\`
      source puts the value in \`docker inspect\` and in \`docker compose config\` output."
ok "$n_names declared: $(printf '%s' "$SECRET_NAMES" | tr '\n' ' ')"

# ================================================================================================
# 2. `.env` — Compose INTERPOLATION, read from the project directory (infrastructure/docker).
# ================================================================================================
# Not the container environment (that is ./env/*.env via env_file:), and not a root-level .env,
# which would look canonical and never be read. With this file absent, EVERY ${...} in both compose
# files interpolates to the empty string. Compose warns once, on stderr, mixed into the pull
# output, and proceeds.
say "Compose interpolation file (.env)"
ENV_OK=0
if [[ ! -e "$ENV_FILE" ]]; then
  fail ".env is missing" \
    "  $ENV_FILE does not exist." \
    "" \
    "  Every \${...} in compose.yaml and compose.prod.yaml then interpolates to the EMPTY STRING." \
    "  The deploy does not fail: Traefik gets Host(\`api.\`) and matches nothing, image references" \
    "  lose their digests, and POSTGRES_DB/POSTGRES_USER become empty. Nothing errors." \
    "" \
    "  Create it:  cp $ENV_EXAMPLE $ENV_FILE  and then set DOMAIN, WIDGET_DOMAIN, ACME_EMAIL and" \
    "  every *_DIGEST. Note that .env is gitignored — it is per-deployment, not per-repository."
elif [[ ! -f "$ENV_FILE" ]]; then
  fail ".env is not a regular file" \
    "  $ENV_FILE exists but is not a regular file (a directory, most likely — Docker creates one" \
    "  at a missing bind source). Compose will read nothing out of it."
else
  ENV_OK=1
  ok ".env present"
  m="$(mode_of "$ENV_FILE")"
  # Not a hard failure: .env holds no credential by design (that is the whole point of
  # secrets:), but GRAFANA_ADMIN_PASSWORD lives here today, so a world-readable file is worth
  # saying out loud.
  if looser_than_owner_only "$m"; then
    warn ".env is mode $m — readable beyond its owner. It carries GRAFANA_ADMIN_PASSWORD; chmod 600."
  fi
fi

# ================================================================================================
# 2b. Container environment files (env/*.env) — RENDERED COPIES THAT GO STALE IN SILENCE.
# ================================================================================================
# A DIFFERENT FILE AND A DIFFERENT MECHANISM FROM THE .env ABOVE. `.env` is Compose's
# INTERPOLATION file; `env/*.env` are the CONTAINER environments delivered by `env_file:`. Both are
# gitignored and rendered from a committed `*.example`, and only the second one drifts, because
# `bootstrap.sh` deliberately refuses to clobber an existing copy:
#
#     if [[ -f "$target" ]]; then echo "    $(basename "$target") exists — leaving it alone"
#
# That refusal is right — the rendered file is where an operator's per-deployment edits live — but
# it means a template that gains, loses, or RENAMES a variable reaches a running deployment only if
# somebody re-renders by hand. Nothing warns. The rendered file stays valid, Compose stays happy,
# and the container keeps the old spelling.
#
# THIS IS NOT HYPOTHETICAL, and it is the reason this section exists. `env/ai-service.env.example`
# was corrected from `KB_ENV` to `KB_ENVIRONMENT` (the pydantic-settings field is `environment`);
# the rendered `env/ai-service.env` kept `KB_ENV`, and every ai-api and ai-worker-* container went
# on running as `local` while its own template said `production`. pydantic-settings resolves a
# field by looking up ITS OWN name and never enumerates the environment, so the old spelling was
# not rejected — it was never seen. `extra="forbid"` cannot catch it either.
#
# So compare NAMES, not values. Values are per-deployment by design and differ legitimately; the
# name set is the contract between the template and the code that reads it. And name-only
# comparison is what keeps this section inside the script's no-secret rule.
say "Container environment files (env/*.env)"
ENV_DIR="$DOCKER_DIR/env"
if [[ ! -d "$ENV_DIR" ]]; then
  fail "env/ directory is missing" \
    "  $ENV_DIR does not exist, so every \`env_file:\` target is absent and Compose will refuse" \
    "  to start the services that reference one. Run scripts/dev/bootstrap.sh."
else
  # The comparison itself lives in scripts/lib/env-drift.sh, sourced at the top of this script and
  # by scripts/dev/bootstrap.sh. IT USED TO BE INLINE HERE, and that was the bug shape this
  # repository keeps finding: the checker existed in one script, the script that renders the files
  # had no checker at all, and the five-key core-api gap sat in a live deployment for two days
  # because the only thing that would have caught it is run at deploy time and not at setup time.
  # kb_env_names / kb_env_missing / kb_env_extra are that primitive. Do not re-implement them here.
  shopt -s nullglob
  EXAMPLES=("$ENV_DIR"/*.env.example)
  shopt -u nullglob

  # FAIL CLOSED: finding no templates means the glob or the layout changed, not that everything
  # agrees. A check that silently checks nothing is worse than no check, because it is believed.
  if (( ${#EXAMPLES[@]} == 0 )); then
    fail "no env templates found in env/" \
      "  $ENV_DIR contains no *.env.example, so this check compared nothing at all." \
      "  Either the directory layout moved or the templates were deleted; both are deploy-blocking."
  fi

  for example in "${EXAMPLES[@]}"; do
    target="${example%.example}"
    base="$(basename "$target")"

    if [[ ! -f "$target" ]]; then
      fail "$base has not been rendered" \
        "  $target does not exist while $(basename "$example") does." \
        "" \
        "  Compose fails on a missing env_file, so this one is loud rather than silent — but it" \
        "  still blocks the deploy. Render it:  cp $example $target"
      continue
    fi

    missing="$(kb_env_missing "$example" "$target")"
    extra="$(kb_env_extra "$example" "$target")"

    if [[ -z "$missing" && -z "$extra" ]]; then
      ok "$base matches its template (variable names)"
      continue
    fi

    details=("  $target has drifted from $(basename "$example"). Names only; values are")
    details+=("  per-deployment and are neither compared nor printed.")
    details+=("")
    [[ -n "$missing" ]] && details+=(
      "  IN THE TEMPLATE, ABSENT FROM THE DEPLOYMENT: $missing"
      "    The container never receives these. If one is the new spelling of a variable still"
      "    present below, the service is reading a DEFAULT while both files look correct."
      ""
    )
    [[ -n "$extra" ]] && details+=(
      "  IN THE DEPLOYMENT, ABSENT FROM THE TEMPLATE: $extra"
      "    Either a local addition, or the old spelling of something renamed in the template."
      "    For ai-service these are now fatal rather than ignored: check_environment() in"
      "    app/core/config.py fails startup on any KB_* variable with no field and no recorded"
      "    exemption. For core-api and web they are simply discarded, silently."
      ""
    )
    details+=("  Reconcile by hand — do NOT blind-copy the template over a deployment file that")
    details+=("  carries real per-deployment values.")
    fail "$base has drifted from its template" "${details[@]}"
  done
fi

# ================================================================================================
# 3. Public hostnames. Four of them, and there is never a fifth.
# ================================================================================================
say "Public hostnames"
if [[ "$ENV_OK" -eq 0 ]]; then
  warn "skipped — no readable .env (reported above)"
else
  DOMAIN_V="$(env_value "$ENV_FILE" DOMAIN)"
  WIDGET_V="$(env_value "$ENV_FILE" WIDGET_DOMAIN)"
  ACME_V="$(env_value "$ENV_FILE" ACME_EMAIL)"
  DOMAIN_EX="$(env_value "$ENV_EXAMPLE" DOMAIN)"
  WIDGET_EX="$(env_value "$ENV_EXAMPLE" WIDGET_DOMAIN)"
  ACME_EX="$(env_value "$ENV_EXAMPLE" ACME_EMAIL)"

  # Reserved / documentation TLDs (RFC 2606, RFC 6761). A certificate can never be issued for one,
  # and the router that carries it is healthy and unreachable.
  is_placeholder_domain() {
    case "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" in
      *.example|*.invalid|*.test|*.localhost|localhost|\
      example.com|example.net|example.org|*.example.com|*.example.net|*.example.org) return 0 ;;
    esac
    return 1
  }

  for pair in "DOMAIN|$DOMAIN_V|$DOMAIN_EX" "WIDGET_DOMAIN|$WIDGET_V|$WIDGET_EX"; do
    key="${pair%%|*}"; rest="${pair#*|}"; val="${rest%%|*}"; example="${rest#*|}"
    if [[ -z "$val" ]]; then
      fail "$key is unset or empty in .env" \
        "  With \$$key empty the Traefik rule renders as Host(\`api.\`) — a syntactically valid" \
        "  hostname no client will ever send. The router is CREATED, it is HEALTHY, it shows up in" \
        "  the dashboard, and it matches nothing. Traefik logs nothing, because nothing is wrong." \
        "  The only symptom is a 404 from the edge for every request in the deployment."
    elif [[ -n "$example" && "$val" == "$example" ]]; then
      fail "$key is still the shipped .env.example value ($val)" \
        "  This is the placeholder from $ENV_EXAMPLE. ACME cannot issue a certificate for a" \
        "  reserved domain, so the edge serves Traefik's self-signed default and every client gets" \
        "  a TLS error — after the deploy has already replaced the running containers."
    elif is_placeholder_domain "$val"; then
      fail "$key is a reserved/documentation domain ($val)" \
        "  RFC 2606 / RFC 6761 reserve this name; no CA will ever issue for it and no resolver" \
        "  outside this host will answer for it. Set a domain you actually control."
    elif ! printf '%s' "$val" | grep -qE '^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)+$'; then
      fail "$key is not a bare hostname ($val)" \
        "  It goes verbatim into a Traefik Host(\`…\`) rule and into an ACME request. A scheme, a" \
        "  port, a path or a trailing slash produces a router that compiles and matches nothing."
    else
      ok "$key = $val"
    fi
  done

  # The widget must sit on a DIFFERENT registrable domain, not a subdomain. The admin session
  # cookie is scoped to ${DOMAIN}; put the widget on widget.${DOMAIN} and a widget iframe embedded
  # on a hostile customer page becomes same-site with a real admin credential. CHIPS does not help
  # — the cookie is not the widget's.
  if [[ -n "$DOMAIN_V" && -n "$WIDGET_V" ]]; then
    d_lc="$(printf '%s' "$DOMAIN_V" | tr '[:upper:]' '[:lower:]')"
    w_lc="$(printf '%s' "$WIDGET_V" | tr '[:upper:]' '[:lower:]')"
    if [[ "$d_lc" == "$w_lc" ]]; then
      fail "WIDGET_DOMAIN equals DOMAIN ($WIDGET_V)" \
        "  The widget then shares an ORIGIN with the admin console and the hosted chat. Every" \
        "  same-origin protection between a hostile customer page's iframe and an admin session" \
        "  disappears at once."
    elif [[ "$w_lc" == *".$d_lc" || "$d_lc" == *".$w_lc" ]]; then
      fail "WIDGET_DOMAIN is a subdomain of DOMAIN (or vice versa): $WIDGET_V vs $DOMAIN_V" \
        "  The admin session cookie is scoped to \${DOMAIN}, so a widget iframe on a hostile" \
        "  customer page becomes SAME-SITE with a real admin credential. It must be a different" \
        "  registrable domain — a different eTLD+1, not a different label."
    elif [[ "$(registrable_domain "$d_lc")" == "$(registrable_domain "$w_lc")" ]]; then
      fail "WIDGET_DOMAIN shares a registrable domain with DOMAIN ($(registrable_domain "$d_lc"))" \
        "  $WIDGET_V and $DOMAIN_V are different hostnames but the same eTLD+1, so they are" \
        "  same-site for cookie purposes. SameSite=Lax on the admin session does not separate" \
        "  them; only a different registrable domain does."
    else
      ok "WIDGET_DOMAIN is a separate registrable domain ($(registrable_domain "$w_lc") vs $(registrable_domain "$d_lc"))"
    fi
  fi

  # ACME_EMAIL is passed as a command: FLAG on traefik (Compose interpolates the compose file, not
  # a file it mounts). Empty, and Let's Encrypt registration fails at issuance time — after cutover.
  if [[ -z "$ACME_V" ]]; then
    fail "ACME_EMAIL is unset or empty in .env" \
      "  Traefik registers the ACME account with an empty contact and issuance fails AFTER the" \
      "  deploy has swapped containers. The edge then serves its self-signed default certificate."
  elif [[ -n "$ACME_EX" && "$ACME_V" == "$ACME_EX" ]]; then
    fail "ACME_EMAIL is still the shipped .env.example value ($ACME_V)" \
      "  Expiry warnings go to a mailbox nobody reads, on a domain nobody owns."
  else
    ok "ACME_EMAIL set"
  fi
fi

# ================================================================================================
# 3b. THE TRUSTED-PROXY CHAIN. Three files have to agree or every per-IP rate limit is fiction.
# ================================================================================================
# Traefik is the direct TCP peer of every request from the internet. Laravel therefore learns the
# caller's address from X-Forwarded-For or not at all, and it believes that header only from a CIDR
# it is told to trust. Told nothing, `$request->ip()` is Traefik's container address for EVERY
# request on earth: AppServiceProvider's five auth limiters each carry an `ip:` arm, so login
# becomes 20 attempts per minute for the whole internet and `password-request` becomes 10 — one
# host can hold password reset unavailable for every tenant, indefinitely. The same value is what
# AuditLogger::ipFrom() writes to audit_logs.ip_address, so `auth.login.failed` — null org, null
# actor, by design — would record the reverse proxy and locate nothing.
#
# FOUR THINGS MUST HOLD, AND THE FAILURE OF ANY ONE IS SILENT:
#   a. compose.yaml pins `edge`'s ipam subnet. Unpinned, Docker allocates it from its address pool
#      in creation order, interleaved with every other project on the host, and the CIDR Laravel
#      trusts stops matching the network it is supposed to describe.
#   b. Both routed Laravel services carry TRUSTED_PROXIES, and it is the SAME scalar as (a) — the
#      YAML alias, not a retyped literal.
#   c. It is not a wildcard. '*' expands to 0.0.0.0/0 and makes X-Forwarded-For attacker-supplied,
#      which is strictly worse than trusting nothing: a forged address per request evades every
#      per-IP limiter instead of merely sharing one bucket.
#   d. Traefik does not trust a client's own X-Forwarded-For (`insecure: true` / a non-empty
#      trustedIPs), which would let an internet caller seed the chain Traefik hands on.
#
# PARSED TEXTUALLY, NOT VIA `docker compose config`, for this script's standing reason: that
# command renders every interpolated value in full and its output is not safe to print.
say "Trusted-proxy chain (edge subnet -> TRUSTED_PROXIES -> Traefik)"

COMPOSE_BASE="$DOCKER_DIR/compose.yaml"
TRAEFIK_STATIC="$DOCKER_DIR/traefik/traefik.yaml"

# The anchor definition: `x-edge-subnet: &edge-subnet "..."`. One line, or this check fails closed.
EDGE_ANCHOR_LINES="$(grep -cE '^x-edge-subnet:[[:space:]]*&edge-subnet[[:space:]]' "$COMPOSE_BASE" || true)"
EDGE_SUBNET_DECL="$(sed -nE 's/^x-edge-subnet:[[:space:]]*&edge-subnet[[:space:]]*"?\$\{KB_EDGE_SUBNET:-([^}"]+)\}"?.*/\1/p' "$COMPOSE_BASE" | head -1)"

if [[ "$EDGE_ANCHOR_LINES" != "1" ]]; then
  fail "compose.yaml does not define exactly one x-edge-subnet anchor (found $EDGE_ANCHOR_LINES)" \
    "  The anchor is what keeps the edge network's subnet and TRUSTED_PROXIES the SAME scalar." \
    "  Two definitions, or none, means this check cannot tell which value Laravel will trust —" \
    "  and a second literal is a value Docker is free to invalidate on one side only."
elif [[ -z "$EDGE_SUBNET_DECL" ]]; then
  fail "x-edge-subnet exists but its default CIDR could not be parsed" \
    "  Expected the form:  x-edge-subnet: &edge-subnet \"\${KB_EDGE_SUBNET:-<cidr>}\"" \
    "  (the CIDR is deliberately not spelled out here — the default has moved once already, and a" \
    "  hint that names a stale range sends the reader to fix the wrong half.)" \
    "  Failing closed rather than assuming a range: this value is what Laravel believes an" \
    "  X-Forwarded-For from, and a check that guesses it proves nothing."
else
  # The EFFECTIVE value: .env may override the compose default with one variable.
  EDGE_SUBNET_EFF="$EDGE_SUBNET_DECL"
  if [[ "$ENV_OK" -eq 1 ]]; then
    _env_override="$(env_value "$ENV_FILE" KB_EDGE_SUBNET)"
    [[ -n "$_env_override" ]] && EDGE_SUBNET_EFF="$_env_override"
  fi

  # (a) the network must actually be pinned, and pinned to the alias.
  if grep -qE '^[[:space:]]+- subnet:[[:space:]]*\*edge-subnet[[:space:]]*$' "$COMPOSE_BASE"; then
    ok "edge network ipam subnet is pinned to the anchor (effective: $EDGE_SUBNET_EFF)"
  else
    fail "the edge network's ipam subnet is not pinned to *edge-subnet" \
      "  networks.edge has no \`ipam.config[].subnet: *edge-subnet\`, so Docker assigns the range" \
      "  from its address pool in creation order — interleaved with every other Compose project on" \
      "  this host. MEASURED on the development host: edge=172.24, application=172.25, data=172.26," \
      "  observability=172.28, with the gap at .27 taken by an unrelated network BETWEEN two of" \
      "  ours. Prune the networks or start another project first and every value moves, while" \
      "  TRUSTED_PROXIES keeps naming the old one. Nothing errors; the per-IP limiters just stop" \
      "  being per-IP."
  fi

  # (b) both routed services must carry it, via the alias.
  _tp_alias="$(grep -cE '^[[:space:]]+TRUSTED_PROXIES:[[:space:]]*\*edge-subnet[[:space:]]*$' "$COMPOSE_BASE" || true)"
  _tp_any="$(grep -cE '^[[:space:]]+TRUSTED_PROXIES:' "$COMPOSE_BASE" || true)"
  if [[ "$_tp_alias" == "2" && "$_tp_any" == "2" ]]; then
    ok "TRUSTED_PROXIES set on both routed Laravel services, via the anchor"
  elif [[ "$_tp_any" != "$_tp_alias" ]]; then
    fail "a TRUSTED_PROXIES value in compose.yaml is a literal rather than *edge-subnet" \
      "  $_tp_any occurrences, $_tp_alias of them aliased. A retyped CIDR is a second copy of a" \
      "  value Docker owns: pin the network to one range, type another into an environment key," \
      "  and the mismatch is invisible until you notice every login attempt sharing one bucket." \
      "  Use \`TRUSTED_PROXIES: *edge-subnet\`."
  else
    fail "TRUSTED_PROXIES is set on $_tp_any of the 2 routed Laravel services" \
      "  Both laravel-api and laravel-api-stream serve requests through Traefik and both boot the" \
      "  same framework, so both need it. The stream service is the public chat surface and has" \
      "  rate limiting of its own." \
      "" \
      "  Note it belongs in \`environment:\` in compose.yaml, NOT in env/core-api.env: that file is" \
      "  rendered once from its template and never updated, so a security value placed there" \
      "  reaches new deployments and silently misses every existing one (see §2b above)."
  fi

  # (c) never a wildcard, never all-of-IPv4, never empty.
  case "$EDGE_SUBNET_EFF" in
    '*' | '**' | 0.0.0.0/0 | ::/0 | '')
      fail "the effective trusted-proxy range is '$EDGE_SUBNET_EFF' — that is a wildcard" \
        "  TrustProxies expands '*' to 0.0.0.0/0, and Symfony then reads the CLIENT-SUPPLIED" \
        "  X-Forwarded-For verbatim. That is strictly WORSE than trusting nothing: instead of" \
        "  sharing one global bucket, an attacker picks a fresh address per request and evades" \
        "  every per-IP limiter completely, while audit_logs records whatever it was told." \
        "  Pin KB_EDGE_SUBNET to the edge network's own range."
      ;;
    */*)
      ok "trusted-proxy range is a bounded CIDR, not a wildcard"
      ;;
    *)
      fail "the effective trusted-proxy range '$EDGE_SUBNET_EFF' is not a CIDR" \
        "  A bare address would work but pins Traefik to one container IP, which Docker allocates" \
        "  sequentially and does not reserve — the next container on \`edge\` takes it and Traefik" \
        "  fails to start. Use the network's CIDR."
      ;;
  esac

  # (a'), the one a config review cannot perform: what the LIVE network actually holds.
  #
  # AND THE PART THAT IS EASY TO GET WRONG: a matching subnet does NOT mean the next `up` leaves
  # the network alone. Compose decides by the `com.docker.compose.config-hash` LABEL it stamps on
  # the network, not by comparing subnets, and that hash changes when the declaration changes.
  # MEASURED against Compose v5.3.1 on a throwaway project 2026-08-13: a network created with no
  # `ipam:` and then re-upped with an ipam block naming THE VERY SUBNET DOCKER HAD ALREADY ASSIGNED
  # was stopped, removed, recreated, and its container restarted. The second `up` after that was a
  # clean no-op. So the cost is one ordinary restart of everything on `edge`, once — reported here
  # rather than failed, because it is a scheduling fact and not a misconfiguration.
  if docker network inspect knowledgebot_edge >/dev/null 2>&1; then
    _live_subnet="$(docker network inspect knowledgebot_edge \
      --format '{{range .IPAM.Config}}{{.Subnet}} {{end}}' 2>/dev/null | tr -d ' ')"
    if [[ "$_live_subnet" == "$EDGE_SUBNET_EFF" ]]; then
      warn "live knowledgebot_edge already holds $_live_subnet, which equals the declared range —"
      warn "  but that does NOT mean the next \`up\` is a no-op. Compose compares the network's"
      warn "  com.docker.compose.config-hash label, so if this network predates the ipam pin it will"
      warn "  still be removed and recreated once, restarting traefik, web, sdk, laravel-api,"
      warn "  laravel-api-stream and otel-collector. Idempotent from then on. Do it in a window."
    else
      fail "live knowledgebot_edge is $_live_subnet but the declared range is $EDGE_SUBNET_EFF" \
        "  Until Compose reconciles this, TRUSTED_PROXIES names a range that does not contain" \
        "  Traefik: X-Forwarded-For is ignored, \$request->ip() is the proxy for every caller, and" \
        "  all five per-IP auth limiters are one global bucket. Reconciling REMOVES and recreates" \
        "  the network, restarting every container on it. Plan the window, then re-run this." \
        "" \
        "  If the two ranges disagree because you set KB_EDGE_SUBNET after the network existed," \
        "  that is exactly this case; there is no way to move a live network's subnet in place."
    fi
  fi

  # (d) Traefik must not adopt a client's own forwarded headers.
  if [[ ! -f "$TRAEFIK_STATIC" ]]; then
    fail "traefik/traefik.yaml is missing" \
      "  It is a committed file and a MOUNTED one; Docker turns a missing bind source into a" \
      "  directory, which Traefik reports as a fatal config load. Restore it from git."
  elif grep -qE '^[[:space:]]*insecure:[[:space:]]*true' "$TRAEFIK_STATIC"; then
    fail "traefik.yaml sets forwardedHeaders.insecure: true" \
      "  Traefik would then preserve a client-supplied X-Forwarded-For from anyone on the" \
      "  internet. Combined with Laravel trusting the edge subnet, an attacker chooses the address" \
      "  every rate limiter and every audit row records. The two halves are one control."
  elif grep -qE '^[[:space:]]*trustedIPs:[[:space:]]*\[\][[:space:]]*$' "$TRAEFIK_STATIC"; then
    ok "traefik forwardedHeaders.trustedIPs is empty — no client-supplied X-Forwarded-* is adopted"
  else
    warn "traefik.yaml's forwardedHeaders.trustedIPs is neither the empty list nor absent."
    warn "  That is legitimate ONLY if you genuinely run a CDN or L7 balancer in front of Traefik."
    warn "  If you do, TRUSTED_PROXIES must gain that upstream's range too: X-Forwarded-For then"
    warn "  carries one more hop, and Laravel discounts only the hops it is told to trust."
  fi
fi

# ================================================================================================
# 4. Image references. Digest-pinned, and the placeholders are all zeros.
# ================================================================================================
# compose.prod.yaml pins every image as tag@${..._DIGEST}. The shipped placeholders are
# sha256:0000…0000, which INTERPOLATES FINE — `config` renders, and the failure lands at `docker
# pull`, mid-deploy, after some services have already been recreated. Catching it here costs
# nothing; catching it there costs the window.
say "Image references"
if [[ "$ENV_OK" -eq 0 ]]; then
  warn "skipped — no readable .env (reported above)"
else
  for key in REGISTRY VERSION; do
    if [[ -z "$(env_value "$ENV_FILE" "$key")" ]]; then
      fail "$key is unset or empty in .env" \
        "  compose.prod.yaml builds every application image reference as \${REGISTRY}/name:" \
        "  \${VERSION}@\${..._DIGEST}. An empty half produces an invalid reference and the pull" \
        "  fails partway through the deploy."
    fi
  done
  # Derived from the overlay, so a new service's digest is checked the day it is added.
  # shellcheck disable=SC2016  # '${}' is a literal character set for tr, not an expansion
  digest_vars="$(grep -oE '\$\{[A-Z0-9_]*DIGEST\}' "$DOCKER_DIR/compose.prod.yaml" \
                 | tr -d '${}' | sort -u)"
  bad_digests=""
  for key in $digest_vars; do
    v="$(env_value "$ENV_FILE" "$key")"
    if [[ -z "$v" ]]; then
      bad_digests="$bad_digests $key(unset)"
    elif ! printf '%s' "$v" | grep -qE '^sha256:[0-9a-f]{64}$'; then
      bad_digests="$bad_digests $key(malformed)"
    elif [[ "$v" == "sha256:0000000000000000000000000000000000000000000000000000000000000000" ]]; then
      bad_digests="$bad_digests $key(placeholder)"
    fi
  done
  if [[ -n "$bad_digests" ]]; then
    fail "image digests unusable:$bad_digests" \
      "  A tag is a mutable pointer; the digest is the artifact, and it is what makes the SBOM" \
      "  describe the thing that actually shipped. These interpolate cleanly, so nothing fails" \
      "  until \`docker pull\` — partway through the deploy, with containers already replaced." \
      "  Refresh with: docker buildx imagetools inspect <image>:<tag>"
  else
    ok "$(printf '%s' "$digest_vars" | grep -c . || true) digests present and well-formed"
  fi
fi

# ================================================================================================
# 5. THE SECRETS. This is the check the whole script is for.
# ================================================================================================
say "Secrets ($SECRETS_DIR)"
if [[ ! -d "$SECRETS_DIR" ]]; then
  fail "secrets/ directory is missing" \
    "  $SECRETS_DIR does not exist. Docker will CREATE IT, and create a DIRECTORY for every" \
    "  declared secret inside it, and the deploy will come up wide open. Run scripts/dev/" \
    "  bootstrap.sh on a development host, or provision the files from your secret store here."
else
  dmode="$(mode_of "$SECRETS_DIR")"
  if looser_than_owner_only "$dmode"; then
    fail "secrets/ is mode $dmode, not 700" \
      "  Every other uid on this host can list — and depending on the bits, read — the KEK, the" \
      "  internal HMAC signing keys, the database password and the S3 secret key. Those are the" \
      "  credentials the entire tenancy model rests on. Fix: chmod 700 $SECRETS_DIR"
  else
    ok "secrets/ mode $dmode"
  fi

  declare -A SEEN_HASH=()
  hash_of() { sha256sum "$1" 2>/dev/null | cut -d' ' -f1 || openssl dgst -sha256 -r "$1" 2>/dev/null | cut -d' ' -f1; }

  while IFS=$'\t' read -r name relpath; do
    [[ -n "$name" ]] || continue
    # Paths in the compose file are relative to the PROJECT DIRECTORY (infrastructure/docker).
    case "$relpath" in
      /*) path="$relpath" ;;
      *)  path="$DOCKER_DIR/${relpath#./}" ;;
    esac

    # ---- The missing-mount-source trap, first and by itself. --------------------------------
    if [[ -d "$path" ]]; then
      fail "secret '$name' is a DIRECTORY, not a file" \
        "  $path" \
        "" \
        "  This is what Docker does with a missing bind source: it creates a directory rather" \
        "  than failing. The container then finds a DIRECTORY at /run/secrets/$name." \
        "" \
        "  What that costs depends on the consumer, and the two here are opposite (ADR-037):" \
        "  a directory at the SeaweedFS -s3.config path is a FATAL load, exit 255, crash-loop —" \
        "  it fails CLOSED. A directory at valkey's aclfile STARTS CLEANLY, wide open. Either" \
        "  way this is a broken deploy, and neither is fixed by writing an empty JSON object" \
        "  into the config: '{}' is the Allow-All state, not a repair." \
        "" \
        "  Remove the directory and write the real secret:  rmdir '$path'"
      continue
    fi
    if [[ ! -e "$path" ]]; then
      if [[ "$name" == "app_key" ]]; then
        # Every other missing secret here is an outage: provision it, redeploy, done. This one is
        # the only entry in the list where the OBVIOUS remedy — generate a fresh one — is itself
        # the unrecoverable failure, so the remedy is spelled out before the reason.
        fail "secret 'app_key' is missing" \
          "  $path does not exist." \
          "" \
          "  DO NOT GENERATE A NEW ONE IF THIS DEPLOYMENT HAS EVER STORED ANYTHING. APP_KEY is the" \
          "  AES-256-CBC key behind every \`encrypted:\` column — the wrapped provider credentials" \
          "  among them — behind every session and cookie, and behind the audit key_fingerprint" \
          "  HMAC. Writing a NEW key here makes all of it permanently undecryptable. Not degraded," \
          "  not offline: gone. The ciphertext is intact and the only key that opens it no longer" \
          "  exists, so restoring the database, failing over or rolling back recovers nothing, and" \
          "  the provider credentials are not recoverable from anywhere else at all." \
          "" \
          "  Recover the ORIGINAL key from your secret store or from a host that still has it, and" \
          "  write it back to this path (mode 600)." \
          "" \
          "  A new key is correct ONLY on a deployment with no data yet. Generate it in Laravel's" \
          "  own format — a generic random string boots fine and throws 'unsupported cipher or" \
          "  incorrect key length' at the first decrypt, which users see as a login loop:" \
          "      printf 'base64:%s' \"\$(openssl rand -base64 32)\" > $path && chmod 600 $path" \
          "" \
          "  Deliberate rotation is three steps in this order: append the CURRENT key to" \
          "  secrets/app_previous_keys, THEN write the new key here, THEN re-encrypt."
      else
        fail "secret '$name' is missing" \
          "  $path does not exist." \
          "  Compose declares it as a file: source, so Docker CREATES A DIRECTORY there on the next" \
          "  \`up\` — see the directory case above for what that means for the object store." \
          "  Provision it before deploying; scripts/dev/bootstrap.sh generates the dev set."
      fi
      continue
    fi
    if [[ ! -f "$path" ]]; then
      fail "secret '$name' is not a regular file" \
        "  $path is a socket, device or dangling symlink. Docker will mount whatever it is."
      continue
    fi
    if [[ ! -s "$path" ]]; then
      # app_previous_keys is the ONE secret whose empty state is correct. It holds the retired
      # APP_KEY values, comma-separated, and an unrotated deployment has none. Empty means "none";
      # a file holding a comma or a stray newline would mean "one empty key", which Laravel then
      # attempts on every decrypt failure. Flagging this would train operators to write something
      # into it, which is the failure — so it is exempted by name, deliberately, and not merely
      # tolerated. (alertmanager_webhook_url is empty-checked below for the opposite reason: there,
      # empty is a silent failure.)
      if [[ "$name" == "app_previous_keys" ]]; then
        ok "$name  ok (empty — no retired APP_KEY yet, which is the correct pre-rotation state)"
      elif [[ "$name" == "app_key" ]]; then
        fail "secret 'app_key' is empty" \
          "  $path is zero bytes. Laravel reads an EMPTY application key, and the failure is not at" \
          "  boot — it is at the first decrypt, as a login loop on a deployment reporting healthy." \
          "" \
          "  Do NOT fix this by generating a fresh key if this deployment has ever stored anything:" \
          "  a new APP_KEY makes every \`encrypted:\` column (including the stored provider" \
          "  credentials), every session and every cookie permanently undecryptable. Restore the" \
          "  ORIGINAL key from your secret store. See the missing-app_key text above for the full" \
          "  rotation procedure."
      elif [[ "$name" == "alertmanager_webhook_url" ]]; then
        fail "secret 'alertmanager_webhook_url' is empty" \
          "  $path is zero bytes. Alertmanager starts, routes match, notifications are dispatched" \
          "  to an empty receiver URL and are DROPPED. The alerting stack looks healthy and pages" \
          "  nobody — which is only discovered during the incident it was supposed to catch." \
          "  Write the real receiver URL, or deploy without the observability profile knowingly."
      else
        fail "secret '$name' is empty" \
          "  $path is zero bytes. The consumer reads an empty string: an empty KEK, an empty HMAC" \
          "  signing key, an empty database password. Most of these do not fail loudly — an empty" \
          "  HMAC key still produces a valid, forgeable signature."
      fi
      continue
    fi

    findings=()

    m="$(mode_of "$path")"
    looser_than_owner_only "$m" && findings+=("mode $m (must be 600 — any group/other bit is a second uid holding the credential)")

    # Placeholder content. grep -q only: nothing matched is ever printed.
    if grep -qiE 'CHANGE[-_ ]?ME|REPLACE[-_ ]?ME|PLACEHOLDER|EXAMPLE[-_ ]?SECRET|^(changeme|password|secret|todo|xxx+)$' "$path"; then
      findings+=("contains a placeholder marker (CHANGE-ME / PLACEHOLDER / similar)")
    fi
    if grep -qE '^[0]+$' "$path"; then
      findings+=("is all zeros — an unfilled template, not a key")
    fi

    # Length. A one-byte KEK is non-empty and useless; 16 bytes is the floor below which nothing
    # here is doing cryptography. The webhook URL is exempt from the entropy reading but must
    # still look like a URL, because Alertmanager accepts a garbage receiver and drops silently.
    bytes="$(wc -c < "$path" | tr -d ' ')"
    if [[ "$name" == "alertmanager_webhook_url" ]]; then
      grep -qE '^https?://' "$path" || findings+=("does not start with http(s):// — Alertmanager will drop every notification")
    elif [[ "$bytes" -lt 16 ]]; then
      findings+=("is only $bytes bytes — too short to be key material")
    fi

    # app_key's SHAPE, not just its presence. Laravel needs the literal `base64:` prefix and exactly
    # 32 decoded bytes for AES-256-CBC. A key of the wrong shape passes every check above: it is a
    # non-empty, mode-600, high-entropy, untracked file. It then boots fine, renders fine, and
    # throws "unsupported cipher or incorrect key length" at the FIRST DECRYPT — which reaches a
    # human as a login loop on a deployment that reports itself healthy. This is the one shape check
    # worth spending a branch on, and the match is `grep -q` only: the value is never printed.
    #
    # 43 base64 characters plus one '=' is exactly 32 bytes, so the regex asserts the length without
    # decoding anything (and without ever holding the key in a shell variable).
    if [[ "$name" == "app_key" ]]; then
      if ! grep -qE '^base64:' "$path"; then
        findings+=("does not begin with 'base64:' — Laravel reads the file's bytes as a RAW key, AES-256-CBC rejects it, and the failure lands at the first decrypt as a login loop rather than at boot as a config error")
      elif ! grep -qE '^base64:[A-Za-z0-9+/]{43}=$' "$path"; then
        findings+=("is base64:-prefixed but is not base64 of exactly 32 bytes (expect 'base64:' followed by 44 characters ending in '='); any other length throws 'unsupported cipher or incorrect key length' on the first decrypt")
      fi
    fi
    if [[ "$name" == "app_previous_keys" ]]; then
      # Non-empty here means a rotation happened, so every comma-separated element must be a real
      # key. One malformed element makes the fallback decrypt throw instead of moving on, so a
      # session or column encrypted under the OLD key becomes unreadable even though the key is
      # present. Checked as a whole-file regex; nothing is echoed.
      grep -qE '^base64:[A-Za-z0-9+/]{43}=(,base64:[A-Za-z0-9+/]{43}=)*$' "$path" \
        || findings+=("is non-empty but is not a comma-separated list of 'base64:'-prefixed 32-byte keys — a malformed element makes the fallback decrypt throw, so data encrypted under a genuinely retired key stays unreadable")
    fi

    # A secret that git tracks is a secret that is published. Content is never read here; this is
    # a path lookup against the index.
    if git -C "$REPO_ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
      if git -C "$REPO_ROOT" ls-files --error-unmatch -- "$path" >/dev/null 2>&1; then
        findings+=("is TRACKED BY GIT — it is in the history, in every clone and in every fork; rotate it, do not just remove it")
      fi
    fi

    # Two secrets with identical bytes means someone copied one into the other. The usual pair is
    # hmac_key_k1 == hmac_key_k2, which makes the whole two-key rotation window decorative — the
    # verifier accepts both ids and both resolve to the same material, so retiring the old id
    # protects nothing. The other pair worth catching is hmac_key_k* == callback_hmac_key_c*: the
    # two directions are signed with disjoint key sets precisely so a compromised OUTBOUND key
    # cannot forge a CALLBACK, and one `cp` silently discards that property with no test failing.
    # Hashes are computed and compared; they are never printed.
    h="$(hash_of "$path")"
    if [[ -n "$h" ]]; then
      if [[ -n "${SEEN_HASH[$h]:-}" ]]; then
        findings+=("has the SAME CONTENT as '${SEEN_HASH[$h]}' — two names, one credential")
      else
        SEEN_HASH[$h]="$name"
      fi
    fi

    if [[ ${#findings[@]} -gt 0 ]]; then
      fail "secret '$name' is not deployable" \
        "  $path" \
        "$(printf '    - %s\n' "${findings[@]}")"
    else
      ok "$name  ok (mode $m, $bytes bytes)"
    fi
  done <<< "$SECRET_FILES"
fi

# ================================================================================================
# 6. seaweedfs/identities.json — the only thing between the object store and anonymous access.
# ================================================================================================
say "SeaweedFS S3 identities"
IDENT="$DOCKER_DIR/seaweedfs/identities.json"
IDENT_EXAMPLE="$DOCKER_DIR/seaweedfs/identities.json.example"
BREAKER="$DOCKER_DIR/seaweedfs/circuit_breaker.json"
# identities.json is GITIGNORED and rendered from identities.json.example by scripts/dev/bootstrap.sh.
# It holds a plaintext S3 secret key byte-identical to secrets/s3_secret_key, so it is not committed.
if [[ -d "$IDENT" || ! -f "$IDENT" ]]; then
  fail "identities.json is missing or is a directory" \
    "  $IDENT" \
    "  The S3 gateway starts with -s3.config=/etc/s3/identities.json. Measured against the pinned" \
    "  chrislusf/seaweedfs:4.40 on 2026-08-11, this exact state is a FATAL startup error (exit" \
    "  255), so the container will crash-loop rather than serve — it fails closed. That is still a" \
    "  broken deploy. Render it: scripts/dev/bootstrap.sh copies identities.json.example into" \
    "  place and rewrites the placeholders." \
    "  (What DOES fail open is dropping -s3.config from the compose command, or a config that" \
    "  parses to zero identities. Never 'fix' a crash-loop by writing {} here.)"
elif [[ ! -s "$IDENT" ]]; then
  fail "identities.json is empty" \
    "  A zero-byte file is also a fatal config load (proto syntax error, exit 255). Do not fill it" \
    "  with an empty JSON object to silence that: `{\"identities\": []}` parses, starts cleanly," \
    "  and serves anonymous ListBuckets with 200. The crash is the safe state; ALLOW-ALL is not."
else
  ident_bad=()
  grep -q '"identities"' "$IDENT" || ident_bad+=("has no \"identities\" key — the gateway will configure nothing and run Allow-All")
  grep -q '"secretKey"' "$IDENT"  || ident_bad+=("declares no secretKey")
  # ANCHORED TO THE secretKey FIELD, deliberately. A bare file-wide `grep -q CHANGE-ME` was a
  # false positive on every correctly bootstrapped deploy: the __README prose inside the file
  # (copied verbatim from identities.json.example, which bootstrap does NOT rewrite) explains what
  # the placeholder marker is and therefore contains the marker. That grep failed the deploy on a
  # file whose credentials were perfectly good. Match the VALUE, not the document.
  grep -qE '"secretKey"[[:space:]]*:[[:space:]]*"[^"]*CHANGE-ME' "$IDENT" \
    && ident_bad+=("still has a placeholder secretKey — the value published in identities.json.example, identical in every clone of this repository")
  grep -qE '"secretKey"[[:space:]]*:[[:space:]]*""' "$IDENT" && ident_bad+=("has an empty secretKey")
  # Byte-identical to the COMMITTED TEMPLATE means bootstrap rendered it and never rewrote it.
  #
  # This used to compare against `HEAD:infrastructure/docker/seaweedfs/identities.json`. That check
  # is now DEAD CODE waiting to happen: the file is gitignored, so `git rev-parse HEAD:<path>` finds
  # no blob, `head_blob` is empty, the `-n "$head_blob"` guard short-circuits, and the check silently
  # passes on every input forever. Comparing against the template on disk instead is strictly better
  # anyway — it works in a tarball export, in a dirty tree, and on a detached HEAD.
  if [[ -f "$IDENT_EXAMPLE" ]]; then
    if cmp -s "$IDENT" "$IDENT_EXAMPLE"; then
      ident_bad+=("is byte-identical to identities.json.example — bootstrap rendered the template but the placeholder credentials were never rewritten")
    fi
  else
    ident_bad+=("identities.json.example is missing, so this check cannot run; it is a committed file and its absence is itself a finding")
  fi
  # ---- THE IDENTITY SET ITSELF, which nothing checked until 2026-08-11. ------------------------
  #
  # Every other credential in this deployment is a file under secrets/, and the loop in section 5
  # walks all fourteen of them: mode, size, entropy, git-trackedness, duplicate content. An
  # identity declared INSIDE identities.json is invisible to that loop — it is a grant, not a
  # file — so `kb-backup` sat here for a week holding ['Read:kb','List:kb'] over the whole bucket,
  # with a secret written nowhere else, referenced by zero lines of compose.yaml, this script and
  # backup.sh. Nothing was wrong with any file. The finding was the SET.
  #
  # So the set is asserted by name, and it is a closed set of one. `kb-app` is the only identity
  # any consumer is configured with (env/core-api.env AWS_ACCESS_KEY_ID, env/ai-service.env
  # KB_S3_ACCESS_KEY_ID). Names are printed; secretKeys never are.
  #
  # This also catches the case the rendered file cannot fix by itself: identities.json is
  # gitignored and bootstrap.sh copies the template only when the file is ABSENT, so a host
  # bootstrapped before the removal still grants kb-backup and no re-render will drop it.
  ident_names="$(grep -oE '"name"[[:space:]]*:[[:space:]]*"[^"]*"' "$IDENT" \
                 | sed -E 's/.*"name"[[:space:]]*:[[:space:]]*"([^"]*)".*/\1/' | sort -u)"
  if [[ -z "$ident_names" ]]; then
    ident_bad+=("declares no identity with a \"name\" — a config that parses to zero identities is the ALLOW-ALL state, and it starts cleanly")
  else
    ident_unexpected="$(printf '%s\n' "$ident_names" | grep -vx 'kb-app' || true)"
    if [[ -n "$ident_unexpected" ]]; then
      ident_bad+=("declares identities no consumer is configured with: $(printf '%s' "$ident_unexpected" | tr '\n' ' ') — each is a standing S3 grant whose key exists in no other file, so no secret sweep can see it. Remove the identity block from identities.json and restart seaweedfs (the file is read ONCE, at startup). If one is genuinely needed, add its consumer, give it a secrets/ twin, and extend this check in the same change.")
    fi
    printf '%s\n' "$ident_names" | grep -qx 'kb-app' \
      || ident_bad+=("does not declare the kb-app identity, which is the accessKey both services are configured with (AWS_ACCESS_KEY_ID / KB_S3_ACCESS_KEY_ID); every object operation would 403")
  fi
  if [[ ${#ident_bad[@]} -gt 0 ]]; then
    fail "identities.json still holds shipped development credentials" \
      "  $IDENT" \
      "$(printf '    - %s\n' "${ident_bad[@]}")" \
      "" \
      "  Deploying with a secretKey published on GitHub is Allow-All with extra steps: anyone who" \
      "  can reach the gateway can read and delete every object in every organization. The key is" \
      "  also expected to match secrets/s3_secret_key, which the application reads at" \
      "  /run/secrets/s3_secret_key." \
      "" \
      "  AND NOTE: this file being correct is NOT proof the gateway is closed. Assert it after" \
      "  the deploy — an anonymous ListBuckets must return 403:" \
      "      aws --endpoint-url http://127.0.0.1:8333 --no-sign-request s3 ls"
  else
    ok "identities.json rewritten (no CHANGE-ME secretKey)"
  fi
fi
# The circuit-breaker config is mounted from the same directory; a missing source there becomes a
# directory too, and the gateway then runs with no request limits at all.
if [[ ! -f "$BREAKER" || ! -s "$BREAKER" ]]; then
  fail "circuit_breaker.json is missing, empty or a directory" \
    "  $BREAKER" \
    "  It is mounted into the same /etc/s3 as identities.json. A missing bind source becomes a" \
    "  Docker-created directory, and the S3 gateway then enforces no concurrency or bandwidth" \
    "  limit at all — one runaway ingestion saturates the store for every tenant."
else
  ok "circuit_breaker.json present"
fi

# ================================================================================================
# 7. valkey/users.acl — the opposite failure mode, and one line that must NOT be flagged.
# ================================================================================================
# ================================================================================================
# THIS SECTION'S PREMISE WAS WRONG UNTIL 2026-08-11, AND IT WAS WRONG IN THE UNSAFE DIRECTION.
# ================================================================================================
# It used to read "Valkey REFUSES TO START without its aclfile, which is the correct failure".
# Measured against the pinned valkey/valkey:9.1.1, with the real core.conf and `aclfile
# /etc/valkey/users.acl`:
#
#   users.acl absent (Docker created a DIRECTORY at the bind source) -> server STARTS, exit 0
#   users.acl present but ZERO BYTES                                 -> server STARTS, exit 0
#
# and in both cases `ACL LIST` is `user default on nopass sanitize-payload ~* &* +@all`. An
# unauthenticated `SET pwned 1` returns OK. So Valkey is the one that FAILS OPEN here, not
# SeaweedFS — the two services' reputations in this repository were exactly backwards. A missing
# users.acl is unauthenticated full-privilege access to the queues, sessions, locks, rate limiters
# and cache of every tenant.
#
# Malformed CONTENT is still fatal (a `#` comment line aborts startup — see valkey/README.md).
# It is ABSENCE and EMPTINESS that are silent, which is the pair a fresh clone can actually hit.
say "Valkey ACL"
ACL="$DOCKER_DIR/valkey/users.acl"
ACL_EXAMPLE="$DOCKER_DIR/valkey/users.acl.example"
if [[ -d "$ACL" || ! -f "$ACL" || ! -s "$ACL" ]]; then
  fail "users.acl is missing, empty or a directory" \
    "  $ACL" \
    "  THIS IS A FAIL-OPEN, not a startup failure. Valkey 9.1.1 starts cleanly with this path" \
    "  absent or empty and enables \`user default on nopass ~* &* +@all\` — unauthenticated" \
    "  full-privilege access to every queue, session, lock, rate limiter and cache entry." \
    "  users.acl is gitignored (it holds the three sha256 password hashes). Render it:" \
    "  scripts/dev/bootstrap.sh copies valkey/users.acl.example into place and provisions the" \
    "  three passwords into secrets/valkey_kb_*_password."
else
  acl_bad=()
  # Byte-identical to the committed template means the passwords were never provisioned. The
  # template denies everyone (all three hashes are #0{64}, `user default off`), so this fails
  # closed rather than open — but it is still an unbootable deploy.
  if [[ -f "$ACL_EXAMPLE" ]] && cmp -s "$ACL" "$ACL_EXAMPLE"; then
    acl_bad+=("is byte-identical to users.acl.example — bootstrap rendered the template but never provisioned the passwords")
  fi
  # Exactly 64 zeros is the shipped placeholder hash for kb-core, kb-ai and kb-observer.
  grep -qE '#0{64}' "$ACL" && acl_bad+=("still contains a #0{64} placeholder password hash — the shipped template value, identical in every clone of this repository")
  # `>plaintext` is a plaintext credential in a file that is committed.
  grep -qE '^[[:space:]]*user[[:space:]]+[^[:space:]]+.*[[:space:]]>' "$ACL" && acl_bad+=("uses the >plaintext password form; the committed form is #<sha256 hex>")
  # `user default off` is CORRECT and is deliberately NOT a finding. Its ABSENCE is: with the
  # default user enabled, every misconfigured client connects with full privileges instead of
  # failing, and "the ACL is working" goes unverified for a year.
  grep -qE '^[[:space:]]*user[[:space:]]+default[[:space:]]+off' "$ACL" \
    || acl_bad+=("does not disable the default user (\`user default off\`); a misconfigured client would connect with full privileges instead of failing")
  if [[ ${#acl_bad[@]} -gt 0 ]]; then
    fail "users.acl still holds shipped development credentials" \
      "  $ACL" \
      "$(printf '    - %s\n' "${acl_bad[@]}")" \
      "" \
      "  A known password hash on kb-core is a queue-write credential; on kb-ai it is read access" \
      "  to every coordination key. The per-service ACL is what makes the logical-DB split a" \
      "  boundary the server enforces rather than a naming convention."
  else
    ok "users.acl rewritten (no placeholder hashes; default user disabled)"
  fi
fi

# ================================================================================================
# 8. THE POSTGRES ROLE SPLIT (D21). The check that makes append-only an enforced control.
# ================================================================================================
# WHAT IT GUARDS. `POSTGRES_USER` creates exactly one role and makes it a SUPERUSER, and a superuser
# BYPASSES EVERY ACL CHECK. Measured on PostgreSQL 18.4 before this split: audit_logs' `REVOKE
# UPDATE, DELETE` landed correctly — `pg_class.relacl` showed no UPDATE and no DELETE on the parent
# or on any partition — and `UPDATE audit_logs SET operation='tampered'` returned `UPDATE 1` anyway.
# The ACL was an audit artifact. Everything below is about keeping it a control instead.
#
# THIS SECTION IS SPLIT IN TWO ON PURPOSE:
#   (a) FILE CHECKS, which run always. Do the compose files and the env templates agree on which
#       role each service connects as, and is the least-privileged one the shared default?
#   (b) LIVE CHECKS, which run only when the postgres container is up. Do the roles exist, is
#       neither a superuser, and does the app role hold no write privilege on any audit relation?
#
# (b) is the assertion that matters and it is the one that cannot always run. It DEGRADES to a
# warning rather than a failure when postgres is down, because `make preflight` must be runnable
# before the first `up` — and it is a FAILURE, not a warning, when postgres IS up and the answer is
# wrong. A check that silently skips is a check that can be made green by stopping a container, so
# the skip prints the reason and the ledger counts it.
say "PostgreSQL role split (D21)"

PG_MIGRATE_ROLE="kb_migrate"
PG_APP_ROLE="kb_app"
PG_CONTAINER_NAME="${PG_CONTAINER:-knowledgebot-postgres-1}"
AUDIT_PARENT="audit_logs"

# ── (a) FILE CHECKS ──────────────────────────────────────────────────────────────────────────────
# The shared env template must carry the LEAST-PRIVILEGED role. It is read by all six core-api
# containers, so a service that declares no override inherits whatever is here — and the only
# acceptable direction for a shared default to be wrong in is "less access".
tpl_core="$DOCKER_DIR/env/core-api.env.example"
tpl_ai="$DOCKER_DIR/env/ai-service.env.example"
if [[ -r "$tpl_core" ]]; then
  db_user="$(sed -nE 's/^[[:space:]]*DB_USERNAME=(.*)$/\1/p' "$tpl_core" | tail -1)"
  if [[ "$db_user" == "$PG_APP_ROLE" ]]; then
    ok "env/core-api.env.example: DB_USERNAME=$PG_APP_ROLE (the least-privileged role is the shared default)"
  else
    fail "env/core-api.env.example has DB_USERNAME=${db_user:-<unset>}, not $PG_APP_ROLE" \
      "  This file is shared by all six core-api containers, so it is the value a service gets when" \
      "  it declares no override. If it names the POSTGRES_USER superuser, every container connects" \
      "  as a superuser, and a superuser bypasses every ACL check — audit_logs' REVOKE becomes an" \
      "  audit artifact again while every ACL assertion in the Pest suite still passes. Measured:" \
      "  \`UPDATE audit_logs SET operation='tampered'\` returned \`UPDATE 1\`." \
      "  The two legitimate overrides (laravel-migrate, laravel-scheduler) live in compose.yaml," \
      "  because which role a container uses is a PER-SERVICE fact and this is one shared file."
  fi
fi
if [[ -r "$tpl_ai" ]]; then
  ai_user="$(sed -nE 's/^[[:space:]]*KB_PG_USER=(.*)$/\1/p' "$tpl_ai" | tail -1)"
  [[ "$ai_user" == "$PG_APP_ROLE" ]] \
    && ok "env/ai-service.env.example: KB_PG_USER=$PG_APP_ROLE (the data plane issues no DDL and owns no migration)" \
    || fail "env/ai-service.env.example has KB_PG_USER=${ai_user:-<unset>}, not $PG_APP_ROLE" \
         "  The data plane owns no migration (ADR-012) and has no reason to hold the owner" \
         "  credential. Its four-table write restriction is enforced only by ALLOWED_TABLES in" \
         "  app/db/writes.py; a superuser connection removes the database's own last word on it."
fi

# The one-shot must exist, must be un-profiled, and laravel-migrate must gate on it. A profiled
# one-shot is only enabled when that profile is active, and a `depends_on` into an inactive profile
# is an invalid model — Compose errors rather than enabling it.
if grep -qE '^  postgres-roles:' "$DOCKER_DIR/compose.yaml"; then
  if awk '/^  postgres-roles:/{f=1;next} f&&/^  [a-z]/{exit} f&&/^    profiles:/{print "yes"}' \
       "$DOCKER_DIR/compose.yaml" | grep -q yes; then
    fail "the postgres-roles one-shot carries a \`profiles:\` key" \
      "  A profiled service is only enabled when that profile is active, so a bare" \
      "  \`docker compose up\` would skip it — and laravel-migrate's depends_on would then be a" \
      "  dependency on a disabled service, which Compose reports as an invalid model rather than" \
      "  auto-enabling. Required services carry no profiles key."
  else
    ok "postgres-roles one-shot is declared and carries no profiles key"
  fi
  awk '/^  laravel-migrate:/{f=1} f&&/postgres-roles:[[:space:]]*\{condition: service_completed_successfully\}/{print "gated"} f&&/^  [a-z].*:$/&&!/laravel-migrate/{exit}' \
      "$DOCKER_DIR/compose.yaml" | grep -q gated \
    && ok "laravel-migrate gates on postgres-roles: service_completed_successfully" \
    || fail "laravel-migrate does not gate on postgres-roles with service_completed_successfully" \
         "  \`postgres: service_healthy\` says the server accepts connections. It says nothing about" \
         "  the role laravel-migrate authenticates as existing. Without the gate the first" \
         "  \`docker compose up\` dies with \`FATAL: role \"$PG_MIGRATE_ROLE\" does not exist\`, and" \
         "  because every Laravel and FastAPI service gates on laravel-migrate's own" \
         "  service_completed_successfully, the entire stack stops there."
else
  fail "compose.yaml declares no postgres-roles service" \
    "  Nothing creates $PG_MIGRATE_ROLE or $PG_APP_ROLE, so every container connects as the" \
    "  POSTGRES_USER superuser and audit_logs is not append-only at the database. See" \
    "  infrastructure/docker/postgres/roles/apply-roles.sh."
fi

# No runtime service may mount the SUPERUSER password. Two legitimate consumers: `postgres` itself
# and the `postgres-roles` one-shot. Anything else holding it makes the whole split decorative.
super_holders="$(awk '
  /^  [a-z0-9-]+:$/ { svc = $0; sub(/^  /, "", svc); sub(/:$/, "", svc) }
  /secrets:/,/\]/   { if ($0 ~ /postgres_password[,\]]/ && svc != "") print svc }
' "$DOCKER_DIR/compose.yaml" | sort -u | grep -vE '^(postgres|postgres-roles)$' || true)"
if [[ -n "$super_holders" ]]; then
  fail "these services mount the POSTGRES_USER superuser password: $(echo "$super_holders" | paste -sd' ' -)" \
    "  A superuser bypasses every ACL check. Any service holding this credential can UPDATE and" \
    "  DELETE audit rows no matter what the ACL says, which is exactly the state the role split" \
    "  exists to leave. Runtime services mount postgres_app_password; laravel-migrate and the" \
    "  scheduler's DDL connection mount postgres_migrate_password."
else
  ok "only postgres and postgres-roles mount the superuser password"
fi

# ── (b) LIVE CHECKS ──────────────────────────────────────────────────────────────────────────────
PG_LIVE_SKIPPED=0
if ! docker inspect -f '{{.State.Running}}' "$PG_CONTAINER_NAME" 2>/dev/null | grep -q true; then
  PG_LIVE_SKIPPED=1
  warn "$PG_CONTAINER_NAME is not running — the four LIVE assertions below did not run:"
  warn "  both roles exist / neither is a superuser / the app role has no CREATE on schema public /"
  warn "  the app role holds no UPDATE, DELETE or TRUNCATE on audit_logs or any partition."
  warn "  These are the assertions that actually prove append-only. Start postgres and re-run:"
  warn "    cd $DOCKER_DIR && docker compose up -d postgres && make preflight"
else
  pgq() {
    docker exec -i "$PG_CONTAINER_NAME" psql -tAq --no-password --no-psqlrc \
      -U "${POSTGRES_USER:-knowledgebot}" -d "${POSTGRES_DB:-knowledgebot}" -c "$1" 2>/dev/null || true
  }
  n_roles="$(pgq "SELECT count(*) FROM pg_roles WHERE rolname IN ('$PG_MIGRATE_ROLE','$PG_APP_ROLE')")"
  if [[ "$n_roles" != "2" ]]; then
    fail "the split roles do not both exist (found ${n_roles:-?} of 2)" \
      "  The compose files name $PG_APP_ROLE and $PG_MIGRATE_ROLE; the cluster does not have them." \
      "  \`docker compose up\` would bring every Laravel and FastAPI container up against" \
      "  \`FATAL: role \"$PG_APP_ROLE\" does not exist\`. THE ORDER IS NOT SYMMETRIC — run the roles" \
      "  script FIRST, then recreate the containers:" \
      "    ./scripts/ops/postgres-roles.sh" \
      "  (Roles first is safe: in between, containers still connect as the superuser, which works.)"
  else
    supers="$(pgq "SELECT coalesce(string_agg(rolname,' '),'') FROM pg_roles
                    WHERE rolname IN ('$PG_MIGRATE_ROLE','$PG_APP_ROLE') AND rolsuper")"
    if [[ -n "${supers// /}" ]]; then
      fail "these split roles are SUPERUSERS: $supers" \
        "  A superuser bypasses every ACL check, so audit_logs' REVOKE is decoration — and every" \
        "  ACL assertion in the Pest suite STILL PASSES, because it reads pg_class.relacl rather" \
        "  than attempting the write. That combination (correct ACL, ineffective control, green" \
        "  suite) is the precise state this whole change exists to leave." \
        "  Repair: ALTER ROLE <name> NOSUPERUSER, or re-run ./scripts/ops/postgres-roles.sh"
    else
      ok "$PG_MIGRATE_ROLE and $PG_APP_ROLE exist and neither is a superuser"
    fi

    if [[ "$(pgq "SELECT has_schema_privilege('$PG_APP_ROLE','public','CREATE')")" == "f" ]]; then
      ok "$PG_APP_ROLE has no CREATE on schema public"
    else
      fail "$PG_APP_ROLE holds CREATE on schema public" \
        "  It could then create a table, OWN it, and an owner may GRANT itself anything on what it" \
        "  owns. Re-run ./scripts/ops/postgres-roles.sh — it revokes this."
    fi

    # THE SWEEP. Parent AND every partition, walked from pg_inherits.
    #
    # WHY THE PARTITIONS ARE THE POINT: privileges are NOT inherited through the partition
    # hierarchy. A query routed through the parent checks only the parent, but `UPDATE
    # audit_logs_2026_08 SET ...` checks the partition. And the app role's default privileges
    # GRANT UPDATE/DELETE, so EVERY NEW PARTITION ARRIVES WRITABLE — measured: a partition created
    # after the parent's revoke came out `kb_app=arwd/kb_migrate`. Only the per-partition REVOKE in
    # EloquentAuditLogPartitionRepository takes it away, which makes this the check that catches the
    # one failure mode the design genuinely has.
    if [[ "$(pgq "SELECT to_regclass('public.$AUDIT_PARENT') IS NULL")" == "t" ]]; then
      warn "$AUDIT_PARENT does not exist in this cluster yet — the write-privilege sweep found"
      warn "  nothing to check. Re-run after \`artisan migrate\`."
    else
      audit_offenders="$(pgq "
        SELECT coalesce(string_agg(rel || '=' || privs, ' '), '')
          FROM (
            SELECT c.oid::regclass::text AS rel,
                   (SELECT string_agg(DISTINCT a.privilege_type, ',' ORDER BY a.privilege_type)
                      FROM aclexplode(c.relacl) a
                     WHERE a.grantee = (SELECT oid FROM pg_roles WHERE rolname='$PG_APP_ROLE')
                       AND a.privilege_type IN ('UPDATE','DELETE','TRUNCATE')) AS privs
              FROM pg_class c
             WHERE c.oid = to_regclass('public.$AUDIT_PARENT')
                OR c.oid IN (SELECT inhrelid FROM pg_inherits
                              WHERE inhparent = to_regclass('public.$AUDIT_PARENT'))
          ) AS s
         WHERE s.privs IS NOT NULL")"
      if [[ -n "${audit_offenders// /}" ]]; then
        fail "$PG_APP_ROLE holds write privileges on audit relations: $audit_offenders" \
          "  audit_logs is not append-only on those relations. If they are partitions created since" \
          "  the last run, the cause is a partition-creating path that did not revoke: a" \
          "  parent-only revoke does NOTHING for a partition, because privileges are not inherited" \
          "  through the hierarchy, and the app role's default privileges grant UPDATE/DELETE so" \
          "  every new partition arrives writable." \
          "  Repair: ./scripts/ops/postgres-roles.sh   (it walks pg_inherits and re-revokes)" \
          "  Then fix the creating path — App\\Repositories\\Eloquent\\EloquentAuditLogPartitionRepository" \
          "  must revoke naming the APPLICATION role, not CURRENT_USER."
      else
        n_audit="$(pgq "SELECT 1 + count(*) FROM pg_inherits WHERE inhparent = to_regclass('public.$AUDIT_PARENT')")"
        ok "${n_audit:-?} audit relation(s): $PG_APP_ROLE holds no UPDATE, DELETE or TRUNCATE"
      fi
    fi
  fi
fi

# ================================================================================================
# 8b. RUNNING CONTAINERS STILL MATCH THEIR OWN DECLARATION — service-name DNS on every network.
# ================================================================================================
# WHY THIS EXISTS, and it is a measured incident rather than a precaution. `otel-collector` declares
# `networks: [edge, observability]`, which gives it its service name as a DNS alias on BOTH. The
# RUNNING container had `aliases=[]` on `edge` while `observability` was correct — so from `web`,
# which is on `edge` only, `otel-collector` did not resolve at all:
#
#     fetch failed  code=ENOTFOUND        (from inside knowledgebot-web-1)
#
# Every browser span was therefore dropped: apps/web's telemetry route handler answered `502` for
# ~3.4s (DNS failure) or 5.0s (its own AbortSignal), the browser exporter retried forever, and the
# ONLY visible symptom was `POST /telemetry/v1/traces 502` in the web log — which reads as a broken
# Collector rather than as a container that stopped matching its own compose file. `docker compose
# config` was correct throughout, and so was every other check in this script.
#
# THE TRIGGER IS AN OPERATION THIS REPO PERFORMS. Recreating a network while containers are attached
# to it (the `edge` subnet pin does exactly that) reconnects them, and a reconnect can land without
# the aliases the original attach had. `docker compose up -d` does NOT repair it, because Compose
# sees a running container whose config hash is unchanged; only `--force-recreate` does. That is the
# gap this check closes: a declaration-vs-reality comparison nothing else in the pipeline makes.
# ================================================================================================
# 8c. THE CERTIFICATE TRAEFIK ACTUALLY SERVES — not the one the config asks for.
# ================================================================================================
# `CN = TRAEFIK DEFAULT CERT` is Traefik's self-signed fallback, served whenever no certificate
# matches. In a dev deployment that is the normal state and it BREAKS THE APP rather than warning:
# an interstitial is offered only for a top-level navigation, so accepting the warning on
# `app.<domain>` leaves the console loading while every `fetch` to `api.<domain>` fails with
# `ERR_CERT_AUTHORITY_INVALID` — no interstitial, no server-side trace, and every auth form showing
# a generic error. Asserted on the WIRE because that is the only place the answer exists: the config
# can name a certificate file that failed to load, and Traefik falls back without failing.
say "TLS certificate served at the edge"
if ! command -v openssl >/dev/null; then
  warn "openssl not installed — cannot check what certificate the edge serves."
elif ! docker inspect -f '{{.State.Running}}' knowledgebot-traefik-1 2>/dev/null | grep -q true; then
  warn "traefik is not running — cannot check what certificate the edge serves."
else
  _served="$(echo | openssl s_client -connect 127.0.0.1:443 -servername "api.${DOMAIN:-knowledgebot.example}" 2>/dev/null \
    | openssl x509 -noout -subject 2>/dev/null || true)"
  if [[ -z "$_served" ]]; then
    warn "no certificate could be read from 127.0.0.1:443 — is the websecure entrypoint published?"
  elif [[ "$_served" == *"TRAEFIK DEFAULT CERT"* ]]; then
    fail "the edge is serving Traefik's self-signed fallback certificate" \
      "  Served for api.${DOMAIN:-knowledgebot.example}: $_served" \
      "  No browser trusts it, and the failure does NOT present as a certificate warning: the" \
      "  console is a different ORIGIN from the API, so a fetch to api.<domain> fails with" \
      "  ERR_CERT_AUTHORITY_INVALID and never offers an interstitial. Every auth form then shows a" \
      "  generic error with nothing in any server log." \
      "  In DEVELOPMENT, issue a locally-trusted certificate and trust its CA on the machine" \
      "  running the browser (Windows, not WSL):" \
      "    scripts/dev/tls-dev-cert.sh" \
      "    cd $DOCKER_DIR && docker compose up -d --force-recreate traefik" \
      "  In PRODUCTION this means ACME has not issued: check that DOMAIN resolves publicly, that" \
      "  80/tcp is reachable for HTTP-01, and that CERT_RESOLVER is 'le' rather than 'le-staging'."
  else
    ok "edge serves: $_served"
  fi
fi

say "Container DNS aliases (service name resolvable on every attached network)"
if ! ALIAS_IDS="$(cd "$DOCKER_DIR" && docker compose ps -q 2>/dev/null)" || [[ -z "$ALIAS_IDS" ]]; then
  warn "no containers of this project are running — this check did not run."
  warn "  It compares each RUNNING container's DNS aliases against the networks it declares, which"
  warn "  is only answerable about a live container. Re-run after \`docker compose up -d\`."
else
  ALIAS_DRIFT=()
  while read -r _cid; do
    [[ -n "$_cid" ]] || continue
    _svc="$(docker inspect -f '{{index .Config.Labels "com.docker.compose.service"}}' "$_cid" 2>/dev/null || true)"
    [[ -n "$_svc" ]] || continue
    # One line per attached network: "<network> <alias> <alias> ...".
    while read -r _net _aliases; do
      [[ -n "$_net" ]] || continue
      # A one-shot container that has exited keeps its networks; only running ones serve DNS.
      case " $_aliases " in
        *" $_svc "*) ;;
        *) ALIAS_DRIFT+=("$_svc has no \`$_svc\` alias on $_net (aliases: ${_aliases:-none})") ;;
      esac
    done < <(docker inspect -f \
      '{{range $n,$v := .NetworkSettings.Networks}}{{$n}} {{range $v.Aliases}}{{.}} {{end}}{{"\n"}}{{end}}' \
      "$_cid" 2>/dev/null || true)
  done <<< "$ALIAS_IDS"

  if ((${#ALIAS_DRIFT[@]} > 0)); then
    fail "these running containers cannot be resolved by service name on a network they are attached to:" \
      "$(printf '    - %s\n' "${ALIAS_DRIFT[@]}")" \
      "  Any service that dials one of these by name gets ENOTFOUND, and the caller's own error is" \
      "  all you see — the dialled service logs NOTHING, because the connection never reached it." \
      "  compose.yaml is not wrong; the container drifted from it. Repair, per service:" \
      "    cd $DOCKER_DIR && docker compose up -d --no-deps --force-recreate <service>" \
      "  \`up -d\` alone will NOT fix it: the config hash is unchanged, so Compose leaves it running."
  else
    ok "every running container answers to its service name on each network it is attached to"
  fi
fi

# ================================================================================================
# 9. THE POSITIVE CONTROL. Config files being individually correct is not the same as the stack
#    rendering.
# ================================================================================================
# Everything above is an assertion about a file. This is the assertion that the thing `make deploy`
# is about to run actually resolves: every env_file: present, every ${...} interpolable, every
# secret source declared, both overlays merging.
#
# --quiet is mandatory, not tidiness: `docker compose config` renders every interpolated value IN
# FULL. Its stdout is discarded and its stderr is redacted before it reaches this terminal.
say "Production compose set renders"
TMPDIR_PF="$(mktemp -d)"
trap 'rm -rf "$TMPDIR_PF"' EXIT
chmod 700 "$TMPDIR_PF"
config_rc=0
( cd "$DOCKER_DIR" && docker compose -f compose.yaml -f compose.prod.yaml config --quiet ) \
  >/dev/null 2>"$TMPDIR_PF/config.err" || config_rc=$?

# Compose mixes one line per unset variable into the same stream as the actual error. Left
# together, thirty "variable is not set" lines bury the one line that says which env_file is
# missing — so they are split apart and reported as two different things, because they are.
grep -v 'level=warning' "$TMPDIR_PF/config.err" > "$TMPDIR_PF/config.errors" || true
sed -nE 's/.*The .?.?"?([A-Za-z0-9_]+)"?.?.? variable is not set.*/\1/p' "$TMPDIR_PF/config.err" \
  | sort -u > "$TMPDIR_PF/config.unset" || true

if [[ "$config_rc" -eq 0 ]]; then
  ok "docker compose -f compose.yaml -f compose.prod.yaml config --quiet  -> ok"
else
  fail "the production compose set does not render" \
    "  cd $DOCKER_DIR && docker compose -f compose.yaml -f compose.prod.yaml config --quiet" \
    "  exited $config_rc. \`make deploy\` runs exactly these two files and would fail the same" \
    "  way — partway through, having already recreated whatever it got to first." \
    "" \
    "  Error output (credential-shaped values redacted — re-run the command yourself for the raw" \
    "  text, and do not paste that into a ticket):" \
    "$(redact < "$TMPDIR_PF/config.errors" | head -20 | sed 's/^/    /')"
fi

# Unset variables are only worth reporting separately when a .env exists; without one, "everything
# interpolates to empty" was already the first finding and this would just repeat it at length.
# With one, this is compose's own detection covering the variables the explicit checks above do
# not know about — POSTGRES_DB, POSTGRES_USER, a new one added next month. Names only: an unset
# variable has no value to leak, but a set one would have.
if [[ "$ENV_OK" -eq 1 && -s "$TMPDIR_PF/config.unset" ]]; then
  fail "$(wc -l < "$TMPDIR_PF/config.unset" | tr -d ' ') compose variable(s) are unset and have no default" \
    "  $(tr '\n' ' ' < "$TMPDIR_PF/config.unset")" \
    "" \
    "  Each one interpolates to the EMPTY STRING and the deploy proceeds. Compose says so once," \
    "  on stderr, mixed into the pull output, and nobody reads it. Depending on where the variable" \
    "  lands this is a router matching nothing, an image reference with no digest, or a database" \
    "  name that is the empty string. Set them in $ENV_FILE."
fi

# ================================================================================================
# Report
# ================================================================================================
if [[ ${#FAILURES[@]} -eq 0 ]]; then
  say "PREFLIGHT PASSED"
  cat <<'EOF'
    What this did NOT prove, and what you still owe the deployment:

      * That the S3 gateway is closed. identities.json being correct is a necessary condition,
        not the assertion. After `make deploy`, an anonymous ListBuckets must return 403:
            aws --endpoint-url http://127.0.0.1:8333 --no-sign-request s3 ls
      * That ai-api, Horizon, Qdrant, PostgreSQL, Valkey and the S3 gateway are unroutable. Curl
        the public IP with a spoofed Host header for each; a config review is not a substitute.
      * That the backup restores. scripts/ops/restore-drill.md, on a schedule, not after an
        incident.
EOF
  exit 0
fi

printf '\n\033[31m==> PREFLIGHT FAILED — %d finding(s). NOT DEPLOYING.\033[0m\n' "${#FAILURES[@]}"
i=0
for f in "${FAILURES[@]}"; do
  i=$((i + 1))
  printf '\n\033[31m[%d] %s\033[0m\n' "$i" "${f%%$'\n'*}"
  printf '%s\n' "${f#*$'\n'}"
done
cat <<'EOF'

  Every finding above is a state in which `docker compose up -d` SUCCEEDS. That is the point:
  none of these produce an error at deploy time, several produce no log line at all, and the
  worst of them produce a wide-open object store that reports itself healthy.

  Fix them, then re-run:  make preflight
EOF
exit 1
