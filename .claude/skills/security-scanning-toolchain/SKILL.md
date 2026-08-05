---
name: security-scanning-toolchain
description: CI security and licence scanning for the KnowledgeBot monorepo — SAST across PHP, Python and TypeScript, dependency and image CVE scanning, full-history secret scanning, SBOM generation, and the licence gate that keeps field-of-use-restricted code out of shipped artifacts. Use whenever adding a scanner, triaging a finding, suppressing an unfixable CVE, pinning a lockfile, or adding a model weight. It verifies the rules kb-security-baseline sets, never sets them. Pairs with github-actions-pipeline (where these run).
---

# Security and Licence Scanning Toolchain

Pinned and licence-checked on 2026-08-05: **Trivy 0.73.0** (Apache-2.0), **Syft 1.50.0** / **Grype 0.116.1** (Apache-2.0), **Opengrep 1.26.0** (LGPL-2.1), **Gitleaks 8.30.1** (MIT), **TruffleHog** (AGPL-3.0, container-only), **pip-audit 2.10.1** (Apache-2.0), **OSV-Scanner 2.4.0** (Apache-2.0), **Hadolint** (GPL-3.0, CI-only), **picklescan** (MIT), **composer audit** (bundled with Composer).
**Authoritative spec:** docs/13-security.md §18, docs/18-deployment-backup-cicd.md §26 (steps 7–8), docs/21-risks-licensing-glossary.md §34–35, docs/05-tech-stack.md §9.16

## Non-negotiables

- **A field-of-use-restricted or network-copyleft dependency in a shipped artifact blocks the build.** We hand images and source to self-hosters we do not vet, so an AGPL/SSPL/OpenRAIL term is one *they* inherit without agreeing to it. Two live cases already: Surya's OCR **weights** (modified AI Pubs OpenRAIL-M, $5M operator revenue cap, competing-product clause) and OmniDocBench (research-only — quote its numbers, never run it in our harness). Both in `docs/22-spec-findings-and-decisions.md`. This is a legal defect, not a lint.
- **Secret scanning covers full history, not the diff.** `kb-security-baseline` forbids a provider credential reaching a log, a response, or an audit row; git is all three at once and it is public. A shallow-clone scan that passes proves nothing.
- **The scanner is not the mitigation.** A suppression records that we accepted a risk; a test enforces the thing that makes it acceptable. CVE-2026-44016 (Docling's Playwright HTML page rendering, scope-changed, high C+I) has no patched version — the suppression is paperwork, the assertion that the render option is off is the control.
- **Every release ships an SBOM.** A self-hoster cannot ask us "am I affected by today's advisory"; they run our bits on their metal and must answer it themselves. Under the EU CRA — in force 10 Dec 2024, vulnerability-reporting obligations from **11 Sep 2026**, full application 11 Dec 2027 — this stops being a courtesy.
- **Model weights are dependencies with no package manager.** BGE-M3, `bge-reranker-v2-m3`, and Docling's layout/table models are downloaded artifacts: absent from every SBOM, invisible to pip-audit, and carrying their own licences. They get a pinned manifest or they are unscanned by construction.
- **Lockfiles are committed and base images are digest-pinned.** An unpinned `FROM python:3.12-slim` makes the SBOM a description of a build that no longer exists, and makes "we fixed that CVE" unfalsifiable.

## How we use it

### Coverage: five ecosystems, one gate each

| Surface | Tool | Runs on | Gate |
|---|---|---|---|
| PHP SAST | Opengrep + our rules; `psalm --taint-analysis` | `services/core-api` | changed lines block |
| Python SAST | Opengrep; `bandit -ll` as a cheap second pass | `services/ai-service` | changed lines block |
| TypeScript SAST | Opengrep; `eslint-plugin-security` in the app lint job | `apps/*`, `packages/*` | changed lines block |
| PHP deps | `composer audit --locked --abandoned=report` | `composer.lock` | fix-available blocks |
| Python deps | `pip-audit -r requirements.lock --strict` | lock file | fix-available blocks |
| npm deps (4 apps) | `osv-scanner --lockfile` per workspace | `pnpm-lock.yaml` | fix-available blocks |
| Container images | `trivy image --scanners vuln,secret,license` | every built image | fix-available blocks |
| Dockerfiles | `hadolint` | `infrastructure/docker` | issue only |
| Secrets | `gitleaks git` (full history) + `gitleaks dir` on the worktree | monorepo | **always blocks** |
| Live-credential check | TruffleHog `--only-verified`, nightly | monorepo | blocks + rotate |
| SBOM | `syft` → CycloneDX 1.6, per image **and** per lockfile | lockfiles pre-merge, images at release | generation failure blocks |
| Licence | `scripts/security/license_gate.py` over those SBOMs | shipped artifacts only | **blocks both times** |
| Model weights | `picklescan` + the manifest arm of the licence gate | `models.manifest.toml` | **blocks** |

`osv-scanner` is the unifier for the four Node workspaces because `npm audit` knows only the GitHub Advisory DB and reports per-workspace noise; OSV aggregates GHSA, PySec, and distro feeds behind one exit code.

### Tools deliberately rejected

- **Safety (pyupio).** The CLI carries no OSI licence and `safety-db` is **CC BY-NC-SA 4.0** — *"use the data in any non commercial project"*. We are a commercially usable product; running it in our CI is exactly the prohibited use. `pip-audit` (PyPA, Apache-2.0, OSV-backed) replaces it with no loss.
- **Semgrep's registry rulesets (`p/…`).** The engine is still LGPL-2.1, but the community rules moved to the **Semgrep Rules License v1.0**: internal use only, *"does not allow you to distribute the rules, or to make them available to others as a service."* Vendoring `p/php` into a monorepo we publish to self-hosters **is** distribution. We run **Opengrep** (the LGPL-2.1 fork, actively maintained — 2.9k stars, pushed 2026-08-04) against our own rules plus rulesets whose licence we can restate. Opengrep's forked ruleset licence is not confirmed; check it before vendoring. <!-- UNVERIFIED -->
- **`license-checker` (npm).** Last commit January 2024, no SPDX licence on the repo. Unmaintained tooling in a compliance path is a compliance risk. Syft covers npm.

### What blocks a merge, and why so little does

A scanner that fails the build on every new advisory trains people to bypass it; a scanner that never blocks is decoration. The split is **what this diff introduced** versus **what the world published overnight**:

| Finding | Pre-merge (diff-scoped) | Nightly (full) |
|---|---|---|
| Secret in worktree or history | block, no waiver | block + rotate at the provider |
| Denied licence on a shipped component | block | block |
| `UNKNOWN` licence on a new component | block (fail closed) | issue |
| Critical/High CVE **with a fix**, runtime dependency | block | issue, 7-day SLA |
| Critical/High CVE **with no fix** | block once, then suppress | issue |
| Medium/Low, or dev-only, or OS package with no fix | issue | issue, 30-day SLA |
| SAST finding on changed lines | block | — |
| SAST finding on untouched lines | ignored (`--baseline-commit`) | issue |
| Hadolint, IaC, DAST | issue | issue |

Main is never red because of something nobody in the team did. Nightly opens issues against an owner; the PR gate only ever fails on the author's own change, which is the only failure a human can act on in the next five minutes.

**A vulnerability with no fix available** gets a dated, owned, expiring entry in `scripts/security/policy/vuln-ignores.toml` — never a CLI flag in a workflow file, because a flag has no expiry and no author. The entry must name the compensating control and the test that enforces it. CVE-2026-44016 is the worked case: Docling ships no patched release, so the entry cites `enable_remote_services=False` and the ingestion test that asserts the HTML pipeline never constructs a Playwright backend. When the expiry lapses the nightly job fails and someone re-decides. Suppressing without the assertion is how the mitigation gets reverted by a well-meaning refactor six months later.

**DAST: yes, ZAP baseline only, never blocking.** The active scanner is worth nothing on our two interesting surfaces and actively harmful on one — ZAP's spider cannot open an SSE stream or drive the widget's `postMessage` handshake, and an active scan pointed at `/chat` bills real provider tokens against a tenant credential and trips `provider_rate_limit` (`kb-error-taxonomy`). What the passive baseline *is* good at is exactly the regression we keep having: a CSP directive, a `frame-ancestors`, a cookie flag, or `Vary: Origin` quietly dropped from a response. Run it against the preview environment, fail nothing, open an issue. The streaming and widget security behaviour is covered by the Playwright suite in `vitest-playwright`, which can actually exercise it.

### The licence gate

```python
#!/usr/bin/env python3
"""scripts/security/license_gate.py — the only check that may block a build on licence.
Usage: python scripts/security/license_gate.py sbom/*.cdx.json

Runs twice — pre-merge over lockfile SBOMs (no image build needed, so a bad dependency
never reaches main), then at release over image SBOMs, the only view with the base layer.
CI-only tools are out of scope by construction: hadolint is GPL-3.0 and trufflehog is
AGPL-3.0, and neither is a defect until it lands in something a self-hoster runs.
"""
from __future__ import annotations

import json
import sys
import tomllib
from datetime import date
from pathlib import Path

POLICY = Path(__file__).parent / "policy" / "licences.toml"
MODELS = Path("services/ai-service/models.manifest.toml")


def spdx_ids(component: dict) -> set[str]:
    """CycloneDX expresses a licence three different ways. A component using the form
    you did not parse reads as UNKNOWN — and UNKNOWN must never silently pass."""
    ids: set[str] = set()
    for entry in component.get("licenses", []):
        if "expression" in entry:  # e.g. "Apache-2.0 OR MIT"
            ids.update(
                tok for tok in entry["expression"].replace("(", " ").replace(")", " ").split()
                if tok not in {"AND", "OR", "WITH"}
            )
        elif "license" in entry:
            lic = entry["license"]
            ids.add(lic.get("id") or f"LicenseRef-{lic.get('name', 'unnamed')}")
    return ids or {"UNKNOWN"}


def main(sbom_paths: list[str]) -> int:
    policy = tomllib.loads(POLICY.read_text())
    allow, deny = set(policy["allow"]), set(policy["deny"])
    # An exception without an expiry is a permanent hole nobody revisits.
    waived = {
        e["purl"] for e in policy.get("exception", [])
        if date.fromisoformat(e["expires"]) >= date.today()
    }
    failures: list[str] = []

    for path in map(Path, sbom_paths):
        for comp in json.loads(path.read_text()).get("components", []):
            purl = comp.get("purl") or comp["name"]
            if purl in waived:
                continue
            ids = spdx_ids(comp)
            if blocked := ids & deny:
                failures.append(f"{path.name}: {purl} is {sorted(blocked)} — denied")
            elif unreviewed := ids - allow:
                # Fail closed. PyPI and npm metadata omit or mistype the licence often
                # enough that treating UNKNOWN as a pass is how AGPL code ships.
                failures.append(f"{path.name}: {purl} licence {sorted(unreviewed)} unreviewed")

    for model in tomllib.loads(MODELS.read_text())["model"]:
        if not model["shipped"]:
            continue  # eval-only weights never reach a tenant
        if model["license"] not in allow or model["license"] in deny:
            failures.append(f"model {model['id']}: licence {model['license']} not permitted")
        rev = model["revision"]
        if len(rev) != 40 or rev.strip("0123456789abcdef"):
            # "main" moves, so a scan of it proves nothing about what shipped.
            failures.append(f"model {model['id']}: revision {rev!r} is not a commit sha")
        if model["format"] != "safetensors" and not model.get("picklescan_clean"):
            failures.append(f"model {model['id']}: pickle weights with no recorded picklescan pass")

    for line in failures:
        print(f"BLOCK {line}", file=sys.stderr)
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
```

`policy/licences.toml` allows MIT, Apache-2.0, BSD-2/3, ISC, MPL-2.0, LGPL-2.1/3.0, PSF-2.0, 0BSD, CC0-1.0, Zlib; denies AGPL-3.0, SSPL-1.0, GPL-2.0/3.0, BUSL-1.1, Elastic-2.0, CC-BY-NC-\*, `LicenseRef-OpenRAIL-M`, `LicenseRef-Semgrep-Rules-1.0`. LGPL is allowed because we consume those as separate processes or unmodified shared libraries; modifying one moves it to the deny side and needs a review, not a policy edit. Hadolint (GPL-3.0) and TruffleHog (AGPL-3.0) never appear here, because the gate reads artifact SBOMs only and a CI tool invoked as a separate process is not in a shipped artifact — that distinction is the whole reason the gate is scoped this way.

### Pinning across three package managers

`composer.lock`, the pip-compiled `requirements.lock` (hashes on, `--generate-hashes`), and `pnpm-lock.yaml` are all committed, and CI installs with the frozen flag (`--no-install` / `--require-hashes` / `--frozen-lockfile`) so a resolver that quietly picks a different version fails instead of shipping. Renovate proposes bumps; it never auto-merges into a lockfile a licence gate has not re-run over. Container base images are pinned by digest, not tag. Model revisions are pinned by commit sha in `models.manifest.toml` — Docling's own model specs default to `revision="main"`, which is why the gate rejects it (`docs/22-spec-findings-and-decisions.md`).

## Gotchas

- **The licence gate is green and an AGPL package is in the image.** Syft reports the licence a package *declares* in its metadata, and a large minority of PyPI and npm packages declare nothing or a free-text string Syft cannot map to SPDX. Treated as a pass, `UNKNOWN` is the single most likely way a copyleft dependency ships. Fail closed on anything outside the allow-list, and reserve ScanCode for the pre-release audit where file-level detection matters.
- **Every dependency is clean and the OCR engine is still unshippable.** Model weights appear in no SBOM. Surya's code is Apache-2.0 while its weights are OpenRAIL-M, so a code-level licence check reports the safe half of a package the manifest arm of the gate rejects.
- **Gitleaks passes and the key is three commits back.** `actions/checkout` defaults to `fetch-depth: 1`, so `gitleaks git` sees exactly one commit. Set `fetch-depth: 0` and pass `--log-opts="--all"` to reach every ref. Separately, `detect` and `protect` were deprecated in 8.19.0 in favour of `git` and `dir` — the old names still run, so a stale invocation silently keeps working with different defaults.
- **The secret is removed, the history is rewritten, and the credential is still live.** A force-push does not reach forks, clones, CI caches, or GitHub's cached unreachable objects, which stay fetchable by sha. Rotation at the provider is the only remediation; the git surgery is cleanup. Rotate through `ProviderCredentialService` so the audit row records the fingerprint and not the key (`kb-security-baseline`).
- **Trivy fails with `TOOMANYREQUESTS` on an unrelated PR.** The vulnerability DB is an OCI artifact pulled anonymously from a public registry and rate-limited per IP — shared CI egress hits it. The reflexive fix, adding `--skip-db-update` to the retry, is worse than the failure: the scan then passes against whatever stale DB is cached and reports a false green. Mirror the DB into our own registry in a scheduled job and have the scan job pull only from there.
- **`npm audit` says 0 and Trivy says 12 on the same app.** They look at different things: the lockfile-based scanners see the dependency *tree* including devDependencies, while an image scan sees the built bundle. For `apps/web` and `apps/widget` the bundler inlines npm code into JS with no `node_modules` in the final layer, so the image SBOM lists the base image's OS packages and essentially no npm components. Generate the npm SBOM from the lockfile and the image SBOM from the image, then merge — neither alone describes the artifact.
- **You bump the Python dependency, the CVE stays.** The finding was against an OS package in the base layer, which no application-level bump touches. Read the `PkgPath`/`Target` on the Trivy row before choosing a remediation; an OS-package CVE needs a base-image digest bump and a rebuild.
- **`composer audit` goes red on a PR that changed nothing relevant.** It reports abandoned packages as well as advisories, and abandonment is asynchronous news about someone else's repository. Run it as `--abandoned=report` so abandonment opens an issue and only real advisories affect the exit code. <!-- UNVERIFIED: confirm the default in the Composer version pinned by the image -->
- **Opengrep/Semgrep finds nothing in CI and dozens of things locally.** Registry rulesets are fetched over the network and cached in `$HOME`; a CI container without egress or with a cold cache resolves to an empty ruleset and exits 0. Vendor the rules we are licensed to vendor into `scripts/security/rules/`, and assert a non-zero rule count before scanning.
- **A SAST gate on the whole tree makes every PR red on day one.** Run with `--baseline-commit "$(git merge-base origin/main HEAD)"` so only findings the diff introduced block. Without it the first adoption PR carries hundreds of pre-existing findings and the gate is disabled within a week.
- **`pip-audit --fix` does nothing and the job still fails.** The advisory has no fixed version — CVE-2026-44016 is ours. There is nothing to bump; the decision is accept-with-mitigation or drop the dependency. Record it in `vuln-ignores.toml` with an expiry, never with `--ignore-vuln` in the workflow, because a flag in YAML has no owner and no review date.
- **Loading a model file executes code.** `torch.load` on a `.bin` unpickles, and unpickling is arbitrary code execution — a supply-chain path that no dependency scanner watches because there is no dependency. Require `safetensors`, and where a pickle is unavoidable run picklescan and record the pass in the manifest. ModelScan is the better-known alternative but its last push was February 2026 and its owner changed hands; picklescan is MIT and actively maintained.
- **The suppression outlived the mitigation.** Someone re-enabled the Docling HTML remote-render option during a refactor; the CVE stayed suppressed because the suppression keys on the package, not the configuration. Every `vuln-ignores.toml` entry names a test id, and CI fails if that test does not exist.
- **A committed SBOM is quoted as evidence and is already wrong.** It drifts from the build the moment a lockfile moves. Generate it in the release job, attach it to the release, `.gitignore` `sbom/`.

## Official docs

- [Trivy](https://trivy.dev/latest/docs/) — image, filesystem, licence and secret scanners, plus DB mirroring; [Syft](https://github.com/anchore/syft) and [Grype](https://github.com/anchore/grype) for SBOM generation and SBOM-driven matching.
- [Opengrep](https://github.com/opengrep/opengrep) — LGPL-2.1 SAST engine, Semgrep-compatible rule syntax — and the [Semgrep Rules License v1.0](https://semgrep.dev/legal/rules-license), to read before vendoring any `p/…` ruleset.
- [Gitleaks](https://github.com/gitleaks/gitleaks) — `git`/`dir` subcommands, `--log-opts`, allow-listing — and [TruffleHog](https://github.com/trufflesecurity/trufflehog), whose verified detectors call the provider to prove a key is live.
- [pip-audit](https://github.com/pypa/pip-audit) and [OSV-Scanner](https://google.github.io/osv-scanner/) — OSV-backed dependency auditing; [Composer: audit](https://getcomposer.org/doc/03-cli.md#audit) for the PHP side, including `--abandoned`.
- [CycloneDX specification](https://cyclonedx.org/specification/overview/) and [SPDX License List](https://spdx.org/licenses/) — the SBOM shape and the identifier vocabulary the gate parses.
- [picklescan](https://github.com/mmaitre314/picklescan) and [safetensors](https://huggingface.co/docs/safetensors/index) — model-artifact deserialization safety.
- [ZAP](https://www.zaproxy.org/docs/) — baseline (passive) versus active scan semantics.
- [EU Cyber Resilience Act](https://digital-strategy.ec.europa.eu/en/policies/cyber-resilience-act) — obligation dates and the SBOM expectation for products with digital elements.

## Definition of done

- [ ] All five ecosystems covered: `composer.lock`, `requirements.lock`, four `pnpm-lock.yaml`, every built image, and `models.manifest.toml`
- [ ] `gitleaks git --log-opts="--all"` runs on a `fetch-depth: 0` checkout, and a fixture commit containing a fake `sk-` key fails the job
- [ ] `license_gate.py` blocks on a seeded `UNKNOWN` licence, on a seeded AGPL component, and on a model manifest entry with `revision = "main"`
- [ ] SBOMs generated per image **and** per lockfile, merged, attached to the release, and `sbom/` gitignored
- [ ] Every entry in `vuln-ignores.toml` has an owner, an `expires`, a compensating control, and a test id that exists
- [ ] CVE-2026-44016 specifically: suppression present, plus a test asserting the Docling HTML pipeline never enables remote rendering
- [ ] SAST runs with `--baseline-commit`; the adoption PR is not red with pre-existing findings
- [ ] Trivy pulls its DB from our mirror; no workflow anywhere passes `--skip-db-update`
- [ ] `--frozen-lockfile` / `--require-hashes` / `composer install --no-install` on every CI install path; base images pinned by digest
- [ ] ZAP baseline runs against the preview environment and opens issues; it fails no job
- [ ] Nightly full scan opens issues against a named owner; PR gate fails only on findings the diff introduced
