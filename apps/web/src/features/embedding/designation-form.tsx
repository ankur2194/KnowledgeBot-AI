'use client';

import type { EmbeddingReadinessResource } from '@kb/contracts';
import {
  embeddingDesignationDefaults,
  embeddingDesignationSchema,
  type EmbeddingDesignationIn,
  type EmbeddingDesignationOut,
} from '@kb/contracts/forms';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useRef, useState } from 'react';
import { useForm } from 'react-hook-form';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Form, FormField, FormItem, FormMessage } from '@/components/ui/form';
import { Label } from '@/components/ui/label';
import { applyAuthError } from '@/features/auth/auth-error';
import { actionableConflictMessage } from '@/lib/api/actionable-conflict';

import {
  EMBEDDING_DESIGNATION_KNOWN_PATHS,
  NO_DESIGNATION,
  candidateKey,
  describeCandidate,
  designateEmbeddingConnection,
  resolverRefusalMessage,
} from './api';

/**
 * Choose which `(connection, model)` pair embeds — or clear the choice and return the organization
 * to resolve-by-rule.
 *
 * ── THE PAIR IS ONE CONTROL, SO HALF A DESIGNATION IS UNREPRESENTABLE ──────────────────────────
 * `connection_id` and `model` are `required_with` each other in the FormRequest, the schema repeats
 * it in a `superRefine`, and the DATABASE repeats it a third time as `CHECK num_nonnulls(...) <> 1`.
 * Three layers agree that half a designation names either no credential or no vector space — so this
 * form does not offer a UI that can express one. There is ONE radio group whose every option writes
 * BOTH fields, and the clear option writes both nulls. Two selects, one per field, would have been
 * the obvious shape and would have made the refused state reachable in two clicks.
 *
 * ── THE FORM IS SEEDED FROM `selected` AND FROM NOTHING ELSE ───────────────────────────────────
 * `embeddingDesignationDefaults(readiness)` picks exactly `selected.connection_id` and
 * `selected.model`; its docblock says why, and the parameter type has ONE member, so a
 * `reset(response)` or a `defaultValues={{...readiness}}` does not compile. That is not fussiness:
 * `reset()` KEEPS EVERY KEY IT IS HANDED and submit posts them back, so feeding the readiness object
 * into form state would put `eligible`, `rejected`, `explanation` and `blocks_ingestion` into a PUT
 * body against a `strictObject` — a 422 on four keys nobody rendered, at best.
 *
 * The same factory runs again on SUCCESS, against the readiness the server returned, so the control
 * shows what was stored rather than what was clicked.
 *
 * ── THE RADIO GROUP'S VALUE IS `useState`, NOT A NINTH FORM FIELD ──────────────────────────────
 * Same arrangement as the task selector in `features/models/model-form.tsx`, for a related reason:
 * the composite key is a VIEW of the two real fields, not a value the server has ever heard of, and
 * `test/form-drift.test.ts` asserts SET EQUALITY between a schema's paths and its manifest's keys —
 * so a third path would turn the contracts suite red, correctly, because `DesignateEmbeddingConnection
 * Request` declares no such rule.
 *
 * ── THE CONTRACT GAP THIS USED TO CARRY IS CLOSED, AND THE SEEDING STILL DOES NOT CHANGE ──────
 * `selected` is null BOTH when nothing is designated and when a designation IS stored but no longer
 * resolves (the row lost its embedding flag, say). That was reported here as a contract gap, because
 * the stored pair reached the client only inside the free-text `explanation`;
 * `EmbeddingReadinessResource.designated` now carries it, and the readiness panel above renders it
 * by name — see `<CurrentPairCard>` in readiness-panel.tsx.
 *
 * THE GROUP STILL STARTS WITH NOTHING CHECKED in that case, and closing the gap did not change it:
 * a designation that no longer resolves is by definition NOT in `eligible`, so there is no radio to
 * check — every option this group renders comes from that list. Checking nothing remains the honest
 * rendering ("no pair is resolved") rather than a claim that the organization chose automatic
 * resolution, and the stored pair is named in the panel, where naming it does not also mean
 * proposing to re-submit it.
 *
 * ── NOTHING IS OPTIMISTIC AND NOTHING WRITES THE CACHE ─────────────────────────────────────────
 * `invalidateQueries` on `onSettled`, never `setQueryData`. The verdict the panel must show is the
 * one the SERVER derived — it re-runs the whole resolution rule after the write and can hand back a
 * `rejected[]` the client could not have computed — and a 422 means the panel in front of the
 * operator is also stale, which is often the whole reason the server refused.
 *
 * NO `retry` PROPERTY anywhere: the identifier is an ESLint error outside src/lib/query/client.ts,
 * and the global predicate there is already the right policy.
 */
export function DesignationForm({
  orgId,
  readinessKey,
  readiness,
}: {
  /** The organization the PUT addresses. A ROUTING HINT in the path — `TenantContext` re-reads the
   *  membership row per request and would ignore a client-supplied organization. */
  readonly orgId: string;
  /** The readiness query's org-namespaced key, built by `useOrgKey()` in the screen and passed down
   *  as an ARRAY. Passing the array rather than the builder matters after an organization switch: a
   *  mutation's callbacks fire whether or not its component is still mounted, so an `onSettled` that
   *  called `orgKeyFor(...)` at that moment would throw inside a callback nobody is watching. A
   *  captured array cannot. */
  readonly readinessKey: readonly unknown[];
  readonly readiness: EmbeddingReadinessResource;
}) {
  const queryClient = useQueryClient();

  /** The composite key of the pair the SERVER currently resolves to, or `''` when none does. */
  const seededKey = readiness.selected === null ? '' : candidateKey(readiness.selected);
  const [choice, setChoice] = useState<string>(seededKey);

  /**
   * The refusal paragraph, held OUTSIDE form state.
   *
   * It is not a field error and it is not `root.serverError`: it is guidance about the choice as a
   * whole, it is a paragraph rather than a sentence, and it carries its own next action. Writing it
   * to `root.serverError` would render it as a bare destructive line with no title and no affordance
   * — a raw 422 blob with better typography, which is exactly what this screen must not be.
   */
  const [refusal, setRefusal] = useState<string | null>(null);

  /** The first eligible radio, so the guidance panel's action can put the keyboard on it. */
  const firstEligibleRef = useRef<HTMLInputElement | null>(null);

  const form = useForm<EmbeddingDesignationIn, unknown, EmbeddingDesignationOut>({
    resolver: zodResolver(embeddingDesignationSchema),
    // THE ONLY PATH FROM SERVER DATA INTO THIS FORM'S STATE, and it reaches exactly two fields.
    defaultValues: embeddingDesignationDefaults(readiness),
    mode: 'onTouched',
  });

  const designate = useMutation({
    mutationFn: (values: EmbeddingDesignationOut) => designateEmbeddingConnection(orgId, values),
    onSuccess: (result) => {
      setRefusal(null);
      // RESEED FROM THE ANSWER, through the same two-field factory. Not `reset(result)` — see the
      // docblock; the factory's parameter type is what makes that a typecheck failure rather than a
      // review question.
      form.reset(embeddingDesignationDefaults(result));
      setChoice(result.selected === null ? '' : candidateKey(result.selected));
    },
    onError: (error) => {
      // ── THE ADR-031 CASE, AND THE REASON IT IS CHECKED FIRST ────────────────────────────────
      // The resolver's refusal and the FormRequest's half-designation rule are BOTH
      // `error_class: 'validation'` and differ only in whether `errors` is present
      // (`EmbeddingConfigurationController::update` says so in as many words). `applyAuthError`
      // returns early on `validation` WITH a map and writes the class-mapped sentence otherwise —
      // "Some details need fixing before this can be saved." — which is false here and points at no
      // field. `resolverRefusalMessage` is the structural discriminator; see its docblock for why it
      // is sound rather than a guess about wording.
      const paragraph = resolverRefusalMessage(error);
      if (paragraph !== null) {
        setRefusal(paragraph);
        return;
      }

      setRefusal(null);
      applyAuthError(form, EMBEDDING_DESIGNATION_KNOWN_PATHS, error, {
        // ── THE 409, THROUGH A3'S SENTINEL RATHER THAN A THIRD COPY OF IT ────────────────────
        // A suspended organization may not move its vector space, and `abort_unless(..., 409)`
        // renders as `internal_dependency` / `retryable: false` — indistinguishable from a 500 on
        // `(error_class, retryable)` alone, because `KbError` carries no status. A3 solved that with
        // `actionableConflictMessage`, which returns the server's own sentence only when it is not the
        // fixed >=500 constant; it is imported here, not reimplemented, and it is deliberately NOT
        // renamed because `features/models/model-list.tsx` imports it and that file is finished.
        //
        // TODAY IT RETURNS NULL FOR THIS ENDPOINT and the class-mapped sentence renders instead:
        // the suspended-organization 409 is raised with NO message at all, so there is nothing to
        // show, and inventing "this organization is suspended" would be a cause the server never
        // stated. Reported as a contract gap; the day that abort gains a sentence, this line picks
        // it up with no change here.
        copyFor: (kbError) => actionableConflictMessage(kbError) ?? undefined,
      });
    },
    onSettled: () => {
      // `onSettled` rather than `onSuccess`: a refusal means the verdict on screen is ALSO out of
      // date — the connection set moved under the operator, which is frequently why it was refused.
      void queryClient.invalidateQueries({ queryKey: readinessKey });
    },
  });

  const rootError = form.formState.errors.root?.serverError?.message;
  const pending = designate.isPending;
  /** A PUT that changes nothing is a legal no-op that still costs a write and an audit row. */
  const changed = choice !== seededKey;

  /** Write BOTH halves of the pair, always, from one control. */
  const chooseCandidate = (connectionId: string | null, model: string | null, key: string): void => {
    setChoice(key);
    setRefusal(null);
    form.setValue('connection_id', connectionId, { shouldDirty: true, shouldValidate: true });
    form.setValue('model', model, { shouldDirty: true, shouldValidate: true });
  };

  return (
    <Form {...form}>
      <form
        // POST, never the browser's default GET. Asserted for every form by
        // tests/unit/form-method.test.ts.
        method="post"
        onSubmit={form.handleSubmit((values) => {
          designate.mutate(values);
        })}
        // The browser's own validation bubbles would pre-empt the server's messages and cannot be
        // styled or read consistently by a screen reader.
        noValidate
        className="flex flex-col gap-4"
      >
        {/* ── THE REFUSAL, AS GUIDANCE WITH A NEXT ACTION ────────────────────────────────────────
            The paragraph is the SERVER's, verbatim — the same words `explanation` carries and the
            same words the upload path raises. Everything around it is this screen's: a title so the
            reader knows what was refused, and one action so the paragraph's own closing instruction
            ("Designate one connection and model explicitly.") has something to click.

            WARNING RATHER THAN DESTRUCTIVE, because nothing broke: a choice was refused and the
            organization is exactly as it was. Whether it can ingest at all is the readiness panel's
            verdict, above, and duplicating that severity here would give one page two red banners
            saying different things. */}
        {refusal === null ? null : (
          <Alert variant="warning">
            <AlertTitle>That designation was refused</AlertTitle>
            <AlertDescription className="flex flex-col gap-2">
              <p>{refusal}</p>
              {/* The action is offered only when there IS an eligible pair to designate. With none,
                  the paragraph above is the `nothing can embed` explanation, whose own next step is
                  to add a connection — and offering "choose a pair" against an empty list would be
                  an affordance pointing at nothing. */}
              {readiness.eligible.length === 0 ? null : (
                <>
                  <p className="text-sm">
                    Next: designate one of the eligible pairs below explicitly. Which one embeds is
                    not a preference, so nothing will choose for you.
                  </p>
                  <div>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={() => firstEligibleRef.current?.focus()}
                    >
                      Choose a pair
                    </Button>
                  </div>
                </>
              )}
            </AlertDescription>
          </Alert>
        )}

        {/* Everything `applyAuthError` routes to the banner: a 403, a 429, an unknown envelope, and
            any 422 key this form does not render. */}
        {rootError === undefined ? null : (
          <Alert variant="destructive">
            <AlertDescription>{rootError}</AlertDescription>
          </Alert>
        )}

        {designate.isSuccess && refusal === null ? (
          // INLINE and `aria-live="polite"` rather than a toast: a message that disappears is the
          // wrong surface for one an administrator may want to re-read, and the polite region is
          // what announces the write — the panel above is not a live region and its changed verdict
          // is otherwise silent.
          <Alert variant="success" aria-live="polite">
            <AlertDescription>
              {designate.data.selected === null
                ? 'The designation is cleared. This organization resolves its embedding connection by rule again.'
                : `Designated ${describeCandidate(designate.data.selected)}.`}
            </AlertDescription>
          </Alert>
        ) : null}

        {/* ── THE GROUP ─────────────────────────────────────────────────────────────────────────
            A native `<fieldset>`/`<legend>` with native radios rather than a `<Select>`: the group
            semantics and the arrow-key behaviour come from the platform with no code, a legend is
            announced with every option, and — unlike a select — every candidate's full
            `provider/model` is visible at once, which is the comparison the ADR-031 case exists to
            make possible. Same reasoning as the task chooser in `features/models/model-form.tsx`. */}
        <FormField
          control={form.control}
          name="connection_id"
          render={() => (
            <FormItem>
              <fieldset className="flex flex-col gap-3" disabled={pending}>
                <legend className="text-base font-medium">Which connection embeds</legend>
                <p className="text-sm text-muted-foreground">
                  The provider and model together are the vector space: everything already indexed
                  was embedded under one pair, and changing it does not re-index anything.
                </p>

                {readiness.eligible.map((candidate, index) => {
                  const key = candidateKey(candidate);
                  const id = `embedding-candidate-${key}`;

                  return (
                    <div key={key} className="flex items-start gap-2">
                      <input
                        type="radio"
                        id={id}
                        name="embedding-designation"
                        className="mt-1 size-4 accent-primary"
                        ref={index === 0 ? firstEligibleRef : undefined}
                        checked={choice === key}
                        onChange={() =>
                          chooseCandidate(candidate.connection_id, candidate.model, key)
                        }
                      />
                      <div className="flex min-w-0 flex-col">
                        {/* A real `<label htmlFor>`: it is the second half of the pointer target and
                            what lets a spec — and a person — address the control by the words beside
                            it. The pair is monospaced and verbatim, because it is matched character
                            by character against a vendor dashboard. */}
                        <Label htmlFor={id} className="font-mono font-normal break-all">
                          {describeCandidate(candidate)}
                        </Label>
                        <span className="font-mono text-caption break-all text-muted-foreground">
                          {candidate.connection_id}
                        </span>
                      </div>
                    </div>
                  );
                })}

                {/* CLEARING IS A LEGAL STATE AND ITS OWN OPTION, never a "none" hidden at the top of
                    a select. It posts `{connection_id: null, model: null}` to the SAME endpoint —
                    there is no DELETE — and returns the organization to resolve-by-rule, which may
                    itself be a blocked state. The server always allows it: refusing would leave a
                    dangling pointer at a connection the operator is trying to remove, which the
                    ON DELETE RESTRICT would then also block. */}
                <div className="flex items-start gap-2">
                  <input
                    type="radio"
                    id="embedding-candidate-none"
                    name="embedding-designation"
                    className="mt-1 size-4 accent-primary"
                    checked={choice === NO_DESIGNATION}
                    onChange={() => chooseCandidate(null, null, NO_DESIGNATION)}
                  />
                  <div className="flex flex-col">
                    <Label htmlFor="embedding-candidate-none" className="font-normal">
                      No designation
                    </Label>
                    <span className="text-sm text-muted-foreground">
                      Resolve by rule instead. With one eligible pair that is the same outcome; with
                      two that disagree, nothing resolves and ingestion stops.
                    </span>
                  </div>
                </div>
              </fieldset>
              {/* The 422 slot for `connection_id`. A native radio has a focusable ref only through
                  `register`, which this group deliberately does not use, so `applyServerErrors`
                  never spends `shouldFocus` on it — the message still renders here. */}
              <FormMessage />
            </FormItem>
          )}
        />

        {/* THE OTHER HALF OF THE PAIR HAS NO CONTROL, BUT IT DOES HAVE A MESSAGE SLOT. `model` is
            written by the group above and by nothing else, so there is nothing to render — but the
            server validates it as a named field, and a 422 keyed on it with nowhere to land would
            display NOWHERE: the operator saves, the server rejects, nothing changes on screen, and
            they save again. That is the failure `HIDDEN_PATHS` exists for on the auth forms; here
            the slot is cheaper than the subtraction and puts the message beside the control that
            set both halves. */}
        <FormField
          control={form.control}
          name="model"
          render={() => (
            <FormItem>
              <FormMessage />
            </FormItem>
          )}
        />

        <div className="flex flex-wrap items-center gap-3">
          <Button type="submit" disabled={pending || !changed}>
            {pending ? 'Saving…' : 'Save designation'}
          </Button>
          {changed ? null : (
            <span className="text-sm text-muted-foreground">
              This is what is stored. Choose a different option to save.
            </span>
          )}
        </div>
      </form>
    </Form>
  );
}
