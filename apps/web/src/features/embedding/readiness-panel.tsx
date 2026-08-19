'use client';

import type {
  EmbeddingDesignation,
  EmbeddingReadinessResource,
  EmbeddingRejection,
} from '@kb/contracts';
import { AlertTriangleIcon, BanIcon, CpuIcon } from 'lucide-react';

import { EmptyState, ErrorState, SkeletonLines } from '@/components/states';
import { StatusPill } from '@/components/status-pill';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

import {
  describeCandidate,
  describeDesignation,
  designationState,
  rejectionReasonSentence,
  type DesignationState,
} from './api';

/**
 * The verdict: can this organization embed, is ingestion blocked right now, which pair is selected,
 * and what was refused.
 *
 * ── THE ONE RULE THIS COMPONENT EXISTS TO HONOUR ────────────────────────────────────────────────
 * `explanation` IS RENDERED VERBATIM. Never paraphrased, never summarised, never truncated, and
 * never replaced by a sentence composed from `rejected[]`. The ingestion path raises the SAME
 * string, so an operator who reads it here and then reads it on a failed upload must see the same
 * words — and `EmbeddingConfigurationController` returns it precisely so those two surfaces cannot
 * drift. It is not an error envelope's `message`: it arrives on a 200, it is built from connection
 * ids, provider names, model ids and matrix sources only, and the data plane's own tests assert it
 * carries no credential and no tenant content.
 *
 * THE ONLY FIELD BRANCHED ON FOR THE VERDICT IS `ready`. Not the explanation's text, not its length,
 * not whether it mentions ambiguity, not `rejected.length`. A branch on the string would be a second
 * implementation of the resolution rule, written in a client, out of the one file that owns it
 * (`services/ai-service/app/providers/embedding_selection.py`).
 *
 * ── `blocks_ingestion` IS READ RATHER THAN DERIVED, AND THAT IS DELIBERATE ─────────────────────
 * It is the negation of `ready` under the name the operator sees, and the resource's own docblock
 * says why both are published: "not ready" and "cannot upload anything at all" are the same fact,
 * and only one of the two names is actionable. So the blocking banner is gated on the field that
 * describes the consequence, not on the field that describes the state. If the two ever disagreed,
 * this renders both honestly instead of picking the one that reads better.
 *
 * ── `selected` IS WHAT RESOLVED; `designated` IS WHAT THE ORGANIZATION STORED ─────────────────
 * They are two facts and a null `selected` covers two situations — never designated, or designated
 * and no longer resolving — which used to render identically. `<CurrentPairCard>` below owns that
 * branch and its docblock owns the argument; the verdict above is unaffected, because `ready` is
 * still the only field branched on for it.
 *
 * ── WHY THE BLOCKED TREATMENT IS LOUDER THAN A DEGRADED NOTE ───────────────────────────────────
 * Because there is no degraded mode here. Reranking may be skipped and the answer still arrives;
 * embedding cannot be, because every chunk is embedded before it is indexed. An organization with no
 * embedding-capable connection cannot ingest a SINGLE DOCUMENT — that is the difference between
 * "suboptimal" and "nothing works", and `<DegradedNote>` is the component for the other one. The
 * treatment is therefore the destructive banner plus a `failed` pill in the section heading: a
 * `destructive-soft` surface, a 3px `destructive` left rule, a glyph and the word "blocked", so the
 * state survives greyscale and CVD (kb-ui-accessibility, *Colour independence*).
 *
 * ── ALL FOUR STATES SHIP WITH THE SUCCESS STATE ────────────────────────────────────────────────
 *   loading    a skeleton at the card's shape (never a spinner, never a blank)
 *   empty      FIRST-RUN: nothing was even considered, because no connection carries a model row
 *   error      class-mapped sentence plus the request_id, no retry unless the envelope says so
 *   forbidden  an analyst holds nothing here; §6.4 gives `providers.view` to owner, admin and
 *              knowledge_manager, so a 403 is a real state on this screen and renders through the
 *              same `<ErrorState>` — `authorization` is not retryable by class, so no Try again
 *              affordance appears, which is the correct outcome rather than a missing feature.
 *
 * There is no FILTERED empty state and there must not be one: this surface has no filters, and
 * offering "Clear filters" to an organization with no connections is the bug that split exists to
 * prevent.
 */
export function ReadinessPanel({
  readiness,
  isPending,
  error,
}: {
  readonly readiness: EmbeddingReadinessResource | undefined;
  readonly isPending: boolean;
  readonly error: Error | null;
}) {
  return (
    // SECTIONS ARE SEPARATED BY --space-8 BY THE SCREEN; INSIDE ONE, --space-3 (kb-ui-patterns, the
    // composition law: shell -> page -> section -> card -> content, and nothing skips a level).
    <section aria-labelledby="embedding-readiness-heading" className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 id="embedding-readiness-heading" className="text-h2">
          Readiness
        </h2>
        {/* THE PILL IS THE THIRD CHANNEL — glyph, colour AND word — and it is rendered only once the
            verdict is known, because a pill during loading would state a fact nobody has. */}
        {readiness === undefined ? null : readiness.ready ? (
          <StatusPill status="ready" label="Can embed" />
        ) : (
          <StatusPill status="failed" label="Ingestion blocked" />
        )}
      </div>

      {/* FIRST LOAD IS A SKELETON THAT MIRRORS THE LOADED LAYOUT. Refetch is NOT a skeleton: a
          verdict already on screen and still correct stays on screen while the next read is in
          flight, because blanking it would read as the organization having lost its configuration. */}
      {isPending ? (
        <Card>
          <CardContent className="pt-6">
            <SkeletonLines lines={3} />
          </CardContent>
        </Card>
      ) : null}

      {/* A class-mapped sentence plus the `request_id`, never the envelope's `message` — that field
          is operator-facing and can carry an internal hostname or raw upstream provider text. The
          403 an analyst gets lands here, with no retry, because `authorization` is an ANSWER. */}
      {error === null ? null : (
        <ErrorState title="Embedding readiness could not be loaded" error={error} />
      )}

      {readiness === undefined ? null : (
        <>
          {/* ── THE BLOCKING BANNER ───────────────────────────────────────────────────────────
              `role="alert"` comes from the primitive, so a screen reader is told about it when it
              appears rather than only when somebody navigates to it. */}
          {readiness.blocks_ingestion ? (
            <Alert variant="destructive">
              <AlertTitle>No document can be ingested right now</AlertTitle>
              <AlertDescription className="flex flex-col gap-2">
                {/* THE DATA PLANE'S OWN WORDS. Verbatim, as a JSX child and never as HTML: it is a
                    server string, and this app renders no server string as markup. */}
                <p>{readiness.explanation}</p>
                {/* This sentence is OURS and says nothing about WHY — it states the consequence,
                    which the explanation above does not always spell out, and it is the same on
                    every blocked verdict. It is not a paraphrase and it never substitutes. */}
                <p className="text-sm">
                  Every chunk is embedded before it is indexed and there is no degraded mode for it,
                  unlike reranking. Until this is fixed, uploads and crawls will fail with this same
                  message.
                </p>
              </AlertDescription>
            </Alert>
          ) : null}

          {/* ── THE SELECTED PAIR, AND THE STORED ONE WHEN THEY DIFFER ────────────────────────
              `selected` is what the resolver produced; `designated` is what the organization
              stored. See `<CurrentPairCard>` for why a null `selected` is two states. */}
          <CurrentPairCard readiness={readiness} />

          {/* ── WHAT WAS CONSIDERED ───────────────────────────────────────────────────────────
              FIRST-RUN EMPTY when nothing was even examined: no connection of this organization
              carries a model row, so there was nothing to accept or refuse. That is a different
              state from "everything was refused", which renders the rejection list below with its
              reasons — and telling them apart is the whole reason this branch exists. */}
          {readiness.eligible.length === 0 && readiness.rejected.length === 0 ? (
            <EmptyState
              glyph={CpuIcon}
              title="No candidates were examined"
              body="This organization has no provider connection carrying a model row, so there was nothing to consider. Register a model on a connection first."
            />
          ) : (
            <>
              {readiness.eligible.length === 0 ? null : (
                <Card>
                  <CardHeader>
                    <CardTitle as="h3">Eligible pairs</CardTitle>
                    <CardDescription>
                      Every candidate that passed. More than one is not an error by itself — several
                      connections naming the same provider and model are one vector space and differ
                      only in which credential pays.
                    </CardDescription>
                  </CardHeader>
                  <CardContent>
                    <ul className="flex flex-col gap-2">
                      {readiness.eligible.map((candidate) => (
                        <li
                          key={`${candidate.connection_id}:${candidate.model}`}
                          className="flex flex-wrap items-baseline gap-2"
                        >
                          <span className="font-mono text-base">
                            {describeCandidate(candidate)}
                          </span>
                          <span className="font-mono text-sm text-muted-foreground">
                            {candidate.connection_id}
                          </span>
                        </li>
                      ))}
                    </ul>
                  </CardContent>
                </Card>
              )}

              {readiness.rejected.length === 0 ? null : (
                <Card>
                  <CardHeader>
                    <CardTitle as="h3">Refused candidates</CardTitle>
                    <CardDescription>
                      Listed even when the organization can embed, because an operator asking &ldquo;why
                      is my Anthropic key not being used&rdquo; needs the answer whether or not some other
                      connection saved the day.
                    </CardDescription>
                  </CardHeader>
                  <CardContent>
                    <ul className="flex flex-col gap-4">
                      {readiness.rejected.map((rejection) => (
                        <RejectionItem
                          key={`${rejection.connection_id}:${rejection.model}:${rejection.reason}`}
                          rejection={rejection}
                        />
                      ))}
                    </ul>
                  </CardContent>
                </Card>
              )}
            </>
          )}
        </>
      )}
    </section>
  );
}

/**
 * What is embedding right now, and what this organization asked for — which are two facts and were
 * one rendering until `EmbeddingReadinessResource.designated` existed.
 *
 * ── THE BUG THIS CARD FIXES ─────────────────────────────────────────────────────────────────────
 * `selected: null` used to mean "nothing is resolved" and nothing more, and it covers two situations
 * an operator has to act on differently:
 *
 *   nothing designated   they have never chosen a pair. The next action is to choose one.
 *   designated, refused  they DID choose, and the stored pair stopped resolving — the model row lost
 *                        its embedding flag, the connection was revoked. The next action is to
 *                        designate a DIFFERENT pair, and the stored one is worth naming because it
 *                        is the row they are about to go and look at.
 *
 * Both rendered "Nothing is resolved. See the verdict above." The stored pair was named only inside
 * the free-text `explanation`, so distinguishing them would have meant parsing a paragraph the data
 * plane owns and may reword. `designated` is the field that makes it a branch instead of a regex.
 *
 * ── `designated` IS BRANCHED ON; `explanation` IS STILL THE AUTHORITY ──────────────────────────
 * Nothing here explains WHY a designation stopped resolving. That sentence is the data plane's, it
 * is `explanation`, and it renders verbatim in the blocking banner above — this card picks which
 * true statement of FACT to put beside the pair and composes no verdict. `designationState()` is a
 * two-null branch over two fields and reads neither `ready` nor `rejected[]`.
 *
 * ── WHY THE STORED PAIR IS `warning` AND NOT `destructive` ────────────────────────────────────
 * The page already carries one destructive banner when ingestion is blocked, and it owns that
 * severity. Two red surfaces on one page saying different things is the failure `designation-form`
 * avoids for the same reason. The glyph and the words "no longer resolving" carry the state without
 * the colour, so it survives greyscale and CVD (kb-ui-accessibility, *Colour independence*), and
 * `--warning-soft-foreground` on `--warning-soft` is one of the pairs
 * `tests/unit/design-system.test.ts` holds to 4.5:1 in BOTH modes.
 *
 * It is NOT an `<Alert>` and NOT a `<DegradedNote>`. `<Alert>` carries `role="alert"`, a live region
 * that would announce a fact present since page load, and `<DegradedNote>` means "it worked, but not
 * fully" — there is no degraded mode for embedding, which is the distinction the panel's own
 * docblock turns on.
 */
function CurrentPairCard({ readiness }: { readonly readiness: EmbeddingReadinessResource }) {
  const state = designationState(readiness);

  return (
    <Card>
      <CardHeader>
        <CardTitle as="h3">Current embedding pair</CardTitle>
        <CardDescription>
          The credential that pays for embedding, and the model that names the vector space every
          chunk is indexed under.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        {readiness.selected === null ? (
          // UNCHANGED WORDING on purpose: this line is about the RESOLVER, it is true in both
          // null-`selected` states, and the stored pair below is what tells them apart.
          <p className="text-base text-muted-foreground">
            Nothing is resolved. See the verdict above.
          </p>
        ) : (
          <dl className="flex flex-col gap-2">
            <div className="flex flex-wrap items-baseline gap-2">
              <dt className="text-sm text-muted-foreground">Provider and model</dt>
              {/* MONOSPACED AND VERBATIM: this pair IS the vector space, so it is matched
                  character by character against a vendor dashboard or a data-plane log. */}
              <dd className="font-mono text-base">{describeCandidate(readiness.selected)}</dd>
            </div>
            <div className="flex flex-wrap items-baseline gap-2">
              <dt className="text-sm text-muted-foreground">Connection</dt>
              <dd className="font-mono text-sm">{readiness.selected.connection_id}</dd>
            </div>
          </dl>
        )}

        {/* THE STORED PAIR, SHOWN ONLY WHEN IT IS NOT THE RESOLVED ONE. On a ready verdict the two
            agree on connection and model, so repeating it would be the same pair twice under two
            labels. The `!== null` check is what narrows the type — `state` is a computed label and
            narrows nothing. */}
        {state === 'designated_but_unresolved' && readiness.designated !== null ? (
          <UnresolvedDesignation designation={readiness.designated} />
        ) : null}

        <p className="text-sm text-muted-foreground">{designationSentence(state)}</p>
      </CardContent>
    </Card>
  );
}

/**
 * The pair the organization STORED, when it no longer resolves.
 *
 * `describeCandidate` IS NOT USED HERE AND CANNOT BE. `EmbeddingDesignation` has no `provider` — the
 * organization stores a connection id and a model string, and the vendor is a property of the
 * connection, resolved at read time. Rendering it through the candidate formatter would need a cast
 * and would print `undefined/text-embedding-3-large` on the one screen whose entire job is to name
 * the pair exactly. So the model and the connection id are two labelled fields.
 *
 * BOTH ARE MONOSPACED AND VERBATIM for the same reason the resolved pair is: the operator is about
 * to match these characters against a vendor dashboard, a `provider_models` row and a data-plane log
 * line, and a prettified vendor name is unmatchable.
 */
function UnresolvedDesignation({ designation }: { readonly designation: EmbeddingDesignation }) {
  return (
    <div className="flex items-start gap-2 rounded-lg bg-warning-soft px-3 py-2 text-warning-soft-foreground">
      <AlertTriangleIcon aria-hidden className="mt-1 size-4 shrink-0" />
      <dl className="flex min-w-0 flex-col gap-1">
        <div className="flex flex-wrap items-baseline gap-2">
          {/* THE WORD CARRIES THE STATE, not the colour: readable in greyscale and by a screen
              reader that announces neither the background nor the glyph. */}
          <dt className="text-sm font-medium">Designated model, no longer resolving</dt>
          <dd className="font-mono text-base break-all">{describeDesignation(designation)}</dd>
        </div>
        <div className="flex flex-wrap items-baseline gap-2">
          <dt className="text-sm">Designated connection</dt>
          <dd className="font-mono text-sm break-all">{designation.connection_id}</dd>
        </div>
      </dl>
    </div>
  );
}

/**
 * One sentence of FACT per state — never a reason, never a paraphrase of `explanation`.
 *
 * A `switch` over a CLOSED union of our own, so a fifth state is a typecheck failure rather than
 * `undefined` rendered as nothing. (It is not the wire-value-lookup hazard `rejectionReasonSentence`
 * documents — `DesignationState` is computed here, not read off a response — but the exhaustiveness
 * is worth the same shape.)
 */
function designationSentence(state: DesignationState): string {
  switch (state) {
    case 'designated_and_resolved':
      return 'This pair is designated explicitly, so it is never substituted: if it stops being able to embed, ingestion stops rather than moving to another connection.';
    case 'resolved_by_rule':
      return 'Nothing is designated — this pair was resolved by rule, which holds only while every eligible connection agrees on it. One that named a different pair would leave nothing resolved, with no edit to this organization.';
    case 'designated_but_unresolved':
      return 'The designated pair is not substituted when it stops resolving, because embedding through a different connection would change the vector space under a corpus nobody reindexed. Designate a working pair below.';
    case 'nothing_designated':
      return 'Nothing is designated either, so there is no stored pair to repair. Designate one below once a pair is eligible.';
  }
}

/**
 * One refused candidate: the pair, the reason as a sentence, and the data plane's `detail` verbatim.
 *
 * `detail` RENDERS AS SUPPLIED. It quotes the capability-matrix cell that decided the refusal, and
 * it is the second of the two server strings this screen shows verbatim — end-user-readable by
 * construction, for the same reason `explanation` is. A JSX child, never HTML.
 *
 * AN UNRECOGNISED `reason` IS SHOWN RATHER THAN HIDDEN. `rejectionReasonSentence` returns null for a
 * member this build has never heard of — the vocabulary is the data plane's `EmbeddingIneligibility`
 * and neither Laravel nor `@kb/contracts` narrows it — so the raw value renders as a monospaced
 * badge beside the detail. An operator who can see the token can search for it; one who sees nothing
 * is told a candidate was refused for no stated reason.
 */
function RejectionItem({ rejection }: { readonly rejection: EmbeddingRejection }) {
  const sentence = rejectionReasonSentence(rejection.reason);

  return (
    <li className="flex flex-col gap-1">
      <div className="flex flex-wrap items-center gap-2">
        <BanIcon aria-hidden className="size-4 shrink-0 text-muted-foreground" />
        {/* ONE SPELLING OF "how a pair is written", shared with the eligible list, the selected card
            and the radio group. `EmbeddingRejection` carries every member `EmbeddingCandidate` does,
            so it passes structurally — and a second inline `{provider}/{model}` here is exactly the
            duplicate that drifts by a separator the day somebody changes one of them. */}
        <span className="font-mono text-base break-all">{describeCandidate(rejection)}</span>
        <Badge variant="outline" className="font-mono">
          {rejection.reason}
        </Badge>
      </div>
      {sentence === null ? null : <p className="text-base">{sentence}</p>}
      <p className="text-sm text-muted-foreground">{rejection.detail}</p>
      <p className="font-mono text-caption text-muted-foreground">{rejection.connection_id}</p>
    </li>
  );
}
