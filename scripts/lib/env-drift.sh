# scripts/lib/env-drift.sh — the template-vs-rendered NAME-SET comparison, in one place.
#
# SOURCED, NOT EXECUTED. No shebang, no `set -e`, no output: a library that prints or exits owns
# decisions its callers have to make differently.
#
#   scripts/dev/bootstrap.sh   renders the files, so it reports drift and refuses to start a stack
#                              that would come up with a key missing.
#   scripts/ops/preflight.sh   deploys nothing, so it fails the deploy.
#
# WHY THIS FILE EXISTS AT ALL. `bootstrap.sh` renders every `*.env` / credential file from a
# committed `*.example` and then, correctly, never touches it again — the rendered copy is where an
# operator's per-deployment values live, and copying a template over it would clobber the credential
# a running server is authenticating. The consequence is that a template which GAINS a key delivers
# it to new deployments only. Nothing about the old deployment looks wrong: the file parses, Compose
# starts, and the service reads a default — or, worse, reads nothing and fails closed somewhere far
# away from the cause.
#
# MEASURED, TWICE, IN THIS REPOSITORY:
#   * `env/ai-service.env.example` was corrected from `KB_ENV` to `KB_ENVIRONMENT`; the rendered
#     file kept the old spelling and every ai-* container ran as `local` while its own template
#     said `production`. pydantic-settings looks up its OWN field names and never enumerates the
#     environment, so the stale key was not rejected — it was never seen. `extra="forbid"` cannot
#     catch that direction either.
#   * `env/core-api.env.example` gained FRONTEND_URL, MAIL_EHLO_DOMAIN, MAIL_FROM_ADDRESS,
#     MAIL_FROM_NAME and SANCTUM_STATEFUL_DOMAINS — an entire deliverable — and the rendered
#     `core-api.env` on the development deployment had none of them. Absent
#     SANCTUM_STATEFUL_DOMAINS, `config/sanctum.php` fails closed to `[]`, no request is ever
#     "from the frontend", the whole session middleware stack never runs, and login reaches
#     `session()->regenerate()` with no session bound: an unauthenticated 500.
#
# NAMES ONLY, NEVER VALUES. Values are per-deployment by design and differ legitimately; the NAME
# set is the contract between the template and the code that reads it. Name-only comparison is also
# what keeps every caller inside the no-secret-in-output rule.

# kb_env_names <file>
#
# The assignment names in a dotenv-style file, sorted and unique. Comments and blank lines are
# dropped; `export FOO=` is accepted because an operator may well have written it.
#
# A COMMENTED-OUT KEY IS DELIBERATELY NOT A NAME. `# KB_EDGE_SUBNET=...` in a template documents an
# override whose absence is the supported state, so it must not read as a key the deployment is
# missing. Anchoring to the start of the line after optional whitespace is what implements that.
kb_env_names() {
  sed -nE 's/^[[:space:]]*(export[[:space:]]+)?([A-Za-z_][A-Za-z0-9_]*)=.*/\2/p' "$1" | sort -u
}

# kb_env_missing <example> <target>
#
# Names present in the TEMPLATE and absent from the RENDERED file, space-separated, empty when none.
# This is the direction that breaks deployments silently: the container never receives the variable.
kb_env_missing() {
  comm -23 <(kb_env_names "$1") <(kb_env_names "$2") | tr '\n' ' ' | sed -E 's/[[:space:]]+$//'
}

# kb_env_extra <example> <target>
#
# Names present in the RENDERED file and absent from the template. Either a deliberate local
# addition or the OLD SPELLING of something the template renamed — and the two are indistinguishable
# from here, which is why callers report rather than repair.
kb_env_extra() {
  comm -13 <(kb_env_names "$1") <(kb_env_names "$2") | tr '\n' ' ' | sed -E 's/[[:space:]]+$//'
}

# ------------------------------------------------------------------------------------------------
# WHY NO kb_env_repair, kb_env_append, OR kb_env_fill.
# ------------------------------------------------------------------------------------------------
# There is no safe automatic filler, and this is not a matter of taste — the four keys the core-api
# gap actually produced each behave DIFFERENTLY when present-but-empty versus absent. Laravel's
# `env('X', $default)` returns the default only when the variable is UNSET; a variable set to the
# empty string returns `''`, so `KEY=` is not a neutral placeholder:
#
#   SANCTUM_STATEFUL_DOMAINS  identical either way. config/sanctum.php wraps the explode() in
#                             array_filter, so '' and absent both yield []. Empty is neutral.
#   FRONTEND_URL              empty is BETTER than absent. config/kb.php defaults it to
#                             http://localhost:3000, so absent silently mails every recipient a
#                             link to their own machine; '' makes App\Support\Kb\FrontendUrl throw
#                             with a message naming the variable.
#   MAIL_FROM_ADDRESS         empty is a THROW at send time (Symfony Mailer rejects an empty
#                             From); absent is the quiet 'no-reply@localhost' any real MTA drops.
#   MAIL_FROM_NAME            harmless either way.
#
# So one filler is wrong for some of them, in both directions. And copying the TEMPLATE'S value is
# worse still: the templates carry deliberate placeholders (`https://app.knowledgebot.example`), and
# injecting that for FRONTEND_URL mails password-reset links to a domain the operator does not own —
# a wrong value that looks chosen, replacing a missing one that at least fails locally. The operator
# picks these. The library's job is to make it impossible not to notice.
