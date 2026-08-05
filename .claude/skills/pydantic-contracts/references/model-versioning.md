# Changing a model without breaking a deployed Laravel — reference

Depth for `pydantic-contracts`. Spec: `docs/06-architecture.md` §11.2, `docs/12-api-areas.md` §17.5.

`X-KB-Contract-Version` versions the **shape**; `X-KB-Config-Version` versions the **content** of one snapshot. Bumping the config version tells FastAPI nothing about a new field, and adding a field does not make old snapshots invalid — conflating the two is how a shape change ships with no version bump at all.

| Change to an inbound model | Safe against a Laravel that has not redeployed? |
|---|---|
| Add an optional field with a default | **yes** — the old sender omits it |
| Add a required field | **no** — `missing` on every request. Ship optional-with-default, migrate the sender, then tighten |
| Remove a field | **no** — `extra_forbidden` on every request. This is the price of `forbid`. Stop reading it, migrate the sender, then delete |
| Rename a field | **no** — it is remove + add. Bridge with `validation_alias=AliasChoices("new", "old")`, which accepts both *without* relaxing `extra` |
| Widen a constraint (`le=100` → `le=200`) | **yes** |
| Narrow a constraint, or tighten a `Literal` | **no** |
| Add a member to an enum we **receive** | **yes** — the old sender never sends it |
| Add a member to an enum we **send** (e.g. `finish_reason`) | **no** — client matches are exhaustive; that is a `/internal/v2` change |
| Change a field's type | **never** — add a new field and deprecate the old one |

Enum discipline at the two edges is deliberately asymmetric. **Our own seam is strict:** an unrecognized `reasoning_effort` from Laravel is a `validation` error, because Laravel is our code and a typo there is a bug. **The provider edge is tolerant but loud:** parse the native value as `str`, map it through an explicit table, fall back to a known member, and preserve the raw string in `Diagnostics.native_stop_reason` plus a counter. An unknown provider value must not crash the pipeline and must not disappear.

**An outbound event model is governed by this table too, and more tightly.** `kb-internal-api-contracts` owns the wire; every field on an `Event` subclass is serialized straight through Laravel's relay to browsers, widgets and phones, so renaming one is a client-visible break with no migration window — three clients parse it and none of them redeploys on our schedule.
