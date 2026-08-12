#!/usr/bin/env bash
# =================================================================================================
# credential-file-scan.sh — the scanner that names the credential files by path.
# =================================================================================================
#
# WHY THIS EXISTS, AND WHY IT COULD NOT BE A `grep -r` OVER THE REPO.
#
# Two files under infrastructure/docker/ hold live, generated credentials on every developer's
# disk and on every deployment:
#
#     infrastructure/docker/valkey/users.acl          three SHA-256 Valkey ACL password hashes
#     infrastructure/docker/seaweedfs/identities.json two 44-char S3 secret keys
#
# Until 2026-08-11 (#66) neither was gitignored, which meant `git add infrastructure/` committed
# both. #66 closed that by adding .gitignore rules and `.example` templates. Closing it created a
# second, quieter problem, and this script is the answer to it:
#
#   * `gitleaks git --log-opts=--all` walks COMMITS. These files are untracked, so it sees nothing.
#   * `gitleaks dir .` walks the WORKTREE — but `actions/checkout` never materialises an ignored,
#     untracked file, so on a CI runner the files are not there to scan. CI is green because the
#     bytes are absent, not because they are safe.
#   * A repo-root `grep -r` is worse than useless. On the maintainer's shell `grep` is a FUNCTION
#     shimming to `ugrep --ignore-files`, which honours .gitignore when the search root is the
#     repo root. Measured 2026-08-11 on the live 44-char S3 secret key:
#
#         search root `.`          /usr/bin/grep -rl -> 2 files      shimmed grep -rl -> 0 files
#         search root `infrastructure/docker/`      -> 28            -> 28
#
#     So a sweep written with the shell's `grep`, run from the repo root, returns a clean result
#     for exactly the files a sweep exists to find, and the asymmetry means it looks correct under
#     one spot-check and is blind under another. (Filed as #102.)
#
# THIS SCRIPT THEREFORE:
#   * hard-codes /usr/bin/grep and refuses to run if that is not a real binary;
#   * ENUMERATES the credential files by explicit path rather than discovering them by walking,
#     because a walk is what .gitignore filters;
#   * runs a MANDATORY POSITIVE CONTROL — it asserts it can find a value it knows lives inside an
#     ignored file. If that control returns zero the script FAILS LOUD rather than reporting a
#     clean tree, which is the #102 failure mode made visible.
#
# It never prints a secret. Every diagnostic names a file and a sha256 prefix.
#
# Ownership: platform-devops-engineer. Operational glue only — it reads files and asks git
# questions. It touches no datastore. See scripts/README.md for the boundary.
#
# TWO MODES, BECAUSE ONE OF THE CHECKS ABOVE IS STRUCTURALLY IMPOSSIBLE ON A RUNNER (#110).
#
# `gitleaks dir .` in ci.yml's `secret-scan` is green on every run, and it is green because the
# BYTES ARE ABSENT, not because they are safe: `actions/checkout` never materialises an ignored,
# untracked file. `gitleaks git --log-opts=--all` is blind for a different reason — these files have
# never been committed. So CI has no coverage of the credential files at all, and the green check
# says otherwise.
#
# Running THIS script unchanged on a runner does not fix that. Simulated against a fresh `git init`
# holding only the tracked files, it exits non-zero with four errors: the enumeration, the
# extraction and both controls all report they had nothing to work on. Correct, and permanently red.
#
# The split is therefore explicit rather than inferred:
#
#   KB_SCAN_MODE=full        (default) every check. Requires the credential files to be present —
#                            a developer machine, a deploy host, or scripts/ops/preflight.sh.
#   KB_SCAN_MODE=structural  only the arms that need no credential BYTES: the .gitignore rules, the
#                            untracked assertions, and the placeholder templates. `git check-ignore`
#                            and `git ls-files` answer for a path whether or not the file exists
#                            (verified 2026-08-11), which is precisely why these arms survive a
#                            runner and check 2 does not. Check 2 is SKIPPED AND SAID SO, never
#                            silently satisfied.
#
# The mode is not auto-detected from whether the files happen to exist. That would make a developer
# who has not run bootstrap.sh get the weaker scan and a PASS, which is the #110 failure one layer
# down: a check that quietly degrades to the subset it can perform.
#
# USAGE
#     bash scripts/security/credential-file-scan.sh          # from the repo root
#     KB_SCAN_STRICT_MISSING=1 bash scripts/security/...     # a missing credential file is a
#                                                            # failure, not a skip (deploy hosts)
#     KB_SCAN_MODE=structural bash scripts/security/...      # CI: the runner-safe arms only
#
# EXIT
#     0  every check passed
#     1  at least one check failed (see the ::error:: lines; the format is GitHub's)
#     2  the script cannot run honestly (no /usr/bin/grep, no python3, not a git worktree,
#        or an unknown KB_SCAN_MODE)
# =================================================================================================

# `set -uo pipefail`, deliberately WITHOUT `-e`, which is the one place this file departs from
# scripts/README.md's conventions. Every check below is a grep or a git query whose non-match is a
# normal result; under `set -e` the first one aborts the run and the remaining checks never execute,
# so a single early finding would hide every later one. Failures ACCUMULATE into `$fail` instead —
# the same discipline gates.yml states as "ACCUMULATE, never `! grep`". The preconditions above the
# accumulator still exit immediately, because they make the whole scan dishonest rather than
# incomplete.
set -uo pipefail

# -------------------------------------------------------------------------------------------------
# Preconditions. Each of these makes the whole scan dishonest rather than merely incomplete, so
# they exit 2 immediately instead of accumulating — a partially-blind scanner that prints "PASS"
# at the end is the thing this file exists to prevent.
# -------------------------------------------------------------------------------------------------
GREP=/usr/bin/grep
[ -x "$GREP" ] || { echo "::error::$GREP is not an executable. This scan must not use the shell's \`grep\`: see #102 — it may be a .gitignore-honouring shim, and every file below is gitignored."; exit 2; }
command -v python3 >/dev/null 2>&1 || { echo "::error::python3 is required (stdlib only: json, hashlib, re)."; exit 2; }
git rev-parse --show-toplevel >/dev/null 2>&1 || { echo "::error::not inside a git worktree; the tracked/ignored assertions cannot be evaluated."; exit 2; }

ROOT=$(git rev-parse --show-toplevel)
cd "$ROOT" || exit 2

fail=0
flag() { echo "::error::$1"; fail=1; }
note() { echo "  $1"; }

STRICT_MISSING=${KB_SCAN_STRICT_MISSING:-0}

MODE=${KB_SCAN_MODE:-full}
case "$MODE" in
  full|structural) ;;
  *) echo "::error::KB_SCAN_MODE='$MODE' is not one of: full, structural. Refusing to guess — a scanner that falls back to the weaker mode on a typo is the #110 defect it exists to fix."; exit 2 ;;
esac
# In structural mode `git check-ignore` carries checks 1 and 3 on its own, and a `git check-ignore`
# that answered "ignored" for everything would make both pass while proving nothing. This is the
# discriminating control, and it runs in BOTH modes because it costs nothing: a path that is
# committable by construction must be reported NOT ignored. This file is the obvious choice — if it
# is ever gitignored, nothing below ran on a fresh clone anyway.
if git check-ignore -q -- scripts/security/credential-file-scan.sh; then
  echo "::error::CONTROL (check-ignore discrimination) FAILED: git reports this script itself as gitignored. Either the repository's ignore rules have swallowed the tree, or check-ignore is answering 'ignored' unconditionally — in which case check 1 below passes on every input and proves nothing."
  exit 2
fi

# -------------------------------------------------------------------------------------------------
# THE ENUMERATION. Explicit paths, never a walk.
#
# Two lists, because the two properties are different:
#
#   CRED_FILES   files whose CONTENT is a live secret. Their values are extracted and searched for
#                across the tracked tree. Adding a file here is how its secrets get covered.
#   IGNORE_ONLY  files that must be gitignored and untracked but whose values are NOT extracted.
#                env/*.env and .env are here deliberately: by design every secret in them is a
#                `_FILE=/run/secrets/...` POINTER, not a value. Re-check that claim rather than
#                trusting this comment —
#                  /usr/bin/grep -hE '^[A-Z_]*(PASSWORD|SECRET|KEY|TOKEN)[A-Z_]*=' \
#                      infrastructure/docker/env/*.env infrastructure/docker/.env \
#                    | /usr/bin/grep -vE '=(/run/secrets/|$)'
#                — measured 2026-08-11 the only non-pointer hit is GRAFANA_ADMIN_PASSWORD=admin,
#                a literal dev default. The day that stops being true, move the file up one list.
# -------------------------------------------------------------------------------------------------
CRED_FILES=(
  infrastructure/docker/valkey/users.acl
  infrastructure/docker/seaweedfs/identities.json
)
# Every generated per-secret file. Enumerated by glob and then filtered, because `secrets/` is one
# directory whose whole purpose is to hold secrets — a name-by-name list there would go stale on
# the next rotation and fail open.
for f in infrastructure/docker/secrets/*; do
  case "${f##*/}" in
    .gitkeep|'*') continue ;;
  esac
  [ -f "$f" ] || continue
  [ -s "$f" ] || { note "skipped (zero bytes, nothing to leak): $f"; continue; }
  CRED_FILES+=("$f")
done

IGNORE_ONLY=(
  infrastructure/docker/.env
)
for f in infrastructure/docker/env/*.env; do
  [ -f "$f" ] && IGNORE_ONLY+=("$f")
done

# The `.example` templates. These are TRACKED and must stay placeholder-only — they are the most
# likely place for a live value to land, because bootstrap.sh writes the live file *from* them and
# the two sit side by side in the same directory with adjacent names.
TEMPLATES=(
  infrastructure/docker/valkey/users.acl.example
  infrastructure/docker/seaweedfs/identities.json.example
)

echo "== credential-file-scan =="
note "mode:               $MODE"
note "grep binary:        $GREP"
note "credential files:   ${#CRED_FILES[@]}"
note "ignore-only files:  ${#IGNORE_ONLY[@]}"

# Non-vacuity on the enumeration itself. An empty or truncated list is a scan that passes by
# checking nothing, which is indistinguishable from a clean tree in the exit code.
#
# The floor differs by mode and the difference is not a relaxation. `secrets/*` is a GLOB, and on a
# runner it expands to nothing because actions/checkout never materialises an ignored file — so in
# structural mode the floor of 3 would be asserting that a directory whose contents cannot exist
# is non-empty. The two files that are enumerated BY NAME are asserted in both modes, immediately
# below, and structural mode adds an assertion the glob cannot make: the secrets/ DIRECTORY RULE
# itself, which is checkable with no file present at all.
if [ "$MODE" = "full" ]; then
  [ "${#CRED_FILES[@]}" -ge 3 ] \
    || flag "only ${#CRED_FILES[@]} credential file(s) enumerated; the list is truncated and the scan below is near-vacuous"
else
  [ "${#CRED_FILES[@]}" -ge 2 ] \
    || flag "only ${#CRED_FILES[@]} credential file(s) enumerated; even the two named paths are missing from the list"
  # A path that cannot exist here, under the directory whose whole purpose is to hold secrets.
  # `git check-ignore` answers for a path, not for a file, so this asserts the RULE — which is the
  # only thing about `secrets/` a runner can assert, and the thing that matters: if that rule ever
  # goes away, the next `git add -A` on a developer machine stages every generated secret.
  git check-ignore -q -- infrastructure/docker/secrets/probe-that-does-not-exist \
    || flag "infrastructure/docker/secrets/ is NOT covered by a .gitignore rule; every per-secret file bootstrap.sh writes there would be staged by \`git add -A\` (#66)"
fi
for required in infrastructure/docker/valkey/users.acl infrastructure/docker/seaweedfs/identities.json; do
  printf '%s\n' "${CRED_FILES[@]}" | $GREP -qxF "$required" \
    || flag "$required is not in the enumeration; this scanner exists specifically to cover it"
done

# -------------------------------------------------------------------------------------------------
# CHECK 1 — every credential file is gitignored AND untracked.
#
# Both halves, because they fail independently: `git check-ignore` says nothing about a file that
# is ALREADY tracked (gitignore does not apply to tracked paths, so a rule added after the fact is
# silently inert), and `git ls-files` says nothing about whether the next `git add -A` will stage
# it. #66 is only closed while both hold.
#
# NEITHER HALF NEEDS THE FILE TO EXIST, AND THIS BLOCK USED TO `continue` PAST AN ABSENT ONE — which
# is #110 one layer down: on a runner every credential path is absent, so the loop would skip every
# path and check 1 would report a clean scan having asserted nothing. Both are PATH questions and
# git answers them for a path that is not on disk (verified 2026-08-11: `git check-ignore -v` names
# .gitignore:95 for identities.json whether or not the file is there). So the git assertions now run
# unconditionally; only the "does it exist" question is conditional, and that is what
# KB_SCAN_STRICT_MISSING is for.
# -------------------------------------------------------------------------------------------------
echo "-- check 1: gitignored and untracked"
for f in "${CRED_FILES[@]}" "${IGNORE_ONLY[@]}"; do
  if [ ! -e "$f" ]; then
    if [ "$STRICT_MISSING" = "1" ]; then
      flag "$f does not exist (KB_SCAN_STRICT_MISSING=1)"
    elif [ "$MODE" = "structural" ]; then
      note "absent (expected on a runner); the ignore and tracked assertions below still apply: $f"
    else
      note "absent: $f   (run scripts/dev/bootstrap.sh, or set KB_SCAN_STRICT_MISSING=1)"
    fi
  fi
  git check-ignore -q -- "$f" \
    || flag "$f is NOT gitignored — \`git add -A\` will stage a live credential (#66)"
  if git ls-files --error-unmatch -- "$f" >/dev/null 2>&1; then
    flag "$f is TRACKED. A .gitignore rule does not apply to an already-tracked path, so the rule covering it is inert; \`git rm --cached\` it and rotate the credential."
  fi
done

# -------------------------------------------------------------------------------------------------
# CHECK 2 — no value from any credential file appears in any TRACKED file.
#
# This is the leak that survives #66: the files themselves are ignored, but nothing stops a live
# secretKey being pasted into a README, a compose comment, a test fixture, a docs page, or — most
# likely of all — the `.example` template beside it.
#
# The extraction is per FORMAT, not a generic entropy sweep, so that what is being searched for is
# auditable rather than heuristic:
#     identities.json   every credentials[].secretKey            (accessKey is an identity, not a secret)
#     users.acl         every `#<64 hex>` token                  (a Valkey ACL password hash: publishing
#                                                                it enables an offline attack on the
#                                                                password, so it is credential material)
#     secrets/*         the whole file, per line, >= 16 chars    (one secret per file, by convention)
# -------------------------------------------------------------------------------------------------
nvalues=0
nhay=0
if [ "$MODE" != "full" ]; then

echo "-- check 2: SKIPPED (KB_SCAN_MODE=structural)"
note "Check 2 compares credential VALUES against every committable file. It needs the bytes, and on"
note "a runner actions/checkout never materialises an ignored file, so there are none to read. This"
note "is the one arm CI structurally cannot perform — SAID here rather than reported as a pass."
note "It is covered where the bytes exist: scripts/ops/preflight.sh and a developer machine."

else

echo "-- check 2: no credential value appears in a tracked file"

SECRETS_TSV=$(mktemp)
CLEANUP=("$SECRETS_TSV")
# The restore/cleanup must survive a killed run (standing rule 7): a leftover tempfile holding
# extracted secrets is itself the incident this script is about.
trap 'rm -f "${CLEANUP[@]}" 2>/dev/null; exit 130' INT TERM HUP
trap 'rm -f "${CLEANUP[@]}" 2>/dev/null' EXIT
chmod 600 "$SECRETS_TSV"

python3 - "$SECRETS_TSV" "${CRED_FILES[@]}" <<'PY'
import hashlib, json, re, sys

out_path, paths = sys.argv[1], sys.argv[2:]
rows = []

def add(value, origin, label):
    v = value.strip()
    if len(v) < 16:
        return
    rows.append((v, origin, label, hashlib.sha256(v.encode()).hexdigest()[:12]))

HEX64 = re.compile(r"#([0-9a-f]{64})\b")

for p in paths:
    try:
        raw = open(p, "r", encoding="utf-8", errors="replace").read()
    except OSError:
        continue
    name = p.rsplit("/", 1)[-1]
    if name == "identities.json":
        try:
            doc = json.loads(raw)
        except json.JSONDecodeError:
            print(f"::error::{p} is not valid JSON; its secretKeys could not be extracted and are UNSCANNED")
            continue
        for ident in doc.get("identities", []) or []:
            for cred in ident.get("credentials", []) or []:
                sk = cred.get("secretKey")
                if isinstance(sk, str):
                    add(sk, p, f"secretKey[{ident.get('name', '?')}]")
    elif name == "users.acl":
        for m in HEX64.finditer(raw):
            add(m.group(1), p, "acl-password-sha256")
    else:
        for i, line in enumerate(raw.splitlines(), 1):
            add(line, p, f"line{i}")

with open(out_path, "w", encoding="utf-8") as fh:
    for v, origin, label, digest in rows:
        fh.write("\t".join((v, origin, label, digest)) + "\n")

print(f"  extracted {len(rows)} candidate value(s) from {len(paths)} file(s)")
PY

nvalues=$(wc -l < "$SECRETS_TSV" | tr -d ' ')
[ "$nvalues" -ge 3 ] \
  || flag "extraction produced only $nvalues value(s); the search below is vacuous. Did a file format change (identities.json schema, users.acl hash syntax)?"

# THE HAYSTACK IS "COMMITTABLE", NOT "TRACKED", AND THE DIFFERENCE IS THE WHOLE SCAN TODAY.
#
# `git ls-files` alone returns 128 paths in this repo — every one of them under .claude/, docs/,
# CLAUDE.md or .gitignore — because the entire working tree is untracked pending the first commit.
# A scan whose haystack is 128 documentation files would report a clean result while a live S3 key
# sat in an uncommitted README, and would keep reporting it right up until the moment it mattered.
#
# So the haystack is everything `git add -A` would stage: tracked files PLUS untracked-and-not-
# ignored files. That is exactly the set at risk, it is correct before and after the first commit,
# and it automatically excludes the credential files themselves (they are ignored) so they cannot
# self-report.
HAY=$(mktemp); CLEANUP+=("$HAY")
{ git ls-files -z; git ls-files --others --exclude-standard -z; } | sort -zu > "$HAY"

# -------------------------------------------------------------------------------------------------
# CHECK 2b — TWO MANDATORY POSITIVE CONTROLS. They prove different things and BOTH are needed.
#
# Control A — EXTRACTION. Every extracted value must be re-findable in the file it came from. This
# proves the format parsers above returned real bytes rather than empty strings, and that the files
# are readable. It does NOT prove anything about .gitignore: measured 2026-08-11, the maintainer
# shell's `--ignore-files` shim filters during RECURSIVE TRAVERSAL only and happily searches an
# ignored file that is named explicitly on the command line. Control A names its file explicitly,
# so it passes under both greps and cannot discriminate. Saying so is the point — a control that
# claims to prove blindness and does not is worse than no control.
#
# Control B — TRAVERSAL, and this is the #102 control. It searches RECURSIVELY FROM THE REPO ROOT
# for a value known to live in an ignored credential file, and requires a hit. Measured on the live
# 44-char S3 secret key:
#     /usr/bin/grep -rlF --include='identities.json' -- <key> .   -> 1 file
#     the --ignore-files shim, same arguments                     -> 0 files
# The root matters: the shim honours .gitignore when the search root is the repo root and behaves
# normally when it is a subdirectory, which is why this control must run from the top and why a
# spot-check from `infrastructure/docker/` would look fine and prove nothing.
#
# This scanner does not itself traverse — CHECK 2 feeds `$GREP` an explicit file list, which is
# structurally immune to --ignore-files. Control B is therefore a TRIPWIRE for the next editor: the
# moment anyone "simplifies" this file to a `grep -r`, this control is what fails.
# -------------------------------------------------------------------------------------------------
control_hits=0
control_of=""
while IFS=$'\t' read -r value origin label digest; do
  [ -n "$value" ] || continue
  if $GREP -qF -- "$value" "$origin" 2>/dev/null; then
    control_hits=$((control_hits + 1))
    control_of="$origin"
  fi
done < "$SECRETS_TSV"

if [ "$nvalues" -eq 0 ]; then
  # Guarded separately, or the equality below is 0 == 0 and control A "passes" against nothing while
  # printing an empty source path. This is the state on a CI runner, where `actions/checkout` never
  # materialises an ignored file: the enumeration and extraction assertions above have already
  # failed, and saying so once is enough.
  flag "CONTROL A cannot run: nothing was extracted, so there is no value to re-find. See the enumeration failure above — on a CI runner this is expected, because actions/checkout does not materialise a gitignored file."
elif [ "$control_hits" -ne "$nvalues" ]; then
  flag "CONTROL A FAILED: only $control_hits of $nvalues extracted value(s) can be re-found in the file they came from. The extraction is producing values that are not in the source, so CHECK 2 is searching for the wrong bytes."
else
  git check-ignore -q -- "$control_of" \
    && note "control A (extraction): $control_hits/$nvalues value(s) re-found in their own source; the last was $control_of." \
    || flag "control A matched, but its last source ($control_of) is not gitignored — re-check the enumeration."
fi

# Control B. Scoped with --include so it costs milliseconds instead of walking node_modules and
# vendor; the scoping does not weaken it, because the shim's filtering happens on the traversal and
# the target file is ignored either way.
control_b_value=$(awk -F'\t' '$2 ~ /identities\.json$/ {print $1; exit}' "$SECRETS_TSV")
if [ -z "$control_b_value" ]; then
  flag "CONTROL B cannot run: no value was extracted from identities.json, so the traversal control has nothing to look for."
else
  control_b_hits=$($GREP -rlF --include='identities.json' -- "$control_b_value" . 2>/dev/null | wc -l | tr -d ' ')
  if [ "$control_b_hits" -eq 0 ]; then
    flag "CONTROL B FAILED (#102): a recursive search from the repo root found ZERO copies of a value that provably sits in infrastructure/docker/seaweedfs/identities.json. \$GREP ($GREP) is honouring .gitignore, and every credential file this scanner covers is gitignored. A clean result from this run means nothing. Use /usr/bin/grep."
  else
    note "control B (traversal, #102): recursive search from the repo root found the value in $control_b_hits ignored file(s). \$GREP does not honour .gitignore."
  fi
fi

# -------------------------------------------------------------------------------------------------
# CHECK 2 proper.
# -------------------------------------------------------------------------------------------------
leaks=0
while IFS=$'\t' read -r value origin label digest; do
  [ -n "$value" ] || continue
  hits=$(xargs -0 -a "$HAY" -r "$GREP" -l -F -- "$value" 2>/dev/null | sort -u)
  if [ -n "$hits" ]; then
    leaks=1
    while IFS= read -r h; do
      [ -n "$h" ] || continue
      flag "LIVE CREDENTIAL IN A TRACKED FILE: $h contains $origin's $label (sha256:$digest). Rotate it — a force-push does not reach forks, clones or CI caches — then remove it from $h."
    done <<< "$hits"
  fi
done < "$SECRETS_TSV"
nhay=$(tr -cd '\0' < "$HAY" | wc -c | tr -d ' ')
[ "$leaks" -eq 0 ] && note "no extracted value appears in any of $nhay committable file(s)"
# Non-vacuity on the haystack. A haystack that collapses — a broken `git ls-files`, a .gitignore
# that swallowed the tree — makes check 2 pass by searching nothing.
[ "$nhay" -ge 200 ] \
  || flag "the committable-file haystack is only $nhay path(s); check 2 searched almost nothing. Expected the whole working tree minus ignored paths."

fi   # end of check 2 (full mode only)

# -------------------------------------------------------------------------------------------------
# CHECK 3 — the `.example` templates are placeholders, and are byte-DIFFERENT from the live file.
#
# Two failure directions, and they are not the same:
#   * a template that still equals the live file  -> bootstrap never rewrote it, or someone copied
#     the live file over the template. Byte-identity is the test.
#   * a template carrying no placeholder marker   -> it may have been "helpfully" filled in.
# The marker is checked in the CREDENTIAL FIELD only, never file-wide: #66's agent found that the
# template's own README block explains the marker and therefore CONTAINS it, so a file-wide
# `grep -q CHANGE-ME` passes on a template that has been filled in everywhere except its prose.
# -------------------------------------------------------------------------------------------------
echo "-- check 3: templates are placeholders"
for t in "${TEMPLATES[@]}"; do
  live=${t%.example}
  if [ ! -e "$t" ]; then
    flag "$t is missing; bootstrap.sh renders the live file from it (#66)"
    continue
  fi
  # NOT `git ls-files --error-unmatch`. The whole working tree is untracked pending the first
  # commit, so a tracked-ness assertion fails on every template today for a reason that has nothing
  # to do with credentials — the same shape as gates.yml's `repo-artifact-consistency`, which
  # cannot go green before the first commit either (docs/22 finding F3). The durable property is
  # that the template is COMMITTABLE: not ignored, so it reaches a fresh clone. That is true now
  # and stays true after the first commit.
  if git check-ignore -q -- "$t"; then
    flag "$t is gitignored; a fresh clone would get NEITHER the live file nor its template, and bootstrap.sh has nothing to render from"
  fi
  if [ -e "$live" ] && cmp -s "$t" "$live"; then
    flag "$t is byte-identical to $live — either bootstrap.sh never rewrote the live file (it still holds placeholders and the service will not authenticate), or the live file was copied over the template (a live credential is now tracked)"
  fi
  case "${t##*/}" in
    identities.json.example)
      # Anchored to the field, not the file.
      $GREP -qE '"secretKey"[[:space:]]*:[[:space:]]*"CHANGE-ME' "$t" \
        || flag "$t has no CHANGE-ME placeholder in a \"secretKey\" field; it may have been filled in with a real key" ;;
    users.acl.example)
      # A Valkey ACL password hash is `#<64 hex>`. The placeholder is all zeroes; anything else is
      # a real hash. This is the check that catches a live users.acl copied over the template.
      real=$($GREP -oE '#[0-9a-f]{64}' "$t" | $GREP -vxE '#0{64}' | wc -l | tr -d ' ')
      [ "$real" = "0" ] \
        || flag "$t carries $real non-placeholder ACL password hash(es); the placeholder is 64 zeroes"
      zeroes=$($GREP -cE '#0{64}' "$t")
      [ "$zeroes" -ge 1 ] \
        || flag "$t carries no #<64 zeroes> placeholder at all; the check above cannot discriminate" ;;
  esac
done

# -------------------------------------------------------------------------------------------------
# Vacuity ledger. Stated so that the absence of a line is a claim someone made, not an omission.
# -------------------------------------------------------------------------------------------------
echo "-- vacuity ledger"
note "mode: $MODE"
note "check 1 reads live git state for ${#CRED_FILES[@]} + ${#IGNORE_ONLY[@]} named paths, whether or not the file is on disk; a truncated enumeration fails above, and a \`git check-ignore\` that cannot discriminate exits 2 before any of it runs."
if [ "$MODE" = "full" ]; then
  note "check 2 searches $nvalues extracted value(s) over $nhay committable path(s); a value count < 3 or a haystack < 200 fails above. Control A fails if the extraction returns bytes that are not in the source; control B fails if \$GREP honours .gitignore (#102)."
else
  note "check 2 DID NOT RUN and is not claimed: structural mode has no credential bytes to extract. The value-leak question is answered on a developer machine or by scripts/ops/preflight.sh, never here."
fi
note "check 3 compares each template against its live sibling and asserts a placeholder in the credential FIELD; the users.acl arm also asserts a placeholder exists, so it cannot pass on an empty file. In structural mode the live sibling is absent, so the byte-identity arm is inert and only the placeholder arm reports."
note "NOT covered here, deliberately: env/*.env and .env values (pointers by design — the re-measuring command is in the header), and git HISTORY (that is gitleaks' job in ci.yml's secret-scan; these files have never been committed, and \`git ls-files infrastructure/\` returning 0 is why)."

if [ "$fail" -eq 0 ]; then
  echo "== credential-file-scan: PASS =="
else
  echo "== credential-file-scan: FAIL =="
fi
exit $fail
