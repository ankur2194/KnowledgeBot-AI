# SKILL-TEMPLATE — binding structure for every KnowledgeBot AI skill

Every file under `.claude/skills/<name>/SKILL.md` follows this exactly. Deviating makes the library inconsistent for the 16 agents that read it.

*(This file lives at `.claude/SKILL-TEMPLATE.md`, deliberately outside `.claude/skills/`, so it is never discovered as a skill itself.)*

---

## Frontmatter — two fields, nothing else

```yaml
---
name: qdrant-hybrid-search
description: Hybrid dense + sparse retrieval against Qdrant for the KnowledgeBot AI service. Use whenever creating collections, writing search or upsert calls, shaping point payloads, or debugging retrieval results in services/ai-service/. Every query must carry the mandatory tenant filter — retrieval without it is a cross-tenant leak, not a bug. Pairs with kb-tenancy-isolation (the filter contract) and bge-m3-embeddings (what fills the vectors).
---
```

- `name` — lowercase-hyphen, identical to the directory name.
- `description` — **one plain scalar line**, roughly 350–520 characters, in this shape:

  `<What it is>. Use whenever <concrete triggers: file paths, operations, symptoms>. <The disambiguating fact that stops it being confused with a neighbouring skill>. Pairs with <skill> (<why>).`

  Write it in third person. Load-bearing triggers are concrete: *"writing search or upsert calls"*, not *"working with vectors"*. No `version`, no `allowed-tools`, no `tools`.

## Body — this order, 90–200 lines total

````markdown
# <Human Title>

<One line naming the pinned versions.>
**Authoritative spec:** docs/<file>.md §<n>, docs/<file>.md §<n>

## Non-negotiables

The KnowledgeBot invariants this particular library can violate. 3–7 bullets.
State the rule *and* what breaks when it is ignored. These are lifted from the
`kb-*` doctrine skills — restate them here in the context of this tool, and
cite the doctrine skill rather than redefining it.

## How we use it

Our conventions: project layout, the API surface we actually use, the patterns
to follow. **One complete, runnable example** in the real language of the
service — not a fill-in template, not the same thing in three languages.
Comment the *why*, not the syntax.

## Gotchas

The traps. Each bullet names the **observable failure symptom** first, then the
cause and the fix. A gotcha nobody could hit from reading the official docs is
worth ten that restate them.

## Official docs

Links only, with a few words on what each covers. Never paste API reference —
it rots, and it is one fetch away.

## Definition of done

- [ ] Checkbox list an implementer can actually run or verify.
````

---

## What separates a good skill from a useless one

**Density.** This is the calibration bar, from the user's existing `laravel-backend` skill:

> On `date`-typed columns filter with `where('col', $d)`, **not** `whereDate()` — on Postgres `whereDate` casts the column (`col::date`) and defeats the b-tree index. Reserve `whereDate` for `timestamp`/`datetime` columns where you actually need to strip the time.

Symptom, mechanism, fix, and the boundary of the rule, in two sentences. Compare the version that would have been useless: *"Be careful with date filtering for performance."*

**Specificity beats coverage.** A skill that nails 8 real traps beats one that lists 40 API methods. The agent reading this already knows the basics of most of these tools; it does not know what will bite in *this* project.

**No hedging.** "Consider using X" and "it may be better to" are defects. Decide, state the decision, and say what happens if it is ignored. If something genuinely depends on a condition, name the condition.

**Cite, do not duplicate.** Doctrine lives in the `kb-*` skills. Restate an invariant in context — *"Qdrant's `Filter` must always include `org_id`; see `kb-tenancy-isolation`"* — but never redefine it, or the two copies will drift apart.

**Mark what you could not confirm.** Append `<!-- UNVERIFIED -->` to any claim you could not check against a current source. Do not silently drop it, and do not assert it confidently. The audit pass collects these.

## Length

90–200 lines including frontmatter. Under 90 usually means the research was thin. Over 200 means the detail belongs in a sibling `references/<topic>.md`, linked from the body — keep `SKILL.md` itself as the map.
