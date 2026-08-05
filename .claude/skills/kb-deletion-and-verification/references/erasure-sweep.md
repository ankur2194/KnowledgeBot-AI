# The data-subject erasure sweep (§18.10, ADR-015)

Detail split out of `kb-deletion-and-verification/SKILL.md`, which owns the rule: source deletion and data-subject erasure are two workflows with different reach, and only erasure touches the verbatim text held on the conversation side.

## The three columns

`citations.excerpt`, `retrieval_traces.selected_evidence`, `evaluation_results.retrieved_evidence`. Each stores verbatim source text and each sits outside §8.17's original artifact list — which is why nobody owned the sweep until ADR-015. All three now appear in the artifact inventory marked *source deletion: retain / erasure: purge*.

## Rules

1. **Overwrite in place; keep the row.** Set the text column to `NULL` or a tombstone marker and leave `citation_label`, `display_title`, `location_metadata`, ids, scores, ranks and timestamps intact. Deleting the rows would silently rewrite historical evaluation scores and retrieval metrics: the aggregate numbers would move because of a compliance action, and nobody would ever reconcile the shift against the reason for it.
2. **Same two-phase-plus-proof contract as every other deletion here.** Logical first (the columns stop being served), physical second (the `UPDATE` commits), then a verification pass that re-queries for the erased text **by stable identifier** — citation id, trace id, result id — and asserts absence. Erasure that is not verified is a compliance claim with no evidence behind it. Never verify by searching for the text itself; that is the banned text-matching shape from the delete-key allow-list wearing a different hat.
3. **Scope follows §18.10's four workflows.** Every scope that reaches a conversation reaches all three columns.

   | Erasure scope | Reaches the three columns? |
   |---|---|
   | User | yes — every conversation attributed to the subject |
   | Conversation | yes — that conversation's citations, traces and eval results |
   | Source | **only when raised explicitly as an erasure**, never as an ordinary source deletion |
   | Organization | yes — all conversations in the org |

   The source row is the whole reason there are two workflows. It must be an **explicit flag on the request** — `erasure: true`, set by the caller and carried through the job payload and the audit entry — never inferred from the fact that a delete happened to be raised by a compliance officer, or from any heuristic over the request.

4. **An erasure request colliding with a legal hold is REFUSED and recorded as a conflict — never partially executed.** A half-erased subject satisfies neither obligation and destroys the evidence needed to explain which parts ran. Check holds *before* phase 1; on a hit, write a conflict record naming the hold, the scope and the requesting principal, return the conflict to the operator, and change nothing. The conflict is an operator decision with a legal input; this skill's job is to make it visible, not to resolve it. This is the same reasoning behind the governance-mode-only rule on the legal-hold bucket in `SKILL.md`: a compliance-mode lock has no technical exit, so it must never be applied to data that may later face an erasure request.

## What erasure does *not* change

Everything workflow A already does still applies unchanged: `citations.chunk_id` is nulled and never cascaded, the four §8.17 verification checks still gate `Deleted`, vectors still go by point id, and the original object still obeys retention (or segregation, under a hold). Erasure is additive — a superset, not a different pipeline.
