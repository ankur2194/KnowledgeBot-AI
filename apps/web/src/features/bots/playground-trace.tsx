'use client';

import type {
  RetrievalTrace,
  RetrievalTraceCandidate,
  RetrievalTraceData,
  RetrievalTraceExclusion,
} from '@kb/contracts/admin';

import { StatusPill } from '@/components/status-pill';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { EM_DASH } from '@/features/analytics/api';

/**
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *  THE RETRIEVAL PANEL — §8.24's "excluded results AND REASONS", which is the half that gets cut
 * ═══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Everything here comes from ONE `retrieval.trace` frame, which reaches this client only through
 * `@kb/contracts/admin` — the subpath `apps/widget` does not import, because a client that can NAME
 * the frame is a client that can render internal topology on a stranger's marketing site.
 *
 * ── EVERY FIELD IS READ DEFENSIVELY, AND THAT IS THE DATA PLANE'S DECISION RATHER THAN CAUTION ──
 * `RetrievalTraceFrame` types its payload as `dict[str, Any]` and says why: the shape is
 * `app/rag/runner.py::RetrievalTrace`, *"which is the pipeline's own record and changes with the
 * pipeline; a second transcription of it here would be a second thing to keep in step, and the
 * consumer is one admin panel rather than three clients."* So every member of `RetrievalTrace` is
 * optional and every cell below has an absent arm. A field that moves on the data plane makes a cell
 * read "—" here, never a crash inside a table.
 *
 * ── THE FIVE NUMBERS ARE FIVE COLUMNS, AND THE ASYMMETRY IS THE MOST DIAGNOSTIC THING ON SCREEN ─
 * `CandidateRow`'s own docstring calls it that: a candidate found by only ONE branch keeps `null` for
 * the other branch's rank, and *"Never fill it in from anywhere."* So a missing rank renders as
 * absent rather than as rank 0 — which would say the dense arm ranked it first.
 *
 * ── `rerank_score` IS `null` ON THE DEGRADED PATH AND THAT IS A SUPPORTED OUTCOME ───────────────
 * ADR-030 made reranking capability-gated: a bot whose provider cannot `rerank()` still answers, over
 * a fused set selected by BRANCH AGREEMENT instead of by a threshold. `rerank_skip_reason` is what
 * says so, and the panel leads with it rather than leaving a column of em dashes to be interpreted.
 *
 * ── EXCLUSIONS ARE NOT A DEBUGGING NICETY ───────────────────────────────────────────────────────
 * §8.24 requires excluded results AND THEIR REASONS, and `TraceRun.exclude`'s own docstring names the
 * failure this table exists to prevent: the retain cap gets forgotten because `[:retain]` reads as a
 * slice rather than a filter, *"and then the panel shows fewer candidates than were reranked, every
 * one of them passing the threshold, and no row explaining the difference."* So this renders every
 * drop, grouped by nothing and sorted by nothing — the array's order is the pipeline's.
 */

/**
 * The exclusion vocabulary, as sentences.
 *
 * ── A `Map`, NOT AN OBJECT INDEXED BY THE WIRE VALUE ────────────────────────────────────────────
 * The key comes off the wire, and indexing a plain object by a server-supplied value is the
 * `security/detect-object-injection` sink. The fallback is also the point rather than politeness:
 * `ExclusionReason` is a closed `StrEnum` on the data plane and this console is deployed separately
 * from it, so a ninth member reaches a browser running last week's bundle. Rendering the raw word is
 * the honest answer — it is what the pipeline recorded — where a crash or a blank cell is not.
 */
const EXCLUSION_COPY = new Map<string, string>([
  ['exact_duplicate', 'Identical to a passage already selected'],
  ['document_diversity_cap', 'That document had already contributed its share'],
  ['below_rerank_candidate_cutoff', 'Ranked below the rerank candidate depth'],
  ['below_evidence_threshold', 'Scored below this bot’s evidence threshold'],
  // The one that only exists on the degraded path, and the one whose absence would make a degraded
  // run look like a run that simply retrieved less.
  ['no_branch_agreement', 'Found by only one search branch, and reranking was unavailable'],
  ['above_retain_limit', 'Beyond the number of passages this bot retains'],
  ['context_budget_exhausted', 'No room left in the context budget'],
  ['unknown_citation_label', 'The answer cited a label the pipeline never assigned'],
]);

const exclusionCopy = (reason: string | undefined): string =>
  reason === undefined ? 'Dropped for an unrecorded reason' : (EXCLUSION_COPY.get(reason) ?? reason);

/** `0.8123` -> `0.812`; `null`/absent -> an em dash, which is NOT `0.000`. A missing score and a
 *  score of zero are different facts, and on a branch rank they are opposite ones. */
const score = (value: number | null | undefined): string =>
  value === null || value === undefined ? EM_DASH : value.toFixed(3);

const rank = (value: number | null | undefined): string =>
  value === null || value === undefined ? EM_DASH : String(value);

export function RetrievalTracePanel({ data }: { readonly data: RetrievalTraceData }) {
  const trace: RetrievalTrace = data.trace ?? {};
  const candidates = trace.candidates ?? [];
  const exclusions = trace.exclusions ?? [];
  const packed = trace.packed_order ?? [];

  /**
   * WHICH CANDIDATES WERE ACTUALLY SHOWN TO THE MODEL, by chunk id.
   *
   * `packed_order` is `[chunk_id, label]` pairs IN PACKED ORDER — the order the model read the
   * evidence in, which is NOT reranked order and is the order the citation labels were assigned in.
   * So membership answers "was this selected" and the array's position answers "in what order",
   * and neither is derivable from the candidate table's own ordering.
   */
  const selected = new Map(packed.map(([chunkId, label]) => [chunkId, label]));

  return (
    <div className="flex flex-col gap-4">
      <Card>
        <CardHeader>
          <CardTitle as="h3">How this answer was retrieved</CardTitle>
          <CardDescription>
            {/* THE QUERY THE RETRIEVER ACTUALLY RAN, and the rewrite that produced it. `null` means
                the rewriting stage LEFT THE QUERY ALONE — a real outcome, and different from a
                rewrite that happened to produce the same string. Both are untrusted text (one is the
                visitor's, one is model output) and both are JSX children. */}
            {trace.rewritten_query == null
              ? 'The query ran as it was typed.'
              : 'The query was rewritten before it ran.'}
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <dl className="grid gap-x-6 gap-y-2 sm:grid-cols-2">
            <Pair label="Ran as">
              <span className="font-mono text-sm">
                {trace.retrieval_query ?? trace.rewritten_query ?? trace.original_query ?? EM_DASH}
              </span>
            </Pair>
            <Pair label="Branches queried">
              {(trace.branches_queried ?? []).length === 0
                ? EM_DASH
                : (trace.branches_queried ?? []).join(' + ')}
            </Pair>
            <Pair label="Embedding">
              {trace.embedding_model == null
                ? EM_DASH
                : `${trace.embedding_provider ?? ''} ${trace.embedding_model}`.trim()}
            </Pair>
            <Pair label="Retrieval configuration">
              {trace.retrieval_configuration_version ?? EM_DASH}
            </Pair>
          </dl>

          {trace.rerank_skip_reason == null ? null : (
            /*
             * THE DEGRADED PATH, SAID FIRST AND SAID QUIETLY.
             *
             * ADR-030 made reranking capability-gated, so this is a SUPPORTED OUTCOME rather than a
             * failure — the bot answered, over a set selected by branch agreement. It is a
             * `--warning-soft` note in place, never a modal and never red, for the same reason a
             * refusal is not styled as an error: the product is working.
             *
             * Without it, a column of em dashes under "Rerank" reads as a bug.
             */
            <p className="rounded-lg bg-warning-soft px-3 py-2 text-sm text-warning-soft-foreground">
              Reranking did not run ({trace.rerank_skip_reason}), so candidates were selected by
              branch agreement rather than by score.
            </p>
          )}

          {trace.insufficient_evidence === true ? (
            /*
             * A REFUSAL IS A DESIGNED STATE, NOT AN ERROR. The pipeline found too little to answer
             * from and said so, which is the feature. Calm treatment, `--tone-slate`, nothing shared
             * with the failure path.
             */
            <p className="rounded-lg bg-tone-slate-surface px-3 py-2 text-sm text-tone-slate-foreground">
              Nothing cleared the evidence threshold, so the bot declined to answer. Every candidate
              below is listed with the reason it was dropped.
            </p>
          ) : null}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle as="h3">
            Candidates
            <span className="ml-2 text-caption font-normal text-muted-foreground">
              {candidates.length === 1 ? '1 retrieved' : `${candidates.length} retrieved`} ·{' '}
              {selected.size} shown to the model
            </span>
          </CardTitle>
        </CardHeader>
        <CardContent>
          {candidates.length === 0 ? (
            // A DEFINED ZERO: the query matched nothing, which is a real outcome and a different
            // statement from "the trace did not arrive".
            <p className="text-base text-muted-foreground">This query matched nothing.</p>
          ) : (
            <div className="w-full overflow-x-auto">
              <Table>
                <caption className="sr-only">
                  Retrieved candidates, in ranked order, with each search branch&rsquo;s rank and
                  score
                </caption>
                <TableHeader>
                  <TableRow className="bg-card-inset hover:bg-card-inset">
                    <TableHead scope="col" className="px-card-pad-md">
                      Chunk
                    </TableHead>
                    <TableHead scope="col" className="px-card-pad-md text-right">
                      Dense
                    </TableHead>
                    <TableHead scope="col" className="px-card-pad-md text-right">
                      Sparse
                    </TableHead>
                    <TableHead scope="col" className="px-card-pad-md text-right">
                      Fused
                    </TableHead>
                    <TableHead scope="col" className="px-card-pad-md text-right">
                      Rerank
                    </TableHead>
                    <TableHead scope="col" className="px-card-pad-md">
                      Used
                    </TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {candidates.map((candidate, index) => (
                    <CandidateRow
                      key={candidate.chunk_id ?? `candidate-${index}`}
                      candidate={candidate}
                      label={
                        candidate.chunk_id === undefined
                          ? undefined
                          : selected.get(candidate.chunk_id)
                      }
                    />
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle as="h3">
            Excluded
            <span className="ml-2 text-caption font-normal text-muted-foreground">
              {exclusions.length === 1 ? '1 candidate' : `${exclusions.length} candidates`}
            </span>
          </CardTitle>
          <CardDescription>
            Every candidate the pipeline dropped, and the stage that dropped it. §8.24 requires the
            reasons as well as the results.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {exclusions.length === 0 ? (
            // A DEFINED ZERO, and a meaningful one: nothing was dropped, so the candidate table and
            // the packed set are the same size. Saying it is what stops an empty card reading as a
            // panel that failed to load.
            <p className="text-base text-muted-foreground">
              Nothing was dropped — every candidate reached the model.
            </p>
          ) : (
            <ol className="flex flex-col gap-2">
              {exclusions.map((exclusion, index) => (
                <ExclusionRow
                  key={`${exclusion.chunk_id ?? index}:${exclusion.stage ?? ''}`}
                  exclusion={exclusion}
                />
              ))}
            </ol>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

function CandidateRow({
  candidate,
  label,
}: {
  readonly candidate: RetrievalTraceCandidate;
  /** The citation label this candidate was packed under, or `undefined` if it was not selected. */
  readonly label: string | undefined;
}) {
  return (
    <TableRow className="h-13">
      <TableCell className="px-card-pad-md">
        <div className="flex min-w-0 flex-col">
          <span className="truncate font-mono text-caption" title={candidate.chunk_id}>
            {candidate.chunk_id ?? EM_DASH}
          </span>
          {candidate.source_id == null ? null : (
            <span className="truncate font-mono text-caption text-muted-foreground">
              {candidate.source_id}
            </span>
          )}
        </div>
      </TableCell>
      {/* RANK ABOVE SCORE IN EACH BRANCH CELL. A candidate the other branch did not find keeps
          `null` for BOTH, and the em dash is the whole signal — filling either in from anywhere is
          what `CandidateRow`'s docstring forbids, and rank 0 would claim it ranked first. */}
      <TableCell className="px-card-pad-md text-right">
        <BranchCell rankValue={candidate.dense_rank} scoreValue={candidate.dense_score} />
      </TableCell>
      <TableCell className="px-card-pad-md text-right">
        <BranchCell rankValue={candidate.sparse_rank} scoreValue={candidate.sparse_score} />
      </TableCell>
      <TableCell className="px-card-pad-md text-right tabular-nums">
        {score(candidate.fused_score)}
      </TableCell>
      <TableCell className="px-card-pad-md text-right tabular-nums">
        {/* `null` on the degraded path, where stage 11 never ran — the panel's header note says so
            once rather than leaving this column to be interpreted. */}
        {score(candidate.rerank_score)}
      </TableCell>
      <TableCell className="px-card-pad-md">
        {label === undefined ? (
          <span className="text-caption text-muted-foreground">not used</span>
        ) : (
          // COLOUR IS NEVER THE ONLY CHANNEL: the badge carries the label, which is also the marker
          // the answer cites — so the row ties a score to a footnote.
          <Badge variant="success">[{label}]</Badge>
        )}
      </TableCell>
    </TableRow>
  );
}

function BranchCell({
  rankValue,
  scoreValue,
}: {
  readonly rankValue: number | null | undefined;
  readonly scoreValue: number | null | undefined;
}) {
  return (
    <span className="flex flex-col items-end tabular-nums">
      <span>{rank(rankValue)}</span>
      <span className="text-caption text-muted-foreground">{score(scoreValue)}</span>
    </span>
  );
}

function ExclusionRow({ exclusion }: { readonly exclusion: RetrievalTraceExclusion }) {
  return (
    <li className="flex flex-wrap items-center gap-2 text-sm">
      {/* The reason is the point of the row, so it leads. The stage is second because it is what
          lets a reader group "excluded: dedup" apart from "excluded: budget" — `Exclusion`'s own
          docstring says the stage is captured from the open block rather than passed, so it cannot be
          stated wrongly. */}
      <StatusPill status="disabled" label={exclusionCopy(exclusion.reason)} />
      {exclusion.stage === undefined ? null : (
        <span className="text-caption text-muted-foreground">at {exclusion.stage}</span>
      )}
      <span className="truncate font-mono text-caption text-muted-foreground" title={exclusion.chunk_id}>
        {exclusion.chunk_id ?? EM_DASH}
      </span>
      {exclusion.score == null ? null : (
        <span className="text-caption tabular-nums">scored {score(exclusion.score)}</span>
      )}
    </li>
  );
}

function Pair({ label, children }: { readonly label: string; readonly children: React.ReactNode }) {
  return (
    <div className="flex min-w-0 flex-col gap-0.5">
      <dt className="text-caption text-muted-foreground uppercase">{label}</dt>
      <dd className="truncate text-base">{children}</dd>
    </div>
  );
}
