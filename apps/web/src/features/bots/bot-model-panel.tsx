'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import type { BotResource } from '@kb/contracts';
import {
  BOT_ANSWER_MODES,
  botSettingsSchema,
  type BotSettingsIn,
  type BotSettingsOut,
} from '@kb/contracts/forms';
import { useForm } from 'react-hook-form';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Form,
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
import { Switch } from '@/components/ui/switch';

import { BOT_MODEL_FIELDS, botPanelDefaults } from './api';
import { useBotEditor, useBotSave, useUnsavedBotEdits } from './bot-editor-context';
import { EvidenceThresholdCard } from './bot-model-evidence';
import { ModelSelectionCard } from './bot-model-selection';
import {
  answerModeDescription,
  answerModeLabel,
  asFieldText,
  boundFor,
  DEPTHS_MOVE_BY_EVALUATION,
  evidenceScaleLabel,
  isConfigured,
  numericFieldValue,
  RERANK_IS_CAPABILITY_GATED,
  RETRIEVAL_DEPTHS,
  type BotSettingsForm,
} from './bot-model-shared';

/**
 * TAB 2 OF 3 — "Model & retrieval".
 *
 * The shell (`bot-editor-screen.tsx`) carries the whole contract in prose and is the file to read
 * first; `bot-editor-context.ts` carries the code half. Neither is edited from here, and neither is
 * `./api.ts`. The three private modules beside this one — `bot-model-shared.ts`,
 * `bot-model-selection.tsx`, `bot-model-evidence.tsx` — are this tab's and nobody else's.
 *
 * ── WHAT LANDS HERE ─────────────────────────────────────────────────────────────────────────────
 * `BOT_MODEL_FIELDS`, and nothing outside it:
 *
 *     provider_connection_id  provider_model_id  answer_mode  allow_general_answers
 *     dense_top_k  sparse_top_k  rerank_candidates  rerank_retain
 *     evidence_threshold  evidence_threshold_scale
 *
 * The form is seeded through `botPanelDefaults(bot, BOT_MODEL_FIELDS)` and never `reset(resource)`:
 * `botSettingsSchema` is a `strictObject` whose every field is optional, mirroring `sometimes` on the
 * PATCH, so a form seeded with one tuple parses to exactly that tuple and the request body carries
 * exactly those keys. A panel seeded wholesale would rewrite the other two tabs' fields on every
 * save.
 *
 * ── EVERY CROSS-FIELD RULE IN THE SCHEMA IS IN THIS TUPLE ──────────────────────────────────────
 * All three of `botSettingsSchema`'s `superRefine`s judge a field against a sibling, and all three
 * siblings are here — which is why the partition was drawn this way and why moving a field out of
 * this tuple would produce a form that can never satisfy its own resolver. Two of them get a control
 * that makes the rule visible rather than leaving it to a 422: the evidence pair is one card with
 * one "Clear both" button (`bot-model-evidence.tsx`), and the model/connection pair clears the model
 * when the connection changes (`bot-model-selection.tsx`).
 *
 * The PATCH's own asymmetry stays the server's: a body that clears only the scale passes every
 * declarative rule and still violates the CHECK against the STORED threshold, so `BotService`
 * re-checks the resulting pair and can answer 422 on a field the operator did not send.
 * `botPanelKnownPaths(BOT_MODEL_FIELDS)` puts both halves of the pair under their controls, and
 * anything outside the tuple lands in the banner at the top of the form.
 *
 * ── WHAT THIS TAB DOES NOT RENDER, AND WHY ─────────────────────────────────────────────────────
 * The FALLBACK MODEL CHAIN. `bot_fallback_models` is a real table with composite foreign keys, and
 * it is on no resource and behind no endpoint: `BotResource` carries no field for it and there is no
 * read path, let alone a write one. So there is nothing to render read-only and nothing that could
 * be edited without inventing a mutation. The Model card says that in one sentence, as a fact about
 * the console rather than about the bot — "no chain is configured" would be a claim this screen
 * cannot make.
 */
export function BotModelPanel() {
  const { bot, canManage } = useBotEditor();

  // RULE (a) FROM THE SHELL: no `bots.manage` means NO FORM AT ALL, not a disabled one. A disabled
  // input holding a value is a control an operator will keep clicking, and the shell has already
  // said once, above the tabs, why this viewer cannot change anything.
  if (!canManage) return <ModelReadOnly bot={bot} />;

  return <ModelForm />;
}

/**
 * The editable tab. A separate component so the read-only branch above mounts NO hooks — no
 * `useForm`, no `useUnsavedBotEdits`, and (through `<ModelSelectionCard>`) no provider-connection
 * query whose only possible answer for an analyst is 403.
 */
function ModelForm() {
  const { bot } = useBotEditor();

  const form = useForm<BotSettingsIn, unknown, BotSettingsOut>({
    resolver: zodResolver(botSettingsSchema),
    defaultValues: botPanelDefaults(bot, BOT_MODEL_FIELDS),
    mode: 'onTouched',
  });
  const save = useBotSave(form, BOT_MODEL_FIELDS);

  // The shell owns the guard; this is the whole of a panel's part in it. `TabsContent` renders
  // `present && children`, so this component is gone by the time anyone could ask it whether it had
  // unsaved work — which is why the report is pushed rather than pulled.
  useUnsavedBotEdits('model', form.formState.isDirty);

  const rootError = form.formState.errors.root?.serverError?.message;

  return (
    <Form {...form}>
      <form
        // POST, never the browser's default GET: this form sits behind a session, so the native
        // fallback would put its values in an admin's history and in a referer. Asserted for every
        // form by tests/unit/form-method.test.ts.
        method="post"
        // The browser's own validation bubbles would pre-empt the server's messages and cannot be
        // styled or read consistently by a screen reader.
        noValidate
        onSubmit={form.handleSubmit((values) => {
          save.mutate(values);
        })}
        className="flex flex-col gap-6"
      >
        {/* ONE BANNER, AT THE TOP, for every 422 key outside this panel's partition and for every
            class-mapped failure. The envelope's own `message` reaches no rendered string. */}
        {rootError === undefined ? null : (
          <Alert variant="destructive">
            <AlertDescription>{rootError}</AlertDescription>
          </Alert>
        )}

        <ModelSelectionCard form={form} pending={save.isPending} />
        <AnswerModeCard form={form} pending={save.isPending} />
        <RetrievalDepthsCard form={form} pending={save.isPending} />
        <EvidenceThresholdCard form={form} pending={save.isPending} />

        <div>
          {/* Disabled on `isPending`: the PATCH carries no `Idempotency-Key`, so nothing may replay
              it — including a double-click. */}
          <Button type="submit" disabled={save.isPending}>
            {save.isPending ? 'Saving…' : 'Save changes'}
          </Button>
        </div>
      </form>
    </Form>
  );
}

/**
 * HOW THE BOT ANSWERS: the mode, and the one switch that has to be opened on purpose.
 *
 * `allow_general_answers` is separate from `answer_mode` so that "RAG-first but not yet cleared to
 * publish" stays expressible, and it is the publish guard's only escape hatch — a bot cannot be
 * published in `rag_first` with it closed, and the server answers 409 saying so. The 409 belongs to
 * the transition on the Publishing tab; what belongs here is knowing which of the two fields to
 * change when it arrives.
 */
function AnswerModeCard({
  form,
  pending,
}: {
  readonly form: BotSettingsForm;
  readonly pending: boolean;
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle as="h3">Answer mode</CardTitle>
        <CardDescription>
          Whether this bot may answer from anything other than its own sources.
        </CardDescription>
      </CardHeader>

      <CardContent className="flex flex-col gap-4">
        <FormField
          control={form.control}
          name="answer_mode"
          render={({ field }) => (
            <FormItem>
              <FormLabel htmlFor="bot-answer-mode">Mode</FormLabel>
              <Select
                value={typeof field.value === 'string' ? field.value : undefined}
                onValueChange={field.onChange}
                disabled={pending}
              >
                <FormControl>
                  <SelectTrigger id="bot-answer-mode" aria-label="Mode" className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                </FormControl>
                <SelectContent>
                  {BOT_ANSWER_MODES.map((mode) => (
                    <SelectItem key={mode} value={mode}>
                      {answerModeLabel(mode)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <FormDescription>
                {answerModeDescription(typeof field.value === 'string' ? field.value : 'strict')}
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="allow_general_answers"
          render={({ field }) => (
            <FormItem className="flex flex-row items-center gap-3">
              <FormControl>
                {/* Radix: `role="switch"` with `aria-checked`, operable with Space, taking its id
                    and its `aria-describedby` from `<FormControl>` — which is why neither is set by
                    hand and why `<FormLabel>` needs no `htmlFor`. */}
                <Switch
                  checked={field.value === true}
                  onCheckedChange={field.onChange}
                  disabled={pending}
                />
              </FormControl>
              <div className="flex flex-col">
                <FormLabel>May answer from general knowledge</FormLabel>
                <FormDescription>
                  The one field an operator sets on purpose before a bot may leave its sources.
                  Publishing a RAG-first bot with this closed is refused — the transition on the
                  Publishing tab answers 409 and says so.
                </FormDescription>
              </div>
              <FormMessage />
            </FormItem>
          )}
        />
      </CardContent>
    </Card>
  );
}

/**
 * THE FOUR DEPTHS. Bounds from the server's dumped rules, shipped defaults from
 * `kb-rag-query-contract` §12.7–12.12, and that skill's standing instruction said once on the card.
 *
 * The two rerank controls carry the capability sentence beside them rather than in a tooltip: a
 * provider that cannot rerank produces a TRACED SKIP and not an error, so these two numbers can be
 * saved, stored and never used, with nothing on this screen able to say so in advance.
 *
 * `rerank_retain <= rerank_candidates` needs no rule here and gets none: the server's own bands are
 * 6–10 and 20–30, so every value one accepts is below every value the other accepts. A client-side
 * comparison would be a rule the server does not have.
 */
function RetrievalDepthsCard({
  form,
  pending,
}: {
  readonly form: BotSettingsForm;
  readonly pending: boolean;
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle as="h3">Retrieval depths</CardTitle>
        <CardDescription>
          How wide each arm searches, how many candidates are reranked, and how many reach the
          answer.
        </CardDescription>
      </CardHeader>

      <CardContent className="flex flex-col gap-4">
        <div className="grid gap-4 sm:grid-cols-2">
          {RETRIEVAL_DEPTHS.map((depth) => {
            const bound = boundFor(depth.field);

            return (
              <FormField
                key={depth.field}
                control={form.control}
                name={depth.field}
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{depth.label}</FormLabel>
                    <FormControl>
                      <Input
                        {...field}
                        type="number"
                        inputMode="numeric"
                        step={1}
                        // The SERVER's bounds, read from `UpdateBotRequest`'s dumped rules. An
                        // affordance on the control; the FormRequest is the authority.
                        min={bound?.min}
                        max={bound?.max}
                        value={asFieldText(field.value)}
                        onChange={(event) => {
                          field.onChange(numericFieldValue(event.target.value, undefined));
                        }}
                        disabled={pending}
                      />
                    </FormControl>
                    <FormDescription>
                      {depth.description}
                      {bound === null ? null : ` Accepted: ${bound.min} to ${bound.max}.`}
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />
            );
          })}
        </div>

        <p className="text-sm text-muted-foreground">{RERANK_IS_CAPABILITY_GATED}</p>
        <p className="text-sm text-muted-foreground">{DEPTHS_MOVE_BY_EVALUATION}</p>
      </CardContent>
    </Card>
  );
}

/**
 * THE SAME TEN FIELDS, WITHOUT `bots.manage` — read as text, with no form and no control.
 *
 * It renders the ids rather than the vendor names on purpose: the connection list is not fetched
 * here (an analyst holds neither `bots.manage` nor `providers.view`, so both reads would 403), and a
 * ULID an operator can quote is more honest than a blank where a name would be.
 *
 * The evidence threshold is the one field whose absence is stated rather than left blank, because
 * `null` there does not mean "zero" or "not set yet by mistake" — it means no calibration exists for
 * this pair and the platform declined to invent one.
 */
function ModelReadOnly({ bot }: { readonly bot: BotResource }) {
  const configured = isConfigured(bot.evidence_threshold) && bot.evidence_threshold_scale !== null;

  return (
    <Card>
      <CardHeader>
        <CardTitle as="h3">Model &amp; retrieval</CardTitle>
        <CardDescription>
          Which model answers, how much evidence it is given, and when it refuses.
        </CardDescription>
      </CardHeader>

      <CardContent className="flex flex-col gap-4">
        <dl className="grid gap-3 sm:grid-cols-2">
          <ReadOnlyRow
            label="Provider connection"
            value={bot.provider_connection_id ?? 'Not configured'}
            mono={bot.provider_connection_id !== null}
          />
          <ReadOnlyRow
            label="Model"
            value={bot.provider_model_id ?? 'Not configured'}
            mono={bot.provider_model_id !== null}
          />
          <ReadOnlyRow label="Answer mode" value={answerModeLabel(bot.answer_mode)} />
          <ReadOnlyRow
            label="May answer from general knowledge"
            value={bot.allow_general_answers ? 'Yes' : 'No'}
          />
          <ReadOnlyRow label="Dense candidates" value={String(bot.dense_top_k)} />
          <ReadOnlyRow label="Sparse candidates" value={String(bot.sparse_top_k)} />
          <ReadOnlyRow label="Reranked candidates" value={String(bot.rerank_candidates)} />
          <ReadOnlyRow label="Retained after reranking" value={String(bot.rerank_retain)} />
        </dl>

        <div className="flex flex-col gap-1 rounded-lg bg-card-inset p-4">
          <p className="text-base font-medium">Evidence threshold</p>
          {configured && bot.evidence_threshold_scale !== null ? (
            <p className="text-base">
              <span className="font-mono">{bot.evidence_threshold}</span> on the{' '}
              {evidenceScaleLabel(bot.evidence_threshold_scale)} scale.
            </p>
          ) : (
            <p className="text-base text-muted-foreground">
              None recorded, and there is no default. The scale is a property of the provider and
              model rather than of this bot, so a number carried across from another pair would move
              only the refusal rate and raise nothing anywhere — the platform declines to invent one.
            </p>
          )}
        </div>

        <p className="text-sm text-muted-foreground">{RERANK_IS_CAPABILITY_GATED}</p>
      </CardContent>
    </Card>
  );
}

function ReadOnlyRow({
  label,
  value,
  mono = false,
}: {
  readonly label: string;
  readonly value: string;
  readonly mono?: boolean;
}) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-sm text-muted-foreground">{label}</dt>
      <dd className={mono ? 'font-mono text-base break-all' : 'text-base'}>{value}</dd>
    </div>
  );
}
