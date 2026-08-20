'use client';

import {
  BOT_ACCESS_MODES,
  BOT_STATUSES,
  botSettingsSchema,
  botStatusTransitionDefaults,
  botStatusTransitionSchema,
  type BotSettingsIn,
  type BotSettingsOut,
  type BotStatusTransitionIn,
  type BotStatusTransitionOut,
} from '@kb/contracts/forms';
import type { BotResource } from '@kb/contracts';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState, type ReactNode } from 'react';
import { useForm } from 'react-hook-form';

import { StatusPill } from '@/components/status-pill';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { Textarea } from '@/components/ui/textarea';
import { applyAuthError } from '@/features/auth/auth-error';
import { actionableConflictMessage } from '@/lib/api/actionable-conflict';

import {
  BOT_PUBLISHING_FIELDS,
  BOT_STATUS_KNOWN_PATHS,
  botAccessModeLabel,
  botPanelDefaults,
  botStatusDisplay,
  updateBotStatus,
} from './api';
import { useBotEditor, useBotSave, useUnsavedBotEdits } from './bot-editor-context';
// The Model tab's helpers, imported rather than re-declared. All four fields below are a
// `z.preprocess`, so `field.value` is `unknown` at the control (`asFieldText`) and the raw DOM
// string must be mapped back to what the SERVER would read before it enters form state
// (`numericFieldValue` / `clearableFieldValue`) — otherwise `'60' !== 60` and the tab is dirty
// forever. This file used to carry private copies that did the first half and not the second.
import { asFieldText, clearableFieldValue, numericFieldValue } from './bot-model-shared';
import { BotOrigins } from './bot-origins';

/**
 * TAB 3 OF 3 — "Publishing". THIS FILE IS ONE AGENT'S, AND ONLY THIS FILE.
 *
 * The shell (`bot-editor-screen.tsx`) carries the whole contract in prose and is the file to read
 * first; `bot-editor-context.ts` carries the code half. Neither may be edited from here, and neither
 * may `./api.ts`. Private modules beside this one are fine — this tab has two
 * (`bot-origins.tsx`, `bot-origins-api.ts`).
 *
 * ── THIS TAB OWNS TWO SAVES AND ONE CHILD COLLECTION ────────────────────────────────────────────
 *
 *   `useBotSave(form, BOT_PUBLISHING_FIELDS)`   access_mode  rate_limit_per_minute
 *                                               rate_limit_per_day  retention_days
 *                                               collect_end_user_data  consent_text
 *   `updateBotStatus` + its own `useMutation`   the lifecycle transition
 *   `<BotOrigins>`                              GET·POST·PATCH·DELETE …/bots/{bot}/domains
 *
 * ── `status` IS NOT A FIELD OF THIS TAB'S FORM, AND THAT IS THE ONE THING TO GET RIGHT HERE ─────
 * A lifecycle move is `PUT …/bots/{bot}/status`. `UpdateBotRequest` rules `status` as
 * `["missing"]`, so it is not a `botSettingsSchema` key, not in `BOT_PUBLISHING_FIELDS`, and not
 * in `botFormDefaults`. A RULE rather than an ABSENT one is deliberate on the server's side
 * and is the reason the two saves are separate rather than merged: an absent rule makes
 * `validated()` discard the key in silence, so a console would publish a bot, receive a 200, and
 * find it still in `draft`.
 *
 * They are also two saves because their FAILURE VOCABULARIES differ. A transition can be refused
 * with a 409 that a field save can never produce, and merging them would put a publish attempt
 * behind a button whose label says "Save changes".
 *
 * ── THE PUBLISH REFUSAL IS A 409 WHOSE MESSAGE IS THE PRODUCT, AND IT IS RENDERED VERBATIM ──────
 * `BotService::assertPublishable()` refuses a move to `published` for a bot with no provider
 * connection and model, or one in `rag_first` answer mode with `allow_general_answers` still false.
 * Both are `ConflictHttpException`, which `bootstrap/app.php` renders as `internal_dependency`,
 * `retryable: false`, `actionable: true`, with a message written for a person that names the
 * remedy. The class-mapped copy for that class is "Something on our side is unavailable. Try again
 * shortly.", which is false twice — nothing is unavailable and retrying never works — so this is
 * one of the deliberate exceptions where a server `message` reaches the screen.
 *
 * IT IS NOT A NEW BRANCH TABLE. `actionableConflictMessage` (lib/api/actionable-conflict.ts) already
 * reads exactly this shape — `internal_dependency` + `!retryable` + `actionable` + non-empty message
 * — and it is handed to `applyAuthError`'s `copyFor` hook, which exists for precisely this: replace
 * the class-mapped sentence on ONE screen without forking the branch table. It was
 * `deleteConflictMessage` under `features/providers/` when this panel was written, and the note that
 * used to sit here reported the name as wrong for its third caller; the rename and the move to
 * `lib/api/` are that report being actioned.
 *
 * ── THE THIRD PUBLISH REFUSAL DOES NOT EXIST YET, AND THIS SCREEN SAYS SO ───────────────────────
 * `BotPolicy` names three: no model, no ASSIGNED KNOWLEDGE SOURCE, and the RAG-first pair. The
 * middle one is a deliberate `TODO(phase-c)` because `bot_source_assignments` does not exist. So a
 * bot really can be published with no sources at all, and it will answer nothing and refuse every
 * question — which from a console is indistinguishable from a broken retrieval pipeline. Papering
 * over that with a cheerful "Published" confirmation would make the eventual support ticket
 * unanswerable, so the lifecycle card states it beside the control.
 *
 * ── THE CONSENT PAIR IS THE SERVER'S, AND MIRRORING IT HERE WOULD REMOVE FUNCTIONALITY ──────────
 * `collect_end_user_data: true` is only storable together with `consent_text`, and that pairing is
 * deliberately absent from BOTH FormRequests and from `botSettingsSchema`. The rule would be
 * correct on the POST and WRONG on the PATCH, where enabling collection on a bot that already
 * carries a disclosure would be refused for a field the caller had no reason to resend. The whole
 * check lives in `BotService`, evaluated against the RESULTING row, with
 * `bots_consent_text_present_when_collecting` as the database's copy.
 *
 * So there is no client-side mirror: that is the direction that blocks a body the server accepts
 * and reports nothing. The two controls are rendered TOGETHER in one group and the 422 lands on
 * `consent_text`, which is in this tab's tuple and therefore under its own control.
 *
 * ── `access_mode` IS NOT AN ORIGIN ALLOW-LIST ──────────────────────────────────────────────────
 * It says whether an ANONYMOUS end user may converse. WHERE a widget may be embedded is a separate
 * server-side control — the allow-list at the bottom of this tab — and neither substitutes for the
 * other. The publish guard deliberately does not look at `access_mode` either: `published` +
 * `public` with an EMPTY allow-list is a legitimate intermediate state that hosted chat serves
 * correctly, and the refusal belongs at the embed check. Both facts are said beside their controls
 * rather than left for a reader to infer from "Public".
 *
 * ── THE THREE NULLABLE NUMBERS MEAN SOMETHING A ZERO DOES NOT ──────────────────────────────────
 * `rate_limit_per_minute`, `rate_limit_per_day` and `retention_days` are nullable, and `null` means
 * "no limit" / "keep forever" — a different intention from omitting the key. `nullableIntField`
 * keeps them apart: a CLEARED input posts `null`, an absent key leaves the column alone. Zero is
 * neither: it is a bot that answers nobody, and both `min:1` and `bots_rate_limits_positive` refuse
 * it. The controls must not collapse the three.
 */
export function BotPublishingPanel() {
  const { canManage } = useBotEditor();

  // ── §5a: `canManage === false` MEANS RENDER NO FORM AT ALL ────────────────────────────────────
  // Not a disabled form — a disabled input holding a value is a control an operator will keep
  // clicking, and RHF strips disabled names from a submit anyway. The shell has already said WHY,
  // once, above the tabs, so this branch says only what the row currently holds. The two branches
  // are separate components so that the read-only one mounts no `useForm` and no mutation at all.
  return canManage ? <PublishingEditor /> : <PublishingSummary />;
}

/**
 * ── THE EDITOR: TWO FORMS, ONE CHILD COLLECTION, AND EXACTLY ONE UNSAVED-EDIT REPORT ───────────
 *
 * `useUnsavedBotEdits` is keyed by PANEL, not by form, and this panel has three sources of unsaved
 * text. Calling it three times with `'publishing'` would make the last effect's write the only one
 * that survives — `reportUnsaved(panel, false)` from one form's cleanup would clear the entry
 * another form had just set — so the three dirty flags are ORed into one boolean and reported once.
 * The origins form reports up through a callback for the same reason.
 */
function PublishingEditor() {
  const [statusDirty, setStatusDirty] = useState(false);
  const [settingsDirty, setSettingsDirty] = useState(false);
  const [originsDirty, setOriginsDirty] = useState(false);

  useUnsavedBotEdits('publishing', statusDirty || settingsDirty || originsDirty);

  return (
    // Sections are separated by --space-8, never by a divider (the composition law).
    <div className="flex flex-col gap-8">
      <LifecycleCard onDirtyChange={setStatusDirty} />
      <ReachAndRetentionCard onDirtyChange={setSettingsDirty} />
      <BotOrigins onUnsavedChange={setOriginsDirty} />
    </div>
  );
}

/**
 * `formState.isDirty` -> the panel's single unsaved-edit boolean.
 *
 * AN EFFECT AND NOT A CALL IN THE RENDER BODY: writing to a PARENT's state while a child renders is
 * the "Cannot update a component while rendering a different component" warning, and React's repair
 * for it is to re-render the parent immediately — which re-renders every sibling form on the first
 * keystroke in any of them. The cleanup clears this form's contribution, so a form that unmounts
 * cannot leave the tab guard armed over state that no longer exists.
 */
function useReportDirty(dirty: boolean, onDirtyChange: (dirty: boolean) => void): void {
  useEffect(() => {
    onDirtyChange(dirty);
    return () => {
      onDirtyChange(false);
    };
  }, [dirty, onDirtyChange]);
}

/**
 * THE LIFECYCLE TRANSITION — its own form, its own schema, its own endpoint, its own refusals.
 *
 * `botStatusTransitionDefaults(bot)` opens on the bot's CURRENT status, which is deliberately the
 * one value the server refuses: a no-op is a 422, because re-asserting the status a bot already
 * holds would write an audit row describing a change that did not happen. That makes "submit
 * without choosing anything" a refusal rather than a silent success, which is the correct reading
 * of a transition endpoint — and it means the control cannot be seeded with some other member
 * without misreporting where the bot stands while it sits there.
 *
 * EVERY MEMBER OF `BOT_STATUSES` IS OFFERED AND NONE IS PRE-JUDGED. Three of the four refusals are
 * uncomputable from a cached row — the archived terminal rule, and both halves of the publish guard,
 * which run against the state the write LEAVES the bot in rather than against the transition. Greying
 * out the options a client believes are unreachable is the publish guard reimplemented in the
 * browser, and it fails by hiding a move the server would have allowed.
 */
function LifecycleCard({ onDirtyChange }: { readonly onDirtyChange: (dirty: boolean) => void }) {
  const { orgId, botId, bot, botKey, botsListKey } = useBotEditor();
  const queryClient = useQueryClient();

  const form = useForm<BotStatusTransitionIn, unknown, BotStatusTransitionOut>({
    resolver: zodResolver(botStatusTransitionSchema),
    defaultValues: botStatusTransitionDefaults(bot),
    mode: 'onTouched',
  });

  const transition = useMutation<BotResource, Error, BotStatusTransitionOut>({
    mutationFn: (values) => updateBotStatus(orgId, botId, values),
    onSuccess: (updated) => {
      // The 200 body is THE BOT, not an acknowledgement, with both instruction fields populated —
      // reaching this endpoint requires `bots.manage`, which is the permission that projection is
      // gated on. Writing it into the detail key is not an optimistic write: it is the row the
      // server derived.
      queryClient.setQueryData(botKey, updated);
      form.reset(botStatusTransitionDefaults(updated));
    },
    onError: (error) => {
      applyAuthError(form, BOT_STATUS_KNOWN_PATHS, error, {
        // The publish guard's 409 carries the only sentence that tells the operator what to fix.
        // Returning `undefined` for everything else keeps the class-mapped default from ERROR_COPY,
        // so this is one expression rather than a second branch table. `actionableConflictMessage`
        // identifies the shape by the envelope's `actionable` flag, never by an HTTP status —
        // `KbError` carries none.
        copyFor: (kbError) => actionableConflictMessage(kbError) ?? undefined,
      });
    },
    onSettled: () => {
      // The list prefix, which also catches the detail. A status change moves rows in a list this
      // screen is not looking at. `onSettled` and not `onSuccess`: a 422 no-op means the row in
      // front of the operator is stale, which is exactly what the server's message says.
      void queryClient.invalidateQueries({ queryKey: botsListKey });
    },
  });

  // Reported to the panel, which ORs the three flags into one `useUnsavedBotEdits` call.
  useReportDirty(form.formState.isDirty, onDirtyChange);

  const rootError = form.formState.errors.root?.serverError?.message;
  /** Non-null exactly when the server refused with a sentence written for a person. */
  const refusal =
    transition.error === null ? null : actionableConflictMessage(transition.error);
  const current = botStatusDisplay(bot.status);

  return (
    <section aria-labelledby="publishing-lifecycle-heading">
      <Card>
        <CardHeader>
          <CardTitle as="h3" id="publishing-lifecycle-heading">
            Lifecycle
          </CardTitle>
          <CardDescription>
            Whether this bot is answering, and who it is answering for. A transition is its own
            action with its own audit entry — it is not saved with the settings below.
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <p className="flex flex-wrap items-center gap-2 text-base">
            <span className="text-muted-foreground">This bot is</span>
            <StatusPill status={current.kind} label={current.label} />
          </p>

          {rootError === undefined ? null : (
            <Alert variant="destructive">
              <AlertTitle>
                {refusal === null
                  ? 'That status change did not go through'
                  : 'This bot cannot be published yet'}
              </AlertTitle>
              <AlertDescription>
                {/* THE SERVER'S OWN SENTENCE when it wrote one for this condition, and the
                    class-mapped one plus the request_id otherwise. Never the raw envelope
                    `message` on a path where `actionable` is false — that field is operator-facing
                    and can carry an internal hostname. */}
                {rootError}
                {refusal === null ? null : (
                  <>
                    {' '}
                    The fields it names are on the <strong>Model &amp; retrieval</strong> tab.
                  </>
                )}
              </AlertDescription>
            </Alert>
          )}

          <Form {...form}>
            <form
              method="post"
              noValidate
              className="flex flex-col gap-4 sm:flex-row sm:items-start"
              onSubmit={form.handleSubmit((values) => {
                transition.mutate(values);
              })}
            >
              <FormField
                control={form.control}
                name="status"
                render={({ field }) => (
                  <FormItem className="flex-1">
                    {/* "Lifecycle status", not "Status": role- and label-name matching is a
                        case-insensitive SUBSTRING, and every origin row below carries a select
                        named "Allow-list status for …". Neither name contains the other. */}
                    <FormLabel htmlFor="bot-lifecycle-status">Lifecycle status</FormLabel>
                    {/* A Radix Select has no `register` ref, so `applyServerErrors` never spends
                        `shouldFocus` on this path; the message still renders through FormMessage. */}
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger
                          id="bot-lifecycle-status"
                          aria-label="Lifecycle status"
                          className="w-full"
                        >
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {BOT_STATUSES.map((member) => (
                          <SelectItem key={member} value={member}>
                            {botStatusDisplay(member).label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormDescription>
                      Published makes the bot reachable by end users. Archived is permanent — an
                      archived bot is read-only, including its status, so there is no way back; use
                      Paused for a bot you expect to return.
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <Button type="submit" disabled={transition.isPending} className="sm:mt-8">
                {transition.isPending ? 'Changing…' : 'Change status'}
              </Button>
            </form>
          </Form>

          {/* STATED BEFORE THE OPERATOR PUBLISHES, not after they open a support ticket. The
              assigned-source refusal is a `TODO(phase-c)` in `BotService::assertPublishable()`
              because `bot_source_assignments` does not exist yet, so nothing refuses this and
              nothing warns about it anywhere else in the product. */}
          <Alert variant="warning">
            <AlertTitle>Publishing does not check that this bot has any knowledge</AlertTitle>
            <AlertDescription>
              Assigning knowledge sources to a bot is not built yet, so the publish check cannot ask
              about it. A published bot with nothing assigned accepts questions and answers none of
              them — which looks exactly like a broken retrieval pipeline from here. Publish for a
              real audience only once sources exist.
            </AlertDescription>
          </Alert>
        </CardContent>
      </Card>
    </section>
  );
}

/**
 * THE SIX-FIELD SAVE — `useBotSave(form, BOT_PUBLISHING_FIELDS)` and nothing outside the tuple.
 *
 * `botSettingsSchema` mirrors a PATCH: every field is optional, so a form seeded with one tuple
 * parses to exactly that tuple and the request body carries exactly those keys. That is what lets
 * this tab save without rewriting the other two tabs' fields.
 */
function ReachAndRetentionCard({
  onDirtyChange,
}: {
  readonly onDirtyChange: (dirty: boolean) => void;
}) {
  const { bot } = useBotEditor();

  const form = useForm<BotSettingsIn, unknown, BotSettingsOut>({
    resolver: zodResolver(botSettingsSchema),
    // The ONE sanctioned path from server data into form state. Never `reset(resource)` — that
    // round-trips `id`, `public_bot_id`, `retrieval_configuration_version` and both timestamps into
    // a 200 with no change — and never `botFormDefaults(bot)` unfiltered, which would make every
    // save here rewrite the other two tabs' fields.
    defaultValues: botPanelDefaults(bot, BOT_PUBLISHING_FIELDS),
    mode: 'onTouched',
  });

  const save = useBotSave(form, BOT_PUBLISHING_FIELDS);

  useReportDirty(form.formState.isDirty, onDirtyChange);

  const rootError = form.formState.errors.root?.serverError?.message;

  return (
    <section aria-labelledby="publishing-reach-heading">
      <Card>
        <CardHeader>
          <CardTitle as="h3" id="publishing-reach-heading">
            Reach &amp; retention
          </CardTitle>
          <CardDescription>
            Who may talk to this bot, how often, and what is kept afterwards.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Form {...form}>
            <form
              method="post"
              noValidate
              className="flex flex-col gap-6"
              onSubmit={form.handleSubmit((values) => {
                save.mutate(values);
              })}
            >
              {rootError === undefined ? null : (
                // A 422 keyed OUTSIDE this tuple lands here rather than nowhere — the consent
                // pairing is evaluated by `BotService` against the RESULTING row and can be keyed
                // to a field the operator did not send.
                <Alert variant="destructive">
                  <AlertTitle>These settings were not saved</AlertTitle>
                  <AlertDescription>{rootError}</AlertDescription>
                </Alert>
              )}

              <FormField
                control={form.control}
                name="access_mode"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel htmlFor="bot-access-mode">Access mode</FormLabel>
                    <Select value={field.value} onValueChange={field.onChange}>
                      <FormControl>
                        <SelectTrigger
                          id="bot-access-mode"
                          aria-label="Access mode"
                          className="w-full sm:w-64"
                        >
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {BOT_ACCESS_MODES.map((mode) => (
                          <SelectItem key={mode} value={mode}>
                            {botAccessModeLabel(mode)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    <FormDescription>
                      Public lets an anonymous end user converse; private requires a signed-in
                      member of this organization. It is not an origin allow-list and is no
                      substitute for one — where a widget may be embedded is the list at the bottom
                      of this tab, and Public with an empty allow-list still refuses every embed.
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <div className="grid gap-4 sm:grid-cols-2">
                <FormField
                  control={form.control}
                  name="rate_limit_per_minute"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Messages per minute</FormLabel>
                      <FormControl>
                        {/* `type="number"` with the VALUE handled as text, so an empty control is
                            `''` and reaches the preprocess as the null it means. `valueAsNumber`
                            would make it `NaN`, which is a type error nobody can act on, and
                            coercing `''` would make it 0 — a limit of nothing, which is a bot that
                            answers nobody rather than a bot with no limit. */}
                        <Input
                          type="number"
                          inputMode="numeric"
                          min={1}
                          step={1}
                          name={field.name}
                          ref={field.ref}
                          onBlur={field.onBlur}
                          value={asFieldText(field.value)}
                          onChange={(event) => {
                            field.onChange(numericFieldValue(event.target.value, null));
                          }}
                          placeholder="No limit"
                        />
                      </FormControl>
                      <FormDescription>
                        Leave empty for no limit. Zero is not a limit and is refused.
                      </FormDescription>
                      <FormMessage />
                    </FormItem>
                  )}
                />

                <FormField
                  control={form.control}
                  name="rate_limit_per_day"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Messages per day</FormLabel>
                      <FormControl>
                        <Input
                          type="number"
                          inputMode="numeric"
                          min={1}
                          step={1}
                          name={field.name}
                          ref={field.ref}
                          onBlur={field.onBlur}
                          value={asFieldText(field.value)}
                          onChange={(event) => {
                            field.onChange(numericFieldValue(event.target.value, null));
                          }}
                          placeholder="No limit"
                        />
                      </FormControl>
                      <FormDescription>
                        Leave empty for no limit. This is the bot&apos;s own ceiling; the platform
                        limits and your quota still apply on top of it.
                      </FormDescription>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </div>

              <FormField
                control={form.control}
                name="retention_days"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Conversation retention</FormLabel>
                    <FormControl>
                      <Input
                        type="number"
                        inputMode="numeric"
                        min={1}
                        step={1}
                        className="sm:w-64"
                        name={field.name}
                        ref={field.ref}
                        onBlur={field.onBlur}
                        value={asFieldText(field.value)}
                        onChange={(event) => {
                          field.onChange(numericFieldValue(event.target.value, null));
                        }}
                        placeholder="Keep indefinitely"
                      />
                    </FormControl>
                    <FormDescription>
                      Days. Leave empty to keep conversations indefinitely — which is a decision
                      rather than a default, because it is also what an end user&apos;s messages are
                      kept for.
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />

              {/* ── THE PAIR, RENDERED AS A PAIR ────────────────────────────────────────────────
                  `collect_end_user_data: true` is storable only together with `consent_text`, and
                  that rule is the SERVER's alone — it is not in either FormRequest and not in
                  `botSettingsSchema`, because it would be correct on the POST and wrong on the
                  PATCH. A client mirror would block a body the server accepts, which is the
                  direction that removes functionality with nothing reported. So the two controls
                  sit in one group with one explanation, and the 422 lands on `consent_text`. */}
              <fieldset className="flex flex-col gap-4 rounded-xl bg-card-inset p-card-pad-sm">
                <legend className="px-1 text-base font-medium">End-user data</legend>

                <FormField
                  control={form.control}
                  name="collect_end_user_data"
                  render={({ field }) => (
                    <FormItem className="flex flex-row items-center gap-3">
                      <FormControl>
                        {/* `role="switch"` with `aria-checked`, operable with Space, taking its id
                            and `aria-describedby` from `<FormControl>` — which is why neither is
                            set by hand and why `<FormLabel>` needs no `htmlFor`. */}
                        <Switch
                          checked={field.value === true}
                          onCheckedChange={field.onChange}
                          disabled={save.isPending}
                        />
                      </FormControl>
                      <div className="flex flex-col">
                        <FormLabel>Collect end-user details</FormLabel>
                        <FormDescription>
                          Name, email or anything else the chat surface asks an end user for.
                        </FormDescription>
                      </div>
                      <FormMessage />
                    </FormItem>
                  )}
                />

                <FormField
                  control={form.control}
                  name="consent_text"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Disclosure shown before the first message</FormLabel>
                      <FormControl>
                        <Textarea
                          {...field}
                          value={asFieldText(field.value)}
                          // `clearableText` maps blank -> `null`, so a type-and-delete has to land
                          // on `null` too. Writing the raw `''` would leave `'' !== null` against a
                          // stored null and arm the tab guard on a form nobody edited.
                          onChange={(event) => {
                            field.onChange(clearableFieldValue(event.target.value));
                          }}
                          rows={3}
                          placeholder="We store your name and email so we can follow up on this conversation."
                        />
                      </FormControl>
                      <FormDescription>
                        Required whenever collection is on — the server checks the pair against the
                        row this save produces, not against what you sent, so clearing this while
                        collection stays on is refused too. Clearing it while collection is off is
                        fine.
                      </FormDescription>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </fieldset>

              <div>
                <Button type="submit" disabled={save.isPending}>
                  {save.isPending ? 'Saving…' : 'Save changes'}
                </Button>
              </div>
            </form>
          </Form>
        </CardContent>
      </Card>
    </section>
  );
}

/**
 * THE READ-ONLY VIEW, for a viewer who holds `bots.view` and not `bots.manage`.
 *
 * No `useForm`, no mutation, no disabled control. The shell has already said, once, that this
 * viewer may read the bot and not change it, so nothing here repeats that as an error — it states
 * what the row holds, which is exactly what an analyst reading this screen is entitled to.
 *
 * The origins list still renders, and that is not an oversight:
 * `App\Enums\Permission::BotsView` names "a bot's configuration, its origin allow-list, and its
 * starter questions" in as many words. `<BotOrigins>` reads `canManage` itself and mounts no form.
 */
function PublishingSummary() {
  const { bot } = useBotEditor();
  const status = botStatusDisplay(bot.status);

  return (
    <div className="flex flex-col gap-8">
      <section aria-labelledby="publishing-summary-heading">
        <Card>
          <CardHeader>
            <CardTitle as="h3" id="publishing-summary-heading">
              Publishing
            </CardTitle>
            <CardDescription>
              Whether this bot is answering, who may talk to it, and what is kept afterwards.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <dl className="grid gap-4 sm:grid-cols-2">
              <Fact label="Lifecycle status">
                <StatusPill status={status.kind} label={status.label} />
              </Fact>
              <Fact label="Access mode">{botAccessModeLabel(bot.access_mode)}</Fact>
              <Fact label="Messages per minute">
                {bot.rate_limit_per_minute === null ? 'No limit' : bot.rate_limit_per_minute}
              </Fact>
              <Fact label="Messages per day">
                {bot.rate_limit_per_day === null ? 'No limit' : bot.rate_limit_per_day}
              </Fact>
              <Fact label="Conversation retention">
                {bot.retention_days === null ? 'Kept indefinitely' : `${bot.retention_days} days`}
              </Fact>
              <Fact label="End-user data">
                {bot.collect_end_user_data ? 'Collected' : 'Not collected'}
              </Fact>
            </dl>

            {/* Tenant-authored prose as a JSX child, never markup. Absent is a different fact from
                empty and reads differently. */}
            {bot.collect_end_user_data ? (
              <p className="mt-4 max-w-prose text-base">
                <span className="text-muted-foreground">Disclosure shown to end users: </span>
                {bot.consent_text === null ? 'none stored' : bot.consent_text}
              </p>
            ) : null}
          </CardContent>
        </Card>
      </section>

      <BotOrigins />
    </div>
  );
}

/** One labelled fact in the read-only view. A real `<dt>`/`<dd>` pair, not two styled spans. */
function Fact({ label, children }: { readonly label: string; readonly children: ReactNode }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="text-caption text-muted-foreground uppercase">{label}</dt>
      <dd className="text-base">{children}</dd>
    </div>
  );
}
