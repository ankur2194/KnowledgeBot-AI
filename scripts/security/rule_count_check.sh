#!/usr/bin/env bash
# scripts/security/rule_count_check.sh
#
# Assert that the vendored ruleset resolves to a NON-ZERO number of rules, before any scan runs.
#
# ================================================================================================
# WHY THIS EXISTS: A SAST SCAN THAT MATCHED NOTHING EXITS 0
# ================================================================================================
# Opengrep and Semgrep fetch registry rulesets over the network and cache them in $HOME. A CI
# container without egress, or with a cold cache, resolves to an EMPTY ruleset and exits 0 —
# a green job that scanned nothing, indistinguishable in the log from a green job that scanned
# everything. The same thing happens locally the first time someone clones the repo and the rules
# directory has not been checked out, or after a rename leaves the --config path pointing at a
# directory that exists and is empty.
#
# Vendoring the rules removes the network dependency. This script removes the rest: it makes the
# "zero rules" case fail loudly instead of passing silently.
#
# Run it FIRST, always:
#     scripts/security/rule_count_check.sh
#     opengrep scan --config scripts/security/rules/ \
#       --baseline-commit "$(git merge-base origin/main HEAD)" --error
#
# Exit codes:
#   0  the ruleset parses and contains at least $MIN_RULES rules
#   1  too few rules, or a file that does not parse
#   2  the check could not run (no rules directory, no YAML parser available)
#
# 2 is deliberately distinct from 0, for exactly the same reason the script exists: a check that
# could not run must never be mistaken for a check that passed.

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
RULES_DIR="${1:-$SCRIPT_DIR/rules}"

# The floor is the number of rules we have deliberately written, minus nothing. Raise it when rules
# are added. A floor of 1 would pass on a directory containing one stray file, which is most of the
# failure it is meant to catch.
MIN_RULES="${MIN_RULES:-20}"
MIN_FILES="${MIN_FILES:-5}"

if [[ ! -d "$RULES_DIR" ]]; then
  echo "FATAL rules directory not found: $RULES_DIR" >&2
  echo "      A missing --config path does not fail a scan; it silently scans with no rules." >&2
  exit 2
fi

if ! command -v python3 >/dev/null 2>&1; then
  echo "FATAL python3 not available; cannot parse the rule files to count them." >&2
  exit 2
fi

python3 - "$RULES_DIR" "$MIN_RULES" "$MIN_FILES" <<'PY'
import sys
from pathlib import Path

rules_dir = Path(sys.argv[1])
min_rules = int(sys.argv[2])
min_files = int(sys.argv[3])

try:
    import yaml
except ImportError:
    print(
        "FATAL PyYAML not available; cannot parse the rule files.\n"
        "      Refusing to fall back to `grep -c 'id:'` — a comment or a metadata key containing\n"
        "      'id:' would inflate the count and the check would pass on a ruleset that does not\n"
        "      parse, which is the exact failure this script exists to catch.",
        file=sys.stderr,
    )
    raise SystemExit(2)

files = sorted(p for p in rules_dir.rglob("*.y*ml"))
if not files:
    print(f"FATAL no .yaml/.yml rule files under {rules_dir}", file=sys.stderr)
    raise SystemExit(1)

# No PEP 585 annotations here. This is the one script in the repo that may run on whatever
# `python3` an operator's box happens to have, including 3.8 — and a TypeError from a type hint
# would make the ruleset check fail for a reason that has nothing to do with the ruleset.
total = 0
ids = set()
problems = []

for path in files:
    try:
        doc = yaml.safe_load(path.read_text(encoding="utf-8"))
    except yaml.YAMLError as exc:
        # A rule file that does not parse is SKIPPED by the engine, not reported. Every rule in it
        # silently stops running.
        problems.append(f"{path.name}: does not parse ({exc.__class__.__name__})")
        continue

    if not isinstance(doc, dict) or "rules" not in doc:
        problems.append(f"{path.name}: no top-level `rules:` key")
        continue

    rules = doc["rules"] or []
    if not rules:
        problems.append(f"{path.name}: `rules:` is empty")
        continue

    for rule in rules:
        rid = rule.get("id")
        if not rid:
            problems.append(f"{path.name}: a rule has no `id`")
            continue
        if rid in ids:
            # Duplicate ids are not an error to the engine — one definition wins, and which one
            # depends on file ordering. The losing rule stops firing with no message.
            problems.append(f"duplicate rule id across files: {rid}")
        ids.add(rid)
        if not rule.get("message"):
            problems.append(f"{rid}: no `message` — a finding nobody can act on")
        if not rule.get("severity"):
            problems.append(f"{rid}: no `severity`")
        if not rule.get("languages") and not rule.get("paths"):
            problems.append(f"{rid}: neither `languages` nor `paths` — it may match nothing")
        total += 1

print(f"rule_count_check: {total} rules across {len(files)} file(s) in {rules_dir}", file=sys.stderr)

for line in problems:
    print(f"PROBLEM {line}", file=sys.stderr)

failed = False
if len(files) < min_files:
    print(f"FAIL only {len(files)} rule file(s); expected at least {min_files}", file=sys.stderr)
    failed = True
if total < min_rules:
    print(
        f"FAIL only {total} rule(s) resolved; expected at least {min_rules}.\n"
        "     A scan against this ruleset would exit 0 while checking almost nothing.",
        file=sys.stderr,
    )
    failed = True
if problems:
    failed = True

raise SystemExit(1 if failed else 0)
PY

echo "rule_count_check: OK" >&2
