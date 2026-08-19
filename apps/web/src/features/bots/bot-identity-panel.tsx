'use client';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

import { useBotEditor } from './bot-editor-context';

/**
 * TAB 1 OF 3 — "Identity & voice". THIS FILE IS ONE AGENT'S, AND ONLY THIS FILE.
 *
 * The shell (`bot-editor-screen.tsx`) carries the whole contract in prose and is the file to read
 * first; `bot-editor-context.ts` carries the code half. Neither may be edited from here, and neither
 * may `./api.ts`. Private modules beside this one are fine (`bot-identity-fields.tsx`, …).
 *
 * ── WHAT LANDS HERE ─────────────────────────────────────────────────────────────────────────────
 * `BOT_IDENTITY_FIELDS` from `./api`, and nothing outside it:
 *
 *     name  slug  description  welcome_message  placeholder_text
 *     system_instruction  answer_style_instruction  theme
 *
 * The first five are what a person reads: what the bot is called, its handle, what your team calls
 * it, the sentence it opens a conversation with, and the composer's placeholder. The next two are
 * what the MODEL reads. `theme` is the three tenant-settable custom properties — `primary`, `accent`
 * and one of the six published radii — and nothing else: every `-foreground` and every accent ramp
 * step is DERIVED at render time, because contrast is derived and never chosen.
 *
 * ── THE ONE THING THIS TAB MUST GET RIGHT AND THE OTHER TWO NEED NOT ────────────────────────────
 * `system_instruction` and `answer_style_instruction` are a MANAGEMENT-ONLY PROJECTION (ADR-056): a
 * caller without `bots.manage` receives `null` for both, whatever is stored. `null` there means "not
 * shown to you", NOT "not set", and the two must never render the same. With `canManage` false this
 * panel mounts no form at all — the shell has already said why, once, above the tabs — and says of
 * those two fields that they are hidden rather than empty.
 *
 * `theme.primary` and `theme.accent` are `oklch()` strings the server re-checks with
 * `App\Rules\ReadableThemeColor`, whose CONTRAST half `botSettingsSchema` deliberately does not
 * mirror (a third spelling of the grammar plus a second gamut-mapping implementation in a
 * zero-dependency package). `apps/web/src/lib/color.ts` is the copy the parity test watches; a colour
 * in the unreachable band comes back as a 422 keyed `theme.primary`, which `botPanelKnownPaths`
 * already routes under its control.
 *
 * ── THE FORM, VERBATIM ──────────────────────────────────────────────────────────────────────────
 *     const { bot, canManage } = useBotEditor();
 *     const form = useForm<BotSettingsIn, unknown, BotSettingsOut>({
 *       resolver: zodResolver(botSettingsSchema),
 *       defaultValues: botPanelDefaults(bot, BOT_IDENTITY_FIELDS),
 *       mode: 'onTouched',
 *     });
 *     const save = useBotSave(form, BOT_IDENTITY_FIELDS);
 *     useUnsavedBotEdits('identity', form.formState.isDirty);
 *
 * Never `reset(resource)` and never `botFormDefaults(bot)` unfiltered — see the shell, §4.
 */
export function BotIdentityPanel() {
  const { bot } = useBotEditor();

  return (
    <Card>
      <CardHeader>
        <CardTitle as="h3">Identity &amp; voice</CardTitle>
        <CardDescription>
          What this bot is called, what it says first, and how it is told to answer.
        </CardDescription>
      </CardHeader>
      <CardContent>
        {/* The controls are the next step. Until they land this states the two facts the row already
            carries and a reader can act on, rather than an empty box: tenant text as a JSX child,
            never markup. */}
        <p className="text-base text-muted-foreground">
          This bot answers as <span className="font-medium text-foreground">{bot.name}</span> under
          the handle <span className="font-mono">{bot.slug}</span>. Its name, welcome message,
          placeholder, instructions and theme are edited here.
        </p>
      </CardContent>
    </Card>
  );
}
