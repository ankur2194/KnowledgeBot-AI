# Security Policy

KnowledgeBot AI is shipped as **self-hostable software**. Our users run our images and our source on
their own infrastructure, with their own provider credentials and their own tenants' documents. They
cannot ask us whether they are affected by today's advisory — they have to be able to answer it
themselves. Everything below follows from that.

---

## Reporting a vulnerability

**Use GitHub private vulnerability reporting.** On this repository: _Security_ tab → _Report a
vulnerability_. That creates a private advisory visible only to maintainers, and it is where fixes,
CVE assignment, and coordinated disclosure are handled.

**Please do not open a public issue, a pull request, or a discussion for a suspected vulnerability.**
A public issue is a zero-day notification to every self-hoster who has not yet patched, and they are
the people least able to respond quickly.

Include, as far as you have it: affected version or commit, the component (control plane, data plane,
widget, mobile, infrastructure), reproduction steps or a proof of concept, the impact you believe it
has, and whether it is being exploited.

**Do not include real data.** No credentials, no customer or tenant names, no production hostnames, no
uploaded documents. If a reproduction genuinely requires sensitive material, say so in the report and
we will arrange a channel for it rather than having it sit in an advisory thread.

We will not pursue legal action against anyone acting in good faith under this policy: testing against
your own deployment, not accessing data belonging to others, and giving us a reasonable window before
disclosure.

---

## Timelines

| Stage                                                      | Target                                                                 |
| ---------------------------------------------------------- | ---------------------------------------------------------------------- |
| Acknowledgement that the report was received               | 3 business days                                                        |
| Initial assessment — is it a vulnerability, and how severe | 10 business days                                                       |
| Fix or documented mitigation for **critical** and **high** | 30 days from assessment                                                |
| Fix or documented mitigation for **medium** and **low**    | next scheduled release                                                 |
| Public advisory                                            | on release of the fix, or 90 days from the report, whichever is sooner |

Severity is assessed with CVSS v4.0, adjusted for what the finding means in a multi-tenant deployment.
Anything that crosses a tenant boundary — a query that escapes an organization scope, a vector search
missing one of its four mandatory filters, a credential reaching a client, a log, or an audit entry —
is treated as at least **high** regardless of what the base score computes, because tenant isolation is
the property this product exists to hold.

If a reported issue is being actively exploited, say so in the report; that changes the handling and
the notification obligations, not just the priority.

These targets are commitments about _our_ process. They say nothing about how fast a self-hoster
deploys the fix, which is why the advisory always states the mitigations available without upgrading.

---

## Supported versions

| Version         | Supported                     |
| --------------- | ----------------------------- |
| `main`          | Yes — the only supported line |
| Tagged releases | None yet                      |

The project has not made a release. Once it does, this table will name the supported minor lines and
their end dates, and security fixes will be backported only to the lines named here. Until then,
report against `main` and expect fixes on `main`.

---

## Scope

### In scope

- Source code in this repository, across every runtime.
- The container images we build and publish, including how they are configured — the Compose topology,
  the network split, the Traefik configuration, the OTel Collector pipeline, and the data-service
  configuration files under `infrastructure/`.
- The default configuration a self-hoster gets from `scripts/dev/bootstrap.sh` and the shipped
  `.env.example` files. **A default that fails open is a vulnerability here**, not a documentation gap.
- The published widget loader and iframe application, and the postMessage bridge between them.
- Our dependency set, including model weights, where a supply-chain issue reaches a shipped artifact.

### Out of scope

- **A self-hoster's own infrastructure.** Their reverse proxy, TLS termination, firewall, DNS, host
  hardening, or backup posture. If their proxy is misconfigured, that is theirs — unless our
  documentation told them to configure it that way, in which case report it and it is ours.
- **Their provider credentials.** Keys for OpenAI, Anthropic, DeepSeek, NVIDIA or OpenRouter are issued
  to them by third parties, are stored encrypted with a KEK they control, and are billed to their
  accounts. A leaked key is a matter for them and their provider. _What is in scope_ is any path by
  which our code exposes one — to a client, a log, an API response, a span attribute, an audit detail,
  or a configuration snapshot.
- **The upstream providers themselves.** External LLM APIs are separate services under their own terms
  and their own security policies. Report a bug in an upstream model or API to that vendor.
- Anything requiring a compromised host, a malicious operator, or physical access to the deployment.
- Findings from an automated scanner with no demonstrated impact — a version string in a banner, a
  missing header on an endpoint that serves nothing, a CVE in a dependency along a path that is not
  reachable. Show the path.
- Denial of service through sheer volume against a deployment you do not operate.

---

## Software bills of materials

**Every release ships an SBOM.** CycloneDX 1.6, generated with Syft, produced **per image and per
lockfile** — neither view alone describes the artifact, because the bundlers inline npm code into JS
with no `node_modules` in the final layer, so an image scan sees the base OS packages and essentially
no npm components.

SBOMs are **attached to the GitHub release** they describe. They are deliberately _not_ committed to
the repository: a committed SBOM drifts from the build the moment a lockfile moves, and a stale SBOM
quoted as evidence is worse than none. `sbom/` is git-ignored for that reason.

Model weights get their own record. The Docling layout and TableFormer models and the RapidOCR ONNX
weights are downloaded artifacts — absent from every SBOM, invisible to dependency scanners, and
carrying their own licences. They are pinned by commit sha in
`services/ai-service/models.manifest.toml`, which is the manifest arm of the licence gate.

**Those three are the whole list, and that is a security property rather than a coincidence.** Under
[ADR-030](docs/19-repo-structure-adrs.md) no embedding or reranking weights are downloaded at all —
both are provider API calls — so the manifest covers only models that read files locally and send
nothing anywhere. The corresponding exposure moved rather than vanished: chunk text is now sent to
each organization's configured embedding provider, under that organization's own credential, through
the same adapter layer, quota accounting and audit redaction rules as every other provider call.

---

## Why this is a policy and not a courtesy

The EU **Cyber Resilience Act** entered into force on 10 December 2024. Its **vulnerability-reporting
obligations apply from 11 September 2026**, with full application from 11 December 2027. For software
placed on the EU market with a digital element — which self-hostable software is — those obligations
include a documented vulnerability-handling process, a machine-readable bill of materials covering at
least the top-level dependencies, security updates for the support period, and notification of actively
exploited vulnerabilities within defined windows.

This file, the SBOM-per-release rule, and the licence and vulnerability gates specified in
`scripts/security/` are how that obligation is met. They are not decoration, and removing one is a
compliance change rather than a cleanup.

### Where that stands today, and why

**Neither gate runs in CI.** Both artifacts exist and neither is invoked by any workflow, Makefile
target or Dockerfile. This is a deferral with two named blockers, not an oversight — and stating it
here is the point, because a compliance claim that is not true is worse than a gap that is dated:

- **The licence gate cannot run.** `scripts/security/license_gate.py` takes SBOM paths and returns
  exit 2 with no inputs, deliberately, so that a run checking nothing can never read as a pass. SBOMs
  are generated per image and per lockfile. Every lockfile now exists — `composer.lock`, `uv.lock` and
  the `pnpm-lock.yaml` files were all resolved (finding O18) — but **no workflow builds an image or
  generates an SBOM** (finding O22), so the gate still has no input. Its one arm whose input _does_
  exist today, `check_models()` over `services/ai-service/models.manifest.toml`, is unreachable from
  `main()` because the no-SBOM path returns before it. That manifest carries three
  `TODO-RESOLVE-SHA` revisions, so the gate would be red on its own inputs even if it were reachable.
- **The `vuln-ignores.toml` `test_id` check would be red today.** The schema makes `test_id` mandatory
  precisely so a suppression is tied to a test enforcing the compensating control. Its sole entry names
  `services/ai-service/tests/security/test_docling_no_remote_render.py`, and that file does not exist.
  Landing the check first would make it a required check that cannot pass, which gets disabled within a
  week — so the test lands first, then the check.

**Both are on the clock.** The vulnerability-reporting obligations above apply from **11 September
2026**, so this gap has a date rather than being open-ended. Until then the licence and vulnerability
policy is enforced by review against `scripts/security/policy/licences.toml` and
`scripts/security/policy/vuln-ignores.toml`, which is weaker and is meant to read that way. The one
security check that _is_ wired is the vendored SAST rule-count floor
(`scripts/security/rule_count_check.sh`, run by the `repo-artifact-consistency` job in
`.github/workflows/gates.yml`), which guarantees a scanner's ruleset is non-empty — not that any
scanner has run.
