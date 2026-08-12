# `scripts/security/rules/` — vendored SAST rules and their licensing

## Everything in this directory is original work, licensed under the repository's own licence.

No rule here is copied, adapted, or derived from any third-party ruleset. That is a licensing
requirement, not a preference.

---

## Why there is not a single `p/…` ruleset here

The obvious move — `semgrep --config p/php --config p/python --config p/typescript` — is
unavailable to this project, and the reason is specific.

**The engine and the rules are licensed differently.** Semgrep's OSS engine is LGPL-2.1, but the
community rules moved to the **Semgrep Rules License v1.0**, which permits internal use and
explicitly *"does not allow you to distribute the rules, or to make them available to others as a
service."*

**We publish this monorepo to self-hosters.** Vendoring `p/php` into a repository we hand to
people we do not vet **is distribution**. It is not a grey area and it does not become one because
the files are small or because everyone does it.

So:

- The scanner is **Opengrep** (LGPL-2.1, the maintained fork) rather than Semgrep. Rule *syntax* is
  compatible; the rules are ours.
- **`scripts/security/policy/licences.toml` denies `LicenseRef-Semgrep-Rules-1.0`**, so if a
  Semgrep-licensed rule file ever arrives through a dependency SBOM, the licence gate blocks the
  build rather than a reviewer having to spot it.
- Opengrep's own forked ruleset carries a licence we have **not** confirmed.
  <!-- UNVERIFIED --> Check it before vendoring anything from there, and record the finding in this
  file rather than in a commit message.

## The operational half: a ruleset that resolves to nothing exits 0

Registry rulesets are fetched over the network and cached in `$HOME`. A CI container without
egress, or with a cold cache, **resolves to an empty ruleset and exits 0** — a green SAST job that
scanned nothing, indistinguishable in the log from a green job that scanned everything.

Vendoring removes the network dependency. `scripts/security/rule_count_check.sh` removes the rest:
it asserts a **non-zero** rule count before any scan runs, so an empty or unparseable rules
directory fails loudly instead of passing silently. Run it first, always.

---

## The five rule files

Each targets a violation that is **invisible without it**: green tests, no error, no log line. That
is the admission criterion — a rule that catches something a failing test would also catch does not
belong here, because every rule that fires on noise is a rule people learn to ignore.

| File | Language | What it catches |
|---|---|---|
| `kb-php-tenancy.yaml` | PHP | a query that escapes the org scope; a Horizon gate that returns `true`; an audit `details` built from `$request->all()` |
| `kb-python-tenancy.yaml` | Python | a Qdrant call with no tenant filter, `Filter(must=[])`, `filter or Filter()`, a delete by anything but a stable id |
| `kb-python-secrets.yaml` | Python | a decrypted credential reaching a log, a span, or an exception message; GenAI content capture being enabled |
| `kb-python-fetch-safety.yaml` | Python | `follow_redirects=True` on a tenant-supplied URL; `is_private` used as the SSRF rule; `enable_remote_services=True`; `torch.load` on a pickle |
| `kb-ts-boundary.yaml` | TypeScript | a client reaching `ai-api` directly; `postMessage(..., '*')`; a `startsWith` origin check; `EventSource` for chat |

## How they run

```bash
scripts/security/rule_count_check.sh                 # assert non-zero rule count FIRST
opengrep scan --config scripts/security/rules/ \
  --baseline-commit "$(git merge-base origin/main HEAD)" \
  --error
```

`--baseline-commit` is not optional in practice. Without it the adoption PR carries every
pre-existing finding in the repository, the job is red on day one, and the gate is disabled within
a week. Only findings the diff introduced block a merge; the nightly full scan opens issues against
a named owner instead.

## Adding a rule

1. It must catch something **silent**. If a failing test would catch it, write the test.
2. It must be **original work**. Do not adapt a registry rule and change the message — that is
   still a derivative, and this file is the record that says we did not do it.
3. Give it a `metadata.kb-invariant` naming the doctrine it enforces, so a future reader can tell a
   real invariant from a style preference.
4. Add an exemption comment form (`// tenancy-exempt: <reason>`) only where a legitimate exception
   genuinely exists. An exemption with no required reason becomes a decoration people paste.
