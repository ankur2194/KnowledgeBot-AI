'use client';

import { EVIDENCE_THRESHOLD_SCALES } from '@kb/contracts/forms';
import { useState } from 'react';
import { useWatch } from 'react-hook-form';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

import {
  asFieldText,
  boundFor,
  evidenceScaleLabel,
  evidenceScaleNote,
  isBoundedScale,
  isConfigured,
  numericFieldValue,
  type BotSettingsForm,
} from './bot-model-shared';

/**
 * THE EVIDENCE THRESHOLD, AND IT IS NOT A NUMBER INPUT WITH A DEFAULT IN IT.
 *
 * ── THE THING THIS CARD EXISTS TO GET RIGHT ─────────────────────────────────────────────────────
 * There is NO PORTABLE DEFAULT, and the absence is deliberate at every layer below this one. The
 * calibration table in the data plane (`app/rag/rerank.py`, `CALIBRATIONS`) is EMPTY ON PURPOSE, and
 * `calibration_for` RAISES on a pair nobody has measured rather than returning a number. `NewBot`
 * writes the column null. The database refuses a threshold without its scale
 * (`bots_evidence_threshold_paired`), and `EvidenceThresholdScale` has no `uncalibrated` member,
 * because that is not a scale — it is the statement that no characterization exists.
 *
 * So a seeded `0.30` in this control would be the one place in the stack that guessed. `0.30` is a
 * valid float on EVERY scale: on an unbounded logit it passes almost everything, on a bounded
 * relevance score it refuses almost everything, and NEITHER RAISES. Only the refusal rate moves,
 * only in aggregate, and since ADR-030 it moves for ONE TENANT and not the rest. No test in this
 * repository would have failed.
 *
 * ── SO THE REFUSAL IS RENDERED AS GUIDANCE ──────────────────────────────────────────────────────
 * The pattern is `features/embedding/readiness-panel.tsx`'s: a refusal the operator can act on is
 * readable prose naming the next step, never a 422 blob and never a control that stores a number
 * nobody can interpret. With nothing recorded, this card renders WHAT a threshold is, WHY the
 * platform will not invent one, and HOW to derive one — and offers no number field at all until the
 * operator says they have an evaluation run to record. The scale is then chosen FIRST, because the
 * number means nothing without it.
 *
 * It is NOT an `<Alert>`. `<Alert>` carries `role="alert"`, a live region, and this is a fact that
 * has been true since page load rather than something that just happened — the same argument
 * `<CurrentPairCard>` makes in the readiness panel. It is not `<DegradedNote>` either: nothing is
 * degraded, an unrecorded threshold is the ordinary state of every bot.
 *
 * ── THE PAIR IS ENFORCED IN BOTH DIRECTIONS, AND THE SERVER STILL HAS THE LAST WORD ────────────
 * `botSettingsSchema`'s `evidencePair` mirrors `required_with` both ways and `thresholdWithinScale`
 * mirrors `App\Rules\EvidenceThresholdWithinScale` — a bounded scale takes [0, 1], a logit does not.
 * Both are client affordances. The rule NEITHER can express is the PATCH's asymmetry: a body that
 * clears only the scale passes every declarative rule and still violates the CHECK against the
 * STORED threshold, so `BotService::assertRepresentable()` re-checks the RESULTING pair and can
 * answer 422 on a field the operator did not send. That is why "Clear both" is one button writing
 * both halves rather than two controls the operator is trusted to clear in order.
 */
export function EvidenceThresholdCard({
  form,
  pending,
}: {
  readonly form: BotSettingsForm;
  readonly pending: boolean;
}) {
  const threshold = useWatch({ control: form.control, name: 'evidence_threshold' });
  const scale = useWatch({ control: form.control, name: 'evidence_threshold_scale' });

  /**
   * Opening the controls is an explicit act and is NOT form state. It is lost when the tab is
   * deselected, along with the rest of this panel — `TabsContent` renders `present && children` —
   * and that is the correct behaviour: a revealed but untouched pair of controls is not an unsaved
   * edit, and `formState.isDirty` (which is what the shell's guard reads) stays false.
   */
  const [recording, setRecording] = useState(false);

  /**
   * A 422 ON EITHER HALF OPENS THE CONTROLS, AND THIS IS NOT A CONVENIENCE.
   *
   * `botPanelKnownPaths(BOT_MODEL_FIELDS)` routes both keys to `setError` rather than to the banner,
   * because both are in this panel's partition — and `knownPaths` means "the paths this form
   * RENDERS". Collapsing the pair behind the guidance breaks that promise for as long as it is
   * collapsed: the server rejects, `setError` writes to a name that displays nowhere, nothing visibly
   * changes, and the operator clicks Save again. That is the exact failure `HIDDEN_PATHS` exists for,
   * met from the other side, and the fix belongs here rather than in the path set — subtracting these
   * two would send a threshold refusal to the banner, away from the control it is about.
   *
   * The case is rare by construction (a body that sends both halves null leaves a representable pair,
   * so there is little for the server to refuse) and it is exactly the kind of rare that ships broken.
   */
  const pairRefused =
    form.formState.errors.evidence_threshold !== undefined ||
    form.formState.errors.evidence_threshold_scale !== undefined;

  const configured = isConfigured(threshold) || isConfigured(scale);
  const bound = boundFor('evidence_threshold');
  const scaleName = typeof scale === 'string' ? scale : null;

  return (
    <Card>
      <CardHeader>
        <CardTitle as="h3">Evidence threshold</CardTitle>
        <CardDescription>
          The score below which the bot treats its evidence as insufficient and says so instead of
          answering.
        </CardDescription>
      </CardHeader>

      <CardContent className="flex flex-col gap-4">
        {configured || recording || pairRefused ? (
          <>
            {/* THE SCALE COMES FIRST, and the order is the argument. A number entered before its
                scale is a number in no units at all, and the control that reads left-to-right as
                "0.30 … on which scale?" is the one that gets half-filled. */}
            <FormField
              control={form.control}
              name="evidence_threshold_scale"
              render={({ field }) => (
                <FormItem>
                  <FormLabel htmlFor="evidence-threshold-scale">Scale</FormLabel>
                  {/* A Radix Select has no `register` ref, so `applyServerErrors`' `hasFocusableRef`
                      returns false for this path and never spends `shouldFocus` on it — the message
                      still renders through `<FormMessage>` below. */}
                  <Select
                    value={scaleName ?? undefined}
                    onValueChange={field.onChange}
                    disabled={pending}
                  >
                    <FormControl>
                      <SelectTrigger
                        id="evidence-threshold-scale"
                        aria-label="Scale"
                        className="w-full"
                      >
                        <SelectValue placeholder="Choose the scale that run measured on" />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      {EVIDENCE_THRESHOLD_SCALES.map((member) => (
                        <SelectItem key={member} value={member}>
                          {evidenceScaleLabel(member)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <FormDescription>
                    {scaleName === null
                      ? 'The scale is a property of the (provider, model) pair, not of this bot. Only the bounds transfer between two bounded scales, and the bounds are not the calibration.'
                      : evidenceScaleNote(scaleName)}
                  </FormDescription>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="evidence_threshold"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Threshold</FormLabel>
                  <FormControl>
                    <Input
                      {...field}
                      type="number"
                      inputMode="decimal"
                      step="any"
                      // The server's own absurdity bounds, read from `UpdateBotRequest`'s dumped
                      // rules rather than typed here. The SCALE-DEPENDENT bound — [0, 1] on a
                      // bounded scale — is not expressible as an attribute and is stated below and
                      // enforced by the resolver.
                      min={bound?.min}
                      max={bound?.max}
                      value={asFieldText(field.value)}
                      onChange={(event) => {
                        field.onChange(numericFieldValue(event.target.value, null));
                      }}
                      disabled={pending}
                      className="font-mono"
                    />
                  </FormControl>
                  <FormDescription>
                    {scaleName !== null && isBoundedScale(scaleName)
                      ? `On the ${scaleName} scale a threshold lies between 0 and 1. A value outside a bounded scale’s own range would refuse every answer without raising anything anywhere.`
                      : 'A logit is unbounded and signed, so there is no range to check here — only the evaluation run behind the number says whether it is right.'}
                  </FormDescription>
                  <FormMessage />
                </FormItem>
              )}
            />

            <div className="flex flex-col gap-2">
              <p className="text-sm text-muted-foreground">
                A threshold is a function of the provider, the model, the scale, the candidate depth
                and the chunker version. It is not portable across any of them: re-derive it when one
                changes rather than carrying the number across.
              </p>
              {/* ONE BUTTON WRITING BOTH HALVES. Clearing them one at a time passes every
                  declarative rule and lands on the CHECK against the stored value — a 422 on a
                  field the operator did not send. */}
              <div>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={pending}
                  onClick={() => {
                    form.setValue('evidence_threshold', null, { shouldDirty: true });
                    form.setValue('evidence_threshold_scale', null, { shouldDirty: true });
                    form.clearErrors(['evidence_threshold', 'evidence_threshold_scale']);
                    setRecording(false);
                  }}
                >
                  Clear both
                </Button>
              </div>
            </div>
          </>
        ) : (
          <UncalibratedGuidance pending={pending} onRecord={() => setRecording(true)} />
        )}
      </CardContent>
    </Card>
  );
}

/**
 * THE REFUSAL, AS GUIDANCE. No control, no seeded number, and a next step that is a real one.
 *
 * The derivation is `bge-reranker`'s, quoted rather than paraphrased into something looser: at least
 * 50 answerable and 50 unanswerable questions, top-1 rerank score per question, `min_score` at the
 * 5th percentile of the answerable distribution — which is an accepted ~5% false-refusal rate, the
 * failure users actually complain about.
 *
 * The last paragraph is the scope, and it is here rather than in the rerank card because this is
 * where somebody reads it: the threshold sits on a RERANK score and on nothing else. On a turn where
 * reranking was skipped there is no score, therefore no threshold, and substituting the fused score
 * is the exact bug stage 12 exists to prevent — RRF discards magnitude, so its top candidate scores
 * the same whether it is a perfect match or noise.
 */
function UncalibratedGuidance({
  pending,
  onRecord,
}: {
  readonly pending: boolean;
  readonly onRecord: () => void;
}) {
  return (
    <div className="flex flex-col gap-3">
      {/* An inset block rather than a coloured banner: nothing is wrong, and this is the state every
          bot starts in. Colour here would make the ordinary read as the exceptional. */}
      <div className="flex flex-col gap-2 rounded-lg bg-card-inset p-4">
        <p className="text-base font-medium">No threshold is recorded, and there is no default.</p>
        <p className="text-base text-muted-foreground">
          A threshold is a score on the reranker&rsquo;s own scale, and the scale is a property of
          the provider and model rather than of this bot: an unbounded signed logit on one vendor, a
          bounded relevance score on another. <span className="font-mono">0.30</span> is a valid
          float on every one of them, so applying one scale&rsquo;s number to another moves only the
          refusal rate, only in aggregate, and raises nothing anywhere.
        </p>
        <p className="text-base text-muted-foreground">
          That is why nothing filled this in for you. The platform refuses to construct a calibration
          for a pair nobody has measured rather than seeding a number that would look like a setting.
        </p>
      </div>

      <div className="flex flex-col gap-2">
        <p className="text-base">To record one, derive it first:</p>
        {/* An ordered list, because the steps are a procedure and the order is load-bearing. */}
        <ol className="flex list-decimal flex-col gap-1 pl-5 text-base text-muted-foreground">
          <li>
            Take at least 50 answerable and 50 unanswerable questions from your evaluation set.
          </li>
          <li>Score both sets through the whole pipeline and keep the top-1 rerank score each.</li>
          <li>
            Take the 5th percentile of the answerable distribution — about 5% false refusals, which
            is the failure people actually report.
          </li>
          <li>
            Check the false-answer rate: above roughly 10% the two distributions overlap and the
            problem is retrieval or chunking rather than this number.
          </li>
        </ol>
      </div>

      <p className="text-sm text-muted-foreground">
        The threshold applies to a rerank score and to nothing else. On a turn where reranking was
        skipped there is no score and therefore no threshold — the fused score is never substituted,
        because RRF discards magnitude and its top candidate scores the same whether it is a perfect
        match or noise.
      </p>

      <div>
        <Button type="button" variant="outline" disabled={pending} onClick={onRecord}>
          Record an evaluated threshold
        </Button>
      </div>
    </div>
  );
}
