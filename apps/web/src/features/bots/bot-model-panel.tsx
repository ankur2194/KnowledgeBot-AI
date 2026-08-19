'use client';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

import { useBotEditor } from './bot-editor-context';

/**
 * TAB 2 OF 3 — "Model & retrieval". THIS FILE IS ONE AGENT'S, AND ONLY THIS FILE.
 *
 * The shell (`bot-editor-screen.tsx`) carries the whole contract in prose and is the file to read
 * first; `bot-editor-context.ts` carries the code half. Neither may be edited from here, and neither
 * may `./api.ts`. Private modules beside this one are fine.
 *
 * ── WHAT LANDS HERE ─────────────────────────────────────────────────────────────────────────────
 * `BOT_MODEL_FIELDS` from `./api`, and nothing outside it:
 *
 *     provider_connection_id  provider_model_id  answer_mode  allow_general_answers
 *     dense_top_k  sparse_top_k  rerank_candidates  rerank_retain
 *     evidence_threshold  evidence_threshold_scale
 *
 * ── EVERY CROSS-FIELD RULE IN THE SCHEMA IS IN THIS TUPLE, WHICH MAKES THIS THE TAB THAT CAN
 *    ACTUALLY BREAK ────────────────────────────────────────────────────────────────────────────
 * Three of `botSettingsSchema`'s `superRefine`s judge fields against siblings, and all three siblings
 * are yours — which is why the partition was drawn this way and why moving a field out of this tuple
 * would produce a form that can never satisfy its own resolver:
 *
 *   · `evidence_threshold` and `evidence_threshold_scale` are `required_with` EACH OTHER. Half a pair
 *     is a number in no units at all: the same float is an unbounded logit on one provider and a
 *     bounded relevance score on another. `bots_evidence_threshold_paired` refuses it one layer down.
 *   · A threshold on `sigmoid` or `unit_interval` is between 0 and 1; `logit` is unbounded and signed.
 *     Applying one scale's number to the other raises NOTHING anywhere — only the refusal rate moves,
 *     only in aggregate — so the control has to make the pairing visible rather than leave it to a
 *     422.
 *   · `provider_model_id` requires `provider_connection_id`, one direction only. A connection with no
 *     model is a real state ("vendor chosen, model not yet"); a model with no connection names no
 *     credential at all, because a catalogue row reaches one only through its parent.
 *
 * The PATCH's own asymmetry is the trap: a body that clears only the scale passes every declarative
 * rule and still violates the CHECK against the STORED threshold, so `BotService` re-checks the
 * resulting pair and answers 422 on a field the operator did not send. `botPanelKnownPaths` puts that
 * key under its control when it is in this tuple, and in the banner when it is not.
 *
 * ── THE TWO READS THIS TAB NEEDS THAT THE SHELL DOES NOT MAKE ──────────────────────────────────
 * The connection and model selectors need `features/providers/api.ts`'s `fetchConnections` and
 * `features/models/api.ts`'s `fetchModels`. Build their keys with `useOrgKey()` like any other
 * screen, forward `signal`, and set no `retry`. A `providers.view`-less viewer is not a case: all
 * four roles hold it except analyst — check before hiding anything, and remember that hiding is an
 * affordance and Laravel is the gate.
 *
 * Reranking is CAPABILITY-GATED and its absence is silent: a model flagged for rerank that the vendor
 * cannot rerank with is accepted, shows no error, and simply never reranks. Say so beside
 * `rerank_candidates`/`rerank_retain` rather than in a tooltip.
 *
 * ── THE FORM, VERBATIM ──────────────────────────────────────────────────────────────────────────
 *     const form = useForm<BotSettingsIn, unknown, BotSettingsOut>({
 *       resolver: zodResolver(botSettingsSchema),
 *       defaultValues: botPanelDefaults(bot, BOT_MODEL_FIELDS),
 *       mode: 'onTouched',
 *     });
 *     const save = useBotSave(form, BOT_MODEL_FIELDS);
 *     useUnsavedBotEdits('model', form.formState.isDirty);
 */
export function BotModelPanel() {
  const { bot } = useBotEditor();

  return (
    <Card>
      <CardHeader>
        <CardTitle as="h3">Model &amp; retrieval</CardTitle>
        <CardDescription>
          Which model answers, how much evidence it is given, and when it refuses.
        </CardDescription>
      </CardHeader>
      <CardContent>
        {/* The controls are the next step. Until they land this states the four depths already stored
            on the row, which is what an operator comparing two bots actually wants to read. */}
        <p className="text-base text-muted-foreground">
          Retrieval currently fetches {bot.dense_top_k} dense and {bot.sparse_top_k} sparse
          candidates, reranks {bot.rerank_candidates} of them and keeps {bot.rerank_retain}. The
          model selection, the answer mode and the evidence threshold are edited here.
        </p>
      </CardContent>
    </Card>
  );
}
