---
name: kb-tenancy-isolation
description: The tenant-isolation contract for KnowledgeBot AI — org ownership on every relational record, the four mandatory Qdrant payload filters, and tenant-safe storage, cache, and queue keys. Use whenever writing a query scope, a vector search, a storage path, a cache key, a background job, an analytics aggregate, or an export. Isolation is enforced in code at seven layers, never delegated to the datastore or the LLM. Pairs with qdrant-hybrid-search (client API) and kb-rag-query-contract (the pipeline around it).
---

# KnowledgeBot Tenancy Isolation

Cross-cutting doctrine, not tied to a library version — it binds Laravel/PHP (control plane), FastAPI/Python (data plane), Qdrant, Valkey, and SeaweedFS S3 alike. Stack pins live in `docs/05-tech-stack.md` §9.
**Authoritative spec:** docs/02-functional-auth-tenancy-bots.md §8.2, docs/07-rag-query-pipeline.md §12.6, docs/11-data-model.md §16, docs/13-security.md §18.4, docs/17-testing-performance.md §22.5, docs/21-risks-licensing-glossary.md §34 (*"Cross-tenant vector leakage — Severe security issue"*)

**What the leak looks like.** HTTP 200. Normal latency. Normal candidate counts. A well-formed answer with well-formed citations pointing at a document the organization never uploaded. Qdrant's own multitenancy guide says the quiet part plainly: *"global requests (without the `group_id` filter) will be slower since they will necessitate scanning all groups."* **Slower. Not rejected.** An unfiltered query is a legal, successful query that returns every tenant's vectors, and nothing in the response distinguishes it from a correct one. No exception, no log line, no metric. It is found by a customer, not by monitoring — and without `retrieval_traces.filters` you cannot even bound which past answers were affected. That silence is the entire reason this file exists.

**And nothing below you enforces it.** Qdrant removed payload-restricted JWT claims in v1.16 (*"Remove payload filter from RBAC/JWT… API keys using it are rejected"*); the remaining `access` claim is collection-granular only, and `value_exists` is a revocation check, not a scope. The `is_tenant=true` payload index is a disk-locality hint, not an access control. On Qdrant ≥1.16 **every tenant boundary in this platform is a line of our code.** <!-- UNVERIFIED: v1.16 changelog + maintainer comment in qdrant discussion #7987, read at master; confirm against the version we actually pin -->

## Non-negotiables

1. **Every tenant-owned relational record reaches an organization.** Either it holds `organization_id` directly — `organization_users`, `provider_connections`, `bots`, `knowledge_sources`, `conversations`, `provider_calls`, `evaluation_datasets`, `background_jobs`, `audit_logs`, `usage_events` (§16) — or it reaches one through a `NOT NULL` foreign-key chain no code path can bypass: `chunks → source_versions → source_items → knowledge_sources`, `citations/feedback → messages → conversations`, `bot_domains/bot_starter_questions → bots`. A nullable link in that chain is an orphan waiting to be read by whoever asks.
2. **`bot_source_assignments` is the one row that can span two organizations.** `bot_id` and `source_id` each inherit their own org and nothing in the FK graph forces them to agree; §8.3 says a source may be assigned to bots *in the same organization*. Enforce it in the service **and** in the schema — denormalize `organization_id` onto the row and make `(organization_id, bot_id)` and `(organization_id, source_id)` composite foreign keys. One mis-scoped insert here is a permanent leak that every downstream filter agrees with, because you taught it that Org B's source belongs to Org A's bot.
3. **Every Qdrant query carries all four terms in `must`, unconditionally** (§12.6): organization, bot access, active source status, active source version. `must` is the only clause that behaves. `should` is an OR over its list, and Qdrant's filter merge concatenates clause lists *per type* — a tenant condition in `should` gets appended to whatever `should` the sub-query already had and widens into `tenant OR anything_else`. Not `should`. Not assembled inside an `if`. Not defaulted to `Filter()`. Not skipped "because this collection has one tenant today".
4. **No bypass exists, because none is built.** There is no admin endpoint, no debug route, no `internal=True` kwarg, no env flag, and no test fixture that turns the filter off. "FastAPI is only reachable from Laravel" is network topology, not authorization (§11.1) — Milvus's CVE-2025-64513 was exactly one trusted internal header away from full admin. Cross-org support access is an impersonation flow that *sets* an organization context, never one that removes it.
5. **Tenancy is expressed positively and fails closed.** `must: [match org_id == X]`, never `must_not: [match org_id == Y]`. A `match` condition is not satisfied by a point that lacks the key at all — which is why Qdrant ships a separate `is_empty` for "absent or null". So a point whose payload lost `org_id` (a rebuild job that forgot it) fails every `must` and passes every `must_not`: under a positive filter it is visible to nobody, under a negative filter it is visible to everybody. One of those is a support ticket, the other is a breach.
6. **The organization identifier comes from the authenticated context, never from request input.** Laravel derives it from the session or token; the signed internal request carries it to FastAPI (§11.2); FastAPI trusts that field and nothing else. An `org_id` a caller can set is not a scope, it is a parameter.
7. **All seven layers, every time** (§8.2): HTTP authorization, query scopes, service-layer policies, vector filters, object-storage paths, analytics queries, logs and exports. Scoping the read path and not the write path is its own CVE class (Filament CVE-2026-48067: the select query was tenant-scoped, the validation rule for the same field was not). "The layer above already checked" is the reasoning that produced every incident here.

## How we use it

**Payload contract.** Every Qdrant point carries these six fields so the filter is expressible at all (superset in §14.6): `org_id`, `bot_ids` (array), `source_id`, `source_item_id`, `source_version_id`, `source_status`. Written at upsert from the chunk's own FK chain — never from a job argument, never defaulted. **Six is the floor that makes the filter expressible, not the whole payload and not a list of the only keys a filter may name** — §14.6 is a superset carrying `chunk_id`, `seq`, `page`, and the rest of `kb-chunking-rules`' metadata schema. Deletion legitimately filters on `chunk_id` to remove a single chunk by identity, which is why CI's deletion-key allow-list is these six **plus `chunk_id`** — seven, deliberately not six (`github-actions-pipeline`). Do not reconcile the two numbers by changing either set. One collection per embedding configuration with payload partitioning (§15.3), matching Qdrant's own recommendation; collection-per-tenant is not an option we grow into, since Qdrant Cloud caps a cluster at 1000 collections.

**Which terms are load-bearing.** `org_id` and `source_version_id` are: the first is the authenticated backstop that survives an IDOR on `bot_id`; the second is resolved from PostgreSQL per request and is what makes a disable or a delete take effect *immediately* (§8.17) without rewriting millions of payloads. `bot_ids` and `source_status` are the redundant pair that catches a bug in the resolver — they lag a payload rewrite, and that is fine; they exist to fail closed, not to be timely. All four are mandatory. The redundancy *is* the design.

```python
# services/ai-service/app/retrieval/tenancy.py
from qdrant_client import models

def tenant_filter(ctx: TenantContext, allowed_version_ids: list[str]) -> models.Filter:
    """The only constructor of a Filter for a tenant-facing operation.

    ctx.org_id comes from the signed internal request (§11.2), never the payload.
    allowed_version_ids is resolved in PostgreSQL from bot_source_assignments ⋈
    knowledge_sources ⋈ source_versions, restricted to ctx.org_id and to versions
    active *right now* — that resolution is what makes a disable take effect at once.
    **Laravel runs that query and ships the result in the config snapshot; FastAPI
    does not query Laravel's tables** (kb-internal-api-contracts). It arrives as an
    argument precisely so this function has no database of its own to get wrong.
    No default value, no Optional: an unfiltered call must be unrepresentable.
    """
    if not ctx.org_id or not allowed_version_ids:
        # An empty scope is a valid outcome. Widening it is not.
        # Filter(must=[]) is a match-all — `all()` over an empty list is True.
        raise EmptyScopeError(ctx.org_id)
    return models.Filter(must=[   # must, never should — see Non-negotiable 3
        models.FieldCondition(key="org_id", match=models.MatchValue(value=ctx.org_id)),
        models.FieldCondition(key="bot_ids", match=models.MatchAny(any=[ctx.bot_id])),
        models.FieldCondition(key="source_status", match=models.MatchAny(any=["ready", "ready_with_warnings"])),
        models.FieldCondition(key="source_version_id", match=models.MatchAny(any=allowed_version_ids)),
    ])
```

**The correctly-filtered hybrid query, and the broken twin.** The difference is `filter=f` on two lines.

```python
f = tenant_filter(ctx, allowed_version_ids)

# CORRECT — every prefetch branch carries the filter, and so does the fusion stage.
# This is the *filter* contract, shown on the prefetch/fusion shape. Production chat does
# NOT fuse server-side: `kb-rag-query-contract` §12.9 fuses in Python because stage 9 needs
# each candidate's per-branch rank AND score, which a server fusion result never returns.
# The rule below is identical either way — two filtered branch calls each carry all four terms.
result = client.query_points(
    collection_name=COLLECTION,
    prefetch=[
        models.Prefetch(query=dense_vec,  using="dense",  limit=20, filter=f),
        models.Prefetch(query=sparse_vec, using="sparse", limit=20, filter=f),
    ],
    query=models.FusionQuery(fusion=models.Fusion.RRF),
    limit=20,
    query_filter=f,   # note the name: top level is `query_filter`, Prefetch is `filter`
)

# LEAK — identical but for the two missing `filter=f`.
result = client.query_points(
    collection_name=COLLECTION,
    prefetch=[
        models.Prefetch(query=dense_vec,  using="dense",  limit=20),
        models.Prefetch(query=sparse_vec, using="sparse", limit=20),
    ],
    query=models.FusionQuery(fusion=models.Fusion.RRF),
    limit=20,
    query_filter=f,
)
```

Why the broken one ships: against a real Qdrant server it usually *works*. The server propagates the top-level filter down into prefetch leaves, so results look correct and the bug reads as a mild recall dip. Two things make that a trap. First, the propagation is an undocumented implementation detail — `hybrid-queries.md` does not contain the word "filter" — and the merge it uses is the same per-clause concatenation from Non-negotiable 3, so it only ANDs correctly because our terms are in `must`. Second, `QdrantClient(":memory:")` does not implement it at all: its RRF/DBSF fusion path ignores the root filter entirely and fuses, then retrieves by ID unfiltered. **The broken version leaks other tenants' points under the in-memory client that most unit tests use, and is safe in production only by an accident nobody wrote down.** Never rely on inheritance you cannot cite. <!-- UNVERIFIED: propagation and the local-client divergence were read from qdrant and qdrant-client source at master, not from documentation — recheck on version bumps -->

**Not defined here.** Qdrant client API, collection setup, and hybrid/fusion query syntax → `qdrant-hybrid-search`. Laravel policy, gate, and RBAC mechanics → `laravel-rbac-policies`. The surrounding retrieval pipeline — rewriting, fusion, rerank, thresholds, citations → `kb-rag-query-contract`. Credential encryption and secret handling → `kb-security-baseline`. This skill owns the filter and the scoping rules, nothing else.

**Namespaces.** Object storage: `org/{org_id}/sources/{source_id}/versions/{source_version_id}/…`. Valkey keys are **family-first, org second — `{family}:{org_id}:…`** — for every family: `ans:{org_id}:…`, `lock:{org_id}:…`, `idem:{org_id}:…`, `sess:{org_id}:{bot_id}:…`. The ordering is not cosmetic: Valkey ACL key patterns match on prefixes, so family-first is what lets `ai-api` be granted `~ans:*` without also being granted the queues, and it makes an org-wide purge a deterministic walk of known family prefixes instead of a `SCAN MATCH` that can miss keys written mid-iteration. `valkey-keyspaces` owns the full catalog; any retrieval- or answer-cache key additionally hashes the resolved `allowed_version_ids` set. Rate-limit counters key on `{org_id}:{bot_id}`, never on a slug. The one family with no org segment is the replay nonce, which is checked before any organization has been resolved.

**Every search-shaped call is a tenant-facing call.** `scroll`, `count`, `retrieve`, `recommend`, `discover`, `delete` by filter, and `set_payload` by filter all take the same builder. A `count()` without it leaks cardinality; a `delete()` without it destroys another tenant's index.

## Gotchas

- **Retrieval returns another org's content and the filter looks correct in review.** It was assembled conditionally — `if bot.restrict_sources: conditions.append(...)` — so a config flag, a null column, or an empty optional silently produces a narrower filter. Build the four terms in one expression that cannot be reached partially; append the optional filters of §12.6 (language, source type, tags, effective date) to a copy, never to the mandatory list.
- **A search returns every tenant's data and nothing raised.** Someone wrote `filter or models.Filter()` or gave a filter parameter a default. `Filter(must=[])` is a confirmed **match-all** — the server checks `must` with `.all()` over the list, and `.all()` of nothing is true. The asymmetry compounds it: `should: []` matches *nothing* over REST, and over gRPC empty clause vectors normalize to absent, so a malformed tenant condition is silently dropped rather than rejected. Make filters positional and required, and raise on empty scope.
- **Isolation tests pass; the same code leaks in staging.** Two causes, both fatal. The fixture seeded one organization — a single-tenant fixture cannot fail an isolation test, because there is nothing to leak. Or the test ran against `QdrantClient(":memory:")`, whose fusion path ignores the root filter. Isolation tests need two orgs, a canary string in the *other* org's content, and a real Qdrant container.
- **A rebuilt source becomes invisible to its own organization** (§15.5). The rebuild job read `chunks` and upserted without the six payload fields, because `chunks` inherits its org three tables up and the job never joined that far. The positive `must` filter then excludes them — the correct failure. The wrong fix is relaxing the filter; the right one is asserting the payload at write time and re-running the rebuild.
- **A deleted or disabled source keeps being cited for minutes.** The retrieval/answer cache key held org and bot but not the active-version set, so a cached hit outlives the filter meant to exclude it. §8.17 requires proving *no retrievable cache entry remains* — a version-set fingerprint in the key makes that automatic instead of a purge you must remember. If an answer cache is ever keyed by *embedding similarity* rather than exact text, note that a 0.97 cosine hit can serve another tenant's cached completion; scope such a cache per organization or do not enable it.
- **Two organizations throttle each other, or one sees the other's consent/quota state.** A Valkey key built from `bot.slug` or `source.name` — unique *per organization*, not globally. This is a shipped CVE class, not a hypothetical: WSO2 CVE-2025-13475 keyed consent records by application name so *"consent granted in one tenant is incorrectly applied to same-named applications in other tenants."* Prefix every key derived from a user-chosen string with `org_id`; §9.8's separate logical databases are not a substitute.
- **Deleting one tenant's object removes a live file for another.** Content-addressed paths (`objects/{sha256}`) dedupe identical uploads across organizations — one shared price list becomes one object. That is an existence oracle and a deletion hazard at once. Paths start with `org/{org_id}/`; dedupe only within an organization.
- **A dashboard or CSV export shows another org's numbers.** Aggregates over `usage_events`, `provider_calls`, and `messages` get grouped by `bot_id` and the `organization_id` predicate is dropped as redundant. §8.2 lists analytics and exports as their own layers precisely because that reasoning is so easy to accept.
- **Qdrant has two operations with no filter parameter at all.** `lookup_from` and `with_lookup` take no filter, and `WithLookup.with_payload` defaults to **true** — so `group_by` + `with_lookup` fetches whole payloads out of another collection with no tenant condition anywhere. Likewise `retrieve` by point ID resolves the point regardless of org, making it an existence oracle. Never expose a point ID to a client, and authorize any ID-addressed fetch against PostgreSQL first.
- **The admin playground's "excluded results and reasons" panel (§8.24) is a leak surface by design.** To explain why a candidate was dropped you must first have retrieved it. Exclusions may only be reported for stages *after* the tenant filter — threshold, dedup, diversity, rerank. If the panel can ever say "excluded: wrong organization", the query behind that line was unfiltered.
- **A job writes correct-looking rows attributed to the wrong organization.** Queue workers reuse processes, so tenant context is a pooled resource that retains its previous occupant — the same shape as the redis-py bug behind the March 2023 ChatGPT leak (CVE-2023-28859), where a cancelled request left a connection holding the next reader's data. *No* tenant set is the loud failure; the *previous* tenant still set is the silent one. Jobs carry `organization_id` in their payload (§16.8) and set the context explicitly on entry and clear it in a `finally`; a job that cannot determine its org fails rather than proceeding.
- **A Laravel global scope silently does not apply.** `DB::table()`, `DB::select()`, and raw SQL never touch the Eloquent builder, so no scope runs; `withoutGlobalScopes()` with no arguments removes *all* of them, tenancy included. Both are greppable — make CI grep for them. (PostgreSQL RLS as a backstop was evaluated and **declined for production** — a table's owner bypasses its own policies unless the table is `FORCE ROW LEVEL SECURITY`, and pgbouncer in transaction pooling does not support the session `SET` the usual `current_tenant` pattern depends on, so the policy leaks the *previous* tenant's value while reviewing as correct. `postgresql-patterns` carries the full reasoning and the one form we do adopt: RLS enabled in the **CI database only**, against a non-owner role, as a tripwire that makes an unscoped query return too few rows and fail the isolation test.)
- **"Only the vectors leaked, not the text" is not a mitigation.** Embedding inversion recovers roughly 92% of 32-token inputs exactly (Morris et al., EMNLP 2023). A vector is not a hash and is not de-identified; cross-tenant read access to embeddings alone is disclosure of the underlying documents.

## Official docs

- [Qdrant — Multitenancy](https://qdrant.tech/documentation/manage-data/multitenancy/) — payload partitioning as the default, `is_tenant`, and the collection-count ceiling.
- [Qdrant — Filtering](https://qdrant.tech/documentation/search/filtering/) — `must`/`should`/`must_not` semantics and `is_empty` vs missing keys.
- [Qdrant — Hybrid queries](https://qdrant.tech/documentation/concepts/hybrid-queries/) — prefetch and fusion shapes; note it says nothing about filters.
- [Qdrant — Security](https://qdrant.tech/documentation/security/) — what the JWT `access` and `value_exists` claims can and cannot scope.
- [OWASP — Multi-tenant security cheat sheet](https://cheatsheetseries.owasp.org/cheatsheets/Multi_Tenant_Security_Cheat_Sheet.html) — composite-key lookups and repositories that raise on unscoped operations.
- [Azure — Test your isolation model](https://learn.microsoft.com/en-us/azure/architecture/guide/multitenant/considerations/tenancy-models) — the only mainstream vendor guidance that treats isolation as something you continuously *test*.

## Definition of done

- [ ] Every new table either has `organization_id NOT NULL` or a `NOT NULL` FK chain to one; join tables across two org-owned entities carry the composite-FK guard.
- [ ] Every Qdrant call goes through `tenant_filter(...)`; `grep -rnE "query_points|\.search\(|\.scroll\(|\.count\(|\.retrieve\(|\.delete\(" services/ai-service --include=*.py` shows no construction outside the retrieval wrapper.
- [ ] Each `Prefetch(...)` in the change set carries `filter=`; all four terms sit in `must`; no filter parameter anywhere has a default value; empty scope raises.
- [ ] Payload assertion covers upsert *and* rebuild — a point missing any of the six fields is rejected at write time, and a test proves such a point is retrievable by nobody.
- [ ] Two-organization isolation tests run against a real Qdrant (not `:memory:`) for the changed surface (§22.5): API read, vector search, analytics aggregate, export, and cache hit — each asserting Org B's canary is absent from Org A's result.
- [ ] Storage paths, cache keys, lock keys, and rate-limit keys begin with the organization identifier; retrieval and answer cache keys also include the active-version fingerprint.
- [ ] `retrieval_traces.filters` records the *resolved* filter — real org, real version IDs — for every query the change set can issue.
- [ ] CI greps for `withoutGlobalScopes(`, `DB::table(`, and raw `DB::select(` in tenant-owned code paths, and fails on new occurrences. **Legitimate exceptions exist and must be annotated, never silently excluded** — the recrawl dispatcher's `FOR UPDATE SKIP LOCKED` claim query (`laravel-scheduler`) is deliberately org-*agnostic*, because it selects across tenants and sets org context per row. Each one carries an inline `// tenancy-exempt: <reason>` marker that the grep allow-lists, so the exception is reviewable and a new unmarked raw query still fails the build.
