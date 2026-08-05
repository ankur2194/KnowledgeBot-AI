# The `extra` policy and where validation runs — reference

Depth for `pydantic-contracts`. Spec: `docs/06-architecture.md` §11.2, §11.4.

## The `extra` policy, and its two exceptions

| Model | `extra` | Why |
|---|---|---|
| Internal request bodies and the nested config snapshot | `forbid` | Sender is our own Laravel; a dropped field is a silent misconfiguration |
| Internal responses and SSE event payloads | `forbid` | We construct these; `forbid` catches a typo'd kwarg at construction, not in a client |
| **Raw provider responses** | `ignore` | Providers add fields weekly; `forbid` converts every one into an outage |
| **Celery task args and anything read back out of Valkey** | `ignore` | A queued message written by the previous deploy is validated by the next one |

## Where validation runs, and where it deliberately does not

| Boundary | Validate |
|---|---|
| Request body from Laravel | **yes** — strict + forbid; the only place a bad snapshot is catchable |
| Raw provider response → normalized model | **yes** — `extra="ignore"` |
| Qdrant payload read back (≤30 candidates/query) | **yes** — small N, and a payload missing `org_id` must be loud (`kb-tenancy-isolation`) |
| Per-token `Token`/`Delta` we produced ourselves | **no** — `model_construct()` |
| A model already validated, handed down the call stack | **no** — `revalidate_instances` stays at its `never` default |
