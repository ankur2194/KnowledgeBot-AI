#!/usr/bin/env python3
"""scripts/security/license_gate.py — the only check that may block a build on licence.

    python scripts/security/license_gate.py sbom/*.cdx.json

Runs twice in the lifecycle:

  * pre-merge, over LOCKFILE SBOMs, so a denied dependency never reaches main without an image
    build being needed;
  * at release, over IMAGE SBOMs, which are the only view that includes the base layer.

Neither view alone describes an artifact. For `apps/web` and `apps/widget` the bundler inlines npm
code into JS with no node_modules in the final layer, so the image SBOM lists the base image's OS
packages and essentially no npm components — generate the npm SBOM from the lockfile, the OS SBOM
from the image, and pass both.

WHY THIS BLOCKS AT ALL
----------------------
We hand images and source to self-hosters we do not vet. An AGPL, SSPL, BUSL or OpenRAIL term in a
shipped artifact is a term *they* inherit without agreeing to it. That is a legal defect, not a
lint. Two live cases already exist in this project: Surya's OCR weights (modified AI Pubs
OpenRAIL-M, with an operator-revenue cap and a competing-product clause) and OmniDocBench
(research-only — its numbers may be quoted, it may never be run in our harness).

SCOPE, AND WHY IT IS DRAWN HERE
-------------------------------
The gate reads ARTIFACT SBOMs only. A CI tool invoked as a separate process is not in a shipped
artifact, which is why hadolint (GPL-3.0) and TruffleHog (AGPL-3.0) are perfectly fine to run and
never appear in the policy file. That distinction is the whole reason the scope is artifacts rather
than "everything we execute".

EXIT CODES
----------
    0  every component reviewed and permitted
    1  at least one BLOCK
    2  the gate could not do its job (missing policy, unreadable SBOM, no inputs)

Exit 2 is deliberately distinct from 0. A gate that cannot run must never be mistaken for a gate
that passed — that is the single most common way a licence check becomes decoration.
"""

from __future__ import annotations

import json
import sys
from datetime import date
from pathlib import Path

try:
    import tomllib
except ModuleNotFoundError:  # Python < 3.11
    print(
        "FATAL license_gate.py requires Python 3.11+ for tomllib (found "
        f"{sys.version_info.major}.{sys.version_info.minor})",
        file=sys.stderr,
    )
    raise SystemExit(2)


REPO_ROOT = Path(__file__).resolve().parents[2]
POLICY = Path(__file__).resolve().parent / "policy" / "licences.toml"
MODELS = REPO_ROOT / "services" / "ai-service" / "models.manifest.toml"

HEX = set("0123456789abcdef")

# Weight containers that do NOT unpickle on load, and therefore do not need a picklescan record.
# The check this feeds used to be `format != "safetensors"`, which made ONNX weights permanently
# unfixable: RapidOCR ships .onnx, picklescan cannot meaningfully scan protobuf, so the only way
# to pass was to record a picklescan result that had not happened. A gate whose only exit is to
# lie in the manifest does not get obeyed for long.
#
# Both entries here are non-executable containers: safetensors is a length-prefixed tensor blob,
# ONNX is protobuf. NEITHER exempts the artifact from having its hash verified at image build —
# that is a different control, and it is still required (see models.manifest.toml).
# Do NOT add .bin, .pt, .pth or .ckpt: those ARE pickles, and that is the whole point.
NON_PICKLE_FORMATS = frozenset({"safetensors", "onnx"})


# ----------------------------------------------------------------------------------------------
# SBOM parsing
# ----------------------------------------------------------------------------------------------
def spdx_ids(component: dict) -> set[str]:
    """Extract SPDX identifiers from one CycloneDX component.

    CycloneDX expresses a licence in three different ways, and a component using the form you did
    not parse reads as UNKNOWN. That is why UNKNOWN must never silently pass: the most likely path
    for an AGPL package to ship is not a deny-list miss, it is a parse the gate quietly gave up on.

        {"licenses": [{"expression": "Apache-2.0 OR MIT"}]}
        {"licenses": [{"license": {"id": "MIT"}}]}
        {"licenses": [{"license": {"name": "BSD-ish, see LICENSE"}}]}   <- no SPDX id at all
    """
    ids: set[str] = set()
    for entry in component.get("licenses") or []:
        if not isinstance(entry, dict):
            continue
        if "expression" in entry:
            # A compound expression is split into its operands and EVERY operand must be
            # permitted. That is stricter than SPDX semantics for `OR`, deliberately: choosing the
            # permissive half of "AGPL-3.0 OR Commercial" is a decision with legal consequences,
            # so it is made once, by a human, as a dated exception — not implicitly by a parser.
            expr = str(entry["expression"]).replace("(", " ").replace(")", " ")
            ids.update(tok for tok in expr.split() if tok not in {"AND", "OR", "WITH"})
        elif "license" in entry and isinstance(entry["license"], dict):
            lic = entry["license"]
            ident = lic.get("id")
            if ident:
                ids.add(str(ident))
            else:
                # Free-text name Syft could not map to SPDX. Preserved as a LicenseRef- so it
                # appears in the failure message by name and can be reviewed, rather than
                # collapsing into an anonymous UNKNOWN.
                ids.add(f"LicenseRef-{lic.get('name', 'unnamed')}")
    return ids or {"UNKNOWN"}


def load_policy() -> tuple[set[str], set[str], set[str], list[str]]:
    """Return (allow, deny, waived_purls, problems)."""
    problems: list[str] = []
    if not POLICY.is_file():
        return set(), set(), set(), [f"policy file not found: {POLICY}"]

    try:
        policy = tomllib.loads(POLICY.read_text(encoding="utf-8"))
    except tomllib.TOMLDecodeError as exc:
        return set(), set(), set(), [f"policy file is not valid TOML: {exc}"]

    allow = set(policy.get("allow") or [])
    deny = set(policy.get("deny") or [])
    if not allow:
        problems.append("policy `allow` list is empty — every component would be reported as unreviewed")
    if not deny:
        problems.append("policy `deny` list is empty — the gate would permit AGPL/SSPL")

    overlap = allow & deny
    if overlap:
        # Ambiguity here is a silent policy inversion depending on evaluation order.
        problems.append(f"licence(s) in BOTH allow and deny: {sorted(overlap)}")

    waived: set[str] = set()
    today = date.today()
    for i, exc in enumerate(policy.get("exception") or []):
        purl = exc.get("purl")
        if not purl:
            problems.append(f"exception[{i}] has no `purl`")
            continue
        # AN EXCEPTION WITHOUT AN EXPIRY IS A PERMANENT HOLE NOBODY REVISITS. Missing `expires`
        # is a hard failure, not a skipped waiver: silently ignoring the entry would make the
        # build fail somewhere else entirely, and someone would "fix" it by widening the allow
        # list.
        if "expires" not in exc:
            problems.append(f"exception for {purl} has no `expires` date")
            continue
        if not exc.get("reason"):
            problems.append(f"exception for {purl} has no `reason`")
            continue
        if not exc.get("owner"):
            problems.append(f"exception for {purl} has no `owner`")
            continue
        try:
            expires = date.fromisoformat(str(exc["expires"]))
        except ValueError:
            problems.append(f"exception for {purl} has an unparseable `expires`: {exc['expires']!r}")
            continue
        if expires < today:
            # Expired, so it stops waiving. The component below will now fail on its own licence,
            # which is the intended prompt to re-decide.
            print(f"NOTE  exception for {purl} expired on {expires} — no longer waived", file=sys.stderr)
            continue
        waived.add(purl)

    return allow, deny, waived, problems


def check_sboms(paths: list[Path], allow: set[str], deny: set[str], waived: set[str]) -> list[str]:
    failures: list[str] = []
    total_components = 0

    for path in paths:
        try:
            doc = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            failures.append(f"{path}: unreadable or not JSON ({exc})")
            continue

        components = doc.get("components") or []
        if not components:
            # An SBOM with no components is almost always a generation failure, and it is
            # indistinguishable from a clean pass unless it is called out.
            failures.append(f"{path.name}: SBOM contains no components — generation likely failed")
            continue

        total_components += len(components)
        for comp in components:
            purl = comp.get("purl") or comp.get("name") or "<unnamed component>"
            if purl in waived:
                continue
            ids = spdx_ids(comp)
            blocked = ids & deny
            if blocked:
                failures.append(f"{path.name}: {purl} is {sorted(blocked)} — denied")
                continue
            unreviewed = ids - allow
            if unreviewed:
                # FAIL CLOSED. PyPI and npm metadata omit or mistype the licence often enough
                # that treating UNKNOWN as a pass is the single most likely way copyleft code
                # ships. Reserve file-level detection (ScanCode) for the pre-release audit.
                failures.append(f"{path.name}: {purl} licence {sorted(unreviewed)} unreviewed")

    if total_components:
        print(f"license_gate: {total_components} components across {len(paths)} SBOM(s)", file=sys.stderr)
    return failures


# ----------------------------------------------------------------------------------------------
# Model weights — dependencies with no package manager
# ----------------------------------------------------------------------------------------------
def check_models() -> list[str]:
    """Docling's layout/TableFormer models and RapidOCR's ONNX weights are downloaded artifacts:
    absent from every SBOM, invisible to pip-audit, and carrying their own licences — Surya's code
    is Apache-2.0 while its WEIGHTS are OpenRAIL-M, so a code-level check reports the safe half of
    a package this arm rejects. They get a pinned manifest or they are unscanned by construction.

    THIS ARM SURVIVED THE REMOVAL OF LOCAL INFERENCE, AND ON PURPOSE. When embeddings and
    reranking became provider API calls, bge-m3 and bge-reranker-v2-m3 left the manifest and the
    obvious next step looked like deleting this function. It is wrong: document parsing and OCR
    are kept, so we still SHIP model weights, so we still ship weight licences — and a weight
    licence is precisely the thing no dependency scanner can see, because there is no dependency.
    """
    if not MODELS.is_file():
        # NOT a skip. The manifest is mandatory; its absence means the model arm of the gate is
        # not running, and a gate that silently checks nothing is worse than no gate.
        return [
            f"model manifest not found: {MODELS.relative_to(REPO_ROOT)} — "
            "model weights are dependencies with no package manager and cannot be scanned without it"
        ]

    try:
        manifest = tomllib.loads(MODELS.read_text(encoding="utf-8"))
    except tomllib.TOMLDecodeError as exc:
        return [f"{MODELS.name}: not valid TOML ({exc})"]

    allow, deny, _, _ = load_policy()
    failures: list[str] = []
    models = manifest.get("model")
    if not models:
        return [f"{MODELS.name}: contains no [[model]] entries"]

    for model in models:
        ident = model.get("id", "<unnamed model>")

        for field in ("license", "revision", "format", "shipped"):
            if field not in model:
                failures.append(f"model {ident}: missing required field `{field}`")
        if failures and any(ident in f for f in failures):
            continue

        if not model["shipped"]:
            # Eval-only weights never reach a tenant, so their licence is ours to accept and not
            # one a self-hoster inherits. OmniDocBench is the standing example.
            continue

        lic = model["license"]
        if lic in deny or lic not in allow:
            failures.append(f"model {ident}: licence {lic!r} not permitted in a shipped artifact")

        rev = str(model["revision"])
        if len(rev) != 40 or set(rev.lower()) - HEX:
            # "main" MOVES. A scan of it proves nothing about what shipped, and Docling's own model
            # specs default to revision="main" — which is exactly how an unreviewed weight arrives.
            failures.append(f"model {ident}: revision {rev!r} is not a 40-character commit sha")

        if model["format"] not in NON_PICKLE_FORMATS and not model.get("picklescan_clean"):
            # torch.load on a .bin unpickles, and unpickling is arbitrary code execution — a
            # supply-chain path no dependency scanner watches, because there is no dependency.
            failures.append(
                f"model {ident}: format {model['format']!r} is a pickle container with no "
                "recorded picklescan pass"
            )

    return failures


def main(argv: list[str]) -> int:
    sbom_args = [a for a in argv if not a.startswith("-")]

    if not sbom_args:
        print(
            "FATAL no SBOM paths given. A licence gate invoked with no inputs would report success "
            "while checking nothing — the most common way this check becomes decoration.\n"
            "usage: license_gate.py sbom/*.cdx.json",
            file=sys.stderr,
        )
        return 2

    paths: list[Path] = []
    for arg in sbom_args:
        p = Path(arg)
        if not p.is_file():
            # An unexpanded shell glob (`sbom/*.cdx.json` with no matches) arrives here literally.
            print(f"FATAL SBOM path does not exist: {arg}", file=sys.stderr)
            return 2
        paths.append(p)

    allow, deny, waived, problems = load_policy()
    if problems:
        for line in problems:
            print(f"FATAL policy: {line}", file=sys.stderr)
        return 2

    failures = check_sboms(paths, allow, deny, waived)
    failures += check_models()

    for line in failures:
        print(f"BLOCK {line}", file=sys.stderr)

    if failures:
        print(f"\nlicense_gate: {len(failures)} blocking finding(s).", file=sys.stderr)
        print(
            "To resolve: replace the dependency, or add a dated `[[exception]]` to "
            "scripts/security/policy/licences.toml with a purl, an owner, a reason and an "
            "`expires` date. There is no flag that turns this off.",
            file=sys.stderr,
        )
        return 1

    print("license_gate: OK", file=sys.stderr)
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
