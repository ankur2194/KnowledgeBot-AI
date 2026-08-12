# The noise floor

**Status: NOT YET MEASURED.** Every tolerance in `baseline.json` is `null` and must stay null until
the procedure below has been run. This file is the prerequisite for setting any of them.

## What run-to-run spread is

Run the identical dataset, against the identical corpus, through the identical pipeline, with the
identical judge, twice, changing nothing. The two aggregate scores will differ.

That difference is the **noise floor**. It is not a bug to be fixed, it is a property of the
instrument, and the only actionable question about it is *how big is it*. Everything smaller than
the floor is indistinguishable from having changed nothing at all.

**A gate tighter than the noise floor fails randomly.** Once a required check has failed twice for
no reason, the team learns to re-run it until it passes, and at that point the gate has negative
value: it costs time, it blocks nothing, and it has trained everyone to disregard the one signal
that would have caught a real regression. A metric that moves when nothing changed is worse than
no metric.

## Where the variance comes from

Ordered roughly by expected contribution, and none of these is fixable from this directory.

1. **Provider nondeterminism at temperature 0.** Inference kernels are not batch-invariant, so the
   floating-point reduction order depends on server-side batching, which depends on other people's
   load. Thinking Machines measured **80 unique completions out of 1,000** at temperature 0. Both
   the answering model and the judge are affected. This alone puts a hard floor under everything
   below and no configuration removes it.
2. **The judge's own instability.** An audit of LLM-as-judge stability found the strongest model
   flipping **14.7% of its verdicts** under nothing more than A/B order reversal. Faithfulness is a
   two-call judged metric over generated text that itself varied.
3. **Ragas' defaults are not zero.** `InstructorModelArgs` defaults to `temperature=0.01,
   top_p=0.1`, and the legacy `get_temperature(n)` returns `0.3` whenever a metric samples more than
   one completion. Our adapter sets `temperature=0.0` explicitly, which removes this term — verify
   it did, rather than assuming, since Ragas' `seed` parameter is dead code in 0.4.3 and the
   documented `in_ci=True` reproducibility flag has been deleted from the codebase while still
   appearing in the docs. There is no library-level determinism lever left.
4. **Compounding across turns.** The four `follow-up` cases run two stochastic pipeline turns each,
   and the second turn is conditioned on the first turn's actual output. Their variance is
   structurally higher and must be measured separately, not pooled.
5. **Re-ingestion.** Re-seeding the corpus rebuilds the index. Retrieval over a *fixed* index is
   effectively deterministic; over a *rebuilt* one it need not be — HNSW graph construction depends
   on insertion order, and insertion order depends on how ingestion parallelised. This produces a
   second, larger floor. See below.
6. **Sampling.** 46 questions is a sample. A bare mean over 46 cases has no error bar, and several
   cases share one fixture, so they are not independent draws.

## Two floors, and the gate needs the larger one

| Floor | Procedure | What it bounds |
|---|---|---|
| **Within-index** | Seed the corpus once. Run the suite N times against that same index. | Judge and generation variance only. The smaller number. |
| **Across-seed** | Re-seed the corpus from scratch N times, one run each. | Everything above, plus index-construction variance. |

The gate compares a PR's run to a baseline that was very likely produced against a *different*
seeding of the corpus — CI does not preserve a Qdrant volume between runs. **So the tolerance band
is set from the across-seed floor.** Using the within-index floor produces a band that looks
rigorous and fails on Tuesdays.

If the across-seed floor is materially larger than the within-index floor, that gap is itself a
finding for `retrieval-engineer` — it means retrieval results depend on ingestion ordering — and it
is reported with the measurements, not worked around by re-seeding less often.

## Procedure

Before setting a single threshold:

1. **Freeze everything.** One corpus version, one dataset version, one judge (record
   `resolved_model_id` and `system_fingerprint` on every call — under a floating alias the
   fingerprint is the only thing that moves when the weights do), one retrieval configuration
   snapshot, one pipeline commit.
2. **N ≥ 5 runs per floor**, and 5 is the minimum that produces a usable spread, not a target. If
   the budget allows 10, do 10 — the standard error of a standard deviation is itself large at
   n=5.
3. **Discard no run.** A run that errored is a data point about the harness and gets reported; it
   is not an outlier to be dropped. A run with non-zero judge errors produces no score at all
   (`baseline.json` → `comparability.refuse_if_nonzero`), and how often that happens is exactly
   what step 6 is asking about.
4. **Report per metric:** mean, standard deviation, min, max, and the full list of values. The list
   matters — a metric that is stable four times and jumps once is a different problem from one that
   wanders, and a standard deviation hides the difference.
5. **Report per category too.** Aggregate stability hides an unstable category. Expect `synthesis`,
   `conflicting`, `ambiguous`, and `follow-up` to be the loud ones, and `factual`, `exact-codes`,
   and `tables` to be nearly silent — those three are deterministic string and number checks over a
   retrieval step that does not vary within an index.
6. **Report the operational numbers:** how many judge calls errored across all runs, how many cases
   were skipped, wall-clock per run, and total judge spend. A floor measurement that does not tell
   you what a full run costs has not finished.

Record the results in this file, dated, with the corpus version, the judge id and fingerprint, and
the pipeline commit they were measured against. **Re-measure whenever the judge changes**, because
a new judge has its own floor and the old measurement describes an instrument that no longer
exists.

## Setting the band from the measurement

Do not gate on a raw mean difference. Two runs differing by 0.03 will block a release, or a real
5-point regression will ship, depending only on which side of an arbitrary line the noise landed.

- **Use paired differences** on the same question set. Question difficulty is the largest source of
  variance in an eval set and pairing removes it entirely.
- **Cluster the standard errors by `source_version_id`.** Several cases share one fixture — five
  against the price list alone — and their errors are correlated. Treating them as independent
  understates the interval badly, and understating the interval is what produces a gate tighter
  than the floor. `arXiv:2411.00640` is the recipe.
- **The gate compares intervals, not point estimates.** It fires when the confidence interval of the
  paired difference excludes zero *and* the point estimate exceeds the band.
- **Set each band above that metric's own measured spread.** A single global tolerance is wrong:
  `hit_rate` over an unchanged index barely moves, while `faithfulness_mean` moves on every run.
- **Different cadences, different bands.** Deterministic metrics are cheap, judge-free, and stable
  enough to run on every change with a tight band. Judged metrics run on schedule with a wide one.
  Ragas never gates a unit suite.

Two rules are **not** measurements and are not negotiated against the floor:

- **`faithfulness_skipped` must not move.** If the skip count changes, the mean is a mean over a
  different set of cases and the two numbers are not comparable at any tolerance. Baseline holds
  `faithfulness_skipped_delta: 0`.
- **A run with any judge error produces no score.** Not a lower score — no score. Averaging over the
  survivors reports a number for a run that did not happen.

## If a metric's floor is too wide to gate on

Say so, and do not gate on it. A metric whose run-to-run spread exceeds the effect size anyone
cares about is a *diagnostic*, reported on the dashboard and read in aggregate over time — not a
release gate. Widening the band until the noisy metric fits is the same mistake as tightening it
below the floor, arrived at from the other direction: the gate stops distinguishing anything.

The deterministic half exists precisely so that something in this suite can be gated tightly.
`false_answer_rate`, `hit_rate`, `source_recall`, and `citation_correctness` involve no judge call,
and over a fixed index most of them should be flat. **If they are not flat, stop and find out why
before measuring anything else** — a deterministic metric with a wide floor means something is
varying that should not be, and every judged measurement taken on top of it inherits that variance.

## References

- [Adding Error Bars to Evals (arXiv:2411.00640)](https://arxiv.org/abs/2411.00640) — paired
  differences and clustered standard errors for eval sets.
- [Defeating Nondeterminism in LLM Inference](https://thinkingmachines.ai/blog/defeating-nondeterminism-in-llm-inference/)
  — why temperature 0 is not reproducible.
- [How to Correctly Report LLM-as-a-Judge Evaluations (arXiv:2511.21140)](https://arxiv.org/abs/2511.21140)
  — bias correction against a human-labelled calibration set.
- [RAGChecker (arXiv:2408.08067)](https://arxiv.org/html/2408.08067v1) — instance-level versus
  system-level agreement; why a single judged score is never a gate on its own.

---

## Measurements

*(empty — nothing has been measured. Append dated blocks below, newest first, and never edit an
older one: a superseded floor is evidence about the instrument's history.)*

Template:

```
### YYYY-MM-DD — across-seed floor, N=…
corpus_version: …           judge: … (fingerprint …)      ragas: 0.4.3
pipeline_git_sha: …         retrieval_configuration_version: …

metric                    mean     sd      min      max     values
faithfulness_mean         …        …       …        …       [ … ]
faithfulness_n            …        …       …        …       [ … ]
faithfulness_skipped      …        …       …        …       [ … ]
false_answer_rate         …        …       …        …       [ … ]
…

judge calls errored: …    cases skipped: …    wall clock: …    judge spend: …
Notes: …
```
