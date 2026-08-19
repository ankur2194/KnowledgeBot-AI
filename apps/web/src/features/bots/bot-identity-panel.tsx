'use client';

import type { BotThemeRadius } from '@kb/contracts';
import {
  botSettingsSchema,
  THEME_RADII,
  type BotSettingsIn,
  type BotSettingsOut,
} from '@kb/contracts/forms';
import { colors, DEFAULT_RADIUS } from '@kb/design-tokens';
import { zodResolver } from '@hookform/resolvers/zod';
import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { useForm, useFormContext, useWatch } from 'react-hook-form';

import { BotThemeScope } from '@/components/bot-theme-scope';
import { DegradedNote, SkeletonLines } from '@/components/states';
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
import { Textarea } from '@/components/ui/textarea';
import { useOrgKey } from '@/features/auth/session-context';
import { parseOklch } from '@/lib/color';
import { asText } from '@/lib/forms/as-text';
import { isReadableAccent, type BotTheme } from '@/lib/theme';

import { BOT_IDENTITY_FIELDS, botPanelDefaults } from './api';
import { useBotEditor, useBotSave, useUnsavedBotEdits } from './bot-editor-context';
import { BotIdentityPreview } from './bot-identity-preview';
import { BotIdentityReadOnly } from './bot-identity-read-only';
import {
  fetchStarterQuestions,
  starterQuestionsKeyParts,
} from './bot-identity-starter-questions';
import { BotStarterQuestions } from './bot-starter-questions';

/**
 * TAB 1 OF 3 — "Identity & voice".
 *
 * The shell (`bot-editor-screen.tsx`) carries the whole contract in prose; `bot-editor-context.ts`
 * carries the code half. Neither is edited from here, and neither is `./api.ts` — the private
 * modules beside this one are where this tab's own code lives.
 *
 * ── WHAT LANDS HERE ─────────────────────────────────────────────────────────────────────────────
 * `BOT_IDENTITY_FIELDS`, and nothing outside it:
 *
 *     name  slug  description  welcome_message  placeholder_text
 *     system_instruction  answer_style_instruction  theme
 *
 * They divide into four things a person can hold in their head, which is why they are four cards
 * rather than one long column: what your TEAM calls it, what a STRANGER sees, what the MODEL reads,
 * and what the surface LOOKS like. One `<form>` spans all four and saves them in one PATCH, because
 * they are one row and four submits would be four audit entries for one edit.
 *
 * The starter questions are NOT among them. They are rows in their own table with their own
 * endpoints, so they sit outside the form entirely (`bot-starter-questions.tsx`).
 *
 * ── THE ONE THING THIS TAB MUST GET RIGHT AND THE OTHER TWO NEED NOT ────────────────────────────
 * `system_instruction` and `answer_style_instruction` are a MANAGEMENT-ONLY PROJECTION (ADR-056): a
 * caller without `bots.manage` receives `null` for both, whatever is stored. `null` there means "not
 * shown to you", NOT "not set" — and the server says which of the two readings applies, per row, in
 * `bot.instructions_visible`.
 *
 * THAT FIELD IS THE CONDITION, AND `canManage` IS NOT. They agree almost always and the gap is the
 * whole bug: `canManage` is this client's own reading of a session role, while the projection was
 * decided per record against that record's organization. A role promoted mid-session, a cached
 * detail row or any refetch skew puts `canManage: true` in front of a body whose instructions were
 * withheld — and because `UpdateBotRequest` rules both fields `sometimes|nullable|string`, a present
 * `null` is a legitimate "clear it": saving a rename would write null over two operator-authored
 * prompts and return 200.
 *
 * So the protection is STRUCTURAL at two levels rather than remembered at either. Without
 * `canManage` this file renders `BotIdentityReadOnly` and mounts NO form at all — no `useForm`, no
 * `defaultValues`, no `botPanelDefaults` call. WITH `canManage` but WITHOUT `instructions_visible`,
 * `botPanelDefaults` OMITS both keys from form state (not `null` — omitted, because `sometimes`
 * leaves an absent key alone) and the card below renders no control for either, because RHF collects
 * a registered input's DOM value on submit whether or not `defaultValues` named it. Only with both
 * are the values the operator's own, and only there does an empty textarea mean "not set".
 *
 * ── WHAT THE PANEL DELIBERATELY DOES NOT DO ────────────────────────────────────────────────────
 * No Server Action — every mutation is a browser fetch to Laravel. No `retry`. No literal colour,
 * shadow, radius, type size or duration: every value is a token, through a shadcn primitive or a
 * token-backed utility. No `dangerouslySetInnerHTML` — tenant text is a JSX child everywhere,
 * including in the preview. No second copy of a path, a schema or an error map. And NO GUARD OF ITS
 * OWN against the tab change: this panel is unmounted when its tab is deselected, it reports its
 * unsaved state through `useUnsavedBotEdits`, and the shell — which owns `onValueChange` — is the
 * only place a change can actually be refused.
 */
export function BotIdentityPanel() {
  const { bot, canManage } = useBotEditor();

  /**
   * THE BRANCH IS A DIFFERENT COMPONENT, not an early return inside one.
   *
   * `useForm` and `useBotSave` may not sit behind a condition — the rules of hooks are the mechanical
   * reason, and the design reason is the same one stated above: the editor's hooks do not exist on
   * the read-only path, so nothing there can seed a control from a projected `null` even by accident.
   */
  if (!canManage) {
    return (
      <div className="flex flex-col gap-8">
        <BotIdentityReadOnly bot={bot} />
        {/* The preview is worth MORE to a reader who cannot edit: it is the only place this console
            shows what the bot actually looks like to the people talking to it. */}
        <BotIdentityPreviewCard
          name={bot.name}
          welcomeMessage={bot.welcome_message}
          placeholderText={bot.placeholder_text}
          // COPIED rather than passed through, for the reason `botFormDefaults` copies it: the
          // resource's object is shared with the query cache. (The spread is also what gives the
          // interface the index signature `BotTheme` in `lib/theme.ts` asks for — an interface has
          // no implicit one, which is the compiler noticing the same aliasing question.)
          theme={{ ...bot.theme }}
        />
        {/* `index` demands `bots.view`, which all four roles hold, so the list is readable here — it
            simply renders no add form and no per-row controls. */}
        <BotStarterQuestions />
      </div>
    );
  }

  return <BotIdentityEditor />;
}

/**
 * The editing path. Mounted only with `bots.manage`, which is an AFFORDANCE and never authorization:
 * Laravel answers 403 whatever this renders, `useBotSave` maps that class, and a role that changed
 * under a cached session shows up as that 403 rather than as a silently missing control.
 */
function BotIdentityEditor() {
  const { bot } = useBotEditor();

  const form = useForm<BotSettingsIn, unknown, BotSettingsOut>({
    resolver: zodResolver(botSettingsSchema),
    // `botPanelDefaults` and NEVER `reset(resource)` or an unfiltered `botFormDefaults(bot)`. The
    // PATCH body is `handleSubmit`'s output verbatim, so a form seeded with all 25 fields SENDS all
    // 25 and this tab would rewrite the retrieval knobs another tab is mid-edit on. The tuple is the
    // module-scope constant, never a literal array — a fresh identity every render would rebuild
    // `useBotSave`'s memoized `knownPaths` on every keystroke.
    defaultValues: botPanelDefaults(bot, BOT_IDENTITY_FIELDS),
    mode: 'onTouched',
  });

  const save = useBotSave(form, BOT_IDENTITY_FIELDS);

  /**
   * The starter questions live outside this form and can hold unsaved text of their own — a
   * half-typed chip label is destroyed by a tab change exactly as a half-typed welcome message is.
   * The shell's registry takes ONE boolean per panel, so the two are folded together here rather than
   * reported twice; a second `useUnsavedBotEdits('identity', …)` elsewhere would be a second writer
   * for one key and the last effect to run would win.
   */
  const [questionsUnsaved, setQuestionsUnsaved] = useState(false);
  const dirty = form.formState.isDirty;
  useUnsavedBotEdits('identity', dirty || questionsUnsaved);

  const rootError = form.formState.errors.root?.serverError?.message;

  return (
    <div className="flex flex-col gap-8">
      <Form {...form}>
        <form
          // POST, never the browser's default GET: this form sits behind a session, so its native
          // fallback would put every value in an admin's history and in a referer.
          method="post"
          // The browser's own validation bubbles would pre-empt the server's messages and cannot be
          // styled or read consistently by a screen reader.
          noValidate
          onSubmit={form.handleSubmit((values) => {
            save.mutate(values);
          })}
          className="flex flex-col gap-6"
        >
          {/* ONE banner, at the top of the form, and it renders `root.serverError` — which is where
              `applyServerErrors` routes a 422 key this panel has no control for. The envelope's own
              `message` reaches no rendered string on any path; what appears here is either Laravel's
              translated validation copy or the class-mapped sentence plus the `request_id`. */}
          {rootError === undefined ? null : (
            <Alert variant="destructive">
              <AlertTitle>This bot could not be saved</AlertTitle>
              <AlertDescription>{rootError}</AlertDescription>
            </Alert>
          )}

          <Card>
            <CardHeader>
              <CardTitle>What your team calls it</CardTitle>
              <CardDescription>
                Internal names. Nobody talking to the bot sees any of these.
              </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-5">
              <FormField
                control={form.control}
                name="name"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Name</FormLabel>
                    <FormControl>
                      <Input
                        {...field}
                        value={asText(field.value)}
                        autoComplete="off"
                        maxLength={NAME_MAX}
                      />
                    </FormControl>
                    <FormDescription>
                      What your team calls it, in the bot list and in every picker.
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="slug"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Handle</FormLabel>
                    <FormControl>
                      <Input
                        {...field}
                        value={asText(field.value)}
                        autoComplete="off"
                        autoCapitalize="none"
                        spellCheck={false}
                        maxLength={SLUG_MAX}
                        className="font-mono"
                      />
                    </FormControl>
                    {/* UNIQUENESS IS NOT MIRRORED AND CANNOT BE. It is per organization, and an
                        unscoped `unique:` would be an existence oracle over the whole platform
                        rendered as a validation error — so there is no rule for it in the manifest
                        and none in the schema. `BotService` checks it through an org-scoped
                        repository method and answers 422 KEYED `slug`, which `botPanelKnownPaths`
                        already routes under this control. It arrives here, not in the banner. */}
                    <FormDescription>
                      Lower-case letters, digits and internal hyphens. Unique within this organization
                      only — another organization using the same handle is not a conflict.
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="description"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Description</FormLabel>
                    <FormControl>
                      {/* `unknown` -> string: `clearableText` is a `z.preprocess`, so the INPUT type
                          is `unknown` while the output is `string | null`. The `''` fallback is also
                          what keeps the control CONTROLLED across the reset that runs after a save. */}
                      <Textarea
                        {...field}
                        value={asText(field.value)}
                        rows={3}
                        maxLength={DESCRIPTION_MAX}
                      />
                    </FormControl>
                    <FormDescription>
                      Optional. For your team — what this bot is for and who it is aimed at.
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>What the people talking to it see</CardTitle>
              <CardDescription>
                Both of these are read by strangers, on a surface you are not looking at. The preview
                below shows them in place.
              </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-5">
              <FormField
                control={form.control}
                name="welcome_message"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Welcome message</FormLabel>
                    <FormControl>
                      <Textarea
                        {...field}
                        value={asText(field.value)}
                        rows={3}
                        maxLength={WELCOME_MESSAGE_MAX}
                      />
                    </FormControl>
                    <FormDescription>
                      The first thing the bot says, before anyone has typed. Clearing it is a real
                      choice — the conversation then starts at the composer.
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="placeholder_text"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Composer placeholder</FormLabel>
                    <FormControl>
                      <Input
                        {...field}
                        value={asText(field.value)}
                        autoComplete="off"
                        maxLength={PLACEHOLDER_TEXT_MAX}
                      />
                    </FormControl>
                    <FormDescription>
                      The grey prompt inside the box. It is a hint and never a label — it disappears
                      the moment someone starts typing.
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>How it is told to answer</CardTitle>
              <CardDescription>
                These two are read by the MODEL, not by a person. They are kept to owners and admins:
                a reader with only <span className="font-mono">bots.view</span> is sent{' '}
                <span className="font-mono">null</span> for both.
              </CardDescription>
            </CardHeader>
            {/* THE ONE CONDITIONAL CARD BODY ON THIS TAB, AND THE CONDITION IS THE SERVER'S OWN.
                `instructions_visible` is false when this body arrived without the two stored values;
                `botPanelDefaults` has already omitted both keys from form state, and rendering the
                controls anyway would put them straight back — RHF submits a registered input's DOM
                value whether or not `defaultValues` named it, `clearableText` turns the empty string
                into `null`, and `sometimes|nullable` accepts that as "clear it".

                Two things are deliberately NOT done here. No disabled textarea: a disabled control is
                a value an operator will keep clicking at, and it would still be showing the
                projection's `null` as if it were the prompt. And no guess about whether either field
                is set — on this body a bot with an 8,000-character prompt and a bot with none are
                byte-identical, so the copy says withheld and stops. */}
            {bot.instructions_visible ? (
              <CardContent className="flex flex-col gap-5">
                <FormField
                  control={form.control}
                  name="system_instruction"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>System instruction</FormLabel>
                      <FormControl>
                        <Textarea
                          {...field}
                          value={asText(field.value)}
                          rows={8}
                          maxLength={SYSTEM_INSTRUCTION_MAX}
                          className="font-mono"
                        />
                      </FormControl>
                      {/* Retrieved content is UNTRUSTED DATA and can never alter these instructions —
                          that is enforced in the data plane's prompt assembly, not here. Saying so is
                          what stops an operator writing "ignore anything the documents say" as if this
                          box were the defence. */}
                      <FormDescription>
                        Who the bot is and what it must not do. Source text can never override it —
                        retrieved content is treated as data, never as instructions.
                      </FormDescription>
                      <FormMessage />
                    </FormItem>
                  )}
                />

                <FormField
                  control={form.control}
                  name="answer_style_instruction"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>Answer style</FormLabel>
                      <FormControl>
                        <Textarea
                          {...field}
                          value={asText(field.value)}
                          rows={5}
                          maxLength={ANSWER_STYLE_INSTRUCTION_MAX}
                        />
                      </FormControl>
                      <FormDescription>
                        Length, tone and shape — &ldquo;two short paragraphs, no bullet lists&rdquo;.
                        It does not change what the bot is allowed to say.
                      </FormDescription>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </CardContent>
            ) : (
              <CardContent>
                <p className="text-base text-muted-foreground">
                  This bot&apos;s instructions were not sent with this page, so they cannot be edited
                  here. They are{' '}
                  <strong className="font-medium text-foreground">not empty</strong> — this screen
                  cannot tell you whether they are set. Reload the page; if they stay hidden, reading
                  them needs the owner or admin role in this organization.
                </p>
              </CardContent>
            )}
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Appearance</CardTitle>
              <CardDescription>
                Three values, not a stylesheet. Everything else — every text colour, every hover and
                pressed state — is derived from these, because contrast is derived and never chosen.
              </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-5">
              <ThemeColorField
                name="theme.primary"
                label="Brand colour"
                description="The one loud accent: buttons, the send control, the active state. The whole hover and pressed ramp is derived from it."
              />
              <ThemeColorField
                name="theme.accent"
                label="Highlight colour"
                description="The quiet wash behind a hovered row or a selected suggestion. Not a second brand colour — it sits under text and has to stay readable."
              />

              <FormField
                control={form.control}
                name="theme.radius"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel htmlFor="bot-theme-radius">Corner radius</FormLabel>
                    {/* A Radix Select installs no `register` ref, so `applyServerErrors`'
                        `hasFocusableRef` returns false for this path and never spends `shouldFocus`
                        on it — the message still renders through FormMessage below. */}
                    <Select
                      value={field.value ?? PLATFORM_RADIUS}
                      onValueChange={(next) =>
                        field.onChange(next === PLATFORM_RADIUS ? undefined : next)
                      }
                    >
                      <FormControl>
                        <SelectTrigger id="bot-theme-radius" className="w-full">
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        <SelectItem value={PLATFORM_RADIUS}>Use the platform radius</SelectItem>
                        {THEME_RADII.map((radius) => (
                          <SelectItem key={radius} value={radius}>
                            {radiusLabel(radius)}
                            {radius === DEFAULT_RADIUS ? ' — the platform default' : ''}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    {/* THE SIX ARE A CLOSED SET matched exactly against the values
                        `packages/design-tokens` publishes, and the renderer drops anything else — so
                        an arbitrary CSS length is refused on write rather than ignored on render. */}
                    <FormDescription>
                      Six published values, and only these six. Leaving it unset is different from
                      picking the platform&apos;s own number: unset follows the platform if it ever
                      changes.
                    </FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </CardContent>
          </Card>

          <WatchedPreviewCard />

          <Card>
            <CardContent className="flex flex-wrap items-center justify-end gap-3">
              {/* Rendered unconditionally so the region exists in the accessibility tree BEFORE it
                  has anything to say — a live region inserted together with its first message is not
                  reliably announced. `polite` and not an alert: nothing is wrong. */}
              <p role="status" aria-live="polite" className="mr-auto text-sm text-muted-foreground">
                {dirty
                  ? 'Unsaved changes'
                  : save.isSuccess
                    ? 'Changes saved.'
                    : ''}
              </p>
              {/* Disabled on `isPending`: this PATCH carries no `Idempotency-Key`, so nothing may
                  replay it — including a double-click. */}
              <Button type="submit" disabled={save.isPending}>
                {save.isPending ? 'Saving…' : 'Save changes'}
              </Button>
            </CardContent>
          </Card>
        </form>
      </Form>

      {/* OUTSIDE the form, and it has to be: these are a separate resource with their own endpoints,
          and its add and rename controls are `<form>` elements of their own — nesting one inside
          another is invalid HTML and the inner submit would be swallowed. */}
      <BotStarterQuestions onUnsavedChange={setQuestionsUnsaved} />
    </div>
  );
}

/**
 * THE PREVIEW, FED FROM LIVE FORM STATE — and it is a component of its own so that the four field
 * cards do not re-render on every keystroke.
 *
 * `useWatch` and NOT `form.watch(name)`. The two differ in where the subscription lives: `watch()`
 * subscribes the component that CALLED it, so calling it in the editor body would re-render all four
 * cards, the preview and the save bar on every character typed into any of them — which is exactly
 * the cost React Hook Form's uncontrolled design exists to avoid. `useWatch` subscribes this
 * component alone. (It is also the API React Compiler can reason about: `watch()` is a function
 * returned from `useForm()` and the compiler skips memoizing any component that reads one, which
 * `react-hooks/incompatible-library` reports.)
 */
function WatchedPreviewCard() {
  const { control } = useFormContext<BotSettingsIn, unknown, BotSettingsOut>();

  const name = useWatch({ control, name: 'name' });
  const welcomeMessage = useWatch({ control, name: 'welcome_message' });
  const placeholderText = useWatch({ control, name: 'placeholder_text' });
  const theme = useWatch({ control, name: 'theme' });

  return (
    <BotIdentityPreviewCard
      name={asText(name)}
      welcomeMessage={asOptionalText(welcomeMessage)}
      placeholderText={asOptionalText(placeholderText)}
      // `{}` is the unthemed state and is NOT null: an absent `theme` leaves the stored one alone
      // while `{}` clears it, and the preview draws the second of those — the platform palette.
      theme={theme ?? {}}
    />
  );
}

/**
 * THE PREVIEW CARD — the query, the four states, and the presentational component underneath.
 *
 * It reads the starter questions with the SAME key the collection card uses (one fetch, one cache
 * entry, shared by both), which is why that key's parts are a function in the transport module rather
 * than typed out twice.
 *
 * THE ERROR STATE IS DELIBERATELY NOT RENDERED HERE. One failed request has one cause, and the
 * starter-questions card below is already showing it with the class-mapped sentence and the
 * `request_id`; a second alert for the same failure is two problems where there is one. The preview
 * simply draws with no chips, which is what the surface would look like if the list really were
 * empty — and the card below says why it might not be.
 */
function BotIdentityPreviewCard({
  name,
  welcomeMessage,
  placeholderText,
  theme,
}: {
  readonly name: string;
  readonly welcomeMessage: string | null;
  readonly placeholderText: string | null;
  readonly theme: BotTheme;
}) {
  const { orgId, botId } = useBotEditor();
  const orgKeyFor = useOrgKey();

  const questionsKey = useMemo(
    () => orgKeyFor(...starterQuestionsKeyParts(botId)),
    [orgKeyFor, botId],
  );

  const questions = useQuery({
    queryKey: questionsKey,
    queryFn: ({ signal }) => fetchStarterQuestions(orgId, botId, signal),
  });

  return (
    <Card>
      <CardHeader>
        <CardTitle>Preview</CardTitle>
        <CardDescription>
          The first screen of a conversation, drawn from the values above as you type them.
        </CardDescription>
      </CardHeader>
      <CardContent>
        {questions.isPending ? (
          // A skeleton at the preview's own shape, so nothing reflows when the chips arrive.
          <SkeletonLines lines={3} />
        ) : (
          <BotIdentityPreview
            name={name}
            welcomeMessage={welcomeMessage}
            placeholderText={placeholderText}
            theme={theme}
            questions={(questions.data ?? []).map((row) => row.question)}
          />
        )}
      </CardContent>
    </Card>
  );
}

/**
 * ONE TENANT COLOUR: the input, a swatch of what it resolves to, and the readability note.
 *
 * ── THE NOTE IS ADVICE AND NOT VALIDATION, AND THAT DISTINCTION IS THE WHOLE DESIGN ────────────
 * `theme.primary` and `theme.accent` are re-checked server-side by `App\Rules\ReadableThemeColor`,
 * whose CONTRAST half `botSettingsSchema` deliberately does NOT mirror — mirroring it would be a
 * third spelling of the `oklch()` grammar plus a second gamut-mapping implementation inside a
 * zero-dependency package. So the resolver here says nothing about contrast and a colour in the
 * unreachable band comes back as a 422 keyed `theme.primary`, which lands under this control.
 *
 * What this adds is the answer BEFORE the round trip, from `apps/web/src/lib/color.ts` — the copy the
 * parity test watches. It never blocks a submit: a client check that refused what the server accepts
 * is the invisible half of the drift asymmetry, functionality removed with nothing reported. It is
 * `<DegradedNote>` — quiet, in place, no live region — rather than an `<Alert role="alert">`, which
 * would interrupt a screen reader on every keystroke.
 *
 * The band is real rather than a rounding problem: two fixed text candidates cannot cover the whole
 * lightness axis, and `L ∈ [0.538, 0.634]` bottoms out at 4.143:1 for some chroma/hue pairs. Even
 * pure black on pure white only reaches 4.583:1 at its crossover, so no choice of candidates closes
 * it — the colour is refused rather than shipped with unreadable text on it.
 *
 * ── THE SWATCH GOES THROUGH `BotThemeScope`, NOT THROUGH `style=""` ────────────────────────────
 * A tenant string interpolated into a `style` attribute is the CSS-injection path, and a nonce does
 * not cover an attribute — so it would work in development and silently vanish under the production
 * CSP. `BotThemeScope` writes custom properties through the CSSOM and drops anything that fails the
 * grammar; the swatch is drawn only for a value that already parses AND is readable, so it can never
 * show the platform colour while claiming to show the tenant's.
 */
function ThemeColorField({
  name,
  label,
  description,
}: {
  readonly name: 'theme.primary' | 'theme.accent';
  readonly label: string;
  readonly description: string;
}) {
  // `useFormContext` and not a `form` prop: `<Form {...form}>` is already a provider above every
  // field on this tab, and threading the object through would be a second way for one of these two
  // controls to end up bound to a different form instance than the one it is rendered inside.
  const { control } = useFormContext<BotSettingsIn, unknown, BotSettingsOut>();

  return (
    <FormField
      control={control}
      name={name}
      render={({ field }) => {
        const typed = asText(field.value);
        const parsed = parseOklch(typed);
        const readable = parsed !== null && isReadableAccent(parsed);

        return (
          <FormItem>
            <FormLabel>{label}</FormLabel>
            <div className="flex items-center gap-3">
              <FormControl>
                <Input
                  {...field}
                  value={typed}
                  autoComplete="off"
                  autoCapitalize="none"
                  spellCheck={false}
                  maxLength={THEME_COLOR_MAX}
                  className="font-mono"
                  // The platform accent, READ from the token package rather than transcribed. It is
                  // display text and not an applied style, so a stale literal here would not have
                  // shown up as a colour drift — it would have shown up as an example that no longer
                  // matches the colour the field falls back to, which is worse to debug than a wrong
                  // swatch. `light` because this is the grammar example, not a rendered value: the
                  // dark-mode accent is a different triple and showing it in dark mode would suggest
                  // the field's default follows the console's theme, which it does not.
                  placeholder={colors.primary.light}
                  // CLEARING THE BOX OMITS THE KEY rather than sending `""`. `ConvertEmptyStringsToNull`
                  // turns `""` into null before any rule runs and neither colour is `nullable`, so an
                  // empty string is a 422 on both sides — and `theme` is replaced wholesale, so an
                  // omitted member IS how a tenant colour is cleared back to the platform's.
                  onChange={(event) =>
                    field.onChange(
                      event.currentTarget.value === '' ? undefined : event.currentTarget.value,
                    )
                  }
                />
              </FormControl>
              {readable ? (
                <BotThemeScope theme={{ [swatchProperty(name)]: typed }}>
                  <span
                    aria-hidden
                    className={
                      name === 'theme.primary'
                        ? 'block size-9 shrink-0 rounded-full bg-primary ring-1 ring-input'
                        : 'block size-9 shrink-0 rounded-full bg-accent ring-1 ring-input'
                    }
                  />
                </BotThemeScope>
              ) : null}
            </div>
            <FormDescription>{description}</FormDescription>
            {parsed !== null && !readable ? (
              <DegradedNote>
                No text colour reads at 4.5:1 on this one, so the server will refuse it. Move it
                lighter or darker — the unreachable band is a narrow strip in the middle of the
                lightness axis.
              </DegradedNote>
            ) : null}
            <FormMessage />
          </FormItem>
        );
      }}
    />
  );
}

/** `theme.primary` -> `primary`. The two sub-paths are a closed pair, so this is a narrowing rather
 *  than a string operation on tenant input. */
function swatchProperty(name: 'theme.primary' | 'theme.accent'): 'primary' | 'accent' {
  return name === 'theme.primary' ? 'primary' : 'accent';
}

/** The Select's "no value" sentinel. Radix refuses an empty-string item value, and `undefined` on
 *  `theme.radius` is the state that means "follow the platform" — the two need distinct spellings. */
const PLATFORM_RADIUS = 'platform';

/**
 * The six published radii, in words.
 *
 * A `Record` over the union rather than a list of pairs, so a seventh radius added to `THEME_RADII`
 * is a TYPECHECK failure naming the missing label instead of a select item that renders its own raw
 * value. It is read with `Object.entries` + `find` rather than `LABELS[value]`, because indexing an
 * object by a variable is `security/detect-object-injection`'s sink and reports as a warning nobody
 * can act on.
 */
const RADIUS_LABELS: Readonly<Record<BotThemeRadius, string>> = {
  '0rem': 'Square corners',
  '0.25rem': 'Barely rounded',
  '0.5rem': 'Rounded',
  '0.625rem': 'Rounded, a little more',
  '0.75rem': 'Soft',
  '1rem': 'Very soft',
};

function radiusLabel(radius: BotThemeRadius): string {
  return Object.entries(RADIUS_LABELS).find(([value]) => value === radius)?.[1] ?? radius;
}

/**
 * A watched `clearableText` field as the preview wants it.
 *
 * BLANK BECOMES `null`, which is what the SERVER sees: `TrimStrings` then
 * `ConvertEmptyStringsToNull` run before any rule, so a cleared textarea is stored as null. A preview
 * that showed an empty bubble for a whitespace-only welcome message would be showing something the
 * end user never gets.
 */
function asOptionalText(value: unknown): string | null {
  if (typeof value !== 'string') return null;
  const trimmed = value.trim();
  return trimmed === '' ? null : value;
}

/**
 * The FormRequest's own `max:` bounds, as `maxLength` AFFORDANCES on the controls.
 *
 * They are affordances and never the authority — `botSettingsSchema` mirrors them with `.trim()`
 * first (Laravel's `TrimStrings` runs before `max:`, so two spaces plus 1,999 characters is 1,999
 * server-side and 2,001 in a browser that counted first) and the FormRequest is what actually
 * refuses. They are stated here rather than imported because `@kb/contracts/forms` keeps them
 * private to the schema module, and the drift suite compares the SCHEMA against the manifest — which
 * is the comparison that matters. A number here that drifted would over- or under-stop a keystroke,
 * which the field message then explains; it cannot make the form accept what the server refuses.
 */
const NAME_MAX = 120;
const SLUG_MAX = 64;
const DESCRIPTION_MAX = 2000;
const WELCOME_MESSAGE_MAX = 2000;
const PLACEHOLDER_TEXT_MAX = 200;
const SYSTEM_INSTRUCTION_MAX = 8000;
const ANSWER_STYLE_INSTRUCTION_MAX = 4000;
const THEME_COLOR_MAX = 64;
