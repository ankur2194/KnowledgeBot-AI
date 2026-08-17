#!/usr/bin/env bash
# scripts/dev/tls-dev-cert.sh — a locally-trusted TLS certificate for the DEVELOPMENT stack.
#
# ── WHY THIS EXISTS ─────────────────────────────────────────────────────────────────────────────────
#
# Traefik obtains certificates by ACME HTTP-01, which requires Let's Encrypt to reach the host over the
# public internet at the name being certified. `DOMAIN` in a development deployment is
# `knowledgebot.example` — a name reserved by RFC 2606 that resolves only through a hosts-file entry on
# the operator's own machine. ACME therefore CANNOT issue for it, ever, and Traefik falls back to
# `CN = TRAEFIK DEFAULT CERT`, a self-signed certificate no browser trusts.
#
# That fallback is not merely a warning to click past, and this is the part that cost a debugging
# session: an interstitial is only offered for a TOP-LEVEL NAVIGATION. The admin console is on
# `app.<domain>` and the API is on `api.<domain>` — two origins. Accepting the warning on `app` creates
# an exception for `app` alone, so the page loads and looks healthy, and then every `fetch` to `api`
# fails with `ERR_CERT_AUTHORITY_INVALID` with NO interstitial and NO server-side trace. Measured
# symptom: `GET https://api.<domain>/sanctum/csrf-cookie net::ERR_CERT_AUTHORITY_INVALID` in the
# console, a failed preflight in the network tab, and every auth form showing a generic error.
#
# So this script issues one certificate covering all four hostnames, signed by a local CA the operator
# trusts once. It is the only way this deployment can have TLS a browser accepts.
#
# ── WHY IT IS NOT WIRED INTO THE BASE COMPOSE FILE ──────────────────────────────────────────────────
#
# `compose.override.yaml` — the DEV overlay — is what mounts the output. Production (`compose.yaml`
# alone, or with `compose.prod.yaml`) never mounts it, so a hand-made certificate cannot displace ACME
# on a real domain even if these files are present on disk. Same reasoning as mailpit's placement:
# a dev-only artifact belongs where a production render cannot reach it, not behind a profile someone
# has to remember to exclude.
#
# ── IDEMPOTENT, AND DELIBERATELY SO FOR THE CA ──────────────────────────────────────────────────────
#
# The CA is REUSED when it already exists, because trusting it is a manual step on the host and
# regenerating it silently would break trust that appeared to be working. The leaf is always reissued
# (it is cheap, and the SAN list follows DOMAIN/WIDGET_DOMAIN, which change). Pass --force-ca to
# replace the CA too — after which the host trust step must be repeated.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER_DIR="$REPO_ROOT/infrastructure/docker"
CERT_DIR="$DOCKER_DIR/traefik/certs"

say()  { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[33m    %s\033[0m\n' "$*"; }
ok()   { printf '    %s\n' "$*"; }
die()  { printf '\033[31mFATAL %s\033[0m\n' "$*" >&2; exit 1; }

FORCE_CA=0
[[ "${1:-}" == "--force-ca" ]] && FORCE_CA=1

command -v openssl >/dev/null || die "openssl is not installed."

# DOMAIN and WIDGET_DOMAIN are read from the Compose interpolation file rather than re-declared, so the
# SAN list cannot disagree with the hostnames Traefik actually routes.
[[ -f "$DOCKER_DIR/.env" ]] || die "$DOCKER_DIR/.env not found — run scripts/dev/bootstrap.sh first."
DOMAIN="$(grep -E '^DOMAIN=' "$DOCKER_DIR/.env" | tail -1 | cut -d= -f2-)"
WIDGET_DOMAIN="$(grep -E '^WIDGET_DOMAIN=' "$DOCKER_DIR/.env" | tail -1 | cut -d= -f2-)"
[[ -n "$DOMAIN" ]] || die "DOMAIN is empty in $DOCKER_DIR/.env"
[[ -n "$WIDGET_DOMAIN" ]] || die "WIDGET_DOMAIN is empty in $DOCKER_DIR/.env"

say "Development TLS certificate for *.$DOMAIN and $WIDGET_DOMAIN"
mkdir -p "$CERT_DIR"
chmod 700 "$CERT_DIR"

CA_KEY="$CERT_DIR/dev-ca.key"
CA_CRT="$CERT_DIR/dev-ca.crt"
LEAF_KEY="$CERT_DIR/dev-leaf.key"
LEAF_CRT="$CERT_DIR/dev-leaf.crt"
DYNAMIC="$CERT_DIR/dev-tls.yaml"

# ── The CA ──────────────────────────────────────────────────────────────────────────────────────────
if [[ -f "$CA_KEY" && -f "$CA_CRT" && $FORCE_CA -eq 0 ]]; then
  ok "reusing the existing CA ($CA_CRT) — host trust is preserved"
else
  [[ $FORCE_CA -eq 1 ]] && warn "--force-ca: replacing the CA. The host trust step MUST be repeated."

  # A COMPLETE `-config`, NOT `-addext`, AND THE REASON IS A BUG THIS SCRIPT SHIPPED ONCE.
  # `openssl req -x509` already applies the `x509_extensions` section named by the system
  # openssl.cnf (Ubuntu's defines `v3_ca`, which sets basicConstraints). Adding
  # `-addext basicConstraints=...` on top APPENDS a second copy, and a certificate with a duplicate
  # extension is malformed under RFC 5280 §4.2 — OpenSSL then refuses to use it as an issuer:
  #     verify error:num=20:unable to get local issuer certificate
  # The generated CA looked completely normal (`openssl x509 -subject` printed the right name) and
  # the script reported success. Supplying the whole config makes the extension set exactly what is
  # written here, on any distro, with nothing inherited.
  CA_CNF="$(mktemp)"
  cat > "$CA_CNF" <<'CNF'
[req]
distinguished_name = dn
x509_extensions    = v3_ca
prompt             = no

[dn]
CN = KnowledgeBot AI local development CA
O  = KnowledgeBot AI
OU = development only

[v3_ca]
basicConstraints     = critical,CA:TRUE,pathlen:0
keyUsage             = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
CNF
  openssl req -x509 -newkey rsa:4096 -sha256 -days 3650 -nodes \
    -keyout "$CA_KEY" -out "$CA_CRT" -config "$CA_CNF" 2>/dev/null
  rm -f "$CA_CNF"

  # A duplicate extension is invisible to `-subject`, so assert the shape rather than the name.
  if [[ "$(openssl x509 -in "$CA_CRT" -noout -text | grep -c 'X509v3 Basic Constraints')" != "1" ]]; then
    die "the generated CA has a duplicated Basic Constraints extension — it cannot sign anything."
  fi
  ok "issued a new CA valid for 10 years"
fi

# ── The leaf ────────────────────────────────────────────────────────────────────────────────────────
# `localhost` and 127.0.0.1 are included because the published ports are reachable that way too, and a
# certificate that covers only the routed names makes a direct port check fail for a reason unrelated
# to whatever is being debugged.
SAN="DNS:$DOMAIN,DNS:*.$DOMAIN,DNS:$WIDGET_DOMAIN,DNS:*.$WIDGET_DOMAIN,DNS:localhost,IP:127.0.0.1"

# 825 days is the CA/Browser-Forum maximum browsers accept for a leaf; going over it is how a
# "correctly issued" certificate gets rejected for a reason the error text does not mention.
openssl req -newkey rsa:2048 -sha256 -nodes -keyout "$LEAF_KEY" -out "$CERT_DIR/dev-leaf.csr" \
  -subj "/CN=$DOMAIN/O=KnowledgeBot AI/OU=development only" 2>/dev/null

openssl x509 -req -in "$CERT_DIR/dev-leaf.csr" -CA "$CA_CRT" -CAkey "$CA_KEY" \
  -CAcreateserial -out "$LEAF_CRT" -days 825 -sha256 \
  -extfile <(printf 'subjectAltName=%s\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid,issuer\n' "$SAN") 2>/dev/null
rm -f "$CERT_DIR/dev-leaf.csr"

# ── THE GATE. Never report success without verifying the chain. ──────────────────────────────────────
# The duplicate-extension bug above produced files that every `openssl x509 -subject`/`-issuer` probe
# described correctly while no client could build a path to them. Traefik served the leaf happily and
# the browser said `ERR_CERT_AUTHORITY_INVALID` — the same message as having done nothing at all, which
# is the worst possible outcome for a script whose entire job is to remove that message.
if ! VERIFY_OUT="$(openssl verify -CAfile "$CA_CRT" "$LEAF_CRT" 2>&1)"; then
  printf '%s\n' "$VERIFY_OUT" | sed 's/^/    /'
  die "the leaf does not verify against the CA. Re-run with --force-ca to reissue both."
fi
ok "chain verifies: leaf -> CA (openssl verify)"

# Traefik runs as a non-root user in the image and reads these read-only; 644 on the certificate and
# 600 on the keys is the narrowest pair that still lets the container read the chain.
chmod 600 "$CA_KEY" "$LEAF_KEY"
chmod 644 "$CA_CRT" "$LEAF_CRT"
ok "issued a leaf for: $SAN"

# ── The Traefik dynamic snippet ─────────────────────────────────────────────────────────────────────
# Generated rather than tracked so that it cannot exist without the certificates it names — a dangling
# `certFile` makes Traefik log a load error and serve the default certificate, which is the exact
# failure this script exists to remove.
cat > "$DYNAMIC" <<YAML
# GENERATED by scripts/dev/tls-dev-cert.sh — do not edit; re-run the script instead.
#
# Mounted ONLY by compose.override.yaml (the dev overlay), so a production render cannot see it and
# ACME stays the only certificate source there. \`stores.default.defaultCertificate\` is what answers
# a request whose router has no \`tls.certresolver\` — which, in dev, is all of them.
tls:
  stores:
    default:
      defaultCertificate:
        certFile: /etc/traefik/certs/dev-leaf.crt
        keyFile: /etc/traefik/certs/dev-leaf.key
  certificates:
    - certFile: /etc/traefik/certs/dev-leaf.crt
      keyFile: /etc/traefik/certs/dev-leaf.key
YAML
chmod 644 "$DYNAMIC"
ok "wrote $DYNAMIC"

say "Trust the CA on the machine running the BROWSER"
warn "The stack is reached from Windows, so the CA must be trusted THERE — trusting it inside WSL"
warn "does nothing for a Windows browser. Copy this file out and import it:"
ok ""
ok "  cp $CA_CRT /mnt/c/Users/\$USER/Desktop/knowledgebot-dev-ca.crt"
ok ""
ok "Then, in PowerShell AS ADMINISTRATOR:"
ok "  Import-Certificate -FilePath \$HOME\\Desktop\\knowledgebot-dev-ca.crt \\"
ok "    -CertStoreLocation Cert:\\LocalMachine\\Root"
ok ""
ok "Or by hand: double-click -> Install Certificate -> Local Machine -> Place all certificates in"
ok "the following store -> Trusted Root Certification Authorities."
ok ""
warn "Firefox keeps its OWN trust store and ignores the Windows one: about:config ->"
warn "security.enterprise_roots.enabled = true, or import the CA under Settings -> Certificates."
ok ""
ok "Then restart Traefik so it picks up the new dynamic file:"
ok "  cd $DOCKER_DIR && docker compose up -d --force-recreate traefik"
ok ""
ok "Verify (should print the CA above, not 'TRAEFIK DEFAULT CERT'):"
ok "  echo | openssl s_client -connect 127.0.0.1:443 -servername api.$DOMAIN 2>/dev/null \\"
ok "    | openssl x509 -noout -subject -issuer"
