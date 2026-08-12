# Golden case schema

Written so a reviewer can diff a case without reading the harness. If a field's meaning is not
here, it does not have one — the harness may not invent semantics for a key this document does
not define, and an unknown key is a load error rather than something ignored.

## File shape

One file per §21.2 category, under `cases/`. Every file:

```yaml
dataset: kb-platform-golden
corpus_version: corpus-2026.08.1
category: factual
cases:
  - id: f-001
    ...
```

`corpus_version` in every case file must equal `samples/VERSION` and `dataset.toml`'s
`corpus_version`. The loader compares all three and refuses to run on a mismatch. This is
belt-and-braces on purpose: the failure it prevents is a half-updated dataset producing a
plausible score against a corpus nobody can identify afterwards.

## Case fields

| Field | Required | Type | Meaning |
|---|---|---|---|
| `id` | yes | string | Stable forever, `<prefix>-NNN`. Never reused after retirement — a recycled id makes historical runs lie. Prefixes: `f` factual, `e` exact-codes, `s` synthesis, `t` tables, `d` date-sensitive, `a` ambiguous, `u` unanswerable, `c` conflicting, `fu` follow-up, `m` multilingual. |
| `question` | yes | string | Exactly what is sent to the pipeline. Changing one word is a dataset change and bumps `VERSION`. |
| `unanswerable` | yes | bool | Whether the corpus contains the answer. Drives `false_refusal` and `false_answer` and the CRAG score. Required even when false — an omitted default is how a case silently leaves the unanswerable denominator. |
| `expected_sources` | yes | list of **manifest ids** | Which fixtures must appear in the retrieved evidence. **Manifest ids, never paths** (see below). Empty list `[]` for unanswerable cases, and empty means *"no source should be sufficient"*, not *"unchecked"*. |
| `difficulty` | yes | `easy`/`medium`/`hard` | Reporting only. Never weights a score; a weighted aggregate hides which half moved. |
| `grading_notes` | yes | string | For the human reviewer. §21.3 makes human review mandatory, and a reviewer with no rubric produces a different verdict each time. |
| `reference` | **conditional** | string | Ground-truth answer text. **Required for ContextRecall** — see below. Omit only for `unanswerable: true`. |
| `expected_answer` | no | string | A model answer for human review. Never string-matched. |
| `required_facts` | no | list of string | Facts the answer must contain. Checked deterministically (normalized substring / numeric match), no judge call. This is what catches the *grounded but wrong* answer that faithfulness cannot see. |
| `forbidden_claims` | no | list of string | Claims whose presence is a failure — a superseded rate, a competitor's number, an invented code. Also deterministic. |
| `history` | no | list of `{question}` | Preceding turns for follow-up cases. See below. |
| `clarification_ok` | no | bool (default false) | A clarifying question is an acceptable response and is not scored as a false refusal. See below. |
| `unanswerable_kind` | no | enum | Why it is unanswerable: `absent_fact`, `out_of_domain`, `false_premise`, `nonexistent_entity`, `not_yet_published`, `out_of_crawl_scope`, `not_derivable`, `pii_absent`. Unanswerable cases must set it; reporting the breakdown is how a threshold change shows *which* kind of refusal it bought. |
| `language` | no | BCP-47, default `en` | Question language. `answer_language` in `grading_notes` states the expected reply language when it differs. |
| `tags` | no | list of string | Free-form, for slicing a run. Never affects scoring. |
| `locators` | no | list of string | Human-readable pointers into the fixture (`"§3 Leave"`, `"sheet Instruments, row KW-2200-B"`, `"slide 4 speaker notes"`). **For the fixture author and the reviewer only.** Never used for scoring — a locator is prose, and matching against it would make the score depend on how the chunker happened to label a section. |

## `expected_sources` lists manifest ids

```yaml
expected_sources: [pricing-catalog-v1, quarterly-review-q2-2026]   # correct
expected_sources: [corpus/documents/pricing/pricing-catalog-v1.xlsx]  # WRONG
```

Ids resolve through `corpus/manifest.toml`. A path in a case file means a file rename breaks
forty-five YAML files silently — source recall quietly drops to zero for the renamed fixture and
reads as a retrieval regression. The manifest is the single indirection that makes a rename a
one-line edit.

## `reference`, and why omitting it SKIPS rather than zeroes

`ContextRecall` takes `user_input`, `retrieved_contexts`, and **`reference`**. It has no
reference-free variant in our metric set. A case with no `reference` cannot be scored by it.

**A case with no `reference` is SKIPPED for ContextRecall, and the skip is counted.** It is not
scored 0. A zero is a claim that retrieval failed; a skip is a statement that the metric did not
run. Averaging skips as zeros drags the mean down by an amount proportional to how many cases
someone forgot to write a reference for, which is not a property of the pipeline.

The same rule governs `Faithfulness`, for a different reason, and it is the reason
`expected/baseline.json` carries a **triple** — `faithfulness_mean`, `faithfulness_n`,
`faithfulness_skipped` — rather than a mean:

- Ragas returns `MetricResult(value=nan)` when statement extraction yields nothing, which is
  exactly what a refusal produces.
- The legacy aggregator uses `np.nanmean` and drops those rows from the denominator entirely.
- So a run where a third of the answers were refusals reports a *higher* faithfulness, and the
  number rises every time the bot becomes more cautious. The metric structurally cannot see the
  failure mode we care about most.

Refusals are therefore skipped **explicitly**, `faithfulness_n` is reported beside every mean,
and **the gate fails when `faithfulness_skipped` moves**, independently of what the mean did. A
mean without its `n` is not a result.

Likewise: a run in which judge calls **errored** is a failed run. Errored judgements are counted
separately from skips (a skip is a decision, an error is a failure) and a run whose judge-error
count is non-zero does not produce a publishable score.

## `history` — follow-up cases

```yaml
- id: fu-001
  history:
    - question: "What is the list price of KW-2200-B?"
  question: "And the field variant?"
```

Each `history` entry carries **only a question**. The harness runs each one through the real
pipeline, in order, in the same conversation, and lets the pipeline's own answer become the
assistant turn. The graded case is the final `question`.

Hand-writing the assistant's replies would be easier and wrong: it tests a conversation the bot
never had. Query rewriting (stage 2) resolves *"And the field variant?"* against whatever the
assistant actually said — if the first turn refused, or answered about the wrong instrument, the
follow-up is supposed to be affected. Pinning an idealised history hides precisely the failure a
follow-up case exists to find.

Consequence, stated plainly: **follow-up cases cost more than one pipeline run each** and their
variance is higher, because two stochastic turns compose. Their per-case detail must record every
turn, not just the last.

## `clarification_ok` — ambiguous cases

`false_refusal = refused and not unanswerable`. An ambiguous question answered with *"Do you mean
the benchtop or the field variant?"* is a **good** response that this formula scores as a false
refusal, which would push the gate toward a bot that guesses.

So: a case with `clarification_ok: true` that produced a clarifying question is
- **excluded** from the false-refusal denominator,
- counted in a separately reported `clarification_rate`, with its denominator being the
  `clarification_ok` cases only,
- and still fully scored on retrieval metrics — a clarifying question does not excuse failing to
  retrieve the ambiguous alternatives.

A `clarification_ok` case that instead answers *both* interpretations, with citations, is also
correct and is scored normally. Only silently picking one interpretation is a failure, and
`forbidden_claims` on those cases is what catches it.

> **Note for the harness author.** `ragas-evaluation`'s `aggregate()` divides false refusals by
> *all* answerable cases. The exclusion above is a deliberate refinement, not a disagreement, and
> it must be implemented as a *reported* exclusion — `false_refusal_rate` alongside
> `clarification_ok_excluded: 4` — so the denominator stays visible. Never silently narrow a
> denominator.

## Version pinning: manifest id here, `source_version_id` at run time

This is the nuance that decides whether a corpus can be checked into git at all.

`ragas-evaluation` requires that a case pins **`source_version_id`s, not source ids**: when a
source is recrawled or reprocessed, a new version activates, the fixed question set is now asking
about different text, and every case pinned to the old version must be marked `stale` and
excluded from the aggregate rather than silently scoring against new content.

That requirement applies to the **`evaluation_cases` rows** — database records that exist only
*after* the corpus has been ingested into an organization. It cannot apply to a file in git:

- a `source_version_id` is minted by ingestion, so it does not exist when the file is written;
- it is different in every environment — CI, a developer's stack, a re-seed after a wipe;
- committing one would make the dataset valid in exactly one database, forever.

**So the committed case pins what a file can honestly pin: a `manifest id` plus the fixture's
`sha256`.** Those two together identify the bytes, which is the thing a question is actually
about.

The seeding step materialises the version pin, once per run:

1. ingest each fixture; ingestion mints a `source_version_id`,
2. verify each fixture's bytes against the manifest `sha256` — a mismatch aborts,
3. write the `manifest id → source_version_id` map into the `evaluation_runs` record,
4. materialise `evaluation_cases` rows whose `expected_sources` carry those `source_version_id`s.

From that point on the run behaves exactly as the skill requires: the rows are version-pinned, a
version change marks affected cases stale, the stale count is reported, and a run whose stale
count changed is not comparable to the previous one.

Conflating the two layers produces one of two broken outcomes: a corpus that cannot be committed
(version ids in git), or a run with no version pinning at all (manifest ids in the database).
They are different pins for different lifetimes, and both are required.

## What a case may not do

- **May not reference a fixture absent from the manifest.** Load error.
- **May not depend on text unique to `scanned-po-88214` page 3.** That page is deliberately
  degraded; OCR output on it is not stable across engine versions and a case grading against it
  would fail for reasons unrelated to retrieval.
- **May not encode a threshold, a top-K, or any pipeline default.** The dataset measures the
  pipeline; it never restates its configuration. If a case only passes at a particular
  `evidence.min_score`, that is a finding for `retrieval-engineer`, recorded with evidence — not a
  number copied into this directory.
- **May not contain real tenant content, real personal data, or a real company's figures.**
