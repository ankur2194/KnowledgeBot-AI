# `scripts/` — repo-level developer and operational scripts

**Owned by `platform-devops-engineer`. Everyone else reads.**

---

## The boundary

> **`scripts/` is yours, and it is not a bypass.** A script that reaches into a database, an index,
> or object storage is doing an owning engineer's job without that engineer's invariants — no
> script may issue a Qdrant query without the four tenant filters, or a deletion by anything but a
> stable identifier. **Operational glue only.**

That is the whole rule, and it is worth being concrete about why it is stated as a prohibition
rather than a guideline.

A one-off script is the most tempting place in a repository to write the query the application
refuses to write. It has no policy layer, no tenant middleware, no `error_class`, no audit row, and
no test. It runs as an operator, on production, at the moment someone is under pressure — which is
exactly when "just this once, unfiltered" happens. The invariants it would skip are not stylistic:

- **Every Qdrant query filters `org_id`, `bot_ids`, `source_status` and `source_version_id`.** An
  unfiltered query is a *legal, successful, cross-tenant* query: HTTP 200, normal latency, no log
  line. `Filter(must=[])` is a match-all. There is nothing to notice afterwards.
- **Deletion uses stable identifiers and is verified.** Never by text match, never by content hash,
  never by "everything matching this title". A text-matched delete cuts a hole in a source nobody
  touched, in another tenant, and reports success.
- **PostgreSQL is the source of truth and Laravel owns every migration.** A script that writes a
  control-plane table has no policy check, no audit entry, and no framework tenant scope.

### What belongs here

| Directory | Contents |
|---|---|
| `dev/` | first-run bootstrap, local reset, host-file guidance |
| `ops/` | backup, restore drills, deploy-adjacent operator procedures |
| `security/` | the licence gate, the vendored SAST rules, and the policy files CI reads |

### What does not

- Anything that queries or mutates Qdrant, PostgreSQL, or object storage **content**. Ask the
  owning engineer for a `php artisan` command or a Celery task, where the invariants already live
  and where a test can cover it.
- Anything that would become a second migration path, a second deletion path, or a second
  retrieval path.
- Build orchestration. The three package managers (`composer`, `uv`, `pnpm`) are the build system;
  the root `Makefile` is one command per target and must never grow into a parallel one.

A useful test before adding a script: *if this had a bug, would it produce a wrong answer or a
cross-tenant read, silently?* If yes, it belongs in a service with the invariants, not here.

---

## Contents

```
scripts/
  dev/
    bootstrap.sh          first run: generate secrets, start the stack, verify the S3 gateway is closed
    reset.sh              destroy local state and start clean (DESTRUCTIVE, and it says so)
    hosts.md              the four hostnames and how to resolve them locally
  ops/
    backup.sh             pgdata + the SeaweedFS volume. Nothing else — the scope IS the invariant
    restore-drill.md      the drill. An untested backup is an assumption
  security/
    license_gate.py       the only check that may block a build on licence
    rule_count_check.sh   asserts a NON-ZERO rule count before any scan runs
    credential-file-scan.sh  the two live-credential files under infrastructure/docker/ are
                          gitignored (#66), so gitleaks never sees them: `gitleaks git` walks
                          commits and they have none, and `actions/checkout` never materialises
                          an ignored file on a runner. This names them by path instead of
                          walking, and hard-codes /usr/bin/grep — the shell's `grep` may be a
                          .gitignore-honouring shim (#102), which is blind to exactly these files
    policy/
      licences.toml       allow / deny / dated exceptions
      vuln-ignores.toml   accepted-with-mitigation CVEs, each with an owner, an expiry and a test id
    rules/                vendored Opengrep rules + LICENCES.md
```

---

## Conventions

Every script here:

- runs under `set -euo pipefail` and is safe to re-run — **except a script whose job is to report
  every finding rather than the first**, which drops `-e` and accumulates into a failure counter.
  `security/credential-file-scan.sh` is the one such script today and it says so at its `set` line;
  under `-e` its first non-matching grep would abort the run and hide every later check;
- **prints what it is about to do before doing it**, and refuses destructive work without an
  explicit confirmation;
- takes no credential as an argument — arguments land in shell history and in `ps` output for every
  user on the box. Credentials are read from `infrastructure/docker/secrets/` or `/run/secrets/`;
- **never echoes a secret**, not even a fingerprint of one that could be brute-forced;
- resolves paths from the repository root, not from `$PWD`, so it behaves the same from anywhere.

Every script here is written to be invoked identically by a human and by a job, and its exit code is
the contract. `security/rule_count_check.sh` is wired — `gates.yml` runs it.
`security/license_gate.py` is **not yet wired to any workflow**.

`security/credential-file-scan.sh` **is wired now**, and how it got there is the interesting part.
On a GitHub runner `actions/checkout` never materialises a gitignored file, so the credential files
it exists to read are simply absent; simulated against a checkout holding only the committable
files, the unmodified script **exits non-zero** because the enumeration, the extraction and both
controls all report they had nothing to work on. Correct, not a false green — and permanently red.
The two options this paragraph used to list were *split the runner-safe arms from the
value-extraction arm* or *run the whole thing where the credentials exist*. **Both were taken**
(#110):

- `KB_SCAN_MODE=structural` runs only the arms decidable from git alone, and every one of them
  answers for a **path** rather than a file — which is why they survive a runner. `ci.yml`'s
  `secret-scan` job runs this, right after the two gitleaks invocations that structurally cannot see
  these files. Check 2 is skipped **and printed as skipped**; a mode typo exits 2 rather than
  falling back to the weaker scan.
- `KB_SCAN_MODE=full` (the default) is unchanged and is what a developer machine, a deploy host and
  `scripts/ops/preflight.sh` run. It is the only mode that answers the value-leak question.

One consequence worth keeping: check 1 no longer skips a path whose file is absent. Both of its
assertions — gitignored, and untracked — are questions git answers for a path that is not on disk,
and skipping them was the same blindness the job as a whole had.
