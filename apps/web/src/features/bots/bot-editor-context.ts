import type { BotResource } from '@kb/contracts';
import type { BotSettingsIn, BotSettingsOut } from '@kb/contracts/forms';
import { useMutation, useQueryClient, type UseMutationResult } from '@tanstack/react-query';
import { createContext, useContext, useEffect, useMemo } from 'react';
import type { UseFormReturn } from 'react-hook-form';

import { applyAuthError } from '@/features/auth/auth-error';

import { botPanelDefaults, botPanelKnownPaths, updateBot, type BotSettingsField } from './api';

/**
 * THE CONTRACT THE THREE EDITOR TABS ARE WRITTEN AGAINST — the context they read, the save they
 * call, and the unsaved-edit report the shell needs from them.
 *
 * `bot-editor-screen.tsx` holds the whole contract in prose and is the file to read first. This
 * module is the part of it that is code, and it is deliberately the ONLY module a panel has to
 * import besides `./api` and the shadcn primitives.
 *
 * ── IT IS A `.ts` AND NOT A `.tsx`, AND THAT IS LOAD-BEARING ─────────────────────────────────────
 * Same constraint `features/auth/session-context.ts` records: the `unit` Vitest project runs in
 * `node` with NO react plugin while `tsconfig.json` sets `jsx: "preserve"`, so esbuild leaves JSX
 * verbatim and Vite fails the whole FILE with "content contains invalid JS syntax" — for any module
 * a `tests/unit/**` spec reaches TRANSITIVELY. `tests/unit/bot-editor.test.ts` reaches `./api`, and
 * the day it reaches this module too, a JSX element added here would fail the spec by filename.
 * `createContext` is not JSX; a `<BotEditorProvider>` component is, which is why the provider lives
 * in the shell rather than here.
 *
 * ── NO `'use client'` DIRECTIVE, DELIBERATELY ────────────────────────────────────────────────────
 * A module reached only from client components is already in the client graph. The directive belongs
 * where the boundary actually is, which is `bot-editor-screen.tsx`.
 */

/** The three tab values, and the three panel identities. `Tabs` reads these as its `value`s. */
export type BotEditorPanelId = 'identity' | 'model' | 'publishing';

export interface BotEditorContextValue {
  /**
   * A ROUTING HINT in every path this feature builds, and separately the CACHE NAMESPACE at segment
   * 1 of every key. It is never a request parameter: Laravel derives the real organization from the
   * session cookie and would ignore a client-supplied one.
   */
  readonly orgId: string;
  readonly botId: string;
  /**
   * The LOADED row. A panel only ever mounts inside a resolved query, so this is never `undefined`
   * and no panel needs a pending branch of its own — the shell owns all four states.
   *
   * `system_instruction` and `answer_style_instruction` are a MANAGEMENT-ONLY PROJECTION: `null`
   * when `canManage` is false, whatever is stored. `null` there means "not shown to you", NOT "not
   * set", and nothing may seed a control from one without `canManage`.
   */
  readonly bot: BotResource;
  /** `['org', orgId, 'bots', botId]`, built once by the shell. */
  readonly botKey: readonly unknown[];
  /** `['org', orgId, 'bots']` — the PREFIX every list page, sort and filter hangs off. */
  readonly botsListKey: readonly unknown[];
  /**
   * Whether this viewer holds `bots.manage` (owner or admin, ADR-056), read from the membership list
   * the server itself handed us. AN AFFORDANCE, NEVER AUTHORIZATION: Laravel answers 403 whatever a
   * panel renders, `useBotSave` handles that class, and a role that changed under a cached session
   * shows up as that 403 rather than as a silently missing control.
   */
  readonly canManage: boolean;
  /**
   * Written by `useUnsavedBotEdits`, read by the shell when the tab changes. A panel never calls this
   * directly.
   */
  readonly reportUnsaved: (panel: BotEditorPanelId, unsaved: boolean) => void;
}

/** Written only by `<BotEditorProvider>` in bot-editor-screen.tsx. */
export const BotEditorContext = createContext<BotEditorContextValue | null>(null);

/**
 * The loaded bot, its keys, and the viewer's write capability.
 *
 * It THROWS outside the shell rather than returning a degraded value, for the reason `useOrgKey`
 * does: a panel rendered outside the editor would otherwise build a key from `undefined`, and one
 * namespace shared by every org-less state is exactly the leak the prefix exists to prevent. A throw
 * is a mounting mistake the developer sees on the first render.
 */
export function useBotEditor(): BotEditorContextValue {
  const value = useContext(BotEditorContext);
  if (value === null) {
    throw new Error('useBotEditor() used outside <BotEditorProvider> (bot-editor-screen.tsx).');
  }
  return value;
}

/**
 * THE SAVE. One PATCH, one 422 mapping, one invalidation — for all three panels.
 *
 * ```tsx
 * const form = useForm<BotSettingsIn, unknown, BotSettingsOut>({
 *   resolver: zodResolver(botSettingsSchema),
 *   defaultValues: botPanelDefaults(bot, BOT_IDENTITY_FIELDS),
 *   mode: 'onTouched',
 * });
 * const save = useBotSave(form, BOT_IDENTITY_FIELDS);
 * ...
 * <form method="post" noValidate onSubmit={form.handleSubmit((values) => { save.mutate(values); })}>
 * <Button type="submit" disabled={save.isPending}>{save.isPending ? 'Saving…' : 'Save changes'}</Button>
 * ```
 *
 * ── WHY IT TAKES THE FIELD TUPLE AS WELL AS THE FORM ────────────────────────────────────────────
 * The tuple decides two different things and getting either from somewhere else re-forks the
 * partition: which 422 keys this panel can put UNDER A CONTROL (`botPanelKnownPaths`), and which
 * fields the post-save `reset` re-seeds from the server's row. Pass the same constant the form's
 * `defaultValues` came from — `BOT_IDENTITY_FIELDS`, `BOT_MODEL_FIELDS` or `BOT_PUBLISHING_FIELDS`
 * — and never a literal array, which is a fresh identity on every render.
 *
 * ── THE BODY IS `handleSubmit`'s OUTPUT VERBATIM, WHICH IS WHY THE PARTITION MATTERS ────────────
 * `botSettingsSchema` is a `strictObject` whose every field is optional, mirroring `sometimes` on the
 * PATCH, so parsing a form seeded with ONE panel's fields yields exactly those keys and the request
 * carries exactly those keys. A panel seeded from `botFormDefaults(bot)` wholesale would send all 25
 * and rewrite the other two tabs' fields on every save. `botPanelDefaults` is the narrowing, and it
 * is the only sanctioned path from server data into form state — `reset(resource)` is never one.
 *
 * ── NOTHING HERE IS OPTIMISTIC ──────────────────────────────────────────────────────────────────
 * `onSuccess` writes the SERVER'S OWN 200 body into the detail key. That is not an optimistic write:
 * it is the row the server derived, including the fields it computed rather than accepted
 * (`retrieval_configuration_version` moves only when a knob's VALUE changes, `updated_at`, and a
 * `status` the publish guard may have refused to move). `form.reset` re-seeds from the same row, so
 * the panel's dirty flag clears against what was actually stored rather than against what was typed.
 *
 * ── THE INVALIDATION IS THE LIST PREFIX, AND IT DELIBERATELY CATCHES THE DETAIL TOO ─────────────
 * `botsListKey` is `['org', orgId, 'bots']`, which prefix-matches every page/sort/filter entry — a
 * renamed bot, a new slug or a changed status all move rows in a list this screen is not looking at.
 * It ALSO prefix-matches `['org', orgId, 'bots', botId]`, so the detail is re-read once per save.
 * That is a cost accepted on purpose rather than an oversight: one GET buys a row confirmed by a
 * second round trip, and the alternative — a `predicate` that excludes the detail by inspecting key
 * shape — encodes the key layout in a second place, which is how two spellings start.
 *
 * `onSettled` and not `onSuccess`: a 422 means the row in front of the operator may ALSO be stale,
 * because the rule that refused them was evaluated against a STORED value they cannot see.
 *
 * ── NO `retry` PROPERTY, AND THE IDENTIFIER IS AN ESLINT ERROR OUTSIDE lib/query/client.ts ──────
 * Mutations default to zero attempts and stay there: a PATCH carries no `Idempotency-Key`, so
 * nothing — including a double-click — may replay it. Disable the submit on `isPending`.
 */
export function useBotSave(
  form: UseFormReturn<BotSettingsIn, unknown, BotSettingsOut>,
  fields: readonly BotSettingsField[],
): UseMutationResult<BotResource, Error, BotSettingsOut> {
  const { orgId, botId, botKey, botsListKey } = useBotEditor();
  const queryClient = useQueryClient();

  // Derived once per tuple identity. The tuples are module-scope constants in ./api, so this is one
  // computation per panel for the life of the screen.
  const knownPaths = useMemo(() => botPanelKnownPaths(fields), [fields]);

  return useMutation<BotResource, Error, BotSettingsOut>({
    mutationFn: (values) => updateBot(orgId, botId, values),
    onSuccess: (bot) => {
      queryClient.setQueryData(botKey, bot);
      // Re-seeded from the SERVER'S row, through the one sanctioned narrowing. This is what clears
      // `formState.isDirty`, which is what the shell's tab guard reads.
      form.reset(botPanelDefaults(bot, fields));
    },
    onError: (error) => {
      // Branches on `error_class`, never on an HTTP status — one class renders several statuses by
      // surface. `validation` returns early inside the helper, so a per-field 422 is never
      // overwritten by a banner; `authorization` (403 for a missing `bots.manage`, and 404 for a bot
      // this session can no longer address) falls through to the class-mapped sentence plus the
      // request_id. The envelope's own `message` reaches no rendered string.
      applyAuthError(form, knownPaths, error);
    },
    onSettled: () => {
      void queryClient.invalidateQueries({ queryKey: botsListKey });
    },
  });
}

/**
 * REPORT UNSAVED EDITS TO THE SHELL. One line per panel, and the panel does nothing else about it.
 *
 * ```tsx
 * useUnsavedBotEdits('identity', form.formState.isDirty);
 * ```
 *
 * ── WHY THE SHELL HAS TO OWN THIS AND A PANEL CANNOT ────────────────────────────────────────────
 * Radix's `TabsContent` renders `present && children`, so an unselected panel's CHILDREN are unmounted
 * (the `role="tabpanel"` div itself stays, `hidden`). `forceMount` does not help — it makes `present`
 * unconditionally true and `hidden` is `!present`, so all three panels would become visible at once.
 * Measured in tests/components/bot-editor-screen.test.tsx rather than assumed. So switching tabs destroys the outgoing panel's form state, and the outgoing panel
 * is not around to object. Only the component that owns `onValueChange` can intercept the change,
 * and that is the shell.
 *
 * A `Set` keyed by panel rather than one boolean: today exactly one panel is mounted, so a boolean
 * would do, and it would silently stop working the day a panel is force-mounted or the layout gains
 * a second visible pane. The cleanup clears this panel's entry on unmount, which is what makes the
 * ordinary tab change (confirmed, or never dirty) leave nothing behind.
 */
export function useUnsavedBotEdits(panel: BotEditorPanelId, unsaved: boolean): void {
  const { reportUnsaved } = useBotEditor();

  useEffect(() => {
    reportUnsaved(panel, unsaved);
    return () => {
      reportUnsaved(panel, false);
    };
  }, [panel, unsaved, reportUnsaved]);
}
